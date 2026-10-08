<?php
/**
 * Client content round-trip provisioning.
 *
 * Provisions the WP side for the client ↔ WP translation round-trip lane:
 *   zh_CN language row → fresh virtual site + en_US→zh_CN relation
 *   (model: wordpress-blog) → post translation rule (post_title/post_content/
 *   post_excerpt translate) → one freshly published source post carrying a
 *   real image attachment (media leg).
 *
 * The language row and wordpress-blog model are idempotent (shared across
 * runs); the virtual site, relation, rule, post and attachment are FRESH per
 * run (see the virtual-site comment below for why the relation must not be
 * reused).
 *
 * Emits a single JSON line with the ids the orchestrator needs:
 *   {"relation_id":N,"rule_id":N,"post_id":N,"virtual_site_id":"v_...",
 *    "post_title":"...","media_attachment_id":N,"media_source_url":"...",
 *    "media_translated_probe_url":"..."}
 *
 * Usage:
 *   docker exec <lab> wp eval-file <this file> --allow-root
 *
 * sync_mode=new_only only translates content published after the relation
 * and rule exist — the post is always created after both in this script.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$stamp = gmdate( 'Ymd-His' );

/**
 * Ensure a FRESH virtual target site + relation for THIS run.
 *
 * The virtual site id is stamped per run: the relation's discovery claim
 * window then contains only the post published below. A fixed id would
 * accumulate one claimable post per lane run under the same relation; the
 * claim state does not persist across worker run-once calls, so every call
 * would restart from the oldest item and the newest post would sit behind a
 * growing backlog that the lane's bounded worker loop can never drain.
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

$vs_table      = $wpdb->prefix . 'wptsall_virtual_sites';
$virtual_id    = 'v_client_rt_' . strtolower( $stamp );
$has_virtual   = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$vs_table} WHERE id = %s", $virtual_id )
);
if ( 0 === $has_virtual ) {
	$wpdb->insert(
		$vs_table,
		array(
			'id'               => $virtual_id,
			'site_name'        => 'Client Roundtrip 站 ' . $stamp,
			'site_path'        => '/zh-rt-' . strtolower( $stamp ) . '/',
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
 * Ensure the wordpress-blog model exists (usually created by the setup wizard
 * or the plugin initialization scan).
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

/**
 * Ensure the en_US → zh_CN relation with the model attached.
 */
