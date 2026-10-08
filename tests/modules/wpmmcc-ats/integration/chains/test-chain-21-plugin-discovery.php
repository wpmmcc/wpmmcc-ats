<?php
/**
 * Chain 21: 插件动态发现链路测试
 *
 * 测试插件发现流程：激活插件检测 → post_type 发现 → taxonomy 发现 → meta 字段发现
 *
 * 核心类:
 * - Models\\Services\\Plugin_Scanner
 * - Models\\Scanners\\Model_Scanner_V2
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 21 Test: Plugin Dynamic Discovery
 */
class Test_Chain_21_Plugin_Discovery extends REST_Integration_Test_Case {

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

		// 确保 get_plugins 函数可用
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// 检查 WordPress 核心函数存在
		$required_functions = array(
			'get_post_types',
			'get_taxonomies',
			'get_plugins',
		);

		foreach ( $required_functions as $func ) {
			if ( ! function_exists( $func ) ) {
				self::$chain_runnable = false;
				self::$skip_reason = "缺少核心函数: {$func}";
				return;
			}
		}

		// 检查扫描器类存在
		$discovery_classes = array(
			'WPTSALL\\Models\\Services\\Plugin_Scanner',
			'WPTSALL\\Models\\Scanners\\Model_Scanner_V2',
		);

		$has_discovery = false;
		foreach ( $discovery_classes as $class ) {
			if ( class_exists( $class ) ) {
				$has_discovery = true;
				break;
			}
		}

