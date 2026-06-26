<?php

defined( 'ABSPATH' ) || exit;

class AutoAlt_Processor {

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activation_check() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			deactivate_plugins( plugin_basename( AUTOALT_PLUGIN_DIR . 'wp-auto-alt-text.php' ) );
			wp_die(
				esc_html__( 'Auto Alt Text Generator requires WordPress 7.0 or later.', 'auto-alt-text' )
			);
		}
	}

	public function get_image_ids( $mode, $offset, $batch_size ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'any',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
			'suppress_filters' => true,
		);

		if ( 'missing' === $mode ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query( $args );
		return array(
			'ids'   => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	public function process_single( $attachment_id, $mode = 'review' ) {
		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$file        = get_attached_file( $attachment_id );
		$mime        = get_post_mime_type( $attachment_id );
		$title       = get_the_title( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $this->result( $attachment_id, $title, 'error', null, __( 'File not found on server.', 'auto-alt-text' ) );
		}

		if ( ! str_starts_with( $mime, 'image/' ) ) {
			return $this->result( $attachment_id, $title, 'skipped', null, null, __( 'Not an image.', 'auto-alt-text' ) );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $this->result( $attachment_id, $title, 'error', null, __( 'AI Client not available.', 'auto-alt-text' ) );
		}

		if ( 'missing' === $mode && ! empty( $current_alt ) ) {
			return array(
				'id'        => $attachment_id,
				'title'     => $title,
				'status'    => 'skipped',
				'reason'    => __( 'Already has alt text.', 'auto-alt-text' ),
				'previous'  => $current_alt,
				'generated' => $current_alt,
				'changed'   => false,
			);
		}

		list( $prompt, $system ) = $this->build_prompt( $mode, $current_alt );

		$alt_text = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->with_file( $file, $mime )
			->generate_text();

		if ( is_wp_error( $alt_text ) ) {
			return $this->result( $attachment_id, $title, 'error', null, __( 'AI generation failed.', 'auto-alt-text' ) );
		}

		$alt_text = sanitize_text_field( $alt_text );
		if ( strlen( $alt_text ) > 125 ) {
			$alt_text = substr( $alt_text, 0, 125 );
		}
		$changed  = $alt_text !== $current_alt;

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		return array(
			'id'        => $attachment_id,
			'title'     => $title,
			'status'    => 'success',
			'previous'  => $current_alt ?: '',
			'generated' => $alt_text,
			'changed'   => $changed,
		);
	}

	public function default_system_prompt() {
		return 'You are an alt text generator for website images.' . "\n\n"
			. 'Rules:' . "\n"
			. '- Output MUST be under 125 characters.' . "\n"
			. '- Describe only objective visual elements.' . "\n"
			. '- Never use "Image of", "Photo of", or speculative language.' . "\n"
			. '- If the image is decorative, return an empty string.' . "\n\n"
			. 'Examples:' . "\n"
			. 'Good: Smiling baby in a patterned dress pointing at the camera.' . "\n"
			. 'Bad: The image features a beautiful close-up shot of a happy baby that evokes warm feelings.' . "\n"
			. 'Good: Three-tier wedding cake with white frosting and fresh flowers on top.' . "\n"
			. 'Bad: This is a stunning picture of a delicious-looking cake with beautiful floral decorations.' . "\n"
			. 'Good: (empty)' . "\n"
			. 'Bad: A beautiful abstract background pattern with flowing colors.';
	}

	private function build_prompt( $mode, $current_alt ) {
		$custom = get_option( 'autoalt_system_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_system_prompt();
		}

		if ( 'review' === $mode && ! empty( $current_alt ) ) {
			$prompt  = "Review the alt text for this image and improve it if necessary.\n";
			$prompt .= "Current alt text: \"{$current_alt}\"";
		} else {
			$prompt = 'Generate concise, descriptive alt text for this image.';
		}

		return array( $prompt, $system );
	}

	private function result( $id, $title, $status, $generated = null, $error = null, $reason = null ) {
		$r = array(
			'id'    => $id,
			'title' => $title,
			'status' => $status,
		);
		if ( null !== $generated ) {
			$r['generated'] = $generated;
		}
		if ( null !== $error ) {
			$r['error'] = $error;
		}
		if ( null !== $reason ) {
			$r['reason'] = $reason;
		}
		return $r;
	}
}
