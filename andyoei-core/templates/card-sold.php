<?php
/**
 * Transaction card: full-bleed image with the detail panel overlapping the
 * bottom-left corner. Sold dates are deliberately never shown.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$price = get_post_meta( $post_id, 'ao_price', true );
$beds  = get_post_meta( $post_id, 'ao_beds', true );
$baths = get_post_meta( $post_id, 'ao_baths', true );
$sqft  = get_post_meta( $post_id, 'ao_sqft', true );
$city  = get_post_meta( $post_id, 'ao_city', true );

$nbhds = wp_get_object_terms( $post_id, 'ao_neighborhood', array( 'fields' => 'names' ) );
$nbhd  = ( ! is_wp_error( $nbhds ) && $nbhds ) ? $nbhds[0] : '';

$place = array_filter( array( $nbhd, $city ? $city : 'Philadelphia' ) );

$facts = array_filter( array(
	$beds ? $beds . ' Beds' : '',
	$baths ? $baths . ' Baths' : '',
	$sqft ? number_format( (float) $sqft ) . ' Sq Ft' : '',
) );

$link = get_permalink( $post_id );
$tag  = $link ? 'a' : 'div';
?>
<<?php echo esc_attr( $tag ); ?> class="ao-card ao-card--property"<?php echo $link ? ' href="' . esc_url( $link ) . '"' : ''; ?>>
	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
	</div>

	<div class="ao-card-panel">
		<?php if ( $place ) : ?>
			<p class="ao-card-eyebrow"><?php echo esc_html( implode( ' · ', $place ) ); ?></p>
		<?php endif; ?>

		<h3 class="ao-card-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>

		<?php if ( $facts ) : ?>
			<ul class="ao-card-facts">
				<?php foreach ( $facts as $fact ) : ?>
					<li><?php echo esc_html( $fact ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $price ) : ?>
			<p class="ao-card-price">
				<?php echo esc_html( '$' . number_format( (float) preg_replace( '/[^0-9.]/', '', $price ) ) ); ?>
			</p>
		<?php endif; ?>
	</div>
</<?php echo esc_attr( $tag ); ?>>
