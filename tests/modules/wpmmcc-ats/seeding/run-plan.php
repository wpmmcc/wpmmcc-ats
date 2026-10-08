<?php
/**
 * 内容插件数据填充工作流 - 主入口
 *
 * 为内容插件（WooCommerce、bbPress、LMS 等）填充测试数据的工作流。
 * 此工作流与 WPTSALL 翻译插件无关，WPTSALL 作为公共插件保持启用即可。
 *
 * 工作流阶段：
 * 1. Pre-Setup: 前置配置（如 LMS URL 冲突解决）
 * 2. Seed: 数据填充 (Phase 1-3)
 * 3. Post-Setup: 后置配置（如页面创建）
 * 4. Verify: 验证测试
 *
 * Usage:
 *   wp eval-file run-plan.php A              # 完整工作流
 *   wp eval-file run-plan.php A pre          # 只执行 Pre-Setup
 *   wp eval-file run-plan.php A seed         # 只执行数据填充 (Phase 1-3)
 *   wp eval-file run-plan.php A post         # 只执行 Post-Setup
 *   wp eval-file run-plan.php A verify       # 只执行验证
 *   wp eval-file run-plan.php A full         # 完整工作流（默认）
 *
 * @package DevTools\Seeding
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// =========================================================
// 初始化
// =========================================================

error_reporting( E_ERROR | E_WARNING | E_PARSE );
ini_set( 'display_errors', '0' );
while ( ob_get_level() ) {
    ob_end_clean();
}

wp_set_current_user( 1 );

// =========================================================
// 解析参数
// =========================================================

$args = $args ?? array();

// 解析计划 ID
$plan_id = 'A';
foreach ( $args as $arg ) {
    if ( preg_match( '/^[A-E]$/i', $arg ) ) {
        $plan_id = strtoupper( $arg );
        break;
    }
}

// 解析阶段
$stage = 'full'; // 默认完整流程
$valid_stages = array( 'pre', 'seed', 'post', 'verify', 'full' );

foreach ( $args as $arg ) {
    $arg_lower = strtolower( $arg );
    if ( in_array( $arg_lower, $valid_stages, true ) ) {
        $stage = $arg_lower;
        break;
    }
}

// =========================================================
// 设置路径
// =========================================================

define( 'SEEDING_BASE_DIR', __DIR__ );

$plan_dir = SEEDING_BASE_DIR . '/plans/' . $plan_id;
$results_dir = $plan_dir . '/results';
$scripts_dir = SEEDING_BASE_DIR . '/official-data/scripts';

if ( ! is_dir( $results_dir ) ) {
    mkdir( $results_dir, 0755, true );
}

// =========================================================
// 输出头部
// =========================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  内容插件数据填充工作流 v1.0.0                               ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  计划: $plan_id                                                       ║\n";

$stage_names = array(
    'pre'    => 'Pre-Setup (前置配置)',
    'seed'   => 'Seed (数据填充)',
    'post'   => 'Post-Setup (后置配置)',
    'verify' => 'Verify (验证测试)',
    'full'   => 'Full (完整工作流)',
);
printf( "║  阶段: %-48s ║\n", $stage_names[ $stage ] );
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// =========================================================
// 结果跟踪
// =========================================================

// Use global to make it accessible in functions
$GLOBALS['workflow_results'] = array(
    'plan_id'   => $plan_id,
    'stage'     => $stage,
    'timestamp' => date( 'Y-m-d H:i:s' ),
    'steps'     => array(),
);

/**
 * 记录步骤结果
 */
function workflow_record_step( $step_name, $success, $details = array() ) {
    $GLOBALS['workflow_results']['steps'][ $step_name ] = array(
        'success' => $success,
        'details' => $details,
        'time'    => date( 'H:i:s' ),
    );
}

// =========================================================
// Step 1: Pre-Setup (前置配置)
// =========================================================

if ( $stage === 'full' || $stage === 'pre' ) {
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  Step 1: Pre-Setup (前置配置)                                ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    $pre_setup_file = $scripts_dir . '/pre-setup/dispatcher.php';

    if ( file_exists( $pre_setup_file ) ) {
        $pre_result = include $pre_setup_file;
        $pre_success = is_array( $pre_result ) && ! empty( $pre_result['success'] );
        workflow_record_step( 'pre_setup', $pre_success, $pre_result );

        if ( $pre_success ) {
            echo "\n✓ Pre-Setup 完成\n\n";
        } else {
            echo "\n⚠️ Pre-Setup 有警告，继续执行...\n\n";
        }
    } else {
        echo "  (i) 未找到 pre-setup/dispatcher.php，跳过\n\n";
        workflow_record_step( 'pre_setup', true, array( 'skipped' => true ) );
    }
}

// =========================================================
// Step 2: Seed (数据填充)
// =========================================================

