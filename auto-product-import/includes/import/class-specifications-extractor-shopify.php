<?php
/**
 * Specifications Extractor for Shopify
 *
 * @package Auto_Product_Import
 * @since 2.1.5
 */

if (!defined('WPINC')) {
    die;
}

class APM_Specifications_Extractor_Shopify {
    
    /**
     * Extract specifications from Shopify product page
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param string $html_content The raw HTML content
     * @param bool $debug Debug mode flag
     * @return array Specifications data with 'found' and 'content' keys
     */
    public function extract($xpath, $url, $html_content, $debug = false) {
        if ($debug) {
            error_log("APM Specifications: Starting Shopify-specific extraction");
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
            'Product Details'
        );
        
        // Try different Shopify tab/accordion patterns
        $selectors = array(
            // Accordion patterns
            '//div[contains(@class, "accordion")]//button[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//div[@class="accordion-item"]//button[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//details//summary[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            
            // Tab patterns
            '//button[@role="tab"][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//div[@role="tab"][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//a[@role="tab"][contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            
            // Generic heading patterns
            '//h2[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//h3[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//h4[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            
            // Div with data attributes
            '//div[@data-tab-title][contains(translate(@data-tab-title, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "specification")]',
            '//div[@data-content-type="specifications"]',
            
            // Class-based patterns
            '//div[contains(@class, "specifications")]',
            '//div[contains(@class, "product-specifications")]',
            '//div[contains(@class, "tech-specs")]'
        );
        
        foreach ($selectors as $index => $selector) {
            if ($debug) {
                error_log("APM Specifications: Trying Shopify selector #" . ($index + 1));
            }
            
            $nodes = $xpath->query($selector);
            
            if ($nodes && $nodes->length > 0) {
                if ($debug) {
                    error_log("APM Specifications: ✓ Selector #" . ($index + 1) . " found " . $nodes->length . " matches");
                }
                
                // Check each node for priority heading match
                foreach ($nodes as $node) {
                    $heading_text = trim($node->textContent);
                    
                    // Check against priorities
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
            error_log("APM Specifications: No Shopify specifications found");
        }
        
        return $specifications;
    }
    
    /**
     * Extract content for a specifications node
     *
     * @param DOMElement $node The heading/button node
     * @param DOMXPath $xpath The XPath object
     * @param bool $debug Debug mode flag
     * @return string Formatted specifications content
     */
    private function extract_content_for_node($node, $xpath, $debug = false) {
        $content_node = null;
        
        // Handle different element types
        $element_name = strtolower($node->nodeName);
        
        if ($element_name === 'button' || $element_name === 'summary') {
            // For accordion: content is usually the next sibling or parent's next sibling
            $content_node = $this->find_accordion_content($node, $xpath);
        } elseif ($element_name === 'a' || $element_name === 'div') {
            // For tabs: look for associated panel via aria-controls or data attributes
            $content_node = $this->find_tab_panel($node, $xpath);
        } elseif ($element_name === 'h2' || $element_name === 'h3' || $element_name === 'h4') {
            // For headings: content is the next sibling container
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
     * Find accordion content node
     */
    private function find_accordion_content($button, $xpath) {
        // Try aria-controls
        if ($button->hasAttribute('aria-controls')) {
            $controls_id = $button->getAttribute('aria-controls');
            $panel = $xpath->query('//*[@id="' . $controls_id . '"]');
            if ($panel && $panel->length > 0) {
                return $panel->item(0);
            }
        }
        
        // Try next sibling
        $sibling = $button->nextSibling;
        while ($sibling && $sibling->nodeType === XML_TEXT_NODE) {
            $sibling = $sibling->nextSibling;
        }
        
        if ($sibling) {
            return $sibling;
        }
        
        // Try parent's next sibling
        $parent = $button->parentNode;
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
     * Find tab panel content node
     */
    private function find_tab_panel($tab, $xpath) {
        // Try aria-controls
        if ($tab->hasAttribute('aria-controls')) {
            $controls_id = $tab->getAttribute('aria-controls');
            $panel = $xpath->query('//*[@id="' . $controls_id . '"]');
            if ($panel && $panel->length > 0) {
                return $panel->item(0);
            }
        }
        
        // Try data-tab attribute
        if ($tab->hasAttribute('data-tab')) {
            $tab_id = $tab->getAttribute('data-tab');
            $panel = $xpath->query('//*[@data-tab-content="' . $tab_id . '"]');
            if ($panel && $panel->length > 0) {
                return $panel->item(0);
            }
        }
        
        // Try role="tabpanel" with matching ID
        if ($tab->hasAttribute('id')) {
            $tab_id = $tab->getAttribute('id');
            $panel_id = str_replace('-tab', '-panel', $tab_id);
            $panel = $xpath->query('//*[@id="' . $panel_id . '"]');
            if ($panel && $panel->length > 0) {
                return $panel->item(0);
            }
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
