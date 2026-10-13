<?php
/**
 * E2E v2 seed baseline fixtures (ISS-00).
 *
 * Creates a deterministic baseline for:
 * - relation context (uses runtime/relation-ids.json)
 * - manual fields + rule sync (model_object_fields + translation_rules)
 * - optional language_pack scan artifacts (templates + template_entries)
 * - media reference fixture (attachment + post meta/content reference)
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/seed-baseline-fixtures.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Seed Baseline Fixtures ===\n\n";

$skip_language_pack = false;
$text_only_fixture  = false;
$run_id             = '';
$fixture_marker     = '';
$scenario_id        = 'wptsall-business-smoke';
$requirement_id     = 'REQ-WPTSALL-BUSINESS-SMOKE';
if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw_arg ) {
		$raw_arg = trim( (string) $raw_arg );
		$arg     = strtolower( $raw_arg );
		if ( '--skip-language-pack' === $arg || 'skip-language-pack' === $arg || 'skip-language-pack=1' === $arg ) {
			$skip_language_pack = true;
			continue;
		}
		if ( '--text-only' === $arg || 'text-only' === $arg || 'text-only=1' === $arg || 'skip-media-refs=1' === $arg ) {
			$text_only_fixture = true;
			continue;
		}
		if ( 0 === strpos( $raw_arg, 'run_id=' ) ) {
			$run_id = sanitize_text_field( substr( $raw_arg, strlen( 'run_id=' ) ) );
			continue;
		}
		if ( 0 === strpos( $raw_arg, 'fixture_marker=' ) ) {
			$fixture_marker = sanitize_text_field( substr( $raw_arg, strlen( 'fixture_marker=' ) ) );
			continue;
		}
		if ( 0 === strpos( $raw_arg, 'scenario_id=' ) ) {
			$scenario_id = sanitize_text_field( substr( $raw_arg, strlen( 'scenario_id=' ) ) );
			continue;
		}
		if ( 0 === strpos( $raw_arg, 'requirement_id=' ) ) {
			$requirement_id = sanitize_text_field( substr( $raw_arg, strlen( 'requirement_id=' ) ) );
		}
	}
}
if ( '' === $fixture_marker && '' !== $run_id ) {
	$fixture_marker = 'wptsall-business-' . $run_id;
}

/**
 * Exit with an error message.
 *
 * @param string $message Error detail.
 * @return void
 */
function e2e_fixture_fail( $message ) {
	echo "ERROR: {$message}\n";
	exit( 1 );
}

/**
 * Media fixture specs keyed by semantic type.
 *
 * @return array<string,array<string,string>>
 */
function e2e_fixture_media_specs() {
	return array(
		'image'    => array(
			'meta_key'  => '_wptsall_e2e_fixture_media_image',
			'filename'  => 'wptsall-e2e-fixture-image.png',
			'mime'      => 'image/png',
			'title'     => 'WPTSALL E2E Fixture Media Image',
			'payload'   => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO9n8xQAAAAASUVORK5CYII=',
			'encoding'  => 'base64',
			'is_image'  => '1',
		),
		'video'    => array(
			'meta_key'  => '_wptsall_e2e_fixture_media_video',
			'filename'  => 'wptsall-e2e-fixture-video.mp4',
			'mime'      => 'video/mp4',
			'title'     => 'WPTSALL E2E Fixture Media Video',
			'payload'   => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom",
			'encoding'  => 'raw',
			'is_image'  => '0',
		),
		'audio'    => array(
			'meta_key'  => '_wptsall_e2e_fixture_media_audio',
			'filename'  => 'wptsall-e2e-fixture-audio.mp3',
			'mime'      => 'audio/mpeg',
			'title'     => 'WPTSALL E2E Fixture Media Audio',
			'payload'   => "ID3\x04\x00\x00\x00\x00\x00\x00E2E-FIXTURE-AUDIO",
			'encoding'  => 'raw',
			'is_image'  => '0',
		),
		'document' => array(
			'meta_key'  => '_wptsall_e2e_fixture_media_document',
			'filename'  => 'wptsall-e2e-fixture-document.pdf',
			'mime'      => 'application/pdf',
			'title'     => 'WPTSALL E2E Fixture Media Document',
			'payload'   => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
			'encoding'  => 'raw',
			'is_image'  => '0',
		),
	);
}

/**
 * Build binary payload by spec.
 *
 * @param array<string,string> $spec Media spec.
 * @return string
 */
