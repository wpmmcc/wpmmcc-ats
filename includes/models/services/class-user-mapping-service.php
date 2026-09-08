<?php
/**
 * User Mapping Service
 *
 * Handles user ID mappings between source and target sites.
 * Used for mapping post_author and other user ID fields during sync.
 *
 * Mapping strategies:
 * - manual: Explicitly configured mapping
 * - email_match: Auto-matched by user email address
 * - auto_fallback: Fallback to default user (admin or current user)
 *
 * @package WPTSALL\Models\Services
 * @since 0.8.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User Mapping Service Class
 */
class User_Mapping_Service {

	/**
	 * Get user mapping from source to target
	 *
	 * @param int    $source_user_id Source user ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID (can be virtual).
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_mapping( $source_user_id, $source_site_id, $target_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE source_user_id = %d
				AND source_site_id = %d
				AND target_site_id = %s",
				$table,
				$source_user_id,
				$source_site_id,
				$target_site_id
			),
			ARRAY_A
		);

		return $mapping ? $mapping : null;
	}

	/**
	 * Get mapped user ID by relation ID and source user ID
	 *
	 * Simplified alias for cross-module API compliance.
	 * Uses relation_id to resolve source_site_id and target_site_id internally.
	 *
	 * Note: For more comprehensive user mapping with fallback logic,
	 * use get_mapped_user_id() method instead.
	 *
	 * @since 0.8.1
	 *
	 * @param int $relation_id Site relation ID.
	 * @param int $source_id   Source user ID.
	 * @return int|null Target user ID or null if not found.
	 */
	public static function get_mapped_id( int $relation_id, int $source_id ): ?int {
		// 1. Get relation info.
		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return null;
		}

