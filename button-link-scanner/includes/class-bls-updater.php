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

        update_option( self::AUTOFILL_QUEUE_OPTION, $queue, false );
        update_option( self::AUTOFILL_PROGRESS_OPTION, [
            'total_items'         => count( $queue ),
            'pairs_processed'     => 0,
            'posts_updated'       => 0,
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
                $post = get_post( (int) $post_id );
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

                    $progress['posts_updated']++;
                    $progress['titles_added'] += $result['count'];
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
     * Generate "button text – destination context" for a button/link
     * pair. Internal links use the actual destination page's title
     * (most descriptive, matches how the button is really used); external
     * or unresolvable links fall back to the domain name, since there's
     * no page title to pull from without an outbound fetch.
     */
    private function generate_auto_title( string $button_text, string $link_url ): string {
        $button_text = trim( $button_text );
        if ( $button_text === '' ) {
            return '';
        }

        $check_url = trim( $link_url );
        if ( $check_url === '' || $check_url === '#' ) {
            return $button_text;
        }
        if ( ! preg_match( '#^https?://#i', $check_url ) ) {
            // Relative path — resolve against this site to look up the
            // destination post, same normalization used by the link
            // health checker.
            $check_url = home_url( '/' . ltrim( $check_url, '/' ) );
        }

        $post_id = url_to_postid( $check_url );
        if ( $post_id > 0 ) {
            $dest_title = get_the_title( $post_id );
            if ( ! empty( $dest_title ) ) {
                return $button_text . ' – ' . $dest_title;
            }
        }

        // External (or unresolvable internal) link — use the domain as
        // context instead, since there's no page title to pull from
        // without making an outbound request.
        $host = wp_parse_url( $check_url, PHP_URL_HOST );
        if ( ! empty( $host ) ) {
            $host = preg_replace( '/^www\./i', '', $host );
            return $button_text . ' – ' . $host;
        }

        return $button_text;
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
                    'href_matched' => $anchor_href === $target_link,
                    'has_title'    => trim( $anchor->getAttribute( 'title' ) ) !== '',
                ];
            }

            if ( $text !== $normalized_text ) {
                continue;
            }
            if ( $anchor_href !== $target_link ) {
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
                if ( ! $form || trim( $form->getAttribute( 'action' ) ) !== $target_link ) {
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

        // None of the writable sources contained a literal match, even
        // after rendering. This means the button was found via a
        // live-only fetch (the homepage-only check, or the bounded
        // "needs manual check" recheck) — content hardcoded directly
        // into a theme template file, with no post at all behind it.
        // That's a genuine, honest limit: there's no reachable place to
        // save a fix, live or persisted.
        return [ 'applied' => false, 'count' => 0, 'candidates' => $all_candidates ];
    }
}
