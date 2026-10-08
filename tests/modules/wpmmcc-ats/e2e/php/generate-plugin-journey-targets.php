<?php
/**
 * Build Playwright journey targets for the current E2E project after translation.
 *
 * Output: runtime/plugin-journey-targets.json
 */

require_once __DIR__ . '/helpers.php';

$project   = e2e_project();
// core-content is the orchestrator / family label; Stage 8 specs live under wptsall-content.
if ( 'core-content' === $project ) {
	$project = 'wptsall-content';
}
$specs     = e2e_plugin_project_specs();
$spec      = $specs[ $project ] ?? null;
$yaml_path = dirname( __DIR__ ) . '/plugin-journeys.yaml';

if ( ! is_array( $spec ) ) {
	echo "ERROR: unknown E2E project {$project}\n";
	exit( 1 );
}

function e2e_pjt_yaml_path_token( string $raw ): string {
	// Flow-style YAML lines look like: path: /shop/, expect_selector: ...
	return rtrim( trim( $raw, " \"/" ), ',;' );
}

function e2e_pjt_load_yaml_plugins( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return array();
	}
	$raw   = file_get_contents( $path );
	$lines = explode( "\n", (string) $raw );
	$in    = false;
	$buf   = '';
	foreach ( $lines as $line ) {
		if ( preg_match( '/^plugins:\s*$/', $line ) ) {
			$in = true;
			continue;
		}
		if ( $in && preg_match( '/^[a-z].*:/', $line ) && ! preg_match( '/^  /', $line ) ) {
			break;
		}
		if ( $in ) {
			$buf .= $line . "\n";
		}
	}
	// Minimal parse: project keys at column 0 under plugins:
	$plugins = array();
	$current = null;
	foreach ( explode( "\n", $buf ) as $line ) {
		if ( preg_match( '/^  ([a-z0-9-]+-content):\s*$/', $line, $m ) ) {
			$current = $m[1];
			$plugins[ $current ] = array();
			continue;
		}
		if ( null === $current ) {
			continue;
		}
		if ( preg_match( '/slug:\s*(\S+)/', $line, $m ) ) {
			$plugins[ $current ]['slug'] = $m[1];
		}
		if ( preg_match( '/member_center:\s*\{\s*path:\s*([^,\s}]+)/', $line, $m ) ) {
			$plugins[ $current ]['member_center'] = array( 'path' => e2e_pjt_yaml_path_token( $m[1] ) );
			if ( preg_match( '/\boptional:\s*(true|1|yes)/', $line ) ) {
				$plugins[ $current ]['member_center']['optional'] = true;
			}
			// Optional: page_option: option_key or nested option_key.sub_key (e.g. edd_settings.purchase_history_page)
			if ( preg_match( '/\bpage_option:\s*([A-Za-z0-9_.-]+)/', $line, $om ) ) {
				$plugins[ $current ]['member_center']['page_option'] = $om[1];
			}
		}
		if ( preg_match( '/admin_native:\s*\{\s*path:\s*([^,\s}]+)/', $line, $m ) ) {
			$plugins[ $current ]['admin_native'] = array( 'path' => e2e_pjt_yaml_path_token( $m[1] ) );
			if ( preg_match( '/\boptional:\s*(true|1|yes)/', $line ) ) {
				$plugins[ $current ]['admin_native']['optional'] = true;
			}
		}
		if ( preg_match( '/storefront:\s*\{/', $line ) ) {
			if ( preg_match( '/path:\s*([^,\s}]+)/', $line, $m ) ) {
				$plugins[ $current ]['storefront'] = array( 'path' => e2e_pjt_yaml_path_token( $m[1] ) );
			} else {
				$plugins[ $current ]['storefront'] = array();
			}
			if ( preg_match( '/\boptional:\s*(true|1|yes)/', $line ) ) {
				$plugins[ $current ]['storefront']['optional'] = true;
			}
			if ( preg_match( '/virtual_optional:\s*(true|1|yes)/', $line ) ) {
				$plugins[ $current ]['storefront']['virtual_optional'] = true;
			}
		}
		if ( preg_match( '/archive:\s*\{\s*path:\s*([^,\s}]+)/', $line, $m ) ) {
			$plugins[ $current ]['archive'] = array( 'path' => e2e_pjt_yaml_path_token( $m[1] ) );
			if ( preg_match( '/\boptional:\s*(true|1|yes)/', $line ) ) {
				$plugins[ $current ]['archive']['optional'] = true;
			}
		}
	}
	return $plugins;
}

