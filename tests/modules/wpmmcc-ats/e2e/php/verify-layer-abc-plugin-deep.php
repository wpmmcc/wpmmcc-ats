<?php
/**
 * Layer A/B/C deep verifier for one content-plugin project.
 *
 * Env:
 *   E2E_PROJECT=woocommerce-content   (preferred; reads project-specs.json)
 *   WPTSALL_ABC_PLUGIN_SLUG=woocommerce  (fallback)
 *   WPTSALL_ABC_RELATION_ID=440          (optional; else first active virtual relation)
 *   WPTSALL_ABC_SKIP_SCAN=0|1            (skip heavy language_pack scan)
 *
 * Run:
 *   wp eval-file /opt/wptsall-e2e/php/verify-layer-abc-plugin-deep.php --allow-root
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['warn'] = 0;
$GLOBALS['skip'] = 0;
$GLOBALS['checks'] = array();

/**
 * @param bool   $cond Condition.
 * @param string $id   Check id.
 * @param string $msg  Message.
 * @return void
 */
function abc_assert( $cond, $id, $msg ) {
	if ( $cond ) {
		$GLOBALS['pass']++;
		$GLOBALS['checks'][] = array( 'id' => $id, 'status' => 'PASS', 'msg' => $msg );
		echo "PASS {$id}: {$msg}\n";
	} else {
		$GLOBALS['fail']++;
		$GLOBALS['checks'][] = array( 'id' => $id, 'status' => 'FAIL', 'msg' => $msg );
		echo "FAIL {$id}: {$msg}\n";
	}
}

/**
 * @param string $id  Check id.
 * @param string $msg Message.
 * @return void
 */
function abc_warn( $id, $msg ) {
	$GLOBALS['warn']++;
	$GLOBALS['checks'][] = array( 'id' => $id, 'status' => 'WARN', 'msg' => $msg );
	echo "WARN {$id}: {$msg}\n";
}

/**
 * @param string $id  Check id.
 * @param string $msg Message.
 * @return void
 */
function abc_skip( $id, $msg ) {
	$GLOBALS['skip']++;
	$GLOBALS['checks'][] = array( 'id' => $id, 'status' => 'SKIP', 'msg' => $msg );
	echo "SKIP {$id}: {$msg}\n";
}

$project = getenv( 'E2E_PROJECT' ) ?: '';
$plugin_slug = getenv( 'WPTSALL_ABC_PLUGIN_SLUG' ) ?: '';
$specs_file  = getenv( 'E2E_PROJECT_SPECS_FILE' ) ?: dirname( __DIR__ ) . '/project-specs.json';
$content_type = '';
$text_domain  = '';

if ( '' !== $project && is_readable( $specs_file ) ) {
	$specs = json_decode( (string) file_get_contents( $specs_file ), true );
	$spec  = $specs['plugin_projects'][ $project ] ?? null;
	if ( is_array( $spec ) ) {
		$plugin_slug  = (string) ( $spec['plugin_slug'] ?? $plugin_slug );
		$content_type = (string) ( $spec['content_types'][0] ?? ( $spec['verifier']['source_post_type'] ?? '' ) );
		$text_domain  = (string) ( $spec['text_domain'] ?? '' );
	}
}
if ( '' === $plugin_slug ) {
	echo "ERROR: set E2E_PROJECT or WPTSALL_ABC_PLUGIN_SLUG\n";
	exit( 2 );
}
if ( '' === $text_domain ) {
	$text_domain = $plugin_slug; // most WP plugins use slug as domain
}

echo "=== Layer ABC deep: project={$project} plugin={$plugin_slug} cpt={$content_type} ===\n";

// Resolve relation (prefer virtual).
$relation_id = (int) ( getenv( 'WPTSALL_ABC_RELATION_ID' ) ?: 0 );
$relation    = null;
if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
	$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
}
if ( ! $relation && class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
	$all = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations( array( 'status' => 'active' ) );
	foreach ( (array) $all as $row ) {
		if ( 'virtual' === ( $row['target_site_type'] ?? '' ) ) {
			$relation    = $row;
			$relation_id = (int) $row['id'];
			break;
		}
	}
	if ( ! $relation && ! empty( $all[0] ) ) {
		$relation    = $all[0];
		$relation_id = (int) $all[0]['id'];
	}
}
abc_assert( $relation_id > 0 && is_array( $relation ), 'relation', "active relation#{$relation_id}" );
$target_lang = (string) ( $relation['target_lang'] ?? 'en_US' );

