<?php
/**
 * WPTSALL 插件字段同步测试
 *
 * 测试 SEO 插件和 ACF 自定义字段的同步功能
 *
 * 运行方式：
 * wp eval-file /Users/zhangxiao/wptsall-dev/tests/integration/models/test-plugin-fields.php
 *
 * NOTE: This is a standalone test script, not compatible with the integration test runner.
 *
 * @package WPTSALL
 * @since 0.3.0
 */

// Skip auto-execution when included by test runner
if ( defined( 'WPTSALL_TEST_RUNNER' ) && WPTSALL_TEST_RUNNER ) {
	return;
}

// 确保在 WordPress 环境中运行
if ( ! defined( 'ABSPATH' ) ) {
	$wp_load_paths = array(
		dirname( dirname( __DIR__ ) ) . '/wp-load.php',
		dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php',
		'../../../wp-load.php',
	);

	$wp_loaded = false;
	foreach ( $wp_load_paths as $path ) {
		if ( file_exists( $path ) ) {
			define( 'WP_USE_THEMES', false );
			require_once $path;
			$wp_loaded = true;
			break;
		}
	}

	if ( ! $wp_loaded ) {
		die( "错误: 无法加载 WordPress。\n" );
	}
}

// 确保插件已激活
if ( ! defined( 'WPTSALL_PATH' ) ) {
	die( "错误: WPTSALL 插件未激活。\n" );
}

/**
 * 插件字段测试类
 */
class WPTSALL_Plugin_Fields_Tests {

	/**
	 * 测试数据前缀，避免与真实插件数据冲突
	 */
	const TEST_PREFIX = 'wptsall_test_';

	private $results = array();
	private $test_data = array(
		'posts'    => array(),
		'products' => array(),
	);

	/**
	 * 运行所有测试
	 */
	public function run_all_tests() {
		$this->log_header( 'WPTSALL 插件字段同步测试' );
		$this->log_info( '测试时间: ' . current_time( 'Y-m-d H:i:s' ) );
		$this->log_info( '插件版本: v0.3.0' );
		$this->log_separator();

		// 前置检查
		if ( ! $this->pre_check() ) {
			$this->log_error( '前置检查失败，测试终止' );
			return false;
		}

		try {
			// 测试 1: Yoast SEO 字段同步
			$this->test_yoast_seo_sync();

			// 测试 2: Rank Math SEO 字段同步
			$this->test_rank_math_sync();

			// 测试 3: ACF 字段同步
			$this->test_acf_sync();

			// 测试 4: 自定义文章类型同步
			$this->test_custom_post_type_sync();

			// 测试 5: ACF Repeater 字段同步
			$this->test_acf_repeater_sync();

		} catch ( Exception $e ) {
			$this->log_error( '测试异常: ' . $e->getMessage() );
		}

		// 清理测试数据
		$this->cleanup();

		// 输出测试摘要
		$this->log_summary();

		return $this->all_tests_passed();
	}

	/**
	 * 前置检查
	 */
	private function pre_check() {
		$this->log_section( '前置检查' );

		$checks = array(
			'Yoast SEO 插件' => function_exists( 'wpseo_auto_load' ),
			'Rank Math 插件' => defined( 'RANK_MATH_VERSION' ),
			'ACF 插件'      => function_exists( 'acf_get_field_groups' ),
			'Product 文章类型' => post_type_exists( 'product' ),
			'Portfolio 文章类型' => post_type_exists( 'portfolio' ),
		);

		$all_passed = true;
		foreach ( $checks as $name => $condition ) {
			if ( $condition ) {
				$this->log_success( "✓ $name" );
			} else {
				$this->log_warning( "✗ $name (未安装/未激活)" );
				// 不强制要求所有插件都安装
			}
		}

		// 检查核心函数
		$required_functions = array(
			'wptsall_get_complete_post_data',
			'wptsall_generate_tasks_from_template',
			'wptsall_process_task',
		);

		foreach ( $required_functions as $func ) {
			if ( ! function_exists( $func ) ) {
				$this->log_error( "✗ 缺少必需函数: $func" );
				$all_passed = false;
			}
		}

		if ( $all_passed ) {
			$this->log_info( '所有核心检查通过' );
		}

		return $all_passed;
	}

