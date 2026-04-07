(function () {
    function init() {
        var qs = document.getElementById('quick-search');
        if (!qs) return;

        // 1. Raise the iHomeFinder shadow host so its stacking context
        //    sits above .c-wrap (which is inside #slideshow z-index:1)
        var els = qs.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            if (els[i].shadowRoot) {
                els[i].style.position = 'relative';
                els[i].style.zIndex   = '99999';
                break;
            }
        }

        // 2. Watch document.body for React portal containers —
        //    the price dropdown renders outside the shadow DOM into body
        var bodyObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        node.style.zIndex = '99999';
                        node.style.position = node.style.position || 'relative';
                    }
                });
            });
        });
        bodyObserver.observe(document.body, { childList: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(init, 300); });
    } else {
        setTimeout(init, 300);
    }
})();
