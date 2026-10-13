<?php
/**
 * E2E v2 用户与评论真实行为夹具
 *
 * 目标：
 * - 注册固定测试账号（多角色）
 * - 模拟登录后提交评论（核心文章、Woo 评论、bbPress 回复）
 * - 生成可复跑且幂等的数据
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/seed-user-comment-fixtures.php
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

echo "=== E2E v2: Seed User & Comment Fixtures ===\n\n";

$blog_id = get_current_blog_id();
$site    = get_bloginfo( 'name' );

$fixtures = array(
	array(
		'user_login'   => 'e2e_reader_cn',
		'user_email'   => 'e2e_reader_cn@wpmm.test',
		'display_name' => '陈读者',
		'first_name'   => '读者',
		'last_name'    => '陈',
		'role'         => 'subscriber',
		'locale'       => 'zh_CN',
		'description'  => '活跃读者，常在文章区留言。',
	),
	array(
		'user_login'   => 'e2e_reader_en',
		'user_email'   => 'e2e_reader_en@wpmm.test',
		'display_name' => 'Alex Reader',
		'first_name'   => 'Alex',
		'last_name'    => 'Reader',
		'role'         => 'subscriber',
		'locale'       => 'en_US',
		'description'  => 'English-speaking user leaving post comments and product reviews.',
	),
	array(
		'user_login'   => 'e2e_author_es',
		'user_email'   => 'e2e_author_es@wpmm.test',
		'display_name' => 'Marina Autor',
		'first_name'   => 'Marina',
		'last_name'    => 'Autor',
		'role'         => 'author',
		'locale'       => 'es_ES',
		'description'  => '内容作者，参与评论互动。',
	),
	array(
		'user_login'   => 'e2e_customer_shop',
		'user_email'   => 'e2e_customer_shop@wpmm.test',
		'display_name' => 'Shop Customer',
		'first_name'   => 'Shop',
		'last_name'    => 'Customer',
		'role'         => 'customer',
		'locale'       => 'en_US',
		'description'  => 'WooCommerce customer, posts product reviews.',
	),
	array(
		'user_login'   => 'e2e_forum_user',
		'user_email'   => 'e2e_forum_user@wpmm.test',
		'display_name' => '论坛用户A',
		'first_name'   => '论坛',
		'last_name'    => '用户A',
		'role'         => 'bbp_participant',
		'locale'       => 'zh_CN',
		'description'  => 'bbPress 论坛参与者。',
	),
	array(
		'user_login'   => 'e2e_marketer',
		'user_email'   => 'e2e_marketer@wpmm.test',
		'display_name' => 'Growth Marketer',
		'first_name'   => 'Growth',
		'last_name'    => 'Marketer',
		'role'         => 'contributor',
		'locale'       => 'en_US',
		'description'  => '运营角色，关注 SEO 与用户反馈。',
	),
);

$default_password = 'Wptsall-E2E-User-2026!';
$smoke_admin_password = 'Wptsall-Smoke-Admin-2026!';
$smoke_admin_fixture  = array(
	'user_login'   => 'e2esmokeadmin',
	'user_email'   => 'e2esmokeadmin@wpmm.test',
	'display_name' => 'E2E Smoke Admin',
	'first_name'   => 'E2E',
	'last_name'    => 'SmokeAdmin',
	'role'         => 'administrator',
	'locale'       => 'zh_CN',
	'description'  => 'E2E smoke admin for WP core + plugin backend/frontend access checks.',
);

/**
 * Ensure role exists. Fallback to subscriber.
 */
function e2e_fixture_resolve_role( string $role ): string {
	return get_role( $role ) ? $role : 'subscriber';
}

/**
 * Ensure user exists and has role in current site.
 *
 * @return int User ID.
 */
function e2e_fixture_ensure_user( array $fixture, string $password, int $blog_id ): int {
	$user = get_user_by( 'login', $fixture['user_login'] );
	$role = e2e_fixture_resolve_role( (string) $fixture['role'] );

	if ( ! $user ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => $fixture['user_login'],
				'user_pass'    => $password,
				'user_email'   => $fixture['user_email'],
				'display_name' => $fixture['display_name'],
				'first_name'   => $fixture['first_name'],
				'last_name'    => $fixture['last_name'],
				'role'         => $role,
				'description'  => $fixture['description'],
				'locale'       => $fixture['locale'],
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		$user = get_user_by( 'id', (int) $user_id );
	} else {
		$user_id = (int) $user->ID;
		wp_update_user(
			array(
				'ID'           => $user_id,
				'user_email'   => $fixture['user_email'],
				'display_name' => $fixture['display_name'],
				'first_name'   => $fixture['first_name'],
				'last_name'    => $fixture['last_name'],
				'description'  => $fixture['description'],
				'locale'       => $fixture['locale'],
			)
		);
		wp_set_password( $password, $user_id );
	}

	if ( is_multisite() && ! is_user_member_of_blog( $user_id, $blog_id ) ) {
		add_user_to_blog( $blog_id, $user_id, $role );
	}

	$wp_user = new WP_User( $user_id );
	if ( ! in_array( $role, (array) $wp_user->roles, true ) ) {
		$wp_user->add_role( $role );
	}

	update_user_meta( $user_id, 'locale', $fixture['locale'] );
	update_user_meta( $user_id, 'company', 'WPMM QA Lab' );
	update_user_meta( $user_id, 'job_title', 'QA Persona' );
	update_user_meta( $user_id, 'wptsall_fixture', 'e2e_user_comment_v1' );

	return $user_id;
}

