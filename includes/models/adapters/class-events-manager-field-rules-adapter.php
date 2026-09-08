<?php
/**
 * Events Manager field rules adapter
 *
 * Meta keys extracted from wp-plugin/tests/seeding/seed-data/events-manager.json
 * (fields_source: manual-definition, fields_verified: 2026-01-27).
 *
 * Events Manager stores event date/time, ticket price, and a reference
 * to a separate `location` CPT via `_location_id`. The location
 * human-readable fields (`_location_address`, `_location_town`,
 * `_location_state`, `_location_country`) are stored on the EVENT post
 * (denormalized snapshot of the linked location) and are translatable.
 *
 * Date/time values use ISO format (YYYY-MM-DD / HH:MM:SS) and ticket
 * price is numeric. These must NOT be translated; locale-specific
 * formatting is a presentation-layer concern managed by a separate
 * workflow.
 *
 * Phase 2D step 3: promote events-manager from L1 to L3.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Events_Manager_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'events-manager';
	}

	public function get_field_rules(): array {
		return array(
			// --- Date/time/price/id: sync only, do not translate ---
			'_event_start_date'    => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_event_end_date'      => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_event_start_time'    => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_event_end_time'      => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_event_tickets_price' => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,
			'_location_id'         => array(
				'type'           => 'sync',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => false,
				'source'         => 'plugin',
				'translatable' => false,
			)
,

			// --- Location: translatable plain_text ---
			'_location_address'    => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_location_town'       => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_location_state'      => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
				'direction'      => 'one_way',
				'enabled'        => true,
				'source'         => 'plugin',
				'translatable' => true,
			)
,
			'_location_country'    => array(
				'type'           => 'translate',
				'content_format' => 'plain_text',
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
