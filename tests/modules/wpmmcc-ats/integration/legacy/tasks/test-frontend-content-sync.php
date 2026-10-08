<?php
/**
 * Frontend Content Synchronization Integration Test
 *
 * 基于模型模板发现的 URL 进行综合测试
 *
 * 测试范围：
 * - 读取模型模板定义
 * - 获取所有实际存在的内容
 * - 生成所有 URL
 * - 测试虚拟站点和多站点的 URL 输出
 * - 使用 curl 比较源站点和目标站点
 *
 * 使用方法:
 * wp eval-file tests/integration/tasks/test-frontend-content-sync.php
 *
 * NOTE: This is a standalone WP-CLI script, not compatible with the integration test runner.
 *
 * @package WPTSALL
 * @since 0.3.0
 */

// Skip when included by test runner
if ( defined( 'WPTSALL_TEST_RUNNER' ) && WPTSALL_TEST_RUNNER ) {
	return;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return; // Silently skip when not run via WP-CLI
}

// 加载辅助类
require_once __DIR__ . '/../helpers/class-url-comparison-helper.php';

class WPTSALL_Frontend_Content_Sync_Tester {

	private $results = array();
	private $source_site_id;
	private $virtual_target_id;
	private $multisite_target_id;
	private $url_comparisons = array();
	private $templates = array();
	private $total_urls_tested = 0;

	public function run() {
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%G========== 前端内容同步综合测试（基于模板 URL）==========%n' ) );
		WP_CLI::line( '' );

		// 环境检查
		if ( ! $this->check_environment() ) {
			WP_CLI::error( '环境检查失败，无法继续测试' );
			return;
		}

		// 准备测试环境
		$this->prepare_test_environment();

		// 加载模板
		$this->load_templates();

		// 从模板生成 URL 列表
		$this->generate_urls_from_templates();

		// 执行同步（如果需要）
		$this->execute_synchronization();

		// URL 比较测试
		$this->test_url_comparisons();

		// 显示结果
		$this->display_results();
	}

	/**
	 * 检查测试环境
	 */
	private function check_environment() {
		WP_CLI::line( '检查测试环境...' );

		if ( ! is_multisite() ) {
			WP_CLI::error( '必须在多站点环境下运行此测试', false );
			return false;
		}

		if ( ! function_exists( 'curl_init' ) ) {
			WP_CLI::error( 'curl 扩展未安装', false );
			return false;
		}

		WP_CLI::success( '环境检查通过' );
		return true;
	}

	/**
	 * 准备测试环境
	 */
	private function prepare_test_environment() {
		WP_CLI::line( '准备测试环境...' );

		$this->source_site_id = get_current_blog_id();

		// 创建或获取虚拟目标站点
		$this->virtual_target_id = $this->get_virtual_target_site();

		// 创建或获取多站点目标站点
		$this->multisite_target_id = $this->get_multisite_target();

		WP_CLI::line( sprintf( '源站点 ID: %d', $this->source_site_id ) );
		WP_CLI::line( sprintf( '虚拟目标站点 ID: %d', $this->virtual_target_id ) );
		WP_CLI::line( sprintf( '多站点目标站点 ID: %d', $this->multisite_target_id ) );
		WP_CLI::line( '' );
	}

	/**
	 * 获取虚拟目标站点
	 */
	private function get_virtual_target_site() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wptsall_site_relations';

