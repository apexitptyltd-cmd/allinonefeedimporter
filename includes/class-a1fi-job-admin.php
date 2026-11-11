<?php
if (!defined('ABSPATH')) { exit; }

/** Admin controls for inspecting and maintaining queued imports. */
class A1FI_Job_Admin {
    public static function boot() {
        add_action('admin_footer', [__CLASS__, 'render_jobs_ui']);
        add_action('wp_ajax_a1fi_process_queue_now', [__CLASS__, 'process_queue_now']);
        add_action('wp_ajax_a1fi_delete_queued_job', [__CLASS__, 'delete_queued_job']);
    }

    private static function can_manage() {
        return current_user_can('manage_woocommerce');
    }

    public static function process_queue_now() {
        if (!self::can_manage()) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_queue_controls');

        $job_id = absint($_POST['job_id'] ?? 0);
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare('SELECT id,status FROM ' . A1FI_Queue::jobs_table() . ' WHERE id=%d', $job_id));
        if (!$job) wp_send_json_error(__('Import job not found.', 'allinonefeedimporter'), 404);
        if (!in_array($job->status, ['queued', 'running'], true)) {
            wp_send_json_success(['status' => $job->status, 'message' => __('This job is no longer waiting to run.', 'allinonefeedimporter')]);
        }

