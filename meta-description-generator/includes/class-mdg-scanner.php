<?php
defined( 'ABSPATH' ) || exit;

/**
 * MDG_Scanner
 *
 * Discovers all published posts/pages/CPTs and reports the status of
 * their Yoast SEO meta description (_yoast_wpseo_metadesc).
 * Also handles the "Your latest posts" homepage case (post_id = 0).
 */
class MDG_Scanner {

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

    // -------------------------------------------------------------------------
    // Homepage (latest posts front page) helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when WordPress uses "Your latest posts" as the front page
     * (i.e. there is no static page assigned as the homepage).
     */
    public static function has_virtual_homepage(): bool {
        return get_option( 'show_on_front' ) === 'posts';
    }

    /**
     * Build a synthetic row for the virtual homepage.
     * Yoast stores this description in the wpseo_titles option.
     */
    public static function get_homepage_row(): array {
        $meta = self::get_homepage_meta();
        return [
            'ID'        => 0,
            'title'     => 'Homepage',
            'post_type' => 'homepage',
            'url'       => home_url( '/' ),
            'edit_url'  => admin_url( 'options-reading.php' ),
            'meta'      => $meta,
            'has_meta'  => $meta !== '',
            'meta_len'  => mb_strlen( $meta ),
        ];
    }

    public static function get_homepage_meta(): string {
        $titles = get_option( 'wpseo_titles', [] );
        return trim( (string) ( $titles['metadesc-home-wpseo'] ?? '' ) );
    }

    public static function save_homepage_meta( string $description ): void {
        $titles = get_option( 'wpseo_titles', [] );
        $titles['metadesc-home-wpseo'] = $description;
        update_option( 'wpseo_titles', $titles );
    }

    /**
     * Data for the generator when post_id = 0 (virtual homepage).
     */
    public static function get_homepage_data(): array {
        return [
            'ID'        => 0,
            'title'     => get_bloginfo( 'name' ),
            'post_type' => 'Homepage',
            'content'   => get_bloginfo( 'description' ),
            'existing'  => self::get_homepage_meta(),
            'url'       => home_url( '/' ),
        ];
    }

    // -------------------------------------------------------------------------
    // Post list
    // -------------------------------------------------------------------------

    /**
     * @param array $args { status, post_type, search, per_page, page }
     * @return array { rows: array, total: int }
     */
    public static function get_posts( array $args = [] ): array {
        $defaults = [
            'status'    => 'all',
            'post_type' => '',
            'search'    => '',
            'per_page'  => 50,
            'page'      => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $rows         = [];
        $homepage_row = null;

        // Prepend virtual homepage row when on page 1, no post_type filter, and no search.
        if (
            self::has_virtual_homepage() &&
            (int) $args['page'] === 1 &&
            empty( $args['post_type'] ) &&
            empty( $args['search'] )
        ) {
            $hp = self::get_homepage_row();
            $include = (
                $args['status'] === 'all' ||
                ( $args['status'] === 'missing' && ! $hp['has_meta'] ) ||
                ( $args['status'] === 'has'     &&   $hp['has_meta'] )
            );
            if ( $include ) {
                $homepage_row = $hp;
            }
        }

        $post_types   = empty( $args['post_type'] )
            ? self::get_scannable_types()
            : [ sanitize_key( $args['post_type'] ) ];
        $excluded_ids = self::get_excluded_ids();

        $query_args = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => (int) $args['per_page'],
            'paged'          => max( 1, (int) $args['page'] ),
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post__not_in'   => $excluded_ids,
        ];

        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        if ( $args['status'] === 'missing' ) {
            $query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
                'relation' => 'OR',
                [ 'key' => '_yoast_wpseo_metadesc', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_yoast_wpseo_metadesc', 'value' => '', 'compare' => '=' ],
            ];
        } elseif ( $args['status'] === 'has' ) {
            $query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
                [ 'key' => '_yoast_wpseo_metadesc', 'value' => '', 'compare' => '!=' ],
            ];
        }

        $query = new WP_Query( $query_args );

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

        if ( $homepage_row ) {
            array_unshift( $rows, $homepage_row );
        }

        $total = (int) $query->found_posts + ( $homepage_row ? 1 : 0 );

        return [ 'rows' => $rows, 'total' => $total ];
    }

    // -------------------------------------------------------------------------
    // Summary
    // -------------------------------------------------------------------------

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

        array_unshift( $params, MDG_META_MIN, MDG_META_MAX );
        $summary = (array) $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

        // Add virtual homepage to counts.
        if ( self::has_virtual_homepage() ) {
            $hp_meta = self::get_homepage_meta();
            $summary['total']   = ( (int) $summary['total'] ) + 1;
            if ( $hp_meta === '' ) {
                $summary['missing'] = ( (int) $summary['missing'] ) + 1;
            } else {
                $summary['has_meta'] = ( (int) $summary['has_meta'] ) + 1;
                $len = mb_strlen( $hp_meta );
                if ( $len < MDG_META_MIN ) $summary['too_short'] = ( (int) $summary['too_short'] ) + 1;
                if ( $len > MDG_META_MAX ) $summary['too_long']  = ( (int) $summary['too_long'] ) + 1;
            }
        }

        return $summary;
    }

    // -------------------------------------------------------------------------
    // Single post data
    // -------------------------------------------------------------------------

    public static function get_post_data( int $post_id ): ?array {
        if ( $post_id === 0 ) {
            return self::get_homepage_data();
        }

        $post = get_post( $post_id );
        if ( ! $post ) return null;

        $content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        $content = preg_replace( '/\s+/', ' ', $content );
        $content = mb_substr( trim( $content ), 0, 1500 );
        $existing = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) );

        return [
            'ID'        => $post->ID,
            'title'     => $post->post_title,
            'post_type' => get_post_type_object( $post->post_type )->labels->singular_name ?? $post->post_type,
            'content'   => $content,
            'existing'  => $existing,
            'url'       => get_permalink( $post->ID ),
        ];
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
