<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Button Link Scanner', 'button-link-scanner' ); ?> <span class="bls-version-badge">v<?php echo esc_html( BLS_VERSION ); ?></span></h1>

    <!-- Scan Controls -->
    <div class="bls-card bls-card--scan">
        <h2><?php esc_html_e( 'Site Scan', 'button-link-scanner' ); ?></h2>
        <p><?php esc_html_e( 'Scans all published pages, posts, and custom post types for buttons and links. Results replace any previous scan.', 'button-link-scanner' ); ?></p>
        <button id="bls-run-scan" class="button button-primary button-hero">
            <?php esc_html_e( 'Run Full Scan Now', 'button-link-scanner' ); ?>
        </button>
        <?php if ( $scan_abandoned ) : ?>
            <button id="bls-resume-scan" class="button button-secondary">
                <?php esc_html_e( 'Resume Interrupted Scan', 'button-link-scanner' ); ?>
            </button>
        <?php endif; ?>
        <span id="bls-scan-status" class="bls-status"></span>
        <?php if ( $scan_abandoned ) : ?>
            <p class="bls-meta" style="color:#b26b00; font-weight:600;">
                <?php printf(
                    /* translators: 1: items processed, 2: total items */
                    esc_html__( 'A previous scan was interrupted at %1$d of %2$d pages — likely from navigating away or closing the tab mid-scan. Results below reflect that incomplete run. Click "Resume Interrupted Scan" to pick up where it left off, without starting over.', 'button-link-scanner' ),
                    (int) $stuck_progress['processed'],
                    (int) $stuck_progress['total_items']
                ); ?>
            </p>
        <?php endif; ?>
        <?php if ( $last_scan ) : ?>
            <p class="bls-meta">
                <?php printf(
                    /* translators: %s: date/time */
                    esc_html__( 'Last scan: %s', 'button-link-scanner' ),
                    esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) )
                ); ?>
            </p>
        <?php endif; ?>
        <?php $last_error = get_option( 'bls_last_scan_error', '' ); ?>
        <?php if ( ! empty( $last_error ) ) : ?>
            <p class="bls-meta" style="color:#b32d2e; font-weight:600;">
                <?php esc_html_e( 'Last scan error:', 'button-link-scanner' ); ?> <?php echo esc_html( $last_error ); ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $skipped_pages ) || $confirmed_empty > 0 || $idx_vendor_pages > 0 || $offsite_redirects > 0 ) : ?>
    <div class="bls-card bls-card--notice">
        <h2><?php esc_html_e( 'Pages Not Scanned', 'button-link-scanner' ); ?></h2>

        <?php if ( $idx_vendor_pages > 0 ) : ?>
            <p>
                <?php printf(
                    /* translators: %d: number of pages */
                    esc_html__( '%d IDX page(s) skipped. Their content is served by the IDX provider from its own subdomain (search, results, listing detail, and similar), so nothing on them is editable from WordPress and there is nothing here to fix.', 'button-link-scanner' ),
                    $idx_vendor_pages
                ); ?>
            </p>
        <?php endif; ?>

        <?php if ( $offsite_redirects > 0 ) : ?>
            <p>
                <?php printf(
                    /* translators: %d: number of pages */
                    esc_html__( '%d page(s) redirect to another domain (typically an IDX provider\'s own search subdomain). They hold no content of their own, so there is nothing here to scan or fix.', 'button-link-scanner' ),
                    $offsite_redirects
                ); ?>
            </p>
        <?php endif; ?>

        <?php if ( $confirmed_empty > 0 ) : ?>
            <p>
                <?php printf(
                    /* translators: %d: number of pages */
                    esc_html__( '%d page(s) were checked live and genuinely have no buttons or links — for example a front page built entirely from widget areas. Nothing to do.', 'button-link-scanner' ),
                    $confirmed_empty
                ); ?>
            </p>
        <?php endif; ?>

        <?php if ( ! empty( $skipped_pages ) ) : ?>
            <h3 style="margin-bottom:4px;"><?php esc_html_e( 'Needs Manual Check', 'button-link-scanner' ); ?></h3>
            <p><?php esc_html_e( 'These had no scannable content and could not be verified by fetching them live — either the fetch failed, or there were more flagged pages than the per-scan recheck limit. Worth a look:', 'button-link-scanner' ); ?></p>
            <ul>
                <?php foreach ( $skipped_pages as $p ) : ?>
                    <li><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['title'] ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <?php if ( $summary && (int) $summary['total_buttons'] > 0 ) : ?>
    <div class="bls-summary-grid">
        <div class="bls-stat-card bls-stat-card--info">
            <span class="bls-stat-number"><?php echo (int) $summary['total_buttons']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Buttons & Links Found', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--info">
            <span class="bls-stat-number"><?php echo (int) $summary['posts_scanned']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Posts / Pages Scanned', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--success">
            <span class="bls-stat-number"><?php echo (int) $summary['with_link']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Has a URL', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--danger">
            <span class="bls-stat-number"><?php echo (int) $summary['without_link']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'No URL Set', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--warning">
            <span class="bls-stat-number"><?php echo (int) $summary['missing_title']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Missing SEO Title', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--success">
            <span class="bls-stat-number"><?php echo (int) $summary['complete']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Fully Configured', 'button-link-scanner' ); ?></span>
        </div>
    </div>

    <p class="bls-meta">
        <?php printf(
            /* translators: 1: button count, 2: hyperlink count */
            esc_html__( 'Of those: %1$s styled buttons and %2$s plain text links — ', 'button-link-scanner' ),
            '<a href="' . esc_url( admin_url( 'admin.php?page=button-link-scanner-results&kind=buttons' ) ) . '">' . number_format_i18n( (int) $summary['button_count'] ) . '</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=button-link-scanner-results&kind=hyperlink' ) ) . '">' . number_format_i18n( (int) $summary['hyperlink_count'] ) . '</a>'
        ); ?>
        <?php esc_html_e( 'tracked separately so each can be sorted, filtered, and bulk-applied on its own.', 'button-link-scanner' ); ?>
    </p>

    <!-- Quick-action links -->
    <div class="bls-quick-actions">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-results&has_link=0' ) ); ?>" class="button button-secondary">
            <?php esc_html_e( 'View Links With No URL', 'button-link-scanner' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-results&has_link=1&has_title=0' ) ); ?>" class="button button-secondary">
            <?php esc_html_e( 'View Missing SEO Titles', 'button-link-scanner' ); ?>
        </a>
        <button id="bls-auto-fill-titles" class="button button-secondary">
            <?php esc_html_e( 'Auto-Fill Missing Titles', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-auto-fill-status" class="bls-status"></span>
        <button id="bls-wipe-titles" class="button button-link-delete" style="color:#b32d2e;">
            <?php esc_html_e( 'Remove Auto-Filled Titles', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-wipe-status" class="bls-status"></span>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-map' ) ); ?>" class="button button-primary">
            <?php esc_html_e( 'Manage Button Map & Trends', 'button-link-scanner' ); ?>
        </a>
    </div>

    <?php if ( $wipe_abandoned ) : ?>
        <p class="bls-meta" id="bls-wipe-abandoned-notice" style="color:#b26b00; font-weight:600; margin:4px 0 12px;">
            <?php printf(
                /* translators: 1: posts processed, 2: total posts */
                esc_html__( 'A previous title removal was interrupted at %1$d of %2$d pages — likely from navigating away or reloading mid-run. Resuming automatically...', 'button-link-scanner' ),
                (int) $stuck_wipe_progress['posts_processed'],
                (int) $stuck_wipe_progress['total_items']
            ); ?>
        </p>
    <?php endif; ?>

    <?php if ( ! empty( $wipe_result ) ) : ?>
    <div class="bls-card bls-card--notice" id="bls-wipe-result">
        <p>
            <strong><?php esc_html_e( 'Last title removal:', 'button-link-scanner' ); ?></strong>
            <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wipe_result['time'] ) ); ?>
        </p>
        <?php if ( ! empty( $wipe_result['error'] ) ) : ?>
            <p style="color:#b32d2e; font-weight:600;"><?php echo esc_html( $wipe_result['error'] ); ?></p>
        <?php else : ?>
            <ul style="margin:6px 0 0 20px; list-style:disc;">
                <li><?php printf( esc_html__( 'Titles removed from content: %d', 'button-link-scanner' ), (int) $wipe_result['titles_removed'] ); ?></li>
                <li><?php printf( esc_html__( 'Pages changed: %d', 'button-link-scanner' ), (int) $wipe_result['posts_changed'] ); ?></li>
                <li><?php printf( esc_html__( 'Queued render-time titles cleared: %d', 'button-link-scanner' ), (int) $wipe_result['injections_cleared'] ); ?></li>
            </ul>
            <?php if ( ! empty( $wipe_result['changes'] ) ) : ?>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer; font-weight:600;">
                        <?php printf(
                            /* translators: %d: number of removals */
                            esc_html__( 'Show exactly what was removed (%d)', 'button-link-scanner' ),
                            count( $wipe_result['changes'] )
                        ); ?>
                    </summary>
                    <table class="widefat striped" style="margin-top:8px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Page', 'button-link-scanner' ); ?></th>
                                <th><?php esc_html_e( 'Button / link text', 'button-link-scanner' ); ?></th>
                                <th><?php esc_html_e( 'Title removed', 'button-link-scanner' ); ?></th>
                                <th><?php esc_html_e( 'From', 'button-link-scanner' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $wipe_result['changes'] as $c ) : ?>
                            <tr>
                                <td>
                                    <?php if ( ! empty( $c['post_url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $c['post_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $c['post_title'] ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $c['post_title'] ); ?>
                                    <?php endif; ?>
                                    <br><span class="bls-meta">ID <?php echo (int) $c['post_id']; ?></span>
                                </td>
                                <td><?php echo esc_html( $c['button_text'] ); ?></td>
                                <td><del><?php echo esc_html( $c['removed_title'] ); ?></del></td>
                                <td><span class="bls-meta"><?php echo esc_html( $c['source'] ); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ( ! empty( $wipe_result['changes_truncated'] ) ) : ?>
                        <p class="bls-meta" style="margin-top:6px;">
                            <?php printf(
                                esc_html__( 'This table shows the first %1$d only — %2$d further removal(s) were applied. The CSV below has every one of them.', 'button-link-scanner' ),
                                (int) BLS_Updater::AUDIT_LOG_LIMIT,
                                (int) $wipe_result['changes_truncated']
                            ); ?>
                        </p>
                    <?php endif; ?>
                </details>
            <?php endif; ?>

            <?php if ( ! empty( $wipe_result['log_url'] ) ) : ?>
                <p style="margin-top:10px;">
                    <a href="<?php echo esc_url( $wipe_result['log_url'] ); ?>" class="button button-secondary" download>
                        <?php esc_html_e( 'Download full log (CSV)', 'button-link-scanner' ); ?>
                    </a>
                    <span class="bls-meta" style="margin-left:6px;"><?php esc_html_e( 'Every removal from this run, with no row limit.', 'button-link-scanner' ); ?></span>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ( $autofill_abandoned ) : ?>
        <!-- Marker element only — admin.js detects this by ID to auto-resume
             on page load, same pattern as the scan's #bls-resume-scan check. -->
        <p class="bls-meta" id="bls-autofill-abandoned-notice" style="color:#b26b00; font-weight:600; margin: 4px 0 12px;">
            <?php printf(
                /* translators: 1: pairs processed, 2: total pairs */
                esc_html__( 'A previous Auto-Fill run was interrupted at %1$d of %2$d button/link combinations — likely from navigating away or reloading mid-run. Resuming automatically...', 'button-link-scanner' ),
                (int) $stuck_autofill_progress['pairs_processed'],
                (int) $stuck_autofill_progress['total_items']
            ); ?>
        </p>
    <?php endif; ?>

    <?php if ( ! empty( $auto_fill_result ) ) : ?>
    <div class="bls-card bls-card--notice" id="bls-auto-fill-result">
        <p>
            <strong><?php esc_html_e( 'Last Auto-Fill run:', 'button-link-scanner' ); ?></strong>
            <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $auto_fill_result['time'] ) ); ?>
        </p>
        <?php if ( ! empty( $auto_fill_result['error'] ) ) : ?>
            <p style="color:#b32d2e; font-weight:600;"><?php echo esc_html( $auto_fill_result['error'] ); ?></p>
        <?php else : ?>
            <ul style="margin:6px 0 0 20px; list-style:disc;">
                <li><?php printf( esc_html__( 'Distinct button/link combinations checked: %d', 'button-link-scanner' ), (int) $auto_fill_result['pairs_processed'] ); ?></li>
                <li><?php printf( esc_html__( 'Titles added directly to content: %d', 'button-link-scanner' ), (int) $auto_fill_result['titles_added'] ); ?></li>
                <li><?php printf( esc_html__( 'Titles applied live at render time (dynamic/shortcode-generated buttons — e.g. an IDX listing template — where there\'s no stored anchor to write to directly): %d', 'button-link-scanner' ), (int) $auto_fill_result['titles_injected'] ); ?></li>
                <li><?php printf( esc_html__( 'Pages updated: %d', 'button-link-scanner' ), (int) $auto_fill_result['posts_updated'] ); ?></li>
                <?php if ( ! empty( $auto_fill_result['already_titled'] ) ) : ?>
                    <li><?php printf( esc_html__( 'Already had a title — nothing to do: %d', 'button-link-scanner' ), (int) $auto_fill_result['already_titled'] ); ?></li>
                <?php endif; ?>
                <li style="<?php echo (int) $auto_fill_result['could_not_apply'] > 0 ? 'color:#b26b00; font-weight:600;' : ''; ?>">
                    <?php printf( esc_html__( 'Could not be applied automatically: %d', 'button-link-scanner' ), (int) $auto_fill_result['could_not_apply'] ); ?>
                </li>
                <?php if ( (int) $auto_fill_result['could_not_apply'] > 0 ) : ?>
                <ul style="margin:4px 0 0 20px; list-style:circle;">
                    <li>
                        <?php printf(
                            /* translators: %d: number of buttons */
                            esc_html__( 'No matching anchor found anywhere, even in fully rendered content — a genuine dead end (likely a live-only fetch with no post behind it): %d', 'button-link-scanner' ),
                            (int) $auto_fill_result['not_in_database']
                        ); ?>
                    </li>
                    <li>
                        <?php printf(
                            /* translators: %d: number of buttons */
                            esc_html__( 'Found in content but blocked by an href/title mismatch (a real, fixable data issue): %d', 'button-link-scanner' ),
                            (int) $auto_fill_result['blocked_by_mismatch']
                        ); ?>
                    </li>
                </ul>
                <?php endif; ?>
            </ul>
            <?php if ( (int) $auto_fill_result['pairs_processed'] === 0 ) : ?>
                <p style="margin-top:8px;"><em><?php esc_html_e( 'Zero pairs checked means the scan found no buttons currently missing a title at the time this ran — either everything is already covered, or a fresh scan hasn\'t been run since content last changed.', 'button-link-scanner' ); ?></em></p>
            <?php endif; ?>

            <?php if ( ! empty( $auto_fill_result['changes'] ) ) : ?>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer; font-weight:600;">
                        <?php printf(
                            /* translators: %d: number of changes */
                            esc_html__( 'Show exactly what was changed (%d)', 'button-link-scanner' ),
                            count( $auto_fill_result['changes'] )
                        ); ?>
                    </summary>
                    <table class="widefat striped" style="margin-top:8px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Page', 'button-link-scanner' ); ?></th>
                                <th><?php esc_html_e( 'Button / link text', 'button-link-scanner' ); ?></th>
                                <th><?php esc_html_e( 'Links to', 'button-link-scanner' ); ?></th>
                                <th><?php esc_html_e( 'Title set to', 'button-link-scanner' ); ?></th>
                                <th><?php esc_html_e( 'How', 'button-link-scanner' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $auto_fill_result['changes'] as $c ) : ?>
                            <tr>
                                <td>
                                    <?php if ( ! empty( $c['post_url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $c['post_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $c['post_title'] ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $c['post_title'] ); ?>
                                    <?php endif; ?>
                                    <br><span class="bls-meta">ID <?php echo (int) $c['post_id']; ?></span>
                                </td>
                                <td><?php echo esc_html( $c['button_text'] ); ?></td>
                                <td style="word-break:break-all; font-size:0.85em;"><?php echo esc_html( $c['link_url'] ); ?></td>
                                <td><strong><?php echo esc_html( $c['new_title'] ); ?></strong>
                                    <?php if ( (int) $c['count'] > 1 ) : ?>
                                        <br><span class="bls-meta"><?php printf( esc_html__( '%d occurrences on this page', 'button-link-scanner' ), (int) $c['count'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $c['method'] === 'render' ) : ?>
                                        <span title="<?php esc_attr_e( 'Applied live on each page load — nothing was changed in the database, because this button has no stored anchor to write to.', 'button-link-scanner' ); ?>"><?php esc_html_e( 'Live (render)', 'button-link-scanner' ); ?></span>
                                    <?php else : ?>
                                        <span title="<?php esc_attr_e( 'Written directly into the stored page content.', 'button-link-scanner' ); ?>"><?php esc_html_e( 'Saved to content', 'button-link-scanner' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ( ! empty( $auto_fill_result['changes_truncated'] ) ) : ?>
                        <p class="bls-meta" style="margin-top:6px;">
                            <?php printf(
                                /* translators: %d: number of additional changes not listed */
                                esc_html__( 'This table shows the first %1$d only — %2$d further change(s) were applied. The CSV below has every one of them.', 'button-link-scanner' ),
                                (int) BLS_Updater::AUDIT_LOG_LIMIT,
                                (int) $auto_fill_result['changes_truncated']
                            ); ?>
                        </p>
                    <?php endif; ?>
                </details>
            <?php endif; ?>

            <?php if ( ! empty( $auto_fill_result['log_url'] ) ) : ?>
                <p style="margin-top:10px;">
                    <a href="<?php echo esc_url( $auto_fill_result['log_url'] ); ?>" class="button button-secondary" download>
                        <?php esc_html_e( 'Download full log (CSV)', 'button-link-scanner' ); ?>
                    </a>
                    <span class="bls-meta" style="margin-left:6px;"><?php esc_html_e( 'Every change from this run, with no row limit.', 'button-link-scanner' ); ?></span>
                </p>
            <?php endif; ?>
            <?php if ( ! empty( $auto_fill_result['diagnostic'] ) ) :
                $diag = $auto_fill_result['diagnostic'];
            ?>
                <div style="margin-top:12px; padding:10px 12px; background:#fff8e5; border-left:3px solid #b26b00;">
                    <p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Automated diagnosis of the first failure:', 'button-link-scanner' ); ?></strong></p>
                    <p style="margin:0 0 4px;">
                        <?php printf(
                            /* translators: 1: button text, 2: post title */
                            esc_html__( 'Button "%1$s" on "%2$s" —', 'button-link-scanner' ),
                            esc_html( $diag['button_text'] ),
                            esc_html( $diag['post_title'] )
                        ); ?>
                    </p>
                    <?php if ( empty( $diag['candidates'] ) ) : ?>
                        <p style="margin:0;"><?php esc_html_e( 'No anchor with matching text exists in ANY writable source — post content, WooCommerce excerpt, block template, custom fields, OR the fully rendered content (shortcodes/widgets expanded), even after full Unicode-aware normalization. That last check is the one that catches plugin/theme-generated buttons (an IDX listing template, for example); coming up empty even there means this button was found via a live-only fetch with no actual post behind it at all — a genuine dead end, not something Auto-Fill (or its render-time injection) can reach.', 'button-link-scanner' ); ?></p>
                    <?php else : ?>
                        <p style="margin:0;"><?php esc_html_e( 'A matching anchor WAS found (see candidates below), but it was skipped because its href didn\'t match the target URL, or it already has a title. This is a real, fixable data issue — not a shortcode/rendering limitation.', 'button-link-scanner' ); ?></p>
                    <?php endif; ?>
                    <p style="margin:6px 0 0;"><em><?php esc_html_e( 'Raw substring check (informational only):', 'button-link-scanner' ); ?></em>
                        <?php printf(
                            /* translators: 1: yes/no, 2: yes/no */
                            esc_html__( ' text found: %1$s, href found: %2$s', 'button-link-scanner' ),
                            $diag['text_in_raw'] ? esc_html__( 'yes', 'button-link-scanner' ) : esc_html__( 'no', 'button-link-scanner' ),
                            $diag['href_in_raw'] ? esc_html__( 'yes', 'button-link-scanner' ) : esc_html__( 'no', 'button-link-scanner' )
                        ); ?>
                    </p>
                    <p style="margin:6px 0 0; font-family:monospace; font-size:0.8em; background:#fff; padding:6px; border:1px solid #e2d5b4; white-space:pre-wrap; word-break:break-all;"><?php echo esc_html( $diag['content_snippet'] ); ?>&hellip;</p>
                    <?php if ( ! empty( $diag['candidates'] ) ) : ?>
                        <p style="margin:10px 0 4px;"><strong><?php esc_html_e( 'Anchors found with matching text:', 'button-link-scanner' ); ?></strong></p>
                        <?php foreach ( $diag['candidates'] as $c ) : ?>
                            <p style="margin:0; font-family:monospace; font-size:0.8em;">
                                href: <?php echo esc_html( $c['href'] ); ?>
                                — href matches: <?php echo $c['href_matched'] ? 'YES' : 'NO'; ?>
                                — already has title: <?php echo $c['has_title'] ? 'YES' : 'NO'; ?>
                            </p>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p style="margin:10px 0 0;"><em><?php esc_html_e( 'No anchors anywhere in raw content had matching text at all, even after normalization.', 'button-link-scanner' ); ?></em></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php else : ?>
    <div class="bls-card bls-card--notice">
        <p><?php esc_html_e( 'No scan results yet. Run a scan to get started.', 'button-link-scanner' ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Broken Link Monitoring -->
    <div class="bls-card">
        <h2><?php esc_html_e( 'Broken Link Monitoring', 'button-link-scanner' ); ?></h2>
        <p><?php esc_html_e( 'Checks that every linked button still actually resolves — not a 404, server error, or timeout. Runs in the background on a schedule and emails you the moment a link first breaks.', 'button-link-scanner' ); ?></p>

        <?php if ( $broken_count > 0 ) : ?>
            <p class="bls-meta" style="color:#b32d2e; font-weight:600;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-broken-links' ) ); ?>">
                    <?php printf(
                        esc_html( _n( '%d broken link found — view report', '%d broken links found — view report', $broken_count, 'button-link-scanner' ) ),
                        (int) $broken_count
                    ); ?>
                </a>
            </p>
        <?php elseif ( ! BLS_Link_Checker::has_ever_run() ) : ?>
            <p class="bls-meta" style="color:#b26b00; font-weight:600;">
                <?php esc_html_e( 'Links have never been checked on this site — this is not a clean bill of health, just no data yet. Run a check below.', 'button-link-scanner' ); ?>
            </p>
        <?php else : ?>
            <p class="bls-meta" style="color:#1a7a3c; font-weight:600;">
                <?php esc_html_e( 'No broken links found in the last check.', 'button-link-scanner' ); ?>
            </p>
        <?php endif; ?>

        <?php $unverified = BLS_Link_Checker::get_unverified_count(); ?>
        <?php if ( $unverified > 0 ) : ?>
            <p class="bls-meta">
                <?php printf(
                    /* translators: %d: number of links */
                    esc_html__( '%d link(s) could not be verified — the server answered with a login wall, bot protection, or a rate limit (401/403/408/429), or timed out. That does not mean they are dead, so they are not counted as broken and are never emailed.', 'button-link-scanner' ),
                    $unverified
                ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-broken-links' ) ); ?>"><?php esc_html_e( 'See the list', 'button-link-scanner' ); ?></a>
            </p>
        <?php endif; ?>

        <button id="bls-check-links-now" class="button button-secondary">
            <?php esc_html_e( 'Check Links Now', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-link-check-status" class="bls-status"></span>
        <?php if ( $link_check_last ) : ?>
            <p class="bls-meta">
                <?php printf(
                    esc_html__( 'Last checked: %s', 'button-link-scanner' ),
                    esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $link_check_last ) )
                ); ?>
            </p>
        <?php endif; ?>

        <hr style="margin:16px 0;">

        <p><strong><?php esc_html_e( 'Automatic checking', 'button-link-scanner' ); ?></strong></p>
        <select id="bls-link-check-freq">
            <option value=""       <?php selected( $link_check_freq, '' ); ?>><?php esc_html_e( 'Off', 'button-link-scanner' ); ?></option>
            <option value="daily"  <?php selected( $link_check_freq, 'daily' ); ?>><?php esc_html_e( 'Daily', 'button-link-scanner' ); ?></option>
            <option value="weekly" <?php selected( $link_check_freq, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'button-link-scanner' ); ?></option>
        </select>
        <input type="email" id="bls-link-check-email" value="<?php echo esc_attr( $link_check_email ); ?>" placeholder="<?php esc_attr_e( 'Notification email', 'button-link-scanner' ); ?>" style="width:260px;">
        <button id="bls-save-link-schedule" class="button button-secondary">
            <?php esc_html_e( 'Save', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-link-schedule-status" class="bls-status"></span>
    </div>
</div>
