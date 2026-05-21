<?php
/**
 * Admin UI — menu page, AJAX handler, dashboard widget.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Admin {

	public static function init() : void {
		add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_kbs_run_scan',  array( __CLASS__, 'ajax_run_scan' ) );
		add_action( 'wp_dashboard_setup',    array( __CLASS__, 'add_dashboard_widget' ) );
		add_action( 'admin_notices',         array( __CLASS__, 'outage_notice' ) );
	}

	public static function add_menu() : void {
		add_menu_page(
			'Kadence Block Scanner',
			'Kadence Scanner',
			'manage_options',
			'kadence-block-scanner',
			array( __CLASS__, 'render_page' ),
			'dashicons-search',
			80
		);
	}

	public static function enqueue( string $hook ) : void {
		if ( $hook !== 'toplevel_page_kadence-block-scanner' ) return;

		wp_enqueue_style(
			'kbs-admin',
			KBS_URL . 'assets/admin.css',
			array(),
			KBS_VERSION
		);

		wp_enqueue_script(
			'kbs-admin',
			KBS_URL . 'assets/admin.js',
			array( 'jquery' ),
			KBS_VERSION,
			true
		);

		wp_localize_script( 'kbs-admin', 'KBS', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'kbs_scan' ),
		) );
	}

	/** ---------------------------------------------------------------
	 * AJAX: run the scan and return JSON
	 * --------------------------------------------------------------- */
	public static function ajax_run_scan() : void {
		check_ajax_referer( 'kbs_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$results = KBS_Scanner::run_full_scan();
		$summary = KBS_Scanner::summarise( $results );
		$meta    = KBS_Scanner::get_scan_meta();

		wp_send_json_success( array(
			'summary' => $summary,
			'results' => $results,
			'meta'    => $meta,
		) );
	}

	/** ---------------------------------------------------------------
	 * Admin notice when Kadence servers appear down
	 * --------------------------------------------------------------- */
	public static function outage_notice() : void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$results = KBS_Scanner::get_cached_results();
		if ( empty( $results['connectivity'] ) ) return;

		$has_outage = false;
		foreach ( $results['connectivity'] as $c ) {
			if ( $c['status'] === 'error' && strpos( $c['message'] ?? '', 'Liquid Web' ) !== false ) {
				$has_outage = true;
				break;
			}
		}

		if ( ! $has_outage ) return;

		$page = admin_url( 'admin.php?page=kadence-block-scanner' );
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s: scanner URL */
			wp_kses_post( __( '<strong>Kadence Outage Detected:</strong> kadencewp.com is currently redirecting to Liquid Web. Pro license validation and remote assets may be broken on your site. <a href="%s">View Kadence Scanner report</a>.', 'kadence-block-scanner' ) ),
			esc_url( $page )
		);
		echo '</p></div>';
	}

	/** ---------------------------------------------------------------
	 * Dashboard widget
	 * --------------------------------------------------------------- */
	public static function add_dashboard_widget() : void {
		wp_add_dashboard_widget(
			'kbs_dashboard_widget',
			'Kadence Block Health',
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	public static function render_dashboard_widget() : void {
		$results = KBS_Scanner::get_cached_results();
		$meta    = KBS_Scanner::get_scan_meta();

		if ( empty( $results ) ) {
			echo '<p>No scan has been run yet. <a href="' . esc_url( admin_url( 'admin.php?page=kadence-block-scanner' ) ) . '">Run a scan now</a>.</p>';
			return;
		}

		$summary = KBS_Scanner::summarise( $results );
		$icon    = $summary['connectivity_errors'] > 0 ? '🔴' : ( $summary['total_issues'] > 0 ? '🟡' : '🟢' );

		echo '<p>' . esc_html( $icon ) . ' Last scan: ' . esc_html( $meta['scanned_at'] ?? 'unknown' ) . '</p>';
		echo '<ul style="margin:0;padding-left:1.2em">';
		echo '<li>Kadence server errors: <strong>' . intval( $summary['connectivity_errors'] ) . '</strong></li>';
		echo '<li>License issues: <strong>' . intval( $summary['license_issues'] ) . '</strong></li>';
		echo '<li>Affected posts: <strong>' . intval( $summary['affected_posts'] ) . '</strong></li>';
		echo '<li>Total block issues: <strong>' . intval( $summary['total_issues'] ) . '</strong></li>';
		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=kadence-block-scanner' ) ) . '">Full report →</a></p>';
	}

	/** ---------------------------------------------------------------
	 * Main admin page
	 * --------------------------------------------------------------- */
	public static function render_page() : void {
		$results = KBS_Scanner::get_cached_results();
		$meta    = KBS_Scanner::get_scan_meta();
		$summary = ! empty( $results ) ? KBS_Scanner::summarise( $results ) : null;
		?>
		<div class="wrap kbs-wrap">
			<h1>
				<span class="dashicons dashicons-search"></span>
				Kadence Block Scanner
			</h1>

			<div class="kbs-toolbar">
				<button id="kbs-run-scan" class="button button-primary button-hero">
					Run Full Scan
				</button>
				<span id="kbs-scan-status"></span>
			</div>

			<?php if ( ! empty( $meta ) ) : ?>
			<p class="kbs-meta">
				Last scanned: <strong><?php echo esc_html( $meta['scanned_at'] ); ?></strong>
				&nbsp;&mdash;&nbsp;
				<?php echo esc_html( $meta['total_posts'] ); ?> posts checked in
				<?php echo esc_html( $meta['duration'] ); ?>s
			</p>
			<?php endif; ?>

			<div id="kbs-results-wrap">
				<?php if ( ! empty( $results ) ) self::render_results( $results, $summary ); ?>
			</div>
		</div>

		<script>
		// Inline template rendered after AJAX (mirrors PHP render_results)
		// Full render happens server-side via page reload after scan.
		</script>
		<?php
	}

	private static function render_results( array $results, ?array $summary ) : void {
		// ---- Outage banner ----
		$server_ok = true;
		foreach ( $results['connectivity'] ?? array() as $c ) {
			if ( $c['status'] === 'error' ) { $server_ok = false; break; }
		}

		if ( ! $server_ok ) {
			echo '<div class="kbs-outage-banner">';
			echo '<h2>⚠️ Kadence Server Outage Detected</h2>';
			echo '<p>One or more Kadence servers are unreachable. This is likely caused by the <strong>kadencewp.com → Liquid Web redirect</strong> that is currently affecting Kadence-powered sites. The issues below may be caused or worsened by this outage.</p>';
			echo '<p><strong>Immediate mitigations:</strong></p>';
			echo '<ul>';
			echo '<li>Pro license features may silently degrade — check your site\'s front end now.</li>';
			echo '<li>Disable Kadence license auto-renewal pings temporarily to prevent repeated failed requests slowing wp-admin.</li>';
			echo '<li>If your fonts load from Kadence\'s CDN, consider switching to locally hosted fonts.</li>';
			echo '<li>If Kadence Starter Templates rely on remote content, they will fail to load — this won\'t break existing pages.</li>';
			echo '</ul>';
			echo '</div>';
		}

		// ---- Connectivity table ----
		echo '<h2>Kadence Server Connectivity</h2>';
		echo '<table class="kbs-table widefat striped">';
		echo '<thead><tr><th>Host</th><th>Status</th><th>Details</th></tr></thead><tbody>';
		foreach ( $results['connectivity'] ?? array() as $c ) {
			$cls = $c['status'] === 'ok' ? 'kbs-ok' : ( $c['status'] === 'error' ? 'kbs-error' : 'kbs-warning' );
			echo '<tr class="' . esc_attr( $cls ) . '">';
			echo '<td>' . esc_html( $c['host'] ) . '</td>';
			echo '<td>' . esc_html( strtoupper( $c['status'] ) ) . '</td>';
			echo '<td>' . esc_html( $c['message'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// ---- Installed Kadence plugins ----
		if ( ! empty( $results['plugins'] ) ) {
			echo '<h2>Installed Kadence Products</h2>';
			echo '<table class="kbs-table widefat striped">';
			echo '<thead><tr><th>Plugin / Theme</th><th>Version</th><th>Active</th><th>Pro</th></tr></thead><tbody>';
			foreach ( $results['plugins'] as $path => $p ) {
				echo '<tr>';
				echo '<td>' . esc_html( $p['name'] ) . '</td>';
				echo '<td>' . esc_html( $p['version'] ) . '</td>';
				echo '<td>' . ( $p['active'] ? '<span class="kbs-ok">Yes</span>' : '<span class="kbs-error">No</span>' ) . '</td>';
				echo '<td>' . ( $p['pro'] ? '<span class="kbs-warning">Pro</span>' : '—' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// ---- License issues ----
		if ( ! empty( $results['licenses'] ) ) {
			echo '<h2>License Status Issues</h2>';
			echo '<table class="kbs-table widefat striped">';
			echo '<thead><tr><th>Option</th><th>Stored Value</th><th>Notes</th></tr></thead><tbody>';
			foreach ( $results['licenses'] as $lic ) {
				echo '<tr class="kbs-error">';
				echo '<td>' . esc_html( $lic['option'] ) . '</td>';
				echo '<td><code>' . esc_html( $lic['status'] ) . '</code></td>';
				echo '<td>' . esc_html( $lic['message'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// ---- Asset check ----
		if ( ! empty( $results['asset_check'] ) ) {
			echo '<div class="kbs-warning-box">';
			foreach ( $results['asset_check'] as $a ) {
				echo '<p><strong>Asset Warning:</strong> ' . esc_html( $a['message'] ) . '</p>';
			}
			echo '</div>';
		}

		// ---- Summary counts ----
		if ( $summary ) {
			echo '<h2>Block Issue Summary</h2>';
			if ( empty( $summary['issue_types'] ) ) {
				echo '<p class="kbs-ok-text">✅ No block-level issues found.</p>';
			} else {
				echo '<table class="kbs-table widefat" style="max-width:480px">';
				echo '<thead><tr><th>Issue Type</th><th>Count</th></tr></thead><tbody>';
				arsort( $summary['issue_types'] );
				foreach ( $summary['issue_types'] as $type => $count ) {
					echo '<tr><td>' . esc_html( str_replace( '_', ' ', $type ) ) . '</td><td><strong>' . intval( $count ) . '</strong></td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		// ---- Per-post issues ----
		if ( ! empty( $results['posts'] ) ) {
			echo '<h2>Affected Posts & Pages (' . count( $results['posts'] ) . ')</h2>';
			foreach ( $results['posts'] as $post_data ) {
				echo '<div class="kbs-post-card">';
				echo '<div class="kbs-post-header">';
				echo '<strong>' . esc_html( $post_data['post_title'] ) . '</strong>';
				echo ' <span class="kbs-post-type">' . esc_html( $post_data['post_type'] ) . '</span>';
				echo ' <span class="kbs-issue-count">' . count( $post_data['issues'] ) . ' issue(s)</span>';
				if ( $post_data['edit_url'] ) {
					echo ' &nbsp;<a href="' . esc_url( $post_data['edit_url'] ) . '" target="_blank">Edit</a>';
				}
				if ( $post_data['view_url'] ) {
					echo ' <a href="' . esc_url( $post_data['view_url'] ) . '" target="_blank">View</a>';
				}
				echo '</div>';

				echo '<table class="kbs-table widefat">';
				echo '<thead><tr><th>Block</th><th>Issue Type</th><th>Message</th><th>Detail</th></tr></thead><tbody>';
				foreach ( $post_data['issues'] as $issue ) {
					$row_cls = in_array( $issue['type'] ?? '', array( 'missing_image', 'missing_element', 'malformed_attrs', 'remote_asset' ), true )
						? 'kbs-error' : 'kbs-warning';
					echo '<tr class="' . esc_attr( $row_cls ) . '">';
					echo '<td><code>' . esc_html( $issue['block'] ?? '—' ) . '</code></td>';
					echo '<td>' . esc_html( str_replace( '_', ' ', $issue['type'] ?? '—' ) ) . '</td>';
					echo '<td>' . esc_html( $issue['message'] ?? '' ) . '</td>';
					echo '<td><small>' . esc_html( $issue['detail'] ?? '' ) . '</small></td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
				echo '</div>';
			}
		} elseif ( ! empty( $results ) ) {
			echo '<p class="kbs-ok-text">✅ No Kadence block issues found in any posts.</p>';
		}
	}
}
