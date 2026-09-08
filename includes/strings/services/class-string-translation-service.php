<?php
/**
 * String Translation Service
 *
 * Manages translated strings (site title, widget text, nav menu labels, etc.).
 * Mirrors Polylang's `pll_register_string` / `pll_translate_string` pattern:
 * strings are registered with a context + key, then translation lookups happen
 * on the front-end via the `wptsall_translate_string` filter.
 *
 * Layer-B rows are intentionally source-site scoped, not relation-row scoped:
 * the table lives under the current WordPress site's prefix and one row stores
 * a translation map for all target languages. Relation authorization is still
 * enforced by the REST controller; the target language is the data scope and
 * the claim-owner digest binds the lease to device + relation + language.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Strings\Services;

use WPTSALL\Languages\Services\Language_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class String_Translation_Service {

	const TABLE_KEY = 'strings';

	private static function table() {
		return function_exists( 'wptsall_table' )
			? wptsall_table( self::TABLE_KEY )
			: $GLOBALS['wpdb']->prefix . 'wptsall_strings';
	}

	private static function table_exists() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$e = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		return $e === $t;
	}

	/**
	 * Register a string for translation.
	 *
	 * @param string $context     Logical group (e.g. 'site_title', 'widget_text', 'menu_label')
	 * @param string $key         Stable identifier (e.g. option name, widget id)
	 * @param string $text        Source text
	 * @param string $source_lang Source language code (BCP-47 / WP locale, e.g. zh_CN)
	 * @return int|false Row id (or false on failure / not migrated)
	 */
	public static function register( $context, $key, $text, $source_lang = '' ) {
		return self::register_with_object( $context, $key, $text, null, $source_lang );
	}

	/**
	 * Register a string with optional object_id.
	 *
	 * @param string   $context     Logical group.
	 * @param string   $key         Stable identifier.
	 * @param string   $text        Source text.
	 * @param int|null $object_id   Optional object id.
	 * @param string   $source_lang Source language.
	 * @return int|false Row id or false.
	 */
	public static function register_with_object( $context, $key, $text, $object_id = null, $source_lang = '' ) {
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		if ( '' === $source_lang ) {
			$default = Language_Service::get_default();
			$source_lang = $default ? (string) $default['code'] : 'zh_CN';
		}
		if ( null === $object_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- registration dedupe read; caching is not applicable during writes.
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM %i WHERE context = %s AND object_id IS NULL AND string_key = %s AND source_lang = %s',
				self::table(),
				$context,
				$key,
				$source_lang
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- registration dedupe read; caching is not applicable during writes.
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM %i WHERE context = %s AND object_id = %d AND string_key = %s AND source_lang = %s',
				self::table(),
				$context,
				(int) $object_id,
				$key,
				$source_lang
			) );
		}
		$now = current_time( 'mysql', true );
		$row = array(
			'context'     => (string) $context,
			'object_id'   => null === $object_id ? null : (int) $object_id,
			'string_key'  => (string) $key,
			'source_text' => (string) $text,
			'source_lang' => (string) $source_lang,
			'updated_at'  => $now,
		);
		if ( $existing_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( self::table(), $row, array( 'id' => $existing_id ) );
			return $existing_id;
		}
		$row['created_at'] = $now;
		$row['status']     = 'pending';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Save translations for a registered string.
	 *
	 * @param int    $id
	 * @param array  $translations Associative array lang_code => translated_text
	 * @return bool
	 */
	public static function set_translations( $id, $translations ) {
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 || ! is_array( $translations ) ) {
			return false;
		}
		$clean = array();
		foreach ( $translations as $code => $txt ) {
			$clean[ (string) $code ] = (string) $txt;
		}
		$has_any = false;
		foreach ( $clean as $v ) {
			if ( '' !== $v ) { $has_any = true; break; }
		}
		$status = $has_any ? 'translated' : 'pending';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = (bool) $wpdb->update(
			self::table(),
			array(
				'translations' => wp_json_encode( $clean ),
				'status'       => $status,
			),
			array( 'id' => $id )
		);
		if ( $ok ) {
			// Audit log hook (P6-3).
			foreach ( $clean as $lang => $text ) {
				if ( '' !== $text ) {
					do_action( 'wptsall_string_translated', $id, $lang, $text );
				}
			}
		}
		return $ok;
	}

	/**
	 * List strings, optionally filtered.
	 */
	public static function list( $args = array() ) {
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$defaults = array( 'status' => 'all', 'context' => '', 'search' => '', 'limit' => 200, 'offset' => 0 );
		$args = array_merge( $defaults, $args );

		$where = array( '1=1' );
		$params = array();
		if ( 'all' !== $args['status'] ) {
			$where[] = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( '' !== $args['context'] ) {
			$where[] = 'context = %s';
			$params[] = (string) $args['context'];
		}
		if ( '' !== $args['search'] ) {
			$where[] = '(source_text LIKE %s OR string_key LIKE %s)';
			$like = '%' . $GLOBALS['wpdb']->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like; $params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$limit  = max( 1, (int) $args['limit'] );
		$offset = max( 0, (int) $args['offset'] );
		// $where_sql is fixed fragments with placeholders only; limit/offset are ints via prepare.
		$sql    = 'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY context, id LIMIT %d OFFSET %d';
		$params = array_merge( array( self::table() ), $params, array( $limit, $offset ) );
		$rows   = wptsall_db_get_results( $sql, $params, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get a single string row.
	 */
	public static function get( $id ) {
		if ( ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Translate a registered string by context+key for the current language.
	 *
	 * @param string $context
	 * @param string $key
	 * @param string $fallback
	 * @param string|null $lang_code Override current language.
	 * @return string
	 */
	public static function translate( $context, $key, $fallback = '', $lang_code = null ) {
		if ( ! self::table_exists() ) {
			return $fallback;
		}
		if ( null === $lang_code ) {
			$default = Language_Service::get_default();
			$lang_code = $default ? (string) $default['code'] : 'zh_CN';
		}
		global $wpdb;
		// Menu/widget scanners register with object_id; lookup must not require NULL.
		// Duplicates can exist when source_lang differs across register() calls — prefer
		// a row that already has the requested language translation over a newer empty one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT source_text, translations FROM %i WHERE context = %s AND string_key = %s ORDER BY updated_at DESC, id DESC LIMIT 20',
			self::table(),
			$context,
			$key
		), ARRAY_A );
		if ( empty( $rows ) ) {
			return $fallback;
		}
		$lang_code = (string) $lang_code;
		$best_empty = null;
		foreach ( (array) $rows as $row ) {
			$translations = json_decode( (string) ( $row['translations'] ?? '' ), true );
			if ( is_array( $translations ) && ! empty( $translations[ $lang_code ] ) ) {
				return (string) $translations[ $lang_code ];
			}
			if ( null === $best_empty ) {
				$best_empty = $row;
			}
		}
		$row = $best_empty;
		return $fallback !== '' ? $fallback : (string) ( $row['source_text'] ?? '' );
	}

	/**
	 * Count strings by status (for the Strings admin page header).
	 *
	 * @return array
	 */
	public static function counts() {
		if ( ! self::table_exists() ) {
			return array( 'total' => 0, 'translated' => 0, 'pending' => 0 );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) as cnt FROM %i GROUP BY status',
				self::table()
			),
			ARRAY_A
		);
		$out = array( 'total' => 0, 'translated' => 0, 'pending' => 0, 'inherited' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['cnt'];
			$out['total'] += (int) $r['cnt'];
		}
		return $out;
	}

	/**
	 * Delete a string by id.
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
	 * Map Layer B business_line subtype to string contexts.
	 *
	 * @param string $subtype site|menu|widget
	 * @return string[]
	 */
	public static function contexts_for_subtype( $subtype ) {
		$subtype = sanitize_key( (string) $subtype );
		switch ( $subtype ) {
			case 'site':
				return array( 'site_title', 'site_tagline' );
			case 'menu':
				return array( 'menu' );
			case 'widget':
				return array( 'widget' );
			default:
				return array();
		}
	}

	/**
	 * List untranslated strings for client pull (Layer B).
	 *
	 * @param string $target_lang Target language code.
	 * @param string $subtype     site|menu|widget
	 * @param int    $page        Page.
	 * @param int    $per_page    Per page.
	 * @param array  $include_ids Optional id filter.
	 *
	 * Rows are shared by active relations on this source site by design. The
	 * caller must validate the relation's source site before invoking this
	 * method; this service applies the target-language and context scope.
	 * @return array{items:array,total:int}
	 */
	public static function list_untranslated_for_client( $target_lang, $subtype, $page = 1, $per_page = 50, $include_ids = array() ) {
		if ( ! self::table_exists() ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		global $wpdb;
		$contexts = self::contexts_for_subtype( $subtype );
		if ( empty( $contexts ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$claim_timeout = function_exists( 'wptsall_get_client_claim_timeout_seconds' )
			? wptsall_get_client_claim_timeout_seconds()
			: 1800;
		$claim_timeout = gmdate( 'Y-m-d H:i:s', time() - $claim_timeout );
		list( $in_ctx, $ctx_args ) = wptsall_db_prepare_string_in( $contexts );
		$where  = "context IN ($in_ctx) AND (claimed_at IS NULL OR claimed_at < %s)";
		$params = array_merge( array( self::table() ), $ctx_args, array( $claim_timeout ) );

		if ( ! empty( $include_ids ) ) {
			list( $in_ids, $id_args ) = wptsall_db_prepare_int_in( $include_ids );
			$where                   .= " AND id IN ($in_ids)";
			$params                   = array_merge( $params, $id_args );
		}

		$rows = wptsall_db_get_results(
			'SELECT id, context, string_key, source_text, source_lang, translations, status FROM %i WHERE ' . $where . ' ORDER BY id ASC',
			$params,
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$translations = json_decode( (string) ( $row['translations'] ?? '' ), true );
			if ( ! is_array( $translations ) ) {
				$translations = array();
			}
			$has_target = isset( $translations[ $target_lang ] ) && '' !== trim( (string) $translations[ $target_lang ] );
			if ( $has_target ) {
				continue;
			}
			$items[] = array(
				'object_type'   => 'site_string',
				'subtype'       => $subtype,
				'object_id'     => (int) $row['id'],
				'complete_data' => array(
					'string_id'   => (int) $row['id'],
					'entry_id'    => (int) $row['id'],
					'msgid'       => $row['source_text'],
					'context'     => $row['context'],
					'string_key'  => $row['string_key'],
					'source_text' => $row['source_text'],
					'source_lang' => $row['source_lang'],
					'text_domain' => $row['context'],
				),
			);
		}
		$total  = count( $items );
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * max( 1, (int) $per_page );
		$items  = array_slice( $items, $offset, max( 1, (int) $per_page ) );
		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Claim strings for client processing.
	 *
	 * @param array $string_ids String row ids.
	 * @return int Claimed count.
	 */
	public static function claim_strings( $string_ids ) {
		return count( self::claim_string_ids( $string_ids ) );
	}

	/**
	 * Claim only pending, currently unclaimed string rows and return the rows
	 * actually acquired by this caller. The previous bulk count-only API could
	 * make a client treat an already claimed row as owned by it.
	 *
	 * @param int[]    $string_ids       String row ids.
	 * @param string[] $contexts         Optional context allowlist.
	 * @param string   $claim_owner_hash Device/relation/language owner digest.
	 * @param string   $target_lang      Target language whose translation must be missing.
	 * @return int[] IDs whose lease was acquired.
	 */
	public static function claim_string_ids( $string_ids, $contexts = array(), $claim_owner_hash = '', $target_lang = '' ) {
		if ( ! self::table_exists() || empty( $string_ids ) ) {
			return array();
		}
		global $wpdb;
		$ids = array();
		foreach ( (array) $string_ids as $id_candidate ) {
			if ( is_numeric( $id_candidate ) && (int) $id_candidate > 0 ) {
				$ids[] = (int) $id_candidate;
			}
		}
		$ids              = array_values( array_unique( $ids ) );
		$contexts         = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $contexts ) ) ) );
		$claim_owner_hash = strtolower( trim( (string) $claim_owner_hash ) );
		$target_lang      = sanitize_text_field( (string) $target_lang );
		if ( '' !== $claim_owner_hash && ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return array();
		}
		$now           = current_time( 'mysql', true );
		$claim_timeout = function_exists( 'wptsall_get_client_claim_timeout_seconds' )
			? wptsall_get_client_claim_timeout_seconds()
			: 1800;
		$claim_cutoff = gmdate( 'Y-m-d H:i:s', time() - $claim_timeout );
		$claimed_ids  = array();

		foreach ( $ids as $id ) {
			// A valid relation may submit arbitrary ids in claim input, so enforce
			// the target-language missing check here as well as in discovery. The
			// updated_at comparison closes the select/update race with manual edits.
			$row = self::get( $id );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( '' !== $target_lang ) {
				$existing_translations = json_decode( (string) ( $row['translations'] ?? '' ), true );
				if ( is_array( $existing_translations )
					&& isset( $existing_translations[ $target_lang ] )
					&& '' !== trim( (string) $existing_translations[ $target_lang ] ) ) {
					continue;
				}
			}
			$where  = 'id = %d AND status = %s AND (claimed_at IS NULL OR claimed_at < %s) AND updated_at = %s';
			$params = array( self::table(), $id, 'pending', $claim_cutoff, (string) ( $row['updated_at'] ?? '' ) );
			if ( ! empty( $contexts ) ) {
				list( $in_sql, $in_args ) = wptsall_db_prepare_string_in( $contexts );
				$where                  .= " AND context IN ($in_sql)";
				$params                  = array_merge( $params, $in_args );
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- claim CAS write; caching is not applicable to a compare-and-set.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is composed of literal placeholders only; the context IN-list placeholders come from wptsall_db_prepare_string_in(); every value binds through the merged param list.
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET claimed_at = %s, claim_owner_hash = %s, updated_at = %s WHERE ' . $where,
					array_merge( array( self::table(), $now, $claim_owner_hash ?: null, $now ), array_slice( $params, 1 ) )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			if ( false !== $updated && $updated > 0 ) {
				$claimed_ids[] = $id;
			}
		}

		return $claimed_ids;
	}

	/**
	 * Apply client translation callback entries.
	 *
	 * @param string   $target_lang Target language.
	 * @param array    $entries     [{string_id, msgstr}, ...]
	 * @param string[] $contexts    Optional context allowlist.
	 * @param bool     $require_claim Require a current client claim.
	 * @return int Updated count.
	 */
	public static function apply_client_translations( $target_lang, $entries, $contexts = array(), $require_claim = false, $claim_owner_hash = '' ) {
		if ( ! self::table_exists() || '' === $target_lang || ! is_array( $entries ) ) {
			return 0;
		}
		$updated      = 0;
		$contexts     = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $contexts ) ) ) );
		$claim_timeout = function_exists( 'wptsall_get_client_claim_timeout_seconds' )
			? wptsall_get_client_claim_timeout_seconds()
			: 1800;
		$claim_cutoff = gmdate( 'Y-m-d H:i:s', time() - $claim_timeout );
		$claim_owner_hash = strtolower( trim( (string) $claim_owner_hash ) );
		if ( $require_claim && ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return 0;
		}
		global $wpdb;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id     = (int) ( $entry['string_id'] ?? $entry['entry_id'] ?? 0 );
			$msgstr = sanitize_text_field( (string) ( $entry['msgstr'] ?? '' ) );
			if ( $id <= 0 || '' === $msgstr ) {
				continue;
			}
			$row = self::get( $id );
			$claim_is_valid = ! $require_claim
				|| ( is_array( $row )
					&& '' !== (string) ( $row['claimed_at'] ?? '' )
					&& (string) $row['claimed_at'] >= $claim_cutoff
					&& hash_equals( $claim_owner_hash, strtolower( trim( (string) ( $row['claim_owner_hash'] ?? '' ) ) ) ) );
			if ( ! is_array( $row )
				|| ( ! empty( $contexts ) && ! in_array( sanitize_key( (string) ( $row['context'] ?? '' ) ), $contexts, true ) )
				|| ! $claim_is_valid ) {
				continue;
			}
			$translations = json_decode( (string) ( $row['translations'] ?? '' ), true );
			if ( ! is_array( $translations ) ) {
				$translations = array();
			}
			$translations[ $target_lang ] = $msgstr;
			// Keep the write conditional on the exact row version observed above.
			// A manual edit or another callback between SELECT and UPDATE must not
			// be overwritten by a stale client response.
			$where         = array( 'id' => $id, 'updated_at' => (string) ( $row['updated_at'] ?? '' ) );
			$where_formats = array( '%d', '%s' );
			if ( $require_claim ) {
				$where['claimed_at']       = (string) $row['claimed_at'];
				$where['claim_owner_hash'] = $claim_owner_hash;
				$where_formats             = array( '%d', '%s', '%s', '%s' );
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional write guarded on the observed row version; caching is not applicable.
			$written = $wpdb->update(
				self::table(),
				array(
					'translations'      => wp_json_encode( $translations ),
					'status'            => 'translated',
					'claimed_at'        => null,
					'claim_owner_hash'  => null,
					'updated_at'        => current_time( 'mysql', true ),
				),
				$where,
				array( '%s', '%s', '%s', '%s', '%s' ),
				$where_formats
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $written && $written > 0 ) {
				do_action( 'wptsall_string_translated', $id, $target_lang, $msgstr );
				++$updated;
			}
		}
		return $updated;
	}

	/**
	 * Progress summary for dashboard (Layer B).
	 *
	 * @param string $target_lang Optional lang to count missing translations.
	 * @return array{total:int,translated:int,pending:int,by_context:array}
	 */
	public static function progress_summary( $target_lang = '' ) {
		$counts = self::counts();
		global $wpdb;
		$by_context = array();
		if ( self::table_exists() ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dashboard aggregate; one GROUP BY over a bounded strings table, freshness preferred over cache.
			$ctx_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT context, status, COUNT(*) AS cnt FROM %i GROUP BY context, status',
					self::table()
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( (array) $ctx_rows as $r ) {
				$ctx = (string) $r['context'];
				if ( ! isset( $by_context[ $ctx ] ) ) {
					$by_context[ $ctx ] = array( 'total' => 0, 'translated' => 0, 'pending' => 0 );
				}
				$by_context[ $ctx ]['total'] += (int) $r['cnt'];
				$st = (string) $r['status'];
				if ( isset( $by_context[ $ctx ][ $st ] ) ) {
					$by_context[ $ctx ][ $st ] += (int) $r['cnt'];
				}
			}
		}
		return array_merge( $counts, array( 'by_context' => $by_context ) );
	}

	/**
	 * Known context labels for admin filters.
	 *
	 * @return array<string,string>
	 */
	public static function context_labels() {
		return array(
			'site_title'   => __( 'Site title', 'wpmmcc-ats' ),
			'site_tagline' => __( 'Site tagline', 'wpmmcc-ats' ),
			'menu'         => __( 'Navigation menus', 'wpmmcc-ats' ),
			'widget'       => __( 'Widgets', 'wpmmcc-ats' ),
			'cpt_field'    => __( 'Custom fields', 'wpmmcc-ats' ),
		);
	}
}
