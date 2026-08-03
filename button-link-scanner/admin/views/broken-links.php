<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Broken Links', 'button-link-scanner' ); ?> <span class="bls-version-badge">v<?php echo esc_html( BLS_VERSION ); ?></span></h1>
    <p><?php esc_html_e( 'Every button link that failed its last health check (404, server error, or unreachable). A link is checked in the background on whatever schedule is set on the Dashboard, or on demand below.', 'button-link-scanner' ); ?></p>

    <p>
        <button id="bls-check-links-now" class="button button-primary">
            <?php esc_html_e( 'Check Links Now', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-link-check-status" class="bls-status"></span>
    </p>

    <?php if ( $last_run ) : ?>
        <p class="bls-meta">
            <?php printf(
                esc_html__( 'Last checked: %s', 'button-link-scanner' ),
                esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) )
            ); ?>
        </p>
    <?php endif; ?>

    <?php if ( empty( $broken_links ) && ! $last_run ) : ?>
        <div class="bls-card bls-card--notice">
            <p><strong><?php esc_html_e( "This site hasn't been checked yet.", 'button-link-scanner' ); ?></strong>
            <?php esc_html_e( 'Click "Check Links Now" above to run the first check and populate this report.', 'button-link-scanner' ); ?></p>
        </div>
    <?php elseif ( empty( $broken_links ) ) : ?>
        <div class="bls-card bls-card--notice">
            <p><?php esc_html_e( 'No broken links on file as of the last check. Nice.', 'button-link-scanner' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Button', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Broken Link', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Found On', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Broken Since', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'button-link-scanner' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $broken_links as $row ) : ?>
                <tr data-id="<?php echo (int) $row->id; ?>">
                    <td><strong><?php echo esc_html( $row->button_text ); ?></strong></td>
                    <td><a href="<?php echo esc_url( $row->link_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->link_url ); ?></a></td>
                    <td>
                        <?php if ( $row->post_url ) : ?>
                            <a href="<?php echo esc_url( $row->post_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->post_title ); ?></a>
                        <?php else : ?>
                            <em><?php esc_html_e( 'unknown', 'button-link-scanner' ); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="bls-badge bls-badge--danger">
                            <?php echo $row->http_status > 0 ? 'HTTP ' . (int) $row->http_status : esc_html( $row->error_message ?: __( 'unreachable', 'button-link-scanner' ) ); ?>
                        </span>
                    </td>
                    <td><?php echo $row->first_broken_at ? esc_html( mysql2date( get_option( 'date_format' ), $row->first_broken_at ) ) : '—'; ?></td>
                    <td>
                        <button class="button button-small bls-dismiss-broken-link" data-id="<?php echo (int) $row->id; ?>">
                            <?php esc_html_e( 'Mark Fixed', 'button-link-scanner' ); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ( isset( $_GET['bls_ignore_saved'] ) ) : ?>
        <?php $ignore_removed = isset( $_GET['bls_ignore_removed'] ) ? (int) $_GET['bls_ignore_removed'] : 0; ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php esc_html_e( 'Ignore list saved. It applies immediately — no re-check needed.', 'button-link-scanner' ); ?>
            <?php if ( $ignore_removed > 0 ) : ?>
                <?php printf(
                    /* translators: %d: number of rows removed from the report */
                    esc_html( _n( 'Removed %d link from this report.', 'Removed %d links from this report.', $ignore_removed, 'button-link-scanner' ) ),
                    $ignore_removed
                ); ?>
            <?php endif; ?>
        </p></div>
    <?php endif; ?>

    <details style="margin-top:22px;">
        <summary style="cursor:pointer; font-weight:600;">
            <?php printf(
                /* translators: %d: number of patterns */
                esc_html__( 'Ignored links (%d pattern(s)) — assumed to work', 'button-link-scanner' ),
                count( $ignore_patterns )
            ); ?>
        </summary>
        <p style="margin-top:8px; max-width:760px;">
            <?php esc_html_e( 'One pattern per line. Any link whose URL contains one of these is treated as working and kept out of this report entirely — not checked, not counted, not emailed. Matching is a plain case-insensitive substring, so a bare host ("facebook.com") or a path ("google.com/search") both work.', 'button-link-scanner' ); ?>
        </p>
        <p style="margin:0 0 8px; max-width:760px;" class="bls-meta">
            <?php esc_html_e( 'These sites block automated requests by policy, so a check can only ever come back 403 no matter how healthy the link is. Clearing this box entirely turns the behaviour off and everything gets checked again.', 'button-link-scanner' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="bls_save_link_ignore">
            <?php wp_nonce_field( 'bls_save_link_ignore' ); ?>
            <textarea name="bls_ignore" rows="10" style="width:100%; max-width:760px; font-family:monospace;"><?php
                echo esc_textarea( implode( "\n", $ignore_patterns ) );
            ?></textarea>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save ignore list', 'button-link-scanner' ); ?></button></p>
        </form>
    </details>

    <p style="margin-top:18px;">
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bls_export_links_csv' ), 'bls_export_links_csv' ) ); ?>"
           class="button button-secondary">
            <?php esc_html_e( 'Download full link report (CSV)', 'button-link-scanner' ); ?>
        </a>
        <span class="bls-meta" style="margin-left:6px;"><?php esc_html_e( 'Every row, broken and unverified, with no cap.', 'button-link-scanner' ); ?></span>
    </p>

    <?php if ( ! empty( $unverified_links ) ) : ?>
        <h2 style="margin-top:28px;"><?php esc_html_e( 'Could Not Verify', 'button-link-scanner' ); ?></h2>
        <p>
            <?php esc_html_e( 'These answered with a login wall, bot protection, or a rate limit (401/403/408/429), or timed out even after a retry. That is not proof the link is dead — plenty of sites refuse anything that is not a real browser, and checking many links against one domain provokes rate limiting. They are listed for review but are not counted as broken and are never emailed.', 'button-link-scanner' ); ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Link', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Link text', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Found on', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Response', 'button-link-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Last checked', 'button-link-scanner' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $unverified_links as $row ) : ?>
                <tr>
                    <td style="word-break:break-all; font-size:0.85em;">
                        <a href="<?php echo esc_url( $row->link_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->link_url ); ?></a>
                    </td>
                    <td><?php echo esc_html( $row->button_text ); ?></td>
                    <td>
                        <?php if ( $row->post_url ) : ?>
                            <a href="<?php echo esc_url( $row->post_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->post_title ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $row->post_title ); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="bls-badge bls-badge--warning">
                            <?php echo $row->http_status > 0 ? 'HTTP ' . (int) $row->http_status : esc_html( $row->error_message ?: __( 'no response', 'button-link-scanner' ) ); ?>
                        </span>
                    </td>
                    <td><?php echo $row->last_checked ? esc_html( mysql2date( get_option( 'date_format' ), $row->last_checked ) ) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
