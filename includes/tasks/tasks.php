<?php
/**
 * Tasks core functions.
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

/**
 * Get task parameters settings.
 *
 * This function is globally available for use by core modules (cache, automation-cron).
 *
 * @since 0.9.0
 * @since 0.9.1 Added loop control, dedup, and resource limit parameters (ISS-TSK-027).
 * @return array Task parameters with defaults.
 */
function wptsall_get_task_parameters() {
	$defaults = array(
		// Environment preset (v0.9.1).
		'environment_preset'       => 'vps_medium',

		// Cron intervals (in minutes).
		'high_priority_interval'   => 5,
		'normal_priority_interval' => 15,
		'low_priority_interval'    => 30,
		'retry_interval'           => 60,

		// Batch sizes.
		'high_priority_batch'      => 10,
		'normal_priority_batch'    => 20,
		'low_priority_batch'       => 5,
		'monitoring_batch'         => 50,

		// Retry settings.
		'max_retry_count'          => 3,
		'retry_delay_1'            => 5,   // First retry delay (minutes).
		'retry_delay_2'            => 15,  // Second retry delay (minutes).
		'retry_delay_3'            => 60,  // Third retry delay (minutes).

		// Cleanup settings.
		'cleanup_completed_days'   => 7,   // Days to keep completed tasks.
		'cleanup_failed_days'      => 30,  // Days to keep failed tasks.

		// Loop control (v0.9.1 - prevent infinite loops).
		'max_cycles_per_run'       => 10,   // Max cycles per cron run.
		'max_items_per_cycle'      => 100,  // Max items per cycle (alias for monitoring_batch).
		'max_total_items_per_run'  => 1000, // Max total items per cron run.
		'cycle_cooldown_seconds'   => 1,    // Cooldown between cycles (seconds).

		// Dedup control (v0.9.1).
		'enable_dedup'             => true,  // Enable deduplication.
		'dedup_window_seconds'     => 300,   // Dedup window (5 minutes).

		// Timeout settings (v0.9.1 enhanced).
		'task_timeout'             => 300,  // Single task timeout (seconds).
		'run_timeout'              => 600,  // Total cron run timeout (seconds).
		'item_timeout'             => 30,   // Single item timeout (seconds).

		// Resource limits (v0.9.1).
		'memory_limit_percent'     => 80,   // Max memory usage (percent).
		'enable_gc_between_items'  => false, // Force GC between items.

		// Logging control (v0.9.1).
		'log_level'                => 'info', // debug | info | warning | error.
		'log_progress_interval'    => 100,    // Log progress every N items.

		// Concurrency settings (reserved for ISS-TSK-029).
		'enable_parallel'          => false,
		'max_concurrent_tasks'     => 3,
		'enable_task_locking'      => true,

		// Cache TTL settings (in minutes).
		'cache_default_ttl'        => 60,   // Default cache expiration (1 hour).
		'cache_templates_ttl'      => 120,  // Templates cache TTL (2 hours).
		'cache_sites_ttl'          => 120,  // Sites/Virtual sites cache TTL (2 hours).
		'cache_stats_ttl'          => 5,    // Task/Hook stats cache TTL (5 minutes).
	);

	$saved = get_option( 'wptsall_task_parameters', array() );
	return wp_parse_args( $saved, $defaults );
}

/**
 * Get environment presets for task parameters.
 *
 * @since 0.9.1
 * @return array Environment presets.
 */
function wptsall_get_environment_presets() {
	return array(
		'shared_hosting' => array(
			'label'                   => __( 'Shared Hosting', 'wpmmcc-ats' ),
			'description'             => __( 'Conservative settings for shared hosting environments', 'wpmmcc-ats' ),
			'max_cycles_per_run'      => 5,
			'max_items_per_cycle'     => 50,
			'max_total_items_per_run' => 250,
			'monitoring_batch'        => 30,
			'task_timeout'            => 120,
			'run_timeout'             => 300,
			'item_timeout'            => 15,
			'memory_limit_percent'    => 60,
			'cycle_cooldown_seconds'  => 2,
		),
		'vps_small'      => array(
			'label'                   => __( 'VPS Small (1-2 CPU, 2GB RAM)', 'wpmmcc-ats' ),
			'description'             => __( 'Balanced settings for small VPS instances', 'wpmmcc-ats' ),
			'max_cycles_per_run'      => 10,
			'max_items_per_cycle'     => 100,
			'max_total_items_per_run' => 500,
			'monitoring_batch'        => 50,
			'task_timeout'            => 300,
			'run_timeout'             => 600,
			'item_timeout'            => 30,
			'memory_limit_percent'    => 70,
			'cycle_cooldown_seconds'  => 1,
		),
		'vps_medium'     => array(
			'label'                   => __( 'VPS Medium (2-4 CPU, 4GB RAM)', 'wpmmcc-ats' ),
			'description'             => __( 'Default settings for medium VPS instances', 'wpmmcc-ats' ),
			'max_cycles_per_run'      => 15,
			'max_items_per_cycle'     => 200,
			'max_total_items_per_run' => 1000,
			'monitoring_batch'        => 100,
			'task_timeout'            => 300,
			'run_timeout'             => 900,
			'item_timeout'            => 30,
			'memory_limit_percent'    => 75,
			'cycle_cooldown_seconds'  => 1,
		),
		'vps_large'      => array(
			'label'                   => __( 'VPS Large (4+ CPU, 8GB+ RAM)', 'wpmmcc-ats' ),
			'description'             => __( 'Aggressive settings for powerful VPS instances', 'wpmmcc-ats' ),
			'max_cycles_per_run'      => 20,
			'max_items_per_cycle'     => 500,
			'max_total_items_per_run' => 2000,
			'monitoring_batch'        => 200,
			'task_timeout'            => 300,
			'run_timeout'             => 1800,
			'item_timeout'            => 30,
			'memory_limit_percent'    => 80,
			'cycle_cooldown_seconds'  => 0,
		),
		'custom'         => array(
			'label'       => __( 'Custom', 'wpmmcc-ats' ),
			'description' => __( 'Manually configured settings', 'wpmmcc-ats' ),
		),
	);
}


/**
 * Save task parameters settings.
 *
 * @since 0.9.0
 * @since 0.9.1 Added loop control, dedup, and resource limit parameters (ISS-TSK-027).
 * @param array $params Parameters to save.
 * @return bool True on success, false on failure.
 */
function wptsall_save_task_parameters( $params ) {
	$valid_presets = array( 'shared_hosting', 'vps_small', 'vps_medium', 'vps_large', 'custom' );
	$valid_log_levels = array( 'debug', 'info', 'warning', 'error' );

	$sanitized = array(
		// Environment preset (v0.9.1).
		'environment_preset'       => in_array( $params['environment_preset'] ?? 'vps_medium', $valid_presets, true )
			? $params['environment_preset']
			: 'vps_medium',

		// Cron intervals (in minutes).
		'high_priority_interval'   => max( 1, min( 60, intval( $params['high_priority_interval'] ?? 5 ) ) ),
		'normal_priority_interval' => max( 5, min( 120, intval( $params['normal_priority_interval'] ?? 15 ) ) ),
		'low_priority_interval'    => max( 10, min( 240, intval( $params['low_priority_interval'] ?? 30 ) ) ),
		'retry_interval'           => max( 30, min( 1440, intval( $params['retry_interval'] ?? 60 ) ) ),

		// Batch sizes.
		'high_priority_batch'      => max( 1, min( 100, intval( $params['high_priority_batch'] ?? 10 ) ) ),
		'normal_priority_batch'    => max( 1, min( 100, intval( $params['normal_priority_batch'] ?? 20 ) ) ),
		'low_priority_batch'       => max( 1, min( 50, intval( $params['low_priority_batch'] ?? 5 ) ) ),
		'monitoring_batch'         => max( 10, min( 500, intval( $params['monitoring_batch'] ?? 50 ) ) ),

		// Retry settings.
		'max_retry_count'          => max( 1, min( 10, intval( $params['max_retry_count'] ?? 3 ) ) ),
		'retry_delay_1'            => max( 1, min( 60, intval( $params['retry_delay_1'] ?? 5 ) ) ),
		'retry_delay_2'            => max( 5, min( 120, intval( $params['retry_delay_2'] ?? 15 ) ) ),
		'retry_delay_3'            => max( 15, min( 1440, intval( $params['retry_delay_3'] ?? 60 ) ) ),

		// Cleanup settings.
		'cleanup_completed_days'   => max( 1, min( 365, intval( $params['cleanup_completed_days'] ?? 7 ) ) ),
		'cleanup_failed_days'      => max( 7, min( 365, intval( $params['cleanup_failed_days'] ?? 30 ) ) ),

		// Loop control (v0.9.1).
		'max_cycles_per_run'       => max( 1, min( 100, intval( $params['max_cycles_per_run'] ?? 10 ) ) ),
		'max_items_per_cycle'      => max( 10, min( 1000, intval( $params['max_items_per_cycle'] ?? 100 ) ) ),
		'max_total_items_per_run'  => max( 50, min( 10000, intval( $params['max_total_items_per_run'] ?? 1000 ) ) ),
		'cycle_cooldown_seconds'   => max( 0, min( 10, intval( $params['cycle_cooldown_seconds'] ?? 1 ) ) ),

		// Dedup control (v0.9.1).
		'enable_dedup'             => ! empty( $params['enable_dedup'] ?? true ),
		'dedup_window_seconds'     => max( 60, min( 3600, intval( $params['dedup_window_seconds'] ?? 300 ) ) ),

		// Timeout settings (v0.9.1).
		'task_timeout'             => max( 60, min( 900, intval( $params['task_timeout'] ?? 300 ) ) ),
		'run_timeout'              => max( 120, min( 3600, intval( $params['run_timeout'] ?? 600 ) ) ),
		'item_timeout'             => max( 5, min( 120, intval( $params['item_timeout'] ?? 30 ) ) ),

		// Resource limits (v0.9.1).
		'memory_limit_percent'     => max( 50, min( 95, intval( $params['memory_limit_percent'] ?? 80 ) ) ),
		'enable_gc_between_items'  => ! empty( $params['enable_gc_between_items'] ),

		// Logging control (v0.9.1).
		'log_level'                => in_array( $params['log_level'] ?? 'info', $valid_log_levels, true )
			? $params['log_level']
			: 'info',
		'log_progress_interval'    => max( 10, min( 1000, intval( $params['log_progress_interval'] ?? 100 ) ) ),

		// Concurrency settings (reserved for ISS-TSK-029).
		'enable_parallel'          => ! empty( $params['enable_parallel'] ),
		'max_concurrent_tasks'     => max( 1, min( 10, intval( $params['max_concurrent_tasks'] ?? 3 ) ) ),
		'enable_task_locking'      => ! empty( $params['enable_task_locking'] ?? true ),

		// Cache TTL settings (in minutes).
		'cache_default_ttl'        => max( 5, min( 1440, intval( $params['cache_default_ttl'] ?? 60 ) ) ),
		'cache_templates_ttl'      => max( 10, min( 1440, intval( $params['cache_templates_ttl'] ?? 120 ) ) ),
		'cache_sites_ttl'          => max( 10, min( 1440, intval( $params['cache_sites_ttl'] ?? 120 ) ) ),
		'cache_stats_ttl'          => max( 1, min( 60, intval( $params['cache_stats_ttl'] ?? 5 ) ) ),
	);

	$result = update_option( 'wptsall_task_parameters', $sanitized );

	// Reschedule cron jobs if intervals changed.
	if ( function_exists( 'wptsall_reschedule_cron_jobs' ) ) {
		wptsall_reschedule_cron_jobs( $sanitized );
	}

	return $result;
}

/**
 * Validate task creation for a site relation.
 *
 * Checks:
 * 1. Relation exists and is active
 * 2. Model exists for the template
 * 3. Model has translation rules
 *
 * @param int $relation_id Site relation ID.
 * @return true|WP_Error True if valid, WP_Error otherwise.
 */
function wptsall_validate_task_creation( $relation_id ) {
    // 1. Get and validate relation.
    $relation = Site_Relation_Service::get_relation( $relation_id );

    if ( ! $relation ) {
        return new WP_Error( 'invalid_relation', __( 'Site relation does not exist', 'wpmmcc-ats' ) );
    }

    if ( 'active' !== $relation['status'] ) {
        return new WP_Error( 'inactive_relation', __( 'Site relation is not active', 'wpmmcc-ats' ) );
    }

    $template = $relation['template'] ?? '';
    if ( ! $template ) {
        return new WP_Error( 'no_template', __( 'Site relation is missing model configuration', 'wpmmcc-ats' ) );
    }

    // 2. Check model exists.
    $model = Translation_Rule_Service::get_model( $template );

    if ( ! $model ) {
        return new WP_Error( 'no_model', __( 'No corresponding model for this plugin', 'wpmmcc-ats' ) );
    }

    // 3. Check model has translation rules.
    if ( ! wptsall_model_has_translation_rules( $model['id'] ) ) {
        return new WP_Error(
            'no_rules',
            __( 'Please complete the model rules first', 'wpmmcc-ats' )
        );
    }

    return true;
}

/**
 * Check if a model has translation rules.
 *
 * @param int $model_id Model ID.
 * @return bool True if model has rules.
 */
function wptsall_model_has_translation_rules( $model_id ) {
    global $wpdb;

    $rules_table = wptsall_table( 'translation_rules' );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE model_id = %d',
            $rules_table,
            $model_id
        )
    );

	return $count > 0;
}

/**
 * Normalize client task type value.
 *
 * @param string $raw_type Raw task type.
 * @return string
 */
function wptsall_normalize_client_task_type( $raw_type ) {
	$type = sanitize_key( strtolower( trim( (string) $raw_type ) ) );
	if ( in_array( $type, array( 'text', 'image', 'video', 'audio', 'document', 'mixed' ), true ) ) {
		return $type;
	}
	if ( in_array( $type, array( 'text_translation', 'field', 'fields' ), true ) ) {
		return 'text';
	}
	if ( in_array( $type, array( 'image_translation', 'images' ), true ) ) {
		return 'image';
	}
	if ( in_array( $type, array( 'video_translation', 'videos' ), true ) ) {
		return 'video';
	}
	if ( in_array( $type, array( 'audio_translation', 'audios' ), true ) ) {
		return 'audio';
	}
	if ( in_array( $type, array( 'document_translation', 'documents', 'doc', 'file', 'files' ), true ) ) {
		return 'document';
	}
	return 'text';
}

/**
 * Generate a task job id.
 *
 * @param string $context Context hint.
 * @return string
 */
