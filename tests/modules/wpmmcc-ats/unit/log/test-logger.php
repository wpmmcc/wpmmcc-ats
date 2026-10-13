<?php
/**
 * Logger Tests
 *
 * Tests for WPTSALL Log module functions
 *
 * @package WPTSALL
 * @since 0.4.0
 */

class Test_Logger extends WP_UnitTestCase {

	/**
	 * Test log directory path
	 *
	 * @var string
	 */
	private $log_dir;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		$this->log_dir = \WPTSALL\Log\get_log_dir();

		// Ensure log directory exists
		if ( ! file_exists( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		// Clean up test log files
		$test_files = glob( $this->log_dir . '/wptsall-*-test-*.log' );
		if ( $test_files ) {
			foreach ( $test_files as $file ) {
				unlink( $file );
			}
		}

		parent::tearDown();
	}

	/**
	 * Test get_log_dir returns correct path
	 */
	public function test_get_log_dir() {
		$log_dir = \WPTSALL\Log\get_log_dir();

		$this->assertNotEmpty( $log_dir );
		$this->assertStringContainsString( 'wp-content/uploads/wptsall-logs-', $log_dir );
		$this->assertMatchesRegularExpression( '/wptsall-logs-[0-9a-f]{8}$/', $log_dir, 'log dir name must carry the per-installation hash suffix (BUG-SEC-01 remediation)' );
	}

	/**
	 * The hash suffix must be exactly 8 hex chars and stable across calls.
	 */
	public function test_log_dir_suffix_is_stable_and_hashed() {
		$first = \WPTSALL\Log\wptsall_log_dir_suffix();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $first );
		$this->assertEquals( $first, \WPTSALL\Log\wptsall_log_dir_suffix(), 'suffix must be stable so log files are not orphaned' );
	}

	/**
	 * A pre-2.1.3 legacy wptsall-logs directory must migrate into the hashed
	 * directory on the first get_log_dir() call.
	 */
	public function test_get_log_dir_migrates_legacy_directory() {
		$upload_dir = wp_upload_dir();
		$base       = rtrim( (string) $upload_dir['basedir'], '/\\' );
		$legacy     = $base . '/wptsall-logs';
		$hashed     = $base . '/wptsall-logs-' . \WPTSALL\Log\wptsall_log_dir_suffix();

		$created_marker = false;
		if ( ! is_dir( $legacy ) && ! is_dir( $hashed ) ) {
			wp_mkdir_p( $legacy );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $legacy . '/marker.log', 'legacy' );
			$created_marker = true;
		}

		$dir = \WPTSALL\Log\get_log_dir();
		$this->assertEquals( $hashed, $dir );

