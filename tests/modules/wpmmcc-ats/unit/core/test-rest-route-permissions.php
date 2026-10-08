<?php
/**
 * REST Route Permission Audit Tests
 *
 * Executable regression for the one-off REST permission audit in
 * tests/docs/guide/18 §3 (159-route permission review). Iterates every
 * route registered under the wptsall namespace (wptsall/v2, including the
 * route-secret client namespace pattern /wptsall/v2/{secret}/client/...)
 * and asserts each endpoint declares a real permission_callback.
 *
 * Negative case: an editor (no manage_wptsall_* capability) must receive
 * 403 on an admin route, then the admin user is restored.
 *
 * @package WPTSALL
 * @since 2.1.0
 */

/**
 * Test_Rest_Route_Permissions
 */
class Test_Rest_Route_Permissions extends SimpleTestCase {

	/**
	 * REST server instance
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Set up test environment: fresh REST server with all plugin routes.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Clean up: drop the REST server and reset current user.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		$this->server   = null;
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Collect all wptsall endpoints from the REST server.
	 *
	 * Namespace facts (verified against includes/tasks/api/* and
	 * includes/{models,sites,templates}/api/* controllers): every
	 * register_rest_route() call uses namespace 'wptsall/v2' — the dynamic
	 * route secret is embedded in the route PATH (/wptsall/v2/{secret}/client/...),
	 * so a '/wptsall/' route-prefix match covers both admin and client
	 * (secret) route families.
	 *
	 * @return array[] List of array( route, endpoint ) pairs.
	 */
	private function collect_wptsall_endpoints() {
		$collected = array();
		foreach ( $this->server->get_routes() as $route => $endpoints ) {
			// Only plugin routes. Core namespaces (wp/v2, oembed, batch/v1,
			// third-party) are skipped.
			if ( strpos( $route, '/wptsall/' ) !== 0 ) {
				continue;
			}

			// WP_REST_Server auto-registers the bare namespace index route
			// (callback get_namespace_index, no permission_callback). It is
			// core-generated, not a product route, and carries no product
			// capability surface. Everything below it must be audited.
			if ( '/wptsall/v2' === $route ) {
				continue;
			}

			foreach ( $endpoints as $endpoint ) {
				$collected[] = array( $route, $endpoint );
			}
		}
		return $collected;
	}

	/**
	 * Every wptsall REST endpoint must declare a real permission_callback.
	 *
	 * Asserts for each endpoint: permission_callback key exists, is callable,
	 * and is not the '__return_true' function (string or otherwise). This is
	 * the executable version of the guide/18 one-off audit.
	 */
	public function test_all_wptsall_routes_have_real_permission_callbacks() {
		$endpoints = $this->collect_wptsall_endpoints();

		$this->assertNotEmpty( $endpoints, 'No wptsall REST routes were registered — rest_api_init did not run or plugin is inactive' );

		$violations = array();

		foreach ( $endpoints as $pair ) {
			list( $route, $endpoint ) = $pair;

			if ( ! isset( $endpoint['permission_callback'] ) ) {
				$violations[] = "{$route}: permission_callback missing";
				continue;
			}

			$callback = $endpoint['permission_callback'];

			if ( '__return_true' === $callback ) {
				$violations[] = "{$route}: permission_callback is __return_true";
				continue;
			}

			if ( ! is_callable( $callback, false, $callable_name ) ) {
				$violations[] = "{$route}: permission_callback not callable (" . var_export( $callback, true ) . ')';
				continue;
			}

			// A closure named {closure} would also be callable, but the
			// product registers methods as array( $this, 'method' );
			// anything else is worth flagging for review.
			if ( $callback instanceof Closure ) {
				$violations[] = "{$route}: permission_callback is an inline closure";
			}
		}

		$this->assertEquals(
			array(),
			$violations,
			'wptsall REST permission audit violations: ' . implode( '; ', $violations )
		);

		// The guide/18 audit documented 159 routes; with the route-secret
		// client family the live surface is larger. Anything near/below
		// 100 means route registration silently shrank.
		$this->assertGreaterThan(
			100,
			count( $endpoints ),
			'wptsall REST endpoint count dropped to ' . count( $endpoints ) . ' — expected far more than 100'
		);

		// Surface the audited counts for the run log (pass message).
		$paths = array();
		foreach ( $this->server->get_routes() as $route => $_ ) {
			if ( 0 === strpos( $route, '/wptsall/' ) && '/wptsall/v2' !== $route ) {
				$paths[ $route ] = true;
			}
		}
		echo '  ℹ️  wptsall REST permission audit: ' . count( $paths ) . ' route paths, ' . count( $endpoints ) . " endpoints, all permission_callbacks verified\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * The client route-secret family must be present and audited.
	 *
	 * The route secret is dynamic (43-char base64url stored in the
	 * wptsall_client_route_secret option). Instead of hardcoding it, assert
	 * that at least one /wptsall/v2/{secret}/client/ route was registered
	 * and covered by the audit above.
	 */
	public function test_client_secret_routes_are_registered_and_audited() {
		$secret = '';
		if ( function_exists( 'wptsall_get_client_route_secret' ) ) {
			$secret = (string) wptsall_get_client_route_secret();
		}

		$collected = $this->collect_wptsall_endpoints();
		$client_endpoints = array();
		foreach ( $collected as $pair ) {
			list( $route, $_ ) = $pair;
			if ( false !== strpos( $route, '/client' ) ) {
				$client_endpoints[] = $route;
			}
		}

		$this->assertNotEmpty( $client_endpoints, 'No /{secret}/client routes registered — client namespace family missing from audit' );
		$this->assertGreaterThan( 10, count( $client_endpoints ), 'Client secret route family unexpectedly small: ' . count( $client_endpoints ) );

		if ( '' !== $secret ) {
			foreach ( array_slice( $client_endpoints, 0, 3 ) as $route ) {
				$this->assertStringContainsString( $secret, $route, "Client route must embed the active route secret: {$route}" );
			}
		}
	}

	/**
	 * An editor must be rejected (403) on an admin route, then the admin
	 * session must be restored and able to access it.
	 */
	public function test_admin_route_rejects_editor_and_restores_admin() {
		$editor_id = $this->factory->user->create( array(
			'role' => 'editor',
		) );

		try {
			// Editor has no manage_wptsall_* capability → 403.
			wp_set_current_user( $editor_id );

			$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations' );
			$response = $this->server->dispatch( $request );

			$this->assertEquals(
				403,
				$response->get_status(),
				'Editor (no manage_wptsall_* caps) must get HTTP 403 on /wptsall/v2/site-relations, got ' . $response->get_status()
			);
			$this->assertEquals( 'rest_forbidden', $response->get_data()['code'] );
		} finally {
			// Restore the admin session in all paths so later cases in this
			// file (and later files — the runner runs one process) are clean.
			wp_set_current_user( 1 );
		}

		// Positive control: user 1 (administrator, manage_wptsall_* granted
		// at activation) must be able to list site relations.
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/site-relations' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'Admin user 1 must access /wptsall/v2/site-relations after editor rejection, got ' . $response->get_status()
		);
	}
}
