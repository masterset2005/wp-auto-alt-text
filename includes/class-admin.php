<?php

defined( 'ABSPATH' ) || exit;

class AAT_Admin {

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_aat_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	public function add_admin_page() {
		add_media_page(
			__( 'Auto Alt Text', 'auto-alt-text' ),
			__( 'Auto Alt Text', 'auto-alt-text' ),
			'manage_options',
			'auto-alt-text',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'media_page_auto-alt-text' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'aat-admin',
			plugin_dir_url( __DIR__ ) . 'assets/admin.css',
			array(),
			AAT_VERSION
		);

		wp_enqueue_script(
			'aat-admin',
			plugin_dir_url( __DIR__ ) . 'assets/admin.js',
			array( 'jquery' ),
			AAT_VERSION,
			true
		);

		wp_localize_script( 'aat-admin', 'aatData', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'aat_process' ),
			'aiAvailable' => function_exists( 'wp_ai_client_prompt' ),
		) );
	}

	public function render_admin_page() {
		$ai_available = function_exists( 'wp_ai_client_prompt' );
		$processor = AAT_Processor::init();
		$total_missing = $processor->get_total_images( 'missing' );
		$total_poor    = $processor->get_total_images( 'poor' );
		$total_all     = $processor->get_total_images( 'all' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto Alt Text Generator', 'auto-alt-text' ); ?></h1>

			<?php if ( ! $ai_available ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'WordPress 7.0 AI Client is not available. This plugin requires WordPress 7.0+ with a configured AI provider.', 'auto-alt-text' ); ?></p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<div class="aat-status">
				<p>
					<?php esc_html_e( 'Missing alt:', 'auto-alt-text' ); ?>
					<strong><?php echo esc_html( $total_missing ); ?></strong>
					&middot;
					<?php esc_html_e( 'Missing or empty:', 'auto-alt-text' ); ?>
					<strong><?php echo esc_html( $total_poor ); ?></strong>
					&middot;
					<?php esc_html_e( 'Total images:', 'auto-alt-text' ); ?>
					<strong><?php echo esc_html( $total_all ); ?></strong>
				</p>
			</div>

			<div class="aat-controls">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="aat-mode"><?php esc_html_e( 'Processing Mode', 'auto-alt-text' ); ?></label>
						</th>
						<td>
							<select id="aat-mode" class="regular-text">
								<option value="missing">
									<?php esc_html_e( 'Images with missing alt text only', 'auto-alt-text' ); ?>
								</option>
								<option value="poor">
									<?php esc_html_e( 'Images with missing or empty alt text', 'auto-alt-text' ); ?>
								</option>
								<option value="review">
									<?php esc_html_e( 'Review & improve existing alt text (AI evaluates quality)', 'auto-alt-text' ); ?>
								</option>
								<option value="all">
									<?php esc_html_e( 'All images (regenerate all alt text)', 'auto-alt-text' ); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="aat-batch"><?php esc_html_e( 'Batch Size', 'auto-alt-text' ); ?></label>
						</th>
						<td>
							<select id="aat-batch" class="regular-text">
								<option value="1">1</option>
								<option value="3">3</option>
								<option value="5" selected>5</option>
								<option value="10">10</option>
								<option value="20">20</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Images per AJAX request. All images are processed sequentially — this is not a total limit. Lower values are more reliable on slow hosts.', 'auto-alt-text' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p>
					<button id="aat-start" class="button button-primary">
						<?php esc_html_e( 'Start Processing', 'auto-alt-text' ); ?>
					</button>
					<button id="aat-pause" class="button" disabled style="display:none;">
						<?php esc_html_e( 'Pause', 'auto-alt-text' ); ?>
					</button>
					<button id="aat-resume" class="button" disabled style="display:none;">
						<?php esc_html_e( 'Resume', 'auto-alt-text' ); ?>
					</button>
					<button id="aat-cancel" class="button" disabled>
						<?php esc_html_e( 'Cancel', 'auto-alt-text' ); ?>
					</button>
				</p>
			</div>

			<div id="aat-progress" class="aat-progress" style="display:none;">
				<div class="aat-progress-bar-wrapper">
					<div class="aat-progress-bar" style="width:0%;"></div>
				</div>
				<p class="aat-progress-text"><?php esc_html_e( 'Starting...', 'auto-alt-text' ); ?></p>
			</div>

			<div id="aat-log" class="aat-log" style="display:none;">
				<h2><?php esc_html_e( 'Processing Log', 'auto-alt-text' ); ?></h2>
				<div class="aat-log-content"></div>
			</div>
		</div>
		<?php
	}

	public function ajax_process_batch() {
		check_ajax_referer( 'aat_process', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text' ) ) );
		}

		$mode  = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'missing';
		$batch = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$processor = AAT_Processor::init();
		$result = $processor->process_batch( $mode, $offset, $batch );

		$total = $processor->get_total_images( $mode );

		wp_send_json_success( array(
			'batch'   => $result,
			'offset'  => $offset + count( $result['results'] ),
			'total'   => $total,
			'done'    => ! empty( $result['done'] ),
		) );
	}
}
