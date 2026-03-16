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
    private $specifications_tab_creator;
    
    public function __construct() {
        $this->image_uploader = new APM_Image_Uploader();
        $this->pdf_uploader = new APM_PDF_Uploader();
        $this->sync_fields = new APM_Product_Creator_Sync_Fields();
        $this->specifications_tab_creator = new APM_Specifications_Tab_Creator();
        // Documents tab creator removed in v2.2.2 - PDFs still uploaded but no tab created
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

        // For eastwesteng URLs the extractor has already applied GST (×1.1).
        // Skip generic GST detection to avoid double-GST, and apply 15% margin instead.
        $is_eastwesteng = APM_EastWestEng_Extractor::is_eastwesteng_url($source_url);

        if ($is_eastwesteng) {
            // Price from extractor is GST-inclusive; apply 15% margin for the creation price.
            if (!empty($product_data['price'])) {
                $price_with_margin = round(floatval($product_data['price']) * 1.15, 2);
                $product->set_regular_price($price_with_margin);

                if ($debug) {
                    error_log("APM: eastwesteng — GST-inclusive price: " . $product_data['price'] . ", after 15% margin: $price_with_margin");
                }

                error_log("APM: eastwesteng price set with 15% margin: $" . $product_data['price'] . " → $$price_with_margin");
            }
            $gst_info = array('add_gst' => 'yes');
        } else {
            // Detect if we should add GST and set price
            $gst_info = $this->sync_fields->detect_and_apply_gst($product_data, $product, $debug);
        }
        
        // Set SKU - Use extracted SKU or generate one
        $this->set_product_sku($product, $product_data, $debug);
        
        // Set description
        if (!empty($product_data['description'])) {
            $product->set_description($product_data['description']);
        }
        
        if (!empty($product_data['short_description'])) {
            $product->set_short_description($product_data['short_description']);
        }
        
        // Category is set after initial save (domain-based, see below)

        // Save product to get ID
        $product_id = $product->save();

        if ($debug) {
            error_log("APM: Product created with ID: $product_id");
        }

        // Set domain-based category (replaces default category setting)
        if (!empty($source_url)) {
            $domain_cat_id = $this->get_or_create_domain_category($source_url, $debug);
            if ($domain_cat_id) {
                wp_set_object_terms($product_id, array($domain_cat_id), 'product_cat');
            } elseif (!empty($default_category)) {
                // Fallback to default category only if domain category creation fails
                wp_set_object_terms($product_id, array((int) $default_category), 'product_cat');
            }
        } elseif (!empty($default_category)) {
            wp_set_object_terms($product_id, array((int) $default_category), 'product_cat');
        }

        // Add domain-based product tag
        if (!empty($source_url)) {
            $this->add_domain_tag($product_id, $source_url, $debug);
        }
        
        // Set Auto Product Sync fields
        $this->sync_fields->set_sync_fields($product_id, $product_data, $gst_info['add_gst'], $debug);

        // Apply eastwesteng hard-coded defaults (Add GST, Add Margin, Margin %)
        if ($is_eastwesteng) {
            $this->sync_fields->apply_eastwesteng_defaults($product_id, $debug);
        }

        // Upload and attach images
        $this->attach_images($product, $product_id, $product_data, $debug);
        
        // Upload and attach PDFs to media library (no Documents tab created in v2.2.2)
        $uploaded_pdf_ids = $this->attach_pdfs($product_id, $product_data, $debug);
        
        // Documents tab creation removed in v2.2.2
        // PDFs are uploaded to media library but no tab is created
        
        // Create Specifications tab - AFTER PDFs, BEFORE COMPLETION
        $this->create_specifications_tab($product_id, $product_data, $debug);
        
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
     * Attach PDFs to product (media library only, no Documents tab in v2.2.2)
     * Returns array of uploaded attachment IDs for potential future use
     *
     * @param int $product_id The product ID
     * @param array $product_data The scraped product data
     * @param bool $debug Debug mode flag
     * @return array Array of uploaded PDF attachment IDs
     */
    private function attach_pdfs($product_id, $product_data, $debug) {
        $uploaded_pdf_ids = array();
        
        if (!empty($product_data['pdfs'])) {
            if ($debug) {
                error_log("APM: Starting PDF upload. Total PDFs: " . count($product_data['pdfs']));
            }
            
            foreach ($product_data['pdfs'] as $pdf) {
                $pdf_url = isset($pdf['url']) ? $pdf['url'] : '';
                $caption = isset($pdf['caption']) ? $pdf['caption'] : '';
                
                if (!empty($pdf_url)) {
                    $attachment_id = $this->pdf_uploader->upload($pdf_url, $caption, $product_id, $debug);
                    
                    if ($attachment_id) {
                        $uploaded_pdf_ids[] = $attachment_id;
                        
                        if ($debug) {
                            error_log("APM: ✓ PDF uploaded successfully (ID: $attachment_id)");
                        }
                    } else {
                        if ($debug) {
                            error_log("APM: ⚠ PDF upload failed for: $pdf_url - skipping");
                        }
                    }
                }
            }
            
            if ($debug) {
                error_log("APM: PDF upload complete. Uploaded: " . count($uploaded_pdf_ids) . " PDFs (v2.2.2: no Documents tab created)");
            }
        }
        
        return $uploaded_pdf_ids;
    }
    
    /**
     * Extract domain-derived names from a source URL.
     *
     * Category name : first label of the domain (strips www. prefix and all
     *                 TLD segments from the second-to-last dot onward).
     *   e.g. www.eastwesteng.com.au  →  eastwesteng
     *        supplier.com            →  supplier
     *        example.co.uk           →  example
     *
     * Tag name      : full domain with www. prefix removed.
     *   e.g. www.eastwesteng.com.au  →  eastwesteng.com.au
     *
     * @param  string $url Source URL
     * @return array|null  Associative array with keys 'category_name', 'tag_name', 'domain', or null on failure
     */
    private function extract_domain_info($url) {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return null;
        }

        // Remove www. prefix
        $domain = preg_replace('/^www\./i', '', $host);

        // Tag: full domain without www
        $tag_name = $domain;

        // Category: first label only (everything before the first dot)
        $parts         = explode('.', $domain);
        $category_name = $parts[0];

        return array(
            'category_name' => $category_name,
            'tag_name'      => $tag_name,
            'domain'        => $domain,
        );
    }

    /**
     * Find or create the domain-based product category under:
     *   Uncategorised > Hidden > [category_name]
     *
     * Auto-creates "Hidden" under "Uncategorised" if it doesn't exist.
     *
     * @param  string $source_url URL the product was imported from
     * @param  bool   $debug
     * @return int|null  Term ID of the domain category, or null on failure
     */
    private function get_or_create_domain_category($source_url, $debug = false) {
        $info = $this->extract_domain_info($source_url);
        if (!$info) {
            if ($debug) {
                error_log("APM: Domain category — could not parse host from URL: {$source_url}");
            }
            return null;
        }

        $category_name = $info['category_name'];
        error_log("APM: Domain category derivation — URL: {$source_url} → category name: \"{$category_name}\"");

        // 1. Find "Uncategorised" via WooCommerce's stored default category ID.
        //    Using get_option() avoids slug/locale issues (e.g. 'uncategorised' vs 'uncategorized').
        $uncategorised_id = (int) get_option('default_product_cat');
        if (!$uncategorised_id) {
            error_log("APM: WARNING — default_product_cat option not set. Cannot create domain category for URL: {$source_url}");
            return null;
        }
        $uncategorised = get_term($uncategorised_id, 'product_cat');
        if (!$uncategorised || is_wp_error($uncategorised)) {
            error_log("APM: WARNING — default product category (ID: {$uncategorised_id}) not found in product_cat taxonomy. Cannot create domain category for URL: {$source_url}");
            return null;
        }
        if ($debug) {
            error_log("APM: Domain category — found default/Uncategorised category \"{$uncategorised->name}\" (ID: {$uncategorised_id}, slug: {$uncategorised->slug})");
        }

        // 2. Find or create "Hidden" directly under Uncategorised
        $hidden_terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'name'       => 'Hidden',
            'parent'     => $uncategorised_id,
            'hide_empty' => false,
            'number'     => 1,
        ));

        if (!empty($hidden_terms) && !is_wp_error($hidden_terms)) {
            $hidden_id = (int) $hidden_terms[0]->term_id;
            if ($debug) {
                error_log("APM: Domain category — found existing 'Hidden' category (ID: {$hidden_id}) under 'Uncategorised'");
            }
        } else {
            $result = wp_insert_term('Hidden', 'product_cat', array('parent' => $uncategorised_id));
            if (is_wp_error($result)) {
                error_log("APM: Domain category — failed to create 'Hidden' under 'Uncategorised': " . $result->get_error_message());
                return null;
            }
            $hidden_id = (int) $result['term_id'];
            error_log("APM: Domain category — created 'Hidden' category (ID: {$hidden_id}) under 'Uncategorised'");
        }

        // 3. Find or create domain category under Hidden
        $domain_terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'name'       => $category_name,
            'parent'     => $hidden_id,
            'hide_empty' => false,
            'number'     => 1,
        ));

        if (!empty($domain_terms) && !is_wp_error($domain_terms)) {
            $domain_cat_id = (int) $domain_terms[0]->term_id;
            if ($debug) {
                error_log("APM: Domain category — found existing category \"{$category_name}\" (ID: {$domain_cat_id}) under 'Hidden'");
            }
        } else {
            $result = wp_insert_term($category_name, 'product_cat', array('parent' => $hidden_id));
            if (is_wp_error($result)) {
                error_log("APM: Domain category — failed to create \"{$category_name}\" under 'Hidden': " . $result->get_error_message());
                return null;
            }
            $domain_cat_id = (int) $result['term_id'];
            error_log("APM: Domain category — created category \"{$category_name}\" (ID: {$domain_cat_id}) under Uncategorised > Hidden");
        }

        return $domain_cat_id;
    }

    /**
     * Find or create a domain-based product tag and attach it to the product.
     *
     * Tag is the domain name with the www. prefix removed.
     *   e.g. www.eastwesteng.com.au  →  tag: "eastwesteng.com.au"
     *
     * @param int    $product_id
     * @param string $source_url URL the product was imported from
     * @param bool   $debug
     */
    private function add_domain_tag($product_id, $source_url, $debug = false) {
        $info = $this->extract_domain_info($source_url);
        if (!$info) {
            if ($debug) {
                error_log("APM: Domain tag — could not parse host from URL: {$source_url}");
            }
            return;
        }

        $tag_name = $info['tag_name'];
        error_log("APM: Domain tag derivation — URL: {$source_url} → tag name: \"{$tag_name}\"");

        // Find or create the tag
        $existing = get_term_by('name', $tag_name, 'product_tag');
        if ($existing && !is_wp_error($existing)) {
            $tag_id = (int) $existing->term_id;
            if ($debug) {
                error_log("APM: Domain tag — found existing tag \"{$tag_name}\" (ID: {$tag_id})");
            }
        } else {
            $result = wp_insert_term($tag_name, 'product_tag');
            if (is_wp_error($result)) {
                error_log("APM: Domain tag — failed to create tag \"{$tag_name}\": " . $result->get_error_message());
                return;
            }
            $tag_id = (int) $result['term_id'];
            error_log("APM: Domain tag — created tag \"{$tag_name}\" (ID: {$tag_id})");
        }

        // Append tag to product (true = append, don't replace existing tags)
        wp_set_object_terms($product_id, array($tag_id), 'product_tag', true);

        if ($debug) {
            error_log("APM: Domain tag — attached tag \"{$tag_name}\" (ID: {$tag_id}) to product {$product_id}");
        }
    }

    /**
     * Create Specifications tab for product
     *
     * @param int $product_id The product ID
     * @param array $product_data The scraped product data
     * @param bool $debug Debug mode flag
     */
    private function create_specifications_tab($product_id, $product_data, $debug) {
        // Check if specifications were extracted
        if (isset($product_data['specifications'])) {
            $this->specifications_tab_creator->create_tab(
                $product_id,
                $product_data['specifications'],
                $debug
            );
        } elseif ($debug) {
            error_log("APM: No specifications data in product data");
        }
    }
}
