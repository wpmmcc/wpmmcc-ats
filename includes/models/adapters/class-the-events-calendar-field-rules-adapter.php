<?php
/**
 * The Events Calendar field rules adapter.
 *
 * Event narrative lives in `post_content`; TEC stores schedule/venue/cost in
 * `_Event*` meta. IDs, dates, costs, and map flags sync; public EventURL can
 * stay as sync (absolute URLs are remapped by Metadata_Id_Remapper / link
 * filters, not field translation).
 *
 * @package WPTSALL\Models\Adapters
 * @since 2.3.0
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class The_Events_Calendar_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	public function get_plugin_slug(): string {
		return 'the-events-calendar';
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

		return array(
			'_EventStartDate'         => $sync,
			'_EventEndDate'           => $sync,
			'_EventStartDateUTC'      => $sync,
			'_EventEndDateUTC'        => $sync,
			'_EventDuration'          => $sync,
			'_EventAllDay'            => $sync,
			'_EventTimezone'          => $sync,
			'_EventTimezoneAbbr'      => $sync,
			'_EventCost'              => $sync,
			'_EventCurrencySymbol'    => $sync,
			'_EventCurrencyPosition'  => $sync,
			'_EventURL'               => $sync,
			'_EventShowMap'           => $sync,
			'_EventShowMapLink'       => $sync,
			'_EventVenueID'           => $sync,
			'_EventOrganizerID'       => $sync,
			'_EventOrigin'            => $sync,
		);
	}

	/**
	 * @return array<string, array>
	 */
	public function get_field_patterns(): array {
		return array(
			'_Event*' => array(
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
