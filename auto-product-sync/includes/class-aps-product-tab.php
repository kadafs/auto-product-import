<?php
/**
 * Product tab functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class APS_Product_Tab {
    
    public function __construct() {
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_product_data_panel'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_data'));
    }
    
    /**
     * Add custom tab to product data metabox
     */
    public function add_product_data_tab($tabs) {
        $tabs['aps_sync'] = array(
            'label' => __('Auto Product Sync', 'auto-product-sync'),
            'target' => 'aps_sync_product_data',
            'class' => array('show_if_simple', 'show_if_variable'),
        );
        return $tabs;
    }
    
    /**
     * Add custom tab content
     */
    public function add_product_data_panel() {
        global $post;
        
        $enable_sync = APS_Core::get_product_meta($post->ID, '_aps_enable_sync');
        $url = APS_Core::get_product_meta($post->ID, '_aps_url');
        $add_gst = APS_Core::get_product_meta($post->ID, '_aps_add_gst');
        $add_margin = APS_Core::get_product_meta($post->ID, '_aps_add_margin');
        $margin_percentage = APS_Core::get_product_meta($post->ID, '_aps_margin_percentage');
        if (empty($margin_percentage)) {
            $margin_percentage = 10; // Default 10%
        }
        $external_regular_price = APS_Core::get_product_meta($post->ID, '_aps_external_regular_price');
        $external_sale_price = APS_Core::get_product_meta($post->ID, '_aps_external_sale_price');
        $external_regular_price_inc_gst = APS_Core::get_product_meta($post->ID, '_aps_external_regular_price_inc_gst');
        $external_sale_price_inc_gst = APS_Core::get_product_meta($post->ID, '_aps_external_sale_price_inc_gst');
        
        ?>
        <div id="aps_sync_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                // Enable Sync checkbox
                woocommerce_wp_checkbox(array(
                    'id' => '_aps_enable_sync',
                    'label' => __('Enable Sync', 'auto-product-sync'),
                    'description' => __('Enable automatic price synchronization for this product', 'auto-product-sync'),
                    'value' => $enable_sync === 'yes' ? 'yes' : 'no'
                ));
                
                // URL field
                woocommerce_wp_text_input(array(
                    'id' => '_aps_url',
                    'label' => __('URL', 'auto-product-sync'),
                    'description' => __('Enter the URL to extract prices from', 'auto-product-sync'),
                    'type' => 'url',
                    'value' => $url,
                    'placeholder' => 'https://example.com/product-page'
                ));
                
                // Add GST checkbox
                woocommerce_wp_checkbox(array(
                    'id' => '_aps_add_gst',
                    'label' => __('Add GST', 'auto-product-sync'),
                    'description' => __('Add 10% GST to extracted prices', 'auto-product-sync'),
                    'value' => $add_gst === 'yes' ? 'yes' : 'no'
                ));

                // Add Margin checkbox
                woocommerce_wp_checkbox(array(
                    'id' => '_aps_add_margin',
                    'label' => __('Add Margin', 'auto-product-sync'),
                    'description' => __('Add margin percentage to extracted prices (after GST if enabled)', 'auto-product-sync'),
                    'value' => $add_margin === 'yes' ? 'yes' : 'no'
                ));

                // Margin Percentage field
                woocommerce_wp_text_input(array(
                    'id' => '_aps_margin_percentage',
                    'label' => __('Margin Percentage', 'auto-product-sync'),
                    'description' => __('Margin percentage to add (minimum 1%)', 'auto-product-sync'),
                    'type' => 'number',
                    'value' => $margin_percentage,
                    'custom_attributes' => array(
                        'min' => '1',
                        'step' => '0.01'
                    ),
                    'desc_tip' => true
                ));
                ?>
            </div>
            
            <div class="options_group">
                <h3><?php _e('Extracted Prices', 'auto-product-sync'); ?></h3>
                <?php
                // External Regular Price
                woocommerce_wp_text_input(array(
                    'id' => '_aps_external_regular_price',
                    'label' => __('External Regular Price', 'auto-product-sync'),
                    'data_type' => 'price',
                    'value' => $external_regular_price,
                    'custom_attributes' => array('readonly' => 'readonly'),
                    'description' => __('Regular price extracted from URL (read-only)', 'auto-product-sync')
                ));
                
                // External Regular Price (inc GST)
                woocommerce_wp_text_input(array(
                    'id' => '_aps_external_regular_price_inc_gst',
                    'label' => __('External Regular Price (incGST)', 'auto-product-sync'),
                    'data_type' => 'price',
                    'value' => $external_regular_price_inc_gst,
                    'custom_attributes' => array('readonly' => 'readonly'),
                    'description' => __('Regular price including GST (read-only)', 'auto-product-sync')
                ));
                
                // External Sale Price
                woocommerce_wp_text_input(array(
                    'id' => '_aps_external_sale_price',
                    'label' => __('External Sale Price', 'auto-product-sync'),
                    'data_type' => 'price',
                    'value' => $external_sale_price,
                    'custom_attributes' => array('readonly' => 'readonly'),
                    'description' => __('Sale price extracted from URL (read-only)', 'auto-product-sync')
                ));
                
                // External Sale Price (inc GST)
                woocommerce_wp_text_input(array(
                    'id' => '_aps_external_sale_price_inc_gst',
                    'label' => __('External Sale Price (incGST)', 'auto-product-sync'),
                    'data_type' => 'price',
                    'value' => $external_sale_price_inc_gst,
                    'custom_attributes' => array('readonly' => 'readonly'),
                    'description' => __('Sale price including GST (read-only)', 'auto-product-sync')
                ));
                ?>
            </div>
            
            <div class="options_group">
                <p class="form-field">
                    <button type="button" id="aps-download-prices" class="button button-primary" data-product-id="<?php echo $post->ID; ?>">
                        <?php _e('Download Prices', 'auto-product-sync'); ?>
                    </button>
                    <span id="aps-sync-status-product" class="aps-status"></span>
                </p>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#aps-download-prices').on('click', function() {
                var button = $(this);
                var productId = button.data('product-id');
                var status = $('#aps-sync-status-product');
                
                button.prop('disabled', true).text('<?php _e('Downloading...', 'auto-product-sync'); ?>');
                status.removeClass('aps-success aps-error').text('');
                
                $.ajax({
                    url: aps_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'aps_sync_single_product',
                        product_id: productId,
                        security: aps_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            status.addClass('aps-success').text(response.data);
                            // Reload the page to show updated prices
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            status.addClass('aps-error').text(response.data);
                        }
                    },
                    error: function() {
                        status.addClass('aps-error').text('<?php _e('Connection error', 'auto-product-sync'); ?>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php _e('Download Prices', 'auto-product-sync'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Save product data (HPOS compatible)
     */
    public function save_product_data($post_id) {
        // Enable sync
        $enable_sync = isset($_POST['_aps_enable_sync']) ? 'yes' : 'no';
        APS_Core::update_product_meta($post_id, '_aps_enable_sync', $enable_sync);
        
        // URL
        if (isset($_POST['_aps_url'])) {
            $url = esc_url_raw($_POST['_aps_url']);
            APS_Core::update_product_meta($post_id, '_aps_url', $url);
        }
        
        // Add GST
        $add_gst = isset($_POST['_aps_add_gst']) ? 'yes' : 'no';
        APS_Core::update_product_meta($post_id, '_aps_add_gst', $add_gst);

        // Add Margin
        $add_margin = isset($_POST['_aps_add_margin']) ? 'yes' : 'no';
        APS_Core::update_product_meta($post_id, '_aps_add_margin', $add_margin);

        // Margin Percentage
        if (isset($_POST['_aps_margin_percentage'])) {
            $margin_percentage = floatval($_POST['_aps_margin_percentage']);
            // Ensure minimum value of 1%
            $margin_percentage = max(1, $margin_percentage);
            APS_Core::update_product_meta($post_id, '_aps_margin_percentage', $margin_percentage);
        }

        // Note: External prices are read-only and updated by the sync process
    }
}