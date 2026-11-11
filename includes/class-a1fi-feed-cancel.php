<?php
if (!defined('ABSPATH')) { exit; }

/** Per-feed cancellation controls for active queued imports. */
class A1FI_Feed_Cancel {
    public static function boot() {
        add_action('wp_ajax_a1fi_cancel_feed_jobs', [__CLASS__, 'cancel_feed_jobs']);
        add_action('admin_footer', [__CLASS__, 'render_cancel_controls'], 45);
        add_filter('query', [__CLASS__, 'protect_cancelled_jobs']);
    }

    public static function cancel_feed_jobs() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_feed_cancel');
        $feed_id = absint($_POST['feed_id'] ?? 0);
        if (!$feed_id) wp_send_json_error(__('Invalid feed.', 'allinonefeedimporter'), 400);
        global $wpdb;
        $jobs_table = A1FI_Queue::jobs_table();
        $rows_table = A1FI_Queue::rows_table();
        $active_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $jobs_table WHERE feed_id=%d AND status IN ('queued','running')", $feed_id));
        if (!$active_ids) wp_send_json_success(['cancelled'=>0, 'message'=>__('No active import jobs for this feed.', 'allinonefeedimporter')]);
        $ids = array_map('absint', $active_ids);
        $id_list = implode(',', $ids);
        $now = current_time('mysql');
        $wpdb->query("UPDATE $jobs_table SET status='cancelled', finished_at=" . $wpdb->prepare('%s', $now) . ", message='Cancelled by administrator' WHERE feed_id=" . absint($feed_id) . " AND status IN ('queued','running')");
        // Rows already loaded into a worker batch may finish; remaining rows will not run.
        $wpdb->query("UPDATE $rows_table SET status='cancelled' WHERE job_id IN ($id_list) AND status='queued'");
        wp_clear_scheduled_hook('a1fi_process_queue');
        // Restore the recurring worker so other and future imports continue to run.
        A1FI_Queue::ensure_cron();
        wp_send_json_success(['cancelled'=>count($ids), 'message'=>__('Active import jobs cancelled. Any row already being processed may finish.', 'allinonefeedimporter')]);
    }

    /** Prevent a worker finishing a batch from overwriting a concurrent cancellation. */
    public static function protect_cancelled_jobs($query) {
        global $wpdb;
        $table = A1FI_Queue::jobs_table();
        if (stripos($query, 'UPDATE ' . $table) === false || !preg_match("/SET\\s+`?status`?\\s*=\\s*'completed'/i", $query)) return $query;
        if (strpos($query, 'WHERE') === false || stripos($query, "status <> 'cancelled'") !== false) return $query;
        return preg_replace('/\\s+WHERE\\s+/i', " WHERE status <> 'cancelled' AND ", $query, 1);
    }

    public static function render_cancel_controls() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !current_user_can('manage_woocommerce')) return;
        global $wpdb;
        $jobs_table = A1FI_Queue::jobs_table();
        $active = $wpdb->get_results("SELECT feed_id, COUNT(*) AS active_count FROM $jobs_table WHERE status IN ('queued','running') GROUP BY feed_id ORDER BY feed_id");
        if (!$active) return;
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $nonce = wp_create_nonce('a1fi_feed_cancel');
        ?>
        <template id="a1fi-feed-cancel-template">
            <section class="a1fi-feed-cancel-panel notice notice-info" style="margin:16px 0;padding:12px 16px">
                <h2><?php esc_html_e('Active imports by feed', 'allinonefeedimporter'); ?></h2>
                <p><?php esc_html_e('Cancel stops pending rows for that feed. A row already being processed may finish before the worker stops.', 'allinonefeedimporter'); ?></p>
                <table class="widefat striped"><thead><tr><th><?php esc_html_e('Feed', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Active jobs', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Action', 'allinonefeedimporter'); ?></th></tr></thead><tbody>
                <?php foreach ($active as $item): $feed_id = (int)$item->feed_id; $label = $feeds[$feed_id]['name'] ?? sprintf(__('Feed #%d', 'allinonefeedimporter'), $feed_id); ?>
                    <tr data-a1fi-cancel-feed-row="<?php echo $feed_id; ?>"><td><?php echo esc_html($label); ?></td><td><?php echo (int)$item->active_count; ?></td><td><button type="button" class="button button-link-delete a1fi-cancel-feed" data-feed-id="<?php echo $feed_id; ?>"><?php esc_html_e('Cancel import for this feed', 'allinonefeedimporter'); ?></button></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </section>
        </template>
        <script>
        (function(){const template=document.getElementById('a1fi-feed-cancel-template');const panel=template&&template.content.firstElementChild;if(panel){const heading=document.querySelector('.wrap h1');if(heading)heading.insertAdjacentElement('afterend',panel);else{const wrap=document.querySelector('.wrap');if(wrap)wrap.prepend(panel);}}const endpoint=typeof ajaxurl==='string'&&ajaxurl?ajaxurl:new URL('/wp-admin/admin-ajax.php',window.location.origin).href;const nonce=<?php echo wp_json_encode($nonce); ?>;document.addEventListener('click',async function(event){const button=event.target.closest('.a1fi-cancel-feed');if(!button)return;if(!window.confirm('<?php echo esc_js(__('Cancel all queued/running imports for this feed? Rows already in progress may finish.','allinonefeedimporter')); ?>'))return;button.disabled=true;const label=button.textContent;button.textContent='<?php echo esc_js(__('Cancelling…','allinonefeedimporter')); ?>';try{const body=new FormData();body.append('action','a1fi_cancel_feed_jobs');body.append('feed_id',button.dataset.feedId);body.append('_ajax_nonce',nonce);const response=await fetch(endpoint,{method:'POST',body,credentials:'same-origin'});const json=await response.json();if(!json.success)throw new Error(json.data||'Cancel failed');const row=button.closest('tr');row.cells[1].textContent='0';button.textContent='<?php echo esc_js(__('Cancelled','allinonefeedimporter')); ?>';}catch(error){alert(error.message);button.disabled=false;button.textContent=label;}});})();
        </script>
        <?php
    }
}
