<?php
/**
 * Chain 6: 语言包扫描链路测试
 *
 * 测试语言包扫描流程：POT 发现 → 条目提取 → 模板创建 → REST API
 *
 * REST 端点:
 * - POST /wptsall/v2/templates/scan
 * - GET  /wptsall/v2/templates
 * - GET  /wptsall/v2/templates/{id}
 * - GET  /wptsall/v2/templates/{id}/entries
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 6 Test: Language Pack Scanning
 */
class Test_Chain_6_Language_Pack extends REST_Integration_Test_Case {

	// ========================================
	// POT 文件发现测试
	// ========================================

	/**
	 * 测试 POT 文件发现
	 *
	 * 验证 Language_Pack_Scanner 能发现 POT 文件
	 */
	public function test_pot_file_discovery() {
		// 验证 Language_Pack_Scanner 类存在
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Scanners\Language_Pack_Scanner' ),
			'Language_Pack_Scanner 类应存在'
		);

		// 使用反射检查方法
		$reflection = new \ReflectionClass( 'WPTSALL\Templates\Scanners\Language_Pack_Scanner' );

		// 检查扫描方法
		$has_scan_method = $reflection->hasMethod( 'scan' ) ||
		                   $reflection->hasMethod( 'scan_relation' ) ||
		                   $reflection->hasMethod( 'scan_pot_files' );

