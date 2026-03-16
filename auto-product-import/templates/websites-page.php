<?php
/**
 * Websites page template.
 *
 * @since      2.1.0
 * @package    Auto_Product_Import
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wrap">
    <h1><?php _e('Websites Management', 'auto-product-import'); ?></h1>
    
    <div class="apm-admin">
        <!-- Website Section -->
        <div class="apm-websites-section">
            <h2><?php _e('Website', 'auto-product-import'); ?></h2>
            
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="apm-website-url"><?php _e('Website URL', 'auto-product-import'); ?></label>
                        </th>
                        <td>
                            <input 
                                type="url" 
                                id="apm-website-url" 
                                name="apm_website_url" 
                                class="regular-text" 
                                placeholder="https://example.com"
                                pattern="https?://.+"
                                title="<?php esc_attr_e('Please enter a valid URL starting with http:// or https://', 'auto-product-import'); ?>"
                            >
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" id="apm-add-website" class="button button-primary" disabled>
                                <?php _e('Add', 'auto-product-import'); ?>
                            </button>
                            <button type="button" id="apm-remove-website" class="button" disabled>
                                <?php _e('Remove', 'auto-product-import'); ?>
                            </button>
                            <p class="description"><?php _e('Button functionality coming soon.', 'auto-product-import'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Categories Section -->
        <div class="apm-categories-section">
            <h2><?php _e('Categories', 'auto-product-import'); ?></h2>
            
            <table class="wp-list-table widefat fixed striped apm-categories-table">
                <thead>
                    <tr>
                        <th class="apm-col-website"><?php _e('Website', 'auto-product-import'); ?></th>
                        <th class="apm-col-url"><?php _e('URL', 'auto-product-import'); ?></th>
                        <th class="apm-col-category"><?php _e('Category', 'auto-product-import'); ?></th>
                        <th class="apm-col-remove"><?php _e('Remove', 'auto-product-import'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Example Row 1 -->
                    <tr>
                        <td class="apm-col-website">Example Store</td>
                        <td class="apm-col-url">https://example.com</td>
                        <td class="apm-col-category">Electronics</td>
                        <td class="apm-col-remove">
                            <button type="button" class="button button-small" disabled>
                                <?php _e('Remove', 'auto-product-import'); ?>
                            </button>
                        </td>
                    </tr>
                    <!-- Example Row 2 -->
                    <tr>
                        <td class="apm-col-website">Sample Shop</td>
                        <td class="apm-col-url">https://sampleshop.com</td>
                        <td class="apm-col-category">Clothing</td>
                        <td class="apm-col-remove">
                            <button type="button" class="button button-small" disabled>
                                <?php _e('Remove', 'auto-product-import'); ?>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <p class="description" style="margin-top: 10px;">
                <?php _e('Example data shown above. Table functionality will be added in a future update.', 'auto-product-import'); ?>
            </p>
        </div>
    </div>
</div>

<style>
    .apm-websites-section,
    .apm-categories-section {
        background: #fff;
        border: 1px solid #ccc;
        border-radius: 5px;
        padding: 20px;
        margin-top: 20px;
    }
    
    .apm-websites-section h2,
    .apm-categories-section h2 {
        margin-top: 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #e5e5e5;
    }
    
    .apm-categories-table {
        margin-top: 20px;
    }
    
    /* Column widths - can be adjusted */
    .apm-col-website {
        width: 20%;
    }
    
    .apm-col-url {
        width: 40%;
    }
    
    .apm-col-category {
        width: 25%;
    }
    
    .apm-col-remove {
        width: 15%;
        text-align: center;
    }
    
    /* Make table resizable columns */
    .apm-categories-table th {
        position: relative;
        user-select: none;
    }
    
    .apm-categories-table th:not(:last-child)::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 5px;
        height: 100%;
        cursor: col-resize;
        background: transparent;
    }
    
    .apm-categories-table th:not(:last-child):hover::after {
        background: rgba(0, 0, 0, 0.1);
    }
    
    /* Button spacing */
    #apm-add-website {
        margin-right: 5px;
    }
    
    /* Disabled button styling */
    button[disabled] {
        opacity: 0.5;
        cursor: not-allowed;
    }
</style>

<script>
(function() {
    'use strict';
    
    // Add basic column resizing functionality
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.querySelector('.apm-categories-table');
        if (!table) return;
        
        const headers = table.querySelectorAll('th:not(:last-child)');
        let currentHeader = null;
        let startX = 0;
        let startWidth = 0;
        
        headers.forEach(function(header) {
            header.addEventListener('mousedown', function(e) {
                // Only activate if clicking near the right edge (last 5px)
                const rect = header.getBoundingClientRect();
                if (e.clientX > rect.right - 5) {
                    currentHeader = header;
                    startX = e.clientX;
                    startWidth = header.offsetWidth;
                    
                    document.addEventListener('mousemove', handleMouseMove);
                    document.addEventListener('mouseup', handleMouseUp);
                    
                    e.preventDefault();
                }
            });
        });
        
        function handleMouseMove(e) {
            if (!currentHeader) return;
            
            const diff = e.clientX - startX;
            const newWidth = startWidth + diff;
            
            if (newWidth > 50) { // Minimum width
                currentHeader.style.width = newWidth + 'px';
            }
        }
        
        function handleMouseUp() {
            currentHeader = null;
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);
        }
    });
})();
</script>