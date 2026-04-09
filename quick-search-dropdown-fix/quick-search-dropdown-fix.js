/**
 * Quick Search Dropdown Fix v6.11.0
 *
 * Confirmed from live-site DevTools + full site CSS:
 *
 *   #slideshow    z-index:1    root — theme stylesheet
 *   #hp-content   z-index:1001 root — theme overrides site's "unset" attempt
 *   #quick-search z-index:99   inside #slideshow — shadow host
 *   .c-wrap       z-index:999  inside #slideshow — wave
 *   .panel        inside #quick-search (light DOM) — the actual dropdown panels
 *
 * The panels (.panel class, light DOM) are bounded by #quick-search (z-index:99)
 * inside #slideshow (z-index:1).  Two elements block them:
 *   .c-wrap (999 > 99) inside #slideshow   → wave covers panels
 *   #hp-content (1001 > 1) in root         → content section covers lower panels
 *
 * Fix: watch for .panel elements becoming visible; when open, temporarily
 * suppress both blockers.  When closed, removeProperty so natural CSS wins.
 *
 * Three independent detectors feed a shared open/closed state:
 *   1. Light DOM  — .panel inside #quick-search (confirmed present by site CSS)
 *   2. Shadow DOM — .ihf-advanced-search-button-container > div (shadow root)
 *   3. Portal     — MUI portals added to document.body (fallback)
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
            /* Lower wave below #quick-search (99) so panels clear the wave */
            if (w) w.style.setProperty('z-index', '10',  'important');
            /* Lower content section below #slideshow (1) so panels show through */
            if (h) h.style.setProperty('z-index', '0',   'important');
        } else {
            /* Remove inline overrides — let natural CSS cascade take over */
            if (w) w.style.removeProperty('z-index');
            if (h) h.style.removeProperty('z-index');
        }
    }

    function update() { applyState(reasons.lightDom || reasons.shadow || reasons.portal); }

    /* ── 1. Light-DOM .panel watcher (primary) ───────────────────────────── */
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

    /* ── Cosmetic: background strip fix ─────────────────────────────────── */
    /*
     * The dark-green strip below the search form is whichever element inside
     * #slideshow has a dark background showing through.  We scan #slideshow
     * and its immediate children (skipping .c-wrap wave) and force the tan
     * homepage color (#E0DEC1) on any element whose computed background is
     * not transparent/white/tan.
     */
    function fixBackground() {
        var tan = '#E0DEC1';
        var ss = document.getElementById('slideshow');
        if (!ss) return;
        /* Always fix #slideshow itself */
        ss.style.setProperty('background-color', tan, 'important');
        /* Fix direct children that aren't the wave */
        var kids = ss.children;
        for (var i = 0; i < kids.length; i++) {
            var el = kids[i];
            if (el.classList && el.classList.contains('c-wrap')) continue;
            var bg = window.getComputedStyle(el).backgroundColor;
            /* Skip transparent and already-tan elements */
            if (!bg || bg === 'transparent' || bg === 'rgba(0, 0, 0, 0)') continue;
            if (bg === 'rgb(224, 222, 193)') continue; /* already tan */
            el.style.setProperty('background-color', tan, 'important');
        }
        /* Also fix .hp-slideshow if present anywhere */
        var hpSS = document.querySelector('.hp-slideshow');
        if (hpSS) hpSS.style.setProperty('background-color', tan, 'important');
        /* Fix #quick-search host element — confirmed bg: rgb(57,68,66) */
        var qsEl = document.getElementById('quick-search');
        if (qsEl) qsEl.style.setProperty('background-color', tan, 'important');
    }

    /* ── Cosmetic: shadow-DOM background fix ─────────────────────────────── */
    /*
     * The iHF shadow DOM's outermost container has bg rgb(57,68,66) which
     * paints over the host element's tan background.  Scanning all descendants
     * (v6.9.0) accidentally recolored the city-links section further down the
     * page that iHF also renders inside the same shadow root.
     *
     * Fix: inject a <style> into the shadow root that ONLY targets the direct
     * children of the shadow root (:host > div).  This makes just the outermost
     * wrapper transparent so the host's tan background shows through.  All
     * deeper components (form fields, buttons, city links, etc.) keep their
     * own backgrounds untouched.
     */
    function injectShadowCss(sr) {
        if (sr.querySelector('#qsdf-bg-fix')) return; /* already injected */
        var style = document.createElement('style');
        style.id = 'qsdf-bg-fix';
        /* :host > * covers any tag (not just div); two levels catches nested
           wrappers without reaching city-links deep in the component tree */
        style.textContent = ':host > * { background-color: transparent !important; } :host > * > * { background-color: transparent !important; }';
        sr.prepend(style);
    }

    /* ── init ────────────────────────────────────────────────────────────── */
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 150); return; }

        fixBackground();
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
