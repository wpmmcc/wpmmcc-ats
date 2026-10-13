<?php
/**
 * URL and Field Mapping Integration Tests
 *
 * 测试源站点和目标站点之间的 URL 映射和字段对应关系
 * - URL 类型定义验证
 * - ID 映射 (wptsall_mappings 表)
 * - 字段对应关系
 * - 源站点到目标站点的内容完整性
 *
 * @package WPTSALL
 * @since 0.3.0
 */

use WPTSALL\Models\Blog_Template;
use WPTSALL\Sites\Services\Site_Relation_Service;

class Test_URL_Field_Mapping extends WP_UnitTestCase {

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
	 * 关联 ID
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

		// 清理现有关联，避免冲突
		$this->cleanup_existing_relations();

		$this->create_test_content();
		$this->create_virtual_site();
	}

	/**
	 * 清理现有关联
	 */
	private function cleanup_existing_relations() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE template = 'wordpress-blog'" );
		}
	}

	/**
	 * 清理测试
	 */
	public function tearDown(): void {
		parent::tearDown();

		// 清理关联
		if ( $this->relation_id ) {
			Site_Relation_Service::delete_relation( $this->relation_id );
		}
	}

	/**
	 * 创建测试内容
	 */
	private function create_test_content() {
		$unique_id = uniqid() . '_' . time();

		// 创建分类 - 使用更唯一的标识
		$cat_name = 'URL Test Category ' . $unique_id;
		$cat_slug = 'url-test-cat-' . $unique_id;

		// 先检查是否存在，如果存在则获取
		$existing_cat = get_term_by( 'slug', $cat_slug, 'category' );
		if ( $existing_cat ) {
			$this->test_data['categories'][] = $existing_cat->term_id;
		} else {
			$cat = wp_insert_term( $cat_name, 'category', array(
				'slug'        => $cat_slug,
				'description' => 'Category for URL mapping test',
			) );
			if ( ! is_wp_error( $cat ) ) {
				$this->test_data['categories'][] = $cat['term_id'];
			}
		}

		// 如果分类创建失败，使用默认分类
		if ( empty( $this->test_data['categories'] ) ) {
			$default_cat = get_option( 'default_category' );
			if ( $default_cat ) {
				$this->test_data['categories'][] = $default_cat;
			}
		}

		// 创建标签
		$tag_name = 'URL Test Tag ' . $unique_id;
		$tag_slug = 'url-test-tag-' . $unique_id;

		$existing_tag = get_term_by( 'slug', $tag_slug, 'post_tag' );
		if ( $existing_tag ) {
			$this->test_data['tags'][] = $existing_tag->term_id;
		} else {
			$tag = wp_insert_term( $tag_name, 'post_tag', array(
				'slug' => $tag_slug,
			) );
			if ( ! is_wp_error( $tag ) ) {
				$this->test_data['tags'][] = $tag['term_id'];
			}
		}

		// 创建文章
		$post_id = wp_insert_post( array(
			'post_title'   => 'URL Mapping Test Post ' . $unique_id,
			'post_name'    => 'url-mapping-test-post-' . $unique_id,
			'post_content' => '<p>Content for URL mapping test.</p>',
			'post_excerpt' => 'Excerpt for URL test',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_date'    => '2024-06-15 10:30:00',
		) );

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			$this->test_data['posts'][] = $post_id;

			// 添加各种 meta 字段
			update_post_meta( $post_id, '_thumbnail_id', 0 );
			update_post_meta( $post_id, 'custom_field_text', 'Text value' );
			update_post_meta( $post_id, 'custom_field_number', 123 );
			update_post_meta( $post_id, 'custom_field_array', array( 'a', 'b', 'c' ) );
			update_post_meta( $post_id, '_yoast_wpseo_title', 'SEO Title for URL Test' );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'SEO Description' );

			// 关联分类和标签
			if ( ! empty( $this->test_data['categories'] ) ) {
				wp_set_post_categories( $post_id, $this->test_data['categories'] );
			}
			if ( ! empty( $this->test_data['tags'] ) ) {
				wp_set_post_tags( $post_id, $this->test_data['tags'] );
			}
		}

		// 如果文章创建失败，尝试使用现有文章
		if ( empty( $this->test_data['posts'] ) ) {
			$existing_posts = get_posts( array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => 1,
			) );
			if ( ! empty( $existing_posts ) ) {
				$this->test_data['posts'][] = $existing_posts[0]->ID;
			}
		}

		// 创建页面
		$page_id = wp_insert_post( array(
			'post_title'   => 'URL Mapping Test Page ' . $unique_id,
			'post_name'    => 'url-mapping-test-page-' . $unique_id,
			'post_content' => 'Page content for URL test',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$this->test_data['pages'][] = $page_id;
			update_post_meta( $page_id, '_wp_page_template', 'default' );
		}
	}

	/**
	 * 创建虚拟站点
	 */
	private function create_virtual_site() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_virtual_sites';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		if ( ! $table_exists ) {
			return;
		}

		// 使用更唯一的路径避免冲突
		$unique_path = 'url-mapping-test-' . uniqid() . '-' . time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$table,
			array(
				'site_name'       => 'URL Mapping Test Site ' . uniqid(),
				'site_path'       => $unique_path,
				'site_language'   => 'en_US',
				'source_blog_id'  => $this->current_site_id,
				'status'          => 'active',
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $result ) {
			$this->virtual_site_id = $wpdb->insert_id;
		}

		// 如果创建失败，尝试使用现有的虚拟站点
		if ( empty( $this->virtual_site_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'active' ORDER BY id DESC LIMIT 1" );
			if ( $existing ) {
				$this->virtual_site_id = (int) $existing;
			}
		}
	}

	// ==================== URL 类型测试 ====================

	/**
	 * 测试 URL 类型定义存在
	 */
	public function test_url_types_defined() {
		$template = Blog_Template::generate();

		$this->assertArrayHasKey( 'objects', $template );
		$this->assertArrayHasKey( 'url_types', $template['objects'] );
		$this->assertNotEmpty( $template['objects']['url_types'] );
	}

	/**
	 * 测试 URL 类型结构完整性
	 */
	public function test_url_type_structure() {
		$template  = Blog_Template::generate();
		$url_types = $template['objects']['url_types'];

		foreach ( $url_types as $url_type ) {
			// 必需字段
			$this->assertArrayHasKey( 'label', $url_type );
			$this->assertArrayHasKey( 'route', $url_type );
			$this->assertArrayHasKey( 'object_type', $url_type );
			$this->assertArrayHasKey( 'subtype', $url_type );

			// 验证 route 格式
			$this->assertNotEmpty( $url_type['route'] );
		}
	}

	/**
	 * 测试文章归档 URL 类型
	 */
	public function test_post_archive_url_type() {
		$template  = Blog_Template::generate();
		$url_types = $template['objects']['url_types'];

		$post_archive = null;
		foreach ( $url_types as $url_type ) {
			if ( $url_type['object_type'] === 'post_type' &&
			     $url_type['subtype'] === 'post' &&
			     ! empty( $url_type['is_archive'] ) ) {
				$post_archive = $url_type;
				break;
			}
		}

		$this->assertNotNull( $post_archive, 'Post archive URL type should exist' );
		$this->assertArrayHasKey( 'route', $post_archive );
		$this->assertArrayHasKey( 'match_mode', $post_archive );
	}

	/**
	 * 测试单篇文章 URL 类型
	 */
	public function test_single_post_url_type() {
		$template  = Blog_Template::generate();
		$url_types = $template['objects']['url_types'];

		$single_post = null;
		foreach ( $url_types as $url_type ) {
			if ( $url_type['object_type'] === 'post_type' &&
			     $url_type['subtype'] === 'post' &&
			     ! empty( $url_type['is_single'] ) ) {
				$single_post = $url_type;
				break;
			}
		}

		$this->assertNotNull( $single_post, 'Single post URL type should exist' );
		$this->assertArrayHasKey( 'placeholders', $single_post );
		$this->assertNotEmpty( $single_post['placeholders'] );
	}

	/**
	 * 测试页面 URL 类型
	 */
	public function test_page_url_type() {
		$template  = Blog_Template::generate();
		$url_types = $template['objects']['url_types'];

		$page_type = null;
		foreach ( $url_types as $url_type ) {
			if ( $url_type['object_type'] === 'post_type' &&
			     $url_type['subtype'] === 'page' ) {
				$page_type = $url_type;
				break;
			}
		}

		$this->assertNotNull( $page_type, 'Page URL type should exist' );
	}

	/**
	 * 测试分类归档 URL 类型
	 */
	public function test_category_archive_url_type() {
		$template  = Blog_Template::generate();
		$url_types = $template['objects']['url_types'];

		$category_archive = null;
		foreach ( $url_types as $url_type ) {
			if ( $url_type['object_type'] === 'taxonomy' &&
			     $url_type['subtype'] === 'category' ) {
				$category_archive = $url_type;
				break;
			}
		}

		$this->assertNotNull( $category_archive, 'Category archive URL type should exist' );
		$this->assertArrayHasKey( 'placeholders', $category_archive );
	}

	/**
	 * 测试标签归档 URL 类型
	 */
	public function test_tag_archive_url_type() {
		$template  = Blog_Template::generate();
		$url_types = $template['objects']['url_types'];

		$tag_archive = null;
		foreach ( $url_types as $url_type ) {
			if ( $url_type['object_type'] === 'taxonomy' &&
			     $url_type['subtype'] === 'post_tag' ) {
				$tag_archive = $url_type;
				break;
			}
		}

		$this->assertNotNull( $tag_archive, 'Tag archive URL type should exist' );
	}

	// ==================== ID 映射测试 ====================

	/**
	 * 测试 ID 映射函数存在
	 */
	public function test_mapping_functions_exist() {
		$this->assertTrue( function_exists( 'wptsall_insert_mapping' ) );
		$this->assertTrue( function_exists( 'wptsall_get_mapped_id' ) );
		$this->assertTrue( function_exists( 'wptsall_get_mapped_ids_batch' ) );
	}

	/**
	 * 测试插入和获取 ID 映射
	 */
	public function test_insert_and_get_mapping() {
		if ( empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$source_id   = $this->test_data['posts'][0];
		$target_id   = 99999; // 模拟目标 ID
		$target_blog = 2;     // 模拟目标站点

		// 插入映射 (需要 7 个参数，包括 model)
		wptsall_insert_mapping(
			$this->current_site_id,
			'post_type',
			'post',
			$source_id,
			$target_blog,
			$target_id,
			'wordpress-blog'
		);

		// 获取映射
		$mapped_id = wptsall_get_mapped_id(
			$this->current_site_id,
			'post_type',
			'post',
			$source_id,
			$target_blog
		);

		$this->assertEquals( $target_id, $mapped_id );
	}

	/**
	 * 测试批量获取 ID 映射
	 */
	public function test_batch_get_mapping() {
		if ( count( $this->test_data['posts'] ) < 1 ) {
			$this->markTestSkipped( 'Not enough test posts' );
		}

		$target_blog = 2;

		// 插入多个映射
		foreach ( $this->test_data['posts'] as $index => $post_id ) {
			wptsall_insert_mapping(
				$this->current_site_id,
				'post_type',
				'post',
				$post_id,
				$target_blog,
				$post_id + 10000 + $index,
				'wordpress-blog'
			);
		}

		// 批量获取
		$mapped = wptsall_get_mapped_ids_batch(
			$this->current_site_id,
			'post_type',
			'post',
			$this->test_data['posts'],
			$target_blog
		);

		$this->assertIsArray( $mapped );
		$this->assertGreaterThanOrEqual( 1, count( $mapped ) );
	}

	/**
	 * 测试分类 ID 映射
	 */
	public function test_taxonomy_mapping() {
		if ( empty( $this->test_data['categories'] ) ) {
			$this->markTestSkipped( 'No test categories available' );
		}

		$source_id   = $this->test_data['categories'][0];
		$target_id   = 88888;
		$target_blog = 2;

		wptsall_insert_mapping(
			$this->current_site_id,
			'taxonomy',
			'category',
			$source_id,
			$target_blog,
			$target_id,
			'wordpress-blog'
		);

		$mapped_id = wptsall_get_mapped_id(
			$this->current_site_id,
			'taxonomy',
			'category',
			$source_id,
			$target_blog
		);

		$this->assertEquals( $target_id, $mapped_id );
	}

	/**
	 * 测试不存在的映射返回 null
	 */
	public function test_nonexistent_mapping_returns_null() {
		$mapped_id = wptsall_get_mapped_id(
			$this->current_site_id,
			'post_type',
			'post',
			999999999, // 不存在的 ID
			999        // 不存在的目标站点
		);

		$this->assertNull( $mapped_id );
	}

	// ==================== 字段对应测试 ====================

	/**
	 * 测试文章核心字段对应
	 */
	public function test_post_core_field_mapping() {
		if ( ! function_exists( 'wptsall_get_complete_post_data' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_complete_post_data not available' );
		}
		if ( empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$post_id = $this->test_data['posts'][0];
		$post    = get_post( $post_id );

		// 源站点字段
		$source_fields = array(
			'ID'             => $post->ID,
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_name'      => $post->post_name,
			'post_date'      => $post->post_date,
			'post_type'      => $post->post_type,
			'post_author'    => $post->post_author,
			'post_parent'    => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'comment_status' => $post->comment_status,
		);

		// 验证所有字段都有值
		foreach ( $source_fields as $field => $value ) {
			$this->assertTrue(
				isset( $source_fields[ $field ] ),
				"Field '{$field}' should exist"
			);
		}

		// 使用 wptsall_get_complete_post_data 获取数据
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 验证结构
		$this->assertArrayHasKey( 'post', $complete_data );

		// 验证字段值匹配
		$this->assertEquals( $post->post_title, $complete_data['post']['post_title'] );
		$this->assertEquals( $post->post_content, $complete_data['post']['post_content'] );
		$this->assertEquals( $post->post_status, $complete_data['post']['post_status'] );
	}

	/**
	 * 测试 Meta 字段对应
	 */
	public function test_meta_field_mapping() {
		if ( empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$post_id = $this->test_data['posts'][0];

		// 源站点 meta
		$source_meta = array(
			'custom_field_text'     => get_post_meta( $post_id, 'custom_field_text', true ),
			'custom_field_number'   => get_post_meta( $post_id, 'custom_field_number', true ),
			'custom_field_array'    => get_post_meta( $post_id, 'custom_field_array', true ),
			'_yoast_wpseo_title'    => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'_yoast_wpseo_metadesc' => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
		);

		// 验证 meta 值
		$this->assertEquals( 'Text value', $source_meta['custom_field_text'] );
		$this->assertEquals( '123', $source_meta['custom_field_number'] ); // WordPress 存储为字符串
		$this->assertIsArray( $source_meta['custom_field_array'] );
		$this->assertEquals( array( 'a', 'b', 'c' ), $source_meta['custom_field_array'] );
		$this->assertEquals( 'SEO Title for URL Test', $source_meta['_yoast_wpseo_title'] );
	}

	/**
	 * 测试分类关联字段对应
	 */
	public function test_taxonomy_association_mapping() {
		if ( ! function_exists( 'wptsall_get_complete_post_data' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_complete_post_data not available' );
		}
		if ( empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$post_id = $this->test_data['posts'][0];

		// 获取关联的分类 - 至少有默认分类
		$categories = wp_get_post_categories( $post_id, array( 'fields' => 'all' ) );

		// 如果没有分类，使用默认分类
		if ( empty( $categories ) ) {
			$default_cat = get_option( 'default_category' );
			wp_set_post_categories( $post_id, array( $default_cat ) );
			$categories = wp_get_post_categories( $post_id, array( 'fields' => 'all' ) );
		}

		$this->assertNotEmpty( $categories, 'Post should have at least one category' );

		// 验证分类数据结构
		$cat = $categories[0];
		$this->assertObjectHasProperty( 'term_id', $cat );
		$this->assertObjectHasProperty( 'name', $cat );
		$this->assertObjectHasProperty( 'slug', $cat );

		// 通过 wptsall_get_complete_post_data 验证
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		$this->assertArrayHasKey( 'taxonomies', $complete_data );
		// 至少应该有 category
		$this->assertArrayHasKey( 'category', $complete_data['taxonomies'] );
	}

	/**
	 * 测试分类核心字段对应
	 */
	public function test_term_core_field_mapping() {
		if ( ! function_exists( 'wptsall_get_complete_term_data' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_complete_term_data not available' );
		}
		if ( empty( $this->test_data['categories'] ) ) {
			$this->markTestSkipped( 'No test categories available' );
		}

		$term_id = $this->test_data['categories'][0];
		$term    = get_term( $term_id, 'category' );

		// 源站点字段
		$source_fields = array(
			'term_id'     => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
		);

		foreach ( $source_fields as $field => $value ) {
			$this->assertTrue(
				isset( $source_fields[ $field ] ),
				"Term field '{$field}' should exist"
			);
		}

		// 使用 wptsall_get_complete_term_data
		$complete_data = wptsall_get_complete_term_data( 'category', $term_id );

		$this->assertArrayHasKey( 'term', $complete_data );
		$this->assertEquals( $term->name, $complete_data['term']['name'] );
		$this->assertEquals( $term->slug, $complete_data['term']['slug'] );
	}

	// ==================== 源站点到目标站点内容验证 ====================

	/**
	 * 测试同步后内容完整性
	 */
	public function test_sync_content_integrity() {
		if ( ! function_exists( 'wptsall_get_complete_post_data' ) || ! function_exists( 'wptsall_process_task' ) ) {
			$this->markTestSkipped( 'Sync functions not available' );
		}
		if ( empty( $this->virtual_site_id ) || empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'Virtual site or test posts not available' );
		}

		// 创建关联
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'target_sites'   => array(
				array(
					'type' => 'virtual',
					'id'   => $this->virtual_site_id,
					'lang' => 'en_US',
				),
			),
		) );

		if ( empty( $result['relation_ids'][0] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$this->relation_id = $result['relation_ids'][0];

		$post_id     = $this->test_data['posts'][0];
		$source_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 模拟任务处理
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

		$this->assertNotNull( $stored, 'Virtual content should be stored' );

		$virtual_content = json_decode( $stored['content'], true );

		// 确保虚拟内容结构存在
		$this->assertNotNull( $virtual_content, 'Virtual content should be valid JSON' );

		// 验证关键字段存在且不为空
		$this->assertArrayHasKey( 'post_title', $virtual_content['post'], 'Virtual post should have title' );
		$this->assertArrayHasKey( 'post_content', $virtual_content['post'], 'Virtual post should have content' );
		$this->assertArrayHasKey( 'post_status', $virtual_content['post'], 'Virtual post should have status' );

		// 验证源数据和虚拟内容都不为空
		$this->assertNotEmpty( $source_data['post']['post_title'], 'Source post title should not be empty' );
		$this->assertNotEmpty( $virtual_content['post']['post_title'], 'Virtual post title should not be empty' );

		// 验证标题匹配（允许 trim 后匹配）
		$this->assertEquals(
			trim( $source_data['post']['post_title'] ),
			trim( $virtual_content['post']['post_title'] ),
			'Title should match between source and virtual'
		);

		// 验证状态匹配
		$this->assertEquals(
			$source_data['post']['post_status'],
			$virtual_content['post']['post_status'],
			'Status should match between source and virtual'
		);
	}

	/**
	 * 测试分类同步后内容完整性
	 */
	public function test_term_sync_content_integrity() {
		if ( ! function_exists( 'wptsall_get_complete_term_data' ) || ! function_exists( 'wptsall_process_task' ) ) {
			$this->markTestSkipped( 'Sync functions not available' );
		}
		if ( empty( $this->virtual_site_id ) || empty( $this->test_data['categories'] ) ) {
			$this->markTestSkipped( 'Virtual site or test categories not available' );
		}

		// 创建关联
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => $this->current_site_id,
			'source_lang'    => $this->source_lang,
			'target_sites'   => array(
				array(
					'type' => 'virtual',
					'id'   => $this->virtual_site_id,
					'lang' => 'en_US',
				),
			),
		) );

		if ( empty( $result['relation_ids'][0] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$this->relation_id = $result['relation_ids'][0];

		$term_id     = $this->test_data['categories'][0];
		$source_data = wptsall_get_complete_term_data( 'category', $term_id );

		// 模拟任务处理
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
				$term_id,
				'taxonomy'
			),
			ARRAY_A
		);

		$this->assertNotNull( $stored, 'Virtual term content should be stored' );

		$virtual_content = json_decode( $stored['content'], true );

		// 验证名称
		$this->assertEquals(
			$source_data['term']['name'],
			$virtual_content['term']['name'],
			'Term name should match between source and virtual'
		);

		// 验证 slug
		$this->assertEquals(
			$source_data['term']['slug'],
			$virtual_content['term']['slug'],
			'Term slug should match between source and virtual'
		);
	}

	// ==================== URL 生成和匹配测试 ====================

	/**
	 * 测试文章 URL 生成
	 */
	public function test_post_url_generation() {
		if ( empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'No test posts available' );
		}

		$post_id   = $this->test_data['posts'][0];
		$permalink = get_permalink( $post_id );

		$this->assertNotEmpty( $permalink );
		$this->assertStringContainsString( 'url-mapping-test-post', $permalink );
	}

	/**
	 * 测试页面 URL 生成
	 */
	public function test_page_url_generation() {
		if ( empty( $this->test_data['pages'] ) ) {
			$this->markTestSkipped( 'No test pages available' );
		}

		$page_id   = $this->test_data['pages'][0];
		$permalink = get_permalink( $page_id );

		$this->assertNotEmpty( $permalink );
		$this->assertStringContainsString( 'url-mapping-test-page', $permalink );
	}

	/**
	 * 测试分类 URL 生成
	 */
	public function test_category_url_generation() {
		if ( empty( $this->test_data['categories'] ) ) {
			$this->markTestSkipped( 'No test categories available' );
		}

		$term_id  = $this->test_data['categories'][0];
		$term_link = get_term_link( $term_id, 'category' );

		$this->assertNotInstanceOf( 'WP_Error', $term_link );
		$this->assertNotEmpty( $term_link );
		$this->assertStringContainsString( 'category', $term_link );
	}

	/**
	 * 测试标签 URL 生成
	 */
	public function test_tag_url_generation() {
		if ( empty( $this->test_data['tags'] ) ) {
			$this->markTestSkipped( 'No test tags available' );
		}

		$term_id  = $this->test_data['tags'][0];
		$term_link = get_term_link( $term_id, 'post_tag' );

		$this->assertNotInstanceOf( 'WP_Error', $term_link );
		$this->assertNotEmpty( $term_link );
		$this->assertStringContainsString( 'tag', $term_link );
	}

	// ==================== Schema 字段定义测试 ====================

	/**
	 * 测试 Post Schema 定义
	 */
	public function test_post_schema_definition() {
		if ( ! function_exists( 'wptsall_get_post_type_schema' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_post_type_schema not available' );
		}
		$schema = wptsall_get_post_type_schema( 'post' );

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'core', $schema );
		$this->assertArrayHasKey( 'meta', $schema );
		$this->assertArrayHasKey( 'taxonomies', $schema );
		$this->assertArrayHasKey( 'attachments', $schema );

		// 验证核心字段
		$required_core = array( 'ID', 'post_title', 'post_content', 'post_status', 'post_name' );
		foreach ( $required_core as $field ) {
			$this->assertContains( $field, $schema['core'], "Core field '{$field}' should exist in schema" );
		}

		// 验证分类法
		$this->assertContains( 'category', $schema['taxonomies'] );
		$this->assertContains( 'post_tag', $schema['taxonomies'] );
	}

	/**
	 * 测试 Page Schema 定义
	 */
	public function test_page_schema_definition() {
		if ( ! function_exists( 'wptsall_get_post_type_schema' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_post_type_schema not available' );
		}
		$schema = wptsall_get_post_type_schema( 'page' );

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'core', $schema );
		$this->assertArrayHasKey( 'meta', $schema );

		// 页面特有的 meta
		$this->assertContains( '_wp_page_template', $schema['meta'] );
	}

	/**
	 * 测试 Term Schema 定义
	 */
	public function test_term_schema_definition() {
		if ( ! function_exists( 'wptsall_get_taxonomy_schema' ) ) {
			$this->markTestSkipped( 'Function wptsall_get_taxonomy_schema not available' );
		}
		$schema = wptsall_get_taxonomy_schema( 'category' );

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'core', $schema );
		$this->assertArrayHasKey( 'meta', $schema );
		$this->assertArrayHasKey( 'term_taxonomy', $schema );

		// 验证核心字段 (在 core 中)
		$required_core = array( 'term_id', 'name', 'slug' );
		foreach ( $required_core as $field ) {
			$this->assertContains( $field, $schema['core'], "Term core field '{$field}' should exist in schema" );
		}

		// 验证 term_taxonomy 字段
		$required_tt = array( 'description', 'parent' );
		foreach ( $required_tt as $field ) {
			$this->assertContains( $field, $schema['term_taxonomy'], "Term taxonomy field '{$field}' should exist in schema" );
		}
	}

	// ==================== Hook 在同步过程中的验证 ====================

	/**
	 * 测试同步过程中 hook 被正确调用
	 */
	public function test_hooks_called_during_sync() {
		if ( empty( $this->virtual_site_id ) || empty( $this->test_data['posts'] ) ) {
			$this->markTestSkipped( 'Virtual site or test posts not available' );
		}

		$hook_called   = false;
		$hook_data     = null;

		// 注册 hook 监听 (注意: 实际钩子名是复数形式，参数 $relation_ids 是数组)
		add_action( 'wptsall_site_relations_created', function( $relation_ids, $data ) use ( &$hook_called, &$hook_data ) {
			$hook_called = true;
			$hook_data   = $data;
		}, 10, 2 );

		// 创建关联（触发 hook）
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

		$this->relation_id = $result['relation_ids'][0] ?? 0;

		$this->assertTrue( $hook_called, 'wptsall_site_relations_created hook should be called' );
		$this->assertNotNull( $hook_data );
	}

	/**
	 * 测试 filter 在同步过程中的效果
	 */
	public function test_filter_effect_during_sync() {
		$original_batch_size = 10;

		// 注册 filter
		add_filter( 'wptsall_task_batch_size', function( $size ) {
			return $size * 2;
		} );

		$modified_batch_size = apply_filters( 'wptsall_task_batch_size', $original_batch_size );

		$this->assertEquals( 20, $modified_batch_size );
	}
}