// ── Layer A: published content for this plugin CPT ─────────────────────────
global $wpdb;
if ( '' !== $content_type ) {
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','private')",
			$content_type
		)
	);
	abc_assert( $count > 0, 'A.source_cpt', "{$content_type} source posts={$count}" );

	$map_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';
	$mapped    = 0;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $map_table ) );
	if ( $exists === $map_table ) {
		$mapped = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$map_table} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.source_post_id
				 WHERE p.post_type = %s AND m.relation_id = %d",
				$content_type,
				$relation_id
			)
		);
	}
	if ( $mapped > 0 ) {
		abc_assert( true, 'A.mappings', "{$content_type} mappings={$mapped} for relation#{$relation_id}" );
	} else {
		abc_warn( 'A.mappings', "no mappings yet for {$content_type}/relation#{$relation_id} (run Stage 1–7 for full A)" );
	}

	// Model rules present for plugin?
	$models_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'models' ) : $wpdb->prefix . 'wptsall_models';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$model_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM %i WHERE plugin_slug = %s ORDER BY id DESC LIMIT 1',
			$models_table,
			$plugin_slug
		)
	);
	if ( $model_id > 0 ) {
		abc_assert( true, 'A.model', "model#{$model_id} for {$plugin_slug}" );
	} else {
		abc_warn( 'A.model', "no model row for plugin_slug={$plugin_slug}" );
	}
} else {
	abc_skip( 'A.source_cpt', 'no content_type in project spec' );
}