		// 2. Determine source and target site IDs.
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );

		if ( empty( $target_site_id ) ) {
			return null;
		}

		// 3. Validate source user exists (users are global in WordPress, no blog switch needed).
		$source_user = get_userdata( $source_id );
		if ( ! $source_user ) {
			return null;
		}

		// 4. Get mapping using existing method (no auto-create).
		$mapping = self::get_mapping( $source_id, $source_site_id, $target_site_id );

		return $mapping ? (int) $mapping['target_user_id'] : null;
	}

	/**
	 * Get reverse mapping (target to source)
	 *
	 * @param int    $target_user_id Target user ID.
	 * @param string $target_site_id Target site ID.
	 * @param int    $source_site_id Source site ID.
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_reverse_mapping( $target_user_id, $target_site_id, $source_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE target_user_id = %d
				AND target_site_id = %s
				AND source_site_id = %d",
				$table,
				$target_user_id,
				$target_site_id,
				$source_site_id
			),
			ARRAY_A
		);

		return $mapping ? $mapping : null;
	}

	/**
	 * Create or update user mapping
	 *
	 * @param array $mapping_data {
	 *     Mapping data.
	 *
	 *     @type int    $source_user_id Source user ID.
	 *     @type int    $source_site_id Source site ID.
	 *     @type int    $target_user_id Target user ID.
	 *     @type string $target_site_id Target site ID.
	 *     @type string $mapping_type   Mapping type (manual/email_match/auto_fallback).
	 * }
	 * @return int|false Mapping ID or false on failure.
	 */
	public static function create_mapping( $mapping_data ) {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// Check if mapping already exists.
		$existing = self::get_mapping(
			$mapping_data['source_user_id'],
			$mapping_data['source_site_id'],
			$mapping_data['target_site_id']
		);

		$now = current_time( 'mysql' );

		$data = array(
			'source_user_id' => $mapping_data['source_user_id'],
			'source_site_id' => $mapping_data['source_site_id'],
			'target_user_id' => $mapping_data['target_user_id'],
			'target_site_id' => $mapping_data['target_site_id'],
			'mapping_type'   => $mapping_data['mapping_type'] ?? 'manual',
			'updated_at'     => $now,
		);

		if ( $existing ) {
			// Update existing mapping.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				$data,
				array( 'id' => $existing['id'] ),
				array( '%d', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return $result !== false ? (int) $existing['id'] : false;
		} else {
			// Create new mapping.
			$data['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$table,
				$data,
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
			);

			return $result ? (int) $wpdb->insert_id : false;
		}
	}

	/**
	 * Get mapped user ID with fallback logic
	 *
	 * Tries in order:
	 * 1. Check existing manual mapping
	 * 2. Try to match by email address
	 * 3. Fall back to default user (current user or admin)
	 *
	 * @param int    $source_user_id Source user ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param array  $options        Options (create_mapping, fallback_user_id).
	 * @return int Target user ID.
	 */
	public static function get_mapped_user_id( $source_user_id, $source_site_id, $target_site_id, $options = array() ) {
		$create_mapping  = $options['create_mapping'] ?? true;
		$fallback_user_id = $options['fallback_user_id'] ?? null;

		// 1. Check existing mapping.
		$mapping = self::get_mapping( $source_user_id, $source_site_id, $target_site_id );
		if ( $mapping ) {
			return (int) $mapping['target_user_id'];
		}

		// 2. Try to match by email address.
		$matched_user_id = self::match_user_by_email( $source_user_id, $source_site_id, $target_site_id );
		if ( $matched_user_id ) {
			if ( $create_mapping ) {
				self::create_mapping(
					array(
						'source_user_id' => $source_user_id,
						'source_site_id' => $source_site_id,
						'target_user_id' => $matched_user_id,
						'target_site_id' => $target_site_id,
						'mapping_type'   => 'email_match',
					)
				);
			}
			return $matched_user_id;
		}

		// 3. Fall back to default user.
		$default_user_id = self::get_fallback_user_id( $target_site_id, $fallback_user_id );

		if ( $create_mapping ) {
			self::create_mapping(
				array(
					'source_user_id' => $source_user_id,
					'source_site_id' => $source_site_id,
					'target_user_id' => $default_user_id,
					'target_site_id' => $target_site_id,
					'mapping_type'   => 'auto_fallback',
				)
			);
		}

		return $default_user_id;
	}

	/**
	 * Match user by email address
	 *
	 * @param int    $source_user_id Source user ID.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @return int|false Target user ID if matched, false otherwise.
	 */
	private static function match_user_by_email( $source_user_id, $source_site_id, $target_site_id ) {
		// Get source user email.
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$source_user = get_userdata( $source_user_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $source_user || empty( $source_user->user_email ) ) {
			return false;
		}

		$email = $source_user->user_email;

		// Only support numeric target sites (multisite).
		if ( ! is_numeric( $target_site_id ) ) {
			// For virtual sites, cannot match by email.
			return false;
		}

		// Search for user with same email in target site.
		$need_switch = is_multisite() && (int) $target_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( (int) $target_site_id );
		}

		$target_user = get_user_by( 'email', $email );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( $target_user ) {
			return (int) $target_user->ID;
		}

		return false;
	}

	/**
	 * Get fallback user ID for target site
	 *
	 * @param string   $target_site_id   Target site ID.
	 * @param int|null $specified_user_id Specified fallback user ID.
	 * @return int User ID.
	 */
	private static function get_fallback_user_id( $target_site_id, $specified_user_id = null ) {
		// Use specified user ID if provided.
		if ( $specified_user_id && $specified_user_id > 0 ) {
			return $specified_user_id;
		}

		// Use current user if logged in.
		$current_user_id = get_current_user_id();
		if ( $current_user_id > 0 ) {
			return $current_user_id;
		}

		// Fall back to first admin user.
		if ( is_numeric( $target_site_id ) ) {
			$need_switch = is_multisite() && (int) $target_site_id !== get_current_blog_id();
			if ( $need_switch ) {
				switch_to_blog( (int) $target_site_id );
			}

			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);

			if ( $need_switch ) {
				restore_current_blog();
			}

			if ( ! empty( $admins ) ) {
				return (int) $admins[0];
			}
		}

		// Last resort: user ID 1.
		return 1;
	}

	/**
	 * Save batch of user mappings
	 *
	 * @param int   $source_site_id Source site ID.
	 * @param array $target_site_id Target site ID.
	 * @param array $mappings       Array of source_user_id => target_user_id.
	 * @return bool True on success.
	 */
	public static function save_batch( $source_site_id, $target_site_id, $mappings ) {
		foreach ( $mappings as $source_user_id => $target_user_id ) {
			$result = self::create_mapping(
				array(
					'source_user_id' => $source_user_id,
					'source_site_id' => $source_site_id,
					'target_user_id' => $target_user_id,
					'target_site_id' => $target_site_id,
					'mapping_type'   => 'manual',
				)
			);

			if ( false === $result ) {
				wptsall_log_error(
					'models',
					'Failed to save user mapping',
					array(
						'source_user_id' => $source_user_id,
						'source_site_id' => $source_site_id,
						'target_user_id' => $target_user_id,
						'target_site_id' => $target_site_id,
					)
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Get all mappings by source and target site
	 *
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @return array Array of mappings.
	 */
	public static function get_mappings_by_sites( $source_site_id, $target_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE source_site_id = %d
				AND target_site_id = %s
				ORDER BY source_user_id ASC",
				$table,
				$source_site_id,
				$target_site_id
			),
			ARRAY_A
		);

		return $mappings ? $mappings : array();
	}

	/**
	 * Delete mapping
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_mapping( $mapping_id ) {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $mapping_id ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete all mappings for source/target site pair
	 *
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @return int Number of deleted mappings.
	 */
	public static function delete_all_mappings( $source_site_id, $target_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array(
				'source_site_id' => $source_site_id,
				'target_site_id' => $target_site_id,
			),
			array( '%d', '%s' )
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Get mapping statistics
	 *
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @return array Statistics.
	 */
	public static function get_stats( $source_site_id, $target_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'user_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN mapping_type = 'manual' THEN 1 ELSE 0 END) as manual,
					SUM(CASE WHEN mapping_type = 'email_match' THEN 1 ELSE 0 END) as email_match,
					SUM(CASE WHEN mapping_type = 'auto_fallback' THEN 1 ELSE 0 END) as auto_fallback
				FROM %i
				WHERE source_site_id = %d
				AND target_site_id = %s",
				$table,
				$source_site_id,
				$target_site_id
			),
			ARRAY_A
		);

		return array(
			'total'         => (int) ( $stats['total'] ?? 0 ),
			'manual'        => (int) ( $stats['manual'] ?? 0 ),
			'email_match'   => (int) ( $stats['email_match'] ?? 0 ),
			'auto_fallback' => (int) ( $stats['auto_fallback'] ?? 0 ),
		);
	}

	/**
	 * Check if table exists
	 *
	 * @return bool True if table exists.
	 */
	public static function table_exists() {
		return wptsall_user_mappings_table_exists();
	}
}
