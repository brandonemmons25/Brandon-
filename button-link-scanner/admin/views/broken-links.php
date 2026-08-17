<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Broken Links', 'button-link-scanner' ); ?> <span class="bls-version-badge">v<?php echo esc_html( BLS_VERSION ); ?></span></h1>
    <p><?php esc_html_e( 'Every button link that failed its last health check (404, server error, or unreachable). A link is checked in the background on whatever schedule is set on the Dashboard, or on demand below.', 'button-link-scanner' ); ?></p>

    <?php
    // Both actions live above the table on purpose. The CSV link used to sit
    // underneath it, which is fine at ten rows and useless at several hundred —
    // on a report with 366 rows it was several screens down, past the ignore
    // list, and read as missing entirely.
    ?>
    <p>
        <button id="bls-check-links-now" class="button button-primary">
            <?php esc_html_e( 'Check Links Now', 'button-link-scanner' ); ?>
        </button>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bls_export_links_csv' ), 'bls_export_links_csv' ) ); ?>"
           class="button button-secondary">
            <?php esc_html_e( 'Download full link report (CSV)', 'button-link-scanner' ); ?>
        </a>
        <span id="bls-link-check-status" class="bls-status"></span>
    </p>
    <p class="bls-meta" style="margin-top:-6px;">
        <?php esc_html_e( 'The CSV has every row — broken and unverified — with no cap. Easier to sort and scan than the table below.', 'button-link-scanner' ); ?>
    </p>

    <?php if ( $last_run ) : ?>
        <p class="bls-meta">
            <?php printf(
                esc_html__( 'Last checked: %s', 'button-link-scanner' ),
                esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) )
            ); ?>
        </p>
    <?php endif; ?>

    <?php if ( ! empty( $last_unlink ) ) : ?>
        <div class="bls-card bls-card--notice">
            <?php if ( ! empty( $last_unlink['error'] ) ) : ?>
                <p><strong><?php esc_html_e( 'Last link removal failed:', 'button-link-scanner' ); ?></strong>
                    <?php echo esc_html( $last_unlink['error'] ); ?></p>
            <?php else : ?>
                <p style="margin:0 0 6px;"><strong><?php printf(
                    esc_html__( 'Last link removal: %s', 'button-link-scanner' ),
                    esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) ( $last_unlink['time'] ?? '' ) ) )
                ); ?></strong></p>
                <ul style="margin:0 0 6px 18px; list-style:disc;">
                    <li><?php printf(
                        esc_html__( 'Links removed: %1$d across %2$d page(s)', 'button-link-scanner' ),
                        (int) ( $last_unlink['unlinked'] ?? 0 ),
                        (int) ( $last_unlink['pages_changed'] ?? 0 )
                    ); ?></li>
                    <?php if ( ! empty( $last_unlink['skipped_alive'] ) ) : ?>
                        <li><?php printf(
                            esc_html__( 'Left alone because they worked on re-check: %d', 'button-link-scanner' ),
                            (int) $last_unlink['skipped_alive']
                        ); ?></li>
                    <?php endif; ?>
                    <?php if ( ! empty( $last_unlink['skipped_not_found'] ) ) : ?>
                        <li><?php printf(
                            esc_html__( 'Could not be found in editable content: %d', 'button-link-scanner' ),
                            (int) $last_unlink['skipped_not_found']
                        ); ?></li>
                    <?php endif; ?>
                </ul>
                <?php if ( ! empty( $last_unlink['skipped_alive_urls'] ) ) : ?>
                    <details style="margin:0 0 8px;">
                        <summary style="cursor:pointer;"><?php esc_html_e( 'Which ones were left alone', 'button-link-scanner' ); ?></summary>
                        <ul style="margin:6px 0 0 18px; list-style:disc;">
                            <?php foreach ( (array) $last_unlink['skipped_alive_urls'] as $skipped ) : ?>
                                <li><code><?php echo esc_html( (string) ( $skipped['url'] ?? '' ) ); ?></code>
                                    — <?php echo esc_html( (string) ( $skipped['state'] ?? '' ) ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <?php if ( ! empty( $last_unlink['log_url'] ) ) : ?>
                    <p style="margin:0;">
                        <a href="<?php echo esc_url( (string) $last_unlink['log_url'] ); ?>" class="button button-secondary">
                            <?php esc_html_e( 'Download removal log (CSV)', 'button-link-scanner' ); ?>
                        </a>
                        <span class="bls-meta" style="margin-left:6px;"><?php esc_html_e( 'Every link removed, and the page it came off.', 'button-link-scanner' ); ?></span>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $url_fixes ) ) : ?>
        <details style="margin:0 0 16px;">
            <summary style="cursor:pointer; font-weight:600;">
                <?php printf(
                    /* translators: %d: number of corrections */
                    esc_html__( 'Addresses you corrected (%d)', 'button-link-scanner' ),
                    count( $url_fixes )
                ); ?>
            </summary>
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Old address', 'button-link-scanner' ); ?></th>
                        <th><?php esc_html_e( 'Changed to', 'button-link-scanner' ); ?></th>
                        <th><?php esc_html_e( 'Links', 'button-link-scanner' ); ?></th>
                        <th><?php esc_html_e( 'When', 'button-link-scanner' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $url_fixes as $fix ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) ( $fix['old'] ?? '' ) ); ?></code></td>
                        <td><a href="<?php echo esc_url( (string) ( $fix['new'] ?? '' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) ( $fix['new'] ?? '' ) ); ?></a></td>
                        <td><?php printf(
                            /* translators: 1: links changed, 2: pages affected */
                            esc_html__( '%1$d on %2$d page(s)', 'button-link-scanner' ),
                            (int) ( $fix['count'] ?? 0 ),
                            (int) ( $fix['pages'] ?? 0 )
                        ); ?></td>
                        <td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) ( $fix['time'] ?? '' ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>

    <?php if ( $unlink_abandoned ) : ?>
        <div id="bls-unlink-abandoned-notice" class="notice notice-warning">
            <p><?php esc_html_e( 'A link removal was interrupted before it finished. Picking it back up automatically...', 'button-link-scanner' ); ?></p>
        </div>
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
        <?php
        // Bulk unlink. Kept deliberately explicit — nothing is preselected and
        // the count is echoed back before anything runs, because this edits
        // page content and there is no single-click undo.
        ?>
        <div class="bls-card" style="margin:18px 0; padding:14px 16px;">
            <p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Remove dead links', 'button-link-scanner' ); ?></strong></p>
            <p style="margin:0 0 10px; max-width:820px;">
                <?php esc_html_e( 'Tick any links below whose destination is gone for good — a business that closed, a site that no longer exists — and this removes the link while leaving the words exactly where they are. Nothing is deleted from the page; the text simply stops being clickable.', 'button-link-scanner' ); ?>
            </p>
            <p style="margin:0 0 10px; max-width:820px;" class="bls-meta">
                <?php esc_html_e( 'Every link is re-checked at the moment it is processed, so anything that has come back online since the last check is skipped and left alone. Use this only for destinations that are gone for good — if a page has simply moved, use "Fix URL" on its row to point it at the new address instead. There is no undo, and a full log of every change is saved.', 'button-link-scanner' ); ?>
            </p>
            <?php
            $kind_labels = BLS_Link_Checker::failure_kind_labels();
            $kind_counts = [];
            foreach ( $broken_links as $row ) {
                $kind = BLS_Link_Checker::failure_kind( $row );
                $kind_counts[ $kind ] = ( $kind_counts[ $kind ] ?? 0 ) + 1;
            }
            ?>
            <?php if ( ! empty( $kind_counts ) ) : ?>
                <table class="widefat striped" style="margin:0 0 12px; max-width:820px;">
                    <tbody>
                    <?php foreach ( $kind_labels as $kind => $meta ) : ?>
                        <?php if ( empty( $kind_counts[ $kind ] ) ) { continue; } ?>
                        <tr>
                            <td style="width:70px; vertical-align:top;"><strong><?php echo (int) $kind_counts[ $kind ]; ?></strong></td>
                            <td style="vertical-align:top;">
                                <strong><?php echo esc_html( $meta['label'] ); ?></strong>
                                <?php if ( ! empty( $meta['unlink'] ) ) : ?>
                                    <span class="bls-badge" style="background:#d5e7d5; color:#255625;"><?php esc_html_e( 'safe to unlink', 'button-link-scanner' ); ?></span>
                                <?php else : ?>
                                    <span class="bls-badge" style="background:#f5e6c8; color:#6b4e00;"><?php esc_html_e( 'check first', 'button-link-scanner' ); ?></span>
                                <?php endif; ?>
                                <br><span class="bls-meta"><?php echo esc_html( $meta['advice'] ); ?></span>
                            </td>
                            <td style="width:130px; vertical-align:top;">
                                <button type="button" class="button button-small bls-select-kind" data-kind="<?php echo esc_attr( $kind ); ?>">
                                    <?php esc_html_e( 'Select these', 'button-link-scanner' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="margin:0;">
                <button id="bls-unlink-selected" class="button button-primary" disabled>
                    <?php esc_html_e( 'Unlink selected', 'button-link-scanner' ); ?>
                </button>
                <button type="button" id="bls-unlink-clear" class="button button-small"><?php esc_html_e( 'Clear selection', 'button-link-scanner' ); ?></button>
                <span id="bls-unlink-count" class="bls-meta" style="margin-left:8px;"><?php esc_html_e( 'Nothing selected', 'button-link-scanner' ); ?></span>
                <span id="bls-unlink-status" class="bls-status" style="margin-left:8px;"></span>
            </p>
        </div>

        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <td class="check-column" style="width:2.2em;">
                        <input type="checkbox" id="bls-unlink-check-all" title="<?php esc_attr_e( 'Select all', 'button-link-scanner' ); ?>">
                    </td>
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
                <?php $row_kind = BLS_Link_Checker::failure_kind( $row ); ?>
                <tr data-id="<?php echo (int) $row->id; ?>" data-kind="<?php echo esc_attr( $row_kind ); ?>">
                    <td class="check-column">
                        <input type="checkbox" class="bls-unlink-pick" value="<?php echo (int) $row->id; ?>">
                    </td>
                    <td>
                        <strong><?php echo esc_html( $row->button_text ); ?></strong>
                        <?php
                        // Provenance and markup, same as on Scan Results. This
                        // report is where a row for a button nobody can find on
                        // the page is most likely to be noticed, so it is the
                        // last place that should leave the question unanswerable.
                        $meta = $button_meta[ (int) $row->post_id . '|' . $row->link_url ] ?? null;
                        ?>
                        <?php if ( $meta && $meta['source'] !== '' && $meta['source'] !== 'content' ) : ?>
                            <br><span class="bls-badge bls-badge--info"><?php echo esc_html( $meta['source'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( $meta && $meta['button_html'] !== '' ) : ?>
                            <details style="margin-top:4px;">
                                <summary class="bls-meta" style="cursor:pointer;"><?php esc_html_e( 'markup', 'button-link-scanner' ); ?></summary>
                                <pre style="white-space:pre-wrap; word-break:break-all; max-width:340px; margin:6px 0 0; padding:6px; background:#f6f7f7; border:1px solid #dcdcde; font-size:11px;"><?php
                                    echo esc_html( mb_strimwidth( $meta['button_html'], 0, 600, '…' ) );
                                ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
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
                        <br><span class="bls-meta"><?php echo esc_html( $kind_labels[ $row_kind ]['label'] ?? '' ); ?></span>
                    </td>
                    <td><?php echo $row->first_broken_at ? esc_html( mysql2date( get_option( 'date_format' ), $row->first_broken_at ) ) : '—'; ?></td>
                    <td>
                        <button class="button button-small bls-fix-url" data-url="<?php echo esc_attr( $row->link_url ); ?>">
                            <?php esc_html_e( 'Fix URL', 'button-link-scanner' ); ?>
                        </button>
                        <button class="button button-small bls-dismiss-broken-link" data-id="<?php echo (int) $row->id; ?>">
                            <?php esc_html_e( 'Dismiss', 'button-link-scanner' ); ?>
                        </button>
                        <?php
                        // "Not on page" needs the SCAN row, not the health row.
                        // Dismiss above only clears the health row, so the next
                        // link check rebuilds it from the surviving scan row —
                        // which is why a button reported as not existing kept
                        // reappearing here. This removes both and keeps it out
                        // of future scans.
                        $meta_id = $button_meta[ (int) $row->post_id . '|' . $row->link_url ]['id'] ?? 0;
                        ?>
                        <?php if ( $meta_id > 0 ) : ?>
                            <button class="button button-small bls-dismiss-button"
                                    data-id="<?php echo (int) $meta_id; ?>"
                                    title="<?php esc_attr_e( 'The button is not on the page — remove it and keep it out of future scans', 'button-link-scanner' ); ?>">
                                <?php esc_html_e( 'Not on page', 'button-link-scanner' ); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="bls-fix-row" style="display:none;">
                    <td></td>
                    <td colspan="6">
                        <p style="margin:6px 0;">
                            <label>
                                <strong><?php esc_html_e( 'New address for this link:', 'button-link-scanner' ); ?></strong><br>
                                <input type="url" class="bls-fix-url-input regular-text" style="width:70%;"
                                       placeholder="https://example.com/the-new-page/">
                            </label>
                            <button class="button button-primary button-small bls-fix-url-save"><?php esc_html_e( 'Update link', 'button-link-scanner' ); ?></button>
                            <button class="button button-small bls-fix-url-cancel"><?php esc_html_e( 'Cancel', 'button-link-scanner' ); ?></button>
                        </p>
                        <p class="bls-meta" style="margin:0 0 6px;">
                            <?php esc_html_e( 'Every page using the old address is updated at once. The new address is checked first — if it is broken too, nothing is changed.', 'button-link-scanner' ); ?>
                        </p>
                        <span class="bls-fix-url-status bls-status"></span>
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

    <?php if ( ! empty( $unverified_links ) ) : ?>
        <h2 style="margin-top:28px;"><?php esc_html_e( 'Could Not Verify', 'button-link-scanner' ); ?></h2>
        <p>
            <?php esc_html_e( 'These either refused the request (a login wall, bot protection, or a rate limit — 401/403/408/429/444/460), answered with a server error (5xx, including Cloudflare 520-527), or timed out even after a retry. None of that proves the link is dead: plenty of sites refuse anything that is not a real browser, and a server error means the destination was having a bad moment, not that the address is wrong. They are listed for review but are not counted as broken and are never emailed.', 'button-link-scanner' ); ?>
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
