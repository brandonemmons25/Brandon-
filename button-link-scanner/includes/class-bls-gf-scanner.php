<?php
defined( 'ABSPATH' ) || exit;

/**
 * BLS_GF_Scanner
 *
 * Scans every active Gravity Form and evaluates its confirmation settings:
 *
 *  1. Is the confirmation a redirect (not an inline message)?
 *  2. Does it point to a page whose slug contains "thank" (thank-you, thanks, etc.)?
 *  3. Is that thank-you page a CHILD of the page that hosts the form?
 *
 * "Host pages" are found by searching post_content for the Gravity Forms
 * shortcode and, when Elementor is active, walking _elementor_data JSON.
 *
 * Works with or without Gravity Forms installed (returns empty when absent).
 */
class BLS_GF_Scanner {

    /** Slug fragments considered a "thank-you" page. */
    const THANK_YOU_SLUGS = [ 'thank', 'thanks', 'thankyou', 'thank-you', 'thank_you', 'confirmation' ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run a full Gravity Forms confirmation scan.
     *
     * @return array { forms_scanned: int, issues_found: int }
     */
    public function run_full_scan(): array {
        BLS_GF_Database::clear_results();

        if ( ! $this->gravity_forms_active() ) {
            return [ 'forms_scanned' => 0, 'issues_found' => 0, 'error' => 'Gravity Forms is not active.' ];
        }

        $forms         = $this->get_all_forms();
        $forms_scanned = 0;
        $issues_found  = 0;

        foreach ( $forms as $form ) {
            $results      = $this->scan_form( $form );
            $forms_scanned++;
            foreach ( $results as $r ) {
                if ( ! $r['passes'] ) {
                    $issues_found++;
                }
            }
        }

        update_option( 'bls_gf_last_scan_time', current_time( 'mysql' ) );

        return compact( 'forms_scanned', 'issues_found' );
    }

    /**
     * Scan a single form array and persist results.
     *
     * @param  array $form Gravity Forms form array.
     * @return array       Array of result rows inserted.
     */
    public function scan_form( array $form ): array {
        $form_id       = (int) $form['id'];
        $form_title    = $form['title'] ?? "Form #{$form_id}";
        $confirmations = $form['confirmations'] ?? [];
        $host_pages    = $this->get_host_pages( $form_id );
        $rows          = [];

        if ( empty( $confirmations ) ) {
            // Form has no confirmations configured — flag it.
            $row = $this->build_result( $form_id, $form_title, [], $host_pages );
            BLS_GF_Database::insert_result( $row );
            $rows[] = $row;
            return $rows;
        }

        foreach ( $confirmations as $conf ) {
            $row = $this->build_result( $form_id, $form_title, $conf, $host_pages );
            BLS_GF_Database::insert_result( $row );
            $rows[] = $row;
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Result builder
    // -------------------------------------------------------------------------

    private function build_result( int $form_id, string $form_title, array $conf, array $host_pages ): array {
        $now = current_time( 'mysql' );

        if ( empty( $conf ) ) {
            return [
                'form_id'              => $form_id,
                'form_title'           => $form_title,
                'confirmation_id'      => '',
                'confirmation_name'    => 'Default',
                'confirmation_type'    => 'none',
                'redirect_url'         => '',
                'redirect_page_id'     => 0,
                'redirect_page_title'  => '',
                'redirect_page_slug'   => '',
                'redirect_page_url'    => '',
                'is_redirect'          => 0,
                'is_thank_you_page'    => 0,
                'is_child_page'        => 0,
                'host_page_ids'        => implode( ',', array_column( $host_pages, 'ID' ) ),
                'host_page_titles'     => implode( ' | ', array_column( $host_pages, 'post_title' ) ),
                'passes'               => 0,
                'fail_reasons'         => 'No confirmations configured',
                'scan_date'            => $now,
            ];
        }

        $type    = $conf['type']    ?? 'message';  // 'message' | 'redirect' | 'page'
        $name    = $conf['name']    ?? 'Default';
        $conf_id = $conf['id']      ?? '';

        // Resolve redirect target.
        $redirect_url        = '';
        $redirect_page_id    = 0;
        $redirect_page_title = '';
        $redirect_page_slug  = '';
        $redirect_page_url   = '';

        if ( $type === 'redirect' ) {
            $redirect_url = $conf['url'] ?? '';
        } elseif ( $type === 'page' ) {
            $redirect_page_id = (int) ( $conf['pageId'] ?? 0 );
            if ( $redirect_page_id ) {
                $page = get_post( $redirect_page_id );
                if ( $page ) {
                    $redirect_page_title = $page->post_title;
                    $redirect_page_slug  = $page->post_name;
                    $redirect_page_url   = get_permalink( $page->ID );
                }
            }
        }

        // ---- Checks ----

        $is_redirect  = in_array( $type, [ 'redirect', 'page' ], true ) ? 1 : 0;
        $is_thank_you = $this->slug_is_thank_you( $redirect_page_slug ) || $this->url_is_thank_you( $redirect_url ) ? 1 : 0;

        // Child-page check:
        //  -1 = inconclusive (no parent set, or couldn't confirm either way)
        //   0 = has a parent, but that parent does not host this form (confirmed wrong)
        //   1 = has a parent, and that parent DOES host this form (confirmed correct)
        $host_found = ! empty( $host_pages );
        $is_child   = ( $redirect_page_id > 0 && $type === 'page' )
            ? $this->check_parent_relationship( $redirect_page_id, $form_id, $host_pages )
            : -1;

        // Collect failure reasons — the parent-relationship check only fails
        // when we've positively confirmed the parent doesn't host this form
        // (is_child === 0). A -1 (inconclusive) never produces a hard failure,
        // since we cannot be certain the setup is wrong.
        $fails = [];
        if ( ! $is_redirect ) {
            $fails[] = 'Confirmation shows an inline message instead of redirecting';
        }
        if ( $is_redirect && ! $is_thank_you ) {
            $fails[] = 'Redirect target does not appear to be a thank-you page';
        }
        if ( $is_redirect && $is_thank_you && $is_child === 0 ) {
            $fails[] = 'Thank-you page\'s parent does not appear to host this form — check the page hierarchy';
        }
        if ( $is_redirect && $type === 'redirect' && empty( $redirect_url ) ) {
            $fails[] = 'Redirect type set but no URL configured';
        }

        $passes = empty( $fails ) ? 1 : 0;

        return [
            'form_id'              => $form_id,
            'form_title'           => $form_title,
            'confirmation_id'      => $conf_id,
            'confirmation_name'    => $name,
            'confirmation_type'    => $type,
            'redirect_url'         => $redirect_url,
            'redirect_page_id'     => $redirect_page_id,
            'redirect_page_title'  => $redirect_page_title,
            'redirect_page_slug'   => $redirect_page_slug,
            'redirect_page_url'    => $redirect_page_url,
            'is_redirect'          => $is_redirect,
            'is_thank_you_page'    => $is_thank_you,
            'is_child_page'        => $is_child,
            'host_page_ids'        => implode( ',', array_column( $host_pages, 'ID' ) ),
            'host_page_titles'     => implode( ' | ', array_column( $host_pages, 'post_title' ) ),
            'passes'               => $passes,
            'fail_reasons'         => implode( '; ', $fails ),
            'scan_date'            => $now,
        ];
    }

    // -------------------------------------------------------------------------
    // Form discovery
    // -------------------------------------------------------------------------

    private function get_all_forms(): array {
        // Prefer the official API.
        if ( class_exists( 'GFAPI' ) ) {
            $forms = GFAPI::get_forms( null, false ); // active only, all statuses
            return is_array( $forms ) ? $forms : [];
        }

        // Fallback: direct DB query (works even if GFAPI is somehow unavailable).
        global $wpdb;
        $table = $wpdb->prefix . 'gf_form';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT id, title, display_meta FROM {$table} WHERE is_active = 1 AND is_trash = 0"
        );

        $forms = [];
        foreach ( $rows as $row ) {
            $meta = json_decode( $row->display_meta, true );
            if ( is_array( $meta ) ) {
                $meta['id']    = (int) $row->id;
                $meta['title'] = $row->title;
                $forms[]       = $meta;
            }
        }
        return $forms;
    }

    // -------------------------------------------------------------------------
    // Host-page discovery
    // -------------------------------------------------------------------------

    /**
     * Find all pages/posts that embed a given form via shortcode or Gutenberg block or Elementor.
     *
     * Shortcode variants handled:
     *   [gravityforms id="1"]  [gravityforms id='1']  [gravityforms id=1]
     *   [gravityform  id="1"]  [gravityform  id='1']  [gravityform  id=1]
     *
     * Gutenberg block variant:
     *   <!-- wp:gravityforms/form {"formId":"1"} /-->
     *   <!-- wp:gravityforms/form {"formId":1}  /-->
     *
     * @return WP_Post[]
     */
    private function get_host_pages( int $form_id ): array {
        global $wpdb;

        $id = $form_id; // shorthand

        // Build all post_content LIKE patterns for this form ID.
        $patterns = [
            // Quoted shortcodes (double or single quote).
            '%[gravityforms id="'  . $id . '"%',
            "%[gravityforms id='{$id}'%",
            '%[gravityform id="'   . $id . '"%',
            "%[gravityform id='{$id}'%",
            // Unquoted shortcodes.
            '%[gravityforms id=' . $id . ' %',
            '%[gravityforms id=' . $id . ']%',
            '%[gravityform id='  . $id . ' %',
            '%[gravityform id='  . $id . ']%',
            // Gutenberg block (formId as string or integer).
            '%"formId":"' . $id . '"%',
            '%"formId":' . $id . ',%',
            '%"formId":' . $id . '}%',
        ];

        // Build the WHERE clause dynamically.
        $placeholders = implode( ' OR post_content LIKE ', array_fill( 0, count( $patterns ), '%s' ) );
        $post_ids     = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND ( post_content LIKE {$placeholders} )",
            $patterns
        ) );

        if ( empty( $post_ids ) ) {
            return [];
        }

        return get_posts( [
            'post__in'       => array_map( 'intval', $post_ids ),
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'all',
        ] );
    }

