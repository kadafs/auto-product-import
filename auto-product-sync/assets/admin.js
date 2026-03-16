jQuery(document).ready(function($) {
    'use strict';
    
    // Debug: Check if aps_ajax object is available
    if (typeof aps_ajax === 'undefined') {
        console.error('APS: aps_ajax object not found. JavaScript localization may have failed.');
        return;
    }
    
    console.log('APS: JavaScript loaded successfully. AJAX URL:', aps_ajax.ajax_url);
    
    // Single product sync - Using event delegation for dynamically added buttons
    $(document).on('click', '.aps-sync-single', function(e) {
        e.preventDefault();
        console.log('APS: Sync button clicked');
        
        var button = $(this);
        var productId = button.data('product-id');
        var row = button.closest('tr');
        var statusColumn = row.find('.product-error');
        var retryColumn = row.find('.product-retry');
        var timestampColumn = row.find('.product-timestamp');
        
        console.log('APS: Syncing product ID:', productId);
        
        if (!productId) {
            console.error('APS: No product ID found');
            return;
        }
        
        // Store original button text and disable button
        var originalText = button.text();
        button.prop('disabled', true).text('Syncing...');
        
        // Clear any existing error messages in the status column
        statusColumn.html('—');
        
        $.ajax({
            url: aps_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aps_sync_single_product',
                product_id: productId,
                security: aps_ajax.nonce
            },
            beforeSend: function() {
                console.log('APS: AJAX request sent for product', productId);
            },
            success: function(response) {
                console.log('APS: AJAX response received:', response);
                
                if (response && response.success) {
                    // Update row with fresh data from server
                    updateProductRow(productId, statusColumn, retryColumn, timestampColumn);
                    
                    // Show success in button temporarily
                    button.text('✓ Success');
                    setTimeout(function() {
                        button.text(originalText);
                    }, 2000);
                } else {
                    var errorMsg = (response && response.data) ? response.data : 'Unknown error';
                    // Show error in status column
                    statusColumn.html('<div class="aps-last-status aps-error-status">Error: ' + errorMsg + '</div>');
                    
                    // Update retry and timestamp columns
                    updateProductRow(productId, statusColumn, retryColumn, timestampColumn);
                    
                    // Show error in button temporarily
                    button.text('✗ Failed');
                    setTimeout(function() {
                        button.text(originalText);
                    }, 3000);
                }
            },
            error: function(xhr, status, error) {
                console.error('APS: AJAX error:', xhr.responseText, status, error);
                
                // Show connection error in status column
                statusColumn.html('<div class="aps-last-status aps-error-status">Error: Connection failed</div>');
                
                // Show error in button temporarily
                button.text('✗ Connection Error');
                setTimeout(function() {
                    button.text(originalText);
                }, 3000);
            },
            complete: function() {
                // Re-enable button
                button.prop('disabled', false);
                console.log('APS: AJAX request completed for product', productId);
            }
        });
    });
    
    // Function to update a single product row with fresh data
    function updateProductRow(productId, statusColumn, retryColumn, timestampColumn) {
        $.ajax({
            url: aps_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aps_get_product_row_data',
                product_id: productId,
                security: aps_ajax.nonce
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    var data = response.data;
                    
                    // Update status column
                    var statusHtml = '';
                    if (data.error_date) {
                        statusHtml += '<div class="aps-error-date">' + data.error_date + '</div>';
                    }
                    if (data.last_status) {
                        statusHtml += '<div class="aps-last-status">' + data.last_status + '</div>';
                    }
                    if (!statusHtml) {
                        statusHtml = '—';
                    }
                    statusColumn.html(statusHtml);
                    
                    // Update retry column
                    retryColumn.html('<span class="aps-retry-count">' + data.retry_count + '/3</span>');
                    
                    // Update timestamp column
                    if (data.timestamp) {
                        timestampColumn.html(data.timestamp);
                    } else {
                        timestampColumn.html('');
                    }
                }
            }
        });
    }
    
    // Bulk sync
    $('#aps-sync-all').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var statusEl = $('#aps-sync-status');
        var progressContainer = $('#aps-progress-container');
        var progressBar = $('#aps-progress-bar');
        var progressText = $('#aps-progress-text');
        
        // Use localized confirmation message
        var confirmMessage = 'This will sync all enabled products. This may take several minutes. Continue?';
        if (aps_ajax.strings && aps_ajax.strings.confirm_bulk) {
            confirmMessage = aps_ajax.strings.confirm_bulk;
        }
        
        if (confirm(confirmMessage)) {
            console.log('APS: Bulk sync started');
            
            // Store original button text
            var originalText = button.text();
            
            button.prop('disabled', true).text('Starting...');
            statusEl.removeClass('aps-success aps-error').text('Initiating bulk sync...');
            
            if (progressContainer.length) {
                progressContainer.show();
                progressBar.css('width', '0%');
                progressText.text('0%');
            }
            
            $.ajax({
                url: aps_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'aps_sync_all_products',
                    security: aps_ajax.nonce
                },
                success: function(response) {
                    console.log('APS: Bulk sync response:', response);
                    
                    if (response && response.success) {
                        statusEl.addClass('aps-success').text('First batch processing...');
                        button.text('Syncing...');
                        // Start polling for status updates after first batch completes
                        setTimeout(function() {
                            pollSyncStatus(originalText, button, statusEl, progressContainer, progressBar, progressText);
                        }, 1000);
                    } else {
                        var errorMsg = (response && response.data) ? response.data : 'Unknown error';
                        statusEl.addClass('aps-error').text('✗ ' + errorMsg);
                        button.prop('disabled', false).text(originalText);
                        if (progressContainer.length) {
                            progressContainer.hide();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('APS: Bulk sync error:', xhr.responseText, status, error);
                    var errorMessage = 'Connection error';
                    
                    // Try to get more specific error info
                    if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data) {
                                errorMessage = errorResponse.data;
                            }
                        } catch (e) {
                            // If not JSON, use status text
                            if (xhr.statusText) {
                                errorMessage = 'Connection error: ' + xhr.statusText;
                            }
                        }
                    }
                    
                    statusEl.addClass('aps-error').text('✗ ' + errorMessage);
                    button.prop('disabled', false).text(originalText);
                    if (progressContainer.length) {
                        progressContainer.hide();
                    }
                }
            });
        }
    });
    
    // Clear lock button
    $('#aps-clear-lock').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var originalText = button.text();
        
        if (confirm('This will clear all sync locks and stop any running syncs. Continue?')) {
            button.prop('disabled', true).text('Clearing...');
            
            $.ajax({
                url: aps_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'aps_clear_lock',
                    security: aps_ajax.nonce
                },
                success: function(response) {
                    if (response && response.success) {
                        alert('Locks cleared successfully. You can now start a new sync.');
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Connection error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        }
    });
    
    // Poll sync status function with batch triggering
    function pollSyncStatus(originalButtonText, button, statusEl, progressContainer, progressBar, progressText) {
        console.log('APS: Starting status polling');
        
        var pollCount = 0;
        var maxPolls = 600; // 30 minutes at 3-second intervals
        var firstPoll = true;
        
        var pollInterval = setInterval(function() {
            pollCount++;
            console.log('APS: Status poll #' + pollCount);
            
            // On first poll, trigger the batch to start
            if (firstPoll) {
                firstPoll = false;
                triggerNextBatch();
            }
            
            $.ajax({
                url: aps_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'aps_get_sync_status',
                    security: aps_ajax.nonce
                },
                success: function(response) {
                    console.log('APS: Status poll response:', response);
                    
                    if (response && response.success && response.data) {
                        var status = response.data;
                        
                        // Check if we need to trigger the next batch
                        if (status.needs_next_batch === true) {
                            console.log('APS: Triggering next batch');
                            triggerNextBatch();
                        }
                        
                        if (status.running) {
                            var percentage = status.total > 0 ? Math.round((status.completed / status.total) * 100) : 0;
                            
                            // Show current product name if available
                            if (status.current_product && status.current_product !== 'Preparing...') {
                                statusEl.text('Syncing: ' + status.current_product);
                            } else {
                                statusEl.text('In Progress (' + status.completed + '/' + status.total + ')');
                            }
                            
                            if (progressBar.length && progressText.length) {
                                progressBar.css('width', percentage + '%');
                                progressText.text(status.completed + '/' + status.total + ' (' + percentage + '%)');
                            }
                            
                            console.log('APS: Progress update - ' + status.completed + '/' + status.total + ' (' + percentage + '%) - Current: ' + (status.current_product || 'Unknown'));
                        } else if (status.finished) {
                            clearInterval(pollInterval);
                            statusEl.removeClass('aps-success aps-error');
                            
                            // Check if aborted
                            if (status.aborted) {
                                statusEl.addClass('aps-error').text('Aborted by user - ' + status.completed + ' of ' + status.total + ' products synced');
                                button.text(originalButtonText)
                                     .removeClass('button-secondary aps-aborting')
                                     .addClass('button-primary')
                                     .css({'background-color': '', 'border-color': '', 'color': ''});
                            } else if (status.failed && status.failed > 0) {
                                statusEl.addClass('aps-error').text('✗ Completed with ' + status.failed + ' errors');
                                button.text('✗ Completed with Errors')
                                     .removeClass('button-secondary aps-aborting')
                                     .addClass('button-primary')
                                     .css({'background-color': '', 'border-color': '', 'color': ''});
                            } else {
                                statusEl.addClass('aps-success').text('✓ Complete');
                                button.text('✓ Complete')
                                     .removeClass('button-secondary aps-aborting')
                                     .addClass('button-primary')
                                     .css({'background-color': '', 'border-color': '', 'color': ''});
                            }
                            
                            if (progressBar.length && progressText.length) {
                                progressBar.css('width', '100%');
                                progressText.text((status.completed || 0) + '/' + (status.total || 0) + ' (100%)');
                            }
                            
                            button.prop('disabled', false);
                            
                            console.log('APS: Bulk sync completed - ' + status.completed + ' successful, ' + (status.failed || 0) + ' failed');
                            
                            // Update all table rows after bulk sync completes
                            updateTableRows();
                            
                            // Hide progress after delay and reset button
                            setTimeout(function() {
                                if (progressContainer.length) {
                                    progressContainer.fadeOut();
                                }
                                if (!status.aborted) {
                                    button.text(originalButtonText);
                                    statusEl.text('');
                                }
                            }, 3000);
                        }
                    } else {
                        console.warn('APS: Invalid status response:', response);
                        // If we get 10 invalid responses, assume something is wrong
                        if (pollCount > 10) {
                            clearInterval(pollInterval);
                            statusEl.addClass('aps-error').text('✗ Status tracking failed');
                            button.prop('disabled', false).text(originalButtonText);
                            if (progressContainer.length) {
                                progressContainer.hide();
                            }
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('APS: Status poll error:', xhr.responseText, status, error);
                    
                    // If we get too many polling errors, stop
                    if (pollCount > 5) {
                        clearInterval(pollInterval);
                        statusEl.addClass('aps-error').text('✗ Status check failed');
                        button.prop('disabled', false).text(originalButtonText);
                        if (progressContainer.length) {
                            progressContainer.hide();
                        }
                    }
                }
            });
            
            // Stop polling after maximum attempts
            if (pollCount >= maxPolls) {
                clearInterval(pollInterval);
                statusEl.removeClass('aps-success aps-error').text('Sync timeout - check logs');
                button.prop('disabled', false).text(originalButtonText);
                if (progressContainer.length) {
                    progressContainer.hide();
                }
            }
        }, 3000);
    }
    
    // Function to trigger the next batch
    function triggerNextBatch() {
        $.ajax({
            url: aps_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aps_trigger_next_batch',
                security: aps_ajax.nonce
            },
            success: function(response) {
                if (response && response.success) {
                    console.log('APS: Next batch triggered successfully', response.data);
                } else {
                    console.warn('APS: Failed to trigger next batch', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('APS: Error triggering next batch:', xhr.responseText, status, error);
            }
        });
    }
    
    // Product table sorting
    if ($('#aps-products-table').length) {
        $('.aps-sortable').on('click', function() {
            var table = $('#aps-products-table tbody');
            var column = $(this).data('column');
            var rows = table.find('tr').toArray();
            var isAsc = $(this).hasClass('asc');
            
            // Remove existing sort classes
            $('.aps-sortable').removeClass('asc desc');
            
            // Add sort class
            $(this).addClass(isAsc ? 'desc' : 'asc');
            
            rows.sort(function(a, b) {
                var aText, bText;
                
                switch(column) {
                    case 'name':
                        aText = $(a).find('.product-name a').text().trim();
                        bText = $(b).find('.product-name a').text().trim();
                        break;
                    case 'category':
                        aText = $(a).find('.product-category').text().trim();
                        bText = $(b).find('.product-category').text().trim();
                        break;
                    case 'enabled':
                        aText = $(a).find('.product-enabled').text().trim();
                        bText = $(b).find('.product-enabled').text().trim();
                        aText = aText === 'Yes' ? 1 : 0;
                        bText = bText === 'Yes' ? 1 : 0;
                        break;
                    case 'status':
                        aText = $(a).find('.product-error').text().trim();
                        bText = $(b).find('.product-error').text().trim();
                        break;
                    case 'retry':
                        aText = $(a).find('.aps-retry-count').text().trim();
                        bText = $(b).find('.aps-retry-count').text().trim();
                        aText = parseInt(aText.split('/')[0]) || 0;
                        bText = parseInt(bText.split('/')[0]) || 0;
                        break;
                    case 'timestamp':
                        aText = $(a).find('.product-timestamp').text().trim();
                        bText = $(b).find('.product-timestamp').text().trim();
                        // Empty timestamps go to bottom
                        if (!aText) aText = '0';
                        if (!bText) bText = '0';
                        break;
                    default:
                        aText = $(a).find('td').eq($(this).index()).text().trim();
                        bText = $(b).find('td').eq($(this).index()).text().trim();
                }
                
                var result;
                if (typeof aText === 'number' && typeof bText === 'number') {
                    result = aText - bText;
                } else {
                    result = aText.toString().localeCompare(bText.toString(), undefined, {
                        numeric: true,
                        sensitivity: 'base'
                    });
                }
                
                return isAsc ? -result : result;
            });
            
            table.empty().append(rows);
        });
        
        // Update sort indicators
        $('.aps-sortable').hover(
            function() {
                $(this).find('.sort-indicator').css('opacity', '1');
            },
            function() {
                if (!$(this).hasClass('asc') && !$(this).hasClass('desc')) {
                    $(this).find('.sort-indicator').css('opacity', '0.5');
                }
            }
        );
    }
    
    // Form validation
    $('form[action*="aps"]').on('submit', function() {
        var form = $(this);
        var hasErrors = false;
        
        // Validate schedule time
        var scheduleTime = form.find('[name="aps_schedule_time"]').val();
        if (scheduleTime && !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(scheduleTime)) {
            alert('Please enter a valid time in HH:MM format (24-hour)');
            hasErrors = true;
        }
        
        // Validate email
        var email = form.find('[name="aps_admin_email"]').val();
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert('Please enter a valid email address');
            hasErrors = true;
        }
        
        return !hasErrors;
    });
    
    // Tooltips for help text
    $('[data-tooltip]').hover(
        function() {
            var tooltip = $('<div class="aps-tooltip">' + $(this).data('tooltip') + '</div>');
            $('body').append(tooltip);
            
            var pos = $(this).offset();
            tooltip.css({
                top: pos.top - tooltip.outerHeight() - 5,
                left: pos.left + ($(this).outerWidth() / 2) - (tooltip.outerWidth() / 2)
            }).fadeIn(200);
        },
        function() {
            $('.aps-tooltip').remove();
        }
    );
    
    // Function to update all visible table rows
    function updateTableRows() {
        var productIds = [];
        $('#aps-products-table tbody tr').each(function() {
            var productId = $(this).data('product-id');
            if (productId) {
                productIds.push(productId);
            }
        });
        
        if (productIds.length === 0) {
            return;
        }
        
        $.ajax({
            url: aps_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'aps_get_multiple_product_rows',
                product_ids: productIds,
                security: aps_ajax.nonce
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    $.each(response.data, function(productId, data) {
                        var row = $('#aps-products-table tbody tr[data-product-id="' + productId + '"]');
                        if (row.length) {
                            // Update status column
                            var statusHtml = '';
                            if (data.error_date) {
                                statusHtml += '<div class="aps-error-date">' + data.error_date + '</div>';
                            }
                            if (data.last_status) {
                                statusHtml += '<div class="aps-last-status">' + data.last_status + '</div>';
                            }
                            if (!statusHtml) {
                                statusHtml = '—';
                            }
                            row.find('.product-error').html(statusHtml);
                            
                            // Update retry column
                            row.find('.product-retry').html('<span class="aps-retry-count">' + data.retry_count + '/3</span>');
                            
                            // Update timestamp column
                            if (data.timestamp) {
                                row.find('.product-timestamp').html(data.timestamp);
                            } else {
                                row.find('.product-timestamp').html('');
                            }
                        }
                    });
                }
            }
        });
    }
});
