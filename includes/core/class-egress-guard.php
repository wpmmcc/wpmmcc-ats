<?php
/**
 * Provider egress / SSRF guards (ISS S7 / W6).
 *
 * @package WPTSALL\Core
 * @since 2.0.1
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Egress_Guard class.
 */
class Egress_Guard {

	/**
	 * Whether a URL is safe for server-side provider fetch.
	 *
	 * Blocks localhost, link-local, RFC1918, metadata IPs unless explicitly allowlisted.
	 *
	 * @param string $url Candidate URL.
	 * @return true|\WP_Error
	 */
	public static function assert_provider_url_allowed( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return new \WP_Error( 'egress_empty', 'Empty provider URL' );
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'egress_invalid', 'Invalid provider URL' );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'egress_scheme', 'Provider URL scheme not allowed' );
		}
		$host = strtolower( (string) $parts['host'] );
		$allow = (array) apply_filters( 'wptsall_provider_url_allowlist', array() );
		foreach ( $allow as $pattern ) {
			if ( self::host_matches( $host, (string) $pattern ) ) {
				return true;
			}
		}
		if ( self::is_blocked_host( $host ) ) {
			return new \WP_Error( 'egress_blocked_host', 'Provider host blocked (SSRF)' );
		}
		// Resolve DNS and re-check A/AAAA for rebinding to private ranges.
		$ips = self::resolve_ips( $host );
		foreach ( $ips as $ip ) {
			if ( self::is_blocked_ip( $ip ) ) {
				return new \WP_Error( 'egress_blocked_ip', 'Provider resolved to blocked IP', array( 'ip' => $ip ) );
			}
		}
		return true;
	}

	/**
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_blocked_host( $host ) {
		$host = strtolower( (string) $host );
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain', 'metadata.google.internal' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.local$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_blocked_ip( $host );
		}
		return false;
	}

	/**
	 * @param string $ip IP.
	 * @return bool
	 */
	public static function is_blocked_ip( $ip ) {
		$ip = (string) $ip;
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			if ( false === $long ) {
				return true;
			}
			$ranges = array(
				array( '0.0.0.0', '0.255.255.255' ),
				array( '10.0.0.0', '10.255.255.255' ),
				array( '127.0.0.0', '127.255.255.255' ),
				array( '169.254.0.0', '169.254.255.255' ),
				array( '172.16.0.0', '172.31.255.255' ),
				array( '192.168.0.0', '192.168.255.255' ),
			);
			foreach ( $ranges as $r ) {
				if ( $long >= ip2long( $r[0] ) && $long <= ip2long( $r[1] ) ) {
					return true;
				}
			}
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$lower = strtolower( $ip );
			if ( '::1' === $lower || 0 === strpos( $lower, 'fc' ) || 0 === strpos( $lower, 'fd' ) || 0 === strpos( $lower, 'fe80' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $host Host.
	 * @return array<int,string>
	 */
	private static function resolve_ips( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$a = @dns_get_record( $host, DNS_A ); // phpcs:ignore
			foreach ( (array) $a as $row ) {
				if ( ! empty( $row['ip'] ) ) {
					$ips[] = $row['ip'];
				}
			}
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore
			foreach ( (array) $aaaa as $row ) {
				if ( ! empty( $row['ipv6'] ) ) {
					$ips[] = $row['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * @param string $host    Host.
	 * @param string $pattern Exact or *.suffix.
	 * @return bool
	 */
	private static function host_matches( $host, $pattern ) {
		$pattern = strtolower( trim( $pattern ) );
		if ( '' === $pattern ) {
			return false;
		}
		if ( 0 === strpos( $pattern, '*.' ) ) {
			$suffix = substr( $pattern, 1 ); // .example.com
			return $host === substr( $pattern, 2 ) || substr( $host, -strlen( $suffix ) ) === $suffix;
		}
		return $host === $pattern;
	}

	/**
	 * Block private/metadata destinations for provider-tagged HTTP requests.
	 *
	 * @param false|array|\WP_Error $preempt Preempt.
	 * @param array                 $args    Args.
	 * @param string                $url     URL.
	 * @return false|array|\WP_Error
	 */
	public static function filter_pre_http_request( $preempt, $args, $url ) {
		$check = ! empty( $args['wptsall_egress_check'] );
		if ( ! $check ) {
			$patterns = (array) apply_filters( 'wptsall_provider_http_urls', array() );
			foreach ( $patterns as $pattern ) {
				if ( $pattern && false !== strpos( (string) $url, (string) $pattern ) ) {
					$check = true;
					break;
				}
			}
		}
		if ( ! $check ) {
			return $preempt;
		}
		$allowed = self::assert_provider_url_allowed( (string) $url );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return $preempt;
	}
}
