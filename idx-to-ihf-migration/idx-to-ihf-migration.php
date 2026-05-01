<?php
/**
 * Plugin Name: IDX → iHF Migration
 * Description: Match IDX Broker saved searches to iHomeFinder (Optima Express) markets, then replace shortcodes and saved-link URLs across posts and widgets.
 * Version:     1.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'IDXIHF_SERVICE_URL', 'https://www.idxhome.com/service/wordpress' );

// ── Admin menu ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_menu_page(
        'IDX → iHF Migration',
        'IDX → iHF',
        'manage_options',
        'idx-ihf-migration',
        'idxihf_render_page',
        'dashicons-update',
        82
    );
} );

// ── AJAX: fetch iHF hotsheets (with numeric IDs) ───────────────────────────────

add_action( 'wp_ajax_idxihf_fetch_markets', function () {
    check_ajax_referer( 'idxihf_nonce' );

    $auth_token = get_option( 'ihf_authentication_token', '' )
               ?: get_option( 'ihf_activation_token', '' );

    if ( ! $auth_token ) {
        wp_send_json_error( 'No Optima Express authentication token found. Make sure the Optima Express plugin is activated and registered.' );
    }

    $resp = wp_remote_get( add_query_arg( [
        'method'              => 'handleRequest',
        'requestType'         => 'hotsheet-list',
        'viewType'            => 'json',
        'phpStyle'            => 'true',
        'authenticationToken' => $auth_token,
    ], IDXIHF_SERVICE_URL ), [ 'timeout' => 20, 'sslverify' => true ] );

    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( 'Request error: ' . $resp->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $resp );
    $body = wp_remote_retrieve_body( $resp );

    if ( $code !== 200 ) {
        wp_send_json_error( "iHF service returned HTTP {$code}. Body: " . mb_substr( $body, 0, 200 ) );
    }

    $markets = idxihf_parse_hotsheets( $body );

    if ( is_string( $markets ) ) {
        wp_send_json_error( $markets );
    }

    wp_send_json_success( $markets );
} );

// ── AJAX: fetch IDX Broker saved searches ─────────────────────────────────────

add_action( 'wp_ajax_idxihf_fetch_idx', function () {
    check_ajax_referer( 'idxihf_nonce' );

    // Prefer the key stored by IDX Saved Searches Exporter plugin
    $key = get_option( 'isse_api_key', '' );
    if ( ! $key ) {
        wp_send_json_error( 'No IDX Broker API key found. Save it in the IDX Saved Searches plugin settings (or enter it below).' );
    }

    $resp = wp_remote_get( 'https://api.idxbroker.com/clients/savedlinks', [
        'headers' => [ 'accesskey' => $key ],
        'timeout' => 15,
    ] );

    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( $resp->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code !== 200 ) {
        wp_send_json_error( "IDX Broker API returned HTTP {$code}. Check your API key." );
    }

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( ! is_array( $data ) ) {
        wp_send_json_error( 'Unexpected response from IDX Broker API.' );
    }

    $rows = [];
    foreach ( $data as $id => $info ) {
        $name   = $info['linkName'] ?? '';
        $rows[] = [
            'id'   => (string) $id,
            'name' => $name,
            'slug' => idxihf_name_to_slug( $name ),
        ];
    }
    usort( $rows, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

    wp_send_json_success( $rows );
} );

// ── AJAX: dry-run preview ──────────────────────────────────────────────────────

add_action( 'wp_ajax_idxihf_preview', function () {
    check_ajax_referer( 'idxihf_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $pairs = json_decode( stripslashes( $_POST['pairs'] ?? '[]' ), true );
    if ( ! is_array( $pairs ) || empty( $pairs ) ) wp_send_json_error( 'No pairs provided.' );

    $search_host = idxihf_get_search_host();
    $preview     = idxihf_process_pairs( $pairs, $search_host, $dry_run = true );

    wp_send_json_success( $preview );
} );

// ── AJAX: apply replacements ───────────────────────────────────────────────────

add_action( 'wp_ajax_idxihf_apply', function () {
    check_ajax_referer( 'idxihf_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $pairs = json_decode( stripslashes( $_POST['pairs'] ?? '[]' ), true );
    if ( ! is_array( $pairs ) || empty( $pairs ) ) wp_send_json_error( 'No pairs provided.' );

    $search_host = idxihf_get_search_host();
    $result      = idxihf_process_pairs( $pairs, $search_host, $dry_run = false );

    wp_send_json_success( $result );
} );

// ── Core replacement engine ────────────────────────────────────────────────────

/**
 * Process all matched pairs against post_content and widgets.
 *
 * @param array  $pairs       [ ['idx_id'=>..., 'idx_name'=>..., 'ihf_id'=>..., 'ihf_url'=>...], ... ]
 * @param string $search_host Hostname of the IDX search subdomain (e.g. search.domain.com)
 * @param bool   $dry_run     If true, report changes without writing to DB.
 * @return array Summary: posts_updated, widgets_converted, log[]
 */
