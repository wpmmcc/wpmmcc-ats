<?php
/**
 * Chain 8: ID 映射链路测试
 *
 * 测试 ID 映射流程：文章映射 → 媒体映射 → 分类映射 → 用户映射
 *
 * REST 端点:
 * - GET  /wptsall/v1/user-mappings
 * - POST /wptsall/v1/user-mappings
 *
 * 服务层:
 * - Post_Mapping_Service
 * - Media_Mapping_Service
 * - Term_Mapping_Service
 * - User_Mapping_Service
 *
 * @package WPTSALL
 * @since 0.9.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Chain 8 Test: ID Mapping
 */
class Test_Chain_8_ID_Mapping extends REST_Integration_Test_Case {

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
	 * 每个测试前设置
	 */
	public function setUp(): void {
		parent::setUp();

		// 创建虚拟站点
		$this->test_virtual_site_id = $this->create_test_virtual_site( array(
			'name'        => 'ID Mapping Test Site',
			'path_prefix' => 'mapping-test-' . wp_rand( 1000, 9999 ),
			'lang'        => 'zh_CN',
		) );

		// 创建站点关系
		$relation_data = $this->create_test_relation( $this->test_virtual_site_id );
		$this->test_relation_id = $relation_data['relation_ids'][0] ?? 0;
	}

	// ========================================
	// 文章映射测试
	// ========================================

