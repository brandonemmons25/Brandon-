<?php
/**
 * 01 — sold property card. Price leads; sold date is deliberately not shown.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$price   = get_post_meta( $post_id, 'ao_price', true );
$address = get_post_meta( $post_id, 'ao_address', true );
?>
<div class="ao-card ao-card--sold">
	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
	</div>
	<div class="ao-card-body">
		<h3 class="ao-card-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<?php if ( $address ) : ?>
			<p class="ao-card-meta"><?php echo esc_html( $address ); ?></p>
		<?php endif; ?>
		<?php if ( $price ) : ?>
			<p class="ao-card-price"><?php echo esc_html( '$' . number_format( (float) preg_replace( '/[^0-9.]/', '', $price ) ) ); ?></p>
		<?php endif; ?>
	</div>
</div>
