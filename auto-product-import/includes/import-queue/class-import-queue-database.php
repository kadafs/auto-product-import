<?php
/**
 * Import Queue Database class
 *
 * Handles all database operations for the import queue
 *
 * @package Auto_Product_Import
 * @since 2.2.0
 */

if (!defined('WPINC')) {
    die;
}

class APM_Import_Queue_Database {
    
    /**
     * Table name
     */
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'api_import_queue';
    }
    
    /**
     * Create import queue table
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            domain varchar(255) NOT NULL,
            product varchar(500) NOT NULL,
            url text NOT NULL,
            product_id bigint(20) DEFAULT NULL,
            error tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_url (url(191)),
            KEY domain (domain),
            KEY product_id (product_id),
            KEY error (error)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('APM Import Queue: Table created/verified');
    }
    
    /**
     * Sync products from apb_products table
     * 
     * @return array Results of sync operation
     */
    public function sync_from_apb_products() {
        global $wpdb;
        
        $apb_table = $wpdb->prefix . 'apb_products';
        $added = 0;
        $removed = 0;
        
        // Check if apb_products table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$apb_table'");
        if (!$table_exists) {
            error_log('APM Import Queue: apb_products table not found');
            return array('added' => 0, 'removed' => 0, 'error' => 'apb_products table not found');
        }
        
        // Get all products from apb_products where process=1 and removed=0
        $apb_products = $wpdb->get_results("
            SELECT domain, product_name, product_url 
            FROM $apb_table 
            WHERE process = 1 AND removed = 0
        ");
        
        if ($apb_products) {
            foreach ($apb_products as $product) {
                // Check if URL already exists in queue
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE url = %s",
                    $product->product_url
                ));
                
                if (!$exists) {
                    // Add to queue
                    $result = $wpdb->insert(
                        $this->table_name,
                        array(
                            'domain' => $product->domain,
                            'product' => $product->product_name,
                            'url' => $product->product_url,
                            'product_id' => null,
                            'error' => 0
                        ),
                        array('%s', '%s', '%s', '%d', '%d')
                    );
                    
                    if ($result) {
                        $added++;
                    }
                }
            }
        }
        
        // Remove products from queue where removed=1 in apb_products
        $removed_products = $wpdb->get_results("
            SELECT product_url 
            FROM $apb_table 
            WHERE removed = 1
        ");
        
        if ($removed_products) {
            foreach ($removed_products as $product) {
                $result = $wpdb->delete(
                    $this->table_name,
                    array('url' => $product->product_url),
                    array('%s')
                );
                
                if ($result) {
                    $removed++;
                }
            }
        }
        
        // Remove products from queue where process=0 in apb_products
        $disabled_products = $wpdb->get_results("
            SELECT product_url 
            FROM $apb_table 
            WHERE process = 0
        ");
        
        if ($disabled_products) {
            foreach ($disabled_products as $product) {
                $result = $wpdb->delete(
                    $this->table_name,
                    array('url' => $product->product_url),
                    array('%s')
                );
                
                if ($result) {
                    $removed++;
                }
            }
        }
        
        error_log("APM Import Queue: Sync complete. Added: $added, Removed: $removed");
        
        return array('added' => $added, 'removed' => $removed);
    }
    
    /**
     * Get products for batch import (no product_id and error=0)
     * 
     * @return array Products ready for import
     */
    public function get_batch_import_products() {
        global $wpdb;
        
        $products = $wpdb->get_results("
            SELECT * FROM {$this->table_name} 
            WHERE product_id IS NULL AND error = 0 
            ORDER BY id ASC
        ");
        
        return $products ? $products : array();
    }
    
    /**
     * Get imported products (have product_id)
     * 
     * @return array Imported products
     */
    public function get_imported_products() {
        global $wpdb;
        
        $products = $wpdb->get_results("
            SELECT * FROM {$this->table_name} 
            WHERE product_id IS NOT NULL 
            ORDER BY id ASC
        ");
        
        return $products ? $products : array();
    }
    
    /**
     * Update product with product_id after successful import
     *
     * @param int $queue_id Queue item ID
     * @param int $product_id WordPress product ID
     * @return bool Success status
     */
    public function mark_as_imported($queue_id, $product_id) {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            array('product_id' => $product_id),
            array('id' => $queue_id),
            array('%d'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Mark a queue item as imported by URL
     * Used when a product is imported via the single import form so the
     * queue stays in sync and the batch processor won't re-import it.
     *
     * @param string $url        The source URL that was imported
     * @param int    $product_id The WordPress product ID that was created
     * @return bool True if a queue row was updated, false if URL wasn't in the queue
     */
    public function mark_as_imported_by_url($url, $product_id) {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            array('product_id' => $product_id),
            array('url' => $url),
            array('%d'),
            array('%s')
        );

        return $result !== false && $result > 0;
    }
    
    /**
     * Mark product as error
     * 
     * @param int $queue_id Queue item ID
     * @return bool Success status
     */
    public function mark_as_error($queue_id) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array('error' => 1),
            array('id' => $queue_id),
            array('%d'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Verify imported products still exist in WordPress
     * Removes queue entries for deleted products
     * 
     * @return int Number of products removed
     */
    public function verify_imported_products() {
        global $wpdb;
        
        $imported_products = $this->get_imported_products();
        $removed_count = 0;
        
        foreach ($imported_products as $product) {
            // Check if product still exists
            $post_status = get_post_status($product->product_id);
            
            if (!$post_status || $post_status === 'trash') {
                // Product doesn't exist or is trashed, remove from queue
                $result = $wpdb->delete(
                    $this->table_name,
                    array('id' => $product->id),
                    array('%d')
                );
                
                if ($result) {
                    $removed_count++;
                    error_log("APM Import Queue: Removed deleted product (ID: {$product->product_id}) from queue");
                }
            }
        }
        
        return $removed_count;
    }
    
    /**
     * Get single product from queue by ID
     * 
     * @param int $queue_id Queue item ID
     * @return object|null Queue item
     */
    public function get_product($queue_id) {
        global $wpdb;
        
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $queue_id
        ));
        
        return $product;
    }
    
    /**
     * Get statistics
     * 
     * @return array Statistics
     */
    public function get_stats() {
        global $wpdb;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE product_id IS NULL AND error = 0");
        $imported = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE product_id IS NOT NULL");
        $errors = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE error = 1");
        
        return array(
            'total' => (int)$total,
            'pending' => (int)$pending,
            'imported' => (int)$imported,
            'errors' => (int)$errors
        );
    }
}
