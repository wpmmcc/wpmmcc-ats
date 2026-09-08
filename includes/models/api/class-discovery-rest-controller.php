<?php
/**
 * WPTSALL Discovery REST Controller
 *
 * REST API endpoints for database schema discovery and field verification.
 *
 * @package WPTSALL
 * @since 0.5.1
 */

namespace WPTSALL\Models\API;

use WPTSALL\Models\Services\Field_Discovery_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery REST Controller Class
 */
class Discovery_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Get tables
		register_rest_route(
			$this->namespace,
			'/discovery/tables',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tables' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get columns
		register_rest_route(
			$this->namespace,
			'/discovery/columns',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_columns' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'table' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Get meta keys
		register_rest_route(
			$this->namespace,
			'/discovery/meta-keys',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_meta_keys' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_type'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'include_hidden' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		// Test field config
		register_rest_route(
			$this->namespace,
			'/discovery/test-config',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_config' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'config'    => array(
						'required' => true,
						'type'     => 'object',
					),
					'sample_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		// Get distinct values
		register_rest_route(
			$this->namespace,
			'/discovery/values',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_values' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'table'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'column' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'  => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 100,
					),
				),
			)
		);
	}

	/**
	 * Check permission
	 *
	 * @return bool
	 */
	public function check_permission() {
		return wptsall_user_can_manage_translations();
	}

	/**
	 * Get database tables
	 *
	 * @return \WP_REST_Response
	 */
	public function get_tables() {
		$tables = Field_Discovery_Service::get_tables();
		return rest_ensure_response( $tables );
	}

	/**
	 * Get table columns
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_columns( $request ) {
		$table   = $request->get_param( 'table' );
		$columns = Field_Discovery_Service::get_table_columns( $table );
		return rest_ensure_response( $columns );
	}

	/**
	 * Get meta keys
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_meta_keys( $request ) {
		$post_type      = $request->get_param( 'post_type' );
		$include_hidden = $request->get_param( 'include_hidden' );
		$keys           = Field_Discovery_Service::get_post_meta_keys( $post_type, $include_hidden );
		return rest_ensure_response( $keys );
	}

	/**
	 * Test field config
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function test_config( $request ) {
		$config    = $request->get_param( 'config' );
		$sample_id = $request->get_param( 'sample_id' );
		$value     = Field_Discovery_Service::test_field_config( $config, $sample_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'value'   => $value,
			)
		);
	}

	/**
	 * Get distinct values for a field
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_values( $request ) {
		$table  = $request->get_param( 'table' );
		$column = $request->get_param( 'column' );
		$limit  = $request->get_param( 'limit' );

		$values = Field_Discovery_Service::get_distinct_values( $table, $column, $limit );

		return rest_ensure_response( $values );
	}
}
