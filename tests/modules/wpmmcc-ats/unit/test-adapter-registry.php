<?php
/**
 * P2-2: Field Rules Adapter Registry Test
 *
 * Verifies all registered Plugin_Field_Rules_Adapter implementations
 * return correct plugin slug, non-empty field rules, and valid structure.
 *
 * Run via: php tests/modules/wpmmcc-ats/unit/run.php --file=test-adapter-registry.php
 *
 * @package WPTSALL\Tests\Unit
 */

class Test_Adapter_Registry {

	/**
	 * Registry returns non-empty merged rules.
	 */
	public function test_registry_returns_merged_rules() {
		$all = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::get_all_field_rules();
		assert( ! empty( $all ) );
		assert( count( $all ) >= 10, 'Should have at least 10 merged rules, got ' . count( $all ) );
		echo "  ✅ Registry returns " . count( $all ) . " merged field rules\n";
	}

	/**
	 * Each adapter has non-empty slug and field rules.
	 */
	public function test_each_adapter_returns_valid_rules() {
		$adapters = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::get_adapters();
		assert( count( $adapters ) >= 10, 'Should have at least 10 adapters, got ' . count( $adapters ) );

		$ok_count  = 0;
		$skip_count = 0;
		foreach ( $adapters as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				$skip_count++;
				continue;
			}
			$adapter = new $adapter_class();
			$slug    = $adapter->get_plugin_slug();
			$rules   = $adapter->get_field_rules();
			assert( ! empty( $slug ) );
			assert( is_array( $rules ) );
			assert( ! empty( $rules ), "{$adapter_class} ({$slug}) should return non-empty rules" );
			foreach ( $rules as $meta_key => $rule ) {
				assert( isset( $rule['translatable'] ), "Rule for {$meta_key} missing 'translatable'" );
			}
			$ok_count++;
		}
		echo "  ✅ {$ok_count} adapters validated ({$skip_count} skipped - plugin not installed)\n";
	}

	/**
	 * Wildcard patterns structure.
	 */
	public function test_wildcard_patterns_structure() {
		$patterns = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::get_all_field_patterns();
		foreach ( $patterns as $pattern => $rule ) {
			assert( is_string( $pattern ) );
			assert( is_array( $rule ) );
		}
		echo "  ✅ " . count( $patterns ) . " wildcard patterns valid\n";
	}

	/**
	 * match_meta_key resolves explicit rules before patterns.
	 */
	public function test_match_meta_key_priority() {
		$matched = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( '_yoast_wpseo_title' );
		assert( $matched !== null );
		assert( isset( $matched['translatable'] ) );

		$unmatched = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( '_nonexistent_meta_key_12345' );
		assert( $unmatched === null );
		echo "  ✅ match_meta_key explicit + fallback correct\n";
	}

	/**
	 * JSON hot-plug adapter constructs and exposes rules without PHP plugin adapter.
	 */
	public function test_give_adapter_exposes_frontend_content_rules() {
		$rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( '_give_form_content' );
		assert( is_array( $rule ) );
		assert( 'translate' === $rule['type'] );
		assert( 'rich_html' === $rule['content_format'] );
		assert( true === (bool) $rule['translatable'] );

		$display = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( '_give_display_content' );
		assert( is_array( $display ) );
		assert( 'sync' === $display['type'] );
		assert( false === (bool) $display['translatable'] );
		echo "  ✅ Give adapter exposes frontend content translation rules\n";
	}

	public function test_json_field_rules_adapter_hotplug() {
		$adapter = new \WPTSALL\Models\Adapters\Json_Field_Rules_Adapter(
			'give',
			array(
				'_manual_give_blurb' => array(
					'type'           => 'translate',
					'content_format' => 'plain_text',
					'direction'      => 'one_way',
					'enabled'        => true,
					'source'         => 'manual_json',
					'translatable'   => true,
				),
			),
			array()
		);
		assert( 'give' === $adapter->get_plugin_slug() );
		$rules = $adapter->get_field_rules();
		assert( isset( $rules['_manual_give_blurb'] ) );
		assert( true === (bool) $rules['_manual_give_blurb']['translatable'] );
		assert( 'plain_text' === $rules['_manual_give_blurb']['content_format'] );

		$manifest = \WPTSALL\Models\Adapters\Adapter_Manifest::from_adapter( $adapter );
		assert( 'give' === $manifest['plugin_slug'] );
		assert( in_array( 'plain_text', $manifest['content_formats'], true ) );
		echo "  ✅ Json_Field_Rules_Adapter hot-plug constructs and manifests\n";
	}
}
