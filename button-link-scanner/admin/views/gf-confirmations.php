<?php defined( 'ABSPATH' ) || exit;

$gf_active = class_exists( 'GFForms' ) || class_exists( 'GFAPI' );
?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Gravity Forms – Confirmation Check', 'button-link-scanner' ); ?></h1>

    <?php if ( ! $gf_active ) : ?>
    <div class="bls-card bls-card--notice">
        <p>
            <strong><?php esc_html_e( 'Gravity Forms is not active.', 'button-link-scanner' ); ?></strong>
            <?php esc_html_e( 'Install and activate Gravity Forms to use this feature.', 'button-link-scanner' ); ?>
        </p>
    </div>
    <?php else : ?>

    <!-- What we check -->
    <div class="bls-card">
        <h3 style="margin-top:0"><?php esc_html_e( 'What is checked?', 'button-link-scanner' ); ?></h3>
        <ol>
            <li><?php esc_html_e( 'The confirmation redirects (not an inline message).', 'button-link-scanner' ); ?></li>
            <li><?php esc_html_e( 'The destination page slug contains "thank" (thank-you, thanks, etc.).', 'button-link-scanner' ); ?></li>
            <li><?php esc_html_e( 'That thank-you page is a WordPress child page of the page hosting the form.', 'button-link-scanner' ); ?></li>
        </ol>
        <p class="bls-meta">
            <?php esc_html_e( 'All three conditions must pass for a confirmation to be marked OK.', 'button-link-scanner' ); ?>
        </p>
    </div>

    <!-- Scan control -->
    <div class="bls-card bls-card--scan">
        <button id="bls-run-gf-scan" class="button button-primary button-hero">
            <?php esc_html_e( 'Scan Gravity Forms Now', 'button-link-scanner' ); ?>
        </button>
        <span id="bls-gf-scan-status" class="bls-status"></span>
        <?php if ( $last_scan ) : ?>
            <p class="bls-meta">
                <?php printf(
                    esc_html__( 'Last scan: %s', 'button-link-scanner' ),
                    esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_scan ) ) )
                ); ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- Summary -->
    <?php if ( ! empty( $summary ) && (int) $summary['total_confirmations'] > 0 ) : ?>
    <div class="bls-summary-grid">
        <div class="bls-stat-card bls-stat-card--info">
            <span class="bls-stat-number"><?php echo (int) $summary['total_forms']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Forms Scanned', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--info">
            <span class="bls-stat-number"><?php echo (int) $summary['total_confirmations']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Confirmations', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--success">
            <span class="bls-stat-number"><?php echo (int) $summary['passing']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Fully Passing', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--danger">
            <span class="bls-stat-number"><?php echo (int) $summary['failing']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Issues Found', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--warning">
            <span class="bls-stat-number"><?php echo (int) $summary['inline_message']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Inline Message', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--warning">
            <span class="bls-stat-number"><?php echo (int) $summary['no_thank_you']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'No Thank-You Page', 'button-link-scanner' ); ?></span>
        </div>
        <div class="bls-stat-card bls-stat-card--warning">
            <span class="bls-stat-number"><?php echo (int) $summary['not_child']; ?></span>
            <span class="bls-stat-label"><?php esc_html_e( 'Not a Child Page', 'button-link-scanner' ); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="get" class="bls-filter-bar">
        <input type="hidden" name="page" value="button-link-scanner-gf">
        <select name="passes">
            <option value=""  <?php selected( $filters['passes'], '' ); ?>><?php esc_html_e( 'All confirmations', 'button-link-scanner' ); ?></option>
            <option value="0" <?php selected( $filters['passes'], '0' ); ?>><?php esc_html_e( 'Issues only', 'button-link-scanner' ); ?></option>
            <option value="1" <?php selected( $filters['passes'], '1' ); ?>><?php esc_html_e( 'Passing only', 'button-link-scanner' ); ?></option>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
               placeholder="<?php esc_attr_e( 'Search form, confirmation, page…', 'button-link-scanner' ); ?>">
        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'button-link-scanner' ); ?></button>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-gf' ) ); ?>" class="button">
            <?php esc_html_e( 'Reset', 'button-link-scanner' ); ?>
        </a>
    </form>

    <!-- Results table -->
    <?php if ( empty( $rows ) ) : ?>
    <div class="bls-card bls-card--notice">
        <p><?php esc_html_e( 'No results yet. Run a scan above.', 'button-link-scanner' ); ?></p>
    </div>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped bls-results-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Form', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Confirmation', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Type', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Redirect Target', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Is Redirect?', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Thank-You Page?', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Child Page?', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Host Page(s)', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Status', 'button-link-scanner' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) :
            $passes = (bool) $row->passes;
        ?>
            <tr class="<?php echo ! $passes ? 'bls-row--danger' : ''; ?>">
                <!-- Form -->
                <td>
                    <strong><?php echo esc_html( $row->form_title ); ?></strong><br>
                    <span class="bls-meta">ID: <?php echo (int) $row->form_id; ?></span>
                    <?php if ( class_exists( 'GFForms' ) ) : ?>
                        <br><a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms&id=' . $row->form_id ) ); ?>" class="button button-small" style="margin-top:4px">
                            <?php esc_html_e( 'Edit Form', 'button-link-scanner' ); ?>
                        </a>
                    <?php endif; ?>
                </td>

                <!-- Confirmation name -->
                <td><?php echo esc_html( $row->confirmation_name ?: 'Default' ); ?></td>

                <!-- Type badge -->
                <td>
                    <?php
                    $type_labels = [
                        'message'  => [ 'warning', __( 'Inline Message', 'button-link-scanner' ) ],
                        'redirect' => [ 'info',    __( 'URL Redirect', 'button-link-scanner' ) ],
                        'page'     => [ 'info',    __( 'Page Redirect', 'button-link-scanner' ) ],
                        'none'     => [ 'danger',  __( 'None Set', 'button-link-scanner' ) ],
                    ];
                    [ $badge_class, $badge_label ] = $type_labels[ $row->confirmation_type ] ?? [ 'info', $row->confirmation_type ];
                    ?>
                    <span class="bls-badge bls-badge--<?php echo esc_attr( $badge_class ); ?>">
                        <?php echo esc_html( $badge_label ); ?>
                    </span>
                </td>

                <!-- Redirect target -->
                <td class="bls-url-cell">
                    <?php if ( $row->redirect_page_id ) : ?>
                        <a href="<?php echo esc_url( $row->redirect_page_url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $row->redirect_page_title ); ?>
                        </a><br>
                        <span class="bls-meta">/<?php echo esc_html( $row->redirect_page_slug ); ?></span><br>
                        <a href="<?php echo esc_url( get_edit_post_link( $row->redirect_page_id ) ); ?>" class="button button-small">
                            <?php esc_html_e( 'Edit Page', 'button-link-scanner' ); ?>
                        </a>
                    <?php elseif ( $row->redirect_url ) : ?>
                        <a href="<?php echo esc_url( $row->redirect_url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $row->redirect_url ); ?>
                        </a>
                    <?php else : ?>
                        <span class="bls-meta">—</span>
                    <?php endif; ?>
                </td>

                <!-- Check: is redirect -->
                <td class="bls-center">
                    <?php echo $row->is_redirect
                        ? '<span class="bls-icon bls-icon--ok">&#10003;</span>'
                        : '<span class="bls-icon bls-icon--bad">&#10007;</span>'; ?>
                </td>

                <!-- Check: thank-you page -->
                <td class="bls-center">
                    <?php if ( ! $row->is_redirect ) : ?>
                        <span class="bls-icon bls-icon--na">&#8212;</span>
                    <?php elseif ( $row->is_thank_you_page ) : ?>
                        <span class="bls-icon bls-icon--ok">&#10003;</span>
                    <?php else : ?>
                        <span class="bls-icon bls-icon--bad">&#10007;</span>
                    <?php endif; ?>
                </td>

                <!-- Check: child page -->
                <td class="bls-center">
                    <?php
                    $child_val = (int) $row->is_child_page; // -1 = unknown, 0 = no, 1 = yes
                    if ( ! $row->is_redirect || ! $row->is_thank_you_page ) :
                    ?>
                        <span class="bls-icon bls-icon--na">&#8212;</span>
                    <?php elseif ( $row->confirmation_type === 'redirect' ) : ?>
                        <span class="bls-icon bls-icon--na"
                              title="<?php esc_attr_e( 'Child-page check only applies to Page redirects, not raw URLs', 'button-link-scanner' ); ?>">
                            <?php esc_html_e( 'N/A (URL)', 'button-link-scanner' ); ?>
                        </span>
                    <?php elseif ( $child_val === 1 ) : ?>
                        <span class="bls-icon bls-icon--ok">&#10003;</span>
                    <?php elseif ( $child_val === -1 ) : ?>
                        <span class="bls-icon bls-icon--warn"
                              title="<?php esc_attr_e( 'Host page not found in scan — verify the parent relationship manually', 'button-link-scanner' ); ?>">
                            <?php esc_html_e( 'Unknown', 'button-link-scanner' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="bls-icon bls-icon--bad">&#10007;</span>
                    <?php endif; ?>
                </td>

                <!-- Host pages -->
                <td>
                    <?php if ( ! empty( $row->host_page_titles ) ) : ?>
                        <?php
                        $host_titles = explode( ' | ', $row->host_page_titles );
                        $host_ids    = array_filter( explode( ',', $row->host_page_ids ) );
                        foreach ( $host_titles as $i => $ht ) :
                            $hid = $host_ids[ $i ] ?? 0;
                        ?>
                            <div>
                                <?php if ( $hid ) : ?>
                                    <a href="<?php echo esc_url( get_permalink( (int) $hid ) ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html( $ht ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html( $ht ); ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <span class="bls-meta"><?php esc_html_e( 'Not found on any page', 'button-link-scanner' ); ?></span>
                    <?php endif; ?>
                </td>

                <!-- Overall status -->
                <td>
                    <?php if ( $passes ) : ?>
                        <span class="bls-badge bls-badge--success"><?php esc_html_e( 'OK', 'button-link-scanner' ); ?></span>
                    <?php else : ?>
                        <span class="bls-badge bls-badge--danger"><?php esc_html_e( 'Issues', 'button-link-scanner' ); ?></span>
                        <?php if ( $row->fail_reasons ) : ?>
                            <ul class="bls-fail-list">
                                <?php foreach ( explode( ';', $row->fail_reasons ) as $reason ) : ?>
                                    <li><?php echo esc_html( trim( $reason ) ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php endif; // GF active ?>
</div>
