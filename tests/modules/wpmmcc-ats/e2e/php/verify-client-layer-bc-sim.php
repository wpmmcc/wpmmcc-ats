<?php
/**
 * Simulate client Layer B/C pull → claim → callback (no Rust client required).
 *
 * Env: WPTSALL_ABC_RELATION_ID (optional), WPTSALL_SIM_TARGET_LANG (default en_US)
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

/**
 * @param bool   $cond Condition.
 * @param string $id   Id.
 * @param string $msg  Message.
 * @return void
 */
function sim_assert( $cond, $id, $msg ) {
	if ( $cond ) {
		++$GLOBALS['pass'];
		echo "PASS {$id}: {$msg}\n";
	} else {
		++$GLOBALS['fail'];
		echo "FAIL {$id}: {$msg}\n";
	}
}

$relation_id = (int) ( getenv( 'WPTSALL_ABC_RELATION_ID' ) ?: 0 );
$target_lang = (string) ( getenv( 'WPTSALL_SIM_TARGET_LANG' ) ?: 'en_US' );

if ( $relation_id <= 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
	$all = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations( array( 'status' => 'active' ) );
	foreach ( (array) $all as $row ) {
		if ( 'virtual' === ( $row['target_site_type'] ?? '' ) ) {
			$relation_id = (int) $row['id'];
			$target_lang = (string) ( $row['target_lang'] ?? $target_lang );
			break;
		}
	}
}
sim_assert( $relation_id > 0, 'relation', "relation#{$relation_id} lang={$target_lang}" );

// Enable Layer B + C switches (as client discovery would read).
if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' ) ) {
	$cfg = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
	$cfg['translate_site_strings']   = true;
	$cfg['translate_menu_strings']   = true;
	$cfg['translate_widget_strings'] = true;
	$cfg['translate_plugin_i18n']    = true;
	$cfg['translate_theme_i18n']     = ! empty( $cfg['translate_theme_i18n'] );
	$save = \WPTSALL\Sites\Services\Relation_Config_Service::save_template_config( $relation_id, $cfg );
	sim_assert( ! is_wp_error( $save ), 'config', 'Layer B/C switches enabled' );
}

// ── Layer B client path ────────────────────────────────────────────────────
sim_assert(
	class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ),
	'B.service',
	'String_Translation_Service'
);

\WPTSALL\Strings\Services\String_Translation_Service::register(
	'menu',
	'client_sim_probe',
	'Client Sim Menu Label'
);
global $wpdb;
$st = wptsall_table( 'strings' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$st} SET translations='{}', status='pending', claimed_at=NULL WHERE context='menu' AND string_key=%s",
		'client_sim_probe'
	)
);

// list_untranslated_for_client pages ORDER BY id ASC — pin the probe via include_ids
// so it is not buried under thousands of older menu strings.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$probe_ids = array_map(
	'intval',
	(array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$st} WHERE context='menu' AND string_key=%s ORDER BY id DESC",
			'client_sim_probe'
		)
	)
);
$list = \WPTSALL\Strings\Services\String_Translation_Service::list_untranslated_for_client(
	$target_lang,
	'menu',
	1,
	20,
	$probe_ids
);
$item = null;
foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
	if ( ( $it['complete_data']['string_key'] ?? '' ) === 'client_sim_probe' ) {
		$item = $it;
		break;
	}
}
sim_assert( ! empty( $item['object_id'] ), 'B.pull', 'client pull found client_sim_probe' );

if ( ! empty( $item['object_id'] ) ) {
	$sid = (int) $item['object_id'];
	$n   = \WPTSALL\Strings\Services\String_Translation_Service::claim_strings( array( $sid ) );
	sim_assert( $n > 0, 'B.claim', "claimed string#{$sid}" );
	$marker = '【CLIENT-SIM-B】';
	$applied = \WPTSALL\Strings\Services\String_Translation_Service::apply_client_translations(
		$target_lang,
		array( array( 'string_id' => $sid, 'msgstr' => $marker ) )
	);
	$looked = \WPTSALL\Strings\Services\String_Translation_Service::translate( 'menu', 'client_sim_probe', 'X', $target_lang );
	sim_assert( $applied > 0 && $looked === $marker, 'B.callback', "writeback+lookup → {$looked}" );
}

