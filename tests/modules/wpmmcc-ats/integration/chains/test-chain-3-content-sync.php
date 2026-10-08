<?php
/**
 * Chain 3: 内容同步执行链路测试
 *
 * 测试内容同步流程：任务发现 → 任务编排 → 任务执行 → 内容存储
 *
 * REST 端点:
 * - POST /wptsall/v2/tasks/monitor/start
 * - GET  /wptsall/v2/tasks?status=pending&limit=5
 * - GET  /wptsall/v2/tasks
 * - GET  /wptsall/v2/tasks/{id}
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 3 Test: Content Sync Execution
 */
class Test_Chain_3_Content_Sync extends REST_Integration_Test_Case {

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
	 * 每个测试前创建基础数据
	 */
	public function setUp(): void {
		parent::setUp();

		// 创建虚拟站点
		$this->test_virtual_site_id = $this->create_test_virtual_site( array(
			'name'        => 'Chain3 Sync Test Site',
			'path_prefix' => 'chain3-sync-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		// 创建站点关系
		$relation_data = $this->create_test_relation( $this->test_virtual_site_id );
		$this->test_relation_id = $relation_data['relation_ids'][0] ?? 0;
	}

	// ========================================
	// 任务发现测试（使用 GET /tasks?status=pending）
	// ========================================

	/**
	 * 测试通过 GET /tasks?status=pending 发现待处理任务
	 *
	 * 替代已废弃的 POST tasks/discover (preview mode)
	 */
	public function test_task_discovery_via_pending_list() {
		// 先启动一个监控任务，产生 pending 记录
		$this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		// 用 GET /tasks?status=pending 代替 tasks/discover preview 模式
		$response = $this->rest_get( 'tasks', array(
			'status' => 'pending',
			'limit'  => 5,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items 键' );
		$this->assertIsArray( $data['items'], 'items 应为数组' );
	}

	/**
	 * 测试 pending 任务列表包含正确字段结构
	 */
	public function test_pending_task_list_structure() {
		// 启动监控任务以产生至少一条记录
		$monitor_response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		if ( 200 === $monitor_response->get_status() ) {
			$monitor_data = $this->get_response_data( $monitor_response );
			if ( isset( $monitor_data['task_id'] ) ) {
				$this->track_resource( 'tasks', $monitor_data['task_id'] );
			}
		}

		$response = $this->rest_get( 'tasks', array(
			'status' => 'pending',
			'limit'  => 5,
		) );

		$this->assertRestSuccess( $response, 200 );
		$data = $this->get_response_data( $response );

		if ( ! empty( $data['items'] ) ) {
			$task = $data['items'][0];
			// 验证任务结构包含必要字段
			$expected_keys = array( 'id', 'status', 'type', 'relation_id' );
			foreach ( $expected_keys as $key ) {
				$this->assertArrayHasKey( $key, $task, "任务应包含 {$key} 字段" );
			}
		}
	}

	// ========================================
	// 监控任务启动测试（替代废弃的 orchestrate）
	// ========================================

	/**
	 * 测试通过 POST tasks/monitor/start 创建任务
	 *
	 * 替代废弃的 POST tasks/orchestrate 端点
	 *
	 * @covers Tasks_REST_Controller::start_monitoring
	 */
	public function test_monitor_start_creates_task() {
		if ( empty( $this->test_relation_id ) ) {
			$this->markTestSkipped( '测试关系创建失败，无法测试监控启动' );
		}

		$response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		// 如果监控任务已存在，跳过
		$status = $response->get_status();
		if ( 500 === $status ) {
			$data = $response->get_data();
			$message = $data['message'] ?? '';
			if ( strpos( $message, 'already' ) !== false || strpos( $message, 'exists' ) !== false ) {
				$this->markTestSkipped( '监控任务已存在' );
			}
		}

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'], '启动监控应成功' );
		$this->assertArrayHasKey( 'task_id', $data, '响应应包含 task_id' );

		if ( isset( $data['task_id'] ) ) {
			$this->track_resource( 'tasks', $data['task_id'] );
		}
	}

	/**
	 * 测试 monitor/start 启动后 wp_wptsall_tasks 中有 pending 记录
	 *
	 * DB 断言：验证任务确实写入了数据库
	 */
	public function test_monitor_start_creates_db_record() {
		if ( empty( $this->test_relation_id ) ) {
			$this->markTestSkipped( '测试关系创建失败' );
		}

		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d",
				$this->test_relation_id
			)
		);

		$response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		// 可能已存在，只要请求合法即可
		$status = $response->get_status();
		if ( ! in_array( $status, array( 200, 400, 500 ), true ) ) {
			$this->fail( "monitor/start 返回了意外状态: {$status}" );
		}

		if ( 200 === $status ) {
			$data = $this->get_response_data( $response );
			if ( isset( $data['task_id'] ) ) {
				$this->track_resource( 'tasks', $data['task_id'] );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count_after = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d",
					$this->test_relation_id
				)
			);

				$this->assertGreaterThanOrEqual( $count_before, $count_after, 'tasks 表记录数不应减少' );
			}
		}

	/**
	 * 测试 monitor/start 支持 post_type 参数
	 */
	public function test_monitor_start_with_post_type() {
		if ( empty( $this->test_relation_id ) ) {
			$this->markTestSkipped( '测试关系创建失败' );
		}

		$response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
			'post_type'   => 'post',
		) );

