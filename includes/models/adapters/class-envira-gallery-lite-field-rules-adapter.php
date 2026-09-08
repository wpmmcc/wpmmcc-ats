<?php
/**
 * Envira Gallery Lite field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/envira-gallery-lite.json
 * (fields_source: database query, fields_verified: 2026-01-27).
 *
 * Envira Gallery Lite stores gallery configuration in a serialized
 * PHP array under _envira_gallery_data (containing per-image title and
 * alt text). The gallery description (_envira_gallery_description) is
 * rich HTML.
 *
 * Phase 2D step 7: promote envira-gallery-lite from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Envira_Gallery_Lite_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'envira-gallery-lite';
	}

	public function get_field_rules(): array {
		return array(
			// --- Translatable rich HTML ---
			'_envira_gallery_description' => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,

			// --- Translatable serialized PHP (per-image title/alt) ---
			'_envira_gallery_data' => array(
				'type'           => 'translate',
				'content_format' => 'json_structured',
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
