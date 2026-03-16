<?php
/**
 * HTML Parser class
 *
 * @package Auto_Product_Import
 * @since 2.1.1
 */

if (!defined('WPINC')) {
    die;
}

class APM_HTML_Parser {
    
    /**
     * Parse HTML content
     *
     * @param string $html The HTML content
     * @return array Array with 'dom' and 'xpath' objects
     */
    public function parse($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        return array(
            'dom' => $dom,
            'xpath' => $xpath
        );
    }
    
    /**
     * Extract title from HTML
     *
     * @param DOMXPath $xpath The XPath object
     * @param DOMDocument $dom The DOM object
     * @return string The extracted title
     */
    public function extract_title($xpath, $dom) {
        $title_nodes = $xpath->query('//h1[@class="product-title"] | //h1[@class="product_title"] | //h1[contains(@class, "product-title")] | //h1[contains(@class, "product_title")]');
        
        if ($title_nodes && $title_nodes->length > 0) {
            return trim($title_nodes->item(0)->textContent);
        }
        
        $title_tags = $dom->getElementsByTagName('title');
        if ($title_tags->length > 0) {
            return trim($title_tags->item(0)->textContent);
        }
        
        return '';
    }
    
    /**
     * Extract price from HTML
     *
     * @param DOMXPath $xpath The XPath object
     * @return string The extracted price
     */
    public function extract_price($xpath) {
        $price_nodes = $xpath->query('//span[contains(@class, "price")] | //div[contains(@class, "price")] | //p[contains(@class, "price")]');
        
        if ($price_nodes && $price_nodes->length > 0) {
            $price_text = trim($price_nodes->item(0)->textContent);
            preg_match('/[\d.,]+/', $price_text, $matches);
            if (!empty($matches)) {
                return $matches[0];
            }
        }
        
        return '';
    }
}