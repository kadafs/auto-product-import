<?php
/**
 * PDF Extractor class - Main orchestrator
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

class APM_PDF_Extractor {
    
    private $html_parser;
    private $js_parser;
    private $validator;
    
    public function __construct() {
        $this->html_parser = new APM_PDF_Extractor_HTML_Parser();
        $this->js_parser = new APM_PDF_Extractor_JS_Parser();
        $this->validator = new APM_PDF_Extractor_Validator();
    }
    
    /**
     * Check if detailed logging should be enabled for this URL
     *
     * @param string $url The URL being processed
     * @return bool True if detailed logging should be enabled
     */
    private function should_log_detailed($url) {
        // Get the log_pdf setting
        $log_pdf = get_option('auto_product_import_log_pdf', 'no');
        
        // If checkbox is unchecked, never show detailed logs
        if ($log_pdf !== 'yes') {
            return false;
        }
        
        // Get the debug domain setting
        $debug_domain = get_option('auto_product_import_debug_domain', '');
        
        // If debug domain is empty, show detailed logs for ALL domains
        if (empty($debug_domain)) {
            return true;
        }
        
        // Check if URL matches the debug domain
        $url_domain = parse_url($url, PHP_URL_HOST);
        if ($url_domain && strpos($url_domain, $debug_domain) !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Extract PDF links from HTML
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param bool $debug Whether to log debug info (DEPRECATED - use should_log_detailed instead)
     * @param string $html_content The raw HTML content (optional, for fallback)
     * @return array Array of PDF data (url, caption)
     */
    public function extract($xpath, $url, $debug = false, $html_content = '') {
        $pdfs = array();
        
        // Determine if detailed logging should be enabled
        $detailed_log = $this->should_log_detailed($url);
        
        // BASIC LOGGING - Always show
        error_log("APM: Starting PDF extraction from URL: $url");
        
        if ($detailed_log) {
            error_log("APM: ========== PDF EXTRACTION START (DETAILED) ==========");
            error_log("APM: HTML content length: " . strlen($html_content) . " bytes");
        }
        
        // Try HTML/DOM extraction first
        $pdfs = $this->html_parser->extract_from_dom($xpath, $url, $detailed_log);
        
        // If XPath found no PDFs but we have raw HTML, try regex fallback
        if (empty($pdfs) && !empty($html_content)) {
            if ($detailed_log) {
                error_log("APM: PDF links not in DOM - trying regex search on raw HTML...");
            }
            
            $regex_pdfs = $this->extract_pdfs_from_raw_html($html_content, $url, $detailed_log);
            
            if (!empty($regex_pdfs)) {
                // BASIC LOGGING - Always show
                error_log("APM: PDF extraction complete - Found " . count($regex_pdfs) . " PDFs (via regex)");
                return $regex_pdfs;
            } else {
                if ($detailed_log) {
                    error_log("APM: Regex search also found no PDFs");
                }
            }
        }
        
        if ($detailed_log) {
            error_log("APM: ========== PDF EXTRACTION COMPLETE (DETAILED) ==========");
            error_log("APM: TOTAL PDFs FOUND: " . count($pdfs));
            foreach ($pdfs as $i => $pdf) {
                $num = $i + 1;
                error_log("APM: PDF #$num: {$pdf['caption']} - {$pdf['url']}");
            }
            error_log("APM: ===============================================");
        }
        
        // BASIC LOGGING - Always show
        error_log("APM: PDF extraction complete - Found " . count($pdfs) . " PDFs");
        
        return $pdfs;
    }
    
    /**
     * Extract PDFs from raw HTML using regex (fallback for JavaScript-loaded content)
     */
    private function extract_pdfs_from_raw_html($html, $url, $detailed_log = false) {
        $pdfs = array();
        $pdf_urls_set = array();
        
        if ($detailed_log) {
            error_log("APM: === STARTING extract_pdfs_from_raw_html ===");
        }
        
        // STEP 1: Find all PDF filenames that appear as visible text in the HTML
        $visible_pdf_filenames = $this->js_parser->find_visible_pdf_filenames($html, $detailed_log);
        
        if (empty($visible_pdf_filenames)) {
            if ($detailed_log) {
                error_log("APM: ✗ No visible PDF filenames found - CANNOT PROCEED");
            }
            return array();
        }
        
        if ($detailed_log) {
            error_log("APM: ✓ Found " . count($visible_pdf_filenames) . " visible PDF filename(s)");
            foreach ($visible_pdf_filenames as $filename) {
                error_log("APM: Visible PDF: $filename");
            }
        }
        
        // STEP 2: Extract all PDF URLs from HTML
        $all_matches = $this->extract_all_pdf_urls($html, $detailed_log);
        
        // STEP 3: Only include PDFs whose filenames match the visible ones
        foreach ($all_matches as $pdf_url) {
            $pdf_filename = basename(parse_url($pdf_url, PHP_URL_PATH));
            
            // Check if this filename matches any of the visible PDF filenames
            if (!in_array($pdf_filename, $visible_pdf_filenames)) {
                if ($detailed_log) {
                    error_log("APM: ✗ Skipped PDF not in visible list: $pdf_filename");
                }
                continue;
            }
            
            if ($detailed_log) {
                error_log("APM: ✓ PDF filename matches visible list: $pdf_filename");
            }
            
            // Convert to absolute URL if needed
            $pdf_url = $this->validator->make_absolute_url($pdf_url, $url);
            
            // Normalize and check for duplicates
            $normalized_url = $this->validator->normalize_url($pdf_url);
            
            if (in_array($normalized_url, $pdf_urls_set)) {
                continue;
            }
            
            // Try to find the actual link text (Manual, Brochure, etc.)
            $caption = $this->find_caption_near_url($html, $pdf_url, $detailed_log);
            
            if (empty($caption)) {
                // Use filename as fallback
                $filename = basename(parse_url($pdf_url, PHP_URL_PATH));
                $caption = pathinfo($filename, PATHINFO_FILENAME);
                $caption = str_replace(array('_', '-'), ' ', $caption);
                $caption = ucwords($caption);
            }
            
            $pdfs[] = array(
                'url' => $pdf_url,
                'caption' => $caption,
                'detection_method' => 'regex'
            );
            
            $pdf_urls_set[] = $normalized_url;
            
            if ($detailed_log) {
                error_log("APM: ✓✓ ADDED PDF: $caption - " . substr($pdf_url, 0, 80));
            }
        }
        
        if ($detailed_log) {
            error_log("APM: === extract_pdfs_from_raw_html COMPLETE - Returning " . count($pdfs) . " PDFs ===");
        }
        
        return $pdfs;
    }
    
    /**
     * Extract all PDF URLs from HTML
     */
    private function extract_all_pdf_urls($html, $detailed_log = false) {
        $patterns = array(
            '/href=["\']([^"\']*\.pdf[^"\']*)["\']/',
            '/href=&quot;([^&]*\.pdf[^&]*)&quot;/',
            '/href=([^\s>]*\.pdf[^\s>]*)[\s>]/',
        );
        
        $all_matches = array();
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $matches);
            if (!empty($matches[1])) {
                $all_matches = array_merge($all_matches, $matches[1]);
            }
        }
        
        preg_match_all('/https?:\/\/[^\s"\'<>]*\.pdf[^\s"\'<>]*/', $html, $url_matches);
        if (!empty($url_matches[0])) {
            $all_matches = array_merge($all_matches, $url_matches[0]);
        }
        
        $all_matches = array_unique($all_matches);
        
        if ($detailed_log) {
            error_log("APM: Found " . count($all_matches) . " total unique PDF URLs in raw HTML");
        }
        
        return $all_matches;
    }
    
    /**
     * Find caption text near a PDF URL in HTML
     */
    private function find_caption_near_url($html, $pdf_url, $detailed_log = false) {
        $caption = '';
        
        // Clean the PDF URL for matching (remove query params and protocol)
        $pdf_filename = basename(parse_url($pdf_url, PHP_URL_PATH));
        $escaped_filename = preg_quote($pdf_filename, '/');
        
        if ($detailed_log) {
            error_log("APM: Looking for caption near: $pdf_filename");
        }
        
        // Pattern 1: Look for <a class="tigren-linkAttachment">Caption Text</a>
        if (preg_match('/<a[^>]*class=["\'][^"\']*tigren-linkAttachment[^"\']*["\'][^>]*href=["\'][^"\']*' . $escaped_filename . '[^"\']*["\'][^>]*>([^<]+)<\/a>/i', $html, $match)) {
            $caption = trim(strip_tags($match[1]));
            if ($detailed_log) {
                error_log("APM: ✓ Found caption from tigren-linkAttachment: '$caption'");
            }
            return $caption;
        }
        
        // Pattern 2: Look for any link text near this PDF
        if (preg_match('/<a[^>]*href=["\'][^"\']*' . $escaped_filename . '[^"\']*["\'][^>]*>([^<]+)<\/a>/i', $html, $match)) {
            $text = trim(strip_tags($match[1]));
            // Only use if it's short and looks like a caption
            if (!empty($text) && strlen($text) < 100 && strlen($text) > 1) {
                $caption = $text;
                if ($detailed_log) {
                    error_log("APM: ✓ Found caption from link text: '$caption'");
                }
                return $caption;
            }
        }
        
        // Pattern 3: Look for alt text in img near this URL
        if (preg_match('/<a[^>]*href=["\'][^"\']*' . $escaped_filename . '[^"\']*["\'][^>]*>.*?<img[^>]*alt=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is', $html, $match)) {
            $caption = trim($match[1]);
            if ($detailed_log) {
                error_log("APM: ✓ Found caption from img alt: '$caption'");
            }
            return $caption;
        }
        
        if ($detailed_log) {
            error_log("APM: ✗ No caption found for: $pdf_filename");
        }
        
        return $caption;
    }
}
