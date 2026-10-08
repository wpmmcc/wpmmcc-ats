<?php
/**
 * Template_Service 单元测试
 *
 * @package WPTSALL\Tests
 * @since 0.5.0
 */

use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

/**
 * Test_Template_Service 测试类
 */
class Test_Template_Service extends SimpleTestCase {

	/**
	 * 测试用站点关系 ID
	 *
	 * @var int
	 */
	private $test_relation_id = 0;

	/**
	 * 测试用模板 ID 列表
	 *
	 * @var array
	 */
	private $test_template_ids = array();

	/**
	 * 设置测试环境
	 */
	public function setUp(): void {
		parent::setUp();

		// 确保 templates 表存在
		if ( function_exists( 'wptsall_ensure_templates_tables' ) ) {
			wptsall_ensure_templates_tables();
		}

		// 直接在数据库中创建测试用站点关系（绕过复杂验证）
		global $wpdb;
		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => 1,
				'source_site_type' => 'wp',
				'source_lang'      => 'en',
				'template'         => 'test_template_service_' . time(),
				'target_site_id'   => '2',
				'target_site_type' => 'wp',
				'target_lang'      => 'zh_CN',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->test_relation_id = $wpdb->insert_id;
	}

	/**
	 * 清理测试数据
	 */
	public function tearDown(): void {
		// 删除测试创建的模板
		foreach ( $this->test_template_ids as $id ) {
			Template_Service::delete( $id );
		}

		// 直接从数据库删除测试站点关系
		if ( $this->test_relation_id ) {
			global $wpdb;
			$table = wptsall_table( 'site_relations' );
			$wpdb->delete( $table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		parent::tearDown();
	}

	/**
	 * 测试创建模板
	 */
	public function test_create_template() {
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'test-plugin',
			'source_name'  => 'Test Plugin',
			'source_version' => '1.0.0',
		) );

		$this->test_template_ids[] = $template_id;

		$this->assertNotEquals( false, $template_id, '模板应创建成功' );
		$this->assertGreaterThan( 0, $template_id, '模板 ID 应大于 0' );
	}