function wptsall_generate_task_job_id( $context = '' ) {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		$uuid = str_replace( '-', '', wp_generate_uuid4() );
		return 'job_' . sanitize_key( strtolower( $uuid ) );
	}
	$seed = (string) microtime( true ) . '|' . (string) wp_rand() . '|' . $context;
	return 'job_' . sanitize_key( substr( md5( $seed ), 0, 24 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.md5_md5
}

/**
 * Normalize business line value.
 *
 * @param string $raw Raw value.
 * @return string
 */
function wptsall_normalize_business_line( $raw ) {
	$value = sanitize_key( strtolower( trim( (string) $raw ) ) );
	switch ( $value ) {
		case 'post':
		case 'post_type':
		case 'post_content':
			return 'post_content';
		case 'taxonomy':
		case 'term':
		case 'taxonomy_content':
			return 'taxonomy_content';
		case 'theme':
		case 'theme_i18n':
			return 'theme_i18n';
		case 'plugin':
		case 'plugin_i18n':
		case 'language_pack':
		case 'language_pack_i18n':
			return 'plugin_i18n';
		case 'custom_model':
		case 'model':
		case 'custom':
			return 'custom_model';
		default:
			return '';
	}
}

/**
 * Infer business line from task payload.
 *
 * @param array $task Task data.
 * @return string
 */
function wptsall_infer_business_line_from_task( $task ) {
	if ( ! is_array( $task ) ) {
		return 'custom_model';
	}

	$explicit = wptsall_normalize_business_line( $task['business_line'] ?? '' );
	if ( '' !== $explicit ) {
		return $explicit;
	}

	$object_type = sanitize_key( (string) ( $task['object_type'] ?? '' ) );
	$subtype     = sanitize_key( (string) ( $task['subtype'] ?? '' ) );

	if ( 'post_type' === $object_type || 'post' === $object_type ) {
		return 'post_content';
	}
	if ( 'taxonomy' === $object_type || 'term' === $object_type ) {
		return 'taxonomy_content';
	}
	if ( 'language_pack' === $object_type ) {
		if ( 'theme' === $subtype || 'theme_i18n' === $subtype ) {
			return 'theme_i18n';
		}
		return 'plugin_i18n';
	}

	return 'custom_model';
}

/**
 * Build object_ref structure for task payload.
 *
 * @param array $task Task data.
 * @return array
 */
function wptsall_build_task_object_ref( $task ) {
	if ( ! is_array( $task ) ) {
		return array();
	}

	$object_type = sanitize_key( (string) ( $task['object_type'] ?? '' ) );
	$subtype     = sanitize_key( (string) ( $task['subtype'] ?? '' ) );
	$object_id   = intval( $task['object_id'] ?? 0 );

	return array(
		'object_type'       => $object_type,
		'subtype'           => $subtype,
		'object_id'         => $object_id,
		'source_blog_id'    => intval( $task['blog_id'] ?? 0 ),
		'target_blog_id'    => intval( $task['target_blog'] ?? 0 ),
		'target_type'       => sanitize_key( (string) ( $task['target_type'] ?? '' ) ),
		'target_identifier' => sanitize_text_field( (string) ( $task['target_identifier'] ?? '' ) ),
		'site_id'           => intval( $task['site_id'] ?? 0 ),
	);
}

/**
 * Normalize a task array for planner protocol compliance.
 *
 * Ensures every task written to the tasks table has:
 * - job_id: unique job identifier
 * - business_line: inferred or explicit business line
 * - object_ref: structured object reference
 * - source: audit trail field identifying the creation path
 *
 * Entry points that bypass Task_Job_Planner should call this function
 * before inserting tasks. Entry points that go through the planner
 * already get job_id/business_line via prepare_and_plan_tasks().
 *
 * @since 1.0.5
 *
 * @param array  $task   Task array (will be modified in place).
 * @param string $source Audit source identifier (e.g. 'rest_create', 'hook_manager', 'cron_insert').
 * @return array Normalized task array.
 */
function wptsall_normalize_task_for_planner( $task, $source = '' ) {
	if ( ! is_array( $task ) ) {
		return array();
	}

	// Ensure job_id.
	if ( empty( $task['job_id'] ) ) {
		$task['job_id'] = wptsall_generate_task_job_id(
			sanitize_key( (string) ( $task['template'] ?? '' ) )
		);
	}

	// Ensure business_line.
	if ( empty( $task['business_line'] ) ) {
		$task['business_line'] = wptsall_infer_business_line_from_task( $task );
	}

	// Ensure object_ref.
	if ( empty( $task['object_ref'] ) || ! is_array( $task['object_ref'] ) ) {
		$task['object_ref'] = wptsall_build_task_object_ref( $task );
	}

	// Set audit source field.
	if ( '' !== $source ) {
		$task['source'] = sanitize_key( $source );
	} elseif ( empty( $task['source'] ) ) {
		$task['source'] = 'unknown';
	}

	return $task;
}

/**
 * Extract source text from payload value.
 *
 * @param mixed $value Raw payload value.
 * @return string
 */
function wptsall_extract_client_source_text( $value ) {
	if ( is_array( $value ) ) {
		$candidate_keys = array(
			'source_text',
			'text',
			'value',
			'source',
			'caption',
			'title',
			'description',
			'transcript',
			'prompt',
			'content',
			'msgid',
			'name',
		);
		foreach ( $candidate_keys as $key ) {
			if ( isset( $value[ $key ] ) ) {
				$text = wptsall_extract_client_source_text( $value[ $key ] );
				if ( '' !== trim( $text ) ) {
					return $text;
				}
			}
		}
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( is_numeric( $value ) ) {
		return (string) $value;
	}
	if ( is_string( $value ) ) {
		return $value;
	}
	return '';
}

/**
 * Normalize subtasks/content_items payload list.
 *
 * @param mixed  $items         Raw items.
 * @param string $fallback_type Default type.
 * @param string $key_prefix    Key prefix.
 * @return array
 */
function wptsall_normalize_client_subtasks_list( $items, $fallback_type = 'text', $key_prefix = 'subtask' ) {
	$subtasks = array();
	$seen     = array();

	if ( ! is_array( $items ) ) {
		return $subtasks;
	}

	foreach ( array_values( $items ) as $index => $item ) {
		$item_array = is_array( $item ) ? $item : array( 'value' => $item );
		$raw_type   = (string) ( $item_array['type'] ?? ( $item_array['kind'] ?? ( $item_array['task_type'] ?? $fallback_type ) ) );
		$type       = wptsall_normalize_client_task_type( $raw_type );
		$raw_key    = (string) ( $item_array['key'] ?? ( $item_array['id'] ?? ( $item_array['entry_id'] ?? '' ) ) );
		$key        = '' !== trim( $raw_key )
			? sanitize_key( $raw_key )
			: sanitize_key( $key_prefix . '_' . ( (int) $index + 1 ) );
		if ( '' === $key ) {
			$key = sanitize_key( $key_prefix . '_' . ( (int) $index + 1 ) );
		}

		$dedupe = $type . '|' . $key;
		if ( isset( $seen[ $dedupe ] ) ) {
			continue;
		}
		$seen[ $dedupe ] = true;

		$subtask = array(
			'type'        => $type,
			'task_type'   => $type,
			'key'         => $key,
			'source_text' => wptsall_extract_client_source_text( $item_array ),
		);

		// Preserve enriched media metadata when present.
		$source_ref = sanitize_text_field( (string) ( $item_array['source_ref'] ?? '' ) );
		if ( '' !== $source_ref ) {
			$subtask['source_ref'] = $source_ref;
		}
		if ( is_array( $item_array['source_payload'] ?? null ) && ! empty( $item_array['source_payload'] ) ) {
			$subtask['source_payload'] = $item_array['source_payload'];
		}

		$subtasks[] = $subtask;
		$target_path = sanitize_text_field(
			(string) ( $item_array['target_path'] ?? ( $item_array['path'] ?? '' ) )
		);
		if ( '' !== $target_path && preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
			$subtasks[ count( $subtasks ) - 1 ]['target_path'] = $target_path;
		}
		$target_paths = array();
		foreach ( array( 'target_paths', 'field_targets', 'targets' ) as $map_key ) {
			if ( ! is_array( $item_array[ $map_key ] ?? null ) ) {
				continue;
			}
			foreach ( $item_array[ $map_key ] as $raw_field => $raw_path ) {
				$field = sanitize_key( (string) $raw_field );
				$path  = sanitize_text_field( (string) $raw_path );
				if ( '' === $field || '' === $path ) {
					continue;
				}
				if ( ! preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $path ) ) {
					continue;
				}
				$target_paths[ $field ] = $path;
			}
		}
		if ( ! empty( $target_paths ) ) {
			$subtasks[ count( $subtasks ) - 1 ]['target_paths'] = $target_paths;
		}
	}

	return $subtasks;
}

/**
 * Build text subtasks from fields map.
 *
 * @param mixed  $fields        Fields map.
 * @param string $fallback_type Default task type.
 * @return array
 */
function wptsall_build_subtasks_from_fields_map( $fields, $fallback_type = 'text' ) {
	$subtasks = array();
	$seen     = array();
	if ( ! is_array( $fields ) ) {
		return $subtasks;
	}

	foreach ( $fields as $field_key => $field_value ) {
		$key = is_string( $field_key ) && '' !== $field_key
			? sanitize_key( $field_key )
			: 'field_' . ( count( $subtasks ) + 1 );
		if ( '' === $key ) {
			$key = 'field_' . ( count( $subtasks ) + 1 );
		}

		$type   = wptsall_normalize_client_task_type( $fallback_type );
		$dedupe = $type . '|' . $key;
		if ( isset( $seen[ $dedupe ] ) ) {
			continue;
		}
		$seen[ $dedupe ] = true;

		$subtasks[] = array(
			'type'        => $type,
			'key'         => $key,
			'source_text' => wptsall_extract_client_source_text( $field_value ),
		);
	}

	return $subtasks;
}

/**
 * Merge two subtasks lists with type+key dedupe.
 *
 * @param array $base     Base subtasks.
 * @param array $incoming Incoming subtasks.
 * @return array
 */
function wptsall_merge_client_subtasks( $base, $incoming ) {
	$merged = array();
	$seen   = array();

	foreach ( array( (array) $base, (array) $incoming ) as $list ) {
		foreach ( $list as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$type = wptsall_normalize_client_task_type( (string) ( $subtask['type'] ?? 'text' ) );
			$key  = sanitize_key( (string) ( $subtask['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$dedupe = $type . '|' . $key;
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;
			$entry = array(
				'type'        => $type,
				'task_type'   => $type,
				'key'         => $key,
				'source_text' => wptsall_extract_client_source_text( $subtask ),
			);
			// Preserve enriched media metadata.
			$source_ref = sanitize_text_field( (string) ( $subtask['source_ref'] ?? '' ) );
			if ( '' !== $source_ref ) {
				$entry['source_ref'] = $source_ref;
			}
			if ( is_array( $subtask['source_payload'] ?? null ) && ! empty( $subtask['source_payload'] ) ) {
				$entry['source_payload'] = $subtask['source_payload'];
			}
			$merged[] = $entry;
			$target_path = sanitize_text_field(
				(string) ( $subtask['target_path'] ?? ( $subtask['path'] ?? '' ) )
			);
			if ( '' !== $target_path && preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
				$merged[ count( $merged ) - 1 ]['target_path'] = $target_path;
			}
			$target_paths = array();
			foreach ( array( 'target_paths', 'field_targets', 'targets' ) as $map_key ) {
				if ( ! is_array( $subtask[ $map_key ] ?? null ) ) {
					continue;
				}
				foreach ( $subtask[ $map_key ] as $raw_field => $raw_path ) {
					$field = sanitize_key( (string) $raw_field );
					$path  = sanitize_text_field( (string) $raw_path );
					if ( '' === $field || '' === $path ) {
						continue;
					}
					if ( ! preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $path ) ) {
						continue;
					}
					$target_paths[ $field ] = $path;
				}
			}
			if ( ! empty( $target_paths ) ) {
				$merged[ count( $merged ) - 1 ]['target_paths'] = $target_paths;
			}
		}
	}

	return $merged;
}

/**
 * Extract URLs from text.
 *
 * @param string $text Source text.
 * @return array
 */
function wptsall_extract_media_urls_from_text( $text ) {
	$text = is_string( $text ) ? trim( $text ) : '';
	if ( '' === $text ) {
		return array();
	}

	$matches = array();
	preg_match_all( '#https?://[^\s"<>\']+#i', $text, $matches );
	$urls = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : array();
	if ( empty( $urls ) ) {
		return array();
	}
	$urls = array_values(
		array_unique(
			array_map(
				function( $url ) {
					return trim( (string) $url );
				},
				$urls
			)
		)
	);
	return array_filter( $urls );
}

/**
 * Detect media task type from URL extension.
 *
 * @param string $url URL.
 * @return string Empty string when unknown.
 */
function wptsall_detect_media_task_type_from_url( $url ) {
	$path = wp_parse_url( (string) $url, PHP_URL_PATH );
	$path = is_string( $path ) ? strtolower( $path ) : '';
	$ext  = pathinfo( $path, PATHINFO_EXTENSION );
	$ext  = is_string( $ext ) ? strtolower( $ext ) : '';
	if ( '' === $ext ) {
		return '';
	}

	if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp' ), true ) ) {
		return 'image';
	}
	if ( in_array( $ext, array( 'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v' ), true ) ) {
		return 'video';
	}
	if ( in_array( $ext, array( 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac' ), true ) ) {
		return 'audio';
	}
	if ( in_array( $ext, array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv' ), true ) ) {
		return 'document';
	}

	return '';
}

/**
 * Build media subtasks from complete object data.
 *
 * @param array $complete_data Complete object data.
 * @return array
 */
function wptsall_collect_media_subtasks_from_complete_data( $complete_data, &$seen = array(), $key_prefix = 'media' ) {
	$result = array(
		'image'    => array(),
		'video'    => array(),
		'audio'    => array(),
		'document' => array(),
	);
	if ( ! is_array( $complete_data ) ) {
		return $result;
	}
	if ( ! is_array( $seen ) ) {
		$seen = array();
	}
	$key_prefix = sanitize_key( (string) $key_prefix );
	if ( '' === $key_prefix ) {
		$key_prefix = 'media';
	}

	$candidate_sources = array();
	$post = isset( $complete_data['post'] ) && is_array( $complete_data['post'] ) ? $complete_data['post'] : array();
	$term = isset( $complete_data['term'] ) && is_array( $complete_data['term'] ) ? $complete_data['term'] : array();
	$meta = isset( $complete_data['meta'] ) && is_array( $complete_data['meta'] ) ? $complete_data['meta'] : array();

	foreach ( array( 'post_content', 'post_excerpt', '_thumbnail_url' ) as $key ) {
		if ( ! empty( $post[ $key ] ) ) {
			$target_path = in_array( $key, array( 'post_content', 'post_excerpt' ), true )
				? 'post.' . $key
				: 'meta.' . sanitize_key( $key );
			$candidate_sources[] = array(
				'text'        => (string) $post[ $key ],
				'target_path' => $target_path,
			);
		}
	}
	foreach ( array( 'description' ) as $key ) {
		if ( ! empty( $term[ $key ] ) ) {
			$candidate_sources[] = array(
				'text'        => (string) $term[ $key ],
				'target_path' => 'term.' . sanitize_key( $key ),
			);
		}
	}
	foreach ( $meta as $meta_key => $value ) {
		$meta_target_key = sanitize_key( (string) $meta_key );
		$meta_target_path = '' !== $meta_target_key ? 'meta.' . $meta_target_key : '';
		if ( is_string( $value ) ) {
			$candidate_sources[] = array(
				'text'        => $value,
				'target_path' => $meta_target_path,
			);
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_string( $item ) ) {
					$candidate_sources[] = array(
						'text'        => $item,
						'target_path' => $meta_target_path,
					);
				}
			}
		}
	}

	foreach ( $candidate_sources as $candidate ) {
		$text        = (string) ( $candidate['text'] ?? '' );
		$target_path = sanitize_text_field( (string) ( $candidate['target_path'] ?? '' ) );
		$urls = wptsall_extract_media_urls_from_text( $text );
		foreach ( $urls as $url ) {
			$type = wptsall_detect_media_task_type_from_url( $url );
			if ( '' === $type ) {
				continue;
			}
			$dedupe = $type . '|' . $url;
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;
			$index   = count( $result[ $type ] ) + 1;
			$sub_key = sanitize_key( $key_prefix . '_' . $type . '_' . $index );
			$subtask = wptsall_build_enriched_media_subtask( $type, $sub_key, $url );
			if ( '' !== $target_path && preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
				$subtask['target_path']  = $target_path;
				$subtask['target_paths'] = array(
					'translated_ref' => $target_path,
				);
			}
			$result[ $type ][] = $subtask;
		}
	}

	return $result;
}

/**
 * Resolve WordPress attachment metadata for a media URL.
 *
 * Looks up the attachment post by URL and returns enriched metadata
 * including alt_text, caption, description, mime_type, and file_size.
 *
 * @since 1.0.6
 *
 * @param string $url Media URL.
 * @return array Source payload with available metadata, empty array if not found.
 */
function wptsall_resolve_media_attachment_metadata( $url ) {
	if ( ! is_string( $url ) || '' === trim( $url ) ) {
		return array();
	}

	$attachment_id = attachment_url_to_postid( $url );
	if ( $attachment_id <= 0 ) {
		return array();
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return array();
	}

	$meta = array(
		'attachment_id' => $attachment_id,
		'alt_text'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'caption'       => (string) $attachment->post_excerpt,
		'description'   => (string) $attachment->post_content,
		'mime_type'     => (string) $attachment->post_mime_type,
	);

	$file_path = get_attached_file( $attachment_id );
	if ( is_string( $file_path ) && '' !== $file_path && file_exists( $file_path ) ) {
		$meta['file_size'] = (int) filesize( $file_path );
	}

	return $meta;
}

/**
 * Build an enriched media subtask entry.
 *
 * Adds source_ref, task_type, and source_payload (from WP attachment metadata)
 * to the standard subtask shape.
 *
 * @since 1.0.6
 *
 * @param string $type       Media type (image/video/audio/document).
 * @param string $key        Subtask key.
 * @param string $url        Media URL.
 * @return array Enriched subtask.
 */
function wptsall_build_enriched_media_subtask( $type, $key, $url ) {
	$subtask = array(
		'type'        => $type,
		'task_type'   => $type,
		'key'         => $key,
		'source_ref'  => $url,
		'source_text' => $url,
	);

	$attachment_meta = wptsall_resolve_media_attachment_metadata( $url );
	if ( ! empty( $attachment_meta ) ) {
		$subtask['source_payload'] = $attachment_meta;
	}

	return $subtask;
}

/**
 * Collect media subtasks from generic field/entry values.
 *
 * @param mixed  $values     Source values map/list.
 * @param array  $seen       Seen map keyed by "type|url" for cross-source dedupe.
 * @param string $key_prefix Key prefix.
 * @return array
 */
function wptsall_collect_media_subtasks_from_values( $values, &$seen = array(), $key_prefix = 'media_auto' ) {
	$result = array(
		'image'    => array(),
		'video'    => array(),
		'audio'    => array(),
		'document' => array(),
	);
	if ( ! is_array( $values ) ) {
		return $result;
	}
	if ( ! is_array( $seen ) ) {
		$seen = array();
	}
	$key_prefix = sanitize_key( (string) $key_prefix );
	if ( '' === $key_prefix ) {
		$key_prefix = 'media_auto';
	}

	foreach ( $values as $field_key => $value ) {
		$field_target_key = is_string( $field_key ) ? sanitize_key( $field_key ) : '';
		$field_target_path = '' !== $field_target_key ? 'meta.' . $field_target_key : '';
		$source_text = wptsall_extract_client_source_text( $value );
		if ( '' === trim( $source_text ) ) {
			continue;
		}
		$urls = wptsall_extract_media_urls_from_text( $source_text );
		foreach ( $urls as $url ) {
			$type = wptsall_detect_media_task_type_from_url( $url );
			if ( '' === $type ) {
				continue;
			}
			$dedupe = $type . '|' . $url;
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;
			$index   = count( $result[ $type ] ) + 1;
			$sub_key = sanitize_key( $key_prefix . '_' . $type . '_' . $index );
			$subtask = wptsall_build_enriched_media_subtask( $type, $sub_key, $url );
			if ( '' !== $field_target_path && preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $field_target_path ) ) {
				$subtask['target_path']  = $field_target_path;
				$subtask['target_paths'] = array(
					'translated_ref' => $field_target_path,
				);
			}
			$result[ $type ][] = $subtask;
		}
	}

	return $result;
}

/**
 * Seed seen media URL map from named media lists.
 *
 * @param mixed $items Named media list items.
 * @param array $seen  Seen map keyed by "type|url".
 * @return void
 */
function wptsall_seed_media_seen_from_named_items( $items, &$seen ) {
	if ( ! is_array( $items ) ) {
		return;
	}
	if ( ! is_array( $seen ) ) {
		$seen = array();
	}

	foreach ( $items as $item ) {
		$source_text = wptsall_extract_client_source_text( $item );
		$urls        = wptsall_extract_media_urls_from_text( $source_text );
		foreach ( $urls as $url ) {
			$type = wptsall_detect_media_task_type_from_url( $url );
			if ( '' === $type ) {
				continue;
			}
			$seen[ $type . '|' . $url ] = true;
		}
	}
}

/**
 * Ensure task payload contains standard client shape.
 *
 * @param mixed  $payload           Raw payload.
 * @param string $default_task_type Default task type.
 * @return array
 */
function wptsall_build_client_task_payload( $payload, $default_task_type = 'text' ) {
	if ( ! is_array( $payload ) ) {
		$payload = array();
	}

	$has_explicit_task_type = isset( $payload['task_type'] ) || isset( $payload['type'] );
	$raw_task_type          = $payload['task_type'] ?? ( $payload['type'] ?? $default_task_type );
	$task_type              = wptsall_normalize_client_task_type( $raw_task_type );
	$subtasks               = array();

	if ( ! empty( $payload['subtasks'] ) && is_array( $payload['subtasks'] ) ) {
		$subtasks = wptsall_normalize_client_subtasks_list( $payload['subtasks'], $task_type, 'subtask' );
	} elseif ( ! empty( $payload['content_items'] ) && is_array( $payload['content_items'] ) ) {
		$subtasks = wptsall_normalize_client_subtasks_list( $payload['content_items'], $task_type, 'content' );
	}

	$named_lists = array(
		'image'    => array( 'images', 'image_items' ),
		'video'    => array( 'videos', 'video_items' ),
		'audio'    => array( 'audios', 'audio_items' ),
		'document' => array( 'documents', 'document_items' ),
	);
	foreach ( $named_lists as $named_type => $keys ) {
		foreach ( $keys as $list_key ) {
			if ( empty( $payload[ $list_key ] ) || ! is_array( $payload[ $list_key ] ) ) {
				continue;
			}
			$named_subtasks = wptsall_normalize_client_subtasks_list( $payload[ $list_key ], $named_type, $named_type );
			$subtasks       = wptsall_merge_client_subtasks( $subtasks, $named_subtasks );
		}
	}

	if ( empty( $subtasks ) && ! empty( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
		$subtasks = wptsall_build_subtasks_from_fields_map( $payload['fields'], $task_type );
	}

	if ( empty( $subtasks ) && ! empty( $payload['entries'] ) && is_array( $payload['entries'] ) ) {
		$subtasks = wptsall_normalize_client_subtasks_list( $payload['entries'], 'text', 'entry' );
	}

	if ( ! $has_explicit_task_type && ! empty( $subtasks ) ) {
		$type_map = array();
		foreach ( $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$type = wptsall_normalize_client_task_type( (string) ( $subtask['type'] ?? 'text' ) );
			$type_map[ $type ] = true;
		}
		$type_keys = array_keys( $type_map );
		if ( count( $type_keys ) > 1 ) {
			$task_type = 'mixed';
		} elseif ( 1 === count( $type_keys ) ) {
			$task_type = (string) $type_keys[0];
		}
	}

	$payload['task_type']     = $task_type;
	$payload['subtasks']      = array_values( $subtasks );
	$payload['content_items'] = array_values( $subtasks );
	return $payload;
}

/**
 * Ensure generated task contains normalized task_type/subtasks/content_items.
 *
 * @param mixed  $task              Raw task.
 * @param string $default_task_type Default task type.
 * @return array
 */
function wptsall_prepare_task_for_client_payload( $task, $default_task_type = 'text' ) {
	if ( ! is_array( $task ) ) {
		return array();
	}

	$job_id = sanitize_text_field( (string) ( $task['job_id'] ?? '' ) );
	if ( '' === $job_id ) {
		$job_id = sanitize_text_field( (string) ( $task['job'] ?? '' ) );
	}
	if ( '' === $job_id ) {
		$job_id = wptsall_generate_task_job_id( (string) ( $task['template'] ?? '' ) );
	}

	$business_line = wptsall_infer_business_line_from_task( $task );
	$object_ref    = wptsall_build_task_object_ref( $task );

	$payload = array(
		'job_id'        => $job_id,
		'business_line' => $business_line,
		'object_ref'    => $object_ref,
		'task_type'     => $task['task_type'] ?? ( $task['type'] ?? $default_task_type ),
	);
	// Durable lifecycle metadata is carried through the task payload so the
	// client can echo the outbox identity on callback (and recover after a
	// worker restart without relying on process-local state).
	foreach ( array( 'outbox_id', 'outbox_event_key', 'client_task_id' ) as $lifecycle_key ) {
		if ( isset( $task[ $lifecycle_key ] ) && '' !== (string) $task[ $lifecycle_key ] ) {
			$payload[ $lifecycle_key ] = is_numeric( $task[ $lifecycle_key ] )
				? absint( $task[ $lifecycle_key ] ) : sanitize_text_field( (string) $task[ $lifecycle_key ] );
		}
	}
	// Preserve source audit field if set.
	if ( ! empty( $task['source'] ) ) {
		$payload['source'] = sanitize_key( (string) $task['source'] );
	}

	foreach ( array( 'subtasks', 'content_items', 'fields', 'entries', 'images', 'videos', 'audios', 'documents', 'image_items', 'video_items', 'audio_items', 'document_items', 'component_hints' ) as $key ) {
		if ( isset( $task[ $key ] ) ) {
			$payload[ $key ] = $task[ $key ];
		}
	}

	$media_seen = array();
	foreach ( array( 'images', 'image_items', 'videos', 'video_items', 'audios', 'audio_items', 'documents', 'document_items' ) as $media_list_key ) {
		if ( ! empty( $payload[ $media_list_key ] ) && is_array( $payload[ $media_list_key ] ) ) {
			wptsall_seed_media_seen_from_named_items( $payload[ $media_list_key ], $media_seen );
		}
	}

	$auto_media = array(
		'image'    => array(),
		'video'    => array(),
		'audio'    => array(),
		'document' => array(),
	);

	if ( isset( $task['complete_data'] ) && is_array( $task['complete_data'] ) ) {
		$complete_media       = wptsall_collect_media_subtasks_from_complete_data( $task['complete_data'], $media_seen, 'media_complete' );
		$auto_media['image']  = array_merge( $auto_media['image'], $complete_media['image'] );
		$auto_media['video']  = array_merge( $auto_media['video'], $complete_media['video'] );
		$auto_media['audio']  = array_merge( $auto_media['audio'], $complete_media['audio'] );
		$auto_media['document'] = array_merge( $auto_media['document'], $complete_media['document'] );
	}

	if ( ! empty( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
		$field_media          = wptsall_collect_media_subtasks_from_values( $payload['fields'], $media_seen, 'media_fields' );
		$auto_media['image']  = array_merge( $auto_media['image'], $field_media['image'] );
		$auto_media['video']  = array_merge( $auto_media['video'], $field_media['video'] );
		$auto_media['audio']  = array_merge( $auto_media['audio'], $field_media['audio'] );
		$auto_media['document'] = array_merge( $auto_media['document'], $field_media['document'] );
	}

	if ( ! empty( $payload['entries'] ) && is_array( $payload['entries'] ) ) {
		$entry_media          = wptsall_collect_media_subtasks_from_values( $payload['entries'], $media_seen, 'media_entries' );
		$auto_media['image']  = array_merge( $auto_media['image'], $entry_media['image'] );
		$auto_media['video']  = array_merge( $auto_media['video'], $entry_media['video'] );
		$auto_media['audio']  = array_merge( $auto_media['audio'], $entry_media['audio'] );
		$auto_media['document'] = array_merge( $auto_media['document'], $entry_media['document'] );
	}

	$payload['images']    = array_merge( isset( $payload['images'] ) && is_array( $payload['images'] ) ? $payload['images'] : array(), $auto_media['image'] );
	$payload['videos']    = array_merge( isset( $payload['videos'] ) && is_array( $payload['videos'] ) ? $payload['videos'] : array(), $auto_media['video'] );
	$payload['audios']    = array_merge( isset( $payload['audios'] ) && is_array( $payload['audios'] ) ? $payload['audios'] : array(), $auto_media['audio'] );
	$payload['documents'] = array_merge( isset( $payload['documents'] ) && is_array( $payload['documents'] ) ? $payload['documents'] : array(), $auto_media['document'] );

	$media_count = count( $payload['images'] ) + count( $payload['videos'] ) + count( $payload['audios'] ) + count( $payload['documents'] );
	if ( $media_count > 0 ) {
		$payload['component_hints'] = isset( $payload['component_hints'] ) && is_array( $payload['component_hints'] )
			? $payload['component_hints']
			: array();
		$payload['component_hints']['non_text_detected'] = true;
		$payload['component_hints']['non_text_count']    = $media_count;
	}

	$normalized = wptsall_build_client_task_payload( $payload, $default_task_type );

	$task['job_id']        = $job_id;
	$task['business_line'] = $business_line;
	$task['object_ref']    = $object_ref;
	$task['task_type']     = $normalized['task_type'];
	$task['subtasks']      = $normalized['subtasks'];
	$task['content_items'] = $normalized['content_items'];
	return $task;
}

/**
 * Generate tasks from a site relation.
 *
 * @param int   $relation_id Site relation ID.
 * @param array $options     Options: sync_mode, object_types, limit.
 * @return array|WP_Error Array of tasks or WP_Error.
 */
function wptsall_generate_tasks_from_relation( $relation_id, $options = array() ) {
    // Validate first.
    $validation = wptsall_validate_task_creation( $relation_id );
    if ( is_wp_error( $validation ) ) {
        return $validation;
    }

    $relation = Site_Relation_Service::get_relation( $relation_id );
    $template = $relation['template'];
    $model    = Translation_Rule_Service::get_model( $template );

    $job_id = sanitize_text_field( (string) ( $options['job_id'] ?? '' ) );

    // Build site_rel array for compatibility.
    $site_rel = array(
        'id'       => $relation['id'],
        'template' => $template,
        'job_id'   => $job_id,
        'source'   => array(
            'type' => 'wp',
            'id'   => $relation['source_site_id'],
            'lang' => $relation['source_lang'],
        ),
        'targets'  => array(
            array(
                'type'    => $relation['target_site_type'],
                'id'      => $relation['target_site_id'],
                'lang_to' => $relation['target_lang'],
            ),
        ),
    );

    // Get template configuration.
    $tpl = wptsall_get_saved_template( $template );
    if ( ! $tpl ) {
        // Build from model rules if no saved template.
        $tpl = wptsall_build_template_from_model( $model );
    }

    // Apply options.
    $limit = isset( $options['limit'] ) ? intval( $options['limit'] ) : 100;

    // Generate tasks.
    $tasks = wptsall_generate_tasks_from_template( $tpl, $limit, $site_rel );

    // Filter by object_types if specified.
    if ( ! empty( $options['object_types'] ) && is_array( $options['object_types'] ) ) {
        $allowed_types = $options['object_types'];
        $tasks = array_filter( $tasks, function( $task ) use ( $allowed_types ) {
            $full_type = $task['object_type'] . ':' . $task['subtype'];
            return in_array( $task['subtype'], $allowed_types, true )
                || in_array( $full_type, $allowed_types, true );
        });
        $tasks = array_values( $tasks );
    }

    return $tasks;
}

/**
 * Build template configuration from model.
 *
 * @param array $model Model data.
 * @return array Template configuration.
 */
function wptsall_build_template_from_model( $model ) {
    $tpl = array(
        'plugin'  => $model['plugin_slug'] ?? $model['slug'] ?? '',
        'objects' => array(
            'post_types' => array(),
            'taxonomies' => array(),
        ),
    );

    // Get rules and extract object types.
    // Support both flat array (rules) and grouped array (url_rules).
    $rules = $model['rules'] ?? $model['url_rules'] ?? array();
    $seen_post_types = array();
    $seen_taxonomies = array();

    foreach ( $rules as $key => $rule ) {
        // If grouped by access level, iterate inner array.
        if ( is_array( $rule ) && ! isset( $rule['data_type'] ) && ! isset( $rule['object_type'] ) ) {
            foreach ( $rule as $inner_rule ) {
                $data_type   = $inner_rule['data_type'] ?? $inner_rule['object_type'] ?? '';
                $object_name = $inner_rule['object_name'] ?? $inner_rule['object_subtype'] ?? '';
                if ( in_array( $data_type, array( 'post_type', 'post' ), true ) && $object_name && ! isset( $seen_post_types[ $object_name ] ) ) {
                    $tpl['objects']['post_types'][] = array( 'subtype' => $object_name );
                    $seen_post_types[ $object_name ] = true;
                } elseif ( in_array( $data_type, array( 'taxonomy', 'term' ), true ) && $object_name && ! isset( $seen_taxonomies[ $object_name ] ) ) {
                    $tpl['objects']['taxonomies'][] = array( 'subtype' => $object_name );
                    $seen_taxonomies[ $object_name ] = true;
                }
            }
        } else {
            // Flat rule array.
            $data_type   = $rule['data_type'] ?? $rule['object_type'] ?? '';
            $object_name = $rule['object_name'] ?? $rule['object_subtype'] ?? '';
            if ( in_array( $data_type, array( 'post_type', 'post' ), true ) && $object_name && ! isset( $seen_post_types[ $object_name ] ) ) {
                $tpl['objects']['post_types'][] = array( 'subtype' => $object_name );
                $seen_post_types[ $object_name ] = true;
            } elseif ( in_array( $data_type, array( 'taxonomy', 'term' ), true ) && $object_name && ! isset( $seen_taxonomies[ $object_name ] ) ) {
                $tpl['objects']['taxonomies'][] = array( 'subtype' => $object_name );
                $seen_taxonomies[ $object_name ] = true;
            }
        }
    }

    return $tpl;
}

/**
 * Generate a language pack translation task.
 *
 * Creates a task for translating plugin/theme language strings.
 * Used by Templates module to trigger translation.
 *
 * @param int   $template_id Template ID from templates table.
 * @param array $options     Options: entries, lang_from, lang_to, batch_size.
 * @return array|WP_Error Task data or error.
 */
function wptsall_generate_language_pack_task( $template_id, $options = array() ) {
    // Get template data (requires Templates module).
    $template = wptsall_get_template_for_translation( $template_id );

    if ( ! $template ) {
        return new WP_Error( 'invalid_template', __( 'Language pack template does not exist', 'wpmmcc-ats' ) );
    }

    // Get pending entries.
    $entries = isset( $options['entries'] ) ? $options['entries'] : wptsall_get_pending_template_entries( $template_id );

    if ( empty( $entries ) ) {
        return new WP_Error( 'no_entries', __( 'No entries to translate', 'wpmmcc-ats' ) );
    }

    $source_type = $template['source_type'] ?? 'plugin';
    $text_domain = $template['text_domain'] ?? '';
    // ISS-TSK-077: unified fallback chain — source_language/target_language → lang_from/lang_to.
	$lang_from   = $options['source_language'] ?? $options['lang_from'] ?? $template['source_language'] ?? $template['lang_from'] ?? '';
	$lang_to     = $options['target_language'] ?? $options['lang_to'] ?? $template['target_language'] ?? $template['lang_to'] ?? '';
	$batch_size  = $options['batch_size'] ?? 50;
	$job_id      = sanitize_text_field( (string) ( $options['job_id'] ?? '' ) );
	if ( '' === $job_id ) {
		$job_id = wptsall_generate_task_job_id( 'language_pack_' . $template_id );
	}

    // Split into batches if needed.
    $batches = array_chunk( $entries, $batch_size );
    $tasks   = array();

    foreach ( $batches as $batch_index => $batch_entries ) {
        $payload = array(
            'template_id'   => $template_id,
            'relation_id'   => $template['relation_id'] ?? 0,
            'source_type'   => $source_type,
            'text_domain'   => $text_domain,
            'lang_from'     => $lang_from,
            'lang_to'       => $lang_to,
            'entries'       => $batch_entries,
            'batch_index'   => $batch_index,
            'total_batches' => count( $batches ),
        );
        $payload = wptsall_build_client_task_payload( $payload, 'text' );

	        $tasks[] = array(
	            'job_id'            => $job_id,
	            'blog_id'           => get_current_blog_id(),
            'target_blog'       => 0,
            'target_type'       => 'language_pack',
            'target_identifier' => $template_id,
            'site_mode'         => '',
            'site_id'           => $template['relation_id'] ?? 0,
            'template'          => $text_domain,
            'lang_from'         => $lang_from,
            'lang_to'           => $lang_to,
            'object_type'       => 'language_pack',
            'subtype'           => $source_type,
            'object_id'         => $template_id,
            'payload'           => $payload,
            'task_type'         => $payload['task_type'],
            'subtasks'          => $payload['subtasks'],
            'content_items'     => $payload['content_items'],
        );
    }

    return $tasks;
}

/**
 * Get template data for translation.
 *
 * Placeholder for Templates module integration.
 *
 * @param int $template_id Template ID.
 * @return array|null Template data or null.
 */
function wptsall_get_template_for_translation( $template_id ) {
    // TODO: Integrate with Templates module when implemented.
    // For now, return from wptsall_templates table if exists.
    global $wpdb;
    $table = wptsall_table( 'templates' );

    // Check if table exists.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
    );

    if ( ! $table_exists ) {
        return null;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    return $wpdb->get_row(
        $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $template_id ),
        ARRAY_A
    );
}

/**
 * Get pending template entries for translation.
 *
 * Placeholder for Templates module integration.
 *
 * @param int $template_id Template ID.
 * @return array Array of entries.
 */
function wptsall_get_pending_template_entries( $template_id ) {
    // TODO: Integrate with Templates module when implemented.
    global $wpdb;
    $table = wptsall_table( 'template_entries' );

    // Check if table exists.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
    );

    if ( ! $table_exists ) {
        return array();
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $entries = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id as entry_id, msgid, msgctxt FROM %i WHERE template_id = %d AND status = %s",
            $table,
            $template_id,
            'pending'
        ),
        ARRAY_A
    );

    return $entries ?: array();
}


/**
 * Update template translation statistics.
 *
 * @param int $template_id Template ID.
 */
function wptsall_update_template_translation_stats( $template_id ) {
    global $wpdb;
    $templates_table = wptsall_table( 'templates' );
    $entries_table   = wptsall_table( 'template_entries' );

    // Check if tables exist.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $templates_table )
    );

    if ( ! $table_exists ) {
        return;
    }

    // Count translated entries.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $translated_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE template_id = %d AND status = %s",
            $entries_table,
            $template_id,
            'translated'
        )
    );

    // Update templates table.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
        $templates_table,
        array(
            'translated_entries' => $translated_count,
            'updated_at'         => current_time( 'mysql', true ),
        ),
        array( 'id' => $template_id ),
        array( '%d', '%s' ),
        array( '%d' )
    );
}

