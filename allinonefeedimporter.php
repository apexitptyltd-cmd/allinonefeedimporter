<?php
if (!defined('ABSPATH')) { exit; }

/** Queue image work separately from product saves so thumbnail generation does not block the import batch. */
class A1FI_Queue_Image_Importer {
    const BATCH_SIZE = 25;
    const LOCK_SECONDS = 90;
    const MAX_ATTEMPTS = 3;
    const CRON_HOOK = 'a1fi_process_image_queue';
    const TABLE_SUFFIX = 'a1fi_image_queue';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function boot() {
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action('a1fi_process_queue', [__CLASS__, 'queue_batch_images'], 10);
        add_action('woocommerce_after_product_object_save', [__CLASS__, 'enqueue_product_images'], 10, 1);
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

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 15, 'a1fi_image_minute', self::CRON_HOOK);
        }
    }

    public static function cron_schedules($schedules) {
        $schedules['a1fi_image_minute'] = [
            'interval' => 60,
            'display' => __('Every minute (feed image import)', 'allinonefeedimporter'),
        ];
        return $schedules;
    }

    public static function queue_batch_images() {
        if (get_transient('a1fi_queue_lock')) return;
        global $wpdb;

        $jobs_table = A1FI_Queue::jobs_table();
        $rows_table = A1FI_Queue::rows_table();
        $job = $wpdb->get_row("SELECT id, feed_id FROM $jobs_table WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? [];
        $mapping = $feed['mapping'] ?? [];
        $image_column = $mapping['images'] ?? '';
        if ($image_column === '') return;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT payload FROM $rows_table WHERE job_id=%d AND status=%s ORDER BY `row_number` ASC LIMIT %d",
            (int) $job->id,
            'queued',
            self::BATCH_SIZE
        ));
        foreach ((array) $rows as $row) {
            $data = json_decode($row->payload, true);
            if (!is_array($data) || !array_key_exists($image_column, $data)) continue;
            $urls = self::parse_urls($data[$image_column]);
            if (!$urls) continue;

            $product_id = 0;
            $sku_column = $mapping['sku'] ?? '';
            $name_column = $mapping['name'] ?? '';
            $sku = $sku_column !== '' ? trim((string) ($data[$sku_column] ?? '')) : '';
            $name = $name_column !== '' ? trim((string) ($data[$name_column] ?? '')) : '';
            if ($sku !== '') {
                $product_id = (int) wc_get_product_id_by_sku($sku);
            }
            if (!$product_id && $name !== '') {
                $product_id = (int) self::find_product_by_name($name);
            }
            if (!$product_id) continue;

            foreach ($urls as $index => $url) {
                self::enqueue_image((int) $product_id, $url, (int) $index);
            }
        }
    }

    public static function enqueue_product_images($product) {
        if (!$product instanceof WC_Product || !$product->get_id()) return;
        $sku = trim((string) $product->get_sku());
        $raw = $sku !== '' ? self::lookup_by_sku($sku) : [];
        if (!$raw) {
            $name = trim((string) $product->get_name());
            $raw = $name !== '' ? self::lookup_by_name($name) : [];
        }
        if (!$raw) return;

        foreach ($raw as $index => $url) {
            self::enqueue_image((int) $product->get_id(), $url, (int) $index);
        }
    }

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

            if ((int) $task->attempts >= self::MAX_ATTEMPTS) {
                self::fail_task($task, __('Image task exceeded the retry limit.', 'allinonefeedimporter'));
                return;
            }

            $wpdb->update($table, ['attempts' => (int) $task->attempts + 1, 'updated_at' => current_time('mysql')], ['id' => (int) $task->id], ['%d', '%s'], ['%d']);

            if ($task->status === 'queued') {
                self::download_and_register($task);
            } else {
                self::generate_metadata($task);
            }
        } catch (Throwable $error) {
            if (!empty($task)) self::fail_task($task, $error->getMessage());
        } finally {
            delete_transient('a1fi_image_queue_lock');
            if (wp_next_scheduled(self::CRON_HOOK) === false) {
                wp_schedule_single_event(time() + 5, self::CRON_HOOK);
            }
        }
    }

    private static function enqueue_image($product_id, $url, $index) {
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql');
        $hash = md5($url);
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (product_id,image_url,url_hash,image_index,status,created_at,updated_at) VALUES (%d,%s,%s,%d,%s,%s,%s)",
            (int) $product_id,
            $url,
            $hash,
            (int) $index,
            'queued',
            $now,
            $now
        ));
    }

    private static function download_and_register($task) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
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
            @unlink($tmp);
            self::fail_task($task, $upload['error']);
            return;
        }

        $attachment_id = wp_insert_attachment([
            'guid' => $upload['url'],
            'post_mime_type' => $mime,
            'post_title' => sanitize_file_name(pathinfo($upload['file'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file'], true);

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
            'updated_at' => current_time('mysql'),
            'error' => null,
        ], ['id' => (int) $task->id], ['%s', '%d', '%s', '%s'], ['%d']);
    }

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
        $message_prefix = $task && !empty($task->image_url) ? ' for ' . $task->image_url : '';
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error('Image import failed' . $message_prefix . ': ' . $message, ['source' => 'allinonefeedimporter']);
        } else {
            error_log('All In One Feed Importer: image import failed' . $message_prefix . ': ' . $message);
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

    private static function lookup_by_sku($sku) {
        $images = get_option('a1fi_images_by_sku', []);
        return isset($images[$sku]) ? (array) $images[$sku] : [];
    }

    private static function lookup_by_name($name) {
        $images = get_option('a1fi_images_by_name', []);
        return isset($images[$name]) ? (array) $images[$name] : [];
    }

    private static function find_product_by_name($name) {
        global $wpdb;
        $posts = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='product' AND post_title=%s LIMIT 1", $name));
        return $posts ? (int) $posts : 0;
    }
}

add_action('plugins_loaded', ['A1FI_Queue_Image_Importer', 'boot'], 16);

if (!function_exists('a1fi_register_image_queue')) {
    add_action('plugins_loaded', static function () {
        if (!class_exists('A1FI_Queue_Image_Importer')) return;
        add_filter('cron_schedules', ['A1FI_Queue_Image_Importer', 'cron_schedules']);
        add_action('init', ['A1FI_Queue_Image_Importer', 'ensure_cron']);
    }, 20);
}