function idxihf_process_pairs( array $pairs, string $search_host, bool $dry_run ): array {
    global $wpdb;

    $posts_updated    = 0;
    $widgets_converted = 0;
    $log              = [];

    foreach ( $pairs as $pair ) {
        $idx_id   = sanitize_text_field( $pair['idx_id']   ?? '' );
        $idx_name = sanitize_text_field( $pair['idx_name'] ?? '' );
        $ihf_id   = sanitize_text_field( $pair['ihf_id']   ?? '' );
        $ihf_url  = esc_url_raw( $pair['ihf_url']          ?? '' );

        if ( ! $idx_id || ! $ihf_id ) continue;

        $new_sc    = "[optima_express_toppicks id={$ihf_id}]";
        $idx_slug  = idxihf_name_to_slug( $idx_name );

        // ── Post content ────────────────────────────────────────────────────────
        // Build LIKE patterns that will catch posts containing this saved link
        $like_showcase = '%saved_link_id%' . $wpdb->esc_like( $idx_id ) . '%';
        $like_url      = '%/i/' . $wpdb->esc_like( $idx_slug ) . '%';

        $sql   = "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                  WHERE post_status NOT IN ('trash','auto-draft')
                    AND ( post_content LIKE %s OR post_content LIKE %s )";
        $posts = $wpdb->get_results(
            $wpdb->prepare( $sql, $like_showcase, $like_url ),
            ARRAY_A
        );

        foreach ( $posts as $post ) {
            $original = $post['post_content'];
            $updated  = $original;

            // 1. [impress_property_showcase ... saved_link_id="ID" ...]
            $updated = preg_replace(
                '/\[impress_property_showcase\b[^\]]*\bsaved_link_id=["\']?' . preg_quote( $idx_id, '/' ) . '["\']?[^\]]*\]/i',
                $new_sc,
                $updated
            );

            // 2. [IDX-savedlinks id="ID"] or [IDX-savedlinks id=ID]
            $updated = preg_replace(
                '/\[IDX-savedlinks\b[^\]]*\bid=["\']?' . preg_quote( $idx_id, '/' ) . '["\']?[^\]]*\]/i',
                $new_sc,
                $updated
            );

            // 3. Saved-link URL: https://search.domain.com/i/slug  (with or without trailing path)
            if ( $search_host && $idx_slug ) {
                $url_re = '#https?://' . preg_quote( $search_host, '#' ) . '/i/' . preg_quote( $idx_slug, '#' ) . '(?:[/?#][^"\'<\s]*)?#';
                $replacement = $ihf_url ?: home_url( '/idx/' . $idx_slug . '/' );
                $updated = preg_replace( $url_re, $replacement, $updated );
            }

            if ( $updated !== $original ) {
                if ( ! $dry_run ) {
                    $wpdb->update(
                        $wpdb->posts,
                        [ 'post_content' => $updated ],
                        [ 'ID'           => (int) $post['ID'] ]
                    );
                    clean_post_cache( (int) $post['ID'] );
                }
                $posts_updated++;
                $log[] = ( $dry_run ? '[DRY RUN] ' : '' ) .
                         "Post #{$post['ID']} \"{$post['post_title']}\": IDX saved link #{$idx_id} → {$new_sc}";
            }
        }

        // ── Widgets ─────────────────────────────────────────────────────────────
        // IDX Broker Platinum / IMPress widget option keys to check
        $widget_bases = [
            'idx-broker-platinum-showcase',
            'impress-showcase-widget',
            'idx_broker_platinum_showcase',
            'idx-broker-widget',
        ];

        foreach ( $widget_bases as $base ) {
            $opt_key   = 'widget_' . $base;
            $instances = get_option( $opt_key );
            if ( ! is_array( $instances ) ) continue;

            foreach ( $instances as $i => $inst ) {
                if ( ! is_array( $inst ) ) continue;
                $saved_id = (string) ( $inst['saved_link_id'] ?? $inst['savedLinkId'] ?? $inst['saved-link-id'] ?? '' );
                if ( $saved_id !== $idx_id ) continue;

                $widgets_converted++;
                $title = $inst['title'] ?? '';
                $log[] = ( $dry_run ? '[DRY RUN] ' : '' ) .
                         "Widget {$base}[{$i}] \"{$title}\": IDX saved link #{$idx_id} → {$new_sc}";

                if ( ! $dry_run ) {
                    // Remove the IDX widget instance
                    unset( $instances[ $i ] );
                    update_option( $opt_key, $instances );

                    // Insert a replacement Text widget
                    $text_instances = get_option( 'widget_text', [] );
                    if ( ! is_array( $text_instances ) ) $text_instances = [];
                    $int_keys = array_filter( array_keys( $text_instances ), 'is_int' );
                    $new_idx  = $int_keys ? max( $int_keys ) + 1 : 1;

                    $text_instances[ $new_idx ] = [
                        'title'  => $title,
                        'text'   => $new_sc,
                        'filter' => true,
                        'visual' => false,
                    ];
                    update_option( 'widget_text', $text_instances );

                    // Update sidebar assignment
                    $sidebars = get_option( 'sidebars_widgets', [] );
                    foreach ( $sidebars as $sid => &$items ) {
                        if ( ! is_array( $items ) ) continue;
                        $pos = array_search( $base . '-' . $i, $items, true );
                        if ( $pos !== false ) {
                            $items[ $pos ] = 'text-' . $new_idx;
                        }
                    }
                    unset( $items );
                    update_option( 'sidebars_widgets', $sidebars );
                }
            }
        }
    }

    return [
        'posts_updated'     => $posts_updated,
        'widgets_converted' => $widgets_converted,
        'log'               => $log,
        'dry_run'           => $dry_run,
    ];
}

