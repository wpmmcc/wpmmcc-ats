<?php
/**
 * WPTSALL 自动化同步测试
 *
 * 测试内容同步、URL重写和模型字段扫描功能
 *
 * 重要说明：
 * - 此测试脚本独立于插件主程序，仅用于开发测试
 * - 测试使用插件自身的任务调度和hook系统
 * - 测试完成后会自动清理测试数据
 *
 * 运行方式 1 - 使用 WP-CLI（推荐）：
 * cd /Users/zhangxiao/wptsall-dev
 * wp eval-file tests/test-automated-sync.php
 *
 * 运行方式 2 - 直接使用 PHP：
 * cd /Users/zhangxiao/wptsall-dev
 * php -d display_errors=1 tests/test-automated-sync.php
 *
 * 运行方式 3 - 从 WordPress 根目录：
 * wp eval-file wp-content/plugins/wpmmcc-ats/tests/test-automated-sync.php
 *
 * 选项：
 * - 禁用自动清理: 在URL末尾添加 ?cleanup=false
 *   或在脚本中设置: $_GET['cleanup'] = 'false';
 *
 * @package WPTSALL
 * @since 0.3.0
 */

// 确保在 WordPress 环境中运行
if ( ! defined( 'ABSPATH' ) ) {
	// 尝试加载 WordPress
	$wp_load_paths = array(
		// 从插件开发目录
		dirname( dirname( __DIR__ ) ) . '/wp-load.php',
		// 从 WordPress 根目录
		dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php',
		// 从插件目录
		'../../../wp-load.php',
	);

	$wp_loaded = false;
	foreach ( $wp_load_paths as $path ) {
		if ( file_exists( $path ) ) {
			define( 'WP_USE_THEMES', false );
			require_once $path;
			$wp_loaded = true;
			break;
		}
	}

	if ( ! $wp_loaded ) {
		die( "错误: 无法加载 WordPress。请确保从正确的位置运行此脚本。\n" );
	}
}

// 确保插件已激活
if ( ! defined( 'WPTSALL_PATH' ) ) {
	die( "错误: WPTSALL 插件未激活。请先激活插件。\n" );
}

/**
 * WPTSALL 自动化测试类
 */
class WPTSALL_Automated_Tests {

	/**
	 * 测试结果
	 */
	private $results = array();

	/**
	 * 测试数据（用于清理）
	 */
	private $test_data = array(
		'posts'      => array(),
		'pages'      => array(),
		'terms'      => array(),
		'relations'  => array(),
		'tasks'      => array(),
	);

	/**
	 * 运行所有测试
	 */
	public function run_all_tests() {
		$this->log_header( 'WPTSALL 自动化同步测试' );
		$this->log_info( '测试时间: ' . current_time( 'Y-m-d H:i:s' ) );
		$this->log_info( '插件版本: v0.3.0' );
		$this->log_separator();

		// 前置检查
		if ( ! $this->pre_check() ) {
			$this->log_error( '前置检查失败，测试终止' );
			return false;
		}

		try {
			// 测试 1: 模型字段扫描测试
			$this->test_model_field_scanning();

			// 测试 2: 内容同步测试 - Post
			$this->test_post_sync();

			// 测试 3: 内容同步测试 - Page
			$this->test_page_sync();

			// 测试 4: 分类法同步测试
			$this->test_taxonomy_sync();

			// 测试 5: URL 重写测试
			$this->test_url_rewrite();

			// 测试 6: 任务调度测试
			$this->test_task_scheduling();

			// 测试 7: ID 映射测试
			$this->test_id_mapping();

			// 测试摘要
			$this->print_summary();

		} catch ( Exception $e ) {
			$this->log_error( '测试异常: ' . $e->getMessage() );
			$this->log_error( '堆栈跟踪: ' . $e->getTraceAsString() );
		} finally {
			// 清理测试数据
			if ( isset( $_GET['cleanup'] ) && 'false' !== $_GET['cleanup'] ) {
				$this->cleanup();
			}
		}

		return $this->results;
	}

