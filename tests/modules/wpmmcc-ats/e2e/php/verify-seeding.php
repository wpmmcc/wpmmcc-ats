<?php
/**
 * E2E v2 验证数据填充完整性
 *
 * 检查 15 个插件的 seed 数据是否存在。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-seeding.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Verify Seeding Data ===\n\n";

// Section timing (PERF 2026-09-02): gate5 RG-CORE showed ~183s in Stage 3
// verify on the full core dataset; these markers localize slow sections in
// future gate logs without changing any check semantics.
// NOTE: wp eval-file top-level variables are not PHP globals, so the marker
// helper reads/writes $GLOBALS explicitly.
$GLOBALS['e2e_verify_started_at'] = microtime( true );
function e2e_verify_mark( string $section ): void {
	$started = isset( $GLOBALS['e2e_verify_started_at'] )
		? (float) $GLOBALS['e2e_verify_started_at']
		: microtime( true );
	printf(
		"[verify-timing] %-28s %0.1fs\n",
		$section,
		microtime( true ) - $started
	);
}

$passed  = 0;
$failed  = 0;
$skipped = 0;
$checks  = array();
$project = e2e_project();
$project_plugin = e2e_project_plugin_slug();

/**
 * Label used in plugin-specific skip messages.
 */
function e2e_seed_project_label(): string {
	return e2e_project();
}

/**
 * Seed verifier contract for current plugin-specific project.
 *
 * @return array<string,mixed>
 */
function e2e_plugin_seed_checks(): array {
	return e2e_plugin_project_seed_checks();
}

/**
 * Read boolean seed verifier flag from plugin-specific project spec.
 *
 * @param string $field   Field name.
 * @param bool   $default Default value.
 * @return bool
 */
function e2e_plugin_seed_check_bool( string $field, bool $default = false ): bool {
	$checks = e2e_plugin_seed_checks();
	if ( ! array_key_exists( $field, $checks ) ) {
		return $default;
	}

	return (bool) $checks[ $field ];
}

/**
 * Read scalar seed verifier field from plugin-specific project spec.
 *
 * @param string $field   Field name.
 * @param string $default Default value.
 * @return string
 */
function e2e_plugin_seed_check_scalar( string $field, string $default = '' ): string {
	$checks = e2e_plugin_seed_checks();
	$value  = $checks[ $field ] ?? $default;

	return is_scalar( $value ) ? (string) $value : $default;
}

/**
 * Read numeric seed verifier field from plugin-specific project spec.
 *
 * @param string $field   Field name.
 * @param float  $default Default value.
 * @return float
 */
function e2e_plugin_seed_check_number( string $field, float $default ): float {
	$checks = e2e_plugin_seed_checks();
	$value  = $checks[ $field ] ?? $default;

	return is_numeric( $value ) ? (float) $value : $default;
}

/**
 * Read list seed verifier field from plugin-specific project spec.
 *
 * @param string $field Field name.
 * @return string[]
 */
