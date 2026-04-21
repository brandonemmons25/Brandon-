/**
 * Mobile Nav Toggle — Dick Sakowicz site
 * Add via: Appearance > Theme Editor > functions.php  (wp_footer hook)
 * OR paste into a Custom HTML widget set to footer
 * OR enqueue as a child theme script
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {

        var icon = document.querySelector('.responsive-menu-icon');
        var menu = document.getElementById('menu-main-navigation');

        if (!icon || !menu) return;

        icon.addEventListener('click', function () {
            var open = menu.classList.toggle('menu-open');
            icon.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        /* Sub-menu toggles on mobile */
        var items = menu.querySelectorAll('.menu-item-has-children > a');
        items.forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (window.innerWidth >= 1024) return;
                e.preventDefault();
                var li = this.parentElement;
                li.classList.toggle('open');
            });
        });

    });
})();
