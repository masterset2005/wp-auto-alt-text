<?php
/**
 * Plugin Name: Auto Alt Text Generator
 * Plugin URI:  https://example.com/auto-alt-text
 * Description: Uses the WordPress 7.0 AI Client to automatically generate and improve alt text for all images in the media library.
 * Version:     1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author:      Your Name
 * License:     GPL v2 or later
 * Text Domain: auto-alt-text
 */

defined( 'ABSPATH' ) || exit;

define( 'AAT_VERSION', '1.0.0' );
define( 'AAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AAT_PLUGIN_DIR . 'includes/class-processor.php';
require_once AAT_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'AAT_Processor', 'activation_check' ) );

add_action( 'plugins_loaded', array( 'AAT_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'AAT_Processor', 'init' ) );
