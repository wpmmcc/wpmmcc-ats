<?php
/**
 * Canonical unit runner entrypoint.
 *
 * This keeps `wptsall/tests/unit/` as a compatibility entrypoint while the
 * canonical runner implementation lives in the sibling `tests/unit/` tree.
 *
 * @package WPTSALL\Tests\Unit
 */

$runner = dirname( __DIR__ ) . '/unit/run.php';

if ( ! file_exists( $runner ) ) {
	fwrite( STDERR, "Unit runner not found: {$runner}\n" );
	exit( 1 );
}

require $runner;
