<?php
if (!defined('ABSPATH')) { exit; }

/** Normalize mapped prices and quantities to whole numeric values before import. */
class A1FI_Numeric_Normalizer {
    const BATCH_SIZE = 50;

    public static function boot() {
        // Clean source values, calculate adjusted prices, then normalize again before validation.
        add_action('a1fi_process_queue', [__CLASS__, 'normalize_next_batch'], 7);
        add_action('a1fi_process_queue', [__CLASS__, 'normalize_next_batch'], 8.5);
    }

    public static function normalize_next_batch() {
        if (get_transient('a1fi_queue_lock')) return;

        global $wpdb;
        $job = $wpdb->get_row(
            'SELECT id, feed_id FROM ' . A1FI_Queue::jobs_table() . " WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1"
        );
        if (!$job) return;

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? [];
        $mapping = $feed['mapping'] ?? [];
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
                if (!array_key_exists($column, $data)) continue;
                $normalized = self::whole_number($data[$column]);
                // Leave invalid input intact so row validation can report it, rather than silently importing it.
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

    /**
     * Convert a numeric/currency-like value to a numeric whole-number string using ceiling.
     * Decimal separators are treated as decimals when there is only one separator, so 12.01
     * and 12,01 both become 13 rather than being misread as grouped thousands.
     * Return null for malformed values and an empty string for blank values.
     */
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

        $negative = preg_match('/^\(.*\)$/', $text) === 1;
        if ($negative) $text = substr($text, 1, -1);

        // Remove currency labels/symbols, spaces, and common grouping apostrophes.
        $text = preg_replace('/[\p{L}\p{Sc}\s\x{00A0}\x{202F}\']+/u', '', $text);
        if (!is_string($text) || $text === '' || !preg_match('/^[+-]?[0-9.,]+$/', $text)) return null;

        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            // With both marks present, the last one is the decimal mark.
            if ($comma > $dot) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($comma !== false || $dot !== false) {
            $separator = $comma !== false ? ',' : '.';
            $parts = explode($separator, $text);
            if (count($parts) > 2) {
                // Repeated separators are grouping marks unless the final group is not 3 digits.
                $last = array_pop($parts);
                $whole = implode('', $parts);
                $text = strlen($last) === 3 ? $whole . $last : $whole . '.' . $last;
            } else {
                $text = str_replace($separator, '.', $text);
            }
        }

        if (!is_numeric($text) || !is_finite((float) $text)) return null;
        $number = (float) $text;
        if ($negative) $number = -abs($number);
        return (string) (int) ceil($number);
    }
}

add_action('plugins_loaded', ['A1FI_Numeric_Normalizer', 'boot'], 14);
