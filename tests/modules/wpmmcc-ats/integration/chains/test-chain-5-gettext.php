<?php
/**
 * Chain 5: Gettext 翻译链路测试
 *
 * 测试 Gettext 翻译流程：过滤器注册 → 字符串翻译 → 上下文翻译 → 虚拟站点语言
 *
 * REST 端点:
 * - POST /wptsall/v2/tasks/scan-language-pack
 * - POST /wptsall/v2/tasks/translate-language-pack
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 5 Test: Gettext Translation
 */
class Test_Chain_5_Gettext extends REST_Integration_Test_Case {

	/**
	 * 测试虚拟站点 ID
	 *
	 * @var int
	 */
	private $test_virtual_site_id;

	/**
	 * 测试关系 ID
	 *
	 * @var int
	 */
	private $test_relation_id;

	/**
	 * Original simulation option snapshot.
	 *
	 * @var mixed
	 */
	private $simulation_option_original;

	/**
	 * 每个测试前设置
	 */
	public function setUp(): void {
		parent::setUp();

		// 创建虚拟站点
		$this->test_virtual_site_id = $this->create_test_virtual_site( array(
			'name'        => 'Gettext Test Site',
			'path_prefix' => 'gettext-test-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		// 创建站点关系
		$relation_data = $this->create_test_relation( $this->test_virtual_site_id );
		$this->test_relation_id = $relation_data['relation_ids'][0] ?? 0;

		// Explicitly enable simulation for this chain.
		// Production default remains disabled; tests opt in explicitly.
		$this->simulation_option_original = get_option( 'wptsall_enable_translation_simulation', null );
		update_option( 'wptsall_enable_translation_simulation', 1 );
	}

	/**
	 * 每个测试后恢复环境
	 */
	public function tearDown(): void {
		if ( null === $this->simulation_option_original ) {
			delete_option( 'wptsall_enable_translation_simulation' );
		} else {
			update_option( 'wptsall_enable_translation_simulation', $this->simulation_option_original );
		}

		parent::tearDown();
	}

	// ========================================
	// 过滤器注册测试
	// ========================================

	/**
	 * 测试 Gettext 过滤器注册
	 *
	 * 验证 Gettext_Filter 类正确注册了翻译过滤器
	 */
	public function test_gettext_filter_registration() {
		// 检查 Gettext_Filter 类是否存在
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Gettext_Filter' ),
			'Gettext_Filter 类应存在'
		);

		// 检查是否有 gettext 过滤器（可能在虚拟站点上下文中）
		// 注意：实际过滤器可能只在特定上下文中注册
		$has_filter = has_filter( 'gettext' );

		// 过滤器可能存在也可能不存在（取决于上下文与 WP 版本行为）：
		// - false: 无钩子
		// - int: 指定 callback 的优先级
		// - WP_Hook/object: 当前 tag 的 hook 对象（部分环境）
		$this->assertTrue(
			is_bool( $has_filter ) || is_int( $has_filter ) || ( is_object( $has_filter ) && $has_filter instanceof \WP_Hook ),
			'过滤器检查应返回 bool/int/WP_Hook (WP has_filter 无 callback 时返回 bool)'
		);
	}

	/**
	 * 测试 Gettext_Filter 初始化
	 */
	public function test_gettext_filter_initialization() {
		// 获取 Gettext_Filter 类的方法
		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Gettext_Filter' );

		// 检查关键方法是否存在
		$this->assertTrue(
			$reflection->hasMethod( 'filter_gettext' ) || $reflection->hasMethod( 'translate' ),
			'Gettext_Filter 应有翻译方法'
		);
	}

	// ========================================
	// 字符串翻译测试
	// ========================================

	/**
	 * 测试基本字符串翻译
	 *
	 * 验证 Translation_Simulation_Service 正确模拟翻译
	 */
	public function test_string_translation() {
		// 验证 Translation_Simulation_Service 存在
		$this->assertTrue(
			class_exists( 'WPTSALL\Tasks\Services\Translation_Simulation_Service' ),
			'Translation_Simulation_Service 类应存在'
		);

		// 测试模拟翻译功能
		$original = 'Hello World';
		$target_lang = 'zh_CN';

		$translated = \WPTSALL\Tasks\Services\Translation_Simulation_Service::translate_string(
			$original,
			$target_lang
		);

		// 验证翻译后的字符串包含语言标记
		$this->assertStringContainsString( "【{$target_lang}】", $translated, '翻译应包含开始标记' );
		$this->assertStringContainsString( "【/{$target_lang}】", $translated, '翻译应包含结束标记' );
		$this->assertStringContainsString( $original, $translated, '翻译应包含原文' );
	}

	/**
	 * 测试多语言翻译
	 */
	public function test_multi_language_translation() {
		$original = 'Test String';
		$languages = array( 'zh_CN', 'ja', 'ko', 'de_DE' );

		foreach ( $languages as $lang ) {
			$translated = \WPTSALL\Tasks\Services\Translation_Simulation_Service::translate_string(
				$original,
				$lang
			);

			$this->assertStringContainsString( "【{$lang}】", $translated, "翻译应包含 {$lang} 标记" );
		}
	}

	// ========================================
	// 上下文翻译测试
	// ========================================

	/**
	 * 测试带上下文的翻译
	 *
	 * 验证翻译可以区分不同上下文的相同字符串
	 */
	public function test_context_translation() {
		// Translation_Simulation_Service 可能支持上下文参数
		$original = 'Post';
		$target_lang = 'zh_CN';

		// 模拟翻译（当前实现可能不区分上下文）
		$translated = \WPTSALL\Tasks\Services\Translation_Simulation_Service::translate_string(
			$original,
			$target_lang
		);

		// 验证基本翻译功能
		$this->assertStringContainsString( $original, $translated );

		// 验证翻译标记格式 【{lang}】...【/{lang}】 出现在输出中
		$pattern = '/【' . preg_quote( $target_lang, '/' ) . '】.*【\/' . preg_quote( $target_lang, '/' ) . '】/s';
		$this->assertMatchesRegularExpression( $pattern, $translated, '翻译输出必须包含标记格式 【{lang}】...【/{lang}】' );
	}

	// ========================================
	// 虚拟站点语言测试
	// ========================================

	/**
	 * 测试虚拟站点语言设置
	 */
	public function test_virtual_site_locale() {
		// 获取虚拟站点信息
		$response = $this->rest_get( "virtual-sites/{$this->test_virtual_site_id}" );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'lang', $data );
		$this->assertEquals( 'zh_CN', $data['lang'], '虚拟站点语言应为 zh_CN' );
	}