function e2e_fixture_media_bytes( array $spec ) {
	$encoding = (string) ( $spec['encoding'] ?? 'raw' );
	$payload  = (string) ( $spec['payload'] ?? '' );

	if ( 'base64' === $encoding ) {
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			e2e_fixture_fail( 'Unable to decode fixture bytes for ' . ( $spec['filename'] ?? 'unknown' ) );
		}
		return $decoded;
	}

	return $payload;
}

/**
 * Build or reuse deterministic fixtures for image/video/audio/document.
 *
 * @return array<string,array<string,mixed>>
 */
function e2e_ensure_fixture_media_set() {
	global $wpdb;

	$fixtures = array();
	$specs    = e2e_fixture_media_specs();

	foreach ( $specs as $type => $spec ) {
		$meta_key    = (string) ( $spec['meta_key'] ?? '' );
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id DESC LIMIT 1",
				$meta_key
			)
		);

		if ( $existing_id > 0 ) {
			$post = get_post( $existing_id );
			if ( $post && 'attachment' === $post->post_type ) {
				$url = wp_get_attachment_url( $existing_id );
				if ( ! empty( $url ) ) {
					$fixtures[ $type ] = array(
						'id'   => $existing_id,
						'url'  => (string) $url,
						'mime' => (string) get_post_mime_type( $existing_id ),
					);
					continue;
				}
			}
		}

		$upload = wp_upload_bits(
			(string) $spec['filename'],
			null,
			e2e_fixture_media_bytes( $spec )
		);
		if ( ! empty( $upload['error'] ) ) {
			e2e_fixture_fail( 'wp_upload_bits failed for ' . $type . ': ' . $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => (string) $spec['title'],
				'post_mime_type' => (string) $spec['mime'],
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) || (int) $attachment_id <= 0 ) {
			$msg = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'unknown error';
			e2e_fixture_fail( 'wp_insert_attachment failed for ' . $type . ': ' . $msg );
		}

		if ( '1' === (string) ( $spec['is_image'] ?? '0' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( (int) $attachment_id, $metadata );
			}
		}

		update_post_meta( (int) $attachment_id, $meta_key, '1' );
		$url = wp_get_attachment_url( (int) $attachment_id );
		if ( empty( $url ) ) {
			e2e_fixture_fail( 'Unable to resolve fixture media URL for ' . $type . '.' );
		}

		$fixtures[ $type ] = array(
			'id'   => (int) $attachment_id,
			'url'  => (string) $url,
			'mime' => (string) $spec['mime'],
		);
	}

	return $fixtures;
}

/**
 * Build or reuse a dedicated fixture source post.
 *
 * @param array<string,array<string,mixed>> $media_set Fixture media map by type.
 * @param array<string,string>              $run_scope Run-scoped marker metadata.
 * @param bool                              $text_only When true, skip media embeds/refs for text-only smoke.
 * @return int Post ID.
 */
