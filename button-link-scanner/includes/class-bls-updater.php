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

        // Target anchor elements that look like buttons.
        $anchors = $xpath->query( '//a' );
        foreach ( $anchors as $anchor ) {
            $text = strtolower( trim( $anchor->textContent ) );
            if ( $text !== $normalized_text ) {
                continue;
            }
            if ( ! $this->anchor_looks_like_button( $anchor ) ) {
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

    private function anchor_looks_like_button( DOMElement $node ): bool {
        $class = strtolower( $node->getAttribute( 'class' ) );
        foreach ( BLS_Scanner::BUTTON_CLASS_PATTERNS as $pattern ) {
            if ( str_contains( $class, $pattern ) ) {
                return true;
            }
        }
        return $node->getAttribute( 'role' ) === 'button';
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
}
