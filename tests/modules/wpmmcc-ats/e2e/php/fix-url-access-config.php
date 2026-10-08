<?php
/**
 * E2E v2 URL 可访问性配置修复
 *
 * 目标：
 * - 修复 LearnPress / Tutor 丢失的关键页面配置。
 * - 为空 taxonomy 补齐最小可访问 term，并绑定到真实内容对象。
 * - 将已确认“登录门槛”对象规则标记为 requires_login=1（配置层，不改路由代码）。
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/fix-url-access-config.php
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

echo "=== E2E v2: Fix URL Access Config ===\n\n";

/**
 * Ensure a published page by slug/title/content.
 *
 * @return int Page ID.
 */
function e2e_fix_ensure_page( string $slug, string $title, string $content = '' ): int {
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
 * Ensure an option points to an existing published page.
 *
 * @return int Page ID.
 */
function e2e_fix_ensure_lp_option_page( string $option_key, string $slug, string $title ): int {
	$current_id = (int) get_option( $option_key, 0 );
	if ( $current_id > 0 ) {
		$post = get_post( $current_id );
		if ( $post && 'page' === $post->post_type && 'publish' === $post->post_status ) {
			return $current_id;
		}
	}

	$page_id = e2e_fix_ensure_page( $slug, $title, '' );
	if ( $page_id > 0 ) {
		update_option( $option_key, $page_id, false );
	}

	return $page_id;
}

/**
 * Ensure Tutor option page exists and update option value.
 *
 * @return int Page ID.
 */
function e2e_fix_ensure_tutor_option_page( string $option_key, string $slug, string $title, string $content ): int {
	$option_val = 0;
	if ( function_exists( 'tutor_utils' ) ) {
		$option_val = (int) tutor_utils()->get_option( $option_key );
	}
	if ( $option_val > 0 ) {
		$post = get_post( $option_val );
		if ( $post && 'page' === $post->post_type && 'publish' === $post->post_status ) {
			return $option_val;
		}
	}

	$page_id = e2e_fix_ensure_page( $slug, $title, $content );
	if ( $page_id > 0 && function_exists( 'tutor_utils' ) ) {
		tutor_utils()->update_option( $option_key, $page_id );
	}

	return $page_id;
}

/**
 * Ensure at least one term exists in taxonomy and bind to sample post.
 *
 * @return array {term_id, created, assigned_post_id}
 */
function e2e_fix_ensure_tax_term( string $taxonomy, string $slug, string $name, string $post_type ): array {
	$result = array(
		'term_id'          => 0,
		'created'          => false,
		'assigned_post_id' => 0,
	);

	if ( ! taxonomy_exists( $taxonomy ) ) {
		return $result;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		$insert = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug' => $slug,
			)
		);
		if ( is_wp_error( $insert ) ) {
			return $result;
		}
		$result['term_id'] = (int) ( $insert['term_id'] ?? 0 );
		$result['created'] = true;
	} else {
		$result['term_id'] = (int) $term->term_id;
	}

	if ( $result['term_id'] <= 0 || ! post_type_exists( $post_type ) ) {
		return $result;
	}

	$post_ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);
	if ( empty( $post_ids ) ) {
		return $result;
	}

	$post_id = (int) $post_ids[0];
	wp_set_object_terms( $post_id, array( $result['term_id'] ), $taxonomy, true );
	$result['assigned_post_id'] = $post_id;

	return $result;
}

/**
 * Ensure a published sample post exists for a post type.
 *
 * @return array{id:int,created:bool,error:string}
 */
function e2e_fix_ensure_sample_post( string $post_type, string $title, string $content = '' ): array {
	global $wpdb;

	$result = array(
		'id'      => 0,
		'created' => false,
		'error'   => '',
	);

	if ( ! post_type_exists( $post_type ) ) {
		$result['error'] = 'post_type_not_exists';
		return $result;
	}

	$existing_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = %s
			   AND post_status = 'publish'
			 ORDER BY ID DESC
			 LIMIT 1",
			$post_type
		)
	);
	if ( $existing_id > 0 ) {
		$result['id'] = $existing_id;
		return $result;
	}

	$slug      = sanitize_title( $title . '-' . $post_type . '-' . wp_generate_password( 6, false ) );
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
			'post_type'             => $post_type,
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
		$result['error'] = 'insert_failed';
		return $result;
	}

	$post_id = (int) $wpdb->insert_id;
	if ( $post_id <= 0 ) {
		$result['error'] = 'insert_id_missing';
		return $result;
	}
	$wpdb->update(
		$wpdb->posts,
		array(
			'guid' => home_url( '/?p=' . $post_id ),
		),
		array( 'ID' => $post_id ),
		array( '%s' ),
		array( '%d' )
	);
	clean_post_cache( $post_id );

	$result['id']      = (int) $post_id;
	$result['created'] = (int) $post_id > 0;
	return $result;
}