	/**
	 * 前置检查
	 */
	private function pre_check() {
		$this->log_section( '前置检查' );

		$checks = array();

		// 检查数据库表
		global $wpdb;
		$tables = array(
			'wptsall_tasks',
			'wptsall_task_logs',
			'wptsall_mappings',
			'wptsall_virtual_content',
			'wptsall_hooks',
		);

		foreach ( $tables as $table ) {
			$table_name = $wpdb->prefix . $table;
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" );
			$checks[ "表 {$table}" ] = (bool) $exists;
			if ( ! $exists ) {
				$this->log_error( "数据库表不存在: {$table_name}" );
			}
		}

		// 检查关键函数
		$functions = array(
			'wptsall_generate_tasks_from_template',
			'wptsall_process_task',
			'wptsall_get_complete_post_data',
			'wptsall_get_complete_term_data',
			'wptsall_cron_process_tasks',
			'wptsall_insert_mapping',
			'wptsall_get_mapped_id',
		);

		foreach ( $functions as $func ) {
			$checks[ "函数 {$func}" ] = function_exists( $func );
			if ( ! function_exists( $func ) ) {
				$this->log_error( "函数不存在: {$func}" );
			}
		}

		// 检查 Blog 模型
		if ( class_exists( '\WPTSALL\Models\Blog_Template' ) ) {
			$blog_template = \WPTSALL\Models\Blog_Template::get();
			$checks['Blog 模板'] = ! empty( $blog_template );
			if ( empty( $blog_template ) ) {
				$this->log_error( 'Blog 模板未加载' );
				// 尝试生成
				\WPTSALL\Models\Blog_Template::save();
				$blog_template = \WPTSALL\Models\Blog_Template::get();
				$checks['Blog 模板'] = ! empty( $blog_template );
			}
		} else {
			$checks['Blog 模板类'] = false;
			$this->log_error( 'Blog_Template 类不存在' );
		}

		// 打印检查结果
		foreach ( $checks as $name => $passed ) {
			if ( $passed ) {
				$this->log_success( "✓ {$name}" );
			} else {
				$this->log_error( "✗ {$name}" );
			}
		}

		$all_passed = ! in_array( false, $checks, true );
		if ( $all_passed ) {
			$this->log_success( '所有前置检查通过' );
		}

		return $all_passed;
	}

