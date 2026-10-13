<?php
/**
 * Peer config reader tests (WPML XML + WPM JSON).
 *
 * @package WPTSALL
 */

use WPTSALL\Core\WPML_Config_Reader;
use WPTSALL\Models\Services\Plugin_Scanner;

class Test_WPML_Config_Reader extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( class_exists( WPML_Config_Reader::class ) ) {
			WPML_Config_Reader::reset_cache();
		}
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( WPML_Config_Reader::class ) );
	}

	public function test_parse_wpml_xml_custom_fields_and_types() {
		$xml = <<<XML
<wpml-config>
  <custom-fields>
    <custom-field action="translate">custom-title</custom-field>
    <custom-field action="copy">_sku</custom-field>
    <custom-field action="ignore">date-added</custom-field>
    <custom-field action="copy-once">bg-color</custom-field>
  </custom-fields>
  <custom-types>
    <custom-type translate="1">product</custom-type>
    <custom-type translate="0">shop_order</custom-type>
  </custom-types>
  <taxonomies>
    <taxonomy translate="1">product_cat</taxonomy>
  </taxonomies>
</wpml-config>
XML;
		$out = WPML_Config_Reader::parse_wpml_xml( $xml );
		$this->assertEquals( 'translate', $out['custom-fields']['custom-title'] );
		$this->assertEquals( 'sync', $out['custom-fields']['_sku'] );
		$this->assertEquals( 'skip', $out['custom-fields']['date-added'] );
		// P1-XML-01 contract (2.2.0): copy-once is its own type with a
		// write-once gate, distinct from sync/copy (continuous). See
		// tests/unit/hooks/test-field-capability-copy-once.php.
		$this->assertEquals( 'copy_once', $out['custom-fields']['bg-color'] );
		$this->assertEquals( 'translate', $out['custom-types']['product'] );
		$this->assertEquals( 'ignore', $out['custom-types']['shop_order'] );
		$this->assertEquals( 'translate', $out['taxonomies']['product_cat'] );
	}

	public function test_parse_wpm_json_empty_object_means_translate() {
		$json = wp_json_encode(
			array(
				'post_types'  => array( 'event' => array() ),
				'post_fields' => array(
					'_location_address' => array(),
					'_event_start_date' => array( 'action' => 'copy' ),
				),
			)
		);
		$out = WPML_Config_Reader::parse_wpm_json( $json );
		$this->assertEquals( 'translate', $out['custom-types']['event'] );
		$this->assertEquals( 'translate', $out['custom-fields']['_location_address'] );
		$this->assertEquals( 'sync', $out['custom-fields']['_event_start_date'] );
	}

	public function test_bundled_woocommerce_peer_config_is_readable() {
		$config = WPML_Config_Reader::get_config_for_plugin( 'woocommerce' );
		$this->assertNotEmpty( $config );
		$this->assertArrayHasKey( 'custom-fields', $config );
		$this->assertEquals( 'translate', $config['custom-fields']['_product_attributes'] );
		$this->assertEquals( 'sync', $config['custom-fields']['_sku'] );
		$this->assertEquals( 'translate', WPML_Config_Reader::get_field_action( '_elementor_data' ) );
	}

	public function test_acf_runtime_does_not_invent_content_cpt() {
		$enriched = Plugin_Scanner::enrich_scan_with_runtime_authority(
			array(
				'plugin_slug'       => 'advanced-custom-fields',
				'post_types'        => array(
					array( 'name' => 'acf-field-group' ),
				),
				'taxonomies'        => array(),
				'meta_fields'       => array(),
				'custom_tables'     => array(),
				'is_content_plugin' => 0,
			),
			'advanced-custom-fields'
		);
		$this->assertEquals( 'meta_overlay', $enriched['storage']['primary'] );
		$this->assertTrue( Plugin_Scanner::is_excluded_post_type( 'acf-field-group' ) );
		$this->assertTrue( Plugin_Scanner::is_excluded_taxonomy( 'post_format' ) );
		$this->assertTrue( Plugin_Scanner::is_excluded_taxonomy( 'nav_menu' ) );
		$this->assertFalse( Plugin_Scanner::is_excluded_taxonomy( 'category' ) );
	}

	public function test_enrich_scan_adds_peer_meta_for_elementor() {
		$enriched = Plugin_Scanner::enrich_scan_with_peer_and_adapters(
			array(
				'plugin_slug'       => 'elementor',
				'post_types'        => array(),
				'taxonomies'        => array(),
				'meta_fields'       => array(),
				'is_content_plugin' => 0,
			),
			'elementor'
		);
		$keys = array();
		foreach ( (array) $enriched['meta_fields'] as $entry ) {
			$keys[] = is_array( $entry ) ? ( $entry['meta_key'] ?? '' ) : (string) $entry;
		}
		$this->assertContains( '_elementor_data', $keys );
	}

	/**
	 * P1-XML-01: nested admin-texts <key name> trees flatten to slash
	 * option paths; shortcodes and gutenberg segments parse into the
	 * normalized config; malformed XML stays fail-closed (empty config).
	 */
	public function test_admin_texts_nested_tree_flattens_to_option_paths() {
		$xml = <<<XML
<wpml-config>
  <admin-texts>
    <key name="my_plugin_options">
      <key name="title" />
      <key name="settings">
        <key name="footer_note" />
      </key>
    </key>
    <key name="standalone_option" />
  </admin-texts>
</wpml-config>
XML;
		$out = WPML_Config_Reader::parse_wpml_xml( $xml );
		$this->assertContains( 'standalone_option', $out['admin-texts'] );
		$this->assertContains( 'my_plugin_options/title', $out['admin-texts'] );
		$this->assertContains( 'my_plugin_options/settings/footer_note', $out['admin-texts'] );
		$this->assertEquals(
			array( 'my_plugin_options/title', 'my_plugin_options/settings/footer_note', 'standalone_option' ),
			$out['admin-texts']
		);
	}

	public function test_shortcodes_segment_parses_attributes_and_whole_content() {
		$xml = <<<XML
<wpml-config>
  <shortcodes>
    <shortcode name="gallery">
      <attribute name="caption" />
    </shortcode>
    <shortcode name="blockquote" />
    <shortcode name="button">
      <attribute name="label" />
      <attribute name="label" />
      <attribute name="" />
    </shortcode>
  </shortcodes>
</wpml-config>
XML;
		$out = WPML_Config_Reader::parse_wpml_xml( $xml );
		$this->assertSame( array( 'caption' ), $out['shortcodes']['gallery']['attributes'] );
		$this->assertFalse( $out['shortcodes']['gallery']['whole_content'] );
		$this->assertTrue( $out['shortcodes']['blockquote']['whole_content'] );
		$this->assertSame( array( 'label' ), $out['shortcodes']['button']['attributes'] );
	}

	public function test_gutenberg_segment_parses_block_attribute_keys() {
		$xml = <<<XML
<wpml-config>
  <gutenberg>
    <block name="core/paragraph">
      <attribute name="content" />
    </block>
    <block name="core/button">
      <attribute name="text" />
      <attribute name="url" />
    </block>
  </gutenberg>
</wpml-config>
XML;
		$out = WPML_Config_Reader::parse_wpml_xml( $xml );
		$this->assertSame( array( 'content' ), $out['gutenberg']['core/paragraph'] );
		$this->assertSame( array( 'text', 'url' ), $out['gutenberg']['core/button'] );
	}

	public function test_malformed_xml_is_fail_closed() {
		$this->assertSame( array(), WPML_Config_Reader::parse_wpml_xml( '<wpml-config><custom-fields>' ) );
		$this->assertSame( array(), WPML_Config_Reader::parse_wpml_xml( '' ) );
		$this->assertSame( array(), WPML_Config_Reader::parse_wpml_xml( 'not xml at all' ) );
	}

	public function test_merge_config_unions_shortcode_attributes() {
		$m = new ReflectionMethod( WPML_Config_Reader::class, 'merge_config' );
		$m->setAccessible( true );
		$base = array(
			'shortcodes' => array( 'gallery' => array( 'attributes' => array( 'caption' ), 'whole_content' => false ) ),
		);
		$add  = array(
			'shortcodes' => array( 'gallery' => array( 'attributes' => array( 'title' ), 'whole_content' => false ) ),
		);
		$merged = $m->invoke( null, $base, $add );
		$this->assertSame( array( 'caption', 'title' ), $merged['shortcodes']['gallery']['attributes'] );
	}
}
