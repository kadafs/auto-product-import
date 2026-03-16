/**
 * Admin JavaScript for Auto Product Import
 *
 * Handles single product import form and East West Engineering multi-row
 * selection UI.
 *
 * @version 2.2.4
 */

jQuery(document).ready(function($) {

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function isEastWestEngUrl(url) {
        try {
            var host = new URL(url).hostname.toLowerCase();
            return host === 'www.eastwesteng.com.au' || host === 'eastwesteng.com.au';
        } catch (e) {
            return false;
        }
    }

    function showMessage($el, type, html) {
        $el.removeClass('notice-success notice-error notice-warning')
           .addClass('notice-' + type)
           .html('<p>' + html + '</p>')
           .show();
    }

    // -------------------------------------------------------------------------
    // East West Engineering – selection table
    // -------------------------------------------------------------------------

    /**
     * Render the row-selection table into #apm-ewe-selection (created on the fly
     * if it doesn't already exist) and show it.
     *
     * @param {Array}  rows      Array of { sku, title, price }
     * @param {string} cacheKey  Transient key returned from preview AJAX call
     * @param {string} url       Original product URL
     */
    function renderEweSelectionTable(rows, cacheKey, url) {
        // Build selection container if needed
        if ($('#apm-ewe-selection').length === 0) {
            var container =
                '<div id="apm-ewe-selection" style="margin-top:20px;padding:15px;' +
                'background:#fff;border:1px solid #ccc;border-radius:4px;">' +
                '<h3 style="margin-top:0;">Select Products to Import</h3>' +
                '<p>The following products were found. Check the rows you want to import then click <strong>Continue</strong>.</p>' +
                '<table class="widefat striped" id="apm-ewe-table">' +
                '<thead><tr>' +
                '<th style="width:40px;">Import</th>' +
                '<th>Model (SKU)</th>' +
                '<th>Description</th>' +
                '<th>Price (Incl. GST)</th>' +
                '</tr></thead>' +
                '<tbody id="apm-ewe-tbody"></tbody>' +
                '</table>' +
                '<p style="margin-top:15px;">' +
                '<button type="button" id="apm-ewe-continue" class="button button-primary">Continue</button>' +
                '<button type="button" id="apm-ewe-cancel" class="button" style="margin-left:8px;">Cancel</button>' +
                '</p>' +
                '<div id="apm-ewe-progress" style="display:none;margin-top:10px;"></div>' +
                '</div>';
            $('#apm-import-form').after(container);
        }

        // Populate rows
        var tbody = '';
        $.each(rows, function(i, row) {
            var priceDisplay = row.price ? '$' + parseFloat(row.price).toLocaleString('en-AU', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '—';
            tbody +=
                '<tr>' +
                '<td><input type="checkbox" class="apm-ewe-row-cb" checked ' +
                'data-sku="' + $('<span>').text(row.sku).html() + '" ' +
                'data-title="' + $('<span>').text(row.title).html() + '" ' +
                'data-price="' + $('<span>').text(row.price).html() + '"></td>' +
                '<td>' + $('<span>').text(row.sku).html() + '</td>' +
                '<td>' + $('<span>').text(row.title).html() + '</td>' +
                '<td>' + priceDisplay + '</td>' +
                '</tr>';
        });
        $('#apm-ewe-tbody').html(tbody);

        // Store meta on the container
        $('#apm-ewe-selection')
            .data('cache-key', cacheKey)
            .data('url', url)
            .show();

        // Scroll to it
        $('html,body').animate({scrollTop: $('#apm-ewe-selection').offset().top - 40}, 300);
    }

    function hideEweSelection() {
        $('#apm-ewe-selection').hide();
    }

    // Guard flag: prevents double-firing on rapid/repeated clicks
    var eweImportInProgress = false;

    // Continue button: collect checked rows and POST to confirm endpoint
    $(document).on('click', '#apm-ewe-continue', function() {
        // Hard guard against double-submission (event delegation can still fire
        // on a disabled button in some browsers/jQuery versions)
        if (eweImportInProgress) {
            return;
        }

        var $container = $('#apm-ewe-selection');
        var cacheKey   = $container.data('cache-key');
        var url        = $container.data('url');

        var selectedRows = [];
        $('.apm-ewe-row-cb:checked').each(function() {
            selectedRows.push({
                sku:   $(this).data('sku'),
                title: $(this).data('title'),
                price: $(this).data('price'),
            });
        });

        if (selectedRows.length === 0) {
            showMessage($('#apm-import-message'), 'warning', 'Please select at least one row to import.');
            return;
        }

        var $btn      = $(this);
        var $progress = $('#apm-ewe-progress');

        eweImportInProgress = true;
        $btn.prop('disabled', true);
        $progress.html('<span class="spinner is-active" style="float:none;margin-top:0;vertical-align:middle;"></span> Importing ' + selectedRows.length + ' product(s)…').show();

        $.ajax({
            url:  autoProductImportAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action:    'apm_eastwesteng_import_selected',
                nonce:     autoProductImportAdmin.nonce,
                url:       url,
                cache_key: cacheKey,
                rows:      JSON.stringify(selectedRows),
            },
            success: function(response) {
                if (response.success) {
                    renderEweResults(response.data.results);
                    hideEweSelection();
                } else {
                    showMessage($('#apm-import-message'), 'error', response.data.message || 'Import failed.');
                }
            },
            error: function() {
                showMessage($('#apm-import-message'), 'error', 'An error occurred during import. Please try again.');
            },
            complete: function() {
                eweImportInProgress = false;
                $btn.prop('disabled', false);
                $progress.hide();
            }
        });
    });

    // Cancel button: hide the selection table
    $(document).on('click', '#apm-ewe-cancel', function() {
        hideEweSelection();
        var $submitBtn = $('#apm-import-submit');
        $submitBtn.prop('disabled', false);
        $('#apm-import-form').find('.spinner').removeClass('is-active');
    });

    /**
     * Render per-row import results in #apm-import-result.
     */
    function renderEweResults(results) {
        var html = '<table class="widefat striped"><thead><tr>' +
            '<th>SKU</th><th>Description</th><th>Status</th><th>Actions</th>' +
            '</tr></thead><tbody>';

        $.each(results, function(i, r) {
            var actions = '';
            if (r.status === 'imported') {
                actions = '<a href="' + r.edit_link + '" class="button button-small" target="_blank">Edit</a> ' +
                          '<a href="' + r.view_link + '" class="button button-small" target="_blank">View</a>';
            }
            var statusLabel = r.status === 'imported' ? '<span style="color:#46b450;">&#10003; Imported</span>'
                            : r.status === 'skipped'  ? '<span style="color:#f0b849;">&#8212; Skipped</span>'
                            : '<span style="color:#dc3232;">&#10007; Error</span>';

            html += '<tr>' +
                '<td>' + $('<span>').text(r.sku || '').html() + '</td>' +
                '<td>' + $('<span>').text(r.title || r.message || '').html() + '</td>' +
                '<td>' + statusLabel + (r.message && r.status !== 'imported' ? '<br><small>' + $('<span>').text(r.message).html() + '</small>' : '') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';

        $('#apm-import-result-content').html(html);
        $('#apm-import-result').show();

        var imported = results.filter(function(r) { return r.status === 'imported'; }).length;
        var delay    = results.length > 1 ? 6000 : 2000;
        var $msg     = $('#apm-import-message');

        showMessage(
            $msg,
            imported > 0 ? 'success' : 'warning',
            imported + ' of ' + results.length + ' product(s) imported successfully. ' +
            'Page will refresh in ' + (delay / 1000) + ' seconds to update the imported table\u2026'
        );

        // Reload page so the import queue "Imported" table reflects all new products.
        setTimeout(function() { location.reload(); }, delay);
    }

    // -------------------------------------------------------------------------
    // Single Product Import Form Handler
    // -------------------------------------------------------------------------

    $('#apm-import-form').on('submit', function(e) {
        e.preventDefault();

        var $form      = $(this);
        var $submitBtn = $('#apm-import-submit');
        var $spinner   = $form.find('.spinner');
        var $message   = $('#apm-import-message');
        var $result    = $('#apm-import-result');
        var productUrl = $('#apm-product-url').val();

        $submitBtn.prop('disabled', true);
        $spinner.addClass('is-active');
        $message.hide();
        $result.hide();
        hideEweSelection();

        // ── East West Engineering path ────────────────────────────────────────
        if (isEastWestEngUrl(productUrl)) {
            $.ajax({
                url:  autoProductImportAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'apm_eastwesteng_preview',
                    nonce:  autoProductImportAdmin.nonce,
                    url:    productUrl,
                },
                success: function(response) {
                    if (!response.success) {
                        showMessage($message, 'error', response.data.message || 'Failed to fetch product data.');
                        return;
                    }

                    // Single row: already imported server-side
                    if (response.data.single) {
                        showMessage($message, 'success', response.data.message);
                        var resultHtml =
                            '<p><strong>Product ID:</strong> ' + response.data.product_id + '</p>' +
                            '<p><a href="' + response.data.edit_link + '" class="button" target="_blank">Edit Product</a> ' +
                            '<a href="' + response.data.view_link + '" class="button" target="_blank">View Product</a></p>';
                        $('#apm-import-result-content').html(resultHtml);
                        $result.show();
                        $('#apm-product-url').val('');
                        setTimeout(function() { location.reload(); }, 2000);
                        return;
                    }

                    // Multiple rows: show selection table
                    renderEweSelectionTable(response.data.rows, response.data.cache_key, productUrl);
                },
                error: function() {
                    showMessage($message, 'error', 'An error occurred. Please try again.');
                },
                complete: function() {
                    $submitBtn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
            return;
        }

        // ── Standard import path ──────────────────────────────────────────────
        $.ajax({
            url:  autoProductImportAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'import_product_from_url',
                nonce:  autoProductImportAdmin.nonce,
                url:    productUrl,
            },
            success: function(response) {
                if (response.success) {
                    showMessage($message, 'success', response.data.message);

                    var resultHtml =
                        '<p><strong>Product ID:</strong> ' + response.data.product_id + '</p>' +
                        '<p><a href="' + response.data.edit_link + '" class="button" target="_blank">Edit Product</a> ' +
                        '<a href="' + response.data.view_link + '" class="button" target="_blank">View Product</a></p>';

                    $('#apm-import-result-content').html(resultHtml);
                    $result.show();
                    $('#apm-product-url').val('');

                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showMessage($message, 'error', response.data.message);
                }
            },
            error: function() {
                showMessage($message, 'error', 'An error occurred during import. Please try again.');
            },
            complete: function() {
                $submitBtn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });

    // PDF Documents toggle handler removed in v2.2.2
    // PDFs are still uploaded to media library automatically
});
