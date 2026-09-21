<?php
/**
 * 07C — press card. Links to the original publisher by default.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$publication = get_post_meta( $post_id, 'ao_publication', true );
$url         = get_post_meta( $post_id, 'ao_external_url', true );
$cats        = wp_get_object_terms( $post_id, 'ao_press_category', array( 'fields' => 'names' ) );
$cat         = ( ! is_wp_error( $cats ) && $cats ) ? $cats[0] : '';
?>
<article class="ao-card ao-card--press">
	<?php if ( $publication ) : ?>
		<p class="ao-card-eyebrow"><?php echo esc_html( $publication ); ?></p>
	<?php endif; ?>

	<h3 class="ao-card-title">
		<?php if ( $url ) : ?>
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
		<?php else : ?>
			<?php echo esc_html( get_the_title( $post_id ) ); ?>
		<?php endif; ?>
	</h3>

	<p class="ao-card-meta">
		<time datetime="<?php echo esc_attr( get_the_date( 'c', $post_id ) ); ?>"><?php echo esc_html( get_the_date( 'F j, Y', $post_id ) ); ?></time>
		<?php if ( $cat ) : ?>
			<span class="ao-card-sep">·</span><?php echo esc_html( $cat ); ?>
		<?php endif; ?>
	</p>
</article>
