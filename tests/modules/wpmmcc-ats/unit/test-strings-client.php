<?php
/**
 * String client flow Tests (Layer B)
 *
 * Tests for WPTSALL\Strings\Services\String_Translation_Service client-side
 * surface: subtype -> context mapping, untranslated discovery for client pull,
 * claim leases and client translation callback application.
 *
 * @package WPTSALL\Tests\Unit\Strings
 * @since 2.3.0
 */

use WPTSALL\Strings\Services\String_Translation_Service;

class Test_String_Client_Flow extends SimpleTestCase {

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
	 * Register a site_title row with a unique key and track it for cleanup.
	 */
	private function register_site_title( $text ) {
		$id = String_Translation_Service::register( 'site_title', 'unit_client_' . uniqid(), $text );
		$this->assertNotFalse( $id );
		$this->created_ids[] = (int) $id;
		return (int) $id;
	}

	// ==================== contexts_for_subtype ====================

	public function test_contexts_for_subtype_mapping() {
		$svc = 'WPTSALL\Strings\Services\String_Translation_Service';

		$this->assertSame( array( 'site_title', 'site_tagline' ), $svc::contexts_for_subtype( 'site' ) );
		$this->assertSame( array( 'menu' ), $svc::contexts_for_subtype( 'menu' ) );
		$this->assertSame( array( 'widget' ), $svc::contexts_for_subtype( 'widget' ) );
		$this->assertSame( array(), $svc::contexts_for_subtype( 'unknown' ) );
		// Uppercase / spaced input is sanitized to a key before matching.
		$this->assertSame( array( 'site_title', 'site_tagline' ), $svc::contexts_for_subtype( 'Site ' ) );
	}

	// ==================== list_untranslated_for_client ====================

	public function test_list_untranslated_scopes_by_include_ids_and_excludes_translated() {
		$id_a = $this->register_site_title( 'Client pull A' );
		$id_b = $this->register_site_title( 'Client pull B' );
		String_Translation_Service::set_translations( $id_b, array( 'en_US' => 'Client pull B EN' ) );

		$res = String_Translation_Service::list_untranslated_for_client( 'en_US', 'site', 1, 50, array( $id_a, $id_b ) );
		$this->assertIsArray( $res );
		$this->assertSame( 1, (int) $res['total'] );
		$this->assertCount( 1, $res['items'] );

		$item = $res['items'][0];
		$this->assertSame( 'site_string', $item['object_type'] );
		$this->assertSame( 'site', $item['subtype'] );
		$this->assertSame( $id_a, (int) $item['object_id'] );

		$cd = $item['complete_data'];
		$this->assertSame( $id_a, (int) $cd['string_id'] );
		$this->assertSame( $id_a, (int) $cd['entry_id'] );
		$this->assertSame( 'Client pull A', $cd['msgid'] );
		$this->assertSame( 'site_title', $cd['context'] );
		$this->assertSame( 'site_title', $cd['text_domain'] );
		$this->assertSame( 'Client pull A', $cd['source_text'] );
	}

	public function test_list_untranslated_empty_for_unknown_subtype() {
		$id = $this->register_site_title( 'Unknown subtype probe' );
		$res = String_Translation_Service::list_untranslated_for_client( 'en_US', 'bogus_subtype', 1, 50, array( $id ) );
		$this->assertSame( array( 'items' => array(), 'total' => 0 ), $res );
	}

	public function test_list_untranslated_paginates_after_translation_filter() {
		$id_a = $this->register_site_title( 'Page probe A' );
		$id_b = $this->register_site_title( 'Page probe B' );

		$page1 = String_Translation_Service::list_untranslated_for_client( 'en_US', 'site', 1, 1, array( $id_a, $id_b ) );
		$this->assertSame( 2, (int) $page1['total'] );
		$this->assertCount( 1, $page1['items'] );

		$page2 = String_Translation_Service::list_untranslated_for_client( 'en_US', 'site', 2, 1, array( $id_a, $id_b ) );
		$this->assertCount( 1, $page2['items'] );
		$this->assertNotSame( (int) $page1['items'][0]['object_id'], (int) $page2['items'][0]['object_id'] );
	}

	// ==================== claim_string_ids / claim_strings ====================

	public function test_claim_rejects_invalid_owner_hash() {
		$id = $this->register_site_title( 'Claim hash probe' );
		$this->assertSame( array(), String_Translation_Service::claim_string_ids( array( $id ), array(), 'not-a-sha256' ) );

		$row = String_Translation_Service::get( $id );
		$this->assertEmpty( (string) ( $row['claimed_at'] ?? '' ) );
	}

	public function test_claim_acquires_pending_rows_once() {
		$owner = str_repeat( 'a1', 32 ); // 64 hex chars.
		$id    = $this->register_site_title( 'Claim once probe' );

		$claimed = String_Translation_Service::claim_string_ids( array( $id ), array(), $owner );
		$this->assertSame( array( $id ), $claimed );

		$row = String_Translation_Service::get( $id );
		$this->assertNotEmpty( (string) $row['claimed_at'] );
		$this->assertSame( strtolower( $owner ), strtolower( (string) $row['claim_owner_hash'] ) );

		// A second (different) owner must not steal an active lease.
		$other = str_repeat( 'b2', 32 );
		$this->assertSame( array(), String_Translation_Service::claim_string_ids( array( $id ), array(), $other ) );
	}

