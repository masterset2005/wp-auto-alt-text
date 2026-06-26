<?php
/**
 * Auto Alt Text Generator Processor
 *
 * @package Auto_Alt_Text
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core processor: image stats, ID queries, AI generation, and prompting.
 */
class AutoAlt_Processor {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton.
	 *
	 * @return self
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Deactivate plugin if WP 7.0 AI Client is missing.
	 *
	 * @return void
	 */
	public static function activation_check() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			deactivate_plugins( plugin_basename( AUTOALT_PLUGIN_DIR . 'wp-auto-alt-text.php' ) );
			wp_die(
				esc_html__( 'Auto Alt Text Generator requires WordPress 7.0 or later.', 'auto-alt-text-generator' )
			);
		}
	}

	/**
	 * Get library stats: total, missing, too-long, too-short alt texts.
	 *
	 * @return array{total: string, missing: string, too_long: string, too_short: string}|null
	 */
	public function get_stats() {
		global $wpdb;

		/** @var \wpdb $wpdb */
		$sql = $wpdb->prepare(
			"
			SELECT
				COUNT(*) AS total,
				SUM( m.meta_value IS NULL OR m.meta_value = '' ) AS missing,
				SUM( m.meta_value IS NOT NULL AND m.meta_value != '' AND CHAR_LENGTH( m.meta_value ) > 125 ) AS too_long,
				SUM( m.meta_value IS NOT NULL AND m.meta_value != '' AND CHAR_LENGTH( m.meta_value ) < 5 ) AS too_short
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt'
			WHERE p.post_type = 'attachment'
			  AND p.post_mime_type LIKE %s
			  AND p.post_mime_type != 'image/svg+xml'
			",
			$wpdb->esc_like( 'image/' ) . '%'
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null === $row ) {
			return array(
				'total'    => '0',
				'missing'  => '0',
				'too_long' => '0',
				'too_short' => '0',
			);
		}
		return $row;
	}

	/**
	 * Query image IDs by mode and pagination.
	 *
	 * @param string $mode       Processing mode: missing|review|regenerate.
	 * @param int    $offset     Query offset.
	 * @param int    $batch_size Number of IDs to fetch.
	 * @return array{ids: int[], total: int}
	 */
	public function get_image_ids( $mode, $offset, $batch_size ) {
		$args = array(
			'post_type'        => 'attachment',
			'post_mime_type'   => 'image',
			'post_status'      => 'any',
			'posts_per_page'   => $batch_size,
			'offset'           => $offset,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => false,
			'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
		);

		if ( 'missing' === $mode ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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

		$query = new WP_Query( $args );
		return array(
			'ids'   => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Process a single attachment: generate alt text via AI.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @return array{id: int, title: string, status: string, previous?: string, generated?: string, changed?: bool, error?: string, reason?: string}
	 */
	public function process_single( $attachment_id ) {
		$context = $this->get_attachment_context( $attachment_id );
		$file    = get_attached_file( $attachment_id );
		$mime    = get_post_mime_type( $attachment_id );
		$title   = get_the_title( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $this->result( $attachment_id, $title, 'error', null, __( 'File not found on server.', 'auto-alt-text-generator' ) );
		}

		if ( ! is_string( $mime ) ) {
			return $this->result( $attachment_id, $title, 'error', null, __( 'Could not determine file type.', 'auto-alt-text-generator' ) );
		}

		if ( ! str_starts_with( $mime, 'image/' ) ) {
			return $this->result( $attachment_id, $title, 'skipped', null, null, __( 'Not an image.', 'auto-alt-text-generator' ) );
		}

		if ( 'image/svg+xml' === $mime ) {
			return $this->result( $attachment_id, $title, 'skipped', null, null, __( 'SVG images are not supported.', 'auto-alt-text-generator' ) );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $this->result( $attachment_id, $title, 'error', null, __( 'AI Client not available.', 'auto-alt-text-generator' ) );
		}

		// Phase 1: Vision LLM (Observation)
		list( $prompt, $system ) = $this->build_prompt();
		$alt_text = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->with_file( $file, $mime )
			->generate_text();

		if ( is_wp_error( $alt_text ) ) {
			return $this->result( $attachment_id, $title, 'error', null, sprintf( __( 'AI generation failed: %s', 'auto-alt-text-generator' ), $alt_text->get_error_message() ) );
		}

		// Phase 2: Synthesizer (Context + Logic)
		$alt_text = $this->compare_alt_texts( $context, $alt_text );

		if ( preg_match( '/\[\[DECORATIVE(?:_ALT)?\]\]/i', $alt_text ) ) {
			$alt_text = '';
		}

		$alt_text = sanitize_text_field( $alt_text );
		$alt_text = preg_replace( '/^["\'\x{2018}\x{2019}\x{201C}\x{201D}]+|["\'\x{2018}\x{2019}\x{201C}\x{201D}]+$/u', '', $alt_text );
		$alt_text = preg_replace( '/^(?:An?|The)\s+(?:image|photo|picture|shot|scene|view)(?:\s+(?:shows?|features?|depicts?|showcases?|displays?|presents?|captures?|of|with|in))?\s+/i', '', $alt_text );
		$alt_text = preg_replace( '/^(?:Informative|Decorative|Functional):\s+/i', '', $alt_text );

		if ( strlen( $alt_text ) > 125 ) {
			$alt_text = substr( $alt_text, 0, 125 );
		}
		
		$changed = $alt_text !== $context['existing_alt'];
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		return array(
			'id'        => $attachment_id,
			'title'     => $title,
			'status'    => 'success',
			'previous'  => $context['existing_alt'],
			'generated' => $alt_text,
			'changed'   => $changed,
			'thumbnail' => $this->thumbnail_url( $attachment_id ),
		);
	}

	/**
	 * Default system prompt following W3C Alt Decision Tree.
	 *
	 * @return string
	 */
	public function default_system_prompt() {
		return 'You are a visual description specialist. Describe only what is visibly present in the image. List subjects, objects, actions, setting, text, and notable details. Do not infer purpose, meaning, emotions, or context. Do not shorten for accessibility or style. Be factual, neutral, and concise.';
	}

	/**
	 * Default prompt for the text-only comparison step (Synthesizer).
	 *
	 * @return string
	 */
	public function default_compare_prompt() {
		return 'You are an accessibility expert generating the final alt text for an image. You receive contextual information, any existing alt text, and a raw visual description.' . "\n\n"
			. 'Follow the W3C Alt Decision Tree in this exact order:' . "\n"
			. '1) Decorative or redundant → output [[DECORATIVE_ALT]] only.' . "\n"
			. '2) Functional → output a short action or destination phrase.' . "\n"
			. '3) Informative → output one concise sentence conveying essential information.' . "\n"
			. '4) Complex → output one short summary sentence capturing the main point.' . "\n\n"
			. 'CORE PRINCIPLE:' . "\n"
			. 'Alt text conveys the information or purpose the image serves in THIS context. If removing the image removes no meaningful information, output [[DECORATIVE_ALT]].' . "\n\n"
			. 'HARD OUTPUT RULES:' . "\n"
			. '- Output exactly ONE sentence under 125 characters.' . "\n"
			. '- Output ONLY the final alt text. No explanations.' . "\n"
			. '- Do NOT wrap in quotes.' . "\n"
			. '- Do NOT include labels like "Informative:" or "Functional:".' . "\n"
			. '- Do NOT start with: "Image of", "Photo of", "Picture of", "An image shows", "The image shows", "This image", or similar.' . "\n"
			. '- Do NOT use "appears", "seems", "suggests", or uncertainty language.' . "\n"
			. '- If you violate ANY rule, output [[DECORATIVE_ALT]].' . "\n\n"
			. 'Your job is to synthesize the context and the visual description to determine the correct alt text category and produce the final output.';
	}

	/**
	 * Gather context for an attachment: caption, post title, excerpt.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{caption: string, title: string, article_title: string, article_excerpt: string, existing_alt: string}
	 */
	private function get_attachment_context( $attachment_id ) {
		$post = get_post( $attachment_id );
		$parent_id = $post ? (int) $post->post_parent : 0;
		$context = array(
			'caption'         => $post ? (string) $post->post_excerpt : '',
			'title'           => $post ? (string) $post->post_title : '',
			'article_title'   => '',
			'article_excerpt' => '',
			'existing_alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);

		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			if ( $parent ) {
				$context['article_title']   = $parent->post_title;
				$context['article_excerpt'] = mb_substr( wp_strip_all_tags( $parent->post_content ), 0, 500 );
			}
		}
		return $context;
	}

	/**
	 * Build prompt and system instruction for the vision model.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function build_prompt() {
		// Optimized for small models: purely visual, no accessibility constraints.
		$system = 'You are a visual description specialist. Describe only what is visibly present in the image. List subjects, objects, actions, setting, text, and notable details. Do not infer purpose, meaning, emotions, or context. Do not shorten for accessibility or style. Be factual, neutral, and concise.';
		$prompt = 'Describe everything visible in this image.';

		return array( $prompt, $system );
	}

	/**
	 * Compare old and new alt text using a text-only AI call (Synthesizer).
	 *
	 * @param array $context  Attachment context (caption, existing_alt, etc).
	 * @param string $new_alt Newly generated alt text.
	 * @return string
	 */
	private function compare_alt_texts( $context, $new_alt ) {
		$custom = get_option( 'autoalt_compare_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_compare_prompt();
		}

		$prompt = "CONTEXT:\n" . print_r( $context, true ) . "\n\n" .
		          "VISUAL DESCRIPTION:\n\"{$new_alt}\"\n\n" .
		          "Generate the final alt text following all system rules.";

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $new_alt;
		}

		return trim( $result );
	}

/**
 * Get the admin thumbnail URL for an attachment.
 *
 * @param int $id Attachment ID.
 * @return string
 */
private function thumbnail_url( $id ) {
		$url = wp_get_attachment_image_url( $id, array( 40, 40 ) );
		return $url ? $url : '';
	}

	/**
	 * Build a result array for process_single().
	 *
	 * @param int         $id        Attachment ID.
	 * @param string      $title     Attachment title.
	 * @param string      $status    success|error|skipped.
	 * @param string|null $generated Generated alt text.
	 * @param string|null $error     Error message.
	 * @param string|null $reason    Skip reason.
	 * @return array
	 */
	private function result( $id, $title, $status, $generated = null, $error = null, $reason = null ) {
		$r = array(
			'id'        => $id,
			'title'     => $title,
			'status'    => $status,
			'thumbnail' => $this->thumbnail_url( $id ),
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
