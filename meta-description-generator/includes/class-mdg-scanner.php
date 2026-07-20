<?php
defined( 'ABSPATH' ) || exit;

/**
 * MDG_Scanner
 *
 * Discovers all published posts/pages/CPTs and reports the status of
 * their Yoast SEO meta description (_yoast_wpseo_metadesc).
 */
class MDG_Scanner {

    /**
     * Post types excluded from scanning — functional/internal types that
     * don't need public meta descriptions.
     */
    const DEFAULT_EXCLUDE_TYPES = [
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'shop_order',
        'shop_order_refund',
        'shop_coupon',
        'shop_webhook',
    ];

    /**
     * Return every published post with its meta description status.
     *
     * @param array $args {
     *   status     string  'all' | 'missing' | 'has'
     *   post_type  string  '' = all public types
     *   search     string  title substring search
     *   per_page   int
     *   page       int
     * }
     * @return array { rows: array, total: int }
     */
    /**
     * Shared WP_Query args for the status/post_type/search filter trio used
     * by both the paginated table (get_posts) and bulk actions (get_matching_ids).
     */
    private static function build_filter_query_args( array $args ): array {
        $post_types = empty( $args['post_type'] )
            ? self::get_scannable_types()
            : [ sanitize_key( $args['post_type'] ) ];

        $query_args = [
            'post_type'    => $post_types,
            'post_status'  => 'publish',
            'post__not_in' => self::get_excluded_ids(),
        ];

        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        // When filtering by meta status, use a meta query.
        if ( $args['status'] === 'missing' ) {
            $query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
                'relation' => 'OR',
                [
                    'key'     => '_yoast_wpseo_metadesc',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_yoast_wpseo_metadesc',
                    'value'   => '',
                    'compare' => '=',
                ],
            ];
        } elseif ( $args['status'] === 'has' ) {
            $query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'     => '_yoast_wpseo_metadesc',
                    'value'   => '',
                    'compare' => '!=',
                ],
            ];
        }

        return $query_args;
    }

    public static function get_posts( array $args = [] ): array {
        $defaults = [
            'status'    => 'all',
            'post_type' => '',
            'search'    => '',
            'per_page'  => 50,
            'page'      => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $query_args = self::build_filter_query_args( $args ) + [
            'posts_per_page' => (int) $args['per_page'],
            'paged'          => max( 1, (int) $args['page'] ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        $query = new WP_Query( $query_args );
        $rows  = [];

        foreach ( $query->posts as $post ) {
            $meta   = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
            $meta   = is_string( $meta ) ? trim( $meta ) : '';
            $rows[] = [
                'ID'        => $post->ID,
                'title'     => $post->post_title,
                'post_type' => $post->post_type,
                'url'       => get_permalink( $post->ID ),
                'edit_url'  => get_edit_post_link( $post->ID ),
                'meta'      => $meta,
                'has_meta'  => $meta !== '',
                'meta_len'  => mb_strlen( $meta ),
            ];
        }

        return [
            'rows'  => $rows,
            'total' => (int) $query->found_posts,
        ];
    }

    /**
     * All post IDs matching the status/post_type/search filter, ignoring
     * pagination — used by bulk actions that must act on everything a
     * filter matches, not just the current page of results.
     */
    public static function get_matching_ids( array $args = [] ): array {
        $defaults = [
            'status'    => 'all',
            'post_type' => '',
            'search'    => '',
        ];
        $args = wp_parse_args( $args, $defaults );

        $query_args = self::build_filter_query_args( $args ) + [
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        $query = new WP_Query( $query_args );
        return array_map( 'intval', $query->posts );
    }

    /**
     * Summary counts: total posts, missing, has description, too short, too long.
     */
    public static function get_summary(): array {
        global $wpdb;

        $post_types   = self::get_scannable_types();
        $excluded_ids = self::get_excluded_ids();

        if ( empty( $post_types ) ) {
            return [];
        }

        $type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $id_exclusion      = '';
        $params            = $post_types;

        if ( ! empty( $excluded_ids ) ) {
            $id_placeholders = implode( ',', array_fill( 0, count( $excluded_ids ), '%d' ) );
            $id_exclusion    = "AND p.ID NOT IN ({$id_placeholders})";
            $params          = array_merge( $params, $excluded_ids );
        }

        $sql = "
            SELECT
                COUNT(*)                                                            AS total,
                SUM( pm.meta_value IS NULL OR pm.meta_value = '' )                 AS missing,
                SUM( pm.meta_value IS NOT NULL AND pm.meta_value != '' )            AS has_meta,
                SUM( pm.meta_value IS NOT NULL AND pm.meta_value != ''
                     AND CHAR_LENGTH(pm.meta_value) < %d )                          AS too_short,
                SUM( pm.meta_value IS NOT NULL AND pm.meta_value != ''
                     AND CHAR_LENGTH(pm.meta_value) > %d )                          AS too_long
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                   ON pm.post_id = p.ID AND pm.meta_key = '_yoast_wpseo_metadesc'
            WHERE p.post_status = 'publish'
              AND p.post_type IN ({$type_placeholders})
              {$id_exclusion}
        ";

        // Prepend length constants to params.
        array_unshift( $params, MDG_META_MIN, MDG_META_MAX );

        return (array) $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * Fetch a single post's data for the generator (title + clean content).
     * Pulls the excerpt/short description and category/tag terms alongside
     * the main content — for WooCommerce products especially, post_content
     * alone is often thin or empty, and without a category like "Apparel"
     * to ground it, the generator can default to describing the item as
     * whatever the business's main product line is (e.g. calling a hoodie
     * a cider) instead of what the page actually says it is.
     */
    public static function get_post_data( int $post_id ): ?array {
        $post = get_post( $post_id );
        if ( ! $post ) return null;

        $excerpt = wp_strip_all_tags( $post->post_excerpt );
        $body    = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );

        $content = trim( $excerpt . ( $excerpt !== '' && $body !== '' ? ' ' : '' ) . $body );
        // Collapse whitespace and limit to 1500 chars to keep API tokens low.
        $content = preg_replace( '/\s+/', ' ', $content );
        $content = mb_substr( $content, 0, 1500 );

        $categories = [];
        foreach ( [ 'category', 'product_cat', 'product_tag', 'post_tag' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) continue;
            $terms = get_the_terms( $post_id, $taxonomy );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    $categories[] = $term->name;
                }
            }
        }
        $categories = array_values( array_unique( $categories ) );

        $existing = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) );

        return [
            'ID'                    => $post->ID,
            'title'                 => $post->post_title,
            'post_type'             => get_post_type_object( $post->post_type )->labels->singular_name ?? $post->post_type,
            'is_woocommerce_product'=> $post->post_type === 'product',
            'content'               => $content,
            'categories'            => $categories,
            'existing'              => $existing,
            'url'                   => get_permalink( $post->ID ),
            'site_name'             => trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) ),
            'site_desc'             => trim( wp_strip_all_tags( get_bloginfo( 'description' ) ) ),
            'focus_keyphrase'       => trim( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ),
        ];
    }

    /**
     * Return the Yoast SEO field status for a single post.
     * Only returns fields that are currently empty. SEO title is
     * intentionally left to Yoast's own title template — this plugin only
     * manages the focus keyphrase and meta description.
     */
    public static function get_missing_fields( int $post_id ): array {
        $missing = [];
        $keyphrase = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) );
        $meta_desc = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) );

        if ( $keyphrase === '' ) $missing[] = 'focus_keyphrase';
        if ( $meta_desc  === '' ) $missing[] = 'meta_description';

        return $missing;
    }

    /**
     * Return IDs of all published posts/pages missing a focus keyphrase or
     * meta description. SEO title is left to Yoast's own title template.
     */
    public static function get_incomplete_post_ids(): array {
        global $wpdb;

        $post_types   = self::get_scannable_types();
        $excluded_ids = self::get_excluded_ids();

        if ( empty( $post_types ) ) return [];

        $type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $id_exclusion      = '';
        $params            = $post_types;

        if ( ! empty( $excluded_ids ) ) {
            $id_placeholders = implode( ',', array_fill( 0, count( $excluded_ids ), '%d' ) );
            $id_exclusion    = "AND p.ID NOT IN ({$id_placeholders})";
            $params          = array_merge( $params, $excluded_ids );
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_kw
                   ON pm_kw.post_id = p.ID AND pm_kw.meta_key = '_yoast_wpseo_focuskw'
            LEFT JOIN {$wpdb->postmeta} pm_desc
                   ON pm_desc.post_id = p.ID AND pm_desc.meta_key = '_yoast_wpseo_metadesc'
            WHERE p.post_status = 'publish'
              AND p.post_type IN ({$type_placeholders})
              {$id_exclusion}
              AND (
                  pm_kw.meta_value    IS NULL OR pm_kw.meta_value    = ''
               OR pm_desc.meta_value  IS NULL OR pm_desc.meta_value  = ''
              )
            ORDER BY p.post_title ASC
        ";
        // phpcs:enable

        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
        return array_map( 'intval', $rows );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function get_scannable_types(): array {
        $all     = get_post_types( [ 'public' => true ], 'names' );
        $exclude = apply_filters( 'mdg_exclude_post_types', self::DEFAULT_EXCLUDE_TYPES );
        return array_values( array_diff( $all, $exclude ) );
    }

    private static function get_excluded_ids(): array {
        $ids = [];
        if ( class_exists( 'WooCommerce' ) ) {
            foreach ( [
                'woocommerce_shop_page_id',
                'woocommerce_cart_page_id',
                'woocommerce_checkout_page_id',
                'woocommerce_myaccount_page_id',
                'woocommerce_terms_page_id',
            ] as $opt ) {
                $id = (int) get_option( $opt, 0 );
                if ( $id > 0 ) $ids[] = $id;
            }
        }
        return apply_filters( 'mdg_exclude_post_ids', array_unique( $ids ) );
    }
}