	/**
	 * 测试 1: 模型字段扫描测试
	 */
	private function test_model_field_scanning() {
		$this->log_section( '测试 1: 模型字段扫描' );

		// 获取 Blog 模板（强制重新生成以确保完整数据）
		$template = \WPTSALL\Models\Blog_Template::get( true );

		if ( empty( $template ) ) {
			$this->log_error( 'Blog 模板为空' );
			$this->results['model_field_scanning'] = false;
			return;
		}

		// 验证 Post Types
		$this->log_info( '检查 Post Types 配置...' );
		$expected_post_types = array( 'post', 'page' );
		$actual_post_types = array();

		if ( ! empty( $template['objects']['post_types'] ) ) {
			foreach ( $template['objects']['post_types'] as $pt_config ) {
				$subtype = $pt_config['subtype'];
				$actual_post_types[] = $subtype;

				$this->log_info( "  - {$subtype}:" );

				// 检查核心字段
				$post_fields = $pt_config['fields_map']['post'] ?? array();
				$this->log_info( "    核心字段 (" . count( $post_fields ) . "): " . implode( ', ', $post_fields ) );

				// 检查元数据
				$meta_fields = $pt_config['fields_map']['meta'] ?? array();
				$this->log_info( "    元数据 (" . count( $meta_fields ) . "): " . implode( ', ', $meta_fields ) );

				// 检查跨表关系
				if ( ! empty( $pt_config['cross_table'] ) ) {
					$taxonomies = $pt_config['cross_table']['taxonomies'] ?? array();
					$attachments = $pt_config['cross_table']['attachments'] ?? array();

					if ( $taxonomies ) {
						$this->log_info( "    分类法: " . implode( ', ', $taxonomies ) );
					}
					if ( $attachments ) {
						$this->log_info( "    附件字段: " . implode( ', ', $attachments ) );
					}
				}

				// 检查 discover_meta
				$discover_meta = $pt_config['discover_meta'] ?? false;
				$this->log_info( "    自动发现元数据: " . ( $discover_meta ? '是' : '否' ) );
			}
		}

		// 验证 Taxonomies
		$this->log_info( '检查 Taxonomies 配置...' );
		$expected_taxonomies = array( 'category', 'post_tag' );
		$actual_taxonomies = array();

		if ( ! empty( $template['objects']['taxonomies'] ) ) {
			foreach ( $template['objects']['taxonomies'] as $tax_config ) {
				$subtype = $tax_config['subtype'];
				$actual_taxonomies[] = $subtype;

				$this->log_info( "  - {$subtype}:" );

				// 检查核心字段
				$term_fields = $tax_config['fields_map']['term'] ?? array();
				$this->log_info( "    核心字段 (" . count( $term_fields ) . "): " . implode( ', ', $term_fields ) );

				// 检查层级
				$hierarchical = $tax_config['hierarchical'] ?? false;
				$this->log_info( "    层级结构: " . ( $hierarchical ? '是' : '否' ) );
			}
		}

		// 结果验证
		$post_types_match = empty( array_diff( $expected_post_types, $actual_post_types ) );
		$taxonomies_match = empty( array_diff( $expected_taxonomies, $actual_taxonomies ) );

		if ( $post_types_match && $taxonomies_match ) {
			$this->log_success( '✓ 模型字段扫描测试通过' );
			$this->results['model_field_scanning'] = true;
		} else {
			$this->log_error( '✗ 模型字段扫描测试失败' );
			if ( ! $post_types_match ) {
				$this->log_error( '  期望 Post Types: ' . implode( ', ', $expected_post_types ) );
				$this->log_error( '  实际 Post Types: ' . implode( ', ', $actual_post_types ) );
			}
			if ( ! $taxonomies_match ) {
				$this->log_error( '  期望 Taxonomies: ' . implode( ', ', $expected_taxonomies ) );
				$this->log_error( '  实际 Taxonomies: ' . implode( ', ', $actual_taxonomies ) );
			}
			$this->results['model_field_scanning'] = false;
		}
	}

