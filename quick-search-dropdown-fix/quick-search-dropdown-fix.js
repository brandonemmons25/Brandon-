(function () {
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        var shadowHost = null, sr = null;
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { shadowHost = els[i]; sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        // Inject helper class into shadow root
        var style = document.createElement('style');
        style.textContent = '.qs-fixed { position: fixed !important; z-index: 999999 !important; margin: 0 !important; }';
        sr.appendChild(style);

        var SELECTOR = '[class*="quick-search-price"] > button,'
                     + '[class*="quick-search-bed-bath"] > button,'
                     + '[class*="quick-search-property-type"] > button';

        function applyFixed(btn, panel) {
            var rect = btn.getBoundingClientRect();
            panel.classList.add('qs-fixed');
            panel.style.top  = rect.bottom + 'px';
            panel.style.left = rect.left   + 'px';
        }

        function clearFixed() {
            sr.querySelectorAll('.qs-fixed').forEach(function (el) {
                el.classList.remove('qs-fixed');
                el.style.top = el.style.left = '';
            });
        }

        function refresh() {
            clearFixed();
            sr.querySelectorAll(SELECTOR).forEach(function (btn) {
                var panel = btn.nextElementSibling;
                if (panel) applyFixed(btn, panel);
            });
        }

        // shadowHost is a regular light-DOM element — click events
        // from inside the shadow DOM bubble out to it reliably
        shadowHost.addEventListener('click', function () {
            setTimeout(refresh, 80);
        });

        document.addEventListener('click', function (e) {
            if (!qs.contains(e.target)) clearFixed();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
