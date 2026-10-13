<?php
/**
 * 验证脚本公共辅助函数
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 测试 URL 访问状态
 *
 * @param string $url      要测试的 URL
 * @param bool   $follow   是否跟随重定向（默认 true）
 * @return array ['status' => int, 'success' => bool, 'message' => string]
 */
function wptsall_verify_url( $url, $follow = true ) {
    $full_url = home_url( $url );

    $response = wp_remote_get( $full_url, array(
        'timeout'     => 15,
        'sslverify'   => false,
        'redirection' => $follow ? 5 : 0,
    ) );

    if ( is_wp_error( $response ) ) {
        return array(
            'url'     => $full_url,
            'status'  => 0,
            'success' => false,
            'message' => $response->get_error_message(),
        );
    }

    $status = wp_remote_retrieve_response_code( $response );

    // 对于跟随重定向的请求，301/302 重定向后最终状态才重要
    // 如果不跟随重定向，则 3xx 也视为成功（因为重定向本身是正常的）
    $is_success = ( $status >= 200 && $status < 400 );

    return array(
        'url'     => $full_url,
        'status'  => $status,
        'success' => $is_success,
        'message' => $is_success ? 'OK' : 'HTTP ' . $status,
    );
}

/**
 * 测试后台页面访问
 *
 * @param string $admin_path 后台路径（相对于 admin_url）
 * @return array
 */
function wptsall_verify_admin_url( $admin_path ) {
    $full_url = admin_url( $admin_path );

    // 后台需要模拟登录状态
    $response = wp_remote_get( $full_url, array(
        'timeout'     => 10,
        'sslverify'   => false,
        'redirection' => 5,
        'cookies'     => array(),
    ) );

    if ( is_wp_error( $response ) ) {
        return array(
            'url'     => $full_url,
            'status'  => 0,
            'success' => false,
            'message' => $response->get_error_message(),
        );
    }

    $status = wp_remote_retrieve_response_code( $response );

    // 后台可能重定向到登录页，这也是正常的
    return array(
        'url'     => $full_url,
        'status'  => $status,
        'success' => ( $status >= 200 && $status < 400 ),
        'message' => $status >= 200 && $status < 400 ? 'OK' : 'HTTP ' . $status,
    );
}

/**
 * 获取 post_type 的内容数量
 *
 * @param string $post_type
 * @return int
 */
function wptsall_get_post_count( $post_type ) {
    $count = wp_count_posts( $post_type );
    return isset( $count->publish ) ? (int) $count->publish : 0;
}

/**
 * 获取 taxonomy 的 term 数量
 *
 * @param string $taxonomy
 * @return int
 */
function wptsall_get_term_count( $taxonomy ) {
    return (int) wp_count_terms( array( 'taxonomy' => $taxonomy ) );
}

/**
 * 获取随机一条已发布内容的 URL
 *
 * @param string $post_type
 * @return string|null
 */
function wptsall_get_sample_url( $post_type ) {
    $posts = get_posts( array(
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => 5,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ) );

    if ( empty( $posts ) ) {
        return null;
    }

    // Double-check post_status to guard against plugins modifying the query
    // (e.g., bbPress hooks can include private/hidden forums for admin users).
    foreach ( $posts as $post ) {
        if ( $post->post_status === 'publish' ) {
            return get_permalink( $post->ID );
        }
    }

    return null;
}

/**
 * 获取归档页 URL
 *
 * @param string $post_type
 * @return string|null
 */
function wptsall_get_archive_url( $post_type ) {
    $archive_link = get_post_type_archive_link( $post_type );
    return $archive_link ? $archive_link : null;
}

/**
 * 输出验证结果行
 *
 * @param string $label
 * @param bool   $success
 * @param string $detail
 */
function wptsall_verify_output( $label, $success, $detail = '' ) {
    $icon = $success ? '✓' : '✗';
    $line = "  $icon $label";
    if ( $detail ) {
        $line .= " ($detail)";
    }
    echo $line . "\n";
}

/**
 * 输出 URL 测试结果
 *
 * @param string $label
 * @param array  $result wptsall_verify_url 返回值
 */
function wptsall_verify_url_output( $label, $result ) {
    $icon = $result['success'] ? '✓' : '✗';
    $status = $result['status'] ?: 'ERR';
    echo "  $icon $label -> $status\n";
    if ( ! $result['success'] && $result['message'] !== 'HTTP ' . $result['status'] ) {
        echo "      {$result['message']}\n";
    }
}

/**
 * 输出内容统计
 *
 * @param string $post_type
 * @param string $label
 * @param int    $expected 期望数量（0 表示不检查）
 */
function wptsall_verify_count_output( $post_type, $label, $expected = 0 ) {
    $count = wptsall_get_post_count( $post_type );
    $success = $count > 0;

    if ( $expected > 0 ) {
        $success = $count >= $expected;
        $detail = "$count 条" . ( $count < $expected ? " (期望 >= $expected)" : '' );
    } else {
        $detail = "$count 条";
    }

    wptsall_verify_output( $label, $success, $detail );

    return array(
        'post_type' => $post_type,
        'count'     => $count,
        'expected'  => $expected,
        'success'   => $success,
    );
}

/**
 * 创建验证结果摘要
 *
 * @param array $results 各项测试结果
 * @return array ['total' => int, 'passed' => int, 'failed' => int, 'rate' => float]
 */
function wptsall_verify_summary( $results ) {
    $total = count( $results );
    $passed = count( array_filter( $results, function( $r ) {
        return isset( $r['success'] ) && $r['success'];
    } ) );

    return array(
        'total'  => $total,
        'passed' => $passed,
        'failed' => $total - $passed,
        'rate'   => $total > 0 ? round( $passed / $total * 100, 1 ) : 0,
    );
}
