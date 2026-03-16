<?php
/**
 * DOM helper functions
 *
 * @package Auto_Product_Import
 * @since 2.1.1
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Safely check if DOM element has attribute
 *
 * @param DOMElement $element The DOM element
 * @param string $attribute The attribute name
 * @return bool True if has attribute, false otherwise
 */
function apm_dom_has_attribute($element, $attribute) {
    return method_exists($element, 'hasAttribute') && $element->hasAttribute($attribute);
}

/**
 * Safely get DOM element attribute value
 *
 * @param DOMElement $element The DOM element
 * @param string $attribute The attribute name
 * @return string The attribute value or empty string
 */
function apm_dom_get_attribute($element, $attribute) {
    if (method_exists($element, 'getAttribute')) {
        return $element->getAttribute($attribute);
    }
    return '';
}

/**
 * Check if DOM node is in related products section
 *
 * @param DOMNode $node The DOM node to check
 * @param bool $debug Whether to log debug info
 * @return bool True if in related products section
 */
function apm_is_in_related_products_section($node, $debug = false) {
    $current = $node;
    $max_levels = 10;
    $level = 0;
    
    while ($current && $level < $max_levels) {
        $class = apm_dom_get_attribute($current, 'class');
        $id = apm_dom_get_attribute($current, 'id');
        
        $related_product_patterns = array(
            '/related[-_]?products?/i',
            '/similar[-_]?products?/i',
            '/recommended[-_]?products?/i',
            '/you[-_]?may[-_]?also[-_]?like/i',
            '/cross[-_]?sell/i',
            '/up[-_]?sell/i',
            '/product[-_]?recommendations?/i',
            '/product[-_]?suggestions?/i'
        );
        
        foreach ($related_product_patterns as $pattern) {
            if (preg_match($pattern, $class) || preg_match($pattern, $id)) {
                if ($debug) {
                    error_log("Found image in related products section: class=$class, id=$id");
                }
                return true;
            }
        }
        
        if (in_array($current->nodeName, array('h1', 'h2', 'h3', 'h4'))) {
            $text = $current->textContent;
            if (preg_match('/related\s+products/i', $text) || 
                preg_match('/similar\s+products/i', $text) || 
                preg_match('/you\s+may\s+also\s+like/i', $text) ||
                preg_match('/recommended\s+for\s+you/i', $text)) {
                return true;
            }
        }
        
        $current = $current->parentNode;
        $level++;
    }
    
    return false;
}