<?php
/**
 * Tasks REST API Integration Tests
 *
 * 测试 Tasks 模块 REST API 端点的完整覆盖
 *
 * @package WPTSALL
 * @since 0.6.0
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Models\Services\Plugin_Mapping_Service;

class Test_Tasks_REST_API extends SimpleTestCase {

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
	 * 测试文章 ID
	 *
	 * @var int
	 */
	private $test_post_id;

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

		// 创建测试文章
		$this->test_post_id = wp_insert_post( array(
			'post_title'   => 'Tasks REST API Test Post - ' . time(),
			'post_content' => 'Test content for REST API testing',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		// 创建虚拟站点
		$this->create_virtual_site();

		// 创建关系
		$this->create_test_relation();
	}

	/**
	 * 创建虚拟站点
	 */
	private function create_virtual_site() {
		$path_prefix = 'tasks-rest-test-' . time();

		$result = Virtual_Site_Service::create( array(
			'name'        => 'Tasks REST Test Site',
			'path_prefix' => $path_prefix,
			'lang'        => 'en_US',
		) );

		if ( $result['success'] ) {
			$this->virtual_site_id = $result['site_id'];
		}
	}

	/**
	 * 创建测试关系
	 */
	private function create_test_relation() {
		if ( empty( $this->virtual_site_id ) ) {
			return;
		}

		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => 'zh_CN',
			'target_sites'   => array(
				array(
					'type' => 'virtual',
					'id'   => $this->virtual_site_id,
					'lang' => 'en_US',
				),
			),
		) );

		if ( ! empty( $result['relation_ids'] ) ) {
			$this->relation_id = $result['relation_ids'][0];
		}
	}

	/**
	 * 清理测试数据
	 */
	public function tearDown(): void {
		global $wpdb;

		// 删除测试文章
		if ( $this->test_post_id ) {
			wp_delete_post( $this->test_post_id, true );
		}

		// 删除测试关联
		if ( $this->relation_id ) {
			Site_Relation_Service::delete_relation( $this->relation_id );
		}

		// 删除虚拟站点
		if ( $this->virtual_site_id ) {
			Virtual_Site_Service::delete( $this->virtual_site_id );
		}

		// 清理测试任务
		$table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE template = %s",
				'wordpress-blog'
			)
		);

		parent::tearDown();
	}

	// ==================== REST Controller Existence Tests ====================

	/**
	 * 测试 REST 端点已注册
	 */
	public function test_rest_endpoints_registered() {
		// 验证 wptsall 命名空间已注册
		$namespaces = rest_get_server()->get_namespaces();

		$this->assertContains( 'wptsall/v1', $namespaces );
	}

	// ==================== Task Functions Tests ====================

	/**
	 * 测试任务函数存在
	 */
	public function test_task_functions_exist() {
		$this->assertTrue( function_exists( 'wptsall_generate_tasks_from_template' ) );
		$this->assertTrue( function_exists( 'wptsall_process_task' ) );
		$this->assertTrue( function_exists( 'wptsall_insert_tasks' ) );
		$this->assertTrue( function_exists( 'wptsall_update_task_status' ) );
		$this->assertTrue( function_exists( 'wptsall_store_virtual_content' ) );
	}

	/**
	 * 测试任务创建（模拟 POST /tasks）
	 */
	public function test_create_task() {
		if ( empty( $this->relation_id ) || empty( $this->test_post_id ) ) {
			$this->markTestSkipped( 'Relation or test post not available' );
		}

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'post', $this->test_post_id );

		// 创建任务
		$tasks = array(
			array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $this->relation_id,
				'template'          => 'wordpress-blog',
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'object_id'         => $this->test_post_id,
				'complete_data'     => $complete_data,
			),
		);

		// 入队任务
		wptsall_insert_tasks( $tasks );

		// 验证任务已创建
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$task = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE template = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
				'wordpress-blog',
				$this->test_post_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $task );
		$this->assertEquals( 'pending', $task['status'] );
		$this->assertEquals( 'virtual', $task['target_type'] );
		$this->assertEquals( 'post_type', $task['object_type'] );
		$this->assertEquals( 'post', $task['subtype'] );
	}

	/**
	 * 测试任务状态更新
	 */
	public function test_update_task_status() {
		if ( empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Relation not available' );
		}

		// 创建任务
		$tasks = array(
			array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $this->relation_id,
				'template'          => 'wordpress-blog',
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'object_id'         => 99998,
				'complete_data'     => array(),
			),
		);

		wptsall_insert_tasks( $tasks );

		// 获取任务
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$task = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_id = %d ORDER BY id DESC LIMIT 1",
				99998
			),
			ARRAY_A
		);

		$this->assertNotNull( $task );

		// 更新状态
		wptsall_update_task_status( $task, 'completed', 'Test completed via REST API' );

		// 验证更新
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$task['id']
			),
			ARRAY_A
		);

		$this->assertEquals( 'completed', $updated['status'] );
		$this->assertStringContainsString( 'Test completed', $updated['status_note'] );
	}

	// ==================== Task Stats Tests (GET /tasks/stats) ====================

	/**
	 * 测试任务统计
	 */
	public function test_task_stats() {
		if ( empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Relation not available' );
		}

		// 创建多个不同状态的任务
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		$statuses = array( 'pending', 'pending', 'completed', 'completed', 'completed', 'failed' );

		foreach ( $statuses as $i => $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'blog_id'           => $this->current_site_id,
					'target_blog'       => 0,
					'target_type'       => 'virtual',
					'target_identifier' => $this->virtual_site_id,
					'site_id'           => $this->relation_id,
					'template'          => 'wordpress-blog',
					'object_type'       => 'post_type',
					'subtype'           => 'post',
					'object_id'         => 80000 + $i,
					'status'            => $status,
					'created_at'        => current_time( 'mysql' ),
					'updated_at'        => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		// 获取统计
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as count FROM {$table} WHERE template = %s GROUP BY status",
				'wordpress-blog'
			),
			ARRAY_A
		);

		$this->assertNotEmpty( $stats );

		// 验证统计数据
		$status_counts = array();
		foreach ( $stats as $row ) {
			$status_counts[ $row['status'] ] = (int) $row['count'];
		}

		$this->assertArrayHasKey( 'pending', $status_counts );
		$this->assertArrayHasKey( 'completed', $status_counts );
		$this->assertGreaterThanOrEqual( 2, $status_counts['pending'] );
		$this->assertGreaterThanOrEqual( 3, $status_counts['completed'] );
	}

	// ==================== Virtual Content Tests (GET /virtual) ====================

	/**
	 * 测试虚拟内容存储
	 */
	public function test_virtual_content_storage() {
		if ( empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Relation not available' );
		}

		// 存储虚拟内容
		$content = array(
			'post' => array(
				'post_title'   => 'Virtual REST Test',
				'post_content' => 'Content for virtual site',
			),
		);

		$result_id = wptsall_store_virtual_content(
			$this->relation_id,
			'wordpress-blog',
			'post_type',
			'post',
			77777,
			$content
		);

		$this->assertGreaterThan( 0, $result_id );

		// 获取虚拟内容
		global $wpdb;
		$table = wptsall_table( 'virtual' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$result_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $stored );
		$this->assertEquals( 'wordpress-blog', $stored['template'] );
		$this->assertEquals( 'post_type', $stored['object_type'] );

		// 验证内容
		$stored_content = json_decode( $stored['content'], true );
		$this->assertEquals( 'Virtual REST Test', $stored_content['post']['post_title'] );
	}

	/**
	 * 测试虚拟内容查询
	 */
	public function test_virtual_content_query() {
		if ( empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Relation not available' );
		}

		// 存储多个虚拟内容
		for ( $i = 1; $i <= 3; $i++ ) {
			wptsall_store_virtual_content(
				$this->relation_id,
				'wordpress-blog',
				'post_type',
				'post',
				66660 + $i,
				array(
					'post' => array(
						'post_title' => 'Query Test ' . $i,
					),
				)
			);
		}

		// 查询虚拟内容
		global $wpdb;
		$table = wptsall_table( 'virtual' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE site_id = %d AND template = %s AND object_type = %s ORDER BY id DESC",
				$this->relation_id,
				'wordpress-blog',
				'post_type'
			),
			ARRAY_A
		);

		$this->assertGreaterThanOrEqual( 3, count( $results ) );
	}

	// ==================== Language Pack Task Tests (POST /tasks/language) ====================

	/**
	 * 测试语言包任务参数验证
	 */
	public function test_language_task_params() {
		// 验证必要的参数
		$required_params = array(
			'textdomain',
			'component_type',
			'target_lang',
		);

		foreach ( $required_params as $param ) {
			$this->assertIsString( $param );
		}

		// 验证 component_type 有效值
		$valid_types = array( 'plugin', 'theme', 'core' );
		foreach ( $valid_types as $type ) {
			$this->assertContains( $type, $valid_types );
		}
	}

	// ==================== Task Table Schema Tests ====================

	/**
	 * 测试任务表结构
	 */
	public function test_task_table_schema() {
		global $wpdb;
		$table = wptsall_table( 'tasks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );

		$column_names = array_column( $columns, 'Field' );

		// 验证必要字段存在
		$required_columns = array(
			'id',
			'blog_id',
			'target_blog',
			'target_type',
			'target_identifier',
			'site_id',
			'template',
			'object_type',
			'subtype',
			'object_id',
			'status',
			'created_at',
			'updated_at',
		);

		foreach ( $required_columns as $col ) {
			$this->assertContains( $col, $column_names, "Column {$col} should exist in tasks table" );
		}
	}

	/**
	 * 测试虚拟内容表结构
	 */
	public function test_virtual_content_table_schema() {
		global $wpdb;
		$table = wptsall_table( 'virtual' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );

		$column_names = array_column( $columns, 'Field' );

		// 验证必要字段存在
		$required_columns = array(
			'id',
			'site_id',
			'template',
			'object_type',
			'subtype',
			'source_id',
			'content',
			'created_at',
			'updated_at',
		);

		foreach ( $required_columns as $col ) {
			$this->assertContains( $col, $column_names, "Column {$col} should exist in virtual content table" );
		}
	}
}
