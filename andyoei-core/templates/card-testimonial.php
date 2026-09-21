<?php
/**
 * 07B — testimonial card. The client's words carry the card; metadata stays
 * restrained.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$attribution = get_post_meta( $post_id, 'ao_attribution', true );
?>
<figure class="ao-card ao-card--testimonial">
	<blockquote class="ao-quote"><?php echo wp_kses_post( wpautop( get_post_field( 'post_content', $post_id ) ) ); ?></blockquote>
	<?php if ( $attribution ) : ?>
		<figcaption class="ao-attribution"><?php echo esc_html( $attribution ); ?></figcaption>
	<?php endif; ?>
</figure>
