<?php
/**
 * Plugin Name: Auto Alt Text Generator
 * Plugin URI:  https://github.com/masterset2005/wp-auto-alt-text
 * Description: Enhances the WordPress Media Library with "Review & Improve" and "Regenerate" bulk actions for AI-powered alt text using the WP 7.0 AI Client.
 * Version:     1.0.1
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Author:       masterset2005
 * Author URI:   https://github.com/masterset2005
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  auto-alt-text
 * Domain Path:  /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AUTOALT_VERSION', '1.0.1' );
define( 'AUTOALT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AUTOALT_PLUGIN_DIR . 'includes/class-processor.php';
require_once AUTOALT_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'AutoAlt_Processor', 'activation_check' ) );

add_action( 'plugins_loaded', 'autoalt_load_textdomain' );
add_action( 'plugins_loaded', array( 'AutoAlt_Admin', 'init' ) );

function autoalt_load_textdomain() {
	load_plugin_textdomain( 'auto-alt-text', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