// ── Layer B: site structure strings ────────────────────────────────────────
if ( ! class_exists( '\\WPTSALL\\Strings\\Services\\Site_String_Scanner' ) ) {
	abc_assert( false, 'B.scanner', 'Site_String_Scanner missing' );
} else {
	$scan_b = \WPTSALL\Strings\Services\Site_String_Scanner::scan_all();
	abc_assert( is_array( $scan_b ), 'B.scan', 'scan_all returned array registered=' . (int) ( $scan_b['registered'] ?? 0 ) );

	$counts = \WPTSALL\Strings\Services\String_Translation_Service::counts();
	abc_assert( (int) ( $counts['total'] ?? 0 ) > 0, 'B.strings_total', 'wptsall_strings total=' . (int) ( $counts['total'] ?? 0 ) );

	// Per-project synthetic string so later matrix rows are not starved by earlier writebacks.
	$synth_key = 'abc_probe_' . sanitize_key( $plugin_slug ?: $project );
	\WPTSALL\Strings\Services\String_Translation_Service::register( 'menu', $synth_key, 'ABC probe ' . $plugin_slug );
	// Clear prior translation/claim so this project can claim+writeback freshly.
	global $wpdb;
	$st = function_exists( 'wptsall_table' ) ? wptsall_table( 'strings' ) : $wpdb->prefix . 'wptsall_strings';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$st} SET translations = %s, status = 'pending', claimed_at = NULL WHERE context = 'menu' AND string_key = %s",
			'{}',
			$synth_key
		)
	);

	foreach ( array( 'site' => 'site_strings', 'menu' => 'menu_strings', 'widget' => 'widget_strings' ) as $subtype => $bl ) {
		$list = \WPTSALL\Strings\Services\String_Translation_Service::list_untranslated_for_client(
			$target_lang,
			$subtype,
			1,
			20
		);
		$total = (int) ( $list['total'] ?? 0 );
		if ( $total > 0 ) {
			abc_assert( true, "B.list.{$subtype}", "{$bl} untranslated_total={$total}" );
		} else {
			abc_warn( "B.list.{$subtype}", "{$bl} untranslated_total=0 (may already be translated)" );
		}
	}

	// Enable Layer B switches (client discovery gates).
	$b_marker = '';
	$b_sid    = 0;
	$b_ctx    = '';
	$b_key    = '';
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' )
		&& 'virtual' === ( $relation['target_site_type'] ?? '' ) ) {
		$cfg = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		abc_assert( array_key_exists( 'translate_menu_strings', $cfg ), 'B.config_keys', 'translate_menu_strings key present' );
		$cfg['translate_site_strings']   = true;
		$cfg['translate_menu_strings']   = true;
		$cfg['translate_widget_strings'] = true;
		$save_b = \WPTSALL\Sites\Services\Relation_Config_Service::save_template_config( $relation_id, $cfg );
		abc_assert( ! is_wp_error( $save_b ), 'B.config_enable', 'enabled site/menu/widget_strings' );
		$cfg2 = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		abc_assert(
			! empty( $cfg2['translate_menu_strings'] ) && ! empty( $cfg2['translate_site_strings'] ),
			'B.config_readback',
			'menu+site switches true after save'
		);
	} else {
		abc_warn( 'B.config_enable', 'skip Layer B switch matrix (non-virtual relation)' );
	}

	// Prefer this project's synthetic probe, else any pending menu/site.
	$list = \WPTSALL\Strings\Services\String_Translation_Service::list_untranslated_for_client( $target_lang, 'menu', 1, 50 );
	$picked = null;
	foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
		$sk = (string) ( $it['complete_data']['string_key'] ?? '' );
		if ( $sk === $synth_key ) {
			$picked = $it;
			break;
		}
	}
	if ( ! $picked && ! empty( $list['items'][0] ) ) {
		$picked = $list['items'][0];
	}
	if ( ! $picked ) {
		$list = \WPTSALL\Strings\Services\String_Translation_Service::list_untranslated_for_client( $target_lang, 'site', 1, 5 );
		$picked = $list['items'][0] ?? null;
	}
	if ( ! empty( $picked['object_id'] ) ) {
		$b_sid  = (int) $picked['object_id'];
		$cd     = (array) ( $picked['complete_data'] ?? array() );
		$b_ctx  = (string) ( $cd['context'] ?? 'menu' );
		$b_key  = (string) ( $cd['string_key'] ?? '' );
		\WPTSALL\Strings\Services\String_Translation_Service::claim_strings( array( $b_sid ) );
		$b_marker = '【ABC-' . $plugin_slug . '】';
		$n        = \WPTSALL\Strings\Services\String_Translation_Service::apply_client_translations(
			$target_lang,
			array( array( 'string_id' => $b_sid, 'msgstr' => $b_marker ) )
		);
		$row = \WPTSALL\Strings\Services\String_Translation_Service::get( $b_sid );
		$tr  = json_decode( (string) ( $row['translations'] ?? '' ), true );
		if ( '' === $b_key && is_array( $row ) ) {
			$b_ctx = (string) ( $row['context'] ?? $b_ctx );
			$b_key = (string) ( $row['string_key'] ?? '' );
		}
		abc_assert( $n > 0 && ( $tr[ $target_lang ] ?? '' ) === $b_marker, 'B.writeback', "string#{$b_sid} → {$b_marker}" );

		// Runtime lookup used by Menu_Translation / wptsall_translate_string.
		$looked = \WPTSALL\Strings\Services\String_Translation_Service::translate( $b_ctx, $b_key, 'FALLBACK', $target_lang );
		abc_assert( $looked === $b_marker, 'B.runtime_lookup', "translate({$b_ctx},{$b_key}) → {$looked}" );

		$api = function_exists( 'wptsall_translate_string' )
			? wptsall_translate_string( 'FALLBACK', $b_key, $target_lang, $b_ctx )
			: '';
		abc_assert( $api === $b_marker, 'B.public_api', "wptsall_translate_string → {$api}" );

		// Also register site title for runtime API checks — do NOT overwrite blogname
		// with the ABC marker (that pollutes VS chrome for later Layer A matrix lanes).
		\WPTSALL\Strings\Services\String_Translation_Service::register( 'site_title', 'blogname', (string) get_option( 'blogname' ) );
		$site_look = \WPTSALL\Strings\Services\String_Translation_Service::translate( 'site_title', 'blogname', 'X', $target_lang );
		// Probe key already covered menu runtime; site_title may still be untranslated.
		if ( $site_look && $site_look !== 'X' ) {
			abc_assert( true, 'B.site_title_runtime', "blogname lookup ok → " . substr( $site_look, 0, 40 ) );
		} else {
			abc_warn( 'B.site_title_runtime', 'blogname not translated yet (expected until site_strings writeback)' );
		}
	} else {
		abc_skip( 'B.writeback', 'no pending site/menu string to claim' );
		abc_skip( 'B.runtime_lookup', 'no writeback' );
		abc_skip( 'B.public_api', 'no writeback' );
	}

	// Negative switch: menu_strings off must persist (client should skip discovery).
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' )
		&& 'virtual' === ( $relation['target_site_type'] ?? '' ) ) {
		$cfg = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		$cfg['translate_menu_strings'] = false;
		\WPTSALL\Sites\Services\Relation_Config_Service::save_template_config( $relation_id, $cfg );
		$cfg3 = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		abc_assert( empty( $cfg3['translate_menu_strings'] ), 'B.switch_off', 'translate_menu_strings=false persisted' );
		// Restore on for subsequent projects / front check.
		$cfg3['translate_menu_strings']   = true;
		$cfg3['translate_site_strings']   = true;
		$cfg3['translate_widget_strings'] = true;
		\WPTSALL\Sites\Services\Relation_Config_Service::save_template_config( $relation_id, $cfg3 );
	}

	// VS front HTTP: marker may appear if menu is rendered on home.
	$path_prefix = '';
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
		$vs = \WPTSALL\Sites\Services\Virtual_Site_Service::get_by_relation( $relation_id );
		if ( is_array( $vs ) && ! empty( $vs['path_prefix'] ) ) {
			$path_prefix = trim( (string) $vs['path_prefix'], '/' );
		}
	}
	if ( '' === $path_prefix && ! empty( $relation['target_theme_path'] ) ) {
		$path_prefix = trim( (string) $relation['target_theme_path'], '/' );
	}
	if ( '' === $path_prefix && ! empty( $relation['target_lang'] ) ) {
		$path_prefix = sanitize_title( (string) $relation['target_lang'] );
	}
	if ( '' !== $b_marker && '' !== $path_prefix ) {
		// Inside Lab: WP home is host-mapped (:9083). Curl :80 and rewrite redirect hosts.
		$url  = 'http://127.0.0.1/' . $path_prefix . '/';
		$body = '';
		$code = 0;
		$curl = trim( (string) shell_exec( 'command -v curl' ) );
		if ( '' !== $curl ) {
			$tmp = tempnam( sys_get_temp_dir(), 'abc-body' );
			$code = (int) trim( (string) shell_exec(
				sprintf( '%s -sS --connect-timeout 5 -o %s -w "%%{http_code}" %s', escapeshellarg( $curl ), escapeshellarg( $tmp ), escapeshellarg( $url ) )
			) );
			$body = is_readable( $tmp ) ? (string) file_get_contents( $tmp ) : '';
			@unlink( $tmp );
			if ( $code >= 300 && $code < 400 ) {
				$loc = trim( (string) shell_exec(
					sprintf( '%s -sS -o /dev/null -w "%%{redirect_url}" %s', escapeshellarg( $curl ), escapeshellarg( $url ) )
				) );
				if ( '' !== $loc ) {
					$loc  = (string) preg_replace( '#^https?://[^/]+#', 'http://127.0.0.1', $loc );
					$tmp2 = tempnam( sys_get_temp_dir(), 'abc-body2' );
					$code = (int) trim( (string) shell_exec(
						sprintf( '%s -sS --connect-timeout 5 -o %s -w "%%{http_code}" %s', escapeshellarg( $curl ), escapeshellarg( $tmp2 ), escapeshellarg( $loc ) )
					) );
					$body = is_readable( $tmp2 ) ? (string) file_get_contents( $tmp2 ) : '';
					@unlink( $tmp2 );
					$url = $loc;
				}
			}
		}
		if ( $code >= 200 && $code < 400 ) {
			abc_assert( true, 'B.front_http', "{$url} http={$code}" );
		} else {
			abc_warn( 'B.front_http', "VS fetch soft-fail {$url} http={$code}" );
		}
		if ( '' !== $body && false !== strpos( $body, $b_marker ) ) {
			abc_assert( true, 'B.front_marker', 'VS HTML contains Layer B marker' );
		} else {
			abc_warn( 'B.front_marker', 'VS home HTML lacks marker (menu may not render on home)' );
		}
	} else {
		abc_skip( 'B.front_http', 'no marker or path_prefix' );
	}
}