function e2e_plugin_seed_check_list( string $field ): array {
	$checks = e2e_plugin_seed_checks();
	$raw    = $checks[ $field ] ?? array();

	if ( ! is_array( $raw ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map(
				static function ( $value ): string {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				$raw
			),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		)
	);
}

// ---------------------------------------------------------------------------
// 每个插件期望的 post_type 和最小数量
// ---------------------------------------------------------------------------
$plugin_expectations = array();
if ( '' !== $project_plugin ) {
	$seed_expectation = e2e_plugin_project_seed_expectation();
	if ( ! empty( $seed_expectation ) ) {
		$plugin_expectations[ $project_plugin ] = $seed_expectation;
	}

	if ( 'wptsall' !== $project_plugin && e2e_plugin_seed_check_bool( 'require_wptsall_core_seed', false ) ) {
		$plugin_expectations['wptsall'] = array( 'post_type' => 'post', 'min' => 10, 'core' => true );
	}
} elseif ( e2e_is_learning_content_project() ) {
	$plugin_expectations = array(
		'tutor'      => array( 'post_type' => 'courses',   'min' => 5 ),
		'learnpress' => array( 'post_type' => 'lp_course', 'min' => 3 ),
		'wptsall'    => array( 'post_type' => 'post',      'min' => 10, 'core' => true ),
	);
} elseif ( e2e_is_commerce_content_project() ) {
	$plugin_expectations = array(
		'woocommerce'            => array( 'post_type' => 'product',  'min' => 10 ),
		'easy-digital-downloads' => array( 'post_type' => 'download', 'min' => 5 ),
		'wptsall'                => array( 'post_type' => 'post',     'min' => 10, 'core' => true ),
	);
} elseif ( e2e_is_media_builder_content_project() ) {
	$plugin_expectations = array(
		'envira-gallery-lite' => array( 'post_type' => 'envira', 'min' => 3 ),
		'elementor'           => array( 'post_type' => 'page',   'min' => 2, 'meta_key' => '_elementor_edit_mode' ),
		'wptsall'             => array( 'post_type' => 'post',   'min' => 10, 'core' => true ),
	);
} elseif ( e2e_is_content_meta_content_project() ) {
	$plugin_expectations = array(
		'seriously-simple-podcasting' => array( 'post_type' => 'podcast',     'min' => 5 ),
		'wp-recipe-maker'             => array( 'post_type' => 'wprm_recipe', 'min' => 5 ),
		'wordpress-seo'               => array( 'post_type' => 'post',        'min' => 5, 'meta_key' => '_yoast_wpseo_metadesc' ),
		// acf_like_product_meta seeds exactly one demo product with brand meta.
		'advanced-custom-fields'      => array( 'post_type' => 'product',     'min' => 1, 'meta_key' => 'brand' ),
		'wptsall'                     => array( 'post_type' => 'post',        'min' => 10, 'core' => true ),
	);
} elseif ( e2e_is_core_content_project() ) {
	$plugin_expectations = array(
		'wptsall' => array( 'post_type' => 'post', 'min' => 10, 'core' => true ),
	);
} else {
	$plugin_expectations = array(
		'woocommerce'                 => array( 'post_type' => 'product',                 'min' => 10 ),
		'easy-digital-downloads'      => array( 'post_type' => 'download',                'min' => 5 ),
		'bbpress'                     => array( 'post_type' => 'topic',                   'min' => 5 ),
		'tutor'                       => array( 'post_type' => 'courses',                 'min' => 5 ),
		'learnpress'                  => array( 'post_type' => 'lp_course',               'min' => 3 ),
		'the-events-calendar'         => array( 'post_type' => 'tribe_events',            'min' => 5 ),
		'wp-job-manager'              => array( 'post_type' => 'job_listing',             'min' => 5 ),
		'envira-gallery-lite'         => array( 'post_type' => 'envira',                  'min' => 3 ),
		'seriously-simple-podcasting' => array( 'post_type' => 'podcast',                 'min' => 5 ),
		'wp-recipe-maker'             => array( 'post_type' => 'wprm_recipe',             'min' => 5 ),
		'elementor'                   => array( 'post_type' => 'page',                    'min' => 2, 'meta_key' => '_elementor_edit_mode' ),
		'wordpress-seo'               => array( 'post_type' => 'post',                    'min' => 5, 'meta_key' => '_yoast_wpseo_metadesc' ),
		// acf_like_product_meta seeds exactly one demo product with brand meta.
		'advanced-custom-fields'      => array( 'post_type' => 'product',                 'min' => 1, 'meta_key' => 'brand' ),
		'site-reviews'                => array( 'post_type' => 'site-review',             'min' => 5 ),
		'wptsall'                     => array( 'post_type' => 'post',                    'min' => 10, 'core' => true ),
	);
}

foreach ( $plugin_expectations as $slug => $expect ) {
	$pt  = $expect['post_type'];
	$min = $expect['min'];

	if ( ! empty( $expect['meta_key'] ) ) {
		// 特殊检查：通过 meta_key 验证
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key = %s",
			$pt, $expect['meta_key']
		) );
	} elseif ( ! empty( $expect['core'] ) ) {
		// WordPress 核心内容（post/page）
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$pt
		) );
	} else {
		// 标准 post_type 数量检查
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$pt
		) );
	}

	$pass = $count >= $min;
	e2e_check(
		"$slug ($pt)",
		$pass,
		"found $count, need >= $min",
		$checks, $passed, $failed
	);
}

