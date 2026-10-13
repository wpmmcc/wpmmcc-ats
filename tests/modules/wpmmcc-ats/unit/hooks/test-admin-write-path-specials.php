<?php
/**
 * Admin write-path contracts (L2) — special faces.
 *
 * The remaining write paths that are not plain admin_post_* form handlers:
 * cron dispatch (custom action tags, no user/nonce gates — guarded by
 * id validity and empty-state tolerance), an AJAX quick-edit writer, the
 * virtual-site bulk assign filter, and the CPTUI resolution writer.
 *
 * 口径: cron and AJAX handlers that end in exits are only driven through
 * their guard/grey paths; the inline-save writer has no exit and gets a
 * full roundtrip; bulk assign is a returning filter callback and gets a
 * full positive path; the CPTUI writer is protected and reached via
 * reflection with crafted resolutions (its public caller is the heavy
 * resolve-all-orphans scan).
 *
 * catalog: WP-WRITE-handle_i18n_scan_cron
 * catalog: WP-WRITE-handle_monitoring_cron
 * catalog: WP-WRITE-wp_ajax_inline-save
 * catalog: WP-WRITE-handle_bulk_actions
 * catalog: WP-WRITE-handle_cptui_types
 * oracle: L2
 *
 * @package WPTSALL
 */

use WPTSALL\Hooks\Admin_Virtual_Site_Manager;
use WPTSALL\ManualTranslation\Hooks\Language_Meta_Saver;
use WPTSALL\Tasks\Module;

class Test_Admin_Write_Path_Specials extends SimpleTestCase {

	/** @var array post ids created for cleanup. */
	private $post_ids = array();

	/** @var string|null virtual site id created for cleanup. */
	private $site_id;