/**
 * Ensure at least one Woo product keeps serialized _product_attributes payload.
 *
 * @return array{product_id:int,action:string,structured_count:int}
 */
function e2e_fix_ensure_woo_serialized_attributes(): array {
	global $wpdb;

	$result = array(
		'product_id'       => 0,
		'action'           => 'skipped',
		'structured_count' => 0,
	);

	if ( ! post_type_exists( 'product' ) ) {
		return $result;
	}

	$serialized_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_product_attributes'
		   AND meta_value LIKE 'a:%'"
	);
	$result['structured_count'] = $serialized_count;
	if ( $serialized_count > 0 ) {
		$result['action'] = 'kept';
		return $result;
	}

	$product_id = (int) $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'product'
		   AND post_status = 'publish'
		 ORDER BY ID DESC
		 LIMIT 1"
	);
	if ( $product_id <= 0 ) {
		return $result;
	}

	update_post_meta(
		$product_id,
		'_product_attributes',
		array(
			'e2e_material' => array(
				'name'         => 'E2E Material',
				'value'        => 'Cotton | Linen',
				'position'     => 0,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 0,
			),
		)
	);

	$serialized_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_product_attributes'
		   AND meta_value LIKE 'a:%'"
	);

	$result['product_id']       = $product_id;
	$result['structured_count'] = $serialized_count;
	$result['action']           = $serialized_count > 0 ? 'seeded' : 'failed';
	return $result;
}

/**
 * Ensure ACF-like product meta exists before model scanning.
 *
 * @return array{product_id:int,action:string,fields:string[]}
 */
function e2e_fix_ensure_acf_like_product_meta(): array {
	global $wpdb;

	$result = array(
		'product_id' => 0,
		'action'     => 'skipped',
		'fields'     => array(),
	);

	if ( ! post_type_exists( 'product' ) ) {
		return $result;
	}

	$product_id = (int) $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'product'
		   AND post_status = 'publish'
		 ORDER BY ID DESC
		 LIMIT 1"
	);
	if ( $product_id <= 0 ) {
		return $result;
	}

	$meta = array(
		'brand'           => 'WPTSALL Sample Brand',
		'warranty_period' => '24 months',
		'manufacturer'    => 'WPTSALL Fixture Manufacturing',
	);

	foreach ( $meta as $key => $value ) {
		update_post_meta( $product_id, $key, $value );
		$result['fields'][] = $key;
	}

	$result['product_id'] = $product_id;
	$result['action']     = 'seeded';
	return $result;
}

$summary = array(
	'learnpress_pages'    => array(),
	'learnpress_permalink'=> array(),
	'tutor_pages'         => array(),
	'sample_posts'        => array(),
	'structured_fields'   => array(),
	'taxonomy_terms'      => array(),
	'rules_requires_login'=> array(
		'updated' => 0,
		'rows'    => array(),
	),
);

// ---------------------------------------------------------------------------
// 1) LearnPress pages
// ---------------------------------------------------------------------------
// Avoid calling LP_Install::create_pages() here:
// on some plugin combinations it triggers recursive wp_insert_post chains.
// We only use SQL-safe page upsert through e2e_fix_ensure_lp_option_page().

$lp_page_map = array(
	'learn_press_courses_page_id'          => array( 'slug' => 'courses',           'title' => 'Courses' ),
	'learn_press_profile_page_id'          => array( 'slug' => 'lp-profile',        'title' => 'Profile' ),
	'learn_press_checkout_page_id'         => array( 'slug' => 'lp-checkout',       'title' => 'Checkout' ),
	'learn_press_instructors_page_id'      => array( 'slug' => 'instructors',       'title' => 'Instructors' ),
	'learn_press_single_instructor_page_id'=> array( 'slug' => 'instructor',        'title' => 'Instructor' ),
	'learn_press_become_a_teacher_page_id' => array( 'slug' => 'become_a_teacher',  'title' => 'Become an Instructor' ),
	'learn_press_term_conditions_page_id'  => array( 'slug' => 'term_conditions',   'title' => 'Terms and Conditions' ),
);

foreach ( $lp_page_map as $option_key => $cfg ) {
	$page_id = e2e_fix_ensure_lp_option_page(
		$option_key,
		(string) $cfg['slug'],
		(string) $cfg['title']
	);
	$summary['learnpress_pages'][ $option_key ] = $page_id;
}

