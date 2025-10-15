<?php
/**
 * Admin Menu class
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

class APM_Admin_Menu {
    
    /**
     * Initialize admin menu
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_shortcode('apm_import_form', array($this, 'render_import_form_shortcode'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_menu_pages() {
        add_menu_page(
            __('Auto Product Import', 'auto-product-import'),
            __('Auto Product Import', 'auto-product-import'),
            'manage_options',
            'apm-auto-product-import',
            array($this, 'render_import_page'),
            'dashicons-admin-generic',
            56
        );
        
        add_submenu_page(
            'apm-auto-product-import',
            __('Import', 'auto-product-import'),
            __('Import', 'auto-product-import'),
            'manage_options',
            'apm-auto-product-import',
            array($this, 'render_import_page')
        );
        
        add_submenu_page(
            'apm-auto-product-import',
            __('Settings', 'auto-product-import'),
            __('Settings', 'auto-product-import'),
            'manage_options',
            'apm-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Render import page
     */
    public function render_import_page() {
        wp_enqueue_script('apm-admin', AUTO_PRODUCT_IMPORT_PLUGIN_URL . 'assets/admin.js', array('jquery'), AUTO_PRODUCT_IMPORT_VERSION, true);
        wp_localize_script('apm-admin', 'autoProductImportAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auto-product-import-nonce')
        ));
        
        include AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'templates/import-page.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $data = APM_Template_Data::get_settings_data();
        extract($data);
        
        include AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'templates/settings-page.php';
    }
    
    /**
     * Render import form shortcode
     */
    public function render_import_form_shortcode($atts) {
        if (!current_user_can('manage_woocommerce')) {
            return '<p>' . __('You do not have permission to import products.', 'auto-product-import') . '</p>';
        }
        
        wp_enqueue_script('apm-frontend', AUTO_PRODUCT_IMPORT_PLUGIN_URL . 'assets/frontend.js', array('jquery'), AUTO_PRODUCT_IMPORT_VERSION, true);
        wp_localize_script('apm-frontend', 'autoProductImportFrontend', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auto-product-import-nonce')
        ));
        
        ob_start();
        include AUTO_PRODUCT_IMPORT_PLUGIN_DIR . 'templates/import-form.php';
        return ob_get_clean();
    }
}
