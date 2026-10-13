<?php
/**
 * E2E v2 插件前台初始化页与基础配置
 *
 * 目标：
 * - 确保核心前台入口可访问（Woo / bbPress / Job Manager / WPRM / Envira / Site Reviews）
 * - 配置必要 option，避免 404 或空白页
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/init-plugin-front-pages.php
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

echo "=== E2E v2: Init Plugin Front Pages ===\n\n";

/**
 * Ensure page exists and published.
 *
 * @return int page ID
 */
function e2e_init_ensure_page( string $slug, string $title, string $content = '' ): int {
	global $wpdb;

	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return 0;
	}

	$page = get_page_by_path( $slug, OBJECT, 'page' );

	if ( $page ) {
		$page_id = (int) $page->ID;
		$update  = array(
			'post_title'        => $title,
			'post_status'       => 'publish',
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
		);
		if ( '' !== $content ) {
			$update['post_content'] = $content;
		}
		$wpdb->update(
			$wpdb->posts,
			$update,
			array( 'ID' => $page_id ),
			null,
			array( '%d' )
		);
		clean_post_cache( $page_id );
		return $page_id;
	}

	$now_local = current_time( 'mysql' );
	$now_gmt   = current_time( 'mysql', true );
	$inserted  = $wpdb->insert(
		$wpdb->posts,
		array(
			'post_author'           => 1,
			'post_date'             => $now_local,
			'post_date_gmt'         => $now_gmt,
			'post_content'          => $content,
			'post_title'            => $title,
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $slug,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $now_local,
			'post_modified_gmt'     => $now_gmt,
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => '',
			'menu_order'            => 0,
			'post_type'             => 'page',
			'post_mime_type'        => '',
			'comment_count'         => 0,
		),
		array(
			'%d', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%d', '%s', '%d',
			'%s', '%s', '%d',
		)
	);
	if ( false === $inserted ) {
		return 0;
	}
	$page_id = (int) $wpdb->insert_id;
	if ( $page_id <= 0 ) {
		return 0;
	}
	$wpdb->update(
		$wpdb->posts,
		array(
			'guid' => home_url( '/?page_id=' . $page_id ),
		),
		array( 'ID' => $page_id ),
		array( '%s' ),
		array( '%d' )
	);
	clean_post_cache( $page_id );
	return $page_id;
}

/**
 * Ensure Woo pages + options.
 */
function e2e_init_woocommerce(): array {
	$res = array(
		'enabled' => false,
		'pages'   => array(),
	);

	if ( ! class_exists( 'WooCommerce' ) ) {
		return $res;
	}

	$res['enabled'] = true;

	$shop_id = e2e_init_ensure_page( 'shop', 'Shop', '' );
	$cart_id = e2e_init_ensure_page( 'cart', 'Cart', '[woocommerce_cart]' );
	$checkout_id = e2e_init_ensure_page( 'checkout', 'Checkout', '[woocommerce_checkout]' );
	$account_id = e2e_init_ensure_page( 'my-account', 'My Account', '[woocommerce_my_account]' );

	if ( $shop_id > 0 ) {
		update_option( 'woocommerce_shop_page_id', $shop_id );
	}
	if ( $cart_id > 0 ) {
		update_option( 'woocommerce_cart_page_id', $cart_id );
	}
	if ( $checkout_id > 0 ) {
		update_option( 'woocommerce_checkout_page_id', $checkout_id );
	}
	if ( $account_id > 0 ) {
		update_option( 'woocommerce_myaccount_page_id', $account_id );
	}

	$res['pages'] = array(
		'shop'      => $shop_id,
		'cart'      => $cart_id,
		'checkout'  => $checkout_id,
		'myaccount' => $account_id,
	);

	return $res;
}

/**
 * Ensure bbPress forum index page.
 */
function e2e_init_bbpress(): array {
	$res = array(
		'enabled' => false,
		'page'    => 0,
		'topic_page' => 0,
	);

	if ( ! function_exists( 'bbp_insert_forum' ) ) {
		return $res;
	}

	$res['enabled'] = true;

	$page_id = e2e_init_ensure_page( 'forums', 'Forums', '[bbp-forum-index]' );
	$hub_page_id = e2e_init_ensure_page( 'discussion-hub', 'Discussion Hub', '[bbp-forum-index]' );
	$topic_ids = get_posts(
		array(
			'post_type'      => 'topic',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);
	$topic_id = ! empty( $topic_ids ) ? (int) $topic_ids[0] : 0;
	$topic_page_id = 0;
	if ( $topic_id > 0 ) {
		$topic_page_id = e2e_init_ensure_page(
			'discussion-view',
			'Discussion View',
			'[bbp-single-topic id="' . $topic_id . '"]'
		);
	}

	$res['page'] = $hub_page_id > 0 ? $hub_page_id : $page_id;
	$res['topic_page'] = $topic_page_id;
	update_option( '_bbp_root_slug', 'forums' );

	return $res;
}

/**
 * Ensure WP Job Manager listing page.
 */
function e2e_init_job_manager(): array {
	$res = array(
		'enabled' => false,
		'page'    => 0,
	);

	if ( ! post_type_exists( 'job_listing' ) ) {
		return $res;
	}

	$res['enabled'] = true;
	$page_id = e2e_init_ensure_page( 'jobs', 'Jobs', '[jobs]' );
	if ( $page_id > 0 ) {
		update_option( 'job_manager_jobs_page_id', $page_id );
	}
	$res['page'] = $page_id;

	return $res;
}

/**
 * Ensure WPRM and Envira showcase pages.
 */
function e2e_init_showcase_pages(): array {
	$res = array(
		'recipe_page'  => 0,
		'gallery_page' => 0,
		'review_page'  => 0,
	);

	$recipe_ids = get_posts(
		array(
			'post_type'      => 'wprm_recipe',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);
	$recipe_id = ! empty( $recipe_ids ) ? (int) $recipe_ids[0] : 0;

	if ( $recipe_id > 0 ) {
		$res['recipe_page'] = e2e_init_ensure_page(
			'recipes',
			'Recipes',
			'[wprm-recipe id="' . $recipe_id . '"]'
		);
	}

	$gallery_ids = get_posts(
		array(
			'post_type'      => 'envira',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);
	$gallery_id = ! empty( $gallery_ids ) ? (int) $gallery_ids[0] : 0;

	if ( $gallery_id > 0 ) {
		$res['gallery_page'] = e2e_init_ensure_page(
			'galleries',
			'Galleries',
			'[envira-gallery id="' . $gallery_id . '"]'
		);
	}

	if ( shortcode_exists( 'site_reviews' ) ) {
		$res['review_page'] = e2e_init_ensure_page( 'reviews', 'Reviews', '[site_reviews]' );
	}

	return $res;
}

$result = array(
	'woocommerce' => e2e_init_woocommerce(),
	'bbpress'     => e2e_init_bbpress(),
	'job_manager' => e2e_init_job_manager(),
	'showcase'    => e2e_init_showcase_pages(),
);

flush_rewrite_rules( false );

$runtime_dir = e2e_runtime_dir();
if ( ! is_dir( $runtime_dir ) ) {
	wp_mkdir_p( $runtime_dir );
}
$runtime_file = $runtime_dir . '/plugin-front-init.json';
file_put_contents( $runtime_file, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n\n";
echo "rewrite rules flushed\n";
echo "runtime file: {$runtime_file}\n";
