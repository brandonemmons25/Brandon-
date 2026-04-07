(function () {
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        // Find the shadow host inside #quick-search and give it a
        // stacking context high enough to clear .c-wrap (z-index: 999
        // inside #slideshow z-index: 1 = effectively just above 1 in root).
        // #quick-search is already z-index: 99, so its shadow host just
        // needs to be explicitly positioned to inherit that advantage.
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) {
                els[i].style.position = 'relative';
                els[i].style.zIndex   = '99999';

                // Also inject a style into the shadow root to ensure
                // dropdown panels aren't clipped by internal stacking
                var style = document.createElement('style');
                style.textContent = '[aria-expanded="true"] + *, [aria-expanded="true"] ~ * { position: relative !important; z-index: 99999 !important; }';
                els[i].shadowRoot.appendChild(style);
                break;
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 300); });
    } else {
        setTimeout(init, 300);
    }
})();
