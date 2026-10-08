<?php
/**
 * Phase 3: 表单填充
 *
 * 创建 Contact Form 7 表单并嵌入页面
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 填充表单数据
 *
 * @param array $config JSON 配置
 * @param bool  $dry_run 是否干运行
 * @return array 执行结果
 */
function seed_phase3_fill_forms( $config, $dry_run = false ) {
    $result = array(
        'success' => false,
        'items'   => 0,
        'details' => array(),
    );

    $forms = $config['forms'] ?? array();

    if ( empty( $forms ) ) {
        seed_log( '  表单: 无配置', 'warning' );
        return $result;
    }

    // 检查 CF7 是否可用
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        seed_log( '  CF7: 类不可用', 'error' );
        return $result;
    }

    $count = 0;

    foreach ( $forms as $form_config ) {
        $form_name = $form_config['name'] ?? '';
        $form_slug = $form_config['slug'] ?? sanitize_title( $form_name );
        $form_content = $form_config['form_content'] ?? '';
        $embed_config = $form_config['embed_config'] ?? array();

        if ( empty( $form_name ) || empty( $form_content ) ) {
            seed_log( "  跳过表单: 配置不完整", 'warning' );
            continue;
        }

        if ( $dry_run ) {
            $count++;
            continue;
        }

        // Step 1: 检查表单是否已存在（通过名称查找）
        $existing = get_posts( array(
            'post_type'      => 'wpcf7_contact_form',
            'title'          => $form_name,
            'posts_per_page' => 1,
        ) );

        $form_id = null;

        if ( ! empty( $existing ) ) {
            $form_id = $existing[0]->ID;
            seed_log( "  表单已存在: $form_name (ID: $form_id)" );
        } else {
            // 创建新表单
            $cf7 = WPCF7_ContactForm::get_template();
            $cf7->set_title( $form_name );
            $cf7->set_properties( array(
                'form' => $form_content,
            ) );

            // 设置邮件模板
            if ( ! empty( $form_config['mail_template'] ) ) {
                $mail = $cf7->prop( 'mail' );
                $mail['subject'] = $form_config['mail_template']['subject'] ?? $mail['subject'];
                $mail['body'] = $form_config['mail_template']['body'] ?? $mail['body'];
                $cf7->set_properties( array( 'mail' => $mail ) );
            }

            $form_id = $cf7->save();
            seed_log( "  创建表单: $form_name (ID: $form_id)", 'success' );
        }

        // Step 2: 嵌入页面
        if ( $form_id && ! empty( $embed_config ) ) {
            $shortcode = '[contact-form-7 id="' . $form_id . '" title="' . esc_attr( $form_name ) . '"]';

            if ( ! empty( $embed_config['create_page'] ) ) {
                $page_title = $embed_config['page_title'] ?? $form_name;
                $page_slug = $embed_config['page_slug'] ?? $form_slug;

                // 检查页面是否已存在
                $existing_page = get_page_by_path( $page_slug );

                if ( $existing_page ) {
                    // 更新页面内容
                    wp_update_post( array(
                        'ID'           => $existing_page->ID,
                        'post_content' => $shortcode,
                    ) );
                    seed_log( "  更新页面: $page_title" );
                } else {
                    // 创建新页面
                    $page_id = wp_insert_post( array(
                        'post_type'    => 'page',
                        'post_title'   => $page_title,
                        'post_name'    => $page_slug,
                        'post_status'  => 'publish',
                        'post_content' => $shortcode,
                    ) );

                    if ( $page_id ) {
                        seed_log( "  创建页面: $page_title (ID: $page_id)", 'success' );
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
