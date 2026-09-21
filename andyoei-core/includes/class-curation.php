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

	// Term meta keys, kept distinct so the two lists never collide.
	const TERM_FEATURED = 'ao_nbhd_featured';
	const TERM_RANK     = 'ao_nbhd_rank';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		// Buildings.
		add_action( 'add_meta_boxes_ao_building', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_ao_building', array( __CLASS__, 'save_post' ), 10, 2 );

		// Neighborhood terms.
		add_action( 'ao_neighborhood_add_form_fields', array( __CLASS__, 'term_add_fields' ) );
		add_action( 'ao_neighborhood_edit_form_fields', array( __CLASS__, 'term_edit_fields' ) );
		add_action( 'created_ao_neighborhood', array( __CLASS__, 'save_term' ) );
		add_action( 'edited_ao_neighborhood', array( __CLASS__, 'save_term' ) );

		// At-a-glance columns, so the curated set is readable from the list.
		add_filter( 'manage_ao_building_posts_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_ao_building_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_filter( 'manage_edit-ao_neighborhood_columns', array( __CLASS__, 'column' ) );
		add_filter( 'manage_ao_neighborhood_custom_column', array( __CLASS__, 'term_column_value' ), 10, 3 );
	}

	public static function register_meta() {
		$auth = function () {
			return current_user_can( 'edit_posts' );
		};

		register_post_meta( 'ao_building', self::FEATURED, array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth,
		) );

		register_post_meta( 'ao_building', self::RANK, array(
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => $auth,
		) );

		register_term_meta( 'ao_neighborhood', self::TERM_FEATURED, array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth,
		) );

		register_term_meta( 'ao_neighborhood', self::TERM_RANK, array(
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => $auth,
		) );
	}

	/* ── Buildings ──────────────────────────────────────────────────── */

	public static function meta_box() {
		add_meta_box(
			'ao-featured',
			'Featured',
			array( __CLASS__, 'render_meta_box' ),
			'ao_building',
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
		<p class="description">1 shows first. Approximately 4–6 buildings.</p>
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

	/* ── Neighborhood terms ─────────────────────────────────────────── */

	public static function term_add_fields() {
		wp_nonce_field( 'ao_term_featured_save', 'ao_term_featured_nonce' );
		?>
		<div class="form-field">
			<label>
				<input type="checkbox" name="ao_nbhd_featured" value="1">
				Featured neighborhood
			</label>
			<p>Show in the featured strip on the neighborhood directory.</p>
		</div>
		<div class="form-field">
			<label for="ao_nbhd_rank">Featured Rank</label>
			<input type="number" id="ao_nbhd_rank" name="ao_nbhd_rank" min="1" step="1">
			<p>1 shows first. Approximately 4–6 neighborhoods.</p>
		</div>
		<?php
	}

	public static function term_edit_fields( $term ) {
		$featured = get_term_meta( $term->term_id, self::TERM_FEATURED, true );
		$rank     = get_term_meta( $term->term_id, self::TERM_RANK, true );

		?>
		<tr class="form-field">
			<th scope="row">Featured</th>
			<td>
				<?php wp_nonce_field( 'ao_term_featured_save', 'ao_term_featured_nonce' ); ?>
				<label>
					<input type="checkbox" name="ao_nbhd_featured" value="1" <?php checked( $featured, '1' ); ?>>
					Show in the featured strip on the neighborhood directory
				</label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="ao_nbhd_rank">Featured Rank</label></th>
			<td>
				<input type="number" id="ao_nbhd_rank" name="ao_nbhd_rank" min="1" step="1" value="<?php echo esc_attr( $rank ); ?>">
				<p class="description">1 shows first. Approximately 4–6 neighborhoods.</p>
			</td>
		</tr>
		<?php
	}

	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['ao_term_featured_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ao_term_featured_nonce'] ) ), 'ao_term_featured_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( empty( $_POST['ao_nbhd_featured'] ) ) {
			delete_term_meta( $term_id, self::TERM_FEATURED );
		} else {
			update_term_meta( $term_id, self::TERM_FEATURED, '1' );
		}

		$rank = isset( $_POST['ao_nbhd_rank'] ) ? absint( $_POST['ao_nbhd_rank'] ) : 0;

		if ( $rank ) {
			update_term_meta( $term_id, self::TERM_RANK, $rank );
		} else {
			delete_term_meta( $term_id, self::TERM_RANK );
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

	public static function term_column_value( $content, $column, $term_id ) {
		if ( 'ao_featured' !== $column ) {
			return $content;
		}

		return esc_html( self::badge(
			get_term_meta( $term_id, self::TERM_FEATURED, true ),
			get_term_meta( $term_id, self::TERM_RANK, true )
		) );
	}

	private static function badge( $featured, $rank ) {
		if ( '1' !== (string) $featured ) {
			return '—';
		}

		return $rank ? '★ ' . (int) $rank : '★';
	}
}
