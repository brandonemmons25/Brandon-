<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Scan Results', 'button-link-scanner' ); ?> <span class="bls-version-badge">v<?php echo esc_html( BLS_VERSION ); ?></span></h1>

    <!-- Filters -->
    <form method="get" class="bls-filter-bar">
        <input type="hidden" name="page" value="button-link-scanner-results">

        <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
               placeholder="<?php esc_attr_e( 'Search title, button text, URL…', 'button-link-scanner' ); ?>">

        <select name="has_link">
            <option value=""  <?php selected( $filters['has_link'], '' ); ?>><?php esc_html_e( 'All (link status)', 'button-link-scanner' ); ?></option>
            <option value="1" <?php selected( $filters['has_link'], '1' ); ?>><?php esc_html_e( 'Has link', 'button-link-scanner' ); ?></option>
            <option value="0" <?php selected( $filters['has_link'], '0' ); ?>><?php esc_html_e( 'Missing link', 'button-link-scanner' ); ?></option>
        </select>

        <select name="has_title">
            <option value=""  <?php selected( $filters['has_title'], '' ); ?>><?php esc_html_e( 'All (SEO title status)', 'button-link-scanner' ); ?></option>
            <option value="1" <?php selected( $filters['has_title'], '1' ); ?>><?php esc_html_e( 'Has SEO title', 'button-link-scanner' ); ?></option>
            <option value="0" <?php selected( $filters['has_title'], '0' ); ?>><?php esc_html_e( 'Missing SEO title', 'button-link-scanner' ); ?></option>
        </select>

        <?php if ( ! empty( $post_types ) ) : ?>
        <select name="post_type">
            <option value=""><?php esc_html_e( 'All post types', 'button-link-scanner' ); ?></option>
            <?php foreach ( $post_types as $pt ) : ?>
                <option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $filters['post_type'], $pt ); ?>>
                    <?php echo esc_html( $pt ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="kind">
            <option value=""          <?php selected( $filters['kind'], '' ); ?>><?php esc_html_e( 'All kinds', 'button-link-scanner' ); ?></option>
            <option value="buttons"   <?php selected( $filters['kind'], 'buttons' ); ?>><?php esc_html_e( 'Buttons only', 'button-link-scanner' ); ?></option>
            <option value="hyperlink" <?php selected( $filters['kind'], 'hyperlink' ); ?>><?php esc_html_e( 'Hyperlinks only', 'button-link-scanner' ); ?></option>
        </select>

        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'button-link-scanner' ); ?></button>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=button-link-scanner-results' ) ); ?>" class="button">
            <?php esc_html_e( 'Reset', 'button-link-scanner' ); ?>
        </a>
    </form>

    <!-- Results count -->
    <p class="bls-results-count">
        <?php printf(
            esc_html( _n( '%s result found', '%s results found', $total, 'button-link-scanner' ) ),
            number_format_i18n( $total )
        ); ?>
    </p>

    <?php if ( empty( $rows ) ) : ?>
        <div class="bls-card bls-card--notice">
            <p><?php esc_html_e( 'No buttons match your filters. Run a scan first if results are empty.', 'button-link-scanner' ); ?></p>
        </div>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped bls-results-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Post / Page', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Type', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Button Text', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Link URL', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Has Link', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Title Attribute', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Button Kind', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'button-link-scanner' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) :
            $has_link  = (bool) $row->has_link;
            $has_title = (bool) $row->has_title;
        ?>
            <tr class="<?php echo ! $has_link ? 'bls-row--danger' : ( ! $has_title ? 'bls-row--warning' : '' ); ?>">
                <td>
                    <a href="<?php echo esc_url( $row->post_url ); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html( $row->post_title ); ?>
                    </a><br>
                    <span class="bls-meta">ID: <?php echo (int) $row->post_id; ?></span>
                </td>
                <td><?php echo esc_html( $row->post_type ); ?></td>
                <td>
                    <?php
                    $label = trim( (string) $row->button_text );
                    if ( $label === '' ) :
                        ?>
                        <em class="bls-meta"><?php esc_html_e( '(no text)', 'button-link-scanner' ); ?></em>
                    <?php else : ?>
                        <?php echo esc_html( $label ); ?>
                    <?php endif; ?>

                    <?php if ( ! empty( $row->source ) && $row->source !== 'content' ) : ?>
                        <?php
                        // Anything other than the page's own content is worth
                        // saying out loud. A block template is shared by every
                        // page using it, a custom field may never be rendered
                        // anywhere, and a live-page read is the whole document
                        // rather than the editor's content. Rows reported for
                        // buttons nobody could find on the page came from these,
                        // and without the label the only way to tell was guesswork.
                        ?>
                        <br><span class="bls-badge bls-badge--info"><?php echo esc_html( $row->source ); ?></span>
                    <?php endif; ?>

                    <?php
                    // The captured markup, on demand. A row whose label reads
                    // "0" or is blank is almost never a real button — it is
                    // usually a counter, a slider control or a toggle picked
                    // up from somewhere that is not the page body. Guessing
                    // what those are from the label alone wasted real time;
                    // the element itself was in the database the whole while.
                    if ( ! empty( $row->button_html ) ) :
                        ?>
                        <details class="bls-markup">
                            <summary class="bls-meta" style="cursor:pointer;"><?php esc_html_e( 'markup', 'button-link-scanner' ); ?></summary>
                            <pre style="white-space:pre-wrap; word-break:break-all; max-width:420px; margin:6px 0 0; padding:6px; background:#f6f7f7; border:1px solid #dcdcde; font-size:11px;"><?php
                                echo esc_html( mb_strimwidth( (string) $row->button_html, 0, 600, '…' ) );
                            ?></pre>
                        </details>
                    <?php endif; ?>
                </td>
                <td class="bls-url-cell">
                    <?php if ( $has_link ) : ?>
                        <a href="<?php echo esc_url( $row->link_url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $row->link_url ); ?>
                        </a>
                        <?php if ( $row->opens_new_tab ) : ?>
                            <span class="bls-badge bls-badge--info"><?php esc_html_e( 'new tab', 'button-link-scanner' ); ?></span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="bls-badge bls-badge--danger"><?php esc_html_e( 'none', 'button-link-scanner' ); ?></span>
                    <?php endif; ?>
                </td>
                <td class="bls-center">
                    <?php echo $has_link
                        ? '<span class="bls-icon bls-icon--ok" title="Has link">&#10003;</span>'
                        : '<span class="bls-icon bls-icon--bad" title="Missing link">&#10007;</span>'; ?>
                </td>
                <td>
                    <?php if ( $has_title ) : ?>
                        <span class="bls-icon bls-icon--ok">&#10003;</span>
                        <span class="bls-title-value"><?php echo esc_html( $row->title_text ); ?></span>
                    <?php elseif ( $has_link ) : ?>
                        <span class="bls-badge bls-badge--warning"><?php esc_html_e( 'Missing title', 'button-link-scanner' ); ?></span>
                    <?php else : ?>
                        <span class="bls-icon bls-icon--na">&#8212;</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $row->button_type ); ?></td>
                <td>
                    <a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>" class="button button-small">
                        <?php esc_html_e( 'Edit Post', 'button-link-scanner' ); ?>
                    </a>
                    <button class="button button-small bls-add-to-map"
                            data-text="<?php echo esc_attr( $row->button_text ); ?>"
                            data-url="<?php echo esc_attr( $row->link_url ); ?>">
                        <?php esc_html_e( '+ Map', 'button-link-scanner' ); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php
    $total_pages = (int) ceil( $total / $filters['per_page'] );
    if ( $total_pages > 1 ) :
        $base_url = add_query_arg( array_merge( $_GET, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) );
        echo paginate_links( [
            'base'      => $base_url,
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
