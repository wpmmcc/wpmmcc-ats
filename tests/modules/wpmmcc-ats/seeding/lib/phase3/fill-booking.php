<?php
/**
 * Phase 3: 预约系统填充
 *
 * 创建 Simply Schedule Appointments 预约类型并嵌入页面
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 填充预约数据
 *
 * @param array $config JSON 配置
 * @param bool  $dry_run 是否干运行
 * @return array 执行结果
 */
function seed_phase3_fill_booking( $config, $dry_run = false ) {
    $result = array(
        'success' => false,
        'items'   => 0,
        'details' => array(),
    );

    $appointment_types = $config['appointment_types'] ?? array();

    if ( empty( $appointment_types ) ) {
        seed_log( '  预约: 无配置', 'warning' );
        return $result;
    }

    // SSA 插件可能使用不同的 API，这里先创建预约页面
    // 实际的预约类型配置可能需要通过插件后台手动设置

    $count = 0;

    foreach ( $appointment_types as $type_config ) {
        $type_name = $type_config['name'] ?? '';
        $type_slug = $type_config['slug'] ?? sanitize_title( $type_name );
        $description = $type_config['description'] ?? '';
        $embed_config = $type_config['embed_config'] ?? array();

        if ( empty( $type_name ) ) {
            seed_log( "  跳过预约类型: 配置不完整", 'warning' );
            continue;
        }

        if ( $dry_run ) {
            $count++;
            continue;
        }

        // Step 1: 检查 SSA 是否有 API 可用
        $ssa_available = class_exists( 'Simply_Schedule_Appointments' ) ||
                         function_exists( 'ssa' ) ||
                         defined( 'SSA_PLUGIN_FILE' );

        // Step 2: 创建预约页面
        if ( ! empty( $embed_config['create_page'] ) ) {
            $page_title = $embed_config['page_title'] ?? $type_name;
            $page_slug = $embed_config['page_slug'] ?? $type_slug;

            // SSA 短代码格式（根据实际插件调整）
            $shortcode = '[ssa_booking type="' . esc_attr( $type_slug ) . '"]';

            // 如果 SSA 不可用，创建占位内容
            if ( ! $ssa_available ) {
                $page_content = '<div class="booking-placeholder">' . "\n";
                $page_content .= '<h2>' . esc_html( $type_name ) . '</h2>' . "\n";
                $page_content .= '<p>' . esc_html( $description ) . '</p>' . "\n";
                $page_content .= '<p><strong>预约功能配置中...</strong></p>' . "\n";
                $page_content .= '<p>预约时长: ' . ( $type_config['duration'] ?? 30 ) . ' 分钟</p>' . "\n";
                $page_content .= '</div>' . "\n";
                $page_content .= '<!-- SSA shortcode: ' . $shortcode . ' -->';
            } else {
                $page_content = '<h2>' . esc_html( $type_name ) . '</h2>' . "\n";
                $page_content .= '<p>' . esc_html( $description ) . '</p>' . "\n\n";
                $page_content .= $shortcode;
            }

            $existing_page = get_page_by_path( $page_slug );

            if ( $existing_page ) {
                wp_update_post( array(
                    'ID'           => $existing_page->ID,
                    'post_content' => $page_content,
                ) );
                seed_log( "  更新预约页面: $page_title" );
            } else {
                $page_id = wp_insert_post( array(
                    'post_type'    => 'page',
                    'post_title'   => $page_title,
                    'post_name'    => $page_slug,
                    'post_status'  => 'publish',
                    'post_content' => $page_content,
                ) );

                if ( $page_id ) {
                    seed_log( "  创建预约页面: $page_title (ID: $page_id)", 'success' );
                }
            }
        }

        // Step 3: 记录预约类型配置（供后续手动配置参考）
        $config_note = array(
            'name'        => $type_name,
            'slug'        => $type_slug,
            'duration'    => $type_config['duration'] ?? 30,
            'availability' => $type_config['availability'] ?? array(),
            'form_fields' => $type_config['form_fields'] ?? array(),
            'related_to'  => $type_config['related_post_types'] ?? array(),
        );

        $result['details'][] = $config_note;

        $count++;
    }

    // 如果 SSA 不可用，输出配置提示
    if ( ! $ssa_available && $count > 0 ) {
        seed_log( '  注意: SSA 插件需要在后台手动配置预约类型', 'warning' );

        // 保存配置提示到文件
        $config_file = SEEDING_BASE_DIR . '/results/plan-a/booking-config-notes.json';
        file_put_contents(
            $config_file,
            json_encode( $result['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
        );
        seed_log( "  配置参考已保存: $config_file" );
    }

    $result['success'] = $count > 0;
    $result['items'] = $count;

    return $result;
}
