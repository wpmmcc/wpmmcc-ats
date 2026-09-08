<?php
/**
 * Translation Progress Service (P5-7)
 *
 * Computes per-post-type / per-taxonomy translation completion stats.
 * Mapping language comes from site_relations.target_lang (post_mappings has no target_lang column).
 *
 * @package WPTSALL\ManualTranslation
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Services;

use WPTSALL\Languages\Services\Language_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Translation_Progress_Service {
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter


	/**
	 * Get a top-level summary of translation progress.
	 *
	 * @since 1.4.0
	 *
	 * @return array{
	 *     total_source: int,
	 *     total_translated: int,
	 *     percent: int,
	 *     by_language: array<string, array{total: int, translated: int, percent: int}>,
	 *     by_post_type: array<string, array{total: int, translated: int, percent: int}>,
	 *     needs_resync: int,
	 * }
	 */
	public static function summary(): array {
		global $wpdb;
		$languages = Language_Service::get_all( array( 'status' => 'active' ) );
		$lang_codes = array_map( static function ( $l ) { return (string) $l['code']; }, $languages );
		$default = Language_Service::get_default();
		$default_code = $default ? (string) $default['code'] : ( $lang_codes[0] ?? 'zh_CN' );

		$by_language = array();
		foreach ( $lang_codes as $code ) {
			$by_language[ $code ] = array(
				'total'      => 0,
				'translated' => 0,
				'percent'    => 0,
			);
		}

		$by_post_type = array();
		$total_source = 0;
		$total_translated = 0;

		$mappings_table  = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';
		$relations_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';

		// Count source posts; a mapping is "to language L" via relation.target_lang.
		$sql = "SELECT p.ID, p.post_type, COUNT(m.id) AS mapping_count
				FROM %i p
				LEFT JOIN %i m
				  ON m.source_post_id = p.ID
				LEFT JOIN %i r
				  ON r.id = m.relation_id AND r.target_lang <> %s AND r.status = 'active'
				WHERE p.post_status IN ('publish','draft','pending','future','private')
				  AND p.post_type NOT IN ('revision','attachment','nav_menu_item','custom_css','oembed_cache','user_request','wp_block','wp_font_family','wp_font_face')
				GROUP BY p.ID, p.post_type";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $wpdb->posts, $mappings_table, $relations_table, $default_code ) );

		foreach ( (array) $rows as $r ) {
			$pt = (string) $r->post_type;
			if ( ! isset( $by_post_type[ $pt ] ) ) {
				$by_post_type[ $pt ] = array(
					'total'      => 0,
					'translated' => 0,
					'percent'    => 0,
				);
			}
			$by_post_type[ $pt ]['total']++;
			$total_source++;
			$mapping_count = (int) $r->mapping_count;
			$target_langs  = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT r.target_lang
					 FROM %i m
					 INNER JOIN %i r ON r.id = m.relation_id
					 WHERE m.source_post_id = %d
					   AND r.target_lang <> %s
					   AND r.status = 'active'",
					$mappings_table,
					$relations_table,
					(int) $r->ID,
					$default_code
				)
			);
			foreach ( $target_langs as $tl ) {
				$tl = (string) $tl;
				if ( isset( $by_language[ $tl ] ) ) {
					$by_language[ $tl ]['translated']++;
				}
			}
			if ( $mapping_count > 0 ) {
				$by_post_type[ $pt ]['translated']++;
				$total_translated++;
			}
		}

		foreach ( $by_language as $code => &$info ) {
			$info['total']   = $total_source;
			$info['percent'] = $info['total'] > 0 ? (int) round( $info['translated'] * 100 / $info['total'] ) : 0;
		}
		unset( $info );
		foreach ( $by_post_type as $pt => &$info ) {
			$info['percent'] = $info['total'] > 0 ? (int) round( $info['translated'] * 100 / $info['total'] ) : 0;
		}
		unset( $info );

		$percent = $total_source > 0 ? (int) round( $total_translated * 100 / $total_source ) : 0;

		$needs_resync = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE needs_resync = 1',
				$mappings_table
			)
		);

		return array(
			'total_source'     => $total_source,
			'total_translated' => $total_translated,
			'percent'          => $percent,
			'by_language'      => $by_language,
			'by_post_type'     => $by_post_type,
			'needs_resync'     => $needs_resync,
		);
	}

	/**
	 * Get list of source posts that have no translation in the given target language.
	 *
	 * @since 1.4.0
	 *
	 * @param string $target_lang Target language code.
	 * @param string $post_type   Optional post type filter.
	 * @param int    $limit       Max rows (default 200).
	 * @return array<int, array{ID:int, post_title:string, post_type:string, post_status:string, post_date:string, edit_link:string}>
	 */
	public static function pending_for_language( string $target_lang, string $post_type = '', int $limit = 200 ): array {
		global $wpdb;

		$mappings_table  = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';
		$relations_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
		$where           = "p.post_status IN ('publish','draft','pending','future','private')
				  AND p.post_type NOT IN ('revision','attachment','nav_menu_item','custom_css','oembed_cache','user_request','wp_block','wp_font_family','wp_font_face')";
		// posts, mappings, relations, target_lang, mappings (subquery), [post_type], limit
		$params = array( $wpdb->posts, $mappings_table, $relations_table, $target_lang, $mappings_table );
		if ( '' !== $post_type ) {
			$where   .= ' AND p.post_type = %s';
			$params[] = $post_type;
		}
		$params[] = $limit;

		$sql = "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_date
				FROM %i p
				LEFT JOIN %i m
				  ON m.source_post_id = p.ID
				LEFT JOIN %i r
				  ON r.id = m.relation_id AND r.target_lang = %s AND r.status = 'active'
				WHERE r.id IS NULL
				  AND p.ID NOT IN (
				      SELECT target_post_id FROM %i
				      WHERE target_post_id IS NOT NULL AND target_post_id > 0
				  )
				  AND {$where}
				ORDER BY p.post_date DESC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'ID'         => (int) $r->ID,
				'post_title' => (string) $r->post_title,
				'post_type'  => (string) $r->post_type,
				'post_status'=> (string) $r->post_status,
				'post_date'  => (string) $r->post_date,
				'edit_link'  => get_edit_post_link( (int) $r->ID, '' ),
			);
		}
		return $out;
	}

	/**
	 * Three-layer progress: content (A), site strings (B), UI gettext templates (C).
	 *
	 * @return array{layer_a:array,layer_b:array,layer_c:array}
	 */
	public static function layer_summary(): array {
		$content = self::summary();
		$layer_b = array(
			'total'      => 0,
			'translated' => 0,
			'pending'    => 0,
			'percent'    => 0,
		);
		if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			$sc = \WPTSALL\Strings\Services\String_Translation_Service::counts();
			$layer_b['total']      = (int) ( $sc['total'] ?? 0 );
			$layer_b['translated'] = (int) ( $sc['translated'] ?? 0 );
			$layer_b['pending']    = (int) ( $sc['pending'] ?? 0 );
			$layer_b['percent']    = $layer_b['total'] > 0
				? (int) round( 100 * $layer_b['translated'] / $layer_b['total'] )
				: 0;
		}
		$layer_c = array(
			'total'      => 0,
			'translated' => 0,
			'pending'    => 0,
			'percent'    => 0,
		);
		global $wpdb;
		if ( function_exists( 'wptsall_table' ) ) {
			$entries_table = wptsall_table( 'template_entries' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entries_table ) );
			if ( $exists === $entries_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $entries_table ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$translated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'translated'", $entries_table ) );
				$layer_c['total']      = $total;
				$layer_c['translated'] = $translated;
				$layer_c['pending']    = max( 0, $total - $translated );
				$layer_c['percent']    = $total > 0 ? (int) round( 100 * $translated / $total ) : 0;
			}
		}
		return array(
			'layer_a' => array(
				'label'      => __( 'Published content', 'wpmmcc-ats' ),
				'total'      => (int) ( $content['total_source'] ?? 0 ),
				'translated' => (int) ( $content['total_translated'] ?? 0 ),
				'percent'    => (int) ( $content['percent'] ?? 0 ),
			),
			'layer_b' => array_merge( array( 'label' => __( 'Site structure strings', 'wpmmcc-ats' ) ), $layer_b ),
			'layer_c' => array_merge( array( 'label' => __( 'Theme / plugin UI', 'wpmmcc-ats' ) ), $layer_c ),
		);
	}
// phpcs:enable
}
