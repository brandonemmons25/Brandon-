/**
 * Quick Search Dropdown Fix
 *
 * The CSS raises #slideshow's stacking context above the content sections.
 * This JS ensures dropdown panels inside the shadow root also beat the wave
 * (.c-wrap z-index:999) by giving them z-index:max.
 *
 * We use a stylesheet injection (not inline setProperty) so React's inline
 * styles — which lack !important — are overridden by our !important rule.
 *
 * We also watch document.body for any MUI panels portalled there.
 */
(function () {
    'use strict';

    var Z = '2147483647';

    /* ── Raise any MUI portal containers in document.body ───────────────── */
    function raiseBodyPortals() {
        var kids = document.body.children;
        for (var i = 0; i < kids.length; i++) {
            var el  = kids[i];
            var pos = window.getComputedStyle(el).position;
            if (pos === 'fixed' || pos === 'absolute') {
                el.style.zIndex = Z;
            }
        }
    }

    /* ── Inject stylesheet into shadow root ─────────────────────────────── */
    function initShadow(sr) {
        if (sr.getElementById('qs-fix-style')) return;

        var style = document.createElement('style');
        style.id  = 'qs-fix-style';
        /*
         * Target any MUI Paper / Popper / Popover inside the shadow root.
         * Using !important means we beat React's non-!important inline styles
         * (inline styles without !important lose to stylesheet !important).
         * We do NOT change position — let MUI/PopperJS handle placement.
         */
        style.textContent = [
            '[class*="MuiPaper-root"] {',
            '  z-index: ' + Z + ' !important;',
            '}',
            '[class*="MuiPopper-root"],',
            '[class*="MuiPopover-root"],',
            '[class*="MuiMenu-root"] {',
            '  z-index: ' + Z + ' !important;',
            '}'
        ].join('\n');
        sr.appendChild(style);
    }

    /* ── init ────────────────────────────────────────────────────────────── */
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 200); return; }

        var sr  = null;
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        initShadow(sr);

        /* Watch body for MUI portals added dynamically */
        new MutationObserver(raiseBodyPortals)
            .observe(document.body, { childList: true });

        raiseBodyPortals();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
