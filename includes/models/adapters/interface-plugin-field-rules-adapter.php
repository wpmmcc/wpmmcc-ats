<?php
/**
 * Plugin Field Rules Adapter interface
 *
 * Phase 2B step 2: replaces inline hardcoded rules with pluggable
 * adapters so plugin-specific knowledge lives in one place per plugin.
 *
 * Phase 2E step 1: extends contract with wildcard pattern rules so
 * adapters can declare families of meta keys (e.g. `_tpro_*`) without
 * enumerating each key. Backward compatible: existing adapters that
 * only implement get_field_rules() continue to work unchanged.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 * @updated 1.3.0 Phase 2E: wildcard pattern support
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Plugin_Field_Rules_Adapter {

	/**
	 * Plugin slug this adapter declares rules for.
	 *
	 * @return string
	 */
	public function get_plugin_slug(): string;

	/**
	 * Translation field rules keyed by meta key.
	 *
	 * Explicit per-key rules. Adapters that need to declare families of
	 * meta keys (e.g. all `_tpro_*`) should also implement
	 * get_field_patterns() so the Registry can resolve them lazily.
	 *
	 * @return array<string, array>
	 */
	public function get_field_rules(): array;

	/**
	 * Wildcard pattern field rules keyed by glob pattern.
	 *
	 * Optional. Use this for plugin meta keys that follow a naming
	 * family (e.g. `_tpro_*`, `_ec_product_*`) where enumerating each
	 * key is impractical. The Registry resolves patterns lazily when
	 * matching a concrete meta key.
	 *
	 * Pattern syntax: a single `*` matches any sequence of characters
	 * within a single segment. Conversion to regex follows the
	 * data-classifier convention (preg_quote + `.*`).
	 *
	 * Default: empty array (backward compatible).
	 *
	 * @return array<string, array>
	 */
	public function get_field_patterns(): array;
}
