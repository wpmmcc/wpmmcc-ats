<?php
/**
 * Seeding 公共函数库
 *
 * 包含日志、配置加载、结果记录等所有 Phase 共用的函数
 *
 * @package WPTSALL\DevTools\Seeding
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// =========================================================
// 常量定义
// =========================================================

if ( ! defined( 'SEEDING_BASE_DIR' ) ) {
    define( 'SEEDING_BASE_DIR', dirname( __DIR__ ) );
}

if ( ! defined( 'SEED_DATA_DIR' ) ) {
    define( 'SEED_DATA_DIR', SEEDING_BASE_DIR . '/seed-data/' );
}

if ( ! defined( 'SEED_TEMPLATE_DIR' ) ) {
    define( 'SEED_TEMPLATE_DIR', SEEDING_BASE_DIR . '/data-templates/' );
}

if ( ! defined( 'SEED_OFFICIAL_DIR' ) ) {
    define( 'SEED_OFFICIAL_DIR', SEEDING_BASE_DIR . '/official-data/' );
}

// =========================================================
// 配置加载
// =========================================================

/**
 * 加载 seeding 配置文件
 *
 * @return array 配置数组
 */
function seed_load_config() {
    static $config = null;

    if ( $config !== null ) {
        return $config;
    }

    $config_file = SEEDING_BASE_DIR . '/seeding-config.json';

    if ( ! file_exists( $config_file ) ) {
        seed_log( '配置文件不存在: ' . $config_file, 'error' );
        return array();
    }

    $config = json_decode( file_get_contents( $config_file ), true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        seed_log( 'JSON 解析错误: ' . json_last_error_msg(), 'error' );
        return array();
    }

    // 存入全局变量供其他函数使用
    $GLOBALS['seeding_config'] = $config;

    return $config;
}

/**
 * 获取计划配置
 *
 * @param string $plan_id 计划 ID (A/B/C/D)
 * @return array|null 计划配置或 null
 */
function seed_get_plan( $plan_id ) {
    $config = seed_load_config();
    return $config['plans'][ $plan_id ] ?? null;
}

/**
 * Optional E2E plugin allowlist for plugin-specific matrix lanes.
 *
 * The seeding plans were originally defined as broad suites.  Independent E2E
 * plugin lanes must not activate/fill unrelated suite plugins because one
 * plugin with an incomplete migration can break every later lane in wp-admin.
 *
 * @return array<string,bool>|null Slug lookup map, or null when disabled.
 */
function seed_e2e_plugin_allowlist() {
    $raw = getenv( 'WPTSALL_E2E_SEED_PLUGIN_ALLOWLIST' );
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return null;
    }

    $slugs = preg_split( '/[\s,]+/', $raw );
    if ( ! is_array( $slugs ) ) {
        return null;
    }

    $allow = array();
    foreach ( $slugs as $slug ) {
        $slug = trim( (string) $slug );
        if ( $slug === '' ) {
            continue;
        }
        $allow[ $slug ] = true;
    }

    // Plan E uses this synthetic seed slug for WordPress core content.
    $allow['wordpress-core'] = true;

    return empty( $allow ) ? null : $allow;
}

/**
 * Filter plan plugin entries through the optional E2E allowlist.
 *
 * @param array<int,mixed> $plugins Plan entries.
 * @return array<int,mixed>
 */
function seed_filter_plugins_by_e2e_allowlist( $plugins ) {
    $allow = seed_e2e_plugin_allowlist();
    if ( null === $allow ) {
        return $plugins;
    }

    $filtered = array();
    foreach ( (array) $plugins as $plugin ) {
        $slug = is_array( $plugin ) ? (string) ( $plugin['slug'] ?? '' ) : (string) $plugin;
        if ( $slug === '' ) {
            continue;
        }
        if ( ! empty( $allow[ $slug ] ) ) {
            $filtered[] = $plugin;
        }
    }

    return $filtered;
}

