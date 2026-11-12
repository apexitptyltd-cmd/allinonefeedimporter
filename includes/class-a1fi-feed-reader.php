<?php
if (!defined('ABSPATH')) { exit; }

/** Read feed data into normalized headers and rows. */
class A1FI_Feed_Reader {
    const MAX_BYTES = 20971520;
    const MAX_ROWS = 10000;

    public static function read($file, $type, $delimiter = ',', $options = []) {
        if (!$file || !is_readable($file) || filesize($file) > self::MAX_BYTES) {
            return new WP_Error('a1fi_file', __('Feed file is missing, unreadable, or larger than 20 MB.', 'allinonefeedimporter'));
        }
        switch (strtolower($type)) {
            case 'csv':
                return self::csv($file, $delimiter);
            case 'xlsx':
            case 'xls':
                return self::excel($file);
            case 'json':
                return self::json($file);
            case 'xml':
                // Preview sends the unsaved control value; scheduled/import jobs pass the saved feed option.
                if (isset($_POST['item_path']) && empty($options['item_path'])) {
                    $options['item_path'] = sanitize_text_field(wp_unslash($_POST['item_path']));
                }
                return self::xml($file, $options['item_path'] ?? '');
        }
        return new WP_Error('a1fi_type', __('Unsupported feed format.', 'allinonefeedimporter'));
    }

