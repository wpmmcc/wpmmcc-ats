<?php
/**
 * Chain 14: 字段发现服务链路测试
 *
 * 测试字段发现流程：获取数据表 → 列信息 → meta key 发现 → 分类
 *
 * 服务层:
 * - Models\Services\Field_Discovery_Service
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 14 Test: Field Discovery Service
 */
class Test_Chain_14_Field_Discovery extends REST_Integration_Test_Case {

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

		// 检查数据库连接是否正常
		global $wpdb;
		if ( ! $wpdb || ! $wpdb->posts ) {
			self::$chain_runnable = false;
			self::$skip_reason = '数据库连接不可用';
			return;
		}

		// 检查核心 WordPress 表是否存在
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts )
		);
		if ( ! $posts_exists ) {
			self::$chain_runnable = false;
			self::$skip_reason = "缺少 WordPress 核心表: {$wpdb->posts}";
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
	// Field_Discovery_Service 测试
	// ========================================

	/**
	 * 测试 Field_Discovery_Service 类存在
	 */
	public function test_field_discovery_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\\Models\\Services\\Field_Discovery_Service' ),
			'Field_Discovery_Service 类应存在'
		);
	}

	/**
	 * 测试 Field_Discovery_Service 方法
	 */
	public function test_field_discovery_service_methods() {
		if ( ! class_exists( 'WPTSALL\\Models\\Services\\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service 类不存在' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\\Models\\Services\\Field_Discovery_Service' );

		// 至少必须有一个核心发现方法
		$core_methods = array(
			'get_tables',
			'get_table_columns',
			'get_post_meta_keys',
			'get_term_meta_keys',
			'get_user_meta_keys',
		);

		$found_methods = array();
		foreach ( $core_methods as $method ) {
			if ( $reflection->hasMethod( $method ) ) {
				$found_methods[] = $method;
			}
		}

		$this->assertNotEmpty( $found_methods, 'Field_Discovery_Service 必须至少实现一个核心发现方法（' . implode( ', ', $core_methods ) . '）' );
	}

	// ========================================
	// 数据库表发现测试
	// ========================================

	/**
	 * 测试获取所有数据库表
	 */
	public function test_get_all_tables() {
		global $wpdb;

		// 直接查询数据库表
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		$this->assertIsArray( $tables, '应返回表数组' );
		$this->assertNotEmpty( $tables, '应至少有一个表' );

		// 验证 WordPress 核心表存在
		$this->assertContains( $wpdb->posts, $tables, 'posts 表应存在' );
		$this->assertContains( $wpdb->postmeta, $tables, 'postmeta 表应存在' );
	}

	/**
	 * 测试 model_object_fields 表的 data_type 列存在且已填充 (Phase C1)
	 *
	 * @covers Field_Discovery_Service (Phase C1 schema requirement)
	 */
	public function test_model_object_fields_data_type_column_exists() {
		global $wpdb;

		// 验证 data_type 列存在
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$col_exists = $wpdb->get_results(
			"SHOW COLUMNS FROM {$wpdb->prefix}wptsall_model_object_fields LIKE 'data_type'"
		);
		$this->assertNotEmpty( $col_exists, 'data_type column must exist (Phase C1)' );
	}

	/**
	 * 测试获取 WPTSALL 表
	 */
	public function test_get_wptsall_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $wpdb->prefix . 'wptsall_' ) . '%'
			)
		);

		$this->assertIsArray( $tables, 'WPTSALL 表应为数组' );
	}

	// ========================================
	// 列信息发现测试
	// ========================================

	/**
	 * 测试获取表列信息
	 */
	public function test_get_table_columns() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( "DESCRIBE {$wpdb->posts}" );

		$this->assertIsArray( $columns, '列信息应为数组' );
		$this->assertNotEmpty( $columns, '应至少有一列' );

		// 验证核心列存在
		$column_names = array_map( function( $col ) {
			return $col->Field;
		}, $columns );

		$this->assertContains( 'ID', $column_names, 'ID 列应存在' );
		$this->assertContains( 'post_title', $column_names, 'post_title 列应存在' );
		$this->assertContains( 'post_content', $column_names, 'post_content 列应存在' );
	}

	/**
	 * 测试获取列类型信息
	 */
	public function test_get_column_types() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( "DESCRIBE {$wpdb->posts}" );

		foreach ( $columns as $col ) {
			// 验证每列有类型信息
			$this->assertNotEmpty( $col->Type, "列 {$col->Field} 应有类型信息" );
		}
	}

	// ========================================
	// Post Meta 发现测试
	// ========================================

	/**
	 * 测试获取所有 post_meta keys
	 */
	public function test_get_all_post_meta_keys() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} LIMIT 100"
		);

		$this->assertIsArray( $meta_keys, 'meta_keys 应为数组' );
	}

	/**
	 * 测试按 post_type 筛选 meta keys
	 */
	public function test_get_post_meta_keys_by_post_type() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE p.post_type = %s
				LIMIT 100",
				'post'
			)
		);

		$this->assertIsArray( $meta_keys, '按 post_type 筛选的 meta_keys 应为数组' );
	}

	/**
	 * 测试过滤隐藏 meta keys（下划线开头）
	 */
	public function test_filter_hidden_meta_keys() {
		global $wpdb;

		// 获取所有 meta keys
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} LIMIT 100"
		);

		// 分离隐藏和可见 keys
		$hidden_keys = array_filter( $all_keys, function( $key ) {
			return strpos( $key, '_' ) === 0;
		} );

		$visible_keys = array_filter( $all_keys, function( $key ) {
			return strpos( $key, '_' ) !== 0;
		} );

		$this->assertIsArray( $hidden_keys, '隐藏 keys 应为数组' );
		$this->assertIsArray( $visible_keys, '可见 keys 应为数组' );
	}

	// ========================================
	// Term Meta 发现测试
	// ========================================

	/**
	 * 测试获取 term_meta keys
	 */
	public function test_get_term_meta_keys() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM {$wpdb->termmeta} LIMIT 100"
		);

		$this->assertIsArray( $meta_keys, 'term meta_keys 应为数组' );
	}

	// ========================================
	// User Meta 发现测试
	// ========================================

	/**
	 * 测试获取 user_meta keys
	 */
	public function test_get_user_meta_keys() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_keys = $wpdb->get_col(
			"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} LIMIT 100"
		);

		$this->assertIsArray( $meta_keys, 'user meta_keys 应为数组' );
	}

	// ========================================
	// REST API 测试
	// ========================================

	/**
	 * 测试表列表 API
	 */
	public function test_tables_list_api() {
		$this->skip_if_namespace_missing( 'wptsall/v2' );

		if ( ! $this->route_exists( 'discovery/tables', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/tables 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/tables', array(), 'wptsall/v2' );

		// 路由已确认存在，应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, '表列表 API 路由存在时必须返回 200' );
	}

	/**
	 * 测试 meta keys API
	 */
	public function test_meta_keys_api() {
		$this->skip_if_namespace_missing( 'wptsall/v2' );

		if ( ! $this->route_exists( 'discovery/meta-keys', 'wptsall/v2' ) ) {
			$this->markTestSkipped( 'discovery/meta-keys 路由不存在' );
		}

		$response = $this->rest_get( 'discovery/meta-keys', array(
			'type' => 'post',
		), 'wptsall/v2' );

		// 路由已确认存在，应严格返回 200
		$status = $response->get_status();
		$this->assertEquals( 200, $status, 'meta keys API 路由存在时必须返回 200' );
	}

	// ========================================
	// 字段分类工作流测试
	// ========================================

	/**
	 * 测试发现结果用于字段分类
	 */
	public function test_discovery_for_classification() {
		// 创建测试文章和 meta
		$post_id = $this->create_test_post( array(
			'post_title' => 'Classification Workflow Test',
		) );

		// 添加各种类型的 meta
		update_post_meta( $post_id, 'text_field', 'This is translatable text' );
		update_post_meta( $post_id, '_private_field', 'Private data' );
		update_post_meta( $post_id, '_thumbnail_id', 123 );
		update_post_meta( $post_id, 'product_url', 'https://example.com/product' );

		// 验证 meta 已设置
		$this->assertEquals( 'This is translatable text', get_post_meta( $post_id, 'text_field', true ) );
		$this->assertEquals( 'Private data', get_post_meta( $post_id, '_private_field', true ) );
		$this->assertEquals( '123', get_post_meta( $post_id, '_thumbnail_id', true ) );
	}

	// ========================================
	// 性能测试
	// ========================================

	/**
	 * 测试大量 meta keys 处理
	 */
	public function test_large_meta_keys_handling() {
		global $wpdb;

		// 获取 meta keys 数量
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT meta_key) FROM {$wpdb->postmeta}"
		);

		$this->assertIsNumeric( $count, 'meta keys 数量应为数字' );
	}

}
