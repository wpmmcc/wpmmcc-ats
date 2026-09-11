<?php
/**
 * Template Entry Service
 *
 * Translation Entry Service - handles translation entry CRUD operations
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
  * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column identifiers from internal helpers (wptsall_table / \$wpdb->prefix . 'wptsall_*'); user values use prepare placeholders.
 */
namespace WPTSALL\Templates\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template_Entry_Service class
 *
 * Provides business logic for translation entry management
 */
class Template_Entry_Service {

	/**
	 * Normalize a source string for entry/memory matching.
	 *
	 * Canonical form used at both storage time (template scanners store the
	 * trimmed original) and lookup time (translation memory pairs are keyed
	 * on trimmed source_text), so both sides compare identical strings.
	 *
	 * @since 2.1.4
	 * @param string $text Raw source text.
	 * @return string Normalized source text ('' when nothing remains).
	 */
	public static function normalize_original_string( $text ) {
		$text = trim( (string) $text );
		return $text;
	}

	/**
	 * Get a single entry
	 *
	 * @param int $id Entry ID
	 * @return array|null Entry data
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ),
			ARRAY_A
		);

		return $entry;
	}

	/**
	 * Get all entries for a template (paginated)
	 *
	 * @param int   $template_id Template ID
	 * @param array $args        Query parameters
	 *   - status (string): Status filter
	 *   - source (string): Source filter
	 *   - search (string): Search keyword
	 *   - page (int): Page number
	 *   - per_page (int): Items per page, -1 for no pagination
	 * @return array ['items' => array, 'total' => int, 'pages' => int]
	 */
	public static function get_by_template( $template_id, $args = array() ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		$defaults = array(
			'status'   => '',
			'source'   => '',
			'search'   => '',
			'page'     => 1,
			'per_page' => 50,
		);

		$args = wp_parse_args( $args, $defaults );

		// First param is table name (%i); WHERE fragments are fixed 'col = %s' patterns only.
		$where  = array( 'template_id = %d' );
		$values = array( $table, (int) $template_id );

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$values[] = $args['source'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(msgid LIKE %s OR msgstr LIKE %s OR msgctxt LIKE %s)';
			$search   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
		}

		$where_clause = implode( ' AND ', $where );

		// Get total count — $where_clause is built only from fixed placeholder fragments above.
		$total = (int) wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i WHERE ' . $where_clause,
			$values
		);

		// No pagination
		if ( -1 === (int) $args['per_page'] ) {
			$items = wptsall_db_get_results(
				'SELECT * FROM %i WHERE ' . $where_clause . ' ORDER BY id ASC',
				$values,
				ARRAY_A
			);

			return array(
				'items' => $items ?: array(),
				'total' => $total,
				'pages' => 1,
				'page'  => 1,
			);
		}

		// Pagination
		$per_page = max( 1, (int) $args['per_page'] );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;
		$pages    = ceil( $total / $per_page );

		$values[] = $per_page;
		$values[] = $offset;

		$items = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $where_clause . ' ORDER BY id ASC LIMIT %d OFFSET %d',
			$values,
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
	 * Get entries by status
	 *
	 * @param int    $template_id Template ID
	 * @param string $status      Status
	 * @return array Entry list
	 */
	public static function get_by_status( $template_id, $status ) {
		$result = self::get_by_template( $template_id, array(
			'status'   => $status,
			'per_page' => -1,
		) );

		return $result['items'];
	}

