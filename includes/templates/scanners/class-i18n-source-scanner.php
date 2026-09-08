<?php
/**
 * I18n Source Scanner
 *
 * Scans internationalization strings from PHP/JS source files
 *
 * @package WPTSALL\Templates
 * @since 0.7.0
 */

namespace WPTSALL\Templates\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * I18n_Source_Scanner class
 *
 * Scans translatable strings from theme/plugin source files
 * - Scans __(), _e(), _n(), _x() and other function calls in PHP files
 * - Scans wp.i18n.__() and other calls in JS files
 */
class I18n_Source_Scanner {

	/**
	 * PHP i18n function patterns
	 *
	 * @var array
	 */
	private static $php_functions = array(
		// Basic functions
		'__'            => array( 'text' ),
		'_e'            => array( 'text' ),
		'_n'            => array( 'single', 'plural' ),
		'_x'            => array( 'text', 'context' ),
		'_ex'           => array( 'text', 'context' ),
		'_nx'           => array( 'single', 'plural', 'context' ),
		'_n_noop'       => array( 'single', 'plural' ),
		'_nx_noop'      => array( 'single', 'plural', 'context' ),

		// Escape functions
		'esc_html__'    => array( 'text' ),
		'esc_html_e'    => array( 'text' ),
		'esc_html_x'    => array( 'text', 'context' ),
		'esc_attr__'    => array( 'text' ),
		'esc_attr_e'    => array( 'text' ),
		'esc_attr_x'    => array( 'text', 'context' ),
	);

	/**
	 * JS i18n function patterns
	 *
	 * @var array
	 */
	private static $js_functions = array(
		'__'     => array( 'text' ),
		'_x'     => array( 'text', 'context' ),
		'_n'     => array( 'single', 'plural' ),
		'_nx'    => array( 'single', 'plural', 'context' ),
	);

	/**
	 * Scan results cache
	 *
	 * @var array
	 */
	private $entries = array();

	/**
	 * Current text_domain being scanned
	 *
	 * @var string
	 */
	private $text_domain = '';

	/**
	 * Scan theme source files
	 *
	 * @param string $theme_dir   Theme directory
	 * @param string $text_domain Text domain
	 * @return array Scanned entries
	 */
	public function scan_theme( $theme_dir, $text_domain ) {
		$this->entries     = array();
		$this->text_domain = $text_domain;

		if ( ! is_dir( $theme_dir ) ) {
			return array();
		}

		wptsall_log_debug(
			'templates-scanner',
			'Scanning theme source files',
			array(
				'directory'   => $theme_dir,
				'text_domain' => $text_domain,
			)
		);

		// Scan PHP files
		$php_files = $this->find_files( $theme_dir, 'php' );
		foreach ( $php_files as $file ) {
			$this->scan_php_file( $file );
		}

		// Scan JS files
		$js_files = $this->find_files( $theme_dir, 'js' );
		foreach ( $js_files as $file ) {
			$this->scan_js_file( $file );
		}

		wptsall_log_info(
			'templates-scanner',
			'Theme source scan completed',
			array(
				'text_domain'  => $text_domain,
				'php_files'    => count( $php_files ),
				'js_files'     => count( $js_files ),
				'entries'      => count( $this->entries ),
			)
		);

		return $this->deduplicate_entries();
	}

	/**
	 * Scan plugin source files
	 *
	 * @param string $plugin_dir  Plugin directory
	 * @param string $text_domain Text domain
	 * @return array Scanned entries
	 */
	public function scan_plugin( $plugin_dir, $text_domain ) {
		$this->entries     = array();
		$this->text_domain = $text_domain;

		if ( ! is_dir( $plugin_dir ) ) {
			return array();
		}

		wptsall_log_debug(
			'templates-scanner',
			'Scanning plugin source files',
			array(
				'directory'   => $plugin_dir,
				'text_domain' => $text_domain,
			)
		);

		// Scan PHP files
		$php_files = $this->find_files( $plugin_dir, 'php' );
		foreach ( $php_files as $file ) {
			$this->scan_php_file( $file );
		}

		// Scan JS files
		$js_files = $this->find_files( $plugin_dir, 'js' );
		foreach ( $js_files as $file ) {
			$this->scan_js_file( $file );
		}

		wptsall_log_info(
			'templates-scanner',
			'Plugin source scan completed',
			array(
				'text_domain'  => $text_domain,
				'php_files'    => count( $php_files ),
				'js_files'     => count( $js_files ),
				'entries'      => count( $this->entries ),
			)
		);

		return $this->deduplicate_entries();
	}

	/**
	 * Find files of a specified type
	 *
	 * @param string $dir       Directory
	 * @param string $extension File extension
	 * @param int    $max_depth Maximum recursion depth
	 * @return array File path list
	 */
	private function find_files( $dir, $extension, $max_depth = 5 ) {
		$files = array();
		$this->scan_directory( $dir, $extension, $files, 0, $max_depth );
		return $files;
	}

