<?php
/**
 * Write-Back Dispatcher Tests
 *
 * Tests for WPTSALL\Tasks\Sync\Write_Back_Dispatcher
 * Covers: adapter registration, dispatch routing, manual queue fallback,
 *         batch dispatch, and adapter interfaces.
 *
 * @package WPTSALL
 * @since 1.0.5
 */

// These files are not in the autoloader namespace map; require them explicitly.
// The Write_Back_Dispatcher + adapters live in the free plugin under
// includes/tasks/sync/ (the test file's old "Pro plugin" comment is stale —
// the sync helpers were moved back into the free plugin source).
$sync_dir = WP_PLUGIN_DIR . '/wptsall/includes/tasks/sync/';
if ( ! is_dir( $sync_dir ) ) {
	return; // Free plugin source missing — skip the file.
}
require_once $sync_dir . 'class-write-back-adapter.php';
require_once $sync_dir . 'class-attachment-write-back-adapter.php';
require_once $sync_dir . 'class-media-meta-write-back-adapter.php';
require_once $sync_dir . 'class-document-write-back-adapter.php';
require_once $sync_dir . 'class-manual-queue.php';
require_once $sync_dir . 'class-write-back-dispatcher.php';

use WPTSALL\Tasks\Sync\Write_Back_Dispatcher;
use WPTSALL\Tasks\Sync\Write_Back_Adapter_Interface;
use WPTSALL\Tasks\Sync\Write_Back_Adapter_Base;
use WPTSALL\Tasks\Sync\Attachment_Write_Back_Adapter;
use WPTSALL\Tasks\Sync\Media_Meta_Write_Back_Adapter;
use WPTSALL\Tasks\Sync\Document_Write_Back_Adapter;

