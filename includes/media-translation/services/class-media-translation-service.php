<?php
/**
 * Media Translation Service (1.3.0)
 *
 * Tracks which media (image / attachment) is the translated version of a
 * given source media for each language. Mirrors Polylang's
 * `pll_translate_media` / `translate_media` action pattern.
 *
 * Schema (wp_wptsall_media_mappings):
 *   id, source_media_id, source_site_id, relation_id, source_file_path,
 *   source_file_url, target_media_id, target_site_id, target_file_path,
 *   target_file_url, mapping_method, alt_translated, metadata
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\MediaTranslation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Translation_Service {

	private static function table() {
		global $wpdb;
		return function_exists( 'wptsall_table' ) ? wptsall_table( 'media_mappings' ) : $wpdb->prefix . 'wptsall_media_mappings';
	}

	private static function table_exists() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$e = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		return $e === $t;
	}

	/**
	 * Look up the target media id for a source media id in a given language.
	 *
	 * The canonical mapping schema scopes a mapping by target_site_id, not by
	 * language. Resolve the active relation's target language here so frontend
	 * URL swapping uses the same key as callback/write-back media creation.
	 *
	 * @param int    $source_id Source attachment id.
	 * @param string $target_lang Target language code (e.g. en_US).
	 * @return int 0 if no translation found.
	 */
	public static function get_target_id( $source_id, $target_lang ) {
		if ( ! self::table_exists() || (int) $source_id <= 0 || '' === (string) $target_lang ) {
			return 0;
		}
		global $wpdb;
		$relations_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT m.target_media_id
			 FROM %i m
			 LEFT JOIN %i r
			   ON r.target_site_id = m.target_site_id
			  AND r.source_site_id = m.source_site_id
			  AND r.status = %s
			 WHERE m.source_media_id = %d
			 AND m.source_site_id = %d
			 AND (m.target_site_id = %s OR r.target_lang = %s)
			 ORDER BY (m.target_site_id = %s) DESC, r.id DESC, m.id DESC
			 LIMIT 1',
			self::table(),
			$relations_table,
			'active',
			(int) $source_id,
			(int) get_current_blog_id(),
			(string) $target_lang,
			(string) $target_lang,
			(string) $target_lang
		) );
		return $id > 0 ? (int) $id : 0;
	}

	/**
	 * Record a media translation mapping. If a row already exists for the same
	 * source + target_site_id, update the target.
	 *
	 * This is a compatibility facade for the media admin UI. The authoritative
	 * mapping implementation lives in Models\Services\Media_Mapping_Service;
	 * using it avoids the historical, non-existent relation_id column and keeps
	 * admin-created rows compatible with sync/write-back rows.
	 */
	public static function record( $source_id, $source_url, $source_path, $target_id, $target_url, $target_path, $target_lang, $method = 'copy' ) {
		if ( ! self::table_exists() || (int) $source_id <= 0 || (int) $target_id <= 0 || '' === (string) $target_lang ) {
			return false;
		}

		$target_site_id = self::target_site_id_for_language( (string) $target_lang );
		if ( '' === $target_site_id ) {
			// Retain compatibility with early installs that stored language as the
			// target identifier before site relations were introduced.
			$target_site_id = (string) $target_lang;
		}

		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Media_Mapping_Service' ) ) {
			return false;
		}

		$mapping_id = \WPTSALL\Models\Services\Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => (int) $source_id,
				'source_site_id'   => (int) get_current_blog_id(),
				'source_file_path' => (string) $source_path,
				'source_file_url'  => (string) $source_url,
				'target_media_id'  => (int) $target_id,
				'target_site_id'   => $target_site_id,
				'target_file_path' => (string) $target_path,
				'target_file_url'  => (string) $target_url,
				'mapping_method'   => (string) $method,
			)
		);

		return false !== $mapping_id;
	}

	/**
	 * Resolve a target site identifier from the current source site and language.
	 *
	 * @param string $target_lang Target language.
	 * @return string
	 */
	private static function target_site_id_for_language( $target_lang ) {
		if ( ! function_exists( 'wptsall_table' ) || '' === $target_lang ) {
			return '';
		}
		global $wpdb;
		$relations_table = wptsall_table( 'site_relations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT target_site_id FROM %i WHERE source_site_id = %d AND target_lang = %s AND status = %s ORDER BY id DESC LIMIT 1',
				$relations_table,
				(int) get_current_blog_id(),
				$target_lang,
				'active'
			)
		);
	}

	/**
	 * List media translations, optionally filtered.
	 */
	public static function list_mappings( $args = array() ) {
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$defaults = array( 'source_id' => 0, 'target_lang' => '', 'limit' => 200, 'offset' => 0 );
		$args = array_merge( $defaults, $args );
		$where = array( '1=1' );
		$params = array();
		if ( (int) $args['source_id'] > 0 ) {
			$where[] = 'source_media_id = %d';
			$params[] = (int) $args['source_id'];
		}
		if ( '' !== $args['target_lang'] ) {
			// Early installations used the language itself as target_site_id. Keep
			// those rows visible while canonical rows resolve through the active
			// source-site relation.
			$where[] = '(m.target_site_id = %s OR r.target_lang = %s)';
			$params[] = (string) $args['target_lang'];
			$params[] = (string) $args['target_lang'];
		}
		$where_sql = implode( ' AND ', $where );
		$limit  = max( 1, (int) $args['limit'] );
		$offset = max( 0, (int) $args['offset'] );
		$relations_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		$sql    = 'SELECT m.*, COALESCE(NULLIF(r.target_lang, \'\'), m.target_site_id) AS target_lang
			FROM %i m
			LEFT JOIN %i r
			  ON r.target_site_id = m.target_site_id
			 AND r.source_site_id = m.source_site_id
			 AND r.status = %s
			WHERE ' . $where_sql . ' ORDER BY m.id DESC LIMIT %d OFFSET %d';
		$params = array_merge( array( self::table(), $relations_table, 'active' ), $params, array( $limit, $offset ) );
		$rows   = wptsall_db_get_results( $sql, $params, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function delete( $id ) {
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	public static function counts() {
		if ( ! self::table_exists() ) {
			return array( 'total' => 0, 'sources' => 0, 'langs' => 0 );
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$src = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT source_media_id) FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relations_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		// Canonical rows are keyed by target site ID. Count their resolved
		// languages for the admin-facing statistic while retaining legacy rows.
		$langs = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT COALESCE(NULLIF(r.target_lang, \'\'), m.target_site_id))
				 FROM %i m
				 LEFT JOIN %i r
				   ON r.target_site_id = m.target_site_id
				  AND r.source_site_id = m.source_site_id
				  AND r.status = %s',
				$table,
				$relations_table,
				'active'
			)
		);
		return array( 'total' => $total, 'sources' => $src, 'langs' => $langs );
	}
}
