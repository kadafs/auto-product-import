<?php
/**
 * Import Queue Table Renderer class
 *
 * Renders the import queue tables
 *
 * @package Auto_Product_Import
 * @since 2.2.0
 */

if (!defined('WPINC')) {
    die;
}

class APM_Import_Queue_Table_Renderer {
    
    private $database;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new APM_Import_Queue_Database();
    }
    
    /**
     * Render batch import table
     */
    public function render_batch_import_table() {
        $products = $this->database->get_batch_import_products();
        
        ?>
        <div class="apm-queue-table-container">
            <h2><?php _e('Batch Import', 'auto-product-import'); ?></h2>
            
            <?php if (empty($products)): ?>
                <p class="apm-no-products"><?php _e('No products to import', 'auto-product-import'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" id="apm-batch-import-table">
                    <thead>
                        <tr>
                            <th class="column-domain"><?php _e('Domain', 'auto-product-import'); ?></th>
                            <th class="column-product"><?php _e('Product', 'auto-product-import'); ?></th>
                            <th class="column-url"><?php _e('URL', 'auto-product-import'); ?></th>
                            <th class="column-status" style="width: 100px;"><?php _e('Status', 'auto-product-import'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr data-queue-id="<?php echo esc_attr($product->id); ?>">
                                <td class="column-domain"><?php echo esc_html($product->domain); ?></td>
                                <td class="column-product"><?php echo esc_html($product->product); ?></td>
                                <td class="column-url">
                                    <a href="<?php echo esc_url($product->url); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html(substr($product->url, 0, 50)) . '...'; ?>
                                    </a>
                                </td>
                                <td class="column-status">
                                    <span class="status-pending"><?php _e('Pending', 'auto-product-import'); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render imported products table with sorting
     */
    public function render_imported_table() {
        $products = $this->database->get_imported_products();
        
        // Get current sort parameters
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'domain';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'asc';
        
        // Sort products
        if (!empty($products)) {
            usort($products, function($a, $b) use ($orderby, $order) {
                $result = 0;
                
                switch ($orderby) {
                    case 'product':
                        $result = strcmp($a->product, $b->product);
                        break;
                    case 'domain':
                    default:
                        $result = strcmp($a->domain, $b->domain);
                        break;
                }
                
                return $order === 'desc' ? -$result : $result;
            });
        }
        
        ?>
        <div class="apm-queue-table-container">
            <h2><?php _e('Imported', 'auto-product-import'); ?></h2>
            
            <?php if (empty($products)): ?>
                <p class="apm-no-products"><?php _e('No products imported yet', 'auto-product-import'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" id="apm-imported-table">
                    <thead>
                        <tr>
                            <th class="column-domain sortable <?php echo $orderby === 'domain' ? $order : 'desc'; ?>">
                                <a href="<?php echo esc_url($this->get_sort_url('domain', $orderby, $order)); ?>">
                                    <span><?php _e('Domain', 'auto-product-import'); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                            </th>
                            <th class="column-product sortable <?php echo $orderby === 'product' ? $order : 'desc'; ?>">
                                <a href="<?php echo esc_url($this->get_sort_url('product', $orderby, $order)); ?>">
                                    <span><?php _e('Product', 'auto-product-import'); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                            </th>
                            <th class="column-edit" style="width: 80px;"><?php _e('Edit', 'auto-product-import'); ?></th>
                            <th class="column-view" style="width: 80px;"><?php _e('View', 'auto-product-import'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr data-queue-id="<?php echo esc_attr($product->id); ?>" data-product-id="<?php echo esc_attr($product->product_id); ?>">
                                <td class="column-domain"><?php echo esc_html($product->domain); ?></td>
                                <td class="column-product"><?php echo esc_html($product->product); ?></td>
                                <td class="column-edit">
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $product->product_id . '&action=edit')); ?>" 
                                       class="button button-small" 
                                       target="_blank">
                                        <?php _e('Edit', 'auto-product-import'); ?>
                                    </a>
                                </td>
                                <td class="column-view">
                                    <a href="<?php echo esc_url(get_permalink($product->product_id)); ?>" 
                                       class="button button-small" 
                                       target="_blank">
                                        <?php _e('View', 'auto-product-import'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Get sort URL
     * 
     * @param string $column Column to sort by
     * @param string $current_orderby Current orderby
     * @param string $current_order Current order
     * @return string Sort URL
     */
    private function get_sort_url($column, $current_orderby, $current_order) {
        $new_order = 'asc';
        
        if ($current_orderby === $column && $current_order === 'asc') {
            $new_order = 'desc';
        }
        
        return add_query_arg(array(
            'orderby' => $column,
            'order' => $new_order
        ));
    }
    
    /**
     * Render statistics
     */
    public function render_stats() {
        $stats = $this->database->get_stats();
        
        ?>
        <div class="apm-queue-stats">
            <p>
                <strong><?php _e('Total:', 'auto-product-import'); ?></strong> <?php echo $stats['total']; ?> | 
                <strong><?php _e('Pending:', 'auto-product-import'); ?></strong> <?php echo $stats['pending']; ?> | 
                <strong><?php _e('Imported:', 'auto-product-import'); ?></strong> <?php echo $stats['imported']; ?> | 
                <strong><?php _e('Errors:', 'auto-product-import'); ?></strong> <?php echo $stats['errors']; ?>
            </p>
        </div>
        <?php
    }
}
