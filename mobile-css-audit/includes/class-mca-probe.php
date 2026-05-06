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

        // Inject at footer so full DOM is rendered.
        add_action( 'wp_footer', [ __CLASS__, 'inject_scanner' ], 9999 );
    }

    public static function inject_scanner() {
        $viewport = 375;
        ?>
        <script id="mca-scanner">
        (function () {
            'use strict';

            var VP = <?php echo (int) $viewport; ?>; // measurement viewport
            var BP = 782;                             // CSS breakpoint (WP mobile)

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
                    culprits.forEach(function (c) {
                        lines.push('  ' + c.sel + ' | right=' + c.right + 'px excess=+' + c.excess + 'px | w=' + c.w + 'px | ' + c.path);
                        lines.push('Fix → ' + c.fix);
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
                    collapsed.forEach(function (c) {
                        lines.push('  ' + c.sel + ' | ' + c.w + 'x0 | ' + c.path);
                        lines.push('Fix → ' + c.fix);
                    });
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
                    var rect = el.getBoundingClientRect();
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
                        sel:    sel,
                        right:  right,
                        excess: right - vp,
                        w:      Math.round(rect.width),
                        path:   cssPath(el, 4),
                        fix:    generateOfFix(el),
                    });
                }

                results.sort(function (a, b) { return b.excess - a.excess; });
                return results.slice(0, 20);
            }

            // ── True if any ancestor clips via overflow-x scroll ────────────
            function isInsideScrollContainer(el) {
                var node = el.parentElement;
                while (node && node !== document.body) {
                    var ox = getComputedStyle(node).overflowX;
                    if (ox === 'auto' || ox === 'scroll') return true;
                    node = node.parentElement;
                }
                return false;
            }

            // ── Generate CSS fix for an overflowing element ──────────────────
            function generateOfFix(el) {
                var cs  = getComputedStyle(el);
                var tag = el.tagName.toLowerCase();
                var s   = cssSel(el);
                var mq  = '@media (max-width:' + BP + 'px) { ';

                // Fixed/sticky — must stay within viewport bounds
                if (cs.position === 'fixed' || cs.position === 'sticky') {
                    return mq + s + ' { width:100%!important; max-width:100vw!important; left:0!important; right:0!important; box-sizing:border-box!important; } }';
                }
                // Media — scale down
                if (/^(img|video|iframe|embed|object)$/.test(tag)) {
                    return mq + s + ' { max-width:100%!important; width:100%!important; height:auto!important; } }';
                }
                // Tables — horizontal scroll so content stays readable
                if (tag === 'table') {
                    return mq + s + ' { display:block!important; max-width:100%!important; overflow-x:auto!important; } }';
                }
                // Sliders / carousels — contain, JS manages inner width
                if (/swiper|slider|carousel|glide|splide/.test(el.className || '')) {
                    return mq + s + ' { max-width:100%!important; overflow:hidden!important; } }';
                }
                // Default block
                return mq + s + ' { max-width:100%!important; box-sizing:border-box!important; } }';
            }

            // ── Detect collapsed (0-height) visible elements in main content ─
            function findCollapsed(vp) {
                var results = [];
                var main    = document.querySelector('main, .content, #content, [role="main"]');
                if (!main) return results;

                var els = main.querySelectorAll('section, article, div, iframe, video, img');
                for (var i = 0; i < els.length; i++) {
                    var el   = els[i];
                    var rect = el.getBoundingClientRect();
                    var cs   = getComputedStyle(el);

                    if (cs.display === 'none') continue;
                    if (rect.height > 0) continue;
                    if (rect.width < 50) continue;

                    results.push({
                        sel:  makeSel(el),
                        w:    Math.round(rect.width),
                        path: cssPath(el, 4),
                        fix:  generateColFix(el),
                    });

                    if (results.length >= 10) break;
                }
                return results;
            }

            // ── Generate CSS fix for a collapsed element ─────────────────────
            function generateColFix(el) {
                var tag  = el.tagName.toLowerCase();
                var s    = cssSel(el);
                var minH = /^(section|article|header|footer|aside|main)$/.test(tag) ? '60px' : '20px';
                return '@media (max-width:' + BP + 'px) { ' + s + ' { display:block!important; min-height:' + minH + '!important; width:100%!important; box-sizing:border-box!important; } }';
            }

            // ── Layout diagnosis: detect content element, why it's narrow,
            //    and emit a ready-to-use CSS fix. Returns '' when full-width. ─
            function diagnoseLayout(vp) {
                var out = [];

                var bc = (document.body.className || '').trim().split(/\s+/);
                var layoutClasses = bc.filter(function (c) {
                    return /^(genesis|et-pb|ast-|elementor|oxy-|fl-builder|flatsome|ocean|avada|salient|porto|woodmart|storefront|hello-elementor|neve|generatepress|kadence|blocksy|twentytwenty|twentyone|twentytwo|twentythree|twentyfour|twentyfive)/.test(c)
                        || c === 'full-width-content' || c === 'content-sidebar' || c === 'sidebar-content'
                        || c === 'no-sidebar' || c === 'page-template-default';
                }).slice(0, 4);

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

                if (w >= vp - 2) return ''; // full-width — nothing to report

                // Parent chain
                var chain = [];
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
                }

                var bodyPfx = layoutClasses.length ? 'body.' + layoutClasses[0] : 'body';
                var nearPar = chain.length ? chain[0].split('[')[0] : '';
                var fix = '';

                if (floatV && floatV !== 'none') {
                    fix = '@media (max-width:' + BP + 'px) { ' + bodyPfx + ' ' + (nearPar ? nearPar + ' ' : '') + contentSel + ' { float:none!important; width:100%!important; max-width:100%!important; box-sizing:border-box!important; } }';
                } else if (dispV === 'flex' || dispV === 'inline-flex') {
                    fix = '@media (max-width:' + BP + 'px) { ' + bodyPfx + ' ' + contentSel + ' { flex:0 0 100%!important; width:100%!important; max-width:100%!important; box-sizing:border-box!important; } }';
                } else if (dispV === 'grid' || dispV === 'inline-grid') {
                    fix = '@media (max-width:' + BP + 'px) { ' + bodyPfx + ' ' + (nearPar ? nearPar + ' ' : '') + '{ grid-template-columns:1fr!important; } }';
                } else {
                    var mxSelf = cs.maxWidth;
                    if (mxSelf && mxSelf !== 'none' && parseInt(mxSelf) < vp) {
                        fix = '@media (max-width:' + BP + 'px) { ' + bodyPfx + ' ' + contentSel + ' { max-width:100%!important; width:100%!important; box-sizing:border-box!important; } }';
                    } else {
                        fix = '/* inspect: ' + contentSel + ' (' + w + 'px) inside ' + (nearPar || '?') + ' — cause unclear */';
                    }
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
                            nav = candidate;
                            break;
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
                var items = nav.querySelectorAll('li').length;

                return makeSel(nav) + ' | ' + (hidden ? 'HIDDEN' : 'visible') + ' | items:' + items + ' | hamburger:' + (hamburger ? 'yes' : 'NO');
            }

            // ── CSS path helper ──────────────────────────────────────────────
            function cssPath(el, maxDepth) {
                var parts = [];
                var node  = el;
                var depth = 0;
                while (node && node !== document.body && depth < maxDepth) {
                    parts.unshift(makeSel(node));
                    node  = node.parentElement;
                    depth++;
                }
                return parts.join(' > ');
            }

            // ── Short selector for display: tag + id or first class ──────────
            function makeSel(el) {
                if (!el || !el.tagName) return '?';
                var s = el.tagName.toLowerCase();
                if (el.id)                          s += '#' + el.id;
                else if (el.className && typeof el.className === 'string') {
                    var first = el.className.trim().split(/\s+/)[0];
                    if (first) s += '.' + first;
                }
                return s;
            }

            // ── CSS selector for fix generation: prefer .class or #id ────────
            function cssSel(el) {
                if (!el || !el.tagName) return '';
                if (el.id) return '#' + el.id;
                if (el.className && typeof el.className === 'string') {
                    var cls = el.className.trim().split(/\s+/)[0];
                    if (cls) return '.' + cls;
                }
                return el.tagName.toLowerCase();
            }

            if (document.readyState === 'complete') {
                run();
            } else {
                window.addEventListener('load', run);
            }
        }());
        </script>
        <?php
    }
}
