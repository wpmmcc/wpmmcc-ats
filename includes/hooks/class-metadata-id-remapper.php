<?php
/**
 * Runtime post-meta ID remapper (WPML TranslateIds pattern).
 *
 * On virtual-site front requests, rewrite declared id_mapping meta values
 * (thumbnail, ACF relationships, galleries, …) through wptsall_object_id().
 * Distinct from Custom_Field_Translation_Service (string swap).
 *
 * @package WPTSALL\Hooks
 * @since 2.2.0
 */

namespace WPTSALL\Hooks;

use WPTSALL\Models\Services\Id_Mapping_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metadata_Id_Remapper class.
 */
class Metadata_Id_Remapper {

	/**
	 * Recursion guard.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Cached meta_key => field config for this request.
	 *
	 * @var array<string,array>|null
	 */
	private static $field_map = null;

	/**
	 * Register filter (virtual-site front only at call time).
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 20, 4 );
	}

	/**
	 * @param mixed  $value     Current short-circuit value (null = load from DB).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key (empty = all keys — we skip).
	 * @param bool   $single    Single flag.
	 * @return mixed
	 */
	public static function filter_get_post_metadata( $value, $object_id, $meta_key, $single ) {
		if ( self::$busy ) {
			return $value;
		}
		if ( ! Virtual_Site_Router::get_current_virtual_site() ) {
			return $value;
		}
		$meta_key = (string) $meta_key;
		if ( '' === $meta_key ) {
			return $value;
		}

		$config = self::get_field_config( $meta_key );
		if ( ! $config ) {
			return $value;
		}

		/**
		 * Disable runtime ID remapping for a key / request.
		 *
		 * @since 2.2.0
		 * @param bool   $enable    Whether to remap.
		 * @param string $meta_key  Meta key.
		 * @param int    $object_id Post ID.
		 * @param array  $config    Field config.
		 */
		if ( ! apply_filters( 'wptsall_enable_metadata_id_remap', true, $meta_key, (int) $object_id, $config ) ) {
			return $value;
		}

		self::$busy = true;
		remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 20 );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- intentional raw read for remap.
		$raw = get_metadata( 'post', (int) $object_id, $meta_key, (bool) $single );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 20, 4 );
		self::$busy = false;

		$mapped = self::remap_payload( $raw, $meta_key, $config, (bool) $single );
		return $mapped;
	}

	/**
	 * Remap a get_metadata payload.
	 *
	 * @param mixed  $raw      Raw meta.
	 * @param string $meta_key Key.
	 * @param array  $config   Field config.
	 * @param bool   $single   Single flag.
	 * @return mixed
	 */
	public static function remap_payload( $raw, string $meta_key, array $config, bool $single ) {
		if ( null === $raw || false === $raw || '' === $raw ) {
			return $raw;
		}

		if ( $single ) {
			return self::remap_one_value( $raw, $meta_key, $config );
		}

		if ( ! is_array( $raw ) ) {
			return array( self::remap_one_value( $raw, $meta_key, $config ) );
		}

		$out = array();
		foreach ( $raw as $row ) {
			$out[] = self::remap_one_value( $row, $meta_key, $config );
		}
		return $out;
	}

	/**
	 * Remap a single stored meta value (int, CSV, or list of IDs).
	 *
	 * @param mixed  $value    Value.
	 * @param string $meta_key Key.
	 * @param array  $config   Config.
	 * @return mixed
	 */
	public static function remap_one_value( $value, string $meta_key, array $config ) {
		$element_type = self::element_type_for_config( $meta_key, $config );

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_numeric( $v ) ) {
					$out[ $k ] = self::map_id( (int) $v, $element_type );
				} else {
					$out[ $k ] = $v;
				}
			}
			return $out;
		}

		if ( is_string( $value ) && preg_match( '/^\s*\d+(\s*,\s*\d+)+\s*$/', $value ) ) {
			$parts = array_map( 'absint', explode( ',', $value ) );
			$mapped = array();
			foreach ( $parts as $id ) {
				$mapped[] = self::map_id( $id, $element_type );
			}
			return implode( ',', $mapped );
		}

		if ( is_numeric( $value ) ) {
			return self::map_id( (int) $value, $element_type );
		}

		return $value;
	}

	/**
	 * @param int    $id           Source or already-mapped ID.
	 * @param string $element_type Object_Id type.
	 * @return int
	 */
	private static function map_id( int $id, string $element_type ): int {
		if ( $id <= 0 ) {
			return $id;
		}
		if ( function_exists( 'wptsall_object_id' ) ) {
			return (int) wptsall_object_id( $id, $element_type, true, null );
		}
		return $id;
	}

	/**
	 * @param string $meta_key Key.
	 * @param array  $config   Config.
	 * @return string
	 */
	private static function element_type_for_config( string $meta_key, array $config ): string {
		$ref = (string) ( $config['reference_type'] ?? '' );
		if ( '' === $ref && class_exists( Id_Mapping_Resolver::class ) ) {
			$ref = (string) Id_Mapping_Resolver::infer_reference_type( $meta_key );
		}
		$target = (string) ( $config['reference_target'] ?? '' );

		switch ( $ref ) {
			case 'media':
				return 'attachment';
			case 'term':
				return '' !== $target ? $target : 'category';
			case 'user':
				// No language mapping for users in virtual sites — keep original.
				return 'any';
			case 'post':
			case 'generic':
			default:
				return '' !== $target && 'post' !== $target ? $target : 'any';
		}
	}

	/**
	 * @param string $meta_key Meta key.
	 * @return array|null
	 */
	private static function get_field_config( string $meta_key ) {
		$map = self::get_field_map();
		return isset( $map[ $meta_key ] ) ? $map[ $meta_key ] : null;
	}

	/**
	 * Build / cache id_mapping field map for this request.
	 *
	 * @return array<string,array>
	 */
	public static function get_field_map(): array {
		if ( null !== self::$field_map ) {
			return self::$field_map;
		}

		$map = array(
			'_thumbnail_id'          => array(
				'type'            => 'id_mapping',
				'reference_type'  => 'media',
				'reference_target'=> 'attachment',
			),
			'_product_image_gallery' => array(
				'type'            => 'id_mapping',
				'reference_type'  => 'media',
				'reference_target'=> 'attachment',
				'value_format'    => 'csv',
			),
			'_thumbnail_id_product'  => array(
				'type'           => 'id_mapping',
				'reference_type' => 'media',
			),
		);

		// wpml-config / wpm-config declarations.
		if ( class_exists( '\\WPTSALL\\Core\\WPML_Config_Reader' ) ) {
			$fields = \WPTSALL\Core\WPML_Config_Reader::get_all_custom_fields();
			foreach ( (array) $fields as $key => $action ) {
				$action = \WPTSALL\Core\WPML_Config_Reader::normalize_action( $action );
				if ( 'id_mapping' !== $action ) {
					continue;
				}
				$key = (string) $key;
				if ( '' === $key || isset( $map[ $key ] ) ) {
					continue;
				}
				$ref = class_exists( Id_Mapping_Resolver::class )
					? Id_Mapping_Resolver::infer_reference_type( $key )
					: 'generic';
				$map[ $key ] = array(
					'type'           => 'id_mapping',
					'reference_type' => $ref,
				);
			}
		}

		// Active model field_capabilities (id_mapping only).
		$map = array_merge( $map, self::load_model_id_mapping_fields() );

		/**
		 * Filters the runtime id_mapping meta key map.
		 *
		 * @since 2.2.0
		 * @param array $map meta_key => config.
		 */
		self::$field_map = apply_filters( 'wptsall_metadata_id_remap_fields', $map );
		return self::$field_map;
	}

	/**
	 * Reset request cache (tests).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$field_map = null;
	}

	/**
	 * @return array<string,array>
	 */
	private static function load_model_id_mapping_fields(): array {
		global $wpdb;
		$out = array();
		$table = $wpdb->prefix . 'wptsall_models';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return $out;
		}

		// Prefer field_capabilities JSON when present.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$col = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'field_capabilities' ) );
		if ( empty( $col ) ) {
			return $out;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT field_capabilities FROM %i WHERE status = %s LIMIT 50',
				$table,
				'active'
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$caps = json_decode( (string) ( $row['field_capabilities'] ?? '' ), true );
			if ( ! is_array( $caps ) ) {
				continue;
			}
			if ( class_exists( Id_Mapping_Resolver::class ) ) {
				$extracted = Id_Mapping_Resolver::extract_id_mapping_fields( $caps );
				foreach ( $extracted as $key => $cfg ) {
					$out[ (string) $key ] = is_array( $cfg ) ? $cfg : array( 'type' => 'id_mapping' );
				}
			}
		}
		return $out;
	}
}
