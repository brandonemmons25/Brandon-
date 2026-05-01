/**
 * Mobile Responsive Fixes — Hamburger Menu
 * v3.0.0
 */
(function () {
    'use strict';

    function init() {
        if (window.innerWidth > 782) return;

        /* Try multiple selectors in order of specificity */
        var nav =
            document.querySelector('#header .nav') ||
            document.querySelector('#header div.nav') ||
            document.querySelector('div.nav') ||
            document.querySelector('#nav') ||
            document.querySelector('ul#nav');

        if (!nav) return;

        /* Ensure nav is the div wrapper, not the ul */
        if (nav.tagName.toLowerCase() === 'ul') {
            nav = nav.parentElement || nav;
        }

        /* Build hamburger button */
        var btn = document.createElement('button');
        btn.className = 'mrf-hamburger';
        btn.setAttribute('aria-label', 'Toggle navigation');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML =
            '<span class="mrf-bar"></span>' +
            '<span class="mrf-bar"></span>' +
            '<span class="mrf-bar"></span>';

        /* Insert before the nav element */
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
