<?php
/**
 * Client Data REST Controller content trait.
 *
 * Extracted from Client_Data_REST_Controller to isolate discovery content
 * read paths from route registration, permission, and rate-limit glue.
 *
 * @package WPTSALL\Tasks\API
 */
namespace WPTSALL\Tasks\API;

use WPTSALL\Models\Services\Option_Sync_State_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
trait Client_Data_REST_Controller_Content_Trait {

	/**
	 * GET /client/content
	 *
	 * Returns content items for a relation that need translation (not yet
	 * translated). Filters out items that already have a post mapping in the
	 * wp_wptsall_post_mappings table.
	 *
	 * Parameters:
	 * - relation_id (int, required): Site relation ID.
	 * - data_type (string, optional): "post_type"/"post" or "taxonomy"/"term". Default "post".
	 * - subtype (string, optional): Post type or taxonomy slug. Default "" (all).
	 * - page (int, optional): Page number. Default 1.
	 * - per_page (int, optional): Items per page hint.
	 *   - post/term: default 20, max 100
	 *   - language_pack: controlled by Task Parameters "Client Language Pack Batch"
	 *     (default 100, user-configured without hard upper limit)
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
		$per_page  = $this->resolve_content_per_page( $data_type, $requested_per_page );

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

		if ( 'language_pack' === $data_type ) {
			return $this->get_untranslated_language_pack_entries( $relation, $page, $per_page, $subtype, $include_ids );
		}

		if ( 'site_string' === $data_type ) {
			return $this->get_untranslated_site_string_entries( $relation, $page, $per_page, $subtype, $include_ids );
		}

		if ( 'option' === $data_type ) {
			return $this->get_untranslated_options( $relation, $subtype, $page, $per_page );
		}

		$subtypes = array();
		if ( ! empty( $subtype ) ) {
			$subtypes[] = $subtype;
		} else {
			$models = Relation_Model_Service::get_models_by_relation( $relation_id );
			foreach ( $models as $model ) {
				$rules = Translation_Rule_Service::get_model_rules( (int) $model['id'] );
				foreach ( $rules as $rule ) {
					$merged_config = Translation_Rule_Service::get_merged_config(
						(int) $rule['id'],
						(int) $relation_id,
						array( 'suppress_warning_log' => true )
					);

					$rule_enabled = is_array( $merged_config )
						? (bool) ( $merged_config['enabled'] ?? ( $rule['is_active'] ?? true ) )
						: (bool) ( $rule['is_active'] ?? true );
					if ( ! $rule_enabled ) {
						continue;
					}

					$rule_data_type   = $rule['data_type'] ?? '';
					$rule_object_name = $rule['object_name'] ?? '';

					if ( is_array( $merged_config ) ) {
						$rule_data_type   = $merged_config['data_type'] ?? $rule_data_type;
						$rule_object_name = $merged_config['post_type'] ?? $rule_object_name;
					}

					$normalized_rule_data_type = $rule_data_type;
					if ( 'post_type' === $normalized_rule_data_type ) {
						$normalized_rule_data_type = 'post';
					} elseif ( 'taxonomy' === $normalized_rule_data_type ) {
						$normalized_rule_data_type = 'term';
					}

					if ( '' !== $normalized_rule_data_type && $normalized_rule_data_type !== $data_type ) {
						continue;
					}

					if ( ! empty( $rule_object_name ) ) {
						$subtypes[] = $rule_object_name;
					}
				}
			}
			$subtypes = array_unique( $subtypes );
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
			// The active controller keeps legacy inline methods for compatibility;
			// route discovery through the relation-scoped trait implementation so
			// include_ids cannot bypass mapping, source-site, or claim filters.
			return $this->trait_get_untranslated_posts( $relation, $subtypes, $page, $per_page, $include_resync, $include_ids );
		}

		if ( 'term' === $data_type ) {
			return $this->trait_get_untranslated_terms( $relation, $subtypes, $page, $per_page, $include_ids, $include_resync );
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => 'unsupported_data_type',
				'message' => 'Unsupported data_type: ' . esc_html( $data_type ) . '. Use "post_type"/"post", "taxonomy"/"term", "option", or "language_pack".',
			),
			400
		);
	}

	/**
	 * Get untranslated option-backed items for a relation.
	 *
	 * Minimal lane for message/config sources stored in `wp_options`.
	 *
	 * @param array  $relation Relation row.
	 * @param string $subtype  Specific option name or empty for all enabled rule objects.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return \WP_REST_Response
	 */
	private function get_untranslated_options( $relation, $subtype, $page, $per_page ) {
		$relation_id    = (int) ( $relation['id'] ?? 0 );
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$switched       = false;

		if ( is_multisite() && $source_site_id !== get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}

		try {
		$option_names = array();
		if ( '' !== $subtype ) {
			$safe_subtype = sanitize_key( $subtype );
			if ( '' !== $safe_subtype && \wptsall_is_syncable_option( $safe_subtype, $relation_id ) ) {
				$option_names[] = $safe_subtype;
			}
		} else {
			$models = Relation_Model_Service::get_models_by_relation( $relation_id );
			foreach ( $models as $model ) {
				$rules = Translation_Rule_Service::get_model_rules( (int) $model['id'] );
				foreach ( $rules as $rule ) {
					$merged_config = Translation_Rule_Service::get_merged_config(
						(int) $rule['id'],
						$relation_id,
						array( 'suppress_warning_log' => true )
					);

					$rule_enabled = is_array( $merged_config )
						? (bool) ( $merged_config['enabled'] ?? ( $rule['is_active'] ?? true ) )
						: (bool) ( $rule['is_active'] ?? true );
					if ( ! $rule_enabled ) {
						continue;
					}

					$rule_data_type   = $rule['data_type'] ?? '';
					$rule_object_name = $rule['object_name'] ?? '';
					if ( is_array( $merged_config ) ) {
						$rule_data_type   = $merged_config['data_type'] ?? $rule_data_type;
						$rule_object_name = $merged_config['post_type'] ?? $rule_object_name;
					}

					if ( 'option' !== sanitize_key( (string) $rule_data_type ) ) {
						continue;
					}
					if ( '' !== $rule_object_name ) {
						$option_names[] = sanitize_key( (string) $rule_object_name );
					}
				}
			}
		}

		$option_names = array_values( array_unique( array_filter( $option_names ) ) );
		$option_names = array_values(
			array_filter(
			$option_names,
			static function ( $option_name ) use ( $relation_id ) {
				return \wptsall_is_syncable_option( $option_name, $relation_id );
			}
		)
		);
		// Never serialize an otherwise allowlisted option whose nested value
		// contains credential-shaped keys. The option write-back path reads the
		// authoritative source value again, so skipping the item is safer than
		// masking a secret and risking a destructive translated overwrite.
		$option_names = array_values(
			array_filter(
				$option_names,
				function ( $option_name ) {
					return ! $this->option_value_contains_sensitive_key( get_option( $option_name, null ) );
				}
			)
		);

		// A successful client callback marks the relation-scoped option state as
		// synced. Active claims and already-synced options must not be returned
		// again until the lease expires or a change hook marks needs_resync.
		$claim_timeout = method_exists( $this, 'get_claim_timeout_seconds' )
			? self::get_claim_timeout_seconds()
			: 1800;
		$option_names = array_values(
			array_filter(
			$option_names,
			static function ( $option_name ) use ( $relation, $relation_id, $claim_timeout ) {
				if ( ! class_exists( Option_Sync_State_Service::class ) ) {
					return true;
				}
				$state = Option_Sync_State_Service::get_state(
					$relation_id,
					(int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
					(string) ( $relation['target_site_id'] ?? '' ),
					$option_name
				);
				if ( ! is_array( $state ) ) {
					return true;
				}
				$claimed_at = (string) ( $state['claimed_at'] ?? '' );
				$cutoff     = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $claim_timeout ) );
				if ( '' !== $claimed_at && $claimed_at >= $cutoff ) {
					return false;
				}
				return empty( $state['synced_at'] ) || ! empty( $state['needs_resync'] );
			}
			)
		);
		if ( empty( $option_names ) ) {
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

		$total       = count( $option_names );
		$offset      = max( 0, ( $page - 1 ) * $per_page );
		$paged_names = array_slice( $option_names, $offset, $per_page );
		$items       = array();

		foreach ( $paged_names as $option_name ) {
			$items[] = array(
				'object_type'   => 'option',
				'subtype'       => $option_name,
				'object_id'     => $this->build_option_object_id( $option_name ),
				'needs_resync'  => false,
				'complete_data' => $this->build_option_complete_data( $option_name, $relation_id ),
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
	 * Build stable synthetic object id for option-backed content.
	 *
	 * @param string $option_name Option key.
	 * @return int
	 */
	private function build_option_object_id( string $option_name ): int {
		$hash = crc32( 'option:' . $option_name );
		if ( $hash < 0 ) {
			$hash *= -1;
		}
		return (int) $hash;
	}

	/**
	 * Detect credential-shaped keys in an option value before client export.
	 *
	 * @param mixed $value Option value.
	 * @param int   $depth Recursion guard.
	 * @return bool
	 */
	private function option_value_contains_sensitive_key( $value, $depth = 0 ): bool {
		if ( $depth > 12 || ! is_array( $value ) && ! is_object( $value ) ) {
			return false;
		}
		foreach ( (array) $value as $key => $child ) {
			$key = (string) $key;
			if ( preg_match( '/(^|[_-])(api[_-]?key|secret|token|password|authorization|private|credential)([_-]|$)/i', $key ) ) {
				return true;
			}
			if ( $this->option_value_contains_sensitive_key( $child, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build option complete_data payload for client translation.
	 *
	 * @param string $option_name Option key.
	 * @return array
	 */
	private function build_option_complete_data( string $option_name, int $relation_id = 0 ): array {
		if ( ! \wptsall_is_syncable_option( $option_name, $relation_id ) ) {
			return array();
		}

		$raw_value = get_option( $option_name, null );
		$data      = array(
			'option'       => array(
				'option_name'  => $option_name,
				'option_value' => $raw_value,
			),
			'option_name'  => $option_name,
			'option_value' => $raw_value,
		);

		if ( is_array( $raw_value ) ) {
			foreach ( $raw_value as $key => $value ) {
				if ( is_string( $key ) && ! array_key_exists( $key, $data ) ) {
					$data[ $key ] = $value;
				}
			}
		}

		if ( class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
			$object_id = $this->build_option_object_id( $option_name );
			$data['__wptsall_job_snapshot'] = array(
				'source_revision' => \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'option', $object_id, $option_name ),
				'policy_version'  => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
			);
		}

		return $data;
	}

	/**
	 * Resolve per_page based on content type and task parameters.
	 *
	 * @since 1.3.1
	 *
	 * @param string $data_type          Normalized data type.
	 * @param int    $requested_per_page Requested per_page from client.
	 * @return int
	 */
	private function resolve_content_per_page( $data_type, $requested_per_page ) {
		$requested = absint( $requested_per_page );

		if ( 'language_pack' === $data_type ) {
			if ( function_exists( 'wptsall_get_client_language_pack_batch_size' ) ) {
				return (int) wptsall_get_client_language_pack_batch_size();
			}

			$fallback = $requested > 0 ? $requested : 100;
			return max( 1, $fallback );
		}

		$fallback = $requested > 0 ? $requested : 20;
		return max( 1, min( 100, $fallback ) );
	}

	/**
	 * Get untranslated posts for a relation.
	 *
	 * @param array $relation       Relation data.
	 * @param array $subtypes       Post type slugs.
	 * @param int   $page           Page number.
	 * @param int   $per_page       Items per page.
	 * @param bool  $include_resync Whether to also return needs_resync items.
	 * @param array $include_ids    Specific post IDs to fetch.
	 * @return \WP_REST_Response
	 */
	private function get_untranslated_posts( $relation, $subtypes, $page, $per_page, $include_resync = false, $include_ids = array() ) {
		global $wpdb;

		$relation_id    = (int) $relation['id'];
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );

		$switched = false;
		if ( is_multisite() && $source_site_id !== get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}

		try {
		if ( ! empty( $include_ids ) ) {
			if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
				wptsall_ensure_relation_scoped_mapping_tables();
			}
			list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $include_ids );
			$subtype_sql             = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );
			$mappings_table          = wptsall_table( 'post_mappings' );
			$claim_cutoff            = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
			$rows                    = wptsall_db_get_results(
				"SELECT p.ID, p.post_type FROM %i p
					WHERE p.ID IN ($in_sql)
					AND p.post_type IN ($subtype_sql)
					AND p.post_status IN ('publish', 'inherit')
					AND NOT EXISTS (
						SELECT 1 FROM %i sm
						WHERE sm.post_id = p.ID
						AND sm.meta_key = '_wptsall_virtual_site_id'
					)
					AND (
						(
							NOT EXISTS (
								SELECT 1 FROM %i pm
								WHERE pm.relation_id = %d
								AND pm.source_post_id = p.ID
								AND pm.source_post_type = p.post_type
								AND pm.source_site_id = %d
								AND pm.target_site_id = %s
								AND pm.target_post_id > 0
							)
							AND NOT EXISTS (
								SELECT 1 FROM %i pc
								WHERE pc.relation_id = %d
								AND pc.source_post_id = p.ID
								AND pc.source_post_type = p.post_type
								AND pc.source_site_id = %d
								AND pc.target_site_id = %s
								AND pc.claimed_at IS NOT NULL
								AND pc.claimed_at >= %s
							)
						)
						OR EXISTS (
							SELECT 1 FROM %i pr
							WHERE pr.relation_id = %d
							AND pr.source_post_id = p.ID
							AND pr.source_post_type = p.post_type
							AND pr.source_site_id = %d
							AND pr.target_site_id = %s
							AND pr.needs_resync = 1
							AND (pr.claimed_at IS NULL OR pr.claimed_at < %s)
						)
					)",
				array_merge(
					array( $wpdb->posts ),
					$in_args,
					$subtypes,
					array(
						$wpdb->postmeta,
						$mappings_table, $relation_id, $source_site_id, $relation['target_site_id'] ?? '',
						$mappings_table, $relation_id, $source_site_id, $relation['target_site_id'] ?? '', $claim_cutoff,
						$mappings_table, $relation_id, $source_site_id, $relation['target_site_id'] ?? '', $claim_cutoff,
					)
				),
				ARRAY_A
			);

			$batch_data = $this->batch_load_complete_post_data( $rows );
			$items      = array();
			foreach ( $rows as $row ) {
				$complete_data = $batch_data[ (int) $row['ID'] ] ?? null;
				if ( $complete_data ) {
					$complete_data = $this->attach_current_job_snapshot( $complete_data, 'post_type', (int) $row['ID'] );
					$items[] = array(
						'object_type'   => 'post_type',
						'subtype'       => $row['post_type'],
						'object_id'     => (int) $row['ID'],
						'needs_resync'  => false,
						'complete_data' => $this->flatten_complete_data_for_client( $complete_data ),
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

		if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
			wptsall_ensure_relation_scoped_mapping_tables();
		}

		$mappings_table       = wptsall_table( 'post_mappings' );
		$subtype_placeholders = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );
		$claim_cutoff         = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );

		$count_params = array_merge(
			array( $wpdb->posts ),
			$subtypes,
			array( $mappings_table, $relation_id, $source_site_id, $relation['target_site_id'] ?? '' ),
			array( $mappings_table, $relation_id, $source_site_id, $relation['target_site_id'] ?? '', $claim_cutoff ),
			array( $wpdb->postmeta, $wpdb->postmeta )
		);

		$untranslated_total = (int) wptsall_db_get_var(
			"SELECT COUNT(DISTINCT p.ID)
				FROM %i p
				WHERE p.post_type IN ({$subtype_placeholders})
				AND p.post_status IN ('publish', 'inherit')
					AND NOT EXISTS (
						SELECT 1 FROM %i pm
						WHERE pm.relation_id = %d
						AND pm.source_post_id = p.ID
						AND pm.source_post_type = p.post_type
						AND pm.source_site_id = %d
						AND pm.target_site_id = %s
						AND pm.target_post_id > 0
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i pmc
						WHERE pmc.relation_id = %d
						AND pmc.source_post_id = p.ID
						AND pmc.source_post_type = p.post_type
						AND pmc.source_site_id = %d
						AND pmc.target_site_id = %s
						AND pmc.target_post_id = 0
						AND pmc.claimed_at IS NOT NULL
						AND pmc.claimed_at >= %s
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i vsm
						WHERE vsm.post_id = p.ID
						AND vsm.meta_key = '_wptsall_virtual_site_id'
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i sm
						WHERE sm.post_id = p.ID
						AND sm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
				)",
			$count_params
		);

		$offset       = ( $page - 1 ) * $per_page;
		$query_params = array_merge(
			array( $wpdb->posts ),
			$subtypes,
			array( $mappings_table, $relation_id, $source_site_id, $relation['target_site_id'] ?? '' ),
			array( $mappings_table, $relation_id, $source_site_id, $relation['target_site_id'] ?? '', $claim_cutoff ),
			array( $wpdb->postmeta, $wpdb->postmeta, $per_page, $offset )
		);

		$post_rows = wptsall_db_get_results(
			"SELECT p.ID, p.post_type
				FROM %i p
				WHERE p.post_type IN ({$subtype_placeholders})
				AND p.post_status IN ('publish', 'inherit')
					AND NOT EXISTS (
						SELECT 1 FROM %i pm
						WHERE pm.relation_id = %d
						AND pm.source_post_id = p.ID
						AND pm.source_post_type = p.post_type
						AND pm.source_site_id = %d
						AND pm.target_site_id = %s
						AND pm.target_post_id > 0
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i pmc
						WHERE pmc.relation_id = %d
						AND pmc.source_post_id = p.ID
						AND pmc.source_post_type = p.post_type
						AND pmc.source_site_id = %d
						AND pmc.target_site_id = %s
						AND pmc.target_post_id = 0
						AND pmc.claimed_at IS NOT NULL
						AND pmc.claimed_at >= %s
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i vsm
						WHERE vsm.post_id = p.ID
						AND vsm.meta_key = '_wptsall_virtual_site_id'
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i sm
						WHERE sm.post_id = p.ID
						AND sm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
					)
					ORDER BY p.ID ASC
					LIMIT %d OFFSET %d",
			$query_params,
			ARRAY_A
		);

		$items      = array();
		$batch_data = $this->batch_load_complete_post_data( $post_rows );
		foreach ( (array) $post_rows as $row ) {
			$post_id       = (int) $row['ID'];
			$complete_data = $batch_data[ $post_id ] ?? null;

			if ( $complete_data ) {
				$complete_data = $this->attach_current_job_snapshot( $complete_data, 'post_type', $post_id );
				$items[] = array(
					'object_type'   => 'post_type',
					'subtype'       => $row['post_type'],
					'object_id'     => $post_id,
					'needs_resync'  => false,
					'complete_data' => $this->flatten_complete_data_for_client( $complete_data ),
				);
			}
		}

		$resync_total = 0;
		if ( $include_resync ) {
			$claim_timeout = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
			$resync_count_params = array_merge(
				array( $mappings_table, $wpdb->posts, $relation_id, $source_site_id, $relation['target_site_id'] ?? '', $claim_timeout ),
				$subtypes,
				array( $wpdb->postmeta )
			);

			$resync_total = (int) wptsall_db_get_var(
				"SELECT COUNT(*)
					FROM %i pm
					INNER JOIN %i p ON pm.source_post_id = p.ID AND pm.source_post_type = p.post_type
					WHERE pm.relation_id = %d
					AND pm.source_site_id = %d
					AND pm.target_site_id = %s
					AND pm.needs_resync = 1
					AND (pm.claimed_at IS NULL OR pm.claimed_at < %s)
					AND p.post_status IN ('publish', 'inherit')
					AND p.post_type IN ({$subtype_placeholders})
					AND NOT EXISTS (
						SELECT 1 FROM %i sm
						WHERE sm.post_id = p.ID
						AND sm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
					)",
				$resync_count_params
			);

			$global_offset = max( 0, ( $page - 1 ) * $per_page );
			$resync_offset = max( 0, $global_offset - $untranslated_total );
			$remaining     = $per_page - count( $items );
			if ( $remaining > 0 && $resync_total > 0 ) {
				$resync_query_params = array_merge(
					array( $mappings_table, $wpdb->posts, $relation_id, $source_site_id, $relation['target_site_id'] ?? '', $claim_timeout ),
					$subtypes,
					array( $wpdb->postmeta, $remaining, $resync_offset )
				);

				$resync_rows = wptsall_db_get_results(
					"SELECT pm.source_post_id AS ID, pm.source_post_type AS post_type, pm.id AS mapping_id
						FROM %i pm
						INNER JOIN %i p ON pm.source_post_id = p.ID AND pm.source_post_type = p.post_type
						WHERE pm.relation_id = %d
						AND pm.source_site_id = %d
						AND pm.target_site_id = %s
						AND pm.needs_resync = 1
						AND (pm.claimed_at IS NULL OR pm.claimed_at < %s)
						AND p.post_status IN ('publish', 'inherit')
						AND p.post_type IN ({$subtype_placeholders})
						AND NOT EXISTS (
							SELECT 1 FROM %i sm
							WHERE sm.post_id = p.ID
							AND sm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
						)
							ORDER BY pm.source_post_id ASC
							LIMIT %d OFFSET %d",
					$resync_query_params,
					ARRAY_A
				);

				$resync_batch_data = $this->batch_load_complete_post_data( $resync_rows );
				foreach ( (array) $resync_rows as $row ) {
					$post_id       = (int) $row['ID'];
					$complete_data = $resync_batch_data[ $post_id ] ?? null;

					if ( $complete_data ) {
						$complete_data = $this->attach_current_job_snapshot( $complete_data, 'post_type', $post_id );
						$items[] = array(
							'object_type'   => 'post_type',
							'subtype'       => $row['post_type'],
							'object_id'     => $post_id,
							'needs_resync'  => true,
							'mapping_id'    => (int) $row['mapping_id'],
							'complete_data' => $this->flatten_complete_data_for_client( $complete_data ),
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
				'relation_id'    => $relation_id,
				'subtypes'       => $subtypes,
				'untranslated'   => $untranslated_total,
				'resync'         => $resync_total,
				'total'          => $total,
				'page'           => $page,
				'returned'       => count( $items ),
				'include_resync' => $include_resync,
			)
		);

		$response = new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
		return $response;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Get untranslated terms for a relation.
	 *
	 * @param array $relation   Relation data.
	 * @param array $subtypes   Taxonomy slugs.
	 * @param int   $page       Page number.
	 * @param int   $per_page   Items per page.
	 * @param array $include_ids Specific term IDs to fetch.
	 * @return \WP_REST_Response
	 */
	private function get_untranslated_terms( $relation, $subtypes, $page, $per_page, $include_ids = array(), $include_resync = false ) {
		global $wpdb;

		$relation_id         = (int) $relation['id'];
		$source_site_id      = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id      = $relation['target_site_id'] ?? '';
		$target_lang         = sanitize_text_field( (string) ( $relation['target_lang'] ?? $relation['target_language'] ?? '' ) );
		$claim_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );

		$source_switched = false;
		if ( is_multisite() && $source_site_id !== get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$source_switched = true;
		}

		try {
		$term_mappings_table = wptsall_table( 'term_mappings' );
		$tt_table            = $wpdb->term_taxonomy;
		$tax_placeholders    = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );

		if ( ! empty( $include_ids ) ) {
			if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
				wptsall_ensure_relation_scoped_mapping_tables();
			}
			list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $include_ids );
			$fast_params              = array_merge(
				array( $wpdb->terms, $tt_table ),
				$in_args,
				$subtypes,
				array(
					$wpdb->termmeta,
					$term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang,
					$term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang, $claim_cutoff,
					$term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang, $claim_cutoff,
				)
			);

			$term_rows = wptsall_db_get_results(
				"SELECT DISTINCT t.term_id, tt.taxonomy
					FROM %i t
					INNER JOIN %i tt ON t.term_id = tt.term_id
					WHERE t.term_id IN ($in_sql)
						AND tt.taxonomy IN ({$tax_placeholders})
						AND NOT EXISTS (
							SELECT 1 FROM %i tv
							WHERE tv.term_id = t.term_id
							AND tv.meta_key = '_wptsall_virtual_site_id'
						)
						AND (
							(
								NOT EXISTS (
									SELECT 1 FROM %i tm
									WHERE tm.relation_id = %d
									AND tm.source_term_id = t.term_id
									AND tm.source_taxonomy = tt.taxonomy
									AND tm.source_site_id = %d
									AND tm.target_site_id = %s
									AND tm.target_lang = %s
									AND tm.target_term_id > 0
								)
								AND NOT EXISTS (
									SELECT 1 FROM %i tc
									WHERE tc.relation_id = %d
									AND tc.source_term_id = t.term_id
									AND tc.source_taxonomy = tt.taxonomy
									AND tc.source_site_id = %d
									AND tc.target_site_id = %s
									AND tc.target_lang = %s
									AND tc.claimed_at IS NOT NULL
									AND tc.claimed_at >= %s
								)
							)
							OR EXISTS (
								SELECT 1 FROM %i tr
								WHERE tr.relation_id = %d
								AND tr.source_term_id = t.term_id
								AND tr.source_taxonomy = tt.taxonomy
								AND tr.source_site_id = %d
								AND tr.target_site_id = %s
								AND tr.target_lang = %s
								AND tr.needs_resync = 1
								AND (tr.claimed_at IS NULL OR tr.claimed_at < %s)
							)
						)
					ORDER BY t.term_id ASC",
				$fast_params,
				ARRAY_A
			);

			$items = array();
			foreach ( (array) $term_rows as $row ) {
				$term_id       = (int) $row['term_id'];
				$taxonomy      = $row['taxonomy'];
				$complete_data = function_exists( 'wptsall_get_complete_term_data' )
					? wptsall_get_complete_term_data( $taxonomy, $term_id )
					: null;

				if ( $complete_data ) {
					$complete_data = $this->attach_current_job_snapshot( $complete_data, 'taxonomy', $term_id );
					$items[] = array(
						'object_type'   => 'taxonomy',
						'subtype'       => $taxonomy,
						'object_id'     => $term_id,
						'needs_resync'  => false,
						'complete_data' => $this->flatten_complete_data_for_client( $complete_data ),
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

		$count_params = array_merge(
			array( $wpdb->terms, $tt_table ),
			$subtypes,
			array( $wpdb->termmeta ),
			array( $term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang ),
			array( $term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang, $claim_cutoff )
		);

		$total = (int) wptsall_db_get_var(
			"SELECT COUNT(DISTINCT t.term_id)
				FROM %i t
				INNER JOIN %i tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy IN ({$tax_placeholders})
					AND NOT EXISTS (
						SELECT 1 FROM %i vm
						WHERE vm.term_id = t.term_id
						AND vm.meta_key = '_wptsall_virtual_site_id'
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i tm
						WHERE tm.relation_id = %d
						AND tm.source_term_id = t.term_id
						AND tm.source_taxonomy = tt.taxonomy
						AND tm.source_site_id = %d
						AND tm.target_site_id = %s
						AND tm.target_lang = %s
						AND tm.target_term_id > 0
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i tc
						WHERE tc.relation_id = %d
						AND tc.source_term_id = t.term_id
						AND tc.source_taxonomy = tt.taxonomy
						AND tc.source_site_id = %d
						AND tc.target_site_id = %s
						AND tc.target_lang = %s
						AND tc.target_term_id = 0
						AND tc.claimed_at IS NOT NULL
						AND tc.claimed_at >= %s
					)",
			$count_params
		);

		$offset       = ( $page - 1 ) * $per_page;
		$query_params = array_merge(
			array( $wpdb->terms, $tt_table ),
			$subtypes,
			array( $wpdb->termmeta ),
			array( $term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang ),
			array( $term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang, $claim_cutoff ),
			array( $per_page, $offset )
		);

		$term_rows = wptsall_db_get_results(
			"SELECT DISTINCT t.term_id, tt.taxonomy
				FROM %i t
				INNER JOIN %i tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy IN ({$tax_placeholders})
					AND NOT EXISTS (
						SELECT 1 FROM %i vm
						WHERE vm.term_id = t.term_id
						AND vm.meta_key = '_wptsall_virtual_site_id'
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i tm
						WHERE tm.relation_id = %d
						AND tm.source_term_id = t.term_id
						AND tm.source_taxonomy = tt.taxonomy
						AND tm.source_site_id = %d
						AND tm.target_site_id = %s
						AND tm.target_lang = %s
						AND tm.target_term_id > 0
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i tc
						WHERE tc.relation_id = %d
						AND tc.source_term_id = t.term_id
						AND tc.source_taxonomy = tt.taxonomy
						AND tc.source_site_id = %d
						AND tc.target_site_id = %s
						AND tc.target_lang = %s
						AND tc.target_term_id = 0
						AND tc.claimed_at IS NOT NULL
						AND tc.claimed_at >= %s
					)
					ORDER BY t.term_id ASC
					LIMIT %d OFFSET %d",
			$query_params,
			ARRAY_A
		);

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
					'needs_resync'  => false,
					'complete_data' => $this->flatten_complete_data_for_client( $complete_data ),
				);
			}
		}

		if ( $include_resync && count( $items ) < $per_page ) {
			$resync_params = array_merge(
				array( $wpdb->terms, $tt_table, $term_mappings_table, $relation_id, $source_site_id, $target_site_id, $target_lang, $claim_cutoff ),
				$subtypes,
				array( $wpdb->termmeta, $per_page - count( $items ) )
			);

			$resync_rows = wptsall_db_get_results(
				"SELECT DISTINCT t.term_id, tt.taxonomy
					FROM %i t
					INNER JOIN %i tt ON t.term_id = tt.term_id
					INNER JOIN %i tm ON tm.source_term_id = t.term_id
						AND tm.source_taxonomy = tt.taxonomy
						AND tm.relation_id = %d
						AND tm.source_site_id = %d
						AND tm.target_site_id = %s
						AND tm.target_lang = %s
						AND tm.target_term_id > 0
						AND tm.needs_resync = 1
						AND (tm.claimed_at IS NULL OR tm.claimed_at < %s)
					WHERE tt.taxonomy IN ({$tax_placeholders})
					AND NOT EXISTS (
						SELECT 1 FROM %i vm
						WHERE vm.term_id = t.term_id
						AND vm.meta_key = '_wptsall_virtual_site_id'
					)
					ORDER BY t.term_id ASC
					LIMIT %d",
				$resync_params,
				ARRAY_A
			);

			foreach ( (array) $resync_rows as $row ) {
				$term_id  = (int) $row['term_id'];
				$taxonomy = $row['taxonomy'];
				$complete_data = function_exists( 'wptsall_get_complete_term_data' )
					? wptsall_get_complete_term_data( $taxonomy, $term_id )
					: null;

				if ( $complete_data ) {
					$complete_data = $this->attach_current_job_snapshot( $complete_data, 'taxonomy', $term_id );
					$items[] = array(
						'object_type'   => 'taxonomy',
						'subtype'       => $taxonomy,
						'object_id'     => $term_id,
						'needs_resync'  => true,
						'complete_data' => $this->flatten_complete_data_for_client( $complete_data ),
					);
				}
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
				'include_resync' => $include_resync,
			)
		);

		$response = new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
		return $response;
		} finally {
			if ( $source_switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Batch-load complete post data for multiple posts.
	 *
	 * @param array $post_rows Array of rows with 'ID' and 'post_type' keys.
	 * @return array
	 */
	private function batch_load_complete_post_data( $post_rows ) {
		global $wpdb;

		if ( empty( $post_rows ) ) {
			return array();
		}

		$post_ids = wp_list_pluck( $post_rows, 'ID' );
		$post_ids = array_map( 'intval', $post_ids );
		$post_ids = array_filter( $post_ids );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$post_type_by_id = array();
		foreach ( $post_rows as $row ) {
			$post_type_by_id[ (int) $row['ID'] ] = $row['post_type'];
		}

		list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $post_ids );

		$posts_data = wptsall_db_get_results(
			"SELECT * FROM %i WHERE ID IN ($in_sql)",
			array_merge( array( $wpdb->posts ), $in_args )
		);

		$posts_by_id = array();
		foreach ( (array) $posts_data as $post_obj ) {
			$posts_by_id[ (int) $post_obj->ID ] = $post_obj;
		}

		$meta_results = wptsall_db_get_results(
			"SELECT post_id, meta_key, meta_value FROM %i WHERE post_id IN ($in_sql)",
			array_merge( array( $wpdb->postmeta ), $in_args )
		);

		$meta_by_post = array();
		foreach ( (array) $meta_results as $meta ) {
			$meta_by_post[ (int) $meta->post_id ][ $meta->meta_key ][] = $meta->meta_value;
		}

		$result = array();
		foreach ( $post_ids as $post_id ) {
			$post          = $posts_by_id[ $post_id ] ?? null;
			$expected_type = $post_type_by_id[ $post_id ] ?? '';

			if ( ! $post || $post->post_type !== $expected_type ) {
				continue;
			}

			$post_data = array(
				'ID'             => $post->ID,
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => $post->post_status,
				'post_type'      => $post->post_type,
				'post_name'      => $post->post_name,
				'post_parent'    => $post->post_parent,
				'post_author'    => $post->post_author,
				'post_date'      => $post->post_date,
				'post_modified'  => $post->post_modified,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
			);

			$raw_meta       = $meta_by_post[ $post_id ] ?? array();
			$filtered_meta  = array();
			$wp_translatable = array( '_wp_attachment_image_alt' );
			foreach ( $raw_meta as $key => $values ) {
				if ( ( strpos( $key, '_wp_' ) === 0 && ! in_array( $key, $wp_translatable, true ) )
					|| strpos( $key, '_edit_' ) === 0 ) {
					continue;
				}
				$filtered_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}

			$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
			$tax_data   = array();
			foreach ( $taxonomies as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$tax_data[ $taxonomy ] = array_map(
						function ( $term ) {
							return array(
								'term_id' => $term->term_id,
								'name'    => $term->name,
								'slug'    => $term->slug,
							);
						},
						$terms
					);
				}
			}

			$thumbnail_id = isset( $raw_meta['_thumbnail_id'][0] ) ? (int) $raw_meta['_thumbnail_id'][0] : 0;
			if ( $thumbnail_id ) {
				$post_data['_thumbnail_id']  = $thumbnail_id;
				$post_data['_thumbnail_url'] = wp_get_attachment_url( $thumbnail_id );
			}

			// Keep attachment binary discovery consistent with the non-batched
			// complete-object helper used by the durable outbox path.
			if ( 'attachment' === $post->post_type ) {
				$post_data['attachment_url']       = (string) ( wp_get_attachment_url( $post_id ) ?: '' );
				$post_data['attachment_mime_type'] = (string) $post->post_mime_type;
				$post_data['attachment_filename']  = (string) basename( (string) get_attached_file( $post_id ) );
			}

			$result[ $post_id ] = array(
				'object_type' => 'post_type',
				'subtype'     => $post->post_type,
				'object_id'   => $post_id,
				'post'        => $post_data,
				'meta'        => $filtered_meta,
				'taxonomies'  => $tax_data,
			);
		}

		return $result;
	}

	/**
	 * Flatten complete_data for client consumption.
	 *
	 * @param array $complete_data Complete data from wptsall_get_complete_{post,term}_data().
	 * @return array
	 */
	private function flatten_complete_data_for_client( $complete_data ) {
		if ( ! is_array( $complete_data ) ) {
			return $complete_data;
		}

		if ( isset( $complete_data['post'] ) && is_array( $complete_data['post'] ) ) {
			foreach ( $complete_data['post'] as $key => $value ) {
				$complete_data[ $key ] = $value;
			}
		}

		if ( isset( $complete_data['term'] ) && is_array( $complete_data['term'] ) ) {
			foreach ( $complete_data['term'] as $key => $value ) {
				$complete_data[ $key ] = $value;
			}
		}

		if ( isset( $complete_data['meta'] ) && is_array( $complete_data['meta'] ) ) {
			foreach ( $complete_data['meta'] as $key => $value ) {
				if ( ! isset( $complete_data[ $key ] ) ) {
					$complete_data[ $key ] = $value;
				}
			}
		}

		return $complete_data;
	}

	/**
	 * Get i18n config for a site relation.
	 *
	 * @param int $relation_id Relation ID.
	 * @return array
	 */
	private function get_i18n_config_for_relation( $relation_id ) {
		$config = array(
			'translate_plugin_i18n' => false,
			'translate_theme_i18n'  => false,
			'translate_config_i18n' => false,
		);
		if ( class_exists( '\WPTSALL\Sites\Services\Relation_Config_Service' ) ) {
			$template_config = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
			$config['translate_plugin_i18n'] = ! empty( $template_config['translate_plugin_i18n'] );
			$config['translate_theme_i18n']  = ! empty( $template_config['translate_theme_i18n'] );
			$config['translate_config_i18n'] = ! empty( $template_config['translate_config_i18n'] );
		}
		return $config;
	}

	/**
	 * Get untranslated language pack entries for a relation.
	 *
	 * @param array  $relation    Relation data.
	 * @param int    $page        Page number.
	 * @param int    $per_page    Items per page.
	 * @param string $subtype     Optional source_type filter.
	 * @param array  $include_ids Optional entry IDs to fetch; include_ids cannot bypass claim filters.
	 * @return \WP_REST_Response
	 */
	private function get_untranslated_language_pack_entries( $relation, $page, $per_page, $subtype = '', $include_ids = array() ) {
		global $wpdb;

		$relation_id     = (int) $relation['id'];
		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );
		$claim_timeout   = gmdate( 'Y-m-d H:i:s', time() - self::get_claim_timeout_seconds() );
		$offset          = ( $page - 1 ) * $per_page;

		$include_sql      = '';
		$include_ids_args = array();
		if ( ! empty( $include_ids ) ) {
			list( $include_sql, $include_ids_args ) = wptsall_db_prepare_int_in( $include_ids );
			$include_sql                            = " AND e.id IN ($include_sql)";
		}

		// Expand static SQL per branch so PHPCS can count prepare placeholders.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$include_sql} is a placeholder-only IN(...) fragment produced by wptsall_db_prepare_int_in(); every value binds through the merged prepare arrays.
		if ( ! empty( $subtype ) ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i e INNER JOIN %i t ON e.template_id = t.id
					 WHERE t.relation_id = %d AND e.status = 'pending'
					 AND (e.claimed_at IS NULL OR e.claimed_at < %s)
					 AND t.source_type = %s{$include_sql}",
					array_merge(
						array( $entries_table, $templates_table, $relation_id, $claim_timeout, $subtype ),
						$include_ids_args
					)
				)
			);
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.id AS entry_id, e.template_id, e.msgid, e.msgid_plural,
						e.msgctxt, e.reference,
						t.text_domain, t.source_type, t.source_name
					 FROM %i e
					 INNER JOIN %i t ON e.template_id = t.id
					 WHERE t.relation_id = %d AND e.status = 'pending'
					 AND (e.claimed_at IS NULL OR e.claimed_at < %s)
					 AND t.source_type = %s{$include_sql}
					 ORDER BY t.id ASC, e.id ASC
					 LIMIT %d OFFSET %d",
					array_merge(
						array( $entries_table, $templates_table, $relation_id, $claim_timeout, $subtype ),
						$include_ids_args,
						array( $per_page, $offset )
					)
				),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i e INNER JOIN %i t ON e.template_id = t.id
					 WHERE t.relation_id = %d AND e.status = 'pending'
					 AND (e.claimed_at IS NULL OR e.claimed_at < %s){$include_sql}",
					array_merge(
						array( $entries_table, $templates_table, $relation_id, $claim_timeout ),
						$include_ids_args
					)
				)
			);
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.id AS entry_id, e.template_id, e.msgid, e.msgid_plural,
						e.msgctxt, e.reference,
						t.text_domain, t.source_type, t.source_name
					 FROM %i e
					 INNER JOIN %i t ON e.template_id = t.id
					 WHERE t.relation_id = %d AND e.status = 'pending'
					 AND (e.claimed_at IS NULL OR e.claimed_at < %s){$include_sql}
					 ORDER BY t.id ASC, e.id ASC
					 LIMIT %d OFFSET %d",
					array_merge(
						array( $entries_table, $templates_table, $relation_id, $claim_timeout ),
						$include_ids_args,
						array( $per_page, $offset )
					)
				),
				ARRAY_A
			);
		}
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
	}

	/**
	 * Get untranslated Layer B site strings for client.
	 *
	 * @param array  $relation    Relation row.
	 * @param int    $page        Page.
	 * @param int    $per_page    Per page.
	 * @param string $subtype     site|menu|widget
	 * @param array  $include_ids Optional ids.
	 * @return \WP_REST_Response
	 */
	private function get_untranslated_site_string_entries( $relation, $page, $per_page, $subtype = '', $include_ids = array() ) {
		$target_lang = sanitize_text_field( (string) ( $relation['target_lang'] ?? $relation['target_language'] ?? '' ) );
		if ( '' === $target_lang ) {
			$target_lang = sanitize_text_field( (string) ( $relation['source_lang'] ?? 'en_US' ) );
		}
		$source_site_id = absint( $relation['source_site_id'] ?? get_current_blog_id() );
		$switched = false;
		if ( is_multisite() && $source_site_id > 0 && $source_site_id !== get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$switched = true;
		}
		try {
			$result = array( 'items' => array(), 'total' => 0 );
			if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
				$result = \WPTSALL\Strings\Services\String_Translation_Service::list_untranslated_for_client(
					$target_lang,
					$subtype,
					$page,
					$per_page,
					$include_ids
				);
			}
			return new \WP_REST_Response(
			array(
				'items'    => $result['items'],
				'total'    => $result['total'],
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
// phpcs:enable
}