// ---------------------------------------------------------------------------
// 额外检查：WordPress 核心内容
// ---------------------------------------------------------------------------
echo "\n--- WordPress Core Content ---\n";
e2e_verify_mark( 'project-expectations' );

$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
$pages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'" );

$core_content_gate_enabled = true;
if ( '' !== $project_plugin ) {
	$core_content_gate_enabled = e2e_plugin_seed_check_bool( 'core_content_gate', 'wptsall' === $project_plugin );
}

$wp_posts_min = '' !== $project_plugin
	? (int) e2e_plugin_seed_check_number( 'wp_posts_min', 'wptsall' === $project_plugin ? 10.0 : 1.0 )
	: 10;
$wp_pages_min = '' !== $project_plugin
	? (int) e2e_plugin_seed_check_number( 'wp_pages_min', 'wptsall' === $project_plugin ? 3.0 : 1.0 )
	: 3;

if ( ! $core_content_gate_enabled ) {
	echo '  ' . e2e_seed_project_label() . ": WordPress core content hard gate skipped in Stage 3.\n";
	$skipped += 2;
} else {
	e2e_check( 'WordPress posts', $posts >= $wp_posts_min, "found $posts, need >= $wp_posts_min", $checks, $passed, $failed );
	e2e_check( 'WordPress pages', $pages >= $wp_pages_min, "found $pages, need >= $wp_pages_min", $checks, $passed, $failed );
}

// ---------------------------------------------------------------------------
// 额外检查：Taxonomy 内容
// ---------------------------------------------------------------------------
echo "\n--- Taxonomy Content ---\n";
e2e_verify_mark( 'core-content' );

$categories = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category'" );
$tags       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'post_tag'" );

e2e_check( 'Categories', $categories >= 2, "found $categories", $checks, $passed, $failed );
e2e_check( 'Tags', $tags >= 2, "found $tags", $checks, $passed, $failed );

// ---------------------------------------------------------------------------
// 额外检查：关键 content_format 样本存在（serialized/json）
// ---------------------------------------------------------------------------
echo "\n--- Structured Field Presence ---\n";
e2e_verify_mark( 'taxonomy' );

if ( '' !== $project_plugin ) {
	$structured_gate = e2e_plugin_seed_check_scalar( 'structured_gate', 'skip' );

	if ( 'serialized_product_attributes' === $structured_gate ) {
		$serialized_attrs = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_product_attributes'
			   AND meta_value LIKE 'a:%'"
		);
		e2e_check( 'Serialized _product_attributes', $serialized_attrs >= 1, "found $serialized_attrs", $checks, $passed, $failed );
	} elseif ( 'serialized_envira_gallery' === $structured_gate ) {
		$serialized_envira = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key IN ('_envira_gallery_data', '_eg_gallery_data')
			   AND meta_value LIKE 'a:%'"
		);
		e2e_check( 'Serialized Envira gallery data', $serialized_envira >= 1, "found $serialized_envira", $checks, $passed, $failed );
	} elseif ( 'elementor_json' === $structured_gate ) {
		$elementor_json = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_elementor_data'
			   AND (meta_value LIKE '[%' OR meta_value LIKE '{%')"
		);
		e2e_check( 'JSON _elementor_data', $elementor_json >= 1, "found $elementor_json", $checks, $passed, $failed );
	} else {
		echo '  ' . e2e_seed_project_label() . ": structured field hard gate skipped in Stage 3.\n";
		++$skipped;
	}
} elseif ( e2e_is_learning_content_project() ) {
	echo "  learning-content: structured field hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_commerce_content_project() ) {
	$serialized_attrs = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_product_attributes'
		   AND meta_value LIKE 'a:%'"
	);

	e2e_check( 'Serialized _product_attributes', $serialized_attrs >= 1, "found $serialized_attrs", $checks, $passed, $failed );
	echo "  commerce-content: JSON structured hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_media_builder_content_project() ) {
	$serialized_envira = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key IN ('_envira_gallery_data', '_eg_gallery_data')
		   AND meta_value LIKE 'a:%'"
	);
	$elementor_json = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_elementor_data'
		   AND (meta_value LIKE '[%' OR meta_value LIKE '{%')"
	);

	e2e_check( 'Serialized Envira gallery data', $serialized_envira >= 1, "found $serialized_envira", $checks, $passed, $failed );
	e2e_check( 'JSON _elementor_data', $elementor_json >= 1, "found $elementor_json", $checks, $passed, $failed );
} elseif ( e2e_is_community_content_project() ) {
	echo "  community-content: structured field hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_listings_events_content_project() ) {
	echo "  listings-events-content: structured field hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_content_meta_content_project() ) {
	echo "  content-meta-content: structured field hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_core_content_project() ) {
	echo "  core-content: structured field hard gate skipped in Stage 3.\n";
	++$skipped;
} else {
	$serialized_attrs = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_product_attributes'
		   AND meta_value LIKE 'a:%'"
	);
	$elementor_json = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_elementor_data'
		   AND meta_value LIKE '[%'"
	);

	e2e_check( 'Serialized _product_attributes', $serialized_attrs >= 1, "found $serialized_attrs", $checks, $passed, $failed );
	e2e_check( 'JSON _elementor_data', $elementor_json >= 1, "found $elementor_json", $checks, $passed, $failed );
}

