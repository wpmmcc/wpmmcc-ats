<?php
/**
 * Term Mapping Service
 *
 * Handles category/tag ID mappings between source and target sites.
 * Supports auto-creation of missing terms with translation.
 *
 * @package WPTSALL\Models\Services
 * @since 0.5.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Term Mapping Service Class
 */
class Term_Mapping_Service {

	/**
	 * Get term mapping from source to target
	 *
	 * @param int    $source_term_id   Source term ID.
	 * @param string $source_taxonomy  Source taxonomy name.
	 * @param int    $source_site_id   Source site ID.
	 * @param string $target_site_id   Target site ID (can be virtual).
	 * @param string $target_lang      Target language code.
	 * @param int    $relation_id      Optional relation ID. When provided, the
	 *                                 lookup is strictly relation-scoped.
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_mapping( $source_term_id, $source_taxonomy, $source_site_id, $target_site_id, $target_lang, $relation_id = 0 ) {
		global $wpdb;
		$table       = wptsall_table( 'term_mappings' );
		$relation_id = absint( $relation_id );

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE relation_id = %d
					AND source_term_id = %d
					AND source_taxonomy = %s
					AND source_site_id = %d
					AND target_site_id = %s
					AND target_lang = %s",
					$table,
					$relation_id,
					$source_term_id,
					$source_taxonomy,
					$source_site_id,
					$target_site_id,
					$target_lang
				),
				ARRAY_A
			);
		} else {
			// Preserve the historical API for legacy callers. Relation-aware
			// write-back and ID resolution always pass relation_id.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE source_term_id = %d
					AND source_taxonomy = %s
					AND source_site_id = %d
					AND target_site_id = %s
					AND target_lang = %s",
					$table,
					$source_term_id,
					$source_taxonomy,
					$source_site_id,
					$target_site_id,
					$target_lang
				),
				ARRAY_A
			);
		}

		return $mapping ? $mapping : null;
	}

	/**
	 * Get reverse mapping (target to source)
	 *
	 * @param int    $target_term_id   Target term ID.
	 * @param string $target_taxonomy  Target taxonomy name.
	 * @param string $target_site_id   Target site ID.
	 * @param int    $source_site_id   Source site ID.
	 * @param int    $relation_id      Optional relation ID. When provided, the
	 *                                 lookup is strictly relation-scoped.
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_reverse_mapping( $target_term_id, $target_taxonomy, $target_site_id, $source_site_id, $relation_id = 0 ) {
		global $wpdb;
		$table       = wptsall_table( 'term_mappings' );
		$relation_id = absint( $relation_id );

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE relation_id = %d
					AND target_term_id = %d
					AND target_taxonomy = %s
					AND target_site_id = %s
					AND source_site_id = %d",
					$table,
					$relation_id,
					$target_term_id,
					$target_taxonomy,
					$target_site_id,
					$source_site_id
				),
				ARRAY_A
			);
		} else {
			// Preserve the historical API for legacy callers.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE target_term_id = %d
					AND target_taxonomy = %s
					AND target_site_id = %s
					AND source_site_id = %d",
					$table,
					$target_term_id,
					$target_taxonomy,
					$target_site_id,
					$source_site_id
				),
				ARRAY_A
			);
		}

		return $mapping ? $mapping : null;
	}

	/**
	 * Create or update term mapping
	 *
	 * @param array $mapping_data {
	 *     Mapping data.
	 *
	 *     @type int    $source_term_id      Source term ID.
	 *     @type string $source_taxonomy     Source taxonomy.
	 *     @type int    $source_site_id      Source site ID.
	 *     @type string $source_lang         Source language.
	 *     @type int    $target_term_id      Target term ID.
	 *     @type string $target_taxonomy     Target taxonomy.
	 *     @type string $target_site_id      Target site ID.
	 *     @type string $target_lang         Target language.
	 *     @type int    $relation_id         Site relation ID.
	 *     @type string $mapping_method      Mapping method (manual/auto_create/auto_match).
	 *     @type string $translation_method  Translation method (api/template/fallback).
	 * }
	 * @return int|false Mapping ID or false on failure.
	 */
	public static function create_mapping( $mapping_data ) {
		global $wpdb;
		$table       = wptsall_table( 'term_mappings' );
		$relation_id = isset( $mapping_data['relation_id'] )
			? absint( $mapping_data['relation_id'] )
			: 0;

		// Check if mapping already exists
		$existing = self::get_mapping(
			$mapping_data['source_term_id'],
			$mapping_data['source_taxonomy'],
			$mapping_data['source_site_id'],
			$mapping_data['target_site_id'],
			$mapping_data['target_lang'],
			$relation_id
		);

		$now = current_time( 'mysql', true );

		$data = array(
			'relation_id'         => $relation_id,
			'source_term_id'      => $mapping_data['source_term_id'],
			'source_taxonomy'     => $mapping_data['source_taxonomy'],
			'source_site_id'      => $mapping_data['source_site_id'],
			'source_lang'         => $mapping_data['source_lang'],
			'target_term_id'      => $mapping_data['target_term_id'],
			'target_taxonomy'     => $mapping_data['target_taxonomy'],
			'target_site_id'      => $mapping_data['target_site_id'],
			'target_lang'         => $mapping_data['target_lang'],
			'mapping_method'      => $mapping_data['mapping_method'] ?? 'manual',
			'translation_method'  => $mapping_data['translation_method'] ?? null,
			'updated_at'          => $now,
		);

		if ( $existing ) {
			// Update existing mapping
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				$data,
				array( 'id' => $existing['id'] ),
				array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( $result !== false ) {
				// Keep legacy store in sync.
				self::sync_to_legacy_store( $mapping_data );
				return (int) $existing['id'];
			}

			return false;
		} else {
			// Create new mapping
			$data['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$table,
				$data,
				array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( $result ) {
				// Capture insert_id before sync_to_legacy_store(), which performs
				// its own INSERT and overwrites $wpdb->insert_id.
				$new_id = (int) $wpdb->insert_id;
				// Keep legacy store in sync.
				self::sync_to_legacy_store( $mapping_data );
				return $new_id;
			}

			return false;
		}

		// Audit log hook (P6-3) — fired on success only.
		$mapping_id = isset( $new_id ) ? $new_id : ( isset( $existing['id'] ) ? (int) $existing['id'] : 0 );
		if ( $mapping_id > 0 ) {
			do_action( 'wptsall_term_mapping_created', $mapping_id, $mapping_data, true );
		}
	}

	/**
	 * Map term ID with auto-creation support
	 *
	 * @param int    $source_term_id   Source term ID.
	 * @param string $taxonomy         Taxonomy name.
	 * @param int    $source_site_id   Source site ID.
	 * @param string $target_site_id   Target site ID.
	 * @param string $target_lang      Target language.
	 * @param array  $config           Configuration options.
	 * @return int|false Target term ID or false on failure.
	 */
	public static function map_term_with_creation( $source_term_id, $taxonomy, $source_site_id, $target_site_id, $target_lang, $config = array() ) {
		$relation_id = absint( $config['relation_id'] ?? 0 );
		// Check if mapping exists
		$mapping = self::get_mapping( $source_term_id, $taxonomy, $source_site_id, $target_site_id, $target_lang, absint( $config['relation_id'] ?? 0 ) );

		if ( $mapping ) {
			return (int) $mapping['target_term_id'];
		}

		// Get configuration
		$create_if_missing = $config['create_if_missing'] ?? false;
		$auto_translate    = $config['auto_translate'] ?? false;
		$match_by_slug     = $config['match_by_slug'] ?? false;

		// Try to match by slug first
		if ( $match_by_slug ) {
			$matched_id = self::match_term_by_slug( $source_term_id, $taxonomy, $source_site_id, $target_site_id );
			if ( $matched_id ) {
				// Create mapping for matched term
				self::create_mapping(
					array(
						'source_term_id'     => $source_term_id,
						'source_taxonomy'    => $taxonomy,
						'source_site_id'     => $source_site_id,
						'source_lang'        => self::get_site_language( $source_site_id ),
						'target_term_id'     => $matched_id,
						'target_taxonomy'    => $taxonomy,
						'target_site_id'     => $target_site_id,
						'target_lang'        => $target_lang,
						'relation_id'       => $relation_id,
						'mapping_method'     => 'auto_match',
						'translation_method' => 'slug_match',
					)
				);
				return $matched_id;
			}
		}

		// Auto-create if enabled
		if ( $create_if_missing ) {
			$target_term_id = self::create_target_term(
				$source_term_id,
				$taxonomy,
				$source_site_id,
				$target_site_id,
				$target_lang,
				$auto_translate,
				$relation_id
			);

			if ( $target_term_id ) {
				// Create mapping
				self::create_mapping(
					array(
						'source_term_id'      => $source_term_id,
						'source_taxonomy'     => $taxonomy,
						'source_site_id'      => $source_site_id,
						'source_lang'         => self::get_site_language( $source_site_id ),
						'target_term_id'      => $target_term_id,
						'target_taxonomy'     => $taxonomy,
						'target_site_id'      => $target_site_id,
						'target_lang'         => $target_lang,
						'relation_id'        => $relation_id,
						'mapping_method'      => 'auto_create',
						'translation_method'  => $auto_translate ? 'api' : 'fallback',
					)
				);
				return $target_term_id;
			}
		}

		return false;
	}

	/**
	 * Match term by slug
	 *
	 * @param int    $source_term_id  Source term ID.
	 * @param string $taxonomy        Taxonomy name.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @return int|false Target term ID if found, false otherwise.
	 */
	private static function match_term_by_slug( $source_term_id, $taxonomy, $source_site_id, $target_site_id ) {
		// Get source term
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$source_term = get_term( $source_term_id, $taxonomy );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $source_term || is_wp_error( $source_term ) ) {
			return false;
		}

		// Switch to target site if needed (only for multisite, not virtual)
		if ( is_numeric( $target_site_id ) ) {
			$need_switch = is_multisite() && (int) $target_site_id !== get_current_blog_id();
			if ( $need_switch ) {
				switch_to_blog( (int) $target_site_id );
			}

			// Search by slug in target site
			$target_term = get_term_by( 'slug', $source_term->slug, $taxonomy );

			if ( $need_switch ) {
				restore_current_blog();
			}

			if ( $target_term && ! is_wp_error( $target_term ) ) {
				return $target_term->term_id;
			}
		}

		return false;
	}

