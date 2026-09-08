<?php
/**
 * WPTSALL Custom Model REST Controller
 *
 * Custom Model Management REST API
 *
 * @package WPTSALL\Models\API
 * @since 0.9.0
 */

namespace WPTSALL\Models\API;

use WPTSALL\Models\Services\Custom_Model_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Model REST Controller Class
 */
class Custom_Model_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Rest base
	 *
	 * @var string
	 */
	protected $rest_base = 'custom-models';

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Custom models collection.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_type' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'      => array(
							'type'              => 'string',
							'enum'              => array( 'active', 'inactive' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'plugin_slug' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'        => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page'    => array(
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'plugin_slug'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'name'               => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'plugin_name'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'url_pattern'        => array(
							'type'              => 'string',
							'default'           => '/{slug}/',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'post_types'         => array(
							'type'              => 'array',
							'default'           => array(),
							'sanitize_callback' => function( $value ) {
								return array_map( 'sanitize_text_field', (array) $value );
							},
						),
						'field_capabilities' => array(
							'type'    => 'object',
							'default' => array(),
						),
						'link_chains'        => array(
							'type'    => 'array',
							'default' => array(),
						),
						'rules'              => array(
							'type'        => 'array',
							'default'     => array(),
							'description' => __( 'Array of rule configurations for multi-post_type plugins (v0.9.0). Each element should contain: object_name, url_pattern, field_capabilities, etc.', 'wpmmcc-ats' ),
						),
					),
				),
			)
		);

		// Single custom model.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'           => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'plugin_name'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'status'         => array(
							'type' => 'string',
							'enum' => array( 'active', 'inactive' ),
						),
						'post_types'     => array(
							'type'              => 'array',
							'sanitize_callback' => function( $value ) {
								return array_map( 'sanitize_text_field', (array) $value );
							},
						),
						'taxonomies'     => array(
							'type'              => 'array',
							'sanitize_callback' => function( $value ) {
								return array_map( 'sanitize_text_field', (array) $value );
							},
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Custom model fields (field overrides).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/fields',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_field_overrides' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_field_overrides' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'overrides' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		// Link chains.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/link-chains',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_link_chains' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_link_chains' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'chains' => array(
							'required' => true,
							'type'     => 'array',
						),
					),
				),
			)
		);

		// Utility: Get unregistered plugins (plugins without models).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unregistered-plugins',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_unregistered_plugins' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Utility: Check if plugin has data.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check-plugin-data',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_plugin_data' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'plugin_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Utility: Test link chain configuration.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test-link-chain',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_link_chain' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'chain_config' => array(
						'required' => true,
						'type'     => 'array',
					),
					'sample_value' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Check permission and validate addressed custom-model object.
	 *
	 * @param \WP_REST_Request|null $request Request, when invoked by REST.
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request = null ) {
		if ( ! wptsall_user_can_manage_translations() ) {
			return false;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return true;
		}

		// Collection and utility endpoints do not address a model object.
		$route = (string) $request->get_route();
		if ( ! preg_match( '#/custom-models/(\d+)(?:/|$)#', $route, $match ) ) {
			return true;
		}

		$model_id = absint( $match[1] );
		if ( $model_id <= 0 || ! Custom_Model_Service::get( $model_id ) ) {
			return new \WP_Error(
				'rest_object_not_found',
				__( 'Custom model not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Get all custom models
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = array();

		if ( $request->get_param( 'source_type' ) ) {
			$args['source_type'] = $request->get_param( 'source_type' );
		}

		if ( $request->get_param( 'status' ) ) {
			$args['status'] = $request->get_param( 'status' );
		}

		if ( $request->get_param( 'plugin_slug' ) ) {
			$args['plugin_slug'] = $request->get_param( 'plugin_slug' );
		}

		$args['page']     = $request->get_param( 'page' );
		$args['per_page'] = $request->get_param( 'per_page' );

		$result = Custom_Model_Service::get_all( $args );

		return rest_ensure_response(
			array(
				'success' => true,
				'items'   => $result['items'],
				'total'   => $result['total'],
			)
		);
	}

	/**
	 * Get single custom model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$model    = Custom_Model_Service::get( $model_id );

		if ( ! $model ) {
			return new \WP_Error(
				'not_found',
				__( 'Custom model not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $model,
			)
		);
	}

	/**
	 * Create custom model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$data = array(
			'plugin_slug'        => $request->get_param( 'plugin_slug' ),
			'plugin_name'        => $request->get_param( 'plugin_name' ) ?: $request->get_param( 'name' ) ?: $request->get_param( 'plugin_slug' ),
			'url_pattern'        => $request->get_param( 'url_pattern' ),
			'post_types'         => $request->get_param( 'post_types' ),
			'field_capabilities' => $request->get_param( 'field_capabilities' ),
			'link_chains'        => $request->get_param( 'link_chains' ),
			'rules'              => $request->get_param( 'rules' ),  // ISS-MOD-002: support multi-rule creation
		);

		$result = Custom_Model_Service::create_custom_model( $data );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$model = Custom_Model_Service::get( $result['model_id'] );

		return rest_ensure_response(
			array(
				'success'             => true,
				'id'                  => $result['model_id'],
				'rule_id'             => $result['rule_id'],
				'additional_rule_ids' => $result['additional_rule_ids'] ?? array(),
				'total_rules'         => $result['total_rules'] ?? 1,
				'data'                => $model,
				'message'             => __( 'Custom model created successfully', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Update custom model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$model    = Custom_Model_Service::get( $model_id );

		if ( ! $model ) {
			return new \WP_Error(
				'not_found',
				__( 'Custom model not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$data   = array();
		$fields = array( 'name', 'plugin_name', 'description', 'status', 'post_types', 'taxonomies' );

		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				// Map 'name' to 'plugin_name' for consistency.
				$key          = 'name' === $field ? 'plugin_name' : $field;
				$data[ $key ] = $value;
			}
		}

		$result = Custom_Model_Service::update( $model_id, $data );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$updated_model = Custom_Model_Service::get( $model_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $updated_model,
				'message' => __( 'Custom model updated successfully', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Delete custom model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$model    = Custom_Model_Service::get( $model_id );

		if ( ! $model ) {
			return new \WP_Error(
				'not_found',
				__( 'Custom model not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Custom_Model_Service::delete( $model_id );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Custom model deleted successfully', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Get field overrides for a model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_field_overrides( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$model    = Custom_Model_Service::get( $model_id );

		if ( ! $model ) {
			return new \WP_Error(
				'not_found',
				__( 'Custom model not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$overrides = Custom_Model_Service::get_field_overrides( $model_id );

		if ( is_wp_error( $overrides ) ) {
			return new \WP_Error(
				$overrides->get_error_code(),
				$overrides->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $overrides,
			)
		);
	}

	/**
	 * Set field overrides for a model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_field_overrides( $request ) {
		$model_id  = (int) $request->get_param( 'id' );
		$overrides = $request->get_param( 'overrides' );
		$model     = Custom_Model_Service::get( $model_id );

		if ( ! $model ) {
			return new \WP_Error(
				'not_found',
				__( 'Custom model not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Custom_Model_Service::set_field_overrides( $model_id, $overrides );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$updated_overrides = Custom_Model_Service::get_field_overrides( $model_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $updated_overrides,
				'message' => __( 'Field overrides updated successfully', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Get link chains for a model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_link_chains( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$model    = Custom_Model_Service::get( $model_id );

		if ( ! $model ) {
			return new \WP_Error(
				'not_found',
				__( 'Custom model not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Get the first rule's ID.
		$rule_id = ! empty( $model['rules'] ) ? $model['rules'][0]['id'] : 0;

		if ( ! $rule_id ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(),
				)
			);
		}

		$chains = Custom_Model_Service::get_link_chains( $rule_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $chains,
			)
		);
	}

	/**
	 * Set link chains for a model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_link_chains( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$chains   = $request->get_param( 'chains' );
		$model    = Custom_Model_Service::get( $model_id );

		if ( ! $model ) {
			return new \WP_Error(
				'not_found',
				__( 'Custom model not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Get the first rule's ID.
		$rule_id = ! empty( $model['rules'] ) ? $model['rules'][0]['id'] : 0;

		if ( ! $rule_id ) {
			return new \WP_Error(
				'no_rule',
				__( 'No translation rule found for this model', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Custom_Model_Service::save_link_chains( $rule_id, $chains );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$updated_chains = Custom_Model_Service::get_link_chains( $rule_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $updated_chains,
				'message' => __( 'Link chains updated successfully', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Get unregistered plugins (plugins without models)
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_unregistered_plugins( $request ) {
		$plugins = Custom_Model_Service::get_unregistered_plugins();

		return rest_ensure_response(
			array(
				'success' => true,
				'plugins' => $plugins,
				'total'   => count( $plugins ),
			)
		);
	}

	/**
	 * Check if plugin has data
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_plugin_data( $request ) {
		$plugin_slug = $request->get_param( 'plugin_slug' );

		if ( empty( $plugin_slug ) ) {
			return new \WP_Error(
				'missing_plugin_slug',
				__( 'Plugin slug is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Custom_Model_Service::check_plugin_has_data( $plugin_slug );

		return rest_ensure_response(
			array(
				'success'     => true,
				'plugin_slug' => $plugin_slug,
				'has_data'    => $result['has_data'],
				'data_count'  => $result['count'],
				'post_types'  => $result['post_types'] ?? array(),
				'meta_keys'   => $result['meta_keys'] ?? array(),
				'tables'      => $result['tables'] ?? array(),
			)
		);
	}

	/**
	 * Test link chain configuration
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_link_chain( $request ) {
		$chain_config = $request->get_param( 'chain_config' );
		$sample_value = $request->get_param( 'sample_value' );

		if ( empty( $chain_config ) || ! is_array( $chain_config ) ) {
			return new \WP_Error(
				'invalid_chain_config',
				__( 'Invalid chain configuration', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// Validate chain configuration.
		$validation = Custom_Model_Service::validate_link_chain( $chain_config );

		if ( ! $validation['valid'] ) {
			return rest_ensure_response(
				array(
					'success'     => false,
					'valid'       => false,
					'errors'      => $validation['errors'],
					'test_result' => null,
				)
			);
		}

		// Test the chain with sample value.
		$test_result = Custom_Model_Service::test_link_chain( $chain_config, $sample_value );

		return rest_ensure_response(
			array(
				'success'     => true,
				'valid'       => true,
				'test_result' => $test_result,
			)
		);
	}
}
