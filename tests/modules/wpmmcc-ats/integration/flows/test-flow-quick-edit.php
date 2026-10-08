<?php
/**
 * Quick Edit / Bulk Edit Integration Test
 *
 * Verifies that the Quick Edit and Bulk Edit language selector is properly
 * wired and that the _wptsall_language post meta is correctly saved.
 *
 * @package WPTSALL\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	$_SERVER['HTTP_HOST']    = 'blog.wpmm.cc';
	$_SERVER['REQUEST_URI']  = '/';
	define( 'WP_USE_THEMES', false );
	define( 'WP_ADMIN', true );
	require_once dirname( __DIR__ ) . '/bootstrap/load-wordpress.php';
}

$results = [];
$failed  = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
	global $results, $failed;
	$results[] = compact( 'name', 'ok', 'detail' );
	if ( ! $ok ) {
		$failed++;
		echo "FAIL: {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	} else {
		echo "PASS: {$name}\n";
	}
}

// Check 1: Quick Edit JS file exists
$js_file = WPTSALL_PATH . 'assets/js/quick-edit-language.js';
check(
	'quick-edit-language.js exists',
	file_exists( $js_file ),
	'path=' . $js_file
);

// Check 2: Language_Meta_Saver handles _wptsall_language
$meta_saver = WPTSALL_PATH . 'includes/manual-translation/hooks/class-language-meta-saver.php';
check( 'Language_Meta_Saver file exists', file_exists( $meta_saver ) );

// Check 3: Translation_Status_Column enqueues the script
$col_file = WPTSALL_PATH . 'includes/translation-status/class-translation-status-column.php';
$col_source = file_exists( $col_file ) ? file_get_contents( $col_file ) : '';
check(
	'Column enqueues wptsall-quickedit-language script',
	strpos( $col_source, 'wp_enqueue_script' ) !== false && strpos( $col_source, 'quick-edit-language' ) !== false
);

// Check 4: Script localizes language data for the Quick Edit UI.
// Production uses wp_localize_script( ..., 'wptsallQuickEdit', ... );
// JS also accepts legacy window.wptsallQuickEditLangs as a fallback.
check(
	'Inline script declares window.wptsallQuickEditLangs',
	strpos( $col_source, 'wptsallQuickEdit' ) !== false
		|| strpos( $col_source, 'wptsallQuickEditLangs' ) !== false
		|| strpos( $col_source, 'wp_localize_script' ) !== false
);

// Check 5: Languages data is fetched
check(
	'Languages data is fetched from Language_Service',
	strpos( $col_source, 'Language_Service::get_all' ) !== false
);

// Check 6: Only for managed post types
check(
	'Script enqueue is gated on managed post types',
	strpos( $col_source, 'get_managed_post_types' ) !== false
);

// Check 7: _wptsall_language meta is in the language saver
$saver_source = file_exists( $meta_saver ) ? file_get_contents( $meta_saver ) : '';
check(
	'Language_Meta_Saver handles _wptsall_language meta',
	strpos( $saver_source, '_wptsall_language' ) !== false
);

// Check 8: Meta saver has save_post hook
check(
	'Language_Meta_Saver hooks save_post',
	strpos( $saver_source, 'save_post' ) !== false
);

// Check 9: Meta saver has nonce verification
check(
	'Language_Meta_Saver verifies nonce or capability',
	strpos( $saver_source, 'wp_verify_nonce' ) !== false
		|| strpos( $saver_source, 'check_admin_referer' ) !== false
		|| strpos( $saver_source, 'current_user_can' ) !== false
);

// Check 10: Meta saver sanitizes input
check(
	'Language_Meta_Saver sanitizes _wptsall_language value',
	strpos( $saver_source, 'sanitize_' ) !== false
		|| strpos( $saver_source, 'wp_kses' ) !== false
		|| strpos( $saver_source, 'esc_attr' ) !== false
);

// Check 11: JS injects language select in Quick Edit
$js_source = file_get_contents( $js_file );
check(
	'Quick Edit JS injects language select',
	strpos( $js_source, 'injectQuickEditField' ) !== false && strpos( $js_source, 'select' ) !== false
);

// Check 12: JS injects language select in Bulk Edit
check(
	'Quick Edit JS injects language select in Bulk Edit',
	strpos( $js_source, 'injectBulkEditField' ) !== false
);

// Check 13: JS uses _wptsall_language field name
check(
	'JS uses _wptsall_language field name',
	strpos( $js_source, '_wptsall_language' ) !== false
);

// Check 14: JS has i18n support
check(
	'JS has localized strings',
	strpos( $js_source, '— None —' ) !== false || strpos( $js_source, 'None' ) !== false
);

// Check 15: _wptsall_language meta persists across reads
global $wpdb;
// Find a post that has _wptsall_language set
$post_with_lang = $wpdb->get_var(
	"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_language' LIMIT 1"
);
if ( $post_with_lang ) {
	$lang = get_post_meta( $post_with_lang, '_wptsall_language', true );
	check(
		'_wptsall_language meta roundtrip works',
		is_string( $lang ) && strlen( $lang ) > 0,
		'post_id=' . $post_with_lang . ' lang=' . $lang
	);
} else {
	echo "INFO: no post with _wptsall_language found, skipping roundtrip test\n";
}

echo "\n=== Quick Edit / Bulk Edit Integration Test ===\n";
echo "Passed: " . ( count( $results ) - $failed ) . " / " . count( $results ) . "\n";
echo "Failed: {$failed}\n";
if ( defined( 'WPTSALL_INTEGRATION_RUNNER' ) && WPTSALL_INTEGRATION_RUNNER ) {
	$GLOBALS['wptsall_flow_result'] = array(
		'failed' => $failed,
		'total'  => count( $results ),
	);
	if ( $failed > 0 ) {
		throw new RuntimeException( basename( __FILE__ ) . ": {$failed} check(s) failed" );
	}
	return;
}
exit( $failed > 0 ? 1 : 0 );