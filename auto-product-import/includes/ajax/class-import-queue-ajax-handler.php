<?php
/**
 * Import Queue AJAX Handler class
 *
 * Handles AJAX requests for import queue
 *
 * @package Auto_Product_Import
 * @since 2.2.0
 */

if (!defined('WPINC')) {
    die;
}

class APM_Import_Queue_Ajax_Handler {
    
    private $database;
    private $processor;
    
    /**
     * Initialize AJAX handler
     */
    public function init() {
        $this->database = new APM_Import_Queue_Database();
        $this->processor = new APM_Import_Queue_Batch_Processor();
        
        add_action('wp_ajax_apm_import_queue_sync_products', array($this, 'handle_sync_products'));
        add_action('wp_ajax_apm_import_queue_batch_import', array($this, 'handle_batch_import'));
        add_action('wp_ajax_apm_import_queue_stop_import', array($this, 'handle_stop_import'));
        add_action('wp_ajax_apm_import_queue_verify_products', array($this, 'handle_verify_products'));
    }
    
    /**
     * Handle sync products request
     */
    public function handle_sync_products() {
        check_ajax_referer('auto-product-import-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'auto-product-import')));
            return;
        }
        
        $result = $this->database->sync_from_apb_products();
        
        if (isset($result['error'])) {
            wp_send_json_error(array('message' => $result['error']));
            return;
        }
        
        $stats = $this->database->get_stats();
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Sync complete. Added: %d, Removed: %d', 'auto-product-import'),
                $result['added'],
                $result['removed']
            ),
            'stats' => $stats,
            'added' => $result['added'],
            'removed' => $result['removed']
        ));
    }
    
    /**
     * Handle batch import next product
     */
    public function handle_batch_import() {
        check_ajax_referer('auto-product-import-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'auto-product-import')));
            return;
        }
        
        // Process next product
        $result = $this->processor->process_next();
        
        // Get updated stats
        $stats = $this->database->get_stats();
        $result['stats'] = $stats;
        
        if (isset($result['stopped']) && $result['stopped']) {
            wp_send_json_success($result);
            return;
        }
        
        if (isset($result['completed']) && $result['completed']) {
            wp_send_json_success($result);
            return;
        }
        
        if (!$result['success']) {
            // Error occurred but continue with next
            wp_send_json_success($result);
            return;
        }
        
        // Success
        wp_send_json_success($result);
    }
    
    /**
     * Handle stop import request
     */
    public function handle_stop_import() {
        check_ajax_referer('auto-product-import-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'auto-product-import')));
            return;
        }
        
        $this->processor->request_stop();
        
        wp_send_json_success(array(
            'message' => __('Stop requested. Batch import will stop after current product.', 'auto-product-import')
        ));
    }
    
    /**
     * Handle verify products request
     */
    public function handle_verify_products() {
        check_ajax_referer('auto-product-import-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'auto-product-import')));
            return;
        }
        
        $removed = $this->database->verify_imported_products();
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Verification complete. Removed: %d deleted products', 'auto-product-import'),
                $removed
            ),
            'removed' => $removed
        ));
    }
}
