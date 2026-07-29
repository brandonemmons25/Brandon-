<?php
defined( 'ABSPATH' ) || exit;

/**
 * BLS_Scanner
 *
 * Iterates over all published posts/pages/CPTs, parses their HTML
 * content, and stores every detected button in the results table.
 *
 * Button detection targets:
 *  - Gutenberg button blocks  (.wp-block-button__link)
 *  - Any <a> whose class contains "btn" or "button"
 *  - Bare <button> and <input type="button|submit|reset"> elements
 *  - Elements with role="button"
 *
 * Content sources (all DB-only — no outbound HTTP requests are ever made
 * during a scan, which keeps scans fast and avoids hosting-firewall false
 * positives from the site calling itself):
 *  1. apply_filters('the_content', post_content) — covers Gutenberg,
 *     classic HTML, and shortcode-based builders (Divi included, since
 *     Divi's own page-builder shortcodes live directly in post_content).
 *  2. WooCommerce short description (post_excerpt) — some product pages
 *     carry their real copy/CTAs there instead of the main editor.
 *  3. Block-theme (FSE) custom page template — if a page has a custom
 *     template assigned, its content can live entirely in a separate
 *     `wp_template` post rather than the page's own content.
 *  4. Custom fields (ACF, etc.) — classic themes like Genesis commonly
 *     build a page's real content from custom fields via a dedicated
 *     template, with post_content left empty. Checked as a last resort,
 *     only when nothing above found anything.
 *
 * If a post's content is still empty after all sources, it's flagged as
 * "needs manual check" (see scan_post()'s null return) rather than
 * silently skipped.
 *
 * One deliberate exception: the homepage gets ONE additional live HTTP
 * fetch (see scan_homepage()), to catch content hardcoded directly into
 * a theme template rather than stored in the database — common for
 * custom-built homepages. This is scoped to a single URL, run once per
 * full scan, so it doesn't carry the timeout/firewall risk that site-wide
 * fetching did.
 */
class BLS_Scanner {

    /** CSS class fragments that identify a styled button link. */
    const BUTTON_CLASS_PATTERNS = [
        'btn',
        'button',
        'wp-block-button__link',
        'wp-element-button',
        'elementor-button',
        'vc_btn',
        'fusion-button',
        'et_pb_button',
    ];

    /**
     * Class fragments that mark a button as a UI/system control — not
     * an authored CTA. Nodes matching any of these are skipped entirely.
     */
    const SKIP_CLASS_PATTERNS = [
        // Gravity Forms submit / navigation buttons.
        'gform_button',
        'gform_next_button',
        'gform_previous_button',
        'gform_save_link',
        // Generic nav-toggle patterns used by most themes.
        'menu-toggle',
        'nav-toggle',
        'navbar-toggle',
        'hamburger',
        'mobile-menu-toggle',
        // Search toggles.
        'search-toggle',
        'search-submit',
        // WooCommerce storefront UI — not authored marketing CTAs, and not
        // controlled by content edits, so these are always noise for the
        // Button Map / trend-tracking use case.
        'add_to_cart_button',
        'ajax_add_to_cart',
        'single_add_to_cart_button',
        'wc-forward',
        'checkout-button',
        'wc-proceed-to-checkout',
        'apply_coupon',
        'update_cart',
        'showcoupon',
        'wc-backward',
        'reset_variations',
        'woocommerce-Button',
    ];

    /**
     * Class/ID fragments identifying Optima Express / iHomeFinder IDX
     * widget output (search boxes, "schedule a tour" CTAs, listing-detail
     * controls, etc.). Confirmed on cesipagano.com: the "Search" button
     * on the homepage comes from a bare `[optima_express_search]`
     * shortcode with nothing else in post_content, and iHomeFinder's own
     * widgets/shortcodes consistently prefix their markup with `ihf-`/
     * `IHF_`. This content is vendor-controlled and re-rendered by the
     * IDX plugin itself, not authored page content — there's no post,
     * meta field, or template anywhere to attach a title to, and no
     * amount of matching-logic improvement changes that. Treated as
     * noise the same way WooCommerce/Gravity Forms UI chrome already is,
     * rather than flagged as a "missing title" the site owner can't
     * actually do anything about.
     */
    const SKIP_IDX_VENDOR_PATTERNS = [
        'ihf-',
        'ihf_',
        'optima-express',
        'optima_express',
        'ihomefinder',
    ];

    /**
     * aria-label substrings (lowercase) that identify nav/UI-only buttons.
     * These are never authored CTA buttons and should not appear in results.
     */
    const SKIP_ARIA_PATTERNS = [
        'open menu',
        'close menu',
        'toggle menu',
        'toggle navigation',
        'toggle nav',
        'mobile menu',
        'main menu',
        'primary menu',
        'site navigation',
        'submenu',
        'sub-menu',
        'search form',
        'search site',
        'open search',
        'close search',
        'scroll to top',
        'back to top',
        // Photo gallery / carousel / slideshow controls — auto-generated UI
        // chrome from listing/gallery widgets (e.g. real estate photo
        // sliders), not authored content. Common on listing post types.
        'slideshow',
        'slide show',
        'pause slide',
        'play slide',
        'next slide',
        'previous slide',
        'prev slide',
        'next image',
        'previous image',
        'prev image',
        'carousel',
        'gallery navigation',
        'pause gallery',
        'play gallery',
    ];

