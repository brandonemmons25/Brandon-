/**
 * Quick Search Dropdown Fix v6.3.0
 *
 * Confirmed live-site z-index values:
 *   #slideshow    z-index:1    position:relative  (root stacking context)
 *   #hp-content   z-index:1001 position:relative  (root — was blocking panels)
 *   #quick-search z-index:99   position:absolute  (shadow host, inside #slideshow)
 *   .c-wrap       z-index:999  position:absolute  (wave, inside #slideshow)
 *
 * Problem 1 — content section blocks dropdown:
 *   Panels inside #slideshow (z-index:1 in root) are bounded below #hp-content
 *   (z-index:1001).  CSS raises #slideshow to 1002 to fix this.
 *
 * Problem 2 — wave covers shadow-DOM panels:
 *   .c-wrap (999) > #quick-search (99) inside #slideshow → wave paints above
 *   the shadow-DOM panels.  Fix: watch the shadow root; when a panel opens,
 *   temporarily drop .c-wrap to z-index:10 (below #quick-search:99) so the
 *   panels clear the wave.  Restore to 999 when the panel closes.
 *
 * Fallback — MUI body portals:
 *   Some panels may portal to document.body.  A MutationObserver raises any
 *   portal wrapper to z-index:MAX the instant it appears.
 */
(function () {
    'use strict';

    var Z_MAX       = '2147483647';
    var WAVE_NORMAL = '999';  /* original — wave above #quick-search (99) */
    var WAVE_OPEN   = '10';   /* below #quick-search (99) — panels visible */

    /* ── Wave helpers ────────────────────────────────────────────────────── */
    var waveEl   = null;
    var waveDown = false;
    function getWave() {
        if (!waveEl) waveEl = document.querySelector('.c-wrap');
        return waveEl;
    }
    function setWave(z) {
        var w = getWave();
        if (w) w.style.setProperty('z-index', z, 'important');
    }

    /* ── Shadow-root panel watcher ───────────────────────────────────────── */
    function watchShadow(sr) {
        var debounce;
        function check() {
            /* iHF dropdown panels live inside .ihf-advanced-search-button-container > div */
            var panels = sr.querySelectorAll(
                '.ihf-advanced-search-button-container > div,' +
                '[class*="MuiPopper"],[class*="MuiPaper"],[class*="MuiMenu"]'
            );
            var anyOpen = false;
            for (var i = 0; i < panels.length; i++) {
                if (panels[i].offsetHeight > 0) { anyOpen = true; break; }
            }
            if (anyOpen && !waveDown) {
                waveDown = true;
                setWave(WAVE_OPEN);
            } else if (!anyOpen && waveDown) {
                waveDown = false;
                setWave(WAVE_NORMAL);
            }
        }
        new MutationObserver(function () {
            clearTimeout(debounce);
            debounce = setTimeout(check, 25);
        }).observe(sr, { childList: true, subtree: true, attributes: true });
        check();
    }

    /* ── Body-portal fallback ────────────────────────────────────────────── */
    var MUI_SEL = [
        '[class*="MuiPopper-root"]',
        '[class*="MuiPopover-root"]',
        '[class*="MuiMenu-root"]',
        '[class*="MuiAutocomplete-popper"]',
        '[class*="MuiModal-root"]'
    ].join(',');

    function raisePortals() {
        var els = document.querySelectorAll(MUI_SEL);
        for (var i = 0; i < els.length; i++) {
            els[i].style.setProperty('z-index', Z_MAX, 'important');
        }
        var kids = document.body.children;
        for (var j = 0; j < kids.length; j++) {
            var pos = window.getComputedStyle(kids[j]).position;
            if (pos === 'fixed' || pos === 'absolute') {
                kids[j].style.setProperty('z-index', Z_MAX, 'important');
            }
        }
    }

    /* ── init ────────────────────────────────────────────────────────────── */
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 150); return; }

        /* Find shadow root */
        var sr = qs.shadowRoot;
        if (!sr) {
            var all = qs.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                if (all[i].shadowRoot) { sr = all[i].shadowRoot; break; }
            }
        }
        if (sr) watchShadow(sr);

        /* Body portal fallback */
        var debounce2;
        new MutationObserver(function () {
            clearTimeout(debounce2);
            debounce2 = setTimeout(raisePortals, 25);
        }).observe(document.body, { childList: true });

        setInterval(raisePortals, 300);
        raisePortals();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 300); });
    } else {
        setTimeout(init, 300);
    }
})();
