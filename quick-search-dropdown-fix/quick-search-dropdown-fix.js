/**
 * Quick Search Dropdown Fix
 *
 * iHomeFinder renders its search widget inside an open shadow root. The
 * dropdown panels (Price, Beds/Baths, Property Type) can be either:
 *   A) children of the shadow root, OR
 *   B) React portals appended to document.body
 *
 * In both cases the panels end up hidden behind .c-wrap (the wave) or
 * sections below the hero. This script escapes the stacking context two
 * ways simultaneously:
 *   1. Injects CSS into the shadow root to lift any MUI Paper element
 *   2. Monitors document.body for portalled overlays and lifts those too
 * For panels confirmed in the shadow root it also uses getBoundingClientRect
 * on the trigger button so the panel is placed directly below it.
 */
(function () {
    'use strict';

    var Z = '2147483647';

    /* ── helpers ─────────────────────────────────────────────────────────── */

    function fix(el) {
        el.style.setProperty('z-index', Z, 'important');
    }

    function fixFixed(el) {
        el.style.setProperty('position', 'fixed',    'important');
        el.style.setProperty('z-index',  Z,          'important');
        el.style.setProperty('margin',   '0',        'important');
    }

    /* ── Part 1 : raise any MUI portals already in document.body ─────────── */

    function raiseBodyPortals() {
        var kids = document.body.children;
        for (var i = 0; i < kids.length; i++) {
            var el  = kids[i];
            var pos = window.getComputedStyle(el).position;
            if (pos === 'fixed' || pos === 'absolute') {
                fix(el);
            }
        }
    }

    /* ── Part 2 : shadow-root injection ──────────────────────────────────── */

    // Find the dropdown *container* parent (holds both button + panel)
    function getContainer(el, sr) {
        var cur = el;
        while (cur && cur !== sr) {
            var cls = cur.className || '';
            if (
                cls.indexOf('quick-search-price')         !== -1 ||
                cls.indexOf('quick-search-bed')           !== -1 ||
                cls.indexOf('quick-search-property')      !== -1 ||
                cls.indexOf('quick-search-bath')          !== -1 ||
                cls.indexOf('QuickSearch')                !== -1 ||
                cls.indexOf('quicksearch')                !== -1 ||
                cur.tagName === 'LI'                                  // common list-item wrapper
            ) { return cur; }
            cur = cur.parentElement;
        }
        return null;
    }

    function positionPanel(panel, sr) {
        // Walk up to find the container, then find its button
        var container = getContainer(panel, sr);
        var btn = container ? container.querySelector('button') : null;

        if (btn) {
            var rect = btn.getBoundingClientRect();
            if (rect.width > 0) {
                // position: fixed uses viewport coords — do NOT add scrollY
                panel.style.setProperty('top',  rect.bottom + 'px', 'important');
                panel.style.setProperty('left', rect.left   + 'px', 'important');
            }
        }

        fixFixed(panel);
    }

    function applyToShadow(sr) {
        // Broad selector: any MuiPaper inside the shadow root
        var panels = sr.querySelectorAll('[class*="MuiPaper"]');
        for (var i = 0; i < panels.length; i++) {
            var p = panels[i];
            if (!p.dataset.qsFix) {
                p.dataset.qsFix = '1';
                positionPanel(p, sr);
            }
        }
    }

    function initShadow(sr) {
        // Inject a stylesheet as a belt-and-suspenders fallback
        if (!sr.getElementById('qs-fix-style')) {
            var style = document.createElement('style');
            style.id  = 'qs-fix-style';
            style.textContent = [
                /* any MUI paper that lives in a quick-search dropdown container */
                '[class*="quick-search"] [class*="MuiPaper"] {',
                '  position: fixed !important;',
                '  z-index: ' + Z + ' !important;',
                '  margin: 0 !important;',
                '}',
                /* MUI Popper / Popover roots (direct shadow root children) */
                '[class*="MuiPopper"],[class*="MuiPopover"],[class*="MuiMenu"] {',
                '  z-index: ' + Z + ' !important;',
                '}'
            ].join('\n');
            sr.appendChild(style);
        }

        // MutationObserver: re-scan whenever the shadow DOM changes
        var obs = new MutationObserver(function () {
            requestAnimationFrame(function () {
                applyToShadow(sr);
                raiseBodyPortals();
            });
        });
        obs.observe(sr, { childList: true, subtree: true });

        // Polling fallback (catches anything the observer misses)
        setInterval(function () {
            applyToShadow(sr);
            raiseBodyPortals();
        }, 250);
    }

    /* ── init ────────────────────────────────────────────────────────────── */

    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 200); return; }

        // Locate shadow root
        var sr   = null;
        var els  = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        initShadow(sr);

        // Body portal observer
        var bodyObs = new MutationObserver(raiseBodyPortals);
        bodyObs.observe(document.body, { childList: true });

        // First pass
        applyToShadow(sr);
        raiseBodyPortals();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
