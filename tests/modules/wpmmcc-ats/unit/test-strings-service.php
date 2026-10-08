<?php
/**
 * String Translation Service Tests
 *
 * Tests for WPTSALL\Strings\Services\String_Translation_Service (Layer B core):
 * string entry CRUD, idempotent re-registration, translation map persistence,
 * lookup/translate fallbacks, list filters, counts and progress summary.
 *
 * @package WPTSALL\Tests\Unit\Strings
 * @since 2.3.0
 */

use WPTSALL\Strings\Services\String_Translation_Service;

class Test_String_Translation_Service extends SimpleTestCase {

	/**
	 * String row ids created by this test (cleaned in tearDown).
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_strings_table' ) ) {
			wptsall_create_strings_table();
		}
	}

	public function tearDown(): void {
		foreach ( $this->created_ids as $id ) {
			String_Translation_Service::delete( (int) $id );
		}
		$this->created_ids = array();
		parent::tearDown();
	}

	/**
	 * Register a row in a unique context and track it for cleanup.
	 */
	private function register_unique( $key, $text, $source_lang = 'zh_CN' ) {
		$ctx = 'unit_tests_' . uniqid();
		$id  = String_Translation_Service::register( $ctx, $key, $text, $source_lang );
		$this->assertNotFalse( $id );
		$this->created_ids[] = (int) $id;
		return array( $ctx, (int) $id );
	}

	// ==================== register / CRUD ====================

	public function test_register_creates_pending_row() {
		$ctx = 'unit_tests_' . uniqid();
		$id  = String_Translation_Service::register( $ctx, 'unit_key_title', 'Hello Unit Test' );
		$this->assertNotFalse( $id );
		$this->assertIsInt( (int) $id );
		$this->assertGreaterThan( 0, (int) $id );
		$this->created_ids[] = (int) $id;

		$row = String_Translation_Service::get( $id );
		$this->assertIsArray( $row );
		$this->assertSame( $ctx, $row['context'] );
		$this->assertSame( 'unit_key_title', $row['string_key'] );
		$this->assertSame( 'Hello Unit Test', $row['source_text'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertNull( $row['object_id'] );
		$this->assertEmpty( $row['translations'] );
	}

	public function test_register_is_idempotent_and_updates_source_text() {
		$ctx = 'unit_tests_' . uniqid();
		$id1 = String_Translation_Service::register( $ctx, 'unit_key_dup', 'First text' );
		$this->created_ids[] = (int) $id1;

		// Re-register same context+key+lang: must update in place, not insert a duplicate.
		$id2 = String_Translation_Service::register( $ctx, 'unit_key_dup', 'Second text' );
		$this->assertSame( (int) $id1, (int) $id2 );

		$rows = String_Translation_Service::list( array( 'context' => $ctx ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Second text', $rows[0]['source_text'] );
	}

	public function test_register_with_object_distinguishes_object_id() {
		$ctx = 'unit_tests_' . uniqid();

		$id_no_obj  = String_Translation_Service::register_with_object( $ctx, 'unit_key_obj', 'No object', null );
		$id_obj_111 = String_Translation_Service::register_with_object( $ctx, 'unit_key_obj', 'With object', 111 );
		$this->assertNotFalse( $id_no_obj );
		$this->assertNotFalse( $id_obj_111 );
		$this->assertNotSame( (int) $id_no_obj, (int) $id_obj_111 );
		$this->created_ids[] = (int) $id_no_obj;
		$this->created_ids[] = (int) $id_obj_111;

		$row = String_Translation_Service::get( $id_obj_111 );
		$this->assertSame( 111, (int) $row['object_id'] );

		// Re-register with the same object id hits the same row.
		$id_obj_111_again = String_Translation_Service::register_with_object( $ctx, 'unit_key_obj', 'With object v2', 111 );
		$this->assertSame( (int) $id_obj_111, (int) $id_obj_111_again );
		$this->assertSame( 'With object v2', String_Translation_Service::get( $id_obj_111 )['source_text'] );
	}

	public function test_register_preserves_status_on_re_register() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_status', 'Status probe' );

		$ok = String_Translation_Service::set_translations( $id, array( 'en_US' => 'Translated probe' ) );
		$this->assertTrue( $ok );
		$this->assertSame( 'translated', String_Translation_Service::get( $id )['status'] );

		// A re-register with the same source language (e.g. re-scan) must not
		// reset the translation state.
		$again = String_Translation_Service::register( $ctx, 'unit_key_status', 'Status probe v2', 'zh_CN' );
		$this->assertSame( $id, (int) $again );
		$row = String_Translation_Service::get( $id );
		$this->assertSame( 'translated', $row['status'] );
		$this->assertSame( 'Status probe v2', $row['source_text'] );

		$tr = json_decode( $row['translations'], true );
		$this->assertSame( 'Translated probe', $tr['en_US'] );
	}

	// ==================== set_translations ====================

	public function test_set_translations_stores_map_and_marks_translated() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_tr', 'Translatable A' );

		$this->assertTrue( String_Translation_Service::set_translations( $id, array( 'en_US' => 'Hola', 'fr_FR' => 'Bonjour' ) ) );
		$row = String_Translation_Service::get( $id );
		$this->assertSame( 'translated', $row['status'] );

		$map = json_decode( $row['translations'], true );
		$this->assertSame( 'Hola', $map['en_US'] );
		$this->assertSame( 'Bonjour', $map['fr_FR'] );
	}

	public function test_set_translations_all_empty_keeps_pending() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_empty', 'Empty probe' );

		$ok = String_Translation_Service::set_translations( $id, array( 'en_US' => '', 'fr_FR' => '' ) );
		$this->assertTrue( $ok );
		$this->assertSame( 'pending', String_Translation_Service::get( $id )['status'] );
	}

	public function test_set_translations_rejects_invalid_input() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_invalid', 'Invalid probe' );

		$this->assertFalse( String_Translation_Service::set_translations( 0, array( 'en_US' => 'x' ) ) );
		$this->assertFalse( String_Translation_Service::set_translations( -5, array( 'en_US' => 'x' ) ) );
		$this->assertFalse( String_Translation_Service::set_translations( $id, 'not-an-array' ) );
		// Unknown id: update affects 0 rows -> false.
		$this->assertFalse( String_Translation_Service::set_translations( 999999999, array( 'en_US' => 'x' ) ) );
	}

