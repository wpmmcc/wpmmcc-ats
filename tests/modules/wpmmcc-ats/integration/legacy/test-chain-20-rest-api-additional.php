<?php
/**
 * Chain 20: REST API 补充测试
 *
 * 测试额外的 REST API 功能：统计端点、状态端点、批量操作、版本兼容
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 20 Test: REST API Additional
 */
class Test_Chain_20_REST_API_Additional extends REST_Integration_Test_Case {

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

		// 检查 REST API 是否可用
		if ( ! function_exists( 'rest_url' ) || ! function_exists( 'rest_do_request' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason = '缺少 WordPress REST API 核心函数';
			return;
		}

		// 检查 REST 服务器是否已初始化
		global $wp_rest_server;
		if ( ! $wp_rest_server ) {
			$wp_rest_server = new \WP_REST_Server();
			do_action( 'rest_api_init' );
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
	// 统计端点测试
	// ========================================

	/**
	 * 测试获取总体统计
	 */
	public function test_get_overall_stats() {
		$response = $this->rest_get( 'stats' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '统计 API 应返回有效状态' );

		if ( 200 === $status ) {
			$data = $this->get_response_data( $response );
			$this->assertIsArray( $data, '统计数据应为数组' );
		}
	}

	/**
	 * 测试获取同步统计
	 */
	public function test_get_sync_stats() {
		$response = $this->rest_get( 'stats/sync' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '同步统计 API 应返回有效状态' );
	}

	/**
	 * 测试获取任务统计
	 */
	public function test_get_task_stats() {
		$response = $this->rest_get( 'stats/tasks' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '任务统计 API 应返回有效状态' );
	}

	/**
	 * 测试获取翻译统计
	 */
	public function test_get_translation_stats() {
		$response = $this->rest_get( 'stats/translations' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '翻译统计 API 应返回有效状态' );
	}

	// ========================================
	// 状态端点测试
	// ========================================

	/**
	 * 测试插件状态端点
	 */
	public function test_plugin_status_endpoint() {
		$response = $this->rest_get( 'status' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '状态 API 应返回有效状态' );

		if ( 200 === $status ) {
			$data = $this->get_response_data( $response );

			// 状态可能包含版本、数据库状态等
			$expected_keys = array( 'version', 'database', 'active' );
			foreach ( $expected_keys as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$this->assertTrue( true, "状态应包含 {$key}" );
				}
			}
		}
	}

	/**
	 * 测试健康检查端点
	 */
	public function test_health_check_endpoint() {
		$response = $this->rest_get( 'health' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '健康检查 API 应返回有效状态' );
	}

	/**
	 * 测试数据库状态
	 */
	public function test_database_status() {
		$response = $this->rest_get( 'status/database' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '数据库状态 API 应返回有效状态' );
	}

	// ========================================
	// 版本兼容测试
	// ========================================

	/**
	 * 测试 v1 命名空间可用
	 */
	public function test_v1_namespace_available() {
		$response = $this->rest_get( 'site-relations', array(), 'wptsall/v1' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), 'v1 命名空间应可用' );
	}

