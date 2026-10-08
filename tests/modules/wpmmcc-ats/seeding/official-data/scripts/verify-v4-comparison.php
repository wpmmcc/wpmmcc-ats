<?php
/**
 * v4 Scanner vs Seed-Data 字段对比验证脚本
 *
 * 用途：对比 v4-smart-scanner 扫描结果与 seed-data 定义的字段
 */

$base_dir = dirname( dirname( dirname( __DIR__ ) ) );
$v4_output_dir = $base_dir . '/scanning/v4-smart-scanner/output';
$seed_data_dir = $base_dir . '/seeding/seed-data';
$config_file = $base_dir . '/seeding/seeding-config.json';

// 读取配置
$config = json_decode( file_get_contents( $config_file ), true );
$plans = $config['plans'];

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║  v4 Scanner vs Seed-Data 字段对比验证                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$report = array(
    'generated_at' => date( 'Y-m-d H:i:s' ),
    'plans' => array(),
    'summary' => array(
        'total_custom_seeding_plugins' => 0,
        'plugins_with_v4_match' => 0,
        'plugins_without_v4_match' => 0,
        'total_seed_meta_fields' => 0,
        'total_v4_meta_fields' => 0,
        'matched_fields' => 0,
        'seed_only_fields' => 0,
        'v4_only_fields' => 0,
    ),
);

// 映射 seed-data 文件名到 v4 扫描文件前缀
$plugin_v4_mapping = array(
    'academy' => array( 'academy-academy_courses', 'academy-academy_lessons' ),
    'masterstudy-lms' => array( 'masterstudy-lms-learning-management-system-stm-courses', 'masterstudy-lms-learning-management-system-stm-lessons', 'masterstudy-lms-learning-management-system-stm-quizzes' ),
    'classified-listing' => array( 'classified-listing-rtcl_listing' ),
    'easy-property-listings' => array( 'epl-epl_contact' ),
    'cooked' => array( 'cp-cp_recipe' ),
    'envira-gallery-lite' => array( 'unknown-envira' ),
    'site-reviews' => array( 'gemini_labs-site-review' ),
    'testimonial-free' => array( 'spt-spt_testimonial' ),
    'directorist' => array( 'at-at_biz_dir' ),
    'the-events-calendar' => array( 'tribe-tribe_events', 'tribe-tribe_venue', 'tribe-tribe_organizer' ),
    'wp-job-manager' => array( 'job-job_listing' ),
    'essential-real-estate' => array( 'unknown-property' ),
    'asgaros-forum' => array(),  // 自定义表，无 post_type
    'delicious-recipes' => array( 'unknown-recipe' ),
    'foogallery' => array( 'unknown-foogallery' ),
    'portfolio-post-type' => array( 'unknown-portfolio' ),
    'seriously-simple-podcasting' => array( 'unknown-podcast' ),
    'wp-easycart' => array( 'ec-ec_store' ),
    'wpforo' => array(),  // 自定义表
    'estatik' => array( 'unknown-properties' ),
    'wp-recipe-maker' => array( 'wprm-wprm_recipe' ),
    'simple-job-board' => array( 'unknown-jobpost', 'jobpost-jobpost_applicants' ),
    'ultimate-faqs' => array( 'unknown-ufaq' ),
    'wp-customer-reviews' => array( 'wpcr3-wpcr3_review' ),
    'storeengine' => array( 'storeengine-storeengine_product', 'storeengine-storeengine_coupon' ),
    'hivepress' => array( 'hp-hp_listing', 'hp-hp_vendor' ),
    'geodirectory' => array( 'gd-gd_place' ),
    'forumwp' => array( 'fmwp-fmwp_forum', 'fmwp-fmwp_topic', 'fmwp-fmwp_reply' ),
    'propertyhive' => array( 'unknown-property' ),
    'events-manager' => array( 'unknown-event', 'unknown-location' ),
    'wp-job-openings' => array( 'awsm-awsm_job_openings', 'awsm-awsm_job_application' ),
    'give' => array( 'give-give_forms', 'give-give_payment' ),
    'podlove' => array( 'unknown-podcast' ),  // Podlove uses 'podcast' post type
    'podlove-podcasting-plugin-for-wordpress' => array( 'unknown-podcast' ),  // Full slug mapping
);

