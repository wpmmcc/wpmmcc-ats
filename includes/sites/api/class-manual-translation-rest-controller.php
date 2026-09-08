<?php
/**
 * WPTSALL Manual Translation REST Controller
 *
 * Manual translation REST API (free version)
 *
 * @package WPTSALL
 * @since 1.0.0
 */

namespace WPTSALL\Sites\API;

use WPTSALL\Sites\Services\Manual_Content_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual Translation REST Controller Class
 *
 * Provides endpoints for the free-version manual translation feature.
 * Delegates all business logic to Manual_Content_Service.
 *
 * @since 1.0.0
 */
class Manual_Translation_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Register routes
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Get editor data for manual translation
		register_rest_route(
			$this->namespace,
			'/manual-translations/editor-data',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_editor_data' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'relation_id'    => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Save manual translation
		register_rest_route(
			$this->namespace,
			'/manual-translations',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_translation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_post_id'  => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'relation_id'     => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'translated_data' => array(
							'required' => true,
							'type'     => array( 'object', 'array' ),
						),
					),
				),
			)
		);

		// Create translation skeleton
		register_rest_route(
			$this->namespace,
			'/manual-translations/create-skeleton',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_skeleton' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'relation_id'    => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Update all fields on a target post (Edit Mode)
		register_rest_route(
			$this->namespace,
			'/manual-translations/(?P<target_id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_all_fields' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'target_id'   => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'relation_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'fields'      => array(
							'required' => true,
							'type'     => array( 'object', 'array' ),
						),
						'taxonomies'  => array(
							'required' => false,
							'type'     => array( 'object', 'array' ),
						),
					),
				),
			)
		);

		// Get translation status for a post
		register_rest_route(
			$this->namespace,
			'/manual-translations/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_translation_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'post_type'      => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Get editor data for manual translation
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_editor_data( $request ) {
		$source_post_id = $request->get_param( 'source_post_id' );
		$relation_id    = $request->get_param( 'relation_id' );

		$result = Manual_Content_Service::get_editor_data( $source_post_id, $relation_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create translation skeleton
	 *
	 * Creates a draft target post with sync fields copied, translate fields empty,
	 * and compute fields derived from the source post.
	 *
	 * @since 1.1.0
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_skeleton( $request ) {
		$source_post_id = $request->get_param( 'source_post_id' );
		$relation_id    = $request->get_param( 'relation_id' );

		$result = Manual_Content_Service::create_translation_skeleton( $source_post_id, $relation_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Save manual translation
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_translation( $request ) {
		$source_post_id  = $request->get_param( 'source_post_id' );
		$relation_id     = $request->get_param( 'relation_id' );
		$translated_data = $request->get_param( 'translated_data' );

		// Sanitize translated data fields.
		// The service uses field_config to determine which fields accept user input (translate/compute)
		// vs which are copied from source (sync). We sanitize all submitted keys here.
		$sanitized_data = array();

		if ( is_array( $translated_data ) ) {
			foreach ( $translated_data as $key => $value ) {
				$safe_key                   = sanitize_key( $key );
				$sanitized_data[ $safe_key ] = Manual_Content_Service::sanitize_editor_payload_value( $safe_key, $value );
			}
		}

		$result = Manual_Content_Service::save_translation( $source_post_id, $relation_id, $sanitized_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update all fields on a target post (Edit Mode)
	 *
	 * Accepts all field values and taxonomies, bypassing field classification.
	 * Used when the admin switches to "Edit Mode" for full content control.
	 *
	 * @since 1.2.0
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_all_fields( $request ) {
		$target_id   = $request->get_param( 'target_id' );
		$relation_id = $request->get_param( 'relation_id' );
		$fields      = $request->get_param( 'fields' );
		$taxonomies  = $request->get_param( 'taxonomies' );

		// Sanitize field values.
		$sanitized_fields = array();

		if ( is_array( $fields ) ) {
			foreach ( $fields as $key => $value ) {
				$safe_key                      = sanitize_key( $key );
				$sanitized_fields[ $safe_key ] = Manual_Content_Service::sanitize_editor_payload_value( $safe_key, $value );
			}
		}

		// Pass taxonomies through if provided.
		if ( ! empty( $taxonomies ) && is_array( $taxonomies ) ) {
			$sanitized_fields['taxonomies'] = $taxonomies;
		}

		$result = Manual_Content_Service::update_all_fields( $target_id, $relation_id, $sanitized_fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get translation status for a post
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_translation_status( $request ) {
		$source_post_id = $request->get_param( 'source_post_id' );
		$post_type      = $request->get_param( 'post_type' );

		$result = Manual_Content_Service::get_translation_status( $source_post_id, $post_type );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Check permission
	 *
	 * Distinguish between unauthenticated (401) and unauthorized (403) cases.
	 *
	 * @since 1.0.0
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request = null ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to perform this action', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}

		if ( ! wptsall_user_can_manage_translations() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action', 'wpmmcc-ats' ),
				array( 'status' => 403 )
			);
		}

		// Manual translation endpoints address source posts, relation rows and
		// (for edit mode) target posts. Validate those references in the REST
		// permission callback before service hooks or writes can run.
		if ( ! $request instanceof \WP_REST_Request ) {
			return true;
		}

		$relation_id = absint( $request->get_param( 'relation_id' ) );
		$relation    = null;
		if ( $relation_id > 0 ) {
			$relation = Site_Relation_Service::get_relation( $relation_id );
			if ( ! $relation ) {
				return $this->rest_object_not_found( __( 'Site relation does not exist.', 'wpmmcc-ats' ) );
			}
		}

		$source_post_id = absint( $request->get_param( 'source_post_id' ) );
		if ( $source_post_id > 0 ) {
			$source_exists = false;
			$source_site_id = absint( $relation['source_site_id'] ?? 0 );
			$switched = false;
			if ( $source_site_id > 0 && is_multisite() && $source_site_id !== get_current_blog_id() ) {
				switch_to_blog( $source_site_id );
				$switched = true;
			}
			$source_exists = (bool) get_post( $source_post_id );
			if ( $switched ) {
				restore_current_blog();
			}
			if ( ! $source_exists ) {
				return $this->rest_object_not_found( __( 'Source post does not exist.', 'wpmmcc-ats' ) );
			}
		}

		// Edit-mode updates must target a post attached to the selected relation.
		if ( $relation && preg_match( '#/manual-translations/(\d+)$#', (string) $request->get_route(), $match ) ) {
			$target_id = absint( $match[1] );
			$target_blog = 'wp' === (string) ( $relation['target_site_type'] ?? 'wp' )
				? absint( $relation['target_site_id'] ?? 0 )
				: 0;
			$switched = false;
			if ( $target_blog > 0 && is_multisite() && $target_blog !== get_current_blog_id() ) {
				switch_to_blog( $target_blog );
				$switched = true;
			}
			$target_post = get_post( $target_id );
			$target_relation_id = $target_post ? absint( \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $target_id, \WPTSALL\Sites\Services\Translation_Identity::META_RELATION_ID ) ) : 0;
			if ( $switched ) {
				restore_current_blog();
			}
			if ( ! $target_post ) {
				return $this->rest_object_not_found( __( 'Target post does not exist.', 'wpmmcc-ats' ) );
			}
			if ( $target_relation_id !== $relation_id ) {
				return new \WP_Error(
					'rest_object_forbidden',
					__( 'Target post is not attached to the selected site relation.', 'wpmmcc-ats' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Return a consistent object-not-found REST error.
	 *
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private function rest_object_not_found( $message ) {
		return new \WP_Error( 'rest_object_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * Check if a field name represents a media/attachment field.
	 *
	 * @since 1.1.0
	 * @param string $field_name Field name to check.
	 * @return bool True if the field is a media/attachment field.
	 */
	private static function is_media_field( $field_name ) {
		$media_fields = array( '_thumbnail_id' );

		if ( in_array( $field_name, $media_fields, true ) ) {
			return true;
		}

		$media_patterns = array(
			'/^_thumbnail/',
			'/_image_id$/',
			'/_image$/',
			'/_gallery$/',
			'/_attachment_id$/',
			'/^_wp_attached/',
		);

		foreach ( $media_patterns as $pattern ) {
			if ( preg_match( $pattern, $field_name ) ) {
				return true;
			}
		}

		return false;
	}
}
