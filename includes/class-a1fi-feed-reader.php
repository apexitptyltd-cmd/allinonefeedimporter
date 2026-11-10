<?php
if (!defined('ABSPATH')) { exit; }

/** Read feed data into normalized headers and row arrays. */
class A1FI_Feed_Reader {
    const MAX_BYTES = 20971520;
    const MAX_ROWS = 10000;

    public static function read($file, $type, $delimiter = ',', $options = []) {
        if (!$file || !is_readable($file) || filesize($file) > self::MAX_BYTES) return new WP_Error('a1fi_file', __('Feed file is missing, unreadable, or larger than 20 MB.', 'allinonefeedimporter'));
        switch (strtolower($type)) {
            case 'csv': return self::csv($file, $delimiter);
            case 'xlsx': case 'xls': return self::excel($file);
            case 'json': return self::json($file);
            case 'xml':
                if (empty($options['item_path']) && isset($_POST['item_path'])) $options['item_path'] = sanitize_text_field(wp_unslash($_POST['item_path']));
                return self::xml($file, $options['item_path'] ?? '');
        }
        return new WP_Error('a1fi_type', __('Unsupported feed format.', 'allinonefeedimporter'));
    }

    private static function csv($file, $delimiter) {
        $delimiter = in_array($delimiter, [',',';',"\t",'|'], true) ? $delimiter : ',';
        $fh = fopen($file, 'rb'); if (!$fh) return new WP_Error('a1fi_csv', __('Could not read CSV.', 'allinonefeedimporter'));
        $headers = fgetcsv($fh, 0, $delimiter); if (!$headers) { fclose($fh); return new WP_Error('a1fi_headers', __('CSV has no header row.', 'allinonefeedimporter')); }
        $headers = array_map(static function($h) { return trim((string)$h, "\xEF\xBB\xBF \t\n\r\0\x0B"); }, $headers); $rows=[];
        while (($values=fgetcsv($fh, 0, $delimiter)) !== false && count($rows)<self::MAX_ROWS) { if ($values === [null]) continue; $row=[]; foreach($headers as $i=>$header) if($header!=='') $row[$header]=$values[$i]??''; $rows[]=$row; }
        fclose($fh); return ['headers'=>$headers,'rows'=>$rows];
    }

