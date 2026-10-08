<?php
/**
 * Field Rules Store Tests
 *
 * Tests for WPTSALL\Models\Adapters\Field_Rules_Store:
 * - save_document(): valid doc persisted (normalized), invalid doc rejected
 *   with errors and NOT persisted
 * - list_option_documents(): non-array option, slug sanitizing, junk rows
 * - delete_document(): true on removal, false when absent
 * - discover_all_documents(): option entries exposed with source='option'
 *   and validated/normalized payloads; deleted docs disappear
 *
 * catalog: WP-CLASS-Field_Rules_Store
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Adapters\Field_Rules_Store;

class Test_Field_Rules_Store extends SimpleTestCase {

	const OPTION_KEY = 'wptsall_json_field_rules';

	/**
	 * Slugs created by this run (uniqid-tagged; tearDown deletes them only).
	 *
	 * @var array<int, string>
	 */
	private $created_slugs = array();

	/**
	 * Original option value (null = option absent).
	 *
	 * @var mixed
	 */
	private $original_option;

	public function setUp(): void {
		parent::setUp();
		$this->original_option = get_option( self::OPTION_KEY, null );
		delete_option( self::OPTION_KEY );
	}

	public function tearDown(): void {
		// Delete only this run's slugs from whatever is stored now.
		$all = get_option( self::OPTION_KEY, array() );
		if ( is_array( $all ) ) {
			foreach ( $this->created_slugs as $slug ) {
				unset( $all[ $slug ] );
			}
			if ( empty( $all ) ) {
				delete_option( self::OPTION_KEY );
			} else {
				update_option( self::OPTION_KEY, $all, false );
			}
		}
		// Restore the pre-test option exactly.
		if ( null !== $this->original_option ) {
			update_option( self::OPTION_KEY, $this->original_option, false );
		} else {
			delete_option( self::OPTION_KEY );
		}
		parent::tearDown();
	}

	/**
	 * Build a valid document for a uniqid-tagged slug.
	 *
	 * @return array{plugin_slug: string, field_rules: array<string, array>, field_patterns: array<string, array>}
	 */
	private function valid_doc(): array {
		$slug = 'zz-frs-' . strtolower( uniqid() );
		return array(
			'plugin_slug' => $slug,
			'field_rules' => array(
				'_model_field' => array(
					'type'           => 'translate',
					'content_format' => 'plain_text',
					'storage'        => 'post_meta',
				),
			),
			'field_patterns' => array(
				'_cf_*' => array(
					'type'           => 'copy',
					'content_format' => 'text',
					'storage'        => 'post_meta',
				),
			),
		);
	}

	public function test_class_and_option_key_contract() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Adapters\Field_Rules_Store' ) );
		$this->assertEquals( 'wptsall_json_field_rules', Field_Rules_Store::OPTION_KEY );
		// Fresh option resolves to an empty list, never a scalar.
		$this->assertIsArray( Field_Rules_Store::list_option_documents() );
		$this->assertSame( array(), Field_Rules_Store::list_option_documents() );
	}

	public function test_save_document_persists_normalized_doc() {
		$doc = $this->valid_doc();
		$this->created_slugs[] = $doc['plugin_slug'];

		$result = Field_Rules_Store::save_document( $doc );

		$this->assertTrue( $result['ok'], 'valid doc must save: ' . implode( '; ', $result['errors'] ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertArrayHasKey( 'document', $result );

		// Normalization contract: alias format 'text' -> canonical 'plain_text'.
		$stored = Field_Rules_Store::list_option_documents();
		$this->assertArrayHasKey( $doc['plugin_slug'], $stored );
		$this->assertEquals( 'plain_text', $stored[ $doc['plugin_slug'] ]['field_patterns']['_cf_*']['content_format'] );

		// Round-trip through the raw option.
		$raw = get_option( self::OPTION_KEY );
		$this->assertIsArray( $raw );
		$this->assertArrayHasKey( $doc['plugin_slug'], $raw );
	}

	public function test_save_document_rejects_invalid_doc_without_persisting() {
		$before = get_option( self::OPTION_KEY, null );

		// Missing plugin_slug AND invalid rule type AND missing content_format.
		$bad = array(
			'plugin_slug' => '',
			'field_rules' => array(
				'x' => array( 'type' => 'explode', 'storage' => 'post_meta' ),
			),
		);
		$result = Field_Rules_Store::save_document( $bad );

		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertArrayNotHasKey( 'document', $result );

		// Nothing persisted: option unchanged (still absent here).
		$this->assertSame( $before, get_option( self::OPTION_KEY, null ) );
	}

	public function test_save_document_rejects_reserved_prefix_key() {
		$doc = $this->valid_doc();
		$doc['field_rules'] = array(
			'_wptsall_internal' => array(
				'type'           => 'skip',
				'content_format'  => 'plain_text',
				'storage'        => 'post_meta',
			),
		);
		$result = Field_Rules_Store::save_document( $doc );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( "field_rules key '_wptsall_internal' uses reserved _wptsall_ prefix", $result['errors'] );
	}

	public function test_list_option_documents_handles_corrupt_option() {
		update_option( self::OPTION_KEY, 'garbage-not-an-array' );
		$this->assertSame( array(), Field_Rules_Store::list_option_documents() );

		// Slug sanitizing + junk rows are dropped.
		update_option( self::OPTION_KEY, array(
			'UPPER_Slug!!'  => array( 'field_rules' => array() ),
			'!!!'           => array( 'field_rules' => array() ),
			'not-an-array'  => 'scalar-value',
			'valid_doc_row' => array( 'field_rules' => array() ),
			'0'             => array( 'field_rules' => array() ),
		) );
		$listed = Field_Rules_Store::list_option_documents();
		$this->assertArrayHasKey( 'upper_slug', $listed, 'slugs must be sanitize_key()-ed' );
		$this->assertArrayNotHasKey( '!!!', $listed, 'slugs that sanitize to EMPTY must be skipped' );
		$this->assertArrayNotHasKey( 'not-an-array', $listed, 'non-array doc rows must be skipped' );
		$this->assertArrayHasKey( 'valid_doc_row', $listed );
		// Pin current contract: sanitize_key( '0' ) is '0' (non-empty), so a
		// numeric-string slug is KEPT — only empty sanitize results are skipped.
		$this->assertArrayHasKey( '0', $listed );
	}

	public function test_delete_document_roundtrip() {
		$doc = $this->valid_doc();
		$this->created_slugs[] = $doc['plugin_slug'];
		Field_Rules_Store::save_document( $doc );

		$this->assertTrue( Field_Rules_Store::delete_document( $doc['plugin_slug'] ) );
		$this->assertArrayNotHasKey( $doc['plugin_slug'], Field_Rules_Store::list_option_documents() );

		// Second delete of the now-absent slug returns false.
		$this->assertFalse( Field_Rules_Store::delete_document( $doc['plugin_slug'] ) );
		$this->assertFalse( Field_Rules_Store::delete_document( 'zz-frs-never-saved' ) );
	}

	public function test_discover_all_documents_exposes_saved_option_doc() {
		$doc = $this->valid_doc();
		$this->created_slugs[] = $doc['plugin_slug'];
		$save = Field_Rules_Store::save_document( $doc );
		$this->assertTrue( $save['ok'] );

		$found = null;
		foreach ( Field_Rules_Store::discover_all_documents() as $entry ) {
			if ( $entry['plugin_slug'] === $doc['plugin_slug'] ) {
				$found = $entry;
				break;
			}
		}

		$this->assertNotNull( $found, 'saved option doc must appear in discover_all_documents()' );
		$this->assertEquals( 'option', $found['source'] );
		$this->assertTrue( $found['validation']['ok'] );
		$this->assertSame( array(), $found['validation']['errors'] );
		// Discovered docs are normalized: post_meta translate rule survives.
		$this->assertEquals( 'translate', $found['field_rules']['_model_field']['type'] );
		$this->assertEquals( 'plain_text', $found['field_rules']['_model_field']['content_format'] );
		$this->assertEquals( 'post_meta', $found['field_rules']['_model_field']['storage'] );

		// Delete: the slug disappears from discovery.
		Field_Rules_Store::delete_document( $doc['plugin_slug'] );
		$slugs = array_map(
			static function ( $entry ) {
				return $entry['plugin_slug'];
			},
			Field_Rules_Store::discover_all_documents()
		);
		$this->assertNotContains( $doc['plugin_slug'], $slugs );
	}

	public function test_discover_all_documents_marks_invalid_option_doc_not_ok() {
		// Bypass save_document validation and inject a structurally broken doc.
		$slug = 'zz-frs-bad-' . strtolower( uniqid() );
		$this->created_slugs[] = $slug;
		update_option( self::OPTION_KEY, array(
			$slug => array(
				'field_rules' => array(
					'bad' => array( 'type' => 'explode' ), // invalid type + missing format/storage
				),
			),
		), false );

		$found = null;
		foreach ( Field_Rules_Store::discover_all_documents() as $entry ) {
			if ( $entry['plugin_slug'] === $slug ) {
				$found = $entry;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertEquals( 'option', $found['source'] );
		$this->assertFalse( $found['validation']['ok'], 'broken doc must be flagged, not silently accepted' );
		$this->assertNotEmpty( $found['validation']['errors'] );
		// Flag-not-drop contract: an invalid doc keeps its RAW payload
		// (normalized replacement only happens for ok docs), so callers can
		// see exactly which rule is broken.
		$this->assertArrayHasKey( 'bad', $found['field_rules'] );
	}
}