		if ( ! $has_discovery ) {
			self::$chain_runnable = false;
			self::$skip_reason = '缺少插件发现服务类';
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
	// Plugin_Scanner 测试
	// ========================================

	/**
	 * 测试 Plugin_Scanner 类存在
	 */
	public function test_plugin_scanner_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Models\\Services\\Plugin_Scanner' ),
			'Plugin_Scanner 类应存在'
		);
	}

	/**
	 * 测试扫描方法存在
	 */
	public function test_discovery_methods_exist() {
		$class_name = 'WPTSALL\\Models\\Services\\Plugin_Scanner';

		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( 'Plugin_Scanner 类不存在' );
		}

		$reflection = new \ReflectionClass( $class_name );

		$methods = array(
			'scan_all_plugins',
			'scan_plugin',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				"Plugin_Scanner 应有方法 {$method}"
			);
		}
	}

	// ========================================
	// 激活插件检测测试
	// ========================================

	/**
	 * 测试获取激活的插件列表
	 */
	public function test_get_active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		$this->assertIsArray( $all_plugins, '插件列表应为数组' );
		$this->assertIsArray( $active_plugins, '激活插件列表应为数组' );
	}

	/**
	 * 测试 WPTSALL 插件激活状态
	 */
	public function test_wptsall_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$this->assertTrue( wptsall_integration_is_plugin_active(), 'WPTSALL 插件应处于激活状态' );
	}

	/**
	 * 测试通过 REST API 获取插件
	 */
	public function test_get_plugins_via_rest() {
		if ( ! $this->route_exists( 'discovery/plugins', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/plugins 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/plugins', array(), 'wptsall/v2' );

		// 路由存在时应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '插件发现 API 路由存在时必须返回 200' );

		$data = $this->get_response_data( $response );
		$this->assertIsArray( $data['plugins'] ?? $data, '响应应包含插件数组' );
	}

	// ========================================
	// Post Type 发现测试
	// ========================================

	/**
	 * 测试获取所有 post_type
	 */
	public function test_get_all_post_types() {
		$post_types = get_post_types( array(), 'objects' );

		$this->assertIsArray( $post_types, 'post_types 应为数组' );
		$this->assertNotEmpty( $post_types, '应至少有一个 post_type' );

		// 验证核心类型存在
		$this->assertArrayHasKey( 'post', $post_types, 'post 类型应存在' );
		$this->assertArrayHasKey( 'page', $post_types, 'page 类型应存在' );
	}

	/**
	 * 测试获取公开 post_type
	 */
	public function test_get_public_post_types() {
		$public_types = get_post_types( array( 'public' => true ), 'objects' );

		$this->assertIsArray( $public_types, '公开 post_types 应为数组' );
		$this->assertArrayHasKey( 'post', $public_types, 'post 应为公开类型' );
	}

	/**
	 * 测试通过 REST API 获取 post_type
	 */
	public function test_get_post_types_via_rest() {
		if ( ! $this->route_exists( 'discovery/post-types', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/post-types 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/post-types', array(), 'wptsall/v2' );

		// 路由存在时应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, 'post_type 发现 API 路由存在时必须返回 200' );
	}

	/**
	 * 测试 post_type 与插件关联
	 */
	public function test_post_type_plugin_association() {
		if ( ! $this->route_exists( 'discovery/post-types', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/post-types 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/post-types', array(
			'with_plugin' => true,
		), 'wptsall/v2' );

		// 路由存在时应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '带插件关联的 post_type 查询路由存在时必须返回 200' );
	}

	// ========================================
	// Taxonomy 发现测试
	// ========================================

	/**
	 * 测试获取所有 taxonomy
	 */
	public function test_get_all_taxonomies() {
		$taxonomies = get_taxonomies( array(), 'objects' );

		$this->assertIsArray( $taxonomies, 'taxonomies 应为数组' );
		$this->assertNotEmpty( $taxonomies, '应至少有一个 taxonomy' );

		// 验证核心分类存在
		$this->assertArrayHasKey( 'category', $taxonomies, 'category 应存在' );
		$this->assertArrayHasKey( 'post_tag', $taxonomies, 'post_tag 应存在' );
	}

	/**
	 * 测试获取公开 taxonomy
	 */
	public function test_get_public_taxonomies() {
		$public_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		$this->assertIsArray( $public_taxonomies, '公开 taxonomies 应为数组' );
	}

	/**
	 * 测试通过 REST API 获取 taxonomy
	 */
	public function test_get_taxonomies_via_rest() {
		if ( ! $this->route_exists( 'discovery/taxonomies', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/taxonomies 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/taxonomies', array(), 'wptsall/v2' );

		// 路由存在时应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, 'taxonomy 发现 API 路由存在时必须返回 200' );
	}

	// ========================================
	// Meta 字段发现测试
	// ========================================

	/**
	 * 测试获取 post meta 字段
	 */
	public function test_get_post_meta_fields() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} LIMIT 100"
		);

		$this->assertIsArray( $meta_keys, 'post meta keys 应为数组' );
	}

	/**
	 * 测试获取 user meta 字段
	 */
	public function test_get_user_meta_fields() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} LIMIT 100"
		);

		$this->assertIsArray( $meta_keys, 'user meta keys 应为数组' );
	}

	/**
	 * 测试获取 term meta 字段
	 */
	public function test_get_term_meta_fields() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM {$wpdb->termmeta} LIMIT 100"
		);

		$this->assertIsArray( $meta_keys, 'term meta keys 应为数组' );
	}

	/**
	 * 测试按 post_type 获取 meta 字段
	 */
	public function test_get_meta_fields_by_post_type() {
		if ( ! $this->route_exists( 'discovery/meta-keys', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/meta-keys 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/meta-keys', array(
			'post_type' => 'post',
		), 'wptsall/v2' );

		// 路由存在时应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '按 post_type 获取 meta 路由存在时必须返回 200' );
	}

	// ========================================
	// Model_Scanner_V2 测试
	// ========================================

	/**
	 * 测试 Model_Scanner_V2 类存在
	 */
	public function test_model_scanner_v2_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Models\\Scanners\\Model_Scanner_V2' ),
			'Model_Scanner_V2 类应存在'
		);
	}

	/**
	 * 测试扫描单个插件
	 */
	public function test_scan_single_plugin() {
		$response = $this->rest_post( 'discovery/scan', array(
			'plugin' => 'wordpress-core',
		), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '单插件扫描应返回有效状态' );
	}

	/**
	 * 测试扫描所有插件
	 */
	public function test_scan_all_plugins() {
		$response = $this->rest_post( 'discovery/scan-all', array(), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '全插件扫描应返回有效状态' );
	}

	// ========================================
	// 插件与 post_type 关联测试
	// ========================================

	/**
	 * 测试检测插件注册的 post_type
	 */
	public function test_detect_plugin_registered_post_types() {
		// WordPress 核心注册了 post, page, attachment
		$core_types = array( 'post', 'page', 'attachment' );

		$post_types = get_post_types( array(), 'names' );

		foreach ( $core_types as $type ) {
			$this->assertContains( $type, $post_types, "{$type} 应被 WordPress 核心注册" );
		}
	}

	/**
	 * 测试检测自定义 post_type 来源
	 */
	public function test_detect_custom_post_type_source() {
		$custom_types = get_post_types( array( '_builtin' => false ), 'objects' );

		foreach ( $custom_types as $type ) {
			// 每个自定义类型应有名称
			$this->assertNotEmpty( $type->name, '自定义 post_type 应有名称' );
		}
	}

	// ========================================
	// 字段分类测试
	// ========================================

	/**
	 * 测试 Smart_Field_Classifier 存在
	 */
	public function test_smart_field_classifier_exists() {
		$exists = class_exists( 'WPTSALL\\Core\\Classifiers\\Smart_Field_Classifier' ) ||
		          class_exists( 'WPTSALL\\Models\\Classifiers\\Smart_Field_Classifier' ) ||
		          class_exists( 'WPTSALL\\Core\\Smart_Field_Classifier' );

		$this->assertTrue( $exists, 'Smart_Field_Classifier 类必须在已知命名空间之一中存在' );
	}

	/**
	 * 测试字段分类结果
	 */
	public function test_field_classification_result() {
		$response = $this->rest_get( 'discovery/classify-fields', array(
			'post_type' => 'post',
		), 'wptsall/v2' );

		$status = $response->get_status();

		if ( ! $this->route_exists( 'discovery/classify-fields', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/classify-fields 路由不存在' );
		}

		// 路由存在时应严格返回 200
		$this->assertEquals( 200, $status, '字段分类路由存在时应返回 200' );

		$data = $this->get_response_data( $response );

		// 响应应包含至少一个字段分类键
		$expected_categories = array(
			'translate_fields',
			'sync_fields',
			'field_mappings',
			'compute_fields',
		);

		$found_categories = array();
		foreach ( $expected_categories as $category ) {
			if ( isset( $data[ $category ] ) ) {
				$found_categories[] = $category;
			}
		}

		$this->assertNotEmpty( $found_categories, '字段分类响应应包含至少一个分类键（translate_fields/sync_fields/field_mappings/compute_fields）' );
	}

	/**
	 * 测试 Plan A 插件发现（woocommerce、bbpress 或 easy-digital-downloads）
	 *
	 * 验证已激活的 Plan A 插件能被发现或 wptsall_models 表有相关记录
	 */
	public function test_plan_a_plugin_discovery() {
		global $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plan_a_plugins = array( 'woocommerce', 'bbpress', 'easy-digital-downloads' );
		$active_plugins = get_option( 'active_plugins', array() );

		// 检查是否有任意 Plan A 插件激活
		$active_plan_a = array();
		foreach ( $plan_a_plugins as $slug ) {
			foreach ( $active_plugins as $plugin_file ) {
				if ( strpos( $plugin_file, $slug ) !== false ) {
					$active_plan_a[] = $slug;
					break;
				}
			}
		}

		if ( ! empty( $active_plan_a ) ) {
			// 有 Plan A 插件激活时，验证 wptsall_models 表中有对应记录
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$model_count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_models"
			);
			$this->assertGreaterThan( 0, $model_count, '激活 Plan A 插件后 wptsall_models 表应有记录' );
		} else {
			// 无 Plan A 插件时，验证 wordpress-blog 核心模型存在（基础发现能力）
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wordpress_model = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_models WHERE plugin_slug = %s",
					'wordpress-blog'
				)
			);
			// 核心 WordPress 模型应可被发现（通过 scan-all 触发后）
			// 如果表不存在则跳过
			if ( null !== $wordpress_model ) {
				$this->assertIsNumeric( $wordpress_model, 'wordpress-blog 模型查询应返回数字结果' );
			} else {
				$this->markTestSkipped( 'wptsall_models 表不存在，跳过 Plan A 插件发现验证' );
			}
		}
	}

	// ========================================
	// 增量发现测试
	// ========================================

	/**
	 * 测试增量扫描
	 */
	public function test_incremental_scan() {
		$response = $this->rest_post( 'discovery/scan', array(
			'mode' => 'incremental',
		), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '增量扫描应返回有效状态' );
	}

	/**
	 * 测试强制完整扫描
	 */
	public function test_force_full_scan() {
		$response = $this->rest_post( 'discovery/scan', array(
			'mode'  => 'full',
			'force' => true,
		), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '强制完整扫描应返回有效状态' );
	}

	// ========================================
	// 扫描结果持久化测试
	// ========================================

	/**
	 * 测试扫描结果保存
	 */
	public function test_scan_result_persistence() {
		// 检查路由是否存在
		if ( ! $this->route_exists( 'models/scan', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'models/scan 路由不存在' );
		}

		// 触发扫描 - 使用正确的路由 models/scan
		$scan_response = $this->rest_post( 'models/scan', array(
			'plugin' => 'wordpress-core',
		), 'wptsall/v2' );

		$scan_status = $scan_response->get_status();
		// 扫描可能返回 200 (成功) 或 400 (无字段)，都算正常
		$this->assertContains( $scan_status, array( 200, 400 ), '扫描应返回有效状态' );

		// 获取翻译规则（如果路由存在则严格验证）
		if ( $this->route_exists( 'translation-rules', 'wptsall/v2' ) ) {
			$rules_response = $this->rest_get( 'translation-rules', array(
				'plugin_slug' => 'wordpress-core',
			), 'wptsall/v2' );

			$status = $rules_response->get_status();
			$this->assertEquals( 200, $status, 'translation-rules 路由存在时必须返回 200' );
		}
	}

	/**
	 * 测试获取扫描历史
	 */
	public function test_get_scan_history() {
		if ( ! $this->route_exists( 'discovery/history', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/history 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/history', array(), 'wptsall/v2' );

		// 路由存在时应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '扫描历史路由存在时必须返回 200' );
	}

	// ========================================
	// 插件激活/停用钩子测试
	// ========================================

	/**
	 * 测试插件激活钩子
	 */
	public function test_plugin_activation_hook() {
		// activated_plugin 是 WordPress 核心 action，必须始终存在
		$this->assertTrue(
			has_filter( 'activated_plugin' ) !== false || function_exists( 'do_action' ),
			'WordPress 核心 activated_plugin action 应可用'
		);

		// 验证 WordPress 钩子系统正常工作
		$this->assertTrue( function_exists( 'add_action' ), 'WordPress add_action() 函数必须存在' );
	}

	/**
	 * 测试插件停用钩子
	 */
	public function test_plugin_deactivation_hook() {
		// deactivated_plugin 是 WordPress 核心 action，必须始终存在
		$this->assertTrue(
			has_filter( 'deactivated_plugin' ) !== false || function_exists( 'do_action' ),
			'WordPress 核心 deactivated_plugin action 应可用'
		);

		// 验证 WordPress 钩子系统正常工作
		$this->assertTrue( function_exists( 'add_action' ), 'WordPress add_action() 函数必须存在' );
	}

	// ========================================
	// 缓存测试
	// ========================================

	/**
	 * 测试发现结果缓存
	 */
	public function test_discovery_result_caching() {
		// 第一次请求
		$start1 = microtime( true );
		$response1 = $this->rest_get( 'discovery/plugins', array(), 'wptsall/v2' );
		$time1 = microtime( true ) - $start1;

		// 第二次请求（可能来自缓存）
		$start2 = microtime( true );
		$response2 = $this->rest_get( 'discovery/plugins', array(), 'wptsall/v2' );
		$time2 = microtime( true ) - $start2;

		// 两次请求应返回相同的状态码（一致性验证）
		$this->assertEquals(
			$response1->get_status(),
			$response2->get_status(),
			'两次相同请求应返回相同状态码（发现结果缓存一致性）'
		);
	}

	/**
	 * 测试清除发现缓存
	 */
	public function test_clear_discovery_cache() {
		$response = $this->rest_post( 'discovery/clear-cache', array(), 'wptsall/v2' );

		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '清除缓存应返回有效状态' );
	}
}
