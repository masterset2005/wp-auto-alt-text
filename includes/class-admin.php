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
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_processing_script' ) );
		add_action( 'admin_notices', array( $this, 'quick_action_notice' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_autoalt_process_single', array( $this, 'ajax_process_single' ) );
		add_action( 'wp_ajax_autoalt_get_ids', array( $this, 'ajax_get_ids' ) );
	}

	public function register_bulk_actions( $actions ) {
		$actions['autoalt_missing']     = __( 'Fill Missing Alt Text', 'auto-alt-text' );
		$actions['autoalt_review']      = __( 'Review & Improve Alt Text', 'auto-alt-text' );
		$actions['autoalt_regenerate']  = __( 'Regenerate Alt Text', 'auto-alt-text' );
		return $actions;
	}

	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'autoalt_missing', 'autoalt_review', 'autoalt_regenerate' ), true ) ) {
			return $redirect;
		}

		$mode_map = array(
			'autoalt_missing'    => 'missing',
			'autoalt_review'     => 'review',
			'autoalt_regenerate' => 'regenerate',
		);

		$redirect = add_query_arg( 'autoalt_action', $mode_map[ $action ], $redirect );
		return $redirect;
	}

	public function quick_action_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		if ( isset( $_GET['autoalt_action'] ) ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		$processor = AutoAlt_Processor::init();

		$missing = $processor->get_image_ids( 'missing', 0, 1 );
		$all     = $processor->get_image_ids( 'review', 0, 1 );

		if ( empty( $missing['total'] ) && empty( $all['total'] ) ) {
			return;
		}

		?>
		<div class="notice notice-info" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;">
			<p style="margin:8px 0;">
				<?php if ( $missing['total'] ) : ?>
					<strong><?php echo esc_html( $missing['total'] ); ?></strong>
					<?php esc_html_e( 'images missing alt text', 'auto-alt-text' ); ?>
					&middot;
				<?php endif; ?>
				<strong><?php echo esc_html( $all['total'] ); ?></strong>
				<?php esc_html_e( 'total images', 'auto-alt-text' ); ?>
			</p>
			<?php if ( $missing['total'] ) : ?>
				<a href="upload.php?autoalt_action=missing" class="button button-primary">
					<?php esc_html_e( 'Fill Missing Alt Text', 'auto-alt-text' ); ?>
				</a>
			<?php endif; ?>
			<a href="upload.php?autoalt_action=review" class="button">
				<?php esc_html_e( 'Review & Improve All', 'auto-alt-text' ); ?>
			</a>
			<a href="upload.php?autoalt_action=regenerate" class="button">
				<?php esc_html_e( 'Regenerate All', 'auto-alt-text' ); ?>
			</a>
		</div>
		<?php
	}

	public function enqueue_processing_script( $hook ) {
		if ( 'upload.php' !== $hook ) {
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
		add_submenu_page(
			'upload.php',
			__( 'Auto Alt Text Settings', 'auto-alt-text' ),
			__( 'Auto Alt Text', 'auto-alt-text' ),
			'manage_options',
			'auto-alt-text',
			array( $this, 'render_settings_page' )
		);
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
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
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
