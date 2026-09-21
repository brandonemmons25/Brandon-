<?php
/**
 * Section shell: rule + eyebrow, display heading, view-all link, and either a
 * carousel track or a grid of cards.
 *
 * @var array  $atts
 * @var string $cards
 * @var bool   $carousel
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="ao-section">
	<div class="ao-section-head">
		<div class="ao-section-heading">
			<?php if ( $atts['eyebrow'] ) : ?>
				<p class="ao-eyebrow"><span class="ao-rule" aria-hidden="true"></span><?php echo esc_html( $atts['eyebrow'] ); ?></p>
			<?php endif; ?>

			<?php if ( $atts['title'] ) : ?>
				<h2 class="ao-section-title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
		</div>

		<?php if ( $atts['link'] && $atts['link_text'] ) : ?>
			<a class="ao-viewall" href="<?php echo esc_url( $atts['link'] ); ?>">
				<?php echo esc_html( $atts['link_text'] ); ?>
				<span class="ao-arrow" aria-hidden="true">&rarr;</span>
			</a>
		<?php endif; ?>
	</div>

	<?php if ( $carousel ) : ?>
		<div class="ao-carousel" data-ao-carousel>
			<button type="button" class="ao-nav ao-nav--prev" aria-label="Previous">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 4 7 12l8 8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
			</button>

			<div class="ao-track">
				<?php echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>

			<button type="button" class="ao-nav ao-nav--next" aria-label="Next">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 4 8 8-8 8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
			</button>
		</div>
	<?php else : ?>
		<div class="ao-section-grid">
			<?php echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	<?php endif; ?>
</section>
