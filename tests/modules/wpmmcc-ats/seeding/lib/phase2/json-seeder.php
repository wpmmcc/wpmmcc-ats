<?php
/**
 * Phase 2: 自定义 JSON 数据填充
 *
 * 从 seed-data/*.json 读取数据并填充到 WordPress
 *
 * 处理流程：
 * 0. 上传媒体文件到 WordPress 媒体库
 * 1. 读取 JSON 文件
 * 2. 创建分类法术语
 * 3. 创建文章并写入 meta
 * 4. 关联分类法到文章
 *
 * @package WPTSALL\DevTools\Seeding\Phase2
 * @version 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 执行 Phase 2: 自定义 JSON 数据填充
 *
 * @param string $plan_id 计划 ID (A/B/C/D)
 * @return array 执行结果统计
 */
function seed_phase2_execute( $plan_id ) {
    seed_log_phase( "Phase 2: 自定义 JSON 数据填充 (Plan $plan_id)" );

    // Step 0: Upload media files to WordPress media library
    seed_phase2_upload_media();

    $custom_plugins = seed_get_custom_plugins( $plan_id );

    if ( empty( $custom_plugins ) ) {
        seed_log( '此计划没有自定义填充插件', 'warning' );
        return array(
            'total'   => 0,
            'success' => 0,
            'failed'  => 0,
            'skipped' => 0,
        );
    }

    $stats = array(
        'total'   => count( $custom_plugins ),
        'success' => 0,
        'failed'  => 0,
        'skipped' => 0,
        'items'   => 0,
    );

    foreach ( $custom_plugins as $plugin ) {
        $slug = $plugin['slug'];
        $name = $plugin['name'] ?? $slug;

        seed_log( "\n--- $name ---" );

        // 检查插件是否激活
        if ( ! seed_is_plugin_active( $slug ) ) {
            if ( seed_try_activate_plugin( $slug ) ) {
                seed_log( "检测到未激活，已自动激活", 'warning' );
            } else {
                seed_log( "跳过: 插件未激活", 'warning' );
                $stats['skipped']++;
                continue;
            }
        }

        // 执行填充
        $result = seed_phase2_fill_plugin( $slug );

        if ( $result['success'] ) {
            $stats['success']++;
            $stats['items'] += $result['items'];
            seed_log( "填充完成: {$result['items']} 条", 'success' );
        } else {
            $stats['failed']++;
            seed_log( "填充失败: {$result['error']}", 'error' );
        }
    }

    // 输出统计
    seed_log( "\n--- Phase 2 统计 ---" );
    seed_log( "插件: {$stats['total']}, 成功: {$stats['success']}, 失败: {$stats['failed']}, 跳过: {$stats['skipped']}" );
    seed_log( "总内容: {$stats['items']} 条" );

    return $stats;
}

/**
 * 填充单个插件的数据
 *
 * @param string $plugin_slug 插件 slug
 * @return array 结果 ['success' => bool, 'items' => int, 'error' => string]
 */
