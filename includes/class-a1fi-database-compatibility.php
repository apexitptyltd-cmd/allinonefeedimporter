<?php
if (!defined('ABSPATH')) { exit; }

/** MariaDB compatibility for the queue's reserved row_number identifier. */
class A1FI_Database_Compatibility {
    public static function boot() {
        // Register before A1FI_Queue::boot() runs dbDelta on plugins_loaded.
        add_filter('query', [__CLASS__, 'quote_queue_row_number']);
    }

    public static function quote_queue_row_number($query) {
        global $wpdb;
        $table = $wpdb->prefix . 'a1fi_job_rows';
        if (stripos($query, $table) === false) return $query;

        // dbDelta emits ALTER statements that use this reserved identifier unquoted.
        // Quote only SQL identifier positions so row data and JSON payloads stay intact.
        $query = preg_replace(
            '/(?<![`A-Za-z0-9_])row_number(?=\\s+int\\s+unsigned\\s+NOT\\s+NULL)/i',
            '`row_number`',
            $query
        );
        $query = preg_replace(
            '/\\bCHANGE\\s+COLUMN\\s+`?row_number`?\\s+`?row_number`?(?=\\s+int\\b)/i',
            'CHANGE COLUMN `row_number` `row_number`',
            $query
        );
        $query = preg_replace(
            '/\\b(ADD\\s+(?:KEY|INDEX)\\s+`?job_row`?\\s*\\()\\s*`?job_id`?\\s*,\\s*`?row_number`?\\s*(\\))/i',
            '$1`job_id`, `row_number`$2',
            $query
        );
        $query = preg_replace(
            '/\\((\\s*job_id\\s*,\\s*)row_number(\\s*,\\s*payload\\s*,\\s*status\\s*\\))/i',
            '($1`row_number`$2',
            $query
        );
        $query = preg_replace('/\\bORDER\\s+BY\\s+row_number\\s+ASC\\b/i', 'ORDER BY `row_number` ASC', $query);

        // Some installations already have this index although dbDelta attempts to add
        // it again (usually after a failed schema upgrade). Skip only that duplicate
        // ADD KEY operation; preserve the existing index and allow other migrations.
        if (preg_match('/^\\s*ALTER\\s+TABLE\\s+`?' . preg_quote($table, '/') . '`?\\s+ADD\\s+(?:KEY|INDEX)\\s+`?job_row`?\\b/i', $query)) {
            $index = $wpdb->get_var($wpdb->prepare(
                "SHOW INDEX FROM `$table` WHERE Key_name = %s",
                'job_row'
            ));
            if ($index) return 'SELECT 1';
        }

        return $query;
    }
}