// ── Layer C client path (language_pack entries) ────────────────────────────
$tpl = wptsall_table( 'templates' );
$ent = wptsall_table( 'template_entries' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$entry = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT e.id, e.msgid, t.text_domain FROM {$ent} e
		 INNER JOIN {$tpl} t ON t.id = e.template_id
		 WHERE t.relation_id = %d AND e.status = 'pending' AND t.source_type = 'plugin'
		 ORDER BY e.id ASC LIMIT 1",
		$relation_id
	),
	ARRAY_A
);
if ( ! $entry ) {
	echo "WARN C.pull: no pending plugin entry — attempting source scan\n";
	if ( class_exists( '\\WPTSALL\\Templates\\Scanners\\Language_Pack_Scanner' ) ) {
		\WPTSALL\Templates\Scanners\Language_Pack_Scanner::scan_relation(
			$relation_id,
			\WPTSALL\Templates\Scanners\Language_Pack_Scanner::SCAN_SOURCE
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$entry = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.id, e.msgid, t.text_domain FROM {$ent} e
			 INNER JOIN {$tpl} t ON t.id = e.template_id
			 WHERE t.relation_id = %d AND e.status = 'pending' AND t.source_type IN ('plugin','theme')
			 ORDER BY e.id ASC LIMIT 1",
			$relation_id
		),
		ARRAY_A
	);
}

// Lab after ABC matrix may have exhausted pending plugin/theme entries — seed one
// synthetic pending row so claim→callback→gettext stays covered.
if ( empty( $entry['id'] ) && class_exists( '\\WPTSALL\\Templates\\Services\\Template_Service' ) ) {
	echo "WARN C.pull: seeding synthetic pending language_pack entry\n";
	$domain = 'wptsall-client-sim';
	$msgid  = 'client_sim_c_probe_' . gmdate( 'YmdHis' );
	$tpl_id = 0;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$tpl_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$tpl} WHERE relation_id = %d AND text_domain = %s ORDER BY id DESC LIMIT 1",
			$relation_id,
			$domain
		)
	);
	if ( $tpl_id <= 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$tpl,
			array(
				'relation_id' => $relation_id,
				'text_domain' => $domain,
				'source_type' => 'plugin',
				'status'      => 'active',
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			)
		);
		$tpl_id = (int) $wpdb->insert_id;
	}
	if ( $tpl_id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$ent,
			array(
				'template_id' => $tpl_id,
				'msgid'       => $msgid,
				'msgstr'      => '',
				'status'      => 'pending',
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			)
		);
		$eid = (int) $wpdb->insert_id;
		if ( $eid > 0 ) {
			$entry = array(
				'id'          => $eid,
				'msgid'       => $msgid,
				'text_domain' => $domain,
			);
		}
	}
}

sim_assert( ! empty( $entry['id'] ), 'C.pull', 'pending language_pack entry available' );
if ( ! empty( $entry['id'] ) ) {
	$eid    = (int) $entry['id'];
	$marker = '【CLIENT-SIM-C】';
	$now    = current_time( 'mysql' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update(
		$ent,
		array(
			'msgstr'     => $marker,
			'status'     => 'translated',
			'updated_at' => $now,
		),
		array( 'id' => $eid )
	);
	$hook = class_exists( '\\WPTSALL\\Templates\\Services\\Template_Entry_Service' )
		? \WPTSALL\Templates\Services\Template_Entry_Service::get_translations_for_hook( $relation_id, (string) $entry['text_domain'] )
		: array();
	$ok = isset( $hook[ $entry['msgid'] ]['msgstr'] ) && $hook[ $entry['msgid'] ]['msgstr'] === $marker;
	sim_assert( $ok, 'C.callback', "entry#{$eid} domain={$entry['text_domain']} hook ready" );

	if ( class_exists( '\\WPTSALL\\Hooks\\Gettext_Filter' ) ) {
		\WPTSALL\Hooks\Gettext_Filter::preload_translations( $relation_id, (string) $entry['text_domain'] );
		$filtered = \WPTSALL\Hooks\Gettext_Filter::filter_gettext( 'ORIG', (string) $entry['msgid'], (string) $entry['text_domain'] );
		sim_assert(
			$filtered === $marker || false !== strpos( (string) $filtered, $marker ),
			'C.gettext',
			"filter_gettext → {$filtered}"
		);
	}
}

echo 'SUMMARY pass=' . $GLOBALS['pass'] . ' fail=' . $GLOBALS['fail'] . "\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
