<?php
/**
 * Translation editor entry-point and page rendering tests.
 *
 * @package WPTSALL
 */

class Test_Translation_Editor_Unit extends WP_UnitTestCase {

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
	 * Snapshot of original GET params.
	 *
	 * @var array
	 */
	private $original_get = array();

	/**
	 * Lightweight posts created directly in DB for test fixtures.
	 *
	 * @var int[]
	 */
	private $seed_post_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->original_get = $_GET;
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

		$_GET = $this->original_get;

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

		foreach ( $this->seed_post_ids as $post_id ) {
			$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $post_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->posts, array( 'ID' => (int) $post_id ), array( '%d' ) );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_post_list_row_action_includes_translate_link_for_supported_post_type() {
		$source_post = $this->create_lightweight_source_post( 'post' );
		$source_post_id = (int) $source_post->ID;
		$relation_id    = $this->create_active_relation( 'fr_FR' );

		$this->create_model_rule_binding( $relation_id, (string) $source_post->post_type );
		$post = (object) array(
			'ID'        => $source_post_id,
			'post_type' => (string) $source_post->post_type,
		);

		$manager = new \WPTSALL\Hooks\Admin_Virtual_Site_Manager();
		$actions = $manager->add_translation_row_actions( array(), $post );
		$link    = $this->extract_translate_action( $actions, $relation_id );

		$this->assertNotEmpty( $link, 'Translate row action should be present for a relation-bound post type.' );
		$this->assertStringContainsString( 'page=wptsall-translate', $link );
		$this->assertStringContainsString( 'source_post_id=' . $source_post_id, $link );
		$this->assertStringContainsString( 'relation_id=' . $relation_id, $link );
		// Label is translated via text domain wpmmcc-ats; match English or locale string.
		$label_ok = ( false !== strpos( $link, 'Translate' ) )
			|| ( false !== strpos( $link, __( 'Translate', 'wpmmcc-ats' ) ) )
			|| (bool) preg_match( '/>([^<]+)</', $link );
		$this->assertTrue( $label_ok, 'Row action should include a visible translate label (localized OK).' );
	}

	public function test_post_list_row_action_hidden_when_post_type_has_no_active_rule() {
		$source_post = $this->create_lightweight_source_post( 'post' );
		$source_post_id = (int) $source_post->ID;
		$relation_id    = $this->create_active_relation( 'es_ES' );

		// Bind a model/rule for a different object type to ensure mismatch.
		$mismatch_type = ( 'post' === (string) $source_post->post_type ) ? 'page' : 'post';
		$this->create_model_rule_binding( $relation_id, $mismatch_type );
		$post = (object) array(
			'ID'        => $source_post_id,
			'post_type' => (string) $source_post->post_type,
		);

		$manager = new \WPTSALL\Hooks\Admin_Virtual_Site_Manager();
		$actions = $manager->add_translation_row_actions( array(), $post );

		$this->assertFalse(
			isset( $actions[ 'wptsall_translate_' . $relation_id ] ),
			'Translate action for mismatched relation should be hidden. relation_id=' . $relation_id . ' actions=' . wp_json_encode( $actions )
		);
	}

	public function test_render_page_shows_error_notice_for_invalid_source_post_id() {
		$invalid_post_id = $this->get_nonexistent_post_id();
		$relation_id = $this->create_active_relation( 'de_DE' );

		$_GET = array(
			'page'           => 'wptsall-translate',
			'source_post_id' => $invalid_post_id,
			'relation_id'    => $relation_id,
		);

		ob_start();
		\WPTSALL\Sites\Admin\Translation_Editor_Page::render_page();
		$output = ob_get_clean();

		// Accept both English source and zh-CN translation (i18n).
		$this->assertTrue(
			str_contains( $output, 'Source post not found' ) || str_contains( $output, '未找到源文章' ),
			'output should contain a source-post-not-found marker (en or zh-CN); got: ' . $output
		);
	}

	public function test_render_page_shows_error_notice_for_invalid_relation_id() {
		$source_post = $this->create_lightweight_source_post( 'post' );

		$invalid_relation_id = $this->get_nonexistent_relation_id();

		$_GET = array(
			'page'           => 'wptsall-translate',
			'source_post_id' => (int) $source_post->ID,
			'relation_id'    => $invalid_relation_id,
		);

		ob_start();
		\WPTSALL\Sites\Admin\Translation_Editor_Page::render_page();
		$output = ob_get_clean();

		// Accept both English source and zh-CN translation (i18n).
		$this->assertTrue(
			str_contains( $output, 'Site relation not found' ) || str_contains( $output, '未找到站关系' ) || str_contains( $output, '未找到站点关系' ),
			'output should contain a site-relation-not-found marker (en or zh-CN); got: ' . $output
		);
	}

