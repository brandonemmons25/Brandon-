<?php
/**
 * Plugin Name: iHF Migration Setup
 * Description: Auto-configures staging for IDX → iHomeFinder migration on activation. Exposes a REST endpoint so Claude Code can retrieve MCP credentials and run the migration autonomously.
 * Version:     2.0.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'IMS_VERSION', '2.1.0' );
define( 'IMS_FILE',    __FILE__ );
define( 'IMS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'IMS_URL',     plugin_dir_url( __FILE__ ) );

// Option keys
define( 'IMS_OPT_STATUS',   'ims_status' );
define( 'IMS_OPT_APP_PASS', 'ims_app_password' );
define( 'IMS_OPT_MARKETS',  'ims_markets' );
define( 'IMS_OPT_CLAUDE_MD','ims_claude_md' );
define( 'IMS_OPT_SCAN',       'ims_scan_results' );
define( 'IMS_OPT_IDX_DOMAIN', 'ims_idx_search_domain' );

require_once IMS_DIR . 'migration-runner.php';

// ── Activation: auto-run all setup ────────────────────────────────────────────

register_activation_hook( IMS_FILE, 'ims_on_activation' );

function ims_on_activation(): void {
    $status = [ 'activated_at' => current_time( 'mysql' ) ];

    $user_result             = ims_do_create_user();
    $status['user']          = $user_result;

    $snippet_result          = ims_do_install_snippets();
    $status['snippets']      = $snippet_result;

    $markets_result          = ims_do_fetch_markets();
    $markets                 = is_array( $markets_result ) ? $markets_result : [];
    $status['markets_count'] = count( $markets );
    $status['markets_error'] = is_string( $markets_result ) ? $markets_result : null;

    $scan_result          = ims_do_run_scanner();
    $status['scan_pages'] = $scan_result['pages_count'] ?? 0;
    $status['scan_posts'] = $scan_result['posts_count'] ?? 0;
    $status['scan_menus'] = $scan_result['menus_count'] ?? 0;
    $status['scan_error'] = $scan_result['error'] ?? null;

    ims_do_generate_claude_md( $markets, $scan_result['data'] ?? [] );

    update_option( IMS_OPT_STATUS, $status, false );
}

// ── Internal setup functions ───────────────────────────────────────────────────

function ims_do_create_user(): array {
    $username = 'claude-mcp';
    $existing = get_user_by( 'login', $username );

    if ( $existing ) {
        $user_id = $existing->ID;
        $existing->set_role( 'administrator' );
    } else {
        $email   = $username . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
        $user_id = wp_create_user( $username, wp_generate_password( 32 ), $email );
        if ( is_wp_error( $user_id ) ) {
            return [ 'success' => false, 'error' => $user_id->get_error_message() ];
        }
        $user = new WP_User( $user_id );
        $user->set_role( 'administrator' );
    }

    // Remove any existing 'Claude MCP' app password then create fresh
    $app_name          = 'Claude MCP';
    $existing_app_pws  = WP_Application_Passwords::get_user_application_passwords( $user_id );
    foreach ( $existing_app_pws as $ap ) {
        if ( $ap['name'] === $app_name ) {
            WP_Application_Passwords::delete_application_password( $user_id, $ap['uuid'] );
        }
    }

    $result = WP_Application_Passwords::create_new_application_password(
        $user_id,
        [ 'name' => $app_name ]
    );

    if ( is_wp_error( $result ) ) {
        return [ 'success' => false, 'error' => $result->get_error_message() ];
    }

    // Strip spaces WP adds to the plaintext password for display formatting
    $plain = str_replace( ' ', '', $result[0] );

    update_option( IMS_OPT_APP_PASS, $plain, false );

    return [
        'success'  => true,
        'user_id'  => $user_id,
        'username' => $username,
        'created'  => ! $existing,
    ];
}

function ims_do_install_snippets(): array {
    if ( ! post_type_exists( 'wpcode_snippet' ) ) {
        return [ 'success' => false, 'error' => 'WPCode plugin is not active.' ];
    }

    $snippets  = ims_get_snippets();
    $installed = [];
    $skipped   = [];

    foreach ( $snippets as $snippet ) {
        $existing = get_posts( [
            'post_type'      => 'wpcode_snippet',
            'post_status'    => 'any',
            'title'          => $snippet['title'],
            'posts_per_page' => 1,
        ] );

        if ( $existing ) {
            $skipped[] = $snippet['title'];
            continue;
        }

        $post_id = wp_insert_post( [
            'post_title'   => $snippet['title'],
            'post_content' => $snippet['code'],
            'post_type'    => 'wpcode_snippet',
            'post_status'  => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) {
            return [ 'success' => false, 'error' => 'Failed to install: ' . $snippet['title'] ];
        }

        update_post_meta( $post_id, '_wpcode_snippet_type',   'php' );
        update_post_meta( $post_id, '_wpcode_snippet_status', 1 );
        update_post_meta( $post_id, '_wpcode_snippet_scope',  'global' );

        $installed[] = $snippet['title'];
    }

    return [ 'success' => true, 'installed' => $installed, 'skipped' => $skipped ];
}

function ims_do_fetch_markets() {
    $auth_token = get_option( 'ihf_authentication_token', '' )
               ?: get_option( 'ihf_activation_token', '' );

    if ( ! $auth_token ) {
        return 'No Optima Express authentication token found.';
    }

    $resp = wp_remote_get( add_query_arg( [
        'method'              => 'handleRequest',
        'requestType'         => 'hotsheet-list',
        'viewType'            => 'json',
        'phpStyle'            => 'true',
        'authenticationToken' => $auth_token,
    ], 'https://www.idxhome.com/service/wordpress' ), [ 'timeout' => 20 ] );

    if ( is_wp_error( $resp ) ) {
        return $resp->get_error_message();
    }

    $body    = wp_remote_retrieve_body( $resp );
    $markets = ims_parse_markets( $body );

    if ( is_array( $markets ) ) {
        update_option( IMS_OPT_MARKETS, $markets, false );
    }

    return $markets;
}

function ims_do_run_scanner(): array {
    if ( ! function_exists( 'idx_scanner_run_full_scan' ) ) {
        return [ 'error' => 'AiDX Scanner plugin is not active.' ];
    }
    $scan = idx_scanner_run_full_scan();
    $result = [
        'data'        => $scan,
        'pages_count' => count( $scan['pages'] ?? [] ),
        'posts_count' => count( $scan['posts'] ?? [] ),
        'menus_count' => count( $scan['menus'] ?? [] ),
    ];
    update_option( IMS_OPT_SCAN, $scan, false );
    return $result;
}

function ims_do_generate_claude_md( array $markets, array $scan = [] ): void {
    $site_name = get_bloginfo( 'name' );
    $site_url  = get_site_url();

    if ( empty( $markets ) ) {
        $market_table = "| Market Name | ID | listing-report URL |\n|---|---|---|\n| (no markets found — run Refresh) | — | — |\n";
    } else {
        $market_table = "| Market Name | ID | listing-report URL |\n|---|---|---|\n";
        foreach ( $markets as $m ) {
            $market_table .= "| {$m['name']} | " . ( $m['id'] ?: '—' ) . " | " . ( $m['url'] ?: '—' ) . " |\n";
        }
    }

    $md = ims_claude_md_template( $site_name, $site_url, $market_table, $scan );
    update_option( IMS_OPT_CLAUDE_MD, $md, false );
}

// ── REST API ───────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {

    // POST /wp-json/ims/v1/config
    // Body: { "username": "admin", "password": "wp-admin-password" }
    // Returns MCP config JSON, generated CLAUDE.md, and markets list
    register_rest_route( 'ims/v1', '/config', [
        'methods'             => 'POST',
        'callback'            => 'ims_rest_config',
        'permission_callback' => '__return_true',
        'args'                => [
            'username' => [ 'required' => true, 'type' => 'string' ],
            'password' => [ 'required' => true, 'type' => 'string' ],
        ],
    ] );

    // POST /wp-json/ims/v1/refresh
    // Re-fetches markets from iHF and regenerates CLAUDE.md (for when Optima Express
    // wasn't installed at activation time, or markets changed)
    register_rest_route( 'ims/v1', '/refresh', [
        'methods'             => 'POST',
        'callback'            => 'ims_rest_refresh',
        'permission_callback' => '__return_true',
        'args'                => [
            'username' => [ 'required' => true, 'type' => 'string' ],
            'password' => [ 'required' => true, 'type' => 'string' ],
        ],
    ] );

} );

function ims_rest_validate_admin( WP_REST_Request $request ): WP_User|WP_Error {
    $user = wp_authenticate(
        sanitize_user( $request->get_param( 'username' ) ),
        $request->get_param( 'password' )
    );
    if ( is_wp_error( $user ) ) {
        return new WP_Error( 'unauthorized', 'Invalid credentials.', [ 'status' => 401 ] );
    }
    if ( ! user_can( $user, 'manage_options' ) ) {
        return new WP_Error( 'forbidden', 'Administrator access required.', [ 'status' => 403 ] );
    }
    return $user;
}

function ims_rest_config( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $user = ims_rest_validate_admin( $request );
    if ( is_wp_error( $user ) ) return $user;

    $app_password = get_option( IMS_OPT_APP_PASS );
    if ( ! $app_password ) {
        return new WP_Error( 'not_configured', 'Plugin setup incomplete — re-activate the plugin.', [ 'status' => 500 ] );
    }

    $site_url  = get_site_url();
    $mcp_config = [
        'mcpServers' => [
            'wordpress' => [
                'command' => 'npx',
                'args'    => [ '-y', '@automattic/mcp-server-wordpress' ],
                'env'     => [
                    'WP_SITE_URL'     => $site_url,
                    'WP_USERNAME'     => 'claude-mcp',
                    'WP_APP_PASSWORD' => $app_password,
                ],
            ],
        ],
    ];

    $status = get_option( IMS_OPT_STATUS, [] );

    return new WP_REST_Response( [
        'mcp_config'  => $mcp_config,
        'claude_md'   => get_option( IMS_OPT_CLAUDE_MD, '' ),
        'markets'     => get_option( IMS_OPT_MARKETS, [] ),
        'scan'        => get_option( IMS_OPT_SCAN, [] ),
        'site_url'    => $site_url,
        'status'      => $status,
    ], 200 );
}

function ims_rest_refresh( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $user = ims_rest_validate_admin( $request );
    if ( is_wp_error( $user ) ) return $user;

    $markets_result = ims_do_fetch_markets();
    $markets        = is_array( $markets_result ) ? $markets_result : [];

    $scan_result = ims_do_run_scanner();

    ims_do_generate_claude_md( $markets, $scan_result['data'] ?? [] );

    $status = get_option( IMS_OPT_STATUS, [] );
    $status['markets_refreshed_at'] = current_time( 'mysql' );
    $status['markets_count']        = count( $markets );
    $status['markets_error']        = is_string( $markets_result ) ? $markets_result : null;
    $status['scan_pages']           = $scan_result['pages_count'] ?? 0;
    $status['scan_posts']           = $scan_result['posts_count'] ?? 0;
    $status['scan_menus']           = $scan_result['menus_count'] ?? 0;
    $status['scan_error']           = $scan_result['error'] ?? null;
    update_option( IMS_OPT_STATUS, $status );

    return new WP_REST_Response( [
        'success'       => true,
        'markets_count' => count( $markets ),
        'scan_pages'    => $scan_result['pages_count'] ?? 0,
        'scan_posts'    => $scan_result['posts_count'] ?? 0,
        'scan_menus'    => $scan_result['menus_count'] ?? 0,
        'error'         => is_string( $markets_result ) ? $markets_result : null,
        'claude_md'     => get_option( IMS_OPT_CLAUDE_MD, '' ),
    ], 200 );
}

// ── WPCode snippet definitions ─────────────────────────────────────────────────

function ims_get_snippets(): array {
    return [
        [
            'title' => 'Enable Core Abilities for MCP',
            'code'  => <<<'PHP'
add_filter( 'wp_register_ability_args', function( $args, $name ) {
    if ( ! isset( $args['meta'] ) ) {
        $args['meta'] = array();
    }
    if ( ! isset( $args['meta']['mcp'] ) ) {
        $args['meta']['mcp'] = array();
    }
    $args['meta']['mcp']['public'] = true;
    return $args;
}, 10, 2 );
PHP,
        ],
        [
            'title' => 'WordPress Content Abilities for MCP',
            'code'  => <<<'PHP'
add_action( 'wp_abilities_api_init', function() {

    wp_register_ability( 'migration/get-pages', array(
        'label'       => 'Get All Pages',
        'description' => 'Returns all published pages with their content, URL, and ID',
        'category'    => 'site',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'per_page' => array(
                    'type' => 'integer',
                    'description' => 'Number of pages to return',
                    'default' => 100,
                ),
            ),
        ),
        'execute_callback' => function( $input ) {
            $pages = get_posts( array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => $input['per_page'] ?? 100,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );
            $result = array();
            foreach ( $pages as $page ) {
                $result[] = array(
                    'ID'      => $page->ID,
                    'title'   => $page->post_title,
                    'url'     => get_permalink( $page->ID ),
                    'content' => $page->post_content,
                    'slug'    => $page->post_name,
                );
            }
            return $result;
        },
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

    wp_register_ability( 'migration/update-page', array(
        'label'       => 'Update Page Content',
        'description' => 'Updates the content of a WordPress page by ID',
        'category'    => 'site',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'page_id' => array( 'type' => 'integer', 'description' => 'The page ID to update' ),
                'content' => array( 'type' => 'string',  'description' => 'The new page content (HTML/block markup)' ),
            ),
            'required' => array( 'page_id', 'content' ),
        ),
        'execute_callback' => function( $input ) {
            $result = wp_update_post( array( 'ID' => $input['page_id'], 'post_content' => $input['content'] ), true );
            if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
            return array( 'success' => true, 'page_id' => $result );
        },
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

    wp_register_ability( 'migration/get-menus', array(
        'label'       => 'Get Navigation Menus',
        'description' => 'Returns all navigation menus and their items with URLs',
        'category'    => 'site',
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'execute_callback' => function( $input ) {
            $menus  = wp_get_nav_menus();
            $result = array();
            foreach ( $menus as $menu ) {
                $items      = wp_get_nav_menu_items( $menu->term_id );
                $menu_items = array();
                if ( $items ) {
                    foreach ( $items as $item ) {
                        $menu_items[] = array( 'ID' => $item->ID, 'title' => $item->title, 'url' => $item->url, 'parent' => $item->menu_item_parent );
                    }
                }
                $result[] = array( 'menu_id' => $menu->term_id, 'menu_name' => $menu->name, 'items' => $menu_items );
            }
            return $result;
        },
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

    wp_register_ability( 'migration/update-menu-item', array(
        'label'       => 'Update Menu Item URL',
        'description' => 'Updates the URL of a navigation menu item by ID',
        'category'    => 'site',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'menu_item_id' => array( 'type' => 'integer', 'description' => 'The menu item post ID' ),
                'url'          => array( 'type' => 'string',  'description' => 'The new URL for the menu item' ),
            ),
            'required' => array( 'menu_item_id', 'url' ),
        ),
        'execute_callback' => function( $input ) {
            $result = update_post_meta( $input['menu_item_id'], '_menu_item_url', esc_url_raw( $input['url'] ) );
            return array( 'success' => (bool) $result, 'menu_item_id' => $input['menu_item_id'] );
        },
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

    wp_register_ability( 'migration/find-idx-content', array(
        'label'       => 'Find IDX Broker Content',
        'description' => 'Searches all pages, posts, and menus for IDX Broker shortcodes, links, and blocks',
        'category'    => 'site',
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'execute_callback' => function( $input ) {
            global $wpdb;
            $patterns = array( '%[IDX-%', '%[idx-%', '%[impress_%', '%idx-broker-platinum%', '%/idx/%' );
            $results  = array( 'pages' => array(), 'posts' => array(), 'menus' => array() );
            foreach ( $patterns as $pattern ) {
                $found = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = 'publish' AND post_type IN ('page','post')",
                    $pattern
                ) );
                foreach ( $found as $post ) {
                    $key = $post->post_type === 'page' ? 'pages' : 'posts';
                    $results[ $key ][ $post->ID ] = array( 'ID' => $post->ID, 'title' => $post->post_title, 'type' => $post->post_type, 'url' => get_permalink( $post->ID ), 'content' => $post->post_content );
                }
            }
            $menus = wp_get_nav_menus();
            foreach ( $menus as $menu ) {
                $items = wp_get_nav_menu_items( $menu->term_id );
                if ( $items ) {
                    foreach ( $items as $item ) {
                        if ( stripos( $item->url, '/idx/' ) !== false || stripos( $item->url, 'idxbroker' ) !== false ) {
                            $results['menus'][] = array( 'menu_name' => $menu->name, 'item_ID' => $item->ID, 'title' => $item->title, 'url' => $item->url );
                        }
                    }
                }
            }
            $results['pages'] = array_values( $results['pages'] );
            $results['posts'] = array_values( $results['posts'] );
            return $results;
        },
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

} );
PHP,
        ],
        [
            'title' => 'Migration Get Page by ID (+ search-content)',
            'code'  => <<<'PHP'
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'migration/get-page-by-id', array(
        'label'       => 'Get Page by ID',
        'description' => 'Returns a single page content by its WordPress ID',
        'category'    => 'site',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'page_id' => array( 'type' => 'integer', 'description' => 'The WordPress page ID' ),
            ),
            'required' => array( 'page_id' ),
        ),
        'execute_callback' => function( $input ) {
            $page = get_post( $input['page_id'] );
            if ( ! $page || $page->post_type !== 'page' ) return array( 'error' => 'Page not found' );
            return array( 'ID' => $page->ID, 'title' => $page->post_title, 'slug' => $page->post_name, 'url' => get_permalink( $page->ID ), 'content' => $page->post_content );
        },
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

    wp_register_ability( 'migration/search-content', array(
        'label'       => 'Search Content for Pattern',
        'description' => 'Searches all published pages and posts for a specific text pattern',
        'category'    => 'site',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'search'    => array( 'type' => 'string', 'description' => 'Text pattern to search for in post content' ),
                'post_type' => array( 'type' => 'string', 'description' => 'Post type to search (page or post)', 'default' => 'page' ),
            ),
            'required' => array( 'search' ),
        ),
        'execute_callback' => function( $input ) {
            global $wpdb;
            $search    = '%' . $wpdb->esc_like( $input['search'] ) . '%';
            $post_type = $input['post_type'] ?? 'page';
            $results   = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = 'publish' AND post_type = %s ORDER BY post_title ASC",
                $search, $post_type
            ) );
            $output = array();
            foreach ( $results as $row ) {
                $output[] = array( 'ID' => $row->ID, 'title' => $row->post_title, 'slug' => $row->post_name, 'url' => get_permalink( $row->ID ) );
            }
            return $output;
        },
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );
} );
PHP,
        ],
        [
            'title' => 'Migration Post Abilities',
            'code'  => <<<'PHP'
add_action( 'wp_abilities_api_init', function() {

    wp_register_ability( 'migration/get-post-by-id', array(
        'label'       => 'Get Post by ID',
        'description' => 'Returns a single post content by its WordPress ID',
        'category'    => 'site',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'description' => 'The WordPress post ID' ),
            ),
            'required' => array( 'post_id' ),
        ),
        'execute_callback' => function( $input ) {
            $post = get_post( $input['post_id'] );
            if ( ! $post ) return array( 'error' => 'Post not found' );
            return array(
                'ID'      => $post->ID,
                'title'   => $post->post_title,
                'slug'    => $post->post_name,
                'type'    => $post->post_type,
                'url'     => get_permalink( $post->ID ),
                'content' => $post->post_content,
            );
        },
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

    wp_register_ability( 'migration/update-post', array(
        'label'       => 'Update Post Content',
        'description' => 'Updates the content of a WordPress post by ID',
        'category'    => 'site',
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'post_id' => array( 'type' => 'integer', 'description' => 'The post ID to update' ),
                'content' => array( 'type' => 'string',  'description' => 'The new post content (HTML/block markup)' ),
            ),
            'required' => array( 'post_id', 'content' ),
        ),
        'execute_callback' => function( $input ) {
            $result = wp_update_post( array( 'ID' => $input['post_id'], 'post_content' => $input['content'] ), true );
            if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
            return array( 'success' => true, 'post_id' => $result );
        },
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );

} );
PHP,
        ],
    ];
}

// ── Market parsing helpers ─────────────────────────────────────────────────────

function ims_parse_markets( string $body ) {
    $trimmed = ltrim( $body );

    if ( str_starts_with( $trimmed, '<' ) ) {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        libxml_clear_errors();
        if ( $xml ) {
            foreach ( $xml->children() as $node ) {
                $text = trim( (string) $node );
                if ( stripos( $text, 'pending account' ) !== false ) {
                    return 'iHomeFinder account is Pending. Complete activation first.';
                }
                $decoded = json_decode( $text, true );
                if ( is_array( $decoded ) ) {
                    $r = ims_normalise_markets( $decoded );
                    if ( ! empty( $r ) ) return $r;
                }
                $uns = @unserialize( $text );
                if ( is_array( $uns ) ) {
                    $r = ims_normalise_markets( $uns );
                    if ( ! empty( $r ) ) return $r;
                }
            }
        }
    }

    $data = json_decode( $body, true );
    if ( is_array( $data ) ) return ims_normalise_markets( $data );

    $data = @unserialize( $body );
    if ( is_array( $data ) ) return ims_normalise_markets( $data );

    return 'Could not parse iHF response: ' . mb_substr( $body, 0, 300 );
}

function ims_normalise_markets( array $data ): array {
    $list   = $data['hotsheets'] ?? $data['markets'] ?? $data['savedSearches'] ?? $data['data'] ?? $data;
    $result = [];
    foreach ( (array) $list as $item ) {
        if ( ! is_array( $item ) ) continue;
        $name = $item['name'] ?? $item['title'] ?? $item['hotsheetName'] ?? $item['linkName'] ?? '';
        $id   = (string) ( $item['id'] ?? $item['hotsheetId'] ?? $item['marketId'] ?? $item['savedSearchId'] ?? $item['hotsheet_id'] ?? '' );
        $url  = trim( $item['url'] ?? $item['link'] ?? $item['pageUrl'] ?? $item['permalink'] ?? '' );

        if ( $url && preg_match( '#^https?://#', $url ) ) {
            $parsed = wp_parse_url( $url );
            $url    = ( $parsed['path'] ?? '/' ) . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
        }

        if ( $name ) {
            $result[] = [ 'id' => $id, 'name' => trim( $name ), 'url' => $url ];
        }
    }
    usort( $result, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    return $result;
}

// ── CLAUDE.md template ─────────────────────────────────────────────────────────

function ims_claude_md_template( string $site_name, string $site_url, string $market_table, array $scan = [] ): string {
    $template_path = IMS_DIR . 'CLAUDE.md';

    if ( file_exists( $template_path ) ) {
        $base = file_get_contents( $template_path );

        // Inject iHF markets
        $market_block = "### {$site_name}\n\nSite: {$site_url}\n\n{$market_table}";
        $replacement  = "<!-- MARKET_IDS_START -->\n{$market_block}\n<!-- MARKET_IDS_END -->";
        $base = preg_replace_callback(
            '/<!-- MARKET_IDS_START -->.*?<!-- MARKET_IDS_END -->/s',
            function () use ( $replacement ) { return $replacement; },
            $base
        );

        // Inject AiDX Scanner results
        $scan_block = ims_format_scan_results( $scan );
        $scan_replace = "<!-- SCAN_RESULTS_START -->\n{$scan_block}\n<!-- SCAN_RESULTS_END -->";
        $base = preg_replace_callback(
            '/<!-- SCAN_RESULTS_START -->.*?<!-- SCAN_RESULTS_END -->/s',
            function () use ( $scan_replace ) { return $scan_replace; },
            $base
        );

        return $base;
    }

    return "# CLAUDE.md — IDX Broker to iHomefinder Migration Agent\n\n"
         . "## Site: {$site_name}\n- **URL:** {$site_url}\n\n"
         . "## Client Market IDs\n{$market_table}\n\n"
         . "---\nNOTE: Full CLAUDE.md template not found in plugin directory.\n";
}

function ims_format_scan_results( array $scan ): string {
    if ( empty( $scan ) ) {
        return '(AiDX Scanner not active at activation time — install and re-run setup)';
    }

    $domain = $scan['search_domain'] ?? '';
    $at     = $scan['scanned_at'] ?? '';
    $menus  = $scan['menus'] ?? [];
    $pages  = $scan['pages'] ?? [];
    $posts  = $scan['posts'] ?? [];

    $out = "**Scanned:** {$at}";
    if ( $domain ) $out .= " | **IDX subdomain:** {$domain}";
    $out .= "\n\n";

    // Menus
    $out .= '### Nav Menu Items with IDX URLs (' . count( $menus ) . ")\n";
    if ( $menus ) {
        $out .= "| Menu | Item | Current URL |\n|---|---|---|\n";
        foreach ( $menus as $m ) {
            $out .= "| {$m['menu']} | {$m['item']} | {$m['url']} |\n";
        }
    } else {
        $out .= "(none found)\n";
    }

    $out .= "\n### Pages with IDX Content (" . count( $pages ) . ")\n";
    if ( $pages ) {
        $out .= "| Page | URL | IDX Elements Found |\n|---|---|---|\n";
        foreach ( $pages as $p ) {
            $elements = implode( '<br>', array_map( 'htmlspecialchars', $p['elements'] ) );
            $out .= "| {$p['title']} | {$p['url']} | {$elements} |\n";
        }
    } else {
        $out .= "(none found)\n";
    }

    $out .= "\n### Posts with IDX Content (" . count( $posts ) . ")\n";
    if ( $posts ) {
        $out .= "| Post | URL | IDX Elements Found |\n|---|---|---|\n";
        foreach ( $posts as $p ) {
            $elements = implode( '<br>', array_map( 'htmlspecialchars', $p['elements'] ) );
            $out .= "| {$p['title']} | {$p['url']} | {$elements} |\n";
        }
    } else {
        $out .= "(none found)\n";
    }

    return $out;
}

// ── Admin menu ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_menu_page(
        'iHF Migration Setup',
        'Migration Setup',
        'manage_options',
        'ihf-migration-setup',
        'ims_render_page',
        'dashicons-migrate',
        2
    );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_ihf-migration-setup' ) return;
    wp_enqueue_style(  'ims-admin', IMS_URL . 'assets/admin.css', [], IMS_VERSION );
    wp_enqueue_script( 'ims-admin', IMS_URL . 'assets/admin.js',  [ 'jquery' ], IMS_VERSION, true );
    wp_localize_script( 'ims-admin', 'IMS', [
        'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'ims_nonce' ),
        'siteUrl'  => get_site_url(),
        'restUrl'  => rest_url( 'ims/v1' ),
        'configEndpoint' => rest_url( 'ims/v1/config' ),
    ] );
} );

// AJAX: Re-run full setup (for admin page "Re-run" button)
add_action( 'wp_ajax_ims_rerun_setup', function () {
    check_ajax_referer( 'ims_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
    ims_on_activation();
    $status = get_option( IMS_OPT_STATUS, [] );
    wp_send_json_success( $status );
} );

// AJAX: Refresh markets + re-run scanner
add_action( 'wp_ajax_ims_refresh_markets', function () {
    check_ajax_referer( 'ims_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $markets_result = ims_do_fetch_markets();
    $markets        = is_array( $markets_result ) ? $markets_result : [];

    $scan_result = ims_do_run_scanner();

    ims_do_generate_claude_md( $markets, $scan_result['data'] ?? [] );

    $status = get_option( IMS_OPT_STATUS, [] );
    $status['markets_refreshed_at'] = current_time( 'mysql' );
    $status['markets_count']        = count( $markets );
    $status['markets_error']        = is_string( $markets_result ) ? $markets_result : null;
    $status['scan_pages']           = $scan_result['pages_count'] ?? 0;
    $status['scan_posts']           = $scan_result['posts_count'] ?? 0;
    $status['scan_menus']           = $scan_result['menus_count'] ?? 0;
    $status['scan_error']           = $scan_result['error'] ?? null;
    update_option( IMS_OPT_STATUS, $status );

    wp_send_json_success( [
        'markets_count' => count( $markets ),
        'scan_pages'    => $scan_result['pages_count'] ?? 0,
        'scan_posts'    => $scan_result['posts_count'] ?? 0,
        'scan_menus'    => $scan_result['menus_count'] ?? 0,
        'error'         => is_string( $markets_result ) ? $markets_result : null,
    ] );
} );

// AJAX: Save IDX search domain override then re-run scanner + regenerate CLAUDE.md
add_action( 'wp_ajax_ims_save_idx_domain', function () {
	check_ajax_referer( 'ims_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
	$domain = sanitize_text_field( wp_unslash( $_POST['idx_domain'] ?? '' ) );
	$domain = rtrim( trim( $domain ), '/' );
	if ( strpos( $domain, '://' ) !== false ) {
		$domain = parse_url( $domain, PHP_URL_HOST ) ?: $domain;
	}
	update_option( IMS_OPT_IDX_DOMAIN, $domain, false );

	// Re-run scanner with correct domain now available
	$scan_result = ims_do_run_scanner();
	$markets     = get_option( IMS_OPT_MARKETS, [] );
	ims_do_generate_claude_md( $markets, $scan_result['data'] ?? [] );

	wp_send_json_success( [
		'saved'      => $domain,
		'scan_pages' => $scan_result['pages_count'] ?? 0,
		'scan_posts' => $scan_result['posts_count'] ?? 0,
		'scan_menus' => $scan_result['menus_count'] ?? 0,
	] );
} );

// AJAX: Diagnostics — raw DB data to debug scanner misses
add_action( 'wp_ajax_ims_diagnostics', function () {
	check_ajax_referer( 'ims_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
	global $wpdb;

	// What idx_scanner_get_search_domain() finds
	$scanner_domain = function_exists( 'idx_scanner_get_search_domain' ) ? idx_scanner_get_search_domain() : 'AiDX Scanner not active';

	// Raw idxforza-info option
	$idxforza = get_option( 'idxforza-info', '(not set)' );

	// All nav menus
	$menus = wp_get_nav_menus();
	$menu_summary = [];
	foreach ( $menus as $m ) {
		$items = wp_get_nav_menu_items( $m->term_id ) ?: [];
		$menu_summary[] = [
			'name'  => $m->name,
			'count' => count( $items ),
			'sample_urls' => array_slice( array_column( array_map( fn($i) => ['url' => $i->url], $items ), 'url' ), 0, 5 ),
		];
	}

	// Direct DB count of nav_menu_items
	$nav_item_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item' AND post_status = 'publish'"
	);

	// Sample raw _menu_item_url values
	$raw_urls = $wpdb->get_col(
		"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_menu_item_url' AND pm.meta_value != ''
		 AND p.post_type = 'nav_menu_item'
		 LIMIT 20"
	);

	wp_send_json_success( [
		'scanner_domain'  => $scanner_domain,
		'idxforza_info'   => $idxforza,
		'ims_idx_domain'  => get_option( IMS_OPT_IDX_DOMAIN, '(not set)' ),
		'nav_item_count'  => $nav_item_count,
		'menus'           => $menu_summary,
		'raw_menu_urls'   => $raw_urls,
	] );
} );

// ── Migration AJAX handlers ────────────────────────────────────────────────────

add_action( 'wp_ajax_ims_migrate_menus', function () {
	check_ajax_referer( 'ims_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
	wp_send_json_success( ims_run_menu_migration( isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1' ) );
} );

add_action( 'wp_ajax_ims_migrate_pages', function () {
	check_ajax_referer( 'ims_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
	wp_send_json_success( ims_run_page_migration( isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1' ) );
} );

add_action( 'wp_ajax_ims_migrate_posts', function () {
	check_ajax_referer( 'ims_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
	wp_send_json_success( ims_run_post_migration( isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1' ) );
} );

add_action( 'wp_ajax_ims_migrate_all', function () {
	check_ajax_referer( 'ims_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
	$dry = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
	wp_send_json_success( [
		'menus' => ims_run_menu_migration( $dry ),
		'pages' => ims_run_page_migration( $dry ),
		'posts' => ims_run_post_migration( $dry ),
	] );
} );

add_action( 'wp_ajax_ims_verify_migration', function () {
	check_ajax_referer( 'ims_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );
	wp_send_json_success( ims_run_verify() );
} );

// ── Admin page (status dashboard) ─────────────────────────────────────────────

function ims_render_page(): void {
    $status       = get_option( IMS_OPT_STATUS, [] );
    $app_password = get_option( IMS_OPT_APP_PASS, '' );
    $markets      = get_option( IMS_OPT_MARKETS, [] );
    $claude_md    = get_option( IMS_OPT_CLAUDE_MD, '' );
    $site_url     = get_site_url();
    $idx_domain   = get_option( IMS_OPT_IDX_DOMAIN, '' );

    $activated_at = $status['activated_at'] ?? null;
    $user_ok      = ! empty( $status['user']['success'] );
    $snippets_ok  = ! empty( $status['snippets']['success'] );
    $markets_ok   = ! empty( $status['markets_count'] );
    $markets_err  = $status['markets_error'] ?? null;

    $config_endpoint = rest_url( 'ims/v1/config' );
    ?>
    <div class="wrap" id="ims-wrap">
        <h1>
            <span class="dashicons dashicons-migrate" style="font-size:28px;vertical-align:middle;margin-right:8px;margin-top:-3px;"></span>
            iHF Migration Setup
        </h1>
        <p class="description" style="max-width:700px;margin-bottom:24px;">
            Plugin activates autonomously — no clicking required. Below is the status from the last activation and the credentials Claude Code needs.
        </p>

        <?php if ( ! $activated_at ) : ?>
        <div class="notice notice-warning">
            <p><strong>Setup has not run yet.</strong> Deactivate and re-activate the plugin to trigger automatic setup.</p>
        </div>
        <?php endif; ?>

        <!-- ── Setup Status ────────────────────────────────────────────────── -->
        <div class="ims-card">
            <h2>Setup Status</h2>
            <?php if ( $activated_at ) : ?>
            <table class="ims-status-table">
                <tr><td>Activated</td><td><?php echo esc_html( $activated_at ); ?></td></tr>
                <tr>
                    <td>MCP User</td>
                    <td class="<?php echo $user_ok ? 'ok' : 'err'; ?>">
                        <?php echo $user_ok ? '✓ claude-mcp created' : ( '✗ ' . esc_html( $status['user']['error'] ?? 'failed' ) ); ?>
                    </td>
                </tr>
                <tr>
                    <td>WPCode Snippets</td>
                    <td class="<?php echo $snippets_ok ? 'ok' : 'err'; ?>">
                        <?php
                        if ( $snippets_ok ) {
                            $inst = count( $status['snippets']['installed'] ?? [] );
                            $skip = count( $status['snippets']['skipped'] ?? [] );
                            echo esc_html( "✓ {$inst} installed, {$skip} already existed" );
                        } else {
                            echo '✗ ' . esc_html( $status['snippets']['error'] ?? 'failed' );
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Markets</td>
                    <td class="<?php echo $markets_ok ? 'ok' : ( $markets_err ? 'err' : 'warn' ); ?>">
                        <?php
                        if ( $markets_ok ) {
                            echo esc_html( '✓ ' . $status['markets_count'] . ' markets fetched' );
                            if ( ! empty( $status['markets_refreshed_at'] ) ) {
                                echo ' (refreshed ' . esc_html( $status['markets_refreshed_at'] ) . ')';
                            }
                        } elseif ( $markets_err ) {
                            echo '⚠ ' . esc_html( $markets_err );
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>AiDX Scan</td>
                    <td class="<?php echo isset( $status['scan_pages'] ) ? ( $status['scan_error'] ? 'err' : 'ok' ) : 'warn'; ?>">
                        <?php
                        if ( ! empty( $status['scan_error'] ) ) {
                            echo '⚠ ' . esc_html( $status['scan_error'] );
                        } elseif ( isset( $status['scan_pages'] ) ) {
                            echo esc_html( '✓ ' . $status['scan_pages'] . ' pages, ' . $status['scan_posts'] . ' posts, ' . $status['scan_menus'] . ' menu items' );
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            <?php else : ?>
            <p style="color:#888;">Not yet activated.</p>
            <?php endif; ?>

            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                <button id="ims-btn-rerun" class="button button-secondary">Re-run Full Setup</button>
                <button id="ims-btn-markets" class="button button-secondary">Refresh + Re-scan</button>
                <button id="ims-btn-diag" class="button button-secondary">Diagnostics</button>
            </div>
            <div id="ims-ajax-result" class="ims-result" style="display:none;"></div>
        </div>

        <!-- ── Claude Code Instructions ────────────────────────────────────── -->
        <div class="ims-card">
            <h2>Claude Code — MCP Credentials</h2>
            <p>Give Claude Code these values to connect and run the migration:</p>
            <table class="ims-status-table" style="max-width:700px;">
                <tr><td>Site URL</td><td><code><?php echo esc_html( $site_url ); ?></code></td></tr>
                <tr><td>WP_USERNAME</td><td><code>claude-mcp</code></td></tr>
                <tr>
                    <td>WP_APP_PASSWORD</td>
                    <td>
                        <?php if ( $app_password ) : ?>
                        <code id="ims-app-pass"><?php echo esc_html( $app_password ); ?></code>
                        <button class="button" style="margin-left:8px;" onclick="navigator.clipboard.writeText('<?php echo esc_js( $app_password ); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000);">Copy</button>
                        <?php else : ?>
                        <span style="color:#c00;">Not generated — click Re-run Full Setup</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p style="margin-top:14px;color:#555;font-size:13px;">Tell Claude Code: <em>"Write this to <code>.claude/settings.json</code> under mcpServers and connect to WordPress."</em></p>
        </div>

        <!-- ── IDX Search Domain ───────────────────────────────────────────── -->
        <div class="ims-card">
            <h2>IDX Search Domain</h2>
            <p style="color:#555;font-size:13px;margin-top:0;">The IDX Broker subdomain for this site (e.g. <code>search.humboldthomeguide.com</code>). Auto-detected from IDX Broker plugin options — override here if the scanner or migration finds nothing.</p>
            <div style="display:flex;align-items:center;gap:8px;max-width:600px;">
                <input type="text" id="ims-idx-domain" class="regular-text" placeholder="search.yourdomain.com"
                    value="<?php echo esc_attr( $idx_domain ); ?>" style="flex:1;" />
                <button id="ims-btn-save-domain" class="button button-secondary">Save</button>
            </div>
            <?php if ( $idx_domain ) : ?>
            <p style="margin-top:8px;font-size:12px;color:#1a7a1a;">✓ Override active: <code><?php echo esc_html( $idx_domain ); ?></code></p>
            <?php else : ?>
            <p style="margin-top:8px;font-size:12px;color:#888;">No override set — using auto-detection.</p>
            <?php endif; ?>
            <div id="ims-domain-result" class="ims-result" style="display:none;"></div>
        </div>

        <!-- ── Migration Control Panel ────────────────────────────────────── -->
        <div class="ims-card">
            <h2>Migration Control Panel</h2>
            <p style="color:#555;font-size:13px;margin-top:0;">Run migration steps directly — no external tools needed. Use <strong>Dry Run</strong> to preview changes before applying them.</p>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;font-size:13px;cursor:pointer;">
                <input type="checkbox" id="ims-dry-run" /> Dry Run (preview only — no changes saved)
            </label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <button id="ims-btn-migrate-menus"  class="button button-primary">Migrate Menus</button>
                <button id="ims-btn-migrate-pages"  class="button button-primary">Migrate Pages</button>
                <button id="ims-btn-migrate-posts"  class="button button-primary">Migrate Posts</button>
                <button id="ims-btn-migrate-all"    class="button button-primary" style="background:#1d2327;border-color:#1d2327;">Full Migration</button>
                <button id="ims-btn-verify"         class="button button-secondary">Verify (Check for Remaining IDX)</button>
            </div>
            <div id="ims-migration-result" class="ims-result" style="display:none;max-height:500px;overflow-y:auto;"></div>
        </div>

        <!-- ── CLAUDE.md Preview ───────────────────────────────────────────── -->
        <?php if ( $claude_md ) : ?>
        <div class="ims-card">
            <h2>Generated CLAUDE.md Preview</h2>
            <p style="color:#555;font-size:13px;">This is the site-specific migration instruction file Claude Code retrieves via the REST endpoint. It is regenerated whenever markets are refreshed.</p>
            <textarea class="ims-output" rows="14" readonly><?php echo esc_textarea( mb_substr( $claude_md, 0, 2000 ) . ( mb_strlen( $claude_md ) > 2000 ? "\n\n[... truncated — full content served via REST endpoint ...]" : '' ) ); ?></textarea>
        </div>
        <?php endif; ?>

    </div>

    <style>
    #ims-wrap { max-width: 860px; }
    .ims-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px 24px; margin-bottom: 16px; }
    .ims-card h2 { margin-top: 0; font-size: 15px; }
    .ims-status-table { border-collapse: collapse; width: 100%; max-width: 600px; }
    .ims-status-table td { padding: 6px 10px; border: 1px solid #eee; font-size: 13px; }
    .ims-status-table td:first-child { font-weight: 600; width: 160px; background: #fafafa; }
    .ims-status-table .ok   { color: #1a7a1a; }
    .ims-status-table .err  { color: #c00; }
    .ims-status-table .warn { color: #996600; }
    .ims-result { margin-top: 12px; padding: 10px 14px; background: #f6f7f7; border-left: 4px solid #72aee6; border-radius: 2px; font-family: monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; }
    .ims-result.success { border-color: #00a32a; }
    .ims-result.error   { border-color: #d63638; background: #fcf0f1; }
    textarea.ims-output { width: 100%; font-family: monospace; font-size: 11px; background: #1e1e2e; color: #cdd6f4; padding: 10px; box-sizing: border-box; border-radius: 3px; resize: vertical; }
    </style>
    <?php
}
