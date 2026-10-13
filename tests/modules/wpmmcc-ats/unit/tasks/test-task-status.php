<?php
/**
 * Task status vocabulary SSOT tests (opus5 A-02)
 *
 * Pins the closed status set, the retry-eligible set, the open-states set
 * and the badge maps to the Task_Status single source of truth, and proves
 * the procedural helpers delegate to it instead of carrying their own
 * literal lists.
 *
 * @package WPTSALL
 * @since 2.1.4
 */

use WPTSALL\Tasks\Services\Task_Status;

/**
 * Test_Task_Status
 */
class Test_Task_Status extends SimpleTestCase {

	/**
	 * The closed vocabulary matches the pre-A-02 set exactly.
	 */
	public function test_all_returns_the_closed_vocabulary() {
		$this->assertSame(
			array( 'pending', 'processing', 'active', 'paused', 'completed', 'skipped', 'retry', 'failed', 'error' ),
			Task_Status::all()
		);
	}

	/**
	 * Procedural validation gate delegates to the SSOT.
	 */
	public function test_procedural_validity_gate_delegates() {
		foreach ( Task_Status::all() as $status ) {
			$this->assertTrue( wptsall_is_valid_task_status( $status ), "vocabulary member rejected: {$status}" );
		}
		$this->assertFalse( wptsall_is_valid_task_status( 'bogus' ) );
		$this->assertFalse( wptsall_is_valid_task_status( '' ) );
	}

	/**
	 * Constants equal their literals (call sites may use either).
	 */
	public function test_constants_match_literals() {
		$this->assertSame( 'pending', Task_Status::PENDING );
		$this->assertSame( 'processing', Task_Status::PROCESSING );
		$this->assertSame( 'active', Task_Status::ACTIVE );
		$this->assertSame( 'paused', Task_Status::PAUSED );
		$this->assertSame( 'completed', Task_Status::COMPLETED );
		$this->assertSame( 'skipped', Task_Status::SKIPPED );
		$this->assertSame( 'retry', Task_Status::RETRY );
		$this->assertSame( 'failed', Task_Status::FAILED );
		$this->assertSame( 'error', Task_Status::ERROR );
	}

	/**
	 * The retry cron whitelist is exactly retry + error, both in-vocabulary.
	 */
	public function test_retry_eligible_set() {
		$this->assertSame( array( 'retry', 'error' ), Task_Status::retry_eligible() );
		foreach ( Task_Status::retry_eligible() as $status ) {
			$this->assertTrue( Task_Status::is_valid( $status ) );
		}
		// Terminal states are never retry-eligible.
		$this->assertNotContains( 'failed', Task_Status::retry_eligible() );
		$this->assertNotContains( 'completed', Task_Status::retry_eligible() );
	}

	/**
	 * Open-states list (insert dedupe) delegates to the SSOT.
	 */
	public function test_open_states_for_insert_dedupe_delegate() {
		$this->assertSame(
			array( 'pending', 'retry', 'processing', 'active' ),
			Task_Status::open_states()
		);
		$this->assertSame( Task_Status::open_states(), wptsall_get_open_task_statuses_for_insert() );
	}

	/**
	 * Badge maps cover every vocabulary member — a status can never render
	 * gray in one admin screen and colored in another.
	 */
	public function test_badge_maps_cover_entire_vocabulary() {
		foreach ( Task_Status::all() as $status ) {
			$this->assertArrayHasKey( $status, Task_Status::badge_colors(), "badge color missing for {$status}" );
			$this->assertArrayHasKey( $status, Task_Status::badge_labels(), "badge label missing for {$status}" );
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/i', Task_Status::badge_colors()[ $status ] );
			$this->assertNotSame( '', (string) Task_Status::badge_labels()[ $status ] );
		}

		// Unknown statuses are absent — renderers fall back, they never map.
		$this->assertArrayNotHasKey( 'bogus', Task_Status::badge_colors() );
		$this->assertArrayNotHasKey( 'bogus', Task_Status::badge_labels() );
	}
}
