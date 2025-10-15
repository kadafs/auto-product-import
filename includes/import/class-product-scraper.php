<?php
/**
 * Product Scraper class - Main orchestrator
 *
 * @package Auto_Product_Import
 * @since 2.1.3
 */

if (!defined('WPINC')) {
    die;
}

class APM_Product_Scraper {
    
    private $html_parser;
    private $image_extractor;
    private $pdf_extractor;
    private $description_extractor;
    private $extractors;
    
    public function __construct() {
        $this->html_parser = new APM_HTML_Parser();
        $this->image_extractor = new APM_Image_Extractor();
        $this->pdf_extractor = new APM_PDF_Extractor();
        $this->description_extractor = new APM_Description_Extractor();
        $this->extractors = new APM_Product_Scraper_Extractors();
    }
    
    /**
     * Fetch/scrape product data from URL
     *
     * @param string $url The product URL
     * @param bool $debug Whether to enable debug logging
     * @return array Product data
     */
    public function fetch($url, $debug = false) {
        if ($debug) {
            error_log("APM: Debug mode enabled for URL: $url");
        }
        
        // Fetch HTML content
        $html_content = $this->fetch_html($url, $debug);
        if (empty($html_content)) {
            throw new Exception('Failed to fetch HTML content from URL');
        }
        
        if ($debug) {
            error_log("APM: Successfully fetched HTML content. Length: " . strlen($html_content) . " bytes");
        }
        
        // Parse HTML
        $parsed = $this->html_parser->parse($html_content);
        if (!$parsed['dom'] || !$parsed['xpath']) {
            throw new Exception('Failed to parse HTML content');
        }
        
        $dom = $parsed['dom'];
        $xpath = $parsed['xpath'];
        
        if ($debug) {
            error_log("APM: Starting to parse product data");
        }
        
        // Extract basic product data using the extractors helper
        $title = $this->extractors->extract_title($xpath, $debug);
        $price = $this->extractors->extract_price($xpath, $debug);
        $sku = $this->extractors->extract_sku($xpath, $url, $html_content);
        
        if ($debug) {
            error_log("APM: Extracted title: $title");
            error_log("APM: Extracted price: $price");
            error_log("APM: Extracted SKU: $sku");
        }
        
        // Extract images
        if ($debug) {
            error_log("APM: Starting image extraction");
        }
        $images = $this->image_extractor->extract($xpath, $url, $debug, $html_content);
        if ($debug) {
            error_log("APM: Image extraction complete. Found " . count($images) . " images");
            if (!empty($images)) {
                error_log("APM: First image URL: " . $images[0]);
            }
        }
        
        // Extract PDFs - PASS RAW HTML CONTENT
        if ($debug) {
            error_log("APM: Starting PDF extraction");
            error_log("APM: HTML content length for PDF extraction: " . strlen($html_content) . " bytes");
        }
        $pdfs = $this->pdf_extractor->extract($xpath, $url, $debug, $html_content);
        if ($debug) {
            error_log("APM: PDF extraction complete. Found " . count($pdfs) . " PDFs");
            if (count($pdfs) > 0) {
                foreach ($pdfs as $i => $pdf) {
                    error_log("APM: PDF #" . ($i + 1) . " - Caption: " . $pdf['caption'] . " | URL: " . $pdf['url']);
                }
            }
        }
        
        // Extract description and additional info
        $description_html = $this->description_extractor->extract($dom, $xpath);
        $additional_info = array();
        
        if (!empty($description_html)) {
            $additional_info = $this->description_extractor->extract_additional_info($description_html, $debug);
        }
        
        $description_data = array(
            'description' => $description_html,
            'short_description' => '',
            'additional_info' => $additional_info
        );
        
        if ($debug) {
            error_log("APM: Final product data - Images found: " . count($images) . ", PDFs found: " . count($pdfs));
        }
        
        return array(
            'title' => $title,
            'price' => $price,
            'sku' => $sku,
            'images' => $images,
            'pdfs' => $pdfs,
            'description' => isset($description_data['description']) ? $description_data['description'] : '',
            'short_description' => isset($description_data['short_description']) ? $description_data['short_description'] : '',
            'additional_info' => isset($description_data['additional_info']) ? $description_data['additional_info'] : array(),
            'source_url' => $url,
            'html_content' => $html_content  // ADD RAW HTML FOR GST DETECTION
        );
    }
    
    /**
     * Fetch HTML content from URL
     *
     * @param string $url The URL to fetch
     * @param bool $debug Debug mode flag
     * @return string|false HTML content or false on failure
     */
    private function fetch_html($url, $debug = false) {
        $args = array(
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ),
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            if ($debug) {
                error_log("APM: Error fetching URL: " . $response->get_error_message());
            }
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        if (empty($html)) {
            if ($debug) {
                error_log("APM: Retrieved empty HTML content");
            }
            return false;
        }
        
        return $html;
    }
}