	/**
	 * Create entry
	 *
	 * @param array $data Entry data
	 * @return int|false Entry ID or false
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		$now = current_time( 'mysql' );

		// Suppress errors to handle duplicate entry collisions gracefully in get_or_create.
		$wpdb->suppress_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'template_id'   => (int) $data['template_id'],
				'msgid'         => $data['msgid'],
				'msgid_plural'  => $data['msgid_plural'] ?? '',
				'msgctxt'       => sanitize_text_field( $data['msgctxt'] ?? '' ),
				'msgstr'        => $data['msgstr'] ?? '',
				'msgstr_plural' => $data['msgstr_plural'] ?? '',
				'status'        => sanitize_key( $data['status'] ?? 'pending' ),
				'source'        => sanitize_key( $data['source'] ?? 'scan' ),
				'reference'     => sanitize_text_field( $data['reference'] ?? '' ),
				'note'          => sanitize_text_field( $data['note'] ?? '' ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( false );

		if ( false === $result ) {
			return false;
		}

		$entry_id = $wpdb->insert_id;

		wptsall_log_info(
			'templates-entries',
			'Entry created',
			array(
				'id'          => $entry_id,
				'template_id' => (int) $data['template_id'],
				'msgid'       => mb_substr( $data['msgid'], 0, 50 ),
			)
		);

		return $entry_id;
	}

	/**
	 * Bulk create entries
	 *
	 * Uses bulk INSERT statements for performance, up to 100 records per batch.
	 *
	 * @param array $entries Entry array
	 * @return array ['success' => int, 'failed' => int]
	 */
	public static function bulk_create( $entries ) {
		if ( empty( $entries ) ) {
			return array(
				'success' => 0,
				'failed'  => 0,
			);
		}

		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		$success    = 0;
		$failed     = 0;
		$batch_size = 100; // Max 100 per batch
		$now        = current_time( 'mysql' );

		// Process in batches
		$batches = array_chunk( $entries, $batch_size );

		foreach ( $batches as $batch ) {
			$values      = array();
			$placeholders = array();

			foreach ( $batch as $entry ) {
				// Validate required fields
				if ( empty( $entry['template_id'] ) || ! isset( $entry['msgid'] ) ) {
					$failed++;
					continue;
				}

				$placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)';
				$values[]       = (int) $entry['template_id'];
				$values[]       = $entry['msgid'];
				$values[]       = $entry['msgid_plural'] ?? '';
				$values[]       = sanitize_text_field( $entry['msgctxt'] ?? '' );
				$values[]       = $entry['msgstr'] ?? '';
				$values[]       = $entry['msgstr_plural'] ?? '';
				$values[]       = sanitize_key( $entry['status'] ?? 'pending' );
				$values[]       = sanitize_key( $entry['source'] ?? 'scan' );
				$values[]       = sanitize_text_field( $entry['reference'] ?? '' );
				$values[]       = sanitize_text_field( $entry['note'] ?? '' );
				$values[]       = $now;
				$values[]       = $now;
			}

			if ( empty( $placeholders ) ) {
				continue;
			}

			// Build bulk INSERT statement — table via %i; value groups are fixed placeholder tuples only.
			$sql = 'INSERT INTO %i
				(template_id, msgid, msgid_plural, msgctxt, msgstr, msgstr_plural, status, source, reference, note, created_at, updated_at)
				VALUES ' . implode( ', ', $placeholders );

			$result = wptsall_db_query( $sql, array_merge( array( $table ), $values ) );

			if ( false === $result ) {
				// Bulk insert failed, fall back to single insert
				wptsall_log_warning(
					'templates',
					'Bulk insert failed, falling back to single insert',
					array( 'batch_size' => count( $batch ), 'error' => $wpdb->last_error )
				);

				foreach ( $batch as $entry ) {
					if ( empty( $entry['template_id'] ) || ! isset( $entry['msgid'] ) ) {
						continue; // Already counted as failed
					}
					$single_result = self::create( $entry );
					if ( $single_result ) {
						$success++;
					} else {
						$failed++;
					}
				}
			} else {
				$success += count( $placeholders );
			}
		}

		wptsall_log(
			'templates',
			'info',
			'Bulk create entries completed',
			array(
				'success' => $success,
				'failed'  => $failed,
				'total'   => count( $entries ),
				'batches' => count( $batches ),
			)
		);

