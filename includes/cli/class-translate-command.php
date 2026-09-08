<?php
/**
 * WP-CLI Translate Command (P6-1)
 *
 * ## EXAMPLES
 *
 *     # Show translation progress per language
 *     $ wp wptsall translate progress
 *
 *     # List pending translations for English
 *     $ wp wptsall translate pending en_US
 *
 *     # Discover translation mappings from a list of URLs
 *     $ wp wptsall translate discover --source=zh_CN --target=en_US --file=urls.txt
 *
 *     # Create a single post mapping
 *     $ wp wptsall translate map 123 456 en_US
 *
 * @package WPTSALL\CLI
 * @since 1.5.0
 */

namespace WPTSALL\CLI;

use WPTSALL\ManualTranslation\Services\Translation_Progress_Service;
use WPTSALL\ManualTranslation\Services\Url_Discovery_Service;
use WPTSALL\Models\Services\Post_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translate_Command extends \WP_CLI_Command {

	/**
	 * Show translation progress per language.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table|csv|json).
	 * ---
	 * default: table
	 * ---
	 */
	public function progress( $args, $assoc_args ) {
		$format  = $assoc_args['format'] ?? 'table';
		$summary = Translation_Progress_Service::summary();
		$rows    = array();
		foreach ( $summary['by_language'] ?? array() as $code => $stats ) {
			$total      = (int) ( $stats['total'] ?? 0 );
			$translated = (int) ( $stats['translated'] ?? 0 );
			$rows[]     = array(
				'lang'       => $code,
				'total'      => $total,
				'translated' => $translated,
				'pct'        => $total > 0 ? round( ( $translated / $total ) * 100, 1 ) : 0,
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'lang', 'total', 'translated', 'pct' ) );
	}

	/**
	 * List pending translations (source posts without a translation) for one target language.
	 *
	 * ## OPTIONS
	 *
	 * <target_lang>
	 * : Target language code (e.g. en_US).
	 *
	 * [--post-type=<post_type>]
	 * : Filter by post type.
	 *
	 * [--limit=<number>]
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
	public function pending( $args, $assoc_args ) {
		$target_lang = $args[0] ?? '';
		if ( '' === $target_lang ) {
			\WP_CLI::error( 'Please provide a target language code.' );
		}
		$post_type = $assoc_args['post-type'] ?? '';
		$limit     = (int) ( $assoc_args['limit'] ?? 50 );
		$format    = $assoc_args['format'] ?? 'table';
		$rows      = Translation_Progress_Service::pending_for_language( $target_lang, $post_type, $limit );
		\WP_CLI\Utils\format_items( $format, $rows, array( 'post_id', 'post_type', 'post_title' ) );
	}

	/**
	 * Bulk-discover translation mappings from a list of source URLs.
	 *
	 * ## OPTIONS
	 *
	 * --source=<code>
	 * : Source language code.
	 *
	 * --target=<code>
	 * : Target language code.
	 *
	 * --file=<path>
	 * : Path to a file with one URL per line. Use `-` for stdin.
	 */
	public function discover( $args, $assoc_args ) {
		$source = $assoc_args['source'] ?? '';
		$target = $assoc_args['target'] ?? '';
		$file   = $assoc_args['file'] ?? '';
		if ( '' === $source || '' === $target || '' === $file ) {
			\WP_CLI::error( '--source, --target, and --file are required.' );
		}
		if ( '-' === $file ) {
			$urls = preg_split( '/\r?\n/', file_get_contents( 'php://stdin' ) );
		} else {
			if ( ! file_exists( $file ) ) {
				\WP_CLI::error( "File not found: {$file}" );
			}
			$urls = preg_split( '/\r?\n/', (string) file_get_contents( $file ) );
		}
		$urls = array_values( array_filter( array_map( 'trim', $urls ) ) );
		if ( empty( $urls ) ) {
			\WP_CLI::error( 'No URLs to process.' );
		}
		$result = Url_Discovery_Service::discover_bulk( $urls, $source, $target );
		\WP_CLI::log( sprintf( 'Processed %d URLs: %d found, %d missing, %d errors.', count( $urls ), $result['found'], $result['missing'], $result['errors'] ) );
		foreach ( $result['results'] as $r ) {
			$msg = sprintf( '  [%s] %s -> %s', $r['status'], $r['url'], $r['target_path'] ?? '?' );
			if ( 'found' === $r['status'] ) {
				\WP_CLI::log( \WP_CLI::colorize( '%G' . $msg . '%n' ) );
			} elseif ( 'missing' === $r['status'] ) {
				\WP_CLI::log( \WP_CLI::colorize( '%Y' . $msg . '%n' ) );
			} else {
				\WP_CLI::warning( $msg . ' :: ' . ( $r['message'] ?? '' ) );
			}
		}
	}

	/**
	 * Create a single post mapping.
	 *
	 * ## OPTIONS
	 *
	 * <source_post_id>
	 * : Source post id.
	 *
	 * <target_post_id>
	 * : Target post id.
	 *
	 * <target_lang>
	 * : Target language code.
	 */
	public function map( $args, $assoc_args ) {
		$source_id = (int) ( $args[0] ?? 0 );
		$target_id = (int) ( $args[1] ?? 0 );
		$lang      = (string) ( $args[2] ?? '' );
		if ( $source_id <= 0 || $target_id <= 0 || '' === $lang ) {
			\WP_CLI::error( 'Usage: wp wptsall translate map <source_id> <target_id> <lang>' );
		}
		$source = get_post( $source_id );
		$target = get_post( $target_id );
		if ( ! $source || ! $target ) {
			\WP_CLI::error( 'Source or target post not found.' );
		}
		$mapping_id = Post_Mapping_Service::create_mapping( array(
			'source_post_id'    => $source_id,
			'source_post_type'  => $source->post_type,
			'source_site_id'    => get_current_blog_id(),
			'target_post_id'    => $target_id,
			'target_post_type'  => $target->post_type,
			'target_site_id'    => (string) get_current_blog_id(),
			'target_lang'       => $lang,
			'relationship_type' => 'translation',
		) );
		if ( $mapping_id ) {
			\WP_CLI::success( "Mapping created: #{$mapping_id}" );
		} else {
			\WP_CLI::error( 'Mapping creation failed.' );
		}
	}
}
