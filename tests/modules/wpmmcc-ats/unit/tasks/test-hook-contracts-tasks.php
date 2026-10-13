<?php
/**
 * Tasks-layer hook contract tests (L1).
 *
 * Extension-point contracts for the automation cron (event dispatch +
 * tunables), the task-job planner priority maps, the sync-executor media
 * and conflict-strategy tunables, and the write-back adapter registration
 * event. Each test proves the hook fires at its call site with the
 * documented default/args and, wherever cheaply observable, that the
 * listener return flips the documented behavior.
 *
 * catalog: WP-HOOK-wptsall-sync-cron
 * catalog: WP-HOOK-wptsall-process-pending-sync-tasks
 * catalog: WP-HOOK-wptsall-max-stuck-recovery-count
 * catalog: WP-HOOK-wptsall-retry-batch-size
 * catalog: WP-HOOK-wptsall-daily-cleanup-done
 * catalog: WP-HOOK-wptsall-weekly-maintenance-done
 * catalog: WP-HOOK-wptsall-task-job-business-line-priority
 * catalog: WP-HOOK-wptsall-task-job-object-type-priority
 * catalog: WP-HOOK-wptsall-max-media-per-sync
 * catalog: WP-HOOK-wptsall-sync-conflict-strategy
 * catalog: WP-HOOK-wptsall-register-write-back-adapters
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit\Tasks
 * @since 2.1.4
 */

use WPTSALL\Tasks\Sync\Sync_Executor;
use WPTSALL\Tasks\Sync\Write_Back_Dispatcher;
use WPTSALL\Tasks\Sync\Write_Back_Adapter_Interface;
use WPTSALL\Tasks\Services\Task_Job_Planner;

// NOTE: the stub adapter class is declared at the BOTTOM of this file — the
// simple runner picks the FIRST class in a file as the test class.

class Test_Hook_Contracts_Tasks extends SimpleTestCase {

	/**
	 * Captured hook invocations: hook => list of args arrays.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Captured action-arg counts for do_action captures.
	 *
	 * @var array
	 */
	private $captured_actions = array();

	/**
	 * Register a capturing filter listener.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $override Return override callback.
	 */
	private function capture_filter( $hook, $override = null ) {
		$captured = &$this->captured;
		add_filter(
			$hook,
			function ( $value ) use ( &$captured, $hook, $override ) {
				$args                = func_get_args();
				$captured[ $hook ][] = $args;
				if ( null === $override ) {
					return $value;
				}
				return is_callable( $override ) ? call_user_func_array( $override, $args ) : $override;
			},
			10,
			8
		);
	}

	/**
	 * Register a capturing action listener.
	 *
	 * @param string $hook Hook name.
	 */
	private function capture_action( $hook ) {
		$captured_actions = &$this->captured_actions;
		add_action(
			$hook,
			function () use ( &$captured_actions, $hook ) {
				$captured_actions[ $hook ][] = func_get_args();
			},
			10,
			8
		);
	}

