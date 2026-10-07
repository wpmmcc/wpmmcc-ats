<?php
/**
 * Manual Queue for Non-Text Write-Back Items
 *
 * Items that cannot be automatically applied by write-back adapters
 * are routed here for manual review and processing.
 *
 * Queue items are stored in the wp_wptsall_manual_queue table
 * with status tracking and admin UI integration.
 *
 * @package WPTSALL\Tasks\Sync
 * @since 1.0.5
 */

namespace WPTSALL\Tasks\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual queue for non-text items that need human intervention.
 */
class Manual_Queue {

	/**
	 * Queue status constants.
	 */
	const STATUS_PENDING   = 'pending';
	const STATUS_REVIEWING = 'reviewing';
	const STATUS_APPLIED   = 'applied';
	const STATUS_REJECTED  = 'rejected';
	const STATUS_EXPIRED   = 'expired';

	/**
	 * Enqueue an item that could not be auto-applied.
	 *
	 * @param array $item    The non-text item.
	 *   Expected keys: 'source_id', 'entity_type', 'translated_ref', 'metadata'.
	 * @param array $context Sync context.
	 *   Expected keys: 'relation_id', 'task_id', 'source_blog', 'target_blog', 'adapter'.
	 * @param string $reason Why the item was routed to manual queue.
	 * @return int|false Queue item ID or false on failure.
	 */
	public static function enqueue( array $item, array $context, string $reason = '' ) {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		$data = array(
			'task_id'      => (int) ( $context['task_id'] ?? 0 ),
			'relation_id'  => (int) ( $context['relation_id'] ?? 0 ),
			'source_blog'  => (int) ( $context['source_blog'] ?? 0 ),
			'target_blog'  => (int) ( $context['target_blog'] ?? 0 ),
			'entity_type'  => sanitize_key( (string) ( $item['entity_type'] ?? 'unknown' ) ),
			'source_id'    => (int) ( $item['source_id'] ?? 0 ),
			'adapter_type' => sanitize_key( (string) ( $context['adapter'] ?? '' ) ),
			'payload'      => wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'reason'       => sanitize_text_field( $reason ),
			'status'       => self::STATUS_PENDING,
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);

		// Check if table exists before inserting.
		if ( ! self::table_exists() ) {
			wptsall_log_warning(
				'tasks-sync',
				'Manual queue table does not exist yet; item not queued',
				array(
					'entity_type' => $data['entity_type'],
					'source_id'   => $data['source_id'],
					'reason'      => $data['reason'],
				)
			);
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			$data,
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			wptsall_log_error(
				'tasks-sync',
				'Failed to enqueue manual queue item',
				array(
					'entity_type' => $data['entity_type'],
					'source_id'   => $data['source_id'],
					'error'       => $wpdb->last_error,
				)
			);
			return false;
		}

		$queue_id = $wpdb->insert_id;

		wptsall_log_info(
			'tasks-sync',
			'Item enqueued to manual queue',
			array(
				'queue_id'     => $queue_id,
				'entity_type'  => $data['entity_type'],
				'source_id'    => $data['source_id'],
				'adapter_type' => $data['adapter_type'],
				'reason'       => $data['reason'],
			)
		);

		return $queue_id;
	}

