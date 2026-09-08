<?php
/**
 * Virtual Site Auto-Sync
 *
 * Listens to the relation lifecycle actions emitted by
 * Site_Relation_Service and ensures that any new `virtual` target has a
 * corresponding `wp_wptsall_virtual_sites` row.
 *
 * Why this exists (P0-1 fix):
 *   `Virtual_Site_Router::add_rewrite_rules()` and `get_cached_virtual_sites()`
 *   rely on the union of `wp_wptsall_virtual_sites` and
 *   `wp_wptsall_site_relations` (see
 *   `Virtual_Site_Service::merge_site_relations()`). The merge fallback
 *   works but loses the `id` (auto-increment) and creates synthetic path
 *   prefixes derived from `sanitize_title($lang)`. In the field that
 *   leads to URLs that are not stable across admin renames.
 *
 *   Auto-creating a real `wp_wptsall_virtual_sites` row keeps the URL
 *   prefix pinned to the admin-set `path_prefix` and provides an id that
 *   admin pages can edit.
 *
 * @package WPTSALL\Sites\Services
 * @since 1.5.0 P0-1 fix.
 */

namespace WPTSALL\Sites\Services;

use WPTSALL\Sites\Validators\Site_Relation_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Virtual_Site_Auto_Sync {

	/**
	 * Hook registration. Called from the sites module boot.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wptsall_site_relations_created', array( __CLASS__, 'on_relations_created' ), 10, 2 );
		add_action( 'wptsall_site_relation_updated',  array( __CLASS__, 'on_relation_updated' ), 10, 2 );
	}

	/**
	 * @since 1.5.0
	 *
	 * @param array $relation_ids Created relation ids.
	 * @param array $data         Original create payload.
	 * @return void
	 */
	public static function on_relations_created( $relation_ids, $data ) {
		$targets = is_array( $data ) ? ( $data['target_sites'] ?? array() ) : array();
		foreach ( (array) $targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}
			if ( 'virtual' !== ( $target['type'] ?? 'wp' ) ) {
				continue;
			}
			self::ensure_virtual_site_for_target( $target );
		}
	}

	/**
	 * @since 1.5.0
	 *
	 * @param int   $relation_id Updated relation id.
	 * @param array $data         Update payload.
	 * @return void
	 */
	public static function on_relation_updated( $relation_id, $data ) {
		if ( empty( $data['target_sites'] ) || ! is_array( $data['target_sites'] ) ) {
			return;
		}
		foreach ( $data['target_sites'] as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}
			if ( 'virtual' !== ( $target['type'] ?? 'wp' ) ) {
				continue;
			}
			self::ensure_virtual_site_for_target( $target );
		}
	}

	/**
	 * Walk all active virtual relations and create a virtual_site row for
	 * every target that does not already have one. Used by the wp-cli
	 * `wp wptsall reconcile-vs` command and as a one-shot admin helper.
	 *
	 * @since 1.5.0
	 *
	 * @return array{created:int, skipped:int, errors:array<int,string>}
	 */
	public static function reconcile_all(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, target_site_id, target_theme_name, target_theme_path, target_lang, source_site_id
				 FROM %i
				 WHERE target_site_type = 'virtual' AND status = 'active'",
				$table
			),
			ARRAY_A
		);
		$stats = array( 'created' => 0, 'skipped' => 0, 'errors' => array() );
		foreach ( (array) $rows as $r ) {
			$target = array(
				'id'   => (string) ( $r['target_site_id'] ?? '' ),
				'type' => 'virtual',
				'lang' => (string) ( $r['target_lang'] ?? '' ),
				'name' => (string) ( $r['target_theme_name'] ?? '' ),
				'path' => (string) ( $r['target_theme_path'] ?? '' ),
			);
			$ok = self::ensure_virtual_site_for_target( $target, true );
			if ( true === $ok ) {
				++$stats['created'];
			} elseif ( 'skip' === $ok ) {
				++$stats['skipped'];
			} else {
				$stats['errors'][] = "rel={$r['id']} target={$target['id']}: {$ok}";
			}
		}
		return $stats;
	}

	/**
	 * Ensure a wp_wptsall_virtual_sites row exists for the given target.
	 *
	 * @since 1.5.0
	 *
	 * @param array $target  Target descriptor: id, type, lang, name?, path?.
	 * @param bool  $silent  When true, suppress wptsall_log_info calls.
	 * @return true|string    true on create, 'skip' when already present,
	 *                        error message string on failure.
	 */
	protected static function ensure_virtual_site_for_target( array $target, bool $silent = false ) {
		$target_id = (string) ( $target['id'] ?? '' );
		if ( '' === $target_id ) {
			return 'empty target id';
		}
		$raw_id = Site_Relation_Validator::parse_virtual_site_id( $target_id );

		// Note: do NOT call Virtual_Site_Service::get() here. The current
		// implementation falls through to get_all() on miss and that path
		// can stall in some environments (see P0-1 notes). A direct SQL
		// lookup is sufficient for the existence check we need.
		global $wpdb;
		$table    = $wpdb->prefix . 'wptsall_virtual_sites';
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %s', $table, $raw_id ) );
		if ( $existing ) {
			return 'skip';
		}
		$name  = (string) ( $target['name'] ?? '' );
		if ( '' === $name ) {
			$name = 'Auto-synced Site ' . substr( $raw_id, 0, 16 );
		}
		$path  = (string) ( $target['path'] ?? '' );
		if ( '' === $path ) {
			$path = sanitize_title( (string) ( $target['lang'] ?? 'auto' ) );
		}
		$lang  = (string) ( $target['lang'] ?? '' );
		if ( '' === $lang ) {
			$lang = get_locale();
		}
		$res = Virtual_Site_Service::create(
			array(
				'name'        => $name,
				'path_prefix' => $path,
				'lang'        => $lang,
			)
		);
		if ( empty( $res['success'] ) ) {
			$err = isset( $res['errors'] ) ? implode( '; ', (array) $res['errors'] ) : 'unknown';
			if ( ! $silent ) {
				wptsall_log_warning( 'sites-auto-sync', 'Virtual site create failed', array(
					'target_id' => $target_id,
					'error'     => $err,
				) );
			}
			return $err;
		}
		if ( ! $silent ) {
			wptsall_log_info( 'sites-auto-sync', 'Virtual site auto-created for relation target', array(
				'target_id' => $target_id,
				'site_id'   => $res['site_id'] ?? null,
				'name'      => $name,
				'path'      => $path,
			) );
		}
		// Fire rewrite-rules flush so the new prefix is registered.
		Virtual_Site_Service::flush_rewrite_rules_if_available();
		return true;
	}
}
