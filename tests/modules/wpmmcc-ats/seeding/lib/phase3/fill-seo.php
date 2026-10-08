<?php
/**
 * Phase 3: SEO 数据填充
 *
 * 为内容添加 Yoast SEO meta 数据
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 填充 SEO 数据
 *
 * Note: SEO titles/descriptions are generated from templates like "{post_title} | {site_name}",
 * which is not fully realistic. For more realistic hand-written SEO meta, add them directly
 * in seed-data JSON files (e.g. woocommerce-extra.json has hand-written _yoast_wpseo_* fields).
 *
 * @param array $config JSON 配置
 * @param bool  $dry_run 是否干运行
 * @return array 执行结果
 */
function seed_phase3_fill_seo( $config, $dry_run = false ) {
    $result = array(
        'success' => false,
        'items'   => 0,
        'details' => array(),
    );

    $templates = $config['seo_templates'] ?? array();
    $target_types = $config['target_post_types'] ?? array();

    if ( empty( $templates ) || empty( $target_types ) ) {
        seed_log( '  SEO: 配置为空', 'warning' );
        return $result;
    }

    $site_name = get_bloginfo( 'name' );
    $count = 0;

    foreach ( $target_types as $post_type ) {
        // 检查 post_type 是否存在
        if ( ! post_type_exists( $post_type ) ) {
            seed_log( "  跳过 $post_type: post_type 不存在", 'warning' );
            continue;
        }

        // 获取模板
        $template = $templates[ $post_type ] ?? null;
        if ( ! $template ) {
            seed_log( "  跳过 $post_type: 无 SEO 模板", 'warning' );
            continue;
        }

        // 获取该类型的所有文章
        $posts = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ) );

        if ( empty( $posts ) ) {
            seed_log( "  跳过 $post_type: 无内容" );
            continue;
        }

        foreach ( $posts as $post ) {
            if ( $dry_run ) {
                $count++;
                continue;
            }

            // 生成 SEO 数据
            $seo_title = seed_phase3_parse_seo_template(
                $template['title_template'],
                $post,
                $site_name
            );

            $seo_desc = seed_phase3_parse_seo_template(
                $template['desc_template'],
                $post,
                $site_name
            );

            $focus_kw = '';
            if ( $template['focuskw_source'] === 'post_title' ) {
                // 提取标题中的关键词（取前几个词）
                $focus_kw = seed_phase3_extract_focus_keyword( $post->post_title );
            }

            // 写入 meta
            update_post_meta( $post->ID, '_yoast_wpseo_title', $seo_title );
            update_post_meta( $post->ID, '_yoast_wpseo_metadesc', $seo_desc );
            if ( $focus_kw ) {
                update_post_meta( $post->ID, '_yoast_wpseo_focuskw', $focus_kw );
            }

            $count++;
        }

        seed_log( "  $post_type: " . count( $posts ) . " 条", 'success' );
    }

    $result['success'] = $count > 0;
    $result['items'] = $count;

    return $result;
}

/**
 * 解析 SEO 模板
 *
 * @param string   $template 模板字符串
 * @param WP_Post  $post     文章对象
 * @param string   $site_name 站点名称
 * @return string 解析后的字符串
 */
function seed_phase3_parse_seo_template( $template, $post, $site_name ) {
    $replacements = array(
        '{post_title}'   => $post->post_title,
        '{post_excerpt}' => wp_trim_words( $post->post_excerpt ?: $post->post_content, 20 ),
        '{site_name}'    => $site_name,
    );

    return str_replace(
        array_keys( $replacements ),
        array_values( $replacements ),
        $template
    );
}

/**
 * 从标题提取焦点关键词
 *
 * @param string $title 标题
 * @return string 关键词
 */
function seed_phase3_extract_focus_keyword( $title ) {
    // 简单处理：取前 3-5 个词作为关键词
    $words = preg_split( '/\s+/', trim( $title ) );
    $keyword_words = array_slice( $words, 0, min( 3, count( $words ) ) );
    return implode( ' ', $keyword_words );
}
