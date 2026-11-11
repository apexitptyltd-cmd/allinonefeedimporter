<?php
if (!defined('ABSPATH')) { exit; }

/** Prevent workers from silently completing jobs whose queue rows were not all stored. */
class A1FI_Queue_Integrity {
    /** Allow an in-flight enqueue request to finish before classifying a job as incomplete. */
    const ENQUEUE_GRACE_SECONDS = 300;

    public static function boot() {
        // Run before recovery and the queue worker. Empty jobs are left to the existing
        // recovery handler; partial jobs are failed so the worker cannot report success.
        add_action('a1fi_process_queue', [__CLASS__, 'check_next_job'], 0);
    }

    public static function check_next_job() {
        global $wpdb;
        $jobs_table = A1FI_Queue::jobs_table();
        $rows_table = A1FI_Queue::rows_table();
        $job = $wpdb->get_row("SELECT id, status, total_rows, started_at FROM $jobs_table WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;

        $expected = (int) $job->total_rows;
        $stored = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $rows_table WHERE job_id=%d",
            (int) $job->id
        ));
        if ($stored === $expected) return;

        // A queue job is created before its rows are inserted. Avoid treating a concurrent
        // enqueue as a database failure; a persistent mismatch is checked on a later tick.
        $started = strtotime($job->started_at);
        if (!$started || (time() - $started) < self::ENQUEUE_GRACE_SECONDS) return;

        $message = sprintf(
            __('Import stopped: the queue contains %1$d of %2$d expected feed rows. No further products were processed. Check the database error log and start a new import.', 'allinonefeedimporter'),
            $stored,
            $expected
        );
        $wpdb->update(
            $jobs_table,
            [
                'status' => 'failed',
                'finished_at' => current_time('mysql'),
                'message' => $message,
                'errors' => wp_json_encode([$message]),
            ],
            ['id' => (int) $job->id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }
}

add_action('plugins_loaded', ['A1FI_Queue_Integrity', 'boot'], 15);
