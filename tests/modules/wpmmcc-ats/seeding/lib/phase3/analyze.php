<?php
/**
 * Phase 3: 内容分析 + 生成工具插件配置
 *
 * 分析 Phase 1/2 填充的内容，根据结果生成工具插件的 JSON 配置
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 分析内容并生成工具插件配置
 *
 * @param string $plan_id 计划 ID
 * @return array 分析结果
 */
function seed_phase3_analyze_and_generate( $plan_id ) {
    seed_log_phase( "分析内容 + 生成工具插件配置 (Plan $plan_id)" );

    $result = array(
        'content_analysis' => array(),
        'generated_files'  => array(),
        'content_count'    => 0,
        'config_count'     => 0,
        'success'          => false,
        'error'            => null,
    );

    // Step 1: 读取填充结果
    $seeding_result_file = defined( 'SEED_RESULT_FILE' )
        ? SEED_RESULT_FILE
        : SEEDING_BASE_DIR . '/plans/' . $plan_id . '/results/seeding-result.json';

    if ( ! file_exists( $seeding_result_file ) ) {
        seed_log( "填充结果文件不存在: $seeding_result_file", 'error' );
        seed_log( "请先运行 phase 1 和 phase 2", 'warning' );
        $result['error'] = '填充结果文件不存在，请先运行 phase 1 和 phase 2';
        return $result;
    }

    $seeding_result = json_decode( file_get_contents( $seeding_result_file ), true );

    // Step 2: 分析已填充的内容
    seed_log( "\n--- 分析已填充内容 ---" );
    $content_stats = seed_phase3_analyze_content( $seeding_result );
    $result['content_analysis'] = $content_stats;

    if ( empty( $content_stats ) ) {
        seed_log( "没有找到已填充的内容", 'warning' );
        $result['error'] = '没有找到已填充的内容';
        return $result;
    }

    // 计算内容总数
    $total_content = 0;
    foreach ( $content_stats as $info ) {
        $total_content += $info['count'];
    }
    $result['content_count'] = $total_content;

    // 输出内容统计
    seed_log( "\n| post_type | 数量 | 示例标题 |" );
    seed_log( "|-----------|------|----------|" );
    foreach ( $content_stats as $post_type => $info ) {
        $example = mb_substr( $info['example_title'], 0, 20 );
        seed_log( sprintf( "| %-18s | %4d | %s... |", $post_type, $info['count'], $example ) );
    }

    // Step 3: 获取计划的工具插件配置
    $config = seed_load_config();
    $plan = $config['plans'][ $plan_id ] ?? null;
    $utility_plugins = $plan['utility_plugins'] ?? array();

    if ( empty( $utility_plugins ) ) {
        seed_log( "\n没有配置工具插件", 'warning' );
        $result['error'] = '没有配置工具插件';
        return $result;
    }

    // Step 4: 根据内容和工具插件配置，生成关联配置
    seed_log( "\n--- 生成工具插件配置 ---" );

    // 使用计划专属的 utility 目录
    $utility_dir = defined( 'SEED_UTILITY_DIR' ) ? SEED_UTILITY_DIR : SEEDING_BASE_DIR . '/plans/' . $plan_id . '/utility';
    if ( ! is_dir( $utility_dir ) ) {
        mkdir( $utility_dir, 0755, true );
    }
    seed_log( "输出目录: $utility_dir" );

    foreach ( $utility_plugins as $plugin ) {
        $slug = is_array( $plugin ) ? ( $plugin['slug'] ?? '' ) : $plugin;
        $type = is_array( $plugin ) ? ( $plugin['type'] ?? '' ) : '';

        if ( empty( $slug ) ) {
            continue;
        }

        // 检查插件是否激活
        if ( ! seed_is_plugin_active( $slug ) ) {
            seed_log( "  跳过 $slug: 未激活" );
            continue;
        }

        // 根据插件类型生成配置
        $generated = null;
        switch ( $type ) {
            case 'seo':
                $generated = seed_phase3_generate_seo_config( $slug, $content_stats );
                break;

            case 'custom_fields':
                $generated = seed_phase3_generate_acf_config( $slug, $content_stats );
                break;

            case 'forms':
                $generated = seed_phase3_generate_forms_config( $slug, $content_stats );
                break;

            case 'tables':
                $generated = seed_phase3_generate_tables_config( $slug, $content_stats );
                break;

            case 'booking':
                $generated = seed_phase3_generate_booking_config( $slug, $content_stats );
                break;

            case 'membership':
                $generated = seed_phase3_generate_membership_config( $slug, $content_stats );
                break;

            case 'podcast':
                $generated = seed_phase3_generate_podcast_config( $slug, $content_stats );
                break;

            default:
                seed_log( "  跳过 $slug: 不支持的类型 ($type)" );
                continue 2;
        }

        if ( $generated ) {
            $output_file = $utility_dir . '/' . $slug . '.json';
            $json = json_encode( $generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            file_put_contents( $output_file, $json );

            $result['generated_files'][] = $output_file;
            seed_log( "  生成: $slug.json", 'success' );
        }
    }

    // Step 5: 输出总结
    $result['config_count'] = count( $result['generated_files'] );

    seed_log( "\n--- 分析完成 ---" );
    seed_log( "分析了 " . $result['content_count'] . " 条内容" );
    seed_log( "生成了 " . $result['config_count'] . " 个配置文件" );
    seed_log( "\n请检查 seed-data/utility/ 目录下的配置文件" );
    seed_log( "确认后运行: wp eval-file seed-content.php $plan_id phase 3" );

    $result['success'] = true;
    return $result;
}

/**
 * 分析已填充的内容
 *
 * @param array $seeding_result 填充结果
 * @return array 内容统计
 */
function seed_phase3_analyze_content( $seeding_result ) {
    $stats = array();

    // 从填充结果中提取内容信息
    $items = $seeding_result['items'] ?? array();

    foreach ( $items as $item ) {
        if ( $item['status'] !== 'success' ) {
            continue;
        }

        $post_type = $item['post_type'] ?? '';
        if ( empty( $post_type ) ) {
            continue;
        }

        if ( ! isset( $stats[ $post_type ] ) ) {
            $stats[ $post_type ] = array(
                'count'         => 0,
                'ids'           => array(),
                'example_title' => '',
            );
        }

        $stats[ $post_type ]['count']++;
        if ( ! empty( $item['post_id'] ) ) {
            $stats[ $post_type ]['ids'][] = $item['post_id'];
        }
        if ( empty( $stats[ $post_type ]['example_title'] ) && ! empty( $item['title'] ) ) {
            $stats[ $post_type ]['example_title'] = $item['title'];
        }
    }

    // 如果填充结果格式不同，尝试从数据库补充
    if ( empty( $stats ) ) {
        $stats = seed_phase3_analyze_content_from_db();
    }

    return $stats;
}

/**
 * 从数据库分析内容（备用方法）
 *
 * @return array 内容统计
 */
function seed_phase3_analyze_content_from_db() {
    global $wpdb;

    $stats = array();

    // 获取自定义 post_type 的内容统计
    $custom_types = get_post_types( array( '_builtin' => false, 'public' => true ), 'names' );

    foreach ( $custom_types as $post_type ) {
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ) );

        if ( $count > 0 ) {
            $example = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' LIMIT 1",
                $post_type
            ) );

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                $post_type
            ) );

            $stats[ $post_type ] = array(
                'count'         => (int) $count,
                'ids'           => array_map( 'intval', $ids ),
                'example_title' => $example ?: '',
            );
        }
    }

    return $stats;
}

