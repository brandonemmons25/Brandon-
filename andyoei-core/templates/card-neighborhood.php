<?php
/**
 * 03A — neighborhood card: wordmark, large image, then two stat columns,
 * matching the building card so the two directories read as one system.
 *
 * @var int  $post_id
 * @var bool $featured
 */

defined( 'ABSPATH' ) || exit;

$title = get_the_title( $post_id );

// "The Ritz" would sort under R; neighborhoods follow the same rule.
$sort_name = trim( preg_replace( '/^the\s+/i', '', $title ) );

// Two columns. The building count keeps itself current — it is the published
// count of buildings filed here, not a number anyone types.
$count = AO_Relations::building_count( $post_id );
$price = get_post_meta( $post_id, 'ao_median_price', true );

$rows = array(
	'Buildings'    => $count ? number_format( $count ) : '—',
	'Median Price' => $price ? $price : '—',
);

$classes = 'ao-card ao-card--neighborhood' . ( ! empty( $featured ) ? ' is-featured' : '' );
?>
<a class="<?php echo esc_attr( $classes ); ?>"
	href="<?php echo esc_url( AO_Destinations::for_post( $post_id ) ); ?>"
	data-name="<?php echo esc_attr( strtolower( $sort_name ) ); ?>">
	<div class="ao-card-mark">
		<span class="ao-card-name"><?php echo esc_html( $title ); ?></span>
	</div>

	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
	</div>

	<dl class="ao-card-specs" style="--ao-spec-columns:<?php echo (int) count( $rows ); ?>">
		<?php foreach ( $rows as $label => $value ) : ?>
			<div class="ao-spec">
				<dt><?php echo esc_html( $label ); ?></dt>
				<dd><?php echo esc_html( $value ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
</a>