function e2e_pjt_relation_virtual(): ?array {
	require_once __DIR__ . '/resolve-virtual-frontend-target.php';
	return e2e_vft_resolve_relation();
}

function e2e_pjt_path_prefix( array $relation ): string {
	require_once __DIR__ . '/resolve-virtual-frontend-target.php';
	$prefix = e2e_vft_path_prefix( $relation );
	return '' !== $prefix ? '/' . trim( $prefix, '/' ) . '/' : '/en_us/';
}

/**
 * Resolve a front-end path from a WP option holding a page ID.
 *
 * Spec forms: "myaccount_page_id" or "edd_settings.purchase_history_page"
 * (dot = nested array key under the named option).
 */
function e2e_pjt_path_from_page_option( string $spec ): ?string {
	$spec = trim( $spec );
	if ( '' === $spec ) {
		return null;
	}
	$page_id = 0;
	if ( false !== strpos( $spec, '.' ) ) {
		list( $option_name, $sub_key ) = explode( '.', $spec, 2 );
		$raw = get_option( $option_name, null );
		if ( is_array( $raw ) && isset( $raw[ $sub_key ] ) ) {
			$page_id = (int) $raw[ $sub_key ];
		}
	} else {
		$page_id = (int) get_option( $spec, 0 );
	}
	if ( $page_id <= 0 ) {
		return null;
	}
	$permalink = get_permalink( $page_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return null;
	}
	$rel = wp_make_link_relative( $permalink );
	$rel = '/' . ltrim( (string) $rel, '/' );
	return trailingslashit( $rel );
}

/**
 * Whether a CPT is meant to render as a standalone public URL.
 *
 * Plugins such as Envira Gallery Lite / WP Recipe Maker register publishable
 * admin CPTs with publicly_queryable=false (embed/shortcode only). Guest
 * journeys against those singles always 404 on the main site — skip them and
 * rely on admin_native + virtual root instead.
 */
/**
 * Plugins like WP Job Manager register CPT caps on custom roles only.
 * Journey admin_native uses the Lab smoke administrator — grant that role the
 * CPT's registered caps (generic; no per-plugin hardcoding).
 */
