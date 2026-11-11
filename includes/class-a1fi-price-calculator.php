<?php
if (!defined('ABSPATH')) { exit; }

/** Per-feed price adjustment and normalization for Excel-style CSV cell values. */
class A1FI_Price_Calculator {
    const BATCH_SIZE = 50;

    public static function boot() {
        add_filter('pre_update_option_a1fi_feeds', [__CLASS__, 'save_settings'], 20, 2);
        add_action('admin_footer', [__CLASS__, 'render_settings']);
        // Transform queued rows before validation and product creation.
        add_action('a1fi_process_queue', [__CLASS__, 'prepare_next_batch'], 8);
    }

    public static function save_settings($feeds, $old_feeds) {
        if (!is_admin() || ($_POST['action'] ?? '') !== 'a1fi_save' || !is_array($feeds)) return $feeds;

        $id = absint($_POST['feed_id'] ?? 0);
        if (!$id) {
            $added = array_diff_key($feeds, (array) $old_feeds);
            if ($added) {
                $keys = array_keys($added);
                $id = (int) end($keys);
            }
        }
        if (!$id || !isset($feeds[$id])) return $feeds;

        $mode = sanitize_key(wp_unslash($_POST['a1fi_price_mode'] ?? 'percent'));
        if (!in_array($mode, ['percent', 'fixed', 'multiplier'], true)) $mode = 'percent';
        $amount = sanitize_text_field(wp_unslash($_POST['a1fi_price_amount'] ?? '0'));
        $feeds[$id]['price_calculation'] = [
            'enabled' => !empty($_POST['a1fi_price_enabled']),
            'mode' => $mode,
            'amount' => $amount,
        ];
        return $feeds;
    }

    public static function render_settings() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi') return;
        $id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $settings = $id && isset($feeds[$id]['price_calculation']) ? $feeds[$id]['price_calculation'] : [];
        $enabled = !empty($settings['enabled']);
        $mode = $settings['mode'] ?? 'percent';
        $amount = $settings['amount'] ?? '0';
        ?>
        <script>
        (function(){
            const form=document.getElementById('a1fi-form');
            const mappingHeading=form&&Array.from(form.querySelectorAll('h2')).find(function(el){return el.textContent.indexOf('<?php echo esc_js(__('Column mapping', 'allinonefeedimporter')); ?>')!==-1;});
            if(!form||!mappingHeading)return;
            const box=document.createElement('fieldset');
            box.style.cssText='border:1px solid #c3c4c7;padding:12px 16px;margin:12px 0;max-width:900px';
            box.innerHTML='<legend><strong><?php echo esc_js(__('Price calculation', 'allinonefeedimporter')); ?></strong></legend>'+
                '<label><input type="checkbox" name="a1fi_price_enabled" value="1" <?php checked($enabled); ?>> <?php echo esc_js(__('Calculate the regular price from the mapped feed price before importing', 'allinonefeedimporter')); ?></label>'+
                '<p><?php echo esc_js(__('Choose a percentage markup, fixed amount to add, or multiplier. The feed price remains the base price.', 'allinonefeedimporter')); ?></p>'+
                '<label><?php echo esc_js(__('Calculation', 'allinonefeedimporter')); ?> <select name="a1fi_price_mode">'+
                '<option value="percent" <?php selected($mode, 'percent'); ?>><?php echo esc_js(__('Add percentage (%)', 'allinonefeedimporter')); ?></option>'+
                '<option value="fixed" <?php selected($mode, 'fixed'); ?>><?php echo esc_js(__('Add fixed amount', 'allinonefeedimporter')); ?></option>'+
                '<option value="multiplier" <?php selected($mode, 'multiplier'); ?>><?php echo esc_js(__('Multiply price by', 'allinonefeedimporter')); ?></option>'+
                '</select></label> <label><?php echo esc_js(__('Value', 'allinonefeedimporter')); ?> <input type="number" name="a1fi_price_amount" step="any" value="<?php echo esc_attr($amount); ?>" style="width:120px"></label>'+
                '<p class="description"><?php echo esc_js(__('Example: 15 with percentage adds 15%; 10 with fixed adds 10 currency units; 1.15 multiplies the price by 1.15. Requires a numeric Regular price mapping.', 'allinonefeedimporter')); ?></p>';
            mappingHeading.insertAdjacentElement('afterend',box);
        })();
        </script>
        <?php
    }

    public static function prepare_next_batch() {
        if (get_transient('a1fi_queue_lock')) return;
        global $wpdb;
        $job = $wpdb->get_row("SELECT id, feed_id FROM " . A1FI_Queue::jobs_table() . " WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1");
        if (!$job) return;

        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $feed = $feeds[$job->feed_id] ?? [];
        $mapping = $feed['mapping'] ?? [];
        $sku_column = $mapping['sku'] ?? '';
        $price_column = $mapping['regular_price'] ?? '';
        $calculation = $feed['price_calculation'] ?? [];
        $calculate = !empty($calculation['enabled']);
        $mode = $calculation['mode'] ?? 'percent';
        $amount = $calculation['amount'] ?? '0';
        $amount_valid = is_numeric($amount) && is_finite((float) $amount);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, payload FROM " . A1FI_Queue::rows_table() . " WHERE job_id=%d AND status=%s ORDER BY `row_number` ASC LIMIT %d",
            (int) $job->id, 'queued', self::BATCH_SIZE
        ));
        foreach ((array) $rows as $record) {
            $data = json_decode($record->payload, true);
            if (!is_array($data)) continue;
            $changed = false;

            // CSV exports sometimes wrap cells as Excel formulas, for example ="123".
            foreach ([$sku_column, $price_column] as $column) {
                if ($column !== '' && isset($data[$column]) && is_string($data[$column])) {
                    $clean = self::unwrap_excel_literal($data[$column]);
                    if ($clean !== $data[$column]) {
                        $data[$column] = $clean;
                        $changed = true;
                    }
                }
            }

            if ($calculate && $price_column !== '' && array_key_exists($price_column, $data)) {
                $base = $data[$price_column];
                if (!$amount_valid || !is_numeric($base) || !in_array($mode, ['percent', 'fixed', 'multiplier'], true)) {
                    // Leave a nonnumeric value for the row validator to report rather than
                    // silently importing a different or fabricated price.
                    $data[$price_column] = '';
                } else {
                    $base = (float) $base;
                    $value = (float) $amount;
                    if ($mode === 'percent') $price = $base * (1 + ($value / 100));
                    elseif ($mode === 'fixed') $price = $base + $value;
                    else $price = $base * $value;

                    if (!is_finite($price) || $price < 0) {
                        $data[$price_column] = '';
                    } else {
                        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
                        $data[$price_column] = number_format(round($price, max(0, $decimals)), max(0, $decimals), '.', '');
                    }
                }
                $changed = true;
            }

            if ($changed) {
                $payload = wp_json_encode($data);
                if ($payload !== false) {
                    $wpdb->update(
                        A1FI_Queue::rows_table(),
                        ['payload' => $payload],
                        ['id' => (int) $record->id, 'status' => 'queued'],
                        ['%s'],
                        ['%d', '%s']
                    );
                }
            }
        }
    }

    private static function unwrap_excel_literal($value) {
        if (preg_match('/^\s*=\s*"((?:[^"]|"")*)"\s*$/', $value, $matches)) {
            return str_replace('""', '"', $matches[1]);
        }
        return $value;
    }
}

add_action('plugins_loaded', ['A1FI_Price_Calculator', 'boot'], 15);
