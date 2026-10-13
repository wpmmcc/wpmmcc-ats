<?php
/**
 * Field Rules Adapters data-driven tests (lane E batch 3)
 *
 * Enumerates the built-in field-rules adapter set from
 * Plugin_Field_Rules_Registry (registry discovery, not a hardcoded
 * plugin-activation list) and validates every catalog-uncovered adapter
 * with per-adapter assertion groups:
 *
 * - registry enumeration: all 16 catalog adapter classes are registered
 *   and instantiable via direct class call (no third-party plugin
 *   activation required);
 * - field mapping: each adapter's declared rule set is pinned exactly
 *   (translate keys with content_format, sync/ignore keys), so an E2E
 *   matrix failure localizes to a RULE, not "some plugin chain broke";
 * - registry resolution: a typical plugin meta input is classified via
 *   Plugin_Field_Rules_Registry::match_meta_key() into
 *   translate / sync / uncovered buckets per adapter semantics,
 *   including wildcard pattern families (_lp_*, _Event*, *_og_* ...);
 * - sensitive exclusion: credential/api-key/identity/enum/geo fields
 *   (emails, prices, IDs, lat/lng, SKUs, robots flags) must never land
 *   in the translate bucket, and a registry-wide guard rejects any
 *   future rule that marks a sensitive-named key translatable.
 *
 * catalog: WP-CLASS-AIOSEO_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Classified_Listing_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Elementor_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Envira_Gallery_Lite_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Events_Manager_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Give_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-LearnPress_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-PropertyHive_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Rank_Math_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-SEOPress_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Site_Reviews_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Testimonial_Free_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-The_Events_Calendar_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-Tutor_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-WooCommerce_Field_Rules_Adapter
 * oracle: L1
 * catalog: WP-CLASS-WP_EasyCart_Field_Rules_Adapter
 * oracle: L1
 *
 * Run via: php tests/modules/wpmmcc-ats/unit/run.php --file=test-field-rules-adapters.php
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.3.0
 */

use WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry;

/**
 * Data-driven: one dataset entry per uncovered catalog adapter; every
 * test_* method loops the dataset, so each adapter gets >= 1 assertion
 * group per method and failures name the adapter + rule key.
 */
class Test_Field_Rules_Adapters extends SimpleTestCase {

	/**
	 * Probe key that no adapter declares or matches (uncovered bucket).
	 */
	const UNCOVERED_PROBE = 'uncovered_probe_meta_key_xyz';

