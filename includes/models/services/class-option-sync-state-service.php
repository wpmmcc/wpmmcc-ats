<?php
/**
 * Option Sync State Service
 *
 * Tracks claim/sync/resync state for option-backed content discovery.
 *
 * @package WPTSALL\Models\Services
 * @since 1.2.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Option Sync State Service.
 */
class Option_Sync_State_Service {

	/**
	 * Get state row for a relation-scoped option.
	 *
	 * @param int    $relation_id    Site relation ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $option_name    Option key.
	 * @return array|null
	 */
	public static function get_state( int $relation_id, int $source_site_id, string $target_site_id, string $option_name ): ?array {
		global $wpdb;

		$table = wptsall_table( 'option_sync_state' );
		if ( ! self::table_exists( $table ) ) {
			return null;
		}

		$state = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE relation_id = %d AND source_site_id = %d AND target_site_id = %s AND option_name = %s LIMIT 1',
				$table,
				$relation_id,
				$source_site_id,
				$target_site_id,
				$option_name
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Mark an option as needing re-sync.
	 *
	 * @param int    $relation_id    Site relation ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $option_name    Option key.
	 * @return bool
	 */
	public static function mark_needs_resync( int $relation_id, int $source_site_id, string $target_site_id, string $option_name ): bool {
		return self::upsert_state(
			$relation_id,
			$source_site_id,
			$target_site_id,
			$option_name,
			array(
				'needs_resync'     => 1,
				'claimed_at'       => null,
				'claim_owner_hash' => null,
			)
		);
	}

	/**
	 * Claim an option item for translation.
	 *
	 * @param int    $relation_id             Site relation ID.
	 * @param int    $source_site_id          Source site ID.
	 * @param string $target_site_id          Target site ID.
	 * @param string $option_name             Option key.
	 * @param int    $claim_timeout_seconds   Claim timeout in seconds.
	 * @return bool
	 */
	public static function claim( int $relation_id, int $source_site_id, string $target_site_id, string $option_name, int $claim_timeout_seconds, string $claim_owner_hash = '' ): bool {
		global $wpdb;

		$option_name      = sanitize_key( $option_name );
		$target_site_id   = sanitize_text_field( $target_site_id );
		$claim_owner_hash = strtolower( trim( $claim_owner_hash ) );
		if ( $relation_id <= 0 || $source_site_id <= 0 || '' === $target_site_id || '' === $option_name || ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return false;
		}

		$table        = wptsall_table( 'option_sync_state' );
		$claim_cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $claim_timeout_seconds ) );
		$now          = current_time( 'mysql', true );

