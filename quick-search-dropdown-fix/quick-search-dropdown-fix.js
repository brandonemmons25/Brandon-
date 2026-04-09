/**
 * Quick Search Dropdown Fix v6.2.0
 *
 * Two-part fix:
 *
 * PART 1 — Shadow-DOM stacking context
 *   The div housing #quick-search has a lower z-index than .c-wrap (the wave,
 *   z-index:999).  Shadow-DOM panels are bounded by that stacking context, so
 *   the wave paints over them.
 *   Fix: raise only already-positioned ancestors of #quick-search to z-index
 *   1000 (never add position:relative — that would shift absolutely-positioned
 *   children and break the layout).  Then push .c-wrap to 1001 via JS so the
 *   wave is still visible above the container.
 *   When a dropdown opens, drop .c-wrap to 999 so the panels clear it.
 *   When the dropdown closes, restore .c-wrap to 1001.
 *
 * PART 2 — MUI body-portal panels
 *   iHomeFinder/MUI also portals some panels to document.body at z-index:1300.
 *   The content sections below the hero can have a higher stacking context,
 *   blocking the middle of the panel.
 *   Fix: MutationObserver on body raises every portal element to z-index:MAX
 *   the instant it is added or shown.
 */
(function () {
    'use strict';

    var Z_MAX   = '2147483647';
    var Z_ABOVE = '1001';   /* wave above container (1000) — design intact */
    var Z_BELOW = '999';    /* wave below container (1000) — panels visible */

    var waveEl = null;
    function getWave() {
        if (!waveEl) waveEl = document.querySelector('.c-wrap');
        return waveEl;
    }
    function setWave(z) {
        var w = getWave();
        if (w) w.style.setProperty('z-index', z, 'important');
    }

    var MUI_SEL = [
        '[class*="MuiPopper-root"]',
        '[class*="MuiPopover-root"]',
        '[class*="MuiMenu-root"]',
        '[class*="MuiAutocomplete-popper"]',
        '[class*="MuiModal-root"]',
        '[class*="MuiPaper-root"]'
    ].join(',');

    /* ── PART 2: raise body portals ──────────────────────────────────────── */
    var waveDown = false;
    function raisePortals() {
        var anyOpen = false;

        /* MUI elements anywhere in the document */
        var muiEls = document.querySelectorAll(MUI_SEL);
        for (var i = 0; i < muiEls.length; i++) {
            muiEls[i].style.setProperty('z-index', Z_MAX, 'important');
            if (muiEls[i].offsetHeight > 0) anyOpen = true;
        }

        /* Fixed/absolute direct children of body (portal wrapper divs) */
        var kids = document.body.children;
        for (var j = 0; j < kids.length; j++) {
            var pos = window.getComputedStyle(kids[j]).position;
            if (pos === 'fixed' || pos === 'absolute') {
                kids[j].style.setProperty('z-index', Z_MAX, 'important');
            }
        }

        /* Keep wave in sync with portal state */
        if (anyOpen && !waveDown) {
            waveDown = true;
            setWave(Z_BELOW);
        } else if (!anyOpen && waveDown) {
            waveDown = false;
            setWave(Z_ABOVE);
        }
    }

    /* ── PART 1: raise the #quick-search ancestor stacking context ────────── */
    var ancestorsRaised = false;
    function raiseSearchAncestors() {
        if (ancestorsRaised) return;
        var qs = document.getElementById('quick-search');
        if (!qs) return;
        var el = qs.parentElement;
        while (el && el.id !== 'slideshow' && el !== document.body) {
            /* Only touch already-positioned elements — adding position:relative
               to a static ancestor can shift absolutely-positioned children. */
            if (window.getComputedStyle(el).position !== 'static') {
                el.style.setProperty('z-index', '1000', 'important');
            }
            el = el.parentElement;
        }
        ancestorsRaised = true;
        /* Wave must be above the container (1000) so the design is intact */
        setWave(Z_ABOVE);
    }

    /* ── start ───────────────────────────────────────────────────────────── */
    function start() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(start, 150); return; }

        raiseSearchAncestors();
        raisePortals();

        var debounce;
        new MutationObserver(function () {
            clearTimeout(debounce);
            debounce = setTimeout(raisePortals, 25);
        }).observe(document.body, {
            childList:       true,
            subtree:         true,
            attributes:      true,
            attributeFilter: ['style', 'class']
        });

        setInterval(raisePortals, 300);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 300); });
    } else {
        setTimeout(start, 300);
    }
})();
