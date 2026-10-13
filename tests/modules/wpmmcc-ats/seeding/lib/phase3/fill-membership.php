<?php
/**
 * Phase 3: Membership 填充模块
 *
 * 填充 Restrict Content 会员等级和内容限制配置
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 填充会员配置
 *
 * @param array $config 配置数据
 * @param bool  $dry_run 是否干运行
 * @return array 结果
 */
function seed_phase3_fill_membership( $config, $dry_run = false ) {
    $result = array(
        'success' => false,
        'items'   => 0,
        'details' => array(),
    );

    // 检查 Restrict Content 是否激活
    if ( ! function_exists( 'rcp_get_subscription_levels' ) && ! class_exists( 'RCP_Levels' ) ) {
        seed_log( '  Restrict Content 未激活或版本不兼容', 'warning' );
        return $result;
    }

    $membership_levels = $config['membership_levels'] ?? array();
    $restricted_content = $config['restricted_content'] ?? array();
    $registration_config = $config['registration_config'] ?? array();

    $created = 0;

    // 1. 创建会员等级
    seed_log( '  创建会员等级...' );
    foreach ( $membership_levels as $level ) {
        if ( $dry_run ) {
            seed_log( "    [Dry Run] 会创建等级: {$level['name']}" );
            $created++;
            continue;
        }

        $level_created = seed_phase3_create_membership_level( $level );
        if ( $level_created ) {
            seed_log( "    创建等级: {$level['name']}", 'success' );
            $result['details'][] = array(
                'type'   => 'level',
                'name'   => $level['name'],
                'slug'   => $level['slug'],
            );
            $created++;
        }
    }

    // 2. 设置内容限制（通过 meta 或选项）
    seed_log( '  配置内容限制...' );
    foreach ( $restricted_content as $restriction ) {
        $post_type = $restriction['post_type'] ?? '';
        if ( empty( $post_type ) ) {
            continue;
        }

        if ( $dry_run ) {
            seed_log( "    [Dry Run] 会配置限制: $post_type" );
            $created++;
            continue;
        }

        $restriction_set = seed_phase3_set_content_restriction( $restriction );
        if ( $restriction_set ) {
            seed_log( "    配置限制: $post_type", 'success' );
            $result['details'][] = array(
                'type'      => 'restriction',
                'post_type' => $post_type,
            );
            $created++;
        }
    }

    // 3. 创建注册页面
    if ( ! empty( $registration_config['create_page'] ) ) {
        $page_title = $registration_config['page_title'] ?? '会员注册';
        $page_slug = $registration_config['page_slug'] ?? 'membership-register';

        if ( $dry_run ) {
            seed_log( "    [Dry Run] 会创建页面: $page_title" );
            $created++;
        } else {
            $page_id = seed_phase3_create_membership_page( $page_title, $page_slug );
            if ( $page_id ) {
                seed_log( "    创建页面: $page_title (ID: $page_id)", 'success' );
                $result['details'][] = array(
                    'type'    => 'page',
                    'title'   => $page_title,
                    'post_id' => $page_id,
                );
                $created++;
            }
        }
    }

    $result['success'] = $created > 0;
    $result['items'] = $created;

    return $result;
}

/**
 * 创建会员等级
 *
 * @param array $level 等级配置
 * @return bool 是否成功
 */
function seed_phase3_create_membership_level( $level ) {
    global $wpdb;

    // 检查是否已存在
    $table = $wpdb->prefix . 'rcp_subscription_levels';
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE name = %s",
        $level['name']
    ) );

    if ( $exists ) {
        return true; // 已存在，跳过
    }

    // 插入新等级
    $data = array(
        'name'          => $level['name'],
        'description'   => $level['description'] ?? '',
        'duration'      => $level['duration'] ?? 0,
        'duration_unit' => 'day',
        'price'         => $level['price'] ?? 0,
        'status'        => 'active',
        'role'          => 'subscriber',
    );

    // 尝试使用 RCP API
    if ( function_exists( 'rcp_add_subscription' ) ) {
        $result = rcp_add_subscription( $data );
        return ! is_wp_error( $result );
    }

    // 直接数据库插入作为后备
    $inserted = $wpdb->insert( $table, $data );
    return $inserted !== false;
}

/**
 * 设置内容限制
 *
 * @param array $restriction 限制配置
 * @return bool 是否成功
 */
function seed_phase3_set_content_restriction( $restriction ) {
    $post_type = $restriction['post_type'];
    $rules = $restriction['rules'] ?? array();

    // 获取该类型的文章
    $posts = get_posts( array(
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );

    if ( empty( $posts ) ) {
        return true;
    }

    $updated = 0;

    foreach ( $rules as $rule ) {
        $condition = $rule['condition'] ?? '';
        $access = $rule['access'] ?? '';

        if ( empty( $access ) || $access === 'public' ) {
            continue;
        }

        switch ( $condition ) {
            case 'first_n_posts':
                // 前 N 篇免费，其余需要会员
                $n = (int) ( $rule['value'] ?? 3 );
                $restricted_posts = array_slice( $posts, $n );
                foreach ( $restricted_posts as $post_id ) {
                    update_post_meta( $post_id, 'rcp_access_level', $access );
                    update_post_meta( $post_id, '_rcp_restricted', 1 );
                    $updated++;
                }
                break;

            case 'all':
                // 全部需要会员
                foreach ( $posts as $post_id ) {
                    update_post_meta( $post_id, 'rcp_access_level', $access );
                    update_post_meta( $post_id, '_rcp_restricted', 1 );
                    $updated++;
                }
                break;

            case 'meta_fields':
                // 特定字段需要会员（标记整个文章）
                foreach ( $posts as $post_id ) {
                    update_post_meta( $post_id, 'rcp_access_level', $access );
                    $updated++;
                }
                break;

            case 'content_preview':
                // 内容预览模式
                foreach ( $posts as $post_id ) {
                    update_post_meta( $post_id, 'rcp_access_level', $access );
                    update_post_meta( $post_id, 'rcp_show_excerpt', 1 );
                    $updated++;
                }
                break;
        }
    }

    return $updated > 0;
}

/**
 * 创建会员注册页面
 *
 * @param string $title 页面标题
 * @param string $slug 页面别名
 * @return int|false 页面 ID 或 false
 */
function seed_phase3_create_membership_page( $title, $slug ) {
    // 检查页面是否已存在
    $existing = get_page_by_path( $slug );
    if ( $existing ) {
        return $existing->ID;
    }

    // 创建页面内容
    $content = '<!-- wp:paragraph -->
<p>成为会员，解锁更多精彩内容！</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>会员等级</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>[rcp_subscription_levels]</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>注册表单</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>[rcp_registration_form]</p>
<!-- /wp:paragraph -->';

    $page_id = wp_insert_post( array(
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );

    return $page_id ?: false;
}
