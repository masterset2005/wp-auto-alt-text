<?php

defined( 'ABSPATH' ) || exit;

class AutoAlt_Admin {

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_processing_script' ) );
		add_action( 'admin_notices', array( $this, 'quick_action_notice' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_menu', array( $this, 'add_processing_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_autoalt_process_single', array( $this, 'ajax_process_single' ) );
		add_action( 'wp_ajax_autoalt_get_ids', array( $this, 'ajax_get_ids' ) );
		add_action( 'add_attachment', array( $this, 'auto_generate_on_upload' ) );
	}

	public function quick_action_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		$stats = AutoAlt_Processor::init()->get_stats();

		if ( empty( $stats['total'] ) ) {
			return;
		}

		$needs_help = (int) $stats['missing'] + (int) $stats['too_long'] + (int) $stats['too_short'];
		if ( ! $needs_help ) {
			return;
		}

		?>
		<div class="notice notice-info" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;">
			<p style="margin:8px 0;">
				<strong><?php echo esc_html( $stats['missing'] ); ?></strong>
				<?php esc_html_e( 'missing', 'auto-alt-text' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['too_long'] ); ?></strong>
				<?php esc_html_e( 'too long', 'auto-alt-text' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['too_short'] ); ?></strong>
				<?php esc_html_e( 'too short', 'auto-alt-text' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['total'] ); ?></strong>
				<?php esc_html_e( 'total images', 'auto-alt-text' ); ?>
				&middot;
				<a href="admin.php?page=autoalt-processing"><?php esc_html_e( 'Process', 'auto-alt-text' ); ?></a>
			</p>
			<?php if ( (int) $stats['missing'] ) : ?>
				<a href="admin.php?page=autoalt-processing&autoalt_action=missing" class="button button-primary">
					<?php esc_html_e( 'Fill Missing Alt Text', 'auto-alt-text' ); ?>
				</a>
			<?php endif; ?>
			<a href="admin.php?page=autoalt-processing&autoalt_action=review" class="button">
				<?php esc_html_e( 'Review & Improve All', 'auto-alt-text' ); ?>
			</a>
			<a href="admin.php?page=autoalt-processing&autoalt_action=regenerate" class="button">
				<?php esc_html_e( 'Regenerate All', 'auto-alt-text' ); ?>
			</a>
		</div>
		<?php
	}

