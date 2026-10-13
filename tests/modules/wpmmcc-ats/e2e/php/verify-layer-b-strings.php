<?php
/**
 * Lab smoke: Layer B strings table + site_string REST shape.
 *
 * Usage: wp eval-file verify-layer-b-strings.php --allow-root
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['fail'] = 0;
$GLOBALS['pass'] = 0;

function wptsall_lab_assert( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['pass']++;
		echo "PASS {$label}\n";
	} else {
		$GLOBALS['fail']++;
		echo "FAIL {$label}\n";
	}
}

if ( function_exists( 'wptsall_run_migrations' ) ) {
	wptsall_run_migrations();
}

wptsall_lab_assert(
	class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ),
	'String_Translation_Service exists'
);

$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'strings' ) : $GLOBALS['wpdb']->prefix . 'wptsall_strings';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$exists = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $table ) );
wptsall_lab_assert( $exists === $table, 'wptsall_strings table exists (' . $table . ')' );

if ( class_exists( '\\WPTSALL\\Strings\\Services\\Site_String_Scanner' ) ) {
	$result = \WPTSALL\Strings\Services\Site_String_Scanner::scan_all();
	wptsall_lab_assert( is_array( $result ) && isset( $result['registered'] ), 'Site_String_Scanner::scan_all' );
}

$id = \WPTSALL\Strings\Services\String_Translation_Service::register(
	'site_title',
	'blogname_lab_test',
	get_option( 'blogname' ) ?: 'Test Site'
);
wptsall_lab_assert( $id !== false, 'register string row' );

$list = \WPTSALL\Strings\Services\String_Translation_Service::list_untranslated_for_client(
	'en_US',
	'site',
	1,
	50
);
wptsall_lab_assert( is_array( $list ) && isset( $list['items'] ), 'list_untranslated_for_client' );

if ( ! empty( $list['items'][0]['complete_data']['entry_id'] ) ) {
	wptsall_lab_assert(
		! empty( $list['items'][0]['complete_data']['msgid'] ),
		'site_string item has msgid alias for client'
	);
}

if ( class_exists( '\\WPTSALL\\ManualTranslation\\Services\\Translation_Progress_Service' ) ) {
	$layers = \WPTSALL\ManualTranslation\Services\Translation_Progress_Service::layer_summary();
	wptsall_lab_assert( isset( $layers['layer_b'] ), 'layer_summary layer_b' );
}

echo "\nSUMMARY pass={$GLOBALS['pass']} fail={$GLOBALS['fail']}\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
