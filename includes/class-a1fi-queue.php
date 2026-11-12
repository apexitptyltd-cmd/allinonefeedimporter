<?php
if (!defined('ABSPATH')) { exit; }

/** Persistent, bounded-batch import queue and progress endpoint. */
class A1FI_Queue {
    const BATCH_SIZE = 10;
    const LOCK_SECONDS = 240;
    public static function jobs_table() { global $wpdb; return $wpdb->prefix . 'a1fi_jobs'; }
    public static function rows_table() { global $wpdb; return $wpdb->prefix . 'a1fi_job_rows'; }
