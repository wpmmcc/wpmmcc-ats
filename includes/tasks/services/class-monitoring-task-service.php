<?php
/**
 * Monitoring Task Service — Path A: Task Lifecycle Management
 *
 * This service manages task lifecycle (CRUD, status, scheduling) and creates
 * sync tasks that are picked up by the external Client for processing.
 *
 * == Sync Path A (this service) ==
 * Monitoring_Task_Service creates "pending" tasks in the tasks table.
 * These tasks are then fetched by the external Rust Client via REST API
 * (GET /wptsall/v2/client/tasks), translated by mock-translate-api,
 * and results are submitted back via POST /wptsall/v2/client/tasks/{id}/result.
 *
 * == Sync Path B (Sync_Executor) ==
 * Sync_Executor::execute_with_config() handles the write-back of Client results
 * into the target site (wp_posts for WP subsites, virtual_site_content for virtual sites).
 * It uses Field_Processor for field-level transformations.
 *
 * Key: Path A creates tasks; Path B writes results. They are complementary, not competing.
 *
 * @package WPTSALL\Tasks\Services
 * @since 0.6.0
 * @see \WPTSALL\Tasks\Sync\Sync_Executor  Path B: client result write-back
 * @see as-docs/SYNC-PATHS.md              Full sync paths documentation
  * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers from internal helpers (wptsall_table / \$wpdb->prefix . 'wptsall_*'); user values use prepare placeholders.
 */
namespace WPTSALL\Tasks\Services;

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitoring Task Service — Monitoring task lifecycle and i18n scanning.
 *
 * Since 1.2.0 content translation tasks are driven by the external Client
 * (Client_Data_REST_Controller content discovery + claim + callback).
 * WP no longer creates content sync tasks via cron.
 *
 * This service is still actively used for:
 * - Monitoring task CRUD (start/stop/status per relation)
 * - i18n scanning (theme/plugin .po/.pot file translation)
 *
 * @deprecated 1.2.0 create_sync_task() and create_term_sync_task() are deprecated.
 *             Content tasks are now created via translation-callback endpoint.
 * @see \WPTSALL\Tasks\API\Client_Data_REST_Controller  Client-driven content flow
 * @see \WPTSALL\Tasks\Sync\Sync_Executor               Write-back execution
 */
class Monitoring_Task_Service {

	/**
	 * Task type constant for monitoring tasks
	 *
	 * @var string
	 */
	const TASK_TYPE = 'monitoring';

	/**
	 * Status constants
	 */
	const STATUS_ACTIVE    = 'active';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_ERROR     = 'error';