// ── Parse hotsheet-list API response ──────────────────────────────────────────

/**
 * Returns array of ['id'=>..., 'name'=>..., 'url'=>...] or an error string.
 */
function idxihf_parse_hotsheets( string $body ) {
    $trimmed = ltrim( $body );

    // XML wrapper (iHF sometimes wraps JSON/serialized data in <ihfContent>)
    if ( str_starts_with( $trimmed, '<' ) ) {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        libxml_clear_errors();
        if ( $xml ) {
            foreach ( $xml->children() as $node ) {
                $text = trim( (string) $node );

                if ( stripos( $text, 'pending account' ) !== false ) {
                    return 'Your iHomefinder account is marked as Pending. Complete activation in the iHomefinder dashboard.';
                }

                $decoded = json_decode( $text, true );
                if ( is_array( $decoded ) ) {
                    $result = idxihf_normalise_hotsheets( $decoded );
                    if ( ! empty( $result ) ) return $result;
                }

                $uns = @unserialize( $text );
                if ( is_array( $uns ) ) {
                    $result = idxihf_normalise_hotsheets( $uns );
                    if ( ! empty( $result ) ) return $result;
                }
            }
        }
    }

    // Plain JSON
    $data = json_decode( $body, true );
    if ( is_array( $data ) ) return idxihf_normalise_hotsheets( $data );

    // PHP-serialized
    $data = @unserialize( $body );
    if ( is_array( $data ) ) return idxihf_normalise_hotsheets( $data );

    return 'Could not parse iHF response. Raw: ' . mb_substr( $body, 0, 400 );
}

