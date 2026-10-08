<?php
/**
 * Production autoloader coverage for task sync classes.
 *
 * The write-back dispatcher unit tests require sync files manually, which is
 * useful for direct adapter tests but can hide production REST callback
 * autoload regressions. This file checks the real WPTSALL autoloader mapping.
 *
 * @package WPTSALL
 */

use WPTSALL\Autoloader;

class Test_Sync_Autoloader extends SimpleTestCase {

	public function test_sync_write_back_classes_have_explicit_autoloader_mappings() {
		$autoloader = new Autoloader( WPTSALL_PATH . 'includes/' );
		$ref        = new ReflectionClass( $autoloader );
		$prop       = $ref->getProperty( 'namespace_map' );
		$prop->setAccessible( true );
		$map = $prop->getValue( $autoloader );

		$expected = array(
			'Tasks\\Sync\\Write_Back_Adapter_Interface'  => 'tasks/sync/class-write-back-adapter.php',
			'Tasks\\Sync\\Write_Back_Adapter_Base'       => 'tasks/sync/class-write-back-adapter.php',
			'Tasks\\Sync\\Attachment_Write_Back_Adapter' => 'tasks/sync/class-attachment-write-back-adapter.php',
			'Tasks\\Sync\\Media_Meta_Write_Back_Adapter' => 'tasks/sync/class-media-meta-write-back-adapter.php',
			'Tasks\\Sync\\Document_Write_Back_Adapter'   => 'tasks/sync/class-document-write-back-adapter.php',
			'Tasks\\Sync\\Write_Back_Dispatcher'         => 'tasks/sync/class-write-back-dispatcher.php',
			'Tasks\\Sync\\Manual_Queue'                  => 'tasks/sync/class-manual-queue.php',
			'Tasks\\Sync\\Sync_Executor'                 => 'tasks/sync/class-sync-executor.php',
		);

		foreach ( $expected as $class => $path ) {
			$this->assertArrayHasKey( $class, $map, "{$class} must be explicitly mapped" );
			$this->assertEquals( $path, $map[ $class ], "{$class} autoloader path" );
			$this->assertTrue( file_exists( WPTSALL_PATH . 'includes/' . $path ), "{$path} must exist" );
		}
	}

	public function test_sync_adapter_base_autoloads_before_default_adapters() {
		$autoloader = new Autoloader( WPTSALL_PATH . 'includes/' );

		$autoloader->load_class( 'WPTSALL\\Tasks\\Sync\\Write_Back_Adapter_Base' );
		$autoloader->load_class( 'WPTSALL\\Tasks\\Sync\\Attachment_Write_Back_Adapter' );
		$autoloader->load_class( 'WPTSALL\\Tasks\\Sync\\Media_Meta_Write_Back_Adapter' );
		$autoloader->load_class( 'WPTSALL\\Tasks\\Sync\\Document_Write_Back_Adapter' );

		$this->assertTrue( interface_exists( 'WPTSALL\\Tasks\\Sync\\Write_Back_Adapter_Interface' ), 'write-back interface autoloads' );
		$this->assertTrue( class_exists( 'WPTSALL\\Tasks\\Sync\\Write_Back_Adapter_Base' ), 'write-back base autoloads' );
		$this->assertTrue( class_exists( 'WPTSALL\\Tasks\\Sync\\Attachment_Write_Back_Adapter' ), 'attachment adapter autoloads' );
		$this->assertTrue( class_exists( 'WPTSALL\\Tasks\\Sync\\Media_Meta_Write_Back_Adapter' ), 'media meta adapter autoloads' );
		$this->assertTrue( class_exists( 'WPTSALL\\Tasks\\Sync\\Document_Write_Back_Adapter' ), 'document adapter autoloads' );
	}
}
