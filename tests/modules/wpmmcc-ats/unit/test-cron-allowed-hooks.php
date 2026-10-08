<?php
/**
 * Cron allowlist negative-path tests
 *
 * Covers wptsall_get_allowed_cron_job_hooks() and the manual-trigger
 * validation path wptsall_trigger_cron_job():
 *  - allowlist contains only fixed wptsall_* hook literals
 *  - allowlisted hooks are accepted and actually fire
 *  - non-allowlisted hooks (core hooks, arbitrary strings, non-strings)
 *    are rejected fail-closed
 *  - allowlist filter removal blocks triggering; filter addition cannot
 *    introduce new fireable hooks (switch is limited to fixed literals)
 *  - schedule/clear lifecycle for the allowlisted cron hooks
 *
 * @package WPTSALL
 * @since 2.1.0
 */

class Test_Cron_Allowed_Hooks extends WP_UnitTestCase {

	/**
	 * Hooks scheduled before the lifecycle test (restored in tearDown).
	 *
	 * @var array
	 */
	private $previously_scheduled = array();

	public function tearDown(): void {
		// Restore any cron events the lifecycle test cleared.
		foreach ( $this->previously_scheduled as $hook => $ts ) {
			if ( false !== $ts && ! wp_next_scheduled( $hook ) ) {
				wp_schedule_single_event( $ts, $hook );
			}
		}
		$this->previously_scheduled = array();

		// Never leave a test filter behind.
		remove_all_filters( 'wptsall_allowed_cron_job_hooks' );

		parent::tearDown();
	}

	// ==================== Allowlist contents ====================

	/**
	 * The allowlist must be a non-empty list of fixed wptsall_* literals.
	 */
	public function test_allowlist_contains_only_fixed_wptsall_literals() {
		$this->assertTrue( function_exists( 'wptsall_get_allowed_cron_job_hooks' ) );

		$allowed = wptsall_get_allowed_cron_job_hooks();
		$this->assertIsArray( $allowed );
		$this->assertNotEmpty( $allowed, 'Allowlist must not be empty' );

		$known = array( 'wptsall_daily_cleanup', 'wptsall_weekly_maintenance', 'wptsall_retry_failed_tasks' );
		foreach ( $known as $hook ) {
			$this->assertContains( $hook, $allowed, "Allowlist must contain the core cron hook '{$hook}'" );
		}
		foreach ( $allowed as $hook ) {
			$this->assertIsString( $hook, 'Every entry must be a string literal' );
			$this->assertStringStartsWith( 'wptsall_', $hook, "Every entry must be plugin-prefixed, got '{$hook}'" );
		}
		// The plugin actually schedules hooks from this allowlist.
		$this->assertContains( 'wptsall_process_monitoring_tasks', $allowed );
	}

	// ==================== Acceptance path ====================

	/**
	 * An allowlisted hook with a registered action is accepted and the
	 * action actually fires exactly once.
	 */
	public function test_trigger_accepts_allowlisted_hook_and_fires_action() {
		$fired = 0;
		$recorder = static function () use ( &$fired ) {
			$fired++;
		};
		// This allowlisted hook has no default plugin callback; attaching a
		// test listener keeps the assertion free of plugin side effects.
		add_action( 'wptsall_process_high_priority_tasks', $recorder );

		$this->assertTrue( wptsall_trigger_cron_job( 'wptsall_process_high_priority_tasks' ), 'Allowlisted hook must be accepted' );
		$this->assertSame( 1, $fired, 'The registered action must fire exactly once' );

		remove_action( 'wptsall_process_high_priority_tasks', $recorder );
	}

	// ==================== Rejection paths ====================