	/**
	 * 测试获取模板
	 */
	public function test_get_template() {
		// 创建模板
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'theme',
			'text_domain'  => 'test-theme',
			'source_name'  => 'Test Theme',
			'source_version' => '2.0.0',
		) );
		$this->test_template_ids[] = $template_id;

		// 获取模板
		$template = Template_Service::get( $template_id );

		$this->assertNotNull( $template, '模板应存在' );
		$this->assertIsArray( $template, '返回值应为数组' );
		$this->assertEquals( 'theme', $template['source_type'], '类型应匹配' );
		$this->assertEquals( 'test-theme', $template['text_domain'], '翻译域应匹配' );
		$this->assertEquals( 'Test Theme', $template['source_name'], '名称应匹配' );
	}

	/**
	 * 测试获取不存在的模板
	 */
	public function test_get_nonexistent_template() {
		$template = Template_Service::get( 999999 );

		$this->assertNull( $template, '不存在的模板应返回 null' );
	}

	/**
	 * 测试按站点关系获取模板
	 */
	public function test_get_by_relation() {
		// 创建多个模板
		$id1 = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'plugin-a',
			'source_name'  => 'Plugin A',
		) );
		$this->test_template_ids[] = $id1;

		$id2 = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'plugin-b',
			'source_name'  => 'Plugin B',
		) );
		$this->test_template_ids[] = $id2;

		// 获取
		$templates = Template_Service::get_by_relation( $this->test_relation_id );

		$this->assertIsArray( $templates, '返回值应为数组' );
		$this->assertGreaterThanOrEqual( 2, count( $templates ), '应返回至少 2 个模板' );
	}

	/**
	 * 测试更新模板
	 */
	public function test_update_template() {
		// 创建模板
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'update-test',
			'source_name'  => 'Original Name',
		) );
		$this->test_template_ids[] = $template_id;

		// 更新模板
		$result = Template_Service::update( $template_id, array(
			'source_name'    => 'Updated Name',
			'source_version' => '3.0.0',
			'status'         => 'scanned',
		) );

		$this->assertTrue( $result, '更新应成功' );

		// 验证更新
		$template = Template_Service::get( $template_id );
		$this->assertEquals( 'Updated Name', $template['source_name'], '名称应已更新' );
		$this->assertEquals( '3.0.0', $template['source_version'], '版本应已更新' );
		$this->assertEquals( 'scanned', $template['status'], '状态应已更新' );
	}

	/**
	 * 测试删除模板
	 */
	public function test_delete_template() {
		// 创建模板
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'delete-test',
		) );

		// 删除
		$result = Template_Service::delete( $template_id );
		$this->assertTrue( $result, '删除应成功' );

		// 验证删除
		$template = Template_Service::get( $template_id );
		$this->assertNull( $template, '已删除的模板应不存在' );
	}

	/**
	 * 测试按翻译域查找模板
	 */
	public function test_find_by_domain() {
		// 创建模板
		$template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'unique-domain-123',
		) );
		$this->test_template_ids[] = $template_id;

		// 查找
		$found = Template_Service::find_by_domain(
			$this->test_relation_id,
			'plugin',
			'unique-domain-123'
		);

		$this->assertNotNull( $found, '应找到模板' );
		$this->assertEquals( (int) $template_id, (int) $found['id'], 'ID 应匹配' );
	}

	/**
	 * 测试获取或创建模板
	 */
	public function test_get_or_create() {
		$domain = 'get-or-create-test-' . time();

		// 第一次调用应创建
		$id1 = Template_Service::get_or_create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'theme',
			'text_domain'  => $domain,
			'source_name'  => 'Version 1',
		) );
		$this->test_template_ids[] = $id1;

		$this->assertGreaterThan( 0, $id1, '应创建新模板' );

		// 第二次调用应返回已存在的
		$id2 = Template_Service::get_or_create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'theme',
			'text_domain'  => $domain,
			'source_name'  => 'Version 2',
		) );

		$this->assertEquals( $id1, $id2, '应返回已存在的模板 ID' );
	}

	/**
	 * 测试分页查询
	 */
	public function test_get_all_pagination() {
		// 创建多个模板
		for ( $i = 1; $i <= 5; $i++ ) {
			$id = Template_Service::create( array(
				'relation_id'  => $this->test_relation_id,
				'source_type'  => 'plugin',
				'text_domain'  => 'pagination-test-' . $i,
			) );
			$this->test_template_ids[] = $id;
		}

		// 测试分页
		$result = Template_Service::get_all( array(
			'relation_id' => $this->test_relation_id,
			'per_page'    => 2,
			'page'        => 1,
		) );

		$this->assertArrayHasKey( 'items', $result, '结果应包含 items' );
		$this->assertArrayHasKey( 'total', $result, '结果应包含 total' );
		$this->assertArrayHasKey( 'pages', $result, '结果应包含 pages' );
		$this->assertLessThanOrEqual( 2, count( $result['items'] ), '每页应不超过 2 条' );
		$this->assertGreaterThanOrEqual( 5, $result['total'], '总数应至少为 5' );
	}

	/**
	 * 测试按类型筛选
	 */
	public function test_get_all_filter_by_type() {
		// 创建不同类型的模板
		$id1 = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'filter-plugin-test',
		) );
		$this->test_template_ids[] = $id1;

		$id2 = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'theme',
			'text_domain'  => 'filter-theme-test',
		) );
		$this->test_template_ids[] = $id2;

		// 筛选 plugin 类型
		$result = Template_Service::get_all( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'plugin',
		) );

		foreach ( $result['items'] as $item ) {
			$this->assertEquals( 'plugin', $item['source_type'], '所有结果应为 plugin 类型' );
		}
	}

	/**
	 * 测试获取带关系信息的模板
	 */
	public function test_get_with_relation() {
		// 创建模板
		$template_id = Template_Service::create( array(
			'relation_id'        => $this->test_relation_id,
			'source_type'        => 'plugin',
			'text_domain'        => 'with-relation-test',
			'total_entries'      => 100,
			'translated_entries' => 50,
		) );
		$this->test_template_ids[] = $template_id;

		// 获取带关系信息
		$template = Template_Service::get_with_relation( $template_id );

		$this->assertNotNull( $template, '模板应存在' );
		$this->assertArrayHasKey( 'relation', $template, '应包含关系信息' );
		$this->assertArrayHasKey( 'progress', $template, '应包含进度信息' );
		$this->assertEquals( 50.0, $template['progress'], '进度应为 50%' );
	}
}