function seed_phase2_fill_plugin( $plugin_slug ) {
    // 加载 JSON 数据
    $data = seed_load_json_data( $plugin_slug );

    if ( ! $data ) {
        return array(
            'success' => false,
            'items'   => 0,
            'error'   => '未找到 seed-data JSON 文件',
        );
    }

    $plugin_name = $data['_meta']['plugin_name'] ?? $plugin_slug;

    // Guard: skip standard wp_insert_post for plugins that use custom tables,
    // unless the seed explicitly opts into CPT creation for discover/smoke.
    $force_standard = ! empty( $data['_meta']['force_standard_seed'] );
    if ( ! $force_standard && ( ! empty( $data['_meta']['custom_table'] ) || ! empty( $data['custom_tables'] ) ) ) {
        $table_info = $data['_meta']['custom_table'] ?? 'custom tables';
        seed_log( "  跳过标准填充: $plugin_name 使用自定义表 ($table_info)，需要专用脚本或 API", 'warning' );
        return array(
            'success' => true,
            'items'   => 0,
            'error'   => '',
        );
    }

    // 1. 创建分类法
    $term_map = array();
    if ( ! empty( $data['taxonomies'] ) ) {
        $term_map = seed_phase2_create_taxonomies( $data['taxonomies'] );
    }

    // 2. 创建内容
    $total_success = 0;
    $total_failed  = 0;

    if ( ! empty( $data['posts'] ) ) {
        foreach ( $data['posts'] as $post_type => $items ) {
            if ( ! post_type_exists( $post_type ) ) {
                seed_log( "  跳过 $post_type (post_type 不存在)", 'warning' );
                continue;
            }

            seed_log( "  处理 $post_type: " . count( $items ) . " 条" );

            foreach ( $items as $index => $item ) {
                $post_id = seed_phase2_create_post( $post_type, $item, $term_map, $plugin_slug );

                if ( $post_id ) {
                    $total_success++;

                    // 记录结果
                    seed_record_result(
                        $post_type,
                        $post_id,
                        $item['post_title'] ?: '(no title)',
                        get_permalink( $post_id ),
                        "{$plugin_slug}:{$post_type}:{$index}",
                        array(),
                        $plugin_slug
                    );
                } else {
                    $total_failed++;
                }
            }
        }
    }

    return array(
        'success' => $total_failed === 0,
        'items'   => $total_success,
        'error'   => $total_failed > 0 ? "失败 $total_failed 条" : '',
    );
}

/**
 * 创建分类法术语
 *
 * @param array $taxonomies 分类法配置
 * @return array 术语映射 [taxonomy => [slug => term_id]]
 */
function seed_phase2_create_taxonomies( $taxonomies ) {
    $term_map = array();

    foreach ( $taxonomies as $taxonomy => $terms ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            seed_log( "  分类法不存在: $taxonomy", 'warning' );
            continue;
        }

        $term_map[ $taxonomy ] = array();

        foreach ( $terms as $term_data ) {
            $term_id = seed_phase2_create_term( $taxonomy, $term_data );
            if ( $term_id ) {
                $term_map[ $taxonomy ][ $term_data['slug'] ] = $term_id;
            }

            // 处理子分类
            if ( ! empty( $term_data['children'] ) ) {
                foreach ( $term_data['children'] as $child ) {
                    $child_id = seed_phase2_create_term( $taxonomy, $child, $term_id );
                    if ( $child_id ) {
                        $term_map[ $taxonomy ][ $child['slug'] ] = $child_id;
                    }
                }
            }
        }

        $count = count( $term_map[ $taxonomy ] );
        seed_log( "  创建分类 $taxonomy: $count 个术语" );
    }

    return $term_map;
}

/**
 * 创建单个术语
 *
 * @param string $taxonomy  分类法
 * @param array  $term_data 术语数据
 * @param int    $parent_id 父术语 ID
 * @return int 术语 ID 或 0
 */
function seed_phase2_create_term( $taxonomy, $term_data, $parent_id = 0 ) {
    // 检查是否已存在
    $existing = get_term_by( 'slug', $term_data['slug'], $taxonomy );
    if ( $existing ) {
        return $existing->term_id;
    }

    $result = wp_insert_term(
        $term_data['name'],
        $taxonomy,
        array(
            'slug'        => $term_data['slug'],
            'description' => $term_data['description'] ?? '',
            'parent'      => $parent_id,
        )
    );

    if ( is_wp_error( $result ) ) {
        return 0;
    }

    return $result['term_id'];
}

/**
 * 创建单篇文章
 *
 * @param string $post_type   文章类型
 * @param array  $item        文章数据
 * @param array  $term_map    术语映射
 * @param string $plugin_slug 插件 slug
 * @return int 文章 ID 或 0
 */
