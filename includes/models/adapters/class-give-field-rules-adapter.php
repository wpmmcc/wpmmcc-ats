<?php
/**
 * GiveWP field rules adapter.
 *
 * Give donation forms render their public introduction from `_give_form_content`
 * (not directly from `post_content`) when `_give_display_content` is enabled.
 * These rules make that manual multilingual surface explicit while keeping
 * numeric/display controls synchronized rather than translated.
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.1.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Give_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'give';
	}

	public function get_field_rules(): array {
		return array(
			'_give_form_content'      => array(
				'type'           => 'translate',
				'content_format' => 'rich_html',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_give_checkout_label'    => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_give_reveal_label'      => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_give_display_content'   => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable'   => false,
			),
			'_give_content_placement' => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable'   => false,
			),
			'_give_set_price'         => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable'   => false,
			),
			'_give_custom_amount'     => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable'   => false,
			),
			'_give_goal_option'       => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable'   => false,
			),
			'_give_set_goal'          => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable'   => false,
			),
		);
	}

	/**
	 * @return array<string, array>
	 */
	public function get_field_patterns(): array {
		return array();
	}
}
