<?php
/**
 * Specifications Extractor for Magento
 *
 * @package Auto_Product_Import
 * @since 2.1.5
 */

if (!defined('WPINC')) {
    die;
}

class APM_Specifications_Extractor_Magento {
    
    /**
     * Extract specifications from Magento product page
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param string $html_content The raw HTML content
     * @param bool $debug Debug mode flag
     * @return array Specifications data with 'found' and 'content' keys
     */
    public function extract($xpath, $url, $html_content, $debug = false) {
        if ($debug) {
            error_log("APM Specifications: Starting Magento-specific extraction");
        }
        
        $specifications = array(
            'found' => false,
            'content' => ''
        );
        
        // Define heading priorities
        $heading_priorities = array(
            'Specifications',
            'Technical Specifications',
            'Product Specifications',
            'Specs',
            'Technical Details',
            'Additional Information'
        );
        
        // Try different Magento tab/accordion patterns
        $selectors = array(
            // Magento tab content by ID
            '//div[@id="tab_description_tabbed_contents"]',
            '//*[@id="tab_description_tabbed_contents"]',
            
            // Magento 2 tabs
            '//div[@data-role="collapsible"]//div[@data-role="title"][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//div[@class="data item title"]//a[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//div[@role="tab"]//a[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            
            // Magento accordion
            '//div[@data-collapsible="true"]//div[contains(@class, "title")][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//dt[contains(@class, "title")][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            
            // Magento product details tab
            '//div[@id="product.info.details"]//div[contains(@class, "title")][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//div[@id="additional"]',
            
            // Generic Magento patterns
            '//div[@class="product attribute specifications"]',
            '//div[contains(@class, "product-specs")]',
            '//table[@id="product-attribute-specs-table"]',
            '//table[contains(@class, "data-table") and contains(@class, "additional-attributes")]',
            
            // Heading patterns
            '//h2[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//h3[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            
            // Class-based patterns
            '//div[contains(@class, "specifications")]',
            '//div[contains(@class, "additional-attributes")]',
            
            // Tab list items
            '//li[@id="tab_description_tabbed"]'
        );
        
        foreach ($selectors as $index => $selector) {
            if ($debug) {
                error_log("APM Specifications: Trying Magento selector #" . ($index + 1));
            }
            
            $nodes = $xpath->query($selector);
            
            if ($nodes && $nodes->length > 0) {
                if ($debug) {
                    error_log("APM Specifications: ✓ Selector #" . ($index + 1) . " found " . $nodes->length . " matches");
                }
                
                foreach ($nodes as $node) {
                    // For content divs by ID, just use them directly
                    if ($node->hasAttribute('id') && strpos($node->getAttribute('id'), 'contents') !== false) {
                        if ($debug) {
                            error_log("APM Specifications: ✓ Found specifications content div by ID");
                        }
                        
                        $extractor = new APM_Specifications_Extractor();
                        $content = $extractor->parse_specifications_content($node, $debug);
                        
                        if (!empty($content)) {
                            $content = $this->clean_specifications_content($content);
                            $specifications['found'] = true;
                            $specifications['content'] = $content;
                            return $specifications;
                        }
                    }
                    
                    $heading_text = trim($node->textContent);
                    
                    // For table selectors, just use the table directly
                    if ($node->nodeName === 'table') {
                        if ($debug) {
                            error_log("APM Specifications: ✓ Found specifications table");
                        }
                        
                        $extractor = new APM_Specifications_Extractor();
                        $content = $extractor->parse_specifications_content($node, $debug);
                        
                        if (!empty($content)) {
                            $content = $this->clean_specifications_content($content);
                            $specifications['found'] = true;
                            $specifications['content'] = $content;
                            return $specifications;
                        }
                    }
                    
                    // Check against priorities for non-table elements
                    foreach ($heading_priorities as $priority_heading) {
                        if (stripos($heading_text, $priority_heading) !== false) {
                            if ($debug) {
                                error_log("APM Specifications: ✓ Found priority match: $priority_heading");
                            }
                            
                            // Extract content based on element type
                            $content = $this->extract_content_for_node($node, $xpath, $debug);
                            
                            if (!empty($content)) {
                                $specifications['found'] = true;
                                $specifications['content'] = $content;
                                return $specifications;
                            }
                        }
                    }
                }
            } else {
                if ($debug) {
                    error_log("APM Specifications: ✗ Selector #" . ($index + 1) . " found 0 matches");
                }
            }
        }
        
        if ($debug) {
            error_log("APM Specifications: No Magento specifications found");
        }
        
        return $specifications;
    }
    
