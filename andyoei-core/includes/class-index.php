<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sort + search index.
 *
 * WordPress cannot order by a taxonomy term, and its default search ignores
 * custom fields. On every save we flatten what the directory sorts and
 * searches on into three plain meta keys.
 */
class AO_Index {

	const SORT_NAME = '_ao_sort_name';
	const SORT_NBHD = '_ao_sort_nbhd';
	const SEARCH    = '_ao_search';

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

		$nbhds = wp_get_object_terms( $post_id, 'ao_neighborhood', array( 'fields' => 'names' ) );
		$nbhds = is_wp_error( $nbhds ) ? array() : $nbhds;
		sort( $nbhds );

		// Unplaced buildings sort last under a neighborhood sort.
		$sort_nbhd = $nbhds ? $nbhds[0] : 'zzzz';

		$address = (string) get_post_meta( $post_id, 'ao_address', true );

		update_post_meta( $post_id, self::SORT_NAME, self::key( $sort_name ) );
		update_post_meta( $post_id, self::SORT_NBHD, self::key( $sort_nbhd ) );

		// Spec: search covers building name and address.
		update_post_meta( $post_id, self::SEARCH, self::key( $post->post_title . ' ' . $address ) );
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
