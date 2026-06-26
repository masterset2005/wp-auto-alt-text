<?php
/**
 * Auto Alt Text Generator Admin
 *
 * @package Auto_Alt_Text
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin hooks: settings, processing page, AJAX, and auto-generate on upload.
 */
class AutoAlt_Admin {

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
	 * Hook into WordPress admin.
	 */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_processing_script' ) );
		add_action( 'admin_notices', array( $this, 'quick_action_notice' ) );
		add_action( 'admin_notices', array( $this, 'generated_notice' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'mark_auto_generated' ), 10, 2 );
		add_action( 'admin_footer-upload.php', array( $this, 'generated_script' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_menu', array( $this, 'add_processing_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_autoalt_process_single', array( $this, 'ajax_process_single' ) );

	/** Responsive form table max-height fix */
	<style id="autoalt-responsive-form"></style>
	<script>(document).ready(function(){$('.wrap .form-table td[scope=row]').css({maxHeight:'50vh'}).find('th,td').each(function(){$(this).attr('data-rowscope'?)$(this).css(maxHeight:48vh):void 0;});$('.description').css(width:'100%');});</script>
		add_action( 'wp_ajax_autoalt_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_autoalt_get_ids', array( $this, 'ajax_get_ids' ) );
		add_action( 'wp_ajax_autoalt_create_job', array( $this, 'ajax_create_job' ) );
		add_action( 'wp_ajax_autoalt_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_autoalt_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'autoalt_process_batch', array( $this, 'process_background_batch' ) );
		add_action( 'add_attachment', array( $this, 'auto_generate_on_upload' ) );
	}

	/**
	 * Show quick-action notice bar on Media Library page.
	 *
	 * @return void
	 */
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
				<?php esc_html_e( 'missing', 'auto-alt-text-generator' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['too_long'] ); ?></strong>
				<?php esc_html_e( 'too long', 'auto-alt-text-generator' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['too_short'] ); ?></strong>
				<?php esc_html_e( 'too short', 'auto-alt-text-generator' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['total'] ); ?></strong>
				<?php esc_html_e( 'total images', 'auto-alt-text-generator' ); ?>
				&middot;
				<a href="admin.php?page=autoalt-processing"><?php esc_html_e( 'Process', 'auto-alt-text-generator' ); ?></a>
			</p>
			<?php if ( (int) $stats['missing'] ) : ?>
				<a href="admin.php?page=autoalt-processing&autoalt_action=missing" class="button button-primary">
					<?php esc_html_e( 'Fill Missing Alt Text', 'auto-alt-text-generator' ); ?>
				</a>
			<?php endif; ?>
			<a href="admin.php?page=autoalt-processing&autoalt_action=review" class="button">
				<?php esc_html_e( 'Review & Improve All', 'auto-alt-text-generator' ); ?>
			</a>
			<a href="admin.php?page=autoalt-processing&autoalt_action=regenerate" class="button">
				<?php esc_html_e( 'Regenerate All', 'auto-alt-text-generator' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Enqueue JS for the processing page or inline processing on upload.php.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_processing_script( $hook ) {
		if ( 'upload.php' !== $hook && 'media_page_autoalt-processing' !== $hook ) {
			return;
		}

		$action = isset( $_GET['autoalt_action'] ) ? sanitize_key( wp_unslash( $_GET['autoalt_action'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $action, array( 'missing', 'review', 'regenerate' ), true ) ) {
			return;
		}

		$js_ver   = filemtime( AUTOALT_PLUGIN_DIR . 'assets/admin.js' );
		$batch_sz = absint( get_option( 'autoalt_batch_size', 5 ) );
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

		wp_localize_script(
			'autoalt-bulk',
			'autoaltBulkData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'autoalt_process' ),
				'mode'      => $action,
				'batchSize' => $batch_sz,
			)
		);
	}

	/**
	 * Register the Settings > Auto Alt Text page.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Auto Alt Text', 'auto-alt-text-generator' ),
			__( 'Auto Alt Text', 'auto-alt-text-generator' ),
			'manage_options',
			'auto-alt-text',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the Media > Auto Alt Text Processing page.
	 *
	 * @return void
	 */
	public function add_processing_page() {
		add_media_page(
			__( 'Auto Alt Text Processing', 'auto-alt-text-generator' ),
			__( 'Auto Alt Text Processing', 'auto-alt-text-generator' ),
			'edit_posts',
			'autoalt-processing',
			array( $this, 'render_processing_page' )
		);
	}

	/**
	 * Render the processing page.
	 *
	 * @return void
	 */
	public function render_processing_page() {
		$action = isset( $_GET['autoalt_action'] ) ? sanitize_key( wp_unslash( $_GET['autoalt_action'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stats  = AutoAlt_Processor::init()->get_stats();
		/** @var array{is_running: bool, completed: bool, mode: string, processed: int, total: int, failed: int}|false $job */
		$job    = get_option( 'autoalt_job_status', false );
		?>
			<div class="wrap" style="max-width: 1000px; box-sizing: border-box;">
			<h1><?php esc_html_e( 'Auto Alt Text Processing', 'auto-alt-text-generator' ); ?></h1>


			<div class="notice notice-info" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;">
				<p style="margin:8px 0;">
					<strong><?php echo esc_html( $stats['missing'] ); ?></strong>
					<?php esc_html_e( 'missing', 'auto-alt-text-generator' ); ?>
					&middot;
					<strong><?php echo esc_html( $stats['too_long'] ); ?></strong>
					<?php esc_html_e( 'too long', 'auto-alt-text-generator' ); ?>
					&middot;
					<strong><?php echo esc_html( $stats['too_short'] ); ?></strong>
					<?php esc_html_e( 'too short', 'auto-alt-text-generator' ); ?>
					&middot;
					<strong><?php echo esc_html( $stats['total'] ); ?></strong>
					<?php esc_html_e( 'total images', 'auto-alt-text-generator' ); ?>
					&middot;
					<select id="autoalt-cat-filter" style="margin-left:8px;">
						<option value="0"><?php esc_html_e( 'All Categories', 'auto-alt-text-generator' ); ?></option>
						<?php
						$categories = get_categories();
						foreach ( $categories as $cat ) {
							printf( '<option value="%d">%s</option>', esc_attr( $cat->term_id ), esc_html( $cat->name ) );
						}
						?>
					</select>
				</p>
				<?php if ( (int) $stats['missing'] ) : ?>
					<a href="admin.php?page=autoalt-processing&autoalt_action=missing" class="button button-primary">
						<?php esc_html_e( 'Fill Missing Alt Text', 'auto-alt-text-generator' ); ?>
					</a>
				<?php endif; ?>
				<a href="admin.php?page=autoalt-processing&autoalt_action=review" class="button">
					<?php esc_html_e( 'Review & Improve All', 'auto-alt-text-generator' ); ?>
				</a>
				<a href="admin.php?page=autoalt-processing&autoalt_action=regenerate" class="button">
					<?php esc_html_e( 'Regenerate All', 'auto-alt-text-generator' ); ?>
				</a>
			</div>

			<?php if ( $job && $job['is_running'] ) : ?>
				<div class="notice notice-info" style="margin-top:12px;">
					<p>
						<strong><?php esc_html_e( 'Background job active:', 'auto-alt-text-generator' ); ?></strong>
						<?php
						printf(
							/* translators: 1: mode label, 2: processed count, 3: total count, 4: failed count */
							esc_html__( '%1$s — %2$d / %3$d processed, %4$d failed', 'auto-alt-text-generator' ),
							esc_html( $job['mode'] ),
							(int) $job['processed'],
							(int) $job['total'],
							(int) $job['failed']
						);
						?>
						<button id="autoalt-cancel-job" class="button" style="margin-left:12px;">
							<?php esc_html_e( 'Cancel', 'auto-alt-text-generator' ); ?>
						</button>
					</p>
					<progress value="<?php echo (int) $job['processed']; ?>" max="<?php echo (int) $job['total']; ?>" style="width:100%;height:20px;"></progress>
				</div>
			<?php elseif ( $job && $job['completed'] ) : ?>
				<div class="notice notice-success" style="margin-top:12px;">
					<p>
						<strong><?php esc_html_e( 'Previous job completed:', 'auto-alt-text-generator' ); ?></strong>
						<?php
						printf(
							/* translators: 1: mode label, 2: processed count, 3: total count, 4: failed count */
							esc_html__( '%1$s — %2$d / %3$d processed, %4$d failed', 'auto-alt-text-generator' ),
							esc_html( $job['mode'] ),
							(int) $job['processed'],
							(int) $job['total'],
							(int) $job['failed']
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="autoalt-background-actions" style="margin-top:16px;padding:12px;background:#f0f0f1;border:1px solid #c3c4c7;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Process in Background', 'auto-alt-text-generator' ); ?></h2>
				<p><?php esc_html_e( 'Run processing via WP-Cron — close the browser and come back later. Progress is tracked above.', 'auto-alt-text-generator' ); ?></p>
				<p>
					<?php if ( (int) $stats['missing'] ) : ?>
						<button class="button button-primary autoalt-bg-btn" data-mode="missing">
							<?php esc_html_e( 'Fill Missing (Background)', 'auto-alt-text-generator' ); ?>
						</button>
					<?php endif; ?>
					<button class="button autoalt-bg-btn" data-mode="review">
						<?php esc_html_e( 'Review & Improve (Background)', 'auto-alt-text-generator' ); ?>
					</button>
					<button class="button autoalt-bg-btn" data-mode="regenerate">
						<?php esc_html_e( 'Regenerate All (Background)', 'auto-alt-text-generator' ); ?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Or use WP-CLI for the fastest processing:', 'auto-alt-text-generator' ); ?>
					<code>wp auto-alt process --mode=missing</code>
				</p>
			</div>

			<?php if ( in_array( $action, array( 'missing', 'review', 'regenerate' ), true ) ) : ?>
				<div class="autoalt-processing-log" style="margin-top:16px;">
					<h2 id="autoalt-status" style="margin-bottom:8px;display:inline-block;">
						<?php esc_html_e( 'Processing', 'auto-alt-text-generator' ); ?>&hellip;
					</h2>
					<a href="#" id="autoalt-stop-link" class="autoalt-stop-link" style="display:inline-block;margin-left:12px;color:#d63638;vertical-align:middle;">
						<?php esc_html_e( 'stop', 'auto-alt-text-generator' ); ?>
					</a>
					<div id="autoalt-results" style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-height:600px;overflow-y:auto;font-family:monospace;font-size:13px;line-height:1.6;"></div>
				</div>
			<?php endif; ?>
		</div>

		<script type="text/javascript">
		jQuery(function($) {
			$('.autoalt-bg-btn').on('click', function() {
				var mode = $(this).data('mode');
				if ( ! confirm( '<?php echo esc_js( __( 'Start background processing? You can close the browser and check back later.', 'auto-alt-text-generator' ) ); ?>' ) ) {
					return;
				}
				$.post(ajaxurl, {
					action: 'autoalt_create_job',
					mode: mode,
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'autoalt_create_job' ) ); ?>'
				}, function(resp) {
					if ( resp.success ) {
						location.reload();
					} else {
						alert( resp.data.message || '<?php echo esc_js( __( 'Failed to start job.', 'auto-alt-text-generator' ) ); ?>' );
					}
				});
			});

			$(document).on('click', '#autoalt-cancel-job', function() {
				if ( ! confirm( '<?php echo esc_js( __( 'Cancel the current background job?', 'auto-alt-text-generator' ) ); ?>' ) ) {
					return;
				}
				$.post(ajaxurl, {
					action: 'autoalt_cancel_job',
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'autoalt_cancel_job' ) ); ?>'
				}, function(resp) {
					if ( resp.success ) {
						location.reload();
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'autoalt_settings',
			'autoalt_batch_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_batch_size' ),
				'default'           => 5,
			)
		);

		register_setting(
			'autoalt_settings',
			'autoalt_system_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'autoalt_settings',
			'autoalt_auto_generate',
			array(
				'type'    => 'boolean',
				'default' => false,
			)
		);

		register_setting(
			'autoalt_settings',
			'autoalt_show_generated',
			array(
				'type'    => 'boolean',
				'default' => false,
			)
		);

		register_setting(
			'autoalt_settings',
			'autoalt_compare_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Sanitize batch size (clamp 1–50).
	 *
	 * @param mixed $value Raw input.
	 * @return int
	 */
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

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto Alt Text Settings', 'auto-alt-text-generator' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'autoalt_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="autoalt_debug_mode"><?php esc_html_e( 'Enable Debug Mode', 'auto-alt-text-generator' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="autoalt_debug_mode" name="autoalt_debug_mode" value="1" <?php checked( get_option( 'autoalt_debug_mode', '0' ), '1' ); ?>>
							<label for="autoalt_debug_mode"><?php esc_html_e( 'Show AI reasoning in processing logs.', 'auto-alt-text-generator' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="autoalt_batch_size"><?php esc_html_e( 'Batch Size', 'auto-alt-text-generator' ); ?></label>
						</th>
						<td>
							<select id="autoalt_batch_size" name="autoalt_batch_size">
								<?php
								$current = absint( get_option( 'autoalt_batch_size', 5 ) );
								foreach ( array( 1, 3, 5, 10, 20, 50 ) as $val ) {
									printf(
										'<option value="%d" %s>%d</option>',
										esc_attr( (string) $val ),
										selected( $current, $val, false ),
										esc_html( (string) $val )
									);
								}
								?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Number of image IDs fetched per request. Images are still processed one at a time. Higher values reduce admin-ajax calls on large libraries.', 'auto-alt-text-generator' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="autoalt_system_prompt"><?php esc_html_e( 'System Prompt', 'auto-alt-text-generator' ); ?></label>
						</th>
						<td>
							<textarea id="autoalt_system_prompt" name="autoalt_system_prompt" rows="12" class="large-text code">
							<?php
								echo esc_textarea( get_option( 'autoalt_system_prompt', '' ) );
							?>
							</textarea>
							<p class="description">
								<?php esc_html_e( 'Override the default system instruction sent to the AI model. Use few-shot examples to improve output quality from smaller models. Leave empty to use the built-in prompt.', 'auto-alt-text-generator' ); ?>
							</p>
							<details style="margin-top:8px;">
								<details style="margin-top:8px;">
								<summary><?php esc_html_e( 'Available variables for System Prompt', 'auto-alt-text-generator' ); ?></summary>
								<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
Available context variables for your custom prompt:
{caption}         - Image caption (post_excerpt)
{title}           - Image title (post_title)
{article_title}   - Parent post title
{article_excerpt} - Parent post excerpt (first 1000 chars)
{existing_alt}    - Current alt text in database
{visual_desc}     - Raw output from Vision model

Usage: Just include these placeholders in your prompt text.
Example: "The image is about {article_title}. Visual: {visual_desc}"
								</pre>
							</details>
							<details style="margin-top:8px;">
								<summary><?php esc_html_e( 'Default prompt (click to expand)', 'auto-alt-text-generator' ); ?></summary>
								<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;">
								<?php
									echo esc_textarea( AutoAlt_Processor::init()->default_system_prompt() );
								?>
								</pre>
							</details>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="autoalt_compare_prompt"><?php esc_html_e( 'Comparison Prompt', 'auto-alt-text-generator' ); ?></label>
						</th>
						<td>
							<textarea id="autoalt_compare_prompt" name="autoalt_compare_prompt" rows="8" class="large-text code">
							<?php
								echo esc_textarea( get_option( 'autoalt_compare_prompt', '' ) );
							?>
							</textarea>
							<p class="description">
								<?php esc_html_e( 'System instruction for the text-only comparison step (Review mode). Given old and new alt text, it decides which to keep or combines both. Leave empty to use the built-in default.', 'auto-alt-text-generator' ); ?>
							</p>
							<details style="margin-top:8px;">
								<summary><?php esc_html_e( 'Available variables for Comparison Prompt', 'auto-alt-text-generator' ); ?></summary>
								<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
Available context variables for your custom prompt:
{caption}         - Image caption (post_excerpt)
{title}           - Image title (post_title)
{article_title}   - Parent post title
{article_excerpt} - Parent post excerpt (first 1000 chars)
{existing_alt}    - Current alt text in database
{visual_desc}     - Raw output from Vision model

Usage: Include these placeholders in your prompt text.
Example: "Context: {article_title}. Visual: {visual_desc}"
								</pre>
							</details>
							<details style="margin-top:8px;">
								<summary><?php esc_html_e( 'Default comparison prompt (click to expand)', 'auto-alt-text-generator' ); ?></summary>
								<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;">
								<?php
									echo esc_textarea( AutoAlt_Processor::init()->default_compare_prompt() );
								?>
								</pre>
							</details>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Auto-Generate on Upload', 'auto-alt-text-generator' ); ?>
						</th>
						<td>
							<label for="autoalt_auto_generate">
								<input type="checkbox" id="autoalt_auto_generate" name="autoalt_auto_generate" value="1" <?php checked( get_option( 'autoalt_auto_generate', false ) ); ?>>
								<?php esc_html_e( 'Generate alt text automatically when new images are uploaded', 'auto-alt-text-generator' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Processes each new image with the AI model during upload. May add a delay depending on your AI provider.', 'auto-alt-text-generator' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Show Alt Text After Upload', 'auto-alt-text-generator' ); ?>
						</th>
						<td>
							<label for="autoalt_show_generated">
								<input type="checkbox" id="autoalt_show_generated" name="autoalt_show_generated" value="1" <?php checked( get_option( 'autoalt_show_generated', false ) ); ?>>
								<?php esc_html_e( 'Show generated alt text as a notice on the Media Library page after upload', 'auto-alt-text-generator' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, a notice appears on the Media Library page showing alt text generated for newly uploaded images.', 'auto-alt-text-generator' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
	<style id="autoalt-responsive-form"></style>
	<script>(document).ready(function() { $('.wrap .form-table td[scope=row]').css('max-height', '50vh'); $('.wrap .form-table th[rowscope]').css('max-height', '48vh'); });</script>

	</div>
	<?php
}

/**
 * Mark auto-generated attachments with a flag for JS consumption.
 *
 * @param array      $response   Attachment data for JS.
 * @param WP_Post    $attachment Attachment post object.
 * @return array
 */
	public function mark_auto_generated( $response, $attachment ) {
		if ( ! get_option( 'autoalt_show_generated', false ) ) {
			return $response;
		}

		$data = get_user_meta( get_current_user_id(), 'autoalt_last_generated', true );
		if ( ! empty( $data['attachment_id'] ) && (int) $data['attachment_id'] === $attachment->ID ) {
			$response['autoalt_generated'] = $data['alt_text'];
		}

		return $response;
	}

	/**
	 * Inline script to show a toast notification when auto-generated alt text is detected
	 * in the JS attachment response (fires immediately after upload, no refresh needed).
	 *
	 * @return void
	 */
	public function generated_script() {
		if ( ! get_option( 'autoalt_show_generated', false ) ) {
			return;
		}
		?>
		<script>
		jQuery(function($) {
			var orig = wp.media.view.Attachment.Library;
			if (!orig) return;
			wp.media.view.Attachment.Library = orig.extend({
				render: function() {
					var r = orig.prototype.render.apply(this, arguments);
					if (this.model && this.model.get('autoalt_generated')) {
						var alt = this.model.get('autoalt_generated');
						this.$el.append(
							'<div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.7);color:#fff;font-size:10px;padding:2px 4px;line-height:1.3;word-break:break-word;max-height:100%;overflow:hidden;">AI: ' + $('<span>').text(alt).html() + '</div>'
						);
					}
					return r;
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Show a notice on the Media Library page with the last auto-generated alt text.
	 *
	 * @return void
	 */
	public function generated_notice() {
		if ( ! get_option( 'autoalt_show_generated', false ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$data = get_user_meta( get_current_user_id(), 'autoalt_last_generated', true );
		if ( empty( $data ) || empty( $data['alt_text'] ) ) {
			return;
		}

		delete_user_meta( get_current_user_id(), 'autoalt_last_generated' );

		$thumbnail = wp_get_attachment_image( $data['attachment_id'], array( 60, 60 ), true );
		?>
		<div class="notice notice-success is-dismissible">
			<p style="display:flex;align-items:center;gap:12px;margin:8px 0;">
				<?php if ( $thumbnail ) : ?>
					<?php echo $thumbnail; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
				<span>
					<strong><?php esc_html_e( 'Auto Alt Text Generated:', 'auto-alt-text-generator' ); ?></strong>
					<?php echo esc_html( $data['alt_text'] ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Auto-generate alt text on upload if setting is enabled.
	 *
	 * @param int $attachment_id New attachment ID.
	 * @return void
	 */
	public function auto_generate_on_upload( $attachment_id ) {
		if ( ! get_option( 'autoalt_auto_generate', false ) ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! str_starts_with( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
			return;
		}

		$result = AutoAlt_Processor::init()->process_single( $attachment_id );

		if ( get_option( 'autoalt_show_generated', false ) && 'success' === $result['status'] ) {
			update_user_meta(
				get_current_user_id(),
				'autoalt_last_generated',
				array(
					'attachment_id' => $attachment_id,
					'alt_text'      => $result['generated'],
				)
			);
		}
	}

	/**
	 * AJAX: fetch a batch of image IDs.
	 *
	 * @return void
	 */
	public function ajax_get_ids() {
		check_ajax_referer( 'autoalt_process', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text-generator' ) ) );
		}

		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'missing';
		$cat_id = isset( $_POST['catId'] ) ? absint( $_POST['catId'] ) : 0;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$processor = AutoAlt_Processor::init();
		$result    = $processor->get_image_ids( $mode, $cat_id, $offset, $batch );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: process a single image.
	 *
	 * @return void
	 */
	public function ajax_process_single() {
		check_ajax_referer( 'autoalt_process', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text-generator' ) ) );
		}

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image ID.', 'auto-alt-text-generator' ) ) );
		}

		$processor = AutoAlt_Processor::init();
		$result    = $processor->process_single( $id );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: undo the last alt text change.
	 *
	 * @return void
	 */
	public function ajax_undo() {
		check_ajax_referer( 'autoalt_process', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text-generator' ) ) );
		}

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$alt  = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image ID.', 'auto-alt-text-generator' ) ) );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		$url = wp_get_attachment_image_url( $id, array( 40, 40 ) );

		wp_send_json_success( array(
			'id'        => $id,
			'alt'       => $alt,
			'status'    => 'success',
			'reason'    => __( 'Reverted.', 'auto-alt-text-generator' ),
			'thumbnail' => $url ? $url : '',
		) );
	}

	/**
	 * AJAX: create a background processing job.
	 *
	 * @return void
	 */
	public function ajax_create_job() {
		check_ajax_referer( 'autoalt_create_job' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text-generator' ) ) );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'missing';
		if ( ! in_array( $mode, array( 'missing', 'review', 'regenerate' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid mode.', 'auto-alt-text-generator' ) ) );
		}

		$existing = get_option( 'autoalt_job_status', false );
		if ( $existing && ! empty( $existing['is_running'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A job is already running.', 'auto-alt-text-generator' ) ) );
		}

		$processor = AutoAlt_Processor::init();
		$count     = $processor->get_image_ids( $mode, 0, 1 );

		if ( empty( $count['total'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No images to process.', 'auto-alt-text-generator' ) ) );
		}

		$job = array(
			'mode'       => $mode,
			'total'      => (int) $count['total'],
			'processed'  => 0,
			'failed'     => 0,
			'offset'     => 0,
			'is_running' => true,
			'completed'  => false,
			'updated_at' => time(),
		);

		update_option( 'autoalt_job_status', $job, false );

		// Schedule first batch in 10 seconds to allow the response to return.
		wp_schedule_single_event( time() + 10, 'autoalt_process_batch' );

		wp_send_json_success( array( 'message' => __( 'Job created.', 'auto-alt-text-generator' ) ) );
	}

	/**
	 * AJAX: return current job status.
	 *
	 * @return void
	 */
	public function ajax_job_status() {
		check_ajax_referer( 'autoalt_process', 'nonce' );

		$job = get_option( 'autoalt_job_status', false );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'No job found.', 'auto-alt-text-generator' ) ) );
		}

		wp_send_json_success( $job );
	}

	/**
	 * AJAX: cancel the current background job.
	 *
	 * @return void
	 */
	public function ajax_cancel_job() {
		check_ajax_referer( 'autoalt_cancel_job' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'auto-alt-text-generator' ) ) );
		}

		$job = get_option( 'autoalt_job_status', false );
		if ( $job ) {
			$job['is_running'] = false;
			$job['completed']  = false;
			$job['updated_at'] = time();
			update_option( 'autoalt_job_status', $job, false );
		}

		wp_send_json_success( array( 'message' => __( 'Job cancelled.', 'auto-alt-text-generator' ) ) );
	}

	/**
	 * Cron handler: process one batch of the background job.
	 *
	 * @return void
	 */
	public function process_background_batch() {
		$job = get_option( 'autoalt_job_status', false );
		if ( ! $job || empty( $job['is_running'] ) ) {
			return;
		}

		$processor  = AutoAlt_Processor::init();
		$batch_size = absint( get_option( 'autoalt_batch_size', 5 ) );
		$ids_result = $processor->get_image_ids( $job['mode'], $job['offset'], $batch_size );

		if ( empty( $ids_result['ids'] ) ) {
			$job['is_running'] = false;
			$job['completed']  = true;
			$job['updated_at'] = time();
			update_option( 'autoalt_job_status', $job, false );
			return;
		}

		foreach ( $ids_result['ids'] as $id ) {
			$result = $processor->process_single( $id );
			++$job['processed'];

			if ( 'error' === $result['status'] ) {
				++$job['failed'];
			}
		}

		$job['offset']     = $job['offset'] + count( $ids_result['ids'] );
		$job['updated_at'] = time();

		if ( $job['processed'] >= $job['total'] ) {
			$job['is_running'] = false;
			$job['completed']  = true;
		}

		update_option( 'autoalt_job_status', $job, false );

		if ( $job['is_running'] ) {
			wp_schedule_single_event( time() + 5, 'autoalt_process_batch' );
		}
	}
}
