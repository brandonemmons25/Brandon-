/**
 * iHomeFinder Full Width – runtime detector
 *
 * Runs before the page finishes painting (loaded in <head>).
 * Adds `ihf-full-width` to <body> if any iHomeFinder markup is found,
 * so the CSS can immediately hide the sidebar without a flash.
 */
(function () {
    // iHomeFinder injects a global object or a <script> tag with these markers.
    // Check both the DOM (for inline markers already written) and window globals.
    function isIHFPage() {
        // 1. Window globals set by the iHomeFinder plugin JS
        if (
            typeof window.ihfKestrel !== 'undefined' ||
            typeof window.iHomeFinder !== 'undefined' ||
            typeof window.ihf !== 'undefined'
        ) {
            return true;
        }

        // 2. A <script> src pointing to iHomefinder / IDX domains
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || '';
            if (src.indexOf('ihomefinder') !== -1 || src.indexOf('idxbroker') !== -1) {
                return true;
            }
        }

        // 3. iHomeFinder embeds an <iframe> or a container div with known IDs/classes
        var markers = [
            '#ihf-main-container',
            '#ihf-home',
            '#ihf-search',
            '.ihf-container',
            '.ihf-main',
            '[id^="ihf-"]',
            '[class^="ihf-"]',
            '.idx-content',
            '#kestrel-app',           // iHomeFinder Kestrel (v4+)
            '[data-ihf]',
        ];
        for (var j = 0; j < markers.length; j++) {
            if (document.querySelector(markers[j])) {
                return true;
            }
        }

        return false;
    }

    function apply() {
        if (isIHFPage()) {
            document.documentElement.classList.add('ihf-full-width');
            if (document.body) {
                document.body.classList.add('ihf-full-width');
            }
        }
    }

    // Run immediately (catches globals/scripts already in <head>)
    apply();

    // Run again once DOM is ready (catches elements in <body>)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
