<?php
if (!defined('ABSPATH')) { exit; }

/** Persist image tasks and process download/metadata stages separately from product saves. */
class A1FI_Queue_Image_Importer {
    const LOCK_SECONDS = 90;
    const CRON_HOOK = 'a1fi_process_image_queue';
    const TABLE_SUFFIX = 'a1fi_image_queue';

    private static $images_by_sku = [];
    private static $images_by_name = [];

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function boot() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('init', [__CLASS__, 'ensure_cron']);
        // Priority 9 runs after row normalization/validation and before the queue worker at 10.
        add_action('a1fi_process_queue', [__CLASS__, 'prime_batch'], 9);
        // The save hook only inserts DB work; downloading and thumbnail generation happen in cron.
        add_action('woocommerce_after_product_object_save', [__CLASS__, 'enqueue_for_saved_product'], 10, 1);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_next']);
        self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            image_url text NOT NULL,
            url_hash char(32) NOT NULL,
            image_index int unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            error longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_url (product_id,url_hash),
            KEY status_id (status,id),
            KEY product_id (product_id)
        ) $charset;");
    }

    public static function cron_schedules($schedules) {
        $schedules['a1fi_image_minute'] = [
            'interval' => 60,
            'display' => __('Every minute (feed image import)', 'allinonefeedimporter'),
        ];
        return $schedules;
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 15, 'a1fi_image_minute', self::CRON_HOOK);
        }
    }

    /** Prime image mappings for the same queued product rows the queue worker will save. */
    public static function prime_batch() {
        self::$images_by_sku = [];
        self::$images_by_name = [];
        if (get_transient('a1fi_queue_lock')) return;

        global $wpdb;
        $job = $wpdb->get_row('SELECT id, feed_id FROM ' . A1FI_Queue::jobs_table() . " WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? [];
        $mapping = $feed['mapping'] ?? [];
        $image_column = $mapping['images'] ?? '';
        if ($image_column === '') return;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT payload FROM ' . A1FI_Queue::rows_table() . ' WHERE job_id=%d AND status=%s ORDER BY `row_number` ASC LIMIT %d',
            (int) $job->id,
            'queued',
            A1FI_Queue::BATCH_SIZE
        ));
        foreach ((array) $rows as $record) {
            $data = json_decode($record->payload, true);
            if (!is_array($data) || !array_key_exists($image_column, $data)) continue;
            $urls = self::parse_urls($data[$image_column]);
            if (!$urls) continue;

            $sku_column = $mapping['sku'] ?? '';
            $name_column = $mapping['name'] ?? '';
            if ($sku_column !== '' && !empty($data[$sku_column])) self::$images_by_sku[trim((string) $data[$sku_column])] = $urls;
            if ($name_column !== '' && !empty($data[$name_column])) self::$images_by_name[trim((string) $data[$name_column])] = $urls;
        }
    }

    /** Do no remote/file processing from this product-save hook; only enqueue durable tasks. */
    public static function enqueue_for_saved_product($product) {
        if (!$product instanceof WC_Product || !$product->get_id()) return;

        $sku = trim((string) $product->get_sku());
        $name = trim((string) $product->get_name());
        $urls = ($sku !== '' && isset(self::$images_by_sku[$sku]))
            ? self::$images_by_sku[$sku]
            : (self::$images_by_name[$name] ?? []);
        if (!$urls) return;

        foreach ($urls as $index => $url) {
            self::enqueue_image((int) $product->get_id(), $url, (int) $index);
        }
        self::schedule_worker(5);
    }

    /** One request handles one image stage: download/register OR thumbnail metadata. */
    public static function process_next() {
        if (get_transient('a1fi_image_queue_lock')) return;
        set_transient('a1fi_image_queue_lock', 1, self::LOCK_SECONDS);
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(35);

        try {
            global $wpdb;
            $table = self::table();
            $task = $wpdb->get_row("SELECT * FROM $table WHERE status IN ('queued','metadata') ORDER BY id ASC LIMIT 1");
            if (!$task) return;

            $attempts = (int) $task->attempts + 1;
            $wpdb->update($table, ['attempts' => $attempts, 'updated_at' => current_time('mysql')], ['id' => (int) $task->id], ['%d', '%s'], ['%d']);
            if ($task->status === 'queued') {
                self::download_and_register($task);
            } else {
                self::generate_metadata($task);
            }
        } catch (Throwable $error) {
            if (!empty($task)) self::fail_task($task, $error->getMessage());
        } finally {
            delete_transient('a1fi_image_queue_lock');
            self::schedule_worker(5);
        }
    }

    private static function enqueue_image($product_id, $url, $index) {
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql');
        $hash = md5($url);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (product_id,image_url,url_hash,image_index,status,created_at,updated_at) VALUES (%d,%s,%s,%d,%s,%s,%s)
             ON DUPLICATE KEY UPDATE status=IF(status='failed','queued',status), attempts=IF(status='failed',0,attempts), error=IF(status='failed',NULL,error), updated_at=IF(status='failed',VALUES(updated_at),updated_at)",
            (int) $product_id, $url, $hash, (int) $index, 'queued', $now, $now
        ));
    }

    private static function download_and_register($task) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($task->image_url, 30);
        if (is_wp_error($tmp)) {
            self::fail_task($task, $tmp->get_error_message());
            return;
        }

        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : false;
        $extension = self::extension_for_mime($mime);
        if (!$mime || strpos($mime, 'image/') !== 0 || !$extension) {
            @unlink($tmp);
            self::fail_task($task, __('Downloaded content is not a supported image format.', 'allinonefeedimporter'));
            return;
        }

        $upload = wp_handle_sideload([
            'name' => 'a1fi-' . wp_generate_password(12, false) . '.' . $extension,
            'tmp_name' => $tmp,
        ], ['test_form' => false]);
        if (!empty($upload['error'])) {
            if (file_exists($tmp)) @unlink($tmp);
            self::fail_task($task, $upload['error']);
            return;
        }

        $attachment_id = wp_insert_attachment([
            'guid' => $upload['url'],
            'post_mime_type' => $mime,
            'post_title' => sanitize_file_name(pathinfo($upload['file'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file'], (int) $task->product_id, true);
        if (is_wp_error($attachment_id)) {
            @unlink($upload['file']);
            self::fail_task($task, $attachment_id->get_error_message());
            return;
        }

        update_attached_file((int) $attachment_id, $upload['file']);
        self::assign_attachment((int) $task->product_id, (int) $attachment_id, (int) $task->image_index);

        global $wpdb;
        $wpdb->update(self::table(), [
            'status' => 'metadata',
            'attachment_id' => (int) $attachment_id,
            'attempts' => 0,
            'updated_at' => current_time('mysql'),
            'error' => null,
        ], ['id' => (int) $task->id], ['%s', '%d', '%d', '%s', '%s'], ['%d']);
    }

    /** Run expensive WordPress thumbnail generation outside the product-save request. */
    private static function generate_metadata($task) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $file = get_attached_file((int) $task->attachment_id);
        if (!$file || !is_readable($file)) {
            self::fail_task($task, __('The downloaded image attachment is missing or unreadable.', 'allinonefeedimporter'));
            return;
        }

        $metadata = wp_generate_attachment_metadata((int) $task->attachment_id, $file);
        if (is_wp_error($metadata) || !is_array($metadata)) {
            $message = is_wp_error($metadata) ? $metadata->get_error_message() : __('WordPress could not generate image metadata.', 'allinonefeedimporter');
            self::fail_task($task, $message);
            return;
        }
        wp_update_attachment_metadata((int) $task->attachment_id, $metadata);

        global $wpdb;
        $wpdb->update(self::table(), ['status' => 'completed', 'updated_at' => current_time('mysql'), 'error' => null], ['id' => (int) $task->id], ['%s', '%s', '%s'], ['%d']);
    }

    private static function assign_attachment($product_id, $attachment_id, $image_index) {
        if ((int) $image_index === 0 && !get_post_thumbnail_id($product_id)) {
            set_post_thumbnail($product_id, $attachment_id);
            return;
        }
        $gallery = (string) get_post_meta($product_id, '_product_image_gallery', true);
        $ids = $gallery !== '' ? array_filter(array_map('absint', explode(',', $gallery))) : [];
        if (!in_array($attachment_id, $ids, true)) {
            $ids[] = $attachment_id;
            update_post_meta($product_id, '_product_image_gallery', implode(',', $ids));
        }
    }

    private static function extension_for_mime($mime) {
        if (!$mime) return '';
        foreach ((array) wp_get_mime_types() as $extensions => $candidate_mime) {
            if ($candidate_mime === $mime) return explode('|', $extensions)[0];
        }
        return '';
    }

    private static function fail_task($task, $message) {
        $message = sanitize_text_field((string) $message);
        if ($task && !empty($task->id)) {
            global $wpdb;
            $wpdb->update(self::table(), ['status' => 'failed', 'error' => $message, 'updated_at' => current_time('mysql')], ['id' => (int) $task->id], ['%s', '%s', '%s'], ['%d']);
        }
        $prefix = $task && !empty($task->image_url) ? ' for ' . $task->image_url : '';
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error('Image import failed' . $prefix . ': ' . $message, ['source' => 'allinonefeedimporter']);
        } else {
            error_log('All In One Feed Importer: image import failed' . $prefix . ': ' . $message);
        }
    }

    private static function schedule_worker($delay = 5) {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_HOOK);
        }
    }

    private static function parse_urls($value) {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $text = trim((string) $value);
            if ($text === '') return [];
            $parts = preg_split('/[\r\n|;]+|,\s*(?=https?:\/\/)/i', $text, -1, PREG_SPLIT_NO_EMPTY);
        }
        $urls = [];
        foreach ((array) $parts as $part) {
            $url = trim((string) $part, " \t\n\r\0\x0B\"'");
            if ($url !== '' && wp_http_validate_url($url)) $urls[] = esc_url_raw($url);
        }
        return array_values(array_unique($urls));
    }
}

add_action('plugins_loaded', ['A1FI_Queue_Image_Importer', 'boot'], 16);
