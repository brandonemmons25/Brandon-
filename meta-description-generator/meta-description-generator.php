<?php
/**
 * Plugin Name: Meta Description Generator
 * Plugin URI:  https://github.com/brandonemmons25/brandon-
 * Description: Scans all pages and posts for missing Yoast SEO meta descriptions, generates them with the OpenAI API, lets you review and edit inline, then applies them directly to Yoast — no copy/paste required.
 * Version:     2.0.0
 * Author:      Brandon Emmons
 * License:     GPL-2.0+
 * Text Domain: meta-description-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'MDG_VERSION',     '2.0.0' );
define( 'MDG_PLUGIN_FILE', __FILE__ );
define( 'MDG_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MDG_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// OpenAI model to use for generation.
define( 'MDG_AI_MODEL', 'gpt-4o-mini' );

// Ideal meta description length range (120–158 chars).
// Critical content should sit within the first 120 — mobile truncates sooner.
define( 'MDG_META_MIN', 120 );
define( 'MDG_META_MAX', 158 );

require_once MDG_PLUGIN_DIR . 'includes/class-mdg-scanner.php';
require_once MDG_PLUGIN_DIR . 'includes/class-mdg-generator.php';
require_once MDG_PLUGIN_DIR . 'includes/class-mdg-admin.php';

add_action( 'plugins_loaded', [ 'MDG_Admin', 'init' ] );
