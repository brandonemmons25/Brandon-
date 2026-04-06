(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var qs       = document.getElementById('quick-search');
        var ctTagline = document.querySelector('.ct-tagline');
        if (!qs || !ctTagline) return;

        // While the user is interacting with #quick-search, temporarily
        // pull .ct-tagline (z-index: 11) out of the way so iHomeFinder
        // dropdown panels (inside a shadow DOM) can render above the wave.
        // The wave restores the instant the user clicks outside.

        function lowerWave() {
            ctTagline.style.zIndex = '0';
        }

        function restoreWave() {
            ctTagline.style.zIndex = '';
        }

        // Clicks inside #quick-search bubble up from the shadow DOM
        qs.addEventListener('click', function (e) {
            lowerWave();
            e.stopPropagation(); // prevent document handler on this same click
        });

        // Click anywhere outside #quick-search → restore
        document.addEventListener('click', restoreWave);

        // Also restore if focus moves away from #quick-search
        document.addEventListener('focusin', function (e) {
            if (!qs.contains(e.target)) {
                restoreWave();
            }
        });
    });
})();
