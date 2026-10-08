<?php
/**
 * Custom Field Translation Service Tests
 *
 * Tests for WPTSALL\CustomFields\Services\Custom_Field_Translation_Service:
 * - meta field resolution for a post from active wptsall_models rows
 * - string registration on post save (on_save_post)
 * - frontend value swap through the meta filter (filter_meta)
 *
 * catalog: WP-CLASS-Custom_Field_Translation_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 1.3.0
 */

use WPTSALL\CustomFields\Services\Custom_Field_Translation_Service;
use WPTSALL\Strings\Services\String_Translation_Service;
use WPTSALL\Core\Language_Context;

class Test_Custom_Field_Translation_Service extends SimpleTestCase {

	/**
	 * Model fixture row ids.
	 *
	 * @var int[]
	 */
	private $model_ids = array();

	/**
	 * Registered string row ids.
	 *
	 * @var int[]
	 */
	private $string_ids = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_models_table' ) ) {
			wptsall_create_models_table();
		}
		if ( function_exists( 'wptsall_create_strings_table' ) ) {
			wptsall_create_strings_table();
		}
	}

	public function tearDown(): void {
		global $wpdb;

		// Remove model fixture rows (identified by slug prefix).
		$models = wptsall_table( 'models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE plugin_slug LIKE %s', $models, 'test-cf-%' ) );
		$this->model_ids = array();

		// Remove registered string rows.
		if ( ! empty( $this->string_ids ) ) {
			$strings = wptsall_table( 'strings' );
			$ids     = implode( ',', array_map( 'intval', array_unique( $this->string_ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$strings} WHERE id IN ({$ids})" );
		}

		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			Language_Context::reset();
		}
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		parent::tearDown();
	}

	/**
	 * Insert a models fixture row whose meta_fields contain the given entries.
	 */
	private function insert_model_row( $status, $meta_fields ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok = $wpdb->insert(
			wptsall_table( 'models' ),
			array(
				'plugin_slug'        => 'test-cf-' . uniqid(),
				'plugin_name'        => 'Test CF Fixture',
				'is_content_plugin'  => 1,
				'post_types'         => wp_json_encode( array( 'post' ) ),
				'taxonomies'         => '[]',
				'meta_fields'        => wp_json_encode( $meta_fields ),
				'custom_tables'      => '[]',
				'status'             => $status,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		$this->assertTrue( (bool) $ok, 'models fixture insert failed: ' . $wpdb->last_error );
		$this->model_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	/**
	 * Find the string row registered for a given field key of a post.
	 */
	private function find_registered_field_string( $post_id, $meta_key ) {
		$expected_key = 'field_' . sanitize_key( $meta_key ) . '_' . (int) $post_id;
		foreach ( String_Translation_Service::list( array( 'context' => Custom_Field_Translation_Service::CONTEXT, 'limit' => 500 ) ) as $row ) {
			if ( $expected_key === (string) $row['string_key'] ) {
				$this->string_ids[] = (int) $row['id'];
				return $row;
			}
		}
		return null;
	}

	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\CustomFields\Services\Custom_Field_Translation_Service' ) );
		$this->assertEquals( 'cpt_field', Custom_Field_Translation_Service::CONTEXT );
	}

	public function test_get_meta_fields_for_post_filters_by_subtype_and_active_status() {
		$this->insert_model_row( 'active', array(
			array(
				'object_type'    => 'post',
				'object_subtype' => 'post',
				'meta_key'       => 'cf_subtitle',
			),
			array(
				'object_type'    => 'post',
				'object_subtype' => 'page',
				'meta_key'       => 'cf_page_only',
			),
			array(
				'object_type'    => 'post',
				'object_subtype' => 'post',
				// Empty meta_key entries must be dropped.
			),
		) );

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'post' ) );
		$fields = Custom_Field_Translation_Service::get_meta_fields_for_post( $post );

		$this->assertCount( 1, $fields, 'only the entry matching post_type=post with a meta_key should be returned' );
		$this->assertEquals( 'cf_subtitle', $fields[0]['meta_key'] );

		// An inactive model with the same fields must be ignored.
		$this->insert_model_row( 'inactive', array(
			array(
				'object_type'    => 'post',
				'object_subtype' => 'post',
				'meta_key'       => 'cf_from_inactive',
			),
		) );
		$fields = Custom_Field_Translation_Service::get_meta_fields_for_post( $post );
		$this->assertCount( 1, $fields, 'inactive model rows must not contribute meta fields' );
		$this->assertEquals( 'cf_subtitle', $fields[0]['meta_key'] );
	}

	public function test_get_meta_fields_for_post_ignores_malformed_rows() {
		// JSON that does not decode to an array must be skipped.
		$this->insert_model_row( 'active', 'not-a-json-entry-list' ); // encodes to a JSON scalar string, not an entry array.
		$post = self::factory()->post->create_and_get();
		$this->assertSame( array(), Custom_Field_Translation_Service::get_meta_fields_for_post( $post ) );
	}

	public function test_get_meta_fields_for_post_requires_post_object() {
		$this->insert_model_row( 'active', array(
			array( 'object_subtype' => 'post', 'meta_key' => 'cf_x' ),
		) );
		$this->assertSame( array(), Custom_Field_Translation_Service::get_meta_fields_for_post( '' ) );
		$this->assertSame( array(), Custom_Field_Translation_Service::get_meta_fields_for_post( null ) );
	}

	public function test_on_save_post_registers_meta_values_as_strings() {
		$this->insert_model_row( 'active', array(
			array( 'object_type' => 'post', 'object_subtype' => 'post', 'meta_key' => 'cf_tagline' ),
			array( 'object_type' => 'post', 'object_subtype' => 'post', 'meta_key' => 'cf_empty' ),
		) );

		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'cf_tagline', 'Hello Tagline' );
		update_post_meta( $post->ID, 'cf_empty', '' );

		Custom_Field_Translation_Service::on_save_post( $post->ID, $post, true );

		$row = $this->find_registered_field_string( $post->ID, 'cf_tagline' );
		$this->assertNotNull( $row, 'on_save_post should register the meta value as a cpt_field string' );
		$this->assertEquals( 'Hello Tagline', $row['source_text'] );

		// Empty meta values must not be registered.
		$this->assertNull( $this->find_registered_field_string( $post->ID, 'cf_empty' ) );
	}

	public function test_on_save_post_skips_non_string_meta_and_revisions() {
		$this->insert_model_row( 'active', array(
			array( 'object_type' => 'post', 'object_subtype' => 'post', 'meta_key' => 'cf_array' ),
		) );

		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'cf_array', array( 'nested' => 'value' ) );

		Custom_Field_Translation_Service::on_save_post( $post->ID, $post, true );
		$this->assertNull( $this->find_registered_field_string( $post->ID, 'cf_array' ), 'array meta values must not be registered as strings' );

		// Non-object $post is rejected silently.
		Custom_Field_Translation_Service::on_save_post( $post->ID, 'not-a-post', true );
		$this->assertNull( $this->find_registered_field_string( $post->ID, 'cf_array' ) );
	}

	public function test_filter_meta_swaps_single_value_in_target_language() {
		$this->insert_model_row( 'active', array(
			array( 'object_type' => 'post', 'object_subtype' => 'post', 'meta_key' => 'cf_swappable' ),
		) );

		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'cf_swappable', 'Original subtitle' );

		// Register + translate via the same path the save hook uses.
		Custom_Field_Translation_Service::on_save_post( $post->ID, $post, true );
		$row = $this->find_registered_field_string( $post->ID, 'cf_swappable' );
		$this->assertNotNull( $row );
		$this->assertTrue( String_Translation_Service::set_translations( (int) $row['id'], array( 'en_US' => 'Translated subtitle' ) ) );

		Language_Context::set_language( 'en_US' );

		$single = Custom_Field_Translation_Service::filter_meta( null, $post->ID, 'cf_swappable', true );
		$this->assertEquals( 'Translated subtitle', $single );

		$multi = Custom_Field_Translation_Service::filter_meta( null, $post->ID, 'cf_swappable', false );
		$this->assertEquals( array( 'Translated subtitle' ), $multi );
	}

	public function test_filter_meta_passthrough_without_translation_or_language() {
		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'cf_passthrough', 'Raw value' );

		// No target language context at all: value passes through untouched.
		$this->assertNull( Custom_Field_Translation_Service::filter_meta( null, $post->ID, 'cf_passthrough', true ) );

		// Target language set but no translation registered: the original
		// $value is returned so WordPress proceeds with normal meta loading.
		Language_Context::set_language( 'fr_FR' );
		$this->assertNull( Custom_Field_Translation_Service::filter_meta( null, $post->ID, 'cf_passthrough', true ) );
		$this->assertEquals( 'Raw value', get_post_meta( $post->ID, 'cf_passthrough', true ), 'raw meta stays readable when no translation exists' );
	}

	public function test_filter_meta_ignores_empty_meta_key() {
		Language_Context::set_language( 'en_US' );
		$this->assertNull( Custom_Field_Translation_Service::filter_meta( null, 12345, '', true ) );
	}

	public function test_init_registers_save_and_meta_hooks() {
		Custom_Field_Translation_Service::init();
		$this->assertNotFalse(
			has_action( 'save_post', array( 'WPTSALL\CustomFields\Services\Custom_Field_Translation_Service', 'on_save_post' ) ),
			'init() should hook on_save_post to save_post'
		);
		$this->assertNotFalse(
			has_filter( 'get_post_metadata', array( 'WPTSALL\CustomFields\Services\Custom_Field_Translation_Service', 'filter_meta' ) ),
			'init() should hook filter_meta to get_post_metadata'
		);
	}
}