/**
 * 生成 SEO 配置
 *
 * @param string $slug 插件 slug
 * @param array  $content_stats 内容统计
 * @return array 配置
 */
function seed_phase3_generate_seo_config( $slug, $content_stats ) {
    $target_types = array_keys( $content_stats );

    // 为每个 post_type 生成 SEO 模板
    $seo_templates = array();
    foreach ( $target_types as $post_type ) {
        $seo_templates[ $post_type ] = seed_phase3_get_seo_template_for_type( $post_type );
    }

    return array(
        '_meta' => array(
            'plugin_slug'  => $slug,
            'plugin_name'  => 'Yoast SEO',
            'type'         => 'seo',
            'generated_at' => current_time( 'mysql' ),
            'based_on'     => array_map( function( $pt ) use ( $content_stats ) {
                return $pt . ' (' . $content_stats[ $pt ]['count'] . ')';
            }, $target_types ),
        ),
        'seo_templates'     => $seo_templates,
        'target_post_types' => $target_types,
    );
}

/**
 * 获取特定 post_type 的 SEO 模板
 *
 * @param string $post_type Post type
 * @return array SEO 模板
 */
function seed_phase3_get_seo_template_for_type( $post_type ) {
    // 根据 post_type 名称推断合适的模板
    $type_lower = strtolower( $post_type );

    if ( strpos( $type_lower, 'product' ) !== false ) {
        return array(
            'title_template'   => '{post_title} - 在线购买 | {site_name}',
            'desc_template'    => '{post_excerpt} 立即购买，享受优惠价格。',
            'focuskw_source'   => 'post_title',
        );
    }

    if ( strpos( $type_lower, 'course' ) !== false || strpos( $type_lower, 'lesson' ) !== false ) {
        return array(
            'title_template'   => '学习 {post_title} | {site_name} 在线课程',
            'desc_template'    => '{post_excerpt} 立即报名，开始学习之旅。',
            'focuskw_source'   => 'post_title',
        );
    }

    if ( strpos( $type_lower, 'property' ) !== false || strpos( $type_lower, 'listing' ) !== false ) {
        return array(
            'title_template'   => '{post_title} - 房产信息 | {site_name}',
            'desc_template'    => '{post_excerpt} 查看详情，预约看房。',
            'focuskw_source'   => 'post_title',
        );
    }

    if ( strpos( $type_lower, 'recipe' ) !== false ) {
        return array(
            'title_template'   => '{post_title} 食谱做法 | {site_name}',
            'desc_template'    => '学做 {post_title}，{post_excerpt}',
            'focuskw_source'   => 'post_title',
        );
    }

    if ( strpos( $type_lower, 'review' ) !== false || strpos( $type_lower, 'testimonial' ) !== false ) {
        return array(
            'title_template'   => '{post_title} - 用户评价 | {site_name}',
            'desc_template'    => '{post_excerpt}',
            'focuskw_source'   => 'post_title',
        );
    }

    if ( strpos( $type_lower, 'forum' ) !== false || strpos( $type_lower, 'topic' ) !== false ) {
        return array(
            'title_template'   => '{post_title} - 讨论 | {site_name}',
            'desc_template'    => '加入讨论：{post_excerpt}',
            'focuskw_source'   => 'post_title',
        );
    }

    if ( strpos( $type_lower, 'download' ) !== false ) {
        return array(
            'title_template'   => '下载 {post_title} | {site_name}',
            'desc_template'    => '{post_excerpt} 立即下载。',
            'focuskw_source'   => 'post_title',
        );
    }

    if ( strpos( $type_lower, 'gallery' ) !== false || strpos( $type_lower, 'envira' ) !== false ) {
        return array(
            'title_template'   => '{post_title} 相册 | {site_name}',
            'desc_template'    => '浏览 {post_title} 精选图片。',
            'focuskw_source'   => 'post_title',
        );
    }

    // 默认模板
    return array(
        'title_template'   => '{post_title} | {site_name}',
        'desc_template'    => '{post_excerpt}',
        'focuskw_source'   => 'post_title',
    );
}

