<?php
/**
 * E2E v2 验证翻译规则
 *
 * 检查扫描模型、规则结构和 content_format 覆盖。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-rules.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Verify Translation Rules ===\n\n";

$models_table = e2e_table( 'models' );
$rules_table  = e2e_table( 'translation_rules' );

if ( ! e2e_table_exists( $models_table ) || ! e2e_table_exists( $rules_table ) ) {
	echo "ERROR: Models or rules table not found.\n";
	exit( 1 );
}

$passed = 0;
$failed = 0;
$checks = array();

// ---------------------------------------------------------------------------
// Step 1: 检查内容型插件有 model
// ---------------------------------------------------------------------------
echo "--- Step 1: Models per plugin ---\n";

$plugins = e2e_model_plugin_slugs();

foreach ( $plugins as $slug ) {
	// 特殊处理: wptsall 使用 wordpress-blog model，core 内容
	if ( $slug === 'wptsall' ) {
		$model = $wpdb->get_row( "SELECT id, plugin_slug FROM $models_table WHERE plugin_slug = 'wordpress-blog' AND status = 'active'" );
	} else {
		$model = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, plugin_slug FROM $models_table WHERE plugin_slug = %s AND status = 'active'",
			$slug
		) );
	}

	if ( $model ) {
		e2e_check(
			"Model: $slug",
			true,
			"ID={$model->id}",
			$checks, $passed, $failed
		);
	} else {
		// 一些插件可能通过不同 slug 注册
		$like_model = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, plugin_slug FROM $models_table WHERE plugin_slug LIKE %s AND status = 'active'",
			'%' . $wpdb->esc_like( $slug ) . '%'
		) );

		if ( $like_model ) {
			e2e_check(
				"Model: $slug",
				true,
				"ID={$like_model->id} (as {$like_model->plugin_slug})",
				$checks, $passed, $failed
			);
		} else {
			e2e_check(
				"Model: $slug",
				false,
				"not found",
				$checks, $passed, $failed
			);
		}
	}
}

// ---------------------------------------------------------------------------
// Step 1B: 字段增强型插件覆盖（不强制独立 model）
// ---------------------------------------------------------------------------
echo "\n--- Step 1B: Meta-only plugin coverage ---\n";

$meta_expectations = array(
	'wordpress-seo' => array(
		'like'  => '%_yoast_wpseo_%',
		'label' => 'Yoast meta fields in rules',
	),
	'advanced-custom-fields' => array(
		'like'  => '%\"brand\"%',
		'label' => 'ACF-like custom fields in rules',
	),
);

foreach ( e2e_meta_only_plugin_slugs() as $slug ) {
	$expect = $meta_expectations[ $slug ] ?? null;
	if ( ! is_array( $expect ) ) {
		continue;
	}

	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $rules_table
			 WHERE is_active = 1
			   AND field_capabilities LIKE %s",
			(string) $expect['like']
		)
	);

	e2e_check(
		"Meta coverage: $slug",
		$count > 0,
		$count > 0 ? "{$expect['label']} ({$count} rules)" : "{$expect['label']} missing",
		$checks, $passed, $failed
	);
}

// ---------------------------------------------------------------------------
// Step 2: 检查翻译规则数量
// ---------------------------------------------------------------------------
echo "\n--- Step 2: Translation rules ---\n";

$total_rules = e2e_table_count( $rules_table );
e2e_check(
	'Total rules',
	$total_rules > 0,
	"$total_rules rules",
	$checks, $passed, $failed
);

// ---------------------------------------------------------------------------
// Step 3: 检查 content_format 覆盖
// ---------------------------------------------------------------------------
echo "\n--- Step 3: Content format coverage ---\n";

$rows = $wpdb->get_results(
	"SELECT id, object_name, field_capabilities FROM $rules_table
	 WHERE is_active = 1
	   AND field_capabilities IS NOT NULL
	   AND field_capabilities != ''
	   AND field_capabilities != '{}'",
	ARRAY_A
);

$format_counts = array();
$translate_media_ref_fields = 0;
$translate_media_task_types = array();
foreach ( (array) $rows as $row ) {
	$caps = $row['field_capabilities'];
	if ( is_string( $caps ) ) {
		$caps = json_decode( $caps, true );
	}
	if ( ! is_array( $caps ) ) {
		continue;
	}

	foreach ( $caps as $cap ) {
		if ( ! is_array( $cap ) ) {
			continue;
		}
		$format = sanitize_key( (string) ( $cap['content_format'] ?? '' ) );
		if ( '' === $format ) {
			continue;
		}
		$format_counts[ $format ] = (int) ( $format_counts[ $format ] ?? 0 ) + 1;

		$cap_type = sanitize_key( (string) ( $cap['type'] ?? '' ) );
		if ( 'media_ref' === $format && 'translate' === $cap_type ) {
			$translate_media_ref_fields++;
			$task_type = sanitize_key( (string) ( $cap['task_type'] ?? '' ) );
			if ( '' !== $task_type ) {
				$translate_media_task_types[ $task_type ] = true;
			}
		}
	}
}

if ( empty( $format_counts ) ) {
	e2e_check(
		'Content format extraction',
		false,
		'No content_format found in field_capabilities',
		$checks, $passed, $failed
	);
}

ksort( $format_counts );
$found_formats = array_keys( $format_counts );
foreach ( $format_counts as $format => $count ) {
	echo "  {$format}: {$count} fields\n";
}

// 必需格式：E2E v2 主链路最小覆盖。
// core-only：仅要求 core 文本类格式；serialized_php / media_ref 留给 full / 插件 project。
$core_only = function_exists( 'e2e_is_core_only' ) && e2e_is_core_only();
$required_formats = $core_only
	? array( 'plain_text', 'rich_html', 'json_structured' )
	: array( 'plain_text', 'rich_html', 'serialized_php', 'json_structured' );
foreach ( $required_formats as $ef ) {
	e2e_check(
		"Format: $ef",
		in_array( $ef, $found_formats, true ),
		in_array( $ef, $found_formats, true ) ? 'present' : 'MISSING',
		$checks, $passed, $failed
	);
}
if ( $core_only && ! in_array( 'serialized_php', $found_formats, true ) ) {
	echo "  INFO: core-only: Format serialized_php hard gate skipped (informational).\n";
}

// 推荐格式：用于增强覆盖率可见性（不阻断）。
$recommended_formats = array( 'media_ref', 'slug', 'code' );
foreach ( $recommended_formats as $sf ) {
	if ( ! in_array( $sf, $found_formats, true ) ) {
		echo "  INFO: $sf not present in current field_capabilities\n";
	}
}

if ( $core_only ) {
	echo "  INFO: core-only: media_ref / media task_type hard gates skipped (informational).\n";
	echo '  INFO: media_ref count=' . (int) ( $format_counts['media_ref'] ?? 0 )
		. ', translate_media_ref=' . (int) $translate_media_ref_fields . "\n";
} else {
	// Lane-scoped matrix scans cover only this lane's own slugs, so each lane
	// yields the standard 4 media_ref rules (image/video/audio/document task
	// types). The >=5 threshold is a full-scan expectation (main environment
	// sees 19); lane-scoped lanes require the full 4-type media coverage.
	$media_ref_min = ( function_exists( 'e2e_is_matrix_parallel_lane' ) && e2e_is_matrix_parallel_lane() ) ? 4 : 5;
	e2e_check(
		'Format: media_ref (rich coverage)',
		(int) ( $format_counts['media_ref'] ?? 0 ) >= $media_ref_min,
		'count=' . (int) ( $format_counts['media_ref'] ?? 0 ) . ', expected>=' . $media_ref_min,
		$checks, $passed, $failed
	);
	e2e_check(
		'Translate media_ref fields',
		$translate_media_ref_fields >= 4,
		'translate_media_ref=' . $translate_media_ref_fields . ', expected>=4',
		$checks, $passed, $failed
	);
	foreach ( array( 'image', 'video', 'audio', 'document' ) as $media_task_type ) {
		e2e_check(
			"Media task_type: {$media_task_type}",
			! empty( $translate_media_task_types[ $media_task_type ] ),
			! empty( $translate_media_task_types[ $media_task_type ] ) ? 'present' : 'missing',
			$checks, $passed, $failed
		);
	}
}

// ---------------------------------------------------------------------------
// Step 4: 检查 field_capabilities 结构
// ---------------------------------------------------------------------------
echo "\n--- Step 4: Field capabilities ---\n";

$rules_with_caps = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM $rules_table WHERE field_capabilities IS NOT NULL AND field_capabilities != '' AND field_capabilities != '{}'"
);

e2e_check(
	'Rules with field_capabilities',
	$rules_with_caps > 0,
	"$rules_with_caps / $total_rules",
	$checks, $passed, $failed
);

// 抽样检查 field_capabilities JSON 结构
$sample = $wpdb->get_var(
	"SELECT field_capabilities FROM $rules_table
	 WHERE field_capabilities IS NOT NULL
	   AND field_capabilities != ''
	   AND field_capabilities != '{}'
	   AND field_capabilities != '[]'
	 LIMIT 1"
);

if ( $sample ) {
	$caps = json_decode( $sample, true );
	$valid_json = is_array( $caps ) && ! empty( $caps );
	e2e_check(
		'field_capabilities valid JSON',
		$valid_json,
		$valid_json ? count( $caps ) . ' fields' : 'invalid JSON',
		$checks, $passed, $failed
	);
}

// ---------------------------------------------------------------------------
// 结果
// ---------------------------------------------------------------------------
e2e_print_results( $checks, $passed, $failed );

if ( $failed > 0 ) {
	echo "\nSome rule checks failed. Run trigger-scan.php to regenerate.\n";
	exit( 1 );
}
