<?php
/**
 * Report recorded outbound attempts from the manual-isolation watchdog.
 *
 * Runs inside WP via wp-cli eval-file. Prints a JSON document.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$attempts = get_option( 'wptsall_mi_http_attempts', array() );
if ( ! is_array( $attempts ) ) {
	$attempts = array();
}

// Strip the option so the report payload stays small and secret-free.
echo json_encode(
	array(
		'attempts'   => $attempts,
		'count'      => count( $attempts ),
		'window_end' => gmdate( 'c' ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