/**
 * 获取计划的插件列表
 *
 * @param string $plan_id 计划 ID
 * @return array 插件 slug 数组
 */
function seed_get_plan_plugins( $plan_id ) {
    $plan = seed_get_plan( $plan_id );
    if ( ! $plan ) {
        return array();
    }

    $plugins = array( 'wordpress-blog' ); // 核心内容始终包含

    // 解析 content_plugins
    $content = $plan['content_plugins'] ?? array();

    if ( isset( $content['official_data'] ) || isset( $content['custom_seeding'] ) ) {
        // 新格式：嵌套结构
        foreach ( $content['official_data'] ?? array() as $plugin ) {
            if ( isset( $plugin['slug'] ) ) {
                $plugins[] = $plugin['slug'];
            }
        }
        foreach ( $content['custom_seeding'] ?? array() as $plugin ) {
            if ( isset( $plugin['slug'] ) ) {
                $plugins[] = $plugin['slug'];
            }
        }
    } else {
        // 旧格式：简单数组
        $plugins = array_merge( $plugins, $content );
    }

    // 解析 utility_plugins
    foreach ( $plan['utility_plugins'] ?? array() as $plugin ) {
        if ( is_array( $plugin ) && isset( $plugin['slug'] ) ) {
            $plugins[] = $plugin['slug'];
        } elseif ( is_string( $plugin ) ) {
            $plugins[] = $plugin;
        }
    }

    return seed_filter_plugins_by_e2e_allowlist( array_unique( $plugins ) );
}

/**
 * 获取计划的官方数据插件
 *
 * @param string $plan_id 计划 ID
 * @return array 官方数据插件配置数组
 */
function seed_get_official_plugins( $plan_id ) {
    $plan = seed_get_plan( $plan_id );
    if ( ! $plan ) {
        return array();
    }

    return seed_filter_plugins_by_e2e_allowlist( $plan['content_plugins']['official_data'] ?? array() );
}

/**
 * 获取计划的自定义填充插件
 *
 * @param string $plan_id 计划 ID
 * @return array 自定义填充插件配置数组
 */
function seed_get_custom_plugins( $plan_id ) {
    $plan = seed_get_plan( $plan_id );
    if ( ! $plan ) {
        return array();
    }

    return seed_filter_plugins_by_e2e_allowlist( $plan['content_plugins']['custom_seeding'] ?? array() );
}

/**
 * 获取计划的工具插件
 *
 * @param string $plan_id 计划 ID
 * @return array 工具插件配置数组
 */
function seed_get_utility_plugins( $plan_id ) {
    $plan = seed_get_plan( $plan_id );
    if ( ! $plan ) {
        return array();
    }

    return seed_filter_plugins_by_e2e_allowlist( $plan['utility_plugins'] ?? array() );
}

/**
 * 获取官方数据源配置
 *
 * @param string $plugin_slug 插件 slug
 * @return array|null 数据源配置或 null
 */
function seed_get_official_source( $plugin_slug ) {
    $config = seed_load_config();
    return $config['official_data_sources']['plugins'][ $plugin_slug ] ?? null;
}

// =========================================================
// 日志函数
// =========================================================

/**
 * 输出日志
 *
 * @param string $msg  日志消息
 * @param string $type 日志类型 (info, success, error, warning)
 */
function seed_log( $msg, $type = 'info' ) {
    switch ( $type ) {
        case 'success':
            $prefix = "\033[32m✓\033[0m";
            break;
        case 'error':
            $prefix = "\033[31m✗\033[0m";
            break;
        case 'warning':
            $prefix = "\033[33m⚠\033[0m";
            break;
        default:
            $prefix = '→';
            break;
    }

    $line = date( '[H:i:s] ' ) . $prefix . ' ' . $msg . "\n";
    echo $line;

    // 写入日志文件（如果定义了）
    if ( defined( 'SEED_LOG_FILE' ) && SEED_LOG_FILE ) {
        file_put_contents( SEED_LOG_FILE, strip_tags( $line ), FILE_APPEND );
    }
}

