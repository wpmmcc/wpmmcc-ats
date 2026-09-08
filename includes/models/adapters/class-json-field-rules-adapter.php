<?php
/**
 * JSON file-backed field rules adapter (hot-plug).
 *
 * Third-party plugins can ship `wptsall-field-rules.json` without PHP code.
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.1.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Json_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	private string $plugin_slug;
	/** @var array<string, array> */
	private array $field_rules;
	/** @var array<string, array> */
	private array $field_patterns;

	/**
	 * @param string               $plugin_slug Plugin slug.
	 * @param array<string, array> $field_rules   Explicit meta rules.
	 * @param array<string, array> $field_patterns Pattern rules.
	 */
	public function __construct( string $plugin_slug, array $field_rules, array $field_patterns = array() ) {
		$this->plugin_slug    = $plugin_slug;
		$this->field_rules    = $field_rules;
		$this->field_patterns = $field_patterns;
	}

	public function get_plugin_slug(): string {
		return $this->plugin_slug;
	}

	public function get_field_rules(): array {
		return $this->field_rules;
	}

	public function get_field_patterns(): array {
		return $this->field_patterns;
	}
}
