<?php
/**
 * POT Parser
 *
 * POT/PO file parser
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
 */

namespace WPTSALL\Templates\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POT_Parser class
 *
 * Parses POT/PO files and extracts translation entries
 */
class POT_Parser {

	/**
	 * Parse POT/PO file
	 *
	 * @param string $file_path File path
	 * @return array [{msgid, msgid_plural, msgctxt, msgstr, msgstr_plural, reference}, ...]
	 */
	public static function parse( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			wptsall_log_warning(
				'templates-scanner',
				'POT file not found',
				array( 'path' => $file_path )
			);
			return array();
		}

		$file_size = filesize( $file_path );

		wptsall_log_debug(
			'templates-scanner',
			'Parsing POT file',
			array(
				'file'    => basename( $file_path ),
				'path'    => $file_path,
				'size_kb' => round( $file_size / 1024, 2 ),
			)
		);

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return self::parse_content( $content );
	}

	/**
	 * Parse POT/PO content
	 *
	 * @param string $content File content
	 * @return array Entry array
	 */
	public static function parse_content( $content ) {
		if ( empty( $content ) ) {
			return array();
		}

		$entries = array();

		// Normalize line endings
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );

		// Split into entry blocks (separated by blank lines)
		$blocks = preg_split( '/\n{2,}/', $content );

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( empty( $block ) ) {
				continue;
			}

			$entry = self::parse_block( $block );

			// Skip empty msgid (file header) and empty entries
			if ( $entry && ! empty( $entry['msgid'] ) ) {
				$entries[] = $entry;
			}
		}

		// Count entries with msgctxt
		$with_context = count( array_filter( $entries, function( $e ) {
			return ! empty( $e['msgctxt'] );
		} ) );

		wptsall_log_debug(
			'templates-scanner',
			'POT content parsed',
			array(
				'entries_count'  => count( $entries ),
				'with_context'   => $with_context,
			)
		);

		return $entries;
	}

	/**
	 * Parse single entry block
	 *
	 * @param string $block Entry block content
	 * @return array|null Entry data
	 */
	private static function parse_block( $block ) {
		$entry = array(
			'msgid'        => '',
			'msgid_plural' => '',
			'msgctxt'      => '',
			'msgstr'       => '',
			'msgstr_plural'=> '',
			'reference'    => '',
		);

		// Extract reference location (#: file:line)
		if ( preg_match_all( '/#:\s*(.+)$/m', $block, $refs ) ) {
			$entry['reference'] = implode( ', ', array_slice( $refs[1], 0, 10 ) );
		}

		// Extract translator comments (#.)
		if ( preg_match_all( '/#\.\s*(.+)$/m', $block, $comments ) ) {
			$entry['translator_comment'] = implode( "\n", $comments[1] );
		}

		// Extract context (msgctxt)
		$entry['msgctxt'] = self::extract_string( $block, 'msgctxt' );

		// Extract msgid
		$entry['msgid'] = self::extract_string( $block, 'msgid' );

		// Extract msgid_plural
		$entry['msgid_plural'] = self::extract_string( $block, 'msgid_plural' );

		// Extract msgstr
		$entry['msgstr'] = self::extract_string( $block, 'msgstr' );

		// Extract msgstr[0] and msgstr[1] (plural forms)
		$msgstr0 = self::extract_string( $block, 'msgstr\[0\]' );
		$msgstr1 = self::extract_string( $block, 'msgstr\[1\]' );

		if ( ! empty( $msgstr0 ) || ! empty( $msgstr1 ) ) {
			$entry['msgstr']        = $msgstr0;
			$entry['msgstr_plural'] = $msgstr1;
		}

		// If msgid is empty, return null (likely file header)
		if ( empty( $entry['msgid'] ) ) {
			return null;
		}

		// Detect PO header metadata leak caused by multiline parsing boundary issues
		if ( self::is_po_header_content( $entry['msgid'] ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * Extract string value for a specified field from block
	 *
	 * @param string $block Entry block
	 * @param string $field Field name (supports regex)
	 * @return string String value
	 */
	private static function extract_string( $block, $field ) {
		// Match single-line string: msgid "string"
		$pattern = '/' . $field . '\s+"([^"]*(?:\\\\.[^"]*)*)"/s';
		if ( preg_match( $pattern, $block, $match ) ) {
			// Check for multiline continuation
			$remaining = substr( $block, strpos( $block, $match[0] ) + strlen( $match[0] ) );
			$full_string = $match[1];

			// Match continuation string lines
			while ( preg_match( '/^\s*"([^"]*(?:\\\\.[^"]*)*)"/m', $remaining, $cont_match ) ) {
				$full_string .= $cont_match[1];
				$remaining = substr( $remaining, strpos( $remaining, $cont_match[0] ) + strlen( $cont_match[0] ) );

				// Stop if a new field definition is encountered
				if ( preg_match( '/^\s*(msgid|msgid_plural|msgctxt|msgstr)/m', $remaining ) ) {
					break;
				}
			}

			return self::unescape( $full_string );
		}

		// Match multiline string: msgid ""\n"line1"\n"line2"
		$pattern = '/' . $field . '\s+""\s+((?:"[^"]*(?:\\\\.[^"]*)*"\s*)+)/s';
		if ( preg_match( $pattern, $block, $match ) ) {
			preg_match_all( '/"([^"]*(?:\\\\.[^"]*)*)"/', $match[1], $parts );
			$combined = implode( '', $parts[1] );
			return self::unescape( $combined );
		}

		return '';
	}

	/**
	 * Unescape string
	 *
	 * @param string $string Escaped string
	 * @return string Original string
	 */
	private static function unescape( $string ) {
		$replacements = array(
			'\\n'  => "\n",
			'\\r'  => "\r",
			'\\t'  => "\t",
			'\\"'  => '"',
			'\\\\' => '\\',
		);

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$string
		);
	}

	/**
	 * Detect if string contains PO header metadata keywords
	 *
	 * Multiline parsing boundary issues may cause PO header content to leak as valid msgid
	 *
	 * @param string $str String
	 * @return bool
	 */
	private static function is_po_header_content( $str ) {
		$header_keywords = array(
			'Project-Id-Version:',
			'Report-Msgid-Bugs-To:',
			'POT-Creation-Date:',
			'PO-Revision-Date:',
			'Last-Translator:',
			'Language-Team:',
			'Language:',
			'MIME-Version:',
			'Content-Type: text/plain',
			'Content-Transfer-Encoding:',
			'Plural-Forms:',
			'X-Generator:',
			'X-Poedit-',
			'X-Domain:',
		);

		foreach ( $header_keywords as $keyword ) {
			if ( strpos( $str, $keyword ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Escape string (for generating PO files)
	 *
	 * @param string $string Original string
	 * @return string Escaped string
	 */
	public static function escape( $string ) {
		$replacements = array(
			'\\'  => '\\\\',
			'"'   => '\\"',
			"\n"  => '\\n',
			"\r"  => '\\r',
			"\t"  => '\\t',
		);

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$string
		);
	}

	/**
	 * Generate PO file header
	 *
	 * @param array $meta Metadata
	 * @return string PO file header
	 */
	public static function generate_header( $meta = array() ) {
		$defaults = array(
			'project'     => 'WPTSALL Translation',
			'version'     => '1.0.0',
			'language'    => '',
			'charset'     => 'UTF-8',
			'plural_forms'=> 'nplurals=2; plural=(n != 1);',
		);

		$meta = wp_parse_args( $meta, $defaults );

		$now = gmdate( 'Y-m-d H:i+0000' );

		$header = "# Translation file for " . $meta['project'] . "\n"
			. "# Generated by WPTSALL\n"
			. "#\n"
			. "msgid \"\"\n"
			. "msgstr \"\"\n"
			. "\"Project-Id-Version: " . $meta['project'] . " " . $meta['version'] . "\\n\"\n"
			. "\"Report-Msgid-Bugs-To: \\n\"\n"
			. "\"POT-Creation-Date: " . $now . "\\n\"\n"
			. "\"PO-Revision-Date: " . $now . "\\n\"\n"
			. "\"Last-Translator: WPTSALL\\n\"\n"
			. "\"Language-Team: WPTSALL\\n\"\n"
			. "\"Language: " . $meta['language'] . "\\n\"\n"
			. "\"MIME-Version: 1.0\\n\"\n"
			. "\"Content-Type: text/plain; charset=" . $meta['charset'] . "\\n\"\n"
			. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
			. "\"Plural-Forms: " . $meta['plural_forms'] . "\\n\"\n"
			. "\n";

		return $header;
	}

	/**
	 * Generate single entry
	 *
	 * @param array $entry Entry data
	 * @return string PO entry
	 */
	public static function generate_entry( $entry ) {
		$output = '';

		// Reference location
		if ( ! empty( $entry['reference'] ) ) {
			$refs = explode( ', ', $entry['reference'] );
			foreach ( $refs as $ref ) {
				$output .= '#: ' . trim( $ref ) . "\n";
			}
		}

		// Context
		if ( ! empty( $entry['msgctxt'] ) ) {
			$output .= 'msgctxt "' . self::escape( $entry['msgctxt'] ) . "\"\n";
		}

		// msgid
		$output .= self::format_string( 'msgid', $entry['msgid'] );

		// msgid_plural
		if ( ! empty( $entry['msgid_plural'] ) ) {
			$output .= self::format_string( 'msgid_plural', $entry['msgid_plural'] );

			// Plural form msgstr
			$output .= self::format_string( 'msgstr[0]', $entry['msgstr'] ?? '' );
			$output .= self::format_string( 'msgstr[1]', $entry['msgstr_plural'] ?? '' );
		} else {
			// Singular form
			$output .= self::format_string( 'msgstr', $entry['msgstr'] ?? '' );
		}

		$output .= "\n";

		return $output;
	}

	/**
	 * Format string line (handle multiline)
	 *
	 * @param string $keyword Keyword (msgid, msgstr, etc.)
	 * @param string $string  String content
	 * @return string Formatted line
	 */
	private static function format_string( $keyword, $string ) {
		$escaped = self::escape( $string );

		// If contains newlines or is too long, use multiline format
		if ( strpos( $escaped, '\\n' ) !== false || strlen( $escaped ) > 75 ) {
			$parts  = preg_split( '/(?<=\\\\n)/', $escaped );
			$output = $keyword . " \"\"\n";

			foreach ( $parts as $part ) {
				if ( ! empty( $part ) ) {
					$output .= '"' . $part . "\"\n";
				}
			}

			return $output;
		}

		return $keyword . ' "' . $escaped . "\"\n";
	}

	/**
	 * Generate complete PO file content
	 *
	 * @param array $entries Entry array
	 * @param array $meta    Metadata
	 * @return string PO file content
	 */
	public static function generate( $entries, $meta = array() ) {
		$content = self::generate_header( $meta );

		foreach ( $entries as $entry ) {
			$content .= self::generate_entry( $entry );
		}

		return $content;
	}
}
