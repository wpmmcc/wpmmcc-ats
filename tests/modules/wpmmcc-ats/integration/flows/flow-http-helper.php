<?php
/**
 * Shared HTTP helpers for integration flow scripts.
 *
 * Uses loopback requests with Host header so flows work when the public
 * site URL is not reachable from the test runner (e.g. https://blog.wpmm.cc).
 *
 * @package WPTSALL\Tests
 */

if ( ! function_exists( 'wptsall_flow_local_http_request' ) ) {
	/**
	 * Perform an HTTP request against the local web server.
	 *
	 * @param string $url  Full site URL (uses path/query from this URL).
	 * @param array  $args wp_remote_get/wp_remote_head args.
	 * @return array|\WP_Error
	 */
	function wptsall_flow_local_http_request( string $url, array $args = array() ) {
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
		// Preserve non-default ports in the Host header so WordPress canonical
		// redirects (home_url includes :9081/:9083 in lab) do not loop.
		if ( ! empty( $parsed['port'] ) ) {
			$host .= ':' . (int) $parsed['port'];
		} elseif ( is_string( $host ) && false === strpos( $host, ':' ) ) {
			$home_port = (int) ( wp_parse_url( home_url(), PHP_URL_PORT ) ?? 0 );
			if ( $home_port > 0 && $home_port !== 80 && $home_port !== 443 ) {
				$home_host = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' );
				if ( '' !== $home_host && strtolower( $host ) === strtolower( $home_host ) ) {
					$host .= ':' . $home_port;
				}
			}
		}
		$path = (string) ( $parsed['path'] ?? '/' );

		if ( ! empty( $parsed['query'] ) ) {
			$path .= '?' . $parsed['query'];
		}

		// Loopback port: the lab containers serve Apache on container-local
		// port 80 while home_url() carries the host-published port (:9181),
		// so the transport target is always plain :80 there. Host mode
		// (module-ci runner) serves WP via php -S on an arbitrary port —
		// WPTSALL_TEST_LOCAL_HTTP_PORT tells the helper which loopback port
		// actually answers; unset keeps the lab default (80). The 2026-09-09
		// runner run failed every rss/redirect-loop fetch with transport
		// code=0 because the hardcoded :80 had no listener.
		$local_port = (int) ( getenv( 'WPTSALL_TEST_LOCAL_HTTP_PORT' ) ?: 80 );
		$local_url  = 'http://127.0.0.1' . ( 80 === $local_port ? '' : ':' . $local_port ) . $path;
		$defaults  = array(
			'timeout'     => 15,
			'sslverify'   => false,
			'redirection' => 0,
			'headers'     => array(
				'Host' => $host,
			),
		);

		$request_args = array_merge( $defaults, $args );
		$method       = ! empty( $args['method'] ) && 'HEAD' === strtoupper( (string) $args['method'] )
			? 'wp_remote_head'
			: 'wp_remote_get';

		unset( $request_args['method'] );

		return call_user_func( $method, $local_url, $request_args );
	}
}

if ( ! function_exists( 'wptsall_flow_fetch_url' ) ) {
	/**
	 * Fetch a URL and return body metadata.
	 *
	 * @param string $url Request URL.
	 * @return array{code:int,body:string,content_type:string}
	 */
	function wptsall_flow_fetch_url( string $url ): array {
		$response = wptsall_flow_local_http_request(
			$url,
			array(
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'         => 0,
				'body'         => '',
				'content_type' => '',
			);
		}

		return array(
			'code'         => (int) wp_remote_retrieve_response_code( $response ),
			'body'         => (string) wp_remote_retrieve_body( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}
}

if ( ! function_exists( 'wptsall_flow_fetch_with_redirects' ) ) {
	/**
	 * Fetch a URL following redirects manually to count hops.
	 *
	 * @param string $url        Request URL.
	 * @param int    $max_redirs Maximum redirects.
	 * @return array{code:int,body:string,num_redirects:int,url:string}
	 */
	function wptsall_flow_fetch_with_redirects( string $url, int $max_redirs = 5 ): array {
		$current     = $url;
		$redirects   = 0;
		$final_body  = '';
		$final_code  = 0;
		$final_url   = $url;

		while ( $redirects <= $max_redirs ) {
			$response = wptsall_flow_local_http_request( $current, array( 'redirection' => 0 ) );
			if ( is_wp_error( $response ) ) {
				return array(
					'code'          => 0,
					'body'          => '',
					'num_redirects' => $redirects,
					'url'           => $final_url,
				);
			}

			$final_code = (int) wp_remote_retrieve_response_code( $response );
			$final_body = (string) wp_remote_retrieve_body( $response );
			$final_url  = $current;

			if ( $final_code < 300 || $final_code >= 400 ) {
				break;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( empty( $location ) ) {
				break;
			}

			$current = wp_http_validate_url( $location ) ? $location : wp_make_link_relative( $location );
			if ( ! wp_http_validate_url( $current ) ) {
				$parsed = wp_parse_url( $url );
				$scheme = $parsed['scheme'] ?? 'http';
				$host   = $parsed['host'] ?? ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
				$current = $scheme . '://' . $host . '/' . ltrim( (string) $current, '/' );
			}

			++$redirects;
		}

		return array(
			'code'          => $final_code,
			'body'          => $final_body,
			'num_redirects' => $redirects,
			'url'           => $final_url,
		);
	}
}

if ( ! function_exists( 'wptsall_flow_fetch_headers' ) ) {
	/**
	 * Fetch response headers without following redirects.
	 *
	 * @param string $url Request URL.
	 * @return array{code:int,headers:array<string,string>}
	 */
	function wptsall_flow_fetch_headers( string $url ): array {
		$response = wptsall_flow_local_http_request(
			$url,
			array(
				'method' => 'HEAD',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => 0,
				'headers' => array(),
			);
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $key => $value ) {
			$headers[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
		);
	}
}
