<?php
/**
 * Shopify Extractor class
 *
 * @package Auto_Product_Import
 * @since 2.1.2
 */

if (!defined('WPINC')) {
    die;
}

class APM_Shopify_Extractor {
    
    /**
     * Detect if URL/HTML is from a Shopify site
     *
     * @param string $html The HTML content
     * @param string $url The page URL
     * @param bool $debug Debug mode flag
     * @return bool True if Shopify site detected
     */
    public function is_shopify_site($html, $url = '', $debug = false) {
        $is_shopify = false;
        
        // Check for Shopify in HTML content
        if (stripos($html, 'shopify') !== false) {
            // Look for common Shopify indicators
            $shopify_indicators = array(
                'Shopify.theme',
                'shopify-features',
                'cdn.shopify.com',
                'shopifycdn.com',
                'Shopify.shop',
                'shopify-section',
                'data-shopify',
                'shopify-product',
                'Shopify.routes'
            );
            
            foreach ($shopify_indicators as $indicator) {
                if (stripos($html, $indicator) !== false) {
                    $is_shopify = true;
                    if ($debug) {
                        error_log("APM: Shopify detected via indicator: $indicator");
                    }
                    break;
                }
            }
        }
        
        // Check URL patterns
        if (!$is_shopify && !empty($url)) {
            if (stripos($url, '.myshopify.com') !== false || 
                preg_match('/\/collections\/.*\/products\//i', $url)) {
                $is_shopify = true;
                if ($debug) {
                    error_log("APM: Shopify detected via URL pattern");
                }
            }
        }
        
        if ($debug) {
            error_log("APM: Shopify detection result: " . ($is_shopify ? 'YES' : 'NO'));
        }
        
        return $is_shopify;
    }
    
    /**
     * Extract images using Shopify-specific selectors
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param array &$images Reference to images array
     * @param array &$image_urls_set Reference to image URLs set
     * @param bool $debug Debug mode flag
     */
    public function extract($xpath, $url, &$images, &$image_urls_set, $debug) {
        if ($debug) {
            error_log("APM: Starting Shopify-specific image extraction");
        }
        
        // Get source domain for filtering
        $source_domain = parse_url($url, PHP_URL_HOST);
        
        // Shopify-specific selectors - ordered by priority
        $selectors = array(
            // Shopify 2.0+ themes
            '//product-media//img',
            '//media-gallery//img',
            '//slider-component//img',
            // Common Shopify gallery structures
            '//div[contains(@class, "product__media-list")]//img',
            '//div[contains(@class, "product-media-gallery")]//img',
            '//div[contains(@class, "product__media-wrapper")]//img',
            '//div[contains(@class, "product__media")]//img',
            '//div[contains(@class, "product__main-photos")]//img',
            // Older Shopify themes
            '//div[contains(@class, "product-single__photos")]//img',
            '//div[contains(@class, "product-single__photo")]//img',
            '//ul[contains(@class, "product-single__thumbnails")]//img',
            // Generic product galleries
            '//div[contains(@class, "product-gallery")]//img',
            '//div[contains(@class, "product-photos")]//img',
            '//div[contains(@class, "product-images")]//img',
            // Thumbnail navigation
            '//div[contains(@class, "thumbnail-list")]//img',
            '//div[contains(@class, "product-thumbnails")]//img',
            // Media with data attributes
            '//div[@data-media-id]//img',
            '//div[contains(@id, "MediaGallery")]//img',
            // Any img in product-specific containers
            '//div[contains(@class, "product")]//img[contains(@src, "product") or contains(@src, "products")]',
            '//div[@id="product"]//img'
        );
        
        $blacklisted_terms = apm_get_blacklisted_image_terms();
        $found_images = 0;
        $initial_count = count($images);
        
        foreach ($selectors as $index => $selector) {
            $nodes = $xpath->query($selector);
            
            if ($nodes && $nodes->length > 0) {
                if ($debug) {
                    error_log("APM: Selector #" . ($index + 1) . " found " . $nodes->length . " nodes: $selector");
                }
                
                foreach ($nodes as $node) {
                    // Skip images in related products section
                    if (apm_is_in_related_products_section($node, $debug)) {
                        if ($debug) {
                            error_log("APM: Skipped image in related products section");
                        }
                        continue;
                    }
                    
                    // Extract image using Shopify-specific attributes
                    if ($this->extract_shopify_image($node, $url, $images, $image_urls_set, $blacklisted_terms, $debug, $source_domain)) {
                        $found_images++;
                    }
                }
                
                // If we found enough images, stop searching
                if ($found_images >= 15) {
                    if ($debug) {
                        error_log("APM: Found sufficient images ($found_images), stopping Shopify extraction");
                    }
                    break;
                }
            } else if ($debug && $index < 5) {
                error_log("APM: Selector #" . ($index + 1) . " found 0 nodes: $selector");
            }
        }
        
        if ($debug) {
            $new_images = count($images) - $initial_count;
            error_log("APM: Shopify extraction complete. Added $new_images new images (Total: " . count($images) . ")");
        }
    }
    
