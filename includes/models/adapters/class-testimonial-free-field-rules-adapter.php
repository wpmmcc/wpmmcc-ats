<?php
/**
 * Testimonial Free field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/testimonial-free.json
 * (fields_source: database query, fields_verified: 2026-01-27).
 *
 * Testimonial Free stores user-submitted testimonial metadata. The
 * reviewer identity fields (_client_name, _client_designation,
 * _client_company) are human-readable free text and are translatable.
 * _rating is a numeric score (1-5) and is sync-only.
 *
 * Phase 2D step 6: promote testimonial-free from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Testimonial_Free_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'testimonial-free';
	}

	public function get_field_rules(): array {
		return array(
			// --- Identity / numeric: sync only, do not translate ---
			'_rating'   => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,

			// --- Translatable plain_text ---
			'_client_name'       => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_client_designation' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_client_company'   => array(
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