/**
 * 生成 ACF 配置
 *
 * @param string $slug 插件 slug
 * @param array  $content_stats 内容统计
 * @return array 配置
 */
function seed_phase3_generate_acf_config( $slug, $content_stats ) {
    $field_groups = array();

    foreach ( $content_stats as $post_type => $info ) {
        $group = seed_phase3_get_acf_group_for_type( $post_type, $info );
        if ( $group ) {
            $field_groups[] = $group;
        }
    }

    if ( empty( $field_groups ) ) {
        return null;
    }

    return array(
        '_meta' => array(
            'plugin_slug'  => $slug,
            'plugin_name'  => 'Advanced Custom Fields',
            'type'         => 'custom_fields',
            'generated_at' => current_time( 'mysql' ),
        ),
        'field_groups' => $field_groups,
    );
}

/**
 * 获取特定 post_type 的 ACF 字段组
 *
 * @param string $post_type Post type
 * @param array  $info 内容信息
 * @return array|null 字段组配置
 */
function seed_phase3_get_acf_group_for_type( $post_type, $info ) {
    $type_lower = strtolower( $post_type );

    // 根据 post_type 类型决定需要的字段
    if ( strpos( $type_lower, 'property' ) !== false || strpos( $type_lower, 'listing' ) !== false ) {
        return array(
            'key'         => 'group_' . sanitize_key( $post_type ) . '_details',
            'title'       => '房产详情扩展',
            'post_types'  => array( $post_type ),
            'fields'      => array(
                array( 'key' => 'field_bedrooms', 'name' => 'bedrooms', 'label' => '卧室数量', 'type' => 'number' ),
                array( 'key' => 'field_bathrooms', 'name' => 'bathrooms', 'label' => '浴室数量', 'type' => 'number' ),
                array( 'key' => 'field_square_feet', 'name' => 'square_feet', 'label' => '面积', 'type' => 'number' ),
                array( 'key' => 'field_year_built', 'name' => 'year_built', 'label' => '建造年份', 'type' => 'number' ),
            ),
            'sample_values' => seed_phase3_generate_sample_values( 'property', $info['count'] ),
        );
    }

    if ( strpos( $type_lower, 'product' ) !== false ) {
        return array(
            'key'         => 'group_' . sanitize_key( $post_type ) . '_extended',
            'title'       => '产品扩展信息',
            'post_types'  => array( $post_type ),
            'fields'      => array(
                array( 'key' => 'field_brand', 'name' => 'brand', 'label' => '品牌', 'type' => 'text' ),
                array( 'key' => 'field_warranty', 'name' => 'warranty_period', 'label' => '保修期', 'type' => 'text' ),
                array( 'key' => 'field_manufacturer', 'name' => 'manufacturer', 'label' => '制造商', 'type' => 'text' ),
            ),
            'sample_values' => seed_phase3_generate_sample_values( 'product', $info['count'] ),
        );
    }

    if ( strpos( $type_lower, 'course' ) !== false ) {
        return array(
            'key'         => 'group_' . sanitize_key( $post_type ) . '_info',
            'title'       => '课程额外信息',
            'post_types'  => array( $post_type ),
            'fields'      => array(
                array( 'key' => 'field_skill_level', 'name' => 'skill_level', 'label' => '难度级别', 'type' => 'select' ),
                array( 'key' => 'field_duration', 'name' => 'total_hours', 'label' => '总学时', 'type' => 'number' ),
                array( 'key' => 'field_language', 'name' => 'language', 'label' => '授课语言', 'type' => 'text' ),
            ),
            'sample_values' => seed_phase3_generate_sample_values( 'course', $info['count'] ),
        );
    }

    // 其他类型暂不生成 ACF 配置
    return null;
}

