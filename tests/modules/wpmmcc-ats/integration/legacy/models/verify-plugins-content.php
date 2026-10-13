<?php
/**
 * 验证插件和内容正常显示
 * 生成验证报告
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( dirname( __DIR__ ) ) . '/wp-load.php';
}

echo "========================================\n";
echo "插件和内容验证报告\n";
echo "========================================\n";
echo "时间: " . current_time( 'Y-m-d H:i:s' ) . "\n\n";

// ===== 验证已安装的插件 =====
echo "--- 已安装并激活的插件 ---\n";
$active_plugins = array(
	'wordpress-seo/wp-seo.php'            => 'Yoast SEO',
	'seo-by-rank-math/rank-math.php'      => 'Rank Math SEO',
	'advanced-custom-fields/acf.php'      => 'Advanced Custom Fields',
	'custom-post-type-ui/custom-post-type-ui.php' => 'Custom Post Type UI',
	'classic-editor/classic-editor.php'   => 'Classic Editor',
	'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
	'envira-gallery-lite/envira-gallery-lite.php' => 'Envira Gallery Lite',
);

foreach ( $active_plugins as $plugin => $name ) {
	if ( is_plugin_active( $plugin ) || is_plugin_active_for_network( $plugin ) ) {
		echo "✓ $name\n";
	} else {
		echo "✗ $name (未激活)\n";
	}
}
echo "\n";

// ===== 验证自定义文章类型 =====
echo "--- 自定义文章类型 ---\n";
$custom_types = array( 'product', 'portfolio' );
foreach ( $custom_types as $type ) {
	if ( post_type_exists( $type ) ) {
		$type_obj = get_post_type_object( $type );
		$count = wp_count_posts( $type )->publish;
		echo "✓ {$type_obj->labels->name} ({$type})\n";
		echo "  - 已发布: $count 个\n";
		echo "  - 支持: " . implode( ', ', get_all_post_type_supports( $type ) ) . "\n";
	} else {
		echo "✗ $type (未注册)\n";
	}
}
echo "\n";

// ===== 验证 ACF 字段组 =====
echo "--- ACF 字段组 ---\n";
if ( function_exists( 'acf_get_field_groups' ) ) {
	$field_groups = acf_get_field_groups();
	if ( ! empty( $field_groups ) ) {
		foreach ( $field_groups as $group ) {
			echo "✓ {$group['title']}\n";
			$fields = acf_get_fields( $group['key'] );
			if ( $fields ) {
				echo "  字段数: " . count( $fields ) . "\n";
				foreach ( $fields as $field ) {
					echo "    - {$field['label']} ({$field['name']}) - 类型: {$field['type']}\n";
				}
			}
		}
	} else {
		echo "注意: 未找到已保存的字段组\n";
	}
} else {
	echo "✗ ACF 函数不可用\n";
}
echo "\n";

// ===== 验证测试内容 =====
echo "--- 测试内容验证 ---\n";

// 文章
$posts = get_posts( array(
	'post_type'   => 'post',
	'post_status' => 'publish',
	'numberposts' => 5,
	'orderby'     => 'ID',
	'order'       => 'DESC',
) );

echo "标准文章 (最近 5 篇):\n";
foreach ( $posts as $post ) {
	echo "  - [{$post->ID}] {$post->post_title}\n";

	// 检查 SEO 元数据
	$yoast_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
	$rank_math = get_post_meta( $post->ID, 'rank_math_seo_score', true );

	if ( $yoast_title ) {
		echo "    ✓ Yoast SEO: {$yoast_title}\n";
	}
	if ( $rank_math ) {
		echo "    ✓ Rank Math: 分数 {$rank_math}\n";
	}

	// 检查 ACF 字段
	$subtitle = get_post_meta( $post->ID, 'subtitle', true );
	$reading_time = get_post_meta( $post->ID, 'reading_time', true );

	if ( $subtitle ) {
		echo "    ✓ ACF 副标题: {$subtitle}\n";
	}
	if ( $reading_time ) {
		echo "    ✓ ACF 阅读时长: {$reading_time} 分钟\n";
	}
}
echo "\n";

// 产品
$products = get_posts( array(
	'post_type'   => 'product',
	'post_status' => 'publish',
	'numberposts' => 3,
	'orderby'     => 'ID',
	'order'       => 'DESC',
) );

echo "产品 (最近 3 个):\n";
foreach ( $products as $product ) {
	echo "  - [{$product->ID}] {$product->post_title}\n";

	$price = get_post_meta( $product->ID, 'product_price', true );
	$sku = get_post_meta( $product->ID, 'product_sku', true );
	$stock = get_post_meta( $product->ID, 'product_stock', true );

	if ( $price ) {
		echo "    ✓ 价格: ¥{$price}\n";
	}
	if ( $sku ) {
		echo "    ✓ SKU: {$sku}\n";
	}
	if ( $stock ) {
		echo "    ✓ 库存: {$stock}\n";
	}

	$yoast_title = get_post_meta( $product->ID, '_yoast_wpseo_title', true );
	if ( $yoast_title ) {
		echo "    ✓ SEO: {$yoast_title}\n";
	}
}
echo "\n";

// 作品集
$portfolios = get_posts( array(
	'post_type'   => 'portfolio',
	'post_status' => 'publish',
	'numberposts' => 3,
) );

echo "作品集 (共 " . count( $portfolios ) . " 个):\n";
foreach ( $portfolios as $portfolio ) {
	echo "  - [{$portfolio->ID}] {$portfolio->post_title}\n";
}
echo "\n";

// ===== 验证分类和标签 =====
echo "--- 分类和标签 ---\n";
$categories = get_terms( array(
	'taxonomy'   => 'category',
	'hide_empty' => false,
	'number'     => 5,
	'orderby'    => 'term_id',
	'order'      => 'DESC',
) );

echo "最近创建的分类:\n";
foreach ( $categories as $cat ) {
	echo "  - {$cat->name} (ID: {$cat->term_id}, 文章数: {$cat->count})\n";
}

$tags = get_terms( array(
	'taxonomy'   => 'post_tag',
	'hide_empty' => false,
	'number'     => 5,
	'orderby'    => 'term_id',
	'order'      => 'DESC',
) );

echo "\n最近创建的标签:\n";
foreach ( $tags as $tag ) {
	echo "  - {$tag->name} (ID: {$tag->term_id}, 文章数: {$tag->count})\n";
}
echo "\n";

// ===== 统计摘要 =====
echo "========================================\n";
echo "统计摘要\n";
echo "========================================\n";

$stats = array(
	'posts'     => wp_count_posts( 'post' )->publish,
	'pages'     => wp_count_posts( 'page' )->publish,
	'products'  => wp_count_posts( 'product' )->publish,
	'portfolio' => wp_count_posts( 'portfolio' )->publish,
	'categories' => wp_count_terms( 'category' ),
	'tags'      => wp_count_terms( 'post_tag' ),
);

echo "已发布内容:\n";
echo "  - 文章: {$stats['posts']}\n";
echo "  - 页面: {$stats['pages']}\n";
echo "  - 产品: {$stats['products']}\n";
echo "  - 作品集: {$stats['portfolio']}\n";
echo "  - 分类: {$stats['categories']}\n";
echo "  - 标签: {$stats['tags']}\n";
echo "\n";

echo "✓ 所有插件和内容验证完成\n";
echo "✓ 原站点已准备好进行同步测试\n";
