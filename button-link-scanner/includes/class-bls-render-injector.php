<?php
defined( 'ABSPATH' ) || exit;

/**
 * BLS_Render_Injector
 *
 * Some buttons only exist as an <a> tag AFTER a shortcode/widget expands
 * its own PHP output (an IDX plugin's listing template, for example) —
 * their href/label are never literally stored in post_content,
 * post_excerpt, or postmeta, so Auto-Fill Missing Titles has nowhere in
 * the database to write a title attribute back to. Rather than leave
 * those permanently unfixable, this adds the title live, on every page
 * load, by re-applying the href match against the final rendered HTML —
 * after the shortcode has already produced its output. This is why the
 * href, not the text, is the primary match key here: it's the one piece
 * that's been confirmed to actually exist in stored content (see
 * BLS_Updater's diagnostic), whereas the label is exactly the thing that
 * only exists after rendering.
 */
class BLS_Render_Injector {

    const OPTION = 'bls_render_injections';

    /**
     * Post ID to fall back on when there is no main-query context.
     *
     * inject() normally identifies the page with get_the_ID(), which is
     * correct on a real front-end request. The scanner, though, renders
     * content by calling apply_filters('the_content', ...) directly from an
     * admin-AJAX request — there is no main query, so get_the_ID() returns
     * false, this filter bailed out, and the scanner never saw any
     * render-injected title. Every full scan then re-reported those buttons
     * as missing a title, Auto-Fill re-queued them, and the count never
     * settled. BLS_Scanner sets this around its own render calls so a scan
     * sees exactly what a visitor sees.
     */
    private static $context_post_id = 0;

    /** Scope subsequent inject() calls to a specific post (see $context_post_id). */
    public static function set_context( int $post_id ): void {
        self::$context_post_id = $post_id;
    }

    public static function clear_context(): void {
        self::$context_post_id = 0;
    }

    /**
     * Rules stored under this key apply site-wide rather than to one post.
     *
     * The header and footer are not in the_content, so nothing keyed to a post
     * can reach them. On a block theme they are not plain stored anchors
     * either: social links and navigation live in post_content as block
     * comments, and the <a> tags only exist once those dynamic blocks render.
     * So there is nothing in the database to write a title into, and the only
     * place to add one is at render time — which for blocks means render_block.
     */
    const SITE_WIDE = 0;

    public static function init(): void {
        add_filter( 'the_content', [ __CLASS__, 'inject' ], 999 );

        // Every block's rendered output, which is the only point where a
        // navigation or social link exists as an anchor at all.
        add_filter( 'render_block', [ __CLASS__, 'inject_block' ], 999, 1 );
    }

    /**
     * Apply the site-wide rules to one block's rendered HTML.
     *
     * Runs for every block on every page, so the href substring gate matters
     * here more than anywhere: without it this would parse a DOM per block.
     */
    public static function inject_block( $block_content ) {
        if ( ! is_string( $block_content ) || $block_content === '' ) {
            return $block_content;
        }

        $rules = get_option( self::OPTION, [] );
        if ( empty( $rules[ self::SITE_WIDE ] ) || ! is_array( $rules[ self::SITE_WIDE ] ) ) {
            return $block_content;
        }

        if ( stripos( $block_content, '<a' ) === false ) {
            return $block_content;
        }

        foreach ( $rules[ self::SITE_WIDE ] as $rule ) {
            if ( $rule['link'] === '' || ! str_contains( $block_content, $rule['link'] ) ) {
                continue;
            }
            $block_content = self::apply_title( $block_content, $rule['link'], $rule['title'] );
        }

        return $block_content;
    }

    /**
     * Queue a title to be injected at render time for a specific post.
     */
    public static function queue( int $post_id, string $button_text, string $link_url, string $title ): void {
        $rules = get_option( self::OPTION, [] );
        if ( ! is_array( $rules ) ) {
            $rules = [];
        }

        $rule = [
            'text'  => $button_text,
            'link'  => trim( $link_url ),
            'title' => $title,
        ];

        // Skip an identical existing rule. Without this, every Auto-Fill run
        // appended a fresh copy of the same rule, so this option grew without
        // bound on a site where the same buttons keep being re-queued.
        foreach ( (array) ( $rules[ $post_id ] ?? [] ) as $existing ) {
            if ( $existing == $rule ) {
                return;
            }
        }

        $rules[ $post_id ][] = $rule;

        update_option( self::OPTION, $rules, false );
    }

    /**
     * How many titles are currently being applied at render time.
     *
     * A standing figure, not a per-run one. The Auto-Fill panel only ever shows
     * the most recent run, so after any later run the size of this dependency
     * became invisible — and it matters: these titles are re-applied on every
     * page load from a stored rule and disappear the moment the plugin is
     * deactivated, unlike titles written into content. On a small site that is
     * a footnote. At a few thousand it is worth knowing about, both for what
     * happens if the plugin is ever disabled and for the work done per render.
     */
    public static function count_rules(): int {
        $rules = get_option( self::OPTION, [] );
        if ( ! is_array( $rules ) ) {
            return 0;
        }

        $total = 0;
        foreach ( $rules as $per_post ) {
            $total += count( (array) $per_post );
        }

        return $total;
    }

    public static function inject( string $content ): string {
        $rules = get_option( self::OPTION, [] );
        if ( empty( $rules ) || ! is_array( $rules ) ) {
            return $content;
        }

        $post_id = (int) get_the_ID();
        if ( $post_id < 1 ) {
            $post_id = self::$context_post_id; // Scanning, not serving a request.
        }
        // Site-wide rules are applied by inject_block(), not here — the
        // chrome they target is not part of any post's content.
        if ( $post_id < 1 || empty( $rules[ $post_id ] ) ) {
            return $content;
        }

        foreach ( $rules[ $post_id ] as $rule ) {
            // Cheap pre-check before touching the DOM at all — the href is
            // the one literal string we've confirmed exists in this
            // content, so a plain substring test is a safe, fast gate
            // before doing real parsing on every single page load.
            if ( $rule['link'] === '' || ! str_contains( $content, $rule['link'] ) ) {
                continue;
            }
            $content = self::apply_title( $content, $rule['link'], $rule['title'] );
        }

        return $content;
    }

    /**
     * Add a title to the first matching, title-less anchor whose href
     * equals the target link. Matched by href alone (not text) — see the
     * class docblock for why text can't be relied on here.
     */
    private static function apply_title( string $html, string $target_link, string $title ): string {
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        $changed = false;
        foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $anchor ) {
            if ( trim( $anchor->getAttribute( 'href' ) ) !== $target_link ) {
                continue;
            }
            if ( trim( $anchor->getAttribute( 'title' ) ) !== '' ) {
                continue; // Never overwrite an existing title.
            }

            $anchor->setAttribute( 'title', sanitize_text_field( $title ) );
            $changed = true;
        }

        if ( ! $changed ) {
            return $html;
        }

        $new_html = $dom->saveHTML();
        $new_html = preg_replace( '/^.*<body>/s', '', $new_html );
        $new_html = preg_replace( '/<\/body>.*$/s', '', $new_html );
        $new_html = preg_replace( '/^<\?xml[^>]+>\n?/', '', $new_html );
        return trim( $new_html );
    }
}
