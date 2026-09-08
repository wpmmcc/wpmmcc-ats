<?php
/**
 * WPTSALL Tasks List Table Class
 *
 * Extends WP_List_Table to provide standard WordPress list table for tasks.
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WPTSALL_Tasks_List_Table class.
 */
class WPTSALL_Tasks_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'task',
				'plural'   => 'tasks',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'id'          => __( 'ID', 'wpmmcc-ats' ),
			'job_tree'    => __( 'Job / Task / Subtask', 'wpmmcc-ats' ),
			'template'    => __( 'Template', 'wpmmcc-ats' ),
			'site_id'     => __( 'Site Relation', 'wpmmcc-ats' ),
			'object'      => __( 'Object', 'wpmmcc-ats' ),
			'status'      => __( 'Status', 'wpmmcc-ats' ),
			'retry_count' => __( 'Retry Count', 'wpmmcc-ats' ),
			'client_agg'  => __( 'Client Aggregate', 'wpmmcc-ats' ),
			'created_at'  => __( 'Created', 'wpmmcc-ats' ),
			'status_note' => __( 'Note', 'wpmmcc-ats' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'         => array( 'id', true ),
			'template'   => array( 'template', false ),
			'site_id'    => array( 'site_id', false ),
			'status'     => array( 'status', false ),
			'retry_count'=> array( 'retry_count', false ),
			'created_at' => array( 'created_at', false ),
		);
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'process' => __( 'Process', 'wpmmcc-ats' ),
			'retry'   => __( 'Retry', 'wpmmcc-ats' ),
			'delete'  => __( 'Delete', 'wpmmcc-ats' ),
		);
	}

	/**
	 * Render checkbox column.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="task_ids[]" value="%d" />', intval( $item['id'] ) );
	}

	/**
	 * Render ID column with log link.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_id( $item ) {
		$log_url = add_query_arg(
			array(
				'page'    => 'wptsall-tasks',
				'log_for' => $item['id'],
			),
			admin_url( 'admin.php' )
		);
		return sprintf(
			'%d<br><a href="%s" class="button button-small">%s</a>',
			intval( $item['id'] ),
			esc_url( $log_url ),
			esc_html__( 'View Log', 'wpmmcc-ats' )
		);
	}

	/**
	 * Render job/task/subtask hierarchy column.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_job_tree( $item ) {
		$payload       = $this->decode_json_assoc( $item['payload'] ?? '' );
		$meta          = $this->decode_json_assoc( $item['meta'] ?? '' );
		$job_id        = sanitize_text_field( (string) ( $payload['job_id'] ?? '' ) );
		$business_line = sanitize_key( (string) ( $payload['business_line'] ?? '' ) );
		$task_type     = sanitize_key( (string) ( $payload['task_type'] ?? ( $payload['type'] ?? 'text' ) ) );
		$object_type   = sanitize_key( (string) ( $item['object_type'] ?? '' ) );
		$object_id     = intval( $item['object_id'] ?? 0 );

		if ( '' === $business_line && function_exists( 'wptsall_infer_business_line_from_task' ) ) {
			$business_line = sanitize_key( (string) wptsall_infer_business_line_from_task( array_merge( (array) $item, $payload ) ) );
		}
		if ( '' === $business_line ) {
			$business_line = 'custom_model';
		}

		$subtasks = array();
		if ( is_array( $meta['client_result']['subtasks'] ?? null ) ) {
			$subtasks = $meta['client_result']['subtasks'];
		} elseif ( is_array( $payload['subtasks'] ?? null ) ) {
			$subtasks = $payload['subtasks'];
		} elseif ( is_array( $payload['content_items'] ?? null ) ) {
			$subtasks = $payload['content_items'];
		}
		$subtask_summary = $this->summarize_subtasks( $subtasks );
		$retry_filter    = is_array( $payload['manual_retry_only_subtasks'] ?? null ) ? $payload['manual_retry_only_subtasks'] : array();
		$manual_queue    = $this->summarize_manual_queue( $meta );
		$job_snapshot    = $this->load_job_snapshot( $job_id );

		$html  = '<div style="font-size:11px;line-height:1.6;">';
		$html .= sprintf(
			'<div><strong>Job:</strong> <code>%s</code></div>',
			'' !== $job_id ? esc_html( $job_id ) : '-'
		);
		if ( ! empty( $job_snapshot ) ) {
			$html .= sprintf(
				'<div><strong>JobStatus:</strong> %s (total=%d, completed=%d, retry=%d, failed=%d)</div>',
				esc_html( sanitize_key( (string) ( $job_snapshot['status'] ?? '' ) ) ),
				intval( $job_snapshot['total'] ?? 0 ),
				intval( $job_snapshot['completed'] ?? 0 ),
				intval( $job_snapshot['retry'] ?? 0 ),
				intval( $job_snapshot['failed'] ?? 0 )
			);
		}
		$html .= sprintf(
			'<div><strong>Task:</strong> #%d (%s/%s)</div>',
			intval( $item['id'] ),
			esc_html( $business_line ),
			esc_html( $task_type )
		);
		$html .= sprintf(
			'<div><strong>Object:</strong> %s:%d</div>',
			esc_html( $object_type ),
			$object_id
		);
		$html .= sprintf(
			'<div><strong>Subtasks:</strong> total=%d, completed=%d, failed=%d, skipped=%d</div>',
			intval( $subtask_summary['total'] ),
			intval( $subtask_summary['completed'] ),
			intval( $subtask_summary['failed'] ),
			intval( $subtask_summary['skipped'] )
		);
		if ( ! empty( $retry_filter ) ) {
			$html .= '<div><strong>manual_retry_only:</strong> <code>' . esc_html( implode( ',', array_map( 'sanitize_text_field', $retry_filter ) ) ) . '</code></div>';
		}
		if ( (int) ( $manual_queue['total'] ?? 0 ) > 0 ) {
			$reason_pairs = array();
			foreach ( (array) ( $manual_queue['reasons'] ?? array() ) as $reason_key => $reason_count ) {
				$reason_pairs[] = sanitize_key( (string) $reason_key ) . ':' . intval( $reason_count );
			}
			$html .= sprintf(
				'<div><strong>manual_queue:</strong> total=%d, unapplied=%d, reasons=%s</div>',
				intval( $manual_queue['total'] ?? 0 ),
				intval( $manual_queue['unapplied_fragments'] ?? 0 ),
				! empty( $reason_pairs ) ? esc_html( implode( ',', $reason_pairs ) ) : '-'
			);
			$html .= sprintf(
				'<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">
					<button type="button" class="button button-small wptsall-manual-queue-action" data-action="retry" data-task-id="%d" data-index="%d">%s</button>
					<button type="button" class="button button-small wptsall-manual-queue-action" data-action="dismiss" data-task-id="%d" data-index="%d">%s</button>
					<button type="button" class="button button-small wptsall-manual-queue-action" data-action="clear_all" data-task-id="%d">%s</button>
				</div>',
				intval( $item['id'] ),
				intval( $manual_queue['latest_index'] ?? 0 ),
				esc_html__( 'Retry queue item', 'wpmmcc-ats' ),
				intval( $item['id'] ),
				intval( $manual_queue['latest_index'] ?? 0 ),
				esc_html__( 'Dismiss latest queue item', 'wpmmcc-ats' ),
				intval( $item['id'] ),
				esc_html__( 'Clear manual queue', 'wpmmcc-ats' )
			);
		}
		if ( ! empty( $subtasks ) ) {
			$html .= '<details style="margin-top:4px;">';
			$html .= '<summary style="cursor:pointer;">' . esc_html__( 'View subtasks', 'wpmmcc-ats' ) . '</summary>';
			$html .= '<div style="margin-top:6px;border:1px solid #e2e4e7;border-radius:3px;padding:6px;background:#fafafa;">';
			foreach ( $subtasks as $subtask ) {
				if ( ! is_array( $subtask ) ) {
					continue;
				}
				$subtask_type = $this->normalize_subtask_type( (string) ( $subtask['type'] ?? ( $subtask['task_type'] ?? 'text' ) ) );
				$subtask_key  = $this->normalize_subtask_key( (string) ( $subtask['key'] ?? ( $subtask['id'] ?? '' ) ) );
				$subtask_code = sanitize_text_field( (string) ( $subtask['error_code'] ?? '' ) );
				$subtask_note = sanitize_text_field( (string) ( $subtask['error_message'] ?? ( $subtask['message'] ?? '' ) ) );
				$subtask_status = sanitize_key( (string) ( $subtask['status'] ?? 'pending' ) );

				$html .= '<div style="padding:4px 0;border-bottom:1px dashed #e2e4e7;">';
				$html .= sprintf(
					'<div><code>%s:%s</code> <strong style="color:%s;">%s</strong></div>',
					esc_html( $subtask_type ),
					esc_html( $subtask_key ),
					esc_attr( $this->subtask_status_color( $subtask_status ) ),
					esc_html( $subtask_status )
				);
				if ( '' !== $subtask_code ) {
					$html .= sprintf( '<div style="color:#666;">code=%s</div>', esc_html( $subtask_code ) );
				}
				if ( '' !== $subtask_note ) {
					$html .= sprintf( '<div style="color:#666;">%s</div>', esc_html( $subtask_note ) );
				}
				if ( '' !== $subtask_key ) {
					$html .= sprintf(
						'<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">
							<button type="button" class="button button-small wptsall-subtask-action" data-action="retry" data-task-id="%d" data-subtask-type="%s" data-subtask-key="%s">%s</button>
							<button type="button" class="button button-small wptsall-subtask-action" data-action="skip" data-task-id="%d" data-subtask-type="%s" data-subtask-key="%s">%s</button>
						</div>',
						intval( $item['id'] ),
						esc_attr( $subtask_type ),
						esc_attr( $subtask_key ),
						esc_html__( 'Retry subtask', 'wpmmcc-ats' ),
						intval( $item['id'] ),
						esc_attr( $subtask_type ),
						esc_attr( $subtask_key ),
						esc_html__( 'Skip subtask', 'wpmmcc-ats' )
					);
				}
				$html .= '</div>';
			}
			$html .= '</div></details>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render object column.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_object( $item ) {
		return sprintf(
			'%s:%s (%d)',
			esc_html( $item['object_type'] ),
			esc_html( $item['subtype'] ),
			intval( $item['object_id'] )
		);
	}

	/**
	 * Render status column with color badge.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status_colors = array(
			'pending'    => '#0073aa',
			'processing' => '#f0b849',
			'completed'  => '#46b450',
			'failed'     => '#dc3232',
			'retry'      => '#dc3232',
		);
		$color = isset( $status_colors[ $item['status'] ] ) ? $status_colors[ $item['status'] ] : '#999';
		$badge = sprintf(
			'<span style="display:inline-block;padding:3px 8px;border-radius:3px;background:%s;color:#fff;font-size:11px;font-weight:600;">%s</span>',
			esc_attr( $color ),
			esc_html( $item['status'] )
		);

		$retry_count = isset( $item['retry_count'] ) ? intval( $item['retry_count'] ) : 0;
		$retry_html  = sprintf(
			'<div style="margin-top:4px;color:#666;font-size:11px;">retry=%d</div>',
			$retry_count
		);

		return $badge . $retry_html;
	}

	/**
	 * Render retry count column.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_retry_count( $item ) {
		$retry_count = intval( $item['retry_count'] ?? 0 );
		$color       = $retry_count > 0 ? '#dc3232' : '#666';
		return sprintf(
			'<strong style="color:%s;">%d</strong>',
			esc_attr( $color ),
			$retry_count
		);
	}

	/**
	 * Render client aggregate column.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_client_agg( $item ) {
		$meta      = $this->decode_json_assoc( $item['meta'] ?? '' );
		$aggregate = isset( $meta['client_result_aggregate'] ) && is_array( $meta['client_result_aggregate'] )
			? $meta['client_result_aggregate']
			: array();
		$envelope  = isset( $meta['client_result_envelope'] ) && is_array( $meta['client_result_envelope'] )
			? $meta['client_result_envelope']
			: array();

		if ( empty( $aggregate ) ) {
			return '<span style="color:#999;">-</span>';
		}

		$field_total    = intval( $aggregate['field_total'] ?? 0 );
		$field_fallback = intval( $aggregate['field_fallback'] ?? 0 );
		$subtask_total  = intval( $aggregate['subtask_total'] ?? 0 );
		$subtask_failed = intval( $aggregate['subtask_failed'] ?? 0 );
		$component_id   = sanitize_text_field( (string) ( $envelope['component_id'] ?? '' ) );
		$elapsed_ms     = intval( $envelope['elapsed_ms'] ?? 0 );

		return sprintf(
			'<div style="font-size:11px;line-height:1.6;">
				<div>fields: <strong>%d</strong></div>
				<div>fallback: <strong>%d</strong></div>
				<div>subtasks: <strong>%d</strong></div>
				<div>subtask_failed: <strong>%d</strong></div>
				<div>component: <strong>%s</strong></div>
				<div>elapsed: <strong>%dms</strong></div>
			</div>',
			$field_total,
			$field_fallback,
			$subtask_total,
			$subtask_failed,
			'' !== $component_id ? esc_html( $component_id ) : '-',
			$elapsed_ms
		);
	}

	/**
	 * Render status note column with last client status summary.
	 *
	 * @param array $item Task item.
	 * @return string
	 */
	protected function column_status_note( $item ) {
		$note = isset( $item['status_note'] ) ? (string) $item['status_note'] : '';
		$meta = $this->decode_json_assoc( $item['meta'] ?? '' );
		$last = isset( $meta['client_last_status'] ) && is_array( $meta['client_last_status'] )
			? $meta['client_last_status']
			: array();

		$html = '';
		if ( '' !== $note ) {
			$html .= '<div>' . esc_html( $note ) . '</div>';
		}
		if ( ! empty( $last ) ) {
			$html .= sprintf(
				'<div style="margin-top:4px;color:#666;font-size:11px;">
					client=%s, progress=%d, at=%s
				</div>',
				esc_html( sanitize_key( (string) ( $last['status'] ?? '' ) ) ),
				intval( $last['progress'] ?? 0 ),
				esc_html( sanitize_text_field( (string) ( $last['at'] ?? '' ) ) )
			);
		}

		return '' !== $html ? $html : '<span style="color:#999;">-</span>';
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item Task item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Decode JSON to associative array safely.
	 *
	 * @param string $raw Raw JSON.
	 * @return array
	 */
	private function decode_json_assoc( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Summarize subtasks list.
	 *
	 * @param array $subtasks Subtasks.
	 * @return array
	 */
	private function summarize_subtasks( $subtasks ) {
		$summary = array(
			'total'     => 0,
			'completed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'pending'   => 0,
		);
		foreach ( (array) $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			++$summary['total'];
			$status = sanitize_key( (string) ( $subtask['status'] ?? 'pending' ) );
			if ( in_array( $status, array( 'completed', 'done', 'success' ), true ) ) {
				++$summary['completed'];
			} elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
				++$summary['failed'];
			} elseif ( in_array( $status, array( 'skipped', 'noop' ), true ) ) {
				++$summary['skipped'];
			} else {
				++$summary['pending'];
			}
		}
		return $summary;
	}

	/**
	 * Summarize manual queue from task meta.
	 *
	 * @param array $meta Task meta.
	 * @return array
	 */
	private function summarize_manual_queue( $meta ) {
		$queue = is_array( $meta['client_patch_manual_queue'] ?? null )
			? array_values( $meta['client_patch_manual_queue'] )
			: array();
		$summary = array(
			'total'               => 0,
			'latest_index'        => 0,
			'unapplied_fragments' => 0,
			'last_recorded_at'    => '',
			'reasons'             => array(),
		);
		foreach ( $queue as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$summary['total'] = $idx + 1;
			$reason = sanitize_key( (string) ( $item['reason'] ?? 'unknown' ) );
			if ( '' === $reason ) {
				$reason = 'unknown';
			}
			$summary['reasons'][ $reason ] = intval( $summary['reasons'][ $reason ] ?? 0 ) + 1;
			$summary['unapplied_fragments'] += count( (array) ( $item['unapplied_fragments'] ?? array() ) );
			$recorded_at = sanitize_text_field( (string) ( $item['recorded_at'] ?? '' ) );
			if ( '' !== $recorded_at && ( '' === $summary['last_recorded_at'] || strtotime( $recorded_at ) > strtotime( $summary['last_recorded_at'] ) ) ) {
				$summary['last_recorded_at'] = $recorded_at;
			}
		}
		$summary['latest_index'] = intval( $summary['total'] );
		arsort( $summary['reasons'] );
		return $summary;
	}

	/**
	 * Normalize subtask type.
	 *
	 * @param string $raw Raw type.
	 * @return string
	 */
	private function normalize_subtask_type( $raw ) {
		$raw = sanitize_key( strtolower( trim( $raw ) ) );
		if ( in_array( $raw, array( 'image', 'images', 'image_translation' ), true ) ) {
			return 'image';
		}
		if ( in_array( $raw, array( 'video', 'videos', 'video_translation' ), true ) ) {
			return 'video';
		}
		if ( in_array( $raw, array( 'audio', 'audios', 'audio_translation' ), true ) ) {
			return 'audio';
		}
		if ( in_array( $raw, array( 'document', 'documents', 'doc', 'file', 'files', 'document_translation' ), true ) ) {
			return 'document';
		}
		return 'text';
	}

	/**
	 * Normalize subtask key.
	 *
	 * @param string $raw Raw key.
	 * @return string
	 */
	private function normalize_subtask_key( $raw ) {
		$key = strtolower( trim( (string) $raw ) );
		$key = str_replace( array( ' ', '.', '/' ), '_', $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', (string) $key );
		$key = preg_replace( '/_{2,}/', '_', (string) $key );
		return sanitize_key( trim( (string) $key, '_' ) );
	}

	/**
	 * Get subtask status color.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function subtask_status_color( $status ) {
		if ( in_array( $status, array( 'completed', 'done', 'success' ), true ) ) {
			return '#46b450';
		}
		if ( in_array( $status, array( 'failed', 'error' ), true ) ) {
			return '#dc3232';
		}
		if ( in_array( $status, array( 'skipped', 'noop' ), true ) ) {
			return '#646970';
		}
		return '#0073aa';
	}

	/**
	 * Load job aggregate snapshot from option cache.
	 *
	 * @param string $job_id Job id.
	 * @return array
	 */
	private function load_job_snapshot( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return array();
		}

		static $snapshot_cache = array();
		if ( isset( $snapshot_cache[ $job_id ] ) ) {
			return $snapshot_cache[ $job_id ];
		}

		$snapshot = array();
		if ( function_exists( 'wptsall_get_task_job_snapshot' ) ) {
			$snapshot = wptsall_get_task_job_snapshot( $job_id );
		}
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			$option_key = 'wptsall_job_aggregate_' . md5( $job_id );
			$snapshot   = get_option( $option_key, array() );
			if ( ! is_array( $snapshot ) ) {
				$snapshot = array();
			}
		}
		$snapshot_cache[ $job_id ] = $snapshot;
		return $snapshot;
	}

	/**
	 * Display when no items found.
	 */
	public function no_items() {
		esc_html_e( 'No tasks.', 'wpmmcc-ats' );
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		global $wpdb;

		// Get filter parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$site_id  = isset( $_GET['site_id'] ) ? intval( $_GET['site_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$business_line = isset( $_GET['business_line'] ) ? sanitize_key( wp_unslash( $_GET['business_line'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$subtask_status = isset( $_GET['subtask_status'] ) ? sanitize_key( wp_unslash( $_GET['subtask_status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// Get sorting parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for sorting
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for sorting
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC';

		// Validate orderby/order via whitelist maps only (no esc_sql into SQL).
		$allowed_orderby = array(
			'id'          => 'id',
			'template'    => 'template',
			'site_id'     => 'site_id',
			'status'      => 'status',
			'retry_count' => 'retry_count',
			'created_at'  => 'created_at',
		);
		$orderby = isset( $allowed_orderby[ $orderby ] ) ? $allowed_orderby[ $orderby ] : 'id';

		$allowed_order = array(
			'asc'  => 'ASC',
			'desc' => 'DESC',
		);
		$order_key = strtolower( (string) $order );
		$order     = isset( $allowed_order[ $order_key ] ) ? $allowed_order[ $order_key ] : 'DESC';

		// Build query.
		$table            = wptsall_table( 'tasks' );
		$where_conditions = array();
		$params           = array( $table );

		if ( $status ) {
			$where_conditions[] = 'status = %s';
			$params[]           = $status;
		}
		if ( $site_id ) {
			$where_conditions[] = 'site_id = %d';
			$params[]           = $site_id;
		}
		if ( $template ) {
			$where_conditions[] = 'template = %s';
			$params[]           = $template;
		}
		if ( '' !== $job_id ) {
			$where_conditions[] = 'payload LIKE %s';
			$params[] = '%"job_id":"' . $wpdb->esc_like( $job_id ) . '"%';
		}
		if ( '' !== $business_line ) {
			$where_conditions[] = 'payload LIKE %s';
			$params[] = '%"business_line":"' . $wpdb->esc_like( $business_line ) . '"%';
		}
		if ( '' !== $subtask_status ) {
			$subtask_patterns = array();
			if ( 'failed' === $subtask_status ) {
				$subtask_patterns = array( '"status":"failed"', '"status":"error"' );
			} elseif ( 'completed' === $subtask_status ) {
				$subtask_patterns = array( '"status":"completed"', '"status":"success"', '"status":"done"' );
			} elseif ( 'skipped' === $subtask_status ) {
				$subtask_patterns = array( '"status":"skipped"', '"status":"noop"' );
			} elseif ( 'pending' === $subtask_status ) {
				$subtask_patterns = array( '"status":"pending"', '"status":"processing"' );
			}
			if ( ! empty( $subtask_patterns ) ) {
				$parts = array();
				foreach ( $subtask_patterns as $pattern ) {
					$parts[] = 'meta LIKE %s';
					$params[] = '%' . $wpdb->esc_like( $pattern ) . '%';
				}
				$where_conditions[] = '(' . implode( ' OR ', $parts ) . ')';
			}
		}
		if ( $search ) {
			$where_conditions[] = '(object_type LIKE %s OR subtype LIKE %s OR status_note LIKE %s)';
			$search_term        = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]           = $search_term;
			$params[]           = $search_term;
			$params[]           = $search_term;
		}

		// Get total count.
		if ( ! empty( $where_conditions ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where_conditions );
			$total_items  = intval(
				wptsall_db_get_var(
					'SELECT COUNT(*) FROM %i ' . $where_clause,
					$params
				)
			);
		} else {
			$total_items = intval(
				wptsall_db_get_var(
					'SELECT COUNT(*) FROM %i',
					$params
				)
			);
		}

		// Set pagination.
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);

		// Get items.
		$offset   = ( $current_page - 1 ) * $per_page;
		$params[] = $per_page;
		$params[] = $offset;

		// $orderby/$order are allowlisted; $where_clause is fixed placeholder fragments only.
		if ( ! empty( $where_conditions ) ) {
			$this->items = wptsall_db_get_results(
				'SELECT * FROM %i ' . $where_clause . ' ORDER BY ' . $orderby . ' ' . $order . ' LIMIT %d OFFSET %d',
				$params,
				ARRAY_A
			);
		} else {
			$this->items = wptsall_db_get_results(
				'SELECT * FROM %i ORDER BY ' . $orderby . ' ' . $order . ' LIMIT %d OFFSET %d',
				$params,
				ARRAY_A
			);
		}

		// Set up columns.
		$this->_column_headers = array(
			$this->get_columns(),
			array(), // Hidden columns.
			$this->get_sortable_columns(),
			$this->get_primary_column_name(),
		);
	}

	/**
	 * Display filters above the table.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$sites     = get_option( 'wptsall_sites', array() );
		$templates = wptsall_saved_templates();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$site_id  = isset( $_GET['site_id'] ) ? intval( $_GET['site_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$business_line = isset( $_GET['business_line'] ) ? sanitize_key( wp_unslash( $_GET['business_line'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering
		$subtask_status = isset( $_GET['subtask_status'] ) ? sanitize_key( wp_unslash( $_GET['subtask_status'] ) ) : '';
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="filter-by-status"><?php esc_html_e( 'Filter by Status', 'wpmmcc-ats' ); ?></label>
			<select name="status" id="filter-by-status">
				<option value=""><?php esc_html_e( 'All Statuses', 'wpmmcc-ats' ); ?></option>
			<?php foreach ( array( 'pending', 'processing', 'retry', 'failed', 'completed' ) as $st ) : ?>
					<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( $st ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="filter-by-site"><?php esc_html_e( 'Filter by Site', 'wpmmcc-ats' ); ?></label>
			<select name="site_id" id="filter-by-site">
				<option value="0"><?php esc_html_e( 'All Sites', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( $sites as $s ) : ?>
					<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $site_id, intval( $s['id'] ) ); ?>>
						<?php echo esc_html( $s['template'] ?? '' ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="filter-by-template"><?php esc_html_e( 'Filter by Template', 'wpmmcc-ats' ); ?></label>
			<select name="template" id="filter-by-template">
				<option value=""><?php esc_html_e( 'All Templates', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( $templates as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $template, $slug ); ?>>
						<?php echo esc_html( $name ); ?> (<?php echo esc_html( $slug ); ?>)
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="filter-by-business-line"><?php esc_html_e( 'Filter by Business Line', 'wpmmcc-ats' ); ?></label>
			<select name="business_line" id="filter-by-business-line">
				<option value=""><?php esc_html_e( 'All Business Lines', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( array( 'post_content', 'taxonomy_content', 'plugin_i18n', 'theme_i18n', 'custom_model' ) as $line ) : ?>
					<option value="<?php echo esc_attr( $line ); ?>" <?php selected( $business_line, $line ); ?>><?php echo esc_html( $line ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="filter-by-subtask-status"><?php esc_html_e( 'Filter by Subtask Status', 'wpmmcc-ats' ); ?></label>
			<select name="subtask_status" id="filter-by-subtask-status">
				<option value=""><?php esc_html_e( 'All Subtask Statuses', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( array( 'failed', 'completed', 'pending', 'skipped' ) as $sub_status ) : ?>
					<option value="<?php echo esc_attr( $sub_status ); ?>" <?php selected( $subtask_status, $sub_status ); ?>><?php echo esc_html( $sub_status ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="filter-by-job-id"><?php esc_html_e( 'Filter by Job ID', 'wpmmcc-ats' ); ?></label>
			<input
				type="text"
				name="job_id"
				id="filter-by-job-id"
				value="<?php echo esc_attr( $job_id ); ?>"
				placeholder="<?php echo esc_attr__( 'Job ID', 'wpmmcc-ats' ); ?>"
				style="width: 180px;"
			/>

			<?php submit_button( __( 'Filter', 'wpmmcc-ats' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Display status summary.
	 */
	public function display_status_summary() {
		// Get comprehensive statistics.
		$stats = wptsall_get_task_statistics();

		$overview       = $stats['overview'];
		$by_priority    = $stats['by_priority'];
		$by_template    = $stats['by_template'];
		$by_object_type = $stats['by_object_type'];
		$time_based     = $stats['time_based'];

		?>
		<div class="wptsall-task-statistics" style="margin: 20px 0; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
			<!-- Overview Section -->
			<div style="padding: 15px 20px; border-bottom: 1px solid #e5e5e5;">
				<h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Overall Statistics', 'wpmmcc-ats' ); ?></h3>
				<div style="display: flex; flex-wrap: wrap; gap: 10px;">
					<div style="padding: 8px 12px; background: #f0f0f1; border-radius: 4px;">
						<span style="color: #646970; font-size: 12px;"><?php esc_html_e( 'Total Tasks', 'wpmmcc-ats' ); ?>:</span>
						<strong style="margin-left: 5px; font-size: 16px; color: #1d2327;"><?php echo intval( $overview['total'] ); ?></strong>
					</div>
					<div style="padding: 8px 12px; background: #f0f0f1; border-radius: 4px;">
						<span style="color: #646970; font-size: 12px;"><?php esc_html_e( 'Success Rate', 'wpmmcc-ats' ); ?>:</span>
						<strong style="margin-left: 5px; font-size: 16px; color: #46b450;"><?php echo esc_html( $overview['success_rate'] ); ?>%</strong>
					</div>
				</div>
			</div>

			<!-- Status Section -->
			<div style="padding: 15px 20px; border-bottom: 1px solid #e5e5e5;">
				<h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Statistics by Status', 'wpmmcc-ats' ); ?></h3>
				<div style="display: flex; flex-wrap: wrap; gap: 8px;">
					<?php
					$status_colors = array(
						'pending'    => '#0073aa',
						'processing' => '#f0b849',
						'completed'  => '#46b450',
						'failed'     => '#dc3232',
						'retry'      => '#ff6900',
					);
					$status_labels = array(
						'pending'    => __( 'Pending', 'wpmmcc-ats' ),
						'processing' => __( 'Processing', 'wpmmcc-ats' ),
						'completed'  => __( 'Completed', 'wpmmcc-ats' ),
						'failed'     => __( 'Failed', 'wpmmcc-ats' ),
						'retry'      => __( 'Retry', 'wpmmcc-ats' ),
					);
					foreach ( $overview['by_status'] as $status => $count ) {
						$color = isset( $status_colors[ $status ] ) ? $status_colors[ $status ] : '#999';
						$label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;
						printf(
							'<span style="display:inline-block; padding: 6px 12px; border-radius: 4px; background: %s; color: #fff; font-size: 12px; font-weight: 600;">%s: %d</span>',
							esc_attr( $color ),
							esc_html( $label ),
							intval( $count )
						);
					}
					?>
				</div>
			</div>

			<!-- Priority & Time Section -->
			<div style="padding: 15px 20px; border-bottom: 1px solid #e5e5e5; display: flex; gap: 40px;">
				<!-- Priority -->
				<div style="flex: 1;">
					<h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Statistics by Priority', 'wpmmcc-ats' ); ?></h3>
					<div style="display: flex; gap: 8px;">
						<?php
						$priority_labels = array(
							'high'   => __( 'High', 'wpmmcc-ats' ),
							'normal' => __( 'Normal', 'wpmmcc-ats' ),
							'low'    => __( 'Low', 'wpmmcc-ats' ),
						);
						$priority_colors = array(
							'high'   => '#dc3232',
							'normal' => '#0073aa',
							'low'    => '#999',
						);
						foreach ( $by_priority as $priority => $count ) {
							$color = isset( $priority_colors[ $priority ] ) ? $priority_colors[ $priority ] : '#999';
							$label = isset( $priority_labels[ $priority ] ) ? $priority_labels[ $priority ] : $priority;
							printf(
								'<span style="display:inline-block; padding: 5px 10px; border: 1px solid %s; color: %s; border-radius: 3px; font-size: 12px;">%s: %d</span>',
								esc_attr( $color ),
								esc_attr( $color ),
								esc_html( $label ),
								intval( $count )
							);
						}
						?>
					</div>
				</div>

				<!-- Time-based -->
				<div style="flex: 1;">
					<h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Statistics by Time', 'wpmmcc-ats' ); ?></h3>
					<div style="display: flex; gap: 8px;">
						<span style="display:inline-block; padding: 5px 10px; border: 1px solid #0073aa; color: #0073aa; border-radius: 3px; font-size: 12px;">
							<?php esc_html_e( 'Today', 'wpmmcc-ats' ); ?>: <?php echo intval( $time_based['today'] ); ?>
						</span>
						<span style="display:inline-block; padding: 5px 10px; border: 1px solid #0073aa; color: #0073aa; border-radius: 3px; font-size: 12px;">
							<?php esc_html_e( 'This Week', 'wpmmcc-ats' ); ?>: <?php echo intval( $time_based['this_week'] ); ?>
						</span>
						<span style="display:inline-block; padding: 5px 10px; border: 1px solid #0073aa; color: #0073aa; border-radius: 3px; font-size: 12px;">
							<?php esc_html_e( 'This Month', 'wpmmcc-ats' ); ?>: <?php echo intval( $time_based['this_month'] ); ?>
						</span>
					</div>
				</div>
			</div>

			<!-- Template & Object Type Section -->
			<div style="padding: 15px 20px; display: flex; gap: 40px;">
				<!-- Top Templates -->
				<?php if ( ! empty( $by_template ) ) : ?>
				<div style="flex: 1;">
					<h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Top Templates (Top 5)', 'wpmmcc-ats' ); ?></h3>
					<div style="font-size: 12px; line-height: 1.8;">
						<?php
						$top_templates = array_slice( $by_template, 0, 5, true );
						foreach ( $top_templates as $template => $count ) {
							printf(
								'<div><code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;">%s</code>: <strong>%d</strong></div>',
								esc_html( $template ),
								intval( $count )
							);
						}
						?>
					</div>
				</div>
				<?php endif; ?>

				<!-- Object Types -->
				<?php if ( ! empty( $by_object_type ) ) : ?>
				<div style="flex: 1;">
					<h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( 'Object Type Distribution', 'wpmmcc-ats' ); ?></h3>
					<div style="display: flex; gap: 8px;">
						<?php
						$object_type_labels = array(
							'post_type'     => __( 'Post Type', 'wpmmcc-ats' ),
							'taxonomy'      => __( 'Taxonomy', 'wpmmcc-ats' ),
							'language_pack' => __( 'Language Pack', 'wpmmcc-ats' ),
						);
						foreach ( $by_object_type as $type => $count ) {
							$label = isset( $object_type_labels[ $type ] ) ? $object_type_labels[ $type ] : $type;
							printf(
								'<span style="display:inline-block; padding: 5px 10px; background: #f0f0f1; border-radius: 3px; font-size: 12px;">%s: <strong>%d</strong></span>',
								esc_html( $label ),
								intval( $count )
							);
						}
						?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
