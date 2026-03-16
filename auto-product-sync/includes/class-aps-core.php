<?php
/**
 * Core plugin class - 5-MINUTE CRON SUPPORT
 */

if (!defined('ABSPATH')) {
    exit;
}

class APS_Core {
    
    private static $sync_lock_key = 'aps_bulk_sync_lock';
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_loaded', array($this, 'loaded'));
        
        // Initialize components
        new APS_Admin();
        new APS_Product_Tab();
        
        // Add AJAX handlers with proper security
        add_action('wp_ajax_aps_sync_single_product', array($this, 'ajax_sync_single_product'));
        add_action('wp_ajax_aps_sync_all_products', array($this, 'ajax_sync_all_products'));
        add_action('wp_ajax_aps_abort_bulk_sync', array($this, 'ajax_abort_bulk_sync'));
        add_action('wp_ajax_aps_get_sync_status', array($this, 'ajax_get_sync_status'));
        add_action('wp_ajax_aps_trigger_next_batch', array($this, 'ajax_trigger_next_batch'));
        add_action('wp_ajax_aps_get_product_row_data', array($this, 'ajax_get_product_row_data'));
        add_action('wp_ajax_aps_get_multiple_product_rows', array($this, 'ajax_get_multiple_product_rows'));
        add_action('wp_ajax_aps_clear_lock', array($this, 'ajax_clear_lock'));
        
        // Add cron endpoint handler
        add_action('template_redirect', array($this, 'handle_cron_request'));
        
