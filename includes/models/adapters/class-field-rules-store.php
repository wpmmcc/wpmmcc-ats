<?php
/**
 * Site-level JSON field-rules store (Admin editable) + file discovery merge.
 *
 * Merge precedence for the same plugin_slug document source:
 *   option store overrides plugin-directory file (ops override without SSH).
 * Merge precedence for the same meta key across adapters:
 *   built-in PHP adapter > JSON (file/option) — see Plugin_Field_Rules_Registry.
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.1.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Field_Rules_Store {

	public const OPTION_KEY = 'wptsall_json_field_rules';

	/**
	 * All JSON documents: option entries win over file entries for same slug.
	 *
	 * @return array<int, array{
	 *   plugin_slug: string,
	 *   field_rules: array,
	 *   field_patterns: array,
	 *   source: string,
	 *   manifest_path?: string,
	 *   validation?: array
	 * }>
	 */
	public static function discover_all_documents(): array {
		$by_slug = array();

		foreach ( Adapter_Manifest::discover_json_manifest_files() as $entry ) {
			$slug = (string) $entry['plugin_slug'];
			$by_slug[ $slug ] = array(
				'plugin_slug'    => $slug,
				'field_rules'    => $entry['field_rules'],
				'field_patterns' => $entry['field_patterns'],
				'source'         => 'plugin_file',
				'manifest_path'  => $entry['manifest_path'] ?? '',
			);
		}

		foreach ( self::list_option_documents() as $slug => $doc ) {
			$by_slug[ $slug ] = array(
				'plugin_slug'    => $slug,
				'field_rules'    => $doc['field_rules'] ?? array(),
				'field_patterns' => $doc['field_patterns'] ?? array(),
				'source'         => 'option',
				'manifest_path'  => '',
			);
		}

		$out = array();
		foreach ( $by_slug as $entry ) {
			$validation = Field_Rules_Document_Validator::validate(
				array(
					'plugin_slug'            => $entry['plugin_slug'],
					'min_wp_client_protocol' => 2,
					'content_formats_vocab'  => 'content-formats-v1',
					'field_rules'            => $entry['field_rules'],
					'field_patterns'         => $entry['field_patterns'],
				)
			);
			$entry['validation'] = array(
				'ok'       => $validation['ok'],
				'errors'   => $validation['errors'],
				'warnings' => $validation['warnings'],
			);
			if ( $validation['ok'] && is_array( $validation['normalized'] ) ) {
				$entry['field_rules']    = $validation['normalized']['field_rules'];
				$entry['field_patterns'] = $validation['normalized']['field_patterns'];
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_option_documents(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $slug => $doc ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $doc ) ) {
				continue;
			}
			$out[ $slug ] = $doc;
		}
		return $out;
	}

	/**
	 * Save a validated document into the option store.
	 *
	 * @param array<string, mixed> $doc Document (will be validated).
	 * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, document?: array}
	 */
	public static function save_document( array $doc ): array {
		$validation = Field_Rules_Document_Validator::validate( $doc );
		if ( ! $validation['ok'] || ! is_array( $validation['normalized'] ) ) {
			return array(
				'ok'       => false,
				'errors'   => $validation['errors'],
				'warnings' => $validation['warnings'],
			);
		}
		$normalized = $validation['normalized'];
		$slug       = $normalized['plugin_slug'];
		$all        = self::list_option_documents();
		$all[ $slug ] = $normalized;
		update_option( self::OPTION_KEY, $all, false );
		return array(
			'ok'       => true,
			'errors'   => array(),
			'warnings' => $validation['warnings'],
			'document' => $normalized,
		);
	}

	/**
	 * @param string $plugin_slug Plugin slug.
	 * @return bool
	 */
	public static function delete_document( string $plugin_slug ): bool {
		$slug = sanitize_key( $plugin_slug );
		$all  = self::list_option_documents();
		if ( ! isset( $all[ $slug ] ) ) {
			return false;
		}
		unset( $all[ $slug ] );
		update_option( self::OPTION_KEY, $all, false );
		return true;
	}
}
