<?php
/**
 * Plugin Name: iHomefinder Markets Exporter
 * Description: Fetch iHomefinder markets (hotsheets/saved searches) and export to CSV.
 * Version:     3.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Base URL used by Optima Express for all service requests
define( 'IHFME_SERVICE_URL', 'https://www.idxhome.com/service/wordpress' );

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

    // Step 1: get authentication token.
    // Optima Express stores it in wp_options after plugin activation.
    $auth_token = get_option( 'ihf_authentication_token', '' );

    // Fallback: some versions use the activation token directly.
    if ( ! $auth_token ) {
        $auth_token = get_option( 'ihf_activation_token', '' );
    }

    // Last resort: exchange the saved registration key for a fresh token.
    if ( ! $auth_token ) {
        $reg_key = get_option( 'ihfme_reg_key', '' );
        if ( ! $reg_key ) {
            wp_send_json_error( 'No authentication token found and no registration key saved. ' .
                'Make sure Optima Express is activated, or enter your registration key.' );
        }
        $auth_token = ihfme_exchange_reg_key( $reg_key );
        if ( ! $auth_token ) {
            wp_send_json_error( 'Could not obtain authentication token from registration key. ' .
                'Verify the key is correct.' );
        }
    }

    // Step 2: fetch hotsheet (market) list.
    $resp = wp_remote_get( add_query_arg( [
        'method'              => 'handleRequest',
        'requestType'         => 'hotsheet-list',
        'viewType'            => 'json',
        'phpStyle'            => 'true',
        'authenticationToken' => $auth_token,
    ], IHFME_SERVICE_URL ), [
        'timeout'   => 20,
        'sslverify' => true,
    ] );

    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( 'Request error: ' . $resp->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $resp );
    $body = wp_remote_retrieve_body( $resp );

    if ( $code !== 200 ) {
        wp_send_json_error( "Service returned HTTP $code. Body preview: " . mb_substr( $body, 0, 300 ) );
    }

    $data = json_decode( $body, true );
    if ( $data === null ) {
        // iHomefinder sometimes returns PHP-serialized data; try unserializing
        $data = @unserialize( $body );
    }
    if ( ! is_array( $data ) ) {
        wp_send_json_error( 'Unexpected response format. Preview: ' . mb_substr( $body, 0, 300 ) );
    }

    // Normalise various response shapes
    $list = $data['hotsheets']    ??
            $data['markets']      ??
            $data['savedSearches'] ??
            $data['data']         ??
            $data;

    $markets = [];
    foreach ( (array) $list as $item ) {
        if ( ! is_array( $item ) ) continue;
        $name = $item['name']        ?? $item['title']      ??
                $item['hotsheetName'] ?? $item['linkName']   ?? '';
        $url  = $item['url']         ?? $item['link']        ??
                $item['pageUrl']     ?? $item['permalink']   ?? '';
        if ( $name ) {
            $markets[] = [ 'name' => trim( $name ), 'url' => trim( $url ) ];
        }
    }

    if ( empty( $markets ) ) {
        wp_send_json_error( 'API returned OK but no markets found in response. Raw: ' . mb_substr( $body, 0, 500 ) );
    }

    usort( $markets, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    wp_send_json_success( $markets );
} );

// ── Exchange registration key for authentication token ────────────────────────

function ihfme_exchange_reg_key( string $reg_key ): string {
    $resp = wp_remote_post( IHFME_SERVICE_URL, [
        'timeout' => 20,
        'body'    => [
            'method'          => 'handleRequest',
            'requestType'     => 'activate',
            'viewType'        => 'json',
            'registrationKey' => $reg_key,
        ],
    ] );

    if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
        return '';
    }

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    return $data['authenticationToken'] ?? $data['activationToken'] ?? '';
}

// ── Page HTML ─────────────────────────────────────────────────────────────────

function ihfme_render_page() {
    $reg_key    = get_option( 'ihfme_reg_key', '' );
    $auth_token = get_option( 'ihf_authentication_token', '' ) ?: get_option( 'ihf_activation_token', '' );
    $saved      = isset( $_GET['saved'] );
    ?>
    <div class="wrap">
        <h1>iHomefinder Markets Exporter</h1>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>Registration key saved.</p></div>
        <?php endif; ?>

        <?php if ( $auth_token ) : ?>
            <div class="notice notice-info inline" style="max-width:700px">
                <p>
                    Optima Express authentication token found in the database — no key entry needed.
                    Click <strong>Fetch Markets</strong> to proceed.
                </p>
            </div>
        <?php else : ?>
            <p style="color:#996800;max-width:700px">
                ⚠ No Optima Express token found in this WordPress installation.
                Enter your registration key below as a fallback.
            </p>
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
        <?php endif; ?>

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

        let allRows = [];

        fetchBtn.addEventListener('click', function(){
            fetchBtn.disabled = true;
            status.textContent = 'Fetching…';
            status.style.color = '#666';
            results.innerHTML  = '';
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