/**
 * 生成样本值
 *
 * @param string $type 类型
 * @param int    $count 数量
 * @return array 样本值
 */
function seed_phase3_generate_sample_values( $type, $count ) {
    $samples = array();

    switch ( $type ) {
        case 'property':
            $values = array(
                array( 'bedrooms' => 3, 'bathrooms' => 2, 'square_feet' => 1500, 'year_built' => 2018 ),
                array( 'bedrooms' => 4, 'bathrooms' => 3, 'square_feet' => 2500, 'year_built' => 2015 ),
                array( 'bedrooms' => 2, 'bathrooms' => 1, 'square_feet' => 900, 'year_built' => 2020 ),
                array( 'bedrooms' => 5, 'bathrooms' => 4, 'square_feet' => 3500, 'year_built' => 2010 ),
                array( 'bedrooms' => 3, 'bathrooms' => 2, 'square_feet' => 1800, 'year_built' => 2019 ),
            );
            break;

        case 'product':
            $values = array(
                array( 'brand' => 'Apple', 'warranty_period' => '1年', 'manufacturer' => 'Apple Inc.' ),
                array( 'brand' => 'Samsung', 'warranty_period' => '2年', 'manufacturer' => 'Samsung Electronics' ),
                array( 'brand' => 'Sony', 'warranty_period' => '1年', 'manufacturer' => 'Sony Corporation' ),
                array( 'brand' => 'Dell', 'warranty_period' => '3年', 'manufacturer' => 'Dell Technologies' ),
                array( 'brand' => 'Nike', 'warranty_period' => '6个月', 'manufacturer' => 'Nike Inc.' ),
            );
            break;

        case 'course':
            $values = array(
                array( 'skill_level' => '初级', 'total_hours' => 40, 'language' => '中文' ),
                array( 'skill_level' => '中级', 'total_hours' => 60, 'language' => '英文' ),
                array( 'skill_level' => '高级', 'total_hours' => 80, 'language' => '中文' ),
                array( 'skill_level' => '初级', 'total_hours' => 20, 'language' => '中文' ),
                array( 'skill_level' => '专家', 'total_hours' => 120, 'language' => '英文' ),
            );
            break;

        default:
            return array();
    }

    // 返回足够的样本值
    for ( $i = 0; $i < $count; $i++ ) {
        $samples[] = $values[ $i % count( $values ) ];
    }

    return $samples;
}

/**
 * 生成表单配置
 *
 * @param string $slug 插件 slug
 * @param array  $content_stats 内容统计
 * @return array 配置
 */
