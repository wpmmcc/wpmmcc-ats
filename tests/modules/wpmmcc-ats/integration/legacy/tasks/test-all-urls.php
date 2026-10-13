<?php
/**
 * Complete URL Testing - All Plugins and All URL Types
 *
 * 自动检测所有插件并测试所有类型的 URL
 * - 自动发现所有 post types 和 taxonomies
 * - 每种类型只测试 1 条 URL
 * - 测试单页、归档、搜索等所有 URL 类型
 *
 * 使用方法:
 * wp eval-file tests/integration/tasks/test-all-urls.php
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

class WPTSALL_Complete_URL_Tester {

	private $source_site_id;
	private $virtual_target_id;
	private $multisite_target_id;
	private $url_comparisons = array();
	private $discovered_post_types = array();
	private $discovered_taxonomies = array();
	private $test_urls = array();

	public function run() {
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%G========== 完整 URL 测试（所有插件 + 所有 URL 类型）==========%n' ) );
		WP_CLI::line( '' );

		// 环境检查
		if ( ! $this->check_environment() ) {
			WP_CLI::error( '环境检查失败，无法继续测试' );
			return;
		}

		// 准备测试环境
		$this->prepare_test_environment();

		// 自动发现所有内容类型
		$this->discover_all_content_types();

		// 生成测试 URL
		$this->generate_test_urls();

		// 执行同步（如果需要）
		$this->execute_synchronization();

		// URL 比较测试
		$this->test_all_urls();

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
		$this->virtual_target_id = $this->get_virtual_target_site();
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

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
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

		$sites = get_sites(
			array(
				'number'       => 1,
				'site__not_in' => array( $this->source_site_id ),
			)
		);

		return ! empty( $sites ) ? $sites[0]->blog_id : $this->source_site_id;
	}

	/**
	 * 自动发现所有内容类型
	 */
	private function discover_all_content_types() {
		WP_CLI::line( '自动发现所有内容类型...' );

		switch_to_blog( $this->source_site_id );

		// 发现所有公开的 post types
		$this->discover_post_types();

		// 发现所有公开的 taxonomies
		$this->discover_taxonomies();

		restore_current_blog();

		WP_CLI::success(
			sprintf(
				'发现 %d 个文章类型，%d 个分类法',
				count( $this->discovered_post_types ),
				count( $this->discovered_taxonomies )
			)
		);
		WP_CLI::line( '' );
	}

	/**
	 * 发现所有 post types
	 */
	private function discover_post_types() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		foreach ( $post_types as $post_type => $post_type_obj ) {
			// 跳过附件
			if ( $post_type === 'attachment' ) {
				continue;
			}

			// 检测来源插件
			$plugin = $this->detect_plugin_for_post_type( $post_type );

			$this->discovered_post_types[ $post_type ] = array(
				'name'   => $post_type,
				'label'  => $post_type_obj->labels->singular_name,
				'labels' => $post_type_obj->labels,
				'plugin' => $plugin,
				'public' => $post_type_obj->public,
				'has_archive' => $post_type_obj->has_archive,
			);

			WP_CLI::line( sprintf( '  发现文章类型: %s (%s) - 来自: %s', $post_type_obj->labels->singular_name, $post_type, $plugin ) );
		}
	}

	/**
	 * 发现所有 taxonomies
	 */
	private function discover_taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public' => true,
			),
			'objects'
		);

		foreach ( $taxonomies as $taxonomy => $taxonomy_obj ) {
			// 检测来源插件
			$plugin = $this->detect_plugin_for_taxonomy( $taxonomy );

			$this->discovered_taxonomies[ $taxonomy ] = array(
				'name'   => $taxonomy,
				'label'  => $taxonomy_obj->labels->singular_name,
				'labels' => $taxonomy_obj->labels,
				'plugin' => $plugin,
				'public' => $taxonomy_obj->public,
				'hierarchical' => $taxonomy_obj->hierarchical,
			);

			WP_CLI::line( sprintf( '  发现分类法: %s (%s) - 来自: %s', $taxonomy_obj->labels->singular_name, $taxonomy, $plugin ) );
		}
	}

	/**
	 * 检测 post type 来自哪个插件
	 */
	private function detect_plugin_for_post_type( $post_type ) {
		// WordPress 核心类型
		$core_types = array( 'post', 'page' );
		if ( in_array( $post_type, $core_types, true ) ) {
			return 'WordPress Core';
		}

		// WooCommerce
		$woocommerce_types = array( 'product', 'product_variation', 'shop_order', 'shop_coupon' );
		if ( in_array( $post_type, $woocommerce_types, true ) ) {
			return 'WooCommerce';
		}

		// bbPress
		$bbpress_types = array( 'forum', 'topic', 'reply' );
		if ( in_array( $post_type, $bbpress_types, true ) ) {
			return 'bbPress';
		}

		// BuddyPress (通常没有 post type，但某些扩展可能有)
		if ( strpos( $post_type, 'bp_' ) === 0 ) {
			return 'BuddyPress';
		}

		// Easy Digital Downloads
		$edd_types = array( 'download', 'edd_payment', 'edd_discount' );
		if ( in_array( $post_type, $edd_types, true ) ) {
			return 'Easy Digital Downloads';
		}

		// Events Calendar
		$events_types = array( 'tribe_events', 'tribe_venue', 'tribe_organizer' );
		if ( in_array( $post_type, $events_types, true ) ) {
			return 'The Events Calendar';
		}

		// 通用检测：通过前缀
		$prefix_map = array(
			'acf_'    => 'Advanced Custom Fields',
			'wpcf7_'  => 'Contact Form 7',
			'elementor_' => 'Elementor',
			'job_'    => 'WP Job Manager',
			'course_' => 'LearnDash',
		);

		foreach ( $prefix_map as $prefix => $plugin ) {
			if ( strpos( $post_type, $prefix ) === 0 ) {
				return $plugin;
			}
		}

		return 'Unknown Plugin';
	}

	/**
	 * 检测 taxonomy 来自哪个插件
	 */
	private function detect_plugin_for_taxonomy( $taxonomy ) {
		// WordPress 核心
		$core_taxonomies = array( 'category', 'post_tag' );
		if ( in_array( $taxonomy, $core_taxonomies, true ) ) {
			return 'WordPress Core';
		}

		// WooCommerce
		$woocommerce_taxonomies = array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' );
		if ( in_array( $taxonomy, $woocommerce_taxonomies, true ) ) {
			return 'WooCommerce';
		}

		// bbPress
		$bbpress_taxonomies = array( 'topic-tag' );
		if ( in_array( $taxonomy, $bbpress_taxonomies, true ) ) {
			return 'bbPress';
		}

		// Events Calendar
		$events_taxonomies = array( 'tribe_events_cat' );
		if ( in_array( $taxonomy, $events_taxonomies, true ) ) {
			return 'The Events Calendar';
		}

		return 'Unknown Plugin';
	}

	/**
	 * 生成测试 URL
	 */
	private function generate_test_urls() {
		WP_CLI::line( '生成测试 URL（每种类型 1 条）...' );

		switch_to_blog( $this->source_site_id );

		// 1. 首页
		$this->add_test_url(
			home_url( '/' ),
			'homepage',
			'site',
			'首页',
			'WordPress Core'
		);

		// 2. 文章类型单页 URL
		foreach ( $this->discovered_post_types as $post_type => $config ) {
			$this->generate_single_url_for_post_type( $post_type, $config );

			// 如果有归档页，生成归档 URL
			if ( $config['has_archive'] ) {
				$this->generate_archive_url_for_post_type( $post_type, $config );
			}
		}

		// 3. 分类法归档 URL
		foreach ( $this->discovered_taxonomies as $taxonomy => $config ) {
			$this->generate_taxonomy_archive_url( $taxonomy, $config );
		}

		// 4. 搜索页 URL
		$this->add_test_url(
			home_url( '/?s=test' ),
			'search',
			'site',
			'搜索页',
			'WordPress Core'
		);

		// 5. 作者归档页 URL
		$this->generate_author_archive_url();

		// 6. 日期归档页 URL
		$this->generate_date_archive_url();

		restore_current_blog();

		WP_CLI::success( sprintf( '生成了 %d 个测试 URL', count( $this->test_urls ) ) );
		WP_CLI::line( '' );
	}

	/**
	 * 为 post type 生成单页 URL
	 */
	private function generate_single_url_for_post_type( $post_type, $config ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! empty( $posts ) ) {
			$post = $posts[0];
			$url = get_permalink( $post->ID );

			if ( $url ) {
				$this->add_test_url(
					$url,
					'single_' . $post_type,
					$post_type,
					$config['label'] . ' 单页: ' . get_the_title( $post->ID ),
					$config['plugin']
				);
			}
		}
	}

	/**
	 * 为 post type 生成归档 URL
	 */
	private function generate_archive_url_for_post_type( $post_type, $config ) {
		$archive_url = get_post_type_archive_link( $post_type );

		if ( $archive_url ) {
			$this->add_test_url(
				$archive_url,
				'archive_' . $post_type,
				$post_type,
				$config['label'] . ' 归档页',
				$config['plugin']
			);
		}
	}

	/**
	 * 为 taxonomy 生成归档 URL
	 */
	private function generate_taxonomy_archive_url( $taxonomy, $config ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 1,
			)
		);

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$term = $terms[0];
			$url = get_term_link( $term );

			if ( ! is_wp_error( $url ) ) {
				$this->add_test_url(
					$url,
					'taxonomy_' . $taxonomy,
					$taxonomy,
					$config['label'] . ' 归档: ' . $term->name,
					$config['plugin']
				);
			}
		}
	}

	/**
	 * 生成作者归档 URL
	 */
	private function generate_author_archive_url() {
		$users = get_users(
			array(
				'number'  => 1,
				'orderby' => 'post_count',
				'order'   => 'DESC',
			)
		);

		if ( ! empty( $users ) ) {
			$user = $users[0];
			$url = get_author_posts_url( $user->ID );

			if ( $url ) {
				$this->add_test_url(
					$url,
					'author_archive',
					'author',
					'作者归档: ' . $user->display_name,
					'WordPress Core'
				);
			}
		}
	}

	/**
	 * 生成日期归档 URL
	 */
	private function generate_date_archive_url() {
		// 获取最近一篇文章的日期
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! empty( $posts ) ) {
			$post = $posts[0];
			$year = get_the_date( 'Y', $post );
			$month = get_the_date( 'm', $post );

			$url = get_month_link( $year, $month );

			if ( $url ) {
				$this->add_test_url(
					$url,
					'date_archive',
					'date',
					sprintf( '日期归档: %s年%s月', $year, $month ),
					'WordPress Core'
				);
			}
		}
	}

	/**
	 * 添加测试 URL
	 */
	private function add_test_url( $url, $type, $subtype, $label, $plugin ) {
		$this->test_urls[] = array(
			'url'     => $url,
			'type'    => $type,
			'subtype' => $subtype,
			'label'   => $label,
			'plugin'  => $plugin,
		);

		WP_CLI::line( sprintf( '  + %s', $label ) );
	}

	/**
	 * 执行同步
	 */
	private function execute_synchronization() {
		WP_CLI::line( '执行内容同步...' );
		WP_CLI::warning( '同步功能调用（当前为模拟模式）' );
		WP_CLI::line( '' );
	}

	/**
	 * 测试所有 URL
	 */
	private function test_all_urls() {
		WP_CLI::line( '开始测试所有 URL...' );
		WP_CLI::line( '' );

		$progress = \WP_CLI\Utils\make_progress_bar( '测试进度', count( $this->test_urls ) * 2 );

		foreach ( $this->test_urls as $url_data ) {
			// 测试虚拟站点
			$this->compare_url_with_target( $url_data, 'virtual', $this->virtual_target_id );
			$progress->tick();

			// 测试多站点
			$this->compare_url_with_target( $url_data, 'multisite', $this->multisite_target_id );
			$progress->tick();
		}

		$progress->finish();

		WP_CLI::line( '' );
		WP_CLI::success( sprintf( '完成 %d 个 URL 的测试（共 %d 次测试）', count( $this->test_urls ), count( $this->url_comparisons ) ) );
	}

	/**
	 * 比较 URL 与目标站点
	 */
	private function compare_url_with_target( $url_data, $target_type, $target_site_id ) {
		$source_url = $url_data['url'];
		$target_url = $this->get_target_url( $source_url, $target_type, $target_site_id );

		if ( ! $target_url ) {
			$this->add_url_comparison( $url_data, $target_type, 'error', '无法构建目标 URL' );
			return;
		}

		$source_content = WPTSALL_URL_Comparison_Helper::fetch_url( $source_url );
		$target_content = WPTSALL_URL_Comparison_Helper::fetch_url( $target_url );

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
			$parsed_url = wp_parse_url( $source_url );
			$path       = $parsed_url['path'] ?? '/';
			$query      = $parsed_url['query'] ?? '';

			// Trim leading/trailing slashes from path
			$path = trim( $path, '/' );

			// Build virtual URL in the correct format: /virtual/{site_id}/{path}
			$virtual_url = home_url( sprintf( '/virtual/%s/%s', abs( $target_site_id ), $path ) );

			// Append query string if present
			if ( $query ) {
				$virtual_url .= '?' . $query;
			}

			return $virtual_url;
		} else {
			switch_to_blog( $target_site_id );
			$target_home = home_url( '/' );
			restore_current_blog();

			$source_home = home_url( '/' );
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

		// 按插件分组统计
		$stats_by_plugin = array();
		foreach ( $this->url_comparisons as $comparison ) {
			$plugin = $comparison['url_data']['plugin'];

			if ( ! isset( $stats_by_plugin[ $plugin ] ) ) {
				$stats_by_plugin[ $plugin ] = array(
					'total'   => 0,
					'success' => 0,
					'warning' => 0,
					'error'   => 0,
				);
			}

			$stats_by_plugin[ $plugin ]['total']++;
			$stats_by_plugin[ $plugin ][ $comparison['status'] ]++;
		}

		// 显示各插件统计
		WP_CLI::line( '按插件分组统计:' );
		WP_CLI::line( '' );

		foreach ( $stats_by_plugin as $plugin => $stats ) {
			WP_CLI::line( sprintf( '%s:', $plugin ) );
			WP_CLI::line(
				sprintf(
					'  总计: %d, 成功: %d (%.1f%%), 警告: %d (%.1f%%), 错误: %d (%.1f%%)',
					$stats['total'],
					$stats['success'],
					$stats['total'] > 0 ? ( $stats['success'] / $stats['total'] * 100 ) : 0,
					$stats['warning'],
					$stats['total'] > 0 ? ( $stats['warning'] / $stats['total'] * 100 ) : 0,
					$stats['error'],
					$stats['total'] > 0 ? ( $stats['error'] / $stats['total'] * 100 ) : 0
				)
			);
		}

		WP_CLI::line( '' );

		// 总体统计
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

		WP_CLI::line( '总体统计:' );
		WP_CLI::line( sprintf( '  总测试数: %d', $total ) );
		WP_CLI::line( sprintf( '  成功: %d (%.1f%%)', $success, $total > 0 ? ( $success / $total * 100 ) : 0 ) );
		WP_CLI::line( sprintf( '  警告: %d (%.1f%%)', $warning, $total > 0 ? ( $warning / $total * 100 ) : 0 ) );
		WP_CLI::line( sprintf( '  错误: %d (%.1f%%)', $error, $total > 0 ? ( $error / $total * 100 ) : 0 ) );
		WP_CLI::line( '' );

		// 生成报告
		$this->generate_report();
	}

	/**
	 * 生成测试报告
	 */
	private function generate_report() {
		$report_content = "# 完整 URL 测试报告\n\n";
		$report_content .= sprintf( "测试时间: %s\n\n", current_time( 'Y-m-d H:i:s' ) );

		$report_content .= "## 测试环境\n\n";
		$report_content .= sprintf( "- 源站点 ID: %d\n", $this->source_site_id );
		$report_content .= sprintf( "- 虚拟目标站点 ID: %d\n", $this->virtual_target_id );
		$report_content .= sprintf( "- 多站点目标站点 ID: %d\n", $this->multisite_target_id );
		$report_content .= sprintf( "- 发现的文章类型: %d 个\n", count( $this->discovered_post_types ) );
		$report_content .= sprintf( "- 发现的分类法: %d 个\n", count( $this->discovered_taxonomies ) );
		$report_content .= sprintf( "- 测试 URL 数量: %d 个\n\n", count( $this->test_urls ) );

		$report_content .= "## 发现的文章类型\n\n";
		foreach ( $this->discovered_post_types as $post_type => $config ) {
			$report_content .= sprintf( "- **%s** (%s) - 来自: %s\n", $config['label'], $post_type, $config['plugin'] );
		}
		$report_content .= "\n";

		$report_content .= "## 发现的分类法\n\n";
		foreach ( $this->discovered_taxonomies as $taxonomy => $config ) {
			$report_content .= sprintf( "- **%s** (%s) - 来自: %s\n", $config['label'], $taxonomy, $config['plugin'] );
		}
		$report_content .= "\n";

		$report_content .= "## 测试的 URL 列表\n\n";
		foreach ( $this->test_urls as $index => $url_data ) {
			$report_content .= sprintf( "%d. **%s** (%s)\n", $index + 1, $url_data['label'], $url_data['plugin'] );
			$report_content .= sprintf( "   - URL: %s\n", $url_data['url'] );
		}
		$report_content .= "\n";

		$report_content .= "## URL 测试结果\n\n";
		$report_content .= "| URL | 插件 | 目标类型 | 状态 | 消息 |\n";
		$report_content .= "|-----|------|----------|------|------|\n";

		foreach ( $this->url_comparisons as $comparison ) {
			$url_data    = $comparison['url_data'];
			$status_icon = $comparison['status'] === 'success' ? '✅' : ( $comparison['status'] === 'warning' ? '⚠️' : '❌' );

			$report_content .= sprintf(
				"| %s | %s | %s | %s %s | %s |\n",
				mb_substr( $url_data['label'], 0, 40 ),
				$url_data['plugin'],
				$comparison['target_type'],
				$status_icon,
				$comparison['status'],
				$comparison['message']
			);
		}

		// 保存报告
		$report_file = '/Users/zhangxiao/wptsall-dev/04-testing/COMPLETE-URL-TEST-REPORT.md';
		file_put_contents( $report_file, $report_content );

		WP_CLI::success( sprintf( '测试报告已生成: %s', $report_file ) );
	}
}

// 运行测试
$tester = new WPTSALL_Complete_URL_Tester();
$tester->run();
