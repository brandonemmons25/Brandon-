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
        //  -1 = inconclusive (host page not found, can't verify)
        //   0 = host found but thank-you page is NOT a child of it
        //   1 = host found and thank-you page IS a child of it
        $host_found = ! empty( $host_pages );
        if ( ! $host_found || $redirect_page_id <= 0 || $type !== 'page' ) {
            // Can't determine parent relationship without both sides.
            $is_child = -1;
        } else {
            $is_child = $this->page_is_child_of_hosts( $redirect_page_id, $host_pages ) ? 1 : 0;
        }

        // Collect failure reasons — child check only fails when we have enough
        // info to be certain it's wrong (host found, page found, not a child).
        $fails = [];
        if ( ! $is_redirect ) {
            $fails[] = 'Confirmation shows an inline message instead of redirecting';
        }
        if ( $is_redirect && ! $is_thank_you ) {
            $fails[] = 'Redirect target does not appear to be a thank-you page';
        }
        if ( $is_redirect && $is_thank_you && $is_child === 0 ) {
            $fails[] = 'Thank-you page is not a child of the form\'s host page';
        }
        if ( $is_redirect && $is_thank_you && $is_child === -1 && $host_found ) {
            // Host was found but page ID is 0 or type isn't 'page' — soft note only.
            $fails[] = 'Child-page relationship could not be verified (URL redirect type — check manually)';
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

        // Also search Elementor meta for this form ID.
        $el_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_data'
               AND ( meta_value LIKE %s OR meta_value LIKE %s )",
            '%"form_id":"' . $id . '"%',
            '%"form_id":' . $id . '%'
        ) );

        $all_ids = array_unique( array_merge( array_map( 'intval', $post_ids ), array_map( 'intval', $el_ids ) ) );

        if ( empty( $all_ids ) ) {
            return [];
        }

        return get_posts( [
            'post__in'       => $all_ids,
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
     * Check whether a page is a child of any host page.
     *
     * @param int      $page_id    The thank-you page ID (0 = unknown).
     * @param WP_Post[] $host_pages Pages that embed the form.
     */
    private function page_is_child_of_hosts( int $page_id, array $host_pages ): bool {
        if ( $page_id <= 0 || empty( $host_pages ) ) {
            return false;
        }

        $page = get_post( $page_id );
        if ( ! $page || (int) $page->post_parent === 0 ) {
            return false;
        }

        $host_ids = array_map( fn( $p ) => (int) $p->ID, $host_pages );
        return in_array( (int) $page->post_parent, $host_ids, true );
    }

    private function gravity_forms_active(): bool {
        return class_exists( 'GFForms' ) || class_exists( 'GFAPI' );
    }
}
