<?php
/**
 * Chain 4: 虚拟站点路由链路测试
 *
 * 测试虚拟站点路由：URL 路由 → 内容解析 → 冲突检测
 *
 * REST 端点:
 * - GET  /wptsall/v1/virtual-sites
 * - POST /wptsall/v1/virtual-sites
 * - GET  /wptsall/v1/virtual-sites/{id}
 * - PUT  /wptsall/v1/virtual-sites/{id}
 * - DELETE /wptsall/v1/virtual-sites/{id}
 * - GET  /wptsall/v1/virtual-sites/check-url
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 4 Test: Virtual Site Routing
 */
class Test_Chain_4_Virtual_Routing extends REST_Integration_Test_Case {

	// ========================================
	// 虚拟站点创建测试
	// ========================================

	/**
	 * 测试创建虚拟站点
	 *
	 * @covers Sites_REST_Controller::create_virtual_site
	 */
	public function test_create_virtual_site() {
		$response = $this->rest_post( 'virtual-sites', array(
			'name'        => 'Virtual Routing Test Site',
			'path_prefix' => 'vr-test-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'], '创建虚拟站点应成功' );
		$this->assertArrayHasKey( 'site_id', $data );

		// 跟踪以便清理
		if ( isset( $data['site_id'] ) ) {
			$this->track_resource( 'virtual_sites', $data['site_id'] );
		}
	}

