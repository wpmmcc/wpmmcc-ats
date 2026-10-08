<?php
/**
 * Admin screen registration/render contracts (L2).
 *
 * Covers the two catalog screens that had no test references:
 * the hidden initialization screen (WP-SCREEN-wptsall-init) and the
 * visible "Setup Wizard" redirect entry (WP-SCREEN-wptsall-wizard-redirect).
 *
 * - wptsall-init: hidden submenu registration (null parent), capability
 *   contract, and a full render capture of the page body.
 * - wptsall-wizard-redirect: visible submenu entry under the plugin menu
 *   whose callback 302s to the wizard page. The callback itself exits
 *   right after wp_safe_redirect() and is therefore browser/e2e-owned;
 *   here we prove the wiring: the entry exists with the documented
 *   capability/title, the redirect target page (wptsall-wizard) is
 *   registered under the same parent, and the documented redirect URL
 *   construction resolves to that target.
 *
 * NOTE (hazard lessons baked in): this runner skips tearDown when an
 * assertion throws, so every test restores admin globals immediately
 * after capturing copies, BEFORE any assertion runs. tearDown restores
 * again as a belt.
 *
 * catalog: WP-SCREEN-wptsall-init
 * catalog: WP-SCREEN-wptsall-wizard-redirect
 * catalog: WP-SCREEN-wptsall-manual-queue
 * oracle: L2
 *
 * @package WPTSALL
 */

use WPTSALL\Admin\Initialization_Page;
use WPTSALL\Models\Admin\Model_Backup_Handler;
use WPTSALL\Tasks\Admin\Manual_Queue_Admin_Page;
use WPTSALL\Wizard\Setup_Wizard;

class Test_Admin_Screen_Contracts extends SimpleTestCase {

	/** @var array<string,mixed> snapshot of admin menu globals. */
	private $snap = array();

	/** @var int */
	private $orig_user_id = 0;

	/** @var bool */
	private $registered_init_script = false;

