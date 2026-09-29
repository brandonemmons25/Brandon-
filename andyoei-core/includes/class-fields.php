<?php
defined( 'ABSPATH' ) || exit;

/**
 * The handful of fields the two directories cannot work without: a building's
 * address (the directory searches it) and a neighborhood's median price.
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

		add_action( 'add_meta_boxes_ao_neighborhood', array( __CLASS__, 'neighborhood_meta_box' ) );
		add_action( 'save_post_ao_neighborhood', array( __CLASS__, 'save_neighborhood' ), 10, 2 );
	}

	public static function register_meta() {
		$auth = function () {
			return current_user_can( 'edit_posts' );
		};

		foreach ( array( 'ao_address', 'ao_starting_price' ) as $text_key ) {
			register_post_meta( 'ao_building', $text_key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
			) );
		}

		// Where the card sends a visitor, when the page lives elsewhere.
		register_post_meta( 'ao_building', 'ao_destination', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'esc_url_raw',
			'auth_callback'     => $auth,
		) );

		register_post_meta( 'ao_neighborhood', 'ao_destination', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'esc_url_raw',
			'auth_callback'     => $auth,
		) );

		// Drives the Completion filter, so it is numeric.
		register_post_meta( 'ao_building', 'ao_completion', array(
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => $auth,
		) );

		// Free text, so "Data pending" reads as well as a figure.
		register_post_meta( 'ao_neighborhood', 'ao_median_price', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth,
		) );
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
		$address     = get_post_meta( $post->ID, 'ao_address', true );
		$price       = get_post_meta( $post->ID, 'ao_starting_price', true );
		$completion  = get_post_meta( $post->ID, 'ao_completion', true );
		$destination = get_post_meta( $post->ID, 'ao_destination', true );

		wp_nonce_field( 'ao_details_save', 'ao_details_nonce' );
		?>
		<p>
			<label for="ao_address"><strong>Street Address</strong></label><br>
			<input type="text" id="ao_address" name="ao_address" class="widefat"
				value="<?php echo esc_attr( $address ); ?>">
			<span class="description">Searched alongside the building name.</span>
		</p>
		<p>
			<label for="ao_starting_price"><strong>Starting At</strong></label><br>
			<input type="text" id="ao_starting_price" name="ao_starting_price" class="widefat"
				value="<?php echo esc_attr( $price ); ?>" placeholder="$1,250,000">
			<span class="description">Free text, so "Price on request" works.</span>
		</p>
		<p>
			<label for="ao_completion"><strong>Completion Date</strong></label><br>
			<input type="number" id="ao_completion" name="ao_completion" min="1800" max="2200" step="1"
				value="<?php echo esc_attr( $completion ); ?>" placeholder="2026" style="width:6rem">
			<span class="description">Year. Drives the Completion filter.</span>
		</p>
		<p>
			<label for="ao_destination"><strong>Destination URL</strong></label><br>
			<input type="url" id="ao_destination" name="ao_destination" class="widefat"
				value="<?php echo esc_attr( $destination ); ?>" placeholder="https://andyoei.com/the-ritz-carlton/">
			<span class="description">
				Where the card sends visitors. Leave empty to use this record's own page.
			</span>
		</p>
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

		foreach ( array( 'ao_address', 'ao_starting_price' ) as $text_key ) {
			$value = isset( $_POST[ $text_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $text_key ] ) ) : '';

			if ( '' === $value ) {
				delete_post_meta( $post_id, $text_key );
			} else {
				update_post_meta( $post_id, $text_key, $value );
			}
		}

		$destination = isset( $_POST['ao_destination'] ) ? esc_url_raw( wp_unslash( $_POST['ao_destination'] ) ) : '';

		if ( '' === $destination ) {
			delete_post_meta( $post_id, 'ao_destination' );
		} else {
			update_post_meta( $post_id, 'ao_destination', $destination );
		}

		$completion = isset( $_POST['ao_completion'] ) ? absint( $_POST['ao_completion'] ) : 0;

		if ( $completion ) {
			update_post_meta( $post_id, 'ao_completion', $completion );
		} else {
			delete_post_meta( $post_id, 'ao_completion' );
		}

		// The address feeds the search index.
		AO_Index::index( $post_id );
	}

	/* ── Neighborhoods ──────────────────────────────────────────────── */

	public static function neighborhood_meta_box() {
		add_meta_box(
			'ao-neighborhood-details',
			'Neighborhood Details',
			array( __CLASS__, 'render_neighborhood_box' ),
			'ao_neighborhood',
			'side',
			'default'
		);
	}

	public static function render_neighborhood_box( $post ) {
		$price       = get_post_meta( $post->ID, 'ao_median_price', true );
		$destination = get_post_meta( $post->ID, 'ao_destination', true );

		wp_nonce_field( 'ao_neighborhood_details_save', 'ao_neighborhood_details_nonce' );
		?>
		<p>
			<label for="ao_median_price"><strong>Median Price</strong></label><br>
			<input type="text" id="ao_median_price" name="ao_median_price" class="widefat"
				value="<?php echo esc_attr( $price ); ?>" placeholder="$685,000">
			<span class="description">
				The card's second column. Buildings, the first, counts itself.
			</span>
		</p>
		<p>
			<label for="ao_destination"><strong>Destination URL</strong></label><br>
			<input type="url" id="ao_destination" name="ao_destination" class="widefat"
				value="<?php echo esc_attr( $destination ); ?>" placeholder="https://andyoei.com/rittenhouse-square/">
			<span class="description">
				Where the card sends visitors. Leave empty to use this page.
			</span>
		</p>
		<p class="description">
			The card image is the Featured Image, set in the sidebar.
		</p>
		<?php
	}

	public static function save_neighborhood( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['ao_neighborhood_details_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ao_neighborhood_details_nonce'] ) ), 'ao_neighborhood_details_save' )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$price = isset( $_POST['ao_median_price'] ) ? sanitize_text_field( wp_unslash( $_POST['ao_median_price'] ) ) : '';

		if ( '' === $price ) {
			delete_post_meta( $post_id, 'ao_median_price' );
		} else {
			update_post_meta( $post_id, 'ao_median_price', $price );
		}

		$destination = isset( $_POST['ao_destination'] ) ? esc_url_raw( wp_unslash( $_POST['ao_destination'] ) ) : '';

		if ( '' === $destination ) {
			delete_post_meta( $post_id, 'ao_destination' );
		} else {
			update_post_meta( $post_id, 'ao_destination', $destination );
		}
	}
}
