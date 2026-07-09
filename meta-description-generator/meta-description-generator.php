<?php
/**
 * Plugin Name: Meta Description Generator
 * Plugin URI:  https://github.com/brandonemmons25/brandon-
 * Description: On activation, automatically fills any post or page missing a Yoast focus keyphrase, SEO title, or meta description using the Claude AI API. Only empty fields are written — existing values are never overwritten.
 * Version:     2.4.0
 * Author:      Brandon Emmons
 * License:     GPL-2.0+
 * Text Domain: meta-description-generator
 */

defined( 'ABSPATH' ) || exit;

define( 'MDG_VERSION',     '2.4.0' );
define( 'MDG_PLUGIN_FILE', __FILE__ );
define( 'MDG_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MDG_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

define( 'MDG_CLAUDE_MODEL', 'claude-haiku-4-5-20251001' );

// SEO field character targets.
define( 'MDG_TITLE_MIN', 50  );
define( 'MDG_TITLE_MAX', 60  );
define( 'MDG_META_MIN',  140 );
define( 'MDG_META_MAX',  160 );

require_once MDG_PLUGIN_DIR . 'includes/class-mdg-scanner.php';
require_once MDG_PLUGIN_DIR . 'includes/class-mdg-generator.php';
require_once MDG_PLUGIN_DIR . 'includes/class-mdg-admin.php';

register_activation_hook( __FILE__, [ 'MDG_Admin', 'on_activation' ] );

add_action( 'plugins_loaded', [ 'MDG_Admin', 'init' ] );
