/**
 * Quick Search Dropdown Fix v6.0.0
 *
 * Root cause (confirmed from iHomeFinder admin CSS):
 *   Dropdown panels render inside the shadow DOM at z-index:110.
 *   But the shadow host's CONTAINER div has a z-index lower than
 *   .c-wrap (the wave, z-index:999 inside #slideshow).
 *   Shadow-DOM content is bounded by the host's stacking context,
 *   so the wave always paints over the panels.
 *
 * Fix:
 *   Walk up from #quick-search and raise every ancestor (stopping
 *   at #slideshow) to z-index:1000 — just above the wave's 999.
 *   This lifts the shadow host's stacking context above the wave
 *   without touching #slideshow itself (kept at z-index:50 so it
 *   stays below the site header).
 */
(function () {
    'use strict';

    var raised = false;

    function raiseSearchContainer() {
        if (raised) return;
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        var el = qs.parentElement;
        while (el && el.id !== 'slideshow' && el !== document.body) {
            /* z-index only works on positioned elements */
            if (window.getComputedStyle(el).position === 'static') {
                el.style.setProperty('position', 'relative', 'important');
            }
            el.style.setProperty('z-index', '1000', 'important');
            el = el.parentElement;
        }
        raised = true;
    }

    function init() {
        raiseSearchContainer();
        if (!raised) {
            /* #quick-search not in DOM yet — retry */
            setTimeout(init, 150);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
