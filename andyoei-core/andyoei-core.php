<?php
/**
 * Plugin Name: Andy Oei Core
 * Description: Custom post types, taxonomies, fields and directory filters for andyoei.com. Content and IDX are handled outside this plugin.
 * Version:     1.8.6
 * Author:      imFORZA
 * Text Domain: andyoei
 */

defined( 'ABSPATH' ) || exit;

// Also the cache-busting string on the CSS and JS — bump it on every
// release or browsers and caching plugins keep serving the old assets.
define( 'AO_VERSION', '1.8.6' );
define( 'AO_PATH', plugin_dir_path( __FILE__ ) );
define( 'AO_URL', plugin_dir_url( __FILE__ ) );

require_once AO_PATH . 'includes/class-post-types.php';
require_once AO_PATH . 'includes/class-taxonomies.php';
require_once AO_PATH . 'includes/class-index.php';
require_once AO_PATH . 'includes/class-curation.php';
require_once AO_PATH . 'includes/class-fields.php';
require_once AO_PATH . 'includes/class-directories.php';
require_once AO_PATH . 'includes/class-query.php';
require_once AO_PATH . 'includes/class-rest.php';
require_once AO_PATH . 'includes/class-shortcodes.php';
require_once AO_PATH . 'includes/fields-acf.php';

add_action( 'init', array( 'AO_Post_Types', 'register' ), 5 );
add_action( 'init', array( 'AO_Taxonomies', 'register' ), 5 );
add_action( 'init', array( 'AO_Directories', 'register' ), 20 );

AO_Index::init();
AO_Curation::init();
AO_Fields::init();
AO_REST::init();
AO_Shortcodes::init();

/**
 * Activation: seed the controlled vocabularies, then flush rewrites.
 */
register_activation_hook( __FILE__, function () {
	AO_Post_Types::register();
	AO_Taxonomies::register();
	AO_Taxonomies::seed_terms();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * Locate a template: theme override first (andyoei/<file>), then plugin.
 */
function ao_template( $file, $vars = array() ) {
	$path = locate_template( 'andyoei/' . $file );
	if ( ! $path ) {
		$path = AO_PATH . 'templates/' . $file;
	}
	if ( ! file_exists( $path ) ) {
		return '';
	}
	extract( $vars, EXTR_SKIP ); // phpcs:ignore
	ob_start();
	include $path;
	return ob_get_clean();
}
