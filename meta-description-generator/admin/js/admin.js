/* Meta Description Generator — Admin JS */
/* global MDG, jQuery */

(function ($) {
    'use strict';

    var MIN = MDG.min;  // 120
    var MAX = MDG.max;  // 158

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function spinner() { return '<span class="mdg-spinner"></span>'; }

    function setStatus($el, msg, type) {
        $el.removeClass('mdg-status--ok mdg-status--error')
           .addClass(type === 'ok' ? 'mdg-status--ok' : type === 'error' ? 'mdg-status--error' : '')
           .html(msg);
    }

    function ajax(action, data, done, fail) {
        $.post(MDG.ajax_url, $.extend({ action: action, nonce: MDG.nonce }, data))
            .done(function (res) {
                if (res.success) { done(res.data); }
                else if (fail)   { fail(res.data || 'An error occurred.'); }
            })
            .fail(function () { if (fail) fail('Request failed. Please try again.'); });
    }

    /**
     * Update the character counter and textarea border for a row.
     * Highlights the 120-char mobile cutoff as an amber threshold.
     */
    function updateCounter($ta) {
        var $row     = $ta.closest('tr');
        var $counter = $row.find('.mdg-char-counter');
        var $val     = $counter.find('.mdg-counter-val');
        var $msg     = $counter.find('.mdg-counter-msg');
        var len      = $ta.val().length;

        $val.text(len);
        $counter.removeClass('mdg-counter--ok mdg-counter--warn mdg-counter--danger');
        $ta.removeClass('mdg-ta--ok mdg-ta--warn mdg-ta--danger');

        if (len === 0) {
            $msg.text('');
        } else if (len < MIN) {
            $counter.addClass('mdg-counter--warn');
            $ta.addClass('mdg-ta--warn');
            $msg.text(MDG.strings.chars_short);
        } else if (len <= MAX) {
            $counter.addClass('mdg-counter--ok');
            $ta.addClass('mdg-ta--ok');
            // Extra nudge: warn if key content might get cut on mobile
            var mobileNote = len > 120 ? ' · first 120 chars safe for mobile' : '';
            $msg.text(MDG.strings.chars_ok + mobileNote);
        } else {
            $counter.addClass('mdg-counter--danger');
            $ta.addClass('mdg-ta--danger');
            $msg.text(MDG.strings.chars_long);
        }

        // Enable/disable the per-row Apply button based on whether we have text.
        $row.find('.mdg-apply-one').prop('disabled', len === 0);

        // Refresh the "Apply All" button state.
        updateApplyAllButton();
    }

    function updateApplyAllButton() {
        var anyReady = false;
        $('.mdg-generated-textarea').each(function () {
            if ($(this).val().trim().length > 0) { anyReady = true; return false; }
        });
        $('#mdg-apply-all').prop('disabled', !anyReady);
    }

    // -------------------------------------------------------------------------
    // Live character counter on textarea input
    // -------------------------------------------------------------------------

    $(document).on('input', '.mdg-generated-textarea', function () {
        updateCounter($(this));
    });

    // -------------------------------------------------------------------------
    // Generate one
    // -------------------------------------------------------------------------

    $(document).on('click', '.mdg-generate-one', function () {
        var $btn    = $(this);
        var $row    = $btn.closest('tr');
        var postId  = $btn.data('post-id');
        var $ta     = $row.find('.mdg-generated-textarea');
        var $status = $row.find('.mdg-row-status');

        if (!MDG.has_api_key) {
            alert(MDG.strings.no_api_key);
            return;
        }

        $btn.prop('disabled', true);
        $ta.prop('disabled', true);
        setStatus($status, MDG.strings.generating + spinner(), '');

        ajax('mdg_generate_one', { post_id: postId }, function (data) {
            $btn.prop('disabled', false);
            $ta.prop('disabled', false).val(data.description);
            updateCounter($ta);
            setStatus($status, '');
        }, function (err) {
            $btn.prop('disabled', false);
            $ta.prop('disabled', false);
            setStatus($status, MDG.strings.error + ': ' + err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Generate All Visible
    // -------------------------------------------------------------------------

    $('#mdg-generate-all').on('click', function () {
        var $btn      = $(this);
        var $rows     = $('#mdg-table tbody tr');
        var total     = $rows.length;
        var done      = 0;
        var $progress = $('#mdg-progress-wrap');
        var $fill     = $('#mdg-progress-fill');
        var $label    = $('#mdg-progress-label');
        var $status   = $('#mdg-bulk-status');

        if (!MDG.has_api_key) { alert(MDG.strings.no_api_key); return; }

        $btn.prop('disabled', true);
        $progress.show();
        setStatus($status, '');

        // Process rows sequentially to avoid hammering the API.
        function processNext(index) {
            if (index >= total) {
                $btn.prop('disabled', false);
                $fill.css('width', '100%');
                $label.text('Done — ' + done + ' generated.');
                updateApplyAllButton();
                return;
            }

            var $row   = $($rows[index]);
            var postId = $row.data('post-id');
            var $ta    = $row.find('.mdg-generated-textarea');
            var $st    = $row.find('.mdg-row-status');

            $label.text('Generating ' + (index + 1) + ' of ' + total + ' — ' + $row.find('strong').first().text());
            setStatus($st, spinner(), '');

            ajax('mdg_generate_one', { post_id: postId }, function (data) {
                $ta.val(data.description);
                updateCounter($ta);
                setStatus($st, '');
                done++;
                $fill.css('width', Math.round((index + 1) / total * 100) + '%');
                processNext(index + 1);
            }, function (err) {
                setStatus($st, MDG.strings.error + ': ' + err, 'error');
                $fill.css('width', Math.round((index + 1) / total * 100) + '%');
                processNext(index + 1); // continue despite error
            });
        }

        processNext(0);
    });

    // -------------------------------------------------------------------------
    // Apply one
    // -------------------------------------------------------------------------

    $(document).on('click', '.mdg-apply-one', function () {
        var $btn    = $(this);
        var $row    = $btn.closest('tr');
        var postId  = $btn.data('post-id');
        var $ta     = $row.find('.mdg-generated-textarea');
        var $status = $row.find('.mdg-row-status');
        var desc    = $.trim($ta.val());

        if (!desc) return;

        $btn.prop('disabled', true);
        setStatus($status, MDG.strings.applying + spinner(), '');

        ajax('mdg_apply_one', { post_id: postId, description: desc }, function () {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.applied, 'ok');
            // Update the "Current" cell to reflect the new value.
            var len  = desc.length;
            var cls  = (len >= MIN && len <= MAX) ? 'mdg-ok' : 'mdg-warn';
            $row.find('.mdg-current-cell').html(
                '<span class="mdg-existing-text">' + escHtml(desc) + '</span>' +
                '<span class="mdg-char-badge ' + cls + '">' + len + ' chars</span>'
            );
            $row.removeClass('mdg-row--missing mdg-row--warning');
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.error + ': ' + err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Apply All (bulk)
    // -------------------------------------------------------------------------

    $('#mdg-apply-all').on('click', function () {
        var $btn    = $(this);
        var $status = $('#mdg-bulk-status');
        var items   = [];

        $('.mdg-generated-textarea').each(function () {
            var desc = $.trim($(this).val());
            if (desc) {
                items.push({ post_id: $(this).data('post-id'), description: desc });
            }
        });

        if (!items.length) return;
        if (!confirm(MDG.strings.confirm_bulk)) return;

        $btn.prop('disabled', true);
        setStatus($status, MDG.strings.applying + spinner(), '');

        ajax('mdg_apply_bulk', { items: items }, function (data) {
            $btn.prop('disabled', false);
            var msg = data.saved + ' description' + (data.saved !== 1 ? 's' : '') + ' saved to Yoast.';
            if (data.failed) msg += ' ' + data.failed + ' failed.';
            setStatus($status, msg, 'ok');
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.error + ': ' + err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Check-all checkbox
    // -------------------------------------------------------------------------

    $('#mdg-check-all').on('change', function () {
        $('.mdg-row-check').prop('checked', this.checked);
    });

    // -------------------------------------------------------------------------
    // Settings: show/hide API key (also handled inline in settings.php)
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

}(jQuery));
