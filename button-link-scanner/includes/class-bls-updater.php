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

    /**
     * Find every button that has a link but no title, generate a
     * descriptive title ("button text – destination page title"), and
     * write it into the actual post content — but ONLY where a title is
     * currently missing. Existing hrefs/titles are never touched, so this
     * is safe to run repeatedly without risk of overwriting anything
     * intentional.
     *
     * @return array { pairs_processed: int, posts_updated: int, titles_added: int }
     */
    public function auto_fill_missing_titles(): array {
        global $wpdb;
        $res_table = $wpdb->prefix . BLS_Database::RESULTS_TABLE;

        $pairs = $wpdb->get_results(
            "SELECT DISTINCT button_text, link_url FROM {$res_table}
             WHERE has_link = 1 AND has_title = 0 AND link_url != ''"
        );

        $pairs_processed       = 0;
        $posts_updated         = 0;
        $titles_added          = 0;
        $titles_injected       = 0; // Applied live via BLS_Render_Injector, not written to the DB — see rewrite_missing_title().
        $could_not_apply       = 0;
        $not_in_database       = 0; // No matching anchor even in fully rendered content — genuinely nowhere to attach a title.
        $blocked_by_mismatch   = 0; // A matching anchor WAS found in a writable source, but its href didn't match or it already has a title — a real, fixable data issue.
        $diagnostic            = null; // Captured from the first hard failure only — enough to reveal the pattern.

        foreach ( $pairs as $pair ) {
            $pairs_processed++;
            $generated_title = $this->generate_auto_title( $pair->button_text, $pair->link_url );
            if ( empty( $generated_title ) ) {
                continue;
            }

            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$res_table}
                 WHERE button_text = %s AND link_url = %s AND has_title = 0",
                $pair->button_text,
                $pair->link_url
            ) );

            foreach ( $post_ids as $post_id ) {
                $post = get_post( (int) $post_id );
                if ( ! $post ) {
                    continue;
                }

                $result = $this->rewrite_missing_title( $post, $pair->button_text, $pair->link_url, $generated_title );
                if ( $result['applied'] ) {
                    // Update the results table to match, right now — the
                    // Dashboard/Results pages read from this table, not
                    // from post_content directly, so without this the
                    // fix would be invisible anywhere in the plugin's UI
                    // until a full "Run Full Scan Now" was done again.
                    $wpdb->update(
                        $res_table,
                        [ 'has_title' => 1, 'title_text' => $generated_title ],
                        [ 'post_id' => (int) $post_id, 'button_text' => $pair->button_text, 'link_url' => $pair->link_url, 'has_title' => 0 ]
                    );

                    $posts_updated++;
                    $titles_added += $result['count'];
                } elseif ( ! empty( $result['render_only'] ) ) {
                    // The anchor only exists AFTER shortcode/widget
                    // rendering — nowhere in raw storage to persist a
                    // fix to (see rewrite_missing_title()). Queue it for
                    // BLS_Render_Injector instead, which adds the same
                    // title live on every page load. Mark has_title=1 so
                    // the rest of the plugin's UI reflects reality: from
                    // a visitor's/SEO's perspective the title now exists
                    // on the page, even though nothing changed in the DB.
                    BLS_Render_Injector::queue( (int) $post_id, $pair->button_text, $pair->link_url, $generated_title );
                    $wpdb->update(
                        $res_table,
                        [ 'has_title' => 1, 'title_text' => $generated_title ],
                        [ 'post_id' => (int) $post_id, 'button_text' => $pair->button_text, 'link_url' => $pair->link_url, 'has_title' => 0 ]
                    );
                    $titles_injected++;
                } else {
                    $could_not_apply++;

                    // A candidate found in a writable raw source (post
                    // content, WC excerpt, block template, custom field)
                    // but blocked by an href/title mismatch is a real,
                    // fixable data issue. Nothing found anywhere, even
                    // after checking fully rendered content above, means
                    // there's genuinely no anchor to attach a title to.
                    if ( empty( $result['candidates'] ) ) {
                        $not_in_database++;
                    } else {
                        $blocked_by_mismatch++;
                    }

                    // Diagnose exactly why, from the FIRST failure — this
                    // is the automated equivalent of manually opening the
                    // code editor to check raw content by hand: a plain
                    // substring search (no DOM parsing at all) reveals
                    // whether the button's text/URL literally exist
                    // anywhere in this post's raw stored content.
                    if ( $diagnostic === null ) {
                        $raw_text_found = str_contains( $post->post_content, $pair->button_text );
                        $raw_href_found = str_contains( $post->post_content, $pair->link_url );
                        $diagnostic = [
                            'post_id'          => (int) $post_id,
                            'post_title'       => $post->post_title,
                            'button_text'      => $pair->button_text,
                            'link_url'         => $pair->link_url,
                            'text_in_raw'      => $raw_text_found,
                            'href_in_raw'      => $raw_href_found,
                            'content_snippet'  => substr( $post->post_content, 0, 300 ),
                            'candidates'       => $result['candidates'] ?? [],
                        ];
                    }
                }
            }
        }

        return compact( 'pairs_processed', 'posts_updated', 'titles_added', 'titles_injected', 'could_not_apply', 'not_in_database', 'blocked_by_mismatch', 'diagnostic' );
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
                return [ 'applied' => false, 'render_only' => true, 'count' => 0, 'candidates' => $all_candidates ];
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
                    return [ 'applied' => false, 'render_only' => true, 'count' => 0, 'candidates' => $all_candidates ];
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
