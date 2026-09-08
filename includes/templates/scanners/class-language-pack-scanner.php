<?php
/**
 * Language Pack Scanner
 *
 * Language pack scanner - scans theme/plugin translation files
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
 */

namespace WPTSALL\Templates\Scanners;

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Relation_Config_Service;
use WPTSALL\Models\Services\Model_Config_Provider;
use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;
use WPTSALL\Templates\Scanners\Content_String_Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language_Pack_Scanner class
 *
 * Scans all language packs needed by a site relation
 *
 * Supports three scanning sources:
 * 1. POT/PO files - standard WordPress translation files
 * 2. PHP/JS source files - extracts __(), _e() and other i18n function calls
 * 3. Database content - user-generated translatable content (titles, excerpts, term names, etc.)
 */
class Language_Pack_Scanner {

	/**
	 * Scan mode constants
	 */
	const SCAN_POT_FILES   = 'pot';      // Scan POT/PO files only
	const SCAN_SOURCE      = 'source';   // Scan source code
	const SCAN_CONTENT     = 'content';  // Scan database content
	const SCAN_ALL         = 'all';      // Comprehensive scan

	/**
	 * Scan all language packs needed by a site relation
	 *
	 * @param int    $relation_id Site relation ID
	 * @param string $mode        Scan mode: 'pot', 'source', 'content', 'all'
	 * @return array Scan results
	 */
	public static function scan_relation( $relation_id, $mode = self::SCAN_POT_FILES ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );

		if ( ! $relation ) {
			return array(
				'success' => false,
				'error'   => __( 'Site relation does not exist', 'wpmmcc-ats' ),
			);
		}

		wptsall_log(
			'templates',
			'info',
			'Starting language pack scan',
			array(
				'relation_id' => $relation_id,
				'template'    => $relation['template'],
				'mode'        => $mode,
			)
		);

		$results           = array();
		$domain_whitelist  = array();

		// Switch to source site
		$current_blog_id = get_current_blog_id();
		$need_switch     = is_multisite() && (int) $relation['source_site_id'] !== $current_blog_id;

		if ( $need_switch ) {
			switch_to_blog( $relation['source_site_id'] );
		}

		try {
			// Execute different scans based on scan mode
			$scan_pot     = in_array( $mode, array( self::SCAN_POT_FILES, self::SCAN_ALL ), true );
			$scan_source  = in_array( $mode, array( self::SCAN_SOURCE, self::SCAN_ALL ), true );
			$scan_content = in_array( $mode, array( self::SCAN_CONTENT, self::SCAN_ALL ), true );

			// Get i18n translation config to determine whether to scan theme/plugin i18n strings
			$template_config     = Relation_Config_Service::get_template_config( $relation_id );
			$scan_theme_enabled  = ! empty( $template_config['translate_theme_i18n'] );
			$scan_plugin_enabled = ! empty( $template_config['translate_plugin_i18n'] );
			$domain_whitelist    = array_values(
				array_filter(
					array_map(
						'strval',
						(array) ( $template_config['gettext_domain_whitelist'] ?? array() )
					)
				)
			);

			// Get source site locale for precise .po file matching
			$source_locale = get_locale();

			// Get all models associated with the site relation (v2.0.1: scan all associated plugins, not just template)
			$associated_models = Relation_Model_Service::get_models_by_relation( $relation_id );
			$plugin_slugs      = array();

			foreach ( $associated_models as $model ) {
				$plugin_slug = $model['plugin_slug'] ?? '';
				if ( ! empty( $plugin_slug ) && ! Model_Config_Provider::is_virtual_model( $plugin_slug ) ) {
					$plugin_slugs[] = $plugin_slug;
				}
			}

			// If no associated models, fall back to relation['template']
			if ( empty( $plugin_slugs ) && ! empty( $relation['template'] ) && ! Model_Config_Provider::is_virtual_model( $relation['template'] ) ) {
				$plugin_slugs[] = $relation['template'];
			}

			wptsall_log(
				'templates',
				'info',
				'Associated plugins for language pack scan',
				array(
					'relation_id'        => $relation_id,
					'models_count'       => count( $associated_models ),
					'plugin_slugs'       => $plugin_slugs,
					'scan_theme_enabled' => $scan_theme_enabled,
					'scan_plugin_enabled' => $scan_plugin_enabled,
				)
			);

			// 1. Scan POT/PO files (default)
			if ( $scan_pot ) {
				if ( $scan_theme_enabled ) {
					$theme_results = self::scan_theme( $source_locale );
					$results       = array_merge( $results, $theme_results );
				}

				if ( $scan_plugin_enabled ) {
					foreach ( $plugin_slugs as $plugin_slug ) {
						$plugin_results = self::scan_plugin( $plugin_slug, $source_locale );
						$results        = array_merge( $results, $plugin_results );
					}
				}
			}

			// 2. Scan source code files (scan all associated plugins)
			if ( $scan_source ) {
				$source_results = self::scan_source_files_for_plugins( $plugin_slugs, $scan_theme_enabled );
				$results        = array_merge( $results, $source_results );
			}

			// 3. Layer B site strings (menus, widgets, identity) + optional reusable blocks for config_i18n.
			if ( $scan_content ) {
				if ( class_exists( '\\WPTSALL\\Strings\\Services\\Site_String_Scanner' ) ) {
					\WPTSALL\Strings\Services\Site_String_Scanner::scan_all();
				}
				if ( ! empty( $template_config['translate_config_i18n'] ) ) {
					$config_results = self::scan_site_config_content();
					$results        = array_merge( $results, $config_results );
				}
			}
		} finally {
			if ( $need_switch ) {
				restore_current_blog();
			}
		}

