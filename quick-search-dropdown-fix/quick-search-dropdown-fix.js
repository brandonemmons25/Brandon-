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

        // Inject helper style into shadow root
        var style = document.createElement('style');
        style.textContent = [
            '.qs-fixed {',
            '  position: fixed !important;',
            '  z-index: 2147483647 !important;',
            '  margin: 0 !important;',
            '  top: auto;',
            '  left: auto;',
            '}'
        ].join('\n');
        sr.appendChild(style);

        var PANEL_SELECTOR = '[class*="quick-search-price"] > [class*="MuiPaper"],'
                           + '[class*="quick-search-bed-bath"] > [class*="MuiPaper"],'
                           + '[class*="quick-search-property-type"] > [class*="MuiPaper"]';

        var BTN_SELECTOR = '[class*="quick-search-price"] > button,'
                         + '[class*="quick-search-bed-bath"] > button,'
                         + '[class*="quick-search-property-type"] > button';

        function positionPanel(panel) {
            // Find the sibling button
            var btn = panel.previousElementSibling;
            if (!btn || btn.tagName.toLowerCase() !== 'button') {
                // Try parent's button child
                btn = panel.parentElement && panel.parentElement.querySelector('button');
            }
            if (!btn) return;
            var rect = btn.getBoundingClientRect();
            panel.classList.add('qs-fixed');
            panel.style.top  = (rect.bottom + window.scrollY) + 'px';
            panel.style.left = rect.left + 'px';
            panel.style.width = '';
        }

        function clearFixed() {
            sr.querySelectorAll('.qs-fixed').forEach(function (el) {
                el.classList.remove('qs-fixed');
                el.style.top = '';
                el.style.left = '';
            });
        }

        // Watch the shadow root for panels being added (React conditional render)
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    // Check if it's a panel or contains a panel
                    if (node.matches && node.matches('[class*="MuiPaper"]')) {
                        positionPanel(node);
                    } else if (node.querySelectorAll) {
                        node.querySelectorAll('[class*="MuiPaper"]').forEach(positionPanel);
                    }
                });
                m.removedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    // When a panel is removed, clean up any lingering fixed panels
                    // (not strictly needed but keeps things tidy)
                });
            });
        });

        observer.observe(sr, { childList: true, subtree: true });

        // Also apply to any panels already open when the page loads
        sr.querySelectorAll(PANEL_SELECTOR).forEach(positionPanel);

        // Close dropdowns when clicking outside quick-search
        document.addEventListener('click', function (e) {
            if (!qs.contains(e.target) && !shadowHost.contains(e.target)) {
                clearFixed();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 500); });
    } else {
        setTimeout(init, 500);
    }
})();
