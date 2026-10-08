<?php
/**
 * Full Workflow Integration Tests
 *
 * 测试完整的同步工作流程
 * 模型扫描 → 站点关系创建 → 任务生成 → 任务处理 → 内容验证
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.5.0 适配 v0.4.0/v0.5.0 新架构
 *
 * @requires function wptsall_get_complete_post_data
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;

class Test_Full_Workflow extends WP_UnitTestCase {

	/**
	 * 测试数据
	 *
	 * @var array
	 */
	private $test_data = array(
		'posts'      => array(),
		'pages'      => array(),
		'categories' => array(),
		'tags'       => array(),
	);

	/**
	 * 虚拟站点 ID
	 *
	 * @var int
	 */
	private $virtual_site_id;

	/**
	 * 关联 ID 数组（v0.4.0 返回数组）
	 *
	 * @var array
	 */
	private $relation_ids = array();

	/**
	 * 当前站点 ID
	 *
	 * @var int
	 */
	private $current_site_id;

	/**
	 * 源站点语言
	 *
	 * @var string
	 */
	private $source_lang;

	/**
	 * 设置测试
	 */
	public function setUp(): void {
		parent::setUp();

		$this->current_site_id = get_current_blog_id();
		$this->source_lang     = get_option( 'WPLANG', 'en_US' );
		if ( empty( $this->source_lang ) ) {
			$this->source_lang = 'en_US';
		}

		// 创建完整的测试数据集
		$this->create_complete_test_dataset();

		// 创建虚拟站点
		$this->create_virtual_site();
	}

	/**
	 * 创建完整测试数据集
	 */
	private function create_complete_test_dataset() {
		// 创建层级分类
		$parent_cat = wp_insert_term( 'Workflow Parent Category ' . time(), 'category', array(
			'description' => 'Parent category for workflow test',
		) );

		if ( ! is_wp_error( $parent_cat ) ) {
			$this->test_data['categories']['parent'] = $parent_cat['term_id'];

			// 创建子分类
			$child_cat = wp_insert_term( 'Workflow Child Category ' . time(), 'category', array(
				'description' => 'Child category for workflow test',
				'parent'      => $parent_cat['term_id'],
			) );

			if ( ! is_wp_error( $child_cat ) ) {
				$this->test_data['categories']['child'] = $child_cat['term_id'];
			}
		}

		// 创建标签
		for ( $i = 1; $i <= 3; $i++ ) {
			$tag = wp_insert_term( 'Workflow Tag ' . $i . ' ' . time(), 'post_tag' );
			if ( ! is_wp_error( $tag ) ) {
				$this->test_data['tags'][] = $tag['term_id'];
			}
		}

		// 创建多篇文章
		for ( $i = 1; $i <= 3; $i++ ) {
			$post_id = wp_insert_post( array(
				'post_title'   => 'Workflow Test Post ' . $i . ' ' . time(),
				'post_content' => '<h2>Heading</h2><p>This is workflow test post ' . $i . '.</p><ul><li>Item 1</li><li>Item 2</li></ul>',
				'post_excerpt' => 'Excerpt for post ' . $i,
				'post_status'  => 'publish',
				'post_type'    => 'post',
			) );

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				$this->test_data['posts'][] = $post_id;

				// 添加 meta
				update_post_meta( $post_id, 'workflow_custom_field', 'value_' . $i );
				update_post_meta( $post_id, '_yoast_wpseo_title', 'SEO Title ' . $i );
				update_post_meta( $post_id, 'reading_time', $i * 3 );

				// 关联分类（第一篇用父分类，其他用子分类）
				if ( $i === 1 && ! empty( $this->test_data['categories']['parent'] ) ) {
					wp_set_post_categories( $post_id, array( $this->test_data['categories']['parent'] ) );
				} elseif ( ! empty( $this->test_data['categories']['child'] ) ) {
					wp_set_post_categories( $post_id, array( $this->test_data['categories']['child'] ) );
				}

				// 关联标签
				if ( ! empty( $this->test_data['tags'] ) ) {
					wp_set_post_tags( $post_id, array_slice( $this->test_data['tags'], 0, $i ) );
				}
			}
		}

		// 创建层级页面
		$parent_page = wp_insert_post( array(
			'post_title'   => 'Workflow Parent Page ' . time(),
			'post_content' => 'Parent page content',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );

		if ( $parent_page && ! is_wp_error( $parent_page ) ) {
			$this->test_data['pages']['parent'] = $parent_page;
			update_post_meta( $parent_page, '_wp_page_template', 'default' );

			// 创建子页面
			$child_page = wp_insert_post( array(
				'post_title'   => 'Workflow Child Page ' . time(),
				'post_content' => 'Child page content',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_parent'  => $parent_page,
			) );

			if ( $child_page && ! is_wp_error( $child_page ) ) {
				$this->test_data['pages']['child'] = $child_page;
			}
		}
	}

	/**
	 * 创建虚拟站点（使用 Virtual_Site_Service）
	 */
	private function create_virtual_site() {
		$path_prefix = 'workflow-test-' . time();

		$result = Virtual_Site_Service::create( array(
			'name'        => 'Workflow Test Site',
			'path_prefix' => $path_prefix,
			'lang'        => 'zh_CN',
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
		foreach ( $this->test_data['posts'] as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// 删除测试页面
		foreach ( $this->test_data['pages'] as $page_id ) {
			wp_delete_post( $page_id, true );
		}

		// 删除测试分类
		foreach ( $this->test_data['categories'] as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		// 删除测试标签
		foreach ( $this->test_data['tags'] as $term_id ) {
			wp_delete_term( $term_id, 'post_tag' );
		}

		// 删除关联（v0.4.0 一对一结构）
		foreach ( $this->relation_ids as $relation_id ) {
			Site_Relation_Service::delete_relation( $relation_id );
		}

		// 删除虚拟站点
		if ( $this->virtual_site_id ) {
			Virtual_Site_Service::delete( $this->virtual_site_id );
		}

		// 清理虚拟内容 (v0.7.0+ 使用新表)
		$vc_table = wptsall_table( 'virtual_site_content' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$vc_table} WHERE subtype IN (%s, %s, %s, %s)",
				'post', 'page', 'category', 'post_tag'
			)
		);

		// 清理任务
		$task_table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$task_table} WHERE template = %s",
				'wordpress-blog'
			)
		);

		parent::tearDown();
	}

	/**
	 * 辅助方法：创建站点关系（v0.4.0 API）
	 *
	 * @return array 结果
	 */
	private function create_test_relation() {
		if ( empty( $this->virtual_site_id ) ) {
			return array( 'success' => false, 'errors' => array( 'Virtual site not available' ) );
		}

		$result = Site_Relation_Service::create_relation( array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		if ( $result['success'] && ! empty( $result['relation_ids'] ) ) {
			$this->relation_ids = array_merge( $this->relation_ids, $result['relation_ids'] );
		}

		return $result;
	}

	// ==================== 步骤 2: 站点关系创建测试 ====================

	/**
	 * 测试步骤2：创建站点关系（v0.4.0 API）
	 */
	public function test_step2_create_site_relation() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		$result = $this->create_test_relation();

		$this->assertTrue( $result['success'] ?? false );
		$this->assertArrayHasKey( 'relation_ids', $result );
		$this->assertNotEmpty( $result['relation_ids'] );

		$relation_id = $result['relation_ids'][0];
		$this->assertGreaterThan( 0, $relation_id );

		// 验证关联可以被获取
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertNotNull( $relation );
		$this->assertEquals( 'wordpress-blog', $relation['template'] );
		$this->assertEquals( 'active', $relation['status'] );
		$this->assertEquals( $this->source_lang, $relation['source_lang'] );
		$this->assertEquals( 'virtual', $relation['target_site_type'] );
		$this->assertEquals( 'zh_CN', $relation['target_lang'] );
	}

	/**
	 * 测试五元组唯一约束
	 */
	public function test_step2_quintuple_unique_constraint() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建第一个关系
		$result1 = $this->create_test_relation();
		$this->assertTrue( $result1['success'] ?? false );

		// 尝试创建相同的关系（应该失败或返回错误）
		$result2 = Site_Relation_Service::create_relation( array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		// 五元组约束应该阻止创建重复关系
		// 验证器应该返回错误
		$this->assertFalse( $result2['success'] ?? true );
	}

	/**
	 * 测试多目标站点创建
	 */
	public function test_step2_create_multiple_targets() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建第二个虚拟站点
		$result2 = Virtual_Site_Service::create( array(
			'name'        => 'Second Test Site',
			'path_prefix' => 'v-ja-' . time(),
			'lang'        => 'ja',
		) );

		if ( ! $result2['success'] ) {
			$this->markTestSkipped( 'Could not create second virtual site' );
		}

		$second_site_id = $result2['site_id'];

		// 创建多目标关系
		$result = Site_Relation_Service::create_relation( array(
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'template'       => 'wordpress-blog',
			'target_sites'   => array(
				array(
					'id'   => $this->virtual_site_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
				array(
					'id'   => $second_site_id,
					'type' => 'virtual',
					'lang' => 'ja',
				),
			),
		) );

		$this->assertTrue( $result['success'] ?? false );
		$this->assertArrayHasKey( 'relation_ids', $result );
		$this->assertCount( 2, $result['relation_ids'] );

		// 记录以便清理
		$this->relation_ids = array_merge( $this->relation_ids, $result['relation_ids'] );

		// 清理第二个虚拟站点
		Virtual_Site_Service::delete( $second_site_id );
	}

	// ==================== 步骤 3: 任务生成测试 ====================

	/**
	 * 测试步骤3：从模板生成任务
	 */
	public function test_step3_generate_tasks() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 先创建关联
		$result = $this->create_test_relation();

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $result['relation_ids'][0];
		$relation    = Site_Relation_Service::get_relation( $relation_id );

		// 生成任务
		$template = array(
			'plugin'  => 'wordpress-blog',
			'objects' => array(
				'post_types' => array(
					array( 'subtype' => 'post' ),
					array( 'subtype' => 'page' ),
				),
				'taxonomies' => array(
					array( 'subtype' => 'category' ),
					array( 'subtype' => 'post_tag' ),
				),
			),
		);

		$site_rel = array(
			'id'       => $relation['id'],
			'template' => 'wordpress-blog',
			'source'   => array(
				'type' => 'wp',
				'id'   => $this->current_site_id,
			),
			'targets'  => array(
				array(
					'id'   => $relation['target_site_id'],
					'type' => $relation['target_site_type'],
					'lang' => $relation['target_lang'],
				),
			),
		);

		$tasks = wptsall_generate_tasks_from_template( $template, 10, $site_rel );

		$this->assertIsArray( $tasks );
		$this->assertNotEmpty( $tasks );

		// 验证生成的任务包含多种类型
		$task_types = array();
		foreach ( $tasks as $task ) {
			$key               = $task['object_type'] . ':' . $task['subtype'];
			$task_types[ $key ] = ( $task_types[ $key ] ?? 0 ) + 1;
		}

		// 应该有 post 类型的任务
		$this->assertArrayHasKey( 'post_type:post', $task_types );
	}

	/**
	 * 测试任务数据完整性
	 */
	public function test_step3_task_data_completeness() {
		if ( empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$post_id       = $this->test_data['posts'][0];
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 验证核心数据
		$this->assertArrayHasKey( 'post', $complete_data );
		$this->assertArrayHasKey( 'meta', $complete_data );
		$this->assertArrayHasKey( 'taxonomies', $complete_data );

		// 注意：wptsall_get_complete_post_data 只返回 schema 定义的 meta
		// 自定义 meta 需要通过 WordPress 原生方法验证
		$all_meta = get_post_meta( $post_id );
		$this->assertArrayHasKey( 'workflow_custom_field', $all_meta );
		$this->assertArrayHasKey( 'reading_time', $all_meta );

		// 验证分类关联
		$this->assertArrayHasKey( 'category', $complete_data['taxonomies'] );
	}

	// ==================== 步骤 4: 任务处理测试 ====================

	/**
	 * 测试步骤4：处理单个任务
	 */
	public function test_step4_process_single_task() {
		if ( empty( $this->virtual_site_id ) || empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'Virtual site or test posts not available' );
		}

		// 创建关联
		$result = $this->create_test_relation();

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id   = $result['relation_ids'][0];
		$post_id       = $this->test_data['posts'][0];
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		$task = array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $relation_id,
			'template'          => 'wordpress-blog',
			'object_type'       => 'post_type',
			'subtype'           => 'post',
			'object_id'         => $post_id,
			'complete_data'     => $complete_data,
		);

		$process_result = wptsall_process_task( $task );

		$this->assertTrue( $process_result['success'] );
		$this->assertStringContainsString( '虚拟站点', $process_result['note'] );
	}

	/**
	 * 测试处理层级内容（子分类）
	 */
	public function test_step4_process_hierarchical_content() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		if ( empty( $this->test_data['categories']['child'] ) ) {
			$this->markTestSkipped( 'Child category not available' );
		}

		// 创建关联
		$result = $this->create_test_relation();

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id   = $result['relation_ids'][0];
		$term_id       = $this->test_data['categories']['child'];
		$complete_data = wptsall_get_complete_term_data( 'category', $term_id );

		// 验证包含父级数据
		$this->assertArrayHasKey( 'term', $complete_data );
		$this->assertGreaterThan( 0, $complete_data['term']['parent'] ?? 0 );

		$task = array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $relation_id,
			'template'          => 'wordpress-blog',
			'object_type'       => 'taxonomy',
			'subtype'           => 'category',
			'object_id'         => $term_id,
			'complete_data'     => $complete_data,
		);

		$process_result = wptsall_process_task( $task );

		$this->assertTrue( $process_result['success'] );
	}

	// ==================== 步骤 5: 内容验证测试 ====================

	/**
	 * 测试步骤5：验证虚拟站点存储的内容
	 */
	public function test_step5_verify_virtual_content() {
		if ( empty( $this->virtual_site_id ) || empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'Virtual site or test posts not available' );
		}

		// 创建关联
		$result = $this->create_test_relation();

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $result['relation_ids'][0];
		$post_id     = $this->test_data['posts'][0];
		$source_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 处理任务
		$task = array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $relation_id,
			'template'          => 'wordpress-blog',
			'object_type'       => 'post_type',
			'subtype'           => 'post',
			'object_id'         => $post_id,
			'complete_data'     => $source_data,
		);

		wptsall_process_task( $task );

		// 获取虚拟站点存储的内容 (v0.7.0+ 使用新表)
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_site_content';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_object_id = %d AND object_type = %s ORDER BY id DESC LIMIT 1",
				$post_id,
				'post_type'
			),
			ARRAY_A
		);

		$this->assertNotNull( $stored, 'Expected not null but got null' );

		$virtual_content = json_decode( $stored['content'], true );

		// 验证标题一致
		$this->assertEquals(
			$source_data['post']['post_title'],
			$virtual_content['post']['post_title']
		);

		// 验证 meta 数据存在
		$this->assertArrayHasKey( 'meta', $virtual_content );
		// 注意：只有 schema 定义的 meta 会被同步到虚拟站点
		$this->assertIsArray( $virtual_content['meta'] );

		// 验证分类关联存在
		$this->assertArrayHasKey( 'taxonomies', $virtual_content );
	}

	// ==================== Templates 模块测试 ====================

	/**
	 * 测试创建 Template 记录
	 */
	public function test_templates_create() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$result = $this->create_test_relation();

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $result['relation_ids'][0];

		// 创建模板
		$template_id = Template_Service::create( array(
			'relation_id'   => $relation_id,
			'source_type'   => 'plugin',
			'text_domain'   => 'test-plugin',
			'source_name'   => 'Test Plugin',
			'source_version' => '1.0.0',
			'status'        => 'pending',
		) );

		$this->assertNotFalse( $template_id );
		$this->assertGreaterThan( 0, $template_id );

		// 验证可以获取
		$template = Template_Service::get( $template_id );
		$this->assertNotNull( $template );
		$this->assertEquals( 'test-plugin', $template['text_domain'] );
		$this->assertEquals( $relation_id, $template['relation_id'] );

		// 清理
		Template_Service::delete( $template_id );
	}

	/**
	 * 测试 get_by_relation 方法
	 */
	public function test_templates_get_by_relation() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$result = $this->create_test_relation();

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $result['relation_ids'][0];

		// 创建多个模板
		$template_ids = array();
		$template_ids[] = Template_Service::create( array(
			'relation_id'   => $relation_id,
			'source_type'   => 'theme',
			'text_domain'   => 'twentytwentyfive',
			'source_name'   => 'Twenty Twenty-Five',
			'status'        => 'scanned',
		) );
		$template_ids[] = Template_Service::create( array(
			'relation_id'   => $relation_id,
			'source_type'   => 'plugin',
			'text_domain'   => 'woocommerce',
			'source_name'   => 'WooCommerce',
			'status'        => 'pending',
		) );

		// 获取关系下的所有模板
		$templates = Template_Service::get_by_relation( $relation_id );

		$this->assertIsArray( $templates );
		$this->assertCount( 2, $templates );

		// 清理
		foreach ( $template_ids as $id ) {
			if ( $id ) {
				Template_Service::delete( $id );
			}
		}
	}

	// ==================== 完整工作流测试 ====================

	/**
	 * 测试完整工作流：从头到尾
	 */
	public function test_complete_workflow_end_to_end() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// === 步骤 1: 创建站点关系 ===
		$relation_result = $this->create_test_relation();

		$this->assertTrue( $relation_result['success'] ?? false );
		$relation_id = $relation_result['relation_ids'][0];

		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertNotNull( $relation );

		// === 步骤 2: 生成任务 ===
		$template_config = array(
			'plugin'  => 'wordpress-blog',
			'objects' => array(
				'post_types' => array(
					array( 'subtype' => 'post' ),
				),
			),
		);

		$site_rel = array(
			'id'       => $relation['id'],
			'template' => 'wordpress-blog',
			'source'   => array(
				'type' => 'wp',
				'id'   => $this->current_site_id,
			),
			'targets'  => array(
				array(
					'id'   => $relation['target_site_id'],
					'type' => $relation['target_site_type'],
					'lang' => $relation['target_lang'],
				),
			),
		);

		$tasks = wptsall_generate_tasks_from_template( $template_config, 3, $site_rel );
		$this->assertNotEmpty( $tasks );

		// === 步骤 3: 处理任务 ===
		$processed_count = 0;
		$success_count   = 0;

		foreach ( $tasks as $task ) {
			$task_result = wptsall_process_task( $task );
			++$processed_count;

			if ( $task_result['success'] ) {
				++$success_count;
			}
		}

		$this->assertGreaterThan( 0, $processed_count );
		$this->assertEquals( $processed_count, $success_count, 'All tasks should succeed' );

		// === 步骤 4: 验证结果 === (v0.7.0+ 使用新表)
		global $wpdb;
		$table = wptsall_table( 'virtual_site_content' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE object_type = %s AND subtype = %s",
				'post_type',
				'post'
			)
		);

		$this->assertGreaterThan( 0, $stored_count );
	}

	/**
	 * 测试多种内容类型的完整同步
	 */
	public function test_workflow_multiple_content_types() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$relation_result = $this->create_test_relation();

		if ( empty( $relation_result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $relation_result['relation_ids'][0];

		// 同步文章
		if ( ! empty( $this->test_data['posts'][0] ) ) {
			$post_data = wptsall_get_complete_post_data( 'post', $this->test_data['posts'][0] );
			$result    = wptsall_process_task( array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $relation_id,
				'template'          => 'wordpress-blog',
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'object_id'         => $this->test_data['posts'][0],
				'complete_data'     => $post_data,
			) );
			$this->assertTrue( $result['success'], 'Post sync should succeed' );
		}

		// 同步页面
		if ( ! empty( $this->test_data['pages']['parent'] ) ) {
			$page_data = wptsall_get_complete_post_data( 'page', $this->test_data['pages']['parent'] );
			$result    = wptsall_process_task( array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $relation_id,
				'template'          => 'wordpress-blog',
				'object_type'       => 'post_type',
				'subtype'           => 'page',
				'object_id'         => $this->test_data['pages']['parent'],
				'complete_data'     => $page_data,
			) );
			$this->assertTrue( $result['success'], 'Page sync should succeed' );
		}

		// 同步分类
		if ( ! empty( $this->test_data['categories']['parent'] ) ) {
			$cat_data = wptsall_get_complete_term_data( 'category', $this->test_data['categories']['parent'] );
			$result   = wptsall_process_task( array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $relation_id,
				'template'          => 'wordpress-blog',
				'object_type'       => 'taxonomy',
				'subtype'           => 'category',
				'object_id'         => $this->test_data['categories']['parent'],
				'complete_data'     => $cat_data,
			) );
			$this->assertTrue( $result['success'], 'Category sync should succeed' );
		}

		// 同步标签
		if ( ! empty( $this->test_data['tags'][0] ) ) {
			$tag_data = wptsall_get_complete_term_data( 'post_tag', $this->test_data['tags'][0] );
			$result   = wptsall_process_task( array(
				'blog_id'           => $this->current_site_id,
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => $this->virtual_site_id,
				'site_id'           => $relation_id,
				'template'          => 'wordpress-blog',
				'object_type'       => 'taxonomy',
				'subtype'           => 'post_tag',
				'object_id'         => $this->test_data['tags'][0],
				'complete_data'     => $tag_data,
			) );
			$this->assertTrue( $result['success'], 'Tag sync should succeed' );
		}

		// 验证所有类型都已存储 (v0.7.0+ 使用新表)
		global $wpdb;
		$table = wptsall_table( 'virtual_site_content' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_types = $wpdb->get_results(
			"SELECT DISTINCT object_type, subtype FROM {$table}",
			ARRAY_A
		);

		$type_keys = array();
		foreach ( $stored_types as $row ) {
			$type_keys[] = $row['object_type'] . ':' . $row['subtype'];
		}

		// 验证存储了多种类型
		$this->assertContains( 'post_type:post', $type_keys );
	}

	// ==================== 错误处理测试 ====================

	/**
	 * 测试处理不存在的对象
	 */
	public function test_workflow_nonexistent_object() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$relation_result = $this->create_test_relation();

		if ( empty( $relation_result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $relation_result['relation_ids'][0];

		// 尝试同步不存在的文章
		$result = wptsall_process_task( array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $relation_id,
			'template'          => 'wordpress-blog',
			'object_type'       => 'post_type',
			'subtype'           => 'post',
			'object_id'         => 999999999,  // 不存在的 ID
			'complete_data'     => null,       // 没有数据
		) );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * 测试关系统计
	 */
	public function test_workflow_relation_stats() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$relation_result = $this->create_test_relation();

		// 获取统计
		$stats = Site_Relation_Service::get_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'active', $stats );
		$this->assertArrayHasKey( 'by_template', $stats );
		$this->assertArrayHasKey( 'groups', $stats );
		$this->assertGreaterThanOrEqual( 0, $stats['total'] );
	}

	/**
	 * 测试 get_grouped_relations 方法
	 */
	public function test_get_grouped_relations() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$this->create_test_relation();

		// 获取分组后的关系
		$grouped = Site_Relation_Service::get_grouped_relations( array(
			'template' => 'wordpress-blog',
		) );

		$this->assertIsArray( $grouped );
		$this->assertNotEmpty( $grouped );

		// 验证分组结构
		$group = $grouped[0];
		$this->assertArrayHasKey( 'source_site_id', $group );
		$this->assertArrayHasKey( 'source_lang', $group );
		$this->assertArrayHasKey( 'template', $group );
		$this->assertArrayHasKey( 'targets', $group );
		$this->assertIsArray( $group['targets'] );
	}

	/**
	 * 测试 get_targets_for_source 方法
	 */
	public function test_get_targets_for_source() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$this->create_test_relation();

		// 获取目标站点列表
		$targets = Site_Relation_Service::get_targets_for_source(
			$this->current_site_id,
			$this->source_lang,
			'wordpress-blog'
		);

		$this->assertIsArray( $targets );
		$this->assertNotEmpty( $targets );

		$target = $targets[0];
		$this->assertArrayHasKey( 'target_site_id', $target );
		$this->assertArrayHasKey( 'target_site_type', $target );
		$this->assertArrayHasKey( 'target_lang', $target );
	}

	/**
	 * 测试 sync_theme_info 方法
	 */
	public function test_sync_theme_info() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 创建关联
		$result = $this->create_test_relation();

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $result['relation_ids'][0];

		// 同步主题信息
		$sync_result = Site_Relation_Service::sync_theme_info( $relation_id );

		$this->assertTrue( $sync_result['success'] ?? false );
		$this->assertArrayHasKey( 'source_theme', $sync_result );
		$this->assertArrayHasKey( 'name', $sync_result['source_theme'] );
		$this->assertArrayHasKey( 'path', $sync_result['source_theme'] );
	}
}
