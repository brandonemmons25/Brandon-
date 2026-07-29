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

    public static function init(): void {
        add_filter( 'the_content', [ __CLASS__, 'inject' ], 999 );
    }

    /**
     * Queue a title to be injected at render time for a specific post.
     */
    public static function queue( int $post_id, string $button_text, string $link_url, string $title ): void {
        $rules = get_option( self::OPTION, [] );
        if ( ! is_array( $rules ) ) {
            $rules = [];
        }

        $rules[ $post_id ][] = [
            'text'  => $button_text,
            'link'  => trim( $link_url ),
            'title' => $title,
        ];

        update_option( self::OPTION, $rules, false );
    }

    public static function inject( string $content ): string {
        $rules = get_option( self::OPTION, [] );
        if ( empty( $rules ) || ! is_array( $rules ) ) {
            return $content;
        }

        $post_id = get_the_ID();
        if ( ! $post_id || empty( $rules[ $post_id ] ) ) {
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