function seed_phase3_generate_forms_config( $slug, $content_stats ) {
    $forms = array();

    // 通用联系表单
    $forms[] = array(
        'name'         => '联系我们',
        'slug'         => 'contact-us',
        'form_content' => "<label>您的姓名\n    [text* your-name]</label>\n\n<label>您的邮箱\n    [email* your-email]</label>\n\n<label>主题\n    [text* your-subject]</label>\n\n<label>您的留言\n    [textarea your-message]</label>\n\n[submit \"发送\"]",
        'embed_config' => array(
            'create_page' => true,
            'page_title'  => '联系我们',
            'page_slug'   => 'contact',
        ),
    );

    // 根据内容类型生成特定表单
    foreach ( $content_stats as $post_type => $info ) {
        $type_lower = strtolower( $post_type );

        if ( strpos( $type_lower, 'property' ) !== false || strpos( $type_lower, 'listing' ) !== false ) {
            $forms[] = array(
                'name'         => '房产咨询',
                'slug'         => 'property-inquiry',
                'form_content' => "<label>您的姓名\n    [text* your-name]</label>\n\n<label>您的电话\n    [tel* your-phone]</label>\n\n<label>您的邮箱\n    [email* your-email]</label>\n\n<label>感兴趣的房源\n    [text property-interest]</label>\n\n<label>咨询内容\n    [textarea your-message]</label>\n\n[submit \"提交咨询\"]",
                'embed_config' => array(
                    'create_page' => true,
                    'page_title'  => '房产咨询',
                    'page_slug'   => 'property-inquiry',
                ),
            );
        }

        if ( strpos( $type_lower, 'course' ) !== false ) {
            $forms[] = array(
                'name'         => '课程报名',
                'slug'         => 'course-enrollment',
                'form_content' => "<label>您的姓名\n    [text* your-name]</label>\n\n<label>您的电话\n    [tel* your-phone]</label>\n\n<label>您的邮箱\n    [email* your-email]</label>\n\n<label>报名课程\n    [text* course-name]</label>\n\n<label>其他说明\n    [textarea additional-info]</label>\n\n[submit \"提交报名\"]",
                'embed_config' => array(
                    'create_page' => true,
                    'page_title'  => '课程报名',
                    'page_slug'   => 'course-enrollment',
                ),
            );
        }

        if ( strpos( $type_lower, 'product' ) !== false ) {
            $forms[] = array(
                'name'         => '产品询价',
                'slug'         => 'product-inquiry',
                'form_content' => "<label>您的姓名\n    [text* your-name]</label>\n\n<label>您的邮箱\n    [email* your-email]</label>\n\n<label>产品名称\n    [text* product-name]</label>\n\n<label>询价数量\n    [number quantity min:1]</label>\n\n<label>详细需求\n    [textarea requirements]</label>\n\n[submit \"提交询价\"]",
                'embed_config' => array(
                    'create_page' => true,
                    'page_title'  => '产品询价',
                    'page_slug'   => 'product-inquiry',
                ),
            );
        }
    }

    // 去重
    $forms = array_unique( $forms, SORT_REGULAR );

    return array(
        '_meta' => array(
            'plugin_slug'  => $slug,
            'plugin_name'  => 'Contact Form 7',
            'type'         => 'forms',
            'generated_at' => current_time( 'mysql' ),
        ),
        'forms' => array_values( $forms ),
    );
}

/**
 * 生成表格配置
 *
 * @param string $slug 插件 slug
 * @param array  $content_stats 内容统计
 * @return array 配置
 */