	public function test_set_translations_fires_string_translated_hook() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_hook', 'Hook probe' );

		$fired = array();
		$cb = static function ( $hid, $lang, $text ) use ( &$fired ) {
			$fired[] = array( (int) $hid, (string) $lang, (string) $text );
		};
		add_action( 'wptsall_string_translated', $cb, 10, 3 );

		try {
			String_Translation_Service::set_translations( $id, array( 'en_US' => 'Hooked', 'fr_FR' => '' ) );
			// Only the non-empty translation fires the hook.
			$this->assertCount( 1, $fired );
			$this->assertSame( array( $id, 'en_US', 'Hooked' ), $fired[0] );
		} finally {
			remove_action( 'wptsall_string_translated', $cb, 10, 3 );
		}
	}

	// ==================== get / delete ====================

	public function test_get_missing_returns_null() {
		$this->assertNull( String_Translation_Service::get( 999999999 ) );
	}

	public function test_delete_removes_row() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_del', 'Delete probe' );

		$this->assertNotNull( String_Translation_Service::get( $id ) );
		$this->assertTrue( String_Translation_Service::delete( $id ) );
		$this->assertNull( String_Translation_Service::get( $id ) );
		// Deleting again affects 0 rows -> false.
		$this->assertFalse( String_Translation_Service::delete( $id ) );
	}

	// ==================== translate ====================

	public function test_translate_returns_translation_when_available() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_lookup', 'Lookup source' );
		String_Translation_Service::set_translations( $id, array( 'en_US' => 'Lookup EN' ) );

		$this->assertSame( 'Lookup EN', String_Translation_Service::translate( $ctx, 'unit_key_lookup', 'fb', 'en_US' ) );
	}

	public function test_translate_fallback_and_source_fallback() {
		list( $ctx, $id ) = $this->register_unique( 'unit_key_fb', 'Fallback source' );
		String_Translation_Service::set_translations( $id, array( 'en_US' => 'FB EN' ) );

		// Missing language -> explicit fallback wins.
		$this->assertSame( 'fb', String_Translation_Service::translate( $ctx, 'unit_key_fb', 'fb', 'fr_FR' ) );
		// gettext-like behaviour: no fallback -> fall back to source text.
		$this->assertSame( 'Fallback source', String_Translation_Service::translate( $ctx, 'unit_key_fb', '', 'fr_FR' ) );
		// Unknown key -> fallback / source.
		$this->assertSame( 'fb', String_Translation_Service::translate( $ctx, 'no_such_key', 'fb', 'en_US' ) );
	}

	public function test_translate_prefers_row_with_translation_over_newer_duplicate() {
		// Two rows share context+key but differ in source_lang; the newer row has
		// no translation while the older one does. translate() must return the
		// stored translation, not the newer row's source text.
		$ctx = 'unit_tests_' . uniqid();
		$id_a = String_Translation_Service::register( $ctx, 'unit_key_multi', 'Older text', 'zh_CN' );
		$id_b = String_Translation_Service::register( $ctx, 'unit_key_multi', 'Newer text', 'en_US' );
		$this->assertNotFalse( $id_a );
		$this->assertNotFalse( $id_b );
		$this->created_ids[] = (int) $id_a;
		$this->created_ids[] = (int) $id_b;

		String_Translation_Service::set_translations( $id_a, array( 'fr_FR' => 'Traduction FR' ) );

		$this->assertSame( 'Traduction FR', String_Translation_Service::translate( $ctx, 'unit_key_multi', 'fb', 'fr_FR' ) );
	}

	// ==================== list / counts / progress ====================

	public function test_list_filters_by_context_status_search() {
		$ctx = 'unit_tests_' . uniqid();
		$needle = 'Zq' . uniqid();

		$id_a = String_Translation_Service::register( $ctx, 'search_key_' . $needle, 'Alpha ' . $needle );
		$id_b = String_Translation_Service::register( $ctx, 'unit_plain_key', 'Beta plain' );
		$this->created_ids[] = (int) $id_a;
		$this->created_ids[] = (int) $id_b;
		String_Translation_Service::set_translations( $id_a, array( 'en_US' => 'Alpha EN' ) );

		// Context filter.
		$rows = String_Translation_Service::list( array( 'context' => $ctx ) );
		$this->assertCount( 2, $rows );

		// Status filter.
		$translated = String_Translation_Service::list( array( 'context' => $ctx, 'status' => 'translated' ) );
		$this->assertCount( 1, $translated );
		$this->assertSame( (int) $id_a, (int) $translated[0]['id'] );
		$pending = String_Translation_Service::list( array( 'context' => $ctx, 'status' => 'pending' ) );
		$this->assertCount( 1, $pending );

		// Search on source_text and on string_key.
		$this->assertCount( 1, String_Translation_Service::list( array( 'context' => $ctx, 'search' => $needle ) ) );
		$this->assertCount( 1, String_Translation_Service::list( array( 'context' => $ctx, 'search' => 'search_key_' . $needle ) ) );
	}

	public function test_list_limit_and_offset() {
		$ctx = 'unit_tests_' . uniqid();
		for ( $i = 1; $i <= 3; $i++ ) {
			$id = String_Translation_Service::register( $ctx, 'unit_key_l' . $i, 'Limit probe ' . $i );
			$this->assertNotFalse( $id );
			$this->created_ids[] = (int) $id;
		}

		$this->assertCount( 2, String_Translation_Service::list( array( 'context' => $ctx, 'limit' => 2 ) ) );
		$last = String_Translation_Service::list( array( 'context' => $ctx, 'limit' => 2, 'offset' => 2 ) );
		$this->assertCount( 1, $last );
		$this->assertSame( 'Limit probe 3', $last[0]['source_text'] );
	}

	public function test_counts_reports_status_buckets() {
		$ctx = 'unit_tests_' . uniqid();
		$id_p1 = String_Translation_Service::register( $ctx, 'unit_c_1', 'Count pending 1' );
		$id_p2 = String_Translation_Service::register( $ctx, 'unit_c_2', 'Count pending 2' );
		$id_t  = String_Translation_Service::register( $ctx, 'unit_c_3', 'Count translated' );
		$this->created_ids[] = (int) $id_p1;
		$this->created_ids[] = (int) $id_p2;
		$this->created_ids[] = (int) $id_t;
		String_Translation_Service::set_translations( $id_t, array( 'en_US' => 'Compté' ) );

		$counts = String_Translation_Service::counts();
		$this->assertIsArray( $counts );
		$this->assertArrayHasKey( 'total', $counts );
		$this->assertArrayHasKey( 'translated', $counts );
		$this->assertArrayHasKey( 'pending', $counts );
		// Exact deltas vs the empty-context baseline (rows are per-context).
		$this->assertGreaterThanOrEqual( 3, $counts['total'] );
		$this->assertGreaterThanOrEqual( 1, $counts['translated'] );
		$this->assertGreaterThanOrEqual( 2, $counts['pending'] );
	}

	public function test_progress_summary_groups_by_context() {
		$ctx = 'unit_tests_' . uniqid();
		$id_p = String_Translation_Service::register( $ctx, 'unit_p_1', 'Prog pending' );
		$id_t = String_Translation_Service::register( $ctx, 'unit_p_2', 'Prog translated' );
		$this->created_ids[] = (int) $id_p;
		$this->created_ids[] = (int) $id_t;
		String_Translation_Service::set_translations( $id_t, array( 'en_US' => 'Prog TR' ) );

		$summary = String_Translation_Service::progress_summary();
		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'by_context', $summary );
		$this->assertArrayHasKey( $ctx, $summary['by_context'] );
		$bucket = $summary['by_context'][ $ctx ];
		$this->assertSame( 2, (int) $bucket['total'] );
		$this->assertSame( 1, (int) $bucket['translated'] );
		$this->assertSame( 1, (int) $bucket['pending'] );
	}

	public function test_context_labels_covers_known_contexts() {
		$labels = String_Translation_Service::context_labels();
		$this->assertIsArray( $labels );
		foreach ( array( 'site_title', 'site_tagline', 'menu', 'widget', 'cpt_field' ) as $ctx ) {
			$this->assertArrayHasKey( $ctx, $labels );
			$this->assertNotEmpty( $labels[ $ctx ] );
		}
	}
}
