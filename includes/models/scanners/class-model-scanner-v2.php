<?php
/**
 * WPTSALL Model Scanner V2
 *
 * New model scanner, generates complete translation relation chains
 * Relation chain: Frontend URL <-> Data source <-> Translation/sync fields <-> Backend URL
 *
 * @package WPTSALL
 * @since 0.4.0
 */

namespace WPTSALL\Models\Scanners;

use WPTSALL\Core\Smart_Field_Classifier;
use WPTSALL\Models\Services\Template_Sync_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model Scanner V2 Class
 */
class Model_Scanner_V2 {

	/**
	 * Scanner version
	 */
	const VERSION = '2.0.0';

	/**
	 * Scan mode: 'full' or 'incremental'.
	 *
	 * - full:        Trigger A (plugin activation / first scan).
	 *                Full scan -> SSOT full sync -> auto-create translation rules.
	 * - incremental: Trigger B/C (manual "Scan All" or single model rescan).
	 *                Scan -> SSOT incremental append -> do NOT create/delete rules.
	 *
	 * Default is 'incremental' (safe default — never auto-creates rules).
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private string $mode = 'incremental';

	/**
	 * Set the scan mode.
	 *
	 * @since 1.2.0
	 *
	 * @param string $mode 'full' or 'incremental'.
	 * @return void
	 */
	public function set_mode( string $mode ): void {
		$this->mode = in_array( $mode, array( 'full', 'incremental' ), true ) ? $mode : 'incremental';
	}

	/**
	 * Currently scanning plugin slug
	 *
	 * @var string
	 */
	private $current_plugin_slug = '';

	/**
	 * Current plugin meta_fields (from plugin_mappings)
	 *
	 * @var array
	 */
	private $current_meta_fields = array();