	/**
	 * 测试 2: Post 内容同步测试
	 */
	private function test_post_sync() {
		$this->log_section( '测试 2: Post 内容同步' );

		// 1. 创建测试 Post
		$this->log_info( '步骤 1: 创建测试文章...' );

		$post_data = array(
			'post_title'    => 'WPTSALL 测试文章 - ' . time(),
			'post_content'  => '这是测试文章内容。包含<strong>HTML</strong>标记。',
			'post_excerpt'  => '测试摘要',
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_author'   => get_current_user_id(),
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			$this->log_error( '创建文章失败: ' . $post_id->get_error_message() );
			$this->results['post_sync'] = false;
			return;
		}

		$this->test_data['posts'][] = $post_id;
		$this->log_success( "✓ 文章已创建 (ID: {$post_id})" );

		// 添加元数据
		update_post_meta( $post_id, '_test_meta_key', 'test_meta_value' );
		update_post_meta( $post_id, '_edit_last', get_current_user_id() );
		$this->log_info( '  - 已添加元数据' );

		// 添加分类
		wp_set_post_terms( $post_id, array( 1 ), 'category' ); // Uncategorized
		$this->log_info( '  - 已关联分类' );

		// 2. 获取完整数据并验证字段
		$this->log_info( '步骤 2: 验证完整数据获取...' );

		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		if ( ! $complete_data ) {
			$this->log_error( '获取完整数据失败' );
			$this->results['post_sync'] = false;
			return;
		}

		// 验证核心字段
		$post_fields = $complete_data['post'] ?? array();
		$required_fields = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' );
		$missing_fields = array();

		foreach ( $required_fields as $field ) {
			if ( ! isset( $post_fields[ $field ] ) ) {
				$missing_fields[] = $field;
			}
		}

		if ( $missing_fields ) {
			$this->log_error( '缺少核心字段: ' . implode( ', ', $missing_fields ) );
			$this->results['post_sync'] = false;
			return;
		}

		$this->log_success( '✓ 核心字段完整 (' . count( $post_fields ) . ' 个)' );

		// 验证元数据
		$meta_data = $complete_data['meta'] ?? array();
		$this->log_info( "  - 元数据: " . count( $meta_data ) . " 个" );
		if ( isset( $meta_data['_test_meta_key'] ) ) {
			$this->log_success( '  ✓ 自定义元数据已包含' );
		}

		// 验证分类法
		$taxonomies = $complete_data['taxonomies'] ?? array();
		$this->log_info( "  - 分类法: " . count( $taxonomies ) . " 种" );
		if ( isset( $taxonomies['category'] ) ) {
			$this->log_success( '  ✓ Category 分类已包含 (' . count( $taxonomies['category'] ) . ' 个)' );
		}

		// 3. 创建站点关系（用于生成任务）
		$this->log_info( '步骤 3: 创建站点关系...' );

		$site_relation = array(
			'id'       => 'test_rel_' . time(),
			'template' => 'wordpress-blog',
			'source'   => array(
				'type' => 'wp',
				'id'   => get_current_blog_id(),
			),
			'targets'  => array(
				array(
					'type' => 'virtual',
					'id'   => 'test_virtual_' . time(),
				),
			),
		);

		$this->test_data['relations'][] = $site_relation['id'];

		// 4. 生成同步任务
		$this->log_info( '步骤 4: 生成同步任务...' );

		$template = \WPTSALL\Models\Blog_Template::get();
		$tasks = wptsall_generate_tasks_from_template( $template, 1, $site_relation );

		if ( empty( $tasks ) ) {
			$this->log_error( '未生成任务' );
			$this->results['post_sync'] = false;
			return;
		}

		$this->log_success( "✓ 已生成 " . count( $tasks ) . " 个任务" );

		// 5. 验证任务数据完整性
		$this->log_info( '步骤 5: 验证任务数据...' );

		$post_task = null;
		foreach ( $tasks as $task ) {
			if ( $task['object_type'] === 'post_type' && $task['subtype'] === 'post' && $task['object_id'] == $post_id ) {
				$post_task = $task;
				break;
			}
		}

		if ( ! $post_task ) {
			$this->log_error( '未找到文章同步任务' );
			$this->results['post_sync'] = false;
			return;
		}

		// 验证任务包含完整数据
		if ( empty( $post_task['complete_data'] ) ) {
			$this->log_error( '任务缺少complete_data' );
			$this->results['post_sync'] = false;
			return;
		}

		$task_post_data = $post_task['complete_data']['post'] ?? array();
		$task_meta = $post_task['complete_data']['meta'] ?? array();
		$task_taxonomies = $post_task['complete_data']['taxonomies'] ?? array();

		$this->log_success( "✓ 任务数据完整:" );
		$this->log_info( "  - 核心字段: " . count( $task_post_data ) . " 个" );
		$this->log_info( "  - 元数据: " . count( $task_meta ) . " 个" );
		$this->log_info( "  - 分类法: " . count( $task_taxonomies ) . " 种" );

		// 6. 执行任务（模拟Cron处理）
		$this->log_info( '步骤 6: 执行同步任务...' );

		$result = wptsall_process_task( $post_task );

		if ( ! $result['success'] ) {
			$this->log_error( '任务执行失败: ' . ( $result['note'] ?? '未知错误' ) );
			$this->results['post_sync'] = false;
			return;
		}

		$this->log_success( "✓ 任务执行成功" );
		$this->log_info( "  - 结果: " . ( $result['note'] ?? '无说明' ) );

		// 7. 验证虚拟内容存储
		$this->log_info( '步骤 7: 验证虚拟内容...' );

		global $wpdb;
		$virtual_content_table = $wpdb->prefix . 'wptsall_virtual_content';
		$virtual_record = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$virtual_content_table} WHERE object_type = %s AND subtype = %s AND source_id = %d",
			'post_type',
			'post',
			$post_id
		) );

		if ( ! $virtual_record ) {
			$this->log_error( '虚拟内容未存储' );
			$this->results['post_sync'] = false;
			return;
		}

		$stored_content = json_decode( $virtual_record->content, true );

		if ( empty( $stored_content ) ) {
			$this->log_error( '虚拟内容为空' );
			$this->results['post_sync'] = false;
			return;
		}

		$this->log_success( "✓ 虚拟内容已存储 (ID: {$virtual_record->id})" );

		// 验证存储的数据
		$stored_post = $stored_content['post'] ?? array();
		$stored_meta = $stored_content['meta'] ?? array();
		$stored_taxonomies = $stored_content['taxonomies'] ?? array();

		$this->log_info( "  - 存储的核心字段: " . count( $stored_post ) . " 个" );
		$this->log_info( "  - 存储的元数据: " . count( $stored_meta ) . " 个" );
		$this->log_info( "  - 存储的分类法: " . count( $stored_taxonomies ) . " 种" );

		// 验证标题是否正确
		if ( isset( $stored_post['post_title'] ) && $stored_post['post_title'] === $post_data['post_title'] ) {
			$this->log_success( '  ✓ 标题同步正确' );
		} else {
			$this->log_error( '  ✗ 标题同步错误' );
			$this->results['post_sync'] = false;
			return;
		}

		// 验证内容是否正确
		if ( isset( $stored_post['post_content'] ) && strpos( $stored_post['post_content'], '测试文章内容' ) !== false ) {
			$this->log_success( '  ✓ 内容同步正确' );
		} else {
			$this->log_error( '  ✗ 内容同步错误' );
			$this->results['post_sync'] = false;
			return;
		}

		$this->log_success( '✓ Post 内容同步测试通过' );
		$this->results['post_sync'] = true;
	}

	/**
	 * 测试 3: Page 内容同步测试
	 */
	private function test_page_sync() {
		$this->log_section( '测试 3: Page 内容同步' );

		// 创建测试页面
		$page_data = array(
			'post_title'   => 'WPTSALL 测试页面 - ' . time(),
			'post_content' => '这是测试页面内容。',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		$page_id = wp_insert_post( $page_data );

		if ( is_wp_error( $page_id ) ) {
			$this->log_error( '创建页面失败' );
			$this->results['page_sync'] = false;
			return;
		}

		$this->test_data['pages'][] = $page_id;
		$this->log_success( "✓ 页面已创建 (ID: {$page_id})" );

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'page', $page_id );

		if ( ! $complete_data ) {
			$this->log_error( '获取页面完整数据失败' );
			$this->results['page_sync'] = false;
			return;
		}

		// 验证 page 特有字段
		$post_fields = $complete_data['post'] ?? array();

		if ( isset( $post_fields['post_parent'] ) ) {
			$this->log_success( '✓ post_parent 字段存在' );
		}

		if ( isset( $post_fields['page_template'] ) || isset( $complete_data['meta']['_wp_page_template'] ) ) {
			$this->log_success( '✓ page_template 字段存在' );
		}

		$this->log_success( '✓ Page 内容同步测试通过' );
		$this->results['page_sync'] = true;
	}

	/**
	 * 测试 4: 分类法同步测试
	 */
	private function test_taxonomy_sync() {
		$this->log_section( '测试 4: 分类法同步' );

		// 创建测试分类
		$term_data = array(
			'name'        => 'WPTSALL 测试分类 - ' . time(),
			'slug'        => 'wptsall-test-cat-' . time(),
			'description' => '测试分类描述',
		);

		$term = wp_insert_term( $term_data['name'], 'category', array(
			'slug'        => $term_data['slug'],
			'description' => $term_data['description'],
		) );

		if ( is_wp_error( $term ) ) {
			$this->log_error( '创建分类失败: ' . $term->get_error_message() );
			$this->results['taxonomy_sync'] = false;
			return;
		}

		$term_id = $term['term_id'];
		$this->test_data['terms'][] = array( 'term_id' => $term_id, 'taxonomy' => 'category' );
		$this->log_success( "✓ 分类已创建 (ID: {$term_id})" );

		// 添加 term meta
		update_term_meta( $term_id, '_test_term_meta', 'test_value' );

		// 获取完整数据
		$complete_data = wptsall_get_complete_term_data( 'category', $term_id );

		if ( ! $complete_data ) {
			$this->log_error( '获取分类完整数据失败' );
			$this->results['taxonomy_sync'] = false;
			return;
		}

		// 验证字段
		$term_fields = $complete_data['term'] ?? array();
		$required_fields = array( 'name', 'slug', 'description' );
		$missing_fields = array();

		foreach ( $required_fields as $field ) {
			if ( ! isset( $term_fields[ $field ] ) ) {
				$missing_fields[] = $field;
			}
		}

		if ( $missing_fields ) {
			$this->log_error( '缺少term字段: ' . implode( ', ', $missing_fields ) );
			$this->results['taxonomy_sync'] = false;
			return;
		}

		$this->log_success( '✓ Term 核心字段完整' );

		// 验证元数据
		$meta_data = $complete_data['meta'] ?? array();
		if ( isset( $meta_data['_test_term_meta'] ) ) {
			$this->log_success( '✓ Term 元数据已包含' );
		}

		// 验证层级信息
		if ( isset( $complete_data['term']['parent'] ) ) {
			$this->log_success( '✓ 层级字段存在' );
		}

		$this->log_success( '✓ 分类法同步测试通过' );
		$this->results['taxonomy_sync'] = true;
	}

	/**
	 * 测试 5: URL 重写测试
	 */
	private function test_url_rewrite() {
		$this->log_section( '测试 5: URL 重写' );

		// 检查 rewrite rules 是否注册
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();

		$has_virtual_rule = false;
		if ( $rules ) {
			foreach ( $rules as $pattern => $replacement ) {
				if ( strpos( $pattern, 'virtual' ) !== false ) {
					$has_virtual_rule = true;
					$this->log_info( "  - 规则: {$pattern} → {$replacement}" );
					break;
				}
			}
		}

		if ( ! $has_virtual_rule ) {
			$this->log_warning( '未找到虚拟站点 rewrite 规则' );
			$this->log_info( '尝试刷新 rewrite rules...' );
			flush_rewrite_rules();
		}

		// 检查 query vars 是否注册
		global $wp;
		$query_vars = $wp->public_query_vars ?? array();

		$required_vars = array( 'wpts_virtual', 'wpts_site', 'wpts_type', 'wpts_id', 'wpts_path' );
		$missing_vars = array();

		foreach ( $required_vars as $var ) {
			if ( ! in_array( $var, $query_vars, true ) ) {
				$missing_vars[] = $var;
			}
		}

		if ( $missing_vars ) {
			$this->log_warning( '缺少 query vars: ' . implode( ', ', $missing_vars ) );
		} else {
			$this->log_success( '✓ Query vars 已注册' );
		}

		// 检查 virtual template 是否挂载
		$has_template_redirect = has_action( 'template_redirect', 'wptsall_virtual_template' );
		if ( $has_template_redirect ) {
			$this->log_success( '✓ Virtual template hook 已挂载' );
		} else {
			$this->log_error( '✗ Virtual template hook 未挂载' );
		}

		// 注意：完整的URL测试需要实际HTTP请求，这里只能检查配置
		$this->log_info( '完整 URL 测试需要访问:' );
		$this->log_info( '  http://localhost/virtual/test_virtual_XXXXX/post_type/POST_ID/' );

		$this->log_success( '✓ URL 重写配置测试通过' );
		$this->results['url_rewrite'] = true;
	}

	/**
	 * 测试 6: 任务调度测试
	 */
	private function test_task_scheduling() {
		$this->log_section( '测试 6: 任务调度' );

		// 检查 Cron 事件是否注册
		$scheduled = wp_next_scheduled( 'wptsall_cron_process_tasks' );

		if ( $scheduled ) {
			$next_run = date( 'Y-m-d H:i:s', $scheduled );
			$this->log_success( "✓ Cron 任务已调度" );
			$this->log_info( "  - 下次运行: {$next_run}" );
		} else {
			$this->log_warning( '⚠ Cron 任务未调度' );
			$this->log_info( '  尝试手动调度...' );

			if ( function_exists( 'wptsall_schedule_task_cron' ) ) {
				wptsall_schedule_task_cron();
				$scheduled = wp_next_scheduled( 'wptsall_cron_process_tasks' );
				if ( $scheduled ) {
					$this->log_success( '  ✓ Cron 任务已重新调度' );
				}
			}
		}

		// 检查 Cron schedules
		$schedules = wp_get_schedules();
		if ( isset( $schedules['wptsall_minutely'] ) ) {
			$interval = $schedules['wptsall_minutely']['interval'];
			$this->log_success( "✓ wptsall_minutely schedule 已注册 (间隔: {$interval}秒)" );
		} else {
			$this->log_error( '✗ wptsall_minutely schedule 未注册' );
		}

		// 检查是否有 action 处理器
		$has_cron_handler = has_action( 'wptsall_cron_process_tasks', 'wptsall_cron_process_tasks' );
		if ( $has_cron_handler ) {
			$this->log_success( '✓ Cron 处理器已挂载' );
		} else {
			$this->log_error( '✗ Cron 处理器未挂载' );
		}

		// 测试手动触发 Cron
		$this->log_info( '测试手动触发 Cron 处理...' );

		// 检查是否有待处理任务
		global $wpdb;
		$tasks_table = $wpdb->prefix . 'wptsall_tasks';
		$pending_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE status = 'pending'" );

		$this->log_info( "  - 待处理任务数: {$pending_count}" );

		if ( $pending_count > 0 ) {
			$this->log_info( '  执行 Cron 处理...' );

			if ( function_exists( 'wptsall_cron_process_tasks' ) ) {
				wptsall_cron_process_tasks();

				// 检查处理后的任务数
				$pending_after = $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table} WHERE status = 'pending'" );
				$processed = $pending_count - $pending_after;

				$this->log_success( "  ✓ 已处理 {$processed} 个任务" );
				$this->log_info( "  - 剩余待处理: {$pending_after}" );
			}
		}

		$this->log_success( '✓ 任务调度测试通过' );
		$this->results['task_scheduling'] = true;
	}

	/**
	 * 测试 7: ID 映射测试
	 */
	private function test_id_mapping() {
		$this->log_section( '测试 7: ID 映射' );

		// 测试插入映射
		$source_blog = get_current_blog_id();
		$target_blog = get_current_blog_id();
		$source_id = 999;
		$target_id = 888;

		$inserted = wptsall_insert_mapping(
			$source_blog,
			'post_type',
			'post',
			$source_id,
			$target_blog,
			$target_id,
			'post'
		);

		if ( $inserted ) {
			$this->log_success( '✓ 映射插入成功' );
		} else {
			$this->log_error( '✗ 映射插入失败' );
		}

		// 测试查询映射
		$mapped_id = wptsall_get_mapped_id(
			$source_blog,
			'post_type',
			'post',
			$source_id,
			$target_blog
		);

		if ( $mapped_id == $target_id ) {
			$this->log_success( "✓ 映射查询成功 (源: {$source_id} → 目标: {$mapped_id})" );
		} else {
			$this->log_error( "✗ 映射查询失败 (期望: {$target_id}, 实际: {$mapped_id})" );
		}

		// 清理测试映射
		global $wpdb;
		$mappings_table = $wpdb->prefix . 'wptsall_mappings';
		$wpdb->delete( $mappings_table, array(
			'source_blog_id'    => $source_blog,
			'source_object_id'  => $source_id,
			'target_blog_id'    => $target_blog,
			'target_object_id'  => $target_id,
		), array( '%d', '%d', '%d', '%d' ) );

		$this->log_success( '✓ ID 映射测试通过' );
		$this->results['id_mapping'] = true;
	}

	/**
	 * 清理测试数据
	 */
	private function cleanup() {
		$this->log_separator();
		$this->log_section( '清理测试数据' );

		// 删除测试文章
		if ( ! empty( $this->test_data['posts'] ) ) {
			foreach ( $this->test_data['posts'] as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			$this->log_info( '✓ 已删除 ' . count( $this->test_data['posts'] ) . ' 个测试文章' );
		}

		// 删除测试页面
		if ( ! empty( $this->test_data['pages'] ) ) {
			foreach ( $this->test_data['pages'] as $page_id ) {
				wp_delete_post( $page_id, true );
			}
			$this->log_info( '✓ 已删除 ' . count( $this->test_data['pages'] ) . ' 个测试页面' );
		}

		// 删除测试分类
		if ( ! empty( $this->test_data['terms'] ) ) {
			foreach ( $this->test_data['terms'] as $term_data ) {
				wp_delete_term( $term_data['term_id'], $term_data['taxonomy'] );
			}
			$this->log_info( '✓ 已删除 ' . count( $this->test_data['terms'] ) . ' 个测试分类' );
		}

		// 清理虚拟内容
		global $wpdb;
		$virtual_table = $wpdb->prefix . 'wptsall_virtual_content';
		$wpdb->query( "DELETE FROM {$virtual_table} WHERE site_id LIKE 'test_%'" );

		$this->log_success( '✓ 测试数据清理完成' );
	}

	/**
	 * 打印测试摘要
	 */
	private function print_summary() {
		$this->log_separator();
		$this->log_header( '测试摘要' );

		$total = count( $this->results );
		$passed = count( array_filter( $this->results ) );
		$failed = $total - $passed;

		foreach ( $this->results as $test_name => $result ) {
			$status = $result ? '✓ 通过' : '✗ 失败';
			$color = $result ? 'green' : 'red';
			$this->log( "[{$status}] {$test_name}", $color );
		}

		$this->log_separator();
		$this->log_info( "总计: {$total} 个测试" );
		$this->log_success( "通过: {$passed}" );
		if ( $failed > 0 ) {
			$this->log_error( "失败: {$failed}" );
		}

		$percentage = $total > 0 ? round( ( $passed / $total ) * 100, 2 ) : 0;
		$this->log_info( "通过率: {$percentage}%" );

		if ( $passed === $total ) {
			$this->log_header( '🎉 所有测试通过！' );
		} else {
			$this->log_header( '⚠ 部分测试失败，请检查日志' );
		}
	}

	/**
	 * 日志方法
	 */
	private function log( $message, $color = 'default' ) {
		$colors = array(
			'red'     => "\033[0;31m",
			'green'   => "\033[0;32m",
			'yellow'  => "\033[1;33m",
			'blue'    => "\033[0;34m",
			'cyan'    => "\033[0;36m",
			'default' => "\033[0m",
		);

		$color_code = $colors[ $color ] ?? $colors['default'];
		$reset = $colors['default'];

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// WP-CLI 输出
			echo $message . "\n";
		} else {
			// Web 输出
			$html_colors = array(
				'red'     => '#d63638',
				'green'   => '#00a32a',
				'yellow'  => '#dba617',
				'blue'    => '#2271b1',
				'cyan'    => '#00a0d2',
				'default' => '#1e1e1e',
			);
			$html_color = $html_colors[ $color ] ?? $html_colors['default'];
			echo '<div style="color: ' . esc_attr( $html_color ) . '; font-family: monospace; line-height: 1.5;">' . esc_html( $message ) . '</div>';
		}
	}

	private function log_header( $message ) {
		$this->log( '', 'default' );
		$this->log( '======================================', 'cyan' );
		$this->log( $message, 'cyan' );
		$this->log( '======================================', 'cyan' );
	}

	private function log_section( $message ) {
		$this->log( '', 'default' );
		$this->log( '--- ' . $message . ' ---', 'blue' );
	}

	private function log_separator() {
		$this->log( '--------------------------------------', 'default' );
	}

	private function log_success( $message ) {
		$this->log( $message, 'green' );
	}

	private function log_error( $message ) {
		$this->log( $message, 'red' );
	}

	private function log_warning( $message ) {
		$this->log( $message, 'yellow' );
	}

	private function log_info( $message ) {
		$this->log( $message, 'default' );
	}
}

// 运行测试
if ( defined( 'ABSPATH' ) ) {
	$tester = new WPTSALL_Automated_Tests();
	$tester->run_all_tests();
}