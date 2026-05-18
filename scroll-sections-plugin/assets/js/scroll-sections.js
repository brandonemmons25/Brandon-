/**
 * Scroll Sections v4.0.0
 *
 * Native browser scroll with lerp-based parallax, scroll-triggered
 * content reveals, video play/pause, nav dots, and progress bar.
 *
 * Zero dependencies.
 */
(function () {
    'use strict';

    /* ---- Config ---- */
    var LERP      = 0.08;   // parallax smoothing (lower = smoother)
    var REVEAL_AT = 0.80;   // reveal when section top is at 80% of viewport

    /* ---- State ---- */
    var wrap, secs, nav, dots, prog;
    var pxTargets = [];      // { el, section, speed, cur, dest }
    var depthEls  = [];      // { el, section, depth, startZ, curZ, destZ, curScale, destScale }
    var rafId     = null;
    var ticking   = false;

    /* ---- Reduced motion ---- */
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ==================================================================
       Force Full Width — override any parent container constraints
       ================================================================== */

    function forceFullWidth() {
        var el = document.getElementById('ss-wrap');
        if (!el) return;

        // Walk up the DOM and force every parent container to allow overflow
        var parent = el.parentElement;
        while (parent && parent !== document.body && parent !== document.documentElement) {
            var style = window.getComputedStyle(parent);
            // If parent has overflow hidden or a constraining max-width, override
            if (style.overflow === 'hidden' || style.overflowX === 'hidden') {
                parent.style.overflow = 'visible';
                parent.style.overflowX = 'visible';
            }
            parent = parent.parentElement;
        }

        // Position the wrapper to span full viewport width
        var rect = el.getBoundingClientRect();
        el.style.width = '100vw';
        el.style.maxWidth = '100vw';
        el.style.marginLeft = (-rect.left) + 'px';
        el.style.boxSizing = 'border-box';
    }

    /* ==================================================================
       Init
       ================================================================== */

    function init() {
        wrap = document.getElementById('ss-wrap');
        if (!wrap) return;

        // Force full-width: bust out of any theme container
        forceFullWidth();

        secs = wrap.querySelectorAll('.ss-sec');
        nav  = document.getElementById('ss-nav');
        dots = wrap.querySelectorAll('.ss-dot');
        prog = document.getElementById('ss-prog');

        if (!secs.length) return;

        // Set up each section
        secs.forEach(function (sec) {
            var dur    = sec.getAttribute('data-dur')  || '1.2';
            var del    = parseFloat(sec.getAttribute('data-del')  || '0');
            var stag   = parseFloat(sec.getAttribute('data-stag') || '0.15');
            var ease   = sec.getAttribute('data-ease') || 'cubic-bezier(0.25,0.46,0.45,0.94)';

            // Apply stagger transitions to each child
            var children = sec.querySelectorAll('.ss-child');
            children.forEach(function (child, idx) {
                var d = del + idx * stag;
                child.style.transition =
                    'opacity ' + dur + 's ' + ease + ' ' + d + 's, ' +
                    'transform ' + dur + 's ' + ease + ' ' + d + 's, ' +
                    'filter ' + dur + 's ' + ease + ' ' + d + 's, ' +
                    'clip-path ' + dur + 's ' + ease + ' ' + d + 's';
            });

            // Build parallax target
            var bg = sec.querySelector('.ss-bg');
            if (bg) {
                var speed = parseFloat(sec.getAttribute('data-parallax') || '0.3');
                pxTargets.push({ el: bg, section: sec, speed: speed, cur: 0, dest: 0 });
            }

            // Build 3D depth targets
            sec.querySelectorAll('.ss-3d').forEach(function (el3d) {
                var depth = parseFloat(el3d.getAttribute('data-depth') || '0.5');
                depthEls.push({
                    el: el3d,
                    section: sec,
                    depth: depth,
                    curScale: 0.4,
                    destScale: 0.4,
                    curOpacity: 0,
                    destOpacity: 0
                });
            });
        });

        // Reduced motion: reveal everything immediately
        if (reducedMotion) {
            secs.forEach(function (s) { s.classList.add('ss-visible'); });
            return;
        }

        // Smooth scroll for nav dot clicks
        if ('scrollBehavior' in document.documentElement.style) {
            document.documentElement.style.scrollBehavior = 'smooth';
        }

        // Bind
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                var idx = parseInt(this.getAttribute('data-idx'), 10);
                if (secs[idx]) {
                    secs[idx].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Initial pass
        onScroll();

        // Apply initial 3D depth state immediately (skip lerp for first frame)
        depthEls.forEach(function (d) {
            d.curScale = d.destScale;
            d.curOpacity = d.destOpacity;
            var isShape = d.el.classList.contains('ss-3d-shape');
            if (isShape) {
                d.el.style.setProperty('--ss-scale', d.curScale.toFixed(3));
            } else {
                d.el.style.transform = 'scale(' + d.curScale.toFixed(3) + ')';
            }
            d.el.style.opacity = d.curOpacity.toFixed(3);
            d.el.style.visibility = d.curOpacity < 0.01 ? 'hidden' : 'visible';
        });

        // Start parallax loop
        rafId = requestAnimationFrame(parallaxLoop);
    }

    /* ==================================================================
       Scroll Handler
       ================================================================== */

    function onScroll() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
            update();
            ticking = false;
        });
    }

    function update() {
        var scrollY = window.pageYOffset || document.documentElement.scrollTop;
        var viewH   = window.innerHeight;

        doReveals(scrollY, viewH);
        doParallaxTargets(scrollY, viewH);
        doDepthTargets(scrollY, viewH);
        doNav(scrollY, viewH);
        doProgress(scrollY, viewH);
        doVisibility(scrollY, viewH);
    }

    /* ==================================================================
       Reveals
       ================================================================== */

    function doReveals(scrollY, viewH) {
        var trigger = scrollY + viewH * REVEAL_AT;

        secs.forEach(function (sec) {
            var rect   = sec.getBoundingClientRect();
            var top    = scrollY + rect.top;
            var bottom = top + rect.height;

            var inView = (top < trigger) && (bottom > scrollY + viewH * 0.1);

            if (inView && !sec.classList.contains('ss-visible')) {
                sec.classList.add('ss-visible');
                videoControl(sec, true);
            } else if (!inView && sec.classList.contains('ss-visible')) {
                sec.classList.remove('ss-visible');
                videoControl(sec, false);
            }
        });
    }

    /* ==================================================================
       Parallax (lerp loop)
       ================================================================== */

    function doParallaxTargets(scrollY) {
        if (window.innerWidth <= 768) return;

        pxTargets.forEach(function (p) {
            if (p.speed === 0) return;
            var rect   = p.section.getBoundingClientRect();
            var secTop = window.pageYOffset + rect.top;
            var offset = window.pageYOffset - secTop;
            p.dest = offset * p.speed * 0.4;
        });
    }

    function parallaxLoop() {
        pxTargets.forEach(function (p) {
            if (p.speed === 0) return;
            p.cur += (p.dest - p.cur) * LERP;
            if (Math.abs(p.dest - p.cur) < 0.1) p.cur = p.dest;
            p.el.style.transform = 'translate3d(0,' + p.cur + 'px,0)';
        });

        // Animate 3D depth elements
        depthLerp();

        rafId = requestAnimationFrame(parallaxLoop);
    }

    /* ==================================================================
       3D Depth — elements fly toward the user as you scroll
       ================================================================== */

    function doDepthTargets(scrollY, viewH) {
        // Winner-takes-all: find the single most-centered section
        var bestSection = null;
        var bestCenter  = 0;
        var viewCenter  = viewH / 2;

        secs.forEach(function (sec) {
            var rect = sec.getBoundingClientRect();
            var secH = rect.height || viewH;
            var secCenter = rect.top + secH / 2;
            var dist = Math.abs(secCenter - viewCenter);
            var maxDist = (viewH + secH) / 2;
            var c = Math.max(0, 1 - dist / maxDist);
            if (c > bestCenter) {
                bestCenter  = c;
                bestSection = sec;
            }
        });

        depthEls.forEach(function (d) {
            // Only the winning section's elements are visible
            if (d.section !== bestSection) {
                d.destOpacity = 0;
                d.destScale = 0.4;
                return;
            }

            var eased = bestCenter * bestCenter * (3 - 2 * bestCenter);
            d.destOpacity = eased;
            d.destScale = 0.4 + (0.9 * d.depth * eased);
        });
    }

    function depthLerp() {
        depthEls.forEach(function (d) {
            d.curScale += (d.destScale - d.curScale) * LERP;
            d.curOpacity += (d.destOpacity - d.curOpacity) * LERP;

            if (Math.abs(d.destScale - d.curScale) < 0.001) d.curScale = d.destScale;
            if (Math.abs(d.destOpacity - d.curOpacity) < 0.005) d.curOpacity = d.destOpacity;

            // Shapes use CSS custom property (so CSS rotation animation isn't overridden)
            // Text/image use direct transform
            var isShape = d.el.classList.contains('ss-3d-shape');
            if (isShape) {
                d.el.style.setProperty('--ss-scale', d.curScale.toFixed(3));
            } else {
                d.el.style.transform = 'scale(' + d.curScale.toFixed(3) + ')';
            }
            d.el.style.opacity = d.curOpacity.toFixed(3);

            // Hide completely when invisible to prevent overlap
            d.el.style.visibility = d.curOpacity < 0.01 ? 'hidden' : 'visible';
        });
    }

    /* ==================================================================
       Video Play / Pause
       ================================================================== */

    function videoControl(sec, entering) {
        var mode = sec.getAttribute('data-vidauto');

        // Background video
        var bgVid = sec.querySelector('.ss-bg-vid video');
        if (bgVid) {
            try { entering ? bgVid.play() : bgVid.pause(); } catch (e) {}
        }

        // Inline videos
        if (mode === 'on_scroll' || mode === 'autoplay') {
            // HTML5 video
            sec.querySelectorAll('.ss-vid').forEach(function (v) {
                if (entering) {
                    v.muted = true;
                    try { v.play(); } catch (e) {}
                } else {
                    try { v.pause(); } catch (e) {}
                }
            });

            // iframes (YouTube / Vimeo postMessage API)
            sec.querySelectorAll('.ss-vembed iframe').forEach(function (iframe) {
                try {
                    if (entering) {
                        iframe.contentWindow.postMessage('{"method":"play"}', '*');
                        iframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', '*');
                    } else {
                        iframe.contentWindow.postMessage('{"method":"pause"}', '*');
                        iframe.contentWindow.postMessage('{"event":"command","func":"pauseVideo","args":""}', '*');
                    }
                } catch (e) {}
            });
        }
    }

    /* ==================================================================
       Nav Dots & Progress
       ================================================================== */

    function doNav(scrollY, viewH) {
        var mid = scrollY + viewH * 0.5;
        var activeIdx = 0;

        secs.forEach(function (sec, i) {
            var rect = sec.getBoundingClientRect();
            if (scrollY + rect.top <= mid) activeIdx = i;
        });

        dots.forEach(function (dot, i) {
            dot.classList.toggle('ss-dot-on', i === activeIdx);
        });
    }

    function doProgress(scrollY, viewH) {
        if (!prog) return;
        var rect     = wrap.getBoundingClientRect();
        var wrapTop  = scrollY + rect.top;
        var wrapH    = wrap.offsetHeight;
        var maxScroll = wrapH - viewH;
        if (maxScroll <= 0) return;

        var local = scrollY - wrapTop;
        var pct   = Math.max(0, Math.min(100, (local / maxScroll) * 100));
        prog.style.width = pct + '%';
    }

    function doVisibility(scrollY, viewH) {
        var rect    = wrap.getBoundingClientRect();
        var visible = (rect.bottom > 0) && (rect.top < viewH);

        if (nav)  nav.classList.toggle('ss-nav-show', visible);
        if (prog) prog.classList.toggle('ss-prog-show', visible);
    }

    /* ==================================================================
       Boot
       ================================================================== */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