function idxihf_normalise_hotsheets( array $data ): array {
    $list = $data['hotsheets']     ??
            $data['markets']       ??
            $data['savedSearches'] ??
            $data['data']          ??
            $data;

    $result = [];
    foreach ( (array) $list as $item ) {
        if ( ! is_array( $item ) ) continue;

        $name = $item['name']         ?? $item['title']      ??
                $item['hotsheetName'] ?? $item['linkName']   ?? '';
        $url  = $item['url']          ?? $item['link']        ??
                $item['pageUrl']      ?? $item['permalink']   ?? '';
        // Capture the numeric hotsheet ID — the value needed for [optima_express_toppicks id=X]
        $id   = (string) ( $item['id']            ?? $item['hotsheetId']    ??
                            $item['marketId']      ?? $item['savedSearchId'] ??
                            $item['hotsheet_id']   ?? '' );

        if ( $name ) {
            $result[] = [
                'id'   => $id,
                'name' => trim( $name ),
                'url'  => trim( $url ),
            ];
        }
    }

    usort( $result, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    return $result;
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function idxihf_name_to_slug( string $name ): string {
    $s = strtolower( trim( $name ) );
    $s = preg_replace( '/[^a-z0-9\s-]/', '', $s );
    $s = preg_replace( '/[\s-]+/', '-', $s );
    return trim( $s, '-' );
}

function idxihf_get_search_host(): string {
    if ( function_exists( 'idx_scanner_get_search_domain' ) ) {
        return idx_scanner_get_search_domain();
    }
    $host = get_option( 'isse_subdomain', '' );
    if ( $host && str_contains( $host, '://' ) ) {
        $host = wp_parse_url( $host, PHP_URL_HOST ) ?: '';
    }
    return trim( $host );
}

// ── Admin page ─────────────────────────────────────────────────────────────────

function idxihf_render_page() {
    $nonce       = wp_create_nonce( 'idxihf_nonce' );
    $search_host = idxihf_get_search_host();
    $auth_token  = get_option( 'ihf_authentication_token', '' ) ?: get_option( 'ihf_activation_token', '' );
    ?>
    <div class="wrap">
        <h1>IDX Broker → iHomeFinder Migration</h1>

        <div class="notice notice-warning inline" style="max-width:740px;">
            <p>
                <strong>Before using this tool:</strong> back up your WordPress database.
                Replacements directly modify <code>post_content</code> and widget settings.
                Use the <strong>Preview</strong> button first to see exactly what will change.
            </p>
        </div>

        <p style="max-width:740px;color:#444;margin-top:12px;">
            This tool replaces IDX Broker saved-link shortcodes and URLs with
            <code>[optima_express_toppicks id=X]</code> Optima Express shortcodes.
            Widget instances are converted to Text widgets containing the new shortcode.
            General IDX links (<code>/featured</code>, <code>/search</code>, etc.) are
            <strong>not touched</strong> — those are handled by redirects.
        </p>

        <?php if ( ! $auth_token ) : ?>
            <div class="notice notice-error inline" style="max-width:740px;">
                <p>No Optima Express authentication token found. Make sure the Optima Express plugin is installed and activated.</p>
            </div>
        <?php endif; ?>
        <?php if ( ! $search_host ) : ?>
            <div class="notice notice-warning inline" style="max-width:740px;">
                <p>No IDX search subdomain detected. Save your API key in the <strong>IDX Saved Searches</strong> plugin settings so saved-link URLs can be matched.</p>
            </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;margin:20px 0;flex-wrap:wrap;align-items:center;">
            <button id="idxihf-load-idx" class="button button-primary">1. Load IDX Saved Searches</button>
            <button id="idxihf-load-ihf" class="button button-primary">2. Load iHF Markets</button>
            <button id="idxihf-match"    class="button" disabled>3. Auto-Match</button>
            <span style="flex:1"></span>
            <button id="idxihf-preview"  class="button" disabled style="margin-right:4px;">Preview Changes</button>
            <button id="idxihf-apply"    class="button" disabled style="background:#c00;color:#fff;border-color:#a00;">Apply Replacements</button>
        </div>

        <div id="idxihf-status" style="margin-bottom:14px;padding:8px 12px;background:#f0f0f1;border-left:4px solid #72aee6;display:none;max-width:700px;"></div>

        <div id="idxihf-mapping-wrap" style="display:none;">
            <h2 style="margin-bottom:6px;">Mapping Table</h2>
            <p style="color:#555;max-width:700px;margin-top:0;">
                Review each match. Use the dropdown to change an assignment.
                Rows set to <em>"— skip —"</em> will not be modified.
                If an iHF market shows <em>no ID</em>, enter it manually — it's the Hot Sheet ID
                from <strong>iHomeFinder Control Panel → Listings → Hot Sheets</strong>.
            </p>
            <table class="widefat striped" style="max-width:960px;margin-bottom:16px;">
                <thead>
                    <tr>
                        <th style="width:30%">IDX Broker Saved Search</th>
                        <th style="width:35%">→ iHF Market</th>
                        <th style="width:20%">Hotsheet ID</th>
                        <th style="width:15%">Shortcode Preview</th>
                    </tr>
                </thead>
                <tbody id="idxihf-tbody"></tbody>
            </table>
        </div>

        <div id="idxihf-results" style="margin-top:16px;max-width:700px;"></div>
    </div>

    <style>
    #idxihf-tbody td { vertical-align: middle; padding: 8px 10px; }
    #idxihf-tbody select { min-width: 220px; }
    #idxihf-tbody input.ihf-id-input { width: 80px; font-family: monospace; }
    .idxihf-sc-preview { font-family: monospace; font-size: 12px; color: #444; }
    .idxihf-no-id { color: #c00; font-size: 11px; }
    </style>

    <script>
    (function () {
        'use strict';

        const nonce   = <?php echo wp_json_encode( $nonce ); ?>;
        const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

        let idxData = [];
        let ihfData = [];

        // ── DOM refs ──────────────────────────────────────────────────────────
        const btnLoadIdx = document.getElementById('idxihf-load-idx');
        const btnLoadIhf = document.getElementById('idxihf-load-ihf');
        const btnMatch   = document.getElementById('idxihf-match');
        const btnPreview = document.getElementById('idxihf-preview');
        const btnApply   = document.getElementById('idxihf-apply');
        const statusEl   = document.getElementById('idxihf-status');
        const mappingDiv = document.getElementById('idxihf-mapping-wrap');
        const tbody      = document.getElementById('idxihf-tbody');
        const resultsEl  = document.getElementById('idxihf-results');

        // ── Helpers ───────────────────────────────────────────────────────────
        function esc(s) {
            const d = document.createElement('div');
            d.textContent = String(s ?? '');
            return d.innerHTML;
        }

        function setStatus(msg, type) {
            statusEl.style.display = '';
            statusEl.innerHTML     = msg;
            const colors = { ok: '#2a6e2a', err: '#a00', info: '#2271b1', warn: '#996800' };
            statusEl.style.borderLeftColor = colors[type] || colors.info;
        }

        function post(action, extra = {}) {
            return fetch(ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action, _ajax_nonce: nonce, ...extra }),
            }).then(r => r.json());
        }

        function getPairs() {
            const rows  = tbody.querySelectorAll('tr[data-idx-id]');
            const pairs = [];
            rows.forEach(row => {
                const sel = row.querySelector('select.ihf-select');
                if ( ! sel || ! sel.value ) return;  // skipped

                const idInput = row.querySelector('input.ihf-id-input');
                const ihfId   = idInput ? idInput.value.trim() : '';
                if ( ! ihfId ) return;  // no ID, cannot generate shortcode

                const ihfUrl = sel.options[sel.selectedIndex]?.dataset?.url || '';
                pairs.push({
                    idx_id:   row.dataset.idxId,
                    idx_name: row.dataset.idxName,
                    ihf_id:   ihfId,
                    ihf_url:  ihfUrl,
                });
            });
            return pairs;
        }

        function updateShortcodePreview(row) {
            const idInput = row.querySelector('input.ihf-id-input');
            const scEl    = row.querySelector('.idxihf-sc-preview');
            if ( ! idInput || ! scEl ) return;
            const id = idInput.value.trim();
            scEl.textContent = id ? '[optima_express_toppicks id=' + id + ']' : '—';
        }

        // ── Load IDX data ─────────────────────────────────────────────────────
        btnLoadIdx.addEventListener('click', function () {
            this.disabled = true;
            setStatus('Fetching IDX Broker saved searches…', 'info');
            post('idxihf_fetch_idx').then(json => {
                this.disabled = false;
                if ( ! json.success ) { setStatus('IDX Error: ' + esc(json.data), 'err'); return; }
                idxData = json.data;
                setStatus('IDX Broker: <strong>' + idxData.length + '</strong> saved searches loaded.', 'ok');
                tryEnableMatch();
            }).catch(e => { this.disabled = false; setStatus('Request failed: ' + esc(e.message), 'err'); });
        });

        // ── Load iHF data ─────────────────────────────────────────────────────
        btnLoadIhf.addEventListener('click', function () {
            this.disabled = true;
            setStatus('Fetching iHF markets from Optima Express…', 'info');
            post('idxihf_fetch_markets').then(json => {
                this.disabled = false;
                if ( ! json.success ) { setStatus('iHF Error: ' + esc(json.data), 'err'); return; }
                ihfData = json.data;
                const noId = ihfData.filter(m => ! m.id).length;
                let msg = 'iHomeFinder: <strong>' + ihfData.length + '</strong> markets loaded.';
                if ( noId ) msg += ' <span style="color:#996800">⚠ ' + noId + ' market(s) have no numeric ID in the API response — you\'ll need to enter those manually.</span>';
                setStatus(msg, 'ok');
                tryEnableMatch();
            }).catch(e => { this.disabled = false; setStatus('Request failed: ' + esc(e.message), 'err'); });
        });

        function tryEnableMatch() {
            if ( idxData.length && ihfData.length ) btnMatch.disabled = false;
        }

        // ── Auto-match ────────────────────────────────────────────────────────
        btnMatch.addEventListener('click', function () {
            tbody.innerHTML = '';

            idxData.forEach(idx => {
                const idxLower = idx.name.toLowerCase().trim();

                // Exact name match first; then substring match
                let match = ihfData.find(m => m.name.toLowerCase().trim() === idxLower);
                if ( ! match ) {
                    match = ihfData.find(m =>
                        m.name.toLowerCase().includes(idxLower) ||
                        idxLower.includes(m.name.toLowerCase().trim())
                    );
                }

                const matchId  = match?.id  || '';
                const matchUrl = match?.url || '';

                // Build select options
                const opts = ihfData.map(m => {
                    const sel = m === match ? ' selected' : '';
                    return `<option value="${esc(m.id)}" data-url="${esc(m.url)}"${sel}>${esc(m.name)}${m.id ? ' [' + m.id + ']' : ' (no id)'}</option>`;
                }).join('');

                const row = document.createElement('tr');
                row.dataset.idxId   = idx.id;
                row.dataset.idxName = idx.name;

                row.innerHTML = `
                    <td>
                        <strong>${esc(idx.name)}</strong>
                        <br><small style="color:#888">IDX id: ${esc(idx.id)}</small>
                    </td>
                    <td>
                        <select class="ihf-select">
                            <option value="">— skip —</option>
                            ${opts}
                        </select>
                    </td>
                    <td>
                        <input type="text" class="ihf-id-input" value="${esc(matchId)}"
                               placeholder="e.g. 12345" title="Hotsheet ID from iHF Control Panel">
                        ${matchId ? '' : '<br><span class="idxihf-no-id">Enter ID manually</span>'}
                    </td>
                    <td>
                        <span class="idxihf-sc-preview">${matchId ? '[optima_express_toppicks id=' + esc(matchId) + ']' : '—'}</span>
                    </td>`;

                tbody.appendChild(row);

                // When dropdown changes: pre-fill ID from the selected market
                row.querySelector('select.ihf-select').addEventListener('change', function () {
                    const opt    = this.options[this.selectedIndex];
                    const newId  = this.value ? opt.dataset.id || '' : '';
                    // The option value IS the id; extract from text if needed
                    const selMkt = ihfData.find(m => m.id === this.value);
                    const idInput = row.querySelector('input.ihf-id-input');
                    if ( selMkt ) idInput.value = selMkt.id || '';
                    updateShortcodePreview(row);
                });

                row.querySelector('input.ihf-id-input').addEventListener('input', () => {
                    updateShortcodePreview(row);
                });
            });

            mappingDiv.style.display = '';
            btnPreview.disabled      = false;
            btnApply.disabled        = false;

            const matched = idxData.filter(idx => {
                const idxLower = idx.name.toLowerCase().trim();
                return ihfData.some(m => m.name.toLowerCase().trim() === idxLower ||
                    m.name.toLowerCase().includes(idxLower) ||
                    idxLower.includes(m.name.toLowerCase().trim()));
            }).length;

            setStatus(
                'Auto-matched <strong>' + matched + ' / ' + idxData.length + '</strong> saved searches. ' +
                'Review the table, then click <strong>Preview Changes</strong> before applying.',
                'ok'
            );
        });

        // ── Preview ───────────────────────────────────────────────────────────
        btnPreview.addEventListener('click', function () {
            const pairs = getPairs();
            if ( ! pairs.length ) { setStatus('No matched rows with IDs to preview.', 'warn'); return; }

            this.disabled = true;
            setStatus('Running preview (no changes will be made)…', 'info');

            post('idxihf_preview', { pairs: JSON.stringify(pairs) }).then(json => {
                this.disabled = false;
                if ( ! json.success ) { setStatus('Preview error: ' + esc(json.data), 'err'); return; }
                const d = json.data;
                renderResults(d, true);
                setStatus(
                    '[Preview] Would update <strong>' + d.posts_updated + '</strong> post(s) and convert <strong>' + d.widgets_converted + '</strong> widget(s).',
                    d.posts_updated + d.widgets_converted > 0 ? 'ok' : 'warn'
                );
            }).catch(e => { this.disabled = false; setStatus('Request failed: ' + esc(e.message), 'err'); });
        });

        // ── Apply ─────────────────────────────────────────────────────────────
        btnApply.addEventListener('click', function () {
            const pairs = getPairs();
            if ( ! pairs.length ) { setStatus('No matched rows with IDs to apply.', 'warn'); return; }

            if ( ! confirm(
                'Apply ' + pairs.length + ' replacement(s)?\n\n' +
                'This will modify post content and widget settings.\n' +
                'Make sure you have a database backup before proceeding.'
            ) ) return;

            this.disabled = true;
            setStatus('Applying replacements…', 'info');

            post('idxihf_apply', { pairs: JSON.stringify(pairs) }).then(json => {
                this.disabled = false;
                if ( ! json.success ) { setStatus('Apply error: ' + esc(json.data), 'err'); return; }
                const d = json.data;
                renderResults(d, false);
                setStatus(
                    'Done! Updated <strong>' + d.posts_updated + '</strong> post(s), converted <strong>' + d.widgets_converted + '</strong> widget(s).',
                    'ok'
                );
            }).catch(e => { this.disabled = false; setStatus('Request failed: ' + esc(e.message), 'err'); });
        });

        function renderResults(d, isDryRun) {
            const label = isDryRun ? 'Preview (no changes made)' : 'Change Log';
            if ( ! d.log || ! d.log.length ) {
                resultsEl.innerHTML = `<p style="color:#888">${isDryRun ? 'No changes would be made with current mapping.' : 'No changes made.'}</p>`;
                return;
            }
            resultsEl.innerHTML =
                '<h3>' + esc(label) + '</h3>' +
                '<ul style="list-style:disc;padding-left:20px;">' +
                d.log.map(l => '<li style="margin-bottom:4px;">' + esc(l) + '</li>').join('') +
                '</ul>';
        }

    })();
    </script>
    <?php
}
