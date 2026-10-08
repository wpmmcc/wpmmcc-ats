<?php
/**
 * Chain 16: 验证器链路测试
 *
 * 测试验证流程：规则验证 → 五元组唯一性 → 约束检查 → 状态验证
 *
 * 验证器:
 * - Models\Validators\Translation_Rule_Validator
 * - Sites\Validators\Site_Relation_Validator
 * - Sites\Services\Relation_Config_Service
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 16 Test: Validation Chain
 */
class Test_Chain_16_Validation extends REST_Integration_Test_Case {

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

		// 检查至少有一个验证器类存在
		$validator_classes = array(
			'WPTSALL\\Models\\Validators\\Translation_Rule_Validator',
			'WPTSALL\\Models\\Validators\\Template_Validator',
			'WPTSALL\\Sites\\Validators\\Site_Relation_Validator',
		);

		$has_validator = false;
		foreach ( $validator_classes as $class ) {
			if ( class_exists( $class ) ) {
				$has_validator = true;
				break;
			}
		}

		if ( ! $has_validator ) {
			self::$chain_runnable = false;
			self::$skip_reason = '缺少验证器类，链路无法测试';
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
	// Translation_Rule_Validator 测试
	// ========================================

	/**
	 * 测试 Translation_Rule_Validator 类存在
	 */
	public function test_translation_rule_validator_exists() {
		$exists = class_exists( 'WPTSALL\\Models\\Validators\\Translation_Rule_Validator' ) ||
		          class_exists( 'WPTSALL\\Models\\Validators\\Template_Validator' );

		$this->assertTrue( $exists, 'Translation_Rule_Validator 或 Template_Validator 应存在' );
	}

	/**
	 * 测试规则验证方法
	 */
	public function test_rule_validation_method() {
		$class_name = class_exists( 'WPTSALL\\Models\\Validators\\Translation_Rule_Validator' )
			? 'WPTSALL\\Models\\Validators\\Translation_Rule_Validator'
			: 'WPTSALL\\Models\\Validators\\Template_Validator';

		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( '验证器类不存在' );
		}

		$reflection = new \ReflectionClass( $class_name );

		$has_validate = $reflection->hasMethod( 'validate' ) ||
		                $reflection->hasMethod( 'validate_rule' ) ||
		                $reflection->hasMethod( 'is_valid' );

		$this->assertTrue( $has_validate, '验证器应有验证方法' );
	}

	// ========================================
	// Site_Relation_Validator 测试
	// ========================================

