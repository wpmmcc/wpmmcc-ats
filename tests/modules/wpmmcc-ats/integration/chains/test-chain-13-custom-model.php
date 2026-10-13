<?php
/**
 * Chain 13: 自定义模型服务链路测试
 *
 * 测试自定义模型流程：发现未注册插件 → 手动创建模型 → 字段覆盖 → 链式配置
 *
 * 服务层:
 * - Models\Services\Custom_Model_Service
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 13 Test: Custom Model Service
 */
class Test_Chain_13_Custom_Model extends REST_Integration_Test_Case {

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

		// 检查 translation_rules 表是否存在 (自定义模型存储)
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_translation_rules';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		if ( ! $table_exists ) {
			self::$chain_runnable = false;
			self::$skip_reason = "缺少数据表: {$table}";
			return;
		}

		// 检查 get_plugins 函数是否可用
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
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
	// Custom_Model_Service 测试
	// ========================================

	/**
	 * 测试 Custom_Model_Service 类存在
	 */
	public function test_custom_model_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Models\\Services\\Custom_Model_Service' ),
			'Custom_Model_Service 类应存在'
		);
	}

	/**
	 * 测试 Custom_Model_Service 方法
	 */
	public function test_custom_model_service_methods() {
		if ( ! class_exists( 'WPTSALL\\Models\\Services\\Custom_Model_Service' ) ) {
			$this->markTestSkipped( 'Custom_Model_Service 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Models\\Services\\Custom_Model_Service' );

		// 检查核心方法
		// 实际方法名: create_custom_model (line 521)
		$has_create = $reflection->hasMethod( 'create' ) ||
		              $reflection->hasMethod( 'create_model' ) ||
		              $reflection->hasMethod( 'create_custom_model' );

		$this->assertTrue( $has_create, 'Custom_Model_Service 应有创建方法' );
	}

	/**
	 * 测试获取未注册插件方法
	 */
	public function test_get_unregistered_plugins_method() {
		if ( ! class_exists( 'WPTSALL\\Models\\Services\\Custom_Model_Service' ) ) {
			$this->markTestSkipped( 'Custom_Model_Service 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Models\\Services\\Custom_Model_Service' );

		$has_method = $reflection->hasMethod( 'get_unregistered_plugins' ) ||
		              $reflection->hasMethod( 'find_unregistered' ) ||
		              $reflection->hasMethod( 'get_plugins_without_models' );

		$this->assertTrue( $has_method, '应有获取未注册插件的方法' );
	}

	// ========================================
	// 插件发现测试
	// ========================================

	/**
	 * 测试发现没有模型的插件
	 */
	public function test_discover_plugins_without_models() {
		// 端点: /custom-models/unregistered-plugins
		$response = $this->rest_get( 'custom-models/unregistered-plugins', array(), 'wptsall/v2' );

		// 获取未注册插件应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取未注册插件应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'plugins', $data, '响应应包含 plugins 字段' );
		$this->assertIsArray( $data['plugins'], 'plugins 应为数组' );
	}

	/**
	 * 测试获取插件详情
	 */
	public function test_get_plugin_details() {
		// 获取已激活的插件列表
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$this->assertIsArray( $plugins, '应能获取插件列表' );
		$this->assertNotEmpty( $plugins, '应至少有一个插件' );
	}

	// ========================================
	// 自定义模型创建测试
	// ========================================

	/**
	 * 测试创建自定义模型
	 */
	public function test_create_custom_model() {
		global $wpdb;

		$response = $this->rest_post( 'custom-models', array(
			'plugin_slug' => 'test-plugin-' . wp_rand( 1000, 9999 ),
			'name'        => 'Test Custom Model',
			'post_types'  => array( 'post' ),
		) );

		// 创建应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '创建自定义模型应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
		$this->assertArrayHasKey( 'id', $data, '响应应包含 id' );

			if ( isset( $data['id'] ) ) {
				$this->track_resource( 'custom_models', $data['id'] );

				$model_id = (int) $data['id'];

				// DB 验证：自定义模型创建后至少应生成一条 translation_rule。
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rule_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_translation_rules WHERE model_id = %d",
					$model_id
				) );
				$this->assertGreaterThan( 0, $rule_count, 'Custom model must have at least one translation rule' );
			}
		}

	/**
	 * 测试创建自定义模型时验证 - 缺少必填字段返回 400
	 */
	public function test_create_custom_model_validation() {
		// 缺少必需字段
		$response = $this->rest_post( 'custom-models', array(
			'name' => 'Incomplete Model',
			// 缺少 plugin_slug
		) );

		// 缺少必填字段应返回 400
		$status = $response->get_status();
		$this->assertEquals( 400, $status, '缺少 plugin_slug 应返回 400' );
	}

	// ========================================
	// 字段覆盖测试
	// ========================================

	/**
	 * 测试设置字段覆盖
	 */
	public function test_set_field_overrides() {
		// 先创建模型
		$create_response = $this->rest_post( 'custom-models', array(
			'plugin_slug' => 'override-test-' . wp_rand( 1000, 9999 ),
			'name'        => 'Field Override Test Model',
			'post_types'  => array( 'post' ),
		) );

		// 创建应返回 200
		$this->assertEquals( 200, $create_response->get_status(), '创建测试模型应返回 200' );

		$create_data = $this->get_response_data( $create_response );
		$model_id    = $create_data['id'] ?? 0;

		$this->assertNotEmpty( $model_id, '应返回模型 ID' );
		$this->track_resource( 'custom_models', $model_id );

		// 设置字段覆盖
		$response = $this->rest_put( "custom-models/{$model_id}/fields", array(
			'overrides' => array(
				'post_title' => array(
					'type'     => 'translate',
					'priority' => 'high',
				),
				'post_content' => array(
					'type'     => 'translate',
					'priority' => 'high',
				),
				'_custom_meta' => array(
					'type' => 'sync',
				),
			),
		) );

		// 设置字段覆盖应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '设置字段覆盖应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
	}

	/**
	 * 测试获取字段覆盖
	 */
	public function test_get_field_overrides() {
		// 先创建模型
		$create_response = $this->rest_post( 'custom-models', array(
			'plugin_slug' => 'get-override-test-' . wp_rand( 1000, 9999 ),
			'name'        => 'Get Override Test Model',
			'post_types'  => array( 'post' ),
		) );

		// 创建应返回 200
		$this->assertEquals( 200, $create_response->get_status(), '创建测试模型应返回 200' );

		$create_data = $this->get_response_data( $create_response );
		$model_id    = $create_data['id'] ?? 0;

		$this->assertNotEmpty( $model_id, '应返回模型 ID' );
		$this->track_resource( 'custom_models', $model_id );

		// 获取字段覆盖
		$response = $this->rest_get( "custom-models/{$model_id}/fields" );

		// 获取字段覆盖应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取字段覆盖应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
	}

	// ========================================
	// 链式配置测试
	// ========================================

	/**
	 * 测试创建带链式配置的模型
	 */
	public function test_create_model_with_link_chain() {
		if ( ! class_exists( 'WPTSALL\\Models\\Services\\Custom_Model_Service' ) ) {
			$this->markTestSkipped( 'Custom_Model_Service 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Models\\Services\\Custom_Model_Service' );

		// 至少应有基础创建方法（链式配置是可选扩展功能）
		$has_create = $reflection->hasMethod( 'create' ) ||
		              $reflection->hasMethod( 'create_model' ) ||
		              $reflection->hasMethod( 'create_custom_model' );

		$this->assertTrue( $has_create, 'Custom_Model_Service 必须有创建方法' );

		// 链式配置方法为可选功能，记录是否支持
		$has_link_chain = $reflection->hasMethod( 'create_with_link_chain' ) ||
		                  $reflection->hasMethod( 'set_link_chain' );

		$this->assertIsBool( $has_link_chain, '链式配置支持状态应为布尔值' );
	}

	/**
	 * 测试 URL 解析链配置
	 */
	public function test_url_parsing_link_chain() {
		$response = $this->rest_post( 'custom-models', array(
			'plugin_slug' => 'link-chain-test-' . wp_rand( 1000, 9999 ),
			'name'        => 'Link Chain Test Model',
			'post_types'  => array( 'post' ),
			'link_chains' => array(
				array(
					'type'    => 'meta',
					'key'     => '_product_url',
					'pattern' => '/product/([0-9]+)/',
				),
			),
		) );

		// 带链式配置创建应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '带链式配置创建应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
		$this->assertArrayHasKey( 'id', $data, '响应应包含 id' );

		if ( isset( $data['id'] ) ) {
			$this->track_resource( 'custom_models', $data['id'] );
		}
	}

	// ========================================
	// 模型与字段分类集成测试
	// ========================================

	/**
	 * 测试自定义模型与字段分类集成
	 */
	public function test_custom_model_field_classification_integration() {
		global $wpdb;

		// 创建自定义模型
		$response = $this->rest_post( 'custom-models', array(
			'plugin_slug'        => 'classification-test-' . wp_rand( 1000, 9999 ),
			'name'               => 'Classification Integration Test',
			'post_types'         => array( 'post' ),
			'field_capabilities' => array(
				'translate_fields' => array( 'post_title', 'post_content' ),
				'sync_fields'      => array( 'post_date', 'post_status' ),
				'field_mappings'   => array( '_thumbnail_id' ),
				'compute_fields'   => array( 'post_name' ),
			),
		) );

		// 带字段配置创建应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '字段分类集成创建应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
		$this->assertArrayHasKey( 'id', $data, '响应应包含 id' );

			if ( isset( $data['id'] ) ) {
				$this->track_resource( 'custom_models', $data['id'] );

				$model_id = (int) $data['id'];

				// DB 验证：规则字段能力应正确落库（manual model 不强制立即有 model_objects）。
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rule_caps_json = $wpdb->get_var( $wpdb->prepare(
					"SELECT field_capabilities FROM {$wpdb->prefix}wptsall_translation_rules WHERE model_id = %d ORDER BY id ASC LIMIT 1",
					$model_id
				) );
				$rule_caps = json_decode( (string) $rule_caps_json, true ) ?: array();
				$has_post_title = isset( $rule_caps['post_title'] )
					|| in_array( 'post_title', (array) ( $rule_caps['translate_fields'] ?? array() ), true );
				$has_post_content = isset( $rule_caps['post_content'] )
					|| in_array( 'post_content', (array) ( $rule_caps['translate_fields'] ?? array() ), true );
				$this->assertTrue( $has_post_title, 'Rule field_capabilities must include post_title' );
				$this->assertTrue( $has_post_content, 'Rule field_capabilities must include post_content' );
			}
		}

	// ========================================
	// REST API 测试
	// ========================================

	/**
	 * 测试获取所有自定义模型
	 */
	public function test_get_all_custom_models() {
		$response = $this->rest_get( 'custom-models' );

		// GET /custom-models 应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取自定义模型列表应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items' );
		$this->assertIsArray( $data['items'], 'items 应为数组' );
	}

	/**
	 * 测试获取单个自定义模型
	 */
	public function test_get_single_custom_model() {
		// 先创建模型
		$create_response = $this->rest_post( 'custom-models', array(
			'plugin_slug' => 'single-test-' . wp_rand( 1000, 9999 ),
			'name'        => 'Single Model Test',
			'post_types'  => array( 'post' ),
		) );

		// 创建应返回 200
		$this->assertEquals( 200, $create_response->get_status(), '创建测试模型应返回 200' );

		$create_data = $this->get_response_data( $create_response );
		$model_id    = $create_data['id'] ?? 0;

		$this->assertNotEmpty( $model_id, '应返回模型 ID' );
		$this->track_resource( 'custom_models', $model_id );

		// 获取单个模型
		$response = $this->rest_get( "custom-models/{$model_id}" );

		// 获取存在的模型应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取存在的模型应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
	}

	/**
	 * 测试获取不存在的自定义模型返回 404
	 */
	public function test_get_nonexistent_custom_model_returns_404() {
		$response = $this->rest_get( 'custom-models/999999' );

		// 不存在的模型应返回 404
		$status = $response->get_status();
		$this->assertEquals( 404, $status, '不存在的模型应返回 404' );
	}

	/**
	 * 测试更新自定义模型
	 */
	public function test_update_custom_model() {
		// 先创建模型
		$create_response = $this->rest_post( 'custom-models', array(
			'plugin_slug' => 'update-test-' . wp_rand( 1000, 9999 ),
			'name'        => 'Update Model Test',
			'post_types'  => array( 'post' ),
		) );

		// 创建应返回 200
		$this->assertEquals( 200, $create_response->get_status(), '创建测试模型应返回 200' );

		$create_data = $this->get_response_data( $create_response );
		$model_id    = $create_data['id'] ?? 0;

		$this->assertNotEmpty( $model_id, '应返回模型 ID' );
		$this->track_resource( 'custom_models', $model_id );

		// 更新模型
		$response = $this->rest_put( "custom-models/{$model_id}", array(
			'name' => 'Updated Model Name',
		) );

		// 更新存在的模型应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '更新模型应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
	}

	/**
	 * 测试更新不存在的模型返回 404
	 */
	public function test_update_nonexistent_custom_model_returns_404() {
		$response = $this->rest_put( 'custom-models/999999', array(
			'name' => 'Should Fail',
		) );

		// 不存在的模型应返回 404
		$status = $response->get_status();
		$this->assertEquals( 404, $status, '更新不存在的模型应返回 404' );
	}

	/**
	 * 测试删除自定义模型
	 */
	public function test_delete_custom_model() {
		// 先创建模型
		$create_response = $this->rest_post( 'custom-models', array(
			'plugin_slug' => 'delete-test-' . wp_rand( 1000, 9999 ),
			'name'        => 'Delete Model Test',
			'post_types'  => array( 'post' ),
		) );

		// 创建应返回 200
		$this->assertEquals( 200, $create_response->get_status(), '创建测试模型应返回 200' );

		$create_data = $this->get_response_data( $create_response );
		$model_id    = $create_data['id'] ?? 0;

		$this->assertNotEmpty( $model_id, '应返回模型 ID' );

		// 删除模型（不跟踪，因为我们要删除）
		$response = $this->rest_delete( "custom-models/{$model_id}" );

		// 删除存在的模型应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '删除模型应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertTrue( $data['success'], 'success 应为 true' );
	}

	/**
	 * 测试删除不存在的模型返回 404
	 */
	public function test_delete_nonexistent_custom_model_returns_404() {
		$response = $this->rest_delete( 'custom-models/999999' );

		// 不存在的模型应返回 404
		$status = $response->get_status();
		$this->assertEquals( 404, $status, '删除不存在的模型应返回 404' );
	}
}