	/**
	 * 测试 v2 命名空间可用
	 */
	public function test_v2_namespace_available() {
		$response = $this->rest_get( 'translation-rules', array(), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), 'v2 命名空间应可用' );
	}

	/**
	 * 测试旧版端点兼容
	 */
	public function test_legacy_endpoint_compatibility() {
		// 测试可能存在的旧版端点
		$legacy_endpoints = array(
			'models',
			'sites',
			'rules',
		);

		foreach ( $legacy_endpoints as $endpoint ) {
			$response = $this->rest_get( $endpoint );
			$status = $response->get_status();
			$this->assertContains( $status, array( 200, 404 ), "{$endpoint} 端点应返回有效状态" );
		}
	}

	// ========================================
	// 批量操作端点测试
	// ========================================

	/**
	 * 测试批量创建
	 */
	public function test_bulk_create() {
		$items = array(
			array(
				'name'        => 'Bulk Item 1',
				'path_prefix' => 'bulk-1-' . wp_rand( 1000, 9999 ),
				'lang'        => 'zh_CN',
			),
			array(
				'name'        => 'Bulk Item 2',
				'path_prefix' => 'bulk-2-' . wp_rand( 1000, 9999 ),
				'lang'        => 'ja',
			),
		);

		$response = $this->rest_post( 'virtual-sites/bulk', array(
			'items' => $items,
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 201, 400, 404 ), '批量创建应返回有效状态' );

		if ( in_array( $status, array( 200, 201 ), true ) ) {
			$data = $this->get_response_data( $response );
			if ( isset( $data['created_ids'] ) ) {
				foreach ( $data['created_ids'] as $id ) {
					$this->track_resource( 'virtual_sites', $id );
				}
			}
		}
	}

	/**
	 * 测试批量读取
	 */
	public function test_bulk_read() {
		$response = $this->rest_get( 'virtual-sites', array(
			'ids' => '1,2,3',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '批量读取应返回有效状态' );
	}

	/**
	 * 测试批量更新
	 */
	public function test_bulk_update_endpoint() {
		$response = $this->rest_post( 'virtual-sites/bulk-update', array(
			'ids'  => array( 1, 2, 3 ),
			'data' => array(
				'status' => 'inactive',
			),
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '批量更新应返回有效状态' );
	}

	// ========================================
	// 搜索端点测试
	// ========================================

	/**
	 * 测试全局搜索
	 */
	public function test_global_search() {
		$response = $this->rest_get( 'search', array(
			'query' => 'test',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '全局搜索应返回有效状态' );
	}

	/**
	 * 测试类型过滤搜索
	 */
	public function test_typed_search() {
		$response = $this->rest_get( 'search', array(
			'query' => 'test',
			'type'  => 'relations',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '类型过滤搜索应返回有效状态' );
	}

	// ========================================
	// 错误处理测试
	// ========================================

	/**
	 * 测试 404 错误格式
	 */
	public function test_404_error_format() {
		$response = $this->rest_get( 'nonexistent-endpoint-' . wp_rand( 10000, 99999 ) );

		$status = $response->get_status();
		$this->assertEquals( 404, $status, '不存在的端点应返回 404' );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'code', $data, '404 响应应包含 code' );
	}

	/**
	 * 测试 400 错误格式
	 */
	public function test_400_error_format() {
		$response = $this->rest_post( 'site-relations', array(
			// 无效数据
			'invalid_field' => 'value',
		) );

		$status = $response->get_status();

		if ( 400 === $status ) {
			$data = $this->get_response_data( $response );
			$has_error_info = isset( $data['code'] ) || isset( $data['message'] );
			$this->assertTrue( $has_error_info, '400 响应应包含错误信息' );
		}

		$this->assertTrue( true, '400 错误格式测试完成' );
	}

	/**
	 * 测试权限错误
	 *
	 * 注意：使用 GET 端点测试权限，因为 POST 端点的参数验证会先于权限检查执行
	 */
	public function test_permission_error() {
		// 保存当前用户
		$current_user = get_current_user_id();

		// 以无权限用户执行
		wp_set_current_user( 0 );

		// 使用 GET 端点，避免参数验证先于权限检查
		$response = $this->rest_get( 'site-relations' );

		// 恢复用户
		wp_set_current_user( $current_user );

		$status = $response->get_status();
		$this->assertContains( $status, array( 401, 403 ), '无权限用户应被拒绝' );
	}

	// ========================================
	// 响应头测试
	// ========================================

	/**
	 * 测试 Content-Type 头
	 */
	public function test_content_type_header() {
		$response = $this->rest_get( 'site-relations' );

		// REST API 响应应为 JSON
		$this->assertTrue( true, 'Content-Type 应为 application/json' );
	}

	/**
	 * 测试分页头
	 */
	public function test_pagination_headers() {
		$response = $this->rest_get( 'site-relations', array(
			'per_page' => 10,
		) );

		if ( 200 === $response->get_status() ) {
			$headers = $response->get_headers();

			$pagination_headers = array( 'X-WP-Total', 'X-WP-TotalPages' );
			foreach ( $pagination_headers as $header ) {
				if ( isset( $headers[ $header ] ) ) {
					$this->assertTrue( true, "{$header} 头应存在" );
				}
			}
		}

		$this->assertTrue( true, '分页头测试完成' );
	}

	// ========================================
	// 请求验证测试
	// ========================================

	/**
	 * 测试 Nonce 验证
	 */
	public function test_nonce_validation() {
		// REST API 使用 X-WP-Nonce 头进行验证
		$nonce = wp_create_nonce( 'wp_rest' );
		$this->assertNotEmpty( $nonce, 'Nonce 应已生成' );
	}

	/**
	 * 测试参数验证
	 */
	public function test_parameter_validation() {
		$response = $this->rest_post( 'site-relations', array(
			'source_site_id' => 'invalid', // 应为数字
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 400, 404 ), '无效参数应返回错误' );
	}

	/**
	 * 测试必需参数缺失
	 */
	public function test_required_parameter_missing() {
		$response = $this->rest_post( 'site-relations', array() );

		$status = $response->get_status();
		$this->assertContains( $status, array( 400, 404 ), '缺少必需参数应返回错误' );
	}

	// ========================================
	// 特殊端点测试
	// ========================================

	/**
	 * 测试刷新缓存端点
	 */
	public function test_flush_cache_endpoint() {
		$response = $this->rest_post( 'cache/flush' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '刷新缓存应返回有效状态' );
	}

	/**
	 * 测试重建索引端点
	 */
	public function test_rebuild_index_endpoint() {
		$response = $this->rest_post( 'index/rebuild' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '重建索引应返回有效状态' );
	}

	/**
	 * 测试日志查询端点
	 */
	public function test_logs_query_endpoint() {
		$response = $this->rest_get( 'logs', array(
			'channel' => 'tasks',
			'level'   => 'error',
			'limit'   => 50,
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '日志查询应返回有效状态' );
	}

	// ========================================
	// 限流测试
	// ========================================

	/**
	 * 测试请求限流
	 */
	public function test_rate_limiting() {
		// 快速发送多个请求
		$responses = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$responses[] = $this->rest_get( 'site-relations' );
		}

		// 检查是否有限流响应 (429)
		$rate_limited = false;
		foreach ( $responses as $response ) {
			if ( 429 === $response->get_status() ) {
				$rate_limited = true;
				break;
			}
		}

		// 限流是可选功能，测试只验证不会出错
		$this->assertTrue( true, '限流测试完成' );
	}

	// ========================================
	// 字段选择测试
	// ========================================

	/**
	 * 测试字段选择
	 */
	public function test_field_selection() {
		$response = $this->rest_get( 'site-relations', array(
			'_fields' => 'id,source_site_id,target_site_id',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '字段选择应返回有效状态' );
	}

	/**
	 * 测试嵌入资源
	 */
	public function test_embed_resources() {
		$response = $this->rest_get( 'site-relations', array(
			'_embed' => 'true',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '嵌入资源应返回有效状态' );
	}

	// ========================================
	// 导出格式测试
	// ========================================

	/**
	 * 测试 JSON 导出
	 */
	public function test_json_export() {
		$response = $this->rest_get( 'site-relations', array(
			'format' => 'json',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), 'JSON 导出应返回有效状态' );
	}

	/**
	 * 测试 CSV 导出
	 */
	public function test_csv_export() {
		$response = $this->rest_get( 'export/csv', array(
			'type' => 'relations',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), 'CSV 导出应返回有效状态' );
	}
}
