<?php
/**
 * Isolate one catalog plugin and exercise WPTSALL + target FE/admin/URLs/lang links.
 *
 * wp eval-file run-one.php <slug>
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slug = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';
$home = rtrim( (string) get_option( 'home' ), '/' );
$out  = array(
	'slug'      => $slug,
	'started'   => gmdate( 'c' ),
	'pass'      => true,
	'status'    => 'ran',
	'steps'     => array(),
	'urls'      => array(),
	'ids'       => array(),
	'notes'     => array(),
);

function cov_ok( &$out, $name, $ok, $detail = null ) {
	$out['steps'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => $detail );
	if ( ! $ok ) {
		$out['pass'] = false;
	}
}

function cov_http( $url, $cookies = array() ) {
	$args = array(
		'timeout'     => 20,
		'redirection' => 3,
		'sslverify'   => false,
	);
	if ( $cookies ) {
		$args['cookies'] = $cookies;
	}
	$r = wp_remote_get( $url, $args );
	if ( is_wp_error( $r ) ) {
		return array( 'url' => $url, 'code' => 0, 'error' => $r->get_error_message(), 'title' => '', 'body' => '' );
	}
	$body  = (string) wp_remote_retrieve_body( $r );
	$title = preg_match( '/<title>(.*?)<\/title>/is', $body, $m ) ? html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) : '';
	return array(
		'url'   => $url,
		'code'  => (int) wp_remote_retrieve_response_code( $r ),
		'title' => $title,
		'body'  => $body,
	);
}

function cov_admin_cookies() {
	$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$user  = $users[0] ?? null;
	if ( ! $user ) {
		return array( 0, array() );
	}
	wp_set_current_user( $user->ID );
	$exp = time() + DAY_IN_SECONDS;
	return array(
		(int) $user->ID,
		array(
			AUTH_COOKIE        => wp_generate_auth_cookie( $user->ID, $exp, is_ssl() ? 'secure_auth' : 'auth' ),
			SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user->ID, $exp, 'secure_auth' ),
			LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user->ID, $exp, 'logged_in' ),
		),
	);
}

function cov_catalog() {
	$paths = array(
		'/var/www/html/wp-content/plugins/wpmmcc-ats/../../../../projects/wptsall/wpmmcc-ats/tests/plugin-coverage/catalog.json',
		'/tmp/plugin-coverage/catalog.json',
	);
	foreach ( $paths as $p ) {
		if ( is_readable( $p ) ) {
			$data = json_decode( (string) file_get_contents( $p ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
	}
	return array();
}

require_once __DIR__ . '/cov-relations.php';
$cov_rels = cov_resolve_relations();
$REL_V    = (int) $cov_rels['virtual'];
$REL_W    = (int) $cov_rels['wp'];
foreach ( $cov_rels['notes'] as $note ) {
	$out['notes'][] = $note;
}
cov_ok( $out, 'relations_resolved', $REL_V > 0, array( 'virtual' => $REL_V, 'wp' => $REL_W ) );

function cov_plugin_file_for_slug( $slug ) {
	if ( 'wordpress-blog' === $slug ) {
		return '';
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( get_plugins() as $file => $data ) {
		$dir = dirname( $file );
		if ( $dir === $slug || $file === $slug || 0 === strpos( $dir, $slug ) ) {
			return $file;
		}
		$text = sanitize_key( (string) ( $data['TextDomain'] ?? '' ) );
		if ( $text && $text === $slug ) {
			return $file;
		}
	}
	return '';
}

function cov_isolate( $keep_file, $companions = array() ) {
	if ( ! function_exists( 'activate_plugin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$keep_self = array_merge( array( 'wpmmcc-ats/wpmmcc-ats.php', 'plugin-check/plugin.php' ), (array) $companions );
	$blogs     = array( 1 );
	if ( is_multisite() ) {
		$blogs[] = 2;
	}
	foreach ( array_unique( $blogs ) as $blog_id ) {
		$sw = false;
		if ( is_multisite() && (int) $blog_id !== get_current_blog_id() ) {
			switch_to_blog( (int) $blog_id );
			$sw = true;
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( in_array( $file, $keep_self, true ) || ( $keep_file && $file === $keep_file ) ) {
				continue;
			}
			if ( 0 === strpos( $file, 'wpmmcc-ats/' ) ) {
				continue;
			}
			deactivate_plugins( $file, true );
		}
		if ( $keep_file && ! is_plugin_active( $keep_file ) ) {
			activate_plugin( $keep_file, '', false, true );
		}
		foreach ( (array) $companions as $comp ) {
			if ( $comp && ! is_plugin_active( $comp ) ) {
				activate_plugin( $comp, '', false, true );
			}
		}
		if ( $sw ) {
			restore_current_blog();
		}
	}
}

function cov_pick_post_type( $hint ) {
	$hint = sanitize_key( (string) $hint );
	if ( $hint && post_type_exists( $hint ) ) {
		$obj = get_post_type_object( $hint );
		if ( $obj && ( $obj->public || $obj->show_ui ) ) {
			return $hint;
		}
	}
	// Do not pick leftover CPTs from plugins loaded earlier in the same PHP
	// process. Overlay plugins (SEO, ACF, builders) correctly use post/page.
	return 'post';
}

/**
 * Whether a post's permalink is a core-resolvable URL form.
 *
 * WordPress emits `?{query_var}={slug}` permalinks for CPTs registered with
 * a query var but no rewrite, but core only parses that var when the post
 * type is publicly viewable (WP_Post_Type::add_rewrite_rules() gates the
 * registration on is_post_type_viewable()). A non-viewable CPT permalink
 * (e.g. FooGallery `?foogallery=slug`) is therefore a core-level dead link
 * on ANY WordPress site; the resolvable address for the post is `?p=ID`.
 *
 * Used to decide whether the faithful permalink form is worth an attempt
 * before the `?p=ID` fallback candidate.
 *
 * @param int $post_id Post ID.
 * @return bool True when the permalink form can serve the post.
 */
