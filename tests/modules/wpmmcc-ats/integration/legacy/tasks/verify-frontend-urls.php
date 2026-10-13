<?php
/**
 * 验证模型前台 URL 可访问性
 *
 * 检查当前站点上各插件模型的前台 URL 是否能正常访问。
 * 这是验证 hook 功能的前置步骤。
 *
 * 使用方法:
 *   php tests/integration/verify-frontend-urls.php
 *
 * @package WPTSALL
 * @since 0.3.1
 */

// 配置
$site_url = 'http://localhost';

// 颜色输出
function color( $text, $color ) {
	$colors = array(
		'green'  => "\033[32m",
		'red'    => "\033[31m",
		'yellow' => "\033[33m",
		'blue'   => "\033[34m",
		'reset'  => "\033[0m",
	);
	return ( $colors[ $color ] ?? '' ) . $text . $colors['reset'];
}

echo "\n";
echo color( "========================================\n", 'blue' );
echo color( "  验证模型前台 URL 可访问性\n", 'blue' );
echo color( "========================================\n", 'blue' );
echo "\n";

// 加载 WordPress
define( 'WP_USE_THEMES', false );
require_once '/usr/local/var/www/wp-load.php';

global $wpdb;

// 获取所有活跃模型的公开 URL 规则
$sql = "
SELECT
    m.id as model_id,
    m.plugin_slug,
    m.plugin_name,
    r.id as rule_id,
    r.object_type,
    r.object_subtype,
    r.url_pattern,
    r.label
FROM {$wpdb->prefix}wptsall_models m
JOIN {$wpdb->prefix}wptsall_model_url_rules r ON m.id = r.model_id
WHERE m.status = 'active'
  AND r.access_level = 'public'
  AND r.status = 'active'
ORDER BY m.plugin_slug, r.object_type
";

$rules = $wpdb->get_results( $sql );

if ( empty( $rules ) ) {
	echo color( "没有找到活跃的公开 URL 规则\n", 'yellow' );
	exit;
}

// 按插件分组
$plugins = array();
foreach ( $rules as $rule ) {
	if ( ! isset( $plugins[ $rule->plugin_slug ] ) ) {
		$plugins[ $rule->plugin_slug ] = array(
			'name'  => $rule->plugin_name,
			'rules' => array(),
		);
	}
	$plugins[ $rule->plugin_slug ]['rules'][] = $rule;
}

echo sprintf( "找到 %d 个插件，共 %d 条公开 URL 规则\n\n", count( $plugins ), count( $rules ) );

// 结果统计
$results = array(
	'tested'     => 0,
	'accessible' => 0,
	'missing'    => 0,  // 404，需要添加数据
	'error'      => 0,  // 其他错误
	'skipped'    => 0,
);

$missing_content = array(); // 需要添加数据的插件

// 测试每个插件
foreach ( $plugins as $plugin_slug => $plugin_data ) {
	echo color( sprintf( "\n[%s] %s\n", $plugin_slug, $plugin_data['name'] ), 'blue' );
	echo str_repeat( '-', 50 ) . "\n";

	$plugin_results = array(
		'accessible' => 0,
		'missing'    => 0,
		'error'      => 0,
		'skipped'    => 0,
	);

	// 每种 object_type 只测试一个 URL
	$tested_types = array();

	foreach ( $plugin_data['rules'] as $rule ) {
		$type_key = $rule->object_type . '_' . ( $rule->object_subtype ?? 'default' );

		// 跳过已测试的类型
		if ( isset( $tested_types[ $type_key ] ) ) {
			continue;
		}
		$tested_types[ $type_key ] = true;

		$results['tested']++;

		// 构建实际 URL
		$test_url = build_test_url( $rule, $site_url );

		if ( ! $test_url ) {
			echo sprintf( "  ⏭️  %s (%s) - 跳过（无法构建测试 URL）\n", $rule->label, $rule->object_type );
			$plugin_results['skipped']++;
			$results['skipped']++;
			continue;
		}

		// 测试 URL
		$http_code = test_url( $test_url );

		if ( $http_code >= 200 && $http_code < 400 ) {
			echo sprintf( "  ✓  %s - %s [%d]\n", $rule->label, $test_url, $http_code );
			$plugin_results['accessible']++;
			$results['accessible']++;
		} elseif ( $http_code == 404 ) {
			echo color( sprintf( "  ✗  %s - %s [404 需要数据]\n", $rule->label, $test_url ), 'yellow' );
			$plugin_results['missing']++;
			$results['missing']++;

			// 记录需要添加数据的内容
			if ( ! isset( $missing_content[ $plugin_slug ] ) ) {
				$missing_content[ $plugin_slug ] = array(
					'name'  => $plugin_data['name'],
					'items' => array(),
				);
			}
			$missing_content[ $plugin_slug ]['items'][] = array(
				'type'    => $rule->object_type,
				'subtype' => $rule->object_subtype,
				'label'   => $rule->label,
				'pattern' => $rule->url_pattern,
			);
		} else {
			echo color( sprintf( "  ✗  %s - %s [HTTP %d]\n", $rule->label, $test_url, $http_code ), 'red' );
			$plugin_results['error']++;
			$results['error']++;
		}
	}

	// 插件小结
	$total = $plugin_results['accessible'] + $plugin_results['missing'] + $plugin_results['error'];
	if ( $total > 0 ) {
		$rate = round( $plugin_results['accessible'] / $total * 100 );
		echo sprintf(
			"  小结: %d/%d 可访问 (%d%%), 缺数据: %d, 错误: %d\n",
			$plugin_results['accessible'],
			$total,
			$rate,
			$plugin_results['missing'],
			$plugin_results['error']
		);
	}
}

