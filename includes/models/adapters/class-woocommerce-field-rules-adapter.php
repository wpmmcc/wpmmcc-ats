<?php
/**
 * WooCommerce field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/woocommerce-extra.json
 * (fields_source: v4-scanner, fields_verified: 2026-01-27).
 *
 * WooCommerce stores product commerce data (price/SKU/stock/weight) in
 * underscore-prefixed post meta. These values are numeric or enum-like
 * and must NOT be translated - currency conversion and SKU identity
 * are managed by a separate workflow.
 *
 * Translatable product data (attributes, gallery, downloadable files,
 * variation description) IS declared below so the scanner picks the
 * correct content_format instead of the generic plain_text default.
 *
 * Phase 2A: promote WooCommerce from hook-guide-only to L3 field rules.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooCommerce_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'woocommerce';
	}

	public function get_field_rules(): array {
		return array(
			// --- Commerce meta: sync only, do not translate ---
			'_regular_price'      => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_price'              => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_sale_price'         => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_sku'                => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_weight'             => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_stock'              => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_stock_status'       => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_manage_stock'       => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,

			// --- Translatable product meta ---
			'_product_attributes' => array(
				'type'           => 'translate',
				'content_format' => 'serialized_php',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_product_image_gallery' => array(
				'type'           => 'translate',
				'content_format' => 'media_ref',
				'task_type'      => 'image',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_downloadable_files' => array(
				'type'           => 'translate',
				'content_format' => 'serialized_php',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_variation_description' => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
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
