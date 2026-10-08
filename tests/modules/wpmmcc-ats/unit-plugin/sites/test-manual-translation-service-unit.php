<?php
/**
 * Manual translation service behavior tests.
 *
 * @package WPTSALL
 */

class Test_Manual_Translation_Service_Unit extends WP_UnitTestCase {

	/**
	 * Created relation ids.
	 *
	 * @var int[]
	 */
	private $relation_ids = array();

	/**
	 * Created model ids.
	 *
	 * @var int[]
	 */
	private $model_ids = array();

	/**
	 * Created rule ids.
	 *
	 * @var int[]
	 */
	private $rule_ids = array();

	/**
	 * Created source post ids.
	 *
	 * @var int[]
	 */
	private $source_post_ids = array();

	/**
	 * Created target post ids.
	 *
	 * @var int[]
	 */
	private $target_post_ids = array();

	/**
	 * Created attachment ids.
	 *
	 * @var int[]
	 */
	private $attachment_ids = array();

	/**
	 * Created taxonomy terms.
	 *
	 * @var array<int, array{taxonomy:string, term_id:int}>
	 */
	private $created_terms = array();

	public function setUp(): void {
		parent::setUp();
		$this->ensure_required_tables();

		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		global $wpdb;

		foreach ( $this->rule_ids as $rule_id ) {
			$wpdb->delete( wptsall_table( 'translation_rules' ), array( 'id' => (int) $rule_id ), array( '%d' ) );
		}

		foreach ( $this->model_ids as $model_id ) {
			$wpdb->delete( wptsall_table( 'relation_models' ), array( 'model_id' => (int) $model_id ), array( '%d' ) );
			$wpdb->delete( wptsall_table( 'models' ), array( 'id' => (int) $model_id ), array( '%d' ) );
		}

		foreach ( $this->relation_ids as $relation_id ) {
			$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => (int) $relation_id ), array( '%d' ) );
		}

		$all_source_ids = array_unique( array_merge( $this->source_post_ids, $this->target_post_ids ) );
		foreach ( $all_source_ids as $post_id ) {
			$wpdb->delete( wptsall_table( 'post_mappings' ), array( 'source_post_id' => (int) $post_id ), array( '%d' ) );
		}

		foreach ( $this->target_post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}

		foreach ( $this->source_post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}

		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}

		foreach ( $this->created_terms as $term_ref ) {
			if ( taxonomy_exists( $term_ref['taxonomy'] ) ) {
				wp_delete_term( (int) $term_ref['term_id'], $term_ref['taxonomy'] );
			}
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_save_translation_respects_field_classification_and_sync_structure() {
		$relation_id    = $this->create_active_relation( 'en_US' );
		$this->create_model_rule_binding( $relation_id, 'post' );
		$attachment_id = $this->create_attachment_fixture();

		list( $source_category_id, $source_tag_id ) = $this->create_source_terms();
		$source_post_id = $this->create_source_post(
			array(
				'post_title'   => 'Source Classification Title',
				'post_content' => '<p>Source classification content</p>',
				'post_excerpt' => 'Source classification excerpt',
				'post_status'  => 'private',
				'post_date'    => '2024-01-02 03:04:05',
				'post_name'    => 'source-classification-title',
			),
			$attachment_id,
			array( $source_category_id ),
			array( $source_tag_id )
		);

		$result = \WPTSALL\Sites\Services\Manual_Content_Service::save_translation(
			$source_post_id,
			$relation_id,
			array(
				'post_title'   => 'Translated Classification Title',
				'post_content' => '<p>Translated classification <strong>content</strong></p>',
				'post_excerpt' => 'Translated classification excerpt',
				'post_name'    => 'translated-classification-title',
				'_thumbnail_id' => $attachment_id,
			)
		);

		$this->assertFalse( is_wp_error( $result ), 'save_translation should succeed.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'target_id', $result );

		$target_id = (int) $result['target_id'];
		$this->target_post_ids[] = $target_id;
		$target_post = get_post( $target_id );
		$source_post = get_post( $source_post_id );

		$this->assertNotNull( $target_post, 'Target post should exist after save_translation.' );
		$this->assertEquals( 'Translated Classification Title', $target_post->post_title, 'Translate field post_title should come from user input.' );
		$this->assertStringContainsString( 'Translated classification', $target_post->post_content, 'Translate field post_content should come from user input.' );
		$this->assertEquals( 'Translated classification excerpt', $target_post->post_excerpt, 'Translate field post_excerpt should come from user input.' );
		$this->assertEquals( 'translated-classification-title', $target_post->post_name, 'Compute field post_name should use provided/derived slug.' );
		$this->assertEquals( $source_post->post_status, $target_post->post_status, 'Sync field post_status should be copied from source in Translation Mode.' );
		$this->assertEquals( $source_post->post_date, $target_post->post_date, 'Sync field post_date should be copied from source in Translation Mode.' );
		$this->assertEquals( (string) $source_post_id, (string) get_post_meta( $target_id, '_wptsall_source_post_id', true ) );
		$this->assertEquals( (string) $relation_id, (string) get_post_meta( $target_id, '_wptsall_relation_id', true ) );
		$this->assertNotEmpty( get_post_meta( $target_id, '_wptsall_last_synced', true ), 'Manual save should write _wptsall_last_synced.' );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $target_id ), 'Media id_mapping field should store selected attachment ID.' );

		$target_categories = wp_get_post_terms( $target_id, 'category', array( 'fields' => 'ids' ) );
		$target_tags       = wp_get_post_terms( $target_id, 'post_tag', array( 'fields' => 'ids' ) );
		$this->assertNotEmpty( $target_categories, 'Category terms should sync from source to target.' );
		$this->assertNotEmpty( $target_tags, 'Tag terms should sync from source to target.' );

		$mapping_rows = $this->get_post_mapping_rows( $source_post_id, $target_id );
		$this->assertNotEmpty( $mapping_rows, 'Manual save should create Sync_Executor-compatible post mapping rows.' );
		$this->assertEquals( 'translation', (string) $mapping_rows[0]['relationship_type'] );
	}

	public function test_update_all_fields_edit_mode_bypasses_classification_and_updates_taxonomies() {
		$relation_id    = $this->create_active_relation( 'fr_FR' );
		$this->create_model_rule_binding( $relation_id, 'post' );
		$attachment_id = $this->create_attachment_fixture();

		list( $source_category_id, $source_tag_id ) = $this->create_source_terms();
		$source_post_id = $this->create_source_post(
			array(
				'post_title'   => 'Source Edit Mode Title',
				'post_content' => '<p>Source edit mode content</p>',
				'post_excerpt' => 'Source edit mode excerpt',
				'post_status'  => 'publish',
				'post_date'    => '2024-05-06 07:08:09',
				'post_name'    => 'source-edit-mode-title',
			),
			$attachment_id,
			array( $source_category_id ),
			array( $source_tag_id )
		);

		$save_result = \WPTSALL\Sites\Services\Manual_Content_Service::save_translation(
			$source_post_id,
			$relation_id,
			array(
				'post_title'   => 'Initial translated title',
				'post_content' => '<p>Initial translated content</p>',
				'post_excerpt' => 'Initial translated excerpt',
				'post_name'    => 'initial-translated-title',
				'_thumbnail_id' => $attachment_id,
			)
		);
		$this->assertFalse( is_wp_error( $save_result ) );
		$target_id = (int) $save_result['target_id'];
		$this->target_post_ids[] = $target_id;

		$edit_category_id = $this->create_term( 'category', 'Edit Mode Category ' . uniqid() );
		$new_tag_name     = 'edit-mode-tag-' . uniqid();

		$update_result = \WPTSALL\Sites\Services\Manual_Content_Service::update_all_fields(
			$target_id,
			$relation_id,
			array(
				'post_title'   => 'Edited Mode Final Title',
				'post_content' => '<p>Edited mode final content</p>',
				'post_excerpt' => 'Edited mode final excerpt',
				'post_status'  => 'draft',
				'post_date'    => '2024-09-10 11:12:13',
				'post_name'    => 'edited-mode-final-title',
				'_thumbnail_id' => $attachment_id,
				'taxonomies'   => array(
					'category' => array( $edit_category_id ),
					'post_tag' => array(
						'term_ids'  => array(),
						'new_terms' => array( $new_tag_name ),
					),
				),
			)
		);

		$this->assertFalse( is_wp_error( $update_result ), 'update_all_fields should succeed in Edit Mode.' );
		$target_post = get_post( $target_id );

		$this->assertEquals( 'Edited Mode Final Title', $target_post->post_title, 'Edit Mode should update translate fields.' );
		$this->assertStringContainsString( 'Edited mode final content', $target_post->post_content, 'Edit Mode should update rich text fields.' );
		$this->assertEquals( 'Edited mode final excerpt', $target_post->post_excerpt, 'Edit Mode should update excerpt.' );
		$this->assertEquals( 'draft', $target_post->post_status, 'Edit Mode should bypass classification and update sync field post_status.' );
		$this->assertEquals( '2024-09-10 11:12:13', $target_post->post_date, 'Edit Mode should bypass classification and update sync field post_date.' );
		$this->assertEquals( 'edited-mode-final-title', $target_post->post_name, 'Edit Mode should update compute field post_name.' );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $target_id ), 'Edit Mode should persist media field updates.' );

		$category_ids = wp_get_post_terms( $target_id, 'category', array( 'fields' => 'ids' ) );
		$tag_names    = wp_get_post_terms( $target_id, 'post_tag', array( 'fields' => 'names' ) );
		$this->assertContains( $edit_category_id, $category_ids, 'Hierarchical taxonomy checkbox selection should sync to target post.' );
		$this->assertContains( $new_tag_name, $tag_names, 'Flat taxonomy tag input should create and associate new tags.' );
	}

	public function test_create_translation_skeleton_creates_draft_target_with_sync_fields() {
		$relation_id    = $this->create_active_relation( 'de_DE' );
		$this->create_model_rule_binding( $relation_id, 'post' );
		$attachment_id = $this->create_attachment_fixture();

		$source_post_id = $this->create_source_post(
			array(
				'post_title'   => 'Skeleton Source Title',
				'post_content' => '<p>Skeleton source content</p>',
				'post_excerpt' => 'Skeleton source excerpt',
				'post_status'  => 'publish',
				'post_date'    => '2025-05-06 07:08:09',
				'post_name'    => 'skeleton-source-title',
			),
			$attachment_id,
			array(),
			array()
		);

		$result = \WPTSALL\Sites\Services\Manual_Content_Service::create_translation_skeleton( $source_post_id, $relation_id );
		$this->assertFalse( is_wp_error( $result ), 'create_translation_skeleton should succeed.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'target_id', $result );

		$target_id = (int) $result['target_id'];
		$this->target_post_ids[] = $target_id;
		$target_post = get_post( $target_id );
		$source_post = get_post( $source_post_id );

		$this->assertNotNull( $target_post, 'Skeleton target post should exist.' );
		$this->assertEquals( 'draft', $target_post->post_status, 'Skeleton target should be created as draft.' );
		$this->assertEquals( $source_post->post_date, $target_post->post_date, 'Skeleton should preserve sync field post_date from source.' );
		$this->assertEquals( $source_post->post_title, $target_post->post_title, 'Skeleton should preserve source title fallback when translate fields are empty.' );
		$this->assertEquals( '', (string) $target_post->post_content, 'Skeleton translate field post_content should be empty.' );
		$this->assertEquals( '', (string) $target_post->post_excerpt, 'Skeleton translate field post_excerpt should be empty.' );
		$this->assertNotEmpty( get_post_meta( $target_id, '_wptsall_last_synced', true ) );
		$this->assertEquals( (string) $source_post_id, (string) get_post_meta( $target_id, '_wptsall_source_post_id', true ) );
		$this->assertEquals( (string) $relation_id, (string) get_post_meta( $target_id, '_wptsall_relation_id', true ) );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $target_id ), 'Skeleton should carry media id_mapping into target.' );
	}

	/**
	 * Ensure DB tables required by relation/rule tests exist.
	 *
	 * @return void
	 */
	private function ensure_required_tables() {
		if ( function_exists( 'wptsall_create_site_relations_table' ) ) {
			wptsall_create_site_relations_table();
		}
		if ( function_exists( 'wptsall_create_model_tables' ) ) {
			wptsall_create_model_tables();
		}
		if ( function_exists( 'wptsall_create_relation_models_table' ) ) {
			wptsall_create_relation_models_table();
		}
	}

	/**
	 * Create a virtual active relation row.
	 *
	 * @param string $target_lang Target language.
	 * @return int
	 */
	private function create_active_relation( string $target_lang ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'manual-service-unit-' . uniqid(),
				'target_site_id'   => 'v_manual_unit_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => $target_lang,
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->assertTrue( false !== $inserted, 'Site relation seed should succeed.' );
		$relation_id = (int) $wpdb->insert_id;
		$this->relation_ids[] = $relation_id;

		return $relation_id;
	}

	/**
	 * Create model + rule bindings for a post type with full field classifications.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $post_type   Post type.
	 * @return void
	 */
	private function create_model_rule_binding( int $relation_id, string $post_type ): void {
		global $wpdb;
		$now = current_time( 'mysql' );

		$model_inserted = $wpdb->insert(
			wptsall_table( 'models' ),
			array(
				'plugin_slug'  => 'manual-service-unit-' . uniqid(),
				'plugin_name'  => 'Manual Service Unit',
				'text_domain'  => 'manual-service-unit',
				'post_types'   => wp_json_encode( array( $post_type ) ),
				'taxonomies'   => wp_json_encode( array( 'category', 'post_tag' ) ),
				'status'       => 'active',
				'usage_status' => 'active',
				'is_system'    => 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$this->assertTrue( false !== $model_inserted, 'Model creation should succeed.' );
		$model_id = (int) $wpdb->insert_id;
		$this->model_ids[] = $model_id;

		$bind_inserted = $wpdb->insert(
			wptsall_table( 'relation_models' ),
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
				'created_at'  => $now,
			),
			array( '%d', '%d', '%s' )
		);
		$this->assertTrue( false !== $bind_inserted, 'Relation model binding should succeed.' );

		$field_capabilities = array(
			'post_title'    => array(
				'type'      => 'translate',
				'enabled'   => true,
				'direction' => 'one_way',
			),
			'post_content'  => array(
				'type'      => 'translate',
				'enabled'   => true,
				'direction' => 'one_way',
			),
			'post_excerpt'  => array(
				'type'      => 'translate',
				'enabled'   => true,
				'direction' => 'one_way',
			),
			'post_status'   => array(
				'type'      => 'sync',
				'enabled'   => true,
				'direction' => 'one_way',
			),
			'post_date'     => array(
				'type'      => 'sync',
				'enabled'   => true,
				'direction' => 'one_way',
			),
			'post_name'     => array(
				'type'      => 'compute',
				'enabled'   => true,
				'direction' => 'one_way',
			),
			'_thumbnail_id' => array(
				'type'           => 'id_mapping',
				'reference_type' => 'media',
				'enabled'        => true,
				'direction'      => 'one_way',
			),
		);

		$rule_inserted = $wpdb->insert(
			wptsall_table( 'translation_rules' ),
			array(
				'model_id'           => $model_id,
				'name'               => 'Manual Service Rule ' . uniqid(),
				'url_pattern'        => '/manual-service/' . $post_type . '/{slug}/',
				'url_type'           => 'single',
				'data_type'          => 'post',
				'object_name'        => $post_type,
				'field_capabilities' => wp_json_encode( $field_capabilities ),
				'related_taxonomies' => wp_json_encode( array( 'category', 'post_tag' ) ),
				'is_active'          => 1,
				'priority'           => 10,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		$this->assertTrue( false !== $rule_inserted, 'Translation rule creation should succeed.' );
		$this->rule_ids[] = (int) $wpdb->insert_id;
	}

	/**
	 * Create source category/tag terms.
	 *
	 * @return int[]
	 */
	private function create_source_terms(): array {
		$category_id = $this->create_term( 'category', 'Manual Unit Category ' . uniqid() );
		$tag_id      = $this->create_term( 'post_tag', 'manual-unit-tag-' . uniqid() );

		return array( $category_id, $tag_id );
	}

	/**
	 * Create one taxonomy term and track for cleanup.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Term name.
	 * @return int
	 */
	private function create_term( string $taxonomy, string $name ): int {
		$created = wp_insert_term( $name, $taxonomy );
		$this->assertFalse( is_wp_error( $created ), 'Term creation should succeed for taxonomy: ' . $taxonomy );
		$term_id = (int) $created['term_id'];
		$this->created_terms[] = array(
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
		);
		return $term_id;
	}

	/**
	 * Create a source post fixture and attach media/taxonomies.
	 *
	 * @param array $postarr       Post args.
	 * @param int   $attachment_id Attachment ID.
	 * @param array $categories    Category IDs.
	 * @param array $tags          Tag IDs.
	 * @return int
	 */
	private function create_source_post( array $postarr, int $attachment_id, array $categories, array $tags ): int {
		$postarr = wp_parse_args(
			$postarr,
			array(
				'post_title'   => 'Manual Unit Source ' . uniqid(),
				'post_content' => '<p>Manual unit source content</p>',
				'post_excerpt' => 'Manual unit source excerpt',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_name'    => 'manual-unit-source-' . uniqid(),
			)
		);

		$post_id = wp_insert_post( $postarr, true );
		$this->assertFalse( is_wp_error( $post_id ), 'Source post creation should succeed.' );
		$post_id = (int) $post_id;
		$this->source_post_ids[] = $post_id;

		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		if ( ! empty( $categories ) ) {
			wp_set_post_terms( $post_id, array_map( 'intval', $categories ), 'category', false );
		}

		if ( ! empty( $tags ) ) {
			wp_set_post_terms( $post_id, array_map( 'intval', $tags ), 'post_tag', false );
		}

		return $post_id;
	}

	/**
	 * Create a real image attachment fixture from core image assets.
	 *
	 * @return int
	 */
	private function create_attachment_fixture(): int {
		$source_file = ABSPATH . 'wp-admin/images/wordpress-logo.png';
		$this->assertTrue( file_exists( $source_file ), 'Core image fixture file should exist for attachment tests.' );

		$upload_dir = wp_upload_dir();
		$this->assertFalse( ! empty( $upload_dir['error'] ), 'Upload dir should be writable for attachment fixture.' );
		$destination = trailingslashit( $upload_dir['path'] ) . 'manual-unit-' . uniqid() . '.png';

		$copied = copy( $source_file, $destination );
		$this->assertTrue( $copied, 'Attachment fixture image copy should succeed.' );

		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Manual Unit Image ' . uniqid(),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $destination );
		$this->assertFalse( is_wp_error( $attachment_id ), 'Attachment fixture insert should succeed.' );
		$attachment_id = (int) $attachment_id;
		$this->attachment_ids[] = $attachment_id;

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $destination );
		if ( ! is_wp_error( $metadata ) && is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return $attachment_id;
	}

	/**
	 * Get post mapping rows by source/target pair.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $target_post_id Target post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_post_mapping_rows( int $source_post_id, int $target_post_id ): array {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_post_id, target_post_id, relationship_type
				 FROM {$table}
				 WHERE source_post_id = %d AND target_post_id = %d",
				$source_post_id,
				$target_post_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