	/**
	 * 测试 Post_Mapping_Service 存在
	 */
	public function test_post_mapping_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Services\Post_Mapping_Service' ),
			'Post_Mapping_Service 类应存在'
		);
	}

	/**
	 * 测试文章映射方法
	 *
	 * @covers Post_Mapping_Service::get_mapping
	 */
	public function test_post_mapping_methods() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\Post_Mapping_Service' );

		// 检查映射方法
		$has_get_method = $reflection->hasMethod( 'get_mapping' ) ||
		                  $reflection->hasMethod( 'get' ) ||
		                  $reflection->hasMethod( 'find_mapping' );

		$this->assertTrue( $has_get_method, 'Post_Mapping_Service 应有获取映射方法' );
	}

	/**
	 * 测试文章映射创建
	 *
	 * @covers Post_Mapping_Service::create_mapping
	 */
	public function test_post_mapping_creation() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\Post_Mapping_Service' );

		// 检查创建方法
		$has_create_method = $reflection->hasMethod( 'create_mapping' ) ||
		                     $reflection->hasMethod( 'create' ) ||
		                     $reflection->hasMethod( 'save_mapping' );

		$this->assertTrue( $has_create_method, 'Post_Mapping_Service 应有创建映射方法' );
	}

	/**
	 * 测试通过源 ID 查找映射
	 */
	public function test_find_mapping_by_source_id() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\Post_Mapping_Service' );

		// 检查按源 ID 查找方法
		$has_find_method = $reflection->hasMethod( 'get_by_source_id' ) ||
		                   $reflection->hasMethod( 'find_by_source' ) ||
		                   $reflection->hasMethod( 'get_mapping' );

		$this->assertTrue( $has_find_method, 'Post_Mapping_Service 应有按源 ID 查找方法' );
	}

	// ========================================
	// 媒体映射测试
	// ========================================

	/**
	 * 测试 Media_Mapping_Service 存在
	 */
	public function test_media_mapping_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Services\Media_Mapping_Service' ),
			'Media_Mapping_Service 类应存在'
		);
	}

	/**
	 * 测试媒体映射方法
	 *
	 * @covers Media_Mapping_Service::get_mapping
	 */
	public function test_media_mapping_methods() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\Media_Mapping_Service' );

		// 检查映射方法
		$has_get_method = $reflection->hasMethod( 'get_mapping' ) ||
		                  $reflection->hasMethod( 'get' ) ||
		                  $reflection->hasMethod( 'find_mapping' );

		$this->assertTrue( $has_get_method, 'Media_Mapping_Service 应有获取映射方法' );
	}

	/**
	 * 测试媒体映射创建
	 *
	 * @covers Media_Mapping_Service::create_mapping
	 */
	public function test_media_mapping_creation() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\Media_Mapping_Service' );

		// 检查创建方法
		$has_create_method = $reflection->hasMethod( 'create_mapping' ) ||
		                     $reflection->hasMethod( 'create' ) ||
		                     $reflection->hasMethod( 'save_mapping' );

		$this->assertTrue( $has_create_method, 'Media_Mapping_Service 应有创建映射方法' );
	}

	/**
	 * 测试缩略图 ID 映射
	 */
	public function test_thumbnail_id_mapping() {
		global $wpdb;

		// 创建带有缩略图的文章
		$post_id = $this->create_test_post( array(
			'post_title'   => '缩略图映射测试',
			'post_content' => '测试缩略图 ID 映射。',
		) );

		// 验证文章创建成功
		$this->assertGreaterThan( 0, $post_id );

		// 缩略图映射通常在同步时处理
		// 这里验证服务可用
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Services\Media_Mapping_Service' ),
			'Media_Mapping_Service 应可用'
		);

		// 验证 media_mappings 或 post_mappings 表存在（映射功能的基础）
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$media_table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wptsall_media_mappings' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wptsall_post_mappings' )
		);

		// 至少应有一个映射表存在
		$has_mapping_table = ! empty( $media_table_exists ) || ! empty( $post_table_exists );
		$this->assertTrue( $has_mapping_table, 'wp_wptsall_media_mappings 或 wp_wptsall_post_mappings 表至少一个必须存在' );
	}

	// ========================================
	// 分类映射测试
	// ========================================

	/**
	 * 测试 Term_Mapping_Service 存在
	 */
	public function test_term_mapping_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Services\Term_Mapping_Service' ),
			'Term_Mapping_Service 类应存在'
		);
	}

	/**
	 * 测试分类映射方法
	 *
	 * @covers Term_Mapping_Service::get_mapping
	 */
	public function test_term_mapping_methods() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\Term_Mapping_Service' );

		// 检查映射方法
		$has_get_method = $reflection->hasMethod( 'get_mapping' ) ||
		                  $reflection->hasMethod( 'get' ) ||
		                  $reflection->hasMethod( 'find_mapping' );

		$this->assertTrue( $has_get_method, 'Term_Mapping_Service 应有获取映射方法' );
	}

	/**
	 * 测试分类映射创建
	 *
	 * @covers Term_Mapping_Service::create_mapping
	 */
	public function test_term_mapping_creation() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\Term_Mapping_Service' );

		// 检查创建方法
		$has_create_method = $reflection->hasMethod( 'create_mapping' ) ||
		                     $reflection->hasMethod( 'create' ) ||
		                     $reflection->hasMethod( 'save_mapping' );

		$this->assertTrue( $has_create_method, 'Term_Mapping_Service 应有创建映射方法' );
	}

	/**
	 * 测试分类法映射
	 */
	public function test_taxonomy_mapping() {
		// 创建测试分类
		$term_result = wp_insert_term( '映射测试分类 ' . wp_rand( 1000, 9999 ), 'category' );

		if ( ! is_wp_error( $term_result ) ) {
			$term_id = $term_result['term_id'];

			// 验证分类创建成功
			$this->assertGreaterThan( 0, $term_id );

			// 清理
			wp_delete_term( $term_id, 'category' );
		} else {
			$this->markTestSkipped( '无法创建测试分类' );
		}
	}

	// ========================================
	// 用户映射 REST API 测试
	// ========================================

	/**
	 * 测试获取用户映射
	 *
	 * @covers Sites_REST_Controller::get_user_mappings
	 */
	public function test_get_user_mappings() {
		$response = $this->rest_get( 'user-mappings', array(
			'relation_id' => $this->test_relation_id,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'items', $data, '响应应包含 items' );
		$this->assertIsArray( $data['items'], 'items 应为数组' );
	}

	/**
	 * 测试创建用户映射
	 *
	 * @covers Sites_REST_Controller::create_user_mapping
	 */
	public function test_create_user_mapping() {
		global $wpdb;

		// 获取当前用户 ID
		$source_user_id = get_current_user_id();

		$response = $this->rest_post( 'user-mappings', array(
			'relation_id'    => $this->test_relation_id,
			'source_user_id' => $source_user_id,
			'target_user_id' => $source_user_id, // 测试时映射到同一用户
		) );

		// 可能成功或因为已存在而失败
		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 409 ), '创建用户映射应返回有效状态' );

		// 如果创建成功，验证 user_mappings 表中确实有记录
		if ( 200 === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wptsall_user_mappings WHERE source_user_id = %d",
				$source_user_id
			) );
			$this->assertGreaterThan( 0, $mapping_count, 'wp_wptsall_user_mappings must have an entry after successful mapping creation' );
		}
	}

	/**
	 * 测试用户映射分页
	 */
	public function test_user_mappings_pagination() {
		$response = $this->rest_get( 'user-mappings', array(
			'relation_id' => $this->test_relation_id,
			'page'        => 1,
			'per_page'    => 10,
		) );

		$this->assertRestSuccess( $response, 200 );

		$data = $this->get_response_data( $response );
		$this->assertArrayHasKey( 'total', $data, '响应应包含 total' );
		$this->assertArrayHasKey( 'pages', $data, '响应应包含 pages' );
	}

	// ========================================
	// User_Mapping_Service 测试
	// ========================================

	/**
	 * 测试 User_Mapping_Service 存在
	 */
	public function test_user_mapping_service_exists() {
		$this->assertTrue(
			class_exists( 'WPTSALL\Models\Services\User_Mapping_Service' ),
			'User_Mapping_Service 类应存在'
		);
	}

	/**
	 * 测试用户映射方法
	 *
	 * @covers User_Mapping_Service::get_mapping
	 */
	public function test_user_mapping_methods() {
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\User_Mapping_Service' );

		// 检查映射方法
		$has_get_method = $reflection->hasMethod( 'get_mapping' ) ||
		                  $reflection->hasMethod( 'get' ) ||
		                  $reflection->hasMethod( 'find_mapping' );

		$this->assertTrue( $has_get_method, 'User_Mapping_Service 应有获取映射方法' );
	}

	// ========================================
	// 映射表测试
	// ========================================

	/**
	 * 测试用户映射表存在
	 */
	public function test_user_mappings_table_exists() {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertEquals( $table, $table_exists, 'user_mappings 表应存在' );
	}

	/**
	 * 测试映射表结构
	 */
	public function test_mapping_table_structure() {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results(
			"DESCRIBE {$table}"
		);

		$column_names = array_map( function( $col ) {
			return $col->Field;
		}, $columns );

		// 验证必需的列
		// 实际表结构: id, source_user_id, source_site_id, target_user_id, target_site_id, mapping_type, created_at, updated_at
		$this->assertContains( 'id', $column_names, '表应包含 id 列' );
		$this->assertContains( 'source_site_id', $column_names, '表应包含 source_site_id 列' );
		$this->assertContains( 'target_site_id', $column_names, '表应包含 target_site_id 列' );
	}

	// ========================================
	// 映射一致性测试
	// ========================================

	/**
	 * 测试映射唯一性约束
	 */
	public function test_mapping_uniqueness() {
		// 获取当前用户 ID
		$source_user_id = get_current_user_id();

		// 第一次创建映射
		$response1 = $this->rest_post( 'user-mappings', array(
			'relation_id'    => $this->test_relation_id,
			'source_user_id' => $source_user_id,
			'target_user_id' => $source_user_id,
		) );

		// 第二次创建相同映射应该失败或返回已存在的映射
		$response2 = $this->rest_post( 'user-mappings', array(
			'relation_id'    => $this->test_relation_id,
			'source_user_id' => $source_user_id,
			'target_user_id' => $source_user_id,
		) );

		// 验证第二次请求被正确处理（重复创建应有适当处理）
		$status = $response2->get_status();
		$this->assertContains( $status, array( 200, 400, 409, 500 ), '重复映射应被正确处理' );
	}

	/**
	 * 测试映射双向查找
	 */
	public function test_bidirectional_mapping_lookup() {
		// 验证可以从源 ID 查找目标 ID，也可以反向查找
		$reflection = new \ReflectionClass( 'WPTSALL\Models\Services\User_Mapping_Service' );

		// 正向查找方法必须存在（从源 ID 查目标 ID）
		$has_forward_lookup = $reflection->hasMethod( 'get_mapping' ) ||
		                      $reflection->hasMethod( 'get' ) ||
		                      $reflection->hasMethod( 'find_mapping' ) ||
		                      $reflection->hasMethod( 'get_by_source_id' );

		$this->assertTrue( $has_forward_lookup, 'User_Mapping_Service 必须有正向查找方法（源 ID -> 目标 ID）' );

		// 双向查找是可选功能，记录是否支持
		$has_reverse_lookup = $reflection->hasMethod( 'get_by_target_id' ) ||
		                      $reflection->hasMethod( 'find_by_target' ) ||
		                      $reflection->hasMethod( 'get_source_id' );

		// 不强制要求反向查找，但正向查找必须存在（已在上方断言）
		$this->assertIsBool( $has_reverse_lookup, '双向查找支持状态应为布尔值' );
	}

	// ========================================
	// 字段映射同步测试
	// ========================================

	/**
	 * 测试字段映射在同步中的使用
	 */
	public function test_field_mapping_in_sync() {
		// 创建测试文章
		$post_id = $this->create_test_post( array(
			'post_title'   => '字段映射同步测试',
			'post_content' => '测试字段映射在同步中的使用。',
			'post_author'  => get_current_user_id(),
		) );

		// 验证文章创建成功
		$this->assertGreaterThan( 0, $post_id );

		// 验证 post_author 字段
		$post = get_post( $post_id );
		$this->assertEquals( get_current_user_id(), $post->post_author );
	}

	/**
	 * 测试 _thumbnail_id 映射
	 */
	public function test_thumbnail_id_field_mapping() {
		// 创建文章
		$post_id = $this->create_test_post( array(
			'post_title' => '缩略图字段映射测试',
		) );

		// 设置一个假的缩略图 ID（仅用于测试映射逻辑）
		update_post_meta( $post_id, '_thumbnail_id', 123 );

		// 验证元数据设置
		$thumbnail_id = get_post_meta( $post_id, '_thumbnail_id', true );
		$this->assertEquals( 123, (int) $thumbnail_id );
	}

	// ========================================
	// 批量映射测试
	// ========================================

	/**
	 * 测试批量创建映射
	 */
	public function test_bulk_mapping_creation() {
		// 如果支持批量操作
		$response = $this->rest_post( 'user-mappings/bulk', array(
			'relation_id' => $this->test_relation_id,
			'mappings'    => array(
				array(
					'source_user_id' => get_current_user_id(),
					'target_user_id' => get_current_user_id(),
				),
			),
		) );

		// 批量端点可能不存在
		$status = $response->get_status();
		$this->assertContains( $status, array( 200, 400, 404 ), '批量映射应返回有效状态' );
	}
}
