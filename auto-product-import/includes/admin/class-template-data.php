<?php
/**
 * Template Data class
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

class APM_Template_Data {
    
    /**
     * Get settings page data
     *
     * @return array Settings data
     */
    public static function get_settings_data() {
        return array(
            'default_category' => get_option('auto_product_import_default_category', ''),
            'default_status' => get_option('auto_product_import_default_status', 'draft'),
            'max_images' => get_option('auto_product_import_max_images', 20),
            'max_pdf_size' => get_option('auto_product_import_max_pdf_size', 10),
            'debug_domain' => get_option('auto_product_import_debug_domain', ''),
            'log_pdf' => get_option('auto_product_import_log_pdf', 'no'),
            'log_sku' => get_option('auto_product_import_log_sku', 'no'),
            'log_sync' => get_option('auto_product_import_log_sync', 'no'),
            'categories' => self::get_product_categories()
        );
    }
    
    /**
     * Get product categories
     *
     * @return array Product categories
     */
    private static function get_product_categories() {
        return get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
    }
}
