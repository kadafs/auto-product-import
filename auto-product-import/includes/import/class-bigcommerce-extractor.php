<?php
/**
 * BigCommerce Extractor class
 *
 * @package Auto_Product_Import
 * @since 2.1.1
 */

if (!defined('WPINC')) {
    die;
}

class APM_BigCommerce_Extractor {
    
    /**
     * Extract images using BigCommerce-specific selectors
     */
    public function extract($xpath, $url, &$images, &$image_urls_set, $debug) {
        $selectors = array(
            '//ul[contains(@class, "productView-thumbnails")]/li//img',
            '//figure[contains(@class, "productView-image")]//img',
            '//div[contains(@class, "productView-img-container")]//img',
            '//a[contains(@class, "cloud-zoom-gallery")]',
            '//div[contains(@class, "productView")]//img[contains(@class, "main-image")]'
        );
        
        $blacklisted_terms = apm_get_blacklisted_image_terms();
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            
            if ($nodes && $nodes->length > 0) {
                if ($debug) {
                    error_log("Found " . $nodes->length . " nodes using BigCommerce selector: $selector");
                }
                
                foreach ($nodes as $node) {
                    if (apm_is_in_related_products_section($node, $debug)) {
                        continue;
                    }
                    
                    if ($selector === '//a[contains(@class, "cloud-zoom-gallery")]') {
                        $this->extract_from_link($node, $url, $images, $image_urls_set, $debug);
                    } else {
                        $image_extractor = new APM_Image_Extractor();
                        $image_extractor->extract_and_filter_image($node, $images, $image_urls_set, $blacklisted_terms, $debug, $url);
                    }
                }
            }
        }
    }
    
    /**
     * Extract image from BigCommerce link
     */
    private function extract_from_link($node, $base_url, &$images, &$image_urls_set, $debug) {
        if (apm_dom_has_attribute($node, 'href')) {
            $img_url = apm_dom_get_attribute($node, 'href');
            
            if (!empty($img_url) && strpos($img_url, 'http') !== 0) {
                $img_url = apm_make_url_absolute($img_url, $base_url);
            }
            
            $img_url = apm_convert_to_high_res($img_url);
            
            if (!empty($img_url) && !isset($image_urls_set[$img_url])) {
                $images[] = $img_url;
                $image_urls_set[$img_url] = true;
                
                if ($debug) {
                    error_log("Added image from link href: $img_url");
                }
            }
        }
        
        if (apm_dom_has_attribute($node, 'data-zoom-image')) {
            $img_url = apm_dom_get_attribute($node, 'data-zoom-image');
            
            if (!empty($img_url) && strpos($img_url, 'http') !== 0) {
                $img_url = apm_make_url_absolute($img_url, $base_url);
            }
            
            if (!empty($img_url) && !isset($image_urls_set[$img_url])) {
                $images[] = $img_url;
                $image_urls_set[$img_url] = true;
                
                if ($debug) {
                    error_log("Added image from link data-zoom-image: $img_url");
                }
            }
        }
    }
}