<?php
if (!defined('ABSPATH')) { exit; }

/** Persistent, bounded-batch import queue and progress endpoint. */
class A1FI_Queue {
    const BATCH_SIZE = 10;
    const LOCK_SECONDS = 240;

    public static function boot() {
        self::install();
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action('a1fi_process_queue', [__CLASS__, 'process_queue'], 10);
    }

    /** Register before A1FI_Plugin::boot() so the Run action enqueues instead of importing inline. */
    public static function register_run_handler() {
        add_action('admin_post_a1fi_run', [__CLASS__, 'enqueue_manual_run'], 0);
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

    /** Verify and enqueue a feed from the existing Run form. */
    public static function enqueue_manual_run() {
        $id = absint($_POST['feed_id'] ?? 0);
        check_admin_referer('a1fi_run_' . $id);
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Unauthorized.', 'allinonefeedimporter'));

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$id] ?? null;
        if (!$feed) self::run_redirect(__('Feed not found; save it and try again.', 'allinonefeedimporter'));

        $file = self::get_feed_file($feed);
        if (is_wp_error($file)) self::run_redirect($file->get_error_message());
        $previous_path = $_POST['item_path'] ?? null;
        $had_path = array_key_exists('item_path', $_POST);
        if (!empty($feed['item_path'])) $_POST['item_path'] = $feed['item_path'];
        $result = A1FI_Feed_Reader::read($file, $feed['file_type'] ?? 'csv', $feed['delimiter'] ?? ',');
        if ($had_path) $_POST['item_path'] = $previous_path; else unset($_POST['item_path']);
        if (!empty($file['temporary']) && is_file($file['path'])) @unlink($file['path']);
        if (is_wp_error($result)) self::run_redirect($result->get_error_message());

        $errors = A1FI_Feed_Reader::validate($feed['mapping'] ?? [], $result['headers']);
        if ($errors) self::run_redirect(implode(' ', $errors));
        if (empty($result['rows'])) self::run_redirect(__('The feed contains no product rows to import.', 'allinonefeedimporter'));

        global $wpdb;
        $jobs = self::jobs_table();
        $rows_table = self::rows_table();
        $now = current_time('mysql');
        if ($wpdb->insert($jobs, [
            'feed_id' => $id,
            'status' => 'queued',
            'total_rows' => count($result['rows']),
            'processed_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'started_at' => $now,
            'created_at' => $now,
        ], ['%d','%s','%d','%d','%d','%d','%d','%d','%s','%s']) === false) {
            self::run_redirect(sprintf(__('Could not create import job: %s', 'allinonefeedimporter'), $wpdb->last_error ?: __('database insert failed', 'allinonefeedimporter')));
        }
        $job_id = (int) $wpdb->insert_id;
        $batch = [];
        foreach ($result['rows'] as $index => $row) {
            $payload = wp_json_encode($row);
            if ($payload === false) {
                self::fail_enqueue($job_id, $rows_table, __('A feed row could not be encoded as JSON.', 'allinonefeedimporter'));
            }
            $batch[] = $wpdb->prepare('(%d,%d,%s,%s)', $job_id, $index + 1, $payload, 'queued');
            if (count($batch) >= self::BATCH_SIZE) {
                if (!self::insert_rows($rows_table, $batch)) {
                    self::fail_enqueue($job_id, $rows_table, sprintf(__('Could not save feed rows: %s', 'allinonefeedimporter'), $wpdb->last_error ?: __('database insert failed', 'allinonefeedimporter')));
                }
                $batch = [];
            }
        }
        if ($batch && !self::insert_rows($rows_table, $batch)) {
            self::fail_enqueue($job_id, $rows_table, sprintf(__('Could not save feed rows: %s', 'allinonefeedimporter'), $wpdb->last_error ?: __('database insert failed', 'allinonefeedimporter')));
        }
        $stored = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows_table WHERE job_id=%d", $job_id));
        if ($stored !== count($result['rows'])) {
            self::fail_enqueue($job_id, $rows_table, sprintf(__('Queue saved %1$d of %2$d feed rows. Check the database error log and start a new import.', 'allinonefeedimporter'), $stored, count($result['rows'])));
        }

        wp_schedule_single_event(time() + 5, 'a1fi_process_queue');
        self::run_redirect(sprintf(__('Import job #%d was queued with %d product rows.', 'allinonefeedimporter'), $job_id, $stored), $job_id);
    }

