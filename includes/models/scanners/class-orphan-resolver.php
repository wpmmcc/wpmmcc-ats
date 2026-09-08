<?php
/**
 * WPTSALL Orphan Resolver
 *
 * Detects and resolves "orphan" post_types and taxonomies that are registered
 * but not assigned to any plugin in the plugin_mappings table.
 *
 * @package WPTSALL
 * @since 0.3.2
 */

namespace WPTSALL\Models\Scanners;

use WPTSALL\Models\Services\Plugin_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orphan Resolver Class
 *
 * Finds unassigned content types and attempts to resolve their owner plugin
 * using multiple strategies: runtime tracking, file path analysis, and fuzzy matching.
 */
class Orphan_Resolver {

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
	 * Source-code detected mappings (dynamic, not hardcoded)
	 *
	 * This array is populated at runtime by scanning plugin source code
	 * for register_post_type() calls. It replaces the old hardcoded $known_mappings.
	 *
	 * Format: post_type => plugin_slug
	 *
	 * @var array|null
	 */
	protected static $source_detected_mappings = null;

	/**
	 * Cache for dynamically detected mappings
	 *
	 * @var array|null
	 */
	protected static $dynamic_mappings = null;

	/**
	 * Get source-code detected mappings
	 *
	 * Scans all active plugins' source code for register_post_type() calls
	 * and builds a mapping of post_type => plugin_slug.
	 *
	 * This is a truly dynamic approach that doesn't rely on hardcoded mappings.
	 *
	 * @return array Mappings (post_type => plugin_slug).
	 */
	public static function get_source_detected_mappings() {
		if ( null !== self::$source_detected_mappings ) {
			return self::$source_detected_mappings;
		}

		self::$source_detected_mappings = array();

		// Check if Plugin_Scanner is available
		if ( ! class_exists( '\WPTSALL\Models\Services\Plugin_Scanner' ) ) {
			return self::$source_detected_mappings;
		}

		// Get all active plugins
		$active_plugins = get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $network_plugins ) && is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		$active_plugins = array_unique( $active_plugins );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		// Scan each plugin's source code for register_post_type() calls
		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			$plugin_slug = \WPTSALL\Models\Services\Plugin_Scanner::get_plugin_slug( $plugin_file );

			// Skip this plugin.
			if ( wptsall_is_self_plugin_slug( $plugin_slug ) ) {
				continue;
			}

			$is_single_file = ( '.' === dirname( $plugin_file ) );
			$plugin_dir     = $is_single_file
				? wptsall_get_plugins_dir()
				: wptsall_resolve_plugin_path( $plugin_file, false );

			// Scan source code for explicit post_type registrations.
			// For single-file plugins, scan only the plugin file itself.
			$explicit_types = array();
			if ( $is_single_file ) {
				$single_path    = wptsall_resolve_plugin_path( $plugin_file, true );
				$explicit_types = is_file( $single_path ) ? self::scan_single_file_for_post_types( $single_path ) : array();
			} elseif ( is_dir( $plugin_dir ) ) {
				$explicit_types = self::scan_plugin_for_post_types( $plugin_dir );
			}

