<?php
defined( 'ABSPATH' ) || exit;

class MDG_Admin {

    const MENU_SLUG = 'meta-description-generator';

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_post_mdg_save_settings', [ __CLASS__, 'handle_save_settings' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_mdg_get_posts',    [ __CLASS__, 'ajax_get_posts' ] );
        add_action( 'wp_ajax_mdg_generate_one', [ __CLASS__, 'ajax_generate_one' ] );
        add_action( 'wp_ajax_mdg_apply_one',    [ __CLASS__, 'ajax_apply_one' ] );
        add_action( 'wp_ajax_mdg_apply_bulk',   [ __CLASS__, 'ajax_apply_bulk' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public static function register_menu(): void {
        add_menu_page(
            __( 'Meta Descriptions', 'meta-description-generator' ),
            __( 'Meta Descriptions', 'meta-description-generator' ),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'page_dashboard' ],
            'dashicons-editor-paste-text',
            81
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard', 'meta-description-generator' ),
            __( 'Dashboard', 'meta-description-generator' ),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'page_dashboard' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Generate & Apply', 'meta-description-generator' ),
            __( 'Generate & Apply', 'meta-description-generator' ),
            'manage_options',
            self::MENU_SLUG . '-generate',
            [ __CLASS__, 'page_generate' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Settings', 'meta-description-generator' ),
            __( 'Settings', 'meta-description-generator' ),
            'manage_options',
            self::MENU_SLUG . '-settings',
            [ __CLASS__, 'page_settings' ]
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) return;

        wp_enqueue_style( 'mdg-admin', MDG_PLUGIN_URL . 'admin/css/admin.css', [], MDG_VERSION );
        wp_enqueue_script( 'mdg-admin', MDG_PLUGIN_URL . 'admin/js/admin.js', [ 'jquery' ], MDG_VERSION, true );
        wp_localize_script( 'mdg-admin', 'MDG', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mdg_ajax' ),
            'min'      => MDG_META_MIN,
            'max'      => MDG_META_MAX,
            'strings'  => [
                'generating'    => __( 'Generating…', 'meta-description-generator' ),
                'applying'      => __( 'Applying…', 'meta-description-generator' ),
                'applied'       => __( 'Saved to Yoast ✓', 'meta-description-generator' ),
                'error'         => __( 'Error', 'meta-description-generator' ),
                'confirm_bulk'  => __( 'Apply all approved descriptions to Yoast SEO? This will overwrite any existing meta descriptions for the selected posts.', 'meta-description-generator' ),
                'no_api_key'    => __( 'Add your Claude API key under Settings first.', 'meta-description-generator' ),
                'chars_ok'      => __( 'characters — Good length', 'meta-description-generator' ),
                'chars_short'   => __( 'characters — Too short (aim for 140–160)', 'meta-description-generator' ),
                'chars_long'    => __( 'characters — Too long (aim for 140–160)', 'meta-description-generator' ),
            ],
            'has_api_key' => ! empty( MDG_Generator::get_api_key() ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function page_dashboard(): void {
        $summary    = MDG_Scanner::get_summary();
        $post_types = MDG_Scanner::get_scannable_types();
        $has_yoast  = defined( 'WPSEO_VERSION' );
        $has_key    = ! empty( MDG_Generator::get_api_key() );
        include MDG_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public static function page_generate(): void {
        $filters = [
            'status'    => sanitize_text_field( $_GET['status']    ?? 'missing' ),
            'post_type' => sanitize_text_field( $_GET['post_type'] ?? '' ),
            'search'    => sanitize_text_field( $_GET['s']         ?? '' ),
            'page'      => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
            'per_page'  => 50,
        ];

        $data       = MDG_Scanner::get_posts( $filters );
        $rows       = $data['rows'];
        $total      = $data['total'];
        $post_types = MDG_Scanner::get_scannable_types();
        $has_key    = ! empty( MDG_Generator::get_api_key() );

        include MDG_PLUGIN_DIR . 'admin/views/generate.php';
    }

    public static function page_settings(): void {
        $api_key    = MDG_Generator::get_api_key();
        $saved      = isset( $_GET['saved'] );
        include MDG_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // -------------------------------------------------------------------------
    // Settings form handler
    // -------------------------------------------------------------------------

    public static function handle_save_settings(): void {
        check_admin_referer( 'mdg_save_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $key = sanitize_text_field( wp_unslash( $_POST['mdg_api_key'] ?? '' ) );
        MDG_Generator::save_api_key( $key );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX: paginated post list (for dynamic filtering on Generate page)
    // -------------------------------------------------------------------------

    public static function ajax_get_posts(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $filters = [
            'status'    => sanitize_text_field( $_POST['status']    ?? 'missing' ),
            'post_type' => sanitize_text_field( $_POST['post_type'] ?? '' ),
            'search'    => sanitize_text_field( $_POST['search']    ?? '' ),
            'per_page'  => 50,
            'page'      => max( 1, (int) ( $_POST['page'] ?? 1 ) ),
        ];

        wp_send_json_success( MDG_Scanner::get_posts( $filters ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: generate one meta description
    // -------------------------------------------------------------------------

    public static function ajax_generate_one(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Invalid post ID.' );

        $result = MDG_Generator::generate_for_post( $post_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: apply one approved description
    // -------------------------------------------------------------------------

    public static function ajax_apply_one(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $post_id     = (int) ( $_POST['post_id']     ?? 0 );
        $description = wp_unslash( $_POST['description'] ?? '' );

        if ( ! $post_id ) wp_send_json_error( 'Invalid post ID.' );

        $result = MDG_Generator::apply_to_yoast( $post_id, $description );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: apply a batch of approved descriptions at once
    // -------------------------------------------------------------------------

    public static function ajax_apply_bulk(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $items = $_POST['items'] ?? [];
        if ( ! is_array( $items ) || empty( $items ) ) wp_send_json_error( 'No items provided.' );

        $results = [];
        foreach ( $items as $item ) {
            $post_id     = (int) ( $item['post_id']     ?? 0 );
            $description = wp_unslash( $item['description'] ?? '' );
            if ( ! $post_id || empty( $description ) ) continue;
            $results[] = MDG_Generator::apply_to_yoast( $post_id, $description );
        }

        $saved  = count( array_filter( $results, fn( $r ) => $r['success'] ) );
        $failed = count( $results ) - $saved;
        wp_send_json_success( compact( 'saved', 'failed', 'results' ) );
    }
}
