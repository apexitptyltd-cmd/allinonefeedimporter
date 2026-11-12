<?php
if (!defined('ABSPATH')) { exit; }

/** Safely clear completed import history while preserving active jobs and pending image work. */
class A1FI_History_Cleanup {
    const RESULT_PREFIX = 'a1fi_history_cleanup_';

    public static function boot() {
        add_action('admin_post_a1fi_clear_history', [__CLASS__, 'clear_history']);
        add_action('admin_notices', [__CLASS__, 'render_controls'], 26);
    }

    public static function clear_history() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to clear importer history.', 'allinonefeedimporter'), 403);
        }
        check_admin_referer('a1fi_clear_history');

        global $wpdb;
        $jobs = A1FI_Queue::jobs_table();
        $rows = A1FI_Queue::rows_table();
        $logs = A1FI_Import_Logger::table();
        $image_table = A1FI_Queue_Image_Importer::table();
        $active = "status IN ('queued','running')";
        $result = ['success' => true, 'counts' => []];

        // Delete details before parent jobs. Active jobs and their diagnostic entries are retained.
        $wpdb->query("DELETE r FROM $rows r INNER JOIN $jobs j ON j.id=r.job_id WHERE j.$active");
        $result['counts']['rows'] = (int) $wpdb->rows_affected;

        $wpdb->query("DELETE l FROM $logs l LEFT JOIN $jobs j ON j.id=l.job_id WHERE j.id IS NULL OR j.status NOT IN ('queued','running')");
        $result['counts']['logs'] = (int) $wpdb->rows_affected;

        $wpdb->query("DELETE FROM $jobs WHERE status NOT IN ('queued','running')");
        $result['counts']['jobs'] = (int) $wpdb->rows_affected;

        // Image tasks still queued or at the metadata stage are work in progress, not history.
        $wpdb->query("DELETE FROM $image_table WHERE status IN ('completed','failed')");
        $result['counts']['image_tasks'] = (int) $wpdb->rows_affected;

        if ($wpdb->last_error) {
            $result['success'] = false;
            $result['error'] = sanitize_text_field($wpdb->last_error);
        }

        set_transient(self::RESULT_PREFIX . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('page', 'a1fi', admin_url('admin.php')));
        exit;
    }

    public static function render_controls() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !current_user_can('manage_woocommerce')) return;

        $key = self::RESULT_PREFIX . get_current_user_id();
        $result = get_transient($key);
        if ($result) {
            delete_transient($key);
            $class = !empty($result['success']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p><strong>' . esc_html__('Importer history cleanup:', 'allinonefeedimporter') . '</strong> ';
            if (!empty($result['success'])) {
                printf(
                    esc_html__('Deleted %1$d completed jobs, %2$d job rows, %3$d logs, and %4$d finished image tasks. Active jobs and pending image work were kept.', 'allinonefeedimporter'),
                    (int) ($result['counts']['jobs'] ?? 0),
                    (int) ($result['counts']['rows'] ?? 0),
                    (int) ($result['counts']['logs'] ?? 0),
                    (int) ($result['counts']['image_tasks'] ?? 0)
                );
            } else {
                esc_html_e('Cleanup encountered a database error: ', 'allinonefeedimporter');
                echo esc_html($result['error'] ?? __('unknown error', 'allinonefeedimporter'));
            }
            echo '</p></div>';
        }
        ?>
        <div class="notice notice-warning" style="padding:12px 16px">
            <p><?php esc_html_e('Remove completed and failed job history, associated rows and logs, and finished image tasks. Queued or running jobs and pending image tasks are preserved.', 'allinonefeedimporter'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(__('Delete completed importer jobs and logs? This cannot be undone. Active jobs will be preserved.', 'allinonefeedimporter')); ?>');">
                <input type="hidden" name="action" value="a1fi_clear_history">
                <?php wp_nonce_field('a1fi_clear_history'); ?>
                <?php submit_button(__('Clear completed jobs and logs', 'allinonefeedimporter'), 'delete', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}

add_action('plugins_loaded', ['A1FI_History_Cleanup', 'boot'], 2);
