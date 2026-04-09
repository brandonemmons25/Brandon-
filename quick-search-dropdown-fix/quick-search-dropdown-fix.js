/**
 * Quick Search Dropdown Fix v6.4.0
 *
 * Confirmed live-site values:
 *   #slideshow    z-index:1    position:relative  (root)
 *   #hp-content   z-index:1001 position:relative  (root — was blocking panels)
 *   #quick-search z-index:99   position:absolute  (shadow host, inside #slideshow)
 *   .c-wrap       z-index:999  position:absolute  (wave, inside #slideshow)
 *
 * Two toggles fire when a dropdown opens, both restore when it closes:
 *
 *   WAVE TOGGLE
 *     .c-wrap 999 → 10   puts wave below #quick-search (99) → panels visible
 *     .c-wrap 10  → 999  restores wave design
 *
 *   CONTENT TOGGLE
 *     #hp-content 1001 → 0   puts content section below #slideshow (1) → panels
 *                              extend visually through that area without being blocked
 *     #hp-content 0    → 1001 restores normal stacking
 *
 * NOTE: If you want to remove the wave design entirely and skip the wave
 * toggle, add this to the CSS field in iHF admin or site CSS:
 *     .c-wrap { display: none !important; }
 * Then only the content toggle is needed.
 *
 * Body-portal fallback also raises any MUI element to z-index:MAX.
 */
(function () {
    'use strict';

    var Z_MAX = '2147483647';

    /* Open-state is OR of two independent detectors */
    var reasons = { shadow: false, portal: false };

    var waveEl = null, hpEl = null;
    function getWave() { if (!waveEl) waveEl = document.querySelector('.c-wrap');          return waveEl; }
    function getHp()   { if (!hpEl)   hpEl   = document.getElementById('hp-content');     return hpEl;   }

    var isOpen = false;
    function applyState(open) {
        if (open === isOpen) return;
        isOpen = open;

        /* Wave: 999 (wave above quick-search) → 10 (below quick-search:99) */
        var w = getWave();
        if (w) w.style.setProperty('z-index', open ? '10'   : '999',  'important');

        /* Content section: 1001 → 0 (below #slideshow:1 → panels show through) */
        var h = getHp();
        if (h) h.style.setProperty('z-index', open ? '0'    : '1001', 'important');
    }

    function update() {
        applyState(reasons.shadow || reasons.portal);
    }

    /* ── Body-portal fallback (MUI portals to document.body) ─────────────── */
    var MUI_SEL = [
        '[class*="MuiPopper-root"]',
        '[class*="MuiPopover-root"]',
        '[class*="MuiMenu-root"]',
        '[class*="MuiAutocomplete-popper"]',
        '[class*="MuiModal-root"]'
    ].join(',');

    function checkPortals() {
        var anyOpen = false;
        var els = document.querySelectorAll(MUI_SEL);
        for (var i = 0; i < els.length; i++) {
            els[i].style.setProperty('z-index', Z_MAX, 'important');
            if (els[i].offsetHeight > 0) anyOpen = true;
        }
        var kids = document.body.children;
        for (var j = 0; j < kids.length; j++) {
            var pos = window.getComputedStyle(kids[j]).position;
            if (pos === 'fixed' || pos === 'absolute') {
                kids[j].style.setProperty('z-index', Z_MAX, 'important');
            }
        }
        reasons.portal = anyOpen;
        update();
    }

    /* ── Shadow-root panel watcher ───────────────────────────────────────── */
    function watchShadow(sr) {
        var debounce;
        function check() {
            var panels = sr.querySelectorAll(
                '.ihf-advanced-search-button-container > div,' +
                '[class*="MuiPopper"],[class*="MuiPaper"],[class*="MuiMenu"]'
            );
            var anyOpen = false;
            for (var i = 0; i < panels.length; i++) {
                if (panels[i].offsetHeight > 0) { anyOpen = true; break; }
            }
            reasons.shadow = anyOpen;
            update();
        }
        new MutationObserver(function () {
            clearTimeout(debounce);
            debounce = setTimeout(check, 25);
        }).observe(sr, { childList: true, subtree: true, attributes: true });
        check();
    }

    /* ── init ────────────────────────────────────────────────────────────── */
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 150); return; }

        var sr = qs.shadowRoot;
        if (!sr) {
            var all = qs.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                if (all[i].shadowRoot) { sr = all[i].shadowRoot; break; }
            }
        }
        if (sr) watchShadow(sr);

        var debounce2;
        new MutationObserver(function () {
            clearTimeout(debounce2);
            debounce2 = setTimeout(checkPortals, 25);
        }).observe(document.body, { childList: true });

        setInterval(checkPortals, 300);
        checkPortals();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 300); });
    } else {
        setTimeout(init, 300);
    }
})();
