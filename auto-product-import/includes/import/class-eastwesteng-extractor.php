<?php
/**
 * East West Engineering Extractor
 *
 * Handles scraping of product rows from eastwesteng.com.au pages.
 * The site uses a grouped-items price table inside a product-sec-tabs div.
 * Prices are stored as GST-exclusive in data-base-price attributes; this
 * extractor multiplies by 1.1 to produce the GST-inclusive price.
 *
 * @package Auto_Product_Import
 * @since 2.2.4
 */

if (!defined('WPINC')) {
    die;
}

class APM_EastWestEng_Extractor {

    /**
     * Determine whether a URL belongs to eastwesteng.com.au
     *
     * @param string $url
     * @return bool
     */
    public static function is_eastwesteng_url($url) {
        $host = strtolower(parse_url($url, PHP_URL_HOST));
        return $host === 'www.eastwesteng.com.au' || $host === 'eastwesteng.com.au';
    }

    /**
     * Extract product rows from the Prices tab of an eastwesteng product page.
     *
     * Each returned row contains:
     *   - sku   (string) : value of .child-product-name
     *   - title (string) : description cell text (mobile label stripped)
     *   - price (string) : GST-inclusive price rounded to 2 dp (e.g. "2805.00")
     *
     * Returns an empty array when the expected structure is not found.
     *
     * @param DOMXPath $xpath     XPath instance for the parsed page
     * @param bool     $debug     Whether to write debug log entries
     * @return array
     */
    public function extract_rows($xpath, $debug = false) {
        $rows = array();

        // Locate the grouped_items tab panel
        $panel_nodes = $xpath->query(
            '//div[contains(@class,"woocommerce-Tabs-panel--grouped_items")]'
        );

        if (!$panel_nodes || $panel_nodes->length === 0) {
            if ($debug) {
                error_log('APM EastWestEng: grouped_items tab panel not found');
            }
            return $rows;
        }

        $panel = $panel_nodes->item(0);

        // Find all <tr> elements inside the panel's table body
        $tr_nodes = $xpath->query('.//tbody/tr', $panel);
        if (!$tr_nodes || $tr_nodes->length === 0) {
            if ($debug) {
                error_log('APM EastWestEng: no <tr> rows found in pricing table');
            }
            return $rows;
        }

        foreach ($tr_nodes as $tr) {
            // Skip the GST toggle button row
            if ($xpath->query('.//button[@id="toggle-tax"]', $tr)->length > 0) {
                continue;
            }

            // --- SKU: first .child-product-name in this row ---
            $sku_nodes = $xpath->query('.//*[contains(@class,"child-product-name")]', $tr);
            if (!$sku_nodes || $sku_nodes->length === 0) {
                continue; // Not a data row
            }
            $sku = trim($sku_nodes->item(0)->textContent);
            if (empty($sku)) {
                continue;
            }

            // --- Title: .pricecol containing Description mobile label ---
            $title = '';
            $pricecol_nodes = $xpath->query('.//td[contains(@class,"pricecol")]', $tr);
            foreach ($pricecol_nodes as $td) {
                $mobile_label_nodes = $xpath->query('.//*[contains(@class,"mobile-label-display")]', $td);
                if ($mobile_label_nodes && $mobile_label_nodes->length > 0) {
                    $label_text = trim($mobile_label_nodes->item(0)->textContent);
                    if (stripos($label_text, 'Description') !== false) {
                        // Get full cell text then strip the mobile label portion
                        $full_text  = trim($td->textContent);
                        $label_raw  = trim($mobile_label_nodes->item(0)->textContent);
                        $title      = trim(str_replace($label_raw, '', $full_text));
                        break;
                    }
                }
            }

            if (empty($title)) {
                $title = $sku; // Fallback
            }

            // --- Price: data-base-price attribute × 1.1 (GST inclusive) ---
            $price       = '';
            $price_nodes = $xpath->query('.//*[contains(@class,"price-value")]', $tr);
            if ($price_nodes && $price_nodes->length > 0) {
                $base_price_attr = $price_nodes->item(0)->getAttribute('data-base-price');
                if (is_numeric($base_price_attr)) {
                    $base  = (float) $base_price_attr;
                    $incl  = $base * 1.1;
                    $price = number_format(round($incl, 2), 2, '.', '');
                }
            }

            if ($debug) {
                error_log("APM EastWestEng: row found — SKU: $sku | Title: $title | Price: $price");
            }

            $rows[] = array(
                'sku'   => $sku,
                'title' => $title,
                'price' => $price,
            );
        }

        if ($debug) {
            error_log('APM EastWestEng: total rows extracted: ' . count($rows));
        }

        return $rows;
    }
}
