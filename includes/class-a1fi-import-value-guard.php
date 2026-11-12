<?php
if (!defined('ABSPATH')) { exit; }

/** Final numeric payload guard immediately before validation and import consume queued rows. */
class A1FI_Import_Value_Guard {
    const BATCH_SIZE = 50;

    public static function boot() {
        // Price_Calculator runs at priority 8 and is registered earlier. Run after it,
        // but before numeric normalization and row validation at priority 9, as well as
        // Queue::process_batch at priority 10.
        add_action('a1fi_process_queue', [__CLASS__, 'prepare_next_batch'], 8);
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

                // A failed/empty optional price calculation means “no value”. Remove it
                // so the product worker does not reject an empty string as a price.
                if ($value === '') {
                    unset($data[$column]);
                    $changed = true;
                    continue;
                }

                // Convert Excel literals, currency-formatted strings and decimal
                // separators before the validation and import callbacks inspect them.
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