function cov_permalink_usable( $post_id ) {
	$link = (string) get_permalink( $post_id );
	$path = rtrim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' );
	// The product's virtualize filter prefixes shadow permalinks (/en-us);
	// judge the form only after stripping that prefix.
	if ( 0 === strpos( $path, '/en-us' ) ) {
		$path = substr( $path, strlen( '/en-us' ) );
	}
	$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $path && $path !== $home ) {
		// Pretty path form (or deep path) — usable.
		return true;
	}
	$query = array();
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );
	foreach ( array( 'p', 'page_id' ) as $key ) {
		if ( isset( $query[ $key ] ) && ctype_digit( (string) $query[ $key ] ) ) {
			return true;
		}
	}
	if ( isset( $query['pagename'] ) && '' !== trim( (string) $query['pagename'] ) ) {
		return true;
	}
	$type = (string) get_post_type( $post_id );
	$pto  = $type ? get_post_type_object( $type ) : null;
	if ( $pto && ! empty( $pto->query_var ) && isset( $query[ $pto->query_var ] ) ) {
		// Query-form CPT permalink: resolvable only when core registers the var.
		return (bool) is_post_type_viewable( $type );
	}
	return false;
}

function cov_virtual_url( $home, $target_id ) {
	$link  = get_permalink( $target_id );
	$path  = (string) wp_parse_url( $link, PHP_URL_PATH );
	$query = (string) wp_parse_url( $link, PHP_URL_QUERY );
	$prefix = '/en-us';
	// The product's post_type_link virtualize filter prefixes CPT permalinks,
	// so the permalink may already carry the virtual prefix. Strip it before
	// the query-form check to avoid double-prefixing (a double prefix 404s).
	$bare = $path;
	if ( 0 === strpos( $bare, $prefix ) ) {
		$bare = substr( $bare, strlen( $prefix ) );
	}
	if ( '' === $bare || '/' === $bare ) {
		// Query-form permalink (path '/', e.g. ?topic=slug). Keep the form
		// when its query var is core-registered (viewable CPT — the virtual
		// router's parse_request single-object upgrade resolves it, exercised
		// e2e by this step). Otherwise (non-viewable CPT, e.g. ?foogallery=)
		// core never parses the var anywhere — WordPress design — so address
		// the shadow by the resolvable ?p=ID form.
		$type = (string) get_post_type( $target_id );
		$pto  = $type ? get_post_type_object( $type ) : null;
		$vars = array();
		parse_str( $query, $vars );
		if ( $pto && ! empty( $pto->query_var ) && isset( $vars[ $pto->query_var ] ) && is_post_type_viewable( $type ) ) {
			return $home . $prefix . '/?' . $query;
		}
		return $home . $prefix . '/?p=' . (int) $target_id;
	}
	if ( $path !== $bare ) {
		// Already-virtualized pretty path: use it as-is (no second prefix).
		return $home . $path . ( $query ? '?' . $query : '' );
	}
	return $home . $prefix . $path . ( $query ? '?' . $query : '' );
}

if ( '' === $slug ) {
	$out['status'] = 'error';
	$out['pass']   = false;
	$out['notes'][] = 'missing slug';
	echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) . "\n";
	return;
}

$catalog = cov_catalog();
$meta    = array();
foreach ( (array) ( $catalog['plugins'] ?? array() ) as $row ) {
	if ( ( $row['slug'] ?? '' ) === $slug ) {
		$meta = $row;
		break;
	}
}
$out['name']     = $meta['name'] ?? $slug;
$out['category'] = $meta['category'] ?? '';

