<?php
if (!defined('ABSPATH')) { exit; }

/** Admin-only, on-demand repair for the importer's own database tables. */
class A1FI_Database_Repair {
    const RESULT_TRANSIENT_PREFIX = 'a1fi_db_repair_result_';

    public static function boot() {
        // Install the MariaDB reserved-word compatibility before the logger's plugins_loaded install hook.
        add_filter('query', [__CLASS__, 'quote_logger_row_number'], 1);
        add_action('admin_post_a1fi_repair_tables', [__CLASS__, 'repair_tables']);
        add_action('admin_notices', [__CLASS__, 'render_repair_ui'], 25);
    }

    /** Quote row_number only in SQL identifier positions for the logger table. */
    public static function quote_logger_row_number($query) {
        global $wpdb;
        $table = $wpdb->prefix . 'a1fi_import_logs';
        if (stripos($query, $table) === false) return $query;

        // dbDelta CREATE TABLE column definition.
        $query = preg_replace(
            '/(?<![`A-Za-z0-9_])row_number(?=\s+int\s+unsigned\s+NOT\s+NULL)/i',
            '`row_number`',
            $query
        );

        // Logger INSERT column list. Do not rewrite JSON/details values.
        $quoted_table = preg_quote($table, '/');
        $query = preg_replace_callback(
            '/(INSERT\s+IGNORE\s+INTO\s+`?' . $quoted_table . '`?\s*\()([^)]*)(\)\s*VALUES)/i',
            static function ($matches) {
                $columns = preg_replace('/(?<![`A-Za-z0-9_])row_number(?![`A-Za-z0-9_])/i', '`row_number`', $matches[2]);
                return $matches[1] . $columns . $matches[3];
            },
            $query
        );

        // Viewer SELECT lists whose FROM table is the logger table.
        $query = preg_replace_callback(
            '/(\bSELECT\s+)(.*?)(\bFROM\s+`?' . $quoted_table . '`?\b)/is',
            static function ($matches) {
                $columns = preg_replace('/(?<![`A-Za-z0-9_])row_number(?![`A-Za-z0-9_])/i', '`row_number`', $matches[2]);
                return $matches[1] . $columns . $matches[3];
            },
            $query
        );

        return $query;
    }

    public static function repair_tables() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to repair importer tables.', 'allinonefeedimporter'), 403);
        }
        check_admin_referer('a1fi_repair_tables');

        global $wpdb;
        $messages = [];
        $success = true;

        try {
            // These installers use dbDelta and preserve existing table data while adding missing schema.
            A1FI_Queue::install();
            A1FI_Import_Logger::install();

            $tables = [A1FI_Queue::jobs_table(), A1FI_Queue::rows_table(), A1FI_Import_Logger::table()];
            foreach ($tables as $table) {
                $pattern = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($table) : addcslashes($table, '_%\\');
                $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
                if ($found === $table) {
                    $messages[] = sprintf(__('Verified table: %s', 'allinonefeedimporter'), $table);
                } else {
                    $success = false;
                    $db_error = $wpdb->last_error ? ' ' . $wpdb->last_error : '';
                    $messages[] = sprintf(__('Table is still missing: %1$s.%2$s', 'allinonefeedimporter'), $table, $db_error);
                }
            }
        } catch (Throwable $error) {
            $success = false;
            $messages[] = sprintf(__('Repair failed: %s', 'allinonefeedimporter'), $error->getMessage());
        }

        $result = ['success' => $success, 'messages' => $messages];
        set_transient(self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('page', 'a1fi', admin_url('admin.php')));
        exit;
    }

    public static function render_repair_ui() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !current_user_can('manage_woocommerce')) return;

        $key = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
        $result = get_transient($key);
        if ($result) {
            delete_transient($key);
            $class = !empty($result['success']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p><strong>' . esc_html__('Importer database repair:', 'allinonefeedimporter') . '</strong></p><ul>';
            foreach ((array) ($result['messages'] ?? []) as $message) {
                echo '<li>' . esc_html($message) . '</li>';
            }
            echo '</ul></div>';
        }
        ?>
        <div class="notice notice-info" style="padding:12px 16px">
            <p><?php esc_html_e('Check and create or update the importer queue and log tables. Existing table data is preserved.', 'allinonefeedimporter'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="a1fi_repair_tables">
                <?php wp_nonce_field('a1fi_repair_tables'); ?>
                <?php submit_button(__('Check and repair importer tables', 'allinonefeedimporter'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}

add_action('plugins_loaded', ['A1FI_Database_Repair', 'boot'], 1);