	/**
	 * Create target term with optional translation.
	 *
	 * Supports both WP multisite subsites and virtual sites.
	 * For virtual sites, creates a real wp_terms entry with meta markers
	 * (_wptsall_virtual_site_id, _wptsall_source_term_id, etc.).
	 * Handles hierarchical taxonomies by resolving parent term mappings.
	 *
	 * @since 0.5.0
	 * @since 1.1.0 Added virtual site support, parent resolution, and term_exists fallback.
	 *
	 * @param int    $source_term_id   Source term ID.
	 * @param string $taxonomy         Taxonomy name.
	 * @param int    $source_site_id   Source site ID.
	 * @param string $target_site_id   Target site ID (numeric for WP, string for virtual).
	 * @param string $target_lang      Target language code.
	 * @param bool   $auto_translate   Whether to auto-translate the term name.
	 * @param int    $relation_id      Optional site relation ID.
	 * @return int|false Created term ID or false on failure.
	 */
	private static function create_target_term( $source_term_id, $taxonomy, $source_site_id, $target_site_id, $target_lang, $auto_translate = false, $relation_id = 0 ) {
		// Get source term data.
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$source_term = get_term( $source_term_id, $taxonomy );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $source_term || is_wp_error( $source_term ) ) {
			return false;
		}