	/**
	 * Get pending queue items.
	 *
	 * @param array $args Query arguments.
	 *   Optional keys: 'relation_id', 'entity_type', 'adapter_type', 'status', 'limit', 'offset'.
	 * @return array List of queue items.
	 */
	public static function get_items( array $args = array() ): array {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		if ( ! self::table_exists() ) {
			return array();
		}

		$status      = sanitize_key( (string) ( $args['status'] ?? self::STATUS_PENDING ) );
		$limit       = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset      = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where       = array( 'status = %s' );
		$where_vals  = array( $status );

		if ( ! empty( $args['relation_id'] ) ) {
			$where[]     = 'relation_id = %d';
			$where_vals[] = (int) $args['relation_id'];
		}

		if ( ! empty( $args['entity_type'] ) ) {
			$where[]     = 'entity_type = %s';
			$where_vals[] = sanitize_key( (string) $args['entity_type'] );
		}

		if ( ! empty( $args['adapter_type'] ) ) {
			$where[]     = 'adapter_type = %s';
			$where_vals[] = sanitize_key( (string) $args['adapter_type'] );
		}

		$where_sql = implode( ' AND ', $where );
		// Table first for %i; remaining values are bound placeholders from fixed fragments.
		$query_vals = array_merge( array( $table ), $where_vals, array( $limit, $offset ) );

		$items = wptsall_db_get_results(
			'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY created_at ASC LIMIT %d OFFSET %d',
			$query_vals,
			ARRAY_A
		);

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Update queue item status.
	 *
	 * @param int    $queue_id Queue item ID.
	 * @param string $status   New status.
	 * @param string $note     Optional note.
	 * @return bool True on success.
	 */
	public static function update_status( int $queue_id, string $status, string $note = '' ): bool {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		if ( ! self::table_exists() ) {
			return false;
		}

		$valid_statuses = array(
			self::STATUS_PENDING,
			self::STATUS_REVIEWING,
			self::STATUS_APPLIED,
			self::STATUS_REJECTED,
			self::STATUS_EXPIRED,
		);

		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return false;
		}

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);
		$format = array( '%s', '%s' );

		if ( '' !== $note ) {
			$data['reason'] = sanitize_text_field( $note );
			$format[]       = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $data, array( 'id' => $queue_id ), $format, array( '%d' ) );

