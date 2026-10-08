<?php
/**
 * Models to Tasks Integration Tests
 *
 * 测试从模型扫描到任务生成的完整跨模块流程
 *
 * @package WPTSALL
 * @since 0.6.0
 */

use WPTSALL\Models\Services\Plugin_Mapping_Service;
use WPTSALL\Models\Services\Plugin_Scanner;
use WPTSALL\Models\Scanners\Model_Scanner_V2;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_Models_To_Tasks extends SimpleTestCase {

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
	 * 测试文章 IDs
	 *
	 * @var array
	 */
	private $test_post_ids = array();

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
		$this->create_test_posts();

		// 创建虚拟站点
		$this->create_virtual_site();
	}

	/**
	 * 创建测试文章
	 */
	private function create_test_posts() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$post_id = wp_insert_post( array(
				'post_title'   => 'Models-Tasks Test Post ' . $i . ' - ' . time(),
				'post_content' => 'Test content for cross-module testing ' . $i,
				'post_status'  => 'publish',
				'post_type'    => 'post',
			) );

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				$this->test_post_ids[] = $post_id;
			}
		}
	}

	/**
	 * 创建虚拟站点
	 */
	private function create_virtual_site() {
		$path_prefix = 'models-tasks-test-' . time();

		$result = Virtual_Site_Service::create( array(
			'name'        => 'Models-Tasks Test Site',
			'path_prefix' => $path_prefix,
			'lang'        => 'en_US',
		) );

		if ( $result['success'] ) {
			$this->virtual_site_id = $result['site_id'];
		}
	}

	/**
	 * 清理测试数据
	 */
	public function tearDown(): void {
		global $wpdb;

		// 删除测试文章
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
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

		// 清理虚拟内容 (v0.7.0+ 使用新表)
		$table = wptsall_table( 'virtual_site_content' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE subtype = %s",
				'post'
			)
		);

		parent::tearDown();
	}

	// ==================== Plugin_Mapping_Service Tests ====================

	/**
	 * 测试 Plugin_Mapping_Service 类存在
	 */
	public function test_plugin_mapping_service_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) );
	}

	/**
	 * 测试 Plugin_Mapping_Service::get_by_slug()
	 */
	public function test_get_plugin_mapping_by_slug() {
		// 尝试获取 wordpress-blog 映射
		$mapping = Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );

		// 如果不存在，先创建
		if ( ! $mapping ) {
			$save_result = Plugin_Mapping_Service::save( array(
				'plugin_slug' => 'wordpress-blog',
				'plugin_name' => 'WordPress Blog',
				'post_types'  => array( 'post', 'page' ),
				'taxonomies'  => array( 'category', 'post_tag' ),
			) );

			$this->assertNotFalse( $save_result );

			$mapping = Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
		}

		$this->assertIsArray( $mapping );
		$this->assertArrayHasKey( 'plugin_slug', $mapping );
		$this->assertEquals( 'wordpress-blog', $mapping['plugin_slug'] );
	}

	/**
	 * 测试 Plugin_Mapping_Service::save() 和 get_all()
	 */
	public function test_plugin_mapping_save_and_get_all() {
		// 保存测试映射
		$test_slug = 'test-plugin-' . time();
		$result    = Plugin_Mapping_Service::save( array(
			'plugin_slug' => $test_slug,
			'plugin_name' => 'Test Plugin',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		$this->assertNotFalse( $result );

		// 获取所有映射
		$all_mappings = Plugin_Mapping_Service::get_all();

		$this->assertIsArray( $all_mappings );

		// 验证包含我们创建的映射
		$found = false;
		foreach ( $all_mappings as $mapping ) {
			if ( $mapping['plugin_slug'] === $test_slug ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Created mapping should be in get_all() results' );

		// 清理
		Plugin_Mapping_Service::delete( $test_slug );
	}

	// ==================== Cross-Module Workflow Tests ====================

	/**
	 * 测试完整流程：映射保存 → 关系创建 → 任务生成
	 */
	public function test_mapping_to_relation_to_tasks_workflow() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 步骤 1: 确保 wordpress-blog 映射存在
		$mapping = Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
		if ( ! $mapping ) {
			Plugin_Mapping_Service::save( array(
				'plugin_slug' => 'wordpress-blog',
				'plugin_name' => 'WordPress Blog',
				'post_types'  => array( 'post', 'page' ),
				'taxonomies'  => array( 'category', 'post_tag' ),
			) );
			$mapping = Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
		}

		$this->assertNotNull( $mapping, 'Plugin mapping should exist' );

		// 步骤 2: 创建站点关系
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

		$this->assertTrue( $result['success'] ?? false, 'Relation should be created' );
		$this->relation_id = $result['relation_ids'][0];

		// 步骤 3: 获取关系并验证
		$relation = Site_Relation_Service::get_relation( $this->relation_id );
		$this->assertNotNull( $relation );
		$this->assertEquals( 'wordpress-blog', $relation['template'] );

		// 步骤 4: 构建模板配置（从映射）
		$template_config = array(
			'plugin'  => $mapping['plugin_slug'],
			'objects' => array(
				'post_types' => array(),
				'taxonomies' => array(),
			),
		);

		// 从映射中提取 post_types
		$post_types = $mapping['post_types'] ?? array();
		if ( is_string( $post_types ) ) {
			$post_types = json_decode( $post_types, true ) ?: array();
		}
		foreach ( $post_types as $pt ) {
			// 支持两种格式：简单字符串数组或详细对象数组
			$subtype = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
			if ( ! empty( $subtype ) ) {
				$template_config['objects']['post_types'][] = array( 'subtype' => $subtype );
			}
		}

		// 步骤 5: 生成任务
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

		$tasks = wptsall_generate_tasks_from_template( $template_config, 5, $site_rel );

		$this->assertIsArray( $tasks );
		$this->assertNotEmpty( $tasks, 'Tasks should be generated from mapping' );

		// 验证任务结构
		$task = $tasks[0];
		$this->assertArrayHasKey( 'template', $task );
		$this->assertEquals( 'wordpress-blog', $task['template'] );
		$this->assertArrayHasKey( 'object_type', $task );
		$this->assertArrayHasKey( 'complete_data', $task );
	}

	/**
	 * 测试映射更新后任务生成的变化
	 */
	public function test_mapping_update_affects_task_generation() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建只包含 post 的映射
		$test_slug = 'test-update-' . time();
		Plugin_Mapping_Service::save( array(
			'plugin_slug' => $test_slug,
			'plugin_name' => 'Test Update Plugin',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		$mapping = Plugin_Mapping_Service::get_by_slug( $test_slug );
		$this->assertNotNull( $mapping );

		// 创建关系（禁用自动创建模型，因为我们已经手动创建了映射）
		$result = Site_Relation_Service::create_relation( array(
			'template'          => $test_slug,
			'source_site_id'    => $this->current_site_id,
			'source_lang'       => 'zh_CN',
			'auto_create_model' => false,
			'target_sites'      => array(
				array(
					'type' => 'virtual',
					'id'   => $this->virtual_site_id,
					'lang' => 'en_US',
				),
			),
		) );

		if ( ! ( $result['success'] ?? false ) ) {
			Plugin_Mapping_Service::delete( $test_slug );
			$this->markTestSkipped( 'Could not create relation' );
		}

		$this->relation_id = $result['relation_ids'][0];

		// 更新映射，添加 page
		Plugin_Mapping_Service::save( array(
			'plugin_slug' => $test_slug,
			'plugin_name' => 'Test Update Plugin',
			'post_types'  => array( 'post', 'page' ),
			'taxonomies'  => array( 'category' ),
		) );

		$updated_mapping = Plugin_Mapping_Service::get_by_slug( $test_slug );
		$post_types      = $updated_mapping['post_types'] ?? array();
		if ( is_string( $post_types ) ) {
			$post_types = json_decode( $post_types, true ) ?: array();
		}

		$this->assertContains( 'page', $post_types, 'Mapping should include page after update' );

		// 清理
		Site_Relation_Service::delete_relation( $this->relation_id );
		$this->relation_id = null;
		Plugin_Mapping_Service::delete( $test_slug );
	}

	/**
	 * 测试任务处理后虚拟内容存储
	 */
	public function test_task_processing_stores_virtual_content() {
		if ( empty( $this->virtual_site_id ) || empty( $this->test_post_ids ) ) {
			$this->markTestSkipped( 'Virtual site or test posts not available' );
		}

		// 创建关系
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

		if ( ! ( $result['success'] ?? false ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$this->relation_id = $result['relation_ids'][0];
		$post_id           = $this->test_post_ids[0];

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 处理任务
		$task_result = wptsall_process_task( array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $this->relation_id,
			'template'          => 'wordpress-blog',
			'object_type'       => 'post_type',
			'subtype'           => 'post',
			'object_id'         => $post_id,
			'complete_data'     => $complete_data,
		) );

		$this->assertTrue( $task_result['success'], 'Task should be processed successfully' );

		// 验证虚拟内容已存储 (v0.7.0+ 使用新表)
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_site_content';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_object_id = %d AND object_type = %s",
				$post_id,
				'post_type'
			),
			ARRAY_A
		);

		$this->assertNotNull( $stored, 'Virtual content should be stored' );
		$this->assertEquals( 'post_type', $stored['object_type'] );
		$this->assertEquals( 'post', $stored['subtype'] );
	}

	// ==================== Scanner Integration Tests ====================

	/**
	 * 测试 Model_Scanner_V2 存在
	 */
	public function test_model_scanner_v2_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\\Models\\Scanners\\Model_Scanner_V2' ) );
	}

	/**
	 * 测试扫描结果可以转换为任务配置
	 *
	 * 注意：Model_Scanner_V2::scan_plugin() 用于扫描已安装的插件
	 * 'wordpress-blog' 是 WordPress 核心博客的标识符
	 */
	public function test_scanner_results_to_task_config() {
		// 获取一个实际安装的内容插件进行测试
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Scanner' ) ) {
			$this->markTestSkipped( 'Plugin_Scanner class not available' );
		}

		$scannable = \WPTSALL\Models\Services\Plugin_Scanner::get_scannable_plugins();
		if ( empty( $scannable ) ) {
			$this->markTestSkipped( 'No scannable plugins available' );
		}

		// get_scannable_plugins() 返回 array('slug' => 'Plugin Name', ...)
		// 跳过 'wordpress-blog'，选择一个真实插件
		$plugin_slug = null;
		foreach ( $scannable as $slug => $name ) {
			if ( 'wordpress-blog' !== $slug ) {
				$plugin_slug = $slug;
				break;
			}
		}

		// 如果没有其他插件，使用 'wordpress-blog'
		if ( empty( $plugin_slug ) ) {
			$plugin_slug = 'wordpress-blog';
		}

		// 使用 Model_Scanner_V2 扫描
		$scanner = new Model_Scanner_V2();
		$results = $scanner->scan_plugin( $plugin_slug );

		// 扫描可能返回 WP_Error 或数组
		if ( is_wp_error( $results ) ) {
			$this->markTestSkipped( 'Scanner returned error: ' . $results->get_error_message() );
		}

		$this->assertIsArray( $results );

		// 验证扫描结果结构可用于任务生成
		if ( ! empty( $results['post_types'] ) ) {
			$template_config = array(
				'plugin'  => $plugin_slug,
				'objects' => array(
					'post_types' => array(),
				),
			);

			foreach ( $results['post_types'] as $pt_info ) {
				$subtype = is_array( $pt_info ) ? ( $pt_info['name'] ?? $pt_info['subtype'] ?? null ) : $pt_info;
				if ( $subtype ) {
					$template_config['objects']['post_types'][] = array(
						'subtype' => $subtype,
					);
				}
			}

			$this->assertNotEmpty( $template_config['objects']['post_types'] );
		}
	}
}
