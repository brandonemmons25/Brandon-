/**
 * Mobile CSS Auditor — Probe v1.0.0
 *
 * Runs INSIDE the hidden iframe at ~375 px viewport width.
 * Collects computed styles, shadow DOM content, overflow sources,
 * navigation structure, and z-index stacks, then postMessages the
 * full report to the parent admin frame.
 */
(function () {
    'use strict';

    var VIEWPORT = window.innerWidth || 375;
    var MAX_WAIT  = 5000;   // ms — give iHF / React time to hydrate

    /* ── Utility: short CSS selector for an element ─────────────── */
    function shortSel(el) {
        var s = el.tagName.toLowerCase();
        if (el.id) {
            s += '#' + el.id;
        } else if (el.classList && el.classList.length) {
            var cls = [];
            for (var i = 0; i < Math.min(el.classList.length, 4); i++) {
                cls.push(el.classList[i]);
            }
            s += '.' + cls.join('.');
        }
        return s;
    }

    /* ── Capture computed style snapshot ────────────────────────── */
    function snapshot(el) {
        var c = window.getComputedStyle(el);
        return {
            selector:  shortSel(el),
            tag:       el.tagName.toLowerCase(),
            id:        el.id || null,
            classes:   Array.prototype.slice.call(el.classList || []),
            position:  c.position,
            display:   c.display,
            width:     c.width,
            maxWidth:  c.maxWidth,
            height:    c.height,
            overflow:  c.overflow,
            overflowX: c.overflowX,
            overflowY: c.overflowY,
            zIndex:    c.zIndex,
            float:     c.float,
            flexDir:   c.flexDirection,
            top:       c.top,
            left:      c.left,
            right:     c.right,
            bottom:    c.bottom,
            fontSize:  c.fontSize,
            color:     c.color,
            bgColor:   c.backgroundColor,
            scrollW:   el.scrollWidth,
            offsetW:   el.offsetWidth,
            offsetH:   el.offsetHeight,
        };
    }

    /* ── Walk a shadow root ──────────────────────────────────────── */
    function walkShadow(root) {
        var info = {
            classes:  [],
            ids:      [],
            elements: [],
            nested:   [],
        };

        var els = root.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            var e = els[i];

            /* IDs */
            if (e.id && info.ids.indexOf(e.id) === -1) {
                info.ids.push(e.id);
            }

            /* Class names */
            if (typeof e.className === 'string') {
                var parts = e.className.trim().split(/\s+/);
                for (var p = 0; p < parts.length; p++) {
                    if (parts[p] && info.classes.indexOf(parts[p]) === -1) {
                        info.classes.push(parts[p]);
                    }
                }
            }

            /* Capture layout info for elements that may cause mobile issues */
            var c = window.getComputedStyle(e);
            var w = parseInt(c.width, 10);
            var hasClass = typeof e.className === 'string' && e.className.trim().length > 0;

            if (e.id || hasClass) {
                if (
                    w > VIEWPORT ||
                    c.position === 'absolute' ||
                    c.position === 'fixed' ||
                    c.display === 'flex' ||
                    c.display === 'grid' ||
                    parseInt(c.zIndex, 10) > 10
                ) {
                    info.elements.push({
                        tag:      e.tagName.toLowerCase(),
                        id:       e.id || null,
                        classes:  typeof e.className === 'string'
                                    ? e.className.trim().split(/\s+/).slice(0, 8)
                                    : [],
                        display:  c.display,
                        position: c.position,
                        width:    c.width,
                        maxWidth: c.maxWidth,
                        flexDir:  c.flexDirection,
                        zIndex:   c.zIndex,
                        color:    c.color,
                        bgColor:  c.backgroundColor,
                        offsetH:  e.offsetHeight,
                    });
                }
            }

            /* Recurse nested shadow roots */
            if (e.shadowRoot) {
                info.nested.push({
                    host: shortSel(e),
                    data: walkShadow(e.shadowRoot),
                });
            }
        }

        return info;
    }

    /* ── Find all shadow roots in document ──────────────────────── */
    function findShadowRoots() {
        var found = [];
        var all   = document.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            if (all[i].shadowRoot) {
                found.push({
                    host: shortSel(all[i]),
                    data: walkShadow(all[i].shadowRoot),
                });
            }
        }
        return found;
    }

    /* ── Scan navigation elements ────────────────────────────────── */
    function scanNav() {
        var results = [];
        var navEls  = document.querySelectorAll(
            'nav, #header, #masthead, .site-header, .nav, #nav, ' +
            '.navigation, #navigation, ul.menu, ul.nav-menu'
        );

        var hamburgerSel = '.hamburger, .menu-toggle, .mobile-toggle, ' +
            '[class*="hamburger"], [class*="menu-toggle"], ' +
            '[aria-controls*="menu"], [aria-label*="menu"]';

        var hasHam = !!document.querySelector(hamburgerSel);

        for (var i = 0; i < navEls.length; i++) {
            var ne = navEls[i];
            var nc = window.getComputedStyle(ne);
            var items = ne.querySelectorAll('li');

            results.push({
                selector:     shortSel(ne),
                display:      nc.display,
                position:     nc.position,
                width:        nc.width,
                itemCount:    items.length,
                subMenuCount: ne.querySelectorAll('.sub-menu, .dropdown-menu, .children').length,
                hasHamburger: hasHam,
            });
        }

        return results;
    }

    /* ── Find elements causing horizontal overflow ───────────────── */
    function findOverflowCulprits() {
        var culprits = [];
        var ww       = window.innerWidth;
        var all      = document.querySelectorAll('*');

        for (var i = 0; i < all.length; i++) {
            try {
                var r = all[i].getBoundingClientRect();
                if (r.right > ww + 5) {
                    culprits.push({
                        selector: shortSel(all[i]),
                        right:    Math.round(r.right),
                        left:     Math.round(r.left),
                        width:    Math.round(r.width),
                        viewport: ww,
                        excess:   Math.round(r.right - ww),
                    });
                }
            } catch (e) { /* ignore detached nodes */ }
        }

        /* Deduplicate — keep worst offender per selector */
        var seen   = {};
        var unique = [];
        for (var j = 0; j < culprits.length; j++) {
            var s = culprits[j].selector;
            if (!seen[s]) {
                seen[s] = true;
                unique.push(culprits[j]);
            }
        }

        return unique.slice(0, 30);   // cap result set
    }

    /* ── Main scan ───────────────────────────────────────────────── */
    function scan() {
        var report = {
            url:         location.href,
            title:       document.title,
            viewport:    VIEWPORT,
            bodyScrollW: document.body.scrollWidth,
            windowW:     window.innerWidth,
            hasOverflow: document.body.scrollWidth > window.innerWidth + 5,

            elements:         [],
            shadowRoots:      [],
            navigation:       [],
            overflowCulprits: [],
            images:           [],
        };

        /* --- Targeted elements --- */
        var TARGET_SEL = [
            'html', 'body',
            '#header', 'header', '.site-header', '#masthead',
            '#footer', 'footer', '.site-footer', '#colophon', '.c-footer',
            '#slideshow', '.slideshow', '.hp-slideshow',
            '#quick-search', '.quick-search',
            '#hp-content', '.hp-content',
            '.ihf-container', '[class*="ihf-"]',
            '.c-wrap', '.content-wrap', '#content', 'main', '.main',
            'nav', '.nav', '#nav',
            'ul.fc', '.above-footer', '.footer-top',
            'img', 'video', 'iframe',
            '[style*="position"]', '[style*="z-index"]', '[style*="width"]',
        ].join(',');

        var els  = document.querySelectorAll(TARGET_SEL);
        var seen = {};

        for (var i = 0; i < els.length; i++) {
            var key = els[i].tagName + (els[i].id || '') + (els[i].className || '');
            if (seen[key]) continue;
            seen[key] = true;

            report.elements.push(snapshot(els[i]));

            /* Check for shadow roots on this element and its children */
            if (els[i].shadowRoot) {
                report.shadowRoots.push({
                    host: shortSel(els[i]),
                    data: walkShadow(els[i].shadowRoot),
                });
            }
        }

        /* --- Full shadow root search (catches dynamically added ones) --- */
        var deepShadows = findShadowRoots();
        /* Merge, dedup by host */
        var knownHosts  = {};
        report.shadowRoots.forEach(function (sr) { knownHosts[sr.host] = true; });
        deepShadows.forEach(function (sr) {
            if (!knownHosts[sr.host]) {
                report.shadowRoots.push(sr);
            }
        });

        report.navigation       = scanNav();
        report.overflowCulprits = report.hasOverflow ? findOverflowCulprits() : [];

        /* --- Images missing max-width --- */
        var imgs = document.querySelectorAll('img');
        for (var im = 0; im < imgs.length; im++) {
            var img = imgs[im];
            var ic  = window.getComputedStyle(img);
            if (img.offsetWidth > VIEWPORT || ic.maxWidth === 'none') {
                report.images.push({
                    selector: shortSel(img),
                    offsetW:  img.offsetWidth,
                    maxWidth: ic.maxWidth,
                    naturalW: img.naturalWidth || 0,
                    src:      img.src ? img.src.split('/').pop().split('?')[0] : '',
                });
            }
        }

        window.parent.postMessage({ type: 'MCA_REPORT', data: report }, '*');
    }

    /* ── Wait for page + dynamic content (React / iHF hydration) ── */
    var attempts = 0;

    function tryScan() {
        attempts++;
        var shadows = document.querySelectorAll('*');
        var foundShadow = false;
        for (var i = 0; i < shadows.length; i++) {
            if (shadows[i].shadowRoot) { foundShadow = true; break; }
        }

        /* If shadow DOM expected but not found yet, retry once more */
        var ihfHost = document.querySelector('.ihf-container, [class*="ihf-"]');
        if (ihfHost && !foundShadow && attempts < 3) {
            setTimeout(tryScan, 1500);
            return;
        }

        scan();
    }

    function init() {
        /* Wait for dynamic content */
        setTimeout(tryScan, 1500);

        /* Hard-cap: always send a report within MAX_WAIT */
        setTimeout(function () {
            try { scan(); } catch (e) { /* ignore if already sent */ }
        }, MAX_WAIT);
    }

    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init);
    }

})();
