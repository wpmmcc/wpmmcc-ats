<?php
/**
 * Client data REST rate-limit security test.
 *
 * Verifies the active Client_Data_REST_Controller contract. The counter is an
 * InnoDB row updated with an atomic upsert; raw device identifiers are never
 * persisted in the counter key.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	$_SERVER['HTTP_HOST']    = 'blog.wpmm.cc';
	$_SERVER['REQUEST_URI']  = '/';
	define( 'WP_USE_THEMES', false );
	define( 'WP_ADMIN', true );
	require '/var/www/wordpress/wp-load.php';
}

$results = array();
$failed  = 0;
$warned  = 0;

function check( $name, $ok, $detail = '' ) {
	global $results, $failed;
	$results[] = compact( 'name', 'ok', 'detail' );
	if ( ! $ok ) {
		++$failed;
		echo 'FAIL: ' . $name . ( $detail ? ' -- ' . $detail : '' ) . "\n";
	} else {
		echo 'PASS: ' . $name . "\n";
	}
}

function warn( $name, $detail = '' ) {
	global $results, $warned;
	$results[] = compact( 'name', 'ok', 'detail' );
	++$warned;
	echo 'WARN: ' . $name . ( $detail ? ' -- ' . $detail : '' ) . "\n";
}

$ctrl_class = '\\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller';
$ctrl_file  = WPTSALL_PATH . 'includes/tasks/api/class-client-data-rest-controller.php';
$ctrl_src   = file_exists( $ctrl_file ) ? file_get_contents( $ctrl_file ) : '';

check(
	'Active Client_Data_REST_Controller class exists',
	class_exists( $ctrl_class ),
	'class=' . $ctrl_class
);
check(
	'Active controller owns check_rate_limit()',
	class_exists( $ctrl_class ) && method_exists( $ctrl_class, 'check_rate_limit' )
);
check(
	'Rate-limit implementation uses the atomic client counter table',
	strpos( $ctrl_src, 'client_rate_limits' ) !== false
		&& strpos( $ctrl_src, 'ON DUPLICATE KEY UPDATE' ) !== false
);
check(
	'Active rate-limit implementation does not use transient read/modify/write',
	false === strpos( $ctrl_src, 'get_transient( $key )' )
		&& false === strpos( $ctrl_src, 'set_transient( $key, $state' )
);
check(
	'Rate-limit response exposes the client contract',
	strpos( $ctrl_src, "'client_rate_limited'" ) !== false
		&& strpos( $ctrl_src, "'Retry-After'" ) !== false
		&& strpos( $ctrl_src, "'X-RateLimit-Remaining'" ) !== false
);

if ( class_exists( $ctrl_class ) ) {
	$controller = new $ctrl_class();
	$reflection = new ReflectionMethod( $controller, 'check_rate_limit' );
	$reflection->setAccessible( true );

	$old_device = isset( $_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] )
		? $_SERVER['HTTP_X_WPTSALL_DEVICE_ID']
		: null;
	$_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] = 'rate-limit-security-test-device';
	$filter = function ( $limits, $bucket ) {
		if ( 'security_test' === $bucket ) {
			$limits['security_test'] = array( 'limit' => 1, 'window' => 60 );
		}
		return $limits;
	};
	add_filter( 'wptsall_client_data_rate_limits', $filter, 10, 2 );
	try {
		$first = $reflection->invoke( $controller, 'security_test' );
		$second = $reflection->invoke( $controller, 'security_test' );
		check( 'First request in a fresh bucket is allowed', null === $first );
		check(
			'Second request over the configured limit returns 429',
			$second instanceof WP_REST_Response && 429 === $second->get_status()
		);
		if ( $second instanceof WP_REST_Response ) {
			check( '429 response includes Retry-After', '' !== (string) $second->get_headers()['Retry-After'] );
		}
	} catch ( Throwable $e ) {
		check( 'Atomic rate-limit counter can be exercised', false, $e->getMessage() );
	} finally {
		remove_filter( 'wptsall_client_data_rate_limits', $filter, 10 );
		if ( null === $old_device ) {
			unset( $_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] );
		} else {
			$_SERVER['HTTP_X_WPTSALL_DEVICE_ID'] = $old_device;
		}
	}
}

// A route smoke is intentionally tolerant of auth/route-secret responses; the
// assertion is that the active REST endpoint never produces a PHP fatal.
$rest_url = rest_url( 'wptsall/v2/client/content' );
if ( function_exists( 'curl_init' ) ) {
	$ch = curl_init( $rest_url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_USERAGENT      => 'wptsall-rate-test/2.1',
		)
	);
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	$is_error_response = 401 === $code || 403 === $code || 404 === $code || 405 === $code || 429 === $code || $code >= 500;
	$body_has_fatal    = is_string( $body ) && ( false !== strpos( $body, 'Fatal error' ) || false !== strpos( $body, 'Uncaught Error' ) );
	check(
		'Active /v2/client/content endpoint returns no PHP fatal',
		$is_error_response && ! $body_has_fatal,
		'code=' . $code . ' has_fatal=' . ( $body_has_fatal ? '1' : '0' )
	);
} else {
	warn( 'Route smoke skipped because curl is unavailable' );
}

echo "\n=== Client Data REST Rate Limit Security Test ===\n";
echo 'Passed: ' . ( count( $results ) - $failed - $warned ) . ' / ' . count( $results ) . "\n";
echo 'Warned: ' . $warned . "\n";
echo 'Failed: ' . $failed . "\n";
exit( $failed > 0 ? 1 : 0 );
