<?php
/**
 * Sites REST Controller: User Mappings & Theme Sync Tests (Lane D)
 *
 * REST-dispatch coverage for wptsall/v2 admin routes of
 * includes/sites/api/class-sites-rest-controller.php with zero prior
 * catalog references:
 *
 * - POST   /site-relations/sync-themes (WP-REST-wptsall-site-relations-sync-themes)
 * - GET/POST/DELETE /user-mappings     (WP-REST-VAR-...sites-rest-controller-php-667,
 *                                       adjudicated pattern v2/user-mappings)
 * - GET    /user-mappings/stats        (WP-REST-wptsall-user-mappings-stats)
 *
 * catalog: WP-REST-wptsall-site-relations-sync-themes
 * oracle: L1
 * catalog: WP-REST-VAR-wpmmcc-ats-source-includes-sites-api-class-sites-rest-controller-php-667
 * oracle: L1
 * catalog: WP-REST-wptsall-user-mappings-stats
 * oracle: L1
 * catalog: WP-CLASS-Site_Rest_Controller
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\User_Mapping_Service;

class Test_Sites_Rest_User_Mappings extends WP_UnitTestCase {

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

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		$table = wptsall_table( 'site_relations' );
		foreach ( $this->relation_ids as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $relation_id ), array( '%d' ) );
		}
		$this->relation_ids = array();
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
				'template'         => 'zz-sum-' . uniqid(),
				'target_site_id'   => 'v_sum_' . uniqid(),
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

	private function dispatch( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	public function test_site_relations_sync_themes_route() {
		// The permission boundary validates source_site_id as an existing
		// WordPress site before the handler runs: an unknown site id is a 404
		// rest_object_not_found (not the handler's no_relations error).
		$response = $this->dispatch( 'POST', '/wptsall/v2/site-relations/sync-themes', array(
			'source_site_id' => 999999999,
		) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_object_not_found', $response->get_data()['code'] );

		// Real source site with a seeded relation: batch sync report shape.
		// (Other tests may hold leftover relations for site 1, so only the
		// floor is pinned.)
		$this->insert_relation( get_current_blog_id() );
		$response = $this->dispatch( 'POST', '/wptsall/v2/site-relations/sync-themes', array(
			'source_site_id' => get_current_blog_id(),
		) );
		$this->assertSame( 200, $response->get_status(), 'sync-themes must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertGreaterThanOrEqual( 1, (int) $data['total_count'] );
		$this->assertIsInt( (int) $data['synced_count'] );
		$this->assertIsArray( $data['failed_ids'] );
		$this->assertSame( (int) $data['total_count'], (int) $data['synced_count'] + count( $data['failed_ids'] ), 'synced + failed must account for every relation' );
	}

	public function test_user_mappings_crud_round_trip() {
		$source_site_id = 1;
		$target_site_id = 'v_um_' . uniqid();

		// Empty pair: empty list, zero stats.
		$response = $this->dispatch( 'GET', '/wptsall/v2/user-mappings', array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
		) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertSame( array(), $data['items'] );
		$this->assertSame( 0, (int) $data['total'] );
		$this->assertSame( 1, (int) $data['pages'] );

		// Batch save of two mappings (targets must be existing users).
		$user_a = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'editor' ) );
		$response = $this->dispatch( 'POST', '/wptsall/v2/user-mappings', array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
			'mappings'       => array( (string) $user_a => (string) $this->admin_id, (string) $user_b => (string) $user_a ),
		) );
		$this->assertSame( 200, $response->get_status(), 'save must succeed: ' . wp_json_encode( $response->get_data() ) );
		$this->assertTrue( (bool) $response->get_data()['success'] );

		// The pair now lists both mappings.
		$response = $this->dispatch( 'GET', '/wptsall/v2/user-mappings', array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
		) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 2, (int) $data['total'] );
		$sources = wp_list_pluck( $data['items'], 'source_user_id' );
		$this->assertContains( (int) $user_a, array_map( 'intval', $sources ) );

		// Stats for the pair count them.
		$response = $this->dispatch( 'GET', '/wptsall/v2/user-mappings/stats', array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
		) );
		$this->assertSame( 200, $response->get_status() );
		$stats = $response->get_data()['stats'];
		$this->assertIsArray( $stats );
		$this->assertSame( 2, (int) $stats['total'] );

		// Missing mappings payload: the REST required-args boundary fires
		// first (mappings is a required arg). NOTE (catalog FINDING): the
		// handler documents a single-mapping format (source_user_id +
		// target_user_id without mappings), but that branch is unreachable
		// via REST for the same reason.
		$response = $this->dispatch( 'POST', '/wptsall/v2/user-mappings', array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
		) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );

		// Delete-all clears the pair and reports the count.
		$response = $this->dispatch( 'DELETE', '/wptsall/v2/user-mappings', array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
		) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( (bool) $response->get_data()['success'] );
		$this->assertSame( 2, (int) $response->get_data()['deleted'] );

		// Service-level confirmation: nothing remains for the pair.
		$this->assertSame( array(), User_Mapping_Service::get_mappings_by_sites( $source_site_id, $target_site_id ) );
	}
}
