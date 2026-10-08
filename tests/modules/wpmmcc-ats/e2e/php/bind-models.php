<?php
/**
 * E2E v2 绑定全部模型到站点关系
 *
 * 查询所有 active models，绑定到 runtime/relation-ids.json 中的每个关系。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/bind-models.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Bind Models to Relations ===\n\n";

$rel_table    = e2e_table( 'site_relations' );
$rm_table     = e2e_table( 'relation_models' );
$models_table = e2e_table( 'models' );

// 验证表存在
foreach ( array( $rel_table, $rm_table, $models_table ) as $t ) {
	if ( ! e2e_table_exists( $t ) ) {
		echo "ERROR: Table $t does not exist.\n";
		exit( 1 );
	}
}

// ---------------------------------------------------------------------------
// Step 1: 加载 relation IDs
// ---------------------------------------------------------------------------
echo "--- Step 1: Load relation IDs ---\n";

$relation_ids = e2e_load_relation_ids();
if ( empty( $relation_ids ) ) {
	echo "ERROR: No relation IDs found. Run setup-relations.php first.\n";
	exit( 1 );
}

$normalized_rels = e2e_relation_ids_only( $relation_ids );
$active_rels     = e2e_active_relation_ids( $relation_ids );
foreach ( $normalized_rels as $type => $id ) {
	if ( isset( $active_rels[ $type ] ) ) {
		echo "  $type: ID=$id\n";
	} else {
		echo "  $type: SKIPPED\n";
	}
}

// ---------------------------------------------------------------------------
// Step 2: 获取所有 active models
// ---------------------------------------------------------------------------
echo "\n--- Step 2: Load active models ---\n";

$models = $wpdb->get_results(
	"SELECT id, plugin_slug, plugin_name FROM $models_table WHERE status = 'active' ORDER BY id",
	ARRAY_A
);

if ( e2e_is_matrix_parallel_lane() ) {
	$lane_slugs = e2e_lane_model_plugin_slugs();
	$models     = array_values(
		array_filter(
			$models,
			static function ( $m ) use ( $lane_slugs ) {
				return in_array( (string) ( $m['plugin_slug'] ?? '' ), $lane_slugs, true );
			}
		)
	);
	echo '  Matrix parallel: binding ' . count( $models ) . ' lane model(s) only' . "\n";
}

if ( empty( $models ) ) {
	echo "ERROR: No active models found. Run trigger-scan.php first.\n";
	exit( 1 );
}

echo "  Found " . count( $models ) . " active models:\n";
foreach ( $models as $m ) {
	echo "    ID={$m['id']}: {$m['plugin_slug']} ({$m['plugin_name']})\n";
}

// ---------------------------------------------------------------------------
// Step 3: 绑定模型到每个关系
// ---------------------------------------------------------------------------
echo "\n--- Step 3: Bind models ---\n";

$now     = current_time( 'mysql' );
$bound   = 0;
$existed = 0;

foreach ( $active_rels as $type => $rid ) {
	echo "\n  Relation $rid ($type):\n";

	foreach ( $models as $m ) {
		$mid = (int) $m['id'];
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $rm_table WHERE relation_id = %d AND model_id = %d",
			$rid, $mid
		) );

		if ( $exists ) {
			++$existed;
		} else {
			$wpdb->insert( $rm_table, array(
				'relation_id' => $rid,
				'model_id'    => $mid,
				'created_at'  => $now,
			) );
			++$bound;
			echo "    + {$m['plugin_slug']}\n";
		}
	}

	// 更新 models_count
	$cnt = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $rm_table WHERE relation_id = %d", $rid
	) );
	$wpdb->update( $rel_table, array( 'models_count' => $cnt, 'updated_at' => $now ), array( 'id' => $rid ) );
	echo "    models_count = $cnt\n";
}

echo "\n--- Summary ---\n";
echo "  Newly bound: $bound\n";
echo "  Already existed: $existed\n";
echo "  Total models: " . count( $models ) . "\n";
echo "  Total relations: " . count( $active_rels ) . "\n";
