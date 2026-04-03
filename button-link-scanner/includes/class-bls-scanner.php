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
 * Content sources (tried in order, merged):
 *  1. apply_filters('the_content', post_content)  – handles shortcodes
 *  2. Elementor _elementor_data meta              – page-builder widgets
 *  3. HTTP fetch of the rendered permalink         – final fallback
 *
 * The homepage is always scanned explicitly, even when WordPress is
 * set to display "Latest Posts" (no static front page).
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
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run a full site scan.
     *
     * @return array Summary stats.
     */
    public function run_full_scan(): array {
        BLS_Database::clear_results();

        $post_types       = $this->get_scannable_post_types();
        $excluded_ids     = $this->get_excluded_post_ids();
        $total            = 0;
        $scanned_post_ids = [];

        foreach ( $post_types as $post_type ) {
            $paged = 1;
            do {
                $query = new WP_Query( [
                    'post_type'      => $post_type,
                    'post_status'    => 'publish',
                    'posts_per_page' => 50,
                    'paged'          => $paged,
                    'no_found_rows'  => false,
                    'fields'         => 'all',
                    'post__not_in'   => $excluded_ids,
                ] );

                foreach ( $query->posts as $post ) {
                    $found  = $this->scan_post( $post );
                    $total += $found;
                    $scanned_post_ids[] = $post->ID;
                }

                $paged++;
            } while ( $paged <= $query->max_num_pages );
        }

        // Always scan the homepage explicitly.
        $total += $this->scan_homepage( $scanned_post_ids );

        update_option( 'bls_last_scan_total', $total );
        update_option( 'bls_last_scan_time',  current_time( 'mysql' ) );

        return [ 'buttons_found' => $total ];
    }

    /**
     * Scan a single WP_Post object and persist button data.
     *
     * @return int Number of buttons found.
     */
    public function scan_post( WP_Post $post ): int {
        $content = $this->get_post_content( $post );

        if ( empty( trim( $content ) ) ) {
            return 0;
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
     * Collect the best-available HTML for a post, merging multiple sources.
     *
     * Priority:
     *  1. apply_filters('the_content') – handles Gutenberg, shortcodes
     *  2. Elementor JSON meta          – Elementor page builder
     *  3. HTTP fetch of the permalink  – any other builder / truly empty content
     */
    private function get_post_content( WP_Post $post ): string {
        $parts = [];

        // Source 1: WordPress content pipeline.
        $wp_content = apply_filters( 'the_content', $post->post_content );
        if ( ! empty( trim( $wp_content ) ) ) {
            $parts[] = $wp_content;
        }

        // Source 2: Elementor – parse _elementor_data JSON.
        $elementor_html = $this->get_elementor_html( $post->ID );
        if ( ! empty( $elementor_html ) ) {
            $parts[] = $elementor_html;
        }

        // Source 3: HTTP fetch – fires only when the above sources yielded
        // no useful content (empty post_content AND no Elementor data).
        if ( empty( $parts ) ) {
            $url      = get_permalink( $post->ID );
            $fetched  = $this->fetch_rendered_html( $url );
            if ( ! empty( $fetched ) ) {
                $parts[] = $fetched;
            }
        }

        return implode( "\n", $parts );
    }

    /**
     * Scan the homepage, regardless of whether it is a static page or
     * the "Latest Posts" index (which has no post_id to query).
     *
     * @param int[] $already_scanned Post IDs already processed by run_full_scan.
     * @return int Number of additional buttons found.
     */
    private function scan_homepage( array $already_scanned ): int {
        $show_on_front = get_option( 'show_on_front', 'posts' );
        $page_on_front = (int) get_option( 'page_on_front', 0 );

        if ( $show_on_front === 'page' && $page_on_front > 0 ) {
            // Static front page – only re-scan if it was missed (e.g. content
            // was empty in the main loop and we can now try HTTP fetch).
            if ( in_array( $page_on_front, $already_scanned, true ) ) {
                return 0; // Already handled.
            }
            $post = get_post( $page_on_front );
            if ( $post ) {
                return $this->scan_post( $post );
            }
        }

        // "Latest Posts" homepage – no static page, must fetch via HTTP.
        $html = $this->fetch_rendered_html( home_url( '/' ) );
        if ( empty( $html ) ) {
            return 0;
        }

        $buttons = $this->extract_buttons( $html );
        $count   = 0;

        foreach ( $buttons as $btn ) {
            BLS_Database::insert_result( [
                'post_id'       => 0,
                'post_title'    => __( 'Home Page', 'button-link-scanner' ),
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
            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Elementor support
    // -------------------------------------------------------------------------

    /**
     * Extract button HTML from Elementor's _elementor_data meta.
     * Returns a synthetic HTML string that the standard DOMDocument
     * parser can then process normally.
     */
    private function get_elementor_html( int $post_id ): string {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) {
            return '';
        }

        $elements = json_decode( $raw, true );
        if ( ! is_array( $elements ) ) {
            return '';
        }

        $html = '';
        $this->walk_elementor_elements( $elements, $html );
        return $html;
    }

    /**
     * Recursively walk Elementor element tree and synthesise button HTML.
     */
    private function walk_elementor_elements( array $elements, string &$html ): void {
        foreach ( $elements as $element ) {
            $widget_type = $element['widgetType'] ?? '';
            $settings    = $element['settings']   ?? [];

            switch ( $widget_type ) {

                case 'button':
                    // Standard Elementor Button widget.
                    $text   = sanitize_text_field( $settings['text']          ?? 'Button' );
                    $url    = esc_url_raw(          $settings['link']['url']   ?? '' );
                    $new_tab = ! empty( $settings['link']['is_external'] ) ? ' target="_blank"' : '';
                    $class  = 'elementor-button';
                    $html  .= '<a class="' . $class . '" href="' . esc_attr( $url ?: '#' ) . '"' . $new_tab . '>'
                            . esc_html( $text ) . '</a>' . "\n";
                    break;

                case 'icon-box':
                case 'image-box':
                    // These widgets often have a CTA link.
                    $link_url = esc_url_raw( $settings['link']['url'] ?? '' );
                    $btn_text = sanitize_text_field( $settings['button_text'] ?? $settings['title']['text'] ?? '' );
                    if ( $link_url && $btn_text ) {
                        $html .= '<a class="elementor-button" href="' . esc_attr( $link_url ) . '">'
                               . esc_html( $btn_text ) . '</a>' . "\n";
                    }
                    break;

                case 'call-to-action':
                    $btn_url  = esc_url_raw( $settings['button_url']['url'] ?? '' );
                    $btn_text = sanitize_text_field( $settings['button_text'] ?? '' );
                    if ( $btn_text ) {
                        $html .= '<a class="elementor-button" href="' . esc_attr( $btn_url ?: '#' ) . '">'
                               . esc_html( $btn_text ) . '</a>' . "\n";
                    }
                    break;
            }

            // Recurse into child elements.
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->walk_elementor_elements( $element['elements'], $html );
            }
        }
    }

    // -------------------------------------------------------------------------
    // HTTP fetch fallback
    // -------------------------------------------------------------------------

    /**
     * Fetch the fully-rendered HTML of a URL via wp_remote_get.
     * Used as a last resort when post_content and meta are both empty
     * (covers Divi, Beaver Builder, WPBakery, and any unknown builders).
     */
    private function fetch_rendered_html( string $url ): string {
        if ( empty( $url ) ) {
            return '';
        }

        $response = wp_remote_get( $url, [
            'timeout'    => 20,
            'user-agent' => 'WordPress/BLS-Scanner',
            'sslverify'  => apply_filters( 'bls_fetch_sslverify', true ),
            'cookies'    => [], // no auth cookies – public content only
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return '';
        }

        return wp_remote_retrieve_body( $response );
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
        foreach ( self::SKIP_CLASS_PATTERNS as $pattern ) {
            if ( str_contains( $class, $pattern ) ) {
                return true;
            }
        }

        $aria = strtolower( $node->getAttribute( 'aria-label' ) );
        if ( $aria !== '' ) {
            foreach ( self::SKIP_ARIA_PATTERNS as $pattern ) {
                if ( str_contains( $aria, $pattern ) ) {
                    return true;
                }
            }
        }

        // Skip buttons inside a Gravity Forms form wrapper.
        $ancestor = $node->parentNode;
        while ( $ancestor instanceof DOMElement ) {
            $ancestor_class = strtolower( $ancestor->getAttribute( 'class' ) );
            $ancestor_id    = strtolower( $ancestor->getAttribute( 'id' ) );
            if ( str_contains( $ancestor_class, 'gform_wrapper' )
                 || str_contains( $ancestor_id, 'gform_wrapper' )
                 || str_contains( $ancestor_id, 'gform_' ) ) {
                return true;
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

        if ( $parent instanceof DOMElement && strtolower( $parent->nodeName ) === 'a' ) {
            $href     = trim( $parent->getAttribute( 'href' ) );
            $title    = trim( $parent->getAttribute( 'title' ) );
            $has_link = ! empty( $href ) && $href !== '#';
            $new_tab  = $parent->getAttribute( 'target' ) === '_blank';
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

        if ( $parent instanceof DOMElement && strtolower( $parent->nodeName ) === 'a' ) {
            $href     = trim( $parent->getAttribute( 'href' ) );
            $title    = trim( $parent->getAttribute( 'title' ) );
            $has_link = ! empty( $href ) && $href !== '#';
            $new_tab  = $parent->getAttribute( 'target' ) === '_blank';
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
