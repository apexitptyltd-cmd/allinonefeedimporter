<?php
if (!defined('ABSPATH')) { exit; }

/** Detect and recover queue jobs whose product rows failed to persist. */
class A1FI_Queue_Recovery {
    public static function boot() {
        // Run before A1FI_Queue::process_batch() so the native worker sees repaired rows.
        add_action('a1fi_process_queue', [__CLASS__, 'repair_empty_active_job'], 1);
        add_action('wp_ajax_a1fi_retry_empty_job', [__CLASS__, 'retry_empty_job']);
        add_action('admin_footer', [__CLASS__, 'jobs_ui'], 40);
    }

    public static function repair_empty_active_job() {
        if (get_transient('a1fi_queue_lock') || get_transient('a1fi_queue_repair_lock')) return;
        global $wpdb;
        $jobs = A1FI_Queue::jobs_table();
        $rows = A1FI_Queue::rows_table();
        $job = $wpdb->get_row("SELECT * FROM $jobs WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job || (int)$job->total_rows <= (int)$job->processed_rows) return;
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows WHERE job_id=%d", $job->id));
        if ($count > 0) return;

        set_transient('a1fi_queue_repair_lock', 1, 180);
        $result = self::restore_rows($job);
        delete_transient('a1fi_queue_repair_lock');
        if (is_wp_error($result)) {
            $wpdb->update($jobs, [
                'status' => 'failed',
                'finished_at' => current_time('mysql'),
                'message' => $result->get_error_message(),
                'errors' => wp_json_encode([$result->get_error_message()]),
            ], ['id' => (int)$job->id], ['%s','%s','%s','%s'], ['%d']);
        }
    }

    public static function retry_empty_job() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_queue_controls');
        $job_id = absint($_POST['job_id'] ?? 0);
        global $wpdb;
        $jobs = A1FI_Queue::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE id=%d", $job_id));
        if (!$job) wp_send_json_error(__('Import job not found.', 'allinonefeedimporter'), 404);
        if (!in_array($job->status, ['completed','failed'], true) || (int)$job->processed_rows !== 0 || (int)$job->total_rows < 1) {
            wp_send_json_error(__('Only jobs that finished without processing any rows can be retried this way.', 'allinonefeedimporter'), 409);
        }
        if (get_transient('a1fi_queue_lock') || get_transient('a1fi_queue_repair_lock')) {
            wp_send_json_error(__('The import worker is busy; wait a moment and retry.', 'allinonefeedimporter'), 409);
        }
        $wpdb->delete(A1FI_Queue::rows_table(), ['job_id'=>$job_id], ['%d']);
        $wpdb->update($jobs, ['status'=>'queued','processed_rows'=>0,'created_count'=>0,'updated_count'=>0,'skipped_count'=>0,'failed_count'=>0,'errors'=>null,'message'=>null,'finished_at'=>null], ['id'=>$job_id], ['%s','%d','%d','%d','%d','%d','%s','%s','%s'], ['%d']);
        do_action('a1fi_process_queue');
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $jobs WHERE id=%d", $job_id));
        wp_send_json_success(['status'=>$status ?: 'queued']);
    }

