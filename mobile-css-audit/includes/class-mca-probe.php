<?php
/**
 * Handles injection of the scanner JS when ?mca_probe=1 is present.
 */
class MCA_Probe {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'maybe_inject' ] );
    }

    public static function maybe_inject() {
        if ( empty( $_GET['mca_probe'] ) ) return;

        $nonce = get_option( 'mca_nonce' );
        if ( ! $nonce || $_GET['mca_nonce'] !== $nonce ) return;

        add_action( 'wp_footer', [ __CLASS__, 'inject_scanner' ], 9999 );
    }

    public static function inject_scanner() {
        $viewport = 375;
        ?>
        <script id="mca-scanner">
        (function () {
            'use strict';

            var VP = <?php echo (int) $viewport; ?>;
            var BP = 782;

            function run() {
                var report = buildReport(VP);
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({ mca: true, report: report }, '*');
                } else {
                    var pre = document.createElement('pre');
                    pre.id  = 'mca-output';
                    pre.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;overflow:auto;z-index:999999;background:#111;color:#0f0;font:12px/1.5 monospace;padding:16px;white-space:pre-wrap;';
                    pre.textContent = report;
                    document.body.appendChild(pre);
                }
            }

            function buildReport(vp) {
                var lines = [];

                // ── Header ──────────────────────────────────────────────────
                var scrollW   = document.documentElement.scrollWidth;
                var docH      = Math.round(document.documentElement.scrollHeight);
                var overflow  = scrollW > vp ? 'YES +' + (scrollW - vp) + 'px' : 'no';
                var bodyClass = (document.body.className || '').replace(/\s+/g, ' ').trim();
                if (bodyClass.length > 120) bodyClass = bodyClass.slice(0, 120) + '…';

                lines.push('PAGE: ' + document.title);
                lines.push('URL: ' + location.href);
                lines.push('Viewport: ' + vp + 'px | BodyScrollW: ' + scrollW + 'px | Overflow: ' + overflow + ' | DocHeight: ' + docH + 'px');
                lines.push('Body classes: ' + bodyClass);

                // ── Overflow culprits ────────────────────────────────────────
                var culprits = findOverflows(vp);
                if (culprits.length) {
                    lines.push('--- OVERFLOW CULPRITS (' + culprits.length + ') ---');
                    // Collect unique pattern-based fixes across all culprits.
                    var fixSeen = new Set();
                    culprits.forEach(function (c) {
                        lines.push('  ' + c.sel + ' | right=' + c.right + 'px excess=+' + c.excess + 'px | w=' + c.w + 'px | ' + c.path);
                        if (c.fix && !fixSeen.has(c.fix)) {
                            fixSeen.add(c.fix);
                            lines.push('Fix → ' + c.fix);
                        }
                    });
                } else {
                    lines.push('--- NO OVERFLOW ---');
                }

                // ── Layout diagnosis (only when content is narrow) ───────────
                var diagLines = diagnoseLayout(vp);
                if (diagLines) lines.push(diagLines);

                // ── Navigation ──────────────────────────────────────────────
                var navInfo = getNavInfo(vp);
                if (navInfo) lines.push('--- NAV: ' + navInfo + ' ---');

                // ── Zero-height visible elements (collapse bugs) ─────────────
                var collapsed = findCollapsed(vp);
                if (collapsed.length) {
                    lines.push('--- COLLAPSED ELEMENTS (' + collapsed.length + ') ---');
                    var colFixSeen = new Set();
                    collapsed.forEach(function (c) {
                        lines.push('  ' + c.sel + ' | ' + c.w + 'x0 | ' + c.path);
                        if (c.fix && !colFixSeen.has(c.fix)) {
                            colFixSeen.add(c.fix);
                            lines.push('Fix → ' + c.fix);
                        }
                    });
                }

                // ── YouTube iframe diagnostics ───────────────────────────────
                var ytFrames = document.querySelectorAll('iframe[src*="youtube"]');
                if (ytFrames.length) {
                    lines.push('--- YOUTUBE IFRAMES (' + ytFrames.length + ') ---');
                    for (var yi = 0; yi < Math.min(ytFrames.length, 3); yi++) {
                        var yf   = ytFrames[yi];
                        var yr   = yf.getBoundingClientRect();
                        var ycs  = getComputedStyle(yf);
                        var ypar = yf.parentElement;
                        var yprect = ypar ? ypar.getBoundingClientRect() : null;
                        var ypcls  = ypar ? (ypar.className || ypar.tagName) : '?';
                        var ypcs   = ypar ? getComputedStyle(ypar) : null;
                        lines.push(
                            '  iframe: ' + Math.round(yr.width) + 'x' + Math.round(yr.height) +
                            ' | pos:' + ycs.position +
                            ' | inline-h:' + (yf.getAttribute('height') || 'none') +
                            ' | computed-h:' + ycs.height +
                            '\n  parent: ' + ypcls +
                            (yprect ? ' ' + Math.round(yprect.width) + 'x' + Math.round(yprect.height) : '') +
                            (ypcs ? ' pos:' + ypcs.position + ' overflow:' + ypcs.overflow : '')
                        );
                    }
                }

                lines.push('------------------------------------------------------------');
                return lines.join('\n');
            }

            // ── Find all elements whose right edge exceeds viewport ──────────
            function findOverflows(vp) {
                var results = [];
                var seen    = new Set();
                var all     = document.body.querySelectorAll('*');

                for (var i = 0; i < all.length; i++) {
                    var el   = all[i];
                    if (isAdminEl(el)) continue;

                    var rect  = el.getBoundingClientRect();
                    var right = Math.round(rect.right);
                    if (right <= vp + 1) continue;

                    var cs = getComputedStyle(el);
                    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
                    if (rect.width === 0 && rect.height === 0) continue;
                    if (isInsideScrollContainer(el)) continue;

                    var sel = makeSel(el);
                    var key = sel + right;
                    if (seen.has(key)) continue;
                    seen.add(key);

                    results.push({
                        el:     el,
                        sel:    sel,
                        right:  right,
                        excess: right - vp,
                        w:      Math.round(rect.width),
                        path:   cssPath(el, 4),
                        fix:    patternFix(el, cs),
                    });
                }

                results.sort(function (a, b) { return b.excess - a.excess; });
                return results.slice(0, 20);
            }

            // ── Detect collapsed (0-height) elements in main content ─────────
            function findCollapsed(vp) {
                var results = [];
                var main    = document.querySelector('main, .content, #content, [role="main"]');
                if (!main) return results;

                var els = main.querySelectorAll('section, article, div, iframe, video, img');
                for (var i = 0; i < els.length; i++) {
                    var el   = els[i];
                    if (isAdminEl(el)) continue;

                    var rect = el.getBoundingClientRect();
                    var cs   = getComputedStyle(el);
                    if (cs.display === 'none') continue;
                    if (rect.height > 0) continue;
                    if (rect.width < 50) continue;

                    var fix = collapsedFix(el);
                    results.push({
                        sel:  makeSel(el),
                        w:    Math.round(rect.width),
                        path: cssPath(el, 4),
                        fix:  fix,
                    });
                    if (results.length >= 10) break;
                }
                return results;
            }

            // ── Fix for overflow — only fixed/sticky needs a specific rule.
            // Tables, images, iframes, embeds are all covered by the universal
            // rules that Fix Summary always outputs. Return '' for those so the
            // generated CSS stays as small as possible.
            function patternFix(el, cs) {
                if (cs.position === 'fixed' || cs.position === 'sticky') {
                    var s = stableSel(el);
                    if (!s) return '';
                    // reCAPTCHA badge is a cosmetic fixed widget — hide it on mobile
                    // (v3 script still fires; badge is purely decorative)
                    if (s === '.grecaptcha-badge') return s + ' { display:none; }';
                    return s + ' { width:100%!important; max-width:100vw!important; left:0!important; right:0!important; box-sizing:border-box!important; }';
                }
                return ''; // covered by universal rules
            }

            // Collapsed elements need manual investigation; no auto-CSS generated.
            function collapsedFix(el) { return ''; }

            // ── Return a stable, reusable CSS selector for an element.
            //    Walks up the DOM until it finds an element with a class or
            //    a non-dynamic ID. Skips randomly-generated IDs (GTM, Swiper).
            function stableSel(el) {
                var node = el, depth = 0;
                while (node && node !== document.body && depth < 8) {
                    var s = '';
                    if (node.id && !isDynamicId(node.id)) {
                        s = '#' + node.id;
                    } else if (node.className && typeof node.className === 'string') {
                        var cls = node.className.trim().split(/\s+/)[0];
                        if (cls) s = '.' + cls;
                    }
                    if (s) return s;
                    node = node.parentElement;
                    depth++;
                }
                return '';
            }

            // Randomly-generated IDs that change per post/visit are useless in CSS
            function isDynamicId(id) {
                return /^(gt-wrapper|swiper-wrapper|block-[0-9])/i.test(id)
                    || /^[a-f0-9]{8,}$/i.test(id); // pure hex strings
            }

            // ── Layout diagnosis: detect narrow content and suggest fix ───────
            function diagnoseLayout(vp) {
                var out = [];

                var bc = (document.body.className || '').trim().split(/\s+/);
                var layoutClasses = bc.filter(function (c) {
                    return /^(genesis|et-pb|ast-|elementor|oxy-|fl-builder|flatsome|ocean|avada|salient|porto|woodmart|storefront|hello-elementor|neve|generatepress|kadence|blocksy|twentytwenty|twentyone|twentytwo|twentythree|twentyfour|twentyfive)/.test(c)
                        || c === 'full-width-content' || c === 'content-sidebar' || c === 'sidebar-content'
                        || c === 'no-sidebar' || c === 'page-template-default';
                }).slice(0, 4);
                // layoutClasses used only for reporting (Theme: line) — not in the CSS rule

                var sels = [
                    'main.content', 'main#main', '#main-content', '#content-area',
                    '.site-content', 'main', '.content', '#content', '#main',
                    '[role="main"]', '.entry-content'
                ];
                var contentEl = null, contentSel = '';
                for (var i = 0; i < sels.length; i++) {
                    try {
                        var el = document.querySelector(sels[i]);
                        if (el && !el.closest('#wpadminbar') && el.getBoundingClientRect().width > 20) {
                            contentEl = el; contentSel = sels[i]; break;
                        }
                    } catch(e) {}
                }
                if (!contentEl) return '';

                var cs     = getComputedStyle(contentEl);
                var rect   = contentEl.getBoundingClientRect();
                var w      = Math.round(rect.width);
                var floatV = cs.float || '';
                var dispV  = cs.display;

                if (w >= vp - 2) return '';

                // Walk up and collect narrow ancestors so the fix rule actually works.
                // Parents constrained to < vp must also be widened, not just the content el.
                var chain = [];
                var narrowParSels = [];
                var nd = contentEl.parentElement;
                for (var d = 0; nd && nd.tagName !== 'BODY' && d < 5; d++, nd = nd.parentElement) {
                    var pr  = nd.getBoundingClientRect();
                    var pcs = getComputedStyle(nd);
                    var pw  = Math.round(pr.width);
                    var ps  = makeSel(nd) + '[' + pw + 'px';
                    if (pcs.display === 'flex')      ps += ',flex';
                    else if (pcs.display === 'grid') ps += ',grid';
                    if (pcs.overflowX === 'hidden')  ps += ',ovf:hidden';
                    var pmx = pcs.maxWidth;
                    if (pmx && pmx !== 'none' && pmx !== pw + 'px') ps += ',max:' + pmx;
                    chain.push(ps + ']');
                    if (pw < vp - 2) {
                        var pSel = stableSel(nd);
                        if (pSel && narrowParSels.indexOf(pSel) === -1) narrowParSels.push(pSel);
                    }
                }

                var nearPar = chain.length ? chain[0].split('[')[0] : '';
                var fix = '';

                if (dispV === 'grid' || dispV === 'inline-grid') {
                    fix = (nearPar || contentSel) + ' { grid-template-columns:1fr!important; }';
                } else if (w < vp - 2) {
                    // Include every narrow ancestor so widening them cascades down.
                    var selGroup = narrowParSels.length
                        ? narrowParSels.join(', ') + ', ' + contentSel
                        : contentSel;
                    fix = selGroup + ' { float:none!important; width:100%!important; max-width:100%!important; box-sizing:border-box!important; }';
                } else {
                    fix = '/* inspect: ' + contentSel + ' (' + w + 'px) inside ' + (nearPar || '?') + ' — cause unclear */';
                }

                out.push('--- LAYOUT DIAGNOSIS ---');
                if (layoutClasses.length) out.push('Theme: ' + layoutClasses.join(' '));
                out.push('Content: ' + contentSel + ' | ' + w + 'px NARROW' +
                    (floatV && floatV !== 'none' ? ' | float:' + floatV + ' (' + cs.width + ')' : '') +
                    (dispV !== 'block' ? ' | display:' + dispV : ''));
                if (chain.length) out.push('Parents: ' + chain.join(' > '));
                out.push('Fix → ' + fix);
                return out.join('\n');
            }

            // ── Nav status ──────────────────────────────────────────────────
            function getNavInfo(vp) {
                var navSelectors = [
                    '.nav-primary', 'nav.nav-primary', 'header nav',
                    '.site-header nav', '#site-navigation',
                    'nav:not(#wp-toolbar nav):not([class*="admin"])',
                    '[role="navigation"]:not(#wp-toolbar [role="navigation"])',
                    'nav'
                ];
                var nav = null;
                for (var ns = 0; ns < navSelectors.length; ns++) {
                    try {
                        var candidate = document.querySelector(navSelectors[ns]);
                        if (candidate && !candidate.closest('#wp-toolbar, #wpadminbar')) {
                            nav = candidate; break;
                        }
                    } catch(e) {}
                }
                if (!nav) return '';

                var rect      = nav.getBoundingClientRect();
                var cs        = getComputedStyle(nav);
                var hidden    = cs.display === 'none' || rect.height === 0;
                var hamburger = !!document.querySelector(
                    '.menu-toggle, .hamburger, [aria-label*="menu" i], button.nav-toggle, .mobile-menu-toggle, #mobile-nav-primary'
                );
                return makeSel(nav) + ' | ' + (hidden ? 'HIDDEN' : 'visible') + ' | items:' + nav.querySelectorAll('li').length + ' | hamburger:' + (hamburger ? 'yes' : 'NO');
            }

            function isInsideScrollContainer(el) {
                var node = el.parentElement;
                while (node && node !== document.body) {
                    var ox = getComputedStyle(node).overflowX;
                    if (ox === 'auto' || ox === 'scroll') return true;
                    node = node.parentElement;
                }
                return false;
            }

            function isAdminEl(el) {
                return !!(el.closest && el.closest('#wpadminbar, #wp-toolbar'));
            }

            function cssPath(el, maxDepth) {
                var parts = [], node = el, depth = 0;
                while (node && node !== document.body && depth < maxDepth) {
                    parts.unshift(makeSel(node));
                    node = node.parentElement; depth++;
                }
                return parts.join(' > ');
            }

            function makeSel(el) {
                if (!el || !el.tagName) return '?';
                var s = el.tagName.toLowerCase();
                if (el.id) s += '#' + el.id;
                else if (el.className && typeof el.className === 'string') {
                    var first = el.className.trim().split(/\s+/)[0];
                    if (first) s += '.' + first;
                }
                return s;
            }

            if (document.readyState === 'complete') { run(); }
            else { window.addEventListener('load', run); }
        }());
        </script>
        <?php
    }
}
