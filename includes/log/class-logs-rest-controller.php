<?php
/**
 * Admin Logs REST Controller
 *
 * Ops file logs + audit ring-buffer for authenticated admins.
 *
 * @package WPTSALL\Log
 * @since 2.1.5
 */

namespace WPTSALL\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs_REST_Controller
 */
class Logs_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/admin/ops-logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_ops_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'channel' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/ops-logs/read',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'read_ops_log' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'file'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					),
					'lines' => array(
						'type'              => 'integer',
						'default'           => 200,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/audit-logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_audit_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
					'action' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/admin/audit-logs/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_audit_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request = null ) {
		unset( $request );
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'manage_wptsall_settings' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'wpmmcc-ats' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /admin/ops-logs
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_ops_logs( $request ) {
		$channel = sanitize_key( (string) $request->get_param( 'channel' ) );
		$channel = '' !== $channel ? $channel : null;
		$files   = function_exists( 'wptsall_get_log_files' )
			? wptsall_get_log_files( $channel )
			: array();

		$items = array();
		foreach ( (array) $files as $file ) {
			$items[] = array(
				'name'     => (string) ( $file['name'] ?? '' ),
				'size'     => (int) ( $file['size'] ?? 0 ),
				'modified' => (int) ( $file['modified'] ?? 0 ),
				'modified_gmt' => ! empty( $file['modified'] )
					? gmdate( 'c', (int) $file['modified'] )
					: '',
			);
		}

		return new \WP_REST_Response(
			array(
				'success'     => true,
				'log_enabled' => function_exists( 'wptsall_log_enabled' ) ? (bool) wptsall_log_enabled() : false,
				'data'        => array(
					'items' => $items,
					'count' => count( $items ),
				),
			),
			200
		);
	}

	/**
	 * GET /admin/ops-logs/read
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function read_ops_log( $request ) {
		$name  = sanitize_file_name( (string) $request->get_param( 'file' ) );
		$lines = absint( $request->get_param( 'lines' ) );
		if ( $lines <= 0 ) {
			$lines = 200;
		}
		$lines = min( 2000, $lines );

		if ( '' === $name
			|| false !== strpos( $name, '..' )
			|| 0 !== strpos( $name, 'wptsall-' )
			|| ! preg_match( '/\.log(\.\d+)?$/', $name ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'invalid_file',
					'message' => 'Invalid log file name.',
				),
				400
			);
		}

		$log_dir = get_log_dir();
		$path    = trailingslashit( $log_dir ) . $name;
		$content = function_exists( 'wptsall_read_log_file' )
			? wptsall_read_log_file( $path, $lines )
			: '';

		$line_list = array();
		if ( '' !== $content ) {
			$line_list = preg_split( '/\r\n|\r|\n/', $content );
			if ( ! is_array( $line_list ) ) {
				$line_list = array();
			}
			// Drop a trailing empty split from final newline.
			if ( ! empty( $line_list ) && '' === end( $line_list ) ) {
				array_pop( $line_list );
			}
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'file'  => $name,
					'lines' => array_values( $line_list ),
					'count' => count( $line_list ),
				),
			),
			200
		);
	}

	/**
	 * GET /admin/audit-logs
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_audit_logs( $request ) {
		$limit  = absint( $request->get_param( 'limit' ) );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( $limit <= 0 ) {
			$limit = 50;
		}
		$limit = min( 500, $limit );

		$rows = array();
		if ( class_exists( '\\WPTSALL\\CLI\\Audit_Log' ) ) {
			$rows = \WPTSALL\CLI\Audit_Log::list( $limit, $action );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'items' => $rows,
					'count' => count( $rows ),
					'cap'   => class_exists( '\\WPTSALL\\CLI\\Audit_Log' ) ? (int) \WPTSALL\CLI\Audit_Log::CAP : 500,
				),
			),
			200
		);
	}

	/**
	 * POST /admin/audit-logs/clear
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function clear_audit_logs( $request ) {
		unset( $request );
		$cleared = 0;
		if ( class_exists( '\\WPTSALL\\CLI\\Audit_Log' ) ) {
			$cleared = (int) \WPTSALL\CLI\Audit_Log::clear();
		}
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array( 'cleared' => $cleared ),
			),
			200
		);
	}
}
