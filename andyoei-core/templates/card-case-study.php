<?php
/**
 * 05D — seller case study card. Property imagery dominates.
 *
 * @var int $post_id
 */

defined( 'ABSPATH' ) || exit;

$result = get_post_meta( $post_id, 'ao_result', true );
?>
<a class="ao-card ao-card--case-study" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<div class="ao-card-media">
		<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'loading' => 'lazy' ) ); ?>
	</div>
	<div class="ao-card-body">
		<h3 class="ao-card-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<?php if ( $result ) : ?>
			<p class="ao-card-meta"><?php echo esc_html( wp_trim_words( $result, 18 ) ); ?></p>
		<?php endif; ?>
	</div>
</a>
