<?php
/**
 * Curated horizontal strip. Hides itself while its directory is being
 * searched, filtered or re-sorted, and returns on reset.
 *
 * @var string $title
 * @var string $note
 * @var string $cards
 * @var string $key    Directory this strip belongs to.
 * @var bool   $hidden Page loaded with filters already applied.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="ao-featured" data-ao-hide-when-filtered="<?php echo esc_attr( $key ); ?>"<?php echo ! empty( $hidden ) ? ' hidden' : ''; ?>>
	<div class="ao-featured-head">
		<h2 class="ao-featured-title"><?php echo esc_html( $title ); ?></h2>
		<?php if ( $note ) : ?>
			<p class="ao-featured-note"><?php echo esc_html( $note ); ?></p>
		<?php endif; ?>
	</div>

	<div class="ao-carousel" data-ao-carousel>
		<button type="button" class="ao-nav ao-nav--prev" aria-label="Previous">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 4 7 12l8 8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
		</button>

		<div class="ao-track ao-track--featured">
			<?php echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>

		<button type="button" class="ao-nav ao-nav--next" aria-label="Next">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 4 8 8-8 8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
		</button>
	</div>
</section>