	/**
	 * 测试创建虚拟站点时验证必需字段
	 */
	public function test_create_virtual_site_validates_required_fields() {
		// 缺少 name
		$response = $this->rest_post( 'virtual-sites', array(
			'path_prefix' => 'missing-name-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		$status = $response->get_status();
		$this->assertGreaterThanOrEqual( 400, $status, '缺少 name 应返回错误' );
	}

	/**
	 * 测试获取所有虚拟站点
	 *
	 * @covers Sites_REST_Controller::get_virtual_sites
	 */
	public function test_get_all_virtual_sites() {
		// 创建测试虚拟站点
		$this->create_test_virtual_site();

		$response = $this->rest_get( 'virtual-sites' );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertIsArray( $data, '响应应为数组' );
	}

	/**
	 * 测试获取单个虚拟站点
	 *
	 * @covers Sites_REST_Controller::get_virtual_site
	 */
	public function test_get_single_virtual_site() {
		$site_id = $this->create_test_virtual_site();

		$response = $this->rest_get( "virtual-sites/{$site_id}" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertEquals( $site_id, $data['id'] ?? null, '返回的站点 ID 应匹配' );
	}

	/**
	 * 测试获取不存在的虚拟站点
	 */
	public function test_get_nonexistent_virtual_site() {
		$response = $this->rest_get( 'virtual-sites/999999' );

		$this->assertRestError( $response, 'not_found', 404 );
	}

	// ========================================
	// URL 路由测试
	// ========================================

	/**
	 * 测试 URL 路径路由
	 *
	 * 验证虚拟站点的 path_prefix 正确设置并可路由
	 */
	public function test_url_path_routing() {
		$path_prefix = 'url-test-' . wp_rand( 1000, 9999 );

		$site_id = $this->create_test_virtual_site( array(
			'name'        => 'URL Routing Test',
			'path_prefix' => $path_prefix,
			'lang'        => 'zh_CN',
		) );

		// 获取站点信息验证 path_prefix
		$response = $this->rest_get( "virtual-sites/{$site_id}" );
		$data = $this->get_response_data( $response );

		$this->assertEquals( $path_prefix, $data['path_prefix'] ?? '', 'path_prefix 应正确保存' );
	}

	/**
	 * 测试虚拟站点 URL 生成
	 */
	public function test_virtual_site_url_generation() {
		$path_prefix = 'url-gen-' . wp_rand( 1000, 9999 );

		$site_id = $this->create_test_virtual_site( array(
			'name'        => 'URL Generation Test',
			'path_prefix' => $path_prefix,
			'lang'        => 'zh_CN',
		) );

		// 获取站点信息
		$response = $this->rest_get( "virtual-sites/{$site_id}" );
		$data = $this->get_response_data( $response );

		// 验证 URL 相关字段
		$this->assertArrayHasKey( 'path_prefix', $data );

		// 如果有 url 字段，验证它包含 path_prefix
		if ( isset( $data['url'] ) ) {
			$this->assertStringContainsString( $path_prefix, $data['url'] );
		}
	}

	// ========================================
	// 内容解析测试
	// ========================================

	/**
	 * 测试虚拟站点内容解析
	 *
	 * 验证 Virtual_Site_Router 能正确解析虚拟站点的内容请求
	 */
	public function test_content_resolution() {
		$site_id = $this->create_test_virtual_site( array(
			'name'        => 'Content Resolution Test',
			'path_prefix' => 'content-res-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		// 获取站点信息确保创建成功
		$response = $this->rest_get( "virtual-sites/{$site_id}" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'lang', $data, '站点应包含语言设置' );
		$this->assertEquals( 'zh_CN', $data['lang'], '语言应为 zh_CN' );
	}

	// ========================================
	// URL 冲突检测测试
	// ========================================

	/**
	 * 测试 URL 冲突检测
	 *
	 * @covers Sites_REST_Controller::check_url_conflict
	 */
	public function test_url_conflict_detection() {
		$path_prefix = 'conflict-test-' . wp_rand( 1000, 9999 );

		// 创建第一个虚拟站点
		$site_id = $this->create_test_virtual_site( array(
			'name'        => 'Conflict Test 1',
			'path_prefix' => $path_prefix,
			'lang'        => 'zh_CN',
		) );

		// 检查相同 path_prefix 是否有冲突
		$response = $this->rest_get( 'virtual-sites/check-url', array(
			'path_prefix' => $path_prefix,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'has_conflict', $data );
		$this->assertTrue( $data['has_conflict'], '相同 path_prefix 应检测到冲突' );
	}

	/**
	 * 测试排除当前站点的冲突检测
	 */
	public function test_url_conflict_detection_with_exclude() {
		$path_prefix = 'exclude-test-' . wp_rand( 1000, 9999 );

		// 创建虚拟站点
		$site_id = $this->create_test_virtual_site( array(
			'name'        => 'Exclude Test',
			'path_prefix' => $path_prefix,
			'lang'        => 'zh_CN',
		) );

		// 检查冲突时排除当前站点
		$response = $this->rest_get( 'virtual-sites/check-url', array(
			'path_prefix' => $path_prefix,
			'exclude_id'  => $site_id,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'has_conflict', $data );
		$this->assertFalse( $data['has_conflict'], '排除自己后不应有冲突' );
	}

	/**
	 * 测试不存在的 path_prefix 无冲突
	 */
	public function test_no_conflict_for_new_path() {
		$response = $this->rest_get( 'virtual-sites/check-url', array(
			'path_prefix' => 'new-unique-path-' . wp_rand( 10000, 99999 ),
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'has_conflict', $data );
		$this->assertFalse( $data['has_conflict'], '新路径不应有冲突' );
	}

	// ========================================
	// 虚拟站点更新测试
	// ========================================

	/**
	 * 测试更新虚拟站点
	 *
	 * @covers Sites_REST_Controller::update_virtual_site
	 */
	public function test_update_virtual_site() {
		$site_id = $this->create_test_virtual_site();

		$new_name = 'Updated Site Name ' . wp_rand( 1000, 9999 );

		$response = $this->rest_put( "virtual-sites/{$site_id}", array(
			'name' => $new_name,
		) );

		$this->assertRestSuccess( $response, 200 );

		// 验证更新
		$verify_response = $this->rest_get( "virtual-sites/{$site_id}" );
		$verify_data = $this->get_response_data( $verify_response );
		$this->assertEquals( $new_name, $verify_data['name'] ?? '' );
	}

	/**
	 * 测试更新虚拟站点语言
	 */
	public function test_update_virtual_site_language() {
		$site_id = $this->create_test_virtual_site( array(
			'lang' => 'zh_CN',
		) );

		$response = $this->rest_put( "virtual-sites/{$site_id}", array(
			'lang' => 'ja',
		) );

		$this->assertRestSuccess( $response, 200 );

		// 验证更新
		$verify_response = $this->rest_get( "virtual-sites/{$site_id}" );
		$verify_data = $this->get_response_data( $verify_response );
		$this->assertEquals( 'ja', $verify_data['lang'] ?? '' );
	}

	// ========================================
	// 虚拟站点删除测试
	// ========================================

	/**
	 * 测试删除虚拟站点
	 *
	 * @covers Sites_REST_Controller::delete_virtual_site
	 */
	public function test_delete_virtual_site() {
		$site_id = $this->create_test_virtual_site();

		// 从跟踪列表移除（我们要手动删除）
		$this->tracked_resources['virtual_sites'] = array_diff(
			$this->tracked_resources['virtual_sites'],
			array( $site_id )
		);

		// 删除
		$response = $this->rest_delete( "virtual-sites/{$site_id}" );
		$this->assertRestSuccess( $response, 200 );

		// 验证删除
		$verify_response = $this->rest_get( "virtual-sites/{$site_id}" );
		$this->assertRestError( $verify_response, 'not_found', 404 );
	}

	// ========================================
	// 批量操作测试
	// ========================================

	/**
	 * 测试批量创建虚拟站点
	 *
	 * @covers Sites_REST_Controller::bulk_create_virtual_sites
	 */
	public function test_bulk_create_virtual_sites() {
		$response = $this->rest_post( 'virtual-sites/bulk', array(
			'sites' => array(
				array(
					'name'        => 'Bulk Test 1',
					'path_prefix' => 'bulk-1-' . wp_rand( 1000, 9999 ),
					'lang'        => 'zh_CN',
				),
				array(
					'name'        => 'Bulk Test 2',
					'path_prefix' => 'bulk-2-' . wp_rand( 1000, 9999 ),
					'lang'        => 'ja',
				),
			),
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'created', $data );

		// 跟踪创建的站点
		foreach ( $data['created'] ?? array() as $created ) {
			if ( isset( $created['site_id'] ) ) {
				$this->track_resource( 'virtual_sites', $created['site_id'] );
			}
		}
	}

	/**
	 * 测试批量删除虚拟站点
	 *
	 * @covers Sites_REST_Controller::bulk_delete_virtual_sites
	 */
	public function test_bulk_delete_virtual_sites() {
		// 创建两个站点
		$site_id_1 = $this->create_test_virtual_site( array(
			'path_prefix' => 'bulk-del-1-' . wp_rand( 1000, 9999 ),
		) );
		$site_id_2 = $this->create_test_virtual_site( array(
			'path_prefix' => 'bulk-del-2-' . wp_rand( 1000, 9999 ),
		) );

		// 从跟踪列表移除
		$this->tracked_resources['virtual_sites'] = array_diff(
			$this->tracked_resources['virtual_sites'],
			array( $site_id_1, $site_id_2 )
		);

		// 批量删除
		$response = $this->rest_post( 'virtual-sites/bulk-delete', array(
			'site_ids' => array( $site_id_1, $site_id_2 ),
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'deleted', $data );
	}

	// ========================================
	// 服务层测试
	// ========================================

	/**
	 * 测试 Virtual_Site_Service 存在且可用
	 */
	public function test_virtual_site_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Sites\Services\Virtual_Site_Service' ),
			'Virtual_Site_Service 类应存在'
		);
	}

	/**
	 * 测试 Virtual_Site_Router 存在且可用
	 */
	public function test_virtual_site_router_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Virtual_Site_Router' ),
			'Virtual_Site_Router 类应存在'
		);
	}

	/**
	 * 测试 Virtual_Site_Router::detect_virtual_site() 静态方法存在
	 *
	 * 验证路由检测入口点是静态方法而非实例方法
	 */
	public function test_virtual_site_router_detect_method_is_static() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Virtual_Site_Router' ),
			'Virtual_Site_Router 类应存在'
		);

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Virtual_Site_Router' );

		// detect_virtual_site 或等效方法应存在
		$has_detect = $reflection->hasMethod( 'detect_virtual_site' ) ||
		              $reflection->hasMethod( 'detect' ) ||
		              $reflection->hasMethod( 'get_current_virtual_site' );

		$this->assertTrue( $has_detect, 'Virtual_Site_Router must have a detect_virtual_site (or equivalent) method' );

		// 如果 detect_virtual_site 存在，它应该是静态方法
		if ( $reflection->hasMethod( 'detect_virtual_site' ) ) {
			$method = $reflection->getMethod( 'detect_virtual_site' );
			$this->assertTrue( $method->isStatic(), 'Virtual_Site_Router::detect_virtual_site() must be a static method' );
		}
	}

	/**
	 * 测试 REQUEST_URI 通过 wptsall_get_request_uri() 清理（路径非完整 URL）
	 *
	 * 1.9.0+：不再对 REQUEST_URI 使用 esc_url_raw()；保留路径斜杠与 query。
	 */
	public function test_router_uses_wptsall_get_request_uri_for_uri_matching() {
		$this->assertTrue(
			function_exists( 'wptsall_get_request_uri' ),
			'wptsall_get_request_uri() must exist for REQUEST_URI sanitization'
		);

		// Preserve path + query; reject control characters.
		$_SERVER['REQUEST_URI'] = "/zh-cn/some-page/?param=value\x00";
		$sanitized              = wptsall_get_request_uri();
		$this->assertStringContainsString( '/', $sanitized, 'wptsall_get_request_uri() must preserve path slashes' );
		$this->assertStringContainsString( 'param=value', $sanitized, 'wptsall_get_request_uri() must keep query string' );
		$this->assertStringNotContainsString( "\x00", $sanitized, 'control characters must be stripped' );

		// Router source must not use esc_url_raw( $_SERVER['REQUEST_URI'] ).
		$router_file = WPTSALL_PATH . 'includes/hooks/class-virtual-site-router.php';
		if ( file_exists( $router_file ) ) {
			$src = file_get_contents( $router_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertStringNotContainsString(
				"esc_url_raw( wp_unslash( \$_SERVER['REQUEST_URI'] ) )",
				$src,
				'Virtual_Site_Router must not use esc_url_raw on REQUEST_URI'
			);
			$this->assertStringContainsString(
				'wptsall_get_request_uri',
				$src,
				'Virtual_Site_Router must call wptsall_get_request_uri()'
			);
		}
	}
}
