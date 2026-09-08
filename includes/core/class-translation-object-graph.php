<?php
/**
 * Translation object graph — unified lookup for post/term/media/menu/FSE.
 *
 * @package WPTSALL\Core
 * @since 2.0.1
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation_Object_Graph class.
 */
class Translation_Object_Graph {

	/**
	 * Resolve a translation object id for a language / VS.
	 *
	 * @param int    $source_id   Source object ID.
	 * @param string $object_type post|term|media|menu|template|navigation.
	 * @param string $lang        Target language / locale.
	 * @return int Target id or 0.
	 */
	public static function get_translation( $source_id, $object_type, $lang ) {
		$source_id   = (int) $source_id;
		$object_type = (string) $object_type;
		$lang        = (string) $lang;
		if ( $source_id <= 0 || '' === $lang ) {
			return 0;
		}
		if ( function_exists( 'wptsall_object_id' ) && in_array( $object_type, array( 'post', 'page', 'term', 'category', 'post_tag', 'attachment', 'media' ), true ) ) {
			$type = ( 'media' === $object_type ) ? 'attachment' : $object_type;
			$mapped = (int) wptsall_object_id( $source_id, $type, false, $lang );
			if ( $mapped > 0 ) {
				return $mapped;
			}
		}
		if ( 'menu' === $object_type && class_exists( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service' ) ) {
			$vs_id = self::vs_id_for_lang( $lang );
			if ( '' !== $vs_id ) {
				return \WPTSALL\MenuTranslation\Menu_Mapping_Service::get_target_menu( $source_id, $vs_id );
			}
		}
		return self::lookup_post_mapping( $source_id, $lang );
	}

	/**
	 * @param int    $target_id   Target id.
	 * @param string $object_type Type.
	 * @return int
	 */
	public static function get_source( $target_id, $object_type = 'post' ) {
		$target_id = (int) $target_id;
		if ( $target_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		if ( 'menu' === $object_type ) {
			$table = wptsall_table( 'menu_mappings' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT source_menu_term_id FROM %i WHERE target_menu_term_id = %d LIMIT 1',
					$table,
					$target_id
				)
			);
		}
		$table = wptsall_table( 'post_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT source_post_id FROM %i WHERE target_post_id = %d LIMIT 1',
				$table,
				$target_id
			)
		);
	}

	/**
	 * @param string $url     URL.
	 * @param string $context Optional.
	 * @return string
	 */
	public static function rewrite_url( $url, $context = '' ) {
		unset( $context );
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		if ( empty( $GLOBALS['wptsall_current_virtual_site'] ) || ! class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
			return $url;
		}
		$vs = $GLOBALS['wptsall_current_virtual_site'];
		return \WPTSALL\Sites\Services\Url_Converter::virtualize( $url, $vs );
	}

	/**
	 * @param int    $source_id Source post ID.
	 * @param string $lang      Lang.
	 * @return int
	 */
	private static function lookup_post_mapping( $source_id, $lang ) {
		global $wpdb;
		$pm = wptsall_table( 'post_mappings' );
		$sr = wptsall_table( 'site_relations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.target_post_id
				 FROM %i pm
				 INNER JOIN %i sr ON sr.id = pm.relation_id
				 WHERE pm.source_post_id = %d AND sr.target_lang = %s AND pm.target_post_id > 0
				 LIMIT 1",
				$pm,
				$sr,
				(int) $source_id,
				$lang
			)
		);
		return $tid;
	}

	/**
	 * @param string $lang Language.
	 * @return string VS id.
	 */
	private static function vs_id_for_lang( $lang ) {
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			return '';
		}
		$sites = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
		foreach ( (array) $sites as $site ) {
			if ( isset( $site['lang'] ) && 0 === strcasecmp( (string) $site['lang'], $lang ) ) {
				return (string) ( $site['id'] ?? '' );
			}
		}
		return '';
	}
}