// ---------------------------------------------------------------------------
// 额外检查：真实性与可用性（SEO / ALT / excerpt / 时间分布）
// ---------------------------------------------------------------------------
echo "\n--- Realism & Usability ---\n";
e2e_verify_mark( 'structured-fields' );

$plugin_realism_types = array(
	'woocommerce'                 => array( 'post', 'page', 'product' ),
	'easy-digital-downloads'      => array( 'post', 'page', 'download' ),
	'bbpress'                     => array( 'post', 'page', 'forum', 'topic', 'reply' ),
	'tutor'                       => array( 'post', 'page', 'courses' ),
	'learnpress'                  => array( 'post', 'page', 'lp_course' ),
	'the-events-calendar'         => array( 'post', 'page', 'tribe_events' ),
	'wp-job-manager'              => array( 'post', 'page', 'job_listing' ),
	'envira-gallery-lite'         => array( 'post', 'page', 'envira' ),
	'seriously-simple-podcasting' => array( 'post', 'page', 'podcast' ),
	'wp-recipe-maker'             => array( 'post', 'page', 'wprm_recipe' ),
	'elementor'                   => array( 'post', 'page', 'elementor_library' ),
	'wordpress-seo'               => array( 'post', 'page' ),
	'advanced-custom-fields'      => array( 'post', 'page', 'product' ),
	'site-reviews'                => array( 'post', 'page', 'site-review' ),
	'wptsall'                     => array( 'post', 'page' ),
);

if ( '' !== $project_plugin ) {
	$realism_post_types = e2e_plugin_seed_check_list( 'realism_post_types' );
	if ( empty( $realism_post_types ) ) {
		$realism_post_types = $plugin_realism_types[ $project_plugin ] ?? array( 'post', 'page' );
	}
} elseif ( e2e_is_learning_content_project() ) {
	$realism_post_types = array( 'post', 'page', 'courses', 'lp_course' );
} elseif ( e2e_is_commerce_content_project() ) {
	$realism_post_types = array( 'post', 'page', 'product', 'download' );
} elseif ( e2e_is_media_builder_content_project() ) {
	$realism_post_types = array( 'post', 'page', 'envira', 'elementor_library' );
} elseif ( e2e_is_community_content_project() ) {
	$realism_post_types = array( 'post', 'page', 'forum', 'topic', 'reply', 'site-review' );
} elseif ( e2e_is_listings_events_content_project() ) {
	$realism_post_types = array( 'post', 'page', 'tribe_events', 'job_listing' );
} elseif ( e2e_is_content_meta_content_project() ) {
	$realism_post_types = array( 'post', 'page', 'podcast', 'wprm_recipe' );
} elseif ( e2e_is_core_content_project() ) {
	$realism_post_types = array( 'post', 'page' );
} else {
	$realism_post_types = array(
		'post',
		'page',
		'product',
		'download',
		'topic',
		'courses',
		'lp_course',
		'tribe_events',
		'job_listing',
		'envira',
		'podcast',
		'wprm_recipe',
		'site-review',
	);
}
$type_sql = "'" . implode( "','", array_map( 'esc_sql', $realism_post_types ) ) . "'";