function wptsall_get_saved_template( $plugin_slug ) {
    $plugin_slug = sanitize_key( $plugin_slug );
    if ( ! $plugin_slug ) {
        return null;
    }
    $tpl = get_option( 'wptsall_template_' . $plugin_slug );
    return is_array( $tpl ) ? $tpl : null;
}

function wptsall_generate_tasks_from_template( $template, $limit = 1, $site_rel = null ) {
	$tasks = array();
    $source_type = $site_rel['source']['type'] ?? 'wp';
    $source_id   = isset( $site_rel['source']['id'] ) ? $site_rel['source']['id'] : get_current_blog_id();
    $source_blog = ( 'wp' === $source_type ) ? intval( $source_id ) : get_current_blog_id();
    $lang_from   = isset( $site_rel['source']['lang'] ) ? $site_rel['source']['lang'] : '';
    $site_id     = $site_rel['id'] ?? 0;
	$template_slug = $site_rel['template'] ?? ( $template['plugin'] ?? '' );
	$job_id = sanitize_text_field( (string) ( $site_rel['job_id'] ?? '' ) );
	if ( '' === $job_id ) {
		$job_id = wptsall_generate_task_job_id( 'template_' . $template_slug . '_' . $site_id );
	}
	$default_target = array(
        'type'    => 'wp',
        'id'      => $source_blog,
        'lang_to' => '',
    );
    $targets = isset( $site_rel['targets'] ) ? (array) $site_rel['targets'] : array( $default_target );
    if ( empty( $template['objects'] ) ) {
        return $tasks;
    }

    // Switch to source site to fetch data
    $switched = false;
    if ( $source_blog && $source_blog !== get_current_blog_id() && is_multisite() ) {
        switch_to_blog( $source_blog );
        $switched = true;
    }

    foreach ( $targets as $target ) {
        $target_type       = isset( $target['type'] ) ? $target['type'] : 'wp';
        $target_identifier = isset( $target['id'] ) ? $target['id'] : $source_blog;
        $target_blog       = ( 'wp' === $target_type ) ? intval( $target_identifier ) : 0;
        $lang_to     = isset( $target['lang_to'] ) ? $target['lang_to'] : '';

        // Process Post Types
        if ( ! empty( $template['objects']['post_types'] ) ) {
            foreach ( $template['objects']['post_types'] as $obj ) {
                $post_type = $obj['subtype'];

                // Get sample IDs
                $post_ids = get_posts( array(
                    'post_type'      => $post_type,
                    'post_status'    => 'publish',
                    'posts_per_page' => $limit,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'DESC',
                ) );

                foreach ( $post_ids as $post_id ) {
                    // Use new complete data fetch function
                    $complete_data = wptsall_get_complete_post_data( $post_type, $post_id );

                    if ( ! $complete_data ) {
                        continue;
                    }

	                    $task = array(
	                        'job_id'            => $job_id,
	                        'blog_id'           => $source_blog,
                        'target_blog'       => $target_blog,
                        'target_type'       => $target_type,
                        'target_identifier' => $target_identifier,
                        'site_mode'         => '',
                        'site_id'           => $site_id,
                        'template'          => $template_slug,
                        'lang_from'         => $lang_from,
                        'lang_to'           => $lang_to,
                        'object_type'       => 'post_type',
                        'subtype'           => $post_type,
                        'object_id'         => $post_id,
                        'complete_data'     => $complete_data, // Complete data
                        'fields'            => $complete_data['post'], // Backward compatible format
                    );
                    $tasks[] = wptsall_prepare_task_for_client_payload( $task, 'text' );
	                }
	            }
	        }

        // Process Taxonomies
        if ( ! empty( $template['objects']['taxonomies'] ) ) {
            foreach ( $template['objects']['taxonomies'] as $obj ) {
                $tax = $obj['subtype'];

                // Get sample IDs
                $terms = get_terms( array(
                    'taxonomy'   => $tax,
                    'hide_empty' => false,
                    'number'     => $limit,
                    'fields'     => 'ids',
                    'orderby'    => 'term_id',
                    'order'      => 'DESC',
                ) );
                if ( is_wp_error( $terms ) ) {
                    $terms = array();
                }

                foreach ( $terms as $term_id ) {
                    // Use new complete data fetch function
                    $complete_data = wptsall_get_complete_term_data( $tax, $term_id );

                    if ( ! $complete_data ) {
                        continue;
                    }

	                    $task = array(
	                        'job_id'            => $job_id,
	                        'blog_id'           => $source_blog,
                        'target_blog'       => $target_blog,
                        'target_type'       => $target_type,
                        'target_identifier' => $target_identifier,
                        'site_mode'         => '',
                        'site_id'           => $site_id,
                        'template'          => $template_slug,
                        'lang_from'         => $lang_from,
                        'lang_to'           => $lang_to,
                        'object_type'       => 'taxonomy',
                        'subtype'           => $tax,
                        'object_id'         => $term_id,
                        'complete_data'     => $complete_data, // Complete data
                        'fields'            => $complete_data['term'], // Backward compatible format
                    );
                    $tasks[] = wptsall_prepare_task_for_client_payload( $task, 'text' );
	                }
	            }
	        }
    }

    if ( $switched ) {
        restore_current_blog();
    }

    return $tasks;
}