		if ( false === $result ) {
			return false;
		}
		if ( 0 === (int) $result ) {
			// wpdb->update returns 0 both for a missing row and for a no-op
			// update (same status within the same second). Distinguish them:
			// a missing row is a failure, a no-op on an existing row is
			// success.
			$row = self::get_item( $queue_id );
			return null !== $row && $status === (string) ( $row['status'] ?? '' );
		}
		return true;
	}

	/**
	 * Get count of pending items.
	 *
	 * @param array $args Optional filter args (relation_id, entity_type).
	 * @return int Count.
	 */
	public static function count_pending( array $args = array() ): int {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		if ( ! self::table_exists() ) {
			return 0;
		}

		$where      = array( 'status = %s' );
		$where_vals = array( self::STATUS_PENDING );

		if ( ! empty( $args['relation_id'] ) ) {
			$where[]     = 'relation_id = %d';
			$where_vals[] = (int) $args['relation_id'];
		}

		$where_sql  = implode( ' AND ', $where );
		$query_vals = array_merge( array( $table ), $where_vals );

		$count = wptsall_db_get_var(
			'SELECT COUNT(*) FROM %i WHERE ' . $where_sql,
			$query_vals
		);

		return (int) $count;
	}

	/**
	 * Get a single queue item row by ID.
	 *
	 * @param int $queue_id Queue item ID.
	 * @return array|null Row (ARRAY_A) or null when not found.
	 */
	public static function get_item( int $queue_id ): ?array {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		if ( ! self::table_exists() || $queue_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $queue_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Status counts for the queue (admin surface summary / REST list meta).
	 *
	 * @return array status => count, always covering all five statuses.
	 */
	public static function counts(): array {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		$counts = array(
			self::STATUS_PENDING   => 0,
			self::STATUS_REVIEWING => 0,
			self::STATUS_APPLIED   => 0,
			self::STATUS_REJECTED  => 0,
			self::STATUS_EXPIRED   => 0,
		);
		if ( ! self::table_exists() ) {
			return $counts;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * Apply (re-dispatch) a queued item through Write_Back_Dispatcher.
	 *
	 * A-01 / decision D-1(a) (opus5): the queue previously had three write
	 * points and zero readers; this is the "apply" action of the admin
	 * surface. The stored payload is re-dispatched with a context rebuilt
	 * from the row (the relation must exist and be active — same doctrine as
	 * M-01). On success the row is marked applied. On failure the row stays
	 * pending with the fresh error in its reason, and the duplicate pending
	 * row the failed retry enqueued is removed so exactly one row keeps
	 * tracking the item.
	 *
	 * @param int $queue_id Queue item ID.
	 * @return array|\WP_Error { queue_id, status, dispatch } or WP_Error.
	 */
	public static function apply_item( int $queue_id ) {
		global $wpdb;

		$row = self::get_item( $queue_id );

		if ( ! $row ) {
			return new \WP_Error(
				'manual_queue_item_not_found',
				__( 'Manual queue item does not exist.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$status = (string) ( $row['status'] ?? '' );
		if ( ! in_array( $status, array( self::STATUS_PENDING, self::STATUS_REVIEWING ), true ) ) {
			return new \WP_Error(
				'manual_queue_item_not_reviewable',
				sprintf(
					/* translators: %s: the item's current status */
					__( 'Only pending or reviewing items can be applied (current: %s).', 'wpmmcc-ats' ),
					$status
				),
				array( 'status' => 409 )
			);
		}

		$item = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( ! is_array( $item ) ) {
			return new \WP_Error(
				'manual_queue_payload_invalid',
				__( 'The queued payload is not valid JSON and cannot be re-applied.', 'wpmmcc-ats' ),
				array( 'status' => 400 )
			);
		}

		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( (int) ( $row['relation_id'] ?? 0 ) );
		if ( ! $relation ) {
			return new \WP_Error(
				'relation_not_found',
				__( 'Site relation not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// M-01 doctrine: an inactive relation never accepts write-back either.
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_Error(
				'relation_inactive',
				__( 'Site relation is not active.', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}

		$context = array(
			'relation_id' => (int) ( $row['relation_id'] ?? 0 ),
			'task_id'     => (int) ( $row['task_id'] ?? 0 ),
			'source_blog' => (int) ( $row['source_blog'] ?? 0 ),
			'target_blog' => (int) ( $row['target_blog'] ?? 0 ),
			'target_type' => (string) ( $relation['target_site_type'] ?? 'wp' ),
			'lang_to'     => (string) ( $relation['target_lang'] ?? '' ),
		);

		$result = Write_Back_Dispatcher::dispatch( $item, $context );

		if ( ! empty( $result['success'] ) ) {
			$note = sprintf(
				'Applied via manual queue (adapter=%s, target_id=%s)',
				(string) ( $result['adapter'] ?? 'unknown' ),
				(string) ( $result['target_id'] ?? '' )
			);
			self::update_status( $queue_id, self::STATUS_APPLIED, $note );
			return array(
				'queue_id' => $queue_id,
				'status'   => self::STATUS_APPLIED,
				'dispatch' => $result,
			);
		}

		// The failed retry enqueued a duplicate pending row; remove it so a
		// single row keeps tracking this item, then surface the fresh error
		// on the row under review (which stays pending).
		$duplicate = (int) ( $result['queue_id'] ?? 0 );
		if ( $duplicate > 0 && $duplicate !== $queue_id ) {
			$table = wptsall_table( 'manual_queue' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $duplicate ), array( '%d' ) );
		}
		$error = (string) ( $result['error'] ?? __( 'Adapter apply failed', 'wpmmcc-ats' ) );
		self::update_status( $queue_id, $status, $error );
		return new \WP_Error(
			'manual_queue_apply_failed',
			$error,
			array( 'status' => 500 )
		);
	}

	/**
	 * Expire pending items older than N days (review backlog cleanup).
	 *
	 * Marks only pending rows; applied/rejected/expired rows are untouched,
	 * and the original routing reason is preserved (status + updated_at are
	 * the audit trail).
	 *
	 * @param int $days  Age threshold in days (default 30, min 1).
	 * @param int $limit Max rows to expire in one call (default 200, max 1000).
	 * @return int Number of rows marked expired.
	 */
	public static function expire_old( int $days = 30, int $limit = 200 ): int {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		if ( ! self::table_exists() ) {
			return 0;
		}
		$days  = max( 1, $days );
		$limit = max( 1, min( 1000, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND created_at < DATE_SUB( %s, INTERVAL %d DAY ) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING,
				current_time( 'mysql' ),
				$days,
				$limit
			),
			ARRAY_A
		);

		$expired = 0;
		foreach ( (array) $rows as $row ) {
			if ( self::update_status( (int) $row['id'], self::STATUS_EXPIRED ) ) {
				++$expired;
			}
		}
		return $expired;
	}

	/**
	 * Check if the manual_queue table exists.
	 *
	 * @return bool
	 */
	private static function table_exists(): bool {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$exists = ( $result === $table );
		return $exists;
	}
}
