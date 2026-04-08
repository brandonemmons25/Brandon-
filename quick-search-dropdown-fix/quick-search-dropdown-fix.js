/**
 * Quick Search Dropdown Fix
 *
 * iHomeFinder dropdown panels (Price, Beds/Baths, Property Type, Location)
 * are React/MUI components that portal to document.body.  They land with
 * z-index:1300 (MUI default) which loses to the wave (.c-wrap, inside
 * #slideshow) and to the content sections below.
 *
 * Strategy:
 *  1. Use document.querySelectorAll to find ALL MUI overlay elements in the
 *     main document (works even if they are nested inside a portal wrapper).
 *  2. Apply z-index:MAX via setProperty('important') — React's plain
 *     el.style.zIndex assignments cannot override inline !important.
 *  3. Watch the whole body subtree so we catch elements the moment they
 *     are added or mutated by React.
 *  4. Inject a stylesheet into the shadow root as a belt-and-suspenders
 *     fallback for any panels that stay inside the shadow DOM.
 */
(function () {
    'use strict';

    var Z = '2147483647';

    /* Selectors for every MUI overlay component */
    var MUI_SEL = [
        '[class*="MuiPopper-root"]',
        '[class*="MuiPopover-root"]',
        '[class*="MuiMenu-root"]',
        '[class*="MuiAutocomplete-popper"]',
        '[class*="MuiModal-root"]'
    ].join(',');

    /* ── Raise all MUI overlays found anywhere in the main document ──────── */
    function raiseAll() {
        var els = document.querySelectorAll(MUI_SEL);
        for (var i = 0; i < els.length; i++) {
            els[i].style.setProperty('z-index', Z, 'important');
        }

        /* Belt-and-suspenders: also raise any positioned direct body child
           that might not carry a MUI class (e.g. custom portal wrappers) */
        var kids = document.body.children;
        for (var j = 0; j < kids.length; j++) {
            var pos = window.getComputedStyle(kids[j]).position;
            if (pos === 'fixed' || pos === 'absolute') {
                kids[j].style.setProperty('z-index', Z, 'important');
            }
        }
    }

    /* ── Inject stylesheet into shadow root ──────────────────────────────── */
    function initShadow(sr) {
        if (sr.querySelector('#qs-fix-style')) return;
        var style = document.createElement('style');
        style.id  = 'qs-fix-style';
        style.textContent = [
            /* stylesheet !important beats React non-!important inline */
            '[class*="MuiPaper-root"] { z-index: ' + Z + ' !important; }',
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

        var sr  = null;
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        initShadow(sr);

        /* Watch the entire body subtree — fires whenever React adds or
           changes anything, including nested portal containers */
        var debounce;
        new MutationObserver(function () {
            clearTimeout(debounce);
            debounce = setTimeout(raiseAll, 30);
        }).observe(document.body, {
            childList:  true,
            subtree:    true,
            attributes: true,
            attributeFilter: ['style', 'class']
        });

        /* Periodic fallback in case a reconcile slips past the observer */
        setInterval(raiseAll, 300);

        raiseAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
