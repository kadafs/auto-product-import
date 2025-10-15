/**
 * Admin JavaScript for Auto Product Import.
 *
 * @since      2.1.1
 * @package    Auto_Product_Import
 */

(function($) {
    'use strict';

    /**
     * Initialize the admin functionality.
     */
    function init() {
        // Form submission handler
        $('#apm-import-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $submitButton = $('#apm-import-submit');
            const $spinner = $submitButton.next('.spinner');
            const $message = $('#apm-import-message');
            const $result = $('#apm-import-result');
            const $resultContent = $('#apm-import-result-content');
            
            // Get the URL
            const url = $('#apm-product-url').val();
            
            if (!url) {
                showMessage('error', 'Please enter a valid URL.');
                return;
            }
            
            // Show spinner and disable submit button
            $spinner.addClass('is-active');
            $submitButton.prop('disabled', true);
            $message.hide();
            $result.hide();
            
            // Make AJAX request
            $.ajax({
                url: autoProductImportAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'import_product_from_url',
                    url: url,
                    nonce: autoProductImportAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Display success message
                        showMessage('updated', response.data.message);
                        
                        // Display result
                        $resultContent.html(
                            '<div class="apm-import-success">' +
                            '<p><strong>Product imported successfully!</strong></p>' +
                            '<p>Product ID: ' + response.data.product_id + '</p>' +
                            '<p>' +
                            '<a href="' + response.data.edit_link + '" class="button" target="_blank">Edit Product</a> ' +
                            '<a href="' + response.data.view_link + '" class="button" target="_blank">View Product</a>' +
                            '</p>' +
                            '</div>'
                        );
                        $result.show();
                    } else {
                        // Display error message
                        showMessage('error', response.data.message || 'An unknown error occurred.');
                    }
                },
                error: function() {
                    // Display error message
                    showMessage('error', 'An error occurred while processing the request.');
                },
                complete: function() {
                    // Hide spinner and enable submit button
                    $spinner.removeClass('is-active');
                    $submitButton.prop('disabled', false);
                }
            });
        });
    }
    
    /**
     * Show a message.
     *
     * @param {string} type    The message type ('updated', 'error').
     * @param {string} message The message to display.
     */
    function showMessage(type, message) {
        const $message = $('#apm-import-message');
        $message.removeClass('updated error')
                .addClass(type)
                .html('<p>' + message + '</p>')
                .show();
    }
    
    // Initialize on document ready
    $(document).ready(init);
    
})(jQuery);