$file = cov_plugin_file_for_slug( $slug );
if ( 'wordpress-blog' !== $slug && '' === $file ) {
	$out['status']  = 'not_installed';
	$out['pass']    = false;
	$out['notes'][] = 'plugin zip/file not present in lab';
	echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) . "\n";
	return;
}

// Companions required for the target plugin to activate (e.g. dokan needs WooCommerce).
$catalog_meta  = array();
foreach ( (array) ( $catalog['plugins'] ?? array() ) as $row ) {
	if ( ( $row['slug'] ?? '' ) === $slug ) {
		$catalog_meta = $row;
		break;
	}
}
$companions = $catalog_meta['companions'] ?? array();

cov_isolate( $file, $companions );
// Plugin activation via silent activate_plugin() skips the activation hook, so
// CPT rewrite rules are never persisted for the blog the plugin was activated
// on. orchestrate.py now does proper activation (real hooks) followed by a
// per-blog flush in a separate boot context for each blog BEFORE this runner
// executes. Flushing blog 2 FROM THIS PROCESS is wrong no matter how it is
// done: a fresh `new WP_Rewrite()` has no plugin permastructs (wipes every
// CPT rule from wp_2_options), and the loaded object carries BLOG 1's
// permastruct (/blog/Y/M/D/...), which stamps main-blog rules over the
// subsite's own /Y/M/D/ structure and 404s its date-based pretty permalinks.
// Only the main-blog flush belongs in-process (its context is correct here).
flush_rewrite_rules( true );
$isolated = ( 'wordpress-blog' === $slug ) || is_plugin_active( $file );
cov_ok( $out, 'isolated', $isolated, array( 'file' => $file, 'active' => get_option( 'active_plugins' ) ) );

$pt = cov_pick_post_type( $meta['primary_post_type'] ?? 'post' );
$out['post_type'] = $pt;
cov_ok( $out, 'post_type_ready', post_type_exists( $pt ), $pt );

if ( class_exists( '\\WPTSALL\\Core\\WPML_Config_Reader' ) ) {
	\WPTSALL\Core\WPML_Config_Reader::reset_cache();
}

$scan_slug = $slug;
if ( class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) && 'wordpress-blog' !== $slug ) {
	\WPTSALL\Models\Services\Plugin_Mapping_Service::scan_and_save_selected( array( $scan_slug ) );
}
$model = class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service' )
	? \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( $scan_slug )
	: null;
if ( ! $model && 'wordpress-blog' === $slug ) {
	$model = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
}
if ( $model ) {
	\WPTSALL\Models\Services\Translation_Rule_Service::create_rules_for_model( (int) $model['id'], array( 'skip_existing' => true ) );
	if ( $REL_V > 0 ) {
		\WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $REL_V, (int) $model['id'] );
	}
	if ( $REL_W > 0 ) {
		\WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $REL_W, (int) $model['id'] );
	}
}
$blog = \WPTSALL\Models\Services\Plugin_Mapping_Service::get_by_slug( 'wordpress-blog' );
if ( $blog ) {
	if ( $REL_V > 0 ) {
		\WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $REL_V, (int) $blog['id'] );
	}
	if ( $REL_W > 0 ) {
		\WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $REL_W, (int) $blog['id'] );
	}
}
cov_ok( $out, 'model_or_overlay', true, array( 'model_id' => $model['id'] ?? null, 'storage' => $model['storage'] ?? null ) );

$hooks = array();
if ( $REL_V > 0 ) {
	$hooks = array_merge( $hooks, \WPTSALL\Hooks\Hook_Manager::generate_auto_hooks( $REL_V ) );
}
if ( $REL_W > 0 ) {
	$hooks = array_merge( $hooks, \WPTSALL\Hooks\Hook_Manager::generate_auto_hooks( $REL_W ) );
}
$hnames = array();
foreach ( $hooks as $h ) {
	$hnames[] = $h['hook_name'];
}
$hnames = array_values( array_unique( $hnames ) );
cov_ok(
	$out,
	'hooks_listen_only',
	! in_array( 'template_include', $hnames, true ) && ! in_array( 'woocommerce_before_single_product', $hnames, true ),
	$hnames
);
$listen_pt = 'save_post_' . $pt;
cov_ok( $out, 'hooks_bound_post_type', in_array( $listen_pt, $hnames, true ) || in_array( 'save_post', $hnames, true ), $listen_pt );

/**
 * First administrator user ID.
 *
 * run-one.php executes via `wp eval-file --allow-root` with no --user, so
 * wp_insert_post() defaults post_author to 0. Plugin domain models that type
 * an owner field (MasterStudy Course::$owner : WP_User) then fatal on
 * get_userdata(0) === false. Test posts must carry a real author like any
 * admin-created content.
 *
 * @return int
 */
function cov_admin_user_id() {
	static $id = 0;
	if ( $id ) {
		return (int) $id;
	}
	$users = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) ) ?: array();
	$id    = (int) ( $users[0] ?? 0 );
	return $id;
}

