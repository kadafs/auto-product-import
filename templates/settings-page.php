<?php
/**
 * Settings page template
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

$data = APM_Template_Data::get_settings_data();
?>

<div class="wrap apm-settings-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form method="post" action="options.php" class="apm-settings-form">
        <?php
        settings_fields('auto_product_import_settings');
        do_settings_sections('auto_product_import_settings');
        ?>
        
        <table class="form-table apm-form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="auto_product_import_default_category">Default Product Category</label>
                    </th>
                    <td>
                        <select name="auto_product_import_default_category" id="auto_product_import_default_category" class="regular-text">
                            <option value="">Select a category</option>
                            <?php
                            if (!empty($data['categories']) && !is_wp_error($data['categories'])) {
                                foreach ($data['categories'] as $category) {
                                    $selected = ($data['default_category'] == $category->term_id) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($category->term_id) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <p class="description">Products will be assigned to this category by default.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="auto_product_import_default_status">Default Product Status</label>
                    </th>
                    <td>
                        <select name="auto_product_import_default_status" id="auto_product_import_default_status" class="regular-text">
                            <option value="draft" <?php selected($data['default_status'], 'draft'); ?>>Draft</option>
                            <option value="publish" <?php selected($data['default_status'], 'publish'); ?>>Published</option>
                        </select>
                        <p class="description">New products will be created with this status.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="auto_product_import_max_images">Maximum Images to Import</label>
                    </th>
                    <td>
                        <input type="number" 
                               name="auto_product_import_max_images" 
                               id="auto_product_import_max_images" 
                               value="<?php echo esc_attr($data['max_images']); ?>" 
                               min="1" 
                               max="100" 
                               class="small-text">
                        <p class="description">Maximum number of images to import per product (1-100).</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="auto_product_import_max_pdf_size">Maximum PDF Size (MB)</label>
                    </th>
                    <td>
                        <input type="number" 
                               name="auto_product_import_max_pdf_size" 
                               id="auto_product_import_max_pdf_size" 
                               value="<?php echo esc_attr($data['max_pdf_size']); ?>" 
                               min="1" 
                               max="100" 
                               class="small-text">
                        <p class="description">Maximum file size for PDF uploads in megabytes (1-100 MB).</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="auto_product_import_debug_domain">Debug Domain</label>
                    </th>
                    <td>
                        <input type="text" 
                               name="auto_product_import_debug_domain" 
                               id="auto_product_import_debug_domain" 
                               value="<?php echo esc_attr($data['debug_domain']); ?>" 
                               class="regular-text"
                               placeholder="example.com">
                        <p class="description">
                            Leave empty to enable detailed logging for ALL domains (when checkboxes below are enabled).<br>
                            Enter a domain (e.g., 'example.com') to only show detailed logs for that specific domain.
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Detailed Logging Options</th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><span>Detailed Logging Options</span></legend>
                            
                            <label for="auto_product_import_log_pdf">
                                <input type="checkbox" 
                                       name="auto_product_import_log_pdf" 
                                       id="auto_product_import_log_pdf" 
                                       value="yes" 
                                       <?php checked($data['log_pdf'], 'yes'); ?>>
                                Enable detailed PDF extraction logging
                            </label>
                            <br>
                            
                            <label for="auto_product_import_log_sku">
                                <input type="checkbox" 
                                       name="auto_product_import_log_sku" 
                                       id="auto_product_import_log_sku" 
                                       value="yes" 
                                       <?php checked($data['log_sku'], 'yes'); ?>>
                                Enable detailed SKU extraction logging
                            </label>
                            <br>
                            
                            <label for="auto_product_import_log_sync">
                                <input type="checkbox" 
                                       name="auto_product_import_log_sync" 
                                       id="auto_product_import_log_sync" 
                                       value="yes" 
                                       <?php checked($data['log_sync'], 'yes'); ?>>
                                Enable detailed sync field logging
                            </label>
                            
                            <p class="description">
                                Enable step-by-step detailed logging for specific features. Basic logging (errors, completion messages) is always enabled.
                            </p>
                        </fieldset>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <?php submit_button('Save Settings'); ?>
    </form>
</div>
