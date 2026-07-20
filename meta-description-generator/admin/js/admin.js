/* Meta Description Generator — Admin JS */
/* global MDG, jQuery */

(function ($) {
    'use strict';

    var MIN = MDG.min;  // 140
    var MAX = MDG.max;  // 156

    // Anthropic's org rate limit can be as low as 5 requests/minute. Each
    // "Generate" now makes 2 Claude calls per row (draft + quality review,
    // occasionally 3 if a too-short draft needs a retry too), not 1 — so
    // the per-row pace has to roughly double to stay under the limit.
    var API_PACE_MS = 26000;

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

    function chunk(arr, size) {
        var out = [];
        for (var i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
        return out;
    }

    /**
     * Run a bulk AJAX action over a large list in small sequential batches
     * instead of one request. A single request looping over dozens of
     * Yoast indexable saves can be slow enough on some hosts to hit the
     * PHP execution time limit mid-loop, silently leaving the tail of the
     * list untouched — batching keeps each request small and safe.
     */
    function runInBatches(items, batchSize, action, buildData, onProgress, onDone, onError) {
        var batches = chunk(items, batchSize);
        var doneCount = 0;
        var total = items.length;

        function next(i) {
            if (i >= batches.length) { onDone(doneCount, total); return; }
            ajax(action, buildData(batches[i]), function (data) {
                doneCount += batches[i].length;
                onProgress(doneCount, total, data, batches[i]);
                next(i + 1);
            }, function (err) {
                onError(err, batches[i]);
            });
        }

        next(0);
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
            $ta.prop('disabled', false).val(data.description);
            updateCounter($ta);

            // Generating is now a one-click action — apply immediately
            // rather than leaving the draft sitting unreviewed.
            setStatus($status, MDG.strings.applying + spinner(), '');
            applyRow($row, postId, data.description, function () {
                $btn.prop('disabled', false);
                setStatus($status, MDG.strings.applied, 'ok');
            }, function (err) {
                $btn.prop('disabled', false);
                setStatus($status, MDG.strings.error + ': ' + err, 'error');
            });
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
                $label.text('Done — ' + done + ' generated and saved to Yoast.');
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

                // One-click generation applies immediately rather than
                // leaving every row's draft waiting on a separate Apply All.
                applyRow($row, postId, data.description, function () {
                    setStatus($st, MDG.strings.applied, 'ok');
                    done++;
                    $fill.css('width', Math.round((index + 1) / total * 100) + '%');
                    waitThenProcessNext(index + 1);
                }, function (err) {
                    setStatus($st, MDG.strings.error + ': ' + err, 'error');
                    $fill.css('width', Math.round((index + 1) / total * 100) + '%');
                    waitThenProcessNext(index + 1); // continue despite error
                });
            }, function (err) {
                setStatus($st, MDG.strings.error + ': ' + err, 'error');
                $fill.css('width', Math.round((index + 1) / total * 100) + '%');
                waitThenProcessNext(index + 1); // continue despite error
            });
        }

        // Pace calls to stay under the Claude API rate limit, with a visible countdown.
        function waitThenProcessNext(nextIndex) {
            if (nextIndex >= total) { processNext(nextIndex); return; }

            var secondsLeft = Math.ceil(API_PACE_MS / 1000);
            var tick = setInterval(function () {
                secondsLeft--;
                if (secondsLeft > 0) {
                    $label.text('Waiting ' + secondsLeft + 's to respect the Claude API rate limit…');
                }
            }, 1000);

            setTimeout(function () {
                clearInterval(tick);
                processNext(nextIndex);
            }, API_PACE_MS);
        }

        processNext(0);
    });

    // -------------------------------------------------------------------------
    // Apply one
    // -------------------------------------------------------------------------

    /**
     * Apply one row's description to Yoast and update its "Current" cell.
     * Shared by the manual Apply button and auto-apply-after-generate.
     */
    function applyRow($row, postId, desc, onDone, onError) {
        ajax('mdg_apply_one', { post_id: postId, description: desc }, function () {
            var len = desc.length;
            var cls = (len >= MIN && len <= MAX) ? 'mdg-ok' : 'mdg-warn';
            $row.find('.mdg-current-cell').html(
                '<span class="mdg-existing-text">' + escHtml(desc) + '</span>' +
                '<span class="mdg-char-badge ' + cls + '">' + len + ' chars</span>'
            );
            $row.removeClass('mdg-row--missing mdg-row--warning');
            // A Clear button may not exist yet if this row had no description on page load.
            if (!$row.find('.mdg-clear-one').length) {
                $row.find('.mdg-apply-one').after(
                    ' <button class="button button-small mdg-clear-one" data-post-id="' + postId + '">Clear</button>'
                );
            }
            onDone();
        }, onError);
    }

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

        applyRow($row, postId, desc, function () {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.applied, 'ok');
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.error + ': ' + err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Clear one — wipe the current Yoast meta description so it can be
    // regenerated from scratch (and shows up under "Missing" again).
    // -------------------------------------------------------------------------

    $(document).on('click', '.mdg-clear-one', function () {
        var $btn    = $(this);
        var $row    = $btn.closest('tr');
        var postId  = $btn.data('post-id');
        var $status = $row.find('.mdg-row-status');

        if (!confirm(MDG.strings.confirm_clear_one)) return;

        $btn.prop('disabled', true);
        setStatus($status, MDG.strings.clearing + spinner(), '');

        ajax('mdg_clear_one', { post_id: postId }, function () {
            setStatus($status, MDG.strings.cleared, 'ok');
            $row.find('.mdg-current-cell').html(
                '<span class="mdg-badge mdg-badge--missing">None</span>'
            );
            $row.addClass('mdg-row--missing').removeClass('mdg-row--warning');
            $btn.remove();
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.error + ': ' + err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Clear Selected (bulk)
    // -------------------------------------------------------------------------

    // Small enough that a batch of Yoast indexable saves can't run into a
    // PHP execution time limit on slower hosts, even with many other
    // plugins hooking into postmeta updates.
    var CLEAR_BATCH_SIZE = 15;

    $('#mdg-clear-selected').on('click', function () {
        var $btn    = $(this);
        var $status = $('#mdg-bulk-status');
        var $rows   = $('.mdg-row-check:checked').closest('tr');
        var ids     = $rows.map(function () { return $(this).data('post-id'); }).get();
        var rowsById = {};
        $rows.each(function () { rowsById[$(this).data('post-id')] = $(this); });

        if (!ids.length) { alert(MDG.strings.no_selection); return; }
        if (!confirm(MDG.strings.confirm_clear_bulk.replace('%d', ids.length))) return;

        $btn.prop('disabled', true);
        setStatus($status, MDG.strings.clearing + spinner(), '');

        runInBatches(ids, CLEAR_BATCH_SIZE, 'mdg_clear_bulk',
            function (batch) { return { post_ids: batch }; },
            function (done, total, data, batch) {
                setStatus($status, MDG.strings.clearing + ' ' + done + '/' + total + spinner(), '');
                batch.forEach(function (id) {
                    var $row = rowsById[id];
                    if (!$row) return;
                    $row.find('.mdg-current-cell').html('<span class="mdg-badge mdg-badge--missing">None</span>');
                    $row.addClass('mdg-row--missing').removeClass('mdg-row--warning');
                    $row.find('.mdg-clear-one').remove();
                });
            },
            function (done, total) {
                $btn.prop('disabled', false);
                setStatus($status, done + ' description' + (done !== 1 ? 's' : '') + ' cleared.', 'ok');
            },
            function (err) {
                $btn.prop('disabled', false);
                setStatus($status, MDG.strings.error + ': ' + err, 'error');
            }
        );
    });

    // -------------------------------------------------------------------------
    // Clear All Matching Filter — ignores pagination. Fetches every post ID
    // the current status/post_type/search filter matches (cheap, read-only),
    // then clears them in small batches and reloads so the table reflects
    // reality (rows can move between "missing" and "has").
    // -------------------------------------------------------------------------

    $('#mdg-clear-all-matching').on('click', function () {
        var $btn    = $(this);
        var $status = $('#mdg-bulk-status');
        var total   = parseInt($btn.data('total'), 10) || 0;

        if (!total) return;
        if (!confirm(MDG.strings.confirm_clear_all.replace('%d', total))) return;

        $btn.prop('disabled', true);
        setStatus($status, MDG.strings.clearing + spinner(), '');

        ajax('mdg_get_matching_ids', {
            status: $btn.data('status'),
            post_type: $btn.data('post-type'),
            search: $btn.data('search')
        }, function (data) {
            var ids = data.ids || [];
            if (!ids.length) { $btn.prop('disabled', false); setStatus($status, '', ''); return; }

            runInBatches(ids, CLEAR_BATCH_SIZE, 'mdg_clear_bulk',
                function (batch) { return { post_ids: batch }; },
                function (done, totalIds) {
                    setStatus($status, MDG.strings.clearing + ' ' + done + '/' + totalIds + spinner(), '');
                },
                function (done) {
                    setStatus($status, done + ' description' + (done !== 1 ? 's' : '') + ' cleared. Reloading…', 'ok');
                    location.reload();
                },
                function (err) {
                    $btn.prop('disabled', false);
                    setStatus($status, MDG.strings.error + ': ' + err, 'error');
                }
            );
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.error + ': ' + err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Clear SEO Titles (Matching Filter) — same pattern as Clear All Matching
    // Filter, but clears the Yoast SEO title instead of the description, so
    // Yoast's own title template ("Page Title | Site Title") takes over.
    // -------------------------------------------------------------------------

    $('#mdg-clear-titles-matching').on('click', function () {
        var $btn    = $(this);
        var $status = $('#mdg-bulk-status');
        var total   = parseInt($btn.data('total'), 10) || 0;

        if (!total) return;
        if (!confirm(MDG.strings.confirm_clear_titles.replace('%d', total))) return;

        $btn.prop('disabled', true);
        setStatus($status, MDG.strings.clearing + spinner(), '');

        ajax('mdg_get_matching_ids', {
            status: $btn.data('status'),
            post_type: $btn.data('post-type'),
            search: $btn.data('search')
        }, function (data) {
            var ids = data.ids || [];
            if (!ids.length) { $btn.prop('disabled', false); setStatus($status, '', ''); return; }

            runInBatches(ids, CLEAR_BATCH_SIZE, 'mdg_clear_titles_bulk',
                function (batch) { return { post_ids: batch }; },
                function (done, totalIds) {
                    setStatus($status, MDG.strings.clearing + ' ' + done + '/' + totalIds + spinner(), '');
                },
                function (done) {
                    setStatus($status, done + ' SEO title' + (done !== 1 ? 's' : '') + ' cleared.', 'ok');
                    $btn.prop('disabled', false);
                },
                function (err) {
                    $btn.prop('disabled', false);
                    setStatus($status, MDG.strings.error + ': ' + err, 'error');
                }
            );
        }, function (err) {
            $btn.prop('disabled', false);
            setStatus($status, MDG.strings.error + ': ' + err, 'error');
        });
    });

    // -------------------------------------------------------------------------
    // Enable/disable "Clear Selected" based on checkbox state
    // -------------------------------------------------------------------------

    function updateClearSelectedButton() {
        $('#mdg-clear-selected').prop('disabled', $('.mdg-row-check:checked').length === 0);
    }

    $(document).on('change', '.mdg-row-check, #mdg-check-all', updateClearSelectedButton);

    // -------------------------------------------------------------------------
    // Apply All (bulk)
    // -------------------------------------------------------------------------

    $('#mdg-apply-all').on('click', function () {
        var $btn     = $(this);
        var $status  = $('#mdg-bulk-status');
        var items    = [];
        var rowsById = {};

        $('.mdg-generated-textarea').each(function () {
            var desc   = $.trim($(this).val());
            var postId = $(this).data('post-id');
            rowsById[postId] = $(this).closest('tr');
            if (desc) {
                items.push({ post_id: postId, description: desc });
            }
        });

        if (!items.length) return;
        if (!confirm(MDG.strings.confirm_bulk)) return;

        $btn.prop('disabled', true);
        setStatus($status, MDG.strings.applying + spinner(), '');

        var saved = 0, failed = 0;
        runInBatches(items, CLEAR_BATCH_SIZE, 'mdg_apply_bulk',
            function (batch) { return { items: batch }; },
            function (done, total, data, batch) {
                saved  += data.saved;
                failed += data.failed;
                setStatus($status, MDG.strings.applying + ' ' + done + '/' + total + spinner(), '');

                // Flip each applied row's "Current Yoast Description" from
                // the red "None" badge to the saved text with a green
                // character-count badge, instead of leaving it stale.
                batch.forEach(function (item) {
                    var $row = rowsById[item.post_id];
                    if (!$row) return;
                    var len = item.description.length;
                    var cls = (len >= MIN && len <= MAX) ? 'mdg-ok' : 'mdg-warn';
                    $row.find('.mdg-current-cell').html(
                        '<span class="mdg-existing-text">' + escHtml(item.description) + '</span>' +
                        '<span class="mdg-char-badge ' + cls + '">' + len + ' chars</span>'
                    );
                    $row.removeClass('mdg-row--missing mdg-row--warning');
                    if (!$row.find('.mdg-clear-one').length) {
                        $row.find('.mdg-apply-one').after(
                            ' <button class="button button-small mdg-clear-one" data-post-id="' + item.post_id + '">Clear</button>'
                        );
                    }
                });
            },
            function () {
                $btn.prop('disabled', false);
                var msg = saved + ' description' + (saved !== 1 ? 's' : '') + ' saved to Yoast.';
                if (failed) msg += ' ' + failed + ' failed.';
                setStatus($status, msg, 'ok');
            },
            function (err) {
                $btn.prop('disabled', false);
                setStatus($status, MDG.strings.error + ': ' + err, 'error');
            }
        );
    });

    // -------------------------------------------------------------------------
    // Auto-fill queue processor (runs on every admin page load while active)
    // -------------------------------------------------------------------------

    if (MDG.auto_run) {
        runAutoFill();
    }

    function runAutoFill() {
        var total    = MDG.auto_total;
        var $notice  = $('#mdg-auto-notice');
        var $bar     = $('#mdg-auto-bar');
        var $text    = $('#mdg-auto-progress-text');

        function processNext() {
            ajax('mdg_auto_fill_next', {}, function (data) {
                var pct = total ? Math.round(data.processed / total * 100) : 100;
                $bar.css('width', pct + '%');
                if ($text.length) $text.text(data.processed + ' of ' + total);

                if (data.done) {
                    if ($notice.length) {
                        $notice.removeClass('notice-info').addClass('notice-success');
                        $notice.find('p').html('<strong>' + MDG.strings.auto_done + '</strong> ' + data.processed + ' posts/pages processed.');
                        $notice.find('div').hide();
                    }
                } else {
                    // Pace calls to stay under the Claude API rate limit.
                    setTimeout(processNext, API_PACE_MS);
                }
            }, function () {
                // On error (often a rate limit), back off longer than the
                // normal pace before retrying.
                setTimeout(processNext, API_PACE_MS * 2);
            });
        }

        processNext();
    }

    // -------------------------------------------------------------------------
    // Check-all checkbox
    // -------------------------------------------------------------------------

    $('#mdg-check-all').on('change', function () {
        $('.mdg-row-check').prop('checked', this.checked);
    });

    // -------------------------------------------------------------------------
    // Auto-apply the status/type dropdowns instead of requiring a separate
    // click on "Filter" — selecting a new value alone did nothing, which
    // left the bulk action buttons looking stuck/disabled against stale results.
    // -------------------------------------------------------------------------

    $('#mdg-filter-status, #mdg-filter-type').on('change', function () {
        $('#mdg-filter-form').trigger('submit');
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