    private static function restore_rows($job) {
        global $wpdb;
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? null;
        if (!$feed) return new WP_Error('a1fi_repair_feed', __('Cannot retry: the feed configuration no longer exists.', 'allinonefeedimporter'));
        if (($feed['source_type'] ?? '') === 'upload') {
            $file = $feed['file_path'] ?? '';
            if (!$file || !is_readable($file)) return new WP_Error('a1fi_repair_file', __('Cannot retry: the uploaded feed file is missing or unreadable.', 'allinonefeedimporter'));
            $temporary = false;
        } else {
            $url = $feed['source_url'] ?? '';
            if (!$url || !wp_http_validate_url($url)) return new WP_Error('a1fi_repair_url', __('Cannot retry: the saved feed URL is invalid.', 'allinonefeedimporter'));
            $response = wp_safe_remote_get($url, ['timeout'=>60,'redirection'=>3,'limit_response_size'=>A1FI_Feed_Reader::MAX_BYTES]);
            if (is_wp_error($response)) return new WP_Error('a1fi_repair_download', sprintf(__('Cannot retry: feed download failed: %s', 'allinonefeedimporter'), $response->get_error_message()));
            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) return new WP_Error('a1fi_repair_http', sprintf(__('Cannot retry: feed URL returned HTTP %d.', 'allinonefeedimporter'), $code));
            $body = wp_remote_retrieve_body($response);
            if (strlen($body) > A1FI_Feed_Reader::MAX_BYTES) return new WP_Error('a1fi_repair_size', __('Cannot retry: feed exceeds the 20 MB limit.', 'allinonefeedimporter'));
            $file = wp_tempnam('a1fi-repair');
            if (!$file || file_put_contents($file, $body) === false) return new WP_Error('a1fi_repair_temp', __('Cannot retry: downloaded feed could not be saved.', 'allinonefeedimporter'));
            $temporary = true;
        }

        $had_path = array_key_exists('item_path', $_POST);
        $old_path = $_POST['item_path'] ?? null;
        if (!empty($feed['item_path'])) $_POST['item_path'] = $feed['item_path'];
        $result = A1FI_Feed_Reader::read($file, $feed['file_type'] ?? 'csv', $feed['delimiter'] ?? ',');
        if ($had_path) $_POST['item_path'] = $old_path; else unset($_POST['item_path']);
        if ($temporary && is_file($file)) @unlink($file);
        if (is_wp_error($result)) return new WP_Error('a1fi_repair_parse', sprintf(__('Cannot retry: feed could not be parsed: %s', 'allinonefeedimporter'), $result->get_error_message()));
        $errors = A1FI_Feed_Reader::validate($feed['mapping'] ?? [], $result['headers']);
        if ($errors) return new WP_Error('a1fi_repair_mapping', sprintf(__('Cannot retry: %s', 'allinonefeedimporter'), implode(' ', $errors)));
        $expected = (int)$job->total_rows;
        if (count($result['rows']) !== $expected) {
            return new WP_Error('a1fi_repair_changed', sprintf(__('Feed now contains %1$d rows; this job was created for %2$d. Start a new import to use the current feed.', 'allinonefeedimporter'), count($result['rows']), $expected));
        }

