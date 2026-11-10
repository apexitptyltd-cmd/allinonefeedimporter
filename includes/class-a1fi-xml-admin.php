<?php
if (!defined('ABSPATH')) { exit; }

/** Add a real XML XPath field to the existing settings form and persist it per feed. */
class A1FI_XML_Admin {
    public static function boot() {
        add_action('admin_footer', [__CLASS__, 'insert_xpath_control']);
        add_filter('pre_update_option_a1fi_feeds', [__CLASS__, 'save_paths'], 10, 2);
    }
    public static function save_paths($value, $old_value) {
        if (!is_admin() || ($_POST['action'] ?? '') !== 'a1fi_save' || !is_array($value)) return $value;
        $id=absint($_POST['feed_id']??0);
        if(!$id) { $added=array_diff_key($value,(array)$old_value); if($added){$keys=array_keys($added);$id=(int)end($keys);} }
        if($id && isset($value[$id])) $value[$id]['item_path']=sanitize_text_field(wp_unslash($_POST['item_path']??''));
        return $value;
    }
    public static function insert_xpath_control() {
        if(!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page'])!=='a1fi')return;
        $id=isset($_GET['edit'])?absint($_GET['edit']):0; $feeds=get_option(A1FI_Plugin::OPTION,[]); $value=$id&&!empty($feeds[$id]['item_path'])?$feeds[$id]['item_path']:'';
        ?>
        <script>
        (function(){const form=document.getElementById('a1fi-form'),type=document.getElementById('a1fi-type');if(!form||!type)return;const row=type.closest('tr');if(!row)return;const tr=document.createElement('tr');const th=document.createElement('th'),label=document.createElement('label');label.htmlFor='a1fi-item-path';label.textContent='<?php echo esc_js(__('XML item XPath','allinonefeedimporter')); ?>';th.appendChild(label);const td=document.createElement('td'),input=document.createElement('input');input.type='text';input.id='a1fi-item-path';input.name='item_path';input.className='regular-text';input.placeholder='//ProductDto';input.value=<?php echo wp_json_encode($value); ?>;td.appendChild(input);const p=document.createElement('p');p.className='description';p.textContent='<?php echo esc_js(__('Optional. Select repeated product nodes. Try //ProductDto, //Product, //product, or //*[local-name()="ProductDto"] for XML with namespaces. Leave blank for automatic detection.','allinonefeedimporter')); ?>';td.appendChild(p);tr.appendChild(th);tr.appendChild(td);row.parentNode.insertBefore(tr,row.nextSibling);function toggle(){tr.style.display=type.value==='xml'?'':'none'}type.addEventListener('change',toggle);toggle();})();
        </script>
        <?php
    }
}
A1FI_XML_Admin::boot();
