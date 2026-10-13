<?php
/**
 * Content Comparison Integration Tests
 *
 * 测试源站点和目标站点（虚拟站点）之间的内容对比
 * - 文章数据完整性
 * - Meta 数据同步正确性
 * - 分类法关联同步
 * - 虚拟站点存储验证
 *
 * @package WPTSALL
 * @since 0.3.0
 *
 * @requires function wptsall_get_complete_post_data
 * @requires function wptsall_get_complete_term_data
 */

use WPTSALL\Models\Blog_Template;
use WPTSALL\Sites\Services\Site_Relation_Service;

/**
 * 注意：此测试已转换为使用 SimpleTestCase 以便在主测试套件中运行
 * 不再依赖 WP_UnitTestCase 和 WordPress 测试库
 */
class Test_Content_Comparison extends SimpleTestCase {

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
	 * @var int
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
		$this->create_test_content();

		// 创建虚拟站点
		$this->create_virtual_site();

		// 创建关联
		$this->create_test_relation();
	}

	/**
	 * 创建测试内容
	 */
	private function create_test_content() {
		// 创建分类
		$cat_result = wp_insert_term( 'Comparison Test Category ' . time(), 'category', array(
			'description' => 'Test category for comparison',
			'slug'        => 'comparison-test-cat-' . time(),
		) );

		if ( ! is_wp_error( $cat_result ) ) {
			$this->test_term_ids['category'] = $cat_result['term_id'];
		}

		// 创建标签
		$tag_result = wp_insert_term( 'Comparison Test Tag ' . time(), 'post_tag', array(
			'slug' => 'comparison-test-tag-' . time(),
		) );

		if ( ! is_wp_error( $tag_result ) ) {
			$this->test_term_ids['tag'] = $tag_result['term_id'];
		}

		// 创建测试文章
		$post_id = wp_insert_post( array(
			'post_title'    => 'Comparison Test Post ' . time(),
			'post_content'  => '<p>This is the test post content.</p><p>Second paragraph.</p>',
			'post_excerpt'  => 'Test excerpt for comparison',
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_name'     => 'comparison-test-post-' . time(),
		) );

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			$this->test_post_ids['post'] = $post_id;

			// 添加各种 meta
			update_post_meta( $post_id, '_thumbnail_id', 0 );
			update_post_meta( $post_id, 'custom_text', 'Custom text value' );
			update_post_meta( $post_id, 'custom_number', 42 );
			update_post_meta( $post_id, 'custom_array', array( 'a', 'b', 'c' ) );
			update_post_meta( $post_id, '_yoast_wpseo_title', 'SEO Title for Test' );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'SEO Description' );

			// 关联分类和标签
			if ( ! empty( $this->test_term_ids['category'] ) ) {
				wp_set_post_categories( $post_id, array( $this->test_term_ids['category'] ) );
			}
			if ( ! empty( $this->test_term_ids['tag'] ) ) {
				wp_set_post_tags( $post_id, array( $this->test_term_ids['tag'] ) );
			}
		}

		// 创建测试页面
		$page_id = wp_insert_post( array(
			'post_title'   => 'Comparison Test Page ' . time(),
			'post_content' => 'Test page content for comparison',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_name'    => 'comparison-test-page-' . time(),
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$this->test_post_ids['page'] = $page_id;
			update_post_meta( $page_id, '_wp_page_template', 'default' );
		}
	}

	/**
	 * 创建虚拟站点
	 *
	 * 注意：v0.4.0 起 wp_wptsall_virtual_sites 表已废弃
	 * 虚拟站点信息合并到 site_relations 表，通过 target_site_type='virtual' 标识
	 */
	private function create_virtual_site() {
		// 生成虚拟站点标识（字符串格式）
		$this->virtual_site_id = 'comparison-test-' . time();
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
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// 删除测试分类
		foreach ( $this->test_term_ids as $term_id ) {
			wp_delete_term( $term_id, 'category' );
			wp_delete_term( $term_id, 'post_tag' );
		}

		// 删除关联
		if ( $this->relation_id ) {
			$table = $wpdb->prefix . 'wptsall_site_relations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $this->relation_id ), array( '%d' ) );
		}

		// 删除虚拟站点
		if ( $this->virtual_site_id ) {
			$table = $wpdb->prefix . 'wptsall_virtual_sites';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $this->virtual_site_id ), array( '%d' ) );
		}

		// 清理虚拟内容 (v0.7.0+ 使用新表)
		$vc_table = $wpdb->prefix . 'wptsall_virtual_site_content';
		$virtual_site_id_str = 'v_' . $this->virtual_site_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$vc_table} WHERE virtual_site_id = %s",
				$virtual_site_id_str
			)
		);

		// 清理任务
		$task_table = $wpdb->prefix . 'wptsall_tasks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$task_table} WHERE template = %s",
				'wordpress-blog'
			)
		);

		parent::tearDown();
	}

	// ==================== 数据获取函数测试 ====================

	/**
	 * 测试 wptsall_get_complete_post_data 函数存在
	 */
	public function test_get_complete_post_data_exists() {
		$this->assertTrue( function_exists( 'wptsall_get_complete_post_data' ) );
	}

	/**
	 * 测试获取完整文章数据
	 */
	public function test_get_complete_post_data() {
		if ( ! function_exists( 'wptsall_get_complete_post_data' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_complete_post_data not available' );
		}
		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$data = wptsall_get_complete_post_data( 'post', $post_id );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'object_type', $data );
		$this->assertArrayHasKey( 'subtype', $data );
		$this->assertArrayHasKey( 'object_id', $data );
		$this->assertArrayHasKey( 'post', $data );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'taxonomies', $data );

		$this->assertEquals( 'post_type', $data['object_type'] );
		$this->assertEquals( 'post', $data['subtype'] );
		$this->assertEquals( $post_id, $data['object_id'] );
	}

	/**
	 * 测试文章核心字段完整性
	 */
	public function test_post_core_fields_completeness() {
		if ( ! function_exists( 'wptsall_get_complete_post_data' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_complete_post_data not available' );
		}
		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$data = wptsall_get_complete_post_data( 'post', $post_id );
		$post_data = $data['post'];

		// 验证核心字段存在 (post_type 在 subtype 中)
		$required_fields = array(
			'post_title',
			'post_content',
			'post_excerpt',
			'post_status',
			'post_name',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey( $field, $post_data, "Field '{$field}' should exist" );
		}

		// 验证字段值正确
		$this->assertStringContainsString( 'Comparison Test Post', $post_data['post_title'] );
		$this->assertStringContainsString( 'test post content', $post_data['post_content'] );
		$this->assertEquals( 'publish', $post_data['post_status'] );

		// post_type 在顶层数据结构的 subtype 中
		$this->assertEquals( 'post', $data['subtype'] );
	}

	/**
	 * 测试 Meta 数据完整性
	 */
	public function test_post_meta_completeness() {
		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$data = wptsall_get_complete_post_data( 'post', $post_id );
		$meta = $data['meta'];

		// 验证 meta 结构存在
		$this->assertIsArray( $meta );

		// 验证 schema 定义的核心 meta 字段存在（如果有值）
		// _thumbnail_id 是 schema 中定义的，在 setUp 中设置了
		$this->assertArrayHasKey( '_thumbnail_id', $meta );

		// 验证可以通过 WordPress 原生方法获取所有 meta
		$all_meta = get_post_meta( $post_id );
		$this->assertArrayHasKey( 'custom_text', $all_meta );
		$this->assertEquals( 'Custom text value', $all_meta['custom_text'][0] );

		$this->assertArrayHasKey( 'custom_number', $all_meta );
		$this->assertEquals( '42', $all_meta['custom_number'][0] ); // WordPress 存储为字符串

		// SEO meta
		$this->assertArrayHasKey( '_yoast_wpseo_title', $all_meta );
		$this->assertEquals( 'SEO Title for Test', $all_meta['_yoast_wpseo_title'][0] );
	}

	/**
	 * 测试分类法关联完整性
	 */
	public function test_taxonomy_associations_completeness() {
		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$data = wptsall_get_complete_post_data( 'post', $post_id );
		$taxonomies = $data['taxonomies'];

		// 验证分类存在
		$this->assertArrayHasKey( 'category', $taxonomies );
		$this->assertNotEmpty( $taxonomies['category'] );

		// 验证标签存在
		$this->assertArrayHasKey( 'post_tag', $taxonomies );
		$this->assertNotEmpty( $taxonomies['post_tag'] );

		// 验证分类数据结构
		$category = $taxonomies['category'][0];
		$this->assertArrayHasKey( 'term_id', $category );
		$this->assertArrayHasKey( 'name', $category );
		$this->assertArrayHasKey( 'slug', $category );
	}

	// ==================== 获取 Term 数据测试 ====================

	/**
	 * 测试 wptsall_get_complete_term_data 函数存在
	 */
	public function test_get_complete_term_data_exists() {
		$this->assertTrue( function_exists( 'wptsall_get_complete_term_data' ) );
	}

	/**
	 * 测试获取完整 Term 数据
	 */
	public function test_get_complete_term_data() {
		if ( empty( $this->test_term_ids['category'] ) ) {
			$this->markTestSkipped( 'Test category not available' );
		}

		$term_id = $this->test_term_ids['category'];
		$data = wptsall_get_complete_term_data( 'category', $term_id );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'object_type', $data );
		$this->assertArrayHasKey( 'subtype', $data );
		$this->assertArrayHasKey( 'object_id', $data );
		$this->assertArrayHasKey( 'term', $data );

		$this->assertEquals( 'taxonomy', $data['object_type'] );
		$this->assertEquals( 'category', $data['subtype'] );
		$this->assertEquals( $term_id, $data['object_id'] );
	}

	/**
	 * 测试 Term 核心字段完整性
	 */
	public function test_term_core_fields_completeness() {
		if ( empty( $this->test_term_ids['category'] ) ) {
			$this->markTestSkipped( 'Test category not available' );
		}

		$term_id = $this->test_term_ids['category'];
		$data = wptsall_get_complete_term_data( 'category', $term_id );
		$term_data = $data['term'];

		$required_fields = array(
			'term_id',
			'name',
			'slug',
			'description',
			'taxonomy',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey( $field, $term_data, "Term field '{$field}' should exist" );
		}

		$this->assertStringContainsString( 'Comparison Test Category', $term_data['name'] );
		$this->assertEquals( 'category', $term_data['taxonomy'] );
	}

	// ==================== 虚拟站点同步测试 ====================

	/**
	 * 测试同步到虚拟站点
	 */
	public function test_sync_to_virtual_site() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		$task = array(
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
		);

		$result = wptsall_process_task( $task );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '虚拟站点', $result['note'] );
	}

	/**
	 * 测试虚拟站点内容存储验证
	 */
	public function test_virtual_content_storage_verification() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 同步到虚拟站点
		$task = array(
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
		);

		wptsall_process_task( $task );

		// 验证虚拟内容已存储 (v0.7.0+ 使用新表)
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

		$this->assertNotNull( $stored, '断言失败: 不期望 null' );
		$this->assertEquals( 'post_type', $stored['object_type'] );
		$this->assertEquals( 'post', $stored['subtype'] );
	}

	/**
	 * 测试源和虚拟站点内容一致性
	 */
	public function test_source_virtual_content_consistency() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$source_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 同步到虚拟站点
		$task = array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $this->relation_id,
			'template'          => 'wordpress-blog',
			'object_type'       => 'post_type',
			'subtype'           => 'post',
			'object_id'         => $post_id,
			'complete_data'     => $source_data,
		);

		wptsall_process_task( $task );

		// 获取存储的内容 (v0.7.0+ 使用新表)
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_site_content';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT content FROM {$table} WHERE source_object_id = %d AND object_type = %s ORDER BY id DESC LIMIT 1",
				$post_id,
				'post_type'
			),
			ARRAY_A
		);

		$this->assertNotNull( $stored, 'Virtual content should be stored' );
		$virtual_data = json_decode( $stored['content'], true );

		// 比较关键字段
		$this->assertEquals(
			$source_data['post']['post_title'],
			$virtual_data['post']['post_title'],
			'Title should match between source and virtual'
		);

		// 内容包含站点标记，需要去掉比较
		$source_content = $source_data['post']['post_content'];
		$virtual_content = preg_replace( '/\s*\[site_id=\d+\]/', '', $virtual_data['post']['post_content'] );
		$this->assertStringContainsString(
			'test post content',
			$virtual_content,
			'Content should contain original text'
		);

		// Meta 数据应该包含
		$this->assertArrayHasKey( 'meta', $virtual_data );
	}

	// ==================== Term 同步到虚拟站点测试 ====================

	/**
	 * 测试分类同步到虚拟站点
	 */
	public function test_sync_term_to_virtual_site() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		if ( empty( $this->test_term_ids['category'] ) ) {
			$this->markTestSkipped( 'Test category not available' );
		}

		$term_id = $this->test_term_ids['category'];
		$complete_data = wptsall_get_complete_term_data( 'category', $term_id );

		$task = array(
			'blog_id'           => $this->current_site_id,
			'target_blog'       => 0,
			'target_type'       => 'virtual',
			'target_identifier' => $this->virtual_site_id,
			'site_id'           => $this->relation_id,
			'template'          => 'wordpress-blog',
			'object_type'       => 'taxonomy',
			'subtype'           => 'category',
			'object_id'         => $term_id,
			'complete_data'     => $complete_data,
		);

		$result = wptsall_process_task( $task );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '虚拟站点', $result['note'] );
	}

	// ==================== 批量同步测试 ====================

	/**
	 * 测试任务生成和数据完整性
	 */
	public function test_task_generation_data_integrity() {
		if ( empty( $this->virtual_site_id ) || empty( $this->relation_id ) ) {
			$this->markTestSkipped( 'Virtual site or relation not available' );
		}

		// 保存模板
		Blog_Template::save();

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

		$tasks = wptsall_generate_tasks_from_template( $template, 5, $site_rel );

		$this->assertIsArray( $tasks );

		if ( ! empty( $tasks ) ) {
			foreach ( $tasks as $task ) {
				// 验证任务结构
				$this->assertArrayHasKey( 'blog_id', $task );
				$this->assertArrayHasKey( 'target_type', $task );
				$this->assertArrayHasKey( 'object_type', $task );
				$this->assertArrayHasKey( 'subtype', $task );
				$this->assertArrayHasKey( 'object_id', $task );
				$this->assertArrayHasKey( 'complete_data', $task );

				// 验证 complete_data 包含必要字段
				$this->assertArrayHasKey( 'post', $task['complete_data'] );
				$this->assertArrayHasKey( 'meta', $task['complete_data'] );
			}
		}
	}

	// ==================== 数据转换验证 ====================

	/**
	 * 测试序列化数据的正确处理
	 *
	 * 注意：wptsall_get_complete_post_data 只返回 schema 定义的 meta 字段
	 * 这里测试 WordPress 原生的序列化处理
	 */
	public function test_serialized_data_handling() {
		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];

		// 添加序列化数据
		$array_data = array( 'key1' => 'value1', 'key2' => 'value2' );
		update_post_meta( $post_id, 'serialized_test', $array_data );

		// 验证 WordPress 能正确存储和检索序列化数据
		$retrieved = get_post_meta( $post_id, 'serialized_test', true );

		$this->assertIsArray( $retrieved );
		$this->assertEquals( 'value1', $retrieved['key1'] );
		$this->assertEquals( 'value2', $retrieved['key2'] );

		// 测试 maybe_unserialize 函数工作正常
		$raw = get_post_meta( $post_id, 'serialized_test', false );
		$unserialized = maybe_unserialize( $raw[0] );
		$this->assertIsArray( $unserialized );
	}

	/**
	 * 测试特殊字符处理
	 */
	public function test_special_characters_handling() {
		$post_id = wp_insert_post( array(
			'post_title'   => 'Test 中文 日本語 한국어 ' . time(),
			'post_content' => '<p>Special chars: &amp; &lt; &gt; "quotes" \'apostrophe\'</p>',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		if ( $post_id ) {
			$this->test_post_ids['special'] = $post_id;

			$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

			// 验证中文等字符被正确保留
			$this->assertStringContainsString( '中文', $complete_data['post']['post_title'] );
			$this->assertStringContainsString( '日本語', $complete_data['post']['post_title'] );
		}
	}

	// ==================== 比较辅助函数 ====================

	/**
	 * 测试内容相似度计算
	 */
	public function test_content_similarity_calculation() {
		$text1 = 'This is the original text content.';
		$text2 = 'This is the modified text content.';
		$text3 = 'Completely different text here.';

		// 相同文本相似度应该是 100%
		$this->assertEquals( 100, $this->calculate_similarity( $text1, $text1 ) );

		// 相似文本相似度应该较高
		$similarity_high = $this->calculate_similarity( $text1, $text2 );
		$this->assertGreaterThan( 70, $similarity_high );

		// 不同文本相似度应该较低
		$similarity_low = $this->calculate_similarity( $text1, $text3 );
		$this->assertLessThan( 50, $similarity_low );
	}

	/**
	 * 计算文本相似度
	 *
	 * @param string $text1 文本1
	 * @param string $text2 文本2
	 * @return float 相似度百分比
	 */
	private function calculate_similarity( $text1, $text2 ) {
		if ( $text1 === $text2 ) {
			return 100;
		}

		$distance = levenshtein( $text1, $text2 );
		$max_len = max( strlen( $text1 ), strlen( $text2 ) );

		if ( $max_len === 0 ) {
			return 100;
		}

		return round( ( 1 - $distance / $max_len ) * 100, 2 );
	}

	/**
	 * 测试完整数据 JSON 编码
	 */
	public function test_complete_data_json_encoding() {
		if ( empty( $this->test_post_ids['post'] ) ) {
			$this->markTestSkipped( 'Test post not available' );
		}

		$post_id = $this->test_post_ids['post'];
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 测试 JSON 编码不会丢失数据
		$json = wp_json_encode( $complete_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$this->assertNotFalse( $json );

		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertEquals( $complete_data['post']['post_title'], $decoded['post']['post_title'] );
	}
}
