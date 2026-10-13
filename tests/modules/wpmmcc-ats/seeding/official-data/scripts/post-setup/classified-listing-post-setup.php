<?php
/**
 * Classified Listing 数据填充后置配置脚本
 *
 * 检查和优化 Classified Listing 配置
 *
 * 执行方式:
 *   wp eval-file classified-listing-post-setup.php
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Classified Listing 后置配置                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'rtcl_listing' ) ) {
    echo "❌ Classified Listing 未激活，跳过配置\n";
    return;
}

$changes = array();

// =============================================================
// 1. 检查必要页面配置
// =============================================================
echo "--- 1. 页面配置 ---\n";

// Classified Listing 使用多个页面
$rtcl_pages = array(
    'rtcl_listings_page'   => 'Listings',
    'rtcl_submit_page'     => 'Submit Listing',
    'rtcl_checkout_page'   => 'Checkout',
    'rtcl_my_account_page' => 'My Account',
);

foreach ( $rtcl_pages as $option_name => $page_title ) {
    $page_id = get_option( $option_name );
    $page = $page_id ? get_post( $page_id ) : null;

    if ( ! $page || $page->post_status !== 'publish' ) {
        // 尝试查找已存在的页面
        $slug = sanitize_title( $page_title );
        $existing = get_page_by_path( $slug );

        if ( ! $existing ) {
            // 创建新页面
            $new_page_id = wp_insert_post( array(
                'post_type'    => 'page',
                'post_title'   => $page_title,
                'post_name'    => $slug,
                'post_status'  => 'publish',
                'post_content' => "[rtcl_{$slug}]",
            ) );

            if ( ! is_wp_error( $new_page_id ) ) {
                update_option( $option_name, $new_page_id );
                echo "  ✓ 创建页面 '$page_title': ID $new_page_id\n";
                $changes[] = "created_$option_name";
            }
        } else {
            update_option( $option_name, $existing->ID );
            echo "  ✓ 配置已有页面 '$page_title': ID {$existing->ID}\n";
            $changes[] = "configured_$option_name";
        }
    } else {
        echo "  页面 '$page_title' 已配置: ID $page_id\n";
    }
}

// =============================================================
// 2. 性能优化建议
// =============================================================
echo "\n--- 2. 性能检查 ---\n";

// 检查是否启用了相关图片
$rtcl_settings = get_option( 'rtcl_moderation_settings', array() );
$related_listings = isset( $rtcl_settings['related_listings'] ) ? $rtcl_settings['related_listings'] : 'yes';

if ( $related_listings === 'yes' ) {
    echo "  ⚠️ 相关列表功能已启用（可能影响页面加载速度）\n";
    echo "     如果单页加载缓慢，可考虑禁用相关列表\n";
} else {
    echo "  ✓ 相关列表功能已禁用\n";
}

// 检查图片数量限制
$max_images = get_option( 'rtcl_moderation_settings' );
$max_images = isset( $max_images['listing_max_gallery_images'] ) ? $max_images['listing_max_gallery_images'] : 5;
echo "  列表最大图片数: $max_images\n";

// =============================================================
// 3. 刷新永久链接
// =============================================================
echo "\n--- 3. 刷新永久链接 ---\n";
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
    printf( "║  执行了 %d 项更改                                            ║\n", count( $changes ) );
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 验证
echo "--- 验证 ---\n";
$archive_url = get_post_type_archive_link( 'rtcl_listing' );
echo "Archive: $archive_url\n";

$sample = get_posts( array(
    'post_type'      => 'rtcl_listing',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
) );
if ( ! empty( $sample ) ) {
    echo "Sample: " . get_permalink( $sample[0]->ID ) . "\n";
}

echo "\n";
echo "注意：Classified Listing 单页可能加载较慢（1-20秒），\n";
echo "这是插件的正常特性，建议在生产环境启用缓存。\n";

return array(
    'plugin'  => 'classified-listing',
    'changes' => $changes,
);