/**
 * 输出阶段标题
 *
 * @param string $title 标题
 */
function seed_log_phase( $title ) {
    $line = "\n" . str_repeat( '=', 60 ) . "\n";
    $line .= "  $title\n";
    $line .= str_repeat( '=', 60 ) . "\n";
    echo $line;

    if ( defined( 'SEED_LOG_FILE' ) && SEED_LOG_FILE ) {
        file_put_contents( SEED_LOG_FILE, $line, FILE_APPEND );
    }
}

// =========================================================
// 结果记录
// =========================================================

/**
 * 初始化结果存储
 */
function seed_init_results() {
    $GLOBALS['seeding_results'] = array();
    $GLOBALS['seeding_stats'] = array(
        'total'   => 0,
        'success' => 0,
        'failed'  => 0,
    );
}

/**
 * 记录填充结果
 *
 * @param string $type        内容类型 (post_type)
 * @param int    $id          文章 ID
 * @param string $title       标题
 * @param string $url         URL
 * @param string $template_id 模板 ID
 * @param array  $verification 验证信息
 * @param string $plugin_slug 插件 slug
 */
function seed_record_result( $type, $id, $title, $url, $template_id = '', $verification = array(), $plugin_slug = '' ) {
    $result = array(
        'type'         => $type,
        'post_id'      => $id,
        'title'        => $title,
        'url'          => $url,
        'template_id'  => $template_id,
        'verification' => $verification,
        'created_at'   => current_time( 'mysql' ),
    );

    // 记录插件归属
    if ( empty( $plugin_slug ) ) {
        $plugin_slug = seed_get_plugin_for_post_type( $type );
    }
    if ( ! empty( $plugin_slug ) ) {
        $result['plugin_slug'] = $plugin_slug;
    }

    $GLOBALS['seeding_results'][] = $result;
    $GLOBALS['seeding_stats']['total']++;
    $GLOBALS['seeding_stats']['success']++;
}

/**
 * 记录失败
 *
 * @param string $type    内容类型
 * @param string $title   标题
 * @param string $error   错误信息
 */
function seed_record_failure( $type, $title, $error ) {
    $GLOBALS['seeding_stats']['total']++;
    $GLOBALS['seeding_stats']['failed']++;

    seed_log( "创建失败 [$type] $title: $error", 'error' );
}

/**
 * 获取填充统计
 *
 * @return array 统计数组
 */
function seed_get_stats() {
    return $GLOBALS['seeding_stats'] ?? array(
        'total'   => 0,
        'success' => 0,
        'failed'  => 0,
    );
}

/**
 * 获取填充结果
 *
 * @return array 结果数组
 */
function seed_get_results() {
    return $GLOBALS['seeding_results'] ?? array();
}

/**
 * 保存结果到文件
 *
 * @param string $file 文件路径
 * @return bool 是否成功
 */
function seed_save_results( $file ) {
    $data = array(
        'generated_at' => current_time( 'mysql' ),
        'stats'        => seed_get_stats(),
        'results'      => seed_get_results(),
    );

    $dir = dirname( $file );
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0755, true );
    }

    $json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    return file_put_contents( $file, $json ) !== false;
}

// =========================================================
// 数据加载
// =========================================================

/**
 * 加载 seed-data JSON 文件
 *
 * @param string $plugin_slug 插件 slug
 * @return array|null 数据或 null
 */
function seed_load_json_data( $plugin_slug ) {
    $config = seed_load_config();

    // 从配置获取文件名映射
    $files_map = $config['seed_data_config']['files'] ?? array();
    $filename  = $files_map[ $plugin_slug ] ?? "{$plugin_slug}.json";

    $file = SEED_DATA_DIR . $filename;
    if ( ! file_exists( $file ) ) {
        return null;
    }

    $data = json_decode( file_get_contents( $file ), true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        seed_log( "JSON 解析错误 [$plugin_slug]: " . json_last_error_msg(), 'error' );
        return null;
    }

    return $data;
}