function e2e_ensure_fixture_post( array $media_set, array $run_scope = array(), $text_only = false ) {
	global $wpdb;

	$fixture_marker = sanitize_text_field( (string) ( $run_scope['fixture_marker'] ?? '' ) );
	$run_id         = sanitize_text_field( (string) ( $run_scope['run_id'] ?? '' ) );
	$scenario_id    = sanitize_text_field( (string) ( $run_scope['scenario_id'] ?? '' ) );
	$requirement_id = sanitize_text_field( (string) ( $run_scope['requirement_id'] ?? '' ) );

	$meta_key   = '_wptsall_e2e_fixture_post';
	$lookup_key = '' !== $fixture_marker ? '_wptsall_e2e_fixture_marker' : $meta_key;
	$lookup_val = '' !== $fixture_marker ? $fixture_marker : '1';
	$existing_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
			$lookup_key,
			$lookup_val
		)
	);

	$marker_suffix     = '' !== $fixture_marker ? ' Marker: ' . $fixture_marker . '.' : '';
	$post_title        = '' !== $fixture_marker ? 'WPTSALL E2E Baseline Fixture Post ' . $fixture_marker : 'WPTSALL E2E Baseline Fixture Post';
	$post_excerpt      = 'E2E baseline fixture excerpt.' . $marker_suffix;
	$manual_text       = 'E2E baseline manual text fixture for translation pipeline.' . $marker_suffix;
	$manual_json       = wp_json_encode(
		array(
			'title'  => 'E2E JSON fixture',
			'blocks' => array( 'alpha', 'beta', 'gamma' ),
			'meta'   => array(
				'caption' => 'Structured field for json_structured path',
				'marker'  => $fixture_marker,
			),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	$manual_serialized = maybe_serialize(
		array(
			'title'  => 'E2E serialized fixture',
			'items'  => array( 'one', 'two', 'three' ),
			'marker' => $fixture_marker,
		)
	);
	$manual_code       = "function e2e_fixture() { return 'baseline'; }\n.e2e-fixture { color: #123456; }";
	$manual_slug       = 'e2e-fixture-manual-slug';
	$image_id         = (int) ( $media_set['image']['id'] ?? 0 );
	$image_url        = (string) ( $media_set['image']['url'] ?? '' );
	$video_id         = (int) ( $media_set['video']['id'] ?? 0 );
	$video_url        = (string) ( $media_set['video']['url'] ?? '' );
	$audio_id         = (int) ( $media_set['audio']['id'] ?? 0 );
	$audio_url        = (string) ( $media_set['audio']['url'] ?? '' );
	$document_id      = (int) ( $media_set['document']['id'] ?? 0 );
	$document_url     = (string) ( $media_set['document']['url'] ?? '' );
	if ( $text_only ) {
		$content = '<p>E2E baseline fixture source content.' . esc_html( $marker_suffix ) . '</p>'
			. "\n" . '<p>Manual text: ' . esc_html( $manual_text ) . '</p>';
	} else {
		$document_link = $document_url ? '<a href="' . esc_url( $document_url ) . '">Fixture document</a>' : 'Fixture document missing';
		$video_block   = $video_url ? '<video controls src="' . esc_url( $video_url ) . '"></video>' : '<span>Video fixture missing</span>';
		$audio_block   = $audio_url ? '<audio controls src="' . esc_url( $audio_url ) . '"></audio>' : '<span>Audio fixture missing</span>';
		$content       = '<p>E2E baseline fixture source content.' . esc_html( $marker_suffix ) . '</p>'
			. "\n" . '<p><img src="' . esc_url( $image_url ) . '" alt="wptsall-e2e-fixture-image" /></p>'
			. "\n" . '<p>' . $video_block . '</p>'
			. "\n" . '<p>' . $audio_block . '</p>'
			. "\n" . '<p>' . $document_link . '</p>'
			. "\n" . '<p>Manual text: ' . esc_html( $manual_text ) . '</p>';
	}

	$post_id = 0;
	if ( $existing_id > 0 && get_post( $existing_id ) ) {
		$updated = $wpdb->update(
			$wpdb->posts,
			array(
				'post_status'       => 'publish',
				'post_title'        => $post_title,
				'post_excerpt'      => $post_excerpt,
				'post_content'      => $content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $existing_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			e2e_fixture_fail( 'Failed to update fixture post via SQL.' );
		}
		$post_id = (int) $existing_id;
	} else {
		$now_local = current_time( 'mysql' );
		$now_gmt   = current_time( 'mysql', true );
		$inserted  = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => 1,
				'post_date'             => $now_local,
				'post_date_gmt'         => $now_gmt,
				'post_content'          => $content,
				'post_title'            => $post_title,
				'post_excerpt'          => $post_excerpt,
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => '' !== $fixture_marker ? sanitize_title( 'wptsall-e2e-' . $fixture_marker ) : 'wptsall-e2e-baseline-fixture',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $now_local,
				'post_modified_gmt'     => $now_gmt,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => '',
				'menu_order'            => 0,
				'post_type'             => 'post',
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
			e2e_fixture_fail( 'Failed to insert fixture post via SQL.' );
		}
		$post_id = (int) $wpdb->insert_id;
		if ( $post_id <= 0 ) {
			e2e_fixture_fail( 'Inserted fixture post but missing insert ID.' );
		}
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

	update_post_meta( $post_id, $meta_key, '1' );
	if ( '' !== $fixture_marker ) {
		update_post_meta( $post_id, '_wptsall_e2e_fixture_marker', $fixture_marker );
	}
	if ( '' !== $run_id ) {
		update_post_meta( $post_id, '_wptsall_e2e_run_id', $run_id );
	}
	if ( '' !== $scenario_id ) {
		update_post_meta( $post_id, '_wptsall_e2e_scenario_id', $scenario_id );
	}
	if ( '' !== $requirement_id ) {
		update_post_meta( $post_id, '_wptsall_e2e_requirement_id', $requirement_id );
	}
	update_post_meta( $post_id, '_e2e_manual_text', $manual_text );
	if ( $text_only ) {
		delete_post_meta( $post_id, '_e2e_media_ref_id' );
		delete_post_meta( $post_id, '_e2e_media_ref_image' );
		delete_post_meta( $post_id, '_e2e_media_ref_video' );
		delete_post_meta( $post_id, '_e2e_media_ref_audio' );
		delete_post_meta( $post_id, '_e2e_media_ref_document' );
		delete_post_meta( $post_id, '_thumbnail_id' );
	} else {
		update_post_meta( $post_id, '_e2e_media_ref_id', $image_id );
		update_post_meta( $post_id, '_e2e_media_ref_image', $image_id );
		update_post_meta( $post_id, '_e2e_media_ref_video', $video_id );
		update_post_meta( $post_id, '_e2e_media_ref_audio', $audio_id );
		update_post_meta( $post_id, '_e2e_media_ref_document', $document_id );
		update_post_meta( $post_id, '_thumbnail_id', $image_id );
	}
	update_post_meta( $post_id, '_e2e_manual_json', (string) $manual_json );
	update_post_meta( $post_id, '_e2e_manual_serialized', (string) $manual_serialized );
	update_post_meta( $post_id, '_e2e_manual_code', (string) $manual_code );
	update_post_meta( $post_id, '_e2e_manual_slug', (string) $manual_slug );
	update_post_meta( $post_id, '_e2e_manual_skip', 'do-not-translate' );
	update_post_meta( $post_id, '_e2e_fixture_updated_at', gmdate( 'c' ) );

	return $post_id;
}

/**
 * Convert [ [source_type=>x, cnt=>n], ... ] to map.
 *
 * @param array $rows DB rows.
 * @return array
 */
function e2e_rows_to_count_map( $rows ) {
	$map = array();
	foreach ( (array) $rows as $row ) {
		$key = (string) ( $row['source_type'] ?? '' );
		if ( '' === $key ) {
			continue;
		}
		$map[ $key ] = (int) ( $row['cnt'] ?? 0 );
	}
	ksort( $map );
	return $map;
}

$relation_ids = e2e_load_relation_ids();
if ( empty( $relation_ids ) ) {
	e2e_fixture_fail( 'No relation IDs found. Run setup-relations.php first.' );
}

$virtual_relation_id = (int) ( $relation_ids['virtual'] ?? 0 );
if ( $virtual_relation_id <= 0 ) {
	e2e_fixture_fail( 'Virtual relation ID missing in runtime/relation-ids.json.' );
}

$required_tables = array(
	e2e_table( 'models' ),
	e2e_table( 'model_objects' ),
	e2e_table( 'model_object_fields' ),
	e2e_table( 'translation_rules' ),
	e2e_table( 'templates' ),
	e2e_table( 'template_entries' ),
);
foreach ( $required_tables as $table ) {
	if ( ! e2e_table_exists( $table ) ) {
		e2e_fixture_fail( "Required table missing: {$table}" );
	}
}

echo "--- Step 1: Media + source post fixture ---\n";
$media_set = e2e_ensure_fixture_media_set();
$run_scope = array(
	'run_id'         => $run_id,
	'scenario_id'    => $scenario_id,
	'requirement_id' => $requirement_id,
	'fixture_marker' => $fixture_marker,
);
$post_id   = e2e_ensure_fixture_post( $media_set, $run_scope, $text_only_fixture );
if ( $text_only_fixture ) {
	echo "  Fixture mode:                  text-only (skip media refs)\n";
} else {
	foreach ( array( 'image', 'video', 'audio', 'document' ) as $media_type ) {
		$media_id = (int) ( $media_set[ $media_type ]['id'] ?? 0 );
		echo "  Fixture media ({$media_type}) ID: {$media_id}\n";
	}
}
echo "  Fixture post ID:               {$post_id}\n";
if ( '' !== $fixture_marker ) {
	echo "  Fixture marker:                {$fixture_marker}\n";
}

echo "\n--- Step 2: Manual fields + rule sync fixture ---\n";
$models_table = e2e_table( 'models' );
$model_row    = $wpdb->get_row(
	"SELECT id, plugin_slug FROM {$models_table}
	 WHERE status = 'active'
	 ORDER BY CASE WHEN plugin_slug = 'wordpress-blog' THEN 0 ELSE 1 END, id ASC
	 LIMIT 1",
	ARRAY_A
);
if ( empty( $model_row['id'] ) ) {
	e2e_fixture_fail( 'No active model found for baseline fixture.' );
}

$model_id      = (int) $model_row['id'];
$plugin_slug   = (string) ( $model_row['plugin_slug'] ?? '' );
$objects_table = e2e_table( 'model_objects' );
$object_row    = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, object_name FROM {$objects_table}
		 WHERE model_id = %d AND object_type = 'post_type'
		 ORDER BY CASE WHEN object_name = 'post' THEN 0 ELSE 1 END, id ASC
		 LIMIT 1",
		$model_id
	),
	ARRAY_A
);
if ( empty( $object_row['id'] ) ) {
	e2e_fixture_fail( "No post_type object found for model_id={$model_id}." );
}

