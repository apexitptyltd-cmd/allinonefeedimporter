<?php
if (!defined('ABSPATH')) { exit; }

/** Normalize mapped prices and quantities to positive whole numeric values. */
class A1FI_Numeric_Normalizer {
    const BATCH_SIZE = 50;
    public static function boot() {
        // Normalize feed literals before optional price calculations, then round the
        // calculated values again before row validation and WooCommerce product save.
        add_action('a1fi_process_queue', [__CLASS__, 'normalize_next_batch'], 7);
        add_action('a1fi_process_queue', [__CLASS__, 'normalize_next_batch'], 8.5);
    }

    public static function normalize_next_batch() {
        if (get_transient('a1fi_queue_lock')) return;
        global $wpdb;
        $job = $wpdb->get_row('SELECT id, feed_id FROM ' . A1FI_Queue::jobs_table() . " WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? [];
        $mapping = $feed['mapping'] ?? [];
        $numeric_fields = ['regular_price', 'sale_price', 'stock_quantity'];
        $columns = [];
        foreach ($numeric_fields as $field) {
            if (!empty($mapping[$field])) $columns[$field] = $mapping[$field];
        }
        if (!$columns) return;

        $rows_table = A1FI_Queue::rows_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, payload FROM $rows_table WHERE job_id=%d AND status=%s ORDER BY `row_number` ASC LIMIT %d",
            (int) $job->id, 'queued', self::BATCH_SIZE
        ));
        foreach ((array) $rows as $record) {
            $data = json_decode($record->payload, true);
            if (!is_array($data)) continue;
            $changed = false;
            foreach ($columns as $field => $column) {
                if (!array_key_exists($column, $data)) continue;
                $normalized = self::whole_number($data[$column]);
                // Preserve malformed values so row validation records the actionable error.
                if ($normalized === null || $normalized === '' || $normalized === $data[$column]) continue;
                $data[$column] = $normalized;
                $changed = true;
            }
            if ($changed) {
                $payload = wp_json_encode($data);
                if ($payload !== false) {
                    $wpdb->update($rows_table, ['payload' => $payload], ['id' => (int) $record->id, 'status' => 'queued'], ['%s'], ['%d', '%s']);
                }
            }
        }
    }

    /** Return a whole-number numeric string, empty string for blank input, null if invalid. */
    public static function whole_number($value) {
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) return null;
            return (string) (int) ceil((float) $value);
        }
        if (!is_string($value)) return null;
        $text = trim($value);
        if ($text === '') return '';

        // Excel exports sometimes contain string formulas such as ="1,234.50".
        if (preg_match('/^\s*=\s*"((?:[^"]|"")*)"\s*$/', $text, $match)) {
            $text = str_replace('""', '"', $match[1]);
        }
        $text = trim($text);
        if ($text === '') return '';

        // Support accounting negatives, currency symbols/codes, and grouping spaces.
        $negative = preg_match('/^\(.*\)$/', $text) === 1;
        if ($negative) $text = substr($text, 1, -1);
        $text = preg_replace('/[^0-9,\.\-+]/u', '', $text);
        if ($text === '' || !preg_match('/^[+-]?[0-9.,]+$/', $text)) return null;

        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            // The final separator is the decimal mark; the other is a thousands mark.
            if ($comma > $dot) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($comma !== false) {
            $parts = explode(',', $text);
            if (count($parts) > 2 || (count($parts) === 2 && strlen($parts[1]) === 3 && strlen($parts[0]) > 0)) {
                $text = implode('', $parts);
            } else {
                $text = str_replace(',', '.', $text);
            }
        } elseif ($dot !== false) {
            $parts = explode('.', $text);
            if (count($parts) > 2 || (count($parts) === 2 && strlen($parts[1]) === 3 && strlen($parts[0]) > 0)) {
                $text = implode('', $parts);
            }
        }

        if (!is_numeric($text) || !is_finite((float) $text)) return null;
        $number = (float) $text;
        if ($negative) $number = -abs($number);
        return (string) (int) ceil($number);
    }
}

add_action('plugins_loaded', ['A1FI_Numeric_Normalizer', 'boot'], 14);
