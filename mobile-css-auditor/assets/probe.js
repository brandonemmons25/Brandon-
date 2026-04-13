/**
 * Mobile CSS Auditor — Probe v1.2.0
 *
 * Runs INSIDE the hidden iframe at ~375 px viewport width.
 * Collects deep computed style data, shadow DOM content, parent context,
 * background images, overflow sources, and navigation structure, then
 * postMessages the full report to the parent admin frame.
 */
(function () {
    'use strict';

    var VIEWPORT = window.innerWidth || 375;
    var MAX_WAIT  = 6000;

    /* ── Full CSS selector path (up to 4 ancestors) ─────────────── */
    function fullSel(el) {
        var parts = [];
        var node  = el;
        var depth = 0;
        while (node && node !== document.body && depth < 4) {
            var s = node.tagName.toLowerCase();
            if (node.id) {
                s += '#' + node.id;
                parts.unshift(s);
                break;   // ID is unique enough
            } else if (node.classList && node.classList.length) {
                var cls = Array.prototype.slice.call(node.classList).slice(0, 3);
                s += '.' + cls.join('.');
            }
            parts.unshift(s);
            node = node.parentElement;
            depth++;
        }
        return parts.join(' > ');
    }

    /* Short selector for dedup keys */
    function shortSel(el) {
        var s = el.tagName.toLowerCase();
        if (el.id) {
            s += '#' + el.id;
        } else if (el.classList && el.classList.length) {
            s += '.' + Array.prototype.slice.call(el.classList).slice(0, 4).join('.');
        }
        return s;
    }

    /* ── Capture computed style snapshot ────────────────────────── */
    function snapshot(el) {
        var c   = window.getComputedStyle(el);
        var par = el.parentElement;
        var parSel = par ? shortSel(par) : '';
        var bgImg = c.backgroundImage !== 'none' ? c.backgroundImage.substring(0, 80) : '';

        /* Detect inline style overrides */
        var inlineStyle = el.getAttribute('style') || '';

        /* Detect if element is visually hidden */
        var isHidden = (c.display === 'none' || c.visibility === 'hidden' ||
                        parseFloat(c.opacity) === 0 || el.offsetHeight === 0);

        /* Detect flex/grid children layout */
        var flexGrow   = c.flexGrow   || '';
        var flexShrink = c.flexShrink || '';
        var flexBasis  = c.flexBasis  || '';
        var gridCol    = c.gridColumn || '';
        var gridRow    = c.gridRow    || '';

        /* Text content preview */
        var textPreview = '';
        if (el.childElementCount === 0 && el.textContent) {
            textPreview = el.textContent.trim().substring(0, 60).replace(/\s+/g, ' ');
        }

        return {
            selector:     shortSel(el),
            fullPath:     fullSel(el),
            tag:          el.tagName.toLowerCase(),
            id:           el.id || '',
            classes:      Array.prototype.slice.call(el.classList || []).join(' '),
            parent:       parSel,

            /* Box model */
            position:     c.position,
            display:      c.display,
            width:        c.width,
            maxWidth:     c.maxWidth,
            minWidth:     c.minWidth,
            height:       c.height,
            maxHeight:    c.maxHeight,

            /* Overflow */
            overflow:     c.overflow,
            overflowX:    c.overflowX,
            overflowY:    c.overflowY,

            /* Stacking */
            zIndex:       c.zIndex,
            isolation:    c.isolation || '',

            /* Positioning offsets */
            top:          c.top,
            left:         c.left,
            right:        c.right,
            bottom:       c.bottom,

            /* Flex / Grid */
            flexDir:      c.flexDirection,
            flexWrap:     c.flexWrap,
            flexGrow:     flexGrow,
            flexShrink:   flexShrink,
            flexBasis:    flexBasis,
            justifyContent: c.justifyContent,
            alignItems:   c.alignItems,
            gridTemplateCols: c.gridTemplateColumns || '',
            gridCol:      gridCol,
            gridRow:      gridRow,

            /* Float */
            float:        c.float,

            /* Text */
            fontSize:     c.fontSize,
            lineHeight:   c.lineHeight,
            textAlign:    c.textAlign,
            whiteSpace:   c.whiteSpace,

            /* Color */
            color:        c.color,
            bgColor:      c.backgroundColor,
            bgImage:      bgImg,

            /* Measured sizes */
            scrollW:      el.scrollWidth,
            scrollH:      el.scrollHeight,
            offsetW:      el.offsetWidth,
            offsetH:      el.offsetHeight,

            /* Context */
            inlineStyle:  inlineStyle.substring(0, 120),
            isHidden:     isHidden,
            childCount:   el.childElementCount,
            textPreview:  textPreview,
        };
    }

    /* ── Walk a shadow root — deep capture ───────────────────────── */
    function walkShadow(root) {
        var info = {
            classes:      [],
            ids:          [],
            elements:     [],
            allClassList: [],   // every unique class name
            nested:       [],
        };

        var els = root.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            var e = els[i];

            if (e.id && info.ids.indexOf(e.id) === -1) {
                info.ids.push(e.id);
            }

            if (typeof e.className === 'string') {
                e.className.trim().split(/\s+/).forEach(function (c) {
                    if (c) {
                        if (info.classes.indexOf(c) === -1) info.classes.push(c);
                        if (info.allClassList.indexOf(c) === -1) info.allClassList.push(c);
                    }
                });
            }

            /* Capture every shadow element that might matter */
            var c = window.getComputedStyle(e);
            var w = parseInt(c.width, 10);
            var hasIdentifier = e.id || (typeof e.className === 'string' && e.className.trim());

            if (hasIdentifier) {
                info.elements.push({
                    tag:        e.tagName.toLowerCase(),
                    id:         e.id || '',
                    classes:    typeof e.className === 'string'
                                    ? e.className.trim().split(/\s+/).slice(0, 10).join(' ')
                                    : '',
                    display:    c.display,
                    position:   c.position,
                    width:      c.width,
                    maxWidth:   c.maxWidth,
                    height:     c.height,
                    flexDir:    c.flexDirection,
                    flexWrap:   c.flexWrap,
                    justifyContent: c.justifyContent,
                    alignItems: c.alignItems,
                    zIndex:     c.zIndex,
                    overflow:   c.overflow,
                    color:      c.color,
                    bgColor:    c.backgroundColor,
                    fontSize:   c.fontSize,
                    offsetW:    e.offsetWidth,
                    offsetH:    e.offsetHeight,
                    isWide:     w > VIEWPORT,
                    isAbsolute: c.position === 'absolute' || c.position === 'fixed',
                    isFlex:     c.display === 'flex' || c.display === 'inline-flex',
                    isGrid:     c.display === 'grid' || c.display === 'inline-grid',
                    isHidden:   c.display === 'none' || e.offsetHeight === 0,
                    inlineStyle: (e.getAttribute('style') || '').substring(0, 80),
                });
            }

            if (e.shadowRoot) {
                info.nested.push({ host: shortSel(e), data: walkShadow(e.shadowRoot) });
            }
        }

        return info;
    }

    /* ── Find ALL shadow roots in document ──────────────────────── */
    function findShadowRoots() {
        var found = [];
        var all   = document.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            if (all[i].shadowRoot) {
                found.push({
                    host: shortSel(all[i]),
                    hostFull: fullSel(all[i]),
                    data: walkShadow(all[i].shadowRoot),
                });
            }
        }
        return found;
    }

    /* ── Scan navigation ─────────────────────────────────────────── */
    function scanNav() {
        var results = [];
        var navEls  = document.querySelectorAll(
            'nav, #header, #masthead, .site-header, .nav, #nav, ' +
            '.navigation, #navigation, ul.menu, ul.nav-menu'
        );
        var hamSel = '.hamburger, .menu-toggle, .mobile-toggle, ' +
            '[class*="hamburger"], [class*="menu-toggle"], ' +
            '[aria-controls*="menu"], [aria-label*="menu"]';
        var hasHam = !!document.querySelector(hamSel);

        for (var i = 0; i < navEls.length; i++) {
            var ne = navEls[i];
            var nc = window.getComputedStyle(ne);
            results.push({
                selector:     shortSel(ne),
                display:      nc.display,
                position:     nc.position,
                width:        nc.width,
                itemCount:    ne.querySelectorAll('li').length,
                topLevelItems: ne.querySelectorAll(':scope > ul > li, :scope > li').length,
                subMenuCount: ne.querySelectorAll('.sub-menu, .dropdown-menu, .children').length,
                hasHamburger: hasHam,
                isHidden:     nc.display === 'none' || ne.offsetHeight === 0,
            });
        }
        return results;
    }

    /* ── Find horizontal overflow culprits ───────────────────────── */
    function findOverflowCulprits() {
        var ww       = window.innerWidth;
        var culprits = [];
        var seen     = {};
        var all      = document.querySelectorAll('*');

        for (var i = 0; i < all.length; i++) {
            try {
                var r = all[i].getBoundingClientRect();
                if (r.right > ww + 5) {
                    var key = shortSel(all[i]);
                    if (!seen[key]) {
                        seen[key] = true;
                        var c = window.getComputedStyle(all[i]);
                        culprits.push({
                            selector:  key,
                            fullPath:  fullSel(all[i]),
                            right:     Math.round(r.right),
                            left:      Math.round(r.left),
                            width:     Math.round(r.width),
                            excess:    Math.round(r.right - ww),
                            viewport:  ww,
                            position:  c.position,
                            display:   c.display,
                            maxWidth:  c.maxWidth,
                            inlineStyle: (all[i].getAttribute('style') || '').substring(0, 80),
                        });
                    }
                }
            } catch (e) { /* detached node */ }
        }
        return culprits.slice(0, 40);
    }

    /* ── Collect all unique class names on page ──────────────────── */
    function collectAllClasses() {
        var classes = {};
        var all     = document.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            if (typeof all[i].className === 'string') {
                all[i].className.trim().split(/\s+/).forEach(function (c) {
                    if (c) classes[c] = (classes[c] || 0) + 1;
                });
            }
        }
        /* Sort by frequency */
        return Object.keys(classes).sort(function (a, b) {
            return classes[b] - classes[a];
        }).slice(0, 100).map(function (c) {
            return { name: c, count: classes[c] };
        });
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
            docHeight:   document.body.scrollHeight,

            elements:         [],
            shadowRoots:      [],
            navigation:       [],
            overflowCulprits: [],
            images:           [],
            allClasses:       [],
        };

        var TARGET_SEL = [
            'html', 'body',
            '#header', 'header', '.site-header', '#masthead',
            '#footer', 'footer', '.site-footer', '#colophon', '.c-footer',
            '#slideshow', '.slideshow', '.hp-slideshow',
            '#quick-search', '.quick-search',
            '#hp-content', '.hp-content',
            '.ihf-container', '[class*="ihf-"]',
            '.c-wrap', '.content-wrap', '#content', '#primary', 'main', '.main',
            'nav', '.nav', '#nav', '#primary-menu',
            'ul.fc', '.above-footer', '.footer-top', '.footer-widgets',
            'section', 'article', '.entry-content', '.page-content',
            'img', 'video', 'iframe',
            '[style*="position"]', '[style*="z-index"]',
            '[style*="width"]', '[style*="overflow"]',
        ].join(',');

        var els  = document.querySelectorAll(TARGET_SEL);
        var seen = {};

        for (var i = 0; i < els.length; i++) {
            var key = els[i].tagName + '|' + (els[i].id || '') + '|' + (els[i].className || '');
            if (seen[key]) continue;
            seen[key] = true;
            report.elements.push(snapshot(els[i]));
        }

        /* Shadow roots */
        report.shadowRoots = findShadowRoots();

        report.navigation       = scanNav();
        report.overflowCulprits = report.hasOverflow ? findOverflowCulprits() : [];
        report.allClasses       = collectAllClasses();

        /* Images missing max-width */
        var imgs = document.querySelectorAll('img');
        for (var im = 0; im < imgs.length; im++) {
            var img = imgs[im];
            var ic  = window.getComputedStyle(img);
            if (img.offsetWidth > VIEWPORT || ic.maxWidth === 'none') {
                report.images.push({
                    selector:  shortSel(img),
                    fullPath:  fullSel(img),
                    src:       (img.src || '').split('/').pop().split('?')[0].substring(0, 60),
                    alt:       (img.alt || '').substring(0, 40),
                    offsetW:   img.offsetWidth,
                    offsetH:   img.offsetHeight,
                    naturalW:  img.naturalWidth  || 0,
                    naturalH:  img.naturalHeight || 0,
                    maxWidth:  ic.maxWidth,
                    width:     ic.width,
                    display:   ic.display,
                    isInline:  ic.display === 'inline',
                });
            }
        }

        window.parent.postMessage({ type: 'MCA_REPORT', data: report }, '*');
    }

    /* ── Retry until shadow DOM hydrates ────────────────────────── */
    var attempts = 0;

    function tryScan() {
        attempts++;
        var ihfHost     = document.querySelector('.ihf-container, [class*="ihf-"]');
        var hasShadow   = false;
        var all         = document.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            if (all[i].shadowRoot) { hasShadow = true; break; }
        }
        if (ihfHost && !hasShadow && attempts < 4) {
            setTimeout(tryScan, 1500);
            return;
        }
        scan();
    }

    function init() {
        setTimeout(tryScan, 1500);
        /* Hard cap */
        setTimeout(function () { try { scan(); } catch (e) {} }, MAX_WAIT);
    }

    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init);
    }

})();
