<?php
/**
 * Translation Status Tests
 *
 * Tests for WPTSALL\TranslationStatus\Translation_Status_Column and
 * Translation_Status_Term_Column:
 * - column registration / managed object types from wptsall_models
 * - rendered language flags per object (source / translated / missing)
 *
 * catalog: WP-CLASS-Translation_Status_Column
 * catalog: WP-CLASS-Translation_Status_Term_Column
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 1.4.0
 */

use WPTSALL\TranslationStatus\Translation_Status_Column;
use WPTSALL\TranslationStatus\Translation_Status_Term_Column;
use WPTSALL\Models\Services\Post_Mapping_Service;
use WPTSALL\Models\Services\Term_Mapping_Service;
use WPTSALL\Languages\Services\Language_Service;

class Test_Translation_Status extends SimpleTestCase {

	/**
	 * Model row ids created during a test.
	 *
	 * @var int[]
	 */
	private $model_ids = array();

	/**
	 * Site relation ids created during a test.
	 *
	 * @var int[]
	 */
	private $relation_ids = array();

	/**
	 * Term mapping ids created during a test.
	 *
	 * @var int[]
	 */
	private $term_mapping_ids = array();

	/**
	 * Language codes that existed before the test ran (do not delete these).
	 *
	 * @var array
	 */
	private $preexisting_lang_codes = array();

	/**
	 * Default language id captured in setUp and restored in tearDown.
	 *
	 * The shared Lab DB can carry a default marker left by earlier fixtures;
	 * tests that assert default-language behavior must set AND restore it.
	 *
	 * @var int
	 */
	private $preexisting_default_id = 0;

	/**
	 * Language ids created by this test.
	 *
	 * @var int[]
	 */
	private $created_lang_ids = array();

