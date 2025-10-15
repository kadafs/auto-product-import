<?php
/**
 * Image Uploader class
 *
 * @package Auto_Product_Import
 * @since 2.1.2
 */

if (!defined('WPINC')) {
    die;
}

class APM_Image_Uploader {
    
    /**
     * Upload remote image to WordPress
     *
     * @param string $image_url The image URL
     * @param int $product_id The product ID
     * @param bool $debug Debug mode flag
     * @return int|false Attachment ID or false on failure
     */
    public function upload($image_url, $product_id, $debug = false) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        if ($debug) {
            error_log("APM: Starting download of: " . substr($image_url, 0, 150));
        }
        
        $tmp = $this->download_image($image_url, $debug);
        if (is_wp_error($tmp) || $tmp === false) {
            if ($debug && is_wp_error($tmp)) {
                error_log("APM: Download failed: " . $tmp->get_error_message());
            }
            return false;
        }
        
        if ($debug) {
            error_log("APM: Image downloaded to temp file: $tmp");
        }
        
        $file_array = $this->prepare_file_array($image_url, $tmp);
        
        if (!$this->validate_image($tmp, $debug)) {
            @unlink($tmp);
            if ($debug) {
                error_log("APM: Image validation failed");
            }
            return false;
        }
        
        if ($debug) {
            error_log("APM: Image validated, uploading to media library...");
        }
        
        $attachment_id = media_handle_sideload($file_array, $product_id);
        
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
        
        if (is_wp_error($attachment_id)) {
            error_log('APM: Failed to process image: ' . $image_url . ' - Error: ' . $attachment_id->get_error_message());
            return false;
        }
        
        if ($debug) {
            error_log("APM: Image uploaded successfully - Attachment ID: $attachment_id");
        }
        
        return $attachment_id;
    }
    
    /**
     * Download image from URL
     */
    private function download_image($image_url, $debug = false) {
        $timeout = apply_filters('auto_product_import_image_timeout', 60);
        
        if ($debug) {
            error_log("APM: Downloading with timeout: {$timeout}s");
        }
        
        $tmp = download_url($image_url, $timeout);
        
        if (is_wp_error($tmp)) {
            error_log('APM: Failed to download image: ' . $image_url . ' - Error: ' . $tmp->get_error_message());
            return false;
        }
        
        return $tmp;
    }
    
    /**
     * Prepare file array for upload
     */
    private function prepare_file_array($image_url, $tmp) {
        $file_array = array();
        $file_array['name'] = basename(parse_url($image_url, PHP_URL_PATH));
        $file_array['tmp_name'] = $tmp;
        
        // Remove query string from filename if present
        $file_array['name'] = preg_replace('/\?.*$/', '', $file_array['name']);
        
        $filetype = wp_check_filetype($file_array['name'], null);
        
        if (empty($filetype['ext']) || empty($filetype['type'])) {
            $file_array['name'] = $this->generate_filename($image_url, $tmp);
        }
        
        return $file_array;
    }
    
    /**
     * Generate filename for image
     */
    private function generate_filename($image_url, $tmp) {
        $url_parts = parse_url($image_url);
        $url_path = pathinfo($url_parts['path']);
        
        if (!empty($url_path['extension'])) {
            $ext = strtolower($url_path['extension']);
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'))) {
                return 'product-image-' . time() . '-' . wp_rand(1000, 9999) . '.' . $ext;
            }
        }
        
        $file_info = @getimagesize($tmp);
        if ($file_info && isset($file_info['mime'])) {
            $ext = $this->get_extension_from_mime($file_info['mime']);
            return 'product-image-' . time() . '-' . wp_rand(1000, 9999) . '.' . $ext;
        }
        
        return 'product-image-' . time() . '-' . wp_rand(1000, 9999) . '.jpg';
    }
    
    /**
     * Get file extension from MIME type
     */
    private function get_extension_from_mime($mime) {
        $mime_map = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        );
        
        return isset($mime_map[$mime]) ? $mime_map[$mime] : 'jpg';
    }
    
    /**
     * Validate image dimensions and type
     */
    private function validate_image($tmp, $debug = false) {
        if (!file_exists($tmp)) {
            if ($debug) {
                error_log("APM: Temp file does not exist: $tmp");
            }
            return false;
        }
        
        $image_info = @getimagesize($tmp);
        if (!$image_info) {
            if ($debug) {
                error_log("APM: getimagesize() failed - not a valid image");
            }
            return false;
        }
        
        list($width, $height) = $image_info;
        
        if ($debug) {
            error_log("APM: Image dimensions: {$width}x{$height}");
        }
        
        if ($width < 50 || $height < 50) {
            if ($debug) {
                error_log("APM: Image too small (min 50x50)");
            }
            return false;
        }
        
        return true;
    }
}
