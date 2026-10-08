<?php
/**
 * Template Entry Service 性能测试
 *
 * 测试缓存和批量操作优化效果
 *
 * @package WPTSALL\Tests
 */

use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Templates\Services\Template_Entry_Service;

/**
 * 测试 Template_Entry_Service 性能优化
 */
class Test_Template_Entry_Performance {

	/**
	 * 测试模板 ID
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * 构造函数 - 创建测试数据
	 */
	public function __construct() {
		// 确保模板表存在
		wptsall_ensure_templates_tables();

		// 创建测试模板
		$this->template_id = Template_Service::create( array(
			'relation_id'  => 999, // 测试用 relation_id
			'slug'         => 'perf-test-' . time() . '-' . wp_rand( 1000, 9999 ),
			'source_type'  => 'plugin',
			'text_domain'  => 'perf-test-domain',
			'source_name'  => 'Performance Test',
			'status'       => 'active',
		) );
	}

	/**
	 * 析构函数 - 清理测试数据
	 */
	public function __destruct() {
		if ( $this->template_id ) {
			Template_Service::delete( $this->template_id );
		}
		// 清除缓存
		Template_Entry_Service::clear_translations_cache( 999, 'perf-test-domain' );
	}

	/**
	 * 测试 bulk_create 基本功能
	 */
	public function test_bulk_create_basic() {
		$entry_count = 20;
		$entries     = array();

		for ( $i = 0; $i < $entry_count; $i++ ) {
			$entries[] = array(
				'template_id' => $this->template_id,
				'msgid'       => "Bulk test message {$i}",
				'msgctxt'     => 'test_context',
				'status'      => 'pending',
				'source'      => 'scan',
			);
		}

		// 测试批量插入
		$start_time = microtime( true );
		$result     = Template_Entry_Service::bulk_create( $entries );
		$bulk_time  = microtime( true ) - $start_time;

		// 断言
		if ( $result['success'] !== $entry_count ) {
			throw new Exception( "Expected {$entry_count} success, got {$result['success']}" );
		}

		if ( $result['failed'] !== 0 ) {
			throw new Exception( "Expected 0 failed, got {$result['failed']}" );
		}

		echo "   [Performance] bulk_create {$entry_count} entries: " . round( $bulk_time * 1000, 2 ) . " ms\n";

		return true;
	}

	/**
	 * 测试 get_translations_for_hook 缓存效果
	 */
	public function test_get_translations_for_hook_caching() {
		// 先创建一些翻译条目
		$entries = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$entries[] = array(
				'template_id' => $this->template_id,
				'msgid'       => "Cache test message {$i}",
				'msgstr'      => "缓存测试消息 {$i}",
				'status'      => 'translated',
				'source'      => 'manual',
			);
		}
		Template_Entry_Service::bulk_create( $entries );

		// 清除缓存
		Template_Entry_Service::clear_translations_cache( 999, 'perf-test-domain' );

		// 第一次调用（无缓存）
		$start_time   = microtime( true );
		$translations = Template_Entry_Service::get_translations_for_hook( 999, 'perf-test-domain' );
		$first_time   = microtime( true ) - $start_time;

		// 第二次调用（有缓存）
		$start_time    = microtime( true );
		$translations2 = Template_Entry_Service::get_translations_for_hook( 999, 'perf-test-domain' );
		$cached_time   = microtime( true ) - $start_time;

		// 验证结果一致
		if ( $translations !== $translations2 ) {
			throw new Exception( 'Cached result should match original result' );
		}

		echo "   [Performance] first call: " . round( $first_time * 1000, 2 ) . " ms\n";
		echo "   [Performance] cached call: " . round( $cached_time * 1000, 2 ) . " ms\n";

		return true;
	}

	/**
	 * 测试缓存清除
	 */
	public function test_cache_invalidation_on_update() {
		// 创建测试条目
		$entry_id = Template_Entry_Service::create( array(
			'template_id' => $this->template_id,
			'msgid'       => 'Cache invalidation test ' . time(),
			'msgstr'      => '原始翻译',
			'status'      => 'translated',
			'source'      => 'manual',
		) );

		if ( ! $entry_id ) {
			throw new Exception( 'Failed to create entry' );
		}

		// 获取翻译（填充缓存）
		Template_Entry_Service::get_translations_for_hook( 999, 'perf-test-domain' );

		// 更新翻译
		$update_result = Template_Entry_Service::update( $entry_id, array(
			'msgstr' => '更新后的翻译',
		) );

		if ( ! $update_result ) {
			throw new Exception( 'Failed to update entry' );
		}

		// 再次获取翻译（应该获取到新值）
		$translations = Template_Entry_Service::get_translations_for_hook( 999, 'perf-test-domain' );

		// 查找更新的条目
		$found = false;
		foreach ( $translations as $key => $value ) {
			if ( strpos( $key, 'Cache invalidation test' ) !== false ) {
				if ( $value['msgstr'] === '更新后的翻译' ) {
					$found = true;
					break;
				}
			}
		}

		if ( ! $found ) {
			throw new Exception( 'Translation should be updated after cache invalidation' );
		}

		return true;
	}

	/**
	 * 测试批量创建的错误处理
	 */
	public function test_bulk_create_error_handling() {
		$entries = array(
			// 有效条目
			array(
				'template_id' => $this->template_id,
				'msgid'       => 'Valid entry 1 ' . time(),
			),
			// 无效条目（缺少 template_id）
			array(
				'msgid' => 'Invalid entry - no template_id',
			),
			// 无效条目（缺少 msgid）
			array(
				'template_id' => $this->template_id,
			),
			// 有效条目
			array(
				'template_id' => $this->template_id,
				'msgid'       => 'Valid entry 2 ' . time(),
			),
		);

		$result = Template_Entry_Service::bulk_create( $entries );

		if ( $result['success'] !== 2 ) {
			throw new Exception( "Expected 2 success, got {$result['success']}" );
		}

		if ( $result['failed'] !== 2 ) {
			throw new Exception( "Expected 2 failed, got {$result['failed']}" );
		}

		return true;
	}

	/**
	 * 测试空数组的批量创建
	 */
	public function test_bulk_create_empty_array() {
		$result = Template_Entry_Service::bulk_create( array() );

		if ( $result['success'] !== 0 || $result['failed'] !== 0 ) {
			throw new Exception( 'Empty array should return 0 success and 0 failed' );
		}

		return true;
	}

	/**
	 * 测试 clear_translations_cache 方法存在
	 */
	public function test_clear_translations_cache_exists() {
		if ( ! method_exists( Template_Entry_Service::class, 'clear_translations_cache' ) ) {
			throw new Exception( 'clear_translations_cache method should exist' );
		}

		// 调用不应该报错
		Template_Entry_Service::clear_translations_cache( 999, 'perf-test-domain' );
		Template_Entry_Service::clear_translations_cache(); // 清除所有

		return true;
	}
}
