<?php
/**
 * Admin meta boxes for Scroll Section options.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSD_Meta_Boxes {

    /** Meta key prefix. */
    const PREFIX = '_ssd_';

    /** Default values for every meta field. */
    public static function defaults() {
        return array(
            'bg_type'          => 'image',    // image | video | color
            'bg_image'         => '',          // attachment URL
            'bg_video'         => '',          // video URL (mp4 / YouTube / Vimeo)
            'bg_color'         => '#000000',
            'overlay_color'    => 'rgba(0,0,0,0.4)',
            'overlay_enabled'  => '1',
            'content_align'    => 'center',    // left | center | right
            'content_valign'   => 'center',    // top | center | bottom
            'animation_type'   => 'fade-up',   // fade-up | fade-in | scale-up | slide-left | slide-right | parallax-only | none
            'parallax_speed'   => '0.5',       // 0 = no parallax, 1 = full speed
            'text_color'       => '#ffffff',
            'subtitle'         => '',
            'cta_text'         => '',
            'cta_url'          => '',
        );
    }

    /**
     * Hook into add_meta_boxes.
     */
    public static function add() {
        add_meta_box(
            'ssd_section_options',
            'Section Options',
            array( __CLASS__, 'render' ),
            'scroll_section',
            'normal',
            'high'
        );
    }

    /**
     * Save meta values.
     */
    public static function save( $post_id, $post ) {
        if ( ! isset( $_POST['ssd_nonce'] ) || ! wp_verify_nonce( $_POST['ssd_nonce'], 'ssd_save_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== 'scroll_section' ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $defaults = self::defaults();
        foreach ( array_keys( $defaults ) as $key ) {
            $field = self::PREFIX . $key;
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            } else {
                // Checkbox-style fields: if not present, store empty.
                delete_post_meta( $post_id, $field );
            }
        }
    }

    /**
     * Render the meta box.
     */
    public static function render( $post ) {
        wp_nonce_field( 'ssd_save_meta', 'ssd_nonce' );

        $defaults = self::defaults();
        $values   = array();
        foreach ( $defaults as $key => $default ) {
            $stored = get_post_meta( $post->ID, self::PREFIX . $key, true );
            $values[ $key ] = $stored !== '' ? $stored : $default;
        }
        ?>
        <div class="ssd-meta-box">

            <!-- Background Type -->
            <div class="ssd-field-group">
                <h4>Background</h4>
                <label>Type</label>
                <select name="<?php echo esc_attr( self::PREFIX ); ?>bg_type" id="ssd-bg-type">
                    <option value="image" <?php selected( $values['bg_type'], 'image' ); ?>>Image</option>
                    <option value="video" <?php selected( $values['bg_type'], 'video' ); ?>>Video</option>
                    <option value="color" <?php selected( $values['bg_type'], 'color' ); ?>>Solid Color</option>
                </select>

                <div class="ssd-bg-field ssd-bg-image-field">
                    <label>Background Image</label>
                    <input type="text"
                           name="<?php echo esc_attr( self::PREFIX ); ?>bg_image"
                           id="ssd-bg-image"
                           value="<?php echo esc_url( $values['bg_image'] ); ?>"
                           class="regular-text" />
                    <button type="button" class="button ssd-upload-btn" data-target="#ssd-bg-image">Select Image</button>
                    <div id="ssd-bg-image-preview" class="ssd-image-preview">
                        <?php if ( $values['bg_image'] ) : ?>
                            <img src="<?php echo esc_url( $values['bg_image'] ); ?>" />
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ssd-bg-field ssd-bg-video-field">
                    <label>Video URL <small>(MP4, YouTube, or Vimeo)</small></label>
                    <input type="url"
                           name="<?php echo esc_attr( self::PREFIX ); ?>bg_video"
                           value="<?php echo esc_url( $values['bg_video'] ); ?>"
                           class="regular-text" />
                </div>

                <div class="ssd-bg-field ssd-bg-color-field">
                    <label>Background Color</label>
                    <input type="text"
                           name="<?php echo esc_attr( self::PREFIX ); ?>bg_color"
                           value="<?php echo esc_attr( $values['bg_color'] ); ?>"
                           class="ssd-color-input" />
                </div>
            </div>

            <!-- Overlay -->
            <div class="ssd-field-group">
                <h4>Overlay</h4>
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr( self::PREFIX ); ?>overlay_enabled"
                           value="1"
                           <?php checked( $values['overlay_enabled'], '1' ); ?> />
                    Enable overlay
                </label>
                <label>Overlay Color <small>(rgba supported)</small></label>
                <input type="text"
                       name="<?php echo esc_attr( self::PREFIX ); ?>overlay_color"
                       value="<?php echo esc_attr( $values['overlay_color'] ); ?>"
                       class="regular-text" />
            </div>

            <!-- Content Layout -->
            <div class="ssd-field-group">
                <h4>Content Layout</h4>

                <label>Horizontal Alignment</label>
                <select name="<?php echo esc_attr( self::PREFIX ); ?>content_align">
                    <option value="left" <?php selected( $values['content_align'], 'left' ); ?>>Left</option>
                    <option value="center" <?php selected( $values['content_align'], 'center' ); ?>>Center</option>
                    <option value="right" <?php selected( $values['content_align'], 'right' ); ?>>Right</option>
                </select>

                <label>Vertical Alignment</label>
                <select name="<?php echo esc_attr( self::PREFIX ); ?>content_valign">
                    <option value="top" <?php selected( $values['content_valign'], 'top' ); ?>>Top</option>
                    <option value="center" <?php selected( $values['content_valign'], 'center' ); ?>>Center</option>
                    <option value="bottom" <?php selected( $values['content_valign'], 'bottom' ); ?>>Bottom</option>
                </select>

                <label>Text Color</label>
                <input type="text"
                       name="<?php echo esc_attr( self::PREFIX ); ?>text_color"
                       value="<?php echo esc_attr( $values['text_color'] ); ?>"
                       class="ssd-color-input" />
            </div>

            <!-- Animation -->
            <div class="ssd-field-group">
                <h4>Animation</h4>

                <label>Animation Type</label>
                <select name="<?php echo esc_attr( self::PREFIX ); ?>animation_type">
                    <option value="fade-up" <?php selected( $values['animation_type'], 'fade-up' ); ?>>Fade Up</option>
                    <option value="fade-in" <?php selected( $values['animation_type'], 'fade-in' ); ?>>Fade In</option>
                    <option value="scale-up" <?php selected( $values['animation_type'], 'scale-up' ); ?>>Scale Up</option>
                    <option value="slide-left" <?php selected( $values['animation_type'], 'slide-left' ); ?>>Slide from Left</option>
                    <option value="slide-right" <?php selected( $values['animation_type'], 'slide-right' ); ?>>Slide from Right</option>
                    <option value="parallax-only" <?php selected( $values['animation_type'], 'parallax-only' ); ?>>Parallax Only (no content animation)</option>
                    <option value="none" <?php selected( $values['animation_type'], 'none' ); ?>>None</option>
                </select>

                <label>Parallax Speed <small>(0 = off, 0.5 = default, 1 = intense)</small></label>
                <input type="number"
                       name="<?php echo esc_attr( self::PREFIX ); ?>parallax_speed"
                       value="<?php echo esc_attr( $values['parallax_speed'] ); ?>"
                       min="0" max="1" step="0.1" />
            </div>

            <!-- Extra Fields -->
            <div class="ssd-field-group">
                <h4>Extra Content</h4>

                <label>Subtitle</label>
                <input type="text"
                       name="<?php echo esc_attr( self::PREFIX ); ?>subtitle"
                       value="<?php echo esc_attr( $values['subtitle'] ); ?>"
                       class="regular-text" />

                <label>Call-to-Action Button Text</label>
                <input type="text"
                       name="<?php echo esc_attr( self::PREFIX ); ?>cta_text"
                       value="<?php echo esc_attr( $values['cta_text'] ); ?>"
                       class="regular-text" />

                <label>Call-to-Action Button URL</label>
                <input type="url"
                       name="<?php echo esc_attr( self::PREFIX ); ?>cta_url"
                       value="<?php echo esc_url( $values['cta_url'] ); ?>"
                       class="regular-text" />
            </div>

        </div>
        <?php
    }

    /**
     * Helper: get a meta value with fallback.
     */
    public static function get( $post_id, $key ) {
        $defaults = self::defaults();
        $value    = get_post_meta( $post_id, self::PREFIX . $key, true );
        return $value !== '' ? $value : ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
    }
}
