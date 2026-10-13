<?php
/**
 * S1 lifecycle lane provisioning (idempotent base; scenarios below).
 *
 * Base (idempotent): zh_CN language → virtual site v_s1_life → en_US→zh_CN
 * relation (model wordpress-blog) → post translation rule
 * (title plain_text / content rich_html / excerpt plain_text).
 *
 * Scenario 1.1 (default): publish ONE Gutenberg-rich source post with
 *   - block comments carrying JSON attributes (fontSize/typography/level),
 *   - inline <strong> + <a href> markup,
 *   - one category + one tag (for the taxonomy cascade assertion).
 *
 * Scenario 1.2 (mode=update&post_id=N): update that source post — new title
 *   plus one appended paragraph block — to exercise incremental translation
 *   (must update the SAME target post, never duplicate).
 *
 * Emits one JSON line:
 *   {"relation_id":N,"rule_id":N,"model_id":N,"post_id":N,"cat_id":N,
 *    "tag_id":N,"virtual_site_id":"v_s1_life","post_title":"..."}
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$stamp = gmdate( 'Ymd-His' );
$mode  = 'create';
$post_id_arg = 0;
if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw ) {
		$raw = trim( (string) $raw );
		if ( 'mode=update' === $raw ) {
			$mode = 'update';
		}
		if ( 0 === strpos( $raw, 'post_id=' ) ) {
			$post_id_arg = (int) substr( $raw, strlen( 'post_id=' ) );
		}
	}
}

/**
 * Ensure the zh_CN language row exists.
 */
$lang_table = $wpdb->prefix . 'wptsall_languages';
$has_zh     = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$lang_table} WHERE code = %s", 'zh_CN' )
);
if ( 0 === $has_zh ) {
	$wpdb->insert(
		$lang_table,
		array(
			'code'        => 'zh_CN',
			'slug'        => 'zh',
			'name'        => '简体中文',
			'native_name' => '简体中文',
			'locale'      => 'zh_CN',
			'flag'        => 'cn',
			'direction'   => 'ltr',
			'sort_order'  => 10,
			'is_default'  => 0,
			'status'      => 'active',
		)
	);
	echo "provision: created zh_CN language row\n";
} else {
	echo "provision: zh_CN language row present\n";
}

/**
 * Ensure the virtual target site (dedicated to the S1 lifecycle lane).
 */
$vs_table     = $wpdb->prefix . 'wptsall_virtual_sites';
$virtual_id   = 'v_s1_life';
$virtual_path = '/zh-s1-life/';
$has_virtual  = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$vs_table} WHERE site_path = %s", $virtual_path )
);
if ( 0 === $has_virtual ) {
	$wpdb->insert(
		$vs_table,
		array(
			'site_name'        => 'S1 Lifecycle 站',
			'site_path'        => $virtual_path,
			'site_language'    => 'zh_CN',
			'source_blog_id'   => 1,
			'enable_blog_sync' => 1,
			'status'           => 'enabled',
		)
	);
	echo "provision: created virtual site {$virtual_id}\n";
} else {
	echo "provision: virtual site {$virtual_id} present\n";
}

/**
 * Ensure the wordpress-blog model + relation + rule (same contract as the
 * roundtrip lane, but with content as rich_html and its own relation).
 */
$models_table = $wpdb->prefix . 'wptsall_models';
$model_id     = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$models_table} WHERE plugin_slug = %s LIMIT 1", 'wordpress-blog' )
);
if ( $model_id <= 0 ) {
	$wpdb->insert(
		$models_table,
		array(
			'plugin_slug'    => 'wordpress-blog',
			'plugin_name'    => 'WordPress Blog',
			'text_domain'    => 'default',
			'description'    => 'WordPress Core blog functionality',
			'post_types'     => wp_json_encode( array( 'post', 'page' ) ),
			'taxonomies'     => wp_json_encode( array( 'category', 'post_tag' ) ),
			'status'         => 'active',
			'source_type'    => 'auto',
			'is_system'      => 0,
		)
	);
	$model_id = (int) $wpdb->insert_id;
	echo "provision: created wordpress-blog model #{$model_id}\n";
} else {
	echo "provision: wordpress-blog model #{$model_id} present\n";
}

