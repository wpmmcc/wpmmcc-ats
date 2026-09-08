<?php
/**
 * Adapter Manifest (P1)
 *
 * Declares per-content-plugin capabilities for independent upgrades:
 * min WP client protocol, content_formats used, and adapter identity.
 * Derived from Plugin_Field_Rules_Adapter rules so existing adapters
 * need no code change; optional get_manifest() overrides when present.
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.1.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Adapter_Manifest {

	/**
	 * Build manifests for all registered field-rules adapters.
	 *
	 * @return array<string, array{
	 *   plugin_slug: string,
	 *   adapter_class: string,
	 *   min_wp_client_protocol: int,
	 *   content_formats: array<int, string>,
	 *   content_formats_vocab: string
	 * }>
	 */
	public static function all(): array {
		$out = array();
		// JSON first; built-in PHP overwrites same plugin_slug (built-in wins).
		foreach ( Plugin_Field_Rules_Registry::get_json_adapters() as $adapter ) {
			$manifest = self::from_adapter( $adapter );
			$manifest['source'] = 'json_hotplug';
			$out[ $manifest['plugin_slug'] ] = $manifest;
		}
		foreach ( Plugin_Field_Rules_Registry::get_adapters() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}
			$adapter = new $adapter_class();
			if ( ! $adapter instanceof Plugin_Field_Rules_Adapter ) {
				continue;
			}
			$manifest = self::from_adapter( $adapter );
			$manifest['source'] = 'php_adapter';
			$out[ $manifest['plugin_slug'] ] = $manifest;
		}
		/**
		 * Filter adapter manifests after JSON + built-in discovery.
		 * Merge order: built-in PHP > JSON hot-plug for the same plugin_slug.
		 *
		 * @param array<string, array> $out Manifest map keyed by plugin_slug.
		 */
		return apply_filters( 'wptsall_adapter_manifests', $out );
	}

	/**
	 * Scan plugin directories for wptsall-field-rules.json hot-plug manifests.
	 *
	 * @return array<int, array{plugin_slug: string, field_rules: array, field_patterns: array}>
	 */
	public static function discover_json_manifest_files(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$discovered = array();
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			$plugin_dir = function_exists( 'wptsall_resolve_plugin_path' )
				? dirname( wptsall_resolve_plugin_path( $plugin_file, true ) )
				: dirname( WP_PLUGIN_DIR . '/' . $plugin_file );
			$json_path  = $plugin_dir . '/wptsall-field-rules.json';
			if ( ! is_readable( $json_path ) ) {
				continue;
			}
			$raw = file_get_contents( $json_path );
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}
			$doc = json_decode( $raw, true );
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$slug = isset( $doc['plugin_slug'] ) ? (string) $doc['plugin_slug'] : '';
			if ( '' === $slug ) {
				$slug = basename( $plugin_dir );
			}
			$rules    = isset( $doc['field_rules'] ) && is_array( $doc['field_rules'] ) ? $doc['field_rules'] : array();
			$patterns = isset( $doc['field_patterns'] ) && is_array( $doc['field_patterns'] ) ? $doc['field_patterns'] : array();
			$discovered[] = array(
				'plugin_slug'    => $slug,
				'field_rules'    => $rules,
				'field_patterns' => $patterns,
				'manifest_path'  => $json_path,
			);
		}
		return $discovered;
	}

	/**
	 * Build one adapter manifest.
	 *
	 * @param Plugin_Field_Rules_Adapter $adapter Adapter instance.
	 * @return array{
	 *   plugin_slug: string,
	 *   adapter_class: string,
	 *   min_wp_client_protocol: int,
	 *   content_formats: array<int, string>,
	 *   content_formats_vocab: string
	 * }
	 */
	public static function from_adapter( Plugin_Field_Rules_Adapter $adapter ): array {
		if ( method_exists( $adapter, 'get_manifest' ) ) {
			$custom = $adapter->get_manifest();
			if ( is_array( $custom ) && ! empty( $custom['plugin_slug'] ) ) {
				return self::normalize( $custom, get_class( $adapter ) );
			}
		}

		$formats = array();
		foreach ( $adapter->get_field_rules() as $rule ) {
			if ( ! empty( $rule['content_format'] ) && is_string( $rule['content_format'] ) ) {
				$formats[] = $rule['content_format'];
			}
		}
		foreach ( $adapter->get_field_patterns() as $rule ) {
			if ( ! empty( $rule['content_format'] ) && is_string( $rule['content_format'] ) ) {
				$formats[] = $rule['content_format'];
			}
		}
		$formats = array_values( array_unique( $formats ) );
		sort( $formats );

		return array(
			'plugin_slug'              => $adapter->get_plugin_slug(),
			'adapter_class'            => get_class( $adapter ),
			'min_wp_client_protocol'   => 2,
			'content_formats'          => $formats,
			'content_formats_vocab'    => 'content-formats-v1',
		);
	}

	/**
	 * @param array  $custom        Custom manifest.
	 * @param string $adapter_class FQCN.
	 * @return array{
	 *   plugin_slug: string,
	 *   adapter_class: string,
	 *   min_wp_client_protocol: int,
	 *   content_formats: array<int, string>,
	 *   content_formats_vocab: string
	 * }
	 */
	private static function normalize( array $custom, string $adapter_class ): array {
		$formats = array();
		if ( ! empty( $custom['content_formats'] ) && is_array( $custom['content_formats'] ) ) {
			foreach ( $custom['content_formats'] as $fmt ) {
				if ( is_string( $fmt ) && '' !== $fmt ) {
					$formats[] = $fmt;
				}
			}
		}
		$formats = array_values( array_unique( $formats ) );
		sort( $formats );

		return array(
			'plugin_slug'            => (string) $custom['plugin_slug'],
			'adapter_class'          => $adapter_class,
			'min_wp_client_protocol' => isset( $custom['min_wp_client_protocol'] )
				? (int) $custom['min_wp_client_protocol']
				: 2,
			'content_formats'        => $formats,
			'content_formats_vocab'  => ! empty( $custom['content_formats_vocab'] )
				? (string) $custom['content_formats_vocab']
				: 'content-formats-v1',
		);
	}
}
