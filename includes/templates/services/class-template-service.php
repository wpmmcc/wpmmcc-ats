<?php
/**
 * Template Service
 *
 * Template Service - handles language pack template CRUD operations
 *
 * Scope: per-relation (ISS-TPL-011 finalized)
 * - Each site relation has its own independent set of language pack templates
 * - Different relations (different target languages) do not share entries
 * - relation_id is a required field and must not be omitted
 * - Gettext_Filter queries by relation_id at runtime, writes must also include relation_id
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
 */

namespace WPTSALL\Templates\Services;

use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Template_Service class
 *
 * Provides business logic for language pack template management
 */
class Template_Service {

	/**
	 * Get a single template
	 *
	 * @param int $id Template ID
	 * @return array|null Template data
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$template = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ),
			ARRAY_A
		);

		return $template;
	}

	/**
	 * Get template by slug
	 *
	 * @param string $slug             Template slug
	 * @param bool   $any_status       Whether to query any status (for duplicate detection), default false (active only)
	 * @param bool   $include_entries  Whether to include entries, default true
	 * @return array|null Template data
	 */
	public static function get_by_slug( $slug, $any_status = false, $include_entries = true ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $any_status ) {
			$template = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE slug = %s',
					$table,
					$slug
				),
				ARRAY_A
			);
		} else {
			$template = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE slug = %s AND status = %s',
					$table,
					$slug,
					'active'
				),
				ARRAY_A
			);
		}

		if ( ! $template ) {
			return null;
		}

		// Load entries
		if ( $include_entries ) {
			$template['entries'] = Template_Entry_Service::get_by_template( (int) $template['id'] );
		}

		return $template;
	}

	/**
	 * Get all templates for a site relation
	 *
	 * Per-relation scope (ISS-TPL-011): Returns all templates for the given relation_id.
	 *
	 * @param int $relation_id Site relation ID
	 * @return array Template list
	 */
	public static function get_by_relation( $relation_id ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$templates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE relation_id = %d ORDER BY source_type, source_name',
				$table,
				$relation_id
			),
			ARRAY_A
		);

		return $templates ?: array();
	}

	/**
	 * Get all templates (paginated)
	 *
	 * @since 0.5.0
	 * @updated 0.6.0 Added target_language, source_identifier, global_only filters
	 *
	 * @param array $args Query parameters
	 *   - relation_id (int): Site relation ID (legacy param, backward compatible)
	 *   - source_type (string): Source type theme/plugin/core
	 *   - source_identifier (string): Source identifier (v0.6.0)
	 *   - target_language (string): Target language code (v0.6.0)
	 *   - status (string): Status
	 *   - text_domain (string): Translation domain (fuzzy search)
	 *   - global_only (bool): Only relation_id=0 templates (backward compatible)
	 *   - page (int): Page number
	 *   - per_page (int): Items per page
	 * @return array ['items' => array, 'total' => int, 'pages' => int]
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		$defaults = array(
			'relation_id'       => 0,
			'source_type'       => '',
			'source_identifier' => '',
			'target_language'   => '',
			'status'            => '',
			'text_domain'       => '',
			'global_only'       => false,
			'page'              => 1,
			'per_page'          => 20,
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause (using parameterized queries)
		$where_parts = array();
		$where_args  = array( $table ); // First param is table name (%i)

		// v0.6.0: Global template filter
		if ( $args['global_only'] ) {
			$where_parts[] = 'relation_id = %d';
			$where_args[]  = 0;
		} elseif ( ! empty( $args['relation_id'] ) ) {
			$where_parts[] = 'relation_id = %d';
			$where_args[]  = (int) $args['relation_id'];
		}

		if ( ! empty( $args['source_type'] ) ) {
			$where_parts[] = 'source_type = %s';
			$where_args[]  = sanitize_key( $args['source_type'] );
		}

		// v0.6.0: Source identifier filter
		if ( ! empty( $args['source_identifier'] ) ) {
			$where_parts[] = 'source_identifier = %s';
			$where_args[]  = sanitize_text_field( $args['source_identifier'] );
		}

		// v0.6.0: Target language filter
		if ( ! empty( $args['target_language'] ) ) {
			$where_parts[] = 'target_language = %s';
			$where_args[]  = sanitize_text_field( $args['target_language'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where_parts[] = 'status = %s';
			$where_args[]  = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['text_domain'] ) ) {
			$where_parts[] = 'text_domain LIKE %s';
			$where_args[]  = '%' . $wpdb->esc_like( $args['text_domain'] ) . '%';
		}

		// Build WHERE clause string (fragments are fixed 'col = %s' patterns only).
		$where_sql = empty( $where_parts ) ? '1=1' : implode( ' AND ', $where_parts );

		// Get total count — $where_sql is built only from fixed placeholder fragments above.
		$total = (int) wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i WHERE ' . $where_sql,
			$where_args
		);

		// Pagination
		$per_page = max( 1, (int) $args['per_page'] );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;
		$pages    = (int) ceil( $total / $per_page );

		// Add pagination params
		$where_args[] = $per_page;
		$where_args[] = $offset;

		// Query data — $where_sql is built only from fixed placeholder fragments above.
		$items = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
			$where_args,
			ARRAY_A
		);

		return array(
			'items' => $items ?: array(),
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		);
	}

	/**
	 * Create template
	 *
	 * relation_id is a required field (per-relation scope, ISS-TPL-011).
	 * Falls back to 0 with a warning when relation_id is missing.
	 *
	 * @param array $data Template data
	 * @return int|false Template ID or false
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		$now = current_time( 'mysql' );

		// Per-relation scope (ISS-TPL-011): relation_id is required.
		$relation_id = (int) ( $data['relation_id'] ?? 0 );
		if ( 0 === $relation_id ) {
			wptsall_log( 'templates', 'warning', 'Template created without relation_id (per-relation scope expected)', $data );
		}
		$source_identifier = sanitize_text_field( $data['source_identifier'] ?? '' );
		$source_language   = sanitize_text_field( $data['source_language'] ?? 'en_US' );
		$target_language   = sanitize_text_field( $data['target_language'] ?? '' );

		// Generate slug from data if not provided
		$slug = '';
		if ( ! empty( $data['slug'] ) ) {
			$slug = sanitize_key( $data['slug'] );
		}

		// Prefer source_identifier + target_language for slug generation
		if ( empty( $slug ) && ! empty( $source_identifier ) && ! empty( $target_language ) ) {
			$slug = sanitize_title( $source_identifier . '-' . $target_language );
		}

		// Fallback: generate from text_domain and relation_id (legacy)
		if ( empty( $slug ) && ! empty( $data['text_domain'] ) ) {
			$slug = sanitize_title( $data['text_domain'] . '-' . $relation_id );
		}

		// Final fallback: use unique identifier to prevent empty slug
		if ( empty( $slug ) ) {
			$slug = 'template-' . $relation_id . '-' . uniqid();
			wptsall_log(
				'templates',
				'warning',
				'Generated fallback slug due to missing identifiers',
				array(
					'slug'              => $slug,
					'text_domain'       => $data['text_domain'] ?? '',
					'source_type'       => $data['source_type'] ?? '',
					'source_identifier' => $source_identifier,
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'relation_id'        => $relation_id,
				'slug'               => sanitize_key( $slug ),
				'source_type'        => sanitize_key( $data['source_type'] ),
				'source_identifier'  => $source_identifier,
				'text_domain'        => sanitize_text_field( $data['text_domain'] ),
				'source_name'        => sanitize_text_field( $data['source_name'] ?? '' ),
				'source_version'     => sanitize_text_field( $data['source_version'] ?? '' ),
				'source_language'    => $source_language,
				'target_language'    => $target_language,
				'total_entries'      => (int) ( $data['total_entries'] ?? 0 ),
				'translated_entries' => (int) ( $data['translated_entries'] ?? 0 ),
				'reviewed_entries'   => (int) ( $data['reviewed_entries'] ?? 0 ),
				'status'             => sanitize_key( $data['status'] ?? 'pending' ),
				'last_scanned_at'    => $data['last_scanned_at'] ?? null,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			wptsall_log(
				'templates',
				'error',
				'Failed to create template',
				array(
					'data'  => $data,
					'error' => $wpdb->last_error,
				)
			);
			return false;
		}

		$template_id = $wpdb->insert_id;

		wptsall_log(
			'templates',
			'info',
			'Template created',
			array(
				'template_id' => $template_id,
				'text_domain' => $data['text_domain'],
			)
		);

		return $template_id;
	}

	/**
	 * Update template
	 *
	 * @param int   $id   Template ID
	 * @param array $data Update data
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		$update_data = array( 'updated_at' => current_time( 'mysql' ) );
		$formats     = array( '%s' );

		$allowed_fields = array(
			'source_name'        => '%s',
			'source_version'     => '%s',
			'source_identifier'  => '%s',
			'source_language'    => '%s',
			'target_language'    => '%s',
			'total_entries'      => '%d',
			'translated_entries' => '%d',
			'reviewed_entries'   => '%d',
			'status'             => '%s',
			'last_scanned_at'    => '%s',
		);

		foreach ( $allowed_fields as $field => $format ) {
			if ( isset( $data[ $field ] ) ) {
				if ( '%s' === $format ) {
					$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
				} else {
					$update_data[ $field ] = (int) $data[ $field ];
				}
				$formats[] = $format;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			wptsall_log(
				'templates',
				'error',
				'Failed to update template',
				array(
					'template_id' => $id,
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		wptsall_log_debug(
			'templates',
			'Template updated',
			array(
				'template_id' => $id,
				'fields'      => array_keys( $update_data ),
			)
		);

		return true;
	}

	/**
	 * Delete template
	 *
	 * @param int $id Template ID
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table         = wptsall_table( 'templates' );
		$entries_table = wptsall_table( 'template_entries' );

		// Delete associated entries first
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$entries_table,
			array( 'template_id' => $id ),
			array( '%d' )
		);

		// Delete template
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $result ) {
			wptsall_log(
				'templates',
				'error',
				'Failed to delete template',
				array( 'template_id' => $id )
			);
			return false;
		}

		wptsall_log(
			'templates',
			'info',
			'Template deleted',
			array( 'template_id' => $id )
		);

		return true;
	}

	/**
	 * Update statistics
	 *
	 * @param int $template_id Template ID
	 * @return bool
	 */
	public static function update_stats( $template_id ) {
		global $wpdb;
		$entries_table = wptsall_table( 'template_entries' );

		// Get total count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE template_id = %d',
				$entries_table,
				$template_id
			)
		);

		// Get translated count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$translated = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE template_id = %d AND status IN (%s, %s)',
				$entries_table,
				$template_id,
				'translated',
				'reviewed'
			)
		);

		// Get reviewed count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$reviewed = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE template_id = %d AND status = %s',
				$entries_table,
				$template_id,
				'reviewed'
			)
		);

		// Update status
		$status = 'pending';
		if ( $total > 0 ) {
			if ( $translated === $total ) {
				$status = 'completed';
			} elseif ( $translated > 0 ) {
				$status = 'translating';
			} else {
				$status = 'scanned';
			}
		}

		return self::update( $template_id, array(
			'total_entries'      => $total,
			'translated_entries' => $translated,
			'reviewed_entries'   => $reviewed,
			'status'             => $status,
		) );
	}

	/**
	 * Find template by relation ID and text domain
	 *
	 * @param int    $relation_id Site relation ID
	 * @param string $source_type Source type
	 * @param string $text_domain Translation domain
	 * @return array|null Template data
	 */
	public static function find_by_domain( $relation_id, $source_type, $text_domain ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$template = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE relation_id = %d AND source_type = %s AND text_domain = %s',
				$table,
				$relation_id,
				$source_type,
				$text_domain
			),
			ARRAY_A
		);

		return $template;
	}

	/**
	 * Get or create template
	 *
	 * @param array $data Template data
	 * @return int Template ID
	 */
	public static function get_or_create( $data ) {
		$existing = self::find_by_domain(
			$data['relation_id'],
			$data['source_type'],
			$data['text_domain']
		);

		if ( $existing ) {
			// Update existing template
			self::update( $existing['id'], array(
				'source_name'     => $data['source_name'] ?? $existing['source_name'],
				'source_version'  => $data['source_version'] ?? $existing['source_version'],
				'last_scanned_at' => current_time( 'mysql' ),
			) );
			return (int) $existing['id'];
		}

		return self::create( $data );
	}

	/**
	 * Get enriched template info (includes relation info)
	 *
	 * @param int $template_id Template ID
	 * @return array|null Enriched template data
	 */
	public static function get_with_relation( $template_id ) {
		$template = self::get( $template_id );
		if ( ! $template ) {
			return null;
		}

		// Get relation info
		$relation = Site_Relation_Service::get_relation( $template['relation_id'] );
		if ( $relation ) {
			$template['relation'] = $relation;
		}

		// Calculate progress
		$template['progress'] = 0;
		if ( $template['total_entries'] > 0 ) {
			$template['progress'] = round(
				( $template['translated_entries'] / $template['total_entries'] ) * 100,
				1
			);
		}

		return $template;
	}

	/**
	 * Bulk delete all templates under a site relation
	 *
	 * @deprecated 0.6.0 Templates are no longer bound to relation_id, kept for backward compatibility
	 *
	 * @param int $relation_id Site relation ID
	 * @return int Number of deleted templates
	 */
	public static function delete_by_relation( $relation_id ) {
		$templates = self::get_by_relation( $relation_id );
		$count     = 0;

		foreach ( $templates as $template ) {
			if ( self::delete( $template['id'] ) ) {
				$count++;
			}
		}

		wptsall_log(
			'templates',
			'info',
			'Templates deleted by relation',
			array(
				'relation_id' => $relation_id,
				'count'       => $count,
			)
		);

		return $count;
	}

	// ========================================
	// v0.6.0 Global language pack methods
	// ========================================

	/**
	 * Get global template by source and target language
	 *
	 * @since 0.6.0
	 *
	 * @param string $source_type       Source type (plugin/theme/core)
	 * @param string $source_identifier Source identifier (plugin slug/theme slug/wordpress)
	 * @param string $target_language   Target language code
	 * @return array|null Template data
	 */
	public static function get_global_template( $source_type, $source_identifier, $target_language ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$template = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_type = %s AND source_identifier = %s AND target_language = %s',
				$table,
				$source_type,
				$source_identifier,
				$target_language
			),
			ARRAY_A
		);

		return $template;
	}

	/**
	 * Get all language packs for a plugin
	 *
	 * @since 0.6.0
	 *
	 * @param string $plugin_slug Plugin slug
	 * @return array Language pack list
	 */
	public static function get_plugin_language_packs( $plugin_slug ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$templates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_type = %s AND source_identifier = %s ORDER BY target_language',
				$table,
				'plugin',
				$plugin_slug
			),
			ARRAY_A
		);

		return $templates ?: array();
	}

	/**
	 * Get all language packs for a target language
	 *
	 * @since 0.6.0
	 *
	 * @param string $target_language Target language code
	 * @param string $source_type     Source type filter (optional)
	 * @return array Language pack list
	 */
	public static function get_by_target_language( $target_language, $source_type = '' ) {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		if ( ! empty( $source_type ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$templates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE target_language = %s AND source_type = %s ORDER BY source_name',
					$table,
					$target_language,
					$source_type
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$templates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE target_language = %s ORDER BY source_type, source_name',
					$table,
					$target_language
				),
				ARRAY_A
			);
		}

		return $templates ?: array();
	}

	/**
	 * Check if plugin has a language pack for specified language
	 *
	 * @since 0.6.0
	 *
	 * @param string $plugin_slug     Plugin slug
	 * @param string $target_language Target language code
	 * @return bool
	 */
	public static function has_language_pack( $plugin_slug, $target_language ) {
		$template = self::get_global_template( 'plugin', $plugin_slug, $target_language );
		return ! empty( $template );
	}

	/**
	 * Get list of plugins missing language packs
	 *
	 * @since 0.6.0
	 *
	 * @param array  $plugin_slugs    Plugin slug list
	 * @param string $target_language Target language code
	 * @return array Plugin slug list missing language packs
	 */
	public static function get_missing_language_packs( $plugin_slugs, $target_language ) {
		$missing = array();

		foreach ( $plugin_slugs as $slug ) {
			if ( ! self::has_language_pack( $slug, $target_language ) ) {
				$missing[] = $slug;
			}
		}

		return $missing;
	}

	/**
	 * Match language packs for a site relation
	 *
	 * Matches theme and associated plugin language packs based on the relation's target language.
	 *
	 * @since 0.6.0
	 *
	 * @param int|array $relation Site relation ID or relation data array
	 * @return array Match results
	 *   - theme (array|null): Theme language pack match result
	 *   - plugins (array): Plugin language pack match results keyed by plugin slug
	 *   - summary (array): Match summary statistics
	 */
	public static function match_language_packs( $relation ) {
		// If passed an ID, get relation data
		if ( is_numeric( $relation ) ) {
			$relation = Site_Relation_Service::get_relation( (int) $relation );
		}

		if ( ! $relation ) {
			return array(
				'theme'   => null,
				'plugins' => array(),
				'summary' => array(
					'total'   => 0,
					'matched' => 0,
					'missing' => 0,
				),
			);
		}

		$target_lang = $relation['target_lang'] ?? '';
		if ( empty( $target_lang ) ) {
			wptsall_log_warning(
				'templates',
				'Cannot match language packs: no target language',
				array( 'relation_id' => $relation['id'] )
			);
			return array(
				'theme'   => null,
				'plugins' => array(),
				'summary' => array(
					'total'   => 0,
					'matched' => 0,
					'missing' => 0,
				),
			);
		}

		$result = array(
			'theme'   => null,
			'plugins' => array(),
			'summary' => array(
				'total'   => 0,
				'matched' => 0,
				'missing' => 0,
			),
		);

		// 1. Match theme language pack
		$theme_slug = self::extract_theme_slug( $relation );
		if ( ! empty( $theme_slug ) ) {
			$result['summary']['total']++;
			$theme_pack = self::find_language_pack( 'theme', $theme_slug, $target_lang );
			$result['theme'] = $theme_pack;
			if ( $theme_pack && $theme_pack['matched'] ) {
				$result['summary']['matched']++;
			} else {
				$result['summary']['missing']++;
			}
		}

		// 2. Match associated plugin language packs
		$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( (int) $relation['id'] );
		foreach ( $models as $model ) {
			$plugin_slug = $model['plugin_slug'] ?? '';
			if ( empty( $plugin_slug ) ) {
				continue;
			}

			$result['summary']['total']++;
			$plugin_pack = self::find_language_pack( 'plugin', $plugin_slug, $target_lang );
			$result['plugins'][ $plugin_slug ] = array(
				'model_id'      => $model['id'],
				'plugin_name'   => $model['plugin_name'] ?? $plugin_slug,
				'language_pack' => $plugin_pack,
			);

			if ( $plugin_pack && $plugin_pack['matched'] ) {
				$result['summary']['matched']++;
			} else {
				$result['summary']['missing']++;
			}
		}

		wptsall_log_info(
			'templates',
			'Language packs matched',
			array(
				'relation_id'  => $relation['id'],
				'target_lang'  => $target_lang,
				'total'        => $result['summary']['total'],
				'matched'      => $result['summary']['matched'],
				'missing'      => $result['summary']['missing'],
			)
		);

		return $result;
	}

	/**
	 * Find language pack for specified source
	 *
	 * @since 0.6.0
	 *
	 * @param string $source_type Source type (theme/plugin/core)
	 * @param string $slug        Source slug
	 * @param string $target_lang Target language code
	 * @return array Language pack match result
	 */
	public static function find_language_pack( $source_type, $slug, $target_lang ) {
		$template = self::get_global_template( $source_type, $slug, $target_lang );

		if ( $template ) {
			$progress = 0;
			if ( (int) $template['total_entries'] > 0 ) {
				$progress = round(
					( (int) $template['translated_entries'] / (int) $template['total_entries'] ) * 100,
					1
				);
			}

			return array(
				'matched'            => true,
				'template_id'        => (int) $template['id'],
				'slug'               => $template['slug'],
				'status'             => $template['status'],
				'total_entries'      => (int) $template['total_entries'],
				'translated_entries' => (int) $template['translated_entries'],
				'reviewed_entries'   => (int) $template['reviewed_entries'],
				'progress'           => $progress,
				'source_version'     => $template['source_version'] ?? '',
			);
		}

		return array(
			'matched' => false,
			'message' => sprintf(
				/* translators: 1: source type, 2: slug, 3: target language */
				__( 'No %1$s language pack for %2$s (%3$s)', 'wpmmcc-ats' ),
				$source_type,
				$slug,
				$target_lang
			),
		);
	}

	/**
	 * Extract theme slug from site relation
	 *
	 * @since 0.6.0
	 *
	 * @param array $relation Site relation data
	 * @return string Theme slug or empty string
	 */
	private static function extract_theme_slug( $relation ) {
		// Try to extract from source_theme_path
		if ( ! empty( $relation['source_theme_path'] ) ) {
			// Format may be themes/theme-slug or theme-slug
			$path = trim( $relation['source_theme_path'], '/' );
			if ( strpos( $path, 'themes/' ) === 0 ) {
				$path = substr( $path, 7 );
			}
			// Take first segment as slug
			$parts = explode( '/', $path );
			return sanitize_key( $parts[0] );
		}

		// Try to infer from source_theme_name (convert to slug format)
		if ( ! empty( $relation['source_theme_name'] ) ) {
			return sanitize_title( $relation['source_theme_name'] );
		}

		return '';
	}

	/**
	 * Get language pack statistics
	 *
	 * @since 0.6.0
	 *
	 * @return array Statistics data
	 */
	public static function get_global_stats() {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// Total count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);

		// By source type
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_source_type = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_type, COUNT(*) as count FROM %i GROUP BY source_type',
				$table
			),
			ARRAY_A
		);

		// By target language
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_target_language = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT target_language, COUNT(*) as count FROM %i WHERE target_language IS NOT NULL AND target_language != %s GROUP BY target_language ORDER BY count DESC',
				$table,
				''
			),
			ARRAY_A
		);

		// By status
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_status = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) as count FROM %i GROUP BY status',
				$table
			),
			ARRAY_A
		);

		return array(
			'total'              => $total,
			'by_source_type'     => $by_source_type,
			'by_target_language' => $by_target_language,
			'by_status'          => $by_status,
		);
	}
}
