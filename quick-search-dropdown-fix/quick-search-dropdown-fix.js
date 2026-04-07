(function () {
    function raiseBodyPortals() {
        // Raise any direct body children that are positioned —
        // these are iHomeFinder React portal containers
        var children = document.body.children;
        for (var i = 0; i < children.length; i++) {
            var el = children[i];
            var pos = window.getComputedStyle(el).position;
            if (pos === 'fixed' || pos === 'absolute') {
                el.style.zIndex = '99999';
            }
        }
    }

    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        // 1. Raise the iHomeFinder shadow host
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) {
                els[i].style.position = 'relative';
                els[i].style.zIndex   = '99999';
                break;
            }
        }

        // 2. Raise any portal containers already in the DOM
        raiseBodyPortals();

        // 3. Watch for new portal containers added to body
        //    AND for existing ones getting content (subtree changes)
        var observer = new MutationObserver(raiseBodyPortals);
        observer.observe(document.body, {
            childList:  true,
            subtree:    true,
            attributes: true,
            attributeFilter: ['style', 'class'],
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 300); });
    } else {
        setTimeout(init, 300);
    }
})();
