<?php
/**
 * Plugin Name: Essence Pro – Front Page 1 Widget Fix
 * Description: Ensures all three Featured Page widgets display correctly in the Front Page 1 widget area of the Essence Pro theme.
 * Version:     1.1.0
 * Author:      Brandon
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Problem: Essence Pro's essence_widget_area_class() calls essence_count_widgets(),
 * which uses wp_get_sidebars_widgets() to count widgets. If the count comes back as 2
 * the function returns 'widget-halves' (2-column float layout) instead of 'widget-thirds'
 * (3-column). The third widget still renders but wraps onto a second row.
 *
 * Fix 1 – PHP: Strip ghost/empty entries from front-page-1 before the count runs.
 *
 * Fix 2 – CSS: Override only the float widths for #front-page-1 so three widgets always
 *   sit in one row, matching Essence Pro's own float-based grid math (31% + 3.5% gap).
 *   No flexbox — keeps all internal widget markup (title position, image, etc.) intact.
 */

/* -------------------------------------------------------------------------
   Fix 1: Ensure the widget count for front-page-1 is accurate
   ------------------------------------------------------------------------- */

add_filter( 'sidebars_widgets', function ( $sidebars_widgets ) {
	if ( ! empty( $sidebars_widgets['front-page-1'] ) && is_array( $sidebars_widgets['front-page-1'] ) ) {
		$sidebars_widgets['front-page-1'] = array_values(
			array_filter( $sidebars_widgets['front-page-1'] )
		);
	}
	return $sidebars_widgets;
} );

/* -------------------------------------------------------------------------
   Fix 2: Force 3-column float layout for Front Page 1
   Uses the same float + width values as Essence Pro's .widget-thirds rule
   so the rest of the theme's CSS (padding, backgrounds, etc.) still matches.
   ------------------------------------------------------------------------- */

add_action( 'wp_head', function () {
	?>
	<style id="ep-fp1-fix">
		#front-page-1 .flexible-widgets .widget {
			float: left;
			margin-left: 3.5%;
			width: 31%;
		}

		#front-page-1 .flexible-widgets .widget:nth-child(3n+1) {
			clear: left;
			margin-left: 0;
		}

		/* Clearfix so the wrapper expands around the floated widgets */
		#front-page-1 .wrap::after {
			content: "";
			display: table;
			clear: both;
		}

		@media (max-width: 768px) {
			#front-page-1 .flexible-widgets .widget {
				float: none;
				margin-left: 0;
				width: 100%;
			}
		}
	</style>
	<?php
} );