    /** Option name used to persist the remaining-work queue between AJAX batch calls. */
    const QUEUE_OPTION = 'bls_scan_queue';

    /** Option name used to persist running totals between AJAX batch calls. */
    const PROGRESS_OPTION = 'bls_scan_progress';

    /** Default number of posts processed per batch. */
    const DEFAULT_BATCH_SIZE = 10;

    // -------------------------------------------------------------------------
    // Public API — batched scan (used by the admin UI)
    // -------------------------------------------------------------------------

    /**
     * Build the work queue and reset stored results/progress. Call once,
     * then call run_batch() repeatedly (e.g. via repeated AJAX requests)
     * until it reports done = true.
     *
     * @return array { total_items: int }
     */
    public function start_scan(): array {
        BLS_Database::clear_results();

        $queue        = [];
        $excluded_ids = $this->get_excluded_post_ids();

        foreach ( $this->get_scannable_post_types() as $post_type ) {
            $ids = get_posts( [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post__not_in'   => $excluded_ids,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ] );
            foreach ( $ids as $id ) {
                $queue[] = (int) $id;
            }
        }

        // Homepage marker: 0 = "run the homepage scan logic", which itself
        // decides whether that means a static front page (already queued
        // above and just skipped again) or a "Latest Posts" HTTP fetch.
        $queue[] = 0;

        update_option( self::QUEUE_OPTION, $queue, false );
        update_option( self::PROGRESS_OPTION, [
            'total_items'   => count( $queue ),
            'processed'     => 0,
            'buttons_found' => 0,
            'scanned_ids'   => [],
            'skipped'       => [], // [{ id, title, url }] — empty content, needs manual check
        ], false );

        return [ 'total_items' => count( $queue ) ];
    }

    /**
     * Process the next batch of items from the stored queue.
     *
     * @param  int $batch_size How many posts to process this call.
     * @return array { done: bool, processed: int, total_items: int, buttons_found: int }
     */
    public function run_batch( int $batch_size = self::DEFAULT_BATCH_SIZE ): array {
        $queue    = get_option( self::QUEUE_OPTION, null );
        $progress = get_option( self::PROGRESS_OPTION, null );

        // Defensive: if state is missing (e.g. batch called without a prior
        // start_scan(), such as after an unexpected reset), initialise fresh
        // rather than fatal-erroring.
        if ( $queue === null || $progress === null ) {
            $this->start_scan();
            $queue    = get_option( self::QUEUE_OPTION, [] );
            $progress = get_option( self::PROGRESS_OPTION );
        }

        $batch = array_splice( $queue, 0, max( 1, $batch_size ) );

        foreach ( $batch as $item ) {
            if ( (int) $item === 0 ) {
                // Homepage marker.
                $progress['buttons_found'] += $this->scan_homepage( $progress );
                continue;
            }

            $post = get_post( (int) $item );
            if ( ! $post ) {
                continue;
            }

            $found = $this->scan_post( $post );
            if ( $found === null ) {
                // Empty content, but not always a real problem — some
                // pages are SUPPOSED to have no content of their own.
                // The most common case: the designated "Posts page"
                // (Settings → Reading → "Posts page"), which only ever
                // displays a dynamic list of blog posts and never has
                // its own body content. Flagging that as "needs manual
                // check" is a false positive, so it's excluded here.
                $posts_page_id = (int) get_option( 'page_for_posts', 0 );
                if ( $post->ID !== $posts_page_id ) {
                    $progress['skipped'][] = [
                        'id'    => $post->ID,
                        'title' => $post->post_title,
                        'url'   => get_permalink( $post->ID ),
                    ];
                }
            } else {
                $progress['buttons_found'] += $found;
            }
            $progress['scanned_ids'][] = $post->ID;
        }

        $progress['processed'] += count( $batch );
        $done = empty( $queue );

        if ( $done ) {
            // One extra step before finalizing: for the (typically small)
            // set of pages that came back with no scannable content,
            // actually fetch the live rendered page and check again. This
            // is different from the site-wide HTTP fallback removed
            // earlier — that one fired on every empty page during a
            // large batched loop and risked timeouts/firewall flags at
            // scale. This one only runs once per full scan, against a
            // short, already-identified list (usually under ~20 pages),
            // the same bounded-risk logic already used for the homepage
            // exception. If a page turns out to have real content when
            // actually rendered, it's scanned for real and dropped from
            // the "needs manual check" list; if it's still empty, that's
            // now a *confirmed* empty page, not just an unexamined one.
            $recheck = $this->recheck_skipped_pages( $progress['skipped'] );
            $progress['buttons_found'] += $recheck['buttons_found'];
            $progress['skipped']        = $recheck['still_skipped'];

            update_option( 'bls_last_scan_total',   $progress['buttons_found'] );
            update_option( 'bls_last_scan_time',    current_time( 'mysql' ) );
            update_option( 'bls_last_scan_skipped', $progress['skipped'], false );
            delete_option( self::QUEUE_OPTION );
            delete_option( self::PROGRESS_OPTION );
        } else {
            update_option( self::QUEUE_OPTION, $queue, false );
            update_option( self::PROGRESS_OPTION, $progress, false );
        }

        return [
            'done'          => $done,
            'processed'     => $progress['processed'],
            'total_items'   => $progress['total_items'],
            'buttons_found' => $progress['buttons_found'],
        ];
    }

    /**
     * Scan a single WP_Post object and persist button data.
     *
     * @return int|null Number of buttons found, or null if the post's
     *                   content was empty (should be flagged for manual review).
     */
    public function scan_post( WP_Post $post ): ?int {
        $content = $this->get_post_content( $post );

        if ( empty( trim( $content ) ) ) {
            return null;
        }

        $buttons = $this->extract_buttons( $content );
        $count   = 0;
        $url     = get_permalink( $post->ID );

        foreach ( $buttons as $btn ) {
            BLS_Database::insert_result( [
                'post_id'       => $post->ID,
                'post_title'    => $post->post_title,
                'post_type'     => $post->post_type,
                'post_status'   => $post->post_status,
                'post_url'      => $url,
                'button_text'   => $btn['text'],
                'button_html'   => $btn['html'],
                'has_link'      => (int) $btn['has_link'],
                'link_url'      => $btn['link_url'],
                'has_title'     => (int) $btn['has_title'],
                'title_text'    => $btn['title_text'],
                'opens_new_tab' => (int) $btn['opens_new_tab'],
                'button_type'   => $btn['button_type'],
            ] );
            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Content gathering
    // -------------------------------------------------------------------------

    /**
     * Get the rendered HTML for a post via WordPress's normal content
     * pipeline. Covers Gutenberg, classic HTML, and any shortcode-based
     * builder (Divi included, since Divi shortcodes live in post_content).
     *
     * Deliberately does NOT make any outbound HTTP requests — a prior
     * version fetched the live URL as a fallback for empty content, but
     * that made the site call itself, which is slow, unreliable, and
     * commonly blocked by hosting firewalls (flagged as SSRF-like traffic).
     * Posts with genuinely empty content are flagged for manual review
     * instead (see scan_post()'s null return).
     */
    /**
     * Get the rendered HTML for a post via WordPress's normal content
     * pipeline, plus additional DB-only sources (no HTTP requests — see
     * class docblock) that commonly hold real content even when
     * post_content itself is empty:
     *
     *  - WooCommerce short description (post_excerpt) — many product
     *    pages carry their real marketing copy/CTAs there instead of
     *    the main content editor.
     */
    private function get_post_content( WP_Post $post ): string {
        $parts = [];

        $main = trim( (string) apply_filters( 'the_content', $post->post_content ) );
        if ( $main !== '' ) {
            $parts[] = $main;
        }

        // WooCommerce short description.
        if ( $post->post_type === 'product' && ! empty( trim( (string) $post->post_excerpt ) ) ) {
            $parts[] = (string) apply_filters( 'the_content', $post->post_excerpt );
        }

        // Block-theme (FSE) custom page template. On block themes,
        // templates are stored as their own `wp_template` posts in the
        // database rather than living inside the page's own content —
        // if a page has a custom template assigned (Page attributes ->
        // Template, in a theme like Powder or any other FSE theme), the
        // page's post_content can be nearly empty while the template
        // itself holds real content/blocks (including forms).
        $template_content = $this->get_block_theme_template_content( $post->ID );
        if ( ! empty( $template_content ) ) {
            $parts[] = $template_content;
        }

        // Custom-field fallback (ACF, etc.). Classic (non-block) themes
        // like Genesis commonly build a page's real visible content from
        // custom fields via a dedicated page template, rather than the
        // main content editor — e.g. a vendor/resource directory page
        // where post_content is empty but postmeta holds the actual
        // names/links/HTML rendered by the template. If nothing else has
        // produced content yet, scan this post's own postmeta for values
        // that look like real markup and include them. Deliberately only
        // fires when the sources above found nothing, and only looks at
        // this post's OWN meta (no cross-post guessing), to keep it safe
        // and narrowly scoped.
        if ( empty( $parts ) ) {
            $meta_content = $this->get_custom_field_content( $post->ID );
            if ( ! empty( $meta_content ) ) {
                $parts[] = $meta_content;
            }
        }

        return implode( "\n", $parts );
    }

    /**
     * Look for real, renderable content sitting in this post's own custom
     * fields (postmeta) — the ACF/custom-field fallback described above.
     * Only considers values that already look like markup (contain a tag
     * likely to hold a button/link: <a, <button, <input, or a raw URL),
     * so it won't accidentally ingest unrelated internal meta (layout
     * flags, IDs, serialized config, etc.).
     */
    private function get_custom_field_content( int $post_id ): string {
        $all_meta = get_post_meta( $post_id );
        if ( empty( $all_meta ) || ! is_array( $all_meta ) ) {
            return '';
        }

        $parts = [];
        foreach ( $all_meta as $key => $values ) {
            // Skip WordPress/plugin-internal meta (leading underscore is
            // the WP convention for "not meant to be rendered directly").
            if ( strpos( $key, '_' ) === 0 ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                if ( ! is_string( $value ) || $value === '' ) {
                    continue;
                }
                // Only interested in values that look like real markup —
                // avoids pulling in serialized arrays, numeric flags, or
                // plain unrelated text with no buttons/links to find.
                if ( preg_match( '/<a\s|<button|<input|href=|https?:\/\//i', $value ) ) {
                    $parts[] = (string) apply_filters( 'the_content', $value );
                }
            }
        }

        return implode( "\n", $parts );
    }

    /**
     * Look up and return the rendered content of a page's assigned block
     * theme template, if it has one other than the default. Returns ''
     * if there's no custom template or it can't be found.
     */
    private function get_block_theme_template_content( int $post_id ): string {
        $template_slug = get_page_template_slug( $post_id );
        if ( empty( $template_slug ) ) {
            return '';
        }

        // Normalize: block-theme template slugs are commonly stored as
        // "templates/page-contact.html" or just "page-contact" depending
        // on WP version / how it was assigned — strip both variants down
        // to a bare slug for matching against wp_template post_name.
        $slug = basename( $template_slug );
        $slug = preg_replace( '/\.html$/', '', $slug );
        if ( empty( $slug ) || $slug === 'default' ) {
            return '';
        }

        $template_post = get_page_by_path( $slug, OBJECT, 'wp_template' );
        if ( ! $template_post || empty( trim( $template_post->post_content ) ) ) {
            return '';
        }

        return (string) apply_filters( 'the_content', $template_post->post_content );
    }

    /**
     * Scan the homepage if it's a static front page. ("Latest Posts" mode
     * has no dedicated content to scan and is skipped — its individual
     * posts are already covered by the normal post-type loop.)
     *
     * @param array $progress Progress state (read for scanned_ids, not mutated here).
     * @return int Number of additional buttons found (0 if nothing to do).
     */
    private function scan_homepage( array $progress ): int {
        $show_on_front = get_option( 'show_on_front', 'posts' );
        $page_on_front = (int) get_option( 'page_on_front', 0 );
        $found_count   = 0;
        $already_ran   = false;

        if ( $show_on_front === 'page' && $page_on_front > 0 && ! in_array( $page_on_front, $progress['scanned_ids'], true ) ) {
            $post = get_post( $page_on_front );
            if ( $post ) {
                $found       = $this->scan_post( $post );
                $found_count += $found ?? 0;
                $already_ran  = true;
            }
        }

        // Homepage-only live fetch. This is the ONE exception to the
        // "no HTTP requests" rule elsewhere in this class — site-wide
        // fetching was removed because it made scans slow/unreliable and
        // firewall-prone, but a single request for a single known URL,
        // run once per full scan (not once per page), carries none of
        // that risk. This exists specifically to catch content that's
        // hardcoded into a theme template rather than stored in the
        // database — common for custom-built homepages.
        $existing = BLS_Database::get_button_signatures_for_post( $page_on_front > 0 ? $page_on_front : 0, home_url( '/' ) );
        $html     = $this->fetch_homepage_html();

        if ( ! empty( $html ) ) {
            $buttons = $this->extract_buttons( $html );
            foreach ( $buttons as $btn ) {
                $signature = $btn['text'] . '|' . $btn['link_url'];
                if ( in_array( $signature, $existing, true ) ) {
                    continue; // Already captured via the database pass above.
                }
                BLS_Database::insert_result( [
                    'post_id'       => $page_on_front > 0 ? $page_on_front : 0,
                    'post_title'    => __( 'Home Page (theme template)', 'button-link-scanner' ),
                    'post_type'     => 'front_page',
                    'post_status'   => 'publish',
                    'post_url'      => home_url( '/' ),
                    'button_text'   => $btn['text'],
                    'button_html'   => $btn['html'],
                    'has_link'      => (int) $btn['has_link'],
                    'link_url'      => $btn['link_url'],
                    'has_title'     => (int) $btn['has_title'],
                    'title_text'    => $btn['title_text'],
                    'opens_new_tab' => (int) $btn['opens_new_tab'],
                    'button_type'   => $btn['button_type'],
                ] );
                $found_count++;
            }
        }

        return $found_count;
    }

    /**
     * Live-fetch each page on the "needs manual check" list and re-check
     * for buttons. Bounded and scoped — unlike the removed site-wide HTTP
     * fallback, this only ever touches a short, already-identified list
     * (capped at 20 pages regardless of how many were flagged), so it
     * carries none of the batch-scale timeout/firewall risk. Real
     * content found this way (e.g. a page whose content comes from a
     * mechanism this scanner's DB-only sources can't see — a template
     * that constructs links in PHP rather than storing them as data, for
     * example) gets scanned and recorded properly; pages still empty
     * after an actual live render are now confirmed empty, not just
     * unexamined.
     *
     * @param array $skipped List of ['id'=>, 'title'=>, 'url'=>] entries.
     * @return array{buttons_found:int, still_skipped:array} buttons found,
     *               and the entries that are still genuinely empty.
     */
    private function recheck_skipped_pages( array $skipped ): array {
        $buttons_found = 0;
        $still_skipped = [];
        $checked       = 0;
        $max_rechecks  = 20;

        foreach ( $skipped as $entry ) {
            if ( $checked >= $max_rechecks ) {
                // Safety cap — if there are more than this many flagged
                // pages, something bigger is going on than a handful of
                // edge cases, and re-fetching dozens of pages live starts
                // to reintroduce the exact timeout risk this is meant to
                // avoid. Leave the rest flagged for manual review as-is.
                $still_skipped[] = $entry;
                continue;
            }
            $checked++;

            $html = $this->fetch_live_page_html( $entry['url'] );
            if ( empty( $html ) ) {
                $still_skipped[] = $entry; // Fetch failed — still unconfirmed either way.
                continue;
            }

            $buttons = $this->extract_buttons( $html );
            if ( empty( $buttons ) ) {
                $still_skipped[] = $entry; // Confirmed empty via a real render, not just unexamined.
                continue;
            }

            $post = get_post( (int) $entry['id'] );
            $url  = $entry['url'];
            foreach ( $buttons as $btn ) {
                BLS_Database::insert_result( [
                    'post_id'       => (int) $entry['id'],
                    'post_title'    => $post ? $post->post_title : $entry['title'],
                    'post_type'     => $post ? $post->post_type : 'page',
                    'post_status'   => $post ? $post->post_status : 'publish',
                    'post_url'      => $url,
                    'button_text'   => $btn['text'],
                    'button_html'   => $btn['html'],
                    'has_link'      => (int) $btn['has_link'],
                    'link_url'      => $btn['link_url'],
                    'has_title'     => (int) $btn['has_title'],
                    'title_text'    => $btn['title_text'],
                    'opens_new_tab' => (int) $btn['opens_new_tab'],
                    'button_type'   => $btn['button_type'],
                ] );
                $buttons_found++;
            }
            // Found real content — drop it from the skipped list entirely.
        }

        return [ 'buttons_found' => $buttons_found, 'still_skipped' => $still_skipped ];
    }

    /**
     * Fetch a single page's live, rendered HTML. Scoped to the bounded
     * recheck above (max 20 calls per full scan) — see that method's
     * docblock for why this is safe despite the "no HTTP requests"
     * principle elsewhere in this class.
     */
    private function fetch_live_page_html( string $url ): string {
        if ( empty( $url ) ) {
            return '';
        }

        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/BLS-Scanner (manual-check recheck)',
            'sslverify'  => apply_filters( 'bls_fetch_sslverify', true ),
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return '';
        }

        return (string) wp_remote_retrieve_body( $response );
    }

    /**
     * Fetch the live, fully-rendered homepage HTML. Scoped to exactly one
     * URL, called exactly once per full scan — see scan_homepage() above
     * for why this is safe despite the "no HTTP requests" rule elsewhere.
     */
    private function fetch_homepage_html(): string {
        $response = wp_remote_get( home_url( '/' ), [
            'timeout'    => 10,
            'user-agent' => 'WordPress/BLS-Scanner (homepage check)',
            'sslverify'  => apply_filters( 'bls_fetch_sslverify', true ),
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return '';
        }

        return (string) wp_remote_retrieve_body( $response );
    }

    // -------------------------------------------------------------------------
    // Button detection (DOM parsing)
    // -------------------------------------------------------------------------

    /**
     * Parse HTML and return an array of button descriptor arrays.
     */
    public function extract_buttons( string $html ): array {
        if ( empty( trim( $html ) ) ) {
            return [];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $xpath   = new DOMXPath( $dom );
        $buttons = [];
        $seen    = []; // deduplicate by outerHTML hash

        // 1. Gutenberg & styled anchor buttons.
        $anchor_nodes = $xpath->query( '//a' );
        foreach ( $anchor_nodes as $node ) {
            if ( $this->node_should_skip( $node ) ) continue;
            if ( $this->node_is_button( $node ) ) {
                $entry = $this->describe_anchor( $node, 'gutenberg_or_styled' );
                $key   = md5( $entry['html'] );
                if ( ! isset( $seen[ $key ] ) ) {
                    $buttons[]    = $entry;
                    $seen[ $key ] = true;
                }
            }
        }

        // 2. Bare <button> elements.
        foreach ( $xpath->query( '//button' ) as $node ) {
            if ( $this->node_should_skip( $node ) ) continue;
            $entry = $this->describe_button_element( $node );
            $key   = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]    = $entry;
                $seen[ $key ] = true;
            }
        }

        // 3. <input type="button|submit|reset">.
        foreach ( $xpath->query( '//input[@type="button" or @type="submit" or @type="reset"]' ) as $node ) {
            if ( $this->node_should_skip( $node ) ) continue;
            $entry = $this->describe_input( $node );
            $key   = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]    = $entry;
                $seen[ $key ] = true;
            }
        }

        // 4. Any element with role="button" not already captured.
        foreach ( $xpath->query( '//*[@role="button"]' ) as $node ) {
            $tag = strtolower( $node->nodeName );
            if ( in_array( $tag, [ 'a', 'button', 'input' ], true ) ) continue;
            if ( $this->node_should_skip( $node ) ) continue;
            $entry = $this->describe_role_button( $node );
            $key   = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]    = $entry;
                $seen[ $key ] = true;
            }
        }

