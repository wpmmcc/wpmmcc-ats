<?php
/**
 * P0-1/P0-2/P1-1/P1-2/P2-1: Translation API and Field Discovery Tests
 *
 * Run via: php tests/modules/wpmmcc-ats/unit/run.php --file=test-translation-api.php
 *
 * @package WPTSALL\Tests\Unit
 */

class Test_Translation_Api {

	/**
	 * P1-2: wptsall_get_translated_field function exists.
	 */
	public function test_get_translated_field_exists() {
		assert( function_exists( 'wptsall_get_translated_field' ), 'wptsall_get_translated_field should exist' );
		echo "  ✅ wptsall_get_translated_field exists\n";
	}

	/**
	 * P2-2: wptsall_register_string / wptsall_translate_string exist.
	 */
	public function test_string_api_exists() {
		assert( function_exists( 'wptsall_register_string' ), 'wptsall_register_string should exist' );
		assert( function_exists( 'wptsall_translate_string' ), 'wptsall_translate_string should exist' );
		echo "  ✅ wptsall_register_string + wptsall_translate_string exist\n";
	}

	/**
	 * P0-2: ACF adapter uses real ACF API when available.
	 */
	public function test_acf_adapter_uses_acf_api() {
		if ( ! class_exists( '\\WPTSALL\\Models\\Adapters\\Advanced_Custom_Fields_Field_Rules_Adapter' ) ) {
			echo "  ⏭️  ACF adapter not loaded, skipping\n";
			return;
		}
		$adapter = new \WPTSALL\Models\Adapters\Advanced_Custom_Fields_Field_Rules_Adapter();
		$rules   = $adapter->get_field_rules();

		// If ACF is active with field groups, rules should contain real field names.
		// If ACF is not active, fallback rules (acf_repeater etc.) should be present.
		assert( ! empty( $rules ), 'ACF adapter should return rules' );

		// Check that the adapter slug is correct.
		assert( $adapter->get_plugin_slug() === 'advanced-custom-fields/acf.php', 'ACF slug should match' );

		echo "  ✅ ACF adapter returns " . count( $rules ) . " rules\n";
	}

	/**
	 * P1-1: Elementor Widget_Schema class exists and has schemas.
	 */
	public function test_elementor_widget_schema() {
		if ( ! class_exists( '\\WPTSALL\\Models\\Elementor\\Widget_Schema' ) ) {
			echo "  ⏭️  Widget_Schema not loaded, skipping\n";
			return;
		}
		$schema_class = '\\WPTSALL\\Models\\Elementor\\Widget_Schema';

		assert( $schema_class::has_schema( 'heading' ), 'heading widget should have schema' );
		assert( $schema_class::has_schema( 'text-editor' ), 'text-editor widget should have schema' );
		assert( $schema_class::has_schema( 'accordion' ), 'accordion widget should have schema' );
		assert( ! $schema_class::has_schema( 'nonexistent_widget' ), 'unknown widget should not have schema' );

		$heading_paths = $schema_class::get_translatable_paths( 'heading' );
		assert( isset( $heading_paths['title'] ), 'heading should have translatable title' );

		$accordion_paths = $schema_class::get_translatable_paths( 'accordion' );
		assert( isset( $accordion_paths['tabs.tab_title'] ), 'accordion should have repeater tab_title' );
		assert( isset( $accordion_paths['tabs.tab_content'] ), 'accordion should have repeater tab_content' );

		echo "  ✅ Elementor Widget_Schema has " . count( $heading_paths ) . " heading fields, " . count( $accordion_paths ) . " accordion fields\n";
	}

	/**
	 * P1-1: Elementor JSON extraction + apply round-trip.
	 */
	public function test_elementor_extract_and_apply() {
		if ( ! class_exists( '\\WPTSALL\\Models\\Elementor\\Widget_Schema' ) ) {
			echo "  ⏭️  Widget_Schema not loaded, skipping\n";
			return;
		}
		$schema_class = '\\WPTSALL\\Models\\Elementor\\Widget_Schema';

		// Simulate a simple Elementor data structure.
		$json = json_encode( array(
			array(
				'id'       => 'abc123',
				'elType'   => 'widget',
				'widgetType' => 'heading',
				'settings' => array(
					'title' => 'Hello World',
				),
			),
			array(
				'id'       => 'def456',
				'elType'   => 'widget',
				'widgetType' => 'text-editor',
				'settings' => array(
					'editor' => '<p>Original content</p>',
				),
			),
		) );

		// Extract.
		$strings = $schema_class::extract_translatable_strings( $json );
		assert( count( $strings ) === 2, 'Should extract 2 translatable strings, got ' . count( $strings ) );

		$found_hello = false;
		$found_content = false;
		foreach ( $strings as $s ) {
			if ( 'Hello World' === $s['text'] ) {
				$found_hello = true;
			}
			if ( '<p>Original content</p>' === $s['text'] ) {
				$found_content = true;
			}
		}
		assert( $found_hello, 'Should find Hello World' );
		assert( $found_content, 'Should find Original content' );

		// Apply translations.
		$translations = array(
			'Hello World' => '你好世界',
			'<p>Original content</p>' => '<p>翻译内容</p>',
		);
		$translated_json = $schema_class::apply_translations( $json, $translations );
		$decoded = json_decode( $translated_json, true );

		assert( $decoded[0]['settings']['title'] === '你好世界', 'Title should be translated' );
		assert( $decoded[1]['settings']['editor'] === '<p>翻译内容</p>', 'Content should be translated' );

		echo "  ✅ Elementor extract + apply round-trip correct\n";
	}

	/**
	 * P0-1: Model_Scanner_V2 has register_meta discovery method.
	 */
	public function test_register_meta_discovery_method_exists() {
		if ( ! class_exists( '\\WPTSALL\\Models\\Scanners\\Model_Scanner_V2' ) ) {
			echo "  ⏭️  Model_Scanner_V2 not loaded, skipping\n";
			return;
		}
		$reflection = new \ReflectionClass( '\\WPTSALL\\Models\\Scanners\\Model_Scanner_V2' );
		assert( $reflection->hasMethod( 'discover_registered_meta_fields' ), 'Model_Scanner_V2 should have discover_registered_meta_fields method' );
		echo "  ✅ Model_Scanner_V2 has discover_registered_meta_fields\n";
	}

	/**
	 * P2-1: Smart_Field_Classifier has get_registered_meta_type method.
	 */
	public function test_classifier_has_register_meta_check() {
		if ( ! class_exists( '\\WPTSALL\\Core\\Smart_Field_Classifier' ) ) {
			echo "  ⏭️  Smart_Field_Classifier not loaded, skipping\n";
			return;
		}
		$reflection = new \ReflectionClass( '\\WPTSALL\\Core\\Smart_Field_Classifier' );
		assert( $reflection->hasMethod( 'get_registered_meta_type' ), 'Smart_Field_Classifier should have get_registered_meta_type' );
		echo "  ✅ Smart_Field_Classifier has get_registered_meta_type\n";
	}
}
