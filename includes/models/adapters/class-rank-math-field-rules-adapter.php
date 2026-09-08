<?php
/**
 * Rank Math SEO field rules adapter
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rank_Math_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'seo-by-rank-math';
	}

	public function get_field_rules(): array {
		return array(
			'_rank_math_title'       => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			),
			'_rank_math_description' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			),
			'_rank_math_focus_keyword' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_rank_math_facebook_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_rank_math_facebook_description' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_rank_math_twitter_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_rank_math_twitter_description' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_rank_math_robots' => array(
				'type'         => 'sync',
				'direction'    => 'one_way',
				'enabled'      => true,
				'source'       => 'plugin',
				'translatable' => false,
			),
			'_rank_math_canonical_url' => array(
				'type'         => 'sync',
				'direction'    => 'one_way',
				'enabled'      => true,
				'source'       => 'plugin',
				'translatable' => false,
			),
		);
	}

	/**
	 * Wildcard pattern rules (Phase 2E step 1).
	 *
	 * @return array<string, array>
	 */
	public function get_field_patterns(): array {
		return array(
			'_rank_math_facebook_*' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin_pattern',
				'translatable'   => true,
			),
			'_rank_math_twitter_*' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin_pattern',
				'translatable'   => true,
			),
		);
	}
}
