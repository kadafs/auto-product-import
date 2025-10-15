<?php
/**
 * AJAX Handler class
 *
 * @package Auto_Product_Import
 * @since 2.1.1
 */

if (!defined('WPINC')) {
    die;
}

class APM_Ajax_Handler {
    
    /**
     * Initialize AJAX handler
     */
    public function init() {
        add_action('wp_ajax_import_product_from_url', array($this, 'handle_import_request'));
    }
    
    /**
     * Handle import request
     */
    public function handle_import_request() {
        check_ajax_referer('auto-product-import-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to import products.', 'auto-product-import')));
            return;
        }
        
        $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';
        if (!apm_validate_url($url)) {
            wp_send_json_error(array('message' => __('Please provide a valid URL.', 'auto-product-import')));
            return;
        }
        
        $scraper = new APM_Product_Scraper();
        $product_data = $scraper->fetch($url);
        
        if (is_wp_error($product_data)) {
            wp_send_json_error(array('message' => $product_data->get_error_message()));
            return;
        }
        
        $creator = new APM_Product_Creator();
        $product_id = $creator->create($product_data);
        
        if (is_wp_error($product_id)) {
            wp_send_json_error(array('message' => $product_id->get_error_message()));
            return;
        }
        
        wp_send_json_success(array(
            'message' => __('Product imported successfully!', 'auto-product-import'),
            'product_id' => $product_id,
            'edit_link' => get_edit_post_link($product_id, 'raw'),
            'view_link' => get_permalink($product_id)
        ));
    }
}