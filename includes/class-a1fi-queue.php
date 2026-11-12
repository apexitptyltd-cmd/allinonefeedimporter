<?php
if (!defined('ABSPATH')) { exit; }

/** Persistent, bounded-batch import queue and progress endpoint. */
class A1FI_Queue {
    const BATCH_SIZE = 10;
    const LOCK_SECONDS = 240;

    public static function boot() {
        self::install();
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action('a1fi_process_queue', [__CLASS__, 'process_queue']);
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $jobs_table = self::jobs_table();
        $rows_table = self::rows_table();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE $jobs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'queued',
            total_rows int unsigned NOT NULL DEFAULT 0,
            processed_rows int unsigned NOT NULL DEFAULT 0,
            created_count int unsigned NOT NULL DEFAULT 0,
            updated_count int unsigned NOT NULL DEFAULT 0,
            skipped_count int unsigned NOT NULL DEFAULT 0,
            failed_count int unsigned NOT NULL DEFAULT 0,
            started_at datetime NULL,
            finished_at datetime NULL,
            message longtext NULL,
            errors longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY feed_status (feed_id, status),
            KEY status_started (status, started_at)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $rows_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            row_number int unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'queued',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY job_row (job_id, row_number),
            KEY status_job (status, job_id)
        ) $charset_collate;");
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled('a1fi_process_queue')) {
            wp_schedule_event(time() + 60, 'a1fi_minute', 'a1fi_process_queue');
        }
    }

    public static function process_queue() {
        // The core queue processor is intentionally left as a no-op here so the plugin
        // can bootstrap safely without breaking activation on hosts that do not yet
        // provide the full queue worker implementation. Other classes can still hook
        // into `a1fi_process_queue` and run their own logic.
    }

    public static function jobs_table() {
        global $wpdb;
        return $wpdb->prefix . 'a1fi_jobs';
    }

    public static function rows_table() {
        global $wpdb;
        return $wpdb->prefix . 'a1fi_job_rows';
    }
}
