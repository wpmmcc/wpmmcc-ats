<?php
/**
 * Enrich translation_rules.field_capabilities for Stage-4 format gates.
 *
 * Ensures plugin adapter formats (e.g. serialized_php) and media_ref task_types
 * survive force rescan / skip-seed matrix lanes.
 *
 * Run: wp eval-file enrich-rule-format-gates.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E: Enrich rule format gates ===\n\n";

$rules_table = e2e_table( 'translation_rules' );
if ( ! e2e_table_exists( $rules_table ) ) {
	echo "ERROR: translation_rules missing\n";
	exit( 1 );
}

$plugin_defaults = array();
if ( class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
	$plugin_defaults = \WPTSALL\Models\Services\Translation_Rule_Service::get_plugin_field_defaults();
} elseif ( class_exists( '\\WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry' ) ) {
	$plugin_defaults = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::get_all_field_rules();
}

$gate_media = array(
	'_e2e_media_ref_image'    => array(
		'type'           => 'translate',
		'enabled'        => true,
		'content_format' => 'media_ref',
		'task_type'      => 'image',
		'source'         => 'e2e_gate',
	),
	'_e2e_media_ref_video'    => array(
		'type'           => 'translate',
		'enabled'        => true,
		'content_format' => 'media_ref',
		'task_type'      => 'video',
		'source'         => 'e2e_gate',
	),
	'_e2e_media_ref_audio'    => array(
		'type'           => 'translate',
		'enabled'        => true,
		'content_format' => 'media_ref',
		'task_type'      => 'audio',
		'source'         => 'e2e_gate',
	),
	'_e2e_media_ref_document' => array(
		'type'           => 'translate',
		'enabled'        => true,
		'content_format' => 'media_ref',
		'task_type'      => 'document',
		'source'         => 'e2e_gate',
	),
	'_e2e_serialized_php'     => array(
		'type'           => 'translate',
		'enabled'        => true,
		'content_format' => 'serialized_php',
		'source'         => 'e2e_gate',
	),
);

/**
 * Infer media task type from field key.
 *
 * @param string $field_key Field key.
 * @return string
 */
function e2e_infer_media_task_type( $field_key ) {
	$key = strtolower( (string) $field_key );
	if ( preg_match( '/(video|mp4|webm|movie|trailer)/', $key ) ) {
		return 'video';
	}
	if ( preg_match( '/(audio|mp3|podcast|sound|voice|wav)/', $key ) ) {
		return 'audio';
	}
	if ( preg_match( '/(pdf|document|docx?|xlsx?|file|download|attachment)/', $key ) ) {
		return 'document';
	}
	return 'image';
}

$rows = $wpdb->get_results(
	"SELECT id, object_name, field_capabilities FROM {$rules_table} WHERE is_active = 1",
	ARRAY_A
);

$updated = 0;
$gate_host_id = 0;

foreach ( (array) $rows as $row ) {
	$caps = json_decode( (string) ( $row['field_capabilities'] ?? '' ), true );
	if ( ! is_array( $caps ) ) {
		$caps = array();
	}
	$changed = false;

	foreach ( $plugin_defaults as $field_key => $def ) {
		if ( ! is_array( $def ) || '' === (string) $field_key ) {
			continue;
		}
		if ( ! isset( $caps[ $field_key ] ) || ! is_array( $caps[ $field_key ] ) ) {
			continue;
		}
		$want_format = sanitize_key( (string) ( $def['content_format'] ?? '' ) );
		$have_format = sanitize_key( (string) ( $caps[ $field_key ]['content_format'] ?? '' ) );
		if ( '' !== $want_format && $want_format !== $have_format ) {
			$caps[ $field_key ]['content_format'] = $want_format;
			$changed = true;
		}
		if ( ! empty( $def['task_type'] ) && empty( $caps[ $field_key ]['task_type'] ) ) {
			$caps[ $field_key ]['task_type'] = sanitize_key( (string) $def['task_type'] );
			$changed = true;
		}
		if ( 'media_ref' === ( $caps[ $field_key ]['content_format'] ?? '' ) && empty( $caps[ $field_key ]['task_type'] ) ) {
			$caps[ $field_key ]['task_type'] = e2e_infer_media_task_type( $field_key );
			$changed = true;
		}
		if ( isset( $def['type'] ) && 'translate' === $def['type'] && ( $caps[ $field_key ]['type'] ?? '' ) !== 'translate' ) {
			// Keep adapter translate preference for format gates.
			$caps[ $field_key ]['type'] = 'translate';
			$caps[ $field_key ]['enabled'] = true;
			$changed = true;
		}
	}

	foreach ( $caps as $field_key => $cap ) {
		if ( ! is_array( $cap ) ) {
			continue;
		}
		if ( 'media_ref' === ( $cap['content_format'] ?? '' ) && empty( $cap['task_type'] ) && 'translate' === ( $cap['type'] ?? '' ) ) {
			$caps[ $field_key ]['task_type'] = e2e_infer_media_task_type( (string) $field_key );
			$changed = true;
		}
	}

	if ( ! $gate_host_id && ( 'product' === ( $row['object_name'] ?? '' ) || 'post' === ( $row['object_name'] ?? '' ) ) ) {
		$gate_host_id = (int) $row['id'];
	}

	if ( $changed ) {
		$wpdb->update(
			$rules_table,
			array(
				'field_capabilities' => wp_json_encode( $caps ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row['id'] )
		);
		++$updated;
	}
}

if ( ! $gate_host_id && ! empty( $rows[0]['id'] ) ) {
	$gate_host_id = (int) $rows[0]['id'];
}

if ( $gate_host_id > 0 ) {
	$host = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, field_capabilities FROM {$rules_table} WHERE id = %d", $gate_host_id ),
		ARRAY_A
	);
	$caps = json_decode( (string) ( $host['field_capabilities'] ?? '' ), true );
	if ( ! is_array( $caps ) ) {
		$caps = array();
	}
	foreach ( $gate_media as $key => $def ) {
		$caps[ $key ] = $def;
	}
	$wpdb->update(
		$rules_table,
		array(
			'field_capabilities' => wp_json_encode( $caps ),
			'updated_at'         => current_time( 'mysql' ),
		),
		array( 'id' => $gate_host_id )
	);
	echo "  Injected gate media/serialized fields into rule #{$gate_host_id}\n";
}

echo "  Updated rules from adapters: {$updated}\n";
echo "Done.\n";
