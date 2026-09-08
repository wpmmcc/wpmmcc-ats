<?php
/**
 * Origin Visit Service
 *
 * Implements global origin + visited de-duplication to prevent backflow/loops across relations/site groups.
 *
 * Language is intentionally NOT part of the visited uniqueness:
 * - If target_lang changes, we do NOT re-translate old content automatically.
 *
 * @package WPTSALL\Tasks\Services
 * @since 1.0.2
 */

namespace WPTSALL\Tasks\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Origin_Visit_Service {

	// Post origin meta keys.
	const META_ORIGIN_SITE_TYPE  = '_wptsall_origin_site_type';
	const META_ORIGIN_SITE_ID    = '_wptsall_origin_site_id';
	const META_ORIGIN_OBJECT_TYPE = '_wptsall_origin_object_type';
	const META_ORIGIN_SUBTYPE    = '_wptsall_origin_subtype';
	const META_ORIGIN_OBJECT_ID  = '_wptsall_origin_object_id';

	/**
	 * Build origin for a post.
	 *
	 * If origin meta exists on the post, keep it (origin never changes).
	 * Otherwise, treat the current site + post as origin.
	 *
	 * @param int    $post_id
	 * @param string $object_type Always 'post_type' for posts.
	 * @param string $subtype Post type name.
	 * @param string $default_site_type 'wp' or 'virtual' (usually 'wp').
	 * @param string $default_site_id Blog ID or virtual_site_id (stringified).
	 * @return array{origin_site_type:string,origin_site_id:string,origin_object_type:string,origin_subtype:string,origin_object_id:int}
	 */
	public static function get_post_origin( $post_id, $object_type, $subtype, $default_site_type, $default_site_id ) {
		$post_id = (int) $post_id;

		$origin_site_type  = get_post_meta( $post_id, self::META_ORIGIN_SITE_TYPE, true );
		$origin_site_id    = get_post_meta( $post_id, self::META_ORIGIN_SITE_ID, true );
		$origin_object_type = get_post_meta( $post_id, self::META_ORIGIN_OBJECT_TYPE, true );
		$origin_subtype    = get_post_meta( $post_id, self::META_ORIGIN_SUBTYPE, true );
		$origin_object_id  = get_post_meta( $post_id, self::META_ORIGIN_OBJECT_ID, true );

		if ( $origin_site_type && $origin_site_id && $origin_object_type && $origin_subtype && $origin_object_id ) {
			return array(
				'origin_site_type'   => (string) $origin_site_type,
				'origin_site_id'     => (string) $origin_site_id,
				'origin_object_type' => (string) $origin_object_type,
				'origin_subtype'     => (string) $origin_subtype,
				'origin_object_id'   => (int) $origin_object_id,
			);
		}

		return array(
			'origin_site_type'   => (string) $default_site_type,
			'origin_site_id'     => (string) $default_site_id,
			'origin_object_type' => (string) $object_type,
			'origin_subtype'     => (string) $subtype,
			'origin_object_id'   => (int) $post_id,
		);
	}

