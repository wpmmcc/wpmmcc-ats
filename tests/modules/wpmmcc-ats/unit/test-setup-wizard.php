<?php
/**
 * Setup Wizard Tests
 *
 * Tests for WPTSALL\Wizard\Setup_Wizard — only the parts that are safe to
 * exercise in a unit-test process: completion-state option handling, hook
 * wiring and the dashboard-nudge gating. The admin-post handlers
 * (handle_step/handle_skip) end in wp_safe_redirect()+exit and the full page
 * render needs an admin screen, so they are intentionally not invoked here.
 *
 * @package WPTSALL\Tests\Unit\Wizard
 * @since 1.2.0
 */

use WPTSALL\Wizard\Setup_Wizard;

class Test_Setup_Wizard extends SimpleTestCase {

	public function tearDown(): void {
		delete_option( Setup_Wizard::OPTION );
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_meta_boxes'] );
		// set_current_screen() leaks an admin screen into is_admin(); reset it
		// so later tests in the same process stay front-end.
		unset( $GLOBALS['current_screen'], $GLOBALS['screen'] );
		parent::tearDown();
	}

	// ==================== Constants ====================

	public function test_constants_match_expected_values() {
		$this->assertSame( 'wptsall-wizard', Setup_Wizard::PAGE_SLUG );
		$this->assertSame( 'wptsall_wizard_state', Setup_Wizard::OPTION );
		$this->assertSame( 'manage_wptsall_settings', Setup_Wizard::CAP );
	}

	// ==================== is_completed ====================

	public function test_is_completed_defaults_to_false() {
		delete_option( Setup_Wizard::OPTION );
		$this->assertFalse( Setup_Wizard::is_completed() );
	}

	public function test_is_completed_true_when_state_completed() {
		delete_option( Setup_Wizard::OPTION );
		update_option( Setup_Wizard::OPTION, array( 'step' => 5, 'completed' => true ) );
		$this->assertTrue( Setup_Wizard::is_completed() );
	}

	public function test_is_completed_false_for_incomplete_or_corrupt_state() {
		update_option( Setup_Wizard::OPTION, array( 'step' => 2, 'completed' => false ) );
		$this->assertFalse( Setup_Wizard::is_completed() );

		update_option( Setup_Wizard::OPTION, array( 'step' => 3 ) );
		$this->assertFalse( Setup_Wizard::is_completed() );

		// Corrupted (non-array) option value must not be fatal and reads as incomplete.
		update_option( Setup_Wizard::OPTION, 'garbage-not-an-array' );
		$this->assertFalse( Setup_Wizard::is_completed() );
	}

	// ==================== Hook wiring ====================

	public function test_init_registers_wizard_hooks() {
		Setup_Wizard::init();

		$this->assertNotFalse( has_action( 'admin_menu', array( 'WPTSALL\Wizard\Setup_Wizard', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_wptsall_wizard_step', array( 'WPTSALL\Wizard\Setup_Wizard', 'handle_step' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_wptsall_wizard_skip', array( 'WPTSALL\Wizard\Setup_Wizard', 'handle_skip' ) ) );
		$this->assertNotFalse( has_action( 'wp_dashboard_setup', array( 'WPTSALL\Wizard\Setup_Wizard', 'maybe_add_dashboard_widget' ) ) );
	}

	// ==================== Dashboard nudge gating ====================

	public function test_maybe_add_dashboard_widget_gated_by_completion_and_cap() {
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			$this->markTestSkipped( 'wp_add_dashboard_widget unavailable' );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		if ( ! current_user_can( Setup_Wizard::CAP ) ) {
			$this->markTestSkipped( 'administrator role lacks manage_wptsall_settings in this environment' );
		}
		// wp_add_dashboard_widget() stores into $wp_meta_boxes keyed by the
		// current screen, so a dashboard screen must be set in CLI context.
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'dashboard' );
		}

		// Incomplete wizard + capable user -> nudge widget registered.
		delete_option( Setup_Wizard::OPTION );
		unset( $GLOBALS['wp_meta_boxes'] );
		Setup_Wizard::maybe_add_dashboard_widget();
		$this->assertArrayHasKey(
			'wptsall-wizard-nudge',
			$GLOBALS['wp_meta_boxes']['dashboard']['normal']['core']
		);

		// Completed wizard -> no nudge widget.
		update_option( Setup_Wizard::OPTION, array( 'step' => 5, 'completed' => true ) );
		unset( $GLOBALS['wp_meta_boxes'] );
		Setup_Wizard::maybe_add_dashboard_widget();
		$this->assertFalse(
			isset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core']['wptsall-wizard-nudge'] )
		);

		// Capable user removed -> no nudge widget even when incomplete.
		delete_option( Setup_Wizard::OPTION );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		unset( $GLOBALS['wp_meta_boxes'] );
		Setup_Wizard::maybe_add_dashboard_widget();
		$this->assertFalse(
			isset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core']['wptsall-wizard-nudge'] )
		);
	}

	public function test_render_dashboard_widget_outputs_cta_link() {
		ob_start();
		Setup_Wizard::render_dashboard_widget();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Start Setup Wizard', $html );
		$this->assertStringContainsString( 'wptsall-wizard', $html );
		// The CTA must link into the wizard admin page, not somewhere arbitrary.
		$this->assertStringContainsString( 'page=wptsall-wizard', $html );
	}
}
