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

            var VP = <?php echo (int) $viewport; ?>;

            function run() {
                var report = buildReport(VP);
                // Send compact data to the controller window via postMessage.
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({ mca: true, report: report }, '*');
                } else {
                    // Fallback: write to page so user can copy.
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
                // Truncate body class list to save space.
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
                    });
                } else {
                    lines.push('--- NO OVERFLOW ---');
                }

                // ── Content width (main issue signal) ───────────────────────
                var contentInfo = getContentWidth();
                if (contentInfo) lines.push('--- CONTENT WIDTH: ' + contentInfo + ' ---');

                // ── Navigation ──────────────────────────────────────────────
                var navInfo = getNavInfo(vp);
                if (navInfo) lines.push('--- NAV: ' + navInfo + ' ---');

                // ── Zero-height visible elements (collapse bugs) ─────────────
                var collapsed = findCollapsed(vp);
                if (collapsed.length) {
                    lines.push('--- COLLAPSED ELEMENTS (' + collapsed.length + ') ---');
                    collapsed.forEach(function (c) {
                        lines.push('  ' + c.sel + ' | ' + c.w + 'x0 | ' + c.path);
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

                    if (right <= vp + 1) continue; // no overflow

                    var cs = getComputedStyle(el);
                    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
                    if (rect.width === 0 && rect.height === 0) continue;

                    var sel  = makeSel(el);
                    var key  = sel + right;
                    if (seen.has(key)) continue;
                    seen.add(key);

                    results.push({
                        sel:    sel,
                        right:  right,
                        excess: right - vp,
                        w:      Math.round(rect.width),
                        path:   cssPath(el, 4),
                    });
                }

                // Sort by largest excess first, cap at 20 results.
                results.sort(function (a, b) { return b.excess - a.excess; });
                return results.slice(0, 20);
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
                    if (rect.width < 50) continue; // ignore tiny spacers

                    results.push({
                        sel:  makeSel(el),
                        w:    Math.round(rect.width),
                        path: cssPath(el, 4),
                    });

                    if (results.length >= 10) break;
                }
                return results;
            }

            // ── Detect actual content column width ───────────────────────────
            function getContentWidth() {
                var candidates = [
                    'main.content', '.content', '#content', 'main', '[role="main"]',
                    '.site-inner', '.entry-content', '.page-content',
                ];
                for (var i = 0; i < candidates.length; i++) {
                    var el = document.querySelector(candidates[i]);
                    if (!el) continue;
                    var w = Math.round(el.getBoundingClientRect().width);
                    if (w < 10) continue;
                    var cs = getComputedStyle(el);
                    var extra = '';
                    if (cs.float && cs.float !== 'none') extra = ' float:' + cs.float;
                    return candidates[i] + ' → ' + w + 'px' + extra;
                }
                return '';
            }

            // ── Nav status ──────────────────────────────────────────────────
            function getNavInfo(vp) {
                var nav = document.querySelector('nav, [role="navigation"], .nav-primary, header nav');
                if (!nav) return '';

                var rect     = nav.getBoundingClientRect();
                var cs       = getComputedStyle(nav);
                var hidden   = cs.display === 'none' || rect.height === 0;
                var hamburger = !!document.querySelector(
                    '.menu-toggle, .hamburger, [aria-label*="menu" i], button.nav-toggle, .mobile-menu-toggle, #mobile-nav-primary'
                );
                var items = nav.querySelectorAll('li').length;
                var tag   = makeSel(nav);

                return tag + ' | ' + (hidden ? 'HIDDEN' : 'visible') + ' | items:' + items + ' | hamburger:' + (hamburger ? 'yes' : 'NO');
            }

            // ── CSS path helper (max depth levels) ──────────────────────────
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

            // ── Short selector: tag + id or first class ──────────────────────
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

            // Wait for full paint before measuring.
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
