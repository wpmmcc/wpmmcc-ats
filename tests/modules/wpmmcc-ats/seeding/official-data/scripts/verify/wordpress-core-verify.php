<?php
/**
 * WordPress Core 验证脚本 - 企业官网模式
 *
 * 验证企业官网数据填充后的内容
 */

require_once __DIR__ . '/verify-helpers.php';

echo "========================================\n";
echo "WordPress Core 内容验证（企业官网）\n";
echo "========================================\n\n";

$results = array(
    'plugin'  => 'wordpress-core',
    'plan'    => 'E',
    'mode'    => 'corporate-site',
    'checks'  => array(),
    'passed'  => 0,
    'failed'  => 0,
);

// 1. 验证内容数量
echo "1. 验证内容数量\n";

$page_count = wp_count_posts( 'page' );
$post_count = wp_count_posts( 'post' );

$checks = array(
    array( 'name' => 'Pages count', 'expected' => 4, 'actual' => $page_count->publish ),
    array( 'name' => 'Posts count', 'expected' => 3, 'actual' => $post_count->publish ),
);

foreach ( $checks as $check ) {
    $passed = $check['actual'] >= $check['expected'];
    $status = $passed ? '✓' : '✗';
    echo "   $status {$check['name']}: {$check['actual']} (expected >= {$check['expected']})\n";

    $results['checks'][] = array(
        'name'     => $check['name'],
        'passed'   => $passed,
        'expected' => $check['expected'],
        'actual'   => $check['actual'],
    );

    if ( $passed ) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
}

// 2. 验证核心页面
echo "\n2. 验证核心页面\n";

$required_pages = array(
    'home'     => '首页',
    'services' => '服务项目',
    'about'    => '关于我们',
    'contact'  => '联系我们',
);

foreach ( $required_pages as $slug => $title ) {
    $page = get_page_by_path( $slug );
    $passed = ! empty( $page ) && $page->post_status === 'publish';
    $status = $passed ? '✓' : '✗';
    $actual_title = $page ? $page->post_title : '未找到';
    echo "   $status $title ($slug): $actual_title\n";

    $results['checks'][] = array(
        'name'   => "Page: $title",
        'passed' => $passed,
        'slug'   => $slug,
    );

    if ( $passed ) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
}

// 3. 验证分类
echo "\n3. 验证分类\n";

$required_categories = array( '公司新闻', '行业动态' );
foreach ( $required_categories as $cat_name ) {
    $cat = get_term_by( 'name', $cat_name, 'category' );
    $passed = ! empty( $cat );
    $status = $passed ? '✓' : '✗';
    echo "   $status 分类: $cat_name\n";

    $results['checks'][] = array(
        'name'   => "Category: $cat_name",
        'passed' => $passed,
    );

    if ( $passed ) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
}

// 4. 验证前台 URL
echo "\n4. 验证前台 URL\n";

$frontend_urls = array(
    '首页'   => home_url( '/' ),
    '服务'   => home_url( '/services/' ),
    '关于'   => home_url( '/about/' ),
    '联系'   => home_url( '/contact/' ),
);

// 获取最新文章 URL
$latest_post = get_posts( array(
    'post_type'      => 'post',
    'posts_per_page' => 1,
    'post_status'    => 'publish',
) );

if ( ! empty( $latest_post ) ) {
    $frontend_urls['文章详情'] = get_permalink( $latest_post[0]->ID );
}

// 获取分类归档 URL
$company_news = get_term_by( 'slug', 'company-news', 'category' );
if ( $company_news ) {
    $frontend_urls['公司新闻归档'] = get_category_link( $company_news->term_id );
}

foreach ( $frontend_urls as $name => $url ) {
    $response = wp_remote_get( $url, array( 'timeout' => 10, 'sslverify' => false ) );
    $status_code = wp_remote_retrieve_response_code( $response );
    $passed = $status_code === 200;
    $status = $passed ? '✓' : '✗';
    echo "   $status $name: $url [$status_code]\n";

    $results['checks'][] = array(
        'name'        => "Frontend: $name",
        'passed'      => $passed,
        'url'         => $url,
        'status_code' => $status_code,
    );

    if ( $passed ) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
}

// 5. 验证后台 URL
echo "\n5. 验证后台 URL\n";

$admin_urls = array(
    'Posts list'    => admin_url( 'edit.php' ),
    'Pages list'    => admin_url( 'edit.php?post_type=page' ),
    'Categories'    => admin_url( 'edit-tags.php?taxonomy=category' ),
    'Menus'         => admin_url( 'nav-menus.php' ),
);

foreach ( $admin_urls as $name => $url ) {
    $passed = ! empty( $url ) && strpos( $url, 'wp-admin' ) !== false;
    $status = $passed ? '✓' : '✗';
    echo "   $status $name: $url\n";

    $results['checks'][] = array(
        'name'   => "Backend: $name",
        'passed' => $passed,
        'url'    => $url,
    );

    if ( $passed ) {
        $results['passed']++;
    } else {
        $results['failed']++;
    }
}

// 6. 验证导航菜单
echo "\n6. 验证导航菜单\n";

$menus = wp_get_nav_menus();
$main_menu = null;
foreach ( $menus as $menu ) {
    if ( strpos( $menu->name, '主导航' ) !== false || strpos( $menu->name, 'Primary' ) !== false ) {
        $main_menu = $menu;
        break;
    }
}

if ( $main_menu ) {
    $menu_items = wp_get_nav_menu_items( $main_menu->term_id );
    $item_count = count( $menu_items );
    $passed = $item_count >= 4;
    $status = $passed ? '✓' : '✗';
    echo "   $status 主导航菜单: {$main_menu->name} ($item_count 项)\n";
} else {
    $passed = false;
    $status = '✗';
    echo "   $status 主导航菜单: 未找到\n";
}

$results['checks'][] = array(
    'name'   => 'Navigation Menu',
    'passed' => $passed,
);

if ( $passed ) {
    $results['passed']++;
} else {
    $results['failed']++;
}

// 输出汇总
echo "\n========================================\n";
echo "验证结果汇总\n";
echo "========================================\n";
echo "通过: {$results['passed']}\n";
echo "失败: {$results['failed']}\n";

$total = $results['passed'] + $results['failed'];
$pass_rate = $total > 0 ? $results['passed'] / $total * 100 : 0;
echo "通过率: " . round( $pass_rate, 1 ) . "%\n";

// 保存结果
$results['pass_rate'] = $pass_rate;
$results['timestamp'] = date( 'Y-m-d H:i:s' );
$results['summary'] = array(
    'passed' => $results['passed'],
    'total'  => $total,
    'rate'   => $pass_rate,
);

$output_file = __DIR__ . '/../results/wordpress-core-verify-result.json';
if ( ! is_dir( dirname( $output_file ) ) ) {
    mkdir( dirname( $output_file ), 0755, true );
}
file_put_contents( $output_file, json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
echo "\n结果已保存到: $output_file\n";

return $results;
