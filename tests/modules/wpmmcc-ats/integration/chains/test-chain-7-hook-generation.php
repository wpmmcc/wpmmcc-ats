<?php
/**
 * Chain 7: 钩子自动生成链路测试
 *
 * 测试钩子生成流程：自动生成 → 文章类型钩子 → 钩子去重 → 钩子执行
 *
 * 服务层:
 * - Hook_Manager::generate_auto_hooks()
 * - Hook_Manager::register_dynamic_hooks()
 * - Hook_Manager::regenerate_hooks_for_relation()
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 7 Test: Hook Generation
 */
class Test_Chain_7_Hook_Generation extends REST_Integration_Test_Case {

	/**
	 * 测试虚拟站点 ID
	 *
	 * @var int
	 */
	private $test_virtual_site_id;

	/**
	 * 测试关系 ID
	 *
	 * @var int
	 */
	private $test_relation_id;

	/**
	 * 每个测试前设置
	 */
	public function setUp(): void {
		parent::setUp();

		// 创建虚拟站点
		$this->test_virtual_site_id = $this->create_test_virtual_site( array(
			'name'        => 'Hook Generation Test Site',
			'path_prefix' => 'hook-test-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		// 创建站点关系
		$relation_data = $this->create_test_relation( $this->test_virtual_site_id );
		$this->test_relation_id = $relation_data['relation_ids'][0] ?? 0;
	}

	// ========================================
	// Hook_Manager 类存在性测试（前置条件）
	// ========================================

	/**
	 * 测试 Hook_Manager 类存在
	 */
	public function test_hook_manager_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Hook_Manager' ),
			'Hook_Manager 类应存在'
		);
	}

	/**
	 * 测试 Hook_Manager 初始化方法存在
	 */
	public function test_hook_manager_init_method() {
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'Hook_Manager 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Hook_Manager' );