$rel_table = $wpdb->prefix . 'wptsall_site_relations';
$rel_id    = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$rel_table} WHERE target_site_id = %s AND source_lang = %s AND target_lang = %s LIMIT 1", $virtual_id, 'en_US', 'zh_CN' )
);
if ( $rel_id <= 0 ) {
	$wpdb->insert(
		$rel_table,
		array(
			'source_site_id'   => 1,
			'source_site_type' => 'wp',
			'source_lang'      => 'en_US',
			'source_theme_name' => 'Twenty Twenty-Five',
			'source_theme_path' => 'twentytwentyfive',
			'template'         => 'wordpress-blog',
			'target_site_id'   => $virtual_id,
			'target_site_type' => 'virtual',
			'target_lang'      => 'zh_CN',
			'target_theme_name' => 'Client Roundtrip 站',
			'target_theme_path' => 'zh-rt',
			'media_handling'   => 'copy',
			'sync_mode'        => 'new_only',
			'direction'        => 'source_to_target',
			'conflict_strategy' => 'source_wins',
			'status'           => 'active',
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

/**
 * Ensure the post translation rule on the model (mirrors the admin UI
 * "Translation Rules → Add Rule" contract: models/{id}/rules REST payload).
 */
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
				'name'        => 'Client Roundtrip Post Rule',
				'url_pattern' => '?post_type=post&p={id}',
				'url_type'    => 'single',
				'data_type'   => 'post',
				'object_name' => 'post',
				'priority'    => 10,
				'is_active'   => true,
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
 * Publish a fresh source post (after relation + rule exist).
 *
 * MEDIA LEG: the post carries a real image attachment. The discovery scan
 * (wptsall_collect_media_subtasks_from_complete_data) extracts the attachment
 * URL out of post_content and emits a media_ref subtask for it, so the client
 * pipeline exercises the full media asset lane (media-capable component →
 * translated asset → /media-upload back to WP → media_mappings + URL rewrite).
 *
 * The mock /api/v1/translate/image answers a non-empty source_ref with the
 * SAME-ORIGIN filename renamed with a "-zh_CN" suffix (translate_media_ref).
 * WordPress sanitizes filenames to lowercase, so the counterpart cannot be
 * sideloaded through the media library — it is seeded as a raw file next to
 * the source upload (same pattern as the sim lane's seedMediaFile), keeping
 * the mock's renamed-URL answer genuinely fetchable.
 */
$media_png_b64   = 'iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAIAAABvFaqvAAADnUlEQVR4nBXUqxpFERSF0Z1lWZZlWZZlWZZlWZblP8uyLHuO43iA8Vm3+X0f4kN+qA/9YT7sh/vwH+EjfqSP/FE+6kf76B/jg4/5sT72x/m4H98nEAIpUAItMAIrcAIvCIIoSIIsKIIqaIIuGAIEU7AEW3AEVzxIIiRSoiRaYiRW4iReEiRRkiRZUiRV0iRdMiRIpmRJtuRIrnyQQiikQim0wiiswim8IiiiIimyoiiqoim6YihQTMVSbMVRXPUgjdBIjdJojdFYjdN4TdBETdJkTdFUTdN0zdCgmZql2ZqjufpBBmGQBmXQBmOwBmfwhmCIhmTIhmKohmbohmHAMA3LsA3HcM2DLMIiLcqiLcZiLc7iLcESLcmSLcVSLc3SLcOCZVqWZVuO5doHOYRDOpRDO4zDOpzDO4IjOpIjO4qjOpqjO4YDx3Qsx3Ycx3UP8giP9CiP9hiP9TiP9wRP9CRP9hRP9TRP9wwPnulZnu05nusfFBABGVABHTABG3ABHwiBGEiBHCiBGmiBHhgBAjOwAjtwAjc8KCIiMqIiOmIiNuIiPhIiMZIiOVIiNdIiPTIiRGZkRXbkRG58UEIkZEIldMIkbMIlfCIkYiIlcqIkaqIlemIkSMzESuzESdz0oIzIyIzK6IzJ2IzL+EzIxEzK5EzJ1EzL9MzIkJmZldmZk7n5QQVRkAVV0AVTsAVX8IVQiIVUyIVSqIVW6IVRoDALq7ALp3DLgyqiIiuqoiumYiuu4iuhEiupkiulUiut0iujQmVWVmVXTuXWBzVEQzZUQzdMwzZcwzdCIzZSIzdKozZaozdGg8ZsrMZunMZtD+qIjuyoju6Yju24ju+ETuykTu6UTu20Tu+MDp3ZWZ3dOZ3bHzQQAzlQAz0wAztwAz8IgzhIgzwogzpogz4YAwZzsAZ7cAZ3POgfUi9eXjC8k37H+M7oHcBb3bd0b13eoN+IXnNfW15B7ysP+b8JCzYcuC8yv4mYyIma6ImZ2Imb+EmYxEma5EmZ1Emb9MmYf2ZO1mRPzuTOBy3EQi7UQi/Mwi7cwi/CIi7SIi/Koi7aoi/G+n9mLtZiL87irgdtxEZu1EZvzMZu3MZvwiZu0iZvyqZu2qZvxv6XNDdrszdnc/eDDuIgD+qgD+ZgD+7gD+EQD+mQD+VQD+3QD+P8GzMP67AP53DPgy7iIi/qoi/mYi/u4i/hEi/pki/lUi/t0i/j/ts7L+uyL+dyLz+3RJquucgKKAAAAABJRU5ErkJggg==';
$media_bytes    = base64_decode( $media_png_b64 );
$media_name     = 'rt-media-' . $stamp . '.png';
$media_alt_text = 'Roundtrip lab media asset alt text';

$media_upload   = wp_upload_bits( $media_name, null, $media_bytes );
if ( ! empty( $media_upload['error'] ) ) {
	echo "provision: wp_upload_bits failed: {$media_upload['error']}\n";
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
$media_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'RT Media ' . $stamp,
		'post_content'   => '',
		'post_status'    => 'inherit',
	),
	$media_upload['file'],
	0
);
if ( is_wp_error( $media_id ) || $media_id <= 0 ) {
	echo "provision: wp_insert_attachment failed\n";
	exit( 1 );
}
wp_update_attachment_metadata( $media_id, wp_generate_attachment_metadata( (int) $media_id, $media_upload['file'] ) );
update_post_meta( (int) $media_id, '_wp_attachment_image_alt', $media_alt_text );

// Seed the mock's renamed-URL counterpart (see comment above).
$translated_probe_path = dirname( $media_upload['file'] ) . '/rt-media-' . $stamp . '-zh_CN.png';
if ( false === file_put_contents( $translated_probe_path, $media_bytes ) ) {
	echo "provision: seeding translated counterpart failed: {$translated_probe_path}\n";
	exit( 1 );
}
$media_source_url        = (string) $media_upload['url'];
$media_translated_probe = str_replace( 'rt-media-' . $stamp . '.png', 'rt-media-' . $stamp . '-zh_CN.png', $media_source_url );
echo "provision: media attachment #{$media_id} ({$media_source_url}) + seeded counterpart\n";

$post_title   = 'Client Roundtrip Post ' . $stamp;
$post_content = "<!-- wp:paragraph -->\n<p>This post was published after the relation and translation rule were provisioned. The client should discover it and translate it to Chinese.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$media_source_url}\" alt=\"{$media_alt_text}\"/></figure>\n<!-- /wp:image -->\n";
$post_id      = wp_insert_post(
	array(
		'post_title'   => $post_title,
		'post_content' => $post_content,
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_author'  => 1,
	),
	true
);
if ( is_wp_error( $post_id ) ) {
	echo "provision: wp_insert_post failed: " . $post_id->get_error_message() . "\n";
	exit( 1 );
}
echo "provision: published source post #{$post_id}\n";

/**
 * Activate the media field on the post: `_e2e_media_ref_image` is part of the
 * site's seeded field catalog (rule capability: translate / media_ref /
 * task_type image — see seed-baseline-fixtures.php), with the post meta value
 * carrying the source attachment ID. Without a value the field is skipped as
 * missing_in_complete_data and the media_ref group never routes to the media
 * component.
 *
 * The ID alone is not enough: the client's field-level media lane resolves the
 * request `source_ref` from the companion `<field>_url` meta key
 * (extract_media_reference_for_field) — an ID-only value leaves source_ref
 * empty, the request body then carries the literal `{{input.source_ref}}`
 * token (the mock echoes it back "translated") and the outcome is discarded by
 * ref normalization (non-text output path not found). The companion key is
 * plain data read from complete_data — it is not in the field catalog, so it
 * is never translated itself.
 */
update_post_meta( (int) $post_id, '_e2e_media_ref_image', (int) $media_id );
update_post_meta( (int) $post_id, '_e2e_media_ref_image_url', $media_source_url );
echo "provision: post #{$post_id} meta _e2e_media_ref_image = {$media_id} (+_url companion)\n";

wp_send_json_success(
	array(
		'relation_id'    => $rel_id,
		'rule_id'        => $rule_id,
		'model_id'       => $model_id,
		'post_id'        => (int) $post_id,
		'virtual_site_id' => $virtual_id,
		'post_title'     => $post_title,
		'media_attachment_id' => (int) $media_id,
		'media_source_url'    => $media_source_url,
		'media_translated_probe_url' => $media_translated_probe,
	)
);
