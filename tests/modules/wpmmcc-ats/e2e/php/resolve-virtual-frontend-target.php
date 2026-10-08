<?php
/**
 * Resolve the current E2E virtual frontend target.
 *
 * Stage 7 must verify the virtual relation that the Client actually processed,
 * not a historical hardcoded /en/ or v_en assumption.
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

function e2e_vft_read_json_file( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $data ) ? $data : array();
}

function e2e_vft_relation_row( int $relation_id ): ?array {
	global $wpdb;
	$table = e2e_table( 'site_relations' );
	if ( $relation_id <= 0 || ! e2e_table_exists( $table ) ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, source_site_id, target_site_id, target_site_type, target_lang,
			        target_theme_name, target_theme_path, status, created_at, updated_at
			   FROM {$table}
			  WHERE id = %d AND target_site_type = 'virtual'
			  LIMIT 1",
			$relation_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

function e2e_vft_virtual_post_count( string $target_site_id ): int {
	global $wpdb;
	if ( '' === $target_site_id ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$wpdb->postmeta}
			  WHERE meta_key = '_wptsall_virtual_site_id'
			    AND meta_value = %s",
			$target_site_id
		)
	);
}

function e2e_vft_resolve_relation(): ?array {
	global $wpdb;

	$candidates = array();

	$summary = e2e_vft_read_json_file( e2e_runtime_file( 'official-client-execution-summary.json' ) );
	if ( ! empty( $summary['selected_relation_id'] ) ) {
		$candidates[] = (int) $summary['selected_relation_id'];
	}

	$relation_ids = e2e_load_relation_ids();
	if ( ! empty( $relation_ids['virtual'] ) ) {
		$candidates[] = (int) $relation_ids['virtual'];
	}

	foreach ( array_values( array_unique( array_filter( $candidates ) ) ) as $relation_id ) {
		$row = e2e_vft_relation_row( (int) $relation_id );
		if ( ! $row ) {
			continue;
		}
		$row['virtual_posts'] = e2e_vft_virtual_post_count( (string) $row['target_site_id'] );
		if ( (int) $row['virtual_posts'] > 0 ) {
			return $row;
		}
	}

	$table = e2e_table( 'site_relations' );
	if ( ! e2e_table_exists( $table ) ) {
		return null;
	}

	$tasks_table = e2e_table( 'tasks' );
	$tasks_join  = e2e_table_exists( $tasks_table )
		? "COALESCE((SELECT COUNT(*) FROM {$tasks_table} t WHERE t.relation_id = r.id AND t.status = 'completed'), 0)"
		: '0';

	$row = $wpdb->get_row(
		"SELECT r.id, r.source_site_id, r.target_site_id, r.target_site_type, r.target_lang,
		        r.target_theme_name, r.target_theme_path, r.status, r.created_at, r.updated_at,
		        COALESCE(vp.virtual_posts, 0) AS virtual_posts,
		        {$tasks_join} AS completed_tasks
		   FROM {$table} r
		   LEFT JOIN (
		        SELECT meta_value AS target_site_id, COUNT(*) AS virtual_posts
		          FROM {$wpdb->postmeta}
		         WHERE meta_key = '_wptsall_virtual_site_id'
		         GROUP BY meta_value
		   ) vp ON vp.target_site_id = r.target_site_id
		  WHERE r.target_site_type = 'virtual'
		    AND r.status = 'active'
		  ORDER BY virtual_posts DESC, completed_tasks DESC, r.id DESC
		  LIMIT 1",
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

function e2e_vft_path_prefix( array $relation ): string {
	$relation_id = (int) ( $relation['id'] ?? 0 );

	if ( $relation_id > 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
		$site = \WPTSALL\Sites\Services\Virtual_Site_Service::get_by_relation( $relation_id );
		if ( is_array( $site ) && ! empty( $site['path_prefix'] ) ) {
			return trim( (string) $site['path_prefix'], '/' );
		}
	}

	$path = trim( (string) ( $relation['target_theme_path'] ?? '' ), '/' );
	if ( '' !== $path ) {
		return $path;
	}

	$lang = (string) ( $relation['target_lang'] ?? '' );
	if ( '' !== $lang ) {
		return sanitize_title( $lang );
	}

	$target = preg_replace( '/^v_/', '', (string) ( $relation['target_site_id'] ?? '' ) );
	return '' !== $target ? sanitize_title( $target ) : 'virtual';
}

/**
 * Directorist (and similar) "search-home" CPT shells fatally render under
 * virtual path prefixes in Lab (HTTP 500). Never use them as verify targets.
 */
