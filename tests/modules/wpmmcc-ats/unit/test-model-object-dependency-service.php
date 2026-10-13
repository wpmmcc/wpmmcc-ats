<?php
/**
 * Model Object Dependency Service Tests
 *
 * Tests for WPTSALL\Models\Services\Model_Object_Dependency_Service.
 *
 * The class documents itself as an intentional stub (since 1.2.0): it
 * returns safe empty results so dependent REST routes stay operational
 * until the real dependency graph lands. These tests pin that contract —
 * including the exact stats shape — so any change from stub to real
 * behavior has to consciously update these assertions.
 *
 * catalog: WP-CLASS-Model_Object_Dependency_Service
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Model_Object_Dependency_Service;

class Test_Model_Object_Dependency_Service extends SimpleTestCase {

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Model_Object_Dependency_Service' ) );
	}

	public function test_rebuild_for_model_returns_stub_stats_shape() {
		$result = Model_Object_Dependency_Service::rebuild_for_model( 123456789 );

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'total'      => 0,
				'resolved'   => 0,
				'unresolved' => 0,
				'external'   => 0,
				'invalid'    => 0,
			),
			$result,
			'stub contract: all counters zero with exactly these five keys'
		);
	}

	public function test_rebuild_for_model_is_input_insensitive() {
		// The stub must be stable for 0, negative and huge ids alike.
		$this->assertSame( Model_Object_Dependency_Service::rebuild_for_model( 0 ), Model_Object_Dependency_Service::rebuild_for_model( PHP_INT_MAX ) );
		$this->assertSame( Model_Object_Dependency_Service::rebuild_for_model( 0 ), Model_Object_Dependency_Service::rebuild_for_model( -1 ) );
	}

	public function test_get_model_dependencies_returns_empty_list() {
		$this->assertSame( array(), Model_Object_Dependency_Service::get_model_dependencies( 123456789 ) );
		$this->assertSame( array(), Model_Object_Dependency_Service::get_model_dependencies( 0 ) );
		$this->assertSame( array(), Model_Object_Dependency_Service::get_model_dependencies( -5 ) );
	}
}
