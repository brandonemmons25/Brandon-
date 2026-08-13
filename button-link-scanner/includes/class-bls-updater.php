<?php
defined( 'ABSPATH' ) || exit;

/**
 * BLS_Updater
 *
 * Applies Button Map assignments to post content site-wide.
 * Each map entry says: "every button whose text matches X should
 * point to URL Y with title Z."
 *
 * The updater rewrites post_content in the database using
 * DOMDocument so it handles both Gutenberg blocks and classic HTML.
 */
class BLS_Updater {

    /**
     * Apply a single button-map entry to all matching posts.
     *
     * @param int $map_id Button map row ID.
     * @return array { updated_posts: int, updated_buttons: int, skipped: int }
     */
    public function apply_map_entry( int $map_id ): array {
        global $wpdb;

        $map_table = $wpdb->prefix . BLS_Database::BUTTON_MAP_TABLE;
        $entry     = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$map_table} WHERE id = %d", $map_id
        ) );

        if ( ! $entry || empty( $entry->assigned_url ) ) {
            return [ 'error' => 'Map entry not found or has no assigned URL.' ];
        }

        // Find all distinct post_ids that contain this button text.
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;
        $hash      = $entry->button_text_hash;
        $text      = $entry->button_text;

        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$res_table}
             WHERE MD5(LOWER(TRIM(button_text))) = %s",
            $hash
        ) );

        if ( empty( $post_ids ) ) {
            return [ 'updated_posts' => 0, 'updated_buttons' => 0, 'skipped' => 0 ];
        }

        $updated_posts   = 0;
        $updated_buttons = 0;
        $skipped         = 0;

        foreach ( $post_ids as $post_id ) {
            $post = get_post( (int) $post_id );
            if ( ! $post ) {
                $skipped++;
                continue;
            }

            $result = $this->rewrite_post_content( $post, $text, $entry );

            if ( $result['changed'] ) {
                wp_update_post( [
                    'ID'           => $post->ID,
                    'post_content' => $result['content'],
                ] );
                $updated_posts++;
                $updated_buttons += $result['count'];
            } else {
                $skipped++;
            }
        }

        BLS_Database::increment_apply_count( $map_id, $updated_buttons );

        return compact( 'updated_posts', 'updated_buttons', 'skipped' );
    }

    /**
     * Apply all map entries that have an assigned URL.
     *
     * @return array Summary per entry.
     */
    public function apply_all_map_entries(): array {
        global $wpdb;
        $map_table = $wpdb->prefix . BLS_Database::BUTTON_MAP_TABLE;
        $entries   = $wpdb->get_results( "SELECT id FROM {$map_table} WHERE assigned_url != '' ORDER BY id ASC" );

        $summary = [];
        foreach ( $entries as $row ) {
            $summary[ $row->id ] = $this->apply_map_entry( (int) $row->id );
        }
        return $summary;
    }

    // -------------------------------------------------------------------------
    // Content rewriting
    // -------------------------------------------------------------------------

    /**
     * Rewrite all matching buttons in a single post's content.
     *
     * @return array { content: string, changed: bool, count: int }
     */
    private function rewrite_post_content( WP_Post $post, string $button_text, object $entry ): array {
        $content         = $post->post_content;
        $normalized_text = strtolower( trim( $button_text ) );
        $changed         = false;
        $count           = 0;

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );

        // Target any anchor whose text matches — not just button-styled
        // ones. Button Map now covers both categories (buttons and plain
        // content hyperlinks), since the exact-text match is already
        // tight enough to be safe either way, and a body-copy link is
        // just as fixable this way as a styled CTA button.
        $anchors = $xpath->query( '//a' );
        foreach ( $anchors as $anchor ) {
            $text = strtolower( trim( $anchor->textContent ) );
            if ( $text !== $normalized_text ) {
                continue;
            }

            $anchor->setAttribute( 'href', esc_url_raw( $entry->assigned_url ) );

            if ( ! empty( $entry->assigned_title ) ) {
                $anchor->setAttribute( 'title', sanitize_text_field( $entry->assigned_title ) );
            }

            if ( $entry->opens_new_tab ) {
                $anchor->setAttribute( 'target', '_blank' );
                $anchor->setAttribute( 'rel', 'noopener noreferrer' );
            } else {
                $anchor->removeAttribute( 'target' );
            }

            $changed = true;
            $count++;
        }

        if ( ! $changed ) {
            return [ 'content' => $content, 'changed' => false, 'count' => 0 ];
        }

        // Extract the body content back (strip the artificial wrapper tags).
        $new_html = $dom->saveHTML();
        $new_html = $this->strip_dom_wrapper( $new_html, $content );

        return [ 'content' => $new_html, 'changed' => true, 'count' => $count ];
    }

    /**
     * DOMDocument adds <html><body> wrappers; strip them back out.
     */
    private function strip_dom_wrapper( string $dom_output, string $original ): string {
        // Remove the xml declaration and html/body wrappers that DOMDocument adds.
        $dom_output = preg_replace( '/^.*<body>/s', '', $dom_output );
        $dom_output = preg_replace( '/<\/body>.*$/s', '', $dom_output );
        $dom_output = preg_replace( '/^<\?xml[^>]+>\n?/', '', $dom_output );
        return trim( $dom_output );
    }

    /**
     * Preview: return all posts + buttons that would be changed for a map entry.
     */
    public function preview_map_entry( int $map_id ): array {
        global $wpdb;

        $map_table = $wpdb->prefix . BLS_Database::BUTTON_MAP_TABLE;
        $entry     = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$map_table} WHERE id = %d", $map_id
        ) );

        if ( ! $entry ) {
            return [];
        }

        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, post_title, post_type, post_url, link_url, has_title
             FROM {$res_table}
             WHERE MD5(LOWER(TRIM(button_text))) = %s",
            $entry->button_text_hash
        ) );
    }

    // -------------------------------------------------------------------------
    // Auto-Fill Missing Titles
    // -------------------------------------------------------------------------

    /** Option name used to persist the remaining-work queue between AJAX batch calls. */
    const AUTOFILL_QUEUE_OPTION = 'bls_autofill_queue';

    /** Option name used to persist running totals between AJAX batch calls. */
    const AUTOFILL_PROGRESS_OPTION = 'bls_autofill_progress';

    /** Default number of button/link pairs processed per batch. */
    const AUTOFILL_DEFAULT_BATCH_SIZE = 5;

    /**
     * Max individual title changes recorded in a run's audit log. Past
     * this, changes still happen and are still counted — only the
     * per-change detail stops being stored, and the overflow is reported
     * as a count so the log never silently looks complete when it isn't.
     */
    const AUDIT_LOG_LIMIT = 500;

    /**
     * Subdirectory under wp-content/uploads where the COMPLETE log of a run
     * is written as CSV, one row appended per change as it happens.
     *
     * The on-page table is deliberately capped (AUDIT_LOG_LIMIT) because it
     * lives in a single wp_options row and renders as HTML — a run applying
     * ~2,800 titles, which happens on a real site, would mean about a
     * megabyte of serialized data re-read on every dashboard load and a
     * table thousands of rows long. Streaming to a file instead keeps the
     * option row small and the page fast while removing the ceiling
     * entirely, and CSV is the more useful format for verifying a run or
     * handing the record to someone else.
     *
     * Filenames carry a random suffix so the file isn't enumerable by URL,
     * and an index.php is dropped in the directory to stop listing. The
     * contents (page titles, page URLs, link URLs, generated titles) are all
     * already-public information, so this is about not leaving it lying
     * around guessable rather than protecting a secret.
     */
    const LOG_SUBDIR = 'bls-logs';

    /** Queue/progress options for the batched title wipe (see start_title_wipe()). */
    const WIPE_QUEUE_OPTION    = 'bls_wipe_queue';
    const WIPE_PROGRESS_OPTION = 'bls_wipe_progress';

    /** Default number of posts processed per wipe batch. */
    const WIPE_DEFAULT_BATCH_SIZE = 5;

    /** Queue/progress options for the batched bulk unlink (see start_unlink()). */
    const UNLINK_QUEUE_OPTION    = 'bls_unlink_queue';
    const UNLINK_PROGRESS_OPTION = 'bls_unlink_progress';

    /**
     * Default URLs processed per unlink batch — deliberately smaller than the
     * others. Each item re-verifies over HTTP before touching anything, so a
     * batch is bounded by network time, not database time.
     */
    const UNLINK_DEFAULT_BATCH_SIZE = 3;

    /**
     * Build the work queue: every distinct button/link pair (buttons AND
     * plain content hyperlinks alike — this was never scoped to buttons
     * only) that has a link but no title. Call once, then call
     * run_auto_fill_batch() repeatedly until it reports done = true — see
     * that method's docblock for why this can't be a single pass.
     *
     * @return array { total_items: int }
     */
    public function start_auto_fill(): array {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $pairs = $wpdb->get_results(
            "SELECT DISTINCT button_text, link_url FROM {$res_table}
             WHERE has_link = 1 AND has_title = 0 AND link_url != ''"
        );

        $queue = [];
        foreach ( $pairs as $pair ) {
            $queue[] = [ 'button_text' => $pair->button_text, 'link_url' => $pair->link_url ];
        }

        $log = $this->create_log_file( 'auto-fill', [
            'Page', 'Page ID', 'Page URL', 'Button/link text', 'Links to',
            'Title set to', 'Occurrences on page', 'Applied via',
        ] );

        update_option( self::AUTOFILL_QUEUE_OPTION, $queue, false );
        update_option( self::AUTOFILL_PROGRESS_OPTION, [
            'total_items'         => count( $queue ),
            'pairs_processed'     => 0,
            'posts_updated'       => 0,
            'updated_post_ids'    => [], // Keyed set — counted as unique pages at the end.
            'already_titled'      => 0,  // Found, already had a title: nothing to do (not a failure).
            'titles_added'        => 0,
            'titles_injected'     => 0,
            'could_not_apply'     => 0,
            'not_in_database'     => 0,
            'blocked_by_mismatch' => 0,
            'diagnostic'          => null,
            // Audit trail of every title actually written, so a run can be
            // reviewed/verified after the fact instead of being trusted on
            // a summary count alone. Capped (see AUDIT_LOG_LIMIT) to keep
            // this option row from growing without bound on a large site.
            'changes'             => [],
            'changes_truncated'   => 0,
            // Complete log streamed to CSV — the in-memory 'changes' list
            // above is only the capped on-page preview. See LOG_SUBDIR.
            'log_path'            => $log['path'] ?? '',
            'log_url'             => $log['url'] ?? '',
        ], false );

        return [ 'total_items' => count( $queue ) ];
    }

    /**
     * Process the next batch of pairs from the stored queue.
     *
     * A single pass over every missing-title pair was never going to
     * scale: this isn't scoped to the handful of styled buttons a site
     * has (98 here) — it's every button OR plain content hyperlink
     * missing a title, which on a real site is easily in the thousands
     * (5,902 on this one). Each pair can involve DOM parsing across up
     * to 6 content sources per matching post, so running the whole queue
     * in one synchronous HTTP request risked exactly the kind of
     * open-ended hang a fixed PHP max_execution_time can't survive.
     * Batched the same way the scanner already is: a handful of pairs
     * per call, looping via repeated AJAX requests until done.
     *
     * @param  int $batch_size How many pairs to process this call.
     * @return array { done: bool, pairs_processed: int, total_items: int, ...running totals }
     */
    public function run_auto_fill_batch( int $batch_size = self::AUTOFILL_DEFAULT_BATCH_SIZE ): array {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $queue    = get_option( self::AUTOFILL_QUEUE_OPTION, null );
        $progress = get_option( self::AUTOFILL_PROGRESS_OPTION, null );

        // Defensive: if state is missing (e.g. batch called without a
        // prior start_auto_fill()), initialise fresh rather than
        // fatal-erroring — same defensive pattern as the scanner's run_batch().
        if ( $queue === null || $progress === null ) {
            $this->start_auto_fill();
            $queue    = get_option( self::AUTOFILL_QUEUE_OPTION, [] );
            $progress = get_option( self::AUTOFILL_PROGRESS_OPTION );
        }

        $batch = array_splice( $queue, 0, max( 1, $batch_size ) );

        foreach ( $batch as $pair ) {
            $progress['pairs_processed']++;
            $generated_title = $this->generate_auto_title( $pair['button_text'], $pair['link_url'] );
            if ( empty( $generated_title ) ) {
                continue;
            }

            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$res_table}
                 WHERE button_text = %s AND link_url = %s AND has_title = 0",
                $pair['button_text'],
                $pair['link_url']
            ) );

            foreach ( $post_ids as $post_id ) {
                $post = (int) $post_id === 0
                    // Site chrome is recorded against post_id 0 because it
                    // belongs to no single page. get_post(0) is null, so
                    // without a stand-in every header, footer and navigation
                    // link was silently skipped here — which is exactly why
                    // Auto-Fill left the footer untouched. The stand-in exists
                    // only to carry a post_type into rewrite_missing_title(),
                    // which uses it to search the theme's template parts.
                    ? new WP_Post( (object) [
                        'ID'           => 0,
                        'post_type'    => 'site_chrome',
                        'post_title'   => __( 'Site header & footer', 'button-link-scanner' ),
                        'post_status'  => 'publish',
                        'post_content' => '',
                        'post_excerpt' => '',
                    ] )
                    : get_post( (int) $post_id );

                if ( ! $post ) {
                    continue;
                }

                $result = $this->rewrite_missing_title( $post, $pair['button_text'], $pair['link_url'], $generated_title );
                if ( $result['applied'] ) {
                    // Update the results table to match, right now — the
                    // Dashboard/Results pages read from this table, not
                    // from post_content directly, so without this the
                    // fix would be invisible anywhere in the plugin's UI
                    // until a full "Run Full Scan Now" was done again.
                    // Bounded to $result['count'] rows, NOT every row
                    // matching this post+text+link — see mark_titles_applied().
                    $this->mark_titles_applied( $res_table, (int) $post_id, $pair['button_text'], $pair['link_url'], $generated_title, $result['count'] );

                    $this->log_change( $progress, $post, $pair, $generated_title, $result['count'], 'content' );

                    // Unique pages, not (pair, page) applications — one page
                    // with several distinct buttons was previously counted
                    // once per button, so "Pages updated" could exceed the
                    // number of pages on the site.
                    $progress['updated_post_ids'][ (int) $post_id ] = true;
                    $progress['titles_added'] += $result['count'];
                } elseif ( ! empty( $result['already_titled'] ) ) {
                    // Found it, and it already has a title — nothing to do.
                    // Sync the stale results row so it stops being reported
                    // as missing on every subsequent run.
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$res_table} SET has_title = 1, title_text = %s
                         WHERE post_id = %d AND button_text = %s AND link_url = %s AND has_title = 0",
                        $result['existing_title'] !== '' ? $result['existing_title'] : $generated_title,
                        (int) $post_id,
                        $pair['button_text'],
                        $pair['link_url']
                    ) );
                    $progress['already_titled']++;
                } elseif ( ! empty( $result['render_only'] ) ) {
                    // The anchor only exists AFTER shortcode/widget
                    // rendering — nowhere in raw storage to persist a
                    // fix to (see rewrite_missing_title()). Queue it for
                    // BLS_Render_Injector instead, which adds the same
                    // title live on every page load. Mark has_title=1 so
                    // the rest of the plugin's UI reflects reality: from
                    // a visitor's/SEO's perspective the title now exists
                    // on the page, even though nothing changed in the DB.
                    BLS_Render_Injector::queue( (int) $post_id, $pair['button_text'], $pair['link_url'], $generated_title );
                    $this->mark_titles_applied( $res_table, (int) $post_id, $pair['button_text'], $pair['link_url'], $generated_title, $result['count'] );
                    $this->log_change( $progress, $post, $pair, $generated_title, $result['count'], 'render' );
                    $progress['titles_injected']++;
                } else {
                    $progress['could_not_apply']++;

                    // A candidate found in a writable raw source (post
                    // content, WC excerpt, block template, custom field)
                    // but blocked by an href/title mismatch is a real,
                    // fixable data issue. Nothing found anywhere, even
                    // after checking fully rendered content above, means
                    // there's genuinely no anchor to attach a title to.
                    if ( empty( $result['candidates'] ) ) {
                        $progress['not_in_database']++;
                    } else {
                        $progress['blocked_by_mismatch']++;
                    }

                    // Diagnose exactly why, from the FIRST failure only —
                    // a plain substring search (no DOM parsing) reveals
                    // whether the button's text/URL literally exist
                    // anywhere in this post's raw stored content.
                    if ( $progress['diagnostic'] === null ) {
                        $progress['diagnostic'] = [
                            'post_id'         => (int) $post_id,
                            'post_title'      => $post->post_title,
                            'button_text'     => $pair['button_text'],
                            'link_url'        => $pair['link_url'],
                            'text_in_raw'     => str_contains( $post->post_content, $pair['button_text'] ),
                            'href_in_raw'     => str_contains( $post->post_content, $pair['link_url'] ),
                            'content_snippet' => substr( $post->post_content, 0, 300 ),
                            'candidates'      => $result['candidates'] ?? [],
                        ];
                    }
                }
            }
        }

        $done = empty( $queue );

        // Resolve the unique-page set into the reported count.
        $progress['posts_updated'] = count( (array) ( $progress['updated_post_ids'] ?? [] ) );

        if ( $done ) {
            update_option( 'bls_last_auto_fill_result', array_merge( $progress, [ 'time' => current_time( 'mysql' ) ] ), false );
            delete_option( self::AUTOFILL_QUEUE_OPTION );
            delete_option( self::AUTOFILL_PROGRESS_OPTION );
        } else {
            update_option( self::AUTOFILL_QUEUE_OPTION, $queue, false );
            update_option( self::AUTOFILL_PROGRESS_OPTION, $progress, false );
        }

        return array_merge( $progress, [ 'done' => $done ] );
    }

    /**
     * Mark up to $limit result-table rows for this post+text+link as
     * having a title now — bounded to exactly how many anchors were
     * actually confirmed changed, NOT every row that happens to share
     * this post+text+link.
     *
     * Why this matters: the same visible text + href can legitimately
     * exist as more than one DISTINCT row for a single post — e.g. an
     * identical "Schedule a private tour" CTA repeated across several
     * property cards/widgets on one page, each recorded separately by
     * the scanner because their surrounding markup differs (dedup is by
     * exact outer HTML, not by text+href). A plain
     * `WHERE post_id=X AND button_text=Y AND link_url=Z AND has_title=0`
     * update with no LIMIT would flip ALL of those rows to has_title=1
     * the moment just ONE of their underlying anchors got fixed — even
     * ones whose actual on-page anchor was never touched, since the fix
     * only ever writes to ONE content source at a time (see
     * rewrite_missing_title(), which returns as soon as the first source
     * succeeds). $wpdb->update() has no LIMIT support at all, so this
     * uses a raw prepared query instead specifically to add one.
     *
     * This doesn't guarantee it flips the exact SAME rows whose anchors
     * were modified (MySQL doesn't guarantee which rows a LIMIT without
     * ORDER BY picks) — but it guarantees the COUNT never exceeds what
     * was actually confirmed fixed, which is what actually matters: the
     * next full site scan rebuilds this table from the live page anyway,
     * so any remaining row-identity ambiguity self-corrects there.
     */
    /**
     * Create the CSV file for a run's complete log and write its header row.
     * See LOG_SUBDIR for why this exists alongside the capped on-page table.
     *
     * Returns [] if the file can't be created (unwritable uploads dir, for
     * instance). Every caller treats that as "no download link" and carries
     * on — a logging problem must never stop the actual work.
     *
     * @return array{path?:string, url?:string}
     */
    private function create_log_file( string $prefix, array $headers ): array {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            return [];
        }

        $dir = trailingslashit( $uploads['basedir'] ) . self::LOG_SUBDIR;
        if ( ! wp_mkdir_p( $dir ) ) {
            return [];
        }

        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        $name = $prefix . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 16, false, false ) . '.csv';
        $path = trailingslashit( $dir ) . $name;

        $handle = fopen( $path, 'w' );
        if ( ! $handle ) {
            return [];
        }
        fputcsv( $handle, $headers );
        fclose( $handle );

        return [
            'path' => $path,
            'url'  => trailingslashit( $uploads['baseurl'] ) . self::LOG_SUBDIR . '/' . $name,
        ];
    }

    /**
     * Append one row to a run's CSV log. Opened and closed per row so an
     * interrupted run (see the auto-resume handling) leaves a valid, complete
     * file for everything processed up to that point rather than a truncated
     * one.
     */
    private function append_log_row( string $path, array $row ): void {
        if ( $path === '' || ! file_exists( $path ) ) {
            return;
        }
        $handle = fopen( $path, 'a' );
        if ( ! $handle ) {
            return;
        }
        fputcsv( $handle, $row );
        fclose( $handle );
    }

    /**
     * Record one applied change in the run's audit log, so the dashboard
     * can show exactly WHICH buttons were changed and what title each one
     * received — not just how many. A count alone gives no way to spot a
     * bad generated title, or to verify a run did what it claims.
     *
     * $method distinguishes 'content' (written into stored content, will
     * persist as-is) from 'render' (applied live by BLS_Render_Injector on
     * each page load, nothing changed in the database) — a meaningful
     * difference when auditing or undoing.
     */
    private function log_change( array &$progress, WP_Post $post, array $pair, string $title, int $count, string $method ): void {
        // CSV gets every row, regardless of the on-page cap below.
        $this->append_log_row( (string) ( $progress['log_path'] ?? '' ), [
            $post->post_title,
            $post->ID,
            get_permalink( $post->ID ),
            $pair['button_text'],
            $pair['link_url'],
            $title,
            $count,
            $method === 'render' ? 'live at render time' : 'saved to content',
        ] );

        if ( count( $progress['changes'] ) >= self::AUDIT_LOG_LIMIT ) {
            $progress['changes_truncated']++;
            return;
        }

        $progress['changes'][] = [
            'post_id'     => $post->ID,
            'post_title'  => $post->post_title,
            'post_url'    => get_permalink( $post->ID ),
            'button_text' => $pair['button_text'],
            'link_url'    => $pair['link_url'],
            'new_title'   => $title,
            'count'       => $count,
            'method'      => $method,
        ];
    }

    private function mark_titles_applied( string $res_table, int $post_id, string $button_text, string $link_url, string $title, int $limit ): void {
        global $wpdb;
        if ( $limit < 1 ) {
            return;
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$res_table} SET has_title = 1, title_text = %s
             WHERE post_id = %d AND button_text = %s AND link_url = %s AND has_title = 0
             LIMIT %d",
            $title,
            $post_id,
            $button_text,
            $link_url,
            $limit
        ) );
    }

    /**
     * The generated title is simply the button/link's own visible text.
     *
     * Earlier versions appended destination context — "Button Text –
     * Destination Page Title", falling back to the domain for external
     * links. In practice that mostly produced redundancy, because a CTA's
     * text usually already names where it goes ("Mortgage Info" linking to
     * the Mortgage Info page became "Mortgage Info – Mortgage Info"), and
     * a title that restates the link text with a dash and a repeat adds
     * nothing for a visitor or a search engine. Copying the text verbatim
     * is what's actually wanted.
     *
     * $link_url is intentionally still accepted: callers pass a
     * (text, url) pair throughout, and keeping the signature stable means
     * destination-aware behavior can return later without rewiring
     * everything.
     */
    private function generate_auto_title( string $button_text, string $link_url ): string {
        return trim( $button_text );
    }

    /**
     * Normalize button text for matching between what the scanner saw
     * (rendered) and what's actually in raw storage. Trailing decoration
     * on CTA-style buttons ("Learn More →", "Schedule Tour »") is a
     * common source of exact-match failure — and critically, that
     * "arrow" isn't reliably the same Unicode character every time. Icon
     * fonts (FontAwesome-style) commonly use Private-Use-Area codepoints
     * that render VISUALLY identical to a normal arrow but aren't the
     * same character at all, so a whitelist of "known arrow characters"
     * can silently fail to catch it. Instead of guessing every possible
     * decorative symbol, this strips ALL non-alphanumeric characters
     * from both sides — arrows, icon glyphs, smart quotes, dashes,
     * bullets, whatever — leaving only the actual words to compare.
     */
    private function normalize_button_text( string $text ): string {
        $text = trim( $text );

        // Collapse any run of non-letter/non-number characters (arrows,
        // icon glyphs, quotes, dashes, bullets, AND any whitespace
        // variant — including non-breaking spaces, which are extremely
        // common in real-world WordPress content from copy-pasted text
        // and which a plain \s pattern doesn't reliably catch) into a
        // single space. One Unicode-aware pass handles both "strip
        // decoration" and "normalize whitespace" at once, rather than
        // two separate steps that could each miss different edge cases.
        $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

        return strtolower( trim( $text ) );
    }

    /**
     * Compare two hrefs by destination rather than by exact string.
     *
     * The scanner records the href it saw in RENDERED output, while
     * Auto-Fill matches against hrefs in RAW stored content — and the two
     * routinely differ in ways that mean nothing about where the link
     * actually goes: a relative path in the editor rendered as absolute,
     * http vs https, www vs bare host, a trailing slash added or dropped,
     * HTML entities decoded, or a mailto: address differing only in case.
     * An exact string comparison rejected all of those, so a link whose
     * text matched perfectly and which was sitting right there in the
     * content still came back as "blocked by an href/title mismatch" and
     * never got its title.
     *
     * Safe to be lenient here because href is only ever the SECOND test —
     * the anchor's normalized text must already match, so this is
     * confirming "same destination", not searching for a link.
     */
    private function hrefs_match( string $a, string $b ): bool {
        $na = $this->normalize_href( $a );
        return $na !== '' && $na === $this->normalize_href( $b );
    }

    /**
     * Reduce an href to a comparable form: scheme-and-formatting noise
     * removed, relative paths resolved against this site.
     */
    private function normalize_href( string $href ): string {
        $href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );

        // Percent-decode, then collapse whitespace. The scanner reads hrefs
        // from RENDERED output, where the browser/WordPress has already
        // percent-encoded anything invalid, while Auto-Fill searches RAW
        // stored content, which keeps whatever was actually typed. A URL
        // containing spaces therefore arrives as "%20" on one side and a
        // literal space on the other and never matches.
        //
        // Real example that exposed this: a link whose href is
        // "http://TEA Accountability for Bryan/College Station Schools" —
        // descriptive text pasted into the URL field, which the editor then
        // prefixed with http:// and encoded. The link is broken either way,
        // but it should not be reported as a matching failure of this plugin.
        $decoded = rawurldecode( $href );
        if ( $decoded !== '' ) {
            $href = $decoded;
        }
        $href = trim( preg_replace( '/\s+/u', ' ', $href ) );
        if ( $href === '' ) {
            return '';
        }

        // Non-web schemes: mailto:/tel:/sms:. Case is meaningless in an
        // email address for this purpose, and phone numbers get written
        // with arbitrary punctuation ("+1-702-580-6101" vs "17025806101"),
        // so reduce those to digits.
        if ( preg_match( '#^(mailto|tel|sms|callto):#i', $href, $m ) ) {
            $scheme = strtolower( $m[1] );
            $value  = strtolower( substr( $href, strlen( $m[0] ) ) );
            if ( $scheme !== 'mailto' ) {
                // Digits only — "+1-702-580-6101" and "17025806101" are the
                // same number written two ways. A missing country code is
                // left as a real difference rather than guessed at.
                $value = preg_replace( '/[^0-9]/', '', $value );
            }
            return $scheme . ':' . $value;
        }

        // In-page anchors have no destination to resolve.
        if ( str_starts_with( $href, '#' ) ) {
            return strtolower( $href );
        }

        if ( str_starts_with( $href, '//' ) ) {
            $href = 'https:' . $href;
        }
        if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $href ) ) {
            // Relative — resolve against this site, the same assumption the
            // scanner and link checker already make.
            $href = home_url( '/' . ltrim( $href, '/' ) );
        }

        $parts = wp_parse_url( $href );
        if ( empty( $parts['host'] ) ) {
            return strtolower( $href );
        }

        // Scheme is deliberately dropped: http and https on the same
        // host+path are the same destination as far as identifying an
        // anchor goes.
        $host     = strtolower( preg_replace( '/^www\./i', '', $parts['host'] ) );
        $path     = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
        $query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
        $fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

        return $host . ( $path === '' ? '/' : $path ) . $query . $fragment;
    }

    /**
     * Core DOM logic, decoupled from any particular content source: given
     * a raw HTML string, add a title attribute to every matching button
     * that's missing one. Reused across every content source below —
     * raw post_content, WooCommerce excerpts, Divi layouts, block-theme
     * templates, and custom-field values all get the exact same matching
     * logic, just applied to a different string and written back to a
     * different place.
     *
     * @return array { html: string, changed: bool, count: int }
     */
    private function apply_title_to_html( string $html, string $button_text, string $link_url, string $title ): array {
        $normalized_text = $this->normalize_button_text( $button_text );
        $target_link     = trim( $link_url );
        $changed         = false;
        $count           = 0;
        $candidates      = []; // Diagnostic only — every anchor examined and why it didn't match.

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        // Anchors — title lives directly on the element.
        foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $anchor ) {
            $text        = $this->normalize_button_text( $anchor->textContent );
            $anchor_href = trim( $anchor->getAttribute( 'href' ) );

            if ( $text === $normalized_text ) {
                // Text matches — record exactly why it does or doesn't
                // proceed, so a href/title mismatch is visible too, not
                // just a blanket "no match found".
                $candidates[] = [
                    'text_matched' => true,
                    'href'         => $anchor_href,
                    'href_matched' => $this->hrefs_match( $anchor_href, $target_link ),
                    'has_title'    => trim( $anchor->getAttribute( 'title' ) ) !== '',
                    'existing_title' => trim( $anchor->getAttribute( 'title' ) ),
                ];
            }

            if ( $text !== $normalized_text ) {
                continue;
            }
            if ( ! $this->hrefs_match( $anchor_href, $target_link ) ) {
                continue;
            }
            if ( trim( $anchor->getAttribute( 'title' ) ) !== '' ) {
                continue; // Already has one — never overwrite an existing title.
            }

            $anchor->setAttribute( 'title', sanitize_text_field( $title ) );
            $changed = true;
            $count++;
        }

        // Input/button elements whose real link is the enclosing form's
        // action attribute (PayPal-style donate buttons and similar).
        foreach ( [ 'input', 'button' ] as $tag ) {
            foreach ( iterator_to_array( $dom->getElementsByTagName( $tag ) ) as $node ) {
                if ( trim( $node->getAttribute( 'title' ) ) !== '' ) {
                    continue;
                }

                $label = $tag === 'input'
                    ? trim( $node->getAttribute( 'value' ) )
                    : trim( $node->textContent );
                if ( $this->normalize_button_text( $label ) !== $normalized_text ) {
                    continue;
                }

                $form = $node->parentNode;
                while ( $form && ! ( $form instanceof DOMElement && strtolower( $form->nodeName ) === 'form' ) ) {
                    $form = $form->parentNode;
                }
                if ( ! $form || ! $this->hrefs_match( $form->getAttribute( 'action' ), $target_link ) ) {
                    continue;
                }

                $node->setAttribute( 'title', sanitize_text_field( $title ) );
                $changed = true;
                $count++;
            }
        }

        if ( ! $changed ) {
            return [ 'html' => $html, 'changed' => false, 'count' => 0, 'candidates' => $candidates ];
        }

        $new_html = $dom->saveHTML();
        $new_html = $this->strip_dom_wrapper( $new_html, $html );

        return [ 'html' => $new_html, 'changed' => true, 'count' => $count, 'candidates' => $candidates ];
    }

    /**
     * Try every content source this plugin's scanner reads from, in the
     * same order, until one actually contains the matching button — then
     * write the fix back to THAT source specifically. This is the piece
     * that was missing before: the scanner already knows how to find
     * buttons living in WooCommerce excerpts, Divi Theme Builder layouts,
     * block-theme templates, and custom fields (not just a post's own
     * raw content), but Auto-Fill only ever wrote back to post_content —
     * so anything found via those other sources was silently un-fixable.
     *
     * @return array { applied: bool, count: int }
     */
    private function rewrite_missing_title( WP_Post $post, string $button_text, string $link_url, string $title ): array {
        $all_candidates = [];

        // 1. Raw post_content — the common case (Gutenberg/classic
        // editor content, and Divi's own shortcodes, which live directly
        // in post_content).
        $result = $this->apply_title_to_html( $post->post_content, $button_text, $link_url, $title );
        $all_candidates = array_merge( $all_candidates, $result['candidates'] );
        if ( $result['changed'] ) {
            wp_update_post( [ 'ID' => $post->ID, 'post_content' => $result['html'] ] );
            return [ 'applied' => true, 'count' => $result['count'], 'candidates' => $all_candidates ];
        }

        // 2. WooCommerce short description (product excerpt).
        if ( $post->post_type === 'product' && ! empty( trim( (string) $post->post_excerpt ) ) ) {
            $result = $this->apply_title_to_html( $post->post_excerpt, $button_text, $link_url, $title );
            $all_candidates = array_merge( $all_candidates, $result['candidates'] );
            if ( $result['changed'] ) {
                wp_update_post( [ 'ID' => $post->ID, 'post_excerpt' => $result['html'] ] );
                return [ 'applied' => true, 'count' => $result['count'], 'candidates' => $all_candidates ];
            }
        }

        // 2b. Block-theme header/footer template parts.
        //
        // Where a block theme keeps its chrome: wp_template_part posts, one per
        // region. They are ordinary editable posts, which is what makes titles
        // on footer and navigation links possible at all — the scanner reads
        // those links from the rendered page, so there is no anchor in any
        // page's own content to write to, and before this the only outcome was
        // "could not be applied".
        //
        // Tried before the page template because a link found in the chrome
        // belongs to a part, and searching every page template first would
        // waste passes on markup that cannot contain it.
        if ( $post->post_type === 'site_chrome' || (int) $post->ID === 0 ) {
            $parts = get_posts( [
                'post_type'      => 'wp_template_part',
                'post_status'    => 'any',
                'posts_per_page' => -1,
            ] );

            foreach ( (array) $parts as $part ) {
                if ( trim( (string) $part->post_content ) === '' ) {
                    continue;
                }
                $result         = $this->apply_title_to_html( $part->post_content, $button_text, $link_url, $title );
                $all_candidates = array_merge( $all_candidates, $result['candidates'] );
                if ( $result['changed'] ) {
                    wp_update_post( [ 'ID' => $part->ID, 'post_content' => $result['html'] ] );
                    return [ 'applied' => true, 'count' => $result['count'], 'candidates' => $all_candidates ];
                }
            }

            // Nothing stored to write to, which is the normal case rather than
            // the exception. A block theme keeps its social links and
            // navigation in post_content as block comments — the anchors only
            // exist once those dynamic blocks render — and a classic theme
            // keeps its footer in PHP files. Either way there is no <a> in the
            // database to attach a title to.
            //
            // The scanner read these links off the rendered page, so they
            // certainly exist there. Hand them to the render injector, which
            // now filters block output as well as the_content, and the title
            // appears on every page the chrome is on.
            return [
                'applied'     => false,
                'render_only' => true,
                // One, not zero: mark_titles_applied() passes this straight
                // into a LIMIT, so a count of zero would update no rows and the
                // link would keep reporting as untitled after every run. One
                // row per distinct anchor, which is what the chrome scan
                // records after collapsing duplicate markup.
                'count'       => 1,
                'candidates'  => $all_candidates,
            ];
        }

        // 3. Block-theme (FSE) custom page template — a separate
        // `wp_template` post.
        $template_slug = get_page_template_slug( $post->ID );
        if ( ! empty( $template_slug ) ) {
            $slug = preg_replace( '/\.html$/', '', basename( $template_slug ) );
            if ( ! empty( $slug ) && $slug !== 'default' ) {
                $template_post = get_page_by_path( $slug, OBJECT, 'wp_template' );
                if ( $template_post && ! empty( trim( $template_post->post_content ) ) ) {
                    $result = $this->apply_title_to_html( $template_post->post_content, $button_text, $link_url, $title );
                    $all_candidates = array_merge( $all_candidates, $result['candidates'] );
                    if ( $result['changed'] ) {
                        wp_update_post( [ 'ID' => $template_post->ID, 'post_content' => $result['html'] ] );
                        return [ 'applied' => true, 'count' => $result['count'], 'candidates' => $all_candidates ];
                    }
                }
            }
        }

        // 4. Custom fields (ACF, etc.) — the value itself holds the
        // markup, so the fix has to be written back to that SAME meta
        // key via update_post_meta(), not post_content.
        $all_meta = get_post_meta( $post->ID );
        foreach ( (array) $all_meta as $key => $values ) {
            if ( strpos( $key, '_' ) === 0 ) {
                continue; // WP/plugin-internal meta, not meant to be rendered directly.
            }
            foreach ( (array) $values as $index => $value ) {
                if ( ! is_string( $value ) || $value === '' ) {
                    continue;
                }
                if ( ! preg_match( '/<a\s|<button|<input/i', $value ) ) {
                    continue; // Doesn't look like markup — skip without touching it.
                }
                $result = $this->apply_title_to_html( $value, $button_text, $link_url, $title );
                $all_candidates = array_merge( $all_candidates, $result['candidates'] );
                if ( $result['changed'] ) {
                    update_post_meta( $post->ID, $key, $result['html'], $value );
                    return [ 'applied' => true, 'count' => $result['count'], 'candidates' => $all_candidates ];
                }
            }
        }

        // 5. Fully rendered content — the SAME pipeline the scanner used
        // to find this button in the first place (apply_filters(
        // 'the_content', ...)). Sources 1-4 above all search RAW,
        // unrendered storage; a button whose markup is produced by a
        // shortcode or widget's own PHP (its href passed in as a
        // shortcode attribute, its label built in code — an IDX plugin's
        // listing template, for example) never appears as a literal <a>
        // tag in any of them, no matter how well text is normalized,
        // simply because it doesn't exist there yet. It DOES exist here.
        // There's still nowhere in the DB to persist this one — writing
        // rendered output back into post_content would freeze today's
        // shortcode result in place and break any future update the
        // shortcode itself makes — so this is reported back as
        // 'render_only' for the caller to queue with BLS_Render_Injector
        // instead, which adds the same title live on every page load.
        $rendered = (string) apply_filters( 'the_content', $post->post_content );
        if ( trim( $rendered ) !== '' && trim( $rendered ) !== trim( $post->post_content ) ) {
            $result = $this->apply_title_to_html( $rendered, $button_text, $link_url, $title );
            $all_candidates = array_merge( $all_candidates, $result['candidates'] );
            if ( $result['changed'] ) {
                return [ 'applied' => false, 'render_only' => true, 'count' => $result['count'], 'candidates' => $all_candidates ];
            }
        }

        // 6. Rendered custom fields — the same rendering gap as #5, but for
        // the custom-field fallback source (#4) instead of post_content.
        // Classic themes like Genesis commonly build a page's real content
        // from a custom field containing a shortcode (an IDX shortcode
        // dropped into a "page content" meta field via a page builder),
        // so the RAW value has no <a> tag for #4's markup pre-check to
        // even notice — same underlying issue #5 exists for, just one
        // field over. Checked separately from #5 because the scanner
        // itself only reaches for custom fields at all when post_content
        // rendered to nothing (see BLS_Scanner::get_post_content()), so a
        // button living here wouldn't already have been caught above.
        foreach ( (array) $all_meta as $key => $values ) {
            if ( strpos( $key, '_' ) === 0 ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                if ( ! is_string( $value ) || $value === '' ) {
                    continue;
                }
                // Cheap pre-check: does this look like it MIGHT contain a
                // shortcode or markup worth rendering? Avoids running
                // apply_filters('the_content', ...) — not free — against
                // every unrelated postmeta value on the post.
                if ( ! preg_match( '/<a\s|<button|<input|\[[a-z0-9_-]+/i', $value ) ) {
                    continue;
                }
                $rendered_meta = (string) apply_filters( 'the_content', $value );
                if ( trim( $rendered_meta ) === '' || trim( $rendered_meta ) === trim( $value ) ) {
                    continue; // Nothing expanded — #4 already covered this value as-is.
                }
                $result = $this->apply_title_to_html( $rendered_meta, $button_text, $link_url, $title );
                $all_candidates = array_merge( $all_candidates, $result['candidates'] );
                if ( $result['changed'] ) {
                    return [ 'applied' => false, 'render_only' => true, 'count' => $result['count'], 'candidates' => $all_candidates ];
                }
            }
        }

        // Before reporting failure: check whether the anchor was found and
        // simply already HAS a title. That isn't a failure at all — it's
        // "nothing to do" — but it looked identical to one, because the
        // only signal was "no title was written". The results row saying
        // has_title = 0 is then just stale relative to the live content,
        // and re-reporting it every run makes a finished button look
        // permanently broken. Distinguished here so the caller can sync
        // the row and count it as already-done.
        $matched_href     = false;
        $needs_title      = false;
        $existing_title   = '';
        foreach ( $all_candidates as $candidate ) {
            if ( empty( $candidate['href_matched'] ) ) {
                continue;
            }
            $matched_href = true;
            if ( empty( $candidate['has_title'] ) ) {
                $needs_title = true; // A real blocker exists — not "already done".
                break;
            }
            if ( $existing_title === '' ) {
                $existing_title = (string) ( $candidate['existing_title'] ?? '' );
            }
        }

        if ( $matched_href && ! $needs_title ) {
            return [
                'applied'        => false,
                'already_titled' => true,
                'existing_title' => $existing_title,
                'count'          => 0,
                'candidates'     => $all_candidates,
            ];
        }

        // None of the writable sources contained a literal match, even
        // after rendering. This means the button was found via a
        // live-only fetch (the homepage-only check, or the bounded
        // "needs manual check" recheck) — content hardcoded directly
        // into a theme template file, with no post at all behind it.
        // That's a genuine, honest limit: there's no reachable place to
        // save a fix, live or persisted.
        return [ 'applied' => false, 'count' => 0, 'candidates' => $all_candidates ];
    }

    // -------------------------------------------------------------------------
    // Remove Auto-Filled Titles (undo)
    // -------------------------------------------------------------------------

    /**
     * Build the work queue for a title wipe: every post the scanner has
     * recorded a button/link on.
     *
     * Deliberately NOT driven by the last run's audit log. That log only
     * covers one run, and titles on a site like this were applied across
     * several runs of several plugin versions — an undo that only reaches
     * the most recent run would leave most of them in place. Working from
     * the results table instead means this wipes titles applied by ANY
     * earlier run, including ones from versions that predate the audit log
     * entirely, which is exactly what's needed for a site already filled
     * in before this feature existed.
     *
     * @return array { total_items: int }
     */
    public function start_title_wipe(): array {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $post_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$res_table} WHERE post_id > 0 ORDER BY post_id ASC"
        );

        $queue = array_map( 'intval', (array) $post_ids );

        $log = $this->create_log_file( 'title-removal', [
            'Page', 'Page ID', 'Page URL', 'Button/link text', 'Title removed', 'Removed from',
        ] );

        update_option( self::WIPE_QUEUE_OPTION, $queue, false );
        update_option( self::WIPE_PROGRESS_OPTION, [
            'total_items'       => count( $queue ),
            'posts_processed'   => 0,
            'posts_changed'     => 0,
            'titles_removed'    => 0,
            'injections_cleared' => 0,
            'changes'           => [],
            'changes_truncated' => 0,
            'log_path'          => $log['path'] ?? '',
            'log_url'           => $log['url'] ?? '',
        ], false );

        return [ 'total_items' => count( $queue ) ];
    }

    /**
     * Process the next batch of posts, removing auto-generated titles.
     * Batched for the same reason the scan and Auto-Fill are — see
     * run_auto_fill_batch().
     *
     * @param  int $batch_size How many posts to process this call.
     * @return array { done: bool, ...running totals }
     */
    public function run_title_wipe_batch( int $batch_size = self::WIPE_DEFAULT_BATCH_SIZE ): array {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $queue    = get_option( self::WIPE_QUEUE_OPTION, null );
        $progress = get_option( self::WIPE_PROGRESS_OPTION, null );

        if ( $queue === null || $progress === null ) {
            $this->start_title_wipe();
            $queue    = get_option( self::WIPE_QUEUE_OPTION, [] );
            $progress = get_option( self::WIPE_PROGRESS_OPTION );
        }

        $batch = array_splice( $queue, 0, max( 1, $batch_size ) );

        foreach ( $batch as $post_id ) {
            $progress['posts_processed']++;
            $post = get_post( (int) $post_id );
            if ( ! $post ) {
                continue;
            }

            $removed = $this->remove_titles_for_post( $post, $progress );
            if ( $removed > 0 ) {
                $progress['posts_changed']++;
                $progress['titles_removed'] += $removed;

                // Re-flag the affected rows as missing a title so the rest
                // of the plugin's UI matches reality immediately, rather
                // than waiting on the next full scan. Row-by-row against
                // the same auto-generated test used on the content itself,
                // keyed by each row's unique id: a blanket
                // "has_title = 0 WHERE post_id = X" would also clear rows
                // whose human-written titles were deliberately KEPT,
                // reporting them as missing when they're still there.
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, button_text, title_text FROM {$res_table}
                     WHERE post_id = %d AND has_title = 1",
                    (int) $post->ID
                ) );
                foreach ( (array) $rows as $row ) {
                    if ( ! $this->looks_auto_generated( (string) $row->title_text, (string) $row->button_text ) ) {
                        continue;
                    }
                    $wpdb->update(
                        $res_table,
                        [ 'has_title' => 0, 'title_text' => '' ],
                        [ 'id' => (int) $row->id ]
                    );
                }
            }
        }

        $done = empty( $queue );

        if ( $done ) {
            // Also drop every queued render-time injection. Those titles
            // were never written to content — they're re-applied on each
            // page load from this option — so clearing it is what actually
            // removes them from the live site.
            $injections = get_option( BLS_Render_Injector::OPTION, [] );
            if ( is_array( $injections ) ) {
                foreach ( $injections as $rules ) {
                    $progress['injections_cleared'] += count( (array) $rules );
                }
            }
            delete_option( BLS_Render_Injector::OPTION );

            update_option( 'bls_last_wipe_result', array_merge( $progress, [ 'time' => current_time( 'mysql' ) ] ), false );
            delete_option( self::WIPE_QUEUE_OPTION );
            delete_option( self::WIPE_PROGRESS_OPTION );
        } else {
            update_option( self::WIPE_QUEUE_OPTION, $queue, false );
            update_option( self::WIPE_PROGRESS_OPTION, $progress, false );
        }

        return array_merge( $progress, [ 'done' => $done ] );
    }

    /**
     * Strip auto-generated titles from every writable source for one post,
     * mirroring the same four sources Auto-Fill writes to.
     *
     * @return int Number of title attributes removed.
     */
    private function remove_titles_for_post( WP_Post $post, array &$progress ): int {
        $removed = 0;

        $result = $this->remove_titles_from_html( $post->post_content );
        if ( $result['changed'] ) {
            wp_update_post( [ 'ID' => $post->ID, 'post_content' => $result['html'] ] );
            $removed += $result['count'];
            $this->log_wipe( $progress, $post, $result['removed'], 'content' );
        }

        if ( ! empty( trim( (string) $post->post_excerpt ) ) ) {
            $result = $this->remove_titles_from_html( $post->post_excerpt );
            if ( $result['changed'] ) {
                wp_update_post( [ 'ID' => $post->ID, 'post_excerpt' => $result['html'] ] );
                $removed += $result['count'];
                $this->log_wipe( $progress, $post, $result['removed'], 'excerpt' );
            }
        }

        $template_slug = get_page_template_slug( $post->ID );
        if ( ! empty( $template_slug ) ) {
            $slug = preg_replace( '/\.html$/', '', basename( $template_slug ) );
            if ( ! empty( $slug ) && $slug !== 'default' ) {
                $template_post = get_page_by_path( $slug, OBJECT, 'wp_template' );
                if ( $template_post && ! empty( trim( $template_post->post_content ) ) ) {
                    $result = $this->remove_titles_from_html( $template_post->post_content );
                    if ( $result['changed'] ) {
                        wp_update_post( [ 'ID' => $template_post->ID, 'post_content' => $result['html'] ] );
                        $removed += $result['count'];
                        $this->log_wipe( $progress, $post, $result['removed'], 'template' );
                    }
                }
            }
        }

        foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
            if ( strpos( $key, '_' ) === 0 ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                if ( ! is_string( $value ) || $value === '' ) {
                    continue;
                }
                if ( ! preg_match( '/<a\s|<button|<input/i', $value ) ) {
                    continue;
                }
                $result = $this->remove_titles_from_html( $value );
                if ( $result['changed'] ) {
                    update_post_meta( $post->ID, $key, $result['html'], $value );
                    $removed += $result['count'];
                    $this->log_wipe( $progress, $post, $result['removed'], 'custom field: ' . $key );
                }
            }
        }

        return $removed;
    }

    /**
     * Remove title attributes that this plugin generated, from one HTML
     * string. Titles a human wrote are left alone.
     *
     * The test is the anchor's own text, not a stored ledger — which is
     * what lets this undo titles applied by earlier plugin versions that
     * kept no record. A title is treated as auto-generated when, after the
     * same Unicode normalization used for matching, it either:
     *
     *   - equals the element's visible text (the current format, which
     *     copies the text verbatim), or
     *   - begins with that text followed by more (the older
     *     "Button Text – Destination Page Title" format).
     *
     * Both mean "this title just restates the link text", which is
     * precisely what Auto-Fill produces and what a human writing a
     * genuinely descriptive title would not.
     *
     * @return array { html: string, changed: bool, count: int, removed: array }
     */
    private function remove_titles_from_html( string $html ): array {
        if ( trim( $html ) === '' || stripos( $html, 'title=' ) === false ) {
            return [ 'html' => $html, 'changed' => false, 'count' => 0, 'removed' => [] ];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $changed = false;
        $count   = 0;
        $removed = [];

        foreach ( [ 'a', 'button', 'input' ] as $tag ) {
            foreach ( iterator_to_array( $dom->getElementsByTagName( $tag ) ) as $node ) {
                $title = trim( $node->getAttribute( 'title' ) );
                if ( $title === '' ) {
                    continue;
                }

                $label = $tag === 'input'
                    ? trim( $node->getAttribute( 'value' ) )
                    : trim( $node->textContent );

                if ( ! $this->looks_auto_generated( $title, $label ) ) {
                    continue; // Human-written — leave it exactly as it is.
                }

                $node->removeAttribute( 'title' );
                $changed = true;
                $count++;
                $removed[] = [ 'text' => $label, 'title' => $title ];
            }
        }

        if ( ! $changed ) {
            return [ 'html' => $html, 'changed' => false, 'count' => 0, 'removed' => [] ];
        }

        $new_html = $this->strip_dom_wrapper( (string) $dom->saveHTML(), $html );

        return [ 'html' => $new_html, 'changed' => true, 'count' => $count, 'removed' => $removed ];
    }

    /**
     * True when a title merely restates the element's own visible text —
     * see remove_titles_from_html() for why that's the signal used.
     */
    private function looks_auto_generated( string $title, string $label ): bool {
        $t = $this->normalize_button_text( $title );
        $l = $this->normalize_button_text( $label );

        if ( $t === '' || $l === '' ) {
            return false;
        }
        if ( $t === $l ) {
            return true; // Current format: title is a verbatim copy of the text.
        }

        // Older format: "<text> – <destination>". Normalization has already
        // reduced the separator to a space, so this is a prefix test.
        return str_starts_with( $t, $l . ' ' );
    }

    // -------------------------------------------------------------------------
    // Bulk unlink — turn dead links back into plain text
    // -------------------------------------------------------------------------

    /**
     * Queue up a set of broken links to be unlinked.
     *
     * Why unlink rather than replace: on collegestationhomes.com the 366
     * broken links were 366 *distinct* URLs across 134 pages — not one
     * repeated twice. A find-and-replace tool fixes those one at a time,
     * which is no faster than editing the page by hand. But 110 of them are
     * domains that no longer resolve at all: the business closed, the site is
     * gone, and there is nothing to point at. For those the only correct fix
     * is to stop linking — keep the text, drop the anchor — and that *does*
     * bulk cleanly.
     *
     * @param  int[] $ids Row ids from the link-health table.
     * @return array { total_items: int }
     */
    public function start_unlink( array $ids ): array {
        $rows = BLS_Link_Checker::get_links_by_ids( $ids );

        $queue = [];
        foreach ( $rows as $row ) {
            $queue[] = [
                'url'         => (string) $row->link_url,
                'button_text' => (string) $row->button_text,
            ];
        }

        $log = $this->create_log_file( 'unlink', [
            'Link removed', 'Link text', 'Page', 'Page ID', 'Page URL', 'Removed from',
        ] );

        update_option( self::UNLINK_QUEUE_OPTION, $queue, false );
        update_option( self::UNLINK_PROGRESS_OPTION, [
            'total_items'       => count( $queue ),
            'processed'         => 0,
            'unlinked'          => 0,
            'pages_changed'     => 0,
            // Distinct page ids touched by the whole run. pages_changed used
            // to be a running sum of each URL's page count, so a page holding
            // five dead links was counted five times — a 58-page run reported
            // as 114.
            'pages_touched'     => [],
            'skipped_alive'     => 0,
            'skipped_not_found' => 0,
            'skipped_alive_urls' => [],
            'changes'           => [],
            'changes_truncated' => 0,
            'log_path'          => $log['path'] ?? '',
            'log_url'           => $log['url'] ?? '',
        ], false );

        return [ 'total_items' => count( $queue ) ];
    }

    /**
     * Process the next batch of queued URLs.
     *
     * @param  int $batch_size How many URLs to handle this call.
     * @return array { done: bool, ...running totals }
     */
    public function run_unlink_batch( int $batch_size = self::UNLINK_DEFAULT_BATCH_SIZE ): array {
        $queue    = get_option( self::UNLINK_QUEUE_OPTION, null );
        $progress = get_option( self::UNLINK_PROGRESS_OPTION, null );

        if ( $queue === null || $progress === null ) {
            return [ 'done' => true, 'error' => 'No unlink run in progress.' ];
        }

        $batch = array_splice( $queue, 0, max( 1, $batch_size ) );

        foreach ( $batch as $item ) {
            $progress['processed']++;
            $url = (string) $item['url'];

            // Re-confirm before editing anything. The health row could be
            // hours old, the destination could have come back up, and
            // stripping a working link is not something to do on a stale
            // reading. Only a fresh 'broken' authorises a change.
            $state = BLS_Link_Checker::verify_url_now( $url );
            if ( $state !== 'broken' ) {
                $progress['skipped_alive']++;
                if ( count( $progress['skipped_alive_urls'] ) < 50 ) {
                    $progress['skipped_alive_urls'][] = [ 'url' => $url, 'state' => $state ];
                }
                continue;
            }

            $result = $this->unlink_url_everywhere( $url, $progress );

            if ( $result['count'] < 1 ) {
                $progress['skipped_not_found']++;
                continue;
            }

            $progress['unlinked'] += $result['count'];

            foreach ( $result['post_ids'] as $touched_id ) {
                $progress['pages_touched'][ $touched_id ] = true;
            }
            $progress['pages_changed'] = count( $progress['pages_touched'] );

            // The anchors are gone from the pages that were actually rewritten,
            // so those scan rows no longer describe anything. Rows for pages
            // where the anchor could not be reached (a page builder's own
            // storage, a theme template) are deliberately left in place — the
            // link is still live there, and clearing them would hide it.
            $this->forget_link_rows( $url, $result['post_ids'] );

            // Only stop reporting the URL once nothing references it anymore.
            if ( ! $this->link_still_used( $url ) ) {
                BLS_Link_Checker::forget_link( $url );
            }
        }

        $done = empty( $queue );

        if ( $done ) {
            update_option( 'bls_last_unlink_result', array_merge( $progress, [ 'time' => current_time( 'mysql' ) ] ), false );
            delete_option( self::UNLINK_QUEUE_OPTION );
            delete_option( self::UNLINK_PROGRESS_OPTION );
        } else {
            update_option( self::UNLINK_QUEUE_OPTION, $queue, false );
            update_option( self::UNLINK_PROGRESS_OPTION, $progress, false );
        }

        return array_merge( $progress, [ 'done' => $done ] );
    }

    /**
     * Point every anchor using one URL at a different one.
     *
     * The other half of fixing a broken link. Unlinking is right when the
     * destination is gone for good, but most 404s are a page that moved
     * rather than a page that died — 40 of the 200 on collegestationhomes.com
     * are cstx.gov and bryantx.gov pages from site redesigns, and the
     * retired county judicial-records and TDHCA links all still exist at new
     * addresses. Those want a corrected URL, and unlinking them would throw
     * away a working destination.
     *
     * Not a bulk operation, because the data says bulk would not help: the
     * broken URLs are essentially all distinct, so there is no
     * one-fix-clears-many to exploit. What it does do is update every page
     * using that URL in one action, which is the part that is tedious by hand.
     *
     * The replacement is checked before anything is written. A typo'd fix
     * would otherwise silently swap one broken link for another, and the
     * report would look like it improved.
     *
     * @return array { ok: bool, message: string, count: int, pages: int }
     */
    public function replace_link_url( string $old_url, string $new_url ): array {
        $old_url = trim( $old_url );
        $new_url = trim( $new_url );

        if ( $old_url === '' || $new_url === '' ) {
            return [ 'ok' => false, 'message' => __( 'Both the old and new address are required.', 'button-link-scanner' ), 'count' => 0, 'pages' => 0 ];
        }

        // Strict comparison, deliberately not hrefs_match(): that treats
        // http/https and a leading www. as equivalent for *finding* anchors,
        // which is right there and wrong here. Upgrading
        // "http://www.uhaul.com" to "https://www.uhaul.com" is a real fix —
        // several links in the report are http:// URLs on hosts that stopped
        // answering on port 80 — and hrefs_match() would call that no change
        // at all and refuse it.
        if ( $old_url === $new_url ) {
            return [ 'ok' => false, 'message' => __( 'The new address is identical to the old one.', 'button-link-scanner' ), 'count' => 0, 'pages' => 0 ];
        }

        // Relative paths are legitimate hrefs, so only reject something that
        // is not a usable address at all.
        if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $new_url ) && ! preg_match( '#^https?://#i', $new_url ) ) {
            return [ 'ok' => false, 'message' => __( 'Enter a web address (http:// or https://) or a path beginning with /.', 'button-link-scanner' ), 'count' => 0, 'pages' => 0 ];
        }

        $state = BLS_Link_Checker::verify_url_now( $new_url );
        if ( $state === 'broken' ) {
            return [
                'ok'      => false,
                'message' => __( 'That address is broken too — nothing was changed. Check it in a browser first.', 'button-link-scanner' ),
                'count'   => 0,
                'pages'   => 0,
            ];
        }

        $result = $this->rewrite_url_everywhere( $old_url, $new_url );

        if ( $result['count'] < 1 ) {
            return [
                'ok'      => false,
                'message' => __( 'The old link could not be found in any editable content, so nothing was changed.', 'button-link-scanner' ),
                'count'   => 0,
                'pages'   => 0,
            ];
        }

        // Move the scan rows onto the new address and stop reporting the old
        // one. The new URL will be picked up by the next link check.
        $this->repoint_link_rows( $old_url, $new_url, $result['post_ids'] );
        if ( ! $this->link_still_used( $old_url ) ) {
            BLS_Link_Checker::forget_link( $old_url );
        }

        $this->record_url_fix( $old_url, $new_url, $result );

        $message = sprintf(
            /* translators: 1: number of links, 2: number of pages */
            _n( 'Updated %1$d link across %2$d page(s).', 'Updated %1$d links across %2$d page(s).', $result['count'], 'button-link-scanner' ),
            $result['count'],
            $result['pages']
        );

        if ( $state !== 'ok' ) {
            $message .= ' ' . __( 'Note: the new address could not be confirmed working — it may be behind bot protection. Worth opening it once to be sure.', 'button-link-scanner' );
        }

        return [ 'ok' => true, 'message' => $message, 'count' => $result['count'], 'pages' => $result['pages'] ];
    }

    /**
     * Rewrite one URL to another everywhere it appears.
     *
     * @return array { count: int, pages: int, post_ids: int[] }
     */
    private function rewrite_url_everywhere( string $old_url, string $new_url ): array {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$res_table} WHERE link_url = %s AND post_id > 0",
            $old_url
        ) );

        $count   = 0;
        $changed = [];

        foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            $written = 0;

            $result = $this->replace_url_in_html( $post->post_content, $old_url, $new_url );
            if ( $result['changed'] ) {
                wp_update_post( [ 'ID' => $post->ID, 'post_content' => $result['html'] ] );
                $written += $result['count'];
            }

            if ( trim( (string) $post->post_excerpt ) !== '' ) {
                $result = $this->replace_url_in_html( $post->post_excerpt, $old_url, $new_url );
                if ( $result['changed'] ) {
                    wp_update_post( [ 'ID' => $post->ID, 'post_excerpt' => $result['html'] ] );
                    $written += $result['count'];
                }
            }

            foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
                if ( strpos( $key, '_' ) === 0 ) {
                    continue;
                }
                foreach ( (array) $values as $value ) {
                    if ( ! is_string( $value ) || $value === '' || stripos( $value, '<a' ) === false ) {
                        continue;
                    }
                    $result = $this->replace_url_in_html( $value, $old_url, $new_url );
                    if ( $result['changed'] ) {
                        update_post_meta( $post->ID, $key, $result['html'], $value );
                        $written += $result['count'];
                    }
                }
            }

            if ( $written > 0 ) {
                $count    += $written;
                $changed[] = $post_id;
            }
        }

        return [ 'count' => $count, 'pages' => count( $changed ), 'post_ids' => $changed ];
    }

    /**
     * Swap the href on every anchor pointing at $old_url, leaving the element
     * and everything inside it untouched. Matching goes through hrefs_match()
     * for the same percent-encoding reasons as the unlinker.
     *
     * @return array { html: string, changed: bool, count: int }
     */
    private function replace_url_in_html( string $html, string $old_url, string $new_url ): array {
        $unchanged = [ 'html' => $html, 'changed' => false, 'count' => 0 ];

        if ( trim( $html ) === '' || stripos( $html, '<a' ) === false ) {
            return $unchanged;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $count = 0;

        foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $node ) {
            if ( ! $node->hasAttribute( 'href' ) ) {
                continue;
            }
            if ( ! $this->hrefs_match( $node->getAttribute( 'href' ), $old_url ) ) {
                continue;
            }
            $node->setAttribute( 'href', $new_url );
            $count++;
        }

        if ( $count < 1 ) {
            return $unchanged;
        }

        return [
            'html'    => $this->strip_dom_wrapper( (string) $dom->saveHTML(), $html ),
            'changed' => true,
            'count'   => $count,
        ];
    }

    /**
     * Move scan rows onto the corrected address for the pages that were
     * actually rewritten, so the report reflects the change without waiting
     * on a full rescan.
     *
     * @param int[] $post_ids Pages the rewrite reached.
     */
    private function repoint_link_rows( string $old_url, string $new_url, array $post_ids ): void {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        foreach ( array_map( 'intval', $post_ids ) as $post_id ) {
            $wpdb->update(
                $res_table,
                [ 'link_url' => $new_url ],
                [ 'link_url' => $old_url, 'post_id' => $post_id ]
            );
        }
    }

    /**
     * Keep a short history of URL corrections. Single fixes are too small to
     * warrant a CSV each, but "what did I change and to what" is exactly the
     * question asked an hour later.
     */
    private function record_url_fix( string $old_url, string $new_url, array $result ): void {
        $history = get_option( 'bls_url_fix_history', [] );
        if ( ! is_array( $history ) ) {
            $history = [];
        }

        array_unshift( $history, [
            'old'   => $old_url,
            'new'   => $new_url,
            'count' => (int) $result['count'],
            'pages' => (int) $result['pages'],
            'time'  => current_time( 'mysql' ),
        ] );

        update_option( 'bls_url_fix_history', array_slice( $history, 0, 50 ), false );
    }

    /**
     * Strip one dead URL's anchors from every page that uses it.
     *
     * The health table keeps a single representative page per URL, but the
     * same link can appear on several, so the pages come from the results
     * table instead — otherwise a bulk unlink would silently leave copies
     * behind and they would reappear on the next scan.
     *
     * @return array { count: int, pages: int, post_ids: int[] }
     */
    private function unlink_url_everywhere( string $url, array &$progress ): array {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$res_table} WHERE link_url = %s AND post_id > 0",
            $url
        ) );

        $count   = 0;
        $changed = [];

        foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            $removed = $this->unlink_url_in_post( $post, $url, $progress );
            if ( $removed > 0 ) {
                $count    += $removed;
                $changed[] = $post_id;
            }
        }

        return [ 'count' => $count, 'pages' => count( $changed ), 'post_ids' => $changed ];
    }

    /**
     * Remove one URL's anchors from every writable source for a single post —
     * the same four places Auto-Fill writes to and the wipe tool cleans.
     *
     * @return int Anchors unlinked.
     */
    private function unlink_url_in_post( WP_Post $post, string $url, array &$progress ): int {
        $removed = 0;

        $result = $this->unlink_url_in_html( $post->post_content, $url );
        if ( $result['changed'] ) {
            wp_update_post( [ 'ID' => $post->ID, 'post_content' => $result['html'] ] );
            $removed += $result['count'];
            $this->log_unlink( $progress, $post, $url, $result['removed'], 'content' );
        }

        if ( trim( (string) $post->post_excerpt ) !== '' ) {
            $result = $this->unlink_url_in_html( $post->post_excerpt, $url );
            if ( $result['changed'] ) {
                wp_update_post( [ 'ID' => $post->ID, 'post_excerpt' => $result['html'] ] );
                $removed += $result['count'];
                $this->log_unlink( $progress, $post, $url, $result['removed'], 'excerpt' );
            }
        }

        $template_slug = get_page_template_slug( $post->ID );
        if ( ! empty( $template_slug ) ) {
            $slug = preg_replace( '/\.html$/', '', basename( $template_slug ) );
            if ( ! empty( $slug ) && $slug !== 'default' ) {
                $template_post = get_page_by_path( $slug, OBJECT, 'wp_template' );
                if ( $template_post && trim( (string) $template_post->post_content ) !== '' ) {
                    $result = $this->unlink_url_in_html( $template_post->post_content, $url );
                    if ( $result['changed'] ) {
                        wp_update_post( [ 'ID' => $template_post->ID, 'post_content' => $result['html'] ] );
                        $removed += $result['count'];
                        $this->log_unlink( $progress, $post, $url, $result['removed'], 'template' );
                    }
                }
            }
        }

        foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
            if ( strpos( $key, '_' ) === 0 ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                if ( ! is_string( $value ) || $value === '' || stripos( $value, '<a' ) === false ) {
                    continue;
                }
                $result = $this->unlink_url_in_html( $value, $url );
                if ( $result['changed'] ) {
                    update_post_meta( $post->ID, $key, $result['html'], $value );
                    $removed += $result['count'];
                    $this->log_unlink( $progress, $post, $url, $result['removed'], 'custom field: ' . $key );
                }
            }
        }

        return $removed;
    }

    /**
     * Replace every anchor pointing at $url with its own contents, leaving the
     * text and any inline markup (a <strong>, an icon <span>) exactly where it
     * was and only the link itself gone.
     *
     * href comparison goes through hrefs_match(), so a stored
     * "http://example.com/a b" still matches a rendered
     * "http://example.com/a%20b" — the percent-encoding difference that
     * needed fixing in 1.27.
     *
     * @return array { html: string, changed: bool, count: int, removed: array }
     */
    private function unlink_url_in_html( string $html, string $url ): array {
        $unchanged = [ 'html' => $html, 'changed' => false, 'count' => 0, 'removed' => [] ];

        if ( trim( $html ) === '' || stripos( $html, '<a' ) === false ) {
            return $unchanged;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $count   = 0;
        $removed = [];

        foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $node ) {
            if ( ! $node->hasAttribute( 'href' ) ) {
                continue;
            }
            if ( ! $this->hrefs_match( $node->getAttribute( 'href' ), $url ) ) {
                continue;
            }

            $text = trim( $node->textContent );

            // Move the anchor's children up into its place, then drop the
            // anchor. An empty anchor (an icon-only link with nothing inside)
            // would otherwise leave nothing at all behind, so it keeps its
            // text if there is any and simply disappears if there isn't.
            $parent = $node->parentNode;
            if ( ! $parent ) {
                continue;
            }
            while ( $node->firstChild ) {
                $parent->insertBefore( $node->firstChild, $node );
            }
            $parent->removeChild( $node );

            $count++;
            $removed[] = [ 'text' => $text ];
        }

        if ( $count < 1 ) {
            return $unchanged;
        }

        return [
            'html'    => $this->strip_dom_wrapper( (string) $dom->saveHTML(), $html ),
            'changed' => true,
            'count'   => $count,
            'removed' => $removed,
        ];
    }

    /**
     * Drop the scan rows for a URL that has just been unlinked, on the pages
     * where the rewrite actually landed. The element is no longer a link at
     * all, so leaving the rows would keep it in the "missing SEO title" and
     * broken-link counts until the next full scan.
     *
     * @param int[] $post_ids Pages that were genuinely rewritten.
     */
    private function forget_link_rows( string $url, array $post_ids ): void {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        foreach ( array_map( 'intval', $post_ids ) as $post_id ) {
            $wpdb->delete( $res_table, [ 'link_url' => $url, 'post_id' => $post_id ] );
        }
    }

    /** True while any scan row still points at this URL. */
    private function link_still_used( string $url ): bool {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$res_table} WHERE link_url = %s",
            $url
        ) ) > 0;
    }

    /** Audit trail for the unlink direction — same shape as log_wipe(). */
    private function log_unlink( array &$progress, WP_Post $post, string $url, array $removed, string $source ): void {
        foreach ( $removed as $entry ) {
            $this->append_log_row( (string) ( $progress['log_path'] ?? '' ), [
                $url,
                $entry['text'],
                $post->post_title,
                $post->ID,
                get_permalink( $post->ID ),
                $source,
            ] );

            if ( count( $progress['changes'] ) >= self::AUDIT_LOG_LIMIT ) {
                $progress['changes_truncated']++;
                continue;
            }
            $progress['changes'][] = [
                'post_id'     => $post->ID,
                'post_title'  => $post->post_title,
                'post_url'    => get_permalink( $post->ID ),
                'link_url'    => $url,
                'button_text' => $entry['text'],
                'source'      => $source,
            ];
        }
    }

    /**
     * Record removals for one post in the wipe's audit log, so the result
     * shows exactly which titles were taken off — same reasoning as
     * log_change() for the apply direction.
     */
    private function log_wipe( array &$progress, WP_Post $post, array $removed, string $source ): void {
        foreach ( $removed as $entry ) {
            // CSV gets every row, regardless of the on-page cap below.
            $this->append_log_row( (string) ( $progress['log_path'] ?? '' ), [
                $post->post_title,
                $post->ID,
                // Site chrome has no permalink of its own — get_permalink(0)
                // returns something misleading, so record the site root.
                (int) $post->ID === 0 ? home_url( '/' ) : get_permalink( $post->ID ),
                $entry['text'],
                $entry['title'],
                $source,
            ] );

            if ( count( $progress['changes'] ) >= self::AUDIT_LOG_LIMIT ) {
                $progress['changes_truncated']++;
                continue;
            }
            $progress['changes'][] = [
                'post_id'      => $post->ID,
                'post_title'   => $post->post_title,
                'post_url'     => get_permalink( $post->ID ),
                'button_text'  => $entry['text'],
                'removed_title' => $entry['title'],
                'source'       => $source,
            ];
        }
    }
}
