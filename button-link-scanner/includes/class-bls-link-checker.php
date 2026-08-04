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

        // Only enqueue things that can actually be fetched. The results table
        // holds every link, mailto:/tel:/in-page anchors included, and those
        // have no HTTP answer to give — checking them produced a 404 for every
        // email and phone link on the site. resolve_checkable_url() is the
        // single source of truth for "is this fetchable", used here and again
        // per-item as a backstop.
        $queue    = [];
        $by_host  = [];
        $keep     = [];
        foreach ( $rows as $row ) {
            $checkable = self::resolve_checkable_url( $row->link_url );
            if ( $checkable === null ) {
                continue;
            }

            // Assumed working — see DEFAULT_IGNORE_PATTERNS.
            if ( self::is_ignored( $row->link_url ) ) {
                continue;
            }

            $keep[ md5( $row->link_url ) ] = true;

            $item = [
                'url'         => $row->link_url,
                'button_text' => $row->button_text,
                'post_id'     => (int) $row->post_id,
                'post_title'  => $row->post_title,
                'post_url'    => $row->post_url,
            ];

            $host = strtolower( (string) wp_parse_url( $checkable, PHP_URL_HOST ) );
            $by_host[ $host ][] = $item;
        }

        // Interleave by host rather than checking every link to one domain
        // back to back. Hammering a single host in sequence is what earns a 429
        // or a temporary block, which then gets reported as a link problem when
        // it is really self-inflicted. Round-robin spreads the load.
        while ( $by_host ) {
            foreach ( array_keys( $by_host ) as $host ) {
                $queue[] = array_shift( $by_host[ $host ] );
                if ( empty( $by_host[ $host ] ) ) {
                    unset( $by_host[ $host ] );
                }
            }
        }

        // Drop health rows for anything this run is not going to check.
        // Skipping a URL when the queue is built means check_one_link() never
        // runs for it, so the cleanup inside that function can never fire — an
        // email or phone link flagged by an older version stayed flagged
        // forever, and a link deleted from the site kept being reported. This
        // is the only place that knows the full set of URLs still in play.
        self::purge_stale_health_rows( array_keys( $keep ) );

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
     * Delete every health row whose URL is not in the set about to be
     * checked. Covers all three ways a row goes stale at once: the URL is
     * no longer fetchable (mailto:, tel:, in-page anchor), it is on the
     * ignore list, or the button holding it was edited or deleted and the
     * URL is simply gone from the site.
     *
     * Diffed in PHP and deleted in chunks rather than as one
     * "NOT IN (...)" — a site with a few thousand links would otherwise
     * build a single enormous query.
     *
     * @param string[] $keep_hashes md5() of each link_url still in play.
     */
    private static function purge_stale_health_rows( array $keep_hashes ): void {
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;

        $existing = $wpdb->get_col( "SELECT url_hash FROM {$table}" );
        if ( empty( $existing ) ) {
            return;
        }

        $keep  = array_fill_keys( $keep_hashes, true );
        $stale = [];
        foreach ( $existing as $hash ) {
            if ( ! isset( $keep[ $hash ] ) ) {
                $stale[] = $hash;
            }
        }

        if ( empty( $stale ) ) {
            return;
        }

        foreach ( array_chunk( $stale, 200 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE url_hash IN ({$placeholders})",
                $chunk
            ) );
        }
    }

    /**
     * Drop health rows for URLs that should never have been reported in the
     * first place: nothing fetchable (mailto:, tel:, in-page anchor) or on
     * the ignore list.
     *
     * Works off the health table alone, so it can run the moment the ignore
     * list is edited instead of making someone sit through a full re-check to
     * see the rows they just excluded disappear.
     *
     * @return int Rows removed.
     */
    public static function purge_unreportable_rows(): int {
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;

        $rows = $wpdb->get_results( "SELECT url_hash, link_url FROM {$table}" );
        if ( empty( $rows ) ) {
            return 0;
        }

        $stale = [];
        foreach ( $rows as $row ) {
            if ( self::resolve_checkable_url( $row->link_url ) === null || self::is_ignored( $row->link_url ) ) {
                $stale[] = $row->url_hash;
            }
        }

        if ( empty( $stale ) ) {
            return 0;
        }

        foreach ( array_chunk( $stale, 200 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE url_hash IN ({$placeholders})",
                $chunk
            ) );
        }

        return count( $stale );
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

        // Non-HTTP schemes cannot be fetched: mailto:, tel:, sms:, callto:,
        // javascript:, and so on. There is nothing to request, so there is
        // nothing to be "broken" about in HTTP terms.
        //
        // Without this they fell through to the relative-path branch below and
        // got resolved against the site, so "mailto:sales@example.com" became
        // "https://thesite.com/mailto:sales@example.com" and 404'd. Every email
        // and phone link on a site was therefore reported broken — on
        // collegestationhomes.com that was most of the top of the report, and it
        // buried the genuine failures underneath.
        //
        // The scheme has to appear before any slash so a relative path is never
        // mistaken for one.
        if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $scheme_match ) ) {
            $scheme = strtolower( $scheme_match[1] );
            if ( $scheme !== 'http' && $scheme !== 'https' ) {
                return null;
            }
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

        // Nothing fetchable here — an in-page anchor, or a mailto:/tel:-style
        // scheme — or a URL on the ignore list, which is assumed to work.
        // Skip rather than recording a false failure, and delete any row an
        // earlier check left behind so it clears from the report rather than
        // staying flagged forever.
        if ( $check_url === null || self::is_ignored( $url ) ) {
            $wpdb->delete( $table, [ 'url_hash' => $hash ] );
            return;
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE url_hash = %s", $hash
        ) );

        // Internal links resolve against the database — no request, so no
        // self-inflicted rate limiting. See verify_internal().
        $internal = self::verify_internal( $check_url );
        if ( $internal !== null ) {
            self::record_result( $item, $url, $hash, $existing, $internal === 'broken', 0, '' );
            return;
        }

        // Space out repeat requests to the same host before touching it.
        self::pace_request( strtolower( (string) wp_parse_url( $check_url, PHP_URL_HOST ) ) );

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

        // One more attempt, with a longer timeout, when the failure was a
        // timeout rather than a real answer. A slow-but-working host was
        // otherwise reported as broken. Not retried for 429, where an
        // immediate second request just gets rate-limited again.
        if ( self::is_timeout( $response ) ) {
            $response = wp_remote_get( $check_url, [
                'timeout'     => 20,
                'redirection' => 5,
                'user-agent'  => 'WordPress/BLS-LinkChecker',
            ] );
            $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        }

        $error_message = is_wp_error( $response ) ? $response->get_error_message() : '';

        // Only a conclusive failure counts as broken. See classify().
        $is_broken = self::classify( $response, $code ) === 'broken';

        self::record_result( $item, $url, $hash, $existing, $is_broken, $code, $error_message );
    }

    /**
     * Write one link's health to the table, queueing a notification if it has
     * just gone bad. Shared by the HTTP path and the database-resolved internal
     * path so both record identically.
     */
    private static function record_result( array $item, string $url, string $hash, $existing, bool $is_broken, int $code, string $error_message ): void {
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;

        $was_broken   = $existing ? (int) $existing->is_broken === 1 : false;
        $first_broken = $existing && $existing->first_broken_at ? $existing->first_broken_at : null;

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


    /**
     * Verify a link on this site against the database instead of fetching it.
     *
     * Most links on a content site are internal, and firing them back at the
     * server over HTTP is both wasteful and actively counterproductive: the
     * host's own rate limiting sees a burst of requests from itself and answers
     * 429. On collegestationhomes.com that produced HTTP 429 against the site's
     * own /blog/ and /community-resources/ pages — reported as broken links
     * when nothing was wrong with them at all.
     *
     * url_to_postid() resolves a permalink to its post without any request. A
     * published post means the link is good. A post that exists but is no longer
     * public (draft, pending, private, trashed) is genuinely broken for a
     * visitor, and worth reporting.
     *
     * Returns 'ok', 'broken', or null when the URL cannot be resolved this way —
     * archives, term pages, paginated URLs and custom routes all land there and
     * still need a real request.
     */
    private static function verify_internal( string $url ): ?string {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        if ( $host === '' || preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $home ) ) {
            return null; // Not ours — has to be fetched.
        }

        $post_id = url_to_postid( $url );
        if ( $post_id < 1 ) {
            return null; // Could be an archive or a custom route; fall back to HTTP.
        }

        $status = get_post_status( $post_id );

        return in_array( $status, [ 'publish', 'inherit' ], true ) ? 'ok' : 'broken';
    }

    /**
     * Pause briefly before hitting a host we just requested.
     *
     * A batch is processed in a tight loop, so without this the server sees ten
     * near-simultaneous requests and rate limits them — the 429s above. Only
     * applies to repeat hits on the same host within a batch, so a run spread
     * across many domains is not slowed down.
     */
    private static function pace_request( string $host ): void {
        static $last = [];

        $now = microtime( true );
        if ( isset( $last[ $host ] ) ) {
            $elapsed = $now - $last[ $host ];
            $min     = (float) apply_filters( 'bls_link_check_host_delay', 0.5 );
            if ( $elapsed < $min ) {
                usleep( (int) ( ( $min - $elapsed ) * 1000000 ) );
            }
        }
        $last[ $host ] = microtime( true );
    }

    /** Option holding the user-editable ignore patterns, one per line. */
    const IGNORE_OPTION = 'bls_link_check_ignore';

    /**
     * Hosts and URL patterns not worth checking, used when the option has
     * never been saved.
     *
     * These block non-browser requests as a matter of policy, so the checker
     * can never get a useful answer from them — it only ever sees 403. A
     * visitor clicking the link is fine. Reporting them forever, even as
     * "could not verify", trains people to stop reading the report, so they
     * are treated as working and left out of it entirely.
     *
     * Matched as a plain case-insensitive substring of the URL, so both a bare
     * host ("facebook.com") and a path pattern ("google.com/search") work.
     */
    const DEFAULT_IGNORE_PATTERNS = [
        'google.com/search',
        'google.com/maps',
        'facebook.com',
        'instagram.com',
        'linkedin.com',
        'x.com/',
        'twitter.com',
        'pinterest.com',
        'tiktok.com',
        'yelp.com',
        'zillow.com',
        'realtor.com',
    ];

    /** The active ignore patterns — saved option if present, defaults if not. */
    public static function get_ignore_patterns(): array {
        $saved = get_option( self::IGNORE_OPTION, null );

        if ( $saved === null ) {
            $patterns = self::DEFAULT_IGNORE_PATTERNS;
        } else {
            $patterns = preg_split( '/\r\n|\r|\n/', (string) $saved, -1, PREG_SPLIT_NO_EMPTY );
        }

        $patterns = array_filter( array_map( 'trim', (array) $patterns ) );

        return (array) apply_filters( 'bls_link_check_ignore_patterns', $patterns );
    }

    /** True if this URL matches an ignore pattern and should be treated as working. */
    public static function is_ignored( string $url ): bool {
        if ( $url === '' ) {
            return false;
        }
        foreach ( self::get_ignore_patterns() as $pattern ) {
            if ( $pattern !== '' && stripos( $url, $pattern ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Statuses that mean "the server answered, but not with the page" —
     * without proving the link is dead.
     *
     * 401/403 are overwhelmingly bot protection and login walls; MLS, news
     * and brokerage sites return them to anything without a browser session,
     * while a human visitor gets through fine. 408/429 are the server asking
     * to be left alone, which is exactly what checking thousands of links
     * against the same handful of domains provokes.
     *
     * Reporting these as broken buried the genuine 404s: on a 2,900-link site
     * it produced hundreds of "broken" links that were nothing of the sort,
     * which trains people to ignore the alert entirely. They are now tracked
     * separately as "couldn't verify" — visible, but not alarming and never
     * emailed.
     *
     * 444 and 460 are non-standard "we hung up on you" codes — nginx closing
     * without a response, and an AWS load balancer reporting the connection
     * was dropped. Both are anti-bot behaviour: tripsavvy.com, investopedia.com,
     * allrecipes.com and treehugger.com all answered 460 while working
     * perfectly in a browser.
     */
    const INCONCLUSIVE_STATUSES = [ 401, 403, 408, 429, 444, 460 ];

    /**
     * Classify a response: 'broken', 'unverified', or 'ok'.
     *
     * Broken means conclusive — the address itself is wrong or the page is
     * gone: a 4xx answer, or a transport error proving there is nothing at
     * the other end (DNS failure, connection refused), which is where a
     * malformed href like "http://TEA Accountability for Bryan/..."
     * correctly lands.
     *
     * A 5xx is deliberately NOT broken. The address resolved and a server
     * answered; that server is just having a bad moment. Cloudflare's 520-527
     * range is the clearest case — 522 is "origin didn't answer in time" and
     * 525 is "SSL handshake with the origin failed", both of which come and go
     * and neither of which a visitor would necessarily hit. 502/503/504 are
     * the same story. Calling these broken put third-party outages in the same
     * list as genuinely dead URLs: three of the top four rows on
     * collegestationhomes.com were 525/502/522 against sites that were
     * perfectly fine, which is exactly what makes a report unreadable.
     */
    private static function classify( $response, int $code ): string {
        if ( is_wp_error( $response ) ) {
            // A timeout or a TLS handshake/certificate problem says something
            // about the connection, not that the page is gone — and an out of
            // date CA bundle on the checking server produces the latter for
            // sites that work fine in a browser.
            return self::is_inconclusive_transport_error( $response ) ? 'unverified' : 'broken';
        }
        if ( in_array( $code, self::INCONCLUSIVE_STATUSES, true ) ) {
            return 'unverified';
        }
        if ( $code >= 500 ) {
            return 'unverified'; // Server-side trouble, not a bad link.
        }
        if ( $code >= 400 ) {
            return 'broken';
        }
        return 'ok';
    }

    /** True if a WP_Error represents a timeout rather than a hard failure. */
    private static function is_timeout( $response ): bool {
        if ( ! is_wp_error( $response ) ) {
            return false;
        }
        $message = strtolower( $response->get_error_message() );
        foreach ( [ 'timed out', 'timeout', 'operation too slow', 'curl error 28' ] as $needle ) {
            if ( str_contains( $message, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Transport failures that do not prove the link is dead: timeouts, and
     * TLS/certificate problems. The latter matter because the checking server's
     * CA bundle can be out of date, which makes perfectly reachable sites look
     * broken. A genuinely dead host fails differently (DNS resolution,
     * connection refused) and is still reported as broken.
     */
    private static function is_inconclusive_transport_error( $response ): bool {
        if ( self::is_timeout( $response ) ) {
            return true;
        }
        $message = strtolower( $response->get_error_message() );
        foreach ( [ 'ssl', 'tls', 'certificate', 'curl error 35', 'curl error 60' ] as $needle ) {
            if ( str_contains( $message, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Links the checker could not get a conclusive answer for — see
     * INCONCLUSIVE_STATUSES. Derived from the stored status rather than a
     * dedicated column so no schema migration is needed.
     */
    public static function get_unverified_links( int $limit = 500 ): array {
        global $wpdb;
        $table  = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;
        $codes  = implode( ',', array_map( 'intval', self::INCONCLUSIVE_STATUSES ) );
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE is_broken = 0 AND ( http_status IN ({$codes}) OR ( http_status = 0 AND error_message != '' ) )
             ORDER BY last_checked DESC LIMIT %d",
            $limit
        ) );
    }

    public static function get_unverified_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . BLS_Database::LINK_HEALTH_TABLE;
        $codes = implode( ',', array_map( 'intval', self::INCONCLUSIVE_STATUSES ) );
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE is_broken = 0 AND ( http_status IN ({$codes}) OR ( http_status = 0 AND error_message != '' ) )"
        );
    }

    /** True once a link check has actually run — distinct from "found none". */
    public static function has_ever_run(): bool {
        return (string) get_option( 'bls_link_check_last_run', '' ) !== '';
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
