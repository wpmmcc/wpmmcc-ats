<?php
/**
 * SEOPress field rules adapter
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.0.1
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEOPress_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'wp-seopress';
	}

	public function get_field_rules(): array {
		return array(
			'_seopress_titles_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_seopress_titles_desc' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_seopress_social_fb_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_seopress_social_fb_desc' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_seopress_social_twitter_title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_seopress_social_twitter_desc' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_seopress_robots_index' => array(
				'type'         => 'sync',
				'direction'    => 'one_way',
				'enabled'      => true,
				'source'       => 'plugin',
				'translatable' => false,
			),
			'_seopress_robots_follow' => array(
				'type'         => 'sync',
				'direction'    => 'one_way',
				'enabled'      => true,
				'source'       => 'plugin',
				'translatable' => false,
			),
			'_seopress_robots_canonical' => array(
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
			'_seopress_social_*' => array(
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
