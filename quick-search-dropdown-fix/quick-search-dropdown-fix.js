(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var qs        = document.getElementById('quick-search');
        var ctTagline = document.querySelector('.ct-tagline');
        if (!qs || !ctTagline) return;

        // While the user is interacting with #quick-search, temporarily
        // pull .ct-tagline (z-index: 11) out of the way so iHomeFinder
        // dropdown panels can render above the wave. Restores on outside click.

        function lowerWave()   { ctTagline.style.zIndex = '0'; }
        function restoreWave() { ctTagline.style.zIndex = ''; }

        // Use mousedown (fires before click) to flag that the upcoming
        // click is inside #quick-search. This lets iHomeFinder's own
        // click handlers receive the event unmodified — no stopPropagation.
        var clickedInside = false;

        qs.addEventListener('mousedown', function () {
            clickedInside = true;
        });

        document.addEventListener('click', function () {
            if (clickedInside) {
                lowerWave();
                clickedInside = false;
            } else {
                restoreWave();
            }
        });
    });
})();