	/**
	 * Ensure origin meta exists on a post (idempotent).
	 *
	 * @param int   $post_id
	 * @param array $origin Origin array from get_post_origin().
	 * @return void
	 */
	public static function ensure_post_origin_meta( $post_id, $origin ) {
		$post_id = (int) $post_id;

		if ( get_post_meta( $post_id, self::META_ORIGIN_SITE_TYPE, true ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_ORIGIN_SITE_TYPE, $origin['origin_site_type'] ?? '' );
		update_post_meta( $post_id, self::META_ORIGIN_SITE_ID, $origin['origin_site_id'] ?? '' );
		update_post_meta( $post_id, self::META_ORIGIN_OBJECT_TYPE, $origin['origin_object_type'] ?? '' );
		update_post_meta( $post_id, self::META_ORIGIN_SUBTYPE, $origin['origin_subtype'] ?? '' );
		update_post_meta( $post_id, self::META_ORIGIN_OBJECT_ID, (string) ( $origin['origin_object_id'] ?? 0 ) );
	}

	/**
	 * Build origin for a term.
	 *
	 * If origin meta exists on the term, keep it (origin never changes).
	 * Otherwise, treat the current site + term as origin.
	 *
	 * @param int    $term_id
	 * @param string $object_type Always 'taxonomy' for terms.
	 * @param string $subtype Taxonomy name.
	 * @param string $default_site_type 'wp' or 'virtual' (usually 'wp').
	 * @param string $default_site_id Blog ID or virtual_site_id (stringified).
	 * @return array{origin_site_type:string,origin_site_id:string,origin_object_type:string,origin_subtype:string,origin_object_id:int}
	 */
	public static function get_term_origin( $term_id, $object_type, $subtype, $default_site_type, $default_site_id ) {
		$term_id = (int) $term_id;

		$origin_site_type  = get_term_meta( $term_id, self::META_ORIGIN_SITE_TYPE, true );
		$origin_site_id    = get_term_meta( $term_id, self::META_ORIGIN_SITE_ID, true );
		$origin_object_type = get_term_meta( $term_id, self::META_ORIGIN_OBJECT_TYPE, true );
		$origin_subtype    = get_term_meta( $term_id, self::META_ORIGIN_SUBTYPE, true );
		$origin_object_id  = get_term_meta( $term_id, self::META_ORIGIN_OBJECT_ID, true );

		if ( $origin_site_type && $origin_site_id && $origin_object_type && $origin_subtype && $origin_object_id ) {
			return array(
				'origin_site_type'   => (string) $origin_site_type,
				'origin_site_id'     => (string) $origin_site_id,
				'origin_object_type' => (string) $origin_object_type,
				'origin_subtype'     => (string) $origin_subtype,
				'origin_object_id'   => (int) $origin_object_id,
			);
		}

		return array(
			'origin_site_type'   => (string) $default_site_type,
			'origin_site_id'     => (string) $default_site_id,
			'origin_object_type' => (string) $object_type,
			'origin_subtype'     => (string) $subtype,
			'origin_object_id'   => (int) $term_id,
		);
	}

	/**
	 * Ensure origin meta exists on a term (idempotent).
	 *
	 * @param int   $term_id
	 * @param array $origin Origin array from get_term_origin().
	 * @return void
	 */
	public static function ensure_term_origin_meta( $term_id, $origin ) {
		$term_id = (int) $term_id;

		if ( get_term_meta( $term_id, self::META_ORIGIN_SITE_TYPE, true ) ) {
			return;
		}

		update_term_meta( $term_id, self::META_ORIGIN_SITE_TYPE, $origin['origin_site_type'] ?? '' );
		update_term_meta( $term_id, self::META_ORIGIN_SITE_ID, $origin['origin_site_id'] ?? '' );
		update_term_meta( $term_id, self::META_ORIGIN_OBJECT_TYPE, $origin['origin_object_type'] ?? '' );
		update_term_meta( $term_id, self::META_ORIGIN_SUBTYPE, $origin['origin_subtype'] ?? '' );
		update_term_meta( $term_id, self::META_ORIGIN_OBJECT_ID, (string) ( $origin['origin_object_id'] ?? 0 ) );
	}

	/**
	 * Determine whether a delivery should be skipped.
	 *
	 * Skip if:
	 * - destination site equals origin site (no-backflow)
	 * - destination was already visited for this origin (deliver once)
	 *
	 * @param array  $origin
	 * @param string $dest_site_type
	 * @param string $dest_site_id
	 * @return bool
	 */
	public static function should_skip_delivery( $origin, $dest_site_type, $dest_site_id ) {
		if ( empty( $origin['origin_site_type'] ) || empty( $origin['origin_site_id'] ) ) {
			return false;
		}

		if ( (string) $dest_site_type === (string) $origin['origin_site_type'] && (string) $dest_site_id === (string) $origin['origin_site_id'] ) {
			return true;
		}

		return self::has_visited( $origin, $dest_site_type, $dest_site_id );
	}

	/**
	 * Check whether (origin -> dest_site) visit exists.
	 *
	 * @param array  $origin
	 * @param string $visited_site_type
	 * @param string $visited_site_id
	 * @return bool
	 */
	public static function has_visited( $origin, $visited_site_type, $visited_site_id ) {
		global $wpdb;

		$table = wptsall_table( 'origin_visits' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i
				 WHERE origin_site_type = %s
				   AND origin_site_id = %s
				   AND origin_object_type = %s
				   AND origin_subtype = %s
				   AND origin_object_id = %d
				   AND visited_site_type = %s
				   AND visited_site_id = %s
				 LIMIT 1',
				$table,
				(string) ( $origin['origin_site_type'] ?? '' ),
				(string) ( $origin['origin_site_id'] ?? '' ),
				(string) ( $origin['origin_object_type'] ?? '' ),
				(string) ( $origin['origin_subtype'] ?? '' ),
				(int) ( $origin['origin_object_id'] ?? 0 ),
				(string) $visited_site_type,
				(string) $visited_site_id
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Record (origin -> visited site) visit.
	 *
	 * @param array       $origin
	 * @param string      $visited_site_type
	 * @param string      $visited_site_id
	 * @param int|null    $visited_object_id Optional destination object ID.
	 * @return bool
	 */
	public static function record_visit( $origin, $visited_site_type, $visited_site_id, $visited_object_id = null ) {
		global $wpdb;

		$table = wptsall_table( 'origin_visits' );
		$now   = current_time( 'mysql' );

		$data = array(
			'origin_site_type'   => (string) ( $origin['origin_site_type'] ?? '' ),
			'origin_site_id'     => (string) ( $origin['origin_site_id'] ?? '' ),
			'origin_object_type' => (string) ( $origin['origin_object_type'] ?? '' ),
			'origin_subtype'     => (string) ( $origin['origin_subtype'] ?? '' ),
			'origin_object_id'   => (int) ( $origin['origin_object_id'] ?? 0 ),
			'visited_site_type'  => (string) $visited_site_type,
			'visited_site_id'    => (string) $visited_site_id,
			'visited_object_id'  => null !== $visited_object_id ? (int) $visited_object_id : null,
			'updated_at'         => $now,
		);

		// Insert if not exists; otherwise update visited_object_id and updated_at.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i
				 WHERE origin_site_type = %s
				   AND origin_site_id = %s
				   AND origin_object_type = %s
				   AND origin_subtype = %s
				   AND origin_object_id = %d
				   AND visited_site_type = %s
				   AND visited_site_id = %s
				 LIMIT 1',
				$table,
				$data['origin_site_type'],
				$data['origin_site_id'],
				$data['origin_object_type'],
				$data['origin_subtype'],
				$data['origin_object_id'],
				$data['visited_site_type'],
				$data['visited_site_id']
			)
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$res = $wpdb->update(
				$table,
				array(
					'visited_object_id' => $data['visited_object_id'],
					'updated_at'        => $now,
				),
				array( 'id' => (int) $existing_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			return false !== $res;
		}

		$data['created_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$res = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $res;
	}
}
