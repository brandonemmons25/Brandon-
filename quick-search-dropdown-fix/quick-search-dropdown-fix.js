/**
 * Quick Search Dropdown Fix v6.14.0
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
 *
 * Mobile: inject responsive CSS into both the quick-search and featured
 * listings shadow roots using confirmed class names from DevTools.
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

    /* ── Shadow CSS ──────────────────────────────────────────────────────── */

    /* Quick-search widget: brand color + mobile field stacking */
    var QS_CSS = [
        '.quick-search { background-color: #48615c !important; height: auto !important; min-height: 80px !important; }',
        '@media (max-width: 768px) {',
        '  .quick-search { padding: 10px !important; box-sizing: border-box !important; }',
        '  .ui-grid-container { flex-direction: column !important; align-items: stretch !important; }',
        '  .ui-grid-item { max-width: 100% !important; flex-basis: 100% !important; width: 100% !important; margin-bottom: 6px !important; }',
        '  .ui-button { width: 100% !important; box-sizing: border-box !important; }',
        '  .ui-form-control { width: 100% !important; }',
        '  .ui-input-base { width: 100% !important; }',
        '}'
    ].join('\n');

    /* Featured listings: center cards, stack layout, fix disclaimer + contact form */
    var LISTING_CSS = [
        '@media (max-width: 768px) {',
        '  * { box-sizing: border-box !important; max-width: 100% !important; }',
        '  [class*="listing-card"],[class*="property-card"],[class*="result-item"] {',
        '    width: 100% !important; margin: 0 auto 16px !important; float: none !important;',
        '  }',
        '  [class*="listings-grid"],[class*="results-grid"],[class*="property-list"] {',
        '    display: flex !important; flex-direction: column !important; align-items: center !important;',
        '  }',
        '  [class*="disclaimer"],[class*="legal"],[class*="attribution"] {',
        '    display: block !important; clear: both !important;',
        '    font-size: 0.7rem !important; line-height: 1.5 !important;',
        '    color: #555 !important; padding: 12px 10px !important;',
        '    margin-bottom: 20px !important;',
        '  }',
        '  [class*="contact"],[class*="lead-form"],[class*="agent-contact"] {',
        '    display: block !important; clear: both !important; width: 100% !important;',
        '    background: #fff !important; color: #222 !important;',
        '    padding: 16px !important; margin-top: 20px !important;',
        '  }',
        '  [class*="contact"] input,[class*="lead"] input,',
        '  [class*="contact"] textarea,[class*="lead"] textarea {',
        '    width: 100% !important; margin-bottom: 10px !important;',
        '    font-size: 16px !important;',
        '  }',
        '}'
    ].join('\n');

    function injectStyle(sr, id, css) {
        if (sr.querySelector('#' + id)) return;
        var style = document.createElement('style');
        style.id = id;
        style.textContent = css;
        sr.prepend(style);
    }

    /* ── init ────────────────────────────────────────────────────────────── */
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 150); return; }

        watchLightDom(qs);

        /* Quick-search shadow root */
        var sr = qs.shadowRoot;
        if (!sr) {
            var all = qs.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                if (all[i].shadowRoot) { sr = all[i].shadowRoot; break; }
            }
        }
        if (sr) {
            watchShadow(sr);
            injectStyle(sr, 'qsdf-bg-fix', QS_CSS);
        }

        /* Featured listings shadow roots */
        var containers = document.querySelectorAll('.ihf-container');
        for (var j = 0; j < containers.length; j++) {
            var csr = containers[j].shadowRoot;
            if (csr) injectStyle(csr, 'qsdf-listing-' + j, LISTING_CSS);
        }

        /* Re-check listing shadow roots as iHF may render them late */
        setTimeout(function () {
            var lateContainers = document.querySelectorAll('.ihf-container');
            for (var k = 0; k < lateContainers.length; k++) {
                var lcsr = lateContainers[k].shadowRoot;
                if (lcsr) injectStyle(lcsr, 'qsdf-listing-' + k, LISTING_CSS);
            }
        }, 2000);

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