function e2e_vft_is_fragile_search_shell( string $post_name_or_path ): bool {
	$slug = strtolower( trim( $post_name_or_path, '/' ) );
	if ( '' === $slug ) {
		return false;
	}
	// Match path segments or bare post_name: search-home, search-home-2, search_home.
	if ( (bool) preg_match( '/(^|\/)search[-_]home(\d*|-\d+)?(\/|$)/', $slug )
		|| (bool) preg_match( '/(^|\/)search-home/', $slug )
		|| (bool) preg_match( '/(^|\/)search_home/', $slug ) ) {
		return true;
	}
	// WooCommerce auth/cart shells commonly 302 under virtual prefixes in Lab.
	return (bool) preg_match( '/(^|\/)(my-account|cart|checkout)(-\d+)?(\/|$)/', $slug );
}

/**
 * Whether a translated wp_posts row can be probed by the virtual frontend as a
 * direct slug.  Matrix slots intentionally preserve source content across
 * lanes, so virtual write-back can contain stale plugin/internal post types
 * whose provider is not active in the current lane (for example wp_navigation
 * or a previous Tutor lesson).  Those rows prove write-back but are not stable
 * frontend route targets.
 */
function e2e_vft_is_frontend_routable_post_type( string $post_type ): bool {
	$post_type = sanitize_key( $post_type );
	if ( '' === $post_type ) {
		return false;
	}

	$internal = array(
		'custom_css'           => true,
		'customize_changeset'  => true,
		'oembed_cache'         => true,
		'revision'             => true,
		'user_request'         => true,
		'wp_block'             => true,
		'wp_global_styles'     => true,
		'wp_navigation'        => true,
		'wp_template'          => true,
		'wp_template_part'     => true,
		// Elementor templates are "public" but use ?elementor_library= slug
		// permalinks that 404 under virtual path prefixes (not a page shell).
		'elementor_library'    => true,
	);
	if ( isset( $internal[ $post_type ] ) ) {
		return false;
	}

	if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
		return true;
	}

	$obj = get_post_type_object( $post_type );
	if ( ! $obj ) {
		return false;
	}

	if ( empty( $obj->public ) ) {
		return false;
	}

	if ( isset( $obj->publicly_queryable ) && false === $obj->publicly_queryable ) {
		return false;
	}

	return true;
}

function e2e_vft_sample_virtual_path( array $sample, string $path_prefix ): string {
	$post_id   = (int) ( $sample['ID'] ?? 0 );
	$post_name = trim( (string) ( $sample['post_name'] ?? '' ), '/' );
	$prefix    = trim( $path_prefix, '/' );
	$root      = '' === $prefix ? '/' : '/' . $prefix . '/';

	if ( $post_id > 0 ) {
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$relative = wp_make_link_relative( $permalink );
			$relative = '/' . ltrim( (string) $relative, '/' );
			// Query-string CPT permalinks (e.g. ?elementor_library=) are not stable
			// under virtual path prefixes — fall back to slug path or skip later.
			if ( false === strpos( $relative, '?' ) && ( '/' === $root || 0 === strpos( $relative, $root ) ) ) {
				return trailingslashit( $relative );
			}
		}
	}

	return '' !== $post_name ? trailingslashit( $root . $post_name ) : $root;
}