// Keep LP taxonomy bases unique to avoid collisions with Tutor taxonomies.
if ( class_exists( 'LP_Settings' ) ) {
	LP_Settings::update_option( 'course_category_base', 'lp-course-category' );
	LP_Settings::update_option( 'course_tag_base', 'lp-course-tag' );

	$summary['learnpress_permalink'] = array(
		'course_category_base' => (string) LP_Settings::get_option( 'course_category_base', '' ),
		'course_tag_base'      => (string) LP_Settings::get_option( 'course_tag_base', '' ),
	);
}

// ---------------------------------------------------------------------------
// 2) Tutor pages
// ---------------------------------------------------------------------------
if ( function_exists( 'tutor_utils' ) ) {
	$tutor_page_map = array(
		'tutor_dashboard_page_id'  => array(
			'slug'    => 'tutor-dashboard',
			'title'   => 'Tutor Dashboard',
			'content' => '[tutor_dashboard]',
		),
		'student_register_page'    => array(
			'slug'    => 'student-registration',
			'title'   => 'Student Registration',
			'content' => '[tutor_student_registration_form]',
		),
		'instructor_register_page' => array(
			'slug'    => 'instructor-registration',
			'title'   => 'Instructor Registration',
			'content' => '[tutor_instructor_registration_form]',
		),
		'tutor_cart_page_id'       => array(
			'slug'    => 'tutor-cart',
			'title'   => 'Tutor Cart',
			'content' => '[tutor_cart]',
		),
		'tutor_checkout_page_id'   => array(
			'slug'    => 'tutor-checkout',
			'title'   => 'Tutor Checkout',
			'content' => '[tutor_checkout]',
		),
	);

	foreach ( $tutor_page_map as $option_key => $cfg ) {
		$page_id = e2e_fix_ensure_tutor_option_page(
			$option_key,
			(string) $cfg['slug'],
			(string) $cfg['title'],
			(string) $cfg['content']
		);
		$summary['tutor_pages'][ $option_key ] = $page_id;
	}
}

// ---------------------------------------------------------------------------
// 3) Ensure sample posts for rule coverage
// ---------------------------------------------------------------------------
$sample_post_fixtures = array(
	array(
		'post_type' => 'tutor_quiz',
		'title'     => 'Tutor Quiz Sample',
		'content'   => 'E2E sample tutor quiz content.',
	),
	array(
		'post_type' => 'tutor_assignments',
		'title'     => 'Tutor Assignment Sample',
		'content'   => 'E2E sample tutor assignment content.',
	),
	array(
		'post_type' => 'tutor_enrolled',
		'title'     => 'Tutor Enrolled Sample',
		'content'   => 'E2E sample tutor enrolled content.',
	),
	array(
		'post_type' => 'tec_calendar_embed',
		'title'     => 'TEC Calendar Embed Sample',
		'content'   => 'E2E sample calendar embed content.',
	),
	array(
		'post_type' => 'e-floating-buttons',
		'title'     => 'Elementor Floating Buttons Sample',
		'content'   => 'E2E sample floating buttons content.',
	),
	array(
		'post_type' => 'elementor_component',
		'title'     => 'Elementor Component Sample',
		'content'   => 'E2E sample elementor component content.',
	),
	array(
		'post_type' => 'wprm_list',
		'title'     => 'WPRM List Sample',
		'content'   => 'E2E sample recipe list content.',
	),
);

foreach ( $sample_post_fixtures as $fixture ) {
	$post_type = (string) $fixture['post_type'];
	$res       = e2e_fix_ensure_sample_post(
		$post_type,
		(string) $fixture['title'],
		(string) $fixture['content']
	);

	$summary['sample_posts'][ $post_type ] = array(
		'id'      => (int) $res['id'],
		'created' => (bool) $res['created'],
		'action'  => (int) $res['id'] > 0
			? ( $res['created'] ? 'created' : 'kept' )
			: 'skipped',
		'error'   => (string) $res['error'],
	);
}

// ---------------------------------------------------------------------------
// 3.5) Ensure Elementor library JSON fixture for elementor-content gates
// ---------------------------------------------------------------------------
define( 'E2E_URL_ACCESS_CONFIG_RUNNING', true );
require_once __DIR__ . '/seed-elementor-library-fixture.php';
$summary['structured_fields']['elementor_library'] = e2e_seed_elementor_library_fixture();

// ---------------------------------------------------------------------------
// 3.6) Ensure serialized Woo field sample for structured gate
// ---------------------------------------------------------------------------
$summary['structured_fields']['woocommerce_product_attributes'] = e2e_fix_ensure_woo_serialized_attributes();
$summary['structured_fields']['acf_like_product_meta'] = e2e_fix_ensure_acf_like_product_meta();