    private static function get_feed_file($feed) {
        if (($feed['source_type'] ?? '') === 'upload') {
            $path = $feed['file_path'] ?? '';
            if (!$path || !is_readable($path)) return new WP_Error('a1fi_queue_file', __('The uploaded feed file is missing or unreadable.', 'allinonefeedimporter'));
            return ['path' => $path, 'temporary' => false];
        }
        $url = $feed['source_url'] ?? '';
        if (!$url || !wp_http_validate_url($url)) return new WP_Error('a1fi_queue_url', __('Enter a valid feed URL.', 'allinonefeedimporter'));
        $response = wp_safe_remote_get($url, ['timeout' => 60, 'redirection' => 3, 'limit_response_size' => A1FI_Feed_Reader::MAX_BYTES]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return new WP_Error('a1fi_queue_http', __('Feed URL returned an unsuccessful HTTP status.', 'allinonefeedimporter'));
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > A1FI_Feed_Reader::MAX_BYTES) return new WP_Error('a1fi_queue_size', __('Feed exceeds 20 MB.', 'allinonefeedimporter'));
        $path = wp_tempnam('a1fi-feed');
        if (!$path || file_put_contents($path, $body) === false) return new WP_Error('a1fi_queue_temp', __('Could not save downloaded feed.', 'allinonefeedimporter'));
        return ['path' => $path, 'temporary' => true];
    }

    private static function insert_rows($table, $batch) {
        global $wpdb;
        return $wpdb->query('INSERT INTO ' . $table . ' (job_id,row_number,payload,status) VALUES ' . implode(',', $batch)) !== false;
    }

    private static function fail_enqueue($job_id, $rows_table, $message) {
        global $wpdb;
        $wpdb->delete($rows_table, ['job_id' => $job_id], ['%d']);
        $wpdb->update(self::jobs_table(), [
            'status' => 'failed',
            'finished_at' => current_time('mysql'),
            'message' => $message,
            'errors' => wp_json_encode([$message]),
        ], ['id' => $job_id], ['%s','%s','%s','%s'], ['%d']);
        self::run_redirect($message, $job_id);
    }

