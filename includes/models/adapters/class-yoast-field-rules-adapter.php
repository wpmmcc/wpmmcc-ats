<?php
/**
 * Yoast SEO field rules adapter
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Yoast_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'wordpress-seo';
	}

	public function get_field_rules(): array {
		return array(
			'_yoast_wpseo_title'    => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_yoast_wpseo_metadesc' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_yoast_wpseo_focuskw'  => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_yoast_wpseo_opengraph-title' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_yoast_wpseo_opengraph-description' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			'_yoast_wpseo_bctitle' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable'   => true,
			),
			// Robots / canonical stay sync (not translated copy).
			'_yoast_wpseo_meta-robots-noindex'  => array(
				'type'         => 'sync',
				'direction'    => 'one_way',
				'enabled'      => true,
				'source'       => 'plugin',
				'translatable' => false,
			),
			'_yoast_wpseo_meta-robots-nofollow' => array(
				'type'         => 'sync',
				'direction'    => 'one_way',
				'enabled'      => true,
				'source'       => 'plugin',
				'translatable' => false,
			),
			'_yoast_wpseo_canonical' => array(
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
	 * Default: none. Concrete adapters can override to declare meta key
	 * families (e.g. `_tpro_*`).
	 *
	 * @return array<string, array>
	 */
	public function get_field_patterns(): array {
		// Yoast stores per-page Twitter / OpenGraph image meta with families.
		return array(
			'_yoast_wpseo_twitter_*' => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin_pattern',
				'translatable'   => true,
			),
			'_yoast_wpseo_opengraph-*' => array(
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