		// The conditional UPDATE is the claim CAS. A read-then-write sequence
		// lets two devices acquire the same option under concurrent requests.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET claimed_at = %s, claim_owner_hash = %s, updated_at = %s WHERE relation_id = %d AND source_site_id = %d AND target_site_id = %s AND option_name = %s AND (claimed_at IS NULL OR claimed_at < %s) AND (synced_at IS NULL OR needs_resync = 1)',
				$table,
				$now,
				$claim_owner_hash,
				$now,
				$relation_id,
				$source_site_id,
				$target_site_id,
				$option_name,
				$claim_cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false !== $updated && $updated > 0 ) {
			return true;
		}

		// No row yet. The unique key arbitrates concurrent first claims; a
		// duplicate insert is followed by the CAS above so only one caller wins.
		$inserted = $wpdb->insert(
			$table,
			array(
				'relation_id'     => $relation_id,
				'source_site_id'  => $source_site_id,
				'target_site_id'  => $target_site_id,
				'option_name'     => $option_name,
				'needs_resync'    => 0,
				'claimed_at'      => $now,
				'claim_owner_hash' => $claim_owner_hash,
				'synced_at'       => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false !== $inserted ) {
			return true;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET claimed_at = %s, claim_owner_hash = %s, updated_at = %s WHERE relation_id = %d AND source_site_id = %d AND target_site_id = %s AND option_name = %s AND (claimed_at IS NULL OR claimed_at < %s) AND (synced_at IS NULL OR needs_resync = 1)',
				$table,
				$now,
				$claim_owner_hash,
				$now,
				$relation_id,
				$source_site_id,
				$target_site_id,
				$option_name,
				$claim_cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $updated && $updated > 0;
	}

	/**
	 * Renew an active option claim.
	 *
	 * @param int    $relation_id           Site relation ID.
	 * @param int    $source_site_id        Source site ID.
	 * @param string $target_site_id        Target site ID.
	 * @param string $option_name           Option key.
	 * @param int    $claim_timeout_seconds Claim timeout in seconds.
	 * @return bool
	 */
	public static function renew_claim( int $relation_id, int $source_site_id, string $target_site_id, string $option_name, int $claim_timeout_seconds, string $claim_owner_hash = '' ): bool {
		global $wpdb;
		$claim_owner_hash = strtolower( trim( $claim_owner_hash ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
			return false;
		}
		$claim_cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $claim_timeout_seconds ) );
		$now          = current_time( 'mysql', true );
		$updated      = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET claimed_at = %s, updated_at = %s WHERE relation_id = %d AND source_site_id = %d AND target_site_id = %s AND option_name = %s AND claim_owner_hash = %s AND claimed_at >= %s',
				wptsall_table( 'option_sync_state' ),
				$now,
				$now,
				$relation_id,
				$source_site_id,
				sanitize_text_field( $target_site_id ),
				sanitize_key( $option_name ),
				$claim_owner_hash,
				$claim_cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $updated && $updated > 0;
	}

	/**
	 * Release an option claim.
	 *
	 * @param int    $relation_id    Site relation ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $option_name    Option key.
	 * @return bool
	 */
	public static function release_claim( int $relation_id, int $source_site_id, string $target_site_id, string $option_name, string $claim_owner_hash = '' ): bool {
		if ( '' !== $claim_owner_hash ) {
			$claim_owner_hash = strtolower( trim( $claim_owner_hash ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
				return false;
			}
			return self::conditional_claim_update( $relation_id, $source_site_id, $target_site_id, $option_name, $claim_owner_hash, array( 'claimed_at' => null, 'claim_owner_hash' => null ) );
		}
		return self::upsert_state(
			$relation_id,
			$source_site_id,
			$target_site_id,
			$option_name,
			array(
				'claimed_at'       => null,
				'claim_owner_hash' => null,
			)
		);
	}

	/**
	 * Mark an option as successfully synced.
	 *
	 * @param int    $relation_id    Site relation ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $option_name    Option key.
	 * @return bool
	 */
	public static function mark_synced( int $relation_id, int $source_site_id, string $target_site_id, string $option_name, string $claim_owner_hash = '' ): bool {
		$now = current_time( 'mysql', true );
		$changes = array(
			'needs_resync'     => 0,
			'claimed_at'       => null,
			'claim_owner_hash' => null,
			'synced_at'        => $now,
		);
		if ( '' !== $claim_owner_hash ) {
			$claim_owner_hash = strtolower( trim( $claim_owner_hash ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash ) ) {
				return false;
			}
			return self::conditional_claim_update( $relation_id, $source_site_id, $target_site_id, $option_name, $claim_owner_hash, $changes );
		}
		return self::upsert_state( $relation_id, $source_site_id, $target_site_id, $option_name, $changes );
	}

	/**
	 * Whether a non-expired option claim belongs to the supplied owner digest.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $option_name Option name.
	 * @param string $claim_owner_hash Owner digest.
	 * @param int    $claim_timeout_seconds Lease duration.
	 * @return bool
	 */
	public static function has_active_claim( int $relation_id, int $source_site_id, string $target_site_id, string $option_name, string $claim_owner_hash, int $claim_timeout_seconds ): bool {
		$state = self::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		if ( ! is_array( $state ) ) {
			return false;
		}
		$claimed_at = (string) ( $state['claimed_at'] ?? '' );
		$owner      = strtolower( trim( (string) ( $state['claim_owner_hash'] ?? '' ) ) );
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - max( 1, $claim_timeout_seconds ) );
		return '' !== $claimed_at
			&& $claimed_at >= $cutoff
			&& preg_match( '/^[a-f0-9]{64}$/', $claim_owner_hash )
			&& '' !== $owner
			&& hash_equals( $owner, strtolower( trim( $claim_owner_hash ) ) );
	}

	/**
	 * Update a state row only while it still belongs to a claim owner.
	 *
	 * @param int    $relation_id    Site relation ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $option_name    Option key.
	 * @param string $claim_owner_hash Owner digest.
	 * @param array  $changes        Column changes.
	 * @return bool
	 */
	private static function conditional_claim_update( int $relation_id, int $source_site_id, string $target_site_id, string $option_name, string $claim_owner_hash, array $changes ): bool {
		global $wpdb;
		$state = self::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		if ( ! is_array( $state ) || empty( $state['id'] ) ) {
			return false;
		}
		$changes['updated_at'] = current_time( 'mysql', true );
		$result = $wpdb->update(
			wptsall_table( 'option_sync_state' ),
			$changes,
			array(
				'id'               => (int) $state['id'],
				'claim_owner_hash' => $claim_owner_hash,
			),
			array_fill( 0, count( $changes ), '%s' ),
			array( '%d', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $result && $result > 0;
	}

	/**
	 * Create or update a relation-scoped option state row.
	 *
	 * @param int    $relation_id    Site relation ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $option_name    Option key.
	 * @param array  $changes        Column changes.
	 * @return bool
	 */
	private static function upsert_state( int $relation_id, int $source_site_id, string $target_site_id, string $option_name, array $changes ): bool {
		global $wpdb;

		$table = wptsall_table( 'option_sync_state' );
		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$relation_id    = absint( $relation_id );
		$source_site_id = absint( $source_site_id );
		$target_site_id = sanitize_text_field( $target_site_id );
		$option_name    = sanitize_key( $option_name );
		$existing       = self::get_state( $relation_id, $source_site_id, $target_site_id, $option_name );
		$now            = current_time( 'mysql', true );
		$data     = array_merge(
			array(
				'relation_id'    => $relation_id,
				'source_site_id' => $source_site_id,
				'target_site_id' => $target_site_id,
				'option_name'    => $option_name,
				'updated_at'     => $now,
			),
			$changes
		);

		if ( null !== $existing ) {
			$result = $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing['id'] )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			return false !== $result;
		}

		$data = array_merge(
			array(
				'needs_resync'     => 0,
				'claimed_at'       => null,
				'claim_owner_hash' => null,
				'synced_at'        => null,
				'created_at'       => $now,
			),
			$data
		);

		$result = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return false !== $result;
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		static $cache = array();

		if ( array_key_exists( $table, $cache ) ) {
			return $cache[ $table ];
		}

		global $wpdb;
		$exists        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cache[ $table ] = ( $exists === $table );

		return $cache[ $table ];
	}
}
