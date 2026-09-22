<?php
/**
 * 03A — neighborhood card: large image, name beneath a hairline. The whole
 * card opens the individual neighborhood page.
 *
 * @var WP_Term $term
 * @var bool    $featured
 */

defined( 'ABSPATH' ) || exit;

$image_id = (int) get_term_meta( $term->term_id, 'ao_card_image', true );
$src      = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';

// "The Ritz" would group under R; neighborhoods follow the same rule.
$sort_name = trim( preg_replace( '/^the\s+/i', '', $term->name ) );
$letter    = strtoupper( substr( $sort_name, 0, 1 ) );

$count   = (int) $term->count;
$classes = 'ao-card ao-card--neighborhood' . ( ! empty( $featured ) ? ' is-featured' : '' );
?>
<a class="<?php echo esc_attr( $classes ); ?>"
	href="<?php echo esc_url( get_term_link( $term ) ); ?>"
	data-name="<?php echo esc_attr( strtolower( $sort_name ) ); ?>"
	data-letter="<?php echo esc_attr( $letter ); ?>">
	<div class="ao-card-media">
		<?php if ( $src ) : ?>
			<img src="<?php echo esc_url( $src ); ?>" alt="" loading="lazy">
		<?php endif; ?>
	</div>

	<dl class="ao-card-specs">
		<div class="ao-spec">
			<dt><?php echo esc_html( $term->name ); ?></dt>
			<dd><?php echo esc_html( $count . ( 1 === $count ? ' Building' : ' Buildings' ) ); ?></dd>
		</div>
	</dl>
</a>