function wptsall_insert_mapping( $source_blog, $object_type, $subtype, $source_id, $target_blog, $target_id, $model, $relation_id = 0 ) {
	global $wpdb;
	// Use base_prefix to ensure mapping table is on the main site, shared across sites.
	$table = $wpdb->base_prefix . 'wptsall_mappings';

	// Self-heal: ensure table exists before write.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $table_exists !== $table && function_exists( 'wptsall_ensure_mapping_table' ) ) {
		wptsall_ensure_mapping_table();
	}
	if ( function_exists( 'wptsall_migrate_legacy_mapping_relation_id_v210' ) ) {
		wptsall_migrate_legacy_mapping_relation_id_v210();
	}

	$source_blog = intval( $source_blog );
	$object_type = sanitize_key( $object_type );
	$subtype     = sanitize_key( $subtype );
	$source_id   = intval( $source_id );
	$target_blog = intval( $target_blog );
	$target_id   = intval( $target_id );
	$model       = sanitize_key( $model );
	$relation_id = absint( $relation_id );

	// Keep one canonical mapping row per source tuple + target blog. A positive
	// relation_id is part of the identity; the zero value is retained only for
	// pre-relation legacy rows and must not be used by relation-aware callers.
	$where = array(
		'source_blog_id'     => $source_blog,
		'source_object_type' => $object_type,
		'source_subtype'     => $subtype,
		'source_object_id'   => $source_id,
		'target_blog_id'     => $target_blog,
	);
	$where_formats = array( '%d', '%s', '%s', '%d', '%d' );
	if ( $relation_id > 0 ) {
		$where['relation_id'] = $relation_id;
		$where_formats[]      = '%d';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $table, $where, $where_formats );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->insert(
		$table,
		array(
			'source_blog_id'     => $source_blog,
			'source_object_type' => $object_type,
			'source_subtype'     => $subtype,
			'source_object_id'   => $source_id,
			'target_blog_id'     => $target_blog,
			'target_object_id'   => $target_id,
			'model'              => $model,
			'relation_id'        => $relation_id,
			'created_at'         => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s' )
	);
}

/**
 * Query mapped target ID
 *
 * @param int    $source_blog   Source site ID.
 * @param string $object_type   Object type (post_type/taxonomy/attachment).
 * @param string $subtype       Subtype.
 * @param int    $source_id     Source object ID.
 * @param int    $target_blog   Target site ID.
 * @param int    $relation_id   Optional relation ID. Positive values are strictly scoped.
 * @return int|null Target ID or null.
 */
function wptsall_get_mapped_id( $source_blog, $object_type, $subtype, $source_id, $target_blog, $relation_id = 0 ) {
    global $wpdb;
    $relation_id = absint( $relation_id );
    // Use base_prefix to ensure mapping table is on the main site, shared across sites
    $table = $wpdb->base_prefix . 'wptsall_mappings';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- correctness-critical mapping lookup; batch API exists for loops, cross-request caching would go stale on mapping writes.
    if ( $relation_id > 0 ) {
        $result = $wpdb->get_var( $wpdb->prepare(
            'SELECT target_object_id FROM %i WHERE source_blog_id = %d AND source_object_type = %s AND source_subtype = %s AND source_object_id = %d AND target_blog_id = %d AND relation_id = %d LIMIT 1',
            $table,
            intval( $source_blog ),
            sanitize_key( $object_type ),
            sanitize_key( $subtype ),
            intval( $source_id ),
            intval( $target_blog ),
            $relation_id
        ) );
    } else {
        $result = $wpdb->get_var( $wpdb->prepare(
            'SELECT target_object_id FROM %i WHERE source_blog_id = %d AND source_object_type = %s AND source_subtype = %s AND source_object_id = %d AND target_blog_id = %d LIMIT 1',
            $table,
            intval( $source_blog ),
            sanitize_key( $object_type ),
            sanitize_key( $subtype ),
            intval( $source_id ),
            intval( $target_blog )
        ) );
    }
    return $result ? intval( $result ) : null;
}

/**
 * Batch query mapped IDs
 *
 * @param int    $source_blog Source site ID.
 * @param string $object_type Object type.
 * @param string $subtype     Subtype.
 * @param array  $source_ids  Source ID array.
 * @param int    $target_blog Target site ID.
 * @param int    $relation_id Optional relation ID. Positive values are strictly scoped.
 * @return array Source ID => Target ID mapping array.
 */
function wptsall_get_mapped_ids_batch( $source_blog, $object_type, $subtype, $source_ids, $target_blog, $relation_id = 0 ) {
    global $wpdb;
    $relation_id = absint( $relation_id );
    if ( empty( $source_ids ) ) {
        return array();
    }
    // Use base_prefix to ensure mapping table is on the main site, shared across sites
    $table = $wpdb->base_prefix . 'wptsall_mappings';
    list( $in_sql, $in_args ) = wptsall_db_prepare_int_in( $source_ids );
    $sql   = "SELECT source_object_id, target_object_id FROM %i WHERE source_blog_id = %d AND source_object_type = %s AND source_subtype = %s AND target_blog_id = %d";
    $args  = array( $table, intval( $source_blog ), sanitize_key( $object_type ), sanitize_key( $subtype ), intval( $target_blog ) );
    if ( $relation_id > 0 ) {
        $sql   .= ' AND relation_id = %d';
        $args[] = $relation_id;
    }
    $sql   .= " AND source_object_id IN ($in_sql)";
    $args   = array_merge( $args, $in_args );
    $results = wptsall_db_get_results( $sql, $args, ARRAY_A );
    $map = array();
    foreach ( (array) $results as $row ) {
        $map[ intval( $row['source_object_id'] ) ] = intval( $row['target_object_id'] );
    }
    return $map;
}

/**
 * Sync attachment to target site
 *
 * @param int    $source_blog      Source site ID.
 * @param int    $attachment_id    Source attachment ID.
 * @param int    $target_blog      Target site ID.
 * @param string $attachment_url   Attachment URL (optional, for downloading).
 * @param int    $relation_id      Optional relation ID. Positive values are strictly scoped.
 * @return int|null Target attachment ID or null.
 */
function wptsall_sync_attachment( $source_blog, $attachment_id, $target_blog, $attachment_url = '', $relation_id = 0 ) {
    $relation_id = absint( $relation_id );
    // 1. Check if mapping already exists
    $mapped_id = wptsall_get_mapped_id( $source_blog, 'attachment', 'attachment', $attachment_id, $target_blog, $relation_id );
    if ( $mapped_id ) {
        // Verify target attachment still exists
        $switched = false;
        if ( $target_blog && $target_blog !== get_current_blog_id() && is_multisite() ) {
            switch_to_blog( $target_blog );
            $switched = true;
        }
        try {
            $exists = get_post( $mapped_id );
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
        if ( $exists && 'attachment' === $exists->post_type ) {
            return $mapped_id;
        }
    }

    // 2. Get source attachment info
    $switched_source = false;
    if ( $source_blog && $source_blog !== get_current_blog_id() && is_multisite() ) {
        switch_to_blog( $source_blog );
        $switched_source = true;
    }

    try {
        $source_attachment = get_post( $attachment_id );
        if ( ! $source_attachment || 'attachment' !== $source_attachment->post_type ) {
            return null;
        }

        $source_file = get_attached_file( $attachment_id );
        $source_url  = $attachment_url ?: wp_get_attachment_url( $attachment_id );
        $source_meta = wp_get_attachment_metadata( $attachment_id );
        $source_alt  = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
    } finally {
        if ( $switched_source ) {
            restore_current_blog();
        }
    }

    // 3. Switch to target site to create attachment
    $switched_target = false;
    if ( $target_blog && $target_blog !== get_current_blog_id() && is_multisite() ) {
        switch_to_blog( $target_blog );
        $switched_target = true;
    }

    $new_attachment_id = null;

    try {
    // Prefer file path (same server multisite)
    if ( $source_file && file_exists( $source_file ) ) {
        // Copy file to target site upload directory
        $upload_dir = wp_upload_dir();
        $filename   = wp_unique_filename( $upload_dir['path'], basename( $source_file ) );
        $new_file   = $upload_dir['path'] . '/' . $filename;

        if ( copy( $source_file, $new_file ) ) {
            $filetype = wp_check_filetype( $filename );
            $attachment_data = array(
                'post_mime_type' => $filetype['type'],
                'post_title'     => $source_attachment->post_title,
                'post_content'   => $source_attachment->post_content,
                'post_excerpt'   => $source_attachment->post_excerpt,
                'post_status'    => 'inherit',
            );

            $new_attachment_id = wp_insert_attachment( $attachment_data, $new_file );

            if ( ! is_wp_error( $new_attachment_id ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attach_data = wp_generate_attachment_metadata( $new_attachment_id, $new_file );
                wp_update_attachment_metadata( $new_attachment_id, $attach_data );

                // Set alt text
                if ( $source_alt ) {
                    update_post_meta( $new_attachment_id, '_wp_attachment_image_alt', $source_alt );
                }
            }
        }
    } elseif ( $source_url ) {
        // Resolve local file from URL (same-server multisite).
        // Direct URL download is disabled; attempt to locate file on disk.
        $upload_dir  = wp_upload_dir();
        $uploads_url = set_url_scheme( $upload_dir['baseurl'] );
        $local_file  = null;

        // Check if the source URL points to the local uploads directory.
        if ( 0 === strpos( $source_url, $uploads_url ) ) {
            $relative    = substr( $source_url, strlen( $uploads_url ) );
            $local_file  = $upload_dir['basedir'] . $relative;
        }

        if ( $local_file && file_exists( $local_file ) ) {
            $target_upload_dir = wp_upload_dir();
            $filename          = wp_unique_filename( $target_upload_dir['path'], basename( $local_file ) );
            $new_file          = $target_upload_dir['path'] . '/' . $filename;

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
            if ( copy( $local_file, $new_file ) ) {
                $filetype        = wp_check_filetype( $filename );
                $attachment_data = array(
                    'post_mime_type' => $filetype['type'],
                    'post_title'     => $source_attachment->post_title,
                    'post_content'   => $source_attachment->post_content,
                    'post_excerpt'   => $source_attachment->post_excerpt,
                    'post_status'    => 'inherit',
                );

                $new_attachment_id = wp_insert_attachment( $attachment_data, $new_file );

                if ( ! is_wp_error( $new_attachment_id ) ) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    $attach_data = wp_generate_attachment_metadata( $new_attachment_id, $new_file );
                    wp_update_attachment_metadata( $new_attachment_id, $attach_data );

                    // Set alt text.
                    if ( $source_alt ) {
                        update_post_meta( $new_attachment_id, '_wp_attachment_image_alt', $source_alt );
                    }
                }
            }
        }
    }

    } finally {
        if ( $switched_target ) {
            restore_current_blog();
        }
    }

    // 4. Save mapping relationship
    if ( $new_attachment_id && ! is_wp_error( $new_attachment_id ) ) {
        wptsall_insert_mapping(
            $source_blog,
            'attachment',
            'attachment',
            $attachment_id,
            $target_blog,
            $new_attachment_id,
            'attachment',
            $relation_id
        );
        return $new_attachment_id;
    }

    return null;
}

/**
 * Get ID mapping fields for a given post type.
 *
 * Uses a 3-tier fallback to obtain the most specific configuration available:
 *
 * - Tier 1: relation_id is provided -> query models via Relation_Model_Service,
 *           find the matching rule, call TRS::get_merged_config( rule_id, relation_id ).
 * - Tier 2: model_id (or direct rule lookup) -> call TRS::get_merged_config( rule_id ).
 * - Tier 3: no rule found -> return core defaults from TRS::get_default_config().
 *
 * @since 1.0.0
 * @since 1.3.0 Refactored to use Id_Mapping_Resolver and field_capabilities.
 * @since 1.4.0 Added $relation_id parameter for relation-aware merged config.
 *
 * @param string $post_type   Post type slug.
 * @param int    $model_id    Optional. Model ID to filter translation rules. Default 0 (no filter).
 * @param int    $relation_id Optional. Site relation ID for relation-aware config. Default 0.
 * @return array Associative array of field_name => field_config (v2 format).
 *               Each field_config is an array with at least 'type' => 'id_mapping',
 *               and optionally 'reference_type', 'value_format', etc.
 *               Returns core fallback when no id_mapping fields are found.
 */
function wptsall_get_id_mapping_fields( $post_type, $model_id = 0, $relation_id = 0 ) {
	$use_trs = class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' );

	// Core fallback used by all tiers when no id_mapping fields are found.
	$core_fallback = array(
		'_thumbnail_id' => array(
			'type'           => 'id_mapping',
			'reference_type' => 'media',
			'value_format'   => 'scalar',
		),
	);

	// ── Tier 1: relation-aware merged config via get_merged_config_for_relation ──
	if ( $relation_id > 0 && $use_trs ) {
		$config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
			$relation_id,
			$post_type
		);
		// get_merged_config_for_relation returns capabilities format; raw fields are in 'field_capabilities'.
		$field_caps = $config['field_capabilities'] ?? array();
		if ( ! empty( $field_caps ) && class_exists( '\\WPTSALL\\Models\\Services\\Id_Mapping_Resolver' ) ) {
			$id_mapping_fields = \WPTSALL\Models\Services\Id_Mapping_Resolver::extract_id_mapping_fields(
				$field_caps
			);
			if ( ! empty( $id_mapping_fields ) ) {
				return $id_mapping_fields;
			}
		}
		// Tier 1 exhausted (default config or no id_mapping fields), fall through to Tier 2.
	}

	// ── Tier 2: model_id / direct rule lookup (no relation context) ──
	if ( $use_trs ) {
		$rule = null;

		if ( $model_id > 0 ) {
			$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule_by_post_type( $model_id, $post_type );
		} else {
			// No model_id — query any active rule for the post type.
			global $wpdb;
			$table = $wpdb->prefix . 'wptsall_translation_rules';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE data_type = 'post' AND object_name = %s AND is_active = 1 ORDER BY id DESC LIMIT 1",
						$table,
						$post_type
					),
					ARRAY_A
				);
				if ( $row ) {
					$rule = array( 'id' => (int) $row['id'] );
				}
			}
		}

		if ( $rule ) {
			$config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config( (int) $rule['id'] );
			if ( $config ) {
				$id_mapping_fields = \WPTSALL\Models\Services\Id_Mapping_Resolver::extract_id_mapping_fields(
					$config['fields'] ?? array()
				);
				return ! empty( $id_mapping_fields ) ? $id_mapping_fields : $core_fallback;
			}
		}
	}

	// ── Tier 3: core defaults ──
	return $core_fallback;
}

/**
 * Generic ID mapping handler
 *
 * Processes fields that need ID mapping using the unified Id_Mapping_Resolver.
 * Resolves source IDs to target IDs via the appropriate Mapping Services
 * (Post, Term, Media, User) based on field_capabilities configuration.
 *
 * @since 1.0.0
 * @since 1.3.0 Refactored to use Id_Mapping_Resolver.
 *
 * @param int    $source_blog   Source site ID.
 * @param int    $target_blog   Target site ID.
 * @param int    $new_id        New object ID.
 * @param string $post_type     Post type.
 * @param array  $complete_data Complete data with 'meta', 'post', 'taxonomies' keys.
 * @param array  $task          Optional. Task data with 'model_id', 'target_lang' etc. Default empty array.
 */