function e2e_vft_samples( string $target_site_id, string $path_prefix, int $limit = 15 ): array {
	global $wpdb;
	if ( '' === $target_site_id ) {
		return array();
	}

	// Prefer ordinary post/page samples over plugin CPT "search" shells that often
	// fatally render under virtual path prefixes (e.g. Directorist search-home-*).
	// Fetch extra rows so we can still return $limit after dropping fragile shells.
	$fetch = max( $limit * 3, 30 );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_name, p.post_title
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} pm
			           ON p.ID = pm.post_id
			          AND pm.meta_key = '_wptsall_virtual_site_id'
			  WHERE pm.meta_value = %s
			    AND p.post_status = 'publish'
			    AND p.post_name <> ''
			  ORDER BY CASE
			             WHEN p.post_type IN ('post', 'page') THEN 0
			             ELSE 1
			           END,
			           CASE
			             WHEN LOWER(p.post_name) LIKE '%%search-home%%'
			               OR LOWER(p.post_name) LIKE '%%search_home%%'
			               OR LOWER(p.post_name) LIKE 'search%%'
			             THEN 1 ELSE 0
			           END,
			           CASE
			             WHEN LOWER(p.post_name) LIKE '%%e3%%80%%90%%'
			               OR LOWER(p.post_name) LIKE '%%e3%%80%%91%%'
			               OR p.post_name LIKE '%%【%%'
			               OR p.post_name LIKE '%%】%%'
			             THEN 1 ELSE 0
			           END,
			           p.ID DESC
			  LIMIT %d",
			$target_site_id,
			$fetch
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$filtered = array();
	foreach ( $rows as $row ) {
		$name      = (string) ( $row['post_name'] ?? '' );
		$post_type = (string) ( $row['post_type'] ?? '' );
		if ( ! e2e_vft_is_frontend_routable_post_type( $post_type ) ) {
			continue;
		}
		if ( e2e_vft_is_fragile_search_shell( $name ) ) {
			continue;
		}
		$row['virtual_path'] = e2e_vft_sample_virtual_path( $row, $path_prefix );
		$filtered[] = $row;
		if ( count( $filtered ) >= $limit ) {
			break;
		}
	}

	return $filtered;
}

function e2e_vft_pick_sample_path( string $frontend_path, array $samples ): string {
	$prefix = trim( $frontend_path, '/' );
	$root   = '' === $prefix ? '/' : '/' . $prefix . '/';

	foreach ( $samples as $sample ) {
		$name      = trim( (string) ( $sample['post_name'] ?? '' ), '/' );
		$post_type = (string) ( $sample['post_type'] ?? '' );
		$path      = trim( (string) ( $sample['virtual_path'] ?? '' ) );
		if ( '' === $name || '' === $path || ! e2e_vft_is_frontend_routable_post_type( $post_type ) || e2e_vft_is_fragile_search_shell( $name ) ) {
			continue;
		}
		return $path;
	}

	// No safe sample: Stage 7 must verify the virtual root, never search-home*.
	return $root;
}

$relation = e2e_vft_resolve_relation();
if ( ! $relation ) {
	echo wp_json_encode(
		array(
			'ok'    => false,
			'error' => 'virtual_relation_not_found',
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( 0 );
}

$target_site_id = (string) ( $relation['target_site_id'] ?? '' );
$path_prefix    = e2e_vft_path_prefix( $relation );
$frontend_path  = '/' . trim( $path_prefix, '/' ) . '/';
$samples        = e2e_vft_samples( $target_site_id, $path_prefix );
$sample_path    = e2e_vft_pick_sample_path( $frontend_path, $samples );

echo wp_json_encode(
	array(
		'ok'             => true,
		'relation_id'    => (int) $relation['id'],
		'target_site_id' => $target_site_id,
		'path_prefix'    => $path_prefix,
		'frontend_path'  => $frontend_path,
		'sample_path'    => $sample_path,
		'virtual_posts'  => (int) ( $relation['virtual_posts'] ?? e2e_vft_virtual_post_count( $target_site_id ) ),
		'samples'        => $samples,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
