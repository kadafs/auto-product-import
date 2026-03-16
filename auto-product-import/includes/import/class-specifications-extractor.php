<?php
/**
 * Specifications Extractor class - Main coordinator
 *
 * @package Auto_Product_Import
 * @since 2.1.5
 */

if (!defined('WPINC')) {
    die;
}

class APM_Specifications_Extractor {
    
    private $shopify_extractor;
    private $magento_extractor;
    
    public function __construct() {
        $this->shopify_extractor = new APM_Specifications_Extractor_Shopify();
        $this->magento_extractor = new APM_Specifications_Extractor_Magento();
    }
    
    /**
     * Extract specifications from product page
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param string $html_content The raw HTML content
     * @param bool $debug Debug mode flag
     * @return array Specifications data with 'found' and 'content' keys
     */
    public function extract($xpath, $url, $html_content, $debug = false) {
        $debug_domain = get_option('apm_debug_domain', '');
        $should_log_detailed = empty($debug_domain) || strpos($url, $debug_domain) !== false;
        
        if ($debug && $should_log_detailed) {
            error_log("APM Specifications: Starting extraction for URL: $url");
        }
        
        // Detect platform
        $platform = $this->detect_platform($html_content, $url, $debug && $should_log_detailed);
        
        if ($debug && $should_log_detailed) {
            error_log("APM Specifications: Detected platform: $platform");
        }
        
        $specifications = array(
            'found' => false,
            'content' => ''
        );
        
        // Platform-specific extraction
        switch ($platform) {
            case 'shopify':
                $specifications = $this->shopify_extractor->extract($xpath, $url, $html_content, $debug && $should_log_detailed);
                break;
                
            case 'magento':
                $specifications = $this->magento_extractor->extract($xpath, $url, $html_content, $debug && $should_log_detailed);
                break;
                
            default:
                if ($debug && $should_log_detailed) {
                    error_log("APM Specifications: Platform not specifically supported, skipping extraction");
                }
                break;
        }
        
        // Log results
        if ($debug) {
            if ($specifications['found']) {
                $content_preview = substr($specifications['content'], 0, 200);
                error_log("APM Specifications: ✓ Successfully extracted specifications");
                if ($should_log_detailed) {
                    error_log("APM Specifications: Content preview: $content_preview...");
                }
            } else {
                error_log("APM Specifications: No specifications found on page");
            }
        }
        
        return $specifications;
    }
    
    /**
     * Detect platform from HTML content
     *
     * @param string $html The HTML content
     * @param string $url The page URL
     * @param bool $debug Debug mode flag
     * @return string Platform identifier (shopify, magento, or unknown)
     */
    private function detect_platform($html, $url, $debug = false) {
        // Check for Shopify
        $shopify_indicators = array(
            'Shopify.theme',
            'shopify-features',
            'cdn.shopify.com',
            'shopifycdn.com',
            'Shopify.shop',
            '.myshopify.com'
        );
        
        foreach ($shopify_indicators as $indicator) {
            if (stripos($html, $indicator) !== false || stripos($url, $indicator) !== false) {
                if ($debug) {
                    error_log("APM Specifications: Shopify detected via indicator: $indicator");
                }
                return 'shopify';
            }
        }
        
        // Check for Magento
        $magento_indicators = array(
            'Magento',
            'mage/cookies',
            'mage/translate',
            'Mage.Cookies',
            'magestore',
            '/mage/',
            'magento_version'
        );
        
        foreach ($magento_indicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                if ($debug) {
                    error_log("APM Specifications: Magento detected via indicator: $indicator");
                }
                return 'magento';
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Parse specifications from HTML node preserving structure
     * Keeps tables, lists as HTML for proper display
     *
     * @param DOMElement $node The specifications container node
     * @param bool $debug Debug mode flag
     * @return string HTML content with preserved structure
     */
    public function parse_specifications_content($node, $debug = false) {
        if (!$node) {
            return '';
        }
        
        // Get the HTML content preserving structure
        $html = $this->get_inner_html($node);
        
        if ($debug) {
            $preview = substr(strip_tags($html), 0, 100);
            error_log("APM Specifications: Extracted HTML content - Preview: $preview...");
        }
        
        return $html;
    }
    
    /**
     * Get inner HTML of a node
     */
    private function get_inner_html($node) {
        $html = '';
        $children = $node->childNodes;
        
        foreach ($children as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        
        return $html;
    }
    
    /**
     * Extract specifications from HTML table
     */
    private function extract_from_table($node) {
        $specs = array();
        $xpath = new DOMXPath($node->ownerDocument);
        $rows = $xpath->query('.//table//tr', $node);
        
        if ($rows && $rows->length > 0) {
            foreach ($rows as $row) {
                $cells = $xpath->query('.//th | .//td', $row);
                
                if ($cells && $cells->length >= 2) {
                    $key = trim($cells->item(0)->textContent);
                    $value = trim($cells->item(1)->textContent);
                    
                    if (!empty($key) && !empty($value)) {
                        $specs[] = "$key: $value";
                    }
                } elseif ($cells && $cells->length === 1) {
                    $text = trim($cells->item(0)->textContent);
                    if (!empty($text)) {
                        $specs[] = $text;
                    }
                }
            }
        }
        
        return $specs;
    }
    
    /**
     * Extract specifications from definition list (dl/dt/dd)
     */
    private function extract_from_definition_list($node) {
        // This method is no longer used but kept for compatibility
        return array();
    }
    
    /**
     * Extract specifications from divs with spec-related classes
     */
    private function extract_from_divs($node) {
        // This method is no longer used but kept for compatibility
        return array();
    }
}
