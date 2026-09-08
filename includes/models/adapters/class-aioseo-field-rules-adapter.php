<?php
/**
 * All in One SEO (AIOSEO) field rules adapter
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.0.1
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOSEO_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'all-in-one-seo-pack';
	}

	public function get_field_rules(): array {
		return array(
			'_aioseo_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_aioseo_description' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_aioseo_og_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_aioseo_og_description' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_aioseo_twitter_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_aioseo_twitter_description' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_aioseo_canonical_url' => array(
				'type'         => 'sync',
				'direction'    => 'one_way',
				'enabled'      => true,
				'source'       => 'plugin',
				'translatable' => false,
			),
		);
	}

	public function get_field_patterns(): array {
		return array(
			'_aioseo_og_*' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin_pattern',
				'translatable'   => true,
			),
			'_aioseo_twitter_*' => array(
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