	/**
	 * Default translation fields (by data type)
	 *
	 * @var array
	 */
	private static $default_translate_fields = array(
		'post'    => array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ),
		'term'    => array( 'name', 'description', 'slug' ),
		'user'    => array( 'display_name' ),
		'comment' => array( 'comment_content', 'comment_author' ),
	);

	/**
	 * Default sync fields (by data type)
	 * Note: ID reference fields (e.g. _thumbnail_id, post_author) should be in ID mapping fields
	 *
	 * @var array
	 */
	private static $default_sync_fields = array(
		'post'    => array( 'post_date', 'post_status', 'menu_order', 'comment_status', 'ping_status' ),
		'term'    => array(),
		'user'    => array( 'user_email', 'user_url' ),
		'comment' => array( 'comment_date', 'comment_type' ),
	);

	/**
	 * Default ID mapping fields (need to find corresponding ID on target site)
	 *
	 * @var array
	 * @since 0.8.0 Added post_author as user ID mapping field.
	 */
	private static $default_id_mapping_fields = array(
		'post'    => array( '_thumbnail_id', 'post_parent', 'post_author' ),
		'term'    => array( 'parent' ),
		'user'    => array(),
		'comment' => array( 'comment_post_ID', 'user_id' ),
	);

	/**
	 * Default compute fields (need to be regenerated from other fields)
	 *
	 * @var array
	 */
	private static $default_compute_fields = array(
		'post' => array( 'guid' ),
		'term' => array(),
		'user' => array(),
	);

	/**
	 * Scan a single plugin and generate model and rules
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array|WP_Error Scan results or error.
	 */
	public function scan_plugin( $plugin_slug ) {
		$start_time = microtime( true );

		wptsall_log_info(
			'models-scanner',
			'Starting plugin scan',
			array( 'plugin' => $plugin_slug )
		);

		// Set current plugin slug and load meta_fields
		$this->current_plugin_slug = $plugin_slug;
		$this->load_plugin_meta_fields( $plugin_slug );

		// Get plugin info
		$plugin_info = $this->get_plugin_info( $plugin_slug );
		if ( is_wp_error( $plugin_info ) ) {
			wptsall_log_debug(
				'models-scanner',
				'Plugin info not found',
				array(
					'plugin' => $plugin_slug,
					'error'  => $plugin_info->get_error_message(),
				)
			);
			return $plugin_info;
		}

		// Get post_types and taxonomies registered by plugin
		$post_types = $this->get_plugin_post_types( $plugin_slug );
		$taxonomies = $this->get_plugin_taxonomies( $plugin_slug );

		if ( empty( $post_types ) && empty( $taxonomies ) ) {
			wptsall_log_debug(
				'models-scanner',
				'No content types found',
				array( 'plugin' => $plugin_slug )
			);
			return new \WP_Error(
				'no_content_types',
				sprintf( 'Plugin %s has no registered content types', $plugin_slug )
			);
		}

		wptsall_log_debug(
			'models-scanner',
			'Discovered content types',
			array(
				'plugin'     => $plugin_slug,
				'post_types' => $post_types,
				'taxonomies' => $taxonomies,
			)
		);

		// Build model data
		$model = array(
			'plugin_slug'    => $plugin_slug,
			'plugin_name'    => $plugin_info['name'],
			'plugin_version' => $plugin_info['version'],
			'text_domain'    => $plugin_info['text_domain'],
			'description'    => $plugin_info['description'],
			'post_types'     => $post_types,
			'taxonomies'     => $taxonomies,
			'status'         => 'active',
			'scan_version'   => self::VERSION,
		);

		// Discovery payload for plugin template (SSOT): multi-source field coverage.
		// This is independent from translation rule generation and is the primary
		// input for model_objects/model_object_fields synchronization.
		$discovery_fields = $this->build_discovery_fields_payload( $post_types, $taxonomies );

		// Generate translation rules
		$rules = array();

		// Generate rules for each post_type
		foreach ( $post_types as $post_type ) {
			$pt_name         = sanitize_key( is_array( $post_type ) ? (string) ( $post_type['name'] ?? '' ) : (string) $post_type );
			$template_fields = is_array( $discovery_fields['post_type'][ $pt_name ] ?? null ) ? $discovery_fields['post_type'][ $pt_name ] : array();
			$pt_rules        = $this->generate_post_type_rules( $post_type, $plugin_slug, $template_fields );
			$rules    = array_merge( $rules, $pt_rules );
		}

		// Generate rules for each taxonomy
		foreach ( $taxonomies as $taxonomy ) {
			$tax_name        = sanitize_key( is_array( $taxonomy ) ? (string) ( $taxonomy['name'] ?? '' ) : (string) $taxonomy );
			$template_fields = is_array( $discovery_fields['taxonomy'][ $tax_name ] ?? null ) ? $discovery_fields['taxonomy'][ $tax_name ] : array();
			$tax_rules       = $this->generate_taxonomy_rules( $taxonomy, $plugin_slug, $template_fields );
			$rules     = array_merge( $rules, $tax_rules );
		}

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );
		$discovered_field_count = 0;
		foreach ( $discovery_fields as $by_type ) {
			if ( ! is_array( $by_type ) ) {
				continue;
			}
			foreach ( $by_type as $object_fields ) {
				if ( is_array( $object_fields ) ) {
					$discovered_field_count += count( $object_fields );
				}
			}
		}
		wptsall_log_info(
			'models-scanner',
			'Plugin scan completed',
			array(
				'plugin'                => $plugin_slug,
				'post_types'            => count( $post_types ),
				'taxonomies'            => count( $taxonomies ),
				'discovered_field_count'=> $discovered_field_count,
				'rules_count'           => count( $rules ),
				'duration_ms'           => $duration_ms,
			)
		);

		// Build scan_result: raw facts from the scan for persistence and L2 validation.
		$scan_result_data = array(
			'scan_version'   => self::VERSION,
			'scan_timestamp' => current_time( 'mysql' ),
			'duration_ms'    => $duration_ms,
			'post_types'     => $post_types,
			'taxonomies'     => $taxonomies,
			'discovery_fields' => $discovery_fields,
			'rules_summary'  => array(),
		);
		foreach ( $rules as $rule ) {
			$scan_result_data['rules_summary'][] = array(
				'object_name'        => $rule['object_name'],
				'data_type'          => $rule['data_type'],
				'field_capabilities' => $rule['field_capabilities'] ?? array(),
				'related_taxonomies' => $rule['related_taxonomies'] ?? array(),
			);
		}

		return array(
			'model'       => $model,
			'rules'       => $rules,
			'scan_result' => $scan_result_data,
		);
	}

	/**
	 * Get plugin info
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array|WP_Error
	 */
	private function get_plugin_info( $plugin_slug ) {
		// Special case for 'wordpress-blog' - WordPress Core
		if ( 'wordpress-blog' === $plugin_slug ) {
			global $wp_version;
			return array(
				'name'        => 'WordPress Blog',
				'version'     => $wp_version,
				'text_domain' => 'default',
				'description' => 'WordPress Core blog functionality',
				'file'        => 'wordpress-core',
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( strpos( $plugin_file, $plugin_slug . '/' ) === 0 ||
				$plugin_file === $plugin_slug . '.php' ) {
				return array(
					'name'        => $plugin_data['Name'],
					'version'     => $plugin_data['Version'],
					'text_domain' => $plugin_data['TextDomain'] ?? $plugin_slug,
					'description' => $plugin_data['Description'] ?? '',
					'file'        => $plugin_file,
				);
			}
		}

		return new \WP_Error( 'plugin_not_found', 'Plugin not found: ' . $plugin_slug );
	}

	/**
	 * Check if post type has publicly accessible frontend URL
	 *
	 * Logic consistent with seeding script (seed-content.php):
	 * - publicly_queryable = true (has frontend URL routing)
	 * - or public = true with rewrite rules
	 *
	 * @param string $post_type Post type name.
	 * @return bool True if post type has public frontend URL.
	 */
	private function is_publicly_accessible( $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return false;
		}

		$obj = get_post_type_object( $post_type );
		if ( ! $obj ) {
			return false;
		}

		// Must be publicly queryable (has frontend URL routing)
		if ( $obj->publicly_queryable ) {
			return true;
		}

		// Or public with rewrite rules
		if ( $obj->public && ! empty( $obj->rewrite ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get post_types registered by plugin
	 *
	 * Merge strategy (Plan A + B compatible):
	 * 1. From plugin_mappings table (Plan A: source code analysis)
	 * 2. From Runtime_Tracker (Plan B: runtime tracking)
	 * 3. From prefix matching (fallback)
	 * 4. Union of all three to avoid missing any
	 *
	 * Note: Excluded system post types are filtered out, but no public-accessibility check.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	private function get_plugin_post_types( $plugin_slug ) {
		// Special case for 'wordpress-blog' - WordPress Core
		if ( 'wordpress-blog' === $plugin_slug ) {
			return array( 'post', 'page' );
		}

		$all_found_types = array();

		// 1. From plugin_mappings table (Plan A: source code analysis)
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) ) {
			$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( $plugin_slug );
			if ( $mapping && ! empty( $mapping['post_types'] ) ) {
				// Extract post_type names from the detailed structure
				// plugin_mappings stores [{name: 'post', ...}, ...] or ['post', ...]
				foreach ( $mapping['post_types'] as $pt ) {
					if ( is_array( $pt ) && isset( $pt['name'] ) ) {
						$all_found_types[] = $pt['name'];
					} elseif ( is_string( $pt ) ) {
						$all_found_types[] = $pt;
					}
				}
			}
		}

		// 2. From Runtime_Tracker (Plan B: runtime tracking)
		if ( class_exists( __NAMESPACE__ . '\\Runtime_Tracker' ) ) {
			$tracked = Runtime_Tracker::get_post_types_by_plugin( $plugin_slug );
			if ( ! empty( $tracked ) ) {
				$all_found_types = array_merge( $all_found_types, $tracked );
			}
		}

		// 3. Prefix matching (fallback)
		$all_post_types = get_post_types( array(), 'objects' );
		$prefixes       = $this->get_plugin_prefixes( $plugin_slug );

		foreach ( $all_post_types as $pt_name => $pt_obj ) {
			// Skip core types
			if ( in_array( $pt_name, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}

			foreach ( $prefixes as $prefix ) {
				if ( strpos( $pt_name, $prefix ) === 0 ) {
					$all_found_types[] = $pt_name;
					break;
				}
			}
		}

		// 3b. Runtime authority / known CPT map (SSP "podcast", etc.)
		// Static source analysis and prefix matching miss plugins that register
		// CPTs under unrelated names; Plugin_Scanner owns the shared heuristics.
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Scanner' ) ) {
			$runtime_probe = \WPTSALL\Models\Services\Plugin_Scanner::enrich_scan_with_runtime_authority(
				array(
					'post_types'  => array(),
					'meta_fields' => array(),
				),
				$plugin_slug
			);
			foreach ( (array) ( $runtime_probe['post_types'] ?? array() ) as $pt ) {
				$name = is_array( $pt ) ? (string) ( $pt['name'] ?? '' ) : (string) $pt;
				if ( '' !== $name ) {
					$all_found_types[] = $name;
				}
			}
		}

		// 4. Deduplicate and filter out excluded system types.
		$unique_types = array_unique( $all_found_types );
		$excluded     = \WPTSALL\Models\Services\Plugin_Scanner::get_excluded_post_types();
		$valid_types  = array_filter(
			$unique_types,
			function ( $pt_name ) use ( $excluded ) {
				return ! in_array( $pt_name, $excluded, true );
			}
		);

		return array_values( $valid_types );
	}

	/**
	 * Check if taxonomy has publicly accessible frontend URL
	 *
	 * Same filtering logic as post_type:
	 * - publicly_queryable = true (has frontend archive pages)
	 * - or public = true with rewrite rules
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool True if taxonomy has public frontend URL.
	 */
	private function is_taxonomy_publicly_accessible( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$obj = get_taxonomy( $taxonomy );
		if ( ! $obj ) {
			return false;
		}

		// Must be publicly queryable (has frontend archive pages)
		if ( $obj->publicly_queryable ) {
			return true;
		}

		// Or public with rewrite rules
		if ( $obj->public && ! empty( $obj->rewrite ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get taxonomies registered by plugin
	 *
	 * Merge strategy (Plan A + B compatible):
	 * 1. From plugin_mappings table (Plan A: source code analysis)
	 * 2. From Runtime_Tracker (Plan B: runtime tracking)
	 * 3. From prefix matching (fallback)
	 * 4. Union of all three to avoid missing any
	 *
	 * Note: No public-accessibility filter — any plugin-registered taxonomy is valid.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	private function get_plugin_taxonomies( $plugin_slug ) {
		// Special case for 'wordpress-blog' - WordPress Core
		if ( 'wordpress-blog' === $plugin_slug ) {
			return array( 'category', 'post_tag' );
		}

		$all_found_taxonomies = array();

		// 1. From plugin_mappings table (Plan A: source code analysis)
		if ( class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) ) {
			$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( $plugin_slug );
			if ( $mapping && ! empty( $mapping['taxonomies'] ) ) {
				// Extract taxonomy names from the detailed structure
				// plugin_mappings stores [{name: 'category', ...}, ...] or ['category', ...]
				foreach ( $mapping['taxonomies'] as $tax ) {
					if ( is_array( $tax ) && isset( $tax['name'] ) ) {
						$all_found_taxonomies[] = $tax['name'];
					} elseif ( is_string( $tax ) ) {
						$all_found_taxonomies[] = $tax;
					}
				}
			}
		}

		// 2. From Runtime_Tracker (Plan B: runtime tracking)
		if ( class_exists( __NAMESPACE__ . '\\Runtime_Tracker' ) ) {
			$tracked = Runtime_Tracker::get_taxonomies_by_plugin( $plugin_slug );
			if ( ! empty( $tracked ) ) {
				$all_found_taxonomies = array_merge( $all_found_taxonomies, $tracked );
			}
		}

		// 3. Prefix matching (fallback)
		$all_taxonomies = get_taxonomies( array(), 'objects' );
		$prefixes       = $this->get_plugin_prefixes( $plugin_slug );

		foreach ( $all_taxonomies as $tax_name => $tax_obj ) {
			// Skip core types
			if ( in_array( $tax_name, array( 'category', 'post_tag', 'post_format' ), true ) ) {
				continue;
			}

			foreach ( $prefixes as $prefix ) {
				if ( strpos( $tax_name, $prefix ) === 0 ) {
					$all_found_taxonomies[] = $tax_name;
					break;
				}
			}
		}

		// 4. Deduplicate — no public-accessibility filter; any plugin-registered taxonomy is valid.
		$unique_taxonomies = array_unique( $all_found_taxonomies );

		return array_values( $unique_taxonomies );
	}

	/**
	 * Get possible plugin prefixes (basic matching)
	 *
	 * Note: This is a fallback. Data from plugin_mappings table takes priority.
	 * Only generates basic prefixes based on plugin slug, no more hardcoded mappings.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array
	 */
	private function get_plugin_prefixes( $plugin_slug ) {
		$prefixes = array();

		// Original slug
		$prefixes[] = $plugin_slug;

		// Remove -pro, -premium etc. suffixes
		$clean_slug = preg_replace( '/-(pro|premium|free|lite)$/', '', $plugin_slug );
		if ( $clean_slug !== $plugin_slug ) {
			$prefixes[] = $clean_slug;
		}

		// Underscore/hyphen variants
		$underscore = str_replace( '-', '_', $plugin_slug );
		$hyphen     = str_replace( '_', '-', $plugin_slug );

		$prefixes[] = $underscore;
		$prefixes[] = $hyphen;
		$prefixes[] = $underscore . '_';
		$prefixes[] = $hyphen . '-';

		// Abbreviation (e.g. easy-digital-downloads -> edd).
		$words = preg_split( '/[-_]/', $plugin_slug );
		$words = array_values(
			array_filter(
				(array) $words,
				static function ( $word ) {
					return '' !== (string) $word;
				}
			)
		);
		if ( count( $words ) > 1 ) {
			$abbrev = '';
			foreach ( $words as $word ) {
				if ( ! empty( $word ) ) {
					$abbrev .= $word[0];
				}
			}
			if ( strlen( $abbrev ) >= 2 && strlen( $abbrev ) <= 4 ) {
				$prefixes[] = $abbrev;
				$prefixes[] = $abbrev . '_';
			}
		}

		// Primary segment fallback (e.g. envira-gallery-lite -> envira).
		if ( ! empty( $words ) ) {
			$primary = sanitize_key( (string) $words[0] );
			if ( strlen( $primary ) >= 3 ) {
				$prefixes[] = $primary;
				$prefixes[] = $primary . '_';
				$prefixes[] = $primary . '-';
			}

			// Common WordPress style: wp + initials (e.g. wp-recipe-maker -> wprm).
			if ( 'wp' === $primary && count( $words ) > 1 ) {
				$wp_abbrev = 'wp';
				for ( $i = 1; $i < count( $words ); $i++ ) {
					$segment = (string) $words[ $i ];
					if ( '' !== $segment ) {
						$wp_abbrev .= $segment[0];
					}
				}
				if ( strlen( $wp_abbrev ) >= 3 ) {
					$prefixes[] = $wp_abbrev;
					$prefixes[] = $wp_abbrev . '_';
				}
			}
		}

		// Singular fallback (e.g. site-reviews -> site-review).
		if ( 's' === substr( $plugin_slug, -1 ) ) {
			$singular_slug = rtrim( $plugin_slug, 's' );
			if ( '' !== $singular_slug && $singular_slug !== $plugin_slug ) {
				$prefixes[] = $singular_slug;
				$prefixes[] = str_replace( '-', '_', $singular_slug );
				$prefixes[] = str_replace( '_', '-', $singular_slug );
			}
		}

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Generate translation rules for post_type
	 *
	 * @param string $post_type       Post type name.
	 * @param string $plugin_slug     Plugin slug.
	 * @param array  $template_fields Plugin-template fields for this post type.
	 * @return array Rules array.
	 */
	private function generate_post_type_rules( $post_type, $plugin_slug, array $template_fields = array() ) {
		$rules   = array();
		$pt_obj  = get_post_type_object( $post_type );

		if ( ! $pt_obj ) {
			return $rules;
		}

		// Determine URL pattern based on rewrite / publicly_queryable status.
		$has_rewrite = ! empty( $pt_obj->rewrite['slug'] );
		if ( $has_rewrite ) {
			$url_slug    = $pt_obj->rewrite['slug'];
			$url_pattern = '/' . $url_slug . '/{slug}/';
			$example_url = '/' . $url_slug . '/sample-' . $post_type . '/';
		} else {
			// No rewrite rules — use query-string format.
			$url_slug    = $post_type;
			$url_pattern = '/?post_type=' . $post_type . '&p={id}';
			$example_url = '/?post_type=' . $post_type . '&p=1';
		}

		// Get related taxonomies
		$related_taxonomies = get_object_taxonomies( $post_type );

		$field_capabilities = $this->build_field_capabilities_from_template_fields( $template_fields, 'post' );
		if ( empty( $field_capabilities ) ) {
			// Backward-compatible fallback when template fields are unavailable.
			$translate_fields = $this->get_translate_fields( 'post', $post_type );
			$sync_fields      = $this->get_sync_fields( 'post', $post_type );
			$field_mappings   = $this->get_id_mapping_fields( 'post', $post_type );
			$compute_fields   = $this->get_compute_fields( 'post', $post_type );
			$field_capabilities = $this->build_field_capabilities(
				$translate_fields,
				$sync_fields,
				$field_mappings,
				$compute_fields
			);
		}

		// Generate single page rule
		$rules[] = array(
			'name'               => $pt_obj->label . ' ' . __( 'Single', 'wpmmcc-ats' ),
			'url_pattern'        => $url_pattern,
			'url_type'           => 'single',
			'requires_login'     => ! $pt_obj->publicly_queryable,
			'example_url'        => $example_url,
			'data_type'          => 'post',
			'object_name'        => $post_type,
			'primary_table'      => 'wp_posts',
			'meta_table'         => 'wp_postmeta',
			'direction'          => 'source_to_target',
			// Product decision (2026-01-29): sync_mode is fixed to "new_only".
			'sync_mode'          => 'new_only',
			'field_capabilities' => $field_capabilities,
			'backend_edit'       => '/wp-admin/post.php?post={id}&action=edit',
			'backend_list'       => '/wp-admin/edit.php?post_type=' . $post_type,
			'backend_new'        => '/wp-admin/post-new.php?post_type=' . $post_type,
			'related_taxonomies' => $related_taxonomies,
			'priority'           => 10,
			'is_active'          => true,
			'auto_detected'      => true,
		);

		// If has archive, generate archive rule
		if ( $pt_obj->has_archive ) {
			$archive_slug = is_string( $pt_obj->has_archive ) ? $pt_obj->has_archive : $url_slug;
			if ( $has_rewrite ) {
				$archive_url_pattern = '/' . $archive_slug . '/';
				$archive_example_url = '/' . $archive_slug . '/';
			} else {
				$archive_url_pattern = '/?post_type=' . $post_type;
				$archive_example_url = '/?post_type=' . $post_type;
			}
			$rules[] = array(
				'name'               => $pt_obj->label . ' ' . __( 'Archive', 'wpmmcc-ats' ),
				'url_pattern'        => $archive_url_pattern,
				'url_type'           => 'archive',
				'requires_login'     => ! $pt_obj->publicly_queryable,
				'example_url'        => $archive_example_url,
				'data_type'          => 'post',
				'object_name'        => $post_type,
				'primary_table'      => 'wp_posts',
				'meta_table'         => null,
				'direction'          => 'source_to_target',
				// Product decision (2026-01-29): sync_mode is fixed to "new_only".
				'sync_mode'          => 'new_only',
				'field_capabilities' => array(),
				'backend_edit'       => null,
				'backend_list'       => '/wp-admin/edit.php?post_type=' . $post_type,
				'backend_new'        => null,
				'related_taxonomies' => $related_taxonomies,
				'priority'           => 20,
				'is_active'          => true,
				'auto_detected'      => true,
			);
		}

		return $rules;
	}

	/**
	 * Generate translation rules for taxonomy
	 *
	 * @param string $taxonomy        Taxonomy name.
	 * @param string $plugin_slug     Plugin slug.
	 * @param array  $template_fields Plugin-template fields for this taxonomy.
	 * @return array Rules array.
	 */
	private function generate_taxonomy_rules( $taxonomy, $plugin_slug, array $template_fields = array() ) {
		$rules   = array();
		$tax_obj = get_taxonomy( $taxonomy );

		if ( ! $tax_obj ) {
			return $rules;
		}

		// Determine URL pattern based on rewrite status.
		$has_rewrite = ! empty( $tax_obj->rewrite['slug'] );
		if ( $has_rewrite ) {
			$url_slug    = $tax_obj->rewrite['slug'];
			$url_pattern = '/' . $url_slug . '/{slug}/';
			$example_url = '/' . $url_slug . '/sample-term/';
		} else {
			$url_slug    = $taxonomy;
			$url_pattern = '/?taxonomy=' . $taxonomy . '&term={slug}';
			$example_url = '/?taxonomy=' . $taxonomy . '&term=sample-term';
		}

		$field_capabilities = $this->build_field_capabilities_from_template_fields( $template_fields, 'term' );
		if ( empty( $field_capabilities ) ) {
			// Backward-compatible fallback when template fields are unavailable.
			$translate_fields = $this->get_translate_fields( 'term', $taxonomy );
			$sync_fields      = $this->get_sync_fields( 'term', $taxonomy );
			$field_mappings   = $this->get_id_mapping_fields( 'term', $taxonomy );
			$compute_fields   = $this->get_compute_fields( 'term', $taxonomy );
			$field_capabilities = $this->build_field_capabilities(
				$translate_fields,
				$sync_fields,
				$field_mappings,
				$compute_fields
			);
		}

		// Generate taxonomy archive rule
		$rules[] = array(
			'name'               => $tax_obj->label . ' ' . __( 'Taxonomy', 'wpmmcc-ats' ),
			'url_pattern'        => $url_pattern,
			'url_type'           => 'taxonomy',
			'requires_login'     => ! $tax_obj->publicly_queryable,
			'example_url'        => $example_url,
			'data_type'          => 'term',
			'object_name'        => $taxonomy,
			'primary_table'      => 'wp_terms',
			'meta_table'         => 'wp_termmeta',
			'direction'          => 'source_to_target',
			// Product decision (2026-01-29): sync_mode is fixed to "new_only".
			'sync_mode'          => 'new_only',
			'field_capabilities' => $field_capabilities,
			'backend_edit'       => '/wp-admin/term.php?taxonomy=' . $taxonomy . '&tag_ID={id}',
			'backend_list'       => '/wp-admin/edit-tags.php?taxonomy=' . $taxonomy,
			'backend_new'        => null,
			'related_taxonomies' => array(),
			'priority'           => 15,
			'is_active'          => true,
			'auto_detected'      => true,
		);

		return $rules;
	}

	/**
	 * Build multi-source discovery fields payload for template synchronization.
	 *
	 * This payload is scanner-layer output for plugin template creation
	 * (model_objects/model_object_fields). It intentionally separates field
	 * discovery from translation rule generation.
	 *
	 * @since 1.6.0
	 *
	 * @param array $post_types Post type names.
	 * @param array $taxonomies Taxonomy names.
	 * @return array {
	 *   @type array $post_type [post_type => field[]]
	 *   @type array $taxonomy  [taxonomy => field[]]
	 * }
	 */
	private function build_discovery_fields_payload( array $post_types, array $taxonomies ): array {
		$payload = array(
			'post_type' => array(),
			'taxonomy'  => array(),
		);

		foreach ( $post_types as $post_type ) {
			$pt_name = sanitize_key( is_array( $post_type ) ? (string) ( $post_type['name'] ?? '' ) : (string) $post_type );
			if ( '' === $pt_name ) {
				continue;
			}
			$payload['post_type'][ $pt_name ] = $this->discover_fields_for_post_type( $pt_name );
		}

		foreach ( $taxonomies as $taxonomy ) {
			$tax_name = sanitize_key( is_array( $taxonomy ) ? (string) ( $taxonomy['name'] ?? '' ) : (string) $taxonomy );
			if ( '' === $tax_name ) {
				continue;
			}
			$payload['taxonomy'][ $tax_name ] = $this->discover_fields_for_taxonomy( $tax_name );
		}

		return $payload;
	}

	/**
	 * Discover fields for a post type from multiple sources.
	 *
	 * Sources combined:
	 * - Runtime supports/core mapping
	 * - Runtime sampled meta + classifier results
	 * - Mapping scanner meta candidates
	 * - wpml-config.xml field declarations
	 *
	 * @since 1.6.0
	 *
	 * @param string $post_type Post type name.
	 * @return array
	 */
	private function discover_fields_for_post_type( string $post_type ): array {
		$field_map = array();

		// 0) Core post schema baseline (not tied to supports but required by pipeline).
		$core_baseline_fields = array(
			array(
				'field_kind'    => 'core',
				'field_key'     => 'post_date',
				'data_type'     => 'datetime',
				'source_origin' => 'post_core_baseline',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'post_status',
				'data_type'     => 'text',
				'source_origin' => 'post_core_baseline',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'menu_order',
				'data_type'     => 'numeric',
				'source_origin' => 'post_core_baseline',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'comment_status',
				'data_type'     => 'text',
				'source_origin' => 'post_core_baseline',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'ping_status',
				'data_type'     => 'text',
				'source_origin' => 'post_core_baseline',
			),
			array(
				'field_kind'       => 'core',
				'field_key'        => 'post_parent',
				'data_type'        => 'id_ref',
				'reference_type'   => 'post',
				'reference_target' => 'self',
				'source_origin'    => 'post_core_baseline',
			),
			array(
				'field_kind'       => 'core',
				'field_key'        => 'post_author',
				'data_type'        => 'id_ref',
				'reference_type'   => 'user',
				'reference_target' => 'user',
				'source_origin'    => 'post_core_baseline',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'post_name',
				'data_type'     => 'slug',
				'source_origin' => 'post_core_baseline',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'guid',
				'data_type'     => 'slug',
				'source_origin' => 'post_core_baseline',
			),
		);
		foreach ( $core_baseline_fields as $field ) {
			$this->add_discovery_field( $field_map, $field );
		}

		// 1) Runtime supports -> core baseline (non-heuristic WP API source).
		$supports = function_exists( 'get_all_post_type_supports' ) ? get_all_post_type_supports( $post_type ) : array();
		$supports = is_array( $supports ) ? array_keys( $supports ) : array();
		$support_map = array(
			'title'     => array(
				'field_kind'    => 'core',
				'field_key'     => 'post_title',
				'data_type'     => 'text',
				'source_origin' => 'supports',
			),
			'editor'    => array(
				'field_kind'    => 'core',
				'field_key'     => 'post_content',
				'data_type'     => 'html',
				'source_origin' => 'supports',
			),
			'excerpt'   => array(
				'field_kind'    => 'core',
				'field_key'     => 'post_excerpt',
				'data_type'     => 'text',
				'source_origin' => 'supports',
			),
			'thumbnail' => array(
				'field_kind'       => 'meta',
				'field_key'        => '_thumbnail_id',
				'data_type'        => 'id_ref',
				'reference_type'   => 'media',
				'reference_target' => 'attachment',
				'source_origin'    => 'supports',
			),
		);
		foreach ( $supports as $support ) {
			$support = sanitize_key( (string) $support );
			if ( isset( $support_map[ $support ] ) ) {
				$this->add_discovery_field( $field_map, $support_map[ $support ] );
			}
		}

		// 2) Runtime sampled meta keys (high coverage).
		$smart_result = $this->smart_classify_all_fields( 'post', $post_type );
		foreach ( array( 'translate', 'sync', 'id_mapping' ) as $cap_type ) {
			$keys = is_array( $smart_result[ $cap_type ] ?? null ) ? $smart_result[ $cap_type ] : array();
			foreach ( $keys as $meta_key ) {
				$schema = $this->capability_to_template_schema( (string) $meta_key, $cap_type, 'post' );
				$this->add_discovery_field(
					$field_map,
					array_merge(
						array(
							'field_kind'    => 'meta',
							'field_key'     => (string) $meta_key,
							'source_origin' => 'runtime_meta_classifier',
						),
						$schema
					)
				);
			}
		}

		// 2b) P0-1: register_meta / register_post_meta declarations.
		// WordPress 5.5+ allows plugins to explicitly declare meta field
		// types via register_post_meta(). This is the most authoritative
		// source of field information (higher priority than DB sampling),
		// because the plugin developer actively declares "this field
		// exists and its type is X". Many modern plugins (Gutenberg
		// blocks, WooCommerce 3.0+) use this API exclusively.
		$registered_meta = $this->discover_registered_meta_fields( $post_type );
		foreach ( $registered_meta as $meta_key => $meta_info ) {
			$this->add_discovery_field(
				$field_map,
				array(
					'field_kind'    => 'meta',
					'field_key'     => $meta_key,
					'data_type'     => $meta_info['data_type'],
					'source_origin' => 'register_meta',
					'single'        => $meta_info['single'],
					'show_in_rest'  => $meta_info['show_in_rest'],
				)
			);
		}

		// 3) Source-code mapped candidates from plugin_mappings.meta_fields.
		$mapped_meta = $this->get_meta_keys_for_object( $post_type );
		foreach ( $mapped_meta as $entry ) {
			$meta_key = sanitize_text_field( (string) ( $entry['key'] ?? '' ) );
			if ( '' === $meta_key ) {
				continue;
			}
			$origin = ( 'manual' === ( $entry['source'] ?? '' ) ) ? 'mapping_manual' : 'mapping_detected';
			$this->add_discovery_field(
				$field_map,
				array(
					'field_kind'    => 'meta',
					'field_key'     => $meta_key,
					'source_origin' => $origin,
				)
			);
		}

		// 4) Declarative compatibility declarations (currently WPML config normalized through a registry service).
		if ( class_exists( '\WPTSALL\Models\Services\Compatibility_Declaration_Service' ) ) {
			$declarations = \WPTSALL\Models\Services\Compatibility_Declaration_Service::get_field_declarations_for_post_type( $post_type );
			foreach ( $declarations as $declaration ) {
				$xml_key    = (string) ( $declaration['field_key'] ?? '' );
				$xml_action = (string) ( $declaration['capability'] ?? 'sync' );
				if ( '' === $xml_key ) {
					continue;
				}
				$schema = $this->wpml_action_to_template_schema( $xml_key, $xml_action, 'post' );
				$this->add_discovery_field(
					$field_map,
					array_merge( $declaration, $schema )
				);
			}
		}

		return array_values( $field_map );
	}

	/**
	 * P0-1: Discover meta fields declared via WordPress register_post_meta() / register_meta().
	 *
	 * WordPress 5.5+ allows plugins to explicitly declare meta fields with
	 * type, single, and show_in_rest flags. This is the most authoritative
	 * source because the plugin developer actively registered the field.
	 *
	 * The method maps WP meta types to wptsall data_type:
	 *   string  → text      (translate candidate)
	 *   integer/number/boolean → numeric (sync candidate)
	 *   array/object  → json_structured (may need per-field translation)
	 *
	 * @since 1.5.1
	 *
	 * @param string $post_type Post type name.
	 * @return array<string,array{data_type:string,single:bool,show_in_rest:bool}>
	 */
	private function discover_registered_meta_fields( string $post_type ): array {
		$result = array();

		// get_registered_meta_keys returns: [meta_key => [type, single, sanitize_callback, auth_callback, show_in_rest]]
		$registered = get_registered_meta_keys( 'post', $post_type );
		if ( ! is_array( $registered ) ) {
			return $result;
		}

		foreach ( $registered as $meta_key => $args ) {
			// Skip private meta (keys starting with _ are WP internal convention
			// for non-user-facing fields, but some plugins like ACF use them
			// intentionally — so we include them but mark as internal).
			$wp_type   = $args['type'] ?? 'string';
			$single    = $args['single'] ?? false;
			$rest      = isset( $args['show_in_rest'] ) && $args['show_in_rest'];

			// Map WP meta type to wptsall data_type.
			$data_type = 'text'; // Default: treat as translatable text.
			switch ( $wp_type ) {
				case 'string':
					$data_type = 'text';
					break;
				case 'integer':
				case 'number':
				case 'boolean':
					$data_type = 'numeric';
					break;
				case 'array':
				case 'object':
					$data_type = 'json_structured';
					break;
			}

			$result[ $meta_key ] = array(
				'data_type'    => $data_type,
				'single'       => (bool) $single,
				'show_in_rest' => $rest,
			);
		}

		if ( ! empty( $result ) ) {
			wptsall_log_debug(
				'models-scanner',
				'register_meta fields discovered',
				array(
					'post_type'  => $post_type,
					'field_count' => count( $result ),
					'fields'     => array_keys( $result ),
				)
			);
		}

		return $result;
	}

	/**
	 * Discover fields for a taxonomy from multiple sources.
	 *
	 * @since 1.6.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	private function discover_fields_for_taxonomy( string $taxonomy ): array {
		$field_map = array();

		// 1) Core taxonomy fields.
		$core_fields = array(
			array(
				'field_kind'    => 'core',
				'field_key'     => 'name',
				'data_type'     => 'text',
				'source_origin' => 'taxonomy_core',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'description',
				'data_type'     => 'html',
				'source_origin' => 'taxonomy_core',
			),
			array(
				'field_kind'    => 'core',
				'field_key'     => 'slug',
				'data_type'     => 'slug',
				'source_origin' => 'taxonomy_core',
			),
			array(
				'field_kind'       => 'core',
				'field_key'        => 'parent',
				'data_type'        => 'id_ref',
				'reference_type'   => 'term',
				'reference_target' => 'self',
				'source_origin'    => 'taxonomy_core',
			),
		);
		foreach ( $core_fields as $field ) {
			$this->add_discovery_field( $field_map, $field );
		}

		// 2) Runtime sampled term meta.
		$smart_result = $this->smart_classify_all_fields( 'term', $taxonomy );
		foreach ( array( 'translate', 'sync', 'id_mapping' ) as $cap_type ) {
			$keys = is_array( $smart_result[ $cap_type ] ?? null ) ? $smart_result[ $cap_type ] : array();
			foreach ( $keys as $meta_key ) {
				$schema = $this->capability_to_template_schema( (string) $meta_key, $cap_type, 'term' );
				$this->add_discovery_field(
					$field_map,
					array_merge(
						array(
							'field_kind'    => 'meta',
							'field_key'     => (string) $meta_key,
							'source_origin' => 'runtime_termmeta_classifier',
						),
						$schema
					)
				);
			}
		}

		return array_values( $field_map );
	}

	/**
	 * Map capability type to template schema fields.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_key Field key.
	 * @param string $cap_type  Capability type.
	 * @param string $context   post|term.
	 * @return array
	 */
	private function capability_to_template_schema( string $field_key, string $cap_type, string $context ): array {
		$schema = array(
			'data_type'        => null,
			'reference_type'   => null,
			'reference_target' => null,
			'status'           => 'active',
		);

		switch ( $cap_type ) {
				case 'translate':
					$content_format = Smart_Field_Classifier::normalize_content_format(
						Smart_Field_Classifier::infer_content_format( $field_key ),
						'plain_text'
					);
				if ( 'rich_html' === $content_format ) {
					$schema['data_type'] = 'html';
				} elseif ( 'json_structured' === $content_format ) {
					$schema['data_type'] = 'json';
				} elseif ( 'serialized_php' === $content_format ) {
					$schema['data_type'] = 'serialized';
				} else {
					$schema['data_type'] = 'text';
				}
				$schema['extra'] = array(
					'content_format' => $content_format,
				);
				break;

			case 'id_mapping':
				$schema['data_type'] = preg_match( '/_ids$|_gallery$|_children$|_list$/i', $field_key ) ? 'id_list' : 'id_ref';
				$capability = Smart_Field_Classifier::propose_field_capability( $field_key, $context, array() );
				$ref_type   = sanitize_key( (string) ( $capability['reference_type'] ?? '' ) );
				if ( '' !== $ref_type ) {
					$schema['reference_type'] = $ref_type;
				}
				break;

			case 'compute':
				$schema['data_type'] = 'slug';
				break;

			case 'skip':
				$schema['status'] = 'skip';
				break;
		}

		return $schema;
	}

	/**
	 * Map WPML action to template schema hints.
	 *
	 * @since 1.6.0
	 *
	 * @param string $field_key Field key.
	 * @param string $action    translate|sync|skip.
	 * @param string $context   post|term.
	 * @return array
	 */
	private function wpml_action_to_template_schema( string $field_key, string $action, string $context ): array {
		$action = sanitize_key( $action );
		$schema = array(
			'data_type'        => null,
			'reference_type'   => null,
			'reference_target' => null,
			'status'           => 'active',
			'extra'            => array(
				'wpml_action' => $action,
			),
		);

		if ( 'translate' === $action ) {
			$schema = array_merge( $schema, $this->capability_to_template_schema( $field_key, 'translate', $context ) );
		} elseif ( 'skip' === $action ) {
			$schema['status'] = 'skip';
		}

		return $schema;
	}

	/**
	 * Insert/merge one discovered field into an object field map.
	 *
	 * @since 1.6.0
	 *
	 * @param array $field_map Field map passed by reference.
	 * @param array $field     Field descriptor.
	 * @return void
	 */
	private function add_discovery_field( array &$field_map, array $field ): void {
		$field_kind = sanitize_key( (string) ( $field['field_kind'] ?? 'meta' ) );
		if ( ! in_array( $field_kind, array( 'core', 'meta', 'column', 'block_attr', 'shortcode_attr', 'config' ), true ) ) {
			$field_kind = 'meta';
		}

		$field_key = sanitize_text_field( (string) ( $field['field_key'] ?? '' ) );
		if ( '' === $field_key ) {
			return;
		}

		$status = sanitize_key( (string) ( $field['status'] ?? 'active' ) );
		if ( ! in_array( $status, array( 'active', 'new', 'skip', 'orphan' ), true ) ) {
			$status = 'active';
		}

		$normalized = array(
			'field_kind'        => $field_kind,
			'field_key'         => $field_key,
			'data_type'         => isset( $field['data_type'] ) ? sanitize_key( (string) $field['data_type'] ) : null,
			'reference_type'    => isset( $field['reference_type'] ) ? sanitize_key( (string) $field['reference_type'] ) : null,
			'reference_target'  => isset( $field['reference_target'] ) ? sanitize_text_field( (string) $field['reference_target'] ) : null,
			'status'            => $status,
			'source_origin'     => sanitize_key( (string) ( $field['source_origin'] ?? 'scan' ) ),
			'source_detail'     => isset( $field['source_detail'] ) ? sanitize_textarea_field( (string) $field['source_detail'] ) : '',
			'confidence_score'  => isset( $field['confidence_score'] ) ? max( 0, min( 100, (int) $field['confidence_score'] ) ) : 0,
			'confidence_reason' => isset( $field['confidence_reason'] ) ? sanitize_textarea_field( (string) $field['confidence_reason'] ) : '',
			'extra'             => is_array( $field['extra'] ?? null ) ? $field['extra'] : array(),
		);

		$map_key = $field_kind . ':' . $field_key;
		if ( ! isset( $field_map[ $map_key ] ) ) {
			$field_map[ $map_key ] = $normalized;
			return;
		}

		$existing = $field_map[ $map_key ];
		if ( empty( $existing['data_type'] ) && ! empty( $normalized['data_type'] ) ) {
			$existing['data_type'] = $normalized['data_type'];
		}
		if ( empty( $existing['reference_type'] ) && ! empty( $normalized['reference_type'] ) ) {
			$existing['reference_type'] = $normalized['reference_type'];
		}
		if ( empty( $existing['reference_target'] ) && ! empty( $normalized['reference_target'] ) ) {
			$existing['reference_target'] = $normalized['reference_target'];
		}
		if ( 'skip' === $normalized['status'] ) {
			$existing['status'] = 'skip';
		}
		if ( (int) ( $normalized['confidence_score'] ?? 0 ) >= (int) ( $existing['confidence_score'] ?? 0 ) ) {
			$existing['confidence_score']  = (int) ( $normalized['confidence_score'] ?? 0 );
			if ( ! empty( $normalized['confidence_reason'] ) ) {
				$existing['confidence_reason'] = $normalized['confidence_reason'];
			}
		}
		if ( empty( $existing['source_detail'] ) && ! empty( $normalized['source_detail'] ) ) {
			$existing['source_detail'] = $normalized['source_detail'];
		} elseif ( ! empty( $existing['source_detail'] ) && ! empty( $normalized['source_detail'] ) && $existing['source_detail'] !== $normalized['source_detail'] ) {
			$details = array_filter( array_unique( array_map( 'trim', array_merge( explode( '|', $existing['source_detail'] ), explode( '|', $normalized['source_detail'] ) ) ) ) );
			$existing['source_detail'] = implode( ' | ', $details );
		}

		$origins = array_filter(
			array_unique(
				array_merge(
					array_filter( explode( ',', (string) ( $existing['source_origin'] ?? '' ) ) ),
					array( $normalized['source_origin'] )
				)
			)
		);
		$existing['source_origin'] = implode( ',', $origins );
		$existing['extra']         = array_merge(
			is_array( $existing['extra'] ?? null ) ? $existing['extra'] : array(),
			is_array( $normalized['extra'] ?? null ) ? $normalized['extra'] : array()
		);

		$field_map[ $map_key ] = $existing;
	}

	/**
	 * Smart classification cache (avoid repeated analysis)
	 *
	 * @var array
	 */
	private $smart_classification_cache = array();

	/**
	 * Use smart classifier to analyze all meta fields
	 *
	 * @since 0.4.0
	 * @since 1.0.0 Uses classify_for_chain() static method, key name id_map -> id_mapping
	 *
	 * @param string $data_type   Data type (post/term).
	 * @param string $object_name Object name (post_type/taxonomy).
	 * @return array Classification results ['translate' => [], 'sync' => [], 'id_mapping' => []]
	 */
	private function smart_classify_all_fields( $data_type, $object_name ) {
		$cache_key = $data_type . '_' . $object_name;

		// Check cache
		if ( isset( $this->smart_classification_cache[ $cache_key ] ) ) {
			return $this->smart_classification_cache[ $cache_key ];
		}

		$result = array(
			'translate'  => array(),
			'sync'       => array(),
			'id_mapping' => array(),
		);

		global $wpdb;

		if ( 'post' === $data_type ) {
			// Get all meta_keys for this post_type
			// Note: Removed meta_value != '' condition since some important fields (e.g. _tax_class)
			// may have empty string as valid value and should not be excluded
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_keys = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT pm.meta_key
					FROM %i pm
					JOIN %i p ON pm.post_id = p.ID
					WHERE p.post_type = %s
					  AND pm.meta_key NOT LIKE %s
					  AND pm.meta_key NOT LIKE %s',
					$wpdb->postmeta,
					$wpdb->posts,
					$object_name,
					'_transient%',
					'_edit_%'
				)
			);
		} elseif ( 'term' === $data_type ) {
			// term: Get all term meta_keys for this taxonomy
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_keys = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT tm.meta_key
					FROM %i tm
					INNER JOIN %i tt ON tm.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					  AND tm.meta_key NOT LIKE %s
					  AND tm.meta_key NOT LIKE %s',
					$wpdb->termmeta,
					$wpdb->term_taxonomy,
					$object_name,
					'_transient%',
					'_edit_%'
				)
			);
		} elseif ( 'user' === $data_type ) {
			// user: Get all usermeta keys
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_keys = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT meta_key FROM %i
					WHERE meta_key NOT LIKE %s
					AND meta_key NOT LIKE %s
					ORDER BY meta_key',
					$wpdb->usermeta,
					'_transient%',
					'_edit_%'
				)
			);
		} elseif ( 'comment' === $data_type ) {
			// comment: Get all commentmeta keys
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_keys = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT meta_key FROM %i
					WHERE meta_key NOT LIKE %s
					AND meta_key NOT LIKE %s
					ORDER BY meta_key',
					$wpdb->commentmeta,
					'_transient%',
					'_edit_%'
				)
			);
		} else {
			// Unknown data types: return empty.
			$this->smart_classification_cache[ $cache_key ] = $result;
			return $result;
		}

		if ( empty( $meta_keys ) ) {
			$this->smart_classification_cache[ $cache_key ] = $result;
			return $result;
		}

		// Get sample values for each field for classify_for_chain()
		$fields_with_samples = array();
		foreach ( $meta_keys as $meta_key ) {
			if ( 'post' === $data_type ) {
				// Get sample values
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$samples = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT pm.meta_value
						FROM %i pm
						INNER JOIN %i p ON pm.post_id = p.ID
						WHERE pm.meta_key = %s
						AND p.post_type = %s
						AND pm.meta_value != \'\'
						AND pm.meta_value IS NOT NULL
						LIMIT 10',
						$wpdb->postmeta,
						$wpdb->posts,
						$meta_key,
						$object_name
					)
				);
			} elseif ( 'term' === $data_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$samples = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT tm.meta_value
						FROM %i tm
						INNER JOIN %i tt ON tm.term_id = tt.term_id
						WHERE tm.meta_key = %s
						AND tt.taxonomy = %s
						AND tm.meta_value != \'\'
						AND tm.meta_value IS NOT NULL
						LIMIT 10',
						$wpdb->termmeta,
						$wpdb->term_taxonomy,
						$meta_key,
						$object_name
					)
				);
			} elseif ( 'user' === $data_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$samples = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT meta_value FROM %i
						WHERE meta_key = %s
						AND meta_value != \'\'
						AND meta_value IS NOT NULL
						LIMIT 10',
						$wpdb->usermeta,
						$meta_key
					)
				);
			} else {
				// comment
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$samples = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT meta_value FROM %i
						WHERE meta_key = %s
						AND meta_value != \'\'
						AND meta_value IS NOT NULL
						LIMIT 10',
						$wpdb->commentmeta,
						$meta_key
					)
				);
			}
			$fields_with_samples[ $meta_key ] = $samples ?: array();
		}

		// Use classify_batch_for_chain() for batch classification (per MODULE-CHAINS.md spec)
		// Pass the data_type as context so PII/compute checks use the right rules.
		$classify_context = $data_type;
		$classifications  = Smart_Field_Classifier::classify_batch_for_chain( $fields_with_samples, $classify_context );

		// Group by classification results
		$skip_count = 0;
		foreach ( $classifications as $meta_key => $type ) {
			// skip and compute types are not included in results (compute handled by default fields)
			if ( 'skip' === $type || 'compute' === $type ) {
				++$skip_count;
				continue;
			}

			if ( isset( $result[ $type ] ) ) {
				$result[ $type ][] = $meta_key;
			}
		}

		// Debug log: record classification results summary
		wptsall_log_debug(
			'models-scanner',
			"smart_classify_all_fields: {$object_name} classification complete",
			array(
				'object_name'       => $object_name,
				'meta_keys_count'   => count( $meta_keys ),
				'translate_count'   => count( $result['translate'] ),
				'sync_count'        => count( $result['sync'] ),
				'id_mapping_count'  => count( $result['id_mapping'] ),
				'skip_count'        => $skip_count,
			)
		);

		// Cache results
		$this->smart_classification_cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Get translation fields
	 *
	 * @param string $data_type   Data type.
	 * @param string $object_name Object name.
	 * @return array
	 */
	private function get_translate_fields( $data_type, $object_name ) {
		// Core fields (always included)
		$fields = self::$default_translate_fields[ $data_type ] ?? array();

		// Use smart classifier to get meta fields
		if ( in_array( $data_type, array( 'post', 'term' ), true ) ) {
			$smart_result = $this->smart_classify_all_fields( $data_type, $object_name );
			$fields       = array_merge( $fields, $smart_result['translate'] );

			// Get meta fields for this object from plugin_mappings meta_fields
			if ( ! empty( $this->current_meta_fields ) ) {
				$classify_context = ( 'term' === $data_type ) ? 'term' : 'post';
				$manual_detected  = $this->get_meta_keys_for_object( $object_name );
				foreach ( $manual_detected as $m ) {
					$meta_key = $m['key'];
					// If explicitly manually configured, add directly (unless already exists)
					if ( $m['source'] === 'manual' ) {
						$fields[] = $meta_key;
					} else {
						// Discovered in source code, needs validation - use static method
						$classification = Smart_Field_Classifier::classify_for_chain( $meta_key, array(), $classify_context );
						$field_type = $classification['type'];
						if ( $field_type === Smart_Field_Classifier::FIELD_TYPE_TRANSLATE ) {
							$fields[] = $meta_key;
						}
					}
				}
			}
		}
		// Exclude fields already identified as ID mapping
		$id_fields = $this->get_id_mapping_fields( $data_type, $object_name );
		$fields    = array_diff( $fields, $id_fields );

		return array_unique( $fields );
	}

	/**
	 * Get sync fields (direct copy, no translation or ID mapping needed)
	 *
	 * @param string $data_type   Data type.
	 * @param string $object_name Object name.
	 * @return array
	 */
	private function get_sync_fields( $data_type, $object_name ) {
		// Core fields (always included)
		$fields = self::$default_sync_fields[ $data_type ] ?? array();

		// Use smart classifier to get meta fields
		if ( in_array( $data_type, array( 'post', 'term' ), true ) ) {
			$smart_result = $this->smart_classify_all_fields( $data_type, $object_name );
			$fields       = array_merge( $fields, $smart_result['sync'] );
		}

		// Exclude fields already identified as ID mapping or translation
		$id_fields        = $this->get_id_mapping_fields( $data_type, $object_name );
		$translate_fields = self::$default_translate_fields[ $data_type ] ?? array();

		$fields = array_diff( $fields, $id_fields, $translate_fields );

		return array_unique( $fields );
	}

	/**
	 * Get ID mapping fields (need to find corresponding ID on target site)
	 *
	 * @param string $data_type   Data type.
	 * @param string $object_name Object name.
	 * @return array
	 */
	private function get_id_mapping_fields( $data_type, $object_name ) {
		// Core fields (always included)
		$fields = self::$default_id_mapping_fields[ $data_type ] ?? array();

		// Use smart classifier to get meta fields
		if ( in_array( $data_type, array( 'post', 'term' ), true ) ) {
			$smart_result = $this->smart_classify_all_fields( $data_type, $object_name );
			$fields       = array_merge( $fields, $smart_result['id_mapping'] );
		}

		return array_unique( $fields );
	}

	/**
	 * Get compute fields (need to be regenerated from other fields)
	 *
	 * @param string $data_type   Data type.
	 * @param string $object_name Object name.
	 * @return array
	 */
	private function get_compute_fields( $data_type, $object_name ) {
		return self::$default_compute_fields[ $data_type ] ?? array();
	}

	/**
	 * Build field_capabilities directly from template discovery fields.
	 *
	 * Rules generation should use template fields as the primary source of truth.
	 * This method maps template schema (data_type/status/reference_*) to chain
	 * capability types (translate/sync/id_mapping/compute), and only uses
	 * classifier heuristics as a fallback for ambiguous fields.
	 *
	 * @since 1.6.0
	 *
	 * @param array  $template_fields Template fields for one object.
	 * @param string $data_type       post|term.
	 * @return array
	 */
	private function build_field_capabilities_from_template_fields( array $template_fields, string $data_type ): array {
		$capabilities = array();
		$data_type    = sanitize_key( $data_type );

		$default_translate = array_fill_keys( self::$default_translate_fields[ $data_type ] ?? array(), true );
		$default_sync      = array_fill_keys( self::$default_sync_fields[ $data_type ] ?? array(), true );
		$default_id_map    = array_fill_keys( self::$default_id_mapping_fields[ $data_type ] ?? array(), true );
		$default_compute   = array_fill_keys( self::$default_compute_fields[ $data_type ] ?? array(), true );

		foreach ( $template_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_key = sanitize_text_field( (string) ( $field['field_key'] ?? '' ) );
			if ( '' === $field_key ) {
				continue;
			}

			$status = sanitize_key( (string) ( $field['status'] ?? 'active' ) );
			if ( in_array( $status, array( 'skip', 'orphan', 'deprecated' ), true ) ) {
				continue;
			}

			$field_format = sanitize_key( (string) ( $field['data_type'] ?? '' ) );
			$extra        = is_array( $field['extra'] ?? null ) ? $field['extra'] : array();
			$wpml_action  = sanitize_key( (string) ( $extra['wpml_action'] ?? '' ) );
			$cap_type     = '';
			$proposal     = array();

			// Explicit WPML action has highest precedence.
			if ( 'translate' === $wpml_action ) {
				$cap_type = 'translate';
			} elseif ( in_array( $wpml_action, array( 'sync', 'copy' ), true ) ) {
				$cap_type = 'sync';
			}

			// Next: existing rule capability marker (if present in carried metadata).
			if ( '' === $cap_type ) {
				$rule_cap = sanitize_key( (string) ( $extra['rule_capability_type'] ?? '' ) );
				if ( in_array( $rule_cap, array( 'translate', 'sync', 'id_mapping', 'compute' ), true ) ) {
					$cap_type = $rule_cap;
				}
			}

			// Then apply core defaults for this object type.
			if ( '' === $cap_type ) {
				if ( isset( $default_compute[ $field_key ] ) ) {
					$cap_type = 'compute';
				} elseif ( isset( $default_id_map[ $field_key ] ) ) {
					$cap_type = 'id_mapping';
				} elseif ( isset( $default_sync[ $field_key ] ) ) {
					$cap_type = 'sync';
				} elseif ( isset( $default_translate[ $field_key ] ) ) {
					$cap_type = 'translate';
				}
			}

				// Then infer from template data format.
				if ( '' === $cap_type ) {
					if ( in_array( $field_format, array( 'id_ref', 'id_list' ), true ) ) {
						$cap_type = 'id_mapping';
					} elseif ( 'slug' === $field_format ) {
						$cap_type = 'translate';
					} elseif ( in_array( $field_format, array( 'text', 'html', 'json', 'serialized' ), true ) ) {
						$cap_type = 'translate';
					}
				}

			// Fallback: classifier rule library based on field naming patterns.
			if ( '' === $cap_type ) {
				$proposal = Smart_Field_Classifier::propose_field_capability( $field_key, $data_type, array() );
				$cap_type = sanitize_key( (string) ( $proposal['type'] ?? 'sync' ) );
			}

			if ( ! in_array( $cap_type, array( 'translate', 'sync', 'id_mapping', 'compute' ), true ) ) {
				$cap_type = 'sync';
			}

			if ( 'compute' === $cap_type ) {
				$capabilities[ $field_key ] = array(
					'type'    => 'compute',
					'enabled' => true,
				);
				continue;
			}

			$entry = array(
				'type'      => $cap_type,
				'direction' => 'one_way',
				'enabled'   => true,
			);

				if ( 'translate' === $cap_type ) {
					$content_format = sanitize_key( (string) ( $extra['content_format'] ?? '' ) );
					if ( '' === $content_format ) {
						if ( 'html' === $field_format ) {
							$content_format = 'rich_html';
						} elseif ( 'json' === $field_format ) {
							$content_format = 'json_structured';
						} elseif ( 'serialized' === $field_format ) {
							$content_format = 'serialized_php';
						} else {
							$content_format = Smart_Field_Classifier::infer_content_format( $field_key );
						}
					}
					$content_format = Smart_Field_Classifier::normalize_content_format( $content_format, 'plain_text' );
					$entry['content_format'] = $content_format;
				} elseif ( 'id_mapping' === $cap_type ) {
				$reference_type   = sanitize_key( (string) ( $field['reference_type'] ?? '' ) );
				$reference_target = sanitize_key( (string) ( $field['reference_target'] ?? '' ) );

				if ( '' === $reference_type && ! empty( $proposal['reference_type'] ) ) {
					$reference_type = sanitize_key( (string) $proposal['reference_type'] );
				}
				if ( '' === $reference_type ) {
					$ref            = self::infer_reference_info( $field_key );
					$reference_type = sanitize_key( (string) ( $ref['reference_type'] ?? '' ) );
					if ( '' === $reference_target ) {
						$reference_target = sanitize_key( (string) ( $ref['reference_target'] ?? '' ) );
					}
				}

				if ( '' !== $reference_type ) {
					$entry['reference_type'] = $reference_type;
				}
				if ( '' !== $reference_target ) {
					$entry['reference_target'] = $reference_target;
				}
			} elseif ( 'sync' === $cap_type ) {
				$value_format = self::infer_value_format( $field_key );
				if ( $value_format ) {
					$entry['value_format'] = $value_format;
				}
			}

			$capabilities[ $field_key ] = $entry;
		}

		return $capabilities;
	}

	/**
	 * Build unified field_capabilities structure
	 *
	 * Merge distributed translate_fields/sync_fields/field_mappings/compute_fields
	 * into v0.8.0 field_capabilities format.
	 *
	 * @since 0.8.0
	 * @since 1.0.0 ID mapping field type changed from 'mapping' to 'id_mapping' (per MODULE-CHAINS.md spec)
	 *
	 * @param array $translate_fields Translation fields array.
	 * @param array $sync_fields      Sync fields array.
	 * @param array $field_mappings   ID mapping fields array.
	 * @param array $compute_fields   Compute fields array.
	 * @return array field_capabilities structure.
	 */
	private function build_field_capabilities( $translate_fields, $sync_fields, $field_mappings, $compute_fields ) {
		$capabilities = array();

			// Translation fields — include content_format (v3).
			foreach ( $translate_fields as $field ) {
				$content_format = Smart_Field_Classifier::normalize_content_format(
					Smart_Field_Classifier::infer_content_format( $field ),
					'plain_text'
				);
				$capabilities[ $field ] = array(
					'type'           => 'translate',
				'direction'      => 'one_way',
				'enabled'        => true,
				'content_format' => $content_format,
			);
		}

		// Sync fields — include value_format (v3).
		foreach ( $sync_fields as $field ) {
			$entry = array(
				'type'      => 'sync',
				'direction' => 'one_way',
				'enabled'   => true,
			);
			$value_format = self::infer_value_format( $field );
			if ( $value_format ) {
				$entry['value_format'] = $value_format;
			}
			$capabilities[ $field ] = $entry;
		}

		// ID mapping fields — include reference_type and reference_target (v3).
		foreach ( $field_mappings as $field ) {
			$entry = array(
				'type'      => 'id_mapping',
				'direction' => 'one_way',
				'enabled'   => true,
			);
			$ref = self::infer_reference_info( $field );
			if ( $ref['reference_type'] ) {
				$entry['reference_type']   = $ref['reference_type'];
				$entry['reference_target'] = $ref['reference_target'];
			}
			$capabilities[ $field ] = $entry;
		}

		// Compute fields
		foreach ( $compute_fields as $field ) {
			$capabilities[ $field ] = array(
				'type'    => 'compute',
				'enabled' => true,
			);
		}

		return $capabilities;
	}

	/**
	 * Infer value_format for sync fields.
	 *
	 * @since 1.2.0
	 *
	 * @param string $field_name Field name.
	 * @return string|null Value format or null if unknown.
	 */
	private static function infer_value_format( string $field_name ): ?string {
		// Decimal/price fields.
		if ( preg_match( '/price$|^_price$|^_regular_price$|^_sale_price$|^edd_price$/i', $field_name ) ) {
			return 'decimal';
		}
		// Integer/count fields.
		if ( preg_match( '/count$|_stock$|quantity$|total_sales$/i', $field_name ) ) {
			return 'integer';
		}
		// Dimension fields.
		if ( preg_match( '/^_weight$|^_length$|^_width$|^_height$/i', $field_name ) ) {
			return 'decimal';
		}
		// Date/time fields.
		if ( preg_match( '/date$|time$|_date_gmt$/i', $field_name ) ) {
			return 'datetime';
		}
		// Boolean fields.
		if ( preg_match( '/^_virtual$|^_downloadable$|^_manage_stock$|^_sold_individually$/i', $field_name ) ) {
			return 'boolean';
		}
		// SKU and similar text-sync fields.
		if ( preg_match( '/^_sku$/i', $field_name ) ) {
			return 'string';
		}
		return null;
	}

	/**
	 * Infer reference_type and reference_target for id_mapping fields.
	 *
	 * @since 1.2.0
	 *
	 * @param string $field_name Field name.
	 * @return array { reference_type: string|null, reference_target: string|null }
	 */
	private static function infer_reference_info( string $field_name ): array {
		// Attachment/media references.
		if ( preg_match( '/^_thumbnail_id$|_image_id$|_image_gallery$|_gallery_ids$|_attachment_id$/i', $field_name ) ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'attachment',
			);
		}
		// Post parent / hierarchy references.
		if ( 'post_parent' === $field_name ) {
			return array(
				'reference_type'   => 'post_column',
				'reference_target' => 'self',
			);
		}
		// Author references.
		if ( 'post_author' === $field_name ) {
			return array(
				'reference_type'   => 'post_column',
				'reference_target' => 'user',
			);
		}
		// Nav menu item references.
		if ( preg_match( '/^_menu_item_object_id$|^_menu_item_menu_item_parent$/i', $field_name ) ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'post',
			);
		}
		// WooCommerce cross-sell / upsell.
		if ( preg_match( '/^_upsell_ids$|^_crosssell_ids$|^_children$/i', $field_name ) ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'product',
			);
		}
		// bbPress forum/topic/reply references.
		if ( preg_match( '/^_bbp_forum_id$|^_bbp_topic_id$|^_bbp_reply_id$|^_bbp_last_topic_id$|^_bbp_last_reply_id$|^_bbp_last_active_id$/i', $field_name ) ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'bbpress',
			);
		}
		// Taxonomy term ID references (primary category, etc.).
		if ( preg_match( '/primary_category$|primary_product_cat$|_cat_ids$|_tag_ids$/i', $field_name ) ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'term',
			);
		}
		// SEO image ID references.
		if ( preg_match( '/image-id$|image_id$/i', $field_name ) ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'attachment',
			);
		}
		// wptsall internal source post reference.
		if ( '_wptsall_source_post_id' === $field_name ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'post',
			);
		}
		// Generic _id suffix — post reference.
		if ( preg_match( '/_id$|_ids$/', $field_name ) ) {
			return array(
				'reference_type'   => 'post_meta',
				'reference_target' => 'post',
			);
		}
		return array(
			'reference_type'   => null,
			'reference_target' => null,
		);
	}

	/**
	 * Load current plugin meta_fields from plugin_mappings
	 *
	 * @param string $plugin_slug Plugin slug.
	 */
	private function load_plugin_meta_fields( $plugin_slug ) {
		$this->current_meta_fields = array();

		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) ) {
			return;
		}

		$mapping = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( $plugin_slug );
		if ( $mapping && ! empty( $mapping['meta_fields'] ) && is_array( $mapping['meta_fields'] ) ) {
			$this->current_meta_fields = $mapping['meta_fields'];
		}
	}

	/**
	 * Get meta_key list for specified object type
	 *
	 * Filter fields matching object_name (post_type) from current_meta_fields
	 *
	 * @param string $object_name Object name (e.g. post_type).
	 * @return array meta_key list.
	 */
	private function get_meta_keys_for_object( $object_name ) {
	$results = array();

	foreach ( $this->current_meta_fields as $field ) {
		// 1. New structure: {table_name, field_name, ...}
		if ( isset( $field['field_name'] ) ) {
			$table = $field['table_name'] ?? '';
			// If wp_posts table or post-related custom table, include it
			if ( $table === 'wp_posts' || empty( $table ) ) {
				$results[] = array(
					'key'    => $field['field_name'],
					'source' => 'manual'
				);
			}
			continue;
		}

		// 2. Old structure: {object_type, object_subtype, meta_key, file}
		$object_type    = $field['object_type'] ?? '';
		$object_subtype = $field['object_subtype'] ?? '';
		$meta_key       = $field['meta_key'] ?? '';

		if ( empty( $meta_key ) ) {
			continue;
		}

		// If object_subtype specified, check if it matches current object_name
		if ( ! empty( $object_subtype ) && $object_subtype !== $object_name ) {
			continue;
		}

		// If object_type is 'post' without subtype, or subtype matches, add it
		if ( 'post' === $object_type ) {
			$results[] = array(
				'key'    => $meta_key,
				'source' => 'detected'
			);
		}
	}

	return $results;
}

	/**
	 * Save scan results to database
	 *
	 * Rescan behavior:
	 * - manual model: validate only, no update (returns validation result)
	 * - auto model: full rescan, upsert model then sync to SSOT tables
	 *
	 * Rule creation behavior depends on $this->mode:
	 * - 'full' mode: After SSOT sync, automatically calls
	 *   Translation_Rule_Service::create_rules_for_model() to create translation rules.
	 * - 'incremental' mode: Only syncs to SSOT tables, does NOT create/delete rules.
	 *
	 * @since 1.2.0 Rewritten to use Template_Sync_Service instead of direct rule creation.
	 * @since 1.2.0 Mode parameter controls rule creation (was always skipped before).
	 *
	 * @param array $scan_result Scan results from scan_plugin().
	 * @return array|WP_Error Array { model_id, is_new, diff }, or WP_Error.
	 */
	public function save_scan_result( $scan_result ) {
		global $wpdb;

		$model            = $scan_result['model'];
		$scan_result_json = isset( $scan_result['scan_result'] ) ? wp_json_encode( $scan_result['scan_result'] ) : null;

		// Save model
		$model_table = wptsall_table( 'models' );
		$now         = current_time( 'mysql' );

		// Check if already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_model = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, source_type FROM %i WHERE plugin_slug = %s',
				$model_table,
				$model['plugin_slug']
			),
			ARRAY_A
		);

		// If manually created model, validate only without updating
		if ( $existing_model && 'manual' === $existing_model['source_type'] ) {
			wptsall_log(
				'models-scanner',
				'info',
				'Manual model validation only (no update)',
				array(
					'model_id'    => $existing_model['id'],
					'plugin_slug' => $model['plugin_slug'],
				)
			);

			return array(
				'model_id'  => (int) $existing_model['id'],
				'is_new'    => false,
				'diff'      => array( 'added' => array(), 'orphan' => array(), 'unchanged' => 0 ),
				'validated' => true,
				'message'   => __( 'Manual model verified, no update performed', 'wpmmcc-ats' ),
			);
		}

		$model_data = array(
			'plugin_slug'    => $model['plugin_slug'],
			'plugin_name'    => $model['plugin_name'],
			'plugin_version' => $model['plugin_version'],
			'text_domain'    => $model['text_domain'],
			'description'    => $model['description'],
			'post_types'     => wp_json_encode( $model['post_types'] ),
			'taxonomies'     => wp_json_encode( $model['taxonomies'] ),
			'status'         => $model['status'],
			'scan_version'   => $model['scan_version'],
			'scan_result'    => $scan_result_json,
			'last_scanned'   => $now,
			'updated_at'     => $now,
		);

		$is_new = false;

		if ( $existing_model ) {
			$model_id = (int) $existing_model['id'];

			// Update model
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $model_table, $model_data, array( 'id' => $model_id ) );
		} else {
			// Insert new model
			$is_new                    = true;
			$model_data['created_at']  = $now;
			$model_data['source_type'] = 'auto';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $model_table, $model_data );
			$model_id = $wpdb->insert_id;
		}

		if ( ! $model_id ) {
			return new \WP_Error( 'db_error', 'Failed to save model' );
		}

		// Step 2: Sync scan results to SSOT tables via Template_Sync_Service.
		// Pass $this->mode so that 'full' mode refreshes usage_count/sample_value
		// while 'incremental' mode only appends new fields.
		$sync_service = new Template_Sync_Service();
		$scan_data    = isset( $scan_result['scan_result'] ) && is_array( $scan_result['scan_result'] )
			? $scan_result['scan_result']
			: array();
		$diff         = $sync_service->sync_scan_to_objects( $model_id, $scan_data, $this->mode );

		wptsall_log_info( 'models-scanner', 'Scan result saved through SSOT pipeline', array(
			'model_id' => $model_id,
			'mode'     => $this->mode,
			'added'    => count( $diff['added'] ?? array() ),
			'orphan'   => count( $diff['orphan'] ?? array() ),
			'unchanged' => $diff['unchanged'] ?? 0,
		) );

		// Step 3: In 'full' mode (Trigger A: activation/first scan), auto-create
		// translation rules from the model. In 'incremental' mode (Trigger B/C:
		// manual rescan), skip rule creation — user manages rules explicitly.
		$rule_count = 0;
		if ( 'full' === $this->mode ) {
			if ( class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
				$rule_result = \WPTSALL\Models\Services\Translation_Rule_Service::create_rules_for_model( $model_id );
				if ( ! is_wp_error( $rule_result ) ) {
					$rule_count = ( $rule_result['created'] ?? 0 ) + ( $rule_result['skipped'] ?? 0 );
				}
				wptsall_log_info( 'models-scanner', 'Full mode: auto-created translation rules', array(
					'model_id'   => $model_id,
					'rule_count' => $rule_count,
				) );
			}
		} else {
			wptsall_log_debug( 'models-scanner', 'Incremental mode: skipping rule creation', array(
				'model_id' => $model_id,
			) );
		}

		return array(
			'model_id'   => $model_id,
			'is_new'     => $is_new,
			'diff'       => $diff,
			'rule_count' => $rule_count,
		);
	}

	/**
	 * Scan all content plugins
	 *
	 * @return array Scan results summary.
	 */
	public function scan_all_plugins() {
		$start_time = microtime( true );

		wptsall_log_info(
			'models-scanner',
			'Starting scan of all plugins',
			array( 'mode' => $this->mode )
		);

		// First initialize Runtime_Tracker
		if ( class_exists( __NAMESPACE__ . '\\Runtime_Tracker' ) ) {
			Runtime_Tracker::init();
		}

		$results = array(
			'models_created' => 0,
			'models_updated' => 0,
			'models_failed'  => 0,
			'total_added'    => 0,
			'total_orphan'   => 0,
			'total_rules'    => 0,
			'details'        => array(),
		);

		// Get all active plugins (multisite supported)
		$active_plugins = get_option( 'active_plugins', array() );

		// Multisite: merge network-activated plugins
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			// Network-activated plugins format is plugin_file => timestamp, need to extract key
			$network_plugin_files = array_keys( $network_plugins );
			$active_plugins       = array_unique( array_merge( $active_plugins, $network_plugin_files ) );
		}

		foreach ( $active_plugins as $plugin_file ) {
			// Get plugin slug
			$parts       = explode( '/', $plugin_file );
			$plugin_slug = $parts[0];

			// Skip self
			if ( $plugin_slug === 'wpmmcc-ats' ) {
				continue;
			}

			// Scan plugin
			$scan_result = $this->scan_plugin( $plugin_slug );

			if ( is_wp_error( $scan_result ) ) {
				// Not a content plugin, skip
				continue;
			}

			// Save results
			$save_result = $this->save_scan_result( $scan_result );

			if ( is_wp_error( $save_result ) ) {
				$results['models_failed']++;
				$results['details'][] = array(
					'plugin' => $plugin_slug,
					'status' => 'failed',
					'error'  => $save_result->get_error_message(),
				);
			} else {
				$model_id   = $save_result['model_id'];
				$diff       = $save_result['diff'] ?? array();
				$added      = count( $diff['added'] ?? array() );
				$orphan     = count( $diff['orphan'] ?? array() );
				$rule_count = $save_result['rule_count'] ?? 0;
				$results['total_added']  += $added;
				$results['total_orphan'] += $orphan;
				$results['total_rules']  += $rule_count;

				// Determine if create or update
				if ( $save_result['is_new'] ) {
					$results['models_created']++;
					$status = 'created';
				} else {
					$results['models_updated']++;
					$status = 'updated';
				}

				$results['details'][] = array(
					'plugin'     => $plugin_slug,
					'status'     => $status,
					'model_id'   => $model_id,
					'diff'       => $diff,
					'rule_count' => $rule_count,
					'post_types' => $scan_result['model']['post_types'],
					'taxonomies' => $scan_result['model']['taxonomies'],
				);
			}
		}

		// Also scan wordpress-blog (WP core content types: post, page, category, post_tag).
		// wordpress-blog is not a real plugin, so it is not in the active_plugins list.
		$blog_result = $this->scan_plugin( 'wordpress-blog' );
		if ( ! is_wp_error( $blog_result ) ) {
			$save_result = $this->save_scan_result( $blog_result );
			if ( is_wp_error( $save_result ) ) {
				$results['models_failed']++;
				$results['details'][] = array(
					'plugin' => 'wordpress-blog',
					'status' => 'failed',
					'error'  => $save_result->get_error_message(),
				);
			} else {
				$model_id   = $save_result['model_id'];
				$diff       = $save_result['diff'] ?? array();
				$added      = count( $diff['added'] ?? array() );
				$orphan     = count( $diff['orphan'] ?? array() );
				$rule_count = $save_result['rule_count'] ?? 0;
				$results['total_added']  += $added;
				$results['total_orphan'] += $orphan;
				$results['total_rules']  += $rule_count;

				if ( $save_result['is_new'] ) {
					$results['models_created']++;
					$status = 'created';
				} else {
					$results['models_updated']++;
					$status = 'updated';
				}

				$results['details'][] = array(
					'plugin'     => 'wordpress-blog',
					'status'     => $status,
					'model_id'   => $model_id,
					'diff'       => $diff,
					'rule_count' => $rule_count,
					'post_types' => $blog_result['model']['post_types'],
					'taxonomies' => $blog_result['model']['taxonomies'],
				);
			}
		}

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );
		wptsall_log_info(
			'models-scanner',
			'All plugins scan completed',
			array(
				'mode'           => $this->mode,
				'models_created' => $results['models_created'],
				'models_updated' => $results['models_updated'],
				'models_failed'  => $results['models_failed'],
				'total_added'    => $results['total_added'],
				'total_orphan'   => $results['total_orphan'],
				'total_rules'    => $results['total_rules'],
				'duration_ms'    => $duration_ms,
			)
		);

		return $results;
	}
}