/**
 * 加载模板文件
 *
 * @param string $name 模板名称
 * @return array|null 模板数据或 null
 */
function seed_load_template( $name ) {
    $file = SEED_TEMPLATE_DIR . $name . '.json';
    if ( ! file_exists( $file ) ) {
        return null;
    }

    return json_decode( file_get_contents( $file ), true );
}

// =========================================================
// 工具函数
// =========================================================

/**
 * 根据 post_type 获取插件 slug
 *
 * @param string $post_type Post type
 * @return string 插件 slug 或空字符串
 */
function seed_get_plugin_for_post_type( $post_type ) {
    static $mapping = array(
        // WordPress 核心
        'post'                    => 'wordpress-blog',
        'page'                    => 'wordpress-blog',

        // Academy LMS
        'academy_courses'         => 'academy',
        'academy_lessons'         => 'academy',

        // BBPress
        'forum'                   => 'bbpress',
        'topic'                   => 'bbpress',
        'reply'                   => 'bbpress',

        // Classified Listing
        'rtcl_listing'            => 'classified-listing',

        // Cooked
        'cp_recipe'               => 'cooked',

        // Delicious Recipes
        'recipe'                  => 'delicious-recipes',

        // Directorist
        'at_biz_dir'              => 'directorist',

        // Easy Digital Downloads
        'download'                => 'easy-digital-downloads',

        // Easy Property Listings
        'property'                => 'easy-property-listings',

        // Envira Gallery
        'envira'                  => 'envira-gallery-lite',

        // Events Manager
        'event'                   => 'events-manager',

        // ForumWP
        'fmwp_forum'              => 'forumwp',
        'fmwp_topic'              => 'forumwp',
        'fmwp_reply'              => 'forumwp',

        // GeoDirectory
        'gd_place'                => 'geodirectory',

        // Give
        'give_forms'              => 'give',

        // HivePress
        'hp_listing'              => 'hivepress',
        'hp_vendor'               => 'hivepress',

        // LearnPress
        'lp_course'               => 'learnpress',
        'lp_lesson'               => 'learnpress',

        // LifterLMS / Sensei LMS (both use 'course'/'lesson')
        // Resolved dynamically below based on active plugin
        // 'course' => 'lifterlms' or 'sensei-lms'
        // 'lesson' => 'lifterlms' or 'sensei-lms'

        // MasterStudy LMS
        'stm-courses'             => 'masterstudy-lms-learning-management-system',
        'stm-lessons'             => 'masterstudy-lms-learning-management-system',

        // Portfolio
        'portfolio'               => 'portfolio-post-type',

        // Simple Job Board
        'jobpost'                 => 'simple-job-board',

        // Site Reviews
        'site-review'             => 'site-reviews',

        // Testimonial Free
        'spt_testimonial'         => 'testimonial-free',

        // The Events Calendar
        'tribe_events'            => 'the-events-calendar',

        // Tutor LMS
        'courses'                 => 'tutor',

        // Ultimate FAQs
        'ufaq'                    => 'ultimate-faqs',

        // WooCommerce
        'product'                 => 'woocommerce',

        // WP Customer Reviews
        'wpcr3_review'            => 'wp-customer-reviews',

        // WP EasyCart
        'ec_store'                => 'wp-easycart',

        // WP Job Manager
        'job_listing'             => 'wp-job-manager',

        // WP Job Openings
        'awsm_job_openings'       => 'wp-job-openings',

        // WP Recipe Maker
        'wprm_recipe'             => 'wp-recipe-maker',

        // WPForo
        'wpforo_topic'            => 'wpforo',
    );

    if ( isset( $mapping[ $post_type ] ) ) {
        return $mapping[ $post_type ];
    }

    // Dynamic resolution for shared post_types (course/lesson used by both LifterLMS and Sensei LMS)
    if ( in_array( $post_type, array( 'course', 'lesson' ), true ) ) {
        // Prefer Sensei if active, otherwise LifterLMS
        if ( function_exists( 'seed_is_plugin_active' ) && seed_is_plugin_active( 'sensei-lms' ) ) {
            return 'sensei-lms';
        }
        return 'lifterlms';
    }

    return '';
}