    /**
     * Extract image from Shopify node
     *
     * @param DOMElement $node The DOM node
     * @param string $base_url The base URL
     * @param array &$images Reference to images array
     * @param array &$image_urls_set Reference to image URLs set
     * @param array $blacklisted_terms Blacklisted terms
     * @param bool $debug Debug mode flag
     * @param string $source_domain Source domain
     * @return bool True if image was extracted
     */
    private function extract_shopify_image($node, $base_url, &$images, &$image_urls_set, $blacklisted_terms, $debug, $source_domain = '') {
        $img_url = '';
        $extracted = false;
        
        // Priority order for Shopify image attributes
        $image_attributes = array(
            'data-srcset',      // Shopify responsive images (highest priority)
            'srcset',           // Standard responsive images
            'data-src',         // Lazy loaded images
            'data-zoom-src',    // Zoom images (usually high-res)
            'data-zoom',        // Alternative zoom attribute
            'data-image',       // Custom data attribute
            'data-full-src',    // Full size image
            'src'               // Fallback to standard src
        );
        
        foreach ($image_attributes as $attr) {
            if (apm_dom_has_attribute($node, $attr)) {
                $attr_value = trim(apm_dom_get_attribute($node, $attr));
                
                if (!empty($attr_value)) {
                    // Handle srcset format (get highest resolution)
                    if ($attr === 'data-srcset' || $attr === 'srcset') {
                        $img_url = $this->extract_highest_res_from_srcset($attr_value, $debug);
                    } else {
                        $img_url = $attr_value;
                    }
                    
                    if (!empty($img_url)) {
                        if ($debug) {
                            error_log("APM: Found image candidate from '$attr': " . substr($img_url, 0, 100));
                        }
                        break;
                    }
                }
            }
        }
        
        if (empty($img_url)) {
            return false;
        }
        
        // Clean up the URL
        $img_url = trim($img_url);
        
        // Convert to absolute URL if needed
        if (strpos($img_url, 'http') !== 0) {
            // Handle protocol-relative URLs
            if (strpos($img_url, '//') === 0) {
                $img_url = 'https:' . $img_url;
            } else {
                $img_url = apm_make_url_absolute($img_url, $base_url);
            }
        }
        
        // Convert to highest resolution for Shopify CDN
        $img_url = $this->convert_to_shopify_high_res($img_url, $debug);
        
        // Normalize URL for duplicate detection (remove query params except v=)
        $normalized_url = $this->normalize_shopify_url($img_url);
        
        // Validate and add image
        if (apm_is_valid_product_image($img_url, $blacklisted_terms, $source_domain) && !isset($image_urls_set[$normalized_url])) {
            $images[] = $img_url;
            $image_urls_set[$normalized_url] = true;
            $extracted = true;
            
            if ($debug) {
                error_log("APM: ✓ Successfully added Shopify image: " . substr($img_url, 0, 150));
            }
        } elseif ($debug) {
            if (isset($image_urls_set[$normalized_url])) {
                error_log("APM: ✗ Skipped duplicate image");
            } else {
                error_log("APM: ✗ Image failed validation: " . substr($img_url, 0, 100));
            }
        }
        
        return $extracted;
    }
    