$rules_table = e2e_table( 'translation_rules' );
$rule_row    = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, object_name, field_capabilities FROM {$rules_table}
		 WHERE model_id = %d AND data_type = 'post'
		 ORDER BY CASE WHEN object_name = 'post' THEN 0 ELSE 1 END, id ASC
		 LIMIT 1",
		$model_id
	),
	ARRAY_A
);
if ( empty( $rule_row['id'] ) ) {
	e2e_fixture_fail( "No post translation rule found for model_id={$model_id}." );
}

// Keep fixture object aligned with the selected rule target object.
// Older environments may have both post/page objects and pick different rows by default.
$rule_object_name = sanitize_key( (string) ( $rule_row['object_name'] ?? '' ) );
if ( '' !== $rule_object_name && (string) ( $object_row['object_name'] ?? '' ) !== $rule_object_name ) {
	$matched_object = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, object_name FROM {$objects_table}
			 WHERE model_id = %d AND object_type = 'post_type' AND object_name = %s
			 LIMIT 1",
			$model_id,
			$rule_object_name
		),
		ARRAY_A
	);
	if ( ! empty( $matched_object['id'] ) ) {
		$object_row = $matched_object;
	}
}

// Prefer already-loaded plugin classes; fall back to active install path.
$wptsall_includes = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wptsall/' ) . 'includes/';
require_once $wptsall_includes . 'models/services/class-model-object-service.php';
require_once $wptsall_includes . 'models/services/class-field-sync-service.php';
require_once $wptsall_includes . 'models/services/class-translation-rule-service.php';