$src_title = 'COV源 ' . $slug;
$en_title  = 'COV EN ' . $slug;
$wp_title  = 'COV SUB ' . $slug;
$src_name  = 'cov-' . $slug . '-' . substr( md5( (string) microtime( true ) ), 0, 6 );
$src_uid   = cov_admin_user_id();

$source_id = wp_insert_post(
	array(
		'post_type'    => $pt,
		'post_status'  => 'publish',
		'post_title'   => $src_title,
		'post_content' => '<p>Coverage source for ' . esc_html( $slug ) . '</p>',
		'post_excerpt' => 'source excerpt ' . $slug,
		'post_name'    => $src_name,
		'post_author'  => $src_uid,
	),
	true
);
if ( is_wp_error( $source_id ) && 'post' !== $pt ) {
	$pt        = 'post';
	$source_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => $src_title,
			'post_content' => '<p>Coverage fallback post for ' . esc_html( $slug ) . '</p>',
			'post_name'    => $src_name,
			'post_author'  => $src_uid,
		),
		true
	);
	$out['notes'][] = 'primary CPT insert failed; fell back to post';
	$out['post_type'] = 'post';
}
cov_ok( $out, 'create_source', ! is_wp_error( $source_id ) && (int) $source_id > 0, is_wp_error( $source_id ) ? $source_id->get_error_message() : (int) $source_id );
$source_id = is_wp_error( $source_id ) ? 0 : (int) $source_id;

$payload_v = array(
	'post_title'   => $en_title,
	'post_content' => '<p>Coverage English ' . $slug . '</p>',
	'post_excerpt' => 'en excerpt ' . $slug,
	'post_name'    => $src_name . '-en',
);
$payload_w = $payload_v;
$payload_w['post_title'] = $wp_title;
$payload_w['post_name']  = $src_name . '-sub';

$save_v = ( $source_id && $REL_V > 0 ) ? \WPTSALL\Sites\Services\Manual_Content_Service::save_translation( $source_id, $REL_V, $payload_v ) : new WP_Error( 'x', 'no source or virtual relation' );
$save_w = ( $source_id && $REL_W > 0 ) ? \WPTSALL\Sites\Services\Manual_Content_Service::save_translation( $source_id, $REL_W, $payload_w ) : new WP_Error( 'x', 'no source or wp relation' );
$tid_v  = ! is_wp_error( $save_v ) ? (int) $save_v['target_id'] : 0;
$tid_w  = ! is_wp_error( $save_w ) ? (int) $save_w['target_id'] : 0;
cov_ok( $out, 'map_virtual', $tid_v > 0, is_wp_error( $save_v ) ? $save_v->get_error_message() : $save_v );
cov_ok( $out, 'map_subsite', $tid_w > 0, is_wp_error( $save_w ) ? $save_w->get_error_message() : $save_w );

/**
 * Seed plugin-domain data that the target plugin's own frontend requires.
 *
 * A bare CPT post (title+content) is not a valid entity for every plugin:
 * their single views read plugin meta or companion records and fail —
 * fatally or with 404 — when it is missing. All root causes were verified
 * live on 2026-09-24 (see the ledger recheck round):
 * - Event Organiser: eo_format_datetime(false) fatal in its single
 *   template when the event has no schedule (eo_update_event() seeds it).
 * - Events Manager: its parse_query hook forces
 *   orderby=meta_value&_event_start_local on ALL frontend event queries;
 *   posts without that meta are INNER-JOIN excluded -> 404.
 * - The Events Calendar: single events without _EventStartDate are routed
 *   to the calendar -> 404.
 * - Download Monitor: a download without a file version answers its
 *   endpoint with "Download Error" 404; its permalink IS the endpoint.
 * Guarded by function_exists()/service checks so the runner stays
 * install-agnostic when a plugin is absent. Must be called with the ids
 * of the CURRENT blog only — subsite targets are seeded under
 * switch_to_blog(2) so meta/table writes land in wp_2_*.
 *
 * @param string $slug Slug under test.
 * @param int[]  $ids  Post IDs on the current blog.
 * @return array Notes about what was seeded.
 */
