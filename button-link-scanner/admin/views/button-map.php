<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap bls-wrap">
    <h1><?php esc_html_e( 'Button Map', 'button-link-scanner' ); ?></h1>
    <p>
        <?php esc_html_e(
            'Assign a canonical URL and SEO title to each unique button label. Once assigned, use "Apply" to rewrite matching buttons across all posts and pages automatically.',
            'button-link-scanner'
        ); ?>
    </p>

    <!-- Add / Edit Entry Form -->
    <div class="bls-card" id="bls-map-form-card">
        <h2><?php esc_html_e( 'Add / Update Entry', 'button-link-scanner' ); ?></h2>
        <table class="form-table bls-form-table">
            <tr>
                <th><label for="bls-map-text"><?php esc_html_e( 'Button Label (exact text)', 'button-link-scanner' ); ?></label></th>
                <td>
                    <input type="text" id="bls-map-text" class="regular-text" list="bls-known-labels"
                           placeholder="<?php esc_attr_e( 'e.g. Get Started', 'button-link-scanner' ); ?>">
                    <datalist id="bls-known-labels">
                        <?php foreach ( $trends as $t ) : ?>
                            <option value="<?php echo esc_attr( $t->button_text ); ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <p class="description"><?php esc_html_e( 'Matching is case-insensitive. Start typing to see labels found during the last scan.', 'button-link-scanner' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="bls-map-url"><?php esc_html_e( 'Destination URL', 'button-link-scanner' ); ?></label></th>
                <td>
                    <input type="url" id="bls-map-url" class="regular-text"
                           placeholder="https://example.com/page">
                </td>
            </tr>
            <tr>
                <th><label for="bls-map-title"><?php esc_html_e( 'SEO Title Attribute', 'button-link-scanner' ); ?></label></th>
                <td>
                    <input type="text" id="bls-map-title" class="regular-text"
                           placeholder="<?php esc_attr_e( 'Descriptive title for accessibility and SEO', 'button-link-scanner' ); ?>">
                    <p class="description"><?php esc_html_e( 'This becomes the title="" attribute on the link for SEO and screen readers.', 'button-link-scanner' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Open in New Tab', 'button-link-scanner' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="bls-map-new-tab">
                        <?php esc_html_e( 'Open link in a new tab (adds target="_blank" rel="noopener noreferrer")', 'button-link-scanner' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <p>
            <button id="bls-save-map-entry" class="button button-primary">
                <?php esc_html_e( 'Save Entry', 'button-link-scanner' ); ?>
            </button>
            <span id="bls-map-save-status" class="bls-status"></span>
        </p>
    </div>

    <!-- Existing Entries -->
    <?php if ( empty( $map_entries ) ) : ?>
        <div class="bls-card bls-card--notice">
            <p><?php esc_html_e( 'No button map entries yet. Add your first entry above, or use "Add to Map" from the Trends or Results pages.', 'button-link-scanner' ); ?></p>
        </div>
    <?php else : ?>

    <div class="bls-map-header">
        <h2><?php esc_html_e( 'Map Entries', 'button-link-scanner' ); ?></h2>
        <div>
            <button id="bls-apply-all" class="button button-primary">
                <?php esc_html_e( 'Apply All to Site', 'button-link-scanner' ); ?>
            </button>
            <span id="bls-apply-all-status" class="bls-status"></span>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Button Label', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Assigned URL', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'SEO Title', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'New Tab', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Times Applied', 'button-link-scanner' ); ?></th>
                <th class="bls-center"><?php esc_html_e( 'Last Updated', 'button-link-scanner' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'button-link-scanner' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $map_entries as $entry ) : ?>
            <tr data-map-id="<?php echo (int) $entry->id; ?>">
                <td><strong><?php echo esc_html( $entry->button_text ); ?></strong></td>
                <td>
                    <?php if ( ! empty( $entry->assigned_url ) ) : ?>
                        <a href="<?php echo esc_url( $entry->assigned_url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $entry->assigned_url ); ?>
                        </a>
                    <?php else : ?>
                        <em><?php esc_html_e( 'not set', 'button-link-scanner' ); ?></em>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $entry->assigned_title ?: '—' ); ?></td>
                <td class="bls-center"><?php echo $entry->opens_new_tab ? '&#10003;' : '&#8212;'; ?></td>
                <td class="bls-center"><?php echo (int) $entry->apply_count; ?></td>
                <td class="bls-center bls-meta"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $entry->updated_at ) ) ); ?></td>
                <td class="bls-action-cell">
                    <button class="button button-small bls-preview-apply" data-map-id="<?php echo (int) $entry->id; ?>">
                        <?php esc_html_e( 'Preview', 'button-link-scanner' ); ?>
                    </button>
                    <?php if ( ! empty( $entry->assigned_url ) ) : ?>
                    <button class="button button-small button-primary bls-apply-single" data-map-id="<?php echo (int) $entry->id; ?>">
                        <?php esc_html_e( 'Apply', 'button-link-scanner' ); ?>
                    </button>
                    <?php endif; ?>
                    <button class="button button-small bls-edit-map"
                            data-id="<?php echo (int) $entry->id; ?>"
                            data-text="<?php echo esc_attr( $entry->button_text ); ?>"
                            data-url="<?php echo esc_attr( $entry->assigned_url ); ?>"
                            data-title="<?php echo esc_attr( $entry->assigned_title ); ?>"
                            data-new-tab="<?php echo (int) $entry->opens_new_tab; ?>">
                        <?php esc_html_e( 'Edit', 'button-link-scanner' ); ?>
                    </button>
                    <button class="button button-small bls-delete-map" data-id="<?php echo (int) $entry->id; ?>">
                        <?php esc_html_e( 'Delete', 'button-link-scanner' ); ?>
                    </button>
                </td>
            </tr>
            <!-- Preview row (hidden by default) -->
            <tr class="bls-preview-row" id="bls-preview-<?php echo (int) $entry->id; ?>" style="display:none;">
                <td colspan="7">
                    <div class="bls-preview-inner">
                        <strong><?php esc_html_e( 'Pages that will be updated:', 'button-link-scanner' ); ?></strong>
                        <div class="bls-preview-content"></div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