$object_id       = (int) $object_row['id'];
$rule_id         = (int) $rule_row['id'];
$postmeta_table  = $wpdb->postmeta;

// Baseline fixture must be deterministic for ITS OWN fields, but must not
// destroy the shared rule: the previous blanket purge wiped scanner-declared
// core post fields (post_title/post_content/…) from the shared
// wordpress-blog rule, which silently disabled automatic translation of
// normal posts lab-wide (found by the T1 subsite-translation gate,
// 2026-09-09). Purge only this fixture's own _e2e_ namespace here; the
// product's core field defaults are re-synced below.
$existing_caps = json_decode( (string) ( $rule_row['field_capabilities'] ?? '{}' ), true );
if ( ! is_array( $existing_caps ) ) {
	$existing_caps = array();
}
foreach ( array_keys( $existing_caps ) as $cap_key ) {
	if ( 0 === strpos( (string) $cap_key, '_e2e_' ) ) {
		unset( $existing_caps[ $cap_key ] );
	}
}
$wpdb->update(
	$rules_table,
	array( 'field_capabilities' => wp_json_encode( $existing_caps ) ),
	array( 'id' => $rule_id ),
	array( '%s' ),
	array( '%d' )
);

$manual_fields   = array(
		'_e2e_manual_text'  => array(
			'table_name'        => $postmeta_table,
			'associated_id_map' => 'post_id',
			'description'       => 'E2E baseline manual text field.',
		),
		'_e2e_manual_json'  => array(
			'table_name'        => $postmeta_table,
			'associated_id_map' => 'post_id',
			'description'       => 'E2E baseline json_structured field.',
		),
		'_e2e_manual_serialized' => array(
			'table_name'        => $postmeta_table,
			'associated_id_map' => 'post_id',
			'description'       => 'E2E baseline serialized_php field.',
		),
		'_e2e_manual_code' => array(
			'table_name'        => $postmeta_table,
			'associated_id_map' => 'post_id',
			'description'       => 'E2E baseline code-like field.',
		),
		'_e2e_manual_slug' => array(
			'table_name'        => $postmeta_table,
			'associated_id_map' => 'post_id',
			'description'       => 'E2E baseline slug-like field.',
		),
		'_e2e_manual_skip' => array(
			'table_name'        => $postmeta_table,
			'associated_id_map' => 'post_id',
			'description'       => 'E2E baseline skip field.',
		),
	);
if ( ! $text_only_fixture ) {
	$manual_fields = array_merge(
		array(
			'_e2e_manual_text' => $manual_fields['_e2e_manual_text'],
			'_e2e_media_ref_id' => array(
				'table_name'        => $postmeta_table,
				'associated_id_map' => 'post_id',
				'description'       => 'E2E baseline manual media reference field.',
			),
			'_e2e_media_ref_image' => array(
				'table_name'        => $postmeta_table,
				'associated_id_map' => 'post_id',
				'description'       => 'E2E baseline media_ref image field.',
			),
			'_e2e_media_ref_video' => array(
				'table_name'        => $postmeta_table,
				'associated_id_map' => 'post_id',
				'description'       => 'E2E baseline media_ref video field.',
			),
			'_e2e_media_ref_audio' => array(
				'table_name'        => $postmeta_table,
				'associated_id_map' => 'post_id',
				'description'       => 'E2E baseline media_ref audio field.',
			),
			'_e2e_media_ref_document' => array(
				'table_name'        => $postmeta_table,
				'associated_id_map' => 'post_id',
				'description'       => 'E2E baseline media_ref document field.',
			),
		),
		array_diff_key( $manual_fields, array( '_e2e_manual_text' => true ) )
	);
}

