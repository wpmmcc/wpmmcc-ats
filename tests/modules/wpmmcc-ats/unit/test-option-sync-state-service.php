<?php
/**
 * Option Sync State Service Tests
 *
 * L1/L2 for WPTSALL\Models\Services\Option_Sync_State_Service (option claim
 * leases backing discovery):
 * - input validation matrix (claim/renew/mark_synced owner digest contract)
 * - first claim persists a scoped row (relation/source/target/option key)
 * - claim is EXCLUSIVE while the lease is fresh (CAS: second owner blocked)
 * - lease expiry hands the claim to another owner; has_active_claim follows
 * - renew_claim is owner-gated and lease-gated
 * - mark_synced is owner-gated, clears the claim and stamps synced_at
 * - mark_needs_resync clears the claim and re-arms claimability
 * - release_claim is owner-gated; get_state is scoped per relation
 *
 * L3 true-concurrency (unique-key arbitration race) stays in Phase 4 per plan.
 *
 * catalog: WP-CLASS-Option_Sync_State_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Option_Sync_State_Service;

class Test_Option_Sync_State_Service extends SimpleTestCase {

	/**
	 * Rows created by this run: [ relation_id, source_site_id, target_site_id, option_name ].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private $scopes = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_option_sync_state_table' ) ) {
			wptsall_create_option_sync_state_table();
		}
	}

	public function tearDown(): void {
		global $wpdb;
		$table = wptsall_table( 'option_sync_state' );
		foreach ( $this->scopes as $s ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array(
				'relation_id'    => (int) $s[0],
				'source_site_id' => (int) $s[1],
				'target_site_id' => (string) $s[2],
				'option_name'    => (string) $s[3],
			), array( '%d', '%d', '%s', '%s' ) );
		}
		$this->scopes = array();
		parent::tearDown();
	}

	/**
	 * Unique scope tuple for this run.
	 *
	 * @return array{0:int,1:int,2:string,3:string}
	 */
	private function scope(): array {
		$tag       = strtolower( uniqid() );
		$scope     = array(
			900000 + ( crc32( $tag ) % 90000 ),
			1,
			'v_tgt_' . $tag,
			'oss_opt_' . $tag,
		);
		$this->scopes[] = $scope;
		return $scope;
	}

	private static function owner( string $seed ): string {
		return hash( 'sha256', $seed );
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Option_Sync_State_Service' ) );
	}

	public function test_claim_input_validation_matrix() {
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();

		$cases = array(
			'empty owner hash'     => array( '', $relation_id, $source_site_id, $target_site_id, $option_name ),
			'non-hex owner hash'   => array( str_repeat( 'z', 64 ), $relation_id, $source_site_id, $target_site_id, $option_name ),
			'short owner hash'     => array( 'abc123', $relation_id, $source_site_id, $target_site_id, $option_name ),
			'empty target'         => array( self::owner( 'a' ), $relation_id, $source_site_id, '', $option_name ),
			'empty option name'    => array( self::owner( 'a' ), $relation_id, $source_site_id, $target_site_id, '' ),
			'zero relation id'     => array( self::owner( 'a' ), 0, $source_site_id, $target_site_id, $option_name ),
			'zero source site id'  => array( self::owner( 'a' ), $relation_id, 0, $target_site_id, $option_name ),
		);
		foreach ( $cases as $label => $args ) {
			$this->assertFalse(
				Option_Sync_State_Service::claim( $args[1], $args[2], $args[3], $args[4], 300, $args[0] ),
				"claim must reject: {$label}"
			);
		}
	}

	public function test_first_claim_persists_scoped_row() {
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner = self::owner( 'first' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner ) );

		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		$this->assertNotNull( $state );
		$this->assertSame( $owner, $state['claim_owner_hash'] );
		$this->assertNotEmpty( $state['claimed_at'] );
		$this->assertEquals( 0, (int) $state['needs_resync'] );
		$this->assertTrue( Option_Sync_State_Service::has_active_claim( $relation_id, $source_site_id, $target_site_id, $option_name, $owner, 300 ) );
	}

	public function test_claim_is_exclusive_while_lease_fresh() {
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner_a = self::owner( 'lease-a' );
		$owner_b = self::owner( 'lease-b' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );
		// CAS: the fresh lease blocks a second owner.
		$this->assertFalse( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_b ) );

		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		$this->assertSame( $owner_a, $state['claim_owner_hash'], 'blocked claim must not steal the lease' );
		$this->assertFalse( Option_Sync_State_Service::has_active_claim( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_b, 300 ) );
	}

	public function test_expired_lease_hands_claim_to_next_owner() {
		global $wpdb;
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner_a = self::owner( 'expired-a' );
		$owner_b = self::owner( 'expired-b' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );
		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		// Force the lease into the past (simulated expiry).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			wptsall_table( 'option_sync_state' ),
			array( 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'id' => (int) $state['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertFalse( Option_Sync_State_Service::has_active_claim( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_a, 300 ) );

		// After expiry the claim is claimable by the next owner.
		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_b ) );
		$this->assertTrue( Option_Sync_State_Service::has_active_claim( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_b, 300 ) );
		$this->assertFalse( Option_Sync_State_Service::has_active_claim( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_a, 300 ) );
	}

	public function test_renew_claim_is_owner_and_lease_gated() {
		global $wpdb;
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner_a = self::owner( 'renew-a' );
		$owner_b = self::owner( 'renew-b' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );

		// Wrong owner cannot renew.
		$this->assertFalse( Option_Sync_State_Service::renew_claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_b ) );

		// KNOWN QUIRK (catalog FINDING, Option_Sync_State_Service): renewing in
		// the SAME second as the claim is a no-op UPDATE (claimed_at/updated_at
		// unchanged -> 0 changed rows) and renew_claim reports false even
		// though the lease is valid and owned. Pinned here as current behavior.
		$this->assertFalse( Option_Sync_State_Service::renew_claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );

		// One second later the renewal lands and refreshes the lease.
		sleep( 1 );
		$this->assertTrue( Option_Sync_State_Service::renew_claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );

		// Expired lease cannot be renewed.
		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			wptsall_table( 'option_sync_state' ),
			array( 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'id' => (int) $state['id'] ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertFalse( Option_Sync_State_Service::renew_claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );
	}

	public function test_mark_synced_is_owner_gated_and_clears_claim() {
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner_a = self::owner( 'synced-a' );
		$owner_b = self::owner( 'synced-b' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );

		// Wrong owner cannot mark synced.
		$this->assertFalse( Option_Sync_State_Service::mark_synced( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_b ) );
		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		$this->assertSame( $owner_a, $state['claim_owner_hash'], 'failed mark_synced must not clear the claim' );

		// Owner marks synced: claim cleared, synced_at stamped.
		$this->assertTrue( Option_Sync_State_Service::mark_synced( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_a ) );
		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		$this->assertEmpty( $state['claim_owner_hash'] );
		$this->assertEmpty( $state['claimed_at'] );
		$this->assertNotEmpty( $state['synced_at'] );
		$this->assertEquals( 0, (int) $state['needs_resync'] );
	}

	public function test_mark_needs_resync_clears_claim_and_re_arms_claimability() {
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner_a = self::owner( 'resync-a' );
		$owner_b = self::owner( 'resync-b' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );
		$this->assertTrue( Option_Sync_State_Service::mark_synced( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_a ) );

		// The synced row is NOT claimable again without a resync mark (CAS
		// condition: synced_at IS NULL OR needs_resync = 1).
		$this->assertFalse( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_b ) );

		// mark_needs_resync re-arms claimability.
		$this->assertTrue( Option_Sync_State_Service::mark_needs_resync( $relation_id, $source_site_id, $target_site_id, $option_name ) );
		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		$this->assertEquals( 1, (int) $state['needs_resync'] );
		$this->assertEmpty( $state['claim_owner_hash'] );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_b ) );
	}

	public function test_release_claim_is_owner_gated() {
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner_a = self::owner( 'release-a' );
		$owner_b = self::owner( 'release-b' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_a ) );

		// Wrong owner cannot release.
		$this->assertFalse( Option_Sync_State_Service::release_claim( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_b ) );
		$state = Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		$this->assertSame( $owner_a, $state['claim_owner_hash'] );

		// Owner releases; the option is immediately claimable again.
		$this->assertTrue( Option_Sync_State_Service::release_claim( $relation_id, $source_site_id, $target_site_id, $option_name, $owner_a ) );
		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner_b ) );
	}

	public function test_get_state_is_scoped_per_relation() {
		list( $relation_id, $source_site_id, $target_site_id, $option_name ) = $this->scope();
		$owner = self::owner( 'scope' );

		$this->assertTrue( Option_Sync_State_Service::claim( $relation_id, $source_site_id, $target_site_id, $option_name, 300, $owner ) );

		// Different relation: no state leaks across scopes.
		$this->assertNull( Option_Sync_State_Service::get_state( $relation_id + 1, $source_site_id, $target_site_id, $option_name ) );
		// Different option name: no state leaks.
		$this->assertNull( Option_Sync_State_Service::get_state( $relation_id, $source_site_id, $target_site_id, $option_name . '_other' ) );
	}
}
