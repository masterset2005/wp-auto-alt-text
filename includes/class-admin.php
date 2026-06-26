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

	public function enqueue_processing_script( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}

		$action = isset( $_GET['autoalt_action'] ) ? sanitize_key( $_GET['autoalt_action'] ) : '';

		if ( ! in_array( $action, array( 'missing', 'review', 'regenerate' ), true ) ) {
			return;
		}

		$js_ver = filemtime( AUTOALT_PLUGIN_DIR . 'assets/admin.js' );

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
			'batchSize' => 5,
		) );
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