foreach ( $manual_fields as $field_key => $extra ) {
	$field_id = \WPTSALL\Models\Services\Model_Object_Service::add_field(
		$object_id,
		'meta',
		$field_key,
		'manual',
		$extra
	);
	if ( ! $field_id ) {
		e2e_fixture_fail( "Failed to add manual field {$field_key} for object_id={$object_id}." );
	}
}

$expected_capabilities = array(
	'_e2e_manual_text' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'content_format' => 'plain_text',
	),
	'_e2e_media_ref_id' => array(
		'type'           => 'id_mapping',
		'enabled'        => true,
		'storage'        => 'meta',
		'reference_type' => 'media',
		'content_format' => 'media_ref',
	),
	'_e2e_media_ref_image' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'reference_type' => 'media',
		'content_format' => 'media_ref',
		'task_type'      => 'image',
	),
	'_e2e_media_ref_video' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'reference_type' => 'media',
		'content_format' => 'media_ref',
		'task_type'      => 'video',
	),
	'_e2e_media_ref_audio' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'reference_type' => 'media',
		'content_format' => 'media_ref',
		'task_type'      => 'audio',
	),
	'_e2e_media_ref_document' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'reference_type' => 'media',
		'content_format' => 'media_ref',
		'task_type'      => 'document',
	),
	'_e2e_manual_json' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'content_format' => 'json_structured',
	),
	'_e2e_manual_serialized' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'content_format' => 'serialized_php',
	),
	'_e2e_manual_code' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'content_format' => 'code',
	),
	'_e2e_manual_slug' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'storage'        => 'meta',
		'content_format' => 'slug',
	),
	'_e2e_manual_skip' => array(
		'type'    => 'skip',
		'enabled' => true,
		'storage' => 'meta',
	),
);
if ( $text_only_fixture ) {
	foreach ( array( '_e2e_media_ref_id', '_e2e_media_ref_image', '_e2e_media_ref_video', '_e2e_media_ref_audio', '_e2e_media_ref_document' ) as $media_field_key ) {
		unset( $expected_capabilities[ $media_field_key ] );
	}
}
$sync_payload = array();
foreach ( $expected_capabilities as $field_key => $capability ) {
	$sync_payload[] = array(
		'field_key'  => $field_key,
		'capability' => $capability,
	);
}

// Self-heal the shared rule's core post fields from the product's
// authoritative defaults (Translation_Rule_Service::get_core_field_defaults).
// Older seed runs blanked them, which broke automatic translation of normal
// posts; re-declaring them here keeps every consumer of the shared
// wordpress-blog rule working (subsite gate, lab journeys, nightly lanes).
$core_defaults = method_exists( '\WPTSALL\Models\Services\Translation_Rule_Service', 'get_core_field_defaults' )
	? \WPTSALL\Models\Services\Translation_Rule_Service::get_core_field_defaults()
	: array();
$post_core_keys = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'guid', 'post_status', 'post_date', 'post_author', 'post_parent', '_thumbnail_id' );
foreach ( $post_core_keys as $core_key ) {
	if ( isset( $core_defaults[ $core_key ] ) && is_array( $core_defaults[ $core_key ] ) ) {
		$sync_payload[] = array(
			'field_key'  => $core_key,
			'capability' => $core_defaults[ $core_key ],
		);
	}
}

$sync_result = \WPTSALL\Models\Services\Field_Sync_Service::sync_fields_to_rule(
	$rule_id,
	$sync_payload
);

$sync_covered  = (int) ( $sync_result['synced'] ?? 0 ) + (int) ( $sync_result['skipped'] ?? 0 );
$sync_expected = count( $manual_fields );
if ( $sync_covered < $sync_expected ) {
	$errors = isset( $sync_result['errors'] ) ? implode( '; ', (array) $sync_result['errors'] ) : 'unknown';
	e2e_fixture_fail( 'Failed to sync manual fields to translation rule. ' . $errors );
}

