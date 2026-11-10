<?php
if (!defined('ABSPATH')) { exit; }

/** Read feed data into a normalized header/rows structure. */
class A1FI_Feed_Reader {
    const MAX_BYTES = 20971520;

    public static function read($file, $type, $delimiter = ',') {
        if (!$file || !is_readable($file) || filesize($file) > self::MAX_BYTES) {
            return new WP_Error('a1fi_file', __('Feed file is missing, unreadable, or larger than 20 MB.', 'allinonefeedimporter'));
        }
        $type = strtolower($type);
        if ($type === 'csv') return self::csv($file, $delimiter);
        if (in_array($type, ['xlsx', 'xls'], true)) return self::excel($file);
        if ($type === 'json') return self::json($file);
        if ($type === 'xml') return self::xml($file);
        return new WP_Error('a1fi_type', __('Unsupported feed format.', 'allinonefeedimporter'));
    }

    private static function csv($file, $delimiter) {
        $delimiter = in_array($delimiter, [',', ';', "\t", '|'], true) ? $delimiter : ',';
        $fh = fopen($file, 'rb');
        if (!$fh) return new WP_Error('a1fi_csv', __('Could not read CSV.', 'allinonefeedimporter'));
        $headers = fgetcsv($fh, 0, $delimiter);
        if (!$headers) { fclose($fh); return new WP_Error('a1fi_headers', __('CSV has no header row.', 'allinonefeedimporter')); }
        $headers = array_map(static function ($h) { return trim((string) $h, "\xEF\xBB\xBF \t\n\r\0\x0B"); }, $headers);
        $rows = [];
        while (($values = fgetcsv($fh, 0, $delimiter)) !== false && count($rows) < 10000) {
            if ($values === [null]) continue;
            $row = [];
            foreach ($headers as $i => $header) if ($header !== '') $row[$header] = isset($values[$i]) ? $values[$i] : '';
            $rows[] = $row;
        }
        fclose($fh);
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function excel($file) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) return new WP_Error('a1fi_excel_dependency', __('Excel support needs PhpSpreadsheet. Run composer install in the plugin directory.', 'allinonefeedimporter'));
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $all = $book->getActiveSheet()->toArray(null, true, true, false);
            if (!$all) return new WP_Error('a1fi_excel_empty', __('Excel sheet is empty.', 'allinonefeedimporter'));
            $headers = array_map('strval', array_shift($all));
            $rows = [];
            foreach (array_slice($all, 0, 10000) as $values) {
                $row = [];
                foreach ($headers as $i => $header) if ($header !== '') $row[$header] = $values[$i] ?? '';
                $rows[] = $row;
            }
            return ['headers' => $headers, 'rows' => $rows];
        } catch (Throwable $e) { return new WP_Error('a1fi_excel_read', __('Could not parse Excel file.', 'allinonefeedimporter')); }
    }

    private static function json($file) {
        $data = json_decode(file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE) return new WP_Error('a1fi_json', __('Invalid JSON: ', 'allinonefeedimporter') . json_last_error_msg());
        if (isset($data['products']) && is_array($data['products'])) $data = $data['products'];
        elseif (isset($data['items']) && is_array($data['items'])) $data = $data['items'];
        elseif (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
        if (!is_array($data)) return new WP_Error('a1fi_json_shape', __('JSON must be an array of product objects or contain products, items, or data.', 'allinonefeedimporter'));
        if (isset($data[0]) && is_array($data[0])) $records = $data;
        else $records = [$data];
        $rows = [];
        foreach (array_slice($records, 0, 10000) as $record) if (is_array($record)) $rows[] = self::flatten($record);
        return self::make_result($rows);
    }

    private static function flatten($array, $prefix = '') {
        $out = [];
        foreach ($array as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && array_values($value) !== $value) $out += self::flatten($value, $name);
            else $out[$name] = is_array($value) ? implode(',', array_map('strval', $value)) : (is_scalar($value) ? (string) $value : '');
        }
        return $out;
    }

    private static function xml($file) {
        if (!function_exists('simplexml_load_file')) return new WP_Error('a1fi_xml_dependency', __('PHP SimpleXML is required for XML feeds.', 'allinonefeedimporter'));
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$xml) return new WP_Error('a1fi_xml', __('Invalid XML feed.', 'allinonefeedimporter'));
        $items = [];
        foreach ($xml->children() as $child) { $items[] = self::xml_node($child); }
        if (!$items && $xml->getName()) $items[] = self::xml_node($xml);
        return self::make_result($items);
    }

    private static function xml_node($node, $prefix = '') {
        $out = [];
        foreach ($node->attributes() as $key => $value) $out['@' . $key] = (string) $value;
        foreach ($node->children() as $key => $child) {
            $value = count($child->children()) ? self::xml_node($child) : (string) $child;
            if (array_key_exists($key, $out)) {
                if (!is_array($out[$key]) || !isset($out[$key][0])) $out[$key] = [$out[$key]];
                $out[$key][] = $value;
            } else $out[$key] = $value;
        }
        return self::flatten($out);
    }

    private static function make_result($rows) {
        $headers = [];
        foreach ($rows as $row) foreach (array_keys($row) as $key) if (!in_array($key, $headers, true)) $headers[] = $key;
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function fields() {
        return [
            'name' => __('Product name (required)', 'allinonefeedimporter'), 'sku' => __('SKU', 'allinonefeedimporter'),
            'description' => __('Description', 'allinonefeedimporter'), 'short_description' => __('Short description', 'allinonefeedimporter'),
            'regular_price' => __('Regular price', 'allinonefeedimporter'), 'sale_price' => __('Sale price', 'allinonefeedimporter'),
            'stock_quantity' => __('Stock quantity', 'allinonefeedimporter'), 'categories' => __('Categories (comma-separated)', 'allinonefeedimporter'),
            'images' => __('Image URLs (comma-separated)', 'allinonefeedimporter'), 'product_type' => __('Product type (simple)', 'allinonefeedimporter'),
            'status' => __('Status (publish, draft, pending)', 'allinonefeedimporter'),
        ];
    }

    public static function validate($mapping, $headers) {
        $errors = [];
        if (empty($mapping['name'])) $errors[] = __('Map Product name; this field is required.', 'allinonefeedimporter');
        foreach ($mapping as $field => $column) {
            if ($column === '') continue;
            if (!array_key_exists($field, self::fields())) $errors[] = sprintf(__('Unknown product field: %s', 'allinonefeedimporter'), $field);
            if (!in_array($column, $headers, true)) $errors[] = sprintf(__('Feed column “%s” does not exist.', 'allinonefeedimporter'), $column);
        }
        return $errors;
    }
}