	public function enqueue_processing_script( $hook ) {
		if ( 'upload.php' === $hook ) {
			$action = isset( $_GET['autoalt_action'] ) ? sanitize_key( $_GET['autoalt_action'] ) : '';
			if ( ! in_array( $action, array( 'missing', 'review', 'regenerate' ), true ) ) {
				return;
			}
		} elseif ( 'media_page_autoalt-processing' !== $hook ) {
			return;
		}

		$action = isset( $_GET['autoalt_action'] ) ? sanitize_key( $_GET['autoalt_action'] ) : '';

		if ( ! in_array( $action, array( 'missing', 'review', 'regenerate' ), true ) ) {
			return;
		}

		$js_ver    = filemtime( AUTOALT_PLUGIN_DIR . 'assets/admin.js' );
		$batch_sz  = absint( get_option( 'autoalt_batch_size', 5 ) );
		if ( $batch_sz < 1 ) {
			$batch_sz = 1;
		}
		if ( $batch_sz > 50 ) {
			$batch_sz = 50;
		}

		wp_enqueue_script(
			'autoalt-bulk',
			plugin_dir_url( __DIR__ ) . 'assets/admin.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		wp_localize_script( 'autoalt-bulk', 'autoaltBulkData', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'autoalt_process' ),
			'mode'      => $action,
			'batchSize' => $batch_sz,
		) );
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Auto Alt Text', 'auto-alt-text' ),
			__( 'Auto Alt Text', 'auto-alt-text' ),
			'manage_options',
			'auto-alt-text',
			array( $this, 'render_settings_page' )
		);
	}

	public function add_processing_page() {
		add_media_page(
			__( 'Auto Alt Text Processing', 'auto-alt-text' ),
			__( 'Auto Alt Text Processing', 'auto-alt-text' ),
			'edit_posts',
			'autoalt-processing',
			array( $this, 'render_processing_page' )
		);
	}

	public function render_processing_page() {
		$action = isset( $_GET['autoalt_action'] ) ? sanitize_key( $_GET['autoalt_action'] ) : '';
		$stats  = AutoAlt_Processor::init()->get_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto Alt Text Processing', 'auto-alt-text' ); ?></h1>

			<div class="notice notice-info" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;">
				<p style="margin:8px 0;">
					<strong><?php echo esc_html( $stats['missing'] ); ?></strong>
					<?php esc_html_e( 'missing', 'auto-alt-text' ); ?>
					&middot;
					<strong><?php echo esc_html( $stats['too_long'] ); ?></strong>
					<?php esc_html_e( 'too long', 'auto-alt-text' ); ?>
					&middot;
					<strong><?php echo esc_html( $stats['too_short'] ); ?></strong>
					<?php esc_html_e( 'too short', 'auto-alt-text' ); ?>
					&middot;
					<strong><?php echo esc_html( $stats['total'] ); ?></strong>
					<?php esc_html_e( 'total images', 'auto-alt-text' ); ?>
				</p>
				<?php if ( (int) $stats['missing'] ) : ?>
					<a href="admin.php?page=autoalt-processing&autoalt_action=missing" class="button button-primary">
						<?php esc_html_e( 'Fill Missing Alt Text', 'auto-alt-text' ); ?>
					</a>
				<?php endif; ?>
				<a href="admin.php?page=autoalt-processing&autoalt_action=review" class="button">
					<?php esc_html_e( 'Review & Improve All', 'auto-alt-text' ); ?>
				</a>
				<a href="admin.php?page=autoalt-processing&autoalt_action=regenerate" class="button">
					<?php esc_html_e( 'Regenerate All', 'auto-alt-text' ); ?>
				</a>
			</div>

			<?php if ( in_array( $action, array( 'missing', 'review', 'regenerate' ), true ) ) : ?>
				<div class="autoalt-processing-log" style="margin-top:16px;">
					<h2 id="autoalt-status" style="margin-bottom:8px;">
						<?php echo esc_html__( 'Processing', 'auto-alt-text' ); ?>&hellip;
					</h2>
					<div id="autoalt-results" style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-height:600px;overflow-y:auto;font-family:monospace;font-size:13px;line-height:1.6;"></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function register_settings() {
		register_setting( 'autoalt_settings', 'autoalt_batch_size', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_batch_size' ),
			'default'           => 5,
		) );

		register_setting( 'autoalt_settings', 'autoalt_system_prompt', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( 'autoalt_settings', 'autoalt_auto_generate', array(
			'type'              => 'boolean',
			'default'           => false,
		) );

		register_setting( 'autoalt_settings', 'autoalt_compare_prompt', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );
	}

	public function sanitize_batch_size( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			$value = 1;
		}
		if ( $value > 50 ) {
			$value = 50;
		}
		return $value;
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto Alt Text Settings', 'auto-alt-text' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'autoalt_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="autoalt_batch_size"><?php esc_html_e( 'Batch Size', 'auto-alt-text' ); ?></label>
						</th>
						<td>
							<select id="autoalt_batch_size" name="autoalt_batch_size">
								<?php
								$current = absint( get_option( 'autoalt_batch_size', 5 ) );
								foreach ( array( 1, 3, 5, 10, 20, 50 ) as $val ) {
									printf(
										'<option value="%d" %s>%d</option>',
										esc_attr( $val ),
										selected( $current, $val, false ),
										esc_html( $val )
									);
								}
								?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Number of image IDs fetched per request. Images are still processed one at a time. Higher values reduce admin-ajax calls on large libraries.', 'auto-alt-text' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="autoalt_system_prompt"><?php esc_html_e( 'System Prompt', 'auto-alt-text' ); ?></label>
						</th>
						<td>
							<textarea id="autoalt_system_prompt" name="autoalt_system_prompt" rows="12" class="large-text code"><?php
								echo esc_textarea( get_option( 'autoalt_system_prompt', '' ) );
							?></textarea>
							<p class="description">
								<?php esc_html_e( 'Override the default system instruction sent to the AI model. Use few-shot examples to improve output quality from smaller models. Leave empty to use the built-in prompt.', 'auto-alt-text' ); ?>
							</p>
							<details style="margin-top:8px;">
								<summary><?php esc_html_e( 'Default prompt (click to expand)', 'auto-alt-text' ); ?></summary>
								<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;"><?php
									echo esc_textarea( AutoAlt_Processor::init()->default_system_prompt() );
								?></pre>
							</details>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="autoalt_compare_prompt"><?php esc_html_e( 'Comparison Prompt', 'auto-alt-text' ); ?></label>
						</th>
						<td>
							<textarea id="autoalt_compare_prompt" name="autoalt_compare_prompt" rows="8" class="large-text code"><?php
								echo esc_textarea( get_option( 'autoalt_compare_prompt', '' ) );
							?></textarea>
							<p class="description">
								<?php esc_html_e( 'System instruction for the text-only comparison step (Review mode). Given old and new alt text, it decides which to keep or combines both. Leave empty to use the built-in default.', 'auto-alt-text' ); ?>
							</p>
							<details style="margin-top:8px;">
								<summary><?php esc_html_e( 'Default comparison prompt (click to expand)', 'auto-alt-text' ); ?></summary>
								<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;"><?php
									echo esc_textarea( AutoAlt_Processor::init()->default_compare_prompt() );
								?></pre>
							</details>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Auto-Generate on Upload', 'auto-alt-text' ); ?>
						</th>
						<td>
							<label for="autoalt_auto_generate">
								<input type="checkbox" id="autoalt_auto_generate" name="autoalt_auto_generate" value="1" <?php checked( get_option( 'autoalt_auto_generate', false ) ); ?>>
								<?php esc_html_e( 'Generate alt text automatically when new images are uploaded', 'auto-alt-text' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Processes each new image with the AI model during upload. May add a delay depending on your AI provider.', 'auto-alt-text' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function auto_generate_on_upload( $attachment_id ) {
		if ( ! get_option( 'autoalt_auto_generate', false ) ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		if ( ! str_starts_with( get_post_mime_type( $attachment_id ), 'image/' ) ) {
			return;
		}

		AutoAlt_Processor::init()->process_single( $attachment_id, 'missing' );
	}

	public function ajax_get_ids() {
		check_ajax_referer( 'autoalt_process', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text' ) ) );
		}

		$mode   = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$processor = AutoAlt_Processor::init();
		$result    = $processor->get_image_ids( $mode, $offset, $batch );

		wp_send_json_success( $result );
	}

	public function ajax_process_single() {
		check_ajax_referer( 'autoalt_process', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text' ) ) );
		}

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'review';

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image ID.', 'auto-alt-text' ) ) );
		}

		$processor = AutoAlt_Processor::init();
		$result    = $processor->process_single( $id, $mode );

		wp_send_json_success( $result );
	}
}
