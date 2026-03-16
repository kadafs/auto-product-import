<?php
/**
 * Import Queue Manager class
 *
 * Main controller for import queue functionality
 *
 * @package Auto_Product_Import
 * @since 2.2.0
 */

if (!defined('WPINC')) {
    die;
}

class APM_Import_Queue_Manager {
    
    private $database;
    private $renderer;
    private $ajax_handler;
    
    /**
     * Constructor - Initialize components immediately
     */
    public function __construct() {
        $this->database = new APM_Import_Queue_Database();
        $this->renderer = new APM_Import_Queue_Table_Renderer();
        $this->ajax_handler = new APM_Import_Queue_Ajax_Handler();
    }
    
    /**
     * Initialize manager
     */
    public function init() {
        // Initialize AJAX handler
        $this->ajax_handler->init();
        
        // Add hooks
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Create table on activation (called from main plugin file)
        register_activation_hook(APM_PLUGIN_DIR . 'auto-product-import.php', array($this, 'activate'));
    }
    
    /**
     * Activation hook
     */
    public function activate() {
        $this->database->create_table();
    }
    
    /**
     * Enqueue scripts and styles for import queue
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_scripts($hook) {
        // Only load on our import page
        if ($hook !== 'toplevel_page_apm-auto-product-import') {
            return;
        }
        
        wp_enqueue_script(
            'apm-import-queue',
            APM_PLUGIN_URL . 'assets/import-queue.js',
            array('jquery'),
            APM_VERSION,
            true
        );
        
        wp_localize_script('apm-import-queue', 'apmImportQueue', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auto-product-import-nonce'),
            'strings' => array(
                'processing' => __('Processing...', 'auto-product-import'),
                'pending' => __('Pending', 'auto-product-import'),
                'imported' => __('Imported', 'auto-product-import'),
                'error' => __('Error', 'auto-product-import'),
                'confirmLeave' => __('Batch import is in progress. Are you sure you want to leave?', 'auto-product-import'),
                'batchImport' => __('Batch Import', 'auto-product-import'),
                'stopImport' => __('Stop Import', 'auto-product-import'),
                'importing' => __('Importing...', 'auto-product-import')
            )
        ));
        
        // Add inline styles
        wp_add_inline_style('wp-admin', $this->get_inline_styles());
    }
    
    /**
     * Get inline styles for import queue
     * 
     * @return string CSS styles
     */
    private function get_inline_styles() {
        return '
            .apm-queue-table-container {
                background: #fff;
                border: 1px solid #ccc;
                border-radius: 5px;
                padding: 20px;
                margin-top: 20px;
                max-width: 100%;
            }
            
            .apm-queue-table-container h2 {
                margin-top: 0;
                margin-bottom: 15px;
            }
            
            .apm-no-products {
                color: #666;
                font-style: italic;
                margin: 10px 0;
            }
            
            .apm-queue-stats {
                background: #f0f0f1;
                padding: 10px 15px;
                border-radius: 4px;
                margin-bottom: 15px;
            }
            
            .apm-queue-stats p {
                margin: 0;
            }
            
            #apm-batch-import-table .status-pending,
            #apm-batch-import-table .status-processing,
            #apm-batch-import-table .status-imported,
            #apm-batch-import-table .status-error {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }
            
            #apm-batch-import-table .status-pending {
                background: #f0f0f1;
                color: #666;
            }
            
            #apm-batch-import-table .status-processing {
                background: #72aee6;
                color: #fff;
            }
            
            #apm-batch-import-table .status-imported {
                background: #00a32a;
                color: #fff;
            }
            
            #apm-batch-import-table .status-error {
                background: #d63638;
                color: #fff;
            }
            
            #apm-batch-import-table .processing-row {
                background-color: #f0f6fc !important;
            }
            
            #apm-batch-import-btn {
                margin-left: 10px;
            }
            
            #apm-batch-import-btn.importing {
                background: #d63638;
                border-color: #d63638;
            }
            
            #apm-batch-import-btn.importing:hover {
                background: #b52727;
                border-color: #b52727;
            }
            
            .apm-batch-progress {
                display: inline-block;
                margin-left: 10px;
                font-weight: 600;
                color: #2271b1;
            }
        ';
    }
    
    /**
     * Render import queue section on import page
     */
    public function render_queue_section() {
        // Sync products from apb_products on page load
        $this->database->sync_from_apb_products();
        
        // Verify imported products
        $this->database->verify_imported_products();
        
        ?>
        <div class="apm-import-queue-section">
            <?php $this->renderer->render_stats(); ?>
            
            <?php $this->renderer->render_batch_import_table(); ?>
            <?php $this->renderer->render_imported_table(); ?>
        </div>
        <?php
    }
}
