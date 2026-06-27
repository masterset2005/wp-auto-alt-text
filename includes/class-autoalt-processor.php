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
	 * Query image IDs by mode, category, and pagination.
	 *
	 * @param string $mode       Processing mode: missing|review|regenerate.
	 * @param int    $cat_id     Category ID.
	 * @param int    $offset     Query offset.
	 * @param int    $batch_size Number of IDs to fetch.
	 * @return array{ids: int[], total: int}
	 */
	public function get_image_ids( $mode, $cat_id, $offset, $batch_size ) {
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
			'suppress_filters' => true,
		);

		if ( ! empty( $cat_id ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => (int) $cat_id,
				),
			);
		}

		if ( 'missing' === $mode ) {
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

		$mode = get_option( 'autoalt_processing_mode', 'two-pass' );

		if ( '1' === get_option( 'autoalt_debug_mode', '0' ) ) {
			error_log( '--- AUTOALT MODE DEBUG --- Processing mode: ' . $mode . ' for attachment #' . $attachment_id );
		}

		if ( 'single-pass' === $mode ) {
			// Single call: vision model receives full instructions + context + image.
			list( $system, $prompt ) = $this->build_single_prompt( $context );
			$alt_text = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->with_file( $file, $mime )
				->generate_text();

			if ( is_wp_error( $alt_text ) ) {
				/* translators: %s: AI provider error message */
				return $this->result( $attachment_id, $title, 'error', null, sprintf( __( 'AI generation failed: %s', 'auto-alt-text-generator' ), $alt_text->get_error_message() ) );
			}
		} else {
			// Two-pass: Vision → Synthesizer.
			list( $prompt, $system ) = $this->build_prompt();
			$alt_text = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->with_file( $file, $mime )
				->generate_text();

			if ( is_wp_error( $alt_text ) ) {
				/* translators: %s: AI provider error message */
				return $this->result( $attachment_id, $title, 'error', null, sprintf( __( 'AI generation failed: %s', 'auto-alt-text-generator' ), $alt_text->get_error_message() ) );
			}

			$alt_text = $this->compare_alt_texts( $context, $alt_text );
		}

		$alt_text = $this->clean_alt_text( $alt_text );

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
	 * Clean raw alt text: decorative check, sanitize, strip labels, truncate.
	 *
	 * @param string $raw Raw alt text from AI.
	 * @return string
	 */
	private function clean_alt_text( $raw ) {
		if ( preg_match( '/\[\[DECORATIVE(?:_ALT)?\]\]/i', $raw ) ) {
			$raw = '';
		}

		$raw = sanitize_text_field( $raw );
		$raw = preg_replace( '/^["\'\x{2018}\x{2019}\x{201C}\x{201D}]+|["\'\x{2018}\x{2019}\x{201C}\x{201D}]+$/u', '', $raw );
		$raw = preg_replace( '/^(?:An?|The)\s+(?:image|photo|picture|shot|scene|view)(?:\s+(?:shows?|features?|depicts?|showcases?|displays?|presents?|captures?|of|with|in))?\s+/i', '', $raw );

		$raw = preg_replace( '/^(?:Informative|Decorative|Functional)(?:\s+alt)?(?::|\s+)?\s*/i', '', $raw );
		$raw = preg_replace( '/^Output:\s+/i', '', $raw );
		$raw = preg_replace( '/\[\[.*?\]\]/s', '', $raw );
		$raw = trim( $raw );

		if ( strlen( $raw ) > 125 ) {
			$raw = substr( $raw, 0, 125 );
		}

		return $raw;
	}

	/**
	 * Default system prompt following W3C Alt Decision Tree.
	 *
	 * @return string
	 */
	public function default_system_prompt() {
		return 'You are a **visual description specialist**. Describe only what is visibly present in the image.' . "\n"
			. '- List subjects, objects, actions, setting, text, and details.' . "\n"
			. '- Do not infer purpose, meaning, emotions, or context.' . "\n"
			. '- Do not shorten for accessibility or style.' . "\n"
			. '- Be factual, neutral, and concise.';
	}

	/**
	 * Default combined prompt for single-pass (high-end models).
	 *
	 * @return string
	 */
	public function default_single_prompt() {
		return 'You are an **accessibility expert** generating alt text for HTML images.' . "\n\n"
			. '**Input:** Context below + attached image' . "\n"
			. '**Output:** One sentence only' . "\n\n"
			. '**W3C Alt Decision Tree (follow in order):**' . "\n\n"
			. '1. **Decorative or redundant?** Image is purely decorative OR the same information is already in adjacent text.' . "\n"
			. '   → `[[DECORATIVE_ALT]]`' . "\n\n"
			. '2. **Functional?** Image is a link, button, control, or the only content of a link.' . "\n"
			. '   → Short text describing the action or destination — not the appearance.' . "\n\n"
			. '3. **Otherwise** → One sentence describing the image, using context when relevant.' . "\n\n"
			. '**Rules:**' . "\n"
			. '- Max **125 characters** — no quotes, no preamble, no explanations' . "\n"
			. '- **Forbidden starts:** `Image of`, `Photo of`, `Picture of`, `An image shows`, `The image features`' . "\n"
			. '- **Forbidden labels:** `Informative:`, `Output:`, `Functional:`, `Alt:`' . "\n"
			. '- Start with a noun phrase' . "\n\n"
			. '**Context:**' . "\n"
			. '**Caption:** {caption}' . "\n"
			. '**Post:** {title}' . "\n"
			. '**Article:** {article_title}' . "\n"
			. '**Excerpt:** {article_excerpt}' . "\n"
			. '**Current alt:** {existing_alt}' . "\n\n"
			. 'Output a single clean string. When uncertain, use `[[DECORATIVE_ALT]]`.';
	}

	/**
	 * Build system + user prompt for single-pass mode.
	 *
	 * @param array $context Attachment context.
	 * @return array{0: string, 1: string}
	 */
	private function build_single_prompt( $context ) {
		$custom = get_option( 'autoalt_single_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_single_prompt();
		}

		$system = str_replace(
			array( '{caption}', '{title}', '{article_title}', '{article_excerpt}', '{existing_alt}', '{visual_desc}' ),
			array( $context['caption'], $context['title'], $context['article_title'], $context['article_excerpt'], $context['existing_alt'], '' ),
			$system
		);

		return array( $system, 'Generate alt text for this image following the system instructions.' );
	}

	/**
	 * Default prompt for the text-only comparison step (Synthesizer).
	 *
	 * @return string
	 */
	public function default_compare_prompt() {
		return 'You are an **AI formatter**.' . "\n\n"
			. '**Input:** Context + Visual Description' . "\n"
			. '**Output:** Final alt text only' . "\n\n"
			. '**Rules:**' . "\n"
			. '- If decorative or redundant → `[[DECORATIVE_ALT]]`' . "\n"
			. '- Otherwise → one sentence describing the image using context' . "\n"
			. '- **Forbidden labels:** `Informative:`, `Output:`, `Functional:`, `Alt:`' . "\n"
			. '- **Forbidden starts:** `Image of`, `Photo of`, `Picture of`, `An image shows`, `The image features`' . "\n"
			. '- Max **125 characters** — no quotes, no preamble, no explanations' . "\n\n"
			. 'Output a single clean string. When uncertain, use `[[DECORATIVE_ALT]]`.';
	}

	/**
	 * Sanitize input value: remove boilerplate, short strings, and noise.
	 *
	 * @param string $value Raw input.
	 * @return string Sanitized value or empty string.
	 */
	private function sanitize_input( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = wp_strip_all_tags( $value );
		$value = trim( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		// Too short to be useful.
		if ( mb_strlen( $value ) < 5 ) {
			return '';
		}

		// Blacklist common garbage values.
		$blacklist = array(
			'img_',
			'dsc_',
			'file_',
			'image',
			'photo',
			'picture',
			'placeholder',
			'untitled',
			'default',
			'no alt',
			'no description',
			'no title',
		);

		$lower = mb_strtolower( $value );
		foreach ( $blacklist as $bad ) {
			if ( 0 === strpos( $lower, $bad ) ) {
				return '';
			}
			// Match whole word (e.g., "image" alone)
			if ( $lower === $bad ) {
				return '';
			}
		}

		return $value;
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
			'caption'         => $this->sanitize_input( $post ? (string) $post->post_excerpt : '' ),
			'title'           => $this->sanitize_input( $post ? (string) $post->post_title : '' ),
			'article_title'   => '',
			'article_excerpt' => '',
			'existing_alt'    => $this->sanitize_input( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
		);

		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			if ( $parent ) {
				$context['article_title']   = $this->sanitize_input( $parent->post_title );
				// Limit excerpt to ~1000 characters to stay safe within 1-3B model context limits
				$context['article_excerpt'] = $this->sanitize_input( mb_substr( wp_strip_all_tags( $parent->post_content ), 0, absint( get_option( 'autoalt_excerpt_limit', 500 ) ) ) );
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
		$system = 'You are a **visual description specialist**.' . "\n"
			. 'Describe only what is visibly present in the image:' . "\n"
			. '- Subjects, objects, actions, setting, text, and details' . "\n"
			. '- Do not infer purpose, meaning, emotions, or context' . "\n"
			. '- Do not shorten for accessibility' . "\n"
			. '- Be factual and concise';
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
			// Replace user placeholders with actual context values
			$system = str_replace(
				array( '{caption}', '{title}', '{article_title}', '{article_excerpt}', '{existing_alt}', '{visual_desc}' ),
				array( $context['caption'], $context['title'], $context['article_title'], $context['article_excerpt'], $context['existing_alt'], $new_alt ),
				$system
			);
		} else {
			$system = $this->default_compare_prompt();
		}

		$prompt = "**Caption:** " . $context['caption'] . "\n" .
		          "**Post:** " . $context['title'] . "\n" .
		          "**Article:** " . $context['article_title'] . "\n" .
		          "**Excerpt:** " . $context['article_excerpt'] . "\n" .
		          "**Current alt:** " . $context['existing_alt'] . "\n\n" .
		          "**Vision:** " . $new_alt;

		if ( '1' === get_option( 'autoalt_debug_mode', '0' ) ) {
			error_log( '--- AUTOALT PROMPT DEBUG ---' );
			error_log( 'SYSTEM: ' . $system );
			error_log( 'PROMPT: ' . $prompt );
		}

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
