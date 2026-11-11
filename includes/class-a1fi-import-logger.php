<?php
if (!defined('ABSPATH')) { exit; }

/** Persistent per-feed and per-row import diagnostics with an admin viewer. */
class A1FI_Import_Logger {
    const MAX_FIELD_LENGTH = 500;
    const VIEW_LIMIT = 250;

    public static function boot() {
        self::install();
        // Observe persisted row statuses after the queue worker has released its lock.
        add_action('a1fi_process_queue', [__CLASS__, 'capture_latest_job'], 20);
        // Render in the admin notices area so the viewer appears near the top of the
        // Feed Importer screen instead of being easy to miss at the footer.
        add_action('admin_notices', [__CLASS__, 'render_viewer'], 20);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'a1fi_import_logs';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) unsigned NOT NULL,
            job_id bigint(20) unsigned NOT NULL,
            row_id bigint(20) unsigned NOT NULL DEFAULT 0,
            row_number int unsigned NOT NULL DEFAULT 0,
            event varchar(32) NOT NULL,
            details longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_row_event (job_id,row_id,event),
            KEY feed_created (feed_id,created_at),
            KEY job_created (job_id,created_at)
        ) $charset;");
    }

    /** Capture row outcomes after each worker invocation; unique index makes this idempotent. */
    public static function capture_latest_job() {
        if (get_transient('a1fi_queue_lock')) return;
        global $wpdb;
        $jobs = A1FI_Queue::jobs_table();
        $rows = A1FI_Queue::rows_table();
        $logs = self::table();
        $job = $wpdb->get_row("SELECT id,feed_id,status,total_rows,processed_rows,created_count,updated_count,skipped_count,failed_count,message,errors FROM $jobs WHERE status IN ('queued','running','completed','failed','cancelled') ORDER BY id DESC LIMIT 1");
        if (!$job) return;

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? [];
        $mapping = $feed['mapping'] ?? [];
        $stored_events = $wpdb->get_col($wpdb->prepare("SELECT event FROM $logs WHERE job_id=%d AND row_id=0", (int) $job->id));
        $stored_events = is_array($stored_events) ? $stored_events : [];
        if (!in_array('job_started', $stored_events, true)) {
            self::write((int) $job->feed_id, (int) $job->id, 0, 0, 'job_started', [
                'status' => $job->status,
                'expected_rows' => (int) $job->total_rows,
                'feed' => sanitize_text_field($feed['name'] ?? ''),
            ]);
        }

        $terminal_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id,r.`row_number`,r.payload,r.status FROM $rows r LEFT JOIN $logs l ON l.job_id=r.job_id AND l.row_id=r.id AND l.event='row_processed' WHERE r.job_id=%d AND r.status IN ('completed','failed','skipped','cancelled') AND l.id IS NULL ORDER BY r.`row_number` ASC LIMIT %d",
            (int) $job->id,
            500
        ));
        foreach ((array) $terminal_rows as $row) {
            $data = json_decode($row->payload, true);
            if (!is_array($data)) $data = [];
            $fields = [];
            foreach (['name','sku','regular_price','sale_price','stock_quantity','status','categories'] as $field) {
                $column = $mapping[$field] ?? '';
                if ($column === '' || !array_key_exists($column, $data)) continue;
                $value = is_scalar($data[$column]) ? (string) $data[$column] : wp_json_encode($data[$column]);
                $fields[$field] = sanitize_text_field(substr($value, 0, self::MAX_FIELD_LENGTH));
            }
            $row_number = (int) $row->row_number;
            $details = ['outcome' => $row->status, 'mapped_fields' => $fields];
            $errors = $job->errors ? json_decode($job->errors, true) : [];
            if (is_array($errors)) {
                $expected_label = sprintf(__('Row %d:', 'allinonefeedimporter'), $row_number + 1);
                foreach ($errors as $error) {
                    if (is_string($error) && strpos($error, $expected_label) === 0) {
                        $details['error'] = $error;
                        break;
                    }
                }
            }
            self::write((int) $job->feed_id, (int) $job->id, (int) $row->id, $row_number, 'row_processed', $details);
        }

        $stored_events = $wpdb->get_col($wpdb->prepare("SELECT event FROM $logs WHERE job_id=%d AND row_id=0", (int) $job->id));
        $stored_events = is_array($stored_events) ? $stored_events : [];
        if (in_array($job->status, ['completed','failed','cancelled'], true) && !in_array('job_finished', $stored_events, true)) {
            self::write((int) $job->feed_id, (int) $job->id, 0, 0, 'job_finished', [
                'status' => $job->status,
                'expected_rows' => (int) $job->total_rows,
                'processed_rows' => (int) $job->processed_rows,
                'created' => (int) $job->created_count,
                'updated' => (int) $job->updated_count,
                'skipped' => (int) $job->skipped_count,
                'failed' => (int) $job->failed_count,
                'message' => sanitize_text_field($job->message ?? ''),
            ]);
        }
    }

    private static function write($feed_id, $job_id, $row_id, $row_number, $event, $details) {
        global $wpdb;
        $encoded = wp_json_encode($details);
        if ($encoded === false) $encoded = '{}';
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() . ' (feed_id,job_id,row_id,row_number,event,details,created_at) VALUES (%d,%d,%d,%d,%s,%s,%s)',
            $feed_id, $job_id, $row_id, $row_number, sanitize_key($event), $encoded, current_time('mysql')
        ));
    }

    public static function render_viewer() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !current_user_can('manage_woocommerce')) return;
        global $wpdb;
        $feed_id = absint($_GET['a1fi_log_feed'] ?? 0);
        $table = self::table();
        if ($feed_id) {
            $items = $wpdb->get_results($wpdb->prepare("SELECT id,feed_id,job_id,row_number,event,details,created_at FROM $table WHERE feed_id=%d ORDER BY id DESC LIMIT %d", $feed_id, self::VIEW_LIMIT));
        } else {
            $items = $wpdb->get_results($wpdb->prepare("SELECT id,feed_id,job_id,row_number,event,details,created_at FROM $table ORDER BY id DESC LIMIT %d", self::VIEW_LIMIT));
        }
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        ?>
        <section id="a1fi-import-logs" class="notice notice-info a1fi-import-log-panel" style="display:block;margin:12px 20px 12px 0;padding:12px 16px">
            <h2><?php esc_html_e('Import logs', 'allinonefeedimporter'); ?></h2>
            <p><?php esc_html_e('Detailed job and row outcomes, including mapped identifiers, prices, and validation errors. The viewer shows up to 250 newest entries.', 'allinonefeedimporter'); ?></p>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="a1fi">
                <label><?php esc_html_e('Filter by feed', 'allinonefeedimporter'); ?>
                    <select name="a1fi_log_feed"><option value="0"><?php esc_html_e('All feeds', 'allinonefeedimporter'); ?></option>
                    <?php foreach ($feeds as $id => $feed): ?><option value="<?php echo (int) $id; ?>" <?php selected($feed_id, (int) $id); ?>><?php echo esc_html($feed['name'] ?? sprintf(__('Feed #%d', 'allinonefeedimporter'), $id)); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <button class="button" type="submit"><?php esc_html_e('Filter logs', 'allinonefeedimporter'); ?></button>
            </form>
            <div style="overflow-x:auto;margin-top:12px">
                <table class="widefat striped"><thead><tr>
                    <th><?php esc_html_e('Time', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Feed', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Job', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Row', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Event', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Details', 'allinonefeedimporter'); ?></th>
                </tr></thead><tbody>
                <?php if (!$items): ?><tr><td colspan="6"><?php esc_html_e('No log entries yet. New activity is recorded as imports run.', 'allinonefeedimporter'); ?></td></tr><?php endif; ?>
                <?php foreach ($items as $item):
                    $feed_name = $feeds[$item->feed_id]['name'] ?? sprintf(__('Feed #%d', 'allinonefeedimporter'), $item->feed_id);
                    $details = $item->details ? json_decode($item->details, true) : [];
                    $detail_text = wp_json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                ?>
                    <tr><td><?php echo esc_html($item->created_at); ?></td><td><?php echo esc_html($feed_name); ?></td><td>#<?php echo (int) $item->job_id; ?></td><td><?php echo $item->row_number ? (int) $item->row_number + 1 : '—'; ?></td><td><?php echo esc_html($item->event); ?></td><td><pre style="white-space:pre-wrap;max-width:700px;margin:0"><?php echo esc_html($detail_text ?: '{}'); ?></pre></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </section>
        <?php
    }
}

add_action('plugins_loaded', ['A1FI_Import_Logger', 'boot'], 15);
