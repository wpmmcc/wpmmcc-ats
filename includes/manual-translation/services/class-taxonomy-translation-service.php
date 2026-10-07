<?php
/**
 * Taxonomy Translation Service (P5-5)
 *
 * Manages manual term-to-term translation mappings across languages.
 * Wraps Post_Mapping_Service-style operations for terms.
 *
 * @package WPTSALL\ManualTranslation
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Services;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Models\Services\Term_Mapping_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Taxonomy_Translation_Service {

	/**
	 * Get all terms in a taxonomy that have NO translation in the target language.
	 *
	 * @since 1.4.0
	 *
	 * @param string $taxonomy    Taxonomy slug.
	 * @param string $target_lang Target language code.
	 * @param int    $limit       Max rows.
	 * @param int    $relation_id Optional site relation ID for relation-scoped pending state.
	 * @param int    $offset      Row offset for pagination (default 0; ATS-P2-05 / 3.8flash B4).
	 * @return array<int, array{term_id:int, name:string, slug:string, count:int}>
	 */
	public static function pending_terms( string $taxonomy, string $target_lang, int $limit = 200, int $relation_id = 0, int $offset = 0 ): array {
		return self::pending_scan( $taxonomy, $target_lang, $relation_id, max( 1, (int) $limit ), max( 0, (int) $offset ) )['rows'];
	}

	/**
	 * Count pending terms with the same filters as pending_terms()
	 * (ATS-P2-05 / 3.8flash B4: the pagination bar needs the FILTERED total).
	 *
	 * @since 1.4.0
	 *
	 * @param string $taxonomy    Taxonomy slug.
	 * @param string $target_lang Target language code.
	 * @param int    $relation_id Optional site relation ID.
	 * @return int
	 */
	public static function count_pending_terms( string $taxonomy, string $target_lang, int $relation_id = 0 ): int {
		return self::pending_scan( $taxonomy, $target_lang, $relation_id, 0, 0 )['total'];
	}

	/**
	 * Windowed pending scan (ATS-P2-05 / 3.8flash B4).
	 *
	 * The pre-B4 code fetched ONE get_terms(number=$limit) batch and filtered it,
	 * so the "pending" list was capped by TERMS FETCHED — deep pending rows were
	 * silently unreachable. This pages get_terms() in batches (WP-native term
	 * filters preserved — no raw SQL rewrite), removes already-mapped terms, and
	 * honors $limit/$offset over the FILTERED pending set. $limit = 0 means
	 * count-only mode (full scan, no slice).
	 *
	 * @param string $taxonomy    Taxonomy slug.
	 * @param string $target_lang Target language code.
	 * @param int    $relation_id Relation scope (0 = language-wide).
	 * @param int    $limit       Page size (0 = count-only).
	 * @param int    $offset      Row offset.
	 * @return array{total:int, rows:array}
	 */
	private static function pending_scan( string $taxonomy, string $target_lang, int $relation_id, int $limit, int $offset ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_term_mappings';
		if ( $relation_id > 0 ) {
			$mapped = $wpdb->get_col( $wpdb->prepare(
				'SELECT DISTINCT source_term_id FROM %i WHERE source_taxonomy = %s AND target_lang = %s AND relation_id = %d',
				$table,
				$taxonomy,
				$target_lang,
				$relation_id
			) );
		} else {
			$mapped = $wpdb->get_col( $wpdb->prepare(
				'SELECT DISTINCT source_term_id FROM %i WHERE source_taxonomy = %s AND target_lang = %s',
				$table,
				$taxonomy,
				$target_lang
			) );
		}
		$mapped = array_map( 'intval', (array) $mapped );

		$pending      = array();
		$batch        = 500;
		$fetch_offset = 0;
		while ( true ) {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $batch,
				'offset'     => $fetch_offset,
			) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				break;
			}
			foreach ( (array) $terms as $t ) {
				if ( in_array( (int) $t->term_id, $mapped, true ) ) {
					continue;
				}
				$pending[] = array(
					'term_id' => (int) $t->term_id,
					'name'    => (string) $t->name,
					'slug'    => (string) $t->slug,
					'count'   => (int) $t->count,
				);
			}
			if ( count( (array) $terms ) < $batch ) {
				break; // taxonomy exhausted
			}
			if ( $limit > 0 && count( $pending ) >= $offset + $limit ) {
				break; // the requested page window is complete
			}
			$fetch_offset += $batch;
		}

		$total = count( $pending );
		$rows  = ( $limit > 0 ) ? array_slice( $pending, $offset, $limit ) : array();
		return array(
			'total' => $total,
			'rows'  => $rows,
		);
	}

	/**
	 * Get existing translation mappings for a term.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $term_id Source term id.
	 * @param string $taxonomy Source taxonomy.
	 * @param int    $relation_id Optional site relation ID for relation-scoped listing.
	 * @return array<string, array{target_term_id:int, target_taxonomy:string, target_lang:string, relation_id:int}>
	 */
	public static function get_translations( int $term_id, string $taxonomy, int $relation_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_term_mappings';
		if ( $relation_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT target_term_id, target_taxonomy, target_lang, relation_id FROM %i WHERE source_term_id = %d AND source_taxonomy = %s AND relation_id = %d',
				$table,
				$term_id,
				$taxonomy,
				$relation_id
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT target_term_id, target_taxonomy, target_lang, relation_id FROM %i WHERE source_term_id = %d AND source_taxonomy = %s',
				$table,
				$term_id,
				$taxonomy
			), ARRAY_A );
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$key = (string) $r['target_lang'];
			if ( $relation_id <= 0 && (int) ( $r['relation_id'] ?? 0 ) > 0 ) {
				$key .= '#relation-' . (int) $r['relation_id'];
			}
			$out[ $key ] = array(
				'target_term_id'   => (int) $r['target_term_id'],
				'target_taxonomy'  => (string) $r['target_taxonomy'],
				'target_lang'      => (string) $r['target_lang'],
				'relation_id'      => (int) ( $r['relation_id'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Link a source term to a target term as a translation.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $source_term_id  Source term id.
	 * @param string $source_taxonomy Source taxonomy.
	 * @param int    $target_term_id  Target term id.
	 * @param string $target_lang     Target language code.
	 * @param int    $relation_id_arg Optional site relation ID for unambiguous virtual-site mapping.
	 * @return int|false Mapping id or false on failure.
	 */
	public static function link( int $source_term_id, string $source_taxonomy, int $target_term_id, string $target_lang, int $relation_id_arg = 0 ) {
		$source_term = get_term( $source_term_id, $source_taxonomy );
		if ( ! $source_term || is_wp_error( $source_term ) ) {
			return false;
		}
		$target_term = get_term( $target_term_id, $source_taxonomy );
		if ( ! $target_term || is_wp_error( $target_term ) ) {
			return false;
		}

		$target_site_id  = (string) get_current_blog_id();
		$virtual_site_id = '';
		$relation_id     = max( 0, (int) $relation_id_arg );
		if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$site = \WPTSALL\Sites\Services\Virtual_Site_Service::get_by_relation( $relation_id );
			if ( is_array( $site ) && ! empty( $site['id'] ) ) {
				$virtual_site_id = (string) $site['id'];
				$target_site_id  = 0 === strpos( $virtual_site_id, 'v_' ) ? $virtual_site_id : 'v_' . $virtual_site_id;
			}
		}
		if ( '' === $virtual_site_id && class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			foreach ( (array) \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) ) as $site ) {
				$site_lang = (string) ( $site['lang'] ?? $site['site_language'] ?? '' );
				if ( strtolower( str_replace( '-', '_', $site_lang ) ) !== strtolower( str_replace( '-', '_', $target_lang ) ) ) {
					continue;
				}
				$virtual_site_id = (string) ( $site['id'] ?? '' );
				if ( $relation_id <= 0 ) {
					$relation_id = (int) ( $site['relation_id'] ?? 0 );
				}
				if ( $relation_id <= 0 ) {
					$relation_id = (int) ( $site['site_relation_id'] ?? 0 );
				}
				if ( '' !== $virtual_site_id ) {
					$target_site_id = 0 === strpos( $virtual_site_id, 'v_' ) ? $virtual_site_id : 'v_' . $virtual_site_id;
				}
				if ( $relation_id <= 0 && class_exists( Site_Relation_Service::class ) ) {
					foreach ( (array) Site_Relation_Service::get_all_relations( array( 'status' => 'active' ) ) as $relation ) {
						$rid = (string) ( $relation['target_site_id'] ?? '' );
						if ( ( $rid === $target_site_id || $rid === $virtual_site_id )
							&& 'virtual' === (string) ( $relation['target_site_type'] ?? '' )
							&& strtolower( str_replace( '-', '_', (string) ( $relation['target_lang'] ?? '' ) ) ) === strtolower( str_replace( '-', '_', $target_lang ) ) ) {
							$relation_id = (int) ( $relation['id'] ?? 0 );
							break;
						}
					}
				}
				break;
			}
		}

		$default = Language_Service::get_default();
		$mapping_id = Term_Mapping_Service::create_mapping( array(
			'source_term_id'   => $source_term_id,
			'source_taxonomy'  => $source_taxonomy,
			'source_site_id'   => get_current_blog_id(),
			'source_lang'      => is_array( $default ) && ! empty( $default['code'] ) ? (string) $default['code'] : 'zh_CN',
			'relation_id'      => $relation_id,
			'target_term_id'   => $target_term_id,
			'target_taxonomy'  => $source_taxonomy,
			'target_site_id'   => $target_site_id,
			'target_lang'      => $target_lang,
			'mapping_method'   => 'manual',
		) );

		if ( $mapping_id && '' !== $virtual_site_id && function_exists( 'update_term_meta' ) ) {
			update_term_meta( $target_term_id, '_wptsall_virtual_site_id', $virtual_site_id );
			update_term_meta( $target_term_id, '_wptsall_source_term_id', $source_term_id );
			update_term_meta( $target_term_id, '_wptsall_source_taxonomy', $source_taxonomy );
			if ( $relation_id > 0 ) {
				update_term_meta( $target_term_id, '_wptsall_relation_id', $relation_id );
			}
		}

		return $mapping_id;
	}

	/**
	 * Unlink a source term from a target term in the given target language.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $source_term_id  Source term id.
	 * @param string $source_taxonomy Source taxonomy.
	 * @param string $target_lang     Target language code.
	 * @param int    $relation_id     Optional site relation ID for scoped unlink.
	 * @return int Number of mappings deleted.
	 */
	public static function unlink( int $source_term_id, string $source_taxonomy, string $target_lang, int $relation_id = 0 ): int {
		global $wpdb;
		$where = array(
			'source_term_id'  => $source_term_id,
			'source_taxonomy' => $source_taxonomy,
			'target_lang'     => $target_lang,
		);
		$formats = array( '%d', '%s', '%s' );
		if ( $relation_id > 0 ) {
			$where['relation_id'] = $relation_id;
			$formats[] = '%d';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->delete(
			$wpdb->prefix . 'wptsall_term_mappings',
			$where,
			$formats
		);
	}
}