function seed_phase3_generate_tables_config( $slug, $content_stats ) {
    $tables = array();

    foreach ( $content_stats as $post_type => $info ) {
        $type_lower = strtolower( $post_type );

        if ( strpos( $type_lower, 'recipe' ) !== false ) {
            $tables[] = array(
                'name'        => '营养成分表',
                'slug'        => 'nutrition-facts',
                'description' => '食谱营养成分',
                'columns'     => array( '营养成分', '每份含量', '每日推荐%' ),
                'data'        => array(
                    array( '热量', '250卡路里', '12%' ),
                    array( '蛋白质', '15g', '30%' ),
                    array( '碳水化合物', '30g', '10%' ),
                    array( '脂肪', '8g', '12%' ),
                    array( '膳食纤维', '5g', '20%' ),
                ),
                'embed_config' => array(
                    'post_types'     => array( $post_type ),
                    'embed_position' => 'after_content',
                    'target_count'   => min( 3, $info['count'] ),
                ),
            );
        }

        if ( strpos( $type_lower, 'property' ) !== false || strpos( $type_lower, 'listing' ) !== false ) {
            $tables[] = array(
                'name'        => '房产户型参数表',
                'slug'        => 'property-specs',
                'description' => '户型规格参数',
                'columns'     => array( '户型', '面积', '卧室', '浴室', '价格区间' ),
                'data'        => array(
                    array( '一居室', '45-60㎡', '1', '1', '¥80-120万' ),
                    array( '两居室', '70-90㎡', '2', '1', '¥150-200万' ),
                    array( '三居室', '100-130㎡', '3', '2', '¥250-350万' ),
                ),
                'embed_config' => array(
                    'create_page' => true,
                    'page_title'  => '户型介绍',
                    'page_slug'   => 'property-types',
                ),
            );
        }

        if ( strpos( $type_lower, 'course' ) !== false ) {
            $tables[] = array(
                'name'        => '课程时间表',
                'slug'        => 'course-schedule',
                'description' => '课程安排',
                'columns'     => array( '时间', '周一', '周二', '周三', '周四', '周五' ),
                'data'        => array(
                    array( '09:00-10:30', '基础课程', '-', '基础课程', '-', '基础课程' ),
                    array( '10:45-12:15', '-', '进阶课程', '-', '进阶课程', '-' ),
                    array( '14:00-15:30', '实践课程', '-', '实践课程', '-', '项目实战' ),
                ),
                'embed_config' => array(
                    'create_page' => true,
                    'page_title'  => '课程时间表',
                    'page_slug'   => 'course-schedule',
                ),
            );
        }

        if ( strpos( $type_lower, 'product' ) !== false ) {
            $tables[] = array(
                'name'        => '产品规格对比表',
                'slug'        => 'product-comparison',
                'description' => '产品规格对比',
                'columns'     => array( '特性', '基础版', '专业版', '企业版' ),
                'data'        => array(
                    array( '价格', '¥99/月', '¥299/月', '¥999/月' ),
                    array( '用户数', '1人', '5人', '无限' ),
                    array( '存储空间', '10GB', '100GB', '1TB' ),
                    array( '技术支持', '邮件', '邮件+电话', '24/7专属' ),
                ),
                'embed_config' => array(
                    'create_page' => true,
                    'page_title'  => '价格方案',
                    'page_slug'   => 'pricing',
                ),
            );
        }
    }

    // 去重
    $tables = array_unique( $tables, SORT_REGULAR );

    if ( empty( $tables ) ) {
        return null;
    }

    return array(
        '_meta' => array(
            'plugin_slug'  => $slug,
            'plugin_name'  => 'TablePress',
            'type'         => 'tables',
            'generated_at' => current_time( 'mysql' ),
        ),
        'tables' => array_values( $tables ),
    );
}

/**
 * 生成预约配置
 *
 * @param string $slug 插件 slug
 * @param array  $content_stats 内容统计
 * @return array 配置
 */
function seed_phase3_generate_booking_config( $slug, $content_stats ) {
    $appointment_types = array();

    foreach ( $content_stats as $post_type => $info ) {
        $type_lower = strtolower( $post_type );

        if ( strpos( $type_lower, 'course' ) !== false ) {
            $appointment_types[] = array(
                'name'               => '课程咨询',
                'slug'               => 'course-consultation',
                'duration'           => 30,
                'description'        => '与课程顾问一对一咨询',
                'related_post_types' => array( $post_type ),
                'embed_config'       => array(
                    'create_page' => true,
                    'page_title'  => '预约课程咨询',
                    'page_slug'   => 'book-course-consultation',
                ),
            );
        }

        if ( strpos( $type_lower, 'property' ) !== false || strpos( $type_lower, 'listing' ) !== false ) {
            $appointment_types[] = array(
                'name'               => '房产看房',
                'slug'               => 'property-viewing',
                'duration'           => 60,
                'description'        => '预约实地看房',
                'related_post_types' => array( $post_type ),
                'embed_config'       => array(
                    'create_page' => true,
                    'page_title'  => '预约看房',
                    'page_slug'   => 'book-property-viewing',
                ),
            );
        }

        if ( strpos( $type_lower, 'product' ) !== false ) {
            $appointment_types[] = array(
                'name'               => '产品演示',
                'slug'               => 'product-demo',
                'duration'           => 45,
                'description'        => '产品功能演示和咨询',
                'related_post_types' => array( $post_type ),
                'embed_config'       => array(
                    'create_page' => true,
                    'page_title'  => '预约产品演示',
                    'page_slug'   => 'book-product-demo',
                ),
            );
        }
    }

    // 去重
    $appointment_types = array_unique( $appointment_types, SORT_REGULAR );

    if ( empty( $appointment_types ) ) {
        return null;
    }

    return array(
        '_meta' => array(
            'plugin_slug'  => $slug,
            'plugin_name'  => 'Simply Schedule Appointments',
            'type'         => 'booking',
            'generated_at' => current_time( 'mysql' ),
        ),
        'appointment_types' => array_values( $appointment_types ),
    );
}

/**
 * 生成会员配置
 *
 * @param string $slug 插件 slug
 * @param array  $content_stats 内容统计
 * @return array 配置
 */
