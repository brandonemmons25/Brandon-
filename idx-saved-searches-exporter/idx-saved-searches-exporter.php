<?php
/**
 * Plugin Name: IDX Saved Searches Exporter
 * Description: Fetch and export IDX Broker saved searches (/i/ URLs) to a CSV spreadsheet.
 * Version:     1.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'IDX Saved Searches',
        'IDX Saved Searches',
        'manage_options',
        'idx-saved-searches',
        'isse_render_page',
        'dashicons-search',
        80
    );
} );

add_action( 'admin_post_isse_save_key', function () {
    check_admin_referer( 'isse_save_key' );
    update_option( 'isse_api_key', sanitize_text_field( $_POST['api_key'] ?? '' ) );
    wp_redirect( admin_url( 'admin.php?page=idx-saved-searches&saved=1' ) );
    exit;
} );

add_action( 'wp_ajax_isse_fetch', function () {
    check_ajax_referer( 'isse_fetch' );

    $key = get_option( 'isse_api_key', '' );
    if ( ! $key ) wp_send_json_error( 'No API key saved.' );

    $response = wp_remote_get( 'https://api.idxbroker.com/clients/savedlinks', [
        'headers' => [ 'accesskey' => $key ],
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) wp_send_json_error( "IDX API returned HTTP $code — check your API key." );

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) ) wp_send_json_error( 'Unexpected response from IDX Broker.' );

    $rows = [];
    foreach ( $data as $id => $info ) {
        $rows[] = [
            'id'       => $id,
            'name'     => $info['linkName']  ?? '',
            'url'      => '/i/' . ( $info['linkURL'] ?? '' ),
            'category' => $info['category']  ?? '',
            'created'  => $info['created']   ?? '',
        ];
    }
    usort( $rows, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

    wp_send_json_success( $rows );
} );

function isse_render_page() {
    $api_key = get_option( 'isse_api_key', '' );
    $saved   = isset( $_GET['saved'] );
    ?>
    <div class="wrap">
        <h1>IDX Saved Searches Exporter</h1>
        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>API key saved.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="margin-bottom:20px;">
            <?php wp_nonce_field( 'isse_save_key' ); ?>
            <input type="hidden" name="action" value="isse_save_key">
            <table class="form-table" style="max-width:500px">
                <tr>
                    <th><label for="api_key">IDX Broker API Key</label></th>
                    <td>
                        <input type="text" id="api_key" name="api_key"
                               value="<?php echo esc_attr( $api_key ); ?>"
                               class="regular-text" placeholder="Paste your IDX Broker API key">
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="button button-secondary">Save API Key</button></p>
        </form>

        <?php if ( $api_key ) : ?>
            <p>
                <button id="isse-fetch" class="button button-primary">Fetch Saved Searches</button>
                <button id="isse-csv" class="button" style="display:none;margin-left:8px;">Download CSV</button>
                <span id="isse-status" style="margin-left:12px;color:#666;"></span>
            </p>
            <div id="isse-results"></div>
        <?php else : ?>
            <p style="color:#888;">Enter and save your API key above to get started.</p>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        const fetchBtn = document.getElementById('isse-fetch');
        const csvBtn   = document.getElementById('isse-csv');
        const status   = document.getElementById('isse-status');
        const results  = document.getElementById('isse-results');
        if (!fetchBtn) return;

        let allRows = [];

        fetchBtn.addEventListener('click', function(){
            fetchBtn.disabled = true;
            status.textContent = 'Fetching…';
            results.innerHTML = '';
            csvBtn.style.display = 'none';

            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'isse_fetch',
                    _ajax_nonce: '<?php echo wp_create_nonce( "isse_fetch" ); ?>'
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
                status.textContent = allRows.length + ' saved searches found.';
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
            const lines = ['"Name","IDX URL","Category","Created"'];
            allRows.forEach(r => {
                lines.push([r.name, r.url, r.category, r.created]
                    .map(v => '"' + String(v).replace(/"/g,'""') + '"').join(','));
            });
            const blob = new Blob([lines.join('\n')], {type:'text/csv'});
            const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
            a.download = 'idx-saved-searches.csv'; a.click();
        });

        function renderTable(rows) {
            let html = '<table class="widefat striped" style="margin-top:16px;max-width:800px">'
                     + '<thead><tr><th>Name</th><th>URL</th><th>Category</th><th>Created</th></tr></thead><tbody>';
            rows.forEach(r => {
                html += `<tr><td>${esc(r.name)}</td><td><code>${esc(r.url)}</code></td><td>${esc(r.category)}</td><td>${esc(r.created)}</td></tr>`;
            });
            html += '</tbody></table>';
            results.innerHTML = html;
        }
        function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
    })();
    </script>
    <?php
}
