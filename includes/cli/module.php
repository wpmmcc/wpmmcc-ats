<?php
/**
 * WP-CLI Module (P6-1)
 *
 * Registers `wp wptsall <command>` for power-user / batch operations:
 *   - wp wptsall translate discover/progress/pending/map
 *   - wp wptsall tm list/export/import/counts
 *   - wp wptsall strings export/import
 *   - wp wptsall identity scan/heal
 *   - wp wptsall tasks process/monitor/status (legacy)
 *
 * @package WPTSALL\CLI
 * @since 1.5.0
 */

namespace WPTSALL\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module {

	const COMMANDS = array(
		'translate' => 'WPTSALL\\CLI\\Translate_Command',
		'tm'        => 'WPTSALL\\CLI\\TM_Command',
		'strings'   => 'WPTSALL\\CLI\\Strings_Command',
		'audit'     => 'WPTSALL\\CLI\\Audit_Command',
		'security'  => 'WPTSALL\\CLI\\Security_Command',
		'identity'  => 'WPTSALL\\CLI\\Identity_Command',
	);

	public static function init() {
		if ( ! defined( 'WP_CLI' ) || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}
		// Initialize audit log hooks.
		if ( class_exists( '\\WPTSALL\\CLI\\Audit_Log' ) ) {
			\WPTSALL\CLI\Audit_Log::init();
		}

		foreach ( self::COMMANDS as $name => $class ) {
			if ( class_exists( $class ) ) {
				\WP_CLI::add_command( "wptsall {$name}", $class );
			}
		}
		if ( class_exists( '\\WPTSALL\\Tasks\\CLI\\Tasks_Command' ) ) {
			\WP_CLI::add_command( 'wptsall tasks', '\\WPTSALL\\Tasks\\CLI\\Tasks_Command' );
		}
	}
}
