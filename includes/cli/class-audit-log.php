<?php
/**
 * Audit Log Service (P6-3)
 *
 * Lightweight in-memory + option-backed audit log of translation actions:
 *   - Mapping create / delete
 *   - Manual translation, URL discovery
 *   - Field/taxonomy changes
 *   - TM record / delete
 *   - String translation changes
 *
 * Stored as a ring buffer in the `wptsall_audit_log` option (newest first).
 * Default cap: 500 entries. Older entries are dropped.
 *
 * Consumers:
 *   - wp wptsall audit list (CLI)
 *   - future admin UI
 *
 * @package WPTSALL\CLI
 * @since 1.5.0
 */

namespace WPTSALL\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit_Log {

	const OPTION_KEY = 'wptsall_audit_log';
	const CAP        = 500;

	/**
	 * Hook into the common actions that mutate translation state.
	 */
	public static function init() {
		add_action( 'wptsall_post_mapping_created', array( __CLASS__, 'on_post_mapping_created' ), 10, 3 );
		add_action( 'wptsall_term_mapping_created', array( __CLASS__, 'on_term_mapping_created' ), 10, 3 );
		add_action( 'wptsall_url_discovery_bulk', array( __CLASS__, 'on_url_discovery_bulk' ), 10, 3 );
		add_action( 'wptsall_tm_recorded', array( __CLASS__, 'on_tm_recorded' ), 10, 4 );
		add_action( 'wptsall_string_translated', array( __CLASS__, 'on_string_translated' ), 10, 3 );
	}

	public static function record( string $action, array $data = array() ): void {
		$entry = array(
			'time'    => gmdate( 'c' ),
			'user'    => function_exists( 'wp_get_current_user' ) ? ( wp_get_current_user()->user_login ?? 'system' ) : 'system',
			'action'  => $action,
			'data'    => $data,
		);
		$log   = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		if ( count( $log ) > self::CAP ) {
			$log = array_slice( $log, 0, self::CAP );
		}
		update_option( self::OPTION_KEY, $log, false );
	}

	public static function list( int $limit = 50, string $action_filter = '' ): array {
		$log  = get_option( self::OPTION_KEY, array() );
		$rows = array();
		foreach ( (array) $log as $e ) {
			if ( '' !== $action_filter && ( $e['action'] ?? '' ) !== $action_filter ) {
				continue;
			}
			$rows[] = $e;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}

	public static function clear(): int {
		$log = get_option( self::OPTION_KEY, array() );
		$n   = is_array( $log ) ? count( $log ) : 0;
		update_option( self::OPTION_KEY, array(), false );
		return $n;
	}

	/* ---------- action handlers ---------- */

	public static function on_post_mapping_created( $mapping_id, $payload, $result ): void {
		self::record( 'post_mapping_created', array(
			'mapping_id'   => (int) $mapping_id,
			'source_id'    => (int) ( $payload['source_post_id'] ?? 0 ),
			'target_id'    => (int) ( $payload['target_post_id'] ?? 0 ),
			'target_lang'  => (string) ( $payload['target_lang'] ?? '' ),
			'result'       => $result ? 'ok' : 'fail',
		) );
	}

	public static function on_term_mapping_created( $mapping_id, $payload, $result ): void {
		self::record( 'term_mapping_created', array(
			'mapping_id'   => (int) $mapping_id,
			'source_term'  => (int) ( $payload['source_term_id'] ?? 0 ),
			'target_term'  => (int) ( $payload['target_term_id'] ?? 0 ),
			'target_lang'  => (string) ( $payload['target_lang'] ?? '' ),
			'result'       => $result ? 'ok' : 'fail',
		) );
	}

	public static function on_url_discovery_bulk( $urls, $source_lang, $target_lang ): void {
		self::record( 'url_discovery_bulk', array(
			'count'       => is_array( $urls ) ? count( $urls ) : 0,
			'source_lang' => (string) $source_lang,
			'target_lang' => (string) $target_lang,
		) );
	}

	public static function on_tm_recorded( $source_text, $source_lang, $target_lang, $target_text ): void {
		self::record( 'tm_recorded', array(
			'source_lang' => (string) $source_lang,
			'target_lang' => (string) $target_lang,
			'len'         => strlen( (string) $source_text ),
		) );
	}

	public static function on_string_translated( $string_id, $lang, $text ): void {
		self::record( 'string_translated', array(
			'string_id' => (int) $string_id,
			'lang'      => (string) $lang,
			'len'       => strlen( (string) $text ),
		) );
	}
}
