<?php
/**
 * Asgaros Forum 数据填充后置配置脚本
 *
 * 配置 Asgaros Forum 所需的页面：
 * - Forum 主页面（包含 [forum] shortcode）
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 检查 Asgaros Forum 是否激活
if ( ! class_exists( 'AsgarosForum' ) ) {
    echo "  跳过: Asgaros Forum 未激活\n";
    return;
}

echo "  配置 Asgaros Forum 页面...\n";

$changes = array();

// 获取当前设置
$asgaros_options = get_option( 'asgarosforum_options', array() );

// Forum 主页面
$forum_page_id = isset( $asgaros_options['location'] ) ? intval( $asgaros_options['location'] ) : 0;
if ( $forum_page_id <= 0 || get_post_status( $forum_page_id ) !== 'publish' ) {
    // 查找现有页面
    $existing = get_page_by_path( 'forum' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $forum_page_id = $existing->ID;
    } else {
        // 创建页面
        $forum_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Forum',
                'post_name'    => 'forum',
                'post_content' => '[forum]',
                'post_status'  => 'publish',
            )
        );
        if ( $forum_page_id ) {
            echo "  ✓ 创建 Forum 页面 (ID: $forum_page_id)\n";
            $changes[] = 'forum_page';
        }
    }

    // 更新设置
    $asgaros_options['location'] = $forum_page_id;
    update_option( 'asgarosforum_options', $asgaros_options );
} else {
    echo "  已存在 Forum 页面 (ID: $forum_page_id)\n";
}

// 刷新永久链接
if ( ! empty( $changes ) ) {
    flush_rewrite_rules();
    echo "  ✓ 永久链接已刷新\n";
}

echo "  Asgaros Forum 配置完成\n";

return array(
    'success' => true,
    'changes' => $changes,
);
