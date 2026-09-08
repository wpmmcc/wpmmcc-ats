<?php
/**
 * Plugin Field Rules Registry
 *
 * Aggregates all registered Plugin_Field_Rules_Adapter implementations
 * and exposes a unified view of plugin field rules keyed by meta key.
 *
 * Adding a new SEO/Builder plugin: write a new adapter class implementing
 * Plugin_Field_Rules_Adapter, then add it to self::$adapters below
 * (or hook `wptsall_plugin_field_rules_adapters`).
 * Declare only canonical content_format ids from
 * `libs/wptsall-contracts/content_formats.json` (plain_text, rich_html,
 * json_structured, serialized_php, slug, media_ref, code).
 * No other core translation/client code needs to change.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin_Field_Rules_Registry {

	/**
	 * Default adapter set.
	 *
	 * Order is irrelevant; entries are merged by meta key.
	 * Filter `wptsall_plugin_field_rules_adapters` lets themes/plugins
	 * add or remove adapters at boot.
	 *
	 * @var array<int, class-string<Plugin_Field_Rules_Adapter>>
	 */
	private static $adapters = array(
		'WPTSALL\\Models\\Adapters\\Yoast_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Rank_Math_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\SEOPress_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\AIOSEO_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Elementor_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Tutor_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\LearnPress_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\The_Events_Calendar_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Give_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\WooCommerce_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Advanced_Custom_Fields_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Classified_Listing_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Events_Manager_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Site_Reviews_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\PropertyHive_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Testimonial_Free_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\Envira_Gallery_Lite_Field_Rules_Adapter',
		'WPTSALL\\Models\\Adapters\\WP_EasyCart_Field_Rules_Adapter',
	);

	/**
	 * Merge order (same meta key / pattern — highest wins):
	 * 1. Built-in PHP Field_Rules_Adapter
	 * 2. JSON hot-plug (plugin file or site option)
	 * Manual Model Editor fields are applied later at rule-generation time (ops override).
	 *
	 * @return array<string, array>
	 */
	public static function get_all_field_rules(): array {
		$merged = array();
		// JSON first, then PHP overwrites → built-in wins on conflict.
		foreach ( self::get_json_adapters() as $adapter ) {
			foreach ( $adapter->get_field_rules() as $meta_key => $rule ) {
				$merged[ $meta_key ] = $rule;
			}
		}
		foreach ( self::get_adapters() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}
			$adapter = new $adapter_class();
			if ( ! $adapter instanceof Plugin_Field_Rules_Adapter ) {
				continue;
			}
			foreach ( $adapter->get_field_rules() as $meta_key => $rule ) {
				$merged[ $meta_key ] = $rule;
			}
		}
		return $merged;
	}

	/**
	 * JSON hot-plug adapters from third-party plugin manifests + site option store.
	 * Invalid documents are skipped (fail-closed for that slug).
	 *
	 * @return array<int, Json_Field_Rules_Adapter>
	 */
	public static function get_json_adapters(): array {
		$adapters = array();
		foreach ( Field_Rules_Store::discover_all_documents() as $entry ) {
			if ( empty( $entry['validation']['ok'] ) ) {
				continue;
			}
			$adapters[] = new Json_Field_Rules_Adapter(
				$entry['plugin_slug'],
				$entry['field_rules'],
				$entry['field_patterns']
			);
		}
		/**
		 * Filter JSON-backed field rules adapters.
		 *
		 * @param array<int, Json_Field_Rules_Adapter> $adapters
		 */
		return apply_filters( 'wptsall_json_field_rules_adapters', $adapters );
	}

	/**
	 * Get all wildcard pattern rules from all registered adapters.
	 *
	 * Each pattern is keyed by glob (e.g. `_tpro_*`). Resolution to a
	 * concrete meta key is lazy: see match_meta_key().
	 * Merge order: JSON first, built-in PHP wins on conflict.
	 *
	 * @return array<string, array> Pattern => rule arrays.
	 */
	public static function get_all_field_patterns(): array {
		$merged = array();
		foreach ( self::get_json_adapters() as $adapter ) {
			foreach ( $adapter->get_field_patterns() as $pattern => $rule ) {
				$merged[ $pattern ] = $rule;
			}
		}
		foreach ( self::get_adapters() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}
			$adapter = new $adapter_class();
			if ( ! $adapter instanceof Plugin_Field_Rules_Adapter ) {
				continue;
			}
			foreach ( $adapter->get_field_patterns() as $pattern => $rule ) {
				$merged[ $pattern ] = $rule;
			}
		}
		return $merged;
	}

	/**
	 * Match a meta key against all explicit + pattern rules across adapters.
	 *
	 * Explicit rules win over pattern matches. Patterns use a single `*`
	 * wildcard (converted to `.*`) — the same convention as
	 * Data_Classifier::match_patterns().
	 *
	 * @param string $meta_key Concrete meta key to look up.
	 * @return array|null Rule array or null when no adapter covers the key.
	 */
	public static function match_meta_key( string $meta_key ): ?array {
		$explicit = self::get_all_field_rules();
		if ( isset( $explicit[ $meta_key ] ) ) {
			return $explicit[ $meta_key ];
		}

		foreach ( self::get_all_field_patterns() as $pattern => $rule ) {
			$regex = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			if ( preg_match( $regex, $meta_key ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Get the list of adapter class names, filterable.
	 *
	 * @return array<int, class-string<Plugin_Field_Rules_Adapter>>
	 */
	public static function get_adapters(): array {
		/**
		 * Filter the registered plugin field rules adapters.
		 *
		 * @param array $adapters Class names of adapters.
		 */
		return apply_filters( 'wptsall_plugin_field_rules_adapters', self::$adapters );
	}

	/**
	 * Get the plugin slugs covered by registered adapters.
	 *
	 * @return array<int, string>
	 */
	public static function get_covered_slugs(): array {
		$slugs = array();
		foreach ( self::get_adapters() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}
			$adapter = new $adapter_class();
			if ( $adapter instanceof Plugin_Field_Rules_Adapter ) {
				$slugs[] = $adapter->get_plugin_slug();
			}
		}
		return $slugs;
	}
}
