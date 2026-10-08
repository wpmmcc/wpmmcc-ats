<?php
/**
 * Templates Module Integration Tests
 *
 * 测试语言包扫描和翻译管理功能的完整工作流
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;
use WPTSALL\Templates\Scanners\POT_Parser;
use WPTSALL\Templates\Scanners\Language_Pack_Scanner;
use WPTSALL\Sites\Services\Site_Relation_Service;

class Test_Template_Workflow extends WP_UnitTestCase {

	/**
	 * 测试站点关系 ID
	 *
	 * @var int
	 */
	private $test_relation_id;

	/**
	 * 测试模板 IDs
	 *
	 * @var array
	 */
	private $template_ids = array();

	/**
	 * 测试 POT 文件路径
	 *
	 * @var string
	 */
	private $test_pot_file;

	/**
	 * 临时目录
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * 设置测试
	 */
	public function setUp(): void {
		parent::setUp();

		// 创建临时目录
		$this->temp_dir = sys_get_temp_dir() . '/wptsall-test-' . time();
		mkdir( $this->temp_dir, 0777, true );

		// 创建测试 POT 文件
		$this->create_test_pot_file();

		// 创建测试站点关系
		$this->create_test_relation();
	}

	/**
	 * 创建测试 POT 文件
	 */
	private function create_test_pot_file() {
		$this->test_pot_file = $this->temp_dir . '/test-theme.pot';

		$pot_content = <<<POT
# Test Theme Translation
# Generated for testing
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"

#: templates/header.php:10
msgid "Welcome"
msgstr ""

#: templates/header.php:15
msgctxt "greeting"
msgid "Hello"
msgstr ""

#: templates/product.php:20
msgid "Add to cart"
msgstr ""

#: templates/cart.php:30
msgid "One item"
msgid_plural "%d items"
msgstr[0] ""
msgstr[1] ""

#: templates/footer.php:5
msgid "Copyright 2024"
msgstr ""

POT;

		file_put_contents( $this->test_pot_file, $pot_content );
	}

	/**
	 * 创建测试站点关系
	 */
	private function create_test_relation() {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );

		$current_site_id = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'template'       => 'woocommerce',
				'source_site_id' => $current_site_id,
				'target_site_id' => $current_site_id,
				'target_type'    => 'real',
				'source_lang'    => 'zh_CN',
				'target_lang'    => 'en_US',
				'status'         => 'active',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->test_relation_id = $wpdb->insert_id;
	}

	/**
	 * 清理测试
	 */
	public function tearDown(): void {
		global $wpdb;

		// 删除测试模板的条目
		foreach ( $this->template_ids as $template_id ) {
			Template_Entry_Service::delete_by_template( $template_id );
			Template_Service::delete( $template_id );
		}

		// 删除测试站点关系
		if ( $this->test_relation_id ) {
			$wpdb->delete(
				wptsall_table( 'site_relations' ),
				array( 'id' => $this->test_relation_id ),
				array( '%d' )
			);
		}

		// 删除临时文件和目录
		if ( file_exists( $this->test_pot_file ) ) {
			unlink( $this->test_pot_file );
		}
		if ( is_dir( $this->temp_dir ) ) {
			rmdir( $this->temp_dir );
		}

		parent::tearDown();
	}

	/**
	 * 测试服务类存在
	 */
	public function test_service_classes_exist() {
		$this->assertTrue( class_exists( 'WPTSALL\\Templates\\Services\\Template_Service' ) );
		$this->assertTrue( class_exists( 'WPTSALL\\Templates\\Services\\Template_Entry_Service' ) );
	}

	/**
	 * 测试扫描器类存在
	 */
	public function test_scanner_classes_exist() {
		$this->assertTrue( class_exists( 'WPTSALL\\Templates\\Scanners\\POT_Parser' ) );
		$this->assertTrue( class_exists( 'WPTSALL\\Templates\\Scanners\\Language_Pack_Scanner' ) );
	}

	/**
	 * 测试数据库表存在
	 */
	public function test_database_tables_exist() {
		global $wpdb;

		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$templates_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$templates_table}'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$entries_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$entries_table}'" );

		$this->assertEquals( $templates_table, $templates_exists );
		$this->assertEquals( $entries_table, $entries_exists );
	}

	/**
	 * 测试 POT 文件解析
	 */
	public function test_pot_parser_parse() {
		$entries = POT_Parser::parse( $this->test_pot_file );

		$this->assertIsArray( $entries );
		// POT 文件包含: Welcome, Hello, Add to cart, One item, %d items (作为 plural), Copyright 2024
		// 复数形式算两个 msgid，但在 POT 中是一个条目块
		$this->assertGreaterThanOrEqual( 4, count( $entries ) ); // 至少 4 条独立条目

		// 验证第一个条目
		$found_welcome = false;
		foreach ( $entries as $entry ) {
			if ( 'Welcome' === $entry['msgid'] ) {
				$found_welcome = true;
				$this->assertEquals( 'templates/header.php:10', $entry['reference'] );
				break;
			}
		}
		$this->assertTrue( $found_welcome, 'Welcome entry not found' );

		// 验证带上下文的条目
		$hello_entry = null;
		foreach ( $entries as $entry ) {
			if ( 'Hello' === $entry['msgid'] ) {
				$hello_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $hello_entry );
		$this->assertEquals( 'greeting', $hello_entry['msgctxt'] );

		// 验证复数形式
		$plural_entry = null;
		foreach ( $entries as $entry ) {
			if ( 'One item' === $entry['msgid'] ) {
				$plural_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $plural_entry );
		$this->assertEquals( '%d items', $plural_entry['msgid_plural'] );
	}

	/**
	 * 测试 POT 内容解析
	 */
	public function test_pot_parser_parse_content() {
		$content = 'msgid "Test String"' . "\n" . 'msgstr ""';
		$entries = POT_Parser::parse_content( $content );

		$this->assertIsArray( $entries );
		$this->assertCount( 1, $entries );
		$this->assertEquals( 'Test String', $entries[0]['msgid'] );
	}

	/**
	 * 测试模板 CRUD 操作
	 */
	public function test_template_crud_operations() {
		// 创建模板
		$template_id = Template_Service::create( array(
			'relation_id'    => $this->test_relation_id,
			'source_type'    => 'theme',
			'text_domain'    => 'test-theme',
			'source_name'    => 'Test Theme',
			'source_version' => '1.0.0',
			'status'         => 'scanned',
		) );

		$this->assertIsInt( $template_id );
		$this->assertGreaterThan( 0, $template_id );
		$this->template_ids[] = $template_id;

		// 读取模板
		$template = Template_Service::get( $template_id );
		$this->assertIsArray( $template );
		$this->assertEquals( $this->test_relation_id, (int) $template['relation_id'] );
		$this->assertEquals( 'test-theme', $template['text_domain'] );

		// 更新模板
		$result = Template_Service::update( $template_id, array(
			'source_version' => '1.1.0',
			'status'         => 'translating',
		) );
		$this->assertTrue( $result );

		$updated = Template_Service::get( $template_id );
		$this->assertEquals( '1.1.0', $updated['source_version'] );
		$this->assertEquals( 'translating', $updated['status'] );
	}

	/**
	 * 测试翻译条目 CRUD 操作
	 */
	public function test_template_entry_crud_operations() {
		// 创建测试模板
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-entries',
			'status'      => 'scanned',
		) );
		$this->template_ids[] = $template_id;

		// 创建条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Hello World',
			'msgctxt'     => 'greeting',
			'status'      => 'pending',
			'source'      => 'scan',
			'reference'   => 'test.php:10',
		) );

		$this->assertIsInt( $entry_id );
		$this->assertGreaterThan( 0, $entry_id );

		// 读取条目
		$entry = Template_Entry_Service::get( $entry_id );
		$this->assertIsArray( $entry );
		$this->assertEquals( 'Hello World', $entry['msgid'] );
		$this->assertEquals( 'greeting', $entry['msgctxt'] );

		// 更新条目
		$result = Template_Entry_Service::update( $entry_id, array(
			'msgstr' => '你好世界',
			'status' => 'translated',
			'source' => 'auto',
		) );
		$this->assertTrue( $result );

		$updated = Template_Entry_Service::get( $entry_id );
		$this->assertEquals( '你好世界', $updated['msgstr'] );
		$this->assertEquals( 'translated', $updated['status'] );

		// 删除条目
		$deleted = Template_Entry_Service::delete( $entry_id );
		$this->assertTrue( $deleted );

		$after_delete = Template_Entry_Service::get( $entry_id );
		$this->assertNull( $after_delete );
	}

	/**
	 * 测试批量创建条目
	 */
	public function test_bulk_create_entries() {
		// 创建测试模板
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-bulk',
			'status'      => 'scanned',
		) );
		$this->template_ids[] = $template_id;

		// 批量创建条目
		$entries = array(
			array(
				'template_id' => $template_id,
				'msgid'       => 'Entry 1',
				'status'      => 'pending',
			),
			array(
				'template_id' => $template_id,
				'msgid'       => 'Entry 2',
				'status'      => 'pending',
			),
			array(
				'template_id' => $template_id,
				'msgid'       => 'Entry 3',
				'status'      => 'pending',
			),
		);

		$result = Template_Entry_Service::bulk_create( $entries );

		$this->assertIsArray( $result );
		$this->assertEquals( 3, $result['success'] );
		$this->assertEquals( 0, $result['failed'] );

		// 验证条目已创建
		$all_entries = Template_Entry_Service::get_by_template( $template_id, array( 'per_page' => -1 ) );
		$this->assertEquals( 3, $all_entries['total'] );
	}

	/**
	 * 测试批量更新条目
	 */
	public function test_bulk_update_entries() {
		// 创建测试模板和条目
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-bulk-update',
			'status'      => 'scanned',
		) );
		$this->template_ids[] = $template_id;

		$entry_ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$entry_ids[] = Template_Entry_Service::create( array(
				'template_id' => $template_id,
				'msgid'       => "Entry {$i}",
				'status'      => 'pending',
			) );
		}

		// 批量更新状态
		$count = Template_Entry_Service::bulk_update( $entry_ids, array(
			'status' => 'reviewed',
		) );

		$this->assertEquals( 3, $count );

		// 验证更新
		foreach ( $entry_ids as $entry_id ) {
			$entry = Template_Entry_Service::get( $entry_id );
			$this->assertEquals( 'reviewed', $entry['status'] );
		}
	}

	/**
	 * 测试按状态获取条目
	 */
	public function test_get_entries_by_status() {
		// 创建测试模板
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-status',
			'status'      => 'scanned',
		) );
		$this->template_ids[] = $template_id;

		// 创建不同状态的条目
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Pending 1',
			'status'      => 'pending',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Translated 1',
			'status'      => 'translated',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Pending 2',
			'status'      => 'pending',
		) );

		// 获取 pending 状态的条目
		$pending = Template_Entry_Service::get_by_status( $template_id, 'pending' );
		$this->assertCount( 2, $pending );

		// 获取 translated 状态的条目
		$translated = Template_Entry_Service::get_by_status( $template_id, 'translated' );
		$this->assertCount( 1, $translated );
	}

	/**
	 * 测试搜索条目
	 */
	public function test_search_entries() {
		// 创建测试模板
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-search',
			'status'      => 'scanned',
		) );
		$this->template_ids[] = $template_id;

		// 创建测试条目
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Add to cart',
			'status'      => 'pending',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Shopping cart',
			'status'      => 'pending',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Checkout',
			'status'      => 'pending',
		) );

		// 搜索包含 "cart" 的条目
		$result = Template_Entry_Service::get_by_template( $template_id, array(
			'search'   => 'cart',
			'per_page' => -1,
		) );

		$this->assertEquals( 2, $result['total'] );
	}

	/**
	 * 测试统计信息更新
	 */
	public function test_update_stats() {
		// 创建测试模板
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-stats',
			'status'      => 'pending',
		) );
		$this->template_ids[] = $template_id;

		// 创建条目
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'String 1',
			'status'      => 'pending',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'String 2',
			'status'      => 'translated',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'String 3',
			'status'      => 'reviewed',
		) );

		// 更新统计
		$result = Template_Service::update_stats( $template_id );
		$this->assertTrue( $result );

		// 验证统计
		$template = Template_Service::get( $template_id );
		$this->assertEquals( 3, (int) $template['total_entries'] );
		$this->assertEquals( 2, (int) $template['translated_entries'] ); // translated + reviewed
		$this->assertEquals( 1, (int) $template['reviewed_entries'] );
		$this->assertEquals( 'translating', $template['status'] ); // 部分翻译
	}

	/**
	 * 测试完整扫描到保存流程
	 */
	public function test_complete_scan_workflow() {
		// 解析 POT 文件
		$entries = POT_Parser::parse( $this->test_pot_file );
		$this->assertGreaterThan( 0, count( $entries ) );

		// 创建模板
		$template_id = Template_Service::get_or_create( array(
			'relation_id'    => $this->test_relation_id,
			'source_type'    => 'theme',
			'text_domain'    => 'test-workflow',
			'source_name'    => 'Test Workflow Theme',
			'source_version' => '1.0.0',
		) );
		$this->template_ids[] = $template_id;

		// 保存条目
		$entry_count = 0;
		foreach ( $entries as $entry ) {
			$entry_id = Template_Entry_Service::get_or_create( $template_id, array(
				'msgid'        => $entry['msgid'],
				'msgid_plural' => $entry['msgid_plural'] ?? '',
				'msgctxt'      => $entry['msgctxt'] ?? '',
				'reference'    => $entry['reference'] ?? '',
				'status'       => 'pending',
				'source'       => 'scan',
			) );

			if ( $entry_id ) {
				$entry_count++;
			}
		}

		// 验证保存的条目数
		$this->assertEquals( count( $entries ), $entry_count );

		// 更新统计
		Template_Service::update_stats( $template_id );

		// 验证模板状态
		$template = Template_Service::get( $template_id );
		$this->assertEquals( count( $entries ), (int) $template['total_entries'] );
		$this->assertEquals( 0, (int) $template['translated_entries'] );
		$this->assertEquals( 'scanned', $template['status'] );
	}

	/**
	 * 测试站点关系删除时清理模板
	 */
	public function test_cleanup_templates_on_relation_deleted() {
		// 创建测试模板
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-cleanup',
			'status'      => 'scanned',
		) );
		$this->template_ids[] = $template_id; // 记录以便 tearDown 清理

		// 创建几个条目
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Test 1',
			'status'      => 'pending',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Test 2',
			'status'      => 'pending',
		) );

		// 验证模板和条目存在
		$this->assertNotNull( Template_Service::get( $template_id ) );
		$entries = Template_Entry_Service::get_by_template( $template_id, array( 'per_page' => -1 ) );
		$this->assertEquals( 2, $entries['total'] );

		// 获取当前关系的所有模板数量（包括之前测试创建的）
		$templates_before = Template_Service::get_by_relation( $this->test_relation_id );
		$expected_count   = count( $templates_before );

		// 删除站点关系下的所有模板
		$count = Template_Service::delete_by_relation( $this->test_relation_id );
		$this->assertGreaterThanOrEqual( 1, $count ); // 至少删除我们创建的那个

		// 验证模板已删除
		$this->assertNull( Template_Service::get( $template_id ) );

		// 验证条目已清理
		$entries_after = Template_Entry_Service::get_by_template( $template_id, array( 'per_page' => -1 ) );
		$this->assertEquals( 0, $entries_after['total'] );
	}

	/**
	 * 测试获取翻译（供 Hooks 使用）
	 */
	public function test_get_translations_for_hook() {
		// 创建测试模板
		$template_id = Template_Service::create( array(
			'relation_id' => $this->test_relation_id,
			'source_type' => 'theme',
			'text_domain' => 'test-hooks',
			'status'      => 'scanned',
		) );
		$this->template_ids[] = $template_id;

		// 创建已翻译的条目
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Hello',
			'msgstr'      => '你好',
			'status'      => 'translated',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Welcome',
			'msgctxt'     => 'greeting',
			'msgstr'      => '欢迎',
			'status'      => 'reviewed',
		) );
		Template_Entry_Service::create( array(
			'template_id' => $template_id,
			'msgid'       => 'Pending',
			'msgstr'      => '',
			'status'      => 'pending',
		) );

		// 获取翻译
		$translations = Template_Entry_Service::get_translations_for_hook(
			$this->test_relation_id,
			'test-hooks'
		);

		// 验证结果
		$this->assertIsArray( $translations );
		$this->assertCount( 2, $translations ); // 只返回已翻译的

		// 验证普通条目
		$this->assertArrayHasKey( 'Hello', $translations );
		$this->assertEquals( '你好', $translations['Hello']['msgstr'] );

		// 验证带上下文的条目（使用 EOT 分隔符）
		$context_key = "greeting\x04Welcome";
		$this->assertArrayHasKey( $context_key, $translations );
		$this->assertEquals( '欢迎', $translations[ $context_key ]['msgstr'] );
	}

	/**
	 * 测试 POT 生成
	 */
	public function test_pot_generation() {
		$entries = array(
			array(
				'msgid'     => 'Hello',
				'msgstr'    => '你好',
				'reference' => 'test.php:10',
			),
			array(
				'msgid'   => 'World',
				'msgctxt' => 'noun',
				'msgstr'  => '世界',
			),
		);

		$pot_content = POT_Parser::generate( $entries, array(
			'project'  => 'Test Project',
			'version'  => '1.0.0',
			'language' => 'zh_CN',
		) );

		$this->assertStringContainsString( 'msgid "Hello"', $pot_content );
		$this->assertStringContainsString( 'msgstr "你好"', $pot_content );
		$this->assertStringContainsString( 'msgctxt "noun"', $pot_content );
		$this->assertStringContainsString( '#: test.php:10', $pot_content );
	}
}
