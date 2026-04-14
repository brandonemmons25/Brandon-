/**
 * Mobile CSS Auditor — Admin UI v1.3.0
 *
 * Scans all pages at 375px, collects deep element data + shadow DOM,
 * and exports a rich CSV for analysis and targeted CSS authoring.
 */
(function ($) {
    'use strict';

    var allReports   = {};
    var pageQueue    = [];
    var scanning     = false;
    var totalPages   = 0;
    var scannedCount = 0;

    /* ── Escape for HTML display ─────────────────────────────────── */
    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ── Page table ──────────────────────────────────────────────── */
    function initTable() {
        var html =
            '<table class="wp-list-table widefat fixed striped mca-table">' +
            '<thead><tr>' +
            '<th style="width:28%">Page</th>' +
            '<th>URL</th>' +
            '<th style="width:160px">Status</th>' +
            '<th style="width:70px">Scan</th>' +
            '</tr></thead><tbody>';

        MCA.pages.forEach(function (p) {
            html +=
                '<tr id="mca-row-' + p.id + '">' +
                '<td><strong>' + esc(p.title) + '</strong></td>' +
                '<td><a href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(p.url) + '</a></td>' +
                '<td class="mca-stat-cell"><span class="mca-badge mca-pending">Pending</span></td>' +
                '<td><button class="button button-small mca-scan-one"' +
                ' data-id="' + p.id + '" data-url="' + esc(p.url) + '" data-title="' + esc(p.title) + '">Scan</button></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#mca-table-wrap').html(html);
    }

    /* ── Scanning ────────────────────────────────────────────────── */
    function buildProbeUrl(url) {
        var sep = url.indexOf('?') > -1 ? '&' : '?';
        return url + sep + 'mca_probe=1&mca_nonce=' + encodeURIComponent(MCA.nonce);
    }

    function scanPage(page, cb) {
        setStatus(page.id, 'Scanning…', 'mca-scanning');

        var iframe = document.getElementById('mca-iframe');
        var done   = false;

        var timer = setTimeout(function () {
            if (done) return;
            done = true;
            window.removeEventListener('message', onMsg);
            setStatus(page.id, 'Timeout', 'mca-error');
            cb(null);
        }, 22000);

        function onMsg(evt) {
            if (!evt.data || evt.data.type !== 'MCA_REPORT') return;
            if (done) return;
            done = true;
            clearTimeout(timer);
            window.removeEventListener('message', onMsg);

            var rpt = evt.data.data;
            allReports[page.id] = rpt;
            scannedCount++;
            updateProgress();

            var n = rpt.elements.length + rpt.shadowRoots.length +
                    rpt.overflowCulprits.length + rpt.images.length;
            setStatus(page.id, n + ' items found', 'mca-done');
            appendSummaryRow(page, rpt);
            cb(rpt);
        }

        window.addEventListener('message', onMsg);
        iframe.src = buildProbeUrl(page.url);
    }

    function scanAll() {
        pageQueue    = MCA.pages.slice();
        totalPages   = pageQueue.length;
        scannedCount = 0;
        scanning     = true;
        $('#mca-progress-bar').show();
        updateProgress();
        nextScan();
    }

    function nextScan() {
        if (!pageQueue.length) {
            scanning = false;
            $('#mca-progress-text').text('Scan complete — copy data and paste into Claude');
            $('#mca-btn-csv').removeClass('mca-hidden');
            $('#mca-btn-view').removeClass('mca-hidden');
            return;
        }
        var page = pageQueue.shift();
        scanPage(page, function () { setTimeout(nextScan, 700); });
    }

    /* ── UI helpers ──────────────────────────────────────────────── */
    function setStatus(id, text, cls) {
        $('#mca-row-' + id + ' .mca-stat-cell')
            .html('<span class="mca-badge ' + cls + '">' + esc(text) + '</span>');
    }

    function updateProgress() {
        var pct = totalPages ? Math.round((scannedCount / totalPages) * 100) : 0;
        $('#mca-progress-fill').css('width', pct + '%');
        $('#mca-progress-text').text('Scanned ' + scannedCount + ' / ' + totalPages);
    }

    function appendSummaryRow(page, rpt) {
        var items = [];

        var abs   = rpt.elements.filter(function (e) {
            return (e.position === 'absolute' || e.position === 'fixed') && !e.isHidden;
        });
        var wide  = rpt.elements.filter(function (e) { return parseInt(e.width) > 768; });
        var highZ = rpt.elements.filter(function (e) {
            var z = parseInt(e.zIndex, 10); return !isNaN(z) && z > 100;
        });
        var noHam = rpt.navigation.filter(function (n) { return !n.hasHamburger; });

        if (abs.length)             items.push(abs.length + ' absolute/fixed elements');
        if (wide.length)            items.push(wide.length + ' elements wider than 768 px');
        if (highZ.length)           items.push(highZ.length + ' high z-index (>100)');
        if (rpt.shadowRoots.length) items.push(rpt.shadowRoots.length + ' shadow DOM root(s)');
        if (rpt.hasOverflow)        items.push('⚠ horizontal overflow — body scrollWidth ' + rpt.bodyScrollW + 'px');
        if (noHam.length)           items.push('⚠ no hamburger/mobile menu found');
        if (rpt.images.length)      items.push(rpt.images.length + ' image(s) without max-width');

        if (!items.length) return;
        $('#mca-row-' + page.id).after(
            '<tr class="mca-detail-row"><td colspan="4">' +
            '<ul class="mca-issue-list">' +
            items.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') +
            '</ul></td></tr>'
        );
    }

    /* ════════════════════════════════════════════════════════════
       CSV Export — rich columns for Claude analysis
    ════════════════════════════════════════════════════════════ */
    function csvCell(val) {
        var s = (val === null || val === undefined) ? '' : String(val).trim();
        if (s.search(/[,"\n\r]/) > -1) s = '"' + s.replace(/"/g, '""') + '"';
        return s;
    }

    function row(arr) { return arr.map(csvCell).join(','); }

    var HEADER = [
        /* Context */
        'Page Title', 'Page URL', 'Section',
        /* Element identity */
        'Selector', 'Full Path', 'Tag', 'ID', 'Classes', 'Parent Selector',
        /* Issue */
        'Issue Type', 'Issue Detail',
        /* Box model */
        'Position', 'Display', 'Width', 'Max-Width', 'Min-Width',
        'Height', 'Max-Height', 'Overflow', 'Overflow-X',
        /* Stacking */
        'Z-Index',
        /* Offsets */
        'Top', 'Left', 'Right', 'Bottom',
        /* Flex/Grid */
        'Flex-Direction', 'Flex-Wrap', 'Flex-Grow', 'Flex-Shrink', 'Flex-Basis',
        'Justify-Content', 'Align-Items',
        'Grid-Template-Columns', 'Grid-Column', 'Grid-Row',
        /* Float */
        'Float',
        /* Text */
        'Font-Size', 'Line-Height', 'Text-Align', 'White-Space',
        /* Color */
        'Color', 'Background-Color', 'Background-Image',
        /* Measured */
        'Offset-Width (px)', 'Offset-Height (px)', 'Scroll-Width (px)',
        /* Extra context */
        'Is Hidden', 'Child Count', 'Inline Style', 'Text Preview',
    ];

    function elRow(pageTitle, pageUrl, section, el, issueType, issueDetail) {
        return row([
            pageTitle, pageUrl, section,
            el.selector  || '', el.fullPath  || '', el.tag || '',
            el.id        || '', el.classes   || '', el.parent || '',
            issueType, issueDetail,
            el.position  || '', el.display   || '', el.width    || '', el.maxWidth  || '',
            el.minWidth  || '', el.height    || '', el.maxHeight || '',
            el.overflow  || '', el.overflowX || '',
            el.zIndex    || '',
            el.top  || '', el.left  || '', el.right  || '', el.bottom || '',
            el.flexDir   || '', el.flexWrap  || '', el.flexGrow  || '',
            el.flexShrink|| '', el.flexBasis || '',
            el.justifyContent || '', el.alignItems || '',
            el.gridTemplateCols || '', el.gridCol || '', el.gridRow || '',
            el.float     || '',
            el.fontSize  || '', el.lineHeight || '', el.textAlign || '', el.whiteSpace || '',
            el.color     || '', el.bgColor   || '', el.bgImage   || '',
            el.offsetW != null ? el.offsetW : '',
            el.offsetH != null ? el.offsetH : '',
            el.scrollW != null ? el.scrollW : '',
            el.isHidden ? 'yes' : 'no',
            el.childCount != null ? el.childCount : '',
            el.inlineStyle || '', el.textPreview || '',
        ]);
    }

    function buildCSV() {
        var rows = [row(HEADER)];

        Object.keys(allReports).forEach(function (id) {
            var r = allReports[id];
            var title = r.title;
            var url   = r.url;

            /* ── Page summary row ── */
            rows.push(row([
                title, url, 'PAGE SUMMARY',
                '', '', '', '', '', '',
                'page-info',
                'viewport=' + r.viewport + 'px | bodyScrollW=' + r.bodyScrollW +
                'px | hasOverflow=' + r.hasOverflow + ' | docHeight=' + r.docHeight + 'px',
                '', '', '', '', '', '', '', '', '', '',
                '', '', '', '',
                '', '', '', '', '', '', '', '', '', '',
                '', '', '', '', '',
                '', '', '', '',
                '', '', '',
                '', '', '',
            ]));

            /* ── Elements ── */
            r.elements.forEach(function (el) {
                var issues = [];

                if ((el.position === 'absolute' || el.position === 'fixed') && !el.isHidden) {
                    issues.push({ type: 'absolute-position', detail: 'position:' + el.position + ' top:' + el.top + ' left:' + el.left });
                }
                if (parseInt(el.width, 10) > 768 && !el.isHidden) {
                    issues.push({ type: 'wide-element', detail: 'width:' + el.width + ' maxWidth:' + el.maxWidth + ' scrollW:' + el.scrollW + 'px' });
                }
                var z = parseInt(el.zIndex, 10);
                if (!isNaN(z) && z > 100) {
                    issues.push({ type: 'high-z-index', detail: 'z-index:' + el.zIndex + ' position:' + el.position });
                }
                if ((el.overflow === 'hidden' || el.overflowX === 'hidden') && !el.isHidden) {
                    issues.push({ type: 'overflow-hidden', detail: 'overflow:' + el.overflow + ' overflowX:' + el.overflowX });
                }
                if (parseFloat(el.fontSize) < 12 && parseFloat(el.fontSize) > 0) {
                    issues.push({ type: 'small-font', detail: 'font-size:' + el.fontSize });
                }
                if ((el.display === 'flex' || el.display === 'grid') && !el.isHidden) {
                    issues.push({ type: 'flex-grid-container', detail: 'display:' + el.display + ' flexDir:' + el.flexDir + ' gridCols:' + el.gridTemplateCols });
                }
                if (el.float && el.float !== 'none') {
                    issues.push({ type: 'float', detail: 'float:' + el.float });
                }
                if (el.bgImage) {
                    issues.push({ type: 'background-image', detail: el.bgImage });
                }
                if (el.inlineStyle) {
                    issues.push({ type: 'inline-style', detail: el.inlineStyle });
                }

                if (issues.length) {
                    issues.forEach(function (issue) {
                        rows.push(elRow(title, url, 'ELEMENT', el, issue.type, issue.detail));
                    });
                } else {
                    /* Still include the element — useful for Claude to see full picture */
                    rows.push(elRow(title, url, 'ELEMENT', el, 'no-issue', ''));
                }
            });

            /* ── Shadow DOM ── */
            r.shadowRoots.forEach(function (sr) {
                /* One header row for the shadow host */
                rows.push(row([
                    title, url, 'SHADOW-DOM',
                    sr.host, sr.hostFull || '', 'shadow-host', '', '', '',
                    'shadow-root',
                    (sr.data.classes ? sr.data.classes.length : 0) + ' classes | ' +
                    (sr.data.ids     ? sr.data.ids.length     : 0) + ' IDs | ' +
                    (sr.data.elements? sr.data.elements.length: 0) + ' notable elements',
                    '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '',
                ]));

                /* All class names found */
                if (sr.data && sr.data.classes && sr.data.classes.length) {
                    rows.push(row([
                        title, url, 'SHADOW-CLASSES',
                        sr.host, '', '', '', sr.data.classes.join(' '), '',
                        'shadow-class-list', sr.data.classes.length + ' unique classes',
                        '', '', '', '', '', '', '', '', '', '', '', '', '',
                        '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                        '', '', '', '', '', '', '', '', '',
                    ]));
                }

                /* Each notable element inside the shadow root */
                if (sr.data && sr.data.elements) {
                    sr.data.elements.forEach(function (se) {
                        var issues = [];
                        if (se.isWide)     issues.push('wide:' + se.width);
                        if (se.isAbsolute) issues.push('position:' + se.position);
                        if (se.isFlex)     issues.push('flex flexDir:' + se.flexDir);
                        if (se.isGrid)     issues.push('grid');
                        if (se.isHidden)   issues.push('hidden');

                        rows.push(row([
                            title, url, 'SHADOW-ELEMENT',
                            (se.id ? '#' + se.id : '') || ('.' + (se.classes || '').split(' ')[0]),
                            '', se.tag, se.id, se.classes, sr.host,
                            'shadow-element',
                            issues.join(' | '),
                            se.position, se.display, se.width, se.maxWidth,
                            '', se.height, '',
                            se.overflow, '',
                            se.zIndex,
                            '', '', '', '',
                            se.flexDir, se.flexWrap, '', '', '',
                            se.justifyContent, se.alignItems,
                            '', '', '',
                            '',
                            se.fontSize, '', '', '',
                            se.color, se.bgColor, '',
                            se.offsetW, se.offsetH, '',
                            se.isHidden ? 'yes' : 'no', '',
                            se.inlineStyle, '',
                        ]));
                    });
                }
            });

            /* ── Overflow culprits ── */
            r.overflowCulprits.forEach(function (c) {
                rows.push(row([
                    title, url, 'OVERFLOW',
                    c.selector, c.fullPath || '', '', '', '', '',
                    'horizontal-overflow',
                    'right=' + c.right + 'px | viewport=' + c.viewport + 'px | excess=+' + c.excess + 'px | left=' + c.left + 'px | width=' + c.width + 'px',
                    c.position, c.display, '', c.maxWidth,
                    '', '', '', '', '',
                    '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '',
                    '', '', c.inlineStyle, '',
                ]));
            });

            /* ── Images ── */
            r.images.forEach(function (img) {
                rows.push(row([
                    title, url, 'IMAGE',
                    img.selector, img.fullPath || '', 'img', '', '', '',
                    'image-no-max-width',
                    'src=' + img.src + ' | naturalW=' + img.naturalW + 'px | naturalH=' + img.naturalH + 'px | alt=' + img.alt,
                    '', img.display, img.width, img.maxWidth,
                    '', '', '', '', '',
                    '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '',
                    img.offsetW, img.offsetH, '',
                    '', '', '', '',
                ]));
            });

            /* ── Navigation ── */
            r.navigation.forEach(function (nav) {
                rows.push(row([
                    title, url, 'NAVIGATION',
                    nav.selector, '', '', '', '', '',
                    nav.hasHamburger ? 'nav-has-hamburger' : 'nav-no-hamburger',
                    'items=' + nav.itemCount + ' | topLevel=' + nav.topLevelItems + ' | subMenus=' + nav.subMenuCount + ' | hidden=' + nav.isHidden,
                    nav.position, nav.display, nav.width, '',
                    '', '', '', '', '',
                    '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '',
                    nav.isHidden ? 'yes' : 'no', '', '', '',
                ]));
            });

            /* ── Layout structure ── */
            if (r.layout) {
                var L = r.layout;

                /* DOM-order section rows */
                if (L.sections) {
                    L.sections.forEach(function (s) {
                        rows.push(row([
                            title, url, 'LAYOUT-SECTION',
                            s.selector || '', s.fullPath || '', s.tagId || '', '', s.classes || '', '',
                            s.isHidden ? 'section-hidden' : 'section-visible',
                            'depth=' + s.depth +
                            ' | display=' + (s.display || 'n/a') +
                            ' | visibility=' + (s.visibility || 'n/a') +
                            ' | opacity=' + (s.opacity || 'n/a') +
                            ' | offsetH=' + (s.offsetH != null ? s.offsetH + 'px' : 'n/a') +
                            ' | offsetW=' + (s.offsetW != null ? s.offsetW + 'px' : 'n/a'),
                            s.position || '', s.display || '', '', '', '', '', '', '', '', '',
                            '', '', '', '',
                            '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                            '', '', '', '', '', '', '', '',
                            s.offsetW != null ? s.offsetW : '',
                            s.offsetH != null ? s.offsetH : '', '',
                            s.isHidden ? 'yes' : 'no', '', '', '',
                        ]));
                    });
                }

                /* Meta: body classes + stylesheets */
                rows.push(row([
                    title, url, 'LAYOUT-META',
                    '', '', '', '', '', '',
                    'layout-meta',
                    'bodyClasses=' + (L.bodyClasses || '').substring(0, 120) +
                    ' | stylesheets=' + (L.stylesheets || []).join(' '),
                    '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '',
                    '', '', '', '',
                ]));

                /* Header HTML snapshot */
                if (L.headerHTML) {
                    rows.push(row([
                        title, url, 'LAYOUT-HEADER-HTML',
                        '#header', '', '', '', '', '',
                        'header-html',
                        L.headerHTML,
                        '', '', '', '', '', '', '', '', '', '', '', '', '',
                        '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                        '', '', '', '', '', '', '', '', '',
                        '', '', '', '',
                    ]));
                }
            }

            /* ── Top class names on page ── */
            if (r.allClasses && r.allClasses.length) {
                var topClasses = r.allClasses.slice(0, 40).map(function (c) {
                    return c.name + '(' + c.count + ')';
                }).join('  ');
                rows.push(row([
                    title, url, 'ALL-CLASSES',
                    '', '', '', '', '', '',
                    'page-class-inventory', topClasses,
                    '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                    '', '', '', '', '', '', '', '', '',
                    '', '', '', '',
                ]));
            }
        });

        return rows.join('\n');
    }

    /* ── View / Copy in browser (no file download needed) ───────── */
    function showViewPanel() {
        var csv = buildCSV();
        $('#mca-data-out').val(csv);
        $('#mca-view-wrap').removeClass('mca-hidden');
        $('html,body').animate({ scrollTop: $('#mca-view-wrap').offset().top - 20 }, 500);
    }

    function downloadCSV() {
        var csv      = buildCSV();
        var blob     = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        var url      = URL.createObjectURL(blob);
        var filename = 'mobile-audit-' + new Date().toISOString().slice(0, 10) + '.csv';
        var a        = document.createElement('a');
        a.href       = url;
        a.download   = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    /* ════════════════════════════════════════════════════════════
       Init
    ════════════════════════════════════════════════════════════ */
    $(document).ready(function () {
        initTable();

        /* Scan all */
        $('#mca-btn-scan-all').on('click', function () {
            if (scanning) return;
            allReports   = {};
            scannedCount = 0;
            $('#mca-btn-csv').addClass('mca-hidden');
            initTable();
            scanAll();
        });

        /* Scan single */
        $(document).on('click', '.mca-scan-one', function () {
            var $b = $(this);
            scanPage(
                { id: $b.data('id'), url: $b.data('url'), title: $b.data('title') },
                function () {
                    if (Object.keys(allReports).length) {
                        $('#mca-btn-csv').removeClass('mca-hidden');
                        $('#mca-btn-view').removeClass('mca-hidden');
                    }
                }
            );
        });

        /* Download CSV */
        $('#mca-btn-csv').on('click', downloadCSV);

        /* View / Copy data in browser */
        $('#mca-btn-view').on('click', showViewPanel);

        /* Select & copy all text in the data textarea */
        $('#mca-btn-copy-data').on('click', function () {
            var ta = document.getElementById('mca-data-out');
            ta.select();
            ta.setSelectionRange(0, 999999999);
            try {
                document.execCommand('copy');
                $(this).text('Copied! Now paste into Claude chat');
                var $btn = $(this);
                setTimeout(function () { $btn.html('&#128203;&nbsp;Select &amp; Copy All'); }, 4000);
            } catch (e) {
                $(this).text('Press Ctrl+A then Ctrl+C in the box below');
            }
        });

        /* Clear */
        $('#mca-btn-clear').on('click', function () {
            allReports   = {};
            scannedCount = 0;
            scanning     = false;
            initTable();
            $('#mca-btn-csv').addClass('mca-hidden');
            $('#mca-btn-view').addClass('mca-hidden');
            $('#mca-view-wrap').addClass('mca-hidden');
            $('#mca-progress-text').text('');
            $('#mca-progress-fill').css('width', '0%');
            document.getElementById('mca-iframe').src = 'about:blank';
        });
    });

})(jQuery);
