<?php
/**
 * 03A — neighborhood card: wordmark, large image, then detail rows, matching
 * the building card so the two directories read as one system.
 *
 * @var WP_Term $term
 * @var bool    $featured
 */

defined( 'ABSPATH' ) || exit;

$image_id = (int) get_term_meta( $term->term_id, 'ao_card_image', true );
$src      = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';

// "The Ritz" would sort under R; neighborhoods follow the same rule.
$sort_name = trim( preg_replace( '/^the\s+/i', '', $term->name ) );

// ACF term fields, absent until it is installed.
$section = function_exists( 'get_field' ) ? get_field( 'ao_section', $term ) : '';
$zips    = function_exists( 'get_field' ) ? get_field( 'ao_zip_codes', $term ) : '';

$count = (int) $term->count;

// Always three rows, matching the building card, so the two directories
// line up and no card is shorter than its neighbours.
$rows = array(
	'Buildings' => $count ? number_format( $count ) : '—',
	'Section'   => $section ? $section : '—',
	'ZIP Codes' => $zips ? $zips : '—',
);

$classes = 'ao-card ao-card--neighborhood' . ( ! empty( $featured ) ? ' is-featured' : '' );
?>
<a class="<?php echo esc_attr( $classes ); ?>"
	href="<?php echo esc_url( get_term_link( $term ) ); ?>"
	data-name="<?php echo esc_attr( strtolower( $sort_name ) ); ?>">
	<div class="ao-card-mark">
		<span class="ao-card-name"><?php echo esc_html( $term->name ); ?></span>
	</div>

	<div class="ao-card-media">
		<?php if ( $src ) : ?>
			<img src="<?php echo esc_url( $src ); ?>" alt="" loading="lazy">
		<?php endif; ?>
	</div>

	<dl class="ao-card-specs">
		<?php foreach ( $rows as $label => $value ) : ?>
			<div class="ao-spec">
				<dt><?php echo esc_html( $label ); ?></dt>
				<dd><?php echo esc_html( $value ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
</a>
