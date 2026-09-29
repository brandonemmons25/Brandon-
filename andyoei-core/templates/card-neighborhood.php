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

// The building count leads and keeps itself current — it is the published
// count on this term, not a number anyone types. The rest are stat pairs
// labelled per neighborhood; a pair missing either half is skipped.
$count = (int) $term->count;
$rows  = array( 'Buildings' => $count ? number_format( $count ) : '—' );

foreach ( range( 1, 3 ) as $n ) {
	$label = get_term_meta( $term->term_id, "ao_stat{$n}_label", true );
	$value = get_term_meta( $term->term_id, "ao_stat{$n}_value", true );

	if ( $label && $value ) {
		$rows[ $label ] = $value;
	}
}

$classes = 'ao-card ao-card--neighborhood' . ( ! empty( $featured ) ? ' is-featured' : '' );
?>
<a class="<?php echo esc_attr( $classes ); ?>"
	href="<?php echo esc_url( AO_Destinations::for_term( $term ) ); ?>"
	data-name="<?php echo esc_attr( strtolower( $sort_name ) ); ?>">
	<div class="ao-card-mark">
		<span class="ao-card-name"><?php echo esc_html( $term->name ); ?></span>
	</div>

	<div class="ao-card-media">
		<?php if ( $src ) : ?>
			<img src="<?php echo esc_url( $src ); ?>" alt="" loading="lazy">
		<?php endif; ?>
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