	/**
	 * Invoke a private/protected static method.
	 *
	 * @param string $class Class name.
	 * @param string $method Method name.
	 * @param array  $args Method args.
	 * @return mixed
	 */
	private function invoke_private( $class, $method, $args = array() ) {
		$m = new ReflectionMethod( $class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}

	/**
	 * Reset a private/protected static property on a class.
	 *
	 * @param string $class Class name.
	 * @param string $prop  Property name.
	 * @param mixed  $value Value to set.
	 */
	private function reset_static( $class, $prop, $value = null ) {
		$rp = new ReflectionProperty( $class, $prop );
		$rp->setAccessible( true );
		$rp->setValue( null, $value );
	}

	public function setUp(): void {
		parent::setUp();
		$this->captured        = array();
		$this->captured_actions = array();
	}

	public function tearDown(): void {
		foreach ( array_keys( $this->captured ) as $hook ) {
			remove_all_filters( $hook, 10 );
		}
		foreach ( array_keys( $this->captured_actions ) as $hook ) {
			remove_all_filters( $hook, 10 );
		}
		$this->captured         = array();
		$this->captured_actions = array();
		// Restore the write-back dispatcher to its pristine lazy state so the
		// stub adapter never leaks into later files (defaults re-init lazily).
		$this->reset_static( Write_Back_Dispatcher::class, 'initialized', false );
		$this->reset_static( Write_Back_Dispatcher::class, 'adapters', array() );
		parent::tearDown();
	}

	// ==================== cron convention actions ====================

	/**
	 * wptsall_sync_cron / wptsall_process_pending_sync_tasks: cron job
	 * dispatch fires the convention action only when a listener exists.
	 */
	public function test_cron_convention_actions_contract() {
		$this->assertFalse(
			wptsall_trigger_cron_job( 'wptsall_sync_cron' ),
			'without a listener the dispatch must report nothing-to-do (false)'
		);

		$this->capture_action( 'wptsall_sync_cron' );
		$this->assertTrue( wptsall_trigger_cron_job( 'wptsall_sync_cron' ), 'with a listener the dispatch must fire it and report true' );
		$this->assertNotEmpty( $this->captured_actions['wptsall_sync_cron'], 'the convention action must fire with no args' );

		$this->assertFalse( wptsall_trigger_cron_job( 'wptsall_process_pending_sync_tasks' ) );
		$this->capture_action( 'wptsall_process_pending_sync_tasks' );
		$this->assertTrue( wptsall_trigger_cron_job( 'wptsall_process_pending_sync_tasks' ) );
		$this->assertNotEmpty( $this->captured_actions['wptsall_process_pending_sync_tasks'] );
	}

	/**
	 * wptsall_max_stuck_recovery_count / wptsall_retry_batch_size: cron
	 * tunables fire with their documented defaults (3 / 10).
	 */
	public function test_cron_tunables_contract() {
		$this->capture_filter( 'wptsall_max_stuck_recovery_count' );
		wptsall_recover_stuck_tasks();
		$calls = $this->captured['wptsall_max_stuck_recovery_count'];
		$this->assertNotEmpty( $calls, 'tunable must be consulted by the stuck-task recovery pass' );
		$this->assertSame( 3, $calls[0][0], 'default max stuck recovery count must be 3' );

		$this->capture_filter( 'wptsall_retry_batch_size' );
		wptsall_retry_failed_tasks();
		$calls = $this->captured['wptsall_retry_batch_size'];
		$this->assertNotEmpty( $calls, 'tunable must be consulted by the retry pass' );
		$this->assertSame( 10, $calls[0][0], 'default retry batch size must be 10' );
	}

	/**
	 * wptsall_daily_cleanup_done / wptsall_weekly_maintenance_done: fired
	 * at the end of the corresponding maintenance pass with a stats array.
	 */
	public function test_maintenance_done_actions_contract() {
		$this->capture_action( 'wptsall_daily_cleanup_done' );
		wptsall_daily_cleanup();
		$calls = $this->captured_actions['wptsall_daily_cleanup_done'];
		$this->assertNotEmpty( $calls, 'daily cleanup completion must fire the convention action' );
		$this->assertIsArray( $calls[0][0], 'the action must receive the stats array' );
		$this->assertArrayHasKey( 'logs_rotated', $calls[0][0], 'stats must report the log rotation count' );

		$this->capture_action( 'wptsall_weekly_maintenance_done' );
		wptsall_weekly_maintenance();
		$calls = $this->captured_actions['wptsall_weekly_maintenance_done'];
		$this->assertNotEmpty( $calls, 'weekly maintenance completion must fire the convention action' );
		$this->assertIsArray( $calls[0][0], 'the action must receive the stats array' );
	}

	// ==================== planner priority maps ====================

	/**
	 * wptsall_task_job_business_line_priority: fired with the default map;
	 * listener overrides reach the normalized priority map.
	 */
	public function test_business_line_priority_contract() {
		$default = $this->invoke_private( Task_Job_Planner::class, 'get_business_line_priority_map' );
		$this->assertIsArray( $default );
		$this->assertNotEmpty( $default, 'the default business-line priority map must not be empty' );

		$this->capture_filter(
			'wptsall_task_job_business_line_priority',
			function ( $map ) {
				$map['post_content'] = 5;
				return $map;
			}
		);
		$extended = $this->invoke_private( Task_Job_Planner::class, 'get_business_line_priority_map' );
		$this->assertSame( 5, $extended['post_content'], 'listener override of a default business line must reach the normalized map' );

		$calls = $this->captured['wptsall_task_job_business_line_priority'];
		$this->assertNotEmpty( $calls );
		$this->assertIsArray( $calls[0][0], 'first arg must be the default priority map' );
	}

	/**
	 * wptsall_task_job_object_type_priority: fired with the default map;
	 * listener overrides reach the normalized priority map.
	 */
	public function test_object_type_priority_contract() {
		$default = $this->invoke_private( Task_Job_Planner::class, 'get_object_type_priority_map' );
		$this->assertIsArray( $default );
		$this->assertNotEmpty( $default, 'the default object-type priority map must not be empty' );

		$this->capture_filter(
			'wptsall_task_job_object_type_priority',
			function ( $map ) {
				$map['post_type'] = 5;
				return $map;
			}
		);
		$extended = $this->invoke_private( Task_Job_Planner::class, 'get_object_type_priority_map' );
		$this->assertSame( 5, $extended['post_type'], 'listener override of a default object type must reach the normalized map' );

		$calls = $this->captured['wptsall_task_job_object_type_priority'];
		$this->assertNotEmpty( $calls );
	}

	// ==================== sync-executor tunables ====================

	/**
	 * wptsall_max_media_per_sync: consulted with default 20 at the head of
	 * the embedded-media sideload pass.
	 */
	public function test_max_media_per_sync_contract() {
		$this->capture_filter( 'wptsall_max_media_per_sync' );
		// The filter fires before any post guards, so a null post is a safe
		// trigger for the consultation contract.
		$this->invoke_private(
			Sync_Executor::class,
			'sideload_embedded_media_in_content',
			array( 0, array(), array() )
		);
		$calls = $this->captured['wptsall_max_media_per_sync'];
		$this->assertNotEmpty( $calls, 'tunable must be consulted by the embedded-media sideload pass' );
		$this->assertSame( 20, $calls[0][0], 'default max media per sync must be 20' );
	}

	/**
	 * wptsall_sync_conflict_strategy: on a target modified after the last
	 * sync, the strategy filter fires with the documented args; a missing
	 * relation (42 here) falls back to the historical 'log_and_overwrite'
	 * default and proceeds (true), and 'skip' aborts (false). X-1: existing
	 * relations drive the default from their canonical conflict_strategy.
	 */
	public function test_sync_conflict_strategy_contract() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		// Seed a last-synced marker in the PAST so the fresh post_modified
		// counts as a newer local edit (conflict state).
		update_post_meta( $post_id, '_wptsall_last_synced', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

		// Default: log_and_overwrite -> conflict check proceeds.
		$proceeds = $this->invoke_private(
			Sync_Executor::class,
			'check_sync_conflict',
			array( $post_id, 42, 'sync_post' )
		);
		$this->assertTrue( $proceeds, 'default strategy log_and_overwrite must let the sync proceed' );

		// Escape hatch: skip -> conflict check aborts.
		$this->capture_filter( 'wptsall_sync_conflict_strategy', 'skip' );
		$aborts = $this->invoke_private(
			Sync_Executor::class,
			'check_sync_conflict',
			array( $post_id, 42, 'sync_post' )
		);
		$this->assertFalse( $aborts, 'strategy skip must abort the sync' );

		$calls = $this->captured['wptsall_sync_conflict_strategy'];
		$this->assertNotEmpty( $calls );
		$this->assertSame( 'log_and_overwrite', $calls[0][0], 'default strategy must be log_and_overwrite' );
		$this->assertSame( $post_id, $calls[0][1], 'second arg must be the target post id' );
		$this->assertSame( 42, $calls[0][2], 'third arg must be the relation id' );
		$this->assertSame( 'sync_post', $calls[0][3], 'fourth arg must be the sync path' );
		$this->assertNotSame( '', $calls[0][4], 'fifth arg must be the last-synced timestamp' );
	}

	// ==================== write-back registration ====================

	/**
	 * wptsall_register_write_back_adapters: fired once during dispatcher
	 * init with the default adapters already registered; a listener can
	 * register additional adapters.
	 */
	public function test_register_write_back_adapters_contract() {
		$this->reset_static( Write_Back_Dispatcher::class, 'initialized', false );
		$this->reset_static( Write_Back_Dispatcher::class, 'adapters', array() );

		$this->capture_action( 'wptsall_register_write_back_adapters' );
		// The action listener registers a stub adapter through the public API.
		add_action(
			'wptsall_register_write_back_adapters',
			static function () {
				Write_Back_Dispatcher::register_adapter( new Hook_Contract_Stub_Write_Back_Adapter() );
			},
			11
		);
		try {
			$adapters = Write_Back_Dispatcher::get_adapters();
			$this->assertNotEmpty( $this->captured_actions['wptsall_register_write_back_adapters'], 'init must fire the registration event' );
			$this->assertArrayHasKey( 'attachment', $adapters, 'default attachment adapter must be registered before the event fires' );
			$this->assertArrayHasKey( 'hooktest_stub', $adapters, 'event-registered adapter must be visible through the public registry' );
		} finally {
			remove_all_filters( 'wptsall_register_write_back_adapters', 11 );
		}
	}
}

/**
 * Minimal write-back adapter stub used to prove the registration event.
 * Declared after the test class (the runner picks the first class as the
 * test class; both are loaded by require_once before methods execute).
 */
class Hook_Contract_Stub_Write_Back_Adapter implements Write_Back_Adapter_Interface {
	public function get_type(): string {
		return 'hooktest_stub';
	}
	public function get_supported_ref_types(): array {
		return array();
	}
	public function validate_ref( array $translated_ref ) {
		return true;
	}
	public function apply( array $item, array $context ): array {
		return $item;
	}
	public function can_handle( array $item ): bool {
		return false;
	}
}