function cov_seed_plugin_data( $slug, $ids ) {
	$notes = array();
	$ids   = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	if ( empty( $ids ) ) {
		return $notes;
	}
	$n = count( $ids );

	if ( 'event-organiser' === $slug && function_exists( 'eo_update_event' ) && class_exists( 'DateTime' ) ) {
		$start = new DateTime( 'tomorrow 10:00' );
		$end   = new DateTime( 'tomorrow 12:00' );
		foreach ( $ids as $pid ) {
			$r = eo_update_event( $pid, array( 'start' => $start, 'end' => $end, 'schedule' => 'once' ) );
			if ( is_wp_error( $r ) ) {
				$notes[] = "eo seed failed on {$pid}: " . $r->get_error_message();
			}
		}
		$notes[] = "seeded eo schedule on {$n} post(s)";
	}

	if ( 'events-manager' === $slug && defined( 'EM_POST_TYPE_EVENT' ) ) {
		$meta = array(
			'_event_start_date'  => '2026-09-25',
			'_event_start_time'  => '10:00:00',
			'_event_end_date'    => '2026-09-25',
			'_event_end_time'    => '12:00:00',
			'_event_start'       => '2026-09-25 10:00:00',
			'_event_end'         => '2026-09-25 12:00:00',
			'_event_start_local' => '2026-09-25 10:00:00',
			'_event_end_local'   => '2026-09-25 12:00:00',
		);
		foreach ( $ids as $pid ) {
			foreach ( $meta as $k => $v ) {
				update_post_meta( $pid, $k, $v );
			}
		}
		$notes[] = "seeded em event date meta on {$n} post(s)";
	}

	if ( 'the-events-calendar' === $slug && defined( 'TRIBE_EVENTS_FILE' ) ) {
		$meta = array(
			'_EventStartDate'    => '2026-09-25 10:00:00',
			'_EventEndDate'      => '2026-09-25 12:00:00',
			'_EventStartDateUTC' => '2026-09-25 10:00:00',
			'_EventEndDateUTC'   => '2026-09-25 12:00:00',
			'_EventTimezone'     => 'UTC',
			'_EventTimezoneAbbr' => 'UTC',
		);
		foreach ( $ids as $pid ) {
			foreach ( $meta as $k => $v ) {
				update_post_meta( $pid, $k, $v );
			}
			// TEC 6.x custom-tables build their wp_tec_events /
			// wp_tec_occurrences rows for a new event ASYNCHRONOUSLY (seconds
			// after save, once a cron/AS tick lands). Single-event queries
			// JOIN those tables, so URL checks fired 1-10s after creation
			// deterministically 404 onto the calendar ("Upcoming Events")
			// even though the post and meta exist — verified live 2026-09-25
			// (post 03:39:09 -> fetch 03:39:18 404 -> occurrence row 03:39:22).
			// commit_post_updates() is TEC's own controller method for
			// synchronously committing a post's custom-table rows; with the
			// timezone meta seeded above it builds the occurrence instantly
			// (verified: occurrence row present + ?p= fetch 200 right away).
			if ( class_exists( '\TEC\Events\Custom_Tables\V1\Updates\Controller' ) && function_exists( 'tribe' ) ) {
				try {
					tribe( '\TEC\Events\Custom_Tables\V1\Updates\Controller' )->commit_post_updates( $pid );
				} catch ( Throwable $e ) {
					$notes[] = 'tec commit_post_updates failed: ' . $e->getMessage();
				}
			}
		}
		$notes[] = "seeded tec event date meta on {$n} post(s)";
	}

	if ( 'business-directory-plugin' === $slug && defined( 'WPBDP_POST_TYPE' ) ) {
		// Real activation re-arms BD's onboarding wizard, whose admin_init
		// redirect hijacks the FIRST admin hit (the harness's post.php edit
		// probe) to the wizard screen. Mark onboarding as skipped in the
		// current blog context — an already-set-up install never redirects.
		update_option( 'wpbdp_onboarding_skipped', true, 'no' );
		$notes[] = 'marked bd onboarding skipped';

		// BD only functions with a "main" page: it discovers the page by
		// scanning for the [businessdirectory] shortcode, and every listing
		// permalink is built from it. Without one (fresh install + wizard
		// skipped above) BD canonicalizes ?p= listing URLs to a broken
		// /{listing-slug}/{listing-slug}/ form that matches no rule and 404s
		// — the 2026-09-25 url_source/url_subsite failures. The official
		// fix-up path is the admin notice's "Create required pages for me"
		// button, which inserts exactly this page; replicate it. The page_ids
		// transients cache the (empty) lookup result, so clear them after
		// the insert or BD stays blind to the new page until expiry.
		// The existence check deliberately does NOT use wpbdp_get_page_id():
		// its static per-request cache is blog-blind, so after the main-blog
		// seed has warmed it, the subsite context would see the MAIN blog's
		// answer and skip creating its own page. Scan pages directly instead.
		$bd_shortcodes = array( '[businessdirectory]', '[business-directory]', '[WPBUSDIRMANUI]' );
		$bd_has_page   = false;
		foreach ( get_pages( array( 'post_status' => array( 'publish', 'private' ) ) ) as $bdp ) {
			foreach ( $bd_shortcodes as $bdsc ) {
				if ( false !== stripos( (string) $bdp->post_content, $bdsc ) ) {
					$bd_has_page = true;
					break 2;
				}
			}
		}
		if ( ! $bd_has_page ) {
			$page_id = wp_insert_post(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Business Directory',
					'post_type'    => 'page',
					'post_content' => '[businessdirectory]',
					'post_author'  => cov_admin_user_id(),
				)
			);
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				foreach ( array( 'main', 'add-listing', 'view-listings', 'manage-listings' ) as $p ) {
					delete_transient( "wpbdp_page_ids_{$p}" );
				}
				$notes[] = "created bd main page {$page_id}";
			} else {
				$notes[] = 'bd main page insert failed: ' . ( is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown' );
			}
		}
	}

	if ( 'the-events-calendar' === $slug && defined( 'TRIBE_EVENTS_FILE' ) ) {
		// Same class: TEC's activation redirect sends the first admin hit to
		// the Guided Setup page unless it was already visited.
		if ( function_exists( 'tribe_update_option' ) ) {
			tribe_update_option( 'tec_onboarding_wizard_visited_guided_setup', true );
			$notes[] = 'marked tec guided setup visited';
		}
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( '_tribe_events_activation_redirect' );
		}
	}

	if ( 'tutor' === $slug && defined( 'TUTOR_VERSION' ) ) {
		// Activation sets tutor_wizard and leaves tutor-new-feature below the
		// current version, so Admin::redirect_to_welcome_page() hijacks EVERY
		// admin hit to admin.php?page=tutor&welcome=1 (the Courses dashboard)
		// — and it never self-clears because wp_safe_redirect() exits before
		// the update_option() on the next line. Mark the welcome as seen in
		// the current blog context, the state a site that finished the
		// welcome screen would have.
		update_option( 'tutor-new-feature', TUTOR_VERSION );
		$notes[] = 'marked tutor welcome as seen';
	}

	if ( 'download-monitor' === $slug && function_exists( 'download_monitor' ) && class_exists( 'DLM_Download_Version' ) ) {		try {
			$upload = wp_upload_bits( 'cov-dlm-seed.txt', null, "download-monitor coverage seed\n" );
			if ( ! empty( $upload['url'] ) ) {
				$repo = download_monitor()->service( 'version_repository' );
				foreach ( $ids as $pid ) {
					// Official creation path (AjaxHandler::add_file): construct a
					// blank version — retrieve_single(0) throws "Version not found".
					$version = new DLM_Download_Version();
					$version->set_download_id( $pid );
					$version->set_author( cov_admin_user_id() );
					$version->set_version( '1.0' );
					// The official path (AjaxHandler::add_file) always sets a
					// date; a blank version has get_date() === null and
					// WordPressVersionRepository::persist() calls
					// get_date()->format() on the insert, fatalling the whole
					// run (the 2026-09-25 DLM plugin_fatal).
					$version->set_date( new DateTime( current_time( 'mysql' ) ) );
					$version->set_mirrors( array( $upload['url'] ) );
					$repo->persist( $version );
					if ( method_exists( download_monitor()->service( 'transient_manager' ), 'clear_versions_transient' ) ) {
						download_monitor()->service( 'transient_manager' )->clear_versions_transient( $pid );
					}
				}
				$notes[] = "seeded dlm file version on {$n} download(s)";
			} else {
				$notes[] = 'dlm seed file upload failed';
			}
		} catch ( Exception $e ) {
			$notes[] = 'dlm seed error: ' . $e->getMessage();
		}
	}

	return $notes;
}

