/**
 * Quick Search Dropdown Fix v6.1.0
 *
 * The wave (.c-wrap, z-index:999) sits above the search container, which
 * is correct for the design.  But it also covers shadow-DOM panels.
 *
 * Strategy:
 *   1. Raise the #quick-search ancestor chain to z-index:1000 — this lifts
 *      the shadow-DOM stacking context above .c-wrap's default z-index:999.
 *   2. Then raise .c-wrap to z-index:2000 so the wave paints above the
 *      container again → wave design fully restored.
 *   3. When the user clicks inside #quick-search (opening a dropdown),
 *      temporarily drop .c-wrap to z-index:999 so the panels (inside the
 *      container at z-index:1000) are visible above the wave.
 *   4. When the user clicks outside or presses Escape, restore .c-wrap to
 *      z-index:2000 — wave reappears instantly.
 */
(function () {
    'use strict';

    var WAVE_HIGH = '2000'; /* above container (1000) — wave visible    */
    var WAVE_LOW  = '999';  /* below container (1000) — panels visible  */

    var waveEl       = null;
    var restoreTimer = null;

    function getWave() {
        if (!waveEl) waveEl = document.querySelector('.c-wrap');
        return waveEl;
    }

    function setWave(z) {
        var w = getWave();
        if (w) w.style.setProperty('z-index', z, 'important');
    }

    /* ── Raise every ancestor of #quick-search (up to #slideshow) ────────── */
    var ancestorsRaised = false;
    function raiseSearchAncestors() {
        if (ancestorsRaised) return;
        var qs = document.getElementById('quick-search');
        if (!qs) return;
        var el = qs.parentElement;
        while (el && el.id !== 'slideshow' && el !== document.body) {
            if (window.getComputedStyle(el).position === 'static') {
                el.style.setProperty('position', 'relative', 'important');
            }
            el.style.setProperty('z-index', '1000', 'important');
            el = el.parentElement;
        }
        ancestorsRaised = true;
    }

    function scheduleRestore() {
        clearTimeout(restoreTimer);
        restoreTimer = setTimeout(function () { setWave(WAVE_HIGH); }, 200);
    }

    /* ── init ────────────────────────────────────────────────────────────── */
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) { setTimeout(init, 150); return; }

        raiseSearchAncestors();
        setWave(WAVE_HIGH); /* wave above container — design intact */

        /* Click inside widget → dropdown opening, lower wave */
        qs.addEventListener('click', function () {
            clearTimeout(restoreTimer);
            setWave(WAVE_LOW);
        });

        /* Click outside widget → dropdown closing, restore wave */
        document.addEventListener('click', function (e) {
            if (!qs.contains(e.target)) {
                scheduleRestore();
            }
        }, true);

        /* Escape key → dropdown closing, restore wave */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                scheduleRestore();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 300); });
    } else {
        setTimeout(init, 300);
    }
})();
