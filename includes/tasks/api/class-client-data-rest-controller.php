<?php
/**
 * Client Data REST Controller
 *
 * Provides content discovery and translation callback endpoints for the
 * external Rust client (self-hosted translation service).
 *
 * Discovery endpoints allow the client to find site relations, translation
 * rules, and untranslated content autonomously. The callback endpoint
 * receives translated results and dispatches sync/write-back.
 *
 * @package WPTSALL\Tasks\API
 * @since 1.0.5
 * @updated 1.1.0 Added content discovery endpoints (site-relations, rules, content)
 * @updated 1.2.0 Added include_resync, content/claim, immediate sync execution
 */
namespace WPTSALL\Tasks\API;

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Models\Services\Post_Mapping_Service;

require_once __DIR__ . '/trait-client-data-rest-controller-discovery.php';
require_once __DIR__ . '/trait-client-data-rest-controller-content.php';
require_once __DIR__ . '/trait-client-data-rest-controller-claim.php';

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Client data REST controller.
 */
class Client_Data_REST_Controller {
	use Client_Data_REST_Controller_Discovery_Trait {
		validate_token as public;
		get_site_relations as private discovery_get_site_relations;
		get_rules as private discovery_get_rules;
	}
	use Client_Data_REST_Controller_Content_Trait {
		get_untranslated_options as private trait_get_untranslated_options;
		get_untranslated_site_string_entries as private trait_get_untranslated_site_string_entries;
		get_untranslated_posts as private trait_get_untranslated_posts;
		get_untranslated_terms as private trait_get_untranslated_terms;
		resolve_content_per_page as private trait_resolve_content_per_page;
	}
	use Client_Data_REST_Controller_Claim_Trait {
		claim_site_string_entries as private trait_claim_site_string_entries;
	}

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'client';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$secrets = function_exists( 'wptsall_get_active_client_route_secrets' )
			? wptsall_get_active_client_route_secrets()
			: array( function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '' );
		if ( empty( $secrets ) ) {
			$secrets = array( '' );
		}

		foreach ( $secrets as $secret ) {
			$secret_prefix = '' !== (string) $secret ? (string) $secret . '/' : '';
			$base          = $secret_prefix . $this->rest_base;

		// POST /{secret}/client/translation-callback
		register_rest_route(
			$this->namespace,
			'/' . $base . '/translation-callback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'translation_callback' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
			)
		);

