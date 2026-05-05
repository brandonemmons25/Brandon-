<?php
/**
 * WordPress admin page: Tools > Mobile CSS Audit
 * Runs a full-site scan and compiles compact output.
 */
class MCA_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'wp_ajax_mca_get_urls', [ __CLASS__, 'ajax_get_urls' ] );
        add_action( 'wp_ajax_mca_get_nonce_val', [ __CLASS__, 'ajax_get_nonce_val' ] );
    }

    public static function add_menu() {
        add_management_page(
            'Mobile CSS Audit',
            'Mobile CSS Audit',
            'manage_options',
            'mobile-css-audit',
            [ __CLASS__, 'render_page' ]
        );
    }

    /** Return list of all published page/post URLs as JSON — same-host only. */
    public static function ajax_get_urls() {
        check_ajax_referer( 'mca_admin', 'nonce' );

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $urls      = [];

        // Pages and posts.
        $query = new WP_Query( [
            'post_type'      => [ 'page', 'post' ],
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
        ] );
        foreach ( $query->posts as $id ) {
            $url = get_permalink( $id );
            if ( wp_parse_url( $url, PHP_URL_HOST ) === $site_host ) {
                $urls[] = $url;
            }
        }

        // Custom post types — skip any whose permalink resolves off-domain
        // (e.g. IDX listing types that redirect to search.domain.com).
        $cpts = get_post_types( [ 'public' => true, '_builtin' => false ], 'names' );
        if ( $cpts ) {
            $cpt_query = new WP_Query( [
                'post_type'      => array_values( $cpts ),
                'post_status'    => 'publish',
                'posts_per_page' => 200,
                'fields'         => 'ids',
            ] );
            foreach ( $cpt_query->posts as $id ) {
                $url = get_permalink( $id );
                if ( wp_parse_url( $url, PHP_URL_HOST ) === $site_host ) {
                    $urls[] = $url;
                }
            }
        }

        // Homepage.
        $home = home_url( '/' );
        if ( ! in_array( $home, $urls, true ) ) {
            array_unshift( $urls, $home );
        }

        wp_send_json_success( array_values( array_unique( array_filter( $urls ) ) ) );
    }

    /** Return the persistent probe nonce. */
    public static function ajax_get_nonce_val() {
        check_ajax_referer( 'mca_admin', 'nonce' );
        $nonce = self::get_or_create_nonce();
        wp_send_json_success( $nonce );
    }

    private static function get_or_create_nonce() {
        $n = get_option( 'mca_nonce' );
        if ( ! $n ) {
            $n = substr( md5( wp_generate_uuid4() ), 0, 10 );
            update_option( 'mca_nonce', $n, false );
        }
        return $n;
    }

    public static function render_page() {
        $admin_nonce = wp_create_nonce( 'mca_admin' );
        ?>
        <div class="wrap">
            <h1>Mobile CSS Audit <span style="font-size:13px;font-weight:400;color:#888;">v<?php echo MCA_VERSION; ?></span></h1>
            <p>Scans all published pages at <strong>375px</strong> viewport for overflow and layout issues. Output is compact — designed to be pasted into Claude.</p>

            <div style="margin:16px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button id="mca-run" class="button button-primary button-large">&#9654; Run Full Site Scan</button>
                <button id="mca-copy" class="button button-large" disabled>Copy Output</button>
                <button id="mca-copy-filtered" class="button button-large" disabled style="display:none;">Copy Filtered</button>
                <span id="mca-status" style="color:#888;font-style:italic;"></span>
            </div>

            <div id="mca-progress" style="display:none;margin-bottom:12px;">
                <div style="background:#e0e0e0;border-radius:4px;height:8px;overflow:hidden;">
                    <div id="mca-bar" style="background:#0073aa;height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <p id="mca-progress-label" style="font-size:12px;color:#666;margin-top:4px;"></p>
            </div>

            <!-- Search / filter bar — shown after scan completes -->
            <div id="mca-search-bar" style="display:none;margin-bottom:10px;display:none;align-items:center;gap:10px;flex-wrap:wrap;">
                <input id="mca-search" type="search" placeholder="Search URL or page title…"
                    style="flex:1;min-width:220px;max-width:380px;padding:6px 10px;font-size:13px;border:1px solid #8c8f94;border-radius:4px;" />
                <span style="font-size:13px;color:#666;">Filter:</span>
                <button class="mca-filter button" data-filter="all">All</button>
                <button class="mca-filter button" data-filter="overflow">Overflow only</button>
                <button class="mca-filter button" data-filter="collapsed">Collapsed only</button>
                <button class="mca-filter button" data-filter="issues">Issues only</button>
                <button class="mca-filter button" data-filter="clean">Clean only</button>
                <span id="mca-count" style="font-size:12px;color:#888;margin-left:4px;"></span>
            </div>

            <textarea id="mca-output" readonly
                style="width:100%;height:520px;font-family:monospace;font-size:12px;line-height:1.5;background:#0d1117;color:#e6edf3;border:1px solid #333;border-radius:4px;padding:12px;box-sizing:border-box;resize:vertical;"
                placeholder="Scan results will appear here…"></textarea>
        </div>

        <script>
        (function () {
            var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var adminNonce = <?php echo wp_json_encode( $admin_nonce ); ?>;
            var probeNonce  = '';
            var lines       = [];   // raw per-page report strings
            var pageBlocks  = [];   // same reference, named for clarity
            var scanHeader  = '';   // "MOBILE CSS AUDIT SUMMARY\n..." line
            var urls        = [];
            var current     = 0;
            var scanWindow  = null;
            var pageTimer   = null;
            var activeFilter = 'all';
            var PAGE_TIMEOUT = 10000;

            var $run          = document.getElementById('mca-run');
            var $copy         = document.getElementById('mca-copy');
            var $copyFiltered = document.getElementById('mca-copy-filtered');
            var $status       = document.getElementById('mca-status');
            var $out          = document.getElementById('mca-output');
            var $prog         = document.getElementById('mca-progress');
            var $bar          = document.getElementById('mca-bar');
            var $label        = document.getElementById('mca-progress-label');
            var $searchBar    = document.getElementById('mca-search-bar');
            var $search       = document.getElementById('mca-search');
            var $count        = document.getElementById('mca-count');

            // ── Listen for probe results ─────────────────────────────────────
            window.addEventListener('message', function (e) {
                if (!e.data || !e.data.mca) return;
                clearTimeout(pageTimer);
                lines.push(e.data.report);
                advance();
            });

            function advance() {
                current++;
                updateProgress();
                if (current < urls.length) {
                    loadNext();
                } else {
                    finishScan();
                }
            }

            // ── Run button ───────────────────────────────────────────────────
            $run.addEventListener('click', function () {
                $run.disabled = true;
                $copy.disabled = true;
                $copyFiltered.disabled = true;
                $copyFiltered.style.display = 'none';
                $searchBar.style.display = 'none';
                $status.textContent = 'Fetching URL list…';
                $out.value = '';
                lines  = [];
                urls   = [];
                current = 0;
                activeFilter = 'all';
                setActiveFilterBtn('all');

                fetch(ajaxUrl + '?action=mca_get_nonce_val&nonce=' + adminNonce)
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) throw new Error('Could not get nonce');
                        probeNonce = res.data;
                        return fetch(ajaxUrl + '?action=mca_get_urls&nonce=' + adminNonce);
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) throw new Error('Could not get URLs');
                        urls = res.data;
                        if (!urls.length) throw new Error('No URLs found');
                        $status.textContent = 'Scanning ' + urls.length + ' pages…';
                        $prog.style.display = 'block';
                        loadNext();
                    })
                    .catch(function (err) {
                        $status.textContent = 'Error: ' + err.message;
                        $run.disabled = false;
                    });
            });

            // ── Load next URL in popup window ────────────────────────────────
            function loadNext() {
                var url = urls[current];
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'mca_probe=1&mca_nonce=' + probeNonce;

                if (scanWindow && !scanWindow.closed) {
                    scanWindow.location.href = url;
                } else {
                    scanWindow = window.open(url, 'mca_scan', 'width=390,height=700,toolbar=0,menubar=0');
                }

                $label.textContent = '(' + (current + 1) + '/' + urls.length + ') ' + urls[current];

                clearTimeout(pageTimer);
                pageTimer = setTimeout(function () {
                    lines.push('PAGE: (skipped — timeout)\nURL: ' + urls[current] + '\n--- SKIPPED: no response within ' + (PAGE_TIMEOUT / 1000) + 's ---\n------------------------------------------------------------');
                    advance();
                }, PAGE_TIMEOUT);
            }

            // ── Update progress bar ──────────────────────────────────────────
            function updateProgress() {
                var pct = Math.round((current / urls.length) * 100);
                $bar.style.width = pct + '%';
            }

            // ── Finish: compile output and show search bar ───────────────────
            function finishScan() {
                if (scanWindow && !scanWindow.closed) scanWindow.close();

                var ts = new Date().toISOString();
                scanHeader = 'MOBILE CSS AUDIT SUMMARY\nGenerated: ' + ts + '\n' +
                             '============================================================\n\n';
                pageBlocks = lines.slice();

                renderOutput();

                $run.disabled  = false;
                $copy.disabled = false;
                $copyFiltered.style.display = 'inline-block';
                $copyFiltered.disabled = false;
                $status.textContent = '✓ Done — ' + urls.length + ' pages scanned.';
                $label.textContent  = '';
                $bar.style.width    = '100%';

                // Show search bar
                $searchBar.style.display = 'flex';
            }

            // ── Search and filter logic ──────────────────────────────────────
            function renderOutput() {
                var term    = ($search ? $search.value : '').toLowerCase().trim();
                var filter  = activeFilter;
                var total   = pageBlocks.length;

                var matched = pageBlocks.filter(function (block) {
                    if (term && block.toLowerCase().indexOf(term) === -1) return false;
                    if (filter === 'overflow'  && block.indexOf('Overflow: YES')        === -1) return false;
                    if (filter === 'collapsed' && block.indexOf('COLLAPSED ELEMENTS')   === -1) return false;
                    if (filter === 'issues'    && block.indexOf('Overflow: YES')        === -1
                                               && block.indexOf('COLLAPSED ELEMENTS')  === -1) return false;
                    if (filter === 'clean'     && (block.indexOf('Overflow: YES')       !== -1
                                               || block.indexOf('COLLAPSED ELEMENTS')  !== -1)) return false;
                    return true;
                });

                var filterNote = '';
                if (filter !== 'all' || term) {
                    var parts = [];
                    if (filter !== 'all') parts.push(filter);
                    if (term) parts.push('"' + term + '"');
                    filterNote = 'Filter: ' + parts.join(' + ') + '\n';
                }
                filterNote += 'Showing ' + matched.length + ' of ' + total + ' pages\n';

                $out.value = scanHeader.replace(/\n\n$/, '\n') + filterNote + '\n' + matched.join('\n\n');

                if ($count) $count.textContent = matched.length + ' / ' + total + ' pages';
            }

            // ── Filter buttons ───────────────────────────────────────────────
            document.querySelectorAll('.mca-filter').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activeFilter = this.getAttribute('data-filter');
                    setActiveFilterBtn(activeFilter);
                    renderOutput();
                });
            });

            function setActiveFilterBtn(filter) {
                document.querySelectorAll('.mca-filter').forEach(function (b) {
                    b.classList.toggle('button-primary', b.getAttribute('data-filter') === filter);
                });
            }

            // ── Search input ─────────────────────────────────────────────────
            if ($search) {
                $search.addEventListener('input', function () { renderOutput(); });
            }

            // ── Copy full output ─────────────────────────────────────────────
            $copy.addEventListener('click', function () {
                var saved = $out.value;
                $out.value = scanHeader + pageBlocks.join('\n\n');
                $out.select();
                document.execCommand('copy');
                $out.value = saved;
                $copy.textContent = 'Copied!';
                setTimeout(function () { $copy.textContent = 'Copy Output'; }, 2000);
            });

            // ── Copy filtered output (what's currently visible) ──────────────
            $copyFiltered.addEventListener('click', function () {
                $out.select();
                document.execCommand('copy');
                $copyFiltered.textContent = 'Copied!';
                setTimeout(function () { $copyFiltered.textContent = 'Copy Filtered'; }, 2000);
            });

            // Initialise filter buttons: 'All' starts active
            setActiveFilterBtn('all');
        }());
        </script>
        <?php
    }
}
