<?php
/**
 * Language Service
 *
 * Language Service - handles CRUD operations for languages.
 * Decouples language concept from site_relations.target_lang.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Languages\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Language_Service class.
 *
 * Database Schema (wp_wptsall_languages):
 * - id, code, slug, name, native_name, locale, flag, direction,
 *   sort_order, is_default, status, created_at, updated_at
 */
class Language_Service {
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

	/**
	 * Check if the languages table exists.
	 */
	private static function table_exists() {
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'languages' ) : $wpdb->prefix . 'wptsall_languages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $exists === $table;
	}

	/**
	 * Get all languages, optionally filtered.
	 *
	 * @param array $args { status, is_default, orderby, order }
	 * @return array
	 */
	public static function get_all( $args = array() ) {
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_languages';

		$defaults = array(
			'status'   => 'active',
			'orderby'  => 'sort_order',
			'order'    => 'ASC',
			'is_default' => null,
		);
		$args = array_merge( $defaults, $args );

		$clauses = array( '1=1' );
		$params  = array();
		if ( null !== $args['is_default'] ) {
			$clauses[] = 'is_default = %d';
			$params[]  = (int) $args['is_default'];
		}
		if ( 'all' !== $args['status'] ) {
			$clauses[] = 'status = %s';
			$params[]  = (string) $args['status'];
		}
		$where   = implode( ' AND ', $clauses );
		$allowed = array( 'sort_order', 'code', 'name', 'id', 'created_at' );
		$orderby = in_array( sanitize_key( (string) $args['orderby'] ), $allowed, true )
			? sanitize_key( (string) $args['orderby'] )
			: 'sort_order';
		$order   = ( 'DESC' === strtoupper( (string) $args['order'] ) ) ? 'DESC' : 'ASC';

		$sql    = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY ' . $orderby . ' ' . $order;
		$params = array_merge( array( $table ), $params );
		$rows   = wptsall_db_get_results( $sql, $params, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get one language by code (e.g. en_US).
	 *
	 * @param string $code
	 * @return array|null
	 */
	public static function get_by_code( $code ) {
		if ( '' === (string) $code || ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_languages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE code = %s', $table, $code ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Get default language row.
	 *
	 * @return array|null
	 */
	public static function get_default() {
		$all = self::get_all( array( 'is_default' => 1 ) );
		if ( ! empty( $all ) ) {
			return $all[0];
		}
		// Fallback: first active.
		$all = self::get_all();
		return ! empty( $all ) ? $all[0] : null;
	}

	/**
	 * Insert or update a language row.
	 *
	 * @param array $data
	 * @return int|false Language id (or false on failure).
	 */
	public static function upsert( $data ) {
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'languages' ) : $wpdb->prefix . 'wptsall_languages';

		$code = sanitize_text_field( (string) ( $data['code'] ?? '' ) );
		$slug = sanitize_title( (string) ( $data['slug'] ?? strtolower( str_replace( '_', '-', $code ) ) ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( strtolower( str_replace( '_', '-', $code ) ) );
		}
		$row = array(
			'code'        => $code,
			'slug'        => $slug,
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'native_name' => sanitize_text_field( (string) ( $data['native_name'] ?? '' ) ),
			'locale'      => sanitize_text_field( (string) ( $data['locale'] ?? ( $data['code'] ?? '' ) ) ),
			'flag'        => sanitize_file_name( (string) ( $data['flag'] ?? '' ) ),
			'direction'   => 'rtl' === ( $data['direction'] ?? '' ) ? 'rtl' : 'ltr',
			'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
			'is_default'  => ! empty( $data['is_default'] ) ? 1 : 0,
			'status'      => in_array( $data['status'] ?? 'active', array( 'active', 'inactive' ), true ) ? $data['status'] : 'active',
			'updated_at'  => current_time( 'mysql' ),
		);
		if ( '' === $row['code'] || '' === $row['name'] ) {
			return false;
		}
		if ( '' === $row['native_name'] ) {
			$row['native_name'] = $row['name'];
		}
		if ( '' === $row['locale'] ) {
			$row['locale'] = $row['code'];
		}

		$target_id = ! empty( $data['id'] ) ? (int) $data['id'] : 0;
		if ( $target_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$target_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE code = %s LIMIT 1', $table, $row['code'] ) );
		}

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
		if ( $target_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->update( $table, $row, array( 'id' => $target_id ), $formats, array( '%d' ) );
			if ( false === $ok ) {
				return false;
			}
			if ( ! empty( $row['is_default'] ) ) {
				self::set_default( $target_id );
			}
			return $target_id;
		}

		$row['created_at'] = $row['updated_at'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $table, $row, array_merge( $formats, array( '%s' ) ) );
		if ( ! $ok ) {
			return false;
		}
		$insert_id = (int) $wpdb->insert_id;
		if ( ! empty( $row['is_default'] ) ) {
			self::set_default( $insert_id );
		}
		return $insert_id;
	}

	/**
	 * Set the default language (atomic — clears others first).
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function set_default( $id ) {
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'languages' ) : $wpdb->prefix . 'wptsall_languages';
		$id    = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d LIMIT 1', $table, $id ) );
		if ( $exists <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$clear = $wpdb->update( $table, array( 'is_default' => 0 ), array( 'is_default' => 1 ), array( '%d' ), array( '%d' ) );
		if ( false === $clear ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->update( $table, array( 'is_default' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		return false !== $ok;
	}

	/**
	 * Delete a language by id (refuses if default or referenced).
	 *
	 * @param int $id
	 * @return true|\WP_Error
	 */
	public static function delete( $id ) {
		if ( ! self::table_exists() ) {
			return new \WP_Error( 'no_table', 'Languages table missing' );
		}
		global $wpdb;
		$id  = (int) $id;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'wptsall_languages', $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Language not found' );
		}
		if ( ! empty( $row['is_default'] ) ) {
			return new \WP_Error( 'is_default', 'Cannot delete the default language' );
		}
		// Soft delete by marking inactive; hard delete only if no relations reference it.
		$relations = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE target_lang = %s OR source_lang = %s', $wpdb->prefix . 'wptsall_site_relations', $row['code'], $row['code'] ) );
		if ( (int) $relations > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $wpdb->prefix . 'wptsall_languages', array( 'status' => 'inactive' ), array( 'id' => $id ) );
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $wpdb->prefix . 'wptsall_languages', array( 'id' => $id ) );
		return true;
	}

	/**
	 * Build a code -> row map for fast lookups.
	 *
	 * @return array<string,array>
	 */
	public static function code_map() {
		$map = array();
		foreach ( self::get_all( array( 'status' => 'all' ) ) as $row ) {
			$map[ (string) $row['code'] ] = $row;
		}
		return $map;
	}
// phpcs:enable
}
