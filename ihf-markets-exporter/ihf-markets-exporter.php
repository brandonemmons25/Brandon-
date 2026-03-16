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

add_action( 'admin_post_ihfme_save_key', function () {
    check_admin_referer( 'ihfme_save_key' );
    update_option( 'ihfme_reg_key', sanitize_text_field( $_POST['reg_key'] ?? '' ) );
    wp_redirect( admin_url( 'admin.php?page=ihf-markets&saved=1' ) );
    exit;
} );

// ── AJAX: fetch markets ───────────────────────────────────────────────────────

add_action( 'wp_ajax_ihfme_fetch', function () {
    check_ajax_referer( 'ihfme_fetch' );

    $key = get_option( 'ihfme_reg_key', '' );
    if ( ! $key ) wp_send_json_error( 'No registration key saved.' );

    // ── Strategy 1: iHomefinder Client REST API ───────────────────────────────
    // Basic auth: registration key as username, empty password.
    $markets = ihfme_try_api( $key );

    // ── Strategy 2: WordPress DB — pages/posts with iHF market shortcodes ────
    if ( empty( $markets ) ) {
        $markets = ihfme_scan_wp_posts();
    }

    // ── Strategy 3: iHF partner/reseller endpoint ─────────────────────────────
    if ( empty( $markets ) ) {
        $markets = ihfme_try_api( $key, 'https://api.ihomefinder.com/v1/partner/market' );
    }

    if ( empty( $markets ) ) {
        wp_send_json_error(
            'No markets found. Expected WordPress pages with URLs matching ' .
            '/listing-report/{name}/{id}/ — make sure those pages are published. ' .
            'Also verify the iHomefinder registration key if using the API.'
        );
    }

    usort( $markets, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    wp_send_json_success( $markets );
} );

// ── REST API helper ───────────────────────────────────────────────────────────

function ihfme_try_api( string $key, string $url = 'https://api.ihomefinder.com/v1/market' ): array {
    $markets = [];

    // Try Basic auth first, then x-ihf-access-key header
    $auth_variants = [
        [ 'Authorization' => 'Basic ' . base64_encode( $key . ':' ), 'Accept' => 'application/json' ],
        [ 'x-ihf-access-key' => $key, 'Accept' => 'application/json' ],
        [ 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ],
    ];

    foreach ( $auth_variants as $headers ) {
        $resp = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 15 ] );
        if ( is_wp_error( $resp ) ) continue;
        if ( wp_remote_retrieve_response_code( $resp ) !== 200 ) continue;

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) ) continue;

        $list = $body['data'] ?? $body['markets'] ?? $body;
        foreach ( (array) $list as $m ) {
            if ( ! is_array( $m ) ) continue;
            $name = $m['name'] ?? $m['title'] ?? $m['marketName'] ?? '';
            $link = $m['url']  ?? $m['link']  ?? $m['pageUrl']   ?? $m['permalink'] ?? '';
            if ( $name ) $markets[] = [ 'name' => $name, 'url' => $link ];
        }

        if ( ! empty( $markets ) ) break;
    }

    return $markets;
}

// ── WordPress DB scan: find /listing-report/{name}/{id}/ pages ────────────────
// Optima Express creates pages under a "listing-report" parent.
// URL pattern: /listing-report/Anderson-Real-Estate/2983632/

function ihfme_scan_wp_posts(): array {
    global $wpdb;
    $markets = [];

    // ── Strategy A: walk the page hierarchy ──────────────────────────────────
    // Find the top-level "listing-report" page (or any page whose slug is
    // "listing-report" regardless of depth).
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

        if ( ! empty( $markets ) ) return $markets;
    }

    // ── Strategy B: SQL — find all pages whose permalink path contains
    //    /listing-report/ by reconstructing paths from post_name + ancestry.
    // We find every published page whose post_name is purely numeric
    // (the {id} segment) AND whose grandparent slug is 'listing-report'.
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.post_name, p.post_parent
         FROM {$wpdb->posts} p
         WHERE p.post_status  = 'publish'
           AND p.post_type    = 'page'
           AND p.post_name    REGEXP '^[0-9]+$'"
    );

    foreach ( $rows as $r ) {
        $permalink = get_permalink( (int) $r->ID );
        if ( $permalink && strpos( $permalink, '/listing-report/' ) !== false ) {
            $markets[] = [
                'name' => $r->post_title,
                'url'  => $permalink,
            ];
        }
    }

    if ( ! empty( $markets ) ) return $markets;

    // ── Strategy C: broad sweep — any published page whose permalink contains
    //    /listing-report/ (catches custom post types with that rewrite base).
    $all_pages = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_status = 'publish'
           AND post_type   = 'page'
         ORDER BY post_title ASC
         LIMIT 2000"
    );

    foreach ( $all_pages as $p ) {
        $url = get_permalink( (int) $p->ID );
        if ( $url && preg_match( '#/listing-report/[^/]+/[0-9]+/?$#', $url ) ) {
            $markets[] = [ 'name' => $p->post_title, 'url' => $url ];
        }
    }

    return $markets;
}

// ── Page HTML ─────────────────────────────────────────────────────────────────

function ihfme_render_page() {
    $reg_key = get_option( 'ihfme_reg_key', '' );
    $saved   = isset( $_GET['saved'] );
    ?>
    <div class="wrap">
        <h1>iHomefinder Markets Exporter</h1>
        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>Registration key saved.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="margin-bottom:20px;">
            <?php wp_nonce_field( 'ihfme_save_key' ); ?>
            <input type="hidden" name="action" value="ihfme_save_key">
            <table class="form-table" style="max-width:560px">
                <tr>
                    <th><label for="reg_key">iHomefinder Registration Key</label></th>
                    <td>
                        <input type="text" id="reg_key" name="reg_key"
                               value="<?php echo esc_attr( $reg_key ); ?>"
                               class="regular-text"
                               placeholder="e.g. 715e2142-b3bb-4d57-be1d-58374920b849">
                        <p class="description">
                            Found in <strong>Optima Express → Settings → Registration</strong><br>
                            The plugin also scans WordPress for pages matching
                            <code>/listing-report/{name}/{id}/</code> as a fallback.
                        </p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="button button-secondary">Save Key</button></p>
        </form>

        <?php if ( $reg_key ) : ?>
            <p>
                <button id="ihfme-fetch" class="button button-primary">Fetch Markets</button>
                <button id="ihfme-csv" class="button" style="display:none;margin-left:8px;">Download CSV</button>
                <span id="ihfme-status" style="margin-left:12px;color:#666;"></span>
            </p>
            <div id="ihfme-results"></div>
        <?php else : ?>
            <p style="color:#888;">Enter and save your registration key above to get started.</p>
        <?php endif; ?>
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
