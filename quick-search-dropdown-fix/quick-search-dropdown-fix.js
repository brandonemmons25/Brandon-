/**
 * Quick Search Dropdown Fix v5.4.0
 *
 * Core fix runs unconditionally — does NOT require finding the shadow root.
 * Shadow root CSS injection is a separate, periodic belt-and-suspenders step.
 *
 * raiseAll() also walks up the ancestor chain of every MUI element so that
 * any positioned portal-wrapper containers are raised alongside the panels.
 */
(function () {
    'use strict';

    var Z = '2147483647';

    var MUI_SEL = [
        '[class*="MuiPopper-root"]',
        '[class*="MuiPopover-root"]',
        '[class*="MuiMenu-root"]',
        '[class*="MuiAutocomplete-popper"]',
        '[class*="MuiModal-root"]',
        '[class*="MuiPaper-root"]'
    ].join(',');

    /* ── Raise MUI overlays + their positioned ancestors ──────────────────── */
    function raiseAll() {
        /* 1. Every MUI element in the main document */
        var muiEls = document.querySelectorAll(MUI_SEL);
        for (var i = 0; i < muiEls.length; i++) {
            muiEls[i].style.setProperty('z-index', Z, 'important');

            /* Walk up and raise any positioned container that might trap it */
            var p = muiEls[i].parentElement;
            while (p && p !== document.body) {
                if (window.getComputedStyle(p).position !== 'static') {
                    p.style.setProperty('z-index', Z, 'important');
                }
                p = p.parentElement;
            }
        }

        /* 2. Fixed/absolute direct body children (portal wrapper divs) */
        var kids = document.body.children;
        for (var j = 0; j < kids.length; j++) {
            var pos = window.getComputedStyle(kids[j]).position;
            if (pos === 'fixed' || pos === 'absolute') {
                kids[j].style.setProperty('z-index', Z, 'important');
            }
        }
    }

    /* ── Shadow-root CSS injection (retried until found) ──────────────────── */
    var shadowDone = false;
    function tryInjectShadow() {
        if (shadowDone) return;
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        /* Check the host element itself first, then its descendants */
        var sr = qs.shadowRoot;
        if (!sr) {
            var all = qs.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                if (all[i].shadowRoot) { sr = all[i].shadowRoot; break; }
            }
        }
        if (!sr) return;

        if (!sr.querySelector('#qs-fix-style')) {
            var style = document.createElement('style');
            style.id   = 'qs-fix-style';
            style.textContent = MUI_SEL + ' { z-index: ' + Z + ' !important; }';
            sr.appendChild(style);
        }
        shadowDone = true;
    }

    /* ── start — runs unconditionally, no shadow-root gate ───────────────── */
    function start() {
        var debounce;
        new MutationObserver(function () {
            clearTimeout(debounce);
            debounce = setTimeout(raiseAll, 20);
        }).observe(document.body, {
            childList:       true,
            subtree:         true,
            attributes:      true,
            attributeFilter: ['style', 'class']
        });

        setInterval(raiseAll,         250);   /* periodic safety net          */
        setInterval(tryInjectShadow,  500);   /* keep probing for shadow root */

        raiseAll();
        tryInjectShadow();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 300); });
    } else {
        setTimeout(start, 300);
    }
})();
