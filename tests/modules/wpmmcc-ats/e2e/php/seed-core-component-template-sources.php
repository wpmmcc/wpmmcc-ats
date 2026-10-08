<?php
/**
 * Seed real WP core content sources for component-template/client flow checks.
 *
 * Creates or refreshes deterministic fixtures for:
 * - post
 * - page
 * - category
 * - attachment (image / video / audio / document)
 *
 * Also patches the active core model/rules so the client can discover these
 * fixtures with explicit field capability metadata:
 * - plain_text
 * - rich_html
 * - json_structured
 * - serialized_php
 * - media_ref
 * - skip
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/seed-core-component-template-sources.php
 */

require_once __DIR__ . '/helpers.php';

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

global $wpdb;

echo "=== E2E v2: Seed Core Component Template Sources ===\n\n";

/**
 * Exit with an error.
 *
 * @param string $message Error message.
 * @return void
 */
function core_seed_fail( string $message ): void {
	echo "ERROR: {$message}\n";
	exit( 1 );
}

/**
 * JSON encode helper.
 *
 * @param mixed $value JSON value.
 * @return string
 */
function core_seed_json( $value ): string {
	return wp_json_encode(
		$value,
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
	);
}

/**
 * Ensure runtime directory exists.
 *
 * @return void
 */
function core_seed_ensure_runtime_dir(): void {
	$dir = e2e_runtime_dir();
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
}

/**
 * Resolve the active relation used by client discovery.
 *
 * Prefer runtime virtual relation, then any active relation.
 *
 * @return array<string,mixed>
 */
function core_seed_resolve_relation(): array {
	global $wpdb;

	$relation_ids = e2e_load_relation_ids();
	$candidates   = array();

	foreach ( array( 'virtual', 'wp', 'self' ) as $key ) {
		$id = (int) ( $relation_ids[ $key ] ?? 0 );
		if ( $id > 0 ) {
			$candidates[] = $id;
		}
	}

	foreach ( $candidates as $relation_id ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( $relation && 'active' === sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return $relation;
		}
	}

	$table = wptsall_table( 'site_relations' );
	$row   = $wpdb->get_row(
		"SELECT * FROM {$table} WHERE status = 'active' ORDER BY CASE WHEN target_site_type = 'virtual' THEN 0 ELSE 1 END, id ASC LIMIT 1",
		ARRAY_A
	);
	if ( is_array( $row ) ) {
		return $row;
	}

	core_seed_fail( 'No active site relation found. Run tests/modules/wpmmcc-ats/e2e/php/setup-relations.php first.' );
}

/**
 * Resolve the core model used for WP built-in content.
 *
 * @return int
 */
function core_seed_resolve_model_id(): int {
	global $wpdb;

	$model_id = Translation_Rule_Service::get_model_id_for_object( 'post_type', 'post' );
	if ( $model_id ) {
		return (int) $model_id;
	}

	$models_table = wptsall_table( 'models' );
	$fallback     = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM %i WHERE plugin_slug = %s LIMIT 1',
			$models_table,
			'wordpress-blog'
		)
	);
	if ( $fallback > 0 ) {
		return $fallback;
	}

	core_seed_fail( 'Unable to resolve core model for post/page/category content.' );
}

/**
 * Ensure the relation is bound to the core model.
 *
 * @param int $relation_id Relation ID.
 * @param int $model_id    Model ID.
 * @return void
 */
function core_seed_ensure_relation_model( int $relation_id, int $model_id ): void {
	$models = Relation_Model_Service::get_models_by_relation( $relation_id );
	foreach ( (array) $models as $model ) {
		if ( (int) ( $model['id'] ?? 0 ) === $model_id ) {
			return;
		}
	}
	Relation_Model_Service::add_model_to_relation( $relation_id, $model_id );
}

/**
 * Deterministic media specs.
 *
 * @return array<string,array<string,string>>
 */
function core_seed_media_specs(): array {
	return array(
		'image'    => array(
			'marker_key' => '_wptsall_core_component_media_image',
			'filename'   => 'wptsall-core-component-image.png',
			'mime'       => 'image/png',
			'title'      => 'WPTSALL Core Component Image',
			'payload'    => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO9n8xQAAAAASUVORK5CYII=',
			'encoding'   => 'base64',
			'alt'        => 'Core image alt text for component routing',
			'caption'    => 'Core image caption for media metadata routing.',
			'content'    => '<p>Core image description with <strong>HTML</strong>.</p>',
		),
		'video'    => array(
			'marker_key' => '_wptsall_core_component_media_video',
			'filename'   => 'wptsall-core-component-video.mp4',
			'mime'       => 'video/mp4',
			'title'      => 'WPTSALL Core Component Video',
			'payload'    => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom",
			'encoding'   => 'raw',
			'alt'        => '',
			'caption'    => 'Core video caption metadata.',
			'content'    => '<p>Core video long description for media metadata routing.</p>',
		),
		'audio'    => array(
			'marker_key' => '_wptsall_core_component_media_audio',
			'filename'   => 'wptsall-core-component-audio.mp3',
			'mime'       => 'audio/mpeg',
			'title'      => 'WPTSALL Core Component Audio',
			'payload'    => "ID3\x04\x00\x00\x00\x00\x00\x00CORE-AUDIO-FIXTURE",
			'encoding'   => 'raw',
			'alt'        => '',
			'caption'    => 'Core audio caption metadata.',
			'content'    => '<p>Core audio description for media metadata routing.</p>',
		),
		'document' => array(
			'marker_key' => '_wptsall_core_component_media_document',
			'filename'   => 'wptsall-core-component-document.pdf',
			'mime'       => 'application/pdf',
			'title'      => 'WPTSALL Core Component Document',
			'payload'    => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
			'encoding'   => 'raw',
			'alt'        => '',
			'caption'    => 'Core document caption metadata.',
			'content'    => '<p>Core document description for media metadata routing.</p>',
		),
	);
}

