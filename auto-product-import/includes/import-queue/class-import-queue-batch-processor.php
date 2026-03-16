<?php
/**
 * Import Queue Batch Processor class
 *
 * Handles batch import processing
 *
 * @package Auto_Product_Import
 * @since 2.2.0
 */

if (!defined('WPINC')) {
    die;
}

class APM_Import_Queue_Batch_Processor {
    
    private $database;
    private $is_stopped = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new APM_Import_Queue_Database();
    }
    
    /**
     * Process next product in queue
     * 
     * @return array Result of processing
     */
    public function process_next() {
        // Check if stop was requested
        if (get_transient('apm_import_queue_stop')) {
            delete_transient('apm_import_queue_stop');
            return array(
                'success' => false,
                'stopped' => true,
                'message' => __('Batch import stopped by user', 'auto-product-import')
            );
        }
        
        // Get next product from queue
        $products = $this->database->get_batch_import_products();
        
        if (empty($products)) {
            return array(
                'success' => true,
                'completed' => true,
                'message' => __('All products imported successfully!', 'auto-product-import')
            );
        }
        
        $product = $products[0]; // Get first product
        
        // Import the product
        $result = $this->import_product($product);
        
        return $result;
    }
    
    /**
     * Import a single product
     * 
     * @param object $queue_item Queue item from database
     * @return array Result of import
     */
    private function import_product($queue_item) {
        // Prevent concurrent imports of the same URL (e.g. batch processor + single import form)
        $lock_key = 'apm_import_lock_' . md5($queue_item->url);
        if (get_transient($lock_key)) {
            error_log("APM Import Queue: Skipping {$queue_item->url} - already being imported by another process");
            return array(
                'success' => false,
                'skipped' => true,
                'queue_id' => $queue_item->id,
                'message' => 'URL is already being imported by another process'
            );
        }
        set_transient($lock_key, 1, 300); // Lock for up to 5 minutes

        try {
            error_log("APM Import Queue: Starting import for: {$queue_item->url}");

            // Use the existing product scraper and creator
            $scraper = new APM_Product_Scraper();
            $product_data = $scraper->fetch($queue_item->url);
            
            if (is_wp_error($product_data)) {
                $this->database->mark_as_error($queue_item->id);
                delete_transient($lock_key);
                error_log("APM Import Queue: Scraping failed for {$queue_item->url} - " . $product_data->get_error_message());
                return array(
                    'success' => false,
                    'error' => true,
                    'queue_id' => $queue_item->id,
                    'message' => $product_data->get_error_message()
                );
            }

            $creator = new APM_Product_Creator();
            $product_id = $creator->create($product_data);

            if (is_wp_error($product_id)) {
                $this->database->mark_as_error($queue_item->id);
                delete_transient($lock_key);
                error_log("APM Import Queue: Product creation failed for {$queue_item->url} - " . $product_id->get_error_message());
                return array(
                    'success' => false,
                    'error' => true,
                    'queue_id' => $queue_item->id,
                    'message' => $product_id->get_error_message()
                );
            }

            // Mark as imported and release lock
            $this->database->mark_as_imported($queue_item->id, $product_id);
            delete_transient($lock_key);

            error_log("APM Import Queue: Successfully imported product {$product_id} from {$queue_item->url}");

            return array(
                'success' => true,
                'queue_id' => $queue_item->id,
                'product_id' => $product_id,
                'product_name' => $queue_item->product,
                'edit_link' => admin_url('post.php?post=' . $product_id . '&action=edit'),
                'view_link' => get_permalink($product_id),
                'message' => sprintf(__('Product "%s" imported successfully', 'auto-product-import'), $queue_item->product)
            );

        } catch (Exception $e) {
            $this->database->mark_as_error($queue_item->id);
            delete_transient($lock_key);
            error_log("APM Import Queue: Exception during import - " . $e->getMessage());
            return array(
                'success' => false,
                'error' => true,
                'queue_id' => $queue_item->id,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Request stop of batch import
     */
    public function request_stop() {
        set_transient('apm_import_queue_stop', true, 300); // 5 minutes
    }
    
    /**
     * Clear stop request
     */
    public function clear_stop_request() {
        delete_transient('apm_import_queue_stop');
    }
}