	/**
	 * 测试 1: Yoast SEO 字段同步
	 */
	private function test_yoast_seo_sync() {
		$this->log_section( '测试 1: Yoast SEO 字段同步' );

		if ( ! function_exists( 'wpseo_auto_load' ) ) {
			$this->log_warning( '跳过: Yoast SEO 未安装' );
			$this->results['yoast_seo_sync'] = 'skipped';
			return;
		}

		// 创建带 SEO 元数据的测试文章
		$post_id = wp_insert_post( array(
			'post_title'   => 'Yoast SEO 测试文章 - ' . time(),
			'post_content' => '测试 Yoast SEO 元数据同步功能。',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		if ( is_wp_error( $post_id ) ) {
			$this->log_error( '创建文章失败' );
			$this->results['yoast_seo_sync'] = false;
			return;
		}

		$this->test_data['posts'][] = $post_id;
		$this->log_info( "✓ 测试文章已创建 (ID: $post_id)" );

		// 添加 Yoast SEO 元数据
		$seo_data = array(
			'_yoast_wpseo_title'       => 'SEO 标题测试',
			'_yoast_wpseo_metadesc'    => 'SEO 描述测试内容',
			'_yoast_wpseo_focuskw'     => '测试关键词',
			'_yoast_wpseo_meta-robots-noindex' => '0',
			'_yoast_wpseo_canonical'   => 'https://example.com/test',
		);

		foreach ( $seo_data as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$this->log_info( '✓ Yoast SEO 元数据已添加 (' . count( $seo_data ) . ' 个字段)' );

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		if ( empty( $complete_data['meta'] ) ) {
			$this->log_error( '✗ 未获取到元数据' );
			$this->results['yoast_seo_sync'] = false;
			return;
		}

		// 验证 SEO 字段是否包含
		$found_seo_fields = 0;
		foreach ( $seo_data as $key => $value ) {
			if ( isset( $complete_data['meta'][ $key ] ) ) {
				$found_seo_fields++;
				$stored_value = is_array( $complete_data['meta'][ $key ] )
					? $complete_data['meta'][ $key ][0]
					: $complete_data['meta'][ $key ];

				if ( $stored_value == $value ) {
					$this->log_success( "  ✓ $key: $value" );
				} else {
					$this->log_warning( "  ⚠ $key 值不匹配" );
				}
			}
		}

		$this->log_info( "找到 $found_seo_fields / " . count( $seo_data ) . " 个 SEO 字段" );

		if ( $found_seo_fields >= 3 ) {
			$this->log_success( '✓ Yoast SEO 字段同步测试通过' );
			$this->results['yoast_seo_sync'] = true;
		} else {
			$this->log_error( '✗ Yoast SEO 字段同步不完整' );
			$this->results['yoast_seo_sync'] = false;
		}
	}

	/**
	 * 测试 2: Rank Math SEO 字段同步
	 */
	private function test_rank_math_sync() {
		$this->log_section( '测试 2: Rank Math SEO 字段同步' );

		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			$this->log_warning( '跳过: Rank Math 未安装' );
			$this->results['rank_math_sync'] = 'skipped';
			return;
		}

		// 创建测试文章
		$post_id = wp_insert_post( array(
			'post_title'   => 'Rank Math 测试文章 - ' . time(),
			'post_content' => '测试 Rank Math 元数据同步功能。',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		if ( is_wp_error( $post_id ) ) {
			$this->log_error( '创建文章失败' );
			$this->results['rank_math_sync'] = false;
			return;
		}

		$this->test_data['posts'][] = $post_id;
		$this->log_info( "✓ 测试文章已创建 (ID: $post_id)" );

		// 添加 Rank Math 元数据
		$rm_data = array(
			'rank_math_seo_score'      => '85',
			'rank_math_focus_keyword'  => 'Rank Math 测试',
			'rank_math_title'          => 'Rank Math 标题',
			'rank_math_description'    => 'Rank Math 描述',
		);

		foreach ( $rm_data as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$this->log_info( '✓ Rank Math 元数据已添加' );

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 验证字段
		$found_fields = 0;
		foreach ( $rm_data as $key => $value ) {
			if ( isset( $complete_data['meta'][ $key ] ) ) {
				$found_fields++;
				$this->log_success( "  ✓ $key 存在" );
			}
		}

		if ( $found_fields >= 2 ) {
			$this->log_success( '✓ Rank Math 字段同步测试通过' );
			$this->results['rank_math_sync'] = true;
		} else {
			$this->log_error( '✗ Rank Math 字段同步不完整' );
			$this->results['rank_math_sync'] = false;
		}
	}

	/**
	 * 测试 3: ACF 字段同步
	 */
	private function test_acf_sync() {
		$this->log_section( '测试 3: ACF 自定义字段同步' );

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			$this->log_warning( '跳过: ACF 未安装' );
			$this->results['acf_sync'] = 'skipped';
			return;
		}

		// 创建测试文章
		$post_id = wp_insert_post( array(
			'post_title'   => 'ACF 测试文章 - ' . time(),
			'post_content' => '测试 ACF 自定义字段同步。',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		if ( is_wp_error( $post_id ) ) {
			$this->log_error( '创建文章失败' );
			$this->results['acf_sync'] = false;
			return;
		}

		$this->test_data['posts'][] = $post_id;
		$this->log_info( "✓ 测试文章已创建 (ID: $post_id)" );

		// 添加 ACF 字段 (使用前缀避免与真实 ACF 字段冲突)
		$acf_data = array(
			self::TEST_PREFIX . 'subtitle'     => '这是副标题',
			self::TEST_PREFIX . 'reading_time' => 12,
			self::TEST_PREFIX . 'author_bio'   => '作者简介内容',
			self::TEST_PREFIX . 'is_featured'  => 1,
			self::TEST_PREFIX . 'custom_meta'  => '<p>自定义元数据内容</p>',
		);

		foreach ( $acf_data as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$this->log_info( '✓ ACF 字段已添加 (' . count( $acf_data ) . ' 个字段)' );

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'post', $post_id );

		// 验证 ACF 字段
		$found_fields = 0;
		foreach ( $acf_data as $key => $expected_value ) {
			if ( isset( $complete_data['meta'][ $key ] ) ) {
				$found_fields++;
				$stored_value = is_array( $complete_data['meta'][ $key ] )
					? $complete_data['meta'][ $key ][0]
					: $complete_data['meta'][ $key ];

				$this->log_success( "  ✓ $key: " . substr( $stored_value, 0, 50 ) );
			}
		}

		$this->log_info( "找到 $found_fields / " . count( $acf_data ) . " 个 ACF 字段" );

		if ( $found_fields >= 4 ) {
			$this->log_success( '✓ ACF 字段同步测试通过' );
			$this->results['acf_sync'] = true;
		} else {
			$this->log_error( '✗ ACF 字段同步不完整' );
			$this->results['acf_sync'] = false;
		}
	}

	/**
	 * 测试 4: 自定义文章类型同步
	 */
	private function test_custom_post_type_sync() {
		$this->log_section( '测试 4: 自定义文章类型 (Product) 同步' );

		if ( ! post_type_exists( 'product' ) ) {
			$this->log_warning( '跳过: Product 文章类型未注册' );
			$this->results['custom_post_type_sync'] = 'skipped';
			return;
		}

		// 创建产品
		$product_id = wp_insert_post( array(
			'post_title'   => '测试产品 - ' . time(),
			'post_content' => '产品描述内容',
			'post_status'  => 'publish',
			'post_type'    => 'product',
		) );

		if ( is_wp_error( $product_id ) ) {
			$this->log_error( '创建产品失败' );
			$this->results['custom_post_type_sync'] = false;
			return;
		}

		$this->test_data['products'][] = $product_id;
		$this->log_info( "✓ 测试产品已创建 (ID: $product_id)" );

		// 添加产品字段 (使用前缀避免与真实插件字段冲突)
		$product_data = array(
			self::TEST_PREFIX . 'product_price' => 999.99,
			self::TEST_PREFIX . 'product_sku'   => 'TEST-SKU-' . time(),
			self::TEST_PREFIX . 'product_stock' => 100,
		);

		foreach ( $product_data as $key => $value ) {
			update_post_meta( $product_id, $key, $value );
		}

		$this->log_info( '✓ 产品字段已添加' );

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'product', $product_id );

		if ( empty( $complete_data ) ) {
			$this->log_error( '✗ 无法获取产品数据' );
			$this->results['custom_post_type_sync'] = false;
			return;
		}

		// 验证产品字段
		$found_fields = 0;
		foreach ( $product_data as $key => $value ) {
			if ( isset( $complete_data['meta'][ $key ] ) ) {
				$found_fields++;
				$this->log_success( "  ✓ $key 存在" );
			}
		}

		if ( $found_fields >= 2 ) {
			$this->log_success( '✓ Product 类型同步测试通过' );
			$this->results['custom_post_type_sync'] = true;
		} else {
			$this->log_error( '✗ Product 字段同步不完整' );
			$this->results['custom_post_type_sync'] = false;
		}
	}

	/**
	 * 测试 5: ACF Repeater 字段同步
	 */
	private function test_acf_repeater_sync() {
		$this->log_section( '测试 5: ACF Repeater 字段同步' );

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			$this->log_warning( '跳过: ACF 未安装' );
			$this->results['acf_repeater_sync'] = 'skipped';
			return;
		}

		// 使用之前创建的产品
		if ( empty( $this->test_data['products'] ) ) {
			// 创建新产品
			$product_id = wp_insert_post( array(
				'post_title'   => 'Repeater 测试产品 - ' . time(),
				'post_content' => '测试 Repeater 字段',
				'post_status'  => 'publish',
				'post_type'    => 'product',
			) );
			$this->test_data['products'][] = $product_id;
		} else {
			$product_id = $this->test_data['products'][0];
		}

		$this->log_info( "使用产品 ID: $product_id" );

		// 添加 Repeater 字段 (使用前缀避免与真实 ACF 字段冲突)
		$related_links_key     = self::TEST_PREFIX . 'related_links';
		$product_features_key  = self::TEST_PREFIX . 'product_features';

		$related_links = array(
			array( 'title' => '链接1', 'url' => 'https://example.com/1' ),
			array( 'title' => '链接2', 'url' => 'https://example.com/2' ),
		);

		$product_features = array(
			array( 'name' => '特性1', 'value' => '值1' ),
			array( 'name' => '特性2', 'value' => '值2' ),
		);

		update_post_meta( $product_id, $related_links_key, $related_links );
		update_post_meta( $product_id, $product_features_key, $product_features );

		$this->log_info( '✓ Repeater 字段已添加' );

		// 获取完整数据
		$complete_data = wptsall_get_complete_post_data( 'product', $product_id );

		// 验证 Repeater 字段
		$found_repeaters = 0;

		if ( isset( $complete_data['meta'][ $related_links_key ] ) ) {
			$found_repeaters++;
			$this->log_success( '  ✓ ' . $related_links_key . ' Repeater 存在' );
		}

		if ( isset( $complete_data['meta'][ $product_features_key ] ) ) {
			$found_repeaters++;
			$this->log_success( '  ✓ ' . $product_features_key . ' Repeater 存在' );
		}

		if ( $found_repeaters >= 1 ) {
			$this->log_success( '✓ ACF Repeater 字段同步测试通过' );
			$this->results['acf_repeater_sync'] = true;
		} else {
			$this->log_error( '✗ ACF Repeater 字段同步失败' );
			$this->results['acf_repeater_sync'] = false;
		}
	}

	/**
	 * 清理测试数据
	 */
	private function cleanup() {
		$this->log_separator();
		$this->log_info( '清理测试数据...' );

		// 删除文章
		foreach ( $this->test_data['posts'] as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// 删除产品
		foreach ( $this->test_data['products'] as $product_id ) {
			wp_delete_post( $product_id, true );
		}

		$total_deleted = count( $this->test_data['posts'] ) + count( $this->test_data['products'] );
		$this->log_info( "✓ 已删除 $total_deleted 个测试内容" );
	}

	/**
	 * 输出测试摘要
	 */
	private function log_summary() {
		$this->log_separator();
		$this->log_header( '测试摘要' );

		$total = 0;
		$passed = 0;
		$skipped = 0;

		foreach ( $this->results as $test => $result ) {
			$total++;
			$status = '';

			if ( $result === true ) {
				$status = '[✓ 通过]';
				$passed++;
			} elseif ( $result === false ) {
				$status = '[✗ 失败]';
			} elseif ( $result === 'skipped' ) {
				$status = '[- 跳过]';
				$skipped++;
			}

			echo "$status $test\n";
		}

		$this->log_separator();
		echo "总计: $total 个测试\n";
		echo "通过: $passed\n";
		echo "跳过: $skipped\n";

		if ( $passed + $skipped === $total ) {
			echo "通过率: 100%\n\n";
			$this->log_header( '🎉 所有测试通过！' );
		} else {
			echo "通过率: " . round( ( $passed / ( $total - $skipped ) ) * 100 ) . "%\n\n";
		}
	}

	/**
	 * 所有测试是否通过
	 */
	private function all_tests_passed() {
		foreach ( $this->results as $result ) {
			if ( $result === false ) {
				return false;
			}
		}
		return true;
	}

	// 日志方法
	private function log_header( $text ) {
		echo "\n======================================\n";
		echo "$text\n";
		echo "======================================\n";
	}

	private function log_section( $text ) {
		echo "\n--- $text ---\n";
	}

	private function log_separator() {
		echo "--------------------------------------\n";
	}

	private function log_info( $text ) {
		echo "$text\n";
	}

	private function log_success( $text ) {
		echo "$text\n";
	}

	private function log_warning( $text ) {
		echo "$text\n";
	}

	private function log_error( $text ) {
		echo "$text\n";
	}
}

// 运行测试
$tests = new WPTSALL_Plugin_Fields_Tests();
$tests->run_all_tests();
