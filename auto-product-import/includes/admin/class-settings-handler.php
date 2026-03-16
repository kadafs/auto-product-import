<?php
/**
 * Settings Handler class
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

class APM_Settings_Handler {
    
    /**
     * Initialize settings handler
     */
    public function init() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('auto_product_import_settings', 'auto_product_import_default_category');
        register_setting('auto_product_import_settings', 'auto_product_import_default_status');
        register_setting('auto_product_import_settings', 'auto_product_import_max_images', array(
            'default' => 20,
            'sanitize_callback' => 'apm_sanitize_max_images'
        ));
        register_setting('auto_product_import_settings', 'auto_product_import_max_pdf_size', array(
            'default' => 10,
            'sanitize_callback' => 'apm_sanitize_max_pdf_size'
        ));
        register_setting('auto_product_import_settings', 'auto_product_import_debug_domain', array(
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        
        // Detailed logging options
        register_setting('auto_product_import_settings', 'auto_product_import_log_pdf', array(
            'default' => 'no',
            'sanitize_callback' => array($this, 'sanitize_checkbox')
        ));
        register_setting('auto_product_import_settings', 'auto_product_import_log_sku', array(
            'default' => 'no',
            'sanitize_callback' => array($this, 'sanitize_checkbox')
        ));
        register_setting('auto_product_import_settings', 'auto_product_import_log_sync', array(
            'default' => 'no',
            'sanitize_callback' => array($this, 'sanitize_checkbox')
        ));
    }
    
    /**
     * Sanitize checkbox value
     */
    public function sanitize_checkbox($value) {
        return ($value === 'yes' || $value === '1' || $value === 1) ? 'yes' : 'no';
    }
}
