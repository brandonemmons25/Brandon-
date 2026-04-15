<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap mdg-wrap">
    <h1><?php esc_html_e( 'Meta Description Generator', 'meta-description-generator' ); ?></h1>

    <?php if ( ! $has_yoast ) : ?>
    <div class="mdg-notice mdg-notice--warn">
        <strong><?php esc_html_e( 'Yoast SEO not detected.', 'meta-description-generator' ); ?></strong>
        <?php esc_html_e( 'Install and activate Yoast SEO for descriptions to be applied to the correct field.', 'meta-description-generator' ); ?>
    </div>
    <?php endif; ?>

    <?php if ( ! $has_key ) : ?>
    <div class="mdg-notice mdg-notice--warn">
        <strong><?php esc_html_e( 'No Claude API key.', 'meta-description-generator' ); ?></strong>
        <?php printf(
            /* translators: %s: settings URL */
            wp_kses( __( 'Add your key on the <a href="%s">Settings page</a> before generating descriptions.', 'meta-description-generator' ), [ 'a' => [ 'href' => [] ] ] ),
            esc_url( admin_url( 'admin.php?page=meta-description-generator-settings' ) )
        ); ?>
    </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <?php if ( ! empty( $summary ) && (int) $summary['total'] > 0 ) : ?>
    <div class="mdg-summary-grid">
        <div class="mdg-stat-card mdg-stat-card--info">
            <span class="mdg-stat-number"><?php echo (int) $summary['total']; ?></span>
            <span class="mdg-stat-label"><?php esc_html_e( 'Total Pages & Posts', 'meta-description-generator' ); ?></span>
        </div>
        <div class="mdg-stat-card mdg-stat-card--danger">
            <span class="mdg-stat-number"><?php echo (int) $summary['missing']; ?></span>
            <span class="mdg-stat-label"><?php esc_html_e( 'Missing Description', 'meta-description-generator' ); ?></span>
        </div>
        <div class="mdg-stat-card mdg-stat-card--success">
            <span class="mdg-stat-number"><?php echo (int) $summary['has_meta']; ?></span>
            <span class="mdg-stat-label"><?php esc_html_e( 'Have Description', 'meta-description-generator' ); ?></span>
        </div>
        <div class="mdg-stat-card mdg-stat-card--warning">
            <span class="mdg-stat-number"><?php echo (int) $summary['too_short']; ?></span>
            <span class="mdg-stat-label"><?php esc_html_e( 'Too Short (&lt;120 chars)', 'meta-description-generator' ); ?></span>
        </div>
        <div class="mdg-stat-card mdg-stat-card--warning">
            <span class="mdg-stat-number"><?php echo (int) $summary['too_long']; ?></span>
            <span class="mdg-stat-label"><?php esc_html_e( 'Too Long (&gt;158 chars)', 'meta-description-generator' ); ?></span>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="mdg-quick-actions">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=meta-description-generator-generate&status=missing' ) ); ?>"
           class="button button-primary button-hero">
            <?php esc_html_e( 'Generate Missing Descriptions', 'meta-description-generator' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=meta-description-generator-generate&status=all' ) ); ?>"
           class="button button-secondary">
            <?php esc_html_e( 'View All Pages & Posts', 'meta-description-generator' ); ?>
        </a>
    </div>
    <?php else : ?>
    <div class="mdg-card mdg-card--notice">
        <p><?php esc_html_e( 'No published posts or pages found. If you just installed the plugin, make sure you have published content.', 'meta-description-generator' ); ?></p>
    </div>
    <?php endif; ?>

    <!-- About -->
    <div class="mdg-card" style="margin-top:24px">
        <h2><?php esc_html_e( 'How It Works', 'meta-description-generator' ); ?></h2>
        <ol class="mdg-steps">
            <li>
                <strong><?php esc_html_e( 'Review the list', 'meta-description-generator' ); ?></strong> —
                <?php esc_html_e( 'Go to Generate & Apply to see every page and post. Filter to "Missing" to focus on gaps.', 'meta-description-generator' ); ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'Generate with Claude AI', 'meta-description-generator' ); ?></strong> —
                <?php esc_html_e( 'Click "Generate All" to write descriptions for every visible row, or click per-row to generate one at a time. Claude reads the page title and content to write 120–158 character descriptions.', 'meta-description-generator' ); ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'Review & edit', 'meta-description-generator' ); ?></strong> —
                <?php esc_html_e( 'Each generated description is editable inline. A live character counter shows whether it is within the ideal 120–158 range.', 'meta-description-generator' ); ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'Apply to Yoast', 'meta-description-generator' ); ?></strong> —
                <?php esc_html_e( 'Click "Apply All" or per-row "Apply" to write the descriptions directly to Yoast SEO\'s meta description field. No copy/paste required.', 'meta-description-generator' ); ?>
            </li>
        </ol>
        <p class="mdg-meta">
            <?php printf(
                esc_html__( 'Optimal length: 120–158 characters. Put keywords and your CTA within the first 120 so mobile truncation doesn\'t cut them. Powered by %s.', 'meta-description-generator' ),
                '<strong>' . esc_html( MDG_CLAUDE_MODEL ) . '</strong>'
            ); ?>
        </p>
    </div>
</div>
