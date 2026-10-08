<?php
/**
 * Phase 3: Podcast 填充模块
 *
 * 填充 Starter Starter (Podcast Player) 播客系列和节目
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 填充播客配置
 *
 * @param array $config 配置数据
 * @param bool  $dry_run 是否干运行
 * @return array 结果
 */
function seed_phase3_fill_podcast( $config, $dry_run = false ) {
    $result = array(
        'success' => false,
        'items'   => 0,
        'details' => array(),
    );

    // 检查 Podcast Player 是否激活
    // Starter Starter / Starter Player 可能有不同的检测方式
    $podcast_active = post_type_exists( 'starter_episode' ) ||
                      post_type_exists( 'podcast' ) ||
                      shortcode_exists( 'starter_starter' ) ||
                      shortcode_exists( 'podcast_player' );

    if ( ! $podcast_active ) {
        seed_log( '  Podcast Player 未激活', 'warning' );
        // 仍然可以创建页面嵌入外部播客
    }

    $podcast_series = $config['podcast_series'] ?? array();
    $podcast_episodes = $config['podcast_episodes'] ?? array();
    $player_config = $config['player_config'] ?? array();
    $feed_config = $config['feed_config'] ?? array();

    $created = 0;

    // 1. 创建播客系列（如果插件支持）
    seed_log( '  创建播客系列...' );
    $series_map = array(); // slug => term_id

    foreach ( $podcast_series as $series ) {
        if ( $dry_run ) {
            seed_log( "    [Dry Run] 会创建系列: {$series['name']}" );
            $created++;
            continue;
        }

        $term_id = seed_phase3_create_podcast_series( $series );
        if ( $term_id ) {
            $series_map[ $series['slug'] ] = $term_id;
            seed_log( "    创建系列: {$series['name']}", 'success' );
            $result['details'][] = array(
                'type'    => 'series',
                'name'    => $series['name'],
                'term_id' => $term_id,
            );
            $created++;
        }
    }

    // 2. 创建播客节目
    seed_log( '  创建播客节目...' );
    foreach ( $podcast_episodes as $episode ) {
        if ( $dry_run ) {
            seed_log( "    [Dry Run] 会创建节目: {$episode['title']}" );
            $created++;
            continue;
        }

        $series_term_id = $series_map[ $episode['series'] ?? '' ] ?? 0;
        $episode_id = seed_phase3_create_podcast_episode( $episode, $series_term_id );

        if ( $episode_id ) {
            seed_log( "    创建节目: {$episode['title']} (ID: $episode_id)", 'success' );
            $result['details'][] = array(
                'type'    => 'episode',
                'title'   => $episode['title'],
                'post_id' => $episode_id,
            );
            $created++;

            // 处理嵌入配置
            $embed = $episode['embed_config'] ?? array();
            if ( ! empty( $embed['create_page'] ) ) {
                $page_id = seed_phase3_create_podcast_page(
                    $embed['page_title'],
                    $embed['page_slug'],
                    $episode_id
                );
                if ( $page_id ) {
                    seed_log( "    创建页面: {$embed['page_title']}", 'success' );
                    $created++;
                }
            }

            if ( ! empty( $embed['post_types'] ) ) {
                $embedded = seed_phase3_embed_podcast_in_posts(
                    $episode_id,
                    $embed['post_types'],
                    $embed['embed_position'] ?? 'after_content',
                    $embed['target_count'] ?? 3
                );
                if ( $embedded > 0 ) {
                    seed_log( "    嵌入 $embedded 篇文章", 'success' );
                }
            }
        }
    }

    // 3. 保存播放器配置
    if ( ! $dry_run && ! empty( $player_config ) ) {
        update_option( 'seed_podcast_player_config', $player_config );
        seed_log( '  保存播放器配置', 'success' );
    }

    // 4. 保存 Feed 配置
    if ( ! $dry_run && ! empty( $feed_config ) ) {
        update_option( 'seed_podcast_feed_config', $feed_config );
        seed_log( '  保存 Feed 配置', 'success' );
    }

    $result['success'] = $created > 0;
    $result['items'] = $created;

    return $result;
}

/**
 * 创建播客系列
 *
 * @param array $series 系列配置
 * @return int|false 分类 ID 或 false
 */
function seed_phase3_create_podcast_series( $series ) {
    // 尝试不同的 taxonomy 名称
    $taxonomies = array( 'podcast_series', 'starter_series', 'podcast_category', 'category' );

    foreach ( $taxonomies as $taxonomy ) {
        if ( taxonomy_exists( $taxonomy ) ) {
            // 检查是否已存在
            $existing = get_term_by( 'slug', $series['slug'], $taxonomy );
            if ( $existing ) {
                return $existing->term_id;
            }

            // 创建新分类
            $result = wp_insert_term(
                $series['name'],
                $taxonomy,
                array(
                    'slug'        => $series['slug'],
                    'description' => $series['description'] ?? '',
                )
            );

            if ( ! is_wp_error( $result ) ) {
                return $result['term_id'];
            }
        }
    }

    // 如果没有专用 taxonomy，使用选项存储
    $stored_series = get_option( 'seed_podcast_series', array() );
    $stored_series[ $series['slug'] ] = $series;
    update_option( 'seed_podcast_series', $stored_series );

    return $series['slug']; // 返回 slug 作为标识
}

