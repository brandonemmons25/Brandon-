<?php
defined( 'ABSPATH' ) || exit;

class BLS_Admin {

    const MENU_SLUG       = 'button-link-scanner';
    const NONCE_SCAN      = 'bls_scan';
    const NONCE_MAP_SAVE  = 'bls_map_save';
    const NONCE_MAP_APPLY = 'bls_map_apply';
    const NONCE_MAP_DEL   = 'bls_map_delete';

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_bls_run_scan',       [ __CLASS__, 'ajax_run_scan' ] );
        add_action( 'wp_ajax_bls_save_map_entry', [ __CLASS__, 'ajax_save_map_entry' ] );
        add_action( 'wp_ajax_bls_apply_map',      [ __CLASS__, 'ajax_apply_map' ] );
        add_action( 'wp_ajax_bls_delete_map',     [ __CLASS__, 'ajax_delete_map' ] );
        add_action( 'wp_ajax_bls_preview_apply',  [ __CLASS__, 'ajax_preview_apply' ] );
        add_action( 'wp_ajax_bls_toggle_schedule',[ __CLASS__, 'ajax_toggle_schedule' ] );
    }

    // -------------------------------------------------------------------------
    // Menu & pages
    // -------------------------------------------------------------------------

    public static function register_menu() {
        add_menu_page(
            __( 'Button Link Scanner', 'button-link-scanner' ),
            __( 'Button Scanner', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'page_dashboard' ],
            'dashicons-button',
            80
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard', 'button-link-scanner' ),
            __( 'Dashboard', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'page_dashboard' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Scan Results', 'button-link-scanner' ),
            __( 'Scan Results', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG . '-results',
            [ __CLASS__, 'page_results' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Link Trends', 'button-link-scanner' ),
            __( 'Link Trends', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG . '-trends',
            [ __CLASS__, 'page_trends' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Button Map', 'button-link-scanner' ),
            __( 'Button Map', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG . '-map',
            [ __CLASS__, 'page_button_map' ]
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        wp_enqueue_style( 'bls-admin', BLS_PLUGIN_URL . 'admin/css/admin.css', [], BLS_VERSION );
        wp_enqueue_script( 'bls-admin', BLS_PLUGIN_URL . 'admin/js/admin.js', [ 'jquery' ], BLS_VERSION, true );
        wp_localize_script( 'bls-admin', 'BLS', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bls_ajax' ),
            'strings'  => [
                'scanning'       => __( 'Scanning...', 'button-link-scanner' ),
                'scan_complete'  => __( 'Scan complete!', 'button-link-scanner' ),
                'applying'       => __( 'Applying...', 'button-link-scanner' ),
                'apply_complete' => __( 'Applied!', 'button-link-scanner' ),
                'confirm_apply'  => __( 'This will rewrite post content in the database. A preview is shown below. Continue?', 'button-link-scanner' ),
                'confirm_delete' => __( 'Delete this button map entry?', 'button-link-scanner' ),
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Page: Dashboard
    // -------------------------------------------------------------------------

    public static function page_dashboard() {
        $summary       = BLS_Database::get_summary();
        $last_scan     = BLS_Database::get_last_scan_date();
        $scheduled     = wp_next_scheduled( 'bls_scheduled_scan' );
        $schedule_freq = get_option( 'bls_schedule_freq', '' );

        include BLS_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    // -------------------------------------------------------------------------
    // Page: Results
    // -------------------------------------------------------------------------

    public static function page_results() {
        $filters = [
            'has_link'  => isset( $_GET['has_link'] )  ? sanitize_text_field( $_GET['has_link'] )  : '',
            'has_title' => isset( $_GET['has_title'] ) ? sanitize_text_field( $_GET['has_title'] ) : '',
            'post_type' => isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : '',
            'search'    => isset( $_GET['s'] )         ? sanitize_text_field( $_GET['s'] )         : '',
            'page'      => isset( $_GET['paged'] )     ? max( 1, (int) $_GET['paged'] )            : 1,
            'per_page'  => 50,
        ];

        $data       = BLS_Database::get_results( $filters );
        $rows       = $data['rows'];
        $total      = $data['total'];
        $post_types = BLS_Database::get_scanned_post_types();

        include BLS_PLUGIN_DIR . 'admin/views/results.php';
    }

    // -------------------------------------------------------------------------
    // Page: Trends
    // -------------------------------------------------------------------------

    public static function page_trends() {
        $trends = BLS_Database::get_trends( 200 );
        include BLS_PLUGIN_DIR . 'admin/views/trends.php';
    }

    // -------------------------------------------------------------------------
    // Page: Button Map
    // -------------------------------------------------------------------------

    public static function page_button_map() {
        $map_entries = BLS_Database::get_button_map( 500 );
        $trends      = BLS_Database::get_trends( 500 ); // used to pre-populate
        include BLS_PLUGIN_DIR . 'admin/views/button-map.php';
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public static function ajax_run_scan() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $scanner = new BLS_Scanner();
        $result  = $scanner->run_full_scan();
        wp_send_json_success( $result );
    }

    public static function ajax_save_map_entry() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $button_text    = sanitize_text_field( wp_unslash( $_POST['button_text']    ?? '' ) );
        $assigned_url   = esc_url_raw( wp_unslash( $_POST['assigned_url']   ?? '' ) );
        $assigned_title = sanitize_text_field( wp_unslash( $_POST['assigned_title'] ?? '' ) );
        $opens_new_tab  = ! empty( $_POST['opens_new_tab'] ) ? 1 : 0;

        if ( empty( $button_text ) || empty( $assigned_url ) ) {
            wp_send_json_error( 'Button text and URL are required.' );
        }

        $id = BLS_Database::upsert_button_map( compact( 'button_text', 'assigned_url', 'assigned_title', 'opens_new_tab' ) );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public static function ajax_delete_map() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $id = (int) ( $_POST['id'] ?? 0 );
        BLS_Database::delete_button_map_entry( $id );
        wp_send_json_success();
    }

    public static function ajax_apply_map() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $updater = new BLS_Updater();
        $id      = isset( $_POST['map_id'] ) ? (int) $_POST['map_id'] : 0;

        if ( $id ) {
            $result = $updater->apply_map_entry( $id );
        } else {
            $result = $updater->apply_all_map_entries();
        }

        wp_send_json_success( $result );
    }

    public static function ajax_preview_apply() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $map_id  = (int) ( $_POST['map_id'] ?? 0 );
        $updater = new BLS_Updater();
        $preview = $updater->preview_map_entry( $map_id );
        wp_send_json_success( $preview );
    }

    public static function ajax_toggle_schedule() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $freq = sanitize_text_field( $_POST['freq'] ?? '' );

        wp_clear_scheduled_hook( 'bls_scheduled_scan' );
        update_option( 'bls_schedule_freq', '' );

        $valid = [ 'daily', 'twicedaily', 'weekly' ];
        if ( in_array( $freq, $valid, true ) ) {
            wp_schedule_event( time(), $freq, 'bls_scheduled_scan' );
            update_option( 'bls_schedule_freq', $freq );
        }

        wp_send_json_success( [ 'freq' => $freq ] );
    }
}