		// 检查表是否存在
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			// 表不存在，返回假设的虚拟站点 ID
			return -1;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT target_site_id FROM {$table_name}
				WHERE source_site_id = %d AND target_type = 'virtual' AND is_deleted = 0 LIMIT 1",
				$this->source_site_id
			)
		);

		return $existing ? (int) $existing : -1;
	}

	/**
	 * 获取多站点目标站点
	 */
	private function get_multisite_target() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wptsall_site_relations';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT target_site_id FROM {$table_name}
					WHERE source_site_id = %d AND target_type = 'multisite' AND is_deleted = 0 LIMIT 1",
					$this->source_site_id
				)
			);

			if ( $existing ) {
				return (int) $existing;
			}
		}

		// 获取网络中的其他站点
		$sites = get_sites(
			array(
				'number'       => 1,
				'site__not_in' => array( $this->source_site_id ),
			)
		);

		return ! empty( $sites ) ? $sites[0]->blog_id : $this->source_site_id;
	}

	/**
	 * 加载模板
	 */
	private function load_templates() {
		WP_CLI::line( '加载模板定义...' );

		// 加载 WordPress Blog 模板
		$this->load_wordpress_blog_template();

		// 加载 WooCommerce 模板（如果存在）
		$this->load_woocommerce_template();

		// 加载 bbPress 模板（如果存在）
		$this->load_bbpress_template();

		WP_CLI::success( sprintf( '加载了 %d 个模板', count( $this->templates ) ) );
		WP_CLI::line( '' );
	}

	/**
	 * 加载 WordPress Blog 模板
	 */
	private function load_wordpress_blog_template() {
		// 优先从 option 加载已保存的模板
		$template = get_option( 'wptsall_template_wordpress-blog' );

		if ( ! $template ) {
			// 如果没有保存的模板，使用类生成
			if ( class_exists( '\\WPTSALL\\Models\\Blog_Template' ) ) {
				$template = \WPTSALL\Models\Blog_Template::generate();
			} else {
				// 使用内联模板定义
				$template = $this->get_default_blog_template();
			}
		}

		if ( $template ) {
			$this->templates['wordpress-blog'] = $template;
		}
	}

	/**
	 * 获取默认 Blog 模板（内联定义）
	 */
	private function get_default_blog_template() {
		return array(
			'plugin'      => 'wordpress-blog',
			'plugin_name' => 'WordPress Blog',
			'objects'     => array(
				'post_types' => array(
					array(
						'type'    => 'post_type',
						'subtype' => 'post',
						'label'   => '文章',
					),
					array(
						'type'    => 'post_type',
						'subtype' => 'page',
						'label'   => '页面',
					),
				),
				'taxonomies' => array(
					array(
						'type'    => 'taxonomy',
						'subtype' => 'category',
						'label'   => '分类',
					),
					array(
						'type'    => 'taxonomy',
						'subtype' => 'post_tag',
						'label'   => '标签',
					),
				),
			),
		);
	}

	/**
	 * 加载 WooCommerce 模板
	 */
	private function load_woocommerce_template() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$template = get_option( 'wptsall_template_woocommerce' );

		if ( ! $template ) {
			// 简单的 WooCommerce 模板定义
			$template = array(
				'plugin'      => 'woocommerce',
				'plugin_name' => 'WooCommerce',
				'objects'     => array(
					'post_types' => array(
						array(
							'type'    => 'post_type',
							'subtype' => 'product',
							'label'   => '产品',
						),
					),
				),
			);
		}

		if ( $template ) {
			$this->templates['woocommerce'] = $template;
		}
	}

	/**
	 * 加载 bbPress 模板
	 */
	private function load_bbpress_template() {
		if ( ! class_exists( 'bbPress' ) ) {
			return;
		}

		$template = get_option( 'wptsall_template_bbpress' );

		if ( ! $template ) {
			$template = array(
				'plugin'      => 'bbpress',
				'plugin_name' => 'bbPress',
				'objects'     => array(
					'post_types' => array(
						array(
							'type'    => 'post_type',
							'subtype' => 'forum',
							'label'   => '论坛',
						),
						array(
							'type'    => 'post_type',
							'subtype' => 'topic',
							'label'   => '主题',
						),
					),
				),
			);
		}

		if ( $template ) {
			$this->templates['bbpress'] = $template;
		}
	}

	/**
	 * 从模板生成 URL 列表
	 */
	private function generate_urls_from_templates() {
		WP_CLI::line( '从模板生成 URL 列表...' );

		switch_to_blog( $this->source_site_id );

		$total_urls = 0;

		foreach ( $this->templates as $template_slug => $template ) {
			WP_CLI::line( sprintf( '处理模板: %s', $template['plugin_name'] ?? $template_slug ) );

			// 处理文章类型
			if ( ! empty( $template['objects']['post_types'] ) ) {
				foreach ( $template['objects']['post_types'] as $post_type_config ) {
					$urls = $this->generate_urls_for_post_type( $post_type_config );
					$total_urls += count( $urls );
					WP_CLI::line( sprintf( '  - %s: %d 个 URL', $post_type_config['label'], count( $urls ) ) );
				}
			}

			// 处理分类法
			if ( ! empty( $template['objects']['taxonomies'] ) ) {
				foreach ( $template['objects']['taxonomies'] as $taxonomy_config ) {
					$urls = $this->generate_urls_for_taxonomy( $taxonomy_config );
					$total_urls += count( $urls );
					WP_CLI::line( sprintf( '  - %s: %d 个 URL', $taxonomy_config['label'], count( $urls ) ) );
				}
			}
		}

		restore_current_blog();

		WP_CLI::success( sprintf( '总共生成 %d 个 URL 用于测试', $total_urls ) );
		WP_CLI::line( '' );
	}

	/**
	 * 为文章类型生成 URL
	 */
	private function generate_urls_for_post_type( $config ) {
		$post_type = $config['subtype'];

		// 获取已发布的文章
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 20, // 限制数量，避免测试时间过长
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$urls = array();

		foreach ( $posts as $post ) {
			$url = get_permalink( $post->ID );

			if ( $url ) {
				$urls[] = array(
					'url'      => $url,
					'type'     => 'post_type',
					'subtype'  => $post_type,
					'label'    => $config['label'],
					'object_id' => $post->ID,
					'title'    => get_the_title( $post->ID ),
				);
			}
		}

		return $urls;
	}

	/**
	 * 为分类法生成 URL
	 */
	private function generate_urls_for_taxonomy( $config ) {
		$taxonomy = $config['subtype'];

		// 获取有内容的分类
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 10, // 限制数量
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$urls = array();

		foreach ( $terms as $term ) {
			$url = get_term_link( $term );

			if ( ! is_wp_error( $url ) ) {
				$urls[] = array(
					'url'       => $url,
					'type'      => 'taxonomy',
					'subtype'   => $taxonomy,
					'label'     => $config['label'],
					'object_id' => $term->term_id,
					'title'     => $term->name,
				);
			}
		}

		return $urls;
	}

	/**
	 * 执行同步
	 */
	private function execute_synchronization() {
		WP_CLI::line( '执行内容同步...' );

		// TODO: 实际调用同步服务
		// $sync_service = new WPTSALL_Sync_Service();
		// $sync_service->sync_all_content( $this->source_site_id, $this->virtual_target_id );
		// $sync_service->sync_all_content( $this->source_site_id, $this->multisite_target_id );

		WP_CLI::warning( '同步功能调用（当前为模拟模式）' );
		WP_CLI::line( '' );
	}

	/**
	 * 测试 URL 比较
	 */
	private function test_url_comparisons() {
		WP_CLI::line( '开始 URL 比较测试...' );
		WP_CLI::line( '' );

		// 重新生成 URL 并测试
		foreach ( $this->templates as $template_slug => $template ) {
			// 处理文章类型
			if ( ! empty( $template['objects']['post_types'] ) ) {
				foreach ( $template['objects']['post_types'] as $post_type_config ) {
					$this->test_post_type_urls( $post_type_config );
				}
			}

			// 处理分类法
			if ( ! empty( $template['objects']['taxonomies'] ) ) {
				foreach ( $template['objects']['taxonomies'] as $taxonomy_config ) {
					$this->test_taxonomy_urls( $taxonomy_config );
				}
			}
		}

		WP_CLI::line( '' );
		WP_CLI::success( sprintf( '完成 %d 个 URL 的测试', $this->total_urls_tested ) );
	}

	/**
	 * 测试文章类型 URL
	 */
	private function test_post_type_urls( $config ) {
		switch_to_blog( $this->source_site_id );

		$urls = $this->generate_urls_for_post_type( $config );

		restore_current_blog();

		WP_CLI::line( sprintf( '测试 %s (%d 个 URL)...', $config['label'], count( $urls ) ) );

		foreach ( $urls as $url_data ) {
			$this->test_single_url( $url_data );
			$this->total_urls_tested++;
		}
	}

	/**
	 * 测试分类法 URL
	 */
	private function test_taxonomy_urls( $config ) {
		switch_to_blog( $this->source_site_id );

		$urls = $this->generate_urls_for_taxonomy( $config );

		restore_current_blog();

		WP_CLI::line( sprintf( '测试 %s (%d 个 URL)...', $config['label'], count( $urls ) ) );

		foreach ( $urls as $url_data ) {
			$this->test_single_url( $url_data );
			$this->total_urls_tested++;
		}
	}

	/**
	 * 测试单个 URL
	 */
	private function test_single_url( $url_data ) {
		$source_url = $url_data['url'];

		// 测试虚拟站点
		$this->compare_url_with_target( $url_data, 'virtual', $this->virtual_target_id );

		// 测试多站点
		$this->compare_url_with_target( $url_data, 'multisite', $this->multisite_target_id );
	}

	/**
	 * 比较 URL 与目标站点
	 */
	private function compare_url_with_target( $url_data, $target_type, $target_site_id ) {
		$source_url = $url_data['url'];

		// 获取目标 URL
		$target_url = $this->get_target_url( $source_url, $target_type, $target_site_id );

		if ( ! $target_url ) {
			$this->add_url_comparison(
				$url_data,
				$target_type,
				'error',
				'无法构建目标 URL'
			);
			return;
		}

		// 使用 curl 获取源站点内容
		$source_content = WPTSALL_URL_Comparison_Helper::fetch_url( $source_url );

		// 使用 curl 获取目标站点内容
		$target_content = WPTSALL_URL_Comparison_Helper::fetch_url( $target_url );

		// 比较内容
		$comparison_result = $this->compare_html_content( $source_content, $target_content, $url_data );

		$this->add_url_comparison(
			$url_data,
			$target_type,
			$comparison_result['status'],
			$comparison_result['message'],
			array(
				'source_url' => $source_url,
				'target_url' => $target_url,
				'details'    => $comparison_result['details'] ?? array(),
			)
		);
	}

	/**
	 * 获取目标 URL
	 */
	private function get_target_url( $source_url, $target_type, $target_site_id ) {
		if ( $target_type === 'virtual' ) {
			// 虚拟站点 URL 处理
			$parsed_url = wp_parse_url( $source_url );
			$path = $parsed_url['path'] ?? '/';

			return add_query_arg(
				array(
					'wptsall_virtual' => abs( $target_site_id ),
					'path'            => $path,
				),
				home_url( '/' )
			);
		} else {
			// 多站点 URL - 替换域名
			switch_to_blog( $target_site_id );
			$target_home = home_url( '/' );
			restore_current_blog();

			$source_home = home_url( '/' );

			// 简单替换域名部分
			return str_replace( $source_home, $target_home, $source_url );
		}
	}

	/**
	 * 比较 HTML 内容
	 */
	private function compare_html_content( $source_result, $target_result, $url_data ) {
		if ( ! $source_result['success'] ) {
			return array(
				'status'  => 'error',
				'message' => '无法获取源站点内容: ' . $source_result['error'],
				'details' => array(),
			);
		}

		if ( ! $target_result['success'] ) {
			return array(
				'status'  => 'error',
				'message' => sprintf( '无法获取目标站点内容 (HTTP %d)', $target_result['http_code'] ),
				'details' => array(),
			);
		}

		// 使用辅助类比较 HTML
		$comparison = WPTSALL_URL_Comparison_Helper::compare_html(
			$source_result['content'],
			$target_result['content'],
			array(
				'strict_match'         => false,
				'similarity_threshold' => 0.8,
			)
		);

		if ( $comparison['all_match'] ) {
			return array(
				'status'  => 'success',
				'message' => '内容匹配',
				'details' => $comparison['comparisons'],
			);
		} else {
			$warnings = array();
			foreach ( $comparison['comparisons'] as $key => $comp ) {
				if ( ! $comp['match'] ) {
					$warnings[] = $key;
				}
			}

			return array(
				'status'  => 'warning',
				'message' => implode( ', ', $warnings ) . ' 不匹配',
				'details' => $comparison['comparisons'],
			);
		}
	}

	/**
	 * 添加 URL 比较结果
	 */
	private function add_url_comparison( $url_data, $target_type, $status, $message, $extra = array() ) {
		$this->url_comparisons[] = array(
			'url_data'    => $url_data,
			'target_type' => $target_type,
			'status'      => $status,
			'message'     => $message,
			'extra'       => $extra,
			'timestamp'   => current_time( 'mysql' ),
		);
	}

	/**
	 * 显示测试结果
	 */
	private function display_results() {
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%C========== 测试结果汇总 ==========%n' ) );
		WP_CLI::line( '' );

		// 统计结果
		$total   = count( $this->url_comparisons );
		$success = 0;
		$warning = 0;
		$error   = 0;

		foreach ( $this->url_comparisons as $comparison ) {
			switch ( $comparison['status'] ) {
				case 'success':
					++$success;
					break;
				case 'warning':
					++$warning;
					break;
				case 'error':
					++$error;
					break;
			}
		}

		WP_CLI::line( sprintf( '总测试数: %d', $total ) );
		WP_CLI::line( sprintf( '成功: %d (%.1f%%)', $success, $total > 0 ? ( $success / $total * 100 ) : 0 ) );
		WP_CLI::line( sprintf( '警告: %d (%.1f%%)', $warning, $total > 0 ? ( $warning / $total * 100 ) : 0 ) );
		WP_CLI::line( sprintf( '错误: %d (%.1f%%)', $error, $total > 0 ? ( $error / $total * 100 ) : 0 ) );
		WP_CLI::line( '' );

		// 生成报告
		$this->generate_report();
	}

	/**
	 * 生成测试报告
	 */
	private function generate_report() {
		$report_content = "# 前端内容同步测试报告（基于模板）\n\n";
		$report_content .= sprintf( "测试时间: %s\n\n", current_time( 'Y-m-d H:i:s' ) );

		$report_content .= "## 测试环境\n\n";
		$report_content .= sprintf( "- 源站点 ID: %d\n", $this->source_site_id );
		$report_content .= sprintf( "- 虚拟目标站点 ID: %d\n", $this->virtual_target_id );
		$report_content .= sprintf( "- 多站点目标站点 ID: %d\n", $this->multisite_target_id );
		$report_content .= sprintf( "- 使用模板数量: %d\n", count( $this->templates ) );
		$report_content .= sprintf( "- 测试 URL 数量: %d\n\n", $this->total_urls_tested );

		$report_content .= "## 使用的模板\n\n";
		foreach ( $this->templates as $slug => $template ) {
			$report_content .= sprintf( "- **%s** (%s)\n", $template['plugin_name'] ?? $slug, $slug );
		}
		$report_content .= "\n";

		$report_content .= "## URL 比较结果\n\n";
		$report_content .= "| 类型 | 标题 | 目标类型 | 状态 | 消息 |\n";
		$report_content .= "|------|------|----------|------|------|\n";

		foreach ( $this->url_comparisons as $comparison ) {
			$url_data    = $comparison['url_data'];
			$status_icon = $comparison['status'] === 'success' ? '✅' : ( $comparison['status'] === 'warning' ? '⚠️' : '❌' );

			$report_content .= sprintf(
				"| %s | %s | %s | %s %s | %s |\n",
				$url_data['label'],
				mb_substr( $url_data['title'], 0, 30 ),
				$comparison['target_type'],
				$status_icon,
				$comparison['status'],
				$comparison['message']
			);
		}

		// 保存报告
		$report_file = '/Users/zhangxiao/wptsall-dev/04-testing/FRONTEND-SYNC-TEST-REPORT.md';
		file_put_contents( $report_file, $report_content );

		WP_CLI::success( sprintf( '测试报告已生成: %s', $report_file ) );
	}
}

// 运行测试
$tester = new WPTSALL_Frontend_Content_Sync_Tester();
$tester->run();
