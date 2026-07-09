<?php
defined( 'ABSPATH' ) || exit;

class MDG_Admin {

    const MENU_SLUG = 'meta-description-generator';

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_notices',         [ __CLASS__, 'maybe_show_auto_run_notice' ] );
        add_action( 'admin_post_mdg_save_settings', [ __CLASS__, 'handle_save_settings' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_mdg_get_posts',        [ __CLASS__, 'ajax_get_posts' ] );
        add_action( 'wp_ajax_mdg_generate_one',     [ __CLASS__, 'ajax_generate_one' ] );
        add_action( 'wp_ajax_mdg_apply_one',        [ __CLASS__, 'ajax_apply_one' ] );
        add_action( 'wp_ajax_mdg_apply_bulk',       [ __CLASS__, 'ajax_apply_bulk' ] );
        add_action( 'wp_ajax_mdg_clear_one',        [ __CLASS__, 'ajax_clear_one' ] );
        add_action( 'wp_ajax_mdg_clear_bulk',       [ __CLASS__, 'ajax_clear_bulk' ] );
        add_action( 'wp_ajax_mdg_clear_all_matching', [ __CLASS__, 'ajax_clear_all_matching' ] );
        add_action( 'wp_ajax_mdg_auto_fill_next',   [ __CLASS__, 'ajax_auto_fill_next' ] );
        add_action( 'wp_ajax_mdg_auto_fill_status', [ __CLASS__, 'ajax_auto_fill_status' ] );
    }

    // -------------------------------------------------------------------------
    // Activation
    // -------------------------------------------------------------------------

    /**
     * On plugin activation: queue all posts missing any SEO field for auto-fill.
     * Requires the API key to already be configured (or user triggers manually).
     */
    public static function on_activation(): void {
        $ids = MDG_Scanner::get_incomplete_post_ids();
        if ( ! empty( $ids ) ) {
            update_option( 'mdg_auto_queue',   $ids );
            update_option( 'mdg_auto_total',   count( $ids ) );
            update_option( 'mdg_auto_done',    0 );
            update_option( 'mdg_auto_errors',  0 );
            update_option( 'mdg_auto_running', 1 );
        }
    }

    public static function maybe_show_auto_run_notice(): void {
        if ( ! get_option( 'mdg_auto_running' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $total     = (int) get_option( 'mdg_auto_total', 0 );
        $done      = (int) get_option( 'mdg_auto_done',  0 );
        $remaining = count( (array) get_option( 'mdg_auto_queue', [] ) );
        $has_key   = ! empty( MDG_Generator::get_api_key() );

        if ( ! $has_key ) {
            echo '<div class="notice notice-warning"><p>';
            printf(
                wp_kses( __( '<strong>Meta Description Generator:</strong> Add your Claude API key on the <a href="%s">Settings page</a> to start auto-filling SEO fields for %d posts/pages.', 'meta-description-generator' ), [ 'strong' => [], 'a' => [ 'href' => [] ] ] ),
                esc_url( admin_url( 'admin.php?page=meta-description-generator-settings' ) ),
                $total
            );
            echo '</p></div>';
            return;
        }

        if ( $remaining > 0 ) {
            echo '<div class="notice notice-info mdg-auto-notice" id="mdg-auto-notice">';
            echo '<p>';
            printf(
                wp_kses( __( '<strong>Meta Description Generator:</strong> Auto-filling SEO fields — <span id="mdg-auto-progress-text">%d of %d</span> complete. <a href="%s">View dashboard</a>', 'meta-description-generator' ), [ 'strong' => [], 'span' => [ 'id' => [] ], 'a' => [ 'href' => [] ] ] ),
                $done,
                $total,
                esc_url( admin_url( 'admin.php?page=meta-description-generator' ) )
            );
            echo '</p>';
            echo '<div style="max-width:400px;background:#e0e0e0;border-radius:4px;height:6px;margin:6px 0 10px">';
            echo '<div id="mdg-auto-bar" style="width:' . esc_attr( $total ? round( $done / $total * 100 ) . '%' : '0%' ) . ';height:100%;background:#2271b1;border-radius:4px;transition:width .3s"></div>';
            echo '</div>';
            echo '</div>';
        }
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
        $queue   = (array) get_option( 'mdg_auto_queue', [] );
        $running = (bool) get_option( 'mdg_auto_running', false );

        wp_localize_script( 'mdg-admin', 'MDG', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'mdg_ajax' ),
            'min'        => MDG_META_MIN,
            'max'        => MDG_META_MAX,
            'auto_run'   => $running && ! empty( $queue ) && ! empty( MDG_Generator::get_api_key() ),
            'auto_total' => (int) get_option( 'mdg_auto_total', 0 ),
            'auto_done'  => (int) get_option( 'mdg_auto_done',  0 ),
            'strings'    => [
                'generating'    => __( 'Generating…', 'meta-description-generator' ),
                'applying'      => __( 'Applying…', 'meta-description-generator' ),
                'applied'       => __( 'Saved to Yoast ✓', 'meta-description-generator' ),
                'error'         => __( 'Error', 'meta-description-generator' ),
                'confirm_bulk'  => __( 'Apply all approved descriptions to Yoast SEO? This will overwrite any existing meta descriptions for the selected posts.', 'meta-description-generator' ),
                'no_api_key'    => __( 'Add your Claude API key under Settings first.', 'meta-description-generator' ),
                'chars_ok'      => __( 'characters — Good length', 'meta-description-generator' ),
                'chars_short'   => __( 'characters — Too short (aim for 140–160)', 'meta-description-generator' ),
                'chars_long'    => __( 'characters — Too long (aim for 140–160)', 'meta-description-generator' ),
                'auto_filling'  => __( 'Auto-filling SEO fields…', 'meta-description-generator' ),
                'auto_done'     => __( 'All SEO fields filled.', 'meta-description-generator' ),
                'clearing'      => __( 'Clearing…', 'meta-description-generator' ),
                'cleared'       => __( 'Cleared ✓', 'meta-description-generator' ),
                'confirm_clear_one'  => __( 'Clear the current Yoast meta description for this post? This cannot be undone.', 'meta-description-generator' ),
                'confirm_clear_bulk' => __( 'Clear the Yoast meta description for %d selected post(s)? This cannot be undone.', 'meta-description-generator' ),
                'confirm_clear_all'  => __( 'Clear the Yoast meta description for ALL %d post(s) matching the current filter, including any written outside this plugin? This cannot be undone.', 'meta-description-generator' ),
                'no_selection'  => __( 'Check at least one row first.', 'meta-description-generator' ),
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

    // -------------------------------------------------------------------------
    // AJAX: clear one post's Yoast meta description
    // -------------------------------------------------------------------------

    public static function ajax_clear_one(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Invalid post ID.' );

        $result = MDG_Generator::clear_description( $post_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: clear the Yoast meta description for a batch of posts
    // -------------------------------------------------------------------------

    public static function ajax_clear_bulk(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $ids = $_POST['post_ids'] ?? [];
        if ( ! is_array( $ids ) || empty( $ids ) ) wp_send_json_error( 'No posts selected.' );

        $cleared = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( ! $id ) continue;
            if ( MDG_Generator::clear_description( $id )['success'] ) $cleared++;
        }

        wp_send_json_success( [ 'cleared' => $cleared ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: clear the Yoast meta description for every post matching the
    // current filter, ignoring pagination — used to wipe everything a
    // previous prompt version already wrote so it can be rewritten fresh.
    // -------------------------------------------------------------------------

    public static function ajax_clear_all_matching(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $filters = [
            'status'    => sanitize_text_field( $_POST['status']    ?? 'all' ),
            'post_type' => sanitize_text_field( $_POST['post_type'] ?? '' ),
            'search'    => sanitize_text_field( $_POST['search']    ?? '' ),
        ];

        $ids = MDG_Scanner::get_matching_ids( $filters );
        $cleared = 0;
        foreach ( $ids as $id ) {
            if ( MDG_Generator::clear_description( $id )['success'] ) $cleared++;
        }

        wp_send_json_success( [ 'cleared' => $cleared ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: auto-fill next post in the queue (called sequentially from JS)
    // -------------------------------------------------------------------------

    public static function ajax_auto_fill_next(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $queue = (array) get_option( 'mdg_auto_queue', [] );

        if ( empty( $queue ) ) {
            delete_option( 'mdg_auto_running' );
            wp_send_json_success( [ 'done' => true, 'remaining' => 0 ] );
        }

        $post_id = (int) array_shift( $queue );
        update_option( 'mdg_auto_queue', $queue );

        $result = MDG_Generator::auto_fill_post( $post_id );

        $done = (int) get_option( 'mdg_auto_done', 0 ) + 1;
        update_option( 'mdg_auto_done', $done );

        if ( ! $result['success'] ) {
            update_option( 'mdg_auto_errors', (int) get_option( 'mdg_auto_errors', 0 ) + 1 );
        }

        $remaining = count( $queue );
        if ( $remaining === 0 ) {
            delete_option( 'mdg_auto_running' );
        }

        wp_send_json_success( [
            'done'      => $remaining === 0,
            'remaining' => $remaining,
            'total'     => (int) get_option( 'mdg_auto_total', 0 ),
            'processed' => $done,
            'result'    => $result,
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: return current auto-fill queue status
    // -------------------------------------------------------------------------

    public static function ajax_auto_fill_status(): void {
        check_ajax_referer( 'mdg_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        wp_send_json_success( [
            'running'   => (bool) get_option( 'mdg_auto_running', false ),
            'remaining' => count( (array) get_option( 'mdg_auto_queue', [] ) ),
            'total'     => (int) get_option( 'mdg_auto_total', 0 ),
            'done'      => (int) get_option( 'mdg_auto_done',  0 ),
            'errors'    => (int) get_option( 'mdg_auto_errors', 0 ),
        ] );
    }
}
