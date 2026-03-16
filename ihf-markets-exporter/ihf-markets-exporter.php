<?php
/**
 * Plugin Name: iHomefinder Markets Exporter
 * Description: Fetch and export iHomefinder (Optima Express) market pages to a CSV spreadsheet.
 * Version:     1.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'iHF Markets',
        'iHF Markets',
        'manage_options',
        'ihf-markets',
        'ihfme_render_page',
        'dashicons-location-alt',
        81
    );
} );

// ── AJAX: fetch markets ───────────────────────────────────────────────────────

add_action( 'wp_ajax_ihfme_fetch', function () {
    check_ajax_referer( 'ihfme_fetch' );

    // Walk the WordPress page hierarchy under /listing-report/
    $markets = ihfme_scan_listing_report_pages();

    if ( empty( $markets ) ) {
        wp_send_json_error(
            'No markets found. Expected published WordPress pages with URLs matching ' .
            '/listing-report/{name}/{id}/ — make sure those pages are published.'
        );
    }

    usort( $markets, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    wp_send_json_success( $markets );
} );

// ── Scan WordPress page hierarchy for /listing-report/{name}/{id}/ ────────────
// URL pattern: /listing-report/Anderson-Real-Estate/2983632/

function ihfme_scan_listing_report_pages(): array {
    $markets = [];

    $root = get_page_by_path( 'listing-report', OBJECT, 'page' );

    if ( $root ) {
        // Children of "listing-report" are the {name} tier.
        // Their children are the {id} tier — those are the actual market pages.
        $name_tier = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_parent'    => $root->ID,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        foreach ( $name_tier as $name_page ) {
            $id_tier = get_posts( [
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'post_parent'    => $name_page->ID,
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ] );

            if ( ! empty( $id_tier ) ) {
                // Market pages live one level deeper (the numeric {id} pages)
                foreach ( $id_tier as $p ) {
                    $markets[] = [
                        'name' => $p->post_title,
                        'url'  => get_permalink( $p->ID ),
                    ];
                }
            } else {
                // Some installs only have two levels: /listing-report/{name}/
                $markets[] = [
                    'name' => $name_page->post_title,
                    'url'  => get_permalink( $name_page->ID ),
                ];
            }
        }

    }

    return $markets;
}

// ── Page HTML ─────────────────────────────────────────────────────────────────

function ihfme_render_page() {
    ?>
    <div class="wrap">
        <h1>iHomefinder Markets Exporter</h1>
        <p style="color:#555">
            Scans WordPress for published pages under <code>/listing-report/{name}/{id}/</code>
            and exports them to CSV.
        </p>
        <p>
            <button id="ihfme-fetch" class="button button-primary">Fetch Markets</button>
            <button id="ihfme-csv" class="button" style="display:none;margin-left:8px;">Download CSV</button>
            <span id="ihfme-status" style="margin-left:12px;color:#666;"></span>
        </p>
        <div id="ihfme-results"></div>
    </div>

    <script>
    (function(){
        const fetchBtn = document.getElementById('ihfme-fetch');
        const csvBtn   = document.getElementById('ihfme-csv');
        const status   = document.getElementById('ihfme-status');
        const results  = document.getElementById('ihfme-results');
        if (!fetchBtn) return;

        let allRows = [];

        fetchBtn.addEventListener('click', function(){
            fetchBtn.disabled = true;
            status.textContent = 'Fetching…';
            status.style.color = '#666';
            results.innerHTML = '';
            csvBtn.style.display = 'none';

            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'ihfme_fetch',
                    _ajax_nonce: '<?php echo wp_create_nonce( "ihfme_fetch" ); ?>'
                })
            })
            .then(r => r.json())
            .then(json => {
                fetchBtn.disabled = false;
                if (!json.success) {
                    status.textContent = 'Error: ' + json.data;
                    status.style.color = '#c00';
                    return;
                }
                allRows = json.data;
                status.textContent = allRows.length + ' markets found.';
                status.style.color = '#060';
                renderTable(allRows);
                csvBtn.style.display = '';
            })
            .catch(e => {
                fetchBtn.disabled = false;
                status.textContent = 'Request failed: ' + e.message;
                status.style.color = '#c00';
            });
        });

        csvBtn.addEventListener('click', function(){
            const lines = ['"Name","iHF URL"'];
            allRows.forEach(r => {
                lines.push([r.name, r.url]
                    .map(v => '"' + String(v).replace(/"/g,'""') + '"').join(','));
            });
            const blob = new Blob([lines.join('\n')], {type:'text/csv'});
            const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
            a.download = 'ihf-markets.csv'; a.click();
        });

        function renderTable(rows) {
            let html = '<table class="widefat striped" style="margin-top:16px;max-width:700px">'
                     + '<thead><tr><th>Name</th><th>iHF URL</th></tr></thead><tbody>';
            rows.forEach(r => {
                html += `<tr><td>${esc(r.name)}</td><td><code>${esc(r.url)}</code></td></tr>`;
            });
            html += '</tbody></table>';
            results.innerHTML = html;
        }
        function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
    })();
    </script>
    <?php
}
