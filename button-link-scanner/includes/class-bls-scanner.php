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
     * Run a full site scan.
     *
     * @return array Summary stats.
     */
    public function run_full_scan() {
        BLS_Database::clear_results();

        $post_types = $this->get_scannable_post_types();
        $total      = 0;

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
                ] );

                foreach ( $query->posts as $post ) {
                    $found  = $this->scan_post( $post );
                    $total += $found;
                }

                $paged++;
            } while ( $paged <= $query->max_num_pages );
        }

        update_option( 'bls_last_scan_total', $total );
        update_option( 'bls_last_scan_time',  current_time( 'mysql' ) );

        return [ 'buttons_found' => $total ];
    }

    /**
     * Scan a single WP_Post object and persist button data.
     *
     * @return int Number of buttons found.
     */
    public function scan_post( WP_Post $post ) {
        // Render shortcodes so we capture dynamically-generated buttons.
        $content = do_shortcode( $post->post_content );

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
    // Detection logic
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
        $seen    = []; // deduplicate by outerHTML

        // 1. Gutenberg & styled anchor buttons.
        $anchor_nodes = $xpath->query( '//a' );
        foreach ( $anchor_nodes as $node ) {
            if ( $this->node_is_button( $node ) ) {
                $entry = $this->describe_anchor( $node, 'gutenberg_or_styled' );
                $key   = md5( $entry['html'] );
                if ( ! isset( $seen[ $key ] ) ) {
                    $buttons[]   = $entry;
                    $seen[ $key ] = true;
                }
            }
        }

        // 2. Bare <button> elements.
        $button_nodes = $xpath->query( '//button' );
        foreach ( $button_nodes as $node ) {
            $entry = $this->describe_button_element( $node );
            $key   = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]   = $entry;
                $seen[ $key ] = true;
            }
        }

        // 3. <input type="button|submit|reset">.
        $input_nodes = $xpath->query( '//input[@type="button" or @type="submit" or @type="reset"]' );
        foreach ( $input_nodes as $node ) {
            $entry = $this->describe_input( $node );
            $key   = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]   = $entry;
                $seen[ $key ] = true;
            }
        }

        // 4. Any element with role="button" not already captured.
        $role_nodes = $xpath->query( '//*[@role="button"]' );
        foreach ( $role_nodes as $node ) {
            $tag = strtolower( $node->nodeName );
            if ( in_array( $tag, [ 'a', 'button', 'input' ], true ) ) {
                continue; // already handled above
            }
            $entry = $this->describe_role_button( $node );
            $key   = md5( $entry['html'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $buttons[]   = $entry;
                $seen[ $key ] = true;
            }
        }

        return $buttons;
    }

    // -------------------------------------------------------------------------
    // Node helpers
    // -------------------------------------------------------------------------

    private function node_is_button( DOMElement $node ): bool {
        $class = strtolower( $node->getAttribute( 'class' ) );
        foreach ( self::BUTTON_CLASS_PATTERNS as $pattern ) {
            if ( str_contains( $class, $pattern ) ) {
                return true;
            }
        }
        // Also treat <a role="button"> as a button.
        if ( $node->getAttribute( 'role' ) === 'button' ) {
            return true;
        }
        return false;
    }

    private function describe_anchor( DOMElement $node, string $type ): array {
        $href      = trim( $node->getAttribute( 'href' ) );
        $title     = trim( $node->getAttribute( 'title' ) );
        $target    = $node->getAttribute( 'target' );
        $has_link  = ! empty( $href ) && $href !== '#';

        return [
            'text'          => trim( $node->textContent ),
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
        // <button> can be wrapped in an <a>; walk up to check.
        $parent    = $node->parentNode;
        $href      = '';
        $title     = '';
        $has_link  = false;
        $new_tab   = false;

        if ( $parent instanceof DOMElement && strtolower( $parent->nodeName ) === 'a' ) {
            $href     = trim( $parent->getAttribute( 'href' ) );
            $title    = trim( $parent->getAttribute( 'title' ) );
            $has_link = ! empty( $href ) && $href !== '#';
            $new_tab  = $parent->getAttribute( 'target' ) === '_blank';
        }

        return [
            'text'          => trim( $node->textContent ),
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
            'text'          => trim( $node->textContent ),
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
        $all = get_post_types( [ 'public' => true ], 'names' );
        // Remove attachment and similar non-content types.
        $exclude = apply_filters( 'bls_exclude_post_types', [ 'attachment' ] );
        return array_values( array_diff( $all, $exclude ) );
    }
}
