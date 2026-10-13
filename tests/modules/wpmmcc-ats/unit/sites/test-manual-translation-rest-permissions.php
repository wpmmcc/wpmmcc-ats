<?php
/**
 * Manual translation REST permission tests (M-01, opus5)
 *
 * Manual_Translation_REST_Controller::check_permission must reject an
 * inactive relation with 409 relation_inactive before any handler runs,
 * mirroring the Manual_Content_Service write guards. No prior unit test
 * covered the /manual-translations routes' permission callback at all.
 *
 * @package WPTSALL
 * @since 2.1.4
 */

use WPTSALL\Sites\API\Manual_Translation_REST_Controller;
use WPTSALL\Sites\Services\Site_Relation_Service;

class Test_Manual_Translation_REST_Permissions extends SimpleTestCase {

	/**
	 * Test relation IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_relation_ids = array();

	/**
	 * Test post IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_post_ids = array();

	/**
	 * Test user IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_user_ids = array();

	public function setUp(): void {
		parent::setUp();
		wp_cache_flush();
	}

	public function tearDown(): void {
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->test_post_ids = array();

		foreach ( $this->test_relation_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}
		$this->test_relation_ids = array();

		foreach ( $this->test_user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->test_user_ids = array();

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Helper: create a test source post.
	 *
	 * @return int Post ID.
	 */
	private function create_test_post() {
		$post_id = wp_insert_post( array(
			'post_title'   => 'REST Permission Source ' . uniqid(),
			'post_content' => 'REST permission test content',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );
		$this->test_post_ids[] = $post_id;
		return $post_id;
	}

	/**
	 * Helper: create a test relation (virtual site target).
	 *
	 * @return int|null Relation ID or null on failure.
	 */
	private function create_test_relation() {
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => 'en',
			'target_sites'   => array(
				array(
					'id'   => 'v_test_' . uniqid(),
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		if ( ! empty( $result['success'] ) && ! empty( $result['relation_ids'] ) ) {
			$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );
			return $result['relation_ids'][0];
		}

		return null;
	}

	/**
	 * Helper: create a logged-in administrator with the translations cap
	 * granted explicitly (deterministic regardless of role wiring).
	 *
	 * @return int User ID.
	 */
	private function create_admin_user() {
		$username = 'restperm_' . uniqid();
		$user_id  = wp_create_user( $username, 'pass-' . uniqid(), $username . '@test.example' );
		$user     = get_userdata( $user_id );
		$user->set_role( 'administrator' );
		$user->add_cap( 'manage_wptsall_translations' );
		wp_set_current_user( $user_id );
		$this->test_user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * M-01: check_permission must accept an active relation and reject the
	 * same request with 409 relation_inactive once the relation is inactive.
	 */
	public function test_check_permission_rejects_inactive_relation_with_409() {
		$this->create_admin_user();

		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$controller = new Manual_Translation_REST_Controller();

		// Control: the active relation must pass the permission callback.
		$active_request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-translations' );
		$active_request->set_param( 'relation_id', $relation_id );
		$active_request->set_param( 'source_post_id', $post_id );

		$active_result = $controller->check_permission( $active_request );
		$this->assertFalse(
			is_wp_error( $active_result ),
			'Active relation must pass check_permission: ' . ( is_wp_error( $active_result ) ? $active_result->get_error_message() : '' )
		);

		// Deactivate; the identical request must now be rejected with 409.
		Site_Relation_Service::update_relation( $relation_id, array( 'status' => 'inactive' ) );
		wp_cache_flush();

		$inactive_request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-translations' );
		$inactive_request->set_param( 'relation_id', $relation_id );
		$inactive_request->set_param( 'source_post_id', $post_id );

		$result = $controller->check_permission( $inactive_request );

		$this->assertTrue( is_wp_error( $result ), 'Inactive relation must be rejected' );
		$this->assertSame( 'relation_inactive', $result->get_error_code(), 'Error code must be relation_inactive' );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null, 'Error status must be 409' );
	}

	/**
	 * M-01: check_permission must still return plain 404 semantics for a
	 * nonexistent relation (no false 409) and keep 401 for anonymous users.
	 */
	public function test_check_permission_keeps_404_and_401_semantics() {
		$this->create_admin_user();

		$controller = new Manual_Translation_REST_Controller();

		$missing_request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-translations' );
		$missing_request->set_param( 'relation_id', 999999999 );

		$missing_result = $controller->check_permission( $missing_request );
		$this->assertTrue( is_wp_error( $missing_result ), 'Missing relation must be rejected' );
		$this->assertNotSame( 'relation_inactive', $missing_result->get_error_code(), 'A missing relation must not be reported as inactive' );

		wp_set_current_user( 0 );

		$anon_request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-translations' );
		$anon_request->set_param( 'relation_id', 1 );

		$anon_result = $controller->check_permission( $anon_request );
		$this->assertTrue( is_wp_error( $anon_result ), 'Anonymous request must be rejected' );
		$this->assertSame( 'rest_not_logged_in', $anon_result->get_error_code(), 'Anonymous must be 401 rest_not_logged_in' );
	}
}
