<?php
/**
 * Site Reviews field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/site-reviews.json
 * (fields_source: database query, fields_verified: 2026-01-27).
 *
 * Site Reviews stores user-submitted review metadata. The reviewer's
 * display name (`_author`) is a language-localizable field \u2014 it is
 * human-readable free text and is the only field that should be
 * translated. The other meta keys are identity or numeric:
 *   - `_email` is a contact address (do not translate)
 *   - `_rating` is numeric 1\u20135 (do not translate)
 *   - `_submitted` is a boolean flag (do not translate)
 *
 * Phase 2D step 4: promote site-reviews from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Reviews_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'site-reviews';
	}

	public function get_field_rules(): array {
		return array(
			// --- Identity / numeric: sync only, do not translate ---
			'_email'    => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_rating'   => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_submitted' => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,

			// --- Translatable plain_text ---
			'_author'   => array(
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
