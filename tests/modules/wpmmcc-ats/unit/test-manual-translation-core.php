<?php
/**
 * Manual translation core services unit tests
 *
 * Covers the core manual-translation services with real API assertions:
 *  - WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service
 *    (term translation link/unlink persistence, pending-queue semantics,
 *    relation-scoped listing)
 *  - WPTSALL\ManualTranslation\Services\Field_Translation_Service
 *    (per-post-type field config persistence + code-like key blocking)
 *  - WPTSALL\ManualTranslation\Services\Translation_Progress_Service
 *    (summary shape contract)
 *
 * @package WPTSALL
 * @since 2.1.0
 */

use WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service;
use WPTSALL\ManualTranslation\Services\Field_Translation_Service;
use WPTSALL\ManualTranslation\Services\Translation_Progress_Service;

class Test_Manual_Translation_Core extends WP_UnitTestCase {

	/**
	 * Term IDs created during tests (terms tracked via factory; this list is
	 * for term_mappings cleanup keyed on source_term_id).
	 *
	 * @var array
	 */
	private $test_term_ids = array();

	/**
	 * term_mappings row ids created directly.
	 *
	 * @var array
	 */
	private $test_mapping_ids = array();

	/**
	 * Active site relations snapshot (id => status) taken in setUp and
	 * restored in tearDown, so tests are hermetic against ambient relation
	 * state in a shared long-lived test container.
	 *
	 * @var array
	 */
	private $active_relations_snapshot = array();

	public function setUp(): void {
		parent::setUp();
		// Ensure the term mappings schema exists even when the plugin
		// bootstrap did not create it in this process.
		if ( function_exists( 'wptsall_create_term_mappings_table' ) ) {
			wptsall_create_term_mappings_table();
		}

		// Hermetic isolation: link() intentionally auto-attaches any active
		// site relation matching the target language (relation-scoped
		// get_translations() keys). In a shared container, ambient active
		// relations left by earlier lanes break the bare-language key
		// assertions. Snapshot and deactivate active relations for the
		// duration of each test; tearDown restores the snapshot.
		global $wpdb;
		$relations_table = $wpdb->base_prefix . 'wptsall_site_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$active = $wpdb->get_results( "SELECT id, status FROM {$relations_table} WHERE status = 'active'", ARRAY_A );
		foreach ( (array) $active as $row ) {
			$this->active_relations_snapshot[ (int) $row['id'] ] = (string) $row['status'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$relations_table,
				array( 'status' => 'inactive' ),
				array( 'id' => (int) $row['id'] )
			);
		}
	}

	public function tearDown(): void {
		global $wpdb;

		// Restore ambient site relation statuses snapshotted in setUp.
		$relations_table = $wpdb->base_prefix . 'wptsall_site_relations';
		foreach ( $this->active_relations_snapshot as $rel_id => $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$relations_table,
				array( 'status' => (string) $status ),
				array( 'id' => (int) $rel_id )
			);
		}
		$this->active_relations_snapshot = array();

