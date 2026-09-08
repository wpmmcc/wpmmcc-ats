<?php
/**
 * User Translation Service (1.3.0)
 *
 * CRUD on `wp_wptsall_user_mappings` (source user <-> target user) and
 * translation of user meta fields (display_name, first_name, last_name,
 * description) via the String Translation service.
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\UserTranslation\Services;

use WPTSALL\Strings\Services\String_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class User_Translation_Service {

	const STRING_CONTEXT = 'user';

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wptsall_user_mappings';
	}

	private static function table_exists() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$e = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		return $e === $t;
	}

	/**
	 * Look up the target user id for a given source user id and target lang.
	 */
	public static function get_target_user_id( $source_user_id, $target_lang ) {
		if ( ! self::table_exists() || (int) $source_user_id <= 0 || '' === (string) $target_lang ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT target_user_id FROM %i
			 WHERE source_user_id = %d AND target_site_id = %s LIMIT 1',
			self::table(),
			(int) $source_user_id,
			(string) $target_lang
		) );
		return $id > 0 ? (int) $id : 0;
	}

	public static function record( $source_id, $target_id, $target_lang, $mapping_type = 'manual' ) {
		if ( ! self::table_exists() || (int) $source_id <= 0 || (int) $target_id <= 0 || '' === (string) $target_lang ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE source_user_id = %d AND target_site_id = %s',
			self::table(),
			(int) $source_id,
			(string) $target_lang
		) );
		$row = array(
			'source_site_id'   => 1,
			'target_site_id'   => (string) $target_lang,
			'source_user_id'   => (int) $source_id,
			'target_user_id'   => (int) $target_id,
			'mapping_type'     => (string) $mapping_type,
		);
		if ( $existing_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (bool) $wpdb->update( self::table(), $row, array( 'id' => $existing_id ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( self::table(), $row );
		return (bool) $ok;
	}

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
			$where[] = 'source_user_id = %d';
			$params[] = (int) $args['source_id'];
		}
		if ( '' !== $args['target_lang'] ) {
			$where[] = 'target_site_id = %s';
			$params[] = (string) $args['target_lang'];
		}
		$where_sql = implode( ' AND ', $where );
		$limit  = max( 1, (int) $args['limit'] );
		$offset = max( 0, (int) $args['offset'] );
		$sql    = 'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params = array_merge( array( self::table() ), $params, array( $limit, $offset ) );
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
		$src = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT source_user_id) FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$langs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT target_site_id) FROM %i', $table ) );
		return array( 'total' => $total, 'sources' => $src, 'langs' => $langs );
	}

	/**
	 * Frontend filter: swap user display_name / first_name / last_name / description
	 * when in a virtual-site request.
	 */
	public static function init() {
		add_filter( 'pre_user_display_name',  array( __CLASS__, 'filter_user_field' ), 10, 2 );
		add_filter( 'pre_user_first_name',    array( __CLASS__, 'filter_user_field' ), 10, 2 );
		add_filter( 'pre_user_last_name',     array( __CLASS__, 'filter_user_field' ), 10, 2 );
		add_filter( 'pre_user_description',   array( __CLASS__, 'filter_user_field' ), 10, 2 );

		// Standard context display filters (pass value, user_id, context).
		add_filter( 'user_display_name',      array( __CLASS__, 'filter_user_field' ), 10, 2 );
		add_filter( 'user_first_name',        array( __CLASS__, 'filter_user_field' ), 10, 2 );
		add_filter( 'user_last_name',         array( __CLASS__, 'filter_user_field' ), 10, 2 );
		add_filter( 'user_description',       array( __CLASS__, 'filter_user_field' ), 10, 2 );

		// Author template tags (pass value, user_id).
		add_filter( 'get_the_author_display_name', array( __CLASS__, 'filter_author_field' ), 10, 2 );
		add_filter( 'get_the_author_first_name',   array( __CLASS__, 'filter_author_field' ), 10, 2 );
		add_filter( 'get_the_author_last_name',    array( __CLASS__, 'filter_author_field' ), 10, 2 );
		add_filter( 'get_the_author_description',  array( __CLASS__, 'filter_author_field' ), 10, 2 );
	}

	public static function filter_user_field( $value, $user_id = 0 ) {
		if ( ! is_string( $value ) || '' === $value || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $value;
		}
		$lang = self::current_target_lang();
		if ( '' === $lang ) {
			return $value;
		}
		$cur_filter = current_filter();
		if ( 0 === strpos( $cur_filter, 'pre_user_' ) ) {
			$field = substr( $cur_filter, strlen( 'pre_user_' ) );
		} elseif ( 0 === strpos( $cur_filter, 'user_' ) ) {
			$field = substr( $cur_filter, strlen( 'user_' ) );
		} else {
			$field = $cur_filter;
		}
		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			global $authordata;
			if ( isset( $authordata->ID ) && (int) $authordata->ID > 0 ) {
				$uid = (int) $authordata->ID;
			}
		}
		$key = 'user_' . $uid . '_' . $field;
		$tr  = String_Translation_Service::translate( self::STRING_CONTEXT, $key, $value, $lang );
		if ( '' !== $tr && $tr !== $value ) {
			return $tr;
		}
		return $value;
	}

	public static function filter_author_field( $value, $user_id = 0 ) {
		if ( ! is_string( $value ) || '' === $value || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $value;
		}
		$lang = self::current_target_lang();
		if ( '' === $lang ) {
			return $value;
		}
		$cur_filter = current_filter();
		$field      = ( 0 === strpos( $cur_filter, 'get_the_author_' ) )
			? substr( $cur_filter, strlen( 'get_the_author_' ) )
			: $cur_filter;
		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			global $authordata;
			if ( isset( $authordata->ID ) && (int) $authordata->ID > 0 ) {
				$uid = (int) $authordata->ID;
			}
		}
		$key = 'user_' . $uid . '_' . $field;
		$tr  = String_Translation_Service::translate( self::STRING_CONTEXT, $key, $value, $lang );
		if ( '' !== $tr && $tr !== $value ) {
			return $tr;
		}
		return $value;
	}

	/**
	 * Register user fields on user_update / profile_update.
	 */
	public static function on_save_user( $user_id, $old_user_data = null ) {
		if ( ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return;
		}
		$fields = array( 'display_name', 'first_name', 'last_name', 'description' );
		foreach ( $fields as $f ) {
			$val = (string) get_user_meta( $user_id, $f, true );
			if ( '' !== $val ) {
				String_Translation_Service::register( self::STRING_CONTEXT, 'user_' . (int) $user_id . '_' . $f, $val );
			}
		}
	}

	private static function current_target_lang() {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::current_language();
		}
		return isset( $GLOBALS['wptsall_current_virtual_site']['lang'] )
			? (string) $GLOBALS['wptsall_current_virtual_site']['lang']
			: '';
	}
// phpcs:enable
}
