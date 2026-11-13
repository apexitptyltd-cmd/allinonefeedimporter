<?php
if (!defined('ABSPATH')) { exit; }

class A1FI_Plugin {
    const OPTION = 'a1fi_feeds';
    public static function boot() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('init', [__CLASS__, 'ensure_cron']);
        add_action('a1fi_check_schedules', [__CLASS__, 'run_scheduled']);
        if (!class_exists('WooCommerce')) { add_action('admin_notices', [__CLASS__, 'woocommerce_notice']); return; }
        if (is_admin()) { add_action('admin_menu', [__CLASS__, 'menu']); add_action('admin_post_a1fi_save', [__CLASS__, 'save']); add_action('admin_post_a1fi_run', [__CLASS__, 'manual_run']); add_action('admin_post_a1fi_delete', [__CLASS__, 'delete']); add_action('wp_ajax_a1fi_preview', [__CLASS__, 'preview_ajax']); }
    }
    public static function activate() { self::schedule_cron(); }
    public static function deactivate() { wp_clear_scheduled_hook('a1fi_check_schedules'); }
    public static function cron_schedules($s) { $s['a1fi_minute'] = ['interval' => 60, 'display' => __('Every minute (feed importer)', 'allinonefeedimporter')]; return $s; }
    public static function ensure_cron() { if (!wp_next_scheduled('a1fi_check_schedules')) self::schedule_cron(); }
    private static function schedule_cron() { if (!wp_next_scheduled('a1fi_check_schedules')) wp_schedule_event(time() + 60, 'a1fi_minute', 'a1fi_check_schedules'); }
    public static function woocommerce_notice() { echo '<div class="notice notice-error"><p>' . esc_html__('All In One Feed Importer requires WooCommerce.', 'allinonefeedimporter') . '</p></div>'; }
    private static function can_manage() { return current_user_can('manage_woocommerce'); }
    public static function menu() { add_submenu_page('woocommerce', __('Feed Importer', 'allinonefeedimporter'), __('Feed Importer', 'allinonefeedimporter'), 'manage_woocommerce', 'a1fi', [__CLASS__, 'page']); }

    public static function page() {
        if (!self::can_manage()) return;
        $feeds = get_option(self::OPTION, []); $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0; $edit = $edit_id && isset($feeds[$edit_id]) ? $feeds[$edit_id] : [];
        $notice = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap"><h1><?php esc_html_e('All In One Feed Importer', 'allinonefeedimporter'); ?></h1>
        <?php if ($notice) echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; ?>
        <p><?php esc_html_e('Create supplier feeds, preview their columns, validate mappings, import immediately, or schedule imports in your WordPress timezone.', 'allinonefeedimporter'); ?></p>
        <h2><?php echo $edit ? esc_html__('Edit feed', 'allinonefeedimporter') : esc_html__('Add feed', 'allinonefeedimporter'); ?></h2>
        <form id="a1fi-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('a1fi_save'); ?><input type="hidden" name="action" value="a1fi_save"><input type="hidden" name="feed_id" value="<?php echo esc_attr($edit_id); ?>">
            <table class="form-table"><tbody>
            <tr><th><label for="a1fi-name"><?php esc_html_e('Feed name', 'allinonefeedimporter'); ?></label></th><td><input id="a1fi-name" name="name" class="regular-text" required value="<?php echo esc_attr($edit['name'] ?? ''); ?>"></td></tr>
            <tr><th><?php esc_html_e('Source', 'allinonefeedimporter'); ?></th><td><label><input type="radio" name="source_type" value="url" <?php checked(($edit['source_type'] ?? 'url'), 'url'); ?>> <?php esc_html_e('Remote URL', 'allinonefeedimporter'); ?></label> &nbsp; <label><input type="radio" name="source_type" value="upload" <?php checked(($edit['source_type'] ?? ''), 'upload'); ?>> <?php esc_html_e('Upload file', 'allinonefeedimporter'); ?></label><p><input type="url" name="source_url" class="large-text" placeholder="https://supplier.example/feed.csv" value="<?php echo esc_attr($edit['source_url'] ?? ''); ?>"></p><input type="file" name="feed_file" accept=".csv,.xls,.xlsx,.xml,.json"><p class="description"><?php esc_html_e('Use a direct feed URL accessible without interactive login, or upload a file. Maximum file size: 20 MB.', 'allinonefeedimporter'); ?></p></td></tr>
            <tr><th><label for="a1fi-type"><?php esc_html_e('Format', 'allinonefeedimporter'); ?></label></th><td><select id="a1fi-type" name="file_type"><?php foreach (['csv'=>'CSV','xlsx'=>'Excel (.xlsx)','xls'=>'Excel (.xls)','xml'=>'XML','json'=>'JSON'] as $v=>$label) echo '<option value="'.esc_attr($v).'" '.selected($edit['file_type'] ?? 'csv',$v,false).'>'.esc_html($label).'</option>'; ?></select> &nbsp; <?php esc_html_e('CSV delimiter', 'allinonefeedimporter'); ?> <select name="delimiter"><?php foreach ([','=>'Comma (,)',';'=>'Semicolon (;)','|'=>'Pipe (|)',"\t"=>'Tab'] as $v=>$label) echo '<option value="'.esc_attr($v).'" '.selected($edit['delimiter'] ?? ',', $v, false).'>'.esc_html($label).'</option>'; ?></select></td></tr>
            <tr><th><?php esc_html_e('Schedule', 'allinonefeedimporter'); ?></th><td><label><input type="checkbox" name="schedule_enabled" value="1" <?php checked(!empty($edit['schedule_enabled'])); ?>> <?php esc_html_e('Enable schedule', 'allinonefeedimporter'); ?></label><p><input type="time" name="schedule_time" value="<?php echo esc_attr($edit['schedule_time'] ?? '02:00'); ?>"></p><?php foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day): ?><label style="margin-right:12px"><input type="checkbox" name="schedule_days[]" value="<?php echo esc_attr($day); ?>" <?php checked(in_array($day, $edit['schedule_days'] ?? [], true)); ?>> <?php echo esc_html(ucfirst($day)); ?></label><?php endforeach; ?><p class="description"><?php esc_html_e('Runs at the selected local time. WordPress cron is traffic-triggered; use a server cron calling wp-cron.php for reliable timing.', 'allinonefeedimporter'); ?></p></td></tr>
            <tr><th><?php esc_html_e('Feed status', 'allinonefeedimporter'); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!isset($edit['enabled']) || !empty($edit['enabled'])); ?>> <?php esc_html_e('Enabled', 'allinonefeedimporter'); ?></label></td></tr>
            </tbody></table>
            <h2><?php esc_html_e('Column mapping', 'allinonefeedimporter'); ?></h2><p><?php esc_html_e('Click “Preview columns” to load source headers. Product name is required. SKU is recommended for matching existing products.', 'allinonefeedimporter'); ?></p>
            <p><button type="button" class="button" id="a1fi-preview"><?php esc_html_e('Preview columns', 'allinonefeedimporter'); ?></button> <span id="a1fi-preview-status" aria-live="polite"></span></p>
            <div id="a1fi-validation" role="status" aria-live="polite"></div>
            <table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e('WooCommerce field', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Feed column', 'allinonefeedimporter'); ?></th></tr></thead><tbody>
            <?php foreach (A1FI_Feed_Reader::fields() as $field=>$label): ?><tr><td><?php echo esc_html($label); ?></td><td><select class="a1fi-map" name="mapping[<?php echo esc_attr($field); ?>]" data-field="<?php echo esc_attr($field); ?>" data-current="<?php echo esc_attr($edit['mapping'][$field] ?? ''); ?>"><option value=""><?php esc_html_e('— Not mapped —', 'allinonefeedimporter'); ?></option><?php if (!empty($edit['mapping'][$field])): ?><option selected value="<?php echo esc_attr($edit['mapping'][$field]); ?>"><?php echo esc_html($edit['mapping'][$field]); ?></option><?php endif; ?></select></td></tr><?php endforeach; ?>
            </tbody></table><p><button type="button" class="button" id="a1fi-validate"><?php esc_html_e('Validate mappings', 'allinonefeedimporter'); ?></button> <button type="submit" class="button button-primary"><?php esc_html_e('Save feed', 'allinonefeedimporter'); ?></button></p>
        </form>
        <hr><h2><?php esc_html_e('Configured feeds', 'allinonefeedimporter'); ?></h2>
        <?php if (!$feeds): ?><p><?php esc_html_e('No feeds configured.', 'allinonefeedimporter'); ?></p><?php else: ?><table class="widefat striped"><thead><tr><th><?php esc_html_e('Feed', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Format / schedule', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Status / last run', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Actions', 'allinonefeedimporter'); ?></th></tr></thead><tbody>
        <?php foreach ($feeds as $id=>$feed): ?><tr><td><?php echo esc_html($feed['name']); ?><br><small><?php echo esc_html($feed['source_url'] ?: basename($feed['file_path'] ?? '')); ?></small></td><td><?php echo esc_html(strtoupper($feed['file_type'])); ?><br><?php echo !empty($feed['schedule_enabled']) ? esc_html(implode(', ', $feed['schedule_days']) . ' ' . $feed['schedule_time']) : esc_html__('Manual only', 'allinonefeedimporter'); ?></td><td><?php echo !empty($feed['enabled']) ? esc_html__('Enabled', 'allinonefeedimporter') : esc_html__('Disabled', 'allinonefeedimporter'); ?><br><?php echo esc_html($feed['last_run'] ?? __('Never run', 'allinonefeedimporter')); ?><?php if (!empty($feed['last_result'])): ?><br><?php echo esc_html($feed['last_result']); ?><?php endif; ?></td><td><a class="button" href="<?php echo esc_url(add_query_arg(['page'=>'a1fi','edit'=>$id], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'allinonefeedimporter'); ?></a> <form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('a1fi_run_'.$id); ?><input type="hidden" name="action" value="a1fi_run"><input type="hidden" name="feed_id" value="<?php echo esc_attr($id); ?>"><button class="button"><?php esc_html_e('Run now', 'allinonefeedimporter'); ?></button></form> <form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this feed?', 'allinonefeedimporter')); ?>')"><?php wp_nonce_field('a1fi_delete_'.$id); ?><input type="hidden" name="action" value="a1fi_delete"><input type="hidden" name="feed_id" value="<?php echo esc_attr($id); ?>"><button class="button"><?php esc_html_e('Delete', 'allinonefeedimporter'); ?></button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        </div>
        <script>
        (function(){const form=document.getElementById('a1fi-form'),btn=document.getElementById('a1fi-preview'),status=document.getElementById('a1fi-preview-status'),box=document.getElementById('a1fi-validation');let headers=[];
        function showErrors(errors){box.replaceChildren();const p=document.createElement('p');p.className=errors.length?'notice notice-error':'notice notice-success';p.textContent=errors.length?errors.join(' '):'<?php echo esc_js(__('Mappings are valid.', 'allinonefeedimporter')); ?>';box.appendChild(p)}
        function setHeaders(cols){headers=cols;document.querySelectorAll('.a1fi-map').forEach(sel=>{const keep=sel.value||sel.dataset.current||'';sel.replaceChildren(new Option('— Not mapped —',''));cols.forEach(c=>sel.add(new Option(c,c)));if(cols.includes(keep))sel.value=keep;});}
        async function preview(){status.textContent='<?php echo esc_js(__('Loading preview…', 'allinonefeedimporter')); ?>';const fd=new FormData(form);fd.append('action','a1fi_preview');fd.append('_ajax_nonce','<?php echo esc_js(wp_create_nonce('a1fi_preview')); ?>');try{const r=await fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(!d.success)throw new Error(d.data||'Preview failed');setHeaders(d.data.headers);status.textContent=`${d.data.headers.length} columns; ${d.data.count} rows found.`;const t=document.getElementById('a1fi-sample');if(t)t.remove();const table=document.createElement('table');table.id='a1fi-sample';table.className='widefat striped';table.style.maxWidth='900px';const tr=document.createElement('tr');d.data.headers.slice(0,8).forEach(h=>{const th=document.createElement('th');th.textContent=h;tr.appendChild(th)});const thead=document.createElement('thead');thead.appendChild(tr);table.appendChild(thead);const body=document.createElement('tbody');(d.data.sample||[]).slice(0,3).forEach(row=>{const rtr=document.createElement('tr');d.data.headers.slice(0,8).forEach(h=>{const td=document.createElement('td');td.textContent=row[h]||'';rtr.appendChild(td)});body.appendChild(rtr)});table.appendChild(body);form.insertBefore(table,document.getElementById('a1fi-validation'));}catch(e){status.textContent=e.message}}
        btn.addEventListener('click',preview);document.getElementById('a1fi-validate').addEventListener('click',()=>{if(!headers.length){showErrors(['<?php echo esc_js(__('Preview the feed first.', 'allinonefeedimporter')); ?>']);return}const mapping={};document.querySelectorAll('.a1fi-map').forEach(s=>mapping[s.dataset.field]=s.value);const errors=[];if(!mapping.name)errors.push('<?php echo esc_js(__('Map Product name; it is required.', 'allinonefeedimporter')); ?>');Object.entries(mapping).forEach(([f,c])=>{if(c&&!headers.includes(c))errors.push(`<?php echo esc_js(__('Mapped column is missing:', 'allinonefeedimporter')); ?> ${c}`)});showErrors(errors)});
        })();
        </script><?php
    }

    private static function source_file($feed, $upload = null) {
        if ($upload && !empty($upload['tmp_name'])) return $upload['tmp_name'];
        if (($feed['source_type'] ?? '') === 'upload') return $feed['file_path'] ?? '';
        if (empty($feed['source_url']) || !wp_http_validate_url($feed['source_url'])) return new WP_Error('a1fi_url', __('Enter a valid feed URL.', 'allinonefeedimporter'));
        $response = wp_safe_remote_get($feed['source_url'], ['timeout'=>30, 'redirection'=>3, 'limit_response_size'=>A1FI_Feed_Reader::MAX_BYTES]);
        if (is_wp_error($response)) return $response;
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) return new WP_Error('a1fi_http', __('Feed URL returned an unsuccessful HTTP status.', 'allinonefeedimporter'));
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > A1FI_Feed_Reader::MAX_BYTES) return new WP_Error('a1fi_size', __('Feed exceeds 20 MB.', 'allinonefeedimporter'));
        $tmp = wp_tempnam('a1fi-feed'); if (!$tmp || file_put_contents($tmp, $body) === false) return new WP_Error('a1fi_temp', __('Could not save downloaded feed.', 'allinonefeedimporter'));
        return $tmp;
    }

    public static function preview_ajax() {
        if (!self::can_manage()) wp_send_json_error(__('Not authorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_preview');
        $type = sanitize_key($_POST['file_type'] ?? 'csv'); $source = sanitize_key($_POST['source_type'] ?? 'url'); $feeds = get_option(self::OPTION, []); $id = absint($_POST['feed_id'] ?? 0); $feed = $feeds[$id] ?? [];
        if (!empty($_FILES['feed_file']['tmp_name'])) $file = self::source_file($feed, $_FILES['feed_file']);
        elseif ($source === 'upload' && !empty($feed['file_path']) && empty($_FILES['feed_file']['tmp_name'])) $file = $feed['file_path'];
        else { $feed['source_type'] = 'url'; $feed['source_url'] = esc_url_raw(wp_unslash($_POST['source_url'] ?? '')); $file = self::source_file($feed); }
        if (is_wp_error($file)) wp_send_json_error($file->get_error_message());
        $result = A1FI_Feed_Reader::read($file, $type, sanitize_text_field(wp_unslash($_POST['delimiter'] ?? ',')));
        if (is_file($file) && strpos(basename($file), 'a1fi-feed') === 0) @unlink($file);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
        wp_send_json_success(['headers'=>$result['headers'], 'count'=>count($result['rows']), 'sample'=>array_slice($result['rows'],0,3)]);
    }

    public static function save() {
        if (!self::can_manage()) wp_die(esc_html__('Unauthorized.', 'allinonefeedimporter'));
        check_admin_referer('a1fi_save'); $feeds = get_option(self::OPTION, []); $id = absint($_POST['feed_id'] ?? 0); if (!$id) $id = time(); $old = $feeds[$id] ?? [];
        $feed = ['id'=>$id, 'name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 'source_type'=>sanitize_key($_POST['source_type'] ?? 'url'), 'source_url'=>esc_url_raw(wp_unslash($_POST['source_url'] ?? '')), 'file_type'=>sanitize_key($_POST['file_type'] ?? 'csv'), 'delimiter'=>sanitize_text_field(wp_unslash($_POST['delimiter'] ?? ',')), 'enabled'=>!empty($_POST['enabled']), 'schedule_enabled'=>!empty($_POST['schedule_enabled']), 'schedule_days'=>array_map('sanitize_key', (array) ($_POST['schedule_days'] ?? [])), 'schedule_time'=>sanitize_text_field(wp_unslash($_POST['schedule_time'] ?? '02:00')), 'mapping'=>[]];
        if (!in_array($feed['file_type'], ['csv','xls','xlsx','xml','json'], true)) wp_die(esc_html__('Unsupported file type.', 'allinonefeedimporter'));
        if (!empty($_POST['mapping']) && is_array($_POST['mapping'])) foreach ($_POST['mapping'] as $field=>$col) $feed['mapping'][sanitize_key($field)] = sanitize_text_field(wp_unslash($col));
        if (!empty($_FILES['feed_file']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; $upload = wp_handle_upload($_FILES['feed_file'], ['test_form'=>false, 'mimes'=>['csv'=>'text/csv','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','xml'=>'text/xml','json'=>'application/json']]);
            if (!empty($upload['error'])) wp_die(esc_html($upload['error']));
            if (!empty($upload['file'])) $feed['file_path'] = $upload['file'];
        } elseif (!empty($old['file_path'])) $feed['file_path'] = $old['file_path'];
        foreach (['last_run','last_result','last_slot'] as $key) if (isset($old[$key])) $feed[$key] = $old[$key];
        if (!$feed['name'] || ($feed['source_type'] === 'url' && !$feed['source_url']) || ($feed['source_type'] === 'upload' && empty($feed['file_path']))) wp_die(esc_html__('Enter a feed name and provide a URL or uploaded file.', 'allinonefeedimporter'));
        $feeds[$id] = $feed; update_option(self::OPTION, $feeds); self::redirect(__('Feed saved.', 'allinonefeedimporter'));
    }
    public static function manual_run() { $id=absint($_POST['feed_id'] ?? 0); check_admin_referer('a1fi_run_'.$id); if (!self::can_manage()) wp_die(esc_html__('Unauthorized.', 'allinonefeedimporter')); self::run_feed($id, true); self::redirect(__('Import completed. See last run status below.', 'allinonefeedimporter')); }
    public static function delete() { $id=absint($_POST['feed_id'] ?? 0); check_admin_referer('a1fi_delete_'.$id); if (!self::can_manage()) wp_die(esc_html__('Unauthorized.', 'allinonefeedimporter')); $feeds=get_option(self::OPTION,[]); unset($feeds[$id]); update_option(self::OPTION,$feeds); self::redirect(__('Feed deleted.', 'allinonefeedimporter')); }
    private static function redirect($message) { wp_safe_redirect(add_query_arg(['page'=>'a1fi','message'=>rawurlencode($message)], admin_url('admin.php'))); exit; }
    public static function run_scheduled() {
        if (!class_exists('WooCommerce')) return; $feeds=get_option(self::OPTION,[]); $now=current_datetime(); $day=strtolower($now->format('l')); $time=$now->format('H:i'); $slot=$now->format('Y-m-d H:i');
        foreach ($feeds as $id=>$feed) if (!empty($feed['enabled']) && !empty($feed['schedule_enabled']) && $feed['schedule_time']===$time && in_array($day,$feed['schedule_days'] ?? [],true) && ($feed['last_slot'] ?? '')!==$slot) self::run_feed((int)$id, false, $slot);
    }
    private static function run_feed($id, $manual=false, $slot='') {
        $feeds=get_option(self::OPTION,[]); if (empty($feeds[$id])) return; $feed=$feeds[$id]; $file=self::source_file($feed);
        if (is_wp_error($file)) { self::save_result($id, $file->get_error_message(), $slot); return; }
        $result=A1FI_Feed_Reader::read($file,$feed['file_type'],$feed['delimiter'] ?? ','); if (is_file($file) && strpos(basename($file),'a1fi-feed')===0) @unlink($file);
        if (is_wp_error($result)) { self::save_result($id,$result->get_error_message(),$slot); return; }
        $errors=A1FI_Feed_Reader::validate($feed['mapping'] ?? [],$result['headers']); if ($errors) { self::save_result($id,implode(' ',$errors),$slot); return; }
        $ok=0; $failed=0; foreach ($result['rows'] as $row) { $r=self::import_product($row,$feed['mapping']); if (is_wp_error($r)) $failed++; else $ok++; }
        self::save_result($id,sprintf(__('%1$d imported/updated; %2$d skipped or failed.', 'allinonefeedimporter'),$ok,$failed),$slot);
    }
    private static function save_result($id,$message,$slot='') { $f=get_option(self::OPTION,[]); if (!isset($f[$id])) return; $f[$id]['last_run']=current_time('mysql'); $f[$id]['last_result']=sanitize_text_field($message); if ($slot) $f[$id]['last_slot']=$slot; update_option(self::OPTION,$f); }

    private static function import_product($row,$mapping) {
        $v=[]; foreach ($mapping as $field=>$column) if ($column !== '' && isset($row[$column])) $v[$field]=$row[$column];
        $name=trim(wp_strip_all_tags((string)($v['name'] ?? ''))); if ($name==='') return new WP_Error('a1fi_name', __('Missing product name.', 'allinonefeedimporter'));
        $product_id=0; $sku=trim((string)($v['sku'] ?? '')); if ($sku!=='') { $product_id=wc_get_product_id_by_sku($sku); }
        $product=$product_id ? wc_get_product($product_id) : new WC_Product_Simple(); if (!$product) $product=new WC_Product_Simple();
        try {
            $product->set_name($name); if ($sku!=='') $product->set_sku($sku);
            foreach (['description'=>'set_description','short_description'=>'set_short_description'] as $key=>$method) if (isset($v[$key])) $product->$method(wp_kses_post((string)$v[$key]));
            foreach (['regular_price'=>'set_regular_price','sale_price'=>'set_sale_price'] as $key=>$method) if (isset($v[$key]) && is_numeric($v[$key])) $product->$method((string)$v[$key]);
            $status=strtolower((string)($v['status'] ?? 'publish')); $product->set_status(in_array($status,['publish','draft','pending','private'],true)?$status:'publish');
            $id=$product->save(); if (!$id) return new WP_Error('a1fi_save', __('WooCommerce could not save product.', 'allinonefeedimporter'));
            if (!empty($v['categories'])) { $terms=[]; foreach (explode(',',(string)$v['categories']) as $name) { $name=trim($name); if (!$name) continue; $term=term_exists($name,'product_cat'); if (!$term) $term=wp_insert_term($name,'product_cat'); if (!is_wp_error($term)) $terms[]=(int)(is_array($term)?$term['term_id']:$term); } if ($terms) wp_set_object_terms($id,$terms,'product_cat'); }
            if (isset($v['stock_quantity']) && is_numeric($v['stock_quantity'])) { $product=wc_get_product($id); $product->set_manage_stock(true); $product->set_stock_quantity((int)$v['stock_quantity']); $product->save(); }
            if (!empty($v['images'])) self::set_images($id,(string)$v['images']);
            return $id;
        } catch (Throwable $e) { return new WP_Error('a1fi_product', __('Product could not be imported; check the feed values and WooCommerce logs.', 'allinonefeedimporter')); }
    }
    public static function set_images($id,$urls) {
        require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php'; $ids=[];
        foreach (array_slice(array_map('trim',explode(',',$urls)),0,10) as $url) { if (!wp_http_validate_url($url)) continue; $att=media_sideload_image(esc_url_raw($url),$id,null,'id'); if (!is_wp_error($att)) $ids[]=(int)$att; }
        if ($ids) { set_post_thumbnail($id,$ids[0]); if (count($ids)>1) update_post_meta($id,'_product_image_gallery',implode(',',array_slice($ids,1))); }
    }
}
