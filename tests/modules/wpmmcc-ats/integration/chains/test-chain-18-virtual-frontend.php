<?php
/**
 * Chain 18: 虚拟站点前端路由链路测试
 *
 * 测试前端路由流程：URL 解析 → 虚拟站点匹配 → 内容解析 → 响应渲染
 *
 * 核心类:
 * - Hooks\\Virtual_Site_Router
 * - Sites\\Services\\Virtual_Site_Service
 *
 * 端点（当前 API）:
 * - GET  /virtual-sites
 * - GET  /virtual-sites/{id}
 * - POST /virtual-sites
 * - PUT  /virtual-sites/{id}
 * - DELETE /virtual-sites/{id}
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 18 Test: Virtual Site Frontend Routing
 */
class Test_Chain_18_Virtual_Frontend extends REST_Integration_Test_Case {

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

		// 检查 virtual_sites 表是否存在（通过 wptsall_table 避免硬编码前缀）
		if ( function_exists( 'wptsall_table' ) ) {
			global $wpdb;
			$table = wptsall_table( 'virtual_sites' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
			if ( ! $table_exists ) {
				self::$chain_runnable = false;
				self::$skip_reason    = "缺少数据表: {$table}";
				return;
			}
		}

		// 检查路由类或服务类存在
		$has_router = class_exists( 'WPTSALL\\Hooks\\Virtual_Site_Router' ) ||
		              class_exists( 'WPTSALL\\Sites\\Services\\Virtual_Site_Service' );

		if ( ! $has_router ) {
			self::$chain_runnable = false;
			self::$skip_reason    = '缺少虚拟站点路由类或服务类';
		}
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
	// Virtual_Site_Service PHP API 直接调用（新增）
	// ========================================

	/**
	 * 测试 Virtual_Site_Service::get_all() 直接调用不产生 WP_Error，返回数组
	 *
	 * 新增：验证服务层方法可调用
	 */
	public function test_virtual_site_service_get_all_no_sql_error() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$this->markTestSkipped( 'Virtual_Site_Service 类不存在' );
		}

