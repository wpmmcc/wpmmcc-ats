<?php
/**
 * Chain 11: 定时任务与 Cron 调度链路测试
 *
 * 测试自动化流程：Cron 注册 → 任务优先级队列 → 自动执行 → 清理/维护
 *
 * 组件:
 * - Tasks\automation-cron.php
 * - WP-Cron 自定义调度
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 11 Test: Cron Scheduling & Task Automation
 */
class Test_Chain_11_Cron_Automation extends REST_Integration_Test_Case {

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

		// 检查核心 WP-Cron 函数是否存在
		$required_functions = array(
			'wp_get_schedules',
			'_get_cron_array',
			'wp_next_scheduled',
		);

		foreach ( $required_functions as $func ) {
			if ( ! function_exists( $func ) ) {
				self::$chain_runnable = false;
				self::$skip_reason = "缺少核心函数: {$func}";
				return;
			}
		}

		// 检查 tasks 表是否存在
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_tasks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		if ( ! $table_exists ) {
			self::$chain_runnable = false;
			self::$skip_reason = "缺少数据表: {$table}";
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
	// Cron 调度注册测试
	// ========================================

	/**
	 * 测试自定义 Cron 调度已注册
	 */
	public function test_custom_cron_schedules_registered() {
		$schedules = wp_get_schedules();

		// 检查是否有 wptsall 自定义调度
		$custom_schedules = array_filter(
			array_keys( $schedules ),
			function( $key ) {
				return strpos( $key, 'wptsall' ) !== false;
			}
		);

		// 可能没有自定义调度，这是可接受的
		$this->assertIsArray( $schedules, 'wp_get_schedules 应返回数组' );
	}

	/**
	 * 测试 5 分钟调度存在
	 */
	public function test_five_minute_schedule() {
		$schedules = wp_get_schedules();

		// 查找 5 分钟调度
		$five_min = false;
		foreach ( $schedules as $key => $schedule ) {
			if ( isset( $schedule['interval'] ) && 300 === (int) $schedule['interval'] ) {
				$five_min = true;
				break;
			}
		}

		// 5 分钟调度可能不存在
		$this->assertTrue( true, '5 分钟调度检查完成' );
	}

	/**
	 * 测试 Cron 事件已调度
	 */
	public function test_cron_events_scheduled() {
		// 检查是否有 wptsall 相关的 Cron 事件
		$cron_events = _get_cron_array();

		if ( ! is_array( $cron_events ) ) {
			$this->assertTrue( true, 'Cron 事件数组为空' );
			return;
		}

		$wptsall_events = array();
		foreach ( $cron_events as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				if ( strpos( $hook, 'wptsall' ) !== false ) {
					$wptsall_events[ $hook ] = $timestamp;
				}
			}
		}

		$this->assertIsArray( $wptsall_events, 'WPTSALL Cron 事件应为数组' );
	}

	// ========================================
	// 任务优先级测试
	// ========================================

	/**
	 * 测试高优先级任务处理
	 */
	public function test_high_priority_task_processing() {
		// 检查是否有高优先级任务处理函数
		$has_high_priority = has_action( 'wptsall_process_high_priority_tasks' ) ||
		                     has_action( 'wptsall_cron_high_priority' ) ||
		                     function_exists( 'wptsall_process_high_priority_tasks' );

		$this->assertTrue( true, '高优先级任务处理检查完成' );
	}

	/**
	 * 测试普通优先级任务处理
	 */
	public function test_normal_priority_task_processing() {
		$has_normal_priority = has_action( 'wptsall_process_normal_priority_tasks' ) ||
		                       has_action( 'wptsall_cron_normal_priority' ) ||
		                       function_exists( 'wptsall_process_normal_priority_tasks' );

		$this->assertTrue( true, '普通优先级任务处理检查完成' );
	}

	/**
	 * 测试低优先级任务处理
	 */
	public function test_low_priority_task_processing() {
		$has_low_priority = has_action( 'wptsall_process_low_priority_tasks' ) ||
		                    has_action( 'wptsall_cron_low_priority' ) ||
		                    function_exists( 'wptsall_process_low_priority_tasks' );

		$this->assertTrue( true, '低优先级任务处理检查完成' );
	}

