<?php
/**
 * 02A — building card: logo (or name), large image, then detail rows
 * separated by hairlines. No card chrome; the photography carries it.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$nbhds = wp_get_object_terms( $post_id, 'ao_neighborhood', array( 'fields' => 'names' ) );
$nbhd  = ( ! is_wp_error( $nbhds ) && $nbhds ) ? $nbhds[0] : '';

$price      = get_post_meta( $post_id, 'ao_starting_price', true );
$completion = get_post_meta( $post_id, 'ao_completion', true );

// ACF field, absent until it is installed.
$logo     = function_exists( 'get_field' ) ? get_field( 'ao_building_logo', $post_id ) : '';
$logo_src = is_array( $logo ) ? $logo['url'] : $logo;

// Always three rows, so every card in a row is the same height. An empty
// price reads as "Price on request", as the reference does.
$rows = array(
	'Starting At'     => $price ? $price : 'Price on request',
	'Area'            => $nbhd ? $nbhd : '—',
	'Completion Date' => $completion ? $completion : '—',
);
?>
<a class="ao-card ao-card--building" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<div class="ao-card-mark">
		<?php if ( $logo_src ) : ?>
			<img class="ao-card-logo" src="<?php echo esc_url( $logo_src ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" loading="lazy">
		<?php else : ?>
			<span class="ao-card-name"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
		<?php endif; ?>
	</div>

	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
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
