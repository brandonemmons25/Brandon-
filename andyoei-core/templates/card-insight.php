<?php
/**
 * 06 — market insight card.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$period = get_post_meta( $post_id, 'ao_report_period', true );
$cats   = wp_get_object_terms( $post_id, 'ao_insight_category', array( 'fields' => 'names' ) );
$cat    = ( ! is_wp_error( $cats ) && $cats ) ? $cats[0] : '';
?>
<a class="ao-card ao-card--insight" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
	</div>
	<div class="ao-card-body">
		<?php if ( $cat ) : ?>
			<p class="ao-card-eyebrow"><?php echo esc_html( $cat ); ?></p>
		<?php endif; ?>
		<h3 class="ao-card-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<p class="ao-card-meta">
			<?php echo esc_html( $period ? $period : get_the_date( 'F j, Y', $post_id ) ); ?>
		</p>
	</div>
</a>