$realism_total = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts}
	 WHERE post_status='publish'
	   AND post_type IN ($type_sql)"
);

$seo_covered = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
	 WHERE p.post_status='publish'
	   AND p.post_type IN ($type_sql)
	   AND pm.meta_key IN ('_yoast_wpseo_metadesc','_aioseo_description','rank_math_description')
	   AND pm.meta_value <> ''"
);
$seo_ratio = $realism_total > 0 ? round( ( $seo_covered / $realism_total ) * 100, 1 ) : 0;
$seo_ratio_min = '' !== $project_plugin
	? e2e_plugin_seed_check_number( 'seo_ratio_min', 30.0 )
	: 80.0;
if ( '' !== $project_plugin && ! e2e_plugin_seed_check_bool( 'seo_gate', true ) ) {
	echo '  ' . e2e_seed_project_label() . ": SEO coverage hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_community_content_project() ) {
	echo "  community-content: SEO coverage hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_core_content_project() && e2e_is_core_only() ) {
	echo "  core-content (core-only): SEO coverage hard gate skipped in Stage 3.\n";
	++$skipped;
} else {
	e2e_check(
		'SEO coverage',
		$realism_total > 0 && $seo_ratio >= $seo_ratio_min,
		"found $seo_covered/$realism_total ({$seo_ratio}%), need >= {$seo_ratio_min}%",
		$checks, $passed, $failed
	);
}

$featured_total = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id'
	 INNER JOIN {$wpdb->posts} a ON a.ID = th.meta_value
	 WHERE p.post_status='publish'
	   AND p.post_type IN ($type_sql)
	   AND a.post_type = 'attachment'
	   AND a.post_mime_type LIKE 'image/%'"
);
$featured_with_alt = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id'
	 INNER JOIN {$wpdb->posts} a ON a.ID = th.meta_value
	 INNER JOIN {$wpdb->postmeta} alt ON alt.post_id = th.meta_value
	 WHERE p.post_status='publish'
	   AND p.post_type IN ($type_sql)
	   AND a.post_type = 'attachment'
	   AND a.post_mime_type LIKE 'image/%'
	   AND alt.meta_key = '_wp_attachment_image_alt'
	   AND alt.meta_value <> ''"
);
$featured_alt_ratio = $featured_total > 0 ? round( ( $featured_with_alt / $featured_total ) * 100, 1 ) : 0;
if ( '' !== $project_plugin && ! e2e_plugin_seed_check_bool( 'featured_alt_gate', 'wptsall' === $project_plugin ) ) {
	echo '  ' . e2e_seed_project_label() . ": featured image ALT hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_core_content_project() && e2e_is_core_only() ) {
	$feat_min = 0;
	if ( $featured_total === 0 ) {
		echo "  core-content (core-only): featured image ALT gate skipped (no featured images).\n";
		++$skipped;
	} else {
		e2e_check(
			'Featured image ALT coverage',
			true,
			"found $featured_with_alt/$featured_total ({$featured_alt_ratio}%), core-only: informational only",
			$checks, $passed, $failed
		);
	}
} else {
	e2e_check(
		'Featured image ALT coverage',
		$featured_total >= 20 && $featured_alt_ratio >= 90.0,
		"found $featured_with_alt/$featured_total ({$featured_alt_ratio}%), need >= 90% and >=20 featured posts",
		$checks, $passed, $failed
	);
}

$excerpt_total = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts}
	 WHERE post_status='publish'
	   AND post_type IN ($type_sql)"
);
$excerpt_filled = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts}
	 WHERE post_status='publish'
	   AND post_type IN ($type_sql)
	   AND post_excerpt <> ''"
);
$excerpt_ratio = $excerpt_total > 0 ? round( ( $excerpt_filled / $excerpt_total ) * 100, 1 ) : 0;
$excerpt_ratio_min = '' !== $project_plugin
	? e2e_plugin_seed_check_number( 'excerpt_ratio_min', 50.0 )
	: 70.0;
