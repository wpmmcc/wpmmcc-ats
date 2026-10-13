<?php
/**
 * WordPress 核心数据清理脚本
 *
 * 在导入 Theme Unit Test Data 之前运行，清空已有的 WordPress 核心数据
 *
 * 用法: wp eval-file cleanup-wp-core.php [--dry-run]
 */

// 检查是否是 dry-run 模式
$dry_run = in_array( '--dry-run', $GLOBALS['argv'] ?? array() );

echo "========================================\n";
echo "WordPress 核心数据清理\n";
echo "========================================\n";

if ( $dry_run ) {
    echo "** DRY RUN 模式 - 不会实际删除数据 **\n";
}
echo "\n";

// 统计
$stats = array(
    'posts'       => 0,
    'pages'       => 0,
    'attachments' => 0,
    'revisions'   => 0,
    'nav_menus'   => 0,
    'comments'    => 0,
    'categories'  => 0,
    'tags'        => 0,
);

// 1. 清理文章 (posts)
echo "1. 清理文章 (posts)...\n";
$posts = get_posts( array(
    'post_type'      => 'post',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
) );
$stats['posts'] = count( $posts );
echo "   找到 {$stats['posts']} 篇文章\n";

if ( ! $dry_run && ! empty( $posts ) ) {
    foreach ( $posts as $post_id ) {
        wp_delete_post( $post_id, true ); // true = 强制删除，跳过回收站
    }
    echo "   ✓ 已删除\n";
}

// 2. 清理页面 (pages)
echo "\n2. 清理页面 (pages)...\n";
$pages = get_posts( array(
    'post_type'      => 'page',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
) );
$stats['pages'] = count( $pages );
echo "   找到 {$stats['pages']} 个页面\n";

if ( ! $dry_run && ! empty( $pages ) ) {
    foreach ( $pages as $page_id ) {
        wp_delete_post( $page_id, true );
    }
    echo "   ✓ 已删除\n";
}

// 3. 清理附件 (attachments)
echo "\n3. 清理附件 (attachments)...\n";
$attachments = get_posts( array(
    'post_type'      => 'attachment',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
) );
$stats['attachments'] = count( $attachments );
echo "   找到 {$stats['attachments']} 个附件\n";

if ( ! $dry_run && ! empty( $attachments ) ) {
    foreach ( $attachments as $attachment_id ) {
        wp_delete_attachment( $attachment_id, true ); // 同时删除文件
    }
    echo "   ✓ 已删除\n";
}

// 4. 清理修订版本 (revisions)
echo "\n4. 清理修订版本 (revisions)...\n";
$revisions = get_posts( array(
    'post_type'      => 'revision',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
) );
$stats['revisions'] = count( $revisions );
echo "   找到 {$stats['revisions']} 个修订版本\n";

if ( ! $dry_run && ! empty( $revisions ) ) {
    foreach ( $revisions as $revision_id ) {
        wp_delete_post( $revision_id, true );
    }
    echo "   ✓ 已删除\n";
}

// 5. 清理导航菜单项 (nav_menu_item)
echo "\n5. 清理导航菜单项...\n";
$nav_items = get_posts( array(
    'post_type'      => 'nav_menu_item',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
) );
$stats['nav_menus'] = count( $nav_items );
echo "   找到 {$stats['nav_menus']} 个菜单项\n";

if ( ! $dry_run && ! empty( $nav_items ) ) {
    foreach ( $nav_items as $nav_id ) {
        wp_delete_post( $nav_id, true );
    }
    echo "   ✓ 已删除\n";
}

// 6. 清理评论 (comments)
echo "\n6. 清理评论 (comments)...\n";
global $wpdb;
$comment_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
$stats['comments'] = (int) $comment_count;
echo "   找到 {$stats['comments']} 条评论\n";

if ( ! $dry_run && $stats['comments'] > 0 ) {
    $wpdb->query( "TRUNCATE TABLE {$wpdb->comments}" );
    $wpdb->query( "TRUNCATE TABLE {$wpdb->commentmeta}" );
    echo "   ✓ 已删除\n";
}

// 7. 清理分类 (categories) - 保留默认分类
echo "\n7. 清理分类 (categories)...\n";
$default_cat = get_option( 'default_category' );
$categories = get_terms( array(
    'taxonomy'   => 'category',
    'hide_empty' => false,
    'exclude'    => array( $default_cat ),
) );
$stats['categories'] = count( $categories );
echo "   找到 {$stats['categories']} 个分类 (排除默认分类)\n";

if ( ! $dry_run && ! empty( $categories ) ) {
    foreach ( $categories as $cat ) {
        wp_delete_term( $cat->term_id, 'category' );
    }
    echo "   ✓ 已删除\n";
}

// 8. 清理标签 (tags)
echo "\n8. 清理标签 (tags)...\n";
$tags = get_terms( array(
    'taxonomy'   => 'post_tag',
    'hide_empty' => false,
) );
$stats['tags'] = count( $tags );
echo "   找到 {$stats['tags']} 个标签\n";

if ( ! $dry_run && ! empty( $tags ) ) {
    foreach ( $tags as $tag ) {
        wp_delete_term( $tag->term_id, 'post_tag' );
    }
    echo "   ✓ 已删除\n";
}

// 9. 清理导航菜单 (nav_menu taxonomy)
echo "\n9. 清理导航菜单...\n";
$menus = get_terms( array(
    'taxonomy'   => 'nav_menu',
    'hide_empty' => false,
) );
$menu_count = count( $menus );
echo "   找到 {$menu_count} 个菜单\n";

if ( ! $dry_run && ! empty( $menus ) ) {
    foreach ( $menus as $menu ) {
        wp_delete_term( $menu->term_id, 'nav_menu' );
    }
    echo "   ✓ 已删除\n";
}

// 10. 清理文章格式 (post_format)
echo "\n10. 清理文章格式关联...\n";
if ( ! $dry_run ) {
    $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'post_format')" );
    echo "   ✓ 已清理\n";
}

// 汇总
echo "\n========================================\n";
echo "清理汇总\n";
echo "========================================\n";

$total = array_sum( $stats );
echo "Posts:       {$stats['posts']}\n";
echo "Pages:       {$stats['pages']}\n";
echo "Attachments: {$stats['attachments']}\n";
echo "Revisions:   {$stats['revisions']}\n";
echo "Nav Menus:   {$stats['nav_menus']}\n";
echo "Comments:    {$stats['comments']}\n";
echo "Categories:  {$stats['categories']}\n";
echo "Tags:        {$stats['tags']}\n";
echo "----------------------------------------\n";
echo "Total:       {$total}\n";

if ( $dry_run ) {
    echo "\n** DRY RUN 完成 - 未删除任何数据 **\n";
    echo "移除 --dry-run 参数以实际执行删除\n";
} else {
    echo "\n✓ 清理完成！现在可以导入 Theme Unit Test Data\n";
    echo "\n下一步:\n";
    echo "wp import /path/to/themeunittestdata.wordpress.xml --authors=create\n";
}
