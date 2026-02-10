<?php
/**
 * Shortcode renderer and frontend asset loader.
 *
 * Usage:
 *   [scroll_sections]                        – Render all published sections ordered by menu_order.
 *   [scroll_sections group="my-group"]       – Render only sections in the "my-group" section group.
 *   [scroll_sections snap="true"]            – Enable section-snap scrolling.
 *   [scroll_sections ids="12,34,56"]         – Render specific section post IDs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSD_Renderer {

    /**
     * Register shortcode.
     */
    public static function init() {
        add_shortcode( 'scroll_sections', array( __CLASS__, 'shortcode' ) );
    }

    /**
     * Enqueue frontend assets (only when shortcode is present).
     */
    private static function enqueue() {
        // GSAP core + ScrollTrigger (CDN — free public plugins).
        wp_enqueue_script(
            'gsap',
            'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js',
            array(),
            '3.12.5',
            true
        );
        wp_enqueue_script(
            'gsap-scrolltrigger',
            'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js',
            array( 'gsap' ),
            '3.12.5',
            true
        );

        // Plugin JS.
        wp_enqueue_script(
            'ssd-front',
            SSD_PLUGIN_URL . 'assets/js/scroll-sections.js',
            array( 'gsap', 'gsap-scrolltrigger' ),
            SSD_VERSION,
            true
        );

        // Plugin CSS.
        wp_enqueue_style(
            'ssd-front',
            SSD_PLUGIN_URL . 'assets/css/scroll-sections.css',
            array(),
            SSD_VERSION
        );
    }

    /**
     * Shortcode callback.
     */
    public static function shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'group' => '',
            'snap'  => 'true',
            'ids'   => '',
        ), $atts, 'scroll_sections' );

        self::enqueue();

        // Build query.
        $query_args = array(
            'post_type'      => 'scroll_section',
            'posts_per_page' => 50,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        );

        if ( ! empty( $atts['ids'] ) ) {
            $query_args['post__in'] = array_map( 'absint', explode( ',', $atts['ids'] ) );
            $query_args['orderby']  = 'post__in';
        } elseif ( ! empty( $atts['group'] ) ) {
            $query_args['tax_query'] = array( array(
                'taxonomy' => 'section_group',
                'field'    => 'slug',
                'terms'    => sanitize_title( $atts['group'] ),
            ) );
        }

        $sections = new WP_Query( $query_args );

        if ( ! $sections->have_posts() ) {
            return '<!-- scroll_sections: no sections found -->';
        }

        $snap_enabled = filter_var( $atts['snap'], FILTER_VALIDATE_BOOLEAN );
        $snap_attr    = $snap_enabled ? 'true' : 'false';

        ob_start();
        ?>
        <div class="ssd-wrapper" data-ssd-snap="<?php echo esc_attr( $snap_attr ); ?>">
            <!-- Progress dots navigation -->
            <nav class="ssd-dots" aria-label="Section navigation">
                <?php
                $dot_index = 0;
                while ( $sections->have_posts() ) : $sections->the_post();
                    ?>
                    <button class="ssd-dot<?php echo $dot_index === 0 ? ' active' : ''; ?>"
                            data-ssd-index="<?php echo $dot_index; ?>"
                            aria-label="<?php echo esc_attr( get_the_title() ); ?>">
                        <span></span>
                    </button>
                    <?php
                    $dot_index++;
                endwhile;
                $sections->rewind_posts();
                ?>
            </nav>

            <?php
            $index = 0;
            while ( $sections->have_posts() ) : $sections->the_post();
                $id = get_the_ID();
                echo self::render_section( $id, $index );
                $index++;
            endwhile;
            wp_reset_postdata();
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single section's HTML.
     */
    private static function render_section( $post_id, $index ) {
        $m = function( $key ) use ( $post_id ) {
            return SSD_Meta_Boxes::get( $post_id, $key );
        };

        $bg_type       = $m( 'bg_type' );
        $bg_image      = $m( 'bg_image' );
        $bg_video      = $m( 'bg_video' );
        $bg_color      = $m( 'bg_color' );
        $overlay_on    = $m( 'overlay_enabled' );
        $overlay_color = $m( 'overlay_color' );
        $align         = $m( 'content_align' );
        $valign        = $m( 'content_valign' );
        $anim          = $m( 'animation_type' );
        $parallax      = $m( 'parallax_speed' );
        $text_color    = $m( 'text_color' );
        $subtitle      = $m( 'subtitle' );
        $cta_text      = $m( 'cta_text' );
        $cta_url       = $m( 'cta_url' );

        $title   = get_the_title( $post_id );
        $content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

        // Use featured image as fallback for bg_image.
        if ( $bg_type === 'image' && empty( $bg_image ) ) {
            $thumb = get_the_post_thumbnail_url( $post_id, 'full' );
            if ( $thumb ) {
                $bg_image = $thumb;
            }
        }

        // Inline style for the background layer.
        $bg_style = '';
        if ( $bg_type === 'image' && $bg_image ) {
            $bg_style = 'background-image:url(' . esc_url( $bg_image ) . ');';
        } elseif ( $bg_type === 'color' ) {
            $bg_style = 'background-color:' . esc_attr( $bg_color ) . ';';
        }

        ob_start();
        ?>
        <section class="ssd-section ssd-align-<?php echo esc_attr( $align ); ?> ssd-valign-<?php echo esc_attr( $valign ); ?>"
                 data-ssd-index="<?php echo (int) $index; ?>"
                 data-ssd-anim="<?php echo esc_attr( $anim ); ?>"
                 data-ssd-parallax="<?php echo esc_attr( $parallax ); ?>">

            <!-- Background layer (parallax target) -->
            <?php if ( $bg_type === 'video' && $bg_video ) : ?>
                <div class="ssd-bg ssd-bg-video" data-ssd-parallax-bg>
                    <video autoplay muted loop playsinline>
                        <source src="<?php echo esc_url( $bg_video ); ?>" type="video/mp4">
                    </video>
                </div>
            <?php else : ?>
                <div class="ssd-bg" style="<?php echo $bg_style; ?>" data-ssd-parallax-bg></div>
            <?php endif; ?>

            <!-- Overlay -->
            <?php if ( $overlay_on ) : ?>
                <div class="ssd-overlay" style="background:<?php echo esc_attr( $overlay_color ); ?>;"></div>
            <?php endif; ?>

            <!-- Content -->
            <div class="ssd-content" data-ssd-content style="color:<?php echo esc_attr( $text_color ); ?>;">
                <?php if ( $subtitle ) : ?>
                    <p class="ssd-subtitle"><?php echo esc_html( $subtitle ); ?></p>
                <?php endif; ?>

                <?php if ( $title ) : ?>
                    <h2 class="ssd-title"><?php echo esc_html( $title ); ?></h2>
                <?php endif; ?>

                <?php if ( $content ) : ?>
                    <div class="ssd-body"><?php echo $content; ?></div>
                <?php endif; ?>

                <?php if ( $cta_text && $cta_url ) : ?>
                    <a href="<?php echo esc_url( $cta_url ); ?>" class="ssd-cta"><?php echo esc_html( $cta_text ); ?></a>
                <?php endif; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}