    /**
     * Extract highest resolution image from srcset
     *
     * @param string $srcset The srcset attribute value
     * @param bool $debug Debug mode flag
     * @return string Highest resolution image URL
     */
    private function extract_highest_res_from_srcset($srcset, $debug) {
        // srcset format: "url1 100w, url2 500w, url3 1000w"
        $sources = explode(',', $srcset);
        $highest_res = 0;
        $highest_url = '';
        
        foreach ($sources as $source) {
            $source = trim($source);
            // Split by whitespace
            $parts = preg_split('/\s+/', $source);
            
            if (count($parts) >= 2) {
                $url = $parts[0];
                $descriptor = $parts[1];
                
                // Extract width (e.g., "1000w" -> 1000)
                if (preg_match('/(\d+)w/i', $descriptor, $matches)) {
                    $width = intval($matches[1]);
                    
                    if ($width > $highest_res) {
                        $highest_res = $width;
                        $highest_url = $url;
                    }
                }
                // Handle density descriptors (e.g., "2x")
                elseif (preg_match('/(\d+\.?\d*)x/i', $descriptor, $matches)) {
                    $density = floatval($matches[1]);
                    $estimated_width = intval($density * 1000); // Estimate
                    
                    if ($estimated_width > $highest_res) {
                        $highest_res = $estimated_width;
                        $highest_url = $url;
                    }
                }
            } elseif (count($parts) === 1 && !empty($parts[0])) {
                // If no descriptor, use this as fallback
                if (empty($highest_url)) {
                    $highest_url = $parts[0];
                }
            }
        }
        
        if ($debug && !empty($highest_url)) {
            error_log("APM: Extracted highest resolution image ({$highest_res}w) from srcset");
        }
        
        return $highest_url;
    }
    
    /**
     * Convert Shopify CDN URL to highest resolution
     *
     * @param string $url The image URL
     * @param bool $debug Debug mode flag
     * @return string High-resolution URL
     */
    private function convert_to_shopify_high_res($url, $debug = false) {
        $original_url = $url;
        
        // Shopify CDN URLs often have size parameters like _100x.jpg, _500x.jpg, _100x100.jpg
        // Pattern: filename_123x456.jpg or filename_123x.jpg
        if (preg_match('/_\d+x\d*\.(jpg|jpeg|png|gif|webp)/i', $url)) {
            // Replace with largest size
            $url = preg_replace('/_\d+x\d*\./', '_2048x.', $url);
            
            if ($debug && $url !== $original_url) {
                error_log("APM: Converted Shopify URL filename to high-res");
            }
        }
        
        // Handle Shopify CDN query string parameters
        // Keep the v= parameter (version/cache busting) but update width
        if (preg_match('/\/cdn\/shop\/(files|products)\//', $url) || 
            stripos($url, 'cdn.shopify.com') !== false || 
            stripos($url, 'shopifycdn.com') !== false) {
            
            // Parse URL to handle query string properly
            $url_parts = parse_url($url);
            $base_url = $url_parts['scheme'] . '://' . $url_parts['host'] . $url_parts['path'];
            
            // Parse existing query string
            $query_params = array();
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $query_params);
            }
            
            // Set width to maximum (or remove it to get original)
            $query_params['width'] = 2048;
            
            // Rebuild URL
            $url = $base_url . '?' . http_build_query($query_params);
            
            if ($debug && $url !== $original_url) {
                error_log("APM: Converted Shopify CDN URL to high-res with width=2048");
            }
        }
        
        return $url;
    }
    
    /**
     * Normalize Shopify URL for duplicate detection
     *
     * @param string $url The URL to normalize
     * @return string Normalized URL
     */
    private function normalize_shopify_url($url) {
        // Parse URL
        $url_parts = parse_url($url);
        $base = $url_parts['scheme'] . '://' . $url_parts['host'] . $url_parts['path'];
        
        // Keep only the v= parameter if it exists (for version/cache)
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
            if (isset($params['v'])) {
                return $base . '?v=' . $params['v'];
            }
        }
        
        return $base;
    }
}