if ( '' !== $project_plugin && ! e2e_plugin_seed_check_bool( 'excerpt_gate', true ) ) {
	echo '  ' . e2e_seed_project_label() . ": excerpt coverage hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_community_content_project() ) {
	echo "  community-content: excerpt coverage hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_core_content_project() && e2e_is_core_only() ) {
	echo "  core-content (core-only): excerpt coverage hard gate skipped in Stage 3.\n";
	++$skipped;
} else {
	e2e_check(
		'Excerpt coverage',
		$excerpt_total > 0 && $excerpt_ratio >= $excerpt_ratio_min,
		"found $excerpt_filled/$excerpt_total ({$excerpt_ratio}%), need >= {$excerpt_ratio_min}%",
		$checks, $passed, $failed
	);
}

$distinct_days = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT DATE(post_date)) FROM {$wpdb->posts}
	 WHERE post_status='publish'
	   AND post_type IN ($type_sql)"
);
$publish_days_min = '' !== $project_plugin
	? (int) e2e_plugin_seed_check_number( 'publish_days_min', 2.0 )
	: 20;
if ( e2e_is_core_content_project() && e2e_is_core_only() ) {
	$publish_days_min = 1;
}
e2e_check(
	'Publish date distribution',
	$distinct_days >= $publish_days_min,
	"found $distinct_days distinct publish days, need >= $publish_days_min",
	$checks, $passed, $failed
);

$image_attachments = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts}
	 WHERE post_type='attachment'
	   AND post_status='inherit'
	   AND post_mime_type LIKE 'image/%'"
);
$image_alt = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
	 WHERE p.post_type='attachment'
	   AND p.post_status='inherit'
	   AND p.post_mime_type LIKE 'image/%'
	   AND pm.meta_key = '_wp_attachment_image_alt'
	   AND pm.meta_value <> ''"
);
$image_alt_ratio = $image_attachments > 0 ? round( ( $image_alt / $image_attachments ) * 100, 1 ) : 0;
if ( '' !== $project_plugin && ! e2e_plugin_seed_check_bool( 'image_alt_gate', 'wptsall' === $project_plugin ) ) {
	echo '  ' . e2e_seed_project_label() . ": image attachment ALT hard gate skipped in Stage 3.\n";
	++$skipped;
} elseif ( e2e_is_core_content_project() && e2e_is_core_only() ) {
	if ( $image_attachments === 0 ) {
		echo "  core-content (core-only): image attachment ALT gate skipped (no image attachments).\n";
		++$skipped;
	} else {
		e2e_check(
			'Image attachment ALT coverage',
			true,
			"found $image_alt/$image_attachments ({$image_alt_ratio}%), core-only: informational only",
			$checks, $passed, $failed
		);
	}
} else {
	e2e_check(
		'Image attachment ALT coverage',
		$image_attachments >= 10 && $image_alt_ratio >= 90.0,
		"found $image_alt/$image_attachments ({$image_alt_ratio}%), need >= 90% and >=10 images",
		$checks, $passed, $failed
	);
}

// ---------------------------------------------------------------------------
// 额外检查：用户与评论真实行为夹具
// ---------------------------------------------------------------------------
echo "\n--- User & Comment Activity ---\n";
e2e_verify_mark( 'realism-usability' );

$fixture_users_min = '' !== $project_plugin
	? (int) e2e_plugin_seed_check_number( 'fixture_users_min', 'wptsall' === $project_plugin ? 6.0 : 3.0 )
	: 6;
$fixture_users = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->users}
	 WHERE user_login LIKE 'e2e\_%'"
);
e2e_check(
	'Fixture users',
	$fixture_users >= $fixture_users_min,
	"found $fixture_users, need >= $fixture_users_min",
	$checks, $passed, $failed
);

$fixture_roles_min = '' !== $project_plugin
	? (int) e2e_plugin_seed_check_number( 'fixture_roles_min', 'wptsall' === $project_plugin ? 3.0 : 2.0 )
	: 3;