/**
 * 解析引用语法 {{post_type:index}}
 *
 * Supported reference formats:
 *   - {{post_type:N}}       — Resolves to the Nth post of that type from seeding results
 *   - {{attachment:N}}      — Resolves to the Nth uploaded media attachment ID (from seed_media_ids_ordered)
 *   - {{image:N}}           — Resolves to the Nth uploaded image attachment ID
 *   - {{video:N}}           — Resolves to the Nth uploaded video attachment ID
 *   - {{audio:N}}           — Resolves to the Nth uploaded audio attachment ID
 *   - {{media:filename}}    — Resolves to the attachment ID of a specific uploaded file
 *
 * @param mixed $value 值
 * @return mixed 解析后的值
 */
function seed_resolve_reference( $value ) {
    if ( ! is_string( $value ) ) {
        return $value;
    }

    // Match {{type:index_or_filename}} format
    if ( ! preg_match( '/^\{\{(\w+):(.+)\}\}$/', $value, $matches ) ) {
        return $value;
    }

    $ref_type = $matches[1];
    $ref_key  = $matches[2];

    // Media references by filename: {{media:product-1.jpg}}
    if ( $ref_type === 'media' ) {
        $media_ids = $GLOBALS['seed_media_ids'] ?? array();
        return $media_ids[ $ref_key ] ?? 0;
    }

    // Media references by index: {{attachment:N}}, {{image:N}}, {{video:N}}, {{audio:N}}
    if ( in_array( $ref_type, array( 'attachment', 'image', 'video', 'audio' ), true ) ) {
        $ref_index = (int) $ref_key;

        if ( $ref_type === 'attachment' ) {
            $ids = $GLOBALS['seed_media_ids_ordered'] ?? array();
        } else {
            $by_type = $GLOBALS['seed_media_ids_by_type'] ?? array();
            $ids = $by_type[ $ref_type ] ?? array();
        }

        return $ids[ $ref_index ] ?? 0;
    }

    // Post type references: {{post_type:N}}
    $ref_index = (int) $ref_key;
    foreach ( $GLOBALS['seeding_results'] ?? array() as $result ) {
        if ( $result['type'] === $ref_type ) {
            if ( $ref_index === 0 ) {
                return $result['post_id'];
            }
            $ref_index--;
        }
    }

    return 0;
}

/**
 * 解析命令行参数中的 --phase 选项
 *
 * @param array $args 命令行参数
 * @return int|null Phase 编号或 null（执行全部）
 */
function seed_parse_phase_option( $args ) {
    foreach ( $args as $arg ) {
        if ( preg_match( '/^--phase=(\d+)$/', $arg, $matches ) ) {
            return (int) $matches[1];
        }
    }
    return null;
}

/**
 * 获取插件可能的主文件列表
 *
 * @param string $plugin_slug 插件 slug
 * @return array 候选插件主文件
 */
function seed_get_plugin_file_candidates( $plugin_slug ) {
    // 常见的主文件名格式
    $possible_files = array(
        $plugin_slug . '/' . $plugin_slug . '.php',
        $plugin_slug . '/' . str_replace( '-', '_', $plugin_slug ) . '.php',
        $plugin_slug . '/' . $plugin_slug . '-base.php',  // directorist 使用这种格式
        $plugin_slug . '.php',
    );

    // 特殊插件主文件 / 别名映射
    $special_files = array(
        'buddypress'                            => 'buddypress/bp-loader.php',
        'masterstudy-lms-learning-management-system' => 'masterstudy-lms-learning-management-system/masterstudy-lms-learning-management-system.php',
        'woocommerce-extra'                     => 'woocommerce/woocommerce.php', // 作为 WooCommerce 补充数据包
        'wp-customer-reviews'                   => 'wp-customer-reviews/wp-customer-reviews-3.php',
        'wp-easycart'                           => 'wp-easycart/wpeasycart.php',
        'seo-by-rank-math'                      => 'seo-by-rank-math/rank-math.php',
        'pods'                                  => 'pods/init.php',
        'restrict-content'                      => 'restrict-content/restrictcontent.php',
        'bookly-responsive-appointment-booking-tool' => 'bookly-responsive-appointment-booking-tool/main.php',
    );

    if ( isset( $special_files[ $plugin_slug ] ) ) {
        $possible_files[] = $special_files[ $plugin_slug ];
    }

    return array_values( array_unique( $possible_files ) );
}

