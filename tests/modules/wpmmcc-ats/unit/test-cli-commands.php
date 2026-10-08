<?php
/**
 * WP-CLI Module Command Tests
 *
 * Tests for the WPTSALL\CLI command classes (includes/cli/):
 *   - Module command registration map
 *   - Command class loadability outside a real WP-CLI runtime
 *   - Audit_Log ring-buffer logic (record / list / filter / clear / cap)
 *   - Strings_Command PO quoting/escaping
 *
 * The unit runner loads WordPress without the WP-CLI runtime, so the
 * command classes need a stub parent (the real one only exists when the
 * wp binary boots the phar). This file must not run any command that
 * writes data; all tested methods are pure logic or option-backed.
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.3.0
 */

// The WP-CLI base class only exists inside the wp phar runtime. Define a
// no-op stub so the command class files can be autoloaded in plain WP.
// (Eval'd so the runner's class-name parser still finds Test_CLI_Commands.)
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	eval( 'class WP_CLI_Command {}' );
}

/**
 * Test_CLI_Commands
 */
class Test_CLI_Commands extends SimpleTestCase {

	const OPTION_KEY = 'wptsall_audit_log';

	/**
	 * Clean the audit log option between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Clean the audit log option after tests.
	 */
	public function tearDown(): void {
		delete_option( self::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Module::COMMANDS must map exactly the 6 built-in command groups to
	 * their class names, and init() must be a safe no-op outside WP-CLI.
	 */
	public function test_module_registers_expected_command_groups() {
		$this->assertTrue( class_exists( 'WPTSALL\CLI\Module' ) );

		$commands = WPTSALL\CLI\Module::COMMANDS;
		$this->assertIsArray( $commands );

		$expected = array(
			'translate' => 'WPTSALL\CLI\Translate_Command',
			'tm'        => 'WPTSALL\CLI\TM_Command',
			'strings'   => 'WPTSALL\CLI\Strings_Command',
			'audit'     => 'WPTSALL\CLI\Audit_Command',
			'security'  => 'WPTSALL\CLI\Security_Command',
			'identity'  => 'WPTSALL\CLI\Identity_Command',
		);
		$this->assertEquals( $expected, $commands );

		// Outside a real WP-CLI runtime init() must return early without
		// attempting any registration (no fatal, no side effects).
		$result = WPTSALL\CLI\Module::init();
		$this->assertNull( $result, 'Module::init() should be a no-op outside WP-CLI' );
	}

	/**
	 * All 6 command classes must be autoloadable in a plain WP boot
	 * (with the stub WP_CLI_Command parent), extending WP_CLI_Command.
	 */
	public function test_cli_command_classes_loadable_without_cli_runtime() {
		$classes = array(
			'WPTSALL\CLI\Translate_Command',
			'WPTSALL\CLI\TM_Command',
			'WPTSALL\CLI\Strings_Command',
			'WPTSALL\CLI\Audit_Command',
			'WPTSALL\CLI\Security_Command',
			'WPTSALL\CLI\Identity_Command',
		);
		foreach ( $classes as $class ) {
			$this->assertTrue( class_exists( $class ), "Command class {$class} should be autoloadable" );
			$this->assertTrue( is_a( $class, 'WP_CLI_Command', true ), "{$class} must extend WP_CLI_Command" );
		}

		// Audit_Log has no WP_CLI_Command parent and must always load.
		$this->assertTrue( class_exists( 'WPTSALL\CLI\Audit_Log' ) );
	}

	/**
	 * Audit_Log::record() prepends (newest first) and list() returns entries
	 * in newest-first order with structural fields intact.
	 */
	public function test_audit_log_records_and_lists_newest_first() {
		WPTSALL\CLI\Audit_Log::record( 'unit_cli_first', array( 'n' => 1 ) );
		WPTSALL\CLI\Audit_Log::record( 'unit_cli_second', array( 'n' => 2 ) );

		$rows = WPTSALL\CLI\Audit_Log::list( 50 );
		$this->assertCount( 2, $rows );
		$this->assertEquals( 'unit_cli_second', $rows[0]['action'], 'Newest entry must be listed first' );
		$this->assertEquals( 'unit_cli_first', $rows[1]['action'] );

		foreach ( $rows as $row ) {
			$this->assertArrayHasKey( 'time', $row );
			$this->assertArrayHasKey( 'user', $row );
			$this->assertArrayHasKey( 'action', $row );
			$this->assertArrayHasKey( 'data', $row );
		}
	}

	/**
	 * list() must honor the action filter and the limit independently.
	 */
	public function test_audit_list_action_filter_and_limit() {
		WPTSALL\CLI\Audit_Log::record( 'unit_keep_a', array( 'x' => 1 ) );
		WPTSALL\CLI\Audit_Log::record( 'unit_drop', array( 'x' => 2 ) );
		WPTSALL\CLI\Audit_Log::record( 'unit_keep_b', array( 'x' => 3 ) );
		WPTSALL\CLI\Audit_Log::record( 'unit_keep_c', array( 'x' => 4 ) );

		// Action filter keeps only matching entries, newest first.
		$filtered = WPTSALL\CLI\Audit_Log::list( 50, 'unit_keep_a' );
		$this->assertCount( 1, $filtered );
		$this->assertEquals( 'unit_keep_a', $filtered[0]['action'] );

		// Limit truncates regardless of filter.
		$all = WPTSALL\CLI\Audit_Log::list( 2 );
		$this->assertCount( 2, $all );
		$this->assertEquals( 'unit_keep_c', $all[0]['action'] );
		$this->assertEquals( 'unit_keep_b', $all[1]['action'] );
	}

	/**
	 * clear() must return the removed entry count and empty the log;
	 * record() must trim the ring buffer back to the CAP (500).
	 */
	public function test_audit_log_clear_and_cap_trimming() {
		WPTSALL\CLI\Audit_Log::record( 'unit_clear_1' );
		WPTSALL\CLI\Audit_Log::record( 'unit_clear_2' );
		WPTSALL\CLI\Audit_Log::record( 'unit_clear_3' );

		$this->assertEquals( 3, WPTSALL\CLI\Audit_Log::clear() );
		$this->assertCount( 0, WPTSALL\CLI\Audit_Log::list( 50 ) );

		// Pre-fill beyond CAP, then record once: buffer must trim to CAP
		// with the newest entry on top.
		$overflow = array();
		for ( $i = 0; $i < 510; $i++ ) {
			$overflow[] = array(
				'time'   => gmdate( 'c' ),
				'user'   => 'seed',
				'action' => 'unit_overflow_' . $i,
				'data'   => array(),
			);
		}
		update_option( self::OPTION_KEY, $overflow, false );

		WPTSALL\CLI\Audit_Log::record( 'unit_cap_newest', array( 'cap' => true ) );

		$log = get_option( self::OPTION_KEY, array() );
		$this->assertEquals( WPTSALL\CLI\Audit_Log::CAP, count( $log ), 'Ring buffer must be trimmed to CAP' );
		$this->assertEquals( 'unit_cap_newest', $log[0]['action'], 'Newest entry must survive on top after trimming' );
	}

	/**
	 * Strings_Command::po_quote() must escape backslashes and double
	 * quotes for PO msgid/msgstr output.
	 */
	public function test_strings_command_po_quote_escapes() {
		$method = new ReflectionMethod( 'WPTSALL\CLI\Strings_Command', 'po_quote' );
		$method->setAccessible( true );
		$cmd = new WPTSALL\CLI\Strings_Command();

		$this->assertEquals( '"hello"', $method->invoke( $cmd, 'hello' ) );
		$this->assertEquals( '"a\\"b"', $method->invoke( $cmd, 'a"b' ), 'Double quotes must be escaped' );
		$this->assertEquals( '"a\\\\b"', $method->invoke( $cmd, 'a\\b' ), 'Backslashes must be doubled' );
		$this->assertEquals( '""', $method->invoke( $cmd, '' ) );
	}
}