        // 5. Plain content hyperlinks — real <a href> links with real text
        // that AREN'T already styled/classed as buttons (those were
        // captured in pass 1). Tracked as a separate button_type
        // ('hyperlink') specifically so they can be filtered and worked
        // with independently from buttons/CTAs — e.g. an inline link in
        // blog post body copy, like "29 Ventada, Rancho Mission Viejo"
        // linking out to a listing detail page. Only meaningful, real
        // links are captured: skip empty/anchor-only hrefs, and skip
        // anything already flagged as UI chrome (nav, GF, etc.) via the
        // same node_should_skip() used everywhere else.
        foreach ( $xpath->query( '//a[@href]' ) as $node ) {
            if ( $this->node_should_skip( $node ) ) continue;
            if ( $this->node_is_button( $node ) ) continue; // already captured in pass 1

            $href = trim( $node->getAttribute( 'href' ) );
            $text = trim( $node->textContent );
            if ( $href === '' || $href === '#' || $text === '' ) {
                continue; // Not a meaningful, checkable content link.
            }

            $entry = $this->describe_anchor( $node, 'hyperlink' );
            $entry['button_type'] = 'hyperlink';
            $key = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]    = $entry;
                $seen[ $key ] = true;
            }
        }

        // 5. Plain content hyperlinks — any <a href> with real text that
        // wasn't already captured above as a button-styled element. This
        // is a separate category from buttons ("Button Kind" = hyperlink)
        // so both can be sorted/filtered/applied independently: a body-
        // copy link like "29 Ventada, Rancho Mission Viejo" pointing to
        // an old IDX Broker URL is just as worth catching as a styled
        // button, but it's a different kind of thing to fix and belongs
        // in its own bucket, not mixed in with CTA buttons.
        foreach ( $anchor_nodes as $node ) {
            if ( $this->node_should_skip( $node ) ) continue;
            if ( $this->node_is_button( $node ) ) continue; // already captured in pass 1

            $text = trim( $node->textContent );
            $href = trim( $node->getAttribute( 'href' ) );
            if ( $text === '' || $href === '' || $href === '#' ) {
                continue; // no real label or no real destination — nothing to track
            }

            $entry = $this->describe_anchor( $node, 'hyperlink' );
            $entry['button_type'] = 'hyperlink';
            $key = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]    = $entry;
                $seen[ $key ] = true;
            }
        }

        return $buttons;
    }

    // -------------------------------------------------------------------------
    // Node descriptor helpers
    // -------------------------------------------------------------------------

    /**
     * Return true if this node is a UI/system button that should never
     * appear in scan results (GF submit buttons, nav toggles, etc.).
     */
    private function node_should_skip( DOMElement $node ): bool {
        $class = strtolower( $node->getAttribute( 'class' ) );
        $id    = strtolower( $node->getAttribute( 'id' ) );
        foreach ( self::SKIP_CLASS_PATTERNS as $pattern ) {
            if ( str_contains( $class, $pattern ) ) {
                return true;
            }
        }
        foreach ( self::SKIP_IDX_VENDOR_PATTERNS as $pattern ) {
            if ( str_contains( $class, $pattern ) || str_contains( $id, $pattern ) ) {
                return true;
            }
        }

        $aria  = strtolower( $node->getAttribute( 'aria-label' ) );
        $title = strtolower( $node->getAttribute( 'title' ) );

        if ( $aria !== '' ) {
            foreach ( self::SKIP_ARIA_PATTERNS as $pattern ) {
                if ( str_contains( $aria, $pattern ) ) {
                    return true;
                }
            }
        }

        // An element with NO visible text, NO aria-label, and NO title,
        // AND a class name suggestive of a navigation/slider control
        // (prev/next/arrow/pause/play/nav/slide/carousel/gallery), is
        // almost certainly decorative UI chrome from a gallery/carousel
        // widget — not an authored CTA. Common pattern: real estate
        // listing photo galleries render prev/next arrows and a
        // play/pause toggle as empty <a>/<button> elements with only a
        // class name and no accessible label at all (an accessibility
        // gap in the widget itself, not something this site's author
        // wrote). Scoped to elements that also look like nav controls by
        // class name, rather than skipping ALL unlabeled elements
        // outright — a genuinely empty-label image-wrapped CTA link
        // elsewhere should still be scanned for a missing/broken href.
        $visible_text = trim( $node->textContent );
        if ( $visible_text === '' && $aria === '' && $title === '' ) {
            $nav_indicators = [ 'prev', 'next', 'arrow', 'pause', 'play', 'nav', 'slide', 'carousel', 'gallery', 'control', 'indicator', 'dot', 'thumb', 'slick', 'swiper', 'glide', 'splide', 'flickity', 'owl-' ];
            foreach ( $nav_indicators as $indicator ) {
                if ( str_contains( $class, $indicator ) ) {
                    return true;
                }
            }
        }

        // Skip buttons inside a Gravity Forms form wrapper, or an
        // Optima Express / iHomeFinder IDX widget wrapper — the button's
        // own class/id often doesn't carry the vendor's branding, only
        // the container it's rendered inside of does.
        $ancestor = $node->parentNode;
        while ( $ancestor instanceof DOMElement ) {
            $ancestor_class = strtolower( $ancestor->getAttribute( 'class' ) );
            $ancestor_id    = strtolower( $ancestor->getAttribute( 'id' ) );
            if ( str_contains( $ancestor_class, 'gform_wrapper' )
                 || str_contains( $ancestor_id, 'gform_wrapper' )
                 || str_contains( $ancestor_id, 'gform_' ) ) {
                return true;
            }
            foreach ( self::SKIP_IDX_VENDOR_PATTERNS as $pattern ) {
                if ( str_contains( $ancestor_class, $pattern ) || str_contains( $ancestor_id, $pattern ) ) {
                    return true;
                }
            }
            $ancestor = $ancestor->parentNode;
        }

        return false;
    }

    private function node_is_button( DOMElement $node ): bool {
        $class = strtolower( $node->getAttribute( 'class' ) );
        foreach ( self::BUTTON_CLASS_PATTERNS as $pattern ) {
            if ( str_contains( $class, $pattern ) ) {
                return true;
            }
        }
        return $node->getAttribute( 'role' ) === 'button';
    }

    /**
     * Resolve the best visible label for a node.
     * Priority: textContent → aria-label → title attribute → "(no text)".
     * Wraps aria-label/title in brackets so origin is clear in the UI.
     */
    private function get_node_text( DOMElement $node ): string {
        $text = trim( $node->textContent );
        if ( $text !== '' ) {
            return $text;
        }
        $aria = trim( $node->getAttribute( 'aria-label' ) );
        if ( $aria !== '' ) {
            return '[aria: ' . $aria . ']';
        }
        $attr_title = trim( $node->getAttribute( 'title' ) );
        if ( $attr_title !== '' ) {
            return '[title: ' . $attr_title . ']';
        }
        return '(no text)';
    }

    private function describe_anchor( DOMElement $node, string $type ): array {
        $href     = trim( $node->getAttribute( 'href' ) );
        $title    = trim( $node->getAttribute( 'title' ) );
        $target   = $node->getAttribute( 'target' );
        $has_link = ! empty( $href ) && $href !== '#';

        return [
            'text'          => $this->get_node_text( $node ),
            'html'          => $this->outer_html( $node ),
            'has_link'      => $has_link,
            'link_url'      => $has_link ? $href : '',
            'has_title'     => ! empty( $title ),
            'title_text'    => $title,
            'opens_new_tab' => $target === '_blank',
            'button_type'   => $this->detect_button_type( $node ),
        ];
    }

    private function describe_button_element( DOMElement $node ): array {
        $parent   = $node->parentNode;
        $href     = '';
        $title    = '';
        $has_link = false;
        $new_tab  = false;
        $type     = strtolower( $node->getAttribute( 'type' ) ?: 'submit' ); // <button> defaults to type=submit

        if ( $parent instanceof DOMElement && strtolower( $parent->nodeName ) === 'a' ) {
            $href     = trim( $parent->getAttribute( 'href' ) );
            $title    = trim( $parent->getAttribute( 'title' ) );
            $has_link = ! empty( $href ) && $href !== '#';
            $new_tab  = $parent->getAttribute( 'target' ) === '_blank';
        } elseif ( in_array( $type, [ 'submit', 'button' ], true ) ) {
            // Same PayPal-style case as describe_input(): a <button> with
            // no <a> wrapper submits its enclosing <form> — that form's
            // action attribute is the real, checkable destination.
            $form = $node->parentNode;
            while ( $form && ! ( $form instanceof DOMElement && strtolower( $form->nodeName ) === 'form' ) ) {
                $form = $form->parentNode;
            }
            if ( $form instanceof DOMElement ) {
                $action = trim( $form->getAttribute( 'action' ) );
                if ( ! empty( $action ) ) {
                    $href     = $action;
                    $has_link = true;
                    $new_tab  = $form->getAttribute( 'target' ) === '_blank';
                }
            }
        }

        return [
            'text'          => $this->get_node_text( $node ),
            'html'          => $this->outer_html( $node ),
            'has_link'      => $has_link,
            'link_url'      => $has_link ? $href : '',
            'has_title'     => ! empty( $title ),
            'title_text'    => $title,
            'opens_new_tab' => $new_tab,
            'button_type'   => 'button_element',
        ];
    }

    private function describe_input( DOMElement $node ): array {
        $value    = trim( $node->getAttribute( 'value' ) );
        $parent   = $node->parentNode;
        $href     = '';
        $title    = '';
        $has_link = false;
        $new_tab  = false;
        $type     = strtolower( $node->getAttribute( 'type' ) );

        if ( $parent instanceof DOMElement && strtolower( $parent->nodeName ) === 'a' ) {
            $href     = trim( $parent->getAttribute( 'href' ) );
            $title    = trim( $parent->getAttribute( 'title' ) );
            $has_link = ! empty( $href ) && $href !== '#';
            $new_tab  = $parent->getAttribute( 'target' ) === '_blank';
        } elseif ( in_array( $type, [ 'submit', 'button', 'image' ], true ) ) {
            // Submit/button inputs don't carry their own href — the real
            // destination is the enclosing <form>'s action attribute.
            // Missing this meant PayPal-style donate buttons (rendered as
            // <form action="https://paypal.com/..."><input type="submit">)
            // always showed as "no link", even when the form's action URL
            // was a real, checkable destination that could be broken.
            $form = $node->parentNode;
            while ( $form && ! ( $form instanceof DOMElement && strtolower( $form->nodeName ) === 'form' ) ) {
                $form = $form->parentNode;
            }
            if ( $form instanceof DOMElement ) {
                $action = trim( $form->getAttribute( 'action' ) );
                if ( ! empty( $action ) ) {
                    $href     = $action;
                    $has_link = true;
                    $new_tab  = $form->getAttribute( 'target' ) === '_blank';
                }
            }
        }

        return [
            'text'          => $value ?: $node->getAttribute( 'type' ),
            'html'          => $this->outer_html( $node ),
            'has_link'      => $has_link,
            'link_url'      => $has_link ? $href : '',
            'has_title'     => ! empty( $title ),
            'title_text'    => $title,
            'opens_new_tab' => $new_tab,
            'button_type'   => 'input_element',
        ];
    }

    private function describe_role_button( DOMElement $node ): array {
        return [
            'text'          => $this->get_node_text( $node ),
            'html'          => $this->outer_html( $node ),
            'has_link'      => false,
            'link_url'      => '',
            'has_title'     => false,
            'title_text'    => '',
            'opens_new_tab' => false,
            'button_type'   => 'role_button',
        ];
    }

    private function detect_button_type( DOMElement $node ): string {
        $class = strtolower( $node->getAttribute( 'class' ) );
        if ( str_contains( $class, 'wp-block-button' ) || str_contains( $class, 'wp-element-button' ) ) {
            return 'gutenberg';
        }
        if ( str_contains( $class, 'elementor-button' ) ) {
            return 'elementor';
        }
        if ( str_contains( $class, 'et_pb_button' ) ) {
            return 'divi';
        }
        if ( str_contains( $class, 'vc_btn' ) || str_contains( $class, 'fusion-button' ) ) {
            return 'page_builder';
        }
        return 'classic';
    }

    private function outer_html( DOMElement $node ): string {
        $doc = new DOMDocument();
        $doc->appendChild( $doc->importNode( $node, true ) );
        return trim( $doc->saveHTML() );
    }

    // -------------------------------------------------------------------------
    // Post type discovery
    // -------------------------------------------------------------------------

    private function get_scannable_post_types(): array {
        $all     = get_post_types( [ 'public' => true ], 'names' );
        $exclude = [ 'attachment' ];

        // WooCommerce internal CPTs have no user-authored button content.
        if ( class_exists( 'WooCommerce' ) ) {
            $exclude = array_merge( $exclude, [
                'shop_order',
                'shop_order_refund',
                'shop_coupon',
                'shop_webhook',
            ] );
        }

        $exclude = apply_filters( 'bls_exclude_post_types', $exclude );
        return array_values( array_diff( $all, $exclude ) );
    }

    /**
     * Return post IDs that should always be skipped, regardless of type.
     * Covers WooCommerce utility pages (cart, checkout, my account, etc.)
     * whose buttons are functional UI, not authored content.
     */
    private function get_excluded_post_ids(): array {
        $ids = [];

        if ( class_exists( 'WooCommerce' ) ) {
            $wc_options = [
                'woocommerce_shop_page_id',
                'woocommerce_cart_page_id',
                'woocommerce_checkout_page_id',
                'woocommerce_myaccount_page_id',
                'woocommerce_terms_page_id',
                'woocommerce_refund_returns_page_id',
            ];
            foreach ( $wc_options as $opt ) {
                $id = (int) get_option( $opt, 0 );
                if ( $id > 0 ) {
                    $ids[] = $id;
                }
            }
        }

        return apply_filters( 'bls_exclude_post_ids', array_unique( $ids ) );
    }
}
