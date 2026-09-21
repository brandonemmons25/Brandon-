<?php
defined( 'ABSPATH' ) || exit;

/**
 * The handful of fields the two directories cannot work without: a building's
 * address (the directory searches it) and a neighborhood's card image.
 *
 * Native, so both directories are complete with no other plugin installed.
 * The richer page content — amenities, gallery, FAQs — stays in ACF, which is
 * only needed once you build the individual pages.
 */
class AO_Fields {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		add_action( 'add_meta_boxes_ao_building', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_ao_building', array( __CLASS__, 'save_post' ), 10, 2 );

		add_action( 'ao_neighborhood_add_form_fields', array( __CLASS__, 'term_add_fields' ) );
		add_action( 'ao_neighborhood_edit_form_fields', array( __CLASS__, 'term_edit_fields' ) );
		add_action( 'created_ao_neighborhood', array( __CLASS__, 'save_term' ) );
		add_action( 'edited_ao_neighborhood', array( __CLASS__, 'save_term' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register_meta() {
		$auth = function () {
			return current_user_can( 'edit_posts' );
		};

		register_post_meta( 'ao_building', 'ao_address', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth,
		) );

		register_term_meta( 'ao_neighborhood', 'ao_card_image', array(
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => $auth,
		) );
	}

	/**
	 * The media picker is only needed on the neighborhood term screens.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();

		if ( ! $screen || 'ao_neighborhood' !== $screen->taxonomy ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'ao-admin-media', AO_URL . 'assets/js/admin-media.js', array( 'jquery' ), AO_VERSION, true );
	}

	/* ── Building address ───────────────────────────────────────────── */

	public static function meta_box() {
		add_meta_box(
			'ao-building-details',
			'Building Details',
			array( __CLASS__, 'render_meta_box' ),
			'ao_building',
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		$address = get_post_meta( $post->ID, 'ao_address', true );

		wp_nonce_field( 'ao_details_save', 'ao_details_nonce' );
		?>
		<p>
			<label for="ao_address"><strong>Street Address</strong></label><br>
			<input type="text" id="ao_address" name="ao_address" class="widefat"
				value="<?php echo esc_attr( $address ); ?>">
		</p>
		<p class="description">Searched alongside the building name in the directory.</p>
		<?php
	}

	public static function save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Absent from quick edit and REST-only saves; leave the value alone.
		if ( ! isset( $_POST['ao_details_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ao_details_nonce'] ) ), 'ao_details_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$address = isset( $_POST['ao_address'] ) ? sanitize_text_field( wp_unslash( $_POST['ao_address'] ) ) : '';

		if ( '' === $address ) {
			delete_post_meta( $post_id, 'ao_address' );
		} else {
			update_post_meta( $post_id, 'ao_address', $address );
		}

		// The address feeds the search index.
		AO_Index::index( $post_id );
	}

	/* ── Neighborhood card image ────────────────────────────────────── */

	public static function term_add_fields() {
		?>
		<div class="form-field">
			<label>Card Image</label>
			<?php self::picker( 0 ); ?>
			<p>Shown on the neighborhood directory card.</p>
		</div>
		<?php
	}

	public static function term_edit_fields( $term ) {
		?>
		<tr class="form-field">
			<th scope="row"><label>Card Image</label></th>
			<td>
				<?php self::picker( (int) get_term_meta( $term->term_id, 'ao_card_image', true ) ); ?>
				<p class="description">Shown on the neighborhood directory card.</p>
			</td>
		</tr>
		<?php
	}

	private static function picker( $attachment_id ) {
		$src = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
		?>
		<div class="ao-media-picker">
			<input type="hidden" name="ao_card_image" class="ao-media-id" value="<?php echo esc_attr( $attachment_id ); ?>">
			<img class="ao-media-preview" src="<?php echo esc_url( $src ); ?>"
				style="max-width:200px;height:auto;display:<?php echo $src ? 'block' : 'none'; ?>;margin-bottom:8px">
			<button type="button" class="button ao-media-select">Select Image</button>
			<button type="button" class="button-link ao-media-remove" style="margin-left:8px;display:<?php echo $src ? 'inline' : 'none'; ?>">Remove</button>
		</div>
		<?php
	}

	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['ao_card_image'] ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// Shares the nonce with the curation fields on the same form.
		if ( ! isset( $_POST['ao_term_featured_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ao_term_featured_nonce'] ) ), 'ao_term_featured_save' ) ) {
			return;
		}

		$id = absint( $_POST['ao_card_image'] );

		if ( $id ) {
			update_term_meta( $term_id, 'ao_card_image', $id );
		} else {
			delete_term_meta( $term_id, 'ao_card_image' );
		}
	}
}
