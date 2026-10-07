<?php
/**
 * Manual Queue Admin Page (opus5 A-01, decision D-1(a))
 *
 * The consumer of the non-text manual review queue
 * (wp_wptsall_manual_queue): lists items routed by Write_Back_Dispatcher
 * when no adapter matched or apply failed, with status filters, per-item
 * Apply (re-dispatch through the write-back adapters) / Reject actions and
 * an expire-old cleanup. Before 2.1.4 the queue had three write points and
 * zero readers — enqueued items were invisible to administrators.
 *
 * @package WPTSALL
 * @since 2.1.4
 */

namespace WPTSALL\Tasks\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Tasks\Sync\Manual_Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual_Queue_Admin_Page class
 */
class Manual_Queue_Admin_Page {

	const PAGE_SLUG = 'wptsall-manual-queue';
	const CAP       = 'manage_wptsall_translations';

	const NONCE_APPLY  = 'wptsall_manual_queue_apply';
	const NONCE_REJECT = 'wptsall_manual_queue_reject';
	const NONCE_EXPIRE = 'wptsall_manual_queue_expire';

	/**
	 * Register the submenu page under the plugin menu.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Manual Queue', 'wpmmcc-ats' ),
			__( 'Manual Queue', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the queue review page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$counts = Manual_Queue::counts();

		$view_statuses = array(
			Manual_Queue::STATUS_PENDING,
			Manual_Queue::STATUS_REVIEWING,
			Manual_Queue::STATUS_APPLIED,
			Manual_Queue::STATUS_REJECTED,
			Manual_Queue::STATUS_EXPIRED,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Manual_Queue::STATUS_PENDING;
		if ( ! in_array( $status, $view_statuses, true ) ) {
			$status = Manual_Queue::STATUS_PENDING;
		}

		$items = Manual_Queue::get_items(
			array(
				'status' => $status,
				'limit'  => 50,
			)
		);

		Admin_Page_Helper::render_header( __( 'Manual Queue', 'wpmmcc-ats' ) );

		$this_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		// One-time notices redirected from the admin_post handlers.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mq_applied'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( /* translators: %s: queue item id */ __( 'Queue item #%s applied.', 'wpmmcc-ats' ), (string) absint( $_GET['mq_applied'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mq_error'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['mq_error'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mq_expired'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( /* translators: %s: row count */ __( '%s pending item(s) expired.', 'wpmmcc-ats' ), (string) absint( $_GET['mq_expired'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}

		$status_labels = array(
			Manual_Queue::STATUS_PENDING   => __( 'Pending', 'wpmmcc-ats' ),
			Manual_Queue::STATUS_REVIEWING => __( 'Reviewing', 'wpmmcc-ats' ),
			Manual_Queue::STATUS_APPLIED   => __( 'Applied', 'wpmmcc-ats' ),
			Manual_Queue::STATUS_REJECTED  => __( 'Rejected', 'wpmmcc-ats' ),
			Manual_Queue::STATUS_EXPIRED   => __( 'Expired', 'wpmmcc-ats' ),
		);

		// Status filter links with counts.
		echo '<ul class="subsubsub" style="margin-bottom:8px;">';
		$links = array();
		foreach ( $view_statuses as $s ) {
			$links[] = sprintf(
				'<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
				esc_url( add_query_arg( 'status', $s, $this_url ) ),
				$s === $status ? ' class="current"' : '',
				esc_html( $status_labels[ $s ] ),
				(int) $counts[ $s ]
			);
		}
		echo wp_kses( implode( ' | ', $links ), array( 'li' => array(), 'a' => array( 'href' => array(), 'class' => array() ), 'span' => array( 'class' => array() ) ) );
		echo '</ul>';

		// Expire-old cleanup form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0;">';
		wp_nonce_field( self::NONCE_EXPIRE );
		echo '<input type="hidden" name="action" value="wptsall_manual_queue_expire">';
		printf(
			'<label>%s <input type="number" name="days" value="30" min="1" max="3650" class="small-text"></label> ',
			esc_html__( 'Expire pending items older than', 'wpmmcc-ats' )
		);
		submit_button( __( 'Expire old', 'wpmmcc-ats' ), 'secondary', 'submit', false );
		echo '</form>';

		// Items table.
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width:70px;">' . esc_html__( 'ID', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Entity', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Relation', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Adapter', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wpmmcc-ats' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $items ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No queue items in this view.', 'wpmmcc-ats' ) . '</td></tr>';
		} else {
			foreach ( $items as $item ) {
				$id      = (int) $item['id'];
				$active  = in_array( (string) $item['status'], array( Manual_Queue::STATUS_PENDING, Manual_Queue::STATUS_REVIEWING ), true );
				$created = (string) ( $item['created_at'] ?? '' );

				echo '<tr>';
				printf( '<td>%d</td>', (int) $id );
				printf( '<td>%s</td>', esc_html( $created ) );
				printf( '<td>%s</td>', esc_html( (string) ( $item['entity_type'] ?? '' ) ) );
				printf( '<td>%d</td>', (int) ( $item['source_id'] ?? 0 ) );
				printf( '<td>%d</td>', (int) ( $item['relation_id'] ?? 0 ) );
				printf( '<td>%s</td>', esc_html( (string) ( $item['adapter_type'] ?? '' ) ) );
				printf( '<td>%s</td>', esc_html( (string) ( $item['reason'] ?? '' ) ) );

				echo '<td style="white-space:nowrap;">';
				if ( $active ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
					wp_nonce_field( self::NONCE_APPLY );
					echo '<input type="hidden" name="action" value="wptsall_manual_queue_apply">';
					printf( '<input type="hidden" name="queue_id" value="%d">', (int) $id );
					submit_button( __( 'Apply', 'wpmmcc-ats' ), 'primary small', 'submit', false );
					echo '</form> ';

					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
					wp_nonce_field( self::NONCE_REJECT );
					echo '<input type="hidden" name="action" value="wptsall_manual_queue_reject">';
					printf( '<input type="hidden" name="queue_id" value="%d">', (int) $id );
					submit_button( __( 'Reject', 'wpmmcc-ats' ), 'small', 'submit', false );
					echo '</form>';
				} else {
					echo '<span aria-hidden="true">&mdash;</span>';
				}
				echo '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		Admin_Page_Helper::render_footer();
	}

	/**
	 * admin_post handler: apply (re-dispatch) one queue item.
	 *
	 * @return void
	 */
	public static function handle_apply() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE_APPLY );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability checked above; nonce verified
		$id     = isset( $_POST['queue_id'] ) ? absint( wp_unslash( $_POST['queue_id'] ) ) : 0;
		$result = Manual_Queue::apply_item( $id );

		$args = array( 'page' => self::PAGE_SLUG );
		if ( is_wp_error( $result ) ) {
			$args['mq_error'] = $result->get_error_message();
		} else {
			$args['mq_applied'] = $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin_post handler: reject one queue item.
	 *
	 * @return void
	 */
	public static function handle_reject() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE_REJECT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability checked above; nonce verified
		$id   = isset( $_POST['queue_id'] ) ? absint( wp_unslash( $_POST['queue_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability checked above; nonce verified
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$row  = Manual_Queue::get_item( $id );

		$args = array( 'page' => self::PAGE_SLUG );
		if ( ! $row || ! in_array( (string) $row['status'], array( Manual_Queue::STATUS_PENDING, Manual_Queue::STATUS_REVIEWING ), true ) ) {
			$args['mq_error'] = __( 'Only pending or reviewing items can be rejected.', 'wpmmcc-ats' );
		} elseif ( ! Manual_Queue::update_status( $id, Manual_Queue::STATUS_REJECTED, $note ) ) {
			$args['mq_error'] = __( 'Could not update the queue item status.', 'wpmmcc-ats' );
		} else {
			$args['mq_rejected'] = $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin_post handler: expire pending items older than N days.
	 *
	 * @return void
	 */
	public static function handle_expire() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE_EXPIRE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability checked above; nonce verified
		$days = isset( $_POST['days'] ) ? max( 1, absint( wp_unslash( $_POST['days'] ) ) ) : 30;

		$expired = Manual_Queue::expire_old( $days );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'mq_expired' => $expired,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
