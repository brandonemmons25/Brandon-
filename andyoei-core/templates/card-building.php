<?php
/**
 * 02A — building card: large image, name, neighborhood. Whole card clickable.
 * No amenity icons, per the concept document.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$nbhds = wp_get_object_terms( $post_id, 'ao_neighborhood', array( 'fields' => 'names' ) );
$nbhd  = ( ! is_wp_error( $nbhds ) && $nbhds ) ? $nbhds[0] : '';
?>
<a class="ao-card ao-card--building" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
	</div>
	<div class="ao-card-body">
		<h3 class="ao-card-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<?php if ( $nbhd ) : ?>
			<p class="ao-card-meta"><?php echo esc_html( $nbhd ); ?></p>
		<?php endif; ?>
	</div>
</a>