function e2e_pjt_ensure_administrator_cpt_caps( string $post_type ): void {
	if ( '' === $post_type ) {
		return;
	}
	$obj = get_post_type_object( $post_type );
	if ( ! $obj || empty( $obj->cap ) ) {
		return;
	}
	$role = get_role( 'administrator' );
	if ( ! $role ) {
		return;
	}
	foreach ( (array) $obj->cap as $cap ) {
		if ( ! is_string( $cap ) || '' === $cap || 'read' === $cap ) {
			continue;
		}
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}

function e2e_pjt_post_type_is_public_front( string $post_type ): bool {
	if ( '' === $post_type ) {
		return false;
	}
	$obj = get_post_type_object( $post_type );
	if ( ! $obj instanceof \WP_Post_Type ) {
		return false;
	}
	return ! empty( $obj->publicly_queryable );
}

/**
 * Whether get_permalink() for this post resolves on the public front end.
 *
 * CPT plugins may keep publish posts that the main query still excludes
 * (empty required meta, expired listings). Prefer posts whose rewrite
 * permalink maps back to the same post id — generic across CPT rewrite bases.
 */
function e2e_pjt_permalink_resolves( int $post_id, string $post_type, string $post_name ): bool {
	// Non-public CPTs still return WP_Query hits and ?post_type=&p= permalinks,
	// but the front controller answers 404 — never treat them as guest targets.
	if ( ! e2e_pjt_post_type_is_public_front( $post_type ) ) {
		return false;
	}
	$permalink = get_permalink( $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return false;
	}
	$resolved = (int) url_to_postid( $permalink );
	if ( $resolved === $post_id ) {
		return true;
	}
	// Some CPTs use rewrite tags url_to_postid misses; fall back to name query
	// (respects CPT pre_get_posts filters the same way the front controller does).
	$q = new WP_Query(
		array(
			'name'                   => $post_name,
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$ids = is_array( $q->posts ) ? $q->posts : array();
	if ( empty( $ids ) || (int) $ids[0] !== $post_id ) {
		return false;
	}
	return e2e_pjt_url_http_accessible( $permalink );
}

/**
 * Confirm the public front end returns a non-404 for this URL (rewrite flush gaps, EM/TEC meta).
 */
function e2e_pjt_url_http_accessible( string $url ): bool {
	if ( '' === trim( $url ) ) {
		return false;
	}
	$home = home_url( '/' );
	if ( 0 === strpos( $url, '/' ) ) {
		$url = trailingslashit( $home ) . ltrim( $url, '/' );
	}
	$resp = wp_remote_head(
		$url,
		array(
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => false,
		)
	);
	if ( is_wp_error( $resp ) ) {
		return false;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code >= 200 && $code < 400 ) {
		return true;
	}
	// Some CPT singles reject HEAD; fall back to lightweight GET.
	if ( 404 === $code || 405 === $code ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 3,
				'sslverify'   => false,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
	}
	return $code >= 200 && $code < 400;
}

function e2e_pjt_sample_post( string $post_type ): ?array {
	global $wpdb;
	// Prefer a real source CPT: skip virtual-site shadow copies so guest_source_cpt
	// does not land on a translated slug that 404s on the main site and triggers
	// permalink_fallback redirects.
	// Among source rows, prefer the richest body so thin CPT stubs (e.g. short
	// job listings ~500–700 visible chars on block themes) do not fail the
	// generic guest_min_body_chars completeness check. Then require a publicly
	// resolvable permalink so incomplete CPT fixtures (empty required meta) are skipped.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_name, p.post_title
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} vm
			     ON vm.post_id = p.ID AND vm.meta_key = '_wptsall_virtual_site_id'
			  WHERE p.post_type = %s
			    AND p.post_status = 'publish'
			    AND p.post_name <> ''
			    AND vm.meta_id IS NULL
			  ORDER BY CHAR_LENGTH( TRIM( p.post_content ) ) DESC, p.ID DESC
			  LIMIT 40",
			$post_type
		),
		ARRAY_A
	);
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return null;
	}
	foreach ( $rows as $row ) {
		$id   = (int) ( $row['ID'] ?? 0 );
		$name = (string) ( $row['post_name'] ?? '' );
		if ( $id <= 0 || '' === $name ) {
			continue;
		}
		if ( e2e_pjt_permalink_resolves( $id, $post_type, $name ) ) {
			return $row;
		}
	}
	return null;
}

function e2e_pjt_virtual_permalink( string $source_path, string $prefix ): string {
	$home = home_url( '/' );
	$rel  = wp_make_link_relative( $source_path );
	$rel  = ltrim( (string) $rel, '/' );
	$pref = trim( $prefix, '/' );
	return trailingslashit( $home ) . $pref . '/' . $rel;
}

/**
 * Resolve a YAML front path to a concrete public URL.
 *
 * Prefers non-virtual published pages (by full path, then leaf slug) so short
 * paths like /order-history/ map to nested permalinks (/checkout-2/order-history/)
 * instead of virtual-site shadow pages that own the bare slug. Falls back to
 * home_url(path) for CPT archives and other non-page routes.
 */