    private static function excel($file) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) return new WP_Error('a1fi_excel_dependency', __('Excel support needs PhpSpreadsheet. Run composer install in the plugin directory.', 'allinonefeedimporter'));
        try { $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file); $all=$book->getActiveSheet()->toArray(null,true,true,false); if(!$all)return new WP_Error('a1fi_excel_empty',__('Excel sheet is empty.','allinonefeedimporter')); $headers=array_map('strval',array_shift($all)); $rows=[]; foreach(array_slice($all,0,self::MAX_ROWS) as $values){$row=[];foreach($headers as $i=>$h)if($h!=='')$row[$h]=$values[$i]??'';$rows[]=$row;} return ['headers'=>$headers,'rows'=>$rows]; }
        catch (Throwable $e) { return new WP_Error('a1fi_excel_read', __('Could not parse Excel file.', 'allinonefeedimporter')); }
    }

    private static function json($file) {
        $data=json_decode(file_get_contents($file),true); if(json_last_error()!==JSON_ERROR_NONE)return new WP_Error('a1fi_json',__('Invalid JSON: ','allinonefeedimporter').json_last_error_msg());
        foreach(['products','items','data'] as $key) if(is_array($data)&&isset($data[$key])&&is_array($data[$key])){$data=$data[$key];break;}
        if(!is_array($data))return new WP_Error('a1fi_json_shape',__('JSON must contain product records.','allinonefeedimporter'));
        if(!self::is_list($data))$data=[$data]; $rows=[]; foreach(array_slice($data,0,self::MAX_ROWS) as $item)if(is_array($item))$rows[]=self::flatten_array($item);
        return self::make_result($rows);
    }

    private static function is_list($array) { if(function_exists('array_is_list'))return array_is_list($array); return array_keys($array)===range(0,count($array)-1); }
    private static function flatten_array($array,$prefix='') {
        $out=[]; foreach($array as $key=>$value){$path=$prefix===''?(string)$key:$prefix.'.'.$key;if(is_array($value)&&!self::is_list($value))$out+=self::flatten_array($value,$path);elseif(is_array($value))$out[$path]=implode(',',array_map(static function($v){return is_scalar($v)?(string)$v:'';},$value));else $out[$path]=is_scalar($value)?(string)$value:'';}return $out;
    }

    private static function xml($file,$item_path='') {
        if(!function_exists('simplexml_load_file'))return new WP_Error('a1fi_xml_dependency',__('PHP SimpleXML is required for XML feeds.','allinonefeedimporter'));
        $previous=libxml_use_internal_errors(true); $xml=simplexml_load_file($file,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA); $errors=libxml_get_errors(); libxml_clear_errors(); libxml_use_internal_errors($previous);
        if(!$xml)return new WP_Error('a1fi_xml',__('Invalid XML feed.','allinonefeedimporter'));
        if(trim($item_path)!=='') { $nodes=self::xpath_nodes($xml,trim($item_path)); if(is_wp_error($nodes))return $nodes; }
        else { $nodes=self::detect_records($xml); }
        if(!$nodes)return new WP_Error('a1fi_xml_records',__('No product records detected. Enter an XML item XPath such as //Product or //ProductDto in the feed settings, then preview again.','allinonefeedimporter'));
        $rows=[]; foreach(array_slice($nodes,0,self::MAX_ROWS) as $node)if($node instanceof SimpleXMLElement)$rows[]=self::xml_fields($node);
        return self::make_result($rows);
    }

    /** XPath selection also supports unprefixed names against default-namespaced documents via local-name(). */
    private static function xpath_nodes($xml,$path) {
        $nodes=$xml->xpath($path);
        if($nodes!==false && $nodes)return $nodes;
        // Friendly fallback for common unprefixed selectors such as //ProductDto on default namespace XML.
        if(preg_match('~^//([A-Za-z_][A-Za-z0-9_.-]*)$~',$path,$m)) {
            $nodes=$xml->xpath('//*[local-name()="'.$m[1].'"]');
            if($nodes!==false && $nodes)return $nodes;
        }
        return new WP_Error('a1fi_xml_xpath',sprintf(__('The XML item XPath “%s” matched no nodes. Try //Product, //ProductDto, or /root/container/item.', 'allinonefeedimporter'),$path));
    }

    /** Select repeated record elements first; otherwise descend through wrapper nodes to the deepest product-like object. */
    private static function detect_records($root) {
        $children=self::xml_children($root);
        if(!$children)return (self::xml_scalar_child_count($root)>=2)?[$root]:[];
        $groups=[]; foreach($children as $child){$name=$child->getName(); if(self::xml_child_count($child)>0)$groups[$name][]=$child;}
        foreach($groups as $group) if(count($group)>1)return $group;
        $complex=array_values(array_filter($children,static function($child){return self::xml_child_count($child)>0;}));
        if(count($complex)===1)return self::detect_records($complex[0]);
        if(count($complex)>1) {
            // Container may have wrapper metadata beside a single product collection. Prefer the branch with most leaf fields.
            usort($complex,static function($a,$b){return self::xml_scalar_child_count($b)<=>self::xml_scalar_child_count($a);});
            return self::detect_records($complex[0]);
        }
        return self::xml_scalar_child_count($root)>=2?[$root]:[];
    }

    private static function xml_children($node) {
        $result=[]; $namespaces=$node->getNamespaces(true); $uris=array_values(array_unique(array_merge([''],array_values($namespaces))));
        foreach($uris as $uri) foreach($node->children($uri) as $child) $result[]=$child;
        return $result;
    }
    private static function xml_child_count($node) { return count(self::xml_children($node)); }
    private static function xml_scalar_child_count($node) { $count=0; foreach(self::xml_children($node) as $child)if(self::xml_child_count($child)===0)$count++; return $count; }

    /** Flatten element text to dotted paths; attributes are exposed as field.@attribute. */
    private static function xml_fields($node,$prefix='') {
        $out=[];
        foreach($node->attributes() as $name=>$value)$out[$prefix.'@'.$name]=(string)$value;
        foreach(self::xml_children($node) as $child) {
            $name=$child->getName(); $path=$prefix===''?$name:$prefix.'.'.$name; $nested=self::xml_children($child);
            if($nested) { $fields=self::xml_fields($child,$path); foreach($fields as $key=>$value){if(array_key_exists($key,$out))$out[$key].=', '.$value;else $out[$key]=$value;} }
            else { $value=trim((string)$child); if(array_key_exists($path,$out))$out[$path].=', '.$value;else $out[$path]=$value; }
        }
        if(!$out && trim((string)$node)!=='')$out[$prefix!==''?$prefix.'.value':'value']=trim((string)$node);
        return $out;
    }

    private static function make_result($rows) { $headers=[]; foreach($rows as $row)foreach(array_keys($row) as $key)if(!in_array($key,$headers,true))$headers[]=$key; return ['headers'=>$headers,'rows'=>$rows]; }
    public static function fields() { return [
        'name'=>__('Product name (required)','allinonefeedimporter'),'sku'=>__('SKU','allinonefeedimporter'),'description'=>__('Description','allinonefeedimporter'),'short_description'=>__('Short description','allinonefeedimporter'),
        'regular_price'=>__('Regular price','allinonefeedimporter'),'sale_price'=>__('Sale price','allinonefeedimporter'),'stock_quantity'=>__('Stock quantity','allinonefeedimporter'),'categories'=>__('Categories (comma-separated)','allinonefeedimporter'),
        'images'=>__('Image URLs (comma-separated)','allinonefeedimporter'),'product_type'=>__('Product type (simple)','allinonefeedimporter'),'status'=>__('Status (publish, draft, pending)','allinonefeedimporter')]; }
    public static function validate($mapping,$headers) { $errors=[];if(empty($mapping['name']))$errors[]=__('Map Product name; this field is required.','allinonefeedimporter');foreach($mapping as $field=>$column){if($column==='')continue;if(!array_key_exists($field,self::fields()))$errors[]=sprintf(__('Unknown product field: %s','allinonefeedimporter'),$field);if(!in_array($column,$headers,true))$errors[]=sprintf(__('Feed column “%s” does not exist.','allinonefeedimporter'),$column);}return $errors; }
}
