<?php
/**
 * Admin-post registration matrix (L1) — the bootstrap-wiring guard.
 *
 * Why this exists (doc 28, UI-28-02/UI-28-03): test-admin-write-path-contracts
 * proves a page class CAN register its handlers by calling ::init() itself
 * when the tag is missing — which passed while production never called that
 * init() (strings scan) and while handlers had been deleted outright (locale
 * overrides). That is the exact blind spot that let dead admin-post forms
 * ship: the unit layer verified the class in isolation, never the real
 * bootstrap chain.
 *
 * This guard asserts the OPPOSITE direction: the plugin is loaded through
 * its REAL bootstrap (the harness activates wpmmcc-ats exactly like WP
 * does), and every action value that a template renders into a form must
 * already be registered — no manual ::init() fallback, no exceptions.
 *
 * The matrix below is generated from the audit inventory of
 * `name="action" value="..."` inputs across all module admin templates
 * (doc 28 §一). Update it only when a form is added/removed deliberately.
 *
 * catalog: WP-GUARD-admin_post_registration_matrix
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit\Hooks
 */

use WPTSALL\Strings\Module as Strings_Module;
use WPTSALL\Settings\Module as Settings_Module;

class Test_Admin_Post_Registration_Matrix extends SimpleTestCase {

	/**
	 * Load the production admin bootstrap branch.
	 *
	 * The unit harness boots the plugin through wp-load in CLI context, so
	 * `is_admin()` is false when bootstrap.php runs and it never requires
	 * includes/admin/menu.php — the file that registers the Tasks page,
	 * Setup_Wizard and Model_Backup_Handler handlers (doc 28's runtime
	 * matrix showed exactly this CLI false-negative). To keep testing the
	 * REAL wiring (not per-class manual init(), which is the blind spot
	 * this guard exists to close), we require the production admin
	 * bootstrap file itself and fire its plugins_loaded aggregator —
	 * the same function WP calls in a real admin request. If menu.php
	 * stops registering any of them, this test fails.
	 *
	 * (Runs in setUp() because the custom runner has no setUpBeforeClass
	 * stage; require_once + WP's identical-callback add_action dedup make
	 * repeated calls safe.)
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WPTSALL_PATH . 'includes/admin/menu.php';
		if ( function_exists( 'wptsall_init_admin_pages' ) ) {
			wptsall_init_admin_pages();
		}
	}

	/**
	 * Every template-rendered admin-post action => the class that owns the
	 * handler (for a precise has_action callback assertion).
	 */
	private const MATRIX = array(
		// Languages.
		'wptsall_language_save'        => 'WPTSALL\Languages\Admin\Languages_Page::handle_save',
		'wptsall_language_delete'      => 'WPTSALL\Languages\Admin\Languages_Page::handle_delete',
		'wptsall_language_set_default' => 'WPTSALL\Languages\Admin\Languages_Page::handle_set_default',
		// Settings (+ restored locale override handlers, UI-28-03).
		'wptsall_settings_save'        => 'WPTSALL\Settings\Admin\Settings_Page::handle_save',
		'wptsall_locale_save'          => 'WPTSALL\Settings\Admin\Settings_Page::handle_locale_save',
		'wptsall_locale_clear'         => 'WPTSALL\Settings\Admin\Settings_Page::handle_locale_clear',
		'wptsall_seo_settings_save'    => 'WPTSALL\Settings\Admin\Seo_Settings_Page::handle_save',
		// Strings (scan was the UI-28-02 dead form; delete/save fixed by UI-28-01).
		'wptsall_strings_save'         => 'WPTSALL\Strings\Admin\Strings_Page::handle_save',
		'wptsall_string_delete'        => 'WPTSALL\Strings\Admin\Strings_Page::handle_delete',
		'wptsall_strings_scan'         => 'WPTSALL\Strings\Admin\Strings_Page::handle_scan',
		// Models (backup export/import; rule form native-submit fallback, UI-28-06).
		'wptsall_model_export'         => 'WPTSALL\Models\Admin\Model_Backup_Handler::handle_export',
		'wptsall_model_import'         => 'WPTSALL\Models\Admin\Model_Backup_Handler::handle_import',
		'wptsall_save_rule'            => 'WPTSALL\Models\Admin\Model_Editor_Page::handle_save_rule_fallback',
		// Tasks page.
		'wptsall_issue_connection_pack' => 'WPTSALL\Tasks\Admin\Tasks_Page::handle_issue_connection_pack',
		// Setup wizard.
		'wptsall_wizard_step'          => 'WPTSALL\Wizard\Setup_Wizard::handle_step',
		'wptsall_wizard_skip'          => 'WPTSALL\Wizard\Setup_Wizard::handle_skip',
		// Browse as role (gettext tracker, UI-28-04 rewrite).
		'wptsall_start_browse_session'  => 'WPTSALL\Templates\Admin\Browse_As_Role_Page::handle_start',
		'wptsall_register_browse_strings' => 'WPTSALL\Templates\Admin\Browse_As_Role_Page::handle_register',
		// Theme & plugin localization.
		'wptsall_scan_i18n'            => 'WPTSALL\Templates\Admin\Theme_Plugin_Localization_Page::handle_scan',
		'wptsall_save_i18n_config'     => 'WPTSALL\Templates\Admin\Theme_Plugin_Localization_Page::handle_save_config',
		'wptsall_scan_site_strings'    => 'WPTSALL\Templates\Admin\Theme_Plugin_Localization_Page::handle_scan_site_strings',
		// User translation.
		'wptsall_user_save'            => 'WPTSALL\UserTranslation\Admin\User_Translation_Page::handle_save',
		'wptsall_user_delete'          => 'WPTSALL\UserTranslation\Admin\User_Translation_Page::handle_delete',
		// Media translation.
		'wptsall_media_save'           => 'WPTSALL\MediaTranslation\Admin\Media_Translation_Page::handle_save',
		'wptsall_media_delete'         => 'WPTSALL\MediaTranslation\Admin\Media_Translation_Page::handle_delete',
		// Menu sync.
		'wptsall_menu_sync'            => 'WPTSALL\MenuTranslation\Admin\Menu_Sync_Page::handle_sync',
		// Manual translation: taxonomies / field discovery / content types / URL discovery.
		'wptsall_tax_link'             => 'WPTSALL\ManualTranslation\Admin\Taxonomy_Translation_Page::handle_link',
		'wptsall_tax_unlink'           => 'WPTSALL\ManualTranslation\Admin\Taxonomy_Translation_Page::handle_unlink',
		'wptsall_field_discovery_save' => 'WPTSALL\ManualTranslation\Admin\Field_Discovery_Page::handle_save',
		'wptsall_content_types_save'   => 'WPTSALL\ManualTranslation\Admin\Content_Types_Config_Page::handle_save',
		'wptsall_url_discovery_run'    => 'WPTSALL\ManualTranslation\Admin\Url_Discovery_Page::handle_run',
		// Translation memory.
		'wptsall_tm_save'              => 'WPTSALL\TranslationMemory\Admin\Translation_Memory_Page::handle_save',
		'wptsall_tm_export'            => 'WPTSALL\TranslationMemory\Admin\Translation_Memory_Page::handle_export',
		'wptsall_tm_delete'            => 'WPTSALL\TranslationMemory\Admin\Translation_Memory_Page::handle_delete',
		// Custom fields.
		'wptsall_cf_save'              => 'WPTSALL\CustomFields\Admin\Custom_Fields_Page::handle_save',
		// Logs.
		'wptsall_clear_audit_logs'     => 'WPTSALL\Log\Admin\Logs_Page::handle_clear_audit',
	);