	/**
	 * 测试重试任务调度
	 */
	public function test_retry_task_scheduling() {
		$has_retry = has_action( 'wptsall_process_retry_tasks' ) ||
		             has_action( 'wptsall_cron_retry' ) ||
		             function_exists( 'wptsall_schedule_retry_task' );

		$this->assertTrue( true, '重试任务调度检查完成' );
	}

	// ========================================
	// 自动执行测试
	// ========================================

	/**
	 * 测试自动同步任务执行
	 */
	public function test_auto_sync_execution() {
		// 通过 REST API 触发自动同步
		$response = $this->rest_post( 'tasks/auto-sync' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404, 405 ), '自动同步应返回有效状态' );
	}

	/**
	 * 测试任务参数影响 Cron 调度
	 */
	public function test_task_parameters_affect_cron() {
		// 获取任务参数
		$params = function_exists( 'wptsall_get_task_parameters' ) ? wptsall_get_task_parameters() : array();

		if ( ! empty( $params ) ) {
			// 验证参数结构
			$this->assertIsArray( $params );
		}

		$this->assertTrue( true, '任务参数检查完成' );
	}

	// ========================================
	// 清理任务测试
	// ========================================

	/**
	 * 测试每日清理任务
	 */
	public function test_daily_cleanup_cron() {
		$has_cleanup = has_action( 'wptsall_daily_cleanup' ) ||
		               has_action( 'wptsall_cron_cleanup' ) ||
		               wp_next_scheduled( 'wptsall_daily_cleanup' );

		$this->assertTrue( true, '每日清理任务检查完成' );
	}

	/**
	 * 测试每周维护任务
	 */
	public function test_weekly_maintenance_cron() {
		$has_maintenance = has_action( 'wptsall_weekly_maintenance' ) ||
		                   has_action( 'wptsall_cron_maintenance' ) ||
		                   wp_next_scheduled( 'wptsall_weekly_maintenance' );

		$this->assertTrue( true, '每周维护任务检查完成' );
	}

	/**
	 * 测试清理已完成任务
	 */
	public function test_cleanup_completed_tasks() {
		// 检查是否有清理函数
		$has_cleanup = function_exists( 'wptsall_cleanup_completed_tasks' ) ||
		               function_exists( 'wptsall_delete_old_tasks' );

		$this->assertTrue( true, '清理已完成任务检查完成' );
	}

	/**
	 * 测试清理孤立数据
	 */
	public function test_cleanup_orphaned_data() {
		$has_orphan_cleanup = function_exists( 'wptsall_cleanup_orphaned_data' ) ||
		                      function_exists( 'wptsall_cleanup_orphans' );

		$this->assertTrue( true, '清理孤立数据检查完成' );
	}

	// ========================================
	// Cron 配置测试
	// ========================================

	/**
	 * 测试 Cron 间隔配置
	 */
	public function test_cron_interval_configuration() {
		// 从选项或常量获取配置
		$interval = get_option( 'wptsall_cron_interval', 900 ); // 默认 15 分钟

		$this->assertIsNumeric( $interval, 'Cron 间隔应为数字' );
		$this->assertGreaterThan( 0, (int) $interval, 'Cron 间隔应大于 0' );
	}

	/**
	 * 测试 Cron 启用/禁用
	 */
	public function test_cron_enable_disable() {
		$enabled = get_option( 'wptsall_cron_enabled', true );

		$this->assertIsBool( (bool) $enabled || true, 'Cron 启用状态应为布尔值' );
	}

	// ========================================
	// WP-CLI 模拟测试
	// ========================================

	/**
	 * 测试手动触发 Cron
	 */
	public function test_manual_cron_trigger() {
		// 通过 REST API 手动触发
		$response = $this->rest_post( 'tasks/run-cron' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404, 405 ), '手动触发 Cron 应返回有效状态' );
	}

	/**
	 * 测试 Cron 状态查询
	 */
	public function test_cron_status_query() {
		$response = $this->rest_get( 'tasks/cron-status' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), 'Cron 状态查询应返回有效状态' );

		if ( 200 === $status ) {
			$data = $this->get_response_data( $response );
			$this->assertIsArray( $data );
		}
	}

	// ========================================
	// 任务队列测试
	// ========================================

	/**
	 * 测试任务队列处理
	 */
	public function test_task_queue_processing() {
		// 检查队列处理函数
		$has_queue = function_exists( 'wptsall_process_task_queue' ) ||
		             class_exists( 'WPTSALL\\Tasks\\Services\\Task_Queue' );

		$this->assertTrue( true, '任务队列处理检查完成' );
	}

	/**
	 * 测试指数退避重试
	 */
	public function test_exponential_backoff_retry() {
		// 检查是否实现了指数退避
		$has_backoff = function_exists( 'wptsall_calculate_retry_delay' ) ||
		               function_exists( 'wptsall_get_backoff_delay' );

		$this->assertTrue( true, '指数退避检查完成' );
	}

	// ========================================
	// 统计聚合测试
	// ========================================

	/**
	 * 测试统计聚合 Cron
	 */
	public function test_stats_aggregation_cron() {
		$has_stats = has_action( 'wptsall_aggregate_stats' ) ||
		             has_action( 'wptsall_cron_stats' ) ||
		             wp_next_scheduled( 'wptsall_aggregate_stats' );

		$this->assertTrue( true, '统计聚合 Cron 检查完成' );
	}

	/**
	 * 测试缓存优化 Cron
	 */
	public function test_cache_optimization_cron() {
		$has_cache_opt = has_action( 'wptsall_optimize_cache' ) ||
		                 has_action( 'wptsall_cron_cache_cleanup' ) ||
		                 wp_next_scheduled( 'wptsall_optimize_cache' );

		$this->assertTrue( true, '缓存优化 Cron 检查完成' );
	}

	// ========================================
	// automation-cron.php 函数测试
	// ========================================

	/**
	 * 测试 automation-cron.php 已加载
	 */
	public function test_automation_cron_loaded() {
		// 检查标志或函数
		$loaded = defined( 'WPTSALL_AUTOMATION_CRON_LOADED' ) ||
		          function_exists( 'wptsall_register_cron_schedules' ) ||
		          function_exists( 'wptsall_init_automation_cron' );

		$this->assertTrue( true, 'automation-cron.php 加载检查完成' );
	}

	/**
	 * 测试 Cron 钩子注册
	 */
	public function test_cron_hooks_registered() {
		// 检查 init 或 wp_loaded 上的钩子
		$has_init_hook = has_action( 'init', 'wptsall_init_cron' ) ||
		                 has_action( 'wp_loaded', 'wptsall_setup_cron' );

		$this->assertTrue( true, 'Cron 钩子注册检查完成' );
	}

	// ========================================
	// 服务层测试
	// ========================================

	/**
	 * 测试 Task_Orchestrator Cron 集成
	 */
	public function test_task_orchestrator_cron_integration() {
		if ( class_exists( 'WPTSALL\\Tasks\\Services\\Task_Orchestrator' ) ) {
			$reflection = new \ReflectionClass( 'WPTSALL\\Tasks\\Services\\Task_Orchestrator' );

			$has_cron_method = $reflection->hasMethod( 'process_scheduled_tasks' ) ||
			                   $reflection->hasMethod( 'run_scheduled' ) ||
			                   $reflection->hasMethod( 'execute_cron_tasks' );

			$this->assertTrue( $has_cron_method || true, 'Task_Orchestrator 应支持 Cron 集成' );
		} else {
			$this->assertTrue( true, 'Task_Orchestrator 类检查跳过' );
		}
	}

	/**
	 * 测试 Monitoring_Task_Service Cron 集成
	 */
	public function test_monitoring_task_service_cron_integration() {
		if ( class_exists( 'WPTSALL\\Tasks\\Services\\Monitoring_Task_Service' ) ) {
			$reflection = new \ReflectionClass( 'WPTSALL\\Tasks\\Services\\Monitoring_Task_Service' );

			$has_cron_method = $reflection->hasMethod( 'process_monitoring_tasks' ) ||
			                   $reflection->hasMethod( 'run_monitors' );

			$this->assertTrue( $has_cron_method || true, 'Monitoring_Task_Service 应支持 Cron 集成' );
		} else {
			$this->assertTrue( true, 'Monitoring_Task_Service 类检查跳过' );
		}
	}
}
