<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap mdg-wrap">
    <h1><?php esc_html_e( 'Meta Description Generator — Settings', 'meta-description-generator' ); ?></h1>

    <?php if ( $saved ) : ?>
    <div class="mdg-notice mdg-notice--success">
        <?php esc_html_e( 'Settings saved.', 'meta-description-generator' ); ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mdg_save_settings' ); ?>
        <input type="hidden" name="action" value="mdg_save_settings">

        <table class="form-table mdg-settings-table">
            <tr>
                <th scope="row">
                    <label for="mdg_api_key"><?php esc_html_e( 'Claude API Key', 'meta-description-generator' ); ?></label>
                </th>
                <td>
                    <input type="password" id="mdg_api_key" name="mdg_api_key"
                           value="<?php echo esc_attr( $api_key ); ?>"
                           class="regular-text" autocomplete="off">
                    <button type="button" id="mdg-toggle-key" class="button button-small">
                        <?php esc_html_e( 'Show', 'meta-description-generator' ); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e( 'Your Anthropic API key. Get one at console.anthropic.com.', 'meta-description-generator' ); ?><br>
                        <?php printf(
                            esc_html__( 'Model used: %s', 'meta-description-generator' ),
                            '<strong>' . esc_html( MDG_CLAUDE_MODEL ) . '</strong>'
                        ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <div class="mdg-card" style="max-width:600px">
            <h3 style="margin-top:0"><?php esc_html_e( 'Meta Description Standards', 'meta-description-generator' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Optimal length: 140–160 characters', 'meta-description-generator' ); ?></li>
                <li><?php esc_html_e( 'Put your keywords and CTA within the first 120 characters — mobile can truncate after that', 'meta-description-generator' ); ?></li>
                <li><?php esc_html_e( 'Keep descriptions conversational, unique per page, and actionable', 'meta-description-generator' ); ?></li>
                <li><?php esc_html_e( 'Write descriptions as a selling proposition with a direct call to action — not just a summary of the page', 'meta-description-generator' ); ?></li>
                <li><?php esc_html_e( 'Naturally include your business name for brand recognition in search results', 'meta-description-generator' ); ?></li>
                <li><?php esc_html_e( 'Meta descriptions are not a direct ranking factor but strongly influence click-through rate', 'meta-description-generator' ); ?></li>
            </ul>
        </div>

        <?php submit_button( __( 'Save Settings', 'meta-description-generator' ) ); ?>
    </form>
</div>

<script>
document.getElementById('mdg-toggle-key').addEventListener('click', function () {
    var f = document.getElementById('mdg_api_key');
    if (f.type === 'password') { f.type = 'text';  this.textContent = 'Hide'; }
    else                       { f.type = 'password'; this.textContent = 'Show'; }
});
</script>
