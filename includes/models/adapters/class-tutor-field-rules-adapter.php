<?php
/**
 * Tutor LMS field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/tutor.json
 * (fields_source: v4-scanner, fields_verified: 2026-01-27).
 *
 * Tutor stores course options in private (underscore-prefixed) post meta.
 * These rules let the scanner recognize them as translatable fields
 * with the correct content_format, instead of falling back to the
 * generic plain_text default.
 *
 * Phase 2D: promote tutor from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tutor_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'tutor';
	}

	public function get_field_rules(): array {
		return array(
			'_tutor_course_level'         => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_tutor_course_duration'      => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_tutor_course_benefits'      => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_tutor_course_requirements'  => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_tutor_course_target_audience' => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_tutor_course_price'         => array(
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
