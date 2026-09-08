<?php
/**
 * Canonical content_format registry.
 *
 * Authority: libs/wptsall-contracts/content_formats.json
 * Keep PHP allowlists aligned with that file when adding formats.
 *
 * @package WPTSALL\Core
 * @since 2.1.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Format_Registry {

	/**
	 * Canonical format ids (content-formats-v1).
	 *
	 * @return array<int, string>
	 */
	public static function canonical(): array {
		return array(
			'plain_text',
			'rich_html',
			'serialized_php',
			'json_structured',
			'slug',
			'code',
			'media_ref',
		);
	}

	/**
	 * Alias → canonical.
	 *
	 * @return array<string, string>
	 */
	public static function aliases(): array {
		return array(
			'text'       => 'plain_text',
			'plain'      => 'plain_text',
			'html'       => 'rich_html',
			'json'       => 'json_structured',
			'serialized' => 'serialized_php',
		);
	}

	/**
	 * Formats accepted on translation-callback (includes aliases).
	 *
	 * @return array<int, string>
	 */
	public static function callback_allowed(): array {
		return array(
			'plain_text',
			'rich_html',
			'slug',
			'media_ref',
			'serialized_php',
			'json_structured',
			'html',
			'json',
			'serialized',
			'text',
			'plain',
		);
	}

	/**
	 * Formats explicitly rejected on callback / free-text MT.
	 *
	 * @return array<int, string>
	 */
	public static function callback_rejected(): array {
		return array( 'code' );
	}

	/**
	 * Normalize a raw format to canonical id when possible.
	 *
	 * @param string $raw Raw format.
	 * @return string
	 */
	public static function normalize( string $raw ): string {
		$key = strtolower( trim( $raw ) );
		if ( '' === $key ) {
			return 'plain_text';
		}
		$aliases = self::aliases();
		if ( isset( $aliases[ $key ] ) ) {
			return $aliases[ $key ];
		}
		return $key;
	}

	/**
	 * Whether a callback content_format is allowed.
	 *
	 * @param string $raw Raw format from client payload.
	 * @return bool
	 */
	public static function is_callback_allowed( string $raw ): bool {
		$key = strtolower( trim( $raw ) );
		if ( '' === $key ) {
			return true;
		}
		if ( in_array( $key, self::callback_rejected(), true ) ) {
			return false;
		}
		return in_array( $key, self::callback_allowed(), true );
	}

	/**
	 * Whether raw format is a known canonical id or alias.
	 *
	 * @param string $raw Raw format.
	 * @return bool
	 */
	public static function is_known( string $raw ): bool {
		$key = strtolower( trim( $raw ) );
		if ( '' === $key ) {
			return false;
		}
		if ( in_array( $key, self::canonical(), true ) ) {
			return true;
		}
		$aliases = self::aliases();
		return isset( $aliases[ $key ] );
	}

	/**
	 * Whether normalized format is a canonical id.
	 *
	 * @param string $raw Raw format.
	 * @return bool
	 */
	public static function is_canonical( string $raw ): bool {
		return in_array( self::normalize( $raw ), self::canonical(), true );
	}
}
