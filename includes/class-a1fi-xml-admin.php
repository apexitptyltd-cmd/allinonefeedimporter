<?php
if (!defined('ABSPATH')) { exit; }

/** Adds an XML record XPath control to the existing feed form and preserves it with feed settings. */
class A1FI_XML_Admin {
    public static function boot() {
        add_action('admin_footer', [__CLASS__, 'insert_xpath_control']);
        add_filter('pre_update_option_a1fi_feeds', [__CLASS__, 'save_paths'], 10, 2);
    }
    public static function save_paths($value, $old_value) {
        if (!is_admin() || empty($_POST['action']) || $_POST['action'] !== 'a1fi_save') return $value;
        $id=absint($_POST['feed_id']??0);
        if(!$id && is_array($value)) { $keys=array_keys($value); $id=(int)end($keys); }
        if($id && isset($value[$id])) $value[$id]['item_path']=sanitize_text_field(wp_unslash($_POST['item_path']??''));
        return $value;
    }
    public static function insert_xpath_control() {
        if(!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page'])!=='a1fi')return;
        ?>
        <script>
        (function(){const form=document.getElementById('a1fi-form');if(!form)return;const format=document.getElementById('a1fi-type');if(!format)return;const row=format.closest('tr');if(!row)return;const tr=document.createElement('tr');tr.innerHTML='<th><label for="a1fi-item-path"><?php echo esc_js(__('XML item XPath','allinonefeedimporter')); ?></label></th><td><input id="a1fi-item-path" name="item_path" class="regular-text" placeholder="//Product or /root/products/Product"><p class="description"><?php echo esc_js(__('Optional. Select the repeated product/item nodes. Leave blank for automatic detection. For Atom/OData XML, try //ProductDto or //entry.', 'allinonefeedimporter')); ?></p></td>';row.parentNode.insertBefore(tr,row.nextSibling);const type=document.getElementById('a1fi-type');function toggle(){tr.style.display=type.value==='xml'?'':'none'}type.addEventListener('change',toggle);toggle();})();
        </script>
        <?php
    }
}
A1FI_XML_Admin::boot();
