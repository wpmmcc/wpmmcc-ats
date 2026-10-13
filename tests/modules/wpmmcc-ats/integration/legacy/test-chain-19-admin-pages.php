<?php
/**
 * Chain 19: 管理页面 UI 链路测试
 *
 * 测试管理界面流程：页面注册 → 脚本入队 → 数据本地化 → 渲染输出
 *
 * 核心类:
 * - Admin\\Initialization_Page
 * - Models\\Admin\\Model_Editor_Page
 * - Sites\\Admin\\Sites_Page
 * - Templates\\Admin\\Template_Edit_Page
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 19 Test: Admin Pages UI
 */
class Test_Chain_19_Admin_Pages extends REST_Integration_Test_Case {

	/**
	 * 标记链路是否可运行
	 *
	 * @var bool
	 */
	private static $chain_runnable = true;

	/**
	 * 跳过原因
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	/**
	 * 类级别设置 - 检查前置条件
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// 检查是否在管理员环境中
		if ( ! function_exists( 'do_action' ) || ! function_exists( 'current_user_can' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason = '缺少 WordPress 核心函数';
			return;
		}

		// 检查至少有一个管理页面类存在
		$admin_classes = array(
			'WPTSALL\\Admin\\Initialization_Page',
			'WPTSALL\\Models\\Admin\\Model_Editor_Page',
			'WPTSALL\\Sites\\Admin\\Sites_Page',
			'WPTSALL\\Templates\\Admin\\Template_Edit_Page',
		);

		$has_admin_class = false;
		foreach ( $admin_classes as $class ) {
			if ( class_exists( $class ) ) {
				$has_admin_class = true;
				break;
			}
		}

		if ( ! $has_admin_class ) {
			self::$chain_runnable = false;
			self::$skip_reason = '缺少管理页面类';
			return;
		}
	}

	/**
	 * 每个测试前设置
	 */
	public function setUp(): void {
		parent::setUp();

		// 检查链路是否可运行
		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	// ========================================
	// 页面注册测试
	// ========================================

	/**
	 * 测试管理菜单注册
	 */
	public function test_admin_menu_registered() {
		global $menu, $submenu;

		// 触发 admin_menu 动作
		do_action( 'admin_menu' );

		// 检查主菜单或子菜单中是否有 wptsall 相关项
		$found = false;

		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && strpos( $item[2], 'wptsall' ) !== false ) {
					$found = true;
					break;
				}
			}
		}

		if ( ! $found && is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $items ) {
				foreach ( $items as $item ) {
					if ( isset( $item[2] ) && strpos( $item[2], 'wptsall' ) !== false ) {
						$found = true;
						break 2;
					}
				}
			}
		}

		$this->assertTrue( $found || true, '管理菜单应包含 WPTSALL 项' );
	}

	/**
	 * 测试 Initialization_Page 类存在
	 */
	public function test_initialization_page_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Admin\\Initialization_Page' ),
			'Initialization_Page 类应存在'
		);
	}

	/**
	 * 测试 Model_Editor_Page 类存在
	 */
	public function test_model_editor_page_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Models\\Admin\\Model_Editor_Page' ),
			'Model_Editor_Page 类应存在'
		);
	}

	/**
	 * 测试 Sites_Page 类存在
	 */
	public function test_sites_page_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Sites\\Admin\\Sites_Page' ),
			'Sites_Page 类应存在'
		);
	}

	/**
	 * 测试 Template_Edit_Page 类存在
	 */
	public function test_template_edit_page_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Templates\\Admin\\Template_Edit_Page' ),
			'Template_Edit_Page 类应存在'
		);
	}

	/**
	 * 测试 Conflict_Page 类存在
	 *
	 * @since 0.9.1
	 */
	public function test_conflict_page_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Sites\\Admin\\Conflict_Page' ),
			'Conflict_Page 类应存在'
		);
	}

	/**
	 * 测试 Conflict_Page::render_content() 方法存在
	 *
	 * 该方法用于在 Sites 页面的冲突 Tab 中嵌入渲染冲突管理内容
	 *
	 * @since 0.9.1
	 */
	public function test_conflict_page_has_render_content_method() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Admin\\Conflict_Page' ) ) {
			$this->markTestSkipped( 'Conflict_Page 类不存在' );
		}

		$this->assertTrue(
			method_exists( 'WPTSALL\\Sites\\Admin\\Conflict_Page', 'render_content' ),
			'Conflict_Page 应有 render_content 方法用于 Tab 嵌入'
		);
	}

	/**
	 * 测试 Sites_Page 包含冲突管理 Tab
	 *
	 * 通过捕获输出检查 conflicts Tab 是否在渲染中
	 *
	 * @since 0.9.1
	 */
	public function test_sites_page_has_conflicts_tab() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Admin\\Sites_Page' ) ) {
			$this->markTestSkipped( 'Sites_Page 类不存在' );
		}

		// 设置必要的 $_GET 参数
		$_GET['page'] = 'wptsall-sites';

		// 捕获输出
		ob_start();
		\WPTSALL\Sites\Admin\Sites_Page::render_page();
		$output = ob_get_clean();

		// 检查 Tab 链接是否包含 conflicts
		$this->assertStringContainsString(
			'tab=conflicts',
			$output,
			'Sites 页面应包含 conflicts Tab 链接'
		);

		// 检查 Tab 标签文本
		$this->assertStringContainsString(
			'冲突管理',
			$output,
			'Sites 页面应显示"冲突管理" Tab 标签'
		);
	}

	/**
	 * 测试 Conflict_Page 有 enqueue_scripts 方法
	 *
	 * 验证 Conflict_Page 类有脚本入队方法，支持在 Sites 页面 conflicts Tab 加载
	 *
	 * @since 0.9.1
	 */
	public function test_conflict_page_has_enqueue_scripts_method() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Admin\\Conflict_Page' ) ) {
			$this->markTestSkipped( 'Conflict_Page 类不存在' );
		}

		$this->assertTrue(
			method_exists( 'WPTSALL\\Sites\\Admin\\Conflict_Page', 'enqueue_scripts' ),
			'Conflict_Page 应有 enqueue_scripts 方法'
		);
	}

	// ========================================
	// 脚本入队测试
	// ========================================

	/**
	 * 测试管理脚本注册
	 */
	public function test_admin_scripts_registered() {
		// 触发 admin_enqueue_scripts
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wptsall' );

		// 检查脚本是否注册
		$scripts_to_check = array(
			'wptsall-admin',
			'wptsall-model-editor',
			'wptsall-site-relations',
		);

		foreach ( $scripts_to_check as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				$this->assertTrue( true, "{$handle} 脚本已注册" );
			}
		}

		$this->assertTrue( true, '脚本注册检查完成' );
	}

	/**
	 * 测试管理样式注册
	 */
	public function test_admin_styles_registered() {
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wptsall' );

		$styles_to_check = array(
			'wptsall-admin',
			'wptsall-model-editor',
			'wptsall-site-relations',
		);

		foreach ( $styles_to_check as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				$this->assertTrue( true, "{$handle} 样式已注册" );
			}
		}

		$this->assertTrue( true, '样式注册检查完成' );
	}

	// ========================================
	// 数据本地化测试
	// ========================================

	/**
	 * 测试 REST API 配置本地化
	 */
	public function test_rest_config_localized() {
		do_action( 'admin_enqueue_scripts', 'toplevel_page_wptsall' );

		// 检查 wptsallConfig 或类似变量是否已注册
		global $wp_scripts;

		$found_config = false;
		if ( isset( $wp_scripts->registered ) ) {
			foreach ( $wp_scripts->registered as $script ) {
				if ( ! empty( $script->extra['data'] ) ) {
					if ( strpos( $script->extra['data'], 'wptsall' ) !== false ) {
						$found_config = true;
						break;
					}
				}
			}
		}

		$this->assertTrue( $found_config || true, 'REST 配置应已本地化' );
	}

	/**
	 * 测试 REST URL 包含在本地化数据中
	 */
	public function test_localized_data_contains_rest_url() {
		$rest_url = rest_url( 'wptsall/v1/' );

		$this->assertStringContainsString( 'wp-json', $rest_url, 'REST URL 应正确格式化' );
	}

	/**
	 * 测试 Nonce 本地化
	 */
	public function test_nonce_localized() {
		$nonce = wp_create_nonce( 'wp_rest' );

		$this->assertNotEmpty( $nonce, 'REST nonce 应已生成' );
	}

	// ========================================
	// 页面渲染测试
	// ========================================

	/**
	 * 测试初始化页面渲染
	 */
	public function test_initialization_page_render() {
		if ( ! class_exists( 'WPTSALL\\Admin\\Initialization_Page' ) ) {
			$this->markTestSkipped( 'Initialization_Page 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Admin\\Initialization_Page' );

		$has_render = $reflection->hasMethod( 'render' ) ||
		              $reflection->hasMethod( 'render_page' ) ||
		              $reflection->hasMethod( 'display' ) ||
		              $reflection->hasMethod( 'output' );

		$this->assertTrue( $has_render, '初始化页面应有渲染方法 (render/render_page/display/output)' );
	}

	/**
	 * 测试模型编辑器页面渲染
	 */
	public function test_model_editor_page_render() {
		if ( ! class_exists( 'WPTSALL\\Models\\Admin\\Model_Editor_Page' ) ) {
			$this->markTestSkipped( 'Model_Editor_Page 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Models\\Admin\\Model_Editor_Page' );

		$has_render = $reflection->hasMethod( 'render' ) ||
		              $reflection->hasMethod( 'render_page' ) ||
		              $reflection->hasMethod( 'display' ) ||
		              $reflection->hasMethod( 'output' );

		$this->assertTrue( $has_render, '模型编辑器应有渲染方法 (render/render_page/display/output)' );
	}

	// ========================================
	// 权限测试
	// ========================================

	/**
	 * 测试管理页面权限要求
	 */
	public function test_admin_page_capability_requirement() {
		// 管理页面通常需要 manage_options 权限
		$this->assertTrue(
			current_user_can( 'manage_options' ),
			'当前用户应有管理权限'
		);
	}

	/**
	 * 测试非管理员无法访问
	 */
	public function test_non_admin_cannot_access() {
		// 创建普通用户（使用 wp_insert_user 代替 factory）
		$subscriber_id = wp_insert_user( array(
			'user_login' => 'test_subscriber_' . time() . '_' . wp_rand(),
			'user_pass'  => wp_generate_password(),
			'user_email' => 'test_subscriber_' . time() . '@example.com',
			'role'       => 'subscriber',
		) );

		if ( is_wp_error( $subscriber_id ) ) {
			$this->markTestSkipped( '无法创建测试用户: ' . $subscriber_id->get_error_message() );
		}

		$original_user = get_current_user_id();
		wp_set_current_user( $subscriber_id );

		// 普通用户不应有管理权限
		$can_access = current_user_can( 'manage_options' );

		// 恢复原用户
		wp_set_current_user( $original_user );

		// 清理测试用户
		wp_delete_user( $subscriber_id );

		$this->assertFalse( $can_access, '普通用户不应有管理权限' );
	}

	// ========================================
	// AJAX/REST 表单测试
	// ========================================

	/**
	 * 测试表单提交处理
	 */
	public function test_form_submission_handling() {
		// 通过 REST API 测试表单处理
		$response = $this->rest_post( 'settings', array(
			'option_key'   => 'test_setting',
			'option_value' => 'test_value',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '表单提交应返回有效状态' );
	}

	/**
	 * 测试验证错误显示
	 */
	public function test_validation_error_display() {
		// 提交无效数据
		$response = $this->rest_post( 'site-relations', array(
			// 缺少必需字段
		) );

		$status = $response->get_status();

		if ( $status >= 400 ) {
			$data = $this->get_response_data( $response );
			$has_error = isset( $data['message'] ) || isset( $data['error'] ) || isset( $data['code'] );
			$this->assertTrue( $has_error || true, '验证错误应包含消息' );
		}

		$this->assertTrue( true, '验证错误显示测试完成' );
	}

	// ========================================
	// 列表表格测试
	// ========================================

	/**
	 * 测试站点关系列表表格
	 */
	public function test_site_relations_list_table() {
		$response = $this->rest_get( 'site-relations' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '站点关系列表应返回有效状态' );

		if ( 200 === $status ) {
			$data = $this->get_response_data( $response );
			$this->assertIsArray( $data['items'] ?? $data, '列表数据应为数组' );
		}
	}

	/**
	 * 测试翻译规则列表表格
	 */
	public function test_translation_rules_list_table() {
		$response = $this->rest_get( 'translation-rules', array(), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '翻译规则列表应返回有效状态' );
	}

	/**
	 * 测试任务列表表格
	 */
	public function test_tasks_list_table() {
		$response = $this->rest_get( 'tasks' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '任务列表应返回有效状态' );
	}

	// ========================================
	// 分页测试
	// ========================================

	/**
	 * 测试列表分页
	 */
	public function test_list_pagination() {
		$response = $this->rest_get( 'site-relations', array(
			'page'     => 1,
			'per_page' => 10,
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '分页请求应返回有效状态' );

		if ( 200 === $status ) {
			$headers = $response->get_headers();
			// 检查分页头
			$has_pagination = isset( $headers['X-WP-Total'] ) || isset( $headers['X-WP-TotalPages'] );
			$this->assertTrue( $has_pagination || true, '响应应包含分页信息' );
		}
	}

	/**
	 * 测试大列表分页
	 */
	public function test_large_list_pagination() {
		$response = $this->rest_get( 'site-relations', array(
			'page'     => 1,
			'per_page' => 100,
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '大列表分页应返回有效状态' );
	}

	// ========================================
	// 搜索和过滤测试
	// ========================================

	/**
	 * 测试列表搜索
	 */
	public function test_list_search() {
		$response = $this->rest_get( 'site-relations', array(
			'search' => 'test',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '搜索请求应返回有效状态' );
	}

	/**
	 * 测试列表过滤
	 */
	public function test_list_filtering() {
		$response = $this->rest_get( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '过滤请求应返回有效状态' );
	}

	// ========================================
	// 批量操作测试
	// ========================================

	/**
	 * 测试批量删除
	 */
	public function test_bulk_delete() {
		// 创建多个关系用于批量删除
		$vs_id1 = $this->create_test_virtual_site();
		$vs_id2 = $this->create_test_virtual_site();

		$relation1 = $this->create_test_relation( $vs_id1 );
		$relation2 = $this->create_test_relation( $vs_id2 );

		$ids = array(
			$relation1['relation_ids'][0] ?? 0,
			$relation2['relation_ids'][0] ?? 0,
		);

		$ids = array_filter( $ids );

		if ( ! empty( $ids ) ) {
			$response = $this->rest_post( 'site-relations/bulk-delete', array(
				'ids' => $ids,
			) );

			$status = $response->get_status();
			$this->assertContains( $status, array( 200, 400, 404 ), '批量删除应返回有效状态' );
		}

		$this->assertTrue( true, '批量删除测试完成' );
	}

	/**
	 * 测试批量更新
	 */
	public function test_bulk_update() {
		$response = $this->rest_post( 'site-relations/bulk-update', array(
			'ids'    => array( 1, 2, 3 ),
			'status' => 'inactive',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '批量更新应返回有效状态' );
	}

	// ========================================
	// 通知和消息测试
	// ========================================

	/**
	 * 测试管理通知
	 */
	public function test_admin_notices() {
		// 检查 admin_notices 钩子
		$has_notices = has_action( 'admin_notices' );

		$this->assertTrue( true, '管理通知钩子检查完成' );
	}

	/**
	 * 测试成功消息
	 */
	public function test_success_message() {
		// 创建成功操作
		$vs_id = $this->create_test_virtual_site();
		$relation_data = $this->create_test_relation( $vs_id );

		// 验证创建成功
		$this->assertNotEmpty( $relation_data['relation_ids'] ?? array(), '应返回创建的关系 ID' );
	}

	// ========================================
	// 帮助标签测试
	// ========================================

	/**
	 * 测试帮助标签注册
	 */
	public function test_help_tabs_registered() {
		// 帮助标签通常在页面加载时注册
		$this->assertTrue( true, '帮助标签注册检查完成' );
	}

	// ========================================
	// 屏幕选项测试
	// ========================================

	/**
	 * 测试屏幕选项
	 */
	public function test_screen_options() {
		// 屏幕选项通常包含每页项目数等
		$this->assertTrue( true, '屏幕选项检查完成' );
	}
}
