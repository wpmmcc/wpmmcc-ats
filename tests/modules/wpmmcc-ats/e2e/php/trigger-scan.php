<?php
/**
 * E2E v2 触发全量模型扫描
 *
 * 调用 WPTSALL 的模型扫描服务，确保所有活跃插件的模型和翻译规则已生成。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/trigger-scan.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Trigger Model Scan ===\n\n";

// ---------------------------------------------------------------------------
// Step 1: 检查扫描服务可用
// ---------------------------------------------------------------------------
echo "--- Step 1: Check scanner availability ---\n";

if ( ! class_exists( 'WPTSALL\\Models\\Scanners\\Model_Scanner_V2' ) ) {
	echo "ERROR: Model_Scanner_V2 class not found. Is wptsall plugin active?\n";
	exit( 1 );
}
if ( ! class_exists( 'WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
	echo "ERROR: Translation_Rule_Service class not found.\n";
	exit( 1 );
}

echo "  Model_Scanner_V2: available\n";
echo "  Translation_Rule_Service: available\n";

// ---------------------------------------------------------------------------
// Step 1b: Populate plugin_mappings first (CPT discovery for plugins like Woo)
// ---------------------------------------------------------------------------
echo "\n--- Step 1b: Plugin mapping scan ---\n";
if ( class_exists( 'WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) ) {
	$map_stats = WPTSALL\Models\Services\Plugin_Mapping_Service::scan_and_save_all();
	echo '  total_scanned: ' . (int) ( $map_stats['total_scanned'] ?? 0 ) . "\n";
	echo '  content_plugins: ' . (int) ( $map_stats['content_plugins'] ?? 0 ) . "\n";
	echo '  saved: ' . (int) ( $map_stats['saved'] ?? 0 ) . "\n";
} else {
	echo "  WARN: Plugin_Mapping_Service unavailable; continuing without mapping pre-scan\n";
}

// ---------------------------------------------------------------------------
// Step 2: 触发全量扫描
// ---------------------------------------------------------------------------
echo "\n--- Step 2: Run scan ---\n";

$models_table = e2e_table( 'models' );
$rules_table  = e2e_table( 'translation_rules' );

$before_models = e2e_table_count( $models_table );
$before_rules  = e2e_table_count( $rules_table );

wp_set_current_user( 1 );

if ( e2e_is_matrix_parallel_lane() && e2e_is_plugin_specific_project() ) {
	require_once __DIR__ . '/trigger-scan-lane.php';
	echo "  Mode: lane-scoped scan (matrix parallel)\n";
	if ( ! e2e_trigger_scan_lane_plugins() ) {
		echo "ERROR: Lane-scoped scan failed\n";
		exit( 1 );
	}
	$after_models = e2e_table_count( $models_table );
	$after_rules  = e2e_table_count( $rules_table );
	echo "\n--- Results ---\n";
	echo "  Models: $before_models -> $after_models\n";
	echo "  Rules:  $before_rules -> $after_rules\n";
	echo "\nLane scan complete.\n";
	exit( 0 );
}

// 使用 scan-all 路由（scan 需要 plugin_slug 参数）。
$scan_route   = '/wptsall/v2/models/scan-all';
$scan_request = new WP_REST_Request( 'POST', $scan_route );
$scan_request->set_param( 'force', true );

// 设置管理员身份
wp_set_current_user( 1 );

$response = rest_do_request( $scan_request );
$status   = $response->get_status();
$data     = $response->get_data();

if ( $status === 200 && ! empty( $data['success'] ) ) {
	echo "  Scan completed successfully\n";
	echo "  models_created: " . (int) ( $data['models_created'] ?? 0 ) . "\n";
	echo "  models_updated: " . (int) ( $data['models_updated'] ?? 0 ) . "\n";
	echo "  models_failed:  " . (int) ( $data['models_failed'] ?? 0 ) . "\n";
} else {
	$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'unknown';
	echo "ERROR: Scan failed, status=$status, message=$message\n";
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Step 3: 为所有 active model 生成/刷新规则
// ---------------------------------------------------------------------------
echo "\n--- Step 3: Generate rules for active models ---\n";

$models = $wpdb->get_results(
	"SELECT id, plugin_slug FROM $models_table WHERE status = 'active' ORDER BY id ASC",
	ARRAY_A
);

if ( empty( $models ) ) {
	echo "ERROR: No active models found after scan.\n";
	exit( 1 );
}

$rule_created = 0;
$rule_updated = 0;
$rule_deleted = 0;
$rule_failed  = 0;

foreach ( $models as $model ) {
	$model_id    = (int) ( $model['id'] ?? 0 );
	$plugin_slug = (string) ( $model['plugin_slug'] ?? '' );
	if ( $model_id <= 0 || '' === $plugin_slug ) {
		continue;
	}

	$result = WPTSALL\Models\Services\Translation_Rule_Service::create_rules_for_model( $model_id );
	if ( is_wp_error( $result ) ) {
		++$rule_failed;
		echo "  [FAIL] {$plugin_slug}: " . $result->get_error_message() . "\n";
		continue;
	}

	$created = (int) ( $result['created'] ?? 0 );
	$updated = (int) ( $result['updated'] ?? 0 );
	$deleted = (int) ( $result['deleted'] ?? 0 );
	$rule_created += $created;
	$rule_updated += $updated;
	$rule_deleted += $deleted;
	echo "  [OK] {$plugin_slug}: created={$created}, updated={$updated}, deleted={$deleted}\n";
}

$after_models = e2e_table_count( $models_table );
$after_rules  = e2e_table_count( $rules_table );

echo "\n--- Results ---\n";
echo "  Models: $before_models -> $after_models\n";
echo "  Rules:  $before_rules -> $after_rules\n";
echo "  Rule changes: created={$rule_created}, updated={$rule_updated}, deleted={$rule_deleted}, failed={$rule_failed}\n";

if ( $after_models === 0 ) {
	echo "\nERROR: No models found after scan. Check that plugins are active.\n";
	exit( 1 );
}
if ( $after_rules === 0 ) {
	echo "\nERROR: No translation rules found after generation.\n";
	exit( 1 );
}

echo "\nScan complete.\n";
