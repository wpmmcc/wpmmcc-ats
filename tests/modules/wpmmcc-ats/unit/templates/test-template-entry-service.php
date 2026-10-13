<?php
/**
 * Template_Entry_Service 单元测试
 *
 * @package WPTSALL\Tests
 * @since 0.5.0
 */

use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

/**
 * Test_Template_Entry_Service 测试类
 */
class Test_Template_Entry_Service extends SimpleTestCase {

	/**
	 * 测试用站点关系 ID
	 *
	 * @var int
	 */
	private $test_relation_id = 0;

	/**
	 * 测试用模板 ID
	 *
	 * @var int
	 */
	private $test_template_id = 0;

	/**
	 * 测试用条目 ID 列表
	 *
	 * @var array
	 */
	private $test_entry_ids = array();

	/**
	 * 设置测试环境
	 */
	public function setUp(): void {
		parent::setUp();

		// 确保表存在
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
				'template'         => 'test_entry_service_' . time(),
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

		// 创建测试用模板
		$this->test_template_id = Template_Service::create( array(
			'relation_id'  => $this->test_relation_id,
			'source_type'  => 'plugin',
			'text_domain'  => 'test-entry-plugin',
			'source_name'  => 'Test Entry Plugin',
		) );
	}

	/**
	 * 清理测试数据
	 */
	public function tearDown(): void {
		// 删除测试条目
		foreach ( $this->test_entry_ids as $id ) {
			Template_Entry_Service::delete( $id );
		}

		// 删除测试模板
		if ( $this->test_template_id ) {
			Template_Service::delete( $this->test_template_id );
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
	 * 测试创建条目
	 */
	public function test_create_entry() {
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Hello World',
			'msgstr'      => '你好，世界',
			'status'      => 'translated',
			'source'      => 'manual',
		) );

		$this->test_entry_ids[] = $entry_id;

		$this->assertNotEquals( false, $entry_id, '条目应创建成功' );
		$this->assertGreaterThan( 0, $entry_id, '条目 ID 应大于 0' );
	}

