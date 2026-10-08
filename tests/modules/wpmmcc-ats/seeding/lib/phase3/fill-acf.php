<?php
/**
 * Phase 3: ACF 字段填充
 *
 * 创建 ACF 字段组并为内容填充字段值
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 填充 ACF 数据
 *
 * @param array $config JSON 配置
 * @param bool  $dry_run 是否干运行
 * @return array 执行结果
 */
function seed_phase3_fill_acf( $config, $dry_run = false ) {
    $result = array(
        'success' => false,
        'items'   => 0,
        'details' => array(),
    );

    // 检查 ACF 是否可用
    if ( ! function_exists( 'acf_add_local_field_group' ) ) {
        seed_log( '  ACF: 函数不可用', 'error' );
        return $result;
    }

    $field_groups = $config['field_groups'] ?? array();

    if ( empty( $field_groups ) ) {
        seed_log( '  ACF: 无字段组配置', 'warning' );
        return $result;
    }

    $total_items = 0;

    foreach ( $field_groups as $group ) {
        $group_key = $group['key'] ?? '';
        $group_title = $group['title'] ?? '未命名字段组';
        $post_types = $group['post_types'] ?? array();
        $fields = $group['fields'] ?? array();
        $sample_values = $group['sample_values'] ?? array();

        if ( empty( $group_key ) || empty( $post_types ) || empty( $fields ) ) {
            seed_log( "  跳过字段组: 配置不完整", 'warning' );
            continue;
        }

        // Step 1: 注册字段组（本地，非数据库持久化）
        if ( ! $dry_run ) {
            $acf_fields = array();
            foreach ( $fields as $field ) {
                $acf_fields[] = array(
                    'key'   => $field['key'],
                    'name'  => $field['name'],
                    'label' => $field['label'],
                    'type'  => $field['type'] ?? 'text',
                );
            }

            // 构建 location 规则
            $location = array();
            foreach ( $post_types as $pt ) {
                $location[] = array(
                    array(
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => $pt,
                    ),
                );
            }

            acf_add_local_field_group( array(
                'key'      => $group_key,
                'title'    => $group_title,
                'fields'   => $acf_fields,
                'location' => $location,
            ) );

            seed_log( "  字段组: $group_title", 'success' );
        }

        // Step 2: 为内容填充字段值
        if ( ! empty( $sample_values ) ) {
            $value_index = 0;
            $values_count = count( $sample_values );

            foreach ( $post_types as $post_type ) {
                if ( ! post_type_exists( $post_type ) ) {
                    continue;
                }

                $posts = get_posts( array(
                    'post_type'      => $post_type,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                ) );

                foreach ( $posts as $post ) {
                    if ( $dry_run ) {
                        $total_items++;
                        continue;
                    }

                    // 循环使用 sample_values
                    $values = $sample_values[ $value_index % $values_count ];
                    $value_index++;

                    // 写入字段值
                    foreach ( $values as $field_name => $field_value ) {
                        // 使用 update_field（ACF 函数）或 update_post_meta
                        if ( function_exists( 'update_field' ) ) {
                            update_field( $field_name, $field_value, $post->ID );
                        } else {
                            update_post_meta( $post->ID, $field_name, $field_value );
                        }
                    }

                    $total_items++;
                }
            }

            seed_log( "  填充字段值: $total_items 条" );
        }
    }

    $result['success'] = $total_items > 0 || ! empty( $field_groups );
    $result['items'] = $total_items;

    return $result;
}