		return array(
			'success' => $success,
			'failed'  => $failed,
		);
	}

	/**
	 * Update entry
	 *
	 * @param int   $id   Entry ID
	 * @param array $data Update data
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		$update_data = array( 'updated_at' => current_time( 'mysql' ) );
		$formats     = array( '%s' );

		// Check if cache clear needed (translation-related fields changed)
		$needs_cache_clear = isset( $data['msgstr'] ) || isset( $data['status'] );

		$allowed_fields = array(
			'msgstr'       => '%s',
			'msgstr_plural'=> '%s',
			'status'       => '%s',
			'source'       => '%s',
			'note'         => '%s',
		);

		foreach ( $allowed_fields as $field => $format ) {
			if ( isset( $data[ $field ] ) ) {
				if ( in_array( $field, array( 'status', 'source' ), true ) ) {
					$update_data[ $field ] = sanitize_key( $data[ $field ] );
				} elseif ( 'note' === $field ) {
					$update_data[ $field ] = sanitize_text_field( $data[ $field ] );
				} else {
					$update_data[ $field ] = $data[ $field ];
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

		// Clear translation cache (if translation-related fields changed)
		if ( false !== $result && $needs_cache_clear ) {
			$text_domain = self::clear_cache_for_entry( $id );
			/**
			 * Fires after a template entry translation/status write.
			 *
			 * Used by Config_Filter / Gettext_Filter to invalidate in-memory caches.
			 *
			 * @param int   $id   Entry ID.
			 * @param array $data Context; includes `text_domain` when known.
			 */
			do_action(
				'wptsall_entry_updated',
				(int) $id,
				array(
					'text_domain' => is_string( $text_domain ) ? $text_domain : '',
				)
			);
		}

		if ( false !== $result ) {
			wptsall_log_info(
				'templates-entries',
				'Entry updated',
				array(
					'id'     => $id,
					'fields' => array_keys( $update_data ),
					'status' => $update_data['status'] ?? null,
				)
			);
		}

		return false !== $result;
	}

	/**
	 * Clear translation cache for entry
	 *
	 * @param int $entry_id Entry ID
	 * @return string Text domain for the entry, or empty string when unknown.
	 */
	private static function clear_cache_for_entry( $entry_id ) {
		global $wpdb;
		$entries_table   = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );

		// Get template info for entry
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$template_info = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.relation_id, t.text_domain
				 FROM %i e
				 JOIN %i t ON e.template_id = t.id
				 WHERE e.id = %d",
				$entries_table,
				$templates_table,
				$entry_id
			),
			ARRAY_A
		);

		if ( $template_info ) {
			self::clear_translations_cache(
				(int) $template_info['relation_id'],
				$template_info['text_domain']
			);
			return (string) ( $template_info['text_domain'] ?? '' );
		}

		return '';
	}

	/**
	 * Bulk update entries
	 *
	 * @param array $ids  Entry ID array
	 * @param array $data Update data
	 * @return int Update count
	 */
	public static function bulk_update( $ids, $data ) {
		$count = 0;

		foreach ( $ids as $id ) {
			if ( self::update( $id, $data ) ) {
				$count++;
			}
		}

		wptsall_log(
			'templates',
			'info',
			'Bulk update entries completed',
			array(
				'updated' => $count,
				'total'   => count( $ids ),
				'fields'  => array_keys( $data ),
			)
		);

		return $count;
	}

	/**
	 * Delete entry
	 *
	 * @param int $id Entry ID
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false !== $result && $result > 0 ) {
			wptsall_log_info(
				'templates-entries',
				'Entry deleted',
				array( 'id' => $id )
			);
		}

		return false !== $result;
	}

	/**
	 * Find entry by msgid
	 *
	 * @param int    $template_id Template ID
	 * @param string $msgid       Source string
	 * @param string $msgctxt     Context
	 * @return array|null Entry data
	 */
	public static function find_by_msgid( $template_id, $msgid, $msgctxt = '' ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE template_id = %d AND msgid = %s AND msgctxt = %s',
				$table,
				$template_id,
				$msgid,
				$msgctxt
			),
			ARRAY_A
		);

		return $entry;
	}

	/**
	 * Get translations (for Hooks usage)
	 *
	 * Uses object cache for performance to avoid repeated database queries.
	 *
	 * @param int    $relation_id Site relation ID
	 * @param string $text_domain Translation domain
	 * @return array [msgid => msgstr, ...] or [msgctxt:msgid => msgstr, ...]
	 */
	public static function get_translations_for_hook( $relation_id, $text_domain ) {
		// Try to get from cache
		$cache_key   = "translations_{$relation_id}_{$text_domain}";
		$cache_group = 'wptsall_templates';
		$cached      = wp_cache_get( $cache_key, $cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.msgid, e.msgctxt, e.msgstr, e.msgstr_plural
				 FROM %i e
				 JOIN %i t ON e.template_id = t.id
				 WHERE t.relation_id = %d
				 AND t.text_domain = %s
				 AND e.status IN (%s, %s)
				 AND e.msgstr != ''",
				$entries_table,
				$templates_table,
				$relation_id,
				$text_domain,
				'translated',
				'reviewed'
			),
			ARRAY_A
		);

		$translations = array();

		foreach ( $results as $row ) {
			$key = $row['msgid'];
			if ( ! empty( $row['msgctxt'] ) ) {
				$key = $row['msgctxt'] . "\x04" . $row['msgid']; // Use EOT separator
			}
			$translations[ $key ] = array(
				'msgstr'       => $row['msgstr'],
				'msgstr_plural'=> $row['msgstr_plural'],
			);
		}

		// Cache result (1 hour expiry)
		wp_cache_set( $cache_key, $translations, $cache_group, HOUR_IN_SECONDS );

		return $translations;
	}

	/**
	 * Clear translations cache
	 *
	 * Call this method when translation entries are updated.
	 *
	 * @param int    $relation_id Site relation ID (optional, empty clears all)
	 * @param string $text_domain Translation domain (optional)
	 */
	public static function clear_translations_cache( $relation_id = 0, $text_domain = '' ) {
		$cache_group = 'wptsall_templates';

		if ( $relation_id && $text_domain ) {
			// Clear specific cache
			$cache_key = "translations_{$relation_id}_{$text_domain}";
			wp_cache_delete( $cache_key, $cache_group );
		} else {
			// Clear entire cache group (requires object cache flush group support)
			// For cache backends that don't support this, this operation may have no effect
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( $cache_group );
			}
		}

		wptsall_log_debug(
			'templates',
			'Translations cache cleared',
			array(
				'relation_id' => $relation_id,
				'text_domain' => $text_domain,
			)
		);
	}

	/**
	 * Get translation (contract method)
	 *
	 * Queries translation by language code, conforming to MODULE-CHAINS.md Contract 4.
	 * Uses object cache for performance.
	 *
	 * @since 0.8.0
	 * @param string $original Source string
	 * @param string $domain   Translation domain
	 * @param string $language Target language code (e.g. zh_CN)
	 * @return string|null Translation result or null
	 */
	public static function get_translation( string $original, string $domain, string $language ): ?string {
		// Try to get from cache
		$cache_key   = 'translation_' . md5( "{$original}_{$domain}_{$language}" );
		$cache_group = 'wptsall_templates';
		$cached      = wp_cache_get( $cache_key, $cache_group );

		if ( false !== $cached ) {
			return '' === $cached ? null : $cached;
		}

		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$msgstr = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT e.msgstr
				 FROM %i e
				 JOIN %i t ON e.template_id = t.id
				 WHERE e.msgid = %s
				 AND t.text_domain = %s
				 AND t.target_language = %s
				 AND e.status IN (%s, %s)
				 AND e.msgstr != ''
				 LIMIT 1",
				$entries_table,
				$templates_table,
				$original,
				$domain,
				$language,
				'translated',
				'reviewed'
			)
		);

		// Cache result (1 hour expiry), empty results are also cached
		wp_cache_set( $cache_key, $msgstr ?: '', $cache_group, HOUR_IN_SECONDS );

		return $msgstr ?: null;
	}

	/**
	 * Batch get translations (contract method)
	 *
	 * Batch queries translations by language code, conforming to MODULE-CHAINS.md Contract 4.
	 * Uses a single SQL query for performance optimization.
	 *
	 * @since 0.8.0
	 * @param array  $originals Source string array
	 * @param string $domain    Translation domain
	 * @param string $language  Target language code (e.g. zh_CN)
	 * @return array [original => translation, ...] Only returns entries with translations
	 */
	public static function get_translations_batch( array $originals, string $domain, string $language ): array {
		if ( empty( $originals ) ) {
			return array();
		}

		// Try to get batch result from cache
		$cache_key   = 'translations_batch_' . md5( implode( '|', $originals ) . "_{$domain}_{$language}" );
		$cache_group = 'wptsall_templates';
		$cached      = wp_cache_get( $cache_key, $cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );

		// Build IN clause placeholders
		$placeholders = implode( ', ', array_fill( 0, count( $originals ), '%s' ) );

		// Build query parameters
		$query_args = array_merge(
			array( $entries_table, $templates_table ),
			$originals,
			array( $domain, $language, 'translated', 'reviewed' )
		);

		$results = wptsall_db_get_results(
			"SELECT e.msgid, e.msgstr
				 FROM %i e
				 JOIN %i t ON e.template_id = t.id
				 WHERE e.msgid IN ({$placeholders})
				 AND t.text_domain = %s
				 AND t.target_language = %s
				 AND e.status IN (%s, %s)
				 AND e.msgstr != ''",
			$query_args,
			ARRAY_A
		);

		$translations = array();
		foreach ( (array) $results as $row ) {
			$translations[ $row['msgid'] ] = $row['msgstr'];
		}

		// Cache result (1 hour expiry)
		wp_cache_set( $cache_key, $translations, $cache_group, HOUR_IN_SECONDS );

		return $translations;
	}

	/**
	 * Find translation (internal method)
	 *
	 * Queries translation by relation_id, for internal use.
	 *
	 * @param int    $relation_id Site relation ID
	 * @param string $text_domain Translation domain
	 * @param string $msgid       Source string
	 * @param string $msgctxt     Context
	 * @return string|null Translation result
	 */
	public static function find_translation( $relation_id, $text_domain, $msgid, $msgctxt = '' ) {
		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$msgstr = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT e.msgstr
				 FROM %i e
				 JOIN %i t ON e.template_id = t.id
				 WHERE t.relation_id = %d
				 AND t.text_domain = %s
				 AND e.msgid = %s
				 AND e.msgctxt = %s
				 AND e.status IN (%s, %s)
				 AND e.msgstr != ''
				 LIMIT 1",
				$entries_table,
				$templates_table,
				$relation_id,
				$text_domain,
				$msgid,
				$msgctxt,
				'translated',
				'reviewed'
			)
		);

		return $msgstr;
	}

	/**
	 * Get or create entry
	 *
	 * @param int   $template_id Template ID
	 * @param array $data        Entry data
	 * @return int Entry ID
	 */
	public static function get_or_create( $template_id, $data ) {
		$existing = self::find_by_msgid(
			$template_id,
			$data['msgid'],
			$data['msgctxt'] ?? ''
		);

		if ( $existing ) {
			// Update reference (if new ones exist)
			if ( ! empty( $data['reference'] ) ) {
				$existing_refs = $existing['reference'] ? explode( ', ', $existing['reference'] ) : array();
				$new_refs      = explode( ', ', $data['reference'] );
				$merged_refs   = array_unique( array_merge( $existing_refs, $new_refs ) );

				if ( count( $merged_refs ) > count( $existing_refs ) ) {
					self::update( $existing['id'], array(
						'reference' => implode( ', ', array_slice( $merged_refs, 0, 10 ) ), // Keep max 10 references
					) );
				}
			}
			return (int) $existing['id'];
		}

		$data['template_id'] = $template_id;
		$new_id              = self::create( $data );

		if ( false === $new_id ) {
			// If creation failed, it might be a race condition or a unique index collision (prefix match).
			// Try to find it again with exact match.
			$retry = self::find_by_msgid( $template_id, $data['msgid'], $data['msgctxt'] ?? '' );
			if ( $retry ) {
				return (int) $retry['id'];
			}

			// If still not found, it's likely a collision on the index prefix (usually 191 chars for TEXT columns).
			// We search for an entry that shares the same prefix to avoid infinite loops and log spam.
			global $wpdb;
			$table  = wptsall_table( 'template_entries' );
			$prefix = mb_substr( $data['msgid'], 0, 191 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$collision = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE template_id = %d AND msgid LIKE %s AND msgctxt = %s LIMIT 1",
					$table,
					$template_id,
					$wpdb->esc_like( $prefix ) . '%',
					$data['msgctxt'] ?? ''
				)
			);

			if ( $collision ) {
				return (int) $collision;
			}
		}

		return $new_id;
	}

	/**
	 * Delete all entries for a template
	 *
	 * @param int $template_id Template ID
	 * @return int Delete count
	 */
	public static function delete_by_template( $template_id ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'template_id' => $template_id ),
			array( '%d' )
		);

		$count = $result ?: 0;

		if ( $count > 0 ) {
			wptsall_log(
				'templates',
				'info',
				'Deleted entries by template',
				array(
					'template_id' => $template_id,
					'count'       => $count,
				)
			);
		}

		return $count;
	}

	/**
	 * Get pending entry ID list
	 *
	 * @param int $template_id Template ID
	 * @param int $limit       Limit count
	 * @return array Entry ID array
	 */
	public static function get_pending_ids( $template_id, $limit = 100 ) {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE template_id = %d AND status = %s ORDER BY id ASC LIMIT %d',
				$table,
				$template_id,
				'pending',
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}
}
