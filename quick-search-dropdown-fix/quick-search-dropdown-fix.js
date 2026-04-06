(function () {
    var qs = document.getElementById('quick-search');
    if (!qs) return;

    // Watch for any descendant element getting/losing a class that
    // indicates an open dropdown. iHomeFinder adds classes like
    // "open", "is-open", "active", "is-active" to dropdown triggers
    // or their containers, and also inserts/removes dropdown panels.
    var observer = new MutationObserver(function () {
        var open =
            // iHomeFinder Kestrel / React-based dropdowns
            qs.querySelector('[class*="dropdown"][class*="open"]') ||
            qs.querySelector('[class*="dropdown"][class*="active"]') ||
            qs.querySelector('[class*="dropdown"][class*="show"]') ||
            // Visible dropdown list containers
            qs.querySelector('[class*="menu"][class*="open"]') ||
            qs.querySelector('[class*="menu"][class*="show"]') ||
            qs.querySelector('[class*="panel"][class*="open"]') ||
            // Bootstrap-style dropdowns
            qs.querySelector('.open > .dropdown-menu') ||
            qs.querySelector('.dropdown-menu.show') ||
            // Generic: any absolutely positioned visible child
            // that appeared after the initial render
            qs.querySelector('[class*="ihf"][class*="open"]') ||
            qs.querySelector('[class*="ihf"][class*="active"]') ||
            qs.querySelector('[aria-expanded="true"]');

        qs.classList.toggle('qs-dropdown-open', !!open);
    });

    observer.observe(qs, {
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'aria-expanded'],
        childList: true,
    });

    // Also close on outside click
    document.addEventListener('click', function (e) {
        if (!qs.contains(e.target)) {
            qs.classList.remove('qs-dropdown-open');
        }
    });
})();
