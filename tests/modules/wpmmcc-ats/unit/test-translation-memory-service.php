<?php
/**
 * Translation Memory Service Tests
 *
 * Tests for WPTSALL\TranslationMemory\Services\Translation_Memory_Service.
 *
 * The unit runner does not create the wptsall_translation_memory table, so
 * setUp() requires the plugin schema file and creates it (CREATE TABLE IF
 * NOT EXISTS semantics via dbDelta).
 *
 * catalog: WP-CLASS-Translation_Memory_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 1.2.0
 */

use WPTSALL\TranslationMemory\Services\Translation_Memory_Service;

class Test_Translation_Memory_Service extends SimpleTestCase {

	/**
	 * Unique marker embedded in every source text created by this run so
	 * tearDown can remove exactly this test's rows (the shared TM table is
	 * not dropped between runs).
	 *
	 * @var string
	 */
	private $marker = '';

	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wptsall_create_translation_memory_table' ) ) {
			require_once WPTSALL_PATH . 'includes/translation-memory/database/schema-translation-memory.php';
		}
		wptsall_create_translation_memory_table();

		$this->marker = 'wptm-test-' . uniqid();
	}

	public function tearDown(): void {
		global $wpdb;
		if ( $this->marker ) {
			$table = $wpdb->prefix . 'wptsall_translation_memory';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE source_text LIKE %s OR target_text LIKE %s',
					$table,
					'%' . $wpdb->esc_like( $this->marker ) . '%',
					'%' . $wpdb->esc_like( $this->marker ) . '%'
				)
			);
		}
		parent::tearDown();
	}

	/**
	 * Fetch the raw row for a source text created by this test.
	 *
	 * @param string $source_text
	 * @return array|null
	 */
	private function get_row( $source_text ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_translation_memory';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE source_text = %s', $table, $source_text ),
			ARRAY_A
		);
	}

	/**
	 * record() then lookup() returns the recorded target text.
	 */
	public function test_record_and_lookup_roundtrip() {
		$src = 'Hello ' . $this->marker;
		$trg = '你好 ' . $this->marker;

		$ok = Translation_Memory_Service::record( $src, 'en_US', 'zh_CN', $trg, 'test-domain', 'test-context' );
		$this->assertTrue( $ok, 'record() should succeed when the table exists and texts are non-empty' );

		$this->assertEquals( $trg, Translation_Memory_Service::lookup( $src, 'en_US', 'zh_CN' ) );

		// Domain / context are persisted on the row.
		$row = $this->get_row( $src );
		$this->assertNotNull( $row );
		$this->assertEquals( 'test-domain', $row['domain'] );
		$this->assertEquals( 'test-context', $row['context'] );
	}

	/**
	 * lookup() returns '' for an unknown pair or wrong language pair.
	 */
	public function test_lookup_miss_returns_empty_string() {
		$src = 'Known ' . $this->marker;
		$this->assertTrue( Translation_Memory_Service::record( $src, 'en_US', 'zh_CN', '已知' . $this->marker ) );

		$this->assertEquals( '', Translation_Memory_Service::lookup( 'Unknown ' . $this->marker, 'en_US', 'zh_CN' ) );
		// Same source text but a different language pair must not match.
		$this->assertEquals( '', Translation_Memory_Service::lookup( $src, 'en_US', 'fr_FR' ) );
	}

	/**
	 * record() rejects blank source/target texts.
	 */
	public function test_record_rejects_empty_text() {
		$this->assertFalse( Translation_Memory_Service::record( '   ', 'en_US', 'zh_CN', 'x' . $this->marker ) );
		$this->assertFalse( Translation_Memory_Service::record( 'x' . $this->marker, 'en_US', 'zh_CN', '   ' ) );
		// Nothing was written.
		$this->assertNull( $this->get_row( 'x' . $this->marker ) );
	}

	/**
	 * lookup() bumps occurrences + last_used_at on the matched row.
	 */
	public function test_lookup_bumps_occurrences() {
		$src = 'Bump ' . $this->marker;
		$this->assertTrue( Translation_Memory_Service::record( $src, 'en_US', 'zh_CN', '命中' . $this->marker ) );

		$row = $this->get_row( $src );
		$this->assertEquals( 1, (int) $row['occurrences'], 'a fresh row starts with occurrences = 1' );

		Translation_Memory_Service::lookup( $src, 'en_US', 'zh_CN' );
		Translation_Memory_Service::lookup( $src, 'en_US', 'zh_CN' );

		$row = $this->get_row( $src );
		$this->assertEquals( 3, (int) $row['occurrences'], 'two lookups should bump occurrences 1 → 3' );
		$this->assertNotEmpty( $row['last_used_at'], 'lookup should set last_used_at' );
	}

	/**
	 * Recording the same pair again updates the row instead of duplicating it.
	 * Also verifies the occurrences-count behavior documented in the code
	 * comment ("existing rows already have occurrence count, leave it").
	 */
	public function test_record_update_refreshes_target_text_single_row() {
		$src = 'Update ' . $this->marker;
		$this->assertTrue( Translation_Memory_Service::record( $src, 'en_US', 'zh_CN', '旧译文' . $this->marker ) );
		Translation_Memory_Service::lookup( $src, 'en_US', 'zh_CN' ); // occurrences 1 → 2

		$this->assertTrue( Translation_Memory_Service::record( $src, 'en_US', 'zh_CN', '新译文' . $this->marker ) );

		// Inspect occurrences BEFORE any further lookup (lookup also bumps the
		// counter, which would mask a reset on the update path).
		$row = $this->get_row( $src );
		$this->assertNotNull( $row );
		$this->assertGreaterThanOrEqual( 2, (int) $row['occurrences'] );

		// lookup returns the refreshed text.
		$this->assertEquals( '新译文' . $this->marker, Translation_Memory_Service::lookup( $src, 'en_US', 'zh_CN' ) );
	}

	/**
	 * list_pairs() filters by language pair and LIKE search, ordered by occurrences.
	 */
	public function test_list_pairs_search_and_lang_filters() {
		$this->assertTrue( Translation_Memory_Service::record( 'Alpha ' . $this->marker, 'en_US', 'zh_CN', '甲' . $this->marker ) );
		$this->assertTrue( Translation_Memory_Service::record( 'Beta ' . $this->marker, 'en_US', 'fr_FR', '乙' . $this->marker ) );

		// Search matches only rows containing the marker.
		$hits = Translation_Memory_Service::list_pairs( array( 'search' => $this->marker ) );
		$this->assertCount( 2, $hits );
		foreach ( $hits as $hit ) {
			$this->assertStringContainsString( $this->marker, $hit['source_text'] . $hit['target_text'] );
		}

		// Language-pair filter narrows to one row.
		$zh = Translation_Memory_Service::list_pairs( array(
			'search'      => $this->marker,
			'source_lang' => 'en_US',
			'target_lang' => 'zh_CN',
		) );
		$this->assertCount( 1, $zh );
		$this->assertEquals( 'Alpha ' . $this->marker, $zh[0]['source_text'] );

		// Limit is honored.
		$limited = Translation_Memory_Service::list_pairs( array( 'search' => $this->marker, 'limit' => 1 ) );
		$this->assertCount( 1, $limited );
	}

	/**
	 * counts() reports total rows and distinct language pairs.
	 */
	public function test_counts_reflect_seeded_pairs() {
		$before = Translation_Memory_Service::counts();

		$this->assertTrue( Translation_Memory_Service::record( 'CountA ' . $this->marker, 'en_US', 'zh_CN', '计甲' . $this->marker ) );
		$this->assertTrue( Translation_Memory_Service::record( 'CountB ' . $this->marker, 'en_US', 'de_DE', '计乙' . $this->marker ) );

		$after = Translation_Memory_Service::counts();
		$this->assertEquals( $before['total'] + 2, $after['total'] );
		$this->assertGreaterThanOrEqual( $before['pairs'] + 1, $after['pairs'], 'two new distinct language pairs should raise the pair count' );
	}

	/**
	 * delete() removes a pair by id; lookup afterwards misses.
	 */
	public function test_delete_removes_pair() {
		$src = 'Delete ' . $this->marker;
		$this->assertTrue( Translation_Memory_Service::record( $src, 'en_US', 'zh_CN', '删除' . $this->marker ) );
		$row = $this->get_row( $src );
		$this->assertNotNull( $row );

		$this->assertTrue( Translation_Memory_Service::delete( (int) $row['id'] ) );
		$this->assertNull( $this->get_row( $src ) );
		$this->assertEquals( '', Translation_Memory_Service::lookup( $src, 'en_US', 'zh_CN' ) );
		// Deleting a non-existent id returns false.
		$this->assertFalse( Translation_Memory_Service::delete( (int) $row['id'] ) );
	}

	/**
	 * Create a zh_CN -> en_US relation row for suggest_for_relation_domain() tests.
	 */
	private function create_suggest_relation() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'wptsall_site_relations',
			array(
				'source_site_id'   => 1,
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'wordpress-blog',
				'target_site_id'   => 'v_tm_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove a relation row created by create_suggest_relation().
	 */
	private function delete_suggest_relation( $relation_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $wpdb->prefix . 'wptsall_site_relations', array( 'id' => (int) $relation_id ), array( '%d' ) );
	}

	/**
	 * suggest_for_relation_domain() returns the recorded pair for the
	 * relation's language pair inside the requested domain, and trims the
	 * incoming source text before matching.
	 */
	public function test_suggest_for_relation_domain_returns_recorded_pair() {
		$relation_id = $this->create_suggest_relation();
		$src         = 'Suggest ' . $this->marker;

		$this->assertTrue( Translation_Memory_Service::record( $src, 'zh_CN', 'en_US', '建议' . $this->marker, 'wordpress-blog' ) );

		$items = Translation_Memory_Service::suggest_for_relation_domain( $relation_id, 'wordpress-blog', '  ' . $src . '  ' );
		$this->delete_suggest_relation( $relation_id );

		$this->assertCount( 1, $items );
		$this->assertEquals( '建议' . $this->marker, $items[0]['target_text'] );
		$this->assertEquals( 'wordpress-blog', $items[0]['domain'] );
		$this->assertIsInt( $items[0]['occurrences'] );
	}

	/**
	 * Domain scoping: a pair recorded under a plugin domain is invisible to
	 * the generic 'wordpress-blog' domain and vice versa.
	 */
	public function test_suggest_for_relation_domain_scopes_by_domain() {
		$relation_id = $this->create_suggest_relation();
		$src         = 'Scoped ' . $this->marker;

		$this->assertTrue( Translation_Memory_Service::record( $src, 'zh_CN', 'en_US', '限定' . $this->marker, 'my-plugin-domain' ) );

		$miss = Translation_Memory_Service::suggest_for_relation_domain( $relation_id, 'wordpress-blog', $src );
		$hit  = Translation_Memory_Service::suggest_for_relation_domain( $relation_id, 'my-plugin-domain', $src );
		$this->delete_suggest_relation( $relation_id );

		$this->assertSame( array(), $miss, 'pair recorded under another domain must not leak into wordpress-blog suggestions' );
		$this->assertCount( 1, $hit );
		$this->assertEquals( '限定' . $this->marker, $hit[0]['target_text'] );
	}

	/**
	 * A non-existent relation yields no suggestions instead of an error.
	 */
	public function test_suggest_for_relation_domain_requires_existing_relation() {
		$src = 'Missing ' . $this->marker;
		$this->assertTrue( Translation_Memory_Service::record( $src, 'zh_CN', 'en_US', '缺失' . $this->marker, 'wordpress-blog' ) );

		$this->assertSame( array(), Translation_Memory_Service::suggest_for_relation_domain( 99999999, 'wordpress-blog', $src ) );
	}

	/**
	 * When a context filter is supplied, only pairs with that exact context match.
	 */
	public function test_suggest_for_relation_domain_filters_by_context() {
		$relation_id = $this->create_suggest_relation();
		$src         = 'Context ' . $this->marker;

		$this->assertTrue( Translation_Memory_Service::record( $src, 'zh_CN', 'en_US', '语境' . $this->marker, 'wordpress-blog', 'ctx-a' ) );

		$hit  = Translation_Memory_Service::suggest_for_relation_domain( $relation_id, 'wordpress-blog', $src, 'ctx-a' );
		$miss = Translation_Memory_Service::suggest_for_relation_domain( $relation_id, 'wordpress-blog', $src, 'ctx-b' );
		$this->delete_suggest_relation( $relation_id );

		$this->assertCount( 1, $hit );
		$this->assertEquals( '语境' . $this->marker, $hit[0]['target_text'] );
		$this->assertSame( array(), $miss );
	}

	/**
	 * opus5 M-04: automatic recording defaults to on and follows the
	 * wptsall_tm_auto_record option.
	 */
	public function test_auto_record_toggle_defaults_on_and_reads_option() {
		delete_option( Translation_Memory_Service::OPTION_AUTO_RECORD );
		$this->assertTrue( Translation_Memory_Service::is_auto_record_enabled(), 'auto-record must default to enabled' );

		update_option( Translation_Memory_Service::OPTION_AUTO_RECORD, 0 );
		$disabled = Translation_Memory_Service::is_auto_record_enabled();
		delete_option( Translation_Memory_Service::OPTION_AUTO_RECORD );

		$this->assertFalse( $disabled, 'option 0 must disable auto recording' );
		$this->assertTrue( Translation_Memory_Service::is_auto_record_enabled(), 'state must be restored after the test' );
	}

	/**
	 * opus5 M-04: record_translation_pairs only stores clean string pairs —
	 * both sides non-empty, each side at most 1200 characters (the same cap
	 * the suggestion gate applies, so nothing enters that lookup would
	 * never accept).
	 */
	public function test_record_translation_pairs_respects_caps() {
		delete_option( Translation_Memory_Service::OPTION_AUTO_RECORD );

		$long_source = str_repeat( '源', 1201 );
		$long_target = str_repeat( '长', 1201 );

		$source_values = array(
			'post_title'   => 'Pair title ' . $this->marker,
			'post_excerpt' => 'Pair excerpt ' . $this->marker,
			'post_content' => $long_source,
		);
		$translated_values = array(
			'post_title'   => '对题 ' . $this->marker,
			'post_excerpt' => '',
			'post_content' => $long_target,
			'post_name'    => array( 'not-a-string' ),
			'post_meta_x'  => 'no source side for this key',
		);

		$recorded = Translation_Memory_Service::record_translation_pairs(
			$source_values,
			'zh_CN',
			'en_US',
			$translated_values,
			'wordpress-blog'
		);

		$this->assertSame( 1, $recorded, 'exactly the title pair passes the caps' );
		$this->assertSame( '对题 ' . $this->marker, Translation_Memory_Service::lookup( 'Pair title ' . $this->marker, 'zh_CN', 'en_US' ) );
		$this->assertSame( '', Translation_Memory_Service::lookup( $long_source, 'zh_CN', 'en_US' ), '>1200-char source must be skipped' );
	}

	/**
	 * opus5 M-04: the toggle off means record_translation_pairs stores nothing.
	 */
	public function test_record_translation_pairs_disabled_records_nothing() {
		update_option( Translation_Memory_Service::OPTION_AUTO_RECORD, 0 );
		try {
			$recorded = Translation_Memory_Service::record_translation_pairs(
				array( 'post_title' => 'Blocked title ' . $this->marker ),
				'zh_CN',
				'en_US',
				array( 'post_title' => '屏蔽 ' . $this->marker ),
				'wordpress-blog'
			);
			$lookup = Translation_Memory_Service::lookup( 'Blocked title ' . $this->marker, 'zh_CN', 'en_US' );
		} finally {
			delete_option( Translation_Memory_Service::OPTION_AUTO_RECORD );
		}

		$this->assertSame( 0, $recorded );
		$this->assertSame( '', $lookup );
	}
}
