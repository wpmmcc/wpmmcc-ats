<?php
/**
 * Sync Tasks Integration Tests
 *
 * 测试同步任务的完整流程（单站点环境，使用虚拟站点）
 *
 * @package WPTSALL
 * @since 0.3.0
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Models\Blog_Template;

/**
 * 注意：此测试已转换为使用 SimpleTestCase 以便在主测试套件中运行
 * 不再依赖 WP_UnitTestCase 和 WordPress 测试库
 */
class Test_Sync_Tasks extends SimpleTestCase {

	/**
	 * 测试文章 IDs
	 *
	 * @var array
	 */
	private $test_post_ids = array();

	/**
	 * 测试分类 IDs
	 *
	 * @var array
	 */
	private $test_term_ids = array();

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

		// 创建测试数据
		$this->create_test_data();

		// 创建测试虚拟站点
		$this->create_virtual_site();

		// 创建测试关联
		$this->create_test_relation();
	}

	/**
	 * 创建测试数据
	 */
	private function create_test_data() {
		// 创建测试分类
		$term_result = wp_insert_term( 'Test Category ' . time(), 'category' );
		if ( ! is_wp_error( $term_result ) ) {
			$this->test_term_ids[] = $term_result['term_id'];
		}

		// 创建测试标签
		$tag_result = wp_insert_term( 'Test Tag ' . time(), 'post_tag' );
		if ( ! is_wp_error( $tag_result ) ) {
			$this->test_term_ids[] = $tag_result['term_id'];
		}

		// 创建测试文章
		for ( $i = 1; $i <= 3; $i++ ) {
			$post_id = wp_insert_post(
				array(
					'post_title'   => 'Integration Test Post ' . $i . ' - ' . time(),
					'post_content' => 'This is test post content ' . $i,
					'post_status'  => 'publish',
					'post_type'    => 'post',
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				// 添加分类和标签
				if ( ! empty( $this->test_term_ids ) ) {
					wp_set_post_categories( $post_id, array( $this->test_term_ids[0] ) );
				}

				// 添加自定义字段
				update_post_meta( $post_id, 'custom_field', 'custom_value_' . $i );
				update_post_meta( $post_id, '_integration_test', 'true' );

				$this->test_post_ids[] = $post_id;
			}
		}
	}

	/**
	 * 创建测试虚拟站点
	 *
	 * 注意：v0.4.0 起 wp_wptsall_virtual_sites 表已废弃
	 * 虚拟站点信息合并到 site_relations 表，通过 target_site_type='virtual' 标识
	 */
	private function create_virtual_site() {
		// 生成虚拟站点标识（字符串格式）
		$this->virtual_site_id = 'test-sync-virtual-' . time();
	}

	/**
	 * 创建测试关联
	 *
	 * v0.4.0 API 要求：
	 * - source_lang: 源语言（必填）
	 * - target_sites[].lang: 目标语言（必填）
	 * - 返回值使用 relation_ids 数组
	 */
	private function create_test_relation() {
		if ( empty( $this->virtual_site_id ) ) {
			return;
		}

		$data = array(
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
		);

		$result = Site_Relation_Service::create_relation( $data );
		if ( ! empty( $result['relation_ids'] ) ) {
			$this->relation_id = $result['relation_ids'][0];
		}
	}

	/**
	 * 清理测试
	 *
	 * v0.4.0+: 虚拟站点信息已合并到 site_relations 表
	 * 删除关联会同时清理虚拟站点数据
	 */
	public function tearDown(): void {
		global $wpdb;

		// 删除测试文章
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// 删除测试分类和标签
		foreach ( $this->test_term_ids as $term_id ) {
			wp_delete_term( $term_id, 'category' );
			wp_delete_term( $term_id, 'post_tag' );
		}

		// 删除测试关联（使用 Service 层 API）
		if ( $this->relation_id ) {
			Site_Relation_Service::delete_relation( $this->relation_id );
		}

		// 注意：v0.4.0+ 虚拟站点信息已合并到 site_relations 表
		// 删除关联时会自动清理相关数据，无需单独删除 wptsall_virtual_sites

		// 清空任务队列中的测试任务
		$table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $table_exists === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE template = %s",
					'wordpress-blog'
				)
			);
		}

		// 清空虚拟站点内容 (v0.7.0+ 使用新表)
		$table = wptsall_table( 'virtual_site_content' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $table_exists === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE subtype = %s",
					'post'
				)
			);
		}

		parent::tearDown();
	}

	/**
	 * 测试任务函数存在
	 */
	public function test_task_functions_exist() {
		$this->assertTrue( function_exists( 'wptsall_generate_tasks_from_template' ) );
		$this->assertTrue( function_exists( 'wptsall_process_task' ) );
		$this->assertTrue( function_exists( 'wptsall_insert_tasks' ) );
	}

	/**
	 * 测试获取插件映射配置 (V2 API)
	 *
	 * 使用 Plugin_Mapping_Service::get_by_slug() 替代已废弃的 wptsall_get_saved_template()
	 *
	 * @since 0.6.0
	 */
	public function test_get_plugin_mapping() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Plugin_Mapping_Service' ) );
		$this->assertTrue( method_exists( 'WPTSALL\Models\Services\Plugin_Mapping_Service', 'get_by_slug' ) );

		// 使用 Plugin_Mapping_Service 获取插件映射
		$result = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );

		// 如果存在映射，验证结构
		if ( $result ) {
			$this->assertIsArray( $result );
			// V2 API 返回的结构包含 plugin_slug 而不是 plugin
			$this->assertArrayHasKey( 'plugin_slug', $result );
		} else {
			// 如果不存在，测试创建新映射
			$create_result = \WPTSALL\Models\Services\Plugin_Mapping_Service::save( array(
				'plugin_slug' => 'wordpress-blog',
				'plugin_name' => 'WordPress Blog',
				'post_types'  => array( 'post', 'page' ),
				'taxonomies'  => array( 'category', 'post_tag' ),
			) );

			$this->assertNotFalse( $create_result );

			// 再次获取验证
			$result = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
			$this->assertIsArray( $result );
			$this->assertEquals( 'wordpress-blog', $result['plugin_slug'] );
		}
	}

	/**
	 * 测试任务生成
	 */
	public function test_generate_tasks_from_template() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		if ( empty( $this->test_post_ids ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		// 创建模板
		$template = array(
			'plugin'  => 'wordpress-blog',
			'objects' => array(
				'post_types' => array(
					array( 'subtype' => 'post' ),
				),
			),
		);

		// 获取关联
		$relation = Site_Relation_Service::get_relation( $this->relation_id );
		$this->assertNotNull( $relation );

		// 构建站点关联结构
		// v0.4.0: get_relation 返回扁平数据，需手动构造 targets 数组
		$site_rel = array(
			'id'       => $relation['id'],
			'template' => 'wordpress-blog',
			'source'   => array(
				'type' => 'wp',
				'id'   => $this->current_site_id,
			),
			'targets'  => array(
				array(
					'type' => $relation['target_site_type'],
					'id'   => $relation['target_site_id'],
					'lang' => $relation['target_lang'],
				),
			),
		);

		// 生成任务（采样 2 条）
		$tasks = wptsall_generate_tasks_from_template( $template, 2, $site_rel );

		$this->assertIsArray( $tasks );

		// 如果有任务生成，验证结构
		if ( ! empty( $tasks ) ) {
			$task = $tasks[0];

			$this->assertArrayHasKey( 'blog_id', $task );
			$this->assertArrayHasKey( 'target_type', $task );
			$this->assertArrayHasKey( 'object_type', $task );
			$this->assertArrayHasKey( 'subtype', $task );
			$this->assertArrayHasKey( 'object_id', $task );
			$this->assertArrayHasKey( 'complete_data', $task );

			$this->assertEquals( $this->current_site_id, $task['blog_id'] );
			$this->assertEquals( 'virtual', $task['target_type'] );
			$this->assertEquals( 'post_type', $task['object_type'] );
			$this->assertEquals( 'post', $task['subtype'] );
		}
	}

	/**
	 * 测试任务数据完整性
	 */
	public function test_task_data_completeness() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		if ( empty( $this->test_post_ids ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$template = array(
			'plugin'  => 'wordpress-blog',
			'objects' => array(
				'post_types' => array(
					array( 'subtype' => 'post' ),
				),
			),
		);

		$relation = Site_Relation_Service::get_relation( $this->relation_id );
		// v0.4.0: get_relation 返回扁平数据，需手动构造 targets 数组
		$site_rel = array(
			'id'       => $relation['id'],
			'template' => 'wordpress-blog',
			'source'   => array(
				'type' => 'wp',
				'id'   => $this->current_site_id,
			),
			'targets'  => array(
				array(
					'type' => $relation['target_site_type'],
					'id'   => $relation['target_site_id'],
					'lang' => $relation['target_lang'],
				),
			),
		);

		$tasks = wptsall_generate_tasks_from_template( $template, 1, $site_rel );

		if ( empty( $tasks ) ) {
			$this->markTestSkipped( 'No tasks generated' );
		}

		$task = $tasks[0];

		// 验证 complete_data 结构
		$this->assertArrayHasKey( 'complete_data', $task );
		$complete_data = $task['complete_data'];

		$this->assertIsArray( $complete_data );
		$this->assertArrayHasKey( 'post', $complete_data );

		// 验证文章数据
		$post = $complete_data['post'];
		$this->assertArrayHasKey( 'post_title', $post );
		$this->assertArrayHasKey( 'post_content', $post );
		$this->assertArrayHasKey( 'post_status', $post );
	}

	/**
	 * 测试任务入队
	 */
	public function test_insert_tasks() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建测试任务
		$tasks = array(
			array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $this->relation_id ?: 1,
				'template'          => 'wordpress-blog',
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'object_id'         => $this->test_post_ids[0] ?? 1,
				'complete_data'     => array(
					'post' => array(
						'post_title'   => 'Test Insert Task',
						'post_content' => 'Test content',
					),
				),
			),
		);

		// 入队任务
		wptsall_insert_tasks( $tasks );

		// 验证数据库记录
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$task_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE template = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
				'wordpress-blog',
				$this->test_post_ids[0] ?? 1
			),
			ARRAY_A
		);

		$this->assertNotNull( $task_row );
		$this->assertEquals( 'pending', $task_row['status'] );
		$this->assertEquals( 'virtual', $task_row['target_type'] );
	}

	/**
	 * 测试处理虚拟站点任务
	 */
	public function test_process_virtual_site_task() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		if ( empty( $this->test_post_ids ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$post_id = $this->test_post_ids[0];
		$post    = get_post( $post_id );

		// 获取完整数据
		$complete_data = array(
			'post' => array(
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_status'  => $post->post_status,
				'post_type'    => $post->post_type,
			),
			'meta' => array(
				'custom_field' => get_post_meta( $post_id, 'custom_field', true ),
			),
			'taxonomies' => array(),
		);

		// 创建任务
		$task = array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $this->relation_id ?: 1,
			'template'          => 'wordpress-blog',
			'object_type'       => 'post_type',
			'subtype'           => 'post',
			'object_id'         => $post_id,
			'complete_data'     => $complete_data,
		);

		// 处理任务
		$result = wptsall_process_task( $task );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'note', $result );
		$this->assertStringContainsString( '虚拟站点', $result['note'] );
	}

	/**
	 * 测试虚拟站点内容存储 (v0.7.0+ 新 API)
	 */
	public function test_virtual_content_storage() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 存储虚拟内容 (v0.7.0+ 新函数签名)
		$content = array(
			'post' => array(
				'post_title'   => 'Virtual Content Test',
				'post_content' => 'Test content for virtual site',
			),
		);

		$virtual_site_id = 'v_' . ( $this->relation_id ?: 1 );
		$source_blog_id  = get_current_blog_id();

		$result_id = wptsall_store_virtual_content(
			$virtual_site_id,
			$source_blog_id,
			123,
			'post_type',
			'post',
			$content
		);

		$this->assertGreaterThan( 0, $result_id );

		// 验证数据库记录 (v0.7.0+ 使用新表)
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_site_content';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$result_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $row, '断言失败: 不期望 null' );
		$this->assertEquals( 'post_type', $row['object_type'] );
		$this->assertEquals( 'post', $row['subtype'] );
		$this->assertEquals( $virtual_site_id, $row['virtual_site_id'] );

		// 验证内容
		$stored_content = json_decode( $row['content'], true );
		$this->assertIsArray( $stored_content );
		if ( ! isset( $stored_content['post'] ) ) {
			$stored_content = array( 'post' => $stored_content );
		}
		$this->assertArrayHasKey( 'post', $stored_content );
		$this->assertEquals( 'Virtual Content Test', $stored_content['post']['post_title'] );
	}

	/**
	 * 测试任务表存在
	 */
	public function test_task_table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertEquals( $table, $table_exists );
	}

	/**
	 * 测试虚拟内容表存在 (v0.7.0+ 使用新表名)
	 */
	public function test_virtual_content_table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_site_content';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertEquals( $table, $table_exists );
	}

	/**
	 * 测试映射表存在
	 */
	public function test_mapping_table_exists() {
		global $wpdb;
		$table = $wpdb->base_prefix . 'wptsall_mappings';

		// 确保表存在
		wptsall_ensure_mapping_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertEquals( $table, $table_exists );
	}

	/**
	 * 测试任务状态更新
	 */
	public function test_update_task_status() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建并入队任务
		$tasks = array(
			array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $this->relation_id ?: 1,
				'template'          => 'wordpress-blog',
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'object_id'         => 99999,
				'complete_data'     => array(),
			),
		);

		wptsall_insert_tasks( $tasks );

		// 获取任务
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$task_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_id = %d ORDER BY id DESC LIMIT 1",
				99999
			),
			ARRAY_A
		);

		$this->assertNotNull( $task_row );
		$this->assertEquals( 'pending', $task_row['status'] );

		// 更新状态
		wptsall_update_task_status( $task_row, 'completed', 'Test completed' );

		// 验证更新
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$task_row['id']
			),
			ARRAY_A
		);

		$this->assertEquals( 'completed', $updated_row['status'] );
		$this->assertEquals( 'Test completed', $updated_row['status_note'] );
	}

	/**
	 * 测试完整工作流：关联 → 任务生成 → 处理
	 */
	public function test_full_workflow() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		if ( empty( $this->test_post_ids ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		// 1. 验证关联存在
		$relation = Site_Relation_Service::get_relation( $this->relation_id );
		$this->assertNotNull( $relation );
		$this->assertEquals( 'wordpress-blog', $relation['template'] );

		// 2. 创建模板
		$template = array(
			'plugin'  => 'wordpress-blog',
			'objects' => array(
				'post_types' => array(
					array( 'subtype' => 'post' ),
				),
			),
		);

		// 3. 生成任务
		// v0.4.0: get_relation 返回扁平数据，需手动构造 targets 数组
		$site_rel = array(
			'id'       => $relation['id'],
			'template' => 'wordpress-blog',
			'source'   => array(
				'type' => 'wp',
				'id'   => $this->current_site_id,
			),
			'targets'  => array(
				array(
					'type' => $relation['target_site_type'],
					'id'   => $relation['target_site_id'],
					'lang' => $relation['target_lang'],
				),
			),
		);

		$tasks = wptsall_generate_tasks_from_template( $template, 1, $site_rel );
		$this->assertIsArray( $tasks );

		if ( empty( $tasks ) ) {
			$this->markTestSkipped( 'No tasks generated' );
		}

		// 4. 处理任务
		$task   = $tasks[0];
		$result = wptsall_process_task( $task );

		$this->assertTrue( $result['success'] );

		// 5. 验证虚拟站点内容已存储 (v0.7.0+ 使用新表)
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_site_content';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_object_id = %d AND object_type = %s ORDER BY id DESC LIMIT 1",
				$task['object_id'],
				'post_type'
			),
			ARRAY_A
		);

		$this->assertNotNull( $stored, '断言失败: 不期望 null' );
		$this->assertEquals( 'post_type', $stored['object_type'] );
		$this->assertEquals( 'post', $stored['subtype'] );
	}
}
