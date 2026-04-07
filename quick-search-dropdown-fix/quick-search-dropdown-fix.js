(function () {
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        var sr = null;
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        // Inject z-index into the shadow root so panels beat .c-wrap (z-index:999)
        // inside #slideshow. We do NOT change position — panels stay where React puts them.
        var style = document.createElement('style');
        style.textContent = [
            '[class*="quick-search-price"] > [class*="MuiPaper"],',
            '[class*="quick-search-bed-bath"] > [class*="MuiPaper"],',
            '[class*="quick-search-property-type"] > [class*="MuiPaper"] {',
            '  z-index: 2147483647 !important;',
            '  position: relative !important;',
            '}'
        ].join('\n');
        sr.appendChild(style);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
