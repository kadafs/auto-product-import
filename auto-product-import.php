<?php
/**
 * Plugin Name: Auto Product Import
 * Plugin URI: https://github.com/kadafs
 * Description: Automatically add WooCommerce products from URLs
 * Version: 2.1.4
 * Author: Kadafs, ArtInMetal
 * Author URI: https://github.com/kadafs
 * Text Domain: auto-product-import
 * Domain Path: /languages
 * WC requires at least: 6.0.0
 * WC tested up to: 9.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('AUTO_PRODUCT_IMPORT_VERSION', '2.1.4');
define('AUTO_PRODUCT_IMPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AUTO_PRODUCT_IMPORT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Declare HPOS (High-Performance Order Storage) compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Check if WooCommerce is active
 */
function auto_product_import_check_woocommerce() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_action('admin_notices', 'auto_product_import_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display notice if WooCommerce is not active
 */
function auto_product_import_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('Auto Product Import requires WooCommerce to be installed and active.', 'auto-product-import'); ?></p>
    </div>
    <?php
}

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'auto_product_import_activate');
function auto_product_import_activate() {
    if (!auto_product_import_check_woocommerce()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Auto Product Import requires WooCommerce to be installed and active.', 'auto-product-import'));
    }
}

/**
 * Plugin initialization
 */
add_action('plugins_loaded', 'auto_product_import_init');
function auto_product_import_init() {
    if (!auto_product_import_check_woocommerce()) {
        return;
    }
    
    // Load plugin text domain
    load_plugin_textdomain('auto-product-import', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Load helper functions
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/helpers/functions-url.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/helpers/functions-dom.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/helpers/functions-validation.php';
    
    // Load import classes - CORE
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-html-parser.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-image-extractor.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-bigcommerce-extractor.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-shopify-extractor.php';
    
    // Load PDF extractor classes (SPLIT FILES)
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-pdf-extractor-validator.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-pdf-extractor-html-parser.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-pdf-extractor-js-parser.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-pdf-extractor.php';
    
    // Load description extractor classes (SPLIT FILES)
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-description-extractor-additional-info.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-description-extractor.php';
    
    // Load product scraper classes (SPLIT FILES)
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-product-scraper-sku.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-product-scraper-extractors.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-product-scraper.php';
    
    // Load uploader classes
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-image-uploader.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-pdf-uploader.php';
    
    // Load product creator classes (SPLIT FILES)
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-product-creator-sync-fields.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/import/class-product-creator.php';
    
    // Load admin classes
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/admin/class-template-data.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/admin/class-settings-handler.php';
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/admin/class-admin-menu.php';
    
    // Load AJAX handler
    require_once AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'includes/ajax/class-ajax-handler.php';
    
    // Initialize admin menu
    $admin_menu = new APM_Admin_Menu();
    $admin_menu->init();
    
    // Initialize settings handler
    $settings_handler = new APM_Settings_Handler();
    $settings_handler->init();
    
    // Initialize AJAX handler
    $ajax_handler = new APM_Ajax_Handler();
    $ajax_handler->init();
}
