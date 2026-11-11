<?php
if (!defined('ABSPATH')) { exit; }

/** MariaDB compatibility for the queue's reserved row_number identifier. */
class A1FI_Database_Compatibility {
    public static function boot() {
        add_filter('query', [__CLASS__, 'quote_queue_row_number']);
    }

    public static function quote_queue_row_number($query) {
        global $wpdb;
        $table = $wpdb->prefix . 'a1fi_job_rows';
        if (stripos($query, $table) === false) return $query;

        // The importer has historically used row_number as a column name. Some MariaDB
        // versions parse it as a reserved/window-function token in INSERT/ORDER BY SQL.
        // Quote only SQL identifier positions, never JSON/string payload data.
        $query = preg_replace(
            '/\((\s*job_id\s*,\s*)row_number(\s*,\s*payload\s*,\s*status\s*\))/i',
            '($1`row_number`$2',
            $query
        );
        $query = preg_replace('/\bORDER\s+BY\s+row_number\s+ASC\b/i', 'ORDER BY `row_number` ASC', $query);
        return $query;
    }
}
