<?php
/**
 * WPTSALL Translation Rule REST Controller
 *
 * Translation Rule REST API Controller (V2)
 * Works with the new translation_rules table structure
 *
 * @package WPTSALL
 * @since 0.4.0
 */

namespace WPTSALL\Models\API;

use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Models\Services\Plugin_Mapping_Service;
use WPTSALL\Models\Services\Simulation_Validator;
use WPTSALL\Models\Services\Rule_Validation_Service;
use WPTSALL\Models\Services\Field_Sync_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation Rule REST Controller Class
 */
class Translation_Rule_REST_Controller {

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
		// Models collection
		register_rest_route(
			$this->namespace,
			'/models',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_models' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status'   => array(
							'type'    => 'string',
							'enum'    => array( 'active', 'inactive', '' ),
							'default' => '',
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
			)
		);

		// Model statistics (must be before /models/{id})
		register_rest_route(
			$this->namespace,
			'/models/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/adapter-manifests',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_adapter_manifests' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/field-rules-docs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_field_rules_docs' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/field-rules-docs/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_field_rules_doc' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/field-rules-docs/(?P<plugin_slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_field_rules_doc' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_field_rules_doc' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Scan all plugins (must be before /models/{id})
		register_rest_route(
			$this->namespace,
			'/models/scan-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_all_plugins' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Scan single plugin (must be before /models/{id})
		register_rest_route(
			$this->namespace,
			'/models/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_plugin' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'plugin_slug' => array(
						'required' => true,
						'type'     => 'string',
					),
					'save'        => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// Detect fields for object (must be before /models/{id})
		register_rest_route(
			$this->namespace,
			'/models/detect-fields',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'detect_fields' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'data_type'   => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'post', 'taxonomy', 'user', 'comment' ),
					),
					'object_name' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Import models (must be before /models/{id})
		register_rest_route(
			$this->namespace,
			'/models/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_models' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'models'    => array(
						'required' => true,
						'type'     => 'array',
					),
					'overwrite' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Export models (must be before /models/{id})
		register_rest_route(
			$this->namespace,
			'/models/export',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'export_models' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'model_ids'     => array(
						'required' => true,
						'type'     => 'array',
					),
					'include_rules' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// Single model
		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_model' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_model' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_model' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve_model' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Rescan model
		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/rescan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rescan_model' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// v0.6.0: Model fields (translation rules by field)
		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/fields',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_model_fields' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'data_type' => array(
						'type'              => 'string',
						'enum'              => array( 'post', 'taxonomy', 'user', 'comment', '' ),
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// v0.6.0: Model URL mappings
		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/urls',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_model_urls' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// v0.6.1: Manual field mappings
		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/manual-fields',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_manual_fields' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_manual_fields' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'meta_fields' => array(
							'required' => true,
							'type'     => 'array',
						),
						'auto_sync_rules' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						),
					),
				),
			)
		);

		// v1.1.0: Field sync status and actions.
		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/field-sync-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_field_sync_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/sync-fields-to-rules',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_fields_to_rules' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'fields' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/sync-all-fields',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_all_fields' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// v1.3.0: Model validation (Rule Chain Validator).
		register_rest_route(
			$this->namespace,
			'/models/(?P<model_id>\d+)/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_model_chain' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'model_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/models/(?P<model_id>\d+)/validation-report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_model_validation_report' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Global rules list (ISS-MOD-032).
		register_rest_route(
			$this->namespace,
			'/rules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_all_rules' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'model_id'    => array( 'type' => 'integer' ),
					'post_type'   => array( 'type' => 'string' ),
					'plugin_slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'page'        => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			)
		);

		// Model rules collection
		register_rest_route(
			$this->namespace,
			'/models/(?P<model_id>\d+)/rules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_model_rules' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'url_type' => array(
							'type' => 'string',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_rule' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Single rule
		register_rest_route(
			$this->namespace,
			'/rules/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rule' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_rule' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_rule' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Toggle rule active status
		register_rest_route(
			$this->namespace,
			'/rules/(?P<id>\d+)/toggle',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_rule' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Patch field_capabilities for a rule (H13: field editing API).
		register_rest_route(
			$this->namespace,
			'/rules/(?P<id>\d+)/field-capabilities',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rule_field_capabilities' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'patch_rule_field_capabilities' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'fields' => array(
							'required'    => true,
							'type'        => 'object',
							'description' => 'Object keyed by field_name, each value an object with optional type/direction/enabled keys.',
						),
					),
				),
			)
		);

		// v1.1.0: Field deletion impact check.
		register_rest_route(
			$this->namespace,
			'/rules/(?P<id>\d+)/field-deletion-impact',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_field_deletion_impact' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'field_key' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// v0.7.1: Link chains for rules
		register_rest_route(
			$this->namespace,
			'/rules/(?P<id>\d+)/link-chains',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rule_link_chains' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_rule_link_chains' ),
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

			// G2: Available fields for a rule (from model_object_fields table).
			register_rest_route(
				$this->namespace,
				'/rules/(?P<id>\d+)/available-fields',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rule_available_fields' ),
				'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			// Effective rule config preview (base vs relation-merged + conflict sources).
			register_rest_route(
				$this->namespace,
				'/rules/(?P<id>\d+)/effective-config',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rule_effective_config' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'relation_id' => array(
							'required' => false,
							'type'     => 'integer',
						),
					),
				)
			);

			// v1.6.0: Dependency graph for a rule/object context.
			register_rest_route(
				$this->namespace,
				'/rules/dependencies',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rule_dependencies' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'rule_id' => array(
							'required' => false,
							'type'     => 'integer',
						),
						'model_id' => array(
							'required' => false,
							'type'     => 'integer',
						),
						'data_type' => array(
							'required' => false,
							'type'     => 'string',
						),
						'object_name' => array(
							'required' => false,
							'type'     => 'string',
						),
					),
				)
			);

			// Validate rule (preview validation without saving)
			register_rest_route(
				$this->namespace,
				'/rules/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_rule' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// E6: Verify rules coverage against a real URL.
		register_rest_route(
			$this->namespace,
			'/rules/verify-with-url',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_with_url' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'include_relationships' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		// E7: Full diagnostic report for a model.
		register_rest_route(
			$this->namespace,
			'/rules/diagnose',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'diagnose_model' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'model_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => function ( $v ) {
							return is_numeric( $v ) && $v > 0;
						},
					),
				),
			)
		);

		// Get available fields for a data type
		register_rest_route(
			$this->namespace,
			'/fields',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_available_fields' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'data_type'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'object_name' => array(
						'required' => true,
						'type'     => 'string',
					),
					'model_id'   => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		// Get URL types and data types options
		register_rest_route(
			$this->namespace,
			'/options',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_options' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get template by plugin slug (migrated from rest.php v1)
		register_rest_route(
			$this->namespace,
			'/template',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'plugin' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Plugin identifier (slug)', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// H1: Export single model as v3.0 JSON.
		register_rest_route(
			$this->namespace,
			'/models/(?P<id>[\d]+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_model_v3' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// H1: Import model from v3.0 JSON (must be before /models/{id}).
		register_rest_route(
			$this->namespace,
			'/models/import-v3',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_model_v3' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'json'   => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'v3.0 export JSON string', 'wpmmcc-ats' ),
					),
					'action' => array(
						'type'        => 'string',
						'default'     => 'check',
						'enum'        => array( 'check', 'overwrite', 'skip', 'duplicate' ),
						'description' => __( 'Import action: check, overwrite, skip, duplicate', 'wpmmcc-ats' ),
					),
				),
			)
		);

		// H2: Export single rule as v3.0 JSON.
		register_rest_route(
			$this->namespace,
			'/rules/(?P<id>\d+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_rule_v3' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// H2: Import rule from v3.0 JSON.
		register_rest_route(
			$this->namespace,
			'/rules/import-v3',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_rule_v3' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'json'     => array(
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'v3.0 rule export JSON string', 'wpmmcc-ats' ),
					),
					'model_id' => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => __( 'Target model ID (optional if plugin_slug is provided)', 'wpmmcc-ats' ),
					),
					'plugin_slug' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Target plugin slug for model lookup (preferred)', 'wpmmcc-ats' ),
					),
					'action'   => array(
						'type'        => 'string',
						'default'     => 'check',
						'enum'        => array( 'check', 'overwrite', 'skip', 'duplicate' ),
						'description' => __( 'Import action: check, overwrite, skip, duplicate', 'wpmmcc-ats' ),
					),
				),
			)
		);
	}

	/**
	 * Check permission and validate addressed model/rule objects.
	 *
	 * Permission callbacks receive the request before the controller callback.
	 * Validate IDs here as well as in mutation callbacks so a caller cannot use
	 * a valid translation capability to address a missing or cross-owned object.
	 * The no-argument form remains supported by unit tests and internal callers.
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

		$route     = (string) $request->get_route();
		$model_id  = absint( $request->get_param( 'model_id' ) );
		$rule_id   = absint( $request->get_param( 'rule_id' ) );
		$relation_id = absint( $request->get_param( 'relation_id' ) );

		// Numeric IDs embedded in /models/{id} and /rules/{id} routes are
		// exposed as the generic `id` parameter by WP REST.
		if ( 0 === $model_id && preg_match( '#/models/(\d+)(?:/|$)#', $route, $match ) ) {
			$model_id = absint( $match[1] );
		}
		if ( 0 === $rule_id && preg_match( '#/rules/(\d+)(?:/|$)#', $route, $match ) ) {
			$rule_id = absint( $match[1] );
		}

		if ( $model_id > 0 && ! Translation_Rule_Service::get_model( $model_id ) ) {
			return new \WP_Error(
				'rest_object_not_found',
				__( 'Model not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$rule = null;
		if ( $rule_id > 0 ) {
			$rule = Translation_Rule_Service::get_rule( $rule_id );
			if ( ! $rule ) {
				return new \WP_Error(
					'rest_object_not_found',
					__( 'Rule not found.', 'wpmmcc-ats' ),
					array( 'status' => 404 )
				);
			}
			$rule_model_id = absint( $rule['model_id'] ?? 0 );
			if ( $model_id > 0 && $rule_model_id !== $model_id ) {
				return new \WP_Error(
					'rest_object_forbidden',
					__( 'Rule does not belong to the selected model.', 'wpmmcc-ats' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( $relation_id > 0 ) {
			$relation = Site_Relation_Service::get_relation( $relation_id );
			if ( ! $relation ) {
				return new \WP_Error(
					'rest_object_not_found',
					__( 'Site relation not found.', 'wpmmcc-ats' ),
					array( 'status' => 404 )
				);
			}

			// If both a model/rule and relation are addressed, enforce the
			// many-to-many ownership edge instead of accepting unrelated IDs.
			$owned_model_id = $model_id > 0 ? $model_id : absint( $rule['model_id'] ?? 0 );
			if ( $owned_model_id > 0 ) {
				$relation_models = Relation_Model_Service::get_models_by_relation( $relation_id );
				$belongs         = false;
				foreach ( $relation_models as $relation_model ) {
					if ( absint( $relation_model['id'] ?? 0 ) === $owned_model_id ) {
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
		}

		return true;
	}

	/**
	 * List content-plugin adapter manifests (built-in + JSON hot-plug).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_adapter_manifests() {
		$manifests = \WPTSALL\Models\Adapters\Adapter_Manifest::all();
		return rest_ensure_response(
			array(
				'items'       => array_values( $manifests ),
				'total'       => count( $manifests ),
				'schema'      => 'adapter-manifest-v1',
				'merge_order' => array(
					'meta_key'     => array( 'php_adapter', 'json_hotplug', 'manual_model_fields' ),
					'plugin_slug'  => array( 'php_adapter', 'json_hotplug' ),
					'doc_source'   => array( 'option', 'plugin_file' ),
					'notes'        => 'Highest listed first wins on conflict. Manual model fields apply at rule generation.',
				),
			)
		);
	}

	/**
	 * List JSON field-rules documents (file + option) with validation status.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_field_rules_docs() {
		$docs = \WPTSALL\Models\Adapters\Field_Rules_Store::discover_all_documents();
		return rest_ensure_response(
			array(
				'items'       => $docs,
				'total'       => count( $docs ),
				'merge_order' => array(
					'meta_key'    => array( 'php_adapter', 'json_hotplug', 'manual_model_fields' ),
					'doc_source'  => array( 'option overrides plugin_file' ),
				),
				'schema'      => 'field-rules-v1',
			)
		);
	}

	/**
	 * Validate a field-rules document without saving.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate_field_rules_doc( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_json', 'Request body must be a JSON object', array( 'status' => 400 ) );
		}
		if ( isset( $body['raw'] ) && is_string( $body['raw'] ) ) {
			$result = \WPTSALL\Models\Adapters\Field_Rules_Document_Validator::validate_json( $body['raw'] );
		} else {
			$result = \WPTSALL\Models\Adapters\Field_Rules_Document_Validator::validate( $body );
		}
		$status = $result['ok'] ? 200 : 400;
		return new \WP_REST_Response( $result, $status );
	}

	/**
	 * Save field-rules document into site option store.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_field_rules_doc( $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'plugin_slug' ) );
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_json', 'Request body must be a JSON object', array( 'status' => 400 ) );
		}
		if ( isset( $body['raw'] ) && is_string( $body['raw'] ) ) {
			$decoded = json_decode( $body['raw'], true );
			if ( ! is_array( $decoded ) ) {
				return new \WP_Error( 'invalid_json', 'raw is not valid JSON', array( 'status' => 400 ) );
			}
			$body = $decoded;
		}
		$body['plugin_slug'] = $slug;
		$result              = \WPTSALL\Models\Adapters\Field_Rules_Store::save_document( $body );
		if ( ! $result['ok'] ) {
			return new \WP_REST_Response( $result, 400 );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Delete option-stored field-rules document.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_field_rules_doc( $request ) {
		$slug    = sanitize_key( (string) $request->get_param( 'plugin_slug' ) );
		$deleted = \WPTSALL\Models\Adapters\Field_Rules_Store::delete_document( $slug );
		return rest_ensure_response(
			array(
				'ok'          => $deleted,
				'plugin_slug' => $slug,
			)
		);
	}

	/**
	 * Get models list
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_models( $request ) {
		$result = Translation_Rule_Service::get_models(
			array(
				'status'   => $request->get_param( 'status' ),
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Get single model with all rules
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_model( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$model    = Translation_Rule_Service::get_model( $model_id );

		if ( ! $model ) {
			return new \WP_Error( 'not_found', 'Model not found', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $model );
	}

	/**
	 * Update model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_model( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$data    = array();

		$model = null;

		// Basic editable fields (ISS-MOD-025).
		$text_fields = array( 'plugin_name', 'plugin_version', 'text_domain', 'description' );
		foreach ( $text_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		// Arrays (stored as JSON).
		if ( $request->has_param( 'post_types' ) ) {
			$post_types = $request->get_param( 'post_types' );
			$requested = is_array( $post_types ) ? array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) ) : array();

			// Preserve existing detailed objects where possible (scanner may store arrays with {name,...}).
			if ( null === $model ) {
				$model = Translation_Rule_Service::get_model( $model_id );
			}
			$existing = is_array( $model['post_types'] ?? null ) ? $model['post_types'] : array();
			$map      = array();
			foreach ( $existing as $pt ) {
				$name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : (string) $pt;
				$name = sanitize_key( $name );
				if ( '' === $name ) {
					continue;
				}
				$map[ $name ] = $pt;
			}

			$merged = array();
			foreach ( $requested as $name ) {
				$merged[] = isset( $map[ $name ] ) ? $map[ $name ] : $name;
			}
			$data['post_types'] = $merged;
		}

		if ( $request->has_param( 'taxonomies' ) ) {
			$taxonomies = $request->get_param( 'taxonomies' );
			$requested = is_array( $taxonomies ) ? array_values( array_filter( array_map( 'sanitize_key', $taxonomies ) ) ) : array();

			if ( null === $model ) {
				$model = Translation_Rule_Service::get_model( $model_id );
			}
			$existing = is_array( $model['taxonomies'] ?? null ) ? $model['taxonomies'] : array();
			$map      = array();
			foreach ( $existing as $tax ) {
				$name = is_array( $tax ) ? ( $tax['name'] ?? '' ) : (string) $tax;
				$name = sanitize_key( $name );
				if ( '' === $name ) {
					continue;
				}
				$map[ $name ] = $tax;
			}

			$merged = array();
			foreach ( $requested as $name ) {
				$merged[] = isset( $map[ $name ] ) ? $map[ $name ] : $name;
			}
			$data['taxonomies'] = $merged;
		}

		// Status fields.
		if ( $request->has_param( 'status' ) ) {
			$data['status'] = $request->get_param( 'status' );
		}

		if ( $request->has_param( 'usage_status' ) ) {
			$data['usage_status'] = $request->get_param( 'usage_status' );
		}

		if ( $request->has_param( 'semantic_status' ) ) {
			$data['semantic_status'] = $request->get_param( 'semantic_status' );
		}

		if ( empty( $data ) ) {
			return new \WP_Error( 'no_data', 'No data to update', array( 'status' => 400 ) );
		}

		$result = Translation_Rule_Service::update_model( $model_id, $data );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$model = Translation_Rule_Service::get_model( $model_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'model'   => $model,
			)
		);
	}

	/**
	 * Approve model semantic state.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve_model( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$result = Translation_Rule_Service::approve_model( $model_id );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'model' => Translation_Rule_Service::get_model( $model_id ) ) );
	}

	/**
	 * Get model manual fields
	 *
	 * Reads manual fields from model_object_fields (primary source).
	 *
	 * @since 1.0.2
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_manual_fields( $request ) {
		$model_id = (int) $request->get_param( 'id' );

		$model = Translation_Rule_Service::get_model( $model_id );
		if ( ! $model ) {
			return new \WP_Error( 'not_found', 'Model not found', array( 'status' => 404 ) );
		}

		require_once dirname( __DIR__ ) . '/services/class-model-object-service.php';

		$fields = \WPTSALL\Models\Services\Model_Object_Service::get_manual_fields( $model_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'fields'  => $fields,
				'total'   => count( $fields ),
			)
		);
	}

	/**
	 * Update model manual fields
	 *
	 * Primary write path: model_object_fields table.
	 * Secondary (deprecated): Plugin_Mapping_Service meta_fields for backward compat.
	 * Requires explicit object binding per entry (object_type + object_name).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_manual_fields( $request ) {
		$model_id    = (int) $request->get_param( 'id' );
		$meta_fields = $request->get_param( 'meta_fields' );
		$auto_sync_rules = true;
		if ( $request->has_param( 'auto_sync_rules' ) ) {
			$auto_sync_rules = rest_sanitize_boolean( $request->get_param( 'auto_sync_rules' ) );
		}

		$model = Translation_Rule_Service::get_model( $model_id );
		if ( ! $model ) {
			return new \WP_Error( 'not_found', 'Model not found', array( 'status' => 404 ) );
		}

		$plugin_slug = $model['plugin_slug'];

		if ( ! is_array( $meta_fields ) ) {
			$meta_fields = array();
		}

		// Require explicit object binding for each complete entry.
		foreach ( $meta_fields as $idx => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$table_name = sanitize_text_field( (string) ( $field['table_name'] ?? '' ) );
			$field_name = sanitize_text_field( (string) ( $field['field_name'] ?? '' ) );
			$id_map     = sanitize_text_field( (string) ( $field['associated_id_map'] ?? '' ) );

			// Skip incomplete rows silently (UI placeholders).
			if ( '' === $table_name || '' === $field_name || '' === $id_map ) {
				continue;
			}

			$object_type = sanitize_key( (string) ( $field['object_type'] ?? '' ) );
			$object_name = sanitize_text_field( (string) ( $field['object_name'] ?? '' ) );

			if ( '' === $object_type || '' === $object_name ) {
				return new \WP_Error(
					'manual_field_binding_required',
					sprintf(
						/* translators: %d: field array index */
						__( 'meta_fields[%d] must include object_type and object_name.', 'wpmmcc-ats' ),
						(int) $idx
					),
					array( 'status' => 400 )
				);
			}
		}

		// Primary: write to model_object_fields via Model_Object_Service.
		require_once dirname( __DIR__ ) . '/services/class-model-object-service.php';

		$stats = \WPTSALL\Models\Services\Model_Object_Service::save_manual_fields(
			$model_id,
			$meta_fields
		);
		if ( ! empty( $stats['errors'] ) ) {
			return new \WP_Error(
				'manual_fields_validation_failed',
				__( 'Manual fields validation failed. Please fix invalid field definitions.', 'wpmmcc-ats' ),
				array(
					'status' => 400,
					'errors' => $stats['errors'],
				)
			);
		}
		$dependency_sync = is_array( $stats['dependency_sync'] ?? null ) ? $stats['dependency_sync'] : array();

		// Secondary (deprecated): write-through to Plugin_Mapping_Service for backward compat.
		$compat_fields = array_map(
			function ( $field ) {
				if ( is_array( $field ) ) {
					$field['source'] = 'manual';
				}
				return $field;
			},
			$meta_fields
		);

		Plugin_Mapping_Service::save(
			array(
				'plugin_slug' => $plugin_slug,
				'meta_fields' => $compat_fields,
			)
		);

		$rule_sync = array(
			'enabled'       => $auto_sync_rules,
			'total_synced'  => 0,
			'total_skipped' => 0,
			'errors'        => array(),
			'per_rule'      => array(),
		);
		if ( $auto_sync_rules ) {
			$sync_result = Field_Sync_Service::sync_all_unsynced( $model_id );
			if ( is_array( $sync_result ) ) {
				$rule_sync = array_merge( $rule_sync, $sync_result );
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'Manual fields saved',
				'stats'   => $stats,
				'dependency_sync' => $dependency_sync,
				'rule_sync' => $rule_sync,
			)
		);
	}

	// ==========================================
	// Field Sync Endpoints (v1.1.0)
	// ==========================================

	/**
	 * Get field sync status for a model.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_field_sync_status( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$status   = Field_Sync_Service::get_sync_status( $model_id );

		return rest_ensure_response( array(
			'success' => true,
			'fields'  => $status,
			'summary' => array(
				'total'    => count( $status ),
				'synced'   => count( array_filter( $status, function ( $f ) { return $f['in_rule']; } ) ),
				'unsynced' => count( array_filter( $status, function ( $f ) { return ! $f['in_rule']; } ) ),
			),
		) );
	}

	/**
	 * Sync specified fields to their corresponding rules.
	 *
	 * Expects body: { fields: [ { rule_id, field_key, capability: {...} }, ... ] }
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function sync_fields_to_rules( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$fields   = $request->get_param( 'fields' );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return new \WP_Error( 'invalid_fields', __( 'Fields array is required.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		// Group fields by rule_id.
		$by_rule = array();
		foreach ( $fields as $entry ) {
			$rid = (int) ( $entry['rule_id'] ?? 0 );
			if ( ! $rid ) {
				continue;
			}
			if ( ! isset( $by_rule[ $rid ] ) ) {
				$by_rule[ $rid ] = array();
			}
			$by_rule[ $rid ][] = array(
				'field_key'  => $entry['field_key'] ?? '',
				'capability' => $entry['capability'] ?? array(),
			);
		}

		$total_synced  = 0;
		$total_skipped = 0;
		$all_errors    = array();
		$all_warnings  = array();
		$per_rule      = array();

		foreach ( $by_rule as $rule_id => $rule_fields ) {
			$res = Field_Sync_Service::sync_fields_to_rule( $rule_id, $rule_fields );
			$total_synced  += $res['synced'];
			$total_skipped += $res['skipped'];
			$all_errors     = array_merge( $all_errors, $res['errors'] );
			if ( ! empty( $res['relationship_warnings'] ) ) {
				$all_warnings = array_merge( $all_warnings, $res['relationship_warnings'] );
			}
			$per_rule[ $rule_id ] = $res;
		}

		return rest_ensure_response( array(
			'success'  => true,
			'synced'   => $total_synced,
			'skipped'  => $total_skipped,
			'errors'   => $all_errors,
			'warnings' => $all_warnings,
			'per_rule' => $per_rule,
		) );
	}

	/**
	 * Sync all unsynced manual fields for a model.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function sync_all_fields( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$result   = Field_Sync_Service::sync_all_unsynced( $model_id );

		return rest_ensure_response( array(
			'success'       => true,
			'total_synced'  => $result['total_synced'],
			'total_skipped' => $result['total_skipped'],
			'errors'        => $result['errors'],
			'per_rule'      => $result['per_rule'],
		) );
	}

	/**
	 * Check the impact of deleting a field from a rule.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_field_deletion_impact( $request ) {
		$rule_id   = (int) $request->get_param( 'id' );
		$field_key = sanitize_text_field( $request->get_param( 'field_key' ) );

		$result = Field_Sync_Service::check_field_deletion_impact( $rule_id, $field_key );

		return rest_ensure_response( $result );
	}

	/**
	 * Delete model and all its rules
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_model( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$result   = Translation_Rule_Service::delete_model( $model_id );

		if ( is_wp_error( $result ) ) {
			$status = 'not_found' === $result->get_error_code() ? 404 : 400;
			wptsall_log_error(
				'models-api',
				'delete_model failed',
				array(
					'model_id' => $model_id,
					'error'    => $result->get_error_message(),
				)
			);
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'deleted' => true,
			)
		);
	}

	// ==========================================
	// Object & Field CRUD endpoints (ISS-MOD-028)
	// ==========================================

	// ==========================================
	// Validation endpoints (Rule Chain Validator)
	// ==========================================

	/**
	 * Trigger full validation (L1+L2) for a model.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate_model_chain( $request ) {
		$model_id = (int) $request['model_id'];

		if ( ! class_exists( Simulation_Validator::class ) ) {
			return new \WP_Error( 'validator_unavailable', 'Simulation validator not available', array( 'status' => 500 ) );
		}

		$validator = new Simulation_Validator();
		$reports   = $validator->validate_full( $model_id );

		return rest_ensure_response( array(
			'model_id' => $model_id,
			'reports'  => $reports,
		) );
	}

	/**
	 * Get validation report for a model.
	 *
	 * Returns a fresh validation report (no caching layer yet).
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_model_validation_report( $request ) {
		$model_id = (int) $request['model_id'];

		if ( ! class_exists( Simulation_Validator::class ) ) {
			return new \WP_Error( 'validator_unavailable', 'Simulation validator not available', array( 'status' => 500 ) );
		}

		$validator = new Simulation_Validator();
		$reports   = $validator->validate_full( $model_id );

		return rest_ensure_response( array(
			'model_id' => $model_id,
			'reports'  => $reports,
		) );
	}

	/**
	 * Get all rules across models (ISS-MOD-032).
	 *
	 * @since 1.0.4
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_all_rules( $request ) {
		$args = array(
			'page'        => (int) $request->get_param( 'page' ) ?: 1,
			'per_page'    => (int) $request->get_param( 'per_page' ) ?: 20,
			'model_id'    => (int) $request->get_param( 'model_id' ),
			'post_type'   => $request->get_param( 'post_type' ) ?: '',
			'plugin_slug' => $request->get_param( 'plugin_slug' ) ?: '',
		);

		$result = Translation_Rule_Service::get_all_rules( $args );

		return rest_ensure_response( $result );
	}

	/**
	 * Get model rules
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_model_rules( $request ) {
		$model_id = (int) $request->get_param( 'model_id' );
		$url_type = $request->get_param( 'url_type' );

		$rules = Translation_Rule_Service::get_model_rules( $model_id, $url_type );

		return rest_ensure_response( $rules );
	}

	/**
	 * Get single rule
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_rule( $request ) {
		$rule_id = (int) $request->get_param( 'id' );
		$rule    = Translation_Rule_Service::get_rule( $rule_id );

		if ( ! $rule ) {
			return new \WP_Error( 'not_found', 'Rule not found', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $rule );
	}

	/**
	 * Get effective rule config preview (base + relation merge + conflict sources).
	 *
	 * @since 1.6.1
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_rule_effective_config( $request ) {
		$rule_id     = (int) $request->get_param( 'id' );
		$relation_id = (int) $request->get_param( 'relation_id' );
		$rule        = Translation_Rule_Service::get_rule( $rule_id );

		if ( ! $rule ) {
			return new \WP_Error(
				'not_found',
				__( 'Rule not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$base_config = Translation_Rule_Service::get_merged_config(
			$rule_id,
			null,
			array( 'suppress_warning_log' => true )
		);
		$effective_config = Translation_Rule_Service::get_merged_config(
			$rule_id,
			$relation_id > 0 ? $relation_id : null,
			array( 'suppress_warning_log' => true )
		);

		if ( ! is_array( $base_config ) || ! is_array( $effective_config ) ) {
			return new \WP_Error(
				'config_unavailable',
				__( 'Could not build effective config for this rule.', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		$base_fields      = is_array( $base_config['fields'] ?? null ) ? $base_config['fields'] : array();
		$effective_fields = is_array( $effective_config['fields'] ?? null ) ? $effective_config['fields'] : array();
		$field_diffs      = $this->build_effective_field_differences( $base_fields, $effective_fields );
		$config_warnings  = is_array( $effective_config['config_warnings'] ?? null ) ? $effective_config['config_warnings'] : array();

		$override = array();
		if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' ) ) {
			$override = \WPTSALL\Sites\Services\Relation_Config_Service::get(
				$relation_id,
				(string) ( $rule['object_name'] ?? '' ),
				(string) ( $rule['data_type'] ?? '' )
			);
			$override = is_array( $override ) ? $override : array();
		}

		$response = array(
			'success'          => true,
			'rule_id'          => $rule_id,
			'relation_id'      => $relation_id > 0 ? $relation_id : null,
			'rule_binding'     => array(
				'model_id'    => (int) ( $rule['model_id'] ?? 0 ),
				'data_type'   => sanitize_key( (string) ( $rule['data_type'] ?? '' ) ),
				'object_name' => sanitize_key( (string) ( $rule['object_name'] ?? '' ) ),
				'url_type'    => sanitize_key( (string) ( $rule['url_type'] ?? '' ) ),
			),
			'base'             => array(
				'enabled'   => (bool) ( $base_config['enabled'] ?? true ),
				'direction' => sanitize_key( (string) ( $base_config['direction'] ?? '' ) ),
				'sync_mode' => sanitize_key( (string) ( $base_config['sync_mode'] ?? '' ) ),
				'fields'    => $base_fields,
			),
			'effective'        => array(
				'enabled'   => (bool) ( $effective_config['enabled'] ?? true ),
				'direction' => sanitize_key( (string) ( $effective_config['direction'] ?? '' ) ),
				'sync_mode' => sanitize_key( (string) ( $effective_config['sync_mode'] ?? '' ) ),
				'fields'    => $effective_fields,
			),
			'field_differences'=> $field_diffs,
			'warnings'         => $config_warnings,
			'override_source'  => array(
				'has_relation_override' => ! empty( $override ),
				'override_keys'         => array_keys( $override ),
				'field_overrides_count' => is_array( $override['field_overrides'] ?? null ) ? count( $override['field_overrides'] ) : 0,
			),
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Build field-level differences between base and effective configs.
	 *
	 * @since 1.6.1
	 *
	 * @param array $base_fields      Base config fields.
	 * @param array $effective_fields Effective config fields.
	 * @return array
	 */
	private function build_effective_field_differences( array $base_fields, array $effective_fields ): array {
		$keys = array_unique( array_merge( array_keys( $base_fields ), array_keys( $effective_fields ) ) );
		$diff = array();

		$metadata_keys = array(
			'translate_fields',
			'sync_fields',
			'id_mapping_fields',
			'compute_fields',
			'skip_fields',
			'id_mapping_details',
		);

		foreach ( $keys as $field_key ) {
			if ( in_array( $field_key, $metadata_keys, true ) ) {
				continue;
			}
			$base_value      = is_array( $base_fields[ $field_key ] ?? null ) ? $base_fields[ $field_key ] : null;
			$effective_value = is_array( $effective_fields[ $field_key ] ?? null ) ? $effective_fields[ $field_key ] : null;
			if ( $base_value === $effective_value ) {
				continue;
			}

			$diff[] = array(
				'field'     => sanitize_text_field( (string) $field_key ),
				'base'      => $base_value,
				'effective' => $effective_value,
			);
		}

		return $diff;
	}

	/**
	 * Get available fields for a rule from the model_object_fields table (G2).
	 *
	 * Fetches all fields belonging to the same model + object_name as the rule,
	 * so the Field Configurator can show them as a pick-list instead of relying
	 * on manual entry.
	 *
	 * @since 1.0.5
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_rule_available_fields( $request ) {
		$rule_id = (int) $request['id'];
		$rule    = Translation_Rule_Service::get_rule( $rule_id );

		if ( ! $rule ) {
			return new \WP_Error(
				'not_found',
				__( 'Rule not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		global $wpdb;

		$fields_table  = $wpdb->prefix . 'wptsall_model_object_fields';
		$objects_table = $wpdb->prefix . 'wptsall_model_objects';
		$object_type   = $this->map_rule_data_type_to_object_type( (string) ( $rule['data_type'] ?? '' ) );
		$where_sql     = 'WHERE o.model_id = %d AND o.object_name = %s';
		$params        = array(
			$fields_table,
			$objects_table,
			(int) $rule['model_id'],
			$rule['object_name'],
		);
		if ( '' !== $object_type ) {
			$where_sql .= ' AND o.object_type = %s';
			$params[]   = $object_type;
		}
		$where_sql .= ' AND (f.status IS NULL OR f.status != %s)';
		$params[]   = 'orphan';

		// $where_sql is built only from fixed fragments with %d/%s placeholders (values in $params).
		$sql = 'SELECT f.id, f.field_key, f.field_kind, f.data_type, f.source, f.status
				 FROM %i f
				 INNER JOIN %i o ON f.object_id = o.id
				 ' . $where_sql . '
				 ORDER BY f.field_kind ASC, f.field_key ASC';
		$fields = wptsall_db_get_results( $sql, $params, ARRAY_A );

		return rest_ensure_response( $fields ?: array() );
	}

	/**
	 * Get dependency rows for a rule/object context.
	 *
	 * Accepts either:
	 * - rule_id, or
	 * - model_id + data_type + object_name.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_rule_dependencies( $request ) {
		$rule_id     = (int) $request->get_param( 'rule_id' );
		$model_id    = (int) $request->get_param( 'model_id' );
		$data_type   = sanitize_key( (string) $request->get_param( 'data_type' ) );
		$object_name = sanitize_key( (string) $request->get_param( 'object_name' ) );
		$rule        = null;

		if ( $rule_id > 0 ) {
			$rule = Translation_Rule_Service::get_rule( $rule_id );
			if ( ! $rule ) {
				return new \WP_Error(
					'not_found',
					__( 'Rule not found', 'wpmmcc-ats' ),
					array( 'status' => 404 )
				);
			}
			$model_id    = (int) ( $rule['model_id'] ?? 0 );
			$data_type   = sanitize_key( (string) ( $rule['data_type'] ?? '' ) );
			$object_name = sanitize_key( (string) ( $rule['object_name'] ?? '' ) );
		}

		if ( $model_id <= 0 || '' === $data_type || '' === $object_name ) {
			return new \WP_Error(
				'invalid_context',
				__( 'Provide rule_id or model_id + data_type + object_name.', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$object_type = $this->map_rule_data_type_to_object_type( $data_type );
		if ( '' === $object_type ) {
			return new \WP_Error(
				'invalid_data_type',
				__( 'Unsupported data_type for dependency lookup.', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$source_object = \WPTSALL\Models\Services\Model_Object_Service::get_object_by_type(
			$model_id,
			$object_type,
			$object_name
		);
		if ( ! $source_object ) {
			return rest_ensure_response(
				array(
					'success'       => true,
					'model_id'      => $model_id,
					'rule_id'       => $rule_id > 0 ? $rule_id : null,
					'data_type'     => $data_type,
					'object_name'   => $object_name,
					'object_type'   => $object_type,
					'source_object' => null,
					'summary'       => array(
						'total'      => 0,
						'resolved'   => 0,
						'unresolved' => 0,
						'external'   => 0,
						'invalid'    => 0,
					),
					'dependencies'  => array(),
					'note'          => __( 'No template object found for this rule binding.', 'wpmmcc-ats' ),
				)
			);
		}

		$source_object_id = (int) ( $source_object['id'] ?? 0 );
		$model_deps       = \WPTSALL\Models\Services\Model_Object_Dependency_Service::get_model_dependencies( $model_id );
		$deps             = array_values(
			array_filter(
				$model_deps,
				function ( $row ) use ( $source_object_id ) {
					return (int) ( $row['source_object_id'] ?? 0 ) === $source_object_id;
				}
			)
		);

		$summary = array(
			'total'      => count( $deps ),
			'resolved'   => 0,
			'unresolved' => 0,
			'external'   => 0,
			'invalid'    => 0,
		);

		$model_rules = Translation_Rule_Service::get_model_rules( $model_id );
		$rule_map    = array();
		foreach ( (array) $model_rules as $model_rule ) {
			$rk_data_type   = sanitize_key( (string) ( $model_rule['data_type'] ?? '' ) );
			$rk_object_name = sanitize_key( (string) ( $model_rule['object_name'] ?? '' ) );
			if ( '' === $rk_data_type || '' === $rk_object_name ) {
				continue;
			}
			$rule_map[ $rk_data_type . ':' . $rk_object_name ] = (int) ( $model_rule['id'] ?? 0 );
		}

		$admin_rule_base = admin_url( 'admin.php' );
		$formatted_deps  = array();

		foreach ( $deps as $dep ) {
			$status = sanitize_key( (string) ( $dep['status'] ?? '' ) );
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}

			$target_object_type = sanitize_key( (string) ( $dep['target_object_type'] ?? '' ) );
			$target_object_name = sanitize_key( (string) ( $dep['target_object_name'] ?? '' ) );
			$target_data_type   = '';
			if ( 'post_type' === $target_object_type ) {
				$target_data_type = 'post';
			} elseif ( 'taxonomy' === $target_object_type ) {
				$target_data_type = 'term';
			} elseif ( 'custom_table' === $target_object_type ) {
				$target_data_type = 'custom_table';
			}

			$target_rule_id = 0;
			if ( '' !== $target_data_type && '' !== $target_object_name ) {
				$target_rule_id = (int) ( $rule_map[ $target_data_type . ':' . $target_object_name ] ?? 0 );
			}

			$target_rule_edit_url = null;
			if ( $target_rule_id > 0 ) {
				$target_rule_edit_url = add_query_arg(
					array(
						'page'     => 'wpmmcc-ats',
						'action'   => 'edit_rule',
						'model_id' => $model_id,
						'rule_id'  => $target_rule_id,
					),
					$admin_rule_base
				);
			}

			$formatted_deps[] = array(
				'id'                => (int) ( $dep['id'] ?? 0 ),
				'source_field_key'  => sanitize_text_field( (string) ( $dep['source_field_key'] ?? '' ) ),
				'reference_type'    => sanitize_key( (string) ( $dep['reference_type'] ?? '' ) ),
				'reference_target'  => sanitize_text_field( (string) ( $dep['reference_target'] ?? '' ) ),
				'status'            => $status,
				'target_object_type'=> $target_object_type,
				'target_object_name'=> $target_object_name,
				'target_data_type'  => $target_data_type,
				'target_rule_id'    => $target_rule_id,
				'target_rule_edit_url' => $target_rule_edit_url,
				'resolution_note'   => sanitize_text_field( (string) ( $dep['resolution_note'] ?? '' ) ),
				'source_origin'     => sanitize_key( (string) ( $dep['source_origin'] ?? '' ) ),
			);
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'model_id'      => $model_id,
				'rule_id'       => $rule_id > 0 ? $rule_id : (int) ( $rule['id'] ?? 0 ),
				'data_type'     => $data_type,
				'object_name'   => $object_name,
				'object_type'   => $object_type,
				'source_object' => array(
					'id'          => $source_object_id,
					'object_type' => sanitize_key( (string) ( $source_object['object_type'] ?? '' ) ),
					'object_name' => sanitize_key( (string) ( $source_object['object_name'] ?? '' ) ),
				),
				'summary'       => $summary,
				'dependencies'  => $formatted_deps,
			)
		);
	}

	/**
	 * Map rule data_type to model object_type.
	 *
	 * @since 1.6.0
	 *
	 * @param string $data_type Rule data type.
	 * @return string Object type or empty string.
	 */
	private function map_rule_data_type_to_object_type( string $data_type ): string {
		$data_type = sanitize_key( $data_type );
		if ( 'post' === $data_type ) {
			return 'post_type';
		}
		if ( 'term' === $data_type || 'taxonomy' === $data_type ) {
			return 'taxonomy';
		}
		if ( 'option' === $data_type ) {
			return 'option';
		}
		if ( 'custom_table' === $data_type ) {
			return 'custom_table';
		}
		return '';
	}

	/**
	 * Create new rule
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_rule( $request ) {
		$model_id = (int) $request->get_param( 'model_id' );
		$data     = $request->get_json_params();

		if ( empty( $data ) ) {
			$data = $request->get_params();
		}

		$result = Translation_Rule_Service::create_rule( $model_id, $data );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			wptsall_log_error(
				'models-api',
				'create_rule failed',
				array(
					'model_id' => $model_id,
					'data'     => $data,
					'error'    => $result->get_error_message(),
				)
			);
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array(
					'status' => 400,
					'errors' => $error_data['errors'] ?? array(),
				)
			);
		}

		$rule = Translation_Rule_Service::get_rule( $result );

		$response_data = array(
			'success' => true,
			'id'      => $result,
			'rule'    => $rule,
		);

		// E5: Post-save validation (Remind Mode — never blocks saving).
		try {
			require_once dirname( __DIR__ ) . '/services/class-simulation-validator.php';

			$validator       = new Simulation_Validator();
			$rule_validation = $validator->validate_rule( $result );

			$template_validation = null;
			$model_id            = (int) ( $rule['model_id'] ?? 0 );
			if ( $model_id > 0 ) {
				$template_validation = $validator->validate_template( $model_id );
			}

			$response_data['validation'] = array(
				'template' => $template_validation,
				'rule'     => $rule_validation,
			);
		} catch ( \Exception $e ) {
			// Never let validation errors block the save.
			$response_data['validation'] = null;
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Update rule
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_rule( $request ) {
		$rule_id = (int) $request->get_param( 'id' );
		$data    = $request->get_json_params();

		if ( empty( $data ) ) {
			$data = $request->get_params();
		}

		// Admin edits are user-owned drafts: later scans must not overwrite them.
		if ( isset( $data['field_capabilities'] ) && ! array_key_exists( 'auto_detected', $data ) ) {
			$data['auto_detected'] = 0;
		}

		$result = Translation_Rule_Service::update_rule( $rule_id, $data );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array(
					'status' => 400,
					'errors' => $error_data['errors'] ?? array(),
				)
			);
		}

		$rule = Translation_Rule_Service::get_rule( $rule_id );

		$response_data = array(
			'success' => true,
			'rule'    => $rule,
		);

		// E5: Post-save validation (Remind Mode — never blocks saving).
		try {
			require_once dirname( __DIR__ ) . '/services/class-simulation-validator.php';

			$validator       = new Simulation_Validator();
			$rule_validation = $validator->validate_rule( $rule_id );

			// Also validate the template if model_id is available.
			$template_validation = null;
			$model_id            = (int) ( $rule['model_id'] ?? 0 );
			if ( $model_id > 0 ) {
				$template_validation = $validator->validate_template( $model_id );
			}

			$response_data['validation'] = array(
				'template' => $template_validation,
				'rule'     => $rule_validation,
			);
		} catch ( \Exception $e ) {
			// Never let validation errors block the save.
			$response_data['validation'] = null;
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Delete rule
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_rule( $request ) {
		$rule_id = (int) $request->get_param( 'id' );
		$result  = Translation_Rule_Service::delete_rule( $rule_id );

		if ( is_wp_error( $result ) ) {
			$status = 'not_found' === $result->get_error_code() ? 404 : 400;
			wptsall_log_error(
				'models-api',
				'delete_rule failed',
				array(
					'rule_id' => $rule_id,
					'error'   => $result->get_error_message(),
				)
			);
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => $status )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'deleted' => true,
			)
		);
	}

	/**
	 * Toggle rule active status
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_rule( $request ) {
		$rule_id = (int) $request->get_param( 'id' );
		$result  = Translation_Rule_Service::toggle_rule_active( $rule_id );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$rule = Translation_Rule_Service::get_rule( $rule_id );

		return rest_ensure_response(
			array(
				'success'   => true,
				'is_active' => (bool) $rule['is_active'],
			)
		);
	}

	/**
	 * Get field_capabilities for a single rule.
	 *
	 * @since 1.0.5
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_rule_field_capabilities( $request ) {
		$rule_id = (int) $request->get_param( 'id' );
		$rule    = Translation_Rule_Service::get_rule( $rule_id );

		if ( ! $rule ) {
			return new \WP_Error( 'not_found', 'Rule not found', array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'success'            => true,
				'rule_id'            => $rule_id,
				'field_capabilities' => $rule['field_capabilities'] ?? array(),
			)
		);
	}

	/**
	 * Patch field_capabilities for a single rule (H13: field editing API).
	 *
	 * Accepts a "fields" object keyed by field_name. Each value may contain:
	 * - type: one of translate/sync/id_mapping/compute/no_sync (five-level classification)
	 * - direction: one_way or bidirectional
	 * - enabled: boolean
	 *
	 * Fields are merged into the existing field_capabilities; unmentioned fields are preserved.
	 *
	 * @since 1.0.5
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function patch_rule_field_capabilities( $request ) {
		$rule_id = (int) $request->get_param( 'id' );
		$rule    = Translation_Rule_Service::get_rule( $rule_id );

		if ( ! $rule ) {
			return new \WP_Error( 'not_found', 'Rule not found', array( 'status' => 404 ) );
		}

		$fields_patch = $request->get_param( 'fields' );
		if ( ! is_array( $fields_patch ) || empty( $fields_patch ) ) {
			return new \WP_Error( 'invalid_fields', 'Invalid field data', array( 'status' => 400 ) );
		}

		// Validate field type values against the five-level classification.
		$allowed_types = array( 'translate', 'sync', 'copy_once', 'copy-once', 'id_mapping', 'mapping', 'compute', 'no_sync', 'skip' );
		$errors        = array();

		foreach ( $fields_patch as $field_name => $field_config ) {
			if ( ! is_array( $field_config ) ) {
				$errors[] = sprintf( 'Field "%s" config must be an object', $field_name );
				continue;
			}
			if ( isset( $field_config['type'] ) && ! in_array( $field_config['type'], $allowed_types, true ) ) {
				$errors[] = sprintf(
					'Field "%s" has invalid type "%s". Allowed: %s',
					$field_name,
					$field_config['type'],
					implode( ', ', $allowed_types )
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'validation_failed', 'Field validation failed', array( 'status' => 400, 'errors' => $errors ) );
		}

		// Merge patch into existing field_capabilities.
		$existing_capabilities = is_array( $rule['field_capabilities'] ) ? $rule['field_capabilities'] : array();

		foreach ( $fields_patch as $field_name => $field_config ) {
			$sanitized_name = sanitize_text_field( $field_name );
			if ( ! isset( $existing_capabilities[ $sanitized_name ] ) ) {
				$existing_capabilities[ $sanitized_name ] = array();
			}
			if ( isset( $field_config['type'] ) && class_exists( '\\WPTSALL\\Models\\Services\\Field_Capability' ) ) {
				$field_config['type'] = \WPTSALL\Models\Services\Field_Capability::normalize_type( (string) $field_config['type'] );
			}
			$existing_capabilities[ $sanitized_name ] = array_merge(
				$existing_capabilities[ $sanitized_name ],
				$field_config
			);
		}

		$result = Translation_Rule_Service::update_rule(
			$rule_id,
			array( 'field_capabilities' => $existing_capabilities )
		);

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			if ( ! is_array( $error_data ) ) {
				$error_data = array();
			}
			if ( empty( $error_data['status'] ) ) {
				$error_data['status'] = 400;
			}
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$error_data
			);
		}

		$updated_rule = Translation_Rule_Service::get_rule( $rule_id );

		return rest_ensure_response(
			array(
				'success'            => true,
				'rule_id'            => $rule_id,
				'field_capabilities' => $updated_rule['field_capabilities'] ?? array(),
			)
		);
	}

	/**
	 * Validate rule without saving
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function validate_rule( $request ) {
		$data     = $request->get_json_params();
		$rule_id  = (int) ( $data['rule_id'] ?? ( $data['id'] ?? 0 ) );

		if ( $rule_id > 0 ) {
			// Validate an existing rule by ID using the new service.
			$service    = new Rule_Validation_Service();
			$validation = $service->validate_all( $rule_id );
		} else {
			// No rule_id provided — cannot validate without a persisted rule.
			$validation = array(
				'valid'  => false,
				'errors' => array( 'No rule_id provided. Save the rule first, then validate.' ),
			);
		}

		return rest_ensure_response(
			array(
				'valid'  => $validation['valid'] ?? false,
				'errors' => $validation['errors'] ?? array(),
			)
		);
	}

	// ==========================================
	// E6: URL Real Data Verification
	// ==========================================

	/**
	 * Verify rule coverage against a real URL.
	 *
	 * Resolves the URL to a post object, gets all actual fields (core + meta),
	 * compares against template fields and rule field_capabilities, and returns
	 * a coverage report with suggestions for uncovered fields.
	 *
	 * @since 1.4.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify_with_url( $request ) {
		$url                   = $request->get_param( 'url' );
		$include_relationships = (bool) $request->get_param( 'include_relationships' );

		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_url', __( 'URL is required', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		// 1. Resolve URL to post object.
		$post_id = url_to_postid( $url );

		// 1b. Term URL fallback: try to resolve as a taxonomy term archive.
		if ( ! $post_id ) {
			$term_result = $this->resolve_term_url( $url );
			if ( $term_result ) {
				return rest_ensure_response( $term_result );
			}

			return new \WP_Error(
				'url_not_resolved',
				__( 'Could not resolve URL to a WordPress post or term. Ensure the URL points to a valid post, page, custom post type, or taxonomy archive.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		// 2. Get actual fields from the post.
		$actual_fields = $this->get_actual_post_fields( $post );

		// 3. Find the matching model for this post type.
		$model    = null;
		$model_id = 0;
		$rules    = array();

		// Search models that handle this post type.
		$all_models = Translation_Rule_Service::get_models( array( 'per_page' => 200 ) );
		$models_list = $all_models['items'] ?? $all_models;
		if ( is_array( $models_list ) ) {
			foreach ( $models_list as $m ) {
				$post_types = $m['post_types'] ?? array();
				foreach ( $post_types as $pt ) {
					$pt_name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : (string) $pt;
					if ( $pt_name === $post->post_type ) {
						$model    = $m;
						$model_id = (int) ( $m['id'] ?? 0 );
						break 2;
					}
				}
			}
		}

		// 4. Get template fields if model found.
		$template_fields = array();
		if ( $model_id > 0 ) {
			require_once dirname( __DIR__ ) . '/services/class-model-object-service.php';

			$objects = \WPTSALL\Models\Services\Model_Object_Service::get_objects_for_model( $model_id );
			foreach ( $objects as $obj ) {
				$obj_type = $obj['object_type'] ?? '';
				$obj_name = $obj['object_name'] ?? '';
				if ( 'post_type' === $obj_type && $obj_name === $post->post_type ) {
					$fields = \WPTSALL\Models\Services\Model_Object_Service::get_fields_for_object( (int) $obj['id'] );
					foreach ( $fields as $f ) {
						$template_fields[ $f['field_key'] ] = array(
							'field_kind' => $f['field_kind'] ?? '',
							'source'     => $f['source'] ?? '',
							'data_type'  => $f['data_type'] ?? '',
						);
					}
				}
			}

			// 5. Get rule field_capabilities.
			$rules = Translation_Rule_Service::get_model_rules( $model_id );
		}

		$rule_fields = array();
		foreach ( $rules as $rule ) {
			$caps = $rule['field_capabilities'] ?? array();
			if ( ! is_array( $caps ) ) {
				continue;
			}
			foreach ( $caps as $field_name => $config ) {
				if ( ! isset( $rule_fields[ $field_name ] ) ) {
					$rule_fields[ $field_name ] = array(
						'type'    => $config['type'] ?? 'sync',
						'rule_id' => (int) ( $rule['id'] ?? 0 ),
					);
				}
			}
		}

		// 6. Calculate coverage.
		$total_actual    = count( $actual_fields );
		$covered_by_tpl  = 0;
		$covered_by_rule = 0;
		$uncovered       = array();
		$coverage_detail = array();

		foreach ( $actual_fields as $field_name => $field_info ) {
			$in_template = isset( $template_fields[ $field_name ] );
			$in_rules    = isset( $rule_fields[ $field_name ] );

			if ( $in_template ) {
				++$covered_by_tpl;
			}
			if ( $in_rules ) {
				++$covered_by_rule;
			}

			$coverage_detail[ $field_name ] = array(
				'source'      => $field_info['source'],
				'in_template' => $in_template,
				'in_rules'    => $in_rules,
			);

			if ( ! $in_template && ! $in_rules ) {
				$uncovered[ $field_name ] = $field_info;
			}
		}

		// 7. Use Smart_Field_Classifier to suggest types for uncovered fields.
		$suggestions = array();
		if ( ! empty( $uncovered ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/core/classifiers/class-smart-field-classifier.php';

			// Find the target rule for this post type.
			$target_rule_id = 0;
			foreach ( $rules as $r ) {
				if ( ( $r['data_type'] ?? '' ) === 'post' && ( $r['object_name'] ?? '' ) === $post->post_type ) {
					$target_rule_id = (int) ( $r['id'] ?? 0 );
					break;
				}
			}

			foreach ( $uncovered as $field_name => $field_info ) {
				$proposed   = \WPTSALL\Core\Smart_Field_Classifier::propose_field_capability( $field_name, 'post' );
				$suggestion = array(
					'field_name'          => $field_name,
					'field_source'        => $field_info['source'],
					'sample_value'        => $field_info['sample_value'] ?? '',
					'suggested_type'      => $proposed['type'] ?? 'sync',
					'confidence'          => $proposed['confidence'] ?? 0,
					'proposed_capability' => $proposed,
					'target_rule_id'      => $target_rule_id,
				);
				$suggestions[] = $suggestion;
			}
		}

		// 7b. Enhance coverage_detail with id_mapping resolution (when relationships requested).
		if ( $include_relationships ) {
			foreach ( $coverage_detail as $field_name => &$detail ) {
				if ( ! isset( $rule_fields[ $field_name ] ) ) {
					continue;
				}
				$rf = $rule_fields[ $field_name ];
				if ( 'id_mapping' === ( $rf['type'] ?? '' ) ) {
					// Look up the full capability from the rule.
					$full_cap = null;
					foreach ( $rules as $r ) {
						$caps_arr = $r['field_capabilities'] ?? array();
						if ( isset( $caps_arr[ $field_name ] ) ) {
							$full_cap = $caps_arr[ $field_name ];
							break;
						}
					}
					if ( $full_cap ) {
						$detail['mapping_resolution'] = array(
							'reference_type' => $full_cap['reference_type'] ?? 'unknown',
							'value_format'   => $full_cap['value_format'] ?? 'scalar',
						);
					}
				}
			}
			unset( $detail );
		}

		$template_rate = $total_actual > 0 ? round( ( $covered_by_tpl / $total_actual ) * 100, 1 ) : 0;
		$rule_rate     = $total_actual > 0 ? round( ( $covered_by_rule / $total_actual ) * 100, 1 ) : 0;

		$response_data = array(
			'success'  => true,
			'post'     => array(
				'id'        => $post->ID,
				'post_type' => $post->post_type,
				'title'     => $post->post_title,
				'url'       => $url,
			),
			'model'    => $model ? array(
				'id'          => $model_id,
				'plugin_slug' => $model['plugin_slug'] ?? '',
				'plugin_name' => $model['plugin_name'] ?? '',
			) : null,
			'coverage' => array(
				'total_actual_fields' => $total_actual,
				'covered_by_template' => $covered_by_tpl,
				'covered_by_rules'    => $covered_by_rule,
				'uncovered_count'     => count( $uncovered ),
				'template_rate'       => $template_rate,
				'rule_rate'           => $rule_rate,
			),
			'detail'      => $coverage_detail,
			'suggestions' => $suggestions,
		);

		// 8. Taxonomy relationship info (opt-in).
		if ( $include_relationships ) {
			$response_data['taxonomy_info'] = $this->get_post_taxonomy_coverage( $post, $model_id, $rules );
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Get taxonomy coverage information for a post.
	 *
	 * For each taxonomy associated with the post's post_type, checks whether a
	 * corresponding term rule exists and whether it's in related_taxonomies.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post     Post object.
	 * @param int      $model_id Model ID.
	 * @param array    $rules    All rules for this model.
	 * @return array Taxonomy coverage entries.
	 */
	private function get_post_taxonomy_coverage( $post, int $model_id, array $rules ): array {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		$result     = array();

		// Gather related_taxonomies from the post-type rule.
		$related_taxonomies = array();
		foreach ( $rules as $r ) {
			if ( ( $r['data_type'] ?? '' ) === 'post' && ( $r['object_name'] ?? '' ) === $post->post_type ) {
				$related_taxonomies = $r['related_taxonomies'] ?? array();
				break;
			}
		}

		foreach ( $taxonomies as $tax_name => $tax_obj ) {
			if ( ! $tax_obj->public ) {
				continue;
			}

			$terms      = wp_get_post_terms( $post->ID, $tax_name );
			$term_count = is_array( $terms ) ? count( $terms ) : 0;
			$term_names = array();
			if ( is_array( $terms ) ) {
				foreach ( array_slice( $terms, 0, 5 ) as $t ) {
					$term_names[] = $t->name;
				}
			}

			// Check if a term rule exists for this taxonomy.
			$has_term_rule = false;
			$term_rule_id  = 0;
			foreach ( $rules as $r ) {
				if ( ( $r['data_type'] ?? '' ) === 'term' && ( $r['object_name'] ?? '' ) === $tax_name ) {
					$has_term_rule = true;
					$term_rule_id  = (int) ( $r['id'] ?? 0 );
					break;
				}
			}

			$result[] = array(
				'taxonomy'       => $tax_name,
				'label'          => $tax_obj->labels->name ?? $tax_name,
				'term_count'     => $term_count,
				'sample_terms'   => $term_names,
				'has_term_rule'  => $has_term_rule,
				'term_rule_id'   => $term_rule_id,
				'in_related'     => in_array( $tax_name, $related_taxonomies, true ),
			);
		}

		return $result;
	}

	/**
	 * Try to resolve a URL as a taxonomy term archive.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url URL to resolve.
	 * @return array|null Term coverage result, or null if not a term URL.
	 */
	private function resolve_term_url( string $url ): ?array {
		$parsed = wp_parse_url( $url );
		$path   = $parsed['path'] ?? '';
		$query  = array();
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query );
		}

		$term     = null;
		$taxonomy = '';

		// Try query-string format: ?taxonomy=category&term=slug
		if ( ! empty( $query['taxonomy'] ) && ! empty( $query['term'] ) ) {
			$taxonomy = sanitize_key( $query['taxonomy'] );
			$term     = get_term_by( 'slug', sanitize_text_field( $query['term'] ), $taxonomy );
		}

		// Try query-string format: ?tag=slug, ?category_name=slug
		if ( ! $term && ! empty( $query['tag'] ) ) {
			$term     = get_term_by( 'slug', sanitize_text_field( $query['tag'] ), 'post_tag' );
			$taxonomy = 'post_tag';
		}
		if ( ! $term && ! empty( $query['category_name'] ) ) {
			$term     = get_term_by( 'slug', sanitize_text_field( $query['category_name'] ), 'category' );
			$taxonomy = 'category';
		}

		// Try pretty permalink: /category/slug/ or /tax-name/slug/
		if ( ! $term && ! empty( $path ) ) {
			$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
			if ( count( $segments ) >= 2 ) {
				$potential_tax  = $segments[ count( $segments ) - 2 ];
				$potential_slug = $segments[ count( $segments ) - 1 ];

				// Check known taxonomy rewrite slugs.
				$all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
				foreach ( $all_taxonomies as $tax_obj ) {
					$rewrite_slug = $tax_obj->rewrite['slug'] ?? $tax_obj->name;
					if ( $potential_tax === $rewrite_slug || $potential_tax === $tax_obj->name ) {
						$candidate = get_term_by( 'slug', $potential_slug, $tax_obj->name );
						if ( $candidate ) {
							$term     = $candidate;
							$taxonomy = $tax_obj->name;
							break;
						}
					}
				}
			}
		}

		if ( ! $term || ! $taxonomy ) {
			return null;
		}

		// Build a term-level coverage report.
		$term_meta   = get_term_meta( $term->term_id, '', true );
		$core_fields = array(
			'name'        => array( 'source' => 'core', 'sample_value' => $term->name ),
			'description' => array( 'source' => 'core', 'sample_value' => mb_substr( $term->description, 0, 100 ) ),
			'slug'        => array( 'source' => 'core', 'sample_value' => $term->slug ),
		);

		$meta_fields = array();
		if ( is_array( $term_meta ) ) {
			foreach ( $term_meta as $mk => $mv ) {
				if ( 0 === strpos( $mk, '_' ) && in_array( $mk, array( '_edit_lock', '_edit_last' ), true ) ) {
					continue;
				}
				$sample = is_array( $mv ) && ! empty( $mv ) ? $mv[0] : '';
				$meta_fields[ $mk ] = array(
					'source'       => 'meta',
					'sample_value' => is_string( $sample ) ? mb_substr( $sample, 0, 100 ) : (string) $sample,
				);
			}
		}

		$actual_fields = array_merge( $core_fields, $meta_fields );

		// Find the matching model and term rule.
		$model_id   = 0;
		$rule_id    = 0;
		$rule_caps  = array();
		$all_models = Translation_Rule_Service::get_models( array( 'per_page' => 200 ) );
		$models_list = $all_models['items'] ?? $all_models;
		if ( is_array( $models_list ) ) {
			foreach ( $models_list as $m ) {
				$mid   = (int) ( $m['id'] ?? 0 );
				$rules = Translation_Rule_Service::get_model_rules( $mid );
				foreach ( $rules as $r ) {
					if ( ( $r['data_type'] ?? '' ) === 'term' && ( $r['object_name'] ?? '' ) === $taxonomy ) {
						$model_id  = $mid;
						$rule_id   = (int) ( $r['id'] ?? 0 );
						$rule_caps = $r['field_capabilities'] ?? array();
						break 2;
					}
				}
			}
		}

		$total       = count( $actual_fields );
		$covered     = 0;
		$detail      = array();
		$suggestions = array();

		foreach ( $actual_fields as $fk => $fi ) {
			$in_rule = isset( $rule_caps[ $fk ] );
			if ( $in_rule ) {
				++$covered;
			}
			$detail[ $fk ] = array(
				'source'      => $fi['source'],
				'in_template' => false,
				'in_rules'    => $in_rule,
			);

			if ( ! $in_rule ) {
				require_once dirname( dirname( __DIR__ ) ) . '/core/classifiers/class-smart-field-classifier.php';
				$proposed     = \WPTSALL\Core\Smart_Field_Classifier::propose_field_capability( $fk, 'term' );
				$suggestions[] = array(
					'field_name'          => $fk,
					'field_source'        => $fi['source'],
					'sample_value'        => $fi['sample_value'] ?? '',
					'suggested_type'      => $proposed['type'] ?? 'sync',
					'confidence'          => $proposed['confidence'] ?? 0,
					'proposed_capability' => $proposed,
					'target_rule_id'      => $rule_id,
				);
			}
		}

		$rate = $total > 0 ? round( ( $covered / $total ) * 100, 1 ) : 0;

		return array(
			'success'     => true,
			'object_type' => 'term',
			'term'        => array(
				'term_id'  => $term->term_id,
				'taxonomy' => $taxonomy,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'url'      => $url,
			),
			'model'    => $model_id ? array( 'id' => $model_id ) : null,
			'coverage' => array(
				'total_actual_fields' => $total,
				'covered_by_rules'    => $covered,
				'uncovered_count'     => $total - $covered,
				'rule_rate'           => $rate,
			),
			'detail'      => $detail,
			'suggestions' => $suggestions,
		);
	}

	/**
	 * Get actual fields from a post object (core columns + meta).
	 *
	 * Filters out internal WordPress meta keys that are not relevant for translation.
	 *
	 * @since 1.4.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Keyed by field name => { source, sample_value }.
	 */
	private function get_actual_post_fields( $post ) {
		$fields = array();

		// Core post columns.
		$core_columns = array(
			'post_title', 'post_content', 'post_excerpt', 'post_name',
			'post_status', 'post_date', 'post_date_gmt', 'post_modified',
			'post_modified_gmt', 'post_author', 'post_parent',
			'menu_order', 'comment_status', 'ping_status', 'post_mime_type',
		);

		foreach ( $core_columns as $col ) {
			$value = $post->$col ?? '';
			// Skip empty non-essential fields.
			if ( '' === $value && ! in_array( $col, array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ), true ) ) {
				continue;
			}
			$fields[ $col ] = array(
				'source'       => 'core',
				'sample_value' => is_string( $value ) ? mb_substr( $value, 0, 100 ) : (string) $value,
			);
		}

		// All post meta.
		$all_meta = get_post_meta( $post->ID, '', true );
		if ( is_array( $all_meta ) ) {
			// Internal WP meta keys to filter out.
			$skip_patterns = array(
				'_edit_lock',
				'_edit_last',
				'_wp_old_slug',
				'_wp_old_date',
				'_wp_trash_meta_status',
				'_wp_trash_meta_time',
				'_wp_desired_post_slug',
				'_encloseme',
				'_pingme',
			);

			foreach ( $all_meta as $meta_key => $meta_values ) {
				// Skip internal WordPress meta.
				if ( in_array( $meta_key, $skip_patterns, true ) ) {
					continue;
				}
				// Skip transient-like keys.
				if ( 0 === strpos( $meta_key, '_transient_' ) || 0 === strpos( $meta_key, '_site_transient_' ) ) {
					continue;
				}

				$sample = is_array( $meta_values ) && ! empty( $meta_values ) ? $meta_values[0] : '';
				$fields[ $meta_key ] = array(
					'source'       => 'meta',
					'sample_value' => is_string( $sample ) ? mb_substr( $sample, 0, 100 ) : (string) $sample,
				);
			}
		}

		return $fields;
	}

	// ==========================================
	// E7: Diagnose Endpoint
	// ==========================================

	/**
	 * Run full diagnostic validation for a model.
	 *
	 * Calls Simulation_Validator::validate_full() which performs
	 * both template-layer and rule-layer validation, returning
	 * a complete diagnostic report.
	 *
	 * @since 1.4.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function diagnose_model( $request ) {
		$model_id = (int) $request->get_param( 'model_id' );

		// Verify model exists.
		$model = Translation_Rule_Service::get_model( $model_id );
		if ( ! $model ) {
			return new \WP_Error( 'not_found', __( 'Model not found', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		require_once dirname( __DIR__ ) . '/services/class-simulation-validator.php';

		try {
			$validator = new Simulation_Validator();
			$report    = $validator->validate_full( $model_id );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'validation_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'model'    => array(
					'id'          => $model_id,
					'plugin_slug' => $model['plugin_slug'] ?? '',
					'plugin_name' => $model['plugin_name'] ?? '',
					'status'      => $model['status'] ?? '',
				),
				'report'   => $report,
				'summary'  => $report['summary'] ?? array(),
			)
		);
	}

	/**
	 * Get available fields for data type
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_available_fields( $request ) {
		$data_type   = $request->get_param( 'data_type' );
		$object_name = $request->get_param( 'object_name' );
		$model_id    = (int) $request->get_param( 'model_id' );

		$fields = Translation_Rule_Service::get_available_fields( $data_type, $object_name, $model_id );

		return rest_ensure_response( $fields );
	}

	/**
	 * Get URL types and data types options
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_options( $request ) {
		return rest_ensure_response(
			array(
				'url_types'  => array(
					'single'     => 'Single content page',
					'archive'    => 'Archive/list page',
					'taxonomy'   => 'Taxonomy/tag archive',
					'author'     => 'Author archive page',
					'date'       => 'Date archive page',
					'search'     => 'Search results page',
					'home'       => 'Home/blog page',
					'page'       => 'Static page',
					'attachment' => 'Attachment page',
					'embed'      => 'Embed page',
					'feed'       => 'RSS/Feed',
					'endpoint'   => 'Custom endpoint',
				),
				'data_types' => array(
					'post'         => 'Posts/custom types (wp_posts)',
					'term'         => 'Terms/tags (wp_terms)',
					'user'         => 'Users (wp_users)',
					'comment'      => 'Comments (wp_comments)',
					'option'       => 'Options/settings (wp_options)',
					'custom_table' => 'Plugin custom table',
				),
			)
		);
	}

	/**
	 * Get model statistics
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_stats( $request ) {
		require_once dirname( __DIR__ ) . '/database/schema-models.php';

		$stats = wptsall_get_models_stats();

		return rest_ensure_response( $stats );
	}

	/**
	 * Scan all plugins
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_all_plugins( $request ) {
		require_once dirname( __DIR__ ) . '/scanners/class-model-scanner-v2.php';
		require_once dirname( __DIR__ ) . '/scanners/class-runtime-tracker.php';

		// Initialize runtime tracker
		\WPTSALL\Models\Scanners\Runtime_Tracker::init();

		$scanner = new \WPTSALL\Models\Scanners\Model_Scanner_V2();
		// Manual "Scan All" button: incremental mode — do not auto-create/delete rules.
		$scanner->set_mode( 'incremental' );
		$results = $scanner->scan_all_plugins();

		return rest_ensure_response(
			array(
				'success'        => true,
				'models_created' => $results['models_created'],
				'models_updated' => $results['models_updated'],
				'models_failed'  => $results['models_failed'],
				'total_rules'    => $results['total_rules'],
				'details'        => $results['details'],
			)
		);
	}

	/**
	 * Scan single plugin
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_plugin( $request ) {
		$plugin_slug = sanitize_key( $request->get_param( 'plugin_slug' ) );
		$save        = $request->get_param( 'save' );

		if ( empty( $plugin_slug ) ) {
			wptsall_log_error( 'models-api', 'scan_plugin failed: missing plugin_slug' );
			return new \WP_Error( 'missing_plugin', 'Please specify plugin slug', array( 'status' => 400 ) );
		}

		require_once dirname( __DIR__ ) . '/scanners/class-model-scanner-v2.php';
		require_once dirname( __DIR__ ) . '/scanners/class-runtime-tracker.php';

		// Initialize runtime tracker
		\WPTSALL\Models\Scanners\Runtime_Tracker::init();

		$scanner = new \WPTSALL\Models\Scanners\Model_Scanner_V2();
		// Manual single plugin scan: incremental mode — do not auto-create/delete rules.
		$scanner->set_mode( 'incremental' );
		$scan_result = $scanner->scan_plugin( $plugin_slug );

		if ( is_wp_error( $scan_result ) ) {
			wptsall_log_error(
				'models-api',
				'scan_plugin failed',
				array(
					'plugin_slug' => $plugin_slug,
					'error'       => $scan_result->get_error_message(),
				)
			);
			return new \WP_Error(
				$scan_result->get_error_code(),
				$scan_result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// If save=false, return preview only
		if ( false === $save ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'preview' => true,
					'result'  => $scan_result,
				)
			);
		}

		// Save the result
		$save_result = $scanner->save_scan_result( $scan_result );

		if ( is_wp_error( $save_result ) ) {
			wptsall_log_error(
				'models-api',
				'scan_plugin save failed',
				array(
					'plugin_slug' => $plugin_slug,
					'error'       => $save_result->get_error_message(),
				)
			);
			return new \WP_Error(
				$save_result->get_error_code(),
				$save_result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Get the saved model
		$model = Translation_Rule_Service::get_model( $save_result['model_id'] );

		return rest_ensure_response(
			array(
				'success'    => true,
				'model_id'   => $save_result['model_id'],
				'is_new'     => $save_result['is_new'],
				'rule_count' => $save_result['rule_count'],
				'model'      => $model,
			)
		);
	}

	/**
	 * Detect available fields for a data type and object
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function detect_fields( $request ) {
		$data_type   = sanitize_key( $request->get_param( 'data_type' ) );
		$object_name = sanitize_key( $request->get_param( 'object_name' ) );

		if ( empty( $data_type ) || empty( $object_name ) ) {
			return new \WP_Error(
				'missing_parameters',
				'Missing required parameters',
				array( 'status' => 400 )
			);
		}

		$fields = array();

		// Detect fields based on data type
		switch ( $data_type ) {
			case 'post':
				$fields = $this->detect_post_fields( $object_name );
				break;
			case 'taxonomy':
				$fields = $this->detect_taxonomy_fields( $object_name );
				break;
			case 'user':
				$fields = $this->detect_user_fields();
				break;
			case 'comment':
				$fields = $this->detect_comment_fields();
				break;
			default:
				return new \WP_Error(
					'invalid_data_type',
					'Unsupported data type',
					array( 'status' => 400 )
				);
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'data_type'   => $data_type,
				'object_name' => $object_name,
				'fields'      => $fields,
			)
		);
	}

	/**
	 * Detect post type fields
	 *
	 * @param string $post_type Post type name.
	 * @return array
	 */
	private function detect_post_fields( $post_type ) {
		$fields = array();

		// Core post fields
		$core_fields = array(
			'post_title'   => array(
				'name'        => 'post_title',
				'type'        => 'text',
				'source'      => 'post',
				'description' => 'Post title',
			),
			'post_content' => array(
				'name'        => 'post_content',
				'type'        => 'html',
				'source'      => 'post',
				'description' => 'Post content',
			),
			'post_excerpt' => array(
				'name'        => 'post_excerpt',
				'type'        => 'text',
				'source'      => 'post',
				'description' => 'Post excerpt',
			),
			'post_date'    => array(
				'name'        => 'post_date',
				'type'        => 'datetime',
				'source'      => 'post',
				'description' => 'Publish date',
			),
			'post_status'  => array(
				'name'        => 'post_status',
				'type'        => 'text',
				'source'      => 'post',
				'description' => 'Post status',
			),
			'post_name'    => array(
				'name'        => 'post_name',
				'type'        => 'text',
				'source'      => 'post',
				'description' => 'Slug',
			),
		);

		$fields = array_merge( $fields, $core_fields );

		// Get post meta fields
		global $wpdb;
		$like_prefix = $wpdb->esc_like( '_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT meta_key
				FROM %i pm
				INNER JOIN %i p ON pm.post_id = p.ID
				WHERE p.post_type = %s
				AND meta_key NOT LIKE %s
				ORDER BY meta_key
				LIMIT 100',
				$wpdb->postmeta,
				$wpdb->posts,
				$post_type,
				$like_prefix
			)
		);

		foreach ( $meta_keys as $meta_key ) {
			$fields[] = array(
				'name'        => $meta_key,
				'type'        => 'text',
				'source'      => 'meta',
				'description' => 'Meta: ' . $meta_key,
			);
		}

		// Get taxonomies
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			$fields[] = array(
				'name'        => $taxonomy->name,
				'type'        => 'taxonomy',
				'source'      => 'taxonomy',
				'description' => 'Taxonomy: ' . $taxonomy->labels->name,
			);
		}

		return $fields;
	}

	/**
	 * Detect taxonomy fields
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	private function detect_taxonomy_fields( $taxonomy ) {
		$fields = array();

		// Core taxonomy fields
		$core_fields = array(
			'name'        => array(
				'name'        => 'name',
				'type'        => 'text',
				'source'      => 'term',
				'description' => 'Name',
			),
			'slug'        => array(
				'name'        => 'slug',
				'type'        => 'text',
				'source'      => 'term',
				'description' => 'Slug',
			),
			'description' => array(
				'name'        => 'description',
				'type'        => 'html',
				'source'      => 'term',
				'description' => 'Description',
			),
		);

		$fields = array_merge( $fields, $core_fields );

		// Get term meta fields
		global $wpdb;
		$like_prefix = $wpdb->esc_like( '_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT meta_key
				FROM %i tm
				INNER JOIN %i tt ON tm.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				AND meta_key NOT LIKE %s
				ORDER BY meta_key
				LIMIT 100',
				$wpdb->termmeta,
				$wpdb->term_taxonomy,
				$taxonomy,
				$like_prefix
			)
		);

		foreach ( $meta_keys as $meta_key ) {
			$fields[] = array(
				'name'        => $meta_key,
				'type'        => 'text',
				'source'      => 'meta',
				'description' => 'Meta: ' . $meta_key,
			);
		}

		return $fields;
	}

	/**
	 * Detect user fields
	 *
	 * @return array
	 */
	private function detect_user_fields() {
		$fields = array(
			array(
				'name'        => 'user_login',
				'type'        => 'text',
				'source'      => 'user',
				'description' => 'Login name',
			),
			array(
				'name'        => 'display_name',
				'type'        => 'text',
				'source'      => 'user',
				'description' => 'Display name',
			),
			array(
				'name'        => 'user_email',
				'type'        => 'text',
				'source'      => 'user',
				'description' => 'Email',
			),
		);

		return $fields;
	}

	/**
	 * Detect comment fields
	 *
	 * @return array
	 */
	private function detect_comment_fields() {
		$fields = array(
			array(
				'name'        => 'comment_content',
				'type'        => 'html',
				'source'      => 'comment',
				'description' => 'Comment content',
			),
			array(
				'name'        => 'comment_author',
				'type'        => 'text',
				'source'      => 'comment',
				'description' => 'Comment author',
			),
		);

		return $fields;
	}

	/**
	 * Rescan model
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rescan_model( $request ) {
		$model_id = (int) $request->get_param( 'id' );
		$model    = Translation_Rule_Service::get_model( $model_id );

		if ( ! $model ) {
			wptsall_log_error(
				'models-api',
				'rescan_model failed: model not found',
				array( 'model_id' => $model_id )
			);
			return new \WP_Error( 'not_found', 'Model not found', array( 'status' => 404 ) );
		}

		require_once dirname( __DIR__ ) . '/scanners/class-model-scanner-v2.php';
		require_once dirname( __DIR__ ) . '/scanners/class-runtime-tracker.php';

		// Initialize runtime tracker
		\WPTSALL\Models\Scanners\Runtime_Tracker::init();

		$scanner = new \WPTSALL\Models\Scanners\Model_Scanner_V2();
		// Single model rescan (Trigger C): incremental mode — do not auto-create/delete rules.
		$scanner->set_mode( 'incremental' );
		$scan_result = $scanner->scan_plugin( $model['plugin_slug'] );

		if ( is_wp_error( $scan_result ) ) {
			wptsall_log_error(
				'models-api',
				'rescan_model scan failed',
				array(
					'model_id'    => $model_id,
					'plugin_slug' => $model['plugin_slug'],
					'error'       => $scan_result->get_error_message(),
				)
			);
			return new \WP_Error(
				$scan_result->get_error_code(),
				$scan_result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Save the scan result (this handles update/replace logic)
		$result = $scanner->save_scan_result( $scan_result );

		if ( is_wp_error( $result ) ) {
			wptsall_log_error(
				'models-api',
				'rescan_model save failed',
				array(
					'model_id'    => $model_id,
					'plugin_slug' => $model['plugin_slug'],
					'error'       => $result->get_error_message(),
				)
			);
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$updated_model = Translation_Rule_Service::get_model( $model_id );

		// Count rules from save result
		$rule_count = $result['rule_count'] ?? 0;

		return rest_ensure_response(
			array(
				'success'    => true,
				'model'      => $updated_model,
				'rule_count' => $rule_count,
				'message'    => 'Model has been fully reinitialized',
			)
		);
	}

	/**
	 * Import models
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_models( $request ) {
		$models    = $request->get_param( 'models' );
		$overwrite = $request->get_param( 'overwrite' );

		if ( empty( $models ) || ! is_array( $models ) ) {
			wptsall_log_error( 'models-api', 'import_models failed: invalid data' );
			return new \WP_Error( 'invalid_data', 'Invalid model data', array( 'status' => 400 ) );
		}

		$imported = 0;
		$errors   = array();

		foreach ( $models as $model_data ) {
			// Support both 'slug' and 'plugin_slug' for backward compatibility
			$plugin_slug = $model_data['plugin_slug'] ?? $model_data['slug'] ?? '';
			if ( empty( $plugin_slug ) ) {
				continue;
			}
			$model_data['plugin_slug'] = $plugin_slug;

			// Check if model exists
			$existing = Translation_Rule_Service::get_model_by_plugin_slug( $plugin_slug );

			if ( $existing && ! $overwrite ) {
				$errors[] = sprintf( 'Model %s already exists', $plugin_slug );
				continue;
			}

			// Extract rules if present
			$rules = isset( $model_data['rules'] ) ? $model_data['rules'] : array();
			unset( $model_data['rules'] );
			unset( $model_data['url_rules'] );
			unset( $model_data['id'] );

			if ( $existing ) {
				// Update existing model
				$result   = Translation_Rule_Service::update_model( $existing['id'], $model_data );
				$model_id = $existing['id'];
			} else {
				// Create new model
				$result   = Translation_Rule_Service::create_model( $model_data );
				$model_id = $result;
			}

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( 'Import %s failed: %s', $plugin_slug, $result->get_error_message() );
				continue;
			}

			// Import rules
			if ( ! empty( $rules ) ) {
				$this->import_rules( $model_id, $rules, $overwrite );
			}

			$imported++;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'imported' => $imported,
				'errors'   => $errors,
			)
		);
	}

	/**
	 * Import rules for a model
	 *
	 * @param int   $model_id  Model ID.
	 * @param array $rules     Rules data.
	 * @param bool  $overwrite Whether to overwrite existing rules.
	 */
	private function import_rules( $model_id, $rules, $overwrite = false ) {
		// Rules should be a flat array in V2 format
		if ( ! is_array( $rules ) ) {
			return;
		}

		foreach ( $rules as $rule_data ) {
			// V2 uses 'name' and 'url_pattern' as identifiers
			if ( empty( $rule_data['url_pattern'] ) ) {
				continue;
			}

			unset( $rule_data['id'] );
			unset( $rule_data['model_id'] );

			// Check if rule with same url_pattern exists
			$existing_rules = Translation_Rule_Service::get_model_rules( $model_id );
			$existing_rule  = null;

			foreach ( $existing_rules as $r ) {
				if ( $r['url_pattern'] === $rule_data['url_pattern'] ) {
					$existing_rule = $r;
					break;
				}
			}

			if ( $existing_rule ) {
				if ( $overwrite ) {
					Translation_Rule_Service::update_rule( $existing_rule['id'], $rule_data );
				}
			} else {
				Translation_Rule_Service::create_rule( $model_id, $rule_data );
			}
		}
	}

	/**
	 * Export models
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_models( $request ) {
		$model_ids     = $request->get_param( 'model_ids' );
		$include_rules = $request->get_param( 'include_rules' );

		if ( empty( $model_ids ) || ! is_array( $model_ids ) ) {
			wptsall_log_error( 'models-api', 'export_models failed: no model_ids' );
			return new \WP_Error( 'invalid_data', 'Please select models to export', array( 'status' => 400 ) );
		}

		$export_data = array();

		foreach ( $model_ids as $model_id ) {
			$model = Translation_Rule_Service::get_model( (int) $model_id );

			if ( ! $model ) {
				continue;
			}

			// Remove internal fields
			unset( $model['id'] );
			unset( $model['created_at'] );
			unset( $model['updated_at'] );

			// Include rules if requested
			if ( $include_rules ) {
				$rules = Translation_Rule_Service::get_model_rules( (int) $model_id );

				// Clean up rule data
				foreach ( $rules as &$rule ) {
					unset( $rule['id'] );
					unset( $rule['model_id'] );
					unset( $rule['created_at'] );
					unset( $rule['updated_at'] );
				}

				$model['rules'] = $rules;
			}

			$export_data[] = $model;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'count'   => count( $export_data ),
				'data'    => $export_data,
			)
		);
	}

	/**
	 * Get model fields (translation rules organized by field)
	 *
	 * Extracts fields from field_capabilities JSON column in rules.
	 *
	 * @since 0.6.0
	 * @since 0.8.0 Updated to use field_capabilities instead of translate_fields.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_model_fields( $request ) {
		$model_id  = (int) $request->get_param( 'id' );
		$data_type = $request->get_param( 'data_type' );

		$model = Translation_Rule_Service::get_model( $model_id );

		if ( ! $model ) {
			return new \WP_Error( 'not_found', __( 'Model not found', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		// Get all rules for this model
		$rules = Translation_Rule_Service::get_model_rules( $model_id );

		// Filter by data_type if specified
		if ( ! empty( $data_type ) ) {
			$rules = array_filter( $rules, function ( $rule ) use ( $data_type ) {
				return $rule['data_type'] === $data_type;
			} );
		}

		// Extract fields from field_capabilities JSON (v0.8.0)
		$all_fields = array();
		foreach ( $rules as $rule ) {
			$field_capabilities = $rule['field_capabilities'] ?? array();

			if ( ! is_array( $field_capabilities ) || empty( $field_capabilities ) ) {
				continue;
			}

			// Process each field in field_capabilities
			foreach ( $field_capabilities as $field_name => $field_config ) {
				if ( empty( $field_name ) || ! is_array( $field_config ) ) {
					continue;
				}

				$key = $rule['data_type'] . ':' . $rule['object_name'] . ':' . $field_name;

				if ( ! isset( $all_fields[ $key ] ) ) {
					$all_fields[ $key ] = array(
						'field_name'  => $field_name,
						'field_type'  => $field_config['type'] ?? 'sync',
						'direction'   => $field_config['direction'] ?? 'one_way',
						'enabled'     => $field_config['enabled'] ?? true,
						'data_type'   => $rule['data_type'] ?? '',
						'object_name' => $rule['object_name'] ?? '',
						'rules'       => array(),
					);
				}

				$all_fields[ $key ]['rules'][] = array(
					'rule_id'   => $rule['id'],
					'url_type'  => $rule['url_type'] ?? '',
					'is_active' => (bool) ( $rule['is_active'] ?? true ),
				);
			}
		}

		// Add rules_count and convert to indexed array
		$result_fields = array();
		foreach ( $all_fields as $field ) {
			$field['rules_count'] = count( $field['rules'] );
			$result_fields[]      = $field;
		}

		return rest_ensure_response( array(
			'success'   => true,
			'model_id'  => $model_id,
			'plugin'    => $model['plugin_slug'] ?? '',
			'fields'    => $result_fields,
			'total'     => count( $result_fields ),
		) );
	}

	/**
	 * Get model URL mappings
	 *
	 * @since 0.6.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_model_urls( $request ) {
		$model_id = (int) $request->get_param( 'id' );

		$model = Translation_Rule_Service::get_model( $model_id );

		if ( ! $model ) {
			return new \WP_Error( 'not_found', __( 'Model not found', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		// Get rules grouped by URL type
		$rules_by_type = Translation_Rule_Service::get_rules_by_type( $model_id );

		// Build URL mappings
		$url_mappings = array();
		foreach ( $rules_by_type as $url_type => $rules ) {
			$url_mappings[] = array(
				'url_type'     => $url_type,
				'display_name' => $this->get_url_type_display_name( $url_type ),
				'rules_count'  => count( $rules ),
				'active_count' => count( array_filter( $rules, function ( $r ) {
					return ! empty( $r['is_active'] );
				} ) ),
				'data_types'   => array_unique( array_column( $rules, 'data_type' ) ),
			);
		}

		return rest_ensure_response( array(
			'success'      => true,
			'model_id'     => $model_id,
			'plugin'       => $model['plugin_slug'] ?? '',
			'url_mappings' => $url_mappings,
			'total_types'  => count( $url_mappings ),
		) );
	}

	/**
	 * Get display name for URL type
	 *
	 * @since 0.6.0
	 *
	 * @param string $url_type URL type.
	 * @return string Display name.
	 */
	private function get_url_type_display_name( $url_type ) {
		$types = array(
			'frontend'     => __( 'Frontend Page', 'wpmmcc-ats' ),
			'admin'        => __( 'Admin Backend', 'wpmmcc-ats' ),
			'ajax'         => __( 'AJAX Request', 'wpmmcc-ats' ),
			'rest_api'     => __( 'REST API', 'wpmmcc-ats' ),
			'cron'         => __( 'Scheduled Task', 'wpmmcc-ats' ),
			'email'        => __( 'Email Content', 'wpmmcc-ats' ),
			'notification' => __( 'Notification', 'wpmmcc-ats' ),
			'global'       => __( 'Global Translation', 'wpmmcc-ats' ),
		);

		return $types[ $url_type ] ?? $url_type;
	}

	/**
	 * Get link chains for a rule
	 *
	 * @since 0.7.1
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_rule_link_chains( $request ) {
		$rule_id = (int) $request->get_param( 'id' );

		$rule = Translation_Rule_Service::get_rule( $rule_id );

		if ( ! $rule ) {
			return new \WP_Error(
				'not_found',
				__( 'Rule not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		require_once dirname( __DIR__ ) . '/services/class-custom-model-service.php';

		$chains = \WPTSALL\Models\Services\Custom_Model_Service::get_link_chains( $rule_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'rule_id' => $rule_id,
				'chains'  => $chains,
				'total'   => count( $chains ),
			)
		);
	}

	/**
	 * Save link chains for a rule
	 *
	 * @since 0.7.1
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_rule_link_chains( $request ) {
		$rule_id = (int) $request->get_param( 'id' );
		$chains  = $request->get_param( 'chains' );

		$rule = Translation_Rule_Service::get_rule( $rule_id );

		if ( ! $rule ) {
			return new \WP_Error(
				'not_found',
				__( 'Rule not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		if ( ! is_array( $chains ) ) {
			return new \WP_Error(
				'invalid_chains',
				__( 'Invalid chain data', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		require_once dirname( __DIR__ ) . '/services/class-custom-model-service.php';

		// Validate all chains first
		$validation = \WPTSALL\Models\Services\Custom_Model_Service::validate_link_chain( $chains );

		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'validation_failed',
				__( 'Chain configuration validation failed', 'wpmmcc-ats' ),
				array(
					'status' => 400,
					'errors' => $validation['errors'],
				)
			);
		}

		// Save the chains
		$result = \WPTSALL\Models\Services\Custom_Model_Service::save_link_chains( $rule_id, $chains );

		if ( is_wp_error( $result ) ) {
			wptsall_log_error(
				'models-api',
				'save_rule_link_chains failed',
				array(
					'rule_id' => $rule_id,
					'error'   => $result->get_error_message(),
				)
			);
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'rule_id' => $rule_id,
				'saved'   => $result,
				'message' => __( 'Link chains saved', 'wpmmcc-ats' ),
			)
		);
	}

	/**
	 * Get template by plugin slug
	 *
	 * Migrated from rest.php v1 endpoint.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_template( $request ) {
		$plugin = $request->get_param( 'plugin' );

		if ( empty( $plugin ) ) {
			return new \WP_Error(
				'invalid_plugin',
				__( 'plugin parameter is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		// Get plugin mapping
		$mapping = Plugin_Mapping_Service::get_by_slug( $plugin );

		if ( ! $mapping ) {
			return new \WP_Error(
				'not_found',
				__( 'Plugin mapping not found', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Convert to template format
		$template = wptsall_mapping_to_template( $mapping );

		if ( ! $template ) {
			return new \WP_Error(
				'invalid_mapping',
				__( 'Invalid mapping configuration', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $template );
	}

	// ==========================================
	// H1: Model v3.0 Import/Export endpoints
	// ==========================================

	/**
	 * Export a single model as v3.0 JSON
	 *
	 * @since 1.4.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_model_v3( $request ) {
		$model_id = (int) $request->get_param( 'id' );

		$json = wptsall_export_model( $model_id );

		if ( is_wp_error( $json ) ) {
			$status = 'not_found' === $json->get_error_code() ? 404 : 400;
			return new \WP_Error(
				$json->get_error_code(),
				$json->get_error_message(),
				array( 'status' => $status )
			);
		}

		$data = json_decode( $json, true );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Import a model from v3.0 JSON
	 *
	 * @since 1.4.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_model_v3( $request ) {
		$json   = $request->get_param( 'json' );
		$action = $request->get_param( 'action' ) ?? 'check';

		if ( empty( $json ) ) {
			return new \WP_Error(
				'missing_json',
				__( 'JSON data is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = wptsall_import_model( $json, $action );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	// ==========================================
	// H2: Rule v3.0 Import/Export endpoints
	// ==========================================

	/**
	 * Export a single rule as v3.0 JSON
	 *
	 * @since 1.4.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_rule_v3( $request ) {
		$rule_id = (int) $request->get_param( 'id' );

		$json = wptsall_export_rule( $rule_id );

		if ( is_wp_error( $json ) ) {
			$status = 'not_found' === $json->get_error_code() ? 404 : 400;
			return new \WP_Error(
				$json->get_error_code(),
				$json->get_error_message(),
				array( 'status' => $status )
			);
		}

		$data = json_decode( $json, true );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Import a rule from v3.0 JSON
	 *
	 * @since 1.4.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_rule_v3( $request ) {
		$json        = $request->get_param( 'json' );
		$model_id    = (int) $request->get_param( 'model_id' );
		$plugin_slug = sanitize_key( (string) $request->get_param( 'plugin_slug' ) );
		$action      = $request->get_param( 'action' ) ?? 'check';

		if ( empty( $json ) ) {
			return new \WP_Error(
				'missing_json',
				__( 'JSON data is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		if ( $model_id <= 0 && '' === $plugin_slug ) {
			return new \WP_Error(
				'invalid_target',
				__( 'Valid model_id or plugin_slug is required', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$result = wptsall_import_rule( $json, $model_id, $action, $plugin_slug );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}
}

/**
 * Register Translation Rule REST API routes
 */
function wptsall_register_translation_rule_rest_routes() {
	$controller = new Translation_Rule_REST_Controller();
	$controller->register_routes();
}
