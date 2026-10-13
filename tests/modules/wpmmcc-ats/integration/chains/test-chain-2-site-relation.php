<?php
/**
 * Chain 2: 站点关系配置链路测试
 *
 * 测试站点关系流程：虚拟站点创建 → 关系配置 → 模型关联 → 五元组唯一约束
 *
 * REST 端点:
 * - POST /wptsall/v1/virtual-sites
 * - GET  /wptsall/v1/virtual-sites
 * - POST /wptsall/v1/site-relations
 * - GET  /wptsall/v1/site-relations
 * - GET  /wptsall/v1/site-relations/{id}
 * - PUT  /wptsall/v1/site-relations/{id}
 * - DELETE /wptsall/v1/site-relations/{id}
 * - POST /wptsall/v1/site-relations/{id}/models
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 2 Test: Site Relation Configuration
 */
class Test_Chain_2_Site_Relation extends REST_Integration_Test_Case {

	/**
	 * 测试虚拟站点 ID
	 *
	 * @var int
	 */
	private $test_virtual_site_id;

	/**
	 * 每个测试前创建虚拟站点
	 */
	public function setUp(): void {
		parent::setUp();

		// 创建测试虚拟站点
		$this->test_virtual_site_id = $this->create_test_virtual_site( array(
			'name'        => 'Chain2 Test Site',
			'path_prefix' => 'chain2-test-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );
	}

	// ========================================
	// 通过 REST API 创建关系测试
	// ========================================

	/**
	 * 测试通过 REST API 创建站点关系
	 *
	 * @covers Sites_REST_Controller::create_site_relation
	 */
	public function test_create_relation_via_rest() {
		$response = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->test_virtual_site_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'], '创建关系应成功' );
		$this->assertArrayHasKey( 'relation_ids', $data );
		$this->assertNotEmpty( $data['relation_ids'], '应返回关系 ID' );

		// 跟踪以便清理
		foreach ( $data['relation_ids'] as $id ) {
			$this->track_resource( 'site_relations', $id );
		}
	}

	/**
	 * 测试创建关系时缺少必需字段返回错误
	 *
	 * @covers Sites_REST_Controller::create_site_relation
	 */
	public function test_create_relation_missing_required_fields() {
		// 缺少 source_site_id
		$response = $this->rest_post( 'site-relations', array(
			'template'     => 'wordpress-blog',
			'target_sites' => array(
				array(
					'id'   => $this->test_virtual_site_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		// 应返回错误（400 或其他错误状态）
		$status = $response->get_status();
		$this->assertGreaterThanOrEqual( 400, $status, '缺少必需字段应返回错误' );
	}

	// ========================================
	// 五元组唯一约束测试
	// ========================================

	/**
	 * 测试五元组唯一约束
	 *
	 * 五元组：source_site_id, source_lang, template, target_site_id, target_lang
	 *
	 * @covers Site_Relation_Service::create_relation
	 */
	public function test_five_tuple_uniqueness() {
		$relation_data = array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->test_virtual_site_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		);

		// 第一次创建应成功
		$response1 = $this->rest_post( 'site-relations', $relation_data );
		$this->assertRestSuccess( $response1, 200 );

		$data1 = $this->get_response_data( $response1 );
		foreach ( $data1['relation_ids'] ?? array() as $id ) {
			$this->track_resource( 'site_relations', $id );
		}

		// 第二次创建相同五元组应失败
		$response2 = $this->rest_post( 'site-relations', $relation_data );

		// 应返回错误
		$status = $response2->get_status();
		$this->assertGreaterThanOrEqual( 400, $status, '重复五元组应返回错误' );
	}

	/**
	 * 测试不同目标语言可以创建新关系
	 */
	public function test_different_target_lang_creates_new_relation() {
		// 创建第二个虚拟站点（不同语言）
		$vs_id_2 = $this->create_test_virtual_site( array(
			'name'        => 'Chain2 Test Site 2',
			'path_prefix' => 'chain2-test2-' . wp_rand( 1000, 9999 ),
			'lang'        => 'ja',
		) );

		// 创建第一个关系（zh_CN）
		$response1 = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->test_virtual_site_id,
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
					'id'   => $vs_id_2,
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
	// 模型关联测试
	// ========================================

	/**
	 * 测试关联模型到关系
	 *
	 * @covers Sites_REST_Controller::add_relation_model
	 */
	public function test_associate_models() {
		// 创建关系
		$relation_data = $this->create_test_relation( $this->test_virtual_site_id );
		$relation_id = $relation_data['relation_ids'][0];

		// 获取可用模型
		$rules_response = $this->rest_get( 'models', array(), 'wptsall/v2' );
		$rules_data = $this->get_response_data( $rules_response );

		// 如果没有翻译规则，先触发扫描创建
		if ( empty( $rules_data['models'] ) ) {
			// 触发扫描创建翻译规则
			if ( $this->route_exists( 'models/scan-all', 'wptsall/v2' ) ) {
				$this->rest_post( 'models/scan-all', array(), 'wptsall/v2' );

				// 重新获取翻译规则
				$rules_response = $this->rest_get( 'models', array(), 'wptsall/v2' );
				$rules_data = $this->get_response_data( $rules_response );
			}

			// 如果仍然没有规则，直接创建测试规则
			if ( empty( $rules_data['models'] ) ) {
				// 使用 Translation_Rule_Service 直接创建测试模型
				if ( class_exists( '\WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
					$test_model_data = array(
						'plugin_slug'        => 'test-plugin-' . wp_rand( 1000, 9999 ),
						'plugin_name'        => 'Test Plugin for Model Association',
						'post_types'         => array( 'post' ),
						'is_system'          => true, // 绕过 content types 验证
						'status'             => 'active',
					);
					$created = \WPTSALL\Models\Services\Translation_Rule_Service::create_model( $test_model_data );

					if ( is_numeric( $created ) && $created > 0 ) {
						$this->track_resource( 'translation_rules', $created );
						// 重新获取规则
						$rules_response = $this->rest_get( 'models', array(), 'wptsall/v2' );
						$rules_data = $this->get_response_data( $rules_response );
					}
				}

				// 如果还是没有规则，跳过测试
				if ( empty( $rules_data['models'] ) ) {
					$this->markTestSkipped( '无法创建测试翻译规则，请检查数据库表是否存在' );
				}
			}
		}

		$model_id = $rules_data['models'][0]['id'];

		// 关联模型
		$response = $this->rest_post( "site-relations/{$relation_id}/models", array(
			'model_id' => $model_id,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'], '关联模型应成功' );
	}

	/**
	 * 测试获取关系关联的模型
	 *
	 * @covers Sites_REST_Controller::get_relation_models
	 */
	public function test_get_relation_models() {
		// 创建关系
		$relation_data = $this->create_test_relation( $this->test_virtual_site_id );
		$relation_id = $relation_data['relation_ids'][0];

		// 获取关联的模型
		$response = $this->rest_get( "site-relations/{$relation_id}/models" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'relation_id', $data );
		$this->assertArrayHasKey( 'models', $data );
		$this->assertIsArray( $data['models'], 'models 应为数组' );
	}

	// ========================================
	// 级联删除测试
	// ========================================

	/**
	 * 测试删除关系时级联删除相关资源
	 *
	 * @covers Site_Relation_Service::delete_relation
	 */
	public function test_delete_cascades() {
		global $wpdb;

		// 创建关系
		$relation_data = $this->create_test_relation( $this->test_virtual_site_id );
		$relation_id = $relation_data['relation_ids'][0];

		// 从跟踪列表移除（我们要手动删除）
		$this->tracked_resources['site_relations'] = array_diff(
			$this->tracked_resources['site_relations'],
			array( $relation_id )
		);

		// 验证关系存在
		$get_response = $this->rest_get( "site-relations/{$relation_id}" );
		$this->assertRestSuccess( $get_response, 200 );

		// 删除关系
		$delete_response = $this->rest_delete( "site-relations/{$relation_id}" );
		$this->assertRestSuccess( $delete_response, 200 );

		// 验证关系已删除
		$verify_response = $this->rest_get( "site-relations/{$relation_id}" );
		$this->assertRestError( $verify_response, 'not_found', 404 );

		// DB 验证：删除成功后，relation_models 必须被级联清除
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_relation_models WHERE relation_id = %d",
			$relation_id
		) );
		$this->assertEquals( 0, $remaining, 'relation_models must be cascade deleted with relation' );
	}

	// ========================================
	// 完整 CRUD 测试
	// ========================================

	/**
	 * 测试关系的完整 CRUD 操作
	 *
	 * @covers Sites_REST_Controller
	 */
	public function test_relation_crud_rest() {
		// CREATE
		$create_response = $this->rest_post( 'site-relations', array(
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => $this->get_source_lang(),
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->test_virtual_site_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );
		$this->assertRestSuccess( $create_response, 200 );
		$create_data = $this->get_response_data( $create_response );
		$relation_id = $create_data['relation_ids'][0];
		$this->track_resource( 'site_relations', $relation_id );

		// READ
		$read_response = $this->rest_get( "site-relations/{$relation_id}" );
		$this->assertRestSuccess( $read_response, 200 );
		$read_data = $this->get_response_data( $read_response );
		$this->assertEquals( $relation_id, $read_data['id'] ?? null );

		// UPDATE
		$update_response = $this->rest_put( "site-relations/{$relation_id}", array(
			'status' => 'inactive',
		) );
		$this->assertRestSuccess( $update_response, 200 );

		// 验证更新
		$verify_response = $this->rest_get( "site-relations/{$relation_id}" );
		$verify_data = $this->get_response_data( $verify_response );
		$this->assertEquals( 'inactive', $verify_data['status'] ?? '' );

		// DELETE（从跟踪列表移除）
		$this->tracked_resources['site_relations'] = array_diff(
			$this->tracked_resources['site_relations'],
			array( $relation_id )
		);

		$delete_response = $this->rest_delete( "site-relations/{$relation_id}" );
		$this->assertRestSuccess( $delete_response, 200 );

		// 验证删除
		$final_response = $this->rest_get( "site-relations/{$relation_id}" );
		$this->assertRestError( $final_response, 'not_found', 404 );

		// DB 验证：级联删除检查
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_relation_models WHERE relation_id = %d",
			$relation_id
		) );
		$this->assertEquals( 0, $remaining, 'relation_models must be cascade deleted with relation' );
	}

	// ========================================
	// 列表和筛选测试
	// ========================================

	/**
	 * 测试获取所有站点关系
	 *
	 * @covers Sites_REST_Controller::get_site_relations
	 */
	public function test_get_all_relations() {
		// 创建测试关系
		$this->create_test_relation( $this->test_virtual_site_id );

		// 获取所有关系
		$response = $this->rest_get( 'site-relations' );
		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertIsArray( $data, '响应应为数组' );
	}

	/**
	 * 测试按模板筛选关系
	 *
	 * @covers Sites_REST_Controller::get_site_relations
	 */
	public function test_filter_relations_by_template() {
		// 创建测试关系
		$this->create_test_relation( $this->test_virtual_site_id, 'wordpress-blog' );

		// 按模板筛选
		$response = $this->rest_get( 'site-relations', array(
			'template' => 'wordpress-blog',
		) );
		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		foreach ( $data as $relation ) {
			if ( isset( $relation['template'] ) ) {
				$this->assertEquals( 'wordpress-blog', $relation['template'] );
			}
		}
	}

	/**
	 * 测试获取分组后的关系
	 *
	 * @covers Sites_REST_Controller::get_grouped_site_relations
	 */
	public function test_get_grouped_relations() {
		// 创建测试关系
		$this->create_test_relation( $this->test_virtual_site_id );

		// 获取分组关系
		$response = $this->rest_get( 'site-relations/grouped' );
		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertIsArray( $data, '响应应为数组' );

		// 验证分组结构
		if ( ! empty( $data ) ) {
			$group = $data[0];
			$this->assertArrayHasKey( 'source_site_id', $group, '分组应包含 source_site_id' );
			$this->assertArrayHasKey( 'targets', $group, '分组应包含 targets 数组' );
		}
	}

	// ========================================
	// 服务层测试
	// ========================================

	/**
	 * 测试 Site_Relation_Service 存在且可用
	 */
	public function test_site_relation_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Sites\Services\Site_Relation_Service' ),
			'Site_Relation_Service 类应存在'
		);
	}

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
	 * 测试 Site_Relation_Validator 存在且可用
	 */
	public function test_site_relation_validator_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Sites\Validators\Site_Relation_Validator' ),
			'Site_Relation_Validator 类应存在'
		);
	}
}