function wptsall_process_id_mapping_fields( $source_blog, $target_blog, $new_id, $post_type, $complete_data, $task = array() ) {
	$model_id = ! empty( $task['model_id'] ) ? (int) $task['model_id'] : 0;

	// Extract relation_id early so it can be passed to wptsall_get_id_mapping_fields().
	$relation_id = ! empty( $task['relation_id'] ) ? (int) $task['relation_id'] : ( ! empty( $task['site_id'] ) ? (int) $task['site_id'] : 0 );

	$id_mapping_fields = wptsall_get_id_mapping_fields( $post_type, $model_id, $relation_id );
	if ( empty( $id_mapping_fields ) ) {
		return;
	}

	$meta = $complete_data['meta'] ?? array();

	// For virtual targets, use the virtual site identifier for relation lookup.
	$target_identifier = $task['target_identifier'] ?? (string) $target_blog;
	if ( 0 === $relation_id ) {
		// Fallback: existing SQL lookup (for backward compatibility with tasks missing relation_id).
		global $wpdb;
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_table ) ) === $relations_table ) {
			$target_lang = $task['target_lang'] ?? $task['target_language'] ?? '';
			if ( ! empty( $target_lang ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$relation_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE source_site_id = %d AND target_site_id = %s AND target_lang = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
						$relations_table,
						$source_blog,
						$target_identifier,
						$target_lang
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$relation_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE source_site_id = %d AND target_site_id = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
						$relations_table,
						$source_blog,
						$target_identifier
					)
				);
			}
		}
	}

	$context = array(
		'relation_id'    => $relation_id,
		'source_blog_id' => $source_blog,
		'target_blog_id' => $target_blog,
		'target_type'    => $task['target_type'] ?? 'wp',
	);

	// Fields handled by dedicated functions (file copy, recursive sync) — skip in Resolver.
	$specialized_fields = array( '_thumbnail_id', '_product_image_gallery', 'post_parent' );

	foreach ( $id_mapping_fields as $field_name => $field_config ) {
		// Skip fields handled by specialized functions that perform side effects
		// (file copying, recursive parent sync) beyond simple ID mapping.
		if ( in_array( $field_name, $specialized_fields, true ) ) {
			continue;
		}

		$source_value = $meta[ $field_name ] ?? null;
		if ( empty( $source_value ) ) {
			continue;
		}

		// WordPress get_post_meta returns arrays; unwrap single-element arrays.
		// Note: wptsall_get_complete_post_data already unwraps single-element arrays,
		// but handle this defensively for other callers that may pass raw meta.
		if ( is_array( $source_value ) && isset( $source_value[0] ) && count( $source_value ) === 1 ) {
			$source_value = $source_value[0];
		}

		$result = \WPTSALL\Models\Services\Id_Mapping_Resolver::resolve(
			$field_name,
			$source_value,
			$field_config,
			$context
		);

		// Write back if fully or partially resolved — the Resolver preserves
		// source IDs as fallback for unresolved entries, so partial data is
		// better than no data.
		if ( null !== $result['target_value'] && '' !== $result['target_value'] ) {
			update_post_meta( $new_id, $field_name, $result['target_value'] );
		}
	}
}

/**
 * Process post_parent mapping
 *
 * @param int    $source_blog   Source site ID.
 * @param int    $target_blog   Target site ID.
 * @param int    $new_id        New post ID.
 * @param string $post_type     Post type.
 * @param array  $complete_data Complete data.
 * @param array  $task          Optional task context with model_id for rule filtering.
 */
function wptsall_process_post_parent_mapping( $source_blog, $target_blog, $new_id, $post_type, $complete_data, $task = array() ) {
    // Guard against infinite recursion from circular parent chains (e.g. A→B→A).
    static $processing_parents = array();
    $max_depth = 10;

    $source_parent = $complete_data['post']['post_parent'] ?? 0;
    if ( empty( $source_parent ) ) {
        return;
    }

    // Prevent circular references within the same source/relation scope. The
    // same numeric parent ID may legitimately occur in another relation.
    $parent_relation_key = absint( $task['relation_id'] ?? 0 );
    if ( $parent_relation_key <= 0 ) {
        $parent_relation_key = absint( $task['site_id'] ?? 0 );
    }
    $parent_key = (int) $source_blog . '_' . $parent_relation_key . '_' . (int) $source_parent;
    if ( isset( $processing_parents[ $parent_key ] ) ) {
        wptsall_log( 'task', 'warning', 'Circular parent reference detected, skipping', array( 'parent_key' => $parent_key ) );
        return;
    }

    // Enforce depth limit.
    $current_depth = count( $processing_parents );
    if ( $current_depth >= $max_depth ) {
        wptsall_log( 'task', 'warning', 'Parent mapping recursion depth limit reached', array( 'depth' => $current_depth, 'max' => $max_depth ) );
        return;
    }

    // Respect model rules — skip if post_parent is not in field_capabilities.
    $model_id    = ! empty( $task['model_id'] ) ? (int) $task['model_id'] : 0;
    $relation_id = absint( $task['relation_id'] ?? 0 );
    if ( $relation_id <= 0 ) {
        $relation_id = absint( $task['site_id'] ?? 0 );
    }
    if ( $relation_id <= 0 ) {
        // Parent IDs are local to a relation. An unscoped legacy task must not
        // resolve or write a parent through the relation_id=0 fallback.
        return;
    }
    $id_mapping_fields = wptsall_get_id_mapping_fields( $post_type, $model_id, $relation_id );
    if ( ! isset( $id_mapping_fields['post_parent'] ) ) {
        return;
    }

    // Parent post type is usually the same as current (page parent is page, post parent is post)
    // 'post_parent' => 'post_type' in id_mapping just indicates this is a post_type reference
    $parent_type = $post_type;

    // Look up already mapped parent ID
    $mapped_parent = wptsall_get_mapped_id(
        $source_blog,
        'post_type',
        $parent_type,
        intval( $source_parent ),
        $target_blog,
        $relation_id
    );

    if ( ! $mapped_parent ) {
        // Parent not mapped, need to sync parent first
        // Switch to source site to get parent data
        $current_blog = get_current_blog_id();
        $switched = false;

        if ( $source_blog && $source_blog !== $current_blog && is_multisite() ) {
            switch_to_blog( $source_blog );
            $switched = true;
        }

        try {
            $parent_complete_data = wptsall_get_complete_post_data( $parent_type, intval( $source_parent ) );
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }

        if ( $parent_complete_data ) {
            // Create parent task data
            $parent_task = array(
                'blog_id'           => $source_blog,
                'target_blog'       => $target_blog,
                'target_type'       => $task['target_type'] ?? 'wp',
                'target_identifier' => $task['target_identifier'] ?? $target_blog,
                'site_id'           => $task['site_id'] ?? 0,
                'relation_id'       => $relation_id,
                'template'          => $task['template'] ?? '',
                'object_type'       => 'post_type',
                'subtype'           => $parent_type,
                'object_id'         => intval( $source_parent ),
                'complete_data'     => $parent_complete_data,
            );

            // Mark this parent as in-progress before recursive call.
            $processing_parents[ $parent_key ] = true;

            // Recursively sync parent
            $parent_result = wptsall_process_task( $parent_task );

            // Unmark after recursive call completes.
            unset( $processing_parents[ $parent_key ] );

            if ( $parent_result && ! empty( $parent_result['success'] ) ) {
                $mapped_parent = $parent_result['target_id'] ?? wptsall_get_mapped_id(
                    $source_blog,
                    'post_type',
                    $parent_type,
                    intval( $source_parent ),
                    $target_blog,
                    $relation_id
                );
            }
        }
    }

    if ( $mapped_parent ) {
        wp_update_post( array(
            'ID'          => $new_id,
            'post_parent' => $mapped_parent,
        ) );
    }
}

/**
 * Process post attachment fields (featured image, gallery, etc.)
 *
 * @param int   $source_blog   Source site ID.
 * @param int   $target_blog   Target site ID.
 * @param int   $new_post_id   New post ID.
 * @param array $complete_data Complete data.
 * @param array $task          Optional task context with model_id for rule filtering.
 */
function wptsall_process_attachment_fields( $source_blog, $target_blog, $new_post_id, $complete_data, $task = array() ) {
    $meta = $complete_data['meta'] ?? array();
    $attachments = $complete_data['attachments'] ?? array();

    // Load model rules to respect field_capabilities (skip / id_mapping).
    $model_id    = ! empty( $task['model_id'] ) ? (int) $task['model_id'] : 0;
    $post_type   = $task['subtype'] ?? 'post';
    $relation_id = ! empty( $task['relation_id'] ) ? (int) $task['relation_id'] : ( ! empty( $task['site_id'] ) ? (int) $task['site_id'] : 0 );
    $id_mapping_fields = wptsall_get_id_mapping_fields( $post_type, $model_id, $relation_id );

    // Same-blog shortcut: virtual sites store posts on the main blog, so
    // source attachments already exist — no file copy needed, use source IDs directly.
    if ( (int) $source_blog === (int) $target_blog ) {
        // Featured image: just copy the source meta value as-is.
        if ( isset( $id_mapping_fields['_thumbnail_id'] ) && ! empty( $meta['_thumbnail_id'] ) ) {
            update_post_meta( $new_post_id, '_thumbnail_id', intval( $meta['_thumbnail_id'] ) );
        }
        // Product gallery: just copy the source meta value as-is.
        if ( isset( $id_mapping_fields['_product_image_gallery'] ) && ! empty( $meta['_product_image_gallery'] ) ) {
            update_post_meta( $new_post_id, '_product_image_gallery', sanitize_text_field( $meta['_product_image_gallery'] ) );
        }
        return;
    }

    // 1. Process featured image
    if ( isset( $id_mapping_fields['_thumbnail_id'] ) && ! empty( $meta['_thumbnail_id'] ) ) {
        $source_thumb_id = intval( $meta['_thumbnail_id'] );
        $thumb_url = $attachments['thumbnail']['url'] ?? '';
        $new_thumb_id = wptsall_sync_attachment( $source_blog, $source_thumb_id, $target_blog, $thumb_url, $relation_id );
        if ( $new_thumb_id ) {
            update_post_meta( $new_post_id, '_thumbnail_id', $new_thumb_id );
        }
    }

    // 2. Process product gallery (WooCommerce)
    if ( isset( $id_mapping_fields['_product_image_gallery'] ) && ! empty( $meta['_product_image_gallery'] ) ) {
        $gallery_ids = array_filter( array_map( 'intval', explode( ',', $meta['_product_image_gallery'] ) ) );
        $new_gallery_ids = array();

        // Build URL mapping
        $gallery_urls = array();
        if ( ! empty( $attachments['gallery'] ) ) {
            foreach ( $attachments['gallery'] as $item ) {
                $gallery_urls[ $item['id'] ] = $item['url'];
            }
        }

        foreach ( $gallery_ids as $gid ) {
            $url = $gallery_urls[ $gid ] ?? '';
            $new_gid = wptsall_sync_attachment( $source_blog, $gid, $target_blog, $url, $relation_id );
            if ( $new_gid ) {
                $new_gallery_ids[] = $new_gid;
            }
        }

        if ( ! empty( $new_gallery_ids ) ) {
            update_post_meta( $new_post_id, '_product_image_gallery', implode( ',', $new_gallery_ids ) );
        }
    }

    // 3. Process image URLs in content
    $content_images = $attachments['content_images'] ?? array();
    if ( ! empty( $content_images ) ) {
        wptsall_process_content_images( $source_blog, $target_blog, $new_post_id, $content_images, $relation_id );
    }
}

/**
 * Process image URL replacements in post content
 *
 * @param int   $source_blog    Source site ID.
 * @param int   $target_blog    Target site ID.
 * @param int   $post_id        Post ID.
 * @param array $content_images Image URL array in content.
 */
function wptsall_process_content_images( $source_blog, $target_blog, $post_id, $content_images, $relation_id = 0 ) {
    if ( empty( $content_images ) ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    $content = $post->post_content;
    $url_replacements = array();

    // Get source site upload directory URL
    $source_upload_url = '';
    if ( $source_blog && is_multisite() ) {
        switch_to_blog( $source_blog );
        $source_upload_dir = wp_upload_dir();
        $source_upload_url = $source_upload_dir['baseurl'];
        restore_current_blog();
    } else {
        $source_upload_dir = wp_upload_dir();
        $source_upload_url = $source_upload_dir['baseurl'];
    }

    // Get target site upload directory URL
    $target_upload_dir = wp_upload_dir();
    $target_upload_url = $target_upload_dir['baseurl'];

    foreach ( $content_images as $image_url ) {
        // Skip external images
        if ( ! wptsall_is_local_image( $image_url, $source_upload_url ) ) {
            continue;
        }

        // Try to find attachment ID from source site
        $attachment_id = wptsall_get_attachment_id_from_url( $image_url, $source_blog );

        if ( $attachment_id ) {
            // Sync attachment and get new URL
            $new_attachment_id = wptsall_sync_attachment( $source_blog, $attachment_id, $target_blog, $image_url, $relation_id );
            if ( $new_attachment_id ) {
                $new_url = wp_get_attachment_url( $new_attachment_id );
                if ( $new_url ) {
                    $url_replacements[ $image_url ] = $new_url;

                    // Process different image sizes
                    $size_urls = wptsall_get_image_size_urls( $image_url );
                    foreach ( $size_urls as $size_url ) {
                        if ( $size_url !== $image_url ) {
                            // Construct new URL for corresponding size
                            $new_size_url = wptsall_convert_image_size_url( $size_url, $image_url, $new_url );
                            if ( $new_size_url ) {
                                $url_replacements[ $size_url ] = $new_size_url;
                            }
                        }
                    }
                }
            }
        } else {
            // Attachment ID not found, try creating via download
            $new_attachment_id = wptsall_download_and_create_attachment( $image_url, $target_blog );
            if ( $new_attachment_id ) {
                $new_url = wp_get_attachment_url( $new_attachment_id );
                if ( $new_url ) {
                    $url_replacements[ $image_url ] = $new_url;
                }
            }
        }
    }

    // Execute replacements
    if ( ! empty( $url_replacements ) ) {
        // Sort by URL length descending to avoid partial match issues
        uksort( $url_replacements, function( $a, $b ) {
            return strlen( $b ) - strlen( $a );
        });

        foreach ( $url_replacements as $old_url => $new_url ) {
            $content = str_replace( $old_url, $new_url, $content );
        }

        wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $content,
        ) );
    }
}

/**
 * Check if image is local
 *
 * @param string $url            Image URL.
 * @param string $upload_baseurl Upload directory base URL.
 * @return bool
 */
function wptsall_is_local_image( $url, $upload_baseurl ) {
    if ( empty( $url ) || empty( $upload_baseurl ) ) {
        return false;
    }

    // Check if starts with upload directory
    if ( strpos( $url, $upload_baseurl ) === 0 ) {
        return true;
    }

    // Check if relative path or same domain
    $url_host = wp_parse_url( $url, PHP_URL_HOST );
    $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

    if ( $url_host === $site_host ) {
        return true;
    }

    // Check if contains /wp-content/uploads/
    if ( strpos( $url, '/wp-content/uploads/' ) !== false ) {
        return true;
    }

    return false;
}

/**
 * Get attachment ID from URL
 *
 * @param string $url       Image URL.
 * @param int    $blog_id   Site ID.
 * @return int|null
 */
function wptsall_get_attachment_id_from_url( $url, $blog_id = 0 ) {
    $switched = false;
    if ( $blog_id && $blog_id !== get_current_blog_id() && is_multisite() ) {
        switch_to_blog( $blog_id );
        $switched = true;
    }

    $attachment_id = attachment_url_to_postid( $url );

    // If not found, try removing size suffix and search again
    if ( ! $attachment_id ) {
        $clean_url = preg_replace( '/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $url );
        if ( $clean_url !== $url ) {
            $attachment_id = attachment_url_to_postid( $clean_url );
        }
    }

    if ( $switched ) {
        restore_current_blog();
    }

    return $attachment_id ?: null;
}

/**
 * Get URL variants for different image sizes
 *
 * @param string $url Original image URL.
 * @return array URL array.
 */
function wptsall_get_image_size_urls( $url ) {
    $urls = array( $url );

    // Extract base URL and extension
    if ( preg_match( '/^(.+)-(\d+x\d+)(\.[a-zA-Z]+)$/', $url, $matches ) ) {
        $base = $matches[1];
        $ext  = $matches[3];

        // Common WordPress image sizes
        $common_sizes = array(
            '150x150', '300x300', '768x768', '1024x1024',
            '100x100', '200x200', '400x400', '600x600', '800x800',
        );

        foreach ( $common_sizes as $size ) {
            $urls[] = $base . '-' . $size . $ext;
        }
    }

    return array_unique( $urls );
}

/**
 * Convert image size URL
 *
 * @param string $size_url Original size URL.
 * @param string $old_base Old base URL.
 * @param string $new_base New base URL.
 * @return string|null
 */
function wptsall_convert_image_size_url( $size_url, $old_base, $new_base ) {
    // Extract size suffix
    if ( preg_match( '/-(\d+x\d+)(\.[a-zA-Z]+)$/', $size_url, $matches ) ) {
        $size = $matches[1];
        $ext  = $matches[2];

        // Construct corresponding size from new URL
        $new_base_clean = preg_replace( '/(\.[a-zA-Z]+)$/', '', $new_base );
        return $new_base_clean . '-' . $size . $ext;
    }

    return null;
}

/**
 * Create attachment from a local URL.
 *
 * Resolves the URL to a local file path and copies it into the media library.
 * External URL download is disabled for security; only local uploads URLs are supported.
 *
 * @param string $url       Image URL (must be local uploads URL).
 * @param int    $blog_id   Target site ID.
 * @return int|null Attachment ID or null on failure.
 */
function wptsall_download_and_create_attachment( $url, $blog_id = 0 ) {
    $switched = false;
    if ( $blog_id && $blog_id !== get_current_blog_id() && is_multisite() ) {
        switch_to_blog( $blog_id );
        $switched = true;
    }

    $attachment_id = null;

    // Resolve local file from URL — only local uploads directory is supported.
    $upload_dir  = wp_upload_dir();
    $uploads_url = set_url_scheme( $upload_dir['baseurl'] );
    $local_file  = null;

    if ( 0 === strpos( $url, $uploads_url ) ) {
        $relative   = substr( $url, strlen( $uploads_url ) );
        $local_file = $upload_dir['basedir'] . $relative;
    }

    if ( $local_file && file_exists( $local_file ) ) {
        $filename = wp_unique_filename( $upload_dir['path'], basename( $local_file ) );
        $new_file = $upload_dir['path'] . '/' . $filename;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( copy( $local_file, $new_file ) ) {
            $filetype = wp_check_filetype( $filename );

            $attachment_data = array(
                'post_mime_type' => $filetype['type'],
                'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            );

            $attachment_id = wp_insert_attachment( $attachment_data, $new_file );

            if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attach_data = wp_generate_attachment_metadata( $attachment_id, $new_file );
                wp_update_attachment_metadata( $attachment_id, $attach_data );
            } else {
                $attachment_id = null;
            }
        }
    }

    if ( $switched ) {
        restore_current_blog();
    }

    return $attachment_id;
}

function wptsall_task_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wptsall_tasks';
}

function wptsall_task_log_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wptsall_task_logs';
}

/**
 * Task job aggregate table name
 *
 * @return string
 * @since 1.1.0
 */
function wptsall_task_jobs_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wptsall_task_jobs';
}


/**
 * Ensure tasks table exists (backward compatible wrapper)
 *
 * @deprecated 0.8.0 Use wptsall_create_tasks_table() instead
 * @see wptsall_create_tasks_table()
 */
function wptsall_ensure_task_table() {
    if ( function_exists( 'wptsall_create_tasks_table' ) ) {
        wptsall_create_tasks_table();
    }
}



/**
 * Write task job aggregate snapshot.
 *
 * @since 1.1.0
 * @param array $snapshot Aggregate snapshot.
 * @return bool
 */