if ( $stage === 'full' || $stage === 'seed' ) {
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  Step 2: Seed (数据填充)                                     ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    // Plan E 使用专用的 seed/dispatcher.php（WordPress 核心内容）
    // Plan A-D 使用 seed-content.php（插件数据）
    if ( $plan_id === 'E' ) {
        $seed_file = $scripts_dir . '/seed/dispatcher.php';

        if ( file_exists( $seed_file ) ) {
            $seed_result = include $seed_file;
            $seed_success = is_array( $seed_result ) && ! empty( $seed_result['success'] );
            workflow_record_step( 'seed', $seed_success, $seed_result );

            if ( $seed_success ) {
                echo "\n✓ Seed 完成 (WordPress 核心内容)\n\n";
            } else {
                echo "\n⚠️ Seed 有警告\n\n";
            }
        } else {
            echo "  ✗ 未找到 seed/dispatcher.php\n\n";
            workflow_record_step( 'seed', false, array( 'error' => 'file_not_found' ) );
        }
    } else {
        $seed_file = SEEDING_BASE_DIR . '/seed-content.php';

        if ( file_exists( $seed_file ) ) {
            // 传递参数给 seed-content.php
            $GLOBALS['seed_plan_id'] = $plan_id;
            include $seed_file;

            // 读取结果
            $seed_result_file = $results_dir . '/seeding-result.json';
            if ( file_exists( $seed_result_file ) ) {
                $seed_result = json_decode( file_get_contents( $seed_result_file ), true );
                workflow_record_step( 'seed', true, $seed_result );
            } else {
                workflow_record_step( 'seed', true, array( 'note' => 'no_result_file' ) );
            }

            echo "\n✓ Seed 完成\n\n";
        } else {
            echo "  ✗ 未找到 seed-content.php\n\n";
            workflow_record_step( 'seed', false, array( 'error' => 'file_not_found' ) );
        }
    }
}

// =========================================================
// Step 3: Post-Setup (后置配置)
// =========================================================

if ( $stage === 'full' || $stage === 'post' ) {
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  Step 3: Post-Setup (后置配置)                               ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    $post_setup_file = $scripts_dir . '/post-setup/dispatcher.php';

    if ( file_exists( $post_setup_file ) ) {
        $post_result = include $post_setup_file;
        $post_success = is_array( $post_result ) && isset( $post_result['executed'] );
        workflow_record_step( 'post_setup', $post_success, $post_result );

        echo "\n✓ Post-Setup 完成\n\n";
    } else {
        echo "  (i) 未找到 post-setup/dispatcher.php，跳过\n\n";
        workflow_record_step( 'post_setup', true, array( 'skipped' => true ) );
    }
}

// =========================================================
// Step 4: Verify (验证测试)
// =========================================================

if ( $stage === 'full' || $stage === 'verify' ) {
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  Step 4: Verify (验证测试)                                   ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    $verify_file = $scripts_dir . '/verify/dispatcher.php';

    if ( file_exists( $verify_file ) ) {
        $verify_result = include $verify_file;
        $verify_success = is_array( $verify_result )
            && isset( $verify_result['overall_rate'] )
            && $verify_result['overall_rate'] >= 95;
        workflow_record_step( 'verify', $verify_success, $verify_result );

        echo "\n";
        if ( $verify_success ) {
            echo "✓ Verify 完成 (通过率: {$verify_result['overall_rate']}%)\n\n";
        } else {
            $rate = isset( $verify_result['overall_rate'] ) ? $verify_result['overall_rate'] : 0;
            echo "⚠️ Verify 完成但通过率不足 (通过率: {$rate}%)\n\n";
        }
    } else {
        echo "  (i) 未找到 verify/dispatcher.php，跳过\n\n";
        workflow_record_step( 'verify', true, array( 'skipped' => true ) );
    }
}

// =========================================================
// 保存工作流结果
// =========================================================

$workflow_result_file = $results_dir . '/workflow-result.json';
$workflow_results = $GLOBALS['workflow_results'];
file_put_contents(
    $workflow_result_file,
    json_encode( $workflow_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
);

// =========================================================
// 输出总结
// =========================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  工作流完成！                                                ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  Plan: $plan_id                                                       ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";

$step_names = array(
    'pre_setup'  => 'Pre-Setup',
    'seed'       => 'Seed',
    'post_setup' => 'Post-Setup',
    'verify'     => 'Verify',
);

foreach ( $GLOBALS['workflow_results']['steps'] as $step => $result ) {
    $name = isset( $step_names[ $step ] ) ? $step_names[ $step ] : $step;
    $status = $result['success'] ? '✓' : '✗';

    // 特殊处理 verify 显示通过率
    if ( $step === 'verify' && isset( $result['details']['overall_rate'] ) ) {
        $rate = $result['details']['overall_rate'];
        printf( "║  %s %-12s %s (%.1f%%)                                ║\n",
            $status, $name . ':', $result['success'] ? '通过' : '警告', $rate );
    } else {
        $detail = '';
        if ( isset( $result['details']['skipped'] ) && $result['details']['skipped'] ) {
            $detail = '(跳过)';
        }
        printf( "║  %s %-12s %s %-28s ║\n",
            $status, $name . ':', $result['success'] ? '完成' : '失败', $detail );
    }
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "结果已保存到: $workflow_result_file\n";
echo "\n";
