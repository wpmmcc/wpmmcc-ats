<?php
/**
 * WPTSALL Runtime Tracker
 *
 * Tracks post_type and taxonomy registrations at runtime using WordPress hooks.
 * This provides accurate mapping between content types and their source plugins.
 *
 * @package WPTSALL
 * @since 0.3.2
 */

namespace WPTSALL\Models\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime Tracker Class
 *
 * Uses WordPress action hooks to track when post_types and taxonomies
 * are registered, capturing the source plugin via debug_backtrace.
 */
class Runtime_Tracker {

	/**
	 * Tracked post_type registrations
	 *
	 * @var array Format: [ post_type => [ 'plugin_slug' => string, 'file' => string, 'function' => string ] ]
	 */
	protected static $post_type_sources = array();

	/**
	 * Tracked taxonomy registrations
	 *
	 * @var array Format: [ taxonomy => [ 'plugin_slug' => string, 'file' => string, 'function' => string ] ]
	 */
	protected static $taxonomy_sources = array();

	/**
	 * Whether tracking is active
	 *
	 * @var bool
	 */
	protected static $is_tracking = false;

	/**
	 * Cache key for stored tracking data
	 *
	 * @var string
	 */
	const CACHE_KEY = 'wptsall_runtime_tracker_data';

	/**
	 * WordPress core post types to exclude
	 *
	 * @var array
	 */
	protected static $core_post_types = array(
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	/**
	 * WordPress core taxonomies to exclude
	 *
	 * @var array
	 */
	protected static $core_taxonomies = array(
		'category',
		'post_tag',
		'nav_menu',
		'link_category',
		'post_format',
		'wp_theme',
		'wp_template_part_area',
		'wp_pattern_category',
	);

	/**
	 * Initialize tracking
	 *
	 * Should be called early in WordPress load, ideally in plugins_loaded or earlier.
	 */
	public static function init() {
		if ( self::$is_tracking ) {
			return;
		}

		// Hook into post_type registration
		add_action( 'registered_post_type', array( __CLASS__, 'on_post_type_registered' ), 10, 2 );

		// Hook into taxonomy registration
		add_action( 'registered_taxonomy', array( __CLASS__, 'on_taxonomy_registered' ), 10, 3 );

		self::$is_tracking = true;
	}

	/**
	 * Handle post_type registration
	 *
	 * @param string       $post_type Post type name.
	 * @param \WP_Post_Type $post_type_object Post type object.
	 */
	public static function on_post_type_registered( $post_type, $post_type_object ) {
		// Skip core post types
		if ( in_array( $post_type, self::$core_post_types, true ) ) {
			return;
		}

		// Skip if not public
		if ( ! $post_type_object->public ) {
			return;
		}

		// Trace the registration source
		$source = self::trace_registration_source();

		if ( $source ) {
			self::$post_type_sources[ $post_type ] = $source;

			wptsall_log_debug(
				'models-tracker',
				'Post type registered',
				array(
					'post_type'   => $post_type,
					'plugin_slug' => $source['plugin_slug'],
					'file'        => $source['file'] ?? '',
					'type'        => $source['type'] ?? 'plugin',
				)
			);
		}
	}

	/**
	 * Handle taxonomy registration
	 *
	 * @param string        $taxonomy       Taxonomy name.
	 * @param array|string  $object_type    Object types for the taxonomy.
	 * @param array         $args           Taxonomy arguments.
	 */
	public static function on_taxonomy_registered( $taxonomy, $object_type, $args ) {
		// Skip core taxonomies
		if ( in_array( $taxonomy, self::$core_taxonomies, true ) ) {
			return;
		}

		// Skip if not public (check args array)
		if ( isset( $args['public'] ) && ! $args['public'] ) {
			return;
		}

		// Trace the registration source
		$source = self::trace_registration_source();

		if ( $source ) {
			self::$taxonomy_sources[ $taxonomy ] = $source;

			wptsall_log_debug(
				'models-tracker',
				'Taxonomy registered',
				array(
					'taxonomy'    => $taxonomy,
					'plugin_slug' => $source['plugin_slug'],
					'file'        => $source['file'] ?? '',
					'type'        => $source['type'] ?? 'plugin',
				)
			);
		}
	}

	/**
	 * Trace the source of a registration call
	 *
	 * Uses debug_backtrace to find which plugin file triggered the registration.
	 * Skips wptsall plugin frames to find the actual source plugin.
	 *
	 * @return array|null Source info or null if not found.
	 */
	protected static function trace_registration_source() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 );