	/**
	 * Get or create monitoring task for a site relation
	 *
	 * @param int $relation_id Site relation ID.
	 * @return array|null Monitoring task data or null on error.
	 */
	public static function get_or_create( $relation_id ) {
		$existing = self::get_by_relation( $relation_id );

		if ( $existing ) {
			return $existing;
		}

		// Validate relation exists.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Cannot create monitoring task: relation not found',
				array( 'relation_id' => $relation_id )
			);
			return null;
		}

		return self::create( $relation_id );
	}

	/**
	 * Get monitoring task by relation ID
	 *
	 * @param int $relation_id Site relation ID.
	 * @return array|null Task data or null.
	 */
	public static function get_by_relation( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$task = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE type = %s AND relation_id = %d ORDER BY id DESC LIMIT 1',
				$table,
				self::TASK_TYPE,
				$relation_id
			),
			ARRAY_A
		);

		if ( ! $task ) {
			return null;
		}

		// Parse JSON fields.
		$task['progress']   = json_decode( $task['progress'] ?? '{}', true ) ?: array();
		$task['meta']       = json_decode( $task['meta'] ?? '{}', true ) ?: array();
		$task['model_ids']  = json_decode( $task['model_ids'] ?? '[]', true ) ?: array();

		return $task;
	}

	/**
	 * Create a new monitoring task for a relation
	 *
	 * @param int $relation_id Site relation ID.
	 * @return array|null Created task data or null on error.
	 */
	public static function create( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return null;
		}

		// Get associated models.
		$models    = Relation_Model_Service::get_models_by_relation( $relation_id );
		$model_ids = array_column( $models, 'id' );

		// Calculate initial progress.
		$progress = self::calculate_initial_progress( $relation, $models );

		$data = array(
			'type'              => self::TASK_TYPE,
			'relation_id'       => $relation_id,
			'model_ids'         => wp_json_encode( $model_ids ),
			'status'            => self::STATUS_ACTIVE,
			'priority'          => 'normal',
			'progress'          => wp_json_encode( $progress ),
			'meta'              => wp_json_encode( array(
				'source_site_type' => $relation['source_site_type'] ?? 'wp',
				'source_site_id'   => $relation['source_site_id'] ?? '',
				'target_site_type' => $relation['target_site_type'] ?? '',
				'target_site_id'   => $relation['target_site_id'] ?? '',
				'models_count'     => count( $model_ids ),
				'source'           => 'monitoring_create',
			) ),
			'last_check_at'     => null,
			'next_check_at'     => current_time( 'mysql' ),
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
			// Mandatory fields with no defaults in schema
			'blog_id'           => (int) ($relation['source_site_id'] ?? 0),
			'site_id'           => (int) $relation_id,
			'target_type'       => sanitize_text_field( $relation['target_site_type'] ?? '' ),
			'target_identifier' => sanitize_text_field( $relation['target_site_id'] ?? '' ),
			'template'          => sanitize_text_field( $relation['template'] ?? '' ),
			'object_type'       => 'monitoring',
			'subtype'           => '',
			'object_id'         => 0,
			'lang_from'         => sanitize_text_field( $relation['source_lang'] ?? '' ),
			'lang_to'           => sanitize_text_field( $relation['target_lang'] ?? '' ),
			'site_mode'         => '',
			'payload'           => '',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Failed to create monitoring task',
				array(
					'relation_id' => $relation_id,
					'error'       => $wpdb->last_error,
				)
			);
			return null;
		}

		$task_id = $wpdb->insert_id;

		wptsall_log_info(
			'tasks-monitoring',
			'Monitoring task created',
			array(
				'task_id'     => $task_id,
				'relation_id' => $relation_id,
				'models'      => count( $model_ids ),
			)
		);

		return self::get( $task_id );
	}

	/**
	 * Create a sync task for a single content item.
	 *
	 * @deprecated 1.2.0 Content tasks are now created via the translation-callback
	 *             endpoint in Client_Data_REST_Controller. Hook_Manager now marks
	 *             needs_resync instead of creating tasks.
	 *
	 * Used by Hook_Manager to create tasks when content is created/updated.
	 * Supports deduplication: won't create duplicate pending tasks for same post + relation.
	 *
	 * @since 0.9.0
	 *
	 * @param int $post_id     Source post ID.
	 * @param int $relation_id Site relation ID.
	 * @return int|false Task ID on success, false on failure or duplicate.
	 */
	public static function create_sync_task( $post_id, $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// Validate inputs.
		$post = get_post( $post_id );
		if ( ! $post ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Cannot create sync task: post not found',
				array( 'post_id' => $post_id )
			);
			return false;
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Cannot create sync task: relation not found',
				array( 'relation_id' => $relation_id )
			);
			return false;
		}

		$post_type = $post->post_type;

		// Check for duplicate open task (same post + relation).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE type = 'sync' AND relation_id = %d AND object_id = %d AND object_type IN ('post','post_type') AND status IN ('pending','retry','processing','active') LIMIT 1",
				$table,
				$relation_id,
				$post_id
			)
		);

		if ( $existing ) {
			wptsall_log_info(
				'tasks-monitoring',
				'Sync task already exists (dedup)',
				array(
					'existing_task_id' => $existing,
					'post_id'          => $post_id,
					'relation_id'      => $relation_id,
				)
			);
			return (int) $existing;
		}

		// Get merged config for field capabilities via the canonical service method.
		$merged_config = Translation_Rule_Service::get_merged_config_for_relation( $relation_id, $post_type );
		// get_merged_config_for_relation returns capabilities format:
		//   translate_fields, sync_fields, id_mapping_fields, field_capabilities (raw), etc.
		// When no rule exists, it returns defaults from get_default_config.
		$config    = $merged_config;
		$rule_caps = $merged_config['field_capabilities'] ?? array();

		// Determine if a real rule was found (non-default configs have populated field_capabilities).
		$rule_found = ! empty( $rule_caps );
		if ( ! $rule_found ) {
			wptsall_log_warning(
				'tasks-monitoring',
				'No translation rule found for post type, task created with empty field_capabilities',
				array(
					'relation_id' => $relation_id,
					'post_type'   => $post_type,
					'post_id'     => $post_id,
				)
			);
		}

		$job_id = function_exists( 'wptsall_generate_task_job_id' )
			? wptsall_generate_task_job_id( 'monitoring_' . $relation_id . '_' . $post_id )
			: sanitize_key( 'job_monitoring_' . $relation_id . '_' . $post_id );
		$fields = array(
			'post_title'   => (string) $post->post_title,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_content' => (string) $post->post_content,
		);

		// Extract additional translation meta fields from merged field_capabilities.
		if ( ! empty( $rule_caps ) && is_array( $rule_caps ) ) {
			foreach ( $rule_caps as $field_name => $field_config ) {
				$capability = is_array( $field_config )
					? ( $field_config['capability'] ?? ( $field_config['type'] ?? 'skip' ) )
					: (string) $field_config;
				// Only collect translate-type meta fields (core fields already processed above)
				if ( 'translate' === $capability && ! in_array( $field_name, array( 'post_title', 'post_content', 'post_excerpt' ), true ) ) {
					$meta_value = get_post_meta( $post->ID, $field_name, true );
					if ( ! empty( $meta_value ) && is_string( $meta_value ) ) {
						$fields[ $field_name ] = $meta_value;
					}
				}
			}
		}

		$task_seed = array(
			'job_id'            => $job_id,
			'blog_id'           => get_current_blog_id(),
			'target_type'       => sanitize_text_field( $relation['target_site_type'] ?? '' ),
			'target_identifier' => sanitize_text_field( $relation['target_site_id'] ?? '' ),
			'template'          => sanitize_text_field( $relation['template'] ?? '' ),
			'object_type'       => 'post_type',
			'subtype'           => $post_type,
			'object_id'         => $post_id,
			'lang_from'         => sanitize_text_field( $relation['source_lang'] ?? '' ),
			'lang_to'           => sanitize_text_field( $relation['target_lang'] ?? '' ),
			'task_type'         => 'text',
			'fields'            => $fields,
		);
		if ( function_exists( 'wptsall_prepare_task_for_client_payload' ) ) {
			$task_seed = wptsall_prepare_task_for_client_payload( $task_seed, 'text' );
		}
		$task_payload = array(
			'post_id'            => $post_id,
			'post_type'          => $post_type,
			'rule_status'        => $rule_found ? 'complete' : 'missing',
			'field_capabilities' => $rule_caps,
			'job_id'             => sanitize_text_field( (string) ( $task_seed['job_id'] ?? $job_id ) ),
			'business_line'      => sanitize_key( (string) ( $task_seed['business_line'] ?? 'post_content' ) ),
			'object_ref'         => is_array( $task_seed['object_ref'] ?? null ) ? $task_seed['object_ref'] : array(),
			'task_type'          => sanitize_key( (string) ( $task_seed['task_type'] ?? 'text' ) ),
			'subtasks'           => is_array( $task_seed['subtasks'] ?? null ) ? $task_seed['subtasks'] : array(),
			'content_items'      => is_array( $task_seed['content_items'] ?? null ) ? $task_seed['content_items'] : array(),
			'fields'             => is_array( $task_seed['fields'] ?? null ) ? $task_seed['fields'] : array(),
		);

			$data = array(
				'type'              => 'sync',
				'relation_id'       => $relation_id,
				'site_id'           => (int) $relation_id,
				'status'            => 'pending',
				'priority'          => 'normal',
				'blog_id'           => get_current_blog_id(),
			'target_type'       => sanitize_text_field( $relation['target_site_type'] ?? '' ),
			'target_identifier' => sanitize_text_field( $relation['target_site_id'] ?? '' ),
			'template'          => sanitize_text_field( $relation['template'] ?? '' ),
			'object_type'       => 'post_type',
			'subtype'           => $post_type,
			'object_id'         => $post_id,
			'lang_from'         => sanitize_text_field( $relation['source_lang'] ?? '' ),
			'lang_to'           => sanitize_text_field( $relation['target_lang'] ?? '' ),
			'site_mode'         => '',
			'payload'           => wp_json_encode( $task_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'meta'              => wp_json_encode( array(
				'source_post_id'   => $post_id,
				'source_post_type' => $post_type,
				'triggered_by'     => 'hook',
			) ),
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $data );

		if ( false === $result ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Failed to create sync task',
				array(
					'post_id'     => $post_id,
					'relation_id' => $relation_id,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		$task_id = $wpdb->insert_id;

		wptsall_log_info(
			'tasks-monitoring',
			'Sync task created',
			array(
				'task_id'     => $task_id,
				'post_id'     => $post_id,
				'post_type'   => $post_type,
				'relation_id' => $relation_id,
			)
		);

		return $task_id;
	}

	/**
	 * Create a sync task for a taxonomy term.
	 *
	 * @deprecated 1.2.0 Content tasks are now created via the translation-callback
	 *             endpoint in Client_Data_REST_Controller.
	 *
	 * Creates a pending task for term content that the Client can pick up
	 * for translation. Supports deduplication like create_sync_task().
	 *
	 * @since 1.1.0
	 *
	 * @param int    $term_id     Term ID.
	 * @param string $taxonomy    Taxonomy name.
	 * @param int    $relation_id Site relation ID.
	 * @return int|false Task ID on success, false on failure or duplicate.
	 */
	public static function create_term_sync_task( $term_id, $taxonomy, $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Cannot create term sync task: term not found',
				array(
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				)
			);
			return false;
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Cannot create term sync task: relation not found',
				array( 'relation_id' => $relation_id )
			);
			return false;
		}

		// Check for duplicate pending task (same term + relation + pending status).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE type = 'sync' AND relation_id = %d AND object_id = %d AND object_type = 'taxonomy' AND status = 'pending' LIMIT 1",
				$table,
				$relation_id,
				$term_id
			)
		);

		if ( $existing ) {
			wptsall_log_info(
				'tasks-monitoring',
				'Term sync task already exists (dedup)',
				array(
					'existing_task_id' => $existing,
					'term_id'          => $term_id,
					'taxonomy'         => $taxonomy,
					'relation_id'      => $relation_id,
				)
			);
			return (int) $existing;
		}

		// Build base fields.
		$fields = array(
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
			'slug'        => (string) $term->slug,
		);

		// B1: Use unified get_merged_config_for_relation() with data_type='term'.
		$config    = Translation_Rule_Service::get_merged_config_for_relation( $relation_id, $taxonomy, 'term' );
		$rule_caps = $config['field_capabilities'] ?? array();

		if ( empty( $rule_caps ) ) {
			wptsall_log_warning(
				'tasks-monitoring',
				'No translation rule found for taxonomy, task created with empty field_capabilities',
				array(
					'relation_id' => $relation_id,
					'taxonomy'    => $taxonomy,
					'term_id'     => $term_id,
				)
			);
		}

		// Extract additional translatable fields from merged config (term_meta).
		if ( ! empty( $rule_caps ) && is_array( $rule_caps ) ) {
			foreach ( $rule_caps as $field_name => $field_config ) {
				$capability = is_array( $field_config )
					? ( $field_config['capability'] ?? ( $field_config['type'] ?? 'skip' ) )
					: (string) $field_config;
				if ( 'translate' === $capability && ! in_array( $field_name, array( 'name', 'description', 'slug' ), true ) ) {
					$meta_value = get_term_meta( $term_id, $field_name, true );
					if ( ! empty( $meta_value ) && is_string( $meta_value ) ) {
						$fields[ $field_name ] = $meta_value;
					}
				}
			}
		}

		$job_id = function_exists( 'wptsall_generate_task_job_id' )
			? wptsall_generate_task_job_id( 'monitoring_' . $relation_id . '_term_' . $term_id )
			: sanitize_key( 'job_monitoring_' . $relation_id . '_term_' . $term_id );

		$task_seed = array(
			'job_id'            => $job_id,
			'blog_id'           => get_current_blog_id(),
			'target_type'       => sanitize_text_field( $relation['target_site_type'] ?? '' ),
			'target_identifier' => sanitize_text_field( $relation['target_site_id'] ?? '' ),
			'template'          => sanitize_text_field( $relation['template'] ?? '' ),
			'object_type'       => 'taxonomy',
			'subtype'           => $taxonomy,
			'object_id'         => $term_id,
			'lang_from'         => sanitize_text_field( $relation['source_lang'] ?? '' ),
			'lang_to'           => sanitize_text_field( $relation['target_lang'] ?? '' ),
			'task_type'         => 'text',
			'fields'            => $fields,
		);
		if ( function_exists( 'wptsall_prepare_task_for_client_payload' ) ) {
			$task_seed = wptsall_prepare_task_for_client_payload( $task_seed, 'text' );
		}

		$task_payload = array(
			'term_id'            => $term_id,
			'taxonomy'           => $taxonomy,
			'rule_status'        => $rule_found ? 'complete' : 'missing',
			'field_capabilities' => $rule_caps,
			'job_id'             => sanitize_text_field( (string) ( $task_seed['job_id'] ?? $job_id ) ),
			'business_line'      => sanitize_key( (string) ( $task_seed['business_line'] ?? 'term_content' ) ),
			'object_ref'         => is_array( $task_seed['object_ref'] ?? null ) ? $task_seed['object_ref'] : array(),
			'task_type'          => sanitize_key( (string) ( $task_seed['task_type'] ?? 'text' ) ),
			'subtasks'           => is_array( $task_seed['subtasks'] ?? null ) ? $task_seed['subtasks'] : array(),
			'content_items'      => is_array( $task_seed['content_items'] ?? null ) ? $task_seed['content_items'] : array(),
			'fields'             => is_array( $task_seed['fields'] ?? null ) ? $task_seed['fields'] : array(),
		);

			$data = array(
				'type'              => 'sync',
				'relation_id'       => $relation_id,
				'site_id'           => (int) $relation_id,
				'status'            => 'pending',
				'priority'          => 'normal',
				'blog_id'           => get_current_blog_id(),
			'target_type'       => sanitize_text_field( $relation['target_site_type'] ?? '' ),
			'target_identifier' => sanitize_text_field( $relation['target_site_id'] ?? '' ),
			'template'          => sanitize_text_field( $relation['template'] ?? '' ),
			'object_type'       => 'taxonomy',
			'subtype'           => $taxonomy,
			'object_id'         => $term_id,
			'lang_from'         => sanitize_text_field( $relation['source_lang'] ?? '' ),
			'lang_to'           => sanitize_text_field( $relation['target_lang'] ?? '' ),
			'site_mode'         => '',
			'payload'           => wp_json_encode( $task_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'meta'              => wp_json_encode( array(
				'source_term_id' => $term_id,
				'source_taxonomy' => $taxonomy,
				'triggered_by'    => 'hook',
			) ),
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $data );

		if ( false === $result ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Failed to create term sync task',
				array(
					'term_id'     => $term_id,
					'taxonomy'    => $taxonomy,
					'relation_id' => $relation_id,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		$task_id = $wpdb->insert_id;

		wptsall_log_info(
			'tasks-monitoring',
			'Term sync task created',
			array(
				'task_id'     => $task_id,
				'term_id'     => $term_id,
				'taxonomy'    => $taxonomy,
				'relation_id' => $relation_id,
			)
		);

		return $task_id;
	}

	/**
	 * Get monitoring task by ID
	 *
	 * @param int $task_id Task ID.
	 * @return array|null Task data or null.
	 */
	public static function get( $task_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$task = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$table,
				$task_id
			),
			ARRAY_A
		);

		if ( ! $task ) {
			return null;
		}

		// Parse JSON fields.
		$task['progress']  = json_decode( $task['progress'] ?? '{}', true ) ?: array();
		$task['meta']      = json_decode( $task['meta'] ?? '{}', true ) ?: array();
		$task['model_ids'] = json_decode( $task['model_ids'] ?? '[]', true ) ?: array();

		return $task;
	}

	/**
	 * Count total syncable items for a relation
	 *
	 * @param int $relation_id Relation ID.
	 * @return int Total item count.
	 */
	public static function count_total_items( $relation_id ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return 0;
		}

		$models     = Relation_Model_Service::get_models_by_relation( $relation_id );
		$total      = 0;
		$source_site_id = $relation['source_site_id'] ?? 1;

		// Switch to source site.
		$current_blog = get_current_blog_id();
		$switched     = false;
		if ( is_multisite() && $current_blog !== (int) $source_site_id ) {
			switch_to_blog( (int) $source_site_id );
			$switched = true;
		}

		try {
			foreach ( $models as $model ) {
				// Parse model objects.
				$post_types = is_string( $model['post_types'] ?? '' )
					? json_decode( $model['post_types'], true )
					: ( $model['post_types'] ?? array() );
				$taxonomies = is_string( $model['taxonomies'] ?? '' )
					? json_decode( $model['taxonomies'], true )
					: ( $model['taxonomies'] ?? array() );

				// Count posts.
				if ( ! empty( $post_types ) && is_array( $post_types ) ) {
					foreach ( $post_types as $pt ) {
						$pt_name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
						if ( $pt_name && post_type_exists( $pt_name ) ) {
							$counts = wp_count_posts( $pt_name );
							$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
						}
					}
				}

				// Count terms.
				if ( ! empty( $taxonomies ) && is_array( $taxonomies ) ) {
					foreach ( $taxonomies as $tax ) {
						$tax_name = is_array( $tax ) ? ( $tax['name'] ?? '' ) : $tax;
						if ( $tax_name && taxonomy_exists( $tax_name ) ) {
							$total += (int) wp_count_terms( array( 'taxonomy' => $tax_name, 'hide_empty' => false ) );
						}
					}
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return $total;
	}

	/**
	 * Calculate initial progress for a relation
	 *
	 * @param array $relation Relation data.
	 * @param array $models   Associated models.
	 * @return array Progress data.
	 */
	private static function calculate_initial_progress( $relation, $models ) {
		$progress = array(
			'total_items'     => 0,
			'synced_items'    => 0,
			'pending_items'   => 0,
			'failed_items'    => 0,
			'percentage'      => 0,
			'models_progress' => array(),
			'last_sync_at'    => null,
		);

		foreach ( $models as $model ) {
			$model_id = $model['id'];
			$progress['models_progress'][ $model_id ] = array(
				'plugin_name' => $model['plugin_name'] ?? '',
				'total'       => 0,
				'synced'      => 0,
				'pending'     => 0,
			);
		}

		return $progress;
	}

	/**
	 * Update monitoring task progress
	 *
	 * @param int   $task_id  Task ID.
	 * @param array $progress Progress data.
	 * @return bool Success.
	 */
	public static function update_progress( $task_id, $progress ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// Calculate percentage.
		if ( $progress['total_items'] > 0 ) {
			$progress['percentage'] = round(
				( $progress['synced_items'] / $progress['total_items'] ) * 100,
				1
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'progress'   => wp_json_encode( $progress ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $task_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update model_ids column for a monitoring task.
	 *
	 * Note: processing loads models dynamically from relation_models; model_ids is primarily for UI/debugging.
	 *
	 * @since 1.0.2
	 * @param int   $task_id   Task ID.
	 * @param array $model_ids Model IDs.
	 * @return bool Success.
	 */
	public static function update_model_ids( $task_id, $model_ids ) {
		$task_id = (int) $task_id;
		if ( $task_id <= 0 ) {
			return false;
		}

		if ( ! is_array( $model_ids ) ) {
			$model_ids = array();
		}

		$model_ids = array_values( array_unique( array_map( 'intval', $model_ids ) ) );

		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'model_ids'  => wp_json_encode( $model_ids ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $task_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update task meta
	 *
	 * @since 0.9.2 ISS-TSK-030 / ISS-TST-041
	 * @param int   $task_id Task ID.
	 * @param array $meta    Meta data to merge.
	 * @return bool Success.
	 */
	public static function update_meta( $task_id, $meta ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// Get current meta and merge.
		$task = self::get( $task_id );
		if ( ! $task ) {
			return false;
		}

		$current_meta = $task['meta'] ?? array();
		$merged_meta  = array_merge( $current_meta, $meta );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'meta'       => wp_json_encode( $merged_meta ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $task_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update task status
	 *
	 * @param int    $task_id     Task ID.
	 * @param string $status      New status.
	 * @param string $status_note Optional status note.
	 * @return bool Success.
	 */
	public static function update_status( $task_id, $status, $status_note = '' ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// Get current status for logging.
		$old_status = '';
		$task = self::get( $task_id );
		if ( $task ) {
			$old_status = $task['status'] ?? '';
		}

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $status_note ) {
			$data['status_note'] = $status_note;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => $task_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wptsall_log_info(
				'tasks-monitoring',
				'Task status updated',
				array(
					'task_id'     => $task_id,
					'old_status'  => $old_status,
					'new_status'  => $status,
					'status_note' => $status_note,
				)
			);

			// Log to task event log table.
			if ( function_exists( 'wptsall_log_task_event' ) ) {
				wptsall_log_task_event( $task_id, $old_status, $status, $status_note );
			}
		}

		return false !== $result;
	}

	/**
	 * Record a check/run of the monitoring task
	 *
	 * @param int   $task_id         Task ID.
	 * @param int   $items_processed Number of items processed.
	 * @param int   $items_synced    Number of items successfully synced.
	 * @param int   $interval        Interval in seconds for next check.
	 * @return bool Success.
	 */
	public static function record_check( $task_id, $items_processed, $items_synced, $interval = 300 ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		$now        = current_time( 'mysql' );
		$next_check = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $interval );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'last_check_at' => $now,
				'next_check_at' => $next_check,
				'updated_at'    => $now,
			),
			array( 'id' => $task_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wptsall_log_debug(
				'tasks-monitoring',
				'Task check recorded',
				array(
					'task_id'    => $task_id,
					'processed'  => $items_processed,
					'synced'     => $items_synced,
					'next_check' => $next_check,
				)
			);
		}

		return false !== $result;
	}

	/**
	 * Get all active monitoring tasks
	 *
	 * @param int $limit Maximum number to return.
	 * @return array Array of tasks.
	 */
	public static function get_active_tasks( $limit = 50 ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$tasks = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, blog_id, target_blog, target_type, target_identifier, site_id, relation_id, template, type, model_ids, priority, object_type, subtype, object_id, lang_from, lang_to, site_mode, status, status_note, retry_count, retry_at, progress, meta, last_check_at, next_check_at, created_at, updated_at FROM %i
				WHERE type = %s
				AND status = %s
				AND (next_check_at IS NULL OR next_check_at <= NOW())
				ORDER BY priority DESC, next_check_at ASC
				LIMIT %d',
				$table,
				self::TASK_TYPE,
				self::STATUS_ACTIVE,
				$limit
			),
			ARRAY_A
		);

		// Parse JSON fields.
		foreach ( $tasks as &$task ) {
			$task['progress']  = json_decode( $task['progress'] ?? '{}', true ) ?: array();
			$task['meta']      = json_decode( $task['meta'] ?? '{}', true ) ?: array();
			$task['model_ids'] = json_decode( $task['model_ids'] ?? '[]', true ) ?: array();
		}

		return $tasks;
	}

	/**
	 * Get monitoring tasks due for check
	 *
	 * @return array Tasks due for check.
	 */
	public static function get_due_tasks() {
		return self::get_active_tasks( 10 );
	}

	/**
	 * Get monitoring status for a relation
	 *
	 * @param int $relation_id Relation ID.
	 * @return array Status data.
	 */
	public static function get_status( $relation_id ) {
		$task = self::get_by_relation( $relation_id );

		if ( ! $task ) {
			return array(
				'has_task'    => false,
				'status'      => 'none',
				'progress'    => null,
				'last_check'  => null,
				'next_check'  => null,
			);
		}

		return array(
			'has_task'    => true,
			'task_id'     => $task['id'],
			'status'      => $task['status'],
			'progress'    => $task['progress'],
			'last_check'  => $task['last_check_at'],
			'next_check'  => $task['next_check_at'],
			'models'      => count( $task['model_ids'] ?? array() ),
		);
	}

	/**
	 * Start monitoring for a relation
	 *
	 * @param int $relation_id Relation ID.
	 * @return array Result.
	 */
	public static function start_monitoring( $relation_id ) {
		$task = self::get_or_create( $relation_id );

		if ( ! $task ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create monitoring task',
			);
		}

		if ( self::STATUS_ACTIVE !== $task['status'] ) {
			self::update_status( $task['id'], self::STATUS_ACTIVE );
		}

		return array(
			'success' => true,
			'task_id' => $task['id'],
			'message' => 'Monitoring started',
		);
	}

	/**
	 * Stop monitoring for a relation
	 *
	 * @param int $relation_id Relation ID.
	 * @return array Result.
	 */
	public static function stop_monitoring( $relation_id ) {
		$task = self::get_by_relation( $relation_id );

		if ( ! $task ) {
			return array(
				'success' => false,
				'error'   => 'No monitoring task found',
			);
		}

		self::update_status( $task['id'], self::STATUS_PAUSED, 'Monitoring stopped by user' );

		return array(
			'success' => true,
			'task_id' => $task['id'],
			'message' => 'Monitoring stopped',
		);
	}

	/**
	 * Delete monitoring task for a relation
	 *
	 * @param int $relation_id Relation ID.
	 * @return bool Success.
	 */
	public static function delete_by_relation( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array(
				'type'        => self::TASK_TYPE,
				'relation_id' => $relation_id,
			),
			array( '%s', '%d' )
		);

		if ( false !== $result && $result > 0 ) {
			wptsall_log_info(
				'tasks-monitoring',
				'Monitoring task deleted',
				array( 'relation_id' => $relation_id )
			);
			return true;
		}

		return false;
	}

	// ========================================
	// i18n scan automation
	// ========================================

	/**
	 * Lock timeout for i18n scan in seconds.
	 *
	 * @var int
	 */
	const I18N_SCAN_LOCK_TIMEOUT = 300;

	/**
	 * Maximum retry count for i18n scan.
	 *
	 * @var int
	 */
	const I18N_SCAN_MAX_RETRIES = 3;

	/**
	 * Run i18n scan for a site relation.
	 *
	 * Performs a two-phase scan:
	 * - Phase 1: Copy existing templates from other relations OR scan .pot files
	 * - Phase 2: Create pending i18n tasks from template entries
	 *
	 * @since 1.2.0
	 * @param int  $relation_id Site relation ID.
	 * @param bool $force       Force re-scan even if completed.
	 * @return array Result with 'success', 'status', and optional details.
	 */
	public static function run_i18n_scan( $relation_id, $force = false ) {
		$relation_id = (int) $relation_id;

		// Validate relation exists and is virtual with i18n enabled.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation || 'virtual' !== ( $relation['target_site_type'] ?? '' ) ) {
			return array( 'success' => false, 'status' => 'invalid', 'error' => 'Relation not found or not virtual' );
		}

		$config = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		if ( ! $config['translate_plugin_i18n'] && ! $config['translate_theme_i18n'] ) {
			return array( 'success' => false, 'status' => 'disabled', 'error' => 'i18n not enabled for this relation' );
		}

		// Get or create monitoring task.
		$task = self::get_or_create( $relation_id );
		if ( ! $task ) {
			return array( 'success' => false, 'status' => 'error', 'error' => 'Cannot get monitoring task' );
		}

		$scan = $task['meta']['i18n_scan'] ?? self::get_default_i18n_scan();

		// Already completed and not forced.
		if ( 'completed' === $scan['status'] && ! $force ) {
			return array( 'success' => true, 'status' => 'completed' );
		}

		// Lock check.
		if ( self::is_i18n_scan_locked( $scan['lock_token'] ?? null ) ) {
			return array( 'success' => false, 'status' => 'in_progress' );
		}

		// Max retries check.
		if ( ( $scan['retry_count'] ?? 0 ) >= self::I18N_SCAN_MAX_RETRIES && ! $force ) {
			return array( 'success' => false, 'status' => 'max_retries', 'error' => $scan['error'] ?? '' );
		}

		// Acquire lock.
		$lock_token = uniqid( '', true ) . '_' . time();
		$scan['lock_token'] = $lock_token;
		$scan['started_at'] = $scan['started_at'] ?: current_time( 'mysql' );
		self::update_meta( (int) $task['id'], array( 'i18n_scan' => $scan ) );

		try {
			// Determine starting phase.
			$start_phase = 1;
			if ( 'phase1_done' === $scan['status'] ) {
				$start_phase = 2;
			}

			// Phase 1: Scan or copy templates.
			if ( $start_phase <= 1 ) {
				$scan['status'] = 'phase1_scanning';
				self::update_meta( (int) $task['id'], array( 'i18n_scan' => $scan ) );

				// Try cross-relation dedup first.
				$copied_domains = self::copy_existing_templates( $relation_id );

				// Scan .pot files for any remaining domains.
				$scanner_class = '\\WPTSALL\\Templates\\Scanners\\Language_Pack_Scanner';
				if ( class_exists( $scanner_class ) ) {
					$scan_result = $scanner_class::scan_relation( $relation_id, 'all' );
					$scan['phase1_templates'] = count( $scan_result['templates'] ?? array() );
				}

				$scan['status'] = 'phase1_done';
				self::update_meta( (int) $task['id'], array( 'i18n_scan' => $scan ) );

				wptsall_log_info( 'tasks-monitoring', 'i18n scan Phase 1 done', array(
					'relation_id'     => $relation_id,
					'copied_domains'  => $copied_domains,
					'templates_found' => $scan['phase1_templates'],
				) );
			}

			// Phase 2: Create i18n tasks from template entries.
			$scan['status'] = 'phase2_creating';
			self::update_meta( (int) $task['id'], array( 'i18n_scan' => $scan ) );

			$discover_result = self::discover_i18n_tasks( $relation_id );
			$scan['phase2_tasks'] = $discover_result['created'] ?? 0;

			// Complete.
			$scan['status']       = 'completed';
			$scan['completed_at'] = current_time( 'mysql' );
			$scan['lock_token']   = null;
			$scan['error']        = null;
			self::update_meta( (int) $task['id'], array( 'i18n_scan' => $scan ) );

			wptsall_log_info( 'tasks-monitoring', 'i18n scan completed', array(
				'relation_id' => $relation_id,
				'templates'   => $scan['phase1_templates'],
				'tasks'       => $scan['phase2_tasks'],
			) );

			return array(
				'success'   => true,
				'status'    => 'completed',
				'templates' => $scan['phase1_templates'],
				'tasks'     => $scan['phase2_tasks'],
			);

		} catch ( \Exception $e ) {
			$scan['status']      = 'error';
			$scan['error']       = $e->getMessage();
			$scan['retry_count'] = ( $scan['retry_count'] ?? 0 ) + 1;
			$scan['lock_token']  = null;
			self::update_meta( (int) $task['id'], array( 'i18n_scan' => $scan ) );

			wptsall_log_error( 'tasks-monitoring', 'i18n scan error', array(
				'relation_id' => $relation_id,
				'error'       => $e->getMessage(),
				'retry_count' => $scan['retry_count'],
			) );

			return array( 'success' => false, 'status' => 'error', 'error' => $e->getMessage() );
		}
	}

	/**
	 * Get i18n scan status for a relation.
	 *
	 * @since 1.2.0
	 * @param int $relation_id Site relation ID.
	 * @return array Scan status structure.
	 */
	public static function get_i18n_scan_status( $relation_id ) {
		$task = self::get_by_relation( (int) $relation_id );
		if ( ! $task ) {
			return self::get_default_i18n_scan();
		}

		return $task['meta']['i18n_scan'] ?? self::get_default_i18n_scan();
	}

	/**
	 * Check if an i18n scan lock is active.
	 *
	 * @since 1.2.0
	 * @param string|null $lock_token Lock token.
	 * @return bool True if locked.
	 */
	private static function is_i18n_scan_locked( $lock_token ) {
		if ( empty( $lock_token ) ) {
			return false;
		}

		// Extract timestamp from token (format: uniqid_timestamp).
		$parts = explode( '_', $lock_token );
		$timestamp = (int) end( $parts );

		return ( time() - $timestamp ) < self::I18N_SCAN_LOCK_TIMEOUT;
	}

	/**
	 * Get default i18n scan status structure.
	 *
	 * @since 1.2.0
	 * @return array Default scan status.
	 */
	private static function get_default_i18n_scan() {
		return array(
			'status'           => 'not_started',
			'started_at'       => null,
			'completed_at'     => null,
			'phase1_templates' => 0,
			'phase2_tasks'     => 0,
			'error'            => null,
			'retry_count'      => 0,
			'lock_token'       => null,
		);
	}

	/**
	 * Copy existing template entries from other relations that share the same plugins/themes.
	 *
	 * This avoids re-scanning .pot files for plugins/themes that have already been scanned
	 * for a different relation. Only used during automatic scan (run_i18n_scan), not manual scan.
	 *
	 * @since 1.2.0
	 * @param int $relation_id Site relation ID.
	 * @return array List of copied text_domain values.
	 */
	private static function copy_existing_templates( $relation_id ) {
		global $wpdb;

		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );

		// Get templates that already exist for OTHER relations with entries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$existing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.source_type, t.text_domain, t.id AS template_id, t.source_name, t.source_version,
				        (SELECT COUNT(*) FROM %i e WHERE e.template_id = t.id) AS entry_count
				 FROM %i t
				 WHERE t.relation_id != %d
				 HAVING entry_count > 0
				 GROUP BY t.source_type, t.text_domain",
				$entries_table,
				$templates_table,
				$relation_id
			),
			ARRAY_A
		);

		if ( empty( $existing ) ) {
			return array();
		}

		$copied_domains = array();

		foreach ( $existing as $source ) {
			$source_type = $source['source_type'];
			$text_domain = $source['text_domain'];
			$source_template_id = (int) $source['template_id'];

			// Check if this relation already has this template.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
			$already_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE relation_id = %d AND source_type = %s AND text_domain = %s LIMIT 1",
					$templates_table,
					$relation_id,
					$source_type,
					$text_domain
				)
			);

			if ( $already_exists ) {
				continue;
			}

			// Create template for this relation via Template_Service.
			$template_id = \WPTSALL\Templates\Services\Template_Service::get_or_create( array(
				'relation_id'    => $relation_id,
				'source_type'    => $source_type,
				'text_domain'    => $text_domain,
				'source_name'    => $source['source_name'] ?? $text_domain,
				'source_version' => $source['source_version'] ?? '',
			) );

			if ( ! $template_id ) {
				continue;
			}

			// Bulk copy entries from source template.
			$now = current_time( 'mysql' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
			$copied = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (template_id, msgid, msgid_plural, msgctxt, reference, status, source, created_at, updated_at)
					 SELECT %d, msgid, msgid_plural, msgctxt, reference, 'pending', 'scan', %s, %s
					 FROM %i
					 WHERE template_id = %d",
					$entries_table,
					$template_id,
					$now,
					$now,
					$entries_table,
					$source_template_id
				)
			);

			if ( $copied ) {
				$copied_domains[] = $text_domain;

				wptsall_log_info( 'tasks-monitoring', 'Copied template entries from existing relation', array(
					'relation_id'          => $relation_id,
					'text_domain'          => $text_domain,
					'source_template_id'   => $source_template_id,
					'target_template_id'   => $template_id,
					'entries_copied'       => $copied,
				) );
			}
		}

		return $copied_domains;
	}

	// ========================================
	// i18n task discovery and creation
	// ========================================

	/**
	 * Discover and create i18n translation tasks for a relation.
	 *
	 * Checks the relation's template config for translate_plugin_i18n / translate_theme_i18n,
	 * then queries pending template entries grouped by template, and creates tasks.
	 *
	 * @since 1.1.0
	 * @param int $relation_id Site relation ID.
	 * @return array Summary of created tasks.
	 */
	public static function discover_i18n_tasks( $relation_id ) {
		$config = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );

		if ( ! $config['translate_plugin_i18n'] && ! $config['translate_theme_i18n'] ) {
			return array(
				'skipped'  => true,
				'reason'   => 'i18n translation not enabled for this relation',
				'created'  => 0,
			);
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation || 'active' !== ( $relation['status'] ?? '' ) ) {
			return array(
				'skipped' => true,
				'reason'  => 'relation inactive or not found',
				'created' => 0,
			);
		}

		// Get templates for this relation.
		$templates = \WPTSALL\Templates\Services\Template_Service::get_by_relation( $relation_id );
		if ( empty( $templates ) ) {
			return array(
				'skipped' => true,
				'reason'  => 'no templates found for relation',
				'created' => 0,
			);
		}

		$created_count = 0;
		$batch_limit   = 500; // Max entries per task.

		foreach ( $templates as $template ) {
			$source_type = $template['source_type'] ?? '';

			// Check if this source type is enabled.
			if ( 'plugin' === $source_type && ! $config['translate_plugin_i18n'] ) {
				continue;
			}
			if ( 'theme' === $source_type && ! $config['translate_theme_i18n'] ) {
				continue;
			}
			// Skip content-type templates (those are handled by content sync).
			if ( 'content' === $source_type ) {
				continue;
			}
			// Config-type: enabled when either plugin or theme i18n is enabled.
			if ( 'config' === $source_type && ! $config['translate_plugin_i18n'] && ! $config['translate_theme_i18n'] ) {
				continue;
			}

			$template_id = (int) $template['id'];

			// Get pending entries for this template.
			$pending = \WPTSALL\Templates\Services\Template_Entry_Service::get_by_template(
				$template_id,
				array(
					'status'   => 'pending',
					'per_page' => $batch_limit,
				)
			);

			if ( empty( $pending['items'] ) ) {
				continue;
			}

			// Check if an i18n task already exists for this template that is not yet completed.
			if ( self::has_pending_i18n_task( $relation_id, $template_id ) ) {
				continue;
			}

			// Build subtasks from pending entries.
			$subtasks = array();
			foreach ( $pending['items'] as $entry ) {
				$subtask = array(
					'id'          => 'entry_' . $entry['id'],
					'key'         => 'entry_' . $entry['id'],
					'type'        => 'text',
					'source_text' => $entry['msgid'],
					'field_key'   => 'msgid_' . $entry['id'],
					'entry_id'    => (int) $entry['id'],
				);
				if ( ! empty( $entry['msgctxt'] ) ) {
					$subtask['context'] = $entry['msgctxt'];
				}
				if ( ! empty( $entry['msgid_plural'] ) ) {
					$subtask['plural'] = $entry['msgid_plural'];
				}
				$subtasks[] = $subtask;
			}

			if ( 'config' === $source_type ) {
				$business_line = 'config_i18n';
			} elseif ( 'plugin' === $source_type ) {
				$business_line = 'plugin_i18n';
			} else {
				$business_line = 'theme_i18n';
			}

			$task_id = self::create_i18n_task( $relation, $template, $business_line, $subtasks );
			if ( $task_id ) {
				$created_count++;

				wptsall_log_info(
					'tasks-monitoring',
					'i18n task created',
					array(
						'task_id'       => $task_id,
						'relation_id'   => $relation_id,
						'template_id'   => $template_id,
						'business_line' => $business_line,
						'subtasks'      => count( $subtasks ),
					)
				);
			}
		}

		return array(
			'skipped' => false,
			'created' => $created_count,
		);
	}

	/**
	 * Check if a pending i18n task already exists for a template.
	 *
	 * @since 1.1.0
	 * @param int $relation_id Site relation ID.
	 * @param int $template_id Template ID.
	 * @return bool
	 */
	private static function has_pending_i18n_task( $relation_id, $template_id ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND object_type = %s AND object_id = %d AND status IN (%s, %s, %s) LIMIT 1',
				$table,
				$relation_id,
				'language_pack',
				$template_id,
				'pending',
				'processing',
				'active'
			)
		);

		return (bool) $exists;
	}

	/**
	 * Preserve completed i18n tasks for a template.
	 *
	 * @deprecated 1.2.3 Completed/failed tasks are historical evidence. The
	 *             legacy hard unique key was replaced by app-level open-task
	 *             dedupe, so cleanup is no longer required.
	 *
	 * @since 1.1.0
	 * @param int $relation_id Site relation ID.
	 * @param int $template_id Template ID (stored as object_id).
	 * @return int Number of deleted rows.
	 */
	private static function cleanup_completed_i18n_tasks( $relation_id, $template_id ) {
		return 0;
	}

	/**
	 * Create a single i18n translation task.
	 *
	 * @since 1.1.0
	 * @param array  $relation      Site relation data.
	 * @param array  $template      Template data.
	 * @param string $business_line Business line: plugin_i18n or theme_i18n.
	 * @param array  $subtasks      Subtask items.
	 * @return int|false Task ID or false on failure.
	 */
	private static function create_i18n_task( $relation, $template, $business_line, $subtasks ) {
		global $wpdb;
		$table = wptsall_table( 'tasks' );
		$now   = current_time( 'mysql' );

		$relation_id = (int) $relation['id'];
		$template_id = (int) $template['id'];
		$source_type = $template['source_type'] ?? 'plugin';
		$text_domain = $template['text_domain'] ?? '';

		$payload = wp_json_encode( array(
			'business_line' => $business_line,
			'task_type'     => 'text',
			'template_id'   => $template_id,
			'text_domain'   => $text_domain,
			'subtasks'      => $subtasks,
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$data = array(
			'blog_id'           => (int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
			'target_blog'       => 0,
			'target_type'       => sanitize_text_field( $relation['target_site_type'] ?? '' ),
			'target_identifier' => sanitize_text_field( $relation['target_site_id'] ?? '' ),
			'site_id'           => $relation_id,
			'relation_id'       => $relation_id,
			'template'          => sanitize_text_field( $text_domain ),
			'type'              => 'sync',
			'model_ids'         => '',
			'priority'          => 'normal',
			'object_type'       => 'language_pack',
			'subtype'           => sanitize_key( $source_type ),
			'object_id'         => $template_id,
			'lang_from'         => sanitize_text_field( $relation['source_lang'] ?? '' ),
			'lang_to'           => sanitize_text_field( $relation['target_lang'] ?? '' ),
			'site_mode'         => '',
			'status'            => 'pending',
			'status_note'       => '',
			'retry_count'       => 0,
			'progress'          => '',
			'meta'              => wp_json_encode( array(
				'source'      => 'i18n_bridge',
				'template_id' => $template_id,
			) ),
			'payload'           => $payload,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$data,
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			wptsall_log_error(
				'tasks-monitoring',
				'Failed to create i18n task',
				array(
					'relation_id' => $relation_id,
					'template_id' => $template_id,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		return $wpdb->insert_id;
	}
}
