<?php
defined( 'ABSPATH' ) || exit;

/**
 * The building → neighborhood relationship.
 *
 * Neighborhoods were a taxonomy until 2.0, which is why they had no block
 * editor. They are a post type now, so the link is a meta key holding the
 * neighborhood's post ID rather than a term assignment. One neighborhood per
 * building; the Areas filter selects several, which is a different thing.
 */
class AO_Relations {

	const KEY = 'ao_neighborhood';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes_ao_building', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_ao_building', array( __CLASS__, 'save' ), 10, 2 );

		add_filter( 'manage_ao_building_posts_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_ao_building_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	public static function register_meta() {
		register_post_meta( 'ao_building', self::KEY, array(
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => function () {
				return current_user_can( 'edit_posts' );
			},
		) );
	}

	/**
	 * Every published neighborhood, A–Z, as id => name.
	 */
	public static function options() {
		$posts = get_posts( array(
			'post_type'      => 'ao_neighborhood',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$options = array();

		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		return $options;
	}

	/**
	 * The neighborhood post a building belongs to, or null.
	 */
	public static function for_building( $post_id ) {
		$id = (int) get_post_meta( $post_id, self::KEY, true );

		if ( ! $id ) {
			return null;
		}

		$post = get_post( $id );

		return ( $post && 'ao_neighborhood' === $post->post_type ) ? $post : null;
	}

	public static function name_for_building( $post_id ) {
		$post = self::for_building( $post_id );

		return $post ? $post->post_title : '';
	}

	/**
	 * How many published buildings sit in a neighborhood. Drives the count on
	 * the neighborhood card, which nobody has to maintain by hand.
	 */
	public static function building_count( $neighborhood_id ) {
		$ids = get_posts( array(
			'post_type'      => 'ao_building',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => (int) $neighborhood_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		) );

		return count( $ids );
	}

	/* ── Admin ──────────────────────────────────────────────────────── */

	public static function meta_box() {
		add_meta_box(
			'ao-neighborhood',
			'Neighborhood',
			array( __CLASS__, 'render' ),
			'ao_building',
			'side',
			'high'
		);
	}

	public static function render( $post ) {
		$current = (int) get_post_meta( $post->ID, self::KEY, true );
		$options = self::options();

		wp_nonce_field( 'ao_neighborhood_save', 'ao_neighborhood_nonce' );
		?>
		<?php if ( ! $options ) : ?>
			<p>
				No neighborhoods yet.
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ao_neighborhood' ) ); ?>">Add one</a>,
				then pick it here.
			</p>
		<?php else : ?>
			<select name="ao_neighborhood" class="widefat">
				<option value="">— Select —</option>
				<?php foreach ( $options as $id => $name ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( $current, $id ); ?>>
						<?php echo esc_html( $name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">Drives the Areas filter and the Area column on the card.</p>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Absent from quick edit and REST-only saves; leave the stored value
		// alone rather than wiping it.
		if ( ! isset( $_POST['ao_neighborhood_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ao_neighborhood_nonce'] ) ), 'ao_neighborhood_save' )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$id = isset( $_POST['ao_neighborhood'] ) ? absint( $_POST['ao_neighborhood'] ) : 0;

		if ( $id ) {
			update_post_meta( $post_id, self::KEY, $id );
		} else {
			delete_post_meta( $post_id, self::KEY );
		}
	}

	public static function column( $columns ) {
		$columns['ao_neighborhood'] = 'Neighborhood';

		return $columns;
	}

	public static function column_value( $column, $post_id ) {
		if ( 'ao_neighborhood' !== $column ) {
			return;
		}

		$name = self::name_for_building( $post_id );

		echo $name ? esc_html( $name ) : '—';
	}
}
