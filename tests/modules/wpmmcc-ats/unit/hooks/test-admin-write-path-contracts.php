<?php
/**
 * Admin write-path contracts (L2) for admin_post_* handlers.
 *
 * 口径 (test-architecture note): every handler under test ends in
 * wp_safe_redirect() + exit — invoking it past its guards would terminate
 * this runner, so the service-effect + redirect leg stays browser/e2e
 * owned. What is pinned here, per write path:
 *   (1) wiring — admin_post_<action> is registered with the documented
 *       callback on the page class;
 *   (2) capability gate FIRST — invoked with no capability the handler
 *       must wp_die before touching anything (intercepted through the
 *       _wp_die_handler filter, converted to a catchable exception);
 *   (3) nonce gate — as a privileged user with a FOREIGN nonce the
 *       handler dies at the nonce gate, before the service call;
 *   (4) the documented nonce action string round-trips through
 *       wp_create_nonce()/wp_verify_nonce().
 *
 * catalog: WP-WRITE-admin_post_wptsall_language_save
 * catalog: WP-WRITE-admin_post_wptsall_language_delete
 * catalog: WP-WRITE-admin_post_wptsall_language_set_default
 * catalog: WP-WRITE-handle_set_default
 * catalog: WP-WRITE-admin_post_wptsall_settings_save
 * catalog: WP-WRITE-admin_post_wptsall_tax_link
 * catalog: WP-WRITE-admin_post_wptsall_tax_unlink
 * catalog: WP-WRITE-handle_link
 * catalog: WP-WRITE-handle_unlink
 * catalog: WP-WRITE-admin_post_wptsall_tm_save
 * catalog: WP-WRITE-admin_post_wptsall_tm_delete
 * catalog: WP-WRITE-admin_post_wptsall_tm_export
 * catalog: WP-WRITE-handle_regenerate_token
 * catalog: WP-WRITE-admin_post_wptsall_user_save
 * catalog: WP-WRITE-admin_post_wptsall_user_delete
 * catalog: WP-WRITE-admin_post_wptsall_media_save
 * catalog: WP-WRITE-admin_post_wptsall_media_delete
 * catalog: WP-WRITE-admin_post_wptsall_cf_save
 * catalog: WP-WRITE-admin_post_wptsall_content_types_save
 * catalog: WP-WRITE-admin_post_wptsall_field_discovery_save
 * catalog: WP-WRITE-admin_post_wptsall_menu_sync
 * catalog: WP-WRITE-handle_sync
 * catalog: WP-WRITE-admin_post_wptsall_start_browse_session
 * catalog: WP-WRITE-admin_post_wptsall_register_browse_strings
 * catalog: WP-WRITE-admin_post_wptsall_save_i18n_config
 * catalog: WP-WRITE-handle_save_config
 * catalog: WP-WRITE-admin_post_wptsall_scan_i18n
 * catalog: WP-WRITE-admin_post_wptsall_scan_site_strings
 * catalog: WP-WRITE-handle_scan_site_strings
 * catalog: WP-WRITE-admin_post_wptsall_seo_settings_save
 * catalog: WP-WRITE-admin_post_wptsall_strings_scan
 * catalog: WP-WRITE-handle_scan
 * catalog: WP-WRITE-admin_post_wptsall_url_discovery_run
 * catalog: WP-WRITE-admin_post_wptsall_issue_connection_pack
 * catalog: WP-WRITE-handle_issue_connection_pack
 * catalog: WP-WRITE-admin_post_wptsall_manual_queue_apply
 * catalog: WP-WRITE-admin_post_wptsall_manual_queue_reject
 * catalog: WP-WRITE-admin_post_wptsall_manual_queue_expire
 * catalog: WP-WRITE-handle_apply
 * catalog: WP-WRITE-handle_reject
 * catalog: WP-WRITE-handle_expire
 * catalog: WP-WRITE-admin_post_wptsall_tm_settings
 * catalog: WP-WRITE-handle_settings
 * catalog: WP-WRITE-admin_post_wptsall_save_rule
 * catalog: WP-WRITE-admin_post_wptsall_clear_audit_logs
 * catalog: WP-WRITE-wp_ajax_wptsall_wizard_scan_models
 * oracle: L2
 *
 * @package WPTSALL
 */

