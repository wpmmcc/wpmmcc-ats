<?php
/**
 * Site Verification REST Controller
 *
 * Endpoints for the domain verification challenge-response protocol.
 *
 * @package WPTSALL\Tasks\API
 * @since 1.1.0
 */

namespace WPTSALL\Tasks\API;

use WPTSALL\Core\Site_Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site REST controller for verification endpoints.
 */
class Site_Rest_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wptsall/v2';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/site/generate-verification',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_verification' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/site/verify',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'verify_site' ),
				// Public challenge endpoint: no cookie auth, but requires a live one-time nonce.
				'permission_callback' => array( $this, 'check_verify_permission' ),
				'args'                => array(
					'nonce' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_verify_nonce_arg' ),
					),
				),
			)
		);
	}

	/**
	 * Check admin permission.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function check_admin_permission( $request ) {
		if ( ! wptsall_user_can_manage_translations() ) {
			return new \WP_Error(
				'rest_forbidden',
				'Only administrators can generate verification URLs.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Validate /site/verify nonce query arg format (UUID).
	 *
	 * @param mixed            $value   Param value.
	 * @param \WP_REST_Request $request Request.
	 * @param string           $param   Param name.
	 * @return bool|\WP_Error
	 */
	public function validate_verify_nonce_arg( $value, $request, $param ) {
		$nonce = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $nonce || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $nonce ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				'Invalid verification nonce format.',
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Permission for public site verification: nonce must exist and not be expired.
	 *
	 * Does not consume the nonce (consumption happens in the callback).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function check_verify_permission( $request ) {
		$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
		if ( '' === $nonce ) {
			return new \WP_Error(
				'rest_forbidden',
				'Verification nonce is required.',
				array( 'status' => 401 )
			);
		}

		$data = get_transient( Site_Verification::TRANSIENT_PREFIX . $nonce );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Verification nonce expired or not found.',
				array( 'status' => 403 )
			);
		}

		if ( isset( $data['expires_at'] ) && time() > (int) $data['expires_at'] ) {
			return new \WP_Error(
				'rest_forbidden',
				'Verification nonce expired.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Generate a verification URL for domain binding.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_verification( $request ) {
		$result = Site_Verification::generate_verification_nonce();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Verify site ownership (accessed by server).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify_site( $request ) {
		$nonce = sanitize_text_field( $request->get_param( 'nonce' ) );
		if ( empty( $nonce ) ) {
			return new \WP_Error( 'missing_nonce', 'Nonce parameter is required', array( 'status' => 400 ) );
		}

		$result = Site_Verification::consume_nonce_and_sign( $nonce );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}
}
