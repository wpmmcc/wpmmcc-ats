<?php
/**
 * Sensei LMS CSV Import Script
 *
 * 使用 WP-CLI 的 sensei-import 命令导入课程和课时
 * 需要先将 CSV 文件复制到 WordPress 根目录
 *
 * @package WPTSALL\DevTools\Seeding\OfficialData
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 检查 Sensei LMS 是否激活
if ( ! class_exists( 'Sensei_Main' ) ) {
    echo "错误: Sensei LMS 未激活\n";
    exit( 1 );
}

// 检查 WP-CLI 是否可用
if ( ! class_exists( 'WP_CLI' ) ) {
    echo "错误: 需要在 WP-CLI 环境中运行\n";
    exit( 1 );
}

// CSV 文件路径
$base_path = dirname( __DIR__ );
$courses_source = $base_path . '/sensei-lms/courses.csv';
$lessons_source = $base_path . '/sensei-lms/lessons.csv';

// 检查源文件
if ( ! file_exists( $courses_source ) ) {
    echo "错误: 找不到 courses.csv: $courses_source\n";
    exit( 1 );
}

if ( ! file_exists( $lessons_source ) ) {
    echo "错误: 找不到 lessons.csv: $lessons_source\n";
    exit( 1 );
}

// 复制到 WordPress 根目录
$wp_root = ABSPATH;
$courses_local = $wp_root . 'sensei-courses.csv';
$lessons_local = $wp_root . 'sensei-lessons.csv';

echo "复制 CSV 文件到 WordPress 根目录...\n";

if ( ! copy( $courses_source, $courses_local ) ) {
    echo "错误: 无法复制 courses.csv\n";
    exit( 1 );
}

if ( ! copy( $lessons_source, $lessons_local ) ) {
    echo "错误: 无法复制 lessons.csv\n";
    unlink( $courses_local );
    exit( 1 );
}

echo "执行 Sensei LMS 导入...\n";

// 执行导入命令
try {
    // 输出重定向到临时文件：嵌套 wp-cli 输出超过管道缓冲时，子进程阻塞在
    // 写端而父进程顺序读取另一条管道，形成死锁（隔离 slot 环境实测）。
    $out_file = tempnam( sys_get_temp_dir(), 'wptsall-sensei-out-' );
    $err_file = tempnam( sys_get_temp_dir(), 'wptsall-sensei-err-' );
    $result = WP_CLI::runcommand(
        'sensei-import --user=admin --courses=sensei-courses.csv --lessons=sensei-lessons.csv'
        . ' > ' . escapeshellarg( $out_file ) . ' 2> ' . escapeshellarg( $err_file ),
        array(
            'return'     => 'all',
            'exit_error' => false,
        )
    );
    $result->stdout = (string) @file_get_contents( $out_file );
    $result->stderr = (string) @file_get_contents( $err_file );
    @unlink( $out_file );
    @unlink( $err_file );

    // 输出结果
    if ( ! empty( $result->stdout ) ) {
        echo $result->stdout . "\n";
    }

    if ( $result->return_code !== 0 && ! empty( $result->stderr ) ) {
        echo "警告: " . $result->stderr . "\n";
    }

    // 统计导入数量
    $courses_count = wp_count_posts( 'course' );
    $lessons_count = wp_count_posts( 'lesson' );

    echo "\n导入统计:\n";
    echo "  - 课程: " . ( $courses_count->publish ?? 0 ) . " 个\n";
    echo "  - 课时: " . ( $lessons_count->publish ?? 0 ) . " 个\n";

} catch ( Exception $e ) {
    echo "错误: " . $e->getMessage() . "\n";
    // 清理临时文件
    @unlink( $courses_local );
    @unlink( $lessons_local );
    exit( 1 );
}

// 清理临时文件
echo "\n清理临时文件...\n";
@unlink( $courses_local );
@unlink( $lessons_local );

echo "Sensei LMS 导入完成!\n";
