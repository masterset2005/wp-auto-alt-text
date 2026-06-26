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

	public function get_stats() {
		global $wpdb;

		return $wpdb->get_row( "
			SELECT
				COUNT(*) AS total,
				SUM( m.meta_value IS NULL OR m.meta_value = '' ) AS missing,
				SUM( m.meta_value IS NOT NULL AND m.meta_value != '' AND CHAR_LENGTH( m.meta_value ) > 125 ) AS too_long,
				SUM( m.meta_value IS NOT NULL AND m.meta_value != '' AND CHAR_LENGTH( m.meta_value ) < 5 ) AS too_short
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt'
			WHERE p.post_type = 'attachment'
			  AND p.post_mime_type LIKE 'image/%'
		", ARRAY_A );
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

		if ( preg_match( '/\[\[DECORATIVE(?:_ALT)?\]\]/i', $alt_text ) ) {
			$alt_text = '';
		}

		if ( 'review' === $mode && ! empty( $current_alt ) && '' !== $alt_text ) {
			$alt_text = $this->compare_alt_texts( $current_alt, $alt_text );
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
		return 'You are an accessibility expert that proposes alternative (alt) text for HTML images. Your output must follow the same decisions authors make with the W3C "An alt Decision Tree" (decorative vs functional vs informative vs complex images).' . "\n\n"
			. 'Core rule: Alt text is not always a description of what the picture looks like. It must convey the information or purpose that the image serves in this specific context. If the image disappeared, what would be lost for someone who cannot see it—that is what belongs in alt text (or in empty alt when nothing should be announced).' . "\n\n"
			. 'Follow this order:' . "\n\n"
			. '1) Decorative or redundant?' . "\n"
			. '- Purely decorative (flourish, spacer, visual-only styling) OR the same information is already in adjacent text.' . "\n"
			. '- Output exactly: [[DECORATIVE_ALT]]' . "\n"
			. '- Do not describe the image for decorative/redundant cases.' . "\n\n"
			. '2) Functional (image is a control or the main content of a link or button)?' . "\n"
			. '- Examples: linked image with no other text in the link; icon-only button; logo linking home.' . "\n"
			. '- Output: short text describing the action or destination, not the image.' . "\n\n"
			. '3) Informative:' . "\n"
			. '- Convey the information the image presents. Keep under 125 characters.' . "\n"
			. '- Describe the purpose, not every pixel.' . "\n"
			. '- One sentence. Never start with "Image of", "Photo of", "Picture of".';
	}

	public function default_compare_prompt() {
		return 'You are an alt text quality checker comparing two alt text entries for the same image.' . "\n\n"
			. 'OLD: presented for reference.' . "\n"
			. 'NEW: generated fresh from the image.' . "\n\n"
			. 'Decide what to keep:' . "\n"
			. '- If OLD is accurate, descriptive, and under 125 chars → keep it.' . "\n"
			. '- If NEW is better → use NEW.' . "\n"
			. '- If both have strengths → write a new version combining the best parts.' . "\n\n"
			. 'Rules: under 125 characters, one sentence, describe only visible content. Avoid "appears", "seems", "suggests".' . "\n"
			. 'Output exactly one line — the final alt text. Nothing else.';
	}

	private function build_prompt( $mode, $current_alt ) {
		$custom = get_option( 'autoalt_system_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_system_prompt();
		}

		$prompt = 'Generate concise, descriptive alt text for this image.';

		return array( $prompt, $system );
	}

	private function compare_alt_texts( $old, $new ) {
		$custom = get_option( 'autoalt_compare_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_compare_prompt();
		}

		$prompt = "OLD: \"{$old}\"\nNEW: \"{$new}\"";

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $new;
		}

		return trim( $result );
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