if ( $source_id ) {
	// Main-blog context: source + virtual (same-blog shadow) posts.
	$seed_notes = cov_seed_plugin_data( $slug, array( $source_id, $tid_v ) );
	// Subsite context: the wp-relation target lives on blog 2 — meta and
	// plugin tables must be written against wp_2_* or the seeds land on a
	// wrong-blog post with the same numeric ID.
	if ( $tid_w && is_multisite() ) {
		switch_to_blog( 2 );
		$seed_notes_w = cov_seed_plugin_data( $slug, array( $tid_w ) );
		restore_current_blog();
		$seed_notes = array_merge( $seed_notes, $seed_notes_w );
	}
	foreach ( $seed_notes as $n ) {
		$out['notes'][] = $n;
	}
}

$map_v = $source_id ? \WPTSALL\Models\Services\Post_Mapping_Service::get_mapping( $source_id, get_post_type( $source_id ), 1, 'v_424' ) : null;
cov_ok( $out, 'mapping_row', is_array( $map_v ) && (int) $map_v['target_post_id'] === $tid_v, $map_v );

// URL-step semantics (url_source / url_virtual / url_subsite):
// - Candidates are tried in order (faithful permalink form first, then the
//   resolvable ?p= form); the first candidate that satisfies the assertion
//   passes and its form is recorded in the detail. Plugin-owned query vars
//   can be hijacked away from the single (The Events Calendar routes
//   ?tribe_events= to its calendar) — that is plugin behavior, not the
//   product's, so the ?p= candidate covers it while the detail keeps the
//   faithful attempt's status for attribution.
// - Non-viewable CPT posts (e.g. FooGallery galleries): WordPress core has
//   no frontend URL for them at all (query var never registered; ?p= is
//   access-controlled to 404). The vanilla surfaces (source/subsite) then
//   ASSERT the 404 — core access control honored, no content leak — while
//   the virtual surface serves the translated shadow (the product's own
//   surface) and still asserts 200 + translated title.
$src_type    = $source_id ? (string) get_post_type( $source_id ) : '';
$src_viewable = $src_type ? (bool) is_post_type_viewable( $src_type ) : true;

