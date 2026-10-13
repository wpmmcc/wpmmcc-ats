<?php
/**
 * Resolve lab site_relation IDs for plugin-coverage harnesses.
 *
 * Prefer (in order): env → catalog.json hints → first matching DB rows.
 * Never hard-require a fixed lab ID like 626/627.
 *
 * @package WPTSALL
 */

if ( ! function_exists( 'cov_relation_row' ) ) {
	/**
	 * @param int $id Relation ID.
	 * @return array|null
	 */
	function cov_relation_row( int $id ): ?array {
		if ( $id <= 0 || ! class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			return null;
		}
		$row = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $id );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param string $type virtual|wp
	 * @return int
	 */
	function cov_find_relation_id_by_type( string $type ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE target_site_type = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
				$type
			)
		);
		return $id;
	}

	/**
	 * @return array{virtual:int,wp:int,notes:array<int,string>}
	 */
	function cov_resolve_relations(): array {
		$notes = array();
		$cat   = function_exists( 'cov_catalog' ) ? cov_catalog() : array();
		if ( ! is_array( $cat ) ) {
			$cat = array();
		}

		$env_v = (int) ( getenv( 'WPTSALL_COV_VIRTUAL_RELATION' ) ?: 0 );
		$env_w = (int) ( getenv( 'WPTSALL_COV_WP_RELATION' ) ?: 0 );
		$hint_v = (int) ( $cat['relations']['virtual'] ?? 0 );
		$hint_w = (int) ( $cat['relations']['wp'] ?? 0 );

		$virtual = 0;
		foreach ( array( $env_v, $hint_v ) as $candidate ) {
			if ( $candidate > 0 && cov_relation_row( $candidate ) ) {
				$virtual = $candidate;
				break;
			}
			if ( $candidate > 0 ) {
				$notes[] = "relation {$candidate} missing; falling back";
			}
		}
		if ( $virtual <= 0 ) {
			$virtual = cov_find_relation_id_by_type( 'virtual' );
			if ( $virtual > 0 ) {
				$notes[] = "resolved virtual relation={$virtual} from DB";
			}
		}

		$wp = 0;
		foreach ( array( $env_w, $hint_w ) as $candidate ) {
			if ( $candidate > 0 && cov_relation_row( $candidate ) ) {
				$wp = $candidate;
				break;
			}
			if ( $candidate > 0 ) {
				$notes[] = "relation {$candidate} missing; falling back";
			}
		}
		if ( $wp <= 0 ) {
			$wp = cov_find_relation_id_by_type( 'wp' );
			if ( $wp > 0 ) {
				$notes[] = "resolved wp relation={$wp} from DB";
			}
		}

		return array(
			'virtual' => $virtual,
			'wp'      => $wp,
			'notes'   => $notes,
		);
	}
}
