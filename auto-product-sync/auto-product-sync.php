<?php
/**
 * Plugin Name: Auto Product Sync
 * Plugin URI: https://yoursite.com/auto-product-sync
 * Description: Automatically sync product prices from external URLs for WooCommerce products
 * Version: 1.3.1
 * Author: ArtInMetal
 * License: GPL v2 or later
 * Text Domain: auto-product-sync
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Define plugin constants
define('APS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('APS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('APS_VERSION', '1.3.1');

// Include required files
require_once APS_PLUGIN_PATH . 'includes/class-aps-core.php';
require_once APS_PLUGIN_PATH . 'includes/class-aps-admin.php';
require_once APS_PLUGIN_PATH . 'includes/class-aps-product-tab.php';
require_once APS_PLUGIN_PATH . 'includes/class-aps-eastwesteng-extractor.php';
require_once APS_PLUGIN_PATH . 'includes/class-aps-price-extractor.php';
require_once APS_PLUGIN_PATH . 'includes/class-aps-logger.php';

// Initialize the plugin
function aps_init() {
    new APS_Core();
}
add_action('plugins_loaded', 'aps_init');

// Activation hook - DATABASE INDEXES ADDED
register_activation_hook(__FILE__, 'aps_activate');
function aps_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'aps_sync_log';
    $charset_collate = $wpdb->get_charset_collate();
    
    // Create table with proper indexes
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        sync_time datetime DEFAULT CURRENT_TIMESTAMP,
        status varchar(20) NOT NULL,
        message text,
        old_price decimal(10,2),
        new_price decimal(10,2),
        old_sale_price decimal(10,2),
        new_sale_price decimal(10,2),
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY sync_time (sync_time),
        KEY status (status),
        KEY product_status (product_id, status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Set default options with proper validation
    $default_options = array(
        'aps_detailed_logging' => 'no',
        'aps_admin_email' => sanitize_email(get_option('admin_email')),
        'aps_fetch_timeout' => 30,
        'aps_batch_size' => 10,
        'aps_max_errors' => 1,
        'aps_skip_recent_sync' => 'no',
        'aps_skip_recent_hours' => 24
    );
    
    foreach ($default_options as $option => $value) {
        if (!get_option($option)) {
            update_option($option, $value);
        }
    }
    
    // Generate cron secret key if it doesn't exist
    if (!get_option('aps_cron_secret_key')) {
        $cron_key = wp_generate_password(32, false);
        update_option('aps_cron_secret_key', $cron_key);
    }
    
    // Clear any existing locks on activation
    delete_transient('aps_bulk_sync_lock');
    delete_transient('aps_current_batch');
    delete_transient('aps_bulk_sync_status');
    
    // Clear old WordPress cron schedules (migration from v1.1.0)
    wp_clear_scheduled_hook('aps_scheduled_sync');
    delete_option('aps_schedule_frequency');
    delete_option('aps_schedule_time');
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'aps_deactivate');
function aps_deactivate() {
    // Clear any scheduled events (legacy)
    wp_clear_scheduled_hook('aps_scheduled_sync');
    
    // Clear transients
    delete_transient('aps_bulk_sync_lock');
    delete_transient('aps_current_batch');
    delete_transient('aps_bulk_sync_status');
}

// Add settings link to plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'aps_add_settings_link');
function aps_add_settings_link($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=auto-product-sync-settings')) . '">' . esc_html__('Settings', 'auto-product-sync') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Admin notice for missing WooCommerce
add_action('admin_notices', 'aps_check_woocommerce');
function aps_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('Auto Product Sync', 'auto-product-sync') . '</strong>: ';
        echo esc_html__('This plugin requires WooCommerce to be installed and activated.', 'auto-product-sync');
        echo '</p></div>';
    }
}

// Admin notice for cron setup reminder (only show once after activation)
add_action('admin_notices', 'aps_cron_setup_notice');
function aps_cron_setup_notice() {
    // Only show on plugin pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'auto-product-sync') === false) {
        return;
    }
    
    // Check if user has dismissed this notice
    if (get_option('aps_cron_notice_dismissed')) {
        return;
    }
    
    // Check if cron key exists
    $cron_key = get_option('aps_cron_secret_key');
    if (empty($cron_key)) {
        return;
    }
    
    ?>
    <div class="notice notice-info is-dismissible" id="aps-cron-notice">
        <p>
            <strong><?php echo esc_html__('Auto Product Sync:', 'auto-product-sync'); ?></strong>
            <?php echo esc_html__('Remember to set up a server cron job for automatic price updates.', 'auto-product-sync'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=auto-product-sync-settings')); ?>">
                <?php echo esc_html__('View setup instructions', 'auto-product-sync'); ?>
            </a>
        </p>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $(document).on('click', '#aps-cron-notice .notice-dismiss', function() {
            $.post(ajaxurl, {
                action: 'aps_dismiss_cron_notice',
                nonce: '<?php echo wp_create_nonce('aps_dismiss_notice'); ?>'
            });
        });
    });
    </script>
    <?php
}

// Handle notice dismissal
add_action('wp_ajax_aps_dismiss_cron_notice', 'aps_dismiss_cron_notice');
function aps_dismiss_cron_notice() {
    check_ajax_referer('aps_dismiss_notice', 'nonce');
    update_option('aps_cron_notice_dismissed', true);
    wp_send_json_success();
}
