<?php
/**
 * Manual Queue REST Controller
 *
 * REST surface for the non-text manual review queue (opus5 A-01, decision
 * D-1(a)): list queued items with status counts, apply (re-dispatch through
 * the write-back adapters), reject, and expire-old cleanup. The queue
 * previously had three write points and zero readers; this controller is
 * the machine-readable consumer used by the admin page and tooling.
 *
 * @package WPTSALL\Tasks
 * @since 2.1.4
 */

namespace WPTSALL\Tasks\API;

use WPTSALL\Tasks\Sync\Manual_Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual_Queue_REST_Controller class
 */
class Manual_Queue_REST_Controller {

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Register routes
	 *
	 * @return void
	 */
	public function register_routes() {
		// Queue collection: list with counts.
		register_rest_route(
			$this->namespace,
			'/manual-queue',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status'      => array(
							'type'              => 'string',
							'enum'              => array( 'pending', 'reviewing', 'applied', 'rejected', 'expired' ),
							'default'           => 'pending',
							'sanitize_callback' => 'sanitize_key',
						),
						'relation_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'entity_type' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'limit'       => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'offset'      => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Expire-old cleanup (bulk, days threshold).
		register_rest_route(
			$this->namespace,
			'/manual-queue/expire',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'expire_old' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'type'              => 'integer',
							'default'           => 30,
							'minimum'           => 1,
							'maximum'           => 3650,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Apply (re-dispatch) a single item.
		register_rest_route(
			$this->namespace,
			'/manual-queue/(?P<id>[\d]+)/apply',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Reject a single item.
		register_rest_route(
			$this->namespace,
			'/manual-queue/(?P<id>[\d]+)/reject',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reject_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'   => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'note' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Check permission
	 *
	 * Distinguish between unauthenticated (401) and unauthorized (403).
	 *
	 * @param \WP_REST_Request|null $request Request.
	 * @return bool|\WP_Error
	 */
	public function check_permission( $request = null ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to perform this action', 'wpmmcc-ats' ),
				array( 'status' => 401 )
			);
		}

		if ( ! wptsall_user_can_manage_translations() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action', 'wpmmcc-ats' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * List queue items with decoded payloads and status counts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = array(
			// Null-coalesce defaults here as well as in the route schema:
			// direct (non-dispatched) calls skip the schema defaults, and a
			// missing limit would otherwise be cast to 0 and clamped to 1.
			'status'      => (string) ( $request->get_param( 'status' ) ?? Manual_Queue::STATUS_PENDING ),
			'relation_id' => (int) ( $request->get_param( 'relation_id' ) ?? 0 ),
			'entity_type' => (string) ( $request->get_param( 'entity_type' ) ?? '' ),
			'limit'       => (int) ( $request->get_param( 'limit' ) ?? 20 ),
			'offset'      => (int) ( $request->get_param( 'offset' ) ?? 0 ),
		);

		$rows = Manual_Queue::get_items( $args );

		$items = array();
		foreach ( $rows as $row ) {
			$row['payload'] = json_decode( (string) ( $row['payload'] ?? '' ), true );
			$items[]        = $row;
		}

		return rest_ensure_response(
			array(
				'items'  => $items,
				'counts' => Manual_Queue::counts(),
				'args'   => $args,
			)
		);
	}

	/**
	 * Apply (re-dispatch) one queued item.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply_item( $request ) {
		$result = Manual_Queue::apply_item( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Reject one queued item.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reject_item( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$note   = (string) $request->get_param( 'note' );
		$row    = Manual_Queue::get_item( $id );

		if ( ! $row ) {
			return new \WP_Error(
				'manual_queue_item_not_found',
				__( 'Manual queue item does not exist.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$status = (string) ( $row['status'] ?? '' );
		if ( ! in_array( $status, array( Manual_Queue::STATUS_PENDING, Manual_Queue::STATUS_REVIEWING ), true ) ) {
			return new \WP_Error(
				'manual_queue_item_not_reviewable',
				sprintf(
					/* translators: %s: the item's current status */
					__( 'Only pending or reviewing items can be rejected (current: %s).', 'wpmmcc-ats' ),
					$status
				),
				array( 'status' => 409 )
			);
		}

		if ( ! Manual_Queue::update_status( $id, Manual_Queue::STATUS_REJECTED, $note ) ) {
			return new \WP_Error(
				'manual_queue_reject_failed',
				__( 'Could not update the queue item status.', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'queue_id' => $id,
				'status'   => Manual_Queue::STATUS_REJECTED,
			)
		);
	}

	/**
	 * Expire pending items older than the days threshold.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function expire_old( $request ) {
		$expired = Manual_Queue::expire_old( (int) $request->get_param( 'days' ) );
		return rest_ensure_response(
			array(
				'success' => true,
				'expired' => $expired,
			)
		);
	}
}
