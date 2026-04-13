/**
 * Quick Search Dropdown Fix v6.13.0
 *
 * Confirmed from live-site DevTools + full site CSS:
 *
 *   #slideshow    z-index:1    root — theme stylesheet
 *   #hp-content   z-index:1001 root — theme overrides site's "unset" attempt
 *   #quick-search z-index:99   inside #slideshow — shadow host
 *   .c-wrap       z-index:999  inside #slideshow — wave
 *   .panel        inside #quick-search (light DOM) — the actual dropdown panels
 *
 * Fix: watch for .panel elements becoming visible; when open, temporarily
 * suppress both blockers.  When closed, removeProperty so natural CSS wins.
 *
 * Cosmetic: inject CSS into the iHF shadow root to set .quick-search
 * background-color and height (confirmed fix from site admin).
 */
(function () {
    'use strict';

    var Z_MAX = '2147483647';

    /* Shared open/closed state — OR of all three detectors */
    var reasons = { lightDom: false, shadow: false, portal: false };
    var isOpen  = false;

    var waveEl = null, hpEl = null;
    function getWave() { if (!waveEl) waveEl = document.querySelector('.c-wrap');      return waveEl; }
    function getHp()   { if (!hpEl)   hpEl   = document.getElementById('hp-content'); return hpEl;   }

    function applyState(open) {
        if (open === isOpen) return;
        isOpen = open;
        var w = getWave(), h = getHp();
        if (open) {
            if (w) w.style.setProperty('z-index', '10',  'important');
            if (h) h.style.setProperty('z-index', '0',   'important');
        } else {
            if (w) w.style.removeProperty('z-index');
            if (h) h.style.removeProperty('z-index');
        }
    }

    function update() { applyState(reasons.lightDom || reasons.shadow || reasons.portal); }

    /* ── 1. Light-DOM .panel watcher ─────────────────────────────────────── */
    function watchLightDom(qs) {
        var debounce;
        function check() {
            var panels = qs.querySelectorAll('.panel');
            var anyOpen = false;
            for (var i = 0; i < panels.length; i++) {
                if (panels[i].offsetHeight > 0) { anyOpen = true; break; }
            }
            reasons.lightDom = anyOpen;
            update();
        }
        new MutationObserver(function () {
            clearTimeout(debounce);
            debounce = setTimeout(check, 25);
        }).observe(qs, { childList: true, subtree: true, attributes: true });
        check();
    }

    /* ── 2. Shadow-root panel watcher ────────────────────────────────────── */
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

    /* ── 3. Body-portal fallback ─────────────────────────────────────────── */
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

    /* ── Cosmetic: inject CSS into iHF shadow root ───────────────────────── */
    function injectShadowCss(sr) {
        if (sr.querySelector('#qsdf-bg-fix')) return;
        var style = document.createElement('style');
        style.id = 'qsdf-bg-fix';
        style.textContent = '.quick-search { background-color: #48615c !important; height: 80px !important; }';
        sr.prepend(style);
    }

    /* ── init ────────────────────────────────────────────────────────────── */
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 150); return; }

        watchLightDom(qs);

        var sr = qs.shadowRoot;
        if (!sr) {
            var all = qs.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                if (all[i].shadowRoot) { sr = all[i].shadowRoot; break; }
            }
        }
        if (sr) {
            watchShadow(sr);
            injectShadowCss(sr);
        }

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