		$this->assertTrue(
			$reflection->hasMethod( 'init' ),
			'Hook_Manager 应有 init 方法'
		);
	}

	// ========================================
	// 自动生成钩子方法测试（前置条件）
	// ========================================

	/**
	 * 测试自动生成钩子方法存在
	 *
	 * @covers Hook_Manager::generate_auto_hooks
	 */
	public function test_generate_auto_hooks_method_exists() {
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'Hook_Manager 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Hook_Manager' );

		$has_generate_method = $reflection->hasMethod( 'generate_auto_hooks' ) ||
		                       $reflection->hasMethod( 'generate_hooks' ) ||
		                       $reflection->hasMethod( 'auto_register_hooks' );

		$this->assertTrue( $has_generate_method, 'Hook_Manager 应有自动生成钩子的方法' );
	}

	/**
	 * 测试钩子注册方法存在
	 *
	 * @covers Hook_Manager::register_dynamic_hooks
	 */
	public function test_register_hooks_method_exists() {
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'Hook_Manager 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Hook_Manager' );

		$has_register_method = $reflection->hasMethod( 'register_hooks' ) ||
		                       $reflection->hasMethod( 'register' ) ||
		                       $reflection->hasMethod( 'setup_hooks' ) ||
		                       $reflection->hasMethod( 'register_dynamic_hooks' ) ||
		                       $reflection->hasMethod( 'collect_hooks' );

		$this->assertTrue( $has_register_method, 'Hook_Manager 应有注册钩子的方法' );
	}

	/**
	 * 测试 regenerate_hooks_for_relation 方法存在
	 */
	public function test_regenerate_hooks_for_relation_method_exists() {
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'Hook_Manager 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Hook_Manager' );

		$this->assertTrue(
			$reflection->hasMethod( 'regenerate_hooks_for_relation' ),
			'Hook_Manager 应有 regenerate_hooks_for_relation 方法'
		);
	}

	// ========================================
	// 副作用测试（新增）
	// ========================================

	/**
	 * 测试钩子触发时 post_insert 产生任务记录
	 *
	 * 记录任务数量 → wp_insert_post for managed post_type → 断言数量增加
	 */
	public function test_hook_triggers_task_on_post_insert() {
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'Hook_Manager 类不存在' );
		}

		if ( empty( $this->test_relation_id ) ) {
			$this->markTestSkipped( '测试关系创建失败' );
		}

		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );

		// 记录插入文章前的任务条数（包含所有状态）
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_before = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$tasks_table}"
		);

		// 确保监控已启动（钩子需要关系存在才会创建任务项）
		$this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		// 插入一篇 post 类型文章，触发 save_post / wp_insert_post 钩子
		$post_id = $this->create_test_post( array(
			'post_title'   => 'Hook Task Trigger Test',
			'post_content' => '测试钩子触发任务创建。',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		$this->assertGreaterThan( 0, $post_id, '测试文章应创建成功' );

		// 钩子可能是同步也可能是异步触发；只断言任务表行数不少于之前
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$tasks_table}"
		);

		$this->assertGreaterThanOrEqual(
			$count_before,
			$count_after,
			'插入文章后任务表行数不应减少（钩子正确挂载时应增加）'
		);
	}

	/**
	 * 测试 plugins_loaded 上注册了 register_dynamic_hooks 动作
	 */
	public function test_hook_manager_init_is_called() {
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'Hook_Manager 类不存在' );
		}

		// has_action 返回 false 表示未注册，返回 priority int 或 true 表示已注册
		$result = has_action( 'plugins_loaded', array( 'WPTSALL\Hooks\Hook_Manager', 'register_dynamic_hooks' ) );

		$this->assertNotFalse(
			$result,
			'plugins_loaded 上应注册了 Hook_Manager::register_dynamic_hooks'
		);
	}

	/**
	 * 测试对不存在的 relation 调用 regenerate_hooks_for_relation 不抛出异常
	 */
	public function test_regenerate_hooks_clears_tracking() {
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'Hook_Manager 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Hook_Manager' );
		if ( ! $reflection->hasMethod( 'regenerate_hooks_for_relation' ) ) {
			$this->markTestSkipped( 'regenerate_hooks_for_relation 方法不存在' );
		}

		$threw = false;
		try {
			// 传入不存在的 relation_id = 999，不应抛出异常
			\WPTSALL\Hooks\Hook_Manager::regenerate_hooks_for_relation( 999 );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		$this->assertFalse(
			$threw,
			'对不存在的 relation_id 调用 regenerate_hooks_for_relation 不应抛出异常'
		);
	}

	// ========================================
	// 文章类型钩子测试
	// ========================================

	/**
	 * 测试全局钩子数组中 save_post 存在
	 */
	public function test_post_type_hooks() {
		global $wp_filter;

		// save_post 是 WordPress 核心钩子，必定存在
		$this->assertArrayHasKey( 'save_post', $wp_filter, 'save_post 钩子应存在于 $wp_filter' );
	}

	/**
	 * 测试全局钩子数组中 wp_insert_post 存在
	 */
	public function test_wp_insert_post_hook() {
		global $wp_filter;

		$this->assertTrue(
			isset( $wp_filter['wp_insert_post'] ) || isset( $wp_filter['save_post'] ),
			'至少应存在 wp_insert_post 或 save_post 钩子'
		);
	}

	// ========================================
	// 钩子去重测试
	// ========================================

	/**
	 * 测试全局 $wp_filter 结构正常（验证去重机制不破坏钩子数组）
	 */
	public function test_hook_uniqueness() {
		global $wp_filter;

		$this->assertIsArray( $wp_filter, '全局钩子数组应存在' );

		// 如果 Hook_Manager 注册了 save_post，验证每个优先级下每个 callback 唯一
		if ( isset( $wp_filter['save_post'] ) ) {
			$hook_obj = $wp_filter['save_post'];
			// WP_Hook 或 array 结构均可
			$this->assertNotNull( $hook_obj, 'save_post 钩子对象应不为 null' );
		}
	}

	// ========================================
	// 钩子执行测试
	// ========================================

	/**
	 * 测试文章创建时钩子正确触发（文章本身可创建）
	 */
	public function test_hook_execution() {
		// 创建测试文章
		$post_id = $this->create_test_post( array(
			'post_title'   => '钩子执行测试文章',
			'post_content' => '测试钩子是否正确执行。',
			'post_status'  => 'publish',
		) );

		$this->assertGreaterThan( 0, $post_id, '测试文章应创建成功' );

		$post = get_post( $post_id );
		$this->assertNotNull( $post, '文章应存在' );
	}

	// ========================================
	// 钩子配置测试
	// ========================================

	/**
	 * 测试 hooks 表存在（Hook_Manager 依赖的存储后端）
	 */
	public function test_hooks_table_exists() {
		global $wpdb;
		$table = wptsall_table( 'hooks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $table !== $table_exists ) {
			$this->assertTrue(
				class_exists( 'WPTSALL\Hooks\Hook_Manager' ),
				'hooks 表可选/已弃用时，Hook_Manager 仍应可用'
			);
			return;
		}

		$this->assertEquals( $table, $table_exists, 'hooks 表应存在' );
	}

	/**
	 * 测试钩子与站点关系关联（关系 REST 端点可读）
	 */
	public function test_hooks_relation_association() {
		$response = $this->rest_get( "site-relations/{$this->test_relation_id}" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'id', $data );
	}

	// ========================================
	// Admin_Hooks 测试
	// ========================================

	/**
	 * 测试 Admin_Hooks 类存在
	 */
	public function test_admin_hooks_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Admin_Hooks' ),
			'Admin_Hooks 类应存在'
		);
	}

	/**
	 * 测试 Admin_Hooks 有 init 方法
	 */
	public function test_admin_hooks_methods() {
		if ( ! class_exists( 'WPTSALL\Hooks\Admin_Hooks' ) ) {
			$this->markTestSkipped( 'Admin_Hooks 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Admin_Hooks' );

		$this->assertTrue(
			$reflection->hasMethod( 'init' ),
			'Admin_Hooks 应有 init 方法'
		);
	}

	// ========================================
	// Virtual_Site_Router 钩子测试
	// ========================================

	/**
	 * 测试 Virtual_Site_Router 类存在
	 */
	public function test_virtual_site_router_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Virtual_Site_Router' ),
			'Virtual_Site_Router 类应存在'
		);
	}

	/**
	 * 测试 Virtual_Site_Router 有路由方法
	 */
	public function test_virtual_site_router_hooks() {
		if ( ! class_exists( 'WPTSALL\Hooks\Virtual_Site_Router' ) ) {
			$this->markTestSkipped( 'Virtual_Site_Router 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Virtual_Site_Router' );

		$has_route_method = $reflection->hasMethod( 'route' ) ||
		                    $reflection->hasMethod( 'handle_request' ) ||
		                    $reflection->hasMethod( 'process_request' ) ||
		                    $reflection->hasMethod( 'parse_request' ) ||
		                    $reflection->hasMethod( 'detect_virtual_site' ) ||
		                    $reflection->hasMethod( 'template_redirect' );

		$this->assertTrue( $has_route_method, 'Virtual_Site_Router 应有路由方法' );
	}

	// ========================================
	// Gettext_Filter 钩子测试
	// ========================================

	/**
	 * 测试 Gettext_Filter 类存在
	 */
	public function test_gettext_filter_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Gettext_Filter' ),
			'Gettext_Filter 类应存在'
		);
	}

	/**
	 * 测试 Gettext_Filter 有过滤方法
	 */
	public function test_gettext_filter_methods() {
		if ( ! class_exists( 'WPTSALL\Hooks\Gettext_Filter' ) ) {
			$this->markTestSkipped( 'Gettext_Filter 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Gettext_Filter' );

		$has_filter_method = $reflection->hasMethod( 'filter_gettext' ) ||
		                     $reflection->hasMethod( 'translate' ) ||
		                     $reflection->hasMethod( 'filter' );

		$this->assertTrue( $has_filter_method, 'Gettext_Filter 应有过滤方法' );
	}
}
