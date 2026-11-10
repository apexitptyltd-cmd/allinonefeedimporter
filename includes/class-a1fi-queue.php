<?php
if (!defined('ABSPATH')) { exit; }

/** Persistent, bounded-batch import queue and progress endpoint. */
class A1FI_Queue {
    const BATCH_SIZE = 50;
    const LOCK_SECONDS = 240;
    public static function jobs_table() { global $wpdb; return $wpdb->prefix . 'a1fi_jobs'; }
    public static function rows_table() { global $wpdb; return $wpdb->prefix . 'a1fi_job_rows'; }

    public static function boot() {
        self::install();
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action('a1fi_process_queue', [__CLASS__, 'process_batch']);
        // Replace legacy synchronous handlers once the original plugin has registered them.
        remove_action('admin_post_a1fi_run', ['A1FI_Plugin', 'manual_run']);
        add_action('admin_post_a1fi_run', [__CLASS__, 'start_from_admin']);
        remove_action('a1fi_check_schedules', ['A1FI_Plugin', 'run_scheduled']);
        add_action('a1fi_check_schedules', [__CLASS__, 'queue_due_feeds']);
        add_action('wp_ajax_a1fi_job_status', [__CLASS__, 'job_status']);
        add_action('admin_footer', [__CLASS__, 'progress_ui']);
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $jobs = self::jobs_table();
        $rows = self::rows_table();
        dbDelta("CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            total_rows int unsigned NOT NULL DEFAULT 0,
            processed_rows int unsigned NOT NULL DEFAULT 0,
            created_count int unsigned NOT NULL DEFAULT 0,
            updated_count int unsigned NOT NULL DEFAULT 0,
            skipped_count int unsigned NOT NULL DEFAULT 0,
            failed_count int unsigned NOT NULL DEFAULT 0,
            errors longtext NULL,
            message text NULL,
            started_at datetime NOT NULL,
            finished_at datetime NULL,
            PRIMARY KEY  (id),
            KEY status_started (status, started_at),
            KEY feed_started (feed_id, started_at)
        ) $collate;");
        dbDelta("CREATE TABLE $rows (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            row_number int unsigned NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            PRIMARY KEY  (id),
            UNIQUE KEY job_row (job_id, row_number),
            KEY job_status (job_id, status)
        ) $collate;");
        update_option('a1fi_queue_db_version', '1');
    }

    public static function cron_schedules($schedules) {
        $schedules['a1fi_queue_minute'] = ['interval' => 60, 'display' => __('Every minute (feed import queue)', 'allinonefeedimporter')];
        return $schedules;
    }
    public static function ensure_cron() {
        if (!wp_next_scheduled('a1fi_process_queue')) wp_schedule_event(time() + 30, 'a1fi_queue_minute', 'a1fi_process_queue');
    }

    public static function start_from_admin() {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Unauthorized.', 'allinonefeedimporter'));
        $feed_id = absint($_POST['feed_id'] ?? 0);
        check_admin_referer('a1fi_run_' . $feed_id);
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        if (empty($feeds[$feed_id])) wp_die(esc_html__('Feed not found.', 'allinonefeedimporter'));
        $job_id = self::enqueue_feed($feed_id, $feeds[$feed_id]);
        if (is_wp_error($job_id)) wp_die(esc_html($job_id->get_error_message()));
        wp_schedule_single_event(time() + 2, 'a1fi_process_queue');
        wp_safe_redirect(add_query_arg(['page' => 'a1fi', 'a1fi_job' => $job_id], admin_url('admin.php')));
        exit;
    }

    public static function queue_due_feeds() {
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $now = current_datetime();
        $day = strtolower($now->format('l'));
        $time = $now->format('H:i');
        $slot = $now->format('Y-m-d H:i');
        foreach ($feeds as $id => $feed) {
            if (empty($feed['enabled']) || empty($feed['schedule_enabled']) || ($feed['schedule_time'] ?? '') !== $time || !in_array($day, $feed['schedule_days'] ?? [], true) || ($feed['last_slot'] ?? '') === $slot) continue;
            $job_id = self::enqueue_feed((int)$id, $feed, $slot);
            if (!is_wp_error($job_id)) {
                $fresh = get_option(A1FI_Plugin::OPTION, []);
                if (isset($fresh[$id])) { $fresh[$id]['last_slot'] = $slot; update_option(A1FI_Plugin::OPTION, $fresh); }
            }
        }
    }