		// POST /{secret}/client/media-upload — binary file upload from client.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/media-upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'media_upload' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
			)
		);

		// GET /{secret}/client/validate-token — connectivity check and site metadata.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/validate-token',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'validate_token' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
			)
		);

		// POST /{secret}/client/pairing/claim — one-time bootstrap for standalone local clients.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/pairing/claim',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pairing_claim' ),
				'permission_callback' => array( $this, 'check_pairing_claim_permission' ),
			)
		);

		// GET /{secret}/client/site-relations — content discovery: list active site relations.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/site-relations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_site_relations' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
			)
		);

		// GET /{secret}/client/rules — content discovery: translation rules for a relation or model.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/rules',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rules' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
				'args'                => array(
					'relation_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'model_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /{secret}/client/content — content discovery: untranslated content for a relation.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/content',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_content' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
				'args'                => array(
					'relation_id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'data_type'      => array(
						'type'              => 'string',
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
					'subtype'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'include_resync' => array(
						'type'              => 'boolean',
						'default'           => false,
					),
					'page'           => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'       => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'include_ids'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /{secret}/client/content/claim — claim content items for processing.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/content/claim',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'claim_content' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
			)
		);

		// GET /{secret}/client/content-changes — durable lifecycle outbox.
		register_rest_route(
			$this->namespace,
			'/' . $base . '/content-changes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_content_changes' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
				'args'                => array(
					'limit'       => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
					'relation_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $base . '/content-changes/(?P<id>\d+)/ack',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ack_content_change' ),
				'permission_callback' => array( $this, 'check_client_permission' ),
			)
		);
		} // end foreach secrets
	}

	/**
	 * Check client permission via X-WPTSALL-Client-Token header.
	 *
	 * Reuses the same auth pattern as Client_Tasks_REST_Controller.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function check_client_permission( $request ) {
		// Pro gate removed: free users can access client data REST.
		// Only official template download requires Pro (enforced server-side).

		// Protocol version negotiation: clients that send X-WPTSALL-Protocol-Version
		// must declare a version this plugin understands.
		if ( function_exists( 'wptsall_check_client_protocol_version' ) ) {
			$proto = wptsall_check_client_protocol_version( $request );
			if ( is_wp_error( $proto ) ) {
				return $proto;
			}
		}
		if ( function_exists( 'wptsall_check_client_contract_capabilities' ) ) {
			$caps = wptsall_check_client_contract_capabilities( $request );
			if ( is_wp_error( $caps ) ) {
				return $caps;
			}
		}

		$token = (string) $request->get_header( 'X-WPTSALL-Client-Token' );
		$device = sanitize_key( (string) $request->get_header( 'X-WPTSALL-Device-Id' ) );
		if ( empty( $token ) || ! function_exists( 'wptsall_license_service' ) ) {
			return new \WP_Error(
				'client_unauthorized',
				__( 'Client authentication failed', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}
		if ( '' === $device ) {
			return new \WP_Error(
				'client_device_required',
				__( 'Protocol v2 requests must declare a device id.', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}
		$ok = wptsall_license_service()->verify_client_token( $token, $device, true );
		if ( ! $ok ) {
			return new \WP_Error(
				'client_unauthorized',
				__( 'Client authentication failed', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Pairing claim is the only client REST route that intentionally does not
	 * require an existing device token: it exchanges a one-time, hash-at-rest
	 * pairing code for the first device-scoped token.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function check_pairing_claim_permission( $request ) {
		if ( ! function_exists( 'wptsall_claim_client_pairing_code' ) ) {
			return new \WP_Error(
				'pairing_unavailable',
				__( 'Pairing is unavailable.', 'wpmmcc-ats' ),
				array( 'status' => 503 )
			);
		}
		return true;
	}

	/**
	 * Apply a small per-device throttle to the high-volume content lanes.
	 *
	 * Authentication is performed by the route permission callback first. The
	 * device id is hashed before it is used as a transient key so neither a
	 * token nor a device identifier is written to the object-cache key. Filters
	 * allow installations to tune the limits without changing the wire
	 * contract. A null return means the request is allowed.
	 *
	 * @param string $bucket Rate-limit bucket name.
	 * @return null|\WP_REST_Response
	 */
	private function check_rate_limit( $bucket ) {
		$bucket = sanitize_key( (string) $bucket );
		if ( '' === $bucket ) {
			$bucket = 'content';
		}

		$limits = apply_filters(
			'wptsall_client_data_rate_limits',
			array(
				'content'       => array( 'limit' => 120, 'window' => 60 ),
				'content_claim' => array( 'limit' => 120, 'window' => 60 ),
			),
			$bucket
		);
		$config = is_array( $limits ) && isset( $limits[ $bucket ] ) && is_array( $limits[ $bucket ] )
			? $limits[ $bucket ]
			: array( 'limit' => 120, 'window' => 60 );
		$limit      = max( 1, min( 10000, absint( $config['limit'] ?? 120 ) ) );
		$max_window = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$window     = max( 1, min( $max_window, absint( $config['window'] ?? 60 ) ) );

		// The permission callback requires this header for protocol v2. The
		// fallback keeps direct unit calls deterministic, but only its digest is
		// persisted. Never use the raw device id as a database key.
		$identity = isset( $_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] )
			? sanitize_key( wp_unslash( $_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] ) )
			: 'unknown-device';
		$bucket_hash = hash( 'sha256', 'wptsall-client-rate-v1|' . $bucket . '|' . $identity );
		$now         = time();
		$reset_at    = $now + $window;
		$now_mysql   = gmdate( 'Y-m-d H:i:s', $now );

		// A transient read/modify/write loses increments when two PHP workers
		// handle requests for the same device concurrently. The InnoDB upsert is
		// one atomic row operation: an expired window resets to one, otherwise
		// the existing counter is incremented under the row lock.
		if ( function_exists( 'wptsall_ensure_client_rate_limits_table' ) ) {
			wptsall_ensure_client_rate_limits_table();
		}
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'client_rate_limits' ) : '';
		global $wpdb;
		$table_exists = '' !== $table
			? $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
			: false;
		if ( ! $table_exists ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'client_rate_limit_unavailable',
					'message' => 'Client request throttling is unavailable; retry later.',
				),
				503
			);
		}

		$upserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket_hash, request_count, reset_at, updated_at)
				 VALUES (%s, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
				 request_count = IF(reset_at <= %d, %d, request_count + 1),
				 reset_at = IF(reset_at <= %d, %d, reset_at),
				 updated_at = %s',
				$table,
				$bucket_hash,
				1,
				$reset_at,
				$now_mysql,
				$now,
				1,
				$now,
				$reset_at,
				$now_mysql
			)
		);
		if ( false === $upserted ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'client_rate_limit_unavailable',
					'message' => 'Client request throttling is unavailable; retry later.',
				),
				503
			);
		}

		$state = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT request_count, reset_at FROM %i WHERE bucket_hash = %s LIMIT 1',
				$table,
				$bucket_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $state ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'client_rate_limit_unavailable',
					'message' => 'Client request throttling is unavailable; retry later.',
				),
				503
			);
		}

		$count       = max( 1, (int) ( $state['request_count'] ?? 1 ) );
		$window_reset = max( $now + 1, (int) ( $state['reset_at'] ?? $reset_at ) );
		$remaining   = max( 0, $limit - $count );
		if ( $count <= $limit ) {
			return null;
		}

		$retry_after = max( 1, $window_reset - $now );
		$response    = new \WP_REST_Response(
			array(
				'success'      => false,
				'error'        => 'client_rate_limited',
				'message'      => 'Too many client data requests; retry later.',
				'retry_after'  => $retry_after,
			),
			429
		);
		$response->header( 'Retry-After', (string) $retry_after );
		$response->header( 'X-RateLimit-Limit', (string) $limit );
		$response->header( 'X-RateLimit-Remaining', (string) $remaining );
		$response->header( 'X-RateLimit-Reset', (string) $window_reset );
		return $response;
	}

	/**
	 * Return the relation content claim lease timeout.
	 *
	 * @return int Timeout in seconds.
	 */
	private static function get_claim_timeout_seconds() {
		if ( function_exists( 'wptsall_get_client_claim_timeout_seconds' ) ) {
			return (int) wptsall_get_client_claim_timeout_seconds();
		}
		return 1800;
	}

	/**
	 * POST /client/pairing/claim.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pairing_claim( $request ) {
		$device_id    = sanitize_key( (string) $request->get_param( 'device_id' ) );
		$pairing_code = sanitize_text_field( (string) $request->get_param( 'pairing_code' ) );
		$device_label = sanitize_text_field( (string) $request->get_param( 'device_label' ) );

		$result = wptsall_claim_client_pairing_code( $device_id, $pairing_code, $device_label );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	// =====================================================================
	// Content Discovery Endpoints
	// =====================================================================

	/**
	 * GET /client/site-relations
	 *
	 * Returns all active site relations with their associated models,
	 * languages, and configuration. Used by the client to discover what
	 * content needs translation.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_site_relations( $request ) {
		return $this->discovery_get_site_relations( $request );
	}

	/**
	 * GET /client/rules
	 *
	 * Returns translation rules for models in a given relation or a specific model.
	 * Rules define which fields to translate, which to map by ID, and which
	 * taxonomies are related.
	 *
	 * Parameters:
	 * - relation_id (int): Get rules for all models in a relation.
	 * - model_id (int): Get rules for a specific model.
	 * At least one of relation_id or model_id is required.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_rules( $request ) {
		return $this->discovery_get_rules( $request );
	}

	/**
	 * GET /client/content
	 *
	 * Returns content items for a relation that need translation (not yet
	 * translated). Filters out items that already have a post mapping in the
	 * wp_wptsall_post_mappings table.
	 *
	 * Parameters:
	 * - relation_id (int, required): Site relation ID.
	 * - data_type (string, optional): "post" or "term". Default "post".
	 * - subtype (string, optional): Post type or taxonomy slug. Default "" (all).
	 * - page (int, optional): Page number. Default 1.
	 * - per_page (int, optional): Items per page. Default 20, max 100.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_content( $request ) {
		$rate_error = $this->check_rate_limit( 'content' );
		if ( $rate_error ) {
			return $rate_error;
		}
		if ( function_exists( 'wptsall_trigger_deferred_sync_queue' ) ) {
			wptsall_trigger_deferred_sync_queue();
		}

		$relation_id        = absint( $request->get_param( 'relation_id' ) );
		$data_type          = sanitize_key( (string) $request->get_param( 'data_type' ) );
		$subtype            = sanitize_key( (string) $request->get_param( 'subtype' ) );
		$include_resync     = rest_sanitize_boolean( $request->get_param( 'include_resync' ) );
		$page               = max( 1, absint( $request->get_param( 'page' ) ) );
		$requested_per_page = absint( $request->get_param( 'per_page' ) );

		$include_ids_raw = $request->get_param( 'include_ids' );
		$include_ids     = ! empty( $include_ids_raw )
			? array_slice( array_values( array_filter( array_map( 'absint', explode( ',', (string) $include_ids_raw ) ) ) ), 0, 100 )
			: array();

		if ( empty( $data_type ) ) {
			$data_type = 'post';
		}
		$data_type = $this->normalize_content_data_type( $data_type );

		// Validate relation exists and is active.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'relation_not_found',
					'message' => 'Site relation not found.',
				),
				404
			);
		}

		if ( 'active' !== ( $relation['status'] ?? '' ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'relation_inactive',
					'message' => 'Site relation is not active.',
				),
				400
			);
		}
		$relation_source_site_id = absint( $relation['source_site_id'] ?? 0 );
		if ( $relation_source_site_id <= 0 ) {
			$relation_source_site_id = (int) get_current_blog_id();
		}
		if ( $relation_source_site_id !== (int) get_current_blog_id() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'source_site_mismatch',
					'message' => 'Client data routes must be called on the relation source site.',
				),
				403
			);
		}

		$per_page = $this->trait_resolve_content_per_page( $data_type, $requested_per_page );
		if ( ! in_array( $data_type, array( 'post', 'term', 'language_pack', 'site_string', 'option' ), true ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'unsupported_data_type',
					'message' => 'Unsupported data_type: ' . $data_type . '. Use "post", "term", "option", "site_string", or "language_pack".',
				),
				400
			);
		}

		// An explicit subtype is an authorization boundary, not merely a query
		// filter. Do not let a valid device token enumerate arbitrary content,
		// option, or Layer-B string data outside this relation's configuration.
		$subtype_allowed = true;
		if ( '' !== $subtype ) {
			if ( 'option' === $data_type ) {
				$subtype_allowed = $this->relation_allows_option( $relation_id, $subtype );
			} elseif ( 'site_string' === $data_type ) {
				$subtype_allowed = $this->relation_allows_site_string_lane( $relation, $subtype );
			} elseif ( ! in_array( $data_type, array( 'language_pack' ), true ) ) {
				$subtype_allowed = $this->relation_allows_subtype( $relation_id, $data_type, $subtype );
			}
		}
		if ( ! $subtype_allowed ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'subtype_not_allowed',
					'message' => 'The requested subtype is not enabled for this site relation.',
				),
				403
			);
		}

		if ( 'language_pack' === $data_type ) {
			return $this->get_untranslated_language_pack_entries( $relation, $page, $per_page, $subtype, $include_ids );
		}
		if ( 'site_string' === $data_type ) {
			return $this->trait_get_untranslated_site_string_entries( $relation, $page, $per_page, $subtype, $include_ids );
		}
		if ( 'option' === $data_type ) {
			return $this->trait_get_untranslated_options( $relation, $subtype, $page, $per_page );
		}

		// Determine which subtypes to query.
		$subtypes = array();
		if ( ! empty( $subtype ) ) {
			$subtypes[] = $subtype;
		} else {
			// Resolve the effective relation rule, including per-relation enabled
			// and object-name overrides. The active route must not drift from the
			// canonical content trait merely because a rule has a local override.
			$models = Relation_Model_Service::get_models_by_relation( $relation_id );
			foreach ( $models as $model ) {
				$rules = Translation_Rule_Service::get_model_rules( (int) $model['id'] );
				foreach ( $rules as $rule ) {
					$merged_config = Translation_Rule_Service::get_merged_config(
						(int) $rule['id'],
						$relation_id,
						array( 'suppress_warning_log' => true )
					);
					$enabled = is_array( $merged_config )
						? (bool) ( $merged_config['enabled'] ?? ( $rule['is_active'] ?? true ) )
						: (bool) ( $rule['is_active'] ?? true );
					if ( ! $enabled ) {
						continue;
					}
					$rule_type = $rule['data_type'] ?? '';
					$rule_name = $rule['object_name'] ?? '';
					if ( is_array( $merged_config ) ) {
						$rule_type = $merged_config['data_type'] ?? $rule_type;
						$rule_name = $merged_config['post_type'] ?? $rule_name;
					}
					$rule_type = $this->normalize_content_data_type( (string) $rule_type );
					if ( $rule_type === $data_type && '' !== (string) $rule_name ) {
						$subtypes[] = sanitize_key( (string) $rule_name );
					}
				}
			}
			$subtypes = array_values( array_unique( array_filter( $subtypes ) ) );
			// FSE objects are WordPress core content, not plugin-owned model rows.
			if ( 'post' === $data_type ) {
				$fse_types = apply_filters(
					'wptsall_fse_managed_post_types',
					array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' )
				);
				foreach ( (array) $fse_types as $fse_type ) {
					$fse_type = sanitize_key( (string) $fse_type );
					if ( '' !== $fse_type && post_type_exists( $fse_type ) ) {
						$subtypes[] = $fse_type;
					}
				}
				$subtypes = array_values( array_unique( $subtypes ) );
			}
		}

		if ( empty( $subtypes ) ) {
			return new \WP_REST_Response(
				array(
					'items'    => array(),
					'total'    => 0,
					'page'     => $page,
					'per_page' => $per_page,
				),
				200
			);
		}

		if ( 'post' === $data_type ) {
			return $this->trait_get_untranslated_posts( $relation, $subtypes, $page, $per_page, $include_resync, $include_ids );
		}

		if ( 'term' === $data_type ) {
			return $this->trait_get_untranslated_terms( $relation, $subtypes, $page, $per_page, $include_ids, $include_resync );
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => 'unsupported_data_type',
				'message' => 'Unsupported data_type: ' . $data_type . '. Use "post", "term", "option", "site_string", or "language_pack".',
			),
			400
		);
	}

	/**
	 * Claim durable content lifecycle changes and materialize normal client tasks.
	 * The outbox row remains processing until the translation callback acknowledges
	 * it; a lease expiry makes it claimable again without creating duplicate tasks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_content_changes( $request ) {
		$limit                 = max( 1, min( 100, absint( $request->get_param( 'limit' ) ) ) );
		$raw_relation_param    = $request->get_param( 'relation_id' );
		$has_relation_filter   = null !== $raw_relation_param;
		$requested_relation_id = $has_relation_filter ? absint( $raw_relation_param ) : 0;
		$empty_response        = array( 'success' => true, 'data' => array( 'items' => array(), 'schema_version' => 1 ) );
		if ( ! class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
			return rest_ensure_response( $empty_response );
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			return rest_ensure_response( $empty_response );
		}

		if ( $has_relation_filter ) {
			if ( $requested_relation_id <= 0 ) {
				return rest_ensure_response( $empty_response );
			}
			$requested_relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $requested_relation_id );
			if ( ! is_array( $requested_relation )
				|| 'active' !== sanitize_key( (string) ( $requested_relation['status'] ?? '' ) )
				|| absint( $requested_relation['source_site_id'] ?? 0 ) !== (int) get_current_blog_id() ) {
				return rest_ensure_response( $empty_response );
			}
			$device_relations = array( $requested_relation );
		} else {
			$device_relations = (array) \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations(
				array( 'status' => 'active', 'source_site_id' => get_current_blog_id() ), false
			);
		}

		$claim_owner = sanitize_key( (string) $request->get_header( 'X-WPTSALL-Device-Id' ) );
		$rows = array();
		foreach ( $device_relations as $device_relation ) {
			$remaining = $limit - count( $rows );
			if ( $remaining <= 0 ) { break; }
			$rows = array_merge( $rows, \WPTSALL\Hooks\Content_Change_Dispatcher::claim_outbox( $remaining, 900, absint( $device_relation['id'] ?? 0 ), $claim_owner ) );
		}
		$items = array();
		foreach ( $rows as $row ) {
			$outbox_id   = absint( $row['id'] ?? 0 );
			$relation_id = absint( $row['relation_id'] ?? 0 );
			$outbox_owner_hash = '' !== $claim_owner && function_exists( 'wptsall_client_claim_owner_hash' )
				? wptsall_client_claim_owner_hash( $claim_owner, $relation_id, '', 'outbox' )
				: '';
			$source_type = sanitize_key( (string) ( $row['source_type'] ?? '' ) );
			$source_id   = absint( $row['source_id'] ?? 0 );
			$payload     = json_decode( (string) ( $row['payload'] ?? '' ), true );
			if ( ! is_array( $payload ) ) { $payload = array(); }
			if ( $outbox_id <= 0 || $source_id <= 0 || ! in_array( $source_type, array( 'post', 'term', 'media' ), true ) ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, 'unsupported_outbox_source', $outbox_owner_hash );
				continue;
			}
			$relation = $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' )
				? \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id ) : null;
			if ( ! is_array( $relation ) ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, 'relation_not_found', $outbox_owner_hash );
				continue;
			}
			$object_type = 'term' === $source_type ? 'taxonomy' : 'post_type';
			$subtype     = 'term' === $source_type
				? sanitize_key( (string) ( $payload['taxonomy'] ?? '' ) )
				: sanitize_key( (string) ( $payload['post_type'] ?? ( 'media' === $source_type ? 'attachment' : '' ) ) );
			$content_claim_owner = $this->get_claim_owner_hash( $request, $relation, $subtype );
			if ( ! $this->claim_outbox_source_mapping( $relation, $object_type, $subtype, $source_id, $content_claim_owner ) ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, 'content_claim_unavailable', $outbox_owner_hash );
				continue;
			}
			$complete    = function_exists( 'wptsall_get_complete_object_data' )
				? wptsall_get_complete_object_data( $object_type, $subtype, $source_id ) : null;
			if ( ! is_array( $complete ) ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, 'source_object_not_found', $outbox_owner_hash );
				continue;
			}
			$complete = $this->attach_current_job_snapshot( $complete, $object_type, $source_id );
			$complete['_wptsall_outbox_id'] = $outbox_id;
			$client_task_id = 'outbox-' . $outbox_id . '-' . substr( (string) ( $row['event_key'] ?? '' ), 0, 16 );
			$task_seed = array(
				'job_id' => 'outbox-' . $outbox_id,
				'blog_id' => absint( $row['source_site_id'] ?? get_current_blog_id() ),
				'target_type' => sanitize_key( (string) ( $relation['target_site_type'] ?? 'wp' ) ),
				'target_identifier' => sanitize_text_field( (string) ( $relation['target_site_id'] ?? '' ) ),
				'site_id' => $relation_id, 'relation_id' => $relation_id,
				'template' => sanitize_key( (string) ( $relation['template'] ?? '' ) ),
				'lang_from' => sanitize_text_field( (string) ( $relation['source_lang'] ?? '' ) ),
				'lang_to' => sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) ),
				'object_type' => $object_type, 'subtype' => $subtype, 'object_id' => $source_id,
				'complete_data' => $complete,
				'business_line' => 'taxonomy' === $object_type ? 'taxonomy_content' : 'post_content',
				'client_task_id' => $client_task_id,
				'claim_owner_hash' => $content_claim_owner,
				'outbox_id' => $outbox_id, 'outbox_event_key' => (string) ( $row['event_key'] ?? '' ),
			);
			$insert = function_exists( 'wptsall_insert_tasks' ) ? wptsall_insert_tasks( array( $task_seed ) ) : array();
			// Resolve the open task after insert/reuse, then persist the association
			// in the outbox payload for callback completion and crash recovery.
			global $wpdb;
			$task_table = wptsall_table( 'tasks' );
			$task_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE relation_id = %d AND object_type = %s AND subtype = %s AND object_id = %d AND status IN (%s,%s,%s,%s) ORDER BY id DESC LIMIT 1', $task_table, $relation_id, $object_type, $subtype, $source_id, 'pending', 'retry', 'processing', 'active' ) );
			if ( $task_id <= 0 ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, 'task_materialization_failed', $outbox_owner_hash );
				continue;
			}
			$task_row = $wpdb->get_row( $wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d LIMIT 1', $task_table, $task_id ), ARRAY_A );
			$task_payload = is_array( $task_row ) ? json_decode( (string) ( $task_row['payload'] ?? '' ), true ) : array();
			if ( ! is_array( $task_payload ) ) {
				$task_payload = array();
			}
			$task_payload['claim_owner_hash'] = $content_claim_owner;
			$task_payload['outbox_id'] = $outbox_id;
			$task_payload['client_task_id'] = $client_task_id;
			$wpdb->update( $task_table, array( 'payload' => wp_json_encode( $task_payload ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $task_id ), array( '%s', '%s' ), array( '%d' ) );
			$payload['task_id'] = $task_id;
			$payload['client_task_id'] = $client_task_id;
			$wpdb->update( wptsall_table( 'content_change_outbox' ), array( 'payload' => wp_json_encode( $payload ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $outbox_id ), array( '%s', '%s' ), array( '%d' ) );
			$items[] = array(
				'task_id'        => $task_id,
				'outbox_id'      => $outbox_id,
				'event_key'      => (string) ( $row['event_key'] ?? '' ),
				'client_task_id' => $client_task_id,
				'relation_id'    => $relation_id,
				'item'           => array(
					'object_type'  => $object_type,
					'subtype'      => $subtype,
					'object_id'    => $source_id,
					'needs_resync' => true,
					'complete_data'=> $this->flatten_outbox_complete_data_for_client( $complete ),
				),
			);
		}
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'items' => $items, 'schema_version' => 1 ) ) );
	}

	/**
	 * Acquire the source mapping lease used by an outbox task.
	 *
	 * Outbox leasing and content mapping leasing are separate durable records.
	 * Materializing an outbox task must acquire both so the callback can prove
	 * ownership all the way through target write-back.
	 *
	 * @param array  $relation         Active relation.
	 * @param string $object_type      post_type or taxonomy.
	 * @param string $subtype          Post type or taxonomy.
	 * @param int    $object_id        Source object id.
	 * @param string $claim_owner_hash Owner digest.
	 * @return bool
	 */
	private function claim_outbox_source_mapping( array $relation, $object_type, $subtype, $object_id, $claim_owner_hash ) {
		global $wpdb;
		$relation_id    = absint( $relation['id'] ?? 0 );
		$source_site_id = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = sanitize_text_field( (string) ( $relation['target_site_id'] ?? '' ) );
		$source_lang    = sanitize_text_field( (string) ( $relation['source_lang'] ?? '' ) );
		$target_lang    = sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) );
		$object_type    = sanitize_key( (string) $object_type );
		$subtype        = sanitize_key( (string) $subtype );
		$claim_owner_hash = strtolower( trim( (string) $claim_owner_hash ) );
		if ( $relation_id <= 0 || $source_site_id <= 0 || '' === $target_site_id || '' === $subtype || ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return false;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		$now    = current_time( 'mysql', true );

		if ( 'taxonomy' === $object_type ) {
			$table = wptsall_table( 'term_mappings' );
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE relation_id = %d AND source_term_id = %d AND source_taxonomy = %s AND source_site_id = %d AND target_site_id = %s AND target_lang = %s LIMIT 1',
					$table, $relation_id, (int) $object_id, $subtype, $source_site_id, $target_site_id, $target_lang
				),
				ARRAY_A
			);
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$updated = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET needs_resync = 1, claimed_at = %s, claim_owner_hash = %s, updated_at = %s WHERE id = %d AND (claimed_at IS NULL OR claimed_at < %s OR claim_owner_hash = %s)',
						$table, $now, $claim_owner_hash, $now, (int) $row['id'], $cutoff, $claim_owner_hash
					)
				);
				return false !== $updated && $updated > 0;
			}
			$inserted = $wpdb->insert(
				$table,
				array(
					'relation_id' => $relation_id, 'source_term_id' => (int) $object_id, 'source_taxonomy' => $subtype,
					'source_site_id' => $source_site_id, 'source_lang' => $source_lang, 'target_term_id' => 0,
					'target_taxonomy' => $subtype, 'target_site_id' => $target_site_id, 'target_lang' => $target_lang,
					'mapping_method' => 'claim_placeholder', 'needs_resync' => 1, 'claimed_at' => $now,
					'claim_owner_hash' => $claim_owner_hash, 'created_at' => $now, 'updated_at' => $now,
				),
				array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			return false !== $inserted;
		}

		if ( 'post_type' !== $object_type ) {
			return false;
		}
		$table = wptsall_table( 'post_mappings' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND source_post_id = %d AND source_post_type = %s AND source_site_id = %d AND target_site_id = %s LIMIT 1',
				$table, $relation_id, (int) $object_id, $subtype, $source_site_id, $target_site_id
			),
			ARRAY_A
		);
		if ( is_array( $row ) && ! empty( $row['id'] ) ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET needs_resync = 1, claimed_at = %s, claim_owner_hash = %s, updated_at = %s WHERE id = %d AND (claimed_at IS NULL OR claimed_at < %s OR claim_owner_hash = %s)',
					$table, $now, $claim_owner_hash, $now, (int) $row['id'], $cutoff, $claim_owner_hash
				)
			);
			return false !== $updated && $updated > 0;
		}
		$inserted = $wpdb->insert(
			$table,
			array(
				'source_post_id' => (int) $object_id, 'source_post_type' => $subtype, 'source_site_id' => $source_site_id,
				'relation_id' => $relation_id, 'target_post_id' => 0, 'target_post_type' => $subtype,
				'target_site_id' => $target_site_id, 'relationship_type' => 'claim_placeholder', 'needs_resync' => 1,
				'claimed_at' => $now, 'claim_owner_hash' => $claim_owner_hash, 'created_at' => $now, 'updated_at' => $now,
			),
			array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return false !== $inserted;
	}

	/**
	 * Acknowledge a terminal no-op or release a leased outbox record for retry.
	 * Successful translation callbacks acknowledge automatically; this endpoint
	 * covers valid no-translation outcomes such as attachment lifecycle events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ack_content_change( $request ) {
		$outbox_id = absint( $request->get_param( 'id' ) );
		$body      = $request->get_json_params();
		$outcome   = sanitize_key( (string) ( is_array( $body ) ? ( $body['outcome'] ?? 'completed' ) : 'completed' ) );
		if ( $outbox_id <= 0 || ! class_exists( '\WPTSALL\Hooks\Content_Change_Dispatcher' ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'outbox_not_found' ), 404 );
		}
		global $wpdb;
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$outbox_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, relation_id, status, payload FROM %i WHERE id = %d LIMIT 1',
				$outbox_table,
				$outbox_id
			),
			ARRAY_A
		);
		if ( ! is_array( $outbox_row ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'outbox_not_found' ), 404 );
		}
		if ( 'completed' === sanitize_key( (string) ( $outbox_row['status'] ?? '' ) ) ) {
			return new \WP_REST_Response( array( 'success' => true, 'idempotent' => true, 'outbox_id' => $outbox_id, 'outcome' => 'completed' ), 200 );
		}
		if ( 'processing' !== sanitize_key( (string) ( $outbox_row['status'] ?? '' ) ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'outbox_not_claimed', 'message' => 'Outbox row is not currently claimed by a client.' ), 409 );
		}
		$payload = json_decode( (string) ( $outbox_row['payload'] ?? '' ), true );
		$claimed_owner = is_array( $payload ) ? strtolower( trim( (string) ( $payload['_wptsall_claim_owner_hash'] ?? '' ) ) ) : '';
		$request_device = sanitize_key( (string) $request->get_header( 'X-WPTSALL-Device-Id' ) );
		$ack_relation_id = absint( $outbox_row['relation_id'] ?? 0 );
		$expected_owner = function_exists( 'wptsall_client_claim_owner_hash' )
			? wptsall_client_claim_owner_hash( $request_device, $ack_relation_id, '', 'outbox' )
			: hash( 'sha256', 'wptsall-claim-owner-v1|' . $request_device . '|' . $ack_relation_id . '||outbox' );
		if ( '' === $request_device || ! preg_match( '/^[a-f0-9]{64}$/', $claimed_owner ) || ! hash_equals( $claimed_owner, $expected_owner ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'outbox_claim_owner_mismatch', 'message' => 'Only the device that claimed this outbox row may acknowledge it.' ), 403 );
		}
		if ( $ack_relation_id > 0 ) {
			$ack_relation = Site_Relation_Service::get_relation( $ack_relation_id );
			if ( ! is_array( $ack_relation ) || 'active' !== sanitize_key( (string) ( $ack_relation['status'] ?? '' ) ) ) {
				return new \WP_REST_Response( array( 'success' => false, 'error' => 'relation_inactive', 'message' => 'The outbox relation is no longer active.' ), 409 );
			}
		}
		if ( 'retry' === $outcome ) {
			$ok = \WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, sanitize_text_field( (string) ( $body['error'] ?? 'client_retry' ) ), $expected_owner );
		} else {
			$ok = \WPTSALL\Hooks\Content_Change_Dispatcher::complete_outbox( $outbox_id, $expected_owner );
		}
		return new \WP_REST_Response( array( 'success' => (bool) $ok, 'outbox_id' => $outbox_id, 'outcome' => $outcome ), $ok ? 200 : 409 );
	}

	/**
	 * Preserve the discovery endpoint's flattened content contract for outbox
	 * snapshots. The dispatcher returns canonical post/term/meta containers;
	 * the Rust field translator consumes their root-level field projection.
	 *
	 * @param array $complete_data Complete object data.
	 * @return array
	 */
	private function flatten_outbox_complete_data_for_client( array $complete_data ) {
		foreach ( array( 'post', 'term', 'meta' ) as $container ) {
			if ( ! empty( $complete_data[ $container ] ) && is_array( $complete_data[ $container ] ) ) {
				foreach ( $complete_data[ $container ] as $key => $value ) {
					if ( ! array_key_exists( $key, $complete_data ) ) {
						$complete_data[ $key ] = $value;
					}
				}
			}
		}
		return $complete_data;
	}

	/**
	 * Get pending language-pack template entries for client processing.
	 *
	 * This is intentionally entry-scoped and does not run a full scanner.
	 *
	 * @param array  $relation Relation data.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @param string $subtype  Template source type, e.g. plugin or theme.
	 * @param array  $include_ids Specific template entry IDs to fetch.
	 * @return \WP_REST_Response
	 */
	private function get_untranslated_language_pack_entries( $relation, $page, $per_page, $subtype = '', $include_ids = array() ) {
		global $wpdb;

		$relation_id     = (int) $relation['id'];
		$source_site_id  = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$current_site_id = (int) get_current_blog_id();
		if ( $source_site_id <= 0 ) {
			$source_site_id = $current_site_id;
		}
		if ( $source_site_id !== $current_site_id && ! is_multisite() ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'source_site_mismatch', 'message' => 'Relation source site is not available in this WordPress installation.' ), 403 );
		}
		$switched = is_multisite() && $source_site_id !== $current_site_id;
		if ( $switched ) {
			switch_to_blog( $source_site_id );
		}
		try {
		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );
		$claim_timeout   = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		$target_lang     = sanitize_text_field( (string) ( $relation['target_lang'] ?? $relation['target_language'] ?? '' ) );
		$subtype         = sanitize_key( (string) $subtype );
		$source_types    = array( 'plugin', 'theme', 'config' );

		// Base WHERE is fixed placeholder fragments; optional IN list uses literal %d only.
		$where  = "t.relation_id = %d AND e.status = %s AND (e.claimed_at IS NULL OR e.claimed_at < %s)";
		$params = array( $entries_table, $templates_table, $relation_id, 'pending', $claim_timeout );
		list( $source_type_sql, $source_type_args ) = wptsall_db_prepare_string_in( $source_types );
		$where  .= " AND t.source_type IN ($source_type_sql)";
		$params = array_merge( $params, $source_type_args );

		if ( ! empty( $subtype ) ) {
			if ( ! in_array( $subtype, $source_types, true ) ) {
				return new \WP_REST_Response(
					array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per_page ),
					200
				);
			}
			$where   .= ' AND t.source_type = %s';
			$params[] = $subtype;
		}

		// A blank target_language is a legacy/shared template. It is eligible for
		// the relation target, but a template for another target is not.
		if ( '' !== $target_lang ) {
			$where   .= ' AND (t.target_language = %s OR t.target_language = %s OR t.target_language IS NULL)';
			$params[] = $target_lang;
			$params[] = '';
		}

		if ( ! empty( $include_ids ) ) {
			list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $include_ids );
			$where                   .= " AND e.id IN ($in_sql)";
			$params                   = array_merge( $params, $in_args );
		}

		$total = (int) wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i e INNER JOIN %i t ON e.template_id = t.id WHERE ' . $where,
			$params
		);

		$offset       = ( $page - 1 ) * $per_page;
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$rows         = wptsall_db_get_results(
			'SELECT e.id AS entry_id, e.template_id, e.msgid, e.msgid_plural,
					e.msgctxt, e.reference,
					t.text_domain, t.source_type, t.source_name
				 FROM %i e
				 INNER JOIN %i t ON e.template_id = t.id
				 WHERE ' . $where . '
				 ORDER BY t.id ASC, e.id ASC
				 LIMIT %d OFFSET %d',
			$query_params,
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'object_type'   => 'language_pack',
				'subtype'       => $row['source_type'],
				'object_id'     => (int) $row['entry_id'],
				'template_id'   => (int) $row['template_id'],
				'text_domain'   => $row['text_domain'],
				'source_name'   => $row['source_name'],
				'complete_data' => array(
					'entry_id'     => (int) $row['entry_id'],
					'msgid'        => $row['msgid'],
					'msgid_plural' => $row['msgid_plural'] ?? '',
					'msgctxt'      => $row['msgctxt'] ?? '',
					'reference'    => $row['reference'] ?? '',
					'text_domain'  => $row['text_domain'],
				),
			);
		}

		return new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Get untranslated posts for a relation.
	 *
	 * Queries published posts of the given subtypes on the source site,
	 * excludes those that already have a post_mapping, and returns complete
	 * post data for the remainder.
	 *
	 * @since 1.1.0
	 *
	 * @param array $relation       Relation data.
	 * @param array $subtypes       Post type slugs.
	 * @param int   $page           Page number.
	 * @param int   $per_page       Items per page.
	 * @param bool  $include_resync Whether to also return needs_resync items.
	 * @param array $include_ids    Specific post IDs to fetch.
	 * @return \WP_REST_Response
	 */
	/**
	 * Legacy inline implementation retained only for backward source compatibility.
	 * Active routes use the aliased relation-scoped trait implementation above.
	 *
	 * @deprecated 2.1.0
	 */
	private function legacy_get_untranslated_posts( $relation, $subtypes, $page, $per_page, $include_resync = false, $include_ids = array() ) {
		global $wpdb;

		$relation_id    = (int) $relation['id'];
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = $relation['target_site_id'] ?? '';

		// Switch to source blog for multisite so $wpdb->posts points to the correct table.
		$switched = false;
		if ( is_multisite() && $source_site_id !== get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}

		try {
		// Fast path: include_ids is a selector, not an authorization bypass.
		// Require a relation-scoped mapping and apply the same source/claim
		// protections as normal discovery.
		if ( ! empty( $include_ids ) ) {
			if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
				wptsall_ensure_relation_scoped_mapping_tables();
			}
			list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $include_ids );
			$subtype_sql             = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );
			$mappings_table          = wptsall_table( 'post_mappings' );
			$claim_cutoff            = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
			$rows                     = wptsall_db_get_results(
				"SELECT p.ID, p.post_type, pm.id AS mapping_id, pm.needs_resync
					FROM %i p
					INNER JOIN %i pm ON pm.source_post_id = p.ID
						AND pm.source_post_type = p.post_type
						AND pm.source_site_id = %d
						AND pm.relation_id = %d
						AND pm.target_site_id = %s
					WHERE p.ID IN ($in_sql)
					AND p.post_type IN ($subtype_sql)
					AND p.post_status IN ('publish', 'inherit')
					AND (pm.target_post_id = 0 OR pm.needs_resync = 1)
					AND (pm.claimed_at IS NULL OR pm.claimed_at < %s)
					AND NOT EXISTS (
						SELECT 1 FROM %i vm
						WHERE vm.post_id = p.ID
						AND vm.meta_key = '_wptsall_virtual_site_id'
					)",
				array_merge(
					array( $wpdb->posts, $mappings_table, $source_site_id, $relation_id, (string) $target_site_id ),
					$in_args,
					$subtypes,
					array( $claim_cutoff, $wpdb->postmeta )
				),
				ARRAY_A
			);

			$items = array();
			foreach ( (array) $rows as $row ) {
				$post_id   = (int) $row['ID'];
				$post_type = $row['post_type'];
				if ( function_exists( 'wptsall_get_complete_post_data' ) ) {
					$complete_data = wptsall_get_complete_post_data( $post_type, $post_id );
				} else {
					$complete_data = null;
				}
				if ( $complete_data ) {
					$rev = class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
						? \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id )
						: '';
					$policy_version = class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
						? \WPTSALL\Core\Job_Snapshot::current_policy_version()
						: '';
					$complete_data  = $this->attach_job_snapshot( $complete_data, $rev, $policy_version );
					$items[] = array(
						'object_type'     => 'post_type',
						'subtype'         => $post_type,
						'object_id'       => $post_id,
						'needs_resync'    => ! empty( $row['needs_resync'] ),
						'mapping_id'      => (int) ( $row['mapping_id'] ?? 0 ),
						'source_revision' => $rev,
						'policy_version'  => $policy_version,
						'complete_data'   => $complete_data,
					);
				}
			}

			return new \WP_REST_Response(
				array(
					'items'    => $items,
					'total'    => count( $items ),
					'page'     => 1,
					'per_page' => count( $items ),
				),
				200
			);
		}

		$mappings_table = wptsall_table( 'post_mappings' );

		// Build the subtype IN clause.
		$subtype_placeholders = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );

		// --- Untranslated posts (no mapping exists) ---

		// Count total untranslated posts.
		$count_params = array_merge(
			array( $wpdb->posts ),
			$subtypes,
			array( $mappings_table, $source_site_id, $relation_id, $target_site_id, $wpdb->postmeta )
		);

		$untranslated_total = (int) wptsall_db_get_var(
			"SELECT COUNT(DISTINCT p.ID)
				FROM %i p
				WHERE p.post_type IN ({$subtype_placeholders})
				AND p.post_status = 'publish'
				AND NOT EXISTS (
					SELECT 1 FROM %i pm
					WHERE pm.source_post_id = p.ID
					AND pm.source_post_type = p.post_type
					AND pm.source_site_id = %d
					AND pm.relation_id = %d
					AND pm.target_site_id = %s
				)
				AND NOT EXISTS (
					SELECT 1 FROM %i vm
					WHERE vm.post_id = p.ID
					AND vm.meta_key = '_wptsall_virtual_site_id'
				)",
			$count_params
		);

		// Query the actual posts with pagination.
		$offset       = ( $page - 1 ) * $per_page;
		$query_params = array_merge(
			array( $wpdb->posts ),
			$subtypes,
			array( $mappings_table, $source_site_id, $relation_id, $target_site_id, $wpdb->postmeta, $per_page, $offset )
		);

		$post_rows = wptsall_db_get_results(
			"SELECT p.ID, p.post_type
				FROM %i p
				WHERE p.post_type IN ({$subtype_placeholders})
				AND p.post_status = 'publish'
				AND NOT EXISTS (
					SELECT 1 FROM %i pm
					WHERE pm.source_post_id = p.ID
					AND pm.source_post_type = p.post_type
					AND pm.source_site_id = %d
					AND pm.relation_id = %d
					AND pm.target_site_id = %s
				)
				AND NOT EXISTS (
					SELECT 1 FROM %i vm
					WHERE vm.post_id = p.ID
					AND vm.meta_key = '_wptsall_virtual_site_id'
				)
				ORDER BY p.ID ASC
				LIMIT %d OFFSET %d",
			$query_params,
			ARRAY_A
		);

		// Build complete data for each post.
		$items = array();
		foreach ( (array) $post_rows as $row ) {
			$post_id   = (int) $row['ID'];
			$post_type = $row['post_type'];

			if ( function_exists( 'wptsall_get_complete_post_data' ) ) {
				$complete_data = wptsall_get_complete_post_data( $post_type, $post_id );
			} else {
				$complete_data = null;
			}

			if ( $complete_data ) {
				$rev = class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
					? \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id )
					: '';
				$policy_version = class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
					? \WPTSALL\Core\Job_Snapshot::current_policy_version()
					: '';
				$complete_data  = $this->attach_job_snapshot( $complete_data, $rev, $policy_version );
				$items[] = array(
					'object_type'     => 'post_type',
					'subtype'         => $post_type,
					'object_id'       => $post_id,
					'needs_resync'    => false,
					'source_revision' => $rev,
					'policy_version'  => $policy_version,
					'complete_data'   => $complete_data,
				);
			}
		}

		// --- Resync posts (mapping exists with needs_resync = 1, not claimed) ---
		$resync_total = 0;
		if ( $include_resync ) {
			$claim_timeout = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );

			$resync_count_params = array_merge(
				array( $mappings_table, $wpdb->posts, $source_site_id, $relation_id, $target_site_id, $claim_timeout ),
				$subtypes,
				array( $wpdb->postmeta )
			);

			$resync_total = (int) wptsall_db_get_var(
				"SELECT COUNT(*)
					FROM %i pm
					INNER JOIN %i p ON pm.source_post_id = p.ID AND pm.source_post_type = p.post_type
					WHERE pm.source_site_id = %d
					AND pm.relation_id = %d
					AND pm.target_site_id = %s
					AND pm.needs_resync = 1
					AND (pm.claimed_at IS NULL OR pm.claimed_at < %s)
					AND p.post_status = 'publish'
					AND p.post_type IN ({$subtype_placeholders})
					AND NOT EXISTS (
						SELECT 1 FROM %i vm
						WHERE vm.post_id = p.ID
						AND vm.meta_key = '_wptsall_virtual_site_id'
					)",
				$resync_count_params
			);

			// Append resync items to the page (fill remaining slots).
			$remaining = $per_page - count( $items );
			if ( $remaining > 0 && $resync_total > 0 ) {
				$resync_query_params = array_merge(
					array( $mappings_table, $wpdb->posts, $source_site_id, $relation_id, $target_site_id, $claim_timeout ),
					$subtypes,
					array( $wpdb->postmeta, $remaining )
				);

				$resync_rows = wptsall_db_get_results(
					"SELECT pm.source_post_id AS ID, pm.source_post_type AS post_type, pm.id AS mapping_id
						FROM %i pm
						INNER JOIN %i p ON pm.source_post_id = p.ID AND pm.source_post_type = p.post_type
						WHERE pm.source_site_id = %d
						AND pm.relation_id = %d
						AND pm.target_site_id = %s
						AND pm.needs_resync = 1
						AND (pm.claimed_at IS NULL OR pm.claimed_at < %s)
						AND p.post_status = 'publish'
						AND p.post_type IN ({$subtype_placeholders})
						AND NOT EXISTS (
							SELECT 1 FROM %i vm
							WHERE vm.post_id = p.ID
							AND vm.meta_key = '_wptsall_virtual_site_id'
						)
						ORDER BY pm.source_post_id ASC
						LIMIT %d",
					$resync_query_params,
					ARRAY_A
				);

				foreach ( (array) $resync_rows as $row ) {
					$post_id   = (int) $row['ID'];
					$post_type = $row['post_type'];

					if ( function_exists( 'wptsall_get_complete_post_data' ) ) {
						$complete_data = wptsall_get_complete_post_data( $post_type, $post_id );
					} else {
						$complete_data = null;
					}

					if ( $complete_data ) {
						$complete_data = $this->attach_current_job_snapshot( $complete_data, 'post_type', $post_id );
						$items[] = array(
							'object_type'   => 'post_type',
							'subtype'       => $post_type,
							'object_id'     => $post_id,
							'needs_resync'  => true,
							'mapping_id'    => (int) $row['mapping_id'],
							'complete_data' => $complete_data,
						);
					}
				}
			}
		}

		$total = $untranslated_total + $resync_total;

		wptsall_log_info(
			'client-api',
			'Content discovery: posts',
			array(
				'relation_id'      => $relation_id,
				'subtypes'         => $subtypes,
				'untranslated'     => $untranslated_total,
				'resync'           => $resync_total,
				'total'            => $total,
				'page'             => $page,
				'returned'         => count( $items ),
				'include_resync'   => $include_resync,
			)
		);

		return new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Get untranslated terms for a relation.
	 *
	 * Queries terms of the given taxonomy subtypes, excludes those that
	 * already have a term_mapping, and returns complete term data.
	 *
	 * @since 1.1.0
	 *
	 * @param array $relation Relation data.
	 * @param array $subtypes Taxonomy slugs.
	 * @param int   $page     Page number.
	 * @param int   $per_page Items per page.
	 * @return \WP_REST_Response
	 */
	/**
	 * Legacy inline implementation retained only for backward source compatibility.
	 * Active routes use the aliased relation-scoped trait implementation above.
	 *
	 * @deprecated 2.1.0
	 */
	private function legacy_get_untranslated_terms( $relation, $subtypes, $page, $per_page, $include_ids = array(), $include_resync = false ) {
		global $wpdb;

		$relation_id    = (int) $relation['id'];
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
		$target_lang    = sanitize_text_field( (string) ( $relation['target_lang'] ?? $relation['target_language'] ?? '' ) );

		// Discovery is always evaluated in the relation's source blog. A numeric
		// term ID is not portable between multisite blogs.
		$switched = false;
		if ( is_multisite() && $source_site_id !== get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}

		try {
		// Fast path: include_ids is a selector, not an authorization bypass.
		if ( ! empty( $include_ids ) ) {
			list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $include_ids );
			$tax_sql                  = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );
			$term_mappings_table      = wptsall_table( 'term_mappings' );
			$claim_cutoff             = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
			$rows                     = wptsall_db_get_results(
				"SELECT DISTINCT t.term_id, tt.taxonomy, tm.id AS mapping_id, tm.needs_resync
					FROM %i t
					INNER JOIN %i tt ON t.term_id = tt.term_id
					INNER JOIN %i tm ON tm.source_term_id = t.term_id
						AND tm.source_taxonomy = tt.taxonomy
						AND tm.source_site_id = %d
						AND tm.relation_id = %d
						AND tm.target_site_id = %s
						AND tm.target_lang = %s
					WHERE t.term_id IN ($in_sql)
					AND tt.taxonomy IN ($tax_sql)
					AND (tm.target_term_id = 0 OR tm.needs_resync = 1)
					AND (tm.claimed_at IS NULL OR tm.claimed_at < %s)
					AND NOT EXISTS (
						SELECT 1 FROM %i vm
						WHERE vm.term_id = t.term_id
						AND vm.meta_key = '_wptsall_virtual_site_id'
					)",
				array_merge(
					array( $wpdb->terms, $wpdb->term_taxonomy, $term_mappings_table, $source_site_id, $relation_id, $target_site_id, $target_lang ),
					$in_args,
					$subtypes,
					array( $claim_cutoff, $wpdb->termmeta )
				),
				ARRAY_A
			);

			$items = array();
			foreach ( (array) $rows as $row ) {
				$term_id  = (int) $row['term_id'];
				$taxonomy = $row['taxonomy'];
				if ( function_exists( 'wptsall_get_complete_term_data' ) ) {
					$complete_data = wptsall_get_complete_term_data( $taxonomy, $term_id );
				} else {
					$complete_data = null;
				}
				if ( $complete_data ) {
					$complete_data = $this->attach_current_job_snapshot( $complete_data, 'taxonomy', $term_id );
					$items[] = array(
						'object_type'   => 'taxonomy',
						'subtype'       => $taxonomy,
						'object_id'     => $term_id,
						'needs_resync'  => ! empty( $row['needs_resync'] ),
						'mapping_id'    => (int) ( $row['mapping_id'] ?? 0 ),
						'complete_data' => $complete_data,
					);
				}
			}
			return new \WP_REST_Response(
				array(
					'items'    => $items,
					'total'    => count( $items ),
					'page'     => 1,
					'per_page' => count( $items ),
				),
				200
			);
		}

		$term_mappings_table = wptsall_table( 'term_mappings' );
		$tt_table            = $wpdb->term_taxonomy;

		// Build the taxonomy IN clause.
		$tax_placeholders = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );

		// Count total untranslated terms.
		$count_params = array_merge(
			array( $wpdb->terms, $tt_table ),
			$subtypes,
			array( $term_mappings_table, $source_site_id, $relation_id, $target_site_id, $target_lang, $wpdb->termmeta )
		);

		$total = (int) wptsall_db_get_var(
			"SELECT COUNT(DISTINCT t.term_id)
				FROM %i t
				INNER JOIN %i tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy IN ({$tax_placeholders})
				AND NOT EXISTS (
					SELECT 1 FROM %i tm
					WHERE tm.source_term_id = t.term_id
					AND tm.source_taxonomy = tt.taxonomy
					AND tm.source_site_id = %d
					AND tm.relation_id = %d
					AND tm.target_site_id = %s
					AND tm.target_lang = %s
				)
				AND NOT EXISTS (
					SELECT 1 FROM %i vm
					WHERE vm.term_id = t.term_id
					AND vm.meta_key = '_wptsall_virtual_site_id'
				)",
			$count_params
		);

		// Query the actual terms with pagination.
		$offset       = ( $page - 1 ) * $per_page;
		$query_params = array_merge(
			array( $wpdb->terms, $tt_table ),
			$subtypes,
			array( $term_mappings_table, $source_site_id, $relation_id, $target_site_id, $target_lang, $wpdb->termmeta, $per_page, $offset )
		);

		$term_rows = wptsall_db_get_results(
			"SELECT DISTINCT t.term_id, tt.taxonomy
				FROM %i t
				INNER JOIN %i tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy IN ({$tax_placeholders})
				AND NOT EXISTS (
					SELECT 1 FROM %i tm
					WHERE tm.source_term_id = t.term_id
					AND tm.source_taxonomy = tt.taxonomy
					AND tm.source_site_id = %d
					AND tm.relation_id = %d
					AND tm.target_site_id = %s
					AND tm.target_lang = %s
				)
				AND NOT EXISTS (
					SELECT 1 FROM %i vm
					WHERE vm.term_id = t.term_id
					AND vm.meta_key = '_wptsall_virtual_site_id'
				)
				ORDER BY t.term_id ASC
				LIMIT %d OFFSET %d",
			$query_params,
			ARRAY_A
		);

		// Build complete data for each term.
		$items = array();
		foreach ( (array) $term_rows as $row ) {
			$term_id  = (int) $row['term_id'];
			$taxonomy = $row['taxonomy'];

			if ( function_exists( 'wptsall_get_complete_term_data' ) ) {
				$complete_data = wptsall_get_complete_term_data( $taxonomy, $term_id );
			} else {
				$complete_data = null;
			}

			if ( $complete_data ) {
				$complete_data = $this->attach_current_job_snapshot( $complete_data, 'taxonomy', $term_id );
				$items[] = array(
					'object_type'   => 'taxonomy',
					'subtype'       => $taxonomy,
					'object_id'     => $term_id,
					'complete_data' => $complete_data,
				);
			}
		}

		wptsall_log_info(
			'client-api',
			'Content discovery: untranslated terms',
			array(
				'relation_id' => $relation_id,
				'subtypes'    => $subtypes,
				'total'       => $total,
				'page'        => $page,
				'returned'    => count( $items ),
			)
		);

		return new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * POST /client/content/claim
	 *
	 * Client declares it is processing a batch of content items.
	 * Uses a simple time lock: sets `claimed_at` in post_mappings.
	 * Claims expire after 30 minutes (unclaimed items reappear in content discovery).
	 *
	 * Body: { "relation_id": int, "items": [ { "object_id": int, "post_type": string } ] }
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function claim_content( $request ) {
		$rate_error = $this->check_rate_limit( 'content_claim' );
		if ( $rate_error ) {
			return $rate_error;
		}

		$body        = $request->get_json_params();
		$relation_id = absint( $body['relation_id'] ?? 0 );
		$items       = $body['items'] ?? array();
		$data_type   = $this->normalize_content_data_type( (string) ( $body['data_type'] ?? 'post' ) );
		$claim_subtype = sanitize_key( (string) ( $body['subtype'] ?? '' ) );

		if ( empty( $relation_id ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'missing_relation_id', 'message' => 'relation_id is required.' ),
				400
			);
		}

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'missing_items', 'message' => 'items array is required.' ),
				400
			);
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'relation_not_found', 'message' => 'Site relation not found.' ),
				404
			);
		}
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'relation_inactive', 'message' => 'Site relation is not active.' ),
				409
			);
		}
		if ( ! in_array( $data_type, array( 'post', 'term', 'language_pack', 'site_string', 'option' ), true ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'error' => 'unsupported_data_type', 'message' => 'Unsupported data_type for claim.' ),
				400
			);
		}
		$claim_source_site_id = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$claim_current_site_id = (int) get_current_blog_id();
		if ( $claim_source_site_id <= 0 ) {
			$claim_source_site_id = $claim_current_site_id;
		}
		if ( $claim_source_site_id !== $claim_current_site_id && ! is_multisite() ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'source_site_mismatch', 'message' => 'Relation source site is not available in this WordPress installation.' ), 403 );
		}
		$claim_switched = is_multisite() && $claim_source_site_id !== $claim_current_site_id;
		if ( $claim_switched ) {
			switch_to_blog( $claim_source_site_id );
		}
		try {
		if ( 'language_pack' === $data_type ) {
			return $this->claim_language_pack_entries( $relation, $items, $claim_subtype, $request );
		}
		if ( 'site_string' === $data_type ) {
			if ( ! $this->relation_allows_site_string_lane( $relation, $claim_subtype ) ) {
				return new \WP_REST_Response( array( 'success' => false, 'error' => 'subtype_not_allowed', 'message' => 'The requested site string lane is not enabled for this site relation.' ), 403 );
			}
			$target_lang = sanitize_text_field( (string) ( $relation['target_lang'] ?? $relation['target_language'] ?? '' ) );
			$owner_hash  = $this->get_claim_owner_hash( $request, $relation, $claim_subtype, $target_lang );
			return $this->trait_claim_site_string_entries( $relation_id, $items, $claim_subtype, $owner_hash, $target_lang );
		}
		if ( 'option' === $data_type ) {
			return $this->claim_option_entries( $relation, $items, $request );
		}

		global $wpdb;
		if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
			wptsall_ensure_relation_scoped_mapping_tables();
		}
		$mappings_table = wptsall_table( 'post_mappings' );
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
		$now            = current_time( 'mysql', true );
		$claimed_count  = 0;
		$claimed_items  = array();
		$rejected_items = array();
		$is_term_claim  = in_array( $data_type, array( 'term', 'taxonomy' ), true );
		$claim_cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );

		// Taxonomy claim lock must respect term_mappings.claimed_at, same as post mappings.
		if ( $is_term_claim ) {
			$term_mappings_table = wptsall_table( 'term_mappings' );
			$source_lang         = (string) ( $relation['source_lang'] ?? '' );
			$target_lang         = (string) ( $relation['target_lang'] ?? '' );

			foreach ( $items as $item ) {
				$object_id = absint( $item['object_id'] ?? 0 );
				$taxonomy  = sanitize_key( (string) ( $item['taxonomy'] ?? $item['post_type'] ?? $item['subtype'] ?? '' ) );
				if ( $object_id <= 0 || '' === $taxonomy ) {
					$rejected_items[] = array( 'object_id' => $object_id, 'taxonomy' => $taxonomy, 'error' => 'invalid_item' );
					continue;
				}
				$source_validation = $this->validate_discovery_object_for_relation( $relation, 'term', $taxonomy, $object_id );
				if ( is_wp_error( $source_validation ) ) {
					$rejected_items[] = array( 'object_id' => $object_id, 'taxonomy' => $taxonomy, 'error' => $source_validation->get_error_code() );
					continue;
				}
				$claim_owner_hash = $this->get_claim_owner_hash( $request, $relation, $taxonomy, $target_lang );

				// 1) Reclaim stale placeholder claims.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$reclaimed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i
						 SET claimed_at = %s, updated_at = %s, claim_owner_hash = %s
						 WHERE relation_id = %d
						   AND source_term_id = %d
						   AND source_taxonomy = %s
						   AND source_site_id = %d
						   AND target_site_id = %s
						   AND target_lang = %s
						   AND target_term_id = 0
						   AND (claimed_at IS NULL OR claimed_at < %s)",
						$term_mappings_table,
						$now,
						$now,
						$claim_owner_hash,
						$relation_id,
						$object_id,
						$taxonomy,
						$source_site_id,
						$target_site_id,
						$target_lang,
						$claim_cutoff
					)
				);

				if ( false !== $reclaimed && $reclaimed > 0 ) {
					++$claimed_count;
					$claimed_items[] = array(
						'object_id' => $object_id,
						'taxonomy'  => $taxonomy,
					);
					continue;
				}

				// 2) Existing row: mapped rows require needs_resync; active claim locks are not reclaimable.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, target_term_id, needs_resync, claimed_at
						 FROM %i
						 WHERE relation_id = %d
						   AND source_term_id = %d
						   AND source_taxonomy = %s
						   AND source_site_id = %d
						   AND target_site_id = %s
						   AND target_lang = %s
						 LIMIT 1",
						$term_mappings_table,
						$relation_id,
						$object_id,
						$taxonomy,
						$source_site_id,
						$target_site_id,
						$target_lang
					),
					ARRAY_A
				);

				if ( is_array( $existing ) ) {
					$mapped_target_id = (int) ( $existing['target_term_id'] ?? 0 );
					$needs_resync     = ! empty( $existing['needs_resync'] );
					if ( $mapped_target_id > 0 && ! $needs_resync ) {
						continue;
					}

					$existing_claimed_at = (string) ( $existing['claimed_at'] ?? '' );
					if ( '' !== $existing_claimed_at && $existing_claimed_at >= $claim_cutoff ) {
						continue;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$updated = $wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET claimed_at = %s, claim_owner_hash = %s, updated_at = %s WHERE id = %d AND (claimed_at IS NULL OR claimed_at < %s)',
							$term_mappings_table,
							$now,
							$claim_owner_hash,
							$now,
							(int) $existing['id'],
							$claim_cutoff
						)
					);
					if ( false !== $updated && $updated > 0 ) {
						++$claimed_count;
						$claimed_items[] = array(
							'object_id' => $object_id,
							'taxonomy'  => $taxonomy,
						);
					}
					continue;
				}

				// 3) No mapping row yet — insert placeholder claim row.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$inserted = $wpdb->insert(
					$term_mappings_table,
					array(
						'relation_id'        => $relation_id,
						'source_term_id'     => $object_id,
						'source_taxonomy'    => $taxonomy,
						'source_site_id'     => $source_site_id,
						'source_lang'        => $source_lang,
						'target_term_id'     => 0,
						'target_taxonomy'    => $taxonomy,
						'target_site_id'     => $target_site_id,
						'target_lang'        => $target_lang,
						'mapping_method'     => 'claim_placeholder',
						'translation_method' => null,
						'needs_resync'       => 0,
						'claimed_at'         => $now,
						'claim_owner_hash'   => $claim_owner_hash,
						'created_at'         => $now,
						'updated_at'         => $now,
					),
					array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
				);
				if ( $inserted ) {
					++$claimed_count;
					$claimed_items[] = array(
						'object_id' => $object_id,
						'taxonomy'  => $taxonomy,
					);
				}
			}

			wptsall_log_info(
				'client-api',
				'Content claimed by client',
				array(
					'relation_id'   => $relation_id,
					'data_type'     => 'term',
					'items_sent'    => count( $items ),
					'claimed_count' => $claimed_count,
				)
			);

			return new \WP_REST_Response(
				array(
					'success'       => true,
					'claimed_count' => $claimed_count,
					'claimed_items' => $claimed_items,
					'rejected_items' => $rejected_items,
				),
				200
			);
		}

		foreach ( $items as $item ) {
			$object_id = absint( $item['object_id'] ?? 0 );
			$post_type = sanitize_key( (string) ( $item['post_type'] ?? $item['subtype'] ?? 'post' ) );

			if ( empty( $object_id ) ) {
				$rejected_items[] = array( 'object_id' => $object_id, 'post_type' => $post_type, 'error' => 'invalid_item' );
				continue;
			}
			$source_validation = $this->validate_discovery_object_for_relation( $relation, 'post', $post_type, $object_id );
			if ( is_wp_error( $source_validation ) ) {
				$rejected_items[] = array( 'object_id' => $object_id, 'post_type' => $post_type, 'error' => $source_validation->get_error_code() );
				continue;
			}
			$claim_owner_hash = $this->get_claim_owner_hash( $request, $relation, $post_type, (string) ( $relation['target_lang'] ?? '' ) );

			// Reclaim stale claim rows; keep active claim locks untouched.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i
					 SET claimed_at = %s, updated_at = %s, claim_owner_hash = %s
					 WHERE relation_id = %d
					   AND source_post_id = %d
					   AND source_post_type = %s
					   AND source_site_id = %d
					   AND target_site_id = %s
					   AND (target_post_id = 0 OR needs_resync = 1)
					   AND (claimed_at IS NULL OR claimed_at < %s)",
					$mappings_table,
					$now,
					$now,
					$claim_owner_hash,
					$relation_id,
					$object_id,
					$post_type,
					$source_site_id,
					$target_site_id,
					$claim_cutoff
				)
			);

			if ( false !== $result && $result > 0 ) {
				++$claimed_count;
				$claimed_items[] = array(
					'object_id' => $object_id,
					'post_type' => $post_type,
				);
			} elseif ( 0 === $result ) {
				// No mapping row yet — create placeholder claim row.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$inserted = $wpdb->insert(
					$mappings_table,
					array(
						'source_post_id'    => $object_id,
						'source_post_type'  => $post_type,
						'source_site_id'    => $source_site_id,
						'relation_id'       => $relation_id,
						'target_post_id'    => 0,
						'target_post_type'  => $post_type,
						'target_site_id'    => $target_site_id,
						'relationship_type' => 'claim_placeholder',
						'claimed_at'        => $now,
						'claim_owner_hash'  => $claim_owner_hash,
						'created_at'        => $now,
						'updated_at'        => $now,
					),
					array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( $inserted ) {
					++$claimed_count;
					$claimed_items[] = array(
						'object_id' => $object_id,
						'post_type' => $post_type,
					);
				}
			}
		}

		wptsall_log_info(
			'client-api',
			'Content claimed by client',
			array(
				'relation_id'   => $relation_id,
				'data_type'     => $data_type,
				'items_sent'    => count( $items ),
				'claimed_count' => $claimed_count,
				'claimed_items' => count( $claimed_items ),
				'rejected_items' => count( $rejected_items ),
			)
		);

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'claimed_count' => $claimed_count,
				'claimed_items' => $claimed_items,
				'rejected_items' => $rejected_items,
			),
			200
		);
		} finally {
			if ( $claim_switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Claim option-backed content items for client processing.
	 *
	 * Option rows use the relation-scoped option sync state table rather than
	 * the post mapping table. This keeps the lease and resync lifecycle tied to
	 * the actual option name and prevents a synthetic numeric object id from
	 * becoming a cross-relation authorization key.
	 *
	 * @param array $relation Relation row.
	 * @param array $items    Items to claim.
	 * @return \WP_REST_Response
	 */
	private function claim_option_entries( array $relation, array $items, $request ) {
		$relation_id    = (int) ( $relation['id'] ?? 0 );
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
		$claimed_items  = array();
		$rejected_items = array();
		$claim_timeout  = self::get_claim_timeout_seconds();

		foreach ( $items as $item ) {
			$option_name = sanitize_key(
				(string) ( $item['option_name'] ?? $item['subtype'] ?? $item['post_type'] ?? '' )
			);
			$object_id = absint( $item['object_id'] ?? 0 );
			$expected_id = crc32( 'option:' . $option_name );
			if ( $expected_id < 0 ) {
				$expected_id *= -1;
			}
			if ( '' === $option_name || ! $this->relation_allows_option( $relation_id, $option_name ) ) {
				$rejected_items[] = array( 'object_id' => $object_id, 'option_name' => $option_name, 'error' => 'option_not_allowed' );
				continue;
			}
			if ( $object_id <= 0 || (int) $expected_id !== $object_id ) {
				$rejected_items[] = array( 'object_id' => $object_id, 'option_name' => $option_name, 'error' => 'option_object_mismatch' );
				continue;
			}

			$claimed = false;
			if ( class_exists( '\\WPTSALL\\Models\\Services\\Option_Sync_State_Service' ) ) {
				$owner_hash = $this->get_claim_owner_hash( $request, $relation, $option_name );
				$claimed = \WPTSALL\Models\Services\Option_Sync_State_Service::claim(
					$relation_id,
					$source_site_id,
					$target_site_id,
					$option_name,
					$claim_timeout,
					$owner_hash
				);
			}
			if ( $claimed ) {
				$claimed_items[] = array(
					'object_id'   => $object_id,
					'post_type'   => $option_name,
					'option_name' => $option_name,
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'claimed_count'  => count( $claimed_items ),
				'claimed_items'  => $claimed_items,
				'rejected_items' => $rejected_items,
			),
			200
		);
	}

	/**
	 * Claim pending language-pack entries by entry_id.
	 *
	 * @param int   $relation_id Relation ID.
	 * @param array $items       Items with entry_id or object_id.
	 * @return \WP_REST_Response
	 */
	private function claim_language_pack_entries( array $relation, $items, $subtype = '', $request = null ) {
		global $wpdb;

		$relation_id     = (int) ( $relation['id'] ?? 0 );
		$target_lang     = sanitize_text_field( (string) ( $relation['target_lang'] ?? $relation['target_language'] ?? '' ) );
		$subtype         = sanitize_key( (string) $subtype );
		$entries_table   = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );
		$now             = current_time( 'mysql', true );
		$claim_cutoff    = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		$claimed_count   = 0;
		$claimed_items   = array();
		$source_types    = array( 'plugin', 'theme', 'config' );
		list( $source_type_sql, $source_type_args ) = wptsall_db_prepare_string_in( $source_types );
		$owner_request   = $request instanceof \WP_REST_Request ? $request : new \WP_REST_Request();

		foreach ( $items as $item ) {
			$entry_id = absint( $item['entry_id'] ?? $item['object_id'] ?? 0 );
			if ( empty( $entry_id ) || ( '' !== $subtype && ! in_array( $subtype, $source_types, true ) ) ) {
				continue;
			}
			// The i18n callback validates ownership with a per-business_line scope
			// (plugin_i18n -> "plugin", theme_i18n -> "theme", ...). When the claim
			// request does not pin a subtype, resolve the entry template's own
			// source_type so the stamped digest matches that scope exactly.
			$entry_scope = $subtype;
			if ( '' === $entry_scope ) {
				$entry_scope = sanitize_key( (string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT t.source_type FROM %i e INNER JOIN %i t ON e.template_id = t.id WHERE e.id = %d AND t.relation_id = %d LIMIT 1',
						$entries_table,
						$templates_table,
						$entry_id,
						$relation_id
					)
				) );
			}
			$owner_hash = $this->get_claim_owner_hash( $owner_request, $relation, $entry_scope, $target_lang );
			$sql_args = array_merge(
				array( $entries_table, $templates_table, $now, $owner_hash, $entry_id, $relation_id, $claim_cutoff ),
				$source_type_args
			);
			$where = "e.id = %d AND t.relation_id = %d AND e.status = 'pending' AND (e.claimed_at IS NULL OR e.claimed_at < %s) AND t.source_type IN ($source_type_sql)";
			if ( '' !== $subtype ) {
				$where .= ' AND t.source_type = %s';
				$sql_args[] = $subtype;
			}
			if ( '' !== $target_lang ) {
				$where .= ' AND (t.target_language = %s OR t.target_language = %s OR t.target_language IS NULL)';
				$sql_args[] = $target_lang;
				$sql_args[] = '';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- entry claim CAS; caching is not applicable to a compare-and-set write.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is composed of literal placeholders only; the source-type IN-list placeholders come from wptsall_db_prepare_string_in(); every value binds through the merged arg list.
			$result = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i e INNER JOIN %i t ON e.template_id = t.id SET e.claimed_at = %s, e.updated_at = %s, e.claim_owner_hash = %s WHERE ' . $where,
					array_merge( array( $entries_table, $templates_table, $now, $now, $owner_hash ), array_slice( $sql_args, 4 ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			if ( false !== $result && $result > 0 ) {
				++$claimed_count;
				$claimed_items[] = array(
					'entry_id' => $entry_id,
				);
			}
		}

		wptsall_log_info(
			'client-api',
			'Language pack entries claimed',
			array(
				'relation_id'   => $relation_id,
				'items_sent'    => count( $items ),
				'claimed_count' => $claimed_count,
			)
		);

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'claimed_count' => $claimed_count,
				'claimed_items' => $claimed_items,
			),
			200
		);
	}

	// =====================================================================
	// Existing Endpoints (translation-callback, media-upload)
	// =====================================================================

	/**
	 * POST /client/media-upload
	 *
	 * Receives binary file data from the client and creates a WordPress attachment.
	 *
	 * Headers:
	 * - Content-Type: MIME type of the file (e.g., image/jpeg)
	 * - X-WPTSALL-Task-ID: Associated task ID
	 * - X-WPTSALL-Source-ID: Source attachment ID being translated
	 * - X-WPTSALL-Filename: Original filename
	 *
	 * Body: Raw binary file data
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function media_upload( $request ) {
		// 1. Read headers.
		$content_type_info = $request->get_content_type();
		$content_type      = isset( $content_type_info['value'] ) ? (string) $content_type_info['value'] : '';
		$task_id           = absint( $request->get_header( 'X-WPTSALL-Task-ID' ) );
		$source_id         = absint( $request->get_header( 'X-WPTSALL-Source-ID' ) );
		$filename          = sanitize_file_name( (string) $request->get_header( 'X-WPTSALL-Filename' ) );

		// 2. Validate required fields.
		if ( empty( $filename ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'missing_filename',
				'message' => 'X-WPTSALL-Filename header is required.',
			), 400 );
		}

		// 3. BLOCKED EXTENSIONS — explicit deny list.
		$blocked_extensions = array(
			'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht',
			'exe', 'sh', 'bat', 'cmd', 'com', 'msi', 'dll',
			'js', 'jsx', 'ts', 'mjs', 'cjs',
			'svg', // SVG can contain JavaScript.
			'htm', 'html', 'xhtml', 'shtml',
			'py', 'pl', 'rb', 'cgi', 'asp', 'aspx', 'jsp',
		);
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $blocked_extensions, true ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'blocked_file_type',
				'message' => 'File type is not allowed: .' . $ext,
			), 400 );
		}

		// 4. Validate MIME type against WordPress allowed types.
		$wp_filetype = wp_check_filetype( $filename );
		if ( empty( $wp_filetype['type'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'disallowed_file_type',
				'message' => 'WordPress does not allow this file type.',
			), 400 );
		}

		// 5. Only accept media types (images, audio, video, documents).
		$allowed_prefixes = array( 'image/', 'audio/', 'video/', 'application/pdf', 'application/msword', 'application/vnd.' );
		$type_allowed     = false;
		foreach ( $allowed_prefixes as $prefix ) {
			if ( 0 === strpos( $wp_filetype['type'], $prefix ) ) {
				$type_allowed = true;
				break;
			}
		}
		if ( ! $type_allowed ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'non_media_type',
				'message' => 'Only media files (images, audio, video, documents) are accepted.',
			), 400 );
		}

		// 6. Bind the upload to an active relation, its source attachment, and
		// an open task. Without all three checks a valid device token could
		// create arbitrary attachments or associate a file with another job.
		$relation_id_header = absint( $request->get_header( 'X-WPTSALL-Relation-ID' ) );
		if ( $relation_id_header <= 0 ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'missing_relation_id',
				'message' => 'X-WPTSALL-Relation-ID header is required.',
			), 400 );
		}
		if ( $task_id <= 0 ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'missing_task_id',
				'message' => 'X-WPTSALL-Task-ID header is required.',
			), 400 );
		}
		if ( $source_id <= 0 ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'missing_source_id',
				'message' => 'X-WPTSALL-Source-ID header is required.',
			), 400 );
		}
		$relation = Site_Relation_Service::get_relation( $relation_id_header );
		if ( ! $relation ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'relation_not_found', 'message' => 'Site relation was not found.' ), 404 );
		}
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'relation_inactive', 'message' => 'Site relation is not active.' ), 409 );
		}
		$source_check = $this->validate_source_object_for_relation(
			array( 'post_type' => 'attachment', 'subtype' => 'attachment' ),
			$relation,
			'media',
			$source_id
		);
		if ( is_wp_error( $source_check ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => $source_check->get_error_code(),
					'message' => $source_check->get_error_message(),
					'data'    => $source_check->get_error_data(),
				),
				(int) ( $source_check->get_error_data()['status'] ?? 403 )
			);
		}
		global $wpdb;
		$task_table = wptsall_table( 'tasks' );
		$task_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, relation_id, site_id, object_type, subtype, object_id, status FROM %i WHERE id = %d AND (relation_id = %d OR (relation_id = 0 AND site_id = %d)) LIMIT 1',
				$task_table,
				$task_id,
				$relation_id_header,
				$relation_id_header
			),
			ARRAY_A
		);
		if ( ! is_array( $task_row ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'task_relation_mismatch', 'message' => 'Task is not owned by the requested relation.' ), 403 );
		}
		$task_object_type = sanitize_key( (string) ( $task_row['object_type'] ?? '' ) );
		$task_subtype     = sanitize_key( (string) ( $task_row['subtype'] ?? '' ) );
		$task_status      = sanitize_key( (string) ( $task_row['status'] ?? '' ) );
		if ( (int) ( $task_row['object_id'] ?? 0 ) !== $source_id
			|| ! in_array( $task_object_type, array( 'media', 'post_type', 'post' ), true )
			|| ( '' !== $task_subtype && 'attachment' !== $task_subtype )
			|| ! in_array( $task_status, array( 'pending', 'retry', 'processing', 'active' ), true ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'task_source_mismatch', 'message' => 'Task does not address the requested source attachment or is not claimable.' ), 409 );
		}

		// 7. Get binary body.
		$body = $request->get_body();
		if ( empty( $body ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'empty_body',
				'message' => 'Request body is empty.',
			), 400 );
		}

		// 8. Max file size check (50MB).
		$max_size = 50 * 1024 * 1024;
		if ( strlen( $body ) > $max_size ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'file_too_large',
				'message' => 'File exceeds maximum allowed size (50MB).',
			), 400 );
		}

		// 9. Write to temp file.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp_file = \wp_tempnam( $filename );
		if ( ! $tmp_file ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'temp_file_failed',
				'message' => 'Failed to create temporary file.',
			), 500 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $tmp_file, $body );
		if ( false === $written || $written !== strlen( $body ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp_file );
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'temp_file_write_failed',
				'message' => 'Failed to write the uploaded file.',
			), 500 );
		}

		// 10. Validate actual file content with wp_check_filetype_and_ext().
		$validate = wp_check_filetype_and_ext( $tmp_file, $filename );
		if ( ! empty( $validate['proper_filename'] ) ) {
			$filename = $validate['proper_filename'];
		}
		$declared_mime = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		$actual_mime   = strtolower( (string) ( $validate['type'] ?? '' ) );
		if ( false === $validate['type']
			|| ( ! empty( $wp_filetype['type'] ) && $actual_mime !== strtolower( (string) $wp_filetype['type'] ) )
			|| ( '' !== $declared_mime && $actual_mime !== $declared_mime ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp_file );
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'content_type_mismatch',
				'message' => 'File content does not match declared type.',
			), 400 );
		}

		// 11. Use media_handle_sideload to create attachment.
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
			'size'     => strlen( $body ),
			'error'    => UPLOAD_ERR_OK,
		);

		// Require media handling functions.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$attachment_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp_file );
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'sideload_failed',
				'message' => $attachment_id->get_error_message(),
			), 500 );
		}

		// 12. Store metadata linking to source.
		if ( $source_id > 0 ) {
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, '_wptsall_source_attachment_id', $source_id );
		}
		if ( $task_id > 0 ) {
			update_post_meta( $attachment_id, '_wptsall_task_id', $task_id );
		}

		// 13. Write media_mappings so Sync_Executor can resolve URLs later.
		if ( $source_id > 0 && $relation_id_header > 0 ) {
			if ( $relation ) {
				$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
				$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
				if ( ! empty( $target_site_id ) ) {
					\WPTSALL\Models\Services\Media_Mapping_Service::create_mapping( array(
						'source_media_id'  => $source_id,
						'relation_id'      => $relation_id_header,
						'source_site_id'   => $source_site_id,
						'source_file_path' => '',
						'source_file_url'  => '',
						'target_media_id'  => $attachment_id,
						'target_site_id'   => $target_site_id,
						'target_file_path' => get_attached_file( $attachment_id ) ?: '',
						'target_file_url'  => wp_get_attachment_url( $attachment_id ) ?: '',
						'mapping_method'   => 'client_upload',
					) );
				}
			}
		}

		$attachment_url = wp_get_attachment_url( $attachment_id );

		wptsall_log_info( 'client-api', 'Media uploaded via binary upload endpoint', array(
			'attachment_id' => $attachment_id,
			'filename'      => $filename,
			'task_id'       => $task_id,
			'source_id'     => $source_id,
			'size'          => strlen( $body ),
		) );

		return new \WP_REST_Response( array(
			'success'        => true,
			'attachment_id'  => $attachment_id,
			'attachment_url' => $attachment_url,
			'url'            => $attachment_url,
		), 200 );
	}

	/**
	 * POST /client/translation-callback
	 *
	 * Receives translated content from client and saves it.
	 *
	 * Business lines:
	 * - post_content / taxonomy_content / custom_model: Save to translation_results -> create sync task
	 * - theme_i18n / plugin_i18n: Write to template_entries
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function translation_callback( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Compute this before the Idempotency-Key cache lookup. Replays must be
		// bound to the same canonical request body, not only to a client header.
		$request_hash = class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
			? \WPTSALL\Core\Job_Snapshot::request_body_hash( $body )
			: hash( 'sha256', (string) wp_json_encode( $body ) );

		// Idempotency-Key header (client-wpplugin sends this for safe retries).
		// If present and previously processed, return the cached response without
		// re-running business logic. This is in addition to the per-task
		// client_task_id dedup below.
		$idempotency_key = (string) $request->get_header( 'Idempotency-Key' );
		if ( '' !== $idempotency_key && function_exists( 'wptsall_idempotency_get' ) ) {
			$cached = wptsall_idempotency_get( $idempotency_key, $request_hash );
			if ( null !== $cached ) {
				if ( ! empty( $cached['conflict'] ) ) {
					wptsall_log_warning(
						'client-api',
						'Translation callback Idempotency-Key reused with different body',
						array( 'idempotency_key' => substr( $idempotency_key, 0, 16 ) . '...' )
					);
					return new \WP_REST_Response(
						array(
							'success' => false,
							'error'   => 'idempotency_conflict',
							'message' => 'Same Idempotency-Key with different body.',
						),
						409
					);
				}
				wptsall_log_info(
					'client-api',
					'Translation callback idempotent replay',
					array(
						'idempotency_key' => substr( $idempotency_key, 0, 16 ) . '...',
						'cached_status'   => $cached['status'],
					)
				);
				return new \WP_REST_Response( $cached['body'], $cached['status'] );
			}
		}

		$business_line  = sanitize_key( $body['business_line'] ?? '' );
		$client_task_id = sanitize_text_field( $body['client_task_id'] ?? '' );
		$relation_id    = absint( $body['relation_id'] ?? 0 );

		if ( empty( $client_task_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'missing_client_task_id',
					'message' => 'client_task_id is required.',
				),
				400
			);
		}

		if ( empty( $relation_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'missing_relation_id',
					'message' => 'relation_id is required.',
				),
				400
			);
		}

		global $wpdb;
		$request_device = sanitize_key( (string) $request->get_header( 'X-WPTSALL-Device-Id' ) );
		$callback_outbox_owner_hash = '' !== $request_device && function_exists( 'wptsall_client_claim_owner_hash' )
			? wptsall_client_claim_owner_hash( $request_device, $relation_id, '', 'outbox' )
			: '';
		// Durable outbox callbacks are leased to one device. Bind the callback
		// to that lease before any result/idempotency work so another valid
		// installation token cannot complete a different device's lifecycle row.
		$callback_outbox_id = absint( $body['outbox_id'] ?? 0 );
		if ( $callback_outbox_id <= 0 && 0 === strpos( $client_task_id, 'outbox-' ) ) {
			$callback_parts = explode( '-', $client_task_id );
			$callback_outbox_id = absint( $callback_parts[1] ?? 0 );
		}
		if ( $callback_outbox_id > 0 && class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
			$callback_outbox = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT relation_id, status, payload FROM %i WHERE id = %d LIMIT 1',
					wptsall_table( 'content_change_outbox' ),
					$callback_outbox_id
				),
				ARRAY_A
			);
			if ( ! is_array( $callback_outbox ) || absint( $callback_outbox['relation_id'] ?? 0 ) !== $relation_id ) {
				return new \WP_REST_Response( array( 'success' => false, 'error' => 'outbox_relation_mismatch', 'message' => 'Outbox row does not belong to the callback relation.' ), 403 );
			}
			$callback_payload = json_decode( (string) ( $callback_outbox['payload'] ?? '' ), true );
			$callback_owner = is_array( $callback_payload ) ? strtolower( trim( (string) ( $callback_payload['_wptsall_claim_owner_hash'] ?? '' ) ) ) : '';
			$expected_owner = function_exists( 'wptsall_client_claim_owner_hash' )
				? wptsall_client_claim_owner_hash( $request_device, $relation_id, '', 'outbox' )
				: hash( 'sha256', 'wptsall-claim-owner-v1|' . $request_device . '|' . $relation_id . '||outbox' );
			$callback_outbox_owner_hash = $expected_owner;
			if ( '' === $request_device || ! preg_match( '/^[a-f0-9]{64}$/', $callback_owner ) || ! hash_equals( $callback_owner, $expected_owner ) ) {
				return new \WP_REST_Response( array( 'success' => false, 'error' => 'outbox_claim_owner_mismatch', 'message' => 'Only the device that claimed this outbox row may submit its callback.' ), 403 );
			}
		}

		// Idempotency check with request-hash conflict (ISS S3).
		$results_table = wptsall_table( 'translation_results' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, request_hash FROM %i WHERE client_task_id = %s',
				$results_table,
				$client_task_id
			),
			ARRAY_A
		);

		if ( $existing ) {
			$existing_status = sanitize_key( (string) ( $existing['status'] ?? '' ) );
			$prev_hash = (string) ( $existing['request_hash'] ?? '' );
			if ( '' !== $prev_hash && '' !== $request_hash && ! hash_equals( $prev_hash, $request_hash ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'idempotency_conflict',
						'message' => 'Same client_task_id with different body.',
						'result_id' => (int) $existing['id'],
					),
					409
				);
			}
			if ( ! in_array( $existing_status, array( 'synced', 'completed', 'partial' ), true ) ) {
				if ( in_array( $existing_status, array( 'failed', 'cancelled' ), true ) ) {
					$replay_outbox_id = absint( $body['outbox_id'] ?? 0 );
					if ( $replay_outbox_id > 0 && class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
						\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $replay_outbox_id, 'translation_callback_retryable', $callback_outbox_owner_hash );
					}
				}
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'callback_not_replayable',
						'message' => 'The previous callback is not in a successful terminal state; fetch and claim the item again.',
						'result_id' => (int) $existing['id'],
					),
					409
				);
			}
			wptsall_log_info(
				'client-api',
				'Translation callback duplicate (idempotent)',
				array(
					'client_task_id' => $client_task_id,
					'existing_id'    => $existing['id'],
				)
			);
			// A worker may have crashed after the result row committed but before
			// closing the outbox lease. Duplicate callbacks must finish that
			// durable transition as well.
			$replay_outbox_id = absint( $body['outbox_id'] ?? 0 );
			if ( $replay_outbox_id <= 0 && 0 === strpos( $client_task_id, 'outbox-' ) ) {
				$replay_parts = explode( '-', $client_task_id );
				$replay_outbox_id = absint( $replay_parts[1] ?? 0 );
			}
			if ( $replay_outbox_id > 0 && class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::complete_outbox( $replay_outbox_id, $callback_outbox_owner_hash );
			}
			return new \WP_REST_Response(
				array(
					'success'    => true,
					'idempotent' => true,
					'result_id'  => (int) $existing['id'],
				),
				200
			);
		}

		// Stash for content handlers.
		$body['_wptsall_request_hash'] = $request_hash;
		$response = null;
		if ( in_array( $business_line, array( 'post_content', 'taxonomy_content', 'custom_model' ), true ) ) {
			$response = $this->handle_content_callback( $body, $client_task_id, $relation_id, $request );
		} elseif ( in_array( $business_line, array( 'site_strings', 'menu_strings', 'widget_strings' ), true ) ) {
			$response = $this->handle_site_string_callback( $body, $client_task_id, $relation_id, $request );
		} elseif ( in_array( $business_line, array( 'theme_i18n', 'plugin_i18n', 'config_i18n' ), true ) ) {
			// Discovery sometimes classifies CPT/config objects as *_i18n but still
			// submits content-shaped payloads (translated_fields/meta) without entries.
			// Accept those via the content write-back path instead of hard-400.
			$has_i18n_entries = ! empty( $body['entries'] ) && is_array( $body['entries'] );
			$has_content_fields = ! empty( $body['translated_fields'] ) || ! empty( $body['translated_meta'] );
			if ( ! $has_i18n_entries && $has_content_fields ) {
				$response = $this->handle_content_callback( $body, $client_task_id, $relation_id, $request );
			} else {
				$response = $this->handle_i18n_callback( $body, $client_task_id, $relation_id, $request );
			}
		} else {
			$response = new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'unknown_business_line',
					'message' => 'Unrecognized business_line: ' . $business_line,
				),
				400
			);
		}

		// Cache successful responses for Idempotency-Key replays.
		// Do not cache 4xx/5xx — otherwise a transient validation failure (e.g.
		// misrouted config_i18n) permanently poisons retries with the same key.
		if ( '' !== $idempotency_key && $response instanceof \WP_REST_Response && function_exists( 'wptsall_idempotency_set' ) ) {
			$status = (int) $response->get_status();
			$data   = $response->get_data();
			if ( is_array( $data ) && $status >= 200 && $status < 300 ) {
				wptsall_idempotency_set( $idempotency_key, $status, $data, DAY_IN_SECONDS, $request_hash );
			}
		}

		// Close the durable lifecycle event only after write-back accepted the
		// callback. Transient/validation failures release the lease for retry.
		$outbox_id = absint( $body['outbox_id'] ?? 0 );
		if ( $outbox_id <= 0 && 0 === strpos( $client_task_id, 'outbox-' ) ) {
			$parts = explode( '-', $client_task_id );
			$outbox_id = absint( $parts[1] ?? 0 );
		}
		if ( $outbox_id <= 0 ) {
			$source_type = 'taxonomy_content' === $business_line ? 'term' : 'post';
			$source_id   = absint( $body['object_id'] ?? 0 );
			if ( $source_id > 0 ) {
				$wpdb = $GLOBALS['wpdb'];
				$outbox_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM %i WHERE relation_id = %d AND source_type = %s AND source_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
						wptsall_table( 'content_change_outbox' ), $relation_id, $source_type, $source_id, 'processing'
					)
				);
			}
		}
		if ( $outbox_id > 0 && class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
			$status = $response instanceof \WP_REST_Response ? (int) $response->get_status() : 500;
			if ( $status >= 200 && $status < 300 ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::complete_outbox( $outbox_id, $callback_outbox_owner_hash );
			} elseif ( $status >= 400 ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::fail_outbox( $outbox_id, 'translation_callback_' . $status, $callback_outbox_owner_hash );
			}
		}

		return $response;
	}

	/**
	 * Handle content translation callback (post_content / taxonomy_content).
	 *
	 * Saves to translation_results table and creates a sync task.
	 *
	 * @param array  $body            Request body.
	 * @param string $client_task_id  Unique client task ID.
	 * @param int    $relation_id     Site relation ID.
	 * @return \WP_REST_Response
	 */
	private function handle_content_callback( $body, $client_task_id, $relation_id, $request = null ) {
		global $wpdb;

		$object_type       = sanitize_key( $body['object_type'] ?? '' );
		if ( 'post' === $object_type ) {
			$object_type = 'post_type';
		} elseif ( 'term' === $object_type ) {
			$object_type = 'taxonomy';
		}
		$object_id         = absint( $body['object_id'] ?? 0 );
		$callback_subtype  = sanitize_key( (string) ( $body['post_type'] ?? $body['subtype'] ?? '' ) );
		$translated_fields = $body['translated_fields'] ?? array();
		$translated_meta   = $body['translated_meta'] ?? array();
		$media_mappings    = $body['media_mappings'] ?? array();
		$source_lang       = sanitize_text_field( $body['source_lang'] ?? '' );
		$target_lang       = sanitize_text_field( $body['target_lang'] ?? '' );
		$source_revision   = sanitize_text_field( $body['source_revision'] ?? '' );
		$policy_version    = sanitize_text_field( $body['policy_version'] ?? '' );
		$request_hash      = sanitize_text_field( $body['_wptsall_request_hash'] ?? '' );

		$allowed_object_types = array( 'post_type', 'taxonomy', 'option', 'media', 'menu', 'custom_table' );
		if ( ! in_array( $object_type, $allowed_object_types, true ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'invalid_object_type',
					'message' => 'object_type must be one of: ' . implode( ', ', $allowed_object_types ),
				),
				400
			);
		}

		if ( empty( $object_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'missing_object_id',
					'message' => 'object_id is required for content callbacks.',
				),
				400
			);
		}

		$allow_test_revision_fallback = function_exists( 'wptsall_is_test_only_source_revision_fallback_enabled' )
			&& wptsall_is_test_only_source_revision_fallback_enabled();
		if ( '' === $source_revision && ! $allow_test_revision_fallback ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'missing_source_revision',
					'message' => 'source_revision from discovery/claim is required for write-back.',
				),
				400
			);
		}

		if ( is_array( $translated_meta ) ) {
			foreach ( array_keys( $translated_meta ) as $meta_key ) {
				if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' )
					&& \WPTSALL\Core\Smart_Field_Classifier::is_code_like_field_name( (string) $meta_key ) ) {
					return new \WP_REST_Response(
						array(
							'success' => false,
							'error'   => 'disallowed_meta_key',
							'message' => 'Code/script meta keys cannot be translated.',
						),
						400
					);
				}
			}
		}

		$field_results = is_array( $body['field_results'] ?? null ) ? $body['field_results'] : array();
		foreach ( $field_results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$fmt = sanitize_key( (string) ( $row['content_format'] ?? '' ) );
			if ( '' === $fmt ) {
				continue;
			}
			$allowed = class_exists( '\\WPTSALL\\Core\\Content_Format_Registry' )
				? \WPTSALL\Core\Content_Format_Registry::is_callback_allowed( $fmt )
				: in_array(
					$fmt,
					array( 'plain_text', 'rich_html', 'slug', 'media_ref', 'serialized_php', 'json_structured', 'html', 'json', 'serialized', 'text', 'plain' ),
					true
				) && 'code' !== $fmt;
			if ( ! $allowed ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'disallowed_content_format',
						'message' => 'content_format "' . $fmt . '" is not allowed for translation.',
					),
					400
				);
			}
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'relation_not_found',
					'message' => 'Site relation was not found.',
				),
				404
			);
		}
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'relation_inactive',
					'message' => 'Site relation is not active.',
				),
				409
			);
		}
		$relation_target_lang = sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) );
		if ( '' !== $relation_target_lang && '' !== $target_lang && $relation_target_lang !== $target_lang ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'target_language_mismatch', 'message' => 'Callback target language does not match the site relation.' ), 409 );
		}
		if ( '' === $target_lang ) {
			$target_lang = $relation_target_lang;
		}
		$relation_source_lang = sanitize_text_field( (string) ( $relation['source_lang'] ?? '' ) );
		if ( '' !== $relation_source_lang && '' !== $source_lang && $relation_source_lang !== $source_lang ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'source_language_mismatch', 'message' => 'Callback source language does not match the site relation.' ), 409 );
		}
		$content_source_site_id = absint( $relation['source_site_id'] ?? 0 );
		if ( $content_source_site_id <= 0 ) {
			$content_source_site_id = (int) get_current_blog_id();
		}
		if ( $content_source_site_id !== (int) get_current_blog_id() ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'source_site_mismatch', 'message' => 'Content callbacks must be submitted to the relation source site.' ), 403 );
		}

		$ownership = $this->validate_source_object_for_relation( $body, $relation, $object_type, $object_id );
		if ( is_wp_error( $ownership ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => $ownership->get_error_code(),
					'message' => $ownership->get_error_message(),
					'data'    => $ownership->get_error_data(),
				),
				(int) ( $ownership->get_error_data()['status'] ?? 403 )
			);
		}

		$claim_scope = 'option' === $object_type
			? (string) ( $body['post_type'] ?? $body['subtype'] ?? '' )
			: $callback_subtype;
		$claim_owner_hash = $request instanceof \WP_REST_Request
			? $this->get_claim_owner_hash( $request, $relation, $claim_scope )
			: '';
		$claim_check = $this->assert_current_content_claim( $relation, $object_type, $object_id, $callback_subtype, $claim_owner_hash );
		if ( is_wp_error( $claim_check ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => $claim_check->get_error_code(),
					'message' => $claim_check->get_error_message(),
					'data'    => $claim_check->get_error_data(),
				),
				(int) ( $claim_check->get_error_data()['status'] ?? 409 )
			);
		}

		// Snapshot minted at discovery/claim must return unchanged. Evaluate it
		// on the relation source blog so multisite callbacks cannot fingerprint a
		// different current/target blog.
		if ( class_exists( '\WPTSALL\Core\Job_Snapshot' ) ) {
			$source_site_id = absint( $relation['source_site_id'] ?? get_current_blog_id() );
			$current_site_id = (int) get_current_blog_id();
			$switched_snapshot = false;
			if ( is_multisite() && $source_site_id > 0 && $source_site_id !== $current_site_id ) {
				switch_to_blog( $source_site_id );
				$switched_snapshot = true;
			}
			try {
				$fresh = \WPTSALL\Core\Job_Snapshot::assert_fresh(
					$object_type,
					$object_id,
					$source_revision,
					$policy_version,
					'option' === $object_type ? ( $body['post_type'] ?? $body['subtype'] ?? '' ) : null
				);
			} finally {
				if ( $switched_snapshot ) {
					restore_current_blog();
				}
			}
			if ( is_wp_error( $fresh ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => $fresh->get_error_code(),
						'message' => $fresh->get_error_message(),
						'data'    => $fresh->get_error_data(),
					),
					409
				);
			}
		}
		if ( ! function_exists( 'wptsall_insert_tasks' ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'sync_task_service_unavailable',
					'message' => 'Sync task service is unavailable.',
				),
				500
			);
		}

		// Keep result persistence and sync-task materialization atomic. The
		// immediate Sync_Executor call below starts its own target-site
		// transaction, so commit this source-side boundary before invoking it.
		if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'callback_transaction_unavailable',
					'message' => 'Unable to start callback persistence transaction.',
				),
				500
			);
		}
		$callback_transaction_started = true;

		// Insert into translation_results.
		$result_id = wptsall_insert_translation_result( array(
			'relation_id'       => $relation_id,
			'object_type'       => $object_type,
			'object_id'         => $object_id,
			'translated_fields' => $translated_fields,
			'translated_meta'   => $translated_meta,
			'media_mappings'    => $media_mappings,
			'client_task_id'    => $client_task_id,
			'source_revision'   => $source_revision,
			'policy_version'    => $policy_version,
			'request_hash'      => $request_hash,
			'source_lang'       => $source_lang,
			'target_lang'       => $target_lang,
		) );

		if ( ! $result_id ) {
			if ( $callback_transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
				$callback_transaction_started = false;
			}
			wptsall_log_error(
				'client-api',
				'Failed to insert translation result',
				array( 'client_task_id' => $client_task_id )
			);
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'insert_failed',
					'message' => 'Failed to save translation result.',
				),
				500
			);
		}

		// Create sync task to write the translation to the target site.
		$sync_task_id  = null;
		$sync_result   = null;
		$sync_error    = null;

		if ( function_exists( 'wptsall_insert_tasks' ) ) {
			$task_data = array(
				array(
					'blog_id'                => (int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
					'target_blog'            => (int) ( $relation['target_site_id'] ?? 0 ),
					'target_type'            => $relation['target_site_type'] ?? 'wp',
					'target_identifier'      => (string) ( $relation['target_site_id'] ?? '' ),
					'site_id'                => $relation_id,
					'object_type'            => $object_type,
					'subtype'                => $callback_subtype ?: $object_type,
					'object_id'              => $object_id,
					'lang_from'              => $source_lang,
					'lang_to'                => $target_lang,
					'site_mode'              => $relation['target_site_type'] ?? 'wp',
					// Link to the translation result so Sync_Executor can read
					// translated fields via execute_translation_sync().
					'translation_result_id'  => $result_id,
					// Mark as originating from translation callback (Path B) so the
					// Orchestrator dispatches to execute_translation_sync() instead
					// of the generic execute_task().
					'sync_source'            => 'translation_callback',
					'claim_owner_hash'       => $claim_owner_hash,
				),
			);

			$insert_stats = wptsall_insert_tasks( $task_data, $source_lang, $target_lang );

			// Do not rely on $wpdb->insert_id here: task insertion performs
			// identity/reuse checks and may update an existing row, in which case
			// insert_id is zero (or belongs to a later query). Resolve the actual
			// open task by its canonical identity instead.
			$task_table = wptsall_table( 'tasks' );
			$sync_task_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE relation_id = %d AND object_type = %s AND subtype = %s AND object_id = %d AND status IN (%s,%s,%s,%s) ORDER BY id DESC LIMIT 1',
					$task_table,
					$relation_id,
					$object_type,
					$callback_subtype ?: $object_type,
					$object_id,
					'pending',
					'retry',
					'processing',
					'active'
				)
			);
			if ( $sync_task_id <= 0 ) {
				wptsall_log_error(
					'client-api',
					'Translation callback could not materialize a sync task',
					array(
						'relation_id' => $relation_id,
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'insert_stats' => is_array( $insert_stats ) ? $insert_stats : array(),
						'db_error'    => (string) $wpdb->last_error,
					)
				);
				if ( $callback_transaction_started ) {
					$wpdb->query( 'ROLLBACK' );
					$callback_transaction_started = false;
				}
				return new \WP_REST_Response(
					array(
						'success'   => false,
						'error'     => 'sync_task_materialization_failed',
						'message'   => 'Translation result and sync task could not be materialized.',
						'result_id' => $result_id,
					),
					500
				);
			}

			// Result and task now exist as one committed source-side unit. Do
			// not hold this transaction while Sync_Executor touches the target.
			if ( $callback_transaction_started ) {
				if ( $wpdb->query( 'COMMIT' ) === false ) {
					$wpdb->query( 'ROLLBACK' );
					$callback_transaction_started = false;
					return new \WP_REST_Response(
						array(
							'success' => false,
							'error'   => 'callback_transaction_commit_failed',
							'message' => 'Unable to commit callback persistence transaction.',
						),
						500
					);
				}
				$callback_transaction_started = false;
			}

			// Immediately execute the sync (client-driven: no cron delay).
			if ( $sync_task_id > 0 && class_exists( '\WPTSALL\Tasks\Sync\Sync_Executor' ) ) {
				$exec_result = \WPTSALL\Tasks\Sync\Sync_Executor::execute_translation_sync( $sync_task_id );
				if ( is_wp_error( $exec_result ) ) {
					$sync_error = $exec_result->get_error_message();
				} else {
					$sync_result = $exec_result;
				}
			}

			// Clear the durable claim only after the target write actually
			// completed. Failed callbacks release their owner lease but retain the
			// resync marker so the item is discoverable again.
			$target_write_succeeded = is_array( $sync_result ) && ! empty( $sync_result['success'] );
			if ( in_array( $object_type, array( 'post_type', 'media' ), true ) && $object_id > 0 ) {
				$mappings_table = wptsall_table( 'post_mappings' );
				$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
				$target_site_id = (string) ( $relation['target_site_id'] ?? '' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$mappings_table,
					array(
						'needs_resync'     => $target_write_succeeded ? 0 : 1,
						'claimed_at'       => null,
						'claim_owner_hash' => null,
						'updated_at'       => current_time( 'mysql', true ),
					),
					array(
						'source_post_id'    => $object_id,
						'source_post_type'  => $callback_subtype,
						'source_site_id'    => $source_site_id,
						'relation_id'       => $relation_id,
						'target_site_id'    => $target_site_id,
						'claim_owner_hash'  => $claim_owner_hash,
					),
					array( '%d', '%s', '%s', '%s' ),
					array( '%d', '%s', '%d', '%d', '%s', '%s' )
				);

				// Attachments are tracked in media_mappings as well as the post claim
				// projection. Release or complete that lease with the same owner CAS.
				if ( 'attachment' === sanitize_key( (string) ( $body['post_type'] ?? '' ) ) ) {
					$wpdb->update(
						wptsall_table( 'media_mappings' ),
						array(
							'needs_resync'     => $target_write_succeeded ? 0 : 1,
							'claimed_at'       => null,
							'claim_owner_hash' => null,
							'updated_at'       => current_time( 'mysql', true ),
						),
						array(
							'source_media_id'  => $object_id,
							'source_site_id'   => $source_site_id,
							'target_site_id'   => $target_site_id,
							'relation_id'      => $relation_id,
							'claim_owner_hash' => $claim_owner_hash,
						),
						array( '%d', '%s', '%s', '%s' ),
						array( '%d', '%d', '%s', '%d', '%s' )
					);
				}
			} elseif ( 'taxonomy' === $object_type && $object_id > 0 ) {
				$wpdb->update(
					wptsall_table( 'term_mappings' ),
					array(
						'needs_resync'     => $target_write_succeeded ? 0 : 1,
						'claimed_at'       => null,
						'claim_owner_hash' => null,
						'updated_at'       => current_time( 'mysql', true ),
					),
					array(
						'source_term_id'   => $object_id,
						'source_taxonomy'  => $callback_subtype,
						'source_site_id'   => (int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
						'relation_id'      => $relation_id,
						'target_site_id'   => (string) ( $relation['target_site_id'] ?? '' ),
						'target_lang'      => (string) ( $relation['target_lang'] ?? $target_lang ),
						'claim_owner_hash' => $claim_owner_hash,
					),
					array( '%d', '%s', '%s', '%s' ),
					array( '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
				);
			}
		}
		if ( $callback_transaction_started ) {
			$wpdb->query( 'COMMIT' );
			$callback_transaction_started = false;
		}

		wptsall_log_info(
			'client-api',
			'Translation content callback processed',
			array(
				'client_task_id' => $client_task_id,
				'result_id'      => $result_id,
				'sync_task_id'   => $sync_task_id,
				'relation_id'    => $relation_id,
				'object_id'      => $object_id,
				'sync_success'   => null === $sync_error,
				'sync_error'     => $sync_error,
			)
		);

		$response = array(
			'success'      => true,
			'result_id'    => $result_id,
			'sync_task_id' => $sync_task_id,
			'queued'       => ( $sync_task_id > 0 ),
			'protocol'     => 'v2',
		);

		if ( $sync_result ) {
			$response['sync_result'] = array(
				'success'   => ! empty( $sync_result['success'] ),
				'target_id' => $sync_result['target_id'] ?? null,
				'skipped'   => ! empty( $sync_result['skipped'] ),
			);
		} elseif ( $sync_error ) {
			$response['sync_result'] = array(
				'success' => false,
				'error'   => $sync_error,
			);
		}
		$response_status = 200;
		if ( ! $target_write_succeeded ) {
			$response['success'] = false;
			$response['error']   = 'target_write_failed';
			$response['message'] = $sync_error ?: 'Translation was saved but target write-back did not complete.';
			$response_status     = 500;
		}

		return new \WP_REST_Response( $response, $response_status );
	}

	/**
	 * Validate a discovery/claim object against the relation source site and
	 * configured model/rule boundary.
	 *
	 * Discovery IDs are supplied by a remote client, so existence alone is not
	 * sufficient: the object must live on the relation source blog, have the
	 * advertised subtype, and be enabled by at least one relation model/rule.
	 *
	 * @param array  $relation Relation row.
	 * @param string $data_type post|term.
	 * @param string $subtype   Post type or taxonomy.
	 * @param int    $object_id Source object ID.
	 * @return true|\WP_Error
	 */
	private function validate_discovery_object_for_relation( $relation, $data_type, $subtype, $object_id ) {
		$data_type = sanitize_key( (string) $data_type );
		$subtype   = sanitize_key( (string) $subtype );
		$object_id = absint( $object_id );
		$source_site_id  = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$current_site_id = (int) get_current_blog_id();
		if ( $source_site_id <= 0 ) {
			$source_site_id = $current_site_id;
		}
		if ( $source_site_id !== $current_site_id && ! is_multisite() ) {
			return new \WP_Error( 'source_site_mismatch', 'Relation source site is not available in this WordPress installation.', array( 'status' => 403 ) );
		}
		if ( '' === $subtype || $object_id <= 0 ) {
			return new \WP_Error( 'invalid_item', 'A valid object_id and subtype are required.', array( 'status' => 400 ) );
		}
		if ( ! $this->relation_allows_subtype( (int) ( $relation['id'] ?? 0 ), $data_type, $subtype ) ) {
			return new \WP_Error( 'subtype_not_allowed', 'The object subtype is not enabled for this site relation.', array( 'status' => 403 ) );
		}

		$switched = false;
		if ( is_multisite() && $source_site_id !== $current_site_id ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}
		$error = null;
		try {
			if ( in_array( $data_type, array( 'term', 'taxonomy' ), true ) ) {
				$term = get_term( $object_id, $subtype );
				if ( ! $term || is_wp_error( $term ) ) {
					$error = new \WP_Error( 'source_object_not_found', 'Source term does not exist on the relation source site.', array( 'status' => 404 ) );
				} elseif ( sanitize_key( (string) $term->taxonomy ) !== $subtype ) {
					$error = new \WP_Error( 'source_object_type_mismatch', 'Source taxonomy does not match the requested subtype.', array( 'status' => 400, 'actual' => $term->taxonomy ) );
				}
			} else {
				$post = get_post( $object_id );
				if ( ! $post ) {
					$error = new \WP_Error( 'source_object_not_found', 'Source post does not exist on the relation source site.', array( 'status' => 404 ) );
				} elseif ( sanitize_key( (string) $post->post_type ) !== $subtype ) {
					$error = new \WP_Error( 'source_object_type_mismatch', 'Source post type does not match the requested subtype.', array( 'status' => 400, 'actual' => $post->post_type ) );
				} elseif ( ! in_array( $post->post_status, array( 'publish', 'inherit' ), true ) ) {
					$error = new \WP_Error( 'source_object_not_discoverable', 'Source post is not in a discoverable status.', array( 'status' => 409, 'status_value' => $post->post_status ) );
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		return $error ?: true;
	}

	/**
	 * Return whether a relation explicitly enables an option rule.
	 *
	 * Options have no safe universal object boundary. Even though the generic
	 * option allowlist protects the value itself, discovery/claim must also
	 * require an enabled option rule belonging to this relation.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $option_name Option name.
	 * @return bool
	 */
	private function relation_allows_option( $relation_id, $option_name ) {
		$relation_id = absint( $relation_id );
		$option_name = sanitize_key( (string) $option_name );
		if ( $relation_id <= 0 || '' === $option_name || ! function_exists( 'wptsall_is_syncable_option' ) || ! wptsall_is_syncable_option( $option_name, $relation_id ) ) {
			return false;
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) || ! class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
			return false;
		}
		foreach ( (array) Relation_Model_Service::get_models_by_relation( $relation_id ) as $model ) {
			foreach ( (array) Translation_Rule_Service::get_model_rules( (int) ( $model['id'] ?? 0 ) ) as $rule ) {
				if ( 'option' !== $this->normalize_content_data_type( (string) ( $rule['data_type'] ?? '' ) ) ) {
					continue;
				}
				$config = Translation_Rule_Service::get_merged_config(
					(int) ( $rule['id'] ?? 0 ),
					$relation_id,
					array( 'suppress_warning_log' => true )
				);
				$enabled = is_array( $config )
					? (bool) ( $config['enabled'] ?? ( $rule['is_active'] ?? true ) )
					: (bool) ( $rule['is_active'] ?? true );
				$name = sanitize_key( (string) ( is_array( $config ) ? ( $config['post_type'] ?? $rule['object_name'] ?? '' ) : ( $rule['object_name'] ?? '' ) ) );
				if ( $enabled && $name === $option_name ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return whether a relation enables a Layer-B site string lane.
	 *
	 * @param array  $relation Relation row.
	 * @param string $subtype  site|menu|widget.
	 * @return bool
	 */
	private function relation_allows_site_string_lane( array $relation, $subtype ) {
		$subtype = sanitize_key( (string) $subtype );
		$flags = array(
			'site'   => 'translate_site_strings',
			'menu'   => 'translate_menu_strings',
			'widget' => 'translate_widget_strings',
		);
		if ( ! isset( $flags[ $subtype ] ) ) {
			return false;
		}
		$relation_id = absint( $relation['id'] ?? 0 );
		if ( $relation_id <= 0 || ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' ) ) {
			return false;
		}
		$config = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		return is_array( $config ) && ! empty( $config[ $flags[ $subtype ] ] );
	}

	/**
	 * Build a deterministic, non-secret claim-owner digest for this request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param array            $relation Relation row.
	 * @param string           $scope Object/lane scope.
	 * @param string           $target_lang Target language.
	 * @return string
	 */
	private function get_claim_owner_hash( $request, array $relation, $scope = '', $target_lang = '' ) {
		$device_id = sanitize_key( (string) $request->get_header( 'X-WPTSALL-Device-Id' ) );
		if ( '' === $device_id ) {
			// Direct unit calls bypass permission_callback. The route can never
			// reach this fallback because protocol-v2 permission requires a device.
			$device_id = 'unknown-device';
		}
		if ( '' === $target_lang ) {
			$target_lang = (string) ( $relation['target_lang'] ?? '' );
		}
		if ( function_exists( 'wptsall_client_claim_owner_hash' ) ) {
			return wptsall_client_claim_owner_hash( $device_id, (int) ( $relation['id'] ?? 0 ), $target_lang, $scope );
		}
		return hash( 'sha256', 'wptsall-claim-owner-v1|' . $device_id . '|' . (int) ( $relation['id'] ?? 0 ) . '|' . sanitize_text_field( $target_lang ) . '|' . sanitize_key( $scope ) );
	}

	/**
	 * Return whether a relation has enabled rules/models for a subtype.
	 *
	 * A relation with no model rows is kept permissive for legacy/manual
	 * installations. Once models are configured, however, an unknown subtype
	 * is denied instead of silently broadening the client data surface.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $data_type   post|term.
	 * @param string $subtype     Post type or taxonomy.
	 * @return bool
	 */
	private function relation_allows_subtype( $relation_id, $data_type, $subtype ) {
		$relation_id = absint( $relation_id );
		$data_type   = sanitize_key( (string) $data_type );
		$subtype     = sanitize_key( (string) $subtype );
		if ( $relation_id <= 0 || '' === $subtype ) {
			return false;
		}
		$models = class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' )
			? (array) Relation_Model_Service::get_models_by_relation( $relation_id )
			: array();
		if ( empty( $models ) ) {
			// Existing sites can have a relation before model discovery has run.
			// Do not break that bootstrap path; explicit model rows tighten it.
			return true;
		}
		$allowed              = array();
		$declared_by_model    = array();
		$has_effective_rules  = false;
		$disabled_by_rule     = array();
		foreach ( $models as $model ) {
			$raw = 'term' === $data_type ? ( $model['taxonomies'] ?? '[]' ) : ( $model['post_types'] ?? '[]' );
			$values = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			foreach ( (array) $values as $value ) {
				$name = is_array( $value ) ? ( $value['name'] ?? '' ) : $value;
				$name = sanitize_key( (string) $name );
				if ( '' !== $name ) {
					$declared_by_model[] = $name;
				}
			}
			if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
				continue;
			}
			foreach ( (array) Translation_Rule_Service::get_model_rules( (int) ( $model['id'] ?? 0 ) ) as $rule ) {
				$rule_type = $this->normalize_content_data_type( (string) ( $rule['data_type'] ?? '' ) );
				if ( $rule_type !== $data_type ) {
					continue;
				}
				$has_effective_rules = true;
				$config = Translation_Rule_Service::get_merged_config(
					(int) ( $rule['id'] ?? 0 ),
					$relation_id,
					array( 'suppress_warning_log' => true )
				);
				$enabled = is_array( $config )
					? (bool) ( $config['enabled'] ?? ( $rule['is_active'] ?? true ) )
					: (bool) ( $rule['is_active'] ?? true );
				$name = sanitize_key( (string) ( is_array( $config ) ? ( $config['post_type'] ?? $rule['object_name'] ?? '' ) : ( $rule['object_name'] ?? '' ) ) );
				if ( '' === $name ) {
					continue;
				}
				if ( $enabled ) {
					$allowed[] = $name;
				} else {
					$disabled_by_rule[] = $name;
				}
			}
		}
		if ( ! $has_effective_rules ) {
			$allowed = $declared_by_model;
		} else {
			$allowed = array_merge( $allowed, array_diff( $declared_by_model, $disabled_by_rule ) );
		}
		if ( 'post' === $data_type ) {
			$fse_types = apply_filters( 'wptsall_fse_managed_post_types', array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ) );
			foreach ( (array) $fse_types as $fse_type ) {
				$allowed[] = sanitize_key( (string) $fse_type );
			}
			// Attachments are a first-class media lane even when a model does not
			// list them as a normal post type.
			$allowed[] = 'attachment';
		}
		return in_array( $subtype, array_values( array_unique( $allowed ) ), true );
	}

	/**
	 * Require a non-expired claim owned by the authenticated device.
	 *
	 * The relation and subtype are part of the lookup, while the device is
	 * represented only by a digest. A valid client token therefore cannot use a
	 * claim acquired by another device, relation, or content lane.
	 *
	 * @param array  $relation         Relation row.
	 * @param string $object_type      Canonical object type.
	 * @param int    $object_id        Source object id.
	 * @param string $subtype          Post type/taxonomy/option name.
	 * @param string $claim_owner_hash Owner digest.
	 * @return true|\WP_Error
	 */
	private function assert_current_content_claim( array $relation, $object_type, $object_id, $subtype, $claim_owner_hash ) {
		$relation_id      = absint( $relation['id'] ?? 0 );
		$source_site_id   = absint( $relation['source_site_id'] ?? 0 );
		if ( $source_site_id <= 0 ) {
			$source_site_id = (int) get_current_blog_id();
		}
		$target_site_id   = sanitize_text_field( (string) ( $relation['target_site_id'] ?? '' ) );
		$object_type      = sanitize_key( (string) $object_type );
		$subtype          = sanitize_key( (string) $subtype );
		$claim_owner_hash = strtolower( trim( (string) $claim_owner_hash ) );
		if ( $relation_id <= 0 || '' === $subtype || ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return new \WP_Error( 'claim_required', 'A valid device-owned content claim is required before callback.', array( 'status' => 409 ) );
		}

		$claim_timeout = self::get_claim_timeout_seconds();
		if ( 'option' === $object_type ) {
			if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Option_Sync_State_Service' ) ) {
				return new \WP_Error( 'claim_unavailable', 'Option claim storage is unavailable.', array( 'status' => 503 ) );
			}
			$current_site_id = (int) get_current_blog_id();
			if ( $source_site_id !== $current_site_id && ! is_multisite() ) {
				return new \WP_Error( 'source_site_mismatch', 'Relation source site is not available in this WordPress installation.', array( 'status' => 403 ) );
			}
			$switched = is_multisite() && $source_site_id > 0 && $source_site_id !== $current_site_id;
			if ( $switched ) {
				switch_to_blog( $source_site_id );
			}
			try {
				$active = \WPTSALL\Models\Services\Option_Sync_State_Service::has_active_claim(
					$relation_id,
					$source_site_id,
					$target_site_id,
					$subtype,
					$claim_owner_hash,
					$claim_timeout
				);
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
			return $active ? true : new \WP_Error( 'claim_owner_mismatch', 'Option claim is missing, expired, or belongs to another device.', array( 'status' => 403 ) );
		}

		if ( ! in_array( $object_type, array( 'post_type', 'taxonomy', 'media' ), true ) ) {
			return new \WP_Error( 'claim_required', 'This callback type does not have a valid content claim.', array( 'status' => 409 ) );
		}
		if ( $source_site_id !== (int) get_current_blog_id() && ! is_multisite() ) {
			return new \WP_Error( 'source_site_mismatch', 'Relation source site is not available in this WordPress installation.', array( 'status' => 403 ) );
		}

		$switched = false;
		if ( is_multisite() && $source_site_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}
		global $wpdb;
		try {
		$table = 'taxonomy' === $object_type ? wptsall_table( 'term_mappings' ) : wptsall_table( 'post_mappings' );
		if ( 'taxonomy' === $object_type ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT claimed_at, claim_owner_hash FROM %i WHERE relation_id = %d AND source_term_id = %d AND source_taxonomy = %s AND source_site_id = %d AND target_site_id = %s AND target_lang = %s LIMIT 1',
					$table,
					$relation_id,
					(int) $object_id,
					$subtype,
					$source_site_id,
					$target_site_id,
					(string) ( $relation['target_lang'] ?? '' )
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT claimed_at, claim_owner_hash FROM %i WHERE relation_id = %d AND source_post_id = %d AND source_post_type = %s AND source_site_id = %d AND target_site_id = %s LIMIT 1',
					$table,
					$relation_id,
					(int) $object_id,
					$subtype,
					$source_site_id,
					$target_site_id
				),
				ARRAY_A
			);
		}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		if ( ! is_array( $row ) || '' === (string) ( $row['claimed_at'] ?? '' ) ) {
			return new \WP_Error( 'claim_required', 'A content claim is required before callback.', array( 'status' => 409 ) );
		}
		$claimed_timestamp = strtotime( (string) $row['claimed_at'] . ' UTC' );
		if ( false === $claimed_timestamp || $claimed_timestamp < time() - $claim_timeout ) {
			return new \WP_Error( 'claim_expired', 'The content claim has expired; rediscover and claim the item again.', array( 'status' => 409 ) );
		}
		if ( ! hash_equals( $claim_owner_hash, strtolower( trim( (string) ( $row['claim_owner_hash'] ?? '' ) ) ) ) ) {
			return new \WP_Error( 'claim_owner_mismatch', 'The content claim belongs to another device.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Ensure a content callback addresses an object owned by the relation's
	 * source site and that the wire subtype matches the real WordPress object.
	 *
	 * @param array  $body      Callback body.
	 * @param array  $relation  Relation row.
	 * @param string $type      Canonical object type.
	 * @param int    $object_id Source object ID.
	 * @return true|\WP_Error
	 */
	private function validate_source_object_for_relation( $body, $relation, $type, $object_id ) {
		$type      = sanitize_key( (string) $type );
		$object_id = absint( $object_id );
		$source_site_id = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$current_site_id = (int) get_current_blog_id();
		if ( $source_site_id <= 0 ) {
			$source_site_id = $current_site_id;
		}

		if ( $source_site_id !== $current_site_id && ! is_multisite() ) {
			return new \WP_Error(
				'source_site_mismatch',
				'Relation source site is not available in this WordPress installation.',
				array( 'status' => 403 )
			);
		}

		$switched = false;
		if ( is_multisite() && $source_site_id !== $current_site_id ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}

		$error = null;
		try {
		$expected_subtype = sanitize_key( (string) ( $body['post_type'] ?? $body['subtype'] ?? '' ) );
		if ( in_array( $type, array( 'post_type', 'media', 'taxonomy' ), true ) && '' === $expected_subtype ) {
			$error = new \WP_Error(
				'source_subtype_required',
				'Callback subtype is required for post, media, and taxonomy objects.',
				array( 'status' => 400 )
			);
		} elseif ( in_array( $type, array( 'post_type', 'media' ), true ) && ! $this->relation_allows_subtype( (int) ( $relation['id'] ?? 0 ), 'post', 'media' === $type ? 'attachment' : $expected_subtype ) ) {
			$error = new \WP_Error(
				'subtype_not_allowed',
				'Callback subtype is not enabled for this site relation.',
				array( 'status' => 403 )
			);
		} elseif ( 'taxonomy' === $type && ! $this->relation_allows_subtype( (int) ( $relation['id'] ?? 0 ), 'term', $expected_subtype ) ) {
			$error = new \WP_Error(
				'subtype_not_allowed',
				'Callback taxonomy is not enabled for this site relation.',
				array( 'status' => 403 )
			);
		} elseif ( 'option' === $type && '' === $expected_subtype ) {
			$error = new \WP_Error(
				'option_name_required',
				'Callback option name is required.',
				array( 'status' => 400 )
			);
		} elseif ( 'option' === $type && ( ! function_exists( 'wptsall_is_syncable_option' ) || ! wptsall_is_syncable_option( $expected_subtype, (int) ( $relation['id'] ?? 0 ) ) ) ) {
			$error = new \WP_Error(
				'option_not_allowed',
				'Callback option is not enabled for this site relation.',
				array( 'status' => 403 )
			);
		} elseif ( 'option' === $type ) {
			$expected_object_id = crc32( 'option:' . $expected_subtype );
			if ( $expected_object_id < 0 ) {
				$expected_object_id *= -1;
			}
			if ( (int) $expected_object_id !== $object_id ) {
				$error = new \WP_Error(
					'option_object_mismatch',
					'Callback option object id does not match the option name.',
					array( 'status' => 400 )
				);
			}
		}
		if ( null === $error && in_array( $type, array( 'post_type', 'media' ), true ) ) {
			$post = get_post( $object_id );
			if ( ! $post ) {
				$error = new \WP_Error(
					'source_object_not_found',
					'Source post does not exist on the relation source site.',
					array( 'status' => 404, 'object_type' => $type, 'object_id' => $object_id )
				);
			} elseif ( 'media' === $type && 'attachment' !== $post->post_type ) {
				$error = new \WP_Error(
					'source_object_type_mismatch',
					'Source media callback must address an attachment.',
					array( 'status' => 400, 'actual_subtype' => $post->post_type )
				);
			} elseif ( '' !== $expected_subtype && $expected_subtype !== sanitize_key( (string) $post->post_type ) ) {
				$error = new \WP_Error(
					'source_object_type_mismatch',
					'Callback subtype does not match the source post type.',
					array( 'status' => 400, 'expected' => $expected_subtype, 'actual' => $post->post_type )
				);
			}
		} elseif ( null === $error && 'taxonomy' === $type ) {
			$taxonomy = $expected_subtype;
			$term     = get_term( $object_id, '' !== $taxonomy ? $taxonomy : null );
			if ( ! $term || is_wp_error( $term ) ) {
				$error = new \WP_Error(
					'source_object_not_found',
					'Source term does not exist on the relation source site.',
					array( 'status' => 404, 'object_type' => $type, 'object_id' => $object_id )
				);
			} elseif ( '' !== $taxonomy && $taxonomy !== sanitize_key( (string) $term->taxonomy ) ) {
				$error = new \WP_Error(
					'source_object_type_mismatch',
					'Callback taxonomy does not match the source term taxonomy.',
					array( 'status' => 400, 'expected' => $taxonomy, 'actual' => $term->taxonomy )
				);
			}
		}

		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		return $error ?: true;
	}

	/**
	 * Handle i18n translation callback (theme_i18n / plugin_i18n).
	 *
	 * Writes translated strings to template_entries.
	 *
	 * @param array  $body            Request body.
	 * @param string $client_task_id  Unique client task ID.
	 * @param int    $relation_id     Site relation ID.
	 * @return \WP_REST_Response
	 */
	private function handle_i18n_callback( $body, $client_task_id, $relation_id, $request = null ) {
		$entries = $body['entries'] ?? array();
		$business_line = sanitize_key( $body['business_line'] ?? '' );

		if ( empty( $entries ) || ! is_array( $entries ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'missing_entries',
					'message' => 'entries array is required for i18n callbacks.',
				),
				400
			);
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'relation_not_found',
					'message' => 'Site relation was not found.',
				),
				404
			);
		}
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'relation_inactive',
					'message' => 'Site relation is not active.',
				),
				409
			);
		}
		$callback_target_lang = sanitize_text_field( (string) ( $body['target_lang'] ?? '' ) );
		$relation_target_lang = sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) );
		if ( '' !== $callback_target_lang && '' !== $relation_target_lang && $callback_target_lang !== $relation_target_lang ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'target_language_mismatch', 'message' => 'Callback target language does not match the site relation.' ), 409 );
		}
		$i18n_source_site_id = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$i18n_current_site_id = (int) get_current_blog_id();
		if ( $i18n_source_site_id <= 0 ) {
			$i18n_source_site_id = $i18n_current_site_id;
		}
		if ( $i18n_source_site_id !== $i18n_current_site_id && ! is_multisite() ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'source_site_mismatch', 'message' => 'Relation source site is not available in this WordPress installation.' ), 403 );
		}
		$i18n_switched = is_multisite() && $i18n_source_site_id !== $i18n_current_site_id;
		if ( $i18n_switched ) {
			switch_to_blog( $i18n_source_site_id );
		}
		try {
		$updated_count = 0;
		$rejected_count = 0;

		global $wpdb;
		$entries_table = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );
		$now           = current_time( 'mysql', true );
		$template_ids  = array();
		$expected_source_type = array(
			'theme_i18n'   => 'theme',
			'plugin_i18n'  => 'plugin',
			'config_i18n'  => 'config',
		)[ $business_line ] ?? '';
		$target_language = sanitize_text_field( (string) ( $body['target_lang'] ?? $relation_target_lang ) );
		if ( '' === $target_language ) {
			$target_language = $relation_target_lang;
		}
		$claim_owner_hash = $request instanceof \WP_REST_Request
			? $this->get_claim_owner_hash( $request, $relation, $expected_source_type, $target_language )
			: '';
		$claim_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', strtolower( trim( $claim_owner_hash ) ) ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'claim_required', 'message' => 'A valid device-owned language-pack claim is required before callback.' ), 409 );
		}

		foreach ( $entries as $entry ) {
			$entry_id = absint( $entry['entry_id'] ?? 0 );
			$msgstr   = $entry['msgstr'] ?? '';

			if ( empty( $entry_id ) || ( is_array( $msgstr ) || is_object( $msgstr ) ) || '' === (string) $msgstr ) {
				++$rejected_count;
				continue;
			}
			$msgstr = (string) $msgstr;

			// Resolve the entry through its template before writing. The entry
			// id is user supplied; updating by entry id alone would let a valid client
			// token write strings belonging to another relation or source family.
			$template_info = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT e.template_id, e.claimed_at, e.claim_owner_hash, t.relation_id, t.source_type, t.target_language, t.text_domain FROM %i e INNER JOIN %i t ON t.id = e.template_id WHERE e.id = %d LIMIT 1',
					$entries_table,
					$templates_table,
					$entry_id
				),
				ARRAY_A
			);
			$claimed_at = is_array( $template_info ) ? (string) ( $template_info['claimed_at'] ?? '' ) : '';
			$claim_is_current = '' !== $claimed_at
				&& false !== strtotime( $claimed_at . ' UTC' )
				&& strtotime( $claimed_at . ' UTC' ) >= time() - self::get_claim_timeout_seconds()
				&& hash_equals( strtolower( trim( $claim_owner_hash ) ), strtolower( trim( (string) ( $template_info['claim_owner_hash'] ?? '' ) ) ) );
			if ( ! is_array( $template_info )
				|| (int) ( $template_info['relation_id'] ?? 0 ) !== $relation_id
				|| ( '' !== $expected_source_type && sanitize_key( (string) ( $template_info['source_type'] ?? '' ) ) !== $expected_source_type )
				|| ( '' !== $target_language && '' !== (string) ( $template_info['target_language'] ?? '' ) && $target_language !== (string) $template_info['target_language'] )
				|| ! $claim_is_current ) {
				++$rejected_count;
				continue;
			}

			// Scope the UPDATE itself as well as the preflight SELECT. This closes
			// the race where an entry is reassigned between the two queries.
			if ( '' !== $expected_source_type ) {
				$result = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i e INNER JOIN %i t ON t.id = e.template_id SET e.msgstr = %s, e.status = %s, e.claimed_at = NULL, e.claim_owner_hash = NULL, e.updated_at = %s WHERE e.id = %d AND t.relation_id = %d AND t.source_type = %s AND (t.target_language = %s OR t.target_language = %s OR t.target_language IS NULL) AND e.claimed_at >= %s AND e.claim_owner_hash = %s',
						$entries_table,
						$templates_table,
						$msgstr,
						'translated',
						$now,
						$entry_id,
						$relation_id,
						$expected_source_type,
						$target_language,
						'',
						$claim_cutoff,
						$claim_owner_hash
					)
				);
			} else {
				$result = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i e INNER JOIN %i t ON t.id = e.template_id SET e.msgstr = %s, e.status = %s, e.claimed_at = NULL, e.claim_owner_hash = NULL, e.updated_at = %s WHERE e.id = %d AND t.relation_id = %d AND (t.target_language = %s OR t.target_language = %s OR t.target_language IS NULL) AND e.claimed_at >= %s AND e.claim_owner_hash = %s',
						$entries_table,
						$templates_table,
						$msgstr,
						'translated',
						$now,
						$entry_id,
						$relation_id,
						$target_language,
						'',
						$claim_cutoff,
						$claim_owner_hash
					)
				);
			}

			if ( false !== $result && $result > 0 ) {
				++$updated_count;
				$template_id = (int) ( $template_info['template_id'] ?? 0 );
				if ( $template_id > 0 ) {
					$template_ids[] = $template_id;
				}
				do_action(
					'wptsall_entry_updated',
					$entry_id,
					array(
						'text_domain' => (string) ( $template_info['text_domain'] ?? '' ),
					)
				);
			} elseif ( false !== $result ) {
				++$rejected_count;
			}
		}

		$template_ids = array_values( array_unique( array_map( 'intval', $template_ids ) ) );
		if ( ! empty( $template_ids ) ) {
			$task_subtype = preg_replace( '/_i18n$/', '', $business_line );
			$tasks_table  = wptsall_table( 'tasks' );
			foreach ( $template_ids as $template_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i
						 SET status = 'completed', updated_at = %s
						 WHERE relation_id = %d
						   AND object_type = 'language_pack'
						   AND subtype = %s
						   AND meta LIKE %s
						   AND status IN ('pending', 'active', 'processing')",
						$tasks_table,
						$now,
						$relation_id,
						$task_subtype,
						'%"template_id":' . $template_id . '%'
					)
				);
			}

			if ( function_exists( 'wptsall_log_info' ) ) {
				wptsall_log_info(
					'client-api',
					'Translation i18n callback completed matching language_pack tasks',
					array(
						'client_task_id' => $client_task_id,
						'relation_id'    => $relation_id,
						'business_line'  => $business_line,
						'template_ids'   => $template_ids,
					)
				);
			}
		}

		// Also record in translation_results for audit trail.
		$result_id = wptsall_insert_translation_result( array(
			'relation_id'       => $relation_id,
			'object_type'       => 'i18n',
			'object_id'         => 0,
			'translated_fields' => array( 'entries_count' => count( $entries ) ),
			'translated_meta'   => array(),
			'media_mappings'    => array(),
			'client_task_id'    => $client_task_id,
			'request_hash'      => sanitize_text_field( (string) ( $body['_wptsall_request_hash'] ?? '' ) ),
			'source_lang'       => sanitize_text_field( $body['source_lang'] ?? '' ),
			'target_lang'       => sanitize_text_field( $body['target_lang'] ?? '' ),
		) );
		if ( $result_id > 0 ) {
			$wpdb->update(
				wptsall_table( 'translation_results' ),
				array(
					'status'    => 0 === $rejected_count ? 'synced' : 'failed',
					'synced_at' => 0 === $rejected_count ? $now : null,
				),
				array( 'id' => (int) $result_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		wptsall_log_info(
			'client-api',
			'Translation i18n callback processed',
			array(
				'client_task_id'  => $client_task_id,
				'entries_updated' => $updated_count,
				'entries_total'   => count( $entries ),
				'relation_id'     => $relation_id,
			)
		);

		$i18n_status = $rejected_count > 0 ? 409 : 200;
		return new \WP_REST_Response(
			array(
				'success'         => 0 === $rejected_count,
				'result_id'       => (int) $result_id,
				'sync_task_id'    => 0,
				'queued'          => false,
				'protocol'        => 'v2',
				'entries_updated' => $updated_count,
				'entries_rejected' => $rejected_count,
				'error'           => $rejected_count > 0 ? 'claim_lost_or_entry_rejected' : null,
			),
			$i18n_status
		);
		} finally {
			if ( $i18n_switched ) {
				restore_current_blog();
			}
		}
	}

	// =====================================================================
	// Version Negotiation (I7)
	// =====================================================================

	/**
	 * Check if the client supports content_format in rules response.
	 *
	 * Reads `X-Client-Version` header and compares to minimum version 2.1.
	 * Returns false if the header is absent or the version is below 2.1,
	 * ensuring backward compatibility with older clients.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool True if client version >= 2.1.
	 */
	private function client_supports_content_format( $request ) {
		$version = (string) $request->get_header( 'X-Client-Version' );
		if ( empty( $version ) ) {
			return false;
		}

		// Parse semver: extract major.minor (ignore patch/pre-release)
		$parts = explode( '.', $version );
		$major = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$minor = isset( $parts[1] ) ? (int) $parts[1] : 0;

		// Require >= 2.1
		if ( $major > 2 ) {
			return true;
		}
		if ( 2 === $major && $minor >= 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * Handle Layer B site string callback (site_strings / menu_strings / widget_strings).
	 *
	 * @param array  $body           Request body.
	 * @param string $client_task_id Client task id.
	 * @param int    $relation_id    Relation id.
	 * @return \WP_REST_Response
	 */
	private function handle_site_string_callback( $body, $client_task_id, $relation_id, $request = null ) {
		$entries       = $body['entries'] ?? array();
		$target_lang   = sanitize_text_field( (string) ( $body['target_lang'] ?? '' ) );
		$business_line = sanitize_key( (string) ( $body['business_line'] ?? '' ) );
		$contexts      = array();

		if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			$contexts = \WPTSALL\Strings\Services\String_Translation_Service::contexts_for_subtype(
				str_replace( '_strings', '', $business_line )
			);
		}

		if ( empty( $entries ) || ! is_array( $entries ) || '' === $target_lang || empty( $contexts ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'missing_entries',
					'message' => 'entries and target_lang are required for site string callbacks.',
				),
				400
			);
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! is_array( $relation ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'relation_not_found', 'message' => 'Site relation was not found.' ), 404 );
		}
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'relation_inactive', 'message' => 'Site relation is not active.' ), 409 );
		}
		$lane_subtype = str_replace( '_strings', '', $business_line );
		if ( ! $this->relation_allows_site_string_lane( $relation, $lane_subtype ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'subtype_not_allowed', 'message' => 'The requested site string lane is not enabled for this site relation.' ), 403 );
		}
		$claim_owner_hash = $request instanceof \WP_REST_Request
			? $this->get_claim_owner_hash( $request, $relation, $lane_subtype, $target_lang )
			: '';
		$relation_target_lang = sanitize_text_field( (string) ( $relation['target_lang'] ?? '' ) );
		if ( '' !== $relation_target_lang && $relation_target_lang !== $target_lang ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'target_language_mismatch', 'message' => 'Callback target language does not match the site relation.' ), 409 );
		}

		$source_site_id  = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$current_site_id = (int) get_current_blog_id();
		if ( $source_site_id <= 0 ) {
			$source_site_id = $current_site_id;
		}
		if ( $source_site_id !== $current_site_id && ! is_multisite() ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'source_site_mismatch', 'message' => 'Relation source site is not available in this WordPress installation.' ), 403 );
		}
		$switched = is_multisite() && $source_site_id !== $current_site_id;
		if ( $switched ) {
			switch_to_blog( $source_site_id );
		}
		try {
			$updated = 0;
			if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
				$updated = \WPTSALL\Strings\Services\String_Translation_Service::apply_client_translations(
					$target_lang,
					$entries,
					$contexts,
					true,
					$claim_owner_hash
				);
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		$entries_rejected = max( 0, count( $entries ) - $updated );
		$site_string_status = $entries_rejected > 0 ? 409 : 200;
		return new \WP_REST_Response(
			array(
				'success'          => 0 === $entries_rejected,
				'updated_count'    => $updated,
				'entries_rejected' => $entries_rejected,
				'error'            => $entries_rejected > 0 ? 'claim_lost_or_entry_rejected' : null,
			),
			$site_string_status
		);
	}

	/**
	 * Embed the immutable claim-time snapshot inside complete_data.
	 *
	 * `ContentItem` deliberately treats complete_data as opaque so that custom
	 * adapters can add fields without a client release.  Keeping the snapshot in
	 * that envelope lets current and older discovery routes use one transport
	 * shape, while the current client can echo it on its callback.
	 *
	 * @param array  $complete_data Complete source object data.
	 * @param string $source_revision Source revision calculated at discovery.
	 * @param string $policy_version Translation policy version at discovery.
	 * @return array
	 */
	private function attach_job_snapshot( $complete_data, $source_revision, $policy_version ) {
		if ( ! is_array( $complete_data ) ) {
			return $complete_data;
		}
		$complete_data['__wptsall_job_snapshot'] = array(
			'source_revision' => (string) $source_revision,
			'policy_version'  => (string) $policy_version,
		);
		return $complete_data;
	}

	/**
	 * Calculate and embed a snapshot for a discovery item.
	 *
	 * @param array  $complete_data Complete source object data.
	 * @param string $object_type Canonical source object type.
	 * @param int    $object_id Source object id.
	 * @return array
	 */
	private function attach_current_job_snapshot( $complete_data, $object_type, $object_id ) {
		if ( ! class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
			return $complete_data;
		}
		return $this->attach_job_snapshot(
			$complete_data,
			\WPTSALL\Core\Job_Snapshot::compute_source_revision( $object_type, $object_id ),
			\WPTSALL\Core\Job_Snapshot::current_policy_version()
		);
	}

}