	private $registered_post_types = array();
	private $registered_taxonomies = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_models_table' ) ) {
			wptsall_create_models_table();
		}
		if ( function_exists( 'wptsall_create_site_relations_table' ) ) {
			wptsall_create_site_relations_table();
		}
		if ( function_exists( 'wptsall_create_field_mapping_tables' ) ) {
			wptsall_create_field_mapping_tables();
		}
		if ( function_exists( 'wptsall_create_languages_table' ) ) {
			wptsall_create_languages_table();
		}
		if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
			wptsall_ensure_relation_scoped_mapping_tables();
		}

		$this->preexisting_lang_codes = array();
		foreach ( Language_Service::get_all( array( 'status' => 'all' ) ) as $row ) {
			$this->preexisting_lang_codes[] = (string) $row['code'];
		}

		$this->preexisting_default_id = 0;
		$default_lang = Language_Service::get_default();
		if ( is_array( $default_lang ) && ! empty( $default_lang['id'] ) ) {
			$this->preexisting_default_id = (int) $default_lang['id'];
		}
	}

	public function tearDown(): void {
		global $wpdb;

		// Remove fixture model rows.
		if ( ! empty( $this->model_ids ) ) {
			$models = wptsall_table( 'models' );
			$ids    = implode( ',', array_map( 'intval', $this->model_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$models} WHERE id IN ({$ids})" );
		}
		// Remove fixture site relations.
		if ( ! empty( $this->relation_ids ) ) {
			$relations = wptsall_table( 'site_relations' );
			$ids       = implode( ',', array_map( 'intval', $this->relation_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$relations} WHERE id IN ({$ids})" );
		}
		// Remove fixture term mappings.
		if ( ! empty( $this->term_mapping_ids ) ) {
			$term_map = wptsall_table( 'term_mappings' );
			$ids      = implode( ',', array_map( 'intval', $this->term_mapping_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$term_map} WHERE id IN ({$ids})" );
		}
		// Remove only languages created by this test.
		foreach ( $this->created_lang_ids as $lang_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->prefix . 'wptsall_languages', array( 'id' => (int) $lang_id ), array( '%d' ) );
		}

		// Restore the default-language marker to the pre-test state: the
		// render fixtures force a specific default via set_default(); leaving
		// it flipped would leak into later suites on the shared Lab DB.
		if ( $this->preexisting_default_id > 0 ) {
			Language_Service::set_default( $this->preexisting_default_id );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wptsall_languages',
				array( 'is_default' => 0 ),
				array( 'is_default' => 1 ),
				array( '%d' ),
				array( '%d' )
			);
		}

		// Unregister test object types registered during the test.
		foreach ( $this->registered_post_types as $pt ) {
			unset( $GLOBALS['wp_post_types'][ $pt ] );
		}
		foreach ( $this->registered_taxonomies as $tx ) {
			unset( $GLOBALS['wp_taxonomies'][ $tx ] );
		}

		Translation_Status_Column::flush_cache();
		Translation_Status_Term_Column::flush_cache();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Ensure a language row exists (created by the test when missing).
	 *
	 * When $is_default is true the row is made the ONLY default via
	 * Language_Service::set_default(). upsert alone would leave a second
	 * default marker on whatever language the shared Lab DB already flagged
	 * (preexisting rows are not touched otherwise), and get_default() then
	 * resolves by sort_order — breaking this fixture's "this code is the
	 * default" premise (2026-09-12 Lab drift: en_US carried the marker).
	 */
	private function ensure_language( $code, $is_default = false ) {
		$existing = Language_Service::get_by_code( $code );
		$id       = ( is_array( $existing ) && ! empty( $existing['id'] ) ) ? (int) $existing['id'] : 0;

		if ( ! $id ) {
			$id = (int) Language_Service::upsert( array(
				'code'       => $code,
				'name'       => $code,
				'status'     => 'active',
				'sort_order' => 10,
			) );
			if ( $id ) {
				$this->created_lang_ids[] = $id;
			}
		}

		if ( $id > 0 && $is_default ) {
			Language_Service::set_default( $id );
		}
	}

	/**
	 * Insert a models row fixture.
	 */
	private function insert_model_row( $args ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok = $wpdb->insert(
			wptsall_table( 'models' ),
			array(
				'plugin_slug'        => 'test-status-' . uniqid(),
				'plugin_name'        => 'Test Status Fixture',
				'is_content_plugin'  => 1,
				'post_types'         => wp_json_encode( $args['post_types'] ?? array() ),
				'taxonomies'         => wp_json_encode( $args['taxonomies'] ?? array() ),
				'meta_fields'       => '[]',
				'custom_tables'      => '[]',
				'status'             => $args['status'] ?? 'active',
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		$this->assertNotFalse( $ok, 'models fixture insert failed: ' . $wpdb->last_error );
		$this->model_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	// ==================== Column registration ====================

	public function test_post_column_classes_exist() {
		$this->assertTrue( class_exists( 'WPTSALL\TranslationStatus\Translation_Status_Column' ) );
		$this->assertTrue( class_exists( 'WPTSALL\TranslationStatus\Translation_Status_Term_Column' ) );
	}

	public function test_post_add_column_inserts_after_title() {
		$in = array(
			'cb'     => '<input type="checkbox" />',
			'title'  => 'Title',
			'author' => 'Author',
			'date'   => 'Date',
		);
		$out = Translation_Status_Column::add_column( $in );

		$this->assertArrayHasKey( Translation_Status_Column::COLUMN_SLUG, $out );
		$keys = array_keys( $out );
		$this->assertEquals(
			array( 'cb', 'title', Translation_Status_Column::COLUMN_SLUG, 'author', 'date' ),
			$keys,
			'translations column must be inserted right after the title column'
		);
		$this->assertEquals( 'Translations', $out[ Translation_Status_Column::COLUMN_SLUG ] );
	}

	public function test_managed_post_types_always_include_post_and_page() {
		Translation_Status_Column::flush_cache();
		$types = Translation_Status_Column::get_managed_post_types();
		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
	}

	public function test_managed_post_types_pull_active_content_plugin_types() {
		register_post_type( 'wptsall_status_cpt', array( 'show_ui' => true, 'public' => true ) );
		// WP caps post type names at 20 chars; use a short slug for the string entry.
		register_post_type( 'wptsall_cpt_str', array( 'show_ui' => true, 'public' => true ) );
		$this->registered_post_types[] = 'wptsall_status_cpt';
		$this->registered_post_types[] = 'wptsall_cpt_str';

		$this->insert_model_row( array(
			'post_types' => array(
				array( 'name' => 'wptsall_status_cpt' ),
				'wptsall_cpt_str',
				'wptsall_status_cpt_missing', // not registered in WP → filtered out
			),
		) );

		Translation_Status_Column::flush_cache();
		$types = Translation_Status_Column::get_managed_post_types();

		$this->assertContains( 'wptsall_status_cpt', $types, 'array entry post type should be included' );
		$this->assertContains( 'wptsall_cpt_str', $types, 'string entry post type should be included' );
		$this->assertNotContains( 'wptsall_status_cpt_missing', $types, 'unregistered post type must be filtered out' );
	}

	public function test_managed_post_types_cache_flush_picks_up_new_models() {
		register_post_type( 'wptsall_status_cpt2', array( 'show_ui' => true, 'public' => true ) );
		$this->registered_post_types[] = 'wptsall_status_cpt2';

		Translation_Status_Column::flush_cache();
		$before = Translation_Status_Column::get_managed_post_types();
		$this->assertNotContains( 'wptsall_status_cpt2', $before );

		$this->insert_model_row( array( 'post_types' => array( array( 'name' => 'wptsall_status_cpt2' ) ) ) );

		// Cached list still stale until flushed.
		$this->assertNotContains( 'wptsall_status_cpt2', Translation_Status_Column::get_managed_post_types() );
		Translation_Status_Column::flush_cache();
		$this->assertContains( 'wptsall_status_cpt2', Translation_Status_Column::get_managed_post_types() );
	}

	public function test_managed_taxonomies_always_include_defaults_and_plugin_types() {
		register_taxonomy( 'wptsall_status_tax', 'post', array( 'show_ui' => true, 'public' => true ) );
		$this->registered_taxonomies[] = 'wptsall_status_tax';

		$this->insert_model_row( array(
			'taxonomies' => array(
				array( 'name' => 'wptsall_status_tax' ),
				'wptsall_status_tax_missing',
			),
		) );

		Translation_Status_Term_Column::flush_cache();
		$taxes = Translation_Status_Term_Column::get_managed_taxonomies();

		$this->assertContains( 'category', $taxes );
		$this->assertContains( 'post_tag', $taxes );
		$this->assertContains( 'wptsall_status_tax', $taxes );
		$this->assertNotContains( 'wptsall_status_tax_missing', $taxes );
	}

	public function test_term_add_column_inserts_after_name() {
		$in  = array( 'cb' => '<input />', 'name' => 'Name', 'slug' => 'Slug', 'posts' => 'Count' );
		$out = Translation_Status_Term_Column::add_column( $in );

		$this->assertArrayHasKey( Translation_Status_Term_Column::COLUMN_SLUG, $out );
		$keys = array_keys( $out );
		$this->assertEquals(
			array( 'cb', 'name', Translation_Status_Term_Column::COLUMN_SLUG, 'slug', 'posts' ),
			$keys,
			'term translations column must be inserted right after the name column'
		);
	}

	// ==================== Rendering ====================

	public function test_render_post_column_outputs_source_translated_and_missing_flags() {
		$this->ensure_language( 'zh_CN', true );
		$this->ensure_language( 'en_US' );
		$this->ensure_language( 'fr_FR' );

		$source_post = self::factory()->post->create();
		$target_post = self::factory()->post->create();

		// Site relation fixture for en_US.
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok = $wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id' => 1,
				'source_lang'    => 'zh_CN',
				'template'       => 'test-status-' . uniqid(),
				'target_site_id' => 'v_en',
				'target_site_type' => 'virtual',
				'target_lang'    => 'en_US',
				'status'         => 'active',
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$this->assertTrue( (bool) $ok, 'site_relations fixture insert failed: ' . $wpdb->last_error );
		$relation_id = (int) $wpdb->insert_id;
		$this->relation_ids[] = $relation_id;

		$mapping_id = Post_Mapping_Service::create_mapping( array(
			'source_post_id'   => $source_post,
			'source_post_type' => 'post',
			'source_site_id'   => 1,
			'target_post_id'   => $target_post,
			'target_post_type' => 'post',
			'target_site_id'   => 'v_en',
			'relation_id'      => $relation_id,
		) );
		$this->assertNotFalse( $mapping_id );

		// Editing links require an authenticated user.
		wp_set_current_user( 1 );

		ob_start();
		Translation_Status_Column::render_column( Translation_Status_Column::COLUMN_SLUG, $source_post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wptsall-translation-flags', $html );
		// en_US has a mapping → translated flag linking to the target post.
		$this->assertStringContainsString( 'wptsall-flag-yes', $html );
		$this->assertStringContainsString( sprintf( 'en_US → #%d', $target_post ), $html );
		// zh_CN is the default and this post is the source → source flag.
		$this->assertStringContainsString( 'wptsall-flag-source', $html );
		$this->assertStringContainsString( 'zh_CN', $html );
		// fr_FR has no mapping → missing flag with "add translation" link.
		$this->assertStringContainsString( 'wptsall-flag-missing', $html );
		$this->assertStringContainsString( '+fr_FR', $html );
	}

	public function test_render_post_column_ignores_other_columns() {
		ob_start();
		Translation_Status_Column::render_column( 'some_other_column', 1 );
		$html = ob_get_clean();
		$this->assertSame( '', $html, 'render_column must not output anything for other columns' );
	}

	public function test_render_term_column_returns_source_translated_and_missing_flags() {
		$this->ensure_language( 'zh_CN', true );
		$this->ensure_language( 'en_US' );
		$this->ensure_language( 'fr_FR' );

		$source_term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$target_term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$mapping_id = Term_Mapping_Service::create_mapping( array(
			'source_term_id'  => $source_term,
			'source_taxonomy' => 'category',
			'source_site_id'  => 1,
			'source_lang'     => 'zh_CN',
			'target_term_id'  => $target_term,
			'target_taxonomy' => 'category',
			'target_site_id'  => 'v_en',
			'target_lang'     => 'en_US',
		) );
		$this->assertNotFalse( $mapping_id );
		$this->term_mapping_ids[] = (int) $mapping_id;

		wp_set_current_user( 1 );

		$out = Translation_Status_Term_Column::render_column( '', Translation_Status_Term_Column::COLUMN_SLUG, $source_term );
		$this->assertIsString( $out );

		$this->assertStringContainsString( 'wptsall-translation-flags', $out );
		$this->assertStringContainsString( 'wptsall-flag-yes', $out );
		$this->assertStringContainsString( sprintf( 'en_US → #%d', $target_term ), $out );
		$this->assertStringContainsString( 'wptsall-flag-source', $out );
		$this->assertStringContainsString( 'zh_CN', $out );
		$this->assertStringContainsString( 'wptsall-flag-missing', $out );
		$this->assertStringContainsString( '+fr_FR', $out );
	}

	public function test_render_term_column_passthrough_for_other_columns() {
		$this->assertSame( 'unchanged', Translation_Status_Term_Column::render_column( 'unchanged', 'some_other_column', 42 ) );
	}
}
