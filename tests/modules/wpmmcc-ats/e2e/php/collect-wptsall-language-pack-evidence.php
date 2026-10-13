<?php
/**
 * Collect WPTSALL language pack lane evidence.
 *
 * Usage:
 *   wp eval-file tests/modules/wpmmcc-ats/e2e/php/collect-wptsall-language-pack-evidence.php [relation_id=...] [run_id=...] [fixture_marker=...]
 */

require_once __DIR__ . '/helpers.php';

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must be run via wp eval-file\n";
	exit( 1 );
}

global $wpdb;

$relation_id    = 0;
$run_id         = '';
$fixture_marker = '';

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
		if ( 0 === strpos( $arg, 'run_id=' ) ) {
			$run_id = sanitize_text_field( substr( $arg, strlen( 'run_id=' ) ) );
			continue;
		}
		if ( 0 === strpos( $arg, 'fixture_marker=' ) ) {
			$fixture_marker = sanitize_text_field( substr( $arg, strlen( 'fixture_marker=' ) ) );
		}
	}
}

if ( $relation_id <= 0 ) {
	$relation_ids = e2e_load_relation_ids();
	$relation_id  = max( 0, (int) ( $relation_ids['virtual'] ?? 0 ) );
}

$templates_table = e2e_table( 'templates' );
$entries_table   = e2e_table( 'template_entries' );

function wptsall_lp_counts_by_sql( $sql ) {
	global $wpdb;

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$key = sanitize_key( (string) ( $row['bucket'] ?? '' ) );
		if ( '' === $key ) {
			$key = 'empty';
		}
		$out[ $key ] = (int) ( $row['cnt'] ?? 0 );
	}

	return $out;
}

$tables_exist = e2e_table_exists( $templates_table ) && e2e_table_exists( $entries_table );

$template_total          = 0;
$entry_total             = 0;
$relation_template_total = 0;
$relation_entry_total    = 0;
$template_source_counts  = array();
$entry_status_counts     = array();
$relation_source_counts  = array();
$relation_status_counts  = array();
$fixture_template_total  = 0;
$fixture_entry_total     = 0;
$fixture_status_counts   = array();
$fixture_claimed_entries = 0;
$fixture_translated_entries = 0;
$fixture_entries         = array();

if ( $tables_exist ) {
	$template_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$templates_table}" );
	$entry_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$entries_table}" );

	$template_source_counts = wptsall_lp_counts_by_sql(
		"SELECT source_type AS bucket, COUNT(*) AS cnt FROM {$templates_table} GROUP BY source_type ORDER BY cnt DESC"
	);
	$entry_status_counts = wptsall_lp_counts_by_sql(
		"SELECT status AS bucket, COUNT(*) AS cnt FROM {$entries_table} GROUP BY status ORDER BY cnt DESC"
	);

	if ( $relation_id > 0 ) {
		$relation_template_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$templates_table} WHERE relation_id = %d", $relation_id )
		);
		$relation_entry_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.relation_id = %d",
				$relation_id
			)
		);
		$relation_source_counts = wptsall_lp_counts_by_sql(
			$wpdb->prepare(
				"SELECT t.source_type AS bucket, COUNT(*) AS cnt
				 FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.relation_id = %d
				 GROUP BY t.source_type
				 ORDER BY cnt DESC",
				$relation_id
			)
		);
		$relation_status_counts = wptsall_lp_counts_by_sql(
			$wpdb->prepare(
				"SELECT e.status AS bucket, COUNT(*) AS cnt
				 FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.relation_id = %d
				 GROUP BY e.status
				 ORDER BY cnt DESC",
				$relation_id
			)
		);
	}

	if ( '' !== $fixture_marker ) {
		$fixture_template_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$templates_table} WHERE slug LIKE %s", '%' . $wpdb->esc_like( $fixture_marker ) . '%' )
		);
		$fixture_entry_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.slug LIKE %s",
				'%' . $wpdb->esc_like( $fixture_marker ) . '%'
			)
		);
		$fixture_status_counts = wptsall_lp_counts_by_sql(
			$wpdb->prepare(
				"SELECT e.status AS bucket, COUNT(*) AS cnt
				 FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.slug LIKE %s
				 GROUP BY e.status
				 ORDER BY cnt DESC",
				'%' . $wpdb->esc_like( $fixture_marker ) . '%'
			)
		);
		$fixture_claimed_entries = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.slug LIKE %s AND e.claimed_at IS NOT NULL",
				'%' . $wpdb->esc_like( $fixture_marker ) . '%'
			)
		);
		$fixture_translated_entries = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.slug LIKE %s AND e.status IN ('translated', 'reviewed') AND e.msgstr <> ''",
				'%' . $wpdb->esc_like( $fixture_marker ) . '%'
			)
		);
		$fixture_entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id AS entry_id, e.status, e.msgstr, e.claimed_at, e.updated_at
				 FROM {$entries_table} e
				 INNER JOIN {$templates_table} t ON e.template_id = t.id
				 WHERE t.slug LIKE %s
				 ORDER BY e.id ASC
				 LIMIT 20",
				'%' . $wpdb->esc_like( $fixture_marker ) . '%'
			),
			ARRAY_A
		);
		$fixture_entries = array_map(
			static function ( $row ) {
				return array(
					'entry_id'   => (int) ( $row['entry_id'] ?? 0 ),
					'status'     => (string) ( $row['status'] ?? '' ),
					'has_msgstr' => '' !== (string) ( $row['msgstr'] ?? '' ),
					'claimed'    => '' !== (string) ( $row['claimed_at'] ?? '' ),
					'updated_at' => (string) ( $row['updated_at'] ?? '' ),
				);
			},
			(array) $fixture_entries
		);
	}
}

$payload = array(
	'generated_at' => current_time( 'mysql' ),
	'run_scope'    => array(
		'run_id'         => $run_id,
		'relation_id'    => $relation_id,
		'fixture_marker' => $fixture_marker,
	),
	'tables'       => array(
		'templates'        => e2e_table_exists( $templates_table ),
		'template_entries' => e2e_table_exists( $entries_table ),
	),
	'totals'       => array(
		'templates'        => $template_total,
		'template_entries' => $entry_total,
	),
	'global'       => array(
		'template_source_counts' => $template_source_counts,
		'entry_status_counts'    => $entry_status_counts,
	),
	'relation'     => array(
		'templates'        => $relation_template_total,
		'template_entries' => $relation_entry_total,
		'source_counts'    => $relation_source_counts,
		'status_counts'    => $relation_status_counts,
	),
	'fixture'      => array(
		'templates'          => $fixture_template_total,
		'template_entries'   => $fixture_entry_total,
		'translated_entries' => $fixture_translated_entries,
		'claimed_entries'    => $fixture_claimed_entries,
		'status_counts'      => $fixture_status_counts,
		'entries'            => $fixture_entries,
	),
);

echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
