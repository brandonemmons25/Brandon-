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

        // Inject helper class into shadow root
        var style = document.createElement('style');
        style.textContent = '.qs-fixed { position: fixed !important; z-index: 999999 !important; margin: 0 !important; }';
        sr.appendChild(style);

        var openBtn = null;

        function applyFixed(btn) {
            var panel = btn.nextElementSibling;
            if (!panel) return;
            var rect = btn.getBoundingClientRect();
            panel.classList.add('qs-fixed');
            panel.style.top  = rect.bottom + 'px';
            panel.style.left = rect.left   + 'px';
            openBtn = btn;
        }

        function closeFixed() {
            if (openBtn) {
                var panel = openBtn.nextElementSibling;
                if (panel) {
                    panel.classList.remove('qs-fixed');
                    panel.style.top = panel.style.left = '';
                }
                openBtn = null;
            }
        }

        // Is this button one of the three dropdown triggers?
        function isTrigger(btn) {
            var c = btn.parentElement ? btn.parentElement.className : '';
            return c.indexOf('quick-search-price')         !== -1 ||
                   c.indexOf('quick-search-bed-bath')      !== -1 ||
                   c.indexOf('quick-search-property-type') !== -1;
        }

        // Delegate on the shadow ROOT — survives React re-renders of child buttons
        sr.addEventListener('click', function (e) {
            var el = e.target, triggerBtn = null;
            while (el && el !== sr) {
                if (el.tagName === 'BUTTON' && isTrigger(el)) { triggerBtn = el; break; }
                el = el.parentElement;
            }
            if (!triggerBtn) return;

            closeFixed(); // always close current panel first

            // React renders the panel ~1 frame after the click
            setTimeout(function () {
                if (triggerBtn.nextElementSibling) {
                    applyFixed(triggerBtn);
                }
            }, 80);
        });

        // Close when clicking outside the search widget
        document.addEventListener('click', function (e) {
            if (!qs.contains(e.target)) closeFixed();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 400); });
    } else {
        setTimeout(init, 400);
    }
})();
