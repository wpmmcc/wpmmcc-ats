<?php
/**
 * Field Translation Service (P5-6)
 *
 * UI-friendly wrapper over Field_Discovery_Service for the field discovery
 * admin page. Adds "is_translatable" flag persisted on the model.
 *
 * Persists "translatable" decisions via update_option() keyed by post_type.
 *
 * @package WPTSALL\ManualTranslation
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Field_Translation_Service {

	const OPTION_KEY = 'wptsall_field_translations';

	/**
	 * Get all configured field translations (post_type → meta_key → [translatable, sync_to_target]).
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, array<string, array{translatable:bool, sync:bool}>>
	 */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Get configured meta keys for a post type.
	 *
	 * @since 1.4.0
	 *
	 * @param string $post_type Post type slug.
	 * @return array<string, array{translatable:bool, sync:bool}>
	 */
	public static function get_for_post_type( string $post_type ): array {
		$all = self::get_all();
		return $all[ $post_type ] ?? array();
	}

	/**
	 * Save the field translation config for a post type.
	 *
	 * @since 1.4.0
	 *
	 * @param string $post_type Post type slug.
	 * @param array<string, array{translatable:bool, sync:bool}> $fields Map of meta_key => config.
	 * @return bool
	 */
	public static function save_for_post_type( string $post_type, array $fields ): bool {
		$all   = self::get_all();
		$all[ $post_type ] = $fields;
		return update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Toggle a single meta key's translatable flag.
	 *
	 * @since 1.4.0
	 *
	 * @param string $post_type Post type slug.
	 * @param string $meta_key  Meta key.
	 * @param bool   $translatable New flag.
	 * @return bool
	 */
	public static function set_translatable( string $post_type, string $meta_key, bool $translatable ): bool {
		if ( $translatable && self::is_code_like_key( $meta_key ) ) {
			return false;
		}
		$current = self::get_for_post_type( $post_type );
		$current[ $meta_key ] = array(
			'translatable' => $translatable,
			'sync'         => $current[ $meta_key ]['sync'] ?? false,
		);
		return self::save_for_post_type( $post_type, $current );
	}

	/**
	 * Whether a meta key looks like code/CSS/JS storage.
	 *
	 * @since 1.8.0
	 */
	public static function is_code_like_key( string $meta_key ): bool {
		if ( class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
			return \WPTSALL\Core\Smart_Field_Classifier::is_code_like_field_name( $meta_key );
		}
		return (bool) preg_match(
			'/(^|_)(css|scss|less|js|javascript|script|code|snippet|regex|template|shortcode|html|head|body|style)$/i',
			$meta_key
		);
	}

	/**
	 * Get list of distinct meta keys with sample value for a post type.
	 * Wraps Field_Discovery_Service::get_post_meta_keys() and adds a sample value column.
	 *
	 * @since 1.4.0
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $limit     Max keys to return.
	 * @return array<int, array{key:string, count:int, sample:string, translatable:bool}>
	 */
	public static function discover_for_post_type( string $post_type, int $limit = 100 ): array {
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Field_Discovery_Service' ) ) {
			return array();
		}
		$keys = \WPTSALL\Models\Services\Field_Discovery_Service::get_post_meta_keys( $post_type, true );
		$keys = array_slice( $keys, 0, $limit );
		$configured = self::get_for_post_type( $post_type );
		$out = array();
		foreach ( $keys as $k ) {
			$sample = self::get_sample_meta_value( $post_type, $k );
			$count  = self::count_meta_key( $post_type, $k );
			$out[]  = array(
				'key'          => (string) $k,
				'count'        => $count,
				'sample'       => $sample,
				'translatable' => (bool) ( $configured[ $k ]['translatable'] ?? false ),
				'blocked'      => self::is_code_like_key( (string) $k ),
			);
		}
		return $out;
	}

	/**
	 * Get a sample meta value for a given key.
	 *
	 * @since 1.4.0
	 *
	 * @param string $post_type Post type slug.
	 * @param string $meta_key  Meta key.
	 * @return string
	 */
	private static function get_sample_meta_value( string $post_type, string $meta_key ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.meta_value FROM %i pm
			 INNER JOIN %i p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND p.post_type = %s AND pm.meta_value <> ''
			 ORDER BY pm.meta_id DESC LIMIT 1",
			$wpdb->postmeta,
			$wpdb->posts,
			$meta_key,
			$post_type
		) );
		if ( ! $val ) {
			return '';
		}
		$val = (string) $val;
		if ( mb_strlen( $val ) > 60 ) {
			return mb_substr( $val, 0, 60 ) . '…';
		}
		return $val;
	}

	/**
	 * Count distinct posts with a given meta key.
	 *
	 * @since 1.4.0
	 */
	private static function count_meta_key( string $post_type, string $meta_key ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(DISTINCT pm.post_id) FROM %i pm
			 INNER JOIN %i p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND p.post_type = %s',
			$wpdb->postmeta,
			$wpdb->posts,
			$meta_key,
			$post_type
		) );
	}
}