/**
 * Login as user and set current context.
 *
 * @return WP_User|WP_Error
 */
function e2e_fixture_login_as( string $user_login, string $password ) {
	wp_set_current_user( 0 );
	return wp_signon(
		array(
			'user_login'    => $user_login,
			'user_password' => $password,
			'remember'      => false,
		),
		false
	);
}

/**
 * Ensure marker comment exists for target.
 *
 * @return int Comment ID.
 */
function e2e_fixture_ensure_comment(
	int $post_id,
	int $user_id,
	string $author_name,
	string $author_email,
	string $content,
	string $marker,
	string $type = '',
	array $meta = array()
): int {
	$existing = get_comments(
		array(
			'post_id' => $post_id,
			'search'  => $marker,
			'number'  => 1,
			'status'  => 'all',
			'type'    => 'all',
		)
	);
	if ( ! empty( $existing ) ) {
		return (int) $existing[0]->comment_ID;
	}

	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $author_name,
			'comment_author_email' => $author_email,
			'comment_content'      => trim( $content . "\n\n" . $marker ),
			'user_id'              => $user_id,
			'comment_type'         => $type,
			'comment_approved'     => 1,
		)
	);

	if ( $comment_id ) {
		foreach ( $meta as $key => $value ) {
			update_comment_meta( $comment_id, $key, $value );
		}
	}

	return (int) $comment_id;
}

/**
 * Ensure bbPress reply exists by marker.
 *
 * @return int Reply post ID.
 */
function e2e_fixture_ensure_bbp_reply( int $topic_id, int $forum_id, int $user_id, string $content, string $marker ): int {
	$existing = get_posts(
		array(
			'post_type'      => bbp_get_reply_post_type(),
			'post_parent'    => $topic_id,
			's'              => $marker,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $existing ) ) {
		return (int) $existing[0];
	}

	return (int) bbp_insert_reply(
		array(
			'post_parent'    => $topic_id,
			'post_content'   => trim( $content . "\n\n" . $marker ),
			'post_status'    => bbp_get_public_status_id(),
			'post_author'    => $user_id,
			'post_title'     => wp_trim_words( wp_strip_all_tags( $content ), 8 ),
			'reply_to'       => 0,
			'comment_status' => 'closed',
			'menu_order'     => 0,
			'forum_id'       => $forum_id,
		)
	);
}

$summary = array(
	'users_created_or_updated' => 0,
	'login_success'            => 0,
	'comments_added'           => 0,
	'woo_reviews_added'        => 0,
	'bbp_replies_added'        => 0,
	'smoke_admin'              => array(),
	'targets'                  => array(),
	'users'                    => array(),
);

// Resolve targets.
$post_id = (int) $wpdb->get_var(
	"SELECT ID FROM {$wpdb->posts}
	 WHERE post_type='post' AND post_status='publish'
	 ORDER BY ID DESC LIMIT 1"
);
$product_id = (int) $wpdb->get_var(
	"SELECT ID FROM {$wpdb->posts}
	 WHERE post_type='product' AND post_status='publish'
	 ORDER BY ID DESC LIMIT 1"
);
$event_id = (int) $wpdb->get_var(
	"SELECT ID FROM {$wpdb->posts}
	 WHERE post_type='tribe_events' AND post_status='publish'
	 ORDER BY ID DESC LIMIT 1"
);
$topic_id = (int) $wpdb->get_var(
	"SELECT ID FROM {$wpdb->posts}
	 WHERE post_type='topic' AND post_status='publish'
	 ORDER BY ID DESC LIMIT 1"
);
$forum_id = $topic_id > 0 ? (int) wp_get_post_parent_id( $topic_id ) : 0;

$summary['targets'] = array(
	'post_id'    => $post_id,
	'product_id' => $product_id,
	'event_id'   => $event_id,
	'topic_id'   => $topic_id,
	'forum_id'   => $forum_id,
);

if ( $post_id <= 0 ) {
	echo "No publish post found, abort.\n";
	exit( 1 );
}

