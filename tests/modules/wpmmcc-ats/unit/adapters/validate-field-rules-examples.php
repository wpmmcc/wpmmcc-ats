<?php
/**
 * Offline validate all docs/e2e/examples/wptsall-field-rules.*.example.json
 *
 * Run from repo root:
 *   php tests/modules/wpmmcc-ats/unit/adapters/validate-field-rules-examples.php
 */

// Repo root from tests/modules/wpmmcc-ats/unit/adapters/ (five levels up;
// was four before the tests/ SSOT move, which silently broke the CI docs
// gate step in client-packaging-matrix).
$root = dirname( __DIR__, 5 );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

$examples = glob( $root . '/tests/docs/e2e/examples/wptsall-field-rules.*.example.json' );
if ( ! $examples ) {
	fwrite( STDERR, "No example files found\n" );
	exit( 1 );
}

require_once $root . '/wpmmcc-ats/source/includes/core/class-content-format-registry.php';
require_once $root . '/wpmmcc-ats/source/includes/models/adapters/class-field-rules-document-validator.php';

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

use WPTSALL\Models\Adapters\Field_Rules_Document_Validator;

$failed = 0;
foreach ( $examples as $path ) {
	$raw    = file_get_contents( $path );
	$result = Field_Rules_Document_Validator::validate_json( (string) $raw );
	$base   = basename( $path );
	if ( $result['ok'] ) {
		$warn = $result['warnings'] ? ( ' warnings=' . implode( '|', $result['warnings'] ) ) : '';
		echo "OK  {$base}{$warn}\n";
	} else {
		++$failed;
		echo "FAIL {$base}: " . implode( '; ', $result['errors'] ) . "\n";
	}
}

$expected = 11;
$count    = count( $examples );
echo "checked={$count} expected_min={$expected} failed={$failed}\n";
if ( $failed > 0 || $count < $expected ) {
	exit( 1 );
}
echo "ALL_OK\n";
exit( 0 );
