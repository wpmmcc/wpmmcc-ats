<?php
/**
 * Delicious Recipes 数据填充后置配置脚本
 *
 * 配置 Delicious Recipes 所需的页面：
 * - Recipe Search 页面
 * - Recipe Dashboard 页面
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 检查 Delicious Recipes 是否激活
if ( ! post_type_exists( 'recipe' ) ) {
    echo "  跳过: Delicious Recipes 未激活\n";
    return;
}

echo "  配置 Delicious Recipes 页面...\n";

$changes = array();

// 1. Recipe Search 页面
$search_page_id = get_option( 'delicious_recipes_recipe-search_page_id', 0 );
if ( empty( $search_page_id ) || get_post_status( $search_page_id ) !== 'publish' ) {
    // 查找现有页面
    $existing = get_page_by_path( 'recipe-search' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $search_page_id = $existing->ID;
    } else {
        // 创建页面
        $search_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Recipe Search',
                'post_name'    => 'recipe-search',
                'post_content' => '[delicious_recipes_search]',
                'post_status'  => 'publish',
            )
        );
        if ( $search_page_id ) {
            echo "  ✓ 创建 Recipe Search 页面 (ID: $search_page_id)\n";
            $changes[] = 'search_page';
        }
    }
    update_option( 'delicious_recipes_recipe-search_page_id', $search_page_id );
} else {
    echo "  已存在 Recipe Search 页面 (ID: $search_page_id)\n";
}

// 2. Recipe Dashboard 页面
$dashboard_page_id = get_option( 'delicious_recipes_recipe-dashboard_page_id', 0 );
if ( empty( $dashboard_page_id ) || get_post_status( $dashboard_page_id ) !== 'publish' ) {
    $existing = get_page_by_path( 'recipe-dashboard' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $dashboard_page_id = $existing->ID;
    } else {
        $dashboard_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Recipe Dashboard',
                'post_name'    => 'recipe-dashboard',
                'post_content' => '[delicious_recipes_dashboard]',
                'post_status'  => 'publish',
            )
        );
        if ( $dashboard_page_id ) {
            echo "  ✓ 创建 Recipe Dashboard 页面 (ID: $dashboard_page_id)\n";
            $changes[] = 'dashboard_page';
        }
    }
    update_option( 'delicious_recipes_recipe-dashboard_page_id', $dashboard_page_id );
} else {
    echo "  已存在 Recipe Dashboard 页面 (ID: $dashboard_page_id)\n";
}

// 刷新永久链接
if ( ! empty( $changes ) ) {
    flush_rewrite_rules();
    echo "  ✓ 永久链接已刷新\n";
}

echo "  Delicious Recipes 配置完成\n";

return array(
    'success' => true,
    'changes' => $changes,
);