		// Remove term mapping rows for test terms (both custom table and
		// the legacy store kept in sync by Term_Mapping_Service).
		if ( ! empty( $this->test_term_ids ) ) {
			$ids   = implode( ',', array_map( 'intval', $this->test_term_ids ) );
			$table = $wpdb->prefix . 'wptsall_term_mappings';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$table} WHERE source_term_id IN ({$ids}) OR target_term_id IN ({$ids})" );
			$legacy = $wpdb->base_prefix . 'wptsall_mappings';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$legacy} WHERE source_object_type = 'taxonomy' AND (source_object_id IN ({$ids}) OR target_object_id IN ({$ids}))" );
		}
		foreach ( $this->test_mapping_ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->prefix . 'wptsall_term_mappings', array( 'id' => (int) $id ), array( '%d' ) );
		}
		$this->test_term_ids   = array();
		$this->test_mapping_ids = array();

		// Remove the field translation option if a test wrote it.
		delete_option( Field_Translation_Service::OPTION_KEY );

		parent::tearDown();
	}

	/**
	 * Helper: create a category term tracked for cleanup.
	 *
	 * @param string $name Term name.
	 * @return int Term ID.
	 */
	private function create_term( $name ) {
		$term_id = $this->factory()->term->create( array(
			'taxonomy' => 'category',
			'name'     => $name,
		) );
		$this->test_term_ids[] = (int) $term_id;
		return (int) $term_id;
	}

	// ==================== Taxonomy_Translation_Service ====================

	/**
	 * link() persists a term translation mapping readable via
	 * get_translations().
	 */
	public function test_link_persists_and_lists_translation() {
		$source = $this->create_term( 'MTC Source ' . uniqid() );
		$target = $this->create_term( 'MTC Target ' . uniqid() );

		$mapping_id = Taxonomy_Translation_Service::link( $source, 'category', $target, 'fr_FR' );
		$this->assertNotFalse( $mapping_id, 'link() must persist the mapping' );
		$this->assertGreaterThan( 0, $mapping_id );

		$translations = Taxonomy_Translation_Service::get_translations( $source, 'category' );
		$this->assertArrayHasKey( 'fr_FR', $translations, 'get_translations() must list the new language' );
		$this->assertSame( $target, $translations['fr_FR']['target_term_id'] );
		$this->assertSame( 'category', $translations['fr_FR']['target_taxonomy'] );
		$this->assertSame( 'fr_FR', $translations['fr_FR']['target_lang'] );
	}

	/**
	 * link() fails closed when the source or target term does not exist.
	 */
	public function test_link_rejects_missing_terms() {
		$existing = $this->create_term( 'MTC Exists ' . uniqid() );

		$this->assertFalse( Taxonomy_Translation_Service::link( 99999999, 'category', $existing, 'fr_FR' ) );
		$this->assertFalse( Taxonomy_Translation_Service::link( $existing, 'category', 99999999, 'fr_FR' ) );

		// Nothing may have been written for the failed attempts.
		$this->assertSame( array(), Taxonomy_Translation_Service::get_translations( $existing, 'category' ) );
	}

	/**
	 * pending_terms() queues exactly the terms without a mapping in the
	 * target language; once linked, a term leaves the queue.
	 */
	public function test_pending_terms_queue_lifecycle() {
		$unlinked = $this->create_term( 'MTC Pending A ' . uniqid() );
		$linked   = $this->create_term( 'MTC Pending B ' . uniqid() );
		$target   = $this->create_term( 'MTC Pending T ' . uniqid() );

		// Before linking both are pending.
		$pending = Taxonomy_Translation_Service::pending_terms( 'category', 'fr_FR' );
		$ids = array_map( static function ( $t ) { return (int) $t['term_id']; }, $pending );
		$this->assertContains( $unlinked, $ids, 'Unlinked term must be in the pending queue' );
		$this->assertContains( $linked, $ids, 'Not-yet-linked term must be in the pending queue' );

		$this->assertNotFalse( Taxonomy_Translation_Service::link( $linked, 'category', $target, 'fr_FR' ) );

		// After linking, only the unlinked term remains pending.
		$pending = Taxonomy_Translation_Service::pending_terms( 'category', 'fr_FR' );
		$ids = array_map( static function ( $t ) { return (int) $t['term_id']; }, $pending );
		$this->assertContains( $unlinked, $ids, 'Unlinked term must stay in the pending queue' );
		$this->assertNotContains( $linked, $ids, 'Linked term must leave the pending queue' );
	}

	/**
	 * unlink() removes the mapping for a language and is idempotent.
	 */
	public function test_unlink_removes_mapping() {
		$source = $this->create_term( 'MTC Unlink S ' . uniqid() );
		$target = $this->create_term( 'MTC Unlink T ' . uniqid() );

		$this->assertNotFalse( Taxonomy_Translation_Service::link( $source, 'category', $target, 'fr_FR' ) );

		$deleted = Taxonomy_Translation_Service::unlink( $source, 'category', 'fr_FR' );
		$this->assertSame( 1, $deleted, 'unlink() must report the deleted row count' );
		$this->assertSame( array(), Taxonomy_Translation_Service::get_translations( $source, 'category' ) );

		// Second unlink is a no-op (idempotent).
		$this->assertSame( 0, Taxonomy_Translation_Service::unlink( $source, 'category', 'fr_FR' ) );
	}

	/**
	 * get_translations()/unlink() honor relation scoping: with relation_id
	 * the lookup/unlink is restricted to that relation only.
	 */
	public function test_relation_scoped_listing_and_unlink() {
		$source = $this->create_term( 'MTC Rel S ' . uniqid() );
		$target = $this->create_term( 'MTC Rel T ' . uniqid() );

		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_term_mappings';
		foreach ( array( 101, 202 ) as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, array(
				'relation_id'     => $relation_id,
				'source_term_id'  => $source,
				'source_taxonomy' => 'category',
				'source_site_id'  => get_current_blog_id(),
				'source_lang'     => 'en_US',
				'target_term_id'  => $target,
				'target_taxonomy' => 'category',
				'target_site_id'  => 'v_rel',
				'target_lang'     => 'fr_FR',
				'mapping_method'  => 'manual',
			) );
			$this->test_mapping_ids[] = (int) $wpdb->insert_id;
		}

		// Relation-scoped listing returns only that relation's mapping.
		$scoped = Taxonomy_Translation_Service::get_translations( $source, 'category', 101 );
		$this->assertCount( 1, $scoped );
		$this->assertArrayHasKey( 'fr_FR', $scoped );
		$this->assertSame( 101, $scoped['fr_FR']['relation_id'] );

		// Unscoped listing surfaces both rows with distinct keys.
		$all = Taxonomy_Translation_Service::get_translations( $source, 'category' );
		$this->assertCount( 2, $all, 'Unscoped listing must surface both relation rows' );

		// Relation-scoped unlink removes only one row.
		$this->assertSame( 1, Taxonomy_Translation_Service::unlink( $source, 'category', 'fr_FR', 101 ) );
		$this->assertSame( 1, count( Taxonomy_Translation_Service::get_translations( $source, 'category', 202 ) ) );
		$this->assertSame( 0, count( Taxonomy_Translation_Service::get_translations( $source, 'category', 101 ) ) );
	}

	// ==================== Field_Translation_Service ====================

	/**
	 * Field config persists per post type through save/get roundtrip.
	 */
	public function test_field_config_roundtrip_per_post_type() {
		$pt = 'mtc_pt_' . substr( md5( uniqid( '', true ) ), 0, 8 );

		$this->assertSame( array(), Field_Translation_Service::get_for_post_type( $pt ), 'Unknown post type must yield empty config' );

		$saved = Field_Translation_Service::save_for_post_type( $pt, array(
			'subtitle' => array( 'translatable' => true, 'sync' => true ),
			'seo_title' => array( 'translatable' => false, 'sync' => false ),
		) );
		$this->assertTrue( $saved );

		$all = Field_Translation_Service::get_all();
		$this->assertArrayHasKey( $pt, $all );
		$config = Field_Translation_Service::get_for_post_type( $pt );
		$this->assertArrayHasKey( 'subtitle', $config );
		$this->assertTrue( $config['subtitle']['translatable'] );
		$this->assertTrue( $config['subtitle']['sync'] );
		$this->assertFalse( $config['seo_title']['translatable'] );

		// Other post types are untouched.
		$this->assertSame( array(), Field_Translation_Service::get_for_post_type( $pt . '_x' ) );
	}

	/**
	 * set_translatable() blocks code-like keys (CSS/JS storage) from being
	 * marked translatable but still permits disabling them.
	 */
	public function test_set_translatable_blocks_code_like_keys() {
		$pt = 'mtc_pt_' . substr( md5( uniqid( '', true ) ), 0, 8 );

		// Enabling a code-like key must fail closed and not persist.
		$this->assertFalse( Field_Translation_Service::set_translatable( $pt, 'custom_css', true ), 'Code-like key must be rejected for enable' );
		$this->assertArrayNotHasKey( 'custom_css', Field_Translation_Service::get_for_post_type( $pt ) );

		// Disabling a code-like key is allowed.
		$this->assertTrue( Field_Translation_Service::set_translatable( $pt, 'custom_css', false ) );
		$config = Field_Translation_Service::get_for_post_type( $pt );
		$this->assertArrayHasKey( 'custom_css', $config );
		$this->assertFalse( $config['custom_css']['translatable'] );

		// A normal text key persists with the requested flag.
		$this->assertTrue( Field_Translation_Service::set_translatable( $pt, 'subtitle', true ) );
		$config = Field_Translation_Service::get_for_post_type( $pt );
		$this->assertTrue( $config['subtitle']['translatable'] );
	}

	// ==================== Translation_Progress_Service ====================

	/**
	 * summary() honors its documented response shape.
	 */
	public function test_progress_summary_shape() {
		$summary = Translation_Progress_Service::summary();

		$this->assertIsArray( $summary );
		foreach ( array( 'total_source', 'total_translated', 'percent', 'by_language', 'by_post_type', 'needs_resync' ) as $key ) {
			$this->assertArrayHasKey( $key, $summary, "summary() must expose '{$key}'" );
		}
		$this->assertIsInt( $summary['total_source'] );
		$this->assertIsInt( $summary['total_translated'] );
		$this->assertIsInt( $summary['needs_resync'] );
		$this->assertGreaterThanOrEqual( 0, $summary['percent'] );
		$this->assertLessThanOrEqual( 100, $summary['percent'] );
		$this->assertIsArray( $summary['by_language'] );
		$this->assertIsArray( $summary['by_post_type'] );

		// A created post must be counted as one source entry for its type.
		$pt_marker = 'post';
		$summary_after = null; // computed below via a tracked post
		$post_id = $this->factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$summary_after = Translation_Progress_Service::summary();
		$this->assertArrayHasKey( $pt_marker, $summary_after['by_post_type'], 'Published post type must appear in by_post_type' );
		$this->assertGreaterThan( 0, $summary_after['by_post_type'][ $pt_marker ]['total'], 'The created post must be counted' );
		$this->assertGreaterThanOrEqual( 1, $summary_after['total_source'] - 0 );
	}
}
