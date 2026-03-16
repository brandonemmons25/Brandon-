<?php
/**
 * Plugin Name: iHomefinder Markets Exporter
 * Description: Fetch iHomefinder markets (saved searches) via the iHF API and export to CSV.
 * Version:     2.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu ────────────────────────────────────────────────────────────────

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

// ── Save key ──────────────────────────────────────────────────────────────────

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
    if ( ! $key ) {
        wp_send_json_error( [ 'message' => 'No registration key saved.', 'debug' => [] ] );
    }

    // Candidate endpoints — ordered most-likely first.
    // The correct one will succeed with HTTP 200 + JSON array of markets.
    $candidates = [
        [
            'url'     => 'https://www.idxhome.com/api/v1/market.json',
            'method'  => 'GET',
            'headers' => [ 'Accept' => 'application/json' ],
            'params'  => [ 'registrationKey' => $key ],
        ],
        [
            'url'     => 'https://www.idxhome.com/api/v1/savedSearch.json',
            'method'  => 'GET',
            'headers' => [ 'Accept' => 'application/json' ],
            'params'  => [ 'registrationKey' => $key ],
        ],
        [
            'url'     => 'https://api.ihomefinder.com/v1/market',
            'method'  => 'GET',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
                'Accept'        => 'application/json',
            ],
            'params'  => [],
        ],
        [
            'url'     => 'https://api.ihomefinder.com/v1/savedSearch',
            'method'  => 'GET',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
                'Accept'        => 'application/json',
            ],
            'params'  => [],
        ],
        [
            'url'     => 'https://www.idxhome.com/api/v1/client/market.json',
            'method'  => 'GET',
            'headers' => [ 'Accept' => 'application/json' ],
            'params'  => [ 'registrationKey' => $key ],
        ],
    ];

    $debug   = [];
    $markets = [];

    foreach ( $candidates as $c ) {
        $full_url = $c['url'];
        if ( ! empty( $c['params'] ) ) {
            $full_url .= '?' . http_build_query( $c['params'] );
        }

        $resp = wp_remote_get( $full_url, [
            'headers' => $c['headers'],
            'timeout' => 15,
        ] );

        $entry = [ 'url' => $full_url, 'error' => null, 'status' => null, 'body_preview' => null ];

        if ( is_wp_error( $resp ) ) {
            $entry['error'] = $resp->get_error_message();
            $debug[] = $entry;
            continue;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $entry['status']       = $code;
        $entry['body_preview'] = mb_substr( $body, 0, 300 );
        $debug[] = $entry;

        if ( $code !== 200 ) continue;

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) continue;

        // Normalise: some APIs wrap in {data:[...]}, some return a flat array
        $list = $data['data'] ?? $data['markets'] ?? $data['savedSearches'] ?? $data;
        if ( ! is_array( $list ) ) continue;

        foreach ( $list as $m ) {
            if ( ! is_array( $m ) ) continue;
            $name = $m['name'] ?? $m['title'] ?? $m['marketName'] ?? $m['linkName'] ?? '';
            $url  = $m['url']  ?? $m['link']  ?? $m['pageUrl']   ?? $m['permalink'] ?? '';
            if ( $name ) {
                $markets[] = [ 'name' => $name, 'url' => $url ];
            }
        }

        if ( ! empty( $markets ) ) break; // found a working endpoint
    }

    if ( empty( $markets ) ) {
        wp_send_json_error( [
            'message' => 'No markets returned from any endpoint. See debug info below.',
            'debug'   => $debug,
        ] );
    }

    usort( $markets, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    wp_send_json_success( [ 'markets' => $markets, 'debug' => $debug ] );
} );

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
                        <p class="description">Found in <strong>Optima Express → Settings → Registration</strong></p>
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
            <div id="ihfme-debug" style="display:none;margin-top:20px;">
                <h3>API Debug Info</h3>
                <p style="color:#888;font-size:.85rem">
                    Shown when the fetch fails — share this to help identify the correct endpoint.
                </p>
                <pre id="ihfme-debug-pre" style="background:#f6f7f7;padding:12px;font-size:.8rem;overflow:auto;max-height:400px;border:1px solid #ddd;border-radius:4px;"></pre>
            </div>
        <?php else : ?>
            <p style="color:#888;">Enter and save your registration key above to get started.</p>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        const fetchBtn  = document.getElementById('ihfme-fetch');
        const csvBtn    = document.getElementById('ihfme-csv');
        const status    = document.getElementById('ihfme-status');
        const results   = document.getElementById('ihfme-results');
        const debugBox  = document.getElementById('ihfme-debug');
        const debugPre  = document.getElementById('ihfme-debug-pre');
        if (!fetchBtn) return;

        let allRows = [];

        fetchBtn.addEventListener('click', function(){
            fetchBtn.disabled = true;
            status.textContent = 'Fetching…';
            status.style.color = '#666';
            results.innerHTML  = '';
            csvBtn.style.display = 'none';
            debugBox.style.display = 'none';

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

                // Show debug in both success and error cases
                const dbg = json.success ? json.data.debug : json.data?.debug;
                if (dbg && dbg.length) {
                    debugPre.textContent = JSON.stringify(dbg, null, 2);
                    debugBox.style.display = '';
                }

                if (!json.success) {
                    status.textContent = 'Error: ' + (json.data?.message || json.data);
                    status.style.color = '#c00';
                    return;
                }

                allRows = json.data.markets;
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
