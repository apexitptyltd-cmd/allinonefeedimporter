<?php
if (!defined('ABSPATH')) { exit; }

/** Attach feed images during queued imports (the queue worker previously omitted image handling). */
class A1FI_Queue_Image_Importer {
    private static $images_by_sku = [];
    private static $images_by_name = [];

    public static function boot() {
        // Prime the upcoming batch after normalization/validation and before the queue worker runs.
        add_action('a1fi_process_queue', [__CLASS__, 'prime_batch'], 9.5);
        add_action('woocommerce_after_product_object_save', [__CLASS__, 'attach_to_saved_product'], 10, 1);
    }

    public static function prime_batch() {
        self::$images_by_sku = [];
        self::$images_by_name = [];
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
            A1FI_Queue::BATCH_SIZE
        ));
        foreach ((array) $rows as $row) {
            $data = json_decode($row->payload, true);
            if (!is_array($data) || !array_key_exists($image_column, $data)) continue;
            $urls = self::parse_urls($data[$image_column]);
            if (!$urls) continue;

            $sku_column = $mapping['sku'] ?? '';
            $name_column = $mapping['name'] ?? '';
            if ($sku_column !== '' && !empty($data[$sku_column])) {
                self::$images_by_sku[(string) $data[$sku_column]] = $urls;
            }
            if ($name_column !== '' && !empty($data[$name_column])) {
                self::$images_by_name[trim((string) $data[$name_column])] = $urls;
            }
        }
    }

    public static function attach_to_saved_product($product) {
        if (!$product instanceof WC_Product || !$product->get_id()) return;

        $sku = (string) $product->get_sku();
        $name = trim((string) $product->get_name());
        $key = $sku !== '' ? $sku : '';
        $urls = $key !== '' && isset(self::$images_by_sku[$key])
            ? self::$images_by_sku[$key]
            : (isset(self::$images_by_name[$name]) ? self::$images_by_name[$name] : []);
        if (!$urls) return;

        unset(self::$images_by_sku[$key], self::$images_by_name[$name]);
        $attachment_ids = [];
        foreach ($urls as $url) {
            $attachment_id = self::sideload_image($url, (int) $product->get_id());
            if ($attachment_id) $attachment_ids[] = $attachment_id;
        }

        if ($attachment_ids) {
            set_post_thumbnail((int) $product->get_id(), $attachment_ids[0]);
            if (count($attachment_ids) > 1) {
                update_post_meta((int) $product->get_id(), '_product_image_gallery', implode(',', array_slice($attachment_ids, 1)));
            } else {
                delete_post_meta((int) $product->get_id(), '_product_image_gallery');
            }
        }
    }

    private static function parse_urls($value) {
        if (is_array($value)) {
            $parts = $value;
        } else {
            // Commas are supported when they separate URLs; commas inside a URL are retained.
            $parts = preg_split('/[\r\n|;]+|,\s*(?=https?:\/\/)/i', (string) $value);
        }

        $urls = [];
        foreach ((array) $parts as $part) {
            $url = trim((string) $part, " \t\n\r\0\x0B\"'");
            if ($url !== '' && wp_http_validate_url($url)) $urls[] = esc_url_raw($url);
        }
        return array_values(array_unique($urls));
    }

    /** Download by content rather than URL extension so signed/CDN URLs work too. */
    private static function sideload_image($url, $product_id) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temporary_file = download_url($url, 30);
        if (is_wp_error($temporary_file)) {
            error_log('All In One Feed Importer: image download failed for ' . $url . ': ' . $temporary_file->get_error_message());
            return 0;
        }

        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($temporary_file) : false;
        $extensions = wp_get_mime_types();
        $extension = '';
        foreach ($extensions as $candidate_extensions => $candidate_mime) {
            if ($candidate_mime === $mime) {
                $extension = explode('|', $candidate_extensions)[0];
                break;
            }
        }
        if (!$mime || strpos($mime, 'image/') !== 0 || !$extension) {
            @unlink($temporary_file);
            error_log('All In One Feed Importer: downloaded URL is not a supported image: ' . $url);
            return 0;
        }

        $file = [
            'name' => 'a1fi-' . wp_generate_password(12, false) . '.' . $extension,
            'tmp_name' => $temporary_file,
        ];
        $attachment_id = media_handle_sideload($file, $product_id);
        if (is_wp_error($attachment_id)) {
            if (file_exists($temporary_file)) @unlink($temporary_file);
            error_log('All In One Feed Importer: image sideload failed for ' . $url . ': ' . $attachment_id->get_error_message());
            return 0;
        }
        return (int) $attachment_id;
    }
}

add_action('plugins_loaded', ['A1FI_Queue_Image_Importer', 'boot'], 16);
