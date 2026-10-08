<?php
/**
 * E2E v2 站点关系创建
 *
 * 创建 3 种目标类型的站点关系：
 * 1. Blog 1 zh_CN → virtual en_US (media: copy)
 * 2. Blog 1 zh_CN → WP 子站 (en_US, media: copy; 优先 Blog 2)
 * 3. Blog 1 zh_CN → Blog 1 self (en_US, media: reference)
 *
 * 适配自 testing/e2e-setup.php Step 1-3，增加 self translation 支持。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/setup-relations.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Setup Site Relations ===\n\n";

$rel_table = e2e_table( 'site_relations' );
$now       = current_time( 'mysql' );
$force_fresh_virtual = false;
$use_fixed_virtual   = false;
$fixed_virtual_target_id = 'v_smoke_fixed';

if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw_arg ) {
		$raw = trim( (string) $raw_arg );
		$arg = strtolower( $raw );
		if ( '' === $arg ) {
			continue;
		}
		if ( '--fresh-virtual' === $arg || 'fresh-virtual' === $arg || 'fresh-virtual=1' === $arg ) {
			$force_fresh_virtual = true;
			continue;
		}
		if ( '--fixed-virtual' === $arg || 'fixed-virtual' === $arg || 'fixed-virtual=1' === $arg ) {
			$use_fixed_virtual = true;
			continue;
		}
		if ( 0 === strpos( $arg, 'fixed-virtual-target=' ) ) {
			$value = sanitize_key( substr( $raw, strlen( 'fixed-virtual-target=' ) ) );
			if ( '' !== $value ) {
				$fixed_virtual_target_id = $value;
				$use_fixed_virtual       = true;
			}
		}
	}
}
if ( $force_fresh_virtual ) {
	$use_fixed_virtual = false;
}

// Matrix parallel: each lane gets its own virtual target (no flock on Stage 5).
$args_list = ( ! empty( $args ) && is_array( $args ) ) ? $args : array();
if ( e2e_is_matrix_parallel_lane() && ! preg_match( '/fixed-virtual-target=/', implode( ' ', $args_list ) ) ) {
	$use_fixed_virtual         = true;
	$fixed_virtual_target_id   = e2e_lane_virtual_target_id();
	echo "  matrix-parallel lane namespace=" . e2e_lane_namespace() . " virtual={$fixed_virtual_target_id}\n";
}

// 验证表存在
if ( ! e2e_table_exists( $rel_table ) ) {
	echo "ERROR: Table $rel_table does not exist.\n";
	exit( 1 );
}

$relations = array();

// Pick WP target blog dynamically for better environment compatibility.
$wp_target_blog_id = 0;
if ( is_multisite() ) {
	if ( get_blog_details( 2 ) ) {
		$wp_target_blog_id = 2;
	} else {
		$sites = get_sites(
			array(
				'number'  => 20,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		foreach ( $sites as $site ) {
			$blog_id = (int) ( $site->blog_id ?? 0 );
			if ( $blog_id > 1 ) {
				$wp_target_blog_id = $blog_id;
				break;
			}
		}
	}
}

// ---------------------------------------------------------------------------
// Relation 1: Blog 1 zh_CN → virtual en_US (v_en)
// ---------------------------------------------------------------------------
echo "--- Relation 1: Virtual Site (en_US) ---\n";

$clone_source_virtual = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM $rel_table WHERE source_site_id = 1 AND target_site_type = 'virtual' AND target_lang = %s AND status = 'active' ORDER BY id ASC LIMIT 1",
	'en_US'
) );

$existing = null;
if ( $use_fixed_virtual ) {
	$existing = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $rel_table
			 WHERE source_site_id = 1
			   AND target_site_type = 'virtual'
			   AND target_site_id = %s
			   AND target_lang = %s
			   AND template = %s
			 LIMIT 1",
			$fixed_virtual_target_id,
			'en_US',
			'wordpress-blog'
		)
	);
} elseif ( ! $force_fresh_virtual ) {
	$existing = $clone_source_virtual;
}

if ( $existing ) {
	$relations['virtual'] = (int) $existing->id;
	echo "  Already exists: ID={$existing->id}, target={$existing->target_site_id}\n";
	if ( $existing->status !== 'active' ) {
		$wpdb->update( $rel_table, array( 'status' => 'active', 'updated_at' => $now ), array( 'id' => $existing->id ) );
		echo "  Reactivated.\n";
	}
} else {
	// For smoke isolation we optionally force-create a fresh virtual relation so it is
	// guaranteed to appear in top-N relation windows on long-lived environments.
	if ( $force_fresh_virtual ) {
		$vs_id = 'v_smoke_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
		echo "  fresh-virtual mode: forcing a new virtual relation target={$vs_id}\n";
	} elseif ( $use_fixed_virtual ) {
		$vs_id = $fixed_virtual_target_id;
		echo "  fixed-virtual mode: creating/reusing virtual relation target={$vs_id}\n";
	} else {
		$max_vs = (int) $wpdb->get_var(
			"SELECT MAX(CAST(REPLACE(target_site_id, 'v_', '') AS UNSIGNED)) FROM $rel_table WHERE target_site_type = 'virtual'"
		);
		$vs_id = 'v_' . ( $max_vs + 1 );
	}

	$wpdb->insert( $rel_table, array(
		'source_site_id'   => 1,
		'source_site_type' => 'wp',
		'source_lang'      => 'zh_CN',
		'template'         => 'wordpress-blog',
		'target_site_id'   => $vs_id,
		'target_site_type' => 'virtual',
		'target_lang'      => 'en_US',
		'media_handling'   => 'copy',
		'sync_mode'        => 'new_only',
		'direction'        => 'source_to_target',
		'status'           => 'active',
		'created_at'       => $now,
		'updated_at'       => $now,
	) );
	$relations['virtual'] = (int) $wpdb->insert_id;
	if ( $relations['virtual'] <= 0 ) {
		echo "ERROR: Failed to create virtual relation row.\n";
		exit( 1 );
	}
	echo "  Created: ID={$relations['virtual']}, target=$vs_id\n";

	if ( ( $force_fresh_virtual || $use_fixed_virtual ) && ! empty( $clone_source_virtual->id ) && (int) $clone_source_virtual->id !== (int) $relations['virtual'] ) {
		$source_relation_id = (int) $clone_source_virtual->id;
		$new_relation_id    = (int) $relations['virtual'];

		$relation_models_table = e2e_table( 'relation_models' );
		if ( e2e_table_exists( $relation_models_table ) ) {
			$inserted_models = (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$relation_models_table} (relation_id, model_id, created_at)
					 SELECT %d, model_id, %s
					 FROM {$relation_models_table}
					 WHERE relation_id = %d",
					$new_relation_id,
					$now,
					$source_relation_id
				)
			);
			echo "  cloned relation_models: source={$source_relation_id}, inserted={$inserted_models}\n";
		}

		$relation_pt_cfg_table = e2e_table( 'relation_post_type_configs' );
		if ( e2e_table_exists( $relation_pt_cfg_table ) ) {
			$inserted_post_type_cfg = (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$relation_pt_cfg_table}
						(relation_id, post_type, enabled, direction, sync_mode, field_overrides, created_at, updated_at)
					 SELECT %d, post_type, enabled, direction, sync_mode, field_overrides, %s, %s
					 FROM {$relation_pt_cfg_table}
					 WHERE relation_id = %d",
					$new_relation_id,
					$now,
					$now,
					$source_relation_id
				)
			);
			echo "  cloned post_type_configs: source={$source_relation_id}, inserted={$inserted_post_type_cfg}\n";
		}
	}
}

// ---------------------------------------------------------------------------
// Relation 2: Blog 1 zh_CN → WP subsite (en_US, media: copy)
// ---------------------------------------------------------------------------
echo "\n--- Relation 2: WP Subsite (en_US) ---\n";

if ( $wp_target_blog_id <= 0 ) {
	$relations['wp'] = 0;
	echo "  SKIPPED: No multisite target blog found.\n";
} else {
	$target_site_id = (string) $wp_target_blog_id;
	$existing       = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM $rel_table WHERE source_site_id = 1 AND target_site_id = %s AND target_lang = %s AND template = %s",
		$target_site_id, 'en_US', 'wordpress-blog'
	) );

	if ( $existing ) {
		$relations['wp'] = (int) $existing->id;
		echo "  Already exists: ID={$existing->id}, target_blog={$wp_target_blog_id}\n";
		if ( $existing->status !== 'active' ) {
			$wpdb->update( $rel_table, array( 'status' => 'active', 'updated_at' => $now ), array( 'id' => $existing->id ) );
			echo "  Reactivated.\n";
		}
	} else {
		$wpdb->insert( $rel_table, array(
			'source_site_id'   => 1,
			'source_site_type' => 'wp',
			'source_lang'      => 'zh_CN',
			'template'         => 'wordpress-blog',
			'target_site_id'   => $target_site_id,
			'target_site_type' => 'wp',
			'target_lang'      => 'en_US',
			'media_handling'   => 'copy',
			'sync_mode'        => 'new_only',
			'direction'        => 'source_to_target',
			'status'           => 'active',
			'created_at'       => $now,
			'updated_at'       => $now,
		) );
		$relations['wp'] = (int) $wpdb->insert_id;
		echo "  Created: ID={$relations['wp']}, target_blog={$wp_target_blog_id}\n";
	}
}

// ---------------------------------------------------------------------------
// Relation 3: Blog 1 zh_CN → Blog 1 self (en_US, media: reference)
// ---------------------------------------------------------------------------
echo "\n--- Relation 3: Self Translation (en_US) ---\n";

$existing = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM $rel_table WHERE source_site_id = 1 AND target_site_id = %s AND target_site_type = %s AND target_lang = %s",
	'1', 'self', 'en_US'
) );

if ( $existing ) {
	$relations['self'] = (int) $existing->id;
	echo "  Already exists: ID={$existing->id}\n";
	if ( $existing->status !== 'active' ) {
		$wpdb->update( $rel_table, array( 'status' => 'active', 'updated_at' => $now ), array( 'id' => $existing->id ) );
		echo "  Reactivated.\n";
	}
} else {
	$wpdb->insert( $rel_table, array(
		'source_site_id'   => 1,
		'source_site_type' => 'wp',
		'source_lang'      => 'zh_CN',
		'template'         => 'wordpress-blog',
		'target_site_id'   => '1',
		'target_site_type' => 'self',
		'target_lang'      => 'en_US',
		'media_handling'   => 'reference',
		'sync_mode'        => 'new_only',
		'direction'        => 'source_to_target',
		'status'           => 'active',
		'created_at'       => $now,
		'updated_at'       => $now,
	) );
	$id = (int) $wpdb->insert_id;

	if ( $id > 0 ) {
		$relations['self'] = $id;
		echo "  Created: ID={$id}\n";
	} else {
		echo "  SKIPPED: Self Translation not yet implemented in schema.\n";
		$relations['self'] = 0;
	}
}

// ---------------------------------------------------------------------------
// 保存 relation IDs
// ---------------------------------------------------------------------------
echo "\n--- Summary ---\n";

$output = array(
	'virtual' => $relations['virtual'] ?? 0,
	'wp'      => $relations['wp'] ?? 0,
	'self'    => $relations['self'] ?? 0,
	'wp_blog_id' => $wp_target_blog_id,
);

foreach ( e2e_target_types() as $type ) {
	$id     = (int) ( $output[ $type ] ?? 0 );
	$status = $id > 0 ? "ID=$id" : 'SKIPPED';
	echo "  $type: $status\n";
}
echo '  wp_blog_id: ' . ( $wp_target_blog_id > 0 ? $wp_target_blog_id : 'SKIPPED' ) . "\n";

e2e_save_relation_ids( $output );
echo "\nSaved to runtime/relation-ids.json\n";
