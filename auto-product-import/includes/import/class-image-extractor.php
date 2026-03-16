<?php
/**
 * Image Extractor class
 *
 * @package Auto_Product_Import
 * @since 2.1.2
 */

if (!defined('WPINC')) {
    die;
}

class APM_Image_Extractor {
    
    /**
     * Extract product images from HTML
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param bool $debug Whether to log debug info
     * @param string $html Optional HTML content for platform detection
     * @return array Array of image URLs
     */
    public function extract($xpath, $url, $debug = false, $html = '') {
        $images = array();
        $image_urls_set = array();
        
        if ($debug) {
            error_log("APM: ========== IMAGE EXTRACTION START ==========");
            error_log("APM: URL: $url");
            error_log("APM: HTML content length: " . strlen($html) . " bytes");
        }
        
        // Try Shopify-specific extraction first
        $tried_shopify = false;
        if (!empty($html)) {
            $shopify_extractor = new APM_Shopify_Extractor();
            
            if ($shopify_extractor->is_shopify_site($html, $url, $debug)) {
                $tried_shopify = true;
                if ($debug) {
                    error_log("APM: ✓ SHOPIFY SITE DETECTED - Using Shopify-specific extraction");
                }
                
                $shopify_extractor->extract($xpath, $url, $images, $image_urls_set, $debug);
                
                if (count($images) >= 3) {
                    if ($debug) {
                        error_log("APM: ✓ Shopify extraction SUCCESSFUL with " . count($images) . " images");
                    }
                    return $this->finalize_images($images, $debug);
                } elseif ($debug) {
                    error_log("APM: ⚠ Shopify extraction found only " . count($images) . " images, will try fallback methods");
                }
            } else if ($debug) {
                error_log("APM: ✗ Not a Shopify site - trying other methods");
            }
        } else if ($debug) {
            error_log("APM: ⚠ No HTML content provided for platform detection");
        }
        
        // Try BigCommerce extraction if not Shopify or if Shopify didn't find enough
        if ($debug) {
            error_log("APM: Trying BigCommerce extraction...");
        }
        $bigcommerce_extractor = new APM_BigCommerce_Extractor();
        $bigcommerce_extractor->extract($xpath, $url, $images, $image_urls_set, $debug);
        
        if (count($images) < 3) {
            if ($debug) {
                error_log("APM: Not enough images (" . count($images) . "), trying fallback methods");
            }
            $this->extract_fallback($xpath, $url, $images, $image_urls_set, $debug);
        }
        
        return $this->finalize_images($images, $debug);
    }
    
    /**
     * Finalize images array
     *
     * @param array $images Images array
     * @param bool $debug Debug mode flag
     * @return array Finalized images
     */
    private function finalize_images($images, $debug) {
        $images = array_unique($images);
        $images = array_values($images);
        
        if ($debug) {
            error_log("APM: ========== IMAGE EXTRACTION COMPLETE ==========");
            error_log("APM: TOTAL IMAGES FOUND: " . count($images));
            if (!empty($images)) {
                foreach (array_slice($images, 0, 3) as $index => $img) {
                    error_log("APM: Image #" . ($index + 1) . ": " . substr($img, 0, 150));
                }
                if (count($images) > 3) {
                    error_log("APM: ... and " . (count($images) - 3) . " more images");
                }
            } else {
                error_log("APM: ⚠ WARNING: NO IMAGES FOUND!");
            }
            error_log("APM: ===============================================");
        }
        
        return $images;
    }
    
    /**
     * Extract using fallback methods
     */
    private function extract_fallback($xpath, $url, &$images, &$image_urls_set, $debug) {
        $blacklisted_terms = apm_get_blacklisted_image_terms();
        $source_domain = parse_url($url, PHP_URL_HOST);
        
        if ($debug) {
            error_log("APM: Trying fallback: product containers (source domain: $source_domain)");
        }
        $this->extract_from_product_containers($xpath, $url, $images, $image_urls_set, $blacklisted_terms, $debug, $source_domain);
        
        if (count($images) < 3) {
            if ($debug) {
                error_log("APM: Trying fallback: main content");
            }
            $this->extract_from_main_content($xpath, $url, $images, $image_urls_set, $blacklisted_terms, $debug, $source_domain);
        }
        
        if (count($images) < 2) {
            if ($debug) {
                error_log("APM: Trying fallback: all images");
            }
            $this->extract_from_all_images($xpath, $url, $images, $image_urls_set, $blacklisted_terms, $debug, $source_domain);
        }
    }
    