$caps_json = (string) $wpdb->get_var(
	$wpdb->prepare( "SELECT field_capabilities FROM {$rules_table} WHERE id = %d", $rule_id )
);
$caps = json_decode( $caps_json, true );
$caps = is_array( $caps ) ? $caps : array();
foreach ( array_keys( $manual_fields ) as $field_key ) {
	if ( empty( $caps[ $field_key ] ) || ! is_array( $caps[ $field_key ] ) ) {
		e2e_fixture_fail( "Rule field_capabilities missing fixture field {$field_key}." );
	}
}

echo "  Model ID:      {$model_id} ({$plugin_slug})\n";
echo "  Object ID:     {$object_id} ({$object_row['object_name']})\n";
echo "  Rule ID:       {$rule_id} ({$rule_row['object_name']})\n";
echo "  Manual fields: " . implode( ', ', array_keys( $manual_fields ) ) . "\n";

$templates_table = e2e_table( 'templates' );
$entries_table   = e2e_table( 'template_entries' );
$template_count  = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$templates_table} WHERE relation_id = %d",
		$virtual_relation_id
	)
);
$entry_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$entries_table} e
		 INNER JOIN {$templates_table} t ON e.template_id = t.id
		 WHERE t.relation_id = %d",
		$virtual_relation_id
	)
);

echo "\n--- Step 3: language_pack fixture scan (virtual relation) ---\n";
if ( $skip_language_pack ) {
	echo "  SKIPPED: skip-language-pack enabled for lightweight functional gate.\n";
} else {
	$wptsall_includes = ( defined( 'WPTSALL_PATH' ) ? WPTSALL_PATH : WP_PLUGIN_DIR . '/wptsall/' ) . 'includes/';
	require_once $wptsall_includes . 'sites/services/class-relation-config-service.php';
	require_once $wptsall_includes . 'templates/scanners/class-language-pack-scanner.php';

	$save_cfg = \WPTSALL\Sites\Services\Relation_Config_Service::save_template_config(
		$virtual_relation_id,
		array(
			'translate_plugin_i18n' => true,
			'translate_theme_i18n'  => true,
			'translate_config_i18n' => true,
			'plugin_slugs'          => e2e_plugin_slugs(),
		)
	);
	if ( is_wp_error( $save_cfg ) ) {
		e2e_fixture_fail( 'Failed to save template config: ' . $save_cfg->get_error_message() );
	}

	if ( $entry_count <= 0 ) {
		$scan_result = \WPTSALL\Templates\Scanners\Language_Pack_Scanner::scan_relation(
			$virtual_relation_id,
			\WPTSALL\Templates\Scanners\Language_Pack_Scanner::SCAN_POT_FILES
		);
		if ( empty( $scan_result['success'] ) ) {
			$error = (string) ( $scan_result['error'] ?? 'unknown error' );
			e2e_fixture_fail( 'Language pack POT scan failed: ' . $error );
		}

		$template_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$templates_table} WHERE relation_id = %d",
				$virtual_relation_id
			)
		);
		$entry_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.relation_id = %d",
				$virtual_relation_id
			)
		);
	} else {
		echo "  Existing language_pack entries detected ({$entry_count}), skip active scan.\n";
	}

	if ( $entry_count <= 0 ) {
		// Fallback to comprehensive scan for environments with sparse POT files.
		$scan_result = \WPTSALL\Templates\Scanners\Language_Pack_Scanner::scan_relation(
			$virtual_relation_id,
			\WPTSALL\Templates\Scanners\Language_Pack_Scanner::SCAN_ALL
		);

		$template_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$templates_table} WHERE relation_id = %d",
				$virtual_relation_id
			)
		);
		$entry_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.relation_id = %d",
				$virtual_relation_id
			)
		);
	}
}

if ( ! $skip_language_pack && $entry_count <= 0 ) {
	e2e_fixture_fail( 'language_pack baseline not ready: no template entries found after scan.' );
}

$template_by_source = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT source_type, COUNT(*) AS cnt
		 FROM {$templates_table}
		 WHERE relation_id = %d
		 GROUP BY source_type
		 ORDER BY source_type ASC",
		$virtual_relation_id
	),
	ARRAY_A
);
$entry_by_source = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT t.source_type, COUNT(*) AS cnt
		 FROM {$entries_table} e
		 INNER JOIN {$templates_table} t ON e.template_id = t.id
		 WHERE t.relation_id = %d
		 GROUP BY t.source_type
		 ORDER BY t.source_type ASC",
		$virtual_relation_id
	),
	ARRAY_A
);

echo "  Virtual relation ID: {$virtual_relation_id}\n";
echo "  Templates count:     {$template_count}\n";
echo "  Entries count:       {$entry_count}\n";