foreach ( $fixtures as $idx => $fixture ) {
	$user_id = e2e_fixture_ensure_user( $fixture, $default_password, $blog_id );
	if ( $user_id <= 0 ) {
		echo "Failed to create/update user: {$fixture['user_login']}\n";
		continue;
	}

	$summary['users_created_or_updated']++;
	$summary['users'][] = array(
		'user_id'    => $user_id,
		'user_login' => $fixture['user_login'],
		'role'       => e2e_fixture_resolve_role( (string) $fixture['role'] ),
	);

	$auth = e2e_fixture_login_as( $fixture['user_login'], $default_password );
	if ( is_wp_error( $auth ) ) {
		echo "Login failed for {$fixture['user_login']}: {$auth->get_error_message()}\n";
		continue;
	}
	$summary['login_success']++;

	$marker_prefix = sprintf( '[E2E-USER-FIXTURE:%s]', $fixture['user_login'] );

	$core_comment = e2e_fixture_ensure_comment(
		$post_id,
		$user_id,
		$fixture['display_name'],
		$fixture['user_email'],
		'这篇内容结构很清晰，实际阅读体验不错。I also checked the translated version and want consistent tone.',
		$marker_prefix . ':post',
		''
	);
	if ( $core_comment > 0 ) {
		$summary['comments_added']++;
	}

	if ( $event_id > 0 && $idx < 3 ) {
		$event_comment = e2e_fixture_ensure_comment(
			$event_id,
			$user_id,
			$fixture['display_name'],
			$fixture['user_email'],
			'活动信息写得比较完整，建议补充地点交通说明。',
			$marker_prefix . ':event',
			''
		);
		if ( $event_comment > 0 ) {
			$summary['comments_added']++;
		}
	}

	if ( $product_id > 0 && in_array( $fixture['user_login'], array( 'e2e_reader_en', 'e2e_customer_shop', 'e2e_marketer' ), true ) ) {
		$rating = $fixture['user_login'] === 'e2e_marketer' ? 4 : 5;
		$review = e2e_fixture_ensure_comment(
			$product_id,
			$user_id,
			$fixture['display_name'],
			$fixture['user_email'],
			'Product quality is solid and packaging is careful. 物流速度可以再快一点。',
			$marker_prefix . ':product-review',
			'review',
			array(
				'rating'              => $rating,
				'verified'            => 1,
				'e2e_review_fixture'  => '1',
			)
		);
		if ( $review > 0 ) {
			$summary['woo_reviews_added']++;
		}
	}

	if ( $topic_id > 0 && function_exists( 'bbp_insert_reply' ) && in_array( $fixture['user_login'], array( 'e2e_forum_user', 'e2e_author_es' ), true ) ) {
		$reply_id = e2e_fixture_ensure_bbp_reply(
			$topic_id,
			$forum_id,
			$user_id,
			'论坛讨论很有价值，补充一个实测场景：翻译后短代码与媒体引用都应保持可用。',
			$marker_prefix . ':bbp-reply'
		);
		if ( $reply_id > 0 ) {
			$summary['bbp_replies_added']++;
		}
	}
}

// Ensure dedicated smoke admin for Playwright backend checks.
$smoke_admin_id = e2e_fixture_ensure_user( $smoke_admin_fixture, $smoke_admin_password, $blog_id );
if ( $smoke_admin_id > 0 ) {
	if ( is_multisite() && function_exists( 'grant_super_admin' ) ) {
		grant_super_admin( $smoke_admin_id );
	}
	$summary['smoke_admin'] = array(
		'user_id'        => $smoke_admin_id,
		'user_login'     => $smoke_admin_fixture['user_login'],
		'password'       => $smoke_admin_password,
		'is_super_admin' => is_multisite() ? is_super_admin( $smoke_admin_id ) : true,
	);
} else {
	$summary['smoke_admin'] = array(
		'error' => 'failed_to_create_or_update',
	);
}

wp_set_current_user( 1 );

$runtime_dir = e2e_runtime_dir();
if ( ! is_dir( $runtime_dir ) ) {
	wp_mkdir_p( $runtime_dir );
}
$runtime_file = $runtime_dir . '/user-comment-fixtures.json';
file_put_contents( $runtime_file, wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );

echo "--- Summary ---\n";
echo "site                : {$site} (blog_id={$blog_id})\n";
echo "users ensured       : {$summary['users_created_or_updated']}\n";
echo "login success       : {$summary['login_success']}\n";
echo "core/event comments : {$summary['comments_added']}\n";
echo "woo reviews         : {$summary['woo_reviews_added']}\n";
echo "bbp replies         : {$summary['bbp_replies_added']}\n";
echo "runtime file        : {$runtime_file}\n";
echo "\nDone.\n";
