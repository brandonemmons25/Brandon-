(function () {
    'use strict';

    function init() {
        var panel = document.querySelector('#header .panel');
        var nav   = document.querySelector('#header .nav');
        if (!panel || !nav) return;

        /* Inject hamburger button */
        var btn = document.createElement('button');
        btn.className = 'mob-toggle';
        btn.setAttribute('aria-label', 'Toggle navigation');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '<span></span><span></span><span></span>';
        panel.appendChild(btn);

        /* Toggle */
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = nav.classList.toggle('nav-open');
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        /* Close on outside tap */
        document.addEventListener('click', function (e) {
            if (!nav.contains(e.target) && !btn.contains(e.target)) {
                nav.classList.remove('nav-open');
                btn.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });

        /* Close when a nav link is tapped */
        nav.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () {
                nav.classList.remove('nav-open');
                btn.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
