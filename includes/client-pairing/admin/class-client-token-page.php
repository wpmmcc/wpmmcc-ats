<?php
/**
 * Client API authorization admin helpers.
 *
 * Registers the admin-post handler for issuing device tokens; the live
 * Authorization tab is rendered by Tasks_Page::render_authorization_tab().
 *
 * @package WPTSALL\Client_Pairing\Admin
 * @since 1.0.0
 * @updated 2.1.2 Renamed from historical License_Page (enterprise module).
 */

namespace WPTSALL\Client_Pairing\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client API authorization page helpers (historical class name Client_Token_Page).
 * Manages Client API device auth — not commercial licensing.
 *
 * @since 1.0.0
 */
class Client_Token_Page {

	/**
	 * Register admin handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_wptsall_regenerate_client_token', array( __CLASS__, 'handle_regenerate_token' ) );
	}

	/**
	 * Render authorization page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! wptsall_user_can_manage_security() ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Client API Authorization', 'wpmmcc-ats' ); ?></h1>
			<?php self::render_authorization_content(); ?>
		</div>
		<?php
	}

	/**
	 * Render authorization content as a tab (without wrap/h1).
	 *
	 * @return void
	 */
	public static function render_as_tab() {
		if ( ! wptsall_user_can_manage_security() ) {
			return;
		}

		self::render_authorization_content();
	}

	/**
	 * Shared authorization UI: free-features notice + client API token management.
	 *
	 * @return void
	 */
	private static function render_authorization_content() {
		$token_info   = wptsall_client_token_service()->get_client_token_snapshot();
		$reveal_token = self::consume_reveal_token();

		self::render_notice();

		echo '<p>' . esc_html__( 'All features are free. No license key required.', 'wpmmcc-ats' ) . '</p>';
		echo '<p>' . esc_html__( 'Device-scoped API tokens authenticate the standalone translation client (Protocol v2). Prefer WP-CLI: wp wptsall security issue-device-token.', 'wpmmcc-ats' ) . '</p>';

		echo '<div class="card" style="max-width: 920px; padding: 16px; margin-top: 16px;">';
		echo '<h2>' . esc_html__( 'Device API Token', 'wpmmcc-ats' ) . '</h2>';
		echo '<p>' . esc_html__( 'Issuing creates a new device-scoped token (shown once). Old install-level shared tokens are not used.', 'wpmmcc-ats' ) . '</p>';

		if ( ! empty( $reveal_token ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo '<strong>' . esc_html__( 'Copy the new device token immediately (shown only once)', 'wpmmcc-ats' ) . '</strong><br />';
			echo '<code style="font-size: 13px;">' . esc_html( $reveal_token ) . '</code>';
			echo '</p></div>';
		} else {
			$active = (int) ( $token_info['active_devices'] ?? 0 );
			echo '<p class="description">';
			printf(
				/* translators: %s: active device count */
				esc_html__( 'Active device tokens: %s', 'wpmmcc-ats' ),
				esc_html( number_format_i18n( $active ) )
			);
			echo '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptsall_regenerate_client_token' );
		echo '<input type="hidden" name="action" value="wptsall_regenerate_client_token" />';
		submit_button( __( 'Issue device token', 'wpmmcc-ats' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handle issue device token action.
	 *
	 * @return void
	 */
	public static function handle_regenerate_token() {
		if ( ! wptsall_user_can_manage_security() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_regenerate_client_token' );

		$issued = wptsall_issue_client_device_token( 'admin-' . get_current_user_id(), 'admin-ui' );
		self::save_reveal_token( (string) ( $issued['token'] ?? '' ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => 'wptsall-tasks',
					'tab'                   => 'authorization',
					'wptsall_token_rotated' => 1,
					'device_id'             => rawurlencode( (string) ( $issued['device_id'] ?? '' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render status notices.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin notice query param.
		$saved = ! empty( $_GET['wptsall_license_saved'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin notice query param.
		$rotated = ! empty( $_GET['wptsall_token_rotated'] );

		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Authorization settings saved.', 'wpmmcc-ats' ) . '</p></div>';
		}

		if ( $rotated ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'API Token has been regenerated. Please update the translation service configuration accordingly.', 'wpmmcc-ats' ) . '</p></div>';
		}
	}

	/**
	 * Format timestamp for display.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		if ( $timestamp <= 0 ) {
			return '-';
		}
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Save one-time reveal token for current admin user.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private static function save_reveal_token( $token ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || '' === (string) $token ) {
			return;
		}
		set_transient( self::get_reveal_transient_key( $user_id ), (string) $token, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Consume one-time reveal token.
	 *
	 * @return string
	 */
	private static function consume_reveal_token() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}

		$key   = self::get_reveal_transient_key( $user_id );
		$token = (string) get_transient( $key );
		if ( '' !== $token ) {
			delete_transient( $key );
		}

		return $token;
	}

	/**
	 * Reveal transient key.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function get_reveal_transient_key( $user_id ) {
		return 'wptsall_client_token_reveal_' . absint( $user_id );
	}
}