function wptsall_upsert_task_job_snapshot( $snapshot ) {
    global $wpdb;

    if ( ! is_array( $snapshot ) ) {
        return false;
    }

    $job_id = sanitize_text_field( (string) ( $snapshot['job_id'] ?? '' ) );
    if ( '' === $job_id ) {
        return false;
    }

    $counts = is_array( $snapshot['counts'] ?? null ) ? $snapshot['counts'] : array();
    $table  = wptsall_task_jobs_table_name();
    $now    = current_time( 'mysql', true );

    static $jobs_table_exists = null;
    if ( null === $jobs_table_exists ) {
        $jobs_table_exists = ! function_exists( 'wptsall_task_jobs_table_exists' ) || wptsall_task_jobs_table_exists();
    }
    if ( ! $jobs_table_exists ) {
        return false;
    }
    $row    = array(
        'job_id'           => $job_id,
        'status'           => sanitize_key( (string) ( $snapshot['status'] ?? 'running' ) ),
        'progress'         => (float) ( $snapshot['progress'] ?? 0 ),
        'task_total'       => max( 0, (int) ( $snapshot['total'] ?? 0 ) ),
        'pending_count'    => max( 0, (int) ( $counts['pending'] ?? 0 ) ),
        'processing_count' => max( 0, (int) ( $counts['processing'] ?? 0 ) ),
        'retry_count'      => max( 0, (int) ( $counts['retry'] ?? 0 ) ),
        'failed_count'     => max( 0, (int) ( $counts['failed'] ?? 0 ) ),
        'completed_count'  => max( 0, (int) ( $counts['completed'] ?? 0 ) ),
        'other_count'      => max( 0, (int) ( $counts['other'] ?? 0 ) ),
        'latest_status'    => sanitize_key( (string) ( $snapshot['latest_status'] ?? '' ) ),
        'latest_task_id'   => max( 0, (int) ( $snapshot['latest_task_id'] ?? 0 ) ),
        'latest_at'        => sanitize_text_field( (string) ( $snapshot['latest_at'] ?? $now ) ),
        'updated_at'       => $now,
    );

    $existing_id = 0;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $existing_id = (int) $wpdb->get_var(
        $wpdb->prepare( 'SELECT id FROM %i WHERE job_id = %s LIMIT 1', $table, $job_id )
    );

    if ( $existing_id > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $table,
            $row,
            array( 'id' => $existing_id ),
            array( '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );
        return false !== $updated;
    }

    $row['created_at'] = $now;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $inserted = $wpdb->insert(
        $table,
        $row,
        array( '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
    );
    return false !== $inserted;
}

/**
 * Get task job aggregate snapshot (prefer database table, fallback to option snapshot).
 *
 * @since 1.1.0
 * @param string $job_id Job ID.
 * @return array
 */
function wptsall_get_task_job_snapshot( $job_id ) {
    global $wpdb;

    $job_id = sanitize_text_field( (string) $job_id );
    if ( '' === $job_id ) {
        return array();
    }

    static $jobs_table_exists = null;
    if ( null === $jobs_table_exists ) {
        $jobs_table_exists = ! function_exists( 'wptsall_task_jobs_table_exists' ) || wptsall_task_jobs_table_exists();
    }

    if ( $jobs_table_exists ) {
        $table = wptsall_task_jobs_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT job_id, status, progress, task_total, pending_count, processing_count, retry_count, failed_count, completed_count, other_count, latest_status, latest_task_id, latest_at, updated_at FROM %i WHERE job_id = %s LIMIT 1',
                $table,
                $job_id
            ),
            ARRAY_A
        );

        if ( is_array( $row ) && ! empty( $row ) ) {
            return array(
                'job_id'         => sanitize_text_field( (string) ( $row['job_id'] ?? '' ) ),
                'status'         => sanitize_key( (string) ( $row['status'] ?? '' ) ),
                'progress'       => (float) ( $row['progress'] ?? 0 ),
                'total'          => (int) ( $row['task_total'] ?? 0 ),
                'counts'         => array(
                    'pending'    => (int) ( $row['pending_count'] ?? 0 ),
                    'processing' => (int) ( $row['processing_count'] ?? 0 ),
                    'retry'      => (int) ( $row['retry_count'] ?? 0 ),
                    'failed'     => (int) ( $row['failed_count'] ?? 0 ),
                    'completed'  => (int) ( $row['completed_count'] ?? 0 ),
                    'other'      => (int) ( $row['other_count'] ?? 0 ),
                ),
                'latest_status'  => sanitize_key( (string) ( $row['latest_status'] ?? '' ) ),
                'latest_task_id' => (int) ( $row['latest_task_id'] ?? 0 ),
                'latest_at'      => sanitize_text_field( (string) ( $row['latest_at'] ?? '' ) ),
                'updated_at'     => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
            );
        }
    }

    $option_key = 'wptsall_job_aggregate_' . md5( $job_id );
    $snapshot   = get_option( $option_key, array() );
    return is_array( $snapshot ) ? $snapshot : array();
}

/**
 * Return statuses that should be treated as an open task for insertion dedupe.
 *
 * Completed/failed/cancelled rows are historical evidence and must not block a
 * new pending task for the same business object.
 *
 * @return array<int,string>
 */
function wptsall_get_open_task_statuses_for_insert() {
	return array( 'pending', 'retry', 'processing', 'active' );
}

/**
 * Find an existing open task for the same business object and task type.
 *
 * @param string $table                 Tasks table.
 * @param int    $effective_relation_id Relation/site id.
 * @param string $template              Template slug.
 * @param string $object_type           Object type.
 * @param string $subtype               Object subtype.
 * @param int    $object_id             Object id.
 * @param string $task_type             Normalized task type.
 * @return array|null
 */
function wptsall_find_open_task_for_insert( $table, $effective_relation_id, $template, $object_type, $subtype, $object_id, $task_type ) {
	global $wpdb;

	$open_statuses = wptsall_get_open_task_statuses_for_insert();
	$status_sql    = implode( ', ', array_fill( 0, count( $open_statuses ), '%s' ) );
	$payload_like  = '%' . $wpdb->esc_like( '"task_type":"' . $task_type . '"' ) . '%';

	$args = array_merge(
		array(
			$table,
			$effective_relation_id,
			$template,
			$object_type,
			$subtype,
			$object_id,
		),
		$open_statuses,
		array( $payload_like )
	);

	$row = wptsall_db_get_row(
		"SELECT id, status
			 FROM %i
			 WHERE relation_id = %d
			 AND template = %s
			 AND object_type = %s
			 AND subtype = %s
			 AND object_id = %d
			 AND status IN ({$status_sql})
			 AND payload LIKE %s
			 ORDER BY FIELD(status, 'pending', 'retry', 'processing', 'active'), id DESC
			 LIMIT 1",
		$args,
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Insert a task or reuse/update an existing open task.
 *
 * @param string $table Tasks table.
 * @param array  $data  Prepared DB row.
 * @param string $task_type Normalized task type.
 * @return array{action:string,id:int}
 */
function wptsall_insert_or_reuse_open_task( $table, array $data, $task_type ) {
	global $wpdb;

	$existing = wptsall_find_open_task_for_insert(
		$table,
		(int) ( $data['relation_id'] ?? 0 ),
		(string) ( $data['template'] ?? '' ),
		(string) ( $data['object_type'] ?? '' ),
		(string) ( $data['subtype'] ?? '' ),
		(int) ( $data['object_id'] ?? 0 ),
		(string) $task_type
	);

	if ( $existing ) {
		$existing_id     = (int) $existing['id'];
		$existing_status = sanitize_key( (string) $existing['status'] );

		if ( in_array( $existing_status, array( 'pending', 'retry' ), true ) ) {
			$update = $data;
			unset( $update['id'], $update['status'], $update['created_at'], $update['retry_count'] );
			$update['updated_at'] = current_time( 'mysql', true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $update, array( 'id' => $existing_id ) );
			return array(
				'action' => 'updated_open',
				'id'     => $existing_id,
			);
		}

		return array(
			'action' => 'reused_open',
			'id'     => $existing_id,
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$inserted = $wpdb->insert( $table, $data );
	if ( false === $inserted ) {
		// Race fallback: another process may have inserted the open row after
		// our lookup. Re-read and reuse instead of creating churn.
		$existing = wptsall_find_open_task_for_insert(
			$table,
			(int) ( $data['relation_id'] ?? 0 ),
			(string) ( $data['template'] ?? '' ),
			(string) ( $data['object_type'] ?? '' ),
			(string) ( $data['subtype'] ?? '' ),
			(int) ( $data['object_id'] ?? 0 ),
			(string) $task_type
		);
		if ( $existing ) {
			return array(
				'action' => 'reused_after_insert_conflict',
				'id'     => (int) $existing['id'],
			);
		}

		return array(
			'action' => 'failed',
			'id'     => 0,
		);
	}

	return array(
		'action' => 'inserted',
		'id'     => (int) $wpdb->insert_id,
	);
}

function wptsall_insert_tasks( $tasks, $lang_from = '', $lang_to = '' ) {
	global $wpdb;
	$table = wptsall_table( 'tasks' );
	$now   = current_time( 'mysql', true );
	$batch_job_id = '';
	foreach ( (array) $tasks as $task_seed ) {
		if ( ! is_array( $task_seed ) ) {
			continue;
		}
		$candidate_job = sanitize_text_field( (string) ( $task_seed['job_id'] ?? '' ) );
		if ( '' !== $candidate_job ) {
			$batch_job_id = $candidate_job;
			break;
		}
	}
	if ( '' === $batch_job_id ) {
		$batch_job_id = wptsall_generate_task_job_id( 'insert_tasks' );
	}

	$count = 0;
	$stats = array(
		'inserted'                     => 0,
		'updated_open'                 => 0,
		'reused_open'                  => 0,
		'reused_after_insert_conflict' => 0,
		'failed'                       => 0,
	);
	foreach ( $tasks as $task ) {
		if ( is_array( $task ) && empty( $task['job_id'] ) ) {
			$task['job_id'] = $batch_job_id;
		}
		// Normalize object_type aliases before storing.
		// The Rust client and monitoring service may send "post"/"term" but
		// the dispatch block in wptsall_process_task() expects "post_type"/"taxonomy".
		$object_type = sanitize_key( $task['object_type'] ?? '' );
		if ( 'post' === $object_type ) {
			$object_type = 'post_type';
		} elseif ( 'term' === $object_type ) {
			$object_type = 'taxonomy';
		}
		$task['object_type'] = $object_type;

		$task = wptsall_prepare_task_for_client_payload( $task, 'text' );
		$payload = wp_json_encode( $task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$task_type = sanitize_key( (string) ( $task['task_type'] ?? 'text' ) );
        // Unify site_id and relation_id: both store the site relation ID.
        // site_id is deprecated; relation_id is the canonical column.
        $effective_relation_id = intval( $task['relation_id'] ?? $task['site_id'] ?? 0 );

		$data = array(
			'blog_id'           => isset( $task['blog_id'] ) ? intval( $task['blog_id'] ) : get_current_blog_id(),
			'target_blog'       => isset( $task['target_blog'] ) ? intval( $task['target_blog'] ) : 0,
			'target_type'       => sanitize_key( $task['target_type'] ?? '' ),
			'target_identifier' => sanitize_text_field( $task['target_identifier'] ?? '' ),
			'site_id'           => $effective_relation_id,
			'relation_id'       => $effective_relation_id,
			'template'          => sanitize_key( $task['template'] ?? '' ),
			'object_type'       => $object_type,
			'subtype'           => isset( $task['subtype'] ) ? sanitize_key( $task['subtype'] ) : '',
			'object_id'         => isset( $task['object_id'] ) ? intval( $task['object_id'] ) : 0,
			'lang_from'         => sanitize_text_field( $task['lang_from'] ?? $lang_from ),
			'lang_to'           => sanitize_text_field( $task['lang_to'] ?? $lang_to ),
			'site_mode'         => sanitize_text_field( $task['site_mode'] ?? '' ),
			'status'            => 'pending',
			'retry_count'       => 0,
			'payload'           => $payload,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$result = wptsall_insert_or_reuse_open_task( $table, $data, $task_type );
		$stats[ $result['action'] ] = (int) ( $stats[ $result['action'] ] ?? 0 ) + 1;
		if ( 'failed' !== $result['action'] ) {
			$count++;
		}
    }
    wptsall_log( 'task', 'info', 'insert_tasks', array_merge( array( 'count' => $count ), $stats ) );
	return array_merge( array( 'count' => $count ), $stats );
}

/**
 * Get virtual site content table name
 *
 * @since 0.7.0 Unified to use virtual_site_content table
 * @return string Table name
 */
function wptsall_virtual_content_table() {
    return wptsall_table( 'virtual_site_content' );
}

/**
 * Check whether the legacy virtual content table exists.
 *
 * @return bool
 */
function wptsall_virtual_content_table_exists() {
    static $exists = null;
    if ( null === $exists ) {
        global $wpdb;
        $table = wptsall_virtual_content_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );
        $exists = ( $result === $table );
    }
    return $exists;
}







function wptsall_ensure_mapping_table() {
    global $wpdb;
    // Use base_prefix to ensure mapping table is on the main site, shared across sites
    $table = $wpdb->base_prefix . 'wptsall_mappings';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        source_blog_id bigint(20) unsigned NOT NULL,
        source_object_type varchar(50) NOT NULL,
        source_subtype varchar(100) NOT NULL,
        source_object_id bigint(20) unsigned NOT NULL,
        target_blog_id bigint(20) unsigned NOT NULL,
        target_object_id bigint(20) unsigned NOT NULL,
        model varchar(100) NOT NULL,
        relation_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY source_idx (source_blog_id, source_object_type, source_subtype, source_object_id),
        KEY relation_source_idx (relation_id, source_blog_id, source_object_type, source_subtype, source_object_id, target_blog_id),
        KEY target_idx (target_blog_id, target_object_id)
    ) {$charset_collate};";
    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    dbDelta( $sql );
    if ( function_exists( 'wptsall_migrate_legacy_mapping_relation_id_v210' ) ) {
        wptsall_migrate_legacy_mapping_relation_id_v210();
    }
}

// Deprecated: plugins_loaded hooks for table creation removed in 1.4.0.
// Tables are now created by the migration system in core/database/migrations.php.
// The ensure_*_table() functions remain available for manual invocation if needed.

function wptsall_log_task_event( $task_id, $from, $to, $note = '', $context = array() ) {
    global $wpdb;
    $task_id = intval( $task_id );
    if ( ! $task_id ) {
        return;
    }
    $table = wptsall_task_log_table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->insert(
        $table,
        array(
            'task_id'     => $task_id,
            'status_from' => sanitize_text_field( $from ),
            'status_to'   => sanitize_text_field( $to ),
            'note'        => wp_kses_post( $note ),
            'context'     => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'created_at'  => current_time( 'mysql', true ),
        )
    );
}

/**
 * Check whether a task status value is valid.
 *
 * @param string $status Status string to validate.
 * @return bool
 */
function wptsall_is_valid_task_status( $status ) {
    $valid = array( 'pending', 'processing', 'active', 'paused', 'completed', 'skipped', 'retry', 'failed', 'error' );
    return in_array( $status, $valid, true );
}

function wptsall_update_task_status( $row, $status_to, $note = '', $increment_retry = false, $context = array() ) {
    global $wpdb;
    if ( ! $row || empty( $row['id'] ) ) {
        return;
    }
    if ( ! wptsall_is_valid_task_status( $status_to ) ) {
        wptsall_log_error( 'tasks', 'Invalid task status rejected', array( 'task_id' => $row['id'], 'status' => $status_to ) );
        return;
    }
    $table = wptsall_table( 'tasks' );
    $retry = intval( $row['retry_count'] ?? 0 );
    if ( $increment_retry ) {
        $retry++;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->update(
        $table,
        array(
            'status'      => $status_to,
            'status_note' => $note,
            'retry_count' => $retry,
            'updated_at'  => current_time( 'mysql', true ),
        ),
        array( 'id' => intval( $row['id'] ) )
    );
    wptsall_log_task_event( $row['id'], $row['status'] ?? '', $status_to, $note, $context );
}





/**
 * Re-queue failed tasks
 *
 * @param int $task_id Task ID.
 * @return bool Whether successful.
 */
function wptsall_requeue_failed_task( $task_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wptsall_tasks';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
        $table,
        array(
            'status'      => 'pending',
            'retry_count' => 0,
            'status_note' => 'Manually re-queued',
            'updated_at'  => current_time( 'mysql', true ),
        ),
        array(
            'id'     => intval( $task_id ),
            'status' => 'failed',
        )
    );

    if ( $result ) {
        wptsall_log( 'task', 'info', 'requeued_task', array( 'task_id' => $task_id ) );
    }

    return $result !== false;
}


function wptsall_fetch_task_row( $blog_id, $object_id, $subtype, $template ) {
    global $wpdb;
    $table = wptsall_table( 'tasks' );
    return $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM %i WHERE blog_id = %d AND object_id = %d AND subtype = %s AND template = %s ORDER BY id DESC LIMIT 1',
            $table,
            intval( $blog_id ),
            intval( $object_id ),
            sanitize_key( $subtype ),
            sanitize_key( $template )
        ),
        ARRAY_A
    );
}

/**
 * Get comprehensive task statistics.
 *
 * Returns detailed statistics for task management UI including
 * overview, by priority, by template, by object type, and time-based.
 *
 * @since 0.9.2
 * @return array Comprehensive statistics.
 */
function wptsall_get_task_statistics() {
	global $wpdb;
	$table = wptsall_table( 'tasks' );

	// Default structure.
	$result = array(
		'overview'       => array(
			'total'        => 0,
			'pending'      => 0,
			'processing'   => 0,
			'completed'    => 0,
			'failed'       => 0,
			'retry'        => 0,
			'success_rate' => 0,
		),
		'by_priority'    => array(
			'high'   => 0,
			'normal' => 0,
			'low'    => 0,
		),
		'by_template'    => array(),
		'by_object_type' => array(),
		'time_based'     => array(
			'today'      => 0,
			'this_week'  => 0,
			'this_month' => 0,
		),
	);

	// Get status counts.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$status_stats = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT status, COUNT(*) as count FROM %i GROUP BY status',
			$table
		),
		ARRAY_A
	);

	$total_tasks     = 0;
	$completed_tasks = 0;

	foreach ( $status_stats as $row ) {
		$status = $row['status'];
		$count  = intval( $row['count'] );
		if ( isset( $result['overview'][ $status ] ) ) {
			$result['overview'][ $status ] = $count;
		}
		$total_tasks += $count;
		if ( 'completed' === $status ) {
			$completed_tasks = $count;
		}
	}

	$result['overview']['total'] = $total_tasks;
	if ( $total_tasks > 0 ) {
		$result['overview']['success_rate'] = round( ( $completed_tasks / $total_tasks ) * 100, 1 );
	}

	// Get priority counts.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$priority_stats = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT priority, COUNT(*) as count FROM %i GROUP BY priority',
			$table
		),
		ARRAY_A
	);

	foreach ( $priority_stats as $row ) {
		$priority = $row['priority'];
		if ( isset( $result['by_priority'][ $priority ] ) ) {
			$result['by_priority'][ $priority ] = intval( $row['count'] );
		}
	}

	// Get template counts (top 10).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$template_stats = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT template, COUNT(*) as count FROM %i WHERE template != '' GROUP BY template ORDER BY count DESC LIMIT 10",
			$table
		),
		ARRAY_A
	);

	foreach ( $template_stats as $row ) {
		$result['by_template'][ $row['template'] ] = intval( $row['count'] );
	}

	// Get object type counts.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$object_stats = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT object_type, subtype, COUNT(*) as count FROM %i GROUP BY object_type, subtype',
			$table
		),
		ARRAY_A
	);

	foreach ( $object_stats as $row ) {
		$key = $row['object_type'];
		if ( ! empty( $row['subtype'] ) ) {
			$key .= '/' . $row['subtype'];
		}
		$result['by_object_type'][ $key ] = intval( $row['count'] );
	}

	// Get time-based counts.
	$today      = gmdate( 'Y-m-d 00:00:00' );
	$week_start = gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
	$month_start = gmdate( 'Y-m-01 00:00:00' );

	// Today.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result['time_based']['today'] = intval(
		$wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table,
				$today
			)
		)
	);

	// This week.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result['time_based']['this_week'] = intval(
		$wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table,
				$week_start
			)
		)
	);

	// This month.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$result['time_based']['this_month'] = intval(
		$wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table,
				$month_start
			)
		)
	);

	return $result;
}

