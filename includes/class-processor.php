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
		return 'You are an accessibility expert generating alt text for website images following the W3C Alt Decision Tree.' . "\n\n"
			. 'Core principle: Alt text must convey the information or purpose the image serves. If the image disappeared, what would be lost for someone who cannot see it?' . "\n\n"
			. 'Decision order:' . "\n"
			. '1. Is it decorative or redundant? (spacer, flourish, decorative border, or information already in adjacent text) → return exactly this: [[DECORATIVE]]' . "\n"
			. '2. Is it functional? (icon button, linked logo, image-as-link with no other link text) → describe the action or destination, not the image.' . "\n"
			. '3. Informative: convey the information the image presents. Keep it under 125 characters.' . "\n\n"
			. 'Never start with "Image of", "Photo of", "Picture of". One sentence. Describe the purpose, not just pixels.';
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
		$prompt = "Compare these two alt text entries for the same image.\n\n"
			. "OLD: \"{$old}\"\n"
			. "NEW: \"{$new}\"\n\n"
			. "Pick the better one, or write a new one combining the best of both.\n"
			. "Return ONLY the final alt text — nothing else.";

		$system  = 'You are an alt text quality checker. ';
		$system .= 'Keep it under 125 characters. ';
		$system .= 'One sentence. Describe only visible objects. ';
		$system .= 'Avoid "appears", "seems", "suggests". ';
		$system .= 'Output exactly one line — the final alt text.';

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
