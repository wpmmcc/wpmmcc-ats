<?php
/**
 * WP EasyCart field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/wp-easycart.json
 * (fields_source: database query, fields_verified: 2026-01-27).
 *
 * WP EasyCart stores product metadata. All five keys are commerce
 * sync-only (numeric prices, SKU, stock count, weight) and should
 * not be translated.
 *
 * Phase 2D step 8: promote wp-easycart from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_EasyCart_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'wp-easycart';
	}

	public function get_field_rules(): array {
		return array(
			// --- Commerce / numeric: sync only, do not translate ---
			'_ec_product_price'    => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_ec_product_sale_price' => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_ec_product_sku'      => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_ec_product_stock'    => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_ec_product_weight'   => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
		);
	}

	/**
	 * Wildcard pattern rules (Phase 2E step 1).
	 *
	 * Default: none. Concrete adapters can override to declare meta key
	 * families (e.g. `_tpro_*`).
	 *
	 * @return array<string, array>
	 */
	public function get_field_patterns(): array {
		return array();
	}
}
