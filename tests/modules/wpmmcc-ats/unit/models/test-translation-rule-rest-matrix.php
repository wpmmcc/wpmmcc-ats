<?php
/**
 * Translation Rule REST Controller Matrix Tests (Lane D)
 *
 * REST-dispatch coverage for the wptsall/v2 admin routes of
 * includes/models/api/class-translation-rule-rest-controller.php that had
 * zero test references in the catalog:
 *
 * - GET  /adapter-manifests            (WP-REST-wptsall-adapter-manifests)
 * - GET  /field-rules-docs             (WP-REST-wptsall-field-rules-docs)
 * - POST /field-rules-docs/validate    (WP-REST-wptsall-field-rules-docs-validate)
 * - PUT/DELETE /field-rules-docs/{slug} (WP-REST-VAR-...rest-controller-php-116,
 *                                       adjudicated pattern v2/field-rules-docs)
 * - POST /models/detect-fields         (WP-REST-wptsall-models-detect-fields)
 * - POST /models/import-v3             (WP-REST-wptsall-models-import-v3)
 * - POST /rules/validate               (WP-REST-wptsall-rules-validate)
 * - POST /rules/verify-with-url        (WP-REST-wptsall-rules-verify-with-url)
 * - POST /rules/diagnose               (WP-REST-wptsall-rules-diagnose)
 * - POST /rules/import-v3              (WP-REST-wptsall-rules-import-v3)
 *
 * catalog: WP-REST-wptsall-adapter-manifests
 * oracle: L1
 * catalog: WP-REST-wptsall-field-rules-docs
 * oracle: L1
 * catalog: WP-REST-wptsall-field-rules-docs-validate
 * oracle: L1
 * catalog: WP-REST-VAR-wpmmcc-ats-source-includes-models-api-class-translation-rule-rest-controller-php-116
 * oracle: L1
 * catalog: WP-REST-wptsall-models-detect-fields
 * oracle: L1
 * catalog: WP-REST-wptsall-models-import-v3
 * oracle: L1
 * catalog: WP-REST-wptsall-rules-validate
 * oracle: L1
 * catalog: WP-REST-wptsall-rules-verify-with-url
 * oracle: L1
 * catalog: WP-REST-wptsall-rules-diagnose
 * oracle: L1
 * catalog: WP-REST-wptsall-rules-import-v3
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Translation_Rule_Rest_Matrix extends WP_UnitTestCase {

	/**
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * @var array<int, int> Model ids created by this run.
	 */
	private $model_ids = array();

	/**
	 * @var array<int, int> Rule row ids created by this run.
	 */
	private $rule_ids = array();

	/**
	 * @var array<int, int> Post ids created by this run.
	 */
	private $post_ids = array();

	/**
	 * @var string|null Original permalink structure.
	 */
	private $orig_permalink = null;

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->orig_permalink = get_option( 'permalink_structure' );
	}

	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		foreach ( $this->rule_ids as $rule_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( wptsall_table( 'translation_rules' ), array( 'id' => $rule_id ), array( '%d' ) );
		}
		$this->rule_ids = array();
		foreach ( $this->model_ids as $model_id ) {
			Translation_Rule_Service::delete_model( $model_id );
		}
		$this->model_ids = array();
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = array();

		if ( null !== $this->orig_permalink ) {
			update_option( 'permalink_structure', $this->orig_permalink );
			global $wp_rewrite;
			$wp_rewrite->init();
			$wp_rewrite->flush_rules();
		}
		parent::tearDown();
	}

	private function set_pretty_permalinks(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		global $wp_rewrite;
		$wp_rewrite->init();
		$wp_rewrite->flush_rules();
	}

	private function create_model(): int {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'zz-trm-' . strtolower( uniqid() ),
			'plugin_name' => 'TRM ' . uniqid(),
			'is_system'   => true,
			'post_types'  => array(),
			'taxonomies'  => array(),
		) );
		$this->assertGreaterThan( 0, (int) $model_id );
		$this->model_ids[] = (int) $model_id;
		return (int) $model_id;
	}

	private function insert_rule_row( int $model_id, string $object_name, array $caps, string $url_type = 'single' ): int {
		global $wpdb;
		$wpdb->insert(
			wptsall_table( 'translation_rules' ),
			array(
				'model_id'           => $model_id,
				'name'               => 'TRM Rule ' . uniqid(),
				'url_pattern'        => '/zz-trm/' . $object_name . '/',
				'url_type'           => $url_type,
				'data_type'          => 'post',
				'object_name'        => $object_name,
				'field_capabilities' => wp_json_encode( $caps ),
				'related_taxonomies' => wp_json_encode( array() ),
				'is_active'          => 1,
				'priority'           => 10,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		$this->assertNotEmpty( $wpdb->insert_id );
		$this->rule_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	private function dispatch( string $method, string $route, array $params = array(), ?array $json_body = null ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( null !== $json_body ) {
			// Handlers using get_json_params() read the raw JSON body, not
			// request params — populate it explicitly.
			$request->set_body( wp_json_encode( $json_body ) );
			$request->add_header( 'Content-Type', 'application/json' );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	public function test_adapter_manifests_route() {
		$response = $this->dispatch( 'GET', '/wptsall/v2/adapter-manifests' );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data['items'] );
		$this->assertSame( count( $data['items'] ), (int) $data['total'] );
		$this->assertSame( 'adapter-manifest-v1', $data['schema'] );
		// Merge order contract: PHP adapters win over JSON hot-plug for meta_key.
		$this->assertSame(
			array( 'php_adapter', 'json_hotplug', 'manual_model_fields' ),
			$data['merge_order']['meta_key']
		);
	}

	public function test_field_rules_docs_routes_lifecycle() {
		// Listing shape.
		$response = $this->dispatch( 'GET', '/wptsall/v2/field-rules-docs' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['items'] );
		$this->assertSame( count( $data['items'] ), (int) $data['total'] );
		$this->assertSame( 'field-rules-v1', $data['schema'] );

		// Validation endpoint: valid document (raw string form).
		$doc = array(
			'plugin_slug'   => 'zz-trm-' . strtolower( uniqid() ),
			'field_rules'   => array(
				'_trm_field' => array(
					'type'           => 'translate',
					'content_format' => 'plain_text',
					'storage'        => 'post_meta',
				),
			),
			'field_patterns' => array(),
		);
		$response = $this->dispatch( 'POST', '/wptsall/v2/field-rules-docs/validate', array(), array(
			'raw' => wp_json_encode( $doc ),
		) );
		$this->assertSame( 200, $response->get_status(), 'valid doc must pass: ' . wp_json_encode( $response->get_data() ) );
		$this->assertTrue( (bool) $response->get_data()['ok'] );

		// Validation endpoint: invalid document (empty slug).
		$bad           = $doc;
		$bad['plugin_slug'] = '';
		$response = $this->dispatch( 'POST', '/wptsall/v2/field-rules-docs/validate', array(), array(
			'raw' => wp_json_encode( $bad ),
		) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( (bool) $response->get_data()['ok'] );

		// Save (PUT on the slug-bound variable route v2/field-rules-docs/{slug}).
		$slug = $doc['plugin_slug'];
		$response = $this->dispatch( 'PUT', "/wptsall/v2/field-rules-docs/{$slug}", array(), array(
			'raw' => wp_json_encode( $doc ),
		) );
		$this->assertSame( 200, $response->get_status(), 'save must succeed: ' . wp_json_encode( $response->get_data() ) );
		$this->assertTrue( (bool) $response->get_data()['ok'] );

		// The saved document now appears in the listing.
		$response = $this->dispatch( 'GET', '/wptsall/v2/field-rules-docs' );
		$slugs    = wp_list_pluck( $response->get_data()['items'], 'plugin_slug' );
		$this->assertContains( $slug, $slugs );

		// Delete cleans the option store.
		$response = $this->dispatch( 'DELETE', "/wptsall/v2/field-rules-docs/{$slug}" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( (bool) $response->get_data()['ok'] );
		$this->assertSame( $slug, $response->get_data()['plugin_slug'] );

		$response = $this->dispatch( 'GET', '/wptsall/v2/field-rules-docs' );
		$slugs    = wp_list_pluck( $response->get_data()['items'], 'plugin_slug' );
		$this->assertNotContains( $slug, $slugs );
	}

	public function test_models_detect_fields_route() {
		$response = $this->dispatch( 'POST', '/wptsall/v2/models/detect-fields', array(
			'data_type'   => 'post',
			'object_name' => 'post',
		) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertSame( 'post', $data['data_type'] );
		$this->assertSame( 'post', $data['object_name'] );
		$this->assertIsArray( $data['fields'] );
		$field_names = wp_list_pluck( $data['fields'], 'name' );
		$this->assertContains( 'post_title', $field_names );
		$this->assertContains( 'post_content', $field_names );

		// Unsupported data type is rejected with 400 (args enum or handler).
		$response = $this->dispatch( 'POST', '/wptsall/v2/models/detect-fields', array(
			'data_type'   => 'bogus',
			'object_name' => 'post',
		) );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_rules_validate_route() {
		// Without rule_id: 200 with valid=false and an explanatory error.
		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/validate', array(), array() );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( (bool) $data['valid'] );
		$this->assertNotEmpty( $data['errors'] );

		// With a seeded invalid rule (unknown capability type).
		$model_id = $this->create_model();
		$rule_id  = $this->insert_rule_row( $model_id, 'zz_trm_pt_' . uniqid(), array(
			'_f' => array( 'type' => 'explode' ),
		) );

		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/validate', array(), array(
			'rule_id' => $rule_id,
		) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( (bool) $data['valid'] );
		$this->assertNotEmpty( $data['errors'] );
	}

	public function test_rules_verify_with_url_route() {
		$this->set_pretty_permalinks();
		$slug    = 'zz-trm-' . strtolower( uniqid() );
		$post_id = wp_insert_post( array(
			'post_title'   => $slug,
			'post_name'    => $slug,
			'post_content' => 'x',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		), true );
		$this->assertTrue( ! is_wp_error( $post_id ), 'post insert failed' );
		$this->post_ids[] = (int) $post_id;

		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/verify-with-url', array(
			'url' => home_url( '/' . $slug . '/' ),
		) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertSame( (int) $post_id, (int) $data['post']['id'] );
		$this->assertSame( 'post', $data['post']['post_type'] );
		$this->assertArrayHasKey( 'model', $data );
		$this->assertArrayHasKey( 'coverage', $data );
		$this->assertIsArray( $data['coverage'] );

		// Unresolvable URL: 404 url_not_resolved.
		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/verify-with-url', array(
			'url' => home_url( '/no-such-' . strtolower( uniqid() ) . '/' ),
		) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'url_not_resolved', $response->get_data()['code'] );
	}

	public function test_rules_diagnose_route() {
		// Unknown model: 404 not_found.
		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/diagnose', array(
			'model_id' => 999999999,
		) );
		$this->assertSame( 404, $response->get_status() );

		// Real model: full diagnostic report.
		$model_id = $this->create_model();
		Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'zz_trm_diag_' . uniqid(),
			array( 'source_type' => 'manual', 'metadata' => array() )
		);

		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/diagnose', array(
			'model_id' => $model_id,
		) );
		$this->assertSame( 200, $response->get_status(), 'diagnose must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertSame( $model_id, (int) $data['model']['id'] );
		$this->assertIsArray( $data['report'] );
		$this->assertArrayHasKey( 'summary', $data );
	}

	public function test_models_import_v3_route() {
		// Missing json: the REST required-args boundary fires first
		// (rest_missing_callback_param); the handler's missing_json branch is
		// defensive and unreachable via REST.
		$response = $this->dispatch( 'POST', '/wptsall/v2/models/import-v3', array() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );

		// Invalid JSON payload: 400 invalid_json.
		$response = $this->dispatch( 'POST', '/wptsall/v2/models/import-v3', array(
			'json' => 'not-json',
		) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_json', $response->get_data()['code'] );

		// Round-trip: export a seeded model, check-import it, conflict is
		// detected against the same slug.
		$model_id = $this->create_model();
		$json     = wptsall_export_model( $model_id );
		$this->assertIsString( $json );
		$this->assertNotEmpty( $json );

		$response = $this->dispatch( 'POST', '/wptsall/v2/models/import-v3', array(
			'json'   => $json,
			'action' => 'check',
		) );
		$this->assertSame( 200, $response->get_status(), 'check import must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['conflict'], 'exported model slug already exists: check must report conflict' );
		$this->assertSame( $model_id, (int) $data['existing_id'] );
	}

	public function test_rules_import_v3_route() {
		// Missing json: REST required-args boundary (json is required).
		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/import-v3', array() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );

		// Neither model_id nor plugin_slug: 400 invalid_target.
		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/import-v3', array(
			'json' => '{"version":"3.0","type":"rule"}',
		) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_target', $response->get_data()['code'] );

		// Round-trip: seed model + object + rule, export rule, check-import.
		$model_id    = $this->create_model();
		$object_name = 'zz_trm_imp_' . uniqid();
		$object_id   = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			$object_name,
			array( 'source_type' => 'manual', 'metadata' => array() )
		);
		$this->assertGreaterThan( 0, (int) $object_id );
		$rule_id = $this->insert_rule_row( $model_id, $object_name, array(
			'_imp_field' => array( 'type' => 'translate' ),
		) );

		$json = wptsall_export_rule( $rule_id );
		$this->assertIsString( $json );
		$this->assertNotEmpty( $json );

		$response = $this->dispatch( 'POST', '/wptsall/v2/rules/import-v3', array(
			'json'     => $json,
			'model_id' => $model_id,
			'action'   => 'check',
		) );
		$this->assertSame( 200, $response->get_status(), 'check import must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'conflict', $data );
		$this->assertTrue( (bool) $data['conflict'], 'same (model,object,url_type) already exists: check must conflict' );
	}
}
