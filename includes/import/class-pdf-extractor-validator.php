<?php
/**
 * PDF Extractor Validator class
 * Handles URL normalization and validation
 *
 * @package Auto_Product_Import
 * @since 2.1.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_PDF_Extractor_Validator {
    
    /**
     * Normalize URL for comparison
     *
     * @param string $url The URL to normalize
     * @return string Normalized URL
     */
    public function normalize_url($url) {
        // Remove query parameters and lowercase for comparison
        return strtolower(preg_replace('/\?.*$/', '', $url));
    }
    
    /**
     * Convert relative URL to absolute
     *
     * @param string $relative_url The relative URL
     * @param string $base_url The base URL
     * @return string Absolute URL
     */
    public function make_absolute_url($relative_url, $base_url) {
        if (strpos($relative_url, 'http') === 0) {
            return $relative_url;
        }
        
        if (strpos($relative_url, '//') === 0) {
            return 'https:' . $relative_url;
        }
        
        if (function_exists('apm_url_convert_to_absolute')) {
            return apm_url_convert_to_absolute($base_url, $relative_url);
        }
        
        // Manual conversion
        $base_parts = parse_url($base_url);
        
        // Handle absolute paths
        if (strpos($relative_url, '/') === 0) {
            return $base_parts['scheme'] . '://' . $base_parts['host'] . $relative_url;
        }
        
        // Handle relative paths
        if (isset($base_parts['path'])) {
            $path = dirname($base_parts['path']);
            return $base_parts['scheme'] . '://' . $base_parts['host'] . $path . '/' . $relative_url;
        }
        
        return $base_parts['scheme'] . '://' . $base_parts['host'] . '/' . ltrim($relative_url, '/');
    }
    
    /**
     * Validate PDF URL
     *
     * @param string $url The URL to validate
     * @return bool True if valid PDF URL
     */
    public function is_valid_pdf_url($url) {
        if (empty($url)) {
            return false;
        }
        
        // Check if it's a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Check if it ends with .pdf
        if (!preg_match('/\.pdf(\?.*)?$/i', $url)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if URL is already in the set
     *
     * @param string $url The URL to check
     * @param array $url_set The set of URLs
     * @return bool True if duplicate
     */
    public function is_duplicate($url, $url_set) {
        $normalized = $this->normalize_url($url);
        return in_array($normalized, $url_set);
    }
}