function e2e_pjt_resolve_path_url( string $path ): string {
	global $wpdb;

	$rel = trim( $path, '/' );
	if ( '' === $rel ) {
		return trailingslashit( home_url( '/' ) );
	}

	$page = get_page_by_path( $rel );
	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		$vs = get_post_meta( $page->ID, '_wptsall_virtual_site_id', true );
		if ( ! $vs ) {
			$permalink = get_permalink( $page );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return trailingslashit( $permalink );
			}
		}
	}

	$leaf = basename( $rel );
	$id   = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} vm
			     ON vm.post_id = p.ID AND vm.meta_key = '_wptsall_virtual_site_id'
			  WHERE p.post_type = 'page'
			    AND p.post_status = 'publish'
			    AND p.post_name = %s
			    AND vm.meta_id IS NULL
			  ORDER BY p.post_parent ASC, p.ID ASC
			  LIMIT 1",
			$leaf
		)
	);
	if ( $id > 0 ) {
		$permalink = get_permalink( $id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			return trailingslashit( $permalink );
		}
	}

	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		$permalink = get_permalink( $page );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			return trailingslashit( $permalink );
		}
	}

	return trailingslashit( home_url( '/' . $rel . '/' ) );
}

function e2e_pjt_guest_min_body_chars( string $post_type ): int {
	$thin = array(
		'hp_listing'  => 250,
		'job_listing' => 600,
		'at_biz_dir'  => 120,
		'event'       => 600,
		'tribe_events'=> 600,
		'forum'       => 600,
		'topic'       => 600,
		'product'     => 500,
		'give_forms'  => 250,
	);
	if ( isset( $thin[ $post_type ] ) ) {
		return (int) $thin[ $post_type ];
	}
	return 800;
}

function e2e_pjt_add_journey( array &$out, string $id, string $role, string $topology, string $url, array $meta = array() ): void {
	if ( '' === $url ) {
		return;
	}
	$out[] = array_merge(
		array(
			'id'       => $id,
			'role'     => $role,
			'topology' => $topology,
			'url'      => $url,
		),
		$meta
	);
}

$plugin_cfg = e2e_pjt_load_yaml_plugins( $yaml_path );
$cfg        = $plugin_cfg[ $project ] ?? array();
$journeys   = array();
$relation   = e2e_pjt_relation_virtual();
$v_prefix   = $relation ? e2e_pjt_path_prefix( $relation ) : '/en_us/';

$post_type = (string) ( $spec['verifier']['source_post_type'] ?? '' );
if ( '' === $post_type && ! empty( $spec['content_types'][0] ) ) {
	$post_type = (string) $spec['content_types'][0];
}

// Guest CPT singles only when the type is publicly queryable (Envira/WPRM/etc.
// are admin+shortcode CPTs — their ?post_type=&p= URLs 404 for guests).
$sample = ( $post_type && e2e_pjt_post_type_is_public_front( $post_type ) )
	? e2e_pjt_sample_post( $post_type )
	: null;
if ( $sample ) {
	$source_url = get_permalink( (int) $sample['ID'] );
	$content_len = strlen( trim( (string) wp_strip_all_tags( (string) get_post_field( 'post_content', (int) $sample['ID'] ) ) ) );
	$min_chars   = e2e_pjt_guest_min_body_chars( $post_type );
	$guest_optional = $content_len < 80 || $content_len < $min_chars;
	e2e_pjt_add_journey(
		$journeys,
		'guest_source_cpt',
		'guest',
		'source',
		$source_url,
		array(
			'post_type' => $post_type,
			'post_id'   => (int) $sample['ID'],
			'expect_translation_marker' => true,
			'min_body_chars'            => e2e_pjt_guest_min_body_chars( $post_type ),
			'optional'                  => $guest_optional,
		)
	);
	e2e_pjt_add_journey(
		$journeys,
		'guest_virtual_cpt',
		'guest',
		'virtual',
		e2e_pjt_virtual_permalink( $source_url, $v_prefix ),
		array(
			'post_type' => $post_type,
			'post_id'   => (int) $sample['ID'],
			'expect_translation_marker' => true,
			'min_body_chars'            => e2e_pjt_guest_min_body_chars( $post_type ),
			'optional'                  => $guest_optional,
		)
	);
}

e2e_pjt_add_journey(
	$journeys,
	'guest_virtual_root',
	'guest',
	'virtual',
	trailingslashit( home_url( $v_prefix ) ),
	array(
		// Locale homepage shells are often short (~300 chars) even when HTTP 200.
		'expect_translation_marker' => true,
		'min_body_chars'            => 200,
	)
);

