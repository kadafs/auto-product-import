<?php
/**
 * Description Extractor Additional Info class
 * Handles extraction of structured product information
 *
 * @package Auto_Product_Import
 * @since 2.1.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_Description_Extractor_Additional_Info {
    
    /**
     * Extract additional product information
     *
     * @param string $description_html The description HTML
     * @param bool $debug Debug mode flag
     * @return array Additional info array
     */
    public function extract($description_html, $debug = false) {
        $fields_to_extract = array(
            'Caliber', 'Power Source', 'Velocity', 'Magazine Capacity', 'Action',
            'Frame Material', 'Barrel', 'Accessory Rail', 'Finish', 'Intended Use',
            'Length', 'Safety', 'Sights', 'Trigger', 'Weight'
        );
        
        $field_variations = $this->get_field_variations();
        $additional_info = array();
        
        if ($debug) {
            error_log('APM: Starting extraction of additional product information from HTML...');
        }
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<div>' . $description_html . '</div>');
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        $additional_info = $this->extract_from_schema($xpath, $fields_to_extract, $field_variations, $additional_info, $debug);
        $additional_info = $this->extract_from_html($description_html, $fields_to_extract, $field_variations, $additional_info, $debug);
        $additional_info = $this->extract_from_tables($xpath, $fields_to_extract, $field_variations, $additional_info, $debug);
        $additional_info = $this->extract_from_lists($xpath, $fields_to_extract, $field_variations, $additional_info, $debug);
        
        if ($debug) {
            error_log('APM: Completed extraction. Found ' . count($additional_info) . ' fields');
        }
        
        return $additional_info;
    }
    
    /**
     * Get field variations
     */
    private function get_field_variations() {
        return array(
            'Magazine Capacity' => array('Capacity', 'Mag Capacity', 'Mag. Capacity', 'Magazine Size'),
            'Frame Material' => array('Frame', 'Material', 'Construction'),
            'Accessory Rail' => array('Rail', 'Rails', 'Accessory', 'Rail Type'),
            'Intended Use' => array('Use', 'Purpose', 'Application'),
            'Power Source' => array('Power', 'Power Type', 'Source'),
            'Barrel' => array('Barrel Length', 'Barrel Size', 'Barrel Details', 'Barrel Specs'),
            'Action' => array('Action Type', 'Operating System'),
            'Finish' => array('Finish Type', 'Surface Finish', 'Color'),
            'Sights' => array('Sight', 'Sight System', 'Sight Type'),
            'Trigger' => array('Trigger Type', 'Trigger System', 'Trigger Pull'),
            'Weight' => array('Gun Weight', 'Product Weight', 'Total Weight'),
            'Safety' => array('Safety Type', 'Safety System', 'Safety Features'),
            'Length' => array('Overall Length', 'Total Length', 'Gun Length')
        );
    }
    
    /**
     * Extract from schema.org format
     */
    private function extract_from_schema($xpath, $fields, $variations, $info, $debug) {
        foreach ($fields as $field) {
            if (isset($info[$field])) continue;
            
            $field_lower = strtolower($field);
            $nodes = $xpath->query('//li[.//span[translate(normalize-space(text()), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "' . $field_lower . '"]]');
            
            if ($nodes && $nodes->length > 0) {
                $value_nodes = $xpath->query('.//span[@itemprop="value"]', $nodes->item(0));
                if ($value_nodes && $value_nodes->length > 0) {
                    $info[$field] = trim($value_nodes->item(0)->textContent);
                    if ($debug) {
                        error_log("APM: Found '$field' via schema.org: " . $info[$field]);
                    }
                    continue;
                }
            }
            
            if (isset($variations[$field])) {
                foreach ($variations[$field] as $variation) {
                    $variation_lower = strtolower($variation);
                    $nodes = $xpath->query('//li[.//span[translate(normalize-space(text()), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "' . $variation_lower . '"]]');
                    
                    if ($nodes && $nodes->length > 0) {
                        $value_nodes = $xpath->query('.//span[@itemprop="value"]', $nodes->item(0));
                        if ($value_nodes && $value_nodes->length > 0) {
                            $info[$field] = trim($value_nodes->item(0)->textContent);
                            if ($debug) {
                                error_log("APM: Found '$field' via schema.org (variation '$variation'): " . $info[$field]);
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        return $info;
    }
    
    /**
     * Extract from HTML patterns
     */
    private function extract_from_html($html, $fields, $variations, $info, $debug) {
        foreach ($fields as $field) {
            if (isset($info[$field])) continue;
            
            $separators = array(':', '-', '—', '=', '|');
            foreach ($separators as $separator) {
                $pattern = '/<li[^>]*>\s*' . preg_quote($field, '/') . '\s*' . preg_quote($separator, '/') . '\s*([^<]+)<\/li>/i';
                if (preg_match($pattern, $html, $matches)) {
                    $info[$field] = trim($matches[1]);
                    if ($debug) {
                        error_log("APM: Found '$field' via HTML regex with separator '$separator': " . $info[$field]);
                    }
                    break;
                }
            }
            
            if (!isset($info[$field]) && isset($variations[$field])) {
                foreach ($variations[$field] as $variation) {
                    foreach ($separators as $separator) {
                        $pattern = '/<li[^>]*>\s*' . preg_quote($variation, '/') . '\s*' . preg_quote($separator, '/') . '\s*([^<]+)<\/li>/i';
                        if (preg_match($pattern, $html, $matches)) {
                            $info[$field] = trim($matches[1]);
                            if ($debug) {
                                error_log("APM: Found '$field' via HTML regex (variation '$variation', separator '$separator'): " . $info[$field]);
                            }
                            break 2;
                        }
                    }
                }
            }
        }
        
        return $info;
    }
    
    /**
     * Extract from tables
     */
    private function extract_from_tables($xpath, $fields, $variations, $info, $debug) {
        foreach ($fields as $field) {
            if (isset($info[$field])) continue;
            
            $field_lower = strtolower($field);
            $nodes = $xpath->query('//tr[./td[1][contains(translate(normalize-space(text()), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "' . $field_lower . '")]]');
            
            if ($nodes && $nodes->length > 0) {
                $value_nodes = $xpath->query('./td[2]', $nodes->item(0));
                if ($value_nodes && $value_nodes->length > 0) {
                    $info[$field] = trim($value_nodes->item(0)->textContent);
                    if ($debug) {
                        error_log("APM: Found '$field' via table row: " . $info[$field]);
                    }
                    continue;
                }
            }
            
            if (isset($variations[$field])) {
                foreach ($variations[$field] as $variation) {
                    $variation_lower = strtolower($variation);
                    $nodes = $xpath->query('//tr[./td[1][contains(translate(normalize-space(text()), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "' . $variation_lower . '")]]');
                    
                    if ($nodes && $nodes->length > 0) {
                        $value_nodes = $xpath->query('./td[2]', $nodes->item(0));
                        if ($value_nodes && $value_nodes->length > 0) {
                            $info[$field] = trim($value_nodes->item(0)->textContent);
                            if ($debug) {
                                error_log("APM: Found '$field' via table row (variation '$variation'): " . $info[$field]);
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        return $info;
    }
    
    /**
     * Extract from list items
     */
    private function extract_from_lists($xpath, $fields, $variations, $info, $debug) {
        $li_nodes = $xpath->query('//li');
        
        if ($li_nodes && $li_nodes->length > 0) {
            foreach ($li_nodes as $li_node) {
                $li_text = trim($li_node->textContent);
                
                foreach ($fields as $field) {
                    if (isset($info[$field])) continue;
                    
                    if (stripos($li_text, $field . ':') === 0) {
                        $parts = explode(':', $li_text, 2);
                        if (count($parts) === 2) {
                            $info[$field] = trim($parts[1]);
                            if ($debug) {
                                error_log("APM: Found '$field' via list item text: " . $info[$field]);
                            }
                        }
                    }
                    
                    if (!isset($info[$field]) && isset($variations[$field])) {
                        foreach ($variations[$field] as $variation) {
                            if (stripos($li_text, $variation . ':') === 0) {
                                $parts = explode(':', $li_text, 2);
                                if (count($parts) === 2) {
                                    $info[$field] = trim($parts[1]);
                                    if ($debug) {
                                        error_log("APM: Found '$field' via list item text (variation '$variation'): " . $info[$field]);
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $info;
    }
}
