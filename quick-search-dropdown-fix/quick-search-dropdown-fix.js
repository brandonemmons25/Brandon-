(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var qs        = document.getElementById('quick-search');
        var ctTagline = document.querySelector('.ct-tagline');
        if (!qs || !ctTagline) return;

        function lowerWave()   { ctTagline.style.zIndex = '0'; }
        function restoreWave() { ctTagline.style.zIndex = ''; }

        // Walk #quick-search children to find the iHomeFinder shadow root
        function findShadowRoot() {
            var els = qs.querySelectorAll('*');
            for (var i = 0; i < els.length; i++) {
                if (els[i].shadowRoot) return els[i].shadowRoot;
            }
            return null;
        }

        function attachObserver(sr) {
            var observer = new MutationObserver(function () {
                // iHomeFinder sets aria-expanded="true" on the trigger button
                // when a dropdown is open
                var isOpen = !!sr.querySelector('[aria-expanded="true"]');
                if (isOpen) {
                    lowerWave();
                } else {
                    restoreWave();
                }
            });

            observer.observe(sr, {
                subtree: true,
                attributes: true,
                attributeFilter: ['aria-expanded', 'class'],
                childList: true,
            });
        }

        // iHomeFinder renders asynchronously — poll until the shadow root exists
        function init() {
            var sr = findShadowRoot();
            if (sr) {
                attachObserver(sr);
            } else {
                setTimeout(init, 150);
            }
        }

        init();
    });
})();
