<?php
defined( 'ABSPATH' ) || exit;

/**
 * BLS_Link_Checker
 *
 * Periodically verifies that every distinct link_url found by a button
 * scan still actually resolves (not a 404, 5xx, or timeout/DNS failure).
 * This is a different question from "does this button have a link at
 * all" — a button can have a perfectly well-formed URL that used to work
 * and no longer does (a page got deleted, a third-party service changed
 * its URL structure, etc.). That's exactly the kind of thing that goes
 * unnoticed for months unless someone happens to click it.
 *
 * Runs as a self-rescheduling WP-Cron job in small batches (not a single
 * long-running request), so a large link list never risks a timeout.
 * When it finds a link that just went from healthy/unknown to broken, it:
 *  - Records it in the bls_link_health table
 *  - Shows a dismissible admin notice site-wide in wp-admin
 *  - Sends one email notification (not repeated every run for the same
 *    still-broken link — only when it first breaks)
 */
class BLS_Link_Checker {

    /** How many links to check per cron tick. Kept small and safe. */
    const BATCH_SIZE = 10;

    const QUEUE_OPTION = 'bls_link_check_queue';

    // -------------------------------------------------------------------------
    // Scheduling
    // -------------------------------------------------------------------------

    public static function maybe_schedule() {
        $freq = get_option( 'bls_link_check_freq', '' );
        $next = wp_next_scheduled( 'bls_link_check_start' );

        if ( empty( $freq ) ) {
            if ( $next ) {
                wp_clear_scheduled_hook( 'bls_link_check_start' );
            }
            return;
        }

        if ( ! $next ) {
            wp_schedule_event( time() + 60, $freq, 'bls_link_check_start' );
        }
    }

    public static function update_schedule( string $freq ): void {
        wp_clear_scheduled_hook( 'bls_link_check_start' );
        update_option( 'bls_link_check_freq', $freq );

        $valid = [ 'daily', 'weekly' ];
        if ( in_array( $freq, $valid, true ) ) {
            wp_schedule_event( time() + 60, $freq, 'bls_link_check_start' );
        }
    }

    // -------------------------------------------------------------------------
    // Queue-driven check (self-rescheduling — safe for any link count)
    // -------------------------------------------------------------------------

    /**
     * Build the list of distinct links to check and kick off the first
     * batch. Called either by cron (bls_link_check_start) or manually
     * from the dashboard ("Check Links Now").
     */
    public static function start_check(): void {
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $rows = $wpdb->get_results(
            "SELECT link_url, button_text, post_id, post_title, post_url
             FROM {$table}
             WHERE has_link = 1 AND link_url != ''
             GROUP BY link_url"
        );

        $queue = [];
        foreach ( $rows as $row ) {
            $queue[] = [
                'url'         => $row->link_url,
                'button_text' => $row->button_text,
                'post_id'     => (int) $row->post_id,
                'post_title'  => $row->post_title,
                'post_url'    => $row->post_url,
            ];
        }

        update_option( self::QUEUE_OPTION, $queue, false );
        update_option( 'bls_link_check_newly_broken', [], false );

        if ( empty( $queue ) ) {
            self::finish_check();
            return;
        }

        // Process the first batch immediately, then let run_tick's own
        // self-rescheduling take over for the rest.
        self::run_tick();
    }

    /**
     * Process one batch of the queue, then schedule itself again shortly
     * if there's more to do — or finalize (send notifications) if done.
     * Safe to call directly (manual "Check Links Now") or via cron.
     */
    public static function run_tick(): array {
        $queue = get_option( self::QUEUE_OPTION, [] );

        if ( empty( $queue ) ) {
            self::finish_check();
            return [ 'done' => true, 'remaining' => 0 ];
        }

        $batch = array_splice( $queue, 0, self::BATCH_SIZE );
        update_option( self::QUEUE_OPTION, $queue, false );

        foreach ( $batch as $item ) {
            self::check_one_link( $item );
        }

        if ( empty( $queue ) ) {
            self::finish_check();
            return [ 'done' => true, 'remaining' => 0 ];
        }

        // More to do — schedule the next tick shortly rather than looping
        // in this same request, so one slow/unreachable URL can never
        // compound into a long-running or timed-out request.
        if ( ! wp_next_scheduled( 'bls_link_check_tick' ) ) {
            wp_schedule_single_event( time() + 20, 'bls_link_check_tick' );
        }

        return [ 'done' => false, 'remaining' => count( $queue ) ];
    }

    /**
     * Turn a stored button URL into something wp_remote_get() can
     * actually check. Buttons correctly store relative paths straight
     * from their href/action attribute (e.g. "/about", "/donate/#donations")
     * — that's completely normal HTML and works fine in a browser, but
     * wp_remote_get() requires an absolute URL with a scheme and host, and
     * will reject a relative path outright with "a valid URL was not
     * provided" — which looked exactly like a broken link even though the
     * page was working perfectly.
     *
     * @return string|null Absolute URL to check, or null if this is a
     *                      pure in-page anchor (e.g. "#donations" with no
     *                      path) that can never meaningfully be "broken".
     */
    private static function resolve_checkable_url( string $url ): ?string {
        $url = trim( $url );

        if ( $url === '' || $url === '#' ) {
            return null;
        }

        // Pure fragment, no path — always resolves to the current page.
        if ( $url[0] === '#' ) {
            return null;
        }

        // Already absolute.
        if ( preg_match( '#^https?://#i', $url ) ) {
            return $url;
        }

        // Protocol-relative ("//example.com/x").
        if ( strpos( $url, '//' ) === 0 ) {
            return ( is_ssl() ? 'https:' : 'http:' ) . $url;
        }

        // Root-relative or bare relative path — resolve against this site.
        $path = '/' . ltrim( $url, '/' );
        return home_url( $path );
    }

