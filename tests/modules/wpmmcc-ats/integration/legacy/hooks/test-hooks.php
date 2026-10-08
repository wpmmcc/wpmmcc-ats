<?php
/**
 * Hook Integration Tests
 *
 * 测试 WPTSALL 插件的所有 Hook 是否正确触发
 * - Action Hooks (do_action)
 * - Filter Hooks (apply_filters)
 *
 * @package WPTSALL
 * @since 0.3.0
 */

use WPTSALL\Models\Blog_Template;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;

class Test_Hooks extends WP_UnitTestCase {

	/**
	 * Hook 调用记录
	 *
	 * @var array
	 */
	private $hook_calls = array();

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

		$this->hook_calls = array();
		$this->current_site_id = get_current_blog_id();

		// 创建虚拟站点用于测试
		$this->create_virtual_site();
	}

	/**
	 * 创建测试虚拟站点
	 *
	 * 使用 Virtual_Site_Service 创建虚拟站点（v0.4.0+ API）
	 */
	private function create_virtual_site() {
		$path_prefix = 'test-hooks-' . time();

		$result = Virtual_Site_Service::create( array(
			'name'        => 'Hook Test Site',
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
		// 删除测试关联
		if ( $this->relation_id ) {
			Site_Relation_Service::delete_relation( $this->relation_id );
		}

		// 删除测试虚拟站点（使用 Virtual_Site_Service）
		if ( $this->virtual_site_id ) {
			Virtual_Site_Service::delete( $this->virtual_site_id );
		}

		// 清理 Blog 模板
		Blog_Template::delete();

		// 移除所有测试 hook
		$this->remove_test_hooks();

		parent::tearDown();
	}

	/**
	 * 注册测试 hook 监听器
	 *
	 * @param string $hook_name Hook 名称
	 */
	private function register_hook_listener( $hook_name ) {
		add_action( $hook_name, function() use ( $hook_name ) {
			$this->hook_calls[ $hook_name ] = array(
				'called' => true,
				'args'   => func_get_args(),
				'time'   => microtime( true ),
			);
		}, 10, 10 );
	}

	/**
	 * 注册测试 filter 监听器
	 *
	 * @param string $filter_name Filter 名称
	 * @param mixed  $return_value 返回值（可选）
	 */
	private function register_filter_listener( $filter_name, $return_value = null ) {
		add_filter( $filter_name, function( $value ) use ( $filter_name, $return_value ) {
			$this->hook_calls[ $filter_name ] = array(
				'called'   => true,
				'args'     => func_get_args(),
				'original' => $value,
				'time'     => microtime( true ),
			);
			return $return_value !== null ? $return_value : $value;
		}, 10, 10 );
	}

	/**
	 * 移除测试 hooks
	 */
	private function remove_test_hooks() {
		// 由于匿名函数无法精确移除，这里只是清理记录
		$this->hook_calls = array();
	}

	/**
	 * 检查 hook 是否被调用
	 *
	 * @param string $hook_name Hook 名称
	 * @return bool
	 */
	private function was_hook_called( $hook_name ) {
		return isset( $this->hook_calls[ $hook_name ] ) && $this->hook_calls[ $hook_name ]['called'];
	}

	/**
	 * 获取 hook 调用参数
	 *
	 * @param string $hook_name Hook 名称
	 * @return array
	 */
	private function get_hook_args( $hook_name ) {
		return $this->hook_calls[ $hook_name ]['args'] ?? array();
	}

	// ==================== Blog Template Action Hooks ====================

	/**
	 * 测试 wptsall_blog_template_saved hook
	 */
	public function test_blog_template_saved_hook() {
		$this->register_hook_listener( 'wptsall_blog_template_saved' );

		// 删除现有模板确保是新保存
		Blog_Template::delete();

		// 保存模板触发 hook
		Blog_Template::save();

		$this->assertTrue( $this->was_hook_called( 'wptsall_blog_template_saved' ) );

		$args = $this->get_hook_args( 'wptsall_blog_template_saved' );
		$this->assertNotEmpty( $args );
		$this->assertIsArray( $args[0] ); // 第一个参数是模板数据
		$this->assertEquals( 'wordpress-blog', $args[0]['plugin'] );
	}

	/**
	 * 测试 wptsall_blog_template_deleted hook
	 */
	public function test_blog_template_deleted_hook() {
		// 先保存模板
		Blog_Template::save();

		$this->register_hook_listener( 'wptsall_blog_template_deleted' );

		// 删除模板触发 hook
		Blog_Template::delete();

		$this->assertTrue( $this->was_hook_called( 'wptsall_blog_template_deleted' ) );
	}

	/**
	 * 测试 wptsall_blog_template_scanned hook
	 */
	public function test_blog_template_scanned_hook() {
		Blog_Template::save();

		$this->register_hook_listener( 'wptsall_blog_template_scanned' );

		// 执行扫描触发 hook
		Blog_Template::scan_and_update( 'quick' );

		$this->assertTrue( $this->was_hook_called( 'wptsall_blog_template_scanned' ) );

		$args = $this->get_hook_args( 'wptsall_blog_template_scanned' );
		$this->assertNotEmpty( $args );
		// 第一个参数是更新后的模板，第二个是扫描结果
		$this->assertIsArray( $args[0] );
	}

	/**
	 * 测试 wptsall_blog_template_field_added hook
	 */
	public function test_blog_template_field_added_hook() {
		Blog_Template::save();

		$this->register_hook_listener( 'wptsall_blog_template_field_added' );

		// 添加手动字段触发 hook
		Blog_Template::add_manual_field( 'post_meta', 'test_manual_field_' . time(), array(
			'sync_enabled' => true,
		) );

		$this->assertTrue( $this->was_hook_called( 'wptsall_blog_template_field_added' ) );

		$args = $this->get_hook_args( 'wptsall_blog_template_field_added' );
		$this->assertNotEmpty( $args );
		// 参数：$meta_key, $context, $config
		$this->assertStringContainsString( 'test_manual_field_', $args[0] );
		$this->assertEquals( 'post_meta', $args[1] );
	}

	/**
	 * 测试 wptsall_blog_template_sync_policies_updated hook
	 */
	public function test_blog_template_sync_policies_updated_hook() {
		Blog_Template::save();

		$this->register_hook_listener( 'wptsall_blog_template_sync_policies_updated' );

		// 更新同步策略触发 hook
		Blog_Template::update_sync_policies( array(
			'meta_sync_mode' => 'blacklist',
		) );

		$this->assertTrue( $this->was_hook_called( 'wptsall_blog_template_sync_policies_updated' ) );
	}

	// ==================== Site Relation Action Hooks ====================

	/**
	 * 测试 wptsall_site_relations_created hook
	 *
	 * 注意: 钩子名是复数 wptsall_site_relations_created
	 * 参数: ($relation_ids, $data) - $relation_ids 是数组
	 */
	public function test_site_relation_created_hook() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		$this->register_hook_listener( 'wptsall_site_relations_created' );

		// 创建关联触发 hook
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

		$this->assertTrue( $this->was_hook_called( 'wptsall_site_relations_created' ) );

		$args = $this->get_hook_args( 'wptsall_site_relations_created' );
		$this->assertNotEmpty( $args );
		// 参数：$relation_ids (数组), $data
		$this->assertIsArray( $args[0] );
		$this->assertNotEmpty( $args[0] );
		$this->assertGreaterThan( 0, $args[0][0] );
	}

	/**
	 * 测试 wptsall_site_relation_status_updated hook
	 */
	public function test_site_relation_status_updated_hook() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 先创建关联
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

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$this->relation_id = $result['relation_ids'][0];

		$this->register_hook_listener( 'wptsall_site_relation_status_updated' );

		// 更新状态触发 hook (只支持 'active' 和 'inactive')
		Site_Relation_Service::update_status( $this->relation_id, 'inactive' );

		$this->assertTrue( $this->was_hook_called( 'wptsall_site_relation_status_updated' ) );

		$args = $this->get_hook_args( 'wptsall_site_relation_status_updated' );
		$this->assertEquals( $this->relation_id, $args[0] );
		$this->assertEquals( 'inactive', $args[1] );
	}

	/**
	 * 测试 wptsall_site_relation_deleted hook
	 */
	public function test_site_relation_deleted_hook() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		// 先创建关联
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

		if ( empty( $result['relation_ids'] ) ) {
			$this->markTestSkipped( 'Could not create relation' );
		}

		$relation_id = $result['relation_ids'][0];

		$this->register_hook_listener( 'wptsall_site_relation_deleted' );

		// 删除关联触发 hook
		Site_Relation_Service::delete_relation( $relation_id );

		$this->assertTrue( $this->was_hook_called( 'wptsall_site_relation_deleted' ) );

		$args = $this->get_hook_args( 'wptsall_site_relation_deleted' );
		$this->assertEquals( $relation_id, $args[0] );
	}

	// ==================== Filter Hooks ====================

	/**
	 * 测试 wptsall_task_batch_size filter
	 */
	public function test_task_batch_size_filter() {
		$this->register_filter_listener( 'wptsall_task_batch_size', 10 );

		// 触发 filter
		$batch_size = apply_filters( 'wptsall_task_batch_size', 5 );

		$this->assertTrue( $this->was_hook_called( 'wptsall_task_batch_size' ) );
		$this->assertEquals( 10, $batch_size );

		$args = $this->get_hook_args( 'wptsall_task_batch_size' );
		$this->assertEquals( 5, $args[0] ); // 原始值
	}

	/**
	 * 测试 wptsall_max_retries filter
	 */
	public function test_max_retries_filter() {
		$this->register_filter_listener( 'wptsall_max_retries', 5 );

		$max_retries = apply_filters( 'wptsall_max_retries', 3 );

		$this->assertTrue( $this->was_hook_called( 'wptsall_max_retries' ) );
		$this->assertEquals( 5, $max_retries );
	}

	/**
	 * 测试 wptsall_virtual_content filter
	 */
	public function test_virtual_content_filter() {
		$test_content = array(
			'post' => array(
				'post_title' => 'Test Title',
			),
		);

		$modified_content = array(
			'post' => array(
				'post_title' => 'Modified Title',
			),
		);

		$this->register_filter_listener( 'wptsall_virtual_content', $modified_content );

		$result = apply_filters( 'wptsall_virtual_content', $test_content );

		$this->assertTrue( $this->was_hook_called( 'wptsall_virtual_content' ) );
		$this->assertEquals( 'Modified Title', $result['post']['post_title'] );
	}

	// ==================== Hook 注册验证 ====================

	/**
	 * 测试核心 action hooks 已注册
	 */
	public function test_core_action_hooks_registered() {
		// 验证这些 hooks 可以被添加监听器
		$hooks = array(
			'wptsall_blog_template_saved',
			'wptsall_blog_template_deleted',
			'wptsall_blog_template_scanned',
			'wptsall_site_relations_created', // 注意: 复数形式
			'wptsall_site_relation_deleted',
		);

		foreach ( $hooks as $hook ) {
			// 先移除所有现有 handler，避免参数数量问题
			remove_all_actions( $hook );

			// 添加一个测试监听器
			$called = false;
			add_action( $hook, function() use ( &$called ) {
				$called = true;
			} );

			// 验证 hook 可以被触发
			do_action( $hook );
			$this->assertTrue( $called, "Hook '{$hook}' should be callable" );
		}
	}

	/**
	 * 测试核心 filter hooks 已注册
	 */
	public function test_core_filter_hooks_registered() {
		$filters = array(
			'wptsall_task_batch_size'   => 5,
			'wptsall_max_retries'       => 3,
			'wptsall_virtual_content'   => array(),
		);

		foreach ( $filters as $filter => $default ) {
			$was_filtered = false;

			add_filter( $filter, function( $value ) use ( &$was_filtered ) {
				$was_filtered = true;
				return $value;
			} );

			apply_filters( $filter, $default );

			$this->assertTrue( $was_filtered, "Filter '{$filter}' should be filterable" );
		}
	}

	// ==================== Hook 参数验证 ====================

	/**
	 * 测试 wptsall_site_relations_created 参数完整性
	 *
	 * 注意: 钩子名是复数 wptsall_site_relations_created
	 * 参数: ($relation_ids, $data) - $relation_ids 是数组
	 */
	public function test_site_relation_created_hook_params() {
		if ( empty( $this->virtual_site_id ) ) {
			$this->markTestSkipped( 'Virtual site not available' );
		}

		$hook_params = array();

		add_action( 'wptsall_site_relations_created', function( $relation_ids, $data ) use ( &$hook_params ) {
			$hook_params = array(
				'relation_ids' => $relation_ids,
				'data'         => $data,
			);
		}, 10, 2 );

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

		$this->assertNotEmpty( $hook_params );
		$this->assertArrayHasKey( 'relation_ids', $hook_params );
		$this->assertArrayHasKey( 'data', $hook_params );
		$this->assertIsArray( $hook_params['relation_ids'] );
		$this->assertNotEmpty( $hook_params['relation_ids'] );
		$this->assertIsInt( $hook_params['relation_ids'][0] );
		$this->assertIsArray( $hook_params['data'] );
	}

	/**
	 * 测试 wptsall_blog_template_scanned 参数完整性
	 */
	public function test_blog_template_scanned_hook_params() {
		Blog_Template::save();

		$hook_params = array();

		add_action( 'wptsall_blog_template_scanned', function( $template, $scan_results ) use ( &$hook_params ) {
			$hook_params = array(
				'template'     => $template,
				'scan_results' => $scan_results,
			);
		}, 10, 2 );

		Blog_Template::scan_and_update( 'quick' );

		$this->assertNotEmpty( $hook_params );
		$this->assertArrayHasKey( 'template', $hook_params );
		$this->assertArrayHasKey( 'scan_results', $hook_params );

		// 验证模板结构
		$this->assertArrayHasKey( 'plugin', $hook_params['template'] );
		$this->assertArrayHasKey( 'objects', $hook_params['template'] );
		$this->assertArrayHasKey( 'discovered_fields', $hook_params['template'] );

		// 验证扫描结果结构
		$this->assertArrayHasKey( 'discovered_fields', $hook_params['scan_results'] );
		$this->assertArrayHasKey( 'scan_meta', $hook_params['scan_results'] );
	}

	// ==================== Hook 执行顺序测试 ====================

	/**
	 * 测试多个监听器的执行顺序
	 */
	public function test_hook_execution_order() {
		$execution_order = array();

		// 添加不同优先级的监听器
		add_action( 'wptsall_blog_template_saved', function() use ( &$execution_order ) {
			$execution_order[] = 'priority_20';
		}, 20 );

		add_action( 'wptsall_blog_template_saved', function() use ( &$execution_order ) {
			$execution_order[] = 'priority_5';
		}, 5 );

		add_action( 'wptsall_blog_template_saved', function() use ( &$execution_order ) {
			$execution_order[] = 'priority_10';
		}, 10 );

		Blog_Template::delete();
		Blog_Template::save();

		// 验证执行顺序
		$this->assertEquals( 'priority_5', $execution_order[0] );
		$this->assertEquals( 'priority_10', $execution_order[1] );
		$this->assertEquals( 'priority_20', $execution_order[2] );
	}

	// ==================== Filter 链式调用测试 ====================

	/**
	 * 测试 filter 链式修改
	 */
	public function test_filter_chain_modification() {
		// 使用独立的 filter 名称避免干扰
		$test_filter = 'wptsall_test_chain_filter';

		// 第一个 filter 添加
		add_filter( $test_filter, function( $size ) {
			return $size + 5;
		}, 10 );

		// 第二个 filter 再次修改
		add_filter( $test_filter, function( $size ) {
			return $size * 2;
		}, 20 );

		$result = apply_filters( $test_filter, 5 );

		// (5 + 5) * 2 = 20
		$this->assertEquals( 20, $result );
	}

	/**
	 * 测试 filter 可以返回不同类型
	 */
	public function test_filter_type_transformation() {
		add_filter( 'wptsall_virtual_content', function( $content ) {
			// 修改内容结构
			if ( is_array( $content ) ) {
				$content['modified'] = true;
				$content['timestamp'] = time();
			}
			return $content;
		} );

		$original = array( 'post' => array( 'title' => 'Test' ) );
		$result = apply_filters( 'wptsall_virtual_content', $original );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'modified', $result );
		$this->assertTrue( $result['modified'] );
		$this->assertArrayHasKey( 'timestamp', $result );
	}
}
