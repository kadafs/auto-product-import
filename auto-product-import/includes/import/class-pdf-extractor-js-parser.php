<?php
/**
 * PDF Extractor JavaScript Parser class
 * Handles JavaScript config parsing (Shopify Tigren app, etc.)
 *
 * @package Auto_Product_Import
 * @since 2.1.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_PDF_Extractor_JS_Parser {
    
    /**
     * Find PDF filenames that are visible as text in the HTML
     * This helps us identify which PDFs are actually displayed on the page
     */
    public function find_visible_pdf_filenames($html, $detailed_log = false) {
        $visible_filenames = array();
        
        if ($detailed_log) {
            error_log("APM: === STARTING find_visible_pdf_filenames ===");
            error_log("APM: Searching entire HTML for ANY .pdf references...");
        }
        
        // Extract ALL PDF filenames found ANYWHERE in the HTML
        preg_match_all('/([a-zA-Z0-9_-]+\.pdf)/i', $html, $all_pdf_matches);
        
        if (!empty($all_pdf_matches[1])) {
            $all_pdfs = array_unique($all_pdf_matches[1]);
            
            if ($detailed_log) {
                error_log("APM: ✓ Found " . count($all_pdfs) . " PDF filename(s) ANYWHERE in HTML");
                
                foreach ($all_pdfs as $pdf_file) {
                    error_log("APM: Found PDF filename: $pdf_file");
                }
            }
            
            foreach ($all_pdfs as $pdf_file) {
                $visible_filenames[] = $pdf_file;
            }
        } else {
            if ($detailed_log) {
                error_log("APM: ✗ No PDF filenames found ANYWHERE in HTML");
            }
        }
        
        // ADDITIONAL: Try to extract from Shopify JavaScript config (Tigren app pattern)
        $js_patterns = array(
            '/window\.TPAConfigs\s*=\s*({.*?product_attachments.*?});/s',
            '/window\.TPAConfigs\.product_attachments\s*=\s*(\[.*?\]);/s',
            '/var\s+TPAConfigs\s*=\s*({.*?product_attachments.*?});/s',
        );
        
        $js_match = null;
        $matched_pattern = null;
        
        foreach ($js_patterns as $pattern) {
            if (preg_match($pattern, $html, $temp_match)) {
                $js_match = $temp_match;
                $matched_pattern = $pattern;
                if ($detailed_log) {
                    error_log("APM: ✓ Found JavaScript config with pattern: " . substr($pattern, 0, 50));
                }
                break;
            }
        }
        
        if ($js_match) {
            if ($detailed_log) {
                error_log("APM: ✓ Found JavaScript config data, length: " . strlen($js_match[1]) . " bytes");
                error_log("APM: First 200 chars of JS match: " . substr($js_match[1], 0, 200));
            }
            
            // Extract current product ID from the page
            $current_product_id = $this->extract_product_id_from_html($html, $detailed_log);
            
            if ($detailed_log) {
                error_log("APM: Current product ID: " . ($current_product_id ? $current_product_id : 'NOT FOUND'));
            }
            
            if ($current_product_id) {
                // Filter PDFs to only those for this product
                $product_specific_filenames = $this->filter_pdfs_by_product_id($js_match[1], $current_product_id, $detailed_log);
                
                if ($detailed_log) {
                    error_log("APM: Filtered PDFs count: " . count($product_specific_filenames));
                }
                
                if (!empty($product_specific_filenames)) {
                    if ($detailed_log) {
                        error_log("APM: ✓ Returning " . count($product_specific_filenames) . " product-specific PDFs from JavaScript");
                    }
                    return $product_specific_filenames;
                } else {
                    if ($detailed_log) {
                        error_log("APM: ✗ No product-specific PDFs found in JavaScript config");
                    }
                }
            } else {
                if ($detailed_log) {
                    error_log("APM: ✗ Could not extract product ID, cannot filter PDFs");
                }
            }
        } else {
            if ($detailed_log) {
                error_log("APM: ✗ No JavaScript config found with any pattern");
            }
        }
        
        // FALLBACK: Try HTML tigren-product-attachments section
        if ($detailed_log) {
            error_log("APM: Trying HTML fallback for tigren-product-attachments section...");
        }
        
        if (preg_match('/<div[^>]*class=["\'][^"\']*tigren-product-attachments[^"\']*["\'][^>]*>(.*?)<\/div>/s', $html, $section_match)) {
            $section_html = $section_match[1];
            
            if ($detailed_log) {
                error_log("APM: ✓ Found tigren-product-attachments section, length: " . strlen($section_html) . " bytes");
                error_log("APM: Section HTML preview: " . substr($section_html, 0, 300));
            }
            
            preg_match_all('/href=["\']([^"\']*\/([^\/]+\.pdf)[^"\']*)["\']/', $section_html, $matches);
            
            if (!empty($matches[2])) {
                if ($detailed_log) {
                    error_log("APM: ✓ Found " . count($matches[2]) . " PDF links in HTML section");
                }
                
                foreach ($matches[2] as $filename) {
                    $filename = preg_replace('/\?.*$/', '', $filename);
                    if (!in_array($filename, $visible_filenames)) {
                        $visible_filenames[] = $filename;
                    }
                    if ($detailed_log) {
                        error_log("APM: Found PDF filename in HTML section: $filename");
                    }
                }
            } else {
                if ($detailed_log) {
                    error_log("APM: ✗ No PDF links found in HTML section");
                }
            }
        } else {
            if ($detailed_log) {
                error_log("APM: ✗ tigren-product-attachments section not found in HTML");
            }
        }
        
        if ($detailed_log) {
            error_log("APM: === END find_visible_pdf_filenames - returning " . count($visible_filenames) . " filenames ===");
        }
        
        return array_unique($visible_filenames);
    }
    
    /**
     * Extract product ID from HTML
     */
    private function extract_product_id_from_html($html, $detailed_log = false) {
        if ($detailed_log) {
            error_log("APM: === EXTRACTING PRODUCT ID ===");
        }
        
        // Try multiple patterns to find product ID
        $patterns = array(
            '/"product-id"\s+value="(\d+)"/' => 'product-id input value',
            '/product_id["\']?\s*:\s*["\']?gid:\/\/shopify\/Product\/(\d+)/' => 'product_id with GID',
            '/data-product-id=["\'](\d+)["\']/' => 'data-product-id attribute',
            '/"id":\s*(\d{10,})/' => 'JSON id field (10+ digits)',
            '/product:\s*{[^}]*id["\']?\s*:\s*["\']?(\d+)/' => 'product object with id',
        );
        
        foreach ($patterns as $pattern => $description) {
            if (preg_match($pattern, $html, $match)) {
                if ($detailed_log) {
                    error_log("APM: ✓ Found product ID using pattern '$description': " . $match[1]);
                }
                return $match[1];
            } else {
                if ($detailed_log) {
                    error_log("APM: ✗ Pattern '$description' did not match");
                }
            }
        }
        
        // LAST RESORT: Search for any occurrence of gid://shopify/Product/
        if (preg_match('/gid:\/\/shopify\/Product\/(\d+)/', $html, $match)) {
            if ($detailed_log) {
                error_log("APM: ✓ Found product ID in GID format (last resort): " . $match[1]);
            }
            return $match[1];
        }
        
        if ($detailed_log) {
            error_log("APM: ✗ Could not find product ID with any pattern");
        }
        
        return null;
    }
    
    /**
     * Filter PDFs by product ID from JavaScript config
     */
    private function filter_pdfs_by_product_id($js_data, $product_id, $detailed_log = false) {
        if ($detailed_log) {
            error_log("APM: === FILTERING PDFs BY PRODUCT ID ===");
            error_log("APM: Product ID to filter by: $product_id");
            error_log("APM: JS data length: " . strlen($js_data) . " bytes");
            error_log("APM: First 500 chars of JS data: " . substr($js_data, 0, 500));
        }
        
        if (!$product_id) {
            if ($detailed_log) {
                error_log("APM: ✗ No product ID provided, cannot filter");
            }
            return array();
        }
        
        $filtered_filenames = array();
        $product_gid = 'gid://shopify/Product/' . $product_id;
        
        if ($detailed_log) {
            error_log("APM: Looking for product GID: $product_gid");
        }
        
        // Check if this is the full TPAConfigs object or just the array
        $attachments_array = $js_data;
        
        // If it's a full object, extract the product_attachments array
        if (strpos($js_data, 'product_attachments') !== false) {
            if ($detailed_log) {
                error_log("APM: Detected full TPAConfigs object, extracting product_attachments array");
            }
            
            if (preg_match('/"product_attachments"\s*:\s*(\[.*?\])/s', $js_data, $array_match)) {
                $attachments_array = $array_match[1];
                if ($detailed_log) {
                    error_log("APM: ✓ Extracted product_attachments array, length: " . strlen($attachments_array) . " bytes");
                }
            } else {
                if ($detailed_log) {
                    error_log("APM: ✗ Could not extract product_attachments array from object");
                }
                return array();
            }
        }
        
        // Parse each complete PDF object from the array
        preg_match_all('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $attachments_array, $object_matches);
        
        $total_objects = count($object_matches[0]);
        
        if ($detailed_log) {
            error_log("APM: Found $total_objects PDF objects in JavaScript");
        }
        
        if ($total_objects === 0) {
            if ($detailed_log) {
                error_log("APM: ✗ No PDF objects found - trying alternate parsing");
            }
            
            // Try a simpler approach - just find all link and apply_product pairs
            preg_match_all('/"link"\s*:\s*"([^"]*\.pdf[^"]*)"/', $attachments_array, $link_matches);
            preg_match_all('/"apply_product"\s*:\s*"([^"]*)"/', $attachments_array, $product_matches);
            
            if ($detailed_log) {
                error_log("APM: Alternate parsing found " . count($link_matches[1]) . " links and " . count($product_matches[1]) . " apply_product fields");
            }
        }
        
        foreach ($object_matches[0] as $index => $obj) {
            $obj_num = $index + 1;
            
            if ($detailed_log) {
                error_log("APM: --- Processing PDF object #$obj_num ---");
                error_log("APM: Object preview: " . substr($obj, 0, 200));
            }
            
            // Extract link from this object
            if (!preg_match('/"link"\s*:\s*"([^"]*\.pdf[^"]*)"/', $obj, $link_match)) {
                if ($detailed_log) {
                    error_log("APM: ✗ Object #$obj_num: No PDF link found");
                }
                continue;
            }
            
            $pdf_url = $link_match[1];
            $filename = basename(parse_url($pdf_url, PHP_URL_PATH));
            
            if ($detailed_log) {
                error_log("APM: ✓ Object #$obj_num: Found PDF link - $filename");
            }
            
            // Extract apply_product from this object
            if (!preg_match('/"apply_product"\s*:\s*"([^"]*)"/', $obj, $product_match)) {
                if ($detailed_log) {
                    error_log("APM: ✗ Object #$obj_num: No apply_product field found");
                }
                continue;
            }
            
            $apply_product = $product_match[1];
            
            if ($detailed_log) {
                error_log("APM: ✓ Object #$obj_num: apply_product = '$apply_product'");
            }
            
            // Check if this PDF applies to our product
            if (strpos($apply_product, $product_gid) !== false) {
                $filename = preg_replace('/\?.*$/', '', $filename);
                $filtered_filenames[] = $filename;
                
                if ($detailed_log) {
                    error_log("APM: ✓✓ Object #$obj_num: PDF MATCHES current product! Added: $filename");
                }
            } else {
                if ($detailed_log) {
                    error_log("APM: ✗ Object #$obj_num: PDF does NOT match (looking for: $product_gid)");
                }
            }
        }
        
        if ($detailed_log) {
            error_log("APM: === FILTERING COMPLETE - Found " . count($filtered_filenames) . " matching PDFs ===");
        }
        
        return array_unique($filtered_filenames);
    }
}