$src_candidates = array();
if ( $source_id ) {
	if ( $src_viewable && cov_permalink_usable( $source_id ) ) {
		$src_candidates[] = array( 'kind' => 'faithful', 'url' => (string) get_permalink( $source_id ) );
	}
	// The ?p= fallback must carry post_type for CPT posts: core restricts a
	// bare ?p= single lookup to post_type=post, so CPT singles 404 without it.
	// With it, canonical redirects to the (flushed) pretty form and serves.
	$src_pt = '';
	if ( $src_type && 'post' !== $src_type && post_type_exists( $src_type ) ) {
		$src_pt = ( 'page' === $src_type ) ? '?page_id=' : '?post_type=' . rawurlencode( $src_type ) . '&p=';
	} else {
		$src_pt = '?p=';
	}
	$src_candidates[] = array( 'kind' => 'p_fallback', 'url' => home_url( '/' . $src_pt . (int) $source_id ) );
}
$v_candidates = array();
if ( $tid_v ) {
	$v_candidates[] = array( 'kind' => 'faithful', 'url' => cov_virtual_url( $home, $tid_v ) );
	// Virtual ?p= needs no post_type: the virtual router resolves the shadow
	// via its template_redirect single setup before the main query restriction.
	$v_candidates[] = array( 'kind' => 'p_fallback', 'url' => $home . '/en-us/?p=' . (int) $tid_v );
}
$w_candidates = array();
if ( $tid_w && is_multisite() ) {
	switch_to_blog( 2 );
	if ( $src_viewable && cov_permalink_usable( $tid_w ) ) {
		$w_candidates[] = array( 'kind' => 'faithful', 'url' => (string) get_permalink( $tid_w ) );
	}
	$w_pt = '';
	if ( $src_type && 'post' !== $src_type && post_type_exists( $src_type ) ) {
		$w_pt = ( 'page' === $src_type ) ? '?page_id=' : '?post_type=' . rawurlencode( $src_type ) . '&p=';
	} else {
		$w_pt = '?p=';
	}
	$w_candidates[] = array( 'kind' => 'p_fallback', 'url' => home_url( '/' . $w_pt . (int) $tid_w ) );
	restore_current_blog();
}
$out['urls'] = array(
	'source'  => $src_candidates ? $src_candidates[0]['url'] : '',
	'virtual' => $v_candidates ? $v_candidates[0]['url'] : '',
	'wp'      => $w_candidates ? $w_candidates[0]['url'] : '',
);

/**
 * Fetch candidates until one satisfies the surface assertion.
 *
 * @param array  $candidates Ordered URL candidates.
 * @param string $expect     'single' (200 + title contains, optional not-contains), 'protected' (404 + no title leak), or 'endpoint' (200 with no HTML-title requirement — plugin-owned binary/download surfaces whose permalink IS an endpoint, e.g. Download Monitor's download link).
 * @param string $want       Title fragment that must appear (single) / must NOT appear (protected).
 * @param string $not_want   Title fragment that must NOT appear (single only).
 * @return array{hit: array|null, attempts: array} Winning response or null, plus per-attempt detail.
 */
function cov_try_url_candidates( $candidates, $expect, $want, $not_want = '' ) {
	$attempts = array();
	foreach ( (array) $candidates as $cand ) {
		$url = is_array( $cand ) ? (string) ( $cand['url'] ?? '' ) : (string) $cand;
		$kind = is_array( $cand ) ? (string) ( $cand['kind'] ?? 'candidate' ) : 'candidate';
		$h          = $url ? cov_http( $url ) : array( 'code' => 0, 'title' => '', 'body' => '' );
		$attempts[] = array( 'form' => $kind, 'url' => $url, 'code' => (int) $h['code'], 'title' => (string) $h['title'] );
		if ( 'protected' === $expect ) {
			// Non-viewable CPT: core must refuse the frontend single (404) and
			// must not leak the content title anywhere in the response title.
			if ( 404 === (int) $h['code'] && false === strpos( (string) $h['title'], $want ) && false === strpos( (string) $h['body'], $want ) ) {
				return array( 'hit' => $h, 'attempts' => $attempts );
			}
		} elseif ( 'endpoint' === $expect ) {
			// Plugin-owned endpoint surface (DLM download link): a served 200
			// is the contract — the body is a file stream, so no <title> can
			// exist to assert against.
			if ( 200 === (int) $h['code'] ) {
				return array( 'hit' => $h, 'attempts' => $attempts );
			}
		} elseif ( 200 === (int) $h['code'] && false !== strpos( (string) $h['title'], $want ) && ( '' === $not_want || false === strpos( (string) $h['title'], $not_want ) ) ) {
			return array( 'hit' => $h, 'attempts' => $attempts );
		}
	}
	return array( 'hit' => null, 'attempts' => $attempts );
}

