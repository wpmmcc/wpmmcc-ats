<?php
/**
 * LearnPress field rules adapter.
 *
 * Course/lesson/quiz options live in `_lp_*` post meta. Numeric/pricing/sync
 * keys stay copy-only; human-readable duration/level strings are translate.
 * Body content remains core `post_content` (no adapter needed).
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.3.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LearnPress_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'learnpress';
	}

	public function get_field_rules(): array {
		$sync = array(
			'type'           => 'sync',
			'content_format' => 'plain_text',
			'direction'      => 'one_way',
			'enabled'        => false,
			'source'         => 'plugin',
			'translatable'   => false,
		);
		$translate = array(
			'type'           => 'translate',
			'content_format' => 'plain_text',
			'direction'      => 'one_way',
			'enabled'        => true,
			'source'         => 'plugin',
			'translatable'   => true,
		);

		return array(
			'_lp_duration'           => $translate,
			'_lp_level'              => $translate,
			'_lp_students'           => $sync,
			'_lp_max_students'       => $sync,
			'_lp_retake_count'       => $sync,
			'_lp_course_result'      => $sync,
			'_lp_passing_condition'  => $sync,
			'_lp_price'              => $sync,
			'_lp_regular_price'      => $sync,
			'_lp_sale_price'         => $sync,
			'_lp_course_is_sale'     => $sync,
			'_lp_final_quiz'         => $sync,
			'_lp_preview'            => $sync,
			'_lp_mark'               => $sync,
			'_lp_type'               => $sync,
			'_lp_sample_data'        => $sync,
		);
	}

	/**
	 * @return array<string, array>
	 */
	public function get_field_patterns(): array {
		return array(
			'_lp_*' => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable'   => false,
			),
		);
	}
}
