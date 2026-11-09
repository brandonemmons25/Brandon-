<?php
/**
 * 02A — building card: a 5:4 architectural photo, then the building name and
 * its street address. Nothing else: price, completion and amenities belong on
 * the building's own page, not on a card being scanned by the dozen.
 *
 * The featured strip adds the neighborhood above the name, because those
 * cards sit on their own rather than under a neighborhood heading.
 *
 * @var int  $post_id
 * @var bool $featured
 */

defined( 'ABSPATH' ) || exit;

$address = get_post_meta( $post_id, 'ao_address', true );
$nbhd    = ! empty( $featured ) ? AO_Relations::name_for_building( $post_id ) : '';

$classes = 'ao-card ao-card--building' . ( ! empty( $featured ) ? ' is-featured' : '' );
?>
<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( AO_Destinations::for_post( $post_id ) ); ?>">
	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
	</div>

	<div class="ao-card-body">
		<?php if ( $nbhd ) : ?>
			<p class="ao-card-eyebrow"><?php echo esc_html( $nbhd ); ?></p>
		<?php endif; ?>

		<h3 class="ao-card-name"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>

		<?php if ( $address ) : ?>
			<p class="ao-card-address"><?php echo esc_html( $address ); ?></p>
		<?php endif; ?>
	</div>
</a>
