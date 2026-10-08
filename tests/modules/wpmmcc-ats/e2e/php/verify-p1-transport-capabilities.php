<?php
/**
 * Local verify: transport policy, contract capabilities, adapter manifest.
 *
 * Usage (docker lab):
 *   docker cp this file into container OR mount tests, then:
 *   php verify-p1-transport-capabilities.php
 *
 * Env: WPTSALL_WP_ROOT (default /var/www/html)
 *      WPTSALL_PLUGIN_DIR (default .../plugins/wpmmcc-ats)
 */

$wp_root = rtrim( getenv( 'WPTSALL_WP_ROOT' ) ?: '/var/www/html', '/' );
$plugin_dir = rtrim(
	getenv( 'WPTSALL_PLUGIN_DIR' ) ?: ( $wp_root . '/wp-content/plugins/wpmmcc-ats' ),
	'/'
);

if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "WP not found at {$wp_root}\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? '192.168.1.14:9081';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? '192.168.1.14';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
define( 'WP_USE_THEMES', false );
require_once $wp_root . '/wp-load.php';

if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Prefer WordPress.org bootstrap (valid header); legacy wptsall.php is a stub.
$main = $plugin_dir . '/wpmmcc-ats.php';
if ( ! file_exists( $main ) ) {
	$main = $plugin_dir . '/wptsall.php';
}
if ( ! file_exists( $main ) ) {
	fwrite( STDERR, "Plugin bootstrap not found under {$plugin_dir}\n" );
	exit( 1 );
}

$relative = ltrim( str_replace( $wp_root . '/wp-content/plugins/', '', $main ), '/' );
if ( ! is_plugin_active( $relative ) ) {
	$activated = activate_plugin( $relative, '', false, true );
	if ( is_wp_error( $activated ) ) {
		fwrite( STDERR, 'Activate failed: ' . $activated->get_error_message() . "\n" );
		exit( 1 );
	}
}

// Same-request activate may register classes via autoload before procedural helpers.
if ( ! function_exists( 'wptsall_get_supported_contract_capabilities' )
	&& file_exists( $plugin_dir . '/includes/core/functions.php' ) ) {
	require_once $plugin_dir . '/includes/core/functions.php';
}

$failures = 0;
$pass     = function ( $name ) {
	echo "[PASS] {$name}\n";
};
$fail     = function ( $name, $detail = '' ) use ( &$failures ) {
	++$failures;
	echo "[FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
};

// --- capabilities ---
if ( ! function_exists( 'wptsall_get_supported_contract_capabilities' ) ) {
	$fail( 'wptsall_get_supported_contract_capabilities exists' );
} else {
	$caps = wptsall_get_supported_contract_capabilities();
	if ( ( $caps['wp_client_protocol'] ?? null ) === 2
		&& ( $caps['workflow_dsl'] ?? null ) === 'workflow-dsl-v1' ) {
		$pass( 'supported contract capabilities axes' );
	} else {
		$fail( 'supported contract capabilities axes', wp_json_encode( $caps ) );
	}
}

if ( function_exists( 'wptsall_check_client_contract_capabilities' ) ) {
	$req = new WP_REST_Request( 'GET', '/wptsall/v2/x/client/tasks' );
	$ok  = wptsall_check_client_contract_capabilities( $req );
	true === $ok ? $pass( 'missing capabilities header allowed' ) : $fail( 'missing capabilities header allowed' );

	$req2 = new WP_REST_Request( 'GET', '/wptsall/v2/x/client/tasks' );
	$req2->set_header(
		'X-WPTSALL-Contract-Capabilities',
		wp_json_encode( array( 'wp_client_protocol' => 1 ) )
	);
	$bad = wptsall_check_client_contract_capabilities( $req2 );
	( is_wp_error( $bad ) && 'unsupported_contract_capability' === $bad->get_error_code() )
		? $pass( 'mismatched protocol rejected' )
		: $fail( 'mismatched protocol rejected' );
} else {
	$fail( 'wptsall_check_client_contract_capabilities exists' );
}

// --- transport policy ---
if ( class_exists( '\\WPTSALL\\Core\\Transport_Middleware' ) ) {
	$method = new ReflectionMethod( '\\WPTSALL\\Core\\Transport_Middleware', 'needs_encryption' );
	$method->setAccessible( true );
	remove_all_filters( 'wptsall_client_transport_encryption_policy' );
	remove_all_filters( 'wptsall_client_transport_encryption_required' );

	add_filter(
		'wptsall_client_transport_encryption_policy',
		static function () {
			return 'off';
		}
	);
	false === $method->invoke( null )
		? $pass( 'transport policy=off' )
		: $fail( 'transport policy=off' );

	remove_all_filters( 'wptsall_client_transport_encryption_policy' );
	add_filter(
		'wptsall_client_transport_encryption_policy',
		static function () {
			return 'always';
		}
	);
	true === $method->invoke( null )
		? $pass( 'transport policy=always' )
		: $fail( 'transport policy=always' );

	remove_all_filters( 'wptsall_client_transport_encryption_policy' );
	remove_all_filters( 'wptsall_client_transport_encryption_required' );

	// Default https_optional on non-SSL (docker lab HTTP) => encryption required.
	$default = $method->invoke( null );
	( true === $default )
		? $pass( 'default https_optional requires encrypt on HTTP' )
		: $fail( 'default https_optional requires encrypt on HTTP', var_export( $default, true ) );
} else {
	$fail( 'Transport_Middleware class' );
}

// --- adapter manifest ---
if ( class_exists( '\\WPTSALL\\Models\\Adapters\\Adapter_Manifest' ) ) {
	$all = \WPTSALL\Models\Adapters\Adapter_Manifest::all();
	if ( isset( $all['elementor'] )
		&& in_array( 'json_structured', $all['elementor']['content_formats'], true )
		&& 2 === (int) $all['elementor']['min_wp_client_protocol'] ) {
		$pass( 'adapter manifest elementor formats' );
	} else {
		$fail( 'adapter manifest elementor formats', wp_json_encode( $all['elementor'] ?? null ) );
	}
} else {
	$fail( 'Adapter_Manifest class' );
}

echo $failures === 0 ? "\nALL PASSED\n" : "\nFAILED={$failures}\n";
exit( $failures === 0 ? 0 : 1 );