		if ( $created_marker ) {
			$this->assertFileExists( $hashed . '/marker.log', 'legacy log files must move into the hashed directory' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $hashed . '/marker.log' );
		}
		$this->assertFalse( is_dir( $legacy ) && $created_marker, 'legacy directory must not remain after migration' );
	}

	/**
	 * Test log level priority values
	 */
	public function test_log_level_priority() {
		$this->assertEquals( 1, wptsall_log_level_priority( 'debug' ) );
		$this->assertEquals( 2, wptsall_log_level_priority( 'info' ) );
		$this->assertEquals( 3, wptsall_log_level_priority( 'warning' ) );
		$this->assertEquals( 4, wptsall_log_level_priority( 'error' ) );
		$this->assertEquals( 0, wptsall_log_level_priority( 'invalid' ) );
	}

	/**
	 * Test log channels list
	 */
	public function test_log_channels() {
		$channels = wptsall_log_channels();

		$this->assertIsArray( $channels );

		// 主模块频道
		$this->assertArrayHasKey( 'models', $channels );
		$this->assertArrayHasKey( 'sites', $channels );
		$this->assertArrayHasKey( 'tasks', $channels );
		$this->assertArrayHasKey( 'hooks', $channels );
		$this->assertArrayHasKey( 'templates', $channels );
		$this->assertArrayHasKey( 'core', $channels );
		$this->assertArrayHasKey( 'api', $channels );

		// 细分频道 - Models
		$this->assertArrayHasKey( 'models-scanner', $channels );
		$this->assertArrayHasKey( 'models-template', $channels );
		$this->assertArrayHasKey( 'models-api', $channels );

		// 细分频道 - Sites
		$this->assertArrayHasKey( 'sites-virtual', $channels );
		$this->assertArrayHasKey( 'sites-relations', $channels );
		$this->assertArrayHasKey( 'sites-api', $channels );

		// 细分频道 - Tasks
		$this->assertArrayHasKey( 'tasks-sync', $channels );
		$this->assertArrayHasKey( 'tasks-translation', $channels );
		$this->assertArrayHasKey( 'tasks-cron', $channels );
		$this->assertArrayHasKey( 'tasks-conflict', $channels );

		// 细分频道 - Templates
		$this->assertArrayHasKey( 'templates-scanner', $channels );
		$this->assertArrayHasKey( 'templates-entry', $channels );

		// 细分频道 - Hooks
		$this->assertArrayHasKey( 'hooks-manager', $channels );
		$this->assertArrayHasKey( 'hooks-router', $channels );

		// 细分频道 - Core
		$this->assertArrayHasKey( 'core-admin', $channels );
		$this->assertArrayHasKey( 'core-migration', $channels );
		$this->assertArrayHasKey( 'core-rest', $channels );

		// 兼容性短名频道
		$this->assertArrayHasKey( 'scanner', $channels );
		$this->assertArrayHasKey( 'sync', $channels );
		$this->assertArrayHasKey( 'virtual', $channels );
		$this->assertArrayHasKey( 'admin', $channels );

		// 验证频道数量 >= 40 (允许扩展)
		$this->assertGreaterThanOrEqual( 40, count( $channels ), 'Should have at least 40 log channels' );
	}

	/**
	 * Test wptsall_log_enabled returns boolean
	 */
	public function test_log_enabled() {
		$enabled = wptsall_log_enabled();

		$this->assertIsBool( $enabled );
	}

	/**
	 * Settings debug_mode must enable ops file logging when the const is off.
	 */
	public function test_debug_mode_enables_file_logging() {
		if ( ! class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			$this->markTestSkipped( 'Settings_Service missing' );
		}
		$before = \WPTSALL\Settings\Services\Settings_Service::get_all();
		\WPTSALL\Settings\Services\Settings_Service::update( array( 'debug_mode' => true ) );
		$this->assertTrue( wptsall_log_enabled(), 'debug_mode=true should enable wptsall_log_enabled()' );
		\WPTSALL\Settings\Services\Settings_Service::update(
			array( 'debug_mode' => ! empty( $before['debug_mode'] ) )
		);
	}

	/**
	 * Test wptsall_log_min_level returns valid level
	 */
	public function test_log_min_level() {
		$level = wptsall_log_min_level();

		$this->assertIsString( $level );
		$valid_levels = array( 'debug', 'info', 'warning', 'error' );
		$this->assertContains( $level, $valid_levels );
	}

	/**
	 * Test wptsall_should_log respects enabled flag
	 */
	public function test_should_log_respects_enabled() {
		// When logging is enabled (which it is in dev environment)
		if ( wptsall_log_enabled() ) {
			// Should return true for error level (always passes filter)
			$result = wptsall_should_log( 'core', 'error' );
			$this->assertTrue( $result );
		} else {
			// When disabled, should always return false
			$result = wptsall_should_log( 'core', 'error' );
			$this->assertFalse( $result );
		}
	}

	/**
	 * Test wptsall_log_file_path format
	 */
	public function test_log_file_path_format() {
		$path = wptsall_log_file_path( 'core' );

		$this->assertStringContainsString( 'wptsall-logs', $path );
		$this->assertStringContainsString( 'wptsall-core-', $path );
		$this->assertStringContainsString( '.log', $path );
		$this->assertStringContainsString( gmdate( 'Y-m-d' ), $path );
	}

	/**
	 * Test wptsall_log writes to file when enabled
	 */
	public function test_log_writes_to_file() {
		// Skip if logging is disabled
		if ( ! wptsall_log_enabled() ) {
			$this->markTestSkipped( 'Logging is disabled' );
		}

		$test_message = 'Test log message ' . uniqid();
		$result = wptsall_log( 'core', 'info', $test_message );

		$this->assertTrue( $result );

		// Verify file exists and contains message
		$log_file = wptsall_log_file_path( 'core' );
		$this->assertFileExists( $log_file );

		$content = file_get_contents( $log_file );
		$this->assertStringContainsString( $test_message, $content );
	}

	/**
	 * Test wptsall_log with context data
	 */
	public function test_log_with_context() {
		// Skip if logging is disabled
		if ( ! wptsall_log_enabled() ) {
			$this->markTestSkipped( 'Logging is disabled' );
		}

		$test_message = 'Test with context ' . uniqid();
		$context = array( 'key' => 'value', 'number' => 123 );

		$result = wptsall_log( 'core', 'info', $test_message, $context );

		$this->assertTrue( $result );

		// Verify context is in log
		$log_file = wptsall_log_file_path( 'core' );
		$content = file_get_contents( $log_file );
		$this->assertStringContainsString( 'Context:', $content );
		$this->assertStringContainsString( '"key":"value"', $content );
	}

	/**
	 * Test shortcut functions exist and work
	 */
	public function test_shortcut_functions_exist() {
		$this->assertTrue( function_exists( 'wptsall_log_debug' ) );
		$this->assertTrue( function_exists( 'wptsall_log_info' ) );
		$this->assertTrue( function_exists( 'wptsall_log_warning' ) );
		$this->assertTrue( function_exists( 'wptsall_log_error' ) );
	}

	/**
	 * Test wptsall_get_log_files returns array
	 */
	public function test_get_log_files() {
		$files = wptsall_get_log_files();

		$this->assertIsArray( $files );

		// If files exist, check structure
		if ( ! empty( $files ) ) {
			$first_file = $files[0];
			$this->assertArrayHasKey( 'path', $first_file );
			$this->assertArrayHasKey( 'name', $first_file );
			$this->assertArrayHasKey( 'size', $first_file );
			$this->assertArrayHasKey( 'modified', $first_file );
		}
	}

	/**
	 * Test wptsall_get_log_files with channel filter
	 */
	public function test_get_log_files_with_channel() {
		$files = wptsall_get_log_files( 'core' );

		$this->assertIsArray( $files );

		// All files should be for core channel
		foreach ( $files as $file ) {
			$this->assertStringContainsString( 'wptsall-core-', $file['name'] );
		}
	}

	/**
	 * Test wptsall_read_log_file with valid path
	 */
	public function test_read_log_file() {
		$files = wptsall_get_log_files( 'core' );

		if ( empty( $files ) ) {
			$this->markTestSkipped( 'No log files to read' );
		}

		$content = wptsall_read_log_file( $files[0]['path'], 10 );

		$this->assertIsString( $content );
	}

	/**
	 * Test wptsall_read_log_file rejects paths outside log directory
	 */
	public function test_read_log_file_security() {
		// Try to read a file outside log directory
		$content = wptsall_read_log_file( '/etc/passwd', 10 );

		$this->assertEmpty( $content );
	}

	/**
	 * Test wptsall_read_log_file with non-existent file
	 */
	public function test_read_log_file_nonexistent() {
		$content = wptsall_read_log_file( $this->log_dir . '/nonexistent.log', 10 );

		$this->assertEmpty( $content );
	}

	/**
	 * Test invalid channel falls back to core
	 */
	public function test_invalid_channel_fallback() {
		// Skip if logging is disabled
		if ( ! wptsall_log_enabled() ) {
			$this->markTestSkipped( 'Logging is disabled' );
		}

		$test_message = 'Invalid channel test ' . uniqid();
		$result = wptsall_log( 'invalid_channel', 'info', $test_message );

		$this->assertTrue( $result );

		// Should be written to core channel
		$log_file = wptsall_log_file_path( 'core' );
		$content = file_get_contents( $log_file );
		$this->assertStringContainsString( $test_message, $content );
	}

	/**
	 * Test invalid level defaults to info
	 */
	public function test_invalid_level_default() {
		// Skip if logging is disabled
		if ( ! wptsall_log_enabled() ) {
			$this->markTestSkipped( 'Logging is disabled' );
		}

		$test_message = 'Invalid level test ' . uniqid();
		$result = wptsall_log( 'core', 'invalid_level', $test_message );

		$this->assertTrue( $result );

		// Should be written with INFO level
		$log_file = wptsall_log_file_path( 'core' );
		$content = file_get_contents( $log_file );
		$this->assertStringContainsString( '[INFO]', $content );
		$this->assertStringContainsString( $test_message, $content );
	}

	/**
	 * Test log format includes timestamp
	 */
	public function test_log_format_timestamp() {
		// Skip if logging is disabled
		if ( ! wptsall_log_enabled() ) {
			$this->markTestSkipped( 'Logging is disabled' );
		}

		$test_message = 'Timestamp test ' . uniqid();
		wptsall_log( 'core', 'info', $test_message );

		$log_file = wptsall_log_file_path( 'core' );
		$content = file_get_contents( $log_file );

		// Should contain date format [YYYY-MM-DD HH:MM:SS]
		$this->assertMatchesRegularExpression( '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content );
	}

	/**
	 * Test wptsall_clean_old_logs function exists
	 */
	public function test_clean_old_logs_exists() {
		$this->assertTrue( function_exists( 'wptsall_clean_old_logs' ) );
	}

	/**
	 * Test wptsall_clean_old_logs returns integer
	 */
	public function test_clean_old_logs_returns_integer() {
		$deleted = wptsall_clean_old_logs( 365 ); // Use large number to not delete anything

		$this->assertIsInt( $deleted );
		$this->assertGreaterThanOrEqual( 0, $deleted );
	}

	/**
	 * Test the daily retention tick: old files deleted, fresh files kept,
	 * run timestamp stamped, and a second tick within one day is a no-op.
	 */
	public function test_log_retention_tick_daily_guard() {
		$old_file = $this->log_dir . '/wptsall-core-test-retention-' . uniqid() . '.log';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $old_file, "old\n" );
		touch( $old_file, time() - ( 40 * DAY_IN_SECONDS ) );

		$fresh_file = $this->log_dir . '/wptsall-core-test-retention-' . uniqid() . '.log';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $fresh_file, "fresh\n" );

		delete_option( 'wptsall_log_cleanup_last_run' );
		$deleted = \WPTSALL\Log\wptsall_log_retention_tick();

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertFalse( file_exists( $old_file ), 'old log file should be deleted' );
		$this->assertFileExists( $fresh_file );
		$this->assertGreaterThan( 0, (int) get_option( 'wptsall_log_cleanup_last_run' ) );

		// Fresh guard: a second tick within one day deletes nothing and returns 0.
		$fresh2 = $this->log_dir . '/wptsall-core-test-retention-' . uniqid() . '.log';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $fresh2, "fresh2\n" );
		touch( $fresh2, time() - ( 40 * DAY_IN_SECONDS ) );
		$this->assertSame( 0, \WPTSALL\Log\wptsall_log_retention_tick() );
		$this->assertFileExists( $fresh2 );

		unlink( $fresh_file );
		unlink( $fresh2 );
		delete_option( 'wptsall_log_cleanup_last_run' );
	}

	/**
	 * Line-prefix timestamps carry millisecond precision and an explicit UTC
	 * offset (audit 2.3: seconds-only, offset-less timestamps were ambiguous).
	 */
	public function test_log_timestamp_has_ms_and_utc_offset() {
		$ts = wptsall_log_timestamp();

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/',
			$ts,
			'timestamps must be Y-m-d H:i:s.v+00:00 (UTC, milliseconds, explicit offset)'
		);
	}

	/**
	 * Fresh log files start with a metadata header (audit 2.3 / matrix P2:
	 * no file header). Every header line is "#" prefixed so line parsers can
	 * skip it.
	 */
	public function test_log_file_header_carries_environment_metadata() {
		$header = wptsall_log_file_header();

		$this->assertStringStartsWith( '# === WPMMCC ATS debug log ===', $header );
		$this->assertStringContainsString( 'plugin: ' . WPTSALL_VERSION, $header );
		$this->assertStringContainsString( 'php: ' . PHP_VERSION, $header );
		$this->assertMatchesRegularExpression( '/multisite: (yes|no)/', $header );
		$this->assertStringContainsString( 'site tz: ', $header );
		$this->assertStringContainsString( 'log timestamps are utc', $header );

		foreach ( explode( "\n", trim( $header ) ) as $line ) {
			$this->assertStringStartsWith( '#', $line, 'header lines must be # prefixed' );
		}
	}

	/**
	 * Size rotation shifts the backup chain (.2→.3, .1→.2, live→.1) and caps
	 * at 3 backups (audit matrix P2: append-only growth had no per-file cap).
	 *
	 * catalog: WP-HOOK-wptsall-log-max-file-size
	 * oracle: L1
	 */
	public function test_maybe_rotate_log_file_shifts_backups() {
		$base = $this->log_dir . '/wptsall-core-test-rotate-' . uniqid() . '.log';
		$suffixes = array( '', '.1', '.2', '.3', '.4' );

		$files = array();
		foreach ( $suffixes as $suffix ) {
			$files[ $suffix ] = $base . $suffix;
		}

		try {
			// Live file above the cap; .1/.2 exist; .3 pre-exists (oldest slot);
			// .4 must never appear.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $files[''], "live-content-longer-than-cap\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $files['.1'], "backup-one\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $files['.2'], "backup-two\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $files['.3'], "backup-three-oldest\n" );

			add_filter( 'wptsall_log_max_file_size', array( $this, '_tiny_log_cap' ) );
			$rotated = wptsall_maybe_rotate_log_file( $files[''] );
			remove_filter( 'wptsall_log_max_file_size', array( $this, '_tiny_log_cap' ) );

			$this->assertTrue( $rotated, 'file above the cap must rotate' );
			$this->assertFileExists( $files['.1'] );
			$this->assertSame( "live-content-longer-than-cap\n", file_get_contents( $files['.1'] ), 'live file must move to .1' );
			$this->assertSame( "backup-one\n", file_get_contents( $files['.2'] ), 'old .1 must shift to .2' );
			$this->assertSame( "backup-two\n", file_get_contents( $files['.3'] ), 'old .2 must shift to .3' );
			$this->assertFalse( file_exists( $files['.4'] ), 'rotation must cap at 3 backups' );
			$this->assertFalse( file_exists( $files[''] ), 'live file must be renamed away' );
		} finally {
			foreach ( $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}

	/**
	 * Files under the cap are left untouched and rotation reports false.
	 */
	public function test_maybe_rotate_log_file_noop_under_cap() {
		$base = $this->log_dir . '/wptsall-core-test-rotate-' . uniqid() . '.log';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $base, "tiny\n" );

		try {
			$this->assertFalse( wptsall_maybe_rotate_log_file( $base ), 'file under the default cap must not rotate' );
			$this->assertSame( "tiny\n", file_get_contents( $base ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $base );
		}
	}

	/**
	 * Filter callback: 10-byte cap for rotation tests.
	 *
	 * @return int
	 */
	public function _tiny_log_cap() {
		return 10;
	}
}