foreach ( array( 'member_center', 'storefront', 'archive' ) as $key ) {
	if ( empty( $cfg[ $key ]['path'] ) && empty( $cfg[ $key ]['page_option'] ) ) {
		continue;
	}
	$path       = ! empty( $cfg[ $key ]['path'] ) ? '/' . ltrim( (string) $cfg[ $key ]['path'], '/' ) : '';
	$source_url = '';
	// Prefer WP page-ID options (e.g. edd_settings.purchase_history_page) when present.
	if ( ! empty( $cfg[ $key ]['page_option'] ) ) {
		$from_opt = e2e_pjt_path_from_page_option( (string) $cfg[ $key ]['page_option'] );
		if ( is_string( $from_opt ) && '' !== $from_opt ) {
			$source_url = trailingslashit( home_url( $from_opt ) );
		}
	}
	if ( '' === $source_url && '' !== $path ) {
		$source_url = e2e_pjt_resolve_path_url( $path );
	}
	if ( '' === $source_url ) {
		continue;
	}
	$optional = ! empty( $cfg[ $key ]['optional'] );
	e2e_pjt_add_journey(
		$journeys,
		'member_' . $key . '_source',
		'member',
		'source',
		$source_url,
		array( 'optional' => $optional )
	);
	e2e_pjt_add_journey(
		$journeys,
		'member_' . $key . '_virtual',
		'member',
		'virtual',
		e2e_pjt_virtual_permalink( $source_url, $v_prefix ),
		array(
			'optional' => $optional || ! empty( $cfg[ $key ]['virtual_optional'] ),
		)
	);
}

if ( ! empty( $cfg['admin_native']['path'] ) ) {
	$admin_path = (string) $cfg['admin_native']['path'];
	if ( '/' !== $admin_path[0] ) {
		$admin_path = '/' . $admin_path;
	}
	// Ensure smoke admin can open CPT list screens (custom-cap plugins).
	e2e_pjt_ensure_administrator_cpt_caps( $post_type );
	if ( preg_match( '/[?&]post_type=([a-z0-9_-]+)/i', $admin_path, $ptm ) ) {
		e2e_pjt_ensure_administrator_cpt_caps( (string) $ptm[1] );
	}
	e2e_pjt_add_journey(
		$journeys,
		'admin_native',
		'admin',
		'source',
		home_url( $admin_path ),
		array( 'optional' => ! empty( $cfg['admin_native']['optional'] ) )
	);
}

e2e_pjt_add_journey(
	$journeys,
	'admin_wptsall_tasks',
	'admin',
	'source',
	admin_url( 'admin.php?page=wptsall-tasks&tab=monitoring' ),
	array( 'optional' => ( 'wptsall-content' === $project ) ? false : true )
);

$subsite_id = 0;
if ( is_multisite() ) {
	$sites = get_sites( array( 'number' => 5, 'offset' => 1 ) );
	if ( ! empty( $sites[0]->blog_id ) ) {
		$subsite_id = (int) $sites[0]->blog_id;
	}
}

if ( $subsite_id > 0 && $sample ) {
	switch_to_blog( $subsite_id );
	$sub_url = get_permalink( (int) $sample['ID'] );
	if ( $sub_url ) {
		e2e_pjt_add_journey( $journeys, 'guest_subsite_cpt', 'guest', 'subsite', $sub_url, array( 'blog_id' => $subsite_id ) );
	}
	restore_current_blog();
}

$payload = array(
	'generated_at'   => gmdate( 'c' ),
	'project'        => $project,
	'plugin_slug'    => (string) ( $spec['plugin_slug'] ?? '' ),
	'virtual_prefix' => $v_prefix,
	'sub_site_id'    => $subsite_id,
	'journeys'       => $journeys,
);

$out = e2e_runtime_file( 'plugin-journey-targets.json' );
wp_mkdir_p( dirname( $out ) );
file_put_contents( $out, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
echo "Wrote {$out} journeys=" . count( $journeys ) . "\n";