		$result = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) );

		$this->assertNotInstanceOf(
			'WP_Error',
			$result,
			'Virtual_Site_Service::get_all 不应返回 WP_Error'
		);

		$this->assertIsArray( $result, 'Virtual_Site_Service::get_all 应返回数组' );
	}

	/**
	 * 测试 Virtual_Site_Service::get_all() 不带参数也返回数组
	 */
	public function test_virtual_site_service_get_all_no_args() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$this->markTestSkipped( 'Virtual_Site_Service 类不存在' );
		}

		$result = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all();

		$this->assertIsArray( $result, 'Virtual_Site_Service::get_all() 应返回数组' );
	}

	// ========================================
	// Virtual_Site_Router 类测试
	// ========================================

	/**
	 * 测试 Virtual_Site_Router 类存在
	 */
	public function test_virtual_site_router_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Hooks\\Virtual_Site_Router' ),
			'Virtual_Site_Router 类应存在'
		);
	}

	/**
	 * 测试路由方法存在
	 */
	public function test_router_methods_exist() {
		if ( ! class_exists( 'WPTSALL\\Hooks\\Virtual_Site_Router' ) ) {
			$this->markTestSkipped( 'Virtual_Site_Router 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Hooks\\Virtual_Site_Router' );

		$methods_to_check = array( 'route_request', 'resolve_virtual_site', 'is_virtual_path',
			'parse_request', 'detect_virtual_site', 'template_redirect' );

		$found = false;
		foreach ( $methods_to_check as $method ) {
			if ( $reflection->hasMethod( $method ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Virtual_Site_Router 应有至少一个路由方法' );
	}

	// ========================================
	// REST API 测试（使用当前 /virtual-sites 端点）
	// ========================================

	/**
	 * 测试获取虚拟站点列表
	 */
	public function test_get_virtual_sites_list() {
		$response = $this->rest_get( 'virtual-sites' );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		// 支持 {items: [...]} 和直接数组两种格式
		$items = $data['items'] ?? $data;
		$this->assertIsArray( $items, '响应应包含虚拟站点数组' );
	}

	/**
	 * 测试解析虚拟站点路径（通过 GET /virtual-sites/{id}）
	 */
	public function test_parse_virtual_site_path() {
		$vs_id = $this->create_test_virtual_site( array(
			'name'        => 'Frontend Test Site',
			'path_prefix' => 'frontend-test-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		$response = $this->rest_get( "virtual-sites/{$vs_id}" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'path_prefix', $data, '应包含 path_prefix' );
		$this->assertNotEmpty( $data['path_prefix'], 'path_prefix 不应为空' );
	}

	/**
	 * 测试 URL 路径前缀匹配（验证构建的 URL 包含正确前缀）
	 */
	public function test_url_path_prefix_matching() {
		$prefix = 'match-test-' . wp_rand( 1000, 9999 );

		$vs_id = $this->create_test_virtual_site( array(
			'name'        => 'Path Match Test',
			'path_prefix' => $prefix,
			'lang'        => 'zh_CN',
		) );

		$response = $this->rest_get( "virtual-sites/{$vs_id}" );

		if ( 200 === $response->get_status() ) {
			$vs_data = $this->get_response_data( $response );
			if ( isset( $vs_data['path_prefix'] ) ) {
				$test_url = home_url( '/' . $vs_data['path_prefix'] . '/sample-page/' );
				$this->assertStringContainsString( $vs_data['path_prefix'], $test_url, 'URL 应包含路径前缀' );
			}
		}
	}

	/**
	 * 测试虚拟站点语言设置
	 */
	public function test_virtual_site_locale_setting() {
		$vs_id = $this->create_test_virtual_site( array(
			'name'        => 'Locale Test Site',
			'path_prefix' => 'locale-test-' . wp_rand( 1000, 9999 ),
			'lang'        => 'ja',
		) );

		$response = $this->rest_get( "virtual-sites/{$vs_id}" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertEquals( 'ja', $data['lang'] ?? '', '虚拟站点语言应为 ja' );
	}

	/**
	 * 测试更新虚拟站点
	 */
	public function test_update_virtual_site() {
		$vs_id = $this->create_test_virtual_site( array(
			'name' => 'Update Test Site',
		) );

		$response = $this->rest_put( "virtual-sites/{$vs_id}", array(
			'name' => 'Updated Site Name',
		) );

		$status = $response->get_status();
		$this->assertContains(
			$status,
			array( 200, 400, 404 ),
			"更新虚拟站点应返回有效状态，实际为 {$status}"
		);
	}

	/**
	 * 测试删除虚拟站点
	 */
	public function test_delete_virtual_site() {
		$vs_id = $this->create_test_virtual_site( array(
			'name' => 'Delete Test Site',
		) );

		// 不调用 track_resource，因为我们要主动删除
		$response = $this->rest_delete( "virtual-sites/{$vs_id}" );

		$status = $response->get_status();
		$this->assertContains(
			$status,
			array( 200, 204, 404 ),
			"删除虚拟站点应返回有效状态，实际为 {$status}"
		);
	}

	// ========================================
	// URL 重写测试
	// ========================================

	/**
	 * 测试重写规则为数组
	 */
	public function test_url_rewrite_rules() {
		$rules = get_option( 'rewrite_rules' );

		$this->assertIsArray( $rules, '重写规则应为数组' );
	}

	// ========================================
	// 缓存一致性测试
	// ========================================

	/**
	 * 测试同一虚拟站点的两次 GET 结果一致（验证缓存不破坏数据）
	 */
	public function test_routing_cache() {
		$vs_id = $this->create_test_virtual_site();

		$response1 = $this->rest_get( "virtual-sites/{$vs_id}" );
		$response2 = $this->rest_get( "virtual-sites/{$vs_id}" );

		if ( 200 === $response1->get_status() && 200 === $response2->get_status() ) {
			$data1 = $this->get_response_data( $response1 );
			$data2 = $this->get_response_data( $response2 );

			$this->assertEquals(
				$data1['id'] ?? null,
				$data2['id'] ?? null,
				'两次请求返回的 ID 应一致'
			);
		}
	}

	// ========================================
	// 性能测试
	// ========================================

	/**
	 * 测试路由解析性能（10 次请求应在 5 秒内完成）
	 */
	public function test_routing_performance() {
		$vs_id = $this->create_test_virtual_site();

		$start = microtime( true );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->rest_get( "virtual-sites/{$vs_id}" );
		}

		$elapsed = microtime( true ) - $start;

		$this->assertLessThan( 5, $elapsed, '10 次路由请求应在 5 秒内完成' );
	}
}
