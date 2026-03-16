/**
 * Import Queue JavaScript
 * 
 * Handles batch import UI and AJAX requests
 */

(function($) {
    'use strict';
    
    var importQueue = {
        isImporting: false,
        isStopping: false,
        currentQueueId: null,
        
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            $('#apm-batch-import-btn').on('click', this.handleBatchImportClick.bind(this));
            $(window).on('beforeunload', this.handleBeforeUnload.bind(this));
        },
        
        /**
         * Handle batch import button click
         */
        handleBatchImportClick: function(e) {
            e.preventDefault();
            
            if (this.isImporting) {
                // Stop import
                this.stopImport();
            } else {
                // Start import
                this.startBatchImport();
            }
        },
        
        /**
         * Start batch import
         */
        startBatchImport: function() {
            this.isImporting = true;
            this.isStopping = false;
            
            // Update button
            $('#apm-batch-import-btn')
                .text(apmImportQueue.strings.stopImport)
                .addClass('importing');
            
            // Show progress
            $('.apm-batch-progress').show().text(apmImportQueue.strings.importing);
            
            // Start processing
            this.processNext();
        },
        
        /**
         * Process next product
         */
        processNext: function() {
            var self = this;
            
            if (this.isStopping) {
                this.finishImport(apmImportQueue.strings.stopImport);
                return;
            }
            
            $.ajax({
                url: apmImportQueue.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'apm_import_queue_batch_import',
                    nonce: apmImportQueue.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.handleImportSuccess(response.data);
                    } else {
                        self.handleImportError(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    self.finishImport('An error occurred during import');
                }
            });
        },
        
        /**
         * Handle import success
         */
        handleImportSuccess: function(data) {
            // Update stats
            this.updateStats(data.stats);
            
            // Check if stopped
            if (data.stopped) {
                this.finishImport(data.message);
                this.reloadTables();
                return;
            }
            
            // Check if completed
            if (data.completed) {
                this.finishImport(data.message);
                this.reloadTables();
                return;
            }
            
            // Check if error
            if (data.error) {
                console.error('Import error:', data.message);
                // Mark row as error and continue
                this.markRowAsError(data.queue_id);
            } else {
                // Success - mark row as imported
                this.markRowAsImported(data.queue_id);
            }
            
            // Update progress
            var pending = data.stats.pending;
            var total = data.stats.pending + data.stats.imported + data.stats.errors;
            $('.apm-batch-progress').text(
                apmImportQueue.strings.importing + ' (' + (total - pending) + '/' + total + ')'
            );
            
            // Continue with next product
            setTimeout(function() {
                this.processNext();
            }.bind(this), 500);
        },
        
        /**
         * Handle import error
         */
        handleImportError: function(data) {
            console.error('Import error:', data);
            this.finishImport(data.message || 'An error occurred');
            this.reloadTables();
        },
        
        /**
         * Mark row as imported
         */
        markRowAsImported: function(queueId) {
            var $row = $('#apm-batch-import-table tr[data-queue-id="' + queueId + '"]');
            if ($row.length) {
                $row.fadeOut(400, function() {
                    $(this).remove();
                    // Check if table is empty
                    if ($('#apm-batch-import-table tbody tr').length === 0) {
                        this.reloadTables();
                    }
                }.bind(this));
            }
        },
        
        /**
         * Mark row as error
         */
        markRowAsError: function(queueId) {
            var $row = $('#apm-batch-import-table tr[data-queue-id="' + queueId + '"]');
            if ($row.length) {
                $row.removeClass('processing-row');
                $row.find('.column-status').html(
                    '<span class="status-error">' + apmImportQueue.strings.error + '</span>'
                );
                
                // Fade out after delay
                setTimeout(function() {
                    $row.fadeOut(400, function() {
                        $(this).remove();
                    });
                }, 1500);
            }
        },
        
        /**
         * Stop import
         */
        stopImport: function() {
            this.isStopping = true;
            
            $('.apm-batch-progress').text('Stopping after current product...');
            
            $.ajax({
                url: apmImportQueue.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'apm_import_queue_stop_import',
                    nonce: apmImportQueue.nonce
                }
            });
        },
        
        /**
         * Finish import
         */
        finishImport: function(message) {
            this.isImporting = false;
            this.isStopping = false;
            
            // Update button
            $('#apm-batch-import-btn')
                .text(apmImportQueue.strings.batchImport)
                .removeClass('importing');
            
            // Hide progress
            $('.apm-batch-progress').hide();
            
            // Show message
            if (message) {
                this.showNotice(message, 'success');
            }
        },
        
        /**
         * Update stats
         */
        updateStats: function(stats) {
            if (!stats) return;
            
            $('.apm-queue-stats').html(
                '<p>' +
                '<strong>Total:</strong> ' + stats.total + ' | ' +
                '<strong>Pending:</strong> ' + stats.pending + ' | ' +
                '<strong>Imported:</strong> ' + stats.imported + ' | ' +
                '<strong>Errors:</strong> ' + stats.errors +
                '</p>'
            );
        },
        
        /**
         * Reload tables
         */
        reloadTables: function() {
            location.reload();
        },
        
        /**
         * Show notice
         */
        showNotice: function(message, type) {
            var $notice = $('#apm-import-message');
            $notice.removeClass('notice-error notice-success notice-warning');
            $notice.addClass('notice-' + type);
            $notice.html('<p>' + message + '</p>');
            $notice.show();
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                $notice.fadeOut();
            }, 5000);
        },
        
        /**
         * Handle before unload
         */
        handleBeforeUnload: function(e) {
            if (this.isImporting) {
                e.preventDefault();
                e.returnValue = apmImportQueue.strings.confirmLeave;
                return apmImportQueue.strings.confirmLeave;
            }
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        importQueue.init();
    });
    
})(jQuery);