    /**
     * Check a single URL and upsert its health record.
     */
    private static function check_one_link( array $item ): void {
        global $wpdb;
        $table    = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;
        $url      = $item['url']; // The URL as stored/displayed — kept as-is (e.g. "/donate/#donations").
        $hash     = md5( $url );
        $check_url = self::resolve_checkable_url( $url );

        // Pure in-page anchors (e.g. "#donations" with no path) always
        // resolve to the current page — there's nothing external to test,
        // so they're never meaningfully "broken". Skip checking entirely
        // rather than recording a false failure.
        if ( $check_url === null ) {
            return;
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE url_hash = %s", $hash
        ) );

        // HEAD first (cheap); some servers reject HEAD, so fall back to GET.
        $response = wp_remote_head( $check_url, [
            'timeout'     => 8,
            'redirection' => 5,
            'user-agent'  => 'WordPress/BLS-LinkChecker',
        ] );

        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        if ( is_wp_error( $response ) || $code === 0 || $code === 405 ) {
            $response = wp_remote_get( $check_url, [
                'timeout'     => 10,
                'redirection' => 5,
                'user-agent'  => 'WordPress/BLS-LinkChecker',
            ] );
            $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        }

        $error_message = is_wp_error( $response ) ? $response->get_error_message() : '';
        $is_broken     = is_wp_error( $response ) || $code >= 400;

        $was_broken     = $existing ? (int) $existing->is_broken === 1 : false;
        $first_broken   = $existing && $existing->first_broken_at ? $existing->first_broken_at : null;

        if ( $is_broken && ! $was_broken ) {
            // Just went bad — queue it for notification.
            $first_broken = current_time( 'mysql' );
            $newly        = get_option( 'bls_link_check_newly_broken', [] );
            $newly[]      = [
                'url'         => $url,
                'button_text' => $item['button_text'],
                'post_title'  => $item['post_title'],
                'post_url'    => $item['post_url'],
                'http_status' => $code,
                'error'       => $error_message,
            ];
            update_option( 'bls_link_check_newly_broken', $newly, false );
        } elseif ( ! $is_broken ) {
            $first_broken = null; // Recovered — clear the broken-since marker.
        }

        $data = [
            'url_hash'        => $hash,
            'link_url'        => $url,
            'button_text'     => $item['button_text'],
            'post_id'         => $item['post_id'],
            'post_title'      => $item['post_title'],
            'post_url'        => $item['post_url'],
            'http_status'     => $code,
            'is_broken'       => $is_broken ? 1 : 0,
            'error_message'   => substr( $error_message, 0, 255 ),
            'last_checked'    => current_time( 'mysql' ),
            'first_broken_at' => $first_broken,
        ];

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => $existing->id ] );
        } else {
            $wpdb->insert( $table, $data );
        }
    }

    /**
     * Queue fully processed: send one notification for anything newly
     * broken this run, and record when the check completed.
     */
    private static function finish_check(): void {
        update_option( 'bls_link_check_last_run', current_time( 'mysql' ) );

        $newly = get_option( 'bls_link_check_newly_broken', [] );
        if ( ! empty( $newly ) ) {
            self::send_notification( $newly );
        }
        delete_option( 'bls_link_check_newly_broken' );
    }

    // -------------------------------------------------------------------------
    // Notification
    // -------------------------------------------------------------------------

    private static function send_notification( array $broken_links ): void {
        $to = get_option( 'bls_link_check_email', get_option( 'admin_email' ) );
        if ( empty( $to ) ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $count     = count( $broken_links );
        $subject   = sprintf(
            /* translators: 1: site name, 2: number of broken links */
            _n( '%1$s: %2$d broken link found', '%1$s: %2$d broken links found', $count, 'button-link-scanner' ),
            $site_name,
            $count
        );

        $lines   = [];
        $lines[] = sprintf( __( 'Button Link Scanner found %d newly broken link(s) on %s:', 'button-link-scanner' ), $count, $site_name );
        $lines[] = '';

        foreach ( $broken_links as $link ) {
            $status = $link['http_status'] > 0 ? 'HTTP ' . $link['http_status'] : ( $link['error'] ?: 'unreachable' );
            $lines[] = '- "' . $link['button_text'] . '" (' . $status . ')';
            $lines[] = '  Link: '   . $link['url'];
            $lines[] = '  Found on: ' . $link['post_title'] . ' — ' . $link['post_url'];
            $lines[] = '';
        }

        $lines[] = __( 'View the full report:', 'button-link-scanner' ) . ' ' . admin_url( 'admin.php?page=button-link-scanner-broken-links' );

        wp_mail( $to, $subject, implode( "\n", $lines ) );

        // Record the email fire time on each affected row.
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;
        foreach ( $broken_links as $link ) {
            $wpdb->update(
                $table,
                [ 'notified_at' => current_time( 'mysql' ) ],
                [ 'url_hash' => md5( $link['url'] ) ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Reads (for the admin UI)
    // -------------------------------------------------------------------------

    public static function get_broken_links( int $limit = 200 ): array {
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE is_broken = 1 ORDER BY first_broken_at DESC LIMIT %d",
            $limit
        ) );
    }

    public static function get_broken_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_broken = 1" );
    }

    public static function get_last_run(): string {
        return (string) get_option( 'bls_link_check_last_run', '' );
    }

    public static function get_check_progress(): array {
        $queue = get_option( self::QUEUE_OPTION, [] );
        return [ 'remaining' => count( $queue ), 'in_progress' => ! empty( $queue ) ];
    }
}