		// Prepare term data.
		$term_name = $source_term->name;
		$term_slug = $source_term->slug;

		// Translate if enabled.
		if ( $auto_translate ) {
			$term_name = $term_name . ' (' . $target_lang . ')';
		}

		// Resolve parent term for hierarchical taxonomies.
		$parent_id = 0;
		if ( $source_term->parent > 0 ) {
			$mapped_parent = self::get_mapping(
				$source_term->parent,
				$taxonomy,
				$source_site_id,
				$target_site_id,
				$target_lang,
				$relation_id
			);

			if ( $mapped_parent ) {
				$parent_id = (int) $mapped_parent['target_term_id'];
			}
		}

		// Virtual site: create term with meta markers on the main blog.
		if ( ! is_numeric( $target_site_id ) ) {
			return self::create_virtual_site_term(
				$source_term,
				$taxonomy,
				$term_name,
				$term_slug,
				$target_lang,
				$target_site_id,
				$source_site_id,
				$parent_id
			);
		}

		// WP multisite subsite: create term on target blog.
		$need_switch = is_multisite() && (int) $target_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( (int) $target_site_id );
		}

		$target_slug = $term_slug . '-' . $target_lang;

		// Check if a term with this slug already exists.
		$existing = get_term_by( 'slug', $target_slug, $taxonomy );
		if ( $existing && ! is_wp_error( $existing ) ) {
			if ( $need_switch ) {
				restore_current_blog();
			}
			return $existing->term_id;
		}

		$result = wp_insert_term(
			$term_name,
			$taxonomy,
			array(
				'slug'        => $target_slug,
				'description' => $source_term->description,
				'parent'      => $parent_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			// Handle term_exists by falling back to get_term_by slug.
			if ( 'term_exists' === $result->get_error_code() ) {
				$fallback = get_term_by( 'slug', $target_slug, $taxonomy );
				if ( $need_switch ) {
					restore_current_blog();
				}
				return $fallback ? $fallback->term_id : false;
			}

			if ( $need_switch ) {
				restore_current_blog();
			}

			wptsall_log_error(
				'models',
				'Failed to create target term',
				array(
					'source_term_id' => $source_term_id,
					'taxonomy'       => $taxonomy,
					'target_site_id' => $target_site_id,
					'error'          => $result->get_error_message(),
				)
			);
			return false;
		}

		$new_term_id = $result['term_id'];

		// Add source tracking meta.
		update_term_meta( $new_term_id, '_wptsall_source_term_id', $source_term_id );
		update_term_meta( $new_term_id, '_wptsall_source_blog_id', $source_site_id );
		\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $new_term_id, '_wptsall_last_synced', current_time( 'mysql' ) );

		if ( $need_switch ) {
			restore_current_blog();
		}

		return $new_term_id;
	}

	/**
	 * Create a term for a virtual site.
	 *
	 * Virtual site terms are stored as real wp_terms entries on the main blog
	 * with meta markers identifying them as virtual site content.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Term $source_term     Source term object.
	 * @param string   $taxonomy        Taxonomy name.
	 * @param string   $term_name       Term name (possibly translated).
	 * @param string   $term_slug       Base term slug.
	 * @param string   $target_lang     Target language code.
	 * @param string   $virtual_site_id Virtual site identifier (e.g., 'v_zh').
	 * @param int      $source_site_id  Source site ID.
	 * @param int      $parent_id       Parent term ID (0 if none).
	 * @return int|false Created term ID or false on failure.
	 */
	private static function create_virtual_site_term( $source_term, $taxonomy, $term_name, $term_slug, $target_lang, $virtual_site_id, $source_site_id, $parent_id = 0 ) {
		$target_slug = $term_slug . '-' . $target_lang;

		// Check if virtual term already exists via meta markers.
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
				$source_term->term_id,
				$taxonomy
			)
		);

		if ( $existing_id ) {
			return (int) $existing_id;
		}

		// Try to find by slug first.
		$existing_by_slug = get_term_by( 'slug', $target_slug, $taxonomy );
		if ( $existing_by_slug && ! is_wp_error( $existing_by_slug ) ) {
			// Claim this term for the virtual site.
			update_term_meta( $existing_by_slug->term_id, '_wptsall_virtual_site_id', $virtual_site_id );
			update_term_meta( $existing_by_slug->term_id, '_wptsall_source_term_id', $source_term->term_id );
			update_term_meta( $existing_by_slug->term_id, '_wptsall_source_blog_id', $source_site_id );
			\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $existing_by_slug->term_id, '_wptsall_last_synced', current_time( 'mysql' ) );
			return $existing_by_slug->term_id;
		}

		// Create new term.
		$result = wp_insert_term(
			$term_name,
			$taxonomy,
			array(
				'slug'        => $target_slug,
				'description' => $source_term->description,
				'parent'      => $parent_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				$fallback = get_term_by( 'slug', $target_slug, $taxonomy );
				if ( $fallback ) {
					$new_term_id = $fallback->term_id;
				} else {
					wptsall_log_error(
						'models',
						'Failed to create virtual site term',
						array(
							'source_term_id'  => $source_term->term_id,
							'taxonomy'        => $taxonomy,
							'virtual_site_id' => $virtual_site_id,
							'error'           => $result->get_error_message(),
						)
					);
					return false;
				}
			} else {
				wptsall_log_error(
					'models',
					'Failed to create virtual site term',
					array(
						'source_term_id'  => $source_term->term_id,
						'taxonomy'        => $taxonomy,
						'virtual_site_id' => $virtual_site_id,
						'error'           => $result->get_error_message(),
					)
				);
				return false;
			}
		} else {
			$new_term_id = $result['term_id'];
		}

		// Add virtual site markers.
		update_term_meta( $new_term_id, '_wptsall_virtual_site_id', $virtual_site_id );
		update_term_meta( $new_term_id, '_wptsall_source_term_id', $source_term->term_id );
		update_term_meta( $new_term_id, '_wptsall_source_blog_id', $source_site_id );
		\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $new_term_id, '_wptsall_last_synced', current_time( 'mysql' ) );

		return $new_term_id;
	}

	/**
	 * Get site language
	 *
	 * @param int $site_id Site ID.
	 * @return string Language code.
	 */
	private static function get_site_language( $site_id ) {
		if ( ! is_multisite() ) {
			return get_option( 'WPLANG', 'en_US' );
		}

		$need_switch = $site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $site_id );
		}

		$lang = get_option( 'WPLANG', 'en_US' );

		if ( $need_switch ) {
			restore_current_blog();
		}

		return empty( $lang ) ? 'en_US' : $lang;
	}

	/**
	 * Get all mappings for a source term
	 *
	 * @param int    $source_term_id  Source term ID.
	 * @param string $source_taxonomy Source taxonomy.
	 * @param int    $source_site_id  Source site ID.
	 * @return array Array of mappings.
	 */
	public static function get_all_mappings_for_source( $source_term_id, $source_taxonomy, $source_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'term_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE source_term_id = %d
				AND source_taxonomy = %s
				AND source_site_id = %d
				ORDER BY target_lang ASC",
				$table,
				$source_term_id,
				$source_taxonomy,
				$source_site_id
			),
			ARRAY_A
		);

		return $mappings ? $mappings : array();
	}

	/**
	 * Delete mapping
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_mapping( $mapping_id ) {
		global $wpdb;
		$table = wptsall_table( 'term_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $mapping_id ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Batch map terms
	 *
	 * @param array  $term_ids        Array of term IDs.
	 * @param string $taxonomy        Taxonomy name.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @param string $target_lang     Target language.
	 * @param array  $config          Configuration options.
	 * @return array Array of source_id => target_id mappings.
	 */
	public static function batch_map_terms( $term_ids, $taxonomy, $source_site_id, $target_site_id, $target_lang, $config = array() ) {
		$mapped = array();

		foreach ( $term_ids as $term_id ) {
			$target_id = self::map_term_with_creation(
				$term_id,
				$taxonomy,
				$source_site_id,
				$target_site_id,
				$target_lang,
				$config
			);

			if ( $target_id ) {
				$mapped[ $term_id ] = $target_id;
			}
		}

		return $mapped;
	}

	/**
	 * Get mapped ID by relation (Chain 8 contract method)
	 *
	 * Simplified API method per MODULE-CHAINS.md Chain 8 spec.
	 * Get target ID by site relation ID and source ID.
	 *
	 * @since 0.8.1
	 *
	 * @param int $relation_id Site relation ID.
	 * @param int $source_id   Source term/tag ID.
	 * @return int|null Target term/tag ID or null.
	 */
	public static function get_mapped_id( int $relation_id, int $source_id ): ?int {
		// 1. Get site relation info
		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return null;
		}

		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
		$target_lang    = $relation['target_lang'] ?? 'en_US';

		if ( empty( $target_site_id ) ) {
			return null;
		}

		// 2. Get source term info (switch to source blog to ensure correct context)
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$term = get_term( $source_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		// 3. Query mapping
		$mapping = self::get_mapping(
			$source_id,
			$term->taxonomy,
			$source_site_id,
			$target_site_id,
			$target_lang,
			$relation_id
		);

		return $mapping ? (int) $mapping['target_term_id'] : null;
	}

	/**
	 * Clean up orphaned mappings
	 *
	 * Deletes mappings where source or target term no longer exists.
	 *
	 * @return int Number of mappings deleted.
	 */
	public static function cleanup_orphaned_mappings() {
		global $wpdb;
		$table = wptsall_table( 'term_mappings' );

		// Get all mappings
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i', $table ),
			ARRAY_A
		);

		$deleted = 0;

		foreach ( $mappings as $mapping ) {
			$source_exists = false;
			$target_exists = false;

			// Check source term exists
			if ( is_multisite() ) {
				switch_to_blog( $mapping['source_site_id'] );
			}
			$source_term = get_term( $mapping['source_term_id'], $mapping['source_taxonomy'] );
			$source_exists = $source_term && ! is_wp_error( $source_term );
			if ( is_multisite() ) {
				restore_current_blog();
			}

			// Check target term exists (only for numeric site IDs)
			if ( is_numeric( $mapping['target_site_id'] ) ) {
				if ( is_multisite() ) {
					switch_to_blog( (int) $mapping['target_site_id'] );
				}
				$target_term = get_term( $mapping['target_term_id'], $mapping['target_taxonomy'] );
				$target_exists = $target_term && ! is_wp_error( $target_term );
				if ( is_multisite() ) {
					restore_current_blog();
				}
			} else {
				// Virtual site - assume exists for now
				$target_exists = true;
			}

			// Delete if either doesn't exist
			if ( ! $source_exists || ! $target_exists ) {
				self::delete_mapping( $mapping['id'] );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Sync a term mapping to the legacy wp_wptsall_mappings store.
	 *
	 * Keeps the legacy mapping table in sync with the new term_mappings table.
	 * Called automatically after create_mapping() succeeds.
	 *
	 * @since 1.1.0
	 *
	 * @param array $mapping_data Term mapping data (same format as create_mapping input).
	 * @return void
	 */
	public static function sync_to_legacy_store( array $mapping_data ): void {
		if ( ! function_exists( 'wptsall_insert_mapping' ) ) {
			return;
		}

		$source_site_id = $mapping_data['source_site_id'] ?? 0;
		$source_term_id = $mapping_data['source_term_id'] ?? 0;
		$target_site_id = $mapping_data['target_site_id'] ?? '';
		$target_term_id = $mapping_data['target_term_id'] ?? 0;
		$taxonomy       = $mapping_data['source_taxonomy'] ?? '';

		if ( ! $source_term_id || ! $target_term_id || empty( $taxonomy ) ) {
			return;
		}

		// Legacy store uses numeric site IDs; for virtual sites extract the numeric
		// part from the 'v_{id}' format so lookups can match by virtual site ID.
		if ( is_numeric( $target_site_id ) ) {
			$target_blog = (int) $target_site_id;
		} elseif ( preg_match( '/^v_(\d+)$/', $target_site_id, $matches ) ) {
			$target_blog = (int) $matches[1];
		} else {
			$target_blog = 0;
		}

		wptsall_insert_mapping(
			(int) $source_site_id,
			'taxonomy',
			$taxonomy,
			(int) $source_term_id,
			$target_blog,
			(int) $target_term_id,
			$taxonomy,
			absint( $mapping_data['relation_id'] ?? 0 )
		);
	}
}
