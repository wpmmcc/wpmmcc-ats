<?php
/**
 * Phase 3: 表格填充
 *
 * 创建 TablePress 表格并嵌入内容
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 填充表格数据
 *
 * @param array $config JSON 配置
 * @param bool  $dry_run 是否干运行
 * @return array 执行结果
 */
function seed_phase3_fill_tables( $config, $dry_run = false ) {
    $result = array(
        'success' => false,
        'items'   => 0,
        'details' => array(),
    );

    $tables = $config['tables'] ?? array();

    if ( empty( $tables ) ) {
        seed_log( '  表格: 无配置', 'warning' );
        return $result;
    }

    // 检查 TablePress 是否可用
    $tablepress_active = class_exists( 'TablePress' ) || defined( 'TABLEPRESS_ABSPATH' );
    if ( ! $tablepress_active ) {
        seed_log( '  TablePress: 不可用', 'error' );
        return $result;
    }

    $count = 0;

    foreach ( $tables as $table_config ) {
        $table_name = $table_config['name'] ?? '';
        $table_slug = $table_config['slug'] ?? sanitize_title( $table_name );
        $columns = $table_config['columns'] ?? array();
        $data = $table_config['data'] ?? array();
        $embed_config = $table_config['embed_config'] ?? array();

        if ( empty( $table_name ) || empty( $columns ) ) {
            seed_log( "  跳过表格: 配置不完整", 'warning' );
            continue;
        }

        if ( $dry_run ) {
            $count++;
            continue;
        }

        // Step 1: 检查表格是否已存在
        $existing = get_posts( array(
            'post_type'      => 'tablepress_table',
            'title'          => $table_name,
            'posts_per_page' => 1,
        ) );

        $table_id = null;

        // 构建表格数据（表头 + 数据行）
        $table_data = array( $columns );
        foreach ( $data as $row ) {
            $table_data[] = $row;
        }

        if ( ! empty( $existing ) ) {
            $table_id = $existing[0]->ID;

            // 更新表格内容
            wp_update_post( array(
                'ID'           => $table_id,
                'post_content' => wp_json_encode( $table_data ),
            ) );

            seed_log( "  表格已存在: $table_name (ID: $table_id)" );
        } else {
            // 创建新表格
            $table_id = wp_insert_post( array(
                'post_type'    => 'tablepress_table',
                'post_title'   => $table_name,
                'post_name'    => $table_slug,
                'post_status'  => 'publish',
                'post_content' => wp_json_encode( $table_data ),
            ) );

            if ( $table_id ) {
                // TablePress 使用 option 存储表格元数据
                $table_options = array(
                    'id'                  => $table_id,
                    'name'                => $table_name,
                    'description'         => $table_config['description'] ?? '',
                    'last_modified'       => current_time( 'mysql' ),
                    'last_modified_by'    => get_current_user_id(),
                    'options'             => array(
                        'table_head'       => true,
                        'table_foot'       => false,
                        'alternating_row_colors' => true,
                        'row_hover'        => true,
                    ),
                );

                update_post_meta( $table_id, '_tablepress_table_options', $table_options );

                seed_log( "  创建表格: $table_name (ID: $table_id)", 'success' );
            }
        }

        // Step 2: 嵌入内容
        if ( $table_id && ! empty( $embed_config ) ) {
            $shortcode = '[table id=' . $table_id . ' /]';

            // 创建独立页面
            if ( ! empty( $embed_config['create_page'] ) ) {
                $page_title = $embed_config['page_title'] ?? $table_name;
                $page_slug = $embed_config['page_slug'] ?? $table_slug;

                $existing_page = get_page_by_path( $page_slug );

                if ( $existing_page ) {
                    wp_update_post( array(
                        'ID'           => $existing_page->ID,
                        'post_content' => '<h2>' . esc_html( $table_name ) . '</h2>' . "\n\n" . $shortcode,
                    ) );
                    seed_log( "  更新页面: $page_title" );
                } else {
                    $page_id = wp_insert_post( array(
                        'post_type'    => 'page',
                        'post_title'   => $page_title,
                        'post_name'    => $page_slug,
                        'post_status'  => 'publish',
                        'post_content' => '<h2>' . esc_html( $table_name ) . '</h2>' . "\n\n" . $shortcode,
                    ) );

                    if ( $page_id ) {
                        seed_log( "  创建页面: $page_title (ID: $page_id)", 'success' );
                    }
                }
            }

            // 嵌入到 post_type 的内容中
            if ( ! empty( $embed_config['post_types'] ) ) {
                foreach ( $embed_config['post_types'] as $post_type ) {
                    if ( ! post_type_exists( $post_type ) ) {
                        continue;
                    }

                    // 只嵌入到第一篇内容（作为示例）
                    $posts = get_posts( array(
                        'post_type'      => $post_type,
                        'post_status'    => 'publish',
                        'posts_per_page' => 1,
                        'orderby'        => 'date',
                        'order'          => 'ASC',
                    ) );

                    if ( ! empty( $posts ) ) {
                        $post = $posts[0];
                        $position = $embed_config['embed_position'] ?? 'after_content';

                        $new_content = $post->post_content;
                        $table_html = "\n\n<h3>" . esc_html( $table_name ) . "</h3>\n" . $shortcode;

                        if ( $position === 'before_content' ) {
                            $new_content = $table_html . "\n\n" . $new_content;
                        } else {
                            $new_content = $new_content . $table_html;
                        }

                        wp_update_post( array(
                            'ID'           => $post->ID,
                            'post_content' => $new_content,
                        ) );

                        seed_log( "  嵌入表格到: {$post->post_title}" );
                    }
                }
            }
        }

        $count++;
    }

    $result['success'] = $count > 0;
    $result['items'] = $count;

    return $result;
}