// ---------------------------------------------------------------------------
// 4) Taxonomy terms for URL accessibility
// ---------------------------------------------------------------------------
$tax_fixtures = array(
	array(
		'taxonomy'  => 'product_brand',
		'slug'      => 'sample-brand',
		'name'      => 'Sample Brand',
		'post_type' => 'product',
	),
	array(
		'taxonomy'  => 'product_tag',
		'slug'      => 'sample-product-tag',
		'name'      => 'Sample Product Tag',
		'post_type' => 'product',
	),
	array(
		'taxonomy'  => 'product_shipping_class',
		'slug'      => 'sample-shipping-class',
		'name'      => 'Sample Shipping Class',
		'post_type' => 'product',
	),
	array(
		'taxonomy'  => 'course_tag',
		'slug'      => 'sample-course-tag',
		'name'      => 'Sample Course Tag',
		'post_type' => 'lp_course',
	),
	array(
		'taxonomy'  => 'question_tag',
		'slug'      => 'sample-question-tag',
		'name'      => 'Sample Question Tag',
		'post_type' => 'lp_question',
	),
	array(
		'taxonomy'  => 'tribe_events_cat',
		'slug'      => 'social',
		'name'      => 'Social',
		'post_type' => 'tribe_events',
	),
	array(
		'taxonomy'  => 'elementor_library_category',
		'slug'      => 'sample-elementor-library-category',
		'name'      => 'Sample Elementor Library Category',
		'post_type' => 'elementor_library',
	),
	array(
		'taxonomy'  => 'wprm_keyword',
		'slug'      => 'sample-wprm-keyword',
		'name'      => 'Sample WPRM Keyword',
		'post_type' => 'wprm_recipe',
	),
	array(
		'taxonomy'  => 'wprm_ingredient',
		'slug'      => 'sample-wprm-ingredient',
		'name'      => 'Sample WPRM Ingredient',
		'post_type' => 'wprm_recipe',
	),
	array(
		'taxonomy'  => 'wprm_ingredient_unit',
		'slug'      => 'sample-wprm-ingredient-unit',
		'name'      => 'Sample WPRM Ingredient Unit',
		'post_type' => 'wprm_recipe',
	),
	array(
		'taxonomy'  => 'wprm_glossary_term',
		'slug'      => 'sample-wprm-glossary-term',
		'name'      => 'Sample WPRM Glossary Term',
		'post_type' => 'wprm_recipe',
	),
	array(
		'taxonomy'  => 'wprm_equipment',
		'slug'      => 'sample-wprm-equipment',
		'name'      => 'Sample WPRM Equipment',
		'post_type' => 'wprm_recipe',
	),
);

foreach ( $tax_fixtures as $fixture ) {
	$taxonomy = (string) $fixture['taxonomy'];
	$res = e2e_fix_ensure_tax_term(
		$taxonomy,
		(string) $fixture['slug'],
		(string) $fixture['name'],
		(string) $fixture['post_type']
	);
	$summary['taxonomy_terms'][ $taxonomy ] = array(
		'action'           => $res['term_id'] > 0
			? ( $res['created'] ? 'created_or_assigned' : 'ensured_or_assigned' )
			: 'skipped',
		'term_id'          => (int) $res['term_id'],
		'created'          => (bool) $res['created'],
		'assigned_post_id' => (int) $res['assigned_post_id'],
		'count_before'     => null,
		'count'            => null,
		);
}

// ---------------------------------------------------------------------------
// 5) Mark gated objects as requires_login
// ---------------------------------------------------------------------------
$rules_table      = e2e_table( 'translation_rules' );
$gated_object_set = array( 'lp_lesson', 'lp_quiz', 'lp_question', 'envira', 'tutor_enrolled', 'wprm_list' );

foreach ( $gated_object_set as $object_name ) {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, object_name, requires_login
			 FROM $rules_table
			 WHERE data_type = 'post'
			   AND object_name = %s
			   AND is_active = 1",
			$object_name
		),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$rule_id = (int) ( $row['id'] ?? 0 );
		if ( $rule_id <= 0 ) {
			continue;
		}
		if ( 1 !== (int) ( $row['requires_login'] ?? 0 ) ) {
			$ok = $wpdb->update(
				$rules_table,
				array(
					'requires_login' => 1,
					'updated_at'     => current_time( 'mysql' ),
				),
				array( 'id' => $rule_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			if ( false !== $ok ) {
				$summary['rules_requires_login']['updated']++;
			}
		}
		$summary['rules_requires_login']['rows'][] = array(
			'id'            => $rule_id,
			'object_name'   => (string) ( $row['object_name'] ?? '' ),
			'requires_login'=> 1,
		);
	}
}

flush_rewrite_rules( false );

echo wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
echo "\nrewrite rules flushed\n";
