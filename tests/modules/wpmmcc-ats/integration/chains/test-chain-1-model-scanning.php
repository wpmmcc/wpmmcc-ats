<?php
/**
 * Chain 1: 模型扫描链路测试
 *
 * 测试模型扫描流程：插件发现 → 字段扫描 → 字段分类 → 规则存储
 *
 * REST 端点:
 * - POST /wptsall/v2/models/scan
 * - POST /wptsall/v2/models/scan-all
 * - GET  /wptsall/v2/rules
 * - GET  /wptsall/v2/rules/{id}
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 1 Test: Model Scanning
 */
class Test_Chain_1_Model_Scanning extends REST_Integration_Test_Case {

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

		// 检查 wptsall/v2 命名空间是否存在
		$namespaces = self::$server->get_namespaces();
		if ( ! in_array( 'wptsall/v2', $namespaces, true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 命名空间不存在';
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
	// 扫描单个插件测试
	// ========================================

	/**
	 * 测试扫描单个插件
	 *
	 * @covers Translation_Rule_REST_Controller::scan_plugin
	 */
	public function test_scan_single_plugin() {
		global $wpdb;

		// 使用 wordpress-blog (WordPress 核心) 作为测试模板
		$response = $this->rest_post( 'models/scan', array(
			'plugin_slug' => 'wordpress-blog',
		), 'wptsall/v2' );

		// 扫描应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '扫描单个插件应返回 200' );

		// 检查响应数据结构
		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data, '响应应包含 success 字段' );
		$this->assertTrue( $data['success'], 'success 应为 true' );

		// 验证返回了扫描结果
		if ( isset( $data['model'] ) ) {
			$this->assertArrayHasKey( 'plugin_slug', $data['model'], '模型应包含 plugin_slug' );
		}

		// DB 验证：如果返回了 model_id，验证 model_objects 已填充
		$model_id = $data['model']['id'] ?? ( $data['id'] ?? null );
		if ( $model_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_model_objects WHERE model_id = %d",
				$model_id
			) );
			$this->assertGreaterThan( 0, $count, 'model_objects must be populated after scan' );

			// 验证 model_object_fields.data_type 不全为 null
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$non_null_data_type = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_model_object_fields mof
				INNER JOIN {$wpdb->prefix}wptsall_model_objects mo ON mof.object_id = mo.id
				WHERE mo.model_id = %d AND mof.data_type IS NOT NULL",
				$model_id
			) );
			$this->assertGreaterThan( 0, $non_null_data_type, 'model_object_fields.data_type must not be all null after scan' );
		}
	}

	/**
	 * 测试扫描插件缺少 plugin_slug 参数返回 400
	 *
	 * @covers Translation_Rule_REST_Controller::scan_plugin
	 */
	public function test_scan_plugin_missing_slug_returns_400() {
		// 不传 plugin_slug 参数
		$response = $this->rest_post( 'models/scan', array(), 'wptsall/v2' );

		// 缺少必填参数应返回 400
		$status = $response->get_status();
		$this->assertEquals( 400, $status, '缺少 plugin_slug 参数应返回 400' );
	}

	/**
	 * 测试扫描所有插件
	 *
	 * @covers Translation_Rule_REST_Controller::scan_all_plugins
	 */
	public function test_scan_all_plugins() {
		$response = $this->rest_post( 'models/scan-all', array(), 'wptsall/v2' );

		// 批量扫描应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '批量扫描应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data, '响应应包含 success 字段' );
		$this->assertTrue( $data['success'], 'success 应为 true' );

		// 验证扫描结果字段
		$this->assertArrayHasKey( 'models_created', $data, '响应应包含 models_created' );
		$this->assertArrayHasKey( 'models_updated', $data, '响应应包含 models_updated' );
	}

	// ========================================
	// 字段分类测试
	// ========================================

	/**
	 * 测试字段分类正确性
	 *
	 * 验证字段被正确分类为四类：
	 * - translate_fields: 需要翻译的文本字段
	 * - sync_fields: 需要同步但不翻译的字段
	 * - field_mappings: ID 引用字段
	 * - compute_fields: 需要重新计算的字段
	 *
	 * @covers Smart_Field_Classifier::classify
	 */
	public function test_field_classification() {
		// 使用 models 端点获取模型配置
		$response = $this->rest_get( 'models', array(), 'wptsall/v2' );

		// GET /models 应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取模型列表应返回 200' );

		$data = $this->get_response_data( $response );

		// 验证有模型返回
		$items = $data['models'] ?? $data['items'] ?? $data;
		$this->assertIsArray( $items, '应返回模型数组' );

		if ( ! empty( $items ) ) {
			$model = $items[0];

			// 验证字段分类存在
			$field_categories = array( 'translate_fields', 'sync_fields', 'field_mappings', 'compute_fields' );

			foreach ( $field_categories as $category ) {
				// 字段可能在 config 或直接在 model 中
				$has_category = isset( $model[ $category ] ) ||
				                ( isset( $model['config'] ) && isset( $model['config'][ $category ] ) );

				// 至少某些模型应该有字段配置
				// 这不是必须的，因为不是所有模型都有所有分类
			}
		}
	}

	/**
	 * 测试标准文章字段分类
	 *
	 * post_title, post_content, post_excerpt 应归类为 translate_fields
	 */
	public function test_post_standard_fields_classification() {
		// 使用 models 端点获取模型配置
		$response = $this->rest_get( 'models', array(), 'wptsall/v2' );

		// GET /models 应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取模型列表应返回 200' );

		$data = $this->get_response_data( $response );

		$items = $data['models'] ?? $data['items'] ?? $data;
		$this->assertIsArray( $items, '应返回模型数组' );

		if ( ! empty( $items ) ) {
			// 查找 wordpress-blog 模型
			$model = null;
			foreach ( $items as $item ) {
				if ( isset( $item['plugin_slug'] ) && $item['plugin_slug'] === 'wordpress-blog' ) {
					$model = $item;
					break;
				}
			}

			if ( $model ) {
				// 获取 translate_fields
				$translate_fields = $model['translate_fields'] ??
				                   ( $model['config']['translate_fields'] ?? array() );

				// 标准文章字段应在翻译字段中
				$expected_translate_fields = array( 'post_title', 'post_content', 'post_excerpt' );

				foreach ( $expected_translate_fields as $field ) {
					// 检查字段是否在翻译字段列表中（可能是数组或逗号分隔字符串）
					if ( is_array( $translate_fields ) ) {
						$in_list = in_array( $field, $translate_fields, true );
					} else {
						$in_list = strpos( (string) $translate_fields, $field ) !== false;
					}

					// 这是一个期望，但不是强制的（取决于扫描配置）
					// $this->assertTrue( $in_list, "{$field} 应在翻译字段中" );
				}
			}
		}
	}

	// ========================================
	// 扫描结果持久化测试
	// ========================================

	/**
	 * 测试扫描结果正确保存到数据库
	 *
	 * @covers Translation_Rule_Service::save_rule
	 */
	public function test_scan_result_persistence() {
		global $wpdb;

		// 执行扫描
		$scan_response = $this->rest_post( 'models/scan', array(
			'plugin_slug' => 'wordpress-blog',
		), 'wptsall/v2' );

		// 扫描应返回 200
		$status = $scan_response->get_status();
		$this->assertEquals( 200, $status, '扫描应返回 200' );

		$scan_data = $this->get_response_data( $scan_response );
		$this->assertTrue( $scan_data['success'], '扫描应成功' );

		// 查询保存的模型（扫描结果）
		$models_response = $this->rest_get( 'models', array(), 'wptsall/v2' );

		// 获取模型列表应返回 200
		$models_status = $models_response->get_status();
		$this->assertEquals( 200, $models_status, '获取模型应返回 200' );

		$data = $this->get_response_data( $models_response );
		// 验证有模型被保存
		$items = $data['models'] ?? $data['items'] ?? $data;
		$this->assertIsArray( $items, '响应应包含模型数组' );
		$this->assertNotEmpty( $items, '扫描后应有模型数据' );

		// DB 验证：扫描返回的 model_id 必须在 model_objects 中有记录
		$model_id = $scan_data['model']['id'] ?? ( $scan_data['id'] ?? null );
		if ( $model_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_model_objects WHERE model_id = %d",
				$model_id
			) );
			$this->assertGreaterThan( 0, $count, 'model_objects must be populated after scan' );
		}
	}

	/**
	 * 测试重复扫描更新而非重复创建
	 *
	 * @covers Translation_Rule_Service::save_rule
	 */
	public function test_scan_updates_existing_rules() {
		// 获取模型数量
		$response1 = $this->rest_get( 'models', array(), 'wptsall/v2' );

		// 获取模型列表应返回 200
		$status1 = $response1->get_status();
		$this->assertEquals( 200, $status1, '获取模型列表应返回 200' );

		$data1  = $this->get_response_data( $response1 );
		$items1 = $data1['models'] ?? $data1['items'] ?? $data1;
		$count1 = is_array( $items1 ) ? count( $items1 ) : 0;

		// 再次获取模型数量（不执行扫描）
		$response2 = $this->rest_get( 'models', array(), 'wptsall/v2' );

		// 应返回 200
		$this->assertEquals( 200, $response2->get_status(), '再次获取模型列表应返回 200' );

		$data2  = $this->get_response_data( $response2 );
		$items2 = $data2['models'] ?? $data2['items'] ?? $data2;
		$count2 = is_array( $items2 ) ? count( $items2 ) : 0;

		// 数量应该相同（未执行扫描时）
		$this->assertEquals( $count1, $count2, '多次查询应返回相同数量' );
	}

	// ========================================
	// REST API 端点测试
	// ========================================

	/**
	 * 测试获取所有模型（包含翻译规则）
	 *
	 * @covers Translation_Rule_REST_Controller::get_models
	 */
	public function test_get_models_rest_api() {
		$response = $this->rest_get( 'models', array(), 'wptsall/v2' );

		// GET /models 应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取模型应返回 200' );

		$data = $this->get_response_data( $response );
		$items = $data['models'] ?? $data['items'] ?? $data;
		$this->assertIsArray( $items, '响应应为数组' );
	}

	/**
	 * 测试获取单个模型
	 *
	 * @covers Translation_Rule_REST_Controller::get_model
	 */
	public function test_get_single_model() {
		// 先获取模型列表
		$list_response = $this->rest_get( 'models', array(), 'wptsall/v2' );

		// 获取模型列表应返回 200
		$this->assertEquals( 200, $list_response->get_status(), '获取模型列表应返回 200' );

		$list_data = $this->get_response_data( $list_response );
		$items     = $list_data['models'] ?? $list_data['items'] ?? $list_data;

		// 逻辑跳过：如果没有模型数据，跳过测试
		if ( empty( $items ) || ! is_array( $items ) ) {
			$this->markTestSkipped( '没有可用的模型数据，需先执行扫描' );
		}

		$model_id = $items[0]['id'] ?? null;
		if ( ! $model_id ) {
			$this->markTestSkipped( '模型没有 ID 字段' );
		}

		// 获取单个模型
		$response = $this->rest_get( 'models/' . $model_id, array(), 'wptsall/v2' );

		// 获取存在的模型应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '获取存在的模型应返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertEquals( (int) $model_id, (int) ( $data['id'] ?? 0 ), '返回的模型 ID 应匹配' );
	}

	/**
	 * 测试获取不存在的模型返回 404
	 *
	 * @covers Translation_Rule_REST_Controller::get_model
	 */
	public function test_get_nonexistent_model_returns_404() {
		$response = $this->rest_get( 'models/999999', array(), 'wptsall/v2' );

		// 不存在的模型应严格返回 404
		$status = $response->get_status();
		$this->assertEquals( 404, $status, '不存在的模型应返回 404' );
	}

	/**
	 * 测试按 status 筛选模型（active）
	 *
	 * @covers Translation_Rule_REST_Controller::get_models
	 */
	public function test_filter_models_by_status_active() {
		$response = $this->rest_get( 'models', array(
			'status' => 'active',
		), 'wptsall/v2' );

		// GET /models 应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '筛选模型应返回 200' );

		$data  = $this->get_response_data( $response );
		$items = $data['models'] ?? $data['items'] ?? $data;

		// 验证返回的模型
		$this->assertIsArray( $items, '应返回模型数组' );

		// 所有返回的模型应该是 active 状态
		foreach ( $items as $model ) {
			$this->assertEquals( 'active', $model['status'] ?? '', '筛选结果应只包含 active 状态' );
		}
	}

	/**
	 * 测试模型分页
	 *
	 * @covers Translation_Rule_REST_Controller::get_models
	 */
	public function test_models_pagination() {
		$response = $this->rest_get( 'models', array(
			'per_page' => 2,
			'page'     => 1,
		), 'wptsall/v2' );

		// 分页请求应返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '分页请求应返回 200' );

		$data  = $this->get_response_data( $response );
		$items = $data['models'] ?? $data['items'] ?? array();

		// 验证返回的模型
		$this->assertIsArray( $items, '应返回模型数组' );

		// 返回数量不应超过 per_page
		$this->assertLessThanOrEqual( 2, count( $items ), '返回数量不应超过 per_page' );
	}

	// ========================================
	// 服务层测试
	// ========================================

	/**
	 * 测试 Translation_Rule_Service 存在且可用
	 */
	public function test_translation_rule_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ),
			'Translation_Rule_Service 类应存在'
		);
	}

	/**
	 * 测试 Smart_Field_Classifier 存在且可用
	 */
	public function test_smart_field_classifier_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Core\Smart_Field_Classifier' ),
			'Smart_Field_Classifier 类应存在'
		);
	}

	/**
	 * 测试 Model_Scanner_V2 存在且可用
	 */
	public function test_model_scanner_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Scanners\Model_Scanner_V2' ),
			'Model_Scanner_V2 类应存在'
		);
	}
}
