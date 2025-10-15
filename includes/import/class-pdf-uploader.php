<?php
/**
 * PDF Uploader class
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

class APM_PDF_Uploader {
    
    /**
     * Upload remote PDF to WordPress
     *
     * @param string $pdf_url The PDF URL
     * @param string $caption The caption for the PDF
     * @param int $product_id The product ID
     * @param bool $debug Debug mode flag
     * @return int|false Attachment ID or false on failure
     */
    public function upload($pdf_url, $caption, $product_id, $debug = false) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        if ($debug) {
            error_log("APM: Starting PDF download: " . substr($pdf_url, 0, 150));
            error_log("APM: PDF Caption: $caption");
        }
        
        // Get original filename from URL
        $path = parse_url($pdf_url, PHP_URL_PATH);
        $original_filename = basename($path);
        $original_filename = preg_replace('/\?.*$/', '', $original_filename);
        
        // CHECK FOR DUPLICATE - if file with same name exists, skip download
        $existing_attachment = $this->check_existing_pdf($original_filename, $debug);
        if ($existing_attachment) {
            if ($debug) {
                error_log("APM: ✓ PDF already exists in media library (ID: $existing_attachment) - skipping download");
            }
            return $existing_attachment;
        }
        
        // Check size limit before downloading
        $max_size_mb = get_option('auto_product_import_max_pdf_size', 10);
        $max_size_bytes = $max_size_mb * 1024 * 1024;
        
        // Get file size from headers
        $file_size = $this->get_remote_file_size($pdf_url, $debug);
        if ($file_size !== false && $file_size > $max_size_bytes) {
            if ($debug) {
                $size_mb = round($file_size / 1024 / 1024, 2);
                error_log("APM: ✗ PDF too large: {$size_mb}MB (max: {$max_size_mb}MB)");
            }
            return false;
        }
        
        $tmp = $this->download_pdf($pdf_url, $debug);
        if (is_wp_error($tmp) || $tmp === false) {
            if ($debug && is_wp_error($tmp)) {
                error_log("APM: PDF download failed: " . $tmp->get_error_message());
            }
            return false;
        }
        
        if ($debug) {
            error_log("APM: PDF downloaded to temp file: $tmp");
        }
        
        // Validate it's actually a PDF
        if (!$this->validate_pdf($tmp, $max_size_bytes, $debug)) {
            @unlink($tmp);
            return false;
        }
        
        // Prepare file array with original filename
        $file_array = $this->prepare_file_array($pdf_url, $tmp, $caption);
        
        if ($debug) {
            error_log("APM: Uploading PDF to media library: " . $file_array['name']);
        }
        
        $attachment_id = media_handle_sideload($file_array, $product_id);
        
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
        
        if (is_wp_error($attachment_id)) {
            error_log('APM: Failed to process PDF: ' . $pdf_url . ' - Error: ' . $attachment_id->get_error_message());
            return false;
        }
        
        // Set caption as attachment caption
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_excerpt' => $caption,  // This is the caption field
            'post_title' => $caption      // Also set as title
        ));
        
        // Add to Downloads category if it exists
        $this->add_to_downloads_category($attachment_id, $debug);
        
        if ($debug) {
            error_log("APM: ✓ PDF uploaded successfully - Attachment ID: $attachment_id");
        }
        
        return $attachment_id;
    }
    
    /**
     * Check if PDF with same filename already exists in media library
     */
    private function check_existing_pdf($filename, $debug = false) {
        global $wpdb;
        
        // Remove extension for comparison
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        
        // Search for attachment by filename
        $query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type = 'application/pdf'
            AND guid LIKE %s
            LIMIT 1",
            '%' . $wpdb->esc_like($filename) . '%'
        );
        
        $attachment_id = $wpdb->get_var($query);
        
        if ($attachment_id) {
            if ($debug) {
                error_log("APM: Found existing PDF with filename: $filename (ID: $attachment_id)");
            }
            return intval($attachment_id);
        }
        
        return false;
    }
    
    /**
     * Add PDF to Downloads media category
     */
    private function add_to_downloads_category($attachment_id, $debug = false) {
        // Check if Media Library Categories plugin or similar is active
        // Common taxonomy names: media_category, mla_media_category, ml-category
        
        $taxonomies = array('media_category', 'mla_media_category', 'ml-category');
        
        foreach ($taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                // Find or create "Downloads" term
                $term = get_term_by('name', 'Downloads', $taxonomy);
                
                if (!$term) {
                    $term_data = wp_insert_term('Downloads', $taxonomy);
                    if (!is_wp_error($term_data)) {
                        $term_id = $term_data['term_id'];
                        if ($debug) {
                            error_log("APM: Created 'Downloads' category in taxonomy: $taxonomy");
                        }
                    }
                } else {
                    $term_id = $term->term_id;
                }
                
                if (isset($term_id)) {
                    wp_set_object_terms($attachment_id, $term_id, $taxonomy, true);
                    if ($debug) {
                        error_log("APM: Added PDF to 'Downloads' category (taxonomy: $taxonomy)");
                    }
                    return; // Success, exit after first taxonomy
                }
            }
        }
        
        if ($debug) {
            error_log("APM: No media category taxonomy found - PDF not categorized");
        }
    }
    
    /**
     * Get remote file size from headers
     */
    private function get_remote_file_size($url, $debug = false) {
        $response = wp_remote_head($url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['content-length'])) {
            return intval($headers['content-length']);
        }
        
        return false;
    }
    
    /**
     * Download PDF from URL
     */
    private function download_pdf($pdf_url, $debug = false) {
        $timeout = apply_filters('auto_product_import_pdf_timeout', 120); // Longer timeout for PDFs
        
        if ($debug) {
            error_log("APM: Downloading with timeout: {$timeout}s");
        }
        
        $tmp = download_url($pdf_url, $timeout);
        
        if (is_wp_error($tmp)) {
            error_log('APM: Failed to download PDF: ' . $pdf_url . ' - Error: ' . $tmp->get_error_message());
            return false;
        }
        
        return $tmp;
    }
    
    /**
     * Prepare file array for upload
     */
    private function prepare_file_array($pdf_url, $tmp, $caption) {
        $file_array = array();
        
        // Use original filename from URL
        $path = parse_url($pdf_url, PHP_URL_PATH);
        $original_filename = basename($path);
        
        // Remove query string from filename if present
        $original_filename = preg_replace('/\?.*$/', '', $original_filename);
        
        // Ensure it has .pdf extension
        if (!preg_match('/\.pdf$/i', $original_filename)) {
            $original_filename .= '.pdf';
        }
        
        $file_array['name'] = $original_filename;
        $file_array['tmp_name'] = $tmp;
        
        return $file_array;
    }
    
    /**
     * Validate PDF file
     */
    private function validate_pdf($tmp, $max_size_bytes, $debug = false) {
        if (!file_exists($tmp)) {
            if ($debug) {
                error_log("APM: Temp file does not exist: $tmp");
            }
            return false;
        }
        
        // Check file size
        $file_size = filesize($tmp);
        if ($file_size === false || $file_size === 0) {
            if ($debug) {
                error_log("APM: Unable to determine file size or file is empty");
            }
            return false;
        }
        
        if ($file_size > $max_size_bytes) {
            if ($debug) {
                $size_mb = round($file_size / 1024 / 1024, 2);
                $max_mb = round($max_size_bytes / 1024 / 1024, 2);
                error_log("APM: PDF file too large: {$size_mb}MB (max: {$max_mb}MB)");
            }
            return false;
        }
        
        // Validate it's actually a PDF by checking file signature
        $handle = fopen($tmp, 'r');
        if ($handle) {
            $header = fread($handle, 5);
            fclose($handle);
            
            // PDF files start with %PDF-
            if (strpos($header, '%PDF-') !== 0) {
                if ($debug) {
                    error_log("APM: File is not a valid PDF (invalid signature)");
                }
                return false;
            }
        } else {
            if ($debug) {
                error_log("APM: Unable to open file for validation");
            }
            return false;
        }
        
        if ($debug) {
            $size_mb = round($file_size / 1024 / 1024, 2);
            error_log("APM: PDF validated: {$size_mb}MB");
        }
        
        return true;
    }
}
