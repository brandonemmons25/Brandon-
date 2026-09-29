<?php
defined( 'ABSPATH' ) || exit;

/**
 * Featured + Featured Rank, for buildings and neighborhoods.
 *
 * Deliberately native rather than ACF: curation is what drives the featured
 * strips, so it must be there the moment the plugin is active, whether or not
 * a field plugin is installed.
 */
class AO_Curation {

	const FEATURED = 'ao_featured';
	const RANK     = 'ao_featured_rank';

	// Both post types curate the same way, so both read these keys.
	const TYPES = array( 'ao_building', 'ao_neighborhood' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		foreach ( self::TYPES as $type ) {
			add_action( "add_meta_boxes_{$type}", array( __CLASS__, 'meta_box' ) );
			add_action( "save_post_{$type}", array( __CLASS__, 'save_post' ), 10, 2 );

			// At-a-glance column, so the curated set reads from the list.
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_value' ), 10, 2 );
		}
	}

	public static function register_meta() {
		$auth = function () {
			return current_user_can( 'edit_posts' );
		};

		foreach ( self::TYPES as $type ) {
			register_post_meta( $type, self::FEATURED, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
			) );

			register_post_meta( $type, self::RANK, array(
				'type'              => 'number',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			) );
		}
	}

	public static function meta_box( $post ) {
		add_meta_box(
			'ao-featured',
			'Featured',
			array( __CLASS__, 'render_meta_box' ),
			$post->post_type,
			'side',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		$featured = get_post_meta( $post->ID, self::FEATURED, true );
		$rank     = get_post_meta( $post->ID, self::RANK, true );

		wp_nonce_field( 'ao_featured_save', 'ao_featured_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="ao_featured" value="1" <?php checked( $featured, '1' ); ?>>
				Show in the featured strip
			</label>
		</p>
		<p>
			<label for="ao_featured_rank"><strong>Featured Rank</strong></label><br>
			<input type="number" id="ao_featured_rank" name="ao_featured_rank" min="1" step="1"
				value="<?php echo esc_attr( $rank ); ?>" style="width:5rem">
		</p>
		<p class="description">1 shows first. Approximately 4–6 records.</p>
		<?php
	}

	public static function save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// The box is absent from quick edit and REST-only saves; leave the
		// stored values alone rather than wiping them.
		if ( ! isset( $_POST['ao_featured_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ao_featured_nonce'] ) ), 'ao_featured_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['ao_featured'] ) ) {
			delete_post_meta( $post_id, self::FEATURED );
		} else {
			update_post_meta( $post_id, self::FEATURED, '1' );
		}

		$rank = isset( $_POST['ao_featured_rank'] ) ? absint( $_POST['ao_featured_rank'] ) : 0;

		if ( $rank ) {
			update_post_meta( $post_id, self::RANK, $rank );
		} else {
			delete_post_meta( $post_id, self::RANK );
		}
	}

	/* ── Admin columns ──────────────────────────────────────────────── */

	public static function column( $columns ) {
		$columns['ao_featured'] = 'Featured';

		return $columns;
	}

	public static function column_value( $column, $post_id ) {
		if ( 'ao_featured' !== $column ) {
			return;
		}

		echo esc_html( self::badge(
			get_post_meta( $post_id, self::FEATURED, true ),
			get_post_meta( $post_id, self::RANK, true )
		) );
	}

	private static function badge( $featured, $rank ) {
		if ( '1' !== (string) $featured ) {
			return '—';
		}

		return $rank ? '★ ' . (int) $rank : '★';
	}
}