/**
 * 检查插件是否激活
 *
 * @param string $plugin_slug 插件 slug
 * @return bool 是否激活
 */
function seed_is_plugin_active( $plugin_slug ) {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $possible_files = seed_get_plugin_file_candidates( $plugin_slug );

    foreach ( $possible_files as $file ) {
        if ( is_plugin_active( $file ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file ) ) ) {
            return true;
        }
    }

    return false;
}

/**
 * 尝试激活插件（用于 seeding 阶段自动补齐环境）
 *
 * @param string $plugin_slug 插件 slug
 * @return bool 激活后是否可用
 */
function seed_try_activate_plugin( $plugin_slug ) {
    if ( seed_is_plugin_active( $plugin_slug ) ) {
        return true;
    }

    if ( ! function_exists( 'activate_plugin' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $possible_files = seed_get_plugin_file_candidates( $plugin_slug );

    foreach ( $possible_files as $file ) {
        $plugin_file = WP_PLUGIN_DIR . '/' . $file;
        if ( ! file_exists( $plugin_file ) ) {
            continue;
        }

        // silent=true 防止激活输出污染 seeding 日志
        $result = activate_plugin( $file, '', false, true );
        if ( ! is_wp_error( $result ) && seed_is_plugin_active( $plugin_slug ) ) {
            return true;
        }
    }

    return false;
}

/**
 * 运行嵌套 WP-CLI 命令，避免 proc_open 管道死锁。
 *
 * WP_CLI::runcommand 会为子进程的 stdout/stderr 建立管道；当嵌套命令输出
 * 超过管道缓冲（如 WP 钩子产生的数据库错误刷屏）时，子进程阻塞在写端，
 * 而父进程顺序读取另一条管道，形成死锁（隔离 slot 环境实测：learnpress
 * seed 脚本挂起 40 分钟，wchan=anon_pipe_write）。把子进程输出重定向到
 * 临时文件后，管道始终为空，父进程立即读到 EOF，死锁不再可能。
 *
 * @param string $command 已去掉 "wp " 前缀的命令
 * @return object { return_code, stdout, stderr }
 */
function seed_phase1_run_nested( $command ) {
    $out_file = tempnam( sys_get_temp_dir(), 'wptsall-seed-out-' );
    $err_file = tempnam( sys_get_temp_dir(), 'wptsall-seed-err-' );

    if ( false === $out_file || false === $err_file ) {
        return (object) array(
            'return_code' => 1,
            'stdout'      => '',
            'stderr'      => '无法创建嵌套命令输出文件',
        );
    }

    $full = $command . ' > ' . escapeshellarg( $out_file ) . ' 2> ' . escapeshellarg( $err_file );

    try {
        $result = WP_CLI::runcommand( $full, array(
            'return'     => 'all',
            'exit_error' => false,
        ) );
        $return_code = $result->return_code;
    } catch ( Exception $e ) {
        $return_code = 1;
    }

    $stdout = (string) @file_get_contents( $out_file );
    $stderr = (string) @file_get_contents( $err_file );
    @unlink( $out_file );
    @unlink( $err_file );

    return (object) array(
        'return_code' => $return_code,
        'stdout'      => $stdout,
        'stderr'      => $stderr,
    );
}
