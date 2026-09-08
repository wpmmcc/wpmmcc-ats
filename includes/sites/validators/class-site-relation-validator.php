<?php
/**
 * Site Relation Validator
 *
 * Site Relation Validator - implements core business rules (v0.4.0 five-tuple unique constraint)
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.4.0 Updated to five-tuple unique constraint validation
 */

namespace WPTSALL\Sites\Validators;

use WPTSALL\Models\Services\Model_Config_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site_Relation_Validator class
 *
 * Validates whether site relations comply with business rules
 */
class Site_Relation_Validator {

	/**
	 * Validate whether a new site relation can be created
	 *
	 * Core business rules (v0.4.0):
	 * 1. Five-tuple unique: source_site_id + source_lang + template + target_site_id + target_lang
	 * 2. Virtual sites cannot be source sites
	 * 3. Source site must have the corresponding plugin activated (except Blog model)
	 * 4. Target site cannot be the same as source site
	 * 5. Real target sites need to have the plugin activated
	 *
	 * @param array $data Relation data
	 *   - source_site_id (int): Source site ID
	 *   - source_lang (string): Source site language
	 *   - template (string): Model identifier
	 *   - target_sites (array): Target sites array [{id, type, lang}]
	 * @return array Validation result array('valid' => bool, 'errors' => array())
	 */
	public static function validate_new_relation( $data ) {
		$errors = array();

		$template       = $data['template'] ?? '';
		$source_site_id = $data['source_site_id'] ?? 0;
		$source_lang    = $data['source_lang'] ?? '';
		$target_sites   = $data['target_sites'] ?? array();

		// Basic validation
		if ( empty( $template ) ) {
			$errors[] = __( 'Model identifier cannot be empty', 'wpmmcc-ats' );
		}

		if ( empty( $source_site_id ) ) {
			$errors[] = __( 'Source site ID cannot be empty', 'wpmmcc-ats' );
		}

		if ( empty( $target_sites ) || ! is_array( $target_sites ) ) {
			$errors[] = __( 'At least one target site is required', 'wpmmcc-ats' );
		}

		// If basic validation fails, return immediately
		if ( ! empty( $errors ) ) {
			if ( function_exists( 'wptsall_log_warning' ) ) {
				wptsall_log_warning(
					'sites-relations',
					'Site relation validation failed (basic)',
					array(
						'errors'         => $errors,
						'source_site_id' => $source_site_id,
						'template'       => $template,
					)
				);
			}
			return array(
				'valid'  => false,
				'errors' => $errors,
			);
		}

		// Rule 2: Validate source site type (cannot be virtual site)
		if ( self::is_virtual_site( $source_site_id ) ) {
			$errors[] = __( 'Virtual sites cannot be used as source sites. Please select a real WordPress site.', 'wpmmcc-ats' );
		}

		// Rule 3: Validate source site plugin status (except virtual models)
		if ( ! Model_Config_Provider::is_virtual_model( $template ) ) {
			if ( ! self::plugin_is_active_on_site( $template, $source_site_id ) ) {
				$errors[] = sprintf(
					/* translators: %1$s: template name, %2$d: site ID */
					__( 'Source site %2$d does not have the %1$s plugin activated. Please activate the plugin first.', 'wpmmcc-ats' ),
					$template,
					$source_site_id
				);
			}
		}

		// Rule 1, 4, 5: Validate each target site
		foreach ( $target_sites as $index => $target ) {
			if ( ! isset( $target['id'] ) || ! isset( $target['type'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: target site index */
					__( 'Target site #%d is missing required fields (id or type)', 'wpmmcc-ats' ),
					$index + 1
				);
				continue;
			}

			$target_id   = $target['id'];
			$target_type = $target['type'];
			$target_lang = $target['lang'] ?? $target['lang_to'] ?? '';

			// Normalize virtual site IDs to v_ prefix format.
			if ( 'virtual' === $target_type ) {
				$target_id = self::format_virtual_site_id( $target_id );
			}

			// Rule 4: Cannot select source site
			if ( 'wp' === $target_type && (int) $target_id === (int) $source_site_id ) {
				$errors[] = sprintf(
					/* translators: %d: site ID */
					__( 'Target site cannot be the same as source site (site %d)', 'wpmmcc-ats' ),
					$target_id
				);
				continue;
			}

			// Rule 1: Check five-tuple uniqueness
			if ( self::relation_exists_by_quintuple( $source_site_id, $source_lang, $template, $target_id, $target_lang ) ) {
				$errors[] = sprintf(
					/* translators: %1$s: template, %2$s: target site, %3$s: target lang */
					__( 'Relation already exists: model "%1$s" to target site %2$s (%3$s)', 'wpmmcc-ats' ),
					$template,
					$target_id,
					$target_lang ?: __( 'Language not specified', 'wpmmcc-ats' )
				);
				continue;
			}

			// Rule 5: Real sites need plugin check (except virtual models)
			if ( 'wp' === $target_type && ! Model_Config_Provider::is_virtual_model( $template ) ) {
				if ( ! self::plugin_is_active_on_site( $template, $target_id ) ) {
					$errors[] = sprintf(
						/* translators: %1$d: site ID, %2$s: template name */
						__( 'Target site %1$d does not have the %2$s plugin activated.', 'wpmmcc-ats' ),
						$target_id,
						$template
					);
				}
			}

			// Virtual model special validation: only supports virtual sites
			if ( Model_Config_Provider::is_virtual_model( $template ) && 'wp' === $target_type ) {
				$errors[] = __( 'This model only supports virtual sites as target sites.', 'wpmmcc-ats' );
			}
		}

		// Log validation failures
		if ( ! empty( $errors ) && function_exists( 'wptsall_log_warning' ) ) {
			wptsall_log_warning(
				'sites-relations',
				'Site relation validation failed',
				array(
					'errors'         => $errors,
					'source_site_id' => $source_site_id,
					'source_lang'    => $source_lang,
					'template'       => $template,
					'target_count'   => count( $target_sites ),
				)
			);
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Validate adding target sites to an existing relation group
	 *
	 * @param int    $source_site_id Source site ID
	 * @param string $source_lang    Source site language
	 * @param string $template       Model identifier
	 * @param array  $new_targets    New target sites array
	 * @return array Validation result
	 */
	public static function validate_add_targets( $source_site_id, $source_lang, $template, $new_targets ) {
		$errors = array();

		foreach ( $new_targets as $index => $target ) {
			if ( ! isset( $target['id'] ) || ! isset( $target['type'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: target site index */
					__( 'Target site #%d is missing required fields (id or type)', 'wpmmcc-ats' ),
					$index + 1
				);
				continue;
			}

			$target_id   = $target['id'];
			$target_type = $target['type'];
			$target_lang = $target['lang'] ?? $target['lang_to'] ?? '';

			// Normalize virtual site IDs to v_ prefix format.
			if ( 'virtual' === $target_type ) {
				$target_id = self::format_virtual_site_id( $target_id );
			}

			// Cannot select source site
			if ( 'wp' === $target_type && (int) $target_id === (int) $source_site_id ) {
				$errors[] = sprintf(
					/* translators: %d: site ID */
					__( 'Target site cannot be the same as source site (site %d)', 'wpmmcc-ats' ),
					$target_id
				);
				continue;
			}

			// Check five-tuple uniqueness
			if ( self::relation_exists_by_quintuple( $source_site_id, $source_lang, $template, $target_id, $target_lang ) ) {
				$errors[] = sprintf(
					/* translators: %1$s: target site, %2$s: target lang */
					__( 'Relation to target site %1$s (%2$s) already exists', 'wpmmcc-ats' ),
					$target_id,
					$target_lang ?: __( 'Language not specified', 'wpmmcc-ats' )
				);
				continue;
			}

			// Real sites need plugin check (except virtual models)
			if ( 'wp' === $target_type && ! Model_Config_Provider::is_virtual_model( $template ) ) {
				if ( ! self::plugin_is_active_on_site( $template, $target_id ) ) {
					$errors[] = sprintf(
						/* translators: %1$d: site ID, %2$s: template name */
						__( 'Target site %1$d does not have the %2$s plugin activated.', 'wpmmcc-ats' ),
						$target_id,
						$template
					);
				}
			}

			// Virtual model special validation
			if ( Model_Config_Provider::is_virtual_model( $template ) && 'wp' === $target_type ) {
				$errors[] = __( 'This model only supports virtual sites as target sites.', 'wpmmcc-ats' );
			}
		}

		// Log validation failures
		if ( ! empty( $errors ) && function_exists( 'wptsall_log_warning' ) ) {
			wptsall_log_warning(
				'sites-relations',
				'Add targets validation failed',
				array(
					'errors'         => $errors,
					'source_site_id' => $source_site_id,
					'source_lang'    => $source_lang,
					'template'       => $template,
					'new_targets'    => count( $new_targets ),
				)
			);
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Check if five-tuple relation already exists
	 *
	 * @param int    $source_site_id Source site ID
	 * @param string $source_lang    Source site language
	 * @param string $template       Model identifier
	 * @param mixed  $target_site_id Target site ID
	 * @param string $target_lang    Target site language
	 * @param int    $exclude_id     Excluded relation ID (for updates)
	 * @return bool
	 */
	public static function relation_exists_by_quintuple( $source_site_id, $source_lang, $template, $target_site_id, $target_lang, $exclude_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		if ( $exclude_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i
					 WHERE source_site_id = %d
					 AND source_lang = %s
					 AND template = %s
					 AND target_site_id = %s
					 AND target_lang = %s
					 AND id != %d',
					$table,
					$source_site_id,
					$source_lang,
					$template,
					(string) $target_site_id,
					$target_lang,
					$exclude_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i
					 WHERE source_site_id = %d
					 AND source_lang = %s
					 AND template = %s
					 AND target_site_id = %s
					 AND target_lang = %s',
					$table,
					$source_site_id,
					$source_lang,
					$template,
					(string) $target_site_id,
					$target_lang
				)
			);
		}

		return $count > 0;
	}

	/**
	 * Virtual site ID prefix
	 *
	 * Used to distinguish virtual site IDs from WordPress site IDs.
	 * Virtual site ID format: v_1, v_2, v_3...
	 * WordPress site ID format: 1, 2, 3...
	 *
	 * @since 0.5.0
	 */
	const VIRTUAL_SITE_PREFIX = 'v_';

	/**
	 * Check if the given ID is a virtual site
	 *
	 * Determines by v_ prefix to avoid conflicts with WordPress site IDs.
	 *
	 * @since 0.5.0 Use prefix check instead of table lookup
	 * @param mixed $site_id Site ID
	 * @return bool
	 */
	public static function is_virtual_site( $site_id ) {
		// Check by prefix: v_1, v_2, etc. are virtual sites
		if ( is_string( $site_id ) && 0 === strpos( $site_id, self::VIRTUAL_SITE_PREFIX ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Format virtual site ID (add prefix)
	 *
	 * @since 0.5.0
	 * @param int|string $virtual_site_id Virtual site original ID
	 * @return string Prefixed virtual site ID (e.g. v_1)
	 */
	public static function format_virtual_site_id( $virtual_site_id ) {
		// If already has prefix, return directly
		if ( is_string( $virtual_site_id ) && 0 === strpos( $virtual_site_id, self::VIRTUAL_SITE_PREFIX ) ) {
			return $virtual_site_id;
		}

		return self::VIRTUAL_SITE_PREFIX . $virtual_site_id;
	}

	/**
	 * Parse virtual site ID (remove prefix)
	 *
	 * @since 0.5.0
	 * @param string $prefixed_id Prefixed virtual site ID (e.g. v_1)
	 * @return int|string Original virtual site ID
	 */
	public static function parse_virtual_site_id( $prefixed_id ) {
		if ( is_string( $prefixed_id ) && 0 === strpos( $prefixed_id, self::VIRTUAL_SITE_PREFIX ) ) {
			$id = substr( $prefixed_id, strlen( self::VIRTUAL_SITE_PREFIX ) );
			// If purely numeric, convert to integer
			return is_numeric( $id ) ? (int) $id : $id;
		}

		return $prefixed_id;
	}

	/**
	 * Check if plugin is active on a site
	 *
	 * @param string $template Model identifier
	 * @param int    $site_id  Site ID
	 * @return bool
	 */
	public static function plugin_is_active_on_site( $plugin_slug, $site_id ) {
		// Virtual models don't need plugin
		if ( Model_Config_Provider::is_virtual_model( $plugin_slug ) ) {
			return true;
		}

		// Use the unified check function from sites.php
		if ( function_exists( 'wptsall_check_plugin_active_on_site' ) ) {
			return wptsall_check_plugin_active_on_site( $site_id, $plugin_slug );
		}

		// Fallback logic (if function is not defined)
		$current_blog_id = get_current_blog_id();
		$need_switch     = is_multisite() && (int) $site_id !== $current_blog_id;

		if ( $need_switch ) {
			switch_to_blog( $site_id );
		}

		$is_active = self::check_plugin_active( $plugin_slug );

		if ( $need_switch ) {
			restore_current_blog();
		}

		return $is_active;
	}

	public static function check_plugin_active( $plugin_slug ) {
		// Use Model_Config_Provider to check virtual models (no plugin activation needed)
		if ( Model_Config_Provider::is_virtual_model( $plugin_slug ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Try direct check (if full path was provided)
		if ( is_plugin_active( $plugin_slug ) || is_plugin_active_for_network( $plugin_slug ) ) {
			return true;
		}

		// Get plugin path from Model_Config_Provider (SSOT)
		$plugin_path = Model_Config_Provider::get_plugin_path( $plugin_slug );

		if ( $plugin_path ) {
			return is_plugin_active( $plugin_path ) || is_plugin_active_for_network( $plugin_path );
		}

		return false;
	}

	/**
	 * Check if plugin is a content type plugin
	 *
	 * @since 0.9.0 Uses Model_Config_Provider API instead of hardcoded list
	 * @param string $plugin_slug Plugin identifier
	 * @param int    $site_id     Site ID (optional, kept for parameter compatibility)
	 * @return bool
	 */
	public static function is_content_plugin( $plugin_slug, $site_id = 0 ) {
		// Use Model_Config_Provider SSOT API
		return Model_Config_Provider::is_content_plugin( $plugin_slug );
	}

	/**
	 * Get site display name
	 *
	 * @param mixed  $site_id   Site ID
	 * @param string $site_type Site type
	 * @return string
	 */
	public static function get_site_display_name( $site_id, $site_type = 'wp' ) {
		if ( 'virtual' === $site_type ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wptsall_virtual_sites';
			$column = self::get_virtual_site_name_column();
			if ( '' === $column ) {
				return $site_id;
			}

			$lookup_id = self::parse_virtual_site_id( $site_id );
			if ( '' === $lookup_id ) {
				$lookup_id = (string) $site_id;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$name = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT %i FROM %i WHERE id = %s',
					$column,
					$table,
					$lookup_id
				)
			);

			return $name ? $name : $site_id;
		}

		// Real site
		if ( is_multisite() ) {
			$site = get_blog_details( $site_id );
			/* translators: %d is the numeric site ID */
		return $site ? $site->blogname : sprintf( __( 'Site %d', 'wpmmcc-ats' ), $site_id );
		}

		return get_bloginfo( 'name' );
	}

	/**
	 * Get language display name
	 *
	 * Uses centralized wptsall_get_language_name() function.
	 *
	 * @since 0.8.1 Refactored to use centralized function.
	 * @param string $lang_code Language code
	 * @return string
	 */
	public static function get_language_display_name( $lang_code ) {
		if ( empty( $lang_code ) ) {
			return __( 'Not specified', 'wpmmcc-ats' );
		}

		return wptsall_get_language_name( $lang_code );
	}

	/**
	 * Detect virtual sites table display-name column with backward compatibility.
	 *
	 * @return string
	 */
	private static function get_virtual_site_name_column() {
		static $resolved_column = null;
		if ( null !== $resolved_column ) {
			return $resolved_column;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_sites';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $table_exists ) {
			$resolved_column = '';
			return $resolved_column;
		}

		foreach ( array( 'site_name', 'name' ) as $candidate ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW COLUMNS FROM %i LIKE %s',
					$table,
					$candidate
				)
			);
			if ( $exists ) {
				$resolved_column = $candidate;
				return $resolved_column;
			}
		}

		$resolved_column = '';
		return $resolved_column;
	}
}
