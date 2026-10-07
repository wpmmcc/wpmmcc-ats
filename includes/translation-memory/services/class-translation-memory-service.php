<?php
/**
 * Translation Memory Service
 *
 * Stores & retrieves bilingual pairs so recurring phrases (e.g. brand names,
 * UI labels) translate consistently across the site. Mirrors TranslatePress's
 * `trp_get_translation_from_memory()` pattern.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\TranslationMemory\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translation_Memory_Service {

	/**
	 * Option name for the automatic-recording toggle (opus5 M-04).
	 *
	 * Shared by the manual save path, the client callback path and the
	 * Translation Memory settings card.
	 */
	const OPTION_AUTO_RECORD = 'wptsall_tm_auto_record';

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wptsall_translation_memory';
	}

	/**
	 * Whether the TM table exists.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$e = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		return $e === $t;
	}

	/**
	 * Look up an exact-match translation.
	 *
	 * @param string $source_text Source text.
	 * @param string $source_lang Source language.
	 * @param string $target_lang Target language.
	 * @return string Empty string if no match.
	 */
	public static function lookup( $source_text, $source_lang, $target_lang ) {
		if ( ! self::table_exists() || '' === trim( (string) $source_text ) ) {
			return '';
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT target_text FROM %i
				 WHERE source_lang = %s AND target_lang = %s AND source_text = %s
				 LIMIT 1',
				self::table(),
				$source_lang,
				$target_lang,
				$source_text
			)
		);
		if ( $row ) {
			// Bump last_used_at + occurrences in the background.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET occurrences = occurrences + 1, last_used_at = NOW()
					 WHERE source_lang = %s AND target_lang = %s AND source_text = %s',
					self::table(),
					$source_lang,
					$target_lang,
					$source_text
				)
			);
			return (string) $row;
		}
		return '';
	}

	/**
	 * Suggest stored translation pairs for one manual-editor field.
	 *
	 * Domain-scoped variant of lookup() used by the manual content service:
	 * the relation supplies the language pair, the resolved text domain
	 * scopes the query (idx_domain), and exact source_text matches are
	 * ordered by usage so the most-used translation is suggested first.
	 * Suggestions are read-only; lookup() keeps the occurrence bumping.
	 *
	 * @since 2.1.4
	 * @param int    $relation_id Site relation ID.
	 * @param string $text_domain Resolved text domain (e.g. 'wordpress-blog').
	 * @param string $source_text Normalized source text.
	 * @param string $context     Optional context filter.
	 * @param int    $limit       Max suggestions (1-10).
	 * @return array List of suggestion items; empty array on any mismatch.
	 */
	public static function suggest_for_relation_domain( $relation_id, $text_domain, $source_text, $context = '', $limit = 5 ) {
		$source_text = trim( (string) $source_text );
		if ( '' === $source_text || ! self::table_exists() ) {
			return array();
		}

		if ( ! class_exists( '\WPTSALL\Sites\Services\Site_Relation_Service' ) ) {
			return array();
		}
		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( (int) $relation_id );
		if ( ! is_array( $relation ) ) {
			return array();
		}

		$source_lang = (string) ( $relation['source_lang'] ?? '' );
		$target_lang = (string) ( $relation['target_lang'] ?? '' );
		if ( '' === $source_lang || '' === $target_lang ) {
			return array();
		}

		$limit = max( 1, min( 10, (int) $limit ) );

		$sql  = 'SELECT id, target_text, domain, context, occurrences, last_used_at FROM %i
			 WHERE source_text = %s AND source_lang = %s AND target_lang = %s AND domain = %s';
		$args = array( self::table(), $source_text, $source_lang, $target_lang, (string) $text_domain );
		if ( '' !== trim( (string) $context ) ) {
			$sql   .= ' AND context = %s';
			$args[] = (string) $context;
		}
		$sql   .= ' ORDER BY occurrences DESC, updated_at DESC LIMIT %d';
		$args[] = $limit;

		$rows = wptsall_db_get_results( $sql, $args, ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'target_text'  => (string) ( $row['target_text'] ?? '' ),
				'domain'       => (string) ( $row['domain'] ?? '' ),
				'context'      => (string) ( $row['context'] ?? '' ),
				'occurrences'  => (int) ( $row['occurrences'] ?? 0 ),
				'last_used_at' => (string) ( $row['last_used_at'] ?? '' ),
			);
		}
		return $items;
	}

	/**
	 * Record a translation pair. If a row exists, refresh target_text + bump occurrences.
	 *
	 * @param string $source_text Source text.
	 * @param string $source_lang Source language.
	 * @param string $target_lang Target language.
	 * @param string $target_text Target text.
	 * @param string $domain      Optional domain.
	 * @param string $context     Optional context.
	 * @return bool
	 */
	public static function record( $source_text, $source_lang, $target_lang, $target_text, $domain = '', $context = '' ) {
		if ( ! self::table_exists() || '' === trim( (string) $source_text ) || '' === trim( (string) $target_text ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i
				 WHERE source_lang = %s AND target_lang = %s AND source_text = %s',
				self::table(),
				$source_lang,
				$target_lang,
				$source_text
			)
		);
		$row = array(
			'source_lang' => (string) $source_lang,
			'target_lang' => (string) $target_lang,
			'source_text' => (string) $source_text,
			'target_text' => (string) $target_text,
			'domain'      => (string) $domain,
			'context'     => (string) $context,
		);
		if ( $existing_id > 0 ) {
			// Existing rows already have occurrence count, leave it intact.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (bool) $wpdb->update( self::table(), $row, array( 'id' => $existing_id ) );
		}
		$row['occurrences'] = 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( self::table(), $row );
		if ( $ok ) {
			// Audit log hook (P6-3).
			do_action( 'wptsall_tm_recorded', $source_text, $source_lang, $target_lang, $target_text );
		}
		return (bool) $ok;
	}

	/**
	 * List TM pairs with optional filters.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function list_pairs( $args = array() ) {
		if ( ! self::table_exists() ) {
			return array();
		}
		$defaults = array(
			'source_lang' => '',
			'target_lang' => '',
			'search'      => '',
			'limit'       => 100,
			'offset'      => 0,
		);
		$args   = array_merge( $defaults, $args );
		$limit  = max( 1, (int) $args['limit'] );
		$offset = max( 0, (int) $args['offset'] );

		$built = self::build_pair_where( $args );
		$rows  = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $built['where_sql'] . ' ORDER BY occurrences DESC, id DESC LIMIT %d OFFSET %d',
			array_merge( array( self::table() ), $built['params'], array( $limit, $offset ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count TM pairs with the same filters as list_pairs() (ATS-P2-05 / 3.8flash B4:
	 * pagination needs the FILTERED total, not the global count() aggregate).
	 *
	 * @param array $args Query args (source_lang/target_lang/search; limit/offset ignored).
	 * @return int
	 */
	public static function count_pairs( $args = array() ) {
		if ( ! self::table_exists() ) {
			return 0;
		}
		$defaults = array(
			'source_lang' => '',
			'target_lang' => '',
			'search'      => '',
		);
		$args = array_merge( $defaults, $args );

		$built = self::build_pair_where( $args );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot filtered count for the pagination bar; freshness preferred.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $built['where_sql'] only carries %d/%s/%i fragments from build_pair_where(); every value goes through this prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $built['where_sql'], array_merge( array( self::table() ), $built['params'] ) ) );
	}

	/**
	 * Build the shared WHERE fragment (with %i placeholders already in $params)
	 * for list_pairs()/count_pairs() so the filtered count always matches the
	 * filtered listing.
	 *
	 * @param array $args Filter args.
	 * @return array{where_sql:string,params:array}
	 */
	private static function build_pair_where( $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( $args['source_lang'] ) {
			$where[]  = 'source_lang = %s';
			$params[] = $args['source_lang'];
		}
		if ( $args['target_lang'] ) {
			$where[]  = 'target_lang = %s';
			$params[] = $args['target_lang'];
		}
		if ( $args['search'] ) {
			$where[]  = '(source_text LIKE %s OR target_text LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		return array(
			'where_sql' => implode( ' AND ', $where ),
			'params'    => $params,
		);
	}

	/**
	 * Delete a TM row by id.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Aggregate counts.
	 *
	 * @return array{total:int,pairs:int}
	 */
	public static function counts() {
		if ( ! self::table_exists() ) {
			return array(
				'total' => 0,
				'pairs' => 0,
			);
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$pairs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT CONCAT(source_lang,'-',target_lang)) FROM %i",
				$table
			)
		);
		return array(
			'total' => $total,
			'pairs' => $pairs,
		);
	}

	/**
	 * Whether automatic TM recording is enabled (opus5 M-04).
	 *
	 * Default on: every manual save and client callback success feeds the
	 * memory. Site admins switch it off via Translation Memory → settings;
	 * the filter allows per-environment overrides.
	 *
	 * @return bool
	 */
	public static function is_auto_record_enabled() {
		$enabled = get_option( self::OPTION_AUTO_RECORD, true );
		return (bool) apply_filters( 'wptsall_tm_auto_record_enabled', $enabled );
	}

	/**
	 * Standard text-column source values for a post (TM pairing side).
	 *
	 * @param int $post_id Source post ID (resolvable in the current blog).
	 * @return array<string,string> field_key => source text (empty array when the post is unavailable).
	 */
	public static function get_source_values_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		return array(
			'post_title'   => (string) $post->post_title,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_content' => (string) $post->post_content,
		);
	}

	/**
	 * Record TM pairs for one saved translation (opus5 M-04).
	 *
	 * Mirrors the suggest-side caps: only string fields, both sides
	 * non-empty, each side at most 1200 characters — the same limit
	 * get_editor_data applies to suggestions, so nothing enters the
	 * memory that lookup would never accept.
	 *
	 * @param array  $source_values     field_key => source text.
	 * @param string $source_lang       Source language.
	 * @param string $target_lang       Target language.
	 * @param array  $translated_values field_key => translated text.
	 * @param string $domain            Memory domain (resolve_memory_text_domain).
	 * @return int Number of recorded pairs.
	 */
	public static function record_translation_pairs( $source_values, $source_lang, $target_lang, $translated_values, $domain = '' ) {
		if ( ! self::is_auto_record_enabled() || ! is_array( $translated_values ) || ! is_array( $source_values ) ) {
			return 0;
		}

		$recorded = 0;
		foreach ( $translated_values as $field => $target_text ) {
			if ( ! is_string( $target_text ) || '' === trim( $target_text ) ) {
				continue;
			}
			$source_text = (string) ( $source_values[ $field ] ?? '' );
			if ( '' === trim( $source_text ) ) {
				continue;
			}
			if ( self::text_length( $source_text ) > 1200 || self::text_length( $target_text ) > 1200 ) {
				continue;
			}
			if ( self::record( $source_text, $source_lang, $target_lang, $target_text, $domain, (string) $field ) ) {
				$recorded++;
			}
		}
		return $recorded;
	}

	/**
	 * Multibyte-safe length used by the pair caps.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function text_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
	}
}
