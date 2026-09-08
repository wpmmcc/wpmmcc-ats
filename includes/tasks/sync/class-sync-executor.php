<?php
/**
 * WPTSALL Sync Executor
 *
 * Integrates Field_Processor with sync tasks to execute field-level synchronization.
 *
 * @package WPTSALL\Tasks\Sync
 * @since 0.5.0
 */
namespace WPTSALL\Tasks\Sync;

use WPTSALL\Models\Services\Field_Processor;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Tasks\Services\Direct_DB_Service;
use WPTSALL\Tasks\Services\Origin_Visit_Service;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Sync Executor — Sync engine.
 *
 * Responsible for writing content to the target site (virtual or WP multisite).
 *
 * Two live entry points:
 *   - execute_task()             — Path A local discover+translate+write (uses Field_Processor).
 *                                  @deprecated product path — Path A is a legacy placeholder;
 *                                  product acceptance is Path B (client claim/callback) only.
 *   - execute_translation_sync() — Path B client translation write-back (authoritative product path).
 *
 * Uses Field_Processor for field-level processing (translate, sync, mapping, compute).
 * Handles both WordPress multisite and virtual site targets.
 */
class Sync_Executor {


	private static function update_translation_result_status( $translation_result_id, $status ) {
		global $wpdb;
		$results_table = wptsall_table( 'translation_results' );
		$data          = array( 'status' => $status );
		$formats       = array( '%s' );

		if ( 'synced' === $status ) {
			$data['synced_at'] = current_time( 'mysql', true );
			$formats[]         = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$update_result = $wpdb->update(
			$results_table,
			$data,
			array( 'id' => $translation_result_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $update_result ) {
			wptsall_log_warning( 'tasks-sync', 'Failed to update translation_results status', array(
				'translation_result_id' => $translation_result_id,
				'status'                => $status,
				'db_error'              => $wpdb->last_error,
			) );
		}
	}

	/**
	 * Execute sync task for an object.
	 *
	 * @param array $task Task data from wp_wptsall_tasks table.
	 * @return array|WP_Error Sync result or error.
	 */
	public static function execute_task( $task ) {
		global $wpdb;

		// If task ID is provided, fetch task data.
		if ( is_numeric( $task ) ) {
			$task_id = (int) $task;
			$table   = wptsall_table( 'tasks' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$task = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $task_id ),
				ARRAY_A
			);

			if ( ! $task ) {
				return new \WP_Error( 'task_not_found', __( 'Task not found', 'wpmmcc-ats' ), array( 'task_id' => $task_id ) );
			}
		}

		$task_id     = $task['id'];
		$object_type = $task['object_type'];
		$subtype     = $task['subtype'];
		$object_id   = $task['object_id'];
		$source_blog = $task['blog_id'];
		$target_blog = $task['target_blog'];
		$target_type = $task['target_type'] ?? 'wp';
		$template    = $task['template'];
		$lang_from   = $task['lang_from'] ?? 'en_US';
		$lang_to     = $task['lang_to'] ?? 'zh_CN';

		// Extract relation_id from task record or payload (CONTRACTS.md 4.2: Required).
		$payload     = isset( $task['payload'] ) ? json_decode( $task['payload'], true ) : array();
		$relation_id = (int) ( $task['relation_id'] ?? $task['site_id'] ?? $payload['relation_id'] ?? 0 );

		wptsall_log_info( 'tasks-sync', 'Starting sync task execution', array(
			'task_id'     => $task_id,
			'object_type' => $object_type,
			'subtype'     => $subtype,
			'object_id'   => $object_id,
			'relation_id' => $relation_id,
		) );

		// Validate relation_id (CONTRACTS.md 4.3: missing relation_id must not execute).
		if ( empty( $relation_id ) ) {
			wptsall_log_error( 'tasks-sync', 'Missing relation_id in task — refusing to execute (CONTRACTS 4.3)', array(
				'task_id'  => $task_id,
				'template' => $template,
			) );
			self::update_task_status( $task_id, 'failed', array( 'error' => 'Missing relation_id in task payload — cannot determine site relation' ) );
			return new \WP_Error(
				'missing_relation_id',
				'Task is missing relation_id — cannot execute sync without a valid site relation',
				array( 'task_id' => $task_id )
			);
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			self::update_task_status( $task_id, 'failed', array( 'error' => 'Site relation not found', 'relation_id' => $relation_id ) );
			return new \WP_Error(
				'relation_not_found',
				'Site relation not found',
				array( 'task_id' => $task_id, 'relation_id' => $relation_id )
			);
		}
		if ( 'active' !== ( $relation['status'] ?? '' ) ) {
			self::update_task_status( $task_id, 'cancelled', array( 'error' => 'relation_inactive', 'relation_id' => $relation_id ) );
			return new \WP_Error(
				'relation_inactive',
				'Site relation is inactive',
				array( 'task_id' => $task_id, 'relation_id' => $relation_id )
			);
		}

		// 1. Get translation rule for this object type
		// First, check if rule ID is in payload
		$rule_id = isset( $payload['translation_rule'] ) ? $payload['translation_rule'] : null;

		if ( $rule_id ) {
			// Get rule by ID from database
			$rule_table = wptsall_table( 'translation_rules' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rule = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $rule_table, $rule_id ),
				ARRAY_A
			);
		} else {
			// Scope rule lookup to models associated with the task's relation.
			$rule = null;
			$normalized_object_type = in_array( (string) $object_type, array( 'term', 'taxonomy' ), true )
				? 'taxonomy'
				: 'post_type';
			$models = Relation_Model_Service::get_models_by_relation( $relation_id );
			if ( ! empty( $models ) ) {
				foreach ( $models as $model ) {
					if ( 'taxonomy' === $normalized_object_type ) {
						$rule = Translation_Rule_Service::get_rule_by_taxonomy( $subtype, (int) $model['id'] );
					} else {
						$rule = Translation_Rule_Service::get_rule_by_post_type( (int) $model['id'], $subtype );
					}
					if ( $rule ) {
						break;
					}
				}
			}
		}

		if ( ! $rule ) {
			wptsall_log_error( 'tasks-sync', 'No translation rule found', array(
				'template'    => $template,
				'object_type' => $object_type,
				'subtype'     => $subtype,
				'rule_id'     => $rule_id,
			) );
			self::update_task_status( $task_id, 'failed', array( 'error' => sprintf( 'No translation rule found for %s/%s', $object_type, $subtype ) ) );
			return new \WP_Error(
				'no_rule',
				sprintf( 'No translation rule found for %s/%s', $object_type, $subtype )
			);
		}

		// 2. Get source data
		$source_data = self::get_source_data( $object_type, $subtype, $object_id, $source_blog, $relation_id );

		if ( is_wp_error( $source_data ) ) {
			self::update_task_status( $task_id, 'failed', array( 'error' => $source_data->get_error_message() ) );
			return $source_data;
		}

		// 2b. Get media_handling from site relation by relation_id (primary key lookup).
		$media_handling  = 'copy'; // Default.
		$relations_table = wptsall_table( 'site_relations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT media_handling FROM %i WHERE id = %d',
				$relations_table,
				$relation_id
			),
			ARRAY_A
		);
		if ( $relation && ! empty( $relation['media_handling'] ) ) {
			$media_handling = $relation['media_handling'];
		}

		// 3. Initialize Field_Processor with options.
		$field_processor = new Field_Processor(
			$rule,
			$source_blog,
			$target_type === 'virtual' ? $task['target_identifier'] : $target_blog,
			$lang_to,
			array(
				'media_handling' => $media_handling,
				'relation_id'   => (int) $relation_id,
			)
		);

		// 4. Process fields
		$processed_data = $field_processor->process_fields( $source_data );

		if ( empty( $processed_data ) ) {
			wptsall_log_error( 'tasks-sync', 'Field processing returned empty data', array(
				'task_id' => $task_id,
			) );
			self::update_task_status( $task_id, 'failed', array( 'error' => 'Field processing returned empty data' ) );
			return new \WP_Error( 'processing_failed', 'Field processing returned empty data' );
		}

		// 5. Apply translation markers to translate fields
		$processed_data = self::apply_translation_markers( $processed_data, $field_processor, $lang_from, $lang_to );

		// 6. Get processing summary
		$summary = $field_processor->get_processing_summary();

		wptsall_log_info( 'tasks-sync', 'Field processing complete', array(
			'task_id' => $task_id,
			'summary' => $summary,
		) );

		// 7. Create/update target object (pass media_handling through task array for sync_post).
		$task['_media_handling'] = $media_handling;
		$result = self::sync_to_target( $object_type, $subtype, $processed_data, $target_blog, $target_type, $task );

		if ( is_wp_error( $result ) ) {
			self::update_task_status( $task_id, 'failed', array( 'error' => $result->get_error_message() ) );
			return $result;
		}

		// 7b. Dispatch non-text items through write-back adapters (if any).
		$non_text_items  = $payload['non_text_items'] ?? array();
		$write_back_summary = array();
			if ( ! empty( $non_text_items ) && is_array( $non_text_items ) ) {
				$dispatch_context = array(
					'relation_id'      => (int) $relation_id,
					'task_id'          => $task_id,
					'source_blog'      => $source_blog,
					'source_site_id'   => (int) $source_blog,
					'target_blog'      => $target_blog,
					'target_type'      => $target_type,
					'target_site_id'   => (string) ( $task['target_identifier'] ?? $target_blog ),
					'target_identifier' => (string) ( $task['target_identifier'] ?? $target_blog ),
					'lang_to'          => $lang_to,
				);

			$write_back_summary = Write_Back_Dispatcher::dispatch_batch( $non_text_items, $dispatch_context );

			wptsall_log_info( 'tasks-sync', 'Non-text write-back dispatch completed', array(
				'task_id' => $task_id,
				'total'   => $write_back_summary['total'] ?? 0,
				'applied' => $write_back_summary['applied'] ?? 0,
				'queued'  => $write_back_summary['queued'] ?? 0,
			) );
		}

		// 8. Update task status
		$status_meta = array(
			'summary'         => $summary,
			'target_id'       => $result['target_id'],
			'fields_synced'   => count( $processed_data ),
			'translations'    => $result['translations'] ?? 0,
		);
		if ( ! empty( $write_back_summary ) ) {
			$status_meta['write_back'] = array(
				'total'   => $write_back_summary['total'] ?? 0,
				'applied' => $write_back_summary['applied'] ?? 0,
				'queued'  => $write_back_summary['queued'] ?? 0,
				'failed'  => $write_back_summary['failed'] ?? 0,
			);
		}
		self::update_task_status( $task_id, 'completed', $status_meta );

