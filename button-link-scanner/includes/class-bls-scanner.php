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
        // Jetpack sharing (the Sharedaddy module). Every variant carries
        // sd-button: share-facebook, share-email, share-twitter, share-tumblr,
        // share-pinterest, and the sharing-anchor "More" toggle. On
        // collegestationhomes.com that module rendered six buttons on each of
        // 322 posts — 1,932 rows, 24% of the entire scan — and its "More"
        // toggle has no href, which accounted for all 322 "no URL set" rows
        // exactly. Sharing widgets are furniture: not authored per post, not
        // editable from one, and not links that can rot.
        'sd-button',
        'sharing-anchor',
        // Theme post meta: the author byline rendered under every post title.
        'entry-author-link',
        // Previous/next post links and the excerpt "Read more" link.
        'adjacent-entry-link',
        'more-link',
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
     * Shortcode tag prefixes stripped from raw content before rendering
     * (see strip_vendor_shortcodes()). Some Optima Express/iHomeFinder
     * widgets turn out to be entirely client-side JavaScript apps (a
     * search bar confirmed on cesipagano.com renders via React/MUI, with
     * build-hashed CSS classes that regenerate every deploy — invisible
     * to this scanner no matter what, since it only parses static
     * server-rendered HTML). Stripping the shortcode tag itself is a
     * firmer, deploy-stable signal than trying to match whatever HTML
     * happens to render today.
     */
    const VENDOR_SHORTCODE_PREFIXES = [
        'optima_express',
        'ihf_',
        'ihomefinder',
    ];

    /**
     * Hostnames that identify IDX vendor-served content. Used to recognise
     * an IDX wrapper page — one whose visible content is produced by the
     * vendor from its own subdomain rather than authored on this site.
     * Every IDX provider creates a similar set of these: "Advanced Search",
     * "Basic Search", "Map Search", "Address", "Listing ID", "Results",
     * "Details", "Email Update Signup".
     *
     * Vendor-neutral by design, and that has already proved its worth: the
     * site this was built against (humboldthomeguide.com) turned out to run
     * iHomeFinder/Optima Express, not IDX Broker as first assumed — the
     * misleading clue was a leftover "IDX Broker" attribution link on one of
     * its pages, presumably from an earlier provider. Matching on the host
     * serving the content meant the filter worked regardless.
     *
     * Hostnames rather than CSS classes or shortcode tags on purpose: a
     * vendor's markup and class names change between versions and builds
     * (see the React/MUI hashed classes that made a class-based filter
     * useless in v1.4.14), but the host its content is served from is stable
     * and directly observable in the page.
     */
    const IDX_VENDOR_HOST_PATTERNS = [
        'idxbroker.com',
        'idxhome.com',
        'ihomefinder.com',
        'idxre.com',
    ];

    /**
     * Class/id fragments marking site-wide chrome regions (header, footer,
     * sidebar, nav) in a LIVE-FETCHED full page. Used only by
     * strip_site_chrome() — see that method for why this matters.
     *
     * Deliberately conservative: only fragments that are unambiguously
     * theme chrome, never generic words like "widget" or "menu" on their
     * own, which can legitimately appear inside real page content.
     * Genesis-oriented (both known client sites run it) but the names are
     * common across most classic themes.
     */
    const SITE_CHROME_PATTERNS = [
        'site-header',
        'site-footer',
        'site-navigation',
        'main-navigation',
        'nav-primary',
        'nav-secondary',
        'navbar',
        'sidebar',
        'widget-area',
        'footer-widgets',
        'site-info',
        'breadcrumb',
        'skip-link',
        'sub-footer',
        'top-bar',
        'utility-bar',
        'social-icons',
        'off-canvas',
        'mobile-menu',
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

    /** Buttons the owner has marked as not present on the page — see get_dismissed_buttons(). */
    const DISMISSED_OPTION = 'bls_dismissed_buttons';

    /**
     * Set by scan_post() when the live page was fetched successfully and simply
     * had no buttons on it. That is a confirmed empty page, not an unknown one,
     * so it does not need re-fetching later — see run_recheck_phase().
     */
    private $live_confirmed_empty = false;

    /** Option name used to persist the remaining-work queue between AJAX batch calls. */
    const QUEUE_OPTION = 'bls_scan_queue';

    /** Option name used to persist running totals between AJAX batch calls. */
    const PROGRESS_OPTION = 'bls_scan_progress';

    /** Default number of posts processed per batch. */
    // Smaller than it was, because each page is now fetched over HTTP rather
    // than read from the database. See scan_post().
    const DEFAULT_BATCH_SIZE = 4;

    /**
     * Sent on every live page fetch. The scanner treats the live page as the
     * authority on what is on the page, which is only true if what comes back
     * is current — a cached copy makes the report describe the site as it was
     * whenever that copy was generated.
     */
    const NO_CACHE_HEADERS = [
        'Cache-Control' => 'no-cache, no-store, max-age=0',
        'Pragma'        => 'no-cache',
    ];

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

        // Site chrome marker: -1 = "scan the header, footer, navigation and
        // widget areas once". Not per page — see extract_site_chrome().
        $queue[] = -1;

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

        // No state means there is nothing in progress — most often because a
        // scan just finished and deleted it. Report done and stop.
        //
        // This used to call start_scan() instead, which turned a single
        // trailing batch request into an entire fresh scan. Combined with the
        // auto-resume on page load, that is a scan that restarts itself
        // indefinitely: finish, reload, resume, restart, finish. Starting work
        // nobody asked for is never the right response to missing state.
        if ( $queue === null || $progress === null ) {
            return [
                'done'          => true,
                'processed'     => 0,
                'total_items'   => 0,
                'buttons_found' => (int) get_option( 'bls_last_scan_total', 0 ),
            ];
        }

        // The live-recheck phase, once the main queue is done. Kept as its own
        // batched phase rather than a step tacked onto the final content batch.
        //
        // It used to run inline the moment the queue emptied, which meant one
        // request had to finish the last posts AND fetch up to twenty pages
        // over HTTP at ten seconds apiece. On a site with seventeen flagged
        // pages that request exceeded the time limit and died — and because the
        // options are only deleted after it returns, the queue survived, the
        // dashboard reported an interrupted scan, auto-resume fired, and the
        // scan failed at exactly the same point every time. A scan that could
        // never finish, forever.
        if ( empty( $queue ) ) {
            return $this->run_recheck_phase( $progress );
        }

        $batch = array_splice( $queue, 0, max( 1, $batch_size ) );

        foreach ( $batch as $item ) {
            if ( (int) $item === 0 ) {
                // Homepage marker.
                $progress['buttons_found'] += $this->scan_homepage( $progress );
                continue;
            }

            if ( (int) $item === -1 ) {
                // Site chrome marker.
                $progress['buttons_found'] += $this->scan_site_chrome();
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
                if ( $this->live_confirmed_empty ) {
                    // Already confirmed against the live page.
                    $progress['confirmed_empty_direct'] = (int) ( $progress['confirmed_empty_direct'] ?? 0 ) + 1;
                } elseif ( $post->ID !== $posts_page_id ) {
                    $progress['skipped'][] = [
                        'id'    => $post->ID,
                        'title' => $post->post_title,
                        'url'   => get_permalink( $post->ID ),
                        'type'  => $post->post_type,
                    ];
                }
            } else {
                $progress['buttons_found'] += $found;
            }
            $progress['scanned_ids'][] = $post->ID;
        }

        $progress['processed'] += count( $batch );

        // Never finalize here. Even when this emptied the queue, the recheck
        // phase still has to run, and it gets its own requests to do it in.
        update_option( self::QUEUE_OPTION, $queue, false );
        update_option( self::PROGRESS_OPTION, $progress, false );

        return [
            'done'          => false,
            'processed'     => $progress['processed'],
            'total_items'   => $progress['total_items'],
            'buttons_found' => $progress['buttons_found'],
        ];
    }

    /** How many flagged pages to re-fetch per request. Each is a live HTTP call. */
    const RECHECK_BATCH_SIZE = 3;

    /**
     * Re-fetch flagged pages a few at a time, then finalize the scan.
     *
     * Entered once the content queue is empty, and called repeatedly until the
     * recheck list is exhausted. Splitting it this way is what makes a scan
     * able to finish on a site with more than a couple of flagged pages — see
     * the note in run_batch().
     */
    private function run_recheck_phase( array $progress ): array {
        // First entry: seed the recheck list and fold it into the total so the
        // progress reading keeps moving instead of appearing stuck at 100%.
        if ( ! isset( $progress['recheck_queue'] ) || ! is_array( $progress['recheck_queue'] ) ) {
            $progress['recheck_queue'] = array_values( (array) ( $progress['skipped'] ?? [] ) );
            $progress['still_skipped'] = [];
            $progress['recheck_totals'] = [
                'confirmed_empty'   => 0,
                'idx_vendor_pages'  => 0,
                'offsite_redirects' => 0,
            ];
            $progress['total_items'] += count( $progress['recheck_queue'] );
        }

        $slice = array_splice( $progress['recheck_queue'], 0, self::RECHECK_BATCH_SIZE );

        if ( ! empty( $slice ) ) {
            $recheck = $this->recheck_skipped_pages( $slice );

            $progress['buttons_found'] += (int) $recheck['buttons_found'];
            $progress['still_skipped']  = array_merge(
                (array) $progress['still_skipped'],
                (array) $recheck['still_skipped']
            );
            foreach ( [ 'confirmed_empty', 'idx_vendor_pages', 'offsite_redirects' ] as $key ) {
                $progress['recheck_totals'][ $key ] += (int) ( $recheck[ $key ] ?? 0 );
            }
            $progress['processed'] += count( $slice );
        }

        if ( ! empty( $progress['recheck_queue'] ) ) {
            update_option( self::PROGRESS_OPTION, $progress, false );
            return [
                'done'          => false,
                'processed'     => $progress['processed'],
                'total_items'   => $progress['total_items'],
                'buttons_found' => $progress['buttons_found'],
            ];
        }

        // Recheck list exhausted — this is the only place a scan completes.
        $progress['skipped'] = $progress['still_skipped'];
        $recheck             = $progress['recheck_totals'];
        $recheck['confirmed_empty'] = (int) ( $recheck['confirmed_empty'] ?? 0 )
            + (int) ( $progress['confirmed_empty_direct'] ?? 0 );

        update_option( 'bls_last_scan_total',   $progress['buttons_found'] );
        update_option( 'bls_last_scan_time',    current_time( 'mysql' ) );
        update_option( 'bls_last_scan_skipped', $progress['skipped'], false );
        update_option( 'bls_last_scan_confirmed_empty', (int) ( $recheck['confirmed_empty'] ?? 0 ), false );
        update_option( 'bls_last_scan_idx_vendor_pages', (int) ( $recheck['idx_vendor_pages'] ?? 0 ), false );
        update_option( 'bls_last_scan_offsite_redirects', (int) ( $recheck['offsite_redirects'] ?? 0 ), false );

        delete_option( self::QUEUE_OPTION );
        delete_option( self::PROGRESS_OPTION );

        return [
            'done'          => true,
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
    public function scan_post( WP_Post $post, bool $bypass_cache = false ): ?int {
        // The live page is the authority on what is on the page.
        //
        // Reading content from the database and rendering it here cannot match
        // what a visitor gets, and no amount of filtering fixes that. This runs
        // in an admin-AJAX request as a logged-in administrator, so every rule
        // that hides a block conditionally — block-visibility plugins,
        // membership and content gating, device or schedule targeting —
        // evaluates the wrong way. Blocks present in post_content rendered for
        // the scanner and were reported as buttons that are genuinely not on
        // the page.
        //
        // wp_remote_get() sends no cookies, so this fetch is anonymous and
        // those rules resolve exactly as they do for a visitor. What comes back
        // is what the public sees, which is the only defensible basis for a
        // report that says "this button is on your page".
        //
        // Costs one request per page and makes a scan considerably slower. That
        // is the right trade: a fast report that lists things which are not
        // there is worse than a slow one that does not.
        $buttons  = null;
        $verified = false;
        $this->live_confirmed_empty = false;

        if ( (bool) apply_filters( 'bls_scan_use_live_pages', true ) ) {
            $fetched = $this->fetch_live_page_html( (string) get_permalink( $post->ID ), $bypass_cache );

            if ( ! empty( $fetched['offsite_redirect'] ) ) {
                return null; // No content of its own — handled as a skipped page.
            }

            if ( ! empty( $fetched['html'] ) ) {
                $buttons = $this->extract_buttons( $this->strip_site_chrome( $fetched['html'] ) );
                foreach ( $buttons as $index => $btn ) {
                    $buttons[ $index ]['source'] = 'live page';
                }
                $verified = true;
            }
        }

        // Fetch failed or live scanning turned off — fall back to reading the
        // database, and record that these rows were NOT confirmed against the
        // live page so the report can say so rather than implying they were.
        if ( $buttons === null ) {
            $parts = $this->get_post_content_parts( $post );

            if ( empty( $parts ) ) {
                return null;
            }

            // Extracted per source so each row can name where it was read from,
            // and deduplicated across sources by markup — the same button
            // reached through two sources is still one button.
            $buttons = [];
            $seen    = [];
            foreach ( $parts as $part ) {
                foreach ( $this->extract_buttons( $part['html'] ) as $btn ) {
                    $key = md5( $btn['html'] );
                    if ( isset( $seen[ $key ] ) ) {
                        continue;
                    }
                    $seen[ $key ]   = true;
                    $btn['source']  = $part['source'];
                    $buttons[]      = $btn;
                }
            }
        }

        if ( empty( $buttons ) ) {
            // A live page that fetched cleanly and holds no buttons is settled;
            // nothing is gained by fetching it again in the recheck phase.
            $this->live_confirmed_empty = $verified;
            return null;
        }

        // On a WooCommerce product, no <button> or <input> is ever authored.
        // Every one belongs to the store or a payment provider: the quantity
        // stepper, Add To Cart, Apple Pay, Google Pay, Stripe Link, and the
        // payment sheets those open — which is where a run of "Close dialog"
        // controls came from. All of them work, none is editable from the
        // product, and none can be a broken link.
        //
        // Anchors are deliberately still collected. A link written into a
        // product description is real content and can rot like any other.
        if ( $post->post_type === 'product' ) {
            $buttons = array_values( array_filter( $buttons, static function ( array $btn ): bool {
                return ! in_array( $btn['button_type'], [ 'button_element', 'input_element', 'role_button' ], true );
            } ) );
        }

        // Drop anything the owner has already said is not on this page.
        $dismissed = $this->get_dismissed_buttons();
        if ( ! empty( $dismissed ) ) {
            $buttons = array_values( array_filter( $buttons, function ( array $btn ) use ( $post, $dismissed ): bool {
                return ! isset( $dismissed[ self::dismissal_key( (int) $post->ID, (string) $btn['html'] ) ] );
            } ) );
        }

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
                'source'        => (string) ( $btn['source'] ?? 'content' ),
                'context'       => (string) ( $btn['context'] ?? '' ),
                'never_linked'  => (int) ( $btn['never_linked'] ?? 0 ),
                'verified'      => $verified ? 1 : 0,
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
        // Tell BLS_Render_Injector which post is being rendered before any
        // the_content filter runs. Its filter normally identifies the page via
        // get_the_ID(), which returns false here (admin-AJAX, no main query),
        // so without this the scanner renders content WITHOUT the titles the
        // injector adds on a real page view — and then reports those buttons
        // as still missing a title on every single scan, forever.
        //
        // try/finally so the context is always cleared even if a third-party
        // filter throws; a stale context would attribute one post's injected
        // titles to the next post scanned.
        BLS_Render_Injector::set_context( $post->ID );

        try {
            return $this->gather_post_content( $post );
        } finally {
            BLS_Render_Injector::clear_context();
        }
    }

    /**
     * The same content, but kept in labelled pieces so every recorded row can
     * say which source it came from.
     *
     * Worth the extra plumbing: three separate times now, a row has been
     * reported for a button nobody could find on the page, and answering
     * "where did this come from" meant reasoning about which of four sources
     * it might have been. The sources are not equivalent — page content is
     * what a visitor sees, a block template is shared across every page using
     * it, and the custom-field fallback reads postmeta that may never be
     * rendered anywhere. A row that names its own origin ends that guessing.
     *
     * @return array<int, array{source: string, html: string}>
     */
    private function get_post_content_parts( WP_Post $post ): array {
        BLS_Render_Injector::set_context( $post->ID );

        try {
            return $this->gather_post_content_parts( $post );
        } finally {
            BLS_Render_Injector::clear_context();
        }
    }

    private function gather_post_content( WP_Post $post ): string {
        $parts = [];


        $main = trim( (string) apply_filters( 'the_content', $this->strip_vendor_shortcodes( $post->post_content ) ) );
        if ( $main !== '' ) {
            $parts[] = $main;
        }

        // WooCommerce short description.
        if ( $post->post_type === 'product' && ! empty( trim( (string) $post->post_excerpt ) ) ) {
            $parts[] = (string) apply_filters( 'the_content', $this->strip_vendor_shortcodes( $post->post_excerpt ) );
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

        // Strip header, footer, navigation and widget areas from everything
        // assembled above, not just from live-fetched HTML.
        //
        // A page's own post_content does not normally contain any of that, so
        // on a classic theme this is a no-op. On a block theme it is not: the
        // page's template renders the whole document, and any plugin can
        // append markup on the_content. Left unfiltered, one site credited
        // every single page with the header navigation, the social icons, the
        // footer's Privacy Policy and My Account links, and the "Made with by
        // imFORZA" credit — none authored on the page, none fixable from it,
        // and every one of them repeated site-wide.
        //
        // Removal only, no narrowing to the main region — see strip_site_chrome().
        return $this->strip_site_chrome( implode( "\n", $parts ), false );
    }

    /**
     * Same sources as gather_post_content(), kept apart and labelled.
     *
     * @return array<int, array{source: string, html: string}>
     */
    private function gather_post_content_parts( WP_Post $post ): array {
        $parts = [];

        $main = trim( (string) apply_filters( 'the_content', $this->strip_vendor_shortcodes( $post->post_content ) ) );
        if ( $main !== '' ) {
            $parts[] = [ 'source' => 'content', 'html' => $main ];
        }

        if ( $post->post_type === 'product' && trim( (string) $post->post_excerpt ) !== '' ) {
            $parts[] = [
                'source' => 'short description',
                'html'   => (string) apply_filters( 'the_content', $this->strip_vendor_shortcodes( $post->post_excerpt ) ),
            ];
        }

        $template_content = $this->get_block_theme_template_content( $post->ID );
        if ( ! empty( $template_content ) ) {
            $parts[] = [ 'source' => 'block template', 'html' => $template_content ];
        }

        // Only when nothing else produced content — see gather_post_content().
        if ( empty( $parts ) ) {
            $meta_content = $this->get_custom_field_content( $post->ID );
            if ( ! empty( $meta_content ) ) {
                $parts[] = [ 'source' => 'custom field', 'html' => $meta_content ];
            }
        }

        foreach ( $parts as $index => $part ) {
            $parts[ $index ]['html'] = $this->strip_site_chrome( $part['html'], false );
        }

        return array_values( array_filter( $parts, static function ( array $part ): bool {
            return trim( $part['html'] ) !== '';
        } ) );
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
                    $parts[] = (string) apply_filters( 'the_content', $this->strip_vendor_shortcodes( $value ) );
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

        $rendered = (string) apply_filters( 'the_content', $this->strip_vendor_shortcodes( $template_post->post_content ) );

        // Chrome-strip this one, unlike the page's own post_content. A block
        // theme's template is the WHOLE page — header, navigation, footer and
        // all — not just the body, and it is shared across every page
        // assigned to it. Without this, one nav or cart control in the
        // template gets recorded against each of those pages as if the author
        // had put it there, which is neither true nor fixable from the page.
        return $this->strip_site_chrome( $rendered );
    }

    /**
     * Remove Optima Express/iHomeFinder shortcodes from raw content
     * BEFORE it's ever rendered — confirmed present on cesipagano.com's
     * Home page, whose entire post_content is a bare
     * `[optima_express_search]` shortcode. This is a firmer signal than
     * guessing at the vendor's rendered CSS classes (tried in a prior
     * version, confirmed NOT to match anything on this site's real
     * output — the class/id filter in node_should_skip() stays as a
     * secondary defense, but this is the primary one now): the shortcode
     * TAG NAME in raw storage is something we've directly observed, not
     * assumed. Stripping it here means do_shortcode() never expands it
     * at all, so whatever markup it would have produced — button,
     * search form, or otherwise — simply never enters the content this
     * scanner sees, regardless of what that markup looks like once
     * rendered.
     */
    private function strip_vendor_shortcodes( string $content ): string {
        if ( $content === '' || ! str_contains( $content, '[' ) ) {
            return $content;
        }

        // Build the pattern from ONLY the vendor shortcodes actually
        // registered on this site, rather than matching every shortcode and
        // filtering in a callback. Narrower, faster, and it means a site
        // with no IDX plugin active skips the regex entirely.
        global $shortcode_tags;
        $vendor_tags = [];
        foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
            foreach ( self::VENDOR_SHORTCODE_PREFIXES as $prefix ) {
                if ( str_starts_with( (string) $tag, $prefix ) ) {
                    $vendor_tags[] = $tag;
                    break;
                }
            }
        }
        if ( empty( $vendor_tags ) ) {
            return $content;
        }

        // get_shortcode_regex() returns an UNDELIMITED pattern — core wraps
        // it in delimiters at every call site. Passing it straight to preg_*
        // makes the call fail and return null, and casting that null to
        // string silently produced an EMPTY document: every page containing
        // any shortcode scanned as having no content at all, and got listed
        // under "Needs Manual Check". That was the v1.4.15–1.4.20 behavior.
        $stripped = preg_replace( '/' . get_shortcode_regex( $vendor_tags ) . '/s', '', $content );

        // Belt and braces: a regex failure must never be allowed to blank
        // real content. Fall back to the original on any error.
        return is_string( $stripped ) ? $stripped : $content;
    }

    /**
     * Scan the homepage if it's a static front page. ("Latest Posts" mode
     * has no dedicated content to scan and is skipped — its individual
     * posts are already covered by the normal post-type loop.)
     *
     * @param array $progress Progress state (read for scanned_ids, not mutated here).
     * @return int Number of additional buttons found (0 if nothing to do).
     */
    /**
     * Scan the site's header, footer, navigation and widget areas — once.
     *
     * Recorded against post_id 0 with its own post type so it reads as one
     * shared thing rather than as belonging to any page, the same approach the
     * homepage theme-template pass already uses. Fetched from the front page
     * because every page carries the same furniture, so one request covers it.
     *
     * @return int Buttons/links found.
     */
    private function scan_site_chrome(): int {
        $fetched = $this->fetch_live_page_html( home_url( '/' ) );

        if ( ! empty( $fetched['offsite_redirect'] ) || empty( $fetched['html'] ) ) {
            return 0;
        }

        $chrome = $this->extract_site_chrome( $fetched['html'] );
        if ( trim( $chrome ) === '' ) {
            return 0;
        }

        $dismissed = $this->get_dismissed_buttons();
        $count     = 0;
        $seen      = [];

        foreach ( $this->extract_buttons( $chrome ) as $btn ) {
            $key = md5( $btn['html'] );
            if ( isset( $seen[ $key ] ) ) {
                continue; // A nav link repeated in a mobile menu is still one link.
            }
            $seen[ $key ] = true;

            if ( isset( $dismissed[ self::dismissal_key( 0, (string) $btn['html'] ) ] ) ) {
                continue;
            }

            BLS_Database::insert_result( [
                'post_id'       => 0,
                'post_title'    => __( 'Site header & footer (every page)', 'button-link-scanner' ),
                'post_type'     => 'site_chrome',
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
                'source'        => 'site chrome',
                'context'       => (string) ( $btn['context'] ?? '' ),
                'never_linked'  => (int) ( $btn['never_linked'] ?? 0 ),
                'verified'      => 1,
            ] );
            $count++;
        }

        return $count;
    }

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
            // Live fetch returns the whole page — drop site-wide chrome so
            // the footer/nav/sidebar isn't recorded as homepage content.
            $buttons = $this->extract_buttons( $this->strip_site_chrome( $html ) );
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
                    'source'        => 'live page (theme template)',
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
     * A page confirmed empty by a real render is DROPPED from the returned
     * list rather than kept on it. "Needs Manual Check" exists to surface
     * pages whose content this scanner might not be able to see — but if a
     * live render of the page produces no buttons at all, there is nothing
     * for anyone to check, and listing it is pure noise. Plenty of pages are
     * legitimately empty: a front page built entirely from widget areas
     * (common in real-estate themes like Agent One), or a placeholder whose
     * content lives in the theme. Only pages that could NOT be verified —
     * fetch failed, or past the recheck cap — stay on the list.
     *
     * @param array $skipped List of ['id'=>, 'title'=>, 'url'=>] entries.
     * @return array{buttons_found:int, still_skipped:array, confirmed_empty:int}
     *               buttons found, the entries that remain UNVERIFIED, and how
     *               many were confirmed genuinely empty and dropped.
     */
    private function recheck_skipped_pages( array $skipped ): array {
        $buttons_found    = 0;
        $still_skipped    = [];
        $confirmed_empty  = 0;
        $idx_vendor_pages = 0;
        $offsite_redirects = 0;
        $checked          = 0;
        $max_rechecks     = 20;

        foreach ( $skipped as $entry ) {
            // A WooCommerce product is never worth fetching live. Everything a
            // person writes on a product — the description and the short
            // description — is already read straight from the database, so if
            // both are empty there is nothing authored left to find. What the
            // live page would add is all template output: related products,
            // up-sells and cross-sells, breadcrumbs, category and tag links,
            // the tabs. strip_site_chrome() cannot help, because all of that
            // sits INSIDE the main content region rather than in a header or
            // footer. On a store with any number of thin products that turns
            // into hundreds of rows nobody can act on, each one attributed to
            // a product whose author never put it there.
            //
            // Tested before the recheck cap below, deliberately. A product
            // costs no request to resolve, so letting products consume the
            // budget meant a store's products could exhaust it and push real
            // pages into "needs manual check" — which is where four ciders
            // ended up on the first WooCommerce site this ran against.
            if ( ( $entry['type'] ?? '' ) === 'product' ) {
                $confirmed_empty++;
                continue;
            }

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

            $fetched = $this->fetch_live_page_html( $entry['url'] );

            // Redirects off-site (IDX wrapper pages point at the provider's
            // own subdomain). The page has no content of its own — scanning
            // whatever the other host returns would attribute a different
            // site's buttons to this page. See fetch_live_page_html().
            if ( ! empty( $fetched['offsite_redirect'] ) ) {
                $offsite_redirects++;
                continue;
            }

            $html = $fetched['html'];
            if ( empty( $html ) ) {
                $still_skipped[] = $entry; // Fetch failed — still unconfirmed either way.
                continue;
            }

            // Live fetch returns the whole page — drop site-wide chrome
            // first, otherwise every empty-content page contributes its own
            // duplicate copy of the same footer/sidebar links (and those
            // rows are guaranteed Auto-Fill dead ends). See
            // strip_site_chrome().
            $page_content = $this->strip_site_chrome( $html );

            // IDX vendor wrapper page: its content region is served by the
            // IDX provider from the provider's own subdomain, not authored
            // here. Skip it outright — don't record its buttons, don't list
            // it for manual review. Nothing on such a page is editable from
            // WordPress, so recording it only produces rows that can never
            // be fixed.
            //
            // Checked AFTER strip_site_chrome() deliberately: an IDX search
            // widget or tracking script in a site-wide header/footer would
            // otherwise match on every page and suppress the whole site.
            // This looks only at the page's own content region, and only for
            // pages that already had no scannable content in the database.
            if ( $this->is_idx_vendor_page( $page_content ) ) {
                $idx_vendor_pages++;
                continue;
            }

            $buttons = $this->extract_buttons( $page_content );
            if ( empty( $buttons ) ) {
                // Verified empty by an actual render — nothing here for
                // anyone to check, so drop it instead of reporting it.
                $confirmed_empty++;
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
                    'source'        => 'live page',
                ] );
                $buttons_found++;
            }
            // Found real content — drop it from the skipped list entirely.
        }

        return [
            'buttons_found'    => $buttons_found,
            'still_skipped'    => $still_skipped,
            'confirmed_empty'  => $confirmed_empty,
            'idx_vendor_pages' => $idx_vendor_pages,
            'offsite_redirects' => $offsite_redirects,
        ];
    }

    /**
     * True if this content region is IDX-vendor-served rather than authored
     * on this site — i.e. an IDX wrapper page.
     *
     * Matching is on the vendor's HOSTNAME appearing in the page's own
     * content region: IDX Broker and iHomeFinder serve their search,
     * results, and listing-detail pages from their own subdomains, and embed
     * scripts/iframes/links pointing there. Also catches the vendor's
     * attribution link ("IDX Broker" / "Powered by ...") those pages carry,
     * which is what previously showed up as an unfixable dead end.
     *
     * Callers must pass content that has already been through
     * strip_site_chrome() — an IDX widget or tracking script sitting in a
     * site-wide header or footer would otherwise match on every page and
     * suppress the entire site.
     */
    private function is_idx_vendor_page( string $content ): bool {
        if ( trim( $content ) === '' ) {
            return false;
        }

        $patterns = apply_filters( 'bls_idx_vendor_host_patterns', self::IDX_VENDOR_HOST_PATTERNS );
        foreach ( (array) $patterns as $pattern ) {
            if ( stripos( $content, (string) $pattern ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch a single page's live, rendered HTML. Scoped to the bounded
     * recheck above (max 20 calls per full scan) — see that method's
     * docblock for why this is safe despite the "no HTTP requests"
     * principle elsewhere in this class.
     */
    private function fetch_live_page_html( string $url, bool $bypass_cache = false ): array {
        if ( empty( $url ) ) {
            return [ 'html' => '', 'offsite_redirect' => false ];
        }

        // A page cache serves this fetch the same stale HTML it serves anyone
        // else, and that is how a title applied ten minutes ago can still be
        // reported as missing: the content has it, the cached copy does not,
        // and the scanner believes the cached copy because the live page is
        // supposed to be the authority.
        //
        // The headers ask politely and most caches honour them. A single-page
        // re-scan also adds a query argument, which defeats the ones that
        // don't, since almost every cache keys on the full URL. That argument
        // is deliberately NOT added on a full scan — it would multiply cache
        // entries across every page of the site.
        if ( $bypass_cache ) {
            $url = add_query_arg( 'bls-fresh', (string) time(), $url );
        }

        // Space the requests out. A scan now fetches every page, and firing
        // those back to back is what earns a 429 or a firewall block from the
        // host — self-inflicted, and indistinguishable in the results from a
        // genuine problem. The link checker learned this the same way in 1.33.
        static $last_fetch = 0.0;
        $gap = (float) apply_filters( 'bls_scan_fetch_gap', 0.3 );
        $since = microtime( true ) - $last_fetch;
        if ( $last_fetch > 0.0 && $since < $gap ) {
            usleep( (int) ( ( $gap - $since ) * 1000000 ) );
        }
        $last_fetch = microtime( true );

        // Probe first WITHOUT following redirects.
        //
        // wp_remote_get() follows up to 5 redirects by default, and IDX
        // wrapper pages routinely 301 straight to the provider's own search
        // subdomain (e.g. an "Email Update Signup" page redirecting to
        // search.<site>.com/idx/usersignup). Following that silently returns
        // the OTHER host's HTML, which this scanner then parsed and recorded
        // as though it were the WordPress page's own content — attributing
        // buttons that exist on a completely different site to a local page,
        // where they could never be found again or edited.
        //
        // A redirect to another host means the page has no content of its
        // own to scan, full stop. Report it so the caller can skip it.
        $probe = wp_remote_get( $url, [
            'timeout'     => 10,
            'redirection' => 0,
            'user-agent'  => 'WordPress/BLS-Scanner (redirect probe)',
            'sslverify'   => apply_filters( 'bls_fetch_sslverify', true ),
            'headers'     => self::NO_CACHE_HEADERS,
        ] );

        if ( ! is_wp_error( $probe ) ) {
            $code = (int) wp_remote_retrieve_response_code( $probe );
            if ( $code >= 300 && $code < 400 ) {
                $location = (string) wp_remote_retrieve_header( $probe, 'location' );
                if ( $location !== '' && $this->is_offsite_url( $location ) ) {
                    return [ 'html' => '', 'offsite_redirect' => true ];
                }
            }
        }

        // Same-host (or no) redirect — safe to fetch normally and let
        // WordPress follow it.
        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/BLS-Scanner (manual-check recheck)',
            'sslverify'  => apply_filters( 'bls_fetch_sslverify', true ),
            'headers'    => self::NO_CACHE_HEADERS,
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [ 'html' => '', 'offsite_redirect' => false ];
        }

        return [ 'html' => (string) wp_remote_retrieve_body( $response ), 'offsite_redirect' => false ];
    }

    /**
     * True if this URL points at a different host than the site itself.
     * A protocol-relative or root-relative target counts as same-site.
     * "www." is ignored on both sides.
     */
    private function is_offsite_url( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( $host === '' ) {
            return false; // Relative redirect — still this site.
        }

        $home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        return preg_replace( '/^www\./', '', $host ) !== preg_replace( '/^www\./', '', $home );
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
     * Remove site-wide chrome (header, footer, nav, sidebar) from a
     * LIVE-FETCHED full page before extracting buttons from it.
     *
     * Only the two live-fetch paths need this — scan_homepage() and
     * recheck_skipped_pages() request a real rendered URL, so what comes
     * back is the ENTIRE page: theme header, nav menus, footer widgets,
     * sidebars, everything. The normal DB path (get_post_content()) never
     * sees any of that, since post_content holds only the page's own
     * authored content.
     *
     * Without this, every page that fell back to a live fetch contributed
     * a fresh copy of the same footer/sidebar links — a footer phone
     * number, a newsletter signup in a widget — producing one result row
     * per page for what is really ONE link the site owner maintains in
     * one place. Beyond inflating counts and the "missing title" total,
     * those rows are guaranteed Auto-Fill dead ends: a footer widget's
     * markup isn't in any post's content, excerpt, template, or meta, so
     * there's nothing writable to attach a title to and the diagnostic
     * correctly reports "text found: no, href found: no" every time.
     *
     * Stripping chrome here means site-wide furniture is simply not
     * treated as page content — the same principle already applied to
     * nav toggles, WooCommerce UI, and Gravity Forms controls in
     * node_should_skip(), just at the region level instead of per-node.
     */
    /**
     * The opposite of strip_site_chrome(): keep only the header, footer,
     * navigation and widget areas, discarding the page's own content.
     *
     * Chrome was excluded from page scans in 1.42 for a good reason — it was
     * being credited to every single page, so one footer produced a row per
     * page across the whole site. But excluding it entirely meant the footer's
     * links were never scanned at all, and so never got titles when Auto-Fill
     * ran. Both complaints are right, and the resolution is the one already
     * asked for about the footer phone number: read it ONCE, fix it once.
     *
     * So the chrome is scanned as its own single entity rather than per page.
     */
    private function extract_site_chrome( string $html ): string {
        if ( trim( $html ) === '' ) {
            return '';
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );

        // Remove the main content region, leaving the furniture around it.
        foreach ( [ '//main', '//*[@role="main"]', '//*[@id="content"]', '//*[@id="primary"]', '//*[@id="main"]' ] as $query ) {
            $found = $xpath->query( $query );
            if ( $found && $found->length > 0 ) {
                $node = $found->item( 0 );
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
                break;
            }
        }

        $out = (string) $dom->saveHTML();
        $out = preg_replace( '/^.*<body>/s', '', $out );
        $out = preg_replace( '/<\/body>.*$/s', '', $out );
        $out = preg_replace( '/^<\?xml[^>]+>\n?/', '', $out );

        return trim( (string) $out );
    }

    private function strip_site_chrome( string $html, bool $narrow_to_main = true ): string {
        if ( trim( $html ) === '' ) {
            return '';
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );

        // Locate the main content region, if the theme marks one. Used two
        // ways: to narrow what's returned, and — more importantly — to
        // PROTECT it and its ancestors from removal below. Without that
        // guard, one over-broad pattern can silently delete the entire
        // page: Genesis wraps content in <div class="content-sidebar-wrap">,
        // which a naive "does the class contain 'sidebar'" test matches,
        // taking the real content down with it.
        $main = null;
        foreach ( [ '//main', '//*[@role="main"]', '//*[@id="content"]', '//*[@id="primary"]', '//*[@id="main"]' ] as $query ) {
            $found = $xpath->query( $query );
            if ( $found && $found->length > 0 ) {
                $main = $found->item( 0 );
                break;
            }
        }

        $protected = [];
        for ( $node = $main; $node !== null; $node = $node->parentNode ) {
            $protected[] = $node;
        }

        $remove = [];

        // Structural HTML5 landmarks — unambiguous by definition.
        foreach ( $xpath->query( '//header | //footer | //nav | //aside' ) as $node ) {
            $remove[] = $node;
        }

        // ARIA landmarks, for themes that use <div role="..."> instead.
        foreach ( $xpath->query( '//*[@role="banner" or @role="contentinfo" or @role="navigation" or @role="complementary" or @role="search"]' ) as $node ) {
            $remove[] = $node;
        }

        // Class/id naming conventions, for themes predating both of the
        // above. Matched per whitespace-separated TOKEN, not as a raw
        // substring — "content-sidebar-wrap" must not match "sidebar",
        // while "sidebar", "sidebar-primary", and "primary-sidebar" all
        // must.
        foreach ( $xpath->query( '//*[@class or @id]' ) as $node ) {
            $tokens = preg_split(
                '/\s+/',
                strtolower( $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'id' ) ),
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ( $this->tokens_match_chrome( (array) $tokens ) ) {
                $remove[] = $node;
            }
        }

        // Collected first, removed after — mutating during an active
        // DOMNodeList iteration skips nodes.
        foreach ( $remove as $node ) {
            if ( in_array( $node, $protected, true ) ) {
                continue; // Contains the main content region — never remove.
            }
            if ( $node->parentNode ) {
                $node->parentNode->removeChild( $node );
            }
        }

        // With chrome gone, prefer returning just the main region when one
        // was identified — anything left outside it is site furniture the
        // patterns above didn't happen to name.
        //
        // Skipped for content assembled from the database. There, narrowing
        // is a liability rather than a help: a page whose own content happens
        // to contain a <div id="content"> would have everything outside that
        // div discarded, which for a page's own body is real content loss.
        // Removing the chrome regions is wanted in both cases; keeping only
        // the main region only makes sense for a whole fetched document.
        if ( $narrow_to_main && $main !== null ) {
            $inner = '';
            foreach ( $main->childNodes as $child ) {
                $inner .= (string) $dom->saveHTML( $child );
            }
            return trim( $inner );
        }

        $out = (string) $dom->saveHTML();
        $out = preg_replace( '/^.*<body>/s', '', $out );
        $out = preg_replace( '/<\/body>.*$/s', '', $out );
        $out = preg_replace( '/^<\?xml[^>]+>\n?/', '', $out );

        return trim( (string) $out );
    }

    /**
     * True if any single class/id token identifies a chrome region.
     *
     * Token-level, not substring: a token matches when it equals a
     * pattern, or is a hyphenated extension of one on either side
     * ("sidebar-primary", "primary-sidebar"). This is what keeps
     * compound CONTENT wrappers like Genesis's "content-sidebar-wrap"
     * from being mistaken for the sidebar itself.
     */
    private function tokens_match_chrome( array $tokens ): bool {
        foreach ( $tokens as $token ) {
            foreach ( self::SITE_CHROME_PATTERNS as $pattern ) {
                if ( $token === $pattern
                     || str_starts_with( $token, $pattern . '-' )
                     || str_ends_with( $token, '-' . $pattern ) ) {
                    return true;
                }
            }
        }
        return false;
    }

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
            if ( $this->is_script_control( $node ) ) continue;
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
    /**
     * Widget containers whose entire contents are plugin UI.
     *
     * Kept separate from SKIP_CLASS_PATTERNS, and matched against ancestors,
     * because some controls are a container plus its children rather than one
     * element. The Events Calendar's "Subscribe to calendar" is the case that
     * prompted this: the toggle is recognised on its own as a control, but the
     * six links it reveals — Google Calendar, iCalendar, Outlook 365, Outlook
     * Live, Export .ics, Export Outlook .ics — are ordinary anchors, and each
     * was being reported as a link missing an SEO title.
     *
     * Deliberately a short, specific list of container names rather than
     * reusing the element-level patterns. Walking ancestors multiplies the
     * reach of anything loose, and an over-broad match here would silently
     * delete real content — which is precisely how 1.4.16 removed most of a
     * page by matching "sidebar" inside "content-sidebar-wrap".
     */
    const NOISE_CONTAINER_PATTERNS = [
        // Jetpack's sharing block, so anything it renders goes with it.
        'sharedaddy',
        'sd-sharing',
        // Theme post meta — author, date, categories, tags. Generated per post
        // by the template rather than written by anyone, so one row per post
        // per item, none of it fixable from the post.
        'entry-meta',
        // Breadcrumb trails, same reasoning: 239 posts each contributed a
        // "Blog" link from theirs.
        'breadcrumb',
        'tribe-events-c-subscribe-dropdown',
        'tribe-events-c-nav',
        'woocommerce-tabs',
        'wc-tabs',
        'related products',
        'up-sells',
        'cross-sells',
    ];

    /** How far up to look for a noise container. Bounded so one match cannot reach the whole document. */
    const NOISE_ANCESTOR_DEPTH = 6;

    private function node_should_skip( DOMElement $node ): bool {
        $class = strtolower( $node->getAttribute( 'class' ) );
        $id    = strtolower( $node->getAttribute( 'id' ) );

        // Hidden by something stated in the HTML — see is_hidden_markup().
        if ( $this->is_hidden_markup( $node ) ) {
            return true;
        }

        // Inside markup the browser never renders as-is.
        //
        // <template> holds a blueprint that only becomes content when script
        // clones it — a modal, a popup, a repeater row. <noscript> renders only
        // when scripting is off. Neither is on the page, but DOMDocument parses
        // their children as ordinary nodes, so a button sitting in a modal
        // template was reported against the page as though it were visible.
        // Walked to the root rather than depth-capped: unlike a class-name
        // match this cannot over-reach, because content inside these elements
        // is never rendered in place, at any nesting depth.
        for ( $up = $node->parentNode; $up instanceof DOMElement; $up = $up->parentNode ) {
            if ( in_array( strtolower( $up->nodeName ), [ 'template', 'noscript', 'script' ], true ) ) {
                return true;
            }
        }

        // Inside a plugin widget whose whole contents are its own UI.
        $ancestor = $node->parentNode;
        for ( $depth = 0; $depth < self::NOISE_ANCESTOR_DEPTH && $ancestor instanceof DOMElement; $depth++ ) {
            $ancestor_class = strtolower( $ancestor->getAttribute( 'class' ) . ' ' . $ancestor->getAttribute( 'id' ) );
            foreach ( self::NOISE_CONTAINER_PATTERNS as $pattern ) {
                if ( $ancestor_class !== ' ' && str_contains( $ancestor_class, $pattern ) ) {
                    return true;
                }
            }
            $ancestor = $ancestor->parentNode;
        }
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

        // Product Collection tile links are deliberately NOT skipped.
        //
        // 1.50 skipped them, on the reasoning that a grid built from live
        // products cannot contain a rotted URL and that there is no stored
        // anchor to write a title to. The first half is true and the second was
        // wrong: these are linked product images, they should carry a title,
        // and BLS_Render_Injector exists precisely for markup generated at
        // render time with nothing in post_content to edit. Auto-Fill already
        // routes anything it cannot find in stored content down that path.
        //
        // So they stay in the report. Add-to-cart controls inside the same
        // tiles are still skipped below, by data-product_id — those genuinely
        // have nothing to fix.

        // Theme navigation that declares itself in the rel attribute.
        //
        // rel is semantic HTML, not a class name a theme can rename, which
        // makes it a far better signal than anything guessed at:
        //   tag       category and tag links under a post
        //   prev/next previous/next post navigation
        //   bookmark  a post's permalink in an archive listing
        //
        // On collegestationhomes.com these were 2,896 of the 5,753 rows that
        // survived the 1.63 filters — 2,328 category links alone, one per tag
        // per post. Every one is generated by the theme from the post list, so
        // none is authored on the page it appears on and none can be given a
        // title there. That is exactly why Auto-Fill wrote 0 titles to content
        // and fell back to 602 render-time injections: there was nothing in
        // post_content to write to, because the theme makes these at render.
        //
        // The .more-link class covers the excerpt "Read more" link, which WP
        // core generates and gives no rel.
        $rel = preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
        foreach ( [ 'tag', 'prev', 'next', 'bookmark' ] as $nav_rel ) {
            if ( in_array( $nav_rel, (array) $rel, true ) ) {
                return true;
            }
        }

        // Links into wp-admin or the login screen.
        //
        // No visitor can follow one, so it is not content and cannot be a
        // broken link. These come from plugin credit lines — Advanced iFrame
        // renders "powered by Advanced iFrame" pointing at its own settings
        // page, inside page content, and it was reported as a link needing an
        // SEO title. It even got one, applied at render time on every page
        // load, for a link only an administrator can see.
        $href = strtolower( trim( $node->getAttribute( 'href' ) ) );
        if ( $href !== '' && ( str_contains( $href, '/wp-admin/' ) || str_contains( $href, 'wp-login.php' ) ) ) {
            return true;
        }

        // WooCommerce add-to-cart, identified by data rather than by class.
        //
        // The class names differ between classic Woo (add_to_cart_button,
        // ajax_add_to_cart) and Woo Blocks (wc-block-components-product-button),
        // and a theme can restyle either, so matching names means chasing every
        // variation and missing the next one — 1.4.14 was exactly that mistake.
        // These two signals are structural instead: Woo stamps the product id
        // onto every add-to-cart control, and the non-ajax form of the link
        // always carries add-to-cart in its query string. Neither is a
        // navigational link and neither is editable from the page.
        if ( $node->hasAttribute( 'data-product_id' ) || $node->hasAttribute( 'data-product_sku' ) ) {
            return true;
        }
        if ( str_contains( strtolower( $node->getAttribute( 'href' ) ), 'add-to-cart=' ) ) {
            return true;
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
        // An <a> with no href attribute at all is a different thing from one
        // whose href was emptied: it is a button block that was placed and
        // never linked. Worth distinguishing, because "missing link" reads as
        // "this used to work" when the truth is "this was never finished".
        $never_linked = ! $node->hasAttribute( 'href' );
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
            'never_linked'  => $never_linked,
            'context'       => $this->get_node_context( $node ),
            'button_type'   => $this->detect_button_type( $node ),
        ];
    }

    /**
     * True for a <button> that exists to drive JavaScript rather than to go
     * anywhere — an accordion toggle, a tab switcher, a quantity control, a
     * modal opener, a menu hamburger.
     *
     * `type="button"` means precisely "does not submit"; that is the whole
     * reason the attribute exists. Such an element has no destination and
     * never can have one, so reporting it as a button with a missing link or
     * a missing SEO title is a false positive by definition. On a WooCommerce
     * product page this produced rows for the "NUTRITION FACTS" and
     * "INGREDIENTS" accordion toggles, and elsewhere for controls whose only
     * label was "0" — a counter.
     *
     * A <button> wrapped in an <a> is excluded from this: that one genuinely
     * navigates, and the anchor is where its destination lives.
     */
    /**
     * Class tokens that mean "present in the markup, not shown to the reader".
     *
     * Matched as whole tokens, never substrings — "hidden" must not match
     * "hidden-field-wrapper" the way 1.4.16's "sidebar" matched
     * "content-sidebar-wrap".
     */
    const HIDDEN_CLASS_TOKENS = [
        'screen-reader-text',
        'visually-hidden',
        'visuallyhidden',
        'sr-only',
        'd-none',
        'is-hidden',
        'hidden',
    ];

    /**
     * True when this element is hidden by something visible in the HTML itself.
     *
     * Covers the honest cases only: a `hidden` attribute, an inline
     * display:none or visibility:hidden, aria-hidden, and the handful of
     * standard utility classes above — on the element or on any ancestor, since
     * hiding a container hides everything inside it.
     *
     * What this deliberately does NOT claim to cover: anything hidden by an
     * external stylesheet or a media query. A mobile-only block, a collapsed
     * accordion panel, a carousel slide off to one side — all are ordinary
     * markup here and only a real browser applying real CSS can tell they are
     * not on screen. That gap is why "Not on page" exists, and why a scanner
     * reading HTML can be truthful about what a server sent without being able
     * to promise what a reader saw.
     */
    private function is_hidden_markup( DOMElement $node ): bool {
        for ( $el = $node; $el instanceof DOMElement; $el = $el->parentNode ) {
            if ( $el->hasAttribute( 'hidden' ) ) {
                return true;
            }

            if ( strtolower( trim( $el->getAttribute( 'aria-hidden' ) ) ) === 'true' ) {
                return true;
            }

            $style = strtolower( preg_replace( '/\s+/', '', $el->getAttribute( 'style' ) ) );
            if ( str_contains( $style, 'display:none' ) || str_contains( $style, 'visibility:hidden' ) ) {
                return true;
            }

            $tokens = preg_split( '/\s+/', strtolower( $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
            foreach ( (array) $tokens as $token ) {
                if ( in_array( $token, self::HIDDEN_CLASS_TOKENS, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_script_control( DOMElement $node ): bool {
        $parent = $node->parentNode;
        if ( $parent instanceof DOMElement && strtolower( $parent->nodeName ) === 'a' ) {
            return false; // Genuinely navigates — the anchor holds its destination.
        }

        $type = strtolower( trim( $node->getAttribute( 'type' ) ) );
        if ( $type === 'button' || $type === 'reset' ) {
            return true;
        }

        // Everything below exists because testing type="button" alone was not
        // enough. A bare <button> with no type attribute defaults to submit
        // per the HTML spec, and dialog-close buttons routinely omit it — so a
        // pile of "Close dialog" controls were still being reported as buttons
        // missing a link. These tests describe what the element *is*, which it
        // cannot opt out of, rather than an attribute it is free to leave off.

        // Drives other UI rather than going anywhere. A thing that expands a
        // panel, opens a menu or controls another element by id is a control by
        // its own declaration.
        foreach ( [ 'aria-expanded', 'aria-haspopup', 'aria-controls', 'data-toggle', 'data-bs-toggle' ] as $attr ) {
            if ( $node->hasAttribute( $attr ) ) {
                return true;
            }
        }

        // Named by its aria-label as a control. Only matched when the element
        // has no visible text of its own, so a real CTA that happens to be
        // labelled "Close out your order" is unaffected.
        if ( trim( $node->textContent ) === '' ) {
            $aria = strtolower( trim( $node->getAttribute( 'aria-label' ) ) );
            foreach ( [ 'close', 'dismiss', 'toggle', 'menu', 'expand', 'collapse', 'open', 'previous', 'next', 'play', 'pause', 'search' ] as $word ) {
                if ( $aria !== '' && str_contains( $aria, $word ) ) {
                    return true;
                }
            }
        }

        // No readable label at all. An authored call to action always has
        // words; an icon-only control or a bare counter does not. This is what
        // catches the ones whose entire label was "0" — a quantity counter —
        // and the icon-only toggles that reach here with nothing to show.
        $visible = trim( $node->textContent );
        if ( $visible === '' || ! preg_match( '/\p{L}/u', $visible ) ) {
            return true;
        }

        // Submits, but to nowhere in particular: a form with no action posts
        // back to whatever URL the visitor is already on. There is no
        // destination to record, nothing that can 404, and nothing to fix — so
        // reporting it as a button missing its link is wrong.
        //
        // This is the age-verification gate on highlimbcider.com:
        //   <button type="submit" name="age_gate[confirm]" value="1">Yes</button>
        // inside an action-less form. Yes and No were both reported as buttons
        // with no URL set. The same shape covers consent banners, filter and
        // sort controls, login and comment forms.
        //
        // A form that DOES carry an action keeps its destination — that is the
        // PayPal case describe_button_element() handles, and it is a real,
        // checkable URL.
        $form = $node->parentNode;
        while ( $form instanceof DOMElement && strtolower( $form->nodeName ) !== 'form' ) {
            $form = $form->parentNode;
        }
        $form_action = $form instanceof DOMElement ? trim( $form->getAttribute( 'action' ) ) : '';
        if ( $form_action === '' ) {
            return true;
        }

        return false;
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
        } elseif ( $type === 'submit' ) {
            // Same PayPal-style case as describe_input(): a <button> with
            // no <a> wrapper submits its enclosing <form> — that form's
            // action attribute is the real, checkable destination.
            //
            // Only for type="submit" (which is also the default when the
            // attribute is absent). type="button" was included here and
            // should never have been: it explicitly does NOT submit, so the
            // form's action is not its destination. On a WooCommerce product
            // page every control inside the add-to-cart form inherited that
            // form's action — the product's own URL — and was reported as a
            // button pointing at the page it was already on.
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
            'context'       => $this->get_node_context( $node ),
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

    /**
     * The words immediately before this element on the page.
     *
     * A row can say what an element is, where it was read from, and what its
     * markup looks like, and still leave someone unable to find the thing —
     * which is where two unlinked Gutenberg buttons on highlimbcider.com left
     * us, reported four times as not existing. Nearby text is searchable: paste
     * it into the page or the editor and the button is either right there or it
     * genuinely is not.
     *
     * Walks up until an ancestor carries some text besides the element's own,
     * so a button wrapped in three layers of block divs still reports the
     * paragraph or heading above it rather than nothing.
     */
    private function get_node_context( DOMElement $node ): string {
        $own = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );

        for ( $up = $node->parentNode, $depth = 0; $up instanceof DOMElement && $depth < 5; $up = $up->parentNode, $depth++ ) {
            // Via markup rather than textContent, replacing tags with spaces.
            // textContent concatenates block text with no separator, so a
            // heading followed by a paragraph comes out as "Our CidersMade in
            // small batches" — harder to search for than the thing it describes.
            $markup = '';
            foreach ( $up->childNodes as $child ) {
                $markup .= (string) $up->ownerDocument->saveHTML( $child );
            }
            $text = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/<[^>]*>/', ' ', $markup ) ) );

            if ( $own !== '' ) {
                $text = trim( str_replace( $own, ' ', $text ) );
                $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
            }

            if ( $text !== '' ) {
                // Tail end, not the start: the words nearest the element are
                // the ones that place it.
                if ( mb_strlen( $text ) > 160 ) {
                    $text = '…' . mb_substr( $text, -160 );
                }
                return $text;
            }
        }

        return '';
    }

    private function outer_html( DOMElement $node ): string {
        $doc = new DOMDocument();
        $doc->appendChild( $doc->importNode( $node, true ) );
        return trim( $doc->saveHTML() );
    }

    // -------------------------------------------------------------------------
    // Post type discovery
    // -------------------------------------------------------------------------

    /**
     * Buttons the site owner has said are not on the page.
     *
     * The scanner renders content by calling the_content inside an admin-AJAX
     * request as a logged-in administrator. That is not the environment a
     * visitor loads the page in, and nothing here can make it one. Any rule
     * that hides a block conditionally — a block-visibility plugin, membership
     * or content gating, device or schedule targeting — evaluates differently
     * there, so a block present in post_content renders for the scanner while a
     * rule removes it on the live page.
     *
     * That produced two unlinked buttons on highlimbcider.com reported four
     * times as not existing. There is no reliable way to detect it from inside
     * a scan, so the report needs to accept being told: dismissed once, keyed
     * on the page and the exact markup, and it stays gone through re-scans.
     *
     * Keyed on markup rather than button text so dismissing one leaves an
     * identically-labelled real button elsewhere alone, and so editing the
     * block brings it back for review rather than hiding the new version.
     *
     * @return array<string, true>
     */
    private function get_dismissed_buttons(): array {
        $dismissed = get_option( self::DISMISSED_OPTION, [] );

        return is_array( $dismissed ) ? $dismissed : [];
    }

    /** Key identifying one dismissed button: this page, this exact markup. */
    public static function dismissal_key( int $post_id, string $button_html ): string {
        return $post_id . ':' . md5( trim( $button_html ) );
    }

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

        // The Events Calendar's venues and organizers are records, not pages:
        // an address, a phone number, a website field. They are registered
        // public so they have permalinks, which is why they were being scanned
        // — but nobody authors buttons on them, and their post_content is
        // normally empty, so each one got fetched live and credited with
        // whatever site-wide furniture the page happened to render. On
        // highlimbcider.com that meant the age-verification modal's "Yes" and
        // "No" were recorded against a venue.
        //
        // Events themselves stay in scope. An event description is authored
        // content and can hold real links.
        $exclude = array_merge( $exclude, [ 'tribe_venue', 'tribe_organizer' ] );

        // IDX Broker wrapper pages.
        //
        // The vendor-host check already skipped 39 of these on
        // obxlistings.com, but that check reads a page's content and only
        // catches the ones whose markup names the provider's host. It missed
        // 777 others, which contributed 6,897 of that site's 7,933 rows — 87%
        // of the entire report, including 2,690 of its 2,693 "styled buttons".
        //
        // Post type is a firmer signal than anything in the markup: these
        // posts exist only as containers for content IDX Broker serves from
        // its own subdomain. Every link on them pointed at
        // realestate.obxlistings.com/idx/..., not one of them is authored in
        // WordPress, and no title written here would ever reach the page. They
        // are the same case as tribe_venue above — registered public so they
        // have permalinks, but not somewhere anybody authors buttons.
        //
        // idx-wrapper is included defensively: idx_page is the one confirmed
        // from a live site, and IDX Broker has used more than one name for its
        // wrapper type across versions. Excluding a type that does not exist
        // costs nothing.
        $exclude = array_merge( $exclude, [ 'idx_page', 'idx-wrapper' ] );

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
