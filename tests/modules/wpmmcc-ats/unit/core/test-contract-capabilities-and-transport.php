<?php
/**
 * P1 contract capabilities + transport policy + adapter manifest tests.
 *
 * Run via: php tests/modules/wpmmcc-ats/unit/run.php --file=test-contract-capabilities-and-transport.php
 *
 * @package WPTSALL\Tests\Unit\Core
 */

class Test_Contract_Capabilities_And_Transport extends SimpleTestCase {

	public function test_supported_contract_capabilities_axes() {
		$this->assertTrue( function_exists( 'wptsall_get_supported_contract_capabilities' ) );
		$caps = wptsall_get_supported_contract_capabilities();
		$this->assertEquals( 2, $caps['wp_client_protocol'] );
		$this->assertEquals( 'content-formats-v1', $caps['content_formats'] );
		$this->assertEquals( 'workflow-policy-v1', $caps['workflow_policy'] );
		$this->assertEquals( 'workflow-dsl-v1', $caps['workflow_dsl'] );
	}

	public function test_missing_capabilities_header_is_allowed() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/tasks' );
		$result  = wptsall_check_client_contract_capabilities( $request );
		$this->assertTrue( $result );
	}

	public function test_matching_capabilities_header_passes() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/tasks' );
		$request->set_header(
			'X-WPTSALL-Contract-Capabilities',
			wp_json_encode(
				array(
					'wp_client_protocol' => 2,
					'workflow_dsl'       => 'workflow-dsl-v1',
				)
			)
		);
		$result = wptsall_check_client_contract_capabilities( $request );
		$this->assertTrue( $result );
	}

	public function test_mismatched_protocol_capability_fails() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/tasks' );
		$request->set_header(
			'X-WPTSALL-Contract-Capabilities',
			wp_json_encode( array( 'wp_client_protocol' => 1 ) )
		);
		$result = wptsall_check_client_contract_capabilities( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'unsupported_contract_capability', $result->get_error_code() );
	}

	/**
	 * opus5 A-05 (decision D-4): versions.json compatibility_rule promises
	 * fail-closed for unknown versions; unknown axis NAMES get the same
	 * treatment — an unrecognized axis must 400, never silently pass as
	 * "supported".
	 */
	public function test_unknown_capability_axis_fails_closed() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/tasks' );
		$request->set_header(
			'X-WPTSALL-Contract-Capabilities',
			wp_json_encode(
				array(
					'wp_client_protocol' => 2,
					'brand_new_axis'     => 'brand-new-v1',
				)
			)
		);
		$result = wptsall_check_client_contract_capabilities( $request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'unknown_contract_capability', $result->get_error_code() );
		$this->assertEquals( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'supported', $result->get_error_data(), 'error data must carry the supported axis map' );
	}

	public function test_transport_policy_default_https_optional() {
		$method = new ReflectionMethod( \WPTSALL\Core\Transport_Middleware::class, 'needs_encryption' );
		$method->setAccessible( true );

		remove_all_filters( 'wptsall_client_transport_encryption_policy' );
		remove_all_filters( 'wptsall_client_transport_encryption_required' );

		// CLI/local lab is usually non-SSL → https_optional requires encryption.
		$default = $method->invoke( null );
		$this->assertTrue( $default, 'HTTP + https_optional should require encryption' );

		add_filter( 'wptsall_client_transport_encryption_policy', static function () {
			return 'off';
		} );
		$this->assertFalse( $method->invoke( null ), 'policy=off should not require encryption' );

		remove_all_filters( 'wptsall_client_transport_encryption_policy' );
		add_filter( 'wptsall_client_transport_encryption_policy', static function () {
			return 'always';
		} );
		$this->assertTrue( $method->invoke( null ), 'policy=always should require encryption' );

		remove_all_filters( 'wptsall_client_transport_encryption_policy' );
		remove_all_filters( 'wptsall_client_transport_encryption_required' );
	}

	public function test_adapter_manifest_lists_formats() {
		$all = \WPTSALL\Models\Adapters\Adapter_Manifest::all();
		$this->assertTrue( is_array( $all ) && ! empty( $all ), 'manifests should not be empty' );
		$this->assertTrue( isset( $all['elementor'] ), 'elementor adapter should appear' );
		$this->assertEquals( 2, $all['elementor']['min_wp_client_protocol'] );
		$this->assertTrue( in_array( 'json_structured', $all['elementor']['content_formats'], true ) );
		$this->assertEquals( 'content-formats-v1', $all['elementor']['content_formats_vocab'] );
	}
}
