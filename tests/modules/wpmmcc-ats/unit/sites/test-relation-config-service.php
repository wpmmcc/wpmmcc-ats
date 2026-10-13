<?php
/**
 * Test Relation_Config_Service
 *
 * @package WPTSALL
 * @since 0.8.0
 */

use WPTSALL\Sites\Services\Relation_Config_Service;

class Test_Relation_Config_Service extends WP_UnitTestCase {

	/**
	 * Test config IDs
	 *
	 * @var array
	 */
	private $test_config_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		$this->create_test_table();
	}

	/**
	 * Create test table if not exists
	 */
	private function create_test_table() {
		global $wpdb;
		$table           = $wpdb->prefix . 'wptsall_relation_post_type_configs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			relation_id bigint(20) unsigned NOT NULL,
			post_type varchar(100) NOT NULL,
			enabled tinyint(1) DEFAULT NULL,
			direction varchar(30) DEFAULT NULL,
			sync_mode varchar(20) DEFAULT NULL,
			field_overrides longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY relation_post_type (relation_id, post_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate unique relation ID
	 *
	 * @return int
	 */
	private function get_unique_relation_id() {
		return 1000 + mt_rand( 1, 999999 );
	}

	// ==================== get() Tests ====================

	/**
	 * Test get returns null for non-existent config
	 */
	public function test_get_returns_null_for_missing() {
		$result = Relation_Config_Service::get( 99999, 'post' );

		$this->assertNull( $result );
	}

	/**
	 * Test get returns config data
	 */
	public function test_get_returns_config() {
		$relation_id = $this->get_unique_relation_id();

		$config_id = Relation_Config_Service::save(
			$relation_id,
			'post',
			array(
				'enabled'   => true,
				'direction' => 'bidirectional',
				'sync_mode' => 'new_only',
			)
		);
		$this->test_config_ids[] = $config_id;

		$result = Relation_Config_Service::get( $relation_id, 'post' );

		$this->assertIsArray( $result );
		$this->assertEquals( $relation_id, (int) $result['relation_id'] );
		$this->assertEquals( 'post', $result['post_type'] );
		$this->assertEquals( 1, (int) $result['enabled'] );
		$this->assertEquals( 'bidirectional', $result['direction'] );
		$this->assertEquals( 'new_only', $result['sync_mode'] );
	}

	/**
	 * Test get decodes field_overrides JSON
	 */
	public function test_get_decodes_field_overrides() {
		$relation_id = $this->get_unique_relation_id();
		$overrides   = array(
			'post_title' => array( 'enabled' => false ),
			'_price'     => array( 'type' => 'no_sync' ),
		);

		$config_id = Relation_Config_Service::save(
			$relation_id,
			'product',
			array( 'field_overrides' => $overrides )
		);
		$this->test_config_ids[] = $config_id;

		$result = Relation_Config_Service::get( $relation_id, 'product' );

		$this->assertIsArray( $result['field_overrides'] );
		$this->assertArrayHasKey( 'post_title', $result['field_overrides'] );
		$this->assertFalse( $result['field_overrides']['post_title']['enabled'] );
	}

	// ==================== get_all_by_relation() Tests ====================

	/**
	 * Test get_all_by_relation returns empty array when none exist
	 */
	public function test_get_all_by_relation_empty() {
		$result = Relation_Config_Service::get_all_by_relation( 99999 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_all_by_relation returns all configs
	 */
	public function test_get_all_by_relation() {
		$relation_id = $this->get_unique_relation_id();

		$id1 = Relation_Config_Service::save( $relation_id, 'post', array( 'enabled' => true ) );
		$id2 = Relation_Config_Service::save( $relation_id, 'page', array( 'enabled' => false ) );
		$id3 = Relation_Config_Service::save( $relation_id, 'product', array( 'sync_mode' => 'new_only' ) );

		$this->test_config_ids = array_merge( $this->test_config_ids, array( $id1, $id2, $id3 ) );

		$results = Relation_Config_Service::get_all_by_relation( $relation_id );

		$this->assertCount( 3, $results );
	}

	// ==================== save() Tests ====================

	/**
	 * Test save creates new config
	 */
	public function test_save_creates_new() {
		$relation_id = $this->get_unique_relation_id();

		$config_id = Relation_Config_Service::save(
			$relation_id,
			'post',
			array(
				'enabled'   => true,
				'direction' => 'source_to_target',
			)
		);

		$this->test_config_ids[] = $config_id;

		$this->assertIsInt( $config_id );
		$this->assertGreaterThan( 0, $config_id );
	}

	/**
	 * Test save updates existing config
	 */
	public function test_save_updates_existing() {
		$relation_id = $this->get_unique_relation_id();

		// Create initial config.
		$config_id = Relation_Config_Service::save(
			$relation_id,
			'post',
			array( 'direction' => 'source_to_target' )
		);
		$this->test_config_ids[] = $config_id;

		// Update config.
		$updated_id = Relation_Config_Service::save(
			$relation_id,
			'post',
			array( 'direction' => 'bidirectional' )
		);

		// Should return same ID.
		$this->assertEquals( $config_id, $updated_id );

		// Verify update.
		$result = Relation_Config_Service::get( $relation_id, 'post' );
		$this->assertEquals( 'bidirectional', $result['direction'] );
	}

	/**
	 * Test save with all fields
	 */
	public function test_save_all_fields() {
		$relation_id = $this->get_unique_relation_id();

		$config_id = Relation_Config_Service::save(
			$relation_id,
			'post',
			array(
				'enabled'         => false,
				'direction'       => 'bidirectional',
				'sync_mode'       => 'new_only',
				'field_overrides' => array( 'post_title' => array( 'enabled' => false ) ),
			)
		);
		$this->test_config_ids[] = $config_id;

		$result = Relation_Config_Service::get( $relation_id, 'post' );

		$this->assertEquals( 0, (int) $result['enabled'] );
		$this->assertEquals( 'bidirectional', $result['direction'] );
		$this->assertEquals( 'new_only', $result['sync_mode'] );
		$this->assertArrayHasKey( 'post_title', $result['field_overrides'] );
	}

	// ==================== delete() Tests ====================

	/**
	 * Test delete specific config
	 */
	public function test_delete_specific() {
		$relation_id = $this->get_unique_relation_id();

		$id1 = Relation_Config_Service::save( $relation_id, 'post', array( 'enabled' => true ) );
		$id2 = Relation_Config_Service::save( $relation_id, 'page', array( 'enabled' => true ) );
		$this->test_config_ids = array_merge( $this->test_config_ids, array( $id1, $id2 ) );

		$result = Relation_Config_Service::delete( $relation_id, 'post' );

		$this->assertTrue( $result );
		$this->assertNull( Relation_Config_Service::get( $relation_id, 'post' ) );
		$this->assertNotNull( Relation_Config_Service::get( $relation_id, 'page' ) );

		// Remove from cleanup since deleted.
		$this->test_config_ids = array_diff( $this->test_config_ids, array( $id1 ) );
	}

	/**
	 * Test delete all configs for relation
	 */
	public function test_delete_all() {
		$relation_id = $this->get_unique_relation_id();

		$id1 = Relation_Config_Service::save( $relation_id, 'post', array( 'enabled' => true ) );
		$id2 = Relation_Config_Service::save( $relation_id, 'page', array( 'enabled' => true ) );
		$this->test_config_ids = array_merge( $this->test_config_ids, array( $id1, $id2 ) );

		$result = Relation_Config_Service::delete( $relation_id );

		$this->assertTrue( $result );
		$this->assertEmpty( Relation_Config_Service::get_all_by_relation( $relation_id ) );

		// Remove from cleanup since deleted.
		$this->test_config_ids = array_diff( $this->test_config_ids, array( $id1, $id2 ) );
	}

	// ==================== exists() Tests ====================

	/**
	 * Test exists returns true when exists
	 */
	public function test_exists_true() {
		$relation_id = $this->get_unique_relation_id();

		$config_id = Relation_Config_Service::save( $relation_id, 'post', array( 'enabled' => true ) );
		$this->test_config_ids[] = $config_id;

		$this->assertTrue( Relation_Config_Service::exists( $relation_id, 'post' ) );
	}

	/**
	 * Test exists returns false when not exists
	 */
	public function test_exists_false() {
		$this->assertFalse( Relation_Config_Service::exists( 99999, 'post' ) );
	}

	// ==================== save_batch() Tests ====================

	/**
	 * Test save_batch saves multiple configs
	 */
	public function test_save_batch() {
		$relation_id = $this->get_unique_relation_id();

		$configs = array(
			'post'    => array( 'enabled' => true, 'direction' => 'source_to_target' ),
			'page'    => array( 'enabled' => false ),
			'product' => array( 'sync_mode' => 'new_only' ),
		);

		$result = Relation_Config_Service::save_batch( $relation_id, $configs );

		$this->assertTrue( $result );

		$all = Relation_Config_Service::get_all_by_relation( $relation_id );
		$this->assertCount( 3, $all );

		// Track for cleanup.
		foreach ( $all as $config ) {
			$this->test_config_ids[] = $config['id'];
		}
	}

	// ==================== get_stats() Tests ====================

	/**
	 * Test get_stats returns correct structure
	 */
	public function test_get_stats() {
		$relation_id = $this->get_unique_relation_id();

		$id1 = Relation_Config_Service::save( $relation_id, 'post', array( 'enabled' => true ) );
		$id2 = Relation_Config_Service::save( $relation_id, 'page', array( 'enabled' => true ) );
		$id3 = Relation_Config_Service::save( $relation_id, 'product', array( 'enabled' => false ) );
		$this->test_config_ids = array_merge( $this->test_config_ids, array( $id1, $id2, $id3 ) );

		$stats = Relation_Config_Service::get_stats( $relation_id );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'enabled', $stats );
		$this->assertArrayHasKey( 'disabled', $stats );
		$this->assertEquals( 3, $stats['total'] );
		$this->assertEquals( 2, $stats['enabled'] );
		$this->assertEquals( 1, $stats['disabled'] );
	}

	// ==================== copy_to_relation() Tests ====================

	/**
	 * Test copy_to_relation copies configs
	 */
	public function test_copy_to_relation() {
		$source_relation = $this->get_unique_relation_id();
		$target_relation = $this->get_unique_relation_id();

		// Create source configs.
		$id1 = Relation_Config_Service::save( $source_relation, 'post', array( 'enabled' => true, 'direction' => 'bidirectional' ) );
		$id2 = Relation_Config_Service::save( $source_relation, 'page', array( 'enabled' => false ) );
		$this->test_config_ids = array_merge( $this->test_config_ids, array( $id1, $id2 ) );

		// Copy to target.
		$result = Relation_Config_Service::copy_to_relation( $source_relation, $target_relation );

		$this->assertTrue( $result );

		// Verify copied.
		$target_configs = Relation_Config_Service::get_all_by_relation( $target_relation );
		$this->assertCount( 2, $target_configs );

		// Track for cleanup.
		foreach ( $target_configs as $config ) {
			$this->test_config_ids[] = $config['id'];
		}

		// Verify data copied correctly.
		$post_config = Relation_Config_Service::get( $target_relation, 'post' );
		$this->assertEquals( 'bidirectional', $post_config['direction'] );
	}

	/**
	 * Test copy_to_relation with no configs returns true
	 */
	public function test_copy_to_relation_empty() {
		$source_relation = $this->get_unique_relation_id();
		$target_relation = $this->get_unique_relation_id();

		$result = Relation_Config_Service::copy_to_relation( $source_relation, $target_relation );

		$this->assertTrue( $result );
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_relation_post_type_configs';

		if ( ! empty( $this->test_config_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->test_config_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" );
		}

		parent::tearDown();
	}
}