	public function test_claim_enforces_context_allowlist_and_target_lang() {
		$owner = str_repeat( 'c3', 32 );

		// site_title rows must not be claimable through the 'widget' context allowlist.
		$site_id = $this->register_site_title( 'Ctx allowlist probe' );
		$this->assertSame( array(), String_Translation_Service::claim_string_ids( array( $site_id ), array( 'widget' ), $owner ) );

		// A row that already carries a translation for the target language is not claimable.
		$id_done = $this->register_site_title( 'Already translated probe' );
		String_Translation_Service::set_translations( $id_done, array( 'en_US' => 'Déjà traduit' ) );
		$this->assertSame( array(), String_Translation_Service::claim_string_ids( array( $id_done ), array( 'site_title' ), $owner, 'en_US' ) );
	}

	public function test_claim_strings_returns_count() {
		$owner = str_repeat( 'd4', 32 );
		$ids   = array( $this->register_site_title( 'Count claim A' ), $this->register_site_title( 'Count claim B' ) );
		$this->assertSame( 2, String_Translation_Service::claim_strings( $ids, array(), $owner ) );
	}

	public function test_claim_empty_input_returns_empty() {
		$this->assertSame( array(), String_Translation_Service::claim_string_ids( array() ) );
		$this->assertSame( array(), String_Translation_Service::claim_string_ids( array( 0, 'x', -1 ) ) );
	}

	// ==================== apply_client_translations ====================

	public function test_apply_without_claim_writes_translation_and_clears_lease() {
		$id = $this->register_site_title( 'Apply no-claim probe' );

		$fired = array();
		$cb = static function ( $hid, $lang, $text ) use ( &$fired ) {
			$fired[] = array( (int) $hid, (string) $lang, (string) $text );
		};
		add_action( 'wptsall_string_translated', $cb, 10, 3 );
		try {
			$updated = String_Translation_Service::apply_client_translations(
				'en_US',
				array( array( 'string_id' => $id, 'msgstr' => 'Applied EN' ) ),
				array( 'site_title' )
			);
			$this->assertSame( 1, $updated );
			$this->assertSame( array( array( $id, 'en_US', 'Applied EN' ) ), $fired );
		} finally {
			remove_action( 'wptsall_string_translated', $cb, 10, 3 );
		}

		$row = String_Translation_Service::get( $id );
		$this->assertSame( 'translated', $row['status'] );
		$this->assertSame( 'Applied EN', json_decode( $row['translations'], true )['en_US'] );
		$this->assertEmpty( (string) ( $row['claimed_at'] ?? '' ) );
	}

	public function test_apply_requires_matching_claim_when_enforced() {
		$owner = str_repeat( 'e5', 32 );
		$id    = $this->register_site_title( 'Apply claim probe' );

		$entries = array( array( 'string_id' => $id, 'msgstr' => 'With claim EN' ) );

		// require_claim without a lease -> nothing written.
		$this->assertSame( 0, String_Translation_Service::apply_client_translations( 'en_US', $entries, array( 'site_title' ), true, $owner ) );
		$this->assertSame( 'pending', String_Translation_Service::get( $id )['status'] );

		// require_claim with a malformed owner hash is rejected outright.
		$this->assertSame( 0, String_Translation_Service::apply_client_translations( 'en_US', $entries, array( 'site_title' ), true, 'zz' ) );

		// Claim with the right owner, then apply with the same owner.
		$this->assertSame( array( $id ), String_Translation_Service::claim_string_ids( array( $id ), array( 'site_title' ), $owner, 'en_US' ) );
		$this->assertSame( 1, String_Translation_Service::apply_client_translations( 'en_US', $entries, array( 'site_title' ), true, $owner ) );

		$row = String_Translation_Service::get( $id );
		$this->assertSame( 'translated', $row['status'] );
		$this->assertSame( 'With claim EN', json_decode( $row['translations'], true )['en_US'] );
		// Lease is released after a successful write.
		$this->assertEmpty( (string) ( $row['claimed_at'] ?? '' ) );
	}

	public function test_apply_ignores_bad_entries_and_wrong_context() {
		$id = $this->register_site_title( 'Apply guard probe' );

		$updated = String_Translation_Service::apply_client_translations(
			'en_US',
			array(
				'not-an-array',
				array( 'string_id' => 0, 'msgstr' => 'x' ),
				array( 'string_id' => $id, 'msgstr' => '' ),
				array( 'string_id' => 999999999, 'msgstr' => 'ghost' ),
			),
			array( 'site_title' )
		);
		$this->assertSame( 0, $updated );
		$this->assertSame( 'pending', String_Translation_Service::get( $id )['status'] );

		// Context allowlist mismatch: site_title row, widget allowlist.
		$this->assertSame(
			0,
			String_Translation_Service::apply_client_translations( 'en_US', array( array( 'string_id' => $id, 'msgstr' => 'Wrong ctx' ) ), array( 'widget' ) )
		);
		$this->assertSame( 'pending', String_Translation_Service::get( $id )['status'] );
	}
}
