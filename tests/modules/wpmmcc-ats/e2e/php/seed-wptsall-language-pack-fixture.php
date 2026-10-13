<?php
/**
 * Seed a tiny WPTSALL language pack fixture without running a full scanner.
 *
 * Usage:
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/php/seed-wptsall-language-pack-fixture.php [relation_id=...] [fixture_marker=...] [entries=4]
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$relation_id    = 0;
$fixture_marker = 'wptsall-language-pack-small';
$entry_limit    = 4;

if ( ! empty( $args ) && is_array( $args ) ) {
	foreach ( $args as $raw_arg ) {
		$arg = trim( (string) $raw_arg );
		if ( '' === $arg ) {
			continue;
		}
		if ( 0 === strpos( $arg, 'relation_id=' ) ) {
			$relation_id = max( 0, (int) substr( $arg, strlen( 'relation_id=' ) ) );
			continue;
		}
		if ( 0 === strpos( $arg, 'fixture_marker=' ) ) {
			$fixture_marker = sanitize_key( substr( $arg, strlen( 'fixture_marker=' ) ) );
			continue;
		}
		if ( 0 === strpos( $arg, 'entries=' ) ) {
			$entry_limit = max( 1, min( 20, (int) substr( $arg, strlen( 'entries=' ) ) ) );
		}
	}
}

if ( $relation_id <= 0 ) {
	$relation_ids = e2e_load_relation_ids();
	$relation_id  = max( 0, (int) ( $relation_ids['virtual'] ?? 0 ) );
}

if ( $relation_id <= 0 ) {
	echo "ERROR: Missing virtual relation. Run setup-relations.php first.\n";
	exit( 1 );
}

$templates_table = e2e_table( 'templates' );
$entries_table   = e2e_table( 'template_entries' );

// The fixture template must speak the relation's languages: discovery filters
// language-pack templates by the relation target_lang, so derive both from the
// relation instead of hardcoding a reversed pair.
$relations_table = e2e_table( 'site_relations' );
$relation_row    = $wpdb->get_row(
	$wpdb->prepare( "SELECT source_lang, target_lang FROM {$relations_table} WHERE id = %d", $relation_id ),
	ARRAY_A
);
if ( ! is_array( $relation_row ) ) {
	echo "ERROR: relation {$relation_id} not found for language alignment.\n";
	exit( 1 );
}
$fixture_source_lang = sanitize_text_field( (string) ( $relation_row['source_lang'] ?? '' ) );
$fixture_target_lang = sanitize_text_field( (string) ( $relation_row['target_lang'] ?? '' ) );
if ( '' === $fixture_target_lang ) {
	$fixture_target_lang = 'en_US';
}
if ( '' === $fixture_source_lang ) {
	$fixture_source_lang = 'zh_CN';
}

if ( ! e2e_table_exists( $templates_table ) || ! e2e_table_exists( $entries_table ) ) {
	echo "ERROR: language pack tables missing.\n";
	exit( 1 );
}

$now         = current_time( 'mysql' );
$slug        = $fixture_marker . '-relation-' . $relation_id;
$text_domain = $fixture_marker;

$template_id = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$templates_table} WHERE slug = %s LIMIT 1", $slug )
);

if ( $template_id <= 0 ) {
	$wpdb->insert(
		$templates_table,
		array(
			'relation_id'        => $relation_id,
			'slug'               => $slug,
			'source_type'        => 'plugin',
			'source_identifier'  => 'wptsall-e2e-language-pack',
			'text_domain'        => $text_domain,
			'source_name'        => 'WPTSALL E2E Language Pack Fixture',
			'source_version'     => 'e2e',
			'source_language'    => $fixture_source_lang,
			'target_language'    => $fixture_target_lang,
			'total_entries'      => 0,
			'translated_entries' => 0,
			'reviewed_entries'   => 0,
			'status'             => 'active',
			'last_scanned_at'    => $now,
			'created_at'         => $now,
			'updated_at'         => $now,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
	);

	if ( '' !== $wpdb->last_error ) {
		echo "ERROR: failed to create template: {$wpdb->last_error}\n";
		exit( 1 );
	}

	$template_id = (int) $wpdb->insert_id;
} else {
	// Reused template from an earlier run: realign languages with the relation
	// so discovery keeps returning it.
	$wpdb->update(
		$templates_table,
		array(
			'source_language' => $fixture_source_lang,
			'target_language' => $fixture_target_lang,
		),
		array( 'id' => $template_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
}

$created = 0;
$updated = 0;
for ( $i = 1; $i <= $entry_limit; $i++ ) {
	$msgid = sprintf( 'WPTSALL E2E language pack string %02d [%s]', $i, $fixture_marker );
	$status = 1 === $i ? 'translated' : 'pending';
	$msgstr = 1 === $i ? sprintf( 'WPTSALL E2E translated string %02d [%s]', $i, $fixture_marker ) : '';

	$entry_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$entries_table} WHERE template_id = %d AND msgid = %s AND msgctxt = %s LIMIT 1",
			$template_id,
			$msgid,
			''
		)
	);

	if ( $entry_id > 0 ) {
		$wpdb->update(
			$entries_table,
			array(
				'status'     => $status,
				'msgstr'     => $msgstr,
				'updated_at' => $now,
			),
			array( 'id' => $entry_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		$updated++;
		continue;
	}

	$wpdb->insert(
		$entries_table,
		array(
			'template_id' => $template_id,
			'msgid'       => $msgid,
			'msgid_plural' => '',
			'msgctxt'     => '',
			'msgstr'      => $msgstr,
			'msgstr_plural' => '',
			'status'      => $status,
			'source'      => 'manual',
			'reference'   => 'wptsall-e2e-language-pack-fixture.php:' . $i,
			'note'        => $fixture_marker,
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$created++;
}

$counts = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT COUNT(*) AS total_entries,
		        SUM(CASE WHEN status IN ('translated', 'reviewed') THEN 1 ELSE 0 END) AS translated_entries
		 FROM {$entries_table}
		 WHERE template_id = %d",
		$template_id
	),
	ARRAY_A
);

$wpdb->update(
	$templates_table,
	array(
		'total_entries'      => (int) ( $counts['total_entries'] ?? 0 ),
		'translated_entries' => (int) ( $counts['translated_entries'] ?? 0 ),
		'updated_at'         => $now,
	),
	array( 'id' => $template_id ),
	array( '%d', '%d', '%s' ),
	array( '%d' )
);

echo wp_json_encode(
	array(
		'success'        => true,
		'relation_id'    => $relation_id,
		'fixture_marker' => $fixture_marker,
		'template_id'    => $template_id,
		'entries'        => $entry_limit,
		'created'        => $created,
		'updated'        => $updated,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
