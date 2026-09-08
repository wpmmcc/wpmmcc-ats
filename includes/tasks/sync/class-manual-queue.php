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

		return false !== $result;
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