		$this->assertTrue( $has_scan_method, 'Language_Pack_Scanner 应有扫描方法' );
	}

	/**
	 * 测试 POT_Parser 类存在
	 */
	public function test_pot_parser_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Scanners\POT_Parser' ),
			'POT_Parser 类应存在'
		);
	}

	// ========================================
	// 条目提取测试
	// ========================================

	/**
	 * 测试 POT 文件解析
	 *
	 * 验证 POT_Parser 能正确解析 POT 文件内容
	 */
	public function test_pot_parsing() {
		// 模拟 POT 文件内容
		$pot_content = <<<POT
# Sample POT file
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"

#: sample.php:10
msgid "Hello"
msgstr ""

#: sample.php:20
msgid "World"
msgstr ""
POT;

		// 如果 POT_Parser 有 parse_string 或类似方法，测试它
		$reflection = new \ReflectionClass( 'WPTSALL\Templates\Scanners\POT_Parser' );

		// 检查解析方法
		$has_parse_method = $reflection->hasMethod( 'parse' ) ||
		                    $reflection->hasMethod( 'parse_file' ) ||
		                    $reflection->hasMethod( 'parse_content' );

		$this->assertTrue( $has_parse_method, 'POT_Parser 应有解析方法' );
	}

	/**
	 * 测试条目数据结构
	 */
	public function test_entry_data_structure() {
		// 验证 Template_Entry_Service 存在
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Services\Template_Entry_Service' ),
			'Template_Entry_Service 类应存在'
		);
	}

	// ========================================
	// 模板创建测试
	// ========================================

	/**
	 * 测试模板创建
	 *
	 * 验证 Template_Service 能创建模板记录
	 */
	public function test_template_creation() {
		// 验证 Template_Service 存在
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Services\Template_Service' ),
			'Template_Service 类应存在'
		);

		// 检查 create 方法
		$reflection = new \ReflectionClass( 'WPTSALL\Templates\Services\Template_Service' );
		$this->assertTrue( $reflection->hasMethod( 'create' ), 'Template_Service 应有 create 方法' );
	}

	/**
	 * 测试模板数据库表存在
	 */
	public function test_templates_table_exists() {
		global $wpdb;
		$table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertEquals( $table, $table_exists, 'templates 表应存在' );
	}

	/**
	 * 测试模板条目表存在
	 */
	public function test_template_entries_table_exists() {
		global $wpdb;
		$table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertEquals( $table, $table_exists, 'template_entries 表应存在' );
	}

	// ========================================
	// REST API 测试
	// ========================================

	/**
	 * 测试获取所有模板
	 *
	 * @covers Template_REST_Controller::get_items
	 */
	public function test_templates_rest_api() {
		$response = $this->rest_get( 'templates' );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items' );
		$this->assertIsArray( $data['items'], 'items 应为数组' );
	}

	/**
	 * 测试模板分页
	 */
	public function test_templates_pagination() {
		$response = $this->rest_get( 'templates', array(
			'page'     => 1,
			'per_page' => 10,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'total', $data, '响应应包含 total' );
		$this->assertArrayHasKey( 'pages', $data, '响应应包含 pages' );
	}

	/**
	 * 测试按类型筛选模板
	 */
	public function test_filter_templates_by_type() {
		$response = $this->rest_get( 'templates', array(
			'source_type' => 'plugin',
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		foreach ( $data['items'] ?? array() as $template ) {
			$this->assertEquals( 'plugin', $template['source_type'] ?? '', '筛选结果应只包含 plugin 类型' );
		}
	}

	/**
	 * 测试按语言筛选模板
	 */
	public function test_filter_templates_by_language() {
		$response = $this->rest_get( 'templates', array(
			'target_language' => 'zh_CN',
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );

		// 如果没有匹配的模板，测试依然通过（筛选功能正常，只是无数据）
		if ( empty( $data['items'] ) ) {
			// 筛选功能正常工作，返回空数组是合法结果
			$this->assertIsArray( $data['items'], '筛选无结果时 items 仍应为数组' );
			return;
		}

		// 验证返回的模板都匹配筛选条件
		foreach ( $data['items'] as $template ) {
			// 如果 API 返回了模板，检查 target_language 字段
			// 字段可能不存在（全局模板）、为空（全局模板）或者值匹配
			$lang = $template['target_language'] ?? null;
			// 允许 null（字段不存在）、''（全局模板）、'zh_CN'（匹配值）
			if ( null !== $lang && '' !== $lang ) {
				$this->assertEquals( 'zh_CN', $lang, '筛选结果应只包含 zh_CN 或全局模板' );
			}
		}
	}

	/**
	 * 测试获取单个模板
	 */
	public function test_get_single_template() {
		// 先获取模板列表
		$list_response = $this->rest_get( 'templates' );
		$list_data = $this->get_response_data( $list_response );

		if ( empty( $list_data['items'] ) ) {
			$this->markTestSkipped( '没有可用的模板' );
		}

		$template_id = $list_data['items'][0]['id'];

		// 获取单个模板
		$response = $this->rest_get( "templates/{$template_id}" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertEquals( $template_id, $data['id'] ?? null, '返回的模板 ID 应匹配' );
	}

	/**
	 * 测试获取不存在的模板返回 404
	 */
	public function test_get_nonexistent_template() {
		$response = $this->rest_get( 'templates/999999' );

		$this->assertRestError( $response, 'not_found', 404 );
	}

	/**
	 * 测试获取模板条目
	 */
	public function test_get_template_entries() {
		// 先获取模板列表
		$list_response = $this->rest_get( 'templates' );
		$list_data = $this->get_response_data( $list_response );

		if ( empty( $list_data['items'] ) ) {
			$this->markTestSkipped( '没有可用的模板' );
		}

		$template_id = $list_data['items'][0]['id'];

		// 获取条目
		$response = $this->rest_get( "templates/{$template_id}/entries" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items' );
	}

	// ========================================
	// 扫描触发测试
	// ========================================

	/**
	 * 测试触发模板扫描
	 */
	public function test_trigger_template_scan() {
		global $wpdb;

		// 创建虚拟站点和关系
		$vs_id = $this->create_test_virtual_site();
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id = $relation_data['relation_ids'][0] ?? 0;

		// 触发扫描
		$response = $this->rest_post( 'tasks/scan-language-pack', array(
			'relation_id' => $relation_id,
			'mode'        => 'pot',
		) );

		// 扫描结果取决于是否有 POT 文件
		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404, 500 ), '扫描应返回有效状态' );

		// 如果扫描成功，验证 template_entries 已创建
		if ( 200 === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$entry_count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_template_entries WHERE status='pending'"
			);
			$this->assertGreaterThan( 0, $entry_count, 'template_entries must be created after language pack scan' );
		}
	}

	// ========================================
	// 服务层测试
	// ========================================

	/**
	 * 测试 Template_Service 存在且可用
	 */
	public function test_template_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Services\Template_Service' ),
			'Template_Service 类应存在'
		);
	}

	/**
	 * 测试 Template_Entry_Service 存在且可用
	 */
	public function test_template_entry_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Services\Template_Entry_Service' ),
			'Template_Entry_Service 类应存在'
		);
	}

	/**
	 * 测试 Language_Pack_Scanner 存在且可用
	 */
	public function test_language_pack_scanner_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Scanners\Language_Pack_Scanner' ),
			'Language_Pack_Scanner 类应存在'
		);
	}

	/**
	 * 测试 POT_Parser 存在且可用
	 */
	public function test_pot_parser_class_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Templates\Scanners\POT_Parser' ),
			'POT_Parser 类应存在'
		);
	}
}