/**
 * Resolve raw media bytes.
 *
 * @param array<string,string> $spec Media spec.
 * @return string
 */
function core_seed_media_bytes( array $spec ): string {
	$encoding = (string) ( $spec['encoding'] ?? 'raw' );
	$payload  = (string) ( $spec['payload'] ?? '' );
	if ( 'base64' === $encoding ) {
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			core_seed_fail( 'Unable to decode base64 fixture for ' . ( $spec['filename'] ?? 'unknown' ) );
		}
		return $decoded;
	}
	return $payload;
}

/**
 * Create or refresh media attachments.
 *
 * @return array<string,array<string,mixed>>
 */
function core_seed_ensure_media_set(): array {
	global $wpdb;

	$fixtures = array();
	foreach ( core_seed_media_specs() as $kind => $spec ) {
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id DESC LIMIT 1",
				(string) $spec['marker_key']
			)
		);

		$attachment_id = 0;
		if ( $existing_id > 0 && 'attachment' === get_post_type( $existing_id ) ) {
			$attachment_id = $existing_id;
		}

		if ( $attachment_id <= 0 ) {
			$upload = wp_upload_bits(
				(string) $spec['filename'],
				null,
				core_seed_media_bytes( $spec )
			);
			if ( ! empty( $upload['error'] ) ) {
				core_seed_fail( 'wp_upload_bits failed for ' . $kind . ': ' . $upload['error'] );
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_title'     => (string) $spec['title'],
					'post_excerpt'   => (string) $spec['caption'],
					'post_content'   => (string) $spec['content'],
					'post_mime_type' => (string) $spec['mime'],
					'post_status'    => 'inherit',
				),
				$upload['file']
			);
			if ( is_wp_error( $attachment_id ) || (int) $attachment_id <= 0 ) {
				$message = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'unknown error';
				core_seed_fail( 'wp_insert_attachment failed for ' . $kind . ': ' . $message );
			}

			if ( 0 === strpos( (string) $spec['mime'], 'image/' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
				if ( is_array( $metadata ) ) {
					wp_update_attachment_metadata( (int) $attachment_id, $metadata );
				}
			}
		} else {
			$result = wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_title'   => (string) $spec['title'],
					'post_excerpt' => (string) $spec['caption'],
					'post_content' => (string) $spec['content'],
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				core_seed_fail( 'wp_update_post failed for ' . $kind . ': ' . $result->get_error_message() );
			}
		}

		$attachment_id = (int) $attachment_id;
		$url           = wp_get_attachment_url( $attachment_id );
		if ( empty( $url ) ) {
			core_seed_fail( 'Unable to resolve attachment URL for ' . $kind . '.' );
		}

		update_post_meta( $attachment_id, (string) $spec['marker_key'], '1' );
		update_post_meta( $attachment_id, '_wptsall_core_component_kind', $kind );
		update_post_meta( $attachment_id, '_wptsall_core_source_file_id', $attachment_id );
		update_post_meta( $attachment_id, '_wptsall_core_source_file_url', (string) $url );
		if ( '' !== (string) ( $spec['alt'] ?? '' ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', (string) $spec['alt'] );
		}

		$fixtures[ $kind ] = array(
			'id'        => $attachment_id,
			'url'       => (string) $url,
			'mime'      => (string) get_post_mime_type( $attachment_id ),
			'title'     => (string) $spec['title'],
			'caption'   => (string) $spec['caption'],
			'content'   => (string) $spec['content'],
			'alt'       => (string) ( $spec['alt'] ?? '' ),
			'extension' => pathinfo( wp_parse_url( (string) $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ),
		);
	}

	return $fixtures;
}

/**
 * Build structured and skip-heavy meta payload for posts/pages.
 *
 * @param array<string,array<string,mixed>> $media_set Media set.
 * @param string                            $variant   post|page
 * @return array<string,mixed>
 */