	/**
	 * Dataset: per-adapter semantics, fully pinned from the adapter source
	 * (wpmmcc-ats/source/includes/models/adapters/).
	 *
	 * Entry shape:
	 * - class  : FQCN (direct-callable, no plugin activation needed)
	 * - slug   : expected get_plugin_slug()
	 * - translate : meta_key => expected rule subset (content_format, extras)
	 * - sync   : meta keys that must be copy-only (type=sync, translatable=false)
	 * - sync_enabled : pinned `enabled` flag for the adapter's sync rules. Two
	 *   deliberate conventions coexist: SEO adapters (AIOSEO, Rank_Math,
	 *   SEOPress, and the covered Yoast reference) declare sync rules
	 *   enabled=true so control meta (robots/canonical) is actively copied
	 *   to the shadow post; content adapters declare enabled=false so
	 *   commerce meta (price/SKU/stock) is excluded from the workflow
	 *   (handled by a separate workflow per their docblocks). The safety
	 *   property identical everywhere: type=sync + translatable=false.
	 * - patterns : glob pattern => expected matched type
	 * - probes : concrete meta key => expected match type via pattern resolution
	 * - sensitive : keys whose values are credential/identity/enum/geo-like and
	 *   must never be classified translate (declared sync keys or pattern-family
	 *   probes that resolve sync)
	 *
	 * @return array<string, array>
	 */
	private static function adapter_dataset(): array {
		$ns = 'WPTSALL\\Models\\Adapters\\';

		return array(
			'AIOSEO'            => array(
				'class'     => $ns . 'AIOSEO_Field_Rules_Adapter',
				'slug'      => 'all-in-one-seo-pack',
				'translate' => array(
					'_aioseo_title'             => array( 'content_format' => 'plain_text' ),
					'_aioseo_description'       => array( 'content_format' => 'plain_text' ),
					'_aioseo_og_title'          => array( 'content_format' => 'plain_text' ),
					'_aioseo_og_description'    => array( 'content_format' => 'plain_text' ),
					'_aioseo_twitter_title'     => array( 'content_format' => 'plain_text' ),
					'_aioseo_twitter_description' => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array( '_aioseo_canonical_url' ),
				'sync_enabled' => true,
				'patterns'  => array(
					'_aioseo_og_*'      => 'translate',
					'_aioseo_twitter_*' => 'translate',
				),
				'probes'    => array(
					'_aioseo_og_image_alt'      => 'translate',
					'_aioseo_twitter_image_alt' => 'translate',
				),
				'sensitive' => array( '_aioseo_canonical_url' ),
			),
			'Classified_Listing' => array(
				'class'     => $ns . 'Classified_Listing_Field_Rules_Adapter',
				'slug'      => 'classified-listing',
				'translate' => array(
					'_rtcl_address' => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_rtcl_price',
					'_rtcl_price_type',
					'_rtcl_listing_type',
					'_rtcl_latitude',
					'_rtcl_longitude',
					'_rtcl_phone',
					'_rtcl_email',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(
					'_rtcl_email',
					'_rtcl_phone',
					'_rtcl_latitude',
					'_rtcl_longitude',
					'_rtcl_price',
				),
			),
			'Elementor'         => array(
				'class'     => $ns . 'Elementor_Field_Rules_Adapter',
				'slug'      => 'elementor',
				'translate' => array(
					'_elementor_data' => array( 'content_format' => 'json_structured' ),
				),
				'sync'      => array(),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(),
			),
			'Envira_Gallery_Lite' => array(
				'class'     => $ns . 'Envira_Gallery_Lite_Field_Rules_Adapter',
				'slug'      => 'envira-gallery-lite',
				'translate' => array(
					'_envira_gallery_description' => array( 'content_format' => 'rich_html' ),
					'_envira_gallery_data'        => array( 'content_format' => 'json_structured' ),
				),
				'sync'      => array(),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(),
			),
			'Events_Manager'    => array(
				'class'     => $ns . 'Events_Manager_Field_Rules_Adapter',
				'slug'      => 'events-manager',
				'translate' => array(
					'_location_address' => array( 'content_format' => 'plain_text' ),
					'_location_town'    => array( 'content_format' => 'plain_text' ),
					'_location_state'   => array( 'content_format' => 'plain_text' ),
					'_location_country' => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_event_start_date',
					'_event_end_date',
					'_event_start_time',
					'_event_end_time',
					'_event_tickets_price',
					'_location_id',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(
					'_location_id',
					'_event_start_date',
					'_event_tickets_price',
				),
			),
			'Give'              => array(
				'class'     => $ns . 'Give_Field_Rules_Adapter',
				'slug'      => 'give',
				'translate' => array(
					'_give_form_content'    => array( 'content_format' => 'rich_html' ),
					'_give_checkout_label' => array( 'content_format' => 'plain_text' ),
					'_give_reveal_label'   => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_give_display_content',
					'_give_content_placement',
					'_give_set_price',
					'_give_custom_amount',
					'_give_goal_option',
					'_give_set_goal',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(
					'_give_set_price',
					'_give_custom_amount',
					'_give_set_goal',
				),
			),
			'LearnPress'        => array(
				'class'     => $ns . 'LearnPress_Field_Rules_Adapter',
				'slug'      => 'learnpress',
				'translate' => array(
					'_lp_duration' => array( 'content_format' => 'plain_text' ),
					'_lp_level'    => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_lp_students',
					'_lp_max_students',
					'_lp_retake_count',
					'_lp_course_result',
					'_lp_passing_condition',
					'_lp_price',
					'_lp_regular_price',
					'_lp_sale_price',
					'_lp_course_is_sale',
					'_lp_final_quiz',
					'_lp_preview',
					'_lp_mark',
					'_lp_type',
					'_lp_sample_data',
				),
				'sync_enabled' => false,
				'patterns'  => array(
					'_lp_*' => 'sync',
				),
				'probes'    => array(
					'_lp_custom_introduction' => 'sync',
					// Credential-shaped keys under the _lp_ family fall through
					// to the sync-default pattern: they are never translated.
					'_lp_api_key'              => 'sync',
					'_lp_secret_key'           => 'sync',
				),
				'sensitive' => array(
					'_lp_price',
					'_lp_api_key',
					'_lp_secret_key',
				),
			),
			'PropertyHive'      => array(
				'class'     => $ns . 'PropertyHive_Field_Rules_Adapter',
				'slug'      => 'propertyhive',
				'translate' => array(
					'_address_street' => array( 'content_format' => 'plain_text' ),
					'_address_two'    => array( 'content_format' => 'plain_text' ),
					'_address_city'   => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_price',
					'_price_qualifier',
					'_bedrooms',
					'_bathrooms',
					'_reception_rooms',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(
					'_price',
					'_bedrooms',
					'_bathrooms',
				),
			),
			'Rank_Math'         => array(
				'class'     => $ns . 'Rank_Math_Field_Rules_Adapter',
				'slug'      => 'seo-by-rank-math',
				'translate' => array(
					'_rank_math_title'                => array( 'content_format' => 'plain_text' ),
					'_rank_math_description'          => array( 'content_format' => 'plain_text' ),
					'_rank_math_focus_keyword'       => array( 'content_format' => 'plain_text' ),
					'_rank_math_facebook_title'       => array( 'content_format' => 'plain_text' ),
					'_rank_math_facebook_description' => array( 'content_format' => 'plain_text' ),
					'_rank_math_twitter_title'        => array( 'content_format' => 'plain_text' ),
					'_rank_math_twitter_description'  => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_rank_math_robots',
					'_rank_math_canonical_url',
				),
				'sync_enabled' => true,
				'patterns'  => array(
					'_rank_math_facebook_*' => 'translate',
					'_rank_math_twitter_*'  => 'translate',
				),
				'probes'    => array(
					'_rank_math_facebook_image_alt' => 'translate',
					'_rank_math_twitter_image_alt'  => 'translate',
				),
				'sensitive' => array(
					'_rank_math_robots',
					'_rank_math_canonical_url',
				),
			),
			'SEOPress'          => array(
				'class'     => $ns . 'SEOPress_Field_Rules_Adapter',
				'slug'      => 'wp-seopress',
				'translate' => array(
					'_seopress_titles_title'      => array( 'content_format' => 'plain_text' ),
					'_seopress_titles_desc'       => array( 'content_format' => 'plain_text' ),
					'_seopress_social_fb_title'   => array( 'content_format' => 'plain_text' ),
					'_seopress_social_fb_desc'    => array( 'content_format' => 'plain_text' ),
					'_seopress_social_twitter_title' => array( 'content_format' => 'plain_text' ),
					'_seopress_social_twitter_desc'   => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_seopress_robots_index',
					'_seopress_robots_follow',
					'_seopress_robots_canonical',
				),
				'sync_enabled' => true,
				'patterns'  => array(
					'_seopress_social_*' => 'translate',
				),
				'probes'    => array(
					'_seopress_social_fb_image_alt' => 'translate',
				),
				'sensitive' => array(
					'_seopress_robots_index',
					'_seopress_robots_follow',
					'_seopress_robots_canonical',
				),
			),
			'Site_Reviews'      => array(
				'class'     => $ns . 'Site_Reviews_Field_Rules_Adapter',
				'slug'      => 'site-reviews',
				'translate' => array(
					'_author' => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array(
					'_email',
					'_rating',
					'_submitted',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(
					'_email',
					'_rating',
				),
			),
			'Testimonial_Free'  => array(
				'class'     => $ns . 'Testimonial_Free_Field_Rules_Adapter',
				'slug'      => 'testimonial-free',
				'translate' => array(
					'_client_name'       => array( 'content_format' => 'plain_text' ),
					'_client_designation' => array( 'content_format' => 'plain_text' ),
					'_client_company'   => array( 'content_format' => 'plain_text' ),
				),
				'sync'      => array( '_rating' ),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array( '_rating' ),
			),
			'The_Events_Calendar' => array(
				'class'     => $ns . 'The_Events_Calendar_Field_Rules_Adapter',
				'slug'      => 'the-events-calendar',
				'translate' => array(),
				'sync'      => array(
					'_EventStartDate',
					'_EventEndDate',
					'_EventStartDateUTC',
					'_EventEndDateUTC',
					'_EventDuration',
					'_EventAllDay',
					'_EventTimezone',
					'_EventTimezoneAbbr',
					'_EventCost',
					'_EventCurrencySymbol',
					'_EventCurrencyPosition',
					'_EventURL',
					'_EventShowMap',
					'_EventShowMapLink',
					'_EventVenueID',
					'_EventOrganizerID',
					'_EventOrigin',
				),
				'sync_enabled' => false,
				'patterns'  => array(
					'_Event*' => 'sync',
				),
				'probes'    => array(
					'_EventNextUpcomingDate' => 'sync',
					// Token-shaped key under the _Event family resolves sync-only.
					'_EventAccessToken'       => 'sync',
				),
				'sensitive' => array(
					'_EventVenueID',
					'_EventOrganizerID',
					'_EventCost',
					'_EventAccessToken',
				),
			),
			'Tutor'             => array(
				'class'     => $ns . 'Tutor_Field_Rules_Adapter',
				'slug'      => 'tutor',
				'translate' => array(
					'_tutor_course_duration'       => array( 'content_format' => 'plain_text' ),
					'_tutor_course_benefits'       => array( 'content_format' => 'rich_html' ),
					'_tutor_course_requirements'  => array( 'content_format' => 'rich_html' ),
					'_tutor_course_target_audience' => array( 'content_format' => 'rich_html' ),
				),
				'sync'      => array(
					'_tutor_course_level',
					'_tutor_course_price',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array( '_tutor_course_price' ),
			),
			'WooCommerce'       => array(
				'class'     => $ns . 'WooCommerce_Field_Rules_Adapter',
				'slug'      => 'woocommerce',
				'translate' => array(
					'_product_attributes'    => array( 'content_format' => 'serialized_php' ),
					'_product_image_gallery' => array(
						'content_format' => 'media_ref',
						'task_type'      => 'image',
					),
					'_downloadable_files'   => array( 'content_format' => 'serialized_php' ),
					'_variation_description' => array( 'content_format' => 'rich_html' ),
				),
				'sync'      => array(
					'_regular_price',
					'_price',
					'_sale_price',
					'_sku',
					'_weight',
					'_stock',
					'_stock_status',
					'_manage_stock',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array(
					'_sku',
					'_price',
					'_stock',
				),
			),
			'WP_EasyCart'       => array(
				'class'     => $ns . 'WP_EasyCart_Field_Rules_Adapter',
				'slug'      => 'wp-easycart',
				'translate' => array(),
				'sync'      => array(
					'_ec_product_price',
					'_ec_product_sale_price',
					'_ec_product_sku',
					'_ec_product_stock',
					'_ec_product_weight',
				),
				'sync_enabled' => false,
				'patterns'  => array(),
				'probes'    => array(),
				'sensitive' => array( '_ec_product_sku' ),
			),
		);
	}

	/**
	 * Group 1: registry enumeration — all 16 catalog adapter classes are
	 * registered and directly instantiable without third-party plugin
	 * activation, and each declares its covered slug.
	 */
	public function test_registry_enumerates_all_sixteen_catalog_adapters() {
		$registered = Plugin_Field_Rules_Registry::get_adapters();
		$slugs      = Plugin_Field_Rules_Registry::get_covered_slugs();

		$this->assertGreaterThanOrEqual( 16, count( $registered ), 'Registry must enumerate at least the 16 catalog adapters' );

		foreach ( self::adapter_dataset() as $name => $spec ) {
			$this->assertContains(
				$spec['class'],
				$registered,
				"{$name}: adapter class must be enumerated by Plugin_Field_Rules_Registry::get_adapters()"
			);
			$this->assertTrue( class_exists( $spec['class'] ), "{$name}: adapter class must be loadable (no plugin activation dependency)" );
			$this->assertContains(
				$spec['slug'],
				$slugs,
				"{$name}: slug '{$spec['slug']}' must appear in get_covered_slugs()"
			);
		}
		echo '  (registry enumerated: ' . count( $registered ) . " registered adapters, 16 catalog adapters present)\n";
	}

	/**
	 * Group 2: per-adapter slug semantics (direct class call).
	 */
	public function test_adapter_slug_matches_semantics() {
		foreach ( self::adapter_dataset() as $name => $spec ) {
			$adapter = new $spec['class']();
			$this->assertInstanceOf( 'WPTSALL\Models\Adapters\Plugin_Field_Rules_Adapter', $adapter, "{$name}: must implement Plugin_Field_Rules_Adapter" );
			$this->assertSame( $spec['slug'], $adapter->get_plugin_slug(), "{$name}: get_plugin_slug() mismatch" );
		}
		echo "  (16 adapters: slug + interface contract validated via direct class call)\n";
	}

	/**
	 * Group 3: translate field mapping — each declared translate rule has
	 * the pinned content_format and full translate invariants.
	 */
	public function test_translate_rule_mapping() {
		foreach ( self::adapter_dataset() as $name => $spec ) {
			$adapter = new $spec['class']();
			$rules   = $adapter->get_field_rules();

			foreach ( $spec['translate'] as $meta_key => $expected_subset ) {
				$this->assertArrayHasKey( $meta_key, $rules, "{$name}: translate rule for '{$meta_key}' must be declared" );
				$rule = $rules[ $meta_key ];
				$this->assertSame( 'translate', $rule['type'], "{$name} rule '{$meta_key}': type must be translate" );
				$this->assertTrue( (bool) $rule['translatable'], "{$name} rule '{$meta_key}': translatable must be true" );
				$this->assertTrue( (bool) $rule['enabled'], "{$name} rule '{$meta_key}': enabled must be true" );
				$this->assertSame( 'one_way', $rule['direction'], "{$name} rule '{$meta_key}': direction must be one_way" );
				$this->assertSame( 'plugin', $rule['source'], "{$name} rule '{$meta_key}': source must be plugin" );
				$this->assertSame(
					$expected_subset['content_format'],
					$rule['content_format'],
					"{$name} rule '{$meta_key}': content_format mismatch"
				);
				foreach ( $expected_subset as $field => $value ) {
					$this->assertSame( $value, $rule[ $field ], "{$name} rule '{$meta_key}': field '{$field}' mismatch" );
				}
			}
		}
		echo "  (translate rule mapping pinned across 16 adapters)\n";
	}

	/**
	 * Group 4: sync/ignore field mapping — identity, numeric, enum, geo and
	 * contact fields are copy-only and never marked translatable.
	 */
	public function test_sync_rule_mapping() {
		foreach ( self::adapter_dataset() as $name => $spec ) {
			$adapter = new $spec['class']();
			$rules   = $adapter->get_field_rules();

			foreach ( $spec['sync'] as $meta_key ) {
				$this->assertArrayHasKey( $meta_key, $rules, "{$name}: sync rule for '{$meta_key}' must be declared" );
				$rule = $rules[ $meta_key ];
				$this->assertSame( 'sync', $rule['type'], "{$name} rule '{$meta_key}': type must be sync (not translate)" );
				$this->assertFalse( (bool) $rule['translatable'], "{$name} rule '{$meta_key}': translatable must be false" );
				// `enabled` follows the adapter's pinned convention (SEO
				// control meta enabled=true is actively copied; content
				// commerce meta enabled=false is excluded from the
				// workflow). See dataset sync_enabled docs + FINDING.
				$this->assertSame(
					$spec['sync_enabled'],
					$rule['enabled'],
					"{$name} rule '{$meta_key}': enabled must be " . var_export( $spec['sync_enabled'], true ) . ' (pinned convention)'
				);
				$this->assertSame( 'one_way', $rule['direction'], "{$name} rule '{$meta_key}': direction must be one_way" );
				$this->assertSame( 'plugin', $rule['source'], "{$name} rule '{$meta_key}': source must be plugin" );
			}
		}
		echo "  (sync/ignore rule mapping pinned across 16 adapters)\n";
	}

	/**
	 * Group 5: exact declared-key pinning — the adapter declares exactly the
	 * dataset translate + sync keys, no more, no less. A diff here localizes
	 * an E2E matrix failure to a single rule instead of a plugin chain.
	 */
	public function test_declared_field_set_is_pinned_exactly() {
		foreach ( self::adapter_dataset() as $name => $spec ) {
			$adapter    = new $spec['class']();
			$declared   = array_keys( $adapter->get_field_rules() );
			$expected   = array_merge( array_keys( $spec['translate'] ), $spec['sync'] );
			$extra      = array_diff( $declared, $expected );
			$missing    = array_diff( $expected, $declared );

			$this->assertSame( array(), $extra, "{$name}: adapter declares unexpected rules: " . implode( ', ', $extra ) );
			$this->assertSame( array(), $missing, "{$name}: adapter is missing pinned rules: " . implode( ', ', $missing ) );
			// No key may be classified both translate and sync.
			$overlap = array_intersect( array_keys( $spec['translate'] ), $spec['sync'] );
			$this->assertSame( array(), $overlap, "{$name}: translate/sync overlap: " . implode( ', ', $overlap ) );
		}
		echo "  (declared key sets exactly pinned for 16 adapters)\n";
	}

	/**
	 * Group 6: wildcard pattern rules — declared patterns and their
	 * resolution types are pinned per adapter.
	 */
	public function test_pattern_rules_declared_match_semantics() {
		foreach ( self::adapter_dataset() as $name => $spec ) {
			$adapter  = new $spec['class']();
			$patterns = $adapter->get_field_patterns();

			$declared = array_keys( $patterns );
			$expected = array_keys( $spec['patterns'] );
			$extra    = array_diff( $declared, $expected );
			$missing  = array_diff( $expected, $declared );
			$this->assertSame( array(), $extra, "{$name}: unexpected pattern rules: " . implode( ', ', $extra ) );
			$this->assertSame( array(), $missing, "{$name}: missing pattern rules: " . implode( ', ', $missing ) );

			foreach ( $spec['patterns'] as $pattern => $expected_type ) {
				$this->assertArrayHasKey( $pattern, $patterns, "{$name}: pattern '{$pattern}' must be declared" );
				$this->assertSame( $expected_type, $patterns[ $pattern ]['type'], "{$name} pattern '{$pattern}': type mismatch" );
				if ( 'translate' === $expected_type ) {
					$this->assertTrue( (bool) $patterns[ $pattern ]['translatable'], "{$name} pattern '{$pattern}': translatable must be true" );
				} else {
					$this->assertFalse( (bool) $patterns[ $pattern ]['translatable'], "{$name} pattern '{$pattern}': translatable must be false" );
				}
				$this->assertSame( 'one_way', $patterns[ $pattern ]['direction'], "{$name} pattern '{$pattern}': direction must be one_way" );
				$this->assertTrue( isset( $patterns[ $pattern ]['enabled'] ), "{$name} pattern '{$pattern}': enabled flag must be present" );
			}
		}
		echo "  (pattern rule families pinned for 16 adapters)\n";
	}

	/**
	 * Group 7: typical-input classification via the registry — a meta input
	 * shaped like the plugin's real post meta splits into exactly the
	 * expected translate / sync / uncovered buckets (explicit rules first,
	 * pattern families as fallback, everything else uncovered).
	 */
	public function test_typical_input_classification_via_registry() {
		foreach ( self::adapter_dataset() as $name => $spec ) {
			$input = array_merge(
				array_keys( $spec['translate'] ),
				$spec['sync'],
				array_keys( $spec['probes'] ),
				array( self::UNCOVERED_PROBE )
			);

			$expected_translate = array_merge( array_keys( $spec['translate'] ) );
			$expected_sync      = array_merge( $spec['sync'] );
			foreach ( $spec['probes'] as $probe => $expected_type ) {
				if ( 'translate' === $expected_type ) {
					$expected_translate[] = $probe;
				} else {
					$expected_sync[] = $probe;
				}
			}

			$got_translate = array();
			$got_sync      = array();
			$got_uncovered = array();
			foreach ( $input as $meta_key ) {
				$rule = Plugin_Field_Rules_Registry::match_meta_key( $meta_key );
				if ( null === $rule ) {
					$got_uncovered[] = $meta_key;
				} elseif ( 'translate' === $rule['type'] ) {
					$got_translate[] = $meta_key;
				} else {
					$got_sync[] = $meta_key;
				}
			}

			sort( $expected_translate );
			sort( $expected_sync );
			sort( $got_translate );
			sort( $got_sync );

			$this->assertSame( $expected_translate, $got_translate, "{$name}: translate bucket mismatch — extra: " . implode( ', ', array_diff( $got_translate, $expected_translate ) ) . '; missing: ' . implode( ', ', array_diff( $expected_translate, $got_translate ) ) );
			$this->assertSame( $expected_sync, $got_sync, "{$name}: sync/ignore bucket mismatch — extra: " . implode( ', ', array_diff( $got_sync, $expected_sync ) ) . '; missing: ' . implode( ', ', array_diff( $expected_sync, $got_sync ) ) );
			$this->assertSame( array( self::UNCOVERED_PROBE ), $got_uncovered, "{$name}: unrelated meta keys must stay uncovered (no adapter rule)" );
		}
		echo "  (typical meta input classified into translate/sync/uncovered buckets for 16 adapters)\n";
	}

	/**
	 * Group 8: sensitive-field exclusion — credential/api-key/identity/enum/
	 * geo/contact fields never end up in the translate set, per adapter and
	 * registry-wide (guards future rule additions too).
	 */
	public function test_sensitive_fields_never_translatable() {
		$sensitive_name_regex = '/(api[_-]?key|secret|pass(word)?|token|credential|license)/i';

		foreach ( self::adapter_dataset() as $name => $spec ) {
			$translate_keys = array_keys( $spec['translate'] );

			foreach ( $spec['sensitive'] as $meta_key ) {
				$this->assertNotContains(
					$meta_key,
					$translate_keys,
					"{$name}: sensitive field '{$meta_key}' must not be in the adapter translate set"
				);

				$rule = Plugin_Field_Rules_Registry::match_meta_key( $meta_key );
				$this->assertNotNull( $rule, "{$name}: sensitive field '{$meta_key}' should be covered by an explicit rule or pattern" );
				$this->assertNotSame( 'translate', $rule['type'], "{$name}: registry resolves sensitive field '{$meta_key}' as translate — credential/identity data would be sent for translation" );
				$this->assertFalse( (bool) $rule['translatable'], "{$name}: sensitive field '{$meta_key}' resolves translatable=true" );
			}
		}

		// Registry-wide guard: any rule (explicit or pattern) whose key name
		// looks like a credential must never be a translate rule, regardless
		// of which adapter (including future ones) declares it.
		foreach ( Plugin_Field_Rules_Registry::get_all_field_rules() as $meta_key => $rule ) {
			if ( preg_match( $sensitive_name_regex, (string) $meta_key ) ) {
				$this->assertNotSame( 'translate', $rule['type'], "Registry-wide guard: sensitive-named key '{$meta_key}' must not be a translate rule" );
				$this->assertFalse( (bool) $rule['translatable'], "Registry-wide guard: sensitive-named key '{$meta_key}' must not be translatable" );
			}
		}
		foreach ( Plugin_Field_Rules_Registry::get_all_field_patterns() as $pattern => $rule ) {
			if ( preg_match( $sensitive_name_regex, (string) $pattern ) ) {
				$this->assertNotSame( 'translate', $rule['type'], "Registry-wide guard: sensitive-named pattern '{$pattern}' must not be a translate pattern" );
				$this->assertFalse( (bool) $rule['translatable'], "Registry-wide guard: sensitive-named pattern '{$pattern}' must not be translatable" );
			}
		}
		echo "  (sensitive/credential/identity exclusion verified per adapter + registry-wide guard)\n";
	}
}
