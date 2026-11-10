# All In One Feed Importer

WooCommerce plugin for importing product catalogs from CSV, Excel, XML, and JSON files or direct feed URLs. Includes previews, per-feed mapping, schedules, asynchronous imports, bounded processing batches, duplicate-SKU checks, and live progress.

## Install

- Requirements: WordPress 6.2+, PHP 7.4+, WooCommerce, SimpleXML for XML, and Composer dependencies for Excel.
- Install the plugin folder under `wp-content/plugins/allinonefeedimporter/` or download the installable ZIP from the repository's **Actions → Build installable plugin ZIP → Artifacts** after a successful workflow run.
- Activate the plugin, then open **WooCommerce → Feed Importer**.
- The GitHub **Code → Download ZIP** archive is source code; it does not include Composer's `vendor/` dependencies. Build the workflow artifact or run `composer install --no-dev --optimize-autoloader` before packaging to include Excel support.

## Batched imports and progress

Click **Run now** to create a queued import job. The progress panel polls the server and reports processed/total rows, created, updated, skipped, and failed counts. Products are processed in batches of 50 rows. Feed records are staged in WordPress database tables so each worker invocation only loads one batch into memory. Repeated non-empty SKUs in the same feed job are skipped after the first row.

The feed parser currently enforces a 20 MB source limit and at most 10,000 rows per run. It parses and stages the feed before batch processing; for extremely large catalogs, split the supplier export into smaller feeds. Image downloading is deliberately not performed in the batch worker to keep each worker request bounded.

## Reliable server cron

WP-Cron is traffic-triggered. For reliable processing, disable page-load cron in `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

Then add a server crontab entry (replace the URL with your site's HTTPS URL):

```cron
* * * * * /usr/bin/curl -fsS --max-time 50 'https://example.com/wp-cron.php?doing_wp_cron' >/dev/null 2>&1
```

Alternatively, if WP-CLI is installed and run as the correct site user:

```cron
* * * * * cd /path/to/wordpress && /usr/local/bin/wp cron event run --due-now --quiet >/dev/null 2>&1
```

The plugin schedules its queue worker every minute and enqueues selected feed schedules through the existing schedule checker. Verify that `a1fi_process_queue` appears in `wp cron event list`, and monitor WooCommerce → Feed Importer for progress. Avoid running multiple independent cron schedulers for the same site.

## Source and mapping

Use authorized direct feed URLs or uploads. Supported source formats: CSV, XLS/XLSX, XML, JSON. Map Product name (required), and preferably SKU for updates/deduplication. Remote marketplace pages are not feed endpoints; the plugin does not scrape sites or bypass authentication. Use supplier-generated exports or official API integrations.

## Notes

- Imports update existing products matched by SKU; otherwise they create simple products.
- Feed histories/progress are stored in custom WordPress database tables. Do not remove these tables during routine cleanup.
- Test on staging and back up the store. This version does not support product variations, automatic deletion of products absent from a feed, or rollback.
