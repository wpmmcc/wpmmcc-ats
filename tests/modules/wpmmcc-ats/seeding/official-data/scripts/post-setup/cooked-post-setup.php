<?php
/**
 * Cooked 食谱插件数据填充后置配置脚本
 *
 * 在填充 Cooked 数据后执行，配置必要的页面和设置
 *
 * 执行方式:
 *   wp eval-file cooked-post-setup.php
 *
 * 配置内容:
 *   1. 创建/配置食谱归档页面
 *   2. 配置食谱浏览页面
 *   3. 配置 Cooked 设置
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Cooked 食谱插件后置配置                                     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查 Cooked 是否激活
if ( ! post_type_exists( 'cp_recipe' ) ) {
    echo "❌ Cooked 插件未激活，跳过配置\n";
    return;
}

$changes = array();

// =============================================================
// 1. 食谱归档页面
// =============================================================
echo "--- 1. 食谱归档页面 ---\n";

// Cooked 使用 option 'cooked_browse_page' 存储浏览页面 ID
$browse_page_id = get_option( 'cooked_browse_page' );
$browse_page = $browse_page_id ? get_post( $browse_page_id ) : null;

if ( ! $browse_page || $browse_page->post_status !== 'publish' ) {
    // 查找是否已存在 recipes 页面
    $existing = get_page_by_path( 'recipes' );

    if ( $existing ) {
        $browse_page_id = $existing->ID;
        echo "  找到已存在的食谱页面: ID $browse_page_id\n";
    } else {
        // 创建新页面
        $browse_page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_title'   => 'Recipes',
            'post_name'    => 'recipes',
            'post_status'  => 'publish',
            'post_content' => '[cooked-browse]',
        ) );
        echo "  ✓ 创建食谱浏览页面: ID $browse_page_id\n";
        $changes[] = 'created_browse_page';
    }

    update_option( 'cooked_browse_page', $browse_page_id );
    echo "  ✓ 配置 cooked_browse_page = $browse_page_id\n";
    $changes[] = 'configured_browse_page';
} else {
    echo "  食谱浏览页面已配置: ID $browse_page_id\n";
}

// =============================================================
// 2. 配置 Cooked 设置
// =============================================================
echo "\n--- 2. Cooked 设置 ---\n";

// 获取当前设置
$cooked_settings = get_option( 'cooked_settings', array() );

// 确保食谱 URL 前缀设置正确
if ( ! isset( $cooked_settings['recipe_slug'] ) || empty( $cooked_settings['recipe_slug'] ) ) {
    $cooked_settings['recipe_slug'] = 'recipes';
    update_option( 'cooked_settings', $cooked_settings );
    echo "  ✓ 配置 recipe_slug = recipes\n";
    $changes[] = 'configured_recipe_slug';
} else {
    echo "  recipe_slug 已配置: " . $cooked_settings['recipe_slug'] . "\n";
}

// =============================================================
// 3. 检查并配置分类法
// =============================================================
echo "\n--- 3. 分类法配置 ---\n";

// 检查食谱分类是否存在
$categories = get_terms( array(
    'taxonomy'   => 'cp_recipe_category',
    'hide_empty' => false,
    'number'     => 5,
) );

if ( is_wp_error( $categories ) ) {
    echo "  ⚠️ 分类法 cp_recipe_category 不可用\n";
} else {
    echo "  分类法 cp_recipe_category 可用，共 " . count( $categories ) . " 个分类\n";
    foreach ( $categories as $cat ) {
        echo "    - " . $cat->name . " (" . $cat->count . " 个食谱)\n";
    }
}

// =============================================================
// 4. 刷新永久链接
// =============================================================
echo "\n--- 4. 刷新永久链接 ---\n";
flush_rewrite_rules();
echo "  ✓ 永久链接已刷新\n";

// =============================================================
// 完成
// =============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  配置完成                                                    ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";

if ( empty( $changes ) ) {
    echo "║  无需更改，所有配置已就绪                                    ║\n";
} else {
    echo "║  执行了 " . count( $changes ) . " 项更改                                            ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 验证
echo "--- 验证 ---\n";
$browse_page_id = get_option( 'cooked_browse_page' );
if ( $browse_page_id ) {
    echo "Browse page: " . get_permalink( $browse_page_id ) . "\n";
}

// 列出一个食谱的 URL
$sample_recipe = get_posts( array(
    'post_type'      => 'cp_recipe',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
) );
if ( ! empty( $sample_recipe ) ) {
    echo "Sample recipe: " . get_permalink( $sample_recipe[0]->ID ) . "\n";
}

// 测试归档 URL
$pt_object = get_post_type_object( 'cp_recipe' );
if ( $pt_object && $pt_object->has_archive ) {
    echo "Archive URL: " . get_post_type_archive_link( 'cp_recipe' ) . "\n";
} else {
    echo "Archive: 使用浏览页面 /recipes/\n";
}
