<?php
/**
 * Unit tests: Field_Capability copy-once + four-state labels.
 *
 * @package WPTSALL
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Field_Capability;
use WPTSALL\Core\WPML_Config_Reader;

class Test_Field_Capability_Copy_Once extends SimpleTestCase {

	public function test_normalize_aliases() {
		$this->assertEquals( 'sync', Field_Capability::normalize_type( 'copy' ) );
		$this->assertEquals( 'copy_once', Field_Capability::normalize_type( 'copy-once' ) );
		$this->assertEquals( 'copy_once', Field_Capability::normalize_type( 'copy_once' ) );
		$this->assertEquals( 'skip', Field_Capability::normalize_type( 'ignore' ) );
		$this->assertEquals( 'id_mapping', Field_Capability::normalize_type( 'mapping' ) );
	}

	public function test_wpml_config_reader_keeps_copy_once() {
		$this->assertEquals( 'copy_once', WPML_Config_Reader::normalize_action( 'copy-once' ) );
		$this->assertEquals( 'sync', WPML_Config_Reader::normalize_action( 'copy' ) );
	}

	public function test_copy_once_write_gate() {
		$this->assertTrue( Field_Capability::should_write_to_target( 'copy_once', '' ) );
		$this->assertTrue( Field_Capability::should_write_to_target( 'copy_once', null ) );
		$this->assertFalse( Field_Capability::should_write_to_target( 'copy_once', 'already set' ) );
		$this->assertTrue( Field_Capability::should_write_to_target( 'sync', 'already set' ) );
		$this->assertFalse( Field_Capability::should_write_to_target( 'skip', '' ) );
	}

	public function test_four_state_labels() {
		$this->assertEquals( 'Translate', Field_Capability::four_state_label( 'translate' ) );
		$this->assertEquals( 'Copy', Field_Capability::four_state_label( 'sync' ) );
		$this->assertEquals( 'Copy once', Field_Capability::four_state_label( 'copy_once' ) );
		$this->assertEquals( "Don't translate", Field_Capability::four_state_label( 'skip' ) );
	}

	public function test_type_for_field() {
		$caps = array(
			'_sku' => array( 'type' => 'copy-once' ),
			'_x'   => 'ignore',
		);
		$this->assertEquals( 'copy_once', Field_Capability::type_for_field( $caps, '_sku' ) );
		$this->assertEquals( 'skip', Field_Capability::type_for_field( $caps, '_x' ) );
		$this->assertEquals( 'sync', Field_Capability::type_for_field( $caps, '_missing' ) );
	}
}
