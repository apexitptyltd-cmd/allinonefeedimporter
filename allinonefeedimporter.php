<?php
/**
 * Plugin Name: All In One Feed Importer
 * Description: Import WooCommerce products from CSV, Excel, XML, and JSON files or remote supplier feeds, with field mapping and schedules.
 * Version: 1.1.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Wynand
 * Text Domain: allinonefeedimporter
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
define('A1FI_VERSION', '1.1.1');
define('A1FI_FILE', __FILE__);
define('A1FI_DIR', plugin_dir_path(__FILE__));
if (file_exists(A1FI_DIR . 'vendor/autoload.php')) require_once A1FI_DIR . 'vendor/autoload.php';
require_once A1FI_DIR . 'includes/class-a1fi-feed-reader.php';
require_once A1FI_DIR . 'includes/class-a1fi-plugin.php';
require_once A1FI_DIR . 'includes/class-a1fi-queue.php';
require_once A1FI_DIR . 'includes/class-a1fi-job-admin.php';
require_once A1FI_DIR . 'includes/class-a1fi-queue-recovery.php';
require_once A1FI_DIR . 'includes/class-a1fi-feed-cancel.php';
require_once A1FI_DIR . 'includes/class-a1fi-queue-integrity.php';
require_once A1FI_DIR . 'includes/class-a1fi-numeric-normalizer.php';
require_once A1FI_DIR . 'includes/class-a1fi-row-validation.php';
require_once A1FI_DIR . 'includes/class-a1fi-price-calculator.php';
require_once A1FI_DIR . 'includes/class-a1fi-import-value-guard.php';
require_once A1FI_DIR . 'includes/class-a1fi-import-logger.php';
require_once A1FI_DIR . 'includes/class-a1fi-job-error-viewer.php';
require_once A1FI_DIR . 'includes/class-a1fi-log-viewer-styles.php';
require_once A1FI_DIR . 'includes/class-a1fi-xml-admin.php';
require_once A1FI_DIR . 'includes/class-a1fi-queue-image-importer.php';
require_once A1FI_DIR . 'includes/class-a1fi-database-repair.php';
require_once A1FI_DIR . 'includes/class-a1fi-history-cleanup.php';
register_activation_hook(__FILE__, ['A1FI_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['A1FI_Plugin', 'deactivate']);
add_action('plugins_loaded', ['A1FI_Plugin', 'boot']);
add_action('plugins_loaded', ['A1FI_Queue', 'boot'], 20);
add_action('plugins_loaded', ['A1FI_Job_Admin', 'boot'], 30);
add_action('plugins_loaded', ['A1FI_Queue_Recovery', 'boot'], 40);
add_action('plugins_loaded', ['A1FI_Feed_Cancel', 'boot'], 50);
