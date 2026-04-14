/**
 * Mobile CSS Auditor — Probe v2.2.0
 *
 * Runs INSIDE the hidden iframe at 375 px viewport width.
 * Collects computed styles, shadow DOM, navigation structure,
 * overflow culprits, slider configs, pseudo-elements, decorative SVGs,
 * and a generic layout map of all body sections.
 * postMessages one report to the parent admin frame.
 */
(function () {
    'use strict';

    var VIEWPORT = 375;   // iframe is always set to 375px in CSS
    var MAX_WAIT  = 8000; // hard-cap safety net

    /* Guard: scan fires exactly once */
    var scanned = false;

    /* ── Full CSS selector path (up to 4 ancestors) ─────────────── */
    function fullSel(el) {
        var parts = [];
        var node  = el;
        var depth = 0;
        while (node && node !== document.documentElement && depth < 5) {
            var s = node.tagName.toLowerCase();
            if (node.id) {
                s += '#' + node.id;
                parts.unshift(s);
                break;
            } else if (node.classList && node.classList.length) {
                s += '.' + Array.prototype.slice.call(node.classList).slice(0, 3).join('.');
            }
            parts.unshift(s);
            node  = node.parentElement;
            depth++;
        }
        return parts.join(' > ');
    }

    /* Short selector for dedup / display */
    function shortSel(el) {
        var s = el.tagName.toLowerCase();
        if (el.id) {
            s += '#' + el.id;
        } else if (el.classList && el.classList.length) {
            s += '.' + Array.prototype.slice.call(el.classList).slice(0, 4).join('.');
        }
        return s;
    }

    /* ── Read slider data-* attributes ──────────────────────────── */
    function readDataAttrs(el) {
        var keys = [
            'data-soliloquy-config', 'data-soliloquy',
            'data-cycle-slides', 'data-cycle-fx', 'data-cycle-speed',
            'data-slick', 'data-owl-carousel',
            'data-swiper', 'data-options', 'data-autoplay',
        ];
        var out = {};
        keys.forEach(function (k) {
            var v = el.getAttribute(k);
            if (v) out[k] = v.substring(0, 300);
        });
        return Object.keys(out).length ? JSON.stringify(out) : '';
    }

    /* ── Capture computed style snapshot ────────────────────────── */
    function snapshot(el) {
        var c          = window.getComputedStyle(el);
        var par        = el.parentElement;
        var parSel     = par ? shortSel(par) : '';
        var bgImg      = c.backgroundImage !== 'none' ? c.backgroundImage.substring(0, 80) : '';
        var inlineStyle = el.getAttribute('style') || '';
        var isHidden   = (c.display === 'none' || c.visibility === 'hidden' ||
                          parseFloat(c.opacity) === 0 || el.offsetHeight === 0);
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
            position:     c.position,
            display:      c.display,
            width:        c.width,
            maxWidth:     c.maxWidth,
            minWidth:     c.minWidth,
            height:       c.height,
            maxHeight:    c.maxHeight,
            overflow:     c.overflow,
            overflowX:    c.overflowX,
            overflowY:    c.overflowY,
            zIndex:       c.zIndex,
            top:          c.top,
            left:         c.left,
            right:        c.right,
            bottom:       c.bottom,
            order:        c.order,
            marginTop:    c.marginTop,
            marginBottom: c.marginBottom,
            marginLeft:   c.marginLeft,
            marginRight:  c.marginRight,
            paddingTop:   c.paddingTop,
            paddingBottom: c.paddingBottom,
            flexDir:      c.flexDirection,
            flexWrap:     c.flexWrap,
            flexGrow:     c.flexGrow     || '',
            flexShrink:   c.flexShrink   || '',
            flexBasis:    c.flexBasis    || '',
            justifyContent: c.justifyContent,
            alignItems:   c.alignItems,
            gridTemplateCols: c.gridTemplateColumns || '',
            gridCol:      c.gridColumn   || '',
            gridRow:      c.gridRow      || '',
            float:        c.float,
            fontSize:     c.fontSize,
            lineHeight:   c.lineHeight,
            textAlign:    c.textAlign,
            whiteSpace:   c.whiteSpace,
            color:        c.color,
            bgColor:      c.backgroundColor,
            bgImage:      bgImg,
            scrollW:      el.scrollWidth,
            scrollH:      el.scrollHeight,
            offsetW:      el.offsetWidth,
            offsetH:      el.offsetHeight,
            inlineStyle:  inlineStyle.substring(0, 120),
            dataAttrs:    readDataAttrs(el),
            isHidden:     isHidden,
            childCount:   el.childElementCount,
            textPreview:  textPreview,
        };
    }

    /* ── Walk a shadow root ──────────────────────────────────────── */
    function walkShadow(root) {
        var info = { classes: [], ids: [], elements: [], allClassList: [], nested: [] };
        var els  = root.querySelectorAll('*');
        for (var i = 0; i < els.length; i++) {
            var e  = els[i];
            if (e.id && info.ids.indexOf(e.id) === -1) info.ids.push(e.id);
            if (typeof e.className === 'string') {
                e.className.trim().split(/\s+/).forEach(function (c) {
                    if (c) {
                        if (info.classes.indexOf(c)     === -1) info.classes.push(c);
                        if (info.allClassList.indexOf(c) === -1) info.allClassList.push(c);
                    }
                });
            }
            var cs = window.getComputedStyle(e);
            var w  = parseInt(cs.width, 10);
            if (e.id || (typeof e.className === 'string' && e.className.trim())) {
                info.elements.push({
                    tag: e.tagName.toLowerCase(), id: e.id || '',
                    classes: typeof e.className === 'string' ? e.className.trim().split(/\s+/).slice(0, 10).join(' ') : '',
                    display: cs.display, position: cs.position,
                    width: cs.width, maxWidth: cs.maxWidth, height: cs.height,
                    flexDir: cs.flexDirection, flexWrap: cs.flexWrap,
                    justifyContent: cs.justifyContent, alignItems: cs.alignItems,
                    zIndex: cs.zIndex, overflow: cs.overflow,
                    color: cs.color, bgColor: cs.backgroundColor, fontSize: cs.fontSize,
                    offsetW: e.offsetWidth, offsetH: e.offsetHeight,
                    isWide: w > VIEWPORT,
                    isAbsolute: cs.position === 'absolute' || cs.position === 'fixed',
                    isFlex: cs.display === 'flex' || cs.display === 'inline-flex',
                    isGrid: cs.display === 'grid' || cs.display === 'inline-grid',
                    isHidden: cs.display === 'none' || e.offsetHeight === 0,
                    inlineStyle: (e.getAttribute('style') || '').substring(0, 80),
                });
            }
            if (e.shadowRoot) info.nested.push({ host: shortSel(e), data: walkShadow(e.shadowRoot) });
        }
        return info;
    }

    /* ── Find all shadow roots ───────────────────────────────────── */
    function findShadowRoots() {
        var found = [];
        var all   = document.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            if (all[i].shadowRoot) {
                found.push({ host: shortSel(all[i]), hostFull: fullSel(all[i]), data: walkShadow(all[i].shadowRoot) });
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
        var hamSel  = '.hamburger, .menu-toggle, .mobile-toggle, ' +
            '[class*="hamburger"], [class*="menu-toggle"], ' +
            '[aria-controls*="menu"], [aria-label*="menu"], [aria-label*="navigation"]';
        var hasHam  = !!document.querySelector(hamSel);
        for (var i = 0; i < navEls.length; i++) {
            var ne = navEls[i];
            var nc = window.getComputedStyle(ne);
            results.push({
                selector:      shortSel(ne),
                fullPath:      fullSel(ne),
                display:       nc.display,
                position:      nc.position,
                width:         nc.width,
                offsetH:       ne.offsetHeight,
                itemCount:     ne.querySelectorAll('li').length,
                topLevelItems: ne.querySelectorAll(':scope > ul > li, :scope > li').length,
                subMenuCount:  ne.querySelectorAll('.sub-menu, .dropdown-menu, .children').length,
                hasHamburger:  hasHam,
                isHidden:      nc.display === 'none' || ne.offsetHeight === 0,
            });
        }
        return results;
    }

    /* ── Scan sliders / carousels ────────────────────────────────── */
    function scanSliders() {
        var sliders = [];

        /* Soliloquy */
        var solEls = document.querySelectorAll(
            '.soliloquy-container, .soliloquy-slider, [data-soliloquy-config], [id^="soliloquy"]'
        );
        for (var i = 0; i < solEls.length; i++) {
            var el     = solEls[i];
            var config = el.getAttribute('data-soliloquy-config') || '';
            var slides = el.querySelectorAll('li.soliloquy-item, .soliloquy-slide');
            var cs     = window.getComputedStyle(el);
            sliders.push({
                type:       'soliloquy',
                selector:   shortSel(el),
                fullPath:   fullSel(el),
                slideCount: slides.length,
                config:     config.substring(0, 400),
                autoplay:   /autoplay/i.test(config) ? 'YES — in data-soliloquy-config' : 'config not found in attribute',
                display:    cs.display,
                offsetH:    el.offsetHeight,
            });
        }

        /* Slick */
        var slickEls = document.querySelectorAll('.slick-slider, [data-slick]');
        for (var j = 0; j < slickEls.length; j++) {
            var se  = slickEls[j];
            var sc  = se.getAttribute('data-slick') || '';
            var scs = window.getComputedStyle(se);
            sliders.push({
                type:       'slick',
                selector:   shortSel(se),
                fullPath:   fullSel(se),
                slideCount: se.querySelectorAll('.slick-slide:not(.slick-cloned)').length,
                config:     sc.substring(0, 400),
                autoplay:   /autoplay/i.test(sc) ? 'YES — in data-slick' : 'not found in attribute',
                display:    scs.display,
                offsetH:    se.offsetHeight,
            });
        }

        /* Cycle2 */
        var cycleEls = document.querySelectorAll('[data-cycle-slides], .cycle-slideshow');
        for (var k = 0; k < cycleEls.length; k++) {
            var ce  = cycleEls[k];
            var cc  = ce.getAttribute('data-cycle-slides') || '';
            var ccs = window.getComputedStyle(ce);
            sliders.push({
                type:       'cycle2',
                selector:   shortSel(ce),
                fullPath:   fullSel(ce),
                slideCount: ce.querySelectorAll(cc || '.slide').length,
                config:     (ce.getAttribute('data-cycle-fx') || '') + ' speed=' + (ce.getAttribute('data-cycle-speed') || ''),
                autoplay:   ce.getAttribute('data-cycle-auto-height') || ce.getAttribute('data-cycle-pause-on-hover') || 'check cycle attrs',
                display:    ccs.display,
                offsetH:    ce.offsetHeight,
            });
        }

        return sliders;
    }

    /* ── Scan pseudo-elements (::before / ::after) ───────────────── */
    /* JS cannot see pseudo-elements as DOM nodes — this is why wave
     * decorators built with ::before/::after are invisible to normal
     * element scans. This function inspects computed styles of
     * pseudo-elements on every block-level element.               */
    function scanPseudoElements() {
        var found = [];
        var candidates = document.querySelectorAll(
            'body, #wrapper, #header, #masthead, .site-header, ' +
            '#slideshow, .hp-slideshow, .soliloquy-container, ' +
            '#hp-content, #footer, section, article, main, ' +
            '[id], [class*="wave"], [class*="hero"], [class*="banner"], [class*="section"]'
        );
        for (var i = 0; i < candidates.length; i++) {
            var el = candidates[i];
            try {
                var pseudos = ['::before', '::after'];
                for (var p = 0; p < pseudos.length; p++) {
                    var pc = window.getComputedStyle(el, pseudos[p]);
                    var content = pc.content;
                    /* Skip empty/none pseudo-elements */
                    if (!content || content === 'none' || content === 'normal' || content === '""' || content === "''") continue;
                    /* Skip if truly invisible */
                    if (pc.display === 'none' || pc.visibility === 'hidden') continue;
                    var h = parseFloat(pc.height) || 0;
                    var w = parseFloat(pc.width)  || 0;
                    if (h === 0 && w === 0) continue;

                    found.push({
                        selector:   shortSel(el) + pseudos[p],
                        fullPath:   fullSel(el) + pseudos[p],
                        pseudo:     pseudos[p],
                        content:    content.substring(0, 80),
                        display:    pc.display,
                        position:   pc.position,
                        width:      pc.width,
                        height:     pc.height,
                        bgColor:    pc.backgroundColor,
                        bgImage:    pc.backgroundImage !== 'none' ? pc.backgroundImage.substring(0, 120) : '',
                        zIndex:     pc.zIndex,
                        top:        pc.top,
                        left:       pc.left,
                    });
                }
            } catch (e) { /* some elements throw */ }
        }
        return found;
    }

    /* ── Scan decorative / wave elements ─────────────────────────── */
    function scanDecorativeElements() {
        var found = [];

        /* All SVG elements */
        var svgs = document.querySelectorAll('svg');
        for (var i = 0; i < svgs.length; i++) {
            var svg = svgs[i];
            var sc  = window.getComputedStyle(svg);
            if (sc.display === 'none') continue;
            var svgClasses = typeof svg.className === 'object'
                ? (svg.className.baseVal || '')
                : (svg.className || '');
            found.push({
                type:     'svg',
                selector: shortSel(svg),
                fullPath: fullSel(svg),
                classes:  svgClasses,
                display:  sc.display,
                position: sc.position,
                width:    sc.width,
                height:   sc.height,
                offsetW:  svg.offsetWidth,
                offsetH:  svg.offsetHeight,
                zIndex:   sc.zIndex,
            });
        }

        /* Elements with wave / divider in class or id */
        var waveEls = document.querySelectorAll(
            '[class*="wave"], [id*="wave"], [class*="Wave"], [id*="Wave"], ' +
            '[class*="divider"], [class*="Divider"], [class*="curve"], [class*="Curve"]'
        );
        for (var j = 0; j < waveEls.length; j++) {
            var we  = waveEls[j];
            var wc  = window.getComputedStyle(we);
            found.push({
                type:     'wave-class',
                selector: shortSel(we),
                fullPath: fullSel(we),
                classes:  Array.prototype.slice.call(we.classList || []).join(' '),
                display:  wc.display,
                position: wc.position,
                width:    wc.width,
                height:   wc.height,
                offsetW:  we.offsetWidth,
                offsetH:  we.offsetHeight,
                zIndex:   wc.zIndex,
                isHidden: wc.display === 'none' || we.offsetHeight === 0,
            });
        }

        return found;
    }

    /* ── Always find horizontal overflow culprits ────────────────── */
    function findOverflowCulprits() {
        var ww       = window.innerWidth;
        var culprits = [];
        var seen     = {};
        var all      = document.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            try {
                var r = all[i].getBoundingClientRect();
                if (r.right > ww + 2) {
                    var key = shortSel(all[i]);
                    if (!seen[key]) {
                        seen[key] = true;
                        var c = window.getComputedStyle(all[i]);
                        culprits.push({
                            selector:    key,
                            fullPath:    fullSel(all[i]),
                            right:       Math.round(r.right),
                            left:        Math.round(r.left),
                            width:       Math.round(r.width),
                            excess:      Math.round(r.right - ww),
                            viewport:    ww,
                            position:    c.position,
                            display:     c.display,
                            maxWidth:    c.maxWidth,
                            inlineStyle: (all[i].getAttribute('style') || '').substring(0, 80),
                        });
                    }
                }
            } catch (ignore) {}
        }
        return culprits.slice(0, 50);
    }

    /* ── Collect all unique class names ─────────────────────────── */
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
        return Object.keys(classes).sort(function (a, b) {
            return classes[b] - classes[a];
        }).slice(0, 100).map(function (c) { return { name: c, count: classes[c] }; });
    }

    /* ── Generic layout map ──────────────────────────────────────── */
    /* Walks 4 levels deep from every body child so wrapper > section >
     * sub-section > content structure is always captured regardless of IDs.
     * depth 0 = body children        (div#wrapper)
     * depth 1 = wrapper children     (div#header, div#slideshow, div#hp-content …)
     * depth 2 = section children     (div.panel, div.logo, div.nav, div.wave …)
     * depth 3 = sub-section children (div.welcome, div.hp-sidebar, div.clear …)
     * depth 4 = content children     (widgets, inner decorators, slider items …) */
    function scanLayout() {
        var sections = [];

        function captureEl(el, depth) {
            var c = window.getComputedStyle(el);
            return {
                depth:        depth,
                tagId:        el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''),
                selector:     shortSel(el),
                fullPath:     fullSel(el),
                classes:      Array.prototype.slice.call(el.classList || []).join(' '),
                display:      c.display,
                visibility:   c.visibility,
                opacity:      c.opacity,
                position:     c.position,
                float:        c.float,
                overflow:     c.overflow,
                order:        c.order,
                marginTop:    c.marginTop,
                marginBottom: c.marginBottom,
                paddingTop:   c.paddingTop,
                paddingBottom: c.paddingBottom,
                offsetW:      el.offsetWidth,
                offsetH:      el.offsetHeight,
                dataAttrs:    readDataAttrs(el),
                isHidden:     c.display === 'none' || c.visibility === 'hidden' ||
                              parseFloat(c.opacity) === 0 || el.offsetHeight === 0,
            };
        }

        var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, NOSCRIPT: 1 };

        function walk(el, depth, maxDepth) {
            if (depth > maxDepth) return;
            if (SKIP_TAGS[el.tagName]) return;
            sections.push(captureEl(el, depth));
            if (depth < maxDepth && el.children.length > 0 && el.children.length < 40) {
                for (var i = 0; i < el.children.length; i++) {
                    walk(el.children[i], depth + 1, maxDepth);
                }
            }
        }

        var bodyChildren = document.body ? document.body.children : [];
        for (var i = 0; i < bodyChildren.length; i++) {
            walk(bodyChildren[i], 0, 4);
        }

        /* Header HTML snapshot for exact nav selector debugging */
        var headerEl   = document.querySelector('#header, header, #masthead, .site-header');
        var headerHTML = headerEl
            ? headerEl.outerHTML.substring(0, 2000).replace(/\s+/g, ' ')
            : '';

        /* Loaded stylesheets (filenames only) */
        var sheets = [];
        var links  = document.querySelectorAll('link[rel="stylesheet"]');
        for (var li = 0; li < links.length; li++) {
            var href  = links[li].href || '';
            var fname = href.split('/').pop().split('?')[0];
            if (fname) sheets.push(fname);
        }

        return {
            sections:   sections,
            headerHTML: headerHTML,
            bodyClasses: document.body ? document.body.className : '',
            stylesheets: sheets,
        };
    }

    /* ── Main scan ───────────────────────────────────────────────── */
    function scan() {
        if (scanned) return;
        scanned = true;

        var report = {
            url:         location.href,
            title:       document.title,
            viewport:    window.innerWidth,
            bodyScrollW: document.body ? document.body.scrollWidth : 0,
            windowW:     window.innerWidth,
            hasOverflow: document.body ? document.body.scrollWidth > window.innerWidth + 5 : false,
            docHeight:   document.body ? document.body.scrollHeight : 0,
            elements:         [],
            shadowRoots:      [],
            navigation:       [],
            overflowCulprits: [],
            images:           [],
            allClasses:       [],
            sliders:          [],
            pseudoElements:   [],
            decorative:       [],
            layout:           null,
        };

        /* Elements to inspect */
        var TARGET_SEL = [
            'html', 'body',
            '#header', 'header', '.site-header', '#masthead',
            '#footer', 'footer', '.site-footer', '#colophon',
            'nav', '.nav', '#nav', 'ul.menu', 'ul.nav-menu', '#primary-menu',
            '#content', '#primary', 'main', '.main', '.site-main',
            '.entry-content', '.page-content', 'article', 'section',
            'img', 'video', 'iframe',
            '#wrapper', '#page', '#outer-wrapper', '#inner-wrapper',
            '#content-area', '#content-wrap', '#main-content',
            '.ihf-container', '[class*="ihf-"]',
            '[id]',
        ].join(',');

        var els  = document.querySelectorAll(TARGET_SEL);
        var seen = {};

        for (var i = 0; i < els.length; i++) {
            var el  = els[i];
            var cls = Array.prototype.slice.call(el.classList || []).slice(0, 5).join('.');
            var key = el.tagName + '||' + (el.id || '') + '||' + cls;
            if (seen[key]) continue;
            seen[key] = true;
            report.elements.push(snapshot(el));
        }

        report.shadowRoots      = findShadowRoots();
        report.navigation       = scanNav();
        report.layout           = scanLayout();
        report.overflowCulprits = findOverflowCulprits();
        report.allClasses       = collectAllClasses();
        report.sliders          = scanSliders();
        report.pseudoElements   = scanPseudoElements();
        report.decorative       = scanDecorativeElements();

        /* Images missing max-width */
        var imgs = document.querySelectorAll('img');
        for (var im = 0; im < imgs.length; im++) {
            var img = imgs[im];
            var ic  = window.getComputedStyle(img);
            if (img.offsetWidth > VIEWPORT || ic.maxWidth === 'none') {
                report.images.push({
                    selector: shortSel(img),
                    fullPath: fullSel(img),
                    src:      (img.src || '').split('/').pop().split('?')[0].substring(0, 60),
                    alt:      (img.alt || '').substring(0, 40),
                    offsetW:  img.offsetWidth,
                    offsetH:  img.offsetHeight,
                    naturalW: img.naturalWidth  || 0,
                    naturalH: img.naturalHeight || 0,
                    maxWidth: ic.maxWidth,
                    width:    ic.width,
                    display:  ic.display,
                });
            }
        }

        window.parent.postMessage({ type: 'MCA_REPORT', data: report }, '*');
    }

    /* ── Wait for iHF shadow DOM to hydrate, then scan ──────────── */
    var attempts = 0;

    function tryScan() {
        if (scanned) return;
        attempts++;
        var ihfHost   = document.querySelector('.ihf-container, [class*="ihf-"]');
        var hasShadow = false;
        if (ihfHost) {
            var all = document.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                if (all[i].shadowRoot) { hasShadow = true; break; }
            }
        }
        if (ihfHost && !hasShadow && attempts < 5) {
            setTimeout(tryScan, 1500);
            return;
        }
        scan();
    }

    function init() {
        setTimeout(tryScan, 1500);
        setTimeout(function () {
            if (!scanned) scan();
        }, MAX_WAIT);
    }

    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init);
    }

})();