function seed_phase2_create_post( $post_type, $item, $term_map, $plugin_slug ) {
    // 准备文章数据
    // Note: Empty string post_title (e.g. forum replies) is valid and passed through.
    // The ?? 'Untitled' fallback only triggers when the key is missing entirely.
    $post_data = array(
        'post_type'    => $post_type,
        'post_title'   => $item['post_title'] ?? 'Untitled',
        'post_content' => $item['post_content'] ?? '',
        'post_excerpt' => $item['post_excerpt'] ?? '',
        'post_status'  => $item['post_status'] ?? 'publish',
        'post_author'  => get_current_user_id(),
    );

    // 可选字段
    if ( isset( $item['post_name'] ) ) {
        $post_data['post_name'] = $item['post_name'];
    }
    if ( isset( $item['menu_order'] ) ) {
        $post_data['menu_order'] = $item['menu_order'];
    }
    if ( isset( $item['post_parent'] ) ) {
        $post_data['post_parent'] = seed_resolve_reference( $item['post_parent'] );
    }

    // 创建文章
    $post_id = wp_insert_post( $post_data, true );

    if ( is_wp_error( $post_id ) ) {
        seed_log( "    创建失败: {$item['post_title']} - " . $post_id->get_error_message(), 'error' );
        return 0;
    }

    // 设置 meta 字段
    if ( ! empty( $item['meta'] ) ) {
        foreach ( $item['meta'] as $key => $value ) {
            // 处理引用语法
            $value = seed_resolve_reference( $value );
            update_post_meta( $post_id, $key, $value );
        }
    }

    // 设置分类法
    if ( ! empty( $item['taxonomy'] ) ) {
        foreach ( $item['taxonomy'] as $taxonomy => $slugs ) {
            $term_ids = array();

            foreach ( $slugs as $slug ) {
                if ( isset( $term_map[ $taxonomy ][ $slug ] ) ) {
                    $term_ids[] = $term_map[ $taxonomy ][ $slug ];
                } else {
                    // 尝试查找已存在的术语
                    $term = get_term_by( 'slug', $slug, $taxonomy );
                    if ( $term ) {
                        $term_ids[] = $term->term_id;
                    }
                }
            }

            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $post_id, $term_ids, $taxonomy );
            }
        }
    }

    if ( $post_type === 'tribe_events' && class_exists( 'Tribe__Events__API' ) ) {
        $start = get_post_meta( $post_id, '_EventStartDate', true );
        $end   = get_post_meta( $post_id, '_EventEndDate', true );
        Tribe__Events__API::updateEvent(
            $post_id,
            array(
                'EventStartDate' => $start,
                'EventEndDate'   => $end,
                'EventTimezone'  => get_post_meta( $post_id, '_EventTimezone', true ) ?: 'UTC',
            )
        );
    }

    // TODO: Add support for 'comments' field in JSON data to create WordPress comments
    // during seeding. Currently, comments are only created by Plan E's corporate-site-seed.php.
    // Comments are not a primary translation target in WPTSALL, so this is deferred.

    // 设置特色图片（如果指定）
    if ( ! empty( $item['featured_image'] ) ) {
        $attachment_id = seed_resolve_reference( $item['featured_image'] );
        if ( $attachment_id ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    seed_log( "    创建: {$item['post_title']} (ID: $post_id)", 'success' );
    return $post_id;
}

/**
 * Upload media files from media/ directory to WordPress media library.
 *
 * Scans the seeding media directory for image/video/audio files,
 * uploads each to WordPress as an attachment, and stores the
 * resulting attachment IDs in $GLOBALS['seed_media_ids'] for
 * use by {{attachment:N}} references.
 *
 * Files are categorized by type:
 *   - image: JPG, JPEG, PNG, GIF, WEBP
 *   - video: MP4, MOV, AVI, WEBM
 *   - audio: MP3, WAV, OGG, M4A
 *
 * @return array Uploaded media IDs keyed by filename
 */
function seed_phase2_upload_media() {
    $media_dir = SEEDING_BASE_DIR . '/media';

    if ( ! is_dir( $media_dir ) ) {
        seed_log( '媒体目录不存在: ' . $media_dir, 'warning' );
        return array();
    }

    // Require WordPress media functions
    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $files = scandir( $media_dir );
    if ( ! $files ) {
        return array();
    }

    // Categorize files by type
    $image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
    $video_exts = array( 'mp4', 'mov', 'avi', 'webm' );
    $audio_exts = array( 'mp3', 'wav', 'ogg', 'm4a' );

    $media_ids     = array();
    $by_type       = array( 'image' => array(), 'video' => array(), 'audio' => array() );
    $upload_count  = 0;
    $skipped_count = 0;

    seed_log( "\n--- 媒体文件上传 ---" );

    foreach ( $files as $filename ) {
        if ( $filename === '.' || $filename === '..' ) {
            continue;
        }

        $filepath = $media_dir . '/' . $filename;
        if ( ! is_file( $filepath ) ) {
            continue;
        }

        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        // Determine media type
        if ( in_array( $ext, $image_exts, true ) ) {
            $media_type = 'image';
        } elseif ( in_array( $ext, $video_exts, true ) ) {
            $media_type = 'video';
        } elseif ( in_array( $ext, $audio_exts, true ) ) {
            $media_type = 'audio';
        } else {
            continue; // Skip unsupported file types
        }

        // Check if already uploaded (by title match)
        $title = pathinfo( $filename, PATHINFO_FILENAME );
        $existing = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'title'          => $title,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );

        if ( ! empty( $existing ) ) {
            $attachment_id = $existing[0];
            $media_ids[ $filename ] = $attachment_id;
            $by_type[ $media_type ][] = $attachment_id;
            $skipped_count++;
            continue;
        }

        // Copy file to uploads directory
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['path'];
        $target_file = $target_dir . '/' . $filename;

        if ( ! copy( $filepath, $target_file ) ) {
            seed_log( "  上传失败: $filename (无法复制文件)", 'error' );
            continue;
        }

        // Determine MIME type
        $mime_types = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/avi',
            'webm' => 'video/webm',
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'm4a'  => 'audio/m4a',
        );
        $mime_type = $mime_types[ $ext ] ?? '';

        // Create attachment post
        $attachment_data = array(
            'post_title'     => $title,
            'post_mime_type' => $mime_type,
            'post_status'    => 'inherit',
            'post_content'   => '',
        );

        $attachment_id = wp_insert_attachment( $attachment_data, $target_file );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            seed_log( "  上传失败: $filename", 'error' );
            continue;
        }

        // Generate attachment metadata (thumbnails for images, etc.)
        $metadata = wp_generate_attachment_metadata( $attachment_id, $target_file );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        $media_ids[ $filename ] = $attachment_id;
        $by_type[ $media_type ][] = $attachment_id;
        $upload_count++;
    }

    // Store in globals for reference resolution
    $GLOBALS['seed_media_ids']         = $media_ids;
    $GLOBALS['seed_media_ids_by_type'] = $by_type;

    // Build the ordered list for {{attachment:N}} references (all IDs in upload order)
    $GLOBALS['seed_media_ids_ordered'] = array_values( $media_ids );

    $total = $upload_count + $skipped_count;
    seed_log( "  媒体文件: $total 个 (新上传: $upload_count, 已存在: $skipped_count)" );
    seed_log( "  图片: " . count( $by_type['image'] ) . ", 视频: " . count( $by_type['video'] ) . ", 音频: " . count( $by_type['audio'] ) );

    return $media_ids;
}

/**
 * 检查 seed-data 文件状态
 *
 * @param string $plan_id 计划 ID
 * @return array 检查结果
 */
function seed_phase2_check_files( $plan_id ) {
    $custom_plugins = seed_get_custom_plugins( $plan_id );
    $results = array();

    foreach ( $custom_plugins as $plugin ) {
        $slug = $plugin['slug'];
        $data = seed_load_json_data( $slug );

        if ( $data ) {
            $post_count = 0;
            foreach ( $data['posts'] ?? array() as $items ) {
                $post_count += count( $items );
            }

            $results[ $slug ] = array(
                'status'     => 'ready',
                'post_types' => array_keys( $data['posts'] ?? array() ),
                'post_count' => $post_count,
                'taxonomies' => array_keys( $data['taxonomies'] ?? array() ),
            );
        } else {
            $results[ $slug ] = array(
                'status'  => 'missing',
                'message' => 'JSON 文件不存在',
            );
        }
    }

    return $results;
}
