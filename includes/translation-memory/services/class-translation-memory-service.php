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
		global $wpdb;
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

		$table  = self::table();
		$params = array( $table );
		$where  = array( '1=1' );

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

		$where_sql = implode( ' AND ', $where );
		$params[]  = $limit;
		$params[]  = $offset;

		$rows = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY occurrences DESC, id DESC LIMIT %d OFFSET %d',
			$params,
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
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
}
