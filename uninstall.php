<?php
/**
 * Auto Alt Text Generator Uninstall
 *
 * @package Auto_Alt_Text
 * @since   1.2.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$autoalt_options = array(
	'autoalt_batch_size',
	'autoalt_system_prompt',
	'autoalt_compare_prompt',
	'autoalt_auto_generate',
	'autoalt_show_generated',
	'autoalt_job_status',
	'autoalt_debug_mode',
	'autoalt_processing_mode',
	'autoalt_single_prompt',
	'autoalt_excerpt_limit',
	'autoalt_vision_model',
	'autoalt_text_model',
);

foreach ( $autoalt_options as $autoalt_option ) {
	delete_option( $autoalt_option );
}

delete_metadata( 'user', 0, 'autoalt_last_generated', '', true );

wp_clear_scheduled_hook( 'autoalt_process_batch' );
