<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sort + search index.
 *
 * WordPress cannot order by a taxonomy term, and its default search does not
 * look at custom fields. So on every save we flatten what the directories need
 * to sort and search on into three plain meta keys.
 */
class AO_Index {

	const SORT_NAME = '_ao_sort_name';
	const SORT_NBHD = '_ao_sort_nbhd';
	const SEARCH    = '_ao_search';
	const PRICE     = '_ao_price';

	/** Post types that get indexed. */
	public static function types() {
		return array( 'ao_building', 'ao_sold', 'ao_press', 'ao_insight', 'ao_case_study', 'ao_testimonial' );
	}

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 20, 1 );
		add_action( 'acf/save_post', array( __CLASS__, 'index' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ao_reindex', array( __CLASS__, 'handle_reindex' ) );
	}

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( in_array( $post->post_type, self::types(), true ) ) {
			self::index( $post_id );
		}
	}

	public static function on_terms( $post_id ) {
		if ( in_array( get_post_type( $post_id ), self::types(), true ) ) {
			self::index( $post_id );
		}
	}

	/**
	 * Write the index for one post.
	 */
	public static function index( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}

		$title = $post->post_title;

		// "The Ritz-Carlton Residences" sorts and groups under R, not T.
		$sort_name = trim( preg_replace( '/^the\s+/i', '', $title ) );

		$nbhds = wp_get_object_terms( $post_id, 'ao_neighborhood', array( 'fields' => 'names' ) );
		$nbhds = is_wp_error( $nbhds ) ? array() : $nbhds;
		sort( $nbhds );

		// Unplaced records sort last rather than first under a neighborhood sort.
		$sort_nbhd = $nbhds ? $nbhds[0] : 'zzzz';

		$address     = (string) get_post_meta( $post_id, 'ao_address', true );
		$publication = (string) get_post_meta( $post_id, 'ao_publication', true );

		$search = implode( ' ', array_filter( array( $title, $address, $publication, implode( ' ', $nbhds ) ) ) );

		update_post_meta( $post_id, self::SORT_NAME, self::key( $sort_name ) );
		update_post_meta( $post_id, self::SORT_NBHD, self::key( $sort_nbhd ) );
		update_post_meta( $post_id, self::SEARCH, self::key( $search ) );

		// Sold properties and case studies sort high → low by price.
		$price = get_post_meta( $post_id, 'ao_price', true );
		update_post_meta( $post_id, self::PRICE, $price ? (float) preg_replace( '/[^0-9.]/', '', $price ) : 0 );
	}

	/**
	 * Normalise for comparison: lowercase, punctuation out, single spaces.
	 */
	private static function key( $value ) {
		$value = remove_accents( (string) $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9 ]+/', ' ', $value );

		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Rebuild everything. Needed once after import, and after any bulk edit
	 * that bypassed save_post.
	 */
	public static function reindex_all() {
		$ids = get_posts( array(
			'post_type'      => self::types(),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $ids as $id ) {
			self::index( $id );
		}

		return count( $ids );
	}

	public static function menu() {
		add_submenu_page(
			'tools.php',
			'Andy Oei Index',
			'Andy Oei Index',
			'manage_options',
			'ao-reindex',
			array( __CLASS__, 'screen' )
		);
	}

	public static function screen() {
		$done = isset( $_GET['done'] ) ? (int) $_GET['done'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1>Andy Oei Index</h1>
			<p>Rebuilds the sort and search index for buildings, sold properties, press, insights, case studies and testimonials. Run this after an import or a bulk edit.</p>
			<?php if ( $done ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $done ); ?> records reindexed.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ao_reindex">
				<?php wp_nonce_field( 'ao_reindex' ); ?>
				<?php submit_button( 'Rebuild index' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_reindex() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ao_reindex' ) ) {
			wp_die( 'Not allowed.' );
		}

		$count = self::reindex_all();

		wp_safe_redirect( admin_url( 'tools.php?page=ao-reindex&done=' . $count ) );
		exit;
	}
}