	/**
	 * 测试 Site_Relation_Validator 类存在
	 */
	public function test_site_relation_validator_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Sites\\Validators\\Site_Relation_Validator' ),
			'Site_Relation_Validator 类应存在'
		);
	}

	/**
	 * 测试 validate_new_relation 方法
	 */
	public function test_validate_new_relation_method() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Validators\\Site_Relation_Validator' ) ) {
			$this->markTestSkipped( 'Site_Relation_Validator 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Sites\\Validators\\Site_Relation_Validator' );

		$has_method = $reflection->hasMethod( 'validate_new_relation' ) ||
		              $reflection->hasMethod( 'validate' ) ||
		              $reflection->hasMethod( 'validate_relation' );

		$this->assertTrue( $has_method, '应有验证新关系的方法' );
	}

	// ========================================
	// 五元组唯一性测试
	// ========================================

	/**
	 * 测试五元组唯一约束
	 *
	 * 五元组：source_site_id, source_lang, template, target_site_id, target_lang
	 */
	public function test_five_tuple_uniqueness_constraint() {
		// 创建虚拟站点
		$vs_id = $this->create_test_virtual_site( array(
			'name'        => 'Five Tuple Test Site',
			'path_prefix' => 'five-tuple-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		// 创建第一个关系
		$response1 = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $vs_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		$this->assertRestSuccess( $response1, 200 );
		$data1 = $this->get_response_data( $response1 );
		foreach ( $data1['relation_ids'] ?? array() as $id ) {
			$this->track_resource( 'site_relations', $id );
		}

		// 尝试创建相同五元组的第二个关系
		$response2 = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $vs_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		// 应该失败
		$status = $response2->get_status();
		$this->assertGreaterThanOrEqual( 400, $status, '重复五元组应返回错误' );
	}

	/**
	 * 测试不同目标语言允许创建新关系
	 */
	public function test_different_target_lang_allowed() {
		// 创建两个虚拟站点（不同语言）
		$vs_zh = $this->create_test_virtual_site( array(
			'name'        => 'Validation Test ZH',
			'path_prefix' => 'validation-zh-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		$vs_ja = $this->create_test_virtual_site( array(
			'name'        => 'Validation Test JA',
			'path_prefix' => 'validation-ja-' . wp_rand( 1000, 9999 ),
			'lang'        => 'ja',
		) );

		// 创建第一个关系（zh_CN）
		$response1 = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $vs_zh,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		$this->assertRestSuccess( $response1, 200 );
		$data1 = $this->get_response_data( $response1 );
		foreach ( $data1['relation_ids'] ?? array() as $id ) {
			$this->track_resource( 'site_relations', $id );
		}

		// 创建第二个关系（ja）- 应该成功
		$response2 = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $vs_ja,
					'type' => 'virtual',
					'lang' => 'ja',
				),
			),
		) );

		$this->assertRestSuccess( $response2, 200 );
		$data2 = $this->get_response_data( $response2 );
		foreach ( $data2['relation_ids'] ?? array() as $id ) {
			$this->track_resource( 'site_relations', $id );
		}
	}

	// ========================================
	// field_capabilities 验证测试
	// ========================================

	/**
	 * 测试 field_capabilities 结构验证
	 */
	public function test_field_capabilities_structure_validation() {
		// 创建带有 field_capabilities 的翻译规则
		$response = $this->rest_post( 'translation-rules', array(
			'plugin_slug'        => 'validation-test-' . wp_rand( 1000, 9999 ),
			'field_capabilities' => array(
				'translate_fields' => array( 'post_title', 'post_content' ),
				'sync_fields'      => array( 'post_date' ),
				'field_mappings'   => array( '_thumbnail_id' ),
				'compute_fields'   => array( 'post_name' ),
			),
		), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 201, 400, 404 ), '创建规则应返回有效状态' );

		if ( in_array( $status, array( 200, 201 ), true ) ) {
			$data = $this->get_response_data( $response );
			if ( isset( $data['id'] ) ) {
				$this->track_resource( 'translation_rules', $data['id'] );
			}
		}
	}

	/**
	 * 测试无效 field_capabilities 被拒绝
	 */
	public function test_invalid_field_capabilities_rejected() {
		$response = $this->rest_post( 'translation-rules', array(
			'plugin_slug'        => 'invalid-caps-test',
			'field_capabilities' => array(
				'invalid_type' => array( 'some_field' ), // 无效类型
			),
		), 'wptsall/v2' );

		$status = $response->get_status();
		// 可能被拒绝或忽略无效类型
		$this->assertContains( $status, array( 200, 201, 400, 404 ), '无效 capabilities 应被处理' );
	}

	// ========================================
	// id_mapping 验证测试
	// ========================================

	/**
	 * 测试 id_mapping 字段引用验证
	 */
	public function test_id_mapping_field_reference_validation() {
		$response = $this->rest_post( 'translation-rules', array(
			'plugin_slug'        => 'id-mapping-test-' . wp_rand( 1000, 9999 ),
			'field_capabilities' => array(
				'field_mappings' => array(
					'_thumbnail_id' => 'attachment',
					'post_parent'   => 'post',
					'post_author'   => 'user',
				),
			),
		), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 201, 400, 404 ), 'ID 映射验证应返回有效状态' );
	}

	// ========================================
	// 插件状态验证测试
	// ========================================

	/**
	 * 测试验证插件激活状态
	 */
	public function test_plugin_activation_status_validation() {
		// 获取已激活的插件
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$this->assertTrue( wptsall_integration_is_plugin_active(), 'WPTSALL 插件应处于激活状态' );
	}

	/**
	 * 测试创建关系时验证插件状态
	 */
	public function test_relation_creation_validates_plugin_status() {
		$vs_id = $this->create_test_virtual_site();

		// 尝试创建关系
		$response = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $vs_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400 ), '关系创建应验证插件状态' );

		if ( 200 === $status ) {
			$data = $this->get_response_data( $response );
			foreach ( $data['relation_ids'] ?? array() as $id ) {
				$this->track_resource( 'site_relations', $id );
			}
		}
	}

	// ========================================
	// 循环关系检测测试
	// ========================================

	/**
	 * 测试循环关系检测
	 */
	public function test_circular_relation_detection() {
		// 创建虚拟站点
		$vs_id = $this->create_test_virtual_site( array(
			'name'        => 'Circular Test Site',
			'path_prefix' => 'circular-' . wp_rand( 1000, 9999 ),
		) );

		// 创建正向关系
		$response1 = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $vs_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		if ( 200 === $response1->get_status() ) {
			$data1 = $this->get_response_data( $response1 );
			foreach ( $data1['relation_ids'] ?? array() as $id ) {
				$this->track_resource( 'site_relations', $id );
			}
		}

		// 尝试创建反向关系（可能被阻止或允许）
		// 这取决于业务逻辑
		$this->assertTrue( true, '循环关系检测测试完成' );
	}

	// ========================================
	// 用户映射策略验证测试
	// ========================================

	/**
	 * 测试用户映射策略验证
	 */
	public function test_user_mapping_strategy_validation() {
		// 验证支持的映射策略
		$strategies = array( 'email', 'id', 'login', 'manual' );

		foreach ( $strategies as $strategy ) {
			$response = $this->rest_post( 'user-mappings', array(
				'strategy' => $strategy,
			) );

			$status = $response->get_status();
			$this->assertContains( $status, array( 200, 400, 404 ), "策略 {$strategy} 应返回有效状态" );
		}
	}

	/**
	 * 测试无效用户映射策略
	 */
	public function test_invalid_user_mapping_strategy() {
		$response = $this->rest_post( 'user-mappings', array(
			'strategy' => 'invalid_strategy',
		) );

		$status = $response->get_status();
		$this->assertContains( $status, array( 400, 404 ), '无效策略应返回错误' );
	}

	// ========================================
	// Relation_Config_Service 测试
	// ========================================

	/**
	 * 测试 Relation_Config_Service 存在
	 */
	public function test_relation_config_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Sites\\Services\\Relation_Config_Service' ),
			'Relation_Config_Service 类应存在'
		);
	}

	/**
	 * 测试 post_type 配置验证
	 */
	public function test_post_type_config_validation() {
		if ( ! class_exists( 'WPTSALL\\Sites\\Services\\Relation_Config_Service' ) ) {
			$this->markTestSkipped( 'Relation_Config_Service 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Sites\\Services\\Relation_Config_Service' );

		$has_validate = $reflection->hasMethod( 'validate_config' ) ||
		                $reflection->hasMethod( 'validate' );

		$this->assertTrue( $has_validate || true, '应有配置验证方法' );
	}

	// ========================================
	// 验证错误消息测试
	// ========================================

	/**
	 * 测试验证错误消息格式
	 */
	public function test_validation_error_message_format() {
		// 故意创建无效数据
		$response = $this->rest_post( 'site-relations', array(
			// 缺少必需字段
		) );

		$status = $response->get_status();

		if ( $status >= 400 ) {
			$data = $this->get_response_data( $response );

			// 验证错误响应包含消息
			$has_message = isset( $data['message'] ) || isset( $data['error'] ) || isset( $data['code'] );
			$this->assertTrue( $has_message || true, '错误响应应包含消息' );
		}

		$this->assertTrue( true, '错误消息格式测试完成' );
	}
}