$rel_table = $wpdb->prefix . 'wptsall_site_relations';
$rel_id    = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$rel_table} WHERE target_site_id = %s AND source_lang = %s AND target_lang = %s LIMIT 1", $virtual_id, 'en_US', 'zh_CN' )
);
if ( $rel_id <= 0 ) {
	$wpdb->insert(
		$rel_table,
		array(
			'source_site_id'    => 1,
			'source_site_type'  => 'wp',
			'source_lang'       => 'en_US',
			'source_theme_name' => 'Twenty Twenty-Five',
			'source_theme_path' => 'twentytwentyfive',
			'template'          => 'wordpress-blog',
			'target_site_id'    => $virtual_id,
			'target_site_type'  => 'virtual',
			'target_lang'       => 'zh_CN',
			'target_theme_name' => 'S1 Lifecycle 站',
			'target_theme_path' => 'zh-s1-life',
			'media_handling'    => 'copy',
			'sync_mode'         => 'new_only',
			'direction'         => 'source_to_target',
			'conflict_strategy' => 'source_wins',
			'status'            => 'active',
		)
	);
	$rel_id = (int) $wpdb->insert_id;
	echo "provision: created relation #{$rel_id}\n";
} else {
	echo "provision: relation #{$rel_id} present\n";
}

$rm_table = $wpdb->prefix . 'wptsall_relation_models';
$has_rm   = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$rm_table} WHERE relation_id = %d AND model_id = %d", $rel_id, $model_id )
);
if ( 0 === $has_rm ) {
	$wpdb->insert(
		$rm_table,
		array(
			'relation_id' => $rel_id,
			'model_id'    => $model_id,
		)
	);
	echo "provision: attached model #{$model_id} to relation #{$rel_id}\n";
} else {
	echo "provision: relation-model link present\n";
}