		// Merge results with the same source_type + text_domain to avoid duplicate writes
		$results = self::merge_results_by_domain( $results );

		// Optional gettext domain whitelist (Theme & Plugin Localization UI).
		if ( ! empty( $domain_whitelist ) ) {
			$results = self::filter_results_by_domain_whitelist( $results, $domain_whitelist );
		}

		// Save scan results to database
		$saved = self::save_scan_results( $relation_id, $results );

		wptsall_log(
			'templates',
			'info',
			'Language pack scan completed',
			array(
				'relation_id'     => $relation_id,
				'scanned_count'   => count( $results ),
				'saved_templates' => count( $saved ),
			)
		);

		return array(
			'success'   => true,
			'results'   => $results,
			'templates' => $saved,
		);
	}

	/**
	 * Scan the current theme
	 *
	 * @param string $source_locale Source site locale for precise .po file matching
	 * @return array Scan results
	 */
	public static function scan_theme( $source_locale = '' ) {
		$results = array();
		$theme   = wp_get_theme();

		// Current theme
		$result = self::scan_theme_files( $theme, $source_locale );
		if ( $result ) {
			$results[] = $result;
		}

		// Parent theme (if this is a child theme)
		if ( $theme->parent() ) {
			$parent_result = self::scan_theme_files( $theme->parent(), $source_locale );
			if ( $parent_result ) {
				$results[] = $parent_result;
			}
		}

		return $results;
	}

	/**
	 * Scan theme files
	 *
	 * @param \WP_Theme $theme         Theme object
	 * @param string    $source_locale Source site locale for precise .po file matching
	 * @return array|null Scan results
	 */
	private static function scan_theme_files( $theme, $source_locale = '' ) {
		$text_domain = $theme->get( 'TextDomain' );
		if ( empty( $text_domain ) ) {
			$text_domain = $theme->get_stylesheet();
		}

		$theme_dir = $theme->get_stylesheet_directory();
		$pot_files = self::find_pot_files( $theme_dir, $text_domain, $source_locale );

		// Also check WordPress language directory
		$wp_lang_theme_dir = wptsall_get_lang_dir() . '/themes';
		if ( is_dir( $wp_lang_theme_dir ) ) {
			// Check for .pot files
			$wp_theme_pot = $wp_lang_theme_dir . '/' . $text_domain . '.pot';
			if ( file_exists( $wp_theme_pot ) && ! in_array( $wp_theme_pot, $pot_files, true ) ) {
				$pot_files[] = $wp_theme_pot;
			}

			// If no .pot found, fall back to a single .po file (all .po files share the same msgid set; only one is needed)
			if ( empty( $pot_files ) ) {
				$pot_files = self::pick_one_po( $wp_lang_theme_dir, $text_domain, $source_locale );
			}
		}

		if ( empty( $pot_files ) ) {
			wptsall_log(
				'templates',
				'debug',
				'No POT files found for theme',
				array(
					'theme'       => $theme->get( 'Name' ),
					'text_domain' => $text_domain,
				)
			);
			return null;
		}

		$entries = array();
		foreach ( $pot_files as $file ) {
			$parsed  = POT_Parser::parse( $file );
			$entries = array_merge( $entries, $parsed );
		}

		// Deduplicate (based on msgid + msgctxt)
		$entries = self::deduplicate_entries( $entries );

		return array(
			'source_type'    => 'theme',
			'text_domain'    => $text_domain,
			'source_name'    => $theme->get( 'Name' ),
			'source_version' => $theme->get( 'Version' ),
			'entries'        => $entries,
			'pot_files'      => $pot_files,
		);
	}

	/**
	 * Scan a plugin
	 *
	 * @param string $plugin_slug    Plugin slug
	 * @param string $source_locale  Source site locale for precise .po file matching
	 * @return array Scan results
	 */
	public static function scan_plugin( $plugin_slug, $source_locale = '' ) {
		$start_time = microtime( true );
		$plugin_dir = wptsall_resolve_plugin_dir_by_slug( $plugin_slug );

		wptsall_log_info(
			'templates-scanner',
			'Scanning plugin language pack',
			array( 'plugin' => $plugin_slug )
		);

		if ( ! is_dir( $plugin_dir ) ) {
			wptsall_log_warning(
				'templates-scanner',
				'Plugin directory not found',
				array( 'plugin_slug' => $plugin_slug )
			);
			return array();
		}

		// Find plugin main file
		$plugin_file = self::find_plugin_file( $plugin_dir, $plugin_slug );
		if ( ! $plugin_file ) {
			wptsall_log_warning(
				'templates-scanner',
				'Plugin main file not found',
				array( 'plugin_slug' => $plugin_slug )
			);
			return array();
		}

		// Get plugin data
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_plugin_data( $plugin_file );

		$text_domain = ! empty( $plugin_data['TextDomain'] ) ? $plugin_data['TextDomain'] : $plugin_slug;

		$pot_files = self::find_pot_files( $plugin_dir, $text_domain, $source_locale );

		// Also check WordPress language directory
		$wp_plugin_lang = wptsall_get_lang_dir() . '/plugins';
		$wp_plugin_pot  = $wp_plugin_lang . '/' . $text_domain . '.pot';
		if ( file_exists( $wp_plugin_pot ) && ! in_array( $wp_plugin_pot, $pot_files, true ) ) {
			$pot_files[] = $wp_plugin_pot;
		}

		// If still no files found, fall back to .po files in WP lang dir
		if ( empty( $pot_files ) && is_dir( $wp_plugin_lang ) ) {
			$pot_files = self::pick_one_po( $wp_plugin_lang, $text_domain, $source_locale );
		}

		if ( empty( $pot_files ) ) {
			wptsall_log_debug(
				'templates-scanner',
				'No POT files found for plugin',
				array(
					'plugin_slug' => $plugin_slug,
					'text_domain' => $text_domain,
				)
			);
			return array();
		}

		// Log discovered POT files
		wptsall_log_debug(
			'templates-scanner',
			'POT files found',
			array(
				'plugin'    => $plugin_slug,
				'pot_files' => $pot_files,
			)
		);

		$entries = array();
		foreach ( $pot_files as $file ) {
			$parsed  = POT_Parser::parse( $file );
			$entries = array_merge( $entries, $parsed );
		}

		// Deduplicate
		$entries = self::deduplicate_entries( $entries );

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		wptsall_log_info(
			'templates-scanner',
			'Language pack scan completed',
			array(
				'plugin'        => $plugin_slug,
				'strings_found' => count( $entries ),
				'pot_files'     => count( $pot_files ),
				'duration_ms'   => $duration_ms,
			)
		);

		return array(
			array(
				'source_type'    => 'plugin',
				'text_domain'    => $text_domain,
				'source_name'    => $plugin_data['Name'],
				'source_version' => $plugin_data['Version'],
				'entries'        => $entries,
				'pot_files'      => $pot_files,
			),
		);
	}

	/**
	 * Find POT/PO files
	 *
	 * @param string $dir           Directory path
	 * @param string $text_domain   Text domain
	 * @param string $source_locale Source site locale for precise .po file matching
	 * @return array Array of file paths
	 */
	private static function find_pot_files( $dir, $text_domain, $source_locale = '' ) {
		$files = array();

		$search_dirs = array(
			$dir . '/languages',
			$dir . '/lang',
			$dir . '/i18n',
			$dir,
		);

		foreach ( $search_dirs as $search_dir ) {
			if ( ! is_dir( $search_dir ) ) {
				continue;
			}

			// Prefer .pot files
			$pot_patterns = array(
				$search_dir . '/' . $text_domain . '.pot',
				$search_dir . '/' . $text_domain . '-*.pot',
			);

			foreach ( $pot_patterns as $pattern ) {
				$matches = glob( $pattern );
				if ( $matches ) {
					$files = array_merge( $files, $matches );
				}
			}

			// If no .pot found, fall back to a single .po file (all .po files share the same msgid set; only one is needed)
			if ( empty( $files ) ) {
				$plain_po = $search_dir . '/' . $text_domain . '.po';
				if ( file_exists( $plain_po ) ) {
					$files[] = $plain_po;
				} else {
					$files = array_merge( $files, self::pick_one_po( $search_dir, $text_domain, $source_locale ) );
				}
			}
		}

		return array_unique( $files );
	}

	/**
	 * Pick one .po file from a directory.
	 *
	 * All .po files share the same msgid set (source strings); only one needs to be parsed.
	 * Prefers the .po matching the source locale; otherwise picks the first one.
	 *
	 * @param string $dir           Directory path
	 * @param string $text_domain   Text domain
	 * @param string $source_locale Source site locale (e.g. 'en_US')
	 * @return array Array containing a single .po file path, or empty array
	 */
	private static function pick_one_po( $dir, $text_domain, $source_locale = '' ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		// Prefer source language locale
		if ( $source_locale ) {
			$preferred = $dir . '/' . $text_domain . '-' . $source_locale . '.po';
			if ( file_exists( $preferred ) ) {
				return array( $preferred );
			}
		}

		// Fallback: pick the first one
		$all = glob( $dir . '/' . $text_domain . '-*.po' );
		return $all ? array( $all[0] ) : array();
	}

	/**
	 * Find plugin main file
	 *
	 * @param string $plugin_dir  Plugin directory
	 * @param string $plugin_slug Plugin slug
	 * @return string|null Plugin main file path
	 */
	private static function find_plugin_file( $plugin_dir, $plugin_slug ) {
		// Common naming patterns
		$possible_files = array(
			$plugin_dir . '/' . $plugin_slug . '.php',
			$plugin_dir . '/plugin.php',
			$plugin_dir . '/index.php',
		);

		foreach ( $possible_files as $file ) {
			if ( file_exists( $file ) ) {
				// Verify this is a valid plugin file
				$content = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( strpos( $content, 'Plugin Name:' ) !== false ) {
					return $file;
				}
			}
		}

		// Traverse directory to find plugin file
		$files = glob( $plugin_dir . '/*.php' );
		foreach ( $files as $file ) {
			$content = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( strpos( $content, 'Plugin Name:' ) !== false ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Deduplicate entries
	 *
	 * @param array $entries Array of entries
	 * @return array Deduplicated entries
	 */
	private static function deduplicate_entries( $entries ) {
		$unique = array();
		$keys   = array();

		foreach ( $entries as $entry ) {
			$key = md5( $entry['msgid'] . '|' . ( $entry['msgctxt'] ?? '' ) );

			if ( ! isset( $keys[ $key ] ) ) {
				$keys[ $key ] = true;
				$unique[]     = $entry;
			} else {
				// Merge reference locations
				foreach ( $unique as &$u ) {
					$u_key = md5( $u['msgid'] . '|' . ( $u['msgctxt'] ?? '' ) );
					if ( $u_key === $key && ! empty( $entry['reference'] ) ) {
						$u['reference'] = trim( $u['reference'] . ', ' . $entry['reference'], ', ' );
						break;
					}
				}
			}
		}

		return $unique;
	}

	/**
	 * Save scan results to database
	 *
	 * @param int   $relation_id Site relation ID
	 * @param array $results     Scan results
	 * @return array Array of saved template IDs
	 */
	private static function save_scan_results( $relation_id, $results ) {
		$saved_templates = array();

		foreach ( $results as $result ) {
			if ( empty( $result['entries'] ) ) {
				continue;
			}

			// Create or update template
			$template_id = Template_Service::get_or_create( array(
				'relation_id'     => $relation_id,
				'source_type'     => $result['source_type'],
				'text_domain'     => $result['text_domain'],
				'source_name'     => $result['source_name'],
				'source_version'  => $result['source_version'],
				'status'          => 'scanned',
				'last_scanned_at' => current_time( 'mysql' ),
			) );

			if ( ! $template_id ) {
				wptsall_log(
					'templates',
					'error',
					'Failed to create template',
					array( 'text_domain' => $result['text_domain'] )
				);
				continue;
			}

			// Save entries
			$entry_count = 0;
			foreach ( $result['entries'] as $entry ) {
				// Quality filter: skip entries not worth translating
				if ( ! Content_String_Scanner::is_translatable( $entry['msgid'] ) ) {
					continue;
				}

				// Skip admin-only Pattern entries (only shown in block editor, not visible on frontend)
				$entry_ctx = $entry['msgctxt'] ?? '';
				if ( in_array( $entry_ctx, array( 'Pattern title', 'Pattern description' ), true ) ) {
					continue;
				}

				$entry_id = Template_Entry_Service::get_or_create( $template_id, array(
					'msgid'        => $entry['msgid'],
					'msgid_plural' => $entry['msgid_plural'] ?? '',
					'msgctxt'      => $entry['msgctxt'] ?? '',
					'reference'    => $entry['reference'] ?? '',
					'status'       => 'pending',
					'source'       => 'scan',
				) );

				if ( $entry_id ) {
					$entry_count++;
				}
			}

			// Update statistics
			Template_Service::update_stats( $template_id );

			$saved_templates[] = array(
				'id'          => $template_id,
				'text_domain' => $result['text_domain'],
				'source_type' => $result['source_type'],
				'entry_count' => $entry_count,
			);

			wptsall_log(
				'templates',
				'info',
				'Template saved',
				array(
					'template_id' => $template_id,
					'text_domain' => $result['text_domain'],
					'entries'     => $entry_count,
				)
			);
		}

		return $saved_templates;
	}

	/**
	 * Rescan a single template
	 *
	 * @param int $template_id Template ID
	 * @return array Scan results
	 */
	public static function rescan_template( $template_id ) {
		$template = Template_Service::get( $template_id );

		if ( ! $template ) {
			return array(
				'success' => false,
				'error'   => __( 'Template does not exist', 'wpmmcc-ats' ),
			);
		}

		$relation = Site_Relation_Service::get_relation( $template['relation_id'] );
		if ( ! $relation ) {
			return array(
				'success' => false,
				'error'   => __( 'Site relation does not exist', 'wpmmcc-ats' ),
			);
		}

		// Switch to source site
		$current_blog_id = get_current_blog_id();
		$need_switch     = is_multisite() && (int) $relation['source_site_id'] !== $current_blog_id;

		if ( $need_switch ) {
			switch_to_blog( $relation['source_site_id'] );
		}

		try {
			$result = null;

			if ( 'theme' === $template['source_type'] ) {
				// Scan theme
				$theme = wp_get_theme( $template['text_domain'] );
				if ( $theme->exists() ) {
					$result = self::scan_theme_files( $theme );
				}
			} else {
				// Scan plugin
				$plugin_results = self::scan_plugin( $template['text_domain'] );
				if ( ! empty( $plugin_results ) ) {
					$result = $plugin_results[0];
				}
			}
		} finally {
			if ( $need_switch ) {
				restore_current_blog();
			}
		}

		if ( ! $result || empty( $result['entries'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Translation files not found', 'wpmmcc-ats' ),
			);
		}

		// Update existing entries, add new entries
		$new_count     = 0;
		$updated_count = 0;

		foreach ( $result['entries'] as $entry ) {
			$existing = Template_Entry_Service::find_by_msgid(
				$template_id,
				$entry['msgid'],
				$entry['msgctxt'] ?? ''
			);

			if ( $existing ) {
				$updated_count++;
			} else {
				$entry_id = Template_Entry_Service::create( array(
					'template_id'  => $template_id,
					'msgid'        => $entry['msgid'],
					'msgid_plural' => $entry['msgid_plural'] ?? '',
					'msgctxt'      => $entry['msgctxt'] ?? '',
					'reference'    => $entry['reference'] ?? '',
					'status'       => 'pending',
					'source'       => 'scan',
				) );

				if ( $entry_id ) {
					$new_count++;
				}
			}
		}

		// Update template information
		Template_Service::update( $template_id, array(
			'source_version'  => $result['source_version'],
			'last_scanned_at' => current_time( 'mysql' ),
		) );

		// Update statistics
		Template_Service::update_stats( $template_id );

		return array(
			'success'  => true,
			'new'      => $new_count,
			'updated'  => $updated_count,
			'total'    => count( $result['entries'] ),
		);
	}

	/**
	 * Comprehensive scan (POT + source code + content)
	 *
	 * @param int $relation_id Site relation ID
	 * @return array Scan results
	 */
	public static function comprehensive_scan( $relation_id ) {
		return self::scan_relation( $relation_id, self::SCAN_ALL );
	}

	/**
	 * Scan source code files for multiple plugins
	 *
	 * @since 2.0.1
	 * @param array $plugin_slugs Array of plugin slugs
	 * @param bool  $scan_theme   Whether to scan theme source files, default true
	 * @return array Scan results
	 */
	private static function scan_source_files_for_plugins( $plugin_slugs, $scan_theme = true ) {
		$results = array();
		$scanner = new I18n_Source_Scanner();

		// Scan theme source files (once only, controlled by i18n config)
		if ( $scan_theme ) {
			$theme     = wp_get_theme();
			$theme_dir = $theme->get_stylesheet_directory();

			$text_domain = $theme->get( 'TextDomain' );
			if ( empty( $text_domain ) ) {
				$text_domain = $theme->get_stylesheet();
			}

			$theme_entries = $scanner->scan_theme( $theme_dir, $text_domain );

			if ( ! empty( $theme_entries ) ) {
				$results[] = array(
					'source_type'    => 'theme',
					'text_domain'    => $text_domain,
					'source_name'    => $theme->get( 'Name' ) . ' (source scan)',
					'source_version' => $theme->get( 'Version' ),
					'entries'        => $theme_entries,
					'scan_source'    => 'source_files',
				);
			}

			// Scan parent theme (if this is a child theme)
			if ( $theme->parent() ) {
				$parent        = $theme->parent();
				$parent_dir    = $parent->get_stylesheet_directory();
				$parent_domain = $parent->get( 'TextDomain' ) ?: $parent->get_stylesheet();

				$parent_entries = $scanner->scan_theme( $parent_dir, $parent_domain );

				if ( ! empty( $parent_entries ) ) {
					$results[] = array(
						'source_type'    => 'theme',
						'text_domain'    => $parent_domain,
						'source_name'    => $parent->get( 'Name' ) . ' (source scan)',
						'source_version' => $parent->get( 'Version' ),
						'entries'        => $parent_entries,
						'scan_source'    => 'source_files',
					);
				}
			}
		}

		// Scan source files for all associated plugins
		foreach ( $plugin_slugs as $plugin_slug ) {
			$plugin_dir = wptsall_resolve_plugin_dir_by_slug( $plugin_slug );

			if ( ! is_dir( $plugin_dir ) ) {
				continue;
			}

			// Get plugin text_domain
			$plugin_file = self::find_plugin_file( $plugin_dir, $plugin_slug );
			$plugin_data = array();

			if ( $plugin_file ) {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugin_data = get_plugin_data( $plugin_file );
			}

			$plugin_text_domain = ! empty( $plugin_data['TextDomain'] ) ? $plugin_data['TextDomain'] : $plugin_slug;
			$plugin_entries     = $scanner->scan_plugin( $plugin_dir, $plugin_text_domain );

			if ( ! empty( $plugin_entries ) ) {
				$results[] = array(
					'source_type'    => 'plugin',
					'text_domain'    => $plugin_text_domain,
					'source_name'    => ( $plugin_data['Name'] ?? $plugin_slug ) . ' (source scan)',
					'source_version' => $plugin_data['Version'] ?? '1.0.0',
					'entries'        => $plugin_entries,
					'scan_source'    => 'source_files',
				);
			}
		}

		wptsall_log(
			'templates',
			'info',
			'Source file scan for multiple plugins completed',
			array(
				'plugin_slugs'  => $plugin_slugs,
				'results_count' => count( $results ),
			)
		);

		return $results;
	}

	/**
	 * Scan site configuration content
	 *
	 * Scans non-user-published database content (widgets, menus, site identity, reusable blocks).
	 * These are not in .pot files and do not go through the sync task path.
	 * Uses source_type='config'; discover_i18n_tasks creates translation tasks for them.
	 *
	 * @since 2.1.0
	 * @return array Scan results
	 */
	private static function scan_site_config_content() {
		$scanner = new Content_String_Scanner();
		$entries = $scanner->scan_config_content();

		if ( empty( $entries ) ) {
			return array();
		}

		return array(
			array(
				'source_type'    => 'config',
				'text_domain'    => 'config-site',
				'source_name'    => __( 'Site configuration', 'wpmmcc-ats' ),
				'source_version' => gmdate( 'Y-m-d' ),
				'entries'        => $entries,
				'scan_source'    => 'config_content',
			),
		);
	}

	/**
	 * Merge scan results with the same source_type + text_domain
	 *
	 * POT scanning and source scanning may each produce results for the same text_domain;
	 * merging avoids duplicate entry writes in save_scan_results.
	 *
	 * @since 2.0.2
	 * @param array $results Array of scan results
	 * @return array Merged results array
	 */
	private static function merge_results_by_domain( $results ) {
		$merged = array();

		foreach ( $results as $result ) {
			$key = $result['source_type'] . ':' . $result['text_domain'];

			if ( isset( $merged[ $key ] ) ) {
				$merged[ $key ]['entries'] = array_merge( $merged[ $key ]['entries'], $result['entries'] );
				// Keep the newer source_version
				if ( ! empty( $result['source_version'] ) ) {
					$merged[ $key ]['source_version'] = $result['source_version'];
				}
			} else {
				$merged[ $key ] = $result;
			}
		}

		// Deduplicate each merged result
		foreach ( $merged as &$result ) {
			$result['entries'] = self::deduplicate_entries( $result['entries'] );
		}

		return array_values( $merged );
	}

	/**
	 * Keep only domains listed in Theme & Plugin Localization whitelist.
	 * Config-site entries are always kept (Layer C option leftovers).
	 *
	 * @param array $results   Scan results.
	 * @param array $whitelist Domain list.
	 * @return array
	 */
	private static function filter_results_by_domain_whitelist( $results, $whitelist ) {
		$allow = array();
		foreach ( (array) $whitelist as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( '' !== $d ) {
				$allow[ $d ] = true;
			}
		}
		if ( empty( $allow ) ) {
			return $results;
		}
		$out = array();
		foreach ( (array) $results as $result ) {
			$domain = strtolower( (string) ( $result['text_domain'] ?? '' ) );
			$type   = (string) ( $result['source_type'] ?? '' );
			if ( 'config' === $type || isset( $allow[ $domain ] ) ) {
				$out[] = $result;
			}
		}
		return $out;
	}

	/**
	 * Scan plugin language pack (contract method)
	 *
	 * Generic scanning method conforming to MODULE-CHAINS.md Chain 6 spec.
	 * Supports custom POT file path or automatic discovery.
	 *
	 * @since 0.8.0
	 * @param string      $plugin_slug Plugin slug
	 * @param string|null $pot_path    Custom POT file path (optional)
	 * @return array Array of scan results
	 */
	public static function scan( string $plugin_slug, ?string $pot_path = null ): array {
		if ( $pot_path ) {
			return self::parse_single_pot_file( $pot_path, $plugin_slug );
		}

		return self::scan_plugin( $plugin_slug );
	}

	/**
	 * Scan all active plugins (contract method)
	 *
	 * Batch scanning method conforming to MODULE-CHAINS.md Chain 6 spec.
	 *
	 * @since 0.8.0
	 * @return array [plugin_slug => scan_result, ...]
	 */
	public static function scan_all_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$results = array();
		$plugins = get_plugins();
		$active  = get_option( 'active_plugins', array() );

		wptsall_log_info(
			'templates-scanner',
			'Scanning all active plugins',
			array( 'total_plugins' => count( $active ) )
		);

		$start_time = microtime( true );

		foreach ( $active as $plugin_file ) {
			$slug = dirname( $plugin_file );

			// Skip single-file plugins (e.g. hello.php)
			if ( '.' === $slug ) {
				continue;
			}

			$scan_result = self::scan_plugin( $slug );

			if ( ! empty( $scan_result ) ) {
				$results[ $slug ] = $scan_result;
			}
		}

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		wptsall_log_info(
			'templates-scanner',
			'All plugins scan completed',
			array(
				'plugins_scanned'   => count( $results ),
				'plugins_with_pot'  => count( array_filter( $results ) ),
				'duration_ms'       => $duration_ms,
			)
		);

		return $results;
	}

	/**
	 * Parse a single POT file
	 *
	 * @since 0.8.0
	 * @param string $pot_path    POT file path
	 * @param string $plugin_slug Plugin slug
	 * @return array Array of scan results
	 */
	private static function parse_single_pot_file( string $pot_path, string $plugin_slug ): array {
		if ( ! file_exists( $pot_path ) ) {
			wptsall_log_warning(
				'templates-scanner',
				'Custom POT file not found',
				array(
					'plugin'   => $plugin_slug,
					'pot_path' => $pot_path,
				)
			);
			return array();
		}

		wptsall_log_info(
			'templates-scanner',
			'Parsing custom POT file',
			array(
				'plugin'   => $plugin_slug,
				'pot_path' => $pot_path,
			)
		);

		$entries = POT_Parser::parse( $pot_path );

		if ( empty( $entries ) ) {
			return array();
		}

		// Try to get plugin information
		$plugin_data = array(
			'Name'    => $plugin_slug,
			'Version' => '1.0.0',
		);

		$plugin_dir  = wptsall_resolve_plugin_dir_by_slug( $plugin_slug );
		$plugin_file = self::find_plugin_file( $plugin_dir, $plugin_slug );

		if ( $plugin_file ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_data = get_plugin_data( $plugin_file );
		}

		$text_domain = ! empty( $plugin_data['TextDomain'] ) ? $plugin_data['TextDomain'] : $plugin_slug;

		return array(
			array(
				'source_type'    => 'plugin',
				'text_domain'    => $text_domain,
				'source_name'    => $plugin_data['Name'],
				'source_version' => $plugin_data['Version'],
				'entries'        => $entries,
				'pot_files'      => array( $pot_path ),
			),
		);
	}
}
