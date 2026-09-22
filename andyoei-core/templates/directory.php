<?php
/**
 * 02A — Condominium Building Directory.
 *
 * A compact bar: Filters (holding the secondary filters), search, then one
 * dropdown per primary filter. Override by copying to your theme as
 * andyoei/directory.php.
 *
 * @var array  $config
 * @var array  $request
 * @var array  $result
 * @var bool   $filtered
 * @var string $featured
 * @var string $width
 * @var string $columns
 */

defined( 'ABSPATH' ) || exit;

$key = $config['key'];

// Primary filters sit in the bar; the rest live behind the Filters button.
$bar   = array();
$panel = array();

foreach ( $config['facets'] as $name => $facet ) {
	if ( ! empty( $facet['in_bar'] ) ) {
		$bar[ $name ] = $facet;
	} else {
		$panel[ $name ] = $facet;
	}
}

/**
 * One facet's options, as an "All" reset followed by its choices.
 */
$render_group = function ( $name, $facet ) use ( $request ) {
	$options = AO_Query::facet_options( $facet );
	$active  = isset( $request['facets'][ $name ] ) ? $request['facets'][ $name ] : array();
	$single  = ! empty( $facet['single'] );
	$type    = $single ? 'radio' : 'checkbox';
	$all     = isset( $facet['all_label'] ) ? $facet['all_label'] : 'All';
	?>
	<div class="ao-group" data-facet="<?php echo esc_attr( $name ); ?>" data-all="<?php echo esc_attr( $all ); ?>">
		<?php if ( ! empty( $facet['show_legend'] ) ) : ?>
			<p class="ao-group-label"><?php echo esc_html( $facet['label'] ); ?></p>
		<?php endif; ?>

		<label class="ao-option ao-option--all">
			<input type="<?php echo esc_attr( $type ); ?>" name="ao-<?php echo esc_attr( $name ); ?>" value="" <?php checked( empty( $active ) ); ?>>
			<span><?php echo esc_html( $all ); ?></span>
		</label>

		<?php foreach ( $options as $value => $label ) : ?>
			<label class="ao-option">
				<input
					type="<?php echo esc_attr( $type ); ?>"
					name="ao-<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					data-label="<?php echo esc_attr( $label ); ?>"
					<?php checked( in_array( (string) $value, $active, true ) ); ?>>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
		<?php endforeach; ?>
	</div>
	<?php
};
?>
<div class="ao-directory" data-ao-directory="<?php echo esc_attr( $key ); ?>" data-filtered="<?php echo $filtered ? '1' : '0'; ?>"<?php echo AO_Shortcodes::style( $width, $columns ); ?>>

	<div class="ao-bar">
		<?php if ( $panel ) : ?>
			<div class="ao-pill ao-pill--panel">
				<button type="button" class="ao-pill-button" aria-expanded="false" aria-haspopup="true">
					<span class="ao-pill-label">Filters</span>
					<span class="ao-pill-count" hidden></span>
					<span class="ao-pill-icon" aria-hidden="true">
						<svg viewBox="0 0 16 12" focusable="false"><path d="M1 2h14M3 6h10M6 10h4" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
					</span>
				</button>

				<div class="ao-menu ao-menu--panel" hidden>
					<?php foreach ( $panel as $name => $facet ) : ?>
						<?php
						$facet['show_legend'] = true;
						$render_group( $name, $facet );
						?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $config['search'] ) : ?>
			<div class="ao-pill ao-pill--search">
				<label class="screen-reader-text" for="ao-s-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $config['search_label'] ); ?></label>
				<input
					type="search"
					id="ao-s-<?php echo esc_attr( $key ); ?>"
					class="ao-search-input"
					placeholder="<?php echo esc_attr( $config['search_label'] ); ?>"
					value="<?php echo esc_attr( $request['search'] ); ?>">
			</div>
		<?php endif; ?>

		<?php foreach ( $bar as $name => $facet ) : ?>
			<?php
			$options = AO_Query::facet_options( $facet );

			if ( ! $options ) {
				continue;
			}

			$active = isset( $request['facets'][ $name ] ) ? $request['facets'][ $name ] : array();
			$wide   = count( $options ) > 8 ? ' ao-menu--wide' : '';
			?>
			<div class="ao-pill ao-pill--facet">
				<button type="button" class="ao-pill-button" aria-expanded="false" aria-haspopup="true">
					<span class="ao-pill-label"><?php echo esc_html( $facet['label'] ); ?></span>
					<span class="ao-pill-value"><?php echo esc_html( AO_Query::facet_summary( $facet, $options, $active ) ); ?></span>
					<span class="ao-pill-caret" aria-hidden="true"></span>
				</button>

				<div class="ao-menu<?php echo esc_attr( $wide ); ?>" hidden>
					<?php $render_group( $name, $facet ); ?>
				</div>
			</div>
		<?php endforeach; ?>

		<?php if ( $config['sorts'] ) : ?>
			<div class="ao-pill ao-pill--sort">
				<span class="ao-pill-label">Sort</span>
				<label class="screen-reader-text" for="ao-sort-<?php echo esc_attr( $key ); ?>">Sort by</label>
				<select id="ao-sort-<?php echo esc_attr( $key ); ?>" class="ao-sort-select">
					<?php foreach ( $config['sorts'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $request['sort'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<button type="button" class="ao-clear" <?php echo $filtered ? '' : 'hidden'; ?>>Clear All</button>
	</div>

	<?php
	// The strip hides as soon as the visitor narrows the set.
	echo $featured; // phpcs:ignore WordPress.Security.EscapeOutput
	?>

	<div class="ao-meta">
		<span class="ao-count"><?php echo esc_html( $result['total'] . ' ' . ( 1 === $result['total'] ? $config['count_label'][0] : $config['count_label'][1] ) ); ?></span>
	</div>

	<div class="ao-results" aria-live="polite" data-last-group="<?php echo esc_attr( $result['last_group'] ); ?>">
		<?php
		// Card markup is escaped inside the card templates.
		echo $result['html']; // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	</div>

	<p class="ao-empty" <?php echo $result['total'] ? 'hidden' : ''; ?>>No buildings match those filters.</p>

	<div class="ao-more-wrap">
		<button type="button" class="ao-more" <?php echo $result['has_more'] ? '' : 'hidden'; ?>>Load More</button>
	</div>
</div>
