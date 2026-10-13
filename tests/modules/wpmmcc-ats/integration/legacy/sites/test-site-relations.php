<?php
/**
 * Site Relations Integration Tests
 *
 * 测试站点关联的完整功能（单站点环境，使用虚拟站点）
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.5.0 适配 v0.4.0 一对一结构 API
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Sites\Validators\Site_Relation_Validator;
use WPTSALL\Templates\Services\Template_Service;

class Test_Site_Relations extends WP_UnitTestCase {

	/**
	 * 测试虚拟站点 IDs
	 *
	 * @var array
	 */
	private $virtual_site_ids = array();

	/**
	 * 测试关联 IDs (v0.4.0 一对一结构)
	 *
	 * @var array
	 */
	private $relation_ids = array();

	/**
	 * 当前站点 ID
	 *
	 * @var int
	 */
	private $current_site_id;

	/**
	 * 源站点语言
	 *
	 * @var string
	 */
	private $source_lang;

	/**
	 * 设置测试
	 */
	public function setUp(): void {
		parent::setUp();

		$this->current_site_id = get_current_blog_id();
		$this->source_lang     = get_option( 'WPLANG', 'en_US' );
		if ( empty( $this->source_lang ) ) {
			$this->source_lang = 'en_US';
		}

		// 确保 Templates 模块已初始化（用于 hook 测试）
		$this->ensure_templates_module_initialized();

		// 创建测试虚拟站点
		$this->create_virtual_sites();
	}

	/**
	 * 确保 Templates 模块已初始化
	 *
	 * 检查 Templates 模块的 hook 是否已注册，如果没有则初始化模块
	 * 这对于测试跨模块的 hook 集成非常重要
	 */
	private function ensure_templates_module_initialized() {
		// 检查 Templates 模块的 hook 是否已注册
		if ( ! has_action( 'wptsall_site_relation_deleted', array( 'WPTSALL\Templates\Module', 'on_relation_deleted' ) ) ) {
			// Hook 未注册，手动初始化 Templates 模块
			if ( class_exists( 'WPTSALL\Templates\Module' ) ) {
				\WPTSALL\Templates\Module::init();
			}
		}
	}

	/**
	 * 创建测试虚拟站点（使用 Virtual_Site_Service）
	 */
	private function create_virtual_sites() {
		// 创建 2 个测试虚拟站点
		$sites = array(
			array(
				'name'        => 'Test Virtual Site 1',
				'path_prefix' => 'test-virtual-' . time() . '-1',
				'lang'        => 'zh_CN',
			),
			array(
				'name'        => 'Test Virtual Site 2',
				'path_prefix' => 'test-virtual-' . time() . '-2',
				'lang'        => 'ja',
			),
		);

		foreach ( $sites as $site_data ) {
			$result = Virtual_Site_Service::create( $site_data );
			if ( $result['success'] ) {
				$this->virtual_site_ids[] = $result['site_id'];
			}
		}
	}

	/**
	 * 清理测试
	 */
	public function tearDown(): void {
		// 删除测试关联（v0.4.0 一对一结构）
		foreach ( $this->relation_ids as $id ) {
			Site_Relation_Service::delete_relation( $id );
		}

		// 删除测试虚拟站点
		foreach ( $this->virtual_site_ids as $id ) {
			Virtual_Site_Service::delete( $id );
		}

		parent::tearDown();
	}

	/**
	 * 辅助方法：创建测试关联
	 *
	 * @param string $template     模板标识
	 * @param array  $target_sites 目标站点数组
	 * @return array 结果
	 */
	private function create_test_relation( $template = 'wordpress-blog', $target_sites = null ) {
		if ( null === $target_sites && ! empty( $this->virtual_site_ids ) ) {
			$target_sites = array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			);
		}

		$data = array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'template'       => $template,
			'target_sites'   => $target_sites,
		);

		$result = Site_Relation_Service::create_relation( $data );

		if ( $result['success'] && ! empty( $result['relation_ids'] ) ) {
			$this->relation_ids = array_merge( $this->relation_ids, $result['relation_ids'] );
		}

		return $result;
	}

	// ==================== 基础测试 ====================

	/**
	 * 测试服务类存在
	 */
	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\\Sites\\Services\\Site_Relation_Service' ) );
	}

	/**
	 * 测试验证器类存在
	 */
	public function test_validator_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\\Sites\\Validators\\Site_Relation_Validator' ) );
	}

	/**
	 * 测试数据库表存在
	 */
	public function test_database_table_exists() {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertEquals( $table, $table_exists );
	}

	// ==================== 创建关联测试（v0.4.0 API） ====================

	/**
	 * 测试创建站点关联（v0.4.0 签名）
	 */
	public function test_create_relation() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();

		$this->assertTrue( $result['success'] ?? false, 'Create relation should succeed' );
		$this->assertArrayHasKey( 'relation_ids', $result );
		$this->assertNotEmpty( $result['relation_ids'] );

		$relation_id = $result['relation_ids'][0];
		$this->assertGreaterThan( 0, $relation_id );

		// 验证数据库记录（v0.4.0 新字段）
		$relation = Site_Relation_Service::get_relation( $relation_id );

		$this->assertNotNull( $relation );
		$this->assertEquals( 'wordpress-blog', $relation['template'] );
		$this->assertEquals( $this->current_site_id, $relation['source_site_id'] );
		$this->assertEquals( $this->source_lang, $relation['source_lang'] );
		$this->assertEquals( 'active', $relation['status'] );
		$this->assertEquals( 'virtual', $relation['target_site_type'] );
		$this->assertEquals( 'zh_CN', $relation['target_lang'] );
		$this->assertArrayHasKey( 'source_theme_name', $relation );
		$this->assertArrayHasKey( 'source_theme_path', $relation );
	}

	/**
	 * 测试创建多目标站点关联
	 */
	public function test_create_relation_multiple_targets() {
		if ( count( $this->virtual_site_ids ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 virtual sites' );
		}

		$result = Site_Relation_Service::create_relation( array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
				array(
					'id'   => $this->virtual_site_ids[1],
					'type' => 'virtual',
					'lang' => 'ja',
				),
			),
		) );

		$this->assertTrue( $result['success'] ?? false );
		$this->assertArrayHasKey( 'relation_ids', $result );
		$this->assertCount( 2, $result['relation_ids'] );

		// 记录以便清理
		$this->relation_ids = array_merge( $this->relation_ids, $result['relation_ids'] );

		// 验证每个关系都是独立记录（一对一结构）
		foreach ( $result['relation_ids'] as $id ) {
			$relation = Site_Relation_Service::get_relation( $id );
			$this->assertNotNull( $relation );
			$this->assertEquals( 'wordpress-blog', $relation['template'] );
		}
	}

	/**
	 * 测试创建关联 - 缺少必需字段
	 */
	public function test_create_relation_missing_fields() {
		$data = array(
			'template' => 'wordpress-blog',
			// 缺少 source_site_id, source_lang, target_sites
		);

		$result = Site_Relation_Service::create_relation( $data );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * 测试创建关联 - 缺少 source_lang
	 */
	public function test_create_relation_missing_source_lang() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			// 缺少 source_lang
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		);

		$result = Site_Relation_Service::create_relation( $data );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'errors', $result );
	}

	/**
	 * 测试创建关联 - 空目标站点
	 */
	public function test_create_relation_empty_targets() {
		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'target_sites'   => array(),
		);

		$result = Site_Relation_Service::create_relation( $data );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'errors', $result );
	}

	/**
	 * 测试五元组唯一约束验证
	 */
	public function test_five_tuple_unique_constraint() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		// 创建第一个关系
		$result1 = $this->create_test_relation();
		$this->assertTrue( $result1['success'] ?? false );

		// 尝试创建相同的五元组关系（应该失败）
		$result2 = Site_Relation_Service::create_relation( array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		// 五元组（source_site_id, source_lang, template, target_site_id, target_lang）唯一约束
		$this->assertFalse( $result2['success'] ?? true );
	}

	// ==================== 添加目标站点测试（v0.4.0 新签名） ====================

	/**
	 * 测试 add_target_sites 方法（v0.4.0 新签名）
	 */
	public function test_add_target_sites() {
		if ( count( $this->virtual_site_ids ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 virtual sites' );
		}

		// 创建第一个关系
		$result1 = $this->create_test_relation();
		$this->assertTrue( $result1['success'] ?? false );

		// 使用新签名添加第二个目标站点
		$result2 = Site_Relation_Service::add_target_sites(
			$this->current_site_id,    // source_id
			$this->source_lang,         // source_lang
			'wordpress-blog',           // template
			array(                      // targets
				array(
					'id'   => $this->virtual_site_ids[1],
					'type' => 'virtual',
					'lang' => 'ja',
				),
			)
		);

		$this->assertTrue( $result2['success'] ?? false );
		$this->assertArrayHasKey( 'relation_ids', $result2 );

		// 记录以便清理
		if ( ! empty( $result2['relation_ids'] ) ) {
			$this->relation_ids = array_merge( $this->relation_ids, $result2['relation_ids'] );
		}

		// 验证现在有 2 个关系记录
		$all_relations = Site_Relation_Service::get_all_relations( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
		) );

		$this->assertCount( 2, $all_relations );
	}

	// ==================== 查询方法测试 ====================

	/**
	 * 测试获取分组关系
	 */
	public function test_get_grouped_relations() {
		if ( count( $this->virtual_site_ids ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 virtual sites' );
		}

		// 创建多目标关系
		$result = Site_Relation_Service::create_relation( array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
				array(
					'id'   => $this->virtual_site_ids[1],
					'type' => 'virtual',
					'lang' => 'ja',
				),
			),
		) );

		$this->relation_ids = array_merge( $this->relation_ids, $result['relation_ids'] ?? array() );

		// 获取分组后的关系
		$grouped = Site_Relation_Service::get_grouped_relations( array(
			'template' => 'wordpress-blog',
		) );

		$this->assertIsArray( $grouped );
		$this->assertNotEmpty( $grouped );

		// 验证分组结构
		$group = $grouped[0];
		$this->assertArrayHasKey( 'source_site_id', $group );
		$this->assertArrayHasKey( 'source_lang', $group );
		$this->assertArrayHasKey( 'template', $group );
		$this->assertArrayHasKey( 'targets', $group );
		$this->assertIsArray( $group['targets'] );

		// 两个目标应该在同一个组内
		$this->assertCount( 2, $group['targets'] );
	}

	/**
	 * 测试获取源站点的所有目标
	 */
	public function test_get_targets_for_source() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		// 创建关系
		$this->create_test_relation();

		// 获取目标站点列表
		$targets = Site_Relation_Service::get_targets_for_source(
			$this->current_site_id,
			$this->source_lang,
			'wordpress-blog'
		);

		$this->assertIsArray( $targets );
		$this->assertNotEmpty( $targets );

		$target = $targets[0];
		$this->assertArrayHasKey( 'target_site_id', $target );
		$this->assertArrayHasKey( 'target_site_type', $target );
		$this->assertArrayHasKey( 'target_lang', $target );
		$this->assertEquals( 'virtual', $target['target_site_type'] );
	}

	/**
	 * 测试获取单个关联（v0.4.0 新字段）
	 */
	public function test_get_relation() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		$relation = Site_Relation_Service::get_relation( $relation_id );

		$this->assertIsArray( $relation );
		$this->assertEquals( $relation_id, $relation['id'] );

		// v0.4.0 新字段验证
		$this->assertArrayHasKey( 'source_site_id', $relation );
		$this->assertArrayHasKey( 'source_site_type', $relation );
		$this->assertArrayHasKey( 'source_lang', $relation );
		$this->assertArrayHasKey( 'source_theme_name', $relation );
		$this->assertArrayHasKey( 'source_theme_path', $relation );
		$this->assertArrayHasKey( 'target_site_id', $relation );
		$this->assertArrayHasKey( 'target_site_type', $relation );
		$this->assertArrayHasKey( 'target_lang', $relation );
		$this->assertArrayHasKey( 'target_theme_name', $relation );
		$this->assertArrayHasKey( 'target_theme_path', $relation );
		$this->assertArrayHasKey( 'plugin_status', $relation );
	}

	/**
	 * 测试获取不存在的关联
	 */
	public function test_get_nonexistent_relation() {
		$relation = Site_Relation_Service::get_relation( 999999 );
		$this->assertNull( $relation );
	}

	/**
	 * 测试获取所有关联
	 */
	public function test_get_all_relations() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$this->create_test_relation();

		$relations = Site_Relation_Service::get_all_relations();

		$this->assertIsArray( $relations );
		$this->assertNotEmpty( $relations );
	}

	/**
	 * 测试按过滤条件获取关联
	 */
	public function test_get_relations_with_filters() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$this->create_test_relation();

		// 按模板过滤
		$relations = Site_Relation_Service::get_all_relations( array(
			'template' => 'wordpress-blog',
		) );

		$this->assertIsArray( $relations );
		foreach ( $relations as $relation ) {
			$this->assertEquals( 'wordpress-blog', $relation['template'] );
		}

		// 按源站点和语言过滤
		$relations = Site_Relation_Service::get_all_relations( array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
		) );

		$this->assertIsArray( $relations );
		foreach ( $relations as $relation ) {
			$this->assertEquals( $this->current_site_id, $relation['source_site_id'] );
			$this->assertEquals( $this->source_lang, $relation['source_lang'] );
		}
	}

	// ==================== 更新与删除测试 ====================

	/**
	 * 测试更新关联状态
	 */
	public function test_update_relation_status() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		// 更新状态为 inactive
		$update_result = Site_Relation_Service::update_status( $relation_id, 'inactive' );

		$this->assertTrue( $update_result['success'] );

		// 验证更新
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertEquals( 'inactive', $relation['status'] );

		// 更新回 active
		$update_result2 = Site_Relation_Service::update_status( $relation_id, 'active' );
		$this->assertTrue( $update_result2['success'] );
	}

	/**
	 * 测试更新关联状态 - 无效状态值
	 */
	public function test_update_relation_invalid_status() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		// 尝试设置无效状态
		$update_result = Site_Relation_Service::update_status( $relation_id, 'invalid_status' );

		$this->assertFalse( $update_result['success'] );
		$this->assertArrayHasKey( 'errors', $update_result );
	}

	/**
	 * 测试删除关联（返回 relation_ids 数组）
	 */
	public function test_delete_relation() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		// 从清理列表中移除（因为我们手动删除）
		$this->relation_ids = array_diff( $this->relation_ids, array( $relation_id ) );

		// 删除关联
		$delete_result = Site_Relation_Service::delete_relation( $relation_id );

		$this->assertTrue( $delete_result['success'] );

		// 验证已删除
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertNull( $relation );
	}

	/**
	 * 测试删除不存在的关联
	 */
	public function test_delete_nonexistent_relation() {
		$delete_result = Site_Relation_Service::delete_relation( 999999 );

		$this->assertFalse( $delete_result['success'] );
		$this->assertArrayHasKey( 'errors', $delete_result );
	}

	// ==================== 主题同步测试 ====================

	/**
	 * 测试主题信息同步
	 */
	public function test_sync_theme_info() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		// 同步主题信息
		$sync_result = Site_Relation_Service::sync_theme_info( $relation_id );

		$this->assertTrue( $sync_result['success'] ?? false );
		$this->assertArrayHasKey( 'source_theme', $sync_result );
		$this->assertArrayHasKey( 'target_theme', $sync_result );

		$this->assertArrayHasKey( 'name', $sync_result['source_theme'] );
		$this->assertArrayHasKey( 'path', $sync_result['source_theme'] );

		// 验证数据库更新
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertNotEmpty( $relation['source_theme_name'] );
		$this->assertNotEmpty( $relation['source_theme_path'] );
	}

	// ==================== 统计测试 ====================

	/**
	 * 测试获取关联统计
	 */
	public function test_get_stats() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$this->create_test_relation();

		$stats = Site_Relation_Service::get_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'active', $stats );
		$this->assertArrayHasKey( 'groups', $stats );
		$this->assertArrayHasKey( 'by_template', $stats );
		$this->assertGreaterThan( 0, $stats['total'] );
	}

	/**
	 * 测试按模板统计数量
	 */
	public function test_count_by_template() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$this->create_test_relation();

		$count = Site_Relation_Service::count_by_template( 'wordpress-blog' );

		$this->assertGreaterThan( 0, $count );
	}

	// ==================== 钩子触发测试 ====================

	/**
	 * 测试删除时触发钩子
	 */
	public function test_delete_triggers_hook() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		// 从清理列表中移除
		$this->relation_ids = array_diff( $this->relation_ids, array( $relation_id ) );

		// 设置钩子监听器
		$hook_called     = false;
		$hook_relation_id = 0;

		add_action( 'wptsall_site_relation_deleted', function( $id, $relation ) use ( &$hook_called, &$hook_relation_id ) {
			$hook_called      = true;
			$hook_relation_id = $id;
		}, 10, 2 );

		// 删除关联
		Site_Relation_Service::delete_relation( $relation_id );

		$this->assertTrue( $hook_called, 'wptsall_site_relation_deleted hook should be triggered' );
		$this->assertEquals( $relation_id, $hook_relation_id );
	}

	/**
	 * 测试 Templates 模块响应删除钩子
	 *
	 * 验证当站点关系被删除时，关联的 templates 是否会自动清理
	 * Hook: wptsall_site_relation_deleted -> Templates\Module::on_relation_deleted()
	 */
	public function test_templates_cleanup_on_relation_delete() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$result = $this->create_test_relation();
		$relation_id = $result['relation_ids'][0];

		// 创建一个模板记录
		$template_id = Template_Service::create( array(
			'relation_id'   => $relation_id,
			'source_type'   => 'plugin',
			'text_domain'   => 'test-cleanup',
			'source_name'   => 'Test Plugin for Cleanup',
			'status'        => 'pending',
		) );

		$this->assertNotFalse( $template_id, 'Template should be created successfully' );

		// 验证模板创建成功
		$template_before = Template_Service::get( $template_id );
		$this->assertNotNull( $template_before, 'Template should exist before relation deletion' );

		// 从清理列表中移除（因为我们手动删除）
		$this->relation_ids = array_diff( $this->relation_ids, array( $relation_id ) );

		// 删除关联 - 这应该触发 wptsall_site_relation_deleted hook
		Site_Relation_Service::delete_relation( $relation_id );

		// 验证模板是否被自动清理（通过 hook）
		$template_after = Template_Service::get( $template_id );

		if ( $template_after ) {
			// Hook 没有工作，手动清理
			Template_Service::delete( $template_id );
			throw new Exception(
				'Templates cleanup hook did not work. Template still exists after relation deletion. ' .
				'Expected: wptsall_site_relation_deleted hook should trigger Templates\Module::on_relation_deleted()'
			);
		}

		// Hook 正常工作，模板已被清理
		$this->assertNull( $template_after, 'Template should be automatically deleted via hook' );
	}

	// ==================== 验证器测试 ====================

	/**
	 * 测试验证器 - 有效关联（v0.4.0）
	 */
	public function test_validator_valid_relation() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$validation = Site_Relation_Validator::validate_new_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		$this->assertTrue( $validation['valid'] );
		$this->assertEmpty( $validation['errors'] );
	}

	/**
	 * 测试验证器 - 空模板
	 */
	public function test_validator_empty_template() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		$validation = Site_Relation_Validator::validate_new_relation( array(
			'template'       => '',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		$this->assertFalse( $validation['valid'] );
		$this->assertNotEmpty( $validation['errors'] );
	}

	/**
	 * 测试验证器 - 空目标站点
	 */
	public function test_validator_empty_targets() {
		$validation = Site_Relation_Validator::validate_new_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'target_sites'   => array(),
		) );

		$this->assertFalse( $validation['valid'] );
		$this->assertNotEmpty( $validation['errors'] );
	}

	/**
	 * 测试验证器 - 添加目标站点（v0.4.0 新方法）
	 */
	public function test_validator_add_targets() {
		if ( count( $this->virtual_site_ids ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 virtual sites' );
		}

		// 先创建一个关系
		$this->create_test_relation();

		// 验证添加新目标
		$validation = Site_Relation_Validator::validate_add_targets(
			$this->current_site_id,
			$this->source_lang,
			'wordpress-blog',
			array(
				array(
					'id'   => $this->virtual_site_ids[1],
					'type' => 'virtual',
					'lang' => 'ja',
				),
			)
		);

		$this->assertTrue( $validation['valid'] );
	}

	/**
	 * 测试验证器 - 重复目标站点应该失败
	 */
	public function test_validator_duplicate_target() {
		if ( empty( $this->virtual_site_ids ) ) {
			$this->markTestSkipped( 'Virtual sites not available' );
		}

		// 先创建一个关系
		$this->create_test_relation();

		// 尝试添加相同的目标（应该被验证器拒绝）
		$validation = Site_Relation_Validator::validate_add_targets(
			$this->current_site_id,
			$this->source_lang,
			'wordpress-blog',
			array(
				array(
					'id'   => $this->virtual_site_ids[0],
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			)
		);

		$this->assertFalse( $validation['valid'] );
	}
}