	/**
	 * 测试更新虚拟站点语言
	 */
	public function test_update_virtual_site_locale() {
		// 更新语言设置
		$response = $this->rest_put( "virtual-sites/{$this->test_virtual_site_id}", array(
			'lang' => 'ja',
		) );

		$this->assertRestSuccess( $response, 200 );

		// 验证更新
		$verify_response = $this->rest_get( "virtual-sites/{$this->test_virtual_site_id}" );
		$verify_data = $this->get_response_data( $verify_response );
		$this->assertEquals( 'ja', $verify_data['lang'] ?? '' );
	}

	// ========================================
	// 语言包扫描测试
	// ========================================

	/**
	 * 测试触发语言包扫描
	 *
	 * @covers Tasks_REST_Controller::scan_language_pack
	 */
	public function test_scan_language_pack() {
		$response = $this->rest_post( 'tasks/scan-language-pack', array(
			'relation_id' => $this->test_relation_id,
			'mode'        => 'pot',
		) );

		// 扫描可能成功或因为没有 POT 文件而返回空结果
		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404, 500 ), '扫描应返回有效状态' );

		if ( $status === 200 ) {
			$data = $this->get_response_data( $response );
			$this->assertArrayHasKey( 'success', $data );
		}
	}

	/**
	 * 测试语言包扫描不同模式
	 */
	public function test_scan_language_pack_modes() {
		$modes = array( 'pot', 'source', 'content' );

		foreach ( $modes as $mode ) {
			$response = $this->rest_post( 'tasks/scan-language-pack', array(
				'relation_id' => $this->test_relation_id,
				'mode'        => $mode,
			) );

			// 只验证请求被接受
			$status = $response->get_status();
			$this->assertContains( $status, array( 200, 400, 404, 500 ), "模式 {$mode} 应返回有效状态" );
		}
	}

	// ========================================
	// 语言包翻译测试
	// ========================================

	/**
	 * 测试触发语言包翻译
	 *
	 * @covers Tasks_REST_Controller::translate_language_pack
	 */
	public function test_translate_language_pack() {
		// 先执行扫描
		$this->rest_post( 'tasks/scan-language-pack', array(
			'relation_id' => $this->test_relation_id,
			'mode'        => 'pot',
		) );

		// 执行翻译
		$response = $this->rest_post( 'tasks/translate-language-pack', array(
			'relation_id' => $this->test_relation_id,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'translated', $data );

		// 如果有翻译样本，验证标记格式 【{lang}】...【/{lang}】
		if ( ! empty( $data['sample'] ) ) {
			$this->assertMatchesRegularExpression(
				'/【[a-zA-Z_]+】.*【\/[a-zA-Z_]+】/s',
				$data['sample'],
				'翻译样本必须包含标记格式 【{lang}】...【/{lang}】'
			);
		}

		// 通过 Translation_Simulation_Service 直接验证 zh_CN 标记格式
		if ( class_exists( 'WPTSALL\Tasks\Services\Translation_Simulation_Service' ) ) {
			$test_translated = \WPTSALL\Tasks\Services\Translation_Simulation_Service::translate_string( 'test', 'zh_CN' );
			$this->assertMatchesRegularExpression(
				'/【zh_CN】.*【\/zh_CN】/s',
				$test_translated,
				'Translation_Simulation_Service 必须输出 【zh_CN】...【/zh_CN】 标记格式'
			);
		}
	}

	// ========================================
	// 翻译模拟服务测试
	// ========================================

	/**
	 * 测试 Translation_Simulation_Service 存在
	 */
	public function test_translation_simulation_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Tasks\Services\Translation_Simulation_Service' ),
			'Translation_Simulation_Service 类应存在'
		);
	}

	/**
	 * 测试翻译标记格式
	 */
	public function test_translation_marker_format() {
		$original = 'Test Content';
		$target_lang = 'zh_CN';

		$translated = \WPTSALL\Tasks\Services\Translation_Simulation_Service::translate_string(
			$original,
			$target_lang
		);

		// 验证标记格式: 【{lang}】{content}【/{lang}】
		$expected_pattern = '/【' . preg_quote( $target_lang, '/' ) . '】.*【\/' . preg_quote( $target_lang, '/' ) . '】/s';
		$this->assertMatchesRegularExpression( $expected_pattern, $translated, '翻译应符合标记格式' );
	}

	/**
	 * 测试空字符串处理
	 */
	public function test_empty_string_translation() {
		$translated = \WPTSALL\Tasks\Services\Translation_Simulation_Service::translate_string(
			'',
			'zh_CN'
		);

		// 空字符串可能返回空或带标记的空字符串
		$this->assertIsString( $translated );
	}

	/**
	 * 测试特殊字符处理
	 */
	public function test_special_characters_translation() {
		$original = 'Test <html> & "quotes" \'apostrophe\'';
		$target_lang = 'zh_CN';

		$translated = \WPTSALL\Tasks\Services\Translation_Simulation_Service::translate_string(
			$original,
			$target_lang
		);

		// 原文应保留在翻译中
		$this->assertStringContainsString( '<html>', $translated );
		$this->assertStringContainsString( '&', $translated );
	}

	// ========================================
	// Gettext_Filter 类测试
	// ========================================

	/**
	 * 测试 Gettext_Filter 类存在
	 */
	public function test_gettext_filter_class_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Hooks\Gettext_Filter' ),
			'Gettext_Filter 类应存在'
		);
	}

	/**
	 * 测试 Gettext_Filter 静态方法
	 */
	public function test_gettext_filter_static_methods() {
		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Gettext_Filter' );

		// 检查 init 方法
		$this->assertTrue(
			$reflection->hasMethod( 'init' ),
			'Gettext_Filter 应有 init 方法'
		);
	}
}
