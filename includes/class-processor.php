<?php

defined( 'ABSPATH' ) || exit;

class AAT_Processor {

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activation_check() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			deactivate_plugins( plugin_basename( AAT_PLUGIN_DIR . 'wp-auto-alt-text.php' ) );
			wp_die(
				esc_html__( 'Auto Alt Text Generator requires WordPress 7.0 or later.', 'auto-alt-text' )
			);
		}
	}

	public function get_total_images( $mode = 'missing' ) {
		$args = $this->build_query_args( $mode );
		$args['posts_per_page'] = 1;
		$args['fields']         = 'ids';
		unset( $args['offset'] );

		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	public function get_image_ids( $mode, $offset, $batch_size ) {
		$args = $this->build_query_args( $mode );
		$args['posts_per_page'] = $batch_size;
		$args['offset']         = $offset;
		$args['fields']         = 'ids';
		$args['no_found_rows']  = true;
		$args['orderby']        = 'ID';
		$args['order']          = 'ASC';

		$query = new WP_Query( $args );
		return $query->posts;
	}

	private function build_query_args( $mode ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'any',
		);

		if ( 'missing' === $mode ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( 'poor' === $mode ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		return $args;
	}

	public function process_batch( $mode, $offset, $batch_size ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array(
				'success' => false,
				'message' => __( 'WordPress 7.0 AI Client not available.', 'auto-alt-text' ),
			);
		}

		$ids = $this->get_image_ids( $mode, $offset, $batch_size );

		if ( empty( $ids ) ) {
			return array(
				'success' => true,
				'done'    => true,
				'results' => array(),
			);
		}

		$results = array();
		foreach ( $ids as $id ) {
			$result = $this->generate_alt_text_for_image( $id );
			$results[] = $result;
		}

		return array(
			'success' => true,
			'done'    => count( $ids ) < $batch_size,
			'results' => $results,
		);
	}

	private function generate_alt_text_for_image( $attachment_id ) {
		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$file = get_attached_file( $attachment_id );
		$mime = get_post_mime_type( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return array(
				'id'     => $attachment_id,
				'title'  => get_the_title( $attachment_id ),
				'status' => 'error',
				'error'  => __( 'File not found on server.', 'auto-alt-text' ),
			);
		}

		if ( ! str_starts_with( $mime, 'image/' ) ) {
			return array(
				'id'     => $attachment_id,
				'title'  => get_the_title( $attachment_id ),
				'status' => 'skipped',
				'reason' => __( 'Not an image.', 'auto-alt-text' ),
			);
		}

		$alt_text = wp_ai_client_prompt( 'Generate concise, descriptive alt text for this image.' )
			->using_system_instruction(
				'You are an accessibility expert generating alt text for website images. '
				. 'Keep it under 125 characters. Describe only what is visible. '
				. 'Do not start with "Image of", "Photo of", or "Picture of". '
				. 'If the image appears to be decorative (pure background, spacer, or empty), '
				. 'return an empty string. Return plain text only.'
			)
			->with_file( $file, $mime )
			->using_model_preference(
				'claude-sonnet-4-6',
				'gpt-5.1',
				'gemini-3.1-pro-preview'
			)
			->generate_text();

		if ( is_wp_error( $alt_text ) ) {
			return array(
				'id'     => $attachment_id,
				'title'  => get_the_title( $attachment_id ),
				'status' => 'error',
				'error'  => $alt_text->get_error_message(),
			);
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		return array(
			'id'          => $attachment_id,
			'title'       => get_the_title( $attachment_id ),
			'status'      => 'success',
			'previous'    => $current_alt ?: '',
			'generated'   => $alt_text,
		);
	}
}
