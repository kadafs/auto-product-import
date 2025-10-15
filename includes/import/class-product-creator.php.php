<?php
/**
 * Product Creator class - Main orchestrator
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

class APM_Product_Creator {
    
    private $image_uploader;
    private $pdf_uploader;
    private $sync_fields;
    
    public function __construct() {
        $this->image_uploader = new APM_Image_Uploader();
        $this->pdf_uploader = new APM_PDF_Uploader();
        $this->sync_fields = new APM_Product_Creator_Sync_Fields();
    }
    
    /**
     * Create product from scraped data
     *
     * @param array $product_data The scraped product data
     * @param bool $debug Whether to enable debug logging
     * @return int Product ID
     */
    public function create($product_data, $debug = false) {
        if ($debug) {
            error_log("APM: Starting product creation");
        }
        
        // Get settings
        $default_category = get_option('auto_product_import_default_category');
        $default_status = get_option('auto_product_import_default_status', 'draft');
        
        // Create WooCommerce product
        $product = new WC_Product_Simple();
        
        // Set basic product data
        $product->set_name($product_data['title']);
        $product->set_status($default_status);
        
        // Get source URL for GST detection (needed before setting price)
        $source_url = isset($product_data['source_url']) ? $product_data['source_url'] : '';
        
        // Detect if we should add GST and set price
        $gst_info = $this->sync_fields->detect_and_apply_gst($product_data, $product, $debug);
        
        // Set SKU - Use extracted SKU or generate one
        $this->set_product_sku($product, $product_data, $debug);
        
        // Set description
        if (!empty($product_data['description'])) {
            $product->set_description($product_data['description']);
        }
        
        if (!empty($product_data['short_description'])) {
            $product->set_short_description($product_data['short_description']);
        }
        
        // Set category
        if (!empty($default_category)) {
            $product->set_category_ids(array($default_category));
        }
        
        // Save product to get ID
        $product_id = $product->save();
        
        if ($debug) {
            error_log("APM: Product created with ID: $product_id");
        }
        
        // Set Auto Product Sync fields
        $this->sync_fields->set_sync_fields($product_id, $product_data, $gst_info['add_gst'], $debug);
        
        // Upload and attach images
        $this->attach_images($product, $product_id, $product_data, $debug);
        
        // Upload and attach PDFs
        $this->attach_pdfs($product_id, $product_data, $debug);
        
        // Add additional product information as meta data
        if (!empty($product_data['additional_info'])) {
            foreach ($product_data['additional_info'] as $key => $value) {
                update_post_meta($product_id, '_additional_' . sanitize_key($key), $value);
            }
        }
        
        if ($debug) {
            error_log("APM: Product creation complete. Product ID: $product_id");
        }
        
        return $product_id;
    }
    
    /**
     * Set product SKU with duplicate detection
     */
    private function set_product_sku($product, $product_data, $debug) {
        if (!empty($product_data['sku'])) {
            // Check if SKU already exists
            $sku_exists = wc_get_product_id_by_sku($product_data['sku']);
            
            if ($sku_exists) {
                // SKU is duplicate - generate a new one
                $generated_sku = 'API-' . rand(1000, 9999);
                $product->set_sku($generated_sku);
                
                // BASIC LOGGING - Always show duplicate
                error_log("APM: Duplicate SKU detected (" . $product_data['sku'] . ") - Generated fallback SKU: $generated_sku");
                
                if ($debug) {
                    error_log("APM: SKU '" . $product_data['sku'] . "' already exists (Product ID: $sku_exists), using generated SKU: $generated_sku");
                }
            } else {
                // SKU is unique - use it
                $product->set_sku($product_data['sku']);
                
                if ($debug) {
                    error_log("APM: Using extracted SKU: " . $product_data['sku']);
                }
            }
        } else {
            // Generate SKU if extraction failed
            $generated_sku = 'API-' . rand(1000, 9999);
            $product->set_sku($generated_sku);
            
            // BASIC LOGGING - Always show fallback
            error_log("APM: SKU extraction failed - Generated fallback SKU: $generated_sku");
            
            if ($debug) {
                error_log("APM: No SKU found in scraped data, generated: $generated_sku");
            }
        }
    }
    
    /**
     * Attach images to product
     */
    private function attach_images($product, $product_id, $product_data, $debug) {
        if (!empty($product_data['images'])) {
            if ($debug) {
                error_log("APM: Starting image upload. Total images: " . count($product_data['images']));
            }
            
            $uploaded_images = array();
            
            foreach ($product_data['images'] as $image_url) {
                $attachment_id = $this->image_uploader->upload($image_url, $product_id, $debug);
                
                if ($attachment_id) {
                    $uploaded_images[] = $attachment_id;
                }
            }
            
            if (!empty($uploaded_images)) {
                // Set the first image as the product image
                $product->set_image_id($uploaded_images[0]);
                
                // Set remaining images as gallery images
                if (count($uploaded_images) > 1) {
                    $gallery_ids = array_slice($uploaded_images, 1);
                    $product->set_gallery_image_ids($gallery_ids);
                }
                
                $product->save();
                
                if ($debug) {
                    error_log("APM: Uploaded and attached " . count($uploaded_images) . " images");
                }
            }
        }
    }
    
    /**
     * Attach PDFs to product
     */
    private function attach_pdfs($product_id, $product_data, $debug) {
        if (!empty($product_data['pdfs'])) {
            if ($debug) {
                error_log("APM: Starting PDF upload. Total PDFs: " . count($product_data['pdfs']));
            }
            
            $uploaded_pdfs = array();
            
            foreach ($product_data['pdfs'] as $pdf) {
                $pdf_url = isset($pdf['url']) ? $pdf['url'] : '';
                $caption = isset($pdf['caption']) ? $pdf['caption'] : '';
                
                if (!empty($pdf_url)) {
                    $attachment_id = $this->pdf_uploader->upload($pdf_url, $caption, $product_id, $debug);
                    
                    if ($attachment_id) {
                        $uploaded_pdfs[] = $attachment_id;
                    }
                }
            }
            
            if ($debug) {
                error_log("APM: PDF upload complete. Uploaded: " . count($uploaded_pdfs) . " PDFs");
            }
        }
    }
}