$fixture_roles = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->usermeta}
	 WHERE meta_key = '{$wpdb->prefix}capabilities'
	   AND user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'e2e\\_%')"
);
e2e_check(
	'Fixture user role diversity',
	$fixture_roles >= $fixture_roles_min,
	"found $fixture_roles distinct capability blobs, need >= $fixture_roles_min",
	$checks, $passed, $failed
);

$fixture_comments = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->comments}
	 WHERE comment_content LIKE '%E2E-USER-FIXTURE:%'"
);
$fixture_comments_min = ( e2e_is_core_content_project() && e2e_is_core_only() ) ? 3 : 12;
if ( '' !== $project_plugin ) {
	$fixture_comments_min = (int) e2e_plugin_seed_check_number(
		'fixture_comments_min',
		'wptsall' === $project_plugin ? 12.0 : 3.0
	);
}
e2e_check(
	'Fixture comments',
	$fixture_comments >= $fixture_comments_min,
	"found $fixture_comments, need >= $fixture_comments_min",
	$checks, $passed, $failed
);

$fixture_reviews = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->comments}
	 WHERE comment_type='review'
	   AND comment_content LIKE '%E2E-USER-FIXTURE:%'"
);
$fixture_reviews_min = '' !== $project_plugin
	? (int) e2e_plugin_seed_check_number( 'woo_review_min', 'wptsall' === $project_plugin ? 3.0 : 1.0 )
	: 3;
if ( '' !== $project_plugin && ! e2e_plugin_seed_check_bool( 'woo_review_gate', false ) ) {
	echo '  ' . e2e_seed_project_label() . ": Woo review fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_learning_content_project() ) {
	echo "  learning-content: Woo review fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_media_builder_content_project() ) {
	echo "  media-builder-content: Woo review fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_community_content_project() ) {
	echo "  community-content: Woo review fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_listings_events_content_project() ) {
	echo "  listings-events-content: Woo review fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_content_meta_content_project() ) {
	echo "  content-meta-content: Woo review fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_core_content_project() ) {
	echo "  core-content: Woo review fixture gate skipped.\n";
	++$skipped;
} else {
	e2e_check(
		'Fixture Woo reviews',
		$fixture_reviews >= $fixture_reviews_min,
		"found $fixture_reviews, need >= $fixture_reviews_min",
		$checks, $passed, $failed
	);
}

$fixture_bbp_replies = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts}
	 WHERE post_type='reply'
	   AND post_content LIKE '%E2E-USER-FIXTURE:%'"
);
$fixture_bbp_replies_min = '' !== $project_plugin
	? (int) e2e_plugin_seed_check_number( 'bbpress_reply_min', 'wptsall' === $project_plugin ? 2.0 : 1.0 )
	: 2;
if ( '' !== $project_plugin && ! e2e_plugin_seed_check_bool( 'bbpress_reply_gate', false ) ) {
	echo '  ' . e2e_seed_project_label() . ": bbPress reply fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_learning_content_project() ) {
	echo "  learning-content: bbPress reply fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_commerce_content_project() ) {
	echo "  commerce-content: bbPress reply fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_media_builder_content_project() ) {
	echo "  media-builder-content: bbPress reply fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_listings_events_content_project() ) {
	echo "  listings-events-content: bbPress reply fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_content_meta_content_project() ) {
	echo "  content-meta-content: bbPress reply fixture gate skipped.\n";
	++$skipped;
} elseif ( e2e_is_core_content_project() ) {
	echo "  core-content: bbPress reply fixture gate skipped.\n";
	++$skipped;
} else {
	e2e_check(
		'Fixture bbPress replies',
		$fixture_bbp_replies >= $fixture_bbp_replies_min,
		"found $fixture_bbp_replies, need >= $fixture_bbp_replies_min",
		$checks, $passed, $failed
	);
}

// ---------------------------------------------------------------------------
// 结果
// ---------------------------------------------------------------------------
e2e_verify_mark( 'user-comment-activity' );
e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	echo "\nSome seeding checks failed. Run seeding plans to fill data.\n";
	exit( 1 );
}
