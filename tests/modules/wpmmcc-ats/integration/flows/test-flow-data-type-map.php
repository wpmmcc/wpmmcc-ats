<?php
/**
 * Content Data Type ↔ Kind Mapping Test
 *
 * Verifies the Content_Data_Type_Map class: data_types × kinds with
 * alias normalization (post_type → post, taxonomy → term).
 * validate() returns null on invalid pairs, describe() for admin UI.
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
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $results, $failed;
    $results[] = compact('name', 'ok', 'detail');
    if ( ! $ok ) {
        $failed++;
        echo "FAIL: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
    } else {
        echo "PASS: {$name}\n";
    }
}

$cls = '\\WPTSALL\\Core\\Content_Data_Type_Map';

// Check 1: class loads
check(
    'Content_Data_Type_Map class exists',
    class_exists( $cls )
);

// Check 2: DATA_TYPES constant
check(
    'DATA_TYPES includes post, term, language_pack, custom_table',
    in_array( 'post', $cls::DATA_TYPES, true ) &&
    in_array( 'term', $cls::DATA_TYPES, true ) &&
    in_array( 'language_pack', $cls::DATA_TYPES, true ) &&
    in_array( 'custom_table', $cls::DATA_TYPES, true )
);

// Check 3: KINDS constant includes expected values
check(
    'KINDS includes text, image, video_translation, document_translation, openai_compatible',
    in_array( 'text', $cls::KINDS, true ) &&
    in_array( 'image', $cls::KINDS, true ) &&
    in_array( 'video_translation', $cls::KINDS, true ) &&
    in_array( 'document_translation', $cls::KINDS, true ) &&
    in_array( 'openai_compatible', $cls::KINDS, true )
);

// Check 4: default kind for post is text
check(
    "default_kind('post') returns 'text'",
    $cls::default_kind( 'post' ) === 'text'
);

// Check 5: default kind for term is text
check(
    "default_kind('term') returns 'text'",
    $cls::default_kind( 'term' ) === 'text'
);

// Check 6: default kind for language_pack is text
check(
    "default_kind('language_pack') returns 'text'",
    $cls::default_kind( 'language_pack' ) === 'text'
);

// Check 7: default kind for custom_table is text
check(
    "default_kind('custom_table') returns 'text'",
    $cls::default_kind( 'custom_table' ) === 'text'
);

// Check 8: default kind for unknown is text (fallback)
check(
    "default_kind('unknown') returns 'text' (fallback)",
    $cls::default_kind( 'unknown' ) === 'text'
);

// Check 9: data_types_for_kind('openai_compatible') returns all 4
$oai_types = $cls::data_types_for_kind( 'openai_compatible' );
check(
    "data_types_for_kind('openai_compatible') returns all 4 data_types",
    count( $oai_types ) === 4,
    'count=' . count( $oai_types ) . ' types=' . implode( ',', $oai_types )
);

// Check 10: data_types_for_kind('video_translation') is restricted to post
$vt_types = $cls::data_types_for_kind( 'video_translation' );
check(
    "data_types_for_kind('video_translation') restricted to ['post']",
    $vt_types === array( 'post' )
);

// Check 11: data_types_for_kind('text') returns all 4
$text_types = $cls::data_types_for_kind( 'text' );
check(
    "data_types_for_kind('text') returns all 4 data_types",
    count( $text_types ) === 4
);

// Check 12: is_kind_supported('post', 'text') is true
check(
    "is_kind_supported('post', 'text') is true",
    $cls::is_kind_supported( 'post', 'text' )
);

// Check 13: is_kind_supported('term', 'video_translation') is false
check(
    "is_kind_supported('term', 'video_translation') is false (terms can't be videos)",
    ! $cls::is_kind_supported( 'term', 'video_translation' )
);

// Check 14: validate('post', '') returns ['data_type'=>'post', 'kind'=>'text']
$v1 = $cls::validate( 'post', '' );
check(
    "validate('post', '') defaults kind to 'text'",
    is_array( $v1 ) && $v1['data_type'] === 'post' && $v1['kind'] === 'text',
    'result=' . json_encode( $v1 )
);

// Check 15: validate('post_type', '') normalizes alias to 'post'
$v2 = $cls::validate( 'post_type', '' );
check(
    "validate('post_type', '') normalizes alias to 'post'",
    is_array( $v2 ) && $v2['data_type'] === 'post'
);

// Check 16: validate('taxonomy', '') normalizes to 'term'
$v3 = $cls::validate( 'taxonomy', '' );
check(
    "validate('taxonomy', '') normalizes to 'term'",
    is_array( $v3 ) && $v3['data_type'] === 'term'
);

// Check 17: validate('post', 'text_translation') accepts
$v4 = $cls::validate( 'post', 'text_translation' );
check(
    "validate('post', 'text_translation') is accepted",
    is_array( $v4 ) && $v4['data_type'] === 'post' && $v4['kind'] === 'text_translation'
);

// Check 18: validate('term', 'video_translation') rejects (term doesn't support video)
$v5 = $cls::validate( 'term', 'video_translation' );
check(
    "validate('term', 'video_translation') is rejected (incompatible)",
    $v5 === null
);

// Check 19: validate('garbage', '') returns null
$v6 = $cls::validate( 'garbage', '' );
check(
    "validate('garbage', '') returns null",
    $v6 === null
);

// Check 20: validate('post', 'nonexistent_kind') returns null
$v7 = $cls::validate( 'post', 'nonexistent_kind' );
check(
    "validate('post', 'nonexistent_kind') returns null",
    $v7 === null
);

// Check 21: describe() returns array with 4 entries
$desc = $cls::describe();
check(
    "describe() returns 4 data_types",
    is_array( $desc ) && count( $desc ) === 4
);

// Check 22: describe() for 'post' includes default_kind and allowed_kinds
$desc_post = $desc['post'] ?? array();
check(
    "describe()['post'] has default_kind='text' and allowed_kinds includes 'image'",
    isset( $desc_post['default_kind'] ) && $desc_post['default_kind'] === 'text' &&
    in_array( 'image', $desc_post['allowed_kinds'], true )
);

echo "\n=== Content Data Type Map Test ===\n";
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