// 总结
echo "\n";
echo color( "========================================\n", 'blue' );
echo color( "  测试结果汇总\n", 'blue' );
echo color( "========================================\n", 'blue' );
echo "\n";

$total_tested = $results['accessible'] + $results['missing'] + $results['error'];
echo sprintf( "总测试数: %d\n", $total_tested );
echo color( sprintf( "✓ 可访问: %d\n", $results['accessible'] ), 'green' );
echo color( sprintf( "⚠ 缺数据 (404): %d\n", $results['missing'] ), 'yellow' );
echo color( sprintf( "✗ 错误: %d\n", $results['error'] ), 'red' );
echo sprintf( "⏭ 跳过: %d\n", $results['skipped'] );

if ( $total_tested > 0 ) {
	$rate = round( $results['accessible'] / $total_tested * 100 );
	echo sprintf( "\n可访问率: %d%%\n", $rate );
}

// 如果有缺失数据，显示需要添加的内容
if ( ! empty( $missing_content ) ) {
	echo "\n";
	echo color( "========================================\n", 'yellow' );
	echo color( "  需要添加测试数据的插件\n", 'yellow' );
	echo color( "========================================\n", 'yellow' );
	echo "\n";

	foreach ( $missing_content as $slug => $data ) {
		echo sprintf( "[%s] %s\n", $slug, $data['name'] );
		foreach ( $data['items'] as $item ) {
			echo sprintf( "  - %s (%s): %s\n", $item['label'], $item['type'], $item['pattern'] );
		}
		echo "\n";
	}
}

echo "\n";

/**
 * 构建测试 URL
 */
function build_test_url( $rule, $site_url ) {
	$pattern = $rule->url_pattern;
	$object_type = $rule->object_type;
	$subtype = $rule->object_subtype;

	// 归档页直接测试
	if ( $object_type === 'archive' ) {
		return $site_url . $pattern;
	}

	// 单页和分类页需要找到实际内容
	if ( $object_type === 'post_type' && $subtype ) {
		// 查找该 post type 的第一篇发布的内容
		$post = get_posts( array(
			'post_type'      => $subtype,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		) );

		if ( ! empty( $post ) ) {
			$permalink = get_permalink( $post[0]->ID );
			if ( $permalink ) {
				return $permalink;
			}
		}

		// 没有内容，构建一个假 URL 用于 404 测试
		$test_pattern = str_replace( '{slug}', 'test-item-12345', $pattern );
		return $site_url . $test_pattern;
	}

	if ( $object_type === 'taxonomy' && $subtype ) {
		// 查找该 taxonomy 的第一个有内容的 term
		$terms = get_terms( array(
			'taxonomy'   => $subtype,
			'hide_empty' => false,
			'number'     => 1,
		) );

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$term_link = get_term_link( $terms[0] );
			if ( ! is_wp_error( $term_link ) ) {
				return $term_link;
			}
		}

		// 没有 term，构建一个假 URL
		$test_pattern = str_replace( '{term}', 'test-term-12345', $pattern );
		return $site_url . $test_pattern;
	}

	// 其他情况
	if ( strpos( $pattern, '{' ) !== false ) {
		// 替换占位符
		$test_pattern = preg_replace( '/\{[^}]+\}/', 'test-item', $pattern );
		return $site_url . $test_pattern;
	}

	return $site_url . $pattern;
}

/**
 * 测试 URL 并返回 HTTP 状态码
 */
function test_url( $url ) {
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_NOBODY, true );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
	// 必须在设置 URL 后执行
	curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	return $http_code;
}
