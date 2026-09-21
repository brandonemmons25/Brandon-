<?php
/**
 * 07D — one proof point, written once and reused.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$value    = get_post_meta( $post_id, 'ao_value', true );
$label    = get_post_meta( $post_id, 'ao_label', true );
$footnote = get_post_meta( $post_id, 'ao_footnote', true );
?>
<div class="ao-proof">
	<span class="ao-proof-value"><?php echo esc_html( $value ? $value : get_the_title( $post_id ) ); ?></span>
	<?php if ( $label ) : ?>
		<span class="ao-proof-label"><?php echo esc_html( $label ); ?></span>
	<?php endif; ?>
	<?php if ( $footnote ) : ?>
		<span class="ao-proof-note"><?php echo esc_html( $footnote ); ?></span>
	<?php endif; ?>
</div>