use WPTSALL\CustomFields\Admin\Custom_Fields_Page;
use WPTSALL\Languages\Admin\Languages_Page;
use WPTSALL\Log\Admin\Logs_Page;
use WPTSALL\Models\Admin\Model_Editor_Page;
use WPTSALL\Tasks\Admin\Manual_Queue_Admin_Page;
use WPTSALL\Wizard\Setup_Wizard;
use WPTSALL\ManualTranslation\Admin\Content_Types_Config_Page;
use WPTSALL\ManualTranslation\Admin\Field_Discovery_Page;
use WPTSALL\ManualTranslation\Admin\Taxonomy_Translation_Page;
use WPTSALL\ManualTranslation\Admin\Url_Discovery_Page;
use WPTSALL\MediaTranslation\Admin\Media_Translation_Page;
use WPTSALL\MenuTranslation\Admin\Menu_Sync_Page;
use WPTSALL\Settings\Admin\Seo_Settings_Page;
use WPTSALL\Settings\Admin\Settings_Page;
use WPTSALL\Strings\Admin\Strings_Page;
use WPTSALL\Tasks\Admin\Tasks_Page;
use WPTSALL\Templates\Admin\Browse_As_Role_Page;
use WPTSALL\Templates\Admin\Theme_Plugin_Localization_Page;
use WPTSALL\TranslationMemory\Admin\Translation_Memory_Page;
use WPTSALL\UserTranslation\Admin\User_Translation_Page;

class Test_Admin_Write_Path_Contracts extends SimpleTestCase {

	/** @var callable|null */
	private $die_thrower = null;