/**
 * Convert plugin mapping to template format.
 *
 * Transforms a plugin mapping record into a template structure
 * suitable for task generation and REST API responses.
 *
 * @since 0.9.2
 * @param array $mapping Plugin mapping data from database.
 * @return array|null Template data or null if invalid.
 */
function wptsall_mapping_to_template( $mapping ) {
	if ( empty( $mapping ) || ! is_array( $mapping ) ) {
		return null;
	}

	$plugin_slug = $mapping['plugin_slug'] ?? '';
	$plugin_name = $mapping['plugin_name'] ?? $plugin_slug;

	if ( empty( $plugin_slug ) ) {
		return null;
	}

	// Parse post_types and taxonomies.
	$post_types = array();
	if ( ! empty( $mapping['post_types'] ) ) {
		$post_types = is_string( $mapping['post_types'] )
			? json_decode( $mapping['post_types'], true )
			: $mapping['post_types'];
	}

	$taxonomies = array();
	if ( ! empty( $mapping['taxonomies'] ) ) {
		$taxonomies = is_string( $mapping['taxonomies'] )
			? json_decode( $mapping['taxonomies'], true )
			: $mapping['taxonomies'];
	}

	// Build template structure.
	$template = array(
		'id'               => $mapping['id'] ?? 0,
		'plugin_slug'      => $plugin_slug,
		'plugin_name'      => $plugin_name,
		'post_types'       => is_array( $post_types ) ? $post_types : array(),
		'taxonomies'       => is_array( $taxonomies ) ? $taxonomies : array(),
		'is_content_plugin' => ! empty( $mapping['is_content_plugin'] ),
		'status'           => $mapping['status'] ?? 'active',
		'created_at'       => $mapping['created_at'] ?? '',
		'updated_at'       => $mapping['updated_at'] ?? '',
	);

	// Include field capabilities if available.
	if ( ! empty( $mapping['scan_result'] ) ) {
		$scan_result = is_string( $mapping['scan_result'] )
			? json_decode( $mapping['scan_result'], true )
			: $mapping['scan_result'];

		if ( is_array( $scan_result ) ) {
			$template['field_capabilities'] = $scan_result;
		}
	}

	return $template;
}

/**
 * Process task - write translation results to target site
 *
 * This function is the write-back entry point for client callbacks:
 * Called by client-tasks-rest-controller.php after receiving client translation results,
 * writes translation content to virtual site or WP subsite.
 *
 * @param array $task Task data.
 * @return array { success: bool, note: string, target_id: int }
 */
function wptsall_process_task( $task ) {
    // Normalize object_type aliases at runtime — tasks stored before the
    // insert-time normalization may still carry "post" or "term".
    $ot = sanitize_key( $task['object_type'] ?? '' );
    if ( 'post' === $ot ) {
        $task['object_type'] = 'post_type';
    } elseif ( 'term' === $ot ) {
        $task['object_type'] = 'taxonomy';
    }

    $status_note = '';
    $target_blog = $task['target_blog'] ?? 0;
    $target_type = $task['target_type'] ?? ( $target_blog ? 'wp' : 'virtual' );
    $target_identifier = $task['target_identifier'] ?? $target_blog;
    // relation_id is the scope authority. Only use the deprecated site_id
    // field when a legacy task has no relation_id at all; never let a present
    // zero-valued site_id hide a positive relation_id.
    $site_id = absint( $task['relation_id'] ?? 0 );
    if ( $site_id <= 0 ) {
        $site_id = absint( $task['site_id'] ?? 0 );
    }
    if ( $site_id <= 0 ) {
        return array( 'success' => false, 'note' => 'A positive relation_id is required for task write-back', 'target_id' => 0 );
    }
    $template = $task['template'] ?? '';
    $success     = false;
    $new_id      = 0;
    $source_blog = $task['blog_id'] ?? get_current_blog_id();
    $restore     = false;
    $site_marker = ' [site_id=' . $target_identifier . ']';

    wptsall_log( 'task', 'debug', 'process_task start', array(
        'target_type'  => $target_type,
        'target_blog'  => $target_blog,
        'object'       => $task['object_type'] . ':' . $task['subtype'],
        'source_blog'  => $source_blog,
        'object_id'    => $task['object_id'],
    ) );

    // Prefer complete data, otherwise fetch from source site
    $complete_data = $task['complete_data'] ?? null;

    if ( ! $complete_data ) {
        // Switch to source site to fetch complete data
        $switched = false;
        if ( $source_blog && $source_blog !== get_current_blog_id() && is_multisite() ) {
            switch_to_blog( $source_blog );
            $switched = true;
        }

        try {
            $complete_data = wptsall_get_complete_object_data(
                $task['object_type'],
                $task['subtype'],
                $task['object_id']
            );
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    if ( ! $complete_data ) {
        return array( 'success' => false, 'note' => 'Unable to fetch source object data', 'target_id' => 0 );
    }

    // Switch to target site
    if ( $target_blog && 'wp' === $target_type && is_multisite() ) {
        $restore = true;
        switch_to_blog( $target_blog );
    }

    try {
        if ( 'post_type' === $task['object_type'] ) {
            $result = wptsall_process_post_task( $task, $complete_data, $target_type, $site_id, $template, $site_marker );
            $success     = $result['success'];
            $new_id      = $result['new_id'];
            $status_note = $result['note'];

            if ( $success && $new_id && 'wp' === $target_type ) {
                wptsall_insert_mapping(
                    $source_blog,
                    $task['object_type'],
                    $task['subtype'],
                    $task['object_id'],
                    get_current_blog_id(),
                    $new_id,
                    $task['subtype'],
                    (int) $site_id
                );
            }
        } elseif ( 'taxonomy' === $task['object_type'] ) {
            $result = wptsall_process_term_task( $task, $complete_data, $target_type, $site_id, $template, $site_marker );
            $success     = $result['success'];
            $new_id      = $result['new_id'];
            $status_note = $result['note'];

            if ( $success && $new_id && 'wp' === $target_type ) {
                wptsall_insert_mapping(
                    $source_blog,
                    $task['object_type'],
                    $task['subtype'],
                    $task['object_id'],
                    get_current_blog_id(),
                    $new_id,
                    $task['subtype'],
                    (int) $site_id
                );
            }
        } else {
            throw new Exception( 'Unsupported object type: ' . $task['object_type'] );
        }
    } catch ( \Throwable $e ) {
        $status_note = $e->getMessage();
        wptsall_log( 'task', 'error', 'process_task error', array( 'error' => $e->getMessage() ) );
    } finally {
        if ( $restore ) {
            restore_current_blog();
        }
    }

    // Field mapping services are blog-local. The write-back above runs in the
    // target blog, so also materialize the authoritative relation-scoped row
    // in the source blog used by discovery/claim. The legacy shared mapping
    // row remains for compatibility, but must not be the only projection.
    if ( $success && $new_id && class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) && in_array( $task['object_type'], array( 'post_type', 'taxonomy' ), true ) ) {
        $mapping_source_blog = absint( $source_blog );
        $mapping_target_site = (string) ( $target_identifier ?? $target_blog );
        $mapping_switched    = false;
        if ( is_multisite() && $mapping_source_blog > 0 && $mapping_source_blog !== get_current_blog_id() ) {
            switch_to_blog( $mapping_source_blog );
            $mapping_switched = true;
        }
        try {
            $mapping_data = array(
                'source_site_id'    => $mapping_source_blog > 0 ? $mapping_source_blog : (int) get_current_blog_id(),
                'source_post_id'    => (int) $task['object_id'],
                'source_post_type'  => (string) $task['subtype'],
                'target_post_id'    => (int) $new_id,
                'target_post_type'  => (string) $task['subtype'],
                'target_site_id'    => $mapping_target_site,
                'relation_id'       => (int) $site_id,
                'relationship_type' => 'translation',
            );
            if ( 'taxonomy' === $task['object_type'] && class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
                \WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
                    array(
                        'source_term_id'     => (int) $task['object_id'],
                        'source_taxonomy'    => (string) $task['subtype'],
                        'source_site_id'     => $mapping_data['source_site_id'],
                        'source_lang'        => (string) ( $task['source_lang'] ?? $task['source_language'] ?? '' ),
                        'target_term_id'     => (int) $new_id,
                        'target_taxonomy'    => (string) $task['subtype'],
                        'target_site_id'     => $mapping_target_site,
                        'target_lang'       => (string) ( $task['target_lang'] ?? $task['target_language'] ?? '' ),
                        'relation_id'       => (int) $site_id,
                        'mapping_method'    => 'task_sync',
                        'translation_method'=> 'task_sync',
                    )
                );
            } else {
                \WPTSALL\Models\Services\Post_Mapping_Service::create_mapping( $mapping_data );
            }
        } finally {
            if ( $mapping_switched ) {
                restore_current_blog();
            }
        }
    }

    return array( 'success' => $success, 'note' => $status_note, 'target_id' => $new_id );
}

/**
 * Process Post Type task - full cross-table sync (write-back)
 *
 * @param array  $task          Task data.
 * @param array  $complete_data Complete object data.
 * @param string $target_type   Target type (virtual/wp).
 * @param int    $site_id       Site relation ID.
 * @param string $template      Template name.
 * @param string $site_marker   Site marker.
 * @return array { success: bool, new_id: int, note: string }
 */
function wptsall_process_post_task( $task, $complete_data, $target_type, $site_id, $template, $site_marker ) {
    $post_data = $complete_data['post'] ?? array();

    if ( empty( $post_data ) ) {
        return array( 'success' => false, 'new_id' => 0, 'note' => 'Missing post data' );
    }

    // Build post array
    $postarr = array(
        'post_type'      => $task['subtype'],
        'post_status'    => $post_data['post_status'] ?? 'publish',
        'post_title'     => $post_data['post_title'] ?? '',
        'post_content'   => $post_data['post_content'] ?? '',
        'post_excerpt'   => $post_data['post_excerpt'] ?? '',
        'post_name'      => $post_data['post_name'] ?? '',
        'post_date'      => $post_data['post_date'] ?? current_time( 'mysql' ),
        'post_author'    => absint( $post_data['post_author'] ?? 0 ) ?: ( get_current_user_id() ?: 1 ),
        'menu_order'     => $post_data['menu_order'] ?? 0,
        'comment_status' => $post_data['comment_status'] ?? 'open',
        'ping_status'    => $post_data['ping_status'] ?? 'open',
    );

    // Source blog is needed by both virtual and wp branches for shared post-processing.
    $source_blog = $task['blog_id'] ?? get_current_blog_id();
    $new_id      = 0;
    $note_prefix = '';

    // Collect id_mapping field keys so the meta loop below skips them.
    // These fields contain source IDs that must be resolved first by
    // wptsall_process_id_mapping_fields() in the shared post-processing section.
    $model_id          = ! empty( $task['model_id'] ) ? (int) $task['model_id'] : 0;
    $relation_id       = ! empty( $task['relation_id'] ) ? (int) $task['relation_id'] : ( ! empty( $task['site_id'] ) ? (int) $task['site_id'] : 0 );
    $id_mapping_fields = wptsall_get_id_mapping_fields( $task['subtype'], $model_id, $relation_id );
    $id_mapping_keys   = array_keys( $id_mapping_fields );

    if ( 'virtual' === $target_type ) {
        // Virtual site: use wp_posts + meta storage (v0.8.0+ replaces deprecated virtual_site_content table)
        $virtual_site_id = $task['target_identifier'] ?? '';
        if ( empty( $virtual_site_id ) ) {
            // target_identifier should always be set from relation's target_site_id during task creation.
            // Fallback: look up the relation to get the real target_site_id.
            $fallback_relation = function_exists( 'wptsall_get_site_relation' ) ? wptsall_get_site_relation( $site_id ) : null;
            $virtual_site_id   = $fallback_relation ? (string) $fallback_relation['target_site_id'] : ( 'v_' . $site_id );
        }
        if ( ! str_starts_with( $virtual_site_id, 'v_' ) && ctype_digit( (string) $virtual_site_id ) ) {
            $virtual_site_id = 'v_' . $virtual_site_id;
        }

        // Check if virtual post already exists.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT p.ID FROM %i p
                INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
                INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
                WHERE pm1.meta_value = %s
                AND pm2.meta_value = %d
                AND p.post_type = %s
                LIMIT 1",
                $wpdb->posts,
                $wpdb->postmeta,
                $wpdb->postmeta,
                $virtual_site_id,
                (int) $task['object_id'],
                $task['subtype']
            )
        );

        if ( $existing_id ) {
            // Update existing virtual post.
            $postarr['ID'] = (int) $existing_id;
            wp_update_post( $postarr );
            $new_id = (int) $existing_id;
        } else {
            // Create new virtual post.
            $new_id = wp_insert_post( $postarr, true );
            if ( is_wp_error( $new_id ) ) {
                return array( 'success' => false, 'new_id' => 0, 'note' => $new_id->get_error_message() );
            }
        }

        // Add virtual site markers (always include relation_id for identity parity).
        $relation_id_for_meta = (int) ( $task['relation_id'] ?? $task['site_id'] ?? 0 );
        if ( $relation_id_for_meta > 0 ) {
            \WPTSALL\Sites\Services\Translation_Identity::ensure_markers(
                (int) $new_id,
                (int) $task['object_id'],
                $relation_id_for_meta,
                array(
                    'source_blog_id'  => (int) $source_blog,
                    'virtual_site_id' => (string) $virtual_site_id,
                    'post_type'       => (string) $task['subtype'],
                )
            );
        } else {
            \WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, $virtual_site_id );
            \WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_id, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_POST_ID, (int) $task['object_id'] );
            \WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_id, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_BLOG_ID, (int) $source_blog );
        }
        \WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

        // Sync meta data (skip internal markers, attachment fields, and id_mapping fields).
        // id_mapping fields are resolved with correct target IDs in the shared post-processing section.
        $attachment_meta_keys = array( '_thumbnail_id', '_product_image_gallery' );
        if ( ! empty( $complete_data['meta'] ) ) {
            foreach ( $complete_data['meta'] as $meta_key => $meta_value ) {
                if ( strpos( $meta_key, '_wptsall_' ) === 0 ) {
                    continue;
                }
                if ( in_array( $meta_key, $attachment_meta_keys, true ) ) {
                    continue; // Attachment fields handled separately below.
                }
                if ( in_array( $meta_key, $id_mapping_keys, true ) ) {
                    continue; // id_mapping fields resolved in shared post-processing.
                }
                update_post_meta( $new_id, $meta_key, $meta_value );
            }
        }

        $note_prefix = 'Written to virtual site storage (wp_posts + meta)';

    } else {
        // WordPress site: create actual content
        $new_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $new_id ) ) {
            return array( 'success' => false, 'new_id' => 0, 'note' => $new_id->get_error_message() );
        }

        // Sync metadata (skip attachment fields and id_mapping fields that need special handling).
        // id_mapping fields are resolved with correct target IDs in the shared post-processing section.
        $attachment_meta_keys = array( '_thumbnail_id', '_product_image_gallery' );
        if ( ! empty( $complete_data['meta'] ) ) {
            foreach ( $complete_data['meta'] as $meta_key => $meta_value ) {
                if ( in_array( $meta_key, $attachment_meta_keys, true ) ) {
                    continue; // Attachment fields handled separately.
                }
                if ( in_array( $meta_key, $id_mapping_keys, true ) ) {
                    continue; // id_mapping fields resolved in shared post-processing.
                }
                update_post_meta( $new_id, $meta_key, $meta_value );
            }
        }

        // Save origin tracking meta for deduplication (wp targets only; virtual uses _wptsall markers).
        update_post_meta( $new_id, '_wptsall_origin_site_type', 'wp' );
        update_post_meta( $new_id, '_wptsall_origin_site_id', (string) $source_blog );
        update_post_meta( $new_id, '_wptsall_origin_object_type', $task['object_type'] );
        update_post_meta( $new_id, '_wptsall_origin_subtype', $task['subtype'] );
        update_post_meta( $new_id, '_wptsall_origin_object_id', (string) $task['object_id'] );

        $note_prefix = 'Synced to site ' . get_current_blog_id();
    }

    // Determine the target site identifier for mapping records.
    // Virtual sites use the virtual site ID (e.g., "v_3"), wp sites use the current blog ID.
    $target_site_identifier = ( 'virtual' === $target_type )
        ? $virtual_site_id
        : (string) get_current_blog_id();

    // === Shared post-processing: runs for both virtual and wp targets ===

    // Process attachment fields (featured image, product gallery)
    wptsall_process_attachment_fields( $source_blog, get_current_blog_id(), $new_id, $complete_data, $task );

    // Sync taxonomy associations
    if ( ! empty( $complete_data['taxonomies'] ) ) {
        foreach ( $complete_data['taxonomies'] as $taxonomy => $terms ) {
            // Sort by hierarchy first, ensure parents are created first
            usort( $terms, function( $a, $b ) {
                $a_parent = $a['parent'] ?? 0;
                $b_parent = $b['parent'] ?? 0;
                if ( $a_parent == 0 && $b_parent > 0 ) return -1;
                if ( $a_parent > 0 && $b_parent == 0 ) return 1;
                return 0;
            });

            $term_ids = array();
            $source_to_target_term_map = array(); // source term_id => target term_id

            foreach ( $terms as $term_data ) {
                // Find or create term
                $existing = get_term_by( 'slug', $term_data['slug'], $taxonomy );
                if ( $existing ) {
                    $term_ids[] = $existing->term_id;
                    $source_to_target_term_map[ $term_data['term_id'] ] = $existing->term_id;

                    // Register slug-matched term in the new term_mappings store.
                    if ( class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
                        $target_lang = $task['target_lang'] ?? $task['target_language'] ?? 'en_US';
                        \WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
                            array(
                                'source_term_id'     => $term_data['term_id'],
                                'source_taxonomy'    => $taxonomy,
                                'source_site_id'     => (int) $source_blog,
                                'source_lang'        => $task['source_lang'] ?? $task['source_language'] ?? '',
                                'target_term_id'     => $existing->term_id,
                                'target_taxonomy'    => $taxonomy,
                                'target_site_id'     => $target_site_identifier,
                                'target_lang'        => $target_lang,
                                'relation_id'        => (int) $site_id,
                                'mapping_method'     => 'auto_match',
                                'translation_method' => 'slug_match',
                            )
                        );
                    }
                } else {
                    // Create new term
                    $args = array(
                        'slug'        => $term_data['slug'],
                        'description' => $term_data['description'] ?? '',
                    );

                    // Process parent
                    if ( ! empty( $term_data['parent'] ) ) {
                        // Prefer looking up from current sync mapping
                        if ( isset( $source_to_target_term_map[ $term_data['parent'] ] ) ) {
                            $args['parent'] = $source_to_target_term_map[ $term_data['parent'] ];
                        } else {
                            // Look up already synced parent from mapping table
                            $mapped_parent = wptsall_get_mapped_id(
                                $source_blog,
                                'taxonomy',
                                $taxonomy,
                                $term_data['parent'],
                                get_current_blog_id(),
                                (int) $site_id
                            );
                            if ( $mapped_parent ) {
                                $args['parent'] = $mapped_parent;
                            }
                        }
                    }

                    $inserted = wp_insert_term( $term_data['name'], $taxonomy, $args );
                    if ( ! is_wp_error( $inserted ) ) {
                        $term_ids[] = $inserted['term_id'];
                        $source_to_target_term_map[ $term_data['term_id'] ] = $inserted['term_id'];

                        // Save term mapping (legacy store).
                        wptsall_insert_mapping(
                            $source_blog,
                            'taxonomy',
                            $taxonomy,
                            $term_data['term_id'],
                            get_current_blog_id(),
                            $inserted['term_id'],
                            $taxonomy,
                            (int) $site_id
                        );

                        // Also register in the new term_mappings store.
                        if ( class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
                            $target_lang = $task['target_lang'] ?? $task['target_language'] ?? 'en_US';
                            \WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
                                array(
                                    'source_term_id'     => $term_data['term_id'],
                                    'source_taxonomy'    => $taxonomy,
                                    'source_site_id'     => (int) $source_blog,
                                    'source_lang'        => $task['source_lang'] ?? $task['source_language'] ?? '',
                                    'target_term_id'     => $inserted['term_id'],
                                    'target_taxonomy'    => $taxonomy,
                                    'target_site_id'     => $target_site_identifier,
                                    'target_lang'        => $target_lang,
                                    'relation_id'        => (int) $site_id,
                                    'mapping_method'     => 'auto_create',
                                    'translation_method' => 'task_sync',
                                )
                            );
                        }
                    }
                }
            }
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $new_id, $term_ids, $taxonomy );
            }
        }
    }

    // Process post_parent mapping (pages, product variations, etc.)
    wptsall_process_post_parent_mapping( $source_blog, get_current_blog_id(), $new_id, $task['subtype'], $complete_data, $task );

    // Process other fields that need ID mapping (e.g., bbPress, related products, etc.)
    wptsall_process_id_mapping_fields( $source_blog, get_current_blog_id(), $new_id, $task['subtype'], $complete_data, $task );

    // Write to post_mappings table for deduplication by the Discovery endpoint.
    // Without this, the same source post would be "discovered" and translated repeatedly.
    if ( $new_id && class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) ) {
        \WPTSALL\Models\Services\Post_Mapping_Service::create_mapping(
            array(
                'source_post_id'    => (int) $task['object_id'],
                'source_post_type'  => $task['subtype'],
                'source_site_id'    => (int) $source_blog,
                'target_post_id'    => $new_id,
                'target_post_type'  => $task['subtype'],
                'target_site_id'    => $target_site_identifier,
                'relation_id'       => (int) $relation_id,
                'relationship_type' => 'translation',
            )
        );
    }

    return array(
        'success' => true,
        'new_id'  => $new_id,
        'note'    => $note_prefix . ' (with metadata, taxonomies, and ID mapping)',
    );
}