/**
 * 创建播客节目
 *
 * @param array $episode 节目配置
 * @param int   $series_term_id 系列分类 ID
 * @return int|false 文章 ID 或 false
 */
function seed_phase3_create_podcast_episode( $episode, $series_term_id = 0 ) {
    // 检查是否已存在
    $existing = get_page_by_path( $episode['slug'], OBJECT, array( 'starter_episode', 'podcast', 'post' ) );
    if ( $existing ) {
        return $existing->ID;
    }

    // 尝试不同的 post_type
    $post_types = array( 'starter_episode', 'podcast', 'post' );
    $post_type = 'post';

    foreach ( $post_types as $type ) {
        if ( post_type_exists( $type ) ) {
            $post_type = $type;
            break;
        }
    }

    // 创建播客内容
    $content = '<!-- wp:paragraph -->
<p>' . esc_html( $episode['description'] ?? '' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><strong>时长：</strong> ' . esc_html( $episode['duration'] ?? '未知' ) . '</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>[podcast_player]</p>
<!-- /wp:paragraph -->';

    $post_data = array(
        'post_title'   => $episode['title'],
        'post_name'    => $episode['slug'],
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => $post_type,
    );

    $post_id = wp_insert_post( $post_data );

    if ( $post_id && ! is_wp_error( $post_id ) ) {
        // 添加 meta
        update_post_meta( $post_id, '_podcast_duration', $episode['duration'] ?? '' );
        update_post_meta( $post_id, '_podcast_series', $episode['series'] ?? '' );

        // 关联分类
        if ( $series_term_id ) {
            $taxonomies = array( 'podcast_series', 'starter_series', 'podcast_category', 'category' );
            foreach ( $taxonomies as $taxonomy ) {
                if ( taxonomy_exists( $taxonomy ) ) {
                    wp_set_object_terms( $post_id, array( $series_term_id ), $taxonomy );
                    break;
                }
            }
        }

        return $post_id;
    }

    return false;
}

/**
 * 创建播客展示页面
 *
 * @param string $title 页面标题
 * @param string $slug 页面别名
 * @param int    $episode_id 节目 ID
 * @return int|false 页面 ID 或 false
 */
function seed_phase3_create_podcast_page( $title, $slug, $episode_id ) {
    // 检查页面是否已存在
    $existing = get_page_by_path( $slug );
    if ( $existing ) {
        return $existing->ID;
    }

    $episode_title = get_the_title( $episode_id );

    // 创建页面内容
    $content = '<!-- wp:heading -->
<h2>' . esc_html( $episode_title ) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>[podcast_player id="' . $episode_id . '"]</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>更多节目</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>[podcast_list limit="5"]</p>
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

/**
 * 在文章中嵌入播客
 *
 * @param int    $episode_id 节目 ID
 * @param array  $post_types 目标文章类型
 * @param string $position 嵌入位置
 * @param int    $count 目标数量
 * @return int 嵌入数量
 */
function seed_phase3_embed_podcast_in_posts( $episode_id, $post_types, $position, $count ) {
    $embedded = 0;
    $shortcode = '[podcast_player id="' . $episode_id . '"]';

    foreach ( $post_types as $post_type ) {
        $posts = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'orderby'        => 'rand',
        ) );

        foreach ( $posts as $post ) {
            // 检查是否已嵌入
            if ( strpos( $post->post_content, 'podcast_player' ) !== false ) {
                continue;
            }

            $new_content = $post->post_content;

            switch ( $position ) {
                case 'before_content':
                    $new_content = '<!-- wp:paragraph --><p>' . $shortcode . '</p><!-- /wp:paragraph -->' . "\n\n" . $new_content;
                    break;

                case 'after_content':
                    $new_content .= "\n\n" . '<!-- wp:heading {"level":3} --><h3>相关播客</h3><!-- /wp:heading -->' . "\n";
                    $new_content .= '<!-- wp:paragraph --><p>' . $shortcode . '</p><!-- /wp:paragraph -->';
                    break;

                case 'sidebar':
                    // Sidebar 嵌入通过 meta 标记
                    update_post_meta( $post->ID, '_podcast_sidebar_id', $episode_id );
                    $embedded++;
                    continue 2;
            }

            wp_update_post( array(
                'ID'           => $post->ID,
                'post_content' => $new_content,
            ) );

            $embedded++;
        }
    }

    return $embedded;
}
