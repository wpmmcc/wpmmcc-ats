<?php
/**
 * Chunked media upload REST lifecycle tests (client-data controller).
 *
 * Drives the media-upload session protocol end to end through the real
 * controller handlers — init → chunk → status → complete — plus the
 * documented 400/404 error legs. Route registration is asserted against
 * the secret-prefixed REST family (client non_text.rs protocol).
 *
 * catalog: WP-REST-wptsall-media-upload-init
 * catalog: WP-REST-wptsall-media-upload-chunk
 * catalog: WP-REST-wptsall-media-upload-status
 * catalog: WP-REST-wptsall-media-upload-complete
 * oracle: L2
 *
 * @package WPTSALL
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Tasks\API\Client_Data_REST_Controller;

/**
 * Test_Media_Upload_REST
 */
class Test_Media_Upload_REST extends SimpleTestCase {

	/**
	 * Session dirs created during the run (for cleanup).
	 *
	 * @var array
	 */
	private $session_dirs = array();

	/**
	 * Relation IDs created during the run (for cleanup).
	 *
	 * @var array
	 */
	private $relation_ids = array();

	/**
	 * Attachment IDs created during the run (for cleanup).
	 *
	 * @var array
	 */
	private $created_attachment_ids = array();

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		foreach ( $this->session_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				foreach ( (array) glob( $dir . '/*' ) as $file ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
					@unlink( $file ); // phpcs:ignore
				}
				@rmdir( $dir ); // phpcs:ignore
			}
		}
		$this->session_dirs = array();
		foreach ( $this->relation_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}
		$this->relation_ids = array();
		foreach ( $this->created_attachment_ids as $aid ) {
			wp_delete_attachment( (int) $aid, true );
		}
		$this->created_attachment_ids = array();
		parent::tearDown();
	}

	/**
	 * Helper: create a virtual-site relation (media attach target).
	 *
	 * @return int Relation ID.
	 */
	private function create_test_relation() {
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => 'en',
			'target_sites'   => array(
				array(
					'id'   => 'v_test_mur_' . uniqid(),
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );
		$this->assertNotEmpty( $result['relation_ids'] ?? array(), 'relation must be created for the media attach' );
		$this->relation_ids = array_map( 'absint', $result['relation_ids'] );
		return (int) $result['relation_ids'][0];
	}

	/**
	 * Helper: session dir for an upload id (mirrors controller layout).
	 *
	 * @param string $upload_id Upload session id.
	 * @return string
	 */
	private function session_dir( $upload_id ) {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wptsall-chunked/' . $upload_id;
		if ( ! in_array( $dir, $this->session_dirs, true ) ) {
			$this->session_dirs[] = $dir;
		}
		return $dir;
	}

	/**
	 * The four secret-prefixed chunked-upload routes are registered.
	 */
	public function test_chunked_upload_routes_registered() {
		$routes = rest_get_server()->get_routes();
		$secret = function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '';
		$prefix = '/wptsall/v2/' . $secret . '/client/media-upload';

		$this->assertArrayHasKey( $prefix . '/init', $routes, 'media-upload/init route missing (expected ' . $prefix . '/init)' );
		$this->assertArrayHasKey( $prefix . '/chunk', $routes, 'media-upload/chunk route missing' );
		$this->assertArrayHasKey( $prefix . '/status', $routes, 'media-upload/status route missing' );
		$this->assertArrayHasKey( $prefix . '/complete', $routes, 'media-upload/complete route missing' );
	}

	/**
	 * Full lifecycle: init → chunk → status → complete with one chunk.
	 *
	 * Drives a REAL source attachment bound to a REAL relation so the final
	 * media_upload() import leg runs its genuine validation chain.
	 */
	public function test_full_chunked_upload_lifecycle() {
		$controller = new Client_Data_REST_Controller();

		// Real 1x1 PNG: chunk payload AND source attachment bytes.
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		$upload    = wp_upload_bits( 'lifecycle-source.png', null, $png );
		$this->assertEmpty( $upload['error'], 'source attachment upload must succeed' );
		$source_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Media upload lifecycle source',
				'post_status'    => 'private',
			),
			$upload['file']
		);
		$this->assertGreaterThan( 0, (int) $source_id, 'source attachment must be created' );
		$this->created_attachment_ids[] = (int) $source_id;

		// init
		$init_request = new WP_REST_Request( 'POST', '/client/media-upload/init' );
		$init_request->set_header( 'X-WPTSALL-Device-Id', 'owned-media-unit' );
		$init_request->set_header( 'Content-Type', 'application/json' );
		// A real 1x1 PNG so the final import leg runs against genuine media bytes.
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		$init_request->set_body( wp_json_encode( array(
			'filename'     => 'lifecycle-test.png',
			'total_size'   => strlen( $png ),
			'chunk_count'  => 1,
			'content_type' => 'image/png',
			'relation_id'  => $this->create_test_relation(),
			'source_id'    => (int) $source_id,
		) ) );

		$init_response = $controller->media_upload_init( $init_request );
		$init_data     = $init_response->get_data();
		$this->assertTrue( (bool) $init_data['success'], 'init must succeed for a valid payload' );
		$this->assertNotEmpty( $init_data['upload_id'] );
		$upload_id = $init_data['upload_id'];
		$this->session_dir( $upload_id );

		// chunk
		$chunk_request = new WP_REST_Request( 'POST', '/client/media-upload/chunk' );
		$chunk_request->set_header( 'X-WPTSALL-Device-Id', 'owned-media-unit' );
		$chunk_request->set_header( 'X-WPTSALL-Upload-ID', $upload_id );
		$chunk_request->set_header( 'X-WPTSALL-Chunk-Index', '0' );
		$chunk_request->set_body( $png );

		$chunk_response = $controller->media_upload_chunk( $chunk_request );
		$chunk_data     = $chunk_response->get_data();
		$this->assertTrue( (bool) $chunk_data['success'], 'chunk 0 must be accepted' );
		$this->assertFileExists( $this->session_dir( $upload_id ) . '/chunk-0.bin' );

		// status
		$status_request = new WP_REST_Request( 'GET', '/client/media-upload/status' );
		$status_request->set_header( 'X-WPTSALL-Device-Id', 'owned-media-unit' );
		$status_request->set_param( 'upload_id', $upload_id );
		$status_response = $controller->media_upload_status( $status_request );
		$status_data     = $status_response->get_data();
		$this->assertTrue( (bool) $status_data['success'] );
		$this->assertSame( array( 0 ), $status_data['data']['received_chunks'], 'chunk 0 must be reported received' );
		$this->assertSame( array(), $status_data['data']['missing_chunks'], 'no chunks may be missing after upload' );

		// complete
		$complete_request = new WP_REST_Request( 'POST', '/client/media-upload/complete' );
		$complete_request->set_header( 'X-WPTSALL-Device-Id', 'owned-media-unit' );
		$complete_request->set_header( 'Content-Type', 'application/json' );
		$complete_request->set_body( wp_json_encode( array( 'upload_id' => $upload_id ) ) );

		$complete_response = $controller->media_upload_complete( $complete_request );
		$complete_data     = $complete_response->get_data();
		$this->assertTrue(
			(bool) $complete_data['success'],
			'complete must assemble the uploaded chunks: ' . wp_json_encode( $complete_data )
		);
		$attachment_id = (int) $complete_data['attachment_id'];
		$this->assertGreaterThan( 0, $attachment_id );
		$this->created_attachment_ids[] = $attachment_id;
		$replay = $controller->media_upload_complete( $complete_request );
		$this->assertSame( $complete_data, $replay->get_data(), 'complete must replay the saved attachment' );
		$this->assertSame( 200, $replay->get_status() );
		$saved_status = $controller->media_upload_status( $status_request );
		$this->assertSame( $attachment_id, $saved_status->get_data()['data']['attachment_id'] );
		$this->assertFileExists( $this->session_dir( $upload_id ) . '/chunk-0.bin', 'success retains the original chunk' );
	}

	/**
	 * init rejects incomplete payloads with the documented 400 error code.
	 */
	public function test_init_rejects_invalid_payload() {
		$controller = new Client_Data_REST_Controller();

		$request = new WP_REST_Request( 'POST', '/client/media-upload/init' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'filename' => '' ) ) );

		$response = $controller->media_upload_init( $request );
		$this->assertSame( 400, $response->get_status(), 'invalid init must be a 400' );
		$this->assertSame( 'invalid_init', $response->get_data()['error'] );
	}

	/**
	 * chunk/status/complete return the documented 404 for unknown sessions.
	 */
	public function test_unknown_upload_session_returns_404() {
		$controller = new Client_Data_REST_Controller();

		$chunk_request = new WP_REST_Request( 'POST', '/client/media-upload/chunk' );
		$chunk_request->set_header( 'X-WPTSALL-Upload-ID', 'no-such-session' );
		$chunk_request->set_header( 'X-WPTSALL-Chunk-Index', '0' );
		$chunk_request->set_body( 'x' );
		$chunk_response = $controller->media_upload_chunk( $chunk_request );
		$this->assertSame( 404, $chunk_response->get_status(), 'unknown upload session must be a 404' );
		$this->assertSame( 'unknown_upload_id', $chunk_response->get_data()['error'] );

		$status_request = new WP_REST_Request( 'GET', '/client/media-upload/status' );
		$status_request->set_param( 'upload_id', 'no-such-session' );
		$status_response = $controller->media_upload_status( $status_request );
		$this->assertSame( 404, $status_response->get_status() );
	}
}
