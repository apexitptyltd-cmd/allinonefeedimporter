# All In One Feed Importer

A WooCommerce extension for importing products from supplier feeds and files in CSV, Excel (`.xls`/`.xlsx`), XML, and JSON formats. Configure independent field mappings, inspect sample rows, validate mappings, run imports manually, or schedule each feed by weekday and local time.

## Features

- Import uploaded files or direct remote feed URLs.
- CSV, Excel, XML, and JSON parsers; JSON `products`, `items`, and `data` arrays are recognized, and nested JSON keys are flattened to paths such as `product.sku`.
- Admin preview of source columns and sample rows, plus mapping validation before saving/importing.
- Per-feed mappings for product name, SKU, descriptions, prices, stock quantity, categories, images, type, and status.
- SKU-based updates to existing products; products without a matching SKU are created.
- Weekday/time schedules in the site's timezone and a manual “Run now” action.
- Import result and last-run status shown for each feed.
- Limits feed files to 20 MB and processes at most 10,000 rows per run.

## Requirements

- WordPress 6.2+
- PHP 7.4+
- WooCommerce (active)
- PHP SimpleXML for XML support
- PhpSpreadsheet for Excel support

## Install

1. Download this repository using **Code → Download ZIP** (GitHub creates a ZIP of the repository), or install the plugin directory in `wp-content/plugins/allinonefeedimporter/`.
2. For Excel parsing, run Composer in the plugin directory before deploying:

   ```sh
   composer install --no-dev --optimize-autoloader
   ```

3. Activate **All In One Feed Importer** in WordPress → Plugins.
4. Open WooCommerce → Feed Importer.

The repository's ZIP is source code and does not include Composer's `vendor/` directory. Run Composer and package the resulting plugin folder for a deployable ZIP if Excel support is needed.

## Configure a feed

1. Enter a feed name and choose **Remote URL** or **Upload file**.
2. Provide the supplier's direct feed URL or upload a CSV/XLS/XLSX/XML/JSON file. Select the matching format and CSV delimiter if applicable.
3. Click **Preview columns**. Preview reads the current uploaded file or URL and displays detected columns and sample values. Some feeds require a supplier account/API token or a generated direct export URL; the plugin does not log in to supplier websites.
4. Map **Product name** (required). SKU is recommended: a matching SKU updates the existing product; otherwise a product is created.
5. Click **Validate mappings**, then save the feed. A feed with an invalid/missing name-column mapping is rejected at import time.
6. Optionally select weekdays, a local time, and **Enable schedule**, or choose **Run now**.

To edit a saved feed, choose **Edit** in the configured-feeds table. For uploaded files, the previous upload remains attached unless replaced with a new upload.

## Supplier compatibility

This is a configurable generic feed importer, not a bespoke connector or API client for Amazon, Shopify, or AliExpress. It can import a supplier's **direct, accessible** CSV/Excel/XML/JSON export when its structure can be represented by the available mappings. Shopify product exports and supplier-generated product feeds can often be mapped; marketplace URLs that serve HTML pages, require browser sessions, block server requests, or need API authentication will not work as plain feed URLs. Provide an authorized export/feed URL and choose the matching file format. XML feed items are taken from the root's immediate child elements; formats with a custom wrapper may need their item layout adjusted or exported in a supported structure.

## Scheduling and reliability

Schedules use the WordPress site timezone. WordPress cron is triggered by site visits, so an exact scheduled minute is best-effort on low-traffic sites. For reliable execution, configure a system cron to request `wp-cron.php` regularly and disable page-load cron if appropriate for your hosting environment. Imports run synchronously and may need batching/queueing for very large catalogs.

## Security and operational notes

- Only users with `manage_woocommerce` can configure or run imports; admin actions use WordPress nonces.
- Remote feeds use WordPress safe HTTP requests and have a 20 MB response limit. Use trusted supplier URLs only.
- Test feeds and mappings on a staging store first. Imports can create/update products and download product images into the media library.
- Feed URLs should be direct files/endpoints. The plugin does not scrape websites, bypass access controls, or handle marketplace credentials.
- This starter release does not delete products missing from a feed, import variations/attributes/taxonomies beyond categories, or provide transactional rollback. Review pricing and stock mappings carefully.

## Development

```sh
composer install
```

PhpSpreadsheet is declared in `composer.json`. WordPress and WooCommerce are supplied by the target site.