	/**
	 * Every template-rendered action must be registered by the real
	 * bootstrap — no manual init() fallback (that is the point).
	 */
	public function test_every_form_action_is_registered_by_bootstrap(): void {
		$missing = array();
		foreach ( self::MATRIX as $action => $callback ) {
			if ( false === has_action( "admin_post_{$action}" ) ) {
				$missing[] = $action;
			}
		}
		$missing_list = $missing ? implode( ', ', $missing ) : '(none)';
		$this->assertSame(
			array(),
			$missing,
			"Dead admin-post forms (template renders the form, bootstrap never registers the handler — doc 28 UI-28-02/03 class): {$missing_list}"
		);
	}

	/**
	 * The registration must point at the documented owning callback.
	 * Module bootstraps may register with a leading-backslash class string
	 * while page init() uses __CLASS__; both name the same method.
	 */
	public function test_actions_are_wired_to_documented_callbacks(): void {
		$wrong = array();
		foreach ( self::MATRIX as $action => $callback ) {
			list( $class, $method ) = explode( '::', $callback );
			$wired = false !== has_action( "admin_post_{$action}", array( $class, $method ) )
				|| false !== has_action( "admin_post_{$action}", array( '\\' . $class, $method ) );
			if ( ! $wired ) {
				$wrong[] = "{$action} != {$callback}";
			}
		}
		$this->assertSame( array(), $wrong, 'Actions wired to unexpected callbacks: ' . implode( '; ', $wrong ) );
	}

	/**
	 * UI-28-02 regression pin: the strings module loader itself must carry
	 * all three strings write paths (not only the never-called class init()).
	 */
	public function test_strings_module_registers_all_three_write_paths(): void {
		// Module::init() is idempotent at the hook level for these tags when
		// the bootstrap already ran it; re-invoking mirrors the real loader.
		Strings_Module::init();
		$this->assertSame(
			array(),
			array_filter(
				array( 'wptsall_strings_save', 'wptsall_string_delete', 'wptsall_strings_scan' ),
				static function ( $a ) {
					return false === has_action( "admin_post_{$a}" );
				}
			),
			'strings module must register save + delete + scan'
		);
	}

	/**
	 * UI-28-03 regression pin: settings module registers the restored
	 * locale override handlers.
	 */
	public function test_settings_module_registers_locale_handlers(): void {
		Settings_Module::init();
		$this->assertNotFalse( has_action( 'admin_post_wptsall_locale_save' ), 'locale_save handler missing' );
		$this->assertNotFalse( has_action( 'admin_post_wptsall_locale_clear' ), 'locale_clear handler missing' );
	}
}
