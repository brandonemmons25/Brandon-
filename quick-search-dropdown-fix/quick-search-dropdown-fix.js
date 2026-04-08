/**
 * Quick Search Dropdown Fix
 *
 * iHomeFinder's React app runs inside an open shadow root.  The Price /
 * Beds-Baths / Property-Type dropdowns open as React portals appended to
 * document.body (standard MUI behaviour).  Those portals have z-index:1300
 * by default, which loses to the wave element (.c-wrap, z-index:999 inside
 * #slideshow whose stacking context sits at z-index:50 in the root).
 *
 * Key bug in earlier versions: we set el.style.zIndex = Z (no !important).
 * React reconciles and writes z-index:1300 back, overriding our value.
 * Fix: use el.style.setProperty('z-index', Z, 'important').
 * Inline !important beats React's non-!important inline style permanently.
 *
 * We also inject a stylesheet into the shadow root so any panels that
 * stay inside the shadow DOM (not portalled) also get z-index:max.
 */
(function () {
    'use strict';

    var Z = '2147483647';

    /* ── Raise portal containers that land in document.body ─────────────── */

    function raiseEl(el) {
        var pos = window.getComputedStyle(el).position;
        if (pos === 'fixed' || pos === 'absolute') {
            /* setProperty with 'important' cannot be overridden by
               React's plain el.style.zIndex = '1300' assignment */
            el.style.setProperty('z-index', Z, 'important');
        }
    }

    function raiseBodyPortals() {
        var kids = document.body.children;
        for (var i = 0; i < kids.length; i++) {
            raiseEl(kids[i]);
        }
    }

    /* ── Inject stylesheet into the shadow root ──────────────────────────── */

    function initShadow(sr) {
        if (sr.querySelector('#qs-fix-style')) return;

        var style = document.createElement('style');
        style.id  = 'qs-fix-style';
        /*
         * stylesheet !important beats React's non-!important inline styles.
         * Targets all MUI overlay components regardless of exact class name.
         */
        style.textContent = [
            '[class*="MuiPaper-root"] {',
            '  z-index: ' + Z + ' !important;',
            '}',
            '[class*="MuiPopper-root"],',
            '[class*="MuiPopover-root"],',
            '[class*="MuiMenu-root"],',
            '[class*="MuiAutocomplete-popper"] {',
            '  z-index: ' + Z + ' !important;',
            '}'
        ].join('\n');
        sr.appendChild(style);
    }

    /* ── init ────────────────────────────────────────────────────────────── */

    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 200); return; }

        /* Find shadow root */
        var sr  = null;
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        initShadow(sr);

        /*
         * Watch document.body for new portal containers AND re-raise them.
         * Also watch each existing body child for style attribute changes
         * in case React overwrites z-index after we set it.
         */
        function watchChild(el) {
            raiseEl(el);
            new MutationObserver(function () { raiseEl(el); })
                .observe(el, { attributes: true, attributeFilter: ['style'] });
        }

        /* Existing body children */
        var kids = document.body.children;
        for (var j = 0; j < kids.length; j++) { watchChild(kids[j]); }

        /* Future body children (new portals) */
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) { watchChild(node); }
                });
            });
        }).observe(document.body, { childList: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
