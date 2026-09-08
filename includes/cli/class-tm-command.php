<?php
/**
 * WP-CLI Translation Memory Command (P6-2)
 *
 * ## EXAMPLES
 *
 *     # Show TM pair counts
 *     $ wp wptsall tm counts
 *
 *     # List TM pairs (paginated)
 *     $ wp wptsall tm list --limit=20
 *
 *     # Export TM as CSV / JSON / XLIFF
 *     $ wp wptsall tm export --format=csv > tm.csv
 *     $ wp wptsall tm export --format=xliff --source=zh_CN --target=en_US > tm.xlf
 *
 *     # Import TM from CSV (cols: source_lang,target_lang,source_text,target_text[,domain,context])
 *     $ wp wptsall tm import tm.csv
 *
 * @package WPTSALL\CLI
 * @since 1.5.0
 */

namespace WPTSALL\CLI;

use WPTSALL\TranslationMemory\Services\Translation_Memory_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TM_Command extends \WP_CLI_Command {
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose



	/**
	 * Show TM pair counts.
	 */
	public function counts( $args, $assoc_args ) {
		$counts = Translation_Memory_Service::counts();
		$rows   = array();
		foreach ( $counts as $key => $val ) {
			$rows[] = array( 'key' => (string) $key, 'count' => (int) $val );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'count' ) );
	}

	/**
	 * List TM pairs.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<code>]
	 * : Source language code.
	 *
	 * [--target=<code>]
	 * : Target language code.
	 *
	 * [--limit=<n>]
	 * : Maximum number of rows.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (table|csv|json).
	 * ---
	 * default: table
	 * ---
	 */
	public function list( $args, $assoc_args ) {
		$rows = Translation_Memory_Service::list_pairs( array(
			'source_lang' => $assoc_args['source'] ?? '',
			'target_lang' => $assoc_args['target'] ?? '',
			'limit'       => (int) ( $assoc_args['limit'] ?? 50 ),
		) );
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'source_lang', 'target_lang', 'source_text', 'target_text' ) );
	}

	/**
	 * Export TM pairs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (csv|json|xliff).
	 * ---
	 * default: csv
	 * ---
	 *
	 * [--source=<code>]
	 * : Source language code.
	 *
	 * [--target=<code>]
	 * : Target language code.
	 *
	 * [--limit=<n>]
	 * : Maximum number of rows.
	 * ---
	 * default: 10000
	 * ---
	 */
	public function export( $args, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'csv';
		$rows   = Translation_Memory_Service::list_pairs( array(
			'source_lang' => $assoc_args['source'] ?? '',
			'target_lang' => $assoc_args['target'] ?? '',
			'limit'       => (int) ( $assoc_args['limit'] ?? 10000 ),
		) );
		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $rows ) );
			return;
		}
		if ( 'xliff' === $format ) {
			$this->export_xliff( $rows, $assoc_args['source'] ?? '', $assoc_args['target'] ?? '' );
			return;
		}
		$out = fopen( 'php://stdout', 'w' );
		fputcsv( $out, array( 'id', 'source_lang', 'target_lang', 'source_text', 'target_text', 'domain', 'context' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array(
				$r['id'] ?? '',
				$r['source_lang'] ?? '',
				$r['target_lang'] ?? '',
				$r['source_text'] ?? '',
				$r['target_text'] ?? '',
				$r['domain'] ?? '',
				$r['context'] ?? '',
			) );
		}
		fclose( $out );
	}

	/**
	 * Import TM pairs from a CSV file (stdin or path).
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to CSV. Use `-` for stdin.
	 */
	public function import( $args, $assoc_args ) {
		$file = $args[0] ?? '-';
		$fh   = ( '-' === $file ) ? fopen( 'php://stdin', 'r' ) : fopen( $file, 'r' );
		if ( ! $fh ) {
			\WP_CLI::error( "Cannot open input: {$file}" );
		}
		$header = fgetcsv( $fh );
		if ( ! $header ) {
			fclose( $fh );
			\WP_CLI::error( 'Empty input.' );
		}
		$idx = array_flip( $header );
		$imported = 0;
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$src_lang = $row[ $idx['source_lang'] ] ?? '';
			$tgt_lang = $row[ $idx['target_lang'] ] ?? '';
			$src_text = $row[ $idx['source_text'] ] ?? '';
			$tgt_text = $row[ $idx['target_text'] ] ?? '';
			$domain   = isset( $idx['domain'] ) ? ( $row[ $idx['domain'] ] ?? '' ) : '';
			$context  = isset( $idx['context'] ) ? ( $row[ $idx['context'] ] ?? '' ) : '';
			if ( '' === $src_lang || '' === $tgt_lang || '' === $src_text || '' === $tgt_text ) {
				continue;
			}
			if ( Translation_Memory_Service::record( $src_text, $src_lang, $tgt_lang, $tgt_text, $domain, $context ) ) {
				++$imported;
			}
		}
		fclose( $fh );
		\WP_CLI::success( "Imported {$imported} TM pairs." );
	}

	private function export_xliff( array $rows, string $source_lang, string $target_lang ): void {
		$src  = $source_lang ?: 'en';
		$tgt  = $target_lang ?: 'zh';
		$out  = fopen( 'php://stdout', 'w' );
		fwrite( $out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" );
		fwrite( $out, '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">' . "\n" );
		fwrite( $out, "  <file source-language=\"{$src}\" target-language=\"{$tgt}\" original=\"wptsall-tm\" datatype=\"plaintext\">\n" );
		fwrite( $out, "    <body>\n" );
		foreach ( $rows as $r ) {
			$id  = 'tm-' . ( $r['id'] ?? '' );
			$src_text = htmlspecialchars( (string) ( $r['source_text'] ?? '' ), ENT_XML1 );
			$tgt_text = htmlspecialchars( (string) ( $r['target_text'] ?? '' ), ENT_XML1 );
			fwrite( $out, "      <trans-unit id=\"{$id}\">\n" );
			fwrite( $out, "        <source>{$src_text}</source>\n" );
			fwrite( $out, "        <target>{$tgt_text}</target>\n" );
			fwrite( $out, "      </trans-unit>\n" );
		}
		fwrite( $out, "    </body>\n" );
		fwrite( $out, "  </file>\n" );
		fwrite( $out, "</xliff>\n" );
		fclose( $out );
	}
// phpcs:enable
}