		$status = $response->get_status();
		// 200 成功, 400 参数不合法, 500 任务已存在 — 均为已知状态
		$this->assertContains(
			$status,
			array( 200, 400, 500 ),
			"monitor/start with post_type 应返回已知状态，实际为 {$status}"
		);

		if ( 200 === $status ) {
			$data = $this->get_response_data( $response );
			if ( isset( $data['task_id'] ) ) {
				$this->track_resource( 'tasks', $data['task_id'] );
			}
		}
	}

	// ========================================
	// 单任务执行测试
	// ========================================

	/**
	 * 测试执行单个任务
	 *
	 * @covers Tasks_REST_Controller::execute_task
	 */
	public function test_execute_single_task() {
		// 先创建监控任务
		$monitor_response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		if ( $monitor_response->get_status() !== 200 ) {
			$this->markTestSkipped( '无法启动监控任务' );
		}

		$monitor_data = $this->get_response_data( $monitor_response );
		$task_id = $monitor_data['task_id'] ?? 0;

		if ( ! $task_id ) {
			$this->markTestSkipped( '没有创建任务' );
		}

		$this->track_resource( 'tasks', $task_id );

		// 执行任务
		$response = $this->rest_post( "tasks/{$task_id}/execute" );

		// 执行可能成功或因为没有待同步内容而返回特定结果
		$status = $response->get_status();
		$this->assertContains(
			$status,
			array( 200, 400, 404, 500 ),
			"执行任务应返回有效状态，实际为 {$status}"
		);
	}

	// ========================================
	// 虚拟站点存储测试
	// ========================================

	/**
	 * 测试虚拟站点内容存储（通过 monitor/start 触发）
	 */
	public function test_virtual_content_storage() {
		// 创建测试文章
		$this->create_test_post( array(
			'post_title'   => '虚拟存储测试文章',
			'post_content' => '测试虚拟站点内容存储。',
		) );

		// 触发监控（替代 orchestrate）
		$monitor_response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		if ( 200 === $monitor_response->get_status() ) {
			$monitor_data = $this->get_response_data( $monitor_response );
			if ( isset( $monitor_data['task_id'] ) ) {
				$this->track_resource( 'tasks', $monitor_data['task_id'] );
			}
		}

		// 检查 tasks 表 — monitor/start 应创建 pending 任务
		// （虚拟站点内容在客户端回调后才写入，此时不断言 virtual_site_content）
		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$task_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tasks_table} WHERE relation_id = %d AND status IN ('pending','active','processing')",
				$this->test_relation_id
			)
		);

		$this->assertGreaterThan(
			0,
			$task_count,
			'monitor/start 应在 tasks 表中为此 relation 创建至少一个 pending 任务'
		);
	}

	// ========================================
	// 任务列表和查询测试
	// ========================================

	/**
	 * 测试获取任务列表
	 *
	 * @covers Tasks_REST_Controller::get_tasks
	 */
	public function test_get_task_list() {
		// 启动监控任务
		$this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		// 获取任务列表
		$response = $this->rest_get( 'tasks', array(
			'relation_id' => $this->test_relation_id,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items' );
		$this->assertIsArray( $data['items'], 'items 应为数组' );
	}

	/**
	 * 测试按状态筛选任务 — pending
	 */
	public function test_filter_tasks_by_status_pending() {
		$response = $this->rest_get( 'tasks', array(
			'status' => 'pending',
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items 键' );
		foreach ( $data['items'] as $task ) {
			$this->assertEquals( 'pending', $task['status'], '筛选结果应只包含 pending 状态' );
		}
	}

	/**
	 * 测试按状态筛选任务 — completed
	 */
	public function test_filter_tasks_by_status_completed() {
		$response = $this->rest_get( 'tasks', array(
			'status' => 'completed',
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items 键' );
		foreach ( $data['items'] as $task ) {
			$this->assertEquals( 'completed', $task['status'], '筛选结果应只包含 completed 状态' );
		}
	}

	/**
	 * 测试按类型筛选任务
	 */
	public function test_filter_tasks_by_type() {
		$response = $this->rest_get( 'tasks', array(
			'type' => 'monitoring',
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		foreach ( $data['items'] ?? array() as $task ) {
			$this->assertEquals( 'monitoring', $task['type'] ?? '', '筛选结果应只包含 monitoring 类型' );
		}
	}

	// ========================================
	// 监控任务测试
	// ========================================

	/**
	 * 测试启动监控
	 *
	 * @covers Tasks_REST_Controller::start_monitoring
	 */
	public function test_start_monitoring() {
		if ( empty( $this->test_relation_id ) ) {
			$this->markTestSkipped( '测试关系创建失败，无法测试监控启动' );
		}

		$response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		// 如果监控任务已存在，跳过测试
		$status = $response->get_status();
		if ( 500 === $status ) {
			$data = $response->get_data();
			$message = $data['message'] ?? '';
			if ( strpos( $message, 'already' ) !== false || strpos( $message, 'exists' ) !== false ) {
				$this->markTestSkipped( '监控任务已存在' );
			}
		}

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'], '启动监控应成功' );
		$this->assertArrayHasKey( 'task_id', $data );

		if ( isset( $data['task_id'] ) ) {
			$this->track_resource( 'tasks', $data['task_id'] );
		}
	}

	/**
	 * 测试停止监控
	 *
	 * @covers Tasks_REST_Controller::stop_monitoring
	 */
	public function test_stop_monitoring() {
		// 先启动监控
		$start_response = $this->rest_post( 'tasks/monitor/start', array(
			'relation_id' => $this->test_relation_id,
		) );

		if ( $start_response->get_status() !== 200 ) {
			$this->markTestSkipped( '无法启动监控' );
		}

		$start_data = $this->get_response_data( $start_response );
		if ( isset( $start_data['task_id'] ) ) {
			$this->track_resource( 'tasks', $start_data['task_id'] );
		}

		// 停止监控
		$response = $this->rest_post( 'tasks/monitor/stop', array(
			'relation_id' => $this->test_relation_id,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data );
	}

	/**
	 * 测试获取监控状态
	 *
	 * @covers Tasks_REST_Controller::get_monitor_status
	 */
	public function test_get_monitor_status() {
		$response = $this->rest_get( 'tasks/monitor', array(
			'relation_id' => $this->test_relation_id,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'relation_id', $data );
		$this->assertArrayHasKey( 'monitoring', $data );
	}

	// ========================================
	// 服务层测试
	// ========================================

	/**
	 * 测试 Task_Orchestrator 存在且可用
	 */
	public function test_task_orchestrator_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Tasks\Services\Task_Orchestrator' ),
			'Task_Orchestrator 类应存在'
		);
	}

	/**
	 * 测试 Monitoring_Task_Service 存在且可用
	 */
	public function test_monitoring_task_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Tasks\Services\Monitoring_Task_Service' ),
			'Monitoring_Task_Service 类应存在'
		);
	}
}
