<?php
/**
 * 03A — neighborhood directory. Sixteen at launch, so search and sort run in
 * the browser against the full set.
 *
 * @var WP_Term[] $terms
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ao-directory ao-directory--terms" data-ao-terms="1">

	<div class="ao-controls">
		<div class="ao-search">
			<label class="screen-reader-text" for="ao-nbhd-search">Search neighborhoods</label>
			<input type="search" id="ao-nbhd-search" class="ao-term-search" placeholder="Search neighborhoods">
		</div>
		<div class="ao-sort">
			<label class="screen-reader-text" for="ao-nbhd-sort">Sort by</label>
			<select id="ao-nbhd-sort" class="ao-term-sort">
				<option value="asc">A–Z</option>
				<option value="desc">Z–A</option>
			</select>
		</div>
	</div>

	<div class="ao-status">
		<span class="ao-count"><?php echo esc_html( count( $terms ) ); ?></span>
	</div>

	<div class="ao-results ao-results--terms">
		<?php
		$last_letter = '';

		foreach ( $terms as $term ) :
			$sort_name = trim( preg_replace( '/^the\s+/i', '', $term->name ) );
			$letter    = strtoupper( substr( $sort_name, 0, 1 ) );
			$image     = function_exists( 'get_field' ) ? get_field( 'ao_card_image', $term ) : '';
			?>
			<?php if ( $letter !== $last_letter ) : ?>
				<div class="ao-group" data-letter="<?php echo esc_attr( $letter ); ?>" role="heading" aria-level="3"><?php echo esc_html( $letter ); ?></div>
				<?php $last_letter = $letter; ?>
			<?php endif; ?>

			<a class="ao-card ao-card--neighborhood"
				href="<?php echo esc_url( get_term_link( $term ) ); ?>"
				data-name="<?php echo esc_attr( strtolower( $sort_name ) ); ?>"
				data-letter="<?php echo esc_attr( $letter ); ?>">
				<div class="ao-card-media">
					<?php if ( $image ) : ?>
						<img src="<?php echo esc_url( is_array( $image ) ? $image['url'] : $image ); ?>" alt="" loading="lazy">
					<?php endif; ?>
				</div>
				<div class="ao-card-body">
					<h3 class="ao-card-title"><?php echo esc_html( $term->name ); ?></h3>
				</div>
			</a>
		<?php endforeach; ?>
	</div>

	<p class="ao-empty" hidden>No neighborhoods match that search.</p>
</div>
