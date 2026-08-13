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
        add_action( 'admin_notices',         [ __CLASS__, 'broken_link_notice' ] );
        add_action( 'wp_ajax_bls_scan_start',      [ __CLASS__, 'ajax_scan_start' ] );
        add_action( 'wp_ajax_bls_scan_batch',      [ __CLASS__, 'ajax_scan_batch' ] );
        add_action( 'wp_ajax_bls_save_map_entry',  [ __CLASS__, 'ajax_save_map_entry' ] );
        add_action( 'wp_ajax_bls_apply_map',       [ __CLASS__, 'ajax_apply_map' ] );
        add_action( 'wp_ajax_bls_delete_map',      [ __CLASS__, 'ajax_delete_map' ] );
        add_action( 'wp_ajax_bls_preview_apply',   [ __CLASS__, 'ajax_preview_apply' ] );
        add_action( 'wp_ajax_bls_auto_fill_start', [ __CLASS__, 'ajax_auto_fill_start' ] );
        add_action( 'wp_ajax_bls_auto_fill_batch', [ __CLASS__, 'ajax_auto_fill_batch' ] );
        add_action( 'wp_ajax_bls_wipe_titles_start', [ __CLASS__, 'ajax_wipe_titles_start' ] );
        add_action( 'wp_ajax_bls_wipe_titles_batch', [ __CLASS__, 'ajax_wipe_titles_batch' ] );
        add_action( 'wp_ajax_bls_unlink_start',      [ __CLASS__, 'ajax_unlink_start' ] );
        add_action( 'wp_ajax_bls_unlink_batch',      [ __CLASS__, 'ajax_unlink_batch' ] );
        add_action( 'wp_ajax_bls_fix_link_url',      [ __CLASS__, 'ajax_fix_link_url' ] );
        add_action( 'wp_ajax_bls_run_gf_scan',     [ __CLASS__, 'ajax_run_gf_scan' ] );
        add_action( 'wp_ajax_bls_link_check_start',    [ __CLASS__, 'ajax_link_check_start' ] );
        add_action( 'wp_ajax_bls_link_check_tick',     [ __CLASS__, 'ajax_link_check_tick' ] );
        add_action( 'wp_ajax_bls_save_link_schedule',  [ __CLASS__, 'ajax_save_link_schedule' ] );
        add_action( 'wp_ajax_bls_dismiss_broken_link', [ __CLASS__, 'ajax_dismiss_broken_link' ] );
        add_action( 'admin_post_bls_export_links_csv',  [ __CLASS__, 'export_links_csv' ] );
        add_action( 'admin_post_bls_save_link_ignore',  [ __CLASS__, 'save_link_ignore' ] );
    }

    /**
     * Site-wide dismissible admin notice when broken links are on file.
     * This is the "notice posted in the dashboard" half of the feature —
     * the other half (email) fires from BLS_Link_Checker as soon as a
     * link is first detected as broken, regardless of whether anyone
     * visits wp-admin.
     */
    public static function broken_link_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_current_screen() && strpos( get_current_screen()->id, self::MENU_SLUG ) !== false ) {
            return; // Don't double up with the in-page summary on our own screens.
        }

        $count = BLS_Link_Checker::get_broken_count();
        if ( $count < 1 ) {
            return;
        }

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            sprintf(
                /* translators: %d: number of broken links */
                esc_html( _n( 'Button Link Scanner found %d broken link on your site.', 'Button Link Scanner found %d broken links on your site.', $count, 'button-link-scanner' ) ),
                (int) $count
            ),
            esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-broken-links' ) ),
            esc_html__( 'View report', 'button-link-scanner' )
        );
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
            __( 'Button Map', 'button-link-scanner' ),
            __( 'Button Map & Trends', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG . '-map',
            [ __CLASS__, 'page_button_map' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Form Confirmations', 'button-link-scanner' ),
            __( 'Form Confirmations', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG . '-gf',
            [ __CLASS__, 'page_gf_confirmations' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Broken Links', 'button-link-scanner' ),
            __( 'Broken Links', 'button-link-scanner' ),
            'manage_options',
            self::MENU_SLUG . '-broken-links',
            [ __CLASS__, 'page_broken_links' ]
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
        $summary        = BLS_Database::get_summary();
        $last_scan      = BLS_Database::get_last_scan_date();
        $skipped_pages  = get_option( 'bls_last_scan_skipped', [] );
        $confirmed_empty  = (int) get_option( 'bls_last_scan_confirmed_empty', 0 );
        $idx_vendor_pages  = (int) get_option( 'bls_last_scan_idx_vendor_pages', 0 );
        $offsite_redirects = (int) get_option( 'bls_last_scan_offsite_redirects', 0 );

        // Detect a scan that was started but never finished — e.g. the
        // browser tab driving the batch loop was navigated away from or
        // closed mid-scan. The server-side queue/progress options are
        // still sitting there in that case, but nothing on a fresh page
        // load would otherwise show it, leaving results looking silently
        // empty/reset with no explanation.
        $stuck_queue    = get_option( BLS_Scanner::QUEUE_OPTION, null );
        $stuck_progress = get_option( BLS_Scanner::PROGRESS_OPTION, null );
        $scan_abandoned = ( $stuck_queue !== null && $stuck_progress !== null );

        // Same detection, same reason, for Auto-Fill Missing Titles — it's
        // the identical client-side batch-loop pattern, so it's exposed to
        // the exact same "tab reload/navigate kills the loop" risk.
        $stuck_autofill_queue    = get_option( BLS_Updater::AUTOFILL_QUEUE_OPTION, null );
        $stuck_autofill_progress = get_option( BLS_Updater::AUTOFILL_PROGRESS_OPTION, null );
        $autofill_abandoned      = ( $stuck_autofill_queue !== null && $stuck_autofill_progress !== null );

        // Broken-link monitoring state, for the dashboard card.
        $broken_count      = BLS_Link_Checker::get_broken_count();
        $link_check_last   = BLS_Link_Checker::get_last_run();
        $link_check_freq   = get_option( 'bls_link_check_freq', '' );
        $link_check_email  = get_option( 'bls_link_check_email', get_option( 'admin_email' ) );

        // Last Auto-Fill Missing Titles result — persisted so it's still
        // visible after a reload (see ajax_auto_fill_titles) rather than
        // only living in transient JS status text that vanishes.
        $auto_fill_result = get_option( 'bls_last_auto_fill_result', null );

        // Same for the title wipe (undo), including its own
        // interrupted-run detection so a killed wipe can auto-resume.
        $wipe_result          = get_option( 'bls_last_wipe_result', null );
        $stuck_wipe_queue     = get_option( BLS_Updater::WIPE_QUEUE_OPTION, null );
        $stuck_wipe_progress  = get_option( BLS_Updater::WIPE_PROGRESS_OPTION, null );
        $wipe_abandoned       = ( $stuck_wipe_queue !== null && $stuck_wipe_progress !== null );

        include BLS_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    // -------------------------------------------------------------------------
    // Page: Broken Links
    // -------------------------------------------------------------------------

    public static function page_broken_links() {
        $ignored = static function ( array $rows ): array {
            return array_values( array_filter( $rows, static function ( $row ) {
                return ! BLS_Link_Checker::is_ignored( (string) $row->link_url );
            } ) );
        };

        $broken_links     = $ignored( BLS_Link_Checker::get_broken_links( 500 ) );
        $unverified_links = $ignored( BLS_Link_Checker::get_unverified_links( 500 ) );
        $last_run         = BLS_Link_Checker::get_last_run();
        $ignore_patterns  = BLS_Link_Checker::get_ignore_patterns();
        $last_unlink      = get_option( 'bls_last_unlink_result', [] );
        $unlink_abandoned = get_option( BLS_Updater::UNLINK_QUEUE_OPTION, null ) !== null;
        $url_fixes        = (array) get_option( 'bls_url_fix_history', [] );
        $button_meta      = BLS_Database::get_button_meta_for_links(
            array_merge( $broken_links, $unverified_links )
        );
        include BLS_PLUGIN_DIR . 'admin/views/broken-links.php';
    }

    // -------------------------------------------------------------------------
    // Page: Results
    // -------------------------------------------------------------------------

    public static function page_results() {
        $filters = [
            'has_link'  => isset( $_GET['has_link'] )  ? sanitize_text_field( $_GET['has_link'] )  : '',
            'has_title' => isset( $_GET['has_title'] ) ? sanitize_text_field( $_GET['has_title'] ) : '',
            'post_type' => isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : '',
            'kind'      => isset( $_GET['kind'] )      ? sanitize_text_field( $_GET['kind'] )      : '',
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
    // Page: Button Map (includes Link Trends — the two are one workflow:
    // see a label's usage, then assign/fix it, right in the same place)
    // -------------------------------------------------------------------------

    public static function page_button_map() {
        $map_entries = BLS_Database::get_button_map( 500 );
        $trends      = BLS_Database::get_trends( 200 );
        include BLS_PLUGIN_DIR . 'admin/views/button-map.php';
    }

    // -------------------------------------------------------------------------
    // Page: GF Confirmations
    // -------------------------------------------------------------------------

    public static function page_gf_confirmations() {
        $filters = [
            'passes' => isset( $_GET['passes'] ) ? sanitize_text_field( $_GET['passes'] ) : '',
            'search' => isset( $_GET['s'] )      ? sanitize_text_field( $_GET['s'] )      : '',
        ];

        $rows      = BLS_GF_Database::get_results( $filters );
        $summary   = BLS_GF_Database::get_summary();
        $last_scan = BLS_GF_Database::get_last_scan_date();

        include BLS_PLUGIN_DIR . 'admin/views/gf-confirmations.php';
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public static function ajax_run_gf_scan() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        ob_start();
        $scanner = new BLS_GF_Scanner();
        $result  = $scanner->run_full_scan();
        self::discard_stray_output();
        wp_send_json_success( $result );
    }

    /**
     * Discard any output that accumulated in the buffer during a scan.
     *
     * A scan calls apply_filters('the_content', ...) on every post, which
     * runs every OTHER active plugin's and the theme's content filters too
     * (Divi, WooCommerce, Yoast, etc.). If any of those emit a PHP notice
     * or warning — common on sites mixing plugin/theme versions — that text
     * gets printed straight into the HTTP response, landing right in the
     * middle of our JSON and breaking it (a valid-looking JSON body that
     * still throws a client-side "parsererror" is the tell-tale sign).
     *
     * We can't fix what other code prints, but we CAN make sure it never
     * reaches the response: buffer everything during the scan and throw
     * the buffer away right before sending our own clean JSON. Anything
     * unexpected gets logged so it's visible on the dashboard instead of
     * silently corrupting the request.
     */
    private static function discard_stray_output(): void {
        if ( ob_get_level() > 0 ) {
            $stray = ob_get_clean();
            if ( trim( $stray ) !== '' ) {
                $stripped = trim( wp_strip_all_tags( $stray ) );
                // If stripping tags left nothing visible (e.g. the stray
                // output was just markup/whitespace with no text), fall
                // back to a raw escaped representation so the log entry
                // is never blank — invisible characters (BOM, control
                // chars) show up explicitly instead of vanishing.
                $snippet = $stripped !== ''
                    ? substr( $stripped, 0, 300 )
                    : 'raw bytes: ' . substr( wp_json_encode( $stray ), 0, 300 );
                // A notice, not a failure. Another plugin printing during our
                // AJAX request is ordinary on a busy site, and catching it is
                // the entire point of the buffer — the scan completed fine.
                // Recorded separately from real errors so it stops being
                // presented in red as though something broke.
                update_option( 'bls_last_scan_notice', 'Ignored some stray output from another plugin or theme during the scan. The scan itself completed normally — no action needed. Output was: ' . $snippet, false );
            }
        }
    }

    /**
     * Kick off a batched scan: clears old results and builds the work
     * queue. The browser then calls ajax_scan_batch repeatedly until done,
     * so no single HTTP request risks a server/gateway timeout.
     */
    public static function ajax_scan_start() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        delete_option( 'bls_last_scan_error' );
        delete_option( 'bls_last_scan_notice' );
        ob_start();
        try {
            $scanner = new BLS_Scanner();
            $result  = $scanner->start_scan();
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            update_option( 'bls_last_scan_error', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), false );
            wp_send_json_error( 'Scan start failed: ' . $e->getMessage() );
        }
    }

    /**
     * Process one batch of the scan queue. Called repeatedly by the browser
     * until the response reports done = true.
     */
    public static function ajax_scan_batch() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $batch_size = isset( $_POST['batch_size'] ) ? max( 1, (int) $_POST['batch_size'] ) : BLS_Scanner::DEFAULT_BATCH_SIZE;

        ob_start();
        try {
            $scanner = new BLS_Scanner();
            $result  = $scanner->run_batch( $batch_size );
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            update_option( 'bls_last_scan_error', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), false );
            wp_send_json_error( 'Batch failed: ' . $e->getMessage() );
        }
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

    /**
     * Kick off a batched Auto-Fill run: builds the work queue of every
     * button/link pair missing a title. The browser then calls
     * ajax_auto_fill_batch repeatedly until done, same pattern as
     * ajax_scan_start/ajax_scan_batch — see BLS_Updater::run_auto_fill_batch()
     * for why a single pass isn't safe on a real site's full scope.
     */
    public static function ajax_auto_fill_start() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        ob_start();
        try {
            $updater = new BLS_Updater();
            $result  = $updater->start_auto_fill();
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            wp_send_json_error( 'Auto-fill start failed: ' . $e->getMessage() );
        }
    }

    /**
     * Process one batch of the Auto-Fill queue. Called repeatedly by the
     * browser until the response reports done = true.
     */
    public static function ajax_auto_fill_batch() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $batch_size = isset( $_POST['batch_size'] ) ? max( 1, (int) $_POST['batch_size'] ) : BLS_Updater::AUTOFILL_DEFAULT_BATCH_SIZE;

        ob_start();
        try {
            $updater = new BLS_Updater();
            $result  = $updater->run_auto_fill_batch( $batch_size );
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            // Persist the failure the same way a completed run persists
            // its result, so it's visible on the Dashboard after reload
            // instead of only in a JS message that vanishes on navigation.
            update_option( 'bls_last_auto_fill_result', [
                'error' => 'Auto-fill failed: ' . $e->getMessage(),
                'time'  => current_time( 'mysql' ),
            ], false );
            wp_send_json_error( 'Auto-fill batch failed: ' . $e->getMessage() );
        }
    }

    /**
     * Kick off a batched wipe of auto-generated titles. Browser then calls
     * ajax_wipe_titles_batch repeatedly until done — same pattern as the
     * scan and Auto-Fill.
     */
    public static function ajax_wipe_titles_start() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        ob_start();
        try {
            $updater = new BLS_Updater();
            $result  = $updater->start_title_wipe();
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            wp_send_json_error( 'Title wipe start failed: ' . $e->getMessage() );
        }
    }

    public static function ajax_wipe_titles_batch() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $batch_size = isset( $_POST['batch_size'] ) ? max( 1, (int) $_POST['batch_size'] ) : BLS_Updater::WIPE_DEFAULT_BATCH_SIZE;

        ob_start();
        try {
            $updater = new BLS_Updater();
            $result  = $updater->run_title_wipe_batch( $batch_size );
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            update_option( 'bls_last_wipe_result', [
                'error' => 'Title wipe failed: ' . $e->getMessage(),
                'time'  => current_time( 'mysql' ),
            ], false );
            wp_send_json_error( 'Title wipe batch failed: ' . $e->getMessage() );
        }
    }

    /**
     * Point a broken link at a corrected address, across every page using it.
     * Synchronous — one URL touches a handful of pages, so there is nothing
     * here that needs batching.
     */
    public static function ajax_fix_link_url() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $old = isset( $_POST['old_url'] ) ? (string) wp_unslash( $_POST['old_url'] ) : '';
        $new = isset( $_POST['new_url'] ) ? (string) wp_unslash( $_POST['new_url'] ) : '';

        // esc_url_raw, not sanitize_text_field: this is going into an href.
        $new = esc_url_raw( trim( $new ) );

        ob_start();
        try {
            $updater = new BLS_Updater();
            $result  = $updater->replace_link_url( $old, $new );
            self::discard_stray_output();

            if ( empty( $result['ok'] ) ) {
                wp_send_json_error( $result['message'] );
            }
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            wp_send_json_error( 'Could not update the link: ' . $e->getMessage() );
        }
    }

    /**
     * Kick off a batched bulk unlink of selected dead links. Browser then
     * calls ajax_unlink_batch repeatedly until done.
     */
    public static function ajax_unlink_start() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : [];
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

        if ( empty( $ids ) ) {
            wp_send_json_error( 'No links selected.' );
        }

        ob_start();
        try {
            $updater = new BLS_Updater();
            $result  = $updater->start_unlink( $ids );
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            wp_send_json_error( 'Unlink start failed: ' . $e->getMessage() );
        }
    }

    public static function ajax_unlink_batch() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $batch_size = isset( $_POST['batch_size'] ) ? max( 1, (int) $_POST['batch_size'] ) : BLS_Updater::UNLINK_DEFAULT_BATCH_SIZE;

        ob_start();
        try {
            $updater = new BLS_Updater();
            $result  = $updater->run_unlink_batch( $batch_size );
            self::discard_stray_output();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            update_option( 'bls_last_unlink_result', [
                'error' => 'Unlink failed: ' . $e->getMessage(),
                'time'  => current_time( 'mysql' ),
            ], false );
            wp_send_json_error( 'Unlink batch failed: ' . $e->getMessage() );
        }
    }

    /**
     * Save the ignore list — URL patterns treated as working and left out of
     * the report entirely. Stored raw (one per line) and parsed on read, so the
     * textarea round-trips exactly what was typed.
     */
    public static function save_link_ignore() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'button-link-scanner' ) );
        }
        check_admin_referer( 'bls_save_link_ignore' );

        $raw = isset( $_POST['bls_ignore'] ) ? (string) wp_unslash( $_POST['bls_ignore'] ) : '';

        // Line-by-line sanitize rather than sanitize_textarea_field on the
        // whole blob, so blank lines are dropped and nothing is silently
        // reformatted.
        $lines = preg_split( '/\r\n|\r|\n/', $raw, -1, PREG_SPLIT_NO_EMPTY );
        $clean = [];
        foreach ( (array) $lines as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( $line !== '' ) {
                $clean[] = $line;
            }
        }

        update_option( BLS_Link_Checker::IGNORE_OPTION, implode( "\n", $clean ), false );

        // Clear anything the new list now excludes straight away, rather than
        // leaving it flagged until the next full check runs.
        $removed = BLS_Link_Checker::purge_unreportable_rows();

        wp_safe_redirect( add_query_arg(
            [
                'bls_ignore_saved'   => '1',
                'bls_ignore_removed' => $removed,
            ],
            admin_url( 'admin.php?page=' . self::MENU_SLUG . '-broken-links' )
        ) );
        exit;
    }

    /**
     * Stream the complete link-health report as CSV.
     *
     * The on-screen table is capped (500 rows) and paging through hundreds of
     * rows in the browser to work out what is actually wrong is miserable —
     * reviewing this list a screenshot at a time is exactly how a bug where
     * every mailto: link was reported as a 404 went unnoticed. One file with
     * every row, including the status, makes the pattern obvious at a glance.
     *
     * Streamed rather than written to disk: nothing to clean up, and it always
     * reflects the current state.
     */
    public static function export_links_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'button-link-scanner' ) );
        }
        check_admin_referer( 'bls_export_links_csv' );

        $filename = 'link-health-' . gmdate( 'Ymd-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'Classification', 'Status', 'Link', 'Link text', 'Found on page', 'Page URL', 'Last checked', 'Broken since' ] );

        $write = static function ( array $rows, string $label ) use ( $out ) {
            foreach ( $rows as $row ) {
                $status = (int) $row->http_status > 0
                    ? 'HTTP ' . (int) $row->http_status
                    : ( $row->error_message ?: 'no response' );
                fputcsv( $out, [
                    $label,
                    $status,
                    $row->link_url,
                    $row->button_text,
                    $row->post_title,
                    $row->post_url,
                    $row->last_checked,
                    $row->first_broken_at,
                ] );
            }
        };

        // No row cap here — the whole point is to see everything.
        // Ignored URLs are filtered here as well as at check time, so a
        // pattern added after the last check takes effect immediately rather
        // than only after re-checking.
        $filter = static function ( array $rows ): array {
            return array_values( array_filter( $rows, static function ( $row ) {
                return ! BLS_Link_Checker::is_ignored( (string) $row->link_url );
            } ) );
        };

        $write( $filter( BLS_Link_Checker::get_broken_links( 100000 ) ), 'broken' );
        $write( $filter( BLS_Link_Checker::get_unverified_links( 100000 ) ), 'could not verify' );

        fclose( $out );
        exit;
    }

    // -------------------------------------------------------------------------
    // Broken-link monitoring
    // -------------------------------------------------------------------------

    public static function ajax_link_check_start() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        ob_start();
        try {
            BLS_Link_Checker::start_check();
            self::discard_stray_output();
            wp_send_json_success( BLS_Link_Checker::get_check_progress() );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            wp_send_json_error( 'Link check failed to start: ' . $e->getMessage() );
        }
    }

    public static function ajax_link_check_tick() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        ob_start();
        try {
            $result = BLS_Link_Checker::run_tick();
            self::discard_stray_output();
            $result['broken_count'] = BLS_Link_Checker::get_broken_count();
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            wp_send_json_error( 'Link check batch failed: ' . $e->getMessage() );
        }
    }

    public static function ajax_save_link_schedule() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $freq  = sanitize_text_field( wp_unslash( $_POST['freq']  ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

        BLS_Link_Checker::update_schedule( $freq );
        if ( ! empty( $email ) ) {
            update_option( 'bls_link_check_email', $email );
        }

        wp_send_json_success( [ 'freq' => $freq, 'email' => $email ] );
    }

    /**
     * Mark a single broken link as dismissed/fixed without needing a full
     * re-check — useful once you've manually confirmed/corrected it.
     */
    public static function ajax_dismiss_broken_link() {
        check_ajax_referer( 'bls_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $id    = (int) ( $_POST['id'] ?? 0 );
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;
        $wpdb->update( $table, [ 'is_broken' => 0, 'first_broken_at' => null ], [ 'id' => $id ] );

        wp_send_json_success( [ 'id' => $id ] );
    }
}
