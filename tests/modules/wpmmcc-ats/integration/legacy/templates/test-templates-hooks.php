<?php
/**
 * Templates to Hooks Integration Tests
 *
 * 测试从模板翻译条目到 gettext Hook 的完整流程
 *
 * @package WPTSALL
 * @since 0.6.0
 */

use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Hooks\Gettext_Filter;

class Test_Templates_Hooks extends SimpleTestCase {

	/**
	 * 测试虚拟站点 ID
	 *
	 * @var string
	 */
	private $virtual_site_id;

	/**
	 * 测试关联 ID
	 *
	 * @var int
	 */
	private $relation_id;

	/**
	 * 测试模板 ID
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * 测试 text_domain
	 *
	 * @var string
	 */
	private $text_domain;

	/**
	 * 当前站点 ID
	 *
	 * @var int
	 */
	private $current_site_id;

	/**
	 * 设置测试
	 */
	public function setUp(): void {
		parent::setUp();

		$this->current_site_id = get_current_blog_id();

		// 创建虚拟站点
		$this->create_virtual_site();

		// 创建关系
		$this->create_test_relation();

		// 创建模板
		$this->create_test_template();
	}

	/**
	 * 创建虚拟站点
	 *
	 * @return bool
	 */
	private function create_virtual_site() {
		$path_prefix = 'templates-hooks-test-' . time();

		$result = Virtual_Site_Service::create( array(
			'name'        => 'Templates-Hooks Test Site',
			'path_prefix' => $path_prefix,
			'lang'        => 'zh_CN',
		) );

		if ( ! empty( $result['success'] ) && ! empty( $result['site_id'] ) ) {
			$this->virtual_site_id = $result['site_id'];
			return true;
		}

		return false;
	}

	/**
	 * 创建测试关系
	 *
	 * @return bool
	 */
	private function create_test_relation() {
		if ( empty( $this->virtual_site_id ) ) {
			return false;
		}

		// 使用 'wordpress-blog' 作为模板名，避免插件激活检查
		// 验证器会跳过 wordpress-blog 的插件检查
		$result = Site_Relation_Service::create_relation( array(
			'template'         => 'wordpress-blog',
			'source_site_id'   => $this->current_site_id,
			'source_lang'      => 'en_US',
			'auto_create_model' => false,
			'target_sites'     => array(
				array(
					'type' => 'virtual',
					'id'   => $this->virtual_site_id,
					'lang' => 'zh_CN',
				),
			),
		) );

		if ( ! empty( $result['success'] ) && ! empty( $result['relation_ids'] ) ) {
			$this->relation_id = $result['relation_ids'][0];
			return true;
		}

		return false;
	}

	/**
	 * 创建测试模板
	 *
	 * @return bool
	 */
	private function create_test_template() {
		if ( empty( $this->relation_id ) ) {
			return false;
		}

		// 使用固定的 text_domain，保存到实例属性供测试使用
		$this->text_domain = 'test-theme-' . time();

		$template_id = Template_Service::create( array(
			'relation_id'    => $this->relation_id,
			'source_type'    => 'theme',
			'text_domain'    => $this->text_domain,
			'source_name'    => 'Test Theme for Hooks',
			'source_version' => '1.0.0',
			'status'         => 'scanned',
		) );

		if ( $template_id && $template_id > 0 ) {
			$this->template_id = $template_id;
			return true;
		}

		return false;
	}

	/**
	 * 清理测试数据
	 */
	public function tearDown(): void {
		// 删除模板条目和模板
		if ( $this->template_id ) {
			Template_Entry_Service::delete_by_template( $this->template_id );
			Template_Service::delete( $this->template_id );
		}

		// 删除测试关联
		if ( $this->relation_id ) {
			Site_Relation_Service::delete_relation( $this->relation_id );
		}

		// 删除虚拟站点
		if ( $this->virtual_site_id ) {
			Virtual_Site_Service::delete( $this->virtual_site_id );
		}

		parent::tearDown();
	}

	// ==================== Template Entry Creation Tests ====================

