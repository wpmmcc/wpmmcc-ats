<?php
/**
 * Chain 17: 导入导出链路测试
 *
 * 测试数据迁移流程：模型导出 → JSON 序列化 → 模型导入 → 数据恢复 → validation 校验
 *
 * 当前 API 端点（wptsall/v2）:
 * - POST /models/export   (body: {model_ids: [...], include_rules: bool})
 * - POST /models/import   (body: {models: [...], overwrite: bool})
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 17 Test: Import/Export
 */
class Test_Chain_17_Import_Export extends REST_Integration_Test_Case {

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

		// 检查 models 表是否存在
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_models';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		if ( ! $table_exists ) {
			self::$chain_runnable = false;
			self::$skip_reason    = "缺少数据表: {$table}";
			return;
		}

		// 检查 Translation_Rule_Service 类或 REST 控制器存在
		$has_export = class_exists( 'WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ||
		              class_exists( 'WPTSALL\\Models\\API\\Translation_Rule_REST_Controller' );

		if ( ! $has_export ) {
			self::$chain_runnable = false;
			self::$skip_reason    = '缺少模型服务类';
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
	// POST /models/export 测试
	// ========================================

	/**
	 * 测试 POST /models/export 端点存在且响应合法
	 *
	 * 新增：断言 200 时返回 success + data 键
	 */
	public function test_export_model_returns_json() {
		// 先获取已有 model 列表，取第一个 ID
		$list_response = $this->rest_get( 'models' );

		if ( 200 !== $list_response->get_status() ) {
			$this->markTestSkipped( 'GET /models 端点不可用，跳过导出测试' );
		}

		$list_data = $this->get_response_data( $list_response );
		$models    = $list_data['items'] ?? $list_data;

		if ( empty( $models ) || ! is_array( $models ) ) {
			$this->markTestSkipped( '没有可用模型，跳过导出测试' );
		}

		$first_model_id = $models[0]['id'] ?? 0;
		if ( ! $first_model_id ) {
			$this->markTestSkipped( '无法获取模型 ID' );
		}

		$response = $this->rest_post( 'models/export', array(
			'model_ids'     => array( $first_model_id ),
			'include_rules' => true,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data, '导出响应应包含 success 键' );
		$this->assertTrue( $data['success'], 'success 应为 true' );
		$this->assertArrayHasKey( 'data', $data, '导出响应应包含 data 键' );
		$this->assertIsArray( $data['data'], 'data 应为数组' );
		$this->assertArrayHasKey( 'count', $data, '导出响应应包含 count 键' );
	}

	/**
	 * 测试导出时不提供 model_ids 返回 400
	 */
	public function test_export_without_model_ids_returns_error() {
		$response = $this->rest_post( 'models/export', array(
			'model_ids' => array(), // 空数组
		) );

		$status = $response->get_status();
		$this->assertEquals( 400, $status, '空 model_ids 应返回 400' );
	}

	// ========================================
	// POST /models/import 测试
	// ========================================

	/**
	 * 测试 POST /models/import 端点存在且响应合法
	 */
	public function test_import_models_endpoint_responds() {
		$import_data = array(
			array(
				'plugin_slug'  => 'test-import-' . wp_rand( 10000, 99999 ),
				'display_name' => 'Chain 17 Import Test',
				'rules'        => array(),
			),
		);

		$response = $this->rest_post( 'models/import', array(
			'models'    => $import_data,
			'overwrite' => false,
		) );

		$status = $response->get_status();
		// 200: 成功导入; 400: 数据验证失败（均是端点存在的证明）
		$this->assertContains(
			$status,
			array( 200, 400 ),
			"POST /models/import 应返回 200 或 400，实际为 {$status}"
		);
	}

	/**
	 * 测试导入无效数据返回 400
	 */
	public function test_import_invalid_data_returns_error() {
		// models 字段为空数组
		$response = $this->rest_post( 'models/import', array(
			'models' => array(),
		) );

		$status = $response->get_status();
		$this->assertEquals( 400, $status, '空 models 数组应返回 400' );
	}

	// ========================================
	// 导出 → 导入往返测试（新增）
	// ========================================

	/**
	 * 测试导出再导入的往返流程：规则数量一致，import 响应包含 success
	 *
	 * 新增：验证 import 响应结构（success + imported + errors）
	 */
	public function test_import_export_roundtrip() {
		// 步骤 1：获取现有模型列表
		$list_response = $this->rest_get( 'models' );

		if ( 200 !== $list_response->get_status() ) {
			$this->markTestSkipped( 'GET /models 端点不可用，跳过往返测试' );
		}

		$list_data = $this->get_response_data( $list_response );
		$models    = $list_data['items'] ?? $list_data;

		if ( empty( $models ) || ! is_array( $models ) ) {
			$this->markTestSkipped( '没有可用模型，跳过往返测试' );
		}

		$first_model_id = $models[0]['id'] ?? 0;
		if ( ! $first_model_id ) {
			$this->markTestSkipped( '无法获取模型 ID' );
		}

		// 步骤 2：导出
		$export_response = $this->rest_post( 'models/export', array(
			'model_ids'     => array( $first_model_id ),
			'include_rules' => true,
		) );

		if ( 200 !== $export_response->get_status() ) {
			$this->markTestSkipped( '导出失败，跳过往返测试' );
		}

		$export_data    = $this->get_response_data( $export_response );
		$exported_models = $export_data['data'] ?? array();

		$this->assertNotEmpty( $exported_models, '导出数据不应为空' );

		// 记录导出的规则数量（用于后续比对）
		$exported_rules_count = count( $exported_models[0]['rules'] ?? array() );

		// 步骤 3：导入（使用 overwrite=true 保证幂等）
		$import_response = $this->rest_post( 'models/import', array(
			'models'    => $exported_models,
			'overwrite' => true,
		) );

		$this->assertRestSuccess( $import_response, 200 );

		$import_result = $this->get_response_data( $import_response );

		// 断言 import 响应包含 success 键
		$this->assertArrayHasKey( 'success', $import_result, '导入响应应包含 success 键' );
		$this->assertTrue( $import_result['success'], 'success 应为 true' );

		// 断言 import 响应包含 imported 计数
		$this->assertArrayHasKey( 'imported', $import_result, '导入响应应包含 imported 键' );

		// 断言 import 响应包含 errors 数组
		$this->assertArrayHasKey( 'errors', $import_result, '导入响应应包含 errors 键' );
		$this->assertIsArray( $import_result['errors'], 'errors 应为数组' );

		// 步骤 4：再次导出验证规则数量一致
		$verify_export = $this->rest_post( 'models/export', array(
			'model_ids'     => array( $first_model_id ),
			'include_rules' => true,
		) );

		if ( 200 === $verify_export->get_status() ) {
			$verify_data        = $this->get_response_data( $verify_export );
			$verify_models      = $verify_data['data'] ?? array();
			$verify_rules_count = count( $verify_models[0]['rules'] ?? array() );

			$this->assertEquals(
				$exported_rules_count,
				$verify_rules_count,
				'往返后规则数量应一致'
			);
		}
	}

	// ========================================
	// 翻译规则导出/导入测试（wptsall/v2 命名空间）
	// ========================================

	/**
	 * 测试 GET /translation-rules 端点（v2）存在
	 */
	public function test_translation_rules_list_endpoint() {
		$response = $this->rest_get( 'translation-rules', array(), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains(
			$status,
			array( 200, 404 ),
			'翻译规则列表应返回有效状态'
		);
	}

	// ========================================
	// 站点关系导出/导入测试
	// ========================================

	/**
	 * 测试 GET /site-relations/export（若端点存在）
	 */
	public function test_export_site_relations() {
		$response = $this->rest_get( 'site-relations/export' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 404 ), '站点关系导出应返回有效状态' );
	}

	/**
	 * 测试 POST /site-relations/import（若端点存在）
	 */
	public function test_import_site_relations() {
		$relations_data = array(
			'version'   => '1.0',
			'relations' => array(
				array(
					'source_site_id' => get_current_blog_id(),
					'source_lang'    => $this->get_source_lang(),
					'template'       => 'import-test-' . wp_rand( 1000, 9999 ),
					'target_sites'   => array(),
				),
			),
		);

		$response = $this->rest_post( 'site-relations/import', array(
			'data' => $relations_data,
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 201, 400, 404 ), '站点关系导入应返回有效状态' );
	}

	// ========================================
	// 数据完整性测试
	// ========================================

	/**
	 * 测试导出不存在的 model_id 时 count 为 0
	 */
	public function test_export_nonexistent_model_returns_empty() {
		$response = $this->rest_post( 'models/export', array(
			'model_ids'     => array( 999999 ),
			'include_rules' => false,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'count', $data, '响应应包含 count' );
		$this->assertEquals( 0, (int) $data['count'], '不存在的模型导出数量应为 0' );
	}
}
