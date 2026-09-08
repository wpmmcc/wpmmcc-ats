<?php
/**
 * Sync Service (1.3.0)
 *
 * Implements bidirectional post/taxonomy meta sync between source and
 * target posts via wp_wptsall_post_mappings. When settings.sync_mode = 'full',
 * any post meta / taxonomy change on a source post propagates to its
 * translated target, and vice versa. The cycle is broken by skipping
 * propagation if the post is already a sync target (recursive guard).
 *
 * Mirrors Polylang's `pll_sync_post_meta` and WPML's "copy once" +
 * "translate independently" model.
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\Sync\Services;

use WPTSALL\Settings\Services\Settings_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Sync_Service {

	const SYNC_META_KEYS = array(
		'_thumbnail_id',
		'_wp_page_template',
		'_menu_item_type',
		'_menu_item_object_id',
		'_menu_item_object',
		'_menu_item_url',
	);

	public static function init() {
		if ( self::is_full_mode() ) {
			add_action( 'added_post_meta',    array( __CLASS__, 'on_meta_changed' ), 10, 4 );
			add_action( 'updated_post_meta',  array( __CLASS__, 'on_meta_changed' ), 10, 4 );
			add_action( 'deleted_post_meta',  array( __CLASS__, 'on_meta_deleted' ), 10, 4 );
			add_action( 'wp_set_object_terms', array( __CLASS__, 'on_terms_changed' ), 10, 6 );
		}
	}

	public static function is_full_mode() {
		return Settings_Service::get( 'sync_mode', 'new_only' ) === 'full';
	}

	/**
	 * Whether a write is already inside the unified dispatcher guard.
	 *
	 * @return bool
	 */
	private static function is_internal_write() {
		return class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' )
			&& \WPTSALL\Hooks\Content_Change_Dispatcher::is_internal_write();
	}

	/**
	 * Run a target write under the shared internal-write guard when available.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	private static function run_internal_write( callable $callback ) {
		if ( class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
			return \WPTSALL\Hooks\Content_Change_Dispatcher::with_internal_write( $callback );
		}
		return $callback();
	}

	/**
	 * Propagate post meta add/update from a source post to its targets.
	 */
	public static function on_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( self::is_internal_write() || ! self::is_syncable_meta( $meta_key ) ) {
			return;
		}
		$targets = self::find_target_post_mappings_for( (int) $object_id );
		self::run_internal_write(
			static function () use ( $targets, $object_id, $meta_key, $meta_value ) {
				foreach ( $targets as $mapping ) {
					self::with_target_mapping(
						$mapping,
						static function ( $target_id, $target_mapping ) use ( $object_id, $meta_key, $meta_value ) {
							$target = get_post( $target_id );
							if ( ! $target || ( ! empty( $target_mapping['target_post_type'] ) && $target->post_type !== $target_mapping['target_post_type'] ) ) {
								return;
							}
							if ( self::is_syncing_recursively( (int) $object_id, $target_id, (string) $meta_key ) ) {
								return;
							}
							update_post_meta( $target_id, (string) $meta_key, $meta_value );
						}
					);
				}
			}
		);
	}

	/**
	 * Propagate post meta delete.
	 */
	public static function on_meta_deleted( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if ( self::is_internal_write() || ! self::is_syncable_meta( $meta_key ) ) {
			return;
		}
		$targets = self::find_target_post_mappings_for( (int) $object_id );
		self::run_internal_write(
			static function () use ( $targets, $object_id, $meta_key ) {
				foreach ( $targets as $mapping ) {
					self::with_target_mapping(
						$mapping,
						static function ( $target_id, $target_mapping ) use ( $object_id, $meta_key ) {
							$target = get_post( $target_id );
							if ( ! $target || ( ! empty( $target_mapping['target_post_type'] ) && $target->post_type !== $target_mapping['target_post_type'] ) ) {
								return;
							}
							if ( self::is_syncing_recursively( (int) $object_id, $target_id, (string) $meta_key ) ) {
								return;
							}
							delete_post_meta( $target_id, (string) $meta_key );
						}
					);
				}
			}
		);
	}

	/**
	 * Propagate term assignment (categories / tags / custom taxonomy).
	 *
	 * Only relation-scoped term IDs are written to a target. Never pass source
	 * term IDs directly to another blog because numeric term IDs are local.
	 *
	 * @param int    $object_id  Post id.
	 * @param array  $terms      Term ids being set.
	 * @param array  $tt_ids     Term taxonomy ids.
	 * @param string $taxonomy   Taxonomy name.
	 */
	public static function on_terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $tt_ids, $old_tt_ids );
		if ( self::is_internal_write() || in_array( $taxonomy, array( 'language', 'post_translations', 'term_language', 'term_translations' ), true ) ) {
			return;
		}
		$source_term_ids = array_values( array_filter( array_map( 'absint', (array) $terms ) ) );
		if ( empty( $source_term_ids ) || ! class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
			return;
		}
		$source_site_id = (int) get_current_blog_id();
		$taxonomy       = sanitize_key( (string) $taxonomy );
		$targets        = self::find_target_post_mappings_for( (int) $object_id );
		$mapped_by_target = array();
		foreach ( $targets as $mapping ) {
			$target_taxonomy = sanitize_key( (string) ( $mapping['target_taxonomy'] ?? $taxonomy ) );
			if ( '' === $target_taxonomy || $target_taxonomy !== $taxonomy ) {
				continue;
			}
			$mapped = \WPTSALL\Models\Services\Term_Mapping_Service::batch_map_terms(
				$source_term_ids,
				$taxonomy,
				$source_site_id,
				(string) ( $mapping['target_site_id'] ?? '' ),
				(string) ( $mapping['target_lang'] ?? '' ),
				array( 'relation_id' => absint( $mapping['relation_id'] ?? 0 ), 'create_if_missing' => false )
			);
			if ( ! empty( $mapped ) ) {
				$mapped_by_target[ self::target_mapping_key( $mapping ) ] = array( $mapping, array_values( $mapped ) );
			}
		}
		self::run_internal_write(
			static function () use ( $mapped_by_target, $taxonomy, $append ) {
				foreach ( $mapped_by_target as $entry ) {
					$mapping   = $entry[0];
					$target_ids = $entry[1];
					self::with_target_mapping(
						$mapping,
						static function ( $target_id ) use ( $target_ids, $taxonomy, $append ) {
							if ( get_post( $target_id ) ) {
								wp_set_object_terms( $target_id, $target_ids, $taxonomy, (bool) $append );
							}
						}
					);
				}
			}
		);
	}

	/**
	 * Return active relation-scoped mapping rows for a source post.
	 *
	 * @param int      $source_id      Source post ID.
	 * @param int      $relation_id    Optional relation filter.
	 * @param int|null $source_site_id Source site; defaults to current blog.
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_target_post_mappings_for( $source_id, $relation_id = 0, $source_site_id = null ) {
		global $wpdb;
		$source_id      = absint( $source_id );
		$relation_id    = absint( $relation_id );
		$source_site_id = null === $source_site_id ? (int) get_current_blog_id() : absint( $source_site_id );
		if ( $source_id <= 0 || $source_site_id <= 0 ) {
			return array();
		}
		$table     = wptsall_table( 'post_mappings' );
		$relations = wptsall_table( 'site_relations' );
		$where     = 'pm.source_post_id = %d AND pm.source_site_id = %d AND pm.relation_id > 0 AND pm.target_post_id > 0 AND pm.target_site_id <> %s';
		$params    = array( $table, $relations, 'active', $source_id, $source_site_id, '' );
		if ( $relation_id > 0 ) {
			$where   .= ' AND pm.relation_id = %d';
			$params[] = $relation_id;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where is placeholder-only; values bound via $params.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pm.target_post_id, pm.target_post_type, pm.target_site_id,
						pm.relation_id, r.target_site_type, r.target_lang,
						pm.relationship_type
				 FROM %i pm
				 INNER JOIN %i r ON r.id = pm.relation_id
					AND r.source_site_id = pm.source_site_id
					AND r.target_site_id = pm.target_site_id
					AND r.status = %s
				 WHERE ' . $where . '
				 ORDER BY pm.id ASC',
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find all target post ids for a source post, relation-scoped.
	 *
	 * @param int $source_id   Source post ID.
	 * @param int $relation_id Optional relation filter.
	 * @return array<int>
	 */
	public static function find_target_posts_for( $source_id, $relation_id = 0 ) {
		$rows = self::find_target_post_mappings_for( $source_id, $relation_id );
		$out  = array();
		foreach ( $rows as $row ) {
			$id = absint( $row['target_post_id'] ?? 0 );
			if ( $id > 0 ) {
				$out[ self::target_mapping_key( $row ) . ':' . $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * Run a callback while the mapping target blog is current.
	 *
	 * @param array    $mapping Mapping row.
	 * @param callable $callback Callback receiving target ID and mapping.
	 * @return mixed
	 */
	private static function with_target_mapping( array $mapping, callable $callback ) {
		$target_id   = absint( $mapping['target_post_id'] ?? 0 );
		$target_type = sanitize_key( (string) ( $mapping['target_site_type'] ?? 'wp' ) );
		$target_site = (string) ( $mapping['target_site_id'] ?? '' );
		if ( $target_id <= 0 || '' === $target_site ) {
			return null;
		}
		$switched = false;
		if ( 'wp' === $target_type && is_multisite() && ctype_digit( $target_site ) ) {
			$target_blog_id = (int) $target_site;
			if ( $target_blog_id > 0 && $target_blog_id !== (int) get_current_blog_id() ) {
				switch_to_blog( $target_blog_id );
				$switched = true;
			}
		}
		try {
			return $callback( $target_id, $mapping );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Build a stable relation/target key for de-duplication.
	 *
	 * @param array $mapping Mapping row.
	 * @return string
	 */
	private static function target_mapping_key( array $mapping ) {
		return absint( $mapping['relation_id'] ?? 0 ) . ':' . (string) ( $mapping['target_site_id'] ?? '' );
	}

	/**
	 * Recursion guard: skip if we're about to write the same meta back.
	 * We use a transient flag to short-circuit. The flag is set in
	 * on_meta_changed before calling update_post_meta and cleared after.
	 */
	private static $recursion_flags = array();

	private static function is_syncing_recursively( $source_id, $target_id, $meta_key ) {
		$key = $source_id . ':' . $target_id . ':' . $meta_key;
		if ( ! empty( self::$recursion_flags[ $key ] ) ) {
			return true;
		}
		self::$recursion_flags[ $key ] = 1;
		// Clear after this request cycle.
		add_action( 'shutdown', function () use ( $key ) {
			unset( self::$recursion_flags[ $key ] );
		} );
		return false;
	}

	/**
	 * Whitelist of post meta keys that should be synced.
	 */
	private static function is_syncable_meta( $key ) {
		// Skip internal _wptsall_* keys (would cause infinite loops).
		if ( strpos( (string) $key, '_wptsall_' ) === 0 ) {
			return false;
		}
		// Skip _edit_lock, _edit_last (revision locking).
		if ( in_array( (string) $key, array( '_edit_lock', '_edit_last' ), true ) ) {
			return false;
		}
		// Default: sync the well-known ones, skip others to keep things conservative.
		return in_array( (string) $key, self::SYNC_META_KEYS, true );
	}

	/**
	 * Manual sync trigger: sync all known mappings in one go. Useful for
	 * "Re-sync everything" admin button.
	 */
	public static function sync_all_mapped_meta() {
		global $wpdb;
		$count          = 0;
		$source_site_id = (int) get_current_blog_id();
		$table          = wptsall_table( 'post_mappings' );
		$relations      = wptsall_table( 'site_relations' );
		$source_ids     = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT pm.source_post_id
				 FROM %i pm
				 INNER JOIN %i r ON r.id = pm.relation_id
					AND r.source_site_id = pm.source_site_id
					AND r.target_site_id = pm.target_site_id
					AND r.status = %s
				 WHERE pm.source_site_id = %d
				 AND pm.relation_id > 0
				 AND pm.target_post_id > 0',
				$table,
				$relations,
				'active',
				$source_site_id
			)
		);
		foreach ( (array) $source_ids as $source_id ) {
			$source_id = absint( $source_id );
			if ( $source_id <= 0 ) {
				continue;
			}
			$values = array();
			foreach ( self::SYNC_META_KEYS as $key ) {
				$value = get_post_meta( $source_id, $key, true );
				if ( '' !== $value && false !== $value ) {
					$values[ $key ] = $value;
				}
			}
			if ( empty( $values ) ) {
				continue;
			}
			$targets = self::find_target_post_mappings_for( $source_id, 0, $source_site_id );
			self::run_internal_write(
				static function () use ( $targets, $values, &$count ) {
					foreach ( $targets as $mapping ) {
						self::with_target_mapping(
							$mapping,
							static function ( $target_id, $target_mapping ) use ( $values, &$count ) {
								$target = get_post( $target_id );
								if ( ! $target || ( ! empty( $target_mapping['target_post_type'] ) && $target->post_type !== $target_mapping['target_post_type'] ) ) {
									return;
								}
								foreach ( $values as $key => $value ) {
									update_post_meta( $target_id, $key, $value );
									$count++;
								}
							}
						);
					}
				}
			);
		}
		return $count;
	}
}
