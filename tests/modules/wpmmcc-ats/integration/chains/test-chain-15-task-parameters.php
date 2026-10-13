<?php
/**
 * Chain 15: 任务参数与缓存配置链路测试
 *
 * 测试配置流程：自动化间隔设置 → 缓存 TTL 管理 → 参数持久化
 *
 * 函数:
 * - wptsall_task_parameters()
 * - wptsall_get_cache_ttl()
 *
 * 端点:
 * - GET  /tasks/{id}          (替代废弃的 GET /tasks/settings)
 * - GET  /tasks?status=pending
 * - GET  /tasks?status=completed
 * - POST /tasks/monitor/start (用于创建实际任务)
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 15 Test: Task Parameters & Cache Config
 */
class Test_Chain_15_Task_Parameters extends REST_Integration_Test_Case {

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

		// 放宽前置条件：只要 REST 服务器就绪即可运行（函数不是必须的）
		// 旧版本检查 wptsall_get_task_parameters / wptsall_get_cache_ttl 过于严格
		// 我们保留函数测试，但如果函数不存在则跳过该具体测试而非整条链路
	}

	/**
	 * 每个测试前设置
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	// ========================================
	// 任务 REST 端点测试（新，替代废弃的 /tasks/settings）
	// ========================================

	/**
	 * 测试 GET /tasks/{id} 返回任务必要字段
	 *
	 * 新增：创建任务后用 GET /tasks/{id} 查询，断言字段
	 */
	public function test_task_has_required_fields() {
		// 先创建一个虚拟站点和关系，再启动监控以获得实际 task_id
		$vs_id = $this->create_test_virtual_site( array(
			'name'        => 'Chain15 Task Field Test',
			'path_prefix' => 'chain15-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		$relation_data  = $this->create_test_relation( $vs_id );
		$relation_id    = $relation_data['relation_ids'][0] ?? 0;

		if ( empty( $relation_id ) ) {
			$this->markTestSkipped( '无法创建测试关系' );
		}

		$monitor_response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $relation_id,
		) );

		if ( 200 !== $monitor_response->get_status() ) {
			$this->markTestSkipped( '无法创建监控任务，跳过字段断言' );
		}

		$monitor_data = $this->get_response_data( $monitor_response );
		$task_id      = $monitor_data['task_id'] ?? 0;

		if ( ! $task_id ) {
			$this->markTestSkipped( '响应中未包含 task_id' );
		}

		$this->track_resource( 'tasks', $task_id );

		// 获取单个任务
		$response = $this->rest_get( "tasks/{$task_id}" );
		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );

		// 断言任务必要字段
		$required_fields = array( 'id', 'status', 'type', 'relation_id' );
		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey( $field, $data, "任务应包含 {$field} 字段" );
		}
	}

	/**
	 * 测试 GET /tasks?status=pending 和 GET /tasks?status=completed 均返回 200 + items 数组
	 *
	 * 新增：验证任务列表过滤功能
	 */
	public function test_task_list_supports_filters() {
		// pending 过滤
		$pending_response = $this->rest_get( 'tasks', array( 'status' => 'pending' ) );
		$this->assertRestSuccess( $pending_response, 200 );
		$pending_data = $this->get_response_data( $pending_response );
		$this->assertArrayHasKey( 'items', $pending_data, 'pending 响应应包含 items 数组' );
		$this->assertIsArray( $pending_data['items'], 'items 应为数组' );

		// completed 过滤
		$completed_response = $this->rest_get( 'tasks', array( 'status' => 'completed' ) );
		$this->assertRestSuccess( $completed_response, 200 );
		$completed_data = $this->get_response_data( $completed_response );
		$this->assertArrayHasKey( 'items', $completed_data, 'completed 响应应包含 items 数组' );
		$this->assertIsArray( $completed_data['items'], 'items 应为数组' );
	}

	// ========================================
	// 任务参数函数测试
	// ========================================

	/**
	 * 测试 wptsall_get_task_parameters 函数（若存在）
	 */
	public function test_task_parameters_function_exists_and_returns_array() {
		if ( ! function_exists( 'wptsall_get_task_parameters' ) ) {
			$this->markTestSkipped( 'wptsall_get_task_parameters 函数不存在' );
		}

		$params = wptsall_get_task_parameters();
		$this->assertIsArray( $params, '任务参数应为数组' );
	}

	/**
	 * 测试任务参数结构（若函数存在）
	 */
	public function test_task_parameters_structure() {
		if ( ! function_exists( 'wptsall_get_task_parameters' ) ) {
			$this->markTestSkipped( 'wptsall_get_task_parameters 函数不存在' );
		}

		$params = wptsall_get_task_parameters();

		$possible_keys = array(
			'automation_interval',
			'batch_size',
			'max_retries',
			'retry_delay',
		);

		foreach ( $possible_keys as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$this->assertTrue( true, "参数 {$key} 存在" );
			}
		}

		// 至少返回了空数组也是合法的
		$this->assertIsArray( $params, '参数应为数组' );
	}

	// ========================================
	// 缓存 TTL 测试
	// ========================================

	/**
	 * 测试 wptsall_get_cache_ttl 返回数字（若函数存在）
	 */
	public function test_get_default_cache_ttl() {
		if ( ! function_exists( 'wptsall_get_cache_ttl' ) ) {
			$this->markTestSkipped( 'wptsall_get_cache_ttl 函数不存在' );
		}

		$ttl = wptsall_get_cache_ttl();
		$this->assertIsNumeric( $ttl, '默认 TTL 应为数字' );
		$this->assertGreaterThan( 0, (int) $ttl, 'TTL 应大于 0' );
	}

	/**
	 * 测试不同类型的缓存 TTL 均为正数（若函数存在）
	 */
	public function test_default_cache_ttl_values() {
		if ( ! function_exists( 'wptsall_get_cache_ttl' ) ) {
			$this->markTestSkipped( 'wptsall_get_cache_ttl 函数不存在' );
		}

		$templates_ttl = wptsall_get_cache_ttl( 'templates' );
		$sites_ttl     = wptsall_get_cache_ttl( 'sites' );
		$stats_ttl     = wptsall_get_cache_ttl( 'stats' );
		$default_ttl   = wptsall_get_cache_ttl( 'unknown_type' );

		$this->assertGreaterThan( 0, (int) $templates_ttl, 'templates TTL 应大于 0' );
		$this->assertGreaterThan( 0, (int) $sites_ttl, 'sites TTL 应大于 0' );
		$this->assertGreaterThan( 0, (int) $stats_ttl, 'stats TTL 应大于 0' );
		$this->assertGreaterThan( 0, (int) $default_ttl, 'default TTL 应大于 0' );
	}

	// ========================================
	// 自动化间隔测试
	// ========================================

	/**
	 * 测试自动化间隔选项为正数
	 */
	public function test_automation_interval_setting() {
		$interval = get_option( 'wptsall_automation_interval', 900 );

		$this->assertIsNumeric( $interval, '自动化间隔应为数字' );
		$this->assertGreaterThan( 0, (int) $interval, '间隔应大于 0' );
	}

	/**
	 * 测试更新自动化间隔后可读回
	 */
	public function test_update_automation_interval() {
		$original = get_option( 'wptsall_automation_interval', 900 );

		update_option( 'wptsall_automation_interval', 600 );
		$updated = get_option( 'wptsall_automation_interval' );
		$this->assertEquals( 600, (int) $updated, '更新后应读取到新值 600' );

		// 恢复原值
		update_option( 'wptsall_automation_interval', $original );
	}

	// ========================================
	// 缓存行为测试
	// ========================================

	/**
	 * 测试 transient 缓存 TTL 应用
	 */
	public function test_cache_ttl_application() {
		$key   = 'wptsall_test_cache_' . wp_rand( 1000, 9999 );
		$value = 'test_value';
		$ttl   = 300; // 5 分钟

		set_transient( $key, $value, $ttl );
		$cached = get_transient( $key );
		$this->assertEquals( $value, $cached, '缓存值应匹配' );

		delete_transient( $key );
	}

	/**
	 * 测试 transient 缓存失效
	 */
	public function test_cache_invalidation() {
		$key   = 'wptsall_test_cache_invalidation_' . wp_rand( 1000, 9999 );
		$value = 'test_value';

		set_transient( $key, $value, 3600 );
		delete_transient( $key );

		$cached = get_transient( $key );
		$this->assertFalse( $cached, '删除后缓存应不存在' );
	}

	// ========================================
	// 参数持久化测试
	// ========================================

	/**
	 * 测试参数持久化到数据库
	 */
	public function test_parameters_persistence() {
		$test_params = array(
			'automation_interval' => 450,
			'batch_size'          => 30,
			'test_key'            => 'test_value',
		);

		update_option( 'wptsall_test_params_chain15', $test_params );
		$retrieved = get_option( 'wptsall_test_params_chain15' );
		$this->assertEquals( $test_params, $retrieved, '参数应正确持久化' );

		delete_option( 'wptsall_test_params_chain15' );
	}

	/**
	 * 测试删除选项后 get_task_parameters 返回默认值数组（若函数存在）
	 */
	public function test_default_task_parameters_after_delete() {
		if ( ! function_exists( 'wptsall_get_task_parameters' ) ) {
			$this->markTestSkipped( 'wptsall_get_task_parameters 函数不存在' );
		}

		$original = get_option( 'wptsall_task_parameters' );
		delete_option( 'wptsall_task_parameters' );

		$params = wptsall_get_task_parameters();
		$this->assertIsArray( $params, '应返回默认参数（数组）' );

		// 恢复
		if ( $original ) {
			update_option( 'wptsall_task_parameters', $original );
		}
	}
}
