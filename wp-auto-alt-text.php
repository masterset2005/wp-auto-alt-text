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
 * Version:     1.2.2
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Author:       masterset2005
 * Author URI:   https://github.com/masterset2005
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  auto-alt-text-generator
 * Domain Path:  /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AUTOALT_VERSION', '1.2.2' );
define( 'AUTOALT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AUTOALT_PLUGIN_DIR . 'includes/class-autoalt-processor.php';
require_once AUTOALT_PLUGIN_DIR . 'includes/class-autoalt-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AUTOALT_PLUGIN_DIR . 'includes/class-autoalt-cli.php';
}

register_activation_hook( __FILE__, array( 'AutoAlt_Processor', 'activation_check' ) );

// Upgrade routine: clear stale custom prompts that may contain old instruction formats.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'autoalt_version', '' ) !== AUTOALT_VERSION ) {
		delete_option( 'autoalt_system_prompt' );
		delete_option( 'autoalt_compare_prompt' );
		update_option( 'autoalt_version', AUTOALT_VERSION, false );
	}
}, 0 );

add_action( 'plugins_loaded', array( 'AutoAlt_Admin', 'init' ) );

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'AutoAlt_CLI' ) ) {
	WP_CLI::add_command( 'auto-alt', 'AutoAlt_CLI' );
}