echo "\n--- Step 4: Ensure fixture post_mapping for include_ids/retry path ---\n";
if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
	wptsall_ensure_relation_scoped_mapping_tables();
}
$post_mappings_table = e2e_table( 'post_mappings' );
$relations_table     = e2e_table( 'site_relations' );
$relation_row        = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, source_site_id, target_site_id FROM {$relations_table} WHERE id = %d LIMIT 1",
		$virtual_relation_id
	),
	ARRAY_A
);
if ( empty( $relation_row['id'] ) ) {
	e2e_fixture_fail( "Virtual relation {$virtual_relation_id} missing while ensuring fixture mapping." );
}
$source_site_id = (int) ( $relation_row['source_site_id'] ?? get_current_blog_id() );
$target_site_id = (string) ( $relation_row['target_site_id'] ?? '' );
$fixture_post   = get_post( $post_id );
$fixture_type   = $fixture_post instanceof WP_Post ? (string) $fixture_post->post_type : 'post';
$now_gmt        = gmdate( 'Y-m-d H:i:s' );
$existing_map_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT id FROM {$post_mappings_table}
		 WHERE relation_id = %d AND source_post_id = %d AND source_site_id = %d AND target_site_id = %s
		 LIMIT 1",
		$virtual_relation_id,
		$post_id,
		$source_site_id,
		$target_site_id
	)
);
if ( $existing_map_id > 0 ) {
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$post_mappings_table}
			 SET source_post_type = %s,
			     target_post_id = 0,
			     target_post_type = %s,
			     relationship_type = 'claim_placeholder',
			     needs_resync = 1,
			     claimed_at = NULL,
			     claim_owner_hash = '',
			     updated_at = %s
			 WHERE id = %d",
			$fixture_type,
			$fixture_type,
			$now_gmt,
			$existing_map_id
		)
	);
	if ( false === $updated ) {
		e2e_fixture_fail( "Failed to refresh fixture post_mapping id={$existing_map_id}." );
	}
	echo "  Updated mapping id={$existing_map_id} for post={$post_id} relation={$virtual_relation_id}\n";
} else {
	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$post_mappings_table}
			 (relation_id, source_post_id, source_post_type, source_site_id,
			  target_post_id, target_post_type, target_site_id, relationship_type,
			  needs_resync, claimed_at, claim_owner_hash, created_at, updated_at)
			 VALUES (%d, %d, %s, %d, 0, %s, %s, 'claim_placeholder', 1, NULL, '', %s, %s)",
			$virtual_relation_id,
			$post_id,
			$fixture_type,
			$source_site_id,
			$fixture_type,
			$target_site_id,
			$now_gmt,
			$now_gmt
		)
	);
	if ( false === $inserted ) {
		e2e_fixture_fail( "Failed to insert fixture post_mapping for post={$post_id} relation={$virtual_relation_id}." );
	}
	echo "  Inserted mapping id={$wpdb->insert_id} for post={$post_id} relation={$virtual_relation_id}\n";
}

$baseline = array(
	'version'               => 2,
	'generated_at'          => gmdate( 'c' ),
	'run_scope'             => $run_scope,
	'relation_ids'          => $relation_ids,
	'fixture_post_id'       => $post_id,
	'fixture_media_id'      => (int) ( $media_set['image']['id'] ?? 0 ),
	'fixture_media'         => $media_set,
	'fixture_model_id'      => $model_id,
	'fixture_object_id'     => $object_id,
	'fixture_rule_id'       => $rule_id,
	'manual_fields'         => array_keys( $manual_fields ),
	'language_pack'         => array(
		'virtual_relation_id'  => $virtual_relation_id,
		'skipped'              => $skip_language_pack,
		'templates_total'      => $template_count,
		'entries_total'        => $entry_count,
		'templates_by_source'  => e2e_rows_to_count_map( $template_by_source ),
		'entries_by_source'    => e2e_rows_to_count_map( $entry_by_source ),
	),
);

$runtime_dir = e2e_runtime_dir();
if ( ! is_dir( $runtime_dir ) ) {
	mkdir( $runtime_dir, 0755, true );
}
$runtime_file = $runtime_dir . '/baseline-fixtures.json';

$written = file_put_contents(
	$runtime_file,
	json_encode( $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
);
if ( false === $written ) {
	e2e_fixture_fail( 'Failed to write runtime/baseline-fixtures.json.' );
}

echo "\n--- Summary ---\n";
echo "  Baseline file: {$runtime_file}\n";
echo "  Fixture post:  {$post_id}\n";
echo "  Fixture media: " . (int) ( $media_set['image']['id'] ?? 0 ) . " (image)\n";
echo "  Fixture rule:  {$rule_id}\n";
echo "  LP entries:    {$entry_count}\n";
echo "\nBaseline fixtures ready.\n";
