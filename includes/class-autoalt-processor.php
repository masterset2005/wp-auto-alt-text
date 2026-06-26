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
				esc_html__( 'Auto Alt Text Generator requires WordPress 7.0 or later.', 'auto-alt-text' )
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
			  AND p.post_mime_type LIKE 'image/%'
			  AND p.post_mime_type != 'image/svg+xml'
			"
		);

		$row = $wpdb->get_row( $sql, ARRAY_A );
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
			'suppress_filters' => true,
		);

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
	 * @param string $mode          Processing mode: missing|review|regenerate.
	 * @return array{id: int, title: string, status: string, previous?: string, generated?: string, changed?: bool, error?: string, reason?: string}
	 */
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

		if ( 'image/svg+xml' === $mime ) {
			return $this->result( $attachment_id, $title, 'skipped', null, null, __( 'SVG images are not supported.', 'auto-alt-text' ) );
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
				'thumbnail' => $this->thumbnail_url( $attachment_id ),
			);
		}

		list( $prompt, $system ) = $this->build_prompt();

		$alt_text = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->with_file( $file, $mime )
			->generate_text();

		if ( is_wp_error( $alt_text ) ) {
			$msg = $alt_text->get_error_message();
			return $this->result( $attachment_id, $title, 'error', null, sprintf(
				/* translators: %s: error message from AI provider */
				__( 'AI generation failed: %s', 'auto-alt-text' ),
				$msg
			) );
		}

		if ( preg_match( '/\[\[DECORATIVE(?:_ALT)?\]\]/i', $alt_text ) ) {
			$alt_text = '';
		}

		if ( 'review' === $mode && ! empty( $current_alt ) && '' !== $alt_text ) {
			$alt_text = $this->compare_alt_texts( $current_alt, $alt_text );
		}

		if ( preg_match( '/\[\[DECORATIVE(?:_ALT)?\]\]/i', $alt_text ) ) {
			$alt_text = '';
		}

		$alt_text = sanitize_text_field( $alt_text );

		// Strip surrounding double or single quotes (common model mistake).
		$alt_text = preg_replace( '/^["\']+|["\']+$/', '', $alt_text );

		// Strip leading framing like "An image shows", "The image features", etc.
		$alt_text = preg_replace(
			'/^(?:An?|The)\s+(?:image|photo|picture|shot|scene|view)(?:\s+(?:shows?|features?|depicts?|showcases?|displays?|presents?|captures?|of|with|in))?\s+/i',
			'',
			$alt_text
		);

		if ( strlen( $alt_text ) > 125 ) {
			$alt_text = substr( $alt_text, 0, 125 );
		}
		$changed = $alt_text !== $current_alt;

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		return array(
			'id'        => $attachment_id,
			'title'     => $title,
			'status'    => 'success',
			'previous'  => ! empty( $current_alt ) ? $current_alt : '',
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
			. '- One sentence — a bare description. Never start with "Image of", "Photo of", "Picture of", "An image shows", "The image shows", "The image features", "The image showcases", "The image depicts", "In this image", "This image", or any similar framing. Just describe what is there.';
	}

	/**
	 * Default prompt for the text-only comparison step (Review mode).
	 *
	 * @return string
	 */
	public function default_compare_prompt() {
		return 'You are an accessibility expert comparing two alt text entries for the same image. Your decisions must follow the W3C "An alt Decision Tree" (decorative vs functional vs informative vs complex images).' . "\n\n"
			. 'Core rule: Alt text must convey the information or purpose the image serves. If the image disappeared, what would be lost for someone who cannot see it — that belongs in alt text (or empty alt when nothing should be announced).' . "\n\n"
			. 'Follow this order:' . "\n"
			. '1) Decorative/redundant → output: [[DECORATIVE_ALT]] (empty alt). Do not describe decorative images.' . "\n"
			. '2) Functional → short text describing the action or destination.' . "\n"
			. '3) Informative → convey the information, not every pixel.' . "\n\n"
			. 'OLD (existing alt text): presented for reference.' . "\n"
			. 'NEW (freshly generated from the image): may or may not be correct.' . "\n\n"
			. 'Decide what to keep:' . "\n"
			. '- If OLD follows the W3C rules above and is accurate/descriptive → keep it.' . "\n"
			. '- If NEW is better → use NEW.' . "\n"
			. '- If both have strengths → write a new version combining the best parts.' . "\n"
			. '- If neither is appropriate → write new alt text from scratch following the rules above.' . "\n\n"
			. 'Rules:' . "\n"
			. '- Under 125 characters, one sentence, describe only visible content.' . "\n"
			. '- Do NOT wrap the output in quotes.' . "\n"
			. '- Never start with "Image of", "Photo of", "Picture of", "An image shows", "The image shows", "The image features", "The image showcases", "The image depicts", "In this image", "This image", or any similar framing.' . "\n"
			. '- Avoid "appears", "seems", "suggests".' . "\n"
			. 'Output exactly one line — the final alt text. Nothing else.';
	}

	/**
	 * Build prompt and system instruction for the vision model.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function build_prompt() {
		$custom = get_option( 'autoalt_system_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_system_prompt();
		}

		$prompt = 'Generate concise, descriptive alt text for this image.';

		return array( $prompt, $system );
	}

	/**
	 * Compare old and new alt text using a text-only AI call (Review mode).
	 *
	 * @param string $old     Existing alt text.
	 * @param string $new_alt Newly generated alt text.
	 * @return string
	 */
	private function compare_alt_texts( $old, $new_alt ) {
		$custom = get_option( 'autoalt_compare_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_compare_prompt();
		}

		$prompt = "OLD: \"{$old}\"\nNEW: \"{$new_alt}\"";

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
