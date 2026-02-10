/**
 * Scroll Sections Developer — Front-end GSAP Animations
 *
 * Provides:
 *  - Full-screen section scroll-snapping
 *  - Parallax background movement
 *  - Scroll-triggered content animations (fade-up, fade-in, scale-up, slide-left/right)
 *  - Dot navigation with active state tracking
 *  - Respects prefers-reduced-motion
 */

(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /*  Wait for DOM                                                       */
    /* ------------------------------------------------------------------ */
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var wrapper = document.querySelector('.ssd-wrapper');
        if (!wrapper) return;

        gsap.registerPlugin(ScrollTrigger);

        var sections   = wrapper.querySelectorAll('.ssd-section');
        var dots       = wrapper.querySelectorAll('.ssd-dot');
        var snapOn     = wrapper.getAttribute('data-ssd-snap') === 'true';
        var prefersRed = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (sections.length === 0) return;

        /* ------------------------------------------------------------------ */
        /*  Snap scrolling (optional)                                          */
        /* ------------------------------------------------------------------ */
        if (snapOn && !prefersRed) {
            ScrollTrigger.create({
                snap: {
                    snapTo: 1 / (sections.length - 1),
                    duration: { min: 0.3, max: 0.8 },
                    ease: 'power2.inOut'
                }
            });
        }

        /* ------------------------------------------------------------------ */
        /*  Per-section animations                                             */
        /* ------------------------------------------------------------------ */
        sections.forEach(function (section, i) {
            var parallaxSpeed = parseFloat(section.getAttribute('data-ssd-parallax')) || 0;
            var animType      = section.getAttribute('data-ssd-anim') || 'none';
            var bg            = section.querySelector('[data-ssd-parallax-bg]');
            var content       = section.querySelector('[data-ssd-content]');

            /* --- Parallax background ------------------------------------ */
            if (bg && parallaxSpeed > 0 && !prefersRed) {
                var yPercent = parallaxSpeed * 30; // how far the bg moves

                gsap.fromTo(bg,
                    { yPercent: -yPercent },
                    {
                        yPercent: yPercent,
                        ease: 'none',
                        scrollTrigger: {
                            trigger: section,
                            start: 'top bottom',
                            end: 'bottom top',
                            scrub: true
                        }
                    }
                );
            }

            /* --- Content entrance animation ----------------------------- */
            if (content && animType !== 'none' && animType !== 'parallax-only' && !prefersRed) {
                var fromVars = { autoAlpha: 0 };
                var toVars   = { autoAlpha: 1, duration: 1, ease: 'power3.out' };

                switch (animType) {
                    case 'fade-up':
                        fromVars.y = 80;
                        toVars.y = 0;
                        break;
                    case 'fade-in':
                        // opacity only
                        break;
                    case 'scale-up':
                        fromVars.scale = 0.85;
                        toVars.scale = 1;
                        break;
                    case 'slide-left':
                        fromVars.x = -120;
                        toVars.x = 0;
                        break;
                    case 'slide-right':
                        fromVars.x = 120;
                        toVars.x = 0;
                        break;
                }

                gsap.fromTo(content, fromVars, Object.assign(toVars, {
                    scrollTrigger: {
                        trigger: section,
                        start: 'top 75%',
                        end: 'top 25%',
                        toggleActions: 'play none none reverse'
                    }
                }));
            }

            /* --- Stagger children within content (title, subtitle, body, cta) */
            if (content && animType !== 'none' && animType !== 'parallax-only' && !prefersRed) {
                var children = content.querySelectorAll('.ssd-subtitle, .ssd-title, .ssd-body, .ssd-cta');
                if (children.length > 1) {
                    gsap.fromTo(children,
                        { autoAlpha: 0, y: 30 },
                        {
                            autoAlpha: 1,
                            y: 0,
                            duration: 0.8,
                            stagger: 0.15,
                            ease: 'power2.out',
                            scrollTrigger: {
                                trigger: section,
                                start: 'top 60%',
                                toggleActions: 'play none none reverse'
                            }
                        }
                    );
                }
            }

            /* --- Dot navigation: update active state -------------------- */
            ScrollTrigger.create({
                trigger: section,
                start: 'top center',
                end: 'bottom center',
                onEnter: function () { setActiveDot(i); },
                onEnterBack: function () { setActiveDot(i); }
            });
        });

        /* ------------------------------------------------------------------ */
        /*  Dot click → scroll to section                                      */
        /* ------------------------------------------------------------------ */
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                var idx     = parseInt(dot.getAttribute('data-ssd-index'), 10);
                var target  = sections[idx];
                if (!target) return;

                gsap.to(window, {
                    scrollTo: { y: target, autoKill: false },
                    duration: prefersRed ? 0 : 1,
                    ease: 'power3.inOut'
                });
            });
        });

        /* ------------------------------------------------------------------ */
        /*  Helpers                                                            */
        /* ------------------------------------------------------------------ */
        function setActiveDot(activeIndex) {
            dots.forEach(function (d, j) {
                d.classList.toggle('active', j === activeIndex);
            });
        }
    }
})();
