# Auto Product Import

Version: 2.1.4

## Description

Auto Product Import is a WordPress plugin that allows administrators to import products directly from external URLs. The plugin extracts product information such as title, description, price, images, PDFs, and SKUs, and creates a new WooCommerce product with the extracted data.

## Features

- Import products from any URL with just one click
- Automatically extract product title, description, price, and images
- **NEW in v2.1.4: SKU extraction with site-specific logic**
- **NEW in v2.1.4: Automatic GST calculation (10%) when "excl GST" detected**
- **NEW in v2.1.4: Duplicate SKU detection with fallback generation**
- **NEW in v2.1.4: Auto Product Sync integration (URL, Enable Sync, Add GST)**
- **NEW in v2.1.4: Advanced logging controls with detailed/basic separation**
- Configure default product settings (category and status)
- NEW in v2.1.3: PDF extraction and import functionality
- NEW in v2.1.3: Automatic PDF attachment to product pages
- NEW in v2.1.3: Duplicate PDF detection to prevent re-uploads
- NEW in v2.1.2: Shopify-specific image extraction with high-resolution support
- NEW in v2.1.2: Intelligent platform detection (Shopify, BigCommerce, Magento)
- NEW in v2.1.2: Enhanced srcset parsing for highest resolution images
- NEW in v2.1.1: Refactored codebase with modular architecture for easier maintenance
- NEW in v2.1.1: Configurable debug domain in settings
- Reorganized admin menu structure with dedicated top-level menu
- Separate Import and Settings pages
- Enhanced CSS class naming with apm prefix to avoid conflicts
- Frontend shortcode to display the import form: [apm_import_form]
- AJAX-powered import process with real-time feedback
- Responsive design for both admin and frontend
- Full WooCommerce HPOS (High-Performance Order Storage) compatibility
- BigCommerce-specific image extraction with high-resolution support
- Smart filtering to exclude non-product images (icons, logos, UI elements)
- Related products section detection to avoid importing irrelevant images

## Requirements

- WordPress 5.0 or higher
- WooCommerce 6.0 or higher (tested up to 9.0)
- PHP 7.2 or higher

## HPOS Compatibility

This plugin is fully compatible with WooCommerce's High-Performance Order Storage (HPOS):
- Explicitly declares compatibility with custom_order_tables feature
- Works seamlessly with HPOS-enabled WooCommerce stores
- No compatibility warnings in WordPress admin
- Future-proof for WooCommerce's evolution

Note: Although this plugin imports products (not orders), HPOS compatibility declaration is required by WooCommerce to prevent admin warnings. Products remain stored as custom post types regardless of HPOS settings.

## Installation

1. Upload the auto-product-import folder to the /wp-content/plugins/ directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Auto Product Import > Import to access the admin interface
4. Configure settings in Auto Product Import > Settings

## Configuration

### Settings Page

Navigate to **Auto Product Import > Settings** to configure:

1. **Default Product Category**: Choose which category imported products should be assigned to
2. **Default Product Status**: Set whether new products are created as Draft or Published
3. **Maximum Images to Import**: Limit the number of images imported per product (1-100)
4. **Maximum PDF Size (MB)**: Set the maximum file size for PDF uploads (1-100 MB)
5. **Debug Domain**: Control detailed logging behavior
   - Leave empty to enable detailed logging for ALL domains (when checkboxes are enabled)
   - Enter a domain (e.g., 'example.com') to only show detailed logs for that specific domain
6. **Detailed Logging Options**: Enable step-by-step logging for specific features
   - Enable detailed PDF extraction logging
   - Enable detailed SKU extraction logging
   - Enable detailed sync field logging

## Usage

### Admin Interface

1. Navigate to **Auto Product Import > Import**
2. Enter the product URL you want to import
3. Click **Import Product**
4. Wait for the import process to complete
5. The plugin will:
   - Extract product title, price, description, and SKU
   - Download and attach product images
   - Download and attach product PDFs
   - Apply GST calculation if "excl GST" is detected
   - Set Auto Product Sync fields (URL saved, sync disabled)
   - Create the product in WooCommerce

### Frontend Shortcode

Use the shortcode `[apm_import_form]` in any post or page to display the import form on the frontend. Users must have the "manage_woocommerce" capability to access this feature.

## SKU Extraction

The plugin includes intelligent SKU extraction with site-specific logic:

### Supported Sites

1. **topgunwelding.com.au**
   - Extracts from `<span class="product-sku__value">`
   - Example: TGWPCUT42PFCLCDBUNDLE

2. **eastwesteng.com.au**
   - Extracts from "Model" column in price table
   - Example: QSS25CB

3. **Generic Sites**
   - Attempts common SKU patterns
   - Fallback to generated SKU if extraction fails

### Duplicate SKU Handling

- Checks if extracted SKU already exists in WooCommerce
- If duplicate detected, generates unique fallback SKU (API-XXXX)
- Logs all SKU decisions for troubleshooting

## GST Calculation

The plugin automatically detects if prices exclude GST by searching for patterns like:
- "excl gst"
- "excl. gst"
- "excluding gst"
- "ex gst"
- "price excl"

When detected:
- Adds 10% to the imported price
- Sets the "Add GST" field in Auto Product Sync tab
- Logs the calculation (e.g., "$100.00 → $110.00")

## Auto Product Sync Integration

The plugin integrates with the Auto Product Sync plugin by setting:
- **URL**: The source URL for price synchronization
- **Enable Sync**: Disabled by default (must be manually enabled)
- **Add GST**: Auto-detected based on page content

## PDF Import

The plugin can extract and import PDF documents from product pages:

### Features
- Automatically detects PDF links on product pages
- Extracts caption/title from link text
- Checks for duplicate PDFs before uploading
- Supports size limits (configurable in settings)
- Categorizes PDFs to "Downloads" media category (if available)

### Supported Scenarios
- Direct PDF links in product pages
- JavaScript-loaded PDFs (Shopify Tigren app)
- PDFs in product tabs and specifications

## Logging

The plugin provides two levels of logging:

### Basic Logging (Always Enabled)
- Import start/completion messages
- Error messages
- Final counts (e.g., "Found 5 images", "Extracted SKU: ABC123")
- GST calculations
- Duplicate SKU warnings

### Detailed Logging (Optional)
- Step-by-step extraction process
- DOM/XPath search results
- Pattern matching details
- Debugging information

Control detailed logging via Settings > Detailed Logging Options.

## File Structure

```
auto-product-import/
├── assets/
│   ├── admin.css
│   ├── admin.js
│   ├── frontend.css
│   └── frontend.js
├── includes/
│   ├── admin/
│   │   ├── class-admin-menu.php
│   │   ├── class-settings-handler.php
│   │   └── class-template-data.php
│   ├── ajax/
│   │   └── class-ajax-handler.php
│   └── import/
│       ├── class-bigcommerce-extractor.php
│       ├── class-description-extractor.php
│       ├── class-html-parser.php
│       ├── class-image-extractor.php
│       ├── class-image-uploader.php
│       ├── class-pdf-extractor.php
│       ├── class-pdf-uploader.php
│       ├── class-product-creator.php
│       ├── class-product-scraper.php
│       └── class-shopify-extractor.php
├── templates/
│   ├── import-form.php
│   ├── import-page.php
│   └── settings-page.php
├── auto-product-import.php
├── functions.php
├── uninstall.php
└── README.md
```

## How It Works

1. **URL Validation**: The plugin validates the provided URL
2. **Content Fetching**: Retrieves HTML content from the URL
3. **Platform Detection**: Identifies if the page is from Shopify, BigCommerce, or other platforms
4. **Data Extraction**:
   - Title from h1 tags or meta tags
   - Price from price elements or meta tags
   - SKU using site-specific extraction logic
   - Description from product description containers
   - Images with platform-specific high-resolution extraction
   - PDFs from links or JavaScript configurations
5. **GST Detection**: Searches for "excl gst" patterns in HTML
6. **Price Calculation**: Adds 10% GST if required
7. **SKU Validation**: Checks for duplicates and generates fallback if needed
8. **Media Upload**: Downloads and processes images and PDFs
9. **Product Creation**: Creates WooCommerce product with all extracted data
10. **Auto Product Sync Setup**: Configures sync fields for future updates

## Troubleshooting

### Images Not Importing
- Check the Maximum Images setting in Settings
- Verify the source website allows image downloads
- Check WordPress debug.log for specific errors
- Enable detailed logging for images (if available in settings)

### PDF Import Issues
- Verify PDFs are linked on the product page
- Check PDF file size against the Maximum PDF Size setting
- Look for "PDF extraction" entries in debug.log
- Enable detailed PDF logging in settings
- Some sites load PDFs via JavaScript - check logs for regex fallback

### SKU Not Extracted
- Enable detailed SKU logging in settings
- Check debug.log for extraction attempts
- Verify the site is supported or uses common SKU patterns
- Plugin will generate fallback SKU (API-XXXX) if extraction fails

### GST Not Applied
- Enable detailed sync field logging
- Check if "excl gst" or similar patterns appear on the page
- Verify in debug.log that GST detection ran
- Manually verify the "Add GST" field in Auto Product Sync tab

### Price Issues
- Verify price is displayed on the source page
- Check if price requires JavaScript rendering
- Enable debug logging to see extraction attempts
- Check if GST was correctly applied to the price

## Changelog

### Version 2.1.4 (2025-10-04)
- Added intelligent SKU extraction with site-specific logic
- Added automatic GST calculation (10%) when "excl GST" detected
- Added duplicate SKU detection with fallback generation
- Added Auto Product Sync integration (URL, Enable Sync, Add GST)
- Added advanced logging controls (basic vs detailed)
- Added debug domain filter for selective detailed logging
- Improved price handling with GST-inclusive calculations
- Removed unused "Websites" admin tab
- Enhanced error handling for duplicate SKUs

### Version 2.1.3 (2025-10-03)
- Added PDF extraction and import functionality
- Added automatic PDF attachment to product pages
- Added duplicate PDF detection to prevent re-uploads
- Added PDF size validation and limits
- Added support for JavaScript-loaded PDFs
- Enhanced logging for PDF operations

### Version 2.1.2 (2024-12-15)
- Added Shopify-specific image extraction with high-resolution support
- Added intelligent platform detection (Shopify, BigCommerce, Magento)
- Enhanced srcset parsing for highest resolution images
- Added BigCommerce-specific image extraction
- Improved filtering to exclude non-product images

### Version 2.1.1 (2024-11-10)
- Refactored codebase with modular architecture
- Added configurable debug domain setting
- Enhanced CSS class naming with apm prefix
- Improved code organization and maintainability

### Version 2.1.0 (2024-10-20)
- Reorganized admin menu structure
- Added separate Settings page
- Enhanced AJAX handling

### Version 2.0.0 (2024-09-15)
- Complete rewrite with improved architecture
- Added HPOS compatibility
- Enhanced image extraction

### Version 1.0.0 (2024-08-01)
- Initial release

## Support

For support, please contact your developer or submit an issue to the plugin repository.

## License

GPL v2 or later

## Credits

Developed by ArtInMetal
