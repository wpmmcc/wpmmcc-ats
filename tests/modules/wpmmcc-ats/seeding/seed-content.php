<?php
/**
 * WPTSALL 数据填充脚本 - 主入口
 *
 * 模块化重构版本，支持分阶段执行
 *
 * Usage:
 *   wp eval-file seed-content.php A              # 执行所有阶段
 *   wp eval-file seed-content.php A phase 1      # 只执行 Phase 1 (官方数据)
 *   wp eval-file seed-content.php A phase 2      # 只执行 Phase 2 (自定义 JSON)
 *   wp eval-file seed-content.php A analyze      # 分析内容 + 生成工具插件配置
 *   wp eval-file seed-content.php A phase 3      # 只执行 Phase 3 (工具关联)
 *   wp eval-file seed-content.php A check        # 检查文件状态
 *
 * 注意：WP-CLI 会消耗 -- 前缀的参数，因此使用不带前缀的格式
 *
 * @package WPTSALL\DevTools\Seeding
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// =========================================================
// 初始化
// =========================================================

// 抑制非致命错误输出
error_reporting( E_ERROR | E_WARNING | E_PARSE );
ini_set( 'display_errors', '0' );
while ( ob_get_level() ) {
    ob_end_clean();
}

// 设置管理员权限
wp_set_current_user( 1 );

// 加载公共函数
require_once __DIR__ . '/lib/seed-helpers.php';

// 加载工作流辅助函数
if ( file_exists( dirname( __DIR__ ) . '/lib/workflow-helpers.php' ) ) {
    require_once dirname( __DIR__ ) . '/lib/workflow-helpers.php';
}

// =========================================================
// 解析参数
// =========================================================

$args = $args ?? array();

// 解析计划 ID
$plan_id = 'A'; // 默认
foreach ( $args as $arg ) {
    if ( preg_match( '/^[A-E]$/i', $arg ) ) {
        $plan_id = strtoupper( $arg );
        break;
    }
}

// 解析选项（支持 --flag 和 flag 两种格式，因为 WP-CLI 可能消耗 -- 前缀）
$phase = null;
$check_mode = false;
$analyze_mode = false;
$dry_run = false;

foreach ( $args as $idx => $arg ) {
    $arg_lower = strtolower( $arg );

    // 支持 --phase=N, phase=N, phase:N 格式
    if ( preg_match( '/^-*phase[=:]?(\d+)$/i', $arg, $matches ) ) {
        $phase = (int) $matches[1];
    }
    // 支持 phase N 格式（下一个参数是数字）
    if ( $arg_lower === 'phase' && isset( $args[ $idx + 1 ] ) && is_numeric( $args[ $idx + 1 ] ) ) {
        $phase = (int) $args[ $idx + 1 ];
    }
    // 支持 --check 和 check 格式
    if ( in_array( $arg_lower, array( '--check', 'check' ), true ) ) {
        $check_mode = true;
    }
    // 支持 --analyze 和 analyze 格式
    if ( in_array( $arg_lower, array( '--analyze', 'analyze' ), true ) ) {
        $analyze_mode = true;
    }
    // 支持 --dry-run, dry-run, dryrun 格式
    if ( in_array( $arg_lower, array( '--dry-run', 'dry-run', 'dryrun' ), true ) ) {
        $dry_run = true;
    }
}

// =========================================================
// 设置计划目录
// =========================================================

$plan_dir = SEEDING_BASE_DIR . '/plans/' . $plan_id;
$results_dir = $plan_dir . '/results';
$utility_dir = $plan_dir . '/utility';

if ( ! is_dir( $results_dir ) ) {
    mkdir( $results_dir, 0755, true );
}
if ( ! is_dir( $utility_dir ) ) {
    mkdir( $utility_dir, 0755, true );
}

define( 'SEED_PLAN_DIR', $plan_dir );
define( 'SEED_UTILITY_DIR', $utility_dir );
define( 'SEED_LOG_FILE', $results_dir . '/seed.log' );
define( 'SEED_RESULT_FILE', $results_dir . '/seeding-result.json' );

// 清空日志
file_put_contents( SEED_LOG_FILE, '' );

// =========================================================
// 输出头部
// =========================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WPTSALL 数据填充脚本 v2.0.0                                 ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  计划: $plan_id                                                       ║\n";

if ( $analyze_mode ) {
    echo "║  模式: 分析 + 生成配置                                         ║\n";
} elseif ( $phase !== null ) {
    echo "║  阶段: Phase $phase                                                  ║\n";
} else {
    echo "║  阶段: 全部                                                    ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// =========================================================
// 检查模式
// =========================================================

if ( $check_mode ) {
    seed_log_phase( "检查模式: Plan $plan_id" );

    // 检查 Phase 1 文件
    require_once __DIR__ . '/lib/phase1/dispatcher.php';
    $phase1_status = seed_phase1_check_files( $plan_id );

    seed_log( "\n--- Phase 1: 官方数据文件 ---" );
    foreach ( $phase1_status as $slug => $info ) {
        $status = $info['status'];
        $icon = $status === 'ready' ? '✓' : '✗';
        seed_log( "  $icon $slug: $status" );
    }

    // 检查 Phase 2 文件
    require_once __DIR__ . '/lib/phase2/json-seeder.php';
    $phase2_status = seed_phase2_check_files( $plan_id );

    seed_log( "\n--- Phase 2: seed-data JSON 文件 ---" );
    foreach ( $phase2_status as $slug => $info ) {
        $status = $info['status'];
        $icon = $status === 'ready' ? '✓' : '✗';
        $detail = $status === 'ready' ? "{$info['post_count']} posts" : $info['message'];
        seed_log( "  $icon $slug: $detail" );
    }

    // 检查 Phase 3 状态
    require_once __DIR__ . '/lib/phase3/dispatcher.php';
    $phase3_status = seed_phase3_check_status( $plan_id );

    seed_log( "\n--- Phase 3: 工具插件状态 ---" );
    seed_log( "  关联计划: " . ( $phase3_status['plan_exists'] ? '已生成' : '未生成' ) );
    foreach ( $phase3_status['utilities_ready'] as $slug => $active ) {
        $icon = $active ? '✓' : '✗';
        seed_log( "  $icon $slug: " . ( $active ? '已激活' : '未激活' ) );
    }

    exit( 0 );
}

// =========================================================
// 分析模式：分析内容 + 生成工具插件配置
// =========================================================

if ( $analyze_mode ) {
    seed_log_phase( "分析模式: Plan $plan_id" );

    require_once __DIR__ . '/lib/phase3/analyze.php';

    $analyze_result = seed_phase3_analyze_and_generate( $plan_id );

    if ( $analyze_result['success'] ) {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║  分析完成！                                                  ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        printf( "║  分析内容: %-5d 条                                         ║\n", $analyze_result['content_count'] );
        printf( "║  生成配置: %-5d 个                                         ║\n", $analyze_result['config_count'] );
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  生成的配置文件:                                             ║\n";

        foreach ( $analyze_result['generated_files'] as $file ) {
            $filename = basename( $file );
            printf( "║    - %-52s ║\n", $filename );
        }

        echo "╚══════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo "请检查生成的配置文件，确认后执行 Phase 3:\n";
        echo "  wp eval-file " . __FILE__ . " $plan_id phase 3\n";
        echo "\n";
    } else {
        echo "\n";
        echo "分析失败: " . ( $analyze_result['error'] ?? '未知错误' ) . "\n";
        echo "\n";
    }

    exit( 0 );
}

// =========================================================
// 初始化结果存储
// =========================================================

seed_init_results();

// =========================================================
// 执行填充
// =========================================================

$stats = array(
    'phase1' => null,
    'phase2' => null,
    'phase3' => null,
);

// Phase 1: 官方数据导入
if ( $phase === null || $phase === 1 ) {
    require_once __DIR__ . '/lib/phase1/dispatcher.php';
    $stats['phase1'] = seed_phase1_execute( $plan_id );
}

// Phase 2: 自定义 JSON 填充
if ( $phase === null || $phase === 2 ) {
    require_once __DIR__ . '/lib/phase2/json-seeder.php';
    $stats['phase2'] = seed_phase2_execute( $plan_id );
}

// Phase 3: 工具插件关联
if ( $phase === null || $phase === 3 ) {
    require_once __DIR__ . '/lib/phase3/dispatcher.php';
    $stats['phase3'] = seed_phase3_execute( $plan_id, array(
        'dry_run' => $dry_run,
    ) );
}

// =========================================================
// 保存结果
// =========================================================

seed_save_results( SEED_RESULT_FILE );

// =========================================================
// 输出统计
// =========================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  数据填充完成！                                              ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  Plan: $plan_id                                                       ║\n";

$total_stats = seed_get_stats();
$success = $total_stats['success'];
$failed = $total_stats['failed'];
$total = $total_stats['total'];

printf( "║  成功: %-5d 失败: %-5d 总计: %-5d                        ║\n", $success, $failed, $total );
echo "╠══════════════════════════════════════════════════════════════╣\n";

if ( $stats['phase1'] ) {
    $p1 = $stats['phase1'];
    printf( "║  Phase 1: 成功 %d, 失败 %d, 跳过 %d                            ║\n",
        $p1['success'], $p1['failed'], $p1['skipped'] );
}

if ( $stats['phase2'] ) {
    $p2 = $stats['phase2'];
    printf( "║  Phase 2: 成功 %d, 失败 %d, 内容 %d 条                         ║\n",
        $p2['success'], $p2['failed'], $p2['items'] ?? 0 );
}

if ( $stats['phase3'] ) {
    echo "║  Phase 3: 完成                                              ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "结果已保存到: " . SEED_RESULT_FILE . "\n";
echo "日志已保存到: " . SEED_LOG_FILE . "\n";

$phase_failed = false;
$phase1_only_failed = false;
foreach ( array( 'phase1', 'phase2', 'phase3' ) as $phase_key ) {
    if ( is_array( $stats[ $phase_key ] ) && (int) ( $stats[ $phase_key ]['failed'] ?? 0 ) > 0 ) {
        if ( $phase_key === 'phase1' ) {
            $phase1_only_failed = true;
            continue;
        }
        $phase_failed = true;
        break;
    }
}

// Official Phase 1 imports can fatal on third-party install hooks (e.g. Events Manager).
// Content-matrix gating relies on Phase 2 CPT seeds; soft-pass when Phase 2/3 succeed.
if ( $phase1_only_failed && ! $phase_failed ) {
    $p2_ok = is_array( $stats['phase2'] ?? null ) && (int) ( $stats['phase2']['failed'] ?? 0 ) === 0;
    if ( $p2_ok ) {
        seed_log( 'Phase 1 had failures but Phase 2 succeeded; treating seed plan as soft-pass', 'warning' );
        $phase1_only_failed = false;
    } else {
        $phase_failed = true;
    }
}

if ( $phase_failed || $failed > 0 ) {
    exit( 1 );
}
