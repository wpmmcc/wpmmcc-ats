<?php
/**
 * PropertyHive field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/propertyhive.json
 * (fields_source: manual-definition, fields_verified: 2026-01-27).
 *
 * PropertyHive is a UK-focused real estate plugin. It stores property
 * data (price, room counts) in underscore-prefixed post meta, plus
 * human-readable address fields that are locale-aware.
 *
 * - Numeric/identifier fields (`_price`, `_bedrooms`, `_bathrooms`,
 *   `_reception_rooms`, `_price_qualifier`) MUST NOT be translated;
 *   currency conversion and room counts are identity.
 * - Address fields (`_address_street`, `_address_two`, `_address_city`)
 *   ARE translatable as plain text \u2014 they're the human-readable
 *   address label, distinct from the geocoded lat/lng.
 *
 * Phase 2D step 5: promote propertyhive from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PropertyHive_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'propertyhive';
	}

	public function get_field_rules(): array {
		return array(
			// --- Numeric / enum: sync only, do not translate ---
			'_price'             => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_price_qualifier'   => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_bedrooms'          => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_bathrooms'         => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_reception_rooms'   => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,

			// --- Address: translatable plain_text ---
			'_address_street'    => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_address_two'       => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_address_city'      => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
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
