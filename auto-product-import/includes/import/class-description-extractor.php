<?php
/**
 * Description Extractor class - Main orchestrator
 *
 * @package Auto_Product_Import
 * @since 2.1.2
 */

if (!defined('WPINC')) {
    die;
}

class APM_Description_Extractor {
    
    private $additional_info_extractor;
    
    public function __construct() {
        $this->additional_info_extractor = new APM_Description_Extractor_Additional_Info();
    }
    
    /**
     * Extract description HTML from page
     *
     * @param DOMDocument $dom The DOM object
     * @param DOMXPath $xpath The XPath object
     * @return string The extracted description HTML
     */
    public function extract($dom, $xpath) {
        $selectors = array(
            '//div[contains(@class, "description") and not(contains(@class, "tab-title")) and not(contains(@class, "tab-heading"))]',
            '//div[contains(@class, "product-description") and not(contains(@class, "tab-title")) and not(contains(@class, "tab-heading"))]',
            '//div[@id="description" and not(contains(@class, "tab-title")) and not(contains(@class, "tab-heading"))]',
            '//div[@id="tab-description"]',
            '//div[contains(@class, "woocommerce-Tabs-panel--description")]',
            '//div[@class="tab-content"]//div[contains(@id, "description")]',
            '//div[@class="tab-content"]/div[contains(@class, "active")]',
            '//div[@id="product-description"]',
            '//section[contains(@class, "product-description")]',
            '//div[contains(@class, "product-details-description")]',
            '//div[contains(@class, "woocommerce-product-details__short-description")]',
            '//div[contains(@class, "product_description")]',
            '//div[contains(@class, "pdp-description")]'
        );
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                return $dom->saveHTML($nodes->item(0));
            }
        }
        
        return $this->extract_broader($dom, $xpath);
    }
    
    /**
     * Extract using broader selectors
     */
    private function extract_broader($dom, $xpath) {
        $broader_selectors = array(
            '//div[contains(@class, "tab-content")]',
            '//div[contains(@class, "product-details")]',
            '//div[contains(@class, "product-info")]',
            '//div[contains(@class, "product-specs")]',
            '//div[contains(@class, "product-specification")]',
            '//article[contains(@class, "product")]',
            '//div[@id="detailBullets"]',
            '//div[@id="productDescription"]'
        );
        
        foreach ($broader_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                return $dom->saveHTML($nodes->item(0));
            }
        }
        
        return $this->extract_product_containers($dom, $xpath);
    }
    
    /**
     * Extract from product containers
     */
    private function extract_product_containers($dom, $xpath) {
        $product_selectors = array(
            '//div[contains(@class, "product")]',
            '//main[contains(@class, "product")]',
            '//div[@id="product"]',
            '//div[@itemtype="http://schema.org/Product"]'
        );
        
        foreach ($product_selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                return $dom->saveHTML($nodes->item(0));
            }
        }
        
        $meta_desc = $xpath->query('//meta[@name="description"]/@content');
        if ($meta_desc && $meta_desc->length > 0) {
            return '<p>' . trim($meta_desc->item(0)->textContent) . '</p>';
        }
        
        return '';
    }
    
    /**
     * Clean HTML content
     */
    public function clean_html($html) {
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html);
        $html = preg_replace('/<ul[^>]*class=["\'][^"\']*(?:tabs|nav-tabs|wc-tabs)[^"\']*["\'][^>]*>.*?<\/ul>/is', '', $html);
        $html = preg_replace('/<nav[^>]*class=["\'][^"\']*(?:woocommerce-tabs|tabs)[^"\']*["\'][^>]*>.*?<\/nav>/is', '', $html);
        $html = preg_replace('/<div[^>]*class=["\'][^"\']*(?:tab-nav|tab-header|wc-tabs-wrapper|product-tabs)[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*role=["\']tablist["\'][^>]*>.*?<\/div>/is', '', $html);
        $html = preg_replace('/<ul[^>]*role=["\']tablist["\'][^>]*>.*?<\/ul>/is', '', $html);
        $html = preg_replace('/<h[1-6][^>]*class=["\'][^"\']*tab[^"\']*["\'][^>]*>.*?<\/h[1-6]>/is', '', $html);
        $html = preg_replace('/<h[1-6][^>]*id=["\'][^"\']*tab[^"\']*["\'][^>]*>.*?<\/h[1-6]>/is', '', $html);
        $html = preg_replace('/<div[^>]*style=["\'][^"\']*background(?:-color)?:\s*#[dD]9[eE][dD][fF]7[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*style=["\'][^"\']*background(?:-color)?:\s*#[eE][aA][fF][0-9a-fA-F][^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*style=["\'][^"\']*background(?:-color)?:\s*rgb\(\s*217\s*,\s*237\s*,\s*247[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        
        $phrases_to_remove = array(
            'Be first to review this item',
            'Ask our customer community',
            'Other customers may have experience',
            'Post Question'
        );
        
        foreach ($phrases_to_remove as $phrase) {
            $html = preg_replace('/<div[^>]*>(?:(?!<div).)*' . preg_quote($phrase, '/') . '.*?<\/div>/is', '', $html);
            $html = preg_replace('/<p[^>]*>(?:(?!<p).)*' . preg_quote($phrase, '/') . '.*?<\/p>/is', '', $html);
        }
        
        $classes_to_remove = array(
            'alert', 'info', 'notice', 'notification', 'comment-area', 'review-area', 
            'feedback', 'rating-widget', 'customer-feedback', 'review-banner',
            'review-section', 'qa-section', 'community-qa', 'product-qa'
        );
        
        foreach ($classes_to_remove as $className) {
            $html = preg_replace('/<div[^>]*class=["\'][^"\']*' . preg_quote($className, '/') . '[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);
        }
        
        $tab_headings = array('DESCRIPTION', 'REVIEWS', 'REVIEWS \(\d+\)', 'Q & A');
        foreach ($tab_headings as $heading) {
            $html = preg_replace('/<div[^>]*>\s*' . $heading . '\s*<\/div>/is', '', $html);
            $html = preg_replace('/<h[1-6][^>]*>\s*' . $heading . '\s*<\/h[1-6]>/is', '', $html);
        }
        
        $html = preg_replace('/<button[^>]*>.*?(?:Post|Review|Question).*?<\/button>/is', '', $html);
        $html = preg_replace('/<a[^>]*class=["\'][^"\']*(?:btn|button)[^"\']*["\'][^>]*>.*?<\/a>/is', '', $html);
        $html = preg_replace('/<hr[^>]*>/is', '', $html);
        $html = preg_replace('/\(\d+\/\d+\)/', '', $html);
        $html = preg_replace('/<p[^>]*>\s*<\/p>/is', '', $html);
        $html = preg_replace('/<div[^>]*>\s*<\/div>/is', '', $html);
        $html = preg_replace('/(\s*<br\s*\/?>\s*){3,}/is', '<br>', $html);
        
        return $html;
    }

    /**
     * Extract additional product information (delegates to helper class)
     */
    public function extract_additional_info($description_html, $debug = false) {
        return $this->additional_info_extractor->extract($description_html, $debug);
    }
}
