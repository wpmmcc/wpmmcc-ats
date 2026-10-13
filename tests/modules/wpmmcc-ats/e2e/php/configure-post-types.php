<?php
/**
 * E2E v2 配置 post_type_configs
 *
 * 为每个 relation × 每个 model 的 post_type 创建 post_type_config 记录。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/configure-post-types.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Configure Post Type Configs ===\n\n";

$rel_table    = e2e_table( 'site_relations' );
$rm_table     = e2e_table( 'relation_models' );
$ptc_table    = e2e_table( 'relation_post_type_configs' );
$models_table = e2e_table( 'models' );
$mo_table     = e2e_table( 'model_objects' );
$rules_table  = e2e_table( 'translation_rules' );

foreach ( array( $rel_table, $rm_table, $ptc_table, $models_table ) as $t ) {
	if ( ! e2e_table_exists( $t ) ) {
		echo "ERROR: Table $t does not exist.\n";
		exit( 1 );
	}
}

// ---------------------------------------------------------------------------
// Step 1: Load relation IDs
// ---------------------------------------------------------------------------
$relation_ids = e2e_load_relation_ids();
$active_rels  = e2e_active_relation_ids( $relation_ids );

if ( empty( $active_rels ) ) {
	echo "ERROR: No active relations. Run setup-relations.php first.\n";
	exit( 1 );
}

echo "Relations: " . implode( ', ', array_map( function( $t, $id ) { return "$t=$id"; }, array_keys( $active_rels ), $active_rels ) ) . "\n\n";

// ---------------------------------------------------------------------------
// Step 2: 获取所有需要配置的 post_types
// ---------------------------------------------------------------------------

// 从模型对象表获取对象名（post_type/taxonomy 的 object_name）。
$post_types = array();

if ( e2e_table_exists( $mo_table ) ) {
	$model_pts = $wpdb->get_col(
		"SELECT DISTINCT object_name
		 FROM $mo_table
		 WHERE object_name != ''
		   AND object_type IN ('post_type', 'taxonomy')"
	);
	$post_types = array_merge( $post_types, $model_pts );
}

// 从规则表补齐（防止模型对象尚未完全同步）。
if ( e2e_table_exists( $rules_table ) ) {
	$rule_pts = $wpdb->get_col(
		"SELECT DISTINCT object_name
		 FROM $rules_table
		 WHERE object_name != ''
		   AND is_active = 1"
	);
	$post_types = array_merge( $post_types, $rule_pts );
}

// 加上 WordPress 核心 post types
$core_types = array( 'post', 'page' );
$post_types = array_unique( array_merge( $core_types, $post_types ) );
$post_types = array_values(
	array_filter(
		array_map( 'sanitize_key', $post_types ),
		static function ( $value ) {
			return '' !== $value && ! in_array( $value, array( 'post_type', 'taxonomy' ), true );
		}
	)
);
sort( $post_types );

if ( empty( $post_types ) ) {
	echo "ERROR: No post types discovered from model_objects/translation_rules.\n";
	exit( 1 );
}

echo "Post types to configure: " . implode( ', ', $post_types ) . "\n\n";

// ---------------------------------------------------------------------------
// Step 3: 创建 post_type_configs
// ---------------------------------------------------------------------------

$now     = current_time( 'mysql' );
$created = 0;
$existed = 0;

foreach ( $active_rels as $type => $rid ) {
	echo "--- Relation $rid ($type) ---\n";

	foreach ( $post_types as $pt ) {
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $ptc_table WHERE relation_id = %d AND post_type = %s",
			$rid, $pt
		) );

		if ( $exists ) {
			++$existed;
		} else {
			$wpdb->insert( $ptc_table, array(
				'relation_id' => $rid,
				'post_type'   => $pt,
				'enabled'     => 1,
				'direction'   => null,
				'sync_mode'   => 'new_only',
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
			++$created;
			echo "  + $pt\n";
		}
	}
}

echo "\n--- Summary ---\n";
echo "  Created: $created\n";
echo "  Already existed: $existed\n";
echo "  Total post types: " . count( $post_types ) . "\n";
