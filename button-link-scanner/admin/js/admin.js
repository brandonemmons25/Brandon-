/* Button Link Scanner - Admin JS */
/* global BLS, jQuery */

(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function spinner() {
        return '<span class="bls-spinner"></span>';
    }

    function setStatus($el, msg, type) {
        $el.removeClass('bls-status--ok bls-status--error')
            .addClass(type === 'ok' ? 'bls-status--ok' : type === 'error' ? 'bls-status--error' : '')
            .html(msg);
    }

    function ajax(action, data, done, fail, opts) {
        opts = opts || {};
        var retries = opts.retries || 0;
        var maxRetries = opts.maxRetries !== undefined ? opts.maxRetries : 3;

        $.post(BLS.ajax_url, $.extend({ action: action, nonce: BLS.nonce }, data))
            .done(function (res) {
                if (res.success) {
                    done(res.data);
                } else {
                    if (fail) fail(res.data || 'An error occurred.');
                }
            })
            .fail(function (jqXHR) {
                // Transient errors (rate limiting, brief 502/504, dropped
                // connection) get a few automatic retries with backoff
                // before we give up and report to the user.
                var status = jqXHR ? jqXHR.status : 0;
                var transient = (status === 0 || status === 429 || status === 502 || status === 503 || status === 504);

                if (transient && retries < maxRetries) {
                    var delay = 1000 * Math.pow(2, retries); // 1s, 2s, 4s
                    setTimeout(function () {
                        ajax(action, data, done, fail, { retries: retries + 1, maxRetries: maxRetries });
                    }, delay);
                    return;
                }

                // Build a real diagnostic instead of a generic message, so
                // the actual cause shows up right on the page.
                var statusText = jqXHR && jqXHR.statusText ? jqXHR.statusText : 'no connection';
                var bodySnippet = '';
                if (jqXHR && jqXHR.responseText) {
                    bodySnippet = jqXHR.responseText.replace(/<[^>]*>/g, ' ').trim().substring(0, 200);
                }
                var msg = 'Request failed (HTTP ' + (status || '0') + ' ' + statusText + ')'
                    + (retries > 0 ? ' after ' + retries + ' retr' + (retries === 1 ? 'y' : 'ies') : '')
                    + (bodySnippet ? ': ' + bodySnippet : '');

                if (fail) fail(msg);
            });
    }

    // -------------------------------------------------------------------------
    // Dashboard: Run Scan
    // -------------------------------------------------------------------------

    $('#bls-run-scan').on('click', function () {
        var $btn    = $('#bls-run-scan, #bls-resume-scan');
        var $status = $('#bls-scan-status');

        $btn.prop('disabled', true);
        setStatus($status, BLS.strings.scanning + spinner(), '');

        // Step 1: build the work queue (fast, single request).
        ajax('bls_scan_start', {}, function () {
            runScanBatchLoop($btn, $status);
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    });

    // Resume a scan that was interrupted (browser tab navigated away or
    // closed mid-run) — the server-side queue/progress are still intact,
    // so this just continues the batch loop instead of rebuilding the
    // queue from scratch via bls_scan_start.
    $('#bls-resume-scan').on('click', function () {
        var $btn    = $('#bls-run-scan, #bls-resume-scan');
        var $status = $('#bls-scan-status');

        $btn.prop('disabled', true);
        setStatus($status, BLS.strings.scanning + spinner(), '');
        runScanBatchLoop($btn, $status);
    });

    // Step 2 (shared by both Run and Resume): process the queue a few
    // posts at a time, looping until the server reports done = true.
    // Each request is small and fast, so no single HTTP call risks a
    // gateway/PHP timeout regardless of site size. IMPORTANT: this loop
    // only continues while this browser tab/page stays open — navigating
    // away mid-scan stops it, leaving the server-side queue incomplete
    // until "Resume Interrupted Scan" (shown automatically on next
    // Dashboard load) is used to pick it back up.
    function runScanBatchLoop($btn, $status) {
        ajax('bls_scan_batch', { batch_size: 5 }, function (data) {
            var pct = data.total_items > 0
                ? Math.round((data.processed / data.total_items) * 100)
                : 100;
            setStatus(
                $status,
                BLS.strings.scanning + ' (' + data.processed + '/' + data.total_items + ' — ' + pct + '%)' + spinner(),
                ''
            );

            if (data.done) {
                $btn.prop('disabled', false);
                var msg = BLS.strings.scan_complete + ' ' + (data.buttons_found || 0) + ' buttons found.';
                setStatus($status, msg, 'ok');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                // Small pause between batches — avoids firing a rapid
                // burst of requests that some hosting firewalls/rate
                // limiters may flag as bot-like traffic.
                setTimeout(function () { runScanBatchLoop($btn, $status); }, 400);
            }
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    }

    // -------------------------------------------------------------------------
    // Results / Trends: "Add to Map" quick-button
    // -------------------------------------------------------------------------

    $(document).on('click', '.bls-add-to-map', function () {
        var text = $(this).data('text');
        var url  = $(this).data('url') || '';

        // If we're on the map page, just populate the form.
        if ($('#bls-map-text').length) {
            $('#bls-map-text').val(text);
            $('#bls-map-url').val(url);
            $('html, body').animate({ scrollTop: $('#bls-map-form-card').offset().top - 40 }, 300);
            $('#bls-map-url').focus();
        } else {
            // Navigate to map page with prefill params.
            window.location.href = ajaxurl.replace('admin-ajax.php', '') +
                'admin.php?page=button-link-scanner-map&prefill_text=' +
                encodeURIComponent(text) + '&prefill_url=' + encodeURIComponent(url);
        }
    });

    // Pre-fill map form from URL params.
    (function () {
        var params = new URLSearchParams(window.location.search);
        if (params.get('prefill_text')) {
            $('#bls-map-text').val(decodeURIComponent(params.get('prefill_text')));
        }
        if (params.get('prefill_url')) {
            $('#bls-map-url').val(decodeURIComponent(params.get('prefill_url')));
        }
    }());

    // -------------------------------------------------------------------------
    // Button Map: Save entry
    // -------------------------------------------------------------------------

    $('#bls-save-map-entry').on('click', function () {
        var $btn    = $(this);
        var $status = $('#bls-map-save-status');

        var text    = $.trim($('#bls-map-text').val());
        var url     = $.trim($('#bls-map-url').val());
        var title   = $.trim($('#bls-map-title').val());
        var new_tab = $('#bls-map-new-tab').is(':checked') ? 1 : 0;

        if (!text || !url) {
            setStatus($status, 'Button label and URL are required.', 'error');
            return;
        }

        $btn.prop('disabled', true);
        setStatus($status, 'Saving...' + spinner(), '');

        ajax('bls_save_map_entry', {
            button_text:    text,
            assigned_url:   url,
            assigned_title: title,
            opens_new_tab:  new_tab,
        }, function () {
            $btn.prop('disabled', false);
            setStatus($status, 'Saved!', 'ok');
            // Clear form.
            $('#bls-map-text, #bls-map-url, #bls-map-title').val('');
            $('#bls-map-new-tab').prop('checked', false);
            setTimeout(function () { location.reload(); }, 900);
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Button Map: Edit entry (populate form)
    // -------------------------------------------------------------------------

    $(document).on('click', '.bls-edit-map', function () {
        var $btn = $(this);
        $('#bls-map-text').val($btn.data('text'));
        $('#bls-map-url').val($btn.data('url'));
        $('#bls-map-title').val($btn.data('title'));
        $('#bls-map-new-tab').prop('checked', $btn.data('new-tab') == 1);
        $('html, body').animate({ scrollTop: $('#bls-map-form-card').offset().top - 40 }, 300);
        $('#bls-map-url').focus();
    });

    // -------------------------------------------------------------------------
    // Button Map: Delete entry
    // -------------------------------------------------------------------------

    $(document).on('click', '.bls-delete-map', function () {
        if (!confirm(BLS.strings.confirm_delete)) return;

        var $row = $(this).closest('tr');
        var id   = $(this).data('id');

        ajax('bls_delete_map', { id: id }, function () {
            $row.next('.bls-preview-row').remove();
            $row.fadeOut(300, function () { $(this).remove(); });
        });
    });

    // -------------------------------------------------------------------------
    // Button Map: Preview apply
    // -------------------------------------------------------------------------

    $(document).on('click', '.bls-preview-apply', function () {
        var mapId    = $(this).data('map-id');
        var $preRow  = $('#bls-preview-' + mapId);
        var $content = $preRow.find('.bls-preview-content');

        if ($preRow.is(':visible')) {
            $preRow.hide();
            return;
        }

        $content.html('<em>Loading preview...</em>' + spinner());
        $preRow.show();

        ajax('bls_preview_apply', { map_id: mapId }, function (rows) {
            if (!rows || rows.length === 0) {
                $content.html('<em>No matching buttons found in the current scan results.</em>');
                return;
            }

            var html = '<table class="bls-preview-table"><thead><tr>' +
                '<th>Post / Page</th><th>Type</th><th>Current URL</th><th>Has Title?</th>' +
                '</tr></thead><tbody>';

            $.each(rows, function (i, r) {
                var editUrl = window.location.origin + '/wp-admin/post.php?post=' + r.post_id + '&action=edit';
                html += '<tr>' +
                    '<td><a href="' + escHtml(r.post_url) + '" target="_blank">' + escHtml(r.post_title) + '</a> ' +
                    '(<a href="' + escHtml(editUrl) + '" target="_blank">edit</a>)</td>' +
                    '<td>' + escHtml(r.post_type) + '</td>' +
                    '<td>' + (r.link_url ? '<a href="' + escHtml(r.link_url) + '" target="_blank">' + escHtml(r.link_url) + '</a>' : '<em>none</em>') + '</td>' +
                    '<td>' + (parseInt(r.has_title) ? '&#10003;' : '&#10007;') + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            $content.html(html);
        }, function (err) {
            $content.html('<em class="bls-text--danger">' + err + '</em>');
        });
    });

    // -------------------------------------------------------------------------
    // Button Map: Apply single entry
    // -------------------------------------------------------------------------

    $(document).on('click', '.bls-apply-single', function () {
        var $btn   = $(this);
        var mapId  = $btn.data('map-id');
        var $row   = $btn.closest('tr');

        if (!confirm(BLS.strings.confirm_apply)) return;

        $btn.prop('disabled', true).text(BLS.strings.applying);

        ajax('bls_apply_map', { map_id: mapId }, function (data) {
            $btn.prop('disabled', false).text('Apply');
            var msg = 'Done: ' + (data.updated_posts || 0) + ' posts updated, ' +
                      (data.updated_buttons || 0) + ' buttons rewritten.';
            alert(msg);
            location.reload();
        }, function (err) {
            $btn.prop('disabled', false).text('Apply');
            alert('Error: ' + err);
        });
    });

    // -------------------------------------------------------------------------
    // Button Map: Apply ALL entries
    // -------------------------------------------------------------------------

    $('#bls-apply-all').on('click', function () {
        var $btn    = $(this);
        var $status = $('#bls-apply-all-status');

        if (!confirm(BLS.strings.confirm_apply)) return;

        $btn.prop('disabled', true);
        setStatus($status, BLS.strings.applying + spinner(), '');

        ajax('bls_apply_map', { map_id: 0 }, function (data) {
            $btn.prop('disabled', false);
            var total_posts    = 0;
            var total_buttons  = 0;
            $.each(data, function (id, result) {
                total_posts   += (result.updated_posts   || 0);
                total_buttons += (result.updated_buttons || 0);
            });
            var msg = BLS.strings.apply_complete +
                ' ' + total_posts + ' posts updated, ' + total_buttons + ' buttons rewritten.';
            setStatus($status, msg, 'ok');
            setTimeout(function () { location.reload(); }, 1500);
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // GF Confirmations: Run Scan
    // -------------------------------------------------------------------------

    $('#bls-run-gf-scan').on('click', function () {
        var $btn    = $(this);
        var $status = $('#bls-gf-scan-status');

        $btn.prop('disabled', true);
        setStatus($status, BLS.strings.scanning + spinner(), '');

        ajax('bls_run_gf_scan', {}, function (data) {
            $btn.prop('disabled', false);
            var msg;
            if (data.error) {
                msg = data.error;
                setStatus($status, msg, 'error');
            } else {
                msg = BLS.strings.scan_complete + ' ' +
                      (data.forms_scanned || 0) + ' forms scanned, ' +
                      (data.issues_found  || 0) + ' issues found.';
                setStatus($status, msg, 'ok');
                setTimeout(function () { location.reload(); }, 1200);
            }
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Dashboard: Auto-Fill Missing Titles
    // -------------------------------------------------------------------------

    $('#bls-auto-fill-titles').on('click', function () {
        var $btn    = $(this);
        var $status = $('#bls-auto-fill-status');

        if (!confirm('This will add a descriptive title to every button or hyperlink that has a link but no title, writing directly into the actual page content where possible. Existing titles are never touched. This can take a while on a large site — it runs in small batches so it never times out. Continue?')) {
            return;
        }

        $btn.prop('disabled', true);
        setStatus($status, 'Starting...' + spinner(), '');

        // Same two-step batched pattern as Run Scan: build the queue once
        // (fast), then process it a few pairs at a time via repeated
        // requests, so no single HTTP call risks a PHP/gateway timeout
        // regardless of how many buttons/hyperlinks are missing a title.
        ajax('bls_auto_fill_start', {}, function () {
            runAutoFillBatchLoop($btn, $status);
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    });

    function runAutoFillBatchLoop($btn, $status) {
        ajax('bls_auto_fill_batch', { batch_size: 5 }, function (data) {
            var pct = data.total_items > 0
                ? Math.round((data.pairs_processed / data.total_items) * 100)
                : 100;
            setStatus(
                $status,
                'Generating titles... (' + data.pairs_processed + '/' + data.total_items + ' — ' + pct + '%)' + spinner(),
                ''
            );

            if (data.done) {
                $btn.prop('disabled', false);
                var msg = data.titles_added + ' title(s) added across ' + data.posts_updated + ' page(s).';
                if (data.titles_injected > 0) {
                    msg += ' ' + data.titles_injected + ' more added live at render time (dynamic/shortcode-generated buttons with no stored anchor to write to directly).';
                }
                if (data.could_not_apply > 0) {
                    msg += ' ' + data.could_not_apply + ' button(s) couldn\'t be fixed at all — see the Dashboard for why.';
                }
                setStatus($status, msg, 'ok');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                // Small pause between batches — avoids firing a rapid
                // burst of requests that some hosting firewalls/rate
                // limiters may flag as bot-like traffic.
                setTimeout(function () { runAutoFillBatchLoop($btn, $status); }, 400);
            }
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    }

    // -------------------------------------------------------------------------
    // Dashboard: Broken Link Monitoring — "Check Links Now"
    // -------------------------------------------------------------------------

    $('#bls-check-links-now').on('click', function () {
        var $btn    = $(this);
        var $status = $('#bls-link-check-status');

        $btn.prop('disabled', true);
        setStatus($status, 'Checking links...' + spinner(), '');

        ajax('bls_link_check_start', {}, function () {
            runNextTick();
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });

        function runNextTick() {
            ajax('bls_link_check_tick', {}, function (data) {
                if (data.done) {
                    $btn.prop('disabled', false);
                    var msg = data.broken_count > 0
                        ? data.broken_count + ' broken link(s) found.'
                        : 'All links check out fine.';
                    setStatus($status, msg, data.broken_count > 0 ? 'error' : 'ok');
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    setStatus($status, 'Checking links... (' + data.remaining + ' remaining)' + spinner(), '');
                    setTimeout(runNextTick, 400);
                }
            }, function (err) {
                $btn.prop('disabled', false);
                setStatus($status, err, 'error');
            });
        }
    });

    // -------------------------------------------------------------------------
    // Dashboard: Broken Link Monitoring — save schedule
    // -------------------------------------------------------------------------

    $('#bls-save-link-schedule').on('click', function () {
        var $status = $('#bls-link-schedule-status');
        var freq    = $('#bls-link-check-freq').val();
        var email   = $.trim($('#bls-link-check-email').val());

        setStatus($status, spinner(), '');

        ajax('bls_save_link_schedule', { freq: freq, email: email }, function (data) {
            var msg = data.freq ? 'Saved — checking ' + data.freq + '.' : 'Automatic checking disabled.';
            setStatus($status, msg, 'ok');
        }, function (err) {
            setStatus($status, err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Broken Links page: Mark Fixed
    // -------------------------------------------------------------------------

    $(document).on('click', '.bls-dismiss-broken-link', function () {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var id   = $btn.data('id');

        $btn.prop('disabled', true);

        ajax('bls_dismiss_broken_link', { id: id }, function () {
            $row.fadeOut(300, function () { $(this).remove(); });
        }, function (err) {
            $btn.prop('disabled', false);
            alert('Error: ' + err);
        });
    });

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Dashboard: Remove Auto-Filled Titles (undo)
    // -------------------------------------------------------------------------

    $('#bls-wipe-titles').on('click', function () {
        var $btn    = $(this);
        var $status = $('#bls-wipe-status');

        if (!confirm(
            'This removes title attributes that merely repeat the link\'s own text — the ones Auto-Fill generates.\n\n'
            + 'Titles a person wrote that say something different are left alone. Any titles being applied live at render time are also cleared.\n\n'
            + 'This edits page content and cannot be undone automatically. Continue?'
        )) {
            return;
        }

        $btn.prop('disabled', true);
        setStatus($status, 'Starting...' + spinner(), '');

        ajax('bls_wipe_titles_start', {}, function () {
            runWipeBatchLoop($btn, $status);
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    });

    function runWipeBatchLoop($btn, $status) {
        ajax('bls_wipe_titles_batch', { batch_size: 5 }, function (data) {
            var pct = data.total_items > 0
                ? Math.round((data.posts_processed / data.total_items) * 100)
                : 100;
            setStatus(
                $status,
                'Removing titles... (' + data.posts_processed + '/' + data.total_items + ' — ' + pct + '%)' + spinner(),
                ''
            );

            if (data.done) {
                $btn.prop('disabled', false);
                setStatus(
                    $status,
                    data.titles_removed + ' title(s) removed across ' + data.posts_changed + ' page(s).',
                    'ok'
                );
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                setTimeout(function () { runWipeBatchLoop($btn, $status); }, 400);
            }
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    }

    // -------------------------------------------------------------------------
    // Auto-resume interrupted batch jobs on page load
    // -------------------------------------------------------------------------
    // Both Site Scan and Auto-Fill run as a client-side loop tied to one
    // specific page load's JS — closing the tab, navigating away, or even
    // just reloading the dashboard to check progress kills the loop with
    // no way for it to continue on its own. Requiring a manual click to
    // pick it back up every single time that happens is easy to forget
    // and easy to mistake for the whole feature being broken (this is
    // exactly what made testing on a 1300+ page site so confusing — a
    // scan repeatedly looked "stuck" or "reset" when it had actually just
    // been interrupted by an ordinary page reload). Instead, the moment
    // this page loads, pick either job back up automatically if the
    // server-side queue/progress from a previous run is still sitting
    // there unfinished. Continues the EXISTING stored queue (not a
    // restart) for the scan; Auto-Fill is naturally safe to just
    // re-request a batch against its existing queue the same way, since
    // run_auto_fill_batch() only ever consumes from what's already
    // stored. The manual "Resume Interrupted Scan" button stays as a
    // visible fallback/indicator that something was mid-run.
    if ($('#bls-resume-scan').length) {
        var $scanBtn = $('#bls-run-scan, #bls-resume-scan');
        var $scanStatus = $('#bls-scan-status');
        $scanBtn.prop('disabled', true);
        setStatus($scanStatus, BLS.strings.scanning + spinner(), '');
        runScanBatchLoop($scanBtn, $scanStatus);
    }
    if ($('#bls-autofill-abandoned-notice').length) {
        var $fillBtn = $('#bls-auto-fill-titles');
        var $fillStatus = $('#bls-auto-fill-status');
        $fillBtn.prop('disabled', true);
        setStatus($fillStatus, 'Resuming interrupted run...' + spinner(), '');
        runAutoFillBatchLoop($fillBtn, $fillStatus);
    }
    if ($('#bls-wipe-abandoned-notice').length) {
        var $wipeBtn = $('#bls-wipe-titles');
        var $wipeStatus = $('#bls-wipe-status');
        $wipeBtn.prop('disabled', true);
        setStatus($wipeStatus, 'Resuming interrupted removal...' + spinner(), '');
        runWipeBatchLoop($wipeBtn, $wipeStatus);
    }

}(jQuery));
