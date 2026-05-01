(function ($) {
    'use strict';

    var marketsData = [];

    function post(action, extra, cb) {
        $.post(IMS.ajaxUrl, Object.assign({ action: action, _ajax_nonce: IMS.nonce }, extra), cb, 'json');
    }

    function showResult(el, data, isSuccess) {
        el.removeClass('success error').show();
        if (isSuccess) {
            el.addClass('success');
        } else {
            el.addClass('error');
        }
        if (typeof data === 'string') {
            el.text(data);
        } else {
            el.html(data);
        }
    }

    function makeCopyBtn(text, label) {
        var btn = $('<button class="button ims-copy-btn">').text('Copy ' + (label || ''));
        btn.on('click', function () {
            navigator.clipboard.writeText(text).then(function () {
                btn.text('Copied!');
                setTimeout(function () { btn.text('Copy ' + (label || '')); }, 2000);
            });
        });
        return btn;
    }

    // ── Step 1: Create user ───────────────────────────────────────────────────
    $('#ims-btn-user').on('click', function () {
        var btn = $(this).prop('disabled', true).text('Creating user…');
        var result = $('#ims-user-result');

        post('ims_create_user', {}, function (resp) {
            btn.prop('disabled', false).text('Run Step 1');
            if (!resp.success) {
                showResult(result, '✗ ' + resp.data, false);
                return;
            }
            var d = resp.data;
            var html = $('<div>');
            html.append($('<p>').html('✓ ' + d.message + '<br><strong>Username:</strong> ' + d.username + '<br><strong>App Password:</strong> <code>' + d.app_password + '</code>'));
            html.append(makeCopyBtn(d.app_password, 'Password'));
            result.removeClass('success error').show().addClass('success').html('').append(html);
        });
    });

    // ── Step 2: Install snippets ──────────────────────────────────────────────
    $('#ims-btn-snippets').on('click', function () {
        var btn = $(this).prop('disabled', true).text('Installing…');
        var result = $('#ims-snippets-result');

        post('ims_install_snippets', {}, function (resp) {
            btn.prop('disabled', false).text('Run Step 2');
            if (!resp.success) {
                showResult(result, '✗ ' + resp.data, false);
                return;
            }
            var d = resp.data;
            var lines = [];
            if (d.installed.length) lines.push('✓ Installed: ' + d.installed.join(', '));
            if (d.skipped.length)   lines.push('↷ Already existed: ' + d.skipped.join(', '));
            if (d.missing.length)   lines.push('⚠ Skipped (code missing): ' + d.missing.join(', '));
            showResult(result, lines.join('\n'), !d.missing.length);
        });
    });

    // ── Step 3: Config ────────────────────────────────────────────────────────
    $('#ims-btn-config').on('click', function () {
        var btn = $(this).prop('disabled', true).text('Generating…');
        var result = $('#ims-config-result');

        post('ims_get_config', {}, function (resp) {
            btn.prop('disabled', false).text('Run Step 3');
            if (!resp.success) {
                showResult(result, '✗ ' + resp.data, false);
                return;
            }
            var d = resp.data;
            var html = $('<div>');
            html.append($('<p>').html('✓ Config generated for <strong>' + d.site_url + '</strong>'));
            html.append($('<textarea class="ims-output">').val(d.config));
            html.append($('<br>'));
            html.append(makeCopyBtn(d.config, 'Config JSON'));
            result.removeClass('success error').show().addClass('success').html('').append(html);
        });
    });

    // ── Step 4: Markets ───────────────────────────────────────────────────────
    $('#ims-btn-markets').on('click', function () {
        var btn = $(this).prop('disabled', true).text('Fetching…');
        var result = $('#ims-markets-result');

        post('ims_get_markets', {}, function (resp) {
            btn.prop('disabled', false).text('Run Step 4');
            if (!resp.success) {
                showResult(result, '✗ ' + resp.data, false);
                return;
            }
            marketsData = resp.data;
            var lines = marketsData.map(function (m) {
                return (m.id || 'NO_ID') + ' — ' + m.name + (m.url ? ' — ' + m.url : '');
            });
            var html = $('<div>');
            html.append($('<p>').html('✓ Found <strong>' + marketsData.length + '</strong> markets:'));
            html.append($('<textarea class="ims-output">').val(lines.join('\n')));
            result.removeClass('success error').show().addClass('success').html('').append(html);
            $('#ims-btn-claude-md').prop('disabled', false);
        });
    });

    // ── Step 5: CLAUDE.md ─────────────────────────────────────────────────────
    $('#ims-btn-claude-md').on('click', function () {
        var btn = $(this).prop('disabled', true).text('Generating…');
        var result = $('#ims-claude-md-result');

        post('ims_generate_claude_md', { markets: JSON.stringify(marketsData) }, function (resp) {
            btn.prop('disabled', false).text('Run Step 5');
            if (!resp.success) {
                showResult(result, '✗ ' + resp.data, false);
                return;
            }
            var md = resp.data.markdown;
            var html = $('<div>');
            html.append($('<p>').html('✓ CLAUDE.md generated — copy and upload to your Claude Desktop project:'));
            html.append($('<textarea class="ims-output">').val(md).css('min-height', '320px'));
            html.append($('<br>'));
            html.append(makeCopyBtn(md, 'CLAUDE.md'));
            result.removeClass('success error').show().addClass('success').html('').append(html);
        });
    });

}(jQuery));