    /**
     * Extract from product containers
     */
    private function extract_from_product_containers($xpath, $url, &$images, &$image_urls_set, $blacklisted_terms, $debug, $source_domain = '') {
        $selectors = array(
            '//div[contains(@class, "product-images")]//img',
            '//div[contains(@class, "product-gallery")]//img',
            '//div[contains(@id, "product-images")]//img',
            '//div[contains(@class, "product-detail")]//img',
            '//div[contains(@class, "product-media")]//img',
            '//div[contains(@class, "product-slider")]//img',
            '//div[contains(@class, "woocommerce-product-gallery")]//img'
        );
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            
            if ($nodes && $nodes->length > 0) {
                if ($debug) {
                    error_log("APM: Found " . $nodes->length . " nodes using selector: $selector");
                }
                
                foreach ($nodes as $node) {
                    if (apm_is_in_related_products_section($node, $debug)) {
                        continue;
                    }
                    $this->extract_and_filter_image($node, $images, $image_urls_set, $blacklisted_terms, $debug, $url, $source_domain);
                }
            }
        }
    }
    
    /**
     * Extract from main content
     */
    private function extract_from_main_content($xpath, $url, &$images, &$image_urls_set, $blacklisted_terms, $debug, $source_domain = '') {
        $selectors = array(
            '//div[contains(@class, "main-content")]//img',
            '//main//img',
            '//article//img',
            '//div[contains(@class, "content")]//img'
        );
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            
            if ($nodes && $nodes->length > 0) {
                if ($debug) {
                    error_log("APM: Found " . $nodes->length . " nodes using selector: $selector");
                }
                
                foreach ($nodes as $node) {
                    if (apm_is_in_related_products_section($node, $debug)) {
                        continue;
                    }
                    $this->extract_and_filter_image($node, $images, $image_urls_set, $blacklisted_terms, $debug, $url, $source_domain);
                }
            }
        }
    }
    
    /**
     * Extract from all images
     */
    private function extract_from_all_images($xpath, $url, &$images, &$image_urls_set, $blacklisted_terms, $debug, $source_domain = '') {
        $allImages = $xpath->query('//img');
        
        if ($allImages && $allImages->length > 0) {
            if ($debug) {
                error_log("APM: Found " . $allImages->length . " total images, filtering for product images");
            }
            
            foreach ($allImages as $node) {
                if (apm_is_in_related_products_section($node, $debug)) {
                    continue;
                }
                
                $this->extract_and_filter_image($node, $images, $image_urls_set, $blacklisted_terms, $debug, $url, $source_domain);
                
                if (count($images) >= 5) {
                    break;
                }
            }
        }
    }
    
    /**
     * Extract and filter image from node
     */
    public function extract_and_filter_image($node, &$images, &$image_urls_set, $blacklisted_terms, $debug, $base_url = '', $source_domain = '') {
        if (apm_is_in_related_products_section($node, $debug)) {
            return;
        }
        
        $img_url = '';
        $image_attributes = array('data-image-gallery-new-image-url', 'data-zoom-image', 'data-large', 'data-src', 'src');
        
        foreach ($image_attributes as $attr) {
            if (apm_dom_has_attribute($node, $attr)) {
                $img_url = apm_dom_get_attribute($node, $attr);
                break;
            }
        }
        
        if (empty($img_url)) {
            return;
        }
        
        $img_url = apm_convert_to_high_res($img_url);
        
        if (!empty($base_url) && strpos($img_url, 'http') !== 0) {
            $img_url = apm_make_url_absolute($img_url, $base_url);
        }
        
        // Normalize URL for duplicate detection
        $normalized_url = $this->normalize_url_for_dedup($img_url);
        
        if (apm_is_valid_product_image($img_url, $blacklisted_terms, $source_domain) && !isset($image_urls_set[$normalized_url])) {
            $images[] = $img_url;
            $image_urls_set[$normalized_url] = true;
            
            if ($debug) {
                error_log("APM: Added image: " . substr($img_url, 0, 150));
            }
        } elseif ($debug && isset($image_urls_set[$normalized_url])) {
            error_log("APM: Skipped duplicate image (normalized)");
        }
    }
    
    /**
     * Normalize URL for duplicate detection
     *
     * @param string $url The URL to normalize
     * @return string Normalized URL
     */
    private function normalize_url_for_dedup($url) {
        // Parse URL
        $url_parts = parse_url($url);
        if (!isset($url_parts['scheme']) || !isset($url_parts['host']) || !isset($url_parts['path'])) {
            return $url; // Return as-is if can't parse
        }
        
        $base = $url_parts['scheme'] . '://' . $url_parts['host'] . $url_parts['path'];
        
        // For Shopify/CDN URLs, keep only v= parameter
        if (isset($url_parts['query']) && (
            stripos($url, 'cdn.shopify.com') !== false ||
            stripos($url, '/cdn/shop/') !== false ||
            stripos($url, 'shopifycdn.com') !== false
        )) {
            parse_str($url_parts['query'], $params);
            if (isset($params['v'])) {
                return $base . '?v=' . $params['v'];
            }
        }
        
        return $base;
    }
}