    private static function run_redirect($message, $job_id = 0) {
        $args = ['page' => 'a1fi', 'message' => rawurlencode($message)];
        if ($job_id) $args['a1fi_job'] = (int) $job_id;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /** Process one bounded batch; cron and the authenticated admin fallback call this action. */
    public static function process_queue() {
        if (get_transient('a1fi_queue_lock')) return;
        set_transient('a1fi_queue_lock', 1, self::LOCK_SECONDS);
        try {
            self::process_batch();
        } finally {
            delete_transient('a1fi_queue_lock');
        }
    }

    private static function process_batch() {
        global $wpdb;
        $jobs = self::jobs_table();
        $rows_table = self::rows_table();
        $job = $wpdb->get_row("SELECT * FROM $jobs WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? null;
        if (!$feed) {
            self::finish_job($job, 'failed', __('Feed configuration no longer exists.', 'allinonefeedimporter'));
            return;
        }
        $stored = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows_table WHERE job_id=%d", (int) $job->id));
        if ($stored !== (int) $job->total_rows) {
            self::finish_job($job, 'failed', sprintf(__('Queue contains %1$d of %2$d expected rows.', 'allinonefeedimporter'), $stored, (int) $job->total_rows));
            return;
        }
        $wpdb->update($jobs, ['status' => 'running'], ['id' => (int) $job->id, 'status' => 'queued'], ['%s'], ['%d','%s']);
        $batch = $wpdb->get_results($wpdb->prepare(
            "SELECT id,`row_number`,payload FROM $rows_table WHERE job_id=%d AND status='queued' ORDER BY `row_number` ASC LIMIT %d",
            (int) $job->id,
            self::BATCH_SIZE
        ));
        if (!$batch) {
            $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows_table WHERE job_id=%d AND status='queued'", (int) $job->id));
            if (!$pending) self::finish_job($job, 'completed', __('Import completed.', 'allinonefeedimporter'));
            return;
        }
        $errors = $job->errors ? json_decode($job->errors, true) : [];
        if (!is_array($errors)) $errors = [];
        foreach ($batch as $queued_row) {
            $data = json_decode($queued_row->payload, true);
            if (!is_array($data)) {
                $wpdb->update($rows_table, ['status' => 'failed'], ['id' => (int) $queued_row->id], ['%s'], ['%d']);
                $wpdb->query($wpdb->prepare("UPDATE $jobs SET processed_rows=processed_rows+1,failed_count=failed_count+1 WHERE id=%d", (int) $job->id));
                $errors[] = sprintf(__('Row %d: stored feed row is invalid JSON.', 'allinonefeedimporter'), (int) $queued_row->row_number);
                continue;
            }
            $outcome = self::import_product($data, $feed['mapping'] ?? []);
            if (is_wp_error($outcome)) {
                $wpdb->update($rows_table, ['status' => 'failed'], ['id' => (int) $queued_row->id], ['%s'], ['%d']);
                $wpdb->query($wpdb->prepare("UPDATE $jobs SET processed_rows=processed_rows+1,failed_count=failed_count+1 WHERE id=%d", (int) $job->id));
                if (count($errors) < 100) $errors[] = sprintf(__('Row %d: %s', 'allinonefeedimporter'), (int) $queued_row->row_number, $outcome->get_error_message());
                continue;
            }
            $wpdb->update($rows_table, ['status' => 'completed'], ['id' => (int) $queued_row->id], ['%s'], ['%d']);
            $counter = $outcome['updated'] ? 'updated_count' : 'created_count';
            $wpdb->query($wpdb->prepare("UPDATE $jobs SET processed_rows=processed_rows+1,`$counter`=`$counter`+1 WHERE id=%d", (int) $job->id));
        }
        $wpdb->update($jobs, ['errors' => $errors ? wp_json_encode($errors) : null, 'updated_at' => current_time('mysql')], ['id' => (int) $job->id], ['%s','%s'], ['%d']);
        $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE id=%d", (int) $job->id));
        if ($current && (int) $current->processed_rows >= (int) $current->total_rows) {
            self::finish_job($current, (int) $current->failed_count ? 'completed' : 'completed', sprintf(__('%1$d imported or updated; %2$d failed.', 'allinonefeedimporter'), (int) $current->created_count + (int) $current->updated_count, (int) $current->failed_count));
        } else {
            wp_schedule_single_event(time() + 5, 'a1fi_process_queue');
        }
    }

    private static function import_product($row, $mapping) {
        $values = [];
        foreach ($mapping as $field => $column) {
            if ($column !== '' && array_key_exists($column, $row)) $values[$field] = $row[$column];
        }
        $name = trim(wp_strip_all_tags((string) ($values['name'] ?? '')));
        if ($name === '') return new WP_Error('a1fi_queue_name', __('Missing product name.', 'allinonefeedimporter'));
        $sku = trim((string) ($values['sku'] ?? ''));
        $product_id = $sku !== '' ? wc_get_product_id_by_sku($sku) : 0;
        $updated = (bool) $product_id;
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
        if (!$product) $product = new WC_Product_Simple();
        try {
            $product->set_name($name);
            if ($sku !== '') $product->set_sku($sku);
            foreach (['description' => 'set_description', 'short_description' => 'set_short_description'] as $field => $method) {
                if (isset($values[$field])) $product->$method(wp_kses_post((string) $values[$field]));
            }
            foreach (['regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price'] as $field => $method) {
                if (isset($values[$field]) && is_numeric($values[$field])) $product->$method((string) $values[$field]);
            }
            $status = strtolower((string) ($values['status'] ?? 'publish'));
            $product->set_status(in_array($status, ['publish','draft','pending','private'], true) ? $status : 'publish');
            $id = $product->save();
            if (!$id) return new WP_Error('a1fi_queue_save', __('WooCommerce could not save product.', 'allinonefeedimporter'));
            if (!empty($values['categories'])) {
                $term_ids = [];
                foreach (explode(',', (string) $values['categories']) as $category) {
                    $category = trim($category);
                    if ($category === '') continue;
                    $term = term_exists($category, 'product_cat');
                    if (!$term) $term = wp_insert_term($category, 'product_cat');
                    if (!is_wp_error($term)) $term_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
                }
                if ($term_ids) $product->set_category_ids(array_values(array_unique($term_ids)));
            }
            if (isset($values['stock_quantity']) && is_numeric($values['stock_quantity'])) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity((int) $values['stock_quantity']);
            }
            $product->save();
            return ['id' => (int) $id, 'updated' => $updated];
        } catch (Throwable $error) {
            return new WP_Error('a1fi_queue_product', __('Product could not be imported; check the feed values and WooCommerce logs.', 'allinonefeedimporter'));
        }
    }

    private static function finish_job($job, $status, $message) {
        global $wpdb;
        $wpdb->update(self::jobs_table(), [
            'status' => $status,
            'finished_at' => current_time('mysql'),
            'message' => $message,
        ], ['id' => (int) $job->id, 'status' => ['queued','running']], ['%s','%s','%s'], ['%d','%s']);
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
