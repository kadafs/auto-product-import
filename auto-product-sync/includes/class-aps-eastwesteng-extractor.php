<?php
/**
 * East West Engineering Price Extractor
 *
 * Handles price extraction for eastwesteng.com.au product pages.
 * The site uses a grouped-items price table inside a woocommerce-Tabs-panel--grouped_items div.
 * Prices are stored as GST-exclusive values in data-base-price attributes.
 * The raw ex-GST price is returned so that the product-level "Add GST" and
 * "Add Margin" checkboxes in Auto Product Sync control the final price.
 *
 * @package Auto_Product_Sync
 * @since 1.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class APS_EastWestEng_Extractor {

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
     * Find the ex-GST price for a specific SKU/model within the eastwesteng pricing table.
     *
     * The pricing table lives inside:
     *   div.woocommerce-Tabs-panel--grouped_items > table > tbody > tr
     *
     * Each data row contains:
     *   - span.child-product-name  — the Model code (matches WooCommerce SKU)
     *   - span.price-value[data-base-price]  — the ex-GST price
     *
     * @param DOMXPath $xpath  XPath instance for the parsed page
     * @param string   $sku    WooCommerce product SKU to match against the Model column
     * @return float|false     Ex-GST price on success, false if not found
     */
    public function extract_price_for_sku($xpath, $sku) {
        // Locate the grouped_items tab panel
        $panel_nodes = $xpath->query(
            '//div[contains(@class,"woocommerce-Tabs-panel--grouped_items")]'
        );

        if (!$panel_nodes || $panel_nodes->length === 0) {
            return false;
        }

        $panel = $panel_nodes->item(0);

        // Find all <tr> elements inside the panel's table body
        $tr_nodes = $xpath->query('.//tbody/tr', $panel);
        if (!$tr_nodes || $tr_nodes->length === 0) {
            return false;
        }

        foreach ($tr_nodes as $tr) {
            // Skip the GST toggle button row
            if ($xpath->query('.//button[@id="toggle-tax"]', $tr)->length > 0) {
                continue;
            }

            // Get the Model/SKU from .child-product-name
            $sku_nodes = $xpath->query('.//*[contains(@class,"child-product-name")]', $tr);
            if (!$sku_nodes || $sku_nodes->length === 0) {
                continue;
            }

            $row_sku = trim($sku_nodes->item(0)->textContent);
            if (empty($row_sku)) {
                continue;
            }

            // Case-insensitive comparison to be forgiving of capitalisation differences
            if (strcasecmp($row_sku, $sku) !== 0) {
                continue;
            }

            // Matching row found — read ex-GST price from data-base-price attribute
            $price_nodes = $xpath->query('.//*[contains(@class,"price-value")]', $tr);
            if ($price_nodes && $price_nodes->length > 0) {
                $base_price_attr = $price_nodes->item(0)->getAttribute('data-base-price');
                if (is_numeric($base_price_attr)) {
                    $price = (float) $base_price_attr;
                    if ($price > 0) {
                        return $price;
                    }
                }
            }
        }

        return false;
    }
}
