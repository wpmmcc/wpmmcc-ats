<?php
/**
 * WordPress Core Theme Unit Test Data Seeder
 *
 * 填充 WordPress 核心数据（post/page/attachment/comment/category/tag/menu）
 * 使用官方 Theme Unit Test Data
 *
 * 执行方式:
 *   wp eval-file wordpress-core-seed.php
 *
 * 工作流程:
 *   1. 检查 WordPress Importer 是否安装并激活
 *   2. 清空已有 WordPress 核心数据
 *   3. 导入官方 Theme Unit Test Data
 *
 * @package WPTSALL\DevTools\Seeding\OfficialData
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 配置
$xml_file = dirname( dirname( __FILE__ ) ) . '/wordpress-core/themeunittestdata.wordpress.xml';
$cleanup_script = dirname( dirname( __FILE__ ) ) . '/wordpress-core/cleanup-wp-core.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WordPress Core Theme Unit Test Data Seeder                  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// =============================================================
// Step 1: 检查 XML 文件是否存在
// =============================================================
echo "Step 1: 检查数据文件...\n";

if ( ! file_exists( $xml_file ) ) {
    echo "  ✗ 错误: 数据文件不存在\n";
    echo "    路径: $xml_file\n";
    echo "    请先下载: https://raw.githubusercontent.com/WordPress/theme-test-data/master/themeunittestdata.wordpress.xml\n";
    return array(
        'success' => false,
        'error'   => 'XML file not found',
    );
}

$file_size = filesize( $xml_file );
echo "  ✓ 数据文件存在 (" . round( $file_size / 1024 ) . " KB)\n";

// =============================================================
// Step 2: 检查 WordPress Importer
// =============================================================
echo "\nStep 2: 检查 WordPress Importer...\n";

// 检查导入器类是否可用
$importer_class = 'WP_Import';
$importer_available = class_exists( $importer_class ) || function_exists( 'wordpress_importer_init' );

if ( ! $importer_available ) {
    // 尝试加载导入器
    $importer_plugin = 'wordpress-importer/wordpress-importer.php';
    $importer_file = WP_PLUGIN_DIR . '/' . $importer_plugin;

    if ( file_exists( $importer_file ) ) {
        echo "  - 正在激活 WordPress Importer...\n";
        activate_plugin( $importer_plugin );
        include_once $importer_file;
    }

    // 再次检查
    if ( ! class_exists( 'WP_Import' ) ) {
        echo "  ✗ 错误: WordPress Importer 未安装或未激活\n";
        echo "    请运行: wp plugin install wordpress-importer --activate\n";
        return array(
            'success' => false,
            'error'   => 'WordPress Importer not available',
        );
    }
}

echo "  ✓ WordPress Importer 可用\n";

// =============================================================
// Step 3: 检查已有数据
// =============================================================
echo "\nStep 3: 检查已有 WordPress 核心数据...\n";

$existing = array(
    'posts'       => wp_count_posts( 'post' )->publish ?? 0,
    'pages'       => wp_count_posts( 'page' )->publish ?? 0,
    'attachments' => wp_count_posts( 'attachment' )->inherit ?? 0,
);

$has_data = ( $existing['posts'] > 5 || $existing['pages'] > 2 || $existing['attachments'] > 5 );

echo "  - 文章: {$existing['posts']} 篇\n";
echo "  - 页面: {$existing['pages']} 个\n";
echo "  - 附件: {$existing['attachments']} 个\n";

// =============================================================
// Step 4: 清理已有数据
// =============================================================
if ( $has_data ) {
    echo "\nStep 4: 清理已有数据...\n";

    if ( file_exists( $cleanup_script ) ) {
        // 直接执行清理逻辑（不使用 --dry-run）
        include $cleanup_script;
    } else {
        // 内联清理逻辑
        echo "  - 清理脚本不存在，使用内联清理...\n";

        global $wpdb;

        // 清理文章
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        foreach ( $posts as $post_id ) {
            wp_delete_post( $post_id, true );
        }
        echo "  ✓ 已删除 " . count( $posts ) . " 篇文章\n";

        // 清理页面
        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        foreach ( $pages as $page_id ) {
            wp_delete_post( $page_id, true );
        }
        echo "  ✓ 已删除 " . count( $pages ) . " 个页面\n";

        // 清理附件
        $attachments = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        foreach ( $attachments as $attachment_id ) {
            wp_delete_attachment( $attachment_id, true );
        }
        echo "  ✓ 已删除 " . count( $attachments ) . " 个附件\n";

        // 清理评论
        $wpdb->query( "TRUNCATE TABLE {$wpdb->comments}" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->commentmeta}" );
        echo "  ✓ 已清空评论\n";

        // 清理分类（保留默认）
        $default_cat = get_option( 'default_category' );
        $categories = get_terms( array(
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'exclude'    => array( $default_cat ),
        ) );
        foreach ( $categories as $cat ) {
            wp_delete_term( $cat->term_id, 'category' );
        }
        echo "  ✓ 已删除 " . count( $categories ) . " 个分类\n";

        // 清理标签
        $tags = get_terms( array(
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
        ) );
        foreach ( $tags as $tag ) {
            wp_delete_term( $tag->term_id, 'post_tag' );
        }
        echo "  ✓ 已删除 " . count( $tags ) . " 个标签\n";
    }
} else {
    echo "\nStep 4: 跳过清理（数据量较少）\n";
}

// =============================================================
// Step 5: 导入数据
// =============================================================
echo "\nStep 5: 导入 Theme Unit Test Data...\n";

// 准备导入器
if ( ! class_exists( 'WP_Import' ) ) {
    require_once WP_PLUGIN_DIR . '/wordpress-importer/wordpress-importer.php';
}

$importer = new WP_Import();

// 配置导入选项
$importer->fetch_attachments = true;

// 执行导入
ob_start();
$importer->import( $xml_file );
$import_output = ob_get_clean();

// 统计导入结果
$imported = array(
    'posts'       => wp_count_posts( 'post' )->publish ?? 0,
    'pages'       => wp_count_posts( 'page' )->publish ?? 0,
    'attachments' => wp_count_posts( 'attachment' )->inherit ?? 0,
    'categories'  => wp_count_terms( 'category' ),
    'tags'        => wp_count_terms( 'post_tag' ),
);

echo "  ✓ 导入完成\n";

// =============================================================
// 汇总
// =============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WordPress Core 数据填充完成                                 ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf( "║  文章:   %3d 篇                                              ║\n", $imported['posts'] );
printf( "║  页面:   %3d 个                                              ║\n", $imported['pages'] );
printf( "║  附件:   %3d 个                                              ║\n", $imported['attachments'] );
printf( "║  分类:   %3d 个                                              ║\n", $imported['categories'] );
printf( "║  标签:   %3d 个                                              ║\n", $imported['tags'] );
echo "╚══════════════════════════════════════════════════════════════╝\n";

// 提示验证
echo "\n下一步: 运行验证脚本\n";
echo "  wp eval-file scripts/verify/wordpress-core-verify.php\n";

return array(
    'success'  => true,
    'plugin'   => 'wordpress-core',
    'imported' => $imported,
);