// Endpoint-class plugins: the permalink IS a plugin-owned endpoint serving a
// file stream (Download Monitor download links, with the tmstv token baked
// in), not an HTML single. A served 200 is the surface contract; there is no
// <title> to assert. (The virtual surface still renders the translated
// shadow page normally and keeps its title assertion.)
$cov_endpoint_class = ( 'download-monitor' === $slug );

$src_expect = $cov_endpoint_class ? 'endpoint' : ( $src_viewable ? 'single' : 'protected' );
$src_try = cov_try_url_candidates( $src_candidates, $src_expect, $src_title );
$hv_try  = cov_try_url_candidates( $v_candidates, 'single', $en_title, $src_title );
$w_expect = $cov_endpoint_class ? 'endpoint' : ( $src_viewable ? 'single' : 'protected' );
$hw_try  = cov_try_url_candidates( $w_candidates, $w_expect, $wp_title );
$hs      = $src_try['hit'];
$hv      = $hv_try['hit'];
$hw      = $hw_try['hit'];

cov_ok( $out, 'url_source', ! empty( $hs ), array( 'viewable' => $src_viewable, 'attempts' => $src_try['attempts'] ) );
cov_ok( $out, 'url_virtual', ! empty( $hv ), array( 'attempts' => $hv_try['attempts'] ) );
cov_ok( $out, 'url_subsite', ! empty( $hw ), array( 'viewable' => $src_viewable, 'attempts' => $hw_try['attempts'] ) );

$lang_body = $hv ? (string) $hv['body'] : '';
$lang_ok = ( false !== strpos( $lang_body, 'hreflang' ) )
	|| ( false !== strpos( $lang_body, 'wptsall virtual site' ) )
	|| ( false !== strpos( $lang_body, '/en-us/' ) );
cov_ok( $out, 'lang_links_follow', $lang_ok && ! empty( $hv ), array(
	'hreflang'  => false !== strpos( $lang_body, 'hreflang' ),
	'switcher'  => false !== strpos( $lang_body, 'wptsall virtual site' ),
	'prefix'    => false !== strpos( $lang_body, '/en-us/' ),
) );

list( $uid, $cookies ) = cov_admin_cookies();
$admin_src = ( $source_id && $uid ) ? admin_url( 'post.php?post=' . $source_id . '&action=edit' ) : '';
$ha        = $admin_src ? cov_http( $admin_src, $cookies ) : array( 'code' => 0, 'title' => '', 'body' => '' );
cov_ok(
	$out,
	'target_plugin_admin_source',
	(int) $ha['code'] >= 200 && (int) $ha['code'] < 400 && ( false !== strpos( (string) $ha['body'], $src_title ) || false !== strpos( (string) $ha['title'], $src_title ) ),
	array( 'code' => $ha['code'], 'title' => $ha['title'] )
);
$admin_w = ( $tid_w && $uid ) ? get_admin_url( 2, 'post.php?post=' . $tid_w . '&action=edit' ) : '';
$haw     = $admin_w ? cov_http( $admin_w, $cookies ) : array( 'code' => 0, 'title' => '', 'body' => '' );
cov_ok(
	$out,
	'target_plugin_admin_subsite',
	(int) $haw['code'] >= 200 && (int) $haw['code'] < 400,
	array( 'code' => $haw['code'], 'title' => $haw['title'] )
);

if ( $source_id && is_array( $map_v ) ) {
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'wptsall_post_mappings', array( 'needs_resync' => 0 ), array( 'id' => (int) $map_v['id'] ), array( '%d' ), array( '%d' ) );
	wp_update_post( array( 'ID' => $source_id, 'post_title' => $src_title . ' *' ) );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT needs_resync FROM ' . $wpdb->prefix . 'wptsall_post_mappings WHERE id=%d', (int) $map_v['id'] ), ARRAY_A );
	cov_ok( $out, 'content_manage_dirty_mark', (int) ( $row['needs_resync'] ?? 0 ) === 1, $row );
}

$out['ids']     = array( 'source' => $source_id, 'virtual' => $tid_v, 'wp' => $tid_w, 'model' => $model['id'] ?? null );
$out['finished'] = gmdate( 'c' );
$out['status']   = $out['pass'] ? 'passed' : 'failed';
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) . "\n";
