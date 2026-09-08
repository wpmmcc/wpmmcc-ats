<?php
/**
 * WP-CLI Audit Log Command (P6-3)
 *
 * ## EXAMPLES
 *
 *     # List latest 20 audit entries
 *     $ wp wptsall audit list
 *
 *     # Filter by action
 *     $ wp wptsall audit list --action=post_mapping_created
 *
 *     # Clear log
 *     $ wp wptsall audit clear
 *
 * @package WPTSALL\CLI
 * @since 1.5.0
 */

namespace WPTSALL\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit_Command extends \WP_CLI_Command {

	/**
	 * List audit log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--action=<action>]
	 * : Filter by action name.
	 *
	 * [--limit=<n>]
	 * : Maximum entries to show.
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
		$entries = Audit_Log::list(
			(int) ( $assoc_args['limit'] ?? 50 ),
			(string) ( $assoc_args['action'] ?? '' )
		);
		$rows = array();
		foreach ( $entries as $e ) {
			$rows[] = array(
				'time'   => $e['time'] ?? '',
				'user'   => $e['user'] ?? 'system',
				'action' => $e['action'] ?? '',
				'data'   => wp_json_encode( $e['data'] ?? array() ),
			);
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'time', 'user', 'action', 'data' ) );
	}

	/**
	 * Clear the audit log.
	 */
	public function clear( $args, $assoc_args ) {
		$n = Audit_Log::clear();
		\WP_CLI::success( "Cleared {$n} audit entries." );
	}
}
