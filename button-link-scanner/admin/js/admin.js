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

    function ajax(action, data, done, fail) {
        $.post(BLS.ajax_url, $.extend({ action: action, nonce: BLS.nonce }, data))
            .done(function (res) {
                if (res.success) {
                    done(res.data);
                } else {
                    if (fail) fail(res.data || 'An error occurred.');
                }
            })
            .fail(function () {
                if (fail) fail('Request failed. Please try again.');
            });
    }

    // -------------------------------------------------------------------------
    // Dashboard: Run Scan
    // -------------------------------------------------------------------------

    $('#bls-run-scan').on('click', function () {
        var $btn    = $(this);
        var $status = $('#bls-scan-status');

        $btn.prop('disabled', true);
        setStatus($status, BLS.strings.scanning + spinner(), '');

        ajax('bls_run_scan', {}, function (data) {
            $btn.prop('disabled', false);
            var msg = BLS.strings.scan_complete + ' ' + (data.buttons_found || 0) + ' buttons found.';
            setStatus($status, msg, 'ok');
            // Reload to refresh stats.
            setTimeout(function () { location.reload(); }, 1200);
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Dashboard: Scheduled scanning
    // -------------------------------------------------------------------------

    $('#bls-save-schedule').on('click', function () {
        var $status = $('#bls-schedule-status');
        var freq    = $('#bls-schedule-freq').val();

        setStatus($status, spinner(), '');

        ajax('bls_toggle_schedule', { freq: freq }, function (data) {
            var msg = data.freq ? 'Schedule saved: ' + data.freq : 'Scheduling disabled.';
            setStatus($status, msg, 'ok');
        }, function (err) {
            setStatus($status, err, 'error');
        });
    });

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

}(jQuery));
