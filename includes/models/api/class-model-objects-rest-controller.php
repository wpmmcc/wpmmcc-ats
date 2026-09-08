<?php
/**
 * Model Objects REST Controller
 *
 * REST API endpoints for model storage objects and object fields.
 *
 * @package WPTSALL\Models\API
 * @since 0.6.0
 */

namespace WPTSALL\Models\API;

use WPTSALL\Models\Services\Model_Object_Dependency_Service;
use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model Objects REST Controller Class
 */
class Model_Objects_REST_Controller {

	/**
	 * API namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * REST base
	 *
	 * @var string
	 */
	protected $rest_base = 'models';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/objects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_model_objects' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_model_object' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'object_type' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'post_type', 'taxonomy', 'option', 'custom_table' ),
						),
						'object_name' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/objects/(?P<object_id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_model_object' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/diagnostics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_model_diagnostics' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/objects/(?P<object_id>[\d]+)/fields',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_object_fields' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_object_field' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'field_kind' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'core', 'meta', 'column' ),
						),
						'field_key'  => array(
							'required' => true,
							'type'     => 'string',
						),
						'source_origin' => array( 'type' => 'string' ),
						'source_detail' => array( 'type' => 'string' ),
						'confidence_score' => array( 'type' => 'integer' ),
						'confidence_reason' => array( 'type' => 'string' ),
						'approval_state' => array( 'type' => 'string' ),
						'is_manual_override' => array( 'type' => 'boolean' ),
						'is_admin_approved' => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/objects/(?P<object_id>[\d]+)/fields/(?P<field_id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_object_field' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_object_field' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Check permission and enforce model/object/field ownership.
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

		$model_id  = absint( $request->get_param( 'id' ) );
		$object_id = absint( $request->get_param( 'object_id' ) );
		$field_id  = absint( $request->get_param( 'field_id' ) );

		if ( $model_id <= 0 || ! Translation_Rule_Service::get_model( $model_id ) ) {
			return new \WP_Error(
				'rest_object_not_found',
				__( 'Model not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		if ( $object_id > 0 ) {
			$object = Model_Object_Service::get_object( $object_id );
			if ( ! $object || absint( $object['model_id'] ?? 0 ) !== $model_id ) {
				return new \WP_Error(
					'rest_object_forbidden',
					__( 'Object does not belong to the selected model.', 'wpmmcc-ats' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( $field_id > 0 ) {
			$field = Model_Object_Service::get_field( $field_id );
			if ( ! $field || $object_id <= 0 || absint( $field['object_id'] ?? 0 ) !== $object_id ) {
				return new \WP_Error(
					'rest_object_forbidden',
					__( 'Field does not belong to the selected object.', 'wpmmcc-ats' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * List storage objects for a model with field counts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_model_objects( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$objects  = Model_Object_Service::get_objects_for_model( $model_id );

		foreach ( $objects as &$object ) {
			$fields                = Model_Object_Service::get_fields_for_object( (int) $object['id'] );
			$object['fields']      = $fields;
			$object['field_count'] = count( $fields );
		}
		unset( $object );

		return rest_ensure_response(
			array(
				'success' => true,
				'objects' => $objects,
				'total'   => count( $objects ),
			)
		);
	}

	/**
	 * Get model diagnostics for confidence and approval gaps.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_model_diagnostics( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		return rest_ensure_response( array(
			'success' => true,
			'diagnostics' => Model_Object_Service::get_model_diagnostics( $model_id ),
		) );
	}

	/**
	 * Create a new storage object.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_model_object( $request ) {
		$model_id    = (int) $request->get_param( 'id' );
		$object_type = sanitize_key( $request->get_param( 'object_type' ) );
		$object_name = sanitize_text_field( $request->get_param( 'object_name' ) );

		$object_id = Model_Object_Service::create_object(
			$model_id,
			$object_type,
			$object_name,
			array(
				'url_signature' => sanitize_text_field( $request->get_param( 'url_signature' ) ?? '' ),
				'source_type'   => 'manual',
			)
		);

		if ( ! $object_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create object', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$object = Model_Object_Service::get_object( $object_id );
		$deps   = Model_Object_Dependency_Service::rebuild_for_model( $model_id );

		return rest_ensure_response(
			array(
				'success'         => true,
				'object'          => $object,
				'dependency_sync' => $deps,
			)
		);
	}

	/**
	 * Delete a storage object and its fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_model_object( $request ) {
		$model_id  = (int) $request->get_param( 'id' );
		$object_id = (int) $request->get_param( 'object_id' );
		$object    = Model_Object_Service::get_object( $object_id );

		if ( ! $object || (int) ( $object['model_id'] ?? 0 ) !== $model_id ) {
			return new \WP_Error( 'not_found', __( 'Object not found', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$deleted = Model_Object_Service::delete_object( $object_id );
		if ( ! $deleted ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete object', 'wpmmcc-ats' ), array( 'status' => 500 ) );
		}

		$deps = Model_Object_Dependency_Service::rebuild_for_model( $model_id );

		return rest_ensure_response(
			array(
				'success'         => true,
				'deleted'         => true,
				'dependency_sync' => $deps,
			)
		);
	}

	/**
	 * Get fields for a storage object.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_object_fields( $request ) {
		$model_id  = (int) $request->get_param( 'id' );
		$object_id = (int) $request->get_param( 'object_id' );
		$object    = Model_Object_Service::get_object( $object_id );

		if ( ! $object || (int) ( $object['model_id'] ?? 0 ) !== $model_id ) {
			return new \WP_Error( 'not_found', __( 'Object not found for this model', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$fields = Model_Object_Service::get_fields_for_object( $object_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'fields'  => $fields,
				'total'   => count( $fields ),
			)
		);
	}

	/**
	 * Create a field for a storage object.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_object_field( $request ) {
		$model_id   = (int) $request->get_param( 'id' );
		$object_id  = (int) $request->get_param( 'object_id' );
		$field_kind = sanitize_key( $request->get_param( 'field_kind' ) );
		$field_key  = sanitize_text_field( $request->get_param( 'field_key' ) );
		$source     = sanitize_key( $request->get_param( 'source' ) ?? 'manual' );
		$extra      = $request->get_param( 'extra' );
		$extra      = is_array( $extra ) ? $extra : array();
		foreach ( array( 'source_origin', 'source_detail', 'confidence_score', 'confidence_reason', 'approval_state', 'is_manual_override', 'is_admin_approved' ) as $meta_key ) {
			if ( $request->has_param( $meta_key ) ) {
				$extra[ $meta_key ] = $request->get_param( $meta_key );
			}
		}
		$object     = Model_Object_Service::get_object( $object_id );

		if ( ! $object || (int) ( $object['model_id'] ?? 0 ) !== $model_id ) {
			return new \WP_Error( 'not_found', __( 'Object not found for this model', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$field_id = Model_Object_Service::add_field(
			$object_id,
			$field_kind,
			$field_key,
			$source,
			$extra
		);

		if ( ! $field_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create field', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$field = Model_Object_Service::get_field( $field_id );
		$deps  = Model_Object_Dependency_Service::rebuild_for_model( $model_id );

		return rest_ensure_response(
			array(
				'success'         => true,
				'field'           => $field,
				'dependency_sync' => $deps,
			)
		);
	}

	/**
	 * Update a field's attributes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_object_field( $request ) {
		$model_id  = (int) $request->get_param( 'id' );
		$object_id = (int) $request->get_param( 'object_id' );
		$field_id  = (int) $request->get_param( 'field_id' );
		$object    = Model_Object_Service::get_object( $object_id );

		if ( ! $object || (int) ( $object['model_id'] ?? 0 ) !== $model_id ) {
			return new \WP_Error( 'not_found', __( 'Object not found for this model', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$field = Model_Object_Service::get_field( $field_id );
		if ( ! $field ) {
			return new \WP_Error( 'not_found', __( 'Field not found', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}
		if ( (int) ( $field['object_id'] ?? 0 ) !== $object_id ) {
			return new \WP_Error( 'invalid_field_owner', __( 'Field does not belong to the selected object', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$data = array();
		if ( $request->has_param( 'field_kind' ) ) {
			$data['field_kind'] = sanitize_key( $request->get_param( 'field_kind' ) );
		}
		if ( $request->has_param( 'source' ) ) {
			$data['source'] = sanitize_key( $request->get_param( 'source' ) );
		}
		foreach ( array( 'source_origin', 'source_detail', 'confidence_score', 'confidence_reason', 'approval_state', 'is_manual_override', 'is_admin_approved' ) as $meta_key ) {
			if ( $request->has_param( $meta_key ) ) {
				$data[ $meta_key ] = $request->get_param( $meta_key );
			}
		}

		if ( $request->has_param( 'extra' ) ) {
			$extra = $request->get_param( 'extra' );
			if ( is_array( $extra ) ) {
				$data['extra'] = $extra;
			}
		}

		$updated = Model_Object_Service::update_field( $field_id, $data );
		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to update field', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$field = Model_Object_Service::get_field( $field_id );
		$deps  = Model_Object_Dependency_Service::rebuild_for_model( $model_id );

		return rest_ensure_response(
			array(
				'success'         => true,
				'field'           => $field,
				'dependency_sync' => $deps,
			)
		);
	}

	/**
	 * Delete a single field.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_object_field( $request ) {
		$model_id  = (int) $request->get_param( 'id' );
		$object_id = (int) $request->get_param( 'object_id' );
		$field_id  = (int) $request->get_param( 'field_id' );
		$object    = Model_Object_Service::get_object( $object_id );

		if ( ! $object || (int) ( $object['model_id'] ?? 0 ) !== $model_id ) {
			return new \WP_Error( 'not_found', __( 'Object not found for this model', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$field = Model_Object_Service::get_field( $field_id );
		if ( ! $field ) {
			return new \WP_Error( 'not_found', __( 'Field not found', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}
		if ( (int) ( $field['object_id'] ?? 0 ) !== $object_id ) {
			return new \WP_Error( 'invalid_field_owner', __( 'Field does not belong to the selected object', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$deleted = Model_Object_Service::delete_field( $field_id );
		if ( ! $deleted ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete field', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$deps = Model_Object_Dependency_Service::rebuild_for_model( $model_id );

		return rest_ensure_response(
			array(
				'success'         => true,
				'deleted'         => true,
				'dependency_sync' => $deps,
			)
		);
	}
}