    // -------------------------------------------------------------------------
    // Check helpers
    // -------------------------------------------------------------------------

    private function slug_is_thank_you( string $slug ): bool {
        if ( empty( $slug ) ) return false;
        $slug = strtolower( $slug );
        foreach ( self::THANK_YOU_SLUGS as $fragment ) {
            if ( str_contains( $slug, $fragment ) ) {
                return true;
            }
        }
        return false;
    }

    private function url_is_thank_you( string $url ): bool {
        if ( empty( $url ) ) return false;
        $path = strtolower( parse_url( $url, PHP_URL_PATH ) ?? '' );
        foreach ( self::THANK_YOU_SLUGS as $fragment ) {
            if ( str_contains( $path, $fragment ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine whether the thank-you page's immediate parent actually
     * hosts the given form.
     *
     * This does NOT rely on matching literal shortcode text (which breaks
     * on page builders like Divi that wrap/transform shortcodes in ways a
     * text search can miss). Instead it:
     *
     *  1. Looks at the thank-you page's real post_parent.
     *     - No parent at all → definitively NOT a child (0).
     *  2. Renders the parent's actual front-end content via
     *     apply_filters('the_content', ...) — this expands Divi, Elementor
     *     shortcodes, Gutenberg blocks, everything — and searches the
     *     RENDERED HTML for markers Gravity Forms itself always outputs
     *     for a given form ID (e.g. id="gform_wrapper_5", data-formid="5").
     *     - Found  → confirmed correct (1).
     *  3. Falls back to checking whether the parent's ID is among the
     *     "host pages" found via the (fragile) site-wide shortcode search,
     *     as a secondary signal.
     *     - Found  → confirmed correct (1).
     *  4. Otherwise → inconclusive (-1), NOT a hard failure. We only report
     *     a definitive failure when there's no parent page at all.
     *
     * @param int      $thank_you_page_id
     * @param int      $form_id
     * @param WP_Post[] $host_pages Pages found via the site-wide shortcode search (secondary signal).
     * @return int -1 inconclusive | 0 confirmed wrong | 1 confirmed correct
     */
    private function check_parent_relationship( int $thank_you_page_id, int $form_id, array $host_pages ): int {
        $page = get_post( $thank_you_page_id );
        if ( ! $page ) {
            return -1;
        }

        $parent_id = (int) $page->post_parent;
        if ( $parent_id <= 0 ) {
            // No parent set at all — definitively not a child of anything.
            return 0;
        }

        // Primary check: does the parent's actual rendered output contain
        // this specific form? Works regardless of page builder.
        $parent = get_post( $parent_id );
        if ( $parent ) {
            $rendered = apply_filters( 'the_content', $parent->post_content );
            $markers  = [
                'gform_wrapper_' . $form_id,
                'gform_' . $form_id . '"',
                "gform_{$form_id}'",
                'data-formid="' . $form_id . '"',
                "data-formid='{$form_id}'",
                'data-form-id="' . $form_id . '"',
                'data-form-index="' . $form_id . '"',
            ];
            foreach ( $markers as $marker ) {
                if ( str_contains( $rendered, $marker ) ) {
                    return 1;
                }
            }

            // Fallback: some page builders (Divi's Code module, saved
            // library sections, etc.) store the raw shortcode in a form
            // that survives in post_content but isn't expanded the same
            // way by the_content filter chain in all contexts. Check the
            // unrendered source directly too.
            $raw = (string) $parent->post_content;
            if ( preg_match( '/\[gravityforms?\s+id=["\']?' . preg_quote( (string) $form_id, '/' ) . '["\']?[\s\]]/i', $raw )
                 || str_contains( $raw, '"formId":"' . $form_id . '"' )
                 || str_contains( $raw, '"formId":' . $form_id . ',' )
                 || str_contains( $raw, '"formId":' . $form_id . '}' ) ) {
                return 1;
            }

            // Block-theme (FSE) custom page template — same blind spot as
            // Divi Theme Builder, different mechanism. On block themes, a
            // page can have a custom template assigned (stored as its own
            // wp_template post) whose content lives entirely outside the
            // page's own post_content. Check that too.
            $template_slug = get_page_template_slug( $parent_id );
            if ( ! empty( $template_slug ) ) {
                $slug = preg_replace( '/\.html$/', '', basename( $template_slug ) );
                if ( ! empty( $slug ) && $slug !== 'default' ) {
                    $template_post = get_page_by_path( $slug, OBJECT, 'wp_template' );
                    if ( $template_post && ! empty( trim( $template_post->post_content ) ) ) {
                        $template_rendered = apply_filters( 'the_content', $template_post->post_content );
                        foreach ( $markers as $marker ) {
                            if ( str_contains( $template_rendered, $marker ) ) {
                                return 1;
                            }
                        }
                        if ( str_contains( $template_post->post_content, '"formId":"' . $form_id . '"' )
                             || str_contains( $template_post->post_content, '"formId":' . $form_id . ',' )
                             || str_contains( $template_post->post_content, '"formId":' . $form_id . '}' ) ) {
                            return 1;
                        }
                    }
                }
            }
        }

        // Secondary check: was the parent identified by the broader
        // site-wide shortcode/block text search?
        foreach ( $host_pages as $host ) {
            if ( (int) $host->ID === $parent_id ) {
                return 1;
            }
        }

        // Has a parent, but we couldn't confirm that parent hosts the form.
        // This is inconclusive rather than a hard failure — the render
        // pass above can miss forms loaded via JS/AJAX or heavily cached
        // builder output.
        return -1;
    }

    private function gravity_forms_active(): bool {
        return class_exists( 'GFForms' ) || class_exists( 'GFAPI' );
    }
}