        // The normal worker is cron-triggered. This authenticated admin action provides
        // a fallback when a host has WP-Cron events scheduled but does not dispatch them.
        do_action('a1fi_process_queue');
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . A1FI_Queue::jobs_table() . ' WHERE id=%d', $job_id));
        wp_send_json_success(['status' => $status ?: $job->status]);
    }

    public static function delete_queued_job() {
        if (!self::can_manage()) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_queue_controls');
        $job_id = absint($_POST['job_id'] ?? 0);
        global $wpdb;
        $table = A1FI_Queue::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $table WHERE id=%d", $job_id));
        if (!$job) wp_send_json_error(__('Import job not found.', 'allinonefeedimporter'), 404);
        if ($job->status !== 'queued') wp_send_json_error(__('Only queued jobs can be deleted. Running or completed jobs are retained.', 'allinonefeedimporter'), 409);

        $wpdb->delete(A1FI_Queue::rows_table(), ['job_id' => $job_id], ['%d']);
        $wpdb->delete($table, ['id' => $job_id, 'status' => 'queued'], ['%d', '%s']);
        wp_send_json_success(['deleted' => true]);
    }

    public static function render_jobs_ui() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !self::can_manage()) return;
        global $wpdb;
        $jobs_table = A1FI_Queue::jobs_table();
        $jobs = $wpdb->get_results("SELECT id,feed_id,status,total_rows,processed_rows,created_count,updated_count,skipped_count,failed_count,started_at FROM $jobs_table ORDER BY id DESC LIMIT 25");
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $nonce = wp_create_nonce('a1fi_queue_controls');
        $has_active_job = false;
        ?>
        <template id="a1fi-jobs-template">
            <section class="a1fi-jobs-panel" style="margin-top:24px">
                <h2><?php esc_html_e('Import jobs', 'allinonefeedimporter'); ?></h2>
                <p><?php esc_html_e('Recent import jobs and their status. The job progress page can run a batch from your browser if the server cron worker is not dispatching.', 'allinonefeedimporter'); ?></p>
                <div class="a1fi-jobs-table-wrap">
                    <table class="widefat striped"><thead><tr>
                        <th><?php esc_html_e('Job', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Feed', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Status', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Progress', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Started', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Actions', 'allinonefeedimporter'); ?></th>
                    </tr></thead><tbody>
                    <?php if (!$jobs): ?><tr><td colspan="6"><?php esc_html_e('No import jobs found.', 'allinonefeedimporter'); ?></td></tr><?php endif; ?>
                    <?php foreach ($jobs as $job): $active = in_array($job->status, ['queued','running'], true); $has_active_job = $has_active_job || $active; $feed_name = $feeds[$job->feed_id]['name'] ?? sprintf(__('Feed #%d', 'allinonefeedimporter'), $job->feed_id); ?>
                        <tr data-a1fi-job-row="<?php echo (int)$job->id; ?>">
                            <td>#<?php echo (int)$job->id; ?></td><td><?php echo esc_html($feed_name); ?></td><td class="a1fi-job-status"><?php echo esc_html($job->status); ?></td>
                            <td><?php echo (int)$job->processed_rows; ?> / <?php echo (int)$job->total_rows; ?> (<?php echo $job->total_rows ? (int)floor(($job->processed_rows / $job->total_rows) * 100) : ($job->status === 'completed' ? 100 : 0); ?>%)<br><small><?php printf(esc_html__('Created %1$d, updated %2$d, skipped %3$d, failed %4$d', 'allinonefeedimporter'), $job->created_count, $job->updated_count, $job->skipped_count, $job->failed_count); ?></small></td>
                            <td><?php echo esc_html($job->started_at); ?></td>
                            <td><a class="button" href="<?php echo esc_url(add_query_arg(['page'=>'a1fi','a1fi_job'=>(int)$job->id], admin_url('admin.php'))); ?>"><?php esc_html_e('View progress', 'allinonefeedimporter'); ?></a>
                                <?php if ($active): ?><button type="button" class="button a1fi-run-queue" data-job-id="<?php echo (int)$job->id; ?>"><?php esc_html_e('Process now', 'allinonefeedimporter'); ?></button><?php endif; ?>
                                <?php if ($job->status === 'queued'): ?><button type="button" class="button-link-delete a1fi-delete-job" data-job-id="<?php echo (int)$job->id; ?>"><?php esc_html_e('Delete', 'allinonefeedimporter'); ?></button><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
            </section>
        </template>
        <script>
        (function(){const wrap=document.querySelector('.wrap'),template=document.getElementById('a1fi-jobs-template');if(wrap&&template)wrap.appendChild(template.content.cloneNode(true));const nonce=<?php echo wp_json_encode($nonce); ?>;const ajax=typeof ajaxurl==='string'&&ajaxurl?ajaxurl:new URL('/wp-admin/admin-ajax.php',window.location.origin).href;async function post(action,id){const fd=new FormData();fd.append('action',action);fd.append('job_id',id);fd.append('_ajax_nonce',nonce);const r=await fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(!d.success)throw new Error(d.data||'Request failed');return d.data;}document.addEventListener('click',async function(e){const run=e.target.closest('.a1fi-run-queue');if(run){run.disabled=true;const old=run.textContent;run.textContent='<?php echo esc_js(__('Processing…','allinonefeedimporter')); ?>';try{await post('a1fi_process_queue_now',run.dataset.jobId);window.location.href=run.closest('tr').querySelector('a.button').href;}catch(err){alert(err.message);run.disabled=false;run.textContent=old;}return;}const del=e.target.closest('.a1fi-delete-job');if(del){if(!window.confirm('<?php echo esc_js(__('Delete this queued import and its pending rows?','allinonefeedimporter')); ?>'))return;del.disabled=true;try{await post('a1fi_delete_queued_job',del.dataset.jobId);del.closest('tr').remove();}catch(err){alert(err.message);del.disabled=false;}}});
        <?php if ($has_active_job && !empty($_GET['a1fi_job'])): ?>
        const activeId=<?php echo absint($_GET['a1fi_job']); ?>;async function kickWorker(){try{const result=await post('a1fi_process_queue_now',activeId);if(result.status==='completed'||result.status==='failed')clearInterval(workerTimer);}catch(e){/* Cron remains available; retry on the next interval. */}}kickWorker();const workerTimer=setInterval(kickWorker,5000);
        <?php endif; ?>
        })();
        </script>
        <?php
    }
}
