<?php
/**
 * Classified Listing field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/classified-listing.json
 * (fields_source: v4-scanner, fields_verified: 2026-01-27).
 *
 * Classified Listing stores listing commerce/contact data
 * (price, lat/lng, phone, email, listing type) in underscore-prefixed
 * post meta. These values are numeric, geo-coordinate, or enum-like
 * and must NOT be translated \u2014 price currency conversion and contact
 * data identity are managed by a separate workflow.
 *
 * The address field (`_rtcl_address`) IS translatable as plain text
 * so the scanner picks the correct content_format instead of the
 * generic plain_text default.
 *
 * Phase 2D step 2: promote classified-listing from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Classified_Listing_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'classified-listing';
	}

	public function get_field_rules(): array {
		return array(
			// --- Commerce/contact meta: sync only, do not translate ---
			'_rtcl_price'        => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_rtcl_price_type'   => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_rtcl_listing_type' => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_rtcl_latitude'     => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_rtcl_longitude'    => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_rtcl_phone'        => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_rtcl_email'        => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,

			// --- Translatable meta ---
			'_rtcl_address'      => array(
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
