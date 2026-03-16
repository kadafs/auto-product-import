<?php
/**
 * PDF Extractor HTML Parser class
 * Handles DOM/XPath-based PDF extraction
 *
 * @package Auto_Product_Import
 * @since 2.1.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_PDF_Extractor_HTML_Parser {
    
    private $validator;
    
    public function __construct() {
        $this->validator = new APM_PDF_Extractor_Validator();
    }
    
    /**
     * Extract PDFs from DOM using XPath
     *
     * @param DOMXPath $xpath The XPath object
     * @param string $url The page URL
     * @param bool $detailed_log Whether to log detailed info
     * @return array Array of PDF data
     */
    public function extract_from_dom($xpath, $url, $detailed_log = false) {
        $pdfs = array();
        $pdf_urls_set = array();
        
        // PDF link keywords to search for
        $pdf_keywords = array(
            'download manual', 'download', 'manual', 'view pdf', 'user manual',
            'installation guide', 'documents', 'brochure', 'datasheet', 'guide',
            'instructions', 'specification', 'spec sheet'
        );
        
        // Find all links
        $all_links = $xpath->query('//a[@href]');
        
        if ($detailed_log) {
            error_log("APM: Found " . $all_links->length . " total links to scan");
        }
        
        // CRITICAL DEBUG: Search specifically for PDF links in a different way
        $pdf_check = $xpath->query('//a[contains(@href, ".pdf")]');
        
        if ($detailed_log) {
            error_log("APM: XPath direct search found " . $pdf_check->length . " links containing '.pdf' in href");
        
            if ($pdf_check->length > 0) {
                error_log("APM: PDF links DO exist in DOM! Checking first one...");
                $first_pdf = $pdf_check->item(0);
                $test_href = $first_pdf->getAttribute('href');
                $test_text = trim($first_pdf->textContent);
                error_log("APM: First PDF link - href: $test_href | text: '$test_text'");
            }
        }
        
        // If no PDFs found in DOM, return early
        if ($pdf_check->length === 0) {
            return array();
        }
        
        $source_domain = parse_url($url, PHP_URL_HOST);
        
        $checked_count = 0;
        $skipped_header_footer = 0;
        $found_pdf_links = 0;
        $evaluated_count = 0;
        
        foreach ($all_links as $link) {
            $checked_count++;
            
            // Skip if in header or footer
            if ($this->is_in_header_or_footer($link, $detailed_log)) {
                $skipped_header_footer++;
                continue;
            }
            
            $evaluated_count++;
            $href = trim($this->get_attribute($link, 'href'));
            $link_text = trim($link->textContent);
            $link_text_lower = strtolower($link_text);
            
            // DEBUG: Log first 10 evaluated links AND any link with manual/brochure in text
            if ($detailed_log && ($evaluated_count <= 10 || stripos($link_text, 'manual') !== false || stripos($link_text, 'brochure') !== false)) {
                error_log("APM: Evaluating link #$evaluated_count - Text: '" . substr($link_text, 0, 50) . "' | href: " . substr($href, 0, 100));
            }
            
            if (empty($href)) {
                continue;
            }
            
            // Check if it's a PDF link
            $is_pdf = false;
            $detection_method = '';
            
            // Method 1: Check if URL ends with .pdf
            if (preg_match('/\.pdf(\?.*)?$/i', $href)) {
                $is_pdf = true;
                $detection_method = 'extension';
                if ($detailed_log) {
                    error_log("APM: ✓ Found PDF by extension: " . substr($href, 0, 100) . " | Text: '$link_text'");
                }
            }
            
            // Method 2: Check if link text contains PDF keywords
            if (!$is_pdf && !empty($link_text)) {
                foreach ($pdf_keywords as $keyword) {
                    if (stripos($link_text, $keyword) !== false) {
                        $is_pdf = true;
                        $detection_method = "keyword '$keyword'";
                        if ($detailed_log) {
                            error_log("APM: ✓ Found potential PDF by keyword '$keyword' in text: '$link_text' | URL: " . substr($href, 0, 80));
                        }
                        break;
                    }
                }
            }
            
            if (!$is_pdf) {
                continue;
            }
            
            $found_pdf_links++;
            
            // Convert to absolute URL if needed
            $href = $this->validator->make_absolute_url($href, $url);
            
            // Get normalized URL to check for duplicates
            $normalized_url = $this->validator->normalize_url($href);
            
            // Skip if we already have this PDF
            if (in_array($normalized_url, $pdf_urls_set)) {
                if ($detailed_log) {
                    error_log("APM: ✗ Skipped duplicate PDF");
                }
                continue;
            }
            
            // Determine caption from title attribute or link text
            $caption = $this->extract_caption($link, $link_text, $href);
            
            $pdfs[] = array(
                'url' => $href,
                'caption' => $caption,
                'detection_method' => $detection_method
            );
            
            $pdf_urls_set[] = $normalized_url;
            
            if ($detailed_log) {
                error_log("APM: ✓ Added PDF: $caption - $href");
            }
        }
        
        if ($detailed_log) {
            error_log("APM: Checked $checked_count links, skipped $skipped_header_footer in header/footer, evaluated $evaluated_count links, found $found_pdf_links potential PDFs");
        }
        
        return $pdfs;
    }
    
    /**
     * Get attribute value safely
     */
    private function get_attribute($node, $name) {
        if (method_exists($node, 'getAttribute')) {
            return $node->getAttribute($name);
        }
        return '';
    }
    
    /**
     * Extract caption from link
     */
    private function extract_caption($link, $link_text, $href) {
        $caption = '';
        $title_attr = $this->get_attribute($link, 'title');
        
        if (!empty($title_attr)) {
            $caption = trim($title_attr);
        } elseif (!empty($link_text)) {
            $caption = trim($link_text);
        } else {
            // Use filename as fallback
            $filename = basename(parse_url($href, PHP_URL_PATH));
            $caption = pathinfo($filename, PATHINFO_FILENAME);
        }
        
        return $caption;
    }
    
    /**
     * Check if link is in header or footer
     */
    private function is_in_header_or_footer($node, $detailed_log = false) {
        $current = $node;
        $max_levels = 15;
        $level = 0;
        
        while ($current && $level < $max_levels) {
            $tag_name = strtolower($current->nodeName);
            
            // Check tag name - always exclude header and footer
            if (in_array($tag_name, array('header', 'footer'))) {
                if ($detailed_log) {
                    error_log("APM: Skipped PDF in <$tag_name> tag");
                }
                return true;
            }
            
            // Only exclude nav if it's actually navigation
            if ($tag_name === 'nav') {
                if (method_exists($current, 'getAttribute')) {
                    $class = strtolower($this->get_attribute($current, 'class'));
                    $id = strtolower($this->get_attribute($current, 'id'));
                    
                    // Navigation-related keywords
                    $nav_keywords = array(
                        'menu', 'navigation', 'navbar', 'nav-bar',
                        'site-nav', 'main-nav', 'breadcrumb', 'bread-crumb'
                    );
                    
                    foreach ($nav_keywords as $keyword) {
                        if (strpos($class, $keyword) !== false || strpos($id, $keyword) !== false) {
                            if ($detailed_log) {
                                error_log("APM: Skipped PDF in <nav> navigation element");
                            }
                            return true;
                        }
                    }
                }
                // If nav doesn't have navigation keywords, allow it (might be product tabs)
            }
            
            // Check class and id for specific header/footer patterns
            if (method_exists($current, 'getAttribute')) {
                $class = strtolower($this->get_attribute($current, 'class'));
                $id = strtolower($this->get_attribute($current, 'id'));
                
                // Only exclude if it's clearly header/footer
                $exclude_patterns = array(
                    'site-header',
                    'page-header',
                    'main-header',
                    'site-footer',
                    'page-footer',
                    'main-footer',
                    'footer-content',
                    'header-content'
                );
                
                foreach ($exclude_patterns as $pattern) {
                    if (strpos($class, $pattern) !== false || strpos($id, $pattern) !== false) {
                        if ($detailed_log) {
                            error_log("APM: Skipped PDF in element with pattern: $pattern");
                        }
                        return true;
                    }
                }
            }
            
            $current = $current->parentNode;
            $level++;
        }
        
        return false;
    }
}
