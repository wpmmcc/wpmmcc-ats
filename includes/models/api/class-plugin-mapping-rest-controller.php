<?php
/**
 * Plugin Mapping REST Controller
 *
 * REST API endpoints for plugin mappings.
 *
 * @package WPTSALL\Models\API
 * @since 0.6.0
 */

namespace WPTSALL\Models\API;

use WPTSALL\Models\Services\Plugin_Mapping_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Mapping REST Controller Class
 *
 * Provides REST API endpoints for plugin mapping operations:
 * - GET /plugins - List all content plugins
 * - GET /plugins/{slug} - Get single plugin mapping
 * - POST /plugins/scan - Scan and save all plugins
 */
class Plugin_Mapping_REST_Controller {

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
	protected $rest_base = 'plugins';

	/**
	 * Register REST routes
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /plugins - List all content plugins
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status' => array(
						'description' => __( 'Filter by status: "content" for content plugins only, "all" for all plugins', 'wpmmcc-ats' ),
						'type'        => 'string',
						'enum'        => array( 'content', 'all' ),
						'default'     => 'content',
					),
					'format' => array(
						'description' => __( 'Response format: "simple" for slug=>name map, "full" for complete details', 'wpmmcc-ats' ),
						'type'        => 'string',
						'enum'        => array( 'simple', 'full' ),
						'default'     => 'simple',
					),
				),
			)
		);

		// GET /plugins/without-models - Get content plugins without models
		// NOTE: Static routes must be registered BEFORE dynamic routes to prevent incorrect matching.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/without-models',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_plugins_without_models' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// GET /plugins/{slug} - Get single plugin mapping
		// NOTE: Dynamic routes must be registered AFTER all static routes.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'slug' => array(
						'description'       => __( 'Plugin slug', 'wpmmcc-ats' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// POST /plugins/scan - Scan and save all plugins
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_plugins' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /plugins/scan-all - Scan all plugins (migrated from rest.php v1)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/scan-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_all_plugins' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'plugins' => array(
						'description'       => __( 'Optional array of plugin slugs to scan', 'wpmmcc-ats' ),
						'type'              => 'array',
						'required'          => false,
						'items'             => array(
							'type' => 'string',
						),
						'sanitize_callback' => function ( $plugins ) {
							return array_map( 'sanitize_key', $plugins );
						},
					),
				),
			)
		);

		// POST /initialize - Initialize plugin (first-time setup, migrated from rest.php v1)
		register_rest_route(
			$this->namespace,
			'/initialize',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'initialize_plugin' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /admin/complete-initialization - Mark initialization complete
		register_rest_route(
			$this->namespace,
			'/admin/complete-initialization',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_initialization' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /plugin-mappings/consent - Grant consent for V4 scanning
		register_rest_route(
			$this->namespace,
			'/plugin-mappings/consent',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'grant_consent' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'plugins' => array(
						'description'       => __( 'Array of plugin slugs to grant consent for', 'wpmmcc-ats' ),
						'type'              => 'array',
						'required'          => true,
						'items'             => array(
							'type' => 'string',
						),
						'sanitize_callback' => function ( $plugins ) {
							return array_map( 'sanitize_key', $plugins );
						},
					),
				),
			)
		);

		// POST /plugin-mappings/v4-scan - Run V4 scan for consented plugins
		register_rest_route(
			$this->namespace,
			'/plugin-mappings/v4-scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_v4_scan' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'force' => array(
						'description' => __( 'Force re-scan even if already scanned', 'wpmmcc-ats' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);

		// GET /plugin-mappings/pending-consent - Get plugins needing consent
		register_rest_route(
			$this->namespace,
			'/plugin-mappings/pending-consent',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pending_consent' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// GET /plugin-mappings/pending-scans - Get plugins pending V4 scan
		register_rest_route(
			$this->namespace,
			'/plugin-mappings/pending-scans',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pending_scans' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check permission for API access
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request = null ) {
		if ( ! wptsall_user_can_manage_translations() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'wpmmcc-ats' ),
				array( 'status' => 403 )
			);
		}

		// The dynamic /plugins/{slug} route addresses a persisted plugin mapping.
		// Reject a fabricated slug before the callback runs, rather than allowing
		// downstream plugin hooks to observe an object that does not exist.
		if ( $request instanceof \WP_REST_Request && $request->has_param( 'slug' ) ) {
			$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
			if ( '' === $slug || ! Plugin_Mapping_Service::get_by_slug( $slug ) ) {
				return new \WP_Error(
					'rest_object_not_found',
					__( 'Plugin mapping does not exist.', 'wpmmcc-ats' ),
					array( 'status' => 404 )
				);
			}
		}

		return true;
	}

	/**
	 * Get all plugins
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$status = $request->get_param( 'status' );
		$format = $request->get_param( 'format' );

		if ( 'content' === $status ) {
			// Only content plugins
			if ( 'simple' === $format ) {
				// Simple format: slug => name map
				$plugins = Plugin_Mapping_Service::get_content_plugins();
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $plugins,
					)
				);
			} else {
				// Full format: complete plugin details
				$mappings = Plugin_Mapping_Service::get_all(
					array(
						'is_content_plugin' => 1,
					)
				);
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $this->prepare_items_for_response( $mappings ),
					)
				);
			}
		} else {
			// All plugins
			$mappings = Plugin_Mapping_Service::get_all();

			if ( 'simple' === $format ) {
				$plugins = array();
				foreach ( $mappings as $mapping ) {
					$plugins[ $mapping['plugin_slug'] ] = $mapping['plugin_name'];
				}
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $plugins,
					)
				);
			} else {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => $this->prepare_items_for_response( $mappings ),
					)
				);
			}
		}
	}

	/**
	 * Get single plugin mapping
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$slug = $request->get_param( 'slug' );

		$mapping = Plugin_Mapping_Service::get_by_slug( $slug );

		if ( ! $mapping ) {
			return new \WP_Error(
				'not_found',
				/* translators: %s: plugin slug */
				sprintf( __( 'Plugin mapping not found: %s', 'wpmmcc-ats' ), $slug ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $this->prepare_item_for_response( $mapping ),
			)
		);
	}

	/**
	 * Scan and save all plugins
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_plugins( $request ) {
		$stats = Plugin_Mapping_Service::scan_and_save_all();

		return rest_ensure_response(
			array(
				'success'          => true,
				'total_scanned'    => $stats['total_scanned'],
				'content_plugins'  => $stats['content_plugins'],
				'saved'            => $stats['saved'],
				'orphans_resolved' => $stats['orphans_resolved'],
			)
		);
	}

	/**
	 * Get content plugins without models
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_plugins_without_models( $request ) {
		$plugins = Plugin_Mapping_Service::get_content_plugins_without_models();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $plugins,
			)
		);
	}

	/**
	 * Prepare items for response
	 *
	 * @param array $mappings Array of mapping data.
	 * @return array Prepared items.
	 */
	protected function prepare_items_for_response( $mappings ) {
		$items = array();
		foreach ( $mappings as $mapping ) {
			$items[] = $this->prepare_item_for_response( $mapping );
		}
		return $items;
	}

	/**
	 * Grant consent for V4 scanning
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function grant_consent( $request ) {
		$plugins = $request->get_param( 'plugins' );

		if ( empty( $plugins ) ) {
			return new \WP_Error(
				'invalid_params',
				__( 'No plugins specified for consent.', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$results = array();
		foreach ( $plugins as $plugin_slug ) {
			$success = Plugin_Mapping_Service::set_user_consent( $plugin_slug, true );
			$results[ $plugin_slug ] = $success;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $results,
				'message' => sprintf(
					/* translators: %d: number of plugins */
					__( 'Consent granted for %d plugins.', 'wpmmcc-ats' ),
					count( array_filter( $results ) )
				),
			)
		);
	}

	/**
	 * Run V4 scan for consented plugins
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_v4_scan( $request ) {
		$force = $request->get_param( 'force' );

		$results = Plugin_Mapping_Service::run_v4_scan_all( $force );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $results,
				'message' => sprintf(
					/* translators: %d: number of plugins scanned */
					__( 'V4 scan completed for %d plugins.', 'wpmmcc-ats' ),
					$results['scanned']
				),
			)
		);
	}

	/**
	 * Get plugins needing consent
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_pending_consent( $request ) {
		$plugins = Plugin_Mapping_Service::get_plugins_needing_consent();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $plugins,
				'count'   => count( $plugins ),
			)
		);
	}

	/**
	 * Get plugins pending V4 scan
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_pending_scans( $request ) {
		$plugins = Plugin_Mapping_Service::get_pending_scans();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $plugins,
				'count'   => count( $plugins ),
			)
		);
	}

	/**
	 * Scan all plugins and update mappings
	 *
	 * Migrated from rest.php v1 endpoint /plugin-mappings/scan-all.
	 * ISS-MOD-024: Now also creates translation rules for publicly accessible post_types.
	 *
	 * @since 0.9.0
	 * @since 0.9.2 Added automatic translation rules creation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function scan_all_plugins( $request ) {
		$selected_plugins = $request->get_param( 'plugins' );

		if ( ! empty( $selected_plugins ) && is_array( $selected_plugins ) ) {
			$selected_plugins = array_values( array_filter( array_map( 'sanitize_key', $selected_plugins ) ) );
			$stats            = Plugin_Mapping_Service::scan_and_save_selected( $selected_plugins );
		} else {
			$stats = Plugin_Mapping_Service::scan_and_save_all();
		}

		// ISS-MOD-024: Create translation rules for scanned plugins.
		$rules_stats = $this->create_rules_for_scanned_plugins( $selected_plugins ?? array() );

		return rest_ensure_response(
			array(
				'success'         => true,
				'total_scanned'   => $stats['total_scanned'],
				'content_plugins' => $stats['content_plugins'],
				'saved'           => $stats['saved'],
				'rules_created'   => $rules_stats['created'],
				'rules_skipped'   => $rules_stats['skipped'],
			)
		);
	}

	/**
	 * Create translation rules for scanned plugins (ISS-MOD-024)
	 *
	 * @since 0.9.2
	 *
	 * @param array $plugin_slugs Optional specific plugin slugs. Empty = all.
	 * @return array Stats with 'created' and 'skipped' counts.
	 */
	private function create_rules_for_scanned_plugins( $plugin_slugs = array() ) {
		$stats = array(
			'created' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		// Get models to process.
		// Do not limit to content plugins only: some plugins may expose translatable
		// objects but be classified as non-content by scanner heuristics.
		$models = Plugin_Mapping_Service::get_all( array(
			'limit' => -1,
		) );

		if ( empty( $models ) ) {
			return $stats;
		}

		$models = array_values(
			array_filter(
				$models,
				function ( $model ) {
					$status = strtolower( (string) ( $model['status'] ?? 'active' ) );
					if ( 'active' !== $status ) {
						return false;
					}

					$post_types = is_array( $model['post_types'] ?? null ) ? $model['post_types'] : array();
					$taxonomies = is_array( $model['taxonomies'] ?? null ) ? $model['taxonomies'] : array();
					return ! empty( $post_types ) || ! empty( $taxonomies );
				}
			)
		);

		// Filter by selected plugins if specified.
		if ( ! empty( $plugin_slugs ) ) {
			$models = array_filter( $models, function ( $model ) use ( $plugin_slugs ) {
				return in_array( $model['plugin_slug'], $plugin_slugs, true );
			} );
		}

		// Create rules for each model.
		foreach ( $models as $model ) {
			$result = Translation_Rule_Service::create_rules_for_model( $model['id'] );

			$stats['created'] += $result['created'];
			$stats['skipped'] += $result['skipped'];
			$stats['errors']  += $result['errors'];
		}

		wptsall_log_info(
			'models-api',
			'create_rules_for_scanned_plugins completed',
			array(
				'models_processed' => count( $models ),
				'rules_created'    => $stats['created'],
				'rules_skipped'    => $stats['skipped'],
			)
		);

		return $stats;
	}

	/**
	 * Mark initialization as complete
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function complete_initialization( $request ) {
		$success = Plugin_Mapping_Service::mark_initialized();

		return rest_ensure_response(
			array(
				'success'     => (bool) $success,
				'initialized' => Plugin_Mapping_Service::is_initialized(),
			)
		);
	}

	/**
	 * Initialize plugin (first-time setup)
	 *
	 * Scans all plugins and marks the system as initialized.
	 * Migrated from rest.php v1 endpoint /initialize.
	 * ISS-MOD-024: Now also creates translation rules for publicly accessible post_types.
	 *
	 * @since 0.9.0
	 * @since 0.9.2 Added automatic translation rules creation
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function initialize_plugin( $request ) {
		// Run the scan.
		$stats = Plugin_Mapping_Service::scan_and_save_all();

		// ISS-MOD-024: Create translation rules for scanned plugins.
		$rules_stats = $this->create_rules_for_scanned_plugins();

		// Mark as initialized.
		Plugin_Mapping_Service::mark_initialized();

		return rest_ensure_response(
			array(
				'success'         => true,
				'total_scanned'   => $stats['total_scanned'],
				'content_plugins' => $stats['content_plugins'],
				'saved'           => $stats['saved'],
				'rules_created'   => $rules_stats['created'],
				'rules_skipped'   => $rules_stats['skipped'],
			)
		);
	}

	/**
	 * Prepare single item for response
	 *
	 * @param array $mapping Mapping data.
	 * @return array Prepared item.
	 */
	protected function prepare_item_for_response( $mapping ) {
		// Check if plugin has a model
		$has_model = false;
		$model_id  = null;

		if ( class_exists( '\WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
			$plugins_with_models = \WPTSALL\Models\Services\Translation_Rule_Service::get_plugins_with_models();
			$has_model           = in_array( $mapping['plugin_slug'], $plugins_with_models, true );

			if ( $has_model ) {
				// Get the model ID
				global $wpdb;
				$table    = wptsall_table( 'models' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$model_id = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM %i WHERE plugin_slug = %s LIMIT 1',
						$table,
						$mapping['plugin_slug']
					)
				);
			}
		}

		return array(
			'id'                => (int) $mapping['id'],
			'plugin_slug'       => $mapping['plugin_slug'],
			'plugin_name'       => $mapping['plugin_name'],
			'plugin_file'       => $mapping['plugin_file'] ?? '',
			'post_types'        => $mapping['post_types'] ?? array(),
			'taxonomies'        => $mapping['taxonomies'] ?? array(),
			'meta_fields'       => $mapping['meta_fields'] ?? array(),
			'is_content_plugin' => (bool) $mapping['is_content_plugin'],
			'detection_method'  => $mapping['detection_method'] ?? 'static',
			'has_model'         => $has_model,
			'model_id'          => $model_id ? (int) $model_id : null,
			'last_scanned'      => $mapping['last_scanned'] ?? null,
			'created_at'        => $mapping['created_at'] ?? null,
			'updated_at'        => $mapping['updated_at'] ?? null,
		);
	}
}
