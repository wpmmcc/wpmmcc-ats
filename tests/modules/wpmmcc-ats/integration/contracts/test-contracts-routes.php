<?php
/**
 * Contract Tests: Route Registration
 *
 * Verifies that all key REST routes are registered and return expected
 * HTTP status codes for basic existence checks.
 * No authentication required for 401/403 checks; admin auth for 200 checks.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

class Test_Contracts_Routes extends REST_Integration_Test_Case {

	/** @var bool Whether this suite can run. */
	private static $chain_runnable = true;

	/** @var string Reason for skipping. */
	private static $skip_reason = '';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace not registered';
		}
	}

	public function setUp(): void {
		parent::setUp();
		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	// ── Admin REST routes ──────────────────────────────────────────────────

	/** Models module */
	public function test_models_list_route_exists() {
		$r = $this->rest_get( 'models' );
		$this->assertNotEquals( 404, $r->get_status(), 'GET /models must be registered (not 404)' );
	}

	public function test_models_scan_route_exists() {
		// POST without body → 400 (bad request), but NOT 404
		$r = $this->rest_post( 'models/scan', array() );
		$this->assertNotEquals( 404, $r->get_status(), 'POST /models/scan must be registered (not 404)' );
	}

	/** Sites module */
	public function test_site_relations_route_exists() {
		$r = $this->rest_get( 'site-relations' );
		$this->assertNotEquals( 404, $r->get_status(), 'GET /site-relations must be registered' );
	}

	public function test_virtual_sites_route_exists() {
		$r = $this->rest_get( 'virtual-sites' );
		$this->assertNotEquals( 404, $r->get_status(), 'GET /virtual-sites must be registered' );
	}

	public function test_manual_translations_editor_data_route_exists() {
		// No source_post_id → 400, not 404
		$r = $this->rest_get( 'manual-translations/editor-data' );
		$this->assertNotEquals( 404, $r->get_status(), 'GET /manual-translations/editor-data must be registered' );
	}

	/** Tasks module */
	public function test_tasks_list_route_exists() {
		$r = $this->rest_get( 'tasks' );
		$this->assertRestSuccess( $r, 200 );
		$data = $this->get_response_data( $r );
		$this->assertArrayHasKey( 'items', $data, 'GET /tasks must return items key' );
	}

	public function test_tasks_monitor_start_route_exists() {
		// Missing relation_id → 400, not 404
		$r = $this->rest_post( 'tasks/monitor/start', array() );
		$this->assertNotEquals( 404, $r->get_status(), 'POST /tasks/monitor/start must be registered' );
	}

	/** Templates module */
	public function test_templates_route_exists() {
		$r = $this->rest_get( 'templates' );
		$this->assertNotEquals( 404, $r->get_status(), 'GET /templates must be registered' );
	}

	// ── Client API routes (require secret prefix) ─────────────────────────

	public function test_client_tasks_route_registered() {
		if ( ! function_exists( 'wptsall_get_client_route_secret' ) ) {
			$this->markTestSkipped( 'wptsall_get_client_route_secret not available' );
		}
		$secret = wptsall_get_client_route_secret();
		$this->assertNotEmpty( $secret, 'Client route secret must not be empty' );
		$this->assertGreaterThanOrEqual( 16, strlen( $secret ), 'Secret must be at least 16 chars' );

		// Without token → 401, not 404. Protocol header is required before auth.
		$request = new WP_REST_Request( 'GET', "/wptsall/v2/{$secret}/client/tasks" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$r = rest_do_request( $request );
		$this->assertNotEquals( 404, $r->get_status(), "GET /{secret}/client/tasks must be registered (not 404)" );
		$this->assertEquals( 401, $r->get_status(), "GET /{secret}/client/tasks must require auth (401)" );
	}

	public function test_client_translation_callback_route_registered() {
		if ( ! function_exists( 'wptsall_get_client_route_secret' ) ) {
			$this->markTestSkipped( 'wptsall_get_client_route_secret not available' );
		}
		$secret  = wptsall_get_client_route_secret();
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$secret}/client/translation-callback" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$r = rest_do_request( $request );
		$this->assertNotEquals( 404, $r->get_status(), "POST /{secret}/client/translation-callback must be registered" );
		$this->assertEquals( 401, $r->get_status(), "Must require client auth (401)" );
	}

	public function test_client_media_upload_route_registered() {
		if ( ! function_exists( 'wptsall_get_client_route_secret' ) ) {
			$this->markTestSkipped( 'wptsall_get_client_route_secret not available' );
		}
		$secret  = wptsall_get_client_route_secret();
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$secret}/client/media-upload" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$r = rest_do_request( $request );
		$this->assertNotEquals( 404, $r->get_status(), "POST /{secret}/client/media-upload must be registered" );
		$this->assertEquals( 401, $r->get_status(), "Must require client auth (401)" );
	}
}
