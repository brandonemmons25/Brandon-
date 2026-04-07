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
        style.textContent = '.qs-fixed { position: fixed !important; z-index: 2147483647 !important; margin: 0 !important; }';
        sr.appendChild(style);

        // Matches a dropdown container class
        function isDropdownContainer(el) {
            var cls = el.className || '';
            return cls.indexOf('quick-search-price')         !== -1 ||
                   cls.indexOf('quick-search-bed-bath')      !== -1 ||
                   cls.indexOf('quick-search-property-type') !== -1;
        }

        // Given a panel (MuiPaper), find its sibling button via the shared parent container
        function findBtn(panel) {
            // Walk up to the dropdown container
            var el = panel;
            while (el && el !== sr) {
                if (isDropdownContainer(el)) {
                    return el.querySelector('button');
                }
                el = el.parentElement;
            }
            return null;
        }

        function positionPanel(panel) {
            var btn = findBtn(panel);
            if (!btn) return;
            var rect = btn.getBoundingClientRect();
            if (!rect.width) return; // not laid out yet
            panel.classList.add('qs-fixed');
            // position: fixed uses VIEWPORT coords — do NOT add scrollY
            panel.style.top  = rect.bottom + 'px';
            panel.style.left = rect.left   + 'px';
        }

        // Selector for open panels (direct child of dropdown container)
        var PANEL_SEL = '[class*="quick-search-price"] > [class*="MuiPaper"],'
                      + '[class*="quick-search-bed-bath"] > [class*="MuiPaper"],'
                      + '[class*="quick-search-property-type"] > [class*="MuiPaper"]';

        function applyAll() {
            sr.querySelectorAll(PANEL_SEL).forEach(function (panel) {
                if (!panel.classList.contains('qs-fixed')) {
                    positionPanel(panel);
                }
            });
        }

        // MutationObserver: React adds/removes panels (conditional render)
        var observer = new MutationObserver(function () {
            // Let React finish its render cycle, then position
            requestAnimationFrame(applyAll);
        });
        observer.observe(sr, { childList: true, subtree: true });

        // Polling fallback — catches anything the observer misses
        setInterval(applyAll, 150);

        // Apply to any panels already in DOM on load
        applyAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 500); });
    } else {
        setTimeout(init, 500);
    }
})();