// ── Layer C: plugin gettext / language_pack ────────────────────────────────
if ( ! class_exists( '\\WPTSALL\\Templates\\Scanners\\Language_Pack_Scanner' ) ) {
	abc_assert( false, 'C.scanner', 'Language_Pack_Scanner missing' );
} else {
	// Enable plugin i18n + whitelist this plugin domain for the relation.
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' )
		&& 'virtual' === ( $relation['target_site_type'] ?? '' ) ) {
		$cfg = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		$cfg['translate_plugin_i18n']     = true;
		$cfg['translate_theme_i18n']      = ! empty( $cfg['translate_theme_i18n'] );
		$cfg['translate_config_i18n']     = ! empty( $cfg['translate_config_i18n'] );
		$cfg['gettext_domain_whitelist'] = array( $text_domain );
		$save = \WPTSALL\Sites\Services\Relation_Config_Service::save_template_config( $relation_id, $cfg );
		abc_assert( ! is_wp_error( $save ), 'C.config_save', 'saved whitelist=' . $text_domain );
	} else {
		abc_warn( 'C.config_save', 'relation not virtual — i18n config may be disabled by design' );
	}

	$skip_scan = (string) ( getenv( 'WPTSALL_ABC_SKIP_SCAN' ) ?: '0' );
	if ( '1' === $skip_scan ) {
		abc_skip( 'C.scan', 'WPTSALL_ABC_SKIP_SCAN=1' );
	} else {
		// Prefer source scan for this plugin only (lighter than full pot dump of all).
		$result = \WPTSALL\Templates\Scanners\Language_Pack_Scanner::scan_relation(
			$relation_id,
			\WPTSALL\Templates\Scanners\Language_Pack_Scanner::SCAN_SOURCE
		);
		abc_assert( ! empty( $result['success'] ) || isset( $result['templates'] ) || isset( $result['saved'] ), 'C.scan', 'scan_relation source completed' );
	}

	$tpl_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'templates' ) : $wpdb->prefix . 'wptsall_templates';
	$ent_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'template_entries' ) : $wpdb->prefix . 'wptsall_template_entries';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$tpl_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE relation_id = %d AND text_domain = %s',
			$tpl_table,
			$relation_id,
			$text_domain
		)
	);
	if ( $tpl_count > 0 ) {
		abc_assert( true, 'C.template_domain', "templates for domain={$text_domain} count={$tpl_count}" );
	} else {
		// Domain may differ from slug (e.g. some plugins) — fall back to any plugin templates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$any = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE relation_id = %d AND source_type IN ('plugin','theme')",
				$tpl_table,
				$relation_id
			)
		);
		if ( $any > 0 ) {
			abc_warn( 'C.template_domain', "no template for domain={$text_domain}; other plugin/theme templates={$any}" );
		} else {
			abc_warn( 'C.template_domain', "no plugin/theme templates for relation#{$relation_id} after scan" );
		}
	}

	// Prefer pending entry WITH msgid (and optional msgctxt) for this domain.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$entry = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.id, e.msgid, e.msgctxt, t.text_domain FROM %i e
			 INNER JOIN %i t ON t.id = e.template_id
			 WHERE t.relation_id = %d AND e.status = 'pending' AND t.source_type = 'plugin'
			   AND t.text_domain = %s AND e.msgid <> ''
			 ORDER BY e.id ASC LIMIT 1",
			$ent_table,
			$tpl_table,
			$relation_id,
			$text_domain
		),
		ARRAY_A
	);
	if ( ! $entry ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT e.id, e.msgid, e.msgctxt, t.text_domain FROM %i e
				 INNER JOIN %i t ON t.id = e.template_id
				 WHERE t.relation_id = %d AND e.status = 'pending' AND t.source_type = 'plugin'
				   AND e.msgid <> ''
				 ORDER BY e.id ASC LIMIT 1",
				$ent_table,
				$tpl_table,
				$relation_id
			),
			ARRAY_A
		);
		if ( $entry ) {
			abc_warn( 'C.writeback_domain', "fallback entry domain={$entry['text_domain']} (wanted {$text_domain})" );
		}
	}
	if ( $entry ) {
		$eid    = (int) $entry['id'];
		$msgid  = (string) ( $entry['msgid'] ?? '' );
		$msgctxt = (string) ( $entry['msgctxt'] ?? '' );
		$domain = (string) ( $entry['text_domain'] ?? $text_domain );
		$marker = '【ABC-I18N-' . $plugin_slug . '】';
		$now    = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$ent_table,
			array(
				'msgstr'     => $marker,
				'status'     => 'translated',
				'updated_at' => $now,
			),
			array( 'id' => $eid )
		);
		wp_cache_delete( "translations_{$relation_id}_{$domain}", 'wptsall_templates' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$msgstr = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT msgstr FROM %i WHERE id = %d', $ent_table, $eid ) );
		abc_assert( $msgstr === $marker, 'C.writeback', "entry#{$eid} domain={$domain} msgstr set" );

		if ( class_exists( '\\WPTSALL\\Templates\\Services\\Template_Entry_Service' ) && '' !== $msgid ) {
			$hook_map = \WPTSALL\Templates\Services\Template_Entry_Service::get_translations_for_hook( $relation_id, $domain );
			$keys     = array( $msgid );
			if ( '' !== $msgctxt ) {
				$keys[] = $msgctxt . "\x04" . $msgid;
			}
			$hit = false;
			foreach ( $keys as $hk ) {
				if ( is_array( $hook_map ) && isset( $hook_map[ $hk ]['msgstr'] ) && $hook_map[ $hk ]['msgstr'] === $marker ) {
					$hit = true;
					break;
				}
			}
			// Fallback: any map value equals marker (msgctxt encoding variance).
			if ( ! $hit && is_array( $hook_map ) ) {
				foreach ( $hook_map as $hk => $hv ) {
					if ( ( $hv['msgstr'] ?? '' ) === $marker && ( $hk === $msgid || str_ends_with( (string) $hk, $msgid ) ) ) {
						$hit = true;
						break;
					}
				}
			}
			abc_assert( $hit, 'C.runtime_hook', "get_translations_for_hook({$domain}) msgid present" );
		} else {
			abc_skip( 'C.runtime_hook', 'Template_Entry_Service or msgid missing' );
		}

		if ( class_exists( '\\WPTSALL\\Hooks\\Gettext_Filter' ) && '' !== $msgid ) {
			\WPTSALL\Hooks\Gettext_Filter::preload_translations( $relation_id, $domain );
			$filtered = \WPTSALL\Hooks\Gettext_Filter::filter_gettext( 'ORIG', $msgid, $domain );
			if ( $filtered === $marker || false !== strpos( (string) $filtered, $marker ) ) {
				abc_assert( true, 'C.gettext_filter', "filter_gettext → {$filtered}" );
			} else {
				abc_warn( 'C.gettext_filter', "filter returned [{$filtered}] (expected marker; may need VS front context)" );
			}
		}
	} else {
		abc_skip( 'C.writeback', 'no pending plugin template_entries' );
		abc_skip( 'C.runtime_hook', 'no writeback' );
	}

	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Config_Service' ) ) {
		$cfg_w = \WPTSALL\Sites\Services\Relation_Config_Service::get_template_config( $relation_id );
		$wl    = array_map( 'strtolower', (array) ( $cfg_w['gettext_domain_whitelist'] ?? array() ) );
		abc_assert( in_array( strtolower( $text_domain ), $wl, true ), 'C.whitelist_persist', 'whitelist contains ' . $text_domain );
	}
}