	public function tearDown(): void {
		foreach ( $this->post_ids as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
		$this->post_ids = array();
		if ( null !== $this->site_id ) {
			\WPTSALL\Sites\Services\Virtual_Site_Service::delete( $this->site_id );
			$this->site_id = null;
		}
		unset( $_POST['post_ID'], $_POST['_inline_edit'], $_POST[ Language_Meta_Saver::META_KEY ], $_REQUEST['_inline_edit'], $_REQUEST['wptsall_bulk_site_id'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Cron write paths are wired on their custom action tags and tolerate
	 * non-positive relation ids / empty task state without side effects.
	 */
	public function test_cron_handlers_wired_and_guarded() {
		if ( false === has_action( 'wptsall_run_i18n_scan' ) ) {
			Module::init();
		}
		$this->assertNotFalse(
			has_action( 'wptsall_run_i18n_scan', array( Module::class, 'handle_i18n_scan_cron' ) ),
			'wptsall_run_i18n_scan must be wired to Module::handle_i18n_scan_cron'
		);
		$this->assertNotFalse(
			has_action( 'wptsall_process_monitoring_tasks', array( Module::class, 'handle_monitoring_cron' ) ),
			'wptsall_process_monitoring_tasks must be wired to Module::handle_monitoring_cron'
		);

		// Non-positive relation ids must be a silent no-op (guard before
		// any service call).
		Module::handle_i18n_scan_cron( 0 );
		Module::handle_i18n_scan_cron( -3 );

		// The recurring handler must tolerate an empty/absent task table
		// (create-if-missing keeps the iteration well-defined; never
		// dropped here — shared Lab table).
		if ( function_exists( 'wptsall_init_tasks_tables' ) ) {
			wptsall_init_tasks_tables();
		}
		Module::handle_monitoring_cron();
		// Reaching this line without an exception IS the contract.
		$this->assertTrue( true, 'monitoring cron must survive an empty run' );
	}

	/**
	 * AJAX quick-edit writer: bad nonce is a silent no-op; with a valid
	 * inline-edit nonce a manager's language choice is persisted.
	 */
	public function test_inline_save_quick_edit_roundtrip() {
		if ( false === has_action( 'wp_ajax_inline-save' ) ) {
			Language_Meta_Saver::init();
		}
		$this->assertNotFalse(
			has_action( 'wp_ajax_inline-save', array( Language_Meta_Saver::class, 'save_quick_edit' ) ),
			'wp_ajax_inline-save must be wired to Language_Meta_Saver::save_quick_edit'
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		if ( ! $post_id ) {
			$post_id = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Inline Save ' . uniqid() ) );
		}
		$this->post_ids[] = (int) $post_id;
		wp_set_current_user( 1 );

		// Pick a real language code so persist()'s validation passes.
		$langs  = \WPTSALL\Languages\Services\Language_Service::get_all( array( 'status' => 'all' ) );
		$target = '';
		foreach ( (array) $langs as $lang ) {
			if ( ! empty( $lang['code'] ) ) {
				$target = (string) $lang['code'];
				break;
			}
		}
		$this->assertNotSame( '', $target, 'fixture precondition: at least one language must exist' );

		// Bad nonce: nothing is written.
		$_POST['post_ID'] = array( (string) $post_id );
		$_POST[ Language_Meta_Saver::META_KEY ] = array( $target );
		Language_Meta_Saver::save_quick_edit();
		$this->assertSame( '', (string) get_post_meta( (int) $post_id, Language_Meta_Saver::META_KEY, true ), 'bad nonce must not write' );

		// Valid inline-edit nonce: the language is persisted. NOTE: the
		// nonce check reads $_REQUEST, which PHP does NOT rebuild from a
		// later $_POST assignment — set both.
		$_REQUEST['_inline_edit'] = $_POST['_inline_edit'] = wp_create_nonce( 'inlineeditnonce' );
		Language_Meta_Saver::save_quick_edit();
		$this->assertSame( $target, (string) get_post_meta( (int) $post_id, Language_Meta_Saver::META_KEY, true ), 'valid nonce must persist the language' );

		// Empty post ids: silent no-op even with a valid nonce.
		$_POST['post_ID'] = array();
		Language_Meta_Saver::save_quick_edit();
		$this->assertTrue( true );
	}

	/**
	 * Virtual-site bulk assign: guard branches return the redirect
	 * unchanged (or with an error arg), and the positive path writes the
	 * site meta and decorates the redirect.
	 */
	public function test_bulk_actions_assign_site_roundtrip() {
		$redirect   = admin_url( 'edit.php' );
		$post_id    = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Bulk Assign ' . uniqid() ) );
		$this->post_ids[] = (int) $post_id;
		$this->assertGreaterThan( 0, (int) $post_id );

		// Create the virtual site BEFORE constructing the manager: it
		// caches the site list on first read.
		$site = \WPTSALL\Sites\Services\Virtual_Site_Service::create(
			array(
				'name'        => 'Bulk Assign Unit ' . uniqid(),
				'path_prefix' => 'bulk-assign-' . uniqid(),
				'lang'        => 'en_US',
			)
		);
		$this->site_id = (string) ( $site['site_id'] ?? '' );
		$this->assertNotSame( '', $this->site_id, 'virtual site must be created' );
		$numeric_site_id = (int) ltrim( $this->site_id, 'v_' );

		wp_set_current_user( 1 );
		$manager = new Admin_Virtual_Site_Manager();

		// Unknown action: passthrough.
		$this->assertSame( $redirect, $manager->handle_bulk_actions( $redirect, 'other_action', array( (int) $post_id ) ) );

		// Assign without a site id: passthrough.
		$this->assertSame( $redirect, $manager->handle_bulk_actions( $redirect, 'wptsall_assign_site', array( (int) $post_id ) ) );

		// Assign with a non-positive site id: passthrough.
		$_REQUEST['wptsall_bulk_site_id'] = '0';
		$this->assertSame( $redirect, $manager->handle_bulk_actions( $redirect, 'wptsall_assign_site', array( (int) $post_id ) ) );

		// Assign with an unknown site id: redirect gains the error arg.
		$_REQUEST['wptsall_bulk_site_id'] = '999999';
		$error_redirect = $manager->handle_bulk_actions( $redirect, 'wptsall_assign_site', array( (int) $post_id ) );
		$this->assertStringContainsString( 'wptsall_bulk_error=invalid_site', $error_redirect );

		// Positive path: assign to the created site, verify meta + args.
		$_REQUEST['wptsall_bulk_site_id'] = (string) $numeric_site_id;
		$done = $manager->handle_bulk_actions( $redirect, 'wptsall_assign_site', array( (int) $post_id ) );
		$this->assertStringContainsString( 'wptsall_bulk_assigned=1', $done, 'assign must decorate the redirect' );
		$this->assertStringContainsString( 'wptsall_bulk_site=' . $numeric_site_id, $done );
		$meta = (string) get_post_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, true );
		$this->assertSame( (string) $numeric_site_id, $meta, 'site meta must be written' );

		// wptsall_move_to_main clears the meta again.
		$moved = $manager->handle_bulk_actions( $redirect, 'wptsall_move_to_main', array( (int) $post_id ) );
		$this->assertStringContainsString( 'wptsall_bulk_moved', $moved );
		$this->assertSame( '', (string) get_post_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, true ), 'move-to-main must clear the meta' );
	}

	/**
	 * CPTUI resolution writer: CPTUI-attributed post types in the orphan
	 * resolutions must produce (or update) the CPTUI model mapping.
	 */
	public function test_cptui_types_handler_creates_cptui_model() {
		global $wpdb;

		if ( ! function_exists( 'wptsall_create_model_tables' ) ) {
			$this->markTestSkipped( 'model tables schema not loaded' );
		}
		wptsall_create_model_tables();

		$models_table = wptsall_table( 'models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$models_table} WHERE plugin_slug = %s", 'custom-post-type-ui' ),
			ARRAY_A
		);

		$method = new ReflectionMethod( \WPTSALL\Models\Services\Plugin_Mapping_Service::class, 'handle_cptui_types' );
		$method->setAccessible( true );
		$method->invoke(
			null,
			array(
				'post_types'  => array( 'book' => array( 'plugin_slug' => 'custom-post-type-ui' ) ),
				'taxonomies'  => array(),
				'unresolved'  => array( 'post_types' => array(), 'taxonomies' => array() ),
			)
		);

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, post_types, detection_method FROM {$models_table} WHERE plugin_slug = %s", 'custom-post-type-ui' ),
				ARRAY_A
			);
			$this->assertNotNull( $row, 'CPTUI resolutions must create the CPTUI model mapping' );
			$this->assertSame( 'cptui_option', (string) $row['detection_method'] );
			$decoded = json_decode( (string) $row['post_types'], true );
			$this->assertIsArray( $decoded );
			$this->assertContains( 'book', $decoded, 'the CPTUI post type must be stored' );
		} finally {
			if ( null === $existing_row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->delete( $models_table, array( 'plugin_slug' => 'custom-post-type-ui' ), array( '%s' ) );
			}
		}
	}
}