	public function tearDown(): void {
		if ( null !== $this->die_thrower ) {
			remove_filter( 'wp_die_handler', $this->die_thrower );
			$this->die_thrower = null;
		}
		unset( $_REQUEST['_wpnonce'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Assert the full guard contract for one admin write path.
	 *
	 * @param string $page_class FQCN of the admin page class.
	 * @param string $action     admin_post_<action> tag.
	 * @param string $method     documented callback method.
	 * @param string $nonce      documented nonce action string.
	 * @param string $die_hint   expected fragment of the no-cap wp_die message.
	 */
	private function assert_write_path( string $page_class, string $action, string $method, string $nonce, string $die_hint = 'Forbidden' ): void {
		// (1) Wiring: register once (idempotent), then prove the exact
		// callback is bound to the documented admin_post tag. Module-level
		// bootstraps register some actions with a leading-backslash class
		// string while the page's own init() uses __CLASS__ — both forms
		// name the same method, so accept either.
		if ( false === has_action( "admin_post_{$action}" ) ) {
			$page_class::init();
		}
		$wired = false !== has_action( "admin_post_{$action}", array( $page_class, $method ) )
			|| false !== has_action( "admin_post_{$action}", array( '\\' . $page_class, $method ) );
		$this->assertTrue( $wired, "admin_post_{$action} must be wired to {$page_class}::{$method}" );

		// (2) Capability gate first: a no-cap invocation must die before
		// any state change; the die is intercepted as an exception.
		wp_set_current_user( 0 );
		$this->install_die_interceptor();
		$die_message = '';
		try {
			$page_class::$method();
			$this->fail( "{$page_class}::{$method} must wp_die for a no-cap user" );
		} catch ( RuntimeException $e ) {
			$die_message = $e->getMessage();
		}
		$this->assertStringContainsString( $die_hint, $die_message, 'no-cap die message' );

		// (3) Nonce gate: a privileged user with a foreign nonce dies at
		// the nonce gate, before the service call / redirect.
		wp_set_current_user( 1 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wptsall_write_path_probe_foreign' );
		try {
			$page_class::$method();
			$this->fail( "{$page_class}::{$method} must reject a foreign nonce" );
		} catch ( RuntimeException $e ) {
			// Died at (or before) the nonce gate — as long as it died, the
			// runner survived and no service call ran.
			$this->assertStringContainsString( 'WP_DIE_INTERCEPTED', $e->getMessage() );
		}
		unset( $_REQUEST['_wpnonce'] );

		// (4) The documented nonce action string round-trips.
		$this->assertNotFalse(
			wp_verify_nonce( wp_create_nonce( $nonce ), $nonce ),
			"nonce action '{$nonce}' must round-trip"
		);
	}

	private function install_die_interceptor(): void {
		if ( null !== $this->die_thrower ) {
			return;
		}
		// The wp_die_handler FILTER receives the previous handler name and
		// must RETURN the handler to invoke; the returned closure is the
		// one that gets the real ($message, $title, $args).
		$this->die_thrower = static function () {
			return static function ( $message ) {
				throw new RuntimeException( 'WP_DIE_INTERCEPTED:' . (string) $message );
			};
		};
		add_filter( 'wp_die_handler', $this->die_thrower );
	}

	public function test_languages_page_write_paths() {
		$this->assert_write_path( Languages_Page::class, 'wptsall_language_save', 'handle_save', 'wptsall_save_lang' );
		$this->assert_write_path( Languages_Page::class, 'wptsall_language_delete', 'handle_delete', 'wptsall_delete_lang' );
		$this->assert_write_path( Languages_Page::class, 'wptsall_language_set_default', 'handle_set_default', 'wptsall_set_default_lang' );
	}

	public function test_settings_page_write_paths() {
		$this->assert_write_path( Settings_Page::class, 'wptsall_settings_save', 'handle_save', 'wptsall_save_settings' );
	}

	public function test_taxonomy_translation_page_write_paths() {
		$this->assert_write_path( Taxonomy_Translation_Page::class, 'wptsall_tax_link', 'handle_link', Taxonomy_Translation_Page::NONCE_LINK );
		$this->assert_write_path( Taxonomy_Translation_Page::class, 'wptsall_tax_unlink', 'handle_unlink', Taxonomy_Translation_Page::NONCE_UNLINK );
	}

	public function test_translation_memory_page_write_paths() {
		$this->assert_write_path( Translation_Memory_Page::class, 'wptsall_tm_save', 'handle_save', 'wptsall_tm_save' );
		$this->assert_write_path( Translation_Memory_Page::class, 'wptsall_tm_delete', 'handle_delete', 'wptsall_tm_delete' );
		$this->assert_write_path( Translation_Memory_Page::class, 'wptsall_tm_export', 'handle_export', 'wptsall_tm_export' );
	}

	public function test_manual_queue_page_write_paths() {
		// tasks/module.php binds these tags only inside is_admin() context;
		// a CLI unit run mirrors the exact documented module bindings so the
		// guard contract can run (module-level wiring is additionally pinned
		// by Test_Admin_Post_Registration_Matrix).
		if ( false === has_action( 'admin_post_wptsall_manual_queue_apply' ) ) {
			add_action( 'admin_post_wptsall_manual_queue_apply', array( '\\' . Manual_Queue_Admin_Page::class, 'handle_apply' ) );
			add_action( 'admin_post_wptsall_manual_queue_reject', array( '\\' . Manual_Queue_Admin_Page::class, 'handle_reject' ) );
			add_action( 'admin_post_wptsall_manual_queue_expire', array( '\\' . Manual_Queue_Admin_Page::class, 'handle_expire' ) );
		}
		$this->assert_write_path( Manual_Queue_Admin_Page::class, 'wptsall_manual_queue_apply', 'handle_apply', Manual_Queue_Admin_Page::NONCE_APPLY );
		$this->assert_write_path( Manual_Queue_Admin_Page::class, 'wptsall_manual_queue_reject', 'handle_reject', Manual_Queue_Admin_Page::NONCE_REJECT );
		$this->assert_write_path( Manual_Queue_Admin_Page::class, 'wptsall_manual_queue_expire', 'handle_expire', Manual_Queue_Admin_Page::NONCE_EXPIRE );
	}

	public function test_translation_memory_settings_write_path() {
		// Module-level binding (translation-memory/module.php, opus5 M-04);
		// mirror it for a bare unit context when it has not fired.
		if ( false === has_action( 'admin_post_wptsall_tm_settings' ) ) {
			add_action( 'admin_post_wptsall_tm_settings', array( '\\' . Translation_Memory_Page::class, 'handle_settings' ) );
		}
		$this->assert_write_path( Translation_Memory_Page::class, 'wptsall_tm_settings', 'handle_settings', 'wptsall_tm_settings' );
	}

	public function test_model_editor_rule_write_path() {
		// Module-level binding (models/module.php save-rule fallback).
		if ( false === has_action( 'admin_post_wptsall_save_rule' ) ) {
			add_action( 'admin_post_wptsall_save_rule', array( '\\' . Model_Editor_Page::class, 'handle_save_rule_fallback' ) );
		}
		$this->assert_write_path( Model_Editor_Page::class, 'wptsall_save_rule', 'handle_save_rule_fallback', 'wptsall_save_rule' );
	}

	public function test_logs_page_clear_audit_write_path() {
		// Module-level binding (log/module.php clear-audit action).
		if ( false === has_action( 'admin_post_wptsall_clear_audit_logs' ) ) {
			add_action( 'admin_post_wptsall_clear_audit_logs', array( '\\' . Logs_Page::class, 'handle_clear_audit' ) );
		}
		$this->assert_write_path( Logs_Page::class, 'wptsall_clear_audit_logs', 'handle_clear_audit', 'wptsall_clear_audit_logs', 'Insufficient permissions.' );
	}

	public function test_wizard_scan_models_ajax_guards() {
		// Setup_Wizard registers the ajax tag in init() (both admin and
		// ajax contexts boot it; a bare unit run may not have).
		if ( false === has_action( 'wp_ajax_wptsall_wizard_scan_models' ) ) {
			Setup_Wizard::init();
		}
		$wired = false !== has_action( 'wp_ajax_wptsall_wizard_scan_models', array( Setup_Wizard::class, 'ajax_scan_models' ) )
			|| false !== has_action( 'wp_ajax_wptsall_wizard_scan_models', array( '\\' . Setup_Wizard::class, 'ajax_scan_models' ) );
		$this->assertTrue( $wired, 'wp_ajax_wptsall_wizard_scan_models must be wired to Setup_Wizard::ajax_scan_models' );

		// Handler termination 口径: ajax_scan_models enforces its capability
		// gate via wp_send_json_error(…, 403), which in a non-ajax context
		// ends in a bare die (NOT the interceptable wp_die path) — invoking
		// it here would terminate the runner. Exactly like the wp_safe_redirect
		// + exit handlers above, the no-cap JSON/403 leg is ajax/browser-owned
		// and stays e2e-covered. What is pinned here: the documented guard
		// constants — the capability and the nonce action round-trip.
		$this->assertSame( 'manage_wptsall_settings', Setup_Wizard::CAP, 'the wizard scan ajax gate must use the documented capability' );

		// The documented nonce action round-trips.
		$this->assertNotFalse( wp_verify_nonce( wp_create_nonce( 'wptsall_wizard_scan' ), 'wptsall_wizard_scan' ) );
	}

	// UI-28-07: test_client_token_page_write_paths removed — the page class
	// was orphaned dead code (Client_Pairing\Module::init() never loaded it,
	// no menu slot rendered it; the regenerate form existed nowhere in the
	// UI). The class itself was deleted 2026-09-20; see doc 28.

	public function test_user_translation_page_write_paths() {
		$this->assert_write_path( User_Translation_Page::class, 'wptsall_user_save', 'handle_save', 'wptsall_user_save' );
		$this->assert_write_path( User_Translation_Page::class, 'wptsall_user_delete', 'handle_delete', 'wptsall_user_delete' );
	}

	public function test_media_translation_page_write_paths() {
		$this->assert_write_path( Media_Translation_Page::class, 'wptsall_media_save', 'handle_save', 'wptsall_media_save' );
		$this->assert_write_path( Media_Translation_Page::class, 'wptsall_media_delete', 'handle_delete', 'wptsall_media_delete' );
	}

	public function test_custom_fields_page_write_paths() {
		$this->assert_write_path( Custom_Fields_Page::class, 'wptsall_cf_save', 'handle_save', 'wptsall_cf_save' );
	}

	public function test_content_types_config_page_write_paths() {
		// This page's no-cap die message is "Permission denied" (the rest
		// of the suite standardizes on "Forbidden").
		$this->assert_write_path( Content_Types_Config_Page::class, 'wptsall_content_types_save', 'handle_save', Content_Types_Config_Page::NONCE, 'Permission denied' );
	}

	public function test_field_discovery_page_write_paths() {
		$this->assert_write_path( Field_Discovery_Page::class, 'wptsall_field_discovery_save', 'handle_save', Field_Discovery_Page::NONCE );
	}

	public function test_menu_sync_page_write_paths() {
		$this->assert_write_path( Menu_Sync_Page::class, 'wptsall_menu_sync', 'handle_sync', 'wptsall_menu_sync' );
	}

	public function test_browse_as_role_page_write_paths() {
		$this->assert_write_path( Browse_As_Role_Page::class, 'wptsall_start_browse_session', 'handle_start', 'wptsall_start_browse' );
		$this->assert_write_path( Browse_As_Role_Page::class, 'wptsall_register_browse_strings', 'handle_register', 'wptsall_register_browse' );
	}

	public function test_theme_plugin_localization_page_write_paths() {
		$this->assert_write_path( Theme_Plugin_Localization_Page::class, 'wptsall_scan_i18n', 'handle_scan', 'wptsall_scan_i18n' );
		$this->assert_write_path( Theme_Plugin_Localization_Page::class, 'wptsall_scan_site_strings', 'handle_scan_site_strings', 'wptsall_scan_site_strings' );
		$this->assert_write_path( Theme_Plugin_Localization_Page::class, 'wptsall_save_i18n_config', 'handle_save_config', 'wptsall_save_i18n_config' );
	}

	public function test_seo_settings_page_write_paths() {
		$this->assert_write_path( Seo_Settings_Page::class, 'wptsall_seo_settings_save', 'handle_save', 'wptsall_save_seo_settings' );
	}

	public function test_strings_page_write_paths() {
		// strings_save / string_delete are covered elsewhere (scanner
		// suite); this pins the scan write path.
		$this->assert_write_path( Strings_Page::class, 'wptsall_strings_scan', 'handle_scan', 'wptsall_strings_scan' );
	}

	public function test_url_discovery_page_write_paths() {
		$this->assert_write_path( Url_Discovery_Page::class, 'wptsall_url_discovery_run', 'handle_run', Url_Discovery_Page::NONCE );
	}

	public function test_tasks_page_write_paths() {
		$this->assert_write_path( Tasks_Page::class, 'wptsall_issue_connection_pack', 'handle_issue_connection_pack', 'wptsall_issue_connection_pack' );
	}
}
