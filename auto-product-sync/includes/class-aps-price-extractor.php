<?php
/**
 * Price extraction functionality - WITH AUTO-RESTORE
 */

if (!defined('ABSPATH')) {
    exit;
}

class APS_Price_Extractor {
    
    private $logger;
    private $max_execution_time;
    private $start_time;
    
    public function __construct() {
        $this->logger = new APS_Logger();
        $this->max_execution_time = ini_get('max_execution_time') ? (int)ini_get('max_execution_time') - 15 : 45;
        $this->start_time = time();
        
        add_action('aps_bulk_sync_process', array($this, 'bulk_sync_process'));
    }
    
    /**
     * Check if we're approaching execution time limit
     */
    private function is_timeout_approaching() {
        return (time() - $this->start_time) > $this->max_execution_time;
    }
    
    /**
     * Sync single product price - ERROR COUNTER SYSTEM
     */
    public function sync_product_price($product_id, $is_retry = false, $retry_count = 0) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return array('success' => false, 'message' => __('Product not found', 'auto-product-sync'));
        }
        
        $enable_sync = APS_Core::get_product_meta($product_id, '_aps_enable_sync');
        if ($enable_sync !== 'yes') {
            return array('success' => false, 'message' => __('Sync not enabled for this product', 'auto-product-sync'));
        }
        
        $url = APS_Core::get_product_meta($product_id, '_aps_url');
        
        // SSRF PROTECTION - Validate URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return array('success' => false, 'message' => __('Invalid or missing URL', 'auto-product-sync'));
        }
        
        // Parse URL and check for internal network access
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return array('success' => false, 'message' => __('Invalid URL format', 'auto-product-sync'));
        }
        
        // Block localhost and internal IPs
        $blocked_hosts = array('localhost', '127.0.0.1', '0.0.0.0', '::1');
        if (in_array(strtolower($parsed['host']), $blocked_hosts)) {
            $this->logger->log("Blocked attempt to access local resource: $url", 'error');
            return array('success' => false, 'message' => __('Access to local resources is denied', 'auto-product-sync'));
        }
        
        // Block private IP ranges
        $ip = gethostbyname($parsed['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $this->logger->log("Blocked attempt to access private IP: $url resolves to $ip", 'error');
            return array('success' => false, 'message' => __('Access to private networks is denied', 'auto-product-sync'));
        }
        
        $this->logger->log("Starting price extraction for product ID: $product_id, URL: $url", 'info');

        // Check if product was previously hidden due to errors
        $was_hidden = ($product->get_catalog_visibility() === 'hidden');
        $had_error = !empty(APS_Core::get_product_meta($product_id, '_aps_error_date'));

        // Get the WooCommerce SKU — needed for SKU-matched scrapers (e.g. eastwesteng)
        $sku = $product->get_sku();

        // Extract prices from URL
        $extraction_result = $this->extract_prices_from_url($url, $sku);
        
        if (!$extraction_result['success']) {
            $this->handle_extraction_error($product_id, $extraction_result['message']);
            return array('success' => false, 'message' => $extraction_result['message']);
        }
        
        // SUCCESS - Reset error counter and restore if needed
        $error_count = APS_Core::get_product_meta($product_id, '_aps_retry_count') ?: 0;
        APS_Core::update_product_meta($product_id, '_aps_retry_count', 0);
        
        // Determine status message
        if ($was_hidden && $had_error) {
            APS_Core::update_product_meta($product_id, '_aps_last_status', 'Success: Restored & Prices updated');
        } else {
            APS_Core::update_product_meta($product_id, '_aps_last_status', 'Success: Prices updated');
        }
        
        APS_Core::update_product_meta($product_id, '_aps_last_sync_timestamp', time());
        
        // Restore product visibility if it was hidden
        if ($was_hidden) {
            $product->set_catalog_visibility('visible');
            $product->save();
            $this->logger->log("Restored product visibility for product ID: $product_id", 'success');
            
            // Send restoration email
            $this->send_restoration_notification($product_id);
        }
        
        // Update product with extracted prices
        $this->update_product_prices($product_id, $extraction_result['prices']);
        
        $this->logger->log("Successfully updated prices for product ID: $product_id", 'success');
        APS_Core::log_sync_activity($product_id, 'success', 'Prices updated successfully');
        
        return array('success' => true, 'message' => __('Prices updated successfully', 'auto-product-sync'));
    }
    
    /**
     * Extract prices from URL
     *
     * @param string $url  The URL to fetch and parse.
     * @param string $sku  WooCommerce product SKU — used by SKU-matched scrapers such as eastwesteng.
     * @return array       Array with 'success' (bool), and either 'prices' or 'message'.
     */
    private function extract_prices_from_url($url, $sku = '') {
        $this->logger->log("Fetching content from: $url", 'debug');

        $content = $this->fetch_url_content($url);

        if (!$content) {
            return array('success' => false, 'message' => __('Failed to fetch content from URL', 'auto-product-sync'));
        }

        $this->logger->log("Content fetched successfully, length: " . strlen($content), 'debug');

        // Route eastwesteng.com.au URLs through the dedicated extractor
        if (APS_EastWestEng_Extractor::is_eastwesteng_url($url)) {
            return $this->extract_eastwesteng_prices($content, $sku);
        }

        $prices = $this->parse_prices_from_content($content);

        if (empty($prices['regular_price']) || $prices['regular_price'] <= 0) {
            return array('success' => false, 'message' => __('No valid regular price found', 'auto-product-sync'));
        }

        return array('success' => true, 'prices' => $prices);
    }

    /**
     * Extract price from an eastwesteng.com.au product page.
     *
     * Parses the HTML into a DOM, then delegates to APS_EastWestEng_Extractor
     * to locate the row whose Model matches the product SKU. The returned price
     * is the raw ex-GST value from data-base-price; the "Add GST" and
     * "Add Margin" product checkboxes are applied afterwards by update_product_prices().
     *
     * @param string $content  Raw HTML content of the page.
     * @param string $sku      WooCommerce product SKU to match.
     * @return array
     */
    private function extract_eastwesteng_prices($content, $sku) {
        if (empty($sku)) {
            $message = __('Product has no SKU set — a WooCommerce SKU matching the East West Eng model code is required', 'auto-product-sync');
            $this->logger->log("EastWestEng: $message", 'error');
            return array('success' => false, 'message' => $message);
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $encoded = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');
        $dom->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath     = new DOMXPath($dom);
        $extractor = new APS_EastWestEng_Extractor();
        $price     = $extractor->extract_price_for_sku($xpath, $sku);

        if ($price === false || $price <= 0) {
            $message = sprintf(
                /* translators: %s: product SKU */
                __('No price found for SKU "%s" in the East West Eng pricing table', 'auto-product-sync'),
                $sku
            );
            $this->logger->log("EastWestEng: $message", 'error');
            return array('success' => false, 'message' => $message);
        }

        $this->logger->log("EastWestEng: found ex-GST price $price for SKU '$sku'", 'debug');

        return array(
            'success' => true,
            'prices'  => array(
                'regular_price' => $price,
                'sale_price'    => 0,
            ),
        );
    }
    
    /**
     * Fetch URL content - SSL SECURITY RESTORED
     */
    private function fetch_url_content($url) {
        $timeout = absint(get_option('aps_fetch_timeout', 30));
        $timeout = max(5, min(60, $timeout));
        
        $args = array(
            'timeout' => $timeout,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'sslverify' => true,
            'redirection' => 5,
            'httpversion' => '1.1',
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log("wp_remote_get error: " . $response->get_error_message(), 'debug');
            
            if (strpos($response->get_error_message(), 'SSL') !== false) {
                $this->logger->log("SSL verification failed for $url - This is a security feature, not a bug", 'error');
            }
            
            return false;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            $this->logger->log("HTTP error: $http_code", 'debug');
            return false;
        }
        
        $content = wp_remote_retrieve_body($response);
        return !empty($content) ? $content : false;
    }
    
    /**
     * Parse prices from HTML content - XSS PROTECTION ADDED
     */
    private function parse_prices_from_content($content) {
        $prices = array(
            'regular_price' => 0,
            'sale_price' => 0
        );
        
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        
        $content = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');
        $dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $errors = libxml_get_errors();
        if ($errors) {
            $this->logger->log("HTML parsing warnings: " . count($errors) . " issues", 'debug');
        }
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        $price_selectors = array(
            'price',
            'product-price',
            'regular-price',
            'current-price',
            'price-current',
            'woocommerce-price-amount',
            'amount',
            'wc-price',
            'product-price-value',
            'price-box'
        );
        
        foreach ($price_selectors as $selector) {
            $safe_selector = str_replace("'", "\\'", $selector);
            $query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$safe_selector} ')]";
            
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                foreach ($nodes as $node) {
                    $price_text = $node->textContent;
                    $price = $this->extract_price_from_text($price_text);
                    if ($price > 0) {
                        $prices['regular_price'] = $price;
                        $this->logger->log("Found regular price using selector '$selector': $price", 'debug');
                        break 2;
                    }
                }
            }
        }
        
        if ($prices['regular_price'] == 0) {
            $prices['regular_price'] = $this->extract_price_with_regex($content);
            if ($prices['regular_price'] > 0) {
                $this->logger->log("Found regular price using regex: " . $prices['regular_price'], 'debug');
            }
        }
        
        if ($prices['regular_price'] == 0) {
            $gentronics_prices = $this->extract_gentronics_css_prices($content, $xpath);
            if (!empty($gentronics_prices) && $gentronics_prices['regular_price'] > 0) {
                $prices['regular_price'] = $gentronics_prices['regular_price'];
                $prices['sale_price'] = $gentronics_prices['sale_price'];
                $this->logger->log("Found prices using Gentronics: Regular {$prices['regular_price']}, Sale {$prices['sale_price']}", 'debug');
            }
        }
        
        if ($prices['regular_price'] == 0) {
            $per_item_prices = $this->extract_per_item_prices($content);
            if (!empty($per_item_prices) && $per_item_prices['regular_price'] > 0) {
                $prices['regular_price'] = $per_item_prices['regular_price'];
                $prices['sale_price'] = $per_item_prices['sale_price'];
                $this->logger->log("Found prices using per-item: Regular {$prices['regular_price']}, Sale {$prices['sale_price']}", 'debug');
            }
        }
        
        return $prices;
    }
    
    /**
     * Extract prices using Gentronics-specific CSS selector
     */
    private function extract_gentronics_css_prices($content, $xpath) {
        $found_prices = array();
        
        $primary_query = "//p[contains(@class, 'gentronics-price') and contains(@class, 'price')]";
        $nodes = $xpath->query($primary_query);
        
        if ($nodes && $nodes->length > 0) {
            foreach ($nodes as $node) {
                $price_text = trim($node->textContent);
                $price = $this->extract_gentronics_price_from_text($price_text);
                if ($price > 0 && $price < 1000000) {
                    $found_prices[] = $price;
                }
            }
        }
        
        rsort($found_prices);
        
        $result = array('regular_price' => 0, 'sale_price' => 0);
        
        if (count($found_prices) >= 2) {
            $result['regular_price'] = $found_prices[0];
            $result['sale_price'] = $found_prices[1];
        } elseif (count($found_prices) == 1) {
            $result['regular_price'] = $found_prices[0];
        }
        
        return $result;
    }
    
    /**
     * Extract price from Gentronics-specific text format
     */
    private function extract_gentronics_price_from_text($text) {
        $text = trim($text);
        
        $patterns = array(
            '/\$(\d+(?:\.\d+)?)per\s*item/i',
            '/\$(\d+(?:\.\d+)?)\s*per\s*item/i',
            '/\$(\d+(?:,\d{3})*(?:\.\d+)?)/i',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $price = str_replace(',', '', $matches[1]);
                $price = floatval($price);
                if ($price > 0 && $price < 1000000) {
                    return $price;
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Extract prices using "per item" pattern
     */
    private function extract_per_item_prices($content) {
        $found_prices = array();
        
        $patterns = array(
            '/\$(\d{1,4}(?:,\d{3})*(?:\.\d{2})?)(?:\s*)?per\s*item/i',
            '/\$(\d{1,4}(?:,\d{3})*(?:\.\d{2})?)(?:\s*)?per\s*unit/i',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $price = str_replace(',', '', $match);
                    $price = floatval($price);
                    if ($price > 0 && $price < 1000000) {
                        $found_prices[] = $price;
                    }
                }
            }
        }
        
        $found_prices = array_unique($found_prices);
        rsort($found_prices);
        
        $result = array('regular_price' => 0, 'sale_price' => 0);
        
        if (count($found_prices) >= 2) {
            $result['regular_price'] = $found_prices[0];
            $result['sale_price'] = $found_prices[1];
        } elseif (count($found_prices) == 1) {
            $result['regular_price'] = $found_prices[0];
        }
        
        return $result;
    }
    
    /**
     * Extract price from text using regex
     */
    private function extract_price_from_text($text) {
        $text = trim($text);
        
        $patterns = array(
            '/\$(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i',
            '/(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $price = str_replace(',', '', $matches[1]);
                $price = floatval($price);
                if ($price > 0 && $price < 1000000) {
                    return $price;
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Extract price using regex on entire content
     */
    private function extract_price_with_regex($content) {
        $patterns = array(
            '/\$(\d{1,3}(?:,\d{3})*\.\d{2})/i',
            '/"price"[^>]*>.*?\$?(\d{1,3}(?:,\d{3})*\.\d{2})/i',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $price = str_replace(',', '', $match);
                    $price = floatval($price);
                    if ($price > 0 && $price < 1000000) {
                        return $price;
                    }
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Update product prices (HPOS compatible)
     */
    private function update_product_prices($product_id, $prices) {
        $add_gst = APS_Core::get_product_meta($product_id, '_aps_add_gst') === 'yes';
        $add_margin = APS_Core::get_product_meta($product_id, '_aps_add_margin') === 'yes';
        $margin_percentage = floatval(APS_Core::get_product_meta($product_id, '_aps_margin_percentage'));

        // Ensure margin percentage has a default value and minimum of 1%
        if (empty($margin_percentage) || $margin_percentage < 1) {
            $margin_percentage = 10; // Default 10%
        }

        $old_regular = APS_Core::get_product_meta($product_id, '_aps_external_regular_price_inc_gst');
        $old_sale = APS_Core::get_product_meta($product_id, '_aps_external_sale_price_inc_gst');

        APS_Core::update_product_meta($product_id, '_aps_external_regular_price', $prices['regular_price']);
        APS_Core::update_product_meta($product_id, '_aps_external_sale_price', $prices['sale_price']);

        // Step 1: Apply GST if enabled (base price * 1.1)
        $regular_price_inc_gst = $add_gst ? round($prices['regular_price'] * 1.1, 2) : $prices['regular_price'];
        $sale_price_inc_gst = $add_gst ? round($prices['sale_price'] * 1.1, 2) : $prices['sale_price'];

        // Step 2: Apply Margin if enabled (price after GST * (1 + margin/100))
        if ($add_margin) {
            $margin_multiplier = 1 + ($margin_percentage / 100);
            $regular_price_inc_gst = round($regular_price_inc_gst * $margin_multiplier, 2);
            if ($sale_price_inc_gst > 0) {
                $sale_price_inc_gst = round($sale_price_inc_gst * $margin_multiplier, 2);
            }
        }

        APS_Core::update_product_meta($product_id, '_aps_external_regular_price_inc_gst', $regular_price_inc_gst);
        APS_Core::update_product_meta($product_id, '_aps_external_sale_price_inc_gst', $sale_price_inc_gst);
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $product->set_regular_price($regular_price_inc_gst);
        
        if ($sale_price_inc_gst > 0) {
            $product->set_sale_price($sale_price_inc_gst);
            $product->set_price($sale_price_inc_gst);
        } else {
            $product->set_sale_price('');
            $product->set_price($regular_price_inc_gst);
        }
        
        $product->save();
        wc_delete_product_transients($product_id);
        
        APS_Core::log_sync_activity(
            $product_id, 
            'success', 
            'Prices updated', 
            $old_regular, 
            $regular_price_inc_gst, 
            $old_sale, 
            $sale_price_inc_gst
        );
        
        $this->logger->log("Updated product $product_id: Regular: $regular_price_inc_gst, Sale: $sale_price_inc_gst", 'info');
    }
    
    /**
     * Handle extraction error - ERROR COUNTER SYSTEM
     */
    private function handle_extraction_error($product_id, $message) {
        $max_errors = absint(get_option('aps_max_errors', 1));
        $max_errors = max(1, min(99, $max_errors));
        
        // Get current error count
        $error_count = absint(APS_Core::get_product_meta($product_id, '_aps_retry_count') ?: 0);
        
        // Increment error count
        $error_count++;
        APS_Core::update_product_meta($product_id, '_aps_retry_count', $error_count);
        
        // Update status and timestamp
        APS_Core::update_product_meta($product_id, '_aps_last_status', 'Error: ' . $message);
        APS_Core::update_product_meta($product_id, '_aps_last_sync_timestamp', time());
        APS_Core::log_sync_activity($product_id, 'error', $message);
        
        $this->logger->log("Error extracting prices for product $product_id: $message (Error count: $error_count/$max_errors)", 'error');
        
        // Check if error count exceeds threshold
        if ($error_count >= $max_errors) {
            // Hide product
            APS_Core::update_product_meta($product_id, '_aps_error_date', current_time('mysql'));
            
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_catalog_visibility('hidden');
                $product->save();
            }
            
            APS_Core::update_product_meta($product_id, '_aps_last_status', 'Failed: Max errors reached - Product hidden');
            
            $this->logger->log("Product $product_id hidden after $error_count errors", 'error');
            
            // Send combined failure + hidden notification
            $this->send_error_notification($product_id, $message, $error_count, $max_errors, true);
        } else {
            // Send regular failure notification
            $this->send_error_notification($product_id, $message, $error_count, $max_errors, false);
        }
    }
    
    /**
     * Bulk sync process - TIMEOUT PROTECTION & MEMORY MANAGEMENT
     */
    public function bulk_sync_process() {
        $this->start_time = time();
        
        $batch_size = absint(get_option('aps_batch_size', 10));
        $batch_size = max(1, min(25, $batch_size));
        
        $current_batch = get_transient('aps_current_batch');
        if ($current_batch === false) {
            $current_batch = 0;
        }
        
        $all_products_list = get_transient('aps_all_products_list');
        if ($all_products_list === false || $current_batch === 0) {
            $products_data = APS_Core::get_products_for_bulk_sync(1000, 1);
            
            if (empty($products_data['products'])) {
                set_transient('aps_bulk_sync_status', array(
                    'running' => false,
                    'completed' => 0,
                    'total' => 0,
                    'failed' => 0,
                    'finished' => true,
                    'current_product' => ''
                ), 3600);
                APS_Core::release_sync_lock();
                return;
            }
            
            $all_products_list = $products_data['products'];
            $total_products = $products_data['total'];
            
            $products_no_timestamp = array();
            $products_with_timestamp = array();
            
            foreach ($all_products_list as $product) {
                $timestamp = APS_Core::get_product_meta($product->ID, '_aps_last_sync_timestamp');
                if (empty($timestamp)) {
                    $products_no_timestamp[] = $product;
                } else {
                    $products_with_timestamp[] = array(
                        'product' => $product,
                        'timestamp' => intval($timestamp)
                    );
                }
            }
            
            usort($products_with_timestamp, function($a, $b) {
                return $a['timestamp'] - $b['timestamp'];
            });
            
            $products_with_timestamp = array_map(function($item) {
                return $item['product'];
            }, $products_with_timestamp);
            
            $all_products_list = array_merge($products_no_timestamp, $products_with_timestamp);
            
            $this->logger->log("Sorted products: " . count($products_no_timestamp) . " without timestamp, " . count($products_with_timestamp) . " with timestamp (oldest to newest)", 'info');
            
            set_transient('aps_all_products_list', $all_products_list, 3600);
            set_transient('aps_total_products_count', $total_products, 3600);
        } else {
            $total_products = get_transient('aps_total_products_count');
        }
        
        $start_index = $current_batch * $batch_size;
        $batch_products = array_slice($all_products_list, $start_index, $batch_size);
        
        if (empty($batch_products)) {
            delete_transient('aps_current_batch');
            delete_transient('aps_all_products_list');
            delete_transient('aps_total_products_count');
            
            $status = get_transient('aps_bulk_sync_status');
            $completed = isset($status['completed']) ? absint($status['completed']) : 0;
            $failed = isset($status['failed']) ? absint($status['failed']) : 0;
            
            set_transient('aps_bulk_sync_status', array(
                'running' => false,
                'completed' => $completed,
                'total' => $total_products,
                'failed' => $failed,
                'finished' => true,
                'current_product' => '',
                'needs_next_batch' => false
            ), 3600);
            
            APS_Core::release_sync_lock();
            $this->logger->log("Bulk sync completed. No more products in queue.", 'info');
            return;
        }
        
        $status = get_transient('aps_bulk_sync_status');
        $completed = isset($status['completed']) ? absint($status['completed']) : 0;
        $failed = isset($status['failed']) ? absint($status['failed']) : 0;
        
        set_transient('aps_bulk_sync_status', array(
            'running' => true,
            'completed' => $completed,
            'total' => $total_products,
            'failed' => $failed,
            'current_product' => sprintf(__('Processing batch %d...', 'auto-product-sync'), $current_batch + 1)
        ), 3600);
        
        $this->logger->log("Starting batch " . ($current_batch + 1) . " - Processing " . count($batch_products) . " products (indices " . $start_index . " to " . ($start_index + count($batch_products) - 1) . " of " . $total_products . " total)", 'info');
        
        foreach ($batch_products as $product) {
            if ($this->is_timeout_approaching()) {
                $this->logger->log("Approaching timeout, stopping batch early", 'warning');
                break;
            }
            
            set_transient('aps_bulk_sync_status', array(
                'running' => true,
                'completed' => $completed,
                'total' => $total_products,
                'failed' => $failed,
                'current_product' => $product->post_title
            ), 3600);
            
            // Process product
            $result = $this->sync_product_price($product->ID);
            
            if ($result['success']) {
                $completed++;
            } else {
                $failed++;
            }
            
            set_transient('aps_bulk_sync_status', array(
                'running' => true,
                'completed' => $completed,
                'total' => $total_products,
                'failed' => $failed,
                'current_product' => $product->post_title
            ), 3600);
            
            sleep(2);
        }
        
        $this->logger->log("Batch " . ($current_batch + 1) . " completed. Progress: $completed successful, $failed failed out of $total_products total", 'info');
        
        if (get_transient('aps_abort_sync')) {
            delete_transient('aps_current_batch');
            delete_transient('aps_all_products_list');
            delete_transient('aps_total_products_count');
            delete_transient('aps_abort_sync');
            
            set_transient('aps_bulk_sync_status', array(
                'running' => false,
                'completed' => $completed,
                'total' => $total_products,
                'failed' => $failed,
                'finished' => true,
                'aborted' => true,
                'current_product' => '',
                'needs_next_batch' => false
            ), 3600);
            
            APS_Core::release_sync_lock();
            $this->logger->log("Bulk sync aborted by user. Progress: $completed/$total_products completed", 'info');
            return;
        }
        
        $next_start = ($current_batch + 1) * $batch_size;
        $has_more_batches = $next_start < $total_products;
        
        if ($has_more_batches) {
            $current_batch++;
            set_transient('aps_current_batch', $current_batch, 3600);
            
            set_transient('aps_bulk_sync_status', array(
                'running' => true,
                'completed' => $completed,
                'total' => $total_products,
                'failed' => $failed,
                'current_product' => __('Preparing next batch...', 'auto-product-sync'),
                'needs_next_batch' => true
            ), 3600);
            
            $this->logger->log("Batch " . $current_batch . " ready. Next batch will start at index $next_start. Progress: $completed/$total_products", 'info');
        } else {
            delete_transient('aps_current_batch');
            delete_transient('aps_all_products_list');
            delete_transient('aps_total_products_count');
            
            set_transient('aps_bulk_sync_status', array(
                'running' => false,
                'completed' => $completed,
                'total' => $total_products,
                'failed' => $failed,
                'finished' => true,
                'current_product' => '',
                'needs_next_batch' => false
            ), 3600);
            
            APS_Core::release_sync_lock();
            
            $this->logger->log("Bulk sync completed. Total: $total_products, Successful: $completed, Failed: $failed", 'info');
        }
    }
    
    /**
     * Send admin notification for product failure
     */
    private function send_error_notification($product_id, $error_message, $error_count, $max_errors, $is_hidden) {
        $admin_email = get_option('aps_admin_email', get_option('admin_email'));
        
        if (!is_email($admin_email)) {
            return;
        }
        
        $product = get_post($product_id);
        $product_name = $product ? $product->post_title : "Product #$product_id";
        
        if ($is_hidden) {
            $subject = sprintf('[%s] %s', get_bloginfo('name'), __('Product Hidden - Auto Product Sync', 'auto-product-sync'));
            
            $message = __("Hello,\n\n", 'auto-product-sync');
            $message .= __("A product has exceeded the maximum error count and has been hidden from your catalog.\n\n", 'auto-product-sync');
            $message .= sprintf(__("Product: %s\n", 'auto-product-sync'), $product_name);
            $message .= sprintf(__("Product ID: %d\n", 'auto-product-sync'), $product_id);
            $message .= sprintf(__("Error Count: %d/%d\n", 'auto-product-sync'), $error_count, $max_errors);
            $message .= sprintf(__("Error: %s\n\n", 'auto-product-sync'), $error_message);
            $message .= __("The product has been hidden from your catalog. It will be automatically restored when the next sync succeeds.\n\n", 'auto-product-sync');
            $message .= sprintf(__("Edit product: %s\n\n", 'auto-product-sync'), admin_url("post.php?post=$product_id&action=edit"));
            $message .= __("Best regards,\nAuto Product Sync Plugin", 'auto-product-sync');
        } else {
            $subject = sprintf('[%s] %s', get_bloginfo('name'), __('Product Sync Failed - Auto Product Sync', 'auto-product-sync'));
            
            $message = __("Hello,\n\n", 'auto-product-sync');
            $message .= __("A product sync attempt has failed.\n\n", 'auto-product-sync');
            $message .= sprintf(__("Product: %s\n", 'auto-product-sync'), $product_name);
            $message .= sprintf(__("Product ID: %d\n", 'auto-product-sync'), $product_id);
            $message .= sprintf(__("Error Count: %d/%d\n", 'auto-product-sync'), $error_count, $max_errors);
            $message .= sprintf(__("Error: %s\n\n", 'auto-product-sync'), $error_message);
            $message .= sprintf(__("The product will be hidden if it reaches %d errors.\n\n", 'auto-product-sync'), $max_errors);
            $message .= sprintf(__("Edit product: %s\n\n", 'auto-product-sync'), admin_url("post.php?post=$product_id&action=edit"));
            $message .= __("Best regards,\nAuto Product Sync Plugin", 'auto-product-sync');
        }
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Send admin notification when product is restored
     */
    private function send_restoration_notification($product_id) {
        $admin_email = get_option('aps_admin_email', get_option('admin_email'));
        
        if (!is_email($admin_email)) {
            return;
        }
        
        $product = get_post($product_id);
        $product_name = $product ? $product->post_title : "Product #$product_id";
        
        $subject = sprintf('[%s] %s', get_bloginfo('name'), __('Product Restored - Auto Product Sync', 'auto-product-sync'));
        
        $message = __("Hello,\n\n", 'auto-product-sync');
        $message .= __("Good news! A product that was previously hidden due to sync errors has been successfully synced and restored to your catalog.\n\n", 'auto-product-sync');
        $message .= sprintf(__("Product: %s\n", 'auto-product-sync'), $product_name);
        $message .= sprintf(__("Product ID: %d\n\n", 'auto-product-sync'), $product_id);
        $message .= __("The product has been successfully synced and restored to your catalog after previous failures.\n\n", 'auto-product-sync');
        $message .= sprintf(__("View product: %s\n\n", 'auto-product-sync'), admin_url("post.php?post=$product_id&action=edit"));
        $message .= __("Best regards,\nAuto Product Sync Plugin", 'auto-product-sync');
        
        wp_mail($admin_email, $subject, $message);
    }
}
