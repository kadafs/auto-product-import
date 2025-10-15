<?php
/**
 * Product Creator Sync Fields class
 * Handles Auto Product Sync field setup and GST detection
 *
 * @package Auto_Product_Import
 * @since 2.1.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_Product_Creator_Sync_Fields {
    
    /**
     * Check if detailed logging should be enabled for sync fields
     *
     * @param string $url The URL being processed
     * @return bool True if detailed logging should be enabled
     */
    private function should_log_detailed($url) {
        // Get the log_sync setting
        $log_sync = get_option('auto_product_import_log_sync', 'no');
        
        // If checkbox is unchecked, never show detailed logs
        if ($log_sync !== 'yes') {
            return false;
        }
        
        // Get the debug domain setting
        $debug_domain = get_option('auto_product_import_debug_domain', '');
        
        // If debug domain is empty, show detailed logs for ALL domains
        if (empty($debug_domain)) {
            return true;
        }
        
        // Check if URL matches the debug domain
        $url_domain = parse_url($url, PHP_URL_HOST);
        if ($url_domain && strpos($url_domain, $debug_domain) !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect GST requirement and apply to product price
     *
     * @param array $product_data Product data array
     * @param WC_Product $product Product object
     * @param bool $debug Debug mode flag
     * @return array Array with 'add_gst' key
     */
    public function detect_and_apply_gst($product_data, $product, $debug = false) {
        $add_gst = 'no'; // Default to no
        
        if (!empty($product_data['html_content'])) {
            $html_lower = strtolower($product_data['html_content']);
            
            // Check for "excl gst", "excluding gst", "ex gst", etc.
            $gst_patterns = array(
                'excl gst',
                'excl. gst',
                'excluding gst',
                'ex gst',
                'ex. gst',
                'price excl',
                'price excluding',
            );
            
            foreach ($gst_patterns as $pattern) {
                if (strpos($html_lower, $pattern) !== false) {
                    $add_gst = 'yes';
                    break;
                }
            }
        }
        
        // Set price - add 10% GST if required
        if (!empty($product_data['price'])) {
            $original_price = floatval($product_data['price']);
            
            if ($add_gst === 'yes') {
                // Add 10% GST to the price
                $price_with_gst = $original_price * 1.10;
                $product->set_regular_price($price_with_gst);
                
                if ($debug) {
                    error_log("APM: Original price (excl GST): $" . number_format($original_price, 2));
                    error_log("APM: Price with 10% GST added: $" . number_format($price_with_gst, 2));
                }
                
                // BASIC LOGGING - Always show GST calculation
                error_log("APM: Added 10% GST to price: $" . number_format($original_price, 2) . " → $" . number_format($price_with_gst, 2));
            } else {
                // Use price as-is (already includes GST or GST not applicable)
                $product->set_regular_price($original_price);
                
                if ($debug) {
                    error_log("APM: Setting price (no GST added): $" . number_format($original_price, 2));
                }
            }
        }
        
        return array('add_gst' => $add_gst);
    }
    
    /**
     * Set Auto Product Sync fields on product
     *
     * @param int $product_id Product ID
     * @param array $product_data Product data array
     * @param string $add_gst Whether to add GST ('yes' or 'no')
     * @param bool $debug Debug mode flag
     */
    public function set_sync_fields($product_id, $product_data, $add_gst, $debug = false) {
        // Get source URL
        $source_url = isset($product_data['source_url']) ? $product_data['source_url'] : '';
        $detailed_log = $this->should_log_detailed($source_url);
        
        // BASIC LOGGING - Always show
        error_log("APM: Setting Auto Product Sync fields for product ID: $product_id");
        
        if ($detailed_log) {
            error_log("APM: ========== SYNC FIELD SETUP START (DETAILED) ==========");
        }
        
        // Set Auto Product Sync URL (correct meta key: _aps_url)
        if (!empty($source_url)) {
            update_post_meta($product_id, '_aps_url', esc_url_raw($source_url));
            
            if ($detailed_log) {
                error_log("APM: ✓ Set _aps_url: $source_url");
            }
        }
        
        // Set Enable Sync to disabled (off/unchecked)
        update_post_meta($product_id, '_aps_enable_sync', 'no');
        
        if ($detailed_log) {
            error_log("APM: ✓ Set _aps_enable_sync: no (disabled)");
        }
        
        // Set Add GST field
        update_post_meta($product_id, '_aps_add_gst', $add_gst);
        
        if ($detailed_log) {
            error_log("APM: ✓ Set _aps_add_gst: $add_gst");
            error_log("APM: ========== SYNC FIELD SETUP COMPLETE (DETAILED) ==========");
        }
        
        // BASIC LOGGING - Always show
        error_log("APM: Auto Product Sync fields set - URL saved, Sync disabled, Add GST: $add_gst");
    }
}