        $rows_table = A1FI_Queue::rows_table();
        $batch = [];
        foreach ($result['rows'] as $index => $row) {
            $payload = wp_json_encode($row);
            if ($payload === false) {
                $wpdb->delete($rows_table, ['job_id'=>(int)$job->id], ['%d']);
                return new WP_Error('a1fi_repair_encode', sprintf(__('Could not encode feed row %d.', 'allinonefeedimporter'), $index + 1));
            }
            $batch[] = $wpdb->prepare('(%d,%d,%s,%s)', (int)$job->id, $index + 1, $payload, 'queued');
            // Small chunks avoid oversized SQL packets for XML records with long descriptions.
            if (count($batch) >= 10) {
                if ($wpdb->query('INSERT INTO ' . $rows_table . ' (job_id,row_number,payload,status) VALUES ' . implode(',', $batch)) === false) {
                    $error = $wpdb->last_error ?: __('Database rejected the queue rows.', 'allinonefeedimporter');
                    $wpdb->delete($rows_table, ['job_id'=>(int)$job->id], ['%d']);
                    return new WP_Error('a1fi_repair_insert', sprintf(__('Could not store import rows: %s', 'allinonefeedimporter'), $error));
                }
                $batch = [];
            }
        }
        if ($batch && $wpdb->query('INSERT INTO ' . $rows_table . ' (job_id,row_number,payload,status) VALUES ' . implode(',', $batch)) === false) {
            $error = $wpdb->last_error ?: __('Database rejected the queue rows.', 'allinonefeedimporter');
            $wpdb->delete($rows_table, ['job_id'=>(int)$job->id], ['%d']);
            return new WP_Error('a1fi_repair_insert', sprintf(__('Could not store import rows: %s', 'allinonefeedimporter'), $error));
        }
        $stored = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rows_table WHERE job_id=%d", $job->id));
        if ($stored !== $expected) {
            $wpdb->delete($rows_table, ['job_id'=>(int)$job->id], ['%d']);
            return new WP_Error('a1fi_repair_count', sprintf(__('Queue storage contains %1$d of %2$d rows; check the database error log and retry.', 'allinonefeedimporter'), $stored, $expected));
        }
        return true;
    }

    public static function jobs_ui() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !current_user_can('manage_woocommerce')) return;
        $nonce = wp_create_nonce('a1fi_queue_controls');
        ?>
        <script>
        (function(){const nonce=<?php echo wp_json_encode($nonce); ?>;const endpoint=typeof ajaxurl==='string'&&ajaxurl?ajaxurl:new URL('/wp-admin/admin-ajax.php',window.location.origin).href;async function request(action,id){const fd=new FormData();fd.append('action',action);fd.append('job_id',id);fd.append('_ajax_nonce',nonce);const response=await fetch(endpoint,{method:'POST',body:fd,credentials:'same-origin'});const json=await response.json();if(!json.success)throw new Error(json.data||'Request failed');return json.data;}
        document.querySelectorAll('tr[data-a1fi-job-row]').forEach(function(row){const status=(row.querySelector('.a1fi-job-status')||{}).textContent.trim();const progress=row.cells[3]?row.cells[3].textContent:'';if((status==='completed'||status==='failed')&&/^\s*0\s*\/\s*[1-9]/.test(progress)&&row.cells[5]&&!row.querySelector('.a1fi-retry-empty-job')){const button=document.createElement('button');button.type='button';button.className='button a1fi-retry-empty-job';button.dataset.jobId=row.dataset.a1fiJobRow;button.textContent='<?php echo esc_js(__('Retry import','allinonefeedimporter')); ?>';row.cells[5].appendChild(button);}});
        document.addEventListener('click',async function(event){const button=event.target.closest('.a1fi-retry-empty-job');if(!button)return;if(!window.confirm('<?php echo esc_js(__('Retry this job from the current saved feed?','allinonefeedimporter')); ?>'))return;button.disabled=true;const original=button.textContent;button.textContent='<?php echo esc_js(__('Retrying…','allinonefeedimporter')); ?>';try{await request('a1fi_retry_empty_job',button.dataset.jobId);window.location.href=new URL('?page=a1fi&a1fi_job='+encodeURIComponent(button.dataset.jobId),window.location.href).href;}catch(error){alert(error.message);button.disabled=false;button.textContent=original;}});
        const params=new URLSearchParams(window.location.search),jobId=params.get('a1fi_job'),progress=document.querySelector('.a1fi-progress-text');if(jobId&&progress){async function pollDiagnostics(){try{const url=new URL(endpoint,window.location.href);url.searchParams.set('action','a1fi_job_status');url.searchParams.set('job_id',jobId);url.searchParams.set('_ajax_nonce',<?php echo wp_json_encode(wp_create_nonce('a1fi_job_status')); ?>);const response=await fetch(url,{credentials:'same-origin'});const json=await response.json();if(!json.success)return;const job=json.data;if(job.status==='failed'&&Array.isArray(job.errors)&&job.errors.length)progress.textContent+=' Error: '+job.errors.join(' ');if(job.status==='completed'&&job.total>0&&job.processed===0)progress.textContent='Job completed without processing rows. Use Retry import in the jobs table, or check the feed mappings and server database error log.';if(job.status==='completed'||job.status==='failed')clearInterval(timer);}catch(e){}}pollDiagnostics();const timer=setInterval(pollDiagnostics,4000);}
        })();
        </script>
        <?php
    }
}