// ── Dashboard layer summary ────────────────────────────────────────────────
if ( class_exists( '\\WPTSALL\\ManualTranslation\\Services\\Translation_Progress_Service' ) ) {
	$layers = \WPTSALL\ManualTranslation\Services\Translation_Progress_Service::layer_summary();
	abc_assert( isset( $layers['layer_a'], $layers['layer_b'], $layers['layer_c'] ), 'X.dashboard_layers', 'layer_summary has A/B/C' );
}

$report = array(
	'project'     => $project,
	'plugin_slug' => $plugin_slug,
	'content_type'=> $content_type,
	'relation_id' => $relation_id,
	'target_lang' => $target_lang,
	'pass'        => $GLOBALS['pass'],
	'fail'        => $GLOBALS['fail'],
	'warn'        => $GLOBALS['warn'],
	'skip'        => $GLOBALS['skip'],
	'checks'      => $GLOBALS['checks'],
	'generated_at'=> gmdate( 'c' ),
);

$out_dir = getenv( 'WPTSALL_ABC_REPORT_DIR' ) ?: ( dirname( __DIR__ ) . '/reports/layer-abc' );
if ( ! is_dir( $out_dir ) ) {
	wp_mkdir_p( $out_dir );
}
$safe = preg_replace( '/[^a-z0-9\-_]+/i', '-', $project ?: $plugin_slug );
$file = trailingslashit( $out_dir ) . 'abc-' . $safe . '.json';
file_put_contents( $file, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
echo "REPORT {$file}\n";
echo "SUMMARY pass={$GLOBALS['pass']} fail={$GLOBALS['fail']} warn={$GLOBALS['warn']} skip={$GLOBALS['skip']}\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
