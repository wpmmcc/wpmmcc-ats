<?php
/**
 * Field rules document validator (JSON hot-plug + Admin option store).
 *
 * Authority: libs/wptsall-contracts/field-rules.v1.schema.json
 * + content_formats.json
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.1.0
 */

namespace WPTSALL\Models\Adapters;

use WPTSALL\Core\Content_Format_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Field_Rules_Document_Validator {

	public const SCHEMA_ID = 'field-rules-v1';

	/**
	 * Allowed storage backends for hot-plug rules.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_storages(): array {
		return array(
			'post_meta',
			'term_meta',
			'option',
			'post_column',
			'term_column',
			'custom_table',
		);
	}

	/**
	 * Storages that JSON hot-plug can both discover and write via core sync.
	 *
	 * @return array<int, string>
	 */
	public static function write_supported_storages(): array {
		return array(
			'post_meta',
			'term_meta',
			'option',
			'post_column',
			'term_column',
		);
	}

	/**
	 * Validate a decoded field-rules document.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, normalized: array<string, mixed>|null}
	 */
	public static function validate( array $doc ): array {
		$errors   = array();
		$warnings = array();

		$slug = isset( $doc['plugin_slug'] ) ? sanitize_key( (string) $doc['plugin_slug'] ) : '';
		if ( '' === $slug ) {
			$errors[] = 'plugin_slug is required';
		}

		if ( isset( $doc['min_wp_client_protocol'] ) && (int) $doc['min_wp_client_protocol'] < 2 ) {
			$errors[] = 'min_wp_client_protocol must be >= 2';
		}

		if ( isset( $doc['content_formats_vocab'] )
			&& 'content-formats-v1' !== (string) $doc['content_formats_vocab'] ) {
			$errors[] = 'content_formats_vocab must be content-formats-v1';
		}

		$rules = isset( $doc['field_rules'] ) && is_array( $doc['field_rules'] ) ? $doc['field_rules'] : null;
		if ( null === $rules ) {
			$errors[] = 'field_rules object is required';
			$rules    = array();
		}

		$patterns = isset( $doc['field_patterns'] ) && is_array( $doc['field_patterns'] )
			? $doc['field_patterns']
			: array();

		$normalized_rules    = array();
		$normalized_patterns = array();

		foreach ( $rules as $key => $rule ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '_wptsall_' ) ) {
				$errors[] = "field_rules key '{$key}' uses reserved _wptsall_ prefix";
				continue;
			}
			$result = self::normalize_rule( $key, $rule, false );
			foreach ( $result['errors'] as $e ) {
				$errors[] = $e;
			}
			foreach ( $result['warnings'] as $w ) {
				$warnings[] = $w;
			}
			if ( null !== $result['rule'] ) {
				$normalized_rules[ $key ] = $result['rule'];
			}
		}

		foreach ( $patterns as $key => $rule ) {
			$key    = (string) $key;
			$result = self::normalize_rule( $key, $rule, true );
			foreach ( $result['errors'] as $e ) {
				$errors[] = $e;
			}
			foreach ( $result['warnings'] as $w ) {
				$warnings[] = $w;
			}
			if ( null !== $result['rule'] ) {
				$normalized_patterns[ $key ] = $result['rule'];
			}
		}

		if ( empty( $normalized_rules ) && empty( $normalized_patterns ) && empty( $errors ) ) {
			$warnings[] = 'document has no field_rules or field_patterns';
		}

		$ok = empty( $errors );
		return array(
			'ok'         => $ok,
			'errors'     => $errors,
			'warnings'   => $warnings,
			'normalized' => $ok
				? array(
					'plugin_slug'            => $slug,
					'min_wp_client_protocol' => isset( $doc['min_wp_client_protocol'] )
						? (int) $doc['min_wp_client_protocol']
						: 2,
					'content_formats_vocab'  => 'content-formats-v1',
					'field_rules'            => $normalized_rules,
					'field_patterns'         => $normalized_patterns,
					'notes'                  => isset( $doc['notes'] ) ? (string) $doc['notes'] : '',
				)
				: null,
		);
	}

	/**
	 * Validate raw JSON string.
	 *
	 * @param string $raw JSON text.
	 * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, normalized: array<string, mixed>|null}
	 */
	public static function validate_json( string $raw ): array {
		$doc = json_decode( $raw, true );
		if ( ! is_array( $doc ) ) {
			return array(
				'ok'         => false,
				'errors'     => array( 'invalid JSON: ' . ( json_last_error_msg() ?: 'decode failed' ) ),
				'warnings'   => array(),
				'normalized' => null,
			);
		}
		return self::validate( $doc );
	}

	/**
	 * @param string               $key     Field key or pattern.
	 * @param mixed                $rule    Rule payload.
	 * @param bool                 $pattern Whether this is a pattern entry.
	 * @return array{errors: array<int, string>, warnings: array<int, string>, rule: array<string, mixed>|null}
	 */
	private static function normalize_rule( string $key, $rule, bool $pattern ): array {
		$errors   = array();
		$warnings = array();
		$label    = $pattern ? "field_patterns['{$key}']" : "field_rules['{$key}']";

		if ( ! is_array( $rule ) ) {
			$errors[] = "{$label} must be an object";
			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
				'rule'     => null,
			);
		}

		$type = isset( $rule['type'] ) ? (string) $rule['type'] : '';
		if ( ! in_array( $type, array( 'translate', 'copy', 'skip', 'media' ), true ) ) {
			$errors[] = "{$label}.type must be translate|copy|skip|media";
		}

		$raw_format = isset( $rule['content_format'] ) ? (string) $rule['content_format'] : '';
		if ( '' === $raw_format ) {
			$errors[] = "{$label}.content_format is required";
			$format   = '';
		} elseif ( ! Content_Format_Registry::is_known( $raw_format ) ) {
			$errors[] = "{$label}.content_format '{$raw_format}' is unknown (see content-formats-v1)";
			$format   = Content_Format_Registry::normalize( $raw_format );
		} else {
			$format = Content_Format_Registry::normalize( $raw_format );
		}

		$storage = isset( $rule['storage'] ) ? (string) $rule['storage'] : '';
		if ( '' === $storage ) {
			$errors[] = "{$label}.storage is required (post_meta|term_meta|option|post_column|term_column|custom_table)";
		} elseif ( ! in_array( $storage, self::allowed_storages(), true ) ) {
			$errors[] = "{$label}.storage '{$storage}' is not allowed";
		} elseif ( 'custom_table' === $storage ) {
			$warnings[] = "{$label}: storage=custom_table is discover-only via JSON; writeback requires a PHP adapter";
			if ( empty( $rule['custom_table'] ) || ! is_array( $rule['custom_table'] ) ) {
				$errors[] = "{$label}.custom_table {table,object_id_column,value_column} is required when storage=custom_table";
			} else {
				foreach ( array( 'table', 'object_id_column', 'value_column' ) as $req ) {
					if ( empty( $rule['custom_table'][ $req ] ) ) {
						$errors[] = "{$label}.custom_table.{$req} is required";
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return array(
				'errors'   => $errors,
				'warnings' => $warnings,
				'rule'     => null,
			);
		}

		$out = array(
			'type'           => $type,
			'content_format' => $format,
			'storage'        => $storage,
			'direction'      => isset( $rule['direction'] ) ? (string) $rule['direction'] : 'one_way',
			'enabled'        => array_key_exists( 'enabled', $rule ) ? (bool) $rule['enabled'] : true,
			'translatable'   => array_key_exists( 'translatable', $rule )
				? (bool) $rule['translatable']
				: ( 'translate' === $type ),
			'source'         => isset( $rule['source'] ) ? (string) $rule['source'] : 'manual_json',
		);

		if ( 'custom_table' === $storage && is_array( $rule['custom_table'] ?? null ) ) {
			$out['custom_table'] = array(
				'table'             => (string) $rule['custom_table']['table'],
				'object_id_column'  => (string) $rule['custom_table']['object_id_column'],
				'value_column'      => (string) $rule['custom_table']['value_column'],
				'write_supported'   => false,
			);
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'rule'     => $out,
		);
	}
}
