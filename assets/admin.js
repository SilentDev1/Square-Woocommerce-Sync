/* Square WooCommerce Sync Pro - Admin JS */
(function($) {
    'use strict';

    const $icon        = $('.sws-wrap h1 .dashicons');
    const $syncBtn     = $('#sws-sync-btn');
    const $testBtn     = $('#sws-test-btn');
    const $progress    = $('#sws-progress');
    const $result      = $('#sws-result');
    const $logContainer = $('#sws-log-container');

    // ── Category Filter ─────────────────────────────────────────────
    var $catSelect = $('#sws-category-filter');
    var $catBtn = $('#sws-load-cats-btn');
    var $catStatus = $('#sws-cats-status');

    if (SWS.saved_categories && SWS.saved_categories.length > 0) {
        for (var i = 0; i < SWS.saved_categories.length; i++) {
            $catSelect.append('<option value="' + escHtml(SWS.saved_categories[i]) + '">' + escHtml(SWS.saved_categories[i]) + '</option>');
        }
        $catStatus.text(SWS.saved_categories.length + ' mapped categories');
        $catBtn.text('Refresh from Square');
    }

    $catBtn.on('click', function() {
        $catBtn.prop('disabled', true).text('Loading...');
        $catStatus.text('Fetching categories from Square...');
        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_get_categories', nonce: SWS.nonce },
            timeout: 120000,
            success: function(resp) {
                $catBtn.prop('disabled', false).text('Refresh from Square');
                if (resp.success) {
                    $catSelect.find('option:not(:first)').remove();
                    var cats = resp.data.categories;
                    for (var name in cats) {
                        if (cats.hasOwnProperty(name)) {
                            $catSelect.append('<option value="' + escHtml(name) + '">' + escHtml(name) + ' (' + cats[name] + ' products)</option>');
                        }
                    }
                    $catStatus.text(Object.keys(cats).length + ' categories loaded (' + resp.data.total_products + ' total products)');
                } else {
                    $catStatus.text('Failed: ' + (resp.data ? resp.data.message : 'Unknown error'));
                }
            },
            error: function() {
                $catBtn.prop('disabled', false).text('Refresh from Square');
                $catStatus.text('Failed to load categories. Check your connection.');
            }
        });
    });

    $catSelect.on('change', function() {
        var val = $(this).val();
        if (val) {
            $syncBtn.text('Sync "' + val + '"');
        } else {
            $syncBtn.text('Run Full Sync Now');
        }
    });

    // ── Run Sync ─────────────────────────────────────────────────────
    var syncPollTimer = null;

    function showSyncError(data) {
        var msg = '';
        if (typeof data === 'string') {
            msg = data;
        } else if (data && data.message) {
            msg = '<strong>❌ Sync Failed</strong><br><br>' +
                  '<strong>Error:</strong> ' + escHtml(data.message);
            if (data.details) {
                msg += '<br><br><strong>💡 How to fix:</strong> ' + escHtml(data.details);
            }
        } else {
            msg = '<strong>❌ Sync Failed</strong><br>An unknown error occurred. Check the Sync Log for details.';
        }
        $result.removeClass('success').addClass('error').html(msg).show();
        $syncBtn.prop('disabled', false).text($catSelect.val() ? 'Sync "' + $catSelect.val() + '"' : 'Run Full Sync Now');
        $testBtn.prop('disabled', false);
        $catSelect.prop('disabled', false);
        $catBtn.prop('disabled', false);
        $progress.hide();
        $icon.removeClass('spinning');
        loadLog();
    }

    function showSyncResult(d) {
        const html = `
            <strong>✅ Sync Complete</strong> — ${d.elapsed}s<br>
            <span class="sws-result-stat">📦 Square Products: ${d.total_square}</span>
            <span class="sws-result-stat">🎯 Matched: ${d.matched}</span>
            <span class="sws-result-stat">✏️ Updated: ${d.updated}</span>
            <span class="sws-result-stat">🔑 SKUs Added: ${d.sku_added}</span>
            <span class="sws-result-stat">🆕 Created: ${d.created}</span>
            <span class="sws-result-stat">⏭ Skipped: ${d.skipped}</span>
            <span class="sws-result-stat" style="color:${d.errors>0?'#d63638':'inherit'}">❌ Errors: ${d.errors}</span>
            <span class="sws-result-stat">🤖 AI Checks: ${d.ai_checks}</span>
            ${d.ai_issues > 0 ? `<span class="sws-result-stat" style="color:#f59e0b">⚠ AI Issues: ${d.ai_issues}</span>` : ''}
        `;
        $result.addClass('success').html(html).show();
        $syncBtn.prop('disabled', false).text($catSelect.val() ? 'Sync "' + $catSelect.val() + '"' : 'Run Full Sync Now');
        $testBtn.prop('disabled', false);
        $catSelect.prop('disabled', false);
        $catBtn.prop('disabled', false);
        $progress.hide();
        $icon.removeClass('spinning');
        loadLog();
    }

    function showSyncRunning(started, progress) {
        $syncBtn.prop('disabled', true).text('Syncing...');
        $testBtn.prop('disabled', true);
        $progress.show();
        $icon.addClass('spinning');

        var pct = 0;
        if (progress && progress.total > 0) {
            pct = Math.round((progress.current / progress.total) * 100);
        }

        $('.sws-progress-fill').css('width', pct + '%');
        $('.sws-progress-pct').text(pct + '%');

        var statusText = 'Initializing sync...';
        if (progress && progress.total > 0) {
            statusText = progress.current + ' / ' + progress.total + ' products';
            if (progress.product) {
                statusText += ' — "' + progress.product + '"';
            }
            statusText += ' | Matched: ' + (progress.matched || 0) + ' | Created: ' + (progress.created || 0);
            if (progress.errors > 0) {
                statusText += ' | Errors: ' + progress.errors;
            }
        }
        $('#sws-progress-text').text(statusText);

        $result.removeClass('success error').addClass('success').html(
            '<strong>Syncing products in batches...</strong><br>' +
            (started ? 'Started at ' + started + '. ' : '') +
            'Processing 25 products per batch. Do not close this page.' +
            '<br><br><button type="button" id="sws-cancel-sync" class="button" style="color:#dc2626;border-color:#dc2626">Cancel Sync</button>'
        ).show();
    }

    $(document).on('click', '#sws-cancel-sync', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Cancelling...');
        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_cancel_sync', nonce: SWS.nonce },
            success: function(resp) {
                if (syncPollTimer) clearTimeout(syncPollTimer);
                $syncBtn.prop('disabled', false).text($catSelect.val() ? 'Sync "' + $catSelect.val() + '"' : 'Run Full Sync Now');
                $testBtn.prop('disabled', false);
                $catSelect.prop('disabled', false);
                $catBtn.prop('disabled', false);
                $progress.hide();
                $icon.removeClass('spinning');
                $result.removeClass('success').addClass('error').html(
                    '<strong>Sync cancelled.</strong><br>You can start a new sync whenever you\'re ready.'
                ).show();
            },
            error: function() {
                $btn.prop('disabled', false).text('Cancel Sync');
                alert('Failed to cancel. Try refreshing the page.');
            }
        });
    });

    function pollSyncStatus() {
        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_sync_status', nonce: SWS.nonce },
            success: function(resp) {
                if ( resp.success ) {
                    if ( resp.data.running ) {
                        showSyncRunning(resp.data.started, resp.data.progress);
                        syncPollTimer = setTimeout(pollSyncStatus, 3000);
                    } else if ( resp.data.error ) {
                        showSyncError({ message: resp.data.error, details: resp.data.error_details });
                    } else if ( resp.data.result && resp.data.result.success ) {
                        showSyncResult(resp.data.result);
                    } else if ( resp.data.result && !resp.data.result.success ) {
                        showSyncError({ message: resp.data.result.error || 'Sync failed', details: resp.data.error_details });
                    } else {
                        $syncBtn.prop('disabled', false).text($catSelect.val() ? 'Sync "' + $catSelect.val() + '"' : 'Run Full Sync Now');
                        $testBtn.prop('disabled', false);
                        $catSelect.prop('disabled', false);
                        $catBtn.prop('disabled', false);
                        $progress.hide();
                        $icon.removeClass('spinning');
                    }
                }
            },
            error: function() {
                syncPollTimer = setTimeout(pollSyncStatus, 5000);
            }
        });
    }

    var batchInFlight = false;
    var batchRetries = 0;

    function runBatchProcess() {
        if (batchInFlight) return;
        batchInFlight = true;

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_batch_process', nonce: SWS.nonce },
            timeout: 120000,
            success: function(resp) {
                batchInFlight = false;
                batchRetries = 0;
                if (resp.success) {
                    if (resp.data.done) {
                        showSyncResult(resp.data);
                    } else {
                        var p = {
                            current: resp.data.offset,
                            total:   resp.data.total,
                            product: resp.data.last_product || '',
                            matched: resp.data.stats ? resp.data.stats.matched : 0,
                            created: resp.data.stats ? resp.data.stats.created : 0,
                            errors:  resp.data.stats ? resp.data.stats.errors : 0,
                        };
                        showSyncRunning('', p);
                        setTimeout(runBatchProcess, 200);
                    }
                } else {
                    showSyncError(resp.data);
                }
            },
            error: function(xhr, status) {
                batchInFlight = false;
                if (status === 'timeout' && batchRetries < 3) {
                    batchRetries++;
                    setTimeout(runBatchProcess, 2000);
                    return;
                }
                var errorData = null;
                try { errorData = JSON.parse(xhr.responseText); if (errorData && errorData.data) errorData = errorData.data; } catch(e) {}
                showSyncError(errorData || { message: 'Batch request failed (HTTP ' + xhr.status + ')', details: 'Check the Debug Log for details.' });
            }
        });
    }

    $syncBtn.on('click', function() {
        if ( $syncBtn.prop('disabled') ) return;

        var selectedCat = $catSelect.val() || '';
        $syncBtn.prop('disabled', true).text('Syncing...');
        $testBtn.prop('disabled', true);
        $catSelect.prop('disabled', true);
        $catBtn.prop('disabled', true);
        $progress.show();
        $result.hide().removeClass('success error').html('');
        $icon.addClass('spinning');

        $('.sws-progress-fill').css('width', '0%');
        $('.sws-progress-pct').text('0%');
        var fetchMsg = selectedCat ? 'Fetching "' + selectedCat + '" products from Square...' : 'Fetching Square catalog...';
        $('#sws-progress-text').text(fetchMsg);

        $result.removeClass('success error').addClass('success').html(
            '<strong>' + fetchMsg + '</strong><br>This may take a moment for large catalogs.'
        ).show();

        var skipOos = $('#sws-skip-oos').is(':checked') ? '1' : '0';

        $.ajax({
            url:      SWS.ajaxurl,
            method:   'POST',
            data:     { action: 'sws_batch_start', nonce: SWS.nonce, category: selectedCat, skip_out_of_stock: skipOos },
            timeout:  120000,
            success:  function(resp) {
                if ( resp.success ) {
                    var p = { current: 0, total: resp.data.total, product: '', matched: 0, created: 0, errors: 0 };
                    showSyncRunning('', p);
                    setTimeout(runBatchProcess, 500);
                } else {
                    showSyncError(resp.data);
                }
            },
            error: function(xhr, status, errorThrown) {
                var errorData = null;
                try { errorData = JSON.parse(xhr.responseText); if (errorData && errorData.data) errorData = errorData.data; } catch(e) {}
                showSyncError(errorData || { message: 'Failed to start sync: ' + (errorThrown || 'Unknown error'), details: 'Check your connection and try again.' });
            }
        });
    });

    if ( $syncBtn.length ) {
        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_sync_status', nonce: SWS.nonce },
            success: function(resp) {
                if ( resp.success && resp.data.running ) {
                    showSyncRunning(resp.data.started, resp.data.progress);
                    syncPollTimer = setTimeout(pollSyncStatus, 3000);
                } else if ( resp.success && resp.data.error ) {
                    showSyncError({ message: resp.data.error, details: resp.data.error_details });
                }
            }
        });
    }

    // ── Debug Log ──────────────────────────────────────────────────
    $('#sws-debug-log-btn').on('click', function(e) {
        e.preventDefault();
        var $panel = $('#sws-debug-log-panel');
        if ($panel.is(':visible')) {
            $panel.slideUp(200);
            return;
        }
        var $content = $('#sws-debug-log-content');
        $content.text('Loading...');
        $panel.slideDown(200);

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_get_debug_log', nonce: SWS.nonce },
            success: function(resp) {
                if (resp.success) {
                    $content.text(resp.data.log || 'No log entries yet.');
                    $('#sws-debug-log-path').text(resp.data.path || '');
                    $content.scrollTop($content[0].scrollHeight);
                } else {
                    $content.text('Failed to load debug log.');
                }
            },
            error: function() {
                $content.text('Failed to load debug log. Check your connection.');
            }
        });
    });

    // ── Test Connections ────────────────────────────────────────────
    $testBtn.on('click', function() {
        $testBtn.prop('disabled', true).text('Testing...');

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_test_connections', nonce: SWS.nonce },
            success: function(resp) {
                if ( resp.success ) {
                    const d = resp.data;
                    let html = '<div id="sws-connection-results">';
                    html += connItem('Square API', d.square);
                    html += connItem('AI Provider', d.ai);
                    html += '</div>';
                    $result.removeClass('success error').addClass('success').html(html).show();
                }
            },
            complete: function() {
                $testBtn.prop('disabled', false).text('🔌 Test Connections');
            }
        });
    });

    function connItem(label, result) {
        const ok = result.success;
        return `
            <div class="sws-conn-item">
                <span class="sws-conn-dot ${ok ? 'ok' : 'err'}"></span>
                <strong>${label}:</strong> ${result.message}
            </div>
        `;
    }

    // ── Log ─────────────────────────────────────────────────────────
    function loadLog() {
        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_get_log', nonce: SWS.nonce },
            success: function(resp) {
                if ( resp.success && resp.data && resp.data.length ) {
                    renderLog( resp.data );
                } else {
                    $logContainer.html('<p class="sws-log-empty">No log entries yet. Run a sync to see output.</p>');
                }
            }
        });
    }

    function parseServerTime(dateStr) {
        if (!dateStr) return null;
        var offset = (SWS && SWS.wp_utc_offset) ? SWS.wp_utc_offset : '';
        var d = new Date(dateStr.replace(' ', 'T') + offset);
        return isNaN(d) ? null : d;
    }

    function renderLog(entries) {
        const lines = entries.slice().reverse().map(function(e) {
            var time = '';
            if (e.sync_time) {
                var td = parseServerTime(e.sync_time);
                time = td ? to12hr(td) : '';
            }
            const msg  = colorizeMessage( escHtml(e.message) );
            const lvl  = e.level || 'info';
            return `
                <div class="sws-log-line">
                    <span class="sws-log-time">${time}</span>
                    <span class="sws-log-level sws-log-level-${lvl}">${lvl.toUpperCase()}</span>
                    <span class="sws-log-msg">${msg}</span>
                </div>
            `;
        }).join('');

        $logContainer.html(lines);
        $logContainer.scrollTop($logContainer[0].scrollHeight);
    }

    function colorizeMessage(msg) {
        if ( msg.includes('===') )        return `<span class="sws-section">${msg}</span>`;
        if ( msg.includes('✓') )          return `<span class="sws-match">${msg}</span>`;
        if ( msg.includes('Created') || msg.includes('Creating') ) return `<span class="sws-create">${msg}</span>`;
        if ( msg.includes('Updated') || msg.includes('→') )        return `<span class="sws-update">${msg}</span>`;
        if ( msg.includes('✗') || msg.includes('No match') )       return `<span class="sws-warn">${msg}</span>`;
        if ( msg.includes('⚠') || msg.includes('issue') )          return `<span class="sws-warn">${msg}</span>`;
        return msg;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    $('#sws-refresh-log-btn').on('click', loadLog);

    $('#sws-clear-log-btn').on('click', function() {
        if ( ! confirm('Clear all sync logs?') ) return;
        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_clear_log', nonce: SWS.nonce },
            success: function() {
                $logContainer.html('<p class="sws-log-empty">Log cleared.</p>');
            }
        });
    });

    if ( $logContainer.length ) loadLog();

    // ── AI Provider Toggle (Settings page) ─────────────────────────
    var $providerSelect = $('#sws_ai_provider');
    if ( $providerSelect.length ) {
        function toggleAiSteps() {
            var provider = $providerSelect.val();
            $('.sws-ai-setup-steps').hide();
            $('.sws-model-help').hide();
            $('#sws-ai-steps-' + provider).show();
            $('#sws-model-help-' + provider).show();
        }
        toggleAiSteps();
        $providerSelect.on('change', toggleAiSteps);
    }

    // ══════════════════════════════════════════════════════════════════
    // PRODUCT INVENTORY PAGE
    // ══════════════════════════════════════════════════════════════════

    var productsPage = 1;
    var productsFilter = 'all';
    var productsSearch = '';
    var productsCatFilter = '';
    var searchTimer = null;

    function loadProducts() {
        var $tbody = $('#sws-products-tbody');
        if ( ! $tbody.length ) return;

        $tbody.html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#6b7280">Loading...</td></tr>');

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   {
                action:   'sws_get_products',
                nonce:    SWS.nonce,
                status:   productsFilter,
                search:   productsSearch,
                category: productsCatFilter,
                page:     productsPage,
            },
            success: function(resp) {
                if ( ! resp.success ) return;
                var d = resp.data;

                var allCount = 0;
                $.each(d.counts, function(k, v) { allCount += v; });
                $('#sws-cnt-all').text(allCount);
                $('#sws-cnt-synced').text(d.counts.synced || 0);
                $('#sws-cnt-updated').text(d.counts.updated || 0);
                $('#sws-cnt-created').text(d.counts.created || 0);
                $('#sws-cnt-unmatched').text(d.counts.unmatched || 0);
                $('#sws-cnt-error').text(d.counts.error || 0);

                var $catFilter = $('#sws-product-cat-filter');
                var currentCat = $catFilter.val() || '';
                $catFilter.find('option:not(:first)').remove();
                if (d.categories) {
                    $.each(d.categories, function(catName, cnt) {
                        $catFilter.append('<option value="' + escHtml(catName) + '"' + (catName === currentCat ? ' selected' : '') + '>' + escHtml(catName) + ' (' + cnt + ')</option>');
                    });
                }

                if ( ! d.products.length ) {
                    $tbody.html('<tr><td colspan="9" style="text-align:center;padding:40px;color:#6b7280">No products found. Run a sync to populate.</td></tr>');
                    $('#sws-showing').text('');
                    $('#sws-page-btns').html('');
                    return;
                }

                var rows = '';
                var lastCat = null;
                $.each(d.products, function(i, p) {
                    var cat = p.square_categories || 'Uncategorized';
                    if (cat !== lastCat) {
                        rows += '<tr class="sws-cat-header-row"><td colspan="9" style="background:#f1f5f9;padding:8px 12px;font-weight:700;font-size:13px;color:#334155;border-top:2px solid #e2e8f0">';
                        rows += '<span class="dashicons dashicons-category" style="font-size:14px;width:14px;height:14px;margin-right:4px;vertical-align:middle;color:#6366f1"></span> ';
                        rows += escHtml(cat);
                        rows += '</td></tr>';
                        lastCat = cat;
                    }
                    var statusBadge = getStatusBadge(p.sync_status);
                    var matchBadge  = getMatchBadge(p.match_method, p.match_confidence);
                    var aiScore     = getAiScoreBadge(p.ai_integrity_score, p.ai_verified);
                    var changes     = getChangesHtml(p.changes_json);
                    var lastSync    = p.last_synced_at ? formatDate(p.last_synced_at) : '<span style="color:#9ca3af">Never</span>';
                    var idx         = (d.page - 1) * d.per_page + i + 1;
                    var wooLinkHtml = p.woo_product_id
                        ? '<a href="post.php?post=' + p.woo_product_id + '&action=edit" target="_blank" style="font-weight:600">#' + p.woo_product_id + ' ' + escHtml(p.woo_product_name || '') + '</a>'
                        : '<span style="color:#9ca3af">—</span>';
                    var wooLink = '<span class="sws-woo-display">' + wooLinkHtml + '</span>'
                        + ' <button class="sws-relink-btn button button-small" data-id="' + p.id + '" data-woo-id="' + (p.woo_product_id || 0) + '" title="Change the linked WooCommerce product" style="padding:1px 6px;font-size:11px;min-height:0;height:22px;line-height:20px">✏️ Relink</button>';

                    rows += '<tr data-id="' + p.id + '" data-square-id="' + escHtml(p.square_id || '') + '">';
                    rows += '<td style="color:#9ca3af;font-size:12px">' + idx + '</td>';
                    rows += '<td><strong>' + escHtml(p.square_name) + '</strong></td>';
                    rows += '<td>' + wooLink + '</td>';
                    rows += '<td>' + statusBadge + '</td>';
                    rows += '<td>' + matchBadge + '</td>';
                    rows += '<td>' + aiScore + '</td>';
                    rows += '<td>' + changes + '</td>';
                    rows += '<td style="font-size:12px;color:#6b7280">' + lastSync + '</td>';
                    rows += '<td style="white-space:nowrap">';
                    if (p.woo_product_id) {
                        rows += '<button class="button button-small sws-verify-btn" data-product="' + p.id + '" title="AI Verify">🤖</button> ';
                        rows += '<button class="button button-small sws-check-inv-btn" data-product="' + p.id + '" title="Check Inventory">📦</button> ';
                    }
                    if (p.square_id) {
                        rows += '<button class="button button-small sws-single-sync-row-btn" data-square-id="' + escHtml(p.square_id) + '" title="Re-sync this product from Square now">🔄</button>';
                    }
                    rows += '</td>';
                    rows += '</tr>';
                });

                $tbody.html(rows);

                var startItem = (d.page - 1) * d.per_page + 1;
                var endItem = Math.min(d.page * d.per_page, d.total);
                $('#sws-showing').text('Showing ' + startItem + '–' + endItem + ' of ' + d.total + ' products');

                var pageHtml = '';
                for (var pg = 1; pg <= d.pages; pg++) {
                    pageHtml += '<button class="button button-small sws-page-btn' + (pg === d.page ? ' button-primary' : '') + '" data-page="' + pg + '">' + pg + '</button>';
                }
                $('#sws-page-btns').html(pageHtml);
            }
        });
    }

    function getStatusBadge(status) {
        var colors = {
            synced:    'background:#dcfce7;color:#166534',
            updated:   'background:#f3e8ff;color:#6b21a8',
            created:   'background:#dbeafe;color:#1e40af',
            unmatched: 'background:#fef9c3;color:#854d0e',
            error:     'background:#fee2e2;color:#991b1b',
            pending:   'background:#f3f4f6;color:#6b7280',
        };
        var labels = {
            synced: '✓ Synced', updated: '✏️ Updated', created: '🆕 Created',
            unmatched: '❌ No Match', error: '⚠ Error', pending: '⏳ Pending',
        };
        var style = colors[status] || colors.pending;
        var label = labels[status] || status;
        return '<span class="sws-status-pill" style="' + style + '">' + label + '</span>';
    }

    function getMatchBadge(method, confidence) {
        if (!method || method === 'none') return '<span style="color:#9ca3af;font-size:12px">—</span>';
        var conf = parseFloat(confidence) || 0;
        var pct  = Math.round(conf * 100);
        var color = conf >= 0.85 ? '#166534' : conf >= 0.7 ? '#854d0e' : '#991b1b';
        var methods = { sku: '🔑 SKU', ai_verified: '🤖 AI', new: '🆕 New', unknown: '❓' };
        return '<span style="font-size:12px">' + (methods[method] || method) + '</span><br>' +
               '<span style="font-size:11px;color:' + color + ';font-weight:600">' + pct + '%</span>';
    }

    function getAiScoreBadge(score, verified) {
        if (!verified || verified === '0') return '<span style="color:#9ca3af;font-size:12px">—</span>';
        var s = parseFloat(score) || 0;
        var pct = Math.round(s * 100);
        var color = s >= 0.85 ? '#166534' : s >= 0.6 ? '#854d0e' : '#991b1b';
        var bg    = s >= 0.85 ? '#dcfce7' : s >= 0.6 ? '#fef9c3' : '#fee2e2';
        return '<span class="sws-ai-pill" style="background:' + bg + ';color:' + color + '">' + pct + '%</span>';
    }

    function getChangesHtml(json) {
        if (!json) return '<span style="color:#9ca3af;font-size:12px">—</span>';
        try {
            var changes = JSON.parse(json);
            if (!changes || !changes.length) {
                if (changes && changes.action === 'created') return '<span style="color:#2563eb;font-size:12px">New product</span>';
                return '<span style="color:#22c55e;font-size:12px">No changes</span>';
            }
            if (Array.isArray(changes)) {
                var summary = [];
                changes.forEach(function(c) {
                    var label = c.variation ? '<strong>' + escHtml(c.variation) + '</strong> ' : '';
                    if (c.field === 'stock') summary.push(label + '📦 ' + c.from + '→' + c.to);
                    else if (c.field === 'price') summary.push(label + '💰 $' + c.from + '→$' + c.to);
                    else if (c.field === 'sku') summary.push(label + '🔑 +' + c.to);
                    else if (c.field === 'new_variation') summary.push('<strong>' + escHtml(c.variation) + '</strong> 🆕 new variation');
                });
                return '<span style="font-size:11px;line-height:1.6">' + summary.join('<br>') + '</span>';
            }
            return '<span style="color:#9ca3af;font-size:12px">—</span>';
        } catch(e) {
            return '<span style="color:#9ca3af;font-size:12px">—</span>';
        }
    }

    function to12hr(d) {
        var h = d.getHours(), m = d.getMinutes();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = parseServerTime(dateStr);
        if (!d) return '';
        var now = new Date();
        var diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff/60) + 'm ago';
        if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
        if (diff < 172800) return 'Yesterday ' + to12hr(d);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + to12hr(d);
    }

    $(document).on('click', '.sws-filter-btn', function() {
        $('.sws-filter-btn').removeClass('active');
        $(this).addClass('active');
        productsFilter = $(this).data('status');
        productsPage = 1;
        loadProducts();
    });

    $('#sws-product-cat-filter').on('change', function() {
        productsCatFilter = $(this).val() || '';
        productsPage = 1;
        loadProducts();
    });

    $(document).on('click', '.sws-page-btn', function() {
        productsPage = parseInt($(this).data('page'));
        loadProducts();
    });

    $('#sws-product-search').on('input', function() {
        clearTimeout(searchTimer);
        var val = $(this).val();
        searchTimer = setTimeout(function() {
            productsSearch = val;
            productsPage = 1;
            loadProducts();
        }, 400);
    });

    $('#sws-refresh-products').on('click', function() {
        loadProducts();
    });

    $(document).on('click', '.sws-verify-btn', function() {
        var $btn = $(this);
        var productId = $btn.data('product');
        $btn.prop('disabled', true).text('⏳');

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_ai_verify_product', nonce: SWS.nonce, product_id: productId },
            success: function(resp) {
                if (resp.success) {
                    var d = resp.data;
                    var pct = Math.round(d.score * 100);
                    var msg = '🤖 AI Integrity: ' + pct + '%\n\n' + d.summary;
                    if (d.issues && d.issues.length) {
                        msg += '\n\nIssues:\n• ' + d.issues.join('\n• ');
                    }
                    alert(msg);
                    loadProducts();
                } else {
                    alert('AI verification failed: ' + (resp.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, errorThrown) {
                var msg = 'AI verification request failed.';
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.data) msg = typeof parsed.data === 'string' ? parsed.data : (parsed.data.message || msg);
                } catch(e) {}
                if (xhr.status === 500) msg += '\n\nCheck wp-content/debug.log for details.';
                if (xhr.status === 0) msg += '\nConnection lost — check your internet connection.';
                alert(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).text('🤖');
            }
        });
    });

    $(document).on('click', '.sws-check-inv-btn', function() {
        var $btn = $(this);
        var productId = $btn.data('product');
        $btn.prop('disabled', true).text('⏳');

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_check_inventory', nonce: SWS.nonce, product_id: productId },
            success: function(resp) {
                if (resp.success) {
                    var d = resp.data;
                    var msg = '📦 Inventory Check: ' + d.square_product + '\n';
                    msg += 'Square ID: ' + d.square_id + '\n';
                    msg += 'Location ID: ' + d.location_id + '\n\n';

                    msg += '── Square Variations ──\n';
                    if (d.square_variations && d.square_variations.length) {
                        d.square_variations.forEach(function(v) {
                            msg += '  ' + v.name + ' (SKU: ' + (v.sku || '—') + ')\n';
                            msg += '    Cached qty: ' + v.cached_qty + ', Live qty: ' + v.live_square_qty + '\n';
                        });
                    } else {
                        msg += '  (none found in cache — run a sync first)\n';
                    }

                    msg += '\n── WooCommerce Variations ──\n';
                    if (d.wc_variations && d.wc_variations.length) {
                        d.wc_variations.forEach(function(v) {
                            msg += '  ' + v.name + ' (SKU: ' + (v.sku || '—') + ')\n';
                            msg += '    Stock: ' + v.stock_qty + ', Manage stock: ' + (v.manage_stock ? 'yes' : 'no') + '\n';
                            if (v.sq_var_id_meta) msg += '    Square Var ID: ' + v.sq_var_id_meta + '\n';
                        });
                    } else {
                        msg += '  (none)\n';
                    }

                    alert(msg);
                } else {
                    alert('Inventory check failed: ' + (resp.data || 'Unknown error'));
                }
            },
            error: function() {
                alert('Inventory check request failed.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('📦');
            }
        });
    });

    // ── Relink Product ──────────────────────────────────────────────
    var relinkSearchTimer = null;

    $(document).on('click', '.sws-relink-btn', function() {
        var $btn        = $(this);
        var trackingId  = $btn.data('id');
        var $row        = $btn.closest('tr');

        // Remove any existing relink panel
        $row.next('.sws-relink-row').remove();

        var panelHtml =
            '<tr class="sws-relink-row" data-for="' + trackingId + '">' +
            '<td colspan="9" style="padding:14px 16px;background:#f0f7ff;border-top:1px dashed #93c5fd;border-bottom:1px dashed #93c5fd">' +
            '<div style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:12px">' +
            '<div style="flex:1;min-width:260px">' +
            '<label style="display:block;font-size:12px;font-weight:600;color:#1e40af;margin-bottom:6px">🔗 Link to WooCommerce Product</label>' +
            '<div style="position:relative">' +
            '<input type="text" class="sws-relink-search regular-text" placeholder="Search by product name or paste a product ID…" style="width:100%;font-size:13px" autocomplete="off">' +
            '<div class="sws-relink-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #c3c4c7;border-top:none;border-radius:0 0 4px 4px;max-height:220px;overflow-y:auto;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.1)"></div>' +
            '</div>' +
            '<div class="sws-relink-selected" style="display:none;margin-top:8px;padding:6px 10px;background:#dbeafe;border-radius:4px;font-size:13px;color:#1e40af">' +
            '<strong>Selected:</strong> <span class="sws-relink-selected-label"></span>' +
            '</div>' +
            '</div>' +
            '<div style="display:flex;flex-direction:column;gap:6px;padding-top:22px">' +
            '<button class="sws-relink-save button button-primary button-small" data-tracking="' + trackingId + '" data-selected-id="0" disabled style="white-space:nowrap">✓ Save Link</button>' +
            '<button class="sws-relink-clear button button-small" data-tracking="' + trackingId + '" style="white-space:nowrap;color:#dc2626;border-color:#fca5a5">✗ Clear Link</button>' +
            '<button class="sws-relink-cancel button button-small" style="white-space:nowrap">Cancel</button>' +
            '</div>' +
            '</div>' +
            '</td>' +
            '</tr>';

        $row.after(panelHtml);
        var $panel = $row.next('.sws-relink-row');
        $panel.find('.sws-relink-search').focus();
    });

    // Close panel on Cancel
    $(document).on('click', '.sws-relink-cancel', function() {
        $(this).closest('.sws-relink-row').remove();
    });

    // Live search
    $(document).on('input', '.sws-relink-search', function() {
        clearTimeout(relinkSearchTimer);
        var $input    = $(this);
        var $dropdown = $input.closest('div').find('.sws-relink-dropdown');
        var term      = $input.val().trim();

        if (term.length < 2) {
            $dropdown.hide().empty();
            return;
        }

        relinkSearchTimer = setTimeout(function() {
            $dropdown.html('<div style="padding:8px 12px;color:#6b7280;font-size:12px">Searching…</div>').show();
            $.ajax({
                url:    SWS.ajaxurl,
                method: 'POST',
                data:   { action: 'sws_search_woo_products', nonce: SWS.nonce, term: term },
                success: function(resp) {
                    if (!resp.success || !resp.data.length) {
                        $dropdown.html('<div style="padding:8px 12px;color:#9ca3af;font-size:12px">No products found.</div>');
                        return;
                    }
                    var html = '';
                    $.each(resp.data, function(i, p) {
                        var meta = p.sku ? ' — SKU: ' + escHtml(p.sku) : '';
                        meta += ' (' + escHtml(p.type) + ')';
                        html += '<div class="sws-relink-result" data-id="' + p.id + '" data-name="' + escHtml(p.name) + '" ' +
                                'style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:13px" ' +
                                'onmouseover="this.style.background=\'#eff6ff\'" onmouseout="this.style.background=\'\'">' +
                                '<strong>#' + p.id + '</strong> ' + escHtml(p.name) +
                                '<span style="color:#9ca3af;font-size:11px;margin-left:6px">' + meta + '</span>' +
                                '</div>';
                    });
                    $dropdown.html(html).show();
                },
                error: function() {
                    $dropdown.html('<div style="padding:8px 12px;color:#dc2626;font-size:12px">Search failed.</div>');
                }
            });
        }, 300);
    });

    // Select a search result
    $(document).on('click', '.sws-relink-result', function() {
        var $result   = $(this);
        var id        = $result.data('id');
        var name      = $result.data('name');
        var $panel    = $result.closest('.sws-relink-row');
        var $search   = $panel.find('.sws-relink-search');
        var $dropdown = $panel.find('.sws-relink-dropdown');
        var $selected = $panel.find('.sws-relink-selected');
        var $saveBtn  = $panel.find('.sws-relink-save');

        $search.val(name);
        $dropdown.hide();
        $selected.find('.sws-relink-selected-label').text('#' + id + ' ' + name);
        $selected.show();
        $saveBtn.data('selected-id', id).prop('disabled', false);
    });

    // Hide dropdown when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.sws-relink-row').length) {
            $('.sws-relink-dropdown').hide();
        }
    });

    // Save new link
    $(document).on('click', '.sws-relink-save', function() {
        var $btn        = $(this);
        var trackingId  = $btn.data('tracking');
        var selectedId  = $btn.data('selected-id');
        if (!selectedId) return;

        $btn.prop('disabled', true).text('Saving…');

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_relink_product', nonce: SWS.nonce, tracking_id: trackingId, woo_product_id: selectedId },
            success: function(resp) {
                if (resp.success) {
                    $btn.closest('.sws-relink-row').remove();
                    loadProducts();
                } else {
                    alert('❌ ' + (resp.data || 'Failed to save link.'));
                    $btn.prop('disabled', false).text('✓ Save Link');
                }
            },
            error: function() {
                alert('❌ Request failed.');
                $btn.prop('disabled', false).text('✓ Save Link');
            }
        });
    });

    // Clear link
    $(document).on('click', '.sws-relink-clear', function() {
        var $btn       = $(this);
        var trackingId = $btn.data('tracking');
        if (!confirm('Remove the link between this Square product and its WooCommerce product?\n\nThe WooCommerce product will NOT be deleted — only the link is removed.')) return;

        $btn.prop('disabled', true).text('Clearing…');

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_relink_product', nonce: SWS.nonce, tracking_id: trackingId, woo_product_id: 0 },
            success: function(resp) {
                if (resp.success) {
                    $btn.closest('.sws-relink-row').remove();
                    loadProducts();
                } else {
                    alert('❌ ' + (resp.data || 'Failed to clear link.'));
                    $btn.prop('disabled', false).text('✗ Clear Link');
                }
            },
            error: function() {
                alert('❌ Request failed.');
                $btn.prop('disabled', false).text('✗ Clear Link');
            }
        });
    });

    // ── Single Product Sync (per-row button) ────────────────────────
    $(document).on('click', '.sws-single-sync-row-btn', function() {
        var $btn = $(this);
        var squareId = $btn.data('square-id');
        if (!squareId) return;
        $btn.prop('disabled', true).text('⏳');

        $.ajax({
            url:     SWS.ajaxurl,
            method:  'POST',
            timeout: 120000,
            data:    { action: 'sws_single_product_sync', nonce: SWS.nonce, square_id: squareId },
            success: function(resp) {
                if (resp.success) {
                    var d = resp.data;
                    var msg = '✅ Sync complete for "' + (d.product || squareId) + '"\n\n';
                    msg += 'Matched: ' + (d.matched || 0) + '  |  Updated: ' + (d.updated || 0) + '  |  Created: ' + (d.created || 0);
                    if (d.errors > 0) msg += '  |  Errors: ' + d.errors;
                    alert(msg);
                    loadProducts();
                } else {
                    var errData = resp.data || {};
                    var errMsg = '❌ Sync failed';
                    if (errData.message) errMsg += ': ' + errData.message;
                    if (errData.details) errMsg += '\n\n💡 ' + errData.details;
                    alert(errMsg);
                }
            },
            error: function(xhr) {
                var msg = '❌ Sync request failed.';
                try {
                    var p = JSON.parse(xhr.responseText);
                    if (p && p.data && p.data.message) msg += '\n' + p.data.message;
                } catch(e) {}
                alert(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).text('🔄');
            }
        });
    });

    // ── Single Product Sync (shared: dashboard card + products toolbar) ──
    function showSingleSyncResult(success, html) {
        var $r = $('#sws-single-sync-result');
        if (!$r.length) return;
        $r.removeClass('success error')
          .addClass(success ? 'success' : 'error')
          .css({
              background: success ? '#f0fdf4' : '#fef2f2',
              border:     '1px solid ' + (success ? '#86efac' : '#fca5a5'),
              color:      success ? '#166534' : '#991b1b',
          })
          .html(html)
          .show();
    }

    function runSingleSync(squareId) {
        squareId = squareId.trim();
        if (!squareId) {
            showSingleSyncResult(false, '⚠️ Please enter a Square Catalog Item ID.');
            $('#sws-single-sync-result').show();
            return;
        }
        var $btn    = $('#sws-single-sync-btn');
        var $result = $('#sws-single-sync-result');
        var origHtml = $btn.html();

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Syncing…');
        $result.hide();

        $.ajax({
            url:     SWS.ajaxurl,
            method:  'POST',
            timeout: 120000,
            data:    { action: 'sws_single_product_sync', nonce: SWS.nonce, square_id: squareId },
            success: function(resp) {
                if (resp.success) {
                    var d = resp.data;
                    var action = d.created > 0 ? '🆕 Created' : (d.updated > 0 ? '✏️ Updated' : '✅ Synced');
                    var html = '<strong>' + action + ':</strong> ' + escHtml(d.product || squareId) + '<br>';
                    html += '<span style="font-size:12px">Matched: ' + (d.matched||0) + ' &nbsp;|&nbsp; Updated: ' + (d.updated||0) + ' &nbsp;|&nbsp; Created: ' + (d.created||0);
                    if (d.errors > 0) html += ' &nbsp;|&nbsp; <span style="color:#dc2626">Errors: ' + d.errors + '</span>';
                    html += '</span>';
                    showSingleSyncResult(true, html);
                    $('#sws-single-sync-input').val('');
                    if (typeof loadProducts === 'function') loadProducts();
                } else {
                    var errData = resp.data || {};
                    var errHtml = '<strong>❌ Sync failed</strong>';
                    if (errData.message) errHtml += ': ' + escHtml(errData.message);
                    if (errData.details) errHtml += '<br><span style="font-size:12px">💡 ' + escHtml(errData.details) + '</span>';
                    showSingleSyncResult(false, errHtml);
                }
            },
            error: function(xhr) {
                var errHtml = '<strong>❌ Request failed.</strong>';
                try {
                    var p = JSON.parse(xhr.responseText);
                    if (p && p.data && p.data.message) errHtml += ' ' + escHtml(p.data.message);
                } catch(e) {}
                showSingleSyncResult(false, errHtml);
            },
            complete: function() {
                $btn.prop('disabled', false).html(origHtml);
            }
        });
    }

    $(document).on('click', '#sws-single-sync-btn', function() {
        runSingleSync($('#sws-single-sync-input').val());
    });

    $(document).on('keydown', '#sws-single-sync-input', function(e) {
        if (e.key === 'Enter') runSingleSync($(this).val());
    });

    $('#sws-ai-verify-all-btn').on('click', function() {
        if (!confirm('Run AI integrity check on all synced products? This will use AI API calls for each product.')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('⏳ Verifying...');

        var rows = [];
        $('#sws-products-tbody tr[data-id]').each(function() {
            var id = $(this).data('id');
            if (id) rows.push(id);
        });

        var idx = 0;
        function verifyNext() {
            if (idx >= rows.length) {
                $btn.prop('disabled', false).text('🤖 AI Verify All');
                loadProducts();
                return;
            }
            $.ajax({
                url: SWS.ajaxurl,
                method: 'POST',
                data: { action: 'sws_ai_verify_product', nonce: SWS.nonce, product_id: rows[idx] },
                complete: function() {
                    idx++;
                    $btn.text('⏳ ' + idx + '/' + rows.length);
                    verifyNext();
                }
            });
        }
        verifyNext();
    });

    // ── Sync History ────────────────────────────────────────────────
    function loadSyncHistory() {
        var $container = $('#sws-sync-history');
        if (!$container.length) return;

        $.ajax({
            url:    SWS.ajaxurl,
            method: 'POST',
            data:   { action: 'sws_get_sync_history', nonce: SWS.nonce },
            success: function(resp) {
                if (!resp.success || !resp.data || !resp.data.length) {
                    $container.html('<p style="color:#6b7280;text-align:center;padding:20px">No sync history yet. Run your first sync from the Dashboard.</p>');
                    return;
                }

                var html = '<table class="sws-history-table"><thead><tr>';
                html += '<th>Date</th><th>Square</th><th>Matched</th><th>Updated</th><th>Created</th><th>Errors</th><th>AI Checks</th><th>AI Issues</th><th>Time</th><th>Mode</th>';
                html += '</tr></thead><tbody>';

                resp.data.forEach(function(h) {
                    var dt = h.sync_date ? formatDate(h.sync_date) : '';
                    html += '<tr>';
                    html += '<td>' + dt + '</td>';
                    html += '<td>' + h.total_square + '</td>';
                    html += '<td style="color:#166534;font-weight:600">' + h.matched + '</td>';
                    html += '<td style="color:#6b21a8;font-weight:600">' + h.updated + '</td>';
                    html += '<td style="color:#1e40af;font-weight:600">' + h.created + '</td>';
                    html += '<td style="color:' + (parseInt(h.errors) > 0 ? '#dc2626' : '#6b7280') + ';font-weight:600">' + h.errors + '</td>';
                    html += '<td>' + h.ai_checks + '</td>';
                    html += '<td style="color:' + (parseInt(h.ai_issues) > 0 ? '#f59e0b' : '#6b7280') + '">' + h.ai_issues + '</td>';
                    html += '<td>' + h.elapsed_seconds + 's</td>';
                    html += '<td>' + (parseInt(h.is_dry_run) ? '<span style="color:#f59e0b">🧪 Dry</span>' : '✓ Live') + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                $container.html(html);
            }
        });
    }

    // Auto-load on product inventory page
    if ($('#sws-products-table').length) {
        loadProducts();
        loadSyncHistory();
    }

    // ── Category Mapping Toggle (Dashboard) ──────────────────────
    $('#sws-toggle-catmap').on('click', function() {
        var $panel = $('#sws-catmap-panel');
        var $arrow = $(this).find('.dashicons');
        if ($panel.is(':visible')) {
            $panel.slideUp(200);
            $arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
        } else {
            $panel.slideDown(200);
            $arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
        }
    });

    // ── Category Mapping ──────────────────────────────────────────
    if ($('#sws-catmap-table').length) {
        var wooCats = [];
        var savedMappings = {};
        try { wooCats = JSON.parse($('#sws-woo-cats').text() || '[]'); } catch(e) {}
        try { savedMappings = JSON.parse($('#sws-saved-mappings').text() || '{}'); } catch(e) {}

        function buildWooSelect(squareCat) {
            var mapped = savedMappings[squareCat] || '';
            var html = '<select class="sws-catmap-select" data-sq-cat="' + escHtml(squareCat) + '" data-testid="select-woocat-' + escHtml(squareCat) + '">';
            html += '<option value="">— Not Mapped —</option>';
            wooCats.forEach(function(wc) {
                var indent = '';
                for (var d = 0; d < wc.depth; d++) indent += '— ';
                var sel = (parseInt(mapped) === wc.id) ? ' selected' : '';
                html += '<option value="' + wc.id + '"' + sel + '>' + indent + escHtml(wc.name) + ' (' + wc.count + ')</option>';
            });
            html += '</select>';
            return html;
        }

        function escHtml(s) {
            return $('<span>').text(s).html();
        }

        function getMatchStatus(squareCat, wooTermId) {
            if (wooTermId) {
                var wc = wooCats.find(function(c) { return c.id === parseInt(wooTermId); });
                if (wc) return '<span style="color:#16a34a;font-weight:600">Mapped</span>';
            }
            var exactMatch = wooCats.find(function(c) {
                return c.name.toLowerCase() === squareCat.toLowerCase() ||
                       c.slug === squareCat.toLowerCase().replace(/\s+/g, '-');
            });
            if (exactMatch) return '<span style="color:#2563eb">Auto-match available</span>';
            return '<span style="color:#9ca3af">Unmapped</span>';
        }

        function renderCatmapRows(categories) {
            var $body = $('#sws-catmap-body');
            $body.empty();
            var sortedCats = Object.keys(categories).sort();
            sortedCats.forEach(function(cat) {
                var count = categories[cat];
                var mappedId = savedMappings[cat] || '';
                var html = '<tr data-testid="catmap-row-' + escHtml(cat) + '">';
                html += '<td><strong>' + escHtml(cat) + '</strong></td>';
                html += '<td style="text-align:center">' + count + '</td>';
                html += '<td style="text-align:center;font-size:18px;color:#9ca3af">→</td>';
                html += '<td>' + buildWooSelect(cat) + '</td>';
                html += '<td style="text-align:center" class="sws-catmap-status">' + getMatchStatus(cat, mappedId) + '</td>';
                html += '</tr>';
                $body.append(html);
            });
        }

        function showCatmapActions() {
            $('#sws-auto-match, #sws-save-catmap').show();
        }

        if (savedMappings && Object.keys(savedMappings).length > 0) {
            var savedCats = {};
            Object.keys(savedMappings).forEach(function(cat) {
                savedCats[cat] = '—';
            });
            renderCatmapRows(savedCats);
            $('#sws-catmap-table-wrap').show();
            showCatmapActions();
            $('#sws-catmap-status').text(Object.keys(savedMappings).length + ' saved mappings. Click "Load Square Categories" for product counts.');
            $('#sws-load-catmap').text('Reload from Square');
        }

        $('#sws-load-catmap').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Loading...');
            $('#sws-catmap-status').text('Fetching categories from Square...');
            $.ajax({
                url:    SWS.ajaxurl,
                method: 'POST',
                data:   { action: 'sws_get_categories', nonce: SWS.nonce },
                timeout: 120000,
                success: function(resp) {
                    $btn.prop('disabled', false).text('Reload from Square');
                    if (resp.success) {
                        var cats = resp.data.categories;
                        var total = resp.data.total_products;
                        Object.keys(savedMappings).forEach(function(cat) {
                            if (!(cat in cats)) cats[cat] = 0;
                        });
                        var catCount = Object.keys(cats).length;
                        $('#sws-catmap-status').text(catCount + ' categories (' + total + ' products)');
                        renderCatmapRows(cats);
                        $('#sws-catmap-table-wrap').show();
                        showCatmapActions();
                    } else {
                        $('#sws-catmap-status').text('Error: ' + (resp.data.message || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Reload from Square');
                    $('#sws-catmap-status').text('Request failed. Check your connection.');
                }
            });
        });

        $(document).on('change', '.sws-catmap-select', function() {
            var $sel = $(this);
            var sq = $sel.data('sq-cat');
            var wc = $sel.val();
            var $status = $sel.closest('tr').find('.sws-catmap-status');
            $status.html(getMatchStatus(sq, wc));
        });

        $('#sws-auto-match').on('click', function() {
            var matched = 0;
            $('.sws-catmap-select').each(function() {
                var $sel = $(this);
                if ($sel.val()) return;
                var sqName = $sel.data('sq-cat').toLowerCase().trim();
                var bestMatch = null;
                wooCats.forEach(function(wc) {
                    if (wc.name.toLowerCase().trim() === sqName) {
                        bestMatch = wc.id;
                    }
                });
                if (!bestMatch) {
                    var sqSlug = sqName.replace(/[^a-z0-9]+/g, '-');
                    wooCats.forEach(function(wc) {
                        if (wc.slug === sqSlug) bestMatch = wc.id;
                    });
                }
                if (!bestMatch) {
                    wooCats.forEach(function(wc) {
                        var wcNorm = wc.name.toLowerCase().replace(/[^a-z0-9]/g, '');
                        var sqNorm = sqName.replace(/[^a-z0-9]/g, '');
                        if (wcNorm === sqNorm) bestMatch = wc.id;
                    });
                }
                if (bestMatch) {
                    $sel.val(bestMatch).trigger('change');
                    matched++;
                }
            });
            var msg = matched > 0 ? matched + ' categories auto-matched!' : 'No matching names found.';
            $('#sws-catmap-save-status').text(msg).show();
            setTimeout(function() { $('#sws-catmap-save-status').fadeOut(); }, 3000);
        });

        $('#sws-save-catmap').on('click', function() {
            var $btn = $(this);
            var mappings = {};
            $('.sws-catmap-select').each(function() {
                var sq = $(this).data('sq-cat');
                var wc = $(this).val();
                if (sq && wc) {
                    mappings[sq] = parseInt(wc);
                }
            });

            $btn.prop('disabled', true).text('Saving...');
            $.ajax({
                url:    SWS.ajaxurl,
                method: 'POST',
                data:   { action: 'sws_save_catmap', nonce: SWS.nonce, mappings: JSON.stringify(mappings) },
                success: function(resp) {
                    $btn.prop('disabled', false).text('Save Mappings');
                    if (resp.success) {
                        savedMappings = mappings;
                        var msg = resp.data.saved + ' mappings saved!';
                        if (resp.data.skipped > 0) msg += ' (' + resp.data.skipped + ' invalid skipped)';
                        $('#sws-catmap-save-status').text(msg).show();
                        setTimeout(function() { $('#sws-catmap-save-status').fadeOut(); }, 3000);
                    } else {
                        alert('Error saving: ' + (resp.data.message || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Save Mappings');
                    alert('Save failed. Check your connection.');
                }
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // LOYALTY CUSTOMERS PAGE
    // ═══════════════════════════════════════════════════════════════════

    var $loyaltyTable = $('#sws-loyalty-table');
    if ($loyaltyTable.length) {
        var loyaltyData = [];
        try {
            loyaltyData = JSON.parse($('#sws-loyalty-data').text() || '[]');
        } catch(e) {}

        function renderLoyaltyTable(data) {
            var $tbody = $('#sws-loyalty-tbody');
            $tbody.empty();
            if (!data.length) {
                $tbody.append('<tr><td colspan="8" style="text-align:center;padding:40px;color:#6b7280">No loyalty customers imported yet. Click "Import from Square" to get started.</td></tr>');
                return;
            }
            for (var i = 0; i < data.length; i++) {
                var c = data[i];
                var enrolled = c.enrolled_at ? new Date(c.enrolled_at).toLocaleDateString() : '';
                $tbody.append(
                    '<tr>' +
                    '<td><input type="checkbox" class="sws-loyalty-cb" data-id="' + escHtml(c.customer_id) + '"></td>' +
                    '<td>' + (i+1) + '</td>' +
                    '<td><strong>' + escHtml(c.name || '—') + '</strong></td>' +
                    '<td>' + escHtml(c.phone || '—') + '</td>' +
                    '<td>' + escHtml(c.email || '—') + '</td>' +
                    '<td>' + (c.balance || 0) + '</td>' +
                    '<td>' + (c.lifetime_points || 0) + '</td>' +
                    '<td style="font-size:12px;color:#6b7280">' + escHtml(enrolled) + '</td>' +
                    '</tr>'
                );
            }
            $('#sws-loyalty-count').text(data.length + ' customer(s) imported');
        }

        renderLoyaltyTable(loyaltyData);

        // Select all checkbox
        $('#sws-loyalty-select-all').on('change', function() {
            var checked = $(this).prop('checked');
            $('.sws-loyalty-cb').prop('checked', checked);
        });

        // Search filter
        $('#sws-loyalty-search').on('input', function() {
            var q = $(this).val().toLowerCase();
            if (!q) {
                renderLoyaltyTable(loyaltyData);
                return;
            }
            var filtered = loyaltyData.filter(function(c) {
                return (c.name || '').toLowerCase().indexOf(q) !== -1 ||
                       (c.phone || '').indexOf(q) !== -1 ||
                       (c.email || '').toLowerCase().indexOf(q) !== -1;
            });
            renderLoyaltyTable(filtered);
        });

        // Import button
        $('#sws-import-loyalty-btn').on('click', function() {
            var $btn = $(this);
            var $status = $('#sws-loyalty-import-status');
            $btn.prop('disabled', true).text('Importing...');
            $status.show().css({background:'#eff6ff',border:'1px solid #bfdbfe',color:'#1e40af'}).text('Fetching loyalty customers from Square...');

            $.ajax({
                url:     SWS.ajaxurl,
                method:  'POST',
                data:    { action: 'sws_import_loyalty_customers', nonce: SWS.nonce },
                timeout: 120000,
                success: function(resp) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Import from Square');
                    if (resp.success) {
                        loyaltyData = resp.data.customers || [];
                        renderLoyaltyTable(loyaltyData);
                        $status.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'}).text(resp.data.message);
                    } else {
                        $status.css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'}).text('Import failed: ' + (resp.data || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Import from Square');
                    $status.css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'}).text('Import failed. Check your connection and Square API settings.');
                }
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // SMS CAMPAIGNS PAGE
    // ═══════════════════════════════════════════════════════════════════

    var $smsMessage = $('#sws-sms-message');
    if ($smsMessage.length) {

        // Character counter with segment warning
        $smsMessage.on('input', function() {
            var len = $(this).val().length;
            var segments = Math.ceil(len / 160) || 1;
            var $charCount = $('#sws-sms-char-count');
            var $segCount = $('#sws-sms-segment-count');
            $charCount.text(len + ' / 160 characters');
            if (len > 160) {
                $charCount.css('color', '#d97706');
                $segCount.css('color', '#dc2626').text('(' + segments + ' segments — longer messages are more likely to be carrier-filtered)');
            } else {
                $charCount.css('color', '#6b7280');
                $segCount.css('color', '#6b7280').text('(' + segments + ' segment)');
            }
        });

        // Send test SMS
        $('#sws-test-sms-btn').on('click', function() {
            var msg = $smsMessage.val().trim();
            if (!msg) { alert('Please enter a message first.'); return; }

            var phone = $('#sws-test-sms-phone').val().trim();
            if (!phone) { alert('Enter a phone number in the test field first.'); $('#sws-test-sms-phone').focus(); return; }

            // Auto-append STOP if checkbox is checked and not already in message
            if ($('#sws-sms-append-stop').is(':checked') && msg.toUpperCase().indexOf('STOP') === -1) {
                msg += '\nReply STOP to opt out.';
            }

            var $btn = $(this);
            var $status = $('#sws-sms-send-status');
            $btn.prop('disabled', true).text('Sending...');
            $status.text('Sending test SMS...');

            $.ajax({
                url:     SWS.ajaxurl,
                method:  'POST',
                data:    { action: 'sws_send_test_sms', nonce: SWS.nonce, to: phone, message: msg },
                timeout: 30000,
                success: function(resp) {
                    $btn.prop('disabled', false).text('Send Test SMS');
                    $status.text(resp.success ? resp.data.message : ('Error: ' + (resp.data || 'Unknown error')));
                },
                error: function() {
                    $btn.prop('disabled', false).text('Send Test SMS');
                    $status.text('Failed to send test SMS.');
                }
            });
        });

        // ── Opt-Out Management ───────────────────────────────────────
        function showOptOutStatus(msg, ok) {
            var $s = $('#sws-optout-status');
            $s.show().css({
                background: ok ? '#f0fdf4' : '#fef2f2',
                border: '1px solid ' + (ok ? '#bbf7d0' : '#fecaca'),
                color: ok ? '#166534' : '#991b1b'
            }).text(msg);
        }

        $('#sws-optout-add-btn').on('click', function() {
            var phone = $('#sws-optout-phone').val().trim();
            if (!phone) { alert('Enter a phone number.'); return; }
            $.ajax({
                url: SWS.ajaxurl, method: 'POST',
                data: { action: 'sws_add_opt_out', nonce: SWS.nonce, phones: phone },
                success: function(resp) {
                    if (resp.success) { showOptOutStatus(resp.data.message, true); setTimeout(function(){ location.reload(); }, 1000); }
                    else { showOptOutStatus(resp.data || 'Error', false); }
                }
            });
        });

        $('#sws-optout-paste-btn').on('click', function() {
            $('#sws-optout-paste-area').toggle();
        });
        $('#sws-optout-paste-cancel').on('click', function() {
            $('#sws-optout-paste-area').hide();
        });
        $('#sws-optout-paste-save').on('click', function() {
            var phones = $('#sws-optout-paste-input').val().trim();
            if (!phones) { alert('Paste phone numbers first.'); return; }
            $.ajax({
                url: SWS.ajaxurl, method: 'POST',
                data: { action: 'sws_add_opt_out', nonce: SWS.nonce, phones: phones },
                success: function(resp) {
                    if (resp.success) { showOptOutStatus(resp.data.message, true); setTimeout(function(){ location.reload(); }, 1000); }
                    else { showOptOutStatus(resp.data || 'Error', false); }
                }
            });
        });

        $(document).on('click', '.sws-optout-remove-btn', function() {
            var phone = $(this).data('phone');
            if (!confirm('Remove ' + phone + ' from the opt-out list? They will receive SMS again.')) return;
            var $row = $(this).closest('tr');
            $.ajax({
                url: SWS.ajaxurl, method: 'POST',
                data: { action: 'sws_remove_opt_out', nonce: SWS.nonce, phone: phone },
                success: function(resp) {
                    if (resp.success) { $row.fadeOut(300, function(){ $(this).remove(); }); showOptOutStatus(resp.data.message, true); }
                    else { showOptOutStatus(resp.data || 'Error', false); }
                }
            });
        });

        // Min-points live counter
        function updatePointsCount() {
            var min = parseInt($('#sws-sms-min-points').val()) || 0;
            $.ajax({
                url: SWS.ajaxurl, method: 'POST',
                data: { action: 'sws_get_loyalty_customers', nonce: SWS.nonce },
                success: function(resp) {
                    if (!resp.success) return;
                    var count = 0;
                    (resp.data.customers || []).forEach(function(c) {
                        if (c.phone && (parseInt(c.balance) || 0) >= min) count++;
                    });
                    $('#sws-sms-points-count').text('(' + count + ' qualifying)');
                }
            });
        }
        $('input[name="sws_sms_recipients"]').on('change', function() {
            if ($(this).val() === 'min_points') updatePointsCount();
        });
        $('#sws-sms-min-points').on('input', function() {
            if ($('input[name="sws_sms_recipients"]:checked').val() === 'min_points') updatePointsCount();
        });

        // Send campaign
        $('#sws-send-sms-btn').on('click', function() {
            var msg = $smsMessage.val().trim();
            var name = $('#sws-sms-campaign-name').val().trim() || 'Untitled Campaign';
            if (!msg) { alert('Please enter a message.'); return; }

            var recipientMode = $('input[name="sws_sms_recipients"]:checked').val();
            var selectedIds = [];
            var minPoints = 0;

            if (recipientMode === 'selected') {
                $('.sws-loyalty-cb:checked').each(function() {
                    selectedIds.push($(this).data('id'));
                });
                if (!selectedIds.length) {
                    alert('No customers selected. Go to the Loyalty Customers page and select customers, or choose "All loyalty customers".');
                    return;
                }
            }

            if (recipientMode === 'min_points') {
                minPoints = parseInt($('#sws-sms-min-points').val()) || 0;
                if (minPoints < 1) { alert('Please enter a minimum points value.'); return; }
            }

            var totalCount = $('#sws-sms-total-count').text();
            var confirmMsg;
            if (recipientMode === 'selected') {
                confirmMsg = 'Send SMS to ' + selectedIds.length + ' selected customer(s)?';
            } else if (recipientMode === 'min_points') {
                confirmMsg = 'Send SMS to loyalty customers with ' + minPoints + '+ points?';
            } else {
                confirmMsg = 'Send SMS to all ' + totalCount + ' loyalty customers with phone numbers?';
            }

            var fullMsg = msg;
            if ($('#sws-sms-append-stop').is(':checked') && msg.toUpperCase().indexOf('STOP') === -1) {
                fullMsg += '\nReply STOP to opt out.';
            }
            var msgLen = fullMsg.length;
            var segWarning = '';
            if (msgLen > 160) {
                var segs = Math.ceil(msgLen / 160);
                segWarning = '\n\n⚠ WARNING: Message is ' + msgLen + ' chars (' + segs + ' segments). Multi-segment messages are more likely to be filtered by carriers. Consider shortening to under 160 characters.';
            }

            if (!confirm(confirmMsg + '\n\nCampaign: ' + name + '\nMessage: ' + msg.substring(0, 100) + (msg.length > 100 ? '...' : '') + segWarning)) return;

            var $btn = $(this);
            var $progress = $('#sws-sms-progress');
            var $result = $('#sws-sms-result');
            $btn.prop('disabled', true);
            $progress.show();
            $result.hide();
            $('#sws-sms-progress-text').text('Sending messages...');
            $('#sws-sms-progress-fill').css('width', '50%');
            $('#sws-sms-progress-pct').text('...');

            $.ajax({
                url:     SWS.ajaxurl,
                method:  'POST',
                data:    {
                    action:        'sws_send_sms_campaign',
                    nonce:         SWS.nonce,
                    campaign_name: name,
                    message:       msg,
                    recipient_mode: recipientMode,
                    selected_ids:  selectedIds,
                    min_points:    minPoints,
                    append_stop:   $('#sws-sms-append-stop').is(':checked') ? '1' : '0'
                },
                timeout: 600000,
                success: function(resp) {
                    $btn.prop('disabled', false);
                    $progress.hide();
                    $result.show();
                    if (resp.success) {
                        var d = resp.data;
                        $result.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'})
                            .html('<strong>' + escHtml(d.message) + '</strong>' +
                                (d.errors && d.errors.length ? '<br><br><strong>Errors:</strong><br>' + d.errors.map(escHtml).join('<br>') : ''));
                    } else {
                        $result.css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                            .text('Campaign failed: ' + (resp.data || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $progress.hide();
                    $result.show().css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                        .text('Campaign failed. Check your connection.');
                }
            });
        });
    }

    // ── Clean Up Duplicate Products ────────────────────────────────
    $('#sws-cleanup-copies-btn').on('click', function() {
        if (!confirm('This will trash all WooCommerce products with "(Copy)" in the title that have a Square Product ID. Continue?')) {
            return;
        }

        var $btn = $(this);
        var $result = $('#sws-cleanup-result');

        $btn.prop('disabled', true).text('Cleaning up...');
        $result.hide();

        $.ajax({
            url:     SWS.ajaxurl,
            method:  'POST',
            data:    { action: 'sws_cleanup_copy_products', nonce: SWS.nonce },
            timeout: 120000,
            success: function(resp) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-trash" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Clean Up Duplicate Products'
                );
                $result.show();
                if (resp.success) {
                    var d = resp.data;
                    if (d.cleaned === 0) {
                        $result.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'})
                            .html('<strong>' + escHtml(d.message) + '</strong>');
                    } else {
                        var html = '<strong>' + escHtml(d.message) + '</strong>';
                        if (d.details && d.details.length) {
                            html += '<ul style="margin:8px 0 0;padding-left:18px;font-size:12px">';
                            for (var i = 0; i < d.details.length; i++) {
                                html += '<li>' + escHtml(d.details[i]) + '</li>';
                            }
                            html += '</ul>';
                        }
                        $result.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'}).html(html);
                    }
                } else {
                    $result.css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                        .text('Cleanup failed: ' + (resp.data || 'Unknown error'));
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-trash" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Clean Up Duplicate Products'
                );
                $result.show().css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                    .text('Cleanup failed. Check your connection.');
            }
        });
    });

    // ── Fix Duplicate "Option" Attributes ──────────────────────────
    $('#sws-cleanup-option-attrs-btn').on('click', function() {
        if (!confirm('This will merge the generic "Option" attribute into the real attribute (e.g. "Flavors") on all affected products and rewrite variation meta. Continue?')) {
            return;
        }

        var $btn = $(this);
        var $result = $('#sws-option-attrs-result');

        $btn.prop('disabled', true).text('Fixing attributes...');
        $result.hide();

        $.ajax({
            url:     SWS.ajaxurl,
            method:  'POST',
            data:    { action: 'sws_cleanup_option_attrs', nonce: SWS.nonce },
            timeout: 120000,
            success: function(resp) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-admin-generic" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Fix Duplicate Option Attributes'
                );
                $result.show();
                if (resp.success) {
                    var d = resp.data;
                    if (d.fixed === 0) {
                        $result.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'})
                            .html('<strong>' + escHtml(d.message) + '</strong>');
                    } else {
                        var html = '<strong>' + escHtml(d.message) + '</strong>';
                        if (d.details && d.details.length) {
                            html += '<ul style="margin:8px 0 0;padding-left:18px;font-size:12px">';
                            for (var i = 0; i < d.details.length; i++) {
                                html += '<li>' + escHtml(d.details[i]) + '</li>';
                            }
                            html += '</ul>';
                        }
                        $result.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'}).html(html);
                    }
                } else {
                    $result.css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                        .text('Fix failed: ' + (resp.data || 'Unknown error'));
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-admin-generic" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Fix Duplicate Option Attributes'
                );
                $result.show().css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                    .text('Fix failed. Check your connection.');
            }
        });
    });

    // ── Delete "Any Flavor / Any Option" Variations ────────────────
    $('#sws-delete-any-vars-btn').on('click', function() {
        if (!confirm('This will PERMANENTLY DELETE all variations with empty attribute values ("Any Flavor...", "Any Option..."). They will be re-created on the next sync. Continue?')) {
            return;
        }

        var $btn = $(this);
        var $result = $('#sws-delete-any-vars-result');

        $btn.prop('disabled', true).text('Deleting...');
        $result.hide();

        $.ajax({
            url:     SWS.ajaxurl,
            method:  'POST',
            data:    { action: 'sws_delete_any_variations', nonce: SWS.nonce },
            timeout: 180000,
            success: function(resp) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-dismiss" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Delete "Any" Variations'
                );
                $result.show();
                if (resp.success) {
                    var d = resp.data;
                    if (d.deleted === 0) {
                        $result.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'})
                            .html('<strong>' + escHtml(d.message) + '</strong>');
                    } else {
                        var html = '<strong>' + escHtml(d.message) + '</strong>';
                        if (d.details && d.details.length) {
                            html += '<ul style="margin:8px 0 0;padding-left:18px;font-size:12px;max-height:200px;overflow-y:auto">';
                            for (var i = 0; i < d.details.length; i++) {
                                html += '<li>' + escHtml(d.details[i]) + '</li>';
                            }
                            html += '</ul>';
                        }
                        $result.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'}).html(html);
                    }
                } else {
                    $result.css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                        .text('Delete failed: ' + (resp.data || 'Unknown error'));
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-dismiss" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span> Delete "Any" Variations'
                );
                $result.show().css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
                    .text('Delete failed. Check your connection.');
            }
        });
    });

})(jQuery);