		return array(
			'success'        => true,
			'task_id'        => $task_id,
			'target_id'      => $result['target_id'],
			'summary'        => $summary,
			'write_back'     => ! empty( $write_back_summary ) ? $write_back_summary : null,
		);
	}

	/**
	 * Execute sync from a translation result (Path B: Client → WP write-back).
	 *
	 * Reads translated fields from the translation_results table and writes to target.
	 * Called after the Rust client submits a translation callback.
	 *
	 * @since 1.1.0
	 *
	 * @param int $sync_task_id Sync task ID (from wp_wptsall_tasks).
	 * @return array|\WP_Error Result or error.
	 */
	public static function execute_translation_sync( $sync_task_id ) {
		global $wpdb;

		// 1. Read the sync task.
		$task_table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$task = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $task_table, $sync_task_id ),
			ARRAY_A
		);

		if ( ! $task ) {
			return new \WP_Error( 'task_not_found', 'Sync task not found', array( 'task_id' => $sync_task_id ) );
		}

		$payload = ! empty( $task['payload'] ) ? json_decode( $task['payload'], true ) : array();
		$translation_result_id = $payload['translation_result_id'] ?? 0;
		$field_results         = is_array( $payload['field_results'] ?? null ) ? $payload['field_results'] : array();
		$claim_owner_hash      = strtolower( trim( (string) ( $payload['claim_owner_hash'] ?? '' ) ) );
		$structured_failures   = self::count_structured_field_failures( $field_results );

		if ( ! $translation_result_id ) {
			self::update_task_status( $sync_task_id, 'failed', array( 'error' => 'No translation_result_id in task payload' ) );
			return new \WP_Error( 'no_result_id', 'No translation_result_id in task payload', array( 'task_id' => $sync_task_id ) );
		}

		// 2. Read translated fields from translation_results table.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tr = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $results_table, $translation_result_id ),
			ARRAY_A
		);

		if ( ! $tr ) {
			self::update_task_status( $sync_task_id, 'failed', array( 'error' => 'Translation result not found', 'id' => $translation_result_id ) );
			return new \WP_Error( 'result_not_found', 'Translation result not found', array( 'id' => $translation_result_id ) );
		}

		$relation_id       = (int) ( $tr['relation_id'] ?? 0 );
		$object_type       = $tr['object_type'] ?? 'post_type';
		$object_id         = (int) ( $tr['object_id'] ?? 0 );
		$translated_fields = json_decode( $tr['translated_fields'] ?? '{}', true ) ?: array();
		$translated_meta   = json_decode( $tr['translated_meta'] ?? '{}', true ) ?: array();
		$media_mappings    = json_decode( $tr['media_mappings'] ?? '[]', true ) ?: array();
		$source_lang       = $tr['source_lang'] ?? 'en_US';
		$target_lang       = $tr['target_lang'] ?? 'zh_CN';

		// Mark task as in-progress for crash recovery.
		self::update_task_status( $sync_task_id, 'processing', array( 'status_note' => 'sync_started' ) );

		wptsall_log_info( 'tasks-sync', 'Starting translation sync', array(
			'sync_task_id'         => $sync_task_id,
			'translation_result_id' => $translation_result_id,
			'relation_id'          => $relation_id,
			'object_type'          => $object_type,
			'object_id'            => $object_id,
			'structured_failures'  => $structured_failures,
		) );
		if ( $structured_failures > 0 ) {
			wptsall_log_warning(
				'tasks-sync',
				'Structured format fields failed on client before sync',
				array(
					'sync_task_id'        => $sync_task_id,
					'translation_result_id' => $translation_result_id,
					'structured_failures' => $structured_failures,
				)
			);
		}

		// Route language_pack to dedicated i18n sync (no source data / target write needed).
		if ( 'language_pack' === $object_type ) {
			return self::execute_i18n_sync( $sync_task_id, $translation_result_id, $translated_fields, $relation_id );
		}

		// 3. Get site relation info.
		$relations_table = wptsall_table( 'site_relations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $relations_table, $relation_id ),
			ARRAY_A
		);

		if ( ! $relation ) {
			self::update_task_status( $sync_task_id, 'failed', array( 'error' => 'Site relation not found', 'relation_id' => $relation_id ) );
			self::update_translation_result_status( $translation_result_id, 'failed' );
			return new \WP_Error( 'relation_not_found', 'Site relation not found', array( 'relation_id' => $relation_id ) );
		}
		if ( 'active' !== ( $relation['status'] ?? '' ) ) {
			self::update_task_status( $sync_task_id, 'cancelled', array( 'error' => 'relation_inactive', 'relation_id' => $relation_id ) );
			self::update_translation_result_status( $translation_result_id, 'cancelled' );
			return new \WP_Error( 'relation_inactive', 'Site relation is inactive', array( 'relation_id' => $relation_id ) );
		}

			$source_blog = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
			$target_blog = $relation['target_site_id'] ?? null;
			$target_type = $relation['target_site_type'] ?? 'wp';
			$target_id   = $relation['target_site_id'] ?? $target_blog;
			$subtype     = $task['subtype'] ?? 'post';

			if ( class_exists( '\\WPTSALL\\Tasks\\Services\\Origin_Visit_Service' ) && in_array( $object_type, array( 'post_type', 'taxonomy' ), true ) ) {
				$origin = 'taxonomy' === $object_type
					? Origin_Visit_Service::get_term_origin( $object_id, $object_type, $subtype, 'wp', (string) $source_blog )
					: Origin_Visit_Service::get_post_origin( $object_id, $object_type, $subtype, 'wp', (string) $source_blog );
			if ( Origin_Visit_Service::should_skip_delivery( $origin, (string) $target_type, (string) $target_id ) ) {
				self::update_task_status( $sync_task_id, 'cancelled', array( 'error' => 'origin_backflow_guard', 'relation_id' => $relation_id ) );
				self::update_translation_result_status( $translation_result_id, 'cancelled' );
				return new \WP_Error( 'origin_backflow_guard', 'Origin delivery skipped by backflow guard', array( 'relation_id' => $relation_id ) );
			}
		}

		// 4. Read original object from source site.
			$source_data = self::get_source_data( $object_type, $subtype, $object_id, $source_blog, $relation_id );

		if ( is_wp_error( $source_data ) ) {
			self::update_task_status( $sync_task_id, 'failed', array( 'error' => $source_data->get_error_message() ) );
			self::update_translation_result_status( $translation_result_id, 'failed' );
			return $source_data;
		}

		// 5. Merge translated fields with source data — skip wp_manual owned fields (CAS).
		// Reject stale job snapshots before write-back (ISS P-A10).
		if ( class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
			$snap_rev = (string) ( $tr['source_revision'] ?? '' );
			$snap_pol = (string) ( $tr['policy_version'] ?? '' );
			$fresh    = \WPTSALL\Core\Job_Snapshot::assert_fresh( $object_type, $object_id, $snap_rev, $snap_pol, 'option' === $object_type ? $subtype : null );
			if ( is_wp_error( $fresh ) ) {
				self::update_task_status(
					$sync_task_id,
					'failed',
					array(
						'error' => $fresh->get_error_code(),
						'msg'   => $fresh->get_error_message(),
					)
				);
				self::update_translation_result_status( $translation_result_id, 'failed' );
				return $fresh;
			}
		}

		// Attachments have a binary lifecycle that is fundamentally different
		// from a normal post insert.  This branch intentionally follows the
		// immutable job-snapshot assertion above: media callbacks receive the
		// same stale-job protection as ordinary post callbacks.
		if ( 'post_type' === $object_type && 'attachment' === $subtype ) {
			return self::execute_attachment_translation_sync(
				$sync_task_id,
				$translation_result_id,
				$relation_id,
				$source_blog,
				$target_blog,
				$target_type,
				(string) $target_id,
				$object_id,
				$source_data,
				$translated_fields,
				$translated_meta,
				$media_mappings
			);
		}
		$field_formats = self::get_field_content_formats_for_sync(
			$relation_id,
			$task['subtype'] ?? 'post',
			$object_type
		);
		$target_existing = in_array( $object_type, array( 'post_type', 'taxonomy' ), true )
			? self::check_target_exists( $relation_id, $object_type, $object_id, $target_type, $target_id, $target_blog )
			: false;
		$ownership_conflicts = 0;
		if ( class_exists( '\\WPTSALL\\Sync\\Services\\Field_Ownership_Service' ) ) {
			$filtered = \WPTSALL\Sync\Services\Field_Ownership_Service::filter_machine_writeback(
				(int) $target_existing,
				(string) $object_type,
				$translated_fields,
				$translated_meta,
				(int) $relation_id,
				(int) $object_id,
				(string) ( $tr['client_task_id'] ?? '' )
			);
			$translated_fields   = $filtered['fields'];
			$translated_meta     = $filtered['meta'];
			$ownership_conflicts = (int) $filtered['conflicts'];
		}
		$merged_data   = self::merge_translation_result( $source_data, $translated_fields, $translated_meta, $object_type, $field_formats );
		// The merge starts from source data so non-translated fields remain
		// complete. For fields explicitly owned by a human on an existing target,
		// restore the target value before writing; otherwise a filtered machine
		// field would be replaced by the source-language fallback.
		if ( $target_existing && class_exists( '\WPTSALL\Sync\Services\Field_Ownership_Service' ) ) {
			$merged_data = self::preserve_manual_owned_fields(
				$merged_data,
				(string) $object_type,
				(int) $target_existing,
				(string) $target_type,
				$target_blog
			);
		}

		// 6. Check if target already exists — update instead of skip.
		$already_exists = $target_existing;
		if ( $already_exists ) {
			wptsall_log_info( 'tasks-sync', 'Updating existing target (target already exists)', array(
				'sync_task_id' => $sync_task_id,
				'relation_id'  => $relation_id,
				'object_id'    => $object_id,
				'target_id'    => $already_exists,
			) );
		}

		// 7. Write to target.
		$task_context = array(
			'object_id'         => $object_id,
			'subtype'           => $subtype,
			'blog_id'           => $source_blog,
			'target_blog'       => $target_blog,
			'target_identifier' => $target_id,
			'relation_id'       => $relation_id,
			'site_id'           => $relation_id,
			'template'          => $task['template'] ?? '',
			'lang_from'         => $source_lang,
			'lang_to'           => $target_lang,
			'target_lang'       => $target_lang,
		);

		$sync_result = self::sync_to_target( $object_type, $subtype, $merged_data, $target_blog, $target_type, $task_context );

		if ( is_wp_error( $sync_result ) ) {
			if ( 'option' === $object_type ) {
				self::update_option_sync_state( $relation, $subtype, 'release', $claim_owner_hash );
			}
			self::update_task_status( $sync_task_id, 'failed', array( 'error' => $sync_result->get_error_message() ) );
			self::update_translation_result_status( $translation_result_id, 'failed' );
			return $sync_result;
		}

		if ( class_exists( '\\WPTSALL\\Sync\\Services\\Field_Ownership_Service' )
			&& ! empty( $sync_result['target_id'] ) ) {
			$type_key = ( 'taxonomy' === $object_type ) ? 'term' : 'post';
			\WPTSALL\Sync\Services\Field_Ownership_Service::stamp_machine_applied(
				$type_key,
				(int) $sync_result['target_id'],
				$translated_fields
			);
		}

		if ( 'option' === $object_type ) {
			self::update_option_sync_state( $relation, $subtype, 'synced', $claim_owner_hash );
		}

		// 8. Process media_mappings through Write_Back_Dispatcher.
		$write_back_summary = array();
		if ( ! empty( $media_mappings ) ) {
			$dispatch_context = array(
				'relation_id'      => $relation_id,
				'task_id'          => $sync_task_id,
				'source_blog'      => $source_blog,
				'source_site_id'   => (int) $source_blog,
				'target_blog'      => $target_blog,
				'target_type'      => $target_type,
				'target_site_id'   => (string) $target_id,
				'target_identifier' => (string) $target_id,
				'lang_to'          => $target_lang,
			);
			$write_back_summary = Write_Back_Dispatcher::dispatch_translation_media( $media_mappings, $dispatch_context );
		}

		// 9. Update task status.
		$status_meta = array(
			'target_id'             => $sync_result['target_id'],
			'translations'          => $sync_result['translations'] ?? 0,
			'fields_synced'         => count( $merged_data ),
			'ownership_conflicts'   => (int) ( $ownership_conflicts ?? 0 ),
		);
		if ( $structured_failures > 0 ) {
			$status_meta['structured_failures'] = $structured_failures;
		}
		if ( ! empty( $write_back_summary ) ) {
			$status_meta['write_back'] = array(
				'total'   => $write_back_summary['total'] ?? 0,
				'applied' => $write_back_summary['applied'] ?? 0,
				'queued'  => $write_back_summary['queued'] ?? 0,
				'failed'  => $write_back_summary['failed'] ?? 0,
			);
		}
		$write_back_failed = (int) ( $write_back_summary['failed'] ?? 0 );
		$write_back_queued = (int) ( $write_back_summary['queued'] ?? 0 );
		$final_status      = ( $write_back_failed > 0 || $write_back_queued > 0 ) ? 'partial' : 'completed';
		self::update_task_status( $sync_task_id, $final_status, $status_meta );
		self::update_translation_result_status( $translation_result_id, 'completed' === $final_status ? 'synced' : 'partial' );
		if ( class_exists( '\\WPTSALL\\Tasks\\Services\\Origin_Visit_Service' ) && ! empty( $sync_result['target_id'] ) && in_array( $object_type, array( 'post_type', 'taxonomy' ), true ) ) {
			$origin = 'taxonomy' === $object_type
				? Origin_Visit_Service::get_term_origin( $object_id, $object_type, $subtype, 'wp', (string) $source_blog )
				: Origin_Visit_Service::get_post_origin( $object_id, $object_type, $subtype, 'wp', (string) $source_blog );
			if ( 'taxonomy' === $object_type ) {
				Origin_Visit_Service::ensure_term_origin_meta( (int) $sync_result['target_id'], $origin );
			} else {
				Origin_Visit_Service::ensure_post_origin_meta( (int) $sync_result['target_id'], $origin );
			}
			Origin_Visit_Service::record_visit( $origin, (string) $target_type, (string) $target_id, (int) $sync_result['target_id'] );
		}

		wptsall_log_info( 'tasks-sync', 'Translation sync completed', array(
			'sync_task_id'         => $sync_task_id,
			'translation_result_id' => $translation_result_id,
			'target_id'            => $sync_result['target_id'],
		) );

		return array(
			'success'    => true,
			'task_id'    => $sync_task_id,
			'target_id'  => $sync_result['target_id'],
			'write_back' => ! empty( $write_back_summary ) ? $write_back_summary : null,
		);
	}

	/**
	 * Write back an attachment binary mapping and its metadata without creating
	 * a duplicate attachment post.
	 *
	 * @param int    $sync_task_id Translation sync task ID.
	 * @param int    $translation_result_id Translation result ID.
	 * @param int    $relation_id Site relation ID.
	 * @param int    $source_blog Source site ID.
	 * @param int|string|null $target_blog Target blog ID.
	 * @param string $target_type Target type.
	 * @param string $target_id Target identifier.
	 * @param int    $source_attachment_id Source attachment ID.
	 * @param array  $source_data Complete source attachment data.
	 * @param array  $translated_fields Translated post fields.
	 * @param array  $translated_meta Translated attachment metadata.
	 * @param array  $media_mappings Client media mapping result.
	 * @return array|\WP_Error
	 */
	private static function execute_attachment_translation_sync(
		$sync_task_id,
		$translation_result_id,
		$relation_id,
		$source_blog,
		$target_blog,
		$target_type,
		$target_id,
		$source_attachment_id,
		array $source_data,
		array $translated_fields,
		array $translated_meta,
		array $media_mappings
	) {
		if ( empty( $media_mappings ) ) {
			self::update_task_status( $sync_task_id, 'failed', array( 'error' => 'attachment_binary_mapping_missing' ) );
			self::update_translation_result_status( $translation_result_id, 'failed' );
			return new \WP_Error( 'attachment_binary_mapping_missing', 'Attachment callback did not include a binary mapping.' );
		}

		$context = array(
			'relation_id'       => (int) $relation_id,
			'task_id'           => (int) $sync_task_id,
			'source_blog'       => (int) $source_blog,
			'source_site_id'    => (int) $source_blog,
			'target_blog'       => $target_blog,
			'target_type'       => (string) $target_type,
			'target_site_id'    => (string) $target_id,
			'target_identifier' => (string) $target_id,
		);
		$write_back = Write_Back_Dispatcher::dispatch_translation_media( $media_mappings, $context );
		$target_attachment_id = 0;
		foreach ( (array) ( $write_back['items'] ?? array() ) as $item_result ) {
			if ( ! empty( $item_result['success'] ) && ! empty( $item_result['target_id'] ) ) {
				$target_attachment_id = (int) $item_result['target_id'];
				break;
			}
		}

		if ( $target_attachment_id <= 0 ) {
			self::update_task_status(
				$sync_task_id,
				'partial',
				array( 'error' => 'attachment_binary_writeback_failed', 'write_back' => $write_back )
			);
			self::update_translation_result_status( $translation_result_id, 'partial' );
			return new \WP_Error( 'attachment_binary_writeback_failed', 'Attachment binary could not be applied to the target.', array( 'write_back' => $write_back ) );
		}

		$source_post = is_array( $source_data['post'] ?? null ) ? $source_data['post'] : array();
		$source_meta = is_array( $source_data['meta'] ?? null ) ? $source_data['meta'] : array();
		$post_update = array( 'ID' => $target_attachment_id );
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			$value = array_key_exists( $field, $translated_fields )
				? $translated_fields[ $field ]
				: ( $source_post[ $field ] ?? null );
			if ( null !== $value ) {
				$post_update[ $field ] = 'post_content' === $field ? wp_kses_post( $value ) : sanitize_text_field( $value );
			}
		}
		$alt_text = array_key_exists( '_wp_attachment_image_alt', $translated_meta )
			? $translated_meta['_wp_attachment_image_alt']
			: ( $source_meta['_wp_attachment_image_alt'] ?? null );
		if ( is_array( $alt_text ) ) {
			$alt_text = $alt_text[0] ?? '';
		}

		$switched = false;
		if ( 'wp' === $target_type && is_multisite() && (int) $target_blog > 0 && (int) $target_blog !== get_current_blog_id() ) {
			switch_to_blog( (int) $target_blog );
			$switched = true;
		}
		try {
			$attachment = get_post( $target_attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				throw new \RuntimeException( 'Uploaded attachment is not available on the target site.' );
			}
			$result = wptsall_with_sync_suppression(
				static function () use ( $post_update, $target_attachment_id, $alt_text ) {
					$updated = wp_update_post( $post_update, true );
					if ( is_wp_error( $updated ) ) {
						return $updated;
					}
					if ( null !== $alt_text ) {
						update_post_meta( $target_attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
					}
					return true;
				}
			);
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			if ( $switched ) {
				restore_current_blog();
			}
			self::update_task_status( $sync_task_id, 'failed', array( 'error' => $e->getMessage(), 'write_back' => $write_back ) );
			self::update_translation_result_status( $translation_result_id, 'failed' );
			return new \WP_Error( 'attachment_metadata_writeback_failed', $e->getMessage() );
		}
		if ( $switched ) {
			restore_current_blog();
		}

		self::update_task_status(
			$sync_task_id,
			'completed',
			array( 'target_id' => $target_attachment_id, 'translations' => count( $translated_fields ) + count( $translated_meta ), 'write_back' => $write_back )
		);
		self::update_translation_result_status( $translation_result_id, 'synced' );

		return array(
			'success'    => true,
			'task_id'    => (int) $sync_task_id,
			'target_id'  => $target_attachment_id,
			'write_back' => $write_back,
		);
	}

	/**
	 * Merge translation result fields with source data.
	 *
	 * Translated fields override source fields; untranslated fields are copied as-is.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $source_data       Source data from get_source_data().
	 * @param array  $translated_fields Translated field values.
	 * @param array  $translated_meta   Translated meta values.
	 * @param string $object_type       Object type (post_type/taxonomy).
	 * @param array  $field_formats     Field name → content_format from translation rules.
	 * @return array Merged data ready for sync_to_target().
	 */
	private static function merge_translation_result( $source_data, $translated_fields, $translated_meta, $object_type, $field_formats = array() ) {
		$merged = array();

		$content_fields = array( 'post_content', 'description' );
		$text_fields    = array( 'post_title', 'post_excerpt', 'name' );

		$html_formats       = array( 'rich_html' );
		$structured_formats = array( 'serialized_php', 'json_structured' );
		$slug_formats       = array( 'slug' );
		$media_ref_formats  = array( 'media_ref' );

		if ( 'post_type' === $object_type && isset( $source_data['post'] ) ) {
			// Start from source post fields.
			foreach ( $source_data['post'] as $key => $value ) {
				$merged[ $key ] = $value;
			}
			// Override with translated fields (sanitized).
			foreach ( $translated_fields as $key => $value ) {
				// Callback payload may include null placeholders for non-translated
				// compute fields (e.g. post_name). Null/empty slug-like values must
				// not overwrite source fields, otherwise permalink slugs are lost.
				if ( null === $value ) {
					continue;
				}
				if ( in_array( $key, array( 'post_name', 'slug', 'guid' ), true ) && '' === trim( (string) $value ) ) {
					continue;
				}

				$sanitized = self::sanitize_translated_value(
					$key,
					$value,
					$field_formats[ $key ] ?? '',
					$content_fields,
					$text_fields,
					$html_formats,
					$structured_formats,
					$slug_formats,
					$media_ref_formats
				);
				if ( null === $sanitized ) {
					continue;
				}
				$merged[ $key ] = $sanitized;
			}
		} elseif ( 'taxonomy' === $object_type && isset( $source_data['term'] ) ) {
			foreach ( $source_data['term'] as $key => $value ) {
				$merged[ $key ] = $value;
			}
			foreach ( $translated_fields as $key => $value ) {
				if ( null === $value ) {
					continue;
				}
				if ( in_array( $key, array( 'post_name', 'slug' ), true ) && '' === trim( (string) $value ) ) {
					continue;
				}

				$sanitized = self::sanitize_translated_value(
					$key,
					$value,
					$field_formats[ $key ] ?? '',
					$content_fields,
					$text_fields,
					$html_formats,
					$structured_formats,
					$slug_formats,
					$media_ref_formats
				);
				if ( null === $sanitized ) {
					continue;
				}
				$merged[ $key ] = $sanitized;
			}
		}

		// Merge meta: source meta first, then translated meta overwrites.
		if ( ! empty( $source_data['meta'] ) ) {
			$merged['meta'] = $source_data['meta'];
		}
		if ( ! empty( $translated_meta ) ) {
			if ( ! isset( $merged['meta'] ) ) {
				$merged['meta'] = array();
			}
			$blocked_prefixes  = array( '_wptsall_', '_edit_', '_oembed_' );
			$wp_translatable   = array( '_wp_attachment_image_alt' );
			foreach ( $translated_meta as $key => $value ) {
				if ( null === $value ) {
					continue;
				}

				$blocked = false;
				foreach ( $blocked_prefixes as $prefix ) {
					if ( str_starts_with( $key, $prefix ) ) {
						$blocked = true;
						break;
					}
				}
				// Block _wp_* keys except explicitly translatable ones.
				if ( ! $blocked && str_starts_with( $key, '_wp_' ) && ! in_array( $key, $wp_translatable, true ) ) {
					$blocked = true;
				}
				if ( $blocked ) {
					wptsall_log_warning( 'tasks-sync', 'Blocked meta key in translated_meta', array( 'key' => $key ) );
					continue;
				}
				if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' )
					&& \WPTSALL\Core\Smart_Field_Classifier::is_code_like_field_name( (string) $key ) ) {
					wptsall_log_warning( 'tasks-sync', 'Blocked code-like meta key in translated_meta', array( 'key' => $key ) );
					continue;
				}

				$format = $field_formats[ $key ] ?? '';
				if ( in_array( $key, array( 'description', '_genesis_custom_body_class' ), true )
					|| str_contains( $key, 'content' ) || str_contains( $key, 'description' ) ) {
					$sanitized = self::sanitize_translated_value(
						$key,
						$value,
						$format ?: 'rich_html',
						$content_fields,
						$text_fields,
						$html_formats,
						$structured_formats,
						$slug_formats,
						$media_ref_formats
					);
				} else {
					$sanitized = self::sanitize_translated_value(
						$key,
						$value,
						$format,
						$content_fields,
						$text_fields,
						$html_formats,
						$structured_formats,
						$slug_formats,
						$media_ref_formats
					);
				}
				if ( null === $sanitized ) {
					continue;
				}
				$merged['meta'][ $key ] = $sanitized;
			}
		} elseif ( 'option' === $object_type && isset( $source_data['option'] ) ) {
			$merged = array(
				'option_name'  => (string) ( $source_data['option']['option_name'] ?? '' ),
				'option_value' => $source_data['option']['option_value'] ?? null,
			);

			$source_option_value = $merged['option_value'];
			if ( array_key_exists( 'option_value', $translated_fields ) ) {
				$raw_translated = $translated_fields['option_value'];
				$option_format  = sanitize_key( (string) ( $field_formats['option_value'] ?? '' ) );
				if ( 'code' === $option_format ) {
					wptsall_log_warning( 'tasks-sync', 'Blocked code-format option_value translation', array() );
				} else {
				$decoded        = json_decode( (string) $raw_translated, true );
				if ( is_array( $source_option_value ) && JSON_ERROR_NONE === json_last_error() ) {
					$merged['option_value'] = $decoded;
				} else {
					$merged['option_value'] = self::sanitize_translated_value(
						'option_value',
						$raw_translated,
						$field_formats['option_value'] ?? '',
						$content_fields,
						$text_fields,
						$html_formats,
						$structured_formats,
						$slug_formats,
						$media_ref_formats
					);
				}
				}
			} elseif ( is_array( $source_option_value ) ) {
				foreach ( $translated_fields as $key => $value ) {
					if ( 'option_name' === $key || ! array_key_exists( $key, $source_option_value ) ) {
						continue;
					}
					$source_option_value[ $key ] = self::sanitize_translated_value(
						$key,
						$value,
						$field_formats[ $key ] ?? '',
						$content_fields,
						$text_fields,
						$html_formats,
						$structured_formats,
						$slug_formats,
						$media_ref_formats
					);
				}
				$merged['option_value'] = $source_option_value;
			}
		}

		return $merged;
	}

	/**
	 * Keep target values for fields claimed by a human editor.
	 *
	 * @param array    $merged_data   Source + translated data.
	 * @param string   $object_type   post_type|taxonomy.
	 * @param int      $target_id     Existing target object ID.
	 * @param string   $target_type   wp|virtual.
	 * @param int|null $target_blog   Target blog for multisite writes.
	 * @return array
	 */
	private static function preserve_manual_owned_fields( array $merged_data, $object_type, $target_id, $target_type, $target_blog ) {
		if ( $target_id <= 0 ) {
			return $merged_data;
		}
		$switched = false;
		if ( 'wp' === $target_type && is_multisite() && $target_blog ) {
			switch_to_blog( (int) $target_blog );
			$switched = true;
		}
		$type_key = 'taxonomy' === $object_type ? 'term' : 'post';
		$owners  = \WPTSALL\Sync\Services\Field_Ownership_Service::get_owners( $type_key, $target_id );
		if ( 'taxonomy' === $object_type ) {
			$term = get_term( $target_id );
			if ( $term && ! is_wp_error( $term ) ) {
				foreach ( array( 'name', 'slug', 'description', 'parent' ) as $field ) {
					if ( isset( $owners[ $field ] ) && 'wp_manual' === ( $owners[ $field ]['value_origin'] ?? '' ) ) {
						$merged_data[ $field ] = $term->$field;
					}
				}
			}
		} else {
			$post = get_post( $target_id );
			if ( $post ) {
				foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ) as $field ) {
					if ( isset( $owners[ $field ] ) && 'wp_manual' === ( $owners[ $field ]['value_origin'] ?? '' ) ) {
						$merged_data[ $field ] = $post->$field;
					}
				}
			}
		}
		foreach ( $owners as $path => $owner ) {
			if ( ! is_array( $owner ) || 'wp_manual' !== ( $owner['value_origin'] ?? '' ) || 0 !== strpos( (string) $path, 'meta:' ) ) {
				continue;
			}
			$key = substr( (string) $path, 5 );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $merged_data['meta'] ) || ! is_array( $merged_data['meta'] ) ) {
				$merged_data['meta'] = array();
			}
			$value = get_post_meta( $target_id, $key, false );
			$merged_data['meta'][ $key ] = $value;
		}
		if ( $switched ) {
			restore_current_blog();
		}
		return $merged_data;
	}

	/**
	 * Sanitize translated value according to content_format and field context.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_key           Field key.
	 * @param mixed  $value               Field value.
	 * @param string $format              content_format.
	 * @param array  $content_fields      Rich content field names.
	 * @param array  $text_fields         Plain text field names.
	 * @param array  $html_formats        HTML-like content_format list.
	 * @param array  $structured_formats  Structured content_format list.
	 * @param array  $slug_formats        Slug content_format list.
	 * @param array  $media_ref_formats   Media reference content_format list.
	 * @return mixed|null Null when the field must not be written back.
	 */
	private static function sanitize_translated_value(
		$key,
		$value,
		$format,
		array $content_fields,
		array $text_fields,
		array $html_formats,
		array $structured_formats,
		array $slug_formats,
		array $media_ref_formats
	) {
		$format = sanitize_key( (string) $format );

		if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
			$format = \WPTSALL\Core\Smart_Field_Classifier::normalize_content_format( $format, '' );
			if ( \WPTSALL\Core\Smart_Field_Classifier::is_code_like_field_name( (string) $key ) ) {
				return null;
			}
		}

		if ( 'code' === $format ) {
			return null;
		}

		if ( in_array( $key, $content_fields, true ) ) {
			return wp_kses_post( $value );
		}
		if ( in_array( $key, $text_fields, true ) ) {
			return sanitize_text_field( $value );
		}
		if ( in_array( $key, array( 'slug', 'post_name' ), true ) ) {
			return sanitize_title( (string) $value );
		}
		if ( in_array( $format, $html_formats, true ) ) {
			return wp_kses_post( $value );
		}
		if ( in_array( $format, $structured_formats, true ) ) {
			if ( 'serialized_php' === $format ) {
				return is_serialized( $value ) ? $value : sanitize_text_field( $value );
			}
			return ( null !== json_decode( (string) $value ) ) ? $value : sanitize_text_field( $value );
		}
		if ( in_array( $format, $slug_formats, true ) ) {
			return sanitize_title( (string) $value );
		}
		if ( in_array( $format, $media_ref_formats, true ) ) {
			if ( is_numeric( $value ) ) {
				return (string) absint( $value );
			}
			return esc_url_raw( (string) $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Strip translation marker wrappers before generating functional slugs.
	 *
	 * Mock/vendor translations may wrap slug-like fields with markers such as
	 * 【en_US】...【/en_US】. WordPress percent-encodes these markers in post_name,
	 * producing user-visible URLs with %e3%80%90... fragments. Functional slugs
	 * must be derived from marker-free text instead.
	 *
	 * @param mixed $value Raw slug/name/title candidate.
	 * @return string Marker-stripped text.
	 */
	private static function strip_translation_markers_for_slug( $value ) {
		$text = rawurldecode( (string) $value );

		if ( function_exists( 'wptsall_unwrap_marker' ) ) {
			$text = (string) wptsall_unwrap_marker( $text );
		}

		$text = (string) preg_replace(
			'/\xE3\x80\x90[\/-]?[a-z]{2,5}(?:_[a-z]{2,5})?\xE3\x80\x91/iu',
			'',
			$text
		);

		return trim( $text );
	}

	/**
	 * Detect marker artifacts that survived slug sanitization.
	 *
	 * @param string $slug Sanitized slug.
	 * @return bool
	 */
	private static function slug_has_marker_artifacts( string $slug ): bool {
		$lower = strtolower( $slug );
		return str_contains( $lower, '%e3%80%90' )
			|| str_contains( $lower, '%e3%80%91' )
			|| str_contains( $slug, '【' )
			|| str_contains( $slug, '】' );
	}

	/**
	 * Build a safe non-empty post slug for write-back.
	 *
	 * Priority:
	 * 1) explicit post_name from merged data
	 * 2) slug generated from marker-stripped title
	 * 3) deterministic fallback with source object id
	 *
	 * @param mixed $raw_slug   Candidate slug from merged data.
	 * @param mixed $raw_title  Candidate title for fallback slug derivation.
	 * @param int   $source_id  Source object id for deterministic fallback.
	 * @return string
	 */
	private static function normalize_post_slug( $raw_slug, $raw_title, $source_id = 0 ) {
		$slug = sanitize_title( self::strip_translation_markers_for_slug( $raw_slug ) );
		if ( '' !== $slug && ! self::slug_has_marker_artifacts( $slug ) ) {
			return $slug;
		}

		$title = self::strip_translation_markers_for_slug( $raw_title );
		$slug = sanitize_title( $title );
		if ( '' !== $slug && ! self::slug_has_marker_artifacts( $slug ) ) {
			return $slug;
		}

		if ( $source_id > 0 ) {
			return 'item-' . absint( $source_id );
		}

		return 'item-' . time();
	}

	/**
	 * Build a unique post slug that stays URL-safe on the target site.
	 *
	 * @param mixed  $raw_slug         Candidate translated slug.
	 * @param mixed  $raw_title        Candidate title fallback.
	 * @param string $post_type        Target post type.
	 * @param string $post_status      Target post status.
	 * @param int    $post_parent      Target post parent.
	 * @param int    $existing_post_id Existing target post id when updating.
	 * @param int    $source_id        Source object id for deterministic fallback.
	 * @return string
	 */
	private static function ensure_unique_post_slug( $raw_slug, $raw_title, $post_type, $post_status = 'draft', $post_parent = 0, $existing_post_id = 0, $source_id = 0 ) {
		$candidate = self::normalize_post_slug( $raw_slug, $raw_title, $source_id );
		$unique    = $candidate;

		if ( function_exists( 'wp_unique_post_slug' ) ) {
			$unique = wp_unique_post_slug(
				$candidate,
				(int) $existing_post_id,
				sanitize_key( (string) $post_status ) ?: 'draft',
				sanitize_key( (string) $post_type ) ?: 'post',
				(int) $post_parent
			);
		}

		$unique = sanitize_title( (string) $unique );
		if ( '' === $unique ) {
			$unique = $candidate;
		}

		if ( $unique !== $candidate ) {
			wptsall_log_info(
				'tasks-sync',
				'Adjusted translated post slug to keep permalink unique',
				array(
					'candidate_slug' => $candidate,
					'unique_slug'    => $unique,
					'post_type'      => $post_type,
					'existing_id'    => (int) $existing_post_id,
				)
			);
		}

		return $unique;
	}

	/**
	 * Build a safe non-empty term slug for write-back.
	 *
	 * @param mixed $raw_slug   Candidate translated slug.
	 * @param mixed $raw_name   Candidate translated name fallback.
	 * @param int   $source_id  Source object id for deterministic fallback.
	 * @return string
	 */
	private static function normalize_term_slug( $raw_slug, $raw_name, $source_id = 0 ) {
		$slug = sanitize_title( self::strip_translation_markers_for_slug( $raw_slug ) );
		if ( '' !== $slug && ! self::slug_has_marker_artifacts( $slug ) ) {
			return $slug;
		}

		$name = self::strip_translation_markers_for_slug( $raw_name );
		$slug = sanitize_title( $name );
		if ( '' !== $slug && ! self::slug_has_marker_artifacts( $slug ) ) {
			return $slug;
		}

		if ( $source_id > 0 ) {
			return 'term-' . absint( $source_id );
		}

		return 'term-' . time();
	}

	/**
	 * Build a unique term slug inside the target taxonomy.
	 *
	 * @param mixed  $raw_slug         Candidate translated slug.
	 * @param mixed  $raw_name         Candidate translated name fallback.
	 * @param string $taxonomy         Target taxonomy.
	 * @param int    $existing_term_id Existing target term id when updating.
	 * @param int    $source_id        Source object id for deterministic fallback.
	 * @return string
	 */
	private static function ensure_unique_term_slug( $raw_slug, $raw_name, $taxonomy, $existing_term_id = 0, $source_id = 0 ) {
		$base = self::normalize_term_slug( $raw_slug, $raw_name, $source_id );
		$slug = $base;

		if ( $source_id > 0 ) {
			$existing = get_term_by( 'slug', $slug, $taxonomy );
			if ( $existing && (int) $existing->term_id !== (int) $existing_term_id ) {
				$slug = sanitize_title( $base . '-' . absint( $source_id ) );
			}
		}

		$suffix = 2;
		while ( true ) {
			$existing = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $existing || (int) $existing->term_id === (int) $existing_term_id ) {
				break;
			}
			$slug = sanitize_title( $base . '-' . $suffix );
			++$suffix;
		}

		if ( $slug !== $base ) {
			wptsall_log_info(
				'tasks-sync',
				'Adjusted translated term slug to keep taxonomy URL unique',
				array(
					'candidate_slug' => $base,
					'unique_slug'    => $slug,
					'taxonomy'       => $taxonomy,
					'existing_id'    => (int) $existing_term_id,
				)
			);
		}

		return $slug;
	}

	/**
	 * Count failed structured-format field results from callback payload.
	 *
	 * Structured formats include json_structured and serialized_php. These
	 * failures are surfaced in task status metadata for operational visibility.
	 *
	 * @since 1.6.1
	 *
	 * @param array $field_results Callback field_results array.
	 * @return int
	 */
	private static function count_structured_field_failures( array $field_results ): int {
		$count = 0;
		foreach ( $field_results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$format = sanitize_key( (string) ( $row['content_format'] ?? '' ) );
			if ( 'failed' !== $status ) {
				continue;
			}
			if ( in_array( $format, array( 'json_structured', 'serialized_php' ), true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Build a field_name → content_format map from translation rules for a relation + subtype.
	 *
	 * Used by merge_translation_result() to select the appropriate sanitization
	 * function (wp_kses_post for rich_html/serialized_php/json_structured, sanitize_text_field otherwise).
	 *
	 * @since 1.3.0
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $subtype     Post type or taxonomy slug.
	 * @param string $object_type Canonical object type ("post_type" or "taxonomy").
	 * @return array Associative array: field_name => content_format string.
	 */
	private static function get_field_content_formats_for_sync( $relation_id, $subtype, $object_type = 'post_type' ) {
		$formats = array();
		$expected_data_type = 'post_type' === $object_type ? 'post' : ( 'taxonomy' === $object_type ? 'term' : '' );

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

				$rule_data_type = $rule['data_type'] ?? '';
				$rule_object_name = $rule['object_name'] ?? '';
				$field_caps = $rule['field_capabilities'] ?? array();

				if ( is_array( $merged_config ) ) {
					$rule_data_type = $merged_config['data_type'] ?? $rule_data_type;
					$rule_object_name = $merged_config['post_type'] ?? $rule_object_name;
					if ( isset( $merged_config['fields'] ) && is_array( $merged_config['fields'] ) ) {
						$field_caps = $merged_config['fields'];
					}
				}

				$normalized_rule_data_type = $rule_data_type;
				if ( 'post_type' === $normalized_rule_data_type ) {
					$normalized_rule_data_type = 'post';
				} elseif ( 'taxonomy' === $normalized_rule_data_type ) {
					$normalized_rule_data_type = 'term';
				}

				// Backward compatibility: empty rule_data_type is treated as wildcard.
				if ( '' !== $expected_data_type && '' !== $normalized_rule_data_type && $normalized_rule_data_type !== $expected_data_type ) {
					continue;
				}
				if ( $rule_object_name !== $subtype ) {
					continue;
				}

				foreach ( $field_caps as $field_name => $config ) {
					if ( is_array( $config ) && ! empty( $config['content_format'] ) ) {
						$format = sanitize_key( (string) $config['content_format'] );
						if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
							$format = \WPTSALL\Core\Smart_Field_Classifier::normalize_content_format( $format, 'plain_text' );
						}
						$formats[ $field_name ] = $format;
					}
				}
			}
		}

		// FSE objects can be delivered by the synthetic Client rule when no
		// relation model exists. Keep write-back sanitization aligned with that
		// rule: block markup is rich HTML, while global styles are JSON.
		if ( 'post_type' === $object_type ) {
			$fse_types = apply_filters(
				'wptsall_fse_managed_post_types',
				array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' )
			);
			if ( in_array( $subtype, (array) $fse_types, true ) ) {
				$formats['post_title']   = 'plain_text';
				$formats['post_content'] = 'wp_global_styles' === $subtype ? 'json_structured' : 'rich_html';
			}
		}

		return $formats;
	}

	/**
	 * Check if a target object already exists for new_only enforcement.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Source object ID.
	 * @param string $target_type Target type (wp/virtual).
	 * @param mixed  $target_id   Target site identifier.
	 * @param int    $target_blog Target blog ID.
	 * @return int Target object ID, or 0 when no target exists.
	 */
	private static function check_target_exists( $relation_id, $object_type, $object_id, $target_type, $target_id, $target_blog ) {
		global $wpdb;

		if ( 'virtual' === $target_type ) {
			// Since v0.8.0, virtual site content is stored natively in wp_posts/wp_terms
			// with meta markers (_wptsall_virtual_site_id + _wptsall_source_post_id/term_id).
			if ( 'post_type' === $object_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT p.ID FROM %i p
						INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
						INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
						WHERE pm1.meta_value = %s AND pm2.meta_value = %d
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->postmeta,
						$target_id,
						$object_id
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT t.term_id FROM %i t
						INNER JOIN %i tm1 ON t.term_id = tm1.term_id AND tm1.meta_key = '_wptsall_virtual_site_id'
						INNER JOIN %i tm2 ON t.term_id = tm2.term_id AND tm2.meta_key = '_wptsall_source_term_id'
						WHERE tm1.meta_value = %s AND tm2.meta_value = %d
						LIMIT 1",
						$wpdb->terms,
						$wpdb->termmeta,
						$wpdb->termmeta,
						$target_id,
						$object_id
					)
				);
			}
			return $exists ? (int) $exists : 0;
		}

		// For wp targets: check _wptsall_source_id postmeta on target blog.
		if ( is_multisite() && $target_blog ) {
			switch_to_blog( $target_blog );
		}

		$meta_key = '_wptsall_source_post_id';
		if ( 'taxonomy' === $object_type ) {
			$meta_key = '_wptsall_source_term_id';
		}

		if ( 'post_type' === $object_type ) {
			$exists = Direct_DB_Service::get_post_by_meta( $meta_key, $object_id );
		} else {
			$exists = Direct_DB_Service::get_term_by_meta( $meta_key, $object_id );
		}

		if ( is_multisite() && $target_blog ) {
			restore_current_blog();
		}

		return $exists ? (int) $exists : 0;
	}

	/**
	 * Get source data for an object.
	 *
	 * @param string $object_type Object type.
	 * @param string $subtype     Object subtype.
	 * @param int    $object_id   Object ID.
	 * @param int    $source_blog Source blog ID.
	 * @param int    $relation_id Site relation ID for option allowlist checks.
	 * @return array|WP_Error Source data array or error.
	 */
	private static function get_source_data( $object_type, $subtype, $object_id, $source_blog, $relation_id = 0 ) {
		if ( is_multisite() && $source_blog ) {
			switch_to_blog( $source_blog );
		}

		$data = array();

		if ( 'post_type' === $object_type ) {
			$post = get_post( $object_id );

			if ( ! $post ) {
				if ( is_multisite() && $source_blog ) {
					restore_current_blog();
				}
				return new \WP_Error( 'post_not_found', 'Source post not found' );
			}

			$data = array(
				'post' => array(
					'ID'           => $post->ID,
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_date'    => $post->post_date,
					'post_status'  => $post->post_status,
					'post_type'    => $post->post_type,
					'post_name'    => $post->post_name,
					'post_author'  => $post->post_author,
				),
				'meta'       => get_post_meta( $object_id ),
				'taxonomies' => array(),
			);

			// Get taxonomies
			$taxonomies = get_object_taxonomies( $post->post_type );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = wp_get_object_terms( $object_id, $taxonomy );
				if ( ! is_wp_error( $terms ) ) {
					$data['taxonomies'][ $taxonomy ] = wp_list_pluck( $terms, 'term_id' );
				}
			}
		} elseif ( 'taxonomy' === $object_type ) {
			$term = get_term( $object_id, $subtype );

			if ( ! $term || is_wp_error( $term ) ) {
				if ( is_multisite() && $source_blog ) {
					restore_current_blog();
				}
				return new \WP_Error( 'term_not_found', 'Source term not found' );
			}

			$data = array(
				'term' => array(
					'term_id'     => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => $term->parent,
				),
				'meta' => get_term_meta( $object_id ),
			);
		} elseif ( 'option' === $object_type ) {
			if ( ! \wptsall_is_syncable_option( $subtype, (int) $relation_id ) ) {
				if ( is_multisite() && $source_blog ) {
					restore_current_blog();
				}
				return new \WP_Error( 'unsyncable_option', 'Option is not in the sync allowlist.' );
			}
			$data = array(
				'option' => array(
					'option_name'  => $subtype,
					'option_value' => get_option( $subtype, null ),
				),
			);
		}

		if ( is_multisite() && $source_blog ) {
			restore_current_blog();
		}

		return $data;
	}

	/**
	 * Apply translation markers to fields that need translation.
	 *
	 * Uses unified marker format: 【lang】content【/lang】
	 * This is consistent with Translation_Simulation_Service.
	 *
	 * Note: This method is still called by execute_task() (Path A local/cron translation).
	 * Path A remains functional for local translation without the external Client.
	 *
	 * @deprecated 1.1.0 Scheduled for removal; Path A local translation is legacy.
	 *             Prefer Path B (Client + translate-api + WP write-back) for production use.
	 *
	 * @param array           $data             Processed data.
	 * @param Field_Processor $field_processor  Field processor instance.
	 * @param string          $lang_from        Source language.
	 * @param string          $lang_to          Target language.
	 * @return array Data with translation markers.
	 */
	private static function apply_translation_markers( $data, $field_processor, $lang_from, $lang_to ) {
		$summary          = $field_processor->get_processing_summary();
		$translate_fields = $summary['translate_fields'] ?? array();

		if ( empty( $translate_fields ) ) {
			return $data;
		}

		// Get marker mode from settings (default to 'unified' which uses 【lang】 format).
		$marker_mode = get_option( 'wptsall_translation_marker_mode', 'unified' );

		foreach ( $translate_fields as $field_info ) {
			$field_name = $field_info['field'];
			$field_type = $field_info['type'] ?? 'text';

			if ( isset( $data[ $field_name ] ) && ! empty( $data[ $field_name ] ) ) {
				$content = $data[ $field_name ];

				// Skip if already has markers.
				if ( preg_match( '/^【' . preg_quote( $lang_to, '/' ) . '】/', $content ) ) {
					continue;
				}

				switch ( $marker_mode ) {
					case 'unified':
					default:
						// Unified format: 【lang】content【/lang】
						$data[ $field_name ] = sprintf(
							'【%s】%s【/%s】',
							$lang_to,
							$content,
							$lang_to
						);
						break;

					case 'legacy_prefix':
						// Legacy format for backward compatibility.
						$data[ $field_name ] = '[needs translation:' . $lang_to . '] ' . $content;
						break;

					case 'none':
						// No marker, keep original.
						break;
				}

				// Store translation metadata for later use.
				if ( ! isset( $data['_translation_meta'] ) ) {
					$data['_translation_meta'] = array();
				}

				$data['_translation_meta'][ $field_name ] = array(
					'needs_translation' => true,
					'field_type'        => $field_type,
					'source_lang'       => $lang_from,
					'target_lang'       => $lang_to,
					'status'            => 'pending',
					'marker_format'     => $marker_mode,
				);
			}
		}

		return $data;
	}

	/**
	 * Sync processed data to target.
	 *
	 * @param string $object_type   Object type.
	 * @param string $subtype       Object subtype.
	 * @param array  $data          Processed data.
	 * @param int    $target_blog   Target blog ID.
	 * @param string $target_type   Target type (wp/virtual).
	 * @param array  $task          Original task data.
	 * @return array|WP_Error Sync result or error.
	 */
	private static function sync_to_target( $object_type, $subtype, $data, $target_blog, $target_type, $task ) {
		return wptsall_with_sync_suppression( function () use ( $object_type, $subtype, $data, $target_blog, $target_type, $task ) {
		if ( 'virtual' === $target_type ) {
			// Store in virtual site storage
			return self::sync_to_virtual_site( $object_type, $subtype, $data, $task );
		}

		// Sync to WordPress site
		if ( is_multisite() && $target_blog ) {
			switch_to_blog( $target_blog );
		}

		try {
			$result = array();

			if ( 'post_type' === $object_type ) {
				$result = self::sync_post( $data, $task );
			} elseif ( 'taxonomy' === $object_type ) {
				$result = self::sync_term( $data, $subtype, $task );
			} elseif ( 'option' === $object_type ) {
				$result = self::sync_option( $data, $subtype, $task );
			}
		} finally {
			if ( is_multisite() && $target_blog ) {
				restore_current_blog();
			}
		}

		return $result;
		} );
	}

	/**
	 * Resolve field_capabilities map for a sync task (copy_once / skip checks).
	 *
	 * @param array $task Task row.
	 * @return array<string,array|string>
	 */
	private static function get_field_capabilities_for_task( array $task ): array {
		$relation_id = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
		$subtype     = (string) ( $task['subtype'] ?? 'post' );
		if ( $relation_id <= 0 || ! class_exists( Translation_Rule_Service::class ) ) {
			return array();
		}
		$config = Translation_Rule_Service::get_merged_config_for_relation( $relation_id, $subtype );
		$caps   = $config['field_capabilities'] ?? array();
		return is_array( $caps ) ? $caps : array();
	}

	/**
	 * Sync post to target.
	 *
	 * Uses Direct_DB_Service with $site_context for multisite support (v0.9.0).
	 *
	 * @param array $data Post data.
	 * @param array $task Task data.
	 * @return array|WP_Error Sync result.
	 */
	private static function sync_post( $data, $task ) {
		$target_blog = $task['target_blog'] ?? null;

		// v1.5.0: Self-translation — overwrite the source post directly.
		$relation_id = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
		if ( $relation_id ) {
			$relation = Site_Relation_Service::get_relation( $relation_id );
			if ( $relation && Site_Relation_Service::is_self_translation( $relation ) ) {
				return self::sync_self_translation( $data, $task, $relation );
			}
		}

		// Build site context for Direct_DB_Service (v0.9.0).
		$site_context = array();
		if ( $target_blog && is_multisite() ) {
			$site_context = array(
				'type'    => 'multisite',
				'blog_id' => (int) $target_blog,
			);
		}

		// Read source meta in the SOURCE blog context.
		// The _wptsall_target_id_ meta is stored on the SOURCE post. Since this method
		// is called from sync_to_target() after switch_to_blog($target_blog), we must
		// temporarily restore to read from the correct blog.
		$source_blog = $task['blog_id'] ?? null;
		if ( is_multisite() && $target_blog ) {
			restore_current_blog();
		}
		$target_id = get_post_meta( $task['object_id'], '_wptsall_target_id_' . $task['relation_id'], true );
		if ( is_multisite() && $target_blog ) {
			switch_to_blog( $target_blog );
		}

		$post_data = array(
			'post_title'   => $data['post_title'] ?? '',
			'post_content' => $data['post_content'] ?? '',
			'post_excerpt' => $data['post_excerpt'] ?? '',
			'post_status'  => $data['post_status'] ?? 'draft',
			'post_type'    => $task['subtype'],
			'post_name'    => self::ensure_unique_post_slug(
				$data['post_name'] ?? '',
				$data['post_title'] ?? '',
				$task['subtype'],
				$data['post_status'] ?? 'draft',
				(int) ( $data['post_parent'] ?? 0 ),
				(int) $target_id,
				(int) ( $task['object_id'] ?? 0 )
			),
			'post_date'    => $data['post_date'] ?? current_time( 'mysql' ),
		);

		if ( $target_id ) {
			// Check for write-back conflict before overwriting.
			if ( ! self::check_sync_conflict( (int) $target_id, (int) ( $task['relation_id'] ?? 0 ), 'sync_post' ) ) {
				return array( 'target_id' => (int) $target_id, 'translations' => 0, 'skipped' => 'conflict' );
			}

			// Update existing post using Direct_DB_Service.
			$result = Direct_DB_Service::update_post( (int) $target_id, $post_data, $site_context );

			if ( is_wp_error( $result ) && 'target_not_found' === $result->get_error_code() ) {
				// Target post was deleted (stale meta). Clear meta and fall through to create.
				if ( is_multisite() && $target_blog ) {
					restore_current_blog();
				}
				delete_post_meta( $task['object_id'], '_wptsall_target_id_' . $task['relation_id'] );
				if ( is_multisite() && $target_blog ) {
					switch_to_blog( $target_blog );
				}
				$target_id = null;
			} elseif ( is_wp_error( $result ) ) {
				return $result;
			} else {
				$result_id = (int) $target_id;
				// Affirm identity markers on update (legacy rows may lack relation_id).
				Direct_DB_Service::update_post_meta( $result_id, '_wptsall_source_post_id', $task['object_id'] );
				Direct_DB_Service::update_post_meta( $result_id, '_wptsall_source_blog_id', $task['blog_id'] ?? get_current_blog_id() );
				$relation_id_for_meta = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
				if ( $relation_id_for_meta > 0 ) {
					Direct_DB_Service::update_post_meta( $result_id, '_wptsall_relation_id', $relation_id_for_meta );
				}
			}
		}

		if ( ! $target_id ) {
			// Create new post using Direct_DB_Service.
			// Wrap in transaction to ensure target_id meta is written atomically with the insert.
			global $wpdb;
			$wpdb->query( 'START TRANSACTION' );
			$result_id = Direct_DB_Service::insert_post( $post_data, $site_context );

			if ( is_wp_error( $result_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $result_id;
			}

			// Store relationship on source post — temporarily restore to source blog context.
			if ( is_multisite() && $target_blog ) {
				restore_current_blog();
			}
			update_post_meta( $task['object_id'], '_wptsall_target_id_' . $task['relation_id'], $result_id );
			if ( is_multisite() && $target_blog ) {
				switch_to_blog( $target_blog );
			}

			// Store source reference on target post (always include relation_id).
			Direct_DB_Service::update_post_meta( $result_id, '_wptsall_source_post_id', $task['object_id'] );
			Direct_DB_Service::update_post_meta( $result_id, '_wptsall_source_blog_id', $task['blog_id'] ?? get_current_blog_id() );
			$relation_id_for_meta = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
			if ( $relation_id_for_meta > 0 ) {
				Direct_DB_Service::update_post_meta( $result_id, '_wptsall_relation_id', $relation_id_for_meta );
			}
			$wpdb->query( 'COMMIT' );
		}

		// Count translation markers.
		$translations = 0;
		if ( isset( $data['_translation_meta'] ) ) {
			$translations = count( $data['_translation_meta'] );

			// Store translation meta on target.
			Direct_DB_Service::update_post_meta( $result_id, '_wptsall_translation_meta', $data['_translation_meta'] );
		}

		// Map featured image (media mapping) if present in source data.
		$source_thumbnail_id = 0;
		if ( ! empty( $data['meta']['_thumbnail_id'] ) ) {
			$raw_thumb = $data['meta']['_thumbnail_id'];
			$source_thumbnail_id = (int) ( is_array( $raw_thumb ) ? ( $raw_thumb[0] ?? 0 ) : $raw_thumb );
		}
			if ( $source_thumbnail_id && $result_id && class_exists( '\\WPTSALL\\Models\\Services\\Media_Mapping_Service' ) ) {
				$media_source_site = (int) ( $task['blog_id'] ?? get_current_blog_id() );
				$media_target_site = ! empty( $task['target_identifier'] ) ? (string) $task['target_identifier'] : (string) ( $task['target_blog'] ?? get_current_blog_id() );
				$media_lang        = $task['lang_to'] ?? $task['target_lang'] ?? '';
				$relation_id       = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );

				$target_media_id = \WPTSALL\Models\Services\Media_Mapping_Service::map_media_with_copy(
					$source_thumbnail_id,
					$media_source_site,
					$media_target_site,
					$media_lang,
					array(
						'copy_file'     => true,
						'translate_alt' => true,
						'relation_id'   => $relation_id,
					)
				);

			if ( $target_media_id ) {
				Direct_DB_Service::update_post_meta( $result_id, '_thumbnail_id', $target_media_id );
			}

			// Map WooCommerce product gallery (_product_image_gallery).
			if ( ! empty( $data['meta']['_product_image_gallery'] ) ) {
				$gallery_csv = is_array( $data['meta']['_product_image_gallery'] )
					? ( $data['meta']['_product_image_gallery'][0] ?? '' )
					: $data['meta']['_product_image_gallery'];
				$gallery_ids = array_filter( array_map( 'intval', explode( ',', $gallery_csv ) ) );
				$gallery_ids = array_slice( $gallery_ids, 0, apply_filters( 'wptsall_max_gallery_media_per_sync', 20 ) );

				$new_gallery = array();
				foreach ( $gallery_ids as $gid ) {
						$mapped = \WPTSALL\Models\Services\Media_Mapping_Service::map_media_with_copy(
							$gid,
							$media_source_site,
							$media_target_site,
							$media_lang,
							array(
								'copy_file'     => true,
								'translate_alt' => true,
								'relation_id'   => $relation_id,
							)
						);
					$new_gallery[] = $mapped ? $mapped : $gid;
				}
				if ( ! empty( $new_gallery ) ) {
					Direct_DB_Service::update_post_meta( $result_id, '_product_image_gallery', implode( ',', $new_gallery ) );
				}
			}
		}

		// Sync remaining meta fields (generic loop).
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$handled_meta = array( '_thumbnail_id', '_product_image_gallery' );
			$field_caps   = self::get_field_capabilities_for_task( $task );
			foreach ( $data['meta'] as $meta_key => $meta_value ) {
				if ( str_starts_with( $meta_key, '_wptsall_' ) ) {
					continue;
				}
				if ( in_array( $meta_key, $handled_meta, true ) ) {
					continue;
				}
				// Honor skip / copy_once when declared in field_capabilities (WPML four-state).
				if ( ! empty( $field_caps[ $meta_key ] ) && class_exists( '\\WPTSALL\\Models\\Services\\Field_Capability' ) ) {
					$type = \WPTSALL\Models\Services\Field_Capability::type_for_field( $field_caps, (string) $meta_key );
					if ( 'skip' === $type ) {
						continue;
					}
					if ( \WPTSALL\Models\Services\Field_Capability::is_copy_once( $type ) ) {
						$existing = Direct_DB_Service::get_post_meta( $result_id, $meta_key, true );
						if ( ! \WPTSALL\Models\Services\Field_Capability::should_write_to_target( 'copy_once', $existing ) ) {
							continue;
						}
					}
				}
				if ( is_array( $meta_value ) && count( $meta_value ) > 1 ) {
					// Multi-value meta: delete all then re-add each.
					Direct_DB_Service::delete_post_meta( $result_id, $meta_key );
					foreach ( $meta_value as $single_value ) {
						Direct_DB_Service::add_post_meta( $result_id, $meta_key, $single_value );
					}
				} else {
					$value = is_array( $meta_value ) ? ( $meta_value[0] ?? '' ) : $meta_value;
					$value = Direct_DB_Service::normalize_meta_value( $value );
					Direct_DB_Service::update_post_meta( $result_id, $meta_key, $value );
				}
			}
		}

		// Update sync timestamp.
		Direct_DB_Service::update_post_meta( $result_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

		// Register post mapping in the post_mappings store.
		if ( $result_id && class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) ) {
			$source_site_id = $task['blog_id'] ?? get_current_blog_id();
			$target_site_id = (string) get_current_blog_id();
			if ( ! empty( $task['target_identifier'] ) ) {
				$target_site_id = (string) $task['target_identifier'];
			} elseif ( ! empty( $task['target_blog'] ) ) {
				$target_site_id = (string) $task['target_blog'];
			}

				\WPTSALL\Models\Services\Post_Mapping_Service::create_mapping(
					array(
						'source_post_id'    => (int) ( $task['object_id'] ?? 0 ),
						'source_post_type'  => $task['subtype'] ?? 'post',
						'source_site_id'    => (int) $source_site_id,
						'relation_id'       => (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 ),
						'target_post_id'    => (int) $result_id,
						'target_post_type'  => $task['subtype'] ?? 'post',
						'target_site_id'    => $target_site_id,
					'relationship_type' => 'translation',
				)
			);
		}

		// Auto-sideload embedded media in post_content (copy mode).
		// These are non-critical post-processing steps — a failure must not
		// crash the sync or prevent post_mapping / task completion.
		if ( $result_id && class_exists( '\\WPTSALL\\Models\\Services\\Media_Mapping_Service' ) ) {
			try {
				self::sideload_embedded_media_in_content( $result_id, $task, $site_context );
			} catch ( \Throwable $e ) {
				wptsall_log_error( 'tasks-sync', 'sideload_embedded_media failed (non-fatal)', array(
					'target_post_id' => $result_id,
					'error'          => $e->getMessage(),
				) );
			}
		}

		// Replace media URLs in post_content using media_mappings.
		if ( $result_id && class_exists( '\\WPTSALL\\Models\\Services\\Media_Mapping_Service' ) ) {
			try {
				self::replace_media_urls_in_content( $result_id, $task, $site_context );
			} catch ( \Throwable $e ) {
				wptsall_log_error( 'tasks-sync', 'replace_media_urls failed (non-fatal)', array(
					'target_post_id' => $result_id,
					'error'          => $e->getMessage(),
				) );
			}
		}

		// Elementor deep links: rewrite same-host URLs inside _elementor_data for target VS.
		if ( $result_id ) {
			try {
				self::rewrite_elementor_data_urls( (int) $result_id, $task, $site_context );
			} catch ( \Throwable $e ) {
				wptsall_log_error( 'tasks-sync', 'rewrite_elementor_data_urls failed (non-fatal)', array(
					'target_post_id' => $result_id,
					'error'          => $e->getMessage(),
				) );
			}
		}

		return array(
			'target_id'    => $result_id,
			'translations' => $translations,
		);
	}

	/**
	 * Self-translation: overwrite the source post with translated content.
	 *
	 * Backs up original content to postmeta on first overwrite, then updates
	 * the source post in-place. Creates a post_mapping with source == target
	 * to prevent re-discovery.
	 *
	 * @since 1.5.0
	 *
	 * @param array $data     Merged post data (translated fields).
	 * @param array $task     Task context data.
	 * @param array $relation Site relation data.
	 * @return array|WP_Error Sync result.
	 */
	private static function sync_self_translation( $data, $task, $relation ) {
		$source_id   = (int) ( $task['object_id'] ?? 0 );
		$relation_id = (int) ( $relation['id'] ?? 0 );

		if ( ! $source_id ) {
			return new \WP_Error( 'self_translation_no_source', 'Missing object_id for self-translation' );
		}

		// Ensure we are on the source blog.
		$source_blog = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$current     = get_current_blog_id();
		$switched    = false;
		if ( is_multisite() && $source_blog !== $current ) {
			switch_to_blog( $source_blog );
			$switched = true;
		}

		$original = get_post( $source_id );
		if ( ! $original ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new \WP_Error( 'self_translation_not_found', 'Source post not found', array( 'post_id' => $source_id ) );
		}

		// Backup original content on first overwrite.
		if ( ! get_post_meta( $source_id, '_wptsall_original_content', true ) ) {
			update_post_meta( $source_id, '_wptsall_original_title', $original->post_title );
			update_post_meta( $source_id, '_wptsall_original_content', $original->post_content );
			update_post_meta( $source_id, '_wptsall_original_excerpt', $original->post_excerpt );

			wptsall_log_info( 'tasks-sync', 'Self-translation: backed up original content', array(
				'post_id'     => $source_id,
				'relation_id' => $relation_id,
			) );
		}

		// Overwrite the source post with translated content.
			$update_data = array(
				'ID'           => $source_id,
				'post_title'   => $data['post_title'] ?? $original->post_title,
				'post_content' => $data['post_content'] ?? $original->post_content,
				'post_excerpt' => $data['post_excerpt'] ?? $original->post_excerpt,
				'post_name'    => self::ensure_unique_post_slug(
					$data['post_name'] ?? $original->post_name,
					$data['post_title'] ?? $original->post_title,
					$original->post_type,
					$data['post_status'] ?? $original->post_status,
					(int) ( $original->post_parent ?? 0 ),
					$source_id,
					$source_id
				),
			);

		if ( ! self::check_sync_conflict( $source_id, $relation_id, 'self_translation' ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return array( 'target_id' => $source_id, 'translations' => 0, 'skipped' => 'conflict' );
		}

		$result = wp_update_post( $update_data, true );
		if ( is_wp_error( $result ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $result;
		}

		// Sync translated meta fields.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $meta_key => $meta_value ) {
				if ( str_starts_with( $meta_key, '_wptsall_' ) ) {
					continue;
				}
				if ( is_array( $meta_value ) && count( $meta_value ) > 1 ) {
					delete_post_meta( $source_id, $meta_key );
					foreach ( $meta_value as $single_value ) {
						add_post_meta( $source_id, $meta_key, wp_kses_post( $single_value ) );
					}
				} else {
					$value = is_array( $meta_value ) ? ( $meta_value[0] ?? '' ) : $meta_value;
					update_post_meta( $source_id, $meta_key, wp_kses_post( $value ) );
				}
			}
		}

		// Update sync timestamp.
		\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $source_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

		// Set target_id meta pointing to self.
		update_post_meta( $source_id, '_wptsall_target_id_' . $relation_id, $source_id );

		// Register post mapping (source == target) to prevent re-discovery.
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) ) {
				\WPTSALL\Models\Services\Post_Mapping_Service::create_mapping( array(
					'source_post_id'    => $source_id,
					'source_post_type'  => $original->post_type,
					'source_site_id'    => $source_blog,
					'relation_id'       => $relation_id,
					'target_post_id'    => $source_id,
					'target_post_type'  => $original->post_type,
					'target_site_id'    => (string) $source_blog,
				'relationship_type' => 'self_translation',
			) );
		}

		// Count translation markers.
		$translations = 0;
		if ( isset( $data['_translation_meta'] ) ) {
			$translations = count( $data['_translation_meta'] );
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $source_id, '_wptsall_translation_meta', $data['_translation_meta'] );
		}

		if ( $switched ) {
			restore_current_blog();
		}

		wptsall_log_info( 'tasks-sync', 'Self-translation completed: source post overwritten', array(
			'post_id'      => $source_id,
			'relation_id'  => $relation_id,
			'translations' => $translations,
		) );

		return array(
			'target_id'    => $source_id,
			'translations' => $translations,
		);
	}

	/**
	 * Sideload embedded media referenced in post_content.
	 *
	 * Extracts attachment IDs and image URLs from Gutenberg blocks and HTML tags,
	 * then calls Media_Mapping_Service::map_media_with_copy() for each to ensure
	 * embedded media files are copied to the target site before URL replacement runs.
	 *
	 * Must be called BEFORE replace_media_urls_in_content() so that media mappings
	 * exist when URL replacement runs.
	 *
	 * @since 1.2.1
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $task           Task data.
	 * @param array $site_context   Site context for Direct_DB_Service.
	 */
		private static function sideload_embedded_media_in_content( int $target_post_id, array $task, array $site_context ): void {
			$max_media_per_post = apply_filters( 'wptsall_max_media_per_sync', 20 );
			$relation_id        = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );

			// Only sideload in copy mode; reference mode keeps source URLs as-is.
			if ( ( $task['_media_handling'] ?? 'copy' ) === 'reference' ) {
			return;
		}

		$post = get_post( $target_post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}
		$content = $post->post_content;

		$source_site_id = (int) ( $task['blog_id'] ?? get_current_blog_id() );
		$target_site_id = '';
		if ( ! empty( $task['target_identifier'] ) ) {
			$target_site_id = (string) $task['target_identifier'];
		} elseif ( ! empty( $task['target_blog'] ) ) {
			$target_site_id = (string) $task['target_blog'];
		}
		$media_lang = $task['lang_to'] ?? $task['target_lang'] ?? '';

		if ( empty( $target_site_id ) ) {
			return;
		}

		// Collect source attachment IDs from Gutenberg block comments.
		$source_ids = array();

		// wp:image, wp:video, wp:audio, wp:cover — "id":NNN
		if ( preg_match_all( '/<!-- wp:(?:image|video|audio|cover)[^>]*"id"\s*:\s*(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$source_ids[ (int) $id ] = true;
			}
		}

		// wp:media-text — "mediaId":NNN
		if ( preg_match_all( '/<!-- wp:media-text[^>]*"mediaId"\s*:\s*(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$source_ids[ (int) $id ] = true;
			}
		}

		// wp:gallery — "ids":[1,2,3]
		if ( preg_match_all( '/<!-- wp:gallery[^>]*"ids"\s*:\s*\[([0-9,\s]+)\]/', $content, $matches ) ) {
			foreach ( $matches[1] as $id_list ) {
				$ids = array_map( 'intval', explode( ',', $id_list ) );
				foreach ( $ids as $id ) {
					if ( $id > 0 ) {
						$source_ids[ $id ] = true;
					}
				}
			}
		}

		// Collect image URLs from <img> tags that look like local uploads.
		$url_only = array();
		if ( preg_match_all( '/<img[^>]+src="([^"]+)"/', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				if ( false !== strpos( $url, '/wp-content/uploads/' ) ) {
					$url_only[] = $url;
				}
			}
		}

		// Resolve URL-only images to attachment IDs in source blog context.
		$target_blog = $task['target_blog'] ?? null;
		if ( ! empty( $url_only ) ) {
			// Temporarily switch to source blog to resolve URLs.
			if ( is_multisite() && $target_blog ) {
				restore_current_blog();
			}

			foreach ( $url_only as $url ) {
				$attachment_id = attachment_url_to_postid( $url );
				if ( $attachment_id > 0 ) {
					$source_ids[ $attachment_id ] = true;
				}
			}

			// Switch back to target blog.
			if ( is_multisite() && $target_blog ) {
				switch_to_blog( (int) $target_blog );
			}
		}

		if ( empty( $source_ids ) ) {
			return;
		}

		// On retry/recovery, skip already-processed media by checking existing mappings.
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Media_Mapping_Service' ) ) {
				$existing_mappings = \WPTSALL\Models\Services\Media_Mapping_Service::get_mappings_by_site_pair(
					$source_site_id,
					$target_site_id,
					$relation_id
				);
			if ( ! empty( $existing_mappings ) && is_array( $existing_mappings ) ) {
				foreach ( $existing_mappings as $mapping ) {
					$mapped_source_id = (int) ( $mapping['source_media_id'] ?? 0 );
					if ( $mapped_source_id > 0 && isset( $source_ids[ $mapped_source_id ] ) ) {
						unset( $source_ids[ $mapped_source_id ] );
					}
				}
			}
		}

		if ( empty( $source_ids ) ) {
			return;
		}

		// Sideload each discovered source attachment via Media_Mapping_Service.
		$sideloaded = 0;
		foreach ( array_keys( $source_ids ) as $source_attachment_id ) {
			if ( $sideloaded >= $max_media_per_post ) {
				wptsall_log_warning( 'tasks-sync', 'Media sideload limit reached, skipping remaining', array(
					'target_post_id' => $target_post_id,
					'limit'          => $max_media_per_post,
					'remaining'      => count( $source_ids ) - $sideloaded,
				) );
				break;
			}
				$target_media_id = \WPTSALL\Models\Services\Media_Mapping_Service::map_media_with_copy(
					$source_attachment_id,
					$source_site_id,
					$target_site_id,
					$media_lang,
					array(
						'copy_file'     => true,
						'translate_alt' => false,
						'relation_id'   => $relation_id,
					)
				);
			if ( $target_media_id ) {
				++$sideloaded;
			}
		}

		if ( $sideloaded > 0 ) {
			wptsall_log_info( 'tasks-sync', 'Sideloaded embedded media from post_content', array(
				'target_post_id' => $target_post_id,
				'discovered'     => count( $source_ids ),
				'sideloaded'     => $sideloaded,
			) );
		}
	}

	/**
	 * Replace source media URLs and Gutenberg block attachment IDs in target post_content.
	 *
	 * First pass: scans content for media URLs from the source site, looks up media_mappings
	 * to find the corresponding target URL, and batch-replaces them.
	 *
	 * Second pass: replaces numeric attachment IDs in Gutenberg block comments using
	 * source_media_id → target_media_id mappings from the media_mappings table.
	 *
	 * @since 1.2.0
	 * @since 1.2.1 Added Gutenberg block attachment ID replacement.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $task           Task data.
	 * @param array $site_context   Site context for Direct_DB_Service.
	 */
	private static function replace_media_urls_in_content( int $target_post_id, array $task, array $site_context ): void {
		global $wpdb;

		$post = get_post( $target_post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}
		$content = $post->post_content;

		$relation_id = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
		if ( $relation_id <= 0 ) {
			return;
		}

		if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
			wptsall_ensure_relation_scoped_mapping_tables();
		}

		// Get all media_mappings for this relation.
		$table = wptsall_table( 'media_mappings' );
		$has_relation_id = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'relation_id' )
		);
		if ( ! $has_relation_id ) {
			// A target subsite may not receive admin_init during the source-site
			// upgrade. Preserve write-back availability and ask the next normal
			// migration pass to add the relation-scoped column.
			wptsall_log_warning( 'tasks-sync', 'media_mappings relation_id migration pending; media replacement skipped', array( 'relation_id' => $relation_id ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_file_url, target_file_url FROM %i WHERE relation_id = %d AND source_file_url != '' AND target_file_url != ''",
				$table,
				$relation_id
			),
			ARRAY_A
		);

		// Pass 1: Replace source media URLs with target URLs.
		$url_replacements = 0;
		if ( ! empty( $mappings ) ) {
			foreach ( $mappings as $mapping ) {
				if ( false !== strpos( $content, $mapping['source_file_url'] ) ) {
					$content = str_replace( $mapping['source_file_url'], $mapping['target_file_url'], $content );
					++$url_replacements;
				}
			}
		}

		// Pass 2: Replace Gutenberg block attachment IDs (source_media_id → target_media_id).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id_mappings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_media_id, target_media_id FROM %i WHERE relation_id = %d AND source_media_id > 0 AND target_media_id > 0",
				$table,
				$relation_id
			),
			ARRAY_A
		);

		$id_replacements = 0;
		if ( ! empty( $id_mappings ) ) {
			// Build source→target ID map.
			$id_map = array();
			foreach ( $id_mappings as $row ) {
				$id_map[ (int) $row['source_media_id'] ] = (int) $row['target_media_id'];
			}

			// Replace "id":NNN in wp:image, wp:video, wp:audio, wp:cover blocks.
			$content = preg_replace_callback(
				'/(<!-- wp:(?:image|video|audio|cover)[^>]*"id"\s*:\s*)(\d+)/',
				function ( $matches ) use ( $id_map, &$id_replacements ) {
					$source_id = (int) $matches[2];
					if ( isset( $id_map[ $source_id ] ) ) {
						++$id_replacements;
						return $matches[1] . $id_map[ $source_id ];
					}
					return $matches[0];
				},
				$content
			);

			// Replace "mediaId":NNN in wp:media-text and wp:cover blocks.
			$content = preg_replace_callback(
				'/("mediaId"\s*:\s*)(\d+)/',
				function ( $matches ) use ( $id_map, &$id_replacements ) {
					$source_id = (int) $matches[2];
					if ( isset( $id_map[ $source_id ] ) ) {
						++$id_replacements;
						return $matches[1] . $id_map[ $source_id ];
					}
					return $matches[0];
				},
				$content
			);
		}

		$total_replacements = $url_replacements + $id_replacements;
		if ( $total_replacements > 0 ) {
			Direct_DB_Service::update_post( $target_post_id, array( 'post_content' => $content ), $site_context );
			wptsall_log_info( 'tasks-sync', 'Replaced media URLs and block IDs in target post content', array(
				'target_post_id'  => $target_post_id,
				'url_replacements' => $url_replacements,
				'id_replacements'  => $id_replacements,
			) );
		}
	}

	/**
	 * Rewrite same-host URLs inside target _elementor_data for the relation's VS.
	 *
	 * @since 2.2.0
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $task           Task data.
	 * @param array $site_context   Direct_DB_Service context.
	 * @return void
	 */
	private static function rewrite_elementor_data_urls( int $target_post_id, array $task, array $site_context ): void {
		if ( ! class_exists( '\\WPTSALL\\Hooks\\Elementor_Data_Url_Rewriter' ) ) {
			return;
		}
		$raw = Direct_DB_Service::get_post_meta( $target_post_id, '_elementor_data', true );
		if ( null === $raw || false === $raw || '' === $raw ) {
			return;
		}

		$vs = self::resolve_virtual_site_for_task( $task );
		if ( ! $vs ) {
			return;
		}

		$rewritten = \WPTSALL\Hooks\Elementor_Data_Url_Rewriter::rewrite_meta_value( $raw, $vs );
		if ( $rewritten === $raw ) {
			return;
		}
		Direct_DB_Service::update_post_meta( $target_post_id, '_elementor_data', $rewritten );
		wptsall_log_info(
			'tasks-sync',
			'Rewrote Elementor data URLs for virtual site',
			array(
				'target_post_id' => $target_post_id,
				'path_prefix'    => $vs['path_prefix'] ?? '',
			)
		);
		unset( $site_context );
	}

	/**
	 * Resolve virtual site row for a sync task (virtual target or lang match).
	 *
	 * @param array $task Task.
	 * @return array|null
	 */
	private static function resolve_virtual_site_for_task( array $task ): ?array {
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			return null;
		}
		$relation_id = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
		$relation    = null;
		if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
		}
		if ( is_array( $relation ) && 'virtual' === ( $relation['target_site_type'] ?? '' ) ) {
			$vs_id = (string) ( $relation['target_site_id'] ?? '' );
			if ( '' !== $vs_id ) {
				$vs = \WPTSALL\Sites\Services\Virtual_Site_Service::get( $vs_id );
				if ( is_array( $vs ) ) {
					return $vs;
				}
			}
		}
		$lang = is_array( $relation ) ? (string) ( $relation['target_lang'] ?? '' ) : '';
		if ( '' === $lang ) {
			return null;
		}
		foreach ( (array) \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) ) as $site ) {
			if ( (string) ( $site['lang'] ?? '' ) === $lang ) {
				return $site;
			}
		}
		return null;
	}

	/**
	 * Sync term to target.
	 *
	 * @param array  $data     Term data.
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $task     Task data.
	 * @return array|WP_Error Sync result.
	 */
	private static function sync_term( $data, $taxonomy, $task ) {
		$target_blog = $task['target_blog'] ?? null;
		// Read source term meta in the SOURCE blog context.
		// The _wptsall_target_id_ meta is stored on the SOURCE term. Since this method
		// is called from sync_to_target() after switch_to_blog($target_blog), we must
		// temporarily restore to read from the correct blog.
		if ( is_multisite() && $target_blog ) {
			restore_current_blog();
		}
		$target_id = get_term_meta( $task['object_id'], '_wptsall_target_id_' . $task['relation_id'], true );
		if ( is_multisite() && $target_blog ) {
			switch_to_blog( $target_blog );
		}

		$term_data   = array(
			'name'        => $data['name'] ?? '',
			'slug'        => self::ensure_unique_term_slug(
				$data['slug'] ?? '',
				$data['name'] ?? '',
				$taxonomy,
				(int) $target_id,
				(int) ( $task['object_id'] ?? 0 )
			),
			'description' => $data['description'] ?? '',
		);

		if ( $target_id ) {
			$term_relation_id = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
			if ( ! self::check_term_sync_conflict( (int) $target_id, $term_relation_id, 'sync_term' ) ) {
				return array( 'target_id' => (int) $target_id, 'translations' => 0, 'skipped' => 'conflict' );
			}

			// Update existing term
			$result = wp_update_term( $target_id, $taxonomy, $term_data );
		} else {
			// Create new term
			$result = wp_insert_term( $term_data['name'], $taxonomy, $term_data );

			if ( ! is_wp_error( $result ) && isset( $result['term_id'] ) ) {
				// Store relationship on source term — temporarily restore to source blog context.
				if ( is_multisite() && $target_blog ) {
					restore_current_blog();
				}
				update_term_meta( $task['object_id'], '_wptsall_target_id_' . $task['relation_id'], $result['term_id'] );
				if ( is_multisite() && $target_blog ) {
					switch_to_blog( $target_blog );
				}

				// Store source reference on target term (these are on the target blog, which is current).
				update_term_meta( $result['term_id'], '_wptsall_source_term_id', $task['object_id'] );
				update_term_meta( $result['term_id'], '_wptsall_source_blog_id', $task['blog_id'] ?? get_current_blog_id() );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result_id = $result['term_id'] ?? $target_id;

		// Sync additional term meta fields for non-virtual targets as well.
		// Keep behavior aligned with sync_term_to_virtual_site(): skip internal
		// _wptsall_* markers and preserve multi-value meta shape.
		if ( $result_id && ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $meta_key => $meta_value ) {
				if ( str_starts_with( $meta_key, '_wptsall_' ) ) {
					continue;
				}
				if ( is_array( $meta_value ) && count( $meta_value ) > 1 ) {
					delete_term_meta( $result_id, $meta_key );
					foreach ( $meta_value as $single_value ) {
						add_term_meta( $result_id, $meta_key, $single_value );
					}
				} else {
					$value = is_array( $meta_value ) ? ( $meta_value[0] ?? '' ) : $meta_value;
					update_term_meta( $result_id, $meta_key, $value );
				}
			}
		}

		// Update sync timestamp for term conflict checks.
		\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $result_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

		// Register term mapping in the new term_mappings store.
		if ( $result_id && class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
			$source_site_id = $task['blog_id'] ?? get_current_blog_id();
			$target_lang    = $task['target_lang'] ?? $task['target_language'] ?? 'en_US';
			$relation_id    = $task['relation_id'] ?? $task['site_id'] ?? 0;

			// Determine target_site_id.
			$target_site_id = (string) get_current_blog_id();
			if ( ! empty( $task['target_identifier'] ) ) {
				$target_site_id = $task['target_identifier'];
			}

			\WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
				array(
					'source_term_id'     => (int) $task['object_id'],
					'source_taxonomy'    => $taxonomy,
					'source_site_id'     => (int) $source_site_id,
					'relation_id'        => (int) $relation_id,
					'source_lang'        => $task['source_lang'] ?? $task['source_language'] ?? '',
					'target_term_id'     => $result_id,
					'target_taxonomy'    => $taxonomy,
					'target_site_id'     => $target_site_id,
					'target_lang'        => $target_lang,
					'mapping_method'     => 'auto_create',
					'translation_method' => 'sync_executor',
				)
			);
		}

		// Count translation markers
		$translations = 0;
		if ( isset( $data['_translation_meta'] ) ) {
			$translations = count( $data['_translation_meta'] );
		}

		return array(
			'target_id'    => $result_id,
			'translations' => $translations,
		);
	}

	/**
	 * Sync option-backed translated content to target.
	 *
	 * Current scope is WordPress targets only; virtual-site option deployment is
	 * not part of this minimal lane.
	 *
	 * @param array  $data        Merged option data.
	 * @param string $option_name Option key.
	 * @param array  $task        Task data.
	 * @return array|WP_Error
	 */
	private static function update_option_sync_state( array $relation, $option_name, $action, $claim_owner_hash = '' ) {
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Option_Sync_State_Service' ) ) {
			return false;
		}

		$source_blog_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$relation_id    = (int) ( $relation['id'] ?? 0 );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
		$option_name    = sanitize_key( (string) $option_name );
		if ( $relation_id <= 0 || '' === $option_name || ! \wptsall_is_syncable_option( $option_name, $relation_id ) ) {
			return false;
		}

		$switched = is_multisite() && $source_blog_id > 0 && $source_blog_id !== get_current_blog_id();
		if ( $switched ) {
			switch_to_blog( $source_blog_id );
		}
		try {
			$service = '\\WPTSALL\\Models\\Services\\Option_Sync_State_Service';
			if ( 'release' === $action ) {
				return $service::release_claim( $relation_id, $source_blog_id, $target_site_id, $option_name, $claim_owner_hash );
			}
			if ( 'synced' === $action ) {
				return $service::mark_synced( $relation_id, $source_blog_id, $target_site_id, $option_name, $claim_owner_hash );
			}
			return false;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Sync option-backed translated content to target.
	 *
	 * Current scope is WordPress targets only; virtual-site option deployment is
	 * not part of this minimal lane.
	 *
	 * @param array  $data        Merged option data.
	 * @param string $option_name Option key.
	 * @param array  $task        Task data.
	 * @return array|WP_Error
	 */
	private static function sync_option( $data, $option_name, $task ) {
		if ( 'virtual' === ( $task['target_type'] ?? 'wp' ) ) {
			return new \WP_Error( 'unsupported_target_type', 'Option sync does not support virtual targets yet.' );
		}

		if ( ! \wptsall_is_syncable_option( $option_name, (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 ) ) ) {
			return new \WP_Error(
				'unsyncable_option',
				sprintf( 'Option "%s" is not in the sync allowlist.', sanitize_key( (string) $option_name ) )
			);
		}

		update_option( $option_name, $data['option_value'] ?? null, false );

		$translations = 0;
		if ( isset( $data['option_value'] ) ) {
			$translations = is_array( $data['option_value'] ) ? count( $data['option_value'] ) : 1;
		}

		return array(
			'target_id'    => (int) ( $task['object_id'] ?? 0 ),
			'translations' => $translations,
		);
	}

	/**
	 * Sync to virtual site storage.
	 *
	 * Since v0.8.0, virtual site content is stored natively in wp_posts/wp_terms
	 * with meta markers (_wptsall_virtual_site_id, _wptsall_source_post_id, etc.)
	 * instead of the deprecated virtual_site_content table.
	 *
	 * @since 0.8.0
	 *
	 * @param string $object_type Object type.
	 * @param string $subtype     Object subtype.
	 * @param array  $data        Processed data.
	 * @param array  $task        Task data.
	 * @return array|WP_Error Sync result.
	 */
	private static function sync_to_virtual_site( $object_type, $subtype, $data, $task ) {
		global $wpdb;

		$virtual_site_id = $task['target_identifier'];
		$source_blog_id  = $task['blog_id'];
		$source_id       = $task['object_id'];
		$relation_id     = $task['relation_id'] ?? $task['site_id'] ?? 0;

		$translations = 0;
		if ( isset( $data['_translation_meta'] ) ) {
			$translations = count( $data['_translation_meta'] );
		}

		if ( 'post_type' === $object_type ) {
			return self::sync_post_to_virtual_site( $data, $subtype, $virtual_site_id, $source_blog_id, $source_id, $relation_id, $translations );
		} elseif ( 'taxonomy' === $object_type ) {
			return self::sync_term_to_virtual_site( $data, $subtype, $virtual_site_id, $source_blog_id, $source_id, $relation_id, $translations );
		}

		return new \WP_Error( 'unsupported_type', "Unsupported object type: {$object_type}" );
	}

	/**
	 * Sync a post to virtual site using native wp_posts storage.
	 *
	 * @param array  $data             Processed data.
	 * @param string $post_type        Post type.
	 * @param string $virtual_site_id  Virtual site identifier.
	 * @param int    $source_blog_id   Source blog ID.
	 * @param int    $source_id        Source post ID.
	 * @param int    $relation_id      Relation ID.
	 * @param int    $translations     Translation count.
	 * @return array Sync result.
	 */
	private static function sync_post_to_virtual_site( $data, $post_type, $virtual_site_id, $source_blog_id, $source_id, $relation_id, $translations ) {
		global $wpdb;

		$relation_id = (int) $relation_id;

		// Relation row drives identity markers, media language and term associations.
		$relation = null;
		if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
		}
		if ( ! is_array( $relation ) ) {
			$relation = array(
				'id'               => $relation_id,
				'target_site_type' => 'virtual',
				'target_site_id'   => $virtual_site_id,
			);
		}

		// Prefer the shared identity resolver (mapping store first, then meta markers with self-heal).
		$existing_id = 0;
		if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			$found       = \WPTSALL\Sites\Services\Translation_Identity::find_target( (int) $source_id, $relation_id, true );
			$existing_id = $found ? (int) $found : 0;
		}

		// Fallback: meta-marker lookup, relation-scoped first so sibling relations cannot collide.
		if ( ! $existing_id && $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
					INNER JOIN %i pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wptsall_relation_id'
					WHERE pm1.meta_value = %s AND pm2.meta_value = %d AND pm3.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$virtual_site_id,
					$source_id,
					$relation_id
				)
			);
		}

		if ( ! $existing_id ) {
			// Legacy rows may predate `_wptsall_relation_id`; match on virtual site + source only.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
					WHERE pm1.meta_value = %s AND pm2.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$virtual_site_id,
					$source_id
				)
			);
		}

		$post_data = array(
			'post_title'   => $data['post_title'] ?? '',
			'post_content' => $data['post_content'] ?? '',
			'post_excerpt' => $data['post_excerpt'] ?? '',
			'post_status'  => $data['post_status'] ?? 'publish',
			'post_type'    => $post_type,
			'post_name'    => self::ensure_unique_post_slug(
				$data['post_name'] ?? '',
				$data['post_title'] ?? '',
				$post_type,
				$data['post_status'] ?? 'publish',
				(int) ( $data['post_parent'] ?? 0 ),
				(int) $existing_id,
				(int) $source_id
			),
			'post_date'    => $data['post_date'] ?? current_time( 'mysql' ),
			'post_author'  => get_current_user_id() ?: 1,
		);

		if ( $existing_id ) {
			// Update existing post.
			$post_data['ID'] = (int) $existing_id;

			if ( ! self::check_sync_conflict( (int) $existing_id, $relation_id, 'virtual_site' ) ) {
				return array( 'target_id' => (int) $existing_id, 'translations' => 0, 'skipped' => 'conflict' );
			}

			$result_id = wp_update_post( $post_data, true );
			if ( is_wp_error( $result_id ) ) {
				return $result_id;
			}
			$target_id = (int) $existing_id;
			$is_update = true;
		} else {
			// Create new post.
			$target_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $target_id ) ) {
				return $target_id;
			}
			$is_update = false;
		}

		// Persist virtual routing/identity markers through the shared identity writer.
		if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			\WPTSALL\Sites\Services\Translation_Identity::ensure_markers(
				(int) $target_id,
				(int) $source_id,
				$relation_id,
				array(
					'relation'        => $relation,
					'source_blog_id'  => (int) $source_blog_id,
					'virtual_site_id' => (string) $virtual_site_id,
					'post_type'       => (string) $post_type,
				)
			);
		} else {
			// No relation context: keep direct DB writes so content-plugin meta filters cannot intercept them.
			Direct_DB_Service::update_post_meta( $target_id, '_wptsall_virtual_site_id', $virtual_site_id );
			Direct_DB_Service::update_post_meta( $target_id, '_wptsall_source_post_id', $source_id );
			Direct_DB_Service::update_post_meta( $target_id, '_wptsall_source_blog_id', $source_blog_id );
		}
		Direct_DB_Service::update_post_meta( $target_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

		// Store translation meta.
		if ( isset( $data['_translation_meta'] ) ) {
			Direct_DB_Service::update_post_meta( $target_id, '_wptsall_translation_meta', $data['_translation_meta'] );
		}

		// Sync additional meta fields (attachment fields are remapped separately below).
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$handled_meta = array( '_thumbnail_id', '_product_image_gallery' );
			foreach ( $data['meta'] as $meta_key => $meta_value ) {
				if ( strpos( $meta_key, '_wptsall_' ) === 0 ) {
					continue;
				}
				if ( in_array( $meta_key, $handled_meta, true ) ) {
					continue;
				}
				if ( is_array( $meta_value ) && count( $meta_value ) > 1 ) {
					delete_post_meta( $target_id, $meta_key );
					foreach ( $meta_value as $single_value ) {
						add_post_meta( $target_id, $meta_key, $single_value );
					}
				} else {
					$value = is_array( $meta_value ) ? ( $meta_value[0] ?? '' ) : $meta_value;
					$value = Direct_DB_Service::normalize_meta_value( $value );
					update_post_meta( $target_id, $meta_key, $value );
				}
			}
		}

		// Remap featured image and product gallery through media mapping (parity with sync_post()).
		if ( $target_id && ! empty( $data['meta'] ) && is_array( $data['meta'] )
			&& class_exists( '\\WPTSALL\\Models\\Services\\Media_Mapping_Service' ) ) {
			$media_source_site = (int) $source_blog_id;
			$media_target_site = (string) $virtual_site_id;
			$media_lang        = (string) ( $relation['target_lang'] ?? '' );
			$media_config      = array(
				'copy_file'     => true,
				'translate_alt' => true,
				'relation_id'   => $relation_id,
			);

			$source_thumbnail_id = 0;
			if ( ! empty( $data['meta']['_thumbnail_id'] ) ) {
				$raw_thumb           = $data['meta']['_thumbnail_id'];
				$source_thumbnail_id = (int) ( is_array( $raw_thumb ) ? ( $raw_thumb[0] ?? 0 ) : $raw_thumb );
			}

			if ( $source_thumbnail_id ) {
				$target_media_id = \WPTSALL\Models\Services\Media_Mapping_Service::map_media_with_copy(
					$source_thumbnail_id,
					$media_source_site,
					$media_target_site,
					$media_lang,
					$media_config
				);
				if ( $target_media_id ) {
					Direct_DB_Service::update_post_meta( $target_id, '_thumbnail_id', $target_media_id );
				}
			}

			// Map WooCommerce product gallery (_product_image_gallery).
			if ( ! empty( $data['meta']['_product_image_gallery'] ) ) {
				$gallery_csv = is_array( $data['meta']['_product_image_gallery'] )
					? ( $data['meta']['_product_image_gallery'][0] ?? '' )
					: $data['meta']['_product_image_gallery'];
				$gallery_ids = array_filter( array_map( 'intval', explode( ',', (string) $gallery_csv ) ) );
				$gallery_ids = array_slice( $gallery_ids, 0, apply_filters( 'wptsall_max_gallery_media_per_sync', 20 ) );

				$new_gallery = array();
				foreach ( $gallery_ids as $gid ) {
					$mapped        = \WPTSALL\Models\Services\Media_Mapping_Service::map_media_with_copy(
						$gid,
						$media_source_site,
						$media_target_site,
						$media_lang,
						$media_config
					);
					$new_gallery[] = $mapped ? $mapped : $gid;
				}
				if ( ! empty( $new_gallery ) ) {
					Direct_DB_Service::update_post_meta( $target_id, '_product_image_gallery', implode( ',', $new_gallery ) );
				}
			}
		}

		// Register post mapping in the post_mappings store.
		if ( $target_id && class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) ) {
				\WPTSALL\Models\Services\Post_Mapping_Service::create_mapping(
					array(
						'source_post_id'    => (int) $source_id,
						'source_post_type'  => $post_type,
						'source_site_id'    => (int) $source_blog_id,
						'relation_id'       => (int) $relation_id,
						'target_post_id'    => (int) $target_id,
						'target_post_type'  => $post_type,
						'target_site_id'    => (string) $virtual_site_id,
					'relationship_type' => 'translation',
				)
			);
		}

		// Carry taxonomy term associations across to the target post.
		if ( $target_id && $relation_id > 0
			&& class_exists( '\\WPTSALL\\Sites\\Services\\Manual_Content_Service' )
			&& method_exists( '\\WPTSALL\\Sites\\Services\\Manual_Content_Service', 'sync_term_associations' ) ) {
			\WPTSALL\Sites\Services\Manual_Content_Service::sync_term_associations(
				(int) $source_id,
				(int) $target_id,
				$relation_id,
				$relation
			);
		}

		wptsall_log_info(
			'tasks-sync',
			'Virtual content synced to native wp_posts',
			array(
				'target_id'       => $target_id,
				'virtual_site_id' => $virtual_site_id,
				'source_id'       => $source_id,
				'post_type'       => $post_type,
				'is_update'       => $is_update,
			)
		);

		if ( $target_id && class_exists( '\\WPTSALL\\Hooks\\Elementor_Data_Url_Rewriter' ) ) {
			$vs = \WPTSALL\Sites\Services\Virtual_Site_Service::get( $virtual_site_id );
			if ( is_array( $vs ) ) {
				$raw = get_post_meta( $target_id, '_elementor_data', true );
				if ( '' !== $raw && false !== $raw && null !== $raw ) {
					$rewritten = \WPTSALL\Hooks\Elementor_Data_Url_Rewriter::rewrite_meta_value( $raw, $vs );
					if ( $rewritten !== $raw ) {
						update_post_meta( $target_id, '_elementor_data', $rewritten );
					}
				}
			}
		}

		return array(
			'target_id'    => $target_id,
			'translations' => $translations,
		);
	}

	/**
	 * Sync a term to virtual site using native wp_terms storage.
	 *
	 * @param array  $data             Processed data.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $virtual_site_id  Virtual site identifier.
	 * @param int    $source_blog_id   Source blog ID.
	 * @param int    $source_id        Source term ID.
	 * @param int    $relation_id      Relation ID.
	 * @param int    $translations     Translation count.
	 * @return array|WP_Error Sync result.
	 */
	private static function sync_term_to_virtual_site( $data, $taxonomy, $virtual_site_id, $source_blog_id, $source_id, $relation_id, $translations ) {
		global $wpdb;

		// Check if already exists via meta markers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT t.term_id FROM %i t
				INNER JOIN %i tm1 ON t.term_id = tm1.term_id AND tm1.meta_key = '_wptsall_virtual_site_id'
				INNER JOIN %i tm2 ON t.term_id = tm2.term_id AND tm2.meta_key = '_wptsall_source_term_id'
				WHERE tm1.meta_value = %s AND tm2.meta_value = %d
				LIMIT 1",
				$wpdb->terms,
				$wpdb->termmeta,
				$wpdb->termmeta,
				$virtual_site_id,
				$source_id
			)
		);

		$term_name = $data['name'] ?? '';
		$term_args = array(
			'slug'        => self::ensure_unique_term_slug(
				$data['slug'] ?? '',
				$term_name,
				$taxonomy,
				(int) $existing_id,
				(int) $source_id
			),
			'description' => $data['description'] ?? '',
		);

		if ( $existing_id ) {
			if ( ! self::check_term_sync_conflict( (int) $existing_id, $relation_id, 'virtual_term' ) ) {
				return array( 'target_id' => (int) $existing_id, 'translations' => 0, 'skipped' => 'conflict' );
			}

			// Update existing term.
			$result = wp_update_term( (int) $existing_id, $taxonomy, array_merge( array( 'name' => $term_name ), $term_args ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$target_id = (int) $existing_id;
		} else {
			// Create new term.
			$result = wp_insert_term( $term_name, $taxonomy, $term_args );
			if ( is_wp_error( $result ) ) {
				// If term exists by slug, use that.
				if ( $result->get_error_code() === 'term_exists' ) {
					$existing_term = get_term_by( 'slug', $term_args['slug'], $taxonomy );
					if ( $existing_term ) {
						$target_id = $existing_term->term_id;
					} else {
						return $result;
					}
				} else {
					return $result;
				}
			} else {
				$target_id = $result['term_id'];
			}
		}

		// Identity markers on create and update (heal missing meta without rewriting the term engine).
		// Posts use Translation_Identity::ensure_markers; terms keep explicit meta + Term_Mapping_Service below.
		$target_id = (int) $target_id;
		if ( $target_id > 0 ) {
			update_term_meta( $target_id, '_wptsall_virtual_site_id', $virtual_site_id );
			update_term_meta( $target_id, '_wptsall_source_term_id', $source_id );
			update_term_meta( $target_id, '_wptsall_source_blog_id', $source_blog_id );
			update_term_meta( $target_id, '_wptsall_relation_id', $relation_id );
		}

		// Update sync timestamp.
		\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $target_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

		// Sync additional term meta fields.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $meta_key => $meta_value ) {
				if ( str_starts_with( $meta_key, '_wptsall_' ) ) {
					continue;
				}
				if ( is_array( $meta_value ) && count( $meta_value ) > 1 ) {
					delete_term_meta( $target_id, $meta_key );
					foreach ( $meta_value as $single_value ) {
						add_term_meta( $target_id, $meta_key, $single_value );
					}
				} else {
					$value = is_array( $meta_value ) ? ( $meta_value[0] ?? '' ) : $meta_value;
					update_term_meta( $target_id, $meta_key, $value );
				}
			}
		}

		// Register virtual term mapping in the new term_mappings store.
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
			\WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
				array(
					'source_term_id'     => (int) $source_id,
					'source_taxonomy'    => $taxonomy,
					'source_site_id'     => (int) $source_blog_id,
					'relation_id'        => (int) $relation_id,
					'source_lang'        => '',
					'target_term_id'     => $target_id,
					'target_taxonomy'    => $taxonomy,
					'target_site_id'     => $virtual_site_id,
					'target_lang'        => '',
					'mapping_method'     => 'auto_create',
					'translation_method' => 'sync_executor_virtual',
				)
			);
		}

		wptsall_log_info(
			'tasks-sync',
			'Virtual term synced to native wp_terms',
			array(
				'target_id'       => $target_id,
				'virtual_site_id' => $virtual_site_id,
				'source_id'       => $source_id,
				'taxonomy'        => $taxonomy,
			)
		);

		return array(
			'target_id'    => $target_id,
			'translations' => $translations,
		);
	}

	/**
	 * Execute i18n sync: write translated entries to template_entries table.
	 *
	 * Unlike post/term sync, language pack sync writes back to the same
	 * database (template_entries) rather than to a target site.
	 *
	 * @since 1.3.0
	 *
	 * @param int   $sync_task_id          Sync task ID.
	 * @param int   $translation_result_id Translation result ID.
	 * @param array $translated_fields     Translated fields from translation_results.
	 * @param int   $relation_id           Site relation ID.
	 * @return array|\WP_Error Result or error.
	 */
	private static function execute_i18n_sync( $sync_task_id, $translation_result_id, $translated_fields, $relation_id ) {
		global $wpdb;

		$entries = $translated_fields['entries'] ?? array();

		if ( empty( $entries ) || ! is_array( $entries ) ) {
			self::update_task_status( $sync_task_id, 'failed', array(
				'error' => 'No entries in translation result',
			) );
			return new \WP_Error( 'no_entries', 'No entries in translation result' );
		}

		// Get the translation result creation time as a cutoff — do not overwrite
		// entries that were updated after this result was produced (MED-12).
		$results_table    = wptsall_table( 'translation_results' );
		$result_created_at = $wpdb->get_var( $wpdb->prepare(
			"SELECT created_at FROM %i WHERE id = %d",
			$results_table, $translation_result_id
		) );

		$entries_table   = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );
		$now             = current_time( 'mysql', true );
		$updated_count   = 0;
		$failed_count    = 0;
		$skipped_count   = 0;
		$deployment      = array(
			'attempted' => false,
			'deployed'  => false,
			'skipped'   => true,
			'reason'    => 'not_applicable',
		);

		foreach ( $entries as $entry ) {
			$entry_id = absint( $entry['entry_id'] ?? 0 );
			$msgstr   = $entry['msgstr'] ?? '';

			if ( empty( $entry_id ) || '' === $msgstr ) {
				++$failed_count;
				continue;
			}

			// Verify entry belongs to this relation and has not been updated
			// after this translation result was created (prevents overwriting
			// newer manual translations on retry).
			if ( $result_created_at ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i e INNER JOIN %i t ON e.template_id = t.id
						 SET e.msgstr = %s, e.status = 'translated', e.claimed_at = NULL, e.updated_at = %s
						 WHERE e.id = %d AND t.relation_id = %d AND e.updated_at <= %s",
						$entries_table,
						$templates_table,
						$msgstr,
						$now,
						$entry_id,
						$relation_id,
						$result_created_at
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i e INNER JOIN %i t ON e.template_id = t.id
						 SET e.msgstr = %s, e.status = 'translated', e.claimed_at = NULL, e.updated_at = %s
						 WHERE e.id = %d AND t.relation_id = %d",
						$entries_table,
						$templates_table,
						$msgstr,
						$now,
						$entry_id,
						$relation_id
					)
				);
			}

			if ( false !== $result && $result > 0 ) {
				++$updated_count;
				do_action(
					'wptsall_entry_updated',
					$entry_id,
					array(
						'text_domain' => '',
					)
				);
			} elseif ( 0 === $result ) {
				++$skipped_count;
			} else {
				++$failed_count;
			}
		}

		// Mark task completed or partial depending on entry results.
		$final_status = ( $failed_count > 0 || 0 === $updated_count ) ? 'partial' : 'completed';
		self::update_task_status( $sync_task_id, $final_status, array(
			'entries_updated' => $updated_count,
			'entries_skipped' => $skipped_count,
			'entries_failed'  => $failed_count,
			'deployment'      => $deployment,
		) );
		self::update_translation_result_status( $translation_result_id, 'completed' === $final_status ? 'synced' : 'partial' );

		wptsall_log_info( 'tasks-sync', 'i18n translation sync completed', array(
			'sync_task_id'    => $sync_task_id,
			'relation_id'     => $relation_id,
			'entries_updated' => $updated_count,
			'entries_skipped' => $skipped_count,
			'entries_failed'  => $failed_count,
			'deployment'      => $deployment,
		) );

		return array(
			'success'         => true,
			'task_id'         => $sync_task_id,
			'entries_updated' => $updated_count,
			'deployment'      => $deployment,
		);
	}

	/**
	 * Update task status.
	 *
	 * @param int    $task_id     Task ID.
	 * @param string $status      New status.
	 * @param array  $result_data Result data to store.
	 */
	private static function update_task_status( $task_id, $status, $result_data = array() ) {
		global $wpdb;

		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$update_result = $wpdb->update(
			$table,
			array(
				'status'      => $status,
				'status_note' => wp_json_encode( $result_data ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $task_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $update_result ) {
			wptsall_log_warning( 'tasks-sync', 'Failed to update task status', array(
				'task_id'  => $task_id,
				'status'   => $status,
				'db_error' => $wpdb->last_error,
			) );
		}
	}

	/**
	 * Check for write-back conflict: target was edited after last sync.
	 *
	 * Returns true if the sync should proceed, false if it should be skipped
	 * (only when a filter changes the strategy to 'skip').
	 *
	 * @since 1.5.0
	 *
	 * @param int    $target_id  Target post ID.
	 * @param int    $relation_id Relation ID.
	 * @param string $sync_path  Sync path identifier ('sync_post', 'self_translation', 'virtual_site').
	 * @return bool True to proceed with sync, false to skip.
	 */
	private static function check_sync_conflict( $target_id, $relation_id, $sync_path ) {
		$last_synced = (string) \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $target_id, '_wptsall_last_synced' );

		// No previous sync — always proceed (first-time sync).
		if ( empty( $last_synced ) ) {
			return true;
		}

		$target = get_post( $target_id );
		if ( ! $target ) {
			return true;
		}

		// Compare local timestamps (post_modified vs _wptsall_last_synced).
		// _wptsall_last_synced is stored as UTC database time.
		$post_modified = $target->post_modified ?? '';
		if ( ! empty( $post_modified ) && strtotime( $post_modified ) <= strtotime( $last_synced ) ) {
			return true; // No conflict.
		}

		/**
		 * Filter the conflict resolution strategy.
		 *
		 * @since 1.5.0
		 *
		 * @param string $strategy    'log_and_overwrite' (default) or 'skip'.
		 * @param int    $target_id   Target post ID.
		 * @param int    $relation_id Relation ID.
		 * @param string $sync_path   Sync path ('sync_post', 'self_translation', 'virtual_site').
		 * @param string $last_synced Last sync timestamp.
		 */
		$strategy = apply_filters( 'wptsall_sync_conflict_strategy', 'log_and_overwrite', $target_id, $relation_id, $sync_path, $last_synced );

		wptsall_log_warning( 'tasks-sync', 'Sync conflict detected: target modified after last sync', array(
			'target_id'     => $target_id,
			'relation_id'   => $relation_id,
			'sync_path'     => $sync_path,
			'last_synced'   => $last_synced,
			'post_modified' => $post_modified,
			'post_modified_gmt' => $target->post_modified_gmt,
			'strategy'      => $strategy,
		) );

		return 'skip' !== $strategy;
	}

	/**
	 * Check term sync conflict based on manual-edit marker.
	 *
	 * WordPress terms do not provide a native modified timestamp. We therefore
	 * compare `_wptsall_last_manual_edit` against `_wptsall_last_synced`.
	 *
	 * @since 1.5.0
	 *
	 * @param int    $target_term_id Target term ID.
	 * @param int    $relation_id    Relation ID.
	 * @param string $sync_path      Sync path identifier.
	 * @return bool True to proceed with sync, false to skip.
	 */
	private static function check_term_sync_conflict( $target_term_id, $relation_id, $sync_path ) {
		$last_synced = wptsall_raw_term_meta( (int) $target_term_id, '_wptsall_last_synced' );
		if ( empty( $last_synced ) ) {
			return true;
		}

		$last_manual_edit = get_term_meta( $target_term_id, '_wptsall_last_manual_edit', true );
		if ( empty( $last_manual_edit ) ) {
			return true;
		}

		if ( strtotime( $last_manual_edit ) <= strtotime( $last_synced ) ) {
			return true;
		}

		/**
		 * Filter the term conflict resolution strategy.
		 *
		 * @since 1.5.0
		 *
		 * @param string $strategy         'log_and_overwrite' (default) or 'skip'.
		 * @param int    $target_term_id   Target term ID.
		 * @param int    $relation_id      Relation ID.
		 * @param string $sync_path        Sync path identifier.
		 * @param string $last_synced      Last sync timestamp.
		 * @param string $last_manual_edit Last manual edit timestamp.
		 */
		$strategy = apply_filters(
			'wptsall_term_sync_conflict_strategy',
			'log_and_overwrite',
			$target_term_id,
			$relation_id,
			$sync_path,
			$last_synced,
			$last_manual_edit
		);

		wptsall_log_warning( 'tasks-sync', 'Term sync conflict detected: target term edited after last sync', array(
			'target_term_id'   => $target_term_id,
			'relation_id'      => $relation_id,
			'sync_path'        => $sync_path,
			'last_synced'      => $last_synced,
			'last_manual_edit' => $last_manual_edit,
			'strategy'         => $strategy,
		) );

		return 'skip' !== $strategy;
	}
// phpcs:enable
}
