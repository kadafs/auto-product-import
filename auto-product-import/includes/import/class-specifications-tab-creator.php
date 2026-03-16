<?php
/**
 * Specifications Tab Creator
 * Creates custom product tabs using WB Custom Product Tabs plugin
 *
 * @package Auto_Product_Import
 * @since 2.1.5
 */

if (!defined('WPINC')) {
    die;
}

class APM_Specifications_Tab_Creator {
    
    /**
     * Check if Custom Product Tabs plugin is active
     *
     * @return bool True if plugin is active
     */
    public function is_plugin_active() {
        // Check if the plugin class exists
        return class_exists('Wb_Custom_Product_Tabs_For_Woocommerce');
    }
    
    /**
     * Create specifications tab for product
     *
     * @param int $product_id The product ID
     * @param array $specifications_data Specifications data with 'found' and 'content' keys
     * @param bool $debug Debug mode flag
     * @return bool True if tab was created, false otherwise
     */
    public function create_tab($product_id, $specifications_data, $debug = false) {
        // Check if plugin is active
        if (!$this->is_plugin_active()) {
            if ($debug) {
                error_log("APM Specifications: Custom Product Tabs plugin not active - skipping tab creation");
            }
            return false;
        }
        
        // Check if specifications were found
        if (empty($specifications_data['found']) || empty($specifications_data['content'])) {
            if ($debug) {
                error_log("APM Specifications: No specifications content to create tab");
            }
            return false;
        }
        
        if ($debug) {
            error_log("APM Specifications: Creating tab for product ID: $product_id");
        }
        
        // Get product
        $product = wc_get_product($product_id);
        
        if (!$product) {
            if ($debug) {
                error_log("APM Specifications: Product not found with ID: $product_id");
            }
            return false;
        }
        
        // Get existing tabs
        $existing_tabs = $product->get_meta('wb_custom_tabs', true);
        $existing_tabs = is_array($existing_tabs) ? $existing_tabs : array();
        
        // Prepare specifications content for tab
        $tab_content = $this->format_content_for_tab($specifications_data['content'], $debug);
        
        // Create new tab
        $new_tab = array(
            'title' => 'Specifications',
            'content' => $tab_content,
            'tab_type' => 'local',
            'position' => 20,
            'nickname' => 'apm_specifications'
        );
        
        // Check if a specifications tab already exists (shouldn't happen, but just in case)
        $tab_exists = false;
        foreach ($existing_tabs as $existing_tab) {
            if (isset($existing_tab['nickname']) && $existing_tab['nickname'] === 'apm_specifications') {
                $tab_exists = true;
                if ($debug) {
                    error_log("APM Specifications: Tab already exists, skipping creation");
                }
                break;
            }
        }
        
        if (!$tab_exists) {
            // Add new tab to existing tabs
            $existing_tabs[] = $new_tab;
            
            // Update product meta
            $product->update_meta_data('wb_custom_tabs', $existing_tabs);
            $product->save();
            
            if ($debug) {
                error_log("APM Specifications: ✓ Successfully created Specifications tab");
                $preview = substr($tab_content, 0, 100);
                error_log("APM Specifications: Tab content preview: $preview...");
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Format specifications content for tab display
     * Converts raw HTML to clean WooCommerce-friendly format
     *
     * @param string $content HTML specifications content
     * @param bool $debug Debug mode flag
     * @return string Formatted HTML content
     */
    private function format_content_for_tab($content, $debug = false) {
        if (empty($content)) {
            return '';
        }
        
        // Load HTML into DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // Find tables and convert to WooCommerce format
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            // Add WooCommerce class
            $table->setAttribute('class', 'shop_attributes');
            
            // Process rows
            $rows = $table->getElementsByTagName('tr');
            foreach ($rows as $row) {
                $cells = $row->getElementsByTagName('td');
                
                if ($cells->length >= 2) {
                    // First cell is the label
                    $label_cell = $cells->item(0);
                    $label_cell->setAttribute('class', 'woocommerce-product-attributes-item__label');
                    
                    // Second cell is the value
                    $value_cell = $cells->item(1);
                    $value_cell->setAttribute('class', 'woocommerce-product-attributes-item__value');
                }
            }
        }
        
        // Get the formatted HTML
        $formatted_content = $dom->saveHTML();
        
        // Remove XML declaration and doctype
        $formatted_content = preg_replace('/^<!DOCTYPE.+?>/', '', $formatted_content);
        $formatted_content = preg_replace('/<\?xml.+?\?>/', '', $formatted_content);
        $formatted_content = preg_replace('/<html><body>/', '', $formatted_content);
        $formatted_content = preg_replace('/<\/body><\/html>/', '', $formatted_content);
        
        // Sanitize using WordPress function
        $formatted_content = wp_kses_post($formatted_content);
        
        if ($debug) {
            error_log("APM Specifications: Formatted HTML content for tab");
        }
        
        return $formatted_content;
    }
}