function seed_phase3_generate_membership_config( $slug, $content_stats ) {
    $membership_levels = array();
    $restricted_content = array();

    // 创建会员等级
    $membership_levels[] = array(
        'name'        => '免费会员',
        'slug'        => 'free-member',
        'description' => '注册后即可访问部分内容',
        'price'       => 0,
        'duration'    => 0,
        'capabilities' => array(
            'read_free_content' => true,
        ),
    );

    $membership_levels[] = array(
        'name'        => '基础会员',
        'slug'        => 'basic-member',
        'description' => '月度订阅，解锁更多内容',
        'price'       => 29,
        'duration'    => 30,
        'capabilities' => array(
            'read_free_content'  => true,
            'read_basic_content' => true,
        ),
    );

    $membership_levels[] = array(
        'name'        => '高级会员',
        'slug'        => 'premium-member',
        'description' => '年度订阅，全站内容无限制',
        'price'       => 199,
        'duration'    => 365,
        'capabilities' => array(
            'read_free_content'    => true,
            'read_basic_content'   => true,
            'read_premium_content' => true,
            'download_resources'   => true,
        ),
    );

    // 根据内容类型生成限制规则
    foreach ( $content_stats as $post_type => $info ) {
        $type_lower = strtolower( $post_type );

        // 课程类型 - 部分免费，部分付费
        if ( strpos( $type_lower, 'course' ) !== false || strpos( $type_lower, 'lesson' ) !== false ) {
            $restricted_content[] = array(
                'post_type'        => $post_type,
                'restriction_type' => 'partial',
                'description'      => '前3节免费，其余需要会员',
                'rules' => array(
                    array(
                        'condition' => 'first_n_posts',
                        'value'     => 3,
                        'access'    => 'public',
                    ),
                    array(
                        'condition' => 'remaining',
                        'access'    => 'basic-member',
                    ),
                ),
            );
        }

        // 下载类型 - 全部需要高级会员
        if ( strpos( $type_lower, 'download' ) !== false ) {
            $restricted_content[] = array(
                'post_type'        => $post_type,
                'restriction_type' => 'full',
                'description'      => '下载资源需要高级会员',
                'rules' => array(
                    array(
                        'condition' => 'all',
                        'access'    => 'premium-member',
                    ),
                ),
            );
        }

        // 房产/商品 - 详情需要登录
        if ( strpos( $type_lower, 'property' ) !== false ||
             strpos( $type_lower, 'listing' ) !== false ||
             strpos( $type_lower, 'product' ) !== false ) {
            $restricted_content[] = array(
                'post_type'        => $post_type,
                'restriction_type' => 'meta_field',
                'description'      => '联系方式等详细信息需要登录',
                'rules' => array(
                    array(
                        'condition'    => 'meta_fields',
                        'fields'       => array( 'contact_phone', 'contact_email', 'exact_price' ),
                        'access'       => 'free-member',
                    ),
                ),
            );
        }

        // 食谱类型 - 完整配方需要基础会员
        if ( strpos( $type_lower, 'recipe' ) !== false ) {
            $restricted_content[] = array(
                'post_type'        => $post_type,
                'restriction_type' => 'partial_content',
                'description'      => '完整配方和步骤需要基础会员',
                'rules' => array(
                    array(
                        'condition' => 'content_preview',
                        'preview'   => '30%',
                        'access'    => 'public',
                    ),
                    array(
                        'condition' => 'full_content',
                        'access'    => 'basic-member',
                    ),
                ),
            );
        }
    }

    // 去重
    $restricted_content = array_unique( $restricted_content, SORT_REGULAR );

    return array(
        '_meta' => array(
            'plugin_slug'  => $slug,
            'plugin_name'  => 'Restrict Content',
            'type'         => 'membership',
            'generated_at' => current_time( 'mysql' ),
        ),
        'membership_levels'   => $membership_levels,
        'restricted_content'  => array_values( $restricted_content ),
        'registration_config' => array(
            'create_page'       => true,
            'page_title'        => '会员注册',
            'page_slug'         => 'membership-register',
            'default_level'     => 'free-member',
            'enable_login_page' => true,
        ),
    );
}

/**
 * 生成播客配置
 *
 * @param string $slug 插件 slug
 * @param array  $content_stats 内容统计
 * @return array 配置
 */