    /** Read/validate once, then persist records individually so product processing stays bounded. */
    private static function enqueue_feed($feed_id, $feed, $slot = '') {
        global $wpdb;
        $file = self::source_file($feed);
        if (is_wp_error($file)) return $file;
        $result = A1FI_Feed_Reader::read($file, $feed['file_type'] ?? 'csv', $feed['delimiter'] ?? ',');
        if (is_file($file) && strpos(basename($file), 'a1fi-feed') === 0) @unlink($file);
        if (is_wp_error($result)) return $result;
        $errors = A1FI_Feed_Reader::validate($feed['mapping'] ?? [], $result['headers']);
        if ($errors) return new WP_Error('a1fi_mapping', implode(' ', $errors));
        $wpdb->insert(self::jobs_table(), ['feed_id'=>(int)$feed_id, 'status'=>'queued', 'total_rows'=>count($result['rows']), 'started_at'=>current_time('mysql')], ['%d','%s','%d','%s']);
        $job_id = (int)$wpdb->insert_id;
        if (!$job_id) return new WP_Error('a1fi_job', __('Could not create import job.', 'allinonefeedimporter'));
        $batch = [];
        foreach ($result['rows'] as $index => $row) {
            $batch[] = $wpdb->prepare('(%d,%d,%s,%s)', $job_id, $index + 1, wp_json_encode($row), 'queued');
            if (count($batch) >= self::BATCH_SIZE) { $wpdb->query('INSERT INTO ' . self::rows_table() . ' (job_id,row_number,payload,status) VALUES ' . implode(',', $batch)); $batch = []; }
        }
        if ($batch) $wpdb->query('INSERT INTO ' . self::rows_table() . ' (job_id,row_number,payload,status) VALUES ' . implode(',', $batch));
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        if (isset($feeds[$feed_id])) {
            $feeds[$feed_id]['last_run'] = current_time('mysql');
            $feeds[$feed_id]['last_result'] = sprintf(__('Queued import job #%d.', 'allinonefeedimporter'), $job_id);
            if ($slot) $feeds[$feed_id]['last_slot'] = $slot;
            update_option(A1FI_Plugin::OPTION, $feeds);
        }
        return $job_id;
    }

    private static function source_file($feed) {
        if (($feed['source_type'] ?? '') === 'upload') return $feed['file_path'] ?? '';
        $url = $feed['source_url'] ?? '';
        if (!$url || !wp_http_validate_url($url)) return new WP_Error('a1fi_url', __('Enter a valid feed URL.', 'allinonefeedimporter'));
        $response = wp_safe_remote_get($url, ['timeout'=>30, 'redirection'=>3, 'limit_response_size'=>A1FI_Feed_Reader::MAX_BYTES]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return new WP_Error('a1fi_http', __('Feed URL returned an unsuccessful HTTP status.', 'allinonefeedimporter'));
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > A1FI_Feed_Reader::MAX_BYTES) return new WP_Error('a1fi_size', __('Feed exceeds 20 MB.', 'allinonefeedimporter'));
        $tmp = wp_tempnam('a1fi-feed');
        if (!$tmp || file_put_contents($tmp, $body) === false) return new WP_Error('a1fi_temp', __('Could not save downloaded feed.', 'allinonefeedimporter'));
        return $tmp;
    }