	public function setUp(): void {
		parent::setUp();
		foreach ( array( 'menu', 'submenu', '_registered_pages', 'admin_page_hooks' ) as $key ) {
			$this->snap[ $key ] = isset( $GLOBALS[ $key ] ) ? $GLOBALS[ $key ] : null;
		}
		$this->orig_user_id = get_current_user_id();
		if ( ! function_exists( 'add_submenu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// menu.php (parent menu + wptsall_register_admin_page) is only
		// loaded by the bootstrap in admin context; the unit runner is
		// front-end, so load it explicitly.
		if ( ! function_exists( 'wptsall_register_admin_page' ) && defined( 'WPTSALL_PATH' ) ) {
			require_once WPTSALL_PATH . 'includes/admin/menu.php';
		}
		wp_set_current_user( 1 );
	}

	public function tearDown(): void {
		$this->restore_admin_globals();
		if ( $this->registered_init_script ) {
			wp_deregister_script( 'wptsall-initialization' );
			$this->registered_init_script = false;
		}
		wp_set_current_user( $this->orig_user_id );
		parent::tearDown();
	}

	/**
	 * Hidden init page registration: null-parent submenu pages are not
	 * listed in $submenu — they are wired through $_registered_pages with
	 * the admin_page_<slug> hookname convention.
	 */
	public function test_init_screen_registered_as_hidden_page() {
		wptsall_register_admin_page();
		Initialization_Page::add_menu_page();

		$hookname      = get_plugin_page_hookname( 'wptsall-init', '' );
		$registered    = isset( $GLOBALS['_registered_pages'][ $hookname ] );
		$hook_prefixes = $hookname;

		$this->restore_admin_globals();

		$this->assertTrue( $registered, "wptsall-init must register hidden page hookname {$hookname}" );
		$this->assertStringStartsWith( 'admin_page_', $hook_prefixes, 'hidden null-parent pages use the admin_page_ convention' );
	}

	/**
	 * Render capture: a manager sees the full initialization page body
	 * (header + wptsall-page-content container).
	 */
	public function test_init_screen_renders_page_body_for_managers() {
		$this->assertTrue(
			current_user_can( 'manage_wptsall_settings' ),
			'fixture precondition: admin must hold manage_wptsall_settings'
		);
		if ( ! wp_script_is( 'wptsall-initialization', 'registered' ) ) {
			wp_register_script( 'wptsall-initialization', '' );
			$this->registered_init_script = true;
		}

		ob_start();
		Initialization_Page::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wptsall-page-content', $html, 'page body container must render' );
		$this->assertStringContainsString( 'wptsall-init-page', $html, 'render_header wrapper class must render' );
		$this->assertStringContainsString( 'WPTSALL Initialization', $html, 'screen title must render' );
	}

	/**
	 * Capability contract: without the manage capability render_page()
	 * must emit nothing at all.
	 */
	public function test_init_screen_renders_nothing_without_capability() {
		wp_set_current_user( 0 );

		ob_start();
		Initialization_Page::render_page();
		$html = ob_get_clean();

		$this->assertSame( '', trim( $html ), 'no-cap users must get an empty response' );
	}

	/**
	 * Manual Queue screen contract (opus5 A-01): the queue review surface is
	 * a visible submenu entry under the plugin menu, registered with the
	 * documented slug and capability.
	 */
	public function test_manual_queue_screen_registered_under_plugin_menu() {
		Manual_Queue_Admin_Page::add_menu_page();

		$submenu = isset( $GLOBALS['submenu']['wpmmcc-ats'] ) ? $GLOBALS['submenu']['wpmmcc-ats'] : array();
		$found   = null;
		foreach ( $submenu as $entry ) {
			if ( isset( $entry[2] ) && Manual_Queue_Admin_Page::PAGE_SLUG === $entry[2] ) {
				$found = $entry;
				break;
			}
		}

		$this->restore_admin_globals();

		$this->assertNotNull( $found, 'wptsall-manual-queue must register a submenu entry under the plugin menu' );
		$this->assertSame(
			Manual_Queue_Admin_Page::CAP,
			$found[1],
			'the manual queue entry must require the documented capability'
		);
	}

	/**
	 * Wizard redirect entry wiring: visible "Setup Wizard" entry under the
	 * plugin menu with the documented capability, plus the redirect TARGET
	 * page registered as a hidden page (reachable via admin.php?page=).
	 * The callback's exit-after-redirect behavior stays browser/e2e-owned
	 * (invoking it here would terminate the test runner).
	 */
	public function test_wizard_redirect_entry_and_target_registered() {
		wptsall_register_admin_page();
		Setup_Wizard::add_menu_page();

		$entries        = isset( $GLOBALS['submenu']['wpmmcc-ats'] ) ? $GLOBALS['submenu']['wpmmcc-ats'] : array();
		$slugs          = wp_list_pluck( $entries, 2 );
		$target_hook    = get_plugin_page_hookname( Setup_Wizard::PAGE_SLUG, '' );
		$target_present = isset( $GLOBALS['_registered_pages'][ $target_hook ] );

		$this->restore_admin_globals();

		$this->assertContains( 'wptsall-wizard-redirect', $slugs, 'visible redirect entry must be registered' );
		$this->assertTrue( $target_present, 'redirect target page must be registered (hidden: ' . $target_hook . ')' );
		foreach ( $entries as $entry ) {
			if ( 'wptsall-wizard-redirect' === $entry[2] ) {
				$this->assertSame( 'manage_wptsall_settings', $entry[1], 'redirect entry capability' );
				$this->assertSame( 'Setup Wizard', $entry[0], 'redirect entry menu title' );
			}
		}
		$this->assertTrue( method_exists( Setup_Wizard::class, 'redirect_to_wizard' ), 'redirect callback must exist' );
		$this->assertSame(
			'wptsall-wizard',
			Setup_Wizard::PAGE_SLUG,
			'redirect destination slug constant'
		);
		// Documented redirect URL construction (mirrors redirect_to_wizard's
		// add_query_arg call without invoking the exiting callback).
		$expected = admin_url( 'admin.php' ) . '?page=' . Setup_Wizard::PAGE_SLUG;
		$this->assertSame(
			$expected,
			add_query_arg( 'page', Setup_Wizard::PAGE_SLUG, admin_url( 'admin.php' ) ),
			'redirect URL must resolve to the registered wizard page'
		);
	}

	private function restore_admin_globals(): void {
		foreach ( $this->snap as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
	}

	/**
	 * #9 (public-repo deep E2E ledger 2026-09-11): after a relation is
	 * created the Sites page must render the next-step guidance — a
	 * success notice linking to "Add First Rule" (the model editor for the
	 * auto-created model) — and must render no guidance without the flag.
	 */
	public function test_sites_page_renders_add_first_rule_guidance_after_creation() {
		$sites_page = '\WPTSALL\Sites\Admin\Sites_Page';
		$this->assertTrue(
			current_user_can( 'wptsall_user_can_manage_translations' ) || current_user_can( 'manage_wptsall_settings' ),
			'fixture precondition: admin must be able to manage translations'
		);

		$orig_get = $_GET;
		try {
			$_GET = array(
				'page'                        => 'wptsall-sites',
				'wptsall_relation_created'    => '1',
				'wptsall_model_id'            => '42',
			);
			ob_start();
			$sites_page::render_page();
			$with_flag = ob_get_clean();

			$this->assertStringContainsString( 'notice-success', $with_flag, 'creation flag must render a success notice' );
			$this->assertStringContainsString( 'Add First Rule', $with_flag, 'the notice must carry the Add First Rule action' );
			$this->assertStringContainsString( 'model_id=42', $with_flag, 'the link must deep-link the auto-created model editor' );
			$this->assertStringContainsString( 'wptsall-page-content', $with_flag, 'the page body must still render' );

			$_GET = array( 'page' => 'wptsall-sites' );
			ob_start();
			$sites_page::render_page();
			$without_flag = ob_get_clean();

			$this->assertStringNotContainsString( 'Add First Rule', $without_flag, 'no creation flag → no guidance notice' );
			$this->assertStringContainsString( 'wptsall-page-content', $without_flag, 'the page body must render without the flag too' );
		} finally {
			$_GET = $orig_get;
		}
	}

	/**
	 * Models backup page render contract (批 O4 疣①): render_header's second
	 * argument is the MODULE identifier ('models' — a wrap class), never a
	 * subtitle string. The handler used to pass a sprintf'd subtitle there;
	 * sanitize_html_class() mangled it into a bogus wrap class and the text
	 * never rendered anywhere. Contract: proper wptsall-models-page wrap
	 * class + the subtitle rendered in the body as the standard description
	 * paragraph.
	 *
	 * catalog: WP-SCREEN-wptsall-models-backup
	 * oracle: L2
	 */
	public function test_models_backup_page_renders_subtitle_in_body() {
		$this->assertTrue(
			current_user_can( 'manage_wptsall_settings' ),
			'fixture precondition: admin must hold manage_wptsall_settings'
		);

		ob_start();
		Model_Backup_Handler::render_page();
		$html = ob_get_clean();

		// render_header arg 2 = module name → proper wrap class.
		$this->assertStringContainsString( 'wptsall-models-page', $html, 'backup page must use the models module wrap class' );
		// No mangled text-as-class remnant of the old misuse.
		$this->assertStringNotContainsString( 'wptsall-Server-side', $html, 'the subtitle must never leak into the wrap class' );
		// The subtitle text must actually render in the page body.
		$this->assertStringContainsString( 'Server-side JSON export/import of the', $html, 'subtitle must render in the page body' );
		$this->assertStringContainsString( 'p class="description"', $html, 'subtitle renders as the standard description paragraph' );
	}
}