function seed_phase3_generate_podcast_config( $slug, $content_stats ) {
    $podcast_episodes = array();
    $podcast_series = array();

    // 创建播客系列
    $podcast_series[] = array(
        'name'        => '技术分享',
        'slug'        => 'tech-talks',
        'description' => '探讨最新技术趋势和实践经验',
        'cover_image' => 'tech-podcast-cover.jpg',
        'author'      => 'WPTSALL Team',
    );

    $podcast_series[] = array(
        'name'        => '行业观察',
        'slug'        => 'industry-insights',
        'description' => '深度分析行业动态和市场趋势',
        'cover_image' => 'industry-podcast-cover.jpg',
        'author'      => 'WPTSALL Team',
    );

    // 根据内容类型生成关联播客节目
    foreach ( $content_stats as $post_type => $info ) {
        $type_lower = strtolower( $post_type );

        // 课程相关 - 学习播客
        if ( strpos( $type_lower, 'course' ) !== false ) {
            $podcast_episodes[] = array(
                'title'        => '课程学习方法论',
                'slug'         => 'learning-methodology',
                'description'  => '分享高效学习方法和课程选择建议',
                'duration'     => '25:30',
                'series'       => 'tech-talks',
                'related_type' => $post_type,
                'embed_config' => array(
                    'post_types'     => array( $post_type ),
                    'embed_position' => 'before_content',
                    'target_count'   => min( 2, $info['count'] ),
                ),
            );

            $podcast_episodes[] = array(
                'title'        => '在线教育的未来',
                'slug'         => 'future-of-online-education',
                'description'  => '探讨在线教育的发展趋势',
                'duration'     => '32:15',
                'series'       => 'industry-insights',
                'related_type' => $post_type,
            );
        }

        // 产品相关 - 产品播客
        if ( strpos( $type_lower, 'product' ) !== false ) {
            $podcast_episodes[] = array(
                'title'        => '产品评测深度解析',
                'slug'         => 'product-review-analysis',
                'description'  => '专业角度解读产品特性和使用体验',
                'duration'     => '28:45',
                'series'       => 'tech-talks',
                'related_type' => $post_type,
                'embed_config' => array(
                    'post_types'     => array( $post_type ),
                    'embed_position' => 'after_content',
                    'target_count'   => min( 3, $info['count'] ),
                ),
            );
        }

        // 房产相关 - 房产播客
        if ( strpos( $type_lower, 'property' ) !== false || strpos( $type_lower, 'listing' ) !== false ) {
            $podcast_episodes[] = array(
                'title'        => '房产投资指南',
                'slug'         => 'property-investment-guide',
                'description'  => '分析房产市场趋势和投资策略',
                'duration'     => '35:20',
                'series'       => 'industry-insights',
                'related_type' => $post_type,
                'embed_config' => array(
                    'create_page' => true,
                    'page_title'  => '房产投资播客',
                    'page_slug'   => 'property-podcast',
                ),
            );
        }

        // 食谱相关 - 美食播客
        if ( strpos( $type_lower, 'recipe' ) !== false ) {
            $podcast_episodes[] = array(
                'title'        => '大厨访谈录',
                'slug'         => 'chef-interviews',
                'description'  => '与知名厨师对话，分享烹饪秘诀',
                'duration'     => '40:10',
                'series'       => 'industry-insights',
                'related_type' => $post_type,
                'embed_config' => array(
                    'post_types'     => array( $post_type ),
                    'embed_position' => 'sidebar',
                    'target_count'   => min( 3, $info['count'] ),
                ),
            );
        }

        // 招聘相关 - 职场播客
        if ( strpos( $type_lower, 'job' ) !== false ) {
            $podcast_episodes[] = array(
                'title'        => '职场发展策略',
                'slug'         => 'career-development',
                'description'  => '求职技巧和职业规划建议',
                'duration'     => '30:00',
                'series'       => 'industry-insights',
                'related_type' => $post_type,
            );
        }
    }

    // 去重
    $podcast_episodes = array_unique( $podcast_episodes, SORT_REGULAR );

    if ( empty( $podcast_episodes ) ) {
        // 如果没有关联内容，至少生成通用播客
        $podcast_episodes[] = array(
            'title'       => '欢迎收听我们的播客',
            'slug'        => 'welcome-episode',
            'description' => '介绍我们的播客栏目和内容规划',
            'duration'    => '15:00',
            'series'      => 'tech-talks',
        );
    }

    return array(
        '_meta' => array(
            'plugin_slug'  => $slug,
            'plugin_name'  => 'Starter Starter',
            'type'         => 'podcast',
            'generated_at' => current_time( 'mysql' ),
        ),
        'podcast_series'   => $podcast_series,
        'podcast_episodes' => array_values( $podcast_episodes ),
        'player_config'    => array(
            'style'           => 'modern',
            'show_transcript' => true,
            'auto_play'       => false,
            'download_enable' => true,
        ),
        'feed_config' => array(
            'enable_rss'   => true,
            'feed_title'   => 'WPTSALL Podcast',
            'feed_url'     => '/podcast/feed/',
            'itunes_ready' => true,
        ),
    );
}
