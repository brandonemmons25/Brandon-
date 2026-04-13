/**
 * Mobile CSS Auditor — Admin UI v1.0.0
 *
 * Manages the scan queue, receives postMessage reports from the probe,
 * and generates copy-ready CSS for Customizer + iHF Admin.
 */
(function ($) {
    'use strict';

    var allReports   = {};   // pageId → report
    var pageQueue    = [];
    var scanning     = false;
    var totalPages   = 0;
    var scannedCount = 0;

    /* ════════════════════════════════════════════════════════════
       Escape helper
    ════════════════════════════════════════════════════════════ */
    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ════════════════════════════════════════════════════════════
       Page table
    ════════════════════════════════════════════════════════════ */
    function initTable() {
        var html =
            '<table class="wp-list-table widefat fixed striped mca-table">' +
            '<thead><tr>' +
            '<th style="width:30%">Page</th>' +
            '<th>URL</th>' +
            '<th style="width:150px">Status</th>' +
            '<th style="width:70px">Scan</th>' +
            '</tr></thead><tbody>';

        MCA.pages.forEach(function (p) {
            html +=
                '<tr id="mca-row-' + p.id + '">' +
                '<td><strong>' + esc(p.title) + '</strong></td>' +
                '<td><a href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(p.url) + '</a></td>' +
                '<td class="mca-stat-cell"><span class="mca-badge mca-pending">Pending</span></td>' +
                '<td>' +
                '<button class="button button-small mca-scan-one"' +
                ' data-id="' + p.id + '"' +
                ' data-url="' + esc(p.url) + '"' +
                ' data-title="' + esc(p.title) + '">Scan</button>' +
                '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#mca-table-wrap').html(html);
    }

    /* ════════════════════════════════════════════════════════════
       Scanning
    ════════════════════════════════════════════════════════════ */
    function buildProbeUrl(pageUrl) {
        var sep = pageUrl.indexOf('?') > -1 ? '&' : '?';
        return pageUrl + sep + 'mca_probe=1&mca_nonce=' + encodeURIComponent(MCA.nonce);
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
            /* Ignore messages that aren't our probe report */
            if (!evt.data || evt.data.type !== 'MCA_REPORT') return;
            if (done) return;
            done = true;
            clearTimeout(timer);
            window.removeEventListener('message', onMsg);

            var rpt = evt.data.data;
            allReports[page.id] = rpt;
            scannedCount++;
            updateProgress();

            var n = rpt.elements.length + rpt.shadowRoots.length;
            setStatus(page.id, n + ' items', 'mca-done');
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
            $('#mca-progress-text').text('All pages scanned — click Generate CSS');
            $('#mca-btn-generate').removeClass('mca-hidden');
            return;
        }
        var page = pageQueue.shift();
        scanPage(page, function () { setTimeout(nextScan, 700); });
    }

    /* ════════════════════════════════════════════════════════════
       UI helpers
    ════════════════════════════════════════════════════════════ */
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
            return e.position === 'absolute' || e.position === 'fixed';
        });
        var wide  = rpt.elements.filter(function (e) { return parseInt(e.width) > 768; });
        var highZ = rpt.elements.filter(function (e) {
            var z = parseInt(e.zIndex, 10);
            return !isNaN(z) && z > 100;
        });
        var noHam = rpt.navigation.filter(function (n) { return !n.hasHamburger; });

        if (abs.length)             items.push(abs.length + ' absolute/fixed positioned elements');
        if (wide.length)            items.push(wide.length + ' elements wider than 768 px');
        if (highZ.length)           items.push(highZ.length + ' high z-index stacks (>100)');
        if (rpt.shadowRoots.length) items.push(rpt.shadowRoots.length + ' shadow DOM root(s) — iHF detected');
        if (rpt.hasOverflow)        items.push('⚠ horizontal overflow detected (' + rpt.bodyScrollW + 'px body vs ' + rpt.windowW + 'px viewport)');
        if (noHam.length)           items.push('⚠ no hamburger/mobile menu toggle found');
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
       Aggregate all reports
    ════════════════════════════════════════════════════════════ */
    function aggregate() {
        var d = {
            absoluteEls:  {},
            wideEls:      {},
            highZEls:     {},
            overflowEls:  {},
            shadowRoots:  {},
            ihfClasses:   [],
            ihfElements:  [],
            navHasHam:    true,
            hasOverflow:  false,
            overflowCulprits: [],
        };

        Object.keys(allReports).forEach(function (id) {
            var r = allReports[id];

            r.elements.forEach(function (el) {
                var s = el.selector;

                if (el.position === 'absolute' || el.position === 'fixed') {
                    d.absoluteEls[s] = el;
                }
                if (parseInt(el.width, 10) > 768) {
                    d.wideEls[s] = el;
                }
                var z = parseInt(el.zIndex, 10);
                if (!isNaN(z) && z > 100) {
                    d.highZEls[s] = el;
                }
                if (el.overflow === 'hidden' || el.overflowX === 'hidden') {
                    d.overflowEls[s] = el;
                }
            });

            r.shadowRoots.forEach(function (sr) {
                d.shadowRoots[sr.host] = sr.data;

                if (sr.data && sr.data.classes) {
                    sr.data.classes.forEach(function (c) {
                        if (d.ihfClasses.indexOf(c) === -1) d.ihfClasses.push(c);
                    });
                }
                if (sr.data && sr.data.elements) {
                    sr.data.elements.forEach(function (el) {
                        d.ihfElements.push(el);
                    });
                }
            });

            r.navigation.forEach(function (nav) {
                if (!nav.hasHamburger) d.navHasHam = false;
            });

            if (r.hasOverflow) {
                d.hasOverflow = true;
                r.overflowCulprits.forEach(function (c) {
                    d.overflowCulprits.push(c);
                });
            }
        });

        return d;
    }

    /* ════════════════════════════════════════════════════════════
       CSS generation — Customizer
    ════════════════════════════════════════════════════════════ */
    function buildCustomizerCSS(d) {
        var lines = [];

        var KNOWN = [
            '#slideshow', '.hp-slideshow', '#quick-search', '#hp-content',
            '.ihf-container', 'body', 'html', '#header', '#footer',
            'footer', 'header', 'ul.fc',
        ];

        function isKnown(sel) {
            return KNOWN.some(function (k) { return sel.indexOf(k) > -1; });
        }

        lines.push('/* ============================================================');
        lines.push('   WORDPRESS CUSTOMIZER — ADDITIONAL CSS');
        lines.push('   Generated by Mobile CSS Auditor v1.0.0');
        lines.push('   ============================================================ */');
        lines.push('');
        lines.push('/* ── Global (applies at all widths) ── */');
        lines.push('#slideshow, .hp-slideshow { background-color: transparent !important; }');
        lines.push('#header { overflow-x: hidden !important; max-width: 100vw !important; }');
        lines.push('img { max-width: 100% !important; height: auto !important; }');
        lines.push('');

        if (d.hasOverflow) {
            lines.push('/* ── Overflow culprits detected on mobile ── */');
            d.overflowCulprits.slice(0, 6).forEach(function (c) {
                lines.push('/* ' + c.selector + ' — extends ' + c.excess + 'px past viewport */');
            });
            lines.push('');
        }

        lines.push('@media (max-width: 768px) {');
        lines.push('');

        /* Body */
        lines.push('  /* ── Body ── */');
        lines.push('  body { overflow-x: hidden !important; width: 100% !important; }');
        lines.push('');

        /* Navigation */
        lines.push('  /* ── Navigation ── */');
        if (!d.navHasHam) {
            lines.push('  /* No hamburger menu detected — compact 2-column grid applied */');
        }
        lines.push('  #header .nav .sub-menu { display: none !important; }');
        lines.push('  #header .nav ul {');
        lines.push('    display: flex !important;');
        lines.push('    flex-wrap: wrap !important;');
        lines.push('    list-style: none !important;');
        lines.push('    margin: 0 !important;');
        lines.push('    padding: 0 !important;');
        lines.push('    background: #1a3055;');
        lines.push('  }');
        lines.push('  #header .nav > div > ul > li { flex: 1 1 50% !important; float: none !important; }');
        lines.push('  #header .nav > div > ul > li > a {');
        lines.push('    display: block !important;');
        lines.push('    padding: 12px 8px !important;');
        lines.push('    color: #fff !important;');
        lines.push('    font-size: 0.78rem !important;');
        lines.push('    text-align: center !important;');
        lines.push('    text-transform: uppercase !important;');
        lines.push('    text-decoration: none !important;');
        lines.push('    border-bottom: 1px solid rgba(255,255,255,0.1) !important;');
        lines.push('    border-right:  1px solid rgba(255,255,255,0.1) !important;');
        lines.push('  }');
        lines.push('  #header .logo img {');
        lines.push('    max-width: 240px !important;');
        lines.push('    width: 100% !important;');
        lines.push('    height: auto !important;');
        lines.push('  }');
        lines.push('');

        /* Hero / Slideshow */
        lines.push('  /* ── Hero Slideshow ── */');
        lines.push('  /* flex column = hero image (order 1) always renders above search bar (order 2) */');
        lines.push('  div#slideshow {');
        lines.push('    display: flex !important;');
        lines.push('    flex-direction: column !important;');
        lines.push('    height: auto !important;');
        lines.push('    overflow: visible !important;');
        lines.push('    width: 100% !important;');
        lines.push('  }');
        lines.push('  div#slideshow div.hp-slideshow {');
        lines.push('    order: 1 !important;');
        lines.push('    position: relative !important;');
        lines.push('    display: block !important;');
        lines.push('    width: 100% !important;');
        lines.push('    height: 260px !important;');
        lines.push('    background-size: cover !important;');
        lines.push('    background-position: center center !important;');
        lines.push('    overflow: hidden !important;');
        lines.push('    flex-shrink: 0 !important;');
        lines.push('  }');
        lines.push('  div#slideshow div#quick-search {');
        lines.push('    order: 2 !important;');
        lines.push('    position: relative !important;');
        lines.push('    top: auto !important;');
        lines.push('    left: auto !important;');
        lines.push('    right: auto !important;');
        lines.push('    bottom: auto !important;');
        lines.push('    display: block !important;');
        lines.push('    width: 100% !important;');
        lines.push('    min-height: 80px !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('    text-align: center !important;');
        lines.push('  }');
        lines.push('  #quick-search .community { display: none !important; }');
        lines.push('');

        /* HP Content */
        lines.push('  /* ── Featured Properties Area ── */');
        lines.push('  #hp-content {');
        lines.push('    display: block !important;');
        lines.push('    visibility: visible !important;');
        lines.push('    width: 100% !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('    text-align: center !important;');
        lines.push('  }');
        lines.push('');

        /* iHF container */
        lines.push('  /* ── iHomeFinder container ── */');
        lines.push('  .ihf-container {');
        lines.push('    width: 100% !important;');
        lines.push('    max-width: 100% !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('    overflow: visible !important;');
        lines.push('    margin-bottom: 120px !important;');
        lines.push('    padding-bottom: 60px !important;');
        lines.push('  }');
        lines.push('');

        /* Auto-detected absolute/fixed elements (unknown ones) */
        var absUnknown = Object.keys(d.absoluteEls).filter(function (s) { return !isKnown(s); });
        if (absUnknown.length) {
            lines.push('  /* ── Absolute/fixed elements detected by scanner ── */');
            absUnknown.slice(0, 8).forEach(function (s) {
                var el = d.absoluteEls[s];
                lines.push('  ' + s + ' {');
                lines.push('    position: relative !important;');
                lines.push('    top: auto !important; left: auto !important;');
                lines.push('    right: auto !important; bottom: auto !important;');
                lines.push('  }  /* was: position:' + el.position + ' */');
            });
            lines.push('');
        }

        /* Auto-detected wide elements (unknown ones) */
        var wideUnknown = Object.keys(d.wideEls).filter(function (s) { return !isKnown(s); });
        if (wideUnknown.length) {
            lines.push('  /* ── Elements wider than 768 px detected by scanner ── */');
            wideUnknown.slice(0, 10).forEach(function (s) {
                var el = d.wideEls[s];
                lines.push('  ' + s + ' {');
                lines.push('    width: 100% !important;');
                lines.push('    max-width: 100% !important;');
                lines.push('    box-sizing: border-box !important;');
                lines.push('  }  /* was: ' + el.width + ' */');
            });
            lines.push('');
        }

        /* High z-index notes */
        var highZKeys = Object.keys(d.highZEls);
        if (highZKeys.length) {
            lines.push('  /* ── High z-index elements (may cause overlay issues) ── */');
            highZKeys.forEach(function (s) {
                var el = d.highZEls[s];
                var z  = parseInt(el.zIndex, 10);
                if (!isKnown(s) || z > 1000) {
                    lines.push('  /* ' + s + ' — z-index: ' + el.zIndex + ', position: ' + el.position + ' */');
                }
            });
            lines.push('');
        }

        /* Footer */
        lines.push('  /* ── Footer ── */');
        lines.push('  #footer, footer, #colophon, .site-footer, .c-footer {');
        lines.push('    clear: both !important;');
        lines.push('    position: relative !important;');
        lines.push('    z-index: 10 !important;');
        lines.push('    margin-top: 60px !important;');
        lines.push('    padding: 0 15px !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('  }');
        lines.push('');

        /* City links */
        lines.push('  /* ── Above-footer city links (ul.fc) ── */');
        lines.push('  ul.fc {');
        lines.push('    display: block !important;');
        lines.push('    visibility: visible !important;');
        lines.push('    height: auto !important;');
        lines.push('    padding: 10px 15px !important;');
        lines.push('    list-style: none !important;');
        lines.push('    word-break: break-word !important;');
        lines.push('    text-align: center !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('    margin: 0 auto 20px !important;');
        lines.push('  }');
        lines.push('  ul.fc li          { display: inline !important; list-style: none !important; }');
        lines.push('  ul.fc li::before  { content: none !important; }');
        lines.push('');

        lines.push('}  /* end @media (max-width: 768px) */');

        return lines.join('\n');
    }

    /* ════════════════════════════════════════════════════════════
       CSS generation — iHF Admin
    ════════════════════════════════════════════════════════════ */
    function buildIhfCSS(d) {
        var lines = [];

        var KNOWN_IHF = [
            'quick-search', 'ui-grid-container', 'ui-grid-item',
            'ui-form-control', 'ui-input-base', 'ui-button',
        ];

        lines.push('/* ============================================================');
        lines.push('   iHOMEFINDER ADMIN — CUSTOM CSS');
        lines.push('   Generated by Mobile CSS Auditor v1.0.0');
        lines.push('   ============================================================ */');
        lines.push('');

        if (Object.keys(d.shadowRoots).length) {
            lines.push('/* Shadow roots detected on: ' + Object.keys(d.shadowRoots).join(', ') + ' */');
            lines.push('/* All class names found inside shadow DOM:');
            lines.push('   ' + d.ihfClasses.slice().sort().join(', '));
            lines.push('*/');
        } else {
            lines.push('/* No shadow roots detected — using attribute selectors as fallback */');
        }

        lines.push('');
        lines.push('@media (max-width: 768px) {');
        lines.push('');

        /* Quick-search wrapper */
        lines.push('  /* ── Quick search wrapper ── */');
        lines.push('  .quick-search {');
        lines.push('    padding: 12px 10px !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('    height: auto !important;');
        lines.push('    width: 100% !important;');
        lines.push('    text-align: center !important;');
        lines.push('  }');
        lines.push('');

        /* Grid */
        lines.push('  /* ── Stack search fields vertically ── */');
        lines.push('  .ui-grid-container {');
        lines.push('    flex-direction: column !important;');
        lines.push('    align-items: center !important;');
        lines.push('    width: 100% !important;');
        lines.push('  }');
        lines.push('  .ui-grid-item {');
        lines.push('    max-width: 100% !important;');
        lines.push('    flex-basis: 100% !important;');
        lines.push('    width: 100% !important;');
        lines.push('    margin-bottom: 8px !important;');
        lines.push('  }');
        lines.push('');

        /* Inputs */
        lines.push('  /* ── Inputs ── */');
        lines.push('  .ui-form-control,');
        lines.push('  .ui-input-base {');
        lines.push('    width: 100% !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('  }');
        lines.push('');

        /* Submit button */
        lines.push('  /* ── Submit button (explicit text color for readability) ── */');
        lines.push('  .ui-button {');
        lines.push('    width: 100% !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('    color: #fff !important;');
        lines.push('    background-color: #48615c !important;');
        lines.push('    font-size: 1rem !important;');
        lines.push('    font-weight: 600 !important;');
        lines.push('    padding: 12px !important;');
        lines.push('    border: none !important;');
        lines.push('  }');
        lines.push('');

        /* Extra shadow DOM classes discovered */
        var extraClasses = d.ihfClasses.filter(function (c) {
            return !KNOWN_IHF.some(function (k) {
                return c === k || c.indexOf(k) === 0;
            });
        });

        /* Look for flex/grid containers in iHF elements */
        var flexEls = d.ihfElements.filter(function (e) {
            return e.display === 'flex' || e.display === 'grid';
        });

        if (flexEls.length) {
            lines.push('  /* ── iHF flex/grid containers detected by scanner ── */');
            var seenFlex = {};
            flexEls.forEach(function (e) {
                var cls = e.classes.join(', .');
                if (cls && !seenFlex[cls]) {
                    seenFlex[cls] = true;
                    if (e.display === 'flex') {
                        lines.push('  .' + cls + ' { flex-direction: column !important; width: 100% !important; }');
                    } else {
                        lines.push('  .' + cls + ' { grid-template-columns: 1fr !important; width: 100% !important; }');
                    }
                }
            });
            lines.push('');
        }

        if (extraClasses.length) {
            lines.push('  /* ── Additional shadow DOM classes found — review and add rules as needed ── */');
            extraClasses.slice(0, 25).forEach(function (c) {
                lines.push('  /* .' + c + ' */');
            });
            lines.push('');
        }

        /* Disclaimer */
        lines.push('  /* ── Disclaimer / legal copy ── */');
        lines.push('  [class*="disclaimer"],');
        lines.push('  [class*="legal"] {');
        lines.push('    display: block !important;');
        lines.push('    clear: both !important;');
        lines.push('    font-size: 0.7rem !important;');
        lines.push('    line-height: 1.6 !important;');
        lines.push('    color: #555 !important;');
        lines.push('    background: #f9f9f9 !important;');
        lines.push('    padding: 12px 10px !important;');
        lines.push('    margin: 20px 0 40px !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('  }');
        lines.push('');

        /* Contact / lead forms */
        lines.push('  /* ── Contact / lead forms ── */');
        lines.push('  [class*="contact"],');
        lines.push('  [class*="lead-form"] {');
        lines.push('    display: block !important;');
        lines.push('    clear: both !important;');
        lines.push('    width: 100% !important;');
        lines.push('    background: #fff !important;');
        lines.push('    color: #222 !important;');
        lines.push('    padding: 16px !important;');
        lines.push('    margin-top: 20px !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('  }');
        lines.push('  [class*="contact"] input, [class*="lead"] input,');
        lines.push('  [class*="contact"] textarea, [class*="lead"] textarea {');
        lines.push('    width: 100% !important;');
        lines.push('    margin-bottom: 12px !important;');
        lines.push('    padding: 10px !important;');
        lines.push('    font-size: 16px !important;');
        lines.push('    box-sizing: border-box !important;');
        lines.push('    background: #fff !important;');
        lines.push('    color: #222 !important;');
        lines.push('    border: 1px solid #ccc !important;');
        lines.push('  }');
        lines.push('  [class*="contact"] button, [class*="lead"] button {');
        lines.push('    width: 100% !important;');
        lines.push('    padding: 12px !important;');
        lines.push('    font-size: 1rem !important;');
        lines.push('    color: #fff !important;');
        lines.push('  }');
        lines.push('');

        /* Safety net */
        lines.push('  /* ── Global safety net ── */');
        lines.push('  * { max-width: 100% !important; box-sizing: border-box !important; }');
        lines.push('');
        lines.push('}  /* end @media (max-width: 768px) */');

        return lines.join('\n');
    }

    /* ════════════════════════════════════════════════════════════
       Raw report text
    ════════════════════════════════════════════════════════════ */
    function buildRawReport(d) {
        var lines = [];

        lines.push('=== MOBILE CSS AUDITOR — RAW SCAN REPORT ===');
        lines.push('Pages scanned: ' + Object.keys(allReports).length);
        lines.push('');

        Object.keys(allReports).forEach(function (id) {
            var r = allReports[id];
            lines.push('─── ' + r.title + ' (' + r.url + ') ───');
            lines.push('Viewport: ' + r.viewport + 'px  Body scroll width: ' + r.bodyScrollW + 'px  Overflow: ' + r.hasOverflow);

            if (r.shadowRoots.length) {
                lines.push('  SHADOW ROOTS:');
                r.shadowRoots.forEach(function (sr) {
                    lines.push('    Host: ' + sr.host);
                    if (sr.data && sr.data.classes && sr.data.classes.length) {
                        lines.push('    Classes: ' + sr.data.classes.slice(0, 30).join(', '));
                    }
                });
            }

            var abs = r.elements.filter(function (e) {
                return e.position === 'absolute' || e.position === 'fixed';
            });
            if (abs.length) {
                lines.push('  ABSOLUTE/FIXED:');
                abs.forEach(function (e) {
                    lines.push('    ' + e.selector + '  pos:' + e.position + '  z:' + e.zIndex + '  w:' + e.width);
                });
            }

            var wide = r.elements.filter(function (e) { return parseInt(e.width) > 768; });
            if (wide.length) {
                lines.push('  WIDE (>768px):');
                wide.forEach(function (e) {
                    lines.push('    ' + e.selector + '  w:' + e.width + '  maxW:' + e.maxWidth);
                });
            }

            if (r.overflowCulprits.length) {
                lines.push('  OVERFLOW CULPRITS:');
                r.overflowCulprits.forEach(function (c) {
                    lines.push('    ' + c.selector + '  right:' + c.right + 'px  excess:+' + c.excess + 'px');
                });
            }

            lines.push('');
        });

        return lines.join('\n');
    }

    /* ════════════════════════════════════════════════════════════
       Generate all
    ════════════════════════════════════════════════════════════ */
    function generateAll() {
        var d       = aggregate();
        var custCSS = buildCustomizerCSS(d);
        var ihfCSS  = buildIhfCSS(d);
        var rawRpt  = buildRawReport(d);

        $('#mca-out-customizer').val(custCSS);
        $('#mca-out-ihf').val(ihfCSS);
        $('#mca-out-report').val(rawRpt);
        $('#mca-output-wrap').removeClass('mca-hidden');

        $('html,body').animate({ scrollTop: $('#mca-output-wrap').offset().top - 30 }, 600);
    }

    /* ════════════════════════════════════════════════════════════
       Copy buttons
    ════════════════════════════════════════════════════════════ */
    function initCopyBtns() {
        $(document).on('click', '.mca-copy-btn', function () {
            var $ta  = $('#' + $(this).data('target'));
            var $btn = $(this);
            $ta[0].select();
            try {
                document.execCommand('copy');
                $btn.text('Copied!');
            } catch (e) {
                $btn.text('Select + copy manually');
            }
            setTimeout(function () { $btn.html('&#128203;&nbsp;Copy to Clipboard'); }, 2500);
        });
    }

    /* ════════════════════════════════════════════════════════════
       Init
    ════════════════════════════════════════════════════════════ */
    $(document).ready(function () {
        initTable();
        initCopyBtns();

        /* Scan all */
        $('#mca-btn-scan-all').on('click', function () {
            if (scanning) return;
            allReports   = {};
            scannedCount = 0;
            $('#mca-btn-generate').addClass('mca-hidden');
            $('#mca-output-wrap').addClass('mca-hidden');
            initTable();
            scanAll();
        });

        /* Scan single page */
        $(document).on('click', '.mca-scan-one', function () {
            var $b = $(this);
            var page = {
                id:    $b.data('id'),
                url:   $b.data('url'),
                title: $b.data('title'),
            };
            scanPage(page, function () {
                if (Object.keys(allReports).length) {
                    $('#mca-btn-generate').removeClass('mca-hidden');
                }
            });
        });

        /* Generate */
        $('#mca-btn-generate').on('click', generateAll);

        /* Clear */
        $('#mca-btn-clear').on('click', function () {
            allReports   = {};
            scannedCount = 0;
            scanning     = false;
            initTable();
            $('#mca-output-wrap').addClass('mca-hidden');
            $('#mca-btn-generate').addClass('mca-hidden');
            $('#mca-progress-text').text('');
            $('#mca-progress-fill').css('width', '0%');
            document.getElementById('mca-iframe').src = 'about:blank';
        });
    });

})(jQuery);
