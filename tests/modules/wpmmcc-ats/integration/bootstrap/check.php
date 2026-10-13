<?php
/**
 * Minimal assert helper for procedural integration flow scripts.
 *
 * Used by license / token-rotation style flows that are not PHPUnit classes.
 *
 * @package WPTSALL
 */

if ( ! function_exists( 'check' ) ) {
	/**
	 * Print a pass/fail line and track failures in $GLOBALS['wptsall_check_failures'].
	 *
	 * @param string   $label Assertion label.
	 * @param bool     $ok    Whether the assertion passed.
	 * @param string   $detail Optional detail on failure.
	 * @return void
	 */
	function check( $label, $ok, $detail = '' ) {
		if ( ! isset( $GLOBALS['wptsall_check_failures'] ) || ! is_array( $GLOBALS['wptsall_check_failures'] ) ) {
			$GLOBALS['wptsall_check_failures'] = array();
		}
		if ( $ok ) {
			echo "\033[0;32m  ✓ {$label}\033[0m\n";
			return;
		}
		$GLOBALS['wptsall_check_failures'][] = $label;
		$suffix = '' !== (string) $detail ? " — {$detail}" : '';
		echo "\033[0;31m  ✗ {$label}{$suffix}\033[0m\n";
	}
}
