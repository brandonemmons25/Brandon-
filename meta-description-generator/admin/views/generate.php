<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap mdg-wrap">
    <h1><?php esc_html_e( 'Generate & Apply Meta Descriptions', 'meta-description-generator' ); ?></h1>

    <?php if ( ! $has_key ) : ?>
    <div class="mdg-notice mdg-notice--warn">
        <?php printf(
            wp_kses( __( 'Add your Claude API key on the <a href="%s">Settings page</a> before generating.', 'meta-description-generator' ), [ 'a' => [ 'href' => [] ] ] ),
            esc_url( admin_url( 'admin.php?page=meta-description-generator-settings' ) )
        ); ?>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="get" class="mdg-filter-bar" id="mdg-filter-form">
        <input type="hidden" name="page" value="meta-description-generator-generate">

        <select name="status" id="mdg-filter-status">
            <option value="missing" <?php selected( $filters['status'], 'missing' ); ?>><?php esc_html_e( 'Missing description', 'meta-description-generator' ); ?></option>
            <option value="all"     <?php selected( $filters['status'], 'all' ); ?>><?php esc_html_e( 'All pages & posts', 'meta-description-generator' ); ?></option>
            <option value="has"     <?php selected( $filters['status'], 'has' ); ?>><?php esc_html_e( 'Already have description', 'meta-description-generator' ); ?></option>
        </select>

        <?php if ( ! empty( $post_types ) ) : ?>
        <select name="post_type" id="mdg-filter-type">
            <option value=""><?php esc_html_e( 'All types', 'meta-description-generator' ); ?></option>
            <?php foreach ( $post_types as $pt ) : ?>
                <option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $filters['post_type'], $pt ); ?>>
                    <?php echo esc_html( $pt ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
               placeholder="<?php esc_attr_e( 'Search by title…', 'meta-description-generator' ); ?>">

        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'meta-description-generator' ); ?></button>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=meta-description-generator-generate' ) ); ?>" class="button">
            <?php esc_html_e( 'Reset', 'meta-description-generator' ); ?>
        </a>
    </form>

    <!-- Bulk action bar -->
    <div class="mdg-bulk-bar">
        <span class="mdg-results-count">
            <?php printf(
                esc_html( _n( '%s result', '%s results', $total, 'meta-description-generator' ) ),
                number_format_i18n( $total )
            ); ?>
        </span>
        <div class="mdg-bulk-actions">
            <?php if ( $has_key ) : ?>
            <button id="mdg-generate-all" class="button button-primary" <?php echo empty( $rows ) ? 'disabled' : ''; ?>>
                <?php esc_html_e( 'Generate All Visible', 'meta-description-generator' ); ?>
            </button>
            <?php endif; ?>
            <button id="mdg-apply-all" class="button" disabled>
                <?php esc_html_e( 'Apply All to Yoast', 'meta-description-generator' ); ?>
            </button>
            <button id="mdg-clear-selected" class="button" disabled>
                <?php esc_html_e( 'Clear Selected', 'meta-description-generator' ); ?>
            </button>
            <span id="mdg-bulk-status" class="mdg-status"></span>
        </div>
    </div>

    <!-- Progress bar (hidden until generation starts) -->
    <div id="mdg-progress-wrap" style="display:none">
        <div id="mdg-progress-bar"><div id="mdg-progress-fill"></div></div>
        <span id="mdg-progress-label"></span>
    </div>

    <?php if ( empty( $rows ) ) : ?>
    <div class="mdg-card mdg-card--notice">
        <p><?php esc_html_e( 'No pages or posts match your current filter.', 'meta-description-generator' ); ?></p>
    </div>
    <?php else : ?>

    <table class="wp-list-table widefat fixed striped mdg-generate-table" id="mdg-table">
        <thead>
            <tr>
                <th class="check-column"><input type="checkbox" id="mdg-check-all"></th>
                <th><?php esc_html_e( 'Page / Post', 'meta-description-generator' ); ?></th>
                <th><?php esc_html_e( 'Type', 'meta-description-generator' ); ?></th>
                <th><?php esc_html_e( 'Current Yoast Description', 'meta-description-generator' ); ?></th>
                <th><?php esc_html_e( 'Generated / Edited Description', 'meta-description-generator' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'meta-description-generator' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) :
            $has  = $row['has_meta'];
            $len  = $row['meta_len'];
            $ok   = $has && $len >= MDG_META_MIN && $len <= MDG_META_MAX;
            $row_class = ! $has ? 'mdg-row--missing' : ( ! $ok ? 'mdg-row--warning' : '' );
        ?>
            <tr class="<?php echo esc_attr( $row_class ); ?>" data-post-id="<?php echo (int) $row['ID']; ?>">
                <td class="check-column">
                    <input type="checkbox" class="mdg-row-check" value="<?php echo (int) $row['ID']; ?>">
                </td>
                <td>
                    <a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener">
                        <strong><?php echo esc_html( $row['title'] ); ?></strong>
                    </a><br>
                    <a href="<?php echo esc_url( $row['edit_url'] ); ?>" class="button button-small" style="margin-top:4px">
                        <?php esc_html_e( 'Edit Post', 'meta-description-generator' ); ?>
                    </a>
                </td>
                <td><?php echo esc_html( $row['post_type'] ); ?></td>

                <!-- Current Yoast description -->
                <td class="mdg-current-cell">
                    <?php if ( $has ) : ?>
                        <span class="mdg-existing-text"><?php echo esc_html( $row['meta'] ); ?></span>
                        <span class="mdg-char-badge <?php echo $ok ? 'mdg-ok' : 'mdg-warn'; ?>">
                            <?php echo $len; ?> chars
                        </span>
                    <?php else : ?>
                        <span class="mdg-badge mdg-badge--missing"><?php esc_html_e( 'None', 'meta-description-generator' ); ?></span>
                    <?php endif; ?>
                </td>

                <!-- Generated / editable description -->
                <td class="mdg-generated-cell">
                    <textarea class="mdg-generated-textarea" rows="3"
                              placeholder="<?php esc_attr_e( 'Click Generate to create…', 'meta-description-generator' ); ?>"
                              data-post-id="<?php echo (int) $row['ID']; ?>"></textarea>
                    <div class="mdg-char-counter">
                        <span class="mdg-counter-val">0</span>
                        <span class="mdg-counter-msg"></span>
                    </div>
                </td>

                <!-- Actions -->
                <td class="mdg-action-cell">
                    <?php if ( $has_key ) : ?>
                    <button class="button button-small mdg-generate-one" data-post-id="<?php echo (int) $row['ID']; ?>">
                        <?php esc_html_e( 'Generate', 'meta-description-generator' ); ?>
                    </button>
                    <?php endif; ?>
                    <button class="button button-small button-primary mdg-apply-one" data-post-id="<?php echo (int) $row['ID']; ?>" disabled>
                        <?php esc_html_e( 'Apply to Yoast', 'meta-description-generator' ); ?>
                    </button>
                    <?php if ( $has ) : ?>
                    <button class="button button-small mdg-clear-one" data-post-id="<?php echo (int) $row['ID']; ?>">
                        <?php esc_html_e( 'Clear', 'meta-description-generator' ); ?>
                    </button>
                    <?php endif; ?>
                    <span class="mdg-row-status mdg-status"></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php
    $total_pages = (int) ceil( $total / $filters['per_page'] );
    if ( $total_pages > 1 ) :
        echo paginate_links( [
            'base'      => add_query_arg( array_merge( $_GET, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ),
            'format'    => '',
            'current'   => $filters['page'],
            'total'     => $total_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ] );
    endif;
    ?>

    <?php endif; ?>
</div>