    public static function process_batch() {
        global $wpdb;
        if (get_transient('a1fi_queue_lock')) return;
        set_transient('a1fi_queue_lock', 1, self::LOCK_SECONDS);
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(45);
        try {
            $job = $wpdb->get_row("SELECT * FROM " . self::jobs_table() . " WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
            if (!$job) return;
            if ($job->status === 'queued') $wpdb->update(self::jobs_table(), ['status'=>'running'], ['id'=>(int)$job->id], ['%s'], ['%d']);
            $feeds = get_option(A1FI_Plugin::OPTION, []);
            $feed = $feeds[$job->feed_id] ?? [];
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::rows_table() . ' WHERE job_id=%d AND status=%s ORDER BY row_number ASC LIMIT %d', $job->id, 'queued', self::BATCH_SIZE));
            if (!$rows) {
                $wpdb->update(self::jobs_table(), ['status'=>'completed','finished_at'=>current_time('mysql'),'message'=>__('Import completed.', 'allinonefeedimporter')], ['id'=>(int)$job->id], ['%s','%s','%s'], ['%d']);
                self::update_feed_result($job->feed_id, $job->id);
                return;
            }
            $seen = [];
            foreach ($rows as $record) {
                $data = json_decode($record->payload, true);
                $sku_col = $feed['mapping']['sku'] ?? '';
                $sku = $sku_col ? trim((string)($data[$sku_col] ?? '')) : '';
                if ($sku !== '' && isset($seen[$sku])) {
                    $wpdb->update(self::rows_table(), ['status'=>'skipped'], ['id'=>(int)$record->id], ['%s'], ['%d']);
                    self::count($job->id, 'skipped_count');
                    continue;
                }
                if ($sku !== '') $seen[$sku] = true;
                $outcome = self::import_product($data, $feed['mapping'] ?? []);
                if (is_wp_error($outcome)) {
                    $wpdb->update(self::rows_table(), ['status'=>'failed'], ['id'=>(int)$record->id], ['%s'], ['%d']);
                    self::record_error($job->id, sprintf(__('Row %1$d: %2$s', 'allinonefeedimporter'), $record->row_number + 1, $outcome->get_error_message()));
                    self::count($job->id, 'failed_count');
                } else {
                    $wpdb->update(self::rows_table(), ['status'=>'completed'], ['id'=>(int)$record->id], ['%s'], ['%d']);
                    self::count($job->id, $outcome['created'] ? 'created_count' : 'updated_count');
                }
                self::count($job->id, 'processed_rows');
            }
            $remaining = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::rows_table() . ' WHERE job_id=%d AND status=%s', $job->id, 'queued'));
            if (!$remaining) {
                $wpdb->update(self::jobs_table(), ['status'=>'completed','finished_at'=>current_time('mysql'),'message'=>__('Import completed.', 'allinonefeedimporter')], ['id'=>(int)$job->id], ['%s','%s','%s'], ['%d']);
                self::update_feed_result($job->feed_id, $job->id);
            } else {
                wp_schedule_single_event(time() + 5, 'a1fi_process_queue');
            }
        } finally {
            delete_transient('a1fi_queue_lock');
        }
    }

    private static function count($job_id, $column) {
        global $wpdb;
        $allowed = ['processed_rows','created_count','updated_count','skipped_count','failed_count'];
        if (in_array($column, $allowed, true)) $wpdb->query($wpdb->prepare('UPDATE ' . self::jobs_table() . " SET $column = $column + 1 WHERE id=%d", $job_id));
    }
    private static function record_error($job_id, $message) {
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare('SELECT errors FROM ' . self::jobs_table() . ' WHERE id=%d', $job_id));
        $errors = $job && $job->errors ? json_decode($job->errors, true) : [];
        if (count($errors) < 100) $errors[] = sanitize_text_field($message);
        $wpdb->update(self::jobs_table(), ['errors'=>wp_json_encode($errors)], ['id'=>(int)$job_id], ['%s'], ['%d']);
    }
    private static function update_feed_result($feed_id, $job_id) {
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE id=%d', $job_id));
        if (!$job) return;
        $message = sprintf(__('%1$d/%2$d processed; %3$d created, %4$d updated, %5$d skipped, %6$d failed.', 'allinonefeedimporter'), $job->processed_rows, $job->total_rows, $job->created_count, $job->updated_count, $job->skipped_count, $job->failed_count);
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        if (isset($feeds[$feed_id])) { $feeds[$feed_id]['last_result'] = $message; update_option(A1FI_Plugin::OPTION, $feeds); }
    }

    private static function import_product($row, $mapping) {
        $v = [];
        foreach ($mapping as $field=>$column) if ($column !== '' && isset($row[$column])) $v[$field] = $row[$column];
        $name = trim(wp_strip_all_tags((string)($v['name'] ?? '')));
        if ($name === '') return new WP_Error('a1fi_name', __('Missing product name.', 'allinonefeedimporter'));
        $sku = trim((string)($v['sku'] ?? ''));
        $existing = $sku !== '' ? wc_get_product_id_by_sku($sku) : 0;
        $product = $existing ? wc_get_product($existing) : new WC_Product_Simple();
        if (!$product) $product = new WC_Product_Simple();
        try {
            $product->set_name($name);
            if ($sku !== '') $product->set_sku($sku);
            foreach (['description'=>'set_description','short_description'=>'set_short_description'] as $field=>$method) if (isset($v[$field])) $product->$method(wp_kses_post((string)$v[$field]));
            foreach (['regular_price'=>'set_regular_price','sale_price'=>'set_sale_price'] as $field=>$method) if (isset($v[$field])) { if (!is_numeric($v[$field])) return new WP_Error('a1fi_price', sprintf(__('Invalid %s.', 'allinonefeedimporter'), $field)); $product->$method((string)$v[$field]); }
            if (isset($v['stock_quantity'])) { if (!is_numeric($v['stock_quantity'])) return new WP_Error('a1fi_stock', __('Stock quantity must be numeric.', 'allinonefeedimporter')); $product->set_manage_stock(true); $product->set_stock_quantity((int)$v['stock_quantity']); }
            $status = strtolower((string)($v['status'] ?? 'publish'));
            $product->set_status(in_array($status, ['publish','draft','pending','private'], true) ? $status : 'publish');
            $product_id = $product->save();
            if (!$product_id) return new WP_Error('a1fi_save', __('WooCommerce could not save product.', 'allinonefeedimporter'));
            if (!empty($v['categories'])) { $terms=[]; foreach (explode(',', (string)$v['categories']) as $term_name) { $term_name=trim($term_name); if (!$term_name) continue; $term=term_exists($term_name,'product_cat'); if (!$term) $term=wp_insert_term($term_name,'product_cat'); if (!is_wp_error($term)) $terms[]=(int)(is_array($term)?$term['term_id']:$term); } if ($terms) wp_set_object_terms($product_id,$terms,'product_cat'); }
            return ['id'=>$product_id,'created'=>!$existing];
        } catch (Throwable $e) { return new WP_Error('a1fi_product', __('Product import failed; verify values and WooCommerce logs.', 'allinonefeedimporter')); }
    }

    public static function job_status() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_job_status');
        global $wpdb;
        $id = absint($_GET['job_id'] ?? 0);
        $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE id=%d', $id));
        if (!$job) wp_send_json_error(__('Import job not found.', 'allinonefeedimporter'), 404);
        $percent = $job->total_rows ? min(100, (int)floor(($job->processed_rows / $job->total_rows) * 100)) : ($job->status === 'completed' ? 100 : 0);
        wp_send_json_success(['id'=>(int)$job->id,'status'=>$job->status,'total'=>(int)$job->total_rows,'processed'=>(int)$job->processed_rows,'percent'=>$percent,'created'=>(int)$job->created_count,'updated'=>(int)$job->updated_count,'skipped'=>(int)$job->skipped_count,'failed'=>(int)$job->failed_count,'errors'=>$job->errors ? json_decode($job->errors,true) : []]);
    }

    public static function progress_ui() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || empty($_GET['a1fi_job'])) return;
        $job_id = absint($_GET['a1fi_job']);
        ?>
        <script>
        (function(){const id=<?php echo (int)$job_id; ?>;const wrap=document.createElement('div');wrap.className='notice notice-info';wrap.innerHTML='<p><strong><?php echo esc_js(__('Import progress','allinonefeedimporter')); ?></strong></p><progress max="100" value="0" style="width:100%;height:22px"></progress><p class="a1fi-progress-text"><?php echo esc_js(__('Waiting for the server cron worker…','allinonefeedimporter')); ?></p>';const target=document.querySelector('.wrap h1');if(target)target.after(wrap);const bar=wrap.querySelector('progress'),text=wrap.querySelector('.a1fi-progress-text');async function poll(){try{const u=new URL(ajaxurl);u.searchParams.set('action','a1fi_job_status');u.searchParams.set('job_id',id);u.searchParams.set('_ajax_nonce','<?php echo esc_js(wp_create_nonce('a1fi_job_status')); ?>');const r=await fetch(u,{credentials:'same-origin'}),d=await r.json();if(!d.success)throw Error(d.data||'Status unavailable');const j=d.data;bar.value=j.percent;text.textContent=`${j.status}: ${j.processed}/${j.total} rows (${j.percent}%). Created ${j.created}, updated ${j.updated}, skipped ${j.skipped}, failed ${j.failed}.`;if(j.status==='completed'||j.status==='failed'){clearInterval(timer);text.textContent+=' <?php echo esc_js(__('Refresh the page to see final feed status.','allinonefeedimporter')); ?>';}}catch(e){text.textContent=e.message;}}poll();const timer=setInterval(poll,3000)})();
        </script>
        <?php
    }
}
