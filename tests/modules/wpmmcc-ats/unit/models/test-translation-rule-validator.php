<?php
/**
 * Translation Rule Validator Tests
 *
 * Tests for WPTSALL\Models\Validators\Translation_Rule_Validator class
 *
 * @package WPTSALL
 * @since 0.10.0
 */

use WPTSALL\Models\Validators\Translation_Rule_Validator;

class Test_Translation_Rule_Validator extends WP_UnitTestCase {

	/**
	 * Validator instance
	 *
	 * @var Translation_Rule_Validator
	 */
	private $validator;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		$this->validator = new Translation_Rule_Validator();
	}

	// ========================================
	// Static Methods Tests
	// ========================================

	/**
	 * Test get_url_types returns valid array
	 */
	public function test_get_url_types_returns_array() {
		$url_types = Translation_Rule_Validator::get_url_types();

		$this->assertIsArray( $url_types );
		$this->assertNotEmpty( $url_types );
		$this->assertArrayHasKey( 'single', $url_types );
		$this->assertArrayHasKey( 'archive', $url_types );
		$this->assertArrayHasKey( 'taxonomy', $url_types );
	}

	/**
	 * Test get_url_types contains expected types
	 */
	public function test_get_url_types_contains_expected() {
		$url_types = Translation_Rule_Validator::get_url_types();

		$expected = array( 'single', 'archive', 'taxonomy', 'author', 'date', 'search', 'home', 'page', 'attachment', 'embed', 'feed', 'endpoint' );

		foreach ( $expected as $type ) {
			$this->assertArrayHasKey( $type, $url_types, "URL type '{$type}' should exist" );
		}
	}

	/**
	 * Test get_data_types returns valid array
	 */
	public function test_get_data_types_returns_array() {
		$data_types = Translation_Rule_Validator::get_data_types();

		$this->assertIsArray( $data_types );
		$this->assertNotEmpty( $data_types );
		$this->assertArrayHasKey( 'post', $data_types );
		$this->assertArrayHasKey( 'term', $data_types );
		$this->assertArrayHasKey( 'user', $data_types );
	}

	/**
	 * Test get_data_types contains expected types
	 */
	public function test_get_data_types_contains_expected() {
		$data_types = Translation_Rule_Validator::get_data_types();

		$expected = array( 'post', 'term', 'user', 'comment', 'option', 'custom_table' );

		foreach ( $expected as $type ) {
			$this->assertArrayHasKey( $type, $data_types, "Data type '{$type}' should exist" );
		}
	}

	// ========================================
	// URL Pattern Validation Tests
	// ========================================

	/**
	 * Test empty url_pattern fails validation
	 */
	public function test_validate_empty_url_pattern_fails() {
		$rule = array(
			'url_pattern' => '',
			'url_type'    => 'single',
			'data_type'   => 'post',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'url_pattern', $result['errors'] );
	}

	/**
	 * Test url_pattern not starting with / fails
	 */
	public function test_validate_url_pattern_without_leading_slash_fails() {
		$rule = array(
			'url_pattern' => 'product/{slug}/',
			'url_type'    => 'single',
			'data_type'   => 'post',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'url_pattern', $result['errors'] );
	}

	/**
	 * Test url_pattern with invalid characters fails
	 */
	public function test_validate_url_pattern_with_invalid_chars_fails() {
		$invalid_patterns = array(
			'/product/<slug>/',
			'/product/"slug"/',
			"/product/'slug'/",
			'/product/ slug/',
		);

		foreach ( $invalid_patterns as $pattern ) {
			$rule = array(
				'url_pattern' => $pattern,
				'url_type'    => 'single',
				'data_type'   => 'post',
				'object_name' => 'post',
			);

			$result = $this->validator->validate( $rule, 1 );

			$this->assertFalse( $result['valid'], "Pattern '{$pattern}' should fail validation" );
		}
	}

	/**
	 * Test single url_type requires placeholder
	 */
	public function test_validate_single_url_type_requires_placeholder() {
		$rule = array(
			'url_pattern' => '/products/',
			'url_type'    => 'single',
			'data_type'   => 'post',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'url_pattern', $result['errors'] );
	}

	/**
	 * Test archive url_type does not require placeholder
	 */
	public function test_validate_archive_url_type_no_placeholder_ok() {
		$rule = array(
			'url_pattern' => '/products/',
			'url_type'    => 'archive',
			'data_type'   => 'post',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		// Should not have url_pattern error about placeholder
		$url_errors = $result['errors']['url_pattern'] ?? array();
		$has_placeholder_error = false;
		foreach ( $url_errors as $err ) {
			if ( strpos( $err['message'], '占位符' ) !== false ) {
				$has_placeholder_error = true;
			}
		}
		$this->assertFalse( $has_placeholder_error, 'Archive URL type should not require placeholder' );
	}

	/**
	 * Test valid url_pattern with {slug} passes
	 */
	public function test_validate_valid_url_pattern_with_slug() {
		$rule = array(
			'url_pattern'        => '/product/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test valid url_pattern with {id} passes
	 */
	public function test_validate_valid_url_pattern_with_id() {
		$rule = array(
			'url_pattern'        => '/post/{id}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertTrue( $result['valid'] );
	}

	// ========================================
	// URL Type Validation Tests
	// ========================================

	/**
	 * Test empty url_type fails validation
	 */
	public function test_validate_empty_url_type_fails() {
		$rule = array(
			'url_pattern' => '/product/{slug}/',
			'url_type'    => '',
			'data_type'   => 'post',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'url_type', $result['errors'] );
	}

	/**
	 * Test invalid url_type fails validation
	 */
	public function test_validate_invalid_url_type_fails() {
		$rule = array(
			'url_pattern' => '/product/{slug}/',
			'url_type'    => 'invalid_type',
			'data_type'   => 'post',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'url_type', $result['errors'] );
	}

	/**
	 * Test all valid url_types pass validation
	 */
	public function test_validate_all_valid_url_types() {
		$url_types = array_keys( Translation_Rule_Validator::get_url_types() );

		foreach ( $url_types as $url_type ) {
			// Skip types that don't require placeholder
			$no_placeholder_types = array( 'archive', 'home', 'search', 'feed' );
			$pattern = in_array( $url_type, $no_placeholder_types, true )
				? '/test/'
				: '/test/{slug}/';

			$rule = array(
				'url_pattern'        => $pattern,
				'url_type'           => $url_type,
				'data_type'          => 'post',
				'object_name'        => 'post',
				'field_capabilities' => array(
					'post_title' => array( 'type' => 'translate', 'enabled' => true ),
				),
			);

			$result = $this->validator->validate( $rule, 1 );

			$this->assertArrayNotHasKey( 'url_type', $result['errors'], "URL type '{$url_type}' should be valid" );
		}
	}

	// ========================================
	// Data Source Validation Tests
	// ========================================

	/**
	 * Test empty data_type fails validation
	 */
	public function test_validate_empty_data_type_fails() {
		$rule = array(
			'url_pattern' => '/product/{slug}/',
			'url_type'    => 'single',
			'data_type'   => '',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'data_type', $result['errors'] );
	}

	/**
	 * Test invalid data_type fails validation
	 */
	public function test_validate_invalid_data_type_fails() {
		$rule = array(
			'url_pattern' => '/product/{slug}/',
			'url_type'    => 'single',
			'data_type'   => 'invalid_type',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'data_type', $result['errors'] );
	}

	/**
	 * Test empty object_name fails validation
	 */
	public function test_validate_empty_object_name_fails() {
		$rule = array(
			'url_pattern' => '/product/{slug}/',
			'url_type'    => 'single',
			'data_type'   => 'post',
			'object_name' => '',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'object_name', $result['errors'] );
	}

	/**
	 * Test non-existent post_type fails validation
	 */
	public function test_validate_nonexistent_post_type_fails() {
		$rule = array(
			'url_pattern' => '/product/{slug}/',
			'url_type'    => 'single',
			'data_type'   => 'post',
			'object_name' => 'nonexistent_post_type_' . uniqid(),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'object_name', $result['errors'] );
	}

	/**
	 * Test existing post_type passes validation
	 */
	public function test_validate_existing_post_type_passes() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post', // WordPress core post type
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test non-existent taxonomy fails validation
	 */
	public function test_validate_nonexistent_taxonomy_fails() {
		$rule = array(
			'url_pattern' => '/category/{slug}/',
			'url_type'    => 'taxonomy',
			'data_type'   => 'term',
			'object_name' => 'nonexistent_taxonomy_' . uniqid(),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'object_name', $result['errors'] );
	}

	/**
	 * Test existing taxonomy passes validation
	 */
	public function test_validate_existing_taxonomy_passes() {
		$rule = array(
			'url_pattern'        => '/category/{slug}/',
			'url_type'           => 'taxonomy',
			'data_type'          => 'term',
			'object_name'        => 'category', // WordPress core taxonomy
			'field_capabilities' => array(
				'name' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertTrue( $result['valid'] );
	}

	// ========================================
	// Field Capabilities Validation Tests
	// ========================================

	/**
	 * Test invalid field_capabilities config format fails
	 */
	public function test_validate_invalid_field_config_format_fails() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => array(
				'post_title' => 'invalid_string_config', // Should be array
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'field_capabilities', $result['errors'] );
	}

	/**
	 * Test invalid field type fails validation
	 */
	public function test_validate_invalid_field_type_fails() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'invalid_type', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'field_capabilities', $result['errors'] );
	}

	/**
	 * Test valid field types pass validation
	 */
	public function test_validate_valid_field_types_pass() {
		$valid_types = array( 'translate', 'sync', 'id_mapping', 'mapping', 'compute', 'skip', 'no_sync' );

		foreach ( $valid_types as $type ) {
			$rule = array(
				'url_pattern'        => '/post/{slug}/',
				'url_type'           => 'single',
				'data_type'          => 'post',
				'object_name'        => 'post',
				'field_capabilities' => array(
					'post_title' => array( 'type' => $type, 'enabled' => true ),
				),
			);

			$result = $this->validator->validate( $rule, 1 );

			// Should not have field_capabilities error about type
			$field_errors = $result['errors']['field_capabilities'] ?? array();
			$has_type_error = false;
			foreach ( $field_errors as $err ) {
				if ( ( $err['type'] ?? 'error' ) === 'error' && strpos( $err['message'], '类型' ) !== false ) {
					$has_type_error = true;
				}
			}
			$this->assertFalse( $has_type_error, "Field type '{$type}' should be valid" );
		}
	}

	/**
	 * Test JSON string field_capabilities is parsed correctly
	 */
	public function test_validate_json_string_field_capabilities() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => json_encode( array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			) ),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test no translate fields generates warning, not error
	 */
	public function test_validate_no_translate_fields_generates_warning() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => array(
				'post_date' => array( 'type' => 'sync', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		// Should still be valid (warning, not error)
		$this->assertTrue( $result['valid'] );
		// Should have a warning
		$this->assertNotEmpty( $result['warnings'] );
	}

	// ========================================
	// Backend URL Validation Tests
	// ========================================

	/**
	 * Test backend_edit without wp-admin fails
	 */
	public function test_validate_backend_edit_without_wp_admin_fails() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'backend_edit'       => '/admin/post.php?action=edit&post={id}',
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'backend_edit', $result['errors'] );
	}

	/**
	 * Test backend_edit without {id} placeholder fails
	 */
	public function test_validate_backend_edit_without_id_fails() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'backend_edit'       => '/wp-admin/post.php?action=edit',
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'backend_edit', $result['errors'] );
	}

	/**
	 * Test valid backend_edit passes
	 */
	public function test_validate_valid_backend_edit_passes() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'backend_edit'       => '/wp-admin/post.php?action=edit&post={id}',
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test backend_list without wp-admin fails
	 */
	public function test_validate_backend_list_without_wp_admin_fails() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'backend_list'       => '/admin/edit.php',
			'field_capabilities' => array(
				'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'backend_list', $result['errors'] );
	}

	// ========================================
	// Complete Rule Validation Tests
	// ========================================

	/**
	 * Test complete valid rule passes all validations
	 */
	public function test_validate_complete_valid_rule() {
		$rule = array(
			'url_pattern'        => '/post/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'backend_edit'       => '/wp-admin/post.php?action=edit&post={id}',
			'backend_list'       => '/wp-admin/edit.php',
			'field_capabilities' => array(
				'post_title'   => array( 'type' => 'translate', 'enabled' => true ),
				'post_content' => array( 'type' => 'translate', 'enabled' => true ),
				'post_date'    => array( 'type' => 'sync', 'enabled' => true ),
			),
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( array_filter( $result['errors'], function( $errs ) {
			return ! empty( $errs );
		} ) );
	}

	/**
	 * Test validate returns structured result
	 */
	public function test_validate_returns_structured_result() {
		$rule = array(
			'url_pattern' => '/post/{slug}/',
			'url_type'    => 'single',
			'data_type'   => 'post',
			'object_name' => 'post',
		);

		$result = $this->validator->validate( $rule, 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertIsBool( $result['valid'] );
		$this->assertIsArray( $result['errors'] );
		$this->assertIsArray( $result['warnings'] );
	}
}
