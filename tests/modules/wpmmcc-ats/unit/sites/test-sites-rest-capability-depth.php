<?php
/**
 * Sites REST Controller: Capability-Matrix Object Depth (Lane Phase 4; plan
 * §7 TEST-REST-CAPABILITY-MATRIX-001 — object-ownership 403 depth).
 *
 * Lane D covered the route-level L1 matrix; this file proves the depth
 * semantics of Sites_Rest_Controller::check_permission() on the
 * object-bearing routes:
 *
 *  1. The cap gate (403 rest_forbidden) runs BEFORE object resolution, so a
 *     no-cap user cannot enumerate relation ids: the status and error code
 *     are identical whether the addressed object exists or not.
 *  2. A cap-passing non-admin (wptsall_translator holds
 *     manage_wptsall_translations) still hits the object layer: missing
 *     objects are 404 rest_object_not_found at the permission boundary,
 *     never inside the handler.
 *  3. Object-ownership depth: a nested model that exists but belongs to a
 *     different relation is 403 rest_object_forbidden ("Model is not
 *     associated with the selected site relation") — the handler never
 *     runs. The owning relation gets the real 200 round trip.
 *
 * catalog: WP-CLASS-Site_Rest_Controller
 * oracle: L2
 * catalog: WP-REST-VAR-wpmmcc-ats-source-includes-sites-api-class-sites-rest-controller-php-170
 * oracle: L1
 * catalog: WP-REST-VAR-wpmmcc-ats-source-includes-sites-api-class-sites-rest-controller-php-405
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

class Test_Sites_Rest_Capability_Depth extends WP_UnitTestCase {

	/**
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * @var array<int, int> Site relation row ids created by this run.
	 */
	private $relation_ids = array();

	/**
	 * @var array<int, int> Models table row ids created by this run.
	 */
	private $model_ids = array();

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// The role is registered on plugin activation; unit runs may start
		// from a clean role table, so mirror the activation caps when absent.
		if ( ! get_role( 'wptsall_translator' ) ) {
			add_role(
				'wptsall_translator',
				'WPTSALL Translator',
				array(
					'read'                        => true,
					'manage_wptsall'              => true,
					'manage_wptsall_translations' => true,
				)
			);
		}
	}

	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		$relation_models = wptsall_table( 'relation_models' );
		foreach ( $this->relation_ids as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relation_models, array( 'relation_id' => $relation_id ), array( '%d' ) );
		}
		$models = wptsall_table( 'models' );
		foreach ( $this->model_ids as $model_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $models, array( 'id' => $model_id ), array( '%d' ) );
		}
		$relations = wptsall_table( 'site_relations' );
		foreach ( $this->relation_ids as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relations, array( 'id' => $relation_id ), array( '%d' ) );
		}
		$this->relation_ids = array();
		$this->model_ids    = array();
		parent::tearDown();
	}

	/**
	 * Insert a site relation row for an isolated source site id.
	 *
	 * @param int $source_site_id Source site id (unique per run).
	 * @return int Relation id.
	 */
	private function insert_relation( int $source_site_id ): int {
		global $wpdb;
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => $source_site_id,
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'zz-capd-' . uniqid(),
				'target_site_id'   => 'v_capd_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotEmpty( $wpdb->insert_id );
		$this->relation_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a models table fixture row.
	 *
	 * @return int Model id.
	 */
	private function insert_model(): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			wptsall_table( 'models' ),
			array(
				'plugin_slug'       => 'test-capd-' . uniqid(),
				'plugin_name'       => 'Cap Depth Fixture',
				'is_content_plugin' => 1,
				'post_types'        => wp_json_encode( array( 'post' ) ),
				'taxonomies'        => '[]',
				'meta_fields'       => '[]',
				'custom_tables'     => '[]',
				'status'            => 'active',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$this->assertTrue( (bool) $ok, 'models fixture insert failed: ' . $wpdb->last_error );
		$this->model_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	private function dispatch( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	public function test_no_cap_user_uniform_403_without_existence_leak() {
		$relation_id = $this->insert_relation( 910001 );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$existing = $this->dispatch( 'GET', "/wptsall/v2/site-relations/{$relation_id}" );
		$missing  = $this->dispatch( 'GET', '/wptsall/v2/site-relations/999999999' );

		// Cap gate precedes object resolution: identical 403 rest_forbidden
		// for an existing and a missing object — no enumeration oracle.
		$this->assertSame( 403, $existing->get_status() );
		$this->assertSame( 'rest_forbidden', $existing->get_data()['code'] );
		$this->assertSame( 403, $missing->get_status() );
		$this->assertSame( 'rest_forbidden', $missing->get_data()['code'] );
		$this->assertSame(
			$existing->get_data()['code'],
			$missing->get_data()['code'],
			'error code must not reveal object existence to a no-cap user'
		);
	}

	public function test_missing_relation_404_at_permission_boundary() {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/wptsall/v2/site-relations/999999999' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_object_not_found', $response->get_data()['code'] );
	}

	public function test_object_ownership_403_for_foreign_nested_model() {
		$relation_a = $this->insert_relation( 910002 );
		$relation_b = $this->insert_relation( 910003 );
		$model_id   = $this->insert_model();

		$attached = \WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $relation_b, $model_id );
		$this->assertNotFalse( $attached, 'fixture: model must attach to relation B' );

		wp_set_current_user( $this->admin_id );

		// The model exists but belongs to relation B: addressed under
		// relation A the permission layer answers ownership 403 — the
		// handler must not run (no remove, no handler error codes).
		$foreign = $this->dispatch( 'DELETE', "/wptsall/v2/site-relations/{$relation_a}/models/{$model_id}" );
		$this->assertSame( 403, $foreign->get_status() );
		$this->assertSame( 'rest_object_forbidden', $foreign->get_data()['code'] );

		// Contrast: the owning relation gets the real round trip.
		$owning = $this->dispatch( 'DELETE', "/wptsall/v2/site-relations/{$relation_b}/models/{$model_id}" );
		$this->assertSame( 200, $owning->get_status() );
		$data = $owning->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertSame( $relation_b, (int) $data['relation_id'] );
		$this->assertSame( $model_id, (int) $data['model_id'] );
	}

	public function test_translator_hits_object_depth_despite_cap_pass() {
		$relation_a = $this->insert_relation( 910004 );
		$relation_b = $this->insert_relation( 910005 );
		$model_id   = $this->insert_model();

		$attached = \WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $relation_b, $model_id );
		$this->assertNotFalse( $attached, 'fixture: model must attach to relation B' );

		$translator = self::factory()->user->create( array( 'role' => 'wptsall_translator' ) );
		wp_set_current_user( $translator );

		$this->assertTrue(
			wptsall_user_can_manage_translations(),
			'fixture: translator must pass the manage-translations cap gate'
		);

		// Missing object under a cap-passing user: 404 at the permission
		// boundary (rest_object_not_found), never the handler's codes.
		$missing = $this->dispatch( 'DELETE', "/wptsall/v2/site-relations/{$relation_a}/models/999999999" );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'rest_object_not_found', $missing->get_data()['code'] );

		// Ownership depth applies to the cap-passing translator exactly as
		// to the admin: the foreign nested model is 403 rest_object_forbidden.
		$foreign = $this->dispatch( 'DELETE', "/wptsall/v2/site-relations/{$relation_a}/models/{$model_id}" );
		$this->assertSame( 403, $foreign->get_status() );
		$this->assertSame( 'rest_object_forbidden', $foreign->get_data()['code'] );
	}
}
