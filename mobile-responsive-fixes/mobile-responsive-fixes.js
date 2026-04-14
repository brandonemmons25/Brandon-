/**
 * Mobile Responsive Fixes — Hamburger Menu
 * v2.0.0
 *
 * Inserts a hamburger toggle button before div.nav inside #header.
 * Only runs on mobile (≤ 782 px).
 * Toggles body class `mrf-nav-open` so CSS can show/hide the nav.
 */
(function () {
    'use strict';

    function init() {
        if (window.innerWidth > 782) return;

        var nav = document.querySelector('#header .nav') ||
                  document.querySelector('#header div.nav');
        if (!nav) return;

        /* Build hamburger button */
        var btn = document.createElement('button');
        btn.className = 'mrf-hamburger';
        btn.setAttribute('aria-label', 'Toggle navigation');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML =
            '<span class="mrf-bar"></span>' +
            '<span class="mrf-bar"></span>' +
            '<span class="mrf-bar"></span>';

        /* Insert before div.nav */
        nav.parentNode.insertBefore(btn, nav);

        /* Toggle */
        btn.addEventListener('click', function () {
            var isOpen = document.body.classList.toggle('mrf-nav-open');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        /* Close when a nav link is tapped */
        nav.addEventListener('click', function (e) {
            if (e.target.tagName === 'A') {
                document.body.classList.remove('mrf-nav-open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
