<?php
if (!defined('ABSPATH')) { exit; }

/** Validate queued product fields before WooCommerce save and preserve actionable row errors. */
class A1FI_Row_Validation {
    const BATCH_SIZE = 50;

    public static function boot() {
        // Runs after the price calculator and numeric payload guard, before queue import.
        add_action('a1fi_process_queue', [__CLASS__, 'validate_next_batch'], 9);
    }

    public static function validate_next_batch() {
        global $wpdb;
        $jobs_table = A1FI_Queue::jobs_table();
        $rows_table = A1FI_Queue::rows_table();
        $job = $wpdb->get_row("SELECT id, feed_id FROM $jobs_table WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? [];
        $mapping = $feed['mapping'] ?? [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, `row_number`, payload FROM $rows_table WHERE job_id=%d AND status=%s ORDER BY `row_number` ASC LIMIT %d",
            (int) $job->id,
            'queued',
            self::BATCH_SIZE
        ));
        if (!$rows) return;

        foreach ($rows as $record) {
            $data = json_decode($record->payload, true);
            $errors = [];
            if (!is_array($data)) {
                $errors[] = __('Feed row is not valid JSON data.', 'allinonefeedimporter');
            } else {
                $changed = false;
                foreach (['regular_price', 'sale_price', 'stock_quantity'] as $field) {
                    $column = $mapping[$field] ?? '';
                    if ($column === '' || !array_key_exists($column, $data) || $data[$column] === null) continue;

                    $normalized = A1FI_Numeric_Normalizer::whole_number($data[$column]);
                    if ($normalized === '') {
                        // Blank optional values must be absent in the payload. Otherwise
                        // import_product() sees isset('') and rejects it as non-numeric.
                        unset($data[$column]);
                        $changed = true;
                    } elseif ($normalized !== null && $normalized !== $data[$column]) {
                        $data[$column] = $normalized;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $payload = wp_json_encode($data);
                    if ($payload !== false) {
                        $wpdb->update($rows_table, ['payload' => $payload], ['id' => (int) $record->id, 'status' => 'queued'], ['%s'], ['%d', '%s']);
                    }
                }
                $errors = self::validate_row($data, $mapping);
            }

            if (!$errors) continue;
            $message = sprintf(__('Row %1$d: %2$s', 'allinonefeedimporter'), (int) $record->row_number + 1, implode(' ', $errors));
            $updated = $wpdb->update(
                $rows_table,
                ['status' => 'failed'],
                ['id' => (int) $record->id, 'status' => 'queued'],
                ['%s'],
                ['%d', '%s']
            );
            if ($updated !== 1) continue;

            $wpdb->query($wpdb->prepare(
                "UPDATE $jobs_table SET failed_count=failed_count+1, processed_rows=processed_rows+1 WHERE id=%d",
                (int) $job->id
            ));
            self::record_error((int) $job->id, $message);
        }
    }

    private static function validate_row($data, $mapping) {
        if (!is_array($data)) return [__('Feed row is not valid JSON data.', 'allinonefeedimporter')];

        $values = [];
        foreach ($mapping as $field => $column) {
            if ($column !== '' && array_key_exists($column, $data) && $data[$column] !== null) $values[$field] = $data[$column];
        }

        $errors = [];
        $name = trim(wp_strip_all_tags((string) ($values['name'] ?? '')));
        if ($name === '') $errors[] = __('Product name is missing or empty.', 'allinonefeedimporter');

        foreach (['regular_price' => __('Regular price', 'allinonefeedimporter'), 'sale_price' => __('Sale price', 'allinonefeedimporter')] as $field => $label) {
            if (isset($values[$field]) && trim((string) $values[$field]) !== '' && !is_numeric($values[$field])) {
                $errors[] = sprintf(__('%s must be numeric.', 'allinonefeedimporter'), $label);
            }
        }
        if (isset($values['stock_quantity']) && trim((string) $values['stock_quantity']) !== '' && !is_numeric($values['stock_quantity'])) {
            $errors[] = __('Stock quantity must be numeric.', 'allinonefeedimporter');
        }
        return $errors;
    }

    private static function record_error($job_id, $message) {
        global $wpdb;
        $table = A1FI_Queue::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT errors FROM $table WHERE id=%d", $job_id));
        $errors = $job && $job->errors ? json_decode($job->errors, true) : [];
        if (!is_array($errors)) $errors = [];
        if (count($errors) < 100) {
            $errors[] = sanitize_text_field($message);
            $wpdb->update($table, ['errors' => wp_json_encode($errors)], ['id' => $job_id], ['%s'], ['%d']);
        }
    }
}

add_action('plugins_loaded', ['A1FI_Row_Validation', 'boot'], 15);
