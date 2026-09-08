<?php
/**
 * Elementor field rules adapter
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elementor_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'elementor';
	}

	public function get_field_rules(): array {
		return array(
			'_elementor_data' => array(
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
