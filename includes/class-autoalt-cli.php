<?php
/**
 * Auto Alt Text Generator WP-CLI Commands
 *
 * @package Auto_Alt_Text
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Process alt text for the entire media library from the command line.
 *
 * ## EXAMPLE
 *
 *     # Fill missing alt text on all images.
 *     wp auto-alt process --mode=missing
 *
 *     # Review and improve all existing alt text.
 *     wp auto-alt process --mode=review
 *
 *     # Regenerate all alt text from scratch.
 *     wp auto-alt process --mode=regenerate
 *
 * @package Auto_Alt_Text
 */
class AutoAlt_CLI extends WP_CLI_Command {

	/**
	 * Process the media library with AI alt text generation.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : Processing mode. One of: missing, review, regenerate.
	 * ---
	 * default: missing
	 * options:
	 *   - missing
	 *   - review
	 *   - regenerate
	 * ---
	 *
	 * [--batch=<n>]
	 * : Images to process per batch (memory control).
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp auto-alt process --mode=missing
	 *     wp auto-alt process --mode=review --batch=20
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function process( $args, $assoc_args ) {
		$mode  = $assoc_args['mode'] ?? 'missing';
		$batch = (int) ( $assoc_args['batch'] ?? 10 );

		if ( ! in_array( $mode, array( 'missing', 'review', 'regenerate' ), true ) ) {
			WP_CLI::error( "Invalid mode: {$mode}. Use missing, review, or regenerate." );
		}

		if ( $batch < 1 ) {
			$batch = 1;
		}

		$processor = AutoAlt_Processor::init();

		// Check for AI Client availability.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			WP_CLI::error( 'WordPress 7.0 AI Client (wp_ai_client_prompt) is not available.' );
		}

		// Get total count.
		$stats = $processor->get_stats();
		$total = (int) $stats['total'];

		if ( ! $total ) {
			WP_CLI::warning( 'No images found in the media library.' );
			return;
		}

		$offset     = 0;
		$processed  = 0;
		$failed     = 0;
		$start_time = microtime( true );

		WP_CLI::line( "Mode: {$mode}" );
		WP_CLI::line( "Total images: {$total}" );
		WP_CLI::line( '' );

		/* translators: 1: batch size */
		WP_CLI::log( sprintf( __( 'Processing in batches of %d...', 'auto-alt-text-generator' ), $batch ) );
		WP_CLI::line( '' );

		$progress = WP_CLI\Utils\make_progress_bar(
			sprintf( 'Processing (%s)', $mode ),
			$total
		);

		while ( true ) {
			$result = $processor->get_image_ids( $mode, $offset, $batch );

			if ( empty( $result['ids'] ) ) {
				break;
			}

			foreach ( $result['ids'] as $id ) {
				$single = $processor->process_single( $id );

				if ( 'error' === $single['status'] ) {
					++$failed;
					WP_CLI::warning( "ID {$id}: {$single['error']}" );
				} elseif ( 'skipped' === $single['status'] && isset( $single['reason'] ) ) {
					WP_CLI::debug( "ID {$id}: skipped ({$single['reason']})" );
				} elseif ( 'success' === $single['status'] ) {
					WP_CLI::debug( "ID {$id}: {$single['generated']}" );
				}

				++$processed;
				$progress->tick();
			}

			$offset += $batch;

			// Avoid memory exhaustion on very large libraries.
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}

		$progress->finish();

		$elapsed = round( microtime( true ) - $start_time, 2 );

		WP_CLI::success(
			sprintf(
				/* translators: 1: processed count, 2: failed count, 3: elapsed seconds */
				__( 'Done — %1$d processed, %2$d failed in %3$ds', 'auto-alt-text-generator' ),
				$processed,
				$failed,
				$elapsed
			)
		);
	}
}