	/**
	 * Recursively scan directory
	 *
	 * @param string $dir       Directory
	 * @param string $extension File extension
	 * @param array  $files     File array (by reference)
	 * @param int    $depth     Current depth
	 * @param int    $max_depth Maximum depth
	 */
	private function scan_directory( $dir, $extension, &$files, $depth, $max_depth ) {
		if ( $depth >= $max_depth ) {
			return;
		}

		$items = @scandir( $dir );
		if ( false === $items ) {
			return;
		}

		// Excluded directories
		$exclude_dirs = array(
			'node_modules',
			'vendor',
			'tests',
			'test',
			'.git',
			'.svn',
			'build',
			'dist',
		);

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				if ( ! in_array( $item, $exclude_dirs, true ) ) {
					$this->scan_directory( $path, $extension, $files, $depth + 1, $max_depth );
				}
			} elseif ( is_file( $path ) ) {
				$file_ext = pathinfo( $path, PATHINFO_EXTENSION );
				if ( strtolower( $file_ext ) === $extension ) {
					$files[] = $path;
				}
			}
		}
	}

	/**
	 * Scan PHP file
	 *
	 * @param string $file File path
	 */
	private function scan_php_file( $file ) {
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( empty( $content ) ) {
			return;
		}

		// Check if the file uses the target text_domain
		if ( ! empty( $this->text_domain ) && strpos( $content, $this->text_domain ) === false ) {
			// File does not use the target text_domain, skip
			return;
		}

		$relative_path = $this->get_relative_path( $file );

		foreach ( self::$php_functions as $function => $args ) {
			$this->extract_function_calls( $content, $function, $args, $relative_path );
		}
	}

	/**
	 * Scan JS file
	 *
	 * @param string $file File path
	 */
	private function scan_js_file( $file ) {
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( empty( $content ) ) {
			return;
		}

		// Check if wp.i18n is used
		if ( strpos( $content, 'wp.i18n' ) === false && strpos( $content, '@wordpress/i18n' ) === false ) {
			return;
		}

		// Check if the file uses the target text_domain (consistent with PHP scanning)
		if ( ! empty( $this->text_domain ) && strpos( $content, $this->text_domain ) === false ) {
			return;
		}

		$relative_path = $this->get_relative_path( $file );

		// Match wp.i18n.__() or __() (imported from @wordpress/i18n)
		foreach ( self::$js_functions as $function => $args ) {
			$this->extract_js_calls( $content, $function, $args, $relative_path );
		}
	}

	/**
	 * Extract function calls from PHP code
	 *
	 * @param string $content  File content
	 * @param string $function Function name
	 * @param array  $args     Argument types
	 * @param string $file     File reference
	 */
	private function extract_function_calls( $content, $function, $args, $file ) {
		// Build regex to match function calls
		// Match: function_name( 'string', ... )
		$pattern = '/' . preg_quote( $function, '/' ) . '\s*\(\s*([\'"])(.+?)\1';

		// Track current capture group number
		$group_num = 2;

		// If has context parameter
		if ( in_array( 'context', $args, true ) ) {
			$group_num += 2; // Capture groups 3 and 4
			$pattern .= '\s*,\s*([\'"])(.+?)\3';
		}

		// If has text_domain parameter (most functions do)
		// Backreference number depends on whether context parameter exists
		$domain_quote_ref = $group_num + 1; // Next capture group
		$pattern .= '\s*,\s*([\'"])(' . preg_quote( $this->text_domain, '/' ) . ')\\' . $domain_quote_ref;

		$pattern .= '/s';

		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$msgid   = $this->unescape_string( $match[2][0] );
				$msgctxt = '';

				// Get context (if present)
				if ( in_array( 'context', $args, true ) && isset( $match[4] ) ) {
					$msgctxt = $this->unescape_string( $match[4][0] );
				}

				// Calculate line number
				$line = substr_count( substr( $content, 0, $match[0][1] ), "\n" ) + 1;

				$this->add_entry( $msgid, '', $msgctxt, $file . ':' . $line );
			}
		}

		// For _n and _nx, need to match plural forms
		if ( in_array( 'plural', $args, true ) ) {
			$this->extract_plural_calls( $content, $function, $args, $file );
		}
	}

	/**
	 * Extract plural form function calls
	 *
	 * @param string $content  File content
	 * @param string $function Function name
	 * @param array  $args     Argument types
	 * @param string $file     File reference
	 */
	private function extract_plural_calls( $content, $function, $args, $file ) {
		// Match: _n( 'single', 'plural', $number, 'domain' )
		// Capture groups: 1–2 singular quote/text, 3–4 plural quote/text.
		$pattern   = '/' . preg_quote( $function, '/' ) . '\s*\(\s*([\'"])(.+?)\1\s*,\s*([\'"])(.+?)\3';
		$group_num = 4;

		// Skip number parameter (uncaptured).
		$pattern .= '\s*,\s*[^,]+';

		// Optional context: _nx( ..., $n, 'context', 'domain' )
		if ( in_array( 'context', $args, true ) ) {
			$ctx_quote = $group_num + 1;
			$pattern  .= '\s*,\s*([\'"])(.+?)\\' . $ctx_quote;
			$group_num += 2;
		}

		// text_domain quote group must track preceding captures (was hardcoded \7,
		// which breaks when context is absent → PCRE "non-existent subpattern").
		$domain_quote_ref = $group_num + 1;
		$pattern         .= '\s*,\s*([\'"])(' . preg_quote( $this->text_domain, '/' ) . ')\\' . $domain_quote_ref;

		$pattern .= '/s';

		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$single  = $this->unescape_string( $match[2][0] );
				$plural  = $this->unescape_string( $match[4][0] );
				$msgctxt = '';

				// Get context
				if ( in_array( 'context', $args, true ) && isset( $match[6] ) ) {
					$msgctxt = $this->unescape_string( $match[6][0] );
				}

				// Calculate line number
				$line = substr_count( substr( $content, 0, $match[0][1] ), "\n" ) + 1;

				$this->add_entry( $single, $plural, $msgctxt, $file . ':' . $line );
			}
		}
	}

	/**
	 * Extract function calls from JS code
	 *
	 * @param string $content  File content
	 * @param string $function Function name
	 * @param array  $args     Argument types
	 * @param string $file     File reference
	 */
	private function extract_js_calls( $content, $function, $args, $file ) {
		// Match: wp.i18n.__( 'text', 'domain' ) or __( 'text', 'domain' )
		$patterns = array(
			'/wp\.i18n\.' . preg_quote( $function, '/' ) . '\s*\(\s*([\'"`])(.+?)\1/',
			'/(?<![.\w])' . preg_quote( $function, '/' ) . '\s*\(\s*([\'"`])(.+?)\1/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $match ) {
					$msgid = $this->unescape_string( $match[2][0] );
					$line  = substr_count( substr( $content, 0, $match[0][1] ), "\n" ) + 1;

					$this->add_entry( $msgid, '', '', $file . ':' . $line );
				}
			}
		}
	}

	/**
	 * Add entry
	 *
	 * @param string $msgid        Message ID
	 * @param string $msgid_plural Plural form
	 * @param string $msgctxt      Context
	 * @param string $reference    Reference location
	 */
	private function add_entry( $msgid, $msgid_plural, $msgctxt, $reference ) {
		if ( empty( $msgid ) ) {
			return;
		}

		$this->entries[] = array(
			'msgid'        => $msgid,
			'msgid_plural' => $msgid_plural,
			'msgctxt'      => $msgctxt,
			'reference'    => $reference,
			'source'       => 'source_scan',
		);
	}

	/**
	 * Deduplicate entries
	 *
	 * @return array
	 */
	private function deduplicate_entries() {
		$unique = array();
		$keys   = array();

		foreach ( $this->entries as $entry ) {
			$key = md5( $entry['msgid'] . '|' . ( $entry['msgctxt'] ?? '' ) );

			if ( ! isset( $keys[ $key ] ) ) {
				$keys[ $key ] = count( $unique );
				$unique[]     = $entry;
			} else {
				// Merge reference locations
				$idx = $keys[ $key ];
				if ( ! empty( $entry['reference'] ) ) {
					$unique[ $idx ]['reference'] = trim(
						$unique[ $idx ]['reference'] . ', ' . $entry['reference'],
						', '
					);
				}
			}
		}

		return $unique;
	}

	/**
	 * Get relative path
	 *
	 * @param string $file File path
	 * @return string
	 */
	private function get_relative_path( $file ) {
		// Try to get path relative to wp-content
		$wp_content = wptsall_get_content_dir();

		if ( strpos( $file, $wp_content ) === 0 ) {
			return substr( $file, strlen( $wp_content ) + 1 );
		}

		return basename( $file );
	}

	/**
	 * Unescape string
	 *
	 * @param string $str String
	 * @return string
	 */
	private function unescape_string( $str ) {
		$str = str_replace( array( '\\n', '\\r', '\\t' ), array( "\n", "\r", "\t" ), $str );
		$str = str_replace( array( "\\'", '\\"' ), array( "'", '"' ), $str );
		return $str;
	}

	/**
	 * Static shortcut method: scan theme
	 *
	 * @param string $theme_dir   Theme directory
	 * @param string $text_domain Text domain
	 * @return array
	 */
	public static function scan_theme_static( $theme_dir, $text_domain ) {
		$scanner = new self();
		return $scanner->scan_theme( $theme_dir, $text_domain );
	}

	/**
	 * Static shortcut method: scan plugin
	 *
	 * @param string $plugin_dir  Plugin directory
	 * @param string $text_domain Text domain
	 * @return array
	 */
	public static function scan_plugin_static( $plugin_dir, $text_domain ) {
		$scanner = new self();
		return $scanner->scan_plugin( $plugin_dir, $text_domain );
	}
}
