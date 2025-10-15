<?php
/**
 * Import page template.
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
    <h1><?php _e('Import Product', 'auto-product-import'); ?></h1>
    
    <div class="apm-admin">
        <div class="apm-import-container">
            <div class="apm-import-form-container">
                <h2><?php _e('Import Product from URL', 'auto-product-import'); ?></h2>
                <p><?php _e('Enter a URL below to automatically import product data.', 'auto-product-import'); ?></p>
                
                <div id="apm-import-message" class="notice" style="display: none;"></div>
                
                <form id="apm-import-form" class="apm-import-form">
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="apm-product-url"><?php _e('Product URL', 'auto-product-import'); ?></label></th>
                                <td>
                                    <input type="url" name="product-url" id="apm-product-url" class="regular-text" required>
                                    <p class="description"><?php _e('Enter the URL of the product page you want to import.', 'auto-product-import'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" id="apm-import-submit" class="button button-primary">
                            <?php _e('Import Product', 'auto-product-import'); ?>
                        </button>
                        <span class="spinner" style="float: none; margin-top: 0;"></span>
                    </p>
                </form>
                
                <div id="apm-import-result" style="display: none;">
                    <h3><?php _e('Import Result', 'auto-product-import'); ?></h3>
                    <div id="apm-import-result-content"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .apm-import-container {
        margin-top: 20px;
    }
    
    .apm-import-form-container {
        background: #fff;
        border: 1px solid #ccc;
        border-radius: 5px;
        padding: 20px;
        max-width: 800px;
    }
    
    .apm-import-form-container h2 {
        margin-top: 0;
    }
    
    #apm-import-result {
        margin-top: 20px;
        padding: 15px;
        background: #f8f8f8;
        border-radius: 4px;
    }
    
    .spinner.is-active {
        visibility: visible;
        display: inline-block;
    }
</style>