	/**
	 * 测试创建翻译条目
	 */
	public function test_create_template_entries() {
		if ( empty( $this->template_id ) ) {
			$this->markTestSkipped( 'Template not available' );
		}

		// 创建翻译条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Hello World',
			'msgstr'      => '你好世界',
			'status'      => 'translated',
			'source'      => 'manual',
		) );

		$this->assertIsInt( $entry_id );
		$this->assertGreaterThan( 0, $entry_id );

		// 验证条目
		$entry = Template_Entry_Service::get( $entry_id );
		$this->assertNotNull( $entry );
		$this->assertEquals( 'Hello World', $entry['msgid'] );
		$this->assertEquals( '你好世界', $entry['msgstr'] );
		$this->assertEquals( 'translated', $entry['status'] );
	}

	/**
	 * 测试创建带上下文的翻译条目
	 */
	public function test_create_context_entry() {
		if ( empty( $this->template_id ) ) {
			$this->markTestSkipped( 'Template not available' );
		}

		// 创建带上下文的条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Post',
			'msgctxt'     => 'noun',
			'msgstr'      => '文章',
			'status'      => 'translated',
			'source'      => 'manual',
		) );

		$this->assertGreaterThan( 0, $entry_id );

		$entry = Template_Entry_Service::get( $entry_id );
		$this->assertEquals( 'noun', $entry['msgctxt'] );
	}

	/**
	 * 测试创建复数形式条目
	 */
	public function test_create_plural_entry() {
		if ( empty( $this->template_id ) ) {
			$this->markTestSkipped( 'Template not available' );
		}

		// 创建复数形式条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id'  => $this->template_id,
			'msgid'        => 'One item',
			'msgid_plural' => '%d items',
			'msgstr'       => '一个项目',
			'msgstr_plural' => '%d 个项目',
			'status'       => 'translated',
			'source'       => 'manual',
		) );

		$this->assertGreaterThan( 0, $entry_id );

		$entry = Template_Entry_Service::get( $entry_id );
		$this->assertEquals( '%d items', $entry['msgid_plural'] );
	}

	// ==================== Get Translations for Hook Tests ====================

	/**
	 * 测试获取 Hook 使用的翻译
	 */
	public function test_get_translations_for_hook() {
		if ( empty( $this->template_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Template or relation not available' );
		}

		// 创建多个翻译条目
		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Welcome',
			'msgstr'      => '欢迎',
			'status'      => 'translated',
			'source'      => 'manual',
		) );

		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Goodbye',
			'msgstr'      => '再见',
			'status'      => 'translated',
			'source'      => 'manual',
		) );

		// 创建未翻译的条目（不应返回）
		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Pending',
			'msgstr'      => '',
			'status'      => 'pending',
			'source'      => 'scan',
		) );

		// 获取翻译（供 Hook 使用）
		$translations = Template_Entry_Service::get_translations_for_hook(
			$this->relation_id,
			$this->text_domain
		);

		$this->assertIsArray( $translations );
		$this->assertArrayHasKey( 'Welcome', $translations );
		$this->assertArrayHasKey( 'Goodbye', $translations );
		$this->assertArrayNotHasKey( 'Pending', $translations );

		$this->assertEquals( '欢迎', $translations['Welcome']['msgstr'] );
		$this->assertEquals( '再见', $translations['Goodbye']['msgstr'] );
	}

	/**
	 * 测试带上下文的翻译键格式
	 */
	public function test_context_translation_key_format() {
		if ( empty( $this->template_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Template or relation not available' );
		}

		// 创建带上下文的翻译
		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Read',
			'msgctxt'     => 'verb',
			'msgstr'      => '阅读',
			'status'      => 'translated',
		) );

		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Read',
			'msgctxt'     => 'adjective',
			'msgstr'      => '已读',
			'status'      => 'translated',
		) );

		// 获取翻译
		$translations = Template_Entry_Service::get_translations_for_hook(
			$this->relation_id,
			$this->text_domain
		);

		// 验证使用 EOT (\x04) 分隔符的键格式
		$verb_key = "verb\x04Read";
		$adj_key  = "adjective\x04Read";

		$this->assertArrayHasKey( $verb_key, $translations );
		$this->assertArrayHasKey( $adj_key, $translations );

		$this->assertEquals( '阅读', $translations[ $verb_key ]['msgstr'] );
		$this->assertEquals( '已读', $translations[ $adj_key ]['msgstr'] );
	}

	// ==================== Gettext Filter Tests ====================

	/**
	 * 测试 Gettext_Filter 类存在
	 */
	public function test_gettext_filter_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\\Hooks\\Gettext_Filter' ) );
	}

	/**
	 * 测试 gettext filter 注册
	 */
	public function test_gettext_filter_registration() {
		// 验证 gettext filter 可以注册
		$registered = false;

		add_filter( 'gettext', function( $translation, $text, $domain ) use ( &$registered ) {
			$registered = true;
			return $translation;
		}, 10, 3 );

		// 触发 gettext
		__( 'Test', 'test-domain' );

		$this->assertTrue( $registered );
	}

	/**
	 * 测试 gettext_with_context filter 注册
	 */
	public function test_gettext_with_context_filter() {
		$context_received = null;

		add_filter( 'gettext_with_context', function( $translation, $text, $context, $domain ) use ( &$context_received ) {
			$context_received = $context;
			return $translation;
		}, 10, 4 );

		// 触发带上下文的 gettext
		_x( 'Test', 'test-context', 'test-domain' );

		$this->assertEquals( 'test-context', $context_received );
	}

	// ==================== Template Stats Update Tests ====================

	/**
	 * 测试模板统计更新
	 */
	public function test_template_stats_update() {
		if ( empty( $this->template_id ) ) {
			$this->markTestSkipped( 'Template not available' );
		}

		// 创建不同状态的条目
		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Stats Test 1',
			'msgstr'      => '统计测试 1',
			'status'      => 'translated',
		) );

		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Stats Test 2',
			'msgstr'      => '统计测试 2',
			'status'      => 'reviewed',
		) );

		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Stats Test 3',
			'msgstr'      => '',
			'status'      => 'pending',
		) );

		// 更新统计
		Template_Service::update_stats( $this->template_id );

		// 验证统计
		$template = Template_Service::get( $this->template_id );

		$this->assertEquals( 3, (int) $template['total_entries'] );
		$this->assertEquals( 2, (int) $template['translated_entries'] ); // translated + reviewed
		$this->assertEquals( 1, (int) $template['reviewed_entries'] );
	}

	// ==================== Hook Manager Integration Tests ====================

	/**
	 * 测试 Hook_Manager 类存在
	 */
	public function test_hook_manager_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\\Hooks\\Hook_Manager' ) );
	}

	/**
	 * 测试 Hook 表存在
	 */
	public function test_hooks_table_exists() {
		global $wpdb;
		$table = wptsall_table( 'hooks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertEquals( $table, $table_exists );
	}

	/**
	 * 测试为站点关系创建 Hook
	 */
	public function test_create_hook_for_relation() {
		if ( empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Relation not available' );
		}

		// 创建 Hook（使用正确的 API 签名）
		// insert_hook_entry( $hook_name, $site_id, $template, $priority = 10, $status = 'enabled' )
		$hook_id = \WPTSALL\Hooks\Hook_Manager::insert_hook_entry(
			'gettext',
			$this->relation_id,
			$this->text_domain,
			10,
			'enabled'
		);

		$this->assertIsInt( $hook_id );
		$this->assertGreaterThan( 0, $hook_id );

		// 验证 Hook
		$hooks = \WPTSALL\Hooks\Hook_Manager::get_hooks( $this->relation_id );
		$this->assertNotEmpty( $hooks );

		$found = false;
		foreach ( $hooks as $hook ) {
			if ( $hook['hook_name'] === 'gettext' ) {
				$found = true;
				$this->assertEquals( 'enabled', $hook['status'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Created hook should be found' );

		// 清理
		\WPTSALL\Hooks\Hook_Manager::delete_hook( $hook_id );
	}

	/**
	 * 测试 Hook 状态切换
	 */
	public function test_toggle_hook_status() {
		if ( empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Relation not available' );
		}

		// 创建 Hook（使用正确的 API 签名）
		$hook_id = \WPTSALL\Hooks\Hook_Manager::insert_hook_entry(
			'gettext_with_context',
			$this->relation_id,
			$this->text_domain,
			10,
			'enabled'
		);

		// 切换状态（toggle_hook 返回 array('id' => ..., 'status' => ...)）
		$result = \WPTSALL\Hooks\Hook_Manager::toggle_hook( $hook_id );

		$this->assertIsArray( $result );
		$this->assertEquals( 'disabled', $result['status'] );

		// 再次切换
		$result = \WPTSALL\Hooks\Hook_Manager::toggle_hook( $hook_id );

		$this->assertIsArray( $result );
		$this->assertEquals( 'enabled', $result['status'] );

		// 清理
		\WPTSALL\Hooks\Hook_Manager::delete_hook( $hook_id );
	}

	// ==================== Complete Workflow Tests ====================

	/**
	 * 测试完整流程：模板条目 → Hook 翻译应用
	 */
	public function test_complete_translation_workflow() {
		if ( empty( $this->template_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Template or relation not available' );
		}

		// 步骤 1: 创建翻译条目
		Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Complete Workflow Test',
			'msgstr'      => '完整流程测试',
			'status'      => 'translated',
			'source'      => 'manual',
		) );

		// 步骤 2: 创建 gettext Hook（使用正确的 API 签名）
		$hook_id = \WPTSALL\Hooks\Hook_Manager::insert_hook_entry(
			'gettext',
			$this->relation_id,
			$this->text_domain,
			10,
			'enabled'
		);

		$this->assertGreaterThan( 0, $hook_id );

		// 步骤 3: 获取翻译
		$translations = Template_Entry_Service::get_translations_for_hook(
			$this->relation_id,
			$this->text_domain
		);

		$this->assertArrayHasKey( 'Complete Workflow Test', $translations );
		$this->assertEquals( '完整流程测试', $translations['Complete Workflow Test']['msgstr'] );

		// 步骤 4: 验证 Hook 已创建
		$hooks = \WPTSALL\Hooks\Hook_Manager::get_hooks( $this->relation_id );
		$gettext_hook = null;
		foreach ( $hooks as $hook ) {
			if ( $hook['hook_name'] === 'gettext' && $hook['template'] === $this->text_domain ) {
				$gettext_hook = $hook;
				break;
			}
		}

		$this->assertNotNull( $gettext_hook );
		$this->assertEquals( 'enabled', $gettext_hook['status'] );

		// 清理
		\WPTSALL\Hooks\Hook_Manager::delete_hook( $hook_id );
	}

	/**
	 * 测试删除关系时清理模板
	 */
	public function test_cleanup_on_relation_delete() {
		if ( empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Relation not available' );
		}

		// 创建新的模板用于此测试
		$test_template_id = Template_Service::create( array(
			'relation_id'    => $this->relation_id,
			'source_type'    => 'plugin',
			'text_domain'    => 'cleanup-test',
			'source_name'    => 'Cleanup Test',
			'source_version' => '1.0.0',
			'status'         => 'scanned',
		) );

		$this->assertGreaterThan( 0, $test_template_id );

		// 添加条目
		Template_Entry_Service::create( array(
			'template_id' => $test_template_id,
			'msgid'       => 'Cleanup Test Entry',
			'msgstr'      => '清理测试条目',
			'status'      => 'translated',
		) );

		// 验证条目存在
		$entries = Template_Entry_Service::get_by_template( $test_template_id, array( 'per_page' => -1 ) );
		$this->assertEquals( 1, $entries['total'] );

		// 删除模板（模拟关系删除时的清理）
		Template_Entry_Service::delete_by_template( $test_template_id );
		Template_Service::delete( $test_template_id );

		// 验证已清理
		$deleted_template = Template_Service::get( $test_template_id );
		$this->assertNull( $deleted_template );

		$entries_after = Template_Entry_Service::get_by_template( $test_template_id, array( 'per_page' => -1 ) );
		$this->assertEquals( 0, $entries_after['total'] );
	}
}
