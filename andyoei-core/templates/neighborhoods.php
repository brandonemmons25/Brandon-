<?php
/**
 * 03A — Neighborhood Directory. Sixteen at launch, so the full set renders at
 * once and search and sort run in the browser.
 *
 * @var WP_Term[] $terms
 * @var string    $featured
 * @var string    $count_label
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ao-directory ao-directory--terms" data-ao-terms="neighborhoods"<?php echo AO_Shortcodes::style( $width, $columns ); ?>>

	<div class="ao-bar">
		<div class="ao-pill ao-pill--search">
			<label class="screen-reader-text" for="ao-nbhd-search">Search Neighborhoods</label>
			<input type="search" id="ao-nbhd-search" class="ao-term-search ao-search-input" placeholder="Search Neighborhoods">
		</div>
		<div class="ao-pill ao-pill--sort">
			<span class="ao-pill-label">Sort</span>
			<label class="screen-reader-text" for="ao-nbhd-sort">Sort by</label>
			<select id="ao-nbhd-sort" class="ao-term-sort ao-sort-select">
				<option value="asc">A–Z</option>
				<option value="desc">Z–A</option>
			</select>
		</div>
	</div>

	<?php
	// Hides while the directory is searched or sorted the other way.
	echo $featured; // phpcs:ignore WordPress.Security.EscapeOutput
	?>

	<div class="ao-meta">
		<span class="ao-count"><?php echo esc_html( count( $terms ) . ' ' . $count_label ); ?></span>
	</div>

	<div class="ao-results ao-results--terms">
		<?php
		$last_letter = '';

		foreach ( $terms as $term ) :
			$sort_name = trim( preg_replace( '/^the\s+/i', '', $term->name ) );
			$letter    = strtoupper( substr( $sort_name, 0, 1 ) );

			if ( $letter !== $last_letter ) :
				$last_letter = $letter;
				?>
				<div class="ao-group" data-letter="<?php echo esc_attr( $letter ); ?>" role="heading" aria-level="3"><?php echo esc_html( $letter ); ?></div>
				<?php
			endif;

			echo ao_template( 'card-neighborhood.php', array( 'term' => $term, 'featured' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		endforeach;
		?>
	</div>

	<p class="ao-empty" hidden>No neighborhoods match that search.</p>
</div>
