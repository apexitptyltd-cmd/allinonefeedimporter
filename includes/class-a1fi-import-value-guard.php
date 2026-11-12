<?php
if (!defined('ABSPATH')) { exit; }

/** Final numeric payload guard immediately before the import worker consumes queued rows. */
class A1FI_Import_Value_Guard {
    const BATCH_SIZE = 50;

    public static function boot() {
        // A1FI_Queue registers its worker on plugins_loaded priority 20. Registering this
        // same action priority earlier ensures this cleanup runs before process_batch().
        add_action('a1fi_process_queue', [__CLASS__, 'prepare_next_batch'], 10);
    }

    public static function prepare_next_batch() {
        if (get_transient('a1fi_queue_lock')) return;

        global $wpdb;
        $job = $wpdb->get_row(
            'SELECT id, feed_id FROM ' . A1FI_Queue::jobs_table() . " WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1"
        );
        if (!$job) return;

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $mapping = $feeds[$job->feed_id]['mapping'] ?? [];
        $columns = [];
        foreach (['regular_price', 'sale_price', 'stock_quantity'] as $field) {
            if (!empty($mapping[$field])) $columns[$field] = $mapping[$field];
        }
        if (!$columns) return;

        $rows_table = A1FI_Queue::rows_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, payload FROM $rows_table WHERE job_id=%d AND status=%s ORDER BY `row_number` ASC LIMIT %d",
            (int) $job->id,
            'queued',
            self::BATCH_SIZE
        ));

        foreach ((array) $rows as $record) {
            $data = json_decode($record->payload, true);
            if (!is_array($data)) continue;

            $changed = false;
            foreach ($columns as $column) {
                if (!array_key_exists($column, $data) || $data[$column] === null) continue;

                $value = is_scalar($data[$column]) ? trim((string) $data[$column]) : '';
                // The price calculator uses an empty string when a calculation cannot
                // produce a price. Treat blank prices as absent, not as the literal
                // non-numeric value that import_product() would reject.
                if ($value === '') {
                    unset($data[$column]);
                    $changed = true;
                    continue;
                }

                $normalized = A1FI_Numeric_Normalizer::whole_number($data[$column]);
                if ($normalized !== null && $normalized !== $data[$column]) {
                    $data[$column] = $normalized;
                    $changed = true;
                }
            }

            if ($changed) {
                $payload = wp_json_encode($data);
                if ($payload !== false) {
                    $wpdb->update(
                        $rows_table,
                        ['payload' => $payload],
                        ['id' => (int) $record->id, 'status' => 'queued'],
                        ['%s'],
                        ['%d', '%s']
                    );
                }
            }
        }
    }
}

add_action('plugins_loaded', ['A1FI_Import_Value_Guard', 'boot'], 16);