function core_seed_post_meta_payload( array $media_set, string $variant ): array {
	$label = 'page' === $variant ? 'Page' : 'Post';

	return array(
		'_wptsall_core_json'          => wp_json_encode(
			array(
				'title'    => "{$label} JSON Fixture",
				'sections' => array(
					array(
						'heading' => "{$label} structured heading",
						'body'    => "{$label} structured paragraph",
					),
				),
				'meta'     => array(
					'cta' => 'Structured CTA text',
				),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		),
		'_wptsall_core_serialized'    => maybe_serialize(
			array(
				'label' => "{$label} Serialized Fixture",
				'items' => array( 'alpha', 'beta', 'gamma' ),
			)
		),
		'_wptsall_core_cta_href'      => 'https://example.com/' . strtolower( $label ) . '/cta?lang=zh-cn',
		'_wptsall_core_gallery_srcset' => implode(
			', ',
			array(
				(string) ( $media_set['image']['url'] ?? '' ) . ' 640w',
				(string) ( $media_set['image']['url'] ?? '' ) . ' 1280w',
			)
		),
		'_wptsall_core_hero_poster'   => (string) ( $media_set['image']['url'] ?? '' ),
		'_wptsall_core_image_id'      => (int) ( $media_set['image']['id'] ?? 0 ),
		'_wptsall_core_image_url'     => (string) ( $media_set['image']['url'] ?? '' ),
		'_wptsall_core_video_id'      => (int) ( $media_set['video']['id'] ?? 0 ),
		'_wptsall_core_video_url'     => (string) ( $media_set['video']['url'] ?? '' ),
		'_wptsall_core_audio_id'      => (int) ( $media_set['audio']['id'] ?? 0 ),
		'_wptsall_core_audio_url'     => (string) ( $media_set['audio']['url'] ?? '' ),
		'_wptsall_core_document_id'   => (int) ( $media_set['document']['id'] ?? 0 ),
		'_wptsall_core_document_url'  => (string) ( $media_set['document']['url'] ?? '' ),
	);
}

/**
 * Create or refresh a deterministic post/page fixture.
 *
 * @param string                            $post_type Post type.
 * @param string                            $slug      Slug.
 * @param string                            $title     Title.
 * @param string                            $content   HTML content.
 * @param string                            $excerpt   Excerpt.
 * @param array<string,mixed>               $meta      Meta payload.
 * @param array<string,array<string,mixed>> $media_set Media set.
 * @return int
 */
function core_seed_ensure_post_fixture(
	string $post_type,
	string $slug,
	string $title,
	string $content,
	string $excerpt,
	array $meta,
	array $media_set
): int {
	global $wpdb;

	$post_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status != 'trash' ORDER BY ID DESC LIMIT 1",
			$slug,
			$post_type
		)
	);
	$now_local = current_time( 'mysql' );
	$now_gmt   = current_time( 'mysql', 1 );

	// Use direct DB upsert instead of wp_insert_post/wp_update_post to avoid
	// third-party plugins re-entering post creation hooks during E2E CLI seeding.
	if ( $post_id > 0 ) {
		$updated = $wpdb->update(
			$wpdb->posts,
			array(
				'post_title'        => $title,
				'post_content'      => $content,
				'post_excerpt'      => $excerpt,
				'post_status'       => 'publish',
				'post_author'       => 1,
				'post_modified'     => $now_local,
				'post_modified_gmt' => $now_gmt,
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			core_seed_fail( 'Failed to update ' . $post_type . ' fixture via SQL: ' . $wpdb->last_error );
		}
	} else {
		$inserted = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'       => 1,
				'post_date'         => $now_local,
				'post_date_gmt'     => $now_gmt,
				'post_content'      => $content,
				'post_title'        => $title,
				'post_excerpt'      => $excerpt,
				'post_status'       => 'publish',
				'comment_status'    => 'closed',
				'ping_status'       => 'closed',
				'post_name'         => $slug,
				'post_modified'     => $now_local,
				'post_modified_gmt' => $now_gmt,
				'post_parent'       => 0,
				'guid'              => '',
				'menu_order'        => 0,
				'post_type'         => $post_type,
				'post_mime_type'    => '',
				'comment_count'     => 0,
			),
			array(
				'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d',
			)
		);
		if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
			core_seed_fail( 'Failed to insert ' . $post_type . ' fixture via SQL: ' . $wpdb->last_error );
		}
		$post_id = (int) $wpdb->insert_id;
		$guid    = home_url( '/?p=' . $post_id );
		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => $guid ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	clean_post_cache( $post_id );
	update_post_meta( $post_id, '_wptsall_core_component_fixture', '1' );
	update_post_meta( $post_id, '_wptsall_core_component_fixture_type', $post_type );
	update_post_meta( $post_id, '_thumbnail_id', (int) ( $media_set['image']['id'] ?? 0 ) );

	foreach ( $meta as $meta_key => $meta_value ) {
		update_post_meta( $post_id, $meta_key, $meta_value );
	}

	return $post_id;
}

/**
 * Create or refresh a deterministic category fixture.
 *
 * @param array<string,array<string,mixed>> $media_set Media set.
 * @return int
 */
