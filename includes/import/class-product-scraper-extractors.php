<?php
/**
 * Product Scraper Extractors class
 * Handles title, price, and SKU extraction
 *
 * @package Auto_Product_Import
 * @since 2.1.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_Product_Scraper_Extractors {
    
    private $sku_extractor;
    
    public function __construct() {
        $this->sku_extractor = new APM_Product_Scraper_SKU();
    }
    
    /**
     * Extract product title
     *
     * @param DOMXPath $xpath The XPath object
     * @param bool $debug Debug mode flag
     * @return string The extracted title
     */
    public function extract_title($xpath, $debug = false) {
        $title = '';
        
        // Try multiple selectors for title
        $selectors = array(
            '//h1[contains(@class, "product") and contains(@class, "title")]',
            '//h1[@class="product-title"]',
            '//h1[contains(@class, "product-name")]',
            '//h1[contains(@class, "productView-title")]',
            '//div[contains(@class, "product-detail")]//h1',
            '//div[contains(@class, "product-info")]//h1',
            '//h1[@itemprop="name"]',
            '//meta[@property="og:title"]/@content',
            '//title',
        );
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $title = trim($nodes->item(0)->textContent);
                if (!empty($title)) {
                    // Clean up title
                    $title = preg_replace('/\s+/', ' ', $title);
                    break;
                }
            }
        }
        
        return $title;
    }
    
    /**
     * Extract product price
     *
     * @param DOMXPath $xpath The XPath object
     * @param bool $debug Debug mode flag
     * @return string The extracted price
     */
    public function extract_price($xpath, $debug = false) {
        $price = '';
        
        // Try multiple selectors for price
        $selectors = array(
            '//span[contains(@class, "price")]',
            '//div[contains(@class, "price")]',
            '//span[@itemprop="price"]',
            '//meta[@property="og:price:amount"]/@content',
            '//span[contains(@class, "productView-price")]',
            '//div[contains(@class, "product-price")]',
        );
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $price_text = trim($nodes->item(0)->textContent);
                
                // Extract numeric price
                if (preg_match('/[\d,]+\.?\d*/', $price_text, $matches)) {
                    $price = str_replace(',', '', $matches[0]);
                    if (!empty($price)) {
                        break;
                    }
                }
            }
        }
        
        return $price;
    }
    
    /**
     * Extract product SKU (delegates to SKU extractor)
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param string $html_content The raw HTML content
     * @return string The extracted SKU or empty string if not found
     */
    public function extract_sku($xpath, $url, $html_content) {
        return $this->sku_extractor->extract($xpath, $url, $html_content);
    }
}