    /**
     * Extract content for a specifications node
     *
     * @param DOMElement $node The heading/title node
     * @param DOMXPath $xpath The XPath object
     * @param bool $debug Debug mode flag
     * @return string Formatted specifications content
     */
    private function extract_content_for_node($node, $xpath, $debug = false) {
        $content_node = null;
        
        // Handle different Magento element types
        $element_name = strtolower($node->nodeName);
        
        if ($element_name === 'a') {
            // Tab link - find associated content
            $content_node = $this->find_tab_content($node, $xpath);
        } elseif ($node->hasAttribute('data-role') && $node->getAttribute('data-role') === 'title') {
            // Magento collapsible title
            $content_node = $this->find_collapsible_content($node, $xpath);
        } elseif ($element_name === 'dt') {
            // Definition term - content is in the dd
            $content_node = $this->find_dd_content($node);
        } elseif ($element_name === 'h2' || $element_name === 'h3') {
            // Heading - content is next sibling
            $content_node = $this->find_heading_content($node);
        } else {
            // For div containers: content is within the div
            $content_node = $node;
        }
        
        if (!$content_node) {
            if ($debug) {
                error_log("APM Specifications: Could not find content node");
            }
            return '';
        }
        
        // Parse the content node
        $extractor = new APM_Specifications_Extractor();
        $content = $extractor->parse_specifications_content($content_node, $debug);
        
        // Clean up content
        $content = $this->clean_specifications_content($content);
        
        return $content;
    }
    
    /**
     * Find Magento tab content
     */
    private function find_tab_content($link, $xpath) {
        // Try href attribute
        if ($link->hasAttribute('href')) {
            $href = $link->getAttribute('href');
            
            // Remove leading #
            $id = ltrim($href, '#');
            
            if (!empty($id)) {
                $panel = $xpath->query('//*[@id="' . $id . '"]');
                if ($panel && $panel->length > 0) {
                    return $panel->item(0);
                }
            }
        }
        
        // Try aria-controls
        if ($link->hasAttribute('aria-controls')) {
            $controls_id = $link->getAttribute('aria-controls');
            $panel = $xpath->query('//*[@id="' . $controls_id . '"]');
            if ($panel && $panel->length > 0) {
                return $panel->item(0);
            }
        }
        
        // Try parent's next sibling (Magento pattern)
        $parent = $link->parentNode;
        if ($parent) {
            $sibling = $parent->nextSibling;
            while ($sibling && $sibling->nodeType === XML_TEXT_NODE) {
                $sibling = $sibling->nextSibling;
            }
            if ($sibling) {
                return $sibling;
            }
        }
        
        return null;
    }
    
    /**
     * Find Magento collapsible content
     */
    private function find_collapsible_content($title, $xpath) {
        // Look for next sibling with data-role="content"
        $sibling = $title->nextSibling;
        
        while ($sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE) {
                if ($sibling->hasAttribute('data-role') && 
                    $sibling->getAttribute('data-role') === 'content') {
                    return $sibling;
                }
            }
            $sibling = $sibling->nextSibling;
        }
        
        // Try parent's next sibling
        $parent = $title->parentNode;
        if ($parent) {
            $sibling = $parent->nextSibling;
            while ($sibling && $sibling->nodeType === XML_TEXT_NODE) {
                $sibling = $sibling->nextSibling;
            }
            if ($sibling) {
                return $sibling;
            }
        }
        
        return null;
    }
    
    /**
     * Find dd content for dt element
     */
    private function find_dd_content($dt) {
        $sibling = $dt->nextSibling;
        
        while ($sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE && $sibling->nodeName === 'dd') {
                return $sibling;
            }
            $sibling = $sibling->nextSibling;
        }
        
        return null;
    }
    
    /**
     * Find heading content node (next sibling container)
     */
    private function find_heading_content($heading) {
        $sibling = $heading->nextSibling;
        
        while ($sibling && $sibling->nodeType === XML_TEXT_NODE) {
            $sibling = $sibling->nextSibling;
        }
        
        return $sibling;
    }
    
    /**
     * Clean specifications content
     */
    private function clean_specifications_content($content) {
        // Remove extra whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/\n\s*\n/', "\n", $content);
        
        // Trim each line
        $lines = explode("\n", $content);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines); // Remove empty lines
        
        return implode("\n", $lines);
    }
}