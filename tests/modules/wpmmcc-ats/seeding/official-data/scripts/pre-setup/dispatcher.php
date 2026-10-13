<?php
/**
 * Pre-Setup 调度器
 *
 * 在填充数据前执行所有必要的预配置脚本
 *
 * 执行方式:
 *   wp eval-file dispatcher.php A
 *
 * 执行时机: 激活插件后、填充数据前
 *
 * @package WPTSALL\DevTools\Seeding\PreSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// wp eval-file 通过 $args 数组传递参数
$args = $args ?? array();

// 解析计划 ID（默认 A）
$plan_id = 'A';
foreach ( $args as $arg ) {
    if ( preg_match( '/^[A-E]$/i', $arg ) ) {
        $plan_id = strtoupper( $arg );
        break;
    }
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Pre-Setup 调度器（填充前配置）                              ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  计划: $plan_id                                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

$pre_setup_dir = __DIR__;

// 所有计划通用的预配置脚本
$common_scripts = array(
    'lms-url-conflict-resolver.php' => 'LMS URL 冲突解决',
    'install-edd-extensions-compat.php' => 'EDD Extensions API 兼容补丁（Lab 无出网容错）',
);

// 计划特定的预配置脚本
$plan_scripts = array(
    'A' => array(),
    'B' => array(),
    'C' => array(),
    'D' => array(),
    'E' => array(),
);

$executed = array();
$skipped = array();

// 执行通用脚本
echo "--- 执行通用预配置 ---\n\n";
foreach ( $common_scripts as $script => $description ) {
    $script_path = $pre_setup_dir . '/' . $script;

    if ( file_exists( $script_path ) ) {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "执行: $description\n";
        echo "═══════════════════════════════════════════════════════════════\n";

        include $script_path;

        $executed[] = $script;
        echo "\n";
    } else {
        $skipped[] = $script;
    }
}

// 执行计划特定脚本
if ( ! empty( $plan_scripts[ $plan_id ] ) ) {
    echo "--- 执行计划 $plan_id 特定配置 ---\n\n";

    foreach ( $plan_scripts[ $plan_id ] as $script => $description ) {
        $script_path = $pre_setup_dir . '/' . $script;

        if ( file_exists( $script_path ) ) {
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "执行: $description\n";
            echo "═══════════════════════════════════════════════════════════════\n";

            include $script_path;

            $executed[] = $script;
            echo "\n";
        } else {
            $skipped[] = $script;
        }
    }
}

// 总结
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Pre-Setup 完成                                              ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf( "║  执行了 %d 个预配置脚本                                       ║\n", count( $executed ) );
echo "╚══════════════════════════════════════════════════════════════╝\n";

if ( ! empty( $executed ) ) {
    echo "\n已执行:\n";
    foreach ( $executed as $script ) {
        echo "  ✓ $script\n";
    }
}

echo "\n";
echo "现在可以开始填充数据了。\n";

return array(
    'success'  => true,
    'executed' => $executed,
    'skipped'  => $skipped,
);
