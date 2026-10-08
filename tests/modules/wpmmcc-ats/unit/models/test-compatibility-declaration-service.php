<?php
/**
 * Compatibility Declaration Service Tests
 *
 * @package WPTSALL
 * @since 1.6.2
 */

use WPTSALL\Models\Services\Compatibility_Declaration_Service;

class Test_Compatibility_Declaration_Service extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		add_filter( 'wptsall_model_compatibility_field_declarations', array( $this, 'inject_test_declarations' ), 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'wptsall_model_compatibility_field_declarations', array( $this, 'inject_test_declarations' ), 10 );
		parent::tearDown();
	}

	public function inject_test_declarations( $rows, $post_type ) {
		if ( 'book' !== $post_type ) {
			return $rows;
		}

		$rows[] = array(
			'field_key'         => 'subtitle',
			'capability'        => 'translate',
			'source_origin'     => 'vendor_registry',
			'source_detail'     => 'provider:test_vendor',
			'confidence_score'  => 81,
			'confidence_reason' => 'declared by test',
			'provider'          => 'test_vendor',
			'provider_key'      => 'fixture-a',
			'declaration_type'  => 'custom_field',
		);

		$rows[] = array(
			'field_key'         => 'subtitle',
			'capability'        => 'translate',
			'source_origin'     => 'vendor_registry',
			'source_detail'     => 'provider:test_vendor_b',
			'confidence_score'  => 96,
			'confidence_reason' => 'higher confidence declaration',
			'provider'          => 'test_vendor',
			'provider_key'      => 'fixture-b',
			'declaration_type'  => 'custom_field',
		);

		$rows[] = array(
			'field_key'        => 'cover_id',
			'capability'       => 'sync',
			'source_origin'    => 'vendor_registry',
			'provider'         => 'test_vendor',
			'provider_key'     => 'fixture-c',
			'declaration_type' => 'custom_field',
		);

		$rows[] = array(
			'field_key'         => 'hero.title',
			'capability'        => 'translate',
			'source_origin'     => 'vendor_registry',
			'source_detail'     => 'provider:test_vendor_block',
			'provider'          => 'test_vendor',
			'provider_key'      => 'fixture-d',
			'declaration_type'  => 'block_subtree',
			'block_name'        => 'core/cover',
			'path'              => array( 'blocks', 'hero', 'title' ),
			'item_shape'        => 'scalar',
		);

		$rows[] = array(
			'field_key'         => 'gallery.caption',
			'capability'        => 'translate',
			'source_origin'     => 'vendor_registry',
			'source_detail'     => 'provider:test_vendor_shortcode',
			'provider'          => 'test_vendor',
			'provider_key'      => 'fixture-e',
			'declaration_type'  => 'shortcode_arg',
			'shortcode_tag'     => 'gallery',
			'arg'               => 'caption',
		);

		$rows[] = array(
			'field_key'         => 'labels.cta',
			'capability'        => 'translate',
			'source_origin'     => 'vendor_registry',
			'source_detail'     => 'provider:test_vendor_config',
			'provider'          => 'test_vendor',
			'provider_key'      => 'fixture-f',
			'declaration_type'  => 'config_object',
			'config_path'       => array( 'labels', 'cta' ),
			'repeater'          => false,
		);

		$rows[] = array(
			'field_key'              => 'email.subject',
			'capability'             => 'translate',
			'source_origin'          => 'vendor_registry',
			'source_detail'          => 'provider:test_vendor_template',
			'provider'               => 'test_vendor',
			'provider_key'           => 'fixture-g',
			'declaration_type'       => 'message_template',
			'message_template_part'  => 'subject',
			'routing_profile'        => 'notification_email',
			'source_role'            => 'message_subject',
		);

		return $rows;
	}

	public function test_get_field_declarations_for_post_type_normalizes_and_deduplicates() {
		$rows = Compatibility_Declaration_Service::get_field_declarations_for_post_type( 'book' );

		$this->assertGreaterThanOrEqual( 6, count( $rows ) );
		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['field_key'] ] = $row;
		}

		$this->assertArrayHasKey( 'subtitle', $map );
		$this->assertArrayHasKey( 'cover_id', $map );
		$this->assertArrayHasKey( 'hero.title', $map );
		$this->assertArrayHasKey( 'gallery.caption', $map );
		$this->assertArrayHasKey( 'labels.cta', $map );
		$this->assertArrayHasKey( 'email.subject', $map );
		$this->assertEquals( 96, (int) $map['subtitle']['confidence_score'] );
		$this->assertStringContainsString( 'test_vendor_b', $map['subtitle']['source_detail'] );
		$this->assertEquals( 'book', $map['subtitle']['extra']['post_type_context'] );
		$this->assertEquals( 'sync', $map['cover_id']['capability'] );
		$this->assertEquals( 'block_attr', $map['hero.title']['field_kind'] );
		$this->assertEquals( 'core/cover', $map['hero.title']['extra']['block_name'] );
		$this->assertEquals( 'blocks', $map['hero.title']['extra']['path'][0] );
		$this->assertEquals( 'shortcode_attr', $map['gallery.caption']['field_kind'] );
		$this->assertEquals( 'gallery', $map['gallery.caption']['extra']['shortcode_tag'] );
		$this->assertEquals( 'config', $map['labels.cta']['field_kind'] );
		$this->assertEquals( 'labels', $map['labels.cta']['extra']['config_path'][0] );
		$this->assertEquals( 'message_template', $map['email.subject']['extra']['source_group'] );
		$this->assertEquals( 'message_template_writeback', $map['email.subject']['extra']['delivery_target'] );
		$this->assertEquals( 'notification_email', $map['email.subject']['extra']['routing_profile'] );
		$this->assertEquals( 'message_subject', $map['email.subject']['extra']['source_role'] );
	}

	public function test_scanner_add_discovery_field_accepts_extended_field_kinds() {
		$scanner = new \WPTSALL\Models\Scanners\Model_Scanner_V2();
		$reflection = new ReflectionClass( $scanner );
		$method = $reflection->getMethod( 'add_discovery_field' );
		$method->setAccessible( true );

		$field_map = array();
		$field = array(
			'field_kind' => 'block_attr',
			'field_key'  => 'hero.title',
			'extra'      => array( 'path' => array( 'blocks', 'hero', 'title' ) ),
		);
		$method->invokeArgs( $scanner, array( &$field_map, $field ) );

		$this->assertArrayHasKey( 'block_attr:hero.title', $field_map );
		$this->assertEquals( 'block_attr', $field_map['block_attr:hero.title']['field_kind'] );
	}
}