		$plugin_dir    = wp_normalize_path( wptsall_get_plugins_dir() );
		$mu_plugin_dir = defined( 'WPMU_PLUGIN_DIR' ) ? wp_normalize_path( WPMU_PLUGIN_DIR ) : '';
		$theme_dir     = wp_normalize_path( get_theme_root() );

		// Plugins to skip (self)
		$skip_plugins = array( 'wpmmcc-ats' );

		foreach ( $backtrace as $frame ) {
			if ( ! isset( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			// Check if file is in plugins directory
			if ( strpos( $file, $plugin_dir ) === 0 ) {
				$relative = substr( $file, strlen( $plugin_dir ) + 1 );
				$parts    = explode( '/', $relative );

				if ( ! empty( $parts[0] ) ) {
					$plugin_slug = $parts[0];

					// Skip wptsall's own frames, continue finding the real source
					if ( in_array( $plugin_slug, $skip_plugins, true ) ) {
						continue;
					}

					return array(
						'plugin_slug' => $plugin_slug,
						'file'        => $relative,
						'function'    => isset( $frame['function'] ) ? $frame['function'] : '',
						'class'       => isset( $frame['class'] ) ? $frame['class'] : '',
						'type'        => 'plugin',
					);
				}
			}

			// Check if file is in mu-plugins directory
			if ( strpos( $file, $mu_plugin_dir ) === 0 ) {
				$relative = substr( $file, strlen( $mu_plugin_dir ) + 1 );
				$parts    = explode( '/', $relative );

				$mu_slug = ! empty( $parts[0] ) ? $parts[0] : 'mu-plugins';

				// Skip wptsall-related mu-plugin
				if ( strpos( $mu_slug, 'wpmmcc-ats' ) !== false ) {
					continue;
				}

				return array(
					'plugin_slug' => $mu_slug,
					'file'        => $relative,
					'function'    => isset( $frame['function'] ) ? $frame['function'] : '',
					'class'       => isset( $frame['class'] ) ? $frame['class'] : '',
					'type'        => 'mu-plugin',
				);
			}

			// Check if file is in theme directory
			if ( strpos( $file, $theme_dir ) === 0 ) {
				$relative = substr( $file, strlen( $theme_dir ) + 1 );
				$parts    = explode( '/', $relative );

				return array(
					'plugin_slug' => ! empty( $parts[0] ) ? 'theme:' . $parts[0] : 'theme',
					'file'        => $relative,
					'function'    => isset( $frame['function'] ) ? $frame['function'] : '',
					'class'       => isset( $frame['class'] ) ? $frame['class'] : '',
					'type'        => 'theme',
				);
			}
		}

		return null;
	}

	/**
	 * Get all tracked post_type sources
	 *
	 * @return array Post type sources.
	 */
	public static function get_post_type_sources() {
		return self::$post_type_sources;
	}

	/**
	 * Get all tracked taxonomy sources
	 *
	 * @return array Taxonomy sources.
	 */
	public static function get_taxonomy_sources() {
		return self::$taxonomy_sources;
	}

	/**
	 * Get post_types registered by a specific plugin
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Array of post_type names.
	 */
	public static function get_post_types_by_plugin( $plugin_slug ) {
		$post_types = array();

		foreach ( self::$post_type_sources as $pt => $source ) {
			if ( $source['plugin_slug'] === $plugin_slug ) {
				$post_types[] = $pt;
			}
		}

		return $post_types;
	}

	/**
	 * Get taxonomies registered by a specific plugin
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array Array of taxonomy names.
	 */
	public static function get_taxonomies_by_plugin( $plugin_slug ) {
		$taxonomies = array();

		foreach ( self::$taxonomy_sources as $tax => $source ) {
			if ( $source['plugin_slug'] === $plugin_slug ) {
				$taxonomies[] = $tax;
			}
		}

		return $taxonomies;
	}

	/**
	 * Get all plugins that registered content types
	 *
	 * @return array Array of plugin slugs.
	 */
	public static function get_plugins_with_content() {
		$plugins = array();

		foreach ( self::$post_type_sources as $source ) {
			if ( $source['type'] === 'plugin' && ! in_array( $source['plugin_slug'], $plugins, true ) ) {
				$plugins[] = $source['plugin_slug'];
			}
		}

		foreach ( self::$taxonomy_sources as $source ) {
			if ( $source['type'] === 'plugin' && ! in_array( $source['plugin_slug'], $plugins, true ) ) {
				$plugins[] = $source['plugin_slug'];
			}
		}

		return $plugins;
	}

	/**
	 * Get source info for a specific post_type
	 *
	 * @param string $post_type Post type name.
	 * @return array|null Source info or null.
	 */
	public static function get_post_type_source( $post_type ) {
		return isset( self::$post_type_sources[ $post_type ] ) ? self::$post_type_sources[ $post_type ] : null;
	}

	/**
	 * Get source info for a specific taxonomy
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array|null Source info or null.
	 */
	public static function get_taxonomy_source( $taxonomy ) {
		return isset( self::$taxonomy_sources[ $taxonomy ] ) ? self::$taxonomy_sources[ $taxonomy ] : null;
	}

	/**
	 * Save tracked data to cache
	 *
	 * Useful for persisting tracking results between requests.
	 */
	public static function save_to_cache() {
		$data = array(
			'post_types' => self::$post_type_sources,
			'taxonomies' => self::$taxonomy_sources,
			'timestamp'  => time(),
		);

		update_option( self::CACHE_KEY, $data, false );
	}

	/**
	 * Load tracked data from cache
	 *
	 * @return bool True if loaded successfully.
	 */
	public static function load_from_cache() {
		$data = get_option( self::CACHE_KEY );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		if ( isset( $data['post_types'] ) ) {
			self::$post_type_sources = $data['post_types'];
		}

		if ( isset( $data['taxonomies'] ) ) {
			self::$taxonomy_sources = $data['taxonomies'];
		}

		return true;
	}

	/**
	 * Clear cached tracking data
	 */
	public static function clear_cache() {
		delete_option( self::CACHE_KEY );
		self::$post_type_sources = array();
		self::$taxonomy_sources = array();
	}

	/**
	 * Get tracking summary for debugging
	 *
	 * @return array Summary data.
	 */
	public static function get_summary() {
		$post_type_by_plugin = array();
		$taxonomy_by_plugin = array();

		foreach ( self::$post_type_sources as $pt => $source ) {
			$slug = $source['plugin_slug'];
			if ( ! isset( $post_type_by_plugin[ $slug ] ) ) {
				$post_type_by_plugin[ $slug ] = array();
			}
			$post_type_by_plugin[ $slug ][] = $pt;
		}

		foreach ( self::$taxonomy_sources as $tax => $source ) {
			$slug = $source['plugin_slug'];
			if ( ! isset( $taxonomy_by_plugin[ $slug ] ) ) {
				$taxonomy_by_plugin[ $slug ] = array();
			}
			$taxonomy_by_plugin[ $slug ][] = $tax;
		}

		return array(
			'total_post_types'    => count( self::$post_type_sources ),
			'total_taxonomies'    => count( self::$taxonomy_sources ),
			'total_plugins'       => count( self::get_plugins_with_content() ),
			'post_types_by_plugin' => $post_type_by_plugin,
			'taxonomies_by_plugin' => $taxonomy_by_plugin,
			'is_tracking'         => self::$is_tracking,
		);
	}

	/**
	 * Check if tracking is active
	 *
	 * @return bool True if tracking.
	 */
	public static function is_tracking() {
		return self::$is_tracking;
	}
}