			foreach ( $explicit_types as $pt ) {
				// Only add if the post_type actually exists in WordPress
				if ( post_type_exists( $pt ) ) {
					// Don't override existing mappings
					if ( ! isset( self::$source_detected_mappings[ $pt ] ) ) {
						self::$source_detected_mappings[ $pt ] = $plugin_slug;
					}
				}
			}
		}

		return self::$source_detected_mappings;
	}

	/**
	 * Scan a single plugin file for register_post_type() calls
	 *
	 * @param string $file_path Absolute path to the single plugin file.
	 * @return array Array of post_type names found in source code.
	 */
	protected static function scan_single_file_for_post_types( $file_path ) {
		return self::scan_plugin_for_post_types( $file_path );
	}

	/**
	 * Scan a plugin directory for register_post_type() calls
	 *
	 * @param string $plugin_dir Plugin directory path, or a single file path.
	 * @return array Array of post_type names found in source code.
	 */
	protected static function scan_plugin_for_post_types( $plugin_dir ) {
		$post_types = array();

		// Support single-file scan
		if ( is_file( $plugin_dir ) ) {
			$files = array( $plugin_dir );
		} else {
			// Get PHP files (limit depth for performance)
			$files = self::get_php_files_for_scan( $plugin_dir, 3, 100 );
		}

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $file );
			if ( false === $content ) {
				continue;
			}

			// Pattern 1: register_post_type( 'type_name', ... )
			if ( preg_match_all( '/register_post_type\s*\(\s*[\'"]([a-z][a-z0-9_-]*)[\'"]/', $content, $matches ) ) {
				$post_types = array_merge( $post_types, $matches[1] );
			}

			// Pattern 2: $post_type = 'type_name'
			if ( preg_match_all( '/\$post_type\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				$post_types = array_merge( $post_types, $matches[1] );
			}

			// Pattern 3: const POST_TYPE = 'type_name'
			if ( preg_match_all( '/const\s+(?:POST_TYPE|POSTTYPE|CPT)\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				$post_types = array_merge( $post_types, $matches[1] );
			}

			// Pattern 4: 'post_type' => 'type_name' (array definitions)
			if ( preg_match_all( '/[\'"]post_type[\'"]\s*=>\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				$post_types = array_merge( $post_types, $matches[1] );
			}

			// Pattern 5: $this->post_type = 'type_name'
			if ( preg_match_all( '/\$this->[a-z_]*post_type\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				$post_types = array_merge( $post_types, $matches[1] );
			}

			// Pattern 6: self::POST_TYPE or static::POST_TYPE definitions
			if ( preg_match_all( '/(?:self|static)::\$?(?:POST_TYPE|POSTTYPE|CPT)\s*=\s*[\'"]([a-z][a-z0-9_-]+)[\'"]/', $content, $matches ) ) {
				$post_types = array_merge( $post_types, $matches[1] );
			}
		}

		// Filter and deduplicate
		$post_types = array_unique( array_filter( $post_types ) );

		// Remove core post types
		$core_types = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item' );
		$post_types = array_diff( $post_types, $core_types );

		return array_values( $post_types );
	}

	/**
	 * Get PHP files from directory for source scanning
	 *
	 * @param string $dir       Directory path.
	 * @param int    $max_depth Maximum directory depth.
	 * @param int    $max_files Maximum number of files.
	 * @return array Array of file paths.
	 */
	protected static function get_php_files_for_scan( $dir, $max_depth = 3, $max_files = 100 ) {
		$files = array();
		self::collect_php_files( $dir, $files, 0, $max_depth, $max_files );

		// Prioritize files likely to contain post_type registrations
		usort(
			$files,
			function ( $a, $b ) {
				$a_priority = 0;
				$b_priority = 0;
				$a_name     = strtolower( basename( $a ) );
				$b_name     = strtolower( basename( $b ) );

				// High priority patterns
				$high_priority = array( 'post-type', 'post_type', 'posttypes', 'cpt', 'content-type' );
				foreach ( $high_priority as $pattern ) {
					if ( strpos( $a_name, $pattern ) !== false ) {
						$a_priority = 10;
					}
					if ( strpos( $b_name, $pattern ) !== false ) {
						$b_priority = 10;
					}
				}

				// Medium priority patterns
				$medium_priority = array( 'init', 'setup', 'register', 'core', 'main' );
				foreach ( $medium_priority as $pattern ) {
					if ( $a_priority < 10 && strpos( $a_name, $pattern ) !== false ) {
						$a_priority = max( $a_priority, 5 );
					}
					if ( $b_priority < 10 && strpos( $b_name, $pattern ) !== false ) {
						$b_priority = max( $b_priority, 5 );
					}
				}

				return $b_priority <=> $a_priority;
			}
		);

		return array_slice( $files, 0, $max_files );
	}

	/**
	 * Recursively collect PHP files from directory
	 *
	 * @param string $dir       Current directory.
	 * @param array  $files     Files array (by reference).
	 * @param int    $depth     Current depth.
	 * @param int    $max_depth Maximum depth.
	 * @param int    $max_files Maximum files.
	 */
	protected static function collect_php_files( $dir, &$files, $depth, $max_depth, $max_files ) {
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

			// Skip non-essential directories
			if ( in_array( $item, array( 'node_modules', 'vendor', 'tests', 'test', 'assets', 'css', 'js', 'images', 'languages' ), true ) ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				self::collect_php_files( $path, $files, $depth + 1, $max_depth, $max_files );
			} elseif ( is_file( $path ) && '.php' === substr( $item, -4 ) ) {
				$files[] = $path;
			}
		}
	}

	/**
	 * Find all orphan post_types
	 *
	 * Orphans are post_types that:
	 * 1. Are registered in WordPress
	 * 2. Are not core post_types
	 * 3. Are not assigned to any plugin in plugin_mappings
	 *
	 * @return array Array of orphan post_type info.
	 */
	public static function find_orphan_post_types() {
		$orphans = array();

		// Get all registered public post types
		$all_types = get_post_types( array( 'public' => true ), 'objects' );

		// Get all post_types already assigned in plugin_mappings
		$assigned = self::get_assigned_post_types();

		foreach ( $all_types as $pt => $obj ) {
			// Skip core
			if ( in_array( $pt, self::$core_post_types, true ) ) {
				continue;
			}

			// Skip if already assigned
			if ( in_array( $pt, $assigned, true ) ) {
				continue;
			}

			$orphans[] = array(
				'name'        => $pt,
				'label'       => $obj->label,
				'description' => $obj->description,
				'public'      => $obj->public,
				'has_archive' => $obj->has_archive,
				'rewrite'     => $obj->rewrite,
			);
		}

		return $orphans;
	}

	/**
	 * Find all orphan taxonomies
	 *
	 * @return array Array of orphan taxonomy info.
	 */
	public static function find_orphan_taxonomies() {
		$orphans = array();

		// Get all registered public or UI-visible taxonomies.
		// Some taxonomies (e.g., WooCommerce pa_* attributes) have public=false
		// but show_ui=true and still need translation.
		$all_taxes = get_taxonomies( array( 'public' => true ), 'objects' )
			+ get_taxonomies( array( 'show_ui' => true ), 'objects' );

		// Get all taxonomies already assigned in plugin_mappings
		$assigned = self::get_assigned_taxonomies();

		foreach ( $all_taxes as $tax => $obj ) {
			// Skip core
			if ( in_array( $tax, self::$core_taxonomies, true ) ) {
				continue;
			}

			// Skip if already assigned
			if ( in_array( $tax, $assigned, true ) ) {
				continue;
			}

			$orphans[] = array(
				'name'         => $tax,
				'label'        => $obj->label,
				'description'  => $obj->description,
				'public'       => $obj->public,
				'object_types' => $obj->object_type,
				'rewrite'      => $obj->rewrite,
			);
		}

		return $orphans;
	}

	/**
	 * Get all post_types already assigned in plugin_mappings
	 *
	 * @return array Array of post_type names.
	 */
	protected static function get_assigned_post_types() {
		$assigned = array();

		if ( ! class_exists( '\WPTSALL\Models\Services\Plugin_Mapping_Service' ) ) {
			return $assigned;
		}

		$mappings = Plugin_Mapping_Service::get_all();

		foreach ( $mappings as $mapping ) {
			if ( ! empty( $mapping['post_types'] ) && is_array( $mapping['post_types'] ) ) {
				foreach ( $mapping['post_types'] as $pt ) {
					if ( is_string( $pt ) ) {
						$assigned[] = $pt;
					} elseif ( is_array( $pt ) && ! empty( $pt['name'] ) ) {
						$assigned[] = $pt['name'];
					}
				}
			}
		}

		return array_unique( $assigned );
	}

	/**
	 * Get all taxonomies already assigned in plugin_mappings
	 *
	 * @return array Array of taxonomy names.
	 */
	protected static function get_assigned_taxonomies() {
		$assigned = array();

		if ( ! class_exists( '\WPTSALL\Models\Services\Plugin_Mapping_Service' ) ) {
			return $assigned;
		}

		$mappings = Plugin_Mapping_Service::get_all();

		foreach ( $mappings as $mapping ) {
			if ( ! empty( $mapping['taxonomies'] ) && is_array( $mapping['taxonomies'] ) ) {
				foreach ( $mapping['taxonomies'] as $tax ) {
					if ( is_string( $tax ) ) {
						$assigned[] = $tax;
					} elseif ( is_array( $tax ) && ! empty( $tax['name'] ) ) {
						$assigned[] = $tax['name'];
					}
				}
			}
		}

		return array_unique( $assigned );
	}

	/**
	 * Resolve orphan post_type to a plugin
	 *
	 * Uses multiple strategies in order:
	 * 1. Check source-code detected mappings (dynamic)
	 * 2. Check dynamic mappings (CPTUI, Pods, Toolset, ACF)
	 * 3. Use Runtime_Tracker if available
	 * 4. Fuzzy match against active plugins
	 *
	 * @param string $post_type Post type name.
	 * @return array|null Resolution result or null.
	 */
	public static function resolve_post_type( $post_type ) {
		// Strategy 1: Check source-code detected mappings (dynamic, not hardcoded)
		$source_mappings = self::get_source_detected_mappings();
		if ( isset( $source_mappings[ $post_type ] ) ) {
			$plugin_slug = $source_mappings[ $post_type ];

			// Verify plugin exists
			if ( self::plugin_exists( $plugin_slug ) ) {
				return array(
					'plugin_slug' => $plugin_slug,
					'method'      => 'source_code_scan',
					'confidence'  => 'high',
				);
			}
		}

		// Strategy 2: Check dynamic mappings (CPTUI, Pods, Toolset, ACF)
		$dynamic_mappings = self::get_dynamic_mappings();
		if ( isset( $dynamic_mappings[ $post_type ] ) ) {
			$plugin_slug = $dynamic_mappings[ $post_type ];

			if ( self::plugin_exists( $plugin_slug ) ) {
				return array(
					'plugin_slug' => $plugin_slug,
					'method'      => 'dynamic_detection',
					'confidence'  => 'high',
				);
			}
		}

		// Strategy 2: Use Runtime_Tracker
		if ( class_exists( '\WPTSALL\Models\Scanners\Runtime_Tracker' ) ) {
			$source = Runtime_Tracker::get_post_type_source( $post_type );

			if ( $source && isset( $source['plugin_slug'] ) ) {
				return array(
					'plugin_slug' => $source['plugin_slug'],
					'method'      => 'runtime_tracker',
					'confidence'  => 'high',
					'file'        => isset( $source['file'] ) ? $source['file'] : '',
				);
			}
		}

		// Strategy 3: Fuzzy match against active plugins
		$fuzzy_result = self::fuzzy_match_plugin( $post_type );

		if ( $fuzzy_result ) {
			return array(
				'plugin_slug' => $fuzzy_result['plugin_slug'],
				'method'      => 'fuzzy_match',
				'confidence'  => $fuzzy_result['confidence'],
				'reason'      => $fuzzy_result['reason'],
			);
		}

		return null;
	}

	/**
	 * Resolve orphan taxonomy to a plugin
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array|null Resolution result or null.
	 */
	public static function resolve_taxonomy( $taxonomy ) {
		// Strategy 1: Use Runtime_Tracker
		if ( class_exists( '\WPTSALL\Models\Scanners\Runtime_Tracker' ) ) {
			$source = Runtime_Tracker::get_taxonomy_source( $taxonomy );

			if ( $source && isset( $source['plugin_slug'] ) ) {
				return array(
					'plugin_slug' => $source['plugin_slug'],
					'method'      => 'runtime_tracker',
					'confidence'  => 'high',
					'file'        => isset( $source['file'] ) ? $source['file'] : '',
				);
			}
		}

		// Strategy 2: Check associated post_types
		$tax_obj = get_taxonomy( $taxonomy );
		if ( $tax_obj && ! empty( $tax_obj->object_type ) ) {
			foreach ( (array) $tax_obj->object_type as $pt ) {
				// Try to resolve the associated post_type
				$pt_resolution = self::resolve_post_type( $pt );
				if ( $pt_resolution ) {
					return array(
						'plugin_slug' => $pt_resolution['plugin_slug'],
						'method'      => 'associated_post_type',
						'confidence'  => 'medium',
						'reason'      => 'Taxonomy is associated with post_type: ' . $pt,
					);
				}
			}
		}

		// Strategy 3: Fuzzy match against active plugins
		$fuzzy_result = self::fuzzy_match_plugin( $taxonomy );

		if ( $fuzzy_result ) {
			return array(
				'plugin_slug' => $fuzzy_result['plugin_slug'],
				'method'      => 'fuzzy_match',
				'confidence'  => $fuzzy_result['confidence'],
				'reason'      => $fuzzy_result['reason'],
			);
		}

		return null;
	}

	/**
	 * Fuzzy match a content type name against active plugins
	 *
	 * @param string $name Content type name (post_type or taxonomy).
	 * @return array|null Match result or null.
	 */
	public static function fuzzy_match_plugin( $name ) {
		$active_plugins = self::get_active_plugin_slugs();
		$name_lower = strtolower( $name );
		$name_parts = preg_split( '/[_\-]/', $name_lower );

		$best_match = null;
		$best_score = 0;

		foreach ( $active_plugins as $plugin_slug ) {
			$slug_lower = strtolower( $plugin_slug );
			$slug_parts = preg_split( '/[_\-]/', $slug_lower );

			$score = 0;
			$reason = '';

			// Exact slug match (very rare)
			if ( $name_lower === $slug_lower ) {
				return array(
					'plugin_slug' => $plugin_slug,
					'confidence'  => 'high',
					'reason'      => 'Exact match',
				);
			}

			// Check if any slug part is a prefix
			foreach ( $slug_parts as $part ) {
				if ( strlen( $part ) < 2 ) {
					continue;
				}

				// Check prefix match
				if ( strpos( $name_lower, $part ) === 0 ) {
					$score += 3;
					$reason = "Prefix match: '$part'";
				} elseif ( strpos( $name_lower, $part . '_' ) !== false || strpos( $name_lower, $part . '-' ) !== false ) {
					$score += 2;
					$reason = "Contains part: '$part'";
				}
			}

			// Check if name parts appear in slug
			foreach ( $name_parts as $part ) {
				if ( strlen( $part ) < 2 ) {
					continue;
				}

				if ( strpos( $slug_lower, $part ) !== false ) {
					$score += 1;
					if ( empty( $reason ) ) {
						$reason = "Slug contains: '$part'";
					}
				}
			}

			// Calculate string similarity for additional scoring
			similar_text( $name_lower, $slug_lower, $similarity );
			if ( $similarity > 50 ) {
				$score += ( $similarity - 50 ) / 25;
			}

			// Check Levenshtein distance for short names
			if ( strlen( $name_lower ) <= 10 && strlen( $slug_lower ) <= 20 ) {
				$lev = levenshtein( $name_lower, $slug_lower );
				if ( $lev <= 3 ) {
					$score += ( 4 - $lev );
					if ( empty( $reason ) ) {
						$reason = "Similar spelling (distance: $lev)";
					}
				}
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = array(
					'plugin_slug' => $plugin_slug,
					'confidence'  => $score >= 3 ? 'medium' : 'low',
					'reason'      => $reason,
					'score'       => $score,
				);
			}
		}

		// Only return if score is meaningful
		if ( $best_score >= 2 ) {
			return $best_match;
		}

		return null;
	}

	/**
	 * Get all active plugin slugs
	 *
	 * @return array Array of plugin slugs.
	 */
	protected static function get_active_plugin_slugs() {
		$slugs = array();

		$active_plugins = get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		foreach ( $active_plugins as $plugin_file ) {
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}
			$slugs[] = $slug;
		}

		return array_unique( $slugs );
	}

	/**
	 * Check if a plugin exists (by slug)
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return bool True if plugin exists.
	 */
	protected static function plugin_exists( $plugin_slug ) {
		$plugin_dir = wptsall_resolve_plugin_dir_by_slug( $plugin_slug );
		return is_dir( $plugin_dir );
	}

	/**
	 * Resolve all orphans and return results
	 *
	 * @return array Resolution results.
	 */
	public static function resolve_all_orphans() {
		$results = array(
			'post_types' => array(),
			'taxonomies' => array(),
			'unresolved' => array(
				'post_types' => array(),
				'taxonomies' => array(),
			),
		);

		// Resolve orphan post_types
		$orphan_pts = self::find_orphan_post_types();
		foreach ( $orphan_pts as $orphan ) {
			$resolution = self::resolve_post_type( $orphan['name'] );

			if ( $resolution ) {
				$results['post_types'][ $orphan['name'] ] = array_merge( $orphan, $resolution );
			} else {
				$results['unresolved']['post_types'][] = $orphan;
			}
		}

		// Resolve orphan taxonomies
		$orphan_taxes = self::find_orphan_taxonomies();
		foreach ( $orphan_taxes as $orphan ) {
			$resolution = self::resolve_taxonomy( $orphan['name'] );

			if ( $resolution ) {
				$results['taxonomies'][ $orphan['name'] ] = array_merge( $orphan, $resolution );
			} else {
				$results['unresolved']['taxonomies'][] = $orphan;
			}
		}

		return $results;
	}

	/**
	 * Apply resolved orphans to plugin mappings
	 *
	 * Updates the plugin_mappings table with resolved orphans.
	 *
	 * @param array $resolutions Resolution results from resolve_all_orphans().
	 * @return array Update results.
	 */
	public static function apply_resolutions( $resolutions ) {
		if ( ! class_exists( '\WPTSALL\Models\Services\Plugin_Mapping_Service' ) ) {
			return array( 'error' => 'Plugin_Mapping_Service not available' );
		}

		$updates = array(
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		// Group resolutions by plugin
		$by_plugin = array();

		foreach ( $resolutions['post_types'] as $pt => $data ) {
			$slug = $data['plugin_slug'];
			if ( ! isset( $by_plugin[ $slug ] ) ) {
				$by_plugin[ $slug ] = array( 'post_types' => array(), 'taxonomies' => array() );
			}
			$by_plugin[ $slug ]['post_types'][] = $pt;
		}

		foreach ( $resolutions['taxonomies'] as $tax => $data ) {
			$slug = $data['plugin_slug'];
			if ( ! isset( $by_plugin[ $slug ] ) ) {
				$by_plugin[ $slug ] = array( 'post_types' => array(), 'taxonomies' => array() );
			}
			$by_plugin[ $slug ]['taxonomies'][] = $tax;
		}

		// Update each plugin's mapping
		foreach ( $by_plugin as $plugin_slug => $new_types ) {
			$existing = Plugin_Mapping_Service::get_by_slug( $plugin_slug );

			if ( ! $existing ) {
				$updates['skipped']++;
				continue;
			}

			// Extract post_type names from detailed structure
			$current_pts = array();
			if ( ! empty( $existing['post_types'] ) && is_array( $existing['post_types'] ) ) {
				foreach ( $existing['post_types'] as $pt ) {
					$current_pts[] = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
				}
			}
			$current_pts = array_filter( $current_pts );

			// Extract taxonomy names from detailed structure
			$current_taxes = array();
			if ( ! empty( $existing['taxonomies'] ) && is_array( $existing['taxonomies'] ) ) {
				foreach ( $existing['taxonomies'] as $tax ) {
					$current_taxes[] = is_array( $tax ) ? ( $tax['name'] ?? '' ) : $tax;
				}
			}
			$current_taxes = array_filter( $current_taxes );

			$updated_pts = array_unique( array_merge( $current_pts, $new_types['post_types'] ) );
			$updated_taxes = array_unique( array_merge( $current_taxes, $new_types['taxonomies'] ) );

			// Only update if there are changes
			if ( count( $updated_pts ) !== count( $current_pts ) || count( $updated_taxes ) !== count( $current_taxes ) ) {
				// Use save() method with updated data
					$result = Plugin_Mapping_Service::save(
						array(
							'plugin_slug' => $plugin_slug,
							'post_types'  => $updated_pts,
							'taxonomies'  => $updated_taxes,
						)
					);

					if ( $result ) {
						// Keep plugin template objects in sync with the updated ownership mapping.
						// Note: orphan resolutions only have names (no supports/rewrite metadata).
						if ( class_exists( '\WPTSALL\Models\Services\Model_Object_Service' ) ) {
							\WPTSALL\Models\Services\Model_Object_Service::sync_from_scan(
								(int) $result,
								$plugin_slug,
								array(
									'post_types'  => $updated_pts,
									'taxonomies'  => $updated_taxes,
									'meta_fields' => $existing['meta_fields'] ?? array(),
								)
							);
						}

						$updates['updated']++;
					} else {
						$updates['errors'][] = "Failed to update: $plugin_slug";
					}
			} else {
				$updates['skipped']++;
			}
		}

		return $updates;
	}

	/**
	 * Add a known mapping
	 *
	 * @param string $content_type Content type name.
	 * @param string $plugin_slug  Plugin slug.
	 */
	public static function add_known_mapping( $content_type, $plugin_slug ) {
		// Add to source-detected mappings (runtime additions)
		if ( null === self::$source_detected_mappings ) {
			self::$source_detected_mappings = array();
		}
		self::$source_detected_mappings[ $content_type ] = $plugin_slug;
	}

	/**
	 * Get all known mappings
	 *
	 * Returns source-detected mappings merged with dynamically detected mappings.
	 * All mappings are now dynamic - no hardcoded values.
	 *
	 * @return array Known mappings.
	 */
	public static function get_known_mappings() {
		// Start with source-code detected mappings
		$mappings = self::get_source_detected_mappings();

		// Merge with dynamic mappings (CPTUI, Pods, Toolset, ACF)
		$dynamic = self::get_dynamic_mappings();
		if ( ! empty( $dynamic ) ) {
			$mappings = array_merge( $mappings, $dynamic );
		}

		return $mappings;
	}

	/**
	 * Get dynamically detected plugin → post_type mappings
	 *
	 * Detects post_types from:
	 * - Custom Post Type UI (cptui_post_types option)
	 * - Pods (pods_pods option)
	 * - Toolset Types (wpcf-custom-types option)
	 * - ACF (acf_post_types option in ACF 6.1+)
	 *
	 * @return array Dynamic mappings (post_type => plugin_slug).
	 */
	public static function get_dynamic_mappings() {
		if ( null !== self::$dynamic_mappings ) {
			return self::$dynamic_mappings;
		}

		self::$dynamic_mappings = array();

		// 1. Custom Post Type UI
		$cptui_types = self::get_cptui_types();
		foreach ( $cptui_types as $pt ) {
			self::$dynamic_mappings[ $pt ] = 'custom-post-type-ui';
		}

		// 2. Pods
		$pods_types = self::get_pods_types();
		foreach ( $pods_types as $pt ) {
			// Don't override if already set by CPTUI
			if ( ! isset( self::$dynamic_mappings[ $pt ] ) ) {
				self::$dynamic_mappings[ $pt ] = 'pods';
			}
		}

		// 3. Toolset Types
		$toolset_types = self::get_toolset_types();
		foreach ( $toolset_types as $pt ) {
			if ( ! isset( self::$dynamic_mappings[ $pt ] ) ) {
				self::$dynamic_mappings[ $pt ] = 'types';
			}
		}

		// 4. ACF Extended
		$acf_types = self::get_acf_types();
		foreach ( $acf_types as $pt ) {
			if ( ! isset( self::$dynamic_mappings[ $pt ] ) ) {
				self::$dynamic_mappings[ $pt ] = 'advanced-custom-fields';
			}
		}

		return self::$dynamic_mappings;
	}

	/**
	 * Get post_types created by Custom Post Type UI
	 *
	 * @return array Array of post_type names.
	 */
	public static function get_cptui_types() {
		$types = array();

		$cptui_option = get_option( 'cptui_post_types', array() );

		if ( ! empty( $cptui_option ) && is_array( $cptui_option ) ) {
			$types = array_keys( $cptui_option );
		}

		return $types;
	}

	/**
	 * Get post_types created by Pods
	 *
	 * @return array Array of post_type names.
	 */
	protected static function get_pods_types() {
		$types = array();

		// Pods stores config in pods_pods option or pods table
		$pods_option = get_option( 'pods_pods', array() );

		if ( ! empty( $pods_option ) && is_array( $pods_option ) ) {
			foreach ( $pods_option as $pod ) {
				if ( isset( $pod['type'] ) && 'post_type' === $pod['type'] && isset( $pod['name'] ) ) {
					$types[] = $pod['name'];
				}
			}
		}

		return $types;
	}

	/**
	 * Get post_types created by Toolset Types
	 *
	 * @return array Array of post_type names.
	 */
	protected static function get_toolset_types() {
		$types = array();

		$toolset_option = get_option( 'wpcf-custom-types', array() );

		if ( ! empty( $toolset_option ) && is_array( $toolset_option ) ) {
			$types = array_keys( $toolset_option );
		}

		return $types;
	}

	/**
	 * Get post_types created by ACF (6.1+)
	 *
	 * @return array Array of post_type names.
	 */
	protected static function get_acf_types() {
		$types = array();

		// ACF 6.1+ stores post types in acf-post-type posts
		if ( post_type_exists( 'acf-post-type' ) ) {
			$acf_posts = get_posts(
				array(
					'post_type'      => 'acf-post-type',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				)
			);

			foreach ( $acf_posts as $acf_post ) {
				$settings = get_post_meta( $acf_post->ID, 'acf_post_type_settings', true );
				if ( ! empty( $settings['post_type'] ) ) {
					$types[] = $settings['post_type'];
				}
			}
		}

		return $types;
	}

	/**
	 * Get taxonomies created by Custom Post Type UI
	 *
	 * @return array Array of taxonomy names.
	 */
	public static function get_cptui_taxonomies() {
		$taxonomies = array();

		$cptui_option = get_option( 'cptui_taxonomies', array() );

		if ( ! empty( $cptui_option ) && is_array( $cptui_option ) ) {
			$taxonomies = array_keys( $cptui_option );
		}

		return $taxonomies;
	}

	/**
	 * Clear dynamic mappings cache
	 *
	 * Call this when plugins are activated/deactivated or options change.
	 */
	public static function clear_cache() {
		self::$dynamic_mappings = null;
		self::$source_detected_mappings = null;
	}
}