// 处理每个计划
foreach ( $plans as $plan_name => $plan_data ) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "计划 $plan_name: {$plan_data['name']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $plan_report = array(
        'name' => $plan_data['name'],
        'official_data_count' => count( $plan_data['content_plugins']['official_data'] ?? array() ),
        'custom_seeding_count' => count( $plan_data['content_plugins']['custom_seeding'] ?? array() ),
        'utility_count' => count( $plan_data['utility_plugins'] ?? array() ),
        'utility_plugins' => array(),
        'custom_seeding_plugins' => array(),
    );

    // 收集工具插件信息
    foreach ( $plan_data['utility_plugins'] ?? array() as $utility ) {
        $plan_report['utility_plugins'][] = array(
            'slug' => $utility['slug'],
            'name' => $utility['name'],
            'type' => $utility['type'],
            'has_associations' => ! empty( $utility['associations'] ),
        );
    }

    echo "\n官方数据插件: " . $plan_report['official_data_count'] . " 个\n";
    echo "自定义填充插件: " . $plan_report['custom_seeding_count'] . " 个\n";
    echo "工具插件: " . $plan_report['utility_count'] . " 个\n";

    // 工具插件详情
    echo "\n工具插件详情:\n";
    foreach ( $plan_data['utility_plugins'] ?? array() as $utility ) {
        $assoc_info = isset( $utility['associations'] ) ? '✓' : '✗';
        echo "  - {$utility['name']} ({$utility['type']}) [关联: $assoc_info]\n";
    }

    // 处理自定义填充插件
    echo "\n自定义填充插件字段对比:\n";

    foreach ( $plan_data['content_plugins']['custom_seeding'] ?? array() as $plugin ) {
        $slug = $plugin['slug'];
        $report['summary']['total_custom_seeding_plugins']++;

        // 找到对应的 seed-data 文件
        $seed_file_name = isset( $config['seed_data_config']['files'][$slug] )
            ? $config['seed_data_config']['files'][$slug]
            : "$slug.json";
        $seed_file = "$seed_data_dir/$seed_file_name";

        $plugin_report = array(
            'slug' => $slug,
            'name' => $plugin['name'],
            'seed_file' => $seed_file_name,
            'seed_exists' => file_exists( $seed_file ),
            'v4_files' => array(),
            'seed_meta_fields' => array(),
            'v4_meta_fields' => array(),
            'comparison' => array(
                'matched' => array(),
                'seed_only' => array(),
                'v4_only' => array(),
            ),
        );

        // 读取 seed-data 字段
        $is_custom_table_plugin = false;
        if ( file_exists( $seed_file ) ) {
            $seed_data = json_decode( file_get_contents( $seed_file ), true );

            // 检查是否是自定义表插件
            if ( isset( $seed_data['custom_tables'] ) ) {
                $is_custom_table_plugin = true;
                // 从 custom_tables 中提取字段名
                foreach ( $seed_data['custom_tables'] as $table_type => $rows ) {
                    if ( is_array( $rows ) ) {
                        foreach ( $rows as $row ) {
                            if ( is_array( $row ) ) {
                                foreach ( array_keys( $row ) as $key ) {
                                    // 排除引用字段（以 _ref 结尾）
                                    if ( substr( $key, -4 ) !== '_ref' && ! in_array( $key, $plugin_report['seed_meta_fields'] ) ) {
                                        $plugin_report['seed_meta_fields'][] = $key;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                // 标准 posts 结构
                foreach ( $seed_data['posts'] ?? array() as $post_type => $posts ) {
                    foreach ( $posts as $post ) {
                        foreach ( $post['meta'] ?? array() as $key => $value ) {
                            if ( ! in_array( $key, $plugin_report['seed_meta_fields'] ) ) {
                                $plugin_report['seed_meta_fields'][] = $key;
                            }
                        }
                    }
                }
            }
        }
        $plugin_report['is_custom_table'] = $is_custom_table_plugin;

        // 查找 v4 扫描结果
        $v4_prefixes = $plugin_v4_mapping[$slug] ?? array();

        // 对于自定义表插件，也查找 {slug}-{table}.json 格式的文件
        if ( $is_custom_table_plugin || empty( $v4_prefixes ) ) {
            $files = glob( "$v4_output_dir/*.json" );
            foreach ( $files as $file ) {
                $base = basename( $file, '.json' );
                // 尝试匹配插件 slug（支持 slug-table 格式）
                if ( strpos( $base, $slug ) === 0 ) {
                    if ( ! in_array( $base, $v4_prefixes ) ) {
                        $v4_prefixes[] = $base;
                    }
                } elseif ( strpos( $base, str_replace( '-', '', $slug ) ) !== false ) {
                    if ( ! in_array( $base, $v4_prefixes ) ) {
                        $v4_prefixes[] = $base;
                    }
                }
            }
        }

        foreach ( $v4_prefixes as $v4_prefix ) {
            $v4_file = "$v4_output_dir/$v4_prefix.json";
            if ( file_exists( $v4_file ) ) {
                $plugin_report['v4_files'][] = basename( $v4_file );
                $v4_data = json_decode( file_get_contents( $v4_file ), true );

                // 检查是否是自定义表扫描结果
                $is_v4_custom_table = ( $v4_data['object_metadata']['type'] ?? '' ) === 'custom_table';

                foreach ( $v4_data['fields'] ?? array() as $field_key => $field_info ) {
                    // 自定义表：包含所有非系统字段
                    if ( $is_custom_table_plugin || $is_v4_custom_table ) {
                        // 排除自增主键 id
                        if ( $field_key !== 'id' && ! in_array( $field_key, $plugin_report['v4_meta_fields'] ) ) {
                            $plugin_report['v4_meta_fields'][] = $field_key;
                        }
                    } else {
                        // 标准 post_type：只计算 meta 字段
                        $meta_prefixes = array(
                            '_',           // 通用 meta 前缀
                            'academy_',    // Academy LMS
                            'rtcl_',       // Classified Listing
                            'stm_',        // MasterStudy LMS
                            'hp_',         // HivePress
                            'geodir_',     // GeoDirectory
                            'awsm_',       // WP Job Openings
                            'fmwp_',       // ForumWP
                            'property_',   // PropertyHive / Easy Property Listings
                            'tribe_',      // The Events Calendar
                            'job_',        // WP Job Manager
                            'wprm_',       // WP Recipe Maker
                            'wpcr3_',      // WP Customer Reviews
                            'cp_',         // Cooked
                            'spt_',        // Testimonial Free
                            $slug . '_',   // 插件 slug 前缀
                        );
                        // 特定无前缀字段（Podcast 相关）
                        $exact_fields = array( 'duration', 'episode_type', 'date_recorded', 'footnotes' );
                        $is_meta = false;
                        foreach ( $meta_prefixes as $mp ) {
                            if ( strpos( $field_key, $mp ) === 0 ) {
                                $is_meta = true;
                                break;
                            }
                        }
                        // 检查无前缀的精确匹配字段
                        if ( ! $is_meta && in_array( $field_key, $exact_fields ) ) {
                            $is_meta = true;
                        }
                        if ( $is_meta && ! in_array( $field_key, $plugin_report['v4_meta_fields'] ) ) {
                            $plugin_report['v4_meta_fields'][] = $field_key;
                        }
                    }
                }
            }
        }

        // 对比字段
        $seed_fields = $plugin_report['seed_meta_fields'];
        $v4_fields = $plugin_report['v4_meta_fields'];

        $plugin_report['comparison']['matched'] = array_intersect( $seed_fields, $v4_fields );
        $plugin_report['comparison']['seed_only'] = array_diff( $seed_fields, $v4_fields );
        $plugin_report['comparison']['v4_only'] = array_diff( $v4_fields, $seed_fields );

        // 更新汇总
        $report['summary']['total_seed_meta_fields'] += count( $seed_fields );
        $report['summary']['total_v4_meta_fields'] += count( $v4_fields );
        $report['summary']['matched_fields'] += count( $plugin_report['comparison']['matched'] );
        $report['summary']['seed_only_fields'] += count( $plugin_report['comparison']['seed_only'] );
        $report['summary']['v4_only_fields'] += count( $plugin_report['comparison']['v4_only'] );

        if ( ! empty( $v4_prefixes ) && ! empty( $plugin_report['v4_files'] ) ) {
            $report['summary']['plugins_with_v4_match']++;
        } else {
            $report['summary']['plugins_without_v4_match']++;
        }

        // 输出
        $v4_status = ! empty( $plugin_report['v4_files'] ) ? '✓' : '✗';
        $seed_count = count( $seed_fields );
        $v4_count = count( $v4_fields );
        $matched_count = count( $plugin_report['comparison']['matched'] );

        echo "\n  {$plugin['name']} ($slug)\n";
        echo "    seed-data: $seed_file_name | v4扫描: $v4_status\n";
        echo "    字段数: seed=$seed_count, v4=$v4_count, 匹配=$matched_count\n";

        if ( ! empty( $plugin_report['comparison']['seed_only'] ) ) {
            echo "    seed-only: " . implode( ', ', array_slice( $plugin_report['comparison']['seed_only'], 0, 5 ) );
            if ( count( $plugin_report['comparison']['seed_only'] ) > 5 ) {
                echo " ...+" . ( count( $plugin_report['comparison']['seed_only'] ) - 5 );
            }
            echo "\n";
        }

        $plan_report['custom_seeding_plugins'][] = $plugin_report;
    }

    $report['plans'][$plan_name] = $plan_report;
    echo "\n";
}

// 输出汇总
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║  综合汇总                                                                ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "自定义填充插件总数: {$report['summary']['total_custom_seeding_plugins']}\n";
echo "  - 有 v4 扫描匹配: {$report['summary']['plugins_with_v4_match']}\n";
echo "  - 无 v4 扫描匹配: {$report['summary']['plugins_without_v4_match']}\n";
echo "\n";

echo "字段对比:\n";
echo "  - seed-data 定义字段: {$report['summary']['total_seed_meta_fields']}\n";
echo "  - v4 扫描发现字段: {$report['summary']['total_v4_meta_fields']}\n";
echo "  - 匹配字段: {$report['summary']['matched_fields']}\n";
echo "  - 仅在 seed-data: {$report['summary']['seed_only_fields']}\n";
echo "  - 仅在 v4 扫描: {$report['summary']['v4_only_fields']}\n";
echo "\n";

// 汇总各计划的工具插件
echo "各计划工具插件统计:\n";
echo "┌─────────┬────────────┬────────────┬────────────┬──────────────────────────────────────────┐\n";
echo "│ 计划    │ 官方数据   │ 自定义填充 │ 工具插件   │ 工具插件类型                             │\n";
echo "├─────────┼────────────┼────────────┼────────────┼──────────────────────────────────────────┤\n";

foreach ( $report['plans'] as $plan_name => $plan_data ) {
    $official = str_pad( $plan_data['official_data_count'], 10 );
    $custom = str_pad( $plan_data['custom_seeding_count'], 10 );
    $utility = str_pad( $plan_data['utility_count'], 10 );

    // 按类型分组工具插件
    $types = array();
    foreach ( $plan_data['utility_plugins'] as $up ) {
        $types[$up['type']] = ( $types[$up['type']] ?? 0 ) + 1;
    }
    $type_str = array();
    foreach ( $types as $t => $c ) {
        $type_str[] = "$t:$c";
    }
    $type_summary = str_pad( implode( ', ', $type_str ), 40 );

    echo "│ $plan_name       │ $official │ $custom │ $utility │ $type_summary │\n";
}
echo "└─────────┴────────────┴────────────┴────────────┴──────────────────────────────────────────┘\n";

// 保存 JSON 报告
$report_file = dirname( __DIR__ ) . '/v4-comparison-report.json';
file_put_contents( $report_file, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
echo "\n详细报告已保存到: $report_file\n";
