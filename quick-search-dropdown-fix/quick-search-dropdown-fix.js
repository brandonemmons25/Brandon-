(function () {
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        // Find the iHomeFinder shadow root
        var sr = null;
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        // Only the three real dropdown trigger buttons (confirmed by console diagnostics)
        var SELECTOR = '[class*="quick-search-price"] > button,'
                     + '[class*="quick-search-bed-bath"] > button,'
                     + '[class*="quick-search-property-type"] > button';

        var triggers = Array.from(sr.querySelectorAll(SELECTOR));
        if (!triggers.length) { setTimeout(init, 200); return; }

        // Inject helper class into shadow root
        var style = document.createElement('style');
        style.textContent = '.qs-fixed { position: fixed !important; z-index: 999999 !important; margin: 0 !important; }';
        sr.appendChild(style);

        // Track which buttons currently have open panels
        var openSet = new Set();

        function getPanel(btn) { return btn.nextElementSibling; }

        function applyFixed(btn) {
            var panel = getPanel(btn);
            if (!panel) return;
            var rect = btn.getBoundingClientRect();
            panel.classList.add('qs-fixed');
            panel.style.top  = rect.bottom + 'px';
            panel.style.left = rect.left   + 'px';
            openSet.add(btn);
        }

        function removeFixed(btn) {
            var panel = getPanel(btn);
            if (!panel) return;
            panel.classList.remove('qs-fixed');
            panel.style.top = panel.style.left = '';
            openSet.delete(btn);
        }

        triggers.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wasOpen = openSet.has(btn);

                // iHomeFinder closes other dropdowns when one opens — mirror that
                triggers.forEach(function (b) { if (b !== btn) removeFixed(b); });

                // Wait for iHF to update its own DOM, then sync fixed state
                setTimeout(function () {
                    if (wasOpen) {
                        removeFixed(btn);   // was open → iHF closed it
                    } else {
                        applyFixed(btn);    // was closed → iHF opened it
                    }
                }, 50);
            });
        });

        // Close all when clicking outside the search widget
        document.addEventListener('click', function (e) {
            if (!qs.contains(e.target)) {
                triggers.forEach(removeFixed);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
