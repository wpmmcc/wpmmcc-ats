<?php
/**
 * WP-CLI Strings Command (P6-2)
 *
 * ## EXAMPLES
 *
 *     # Show string translation counts
 *     $ wp wptsall strings counts
 *
 *     # List string contexts/keys
 *     $ wp wptsall strings list --context=menu
 *
 *     # Export strings to PO / JSON
 *     $ wp wptsall strings export --format=po > strings.po
 *     $ wp wptsall strings export --format=json > strings.json
 *
 * @package WPTSALL\CLI
 * @since 1.5.0
 */

namespace WPTSALL\CLI;

use WPTSALL\Strings\Services\String_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Strings_Command extends \WP_CLI_Command {
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose



	public function counts( $args, $assoc_args ) {
		$counts = String_Translation_Service::counts();
		$rows   = array();
		foreach ( $counts as $key => $val ) {
			$rows[] = array( 'key' => (string) $key, 'count' => (int) $val );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'count' ) );
	}

	/**
	 * List string translation entries.
	 *
	 * ## OPTIONS
	 *
	 * [--context=<context>]
	 * : Filter by context.
	 *
	 * [--key=<key>]
	 * : Filter by key.
	 *
	 * [--limit=<n>]
	 * : Maximum number of rows.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 */
	public function list( $args, $assoc_args ) {
		$rows = String_Translation_Service::list( array(
			'context' => $assoc_args['context'] ?? '',
			'key'     => $assoc_args['key'] ?? '',
			'limit'   => (int) ( $assoc_args['limit'] ?? 50 ),
		) );
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'context', 'string_key', 'source_text' ) );
	}

	/**
	 * Export strings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (po|json).
	 * ---
	 * default: po
	 * ---
	 *
	 * [--context=<context>]
	 * : Filter by context.
	 *
	 * [--limit=<n>]
	 * : Maximum number of rows.
	 * ---
	 * default: 10000
	 * ---
	 */
	public function export( $args, $assoc_args ) {
		$format  = $assoc_args['format'] ?? 'po';
		$entries = String_Translation_Service::list( array(
			'context' => $assoc_args['context'] ?? '',
			'limit'   => (int) ( $assoc_args['limit'] ?? 10000 ),
		) );
		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $entries ) );
			return;
		}
		$out = fopen( 'php://stdout', 'w' );
		fwrite( $out, "# WPTSALL strings export\n" );
		fwrite( $out, "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\"\n" );
		foreach ( $entries as $e ) {
			$ctx = (string) ( $e['context'] ?? '' );
			$key = (string) ( $e['key'] ?? '' );
			$tx  = (string) ( $e['text'] ?? '' );
			if ( '' !== $ctx ) {
				fwrite( $out, "msgctxt " . $this->po_quote( $ctx ) . "\n" );
			}
			fwrite( $out, "msgid " . $this->po_quote( $key ) . "\n" );
			fwrite( $out, "msgstr " . $this->po_quote( $tx ) . "\n\n" );
		}
		fclose( $out );
	}

	private function po_quote( string $s ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $s ) . '"';
	}
// phpcs:enable
}