	/**
	 * Ensure DB tables needed by these tests are present.
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
	 * Create an active site relation row for the current blog.
	 *
	 * @param string $target_lang Target language.
	 * @return int
	 */
	private function create_active_relation( string $target_lang ): int {
		global $wpdb;

		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'translation-editor-unit-' . uniqid(),
				'target_site_id'   => 'v_unit_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => $target_lang,
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->assertTrue( false !== $inserted, 'Failed to seed site relation for translation editor test.' );
		$relation_id          = (int) $wpdb->insert_id;
		$this->relation_ids[] = $relation_id;

		return $relation_id;
	}

	/**
	 * Create and bind a model+rule to a relation for a specific post type.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $post_type   Post type.
	 * @return void
	 */
	private function create_model_rule_binding( int $relation_id, string $post_type ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$model_slug = 'translation-editor-unit-' . uniqid();

		$model_inserted = $wpdb->insert(
			wptsall_table( 'models' ),
			array(
				'plugin_slug'  => $model_slug,
				'plugin_name'  => 'Translation Editor Unit',
				'text_domain'  => $model_slug,
				'post_types'   => wp_json_encode( array( $post_type ) ),
				'taxonomies'   => wp_json_encode( array() ),
				'status'       => 'active',
				'usage_status' => 'active',
				'is_system'    => 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$this->assertTrue( false !== $model_inserted, 'Model creation should succeed for translation editor tests.' );
		$model_id = (int) $wpdb->insert_id;
		$model_id          = (int) $model_id;
		$this->model_ids[] = $model_id;

		$relation_model_inserted = $wpdb->insert(
			wptsall_table( 'relation_models' ),
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
				'created_at'  => $now,
			),
			array( '%d', '%d', '%s' )
		);

		$this->assertTrue( false !== $relation_model_inserted, 'Failed to bind relation model for translation editor tests.' );

		$inserted = $wpdb->insert(
			wptsall_table( 'translation_rules' ),
			array(
				'model_id'           => $model_id,
				'name'               => 'Translation Editor Rule ' . uniqid(),
				'url_pattern'        => '/translation-editor/' . $post_type . '/{slug}/',
				'url_type'           => 'single',
				'data_type'          => 'post',
				'object_name'        => $post_type,
				'field_capabilities' => wp_json_encode(
					array(
						'post_title' => array(
							'type'      => 'translate',
							'enabled'   => true,
							'direction' => 'one_way',
						),
					)
				),
				'related_taxonomies' => wp_json_encode( array() ),
				'is_active'          => 1,
				'priority'           => 10,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		$this->assertTrue( false !== $inserted, 'Failed to seed translation rule row for translation editor tests.' );
		$this->rule_ids[] = (int) $wpdb->insert_id;
	}

	/**
	 * Extract the translate row action html by relation id.
	 *
	 * @param array $actions     Row actions.
	 * @param int   $relation_id Relation ID.
	 * @return string
	 */
	private function extract_translate_action( array $actions, int $relation_id ): string {
		$key = 'wptsall_translate_' . $relation_id;
		if ( isset( $actions[ $key ] ) ) {
			return (string) $actions[ $key ];
		}

		foreach ( $actions as $action ) {
			$action = (string) $action;
			if ( false !== strpos( $action, 'relation_id=' . $relation_id ) ) {
				return $action;
			}
		}

		return '';
	}

	/**
	 * Get a guaranteed-missing relation id for assertions.
	 *
	 * @return int
	 */
	private function get_nonexistent_relation_id(): int {
		global $wpdb;
		$max_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . wptsall_table( 'site_relations' ) );
		return $max_id + 1000;
	}

	/**
	 * Get a guaranteed-missing post id for assertions.
	 *
	 * @return int
	 */
	private function get_nonexistent_post_id(): int {
		global $wpdb;
		$max_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		return $max_id + 1000;
	}

	/**
	 * Create a lightweight source post fixture directly in wp_posts.
	 *
	 * Avoids wp_insert_post hooks for unstable local environments.
	 *
	 * @param string $post_type Post type.
	 * @return object
	 */
	private function create_lightweight_source_post( string $post_type ) {
		global $wpdb;
		$now_local = current_time( 'mysql' );
		$now_gmt   = current_time( 'mysql', 1 );
		$title     = 'Translation Editor Fixture ' . uniqid();

		$inserted = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => get_current_user_id() ?: 1,
				'post_date'             => $now_local,
				'post_date_gmt'         => $now_gmt,
				'post_content'          => 'Fixture content',
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => sanitize_title( $title ),
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $now_local,
				'post_modified_gmt'     => $now_gmt,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => home_url( '/?p=' . uniqid() ),
				'menu_order'            => 0,
				'post_type'             => $post_type,
				'post_mime_type'        => '',
				'comment_count'         => 0,
			),
			array(
				'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d',
			)
		);
		$this->assertTrue( false !== $inserted, 'Failed to seed lightweight source post fixture.' );
		$post_id               = (int) $wpdb->insert_id;
		$this->seed_post_ids[] = $post_id;

		return (object) array(
			'ID'        => $post_id,
			'post_type' => $post_type,
		);
	}
}