	/**
	 * 测试获取条目
	 */
	public function test_get_entry() {
		// 创建条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Test String',
			'msgctxt'     => 'context',
			'msgstr'      => '测试字符串',
			'status'      => 'translated',
			'reference'   => 'file.php:10',
		) );
		$this->test_entry_ids[] = $entry_id;

		// 获取条目
		$entry = Template_Entry_Service::get( $entry_id );

		$this->assertNotNull( $entry, '条目应存在' );
		$this->assertIsArray( $entry, '返回值应为数组' );
		$this->assertEquals( 'Test String', $entry['msgid'], 'msgid 应匹配' );
		$this->assertEquals( 'context', $entry['msgctxt'], 'msgctxt 应匹配' );
		$this->assertEquals( '测试字符串', $entry['msgstr'], 'msgstr 应匹配' );
	}

	/**
	 * 测试按模板获取条目
	 */
	public function test_get_by_template() {
		// 创建多个条目
		for ( $i = 1; $i <= 3; $i++ ) {
			$id = Template_Entry_Service::create( array(
				'template_id' => $this->test_template_id,
				'msgid'       => "String {$i}",
				'status'      => 'pending',
			) );
			$this->test_entry_ids[] = $id;
		}

		// 获取
		$result = Template_Entry_Service::get_by_template( $this->test_template_id );

		$this->assertIsArray( $result, '返回值应为数组' );
		$this->assertArrayHasKey( 'items', $result, '结果应包含 items' );
		$this->assertArrayHasKey( 'total', $result, '结果应包含 total' );
		$this->assertGreaterThanOrEqual( 3, $result['total'], '应至少有 3 个条目' );
	}

	/**
	 * 测试按状态筛选条目
	 */
	public function test_get_by_template_filter_status() {
		// 创建不同状态的条目
		$id1 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Pending string',
			'status'      => 'pending',
		) );
		$this->test_entry_ids[] = $id1;

		$id2 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Translated string',
			'msgstr'      => '已翻译',
			'status'      => 'translated',
		) );
		$this->test_entry_ids[] = $id2;

		// 筛选 translated 状态
		$result = Template_Entry_Service::get_by_template( $this->test_template_id, array(
			'status' => 'translated',
		) );

		foreach ( $result['items'] as $item ) {
			$this->assertEquals( 'translated', $item['status'], '所有结果应为 translated 状态' );
		}
	}

	/**
	 * 测试搜索条目
	 */
	public function test_get_by_template_search() {
		// 创建条目
		$id1 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Apple is a fruit',
			'msgstr'      => '苹果是一种水果',
		) );
		$this->test_entry_ids[] = $id1;

		$id2 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Orange is a fruit',
			'msgstr'      => '橙子是一种水果',
		) );
		$this->test_entry_ids[] = $id2;

		// 搜索 Apple
		$result = Template_Entry_Service::get_by_template( $this->test_template_id, array(
			'search' => 'Apple',
		) );

		$this->assertGreaterThanOrEqual( 1, $result['total'], '应找到至少 1 个包含 Apple 的条目' );
	}

	/**
	 * 测试更新条目
	 */
	public function test_update_entry() {
		// 创建条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Update test',
			'status'      => 'pending',
		) );
		$this->test_entry_ids[] = $entry_id;

		// 更新
		$result = Template_Entry_Service::update( $entry_id, array(
			'msgstr' => '更新测试',
			'status' => 'translated',
			'note'   => '已手动翻译',
		) );

		$this->assertTrue( $result, '更新应成功' );

		// 验证
		$entry = Template_Entry_Service::get( $entry_id );
		$this->assertEquals( '更新测试', $entry['msgstr'], 'msgstr 应已更新' );
		$this->assertEquals( 'translated', $entry['status'], '状态应已更新' );
		$this->assertEquals( '已手动翻译', $entry['note'], '备注应已更新' );
	}

	/**
	 * 测试批量更新条目
	 */
	public function test_bulk_update() {
		// 创建多个条目
		$ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$id = Template_Entry_Service::create( array(
				'template_id' => $this->test_template_id,
				'msgid'       => "Bulk update {$i}",
				'status'      => 'pending',
			) );
			$ids[] = $id;
			$this->test_entry_ids[] = $id;
		}

		// 批量更新
		$count = Template_Entry_Service::bulk_update( $ids, array(
			'status' => 'reviewed',
		) );

		$this->assertEquals( 3, $count, '应更新 3 个条目' );

		// 验证
		foreach ( $ids as $id ) {
			$entry = Template_Entry_Service::get( $id );
			$this->assertEquals( 'reviewed', $entry['status'], '状态应已更新为 reviewed' );
		}
	}

	/**
	 * 测试删除条目
	 */
	public function test_delete_entry() {
		// 创建条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Delete test',
		) );

		// 删除
		$result = Template_Entry_Service::delete( $entry_id );
		$this->assertTrue( $result, '删除应成功' );

		// 验证
		$entry = Template_Entry_Service::get( $entry_id );
		$this->assertNull( $entry, '已删除的条目应不存在' );
	}

	/**
	 * 测试按 msgid 查找条目
	 */
	public function test_find_by_msgid() {
		// 创建条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Unique message id',
			'msgctxt'     => 'unique-context',
		) );
		$this->test_entry_ids[] = $entry_id;

		// 查找
		$found = Template_Entry_Service::find_by_msgid(
			$this->test_template_id,
			'Unique message id',
			'unique-context'
		);

		$this->assertNotNull( $found, '应找到条目' );
		$this->assertEquals( (int) $entry_id, (int) $found['id'], 'ID 应匹配' );
	}

	/**
	 * 测试获取或创建条目
	 */
	public function test_get_or_create() {
		$msgid = 'Get or create test ' . time();

		// 第一次调用应创建
		$id1 = Template_Entry_Service::get_or_create( $this->test_template_id, array(
			'msgid'  => $msgid,
			'status' => 'pending',
		) );
		$this->test_entry_ids[] = $id1;

		$this->assertGreaterThan( 0, $id1, '应创建新条目' );

		// 第二次调用应返回已存在的
		$id2 = Template_Entry_Service::get_or_create( $this->test_template_id, array(
			'msgid'  => $msgid,
			'status' => 'translated',
		) );

		$this->assertEquals( $id1, $id2, '应返回已存在的条目 ID' );
	}

	/**
	 * 测试批量创建条目
	 */
	public function test_bulk_create() {
		$entries = array(
			array(
				'template_id' => $this->test_template_id,
				'msgid'       => 'Bulk create 1',
			),
			array(
				'template_id' => $this->test_template_id,
				'msgid'       => 'Bulk create 2',
			),
			array(
				'template_id' => $this->test_template_id,
				'msgid'       => 'Bulk create 3',
			),
		);

		$result = Template_Entry_Service::bulk_create( $entries );

		$this->assertArrayHasKey( 'success', $result, '结果应包含 success' );
		$this->assertArrayHasKey( 'failed', $result, '结果应包含 failed' );
		$this->assertEquals( 3, $result['success'], '应成功创建 3 个条目' );
		$this->assertEquals( 0, $result['failed'], '应无失败' );

		// 清理（获取刚创建的条目并删除）
		$created = Template_Entry_Service::get_by_template( $this->test_template_id, array(
			'search'   => 'Bulk create',
			'per_page' => -1,
		) );
		foreach ( $created['items'] as $item ) {
			$this->test_entry_ids[] = $item['id'];
		}
	}

	/**
	 * 测试获取待翻译条目 ID
	 */
	public function test_get_pending_ids() {
		// 创建不同状态的条目
		$id1 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Pending 1',
			'status'      => 'pending',
		) );
		$this->test_entry_ids[] = $id1;

		$id2 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Pending 2',
			'status'      => 'pending',
		) );
		$this->test_entry_ids[] = $id2;

		$id3 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Translated',
			'status'      => 'translated',
		) );
		$this->test_entry_ids[] = $id3;

		// 获取待翻译 ID
		$pending_ids = Template_Entry_Service::get_pending_ids( $this->test_template_id );

		$this->assertIsArray( $pending_ids, '返回值应为数组' );
		$this->assertContains( (int) $id1, $pending_ids, '应包含 pending 状态的条目' );
		$this->assertContains( (int) $id2, $pending_ids, '应包含 pending 状态的条目' );
	}

	/**
	 * 测试按状态获取条目
	 */
	public function test_get_by_status() {
		// 创建条目
		$id1 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Reviewed 1',
			'status'      => 'reviewed',
		) );
		$this->test_entry_ids[] = $id1;

		$id2 = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Reviewed 2',
			'status'      => 'reviewed',
		) );
		$this->test_entry_ids[] = $id2;

		// 按状态获取
		$entries = Template_Entry_Service::get_by_status( $this->test_template_id, 'reviewed' );

		$this->assertIsArray( $entries, '返回值应为数组' );
		$this->assertGreaterThanOrEqual( 2, count( $entries ), '应返回至少 2 个 reviewed 状态的条目' );
	}

	/**
	 * 测试删除模板的所有条目
	 */
	public function test_delete_by_template() {
		// 创建多个条目
		for ( $i = 1; $i <= 3; $i++ ) {
			Template_Entry_Service::create( array(
				'template_id' => $this->test_template_id,
				'msgid'       => "Delete all {$i}",
			) );
		}

		// 删除所有条目
		$deleted = Template_Entry_Service::delete_by_template( $this->test_template_id );

		$this->assertGreaterThanOrEqual( 3, $deleted, '应删除至少 3 个条目' );

		// 验证
		$remaining = Template_Entry_Service::get_by_template( $this->test_template_id );
		$this->assertEquals( 0, $remaining['total'], '应无剩余条目' );
	}

	/**
	 * 测试复数形式条目
	 */
	public function test_plural_entry() {
		// 创建复数形式条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id'   => $this->test_template_id,
			'msgid'         => '%d item',
			'msgid_plural'  => '%d items',
			'msgstr'        => '%d 个项目',
			'msgstr_plural' => '%d 个项目',
			'status'        => 'translated',
		) );
		$this->test_entry_ids[] = $entry_id;

		// 获取并验证
		$entry = Template_Entry_Service::get( $entry_id );

		$this->assertEquals( '%d item', $entry['msgid'], 'msgid 应匹配' );
		$this->assertEquals( '%d items', $entry['msgid_plural'], 'msgid_plural 应匹配' );
		$this->assertEquals( '%d 个项目', $entry['msgstr'], 'msgstr 应匹配' );
		$this->assertEquals( '%d 个项目', $entry['msgstr_plural'], 'msgstr_plural 应匹配' );
	}

	/**
	 * 测试按语言获取翻译（契约方法 ISS-TPL-006）
	 *
	 * @since 0.8.0
	 */
	public function test_get_translation_by_language() {
		// 更新模板添加 target_language
		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$wpdb->update(
			$templates_table,
			array( 'target_language' => 'zh_CN' ),
			array( 'id' => $this->test_template_id ),
			array( '%s' ),
			array( '%d' )
		);

		// 创建已翻译条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Hello World',
			'msgstr'      => '你好世界',
			'status'      => 'translated',
		) );
		$this->test_entry_ids[] = $entry_id;

		// 清除缓存以获取新数据
		wp_cache_flush();

		// 使用契约方法查询
		$translation = Template_Entry_Service::get_translation(
			'Hello World',
			'test-entry-plugin',
			'zh_CN'
		);

		$this->assertEquals( '你好世界', $translation, '应返回正确的翻译' );

		// 查询不存在的翻译
		$not_found = Template_Entry_Service::get_translation(
			'Non-existent string',
			'test-entry-plugin',
			'zh_CN'
		);

		$this->assertNull( $not_found, '不存在的翻译应返回 null' );
	}

	/**
	 * 测试批量获取翻译（契约方法 ISS-TPL-006）
	 *
	 * @since 0.8.0
	 */
	public function test_get_translations_batch() {
		// 更新模板添加 target_language
		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$wpdb->update(
			$templates_table,
			array( 'target_language' => 'zh_CN' ),
			array( 'id' => $this->test_template_id ),
			array( '%s' ),
			array( '%d' )
		);

		// 创建多个已翻译条目
		$entries = array(
			array( 'msgid' => 'String A', 'msgstr' => '字符串 A' ),
			array( 'msgid' => 'String B', 'msgstr' => '字符串 B' ),
			array( 'msgid' => 'String C', 'msgstr' => '字符串 C' ),
		);

		foreach ( $entries as $entry ) {
			$id = Template_Entry_Service::create( array(
				'template_id' => $this->test_template_id,
				'msgid'       => $entry['msgid'],
				'msgstr'      => $entry['msgstr'],
				'status'      => 'translated',
			) );
			$this->test_entry_ids[] = $id;
		}

		// 清除缓存
		wp_cache_flush();

		// 批量查询
		$translations = Template_Entry_Service::get_translations_batch(
			array( 'String A', 'String B', 'String D' ), // D 不存在
			'test-entry-plugin',
			'zh_CN'
		);

		$this->assertIsArray( $translations, '应返回数组' );
		$this->assertArrayHasKey( 'String A', $translations, '应包含 String A' );
		$this->assertArrayHasKey( 'String B', $translations, '应包含 String B' );
		$this->assertArrayNotHasKey( 'String D', $translations, '不应包含不存在的 String D' );
		$this->assertEquals( '字符串 A', $translations['String A'], '翻译值应正确' );
		$this->assertEquals( '字符串 B', $translations['String B'], '翻译值应正确' );
	}

	/**
	 * 测试空数组批量查询
	 *
	 * @since 0.8.0
	 */
	public function test_get_translations_batch_empty() {
		$translations = Template_Entry_Service::get_translations_batch(
			array(),
			'any-domain',
			'zh_CN'
		);

		$this->assertIsArray( $translations, '应返回数组' );
		$this->assertEmpty( $translations, '空输入应返回空数组' );
	}

	/**
	 * 测试翻译缓存功能
	 *
	 * @since 0.8.0
	 */
	public function test_get_translation_caching() {
		// 更新模板添加 target_language
		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$wpdb->update(
			$templates_table,
			array( 'target_language' => 'zh_CN' ),
			array( 'id' => $this->test_template_id ),
			array( '%s' ),
			array( '%d' )
		);

		// 创建条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->test_template_id,
			'msgid'       => 'Cache Test',
			'msgstr'      => '缓存测试',
			'status'      => 'translated',
		) );
		$this->test_entry_ids[] = $entry_id;

		// 清除缓存
		wp_cache_flush();

		// 第一次调用（写入缓存）
		$result1 = Template_Entry_Service::get_translation(
			'Cache Test',
			'test-entry-plugin',
			'zh_CN'
		);

		// 检查缓存已写入
		$cache_key   = 'translation_' . md5( "Cache Test_test-entry-plugin_zh_CN" );
		$cache_group = 'wptsall_templates';
		$cached      = wp_cache_get( $cache_key, $cache_group );

		$this->assertEquals( '缓存测试', $result1, '第一次调用应返回正确结果' );
		$this->assertNotFalse( $cached, '缓存应已写入' );
		$this->assertEquals( '缓存测试', $cached, '缓存值应正确' );

		// 第二次调用（从缓存读取）
		$result2 = Template_Entry_Service::get_translation(
			'Cache Test',
			'test-entry-plugin',
			'zh_CN'
		);

		$this->assertEquals( $result1, $result2, '两次调用结果应相同' );
	}

	/**
	 * normalize_original_string() 去除首尾空白，返回条目存储与记忆匹配共用的规范源文本。
	 */
	public function test_normalize_original_string_trims_to_canonical_form() {
		$this->assertEquals( 'Hello World', Template_Entry_Service::normalize_original_string( '  Hello World  ' ) );
		$this->assertEquals( '', Template_Entry_Service::normalize_original_string( " \n\t " ), '纯空白输入应归一化为空串' );
		$this->assertEquals( '', Template_Entry_Service::normalize_original_string( '' ) );
		$this->assertEquals( '5', Template_Entry_Service::normalize_original_string( 5 ) );
	}
}
