/**
 * Frontend JavaScript for Auto Product Import.
 *
 * @since      2.1.1
 * @package    Auto_Product_Import
 */

(function($) {
    'use strict';

    /**
     * Initialize the frontend functionality.
     */
    function init() {
        // Form submission handler
        $('#apm-import-frontend-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $submitButton = $('.apm-import-submit-button');
            const $spinner = $('.apm-import-spinner');
            const $message = $('#apm-import-frontend-message');
            const $result = $('#apm-import-frontend-result');
            const $resultContent = $('#apm-import-frontend-result-content');
            
            // Get the URL
            const url = $('#apm-import-url').val();
            
            if (!url) {
                showMessage('error', 'Please enter a valid URL.');
                return;
            }
            
            // Show spinner and disable submit button
            $spinner.show();
            $submitButton.prop('disabled', true);
            $message.hide();
            $result.hide();
            
            // Make AJAX request
            $.ajax({
                url: autoProductImportFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'import_product_from_url',
                    url: url,
                    nonce: autoProductImportFrontend.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Display success message
                        showMessage('success', response.data.message);
                        
                        // Display result
                        $resultContent.html(
                            '<div class="apm-import-success">' +
                            '<p><strong>Product imported successfully!</strong></p>' +
                            '<p>Product ID: ' + response.data.product_id + '</p>' +
                            '<p>' +
                            '<a href="' + response.data.view_link + '" class="apm-import-button" target="_blank">View Product</a>' +
                            '</p>' +
                            '</div>'
                        );
                        $result.show();
                        
                        // Clear the URL input
                        $('#apm-import-url').val('');
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
                    $spinner.hide();
                    $submitButton.prop('disabled', false);
                }
            });
        });
    }
    
    /**
     * Show a message.
     *
     * @param {string} type    The message type ('success', 'error').
     * @param {string} message The message to display.
     */
    function showMessage(type, message) {
        const $message = $('#apm-import-frontend-message');
        $message.removeClass('success error')
                .addClass(type)
                .html('<p>' + message + '</p>')
                .show();
    }
    
    // Initialize on document ready
    $(document).ready(init);
    
})(jQuery);