    private static function csv($file, $delimiter) {
        $delimiter = in_array($delimiter, [',', ';', "\t", '|'], true) ? $delimiter : ',';
        $fh = fopen($file, 'rb');
        if (!$fh) return new WP_Error('a1fi_csv', __('Could not read CSV.', 'allinonefeedimporter'));

        // Pass escape explicitly for PHP 8.4+. An empty escape uses standard CSV quoting.
        $escape = '';
        $headers = fgetcsv($fh, 0, $delimiter, '"', $escape);
        if (!$headers) {
            fclose($fh);
            return new WP_Error('a1fi_headers', __('CSV has no header row.', 'allinonefeedimporter'));
        }
        $headers = array_map(static function ($header) {
            return trim((string) $header, "\xEF\xBB\xBF \t\n\r\0\x0B");
        }, $headers);
        $rows = [];
        while (($values = fgetcsv($fh, 0, $delimiter, '"', $escape)) !== false && count($rows) < self::MAX_ROWS) {
            if ($values === [null]) continue;
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }
            $rows[] = $row;
        }
        fclose($fh);
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function excel($file) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            return new WP_Error('a1fi_excel_dependency', __('Excel support needs PhpSpreadsheet. Run composer install in the plugin directory.', 'allinonefeedimporter'));
        }
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $all = $book->getActiveSheet()->toArray(null, true, true, false);
            if (!$all) return new WP_Error('a1fi_excel_empty', __('Excel sheet is empty.', 'allinonefeedimporter'));
            $headers = array_map('trim', array_map('strval', array_shift($all)));
            $rows = [];
            foreach (array_slice($all, 0, self::MAX_ROWS) as $values) {
                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') $row[$header] = isset($values[$index]) ? (string) $values[$index] : '';
                }
                $rows[] = $row;
            }
            return ['headers' => $headers, 'rows' => $rows];
        } catch (Throwable $error) {
            return new WP_Error('a1fi_excel_read', __('Could not parse Excel file.', 'allinonefeedimporter'));
        }
    }

    private static function json($file) {
        $contents = file_get_contents($file);
        $data = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('a1fi_json', __('Invalid JSON: ', 'allinonefeedimporter') . json_last_error_msg());
        }
        foreach (['products', 'items', 'data'] as $key) {
            if (is_array($data) && isset($data[$key]) && is_array($data[$key])) {
                $data = $data[$key];
                break;
            }
        }
        if (!is_array($data)) return new WP_Error('a1fi_json_shape', __('JSON must contain product records.', 'allinonefeedimporter'));
        if (!self::is_list($data)) $data = [$data];
        $rows = [];
        foreach (array_slice($data, 0, self::MAX_ROWS) as $item) {
            if (is_array($item)) $rows[] = self::flatten_array($item);
        }
        return self::make_result($rows);
    }

    private static function is_list($array) {
        if (function_exists('array_is_list')) return array_is_list($array);
        return !$array || array_keys($array) === range(0, count($array) - 1);
    }

    private static function flatten_array($array, $prefix = '') {
        $out = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && !self::is_list($value)) {
                $out += self::flatten_array($value, $path);
            } elseif (is_array($value)) {
                $out[$path] = implode(',', array_map(static function ($item) {
                    return is_scalar($item) ? (string) $item : '';
                }, $value));
            } else {
                $out[$path] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $out;
    }

    private static function xml($file, $item_path = '') {
        if (!function_exists('simplexml_load_file')) {
            return new WP_Error('a1fi_xml_dependency', __('PHP SimpleXML is required for XML feeds.', 'allinonefeedimporter'));
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$xml) return new WP_Error('a1fi_xml', __('Invalid XML feed.', 'allinonefeedimporter'));

        if (trim($item_path) !== '') {
            $nodes = self::xpath_nodes($xml, trim($item_path));
            if (is_wp_error($nodes)) return $nodes;
        } else {
            $nodes = self::detect_records($xml);
        }
        if (!$nodes) {
            return new WP_Error('a1fi_xml_records', __('No product records detected. Set XML item XPath to the repeated item node, for example //ProductDto, and preview again.', 'allinonefeedimporter'));
        }
        $rows = [];
        foreach (array_slice($nodes, 0, self::MAX_ROWS) as $node) {
            if ($node instanceof SimpleXMLElement) $rows[] = self::xml_fields($node);
        }
        if (!$rows) return new WP_Error('a1fi_xml_empty_records', __('The selected XML nodes contain no readable product fields. Check the item XPath.', 'allinonefeedimporter'));
        return self::make_result($rows);
    }

    /** Standard XPath first; support unprefixed //Name for default-namespace feeds. */
    private static function xpath_nodes($xml, $path) {
        $nodes = $xml->xpath($path);
        if ($nodes !== false && $nodes) return $nodes;
        if (preg_match('~^//([A-Za-z_][A-Za-z0-9_.-]*)$~', $path, $matches)) {
            $name = $matches[1];
            $nodes = $xml->xpath('//*[local-name()="' . $name . '"]');
            if ($nodes !== false && $nodes) return $nodes;
        }
        if (preg_match('~^/([A-Za-z_][A-Za-z0-9_.-]*)(?:/([A-Za-z_][A-Za-z0-9_.-]*))+$~', $path)) {
            // Prefix-free absolute paths cannot match a default namespace: retry each step by local-name.
            $parts = array_values(array_filter(explode('/', trim($path, '/'))));
            $expression = '';
            foreach ($parts as $part) $expression .= '/*[local-name()="' . esc_sql($part) . '"]';
            $nodes = $xml->xpath($expression);
            if ($nodes !== false && $nodes) return $nodes;
        }
        return new WP_Error('a1fi_xml_xpath', sprintf(__('XML item XPath “%s” matched no nodes. Try //ProductDto or //*[local-name()="ProductDto"].', 'allinonefeedimporter'), $path));
    }

    /** Prefer repeated product/item nodes anywhere below wrappers, not collection metadata. */
    private static function detect_records($root) {
        $groups = [];
        self::collect_repeated_groups($root, 0, $groups);
        if ($groups) {
            usort($groups, static function ($a, $b) {
                if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
                if ($a['depth'] !== $b['depth']) return $b['depth'] <=> $a['depth'];
                return count($b['nodes']) <=> count($a['nodes']);
            });
            return $groups[0]['nodes'];
        }
        // If a feed contains exactly one product object rather than a list, unwrap metadata-only containers.
        $node = $root;
        for ($i = 0; $i < 12; $i++) {
            $children = self::element_children($node);
            if (!$children) break;
            $complex = array_values(array_filter($children, static function ($child) {
                return self::element_children($child) !== [];
            }));
            if (count($complex) !== 1) break;
            $node = $complex[0];
        }
        return self::element_children($node) ? [$node] : [];
    }

    private static function collect_repeated_groups($node, $depth, &$groups) {
        if ($depth > 30) return;
        $by_name = [];
        foreach (self::element_children($node) as $child) $by_name[self::local_name($child)][] = $child;
        foreach ($by_name as $name => $siblings) {
            if (count($siblings) > 1) {
                $groups[] = ['nodes' => $siblings, 'score' => self::record_score($name, $siblings[0]), 'depth' => $depth + 1];
            }
        }
        foreach (self::element_children($node) as $child) self::collect_repeated_groups($child, $depth + 1, $groups);
    }

    private static function record_score($name, $node) {
        $name = strtolower($name);
        $score = 0;
        foreach (['product' => 100, 'productdto' => 120, 'item' => 90, 'offer' => 90, 'entry' => 60, 'record' => 70] as $term => $value) {
            if (strpos($name, $term) !== false) $score = max($score, $value);
        }
        if (self::element_children($node)) $score += 20;
        return $score;
    }

    private static function local_name($node) {
        $name = $node->getName();
        $position = strrpos($name, ':');
        return $position === false ? $name : substr($name, $position + 1);
    }

    /** XPath direct-child selection handles default and prefixed namespaces uniformly. */
    private static function element_children($node) {
        $children = $node->xpath('./*');
        return $children === false ? [] : $children;
    }

    /** Flatten XML fields to dotted paths; attributes use .@attribute. */
    private static function xml_fields($node, $prefix = '') {
        $out = [];
        foreach ($node->attributes() as $name => $value) {
            $out[$prefix === '' ? '@' . $name : $prefix . '.@' . $name] = (string) $value;
        }
        foreach (self::element_children($node) as $child) {
            $name = self::local_name($child);
            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            if (self::element_children($child)) {
                $out += self::xml_fields($child, $path);
            } else {
                $out[$path] = trim((string) $child);
            }
        }
        if (!$out && trim((string) $node) !== '') $out[$prefix !== '' ? $prefix . '.value' : 'value'] = trim((string) $node);
        return $out;
    }

    private static function make_result($rows) {
        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $headers, true)) $headers[] = $key;
            }
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function fields() {
        return [
            'name' => __('Product name (required)', 'allinonefeedimporter'),
            'sku' => __('SKU', 'allinonefeedimporter'),
            'description' => __('Description', 'allinonefeedimporter'),
            'short_description' => __('Short description', 'allinonefeedimporter'),
            'regular_price' => __('Regular price', 'allinonefeedimporter'),
            'sale_price' => __('Sale price', 'allinonefeedimporter'),
            'stock_quantity' => __('Stock quantity', 'allinonefeedimporter'),
            'categories' => __('Categories (comma separated)', 'allinonefeedimporter'),
            'images' => __('Image URLs (comma separated)', 'allinonefeedimporter'),
            'status' => __('Product status', 'allinonefeedimporter'),
        ];
    }

    public static function validate($mapping, $headers) {
        $errors = [];
        if (empty($mapping['name'])) $errors[] = __('Map Product name; this field is required.', 'allinonefeedimporter');
        foreach ($mapping as $field => $column) {
            if ($column !== '' && !in_array($column, $headers, true)) {
                $errors[] = sprintf(__('Mapped column is missing: %s', 'allinonefeedimporter'), $column);
            }
        }
        return $errors;
    }
}
