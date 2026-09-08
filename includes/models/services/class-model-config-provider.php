<?php
/**
 * Model Config Provider
 *
 * Unified API interface for other modules to query Models module data.
 * Eliminates hardcoded dependencies across modules.
 *
 * @package WPTSALL
 * @subpackage Models\Services
 * @since 0.9.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Model_Config_Provider
 *
 * Single Source of Truth (SSOT) API for plugin and field configuration.
 * All other modules should query this class instead of hardcoding.
 */
class Model_Config_Provider {

	/**
	 * Static cache for scan results
	 *
	 * @var array
	 */
	private static $scan_result_cache = array();

	// ==========================================
	// Plugin Metadata Queries
	// ==========================================

	/**
	 * Get plugin path
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return string|null Plugin file path, e.g., 'woocommerce/woocommerce.php'.
	 */
	public static function get_plugin_path( $plugin_slug ) {
		$mapping = Plugin_Mapping_Service::get_by_slug( $plugin_slug );
		return $mapping ? $mapping['plugin_file'] : null;
	}

	/**
	 * Check if plugin is a content plugin
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return bool
	 */
	public static function is_content_plugin( $plugin_slug ) {
		// wordpress-blog is a built-in virtual model.
		if ( 'wordpress-blog' === $plugin_slug ) {
			return true;
		}

		$mapping = Plugin_Mapping_Service::get_by_slug( $plugin_slug );
		return $mapping && ! empty( $mapping['is_content_plugin'] );
	}

	/**
	 * Check if model is virtual (no plugin activation needed)
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return bool
	 */
	public static function is_virtual_model( $plugin_slug ) {
		return 'wordpress-blog' === $plugin_slug;
	}

	// ==========================================
	// Cache Management
	// ==========================================

	/**
	 * Clear cache
	 *
	 * Call this after updating scan results.
	 *
	 * @param string|null $plugin_slug Optional plugin slug to clear specific cache.
	 */
	public static function clear_cache( $plugin_slug = null ) {
		if ( null === $plugin_slug ) {
			self::$scan_result_cache = array();
		} else {
			unset( self::$scan_result_cache[ $plugin_slug ] );
		}
	}
}