class Test_Write_Back_Dispatcher extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		Write_Back_Dispatcher::reset();
	}

	public function tearDown(): void {
		Write_Back_Dispatcher::reset();
		parent::tearDown();
	}

	// ==================== Adapter Registration ====================

	public function test_reset_clears_all_adapters() {
		Write_Back_Dispatcher::reset();
		$adapters = Write_Back_Dispatcher::get_adapters();
		// After reset, get_adapters calls init() which registers defaults.
		$this->assertIsArray( $adapters );
	}

	public function test_get_adapters_returns_default_adapters() {
		$adapters = Write_Back_Dispatcher::get_adapters();
		$this->assertIsArray( $adapters );

		$types = array_keys( $adapters );
		$this->assertContains( 'attachment', $types );
		$this->assertContains( 'media_meta', $types );
		$this->assertContains( 'document', $types );
	}

	public function test_get_adapter_by_type_returns_correct_instance() {
		$adapter = Write_Back_Dispatcher::get_adapter( 'attachment' );
		$this->assertNotNull( $adapter );
		$this->assertInstanceOf( Write_Back_Adapter_Interface::class, $adapter );
		$this->assertEquals( 'attachment', $adapter->get_type() );
	}

	public function test_get_adapter_returns_null_for_unknown_type() {
		$adapter = Write_Back_Dispatcher::get_adapter( 'nonexistent_type' );
		$this->assertNull( $adapter );
	}

	public function test_register_custom_adapter() {
		$custom_adapter = new class() implements Write_Back_Adapter_Interface {
			public function get_type(): string {
				return 'test_custom';
			}

			public function get_supported_ref_types(): array {
				return array( 'url' );
			}

			public function validate_ref( array $translated_ref ) {
				return true;
			}

			public function apply( array $item, array $context ): array {
				return array(
					'success'   => true,
					'target_id' => 999,
					'error'     => null,
					'adapter'   => 'test_custom',
				);
			}

			public function can_handle( array $item ): bool {
				return ( $item['entity_type'] ?? '' ) === 'test_custom';
			}
		};
		Write_Back_Dispatcher::register_adapter( $custom_adapter );

		$adapter = Write_Back_Dispatcher::get_adapter( 'test_custom' );
		$this->assertNotNull( $adapter );
		$this->assertEquals( 'test_custom', $adapter->get_type() );
	}

	// ==================== Adapter Interface Tests ====================

	public function test_attachment_adapter_type() {
		$adapter = new Attachment_Write_Back_Adapter();
		$this->assertEquals( 'attachment', $adapter->get_type() );
	}

	public function test_attachment_adapter_supported_ref_types() {
		$adapter = new Attachment_Write_Back_Adapter();
		$types   = $adapter->get_supported_ref_types();
		$this->assertContains( 'url', $types );
		$this->assertContains( 'id', $types );
		$this->assertContains( 'path', $types );
	}

	public function test_attachment_adapter_can_handle_attachment() {
		$adapter = new Attachment_Write_Back_Adapter();
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'attachment' ) ) );
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'image' ) ) );
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'video' ) ) );
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'audio' ) ) );
		$this->assertFalse( $adapter->can_handle( array( 'entity_type' => 'document' ) ) );
	}

	public function test_media_meta_adapter_type() {
		$adapter = new Media_Meta_Write_Back_Adapter();
		$this->assertEquals( 'media_meta', $adapter->get_type() );
	}

	public function test_media_meta_adapter_supported_ref_types() {
		$adapter = new Media_Meta_Write_Back_Adapter();
		$types   = $adapter->get_supported_ref_types();
		$this->assertContains( 'id', $types );
		$this->assertCount( 1, $types );
	}

	public function test_media_meta_adapter_can_handle() {
		$adapter = new Media_Meta_Write_Back_Adapter();
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'media_meta' ) ) );
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'attachment_meta' ) ) );
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'image_meta' ) ) );
		$this->assertFalse( $adapter->can_handle( array( 'entity_type' => 'attachment' ) ) );
	}

	public function test_document_adapter_type() {
		$adapter = new Document_Write_Back_Adapter();
		$this->assertEquals( 'document', $adapter->get_type() );
	}

	public function test_document_adapter_supported_ref_types() {
		$adapter = new Document_Write_Back_Adapter();
		$types   = $adapter->get_supported_ref_types();
		$this->assertContains( 'url', $types );
		$this->assertContains( 'path', $types );
		$this->assertNotContains( 'id', $types );
	}

	public function test_document_adapter_can_handle_document() {
		$adapter = new Document_Write_Back_Adapter();
		$this->assertTrue( $adapter->can_handle( array( 'entity_type' => 'document' ) ) );
		$this->assertFalse( $adapter->can_handle( array( 'entity_type' => 'image' ) ) );
	}

	public function test_document_adapter_can_handle_by_mime_type() {
		$adapter = new Document_Write_Back_Adapter();
		$this->assertTrue( $adapter->can_handle( array(
			'entity_type' => 'other',
			'mime_type'   => 'application/pdf',
		) ) );
		$this->assertTrue( $adapter->can_handle( array(
			'entity_type' => 'other',
			'mime_type'   => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		) ) );
	}

	// ==================== Ref Validation ====================

	public function test_validate_ref_rejects_missing_ref_type() {
		$adapter    = new Attachment_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array() );
		$this->assertInstanceOf( 'WP_Error', $validation );
		$this->assertEquals( 'missing_ref_type', $validation->get_error_code() );
	}

	public function test_validate_ref_rejects_unsupported_ref_type() {
		$adapter    = new Media_Meta_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'url', 'ref_value' => 'https://example.com' ) );
		$this->assertInstanceOf( 'WP_Error', $validation );
		$this->assertEquals( 'unsupported_ref_type', $validation->get_error_code() );
	}

	public function test_validate_ref_rejects_missing_ref_value() {
		$adapter    = new Attachment_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'url' ) );
		$this->assertInstanceOf( 'WP_Error', $validation );
		$this->assertEquals( 'missing_ref_value', $validation->get_error_code() );
	}

	public function test_validate_ref_rejects_invalid_url() {
		$adapter    = new Attachment_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'url', 'ref_value' => 'not-a-url' ) );
		$this->assertInstanceOf( 'WP_Error', $validation );
		$this->assertEquals( 'invalid_url', $validation->get_error_code() );
	}

	public function test_validate_ref_accepts_valid_url() {
		$adapter    = new Attachment_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'url', 'ref_value' => 'https://example.com/image.jpg' ) );
		$this->assertTrue( $validation );
	}

	public function test_validate_ref_rejects_invalid_id() {
		$adapter    = new Attachment_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'id', 'ref_value' => -1 ) );
		$this->assertInstanceOf( 'WP_Error', $validation );
		$this->assertEquals( 'invalid_id', $validation->get_error_code() );
	}

	public function test_validate_ref_accepts_valid_id() {
		$adapter    = new Media_Meta_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'id', 'ref_value' => 42 ) );
		$this->assertTrue( $validation );
	}

	public function test_validate_ref_rejects_path_with_directory_traversal() {
		$adapter    = new Attachment_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'path', 'ref_value' => '../../etc/passwd' ) );
		$this->assertInstanceOf( 'WP_Error', $validation );
		$this->assertEquals( 'invalid_path', $validation->get_error_code() );
	}

	public function test_validate_ref_accepts_valid_path() {
		$adapter    = new Attachment_Write_Back_Adapter();
		$validation = $adapter->validate_ref( array( 'ref_type' => 'path', 'ref_value' => 'uploads/2026/01/image.jpg' ) );
		$this->assertTrue( $validation );
	}

	// ==================== Dispatch ====================

	public function test_dispatch_returns_result_array() {
		$item = array(
			'entity_type'    => 'attachment',
			'source_id'      => 1,
			'translated_ref' => array( 'ref_type' => 'url', 'ref_value' => 'https://example.com/img.jpg' ),
		);
		$context = array(
			'relation_id'  => 1,
			'task_id'      => 1,
			'source_blog'  => 1,
			'target_blog'  => 2,
			'target_type'  => 'wp',
			'lang_to'      => 'en_US',
		);

		$result = Write_Back_Dispatcher::dispatch( $item, $context );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'adapter', $result );
	}

	public function test_dispatch_routes_attachment_to_correct_adapter() {
		$item = array(
			'entity_type'    => 'image',
			'source_id'      => 1,
			'translated_ref' => array( 'ref_type' => 'url', 'ref_value' => 'https://example.com/img.jpg' ),
		);
		$context = array( 'relation_id' => 1, 'task_id' => 1 );

		$result = Write_Back_Dispatcher::dispatch( $item, $context );
		$this->assertEquals( 'attachment', $result['adapter'] );
	}

	public function test_dispatch_no_adapter_routes_to_manual_queue() {
		$item = array(
			'entity_type'    => 'completely_unknown_entity',
			'source_id'      => 1,
			'translated_ref' => array( 'ref_type' => 'url', 'ref_value' => 'https://example.com/file' ),
		);
		$context = array( 'relation_id' => 1, 'task_id' => 1 );

		$result = Write_Back_Dispatcher::dispatch( $item, $context );
		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['adapter'] );
		$this->assertStringContainsString( 'No write-back adapter', $result['error'] );
	}

	public function test_dispatch_validation_failure_routes_to_queue() {
		$item = array(
			'entity_type'    => 'attachment',
			'source_id'      => 1,
			'translated_ref' => array( 'ref_type' => 'url', 'ref_value' => 'not-a-valid-url' ),
		);
		$context = array( 'relation_id' => 1, 'task_id' => 1 );

		$result = Write_Back_Dispatcher::dispatch( $item, $context );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'attachment', $result['adapter'] );
	}

	// ==================== Batch Dispatch ====================

	public function test_dispatch_batch_returns_summary() {
		$items = array(
			array(
				'entity_type'    => 'attachment',
				'source_id'      => 1,
				'translated_ref' => array( 'ref_type' => 'url', 'ref_value' => 'https://example.com/a.jpg' ),
			),
			array(
				'entity_type'    => 'document',
				'source_id'      => 2,
				'translated_ref' => array( 'ref_type' => 'url', 'ref_value' => 'https://example.com/b.pdf' ),
			),
		);
		$context = array( 'relation_id' => 1, 'task_id' => 1 );

		$summary = Write_Back_Dispatcher::dispatch_batch( $items, $context );
		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'total', $summary );
		$this->assertArrayHasKey( 'applied', $summary );
		$this->assertArrayHasKey( 'queued', $summary );
		$this->assertArrayHasKey( 'failed', $summary );
		$this->assertArrayHasKey( 'items', $summary );
		$this->assertEquals( 2, $summary['total'] );
	}

	public function test_dispatch_batch_handles_empty_array() {
		$summary = Write_Back_Dispatcher::dispatch_batch( array(), array() );
		$this->assertEquals( 0, $summary['total'] );
		$this->assertEquals( 0, $summary['applied'] );
		$this->assertEquals( 0, $summary['queued'] );
		$this->assertEquals( 0, $summary['failed'] );
		$this->assertEmpty( $summary['items'] );
	}

	public function test_dispatch_batch_counts_non_array_items_as_failed() {
		$items = array( 'not-an-array', 42, null );
		$summary = Write_Back_Dispatcher::dispatch_batch( $items, array() );
		$this->assertEquals( 3, $summary['total'] );
		$this->assertEquals( 3, $summary['failed'] );
	}

	// ==================== Adapter Base build_result ====================

	public function test_adapter_base_build_result() {
		$adapter = new Attachment_Write_Back_Adapter();

		// Use reflection to call protected build_result.
		$method = new ReflectionMethod( $adapter, 'build_result' );
		$method->setAccessible( true );

		$result = $method->invoke( $adapter, true, 42, null );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 42, $result['target_id'] );
		$this->assertNull( $result['error'] );
		$this->assertEquals( 'attachment', $result['adapter'] );
	}

	public function test_adapter_base_build_result_with_error() {
		$adapter = new Document_Write_Back_Adapter();

		$method = new ReflectionMethod( $adapter, 'build_result' );
		$method->setAccessible( true );

		$result = $method->invoke( $adapter, false, null, 'Something went wrong' );
		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['target_id'] );
		$this->assertEquals( 'Something went wrong', $result['error'] );
		$this->assertEquals( 'document', $result['adapter'] );
	}

	// ==================== Media Meta Adapter - Apply ====================

	public function test_media_meta_apply_rejects_empty_translated_fields() {
		$adapter = new Media_Meta_Write_Back_Adapter();
		$result  = $adapter->apply(
			array(
				'translated_ref'    => array( 'ref_type' => 'id', 'ref_value' => 42 ),
				'translated_fields' => array(),
			),
			array()
		);
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No translated fields', $result['error'] );
	}

	public function test_media_meta_translatable_fields_constant() {
		$fields = Media_Meta_Write_Back_Adapter::TRANSLATABLE_FIELDS;
		$this->assertIsArray( $fields );
		$this->assertArrayHasKey( 'alt_text', $fields );
		$this->assertArrayHasKey( 'caption', $fields );
		$this->assertArrayHasKey( 'description', $fields );
		$this->assertArrayHasKey( 'title', $fields );
	}
}