function core_seed_ensure_category_fixture( array $media_set ): int {
	$slug        = 'wptsall-core-component-category';
	$existing    = get_term_by( 'slug', $slug, 'category' );
	$term_id     = $existing ? (int) $existing->term_id : 0;
	$description = '<p>Core category description with <strong>HTML</strong> body.</p>';

	if ( $term_id > 0 ) {
		$result = wp_update_term(
			$term_id,
			'category',
			array(
				'name'        => 'WPTSALL Core Component Category',
				'description' => $description,
				'slug'        => $slug,
			)
		);
		if ( is_wp_error( $result ) ) {
			core_seed_fail( 'Failed to update category fixture: ' . $result->get_error_message() );
		}
	} else {
		$result = wp_insert_term(
			'WPTSALL Core Component Category',
			'category',
			array(
				'description' => $description,
				'slug'        => $slug,
			)
		);
		if ( is_wp_error( $result ) || empty( $result['term_id'] ) ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : 'unknown error';
			core_seed_fail( 'Failed to create category fixture: ' . $message );
		}
		$term_id = (int) $result['term_id'];
	}

	update_term_meta( $term_id, '_wptsall_core_component_fixture', '1' );
	update_term_meta( $term_id, '_wptsall_core_term_json', wp_json_encode(
		array(
			'title'  => 'Category JSON Fixture',
			'blocks' => array( 'overview', 'faq' ),
			'meta'   => array(
				'cta' => 'Category structured CTA',
			),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) );
	update_term_meta( $term_id, '_wptsall_core_term_serialized', maybe_serialize(
		array(
			'label' => 'Category Serialized Fixture',
			'items' => array( 'one', 'two' ),
		)
	) );
	update_term_meta( $term_id, '_wptsall_core_term_cta_href', 'https://example.com/category/landing' );
	update_term_meta( $term_id, '_wptsall_core_term_image_id', (int) ( $media_set['image']['id'] ?? 0 ) );
	update_term_meta( $term_id, '_wptsall_core_term_image_url', (string) ( $media_set['image']['url'] ?? '' ) );
	update_term_meta( $term_id, '_wptsall_core_term_image_alt', 'Category image alt text routed as media metadata.' );

	return $term_id;
}

/**
 * Ensure post-term relationship for the seeded post.
 *
 * @param int $post_id Post ID.
 * @param int $term_id Category term ID.
 * @return void
 */
function core_seed_assign_category_to_post( int $post_id, int $term_id ): void {
	if ( $post_id <= 0 || $term_id <= 0 ) {
		return;
	}
	wp_set_post_terms( $post_id, array( $term_id ), 'category', false );
}

/**
 * Ensure a model object exists and return it.
 *
 * @param int    $model_id     Model ID.
 * @param string $object_type  post_type|taxonomy.
 * @param string $object_name  Object name.
 * @return array<string,mixed>
 */
function core_seed_ensure_object( int $model_id, string $object_type, string $object_name ): array {
	$object = Model_Object_Service::get_object_by_type( $model_id, $object_type, $object_name );
	if ( is_array( $object ) ) {
		return $object;
	}

	$object_id = Model_Object_Service::create_object(
		$model_id,
		$object_type,
		$object_name,
		array(
			'metadata'    => array(
				'label'      => ucfirst( $object_name ),
				'seed_scope' => 'core_component_template_sources',
			),
			'source_type' => 'manual',
		)
	);
	if ( ! $object_id ) {
		core_seed_fail( 'Failed to create model object ' . $object_type . ':' . $object_name );
	}

	$object = Model_Object_Service::get_object_by_type( $model_id, $object_type, $object_name );
	if ( ! is_array( $object ) ) {
		core_seed_fail( 'Created model object but could not read it back: ' . $object_name );
	}

	return $object;
}

/**
 * Upsert template fields for an object.
 *
 * @param int                              $object_id Object ID.
 * @param array<int,array<string,mixed>>   $fields    Field declarations.
 * @return void
 */
function core_seed_ensure_template_fields( int $object_id, array $fields ): void {
	foreach ( $fields as $field ) {
		$result = Model_Object_Service::insert_field(
			$object_id,
			array(
				'field_kind'       => (string) ( $field['field_kind'] ?? 'meta' ),
				'field_key'        => (string) ( $field['field_key'] ?? '' ),
				'source'           => 'manual',
				'data_type'        => $field['data_type'] ?? null,
				'reference_type'   => $field['reference_type'] ?? null,
				'reference_target' => $field['reference_target'] ?? null,
				'status'           => 'active',
				'extra'            => array(
					'seed_scope' => 'core_component_template_sources',
				),
			)
		);
		if ( ! $result ) {
			core_seed_fail( 'Failed to upsert template field ' . ( $field['field_key'] ?? '' ) . ' for object_id=' . $object_id );
		}
	}
}

/**
 * Merge capability patches into an existing rule payload.
 *
 * @param array<string,mixed> $existing Existing caps.
 * @param array<string,mixed> $patches  Patches.
 * @return array<string,mixed>
 */
function core_seed_merge_capabilities( array $existing, array $patches ): array {
	foreach ( $patches as $field_key => $capability ) {
		$current = is_array( $existing[ $field_key ] ?? null ) ? $existing[ $field_key ] : array();
		$existing[ $field_key ] = array_merge( $current, $capability );
	}
	return $existing;
}

/**
 * Ensure a rule exists and contains the requested capabilities.
 *
 * @param int                 $model_id          Model ID.
 * @param array<string,mixed> $rule_spec         Rule spec.
 * @param array<string,mixed> $capability_patches Capability patches.
 * @return int
 */
function core_seed_ensure_rule( int $model_id, array $rule_spec, array $capability_patches ): int {
	$data_type   = (string) ( $rule_spec['data_type'] ?? 'post' );
	$object_name = (string) ( $rule_spec['object_name'] ?? '' );

	if ( 'term' === $data_type ) {
		$existing = Translation_Rule_Service::get_rule_by_taxonomy( $object_name, $model_id );
	} else {
		$existing = Translation_Rule_Service::get_rule_by_post_type( $model_id, $object_name );
	}

	if ( is_array( $existing ) ) {
		$rule_id = (int) ( $existing['id'] ?? 0 );
		$caps    = is_array( $existing['field_capabilities'] ?? null ) ? $existing['field_capabilities'] : array();
		$caps    = core_seed_merge_capabilities( $caps, $capability_patches );
		$result  = Translation_Rule_Service::update_rule(
			$rule_id,
			array_merge(
				$rule_spec,
				array(
					'field_capabilities' => $caps,
					'is_active'          => true,
				)
			)
		);

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			if ( false !== strpos( $message, 'Some rule fields are not declared in the corresponding plugin template.' ) ) {
				global $wpdb;
				$rules_table = wptsall_table( 'translation_rules' );
				$forced      = $wpdb->update(
					$rules_table,
					array(
						'field_capabilities' => wp_json_encode( $caps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						'is_active'          => 1,
						'updated_at'         => current_time( 'mysql' ),
					),
					array( 'id' => $rule_id ),
					array( '%s', '%d', '%s' ),
					array( '%d' )
				);
				if ( false === $forced ) {
					core_seed_fail( 'Failed to force-update rule capabilities for ' . $object_name . ': ' . $wpdb->last_error );
				}
				echo '  warn: forced SQL capability update for ' . $object_name . ' (template declaration mismatch)' . "\n";
				return $rule_id;
			}
			core_seed_fail( 'Failed to update rule for ' . $object_name . ': ' . $message );
		}

		return $rule_id;
	}

	$result = Translation_Rule_Service::create_rule(
		$model_id,
		array_merge(
			$rule_spec,
			array(
				'field_capabilities' => $capability_patches,
				'is_active'          => true,
			)
		)
	);
	if ( is_wp_error( $result ) ) {
		core_seed_fail( 'Failed to create rule for ' . $object_name . ': ' . $result->get_error_message() );
	}

	return (int) $result;
}

/**
 * Template field declarations per object.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function core_seed_template_fields(): array {
	return array(
		'post'       => array(
			array( 'field_kind' => 'core', 'field_key' => 'post_title', 'data_type' => 'text' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_content', 'data_type' => 'html' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_excerpt', 'data_type' => 'text' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_name', 'data_type' => 'slug' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_json', 'data_type' => 'json' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_serialized', 'data_type' => 'serialized' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_cta_href', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_gallery_srcset', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_hero_poster', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_image_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_image_url', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_video_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_video_url', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_audio_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_audio_url', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_document_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_document_url', 'data_type' => 'url' ),
		),
		'page'       => array(
			array( 'field_kind' => 'core', 'field_key' => 'post_title', 'data_type' => 'text' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_content', 'data_type' => 'html' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_excerpt', 'data_type' => 'text' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_name', 'data_type' => 'slug' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_json', 'data_type' => 'json' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_serialized', 'data_type' => 'serialized' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_cta_href', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_gallery_srcset', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_hero_poster', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_image_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_image_url', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_video_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_video_url', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_audio_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_audio_url', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_document_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_document_url', 'data_type' => 'url' ),
		),
		'category'   => array(
			array( 'field_kind' => 'core', 'field_key' => 'name', 'data_type' => 'text' ),
			array( 'field_kind' => 'core', 'field_key' => 'description', 'data_type' => 'html' ),
			array( 'field_kind' => 'core', 'field_key' => 'slug', 'data_type' => 'slug' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_term_json', 'data_type' => 'json' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_term_serialized', 'data_type' => 'serialized' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_term_cta_href', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_term_image_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_term_image_url', 'data_type' => 'url' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_term_image_alt', 'data_type' => 'text', 'reference_type' => 'media' ),
		),
		'attachment' => array(
			array( 'field_kind' => 'core', 'field_key' => 'post_title', 'data_type' => 'text', 'reference_type' => 'media' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_excerpt', 'data_type' => 'text', 'reference_type' => 'media' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_content', 'data_type' => 'html', 'reference_type' => 'media' ),
			array( 'field_kind' => 'core', 'field_key' => 'post_name', 'data_type' => 'slug' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wp_attachment_image_alt', 'data_type' => 'text', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_source_file_id', 'data_type' => 'media', 'reference_type' => 'media' ),
			array( 'field_kind' => 'meta', 'field_key' => '_wptsall_core_source_file_url', 'data_type' => 'url' ),
		),
	);
}

/**
 * Capability patches per object.
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function core_seed_capability_patches(): array {
	return array(
		'post'       => array(
			'post_title'                 => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'content_format' => 'plain_text' ),
			'post_content'               => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'content_format' => 'rich_html' ),
			'post_excerpt'               => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'content_format' => 'plain_text' ),
			'post_name'                  => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_column' ),
			'_wptsall_core_json'         => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'content_format' => 'json_structured' ),
			'_wptsall_core_serialized'   => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'content_format' => 'serialized_php' ),
			'_wptsall_core_cta_href'     => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_gallery_srcset' => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_hero_poster'  => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_image_id'     => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'image' ),
			'_wptsall_core_image_url'    => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_video_id'     => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'video' ),
			'_wptsall_core_video_url'    => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_audio_id'     => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'audio' ),
			'_wptsall_core_audio_url'    => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_document_id'  => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'document' ),
			'_wptsall_core_document_url' => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
		),
		'page'       => array(
			'post_title'                 => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'content_format' => 'plain_text' ),
			'post_content'               => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'content_format' => 'rich_html' ),
			'post_excerpt'               => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'content_format' => 'plain_text' ),
			'post_name'                  => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_column' ),
			'_wptsall_core_json'         => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'content_format' => 'json_structured' ),
			'_wptsall_core_serialized'   => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'content_format' => 'serialized_php' ),
			'_wptsall_core_cta_href'     => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_gallery_srcset' => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_hero_poster'  => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_image_id'     => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'image' ),
			'_wptsall_core_image_url'    => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_video_id'     => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'video' ),
			'_wptsall_core_video_url'    => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_audio_id'     => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'audio' ),
			'_wptsall_core_audio_url'    => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
			'_wptsall_core_document_id'  => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'document' ),
			'_wptsall_core_document_url' => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
		),
		'category'   => array(
			'name'                       => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'term_column', 'content_format' => 'plain_text' ),
			'description'                => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'term_column', 'content_format' => 'rich_html' ),
			'slug'                       => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'term_column' ),
			'_wptsall_core_term_json'       => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'term_meta', 'content_format' => 'json_structured' ),
			'_wptsall_core_term_serialized' => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'term_meta', 'content_format' => 'serialized_php' ),
			'_wptsall_core_term_cta_href'   => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'term_meta' ),
			'_wptsall_core_term_image_id'   => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'term_meta', 'reference_type' => 'media', 'content_format' => 'media_ref', 'task_type' => 'image' ),
			'_wptsall_core_term_image_url'  => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'term_meta' ),
			'_wptsall_core_term_image_alt'  => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'term_meta', 'reference_type' => 'media', 'content_format' => 'media_ref' ),
		),
		'attachment' => array(
			'post_title'                  => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'reference_type' => 'media', 'content_format' => 'media_ref' ),
			'post_excerpt'                => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'reference_type' => 'media', 'content_format' => 'media_ref' ),
			'post_content'                => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_column', 'reference_type' => 'media', 'content_format' => 'media_ref' ),
			'post_name'                   => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_column' ),
			'_wp_attachment_image_alt'    => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref' ),
			'_wptsall_core_source_file_id'  => array( 'type' => 'translate', 'enabled' => true, 'storage' => 'post_meta', 'reference_type' => 'media', 'content_format' => 'media_ref' ),
			'_wptsall_core_source_file_url' => array( 'type' => 'skip', 'enabled' => true, 'storage' => 'post_meta' ),
		),
	);
}

$relation   = core_seed_resolve_relation();
$relation_id = (int) ( $relation['id'] ?? 0 );
$model_id   = core_seed_resolve_model_id();

core_seed_ensure_relation_model( $relation_id, $model_id );

$route_secret = function_exists( 'wptsall_get_client_route_secret' ) ? (string) wptsall_get_client_route_secret() : '';
// Device-scoped tokens are validated against the device id that presents
// them (Client_Token_Service::verify_client_token). The gate passes the live
// client's device id via WPTSALL_DEVICE_ID_FOR_TOKEN so the issued token
// matches the client that will actually use it; fall back to the historical
// random per-run device when the caller does not provide one.
$requested_device_id = sanitize_key( (string) getenv( 'WPTSALL_DEVICE_ID_FOR_TOKEN' ) );
$__d = function_exists( 'wptsall_issue_client_device_token' ) ? wptsall_issue_client_device_token( '' !== $requested_device_id ? $requested_device_id : ( 'e2e-' . wp_generate_password( 6, false ) ), 'e2e' ) : array();
$client_token = (string) ( $__d['token'] ?? '' );
$device_id = (string) ( $__d['device_id'] ?? '' );

if ( '' === $route_secret ) {
	core_seed_fail( 'Missing route secret.' );
}
if ( '' === $client_token ) {
	core_seed_fail( 'Missing wp client token.' );
}

echo "Relation ID: {$relation_id}\n";
echo "Model ID:    {$model_id}\n";

echo "\n--- Step 1: Media fixtures ---\n";
$media_set = core_seed_ensure_media_set();
foreach ( $media_set as $kind => $fixture ) {
	echo '  ' . str_pad( $kind, 8 ) . ' attachment=' . (int) $fixture['id'] . ' url=' . (string) $fixture['url'] . "\n";
}

echo "\n--- Step 2: Core source objects ---\n";
$post_id = core_seed_ensure_post_fixture(
	'post',
	'wptsall-core-component-post',
	'WPTSALL Core Component Post',
	'<p>Core post body with <strong>rich HTML</strong> and <a href="https://example.com/post">link</a>.</p>',
	'Core post excerpt for plain text routing.',
	core_seed_post_meta_payload( $media_set, 'post' ),
	$media_set
);
$page_id = core_seed_ensure_post_fixture(
	'page',
	'wptsall-core-component-page',
	'WPTSALL Core Component Page',
	'<section><h2>Core page body</h2><p>Page fixture keeps <em>HTML</em> structure for rich routing.</p></section>',
	'Core page excerpt for plain text routing.',
	core_seed_post_meta_payload( $media_set, 'page' ),
	$media_set
);
$term_id = core_seed_ensure_category_fixture( $media_set );
core_seed_assign_category_to_post( $post_id, $term_id );

echo "  post fixture ID:      {$post_id}\n";
echo "  page fixture ID:      {$page_id}\n";
echo "  category fixture ID:  {$term_id}\n";

echo "\n--- Step 3: Model objects + rule capabilities ---\n";
$template_fields     = core_seed_template_fields();
$capability_patches  = core_seed_capability_patches();
$rule_ids            = array();
$object_ids          = array();

$object_specs = array(
	'post'       => array(
		'object_type'  => 'post_type',
		'rule_spec'    => array(
			'name'               => 'Core Component Source Post',
			'url_pattern'        => '/post/{id}/',
			'url_type'           => 'single',
			'example_url'        => home_url( '/?p=' . $post_id ),
			'data_type'          => 'post',
			'object_name'        => 'post',
			'primary_table'      => $wpdb->posts,
			'meta_table'         => $wpdb->postmeta,
			'direction'          => 'one_way',
			'related_taxonomies' => array( 'category' ),
			'requires_login'     => false,
		),
	),
	'page'       => array(
		'object_type'  => 'post_type',
		'rule_spec'    => array(
			'name'           => 'Core Component Source Page',
			'url_pattern'    => '/page/{id}/',
			'url_type'       => 'single',
			'example_url'    => home_url( '/?page_id=' . $page_id ),
			'data_type'      => 'post',
			'object_name'    => 'page',
			'primary_table'  => $wpdb->posts,
			'meta_table'     => $wpdb->postmeta,
			'direction'      => 'one_way',
			'requires_login' => false,
		),
	),
	'category'   => array(
		'object_type'  => 'taxonomy',
		'rule_spec'    => array(
			'name'           => 'Core Component Source Category',
			'url_pattern'    => '?taxonomy=category&term={slug}',
			'url_type'       => 'taxonomy',
			'example_url'    => get_term_link( $term_id, 'category' ),
			'data_type'      => 'term',
			'object_name'    => 'category',
			'primary_table'  => $wpdb->terms,
			'meta_table'     => $wpdb->termmeta,
			'direction'      => 'one_way',
			'requires_login' => false,
		),
	),
	'attachment' => array(
		'object_type'  => 'post_type',
		'rule_spec'    => array(
			'name'           => 'Core Component Source Attachment',
			'url_pattern'    => '/attachment/{id}/',
			'url_type'       => 'attachment',
			'example_url'    => wp_get_attachment_url( (int) ( $media_set['image']['id'] ?? 0 ) ),
			'data_type'      => 'post',
			'object_name'    => 'attachment',
			'primary_table'  => $wpdb->posts,
			'meta_table'     => $wpdb->postmeta,
			'direction'      => 'one_way',
			'requires_login' => false,
		),
	),
);

foreach ( $object_specs as $object_name => $spec ) {
	$object = core_seed_ensure_object( $model_id, (string) $spec['object_type'], $object_name );
	$object_id = (int) ( $object['id'] ?? 0 );
	if ( $object_id <= 0 ) {
		core_seed_fail( 'Invalid object id for ' . $object_name );
	}

	core_seed_ensure_template_fields( $object_id, $template_fields[ $object_name ] ?? array() );
	$rule_ids[ $object_name ]   = core_seed_ensure_rule( $model_id, $spec['rule_spec'], $capability_patches[ $object_name ] ?? array() );
	$object_ids[ $object_name ] = $object_id;

	echo '  ' . str_pad( $object_name, 10 ) . ' object_id=' . $object_id . ' rule_id=' . (int) $rule_ids[ $object_name ] . "\n";
}

echo "\n--- Step 4: Runtime manifest ---\n";
core_seed_ensure_runtime_dir();

$manifest = array(
	'generated_at'      => gmdate( 'c' ),
	'relation_id'       => $relation_id,
	'model_id'          => $model_id,
	'device_id'         => $device_id,
	'route_secret'      => $route_secret,
	'wp_client_token'   => $client_token,
	'api_base_url'      => home_url( '/wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client' ),
	'fixtures'          => array(
		'post'       => array(
			'object_id'     => (int) ( $object_ids['post'] ?? 0 ),
			'rule_id'       => (int) ( $rule_ids['post'] ?? 0 ),
			'object_name'   => 'post',
			'data_type'     => 'post',
			'subtype'       => 'post',
			'object_id_ref' => $post_id,
			'field_formats' => array(
				'post_title'               => 'plain_text',
				'post_content'             => 'rich_html',
				'_wptsall_core_json'       => 'json_structured',
				'_wptsall_core_serialized' => 'serialized_php',
				'_wptsall_core_image_id'   => 'media_ref:image',
				'_wptsall_core_video_id'   => 'media_ref:video',
				'_wptsall_core_audio_id'   => 'media_ref:audio',
				'_wptsall_core_document_id' => 'media_ref:document',
				'_wptsall_core_cta_href'   => 'skip',
				'_wptsall_core_gallery_srcset' => 'skip',
				'_wptsall_core_hero_poster' => 'skip',
			),
		),
		'page'       => array(
			'object_id'     => (int) ( $object_ids['page'] ?? 0 ),
			'rule_id'       => (int) ( $rule_ids['page'] ?? 0 ),
			'object_name'   => 'page',
			'data_type'     => 'post',
			'subtype'       => 'page',
			'object_id_ref' => $page_id,
			'field_formats' => array(
				'post_title'               => 'plain_text',
				'post_content'             => 'rich_html',
				'_wptsall_core_json'       => 'json_structured',
				'_wptsall_core_serialized' => 'serialized_php',
				'_wptsall_core_image_id'   => 'media_ref:image',
				'_wptsall_core_video_id'   => 'media_ref:video',
				'_wptsall_core_audio_id'   => 'media_ref:audio',
				'_wptsall_core_document_id' => 'media_ref:document',
				'_wptsall_core_cta_href'   => 'skip',
			),
		),
		'category'   => array(
			'object_id'     => (int) ( $object_ids['category'] ?? 0 ),
			'rule_id'       => (int) ( $rule_ids['category'] ?? 0 ),
			'object_name'   => 'category',
			'data_type'     => 'term',
			'subtype'       => 'category',
			'object_id_ref' => $term_id,
			'field_formats' => array(
				'name'                        => 'plain_text',
				'description'                 => 'rich_html',
				'_wptsall_core_term_json'     => 'json_structured',
				'_wptsall_core_term_serialized' => 'serialized_php',
				'_wptsall_core_term_image_id' => 'media_ref:image',
				'_wptsall_core_term_image_alt' => 'media_ref:text',
				'_wptsall_core_term_cta_href' => 'skip',
				'slug'                        => 'skip',
			),
		),
		'attachment' => array(
			'object_id'     => (int) ( $object_ids['attachment'] ?? 0 ),
			'rule_id'       => (int) ( $rule_ids['attachment'] ?? 0 ),
			'object_name'   => 'attachment',
			'data_type'     => 'post',
			'subtype'       => 'attachment',
			'object_id_ref' => array(
				'image'    => (int) ( $media_set['image']['id'] ?? 0 ),
				'video'    => (int) ( $media_set['video']['id'] ?? 0 ),
				'audio'    => (int) ( $media_set['audio']['id'] ?? 0 ),
				'document' => (int) ( $media_set['document']['id'] ?? 0 ),
			),
			'field_formats' => array(
				'post_title'                 => 'media_ref:text',
				'post_excerpt'               => 'media_ref:text',
				'post_content'               => 'media_ref:text',
				'_wp_attachment_image_alt'   => 'media_ref:text',
				'_wptsall_core_source_file_id' => 'media_ref:file',
			),
		),
	),
	'media_set'          => $media_set,
	'verification_urls'  => array(
		'site_relations'   => home_url( '/wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client/site-relations' ),
		'rules'            => home_url( '/wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client/rules?relation_id=' . $relation_id ),
		'post_content'     => home_url( '/wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client/content?relation_id=' . $relation_id . '&data_type=post&subtype=post&include_ids=' . $post_id ),
		'page_content'     => home_url( '/wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client/content?relation_id=' . $relation_id . '&data_type=post&subtype=page&include_ids=' . $page_id ),
		'attachment_content' => home_url( '/wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client/content?relation_id=' . $relation_id . '&data_type=post&subtype=attachment&include_ids=' . implode( ',', array_map( 'intval', wp_list_pluck( $media_set, 'id' ) ) ) ),
		'term_content'     => home_url( '/wp-json/wptsall/v2/' . rawurlencode( $route_secret ) . '/client/content?relation_id=' . $relation_id . '&data_type=term&subtype=category&include_ids=' . $term_id ),
	),
);

$runtime_file = e2e_runtime_file( 'core-component-template-sources.json' );
$written      = file_put_contents( $runtime_file, core_seed_json( $manifest ) . "\n" );
if ( false === $written ) {
	core_seed_fail( 'Failed to write runtime/core-component-template-sources.json.' );
}

echo "  Runtime manifest: {$runtime_file}\n";
echo "  API base:         " . (string) $manifest['api_base_url'] . "\n";
echo "  Token length:     " . strlen( $client_token ) . "\n";
echo "\nDone.\n";
