<?php
/**
 * WPTSALL Sites REST Controller
 *
 * Sites Management REST API
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.4.0 Add site-relations endpoint
 */

namespace WPTSALL\Sites\API;

use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Models\Services\User_Mapping_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Relation_Config_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Sites\Validators\Site_Relation_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sites REST Controller Class
 */
class Sites_REST_Controller {

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
		// Legacy option-backed /sites CRUD removed (PRE-RELEASE-SINGLE-TRUTH).
		// Use /virtual-sites and /site-relations only.

		// Site Relations endpoints (v0.4.0)
		// ========================================

		// TODO: Add GET endpoint for id_mapping_details — currently only available via get_merged_config_for_relation() internally.

		// Site relations collection
		register_rest_route(
			$this->namespace,
			'/site-relations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_relations' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_site_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'template'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'         => array(
							'type'              => 'string',
							'enum'              => array( 'active', 'inactive', 'pending' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_site_relation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_site_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'source_lang'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'template'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'target_sites'   => array(
							'required' => true,
							'type'     => 'array',
						),
						'media_handling' => array(
							'type'              => 'string',
							'default'           => 'copy',
							'enum'              => array( 'copy', 'reference' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// Site relations grouped view
		register_rest_route(
			$this->namespace,
			'/site-relations/grouped',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_grouped_site_relations' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'source_site_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Config warnings (merged config conflicts) for a relation (ISS-TSK-068).
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/config-warnings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_relation_config_warnings' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Add targets to existing relation group
		register_rest_route(
			$this->namespace,
			'/site-relations/add-targets',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_target_sites' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'source_site_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'source_lang'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'template'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_targets'    => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		// Single site relation
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_relation' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_site_relation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status'         => array(
							'type' => 'string',
							'enum' => array( 'active', 'inactive', 'pending' ),
						),
						'target_lang'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'media_handling' => array(
							'type'              => 'string',
							'enum'              => array( 'copy', 'reference' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_site_relation' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Sync theme info for a relation
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/sync-theme',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_relation_theme' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// ========================================
		// Post Type Configs endpoints (v0.8.0)
		// ========================================

		// Post type configs for a relation
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/post-type-configs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_type_configs' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_post_type_configs' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// v0.9.0: Register specific routes BEFORE generic /{post_type} pattern
		// to prevent "stats" and "copy" being matched as post_type names.

		// Post type configs stats (ISS-SIT-015)
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/post-type-configs/stats',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_type_configs_stats' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Copy post type configs to another relation (ISS-SIT-015)
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/post-type-configs/copy',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'copy_post_type_configs' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'target_relation_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Check if post type config exists (ISS-SIT-015)
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/post-type-configs/(?P<post_type>[a-zA-Z0-9_-]+)/exists',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_post_type_config_exists' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Single post type config (generic pattern - must be registered LAST)
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/post-type-configs/(?P<post_type>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_type_config' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_post_type_config' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_post_type_config' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Batch sync theme info for a source site
		register_rest_route(
			$this->namespace,
			'/site-relations/sync-themes',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_sync_themes' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'source_site_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get targets for a relation (replaces wptsall_get_target_sites AJAX)
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/targets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_relation_targets' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Check plugin status (replaces wptsall_check_plugin_status AJAX)
		register_rest_route(
			$this->namespace,
			'/site-relations/check-plugin-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_plugin_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'template' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'site_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// ========================================
		// Relation Models endpoints (v0.6.0) - many-to-many association
		// ========================================

		// Get models for a relation
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/models',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_relation_models' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_relation_model' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'model_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_relation_models' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'model_ids' => array(
							'required' => true,
							'type'     => 'array',
						),
					),
				),
			)
		);

		// Remove model from relation
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/models/(?P<model_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_relation_model' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get language pack status for relation
		register_rest_route(
			$this->namespace,
			'/site-relations/(?P<id>\d+)/language-packs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_relation_language_packs' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// ========================================
		// Virtual Sites endpoints (v0.5.0)
		// ========================================

		// Virtual sites collection
		register_rest_route(
			$this->namespace,
			'/virtual-sites',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_virtual_sites' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_virtual_site' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'path_prefix' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_title',
						),
						'lang'        => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Single virtual site
		register_rest_route(
			$this->namespace,
			'/virtual-sites/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_virtual_site' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_virtual_site' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_virtual_site' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Check URL conflict
		register_rest_route(
			$this->namespace,
			'/virtual-sites/check-url',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_url_conflict' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'path_prefix' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
					'exclude_id'  => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Bulk create virtual sites (v0.9.0)
		register_rest_route(
			$this->namespace,
			'/virtual-sites/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_create_virtual_sites' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'sites' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'Site data array, each element contains name, path_prefix, lang and other fields', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// Bulk delete virtual sites (v0.9.0)
		register_rest_route(
			$this->namespace,
			'/virtual-sites/bulk-delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_delete_virtual_sites' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'site_ids' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'Array of site IDs to delete', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// Bulk update virtual sites (v0.9.0)
		register_rest_route(
			$this->namespace,
			'/virtual-sites/bulk-update',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_update_virtual_sites' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'updates' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'Update data array, each element contains site_id and fields to update', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// ========================================
		// User Mappings endpoints (v0.8.0)
		// ========================================

		// User mappings collection
		// Supports two parameter methods:
		// 1. relation_id - Get source_site_id and target_site_id from site relations
		// 2. source_site_id + target_site_id - Specify directly (backward compatible)
		register_rest_route(
			$this->namespace,
			'/user-mappings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_user_mappings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'relation_id'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Site relation ID (preferred)', 'wpmmcc-ats' ),
						),
						'source_site_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'target_site_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_user_mappings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'relation_id'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Site relation ID (preferred)', 'wpmmcc-ats' ),
						),
						'source_site_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'target_site_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'mappings'       => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_all_user_mappings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'relation_id'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Site relation ID (preferred)', 'wpmmcc-ats' ),
						),
						'source_site_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'target_site_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// User mapping statistics
		register_rest_route(
			$this->namespace,
			'/user-mappings/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_mapping_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'source_site_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'target_site_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Single user mapping
		register_rest_route(
			$this->namespace,
			'/user-mappings/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_user_mapping' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// ========================================
		// Virtual Content endpoints (migrated from rest.php v1)
		// ========================================

		// Get virtual content records
		register_rest_route(
			$this->namespace,
			'/virtual',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_virtual_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'site_id'     => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Virtual site ID', 'wpmmcc-ats' ),
					),
					'object_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Filter by object type', 'wpmmcc-ats' ),
					),
				),
			)
		);
	}

	/**
	 * Check permission
	 *
	 * Distinguish between unauthenticated (401) and unauthorized (403) cases.
	 *
	 * @since 1.0.0 Distinguish 401/403 status codes
	 * @param \WP_REST_Request|null $request Request, when invoked by REST.
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

		// Do object authorization before invoking controller callbacks.  The
		// callbacks repeat these checks as a defence in depth measure, but a
		// permission callback is the REST boundary: accepting a fabricated ID
		// here lets plugins/hooks attached to the callback observe a request for
		// an object that does not exist or is unrelated to its parent.
		if ( ! $request instanceof \WP_REST_Request ) {
			return true;
		}

		$route = (string) $request->get_route();

		// A relation may be addressed as an explicit parameter (for collection
		// endpoints) or as the ID in a nested site-relations route.
		$relation_id = absint( $request->get_param( 'relation_id' ) );
		if ( 0 === $relation_id && preg_match( '#/site-relations/(\d+)(?:/|$)#', $route, $match ) ) {
			$relation_id = absint( $match[1] );
		}
		if ( $relation_id > 0 && ! Site_Relation_Service::get_relation( $relation_id ) ) {
			return $this->rest_object_not_found( __( 'Site relation does not exist.', 'wpmmcc-ats' ) );
		}

		$target_relation_id = absint( $request->get_param( 'target_relation_id' ) );
		if ( $target_relation_id > 0 && ! Site_Relation_Service::get_relation( $target_relation_id ) ) {
			return $this->rest_object_not_found( __( 'Target site relation does not exist.', 'wpmmcc-ats' ) );
		}

		$virtual_site_id = 0;
		if ( preg_match( '#/virtual-sites/(\d+)(?:/|$)#', $route, $match ) ) {
			$virtual_site_id = absint( $match[1] );
		}
		if ( $virtual_site_id > 0 && ! Virtual_Site_Service::get( $virtual_site_id ) ) {
			return $this->rest_object_not_found( __( 'Virtual site does not exist.', 'wpmmcc-ats' ) );
		}

		// `exclude_id` is an object reference too; accepting an arbitrary value
		// makes the URL-conflict endpoint expose a misleading result.
		if ( preg_match( '#/virtual-sites/check-url$#', $route ) && $request->has_param( 'exclude_id' ) ) {
			$exclude_id = absint( $request->get_param( 'exclude_id' ) );
			if ( $exclude_id > 0 && ! Virtual_Site_Service::get( $exclude_id ) ) {
				return $this->rest_object_not_found( __( 'Excluded virtual site does not exist.', 'wpmmcc-ats' ) );
			}
		}

		if ( '/virtual' === substr( $route, -8 ) && $request->has_param( 'site_id' ) ) {
			$virtual_content_site_id = (string) $request->get_param( 'site_id' );
			if ( '' !== $virtual_content_site_id && ! Virtual_Site_Service::get( $virtual_content_site_id ) ) {
				return $this->rest_object_not_found( __( 'Virtual site does not exist.', 'wpmmcc-ats' ) );
			}
		}

		// Relation model references must exist.  A model nested under a relation
		// for DELETE must additionally be attached to that relation; the POST
		// collection route intentionally does not require that, because it creates
		// the association.
		$model_ids = array();
		if ( $request->has_param( 'model_id' ) ) {
			$model_ids[] = absint( $request->get_param( 'model_id' ) );
		}
		foreach ( (array) $request->get_param( 'model_ids' ) as $model_id ) {
			$model_ids[] = absint( $model_id );
		}
		foreach ( array_unique( array_filter( $model_ids ) ) as $model_id ) {
			if ( ! Translation_Rule_Service::get_model( $model_id ) ) {
				return $this->rest_object_not_found( __( 'Translation model does not exist.', 'wpmmcc-ats' ) );
			}
		}
		if ( $relation_id > 0 && preg_match( '#/site-relations/\d+/models/\d+$#', $route, $match ) ) {
			$model_id = absint( $request->get_param( 'model_id' ) );
			$belongs  = false;
			foreach ( Relation_Model_Service::get_models_by_relation( $relation_id ) as $relation_model ) {
				if ( $model_id === absint( $relation_model['id'] ?? 0 ) ) {
					$belongs = true;
					break;
				}
			}
			if ( ! $belongs ) {
				return new \WP_Error(
					'rest_object_forbidden',
					__( 'Model is not associated with the selected site relation.', 'wpmmcc-ats' ),
					array( 'status' => 403 )
				);
			}
		}

		// Creation and bulk relation operations contain site references in their
		// payloads. Validate every target here, rather than allowing a partially
		// valid collection to reach the mutation layer.
		foreach ( array( 'target_sites', 'new_targets' ) as $target_param ) {
			$target_error = $this->validate_relation_target_sites( $request->get_param( $target_param ) );
			if ( is_wp_error( $target_error ) ) {
				return $target_error;
			}
		}

		if ( $request->has_param( 'source_site_id' ) ) {
			$source_site_id = absint( $request->get_param( 'source_site_id' ) );
			if ( $source_site_id > 0 && ! $this->wordpress_site_exists( $source_site_id ) ) {
				return $this->rest_object_not_found( __( 'Source WordPress site does not exist.', 'wpmmcc-ats' ) );
			}
		}
		if ( $request->has_param( 'blog_source_site' ) ) {
			$source_site_id = absint( $request->get_param( 'blog_source_site' ) );
			if ( $source_site_id > 0 && ! $this->wordpress_site_exists( $source_site_id ) ) {
				return $this->rest_object_not_found( __( 'Blog source site does not exist.', 'wpmmcc-ats' ) );
			}
		}

		if ( preg_match( '#/user-mappings/(\d+)$#', $route, $match ) && ! $this->user_mapping_exists( absint( $match[1] ) ) ) {
			return $this->rest_object_not_found( __( 'User mapping does not exist.', 'wpmmcc-ats' ) );
		}

		if ( preg_match( '#/virtual-sites/bulk-delete$#', $route ) ) {
			foreach ( (array) $request->get_param( 'site_ids' ) as $site_id ) {
				if ( absint( $site_id ) <= 0 || ! Virtual_Site_Service::get( absint( $site_id ) ) ) {
					return $this->rest_object_not_found( __( 'One or more virtual sites do not exist.', 'wpmmcc-ats' ) );
				}
			}
		}
		if ( preg_match( '#/virtual-sites/bulk-update$#', $route ) ) {
			foreach ( (array) $request->get_param( 'updates' ) as $update ) {
				$site_id = is_array( $update ) ? absint( $update['site_id'] ?? 0 ) : 0;
				if ( $site_id <= 0 || ! Virtual_Site_Service::get( $site_id ) ) {
					return $this->rest_object_not_found( __( 'One or more virtual sites do not exist.', 'wpmmcc-ats' ) );
				}
			}
		}

		return true;
	}

	/**
	 * Build a consistent REST object-not-found response.
	 *
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private function rest_object_not_found( $message ) {
		return new \WP_Error( 'rest_object_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * Test whether a WordPress site/blog exists in the current installation.
	 *
	 * @param int $site_id WordPress blog ID.
	 * @return bool
	 */
	private function wordpress_site_exists( $site_id ) {
		$site_id = absint( $site_id );
		if ( $site_id <= 0 ) {
			return false;
		}

		if ( is_multisite() ) {
			return (bool) get_site( $site_id );
		}

		return $site_id === (int) get_current_blog_id();
	}

	/**
	 * Validate virtual/WordPress target site references supplied to relation APIs.
	 *
	 * @param mixed $targets Target-site request parameter.
	 * @return true|\WP_Error
	 */
	private function validate_relation_target_sites( $targets ) {
		if ( null === $targets ) {
			return true;
		}
		if ( ! is_array( $targets ) ) {
			return new \WP_Error(
				'rest_invalid_object_reference',
				__( 'Target sites must be an array.', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				return new \WP_Error(
					'rest_invalid_object_reference',
					__( 'Each target site must be an object.', 'wpmmcc-ats' ),
					array( 'status' => 400 )
				);
			}
			$target_type = sanitize_key( (string) ( $target['type'] ?? '' ) );
			$target_id   = $target['id'] ?? '';
			if ( 'virtual' === $target_type ) {
				if ( ! Virtual_Site_Service::get( $target_id ) ) {
					return $this->rest_object_not_found( __( 'Target virtual site does not exist.', 'wpmmcc-ats' ) );
				}
			} elseif ( 'wp' === $target_type ) {
				if ( ! $this->wordpress_site_exists( absint( $target_id ) ) ) {
					return $this->rest_object_not_found( __( 'Target WordPress site does not exist.', 'wpmmcc-ats' ) );
				}
			} else {
				return new \WP_Error(
					'rest_invalid_object_reference',
					__( 'Target site type must be wp or virtual.', 'wpmmcc-ats' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Check a user-mapping row before permitting a single-row mutation.
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return bool
	 */
	private function user_mapping_exists( $mapping_id ) {
		global $wpdb;

		$mapping_id = absint( $mapping_id );
		if ( $mapping_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', wptsall_table( 'user_mappings' ), $mapping_id )
		);
	}

	/**
	 * Get all sites
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_sites( $request ) {
		$sites     = get_option( 'wptsall_sites', array() );
		$result    = array();

		foreach ( $sites as $id => $site ) {
			$result[] = $this->format_site( $id, $site );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get single site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_site( $request ) {
		$id    = $request->get_param( 'id' );
		$sites = get_option( 'wptsall_sites', array() );

		if ( ! isset( $sites[ $id ] ) ) {
			return new \WP_Error( 'not_found', __( 'Site does not exist', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->format_site( $id, $sites[ $id ] ) );
	}

	/**
	 * Create site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_site( $request ) {
		$sites = get_option( 'wptsall_sites', array() );

		$name        = sanitize_text_field( $request->get_param( 'name' ) );
		$type        = sanitize_key( $request->get_param( 'type' ) );
		$slug        = sanitize_title( $request->get_param( 'slug' ) ?: $name );
		$target_lang = sanitize_text_field( $request->get_param( 'target_lang' ) );
		$model_id    = intval( $request->get_param( 'model_id' ) );

		// Generate unique ID
		$id = 'site_' . time() . '_' . wp_rand( 1000, 9999 );

		// Create site data
		$sites[ $id ] = array(
			'name'     => $name,
			'slug'     => $slug,
			'type'     => $type,
			'lang_to'  => $target_lang,
			'model_id' => $model_id,
			'status'   => 'active',
			'created'  => current_time( 'mysql' ),
		);

		update_option( 'wptsall_sites', $sites );

		// Auto-generate hooks if model is bound.
		if ( $model_id ) {
			// Get template slug from model.
			$model = Translation_Rule_Service::get_model( $model_id );
			if ( $model && ! empty( $model['plugin_slug'] ) ) {
				// Note: Legacy sites use string IDs, new hooks use integer site_relation IDs.
				// This auto-generation is deprecated for legacy sites.
				// Use site_relations API for proper hook generation.
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'id'      => $id,
			'site'    => $this->format_site( $id, $sites[ $id ] ),
		) );
	}

	/**
	 * Update site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_site( $request ) {
		$id    = $request->get_param( 'id' );
		$sites = get_option( 'wptsall_sites', array() );

		if ( ! isset( $sites[ $id ] ) ) {
			return new \WP_Error( 'not_found', __( 'Site does not exist', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$params = $request->get_params();

		if ( isset( $params['name'] ) ) {
			$sites[ $id ]['name'] = sanitize_text_field( $params['name'] );
		}
		if ( isset( $params['slug'] ) ) {
			$sites[ $id ]['slug'] = sanitize_title( $params['slug'] );
		}
		if ( isset( $params['target_lang'] ) ) {
			$sites[ $id ]['lang_to'] = sanitize_text_field( $params['target_lang'] );
		}
		if ( isset( $params['model_id'] ) ) {
			$sites[ $id ]['model_id'] = intval( $params['model_id'] );
		}
		if ( isset( $params['status'] ) ) {
			$sites[ $id ]['status'] = sanitize_key( $params['status'] );
		}

		$sites[ $id ]['updated'] = current_time( 'mysql' );

		update_option( 'wptsall_sites', $sites );

		return rest_ensure_response( array(
			'success' => true,
			'site'    => $this->format_site( $id, $sites[ $id ] ),
		) );
	}

	/**
	 * Delete site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_site( $request ) {
		$id    = $request->get_param( 'id' );
		$sites = get_option( 'wptsall_sites', array() );

		if ( ! isset( $sites[ $id ] ) ) {
			wptsall_log_error( 'sites-api', 'delete_site failed: not found', array( 'site_id' => $id ) );
			return new \WP_Error( 'not_found', __( 'Site does not exist', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		unset( $sites[ $id ] );
		update_option( 'wptsall_sites', $sites );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Format site for response
	 *
	 * @param string $id   Site ID.
	 * @param array  $site Site data.
	 * @return array
	 */
	private function format_site( $id, $site ) {
		$type       = $site['type'] ?? 'virtual';
		$type_label = ( $type === 'multisite' ) ? __( 'Multisite network site', 'wpmmcc-ats' ) : __( 'Virtual Sites', 'wpmmcc-ats' );

		$model_name = '';
		if ( ! empty( $site['model_id'] ) ) {
			$model = Translation_Rule_Service::get_model( $site['model_id'] );
			if ( $model ) {
				$model_name = $model['plugin_name'];
			}
		}

		return array(
			'id'           => $id,
			'name'         => $site['name'] ?? $id,
			'slug'         => $site['slug'] ?? $id,
			'type'         => $type,
			'type_label'   => $type_label,
			'target_lang'  => $site['lang_to'] ?? '',
			'model_id'     => $site['model_id'] ?? 0,
			'model_name'   => $model_name,
			'status'       => $site['status'] ?? 'active',
			'status_label' => ( isset( $site['status'] ) && $site['status'] === 'inactive' ) ? __( 'Disabled', 'wpmmcc-ats' ) : __( 'Enabled', 'wpmmcc-ats' ),
			'created'      => $site['created'] ?? '',
			'updated'      => $site['updated'] ?? '',
		);
	}

	// ========================================
	// Site Relations callback methods (v0.4.0)
	// ========================================

	/**
	 * Get all site relations
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_site_relations( $request ) {
		$source_site_id = $request->get_param( 'source_site_id' );
		$template       = $request->get_param( 'template' );
		$status         = $request->get_param( 'status' );

		// Build query args
		$args = array();
		if ( $source_site_id ) {
			$args['source_site_id'] = $source_site_id;
		}
		if ( $template ) {
			$args['template'] = $template;
		}
		if ( $status ) {
			$args['status'] = $status;
		}

		$relations = Site_Relation_Service::get_all_relations( $args );

		// Enhance with display names
		foreach ( $relations as &$relation ) {
			$relation = $this->enhance_relation_data( $relation );
		}

		return rest_ensure_response( $relations );
	}

	/**
	 * Get grouped site relations
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_grouped_site_relations( $request ) {
		$source_site_id = $request->get_param( 'source_site_id' );
		$filters        = array();

		if ( ! empty( $source_site_id ) ) {
			$filters['source_site_id'] = (int) $source_site_id;
		}

		$grouped = Site_Relation_Service::get_grouped_relations( $filters );

		// Enhance each group
		foreach ( $grouped as &$group ) {
			$group['source_site_name'] = Site_Relation_Validator::get_site_display_name( $group['source_site_id'], 'wp' );
			$group['source_lang_name'] = Site_Relation_Validator::get_language_display_name( $group['source_lang'] );

			foreach ( $group['targets'] as &$target ) {
				$target_site_id   = $target['target_site_id'] ?? $target['site_id'] ?? '';
				$target_site_type = $target['target_site_type'] ?? $target['site_type'] ?? 'wp';
				$target_lang      = $target['target_lang'] ?? $target['lang'] ?? '';

				$target['target_site_id']   = $target_site_id;
				$target['target_site_type'] = $target_site_type;
				$target['target_lang']      = $target_lang;
				$target['site_id']          = $target_site_id;
				$target['site_type']        = $target_site_type;
				$target['lang']             = $target_lang;

				$target['target_site_name'] = Site_Relation_Validator::get_site_display_name(
					$target_site_id,
					$target_site_type
				);
				$target['target_lang_name'] = Site_Relation_Validator::get_language_display_name( $target_lang );
			}
		}

		return rest_ensure_response( $grouped );
	}

	/**
	 * Create site relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_site_relation( $request ) {
		$data = array(
			'source_site_id' => $request->get_param( 'source_site_id' ),
			'source_lang'    => $request->get_param( 'source_lang' ) ?: '',
			'template'       => $request->get_param( 'template' ),
			'target_sites'   => $request->get_param( 'target_sites' ),
			'media_handling' => $request->get_param( 'media_handling' ) ?: 'copy',
		);

		// Validate
		$validation = Site_Relation_Validator::validate_new_relation( $data );
		if ( ! $validation['valid'] ) {
			wptsall_log_error(
				'sites-api',
				'create_site_relation validation failed',
				array( 'data' => $data, 'errors' => $validation['errors'] )
			);
			return new \WP_Error(
				'validation_failed',
				implode( '; ', $validation['errors'] ),
				array( 'status' => 400 )
			);
		}

		// Create relation
		$result = Site_Relation_Service::create_relation( $data );

		if ( ! $result['success'] ) {
			wptsall_log_error(
				'sites-api',
				'create_site_relation failed',
				array( 'data' => $data, 'message' => $result['message'] ?? '' )
			);
			return new \WP_Error(
				'creation_failed',
				$result['message'] ?? __( 'Failed to create relation', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'relation_ids' => $result['relation_ids'],
			'model_info'   => $result['model_info'] ?? null,
			'message'      => $result['message'] ?? __( 'Created successfully', 'wpmmcc-ats' ),
		) );
	}

	/**
	 * Get single site relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_site_relation( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$relation = Site_Relation_Service::get_relation( $id );

		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->enhance_relation_data( $relation ) );
	}

	/**
	 * Update site relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_site_relation( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$data = array();

		if ( $request->has_param( 'status' ) ) {
			$data['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		}
		if ( $request->has_param( 'target_lang' ) ) {
			$data['target_lang'] = sanitize_text_field( $request->get_param( 'target_lang' ) );
		}
		if ( $request->has_param( 'media_handling' ) ) {
			$data['media_handling'] = sanitize_key( $request->get_param( 'media_handling' ) );
		}
		if ( $request->has_param( 'sync_mode' ) ) {
			$data['sync_mode'] = sanitize_key( $request->get_param( 'sync_mode' ) );
		}
		if ( $request->has_param( 'direction' ) ) {
			$data['direction'] = sanitize_key( $request->get_param( 'direction' ) );
		}
		if ( $request->has_param( 'conflict_strategy' ) ) {
			$data['conflict_strategy'] = sanitize_key( $request->get_param( 'conflict_strategy' ) );
		}
		if ( empty( $data ) ) {
			return new \WP_Error(
				'no_data',
				__( 'No data to update', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Site_Relation_Service::update_relation( $id, $data );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'update_failed',
				$result['message'] ?? __( 'Update failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		// Get updated relation
		$updated = Site_Relation_Service::get_relation( $id );

		return rest_ensure_response( array(
			'success'  => true,
			'relation' => $this->enhance_relation_data( $updated ),
		) );
	}

	/**
	 * Delete site relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_site_relation( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $id );
		if ( ! $relation ) {
			wptsall_log_error( 'sites-api', 'delete_site_relation failed: not found', array( 'relation_id' => $id ) );
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Site_Relation_Service::delete_relation( $id );

		if ( ! $result['success'] ) {
			wptsall_log_error(
				'sites-api',
				'delete_site_relation failed',
				array( 'relation_id' => $id, 'message' => $result['message'] ?? '' )
			);
			return new \WP_Error(
				'delete_failed',
				$result['message'] ?? __( 'Delete failed', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Add target sites to existing relation group
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_target_sites( $request ) {
		$source_site_id = $request->get_param( 'source_site_id' );
		$source_lang    = $request->get_param( 'source_lang' ) ?: '';
		$template       = $request->get_param( 'template' );
		$new_targets    = $request->get_param( 'new_targets' );

		// Validate
		$validation = Site_Relation_Validator::validate_add_targets(
			$source_site_id,
			$source_lang,
			$template,
			$new_targets
		);

		if ( ! $validation['valid'] ) {
			wptsall_log_error(
				'sites-api',
				'add_target_sites validation failed',
				array(
					'source_site_id' => $source_site_id,
					'template'       => $template,
					'errors'         => $validation['errors'],
				)
			);
			return new \WP_Error(
				'validation_failed',
				implode( '; ', $validation['errors'] ),
				array( 'status' => 400 )
			);
		}

		// Add targets
		$result = Site_Relation_Service::add_target_sites(
			$source_site_id,
			$source_lang,
			$template,
			$new_targets
		);

		if ( ! $result['success'] ) {
			wptsall_log_error(
				'sites-api',
				'add_target_sites failed',
				array(
					'source_site_id' => $source_site_id,
					'template'       => $template,
					'message'        => $result['message'] ?? '',
				)
			);
			return new \WP_Error(
				'add_failed',
				$result['message'] ?? __( 'Failed to add target site', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'relation_ids' => $result['relation_ids'],
			'message'      => $result['message'] ?? __( 'Added successfully', 'wpmmcc-ats' ),
		) );
	}

	/**
	 * Sync theme info for a single relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_relation_theme( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Site_Relation_Service::sync_theme_info( $id );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'sync_failed',
				$result['message'] ?? __( 'Failed to sync theme info', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		// Get updated relation
		$updated = Site_Relation_Service::get_relation( $id );

		return rest_ensure_response( array(
			'success'    => true,
			'relation'   => $this->enhance_relation_data( $updated ),
			'theme_info' => $result['theme_info'] ?? array(),
		) );
	}

	/**
	 * Batch sync theme info for all relations of a source site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function batch_sync_themes( $request ) {
		$source_site_id = $request->get_param( 'source_site_id' );

		// Get all relations for this source site
		$relations = Site_Relation_Service::get_all_relations( array(
			'source_site_id' => $source_site_id,
		) );

		if ( empty( $relations ) ) {
			return new \WP_Error(
				'no_relations',
				__( 'Related site relation not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$synced_count = 0;
		$failed_ids   = array();

		foreach ( $relations as $relation ) {
			$result = Site_Relation_Service::sync_theme_info( $relation['id'] );
			if ( $result['success'] ) {
				$synced_count++;
			} else {
				$failed_ids[] = $relation['id'];
			}
		}

		return rest_ensure_response( array(
			'success'      => true,
			'synced_count' => $synced_count,
			'total_count'  => count( $relations ),
			'failed_ids'   => $failed_ids,
		) );
	}

	/**
	 * Enhance relation data with display names
	 *
	 * @param array $relation Relation data.
	 * @return array Enhanced relation data.
	 */
	private function enhance_relation_data( $relation ) {
		$relation['source_site_name'] = Site_Relation_Validator::get_site_display_name(
			$relation['source_site_id'],
			$relation['source_site_type'] ?? 'wp'
		);
		$relation['source_lang_name'] = Site_Relation_Validator::get_language_display_name(
			$relation['source_lang'] ?? ''
		);
		$relation['target_site_name'] = Site_Relation_Validator::get_site_display_name(
			$relation['target_site_id'],
			$relation['target_site_type'] ?? 'wp'
		);
		$relation['target_lang_name'] = Site_Relation_Validator::get_language_display_name(
			$relation['target_lang'] ?? ''
		);

		// Get model info if exists
		if ( ! empty( $relation['template'] ) ) {
			$model = Translation_Rule_Service::get_model( $relation['template'] );
			if ( $model ) {
				$relation['model_name'] = $model['plugin_name'] ?? $relation['template'];
			}
		}

		return $relation;
	}

	// ========================================
	// Relation Models callback methods (v0.6.0)
	// ========================================

	/**
	 * Get models associated with a relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_relation_models( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$target_lang = $relation['target_lang'] ?? '';
		$models      = Relation_Model_Service::get_models_with_language_pack_status( $relation_id, $target_lang );

		return rest_ensure_response( array(
			'relation_id'   => $relation_id,
			'target_lang'   => $target_lang,
			'models'        => $models,
			'models_count'  => count( $models ),
		) );
	}

	/**
	 * Add model to a relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_relation_model( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$model_id    = absint( $request->get_param( 'model_id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Check if model exists
		$model = Translation_Rule_Service::get_model( $model_id );
		if ( ! $model ) {
			return new \WP_Error(
				'model_not_found',
				__( 'Model does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Relation_Model_Service::add_model_to_relation( $relation_id, $model_id );

		if ( false === $result ) {
			return new \WP_Error(
				'add_failed',
				__( 'Failed to add model association', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'relation_id'  => $relation_id,
			'model_id'     => $model_id,
			'models_count' => Relation_Model_Service::get_models_count( $relation_id ),
		) );
	}

	/**
	 * Set all models for a relation (replace existing)
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_relation_models( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$model_ids   = $request->get_param( 'model_ids' );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Validate model IDs
		$model_ids = array_map( 'absint', (array) $model_ids );
		$model_ids = array_filter( $model_ids );

		$result = Relation_Model_Service::set_relation_models( $relation_id, $model_ids );

		if ( ! $result ) {
			return new \WP_Error(
				'update_failed',
				__( 'Failed to update model association', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'relation_id'  => $relation_id,
			'model_ids'    => $model_ids,
			'models_count' => count( $model_ids ),
		) );
	}

	/**
	 * Remove model from a relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_relation_model( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$model_id    = absint( $request->get_param( 'model_id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Relation_Model_Service::remove_model_from_relation( $relation_id, $model_id );

		if ( ! $result ) {
			return new \WP_Error(
				'remove_failed',
				__( 'Failed to remove model association', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'relation_id'  => $relation_id,
			'model_id'     => $model_id,
			'models_count' => Relation_Model_Service::get_models_count( $relation_id ),
		) );
	}

	/**
	 * Get language pack status for a relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_relation_language_packs( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$target_lang = $relation['target_lang'] ?? '';

		// Get theme language pack status
		$source_theme = $relation['source_theme_path'] ?? $relation['source_theme_name'] ?? '';
		$theme_pack   = null;

		if ( $source_theme ) {
			global $wpdb;
			$templates_table = wptsall_table( 'templates' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$theme_template = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status, total_entries, translated_entries
					FROM %i
					WHERE source_type = 'theme'
					AND source_identifier = %s
					AND target_language = %s
					LIMIT 1",
					$templates_table,
					$source_theme,
					$target_lang
				),
				ARRAY_A
			);

			if ( $theme_template ) {
				$theme_pack = array(
					'matched'            => true,
					'template_id'        => $theme_template['id'],
					'source_identifier'  => $source_theme,
					'status'             => $theme_template['status'],
					'total_entries'      => (int) $theme_template['total_entries'],
					'translated_entries' => (int) $theme_template['translated_entries'],
					'progress'           => $theme_template['total_entries'] > 0
						? round( ( $theme_template['translated_entries'] / $theme_template['total_entries'] ) * 100, 1 )
						: 0,
				);
			} else {
				$theme_pack = array(
					'matched'           => false,
					'source_identifier' => $source_theme,
					'message'           => sprintf(
						/* translators: %s: target language code */
						__( 'Missing %s language pack', 'wpmmcc-ats' ),
						$target_lang
					),
				);
			}
		}

		// Get plugin language packs
		$models       = Relation_Model_Service::get_models_with_language_pack_status( $relation_id, $target_lang );
		$plugin_packs = array();

		foreach ( $models as $model ) {
			$plugin_packs[] = array(
				'model_id'       => $model['id'],
				'plugin_slug'    => $model['plugin_slug'],
				'plugin_name'    => $model['plugin_name'],
				'language_pack'  => $model['language_pack'],
			);
		}

		return rest_ensure_response( array(
			'relation_id'   => $relation_id,
			'target_lang'   => $target_lang,
			'theme_pack'    => $theme_pack,
			'plugin_packs'  => $plugin_packs,
			'all_matched'   => $this->check_all_packs_matched( $theme_pack, $plugin_packs ),
		) );
	}

	/**
	 * Check if all language packs are matched
	 *
	 * @param array|null $theme_pack   Theme language pack status.
	 * @param array      $plugin_packs Plugin language packs status.
	 * @return bool
	 */
	private function check_all_packs_matched( $theme_pack, $plugin_packs ) {
		// Check theme pack
		if ( $theme_pack && ! $theme_pack['matched'] ) {
			return false;
		}

		// Check plugin packs
		foreach ( $plugin_packs as $pack ) {
			if ( ! $pack['language_pack']['matched'] ) {
				return false;
			}
		}

		return true;
	}

	// ========================================
	// Post Type Configs callback methods (v0.8.0)
	// ========================================

	/**
	 * Get merged-config warnings for a relation.
	 *
	 * Used by Sites UI to explain why some overrides did not take effect.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_relation_config_warnings( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$models = Relation_Model_Service::get_models_by_relation( $relation_id );
		if ( empty( $models ) ) {
			return rest_ensure_response(
				array(
					'relation_id'     => $relation_id,
					'warnings_count'  => 0,
					'warnings'        => array(),
					'generated_at'    => current_time( 'mysql' ),
				)
			);
		}

		$items = array();

		foreach ( $models as $model ) {
			$model_id = (int) ( $model['id'] ?? 0 );
			if ( $model_id <= 0 ) {
				continue;
			}

			$rules = Translation_Rule_Service::get_model_rules( $model_id );
			if ( empty( $rules ) ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				$rule_id = (int) ( $rule['id'] ?? 0 );
				if ( $rule_id <= 0 ) {
					continue;
				}

				// Quiet mode: UI needs the warnings but shouldn't spam warning logs repeatedly.
				$config = Translation_Rule_Service::get_merged_config( $rule_id, $relation_id, array( 'suppress_warning_log' => true ) );
				$warnings = $config['config_warnings'] ?? array();

				if ( empty( $warnings ) ) {
					continue;
				}

				$items[] = array(
					'model_id'     => $model_id,
					'plugin_slug'  => $model['plugin_slug'] ?? '',
					'plugin_name'  => $model['plugin_name'] ?? '',
					'rule_id'      => $rule_id,
					'data_type'    => $rule['data_type'] ?? '',
					'object_name'  => $rule['object_name'] ?? '',
					'warnings'     => array_values( array_map( 'strval', $warnings ) ),
				);
			}
		}

		return rest_ensure_response(
			array(
				'relation_id'     => $relation_id,
				'warnings_count'  => count( $items ),
				'warnings'        => $items,
				'generated_at'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get all post type configs for a relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_type_configs( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$configs = Relation_Config_Service::get_all_by_relation( $relation_id );
		$stats   = Relation_Config_Service::get_stats( $relation_id );

		return rest_ensure_response( array(
			'relation_id' => $relation_id,
			'configs'     => $configs,
			'stats'       => $stats,
		) );
	}

	/**
	 * Get single post type config
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_type_config( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$post_type   = sanitize_key( $request->get_param( 'post_type' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$config = Relation_Config_Service::get( $relation_id, $post_type );

		if ( ! $config ) {
			return new \WP_Error(
				'not_found',
				__( 'Post Type configuration does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $config );
	}

	/**
	 * Save (batch) post type configs for a relation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_post_type_configs( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$configs     = $request->get_json_params();

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Validate configs format.
		if ( ! is_array( $configs ) || empty( $configs ) ) {
			return new \WP_Error(
				'invalid_data',
				__( 'Invalid configuration data', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Relation_Config_Service::save_batch( $relation_id, $configs );

		if ( ! $result ) {
			wptsall_log_error(
				'sites-api',
				'save_post_type_configs failed',
				array( 'relation_id' => $relation_id )
			);
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save configuration', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'saved_count' => count( $configs ),
		) );
	}

	/**
	 * Update single post type config
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_post_type_config( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$post_type   = sanitize_key( $request->get_param( 'post_type' ) );
		$config      = $request->get_json_params();

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Relation_Config_Service::save( $relation_id, $post_type, $config );

		if ( false === $result ) {
			wptsall_log_error(
				'sites-api',
				'update_post_type_config failed',
				array(
					'relation_id' => $relation_id,
					'post_type'   => $post_type,
				)
			);
			return new \WP_Error(
				'update_failed',
				__( 'Failed to update configuration', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		$updated = Relation_Config_Service::get( $relation_id, $post_type );

		return rest_ensure_response( array(
			'success'   => true,
			'config_id' => $result,
			'config'    => $updated,
		) );
	}

	/**
	 * Delete post type config
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_post_type_config( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$post_type   = sanitize_key( $request->get_param( 'post_type' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Relation_Config_Service::delete( $relation_id, $post_type );

		if ( ! $result ) {
			wptsall_log_error(
				'sites-api',
				'delete_post_type_config failed',
				array(
					'relation_id' => $relation_id,
					'post_type'   => $post_type,
				)
			);
			return new \WP_Error(
				'delete_failed',
				__( 'Failed to delete configuration', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'success'     => true,
			'relation_id' => $relation_id,
			'post_type'   => $post_type,
		) );
	}

	/**
	 * Check if post type config exists (ISS-SIT-015)
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_post_type_config_exists( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );
		$post_type   = sanitize_key( $request->get_param( 'post_type' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$exists = Relation_Config_Service::exists( $relation_id, $post_type );

		return rest_ensure_response( array(
			'exists'      => $exists,
			'relation_id' => $relation_id,
			'post_type'   => $post_type,
		) );
	}

	/**
	 * Get post type configs stats (ISS-SIT-015)
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_type_configs_stats( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$stats = Relation_Config_Service::get_stats( $relation_id );

		return rest_ensure_response( array(
			'relation_id' => $relation_id,
			'stats'       => $stats,
		) );
	}

	/**
	 * Copy post type configs to another relation (ISS-SIT-015)
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function copy_post_type_configs( $request ) {
		$source_relation_id = absint( $request->get_param( 'id' ) );
		$target_relation_id = absint( $request->get_param( 'target_relation_id' ) );

		// Validate source relation.
		$source_relation = Site_Relation_Service::get_relation( $source_relation_id );
		if ( ! $source_relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Source relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Validate target relation.
		$target_relation = Site_Relation_Service::get_relation( $target_relation_id );
		if ( ! $target_relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Target relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Cannot copy to self.
		if ( $source_relation_id === $target_relation_id ) {
			return new \WP_Error(
				'invalid_target',
				__( 'Source and target relations cannot be the same', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Relation_Config_Service::copy_to_relation( $source_relation_id, $target_relation_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			wptsall_log_error(
				'sites-api',
				'copy_post_type_configs failed',
				array(
					'source_relation_id' => $source_relation_id,
					'target_relation_id' => $target_relation_id,
				)
			);
			return new \WP_Error(
				'copy_failed',
				__( 'Failed to copy configuration', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		// Get the copied configs count.
		$source_stats = Relation_Config_Service::get_stats( $source_relation_id );

		return rest_ensure_response( array(
			'success'            => true,
			'source_relation_id' => $source_relation_id,
			'target_relation_id' => $target_relation_id,
			'copied_count'       => $source_stats['total'] ?? 0,
		) );
	}

	// ========================================
	// New callback methods (v0.5.0) - replaces AJAX
	// ========================================

	/**
	 * Get target sites for a relation
	 *
	 * Replaces wptsall_get_target_sites AJAX.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_relation_targets( $request ) {
		$relation_id = absint( $request->get_param( 'id' ) );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error(
				'not_found',
				__( 'Relation does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$source_site_id = $relation['source_site_id'];
		$source_lang    = $relation['source_lang'] ?? '';
		$template       = $relation['template'];

		// Get all targets for this source group.
		$targets = Site_Relation_Service::get_targets_for_source(
			$source_site_id,
			$source_lang,
			$template
		);

		// Format target sites list.
		$target_sites = array();
		foreach ( $targets as $target ) {
			$target_sites[] = array(
				'relation_id' => $target['id'],
				'id'          => $target['target_site_id'],
				'type'        => $target['target_site_type'] ?? 'wp',
				'lang'        => $target['target_lang'] ?? '',
				'name'        => Site_Relation_Validator::get_site_display_name(
					$target['target_site_id'],
					$target['target_site_type'] ?? 'wp'
				),
				'lang_name'   => Site_Relation_Validator::get_language_display_name( $target['target_lang'] ?? '' ),
				'theme_name'  => $target['target_theme_name'] ?? '',
				'status'      => $target['status'] ?? 'active',
			);
		}

		return rest_ensure_response( array(
			'source_site_id' => $source_site_id,
			'source_lang'    => $source_lang,
			'template'       => $template,
			'target_sites'   => $target_sites,
			'target_count'   => count( $target_sites ),
		) );
	}

	/**
	 * Check plugin status on a site
	 *
	 * Replaces wptsall_check_plugin_status AJAX.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function check_plugin_status( $request ) {
		$template = $request->get_param( 'template' );
		$site_id  = $request->get_param( 'site_id' );

		// Check plugin status.
		$is_active = Site_Relation_Validator::plugin_is_active_on_site( $template, $site_id );

		// Get site name.
		$site_name = Site_Relation_Validator::get_site_display_name( $site_id, 'wp' );

		return rest_ensure_response( array(
			'active'    => $is_active,
			'site_id'   => $site_id,
			'site_name' => $site_name,
			'template'  => $template,
			'message'   => $is_active
				? sprintf(
					/* translators: %s: template name */
					__( 'Plugin %s is active on the source site', 'wpmmcc-ats' ),
					$template
				)
				: sprintf(
					/* translators: %s: template name */
					__( 'Warning: Plugin %s is not active on the source site', 'wpmmcc-ats' ),
					$template
				),
		) );
	}

	// ========================================
	// Virtual Sites callback methods (v0.5.0)
	// ========================================

	/**
	 * Get all virtual sites
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_virtual_sites( $request ) {
		$sites = Virtual_Site_Service::get_all();

		return rest_ensure_response( $sites );
	}

	/**
	 * Get single virtual site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_virtual_site( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$site = Virtual_Site_Service::get( $id );

		if ( ! $site ) {
			return new \WP_Error(
				'not_found',
				__( 'Virtual site does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $site );
	}

	/**
	 * Create virtual site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_virtual_site( $request ) {
		$data = array(
			'name'                => $request->get_param( 'name' ),
			'subtitle'            => $request->get_param( 'subtitle' ) ?: '',
			'path_prefix'         => $request->get_param( 'path_prefix' ),
			'lang'                => $request->get_param( 'lang' ),
			'logo_url'            => $request->get_param( 'logo_url' ) ?: '',
			'enable_blog_sync'    => $request->get_param( 'enable_blog_sync' ) ? 1 : 0,
			'blog_source_site'    => absint( $request->get_param( 'blog_source_site' ) ),
			'permalink_structure' => $request->get_param( 'permalink_structure' ) ?: '',
			'category_base'       => $request->get_param( 'category_base' ) ?: '',
			'tag_base'            => $request->get_param( 'tag_base' ) ?: '',
		);

		wptsall_log_info( 'sites-rest', 'create_virtual_site REST called', array( 'data' => $data ) );

		$result = Virtual_Site_Service::create( $data );

		if ( $result['success'] ) {
			return rest_ensure_response( array(
				'success' => true,
				'site_id' => $result['site_id'],
				'message' => __( 'Virtual site created successfully', 'wpmmcc-ats' ),
			) );
		}

		wptsall_log_error( 'sites-rest', 'create_virtual_site failed', array( 'errors' => $result['errors'] ) );

		return new \WP_Error(
			'creation_failed',
			__( 'Creation failed', 'wpmmcc-ats' ),
			array(
				'status' => 400,
				'errors' => $result['errors'],
			)
		);
	}

	/**
	 * Update virtual site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_virtual_site( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$site = Virtual_Site_Service::get( $id );
		if ( ! $site ) {
			return new \WP_Error(
				'not_found',
				__( 'Virtual site does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$data = array();

		if ( $request->has_param( 'name' ) ) {
			$data['name'] = sanitize_text_field( $request->get_param( 'name' ) );
		}
		if ( $request->has_param( 'subtitle' ) ) {
			$data['subtitle'] = sanitize_text_field( $request->get_param( 'subtitle' ) );
		}
		if ( $request->has_param( 'path_prefix' ) ) {
			$data['path_prefix'] = sanitize_title( $request->get_param( 'path_prefix' ) );
		}
		if ( $request->has_param( 'lang' ) ) {
			$data['lang'] = sanitize_text_field( $request->get_param( 'lang' ) );
		}
		if ( $request->has_param( 'logo_url' ) ) {
			$data['logo_url'] = esc_url_raw( $request->get_param( 'logo_url' ) );
		}
		if ( $request->has_param( 'enable_blog_sync' ) ) {
			$data['enable_blog_sync'] = $request->get_param( 'enable_blog_sync' ) ? 1 : 0;
		}
		if ( $request->has_param( 'blog_source_site' ) ) {
			$data['blog_source_site'] = absint( $request->get_param( 'blog_source_site' ) );
		}
		if ( $request->has_param( 'permalink_structure' ) ) {
			// Use wp_strip_all_tags to preserve % placeholders like %day%.
			$data['permalink_structure'] = wp_strip_all_tags( $request->get_param( 'permalink_structure' ) );
		}
		if ( $request->has_param( 'category_base' ) ) {
			// Use sanitize_title for URL-safe base names.
			$data['category_base'] = sanitize_title( $request->get_param( 'category_base' ) );
		}
		if ( $request->has_param( 'tag_base' ) ) {
			// Use sanitize_title for URL-safe base names.
			$data['tag_base'] = sanitize_title( $request->get_param( 'tag_base' ) );
		}

		if ( empty( $data ) ) {
			return new \WP_Error(
				'no_data',
				__( 'No data to update', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = Virtual_Site_Service::update( $id, $data );

		if ( $result['success'] ) {
			return rest_ensure_response( array(
				'success' => true,
				'site_id' => $id,
				'message' => __( 'Virtual site updated successfully', 'wpmmcc-ats' ),
			) );
		}

		wptsall_log_error( 'sites-rest', 'update_virtual_site failed', array( 'site_id' => $id, 'errors' => $result['errors'] ?? array() ) );

		return new \WP_Error(
			'update_failed',
			__( 'Update failed', 'wpmmcc-ats' ),
			array(
				'status' => 400,
				'errors' => $result['errors'] ?? array(),
			)
		);
	}

	/**
	 * Delete virtual site
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_virtual_site( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$site = Virtual_Site_Service::get( $id );
		if ( ! $site ) {
			wptsall_log_error( 'sites-rest', 'delete_virtual_site failed: not found', array( 'site_id' => $id ) );
			return new \WP_Error(
				'not_found',
				__( 'Virtual site does not exist', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$result = Virtual_Site_Service::delete( $id );

		if ( $result['success'] ) {
			return rest_ensure_response( array(
				'success' => true,
				'site_id' => $id,
				'message' => __( 'Virtual site deleted successfully', 'wpmmcc-ats' ),
			) );
		}

		wptsall_log_error( 'sites-rest', 'delete_virtual_site failed', array( 'site_id' => $id, 'errors' => $result['errors'] ?? array() ) );

		return new \WP_Error(
			'delete_failed',
			$result['errors'][0] ?? __( 'Delete failed', 'wpmmcc-ats' ),
			array(
				'status' => 400,
				'errors' => $result['errors'] ?? array(),
			)
		);
	}

	/**
	 * Check URL path conflict
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function check_url_conflict( $request ) {
		$path_prefix = $request->get_param( 'path_prefix' );
		$exclude_id  = $request->get_param( 'exclude_id' );

		$result = Virtual_Site_Service::check_url_conflict( $path_prefix, $exclude_id ?: null );

		return rest_ensure_response( array(
			'has_conflict' => $result['has_conflict'],
			'conflicts'    => $result['conflicts'],
		) );
	}

	/**
	 * Bulk create virtual sites
	 *
	 * @since 0.9.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_create_virtual_sites( $request ) {
		$sites_data = $request->get_param( 'sites' );

		if ( ! is_array( $sites_data ) || empty( $sites_data ) ) {
			return new \WP_Error(
				'invalid_data',
				__( 'No valid site data provided', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		wptsall_log_info( 'sites-rest', 'bulk_create_virtual_sites called', array( 'count' => count( $sites_data ) ) );

		$result = Virtual_Site_Service::bulk_create( $sites_data );

		if ( $result['success'] ) {
			return rest_ensure_response( array(
				'success' => true,
				'created' => $result['created'],
				'message' => sprintf(
					/* translators: %d: number of sites created */
					__( 'Successfully created %d virtual sites', 'wpmmcc-ats' ),
					count( $result['created'] )
				),
			) );
		}

		// Partial success case
		return rest_ensure_response( array(
			'success' => false,
			'created' => $result['created'],
			'errors'  => $result['errors'],
			'message' => sprintf(
				/* translators: 1: number of sites created, 2: number of failures */
				__( 'Successfully created %1$d, failed %2$d', 'wpmmcc-ats' ),
				count( $result['created'] ),
				count( $result['errors'] )
			),
		) );
	}

	/**
	 * Bulk delete virtual sites
	 *
	 * @since 0.9.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_delete_virtual_sites( $request ) {
		$site_ids = $request->get_param( 'site_ids' );

		if ( ! is_array( $site_ids ) || empty( $site_ids ) ) {
			return new \WP_Error(
				'invalid_data',
				__( 'No valid site IDs provided', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		wptsall_log_info( 'sites-rest', 'bulk_delete_virtual_sites called', array( 'count' => count( $site_ids ) ) );

		$result = Virtual_Site_Service::bulk_delete( $site_ids );

		if ( $result['success'] ) {
			return rest_ensure_response( array(
				'success' => true,
				'deleted' => $result['deleted'],
				'message' => sprintf(
					/* translators: %d: number of sites deleted */
					__( 'Successfully deleted %d virtual sites', 'wpmmcc-ats' ),
					count( $result['deleted'] )
				),
			) );
		}

		// Partial success case
		return rest_ensure_response( array(
			'success' => false,
			'deleted' => $result['deleted'],
			'errors'  => $result['errors'],
			'message' => sprintf(
				/* translators: 1: number of sites deleted, 2: number of failures */
				__( 'Successfully deleted %1$d, failed %2$d', 'wpmmcc-ats' ),
				count( $result['deleted'] ),
				count( $result['errors'] )
			),
		) );
	}

	/**
	 * Bulk update virtual sites
	 *
	 * @since 0.9.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_update_virtual_sites( $request ) {
		$updates = $request->get_param( 'updates' );

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return new \WP_Error(
				'invalid_data',
				__( 'No valid update data provided', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		wptsall_log_info( 'sites-rest', 'bulk_update_virtual_sites called', array( 'count' => count( $updates ) ) );

		$result = Virtual_Site_Service::bulk_update( $updates );

		if ( $result['success'] ) {
			return rest_ensure_response( array(
				'success' => true,
				'updated' => $result['updated'],
				'message' => sprintf(
					/* translators: %d: number of sites updated */
					__( 'Successfully updated %d virtual sites', 'wpmmcc-ats' ),
					count( $result['updated'] )
				),
			) );
		}

		// Partial success case
		return rest_ensure_response( array(
			'success' => false,
			'updated' => $result['updated'],
			'errors'  => $result['errors'],
			'message' => sprintf(
				/* translators: 1: number of sites updated, 2: number of failures */
				__( 'Successfully updated %1$d, failed %2$d', 'wpmmcc-ats' ),
				count( $result['updated'] ),
				count( $result['errors'] )
			),
		) );
	}

	// ========================================
	// User Mappings callback methods (v0.8.0)
	// ========================================

	/**
	 * Resolve site IDs from the request
	 *
	 * Supports two parameter methods:
	 * 1. relation_id - Get source_site_id and target_site_id from site relations
	 * 2. source_site_id + target_site_id - Specify directly
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Request object
	 * @return array|\WP_Error Array with source_site_id and target_site_id, or WP_Error
	 */
	private function resolve_site_ids_from_request( $request ) {
		$relation_id    = $request->get_param( 'relation_id' );
		$source_site_id = $request->get_param( 'source_site_id' );
		$target_site_id = $request->get_param( 'target_site_id' );

		// Prefer relation_id when provided
		if ( ! empty( $relation_id ) ) {
			$relation = Site_Relation_Service::get_relation( $relation_id );
			if ( ! $relation ) {
				return new \WP_Error(
					'invalid_relation',
					__( 'Site relation does not exist', 'wpmmcc-ats' ),
					array( 'status' => 404 )
				);
			}
			return array(
				'source_site_id' => $relation['source_site_id'],
				'target_site_id' => $relation['target_site_id'],
			);
		}

		// Fall back to directly specified parameters
		if ( empty( $source_site_id ) || empty( $target_site_id ) ) {
			return new \WP_Error(
				'missing_params',
				__( 'relation_id or source_site_id + target_site_id is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
		);
	}

	/**
	 * Get user mappings for source/target site pair
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 * @since 0.8.0
	 * @since 1.0.0 Supports relation_id parameter
	 */
	public function get_user_mappings( $request ) {
		$site_ids = $this->resolve_site_ids_from_request( $request );
		if ( is_wp_error( $site_ids ) ) {
			return $site_ids;
		}

		$mappings = User_Mapping_Service::get_mappings_by_sites(
			$site_ids['source_site_id'],
			$site_ids['target_site_id']
		);

		return rest_ensure_response(
			array(
				'success'  => true,
				'items'    => $mappings, // Use items to match test expectations
				'mappings' => $mappings, // Kept for backward compatibility
				'total'    => count( $mappings ),
				'pages'    => 1,
			)
		);
	}

	/**
	 * Save user mappings
	 *
	 * Supports two formats:
	 * 1. Batch format: mappings = {source_user_id: target_user_id, ...}
	 * 2. Single format: source_user_id + target_user_id
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 * @since 0.8.0
	 * @since 1.0.0 Supports relation_id parameter and single mapping creation
	 */
	public function save_user_mappings( $request ) {
		$site_ids = $this->resolve_site_ids_from_request( $request );
		if ( is_wp_error( $site_ids ) ) {
			return $site_ids;
		}

		$mappings       = $request->get_param( 'mappings' );
		$source_user_id = $request->get_param( 'source_user_id' );
		$target_user_id = $request->get_param( 'target_user_id' );

		// Support single mapping creation (source_user_id + target_user_id)
		if ( ! empty( $source_user_id ) && ! empty( $target_user_id ) ) {
			$mapping_array = array(
				(int) $source_user_id => (int) $target_user_id,
			);
		} elseif ( is_array( $mappings ) ) {
			// Batch mapping format
			$mapping_array = array();
			foreach ( $mappings as $src_user_id => $tgt_user_id ) {
				$mapping_array[ (int) $src_user_id ] = (int) $tgt_user_id;
			}
		} else {
			return new \WP_Error(
				'invalid_mappings',
				__( 'mappings object or source_user_id + target_user_id is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = User_Mapping_Service::save_batch(
			$site_ids['source_site_id'],
			$site_ids['target_site_id'],
			$mapping_array
		);

		if ( ! $result ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save user mapping', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'User mapping saved successfully', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Get user mapping statistics
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 * @since 0.8.0
	 */
	public function get_user_mapping_stats( $request ) {
		$source_site_id = $request->get_param( 'source_site_id' );
		$target_site_id = $request->get_param( 'target_site_id' );

		$stats = User_Mapping_Service::get_stats( $source_site_id, $target_site_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'stats'   => $stats,
			)
		);
	}

	/**
	 * Delete single user mapping
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 * @since 0.8.0
	 */
	public function delete_user_mapping( $request ) {
		$mapping_id = (int) $request->get_param( 'id' );

		$result = User_Mapping_Service::delete_mapping( $mapping_id );

		if ( ! $result ) {
			return new \WP_Error(
				'delete_failed',
				__( 'Failed to delete user mapping', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'User mapping deleted successfully', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Delete all user mappings for source/target site pair
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 * @since 0.8.0
	 * @since 1.0.0 Supports relation_id parameter
	 */
	public function delete_all_user_mappings( $request ) {
		$site_ids = $this->resolve_site_ids_from_request( $request );
		if ( is_wp_error( $site_ids ) ) {
			return $site_ids;
		}

		$deleted = User_Mapping_Service::delete_all_mappings(
			$site_ids['source_site_id'],
			$site_ids['target_site_id']
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'deleted' => $deleted,
				/* translators: %d is the number of user mappings deleted */
				'message' => sprintf( __( 'Deleted %d user mappings', 'wpmmcc-ats' ), $deleted ),
			)
		);
	}

	// ========================================
	// Virtual Content callback methods (migrated from rest.php v1)
	// ========================================

	/**
	 * Get virtual content records
	 *
	 * Migrated from rest.php v1 endpoint /virtual.
	 *
	 * Note: This queries the legacy virtual_site_content table.
	 * The display chain (Virtual_Site_Router) now uses wp_posts + _wptsall_virtual_site_id meta.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_virtual_content( $request ) {
		global $wpdb;

		$site_id     = $request->get_param( 'site_id' );
		$object_type = $request->get_param( 'object_type' );

		if ( ! $site_id ) {
			return new \WP_Error(
				'invalid_site',
				__( 'site_id parameter is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$table           = wptsall_table( 'virtual_site_content' );
		// Legacy table may be absent on newer installs; treat as no records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return rest_ensure_response( array() );
		}
		// Accept both 'v_2' and plain '2' formats.
		$virtual_site_id = is_string( $site_id ) && str_starts_with( $site_id, 'v_' )
			? $site_id
			: 'v_' . $site_id;

		if ( $object_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE virtual_site_id = %s AND object_type = %s ORDER BY id DESC LIMIT 50',
					$table,
					$virtual_site_id,
					$object_type
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE virtual_site_id = %s ORDER BY id DESC LIMIT 50',
					$table,
					$virtual_site_id
				),
				ARRAY_A
			);
		}

		return rest_ensure_response( $rows ? $rows : array() );
	}
}