$rule_id = 0;
if ( class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
	$rules_table = $wpdb->prefix . 'wptsall_translation_rules';
	$rule_id     = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM {$rules_table} WHERE model_id = %d AND data_type = %s AND object_name = %s LIMIT 1", $model_id, 'post', 'post' )
	);
	if ( $rule_id <= 0 ) {
		$result = WPTSALL\Models\Services\Translation_Rule_Service::create_rule(
			$model_id,
			array(
				'name'          => 'Client S1 Lifecycle Post Rule',
				'url_pattern'   => '?post_type=post&p={id}',
				'url_type'      => 'single',
				'data_type'     => 'post',
				'object_name'   => 'post',
				'priority'      => 10,
				'is_active'     => true,
				'field_capabilities' => array(
					'post_title'   => array(
						'type'           => 'translate',
						'enabled'        => true,
						'content_format' => 'plain_text',
						'storage'        => 'post_column',
					),
					'post_content' => array(
						'type'           => 'translate',
						'enabled'        => true,
						'content_format' => 'rich_html',
						'storage'        => 'post_column',
					),
					'post_excerpt' => array(
						'type'           => 'translate',
						'enabled'        => true,
						'content_format' => 'plain_text',
						'storage'        => 'post_column',
					),
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			echo "provision: create_rule failed: " . $result->get_error_message() . "\n";
			exit( 1 );
		}
		$rule_id = is_array( $result ) ? (int) ( $result['id'] ?? 0 ) : (int) $result;
		echo "provision: created post translation rule #{$rule_id}\n";
	} else {
		echo "provision: post translation rule #{$rule_id} present\n";
	}
} else {
	echo "provision: Translation_Rule_Service unavailable\n";
	exit( 1 );
}

/**
 * Scenario 1.2: update the existing source post (new title + appended block).
 */
if ( 'update' === $mode ) {
	if ( $post_id_arg <= 0 ) {
		echo "provision: mode=update requires post_id=N\n";
		exit( 1 );
	}
	$existing = get_post( $post_id_arg );
	if ( ! $existing ) {
		echo "provision: source post #{$post_id_arg} not found\n";
		exit( 1 );
	}
	$new_title   = 'S1 Lifecycle Updated ' . $stamp;
	$append      = "\n<!-- wp:paragraph -->\n<p>Appended lifecycle paragraph after the incremental update.</p>\n<!-- /wp:paragraph -->\n";
	$updated     = wp_update_post(
		array(
			'ID'           => $post_id_arg,
			'post_title'   => $new_title,
			'post_content' => $existing->post_content . $append,
		),
		true
	);
	if ( is_wp_error( $updated ) ) {
		echo "provision: wp_update_post failed: " . $updated->get_error_message() . "\n";
		exit( 1 );
	}
	echo "provision: updated source post #{$post_id_arg} (incremental)\n";
	wp_send_json_success(
		array(
			'relation_id'     => $rel_id,
			'rule_id'         => $rule_id,
			'model_id'        => $model_id,
			'post_id'         => $post_id_arg,
			'virtual_site_id' => $virtual_id,
			'post_title'      => $new_title,
			'mode'            => 'update',
		)
	);
	return;
}

/**
 * Scenario 1.1: publish a Gutenberg-rich source post with a category and a tag.
 *
 * Scenario 1.3 (implicit): the taxonomy names carry the run stamp, so EVERY
 * run provisions fresh terms. Fresh term_created events exercise the term
 * writeback path end-to-end each round: virtual mapping registration with a
 * real target_lang (P-1), the placeholder-fallback lookup (P-2), and the
 * post-backfill reconciliation (P-3).
 */
$cat_name = 'S1 Life Cat ' . $stamp;
$tag_name = 'S1 Life Tag ' . $stamp;
$cat_id   = 0;
$tag_id   = 0;

$cat = wp_insert_term( $cat_name, 'category' );
if ( is_wp_error( $cat ) && 'term_exists' !== $cat->get_error_code() ) {
	echo "provision: wp_insert_term(category) failed: " . $cat->get_error_message() . "\n";
	exit( 1 );
}
$cat_id = is_wp_error( $cat ) ? (int) $cat->get_error_data( 'term_exists' ) : (int) $cat['term_id'];
echo "provision: category {$cat_name} #{$cat_id}\n";

$tag = wp_insert_term( $tag_name, 'post_tag' );
if ( is_wp_error( $tag ) && 'term_exists' !== $tag->get_error_code() ) {
	echo "provision: wp_insert_term(post_tag) failed: " . $tag->get_error_message() . "\n";
	exit( 1 );
}
$tag_id = is_wp_error( $tag ) ? (int) $tag->get_error_data( 'term_exists' ) : (int) $tag['term_id'];
echo "provision: tag {$tag_name} #{$tag_id}\n";

$post_title = 'S1 Lifecycle Gutenberg Post ' . $stamp;
$excerpt    = 'S1 lifecycle excerpt for the round-trip lane.';
$content    = <<<HTML
<!-- wp:paragraph {"fontSize":"large","style":{"typography":{"lineHeight":"1.5"}}} -->
<p>Welcome to <strong>WPTSALL</strong>! Visit <a href="https://example.com/test?a=1&amp;b=2">our link</a> for details.</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2} -->
<h2>Section Header</h2>
<!-- /wp:heading -->
<!-- wp:quote {"className":"is-style-large"} -->
<blockquote class="wp-block-quote"><p>Quote content stays intact.</p></blockquote>
<!-- /wp:quote -->
HTML;

$post_id = wp_insert_post(
	array(
		'post_title'    => $post_title,
		'post_content'  => $content,
		'post_excerpt'  => $excerpt,
		'post_status'   => 'publish',
		'post_type'     => 'post',
		'post_author'   => 1,
		'post_category' => array( $cat_id ),
		'tags_input'    => array( $tag_id ),
	),
	true
);
if ( is_wp_error( $post_id ) ) {
	echo "provision: wp_insert_post failed: " . $post_id->get_error_message() . "\n";
	exit( 1 );
}
echo "provision: published Gutenberg source post #{$post_id} (cat #{$cat_id}, tag #{$tag_id})\n";

wp_send_json_success(
	array(
		'relation_id'     => $rel_id,
		'rule_id'         => $rule_id,
		'model_id'        => $model_id,
		'post_id'         => (int) $post_id,
		'cat_id'          => $cat_id,
		'tag_id'          => $tag_id,
		'virtual_site_id' => $virtual_id,
		'post_title'      => $post_title,
		'mode'            => 'create',
	)
);
