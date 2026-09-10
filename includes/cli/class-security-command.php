<?php
/**
 * WP-CLI Security commands (ISS S1 / S2).
 *
 * ## EXAMPLES
 *
 *     $ wp wptsall security rotate-route-secret
 *     $ wp wptsall security issue-device-token --label=lab
 *     $ wp wptsall security issue-pairing-pack --device-id=<client-device-id> --format=json
 *     $ wp wptsall security list-devices
 *
 * @package WPTSALL\CLI
 * @since 2.0.1
 */

namespace WPTSALL\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security_Command class.
 */
class Security_Command extends \WP_CLI_Command {

	/**
	 * Rotate the client REST route secret (≥256-bit, autoload=no).
	 *
	 * ## OPTIONS
	 *
	 * [--grace=<seconds>]
	 * : Ignored (pre-release hard cutover; no grace dual registration).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--porcelain]
	 * : Print only the new secret.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function rotate_route_secret( $args, $assoc_args ) {
		unset( $args );
		if ( ! function_exists( 'wptsall_rotate_client_route_secret' ) ) {
			\WP_CLI::error( 'wptsall_rotate_client_route_secret() unavailable.' );
		}
		$grace  = isset( $assoc_args['grace'] ) ? (int) $assoc_args['grace'] : 0;
		$result = wptsall_rotate_client_route_secret( $grace );
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( $result['new'] );
			return;
		}
		\WP_CLI::success(
			sprintf(
				'Route secret rotated (hard cutover; grace flag ignored=%d). New length=%d.',
				$grace,
				strlen( $result['new'] )
			)
		);
		\WP_CLI::log( 'New secret (store securely; do not commit): ' . $result['new'] );
	}

	/**
	 * Issue a device-scoped client token (plaintext once).
	 *
	 * ## OPTIONS
	 *
	 * [--device-id=<id>]
	 * : Stable device id (default: random UUID).
	 *
	 * [--label=<label>]
	 * : Human label.
	 *
	 * [--ttl=<seconds>]
	 * : Token lifetime in seconds (default: 3600).
	 *
	 * [--porcelain]
	 * : Print token only.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function issue_device_token( $args, $assoc_args ) {
		unset( $args );
		if ( ! function_exists( 'wptsall_issue_client_device_token' ) ) {
			\WP_CLI::error( 'Device token API unavailable.' );
		}
		$issued = wptsall_issue_client_device_token(
			(string) ( $assoc_args['device-id'] ?? '' ),
			(string) ( $assoc_args['label'] ?? '' ),
			isset( $assoc_args['ttl'] ) ? (int) $assoc_args['ttl'] : null
		);
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( $issued['token'] );
			return;
		}
		\WP_CLI::success( 'Device token issued (store securely; shown once).' );
		\WP_CLI::log( 'device_id: ' . $issued['device_id'] );
		\WP_CLI::log( 'token:     ' . $issued['token'] );
		\WP_CLI::log( 'expires_at: ' . $issued['expires_at'] . ' (in ' . $issued['expires_in'] . 's)' );
	}

	/**
	 * Issue a standalone site connection pack with a one-time pairing code.
	 *
	 * ## OPTIONS
	 *
	 * --device-id=<id>
	 * : Stable device id displayed by the standalone client.
	 *
	 * [--label=<label>]
	 * : Human label.
	 *
	 * [--ttl=<seconds>]
	 * : Pairing code lifetime in seconds (default: 300; capped at 3600).
	 *
	 * [--format=<format>]
	 * : Output format. Supports json or table.
	 * ---
	 * default: json
	 * ---
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function issue_pairing_pack( $args, $assoc_args ) {
		unset( $args );
		if ( ! function_exists( 'wptsall_create_site_connection_pack' ) ) {
			\WP_CLI::error( 'Site connection pack API unavailable.' );
		}
		$device_id = sanitize_key( (string) ( $assoc_args['device-id'] ?? '' ) );
		if ( '' === $device_id ) {
			\WP_CLI::error( 'Usage: wp wptsall security issue-pairing-pack --device-id=<client-device-id>' );
		}
		$pack = wptsall_create_site_connection_pack(
			$device_id,
			(string) ( $assoc_args['label'] ?? '' ),
			isset( $assoc_args['ttl'] ) ? (int) $assoc_args['ttl'] : null
		);
		$format = (string) ( $assoc_args['format'] ?? 'json' );
		if ( 'table' === $format ) {
			\WP_CLI\Utils\format_items(
				'table',
				array(
					array(
						'site_url'     => (string) ( $pack['site_url'] ?? '' ),
						'route_secret' => (string) ( $pack['route_secret'] ?? '' ),
						'device_id'    => (string) ( $pack['device_id'] ?? '' ),
						'pairing_code' => (string) ( $pack['pairing_code'] ?? '' ),
						'expires_at'   => (int) ( $pack['expires_at'] ?? 0 ),
					),
				),
				array( 'site_url', 'route_secret', 'device_id', 'pairing_code', 'expires_at' )
			);
			return;
		}
		\WP_CLI::line( wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Revoke a device-scoped client token.
	 *
	 * ## OPTIONS
	 *
	 * <device-id>
	 * : Device id to revoke.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function revoke_device_token( $args, $assoc_args ) {
		unset( $assoc_args );
		$device_id = sanitize_key( (string) ( $args[0] ?? '' ) );
		if ( '' === $device_id ) {
			\WP_CLI::error( 'Usage: wp wptsall security revoke-device-token <device-id>' );
		}
		if ( ! function_exists( 'wptsall_revoke_client_device_token' ) ) {
			\WP_CLI::error( 'Device token API unavailable.' );
		}
		if ( wptsall_revoke_client_device_token( $device_id ) ) {
			\WP_CLI::success( "Revoked device {$device_id}" );
		} else {
			\WP_CLI::warning( "No active device matched {$device_id}" );
		}
	}

	/**
	 * List device-scoped tokens (hashes only).
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function list_devices( $args, $assoc_args ) {
		unset( $args );
		$svc = function_exists( 'wptsall_client_token_service' ) ? wptsall_client_token_service() : null;
		if ( ! $svc || ! method_exists( $svc, 'list_devices' ) ) {
			\WP_CLI::error( 'Device list unavailable.' );
		}
		$rows = $svc->list_devices();
		\WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'device_id', 'label', 'created_at', 'expires_at', 'revoked_at', 'active' )
		);
	}
}
