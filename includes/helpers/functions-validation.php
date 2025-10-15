<?php
/**
 * Validation helper functions
 *
 * @package Auto_Product_Import
 * @since 2.1.2
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Get blacklisted image terms
 *
 * @return array Array of blacklisted terms
 */
function apm_get_blacklisted_image_terms() {
    return array(
        'icon', 'logo', 'placeholder', 'pixel', 'spinner', 'loading', 'banner',
        'button', 'thumbnail-default', 'social', 'facebook', 'twitter', 'instagram',
        'background', 'pattern', 'avatar', 'profile', 'cart', 'checkout', 'payment',
        'shipping', 'footer', 'header', 'navigation', 'menu', 'search', 'sprite', 'guarantee',
        'badge', 'star', 'rating', 'share', 'wishlist', 'compare', 'like', 'heart',
        'zoom', 'magnify', 'close', 'play', 'video-placeholder', 'track.png',
        '/collections/', 'collection-', '-collection',  // Shopify collection images
        '/tY.png', 'logo-', '-logo', 'brand-', '-brand'  // Logo and brand images
    );
}

/**
 * Check if URL is a valid product image
 *
 * @param string $url The image URL
 * @param array $blacklisted_terms Optional blacklisted terms
 * @param string $source_domain Optional source domain to check against
 * @return bool True if valid product image
 */
function apm_is_valid_product_image($url, $blacklisted_terms = null, $source_domain = '') {
    if (empty($url)) {
        return false;
    }
    
    if ($blacklisted_terms === null) {
        $blacklisted_terms = apm_get_blacklisted_image_terms();
    }
    
    // Check blacklisted terms
    foreach ($blacklisted_terms as $term) {
        if (stripos($url, $term) !== false) {
            return false;
        }
    }
    
    // Check for related products patterns in URL
    $related_patterns = array('/related/', '/similar/', '/recommended/');
    foreach ($related_patterns as $pattern) {
        if (preg_match($pattern, $url)) {
            return false;
        }
    }
    
    // Check image dimensions if it looks suspicious (very wide or very short = likely banner/header)
    $filename = basename(parse_url($url, PHP_URL_PATH));
    if (strlen($filename) <= 6) {  // Very short filenames like "tY.png" are often logos
        // Check if it has width parameter indicating small size
        if (preg_match('/[?&]width=(\d+)/i', $url, $matches)) {
            $width = intval($matches[1]);
            if ($width <= 400) {  // Small width often indicates logo/icon
                return false;
            }
        }
    }
    
    // If source domain is provided, reject images from different domains
    // (unless it's a known CDN)
    if (!empty($source_domain)) {
        $image_domain = parse_url($url, PHP_URL_HOST);
        $source_domain_clean = preg_replace('/^www\./i', '', $source_domain);
        $image_domain_clean = preg_replace('/^www\./i', '', $image_domain);
        
        // Check if it's from a different domain
        if ($image_domain_clean !== $source_domain_clean) {
            // Allow known CDNs
            $allowed_cdn_patterns = array(
                'cdn.shopify.com',
                'shopifycdn.com',
                'bigcommerce',
                'cloudinary',
                'imgix',
                'fastly'
            );
            
            $is_allowed_cdn = false;
            foreach ($allowed_cdn_patterns as $cdn_pattern) {
                if (stripos($image_domain, $cdn_pattern) !== false) {
                    $is_allowed_cdn = true;
                    break;
                }
            }
            
            // Also allow if the URL contains the source domain path
            if (!$is_allowed_cdn && stripos($url, '/cdn/shop/') === false) {
                return false;
            }
        }
    }
    
    // Get file extension
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $valid_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
    
    // If it has a valid image extension, it's likely valid
    if (in_array($ext, $valid_extensions)) {
        return true;
    }
    
    // Check for Shopify CDN URLs (these are always product images)
    // But make sure they're in /files/ or /products/ not /assets/
    if (stripos($url, 'cdn.shopify.com') !== false || 
        stripos($url, 'shopifycdn.com') !== false ||
        preg_match('/\/cdn\/shop\/(files|products)\//', $url)) {
        
        // Exclude assets folder which contains theme files/logos
        if (stripos($url, '/assets/') !== false) {
            return false;
        }
        return true;
    }
    
    // Check for BigCommerce CDN URLs
    if (stripos($url, 'bigcommerce') !== false && stripos($url, '/products/') !== false) {
        return true;
    }
    
    // Check for common image paths
    if (strpos($url, '/images/') !== false || 
        strpos($url, '/img/') !== false || 
        strpos($url, 'image') !== false ||
        strpos($url, 'product') !== false) {
        return true;
    }
    
    // If we're not sure, reject it to avoid false positives
    return false;
}

/**
 * Sanitize max images value
 *
 * @param mixed $value The value to sanitize
 * @return int The sanitized value (1-50)
 */
function apm_sanitize_max_images($value) {
    $value = absint($value);
    if ($value < 1) {
        $value = 1;
    } elseif ($value > 50) {
        $value = 50;
    }
    return $value;
}

/**
 * Sanitize max PDF size value
 *
 * @param mixed $value The value to sanitize
 * @return int The sanitized value (10-200 MB)
 */
function apm_sanitize_max_pdf_size($value) {
    $value = absint($value);
    if ($value < 10) {
        $value = 10;
    } elseif ($value > 200) {
        $value = 200;
    }
    return $value;
}
