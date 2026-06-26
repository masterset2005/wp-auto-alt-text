<?php
/**
 * Auto Alt Text Generator
 *
 * @package Auto_Alt_Text
 * @since   1.0.0
 *
 * Plugin Name: Auto Alt Text Generator
 * Plugin URI:  https://github.com/masterset2005/wp-auto-alt-text
 * Description: Fill missing, review and improve, or regenerate alt text across your entire media library. One-click quick-action buttons, WP-Cron background processing, and WP-CLI support. Powered by the WordPress 7.0 AI Client.
 * Version:     1.2.0
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Author:       masterset2005
 * Author URI:   https://github.com/masterset2005
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  auto-alt-text
 * Domain Path:  /languages
 * Update URI:   https://wordpress.org/plugins/auto-alt-text-generator/
 */

defined( 'ABSPATH' ) || exit;

define( 'AUTOALT_VERSION', '1.2.0' );
define( 'AUTOALT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AUTOALT_PLUGIN_DIR . 'includes/class-autoalt-processor.php';
require_once AUTOALT_PLUGIN_DIR . 'includes/class-autoalt-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AUTOALT_PLUGIN_DIR . 'includes/class-autoalt-cli.php';
}

register_activation_hook( __FILE__, array( 'AutoAlt_Processor', 'activation_check' ) );

add_action( 'plugins_loaded', 'autoalt_load_textdomain' );
add_action( 'plugins_loaded', array( 'AutoAlt_Admin', 'init' ) );

/**
 * Load plugin text domain.
 *
 * @return void
 */
function autoalt_load_textdomain() {
	load_plugin_textdomain( 'auto-alt-text', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'AutoAlt_CLI' ) ) {
	WP_CLI::add_command( 'auto-alt', 'AutoAlt_CLI' );
}