/**
 * Process Taxonomy task - full cross-table sync (write-back)
 *
 * @param array  $task          Task data.
 * @param array  $complete_data Complete object data.
 * @param string $target_type   Target type (virtual/wp).
 * @param int    $site_id       Site relation ID.
 * @param string $template      Template name.
 * @param string $site_marker   Site marker.
 * @return array { success: bool, new_id: int, note: string }
 */
function wptsall_process_term_task( $task, $complete_data, $target_type, $site_id, $template, $site_marker ) {
    $term_data = $complete_data['term'] ?? array();

    if ( empty( $term_data ) ) {
        return array( 'success' => false, 'new_id' => 0, 'note' => 'Missing term data' );
    }

    $taxonomy = $task['subtype'];
    $name     = $term_data['name'] ?? '';
    $slug     = $term_data['slug'] ?? '';
    $desc     = $term_data['description'] ?? '';

    if ( 'virtual' === $target_type ) {
        // Virtual site: use wp_terms + meta storage (v0.8.0+ replaces deprecated virtual_site_content table)
        $source_blog_id  = $task['blog_id'] ?? get_current_blog_id();
        $virtual_site_id = $task['target_identifier'] ?? '';
        if ( empty( $virtual_site_id ) ) {
            // target_identifier should always be set from relation's target_site_id during task creation.
            // Fallback: look up the relation to get the real target_site_id.
            $fallback_relation = function_exists( 'wptsall_get_site_relation' ) ? wptsall_get_site_relation( $site_id ) : null;
            $virtual_site_id   = $fallback_relation ? (string) $fallback_relation['target_site_id'] : ( 'v_' . $site_id );
        }
        if ( ! str_starts_with( $virtual_site_id, 'v_' ) && ctype_digit( (string) $virtual_site_id ) ) {
            $virtual_site_id = 'v_' . $virtual_site_id;
        }

        // Check if virtual term already exists.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT t.term_id FROM %i t
                INNER JOIN %i tt ON t.term_id = tt.term_id
                INNER JOIN %i tm1 ON t.term_id = tm1.term_id AND tm1.meta_key = '_wptsall_virtual_site_id'
                INNER JOIN %i tm2 ON t.term_id = tm2.term_id AND tm2.meta_key = '_wptsall_source_term_id'
                WHERE tm1.meta_value = %s
                AND tm2.meta_value = %d
                AND tt.taxonomy = %s
                LIMIT 1",
                $wpdb->terms,
                $wpdb->term_taxonomy,
                $wpdb->termmeta,
                $wpdb->termmeta,
                $virtual_site_id,
                (int) $task['object_id'],
                $taxonomy
            )
        );

        if ( $existing_id ) {
            // Update existing virtual term.
            wp_update_term( (int) $existing_id, $taxonomy, array(
                'name'        => $name,
                'slug'        => $slug,
                'description' => $desc,
            ) );
            $new_id = (int) $existing_id;
        } else {
            // Create new virtual term.
            $term_result = wp_insert_term( $name, $taxonomy, array(
                'slug'        => $slug,
                'description' => $desc,
            ) );
            if ( is_wp_error( $term_result ) ) {
                if ( $term_result->get_error_code() === 'term_exists' ) {
                    $existing_term = get_term_by( 'slug', $slug, $taxonomy );
                    $new_id = $existing_term ? $existing_term->term_id : 0;
                } else {
                    return array( 'success' => false, 'new_id' => 0, 'note' => $term_result->get_error_message() );
                }
            } else {
                $new_id = $term_result['term_id'];
            }
        }

        if ( $new_id ) {
            // Add virtual site markers.
            update_term_meta( $new_id, '_wptsall_virtual_site_id', $virtual_site_id );
            update_term_meta( $new_id, '_wptsall_source_term_id', (int) $task['object_id'] );
            update_term_meta( $new_id, '_wptsall_source_blog_id', (int) $source_blog_id );
            \WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $new_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

            // Sync meta data.
            if ( ! empty( $complete_data['meta'] ) ) {
                foreach ( $complete_data['meta'] as $meta_key => $meta_value ) {
                    if ( strpos( $meta_key, '_wptsall_' ) === 0 ) {
                        continue;
                    }
                    update_term_meta( $new_id, $meta_key, $meta_value );
                }
            }
        }

        // Register virtual term mapping in the new term_mappings store.
        if ( $new_id && class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
            $target_lang = $task['target_lang'] ?? $task['target_language'] ?? 'en_US';
            \WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
                array(
                    'source_term_id'     => (int) $task['object_id'],
                    'source_taxonomy'    => $taxonomy,
                    'source_site_id'     => (int) $source_blog_id,
                    'source_lang'        => $task['source_lang'] ?? $task['source_language'] ?? '',
                    'target_term_id'     => $new_id,
                    'target_taxonomy'    => $taxonomy,
                    'target_site_id'     => $virtual_site_id,
                    'target_lang'        => $target_lang,
                    'relation_id'        => (int) $site_id,
                    'mapping_method'     => 'auto_create',
                    'translation_method' => 'task_sync',
                )
            );
        }

        return array(
            'success' => true,
            'new_id'  => $new_id,
            'note'    => 'Written to virtual site storage (wp_terms + meta)',
        );
    }

    // WordPress site: create actual term
    $source_blog = $task['blog_id'] ?? get_current_blog_id();
    $target_blog = get_current_blog_id();

    $args = array(
        'slug'        => $slug,
        'description' => $desc,
    );

    // Process parent
    if ( ! empty( $term_data['parent'] ) ) {
        $parent_id = null;

        // 1. First try to find by slug (same-name parent)
        if ( ! empty( $complete_data['parent_data']['slug'] ) ) {
            $parent = get_term_by( 'slug', $complete_data['parent_data']['slug'], $taxonomy );
            if ( $parent ) {
                $parent_id = $parent->term_id;
            }
        }

        // 2. If not found, look up from mapping table
        if ( ! $parent_id ) {
            $mapped_parent = wptsall_get_mapped_id(
                $source_blog,
                'taxonomy',
                $taxonomy,
                $term_data['parent'],
                $target_blog,
                (int) $site_id
            );
            if ( $mapped_parent ) {
                $parent_id = $mapped_parent;
            }
        }

        if ( $parent_id ) {
            $args['parent'] = $parent_id;
        }
    }

    // Check if already exists
    $existing = get_term_by( 'slug', $slug, $taxonomy );
    if ( $existing ) {
        $result = wp_update_term( $existing->term_id, $taxonomy, array_merge( $args, array( 'name' => $name ) ) );
        $new_id = is_wp_error( $result ) ? 0 : $existing->term_id;
        if ( is_wp_error( $result ) ) {
            return array( 'success' => false, 'new_id' => 0, 'note' => $result->get_error_message() );
        }
    } else {
        $result = wp_insert_term( $name, $taxonomy, $args );
        if ( is_wp_error( $result ) ) {
            // Handle term_exists error
            if ( 'term_exists' === $result->get_error_code() ) {
                $new_id = intval( $result->get_error_data() );
                wp_update_term( $new_id, $taxonomy, array( 'description' => $desc ) );
            } else {
                return array( 'success' => false, 'new_id' => 0, 'note' => $result->get_error_message() );
            }
        } else {
            $new_id = $result['term_id'];
        }
    }

    // Sync metadata (skip attachment fields that need special handling)
    if ( $new_id && ! empty( $complete_data['meta'] ) ) {
        foreach ( $complete_data['meta'] as $meta_key => $meta_value ) {
            if ( 'thumbnail_id' === $meta_key ) {
                continue; // Attachment fields handled separately
            }
            update_term_meta( $new_id, $meta_key, $meta_value );
        }
    }

    // Process category thumbnail (e.g., WooCommerce product_cat)
    if ( $new_id && ! empty( $complete_data['meta']['thumbnail_id'] ) ) {
        $source_thumb_id = intval( $complete_data['meta']['thumbnail_id'] );
        $thumb_url = $complete_data['thumbnail']['url'] ?? '';
        $new_thumb_id = wptsall_sync_attachment( $source_blog, $source_thumb_id, $target_blog, $thumb_url, (int) $site_id );
        if ( $new_thumb_id ) {
            update_term_meta( $new_id, 'thumbnail_id', $new_thumb_id );
        }
    }

    // Register term mapping in the new term_mappings store.
    if ( $new_id && class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
        $target_lang = $task['target_lang'] ?? $task['target_language'] ?? 'en_US';
        \WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
            array(
                'source_term_id'     => (int) $task['object_id'],
                'source_taxonomy'    => $taxonomy,
                'source_site_id'     => (int) $source_blog,
                'source_lang'        => $task['source_lang'] ?? $task['source_language'] ?? '',
                'target_term_id'     => $new_id,
                'target_taxonomy'    => $taxonomy,
                'target_site_id'     => (string) $target_blog,
                'target_lang'        => $target_lang,
                'relation_id'        => (int) $site_id,
                'mapping_method'     => 'auto_create',
                'translation_method' => 'task_sync',
            )
        );
    }

    return array(
        'success' => true,
        'new_id'  => $new_id,
        'note'    => 'Synced to site ' . $target_blog . ' (with metadata and attachments)',
    );
}

/**
 * Ensure virtual content table exists
 */
function wptsall_ensure_virtual_content_table() {
    if ( function_exists( 'wptsall_create_virtual_site_content_table' ) ) {
        wptsall_create_virtual_site_content_table();
    }
}

/**
 * Store virtual site content
 *
 * @since 0.7.0 Updated to use virtual_site_content table structure
 *
 * @param string $virtual_site_id Virtual site identifier (e.g., 'v_2' or 'v_en')
 * @param int    $source_blog_id  Source blog ID
 * @param int    $source_object_id Source object ID
 * @param string $object_type     Object type (post_type/taxonomy)
 * @param string $subtype         Subtype (post/page/product, etc.)
 * @param array  $content         Content data
 * @param array  $translation_meta Translation metadata (optional)
 * @return int Record ID
 */
function wptsall_store_virtual_content( $virtual_site_id, $source_blog_id, $source_object_id, $object_type, $subtype, $content, $translation_meta = null ) {
    global $wpdb;
    wptsall_ensure_virtual_content_table();
    $table = wptsall_virtual_content_table();
    if ( ! wptsall_virtual_content_table_exists() ) {
        return 0;
    }

    // Check if record already exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id FROM %i WHERE virtual_site_id = %s AND source_blog_id = %d AND source_object_id = %d AND object_type = %s',
            $table,
            $virtual_site_id,
            intval( $source_blog_id ),
            intval( $source_object_id ),
            sanitize_key( $object_type )
        )
    );

    $data = array(
        'virtual_site_id'   => sanitize_text_field( $virtual_site_id ),
        'source_blog_id'    => intval( $source_blog_id ),
        'source_object_id'  => intval( $source_object_id ),
        'object_type'       => sanitize_key( $object_type ),
        'subtype'           => sanitize_key( $subtype ),
        'content'           => wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        'translation_meta'  => $translation_meta ? wp_json_encode( $translation_meta, JSON_UNESCAPED_UNICODE ) : null,
        'last_synced'       => current_time( 'mysql', true ),
    );

    if ( $existing ) {
        // Update existing record
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $table,
            $data,
            array( 'id' => $existing->id ),
            array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        return intval( $existing->id );
    }

    // Insert new record
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert(
        $table,
        $data,
        array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
    );
    return intval( $wpdb->insert_id );
}

/**
 * Insert a translation result record.
 *
 * Used by the Client_Data_REST_Controller translation-callback endpoint
 * to store translated content before syncing to the target site.
 *
 * @since 1.0.5
 * @param array $args {
 *     @type int    $relation_id       Site relation ID.
 *     @type string $object_type       Object type (post, term, i18n).
 *     @type int    $object_id         Object ID.
 *     @type array  $translated_fields Translated field values.
 *     @type array  $translated_meta   Translated meta values.
 *     @type array  $media_mappings    Media mapping data.
 *     @type string $client_task_id    Unique client task ID (idempotency key).
 *     @type string $source_lang       Source language code.
 *     @type string $target_lang       Target language code.
 * }
 * @return int|false Inserted row ID, or false on failure.
 */
function wptsall_insert_translation_result( $args ) {
    global $wpdb;
    $table = wptsall_table( 'translation_results' );

    $defaults = array(
        'relation_id'       => 0,
        'object_type'       => 'post',
        'object_id'         => 0,
        'translated_fields' => array(),
        'translated_meta'   => array(),
        'media_mappings'    => array(),
        'client_task_id'    => '',
        'source_revision'   => '',
        'policy_version'    => '',
        'request_hash'      => '',
        'source_lang'       => '',
        'target_lang'       => '',
    );

    $args = wp_parse_args( $args, $defaults );

    if ( empty( $args['client_task_id'] ) ) {
        return false;
    }

    $now = current_time( 'mysql', true );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert(
        $table,
        array(
            'relation_id'       => absint( $args['relation_id'] ),
            'object_type'       => sanitize_key( $args['object_type'] ),
            'object_id'         => absint( $args['object_id'] ),
            'translated_fields' => wp_json_encode( $args['translated_fields'] ),
            'translated_meta'   => wp_json_encode( $args['translated_meta'] ),
            'media_mappings'    => wp_json_encode( $args['media_mappings'] ),
            'client_task_id'    => sanitize_text_field( $args['client_task_id'] ),
            'source_revision'   => sanitize_text_field( $args['source_revision'] ),
            'policy_version'    => sanitize_text_field( $args['policy_version'] ),
            'request_hash'      => sanitize_text_field( $args['request_hash'] ),
            'source_lang'       => sanitize_text_field( $args['source_lang'] ),
            'target_lang'       => sanitize_text_field( $args['target_lang'] ),
            'status'            => 'pending',
            'created_at'        => $now,
        ),
        array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
    );

    if ( false === $result ) {
        // Idempotency: if a row with the same client_task_id already exists,
        // return its primary key instead of failing. The schema enforces
        // UNIQUE KEY on client_task_id, so a duplicate INSERT surfaces as
        // a "Duplicate entry" error from $wpdb->last_error.
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE client_task_id = %s',
                $table,
                $args['client_task_id']
            )
        );

        if ( $existing_id ) {
            return (int) $existing_id;
        }

        wptsall_log_error(
            'task',
            'Failed to insert translation result',
            array(
                'client_task_id' => $args['client_task_id'],
                'error'          => $wpdb->last_error,
            )
        );
        return false;
    }

    return (int) $wpdb->insert_id;
}
