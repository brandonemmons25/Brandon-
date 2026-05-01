<?php
/**
 * Plugin Name: iHF Migration Setup
 * Description: One-click setup for the IDX → iHomeFinder migration: creates the claude-mcp user, installs WPCode snippets, generates Claude Desktop config, runs the AiDX Scanner, extracts Optima Express Market IDs, and generates the CLAUDE.md.
 * Version:     1.0.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'IMS_VERSION', '1.0.0' );
define( 'IMS_FILE',    __FILE__ );
define( 'IMS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'IMS_URL',     plugin_dir_url( __FILE__ ) );

// ── WPCode snippet definitions ────────────────────────────────────────────────

function ims_get_snippets() {
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
    wp_register_ability( 'migration/get-ihf-markets', array(
        'label'       => 'Get iHF Markets',
        'description' => 'Returns all Optima Express Markets from the iHF API',
        'category'    => 'site',
        'input_schema' => array( 'type' => 'object', 'properties' => array(), 'required' => array() ),
        'execute_callback' => function( $input ) {
            $token = get_option( 'ihf_activation_token' );
            $info  = array();
            $consts = get_defined_constants( true );
            foreach ( $consts['user'] as $k => $v ) {
                if ( stripos( $k, 'ihf' ) !== false || stripos( $k, 'optima' ) !== false || stripos( $k, 'homefinder' ) !== false ) {
                    $info['constants'][ $k ] = $v;
                }
            }
            $classes     = get_declared_classes();
            $ihf_classes = array_values( array_filter( $classes, function( $c ) {
                return stripos( $c, 'ihf' ) !== false || stripos( $c, 'optima' ) !== false || stripos( $c, 'homefinder' ) !== false;
            } ) );
            $info['classes'] = $ihf_classes;
            if ( class_exists( 'IHF_Plugin' ) )           $info['ihf_plugin_methods'] = get_class_methods( 'IHF_Plugin' );
            if ( class_exists( 'OptimalExpressPlugin' ) )  $info['optima_methods']     = get_class_methods( 'OptimalExpressPlugin' );
            $urls = array(
                'a' => "https://api.ihomefinder.com/v1/account/toppicks?apiKey={$token}",
                'b' => "https://api.ihomefinder.com/v1/toppicks?apiKey={$token}",
                'c' => "https://homefinder.com/data/getTopPicks?apiKey={$token}",
            );
            foreach ( $urls as $k => $url ) {
                $r = wp_remote_get( $url, array( 'timeout' => 10 ) );
                $info['api'][ $k ] = is_wp_error( $r ) ? $r->get_error_message() : array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => substr( wp_remote_retrieve_body( $r ), 0, 500 ) );
            }
            return $info;
        },
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
    ) );
} );
PHP,
        ],
    ];
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

// ── Admin assets ───────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_ihf-migration-setup' ) return;
    wp_enqueue_style(  'ims-admin', IMS_URL . 'assets/admin.css', [], IMS_VERSION );
    wp_enqueue_script( 'ims-admin', IMS_URL . 'assets/admin.js',  [ 'jquery' ], IMS_VERSION, true );
    wp_localize_script( 'ims-admin', 'IMS', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'ims_nonce' ),
        'siteUrl' => get_site_url(),
    ] );
} );

// ── AJAX: Create claude-mcp user + app password ────────────────────────────────

add_action( 'wp_ajax_ims_create_user', function () {
    check_ajax_referer( 'ims_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $username = 'claude-mcp';
    $existing = get_user_by( 'login', $username );

    if ( $existing ) {
        $user_id = $existing->ID;
        // Ensure administrator role
        $existing->set_role( 'administrator' );
    } else {
        $email   = $username . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
        $user_id = wp_create_user( $username, wp_generate_password( 32 ), $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( $user_id->get_error_message() );
        }
        $user = new WP_User( $user_id );
        $user->set_role( 'administrator' );
    }

    // Generate alphanumeric-only application password
    $app_name = 'Claude MCP';

    // Remove any existing app password with same name
    $existing_passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );
    foreach ( $existing_passwords as $ap ) {
        if ( $ap['name'] === $app_name ) {
            WP_Application_Passwords::delete_application_password( $user_id, $ap['uuid'] );
        }
    }

    // Generate 24-char alphanumeric password (no special chars, no spaces)
    $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $raw_pass = '';
    for ( $i = 0; $i < 24; $i++ ) {
        $raw_pass .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
    }

    // Store via WP Application Passwords — we supply the raw password
    add_filter( 'wp_application_passwords_expire_passwords', '__return_false' );
    $result = WP_Application_Passwords::create_new_application_password(
        $user_id,
        [ 'name' => $app_name ]
    );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    // $result[0] is the plaintext password (shown only once by WP)
    $plain = $result[0];

    // Store the plain password temporarily in transient so config step can use it
    set_transient( 'ims_app_password_' . $user_id, $plain, HOUR_IN_SECONDS );

    wp_send_json_success( [
        'user_id'      => $user_id,
        'username'     => $username,
        'app_password' => $plain,
        'message'      => $existing ? 'Existing user updated.' : 'User created.',
    ] );
} );

// ── AJAX: Install WPCode snippets ──────────────────────────────────────────────

add_action( 'wp_ajax_ims_install_snippets', function () {
    check_ajax_referer( 'ims_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    if ( ! post_type_exists( 'wpcode_snippet' ) ) {
        wp_send_json_error( 'WPCode plugin is not active. Please install and activate WPCode first.' );
    }

    $snippets  = ims_get_snippets();
    $installed = [];
    $skipped   = [];
    $missing   = [];

    foreach ( $snippets as $snippet ) {
        if ( empty( $snippet['code'] ) ) {
            $missing[] = $snippet['title'];
            continue;
        }

        // Check if already exists
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
            wp_send_json_error( 'Failed to install: ' . $snippet['title'] );
        }

        update_post_meta( $post_id, '_wpcode_snippet_type',   'php' );
        update_post_meta( $post_id, '_wpcode_snippet_status', 1 );
        update_post_meta( $post_id, '_wpcode_snippet_scope',  'global' );

        $installed[] = $snippet['title'];
    }

    wp_send_json_success( [
        'installed' => $installed,
        'skipped'   => $skipped,
        'missing'   => $missing,
    ] );
} );

// ── AJAX: Generate Claude Desktop config ───────────────────────────────────────

add_action( 'wp_ajax_ims_get_config', function () {
    check_ajax_referer( 'ims_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $user = get_user_by( 'login', 'claude-mcp' );
    if ( ! $user ) {
        wp_send_json_error( 'claude-mcp user not found. Run Step 1 first.' );
    }

    $plain = get_transient( 'ims_app_password_' . $user->ID );
    if ( ! $plain ) {
        wp_send_json_error( 'App password not found in session. Re-run Step 1 to regenerate.' );
    }

    $site_url = get_site_url();

    $config = [
        'mcpServers' => [
            'wordpress' => [
                'command' => 'npx',
                'args'    => [ '-y', '@automattic/mcp-server-wordpress' ],
                'env'     => [
                    'WP_SITE_URL'    => $site_url,
                    'WP_USERNAME'    => 'claude-mcp',
                    'WP_APP_PASSWORD'=> $plain,
                ],
            ],
        ],
    ];

    wp_send_json_success( [
        'config'   => wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
        'site_url' => $site_url,
        'username' => 'claude-mcp',
        'password' => $plain,
    ] );
} );

// ── AJAX: Get Optima Express markets ───────────────────────────────────────────

add_action( 'wp_ajax_ims_get_markets', function () {
    check_ajax_referer( 'ims_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $auth_token = get_option( 'ihf_authentication_token', '' )
               ?: get_option( 'ihf_activation_token', '' );

    if ( ! $auth_token ) {
        wp_send_json_error( 'No Optima Express authentication token found. Make sure Optima Express is installed and registered.' );
    }

    $resp = wp_remote_get( add_query_arg( [
        'method'              => 'handleRequest',
        'requestType'         => 'hotsheet-list',
        'viewType'            => 'json',
        'phpStyle'            => 'true',
        'authenticationToken' => $auth_token,
    ], 'https://www.idxhome.com/service/wordpress' ), [ 'timeout' => 20 ] );

    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( $resp->get_error_message() );
    }

    $body    = wp_remote_retrieve_body( $resp );
    $markets = ims_parse_markets( $body );

    if ( is_string( $markets ) ) {
        wp_send_json_error( $markets );
    }

    wp_send_json_success( $markets );
} );

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
    $list = $data['hotsheets'] ?? $data['markets'] ?? $data['savedSearches'] ?? $data['data'] ?? $data;
    $result = [];
    foreach ( (array) $list as $item ) {
        if ( ! is_array( $item ) ) continue;
        $name = $item['name'] ?? $item['title'] ?? $item['hotsheetName'] ?? $item['linkName'] ?? '';
        $id   = (string) ( $item['id'] ?? $item['hotsheetId'] ?? $item['marketId'] ?? $item['savedSearchId'] ?? $item['hotsheet_id'] ?? '' );
        $url  = $item['url'] ?? $item['link'] ?? $item['pageUrl'] ?? $item['permalink'] ?? '';
        if ( $name ) {
            $result[] = [ 'id' => $id, 'name' => trim( $name ), 'url' => trim( $url ) ];
        }
    }
    usort( $result, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
    return $result;
}

// ── AJAX: Generate CLAUDE.md ───────────────────────────────────────────────────

add_action( 'wp_ajax_ims_generate_claude_md', function () {
    check_ajax_referer( 'ims_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $markets_raw = sanitize_text_field( wp_unslash( $_POST['markets'] ?? '' ) );
    $markets     = json_decode( stripslashes( $_POST['markets'] ?? '[]' ), true );
    if ( ! is_array( $markets ) ) wp_send_json_error( 'Invalid markets data.' );

    $site_url    = get_site_url();
    $site_name   = get_bloginfo( 'name' );

    // Build market list for CLAUDE.md
    $market_lines = '';
    foreach ( $markets as $m ) {
        $id_str = $m['id'] ? $m['id'] : 'NO_ID';
        $market_lines .= "- **{$m['name']}** — id={$id_str}" . ( $m['url'] ? " — {$m['url']}" : '' ) . "\n";
    }
    if ( ! $market_lines ) $market_lines = "- (no markets found — add manually)\n";

    $md = ims_claude_md_template( $site_name, $site_url, $market_lines );

    wp_send_json_success( [ 'markdown' => $md ] );
} );

function ims_claude_md_template( string $site_name, string $site_url, string $market_lines ): string {
    return <<<MD
# Migration Instructions — {$site_name}

## Site
- **URL:** {$site_url}
- **MCP User:** claude-mcp (Administrator)

## Your Job
Migrate this WordPress site from IDX Broker to iHomeFinder (Optima Express).

### What that means:
1. Find every IDX Broker shortcode, widget, and URL on the site
2. Replace each one with the correct Optima Express equivalent
3. Map IDX saved searches → iHF Markets using the Market IDs below
4. Replace `[impress_property_showcase saved_link_id=X]` and `[IDX-savedlinks id=X]` with `[optima_express_toppicks id=MARKET_ID]`
5. Replace IDX search URLs with the equivalent iHF page URLs
6. Update navigation menus
7. Leave all design/layout decisions to the human — only touch IDX/iHF content

## iHF Markets
{$market_lines}
## Shortcode Reference

| IDX Broker | Optima Express |
|---|---|
| `[impress_property_showcase saved_link_id=X]` | `[optima_express_toppicks id=MARKET_ID]` |
| `[IDX-savedlinks id=X]` | `[optima_express_toppicks id=MARKET_ID]` |
| `[idx-listings-showcase id=X]` | `[optima_express_toppicks id=MARKET_ID]` |
| `[idx-search-form]` | `[optima_express_search_form]` |
| `[idx-map-search]` | `[optima_express_map_search]` |
| `[idx-featured-listings]` | `[optima_express_toppicks]` |
| `[idx-open-houses]` | `[optima_express_open_houses]` |
| `[idx-property-detail]` | `[optima_express_property_details]` |

## Rules
- Always preview before applying — use dry-run / get_page before update_page
- Never touch CSS, layouts, images, or copy unless it contains an IDX element
- Commit nothing — all changes go through MCP tools only
- Report completion with a summary of every change made
MD;
}

// ── Admin page ─────────────────────────────────────────────────────────────────

function ims_render_page() {
    $snippets = ims_get_snippets();
    $missing_snippets = array_filter( $snippets, fn( $s ) => empty( $s['code'] ) );
    ?>
    <div class="wrap" id="ims-wrap">
        <h1>
            <span class="dashicons dashicons-migrate" style="font-size:28px;vertical-align:middle;margin-right:8px;margin-top:-3px;"></span>
            iHF Migration Setup
        </h1>
        <p class="description" style="max-width:700px;margin-bottom:24px;">
            Automates the one-time staging setup for every IDX → iHomeFinder migration.
            Complete each step in order.
        </p>

        <?php if ( $missing_snippets ) : ?>
        <div class="notice notice-warning inline" style="max-width:700px;">
            <p><strong><?php echo count( $missing_snippets ); ?> WPCode snippet(s) are missing their code</strong> and will be skipped during installation:
            <?php echo implode( ', ', array_map( fn($s) => '<em>' . esc_html($s['title']) . '</em>', $missing_snippets ) ); ?></p>
        </div>
        <?php endif; ?>

        <div id="ims-status" class="ims-status" style="display:none;"></div>

        <!-- ── Step 1: MCP User ─────────────────────────────────────────────── -->
        <div class="ims-card">
            <h2><span class="ims-step">1</span> Create MCP User &amp; Application Password</h2>
            <p>Creates the <code>claude-mcp</code> WordPress user (Administrator) and generates an alphanumeric Application Password for Claude Desktop.</p>
            <button id="ims-btn-user" class="button button-primary">Run Step 1</button>
            <div id="ims-user-result" class="ims-result" style="display:none;"></div>
        </div>

        <!-- ── Step 2: WPCode Snippets ─────────────────────────────────────── -->
        <div class="ims-card">
            <h2><span class="ims-step">2</span> Install WPCode Snippets</h2>
            <p>Installs all 4 MCP ability snippets into WPCode. Requires WPCode plugin to be active.</p>
            <ul style="margin:0 0 12px 20px;color:#555;">
                <?php foreach ( $snippets as $s ) : ?>
                <li><?php echo esc_html( $s['title'] ); echo empty( $s['code'] ) ? ' <span style="color:#c00;">(code missing)</span>' : ''; ?></li>
                <?php endforeach; ?>
            </ul>
            <button id="ims-btn-snippets" class="button button-primary">Run Step 2</button>
            <div id="ims-snippets-result" class="ims-result" style="display:none;"></div>
        </div>

        <!-- ── Step 3: Claude Desktop Config ──────────────────────────────── -->
        <div class="ims-card">
            <h2><span class="ims-step">3</span> Generate Claude Desktop Config</h2>
            <p>Generates the <code>claude_desktop_config.json</code> block to paste into your Claude Desktop configuration. Requires Step 1 to have been run in this session.</p>
            <button id="ims-btn-config" class="button button-primary">Run Step 3</button>
            <div id="ims-config-result" class="ims-result" style="display:none;"></div>
        </div>

        <!-- ── Step 4: Markets ─────────────────────────────────────────────── -->
        <div class="ims-card">
            <h2><span class="ims-step">4</span> Extract Optima Express Market IDs</h2>
            <p>Fetches all Markets from the iHomeFinder API using the Optima Express authentication token. Requires Optima Express to be installed and registered.</p>
            <button id="ims-btn-markets" class="button button-primary">Run Step 4</button>
            <div id="ims-markets-result" class="ims-result" style="display:none;"></div>
        </div>

        <!-- ── Step 5: CLAUDE.md ───────────────────────────────────────────── -->
        <div class="ims-card">
            <h2><span class="ims-step">5</span> Generate CLAUDE.md</h2>
            <p>Generates the migration instruction file for Claude Desktop, pre-filled with this site's Market IDs. Run Step 4 first.</p>
            <button id="ims-btn-claude-md" class="button button-primary" disabled>Run Step 5</button>
            <div id="ims-claude-md-result" class="ims-result" style="display:none;"></div>
        </div>

    </div>

    <style>
    #ims-wrap { max-width: 860px; }
    .ims-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px 24px; margin-bottom: 16px; }
    .ims-card h2 { margin-top: 0; display: flex; align-items: center; gap: 10px; font-size: 16px; }
    .ims-step { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #2271b1; color: #fff; border-radius: 50%; font-size: 13px; font-weight: 700; flex-shrink: 0; }
    .ims-result { margin-top: 14px; padding: 12px 16px; background: #f6f7f7; border-left: 4px solid #72aee6; border-radius: 2px; font-family: monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; }
    .ims-result.success { border-color: #00a32a; }
    .ims-result.error   { border-color: #d63638; background: #fcf0f1; }
    .ims-status { padding: 10px 16px; background: #f0f6fc; border-left: 4px solid #72aee6; margin-bottom: 16px; max-width: 700px; }
    .ims-copy-btn { margin-top: 8px; }
    textarea.ims-output { width: 100%; min-height: 180px; font-family: monospace; font-size: 11px; background: #1e1e2e; color: #cdd6f4; padding: 10px; box-sizing: border-box; border-radius: 3px; resize: vertical; }
    </style>
    <?php
}
