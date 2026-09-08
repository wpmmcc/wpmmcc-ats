<?php
/**
 * Content Data Type ↔ Component Kind Mapping (A-006)
 *
 * Bridges the WP plugin's data_type (post / term / language_pack / custom_table)
 * and the Rust client-wpplugin's component kind (text / image / video_translation /
 * document_translation / openai_compatible).
 *
 * These are conceptually orthogonal:
 *   - data_type  = WHAT is being translated (post content, term name, gettext string, ...)
 *   - kind       = HOW it's packaged for the translation API (plain text, multimodal, etc.)
 *
 * A given data_type typically maps to one canonical kind (default `text`), but the
 * client can also explicitly request a non-default kind (e.g. translate a post as
 * `document_translation` to keep HTML structure intact).
 *
 * @package WPTSALL\Core
 * @since 1.6.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Data_Type_Map {

	/**
	 * Canonical data_type values accepted by the WP plugin REST API.
	 *
	 * Server-side canonical names. Aliases (post_type, taxonomy) are accepted
	 * by normalize_content_data_type() but normalized to these values before
	 * reaching storage or service layers.
	 */
	const DATA_TYPES = array( 'post', 'term', 'language_pack', 'custom_table' );

	/**
	 * Component kind values supported by the client-wpplugin runner.
	 *
	 * Source: client-wpplugin/source/src/web_ui/routes/components/local_registry.rs:353
	 *         ("text" | "image" | "video" | "audio" | "document" | "openai_compatible")
	 * Plus the underscored variants observed in client production code:
	 *   text_translation, video_translation, document_translation
	 */
	const KINDS = array(
		'text',
		'text_translation',
		'image',
		'video_translation',
		'document_translation',
		'audio_translation',
		'openai_compatible',
	);

	/**
	 * Default mapping: data_type → kind.
	 *
	 * Most data_types map to `text` because the default translation is plain
	 * text only. Callers that need richer kinds (e.g. multimodal post) can
	 * override by passing an explicit `kind` parameter.
	 *
	 * @return array<string,string> Map of data_type => default kind.
	 */
	public static function default_kind_for_data_type(): array {
		return array(
			'post'          => 'text',
			'term'          => 'text',
			'language_pack' => 'text',
			'custom_table'  => 'text',
		);
	}

	/**
	 * Look up the default kind for a given data_type.
	 *
	 * @param string $data_type Data type (post, term, language_pack, custom_table).
	 * @return string Default kind, or 'text' as ultimate fallback.
	 */
	public static function default_kind( string $data_type ): string {
		$map = self::default_kind_for_data_type();
		return $map[ $data_type ] ?? 'text';
	}

	/**
	 * Reverse lookup: which data_types can produce a given kind?
	 *
	 * Most kinds can be requested for any data_type, since the client decides
	 * how to package the payload. Only kinds that require special server-side
	 * support are restricted.
	 *
	 * @param string $kind Component kind.
	 * @return array<int,string> List of data_types that can use this kind.
	 */
	public static function data_types_for_kind( string $kind ): array {
		// Special kinds with limited applicability:
		$restrictions = array(
			'openai_compatible'  => array( 'post', 'term', 'language_pack', 'custom_table' ),
			'video_translation'  => array( 'post' ),  // requires post with media attachment
			'document_translation' => array( 'post' ),
			'image'              => array( 'post' ),
		);
		if ( isset( $restrictions[ $kind ] ) ) {
			return $restrictions[ $kind ];
		}
		// All other kinds (text, text_translation, audio_translation) work for any data_type.
		return self::DATA_TYPES;
	}

	/**
	 * Check if a kind is supported for a given data_type.
	 *
	 * @param string $data_type Data type.
	 * @param string $kind      Component kind.
	 * @return bool True if the kind is supported for this data_type.
	 */
	public static function is_kind_supported( string $data_type, string $kind ): bool {
		return in_array( $data_type, self::data_types_for_kind( $kind ), true );
	}

	/**
	 * Validate a (data_type, kind) pair and return the canonical values.
	 *
	 * Normalizes aliases (post_type → post, taxonomy → term) and validates
	 * that the kind is supported for the data_type. Returns null on
	 * invalid input.
	 *
	 * @param string $data_type Raw data_type from client.
	 * @param string $kind      Component kind from client (or empty for default).
	 * @return array|null { data_type: string, kind: string } or null if invalid.
	 */
	public static function validate( string $data_type, string $kind = '' ): ?array {
		// Normalize data_type aliases.
		$aliases = array(
			'post_type' => 'post',
			'taxonomy'  => 'term',
		);
		$normalized = $aliases[ $data_type ] ?? $data_type;
		if ( ! in_array( $normalized, self::DATA_TYPES, true ) ) {
			return null;
		}
		// Default kind if not specified.
		if ( '' === $kind ) {
			$kind = self::default_kind( $normalized );
		}
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return null;
		}
		if ( ! self::is_kind_supported( $normalized, $kind ) ) {
			return null;
		}
		return array(
			'data_type' => $normalized,
			'kind'      => $kind,
		);
	}

	/**
	 * Get a human-readable description of the data_type → kind mapping.
	 *
	 * For admin UI and documentation.
	 *
	 * @return array<string, array{default_kind: string, allowed_kinds: array}>
	 */
	public static function describe(): array {
		$out = array();
		foreach ( self::DATA_TYPES as $dt ) {
			$out[ $dt ] = array(
				'default_kind' => self::default_kind( $dt ),
				'allowed_kinds' => array_values(
					array_filter(
						self::KINDS,
						fn( $k ) => self::is_kind_supported( $dt, $k )
					)
				),
			);
		}
		return $out;
	}
}
