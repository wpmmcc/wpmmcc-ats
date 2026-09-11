<?php
/**
 * WPTSALL Plugin Scanner Service
 *
 * Scans plugin source code to extract prefixes and match post_types/taxonomies.
 *
 * @package WPTSALL
 * @since 0.3.1
 */

namespace WPTSALL\Models\Services;

use WPTSALL\Models\Scanners\Runtime_Tracker;
use WPTSALL\Models\Scanners\Orphan_Resolver;
use WPTSALL\Models\Scanners\Custom_Table_Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Plugin Scanner Class
 *
 * Scans plugins to discover:
 * 1. Prefixes from class names, function names, variable names
 * 2. Match prefixes against registered post_types and taxonomies
 * 3. Determine if plugin is a "content plugin" (has public post_types with frontend URLs)
 * 4. Extract endpoints, shortcodes, admin menus from source code
 * 5. Extract meta field registrations
 */
class Plugin_Scanner {

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
	 * Get all scannable plugins
	 *
	 * Returns a list of plugins that can be scanned for translation models.
	 * Includes WordPress core blog functionality and all active plugins.
	 *
	 * @return array Plugin slug => plugin name associative array
	 */
	public static function get_scannable_plugins() {
		$scannable = array();

		// Always include WordPress core blog functionality as first option
		$scannable['wordpress-blog'] = 'WordPress Blog (Default)';

		// Get all active plugins
		$active_plugins = get_option( 'active_plugins', array() );

		// Include network-activated plugins on multisite
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $network_plugins ) && is_array( $network_plugins ) ) {
				// Network plugins are stored as plugin_file => timestamp
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		// Remove duplicates
		$active_plugins = array_unique( $active_plugins );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin_data = $all_plugins[ $plugin_file ];
			$plugin_slug = self::get_plugin_slug( $plugin_file );

			// Skip self.
			if ( wptsall_is_self_plugin_slug( $plugin_slug ) ) {
				continue;
			}

			// Add plugin to scannable list
			$scannable[ $plugin_slug ] = $plugin_data['Name'];
		}

		return $scannable;
	}

	/**
	 * Scan all active plugins
	 *
	 * @return array Array of scan results
	 */
	public static function scan_all_plugins() {
		$start_time = microtime( true );

		wptsall_log_info(
			'models-scanner',
			'Plugin_Scanner starting scan of all plugins'
		);

		$results = array();

		// First, scan WordPress core (wordpress-blog) - always include as it's the foundation
		$blog_result = self::scan_blog();
		if ( $blog_result ) {
			$results['wordpress-blog'] = $blog_result;
		}

		// Get all active plugins for the current site
		$active_plugins = get_option( 'active_plugins', array() );

		// Include network-activated plugins on multisite
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $network_plugins ) && is_array( $network_plugins ) ) {
				// Network plugins are stored as plugin_file => timestamp
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		// Remove duplicates
		$active_plugins = array_unique( $active_plugins );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin_data = $all_plugins[ $plugin_file ];
			$plugin_slug = self::get_plugin_slug( $plugin_file );

			// Skip self.
			if ( wptsall_is_self_plugin_slug( $plugin_slug ) ) {
				continue;
			}

			$result = self::scan_plugin( $plugin_slug, $plugin_file, $plugin_data );
			if ( $result ) {
				$results[ $plugin_slug ] = $result;
			}
		}

		// Count content plugins
		$content_plugins = array_filter(
			$results,
			function ( $r ) {
				return $r['is_content_plugin'] ?? false;
			}
		);

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );
		wptsall_log_info(
			'models-scanner',
			'Plugin_Scanner scan completed',
			array(
				'total_plugins'   => count( $results ),
				'content_plugins' => count( $content_plugins ),
				'duration_ms'     => $duration_ms,
			)
		);

		return $results;
	}

	/**
	 * Scan a single plugin
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $plugin_file Plugin file path (relative to plugins dir).
	 * @param array  $plugin_data Plugin data from get_plugins().
	 * @return array|null Scan result or null on failure.
	 */
	public static function scan_plugin( $plugin_slug, $plugin_file = '', $plugin_data = array() ) {
		// Special handling for WordPress core (wordpress-blog)
		if ( 'wordpress-blog' === $plugin_slug ) {
			return self::scan_blog();
		}

		wptsall_log_debug(
			'models-scanner',
			'Plugin_Scanner scanning plugin',
			array( 'plugin' => $plugin_slug )
		);

		// Get plugin info if not provided
		if ( empty( $plugin_file ) || empty( $plugin_data ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$all_plugins = get_plugins();
			foreach ( $all_plugins as $file => $data ) {
				$slug = self::get_plugin_slug( $file );
				if ( $slug === $plugin_slug ) {
					$plugin_file = $file;
					$plugin_data = $data;
					break;
				}
			}
		}

		if ( empty( $plugin_file ) ) {
			wptsall_log_debug(
				'models-scanner',
				'Plugin_Scanner plugin file not found',
				array( 'plugin' => $plugin_slug )
			);
			return null;
		}

		$is_single_file = ( '.' === dirname( $plugin_file ) );
		if ( $is_single_file ) {
			// Single-file plugin: scan only that file (not the whole plugins directory).
			$plugin_dir = wptsall_get_plugins_dir();
			$scan_dir   = wptsall_resolve_plugin_path( $plugin_file, true );
		} else {
			$plugin_dir = wptsall_resolve_plugin_path( $plugin_file, false );
			$scan_dir   = $plugin_dir;
		}

		// Extract prefixes and explicit types from source code.
		// For single-file plugins, pass the single file path to avoid scanning all plugins.
		// $scan_dir already set above.
		$prefix_data    = self::extract_prefixes( $scan_dir, $plugin_slug );
		$prefixes       = $prefix_data['prefixes'];
		$explicit_types = $prefix_data['explicit_types'];

		// Start with explicitly declared types from source code (register_post_type calls)
		// These are high-confidence matches - include even if not public (e.g., testimonials shown via shortcode)
		$explicit_post_types = array();
		foreach ( $explicit_types as $explicit_type ) {
			if ( post_type_exists( $explicit_type ) ) {
				$pt_obj = get_post_type_object( $explicit_type );
				if ( $pt_obj && ! in_array( $explicit_type, self::$core_post_types, true ) ) {
					$explicit_post_types[] = $explicit_type;
				}
			}
		}

		// Match post types by prefix
		$prefix_matched = self::match_post_types( $prefixes );

		// Merge explicit + prefix-matched post types with ownership guard.
		// Explicit types are high-confidence; prefix matches are only supplemental.
		$known_mappings = self::get_known_post_type_mappings();
		$mapped_post_types = array();
		foreach ( $known_mappings as $pt => $owner_slug ) {
			if ( $owner_slug !== $plugin_slug ) {
				continue;
			}

			if ( ! post_type_exists( $pt ) ) {
				continue;
			}

			if ( in_array( $pt, self::$core_post_types, true ) ) {
				continue;
			}

			$mapped_post_types[] = $pt;
		}

		$explicit_post_types = array_filter(
			$explicit_post_types,
			function ( $pt ) use ( $plugin_slug, $known_mappings ) {
				// Keep explicit type when ownership is unknown or mapped to this plugin.
				return ! isset( $known_mappings[ $pt ] ) || $known_mappings[ $pt ] === $plugin_slug;
			}
		);

		$prefix_matched = array_filter(
			$prefix_matched,
			function ( $pt ) use ( $plugin_slug, $known_mappings, $explicit_post_types ) {
				if ( isset( $known_mappings[ $pt ] ) ) {
					return $known_mappings[ $pt ] === $plugin_slug;
				}

				// When explicit types already exist, avoid adding unknown prefix matches.
				// This prevents cross-plugin contamination (e.g. shared "sp_" prefixes).
				return empty( $explicit_post_types );
			}
		);

		$post_types = array_unique( array_merge( $explicit_post_types, $mapped_post_types, $prefix_matched ) );

		// Match taxonomies
		$taxonomies = self::match_taxonomies( $prefixes, $post_types );

		// Also include explicitly declared taxonomies
		foreach ( $explicit_types as $explicit_type ) {
			if ( taxonomy_exists( $explicit_type ) ) {
				$tax_obj = get_taxonomy( $explicit_type );
				if ( $tax_obj && $tax_obj->public && ! in_array( $explicit_type, self::$core_taxonomies, true ) ) {
					$taxonomies[] = $explicit_type;
				}
			}
		}
		$taxonomies = array_unique( $taxonomies );

		// Supplemental: resolve orphan post_types/taxonomies via Orphan_Resolver.
		// Catches dynamically registered types (CPTUI, Pods, Toolset) that don't match prefix patterns.
		if ( class_exists( '\WPTSALL\Models\Scanners\Orphan_Resolver' ) ) {
			$orphan_pts = Orphan_Resolver::find_orphan_post_types();
			foreach ( $orphan_pts as $orphan ) {
				$resolution = Orphan_Resolver::resolve_post_type( $orphan['name'] );
				if ( $resolution && $resolution['plugin_slug'] === $plugin_slug
					&& ! in_array( $orphan['name'], $post_types, true ) ) {
					$post_types[] = $orphan['name'];
				}
			}

			$orphan_taxos = Orphan_Resolver::find_orphan_taxonomies();
			foreach ( $orphan_taxos as $orphan ) {
				$resolution = Orphan_Resolver::resolve_taxonomy( $orphan['name'] );
				if ( $resolution && $resolution['plugin_slug'] === $plugin_slug
					&& ! in_array( $orphan['name'], $taxonomies, true ) ) {
					$taxonomies[] = $orphan['name'];
				}
			}
		}

		// Determine if it's a content plugin
		// Only plugins with publicly accessible post_types or taxonomies are content plugins
		// Plugins with public=false post_types (e.g., testimonials shown via shortcode) are NOT content plugins
		// They can still be used for language pack translation but won't create sync models
		$is_content_plugin = self::is_content_plugin( $post_types, $taxonomies );

		// Transform post_types into detailed structure with rest_base, supports, labels, etc.
		$post_types_detailed = self::get_post_types_detailed( $post_types );

		// Transform taxonomies into detailed structure with rest_base, labels, etc.
		$taxonomies_detailed = self::get_taxonomies_detailed( $taxonomies );

		// Store all prefixes for debugging/display
		$all_prefixes = array_merge( $prefixes, $explicit_types );
		$all_prefixes = array_unique( $all_prefixes );

		// Scan for custom tables (ISS-MOD-019).
		$custom_tables = array();
		if ( class_exists( '\WPTSALL\Models\Scanners\Custom_Table_Scanner' ) ) {
			$custom_tables = Custom_Table_Scanner::find_plugin_tables( $plugin_slug );
		}

		// Update is_content_plugin to include custom table plugins.
		if ( ! $is_content_plugin && ! empty( $custom_tables ) ) {
			// Check if has content tables.
			$content_tables = array_filter(
				$custom_tables,
				function ( $table ) {
					return $table['is_content'] ?? false;
				}
			);
			if ( ! empty( $content_tables ) ) {
				$is_content_plugin = true;
			}
		}

		$result = array(
			'plugin_slug'       => $plugin_slug,
			'plugin_name'       => $plugin_data['Name'] ?? $plugin_slug,
			'plugin_version'    => $plugin_data['Version'] ?? '',
			'prefixes'          => $all_prefixes,
			'post_types'        => $post_types_detailed,
			'taxonomies'        => $taxonomies_detailed,
			'custom_tables'     => $custom_tables,
			'is_content_plugin' => $is_content_plugin,
			'is_active'         => is_plugin_active( $plugin_file ),
			// Extended data: registration info extracted from source code
			'endpoints'         => $prefix_data['endpoints'] ?? array(),
			'shortcodes'        => $prefix_data['shortcodes'] ?? array(),
			'admin_menus'       => $prefix_data['admin_menus'] ?? array(),
			'meta_fields'       => $prefix_data['meta_fields'] ?? array(),
			'rest_routes'       => $prefix_data['rest_routes'] ?? array(),
			'option_keys'       => $prefix_data['option_keys'] ?? array(),
		);

		$result = self::enrich_scan_with_peer_and_adapters( $result, $plugin_slug );

		wptsall_log_debug(
			'models-scanner',
			'Plugin_Scanner plugin scan result',
			array(
				'plugin'              => $plugin_slug,
				'is_content_plugin'   => $is_content_plugin,
				'post_types_count'    => count( $post_types_detailed ),
				'taxonomies_count'    => count( $taxonomies_detailed ),
				'custom_tables_count' => count( $custom_tables ),
			)
		);

		return $result;
	}

	/**
	 * Get detailed information for post types
	 *
	 * Transforms a simple array of post_type names into detailed structure
	 * with rest_base, supports, labels, and other metadata.
	 *
	 * @param array $post_type_names Simple array of post_type names.
	 * @return array Array of detailed post_type objects.
	 */
	private static function get_post_types_detailed( $post_type_names ) {
		$detailed = array();

		foreach ( $post_type_names as $pt_name ) {
			$pt_obj = get_post_type_object( $pt_name );

			if ( ! $pt_obj ) {
				continue;
			}

			// Get supports features
			$supports = get_all_post_type_supports( $pt_name );

			// Build detailed structure
			$detailed[] = array(
				'name'               => $pt_name,
				'rest_base'          => $pt_obj->rest_base ?: $pt_name,
				'supports'           => $supports ? array_keys( $supports ) : array(),
				'labels'             => array(
					'name'          => $pt_obj->labels->name ?? $pt_name,
					'singular_name' => $pt_obj->labels->singular_name ?? $pt_name,
					'menu_name'     => $pt_obj->labels->menu_name ?? $pt_name,
				),
				'public'             => (bool) $pt_obj->public,
				'publicly_queryable' => (bool) $pt_obj->publicly_queryable,
				'show_ui'            => (bool) $pt_obj->show_ui,
				'has_archive'        => $pt_obj->has_archive,
				'rewrite'            => is_array( $pt_obj->rewrite ) ? array(
					'slug'       => $pt_obj->rewrite['slug'] ?? $pt_name,
					'with_front' => $pt_obj->rewrite['with_front'] ?? true,
				) : false,
				'show_in_rest'       => (bool) $pt_obj->show_in_rest,
				'rest_namespace'     => $pt_obj->rest_namespace ?? 'wp/v2',
			);
		}

		return $detailed;
	}

	/**
	 * Get detailed information for taxonomies
	 *
	 * Transforms a simple array of taxonomy names into detailed structure
	 * with rest_base, labels, and other metadata.
	 *
	 * @param array $taxonomy_names Simple array of taxonomy names.
	 * @return array Array of detailed taxonomy objects.
	 */
	private static function get_taxonomies_detailed( $taxonomy_names ) {
		$detailed = array();

		foreach ( $taxonomy_names as $tax_name ) {
			$tax_obj = get_taxonomy( $tax_name );

			if ( ! $tax_obj ) {
				continue;
			}

			// Build detailed structure
			$detailed[] = array(
				'name'               => $tax_name,
				'rest_base'          => $tax_obj->rest_base ?: $tax_name,
				'labels'             => array(
					'name'          => $tax_obj->labels->name ?? $tax_name,
					'singular_name' => $tax_obj->labels->singular_name ?? $tax_name,
					'menu_name'     => $tax_obj->labels->menu_name ?? $tax_name,
				),
				'public'             => (bool) $tax_obj->public,
				'publicly_queryable' => (bool) $tax_obj->publicly_queryable,
				'hierarchical'       => (bool) $tax_obj->hierarchical,
				'rewrite'            => is_array( $tax_obj->rewrite ) ? array(
					'slug'       => $tax_obj->rewrite['slug'] ?? $tax_name,
					'with_front' => $tax_obj->rewrite['with_front'] ?? true,
				) : false,
				'show_in_rest'       => (bool) $tax_obj->show_in_rest,
				'rest_namespace'     => $tax_obj->rest_namespace ?? 'wp/v2',
			);
		}

		return $detailed;
	}

	/**
	 * Extract prefixes from plugin source code
	 *
	 * @param string $plugin_dir  Plugin directory path.
	 * @param string $plugin_slug Plugin slug.
	 * @return array Array with 'prefixes' and 'explicit_types' keys.
	 */
	public static function extract_prefixes( $plugin_dir, $plugin_slug ) {
		$prefixes       = array();
		$explicit_types = array();

		// 1. Base prefixes from plugin slug (these are most reliable)
		$prefixes[] = $plugin_slug;
		$prefixes[] = str_replace( '-', '_', $plugin_slug );
		$prefixes[] = str_replace( '_', '-', $plugin_slug );

		// Abbreviation (e.g., easy-digital-downloads -> edd)
		$words = preg_split( '/[-_]/', $plugin_slug );
		if ( count( $words ) > 1 ) {
			$abbrev = '';
			foreach ( $words as $word ) {
				if ( ! empty( $word ) ) {
					$abbrev .= $word[0];
				}
			}
			if ( strlen( $abbrev ) >= 2 && strlen( $abbrev ) <= 4 ) {
				$prefixes[] = $abbrev;
			}
		}

		// 2. Scan source files for explicit post_type declarations and plugin-specific prefixes
		$source_data = null;
		if ( is_file( $plugin_dir ) ) {
			// Single-file plugin: scan only this one file.
			$source_data = self::scan_source_for_prefixes( dirname( $plugin_dir ), array( $plugin_dir ) );
		} elseif ( is_dir( $plugin_dir ) ) {
			$source_data = self::scan_source_for_prefixes( $plugin_dir );
		}

		if ( $source_data ) {
			// Store explicit types separately - these are high-confidence matches
			if ( ! empty( $source_data['explicit_types'] ) ) {
				$explicit_types = array_merge( $explicit_types, $source_data['explicit_types'] );
			}

			// Add unique class prefixes that are specific to this plugin
			if ( ! empty( $source_data['class_prefixes'] ) ) {
				$prefixes = array_merge( $prefixes, $source_data['class_prefixes'] );
			}

			// Add short function prefixes (like bbp, lp, edd, fmwp) found frequently in source
			if ( ! empty( $source_data['function_prefixes'] ) ) {
				$prefixes = array_merge( $prefixes, $source_data['function_prefixes'] );
			}
		}

		// Clean and deduplicate prefixes
		$prefixes = array_map( 'strtolower', $prefixes );
		$prefixes = array_unique( array_filter( $prefixes ) );

		// Filter out very short or common prefixes that cause false matches
		// Don't filter explicit_types - they are directly found in source code
		$common_words = array(
			'wp', 'the', 'class', 'new', 'get', 'set', 'add', 'all', 'any',
			'admin', 'ajax', 'api', 'app', 'blog', 'core', 'data', 'db',
			'edit', 'form', 'free', 'hook', 'init', 'item', 'list', 'load',
			'main', 'menu', 'meta', 'name', 'page', 'post', 'rest', 'save',
			'site', 'term', 'test', 'text', 'type', 'user', 'util', 'view',
			'widget', 'export', 'import', 'plugin', 'public', 'private',
		);

		$prefixes = array_filter(
			$prefixes,
			function ( $p ) use ( $common_words ) {
				return strlen( $p ) >= 2 && ! in_array( $p, $common_words, true );
			}
		);

		// Clean explicit types
		$explicit_types = array_map( 'strtolower', $explicit_types );
		$explicit_types = array_unique( array_filter( $explicit_types ) );

		return array(
			'prefixes'       => array_values( $prefixes ),
			'explicit_types' => array_values( $explicit_types ),
			// Pass extended data
			'endpoints'      => $source_data['endpoints'] ?? array(),
			'shortcodes'     => $source_data['shortcodes'] ?? array(),
			'admin_menus'    => $source_data['admin_menus'] ?? array(),
			'meta_fields'    => $source_data['meta_fields'] ?? array(),
			'rest_routes'    => $source_data['rest_routes'] ?? array(),
			'option_keys'    => $source_data['option_keys'] ?? array(),
		);
	}

	/**
	 * Scan source files to extract prefixes and all registration calls
	 *
	 * Scan source code to extract:
	 * - Prefixes (class names, function names)
	 * - register_post_type / register_taxonomy
	 * - add_rewrite_endpoint
	 * - add_shortcode
	 * - add_menu_page / add_submenu_page
	 * - register_meta / register_post_meta
	 * - register_rest_route
	 * - add_option / update_option / get_option
	 *
	 * @param string $plugin_dir Plugin directory.
	 * @return array Array with extracted data.
	 */
	protected static function scan_source_for_prefixes( $plugin_dir, $file_list = null ) {
		$explicit_types    = array();
		$class_prefixes    = array();
		$function_prefixes = array();
		$class_counts      = array();
		$func_counts       = array();

		// Extended data
		$endpoints   = array();
		$shortcodes  = array();
		$admin_menus = array();
		$meta_fields = array();
		$rest_routes = array();
		$option_keys = array();

		// Get PHP files (limit depth and count for performance)
		// When $file_list is provided (single-file plugins), use it directly.
		$files = is_array( $file_list ) ? $file_list : self::get_php_files( $plugin_dir, 3, 150 );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $file );
			if ( false === $content ) {
				continue;
			}

			$relative_file = str_replace( $plugin_dir . '/', '', $file );

			// ========== 1. Extract class name prefix ==========
			if ( preg_match_all( '/class\s+([A-Z][a-zA-Z0-9]*(?:_[A-Z][a-zA-Z0-9]*)*)/', $content, $matches ) ) {
				foreach ( $matches[1] as $class_name ) {
					$parts = explode( '_', $class_name );
					if ( count( $parts ) >= 2 ) {
						$prefix = strtolower( $parts[0] . '_' . $parts[1] );
						$class_counts[ $prefix ] = ( $class_counts[ $prefix ] ?? 0 ) + 1;
					}
				}
			}

			// ========== 2. Extract function prefix ==========
			if ( preg_match_all( '/function\s+([a-z]{2,5})_[a-z_]+\s*\(/', $content, $matches ) ) {
				foreach ( $matches[1] as $prefix ) {
					$func_counts[ $prefix ] = ( $func_counts[ $prefix ] ?? 0 ) + 1;
				}
			}

			// ========== 3. register_post_type ==========
			// 3.1 Literal form: register_post_type( 'post_type_name', ... )
			if ( preg_match_all( '/register_post_type\s*\(\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $pt ) {
					$explicit_types[] = $pt;
				}
			}

			// 3.2 Variable form: register_post_type( $var, ... ) - track variable definition
			// Plan A enhanced: capture variable name, then find variable definition in same file
			if ( preg_match_all( '/register_post_type\s*\(\s*\$([a-z_][a-z0-9_]*)\s*,/i', $content, $var_matches ) ) {
				foreach ( $var_matches[1] as $var_name ) {
					// Find variable definition: $var_name = 'value' or $var_name = func()
					$var_pattern = '/\$' . preg_quote( $var_name, '/' ) . '\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/i';
					if ( preg_match( $var_pattern, $content, $var_value ) ) {
						$explicit_types[] = $var_value[1];
					}
				}
			}

			// $post_type = 'xxx' (common variable name)
			if ( preg_match_all( '/\$post_type\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $pt ) {
					$explicit_types[] = $pt;
				}
			}

			// $this->post_type = 'xxx' (property assignment)
			if ( preg_match_all( '/\$this->post_type\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $pt ) {
					$explicit_types[] = $pt;
				}
			}

			// const POST_TYPE = 'xxx'
			if ( preg_match_all( '/const\s+POST_TYPE\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $pt ) {
					$explicit_types[] = $pt;
				}
			}

			// Plan A enhanced: config array pattern 'xxx_post_type' => 'yyy'
			// Match config like 'course_post_type' => 'courses'
			if ( preg_match_all( '/[\'"]([a-z_]*post_type[a-z_]*)[\'"]\s*=>\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$explicit_types[] = $match[2];
				}
			}

			// Plan A enhanced: apply_filters default value pattern
			// Match apply_filters( 'tutor_course_post_type', 'courses' )
			if ( preg_match_all( '/apply_filters\s*\(\s*[\'"][a-z_]*post_type[a-z_]*[\'"]\s*,\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $pt ) {
					$explicit_types[] = $pt;
				}
			}

			// ========== 4. register_taxonomy ==========
			if ( preg_match_all( '/register_taxonomy\s*\(\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $tax ) {
					$explicit_types[] = $tax;
				}
			}

			// ========== 5. add_rewrite_endpoint ==========
			// add_rewrite_endpoint( 'endpoint-name', EP_PAGES )
			if ( preg_match_all( '/add_rewrite_endpoint\s*\(\s*[\'"]([a-z][a-z0-9_-]*)[\'"](?:\s*,\s*([A-Z_|]+))?/', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$endpoints[] = array(
						'name'     => $match[1],
						'places'   => $match[2] ?? 'EP_ALL',
						'file'     => $relative_file,
					);
				}
			}

			// ========== 6. add_shortcode ==========
			// add_shortcode( 'shortcode_name', 'callback' )
			if ( preg_match_all( '/add_shortcode\s*\(\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $tag ) {
					$shortcodes[] = array(
						'tag'  => $tag,
						'file' => $relative_file,
					);
				}
			}

			// ========== 7. add_menu_page / add_submenu_page ==========
			// add_menu_page( $page_title, $menu_title, $capability, $menu_slug, ... )
			if ( preg_match_all( '/add_menu_page\s*\(\s*[^,]+,\s*[^,]+,\s*[^,]+,\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $slug ) {
					$admin_menus[] = array(
						'type' => 'menu',
						'slug' => $slug,
						'file' => $relative_file,
					);
				}
			}

			// add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, ... )
			if ( preg_match_all( '/add_submenu_page\s*\(\s*[\'"]([a-z][a-z0-9_.-]*)[\'"],\s*[^,]+,\s*[^,]+,\s*[^,]+,\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$admin_menus[] = array(
						'type'   => 'submenu',
						'parent' => $match[1],
						'slug'   => $match[2],
						'file'   => $relative_file,
					);
				}
			}

			// ========== 8. register_meta / register_post_meta ==========
			// register_meta( 'post', 'meta_key', $args ) — also extract object_subtype from $args.
			if ( preg_match_all( '/register_meta\s*\(\s*[\'"](\w+)[\'"],\s*[\'"]([a-z][a-z0-9_-]*)[\'"]\s*,?\s*([^;]{0,400})/s', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$entry = array(
						'object_type' => $match[1],
						'meta_key'    => $match[2],
						'file'        => $relative_file,
					);

					// Extract object_subtype from args array if present.
					$args_str = isset( $match[3] ) ? $match[3] : '';
					if ( preg_match( '/[\'"]object_subtype[\'"]\s*=>\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $args_str, $sub_m ) ) {
						$entry['object_subtype'] = $sub_m[1];
					}

					$meta_fields[] = $entry;
				}
			}

			// register_post_meta( 'post_type', 'meta_key', $args )
			if ( preg_match_all( '/register_post_meta\s*\(\s*[\'"](\w+)[\'"],\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$meta_fields[] = array(
						'object_type' => 'post',
						'object_subtype' => $match[1],
						'meta_key'    => $match[2],
						'file'        => $relative_file,
					);
				}
			}

			// ========== 9. register_rest_route ==========
			// register_rest_route( 'namespace/v1', '/route', $args )
			if ( preg_match_all( '/register_rest_route\s*\(\s*[\'"]([a-z][a-z0-9_\/-]*)[\'"],\s*[\'"]([^\'\"]+)[\'"]/', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$rest_routes[] = array(
						'namespace' => $match[1],
						'route'     => $match[2],
						'file'      => $relative_file,
					);
				}
			}

			// ========== 10. option keys ==========
			// get_option( 'option_name' ) / update_option( 'option_name', ... ) / add_option( 'option_name', ... )
			if ( preg_match_all( '/(?:get_option|update_option|add_option)\s*\(\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $key ) {
					$option_keys[] = $key;
				}
			}

			// ========== 11. apply_filters patterns ==========
			if ( preg_match_all( '/apply_filters\s*\(\s*[\'"][a-z_]+_post_type[\'"]\s*,\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $pt ) {
					$explicit_types[] = $pt;
				}
			}

			// $this->xxx_post_type = 'typename'
			if ( preg_match_all( '/\$(?:this->)?[a-z_]+_post_type\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $pt ) {
					$explicit_types[] = $pt;
				}
			}

			// add_action/filter hooks with plugin prefix
			if ( preg_match_all( '/add_(?:action|filter)\s*\(\s*[\'"]([a-z]{2,5})_/', $content, $matches ) ) {
				foreach ( $matches[1] as $prefix ) {
					$func_counts[ $prefix ] = ( $func_counts[ $prefix ] ?? 0 ) + 1;
				}
			}
		}

		// Only include class prefixes that appear multiple times
		foreach ( $class_counts as $prefix => $count ) {
			if ( $count >= 3 ) {
				$class_prefixes[] = $prefix;
			}
		}

		// Include function prefixes that appear very frequently
		$common_short_words = array( 'is', 'do', 'wp', 'on', 'if', 'to', 'as', 'at', 'in', 'of', 'can', 'get', 'set', 'add', 'has', 'the', 'for' );
		foreach ( $func_counts as $prefix => $count ) {
			if ( $count >= 10 && strlen( $prefix ) >= 2 && strlen( $prefix ) <= 5 && ! in_array( $prefix, $common_short_words, true ) ) {
				$function_prefixes[] = $prefix;
			}
		}

		// Deduplicate option_keys
		$option_keys = array_unique( $option_keys );

		return array(
			'explicit_types'    => array_unique( $explicit_types ),
			'class_prefixes'    => array_unique( $class_prefixes ),
			'function_prefixes' => array_unique( $function_prefixes ),
			'endpoints'         => $endpoints,
			'shortcodes'        => $shortcodes,
			'admin_menus'       => $admin_menus,
			'meta_fields'       => $meta_fields,
			'rest_routes'       => $rest_routes,
			'option_keys'       => $option_keys,
		);
	}

	/**
	 * Get PHP files from directory with depth and count limits
	 *
	 * @param string $dir       Directory path.
	 * @param int    $max_depth Maximum directory depth.
	 * @param int    $max_files Maximum number of files.
	 * @return array Array of file paths.
	 */
	protected static function get_php_files( $dir, $max_depth = 3, $max_files = 100 ) {
		$files = array();
		// Collect more files than needed, then prioritize and trim
		self::scan_directory( $dir, $files, 0, $max_depth, $max_files * 3 );

		// Prioritize files likely to contain post_type/taxonomy registrations
		usort(
			$files,
			function ( $a, $b ) {
				$a_priority = 0;
				$b_priority = 0;
				$a_name     = strtolower( basename( $a ) );
				$b_name     = strtolower( basename( $b ) );

				// High priority: files with post-type, taxonomy, cpt in name
				$high_priority_patterns = array( 'post-type', 'post_type', 'posttypes', 'cpt', 'taxonomy' );
				foreach ( $high_priority_patterns as $pattern ) {
					if ( strpos( $a_name, $pattern ) !== false ) {
						$a_priority = 10;
					}
					if ( strpos( $b_name, $pattern ) !== false ) {
						$b_priority = 10;
					}
				}

				// Medium priority: init, setup, register files
				$medium_priority_patterns = array( 'init', 'setup', 'core' );
				foreach ( $medium_priority_patterns as $pattern ) {
					if ( $a_priority < 10 && strpos( $a_name, $pattern ) !== false ) {
						$a_priority = max( $a_priority, 5 );
					}
					if ( $b_priority < 10 && strpos( $b_name, $pattern ) !== false ) {
						$b_priority = max( $b_priority, 5 );
					}
				}

				// Lower priority: main plugin file or class files
				if ( $a_priority < 5 && ( strpos( $a_name, 'class-' ) === 0 || strpos( $a_name, basename( dirname( $a ) ) ) !== false ) ) {
					$a_priority = max( $a_priority, 3 );
				}
				if ( $b_priority < 5 && ( strpos( $b_name, 'class-' ) === 0 || strpos( $b_name, basename( dirname( $b ) ) ) !== false ) ) {
					$b_priority = max( $b_priority, 3 );
				}

				return $b_priority <=> $a_priority;
			}
		);

		// Trim to max_files
		return array_slice( $files, 0, $max_files );
	}

	/**
	 * Recursively scan directory for PHP files
	 *
	 * @param string $dir       Current directory.
	 * @param array  $files     Files array (by reference).
	 * @param int    $depth     Current depth.
	 * @param int    $max_depth Maximum depth.
	 * @param int    $max_files Maximum files.
	 */
	protected static function scan_directory( $dir, &$files, $depth, $max_depth, $max_files ) {
		if ( $depth > $max_depth || count( $files ) >= $max_files ) {
			return;
		}

		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( count( $files ) >= $max_files ) {
				return;
			}

			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			// Skip common non-essential directories
			if ( in_array( $item, array( 'node_modules', 'vendor', 'tests', 'test', 'assets', 'css', 'js', 'images', 'languages' ), true ) ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				self::scan_directory( $path, $files, $depth + 1, $max_depth, $max_files );
			} elseif ( is_file( $path ) && '.php' === substr( $item, -4 ) ) {
				$files[] = $path;
			}
		}
	}

	/**
	 * Match post_types using prefixes
	 *
	 * Matches post_types that have:
	 * - show_ui=true (admin management interface), OR
	 * - publicly_queryable=true (has frontend URLs)
	 *
	 * @param array $prefixes Array of prefixes.
	 * @return array Array of matching post_type names.
	 */
	public static function match_post_types( $prefixes ) {
		$matched = array();

		// Get post types with admin UI
		$ui_types = get_post_types( array( 'show_ui' => true ), 'names' );

		// Get post types with public frontend URLs
		$public_types = get_post_types( array( 'publicly_queryable' => true ), 'names' );

		// Combine both sets
		$all_types = array_unique( array_merge( $ui_types, $public_types ) );

		foreach ( $all_types as $pt ) {
			// Skip core types
			if ( in_array( $pt, self::$core_post_types, true ) ) {
				continue;
			}

			// Skip excluded system types
			if ( in_array( $pt, self::$excluded_post_types, true ) ) {
				continue;
			}

			// Check if matches any prefix
			if ( self::matches_prefix( $pt, $prefixes ) ) {
				$matched[] = $pt;
			}
		}

		return array_unique( $matched );
	}

	/**
	 * Get known post_type ownership mapping.
	 *
	 * @return array Array in format: post_type => plugin_slug.
	 */
	protected static function get_known_post_type_mappings() {
		if ( ! class_exists( '\WPTSALL\Models\Scanners\Orphan_Resolver' ) ) {
			return array();
		}

		$mappings = Orphan_Resolver::get_known_mappings();
		return is_array( $mappings ) ? $mappings : array();
	}

	/**
	 * Match taxonomies using prefixes and associated post_types
	 *
	 * Matches taxonomies that have:
	 * - show_ui=true (admin management interface), OR
	 * - publicly_queryable=true (has frontend archive pages)
	 *
	 * @param array $prefixes   Array of prefixes.
	 * @param array $post_types Array of matched post_types.
	 * @return array Array of matching taxonomy names.
	 */
	public static function match_taxonomies( $prefixes, $post_types = array() ) {
		$matched = array();

		// Get taxonomies with admin UI
		$ui_taxes = get_taxonomies( array( 'show_ui' => true ), 'objects' );

		// Get taxonomies with public frontend
		$public_taxes = get_taxonomies( array( 'publicly_queryable' => true ), 'objects' );

		// Combine both
		$all_taxes = array_merge( $ui_taxes, $public_taxes );

		foreach ( $all_taxes as $tax ) {
			// Skip core taxonomies
			if ( in_array( $tax->name, self::$core_taxonomies, true ) ) {
				continue;
			}

			// Skip if already matched
			if ( in_array( $tax->name, $matched, true ) ) {
				continue;
			}

			// Method 1: Check if associated with matched post_types
			$tax_object_types = (array) $tax->object_type;
			if ( ! empty( array_intersect( $tax_object_types, $post_types ) ) ) {
				$matched[] = $tax->name;
				continue;
			}

			// Method 2: Check prefix match
			if ( self::matches_prefix( $tax->name, $prefixes ) ) {
				$matched[] = $tax->name;
			}
		}

		return array_unique( $matched );
	}

	/**
	 * Check if a name matches any of the prefixes
	 *
	 * @param string $name     Name to check.
	 * @param array  $prefixes Array of prefixes.
	 * @return bool True if matches.
	 */
	protected static function matches_prefix( $name, $prefixes ) {
		$name_lower = strtolower( $name );

		foreach ( $prefixes as $prefix ) {
			$prefix_lower = strtolower( $prefix );

			// Exact match
			if ( $name_lower === $prefix_lower ) {
				return true;
			}

			// Prefix match with separator
			if ( strpos( $name_lower, $prefix_lower . '_' ) === 0 ) {
				return true;
			}

			if ( strpos( $name_lower, $prefix_lower . '-' ) === 0 ) {
				return true;
			}

			// Prefix match without separator (for short prefixes like 'wc', 'bp')
			if ( strlen( $prefix_lower ) <= 3 && strpos( $name_lower, $prefix_lower ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * System post types to exclude from content plugins
	 *
	 * These post types have show_ui=true but are internal system types,
	 * not user-facing content that should be synced/translated.
	 *
	 * @var array
	 */
	protected static $excluded_post_types = array(
		// WooCommerce internal types
		'shop_order',
		'shop_order_refund',
		'shop_coupon',
		// ACF internal types
		'acf-field-group',
		'acf-field',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
		// Action Scheduler
		'scheduled_action',
		// Classified Listing internal types
		'rtcl_payment',
		'rtcl_pricing',
		// Other common internal types
		'wp_block',
		'wp_font_family',
		'wp_font_face',
		'oembed_cache',
		'user_request',
		'customize_changeset',
		'custom_css',
	);

	/**
	 * Structural taxonomies whose terms are site chrome, not translatable
	 * content. Term changes in these must not enter the sync outbox.
	 *
	 * @var array
	 */
	protected static $excluded_taxonomies = array(
		'nav_menu',
		'link_category',
		'post_format',
	);

	/**
	 * Merge peer language configs + field adapters into a scan result.
	 *
	 * Lets overlay plugins (Elementor JSON, ACF fields) and dynamic CPTs
	 * (Events Manager) produce a model even when prefix matching missed them.
	 *
	 * @since 2.1.0
	 * @param array  $result      Scan result.
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	public static function enrich_scan_with_peer_and_adapters( array $result, $plugin_slug ) {
		$plugin_slug = sanitize_key( (string) $plugin_slug );
		$peer        = array();
		if ( class_exists( '\\WPTSALL\\Core\\WPML_Config_Reader' ) ) {
			$peer = \WPTSALL\Core\WPML_Config_Reader::get_config_for_plugin( $plugin_slug );
		}

		$existing_types = array();
		foreach ( (array) ( $result['post_types'] ?? array() ) as $pt ) {
			$name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
			if ( '' !== $name ) {
				$existing_types[ $name ] = true;
			}
		}
		$peer_type_names = array();
		foreach ( array_keys( (array) ( $peer['custom-types'] ?? array() ) ) as $pt_name ) {
			$pt_name = sanitize_key( (string) $pt_name );
			if ( '' === $pt_name || isset( $existing_types[ $pt_name ] ) ) {
				continue;
			}
			if ( function_exists( 'post_type_exists' ) && ! post_type_exists( $pt_name ) ) {
				continue;
			}
			$peer_type_names[] = $pt_name;
		}
		if ( ! empty( $peer_type_names ) ) {
			$result['post_types'] = array_merge(
				(array) ( $result['post_types'] ?? array() ),
				self::get_post_types_detailed( $peer_type_names )
			);
		}

		$existing_tax = array();
		foreach ( (array) ( $result['taxonomies'] ?? array() ) as $tax ) {
			$name = is_array( $tax ) ? ( $tax['name'] ?? '' ) : $tax;
			if ( '' !== $name ) {
				$existing_tax[ $name ] = true;
			}
		}
		$peer_tax_names = array();
		foreach ( array_keys( (array) ( $peer['taxonomies'] ?? array() ) ) as $tax_name ) {
			$tax_name = sanitize_key( (string) $tax_name );
			if ( '' === $tax_name || isset( $existing_tax[ $tax_name ] ) ) {
				continue;
			}
			if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $tax_name ) ) {
				continue;
			}
			$peer_tax_names[] = $tax_name;
		}
		if ( ! empty( $peer_tax_names ) ) {
			$result['taxonomies'] = array_merge(
				(array) ( $result['taxonomies'] ?? array() ),
				self::get_taxonomies_detailed( $peer_tax_names )
			);
		}

		$meta_fields = is_array( $result['meta_fields'] ?? null ) ? $result['meta_fields'] : array();
		$seen_meta   = array();
		foreach ( $meta_fields as $entry ) {
			$key = is_array( $entry ) ? ( $entry['meta_key'] ?? $entry['field_name'] ?? $entry['key'] ?? '' ) : (string) $entry;
			if ( '' !== $key ) {
				$seen_meta[ $key ] = true;
			}
		}

		foreach ( (array) ( $peer['custom-fields'] ?? array() ) as $meta_key => $action ) {
			$meta_key = (string) $meta_key;
			if ( '' === $meta_key || isset( $seen_meta[ $meta_key ] ) ) {
				continue;
			}
			$meta_fields[] = array(
				'meta_key'   => $meta_key,
				'field_name' => $meta_key,
				'source'     => 'peer_config',
				'action'     => $action,
			);
			$seen_meta[ $meta_key ] = true;
		}

		if ( class_exists( '\\WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry' ) ) {
			$registry = '\\WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry';
			foreach ( $registry::get_adapters() as $adapter_class ) {
				if ( ! class_exists( $adapter_class ) ) {
					continue;
				}
				$adapter = new $adapter_class();
				if ( ! $adapter instanceof \WPTSALL\Models\Adapters\Plugin_Field_Rules_Adapter ) {
					continue;
				}
				$adapter_slug = sanitize_key( (string) $adapter->get_plugin_slug() );
				if ( $adapter_slug !== $plugin_slug && false === strpos( $adapter->get_plugin_slug(), $plugin_slug ) && false === strpos( $plugin_slug, $adapter_slug ) ) {
					continue;
				}
				foreach ( $adapter->get_field_rules() as $meta_key => $rule ) {
					$meta_key = (string) $meta_key;
					if ( '' === $meta_key || isset( $seen_meta[ $meta_key ] ) ) {
						continue;
					}
					$meta_fields[] = array(
						'meta_key'   => $meta_key,
						'field_name' => $meta_key,
						'source'     => 'adapter',
						'action'     => $rule['type'] ?? 'sync',
					);
					$seen_meta[ $meta_key ] = true;
				}
			}
		}

		$result['meta_fields'] = $meta_fields;

		// P1-XML-01 (2026-09-02): surface WPML-declared shortcodes/gutenberg
		// attribute translations and admin-texts option paths into the scan
		// result so they appear as suggestions and can be overridden by the
		// Model Editor (peer declarations are advisory, never authoritative
		// over explicit user mappings).
		if ( ! empty( $peer['admin-texts'] ) || ! empty( $peer['shortcodes'] ) || ! empty( $peer['gutenberg'] ) ) {
			$result['peer_wpml_declarations'] = array(
				'admin-texts' => array_values( (array) ( $peer['admin-texts'] ?? array() ) ),
				'shortcodes'  => (array) ( $peer['shortcodes'] ?? array() ),
				'gutenberg'   => (array) ( $peer['gutenberg'] ?? array() ),
				'source'      => (string) ( $peer['source'] ?? 'wpml_config_xml' ),
			);
		}

		if ( empty( $result['is_content_plugin'] ) && ( ! empty( $peer_type_names ) || ! empty( $peer_tax_names ) ) ) {
			$result['is_content_plugin'] = true;
		}

		return self::enrich_scan_with_runtime_authority( $result, $plugin_slug );
	}

	/**
	 * Whether this post type is internal and must not become a content model.
	 *
	 * @since 2.1.0
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_excluded_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		return in_array( $post_type, self::$excluded_post_types, true );
	}

	/**
	 * Whether this taxonomy is structural (site chrome) and its term changes
	 * must be skipped by the content-change dispatcher.
	 *
	 * @since 2.1.4
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public static function is_excluded_taxonomy( $taxonomy ) {
		$taxonomy = sanitize_key( (string) $taxonomy );
		return in_array( $taxonomy, self::$excluded_taxonomies, true );
	}

	/**
	 * Overlay WordPress runtime registrations (CPT + register_meta).
	 *
	 * Peer XML is a suggestion. get_post_types() / get_registered_meta_keys()
	 * are the authority. ACF contributes fields via acf_get_fields() only —
	 * never as a fake content CPT.
	 *
	 * @since 2.1.0
	 * @param array  $result      Scan result.
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	public static function enrich_scan_with_runtime_authority( array $result, $plugin_slug ) {
		$plugin_slug = sanitize_key( (string) $plugin_slug );

		$existing_types = array();
		foreach ( (array) ( $result['post_types'] ?? array() ) as $pt ) {
			$name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
			if ( '' !== $name ) {
				$existing_types[ sanitize_key( $name ) ] = true;
			}
		}

		if ( function_exists( 'get_post_types' ) ) {
			$runtime = get_post_types( array( '_builtin' => false ), 'objects' );
			$extra   = array();
			foreach ( (array) $runtime as $pt_name => $obj ) {
				$pt_name = sanitize_key( (string) $pt_name );
				if ( '' === $pt_name || isset( $existing_types[ $pt_name ] ) || self::is_excluded_post_type( $pt_name ) ) {
					continue;
				}
				if ( ! self::runtime_post_type_belongs_to_plugin( $pt_name, $obj, $plugin_slug ) ) {
					continue;
				}
				$extra[] = $pt_name;
				$existing_types[ $pt_name ] = true;
			}
			if ( ! empty( $extra ) ) {
				$result['post_types'] = array_merge(
					(array) ( $result['post_types'] ?? array() ),
					self::get_post_types_detailed( $extra )
				);
				// Runtime-owned CPTs make this a content plugin even when static
				// analysis found no register_post_type() literals.
				$result['is_content_plugin'] = true;
			}
		}

		$meta_fields = is_array( $result['meta_fields'] ?? null ) ? $result['meta_fields'] : array();
		$seen_meta   = array();
		foreach ( $meta_fields as $entry ) {
			$key = is_array( $entry ) ? ( $entry['meta_key'] ?? $entry['field_name'] ?? $entry['key'] ?? '' ) : (string) $entry;
			if ( '' !== $key ) {
				$seen_meta[ $key ] = true;
			}
		}

		if ( function_exists( 'get_registered_meta_keys' ) ) {
			foreach ( array_keys( $existing_types ) as $pt_name ) {
				$registered = get_registered_meta_keys( 'post', $pt_name );
				if ( ! is_array( $registered ) ) {
					continue;
				}
				foreach ( $registered as $meta_key => $args ) {
					$meta_key = (string) $meta_key;
					if ( '' === $meta_key || isset( $seen_meta[ $meta_key ] ) ) {
						continue;
					}
					$wp_type = is_array( $args ) ? ( $args['type'] ?? 'string' ) : 'string';
					$meta_fields[] = array(
						'meta_key'       => $meta_key,
						'field_name'     => $meta_key,
						'source'         => 'register_meta',
						'object_type'    => 'post',
						'object_subtype' => $pt_name,
						'wp_type'        => $wp_type,
					);
					$seen_meta[ $meta_key ] = true;
				}
			}
		}

		// ACF: field overlay only. Never promote acf-field-group to a content CPT.
		if ( 0 === strpos( $plugin_slug, 'advanced-custom-fields' )
			&& function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				$fields = acf_get_fields( $group['key'] ?? '' );
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $field ) {
					$meta_key = (string) ( $field['name'] ?? '' );
					if ( '' === $meta_key || isset( $seen_meta[ $meta_key ] ) ) {
						continue;
					}
					if ( in_array( $field['type'] ?? '', array( 'tab', 'accordion', 'message' ), true ) ) {
						continue;
					}
					$meta_fields[] = array(
						'meta_key'   => $meta_key,
						'field_name' => $meta_key,
						'source'     => 'acf_get_fields',
						'acf_type'   => $field['type'] ?? '',
					);
					$seen_meta[ $meta_key ] = true;
				}
			}
		}

		$result['meta_fields'] = $meta_fields;

		$has_public_pt = false;
		foreach ( (array) ( $result['post_types'] ?? array() ) as $pt ) {
			$name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
			if ( $name && ! self::is_excluded_post_type( $name ) ) {
				$has_public_pt = true;
				break;
			}
		}
		$custom_tables = (array) ( $result['custom_tables'] ?? array() );
		$result['storage'] = array(
			'primary'                         => $has_public_pt ? 'wp_posts' : ( ! empty( $custom_tables ) ? 'custom_table' : 'meta_overlay' ),
			'pretty_permalink_requires_native'=> ! empty( $custom_tables ),
			'custom_table_count'              => count( $custom_tables ),
		);

		return $result;
	}

	/**
	 * Heuristic: does this runtime CPT belong to the plugin being scanned?
	 *
	 * @param string        $pt_name     Post type.
	 * @param object|null   $obj         Post type object.
	 * @param string        $plugin_slug Plugin slug.
	 * @return bool
	 */
	private static function runtime_post_type_belongs_to_plugin( $pt_name, $obj, $plugin_slug ) {
		$pt_name     = sanitize_key( (string) $pt_name );
		$plugin_slug = sanitize_key( (string) $plugin_slug );
		if ( '' === $pt_name || '' === $plugin_slug ) {
			return false;
		}
		$token = str_replace( array( '-', '_' ), '', $plugin_slug );
		$pt    = str_replace( array( '-', '_' ), '', $pt_name );
		if ( $token && false !== strpos( $pt, substr( $token, 0, min( 6, strlen( $token ) ) ) ) ) {
			return true;
		}
		$known = array(
			'woocommerce'                 => array( 'product', 'product_variation' ),
			'events-manager'              => array( 'event', 'event-recurring', 'location' ),
			'elementor'                   => array( 'elementor_library' ),
			'tutor'                       => array( 'courses', 'lesson' ),
			'easy-digital-downloads'      => array( 'download' ),
			// SSP registers CPT as "podcast" (label Episode); static analysis cannot see it.
			'seriously-simple-podcasting' => array( 'podcast' ),
			'envira-gallery-lite'         => array( 'envira' ),
			'the-events-calendar'         => array( 'tribe_events', 'tribe_venue', 'tribe_organizer' ),
			'learnpress'                  => array( 'lp_course', 'lp_lesson', 'lp_quiz', 'lp_question', 'lp_order' ),
			'wp-recipe-maker'             => array( 'wprm_recipe' ),
			'wp-job-manager'              => array( 'job_listing' ),
		);
		foreach ( $known as $slug => $types ) {
			if ( $slug === $plugin_slug || 0 === strpos( $plugin_slug, $slug ) || 0 === strpos( $slug, $plugin_slug ) ) {
				return in_array( $pt_name, $types, true );
			}
		}
		unset( $obj );
		return false;
	}

	/**
	 * Check if plugin is a content plugin (has stored objects)
	 *
	 * Any plugin that registers at least one non-excluded post_type or taxonomy
	 * is considered a content plugin. No show_ui / publicly_queryable checks —
	 * if the plugin has storage objects we should scan and translate them.
	 *
	 * @param array $post_types Plugin's post types.
	 * @param array $taxonomies Plugin's taxonomies (optional).
	 * @return bool True if plugin has stored objects.
	 */
	public static function is_content_plugin( $post_types, $taxonomies = array() ) {
		// Any non-excluded post_type means it's a content plugin.
		if ( ! empty( $post_types ) ) {
			foreach ( $post_types as $pt ) {
				$pt_name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
				if ( empty( $pt_name ) ) {
					continue;
				}

				if ( in_array( $pt_name, self::$excluded_post_types, true ) ) {
					continue;
				}

				// Has at least one non-excluded post_type.
				return true;
			}
		}

		// Any registered taxonomy means it's a content plugin.
		if ( ! empty( $taxonomies ) ) {
			foreach ( $taxonomies as $tax ) {
				$tax_name = is_array( $tax ) ? ( $tax['name'] ?? '' ) : $tax;
				if ( ! empty( $tax_name ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the list of excluded system post types.
	 *
	 * @return array Excluded post type names.
	 */
	public static function get_excluded_post_types() {
		return self::$excluded_post_types;
	}

	/**
	 * Get plugin slug from plugin file path
	 *
	 * @param string $plugin_file Plugin file path (e.g., 'plugin-name/plugin.php' or 'plugin.php').
	 * @return string Plugin slug.
	 */
	public static function get_plugin_slug( $plugin_file ) {
		$slug = dirname( $plugin_file );
		if ( '.' === $slug ) {
			$slug = basename( $plugin_file, '.php' );
		}
		return $slug;
	}

	/**
	 * Scan WordPress core (blog)
	 *
	 * Generates detailed scan result for WordPress core post types and taxonomies.
	 * This is called during initialization to ensure blog is always included.
	 *
	 * @return array Scan result in the same format as scan_plugin()
	 */
	public static function scan_blog() {
		global $wp_version;

		// Get detailed post_types for WordPress core. FSE objects are owned by
		// the WordPress Blog model (not third-party plugins), so they must be
		// visible to model/progress discovery even though generic plugin scans
		// continue to exclude core post types.
		$fse_types = apply_filters(
			'wptsall_fse_managed_post_types',
			array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' )
		);
		$post_types_detailed = self::get_post_types_detailed(
			array_values( array_unique( array_merge( array( 'post', 'page' ), (array) $fse_types ) ) )
		);

		// Get detailed taxonomies for WordPress core
		$taxonomies_detailed = self::get_taxonomies_detailed( array( 'category', 'post_tag' ) );

		return array(
			'plugin_slug'       => 'wordpress-blog',
			'plugin_name'       => 'WordPress Blog',
			'plugin_version'    => $wp_version,
			'plugin_file'       => 'wordpress-core',
			'prefixes'          => array( 'wp', 'post', 'page' ),
			'post_types'        => $post_types_detailed,
			'taxonomies'        => $taxonomies_detailed,
			'custom_tables'     => array(),
			'is_content_plugin' => true,
			'is_active'         => true,
			'endpoints'         => array(),
			'shortcodes'        => array(),
			'admin_menus'       => array(),
			'meta_fields'       => array(),
			'rest_routes'       => array(),
			'option_keys'       => array(),
		);
	}
}
