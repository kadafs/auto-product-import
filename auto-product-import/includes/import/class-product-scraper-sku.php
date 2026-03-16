<?php
/**
 * Product Scraper SKU Extractor class
 * Handles site-specific SKU extraction logic
 *
 * @package Auto_Product_Import
 * @since 2.1.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_Product_Scraper_SKU {
    
    /**
     * Check if detailed logging should be enabled for SKU extraction
     *
     * @param string $url The URL being processed
     * @return bool True if detailed logging should be enabled
     */
    private function should_log_detailed($url) {
        // Get the log_sku setting
        $log_sku = get_option('auto_product_import_log_sku', 'no');
        
        // If checkbox is unchecked, never show detailed logs
        if ($log_sku !== 'yes') {
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
     * Extract product SKU
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param string $html_content The raw HTML content
     * @return string The extracted SKU or empty string if not found
     */
    public function extract($xpath, $url, $html_content) {
        $sku = '';
        $detailed_log = $this->should_log_detailed($url);
        
        // BASIC LOGGING - Always show
        error_log("APM: Starting SKU extraction from URL: $url");
        
        if ($detailed_log) {
            error_log("APM: ========== SKU EXTRACTION START (DETAILED) ==========");
        }
        
        // Determine which site we're scraping
        $url_domain = parse_url($url, PHP_URL_HOST);
        
        if ($detailed_log) {
            error_log("APM: Detected domain: $url_domain");
        }
        
        // TOPGUNWELDING.COM.AU - Look for <span class="product-sku__value">
        if (strpos($url_domain, 'topgunwelding.com.au') !== false) {
            $sku = $this->extract_topgunwelding($xpath, $html_content, $detailed_log);
        }
        
        // EASTWESTENG.COM.AU - Look in price table "Model" column
        elseif (strpos($url_domain, 'eastwesteng.com.au') !== false) {
            $sku = $this->extract_eastwesteng($xpath, $detailed_log);
        }
        
        // OTHER SITES - Generic SKU extraction attempts
        else {
            $sku = $this->extract_generic($xpath, $detailed_log);
        }
        
        if ($detailed_log) {
            error_log("APM: ========== SKU EXTRACTION COMPLETE (DETAILED) ==========");
            if (!empty($sku)) {
                error_log("APM: Final SKU value: $sku");
            } else {
                error_log("APM: No SKU found - will use fallback generation");
            }
            error_log("APM: ===============================================");
        }
        
        // BASIC LOGGING - Always show
        if (!empty($sku)) {
            error_log("APM: SKU extraction complete - SKU: $sku");
        } else {
            error_log("APM: SKU extraction complete - No SKU found (will use fallback)");
        }
        
        return $sku;
    }
    
    /**
     * Extract SKU from topgunwelding.com.au
     */
    private function extract_topgunwelding($xpath, $html_content, $detailed_log) {
        if ($detailed_log) {
            error_log("APM: Using topgunwelding.com.au extraction method");
        }
        
        // Try XPath first
        $nodes = $xpath->query('//span[contains(@class, "product-sku__value")]');
        
        if ($nodes && $nodes->length > 0) {
            $sku = trim($nodes->item(0)->textContent);
            
            if ($detailed_log) {
                error_log("APM: ✓ Found SKU via XPath in span.product-sku__value: $sku");
            }
            return $sku;
        } else {
            if ($detailed_log) {
                error_log("APM: ✗ XPath search found no span.product-sku__value");
                error_log("APM: Trying regex fallback on raw HTML...");
            }
            
            // Fallback: Try regex on raw HTML
            if (preg_match('/<span[^>]*class=["\'][^"\']*product-sku__value[^"\']*["\'][^>]*>([^<]+)<\/span>/i', $html_content, $match)) {
                $sku = trim($match[1]);
                
                if ($detailed_log) {
                    error_log("APM: ✓ Found SKU via regex fallback: $sku");
                }
                return $sku;
            } else {
                if ($detailed_log) {
                    error_log("APM: ✗ Regex fallback also found no SKU");
                }
            }
        }
        
        return '';
    }
    
    /**
     * Extract SKU from eastwesteng.com.au (WooCommerce)
     */
    private function extract_eastwesteng($xpath, $detailed_log) {
        if ($detailed_log) {
            error_log("APM: Using eastwesteng.com.au WooCommerce extraction method");
            error_log("APM: Looking for SKU in standard WooCommerce elements");
        }

        // Standard WooCommerce SKU selectors
        $selectors = array(
            '//*[@itemprop="sku"]',
            '//span[contains(@class, "sku")]',
            '//p[contains(@class, "sku")]',
            '//div[contains(@class, "sku")]',
        );

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $potential_sku = trim($nodes->item(0)->textContent);
                // Remove "SKU:" prefix if present
                $potential_sku = preg_replace('/^SKU:\s*/i', '', $potential_sku);
                if (!empty($potential_sku)) {
                    if ($detailed_log) {
                        error_log("APM: ✓ Found SKU via selector '$selector': $potential_sku");
                    }
                    return $potential_sku;
                }
            }
        }

        if ($detailed_log) {
            error_log("APM: ✗ No SKU found via WooCommerce selectors");
        }

        return '';
    }
    
    /**
     * Extract SKU from generic sites
     */
    private function extract_generic($xpath, $detailed_log) {
        if ($detailed_log) {
            error_log("APM: Using generic SKU extraction method for unknown domain");
        }
        
        // Try common SKU selectors
        $selectors = array(
            '//span[contains(@class, "sku")]',
            '//div[contains(@class, "sku")]',
            '//*[@itemprop="sku"]',
            '//span[contains(text(), "SKU:")]/following-sibling::*[1]',
            '//div[contains(text(), "SKU:")]/following-sibling::*[1]',
        );
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            
            if ($nodes && $nodes->length > 0) {
                $potential_sku = trim($nodes->item(0)->textContent);
                
                // Clean up - remove "SKU:" prefix if present
                $potential_sku = preg_replace('/^SKU:\s*/i', '', $potential_sku);
                
                if (!empty($potential_sku)) {
                    if ($detailed_log) {
                        error_log("APM: ✓ Found SKU via selector '$selector': $potential_sku");
                    }
                    return $potential_sku;
                }
            }
        }
        
        if ($detailed_log) {
            error_log("APM: ✗ Generic SKU extraction found no results");
        }
        
        return '';
    }
}
