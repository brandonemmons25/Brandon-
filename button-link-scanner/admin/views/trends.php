<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Link Trends', 'button-link-scanner' ); ?></h1>
    <p><?php esc_html_e( 'Buttons are grouped by their text label. This view helps you spot inconsistencies (e.g. the same label pointing to different URLs) and identify buttons that are never linked.', 'button-link-scanner' ); ?></p>

    <?php if ( empty( $trends ) ) : ?>
        <div class="bls-card bls-card--notice">
            <p><?php esc_html_e( 'No data yet. Run a scan from the Dashboard first.', 'button-link-scanner' ); ?></p>
        </div>
    <?php else : ?>

    <!-- Legend -->
    <div class="bls-legend">
        <span class="bls-badge bls-badge--danger"><?php esc_html_e( 'Never linked', 'button-link-scanner' ); ?></span>
        <span class="bls-badge bls-badge--warning"><?php esc_html_e( 'Inconsistent URLs', 'button-link-scanner' ); ?></span>
        <span class="bls-badge bls-badge--info"><?php esc_html_e( 'Partially linked', 'button-link-scanner' ); ?></span>
        <span class="bls-badge bls-badge--success"><?php esc_html_e( 'Consistent', 'button-link-scanner' ); ?></span>
    </div>

    <table class="wp-list-table widefat fixed striped bls-trends-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Button Label', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Occurrences', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Unique Pages', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Linked', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Unlinked', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Missing Title', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'URLs Found', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Status', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'button-link-scanner' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $trends as $row ) :
            $urls        = array_filter( explode( '|||', $row->unique_urls ?? '' ) );
            $url_count   = count( $urls );
            $linked      = (int) $row->linked_count;
            $unlinked    = (int) $row->unlinked_count;
            $occurrences = (int) $row->occurrences;

            // Determine row status.
            if ( $linked === 0 ) {
                $status_class = 'bls-badge--danger';
                $status_label = esc_html__( 'Never linked', 'button-link-scanner' );
                $row_class    = 'bls-row--danger';
            } elseif ( $url_count > 1 ) {
                $status_class = 'bls-badge--warning';
                $status_label = esc_html__( 'Inconsistent URLs', 'button-link-scanner' );
                $row_class    = 'bls-row--warning';
            } elseif ( $unlinked > 0 ) {
                $status_class = 'bls-badge--info';
                $status_label = esc_html__( 'Partially linked', 'button-link-scanner' );
                $row_class    = 'bls-row--info';
            } else {
                $status_class = 'bls-badge--success';
                $status_label = esc_html__( 'Consistent', 'button-link-scanner' );
                $row_class    = '';
            }

            $primary_url = ! empty( $urls ) ? $urls[0] : '';
        ?>
            <tr class="<?php echo esc_attr( $row_class ); ?>">
                <td><strong><?php echo esc_html( $row->button_text ); ?></strong></td>
                <td class="bls-center"><?php echo (int) $row->occurrences; ?></td>
                <td class="bls-center"><?php echo (int) $row->unique_pages; ?></td>
                <td class="bls-center bls-text--success"><?php echo $linked; ?></td>
                <td class="bls-center bls-text--danger"><?php echo $unlinked; ?></td>
                <td class="bls-center bls-text--warning"><?php echo (int) $row->missing_title_count; ?></td>
                <td class="bls-url-list">
                    <?php if ( empty( $urls ) ) : ?>
                        <em><?php esc_html_e( 'none', 'button-link-scanner' ); ?></em>
                    <?php else : ?>
                        <?php foreach ( $urls as $u ) : ?>
                            <div><a href="<?php echo esc_url( $u ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $u ); ?></a></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td><span class="bls-badge <?php echo esc_attr( $status_class ); ?>"><?php echo $status_label; ?></span></td>
                <td>
                    <button class="button button-small bls-add-to-map"
                            data-text="<?php echo esc_attr( $row->button_text ); ?>"
                            data-url="<?php echo esc_attr( $primary_url ); ?>">
                        <?php esc_html_e( 'Add to Map', 'button-link-scanner' ); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
