<?php
/**
 * 02A — Condominium Building Directory: controls, featured strip, results.
 *
 * Override by copying to your theme as andyoei/directory.php.
 *
 * @var array  $config
 * @var array  $request
 * @var array  $result
 * @var bool   $filtered
 * @var string $featured
 */

defined( 'ABSPATH' ) || exit;

$key = $config['key'];
?>
<div class="ao-directory" data-ao-directory="<?php echo esc_attr( $key ); ?>" data-filtered="<?php echo $filtered ? '1' : '0'; ?>">

	<div class="ao-controls">
		<?php if ( $config['search'] ) : ?>
			<div class="ao-control ao-control--search">
				<label class="screen-reader-text" for="ao-s-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $config['search_label'] ); ?></label>
				<input
					type="search"
					id="ao-s-<?php echo esc_attr( $key ); ?>"
					class="ao-search-input"
					placeholder="<?php echo esc_attr( $config['search_label'] ); ?>"
					value="<?php echo esc_attr( $request['search'] ); ?>">
			</div>
		<?php endif; ?>

		<?php if ( $config['sorts'] ) : ?>
			<div class="ao-control ao-control--sort">
				<label for="ao-sort-<?php echo esc_attr( $key ); ?>">Sort by:</label>
				<select id="ao-sort-<?php echo esc_attr( $key ); ?>" class="ao-sort-select">
					<?php foreach ( $config['sorts'] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $request['sort'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<?php if ( $config['facets'] ) : ?>
			<button type="button" class="ao-control ao-filters-toggle" aria-expanded="false" aria-controls="ao-drawer-<?php echo esc_attr( $key ); ?>">
				Filters
			</button>
		<?php endif; ?>
	</div>

	<?php
	// The strip sits between the controls and the full directory, and hides
	// itself as soon as the visitor narrows the set.
	echo $featured; // phpcs:ignore WordPress.Security.EscapeOutput
	?>

	<?php if ( $config['facets'] ) : ?>
		<div class="ao-drawer" id="ao-drawer-<?php echo esc_attr( $key ); ?>" hidden>
			<?php foreach ( $config['facets'] as $name => $facet ) : ?>
				<?php
				$terms = get_terms( array( 'taxonomy' => $facet['taxonomy'], 'hide_empty' => false ) );

				if ( is_wp_error( $terms ) || ! $terms ) {
					continue;
				}

				$active = isset( $request['facets'][ $name ] ) ? $request['facets'][ $name ] : array();
				?>
				<fieldset class="ao-facet" data-facet="<?php echo esc_attr( $name ); ?>">
					<legend><?php echo esc_html( $facet['label'] ); ?></legend>
					<?php foreach ( $terms as $term ) : ?>
						<label class="ao-facet-option">
							<input
								type="checkbox"
								value="<?php echo esc_attr( $term->slug ); ?>"
								data-label="<?php echo esc_attr( $term->name ); ?>"
								<?php checked( in_array( $term->slug, $active, true ) ); ?>>
							<span><?php echo esc_html( $term->name ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="ao-directory-head">
		<h2 class="ao-directory-title">All Condominium Buildings</h2>
		<span class="ao-count"><?php echo esc_html( $result['total'] . ' ' . ( 1 === $result['total'] ? $config['count_label'][0] : $config['count_label'][1] ) ); ?></span>
	</div>

	<div class="ao-status">
		<span class="ao-chips"></span>
		<button type="button" class="ao-clear" hidden>Clear All</button>
	</div>

	<div class="ao-results" aria-live="polite" data-last-group="<?php echo esc_attr( $result['last_group'] ); ?>">
		<?php
		// Card markup is escaped inside the card templates.
		echo $result['html']; // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	</div>

	<p class="ao-empty" <?php echo $result['total'] ? 'hidden' : ''; ?>>No buildings match those filters. Try removing one.</p>

	<div class="ao-more-wrap">
		<button type="button" class="ao-more" <?php echo $result['has_more'] ? '' : 'hidden'; ?>>Load More Buildings</button>
	</div>
</div>
