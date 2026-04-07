(function () {
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        // Find the iHomeFinder shadow root
        var shadowHost = null, sr = null;
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) { shadowHost = els[i]; sr = els[i].shadowRoot; break; }
        }
        if (!sr) { setTimeout(init, 200); return; }

        // Inject a class we can toggle on dropdown panels
        var style = document.createElement('style');
        style.textContent = '.qs-fixed { position: fixed !important; z-index: 999999 !important; margin: 0 !important; }';
        sr.appendChild(style);

        // For each button, its dropdown panels are the next sibling elements
        function getSiblingPanels(button) {
            var panels = [], el = button.nextElementSibling;
            while (el) { panels.push(el); el = el.nextElementSibling; }
            return panels;
        }

        function openPanel(button) {
            var rect = button.getBoundingClientRect();
            getSiblingPanels(button).forEach(function (panel) {
                panel.classList.add('qs-fixed');
                panel.style.top  = rect.bottom + 'px';
                panel.style.left = rect.left + 'px';
            });
        }

        function closePanel(button) {
            getSiblingPanels(button).forEach(function (panel) {
                panel.classList.remove('qs-fixed');
                panel.style.top = panel.style.left = '';
            });
        }

        function refresh() {
            sr.querySelectorAll('button').forEach(function (btn) {
                // Detect open state via aria-expanded …
                var expanded = btn.getAttribute('aria-expanded') === 'true';

                // … or via a visible sibling panel (non-zero height, not hidden)
                if (!btn.hasAttribute('aria-expanded')) {
                    var first = btn.nextElementSibling;
                    if (first) {
                        var s = window.getComputedStyle(first);
                        expanded = s.display !== 'none' && s.visibility !== 'hidden'
                                   && first.offsetHeight > 10
                                   && !first.classList.contains('qs-fixed'); // avoid re-triggering
                    }
                }

                if (expanded) { openPanel(btn); } else { closePanel(btn); }
            });
        }

        // Watch shadow root for any state change
        var busy = false;
        var observer = new MutationObserver(function () {
            if (busy) return;
            busy = true;
            refresh();
            busy = false;
        });
        observer.observe(sr, { subtree: true, attributes: true, childList: true });

        // Backup: re-check 60 ms after any click inside the widget
        shadowHost.addEventListener('click', function () { setTimeout(refresh, 60); });

        // Close all panels when clicking outside
        document.addEventListener('click', function (e) {
            if (!qs.contains(e.target)) {
                sr.querySelectorAll('button').forEach(closePanel);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