	/**
	 * Non-allowlisted hooks must be rejected fail-closed: core WP hooks,
	 * arbitrary wptsall_-prefixed strings, empty and non-string input.
	 */
	public function test_trigger_rejects_non_allowlisted_hooks() {
		// Core WP hooks are never fireable through the manual trigger.
		$core_fired = 0;
		add_action( 'init', static function () use ( &$core_fired ) {
			$core_fired++;
		} );
		$this->assertFalse( wptsall_trigger_cron_job( 'init' ), 'Core WP hook must be rejected' );

		// Arbitrary wptsall_* strings outside the allowlist are rejected.
		$this->assertFalse( wptsall_trigger_cron_job( 'wptsall_test_fabricated_hook' ), 'Non-allowlisted plugin-style hook must be rejected' );
		$this->assertFalse( wptsall_trigger_cron_job( 'wptsall_daily_cleanup_evil' ), 'Suffix-mimicking hook must be rejected' );

		// Empty / non-string input is rejected without errors.
		$this->assertFalse( wptsall_trigger_cron_job( '' ) );
		$this->assertFalse( wptsall_trigger_cron_job( array( 'wptsall_daily_cleanup' ) ) );
		$this->assertFalse( wptsall_trigger_cron_job( 123 ) );

		// Rejection must not have fired the core hook.
		$this->assertSame( 0, $core_fired );
	}

	/**
	 * Allowlisted-but-unregistered hooks are refused, and the allowlist
	 * filter can only narrow, never widen, the set of fireable hooks.
	 */
	public function test_trigger_fail_closed_for_edge_cases() {
		// Allowlisted hook with no registered action: the has_action guard
		// refuses to fire it.
		$this->assertFalse(
			wptsall_trigger_cron_job( 'wptsall_cleanup_old_tasks' ),
			'Allowlisted hook without a registered action must return false'
		);

		// Filter can REMOVE a hook from the allowlist: triggering is blocked
		// even though the action is registered.
		$recorder = static function () {};
		add_action( 'wptsall_process_normal_tasks', $recorder );
		add_filter( 'wptsall_allowed_cron_job_hooks', static function ( $allowed ) {
			return array_values( array_diff( $allowed, array( 'wptsall_process_normal_tasks' ) ) );
		} );
		$this->assertFalse(
			wptsall_trigger_cron_job( 'wptsall_process_normal_tasks' ),
			'Hook removed from the allowlist via filter must be rejected'
		);

		// Filter ADDING a hook passes the in_array check but the fixed switch
		// still refuses to fire it (Plugin Check: no dynamic do_action).
		remove_all_filters( 'wptsall_allowed_cron_job_hooks' );
		$fired = 0;
		$custom = static function () use ( &$fired ) {
			$fired++;
		};
		add_action( 'wptsall_test_extension_hook', $custom );
		add_filter( 'wptsall_allowed_cron_job_hooks', static function ( $allowed ) {
			$allowed[] = 'wptsall_test_extension_hook';
			return $allowed;
		} );
		$this->assertFalse(
			wptsall_trigger_cron_job( 'wptsall_test_extension_hook' ),
			'Filter-added hooks must stay fireable-refused (fixed switch fail-closed)'
		);
		$this->assertSame( 0, $fired, 'Filter-added hook must not have fired' );
	}

	// ==================== Schedule / clear lifecycle ====================

	/**
	 * wptsall_setup_cron_jobs() schedules the standard allowlisted hooks
	 * and wptsall_clear_cron_jobs() clears them again.
	 */
	public function test_schedule_and_clear_lifecycle() {
		$hooks = array(
			'wptsall_retry_failed_tasks',
			'wptsall_daily_cleanup',
			'wptsall_weekly_maintenance',
			'wptsall_process_monitoring_tasks',
		);

		// Baseline: clear everything and remember the original state.
		foreach ( $hooks as $hook ) {
			$this->previously_scheduled[ $hook ] = wp_next_scheduled( $hook );
		}
		wptsall_clear_cron_jobs();
		foreach ( $hooks as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), "Baseline: '{$hook}' must not be scheduled" );
		}

		// Setup: all four allowlisted hooks get scheduled.
		wptsall_setup_cron_jobs();
		foreach ( $hooks as $hook ) {
			$this->assertNotFalse(
				wp_next_scheduled( $hook ),
				"setup_cron_jobs() must schedule the allowlisted hook '{$hook}'"
			);
		}

		// Idempotency: a second setup must not duplicate the events.
		$before = array_map( 'wp_next_scheduled', $hooks );
		wptsall_setup_cron_jobs();
		foreach ( $hooks as $i => $hook ) {
			$this->assertSame( $before[ $i ], wp_next_scheduled( $hook ), "Second setup must not duplicate '{$hook}'" );
		}

		// Clear: everything is gone again.
		wptsall_clear_cron_jobs();
		foreach ( $hooks as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), "clear_cron_jobs() must clear '{$hook}'" );
		}
	}
}
