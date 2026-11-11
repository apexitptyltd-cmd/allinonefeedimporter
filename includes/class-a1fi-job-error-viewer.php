<?php
if (!defined('ABSPATH')) { exit; }

/** Direct viewer for persisted job errors, independent of the optional event-log snapshot. */
class A1FI_Job_Error_Viewer {
    const JOB_LIMIT = 25;
    const FIELD_LIMIT = 500;

    public static function render() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !current_user_can('manage_woocommerce')) return;

        global $wpdb;
        $feed_filter = absint($_GET['a1fi_log_feed'] ?? 0);
        $jobs_table = A1FI_Queue::jobs_table();
        $rows_table = A1FI_Queue::rows_table();
        if ($feed_filter) {
            $jobs = $wpdb->get_results($wpdb->prepare(
                "SELECT id,feed_id,status,total_rows,processed_rows,errors,started_at FROM $jobs_table WHERE feed_id=%d AND (failed_count>0 OR (errors IS NOT NULL AND errors<>'')) ORDER BY id DESC LIMIT %d",
                $feed_filter, self::JOB_LIMIT
            ));
        } else {
            $jobs = $wpdb->get_results($wpdb->prepare(
                "SELECT id,feed_id,status,total_rows,processed_rows,errors,started_at FROM $jobs_table WHERE failed_count>0 OR (errors IS NOT NULL AND errors<>'') ORDER BY id DESC LIMIT %d",
                self::JOB_LIMIT
            ));
        }
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        ?>
        <section id="a1fi-job-error-details" class="notice notice-error" style="display:block;margin:12px 20px 12px 0;padding:12px 16px">
            <h2><?php esc_html_e('Import failure details', 'allinonefeedimporter'); ?></h2>
            <p><?php esc_html_e('Read directly from saved import-job errors and failed queue rows. Row numbers, mapped feed values, and error messages are shown where available.', 'allinonefeedimporter'); ?></p>
            <?php if (!$jobs): ?>
                <p><?php esc_html_e('No persisted import failures found for this filter.', 'allinonefeedimporter'); ?></p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="widefat striped"><thead><tr>
                        <th><?php esc_html_e('Job', 'allinonefeedimporter'); ?></th>
                        <th><?php esc_html_e('Feed', 'allinonefeedimporter'); ?></th>
                        <th><?php esc_html_e('Status', 'allinonefeedimporter'); ?></th>
                        <th><?php esc_html_e('Row', 'allinonefeedimporter'); ?></th>
                        <th><?php esc_html_e('Mapped values', 'allinonefeedimporter'); ?></th>
                        <th><?php esc_html_e('Error', 'allinonefeedimporter'); ?></th>
                    </tr></thead><tbody>
                    <?php foreach ($jobs as $job):
                        $feed = $feeds[$job->feed_id] ?? [];
                        $feed_name = $feed['name'] ?? sprintf(__('Feed #%d', 'allinonefeedimporter'), $job->feed_id);
                        $mapping = $feed['mapping'] ?? [];
                        $errors = $job->errors ? json_decode($job->errors, true) : [];
                        if (!is_array($errors)) $errors = $job->errors ? [(string) $job->errors] : [];
                        $failed_rows = $wpdb->get_results($wpdb->prepare(
                            "SELECT id,`row_number`,payload FROM $rows_table WHERE job_id=%d AND status='failed' ORDER BY `row_number` ASC LIMIT 500",
                            (int) $job->id
                        ));
                        $rows_by_number = [];
                        foreach ((array) $failed_rows as $failed_row) $rows_by_number[(int) $failed_row->row_number] = $failed_row;
                        $rendered = [];
                        foreach ($errors as $error) {
                            $error_text = is_scalar($error) ? (string) $error : wp_json_encode($error);
                            $display_row = 0;
                            if (preg_match('/^Row\s+(\d+)\s*:/i', $error_text, $matches)) $display_row = (int) $matches[1];
                            $db_row_number = $display_row > 0 ? $display_row - 1 : 0;
                            $failed_row = $db_row_number > 0 && isset($rows_by_number[$db_row_number]) ? $rows_by_number[$db_row_number] : null;
                            $mapped_values = [];
                            if ($failed_row) {
                                $payload = json_decode($failed_row->payload, true);
                                if (is_array($payload)) {
                                    foreach (['name','sku','regular_price','sale_price','stock_quantity','categories'] as $field) {
                                        $column = $mapping[$field] ?? '';
                                        if ($column === '' || !array_key_exists($column, $payload)) continue;
                                        $value = is_scalar($payload[$column]) ? (string) $payload[$column] : wp_json_encode($payload[$column]);
                                        $mapped_values[$field] = sanitize_text_field(substr($value, 0, self::FIELD_LIMIT));
                                    }
                                }
                            }
                            $rendered[] = ['row' => $display_row ?: ($failed_row ? (int) $failed_row->row_number + 1 : 0), 'values' => $mapped_values, 'error' => $error_text];
                        }
                        // Some product-save failures were counted without a corresponding
                        // legacy error string; still expose each failed queue row explicitly.
                        foreach ($rows_by_number as $number => $failed_row) {
                            $display_number = $number + 1;
                            $already_listed = false;
                            foreach ($rendered as $entry) if ($entry['row'] === $display_number) { $already_listed = true; break; }
                            if ($already_listed) continue;
                            $payload = json_decode($failed_row->payload, true);
                            $mapped_values = [];
                            if (is_array($payload)) {
                                foreach (['name','sku','regular_price','sale_price','stock_quantity','categories'] as $field) {
                                    $column = $mapping[$field] ?? '';
                                    if ($column !== '' && array_key_exists($column, $payload)) $mapped_values[$field] = sanitize_text_field(substr((string) $payload[$column], 0, self::FIELD_LIMIT));
                                }
                            }
                            $rendered[] = ['row' => $display_number, 'values' => $mapped_values, 'error' => __('Row failed, but this job has no matching error message. Check the WooCommerce logs.', 'allinonefeedimporter')];
                        }
                        if (!$rendered) $rendered[] = ['row' => 0, 'values' => [], 'error' => __('Job has a saved error payload; see raw details below.', 'allinonefeedimporter')];
                        foreach ($rendered as $entry):
                    ?>
                        <tr>
                            <td>#<?php echo (int) $job->id; ?><?php if ($entry === reset($rendered)): ?><br><small><?php echo (int) $job->processed_rows; ?> / <?php echo (int) $job->total_rows; ?> rows · <?php echo esc_html($job->started_at); ?></small><?php endif; ?></td>
                            <td><?php echo esc_html($feed_name); ?></td>
                            <td><?php echo esc_html($job->status); ?></td>
                            <td><?php echo $entry['row'] ? (int) $entry['row'] : '—'; ?></td>
                            <td><pre style="white-space:pre-wrap;max-width:420px;margin:0"><?php echo esc_html(wp_json_encode($entry['values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}'); ?></pre></td>
                            <td><?php echo esc_html($entry['error']); ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody></table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
}

add_action('admin_notices', ['A1FI_Job_Error_Viewer', 'render'], 21);
