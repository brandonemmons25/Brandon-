<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Button Link Scanner', 'button-link-scanner' ); ?></h1>

    <!-- Scan Controls -->
    <div class="bls-card bls-card--scan">
        <h2><?php esc_html_e( 'Site Scan', 'button-link-scanner' ); ?></h2>
        <p><?php esc_html_e( 'Scans all published pages, posts, and custom post types for buttons. Results replace any previous scan.', 'button-link-scanner' ); ?></p>
        <button id="bls-run-scan" class="button button-primary button-hero">
            <?php esc_html_e( 'Run Full Scan Now', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-scan-status" class="bls-status"></span>
        <?php if ( $last_scan ) : ?>
            <p class="bls-meta">
                <?php printf(
                    /* translators: %s: date/time */
                    esc_html__( 'Last scan: %s', 'button-link-scanner' ),
                    esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_scan ) ) )
                ); ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- Summary Cards -->
    <?php if ( $summary && (int) $summary['total_buttons'] > 0 ) : ?>
    <div class="bls-summary-grid">
        <div class="bls-stat-card bls-stat-card--info">
            <span class="bls-stat-number"><?php echo (int) $summary['total_buttons']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Total Buttons', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--info">
            <span class="bls-stat-number"><?php echo (int) $summary['posts_scanned']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Posts / Pages Scanned', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--success">
            <span class="bls-stat-number"><?php echo (int) $summary['with_link']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Buttons With Link', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--danger">
            <span class="bls-stat-number"><?php echo (int) $summary['without_link']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Buttons Missing Link', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--warning">
            <span class="bls-stat-number"><?php echo (int) $summary['missing_title']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Links Missing SEO Title', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--success">
            <span class="bls-stat-number"><?php echo (int) $summary['complete']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Fully Configured', 'button-link-scanner' ); ?></span>
        </div>
    </div>

    <!-- Quick-action links -->
    <div class="bls-quick-actions">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-results&has_link=0' ) ); ?>" class="button button-secondary">
            <?php esc_html_e( 'View Unlinked Buttons', 'button-link-scanner' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-results&has_link=1&has_title=0' ) ); ?>" class="button button-secondary">
            <?php esc_html_e( 'View Buttons Missing Title', 'button-link-scanner' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-trends' ) ); ?>" class="button button-secondary">
            <?php esc_html_e( 'View Link Trends', 'button-link-scanner' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-map' ) ); ?>" class="button button-primary">
            <?php esc_html_e( 'Manage Button Map', 'button-link-scanner' ); ?>
        </a>
    </div>
    <?php else : ?>
    <div class="bls-card bls-card--notice">
        <p><?php esc_html_e( 'No scan results yet. Run a scan to get started.', 'button-link-scanner' ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Scheduled Scanning -->
    <div class="bls-card">
        <h2><?php esc_html_e( 'Automated Scanning', 'button-link-scanner' ); ?></h2>
        <p><?php esc_html_e( 'Automatically re-scan the site on a schedule so the results stay current.', 'button-link-scanner' ); ?></p>
        <select id="bls-schedule-freq">
            <option value="" <?php selected( $schedule_freq, '' ); ?>><?php esc_html_e( 'Disabled', 'button-link-scanner' ); ?></option>
            <option value="daily"      <?php selected( $schedule_freq, 'daily' ); ?>><?php esc_html_e( 'Daily', 'button-link-scanner' ); ?></option>
            <option value="twicedaily" <?php selected( $schedule_freq, 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'button-link-scanner' ); ?></option>
            <option value="weekly"     <?php selected( $schedule_freq, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'button-link-scanner' ); ?></option>
        </select>
        <button id="bls-save-schedule" class="button button-secondary">
            <?php esc_html_e( 'Save Schedule', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-schedule-status" class="bls-status"></span>
        <?php if ( $scheduled ) : ?>
            <p class="bls-meta">
                <?php printf(
                    esc_html__( 'Next scheduled scan: %s', 'button-link-scanner' ),
                    esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scheduled ) )
                ); ?>
            </p>
        <?php endif; ?>
    </div>
</div>
