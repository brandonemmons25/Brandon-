<?php
/**
 * Plugin Name: Essence Pro – Front Page 1 Widget Fix
 * Description: Ensures all three Featured Page widgets display correctly in the Front Page 1 widget area of the Essence Pro theme.
 * Version:     1.0.0
 * Author:      Brandon
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Problem: Essence Pro's essence_widget_area_class() calls essence_count_widgets(),
 * which uses wp_get_sidebars_widgets() to count how many widgets are registered in
 * a sidebar. If the stored option contains stale or ghost entries the count can come
 * back as 2 rather than 3, causing essence_widget_area_class() to return
 * 'widget-halves' (2-column float layout) instead of 'widget-thirds' (3-column).
 * With a 2-column layout and 3 actual widgets, the third widget wraps onto a second
 * row that is either clipped or never rendered visibly.
 *
 * Fix 1 – PHP: Filter sidebars_widgets so empty/falsy entries are stripped from
 *   front-page-1 before Essence Pro counts them, guaranteeing an accurate count.
 *
 * Fix 2 – CSS: Convert the front-page-1 flexible-widgets wrapper to flexbox so the
 *   three-column layout is enforced at the browser level regardless of float math.
 */

/* -------------------------------------------------------------------------
   Fix 1: Strip ghost/empty entries from front-page-1 widget list
   ------------------------------------------------------------------------- */

add_filter( 'sidebars_widgets', function ( $sidebars_widgets ) {
	if ( ! empty( $sidebars_widgets['front-page-1'] ) && is_array( $sidebars_widgets['front-page-1'] ) ) {
		// Remove any falsy / empty values that would throw off the widget count.
		$sidebars_widgets['front-page-1'] = array_values(
			array_filter( $sidebars_widgets['front-page-1'] )
		);
	}
	return $sidebars_widgets;
} );

/* -------------------------------------------------------------------------
   Fix 2: Override the float-based layout with flexbox for front-page-1 so
   all three widgets always line up in one row regardless of the CSS class
   that Essence Pro calculates.
   ------------------------------------------------------------------------- */

add_action( 'wp_head', function () {
	?>
	<style id="ep-fp1-fix">
		/* Force the three Featured Page widgets into a proper three-column row. */
		#front-page-1 .flexible-widgets {
			display: flex !important;
			flex-wrap: wrap !important;
		}

		/* Each widget takes an equal share of the row. */
		#front-page-1 .flexible-widgets .widget {
			flex: 1 1 calc(33.333% - 3.5%) !important;
			float: none !important;
			min-width: 0;
		}

		/* Stack to full width on small screens (matches Essence Pro breakpoint). */
		@media (max-width: 768px) {
			#front-page-1 .flexible-widgets .widget {
				flex: 1 1 100% !important;
			}
		}
	</style>
	<?php
} );