        // Add bulk sync process action
        add_action('aps_bulk_sync_process', array($this, 'run_bulk_sync_process'));
    }
    
    public function init() {
        load_plugin_textdomain('auto-product-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function loaded() {
        // Plugin fully loaded
    }
    
    /**
     * AJAX handler for single product sync - SECURITY FIXED
     */
    public function ajax_sync_single_product() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id || !wc_get_product($product_id)) {
            wp_send_json_error(__('Invalid product ID', 'auto-product-sync'), 400);
            exit;
        }
        
        $extractor = new APS_Price_Extractor();
        $result = $extractor->sync_product_price($product_id);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message'], 500);
        }
    }
    
    /**
     * AJAX handler for bulk sync - RACE CONDITION FIXED + AUTO CLEANUP
     */
    public function ajax_sync_all_products() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        // Check for existing lock
        $existing_lock = get_transient(self::$sync_lock_key);
        if ($existing_lock) {
            // Check if lock is stale (older than 10 minutes)
            $lock_age = time() - $existing_lock;
            if ($lock_age > 600) {
                // Stale lock - clean up and proceed
                $this->cleanup_sync();
            } else {
                // Valid lock - sync already running
                wp_send_json_error(__('Sync already running. Please wait.', 'auto-product-sync'), 409);
                exit;
            }
        }
        
        // Set lock for 10 minutes
        set_transient(self::$sync_lock_key, time(), 600);
        
        // Initialize batch processing - CLEAR OLD CACHE
        delete_transient('aps_current_batch');
        delete_transient('aps_all_products_list');
        delete_transient('aps_total_products_count');
        delete_transient('aps_bulk_sync_status');
        delete_transient('aps_abort_sync'); // Clear any previous abort flag
        set_transient('aps_current_batch', 0, 3600);
        
        // Get products to sync - FORCE ALL (ignore skip filter for manual sync)
        $products = self::get_products_for_bulk_sync(-1, 1, true);
        
        if (empty($products['products'])) {
            delete_transient(self::$sync_lock_key);
            wp_send_json_error(__('No products with sync enabled and URLs found.', 'auto-product-sync'), 404);
            exit;
        }
        
        // Initialize status tracking with CORRECT total
        set_transient('aps_bulk_sync_status', array(
            'running' => true,
            'completed' => 0,
            'total' => $products['total'],
            'failed' => 0,
            'current_product' => __('Starting first batch...', 'auto-product-sync'),
            'needs_next_batch' => false
        ), 3600);
        
        // Send success response FIRST
        wp_send_json_success(sprintf(__('Bulk sync started with %d products.', 'auto-product-sync'), $products['total']));
    }
    
    /**
     * AJAX handler to abort bulk sync
     */
    public function ajax_abort_bulk_sync() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        // Set abort flag
        set_transient('aps_abort_sync', true, 300);
        
        wp_send_json_success(__('Abort signal sent. Finishing current batch...', 'auto-product-sync'));
    }
    
    /**
     * AJAX handler to trigger next batch
     */
    public function ajax_trigger_next_batch() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        $extractor = new APS_Price_Extractor();
        $extractor->bulk_sync_process();
        
        wp_send_json_success(array('triggered' => true, 'message' => 'Batch processed'));
    }
    
    /**
     * Run bulk sync process
     */
    public function run_bulk_sync_process() {
        if (get_transient(self::$sync_lock_key)) {
            error_log('APS: Bulk sync skipped - already running');
            return;
        }
        
        set_transient(self::$sync_lock_key, time(), 600);
        
        $extractor = new APS_Price_Extractor();
        $extractor->bulk_sync_process();
        
        delete_transient(self::$sync_lock_key);
    }
    
    /**
     * AJAX handler for sync status
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        $status = get_transient('aps_bulk_sync_status');
        if (!$status) {
            $status = array(
                'running' => false, 
                'completed' => 0, 
                'total' => 0, 
                'failed' => 0,
                'current_product' => ''
            );
        }
        
        wp_send_json_success($status);
    }
    
    /**
     * Get products for bulk sync - ACCURATE COUNT FIX + 24HR FILTER
     * Only products with URLs AND sync enabled
     * Optionally skip products synced in last 24 hours
     * 
     * @param int $limit Not used, kept for compatibility
     * @param int $page Not used, kept for compatibility
     * @param bool $force_all If true, ignores skip filter (used for manual sync)
     */
    public static function get_products_for_bulk_sync($limit = -1, $page = 1, $force_all = false) {
        $query = new WC_Product_Query(array(
            'limit' => -1,
            'paginate' => false,
            'status' => array('publish', 'draft', 'private'),
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_aps_url',
                    'value' => '',
                    'compare' => '!='
                ),
                array(
                    'key' => '_aps_url',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_aps_enable_sync',
                    'value' => 'yes',
                    'compare' => '='
                )
            )
        ));
        
        $products = $query->get_products();
        
        // Check if we should skip recently synced products (not for manual sync)
        $skip_recent = !$force_all && get_option('aps_skip_recent_sync', 'no') === 'yes';
        $skip_hours = absint(get_option('aps_skip_recent_hours', 24));
        $cutoff_time = time() - ($skip_hours * 3600);
        
        // Filter out empty URLs and convert to post objects
        $post_objects = array();
        foreach ($products as $product) {
            $url = self::get_product_meta($product->get_id(), '_aps_url');
            if (empty(trim($url))) {
                continue;
            }
            
            // Skip recently synced products if option is enabled
            // Only skip if the last sync was SUCCESSFUL
            if ($skip_recent) {
                $last_sync = self::get_product_meta($product->get_id(), '_aps_last_sync_timestamp');
                $last_status = self::get_product_meta($product->get_id(), '_aps_last_status');
                
                // Only skip if sync was recent AND successful
                if (!empty($last_sync) && $last_sync > $cutoff_time) {
                    // Check if last status indicates success
                    if (!empty($last_status) && strpos($last_status, 'Success') === 0) {
                        // Product was successfully synced recently, skip it
                        continue;
                    }
                }
            }
            
            $post = get_post($product->get_id());
            if ($post) {
                $post_objects[] = $post;
            }
        }
        
        $true_total = count($post_objects);
        
        return array(
            'products' => $post_objects,
            'total' => $true_total,
            'max_pages' => 1
        );
    }
    
    /**
     * Get all products with URLs (for admin table) - Shows ALL regardless of enabled
     */
    public static function get_products_with_urls($limit = 100, $page = 1) {
        $query = new WC_Product_Query(array(
            'limit' => $limit,
            'page' => $page,
            'paginate' => true,
            'status' => array('publish', 'draft', 'private'),
            'meta_query' => array(
                array(
                    'key' => '_aps_url',
                    'value' => '',
                    'compare' => '!='
                ),
                array(
                    'key' => '_aps_url',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        $results = $query->get_products();
        
        $post_objects = array();
        foreach ($results->products as $product) {
            $url = self::get_product_meta($product->get_id(), '_aps_url');
            if (!empty(trim($url))) {
                $post = get_post($product->get_id());
                if ($post) {
                    $post_objects[] = $post;
                }
            }
        }
        
        return $post_objects;
    }
    
    /**
     * Get product meta value (HPOS compatible)
     */
    public static function get_product_meta($product_id, $meta_key, $single = true) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return $single ? '' : array();
        }
        
        $meta_data = $product->get_meta($meta_key, $single);
        return $meta_data;
    }
    
    /**
     * Update product meta value (HPOS compatible)
     */
    public static function update_product_meta($product_id, $meta_key, $meta_value) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        
        $product->update_meta_data($meta_key, $meta_value);
        $product->save();
        
        return true;
    }
    
    /**
     * Get product categories (HPOS compatible)
     */
    public static function get_product_categories($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return array();
        }
        
        $category_ids = $product->get_category_ids();
        $categories = array();
        
        foreach ($category_ids as $category_id) {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $categories[] = $term->name;
            }
        }
        
        return $categories;
    }
    
    /**
     * Delete product meta value (HPOS compatible)
     */
    public static function delete_product_meta($product_id, $meta_key) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        
        $product->delete_meta_data($meta_key);
        $product->save();
        
        return true;
    }
    
    /**
     * Format currency according to WooCommerce settings
     */
    public static function format_currency($amount) {
        if (function_exists('wc_price')) {
            return wc_price($amount);
        }
        return '$' . number_format($amount, 2);
    }
    
    /**
     * Log sync activity - SQL INJECTION FIXED
     */
    public static function log_sync_activity($product_id, $status, $message = '', $old_price = 0, $new_price = 0, $old_sale_price = 0, $new_sale_price = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aps_sync_log';
        
        $wpdb->insert(
            $table_name,
            array(
                'product_id' => absint($product_id),
                'status' => sanitize_text_field($status),
                'message' => sanitize_textarea_field($message),
                'old_price' => floatval($old_price),
                'new_price' => floatval($new_price),
                'old_sale_price' => floatval($old_sale_price),
                'new_sale_price' => floatval($new_sale_price),
                'sync_time' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s')
        );
    }
    
    /**
     * Release sync lock (call when sync completes or errors)
     */
    public static function release_sync_lock() {
        delete_transient(self::$sync_lock_key);
    }
    
    /**
     * Cleanup sync - clear all transients and locks
     */
    private function cleanup_sync() {
        delete_transient(self::$sync_lock_key);
        delete_transient('aps_current_batch');
        delete_transient('aps_all_products_list');
        delete_transient('aps_total_products_count');
        delete_transient('aps_abort_sync');
    }
    
    /**
     * AJAX handler to get single product row data
     */
    public function ajax_get_product_row_data() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID', 'auto-product-sync'), 400);
            exit;
        }
        
        $data = $this->get_product_row_data($product_id);
        wp_send_json_success($data);
    }
    
    /**
     * AJAX handler to get multiple product row data
     */
    public function ajax_get_multiple_product_rows() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        $product_ids = isset($_POST['product_ids']) ? array_map('absint', $_POST['product_ids']) : array();
        if (empty($product_ids)) {
            wp_send_json_error(__('No product IDs provided', 'auto-product-sync'), 400);
            exit;
        }
        
        $results = array();
        foreach ($product_ids as $product_id) {
            $results[$product_id] = $this->get_product_row_data($product_id);
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Get product row data for table update
     */
    private function get_product_row_data($product_id) {
        $error_date = self::get_product_meta($product_id, '_aps_error_date');
        $last_status = self::get_product_meta($product_id, '_aps_last_status');
        $last_sync_timestamp = self::get_product_meta($product_id, '_aps_last_sync_timestamp');
        $retry_count = self::get_product_meta($product_id, '_aps_retry_count') ?: 0;
        
        // Format timestamp with timezone conversion
        $formatted_timestamp = '';
        if ($last_sync_timestamp) {
            $formatted_timestamp = get_date_from_gmt(
                date('Y-m-d H:i:s', $last_sync_timestamp), 
                get_option('date_format') . ' ' . get_option('time_format')
            );
        }
        
        return array(
            'error_date' => $error_date ? date_i18n(get_option('date_format'), strtotime($error_date)) : '',
            'last_status' => $last_status ? esc_html($last_status) : '',
            'timestamp' => $formatted_timestamp,
            'retry_count' => absint($retry_count)
        );
    }
    
    /**
     * AJAX handler to manually clear locks
     */
    public function ajax_clear_lock() {
        check_ajax_referer('aps_nonce', 'security');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized access', 'auto-product-sync'), 403);
            exit;
        }
        
        $this->cleanup_sync();
        
        wp_send_json_success(__('All locks and transients cleared successfully.', 'auto-product-sync'));
    }
    
    /**
     * Handle cron endpoint requests - OPTIMIZED FOR 5-MINUTE INTERVALS
     */
    public function handle_cron_request() {
        // Check if this is a cron request
        if (!isset($_GET['aps_cron']) || $_GET['aps_cron'] !== '1') {
            return;
        }
        
        $logger = new APS_Logger();
        
        // Validate secret key
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $stored_key = get_option('aps_cron_secret_key', '');
        
        if (empty($provided_key) || empty($stored_key) || !hash_equals($stored_key, $provided_key)) {
            $logger->log("Cron access denied: Invalid or missing key from IP " . $_SERVER['REMOTE_ADDR'], 'error');
            
            wp_send_json_error(array(
                'message' => 'Invalid or missing secret key',
                'timestamp' => current_time('mysql')
            ), 403);
            exit;
        }
        
        $logger->log("Cron triggered from IP " . $_SERVER['REMOTE_ADDR'], 'info');
        
        // Check for existing lock
        $existing_lock = get_transient(self::$sync_lock_key);
        if ($existing_lock) {
            $lock_age = time() - $existing_lock;
            if ($lock_age > 300) { // 5 minutes - matches cron interval
                // Stale lock - clean up
                $this->cleanup_sync();
                $logger->log("Cleared stale lock (age: {$lock_age}s)", 'info');
            } else {
                $logger->log("Cron skipped: Sync already running (lock age: {$lock_age}s)", 'info');
                wp_send_json_success(array(
                    'message' => 'Sync already running',
                    'lock_age' => $lock_age,
                    'timestamp' => current_time('mysql')
                ));
                exit;
            }
        }
        
        // Get products to sync
        $products = self::get_products_for_bulk_sync();
        
        if (empty($products['products'])) {
            $logger->log("Cron: No products to sync", 'info');
            wp_send_json_success(array(
                'message' => 'No products to sync',
                'total' => 0,
                'timestamp' => current_time('mysql')
            ));
            exit;
        }
        
        // Check if there's an ongoing sync from previous cron runs
        $status = get_transient('aps_bulk_sync_status');
        
        if (!$status || !$status['running'] || isset($status['finished'])) {
            // No ongoing sync - start a new one
            set_transient(self::$sync_lock_key, time(), 300); // 5-minute lock
            
            delete_transient('aps_current_batch');
            delete_transient('aps_all_products_list');
            delete_transient('aps_total_products_count');
            delete_transient('aps_bulk_sync_status');
            delete_transient('aps_abort_sync');
            set_transient('aps_current_batch', 0, 3600);
            
            set_transient('aps_bulk_sync_status', array(
                'running' => true,
                'completed' => 0,
                'total' => $products['total'],
                'failed' => 0,
                'current_product' => 'Starting cron sync...',
                'needs_next_batch' => false
            ), 3600);
            
            $logger->log("Cron: Starting NEW sync with {$products['total']} products", 'info');
        } else {
            // Continue existing sync
            set_transient(self::$sync_lock_key, time(), 300); // Extend lock
            $logger->log("Cron: Continuing EXISTING sync - {$status['completed']}/{$status['total']} completed", 'info');
        }
        
        // Process ONE batch
        $extractor = new APS_Price_Extractor();
        $extractor->bulk_sync_process();
        
        // Get final status
        $final_status = get_transient('aps_bulk_sync_status');
        
        // Release lock
        delete_transient(self::$sync_lock_key);
        
        if ($final_status) {
            $logger->log("Cron: Batch completed. Progress: {$final_status['completed']}/{$final_status['total']}", 'info');
            
            wp_send_json_success(array(
                'message' => isset($final_status['finished']) ? 'Sync completed' : 'Batch processed',
                'total' => $final_status['total'],
                'completed' => $final_status['completed'],
                'failed' => $final_status['failed'],
                'finished' => isset($final_status['finished']),
                'timestamp' => current_time('mysql')
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'Batch processed',
                'timestamp' => current_time('mysql')
            ));
        }
        
        exit;
    }
}
