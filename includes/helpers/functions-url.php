<?php
/**
 * URL helper functions
 *
 * @package Auto_Product_Import
 * @since 2.1.1
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Convert relative URL to absolute URL
 *
 * @param string $relative_url The relative URL
 * @param string $base_url The base URL
 * @return string The absolute URL
 */
function apm_make_url_absolute($relative_url, $base_url) {
    if (strpos($relative_url, 'http') === 0) {
        return $relative_url;
    }
    
    $parsed_url = parse_url($base_url);
    $base = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    
    if (strpos($relative_url, '//') === 0) {
        return $parsed_url['scheme'] . ':' . $relative_url;
    }
    
    if (strpos($relative_url, '/') === 0) {
        return $base . $relative_url;
    }
    
    if (isset($parsed_url['path'])) {
        $path = dirname($parsed_url['path']);
        return $base . $path . '/' . $relative_url;
    }
    
    return $base . '/' . $relative_url;
}

/**
 * Convert image URL to high resolution
 *
 * @param string $url The image URL
 * @return string The high-resolution URL
 */
function apm_convert_to_high_res($url) {
    if (strpos($url, '/stencil/') !== false) {
        return preg_replace('/\/stencil\/[^\/]+\//', '/stencil/1280x1280/', $url);
    }
    return $url;
}

/**
 * Validate URL
 *
 * @param string $url The URL to validate
 * @return bool True if valid, false otherwise
 */
function apm_validate_url($url) {
    return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
}