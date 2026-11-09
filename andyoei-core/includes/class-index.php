<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sort + search index.
 *
 * WordPress cannot order by a related record's title, and its default search
 * ignores custom fields. On every save we flatten what the directory sorts
 * and searches on into three plain meta keys.
 */
class AO_Index {

	const SORT_NAME = '_ao_sort_name';
	const SORT_NBHD = '_ao_sort_nbhd';
	const SEARCH    = '_ao_search';

	// First residential occupancy, as a timestamp, so Construction can be
	// measured against today in a query rather than stored as a tag.
	const OCCUPANCY = '_ao_occupancy';

	public static function init() {
		add_action( 'save_post_ao_building', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 20, 1 );
		add_action( 'acf/save_post', array( __CLASS__, 'index' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ao_reindex', array( __CLASS__, 'handle_reindex' ) );
	}

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::index( $post_id );
	}

	public static function on_terms( $post_id ) {
		if ( 'ao_building' === get_post_type( $post_id ) ) {
			self::index( $post_id );
		}
	}

	/**
	 * Write the index for one building.
	 */
	public static function index( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post || 'ao_building' !== $post->post_type ) {
			return;
		}

		// "The Ritz-Carlton Residences" sorts and groups under R, not T.
		$sort_name = trim( preg_replace( '/^the\s+/i', '', $post->post_title ) );

		// Unplaced buildings sort last under a neighborhood sort.
		$nbhd      = AO_Relations::name_for_building( $post_id );
		$sort_nbhd = $nbhd ? $nbhd : 'zzzz';

		$address = (string) get_post_meta( $post_id, 'ao_address', true );

		// Delivery date, at whatever precision the team had.
		$stamp = self::occupancy_stamp( (string) get_post_meta( $post_id, 'ao_delivery', true ) );

		if ( $stamp ) {
			update_post_meta( $post_id, self::OCCUPANCY, $stamp );
		} else {
			delete_post_meta( $post_id, self::OCCUPANCY );
		}

		update_post_meta( $post_id, self::SORT_NAME, self::key( $sort_name ) );
		update_post_meta( $post_id, self::SORT_NBHD, self::key( $sort_nbhd ) );

		// Spec: search covers building name and address.
		update_post_meta( $post_id, self::SEARCH, self::key( $post->post_title . ' ' . $address ) );
	}

	/**
	 * A delivery date as a timestamp, from whatever precision was entered:
	 * 2026, 2026-04 or 2026-04-15.
	 *
	 * A year or a month resolves to the first instant of that period. That
	 * is a comparison point, not a date anyone sees — the spec's rule about
	 * never inventing a day is about display, and nothing here is displayed.
	 * Taking the start of the period makes a building the oldest it could
	 * be, so a boundary case falls into the older bucket rather than
	 * claiming a newness it may not have.
	 *
	 * @return int Unix timestamp, or 0 when nothing usable was entered.
	 */
	public static function occupancy_stamp( $value ) {
		$value = trim( $value );

		if ( ! preg_match( '/^(\d{4})(?:-(\d{1,2}))?(?:-(\d{1,2}))?$/', $value, $m ) ) {
			return 0;
		}

		$year  = (int) $m[1];
		$month = isset( $m[2] ) ? max( 1, min( 12, (int) $m[2] ) ) : 1;
		$day   = isset( $m[3] ) ? max( 1, min( 31, (int) $m[3] ) ) : 1;

		if ( $year < 1800 || $year > 2200 ) {
			return 0;
		}

		return (int) gmmktime( 0, 0, 0, $month, $day, $year );
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
	 * Rebuild everything. Needed once after an import, and after any bulk
	 * edit that bypassed save_post.
	 */
	public static function reindex_all() {
		$ids = get_posts( array(
			'post_type'      => 'ao_building',
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
			'edit.php?post_type=ao_building',
			'Rebuild Index',
			'Rebuild Index',
			'manage_options',
			'ao-reindex',
			array( __CLASS__, 'screen' )
		);
	}

	public static function screen() {
		$done = isset( $_GET['done'] ) ? (int) $_GET['done'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1>Rebuild Index</h1>
			<p>Rebuilds the sort and search index for every building. Run this after an import or a bulk edit.</p>
			<?php if ( $done ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $done ); ?> buildings reindexed.</p></div>
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

		wp_safe_redirect( admin_url( 'edit.php?post_type=ao_building&page=ao-reindex&done=' . $count ) );
		exit;
	}
}
