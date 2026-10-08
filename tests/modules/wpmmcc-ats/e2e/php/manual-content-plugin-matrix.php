<?php
/**
 * Manual-only content plugin matrix fixture and PHP assertions.
 *
 * This script prepares a single manual multilingual virtual site and then
 * exercises WPMMCC ATS manual translation REST/save flow for every content
 * plugin project declared in project-specs.json. It deliberately avoids the
 * Rust/Desktop client and avoids automatic translation.
 *
 * Output:
 *   - stdout JSON report (for shell wrapper)
 *   - runtime/manual-content-plugin-matrix-targets.json (for Playwright)
 *
 * @package WPTSALL\E2E
 */

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Settings\Services\Settings_Service;
use WPTSALL\ManualTranslation\Services\Taxonomy_Translation_Service;
use WPTSALL\Sites\Services\Manual_Content_Service;
use WPTSALL\Hooks\Virtual_Site_SEO;

require_once __DIR__ . '/helpers.php';

$started_at = gmdate( 'c' );
$GLOBALS['wptsall_e2e_mcm_assertions'] = array();
$GLOBALS['wptsall_e2e_mcm_failures']   = array();
$GLOBALS['wptsall_e2e_mcm_warnings']   = array();

/**
 * Record a hard assertion.
 *
 * @param string $name Assertion name.
 * @param bool   $ok   Pass flag.
 * @param mixed  $detail Detail payload.
 * @return void
 */
function wptsall_e2e_mcm_assert( string $name, bool $ok, $detail = null ): void {
	$GLOBALS['wptsall_e2e_mcm_assertions'][] = array(
		'name'   => $name,
		'ok'     => $ok,
		'detail' => $detail,
	);
	if ( ! $ok ) {
		$GLOBALS['wptsall_e2e_mcm_failures'][] = array(
			'name'   => $name,
			'detail' => $detail,
		);
	}
}

/**
 * Record a non-blocking warning.
 *
 * @param string $name Warning name.
 * @param mixed  $detail Detail payload.
 * @return void
 */
function wptsall_e2e_mcm_warn( string $name, $detail = null ): void {
	$GLOBALS['wptsall_e2e_mcm_warnings'][] = array(
		'name'   => $name,
		'detail' => $detail,
	);
}

/**
 * Emit JSON report.
 *
 * @param array $report Report payload.
 * @return void
 */
function wptsall_e2e_mcm_emit( array $report ): void {
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
}

/**
 * Normalize a project name into a short slug.
 *
 * @param string $project Project key.
 * @return string
 */
function wptsall_e2e_mcm_short_slug( string $project ): string {
	$short = preg_replace( '/-content$/', '', $project );
	$short = sanitize_title( (string) $short );
	return '' !== $short ? $short : sanitize_title( $project );
}

/**
 * Whether a plugin slug is currently active (single-site or network-active).
 *
 * @param string $slug Plugin directory slug.
 * @return bool
 */
function wptsall_e2e_mcm_plugin_active( string $slug ): bool {
	if ( '' === trim( $slug ) || 'wptsall' === $slug ) {
		return true;
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$active = (array) get_option( 'active_plugins', array() );
	$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
	foreach ( array_merge( $active, $network ) as $plugin_file ) {
		$plugin_file = (string) $plugin_file;
		if ( 0 === strpos( $plugin_file, $slug . '/' ) || $plugin_file === $slug . '.php' ) {
			return true;
		}
	}
	return false;
}

/**
 * Parse the small subset of plugin-journeys.yaml needed for native admin paths.
 *
 * @param string $path YAML file path.
 * @return array<string,array<string,mixed>>
 */
function wptsall_e2e_mcm_load_admin_paths( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return array();
	}
	$out = array();
	$current = '';
	foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $line ) {
		if ( preg_match( '/^  ([a-z0-9-]+-content):\s*$/', $line, $m ) ) {
			$current = (string) $m[1];
			$out[ $current ] = array();
			continue;
		}
		if ( '' === $current ) {
			continue;
		}
		if ( preg_match( '/admin_native:\s*\{\s*path:\s*([^,\s}]+)/', $line, $m ) ) {
			$raw = trim( (string) $m[1], " \t\n\r\0\x0B\"'" );
			$out[ $current ]['path'] = $raw;
			$out[ $current ]['optional'] = (bool) preg_match( '/\boptional:\s*(true|1|yes)/i', $line );
		}
	}
	return $out;
}

/**
 * Turn a path from plugin-journeys.yaml into an absolute URL.
 *
 * @param string $path URL/path.
 * @return string
 */
function wptsall_e2e_mcm_abs_url( string $path ): string {
	$path = trim( $path );
	if ( '' === $path ) {
		return '';
	}
	if ( preg_match( '#^https?://#i', $path ) ) {
		return $path;
	}
	return home_url( '/' . ltrim( $path, '/' ) );
}

/**
 * Get source post types for a project spec.
 *
 * @param array $spec Project spec.
 * @return string[]
 */
function wptsall_e2e_mcm_source_post_types( array $spec ): array {
	$verifier = is_array( $spec['verifier'] ?? null ) ? $spec['verifier'] : array();
	$types = array();
	if ( ! empty( $verifier['source_post_types'] ) && is_array( $verifier['source_post_types'] ) ) {
		$types = $verifier['source_post_types'];
	} elseif ( ! empty( $verifier['source_post_type'] ) ) {
		$types = array( $verifier['source_post_type'] );
	} elseif ( ! empty( $spec['content_types'] ) && is_array( $spec['content_types'] ) ) {
		$types = $spec['content_types'];
	}
	$types = array_values(
		array_filter(
			array_map( 'sanitize_key', array_map( 'strval', $types ) ),
			static function ( string $type ): bool {
				return '' !== $type && post_type_exists( $type );
			}
		)
	);
	return ! empty( $types ) ? $types : array( 'post' );
}

/**
 * Whether a post type can reasonably be front-end routed.
 *
 * @param string $post_type Post type.
 * @return bool
 */
function wptsall_e2e_mcm_post_type_is_front_routable( string $post_type ): bool {
	$obj = get_post_type_object( $post_type );
	if ( ! $obj instanceof WP_Post_Type ) {
		return false;
	}
	if ( in_array( $post_type, array( 'revision', 'attachment', 'nav_menu_item', 'wp_navigation', 'elementor_library' ), true ) ) {
		return false;
	}
	if ( false === $obj->rewrite && false !== strpos( $post_type, '_library' ) ) {
		return false;
	}
	return ! empty( $obj->public ) && false !== $obj->publicly_queryable;
}

/**
 * Choose a stable source post from existing lab content.
 *
 * @param string[] $post_types Candidate post types.
 * @param string   $source_meta_key Optional source meta key required by spec.
 * @param int[]    $exclude_ids Source IDs already used by this matrix run.
 * @return array<string,mixed>|null
 */
function wptsall_e2e_mcm_pick_source_post( array $post_types, string $source_meta_key = '', array $exclude_ids = array(), string $prefer_project = '' ): ?array {
	global $wpdb;
	$exclude_ids = array_values( array_unique( array_filter( array_map( 'absint', $exclude_ids ) ) ) );
	$exclude_sql = ! empty( $exclude_ids ) ? ' AND p.ID NOT IN (' . implode( ',', $exclude_ids ) . ')' : '';

	// P0: prefer posts tagged as official fixtures for this project.
	if ( '' !== $prefer_project ) {
		foreach ( $post_types as $post_type ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_type, p.post_name, p.post_title, CHAR_LENGTH(TRIM(p.post_content)) AS content_len
					   FROM {$wpdb->posts} p
					   INNER JOIN {$wpdb->postmeta} ofi ON ofi.post_id = p.ID AND ofi.meta_key = '_wptsall_official_fixture' AND ofi.meta_value = '1'
					   INNER JOIN {$wpdb->postmeta} ofp ON ofp.post_id = p.ID AND ofp.meta_key = '_wptsall_official_fixture_project' AND ofp.meta_value = %s
					   LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = '_wptsall_virtual_site_id'
					  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_name <> '' AND vm.meta_id IS NULL{$exclude_sql}
					  ORDER BY p.ID DESC LIMIT 1",
					$prefer_project,
					$post_type
				),
				ARRAY_A
			);
			if ( is_array( $rows ) && ! empty( $rows[0] ) ) {
				$rows[0]['official_fixture'] = true;
				return $rows[0];
			}
		}
	}

	foreach ( $post_types as $post_type ) {
		if ( '' !== $source_meta_key ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_type, p.post_name, p.post_title, CHAR_LENGTH(TRIM(p.post_content)) AS content_len
					   FROM {$wpdb->posts} p
					   INNER JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = %s AND sm.meta_value <> ''
					   LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = '_wptsall_virtual_site_id'
					  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_name <> '' AND vm.meta_id IS NULL{$exclude_sql}
					  ORDER BY CHAR_LENGTH(TRIM(p.post_content)) DESC, p.ID DESC LIMIT 1",
					$source_meta_key,
					$post_type
				),
				ARRAY_A
			);
			if ( is_array( $rows ) && ! empty( $rows[0] ) ) {
				$rows[0]['official_fixture'] = false;
				return $rows[0];
			}
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_name, p.post_title, CHAR_LENGTH(TRIM(p.post_content)) AS content_len
				   FROM {$wpdb->posts} p
				   LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = '_wptsall_virtual_site_id'
				  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_name <> '' AND vm.meta_id IS NULL{$exclude_sql}
				  ORDER BY CHAR_LENGTH(TRIM(p.post_content)) DESC, p.ID DESC LIMIT 1",
				$post_type
			),
			ARRAY_A
		);
		if ( is_array( $rows ) && ! empty( $rows[0] ) ) {
			$rows[0]['official_fixture'] = false;
			return $rows[0];
		}
	}
	return null;
}

/**
 * Create a minimal source post if lab data is missing.
 *
 * @param string $post_type Post type.
 * @param string $project Project name.
 * @param string $stamp Run stamp.
 * @param int    $author Author ID.
 * @return int
 */
function wptsall_e2e_mcm_create_source_post( string $post_type, string $project, string $stamp, int $author ): int {
	$short = wptsall_e2e_mcm_short_slug( $project );
	$post_id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_author'  => $author,
			'post_title'   => 'Manual Matrix Source ' . $project . ' ' . $stamp,
			'post_name'    => 'manual-matrix-source-' . $short . '-' . $stamp,
			'post_content' => '<p>Manual matrix source fixture for ' . esc_html( $project ) . ' ' . esc_html( $stamp ) . '</p>',
			'post_excerpt' => 'Manual matrix source excerpt ' . $project . ' ' . $stamp,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return 0;
	}
	return (int) $post_id;
}

/**
 * Choose a taxonomy associated with a post type for manual term mapping.
 *
 * @param string $post_type Post type.
 * @return string
 */
function wptsall_e2e_mcm_choose_taxonomy( string $post_type ): string {
	$objects = get_object_taxonomies( $post_type, 'objects' );
	if ( ! is_array( $objects ) ) {
		return '';
	}
	$skip = array(
		'post_format'            => true,
		'product_visibility'     => true,
		'product_shipping_class' => true,
		'wp_theme'               => true,
		'elementor_library_type' => true,
	);
	$best = '';
	foreach ( $objects as $tax => $obj ) {
		$tax = (string) $tax;
		if ( isset( $skip[ $tax ] ) || ! taxonomy_exists( $tax ) ) {
			continue;
		}
		if ( ! empty( $obj->show_ui ) || ! empty( $obj->public ) ) {
			return $tax;
		}
		if ( '' === $best ) {
			$best = $tax;
		}
	}
	return $best;
}

/**
 * Create and link manual source/target terms for a source post.
 *
 * @param int    $source_post_id Source post.
 * @param string $post_type Post type.
 * @param string $project Project.
 * @param string $target_lang Target language.
 * @param int    $relation_id Relation ID.
 * @param string $stamp Stamp.
 * @return array<string,mixed>
 */
function wptsall_e2e_mcm_prepare_terms( int $source_post_id, string $post_type, string $project, string $target_lang, int $relation_id, string $stamp ): array {
	$taxonomy = wptsall_e2e_mcm_choose_taxonomy( $post_type );
	if ( '' === $taxonomy ) {
		return array( 'ok' => true, 'skipped' => true, 'reason' => 'no_taxonomy' );
	}
	$short = wptsall_e2e_mcm_short_slug( $project );
	$source = wp_insert_term(
		'Manual Matrix Source Term ' . $project . ' ' . $stamp,
		$taxonomy,
		array( 'slug' => 'manual-matrix-source-' . $short . '-' . $stamp )
	);
	if ( is_wp_error( $source ) ) {
		$existing = term_exists( 'manual-matrix-source-' . $short . '-' . $stamp, $taxonomy );
		if ( ! $existing ) {
			return array( 'ok' => false, 'taxonomy' => $taxonomy, 'error' => $source->get_error_message() );
		}
		$source_term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
	} else {
		$source_term_id = (int) $source['term_id'];
	}
	wp_set_object_terms( $source_post_id, array( $source_term_id ), $taxonomy, false );

	$target = wp_insert_term(
		'Terme manuel FR ' . $project . ' ' . $stamp,
		$taxonomy,
		array( 'slug' => 'terme-manuel-fr-' . $short . '-' . $stamp )
	);
	if ( is_wp_error( $target ) ) {
		$existing = term_exists( 'terme-manuel-fr-' . $short . '-' . $stamp, $taxonomy );
		if ( ! $existing ) {
			return array( 'ok' => false, 'taxonomy' => $taxonomy, 'source_term_id' => $source_term_id, 'error' => $target->get_error_message() );
		}
		$target_term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
	} else {
		$target_term_id = (int) $target['term_id'];
	}

	$mapping_id = class_exists( Taxonomy_Translation_Service::class )
		? Taxonomy_Translation_Service::link( $source_term_id, $taxonomy, $target_term_id, $target_lang, $relation_id )
		: 0;

	return array(
		'ok'             => (int) $mapping_id > 0,
		'skipped'        => false,
		'taxonomy'       => $taxonomy,
		'source_term_id' => $source_term_id,
		'target_term_id' => $target_term_id,
		'mapping_id'     => (int) $mapping_id,
		'target_name'    => get_term_field( 'name', $target_term_id, $taxonomy ),
		'target_slug'    => get_term_field( 'slug', $target_term_id, $taxonomy ),
	);
}

/**
 * Dispatch an internal manual translation REST request.
 *
 * @param string $method Method.
 * @param string $route REST route.
 * @param array  $body Body or query params.
 * @return array{status:int,data:mixed}
 */
function wptsall_e2e_mcm_rest( string $method, string $route, array $body ): array {
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === strtoupper( $method ) ) {
		$request->set_query_params( $body );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body_params( $body );
	}
	$response = rest_do_request( $request );
	return array(
		'status' => (int) $response->get_status(),
		'data'   => $response->get_data(),
	);
}

/**
 * Build a translated value for a source meta value without breaking shapes.
 *
 * @param mixed  $value Source value.
 * @param string $marker Marker text.
 * @return mixed
 */
function wptsall_e2e_mcm_translate_meta_value( $value, string $marker ) {
	if ( is_string( $value ) ) {
		$trim = trim( $value );
		if ( '' === $trim ) {
			return $marker;
		}
		if ( strlen( $trim ) < 400 && '{' !== $trim[0] && '[' !== $trim[0] ) {
			return $marker . ' — ' . $trim;
		}
		return $value;
	}
	if ( is_numeric( $value ) || is_bool( $value ) || null === $value ) {
		return $value;
	}
	if ( is_array( $value ) ) {
		return $value;
	}
	return $value;
}

/**
 * Collect safe/manual meta keys for the REST payload.
 *
 * @param int    $source_post_id Source post.
 * @param array  $spec Project spec.
 * @param string $project Project.
 * @param string $stamp Stamp.
 * @return array{payload:array,expected:array}
 */
function wptsall_e2e_mcm_meta_payload( int $source_post_id, array $spec, string $project, string $stamp ): array {
	$short = wptsall_e2e_mcm_short_slug( $project );
	$generic_key = 'manual_matrix_meta_' . str_replace( '-', '_', $short );
	$generic_value = 'méta manuelle FR ' . $project . ' ' . $stamp;
	update_post_meta( $source_post_id, $generic_key, 'manual matrix source meta ' . $project . ' ' . $stamp );

	$payload = array( $generic_key => $generic_value );
	$expected = array( $generic_key => $generic_value );

	$verifier = is_array( $spec['verifier'] ?? null ) ? $spec['verifier'] : array();
	$source_meta_key = is_scalar( $verifier['source_meta_key'] ?? null ) ? (string) $verifier['source_meta_key'] : '';
	if ( '' !== $source_meta_key ) {
		$raw = get_post_meta( $source_post_id, $source_meta_key, true );
		if ( '' !== $raw && null !== $raw ) {
			$translated = wptsall_e2e_mcm_translate_meta_value( $raw, 'meta FR ' . $project . ' ' . $stamp );
			if ( is_string( $translated ) && $translated !== $raw ) {
				$payload[ $source_meta_key ] = $translated;
				$expected[ $source_meta_key ] = $translated;
			} else {
				wptsall_e2e_mcm_warn( 'source meta kept unmodified (structured or unsafe)', array( 'project' => $project, 'key' => $source_meta_key ) );
			}
		}
	}

	return array( 'payload' => $payload, 'expected' => $expected );
}

/**
 * Seed source-side plugin meta that controls native frontend rendering.
 *
 * @param int    $source_post_id Source post ID.
 * @param string $post_type Source post type.
 * @param string $project Project key.
 * @param string $stamp Run stamp.
 * @return array<string,mixed>
 */
function wptsall_e2e_mcm_prepare_source_plugin_meta( int $source_post_id, string $post_type, string $project, string $stamp ): array {
	if ( $source_post_id <= 0 || 'give-content' !== $project || 'give_forms' !== $post_type ) {
		return array( 'ok' => true, 'skipped' => true );
	}

	$source_content = '<p>Manual matrix Give source introduction ' . esc_html( $project ) . ' ' . esc_html( $stamp ) . '</p>';
	$meta = array(
		'_give_display_content'   => 'enabled',
		'_give_content_placement' => 'give_pre_form',
		'_give_form_content'      => $source_content,
		'_give_checkout_label'    => 'Donate Now',
		'_give_reveal_label'      => 'Donate Now',
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $source_post_id, $key, $value );
	}
	clean_post_cache( $source_post_id );
	wp_cache_delete( $source_post_id, 'post_meta' );

	$checks = array();
	foreach ( $meta as $key => $value ) {
		$checks[ $key ] = get_post_meta( $source_post_id, $key, true ) === $value;
	}

	return array(
		'ok'       => ! in_array( false, $checks, true ),
		'skipped'  => false,
		'post_type' => $post_type,
		'checks'   => $checks,
		'meta'     => $meta,
	);
}

/**
 * Add plugin-specific translated meta needed by native frontend templates.
 *
 * @param string $post_type Source post type.
 * @param string $project Project key.
 * @param string $stamp Run stamp.
 * @param string $kind Object kind.
 * @param string $content Translated rich HTML content.
 * @return array{payload:array,expected:array}
 */
function wptsall_e2e_mcm_plugin_translation_meta( string $post_type, string $project, string $stamp, string $kind, string $content ): array {
	if ( 'give-content' !== $project || 'give_forms' !== $post_type ) {
		return array( 'payload' => array(), 'expected' => array() );
	}

	$label = 'Donner maintenant ' . $kind . ' ' . $stamp;
	$meta = array(
		'_give_display_content'   => 'enabled',
		'_give_content_placement' => 'give_pre_form',
		'_give_form_content'      => $content,
		'_give_checkout_label'    => $label,
		'_give_reveal_label'      => $label,
	);

	return array( 'payload' => $meta, 'expected' => $meta );
}

/**
 * Make a manual translation payload for a post.
 *
 * @param int    $source_post_id Source post ID.
 * @param array  $spec Project spec.
 * @param string $project Project key.
 * @param string $stamp Stamp.
 * @param string $kind Object kind.
 * @return array{translated_data:array,expected:array}
 */
function wptsall_e2e_mcm_translation_payload( int $source_post_id, array $spec, string $project, string $stamp, string $kind = 'plugin' ): array {
	$short = wptsall_e2e_mcm_short_slug( $project );
	$meta = wptsall_e2e_mcm_meta_payload( $source_post_id, $spec, $project, $stamp );
	$title = 'Manuel FR ' . $project . ' ' . $kind . ' ' . $stamp;
	$content_marker = 'MANUAL_MATRIX_' . strtoupper( str_replace( '-', '_', $short ) ) . '_' . strtoupper( $kind ) . '_' . $stamp;
	$content = '<p>Contenu manuel FR pour ' . esc_html( $project ) . ' (' . esc_html( $kind ) . ') ' . esc_html( $stamp ) . '</p><p>' . esc_html( $content_marker ) . '</p>';
	$post_type = (string) get_post_type( $source_post_id );
	$plugin_meta = wptsall_e2e_mcm_plugin_translation_meta( $post_type, $project, $stamp, $kind, $content );
	$excerpt = 'Extrait manuel FR ' . $project . ' ' . $kind . ' ' . $stamp;
	if ( 'lifterlms-content' === $project && 'plugin' === $kind ) {
		$excerpt .= ' ' . $content_marker;
	}
	$translated = array_merge(
		array(
			'post_title'   => $title,
			'post_name'    => 'manuel-fr-' . $short . '-' . sanitize_title( $kind ) . '-' . $stamp,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
		),
		$meta['payload'],
		$plugin_meta['payload']
	);
	return array(
		'translated_data' => $translated,
		'expected'        => array(
			'title'          => $title,
			'content_marker' => $content_marker,
			'meta'           => array_merge( $meta['expected'], $plugin_meta['expected'] ),
		),
	);
}

/**
 * Build a virtual front-end URL for a target post.
 *
 * @param int   $target_post_id Target post ID.
 * @param array $virtual_site Virtual site row.
 * @param string $prefix Prefix.
 * @return string
 */
function wptsall_e2e_mcm_virtual_url( int $target_post_id, array $virtual_site, string $prefix ): string {
	$url = '';
	if ( class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
		$url = (string) \WPTSALL\Sites\Services\Url_Converter::virtualize( get_permalink( $target_post_id ), $virtual_site );
	}
	if ( '' === $url ) {
		$name = get_post_field( 'post_name', $target_post_id );
		$url = home_url( '/' . trim( $prefix, '/' ) . '/' . $name . '/' );
	}
	return trailingslashit( $url );
}

/**
 * Create a public bridge page for a project and manually translate it.
 *
 * @param string $project Project.
 * @param array  $spec Project spec.
 * @param int    $relation_id Relation.
 * @param array  $virtual_site Virtual site row.
 * @param string $prefix Prefix.
 * @param string $stamp Stamp.
 * @param int    $author Author.
 * @return array<string,mixed>
 */
function wptsall_e2e_mcm_create_bridge_page( string $project, array $spec, int $relation_id, array $virtual_site, string $prefix, string $stamp, int $author ): array {
	$short = wptsall_e2e_mcm_short_slug( $project );
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_author'  => $author,
			'post_title'   => 'Manual Matrix Bridge ' . $project . ' ' . $stamp,
			'post_name'    => 'manual-matrix-bridge-' . $short . '-' . $stamp,
			'post_content' => '<p>Bridge page for manual multilingual frontend checks: ' . esc_html( $project ) . '</p>',
			'post_excerpt' => 'Manual matrix bridge ' . $project,
		),
		true
	);
	if ( is_wp_error( $page_id ) || (int) $page_id <= 0 ) {
		return array( 'ok' => false, 'error' => is_wp_error( $page_id ) ? $page_id->get_error_message() : 'page_insert_failed' );
	}
	$payload = wptsall_e2e_mcm_translation_payload( (int) $page_id, $spec, $project, $stamp, 'bridge' );
	$save = wptsall_e2e_mcm_rest(
		'POST',
		'/wptsall/v2/manual-translations',
		array(
			'source_post_id'  => (int) $page_id,
			'relation_id'     => $relation_id,
			'translated_data' => $payload['translated_data'],
		)
	);
	$target_id = (int) ( is_array( $save['data'] ) ? ( $save['data']['target_id'] ?? 0 ) : 0 );
	return array(
		'ok'             => in_array( (int) $save['status'], array( 200, 201 ), true ) && $target_id > 0,
		'source_post_id' => (int) $page_id,
		'target_post_id' => $target_id,
		'save'           => $save,
		'expected'       => $payload['expected'],
		'url'            => $target_id > 0 ? wptsall_e2e_mcm_virtual_url( $target_id, $virtual_site, $prefix ) : '',
		'source_edit_url'=> admin_url( 'post.php?post=' . (int) $page_id . '&action=edit' ),
		'target_edit_url'=> $target_id > 0 ? admin_url( 'post.php?post=' . $target_id . '&action=edit' ) : '',
	);
}

/**
 * Verify target post fields/meta/mapping after manual REST save.
 *
 * @param int    $source_post_id Source post.
 * @param int    $target_post_id Target post.
 * @param int    $relation_id Relation.
 * @param array  $expected Expected marker payload.
 * @param string $project Project.
 * @return array<string,mixed>
 */
function wptsall_e2e_mcm_verify_target( int $source_post_id, int $target_post_id, int $relation_id, array $expected, string $project ): array {
	global $wpdb;
	$target = $target_post_id > 0 ? get_post( $target_post_id ) : null;
	$checks = array();
	$checks['target_exists'] = (bool) $target;
	$checks['title'] = $target && (string) $target->post_title === (string) ( $expected['title'] ?? '' );
	$checks['content_marker'] = $target && false !== strpos( (string) $target->post_content, (string) ( $expected['content_marker'] ?? '' ) );
	$virtual_marker_raw = $target_post_id > 0 ? $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1', $wpdb->postmeta, $target_post_id, '_wptsall_virtual_site_id' ) ) : '';
	$checks['virtual_marker'] = $target_post_id > 0 && '' !== (string) $virtual_marker_raw;
	// P1 identity dual-write: some plugins (Give, etc.) filter get_post_meta —
	// read markers via SQL like virtual_marker.
	$meta_source_raw   = $target_post_id > 0 ? $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1', $wpdb->postmeta, $target_post_id, '_wptsall_source_post_id' ) ) : '';
	$meta_relation_raw = $target_post_id > 0 ? $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1', $wpdb->postmeta, $target_post_id, '_wptsall_relation_id' ) ) : '';
	$meta_source   = (int) $meta_source_raw;
	$meta_relation = (int) $meta_relation_raw;
	$checks['identity_source_meta']   = $meta_source === $source_post_id;
	$checks['identity_relation_meta'] = $meta_relation === $relation_id;
	$mapping_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : e2e_table( 'post_mappings' );
	$mapping = null;
	if ( e2e_table_exists( $mapping_table ) ) {
		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_post_id = %d AND target_post_id = %d AND relation_id = %d ORDER BY id DESC LIMIT 1',
				$mapping_table,
				$source_post_id,
				$target_post_id,
				$relation_id
			),
			ARRAY_A
		);
	}
	$checks['mapping'] = is_array( $mapping );
	$meta_results = array();
	foreach ( (array) ( $expected['meta'] ?? array() ) as $key => $value ) {
		$actual = get_post_meta( $target_post_id, (string) $key, true );
		$meta_results[ (string) $key ] = array(
			'ok'     => $actual === $value,
			'actual' => $actual,
			'expected' => $value,
		);
	}
	$checks['meta'] = ! in_array( false, array_map( static function ( $row ) { return ! empty( $row['ok'] ); }, $meta_results ), true );
	$status = wptsall_e2e_mcm_rest(
		'GET',
		'/wptsall/v2/manual-translations/status',
		array( 'source_post_id' => $source_post_id, 'post_type' => $target ? $target->post_type : '' )
	);
	$status_ok = false;
	foreach ( (array) ( is_array( $status['data'] ) ? $status['data'] : array() ) as $row ) {
		if ( (int) ( $row['relation_id'] ?? 0 ) === $relation_id && (int) ( $row['target_post_id'] ?? 0 ) === $target_post_id && 'published' === (string) ( $row['status'] ?? '' ) ) {
			$status_ok = true;
			break;
		}
	}
	$checks['status_rest'] = 200 === (int) $status['status'] && $status_ok;

	return array(
		'ok'      => ! in_array( false, $checks, true ),
		'checks'  => $checks,
		'meta'    => $meta_results,
		'virtual_marker_raw' => $virtual_marker_raw,
		'identity' => array(
			'source_meta'   => $meta_source,
			'relation_meta' => $meta_relation,
		),
		'mapping' => $mapping,
		'status'  => $status,
		'project' => $project,
	);
}

/**
 * HTTP GET helper for public seam checks.
 *
 * @param string $url URL.
 * @return array{status:int,body:string}
 */
function wptsall_e2e_mcm_http_get( string $url ): array {
	// Inside the Lab container home_url() is often http://127.0.0.1:9083 (host
	// publish port) which is not listening in-container. Rewrite to loopback :80.
	// Also disable auto-redirect: WP may Location: back to :9083.
	$map = static function ( string $u ): string {
		$u = (string) preg_replace( '#^https?://127\.0\.0\.1:\d+#i', 'http://127.0.0.1', $u );
		return (string) preg_replace( '#^https?://localhost:\d+#i', 'http://127.0.0.1', $u );
	};
	$mapped = $map( $url );
	$host   = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '127.0.0.1' );
	$final  = $mapped;
	$body   = '';
	$status = 0;
	for ( $i = 0; $i < 5; $i++ ) {
		$res = wp_remote_get(
			$final,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'sslverify'   => false,
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml',
					'Host'   => $host,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 0, 'body' => $res->get_error_message(), 'url' => $final );
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$body   = (string) wp_remote_retrieve_body( $res );
		if ( $status < 300 || $status >= 400 ) {
			break;
		}
		$loc = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( '' === $loc ) {
			break;
		}
		if ( 0 === strpos( $loc, '/' ) ) {
			$loc = 'http://127.0.0.1' . $loc;
		}
		$final = $map( $loc );
	}
	return array(
		'status' => $status,
		'body'   => $body,
		'url'    => $final,
	);
}

/**
 * P1 public seam pack: body isolation + hreflang x-default + sitemap shadow exclusion.
 *
 * @param int    $source_post_id Source.
 * @param int    $target_post_id Target shadow.
 * @param string $target_url     Virtual URL.
 * @param string $prefix         Path prefix.
 * @param string $content_marker Translated marker.
 * @param string $post_type      Post type.
 * @return array<string,mixed>
 */
function wptsall_e2e_mcm_public_seams( int $source_post_id, int $target_post_id, string $target_url, string $prefix, string $content_marker, string $post_type ): array {
	$checks = array();
	$detail = array();

	$front = wptsall_e2e_mcm_http_get( $target_url );
	$detail['front_status'] = $front['status'];
	$detail['front_fetch_url'] = $front['url'] ?? $target_url;
	$body = $front['body'];
	$checks['front_200'] = 200 === $front['status'];
	// Lab PHP runs inside the container; host-published :9083 is often unreachable
	// (canonical redirects loop). Soft-skip public HTML seams and rely on Playwright
	// from the host for hard front asserts.
	$http_soft = in_array( (int) $front['status'], array( 0, 301, 302, 303, 307, 308 ), true )
		|| ( is_string( $body ) && false !== stripos( $body, 'Failed to connect' ) );
	if ( $http_soft && ! $checks['front_200'] ) {
		return array(
			'ok'      => true,
			'skipped' => true,
			'reason'  => 'in_container_http_unreachable',
			'checks'  => $checks,
			'detail'  => $detail,
			'source'  => $source_post_id,
			'target'  => $target_post_id,
		);
	}
	$checks['front_has_marker'] = '' === $content_marker || false !== strpos( $body, $content_marker );
	$checks['front_vs_marker'] = (bool) preg_match( '/<!--\s*wptsall virtual site/i', $body );
	$checks['front_no_double_prefix'] = ! preg_match( '#/' . preg_quote( $prefix, '#' ) . '/' . preg_quote( $prefix, '#' ) . '/#i', $body );

	// Hreflang: must include x-default without virtual prefix.
	$hreflangs = array();
	if ( preg_match_all( '/<link\b([^>]*rel=["\']alternate["\'][^>]*)>/i', $body, $ms ) ) {
		foreach ( $ms[1] as $attrs ) {
			if ( ! preg_match( '/hreflang=["\']([^"\']+)["\']/i', $attrs, $lm ) ) {
				continue;
			}
			if ( ! preg_match( '/href=["\']([^"\']+)["\']/i', $attrs, $hm ) ) {
				continue;
			}
			$hreflangs[] = array( 'lang' => $lm[1], 'href' => $hm[1] );
		}
	}
	$detail['hreflangs'] = $hreflangs;
	$checks['hreflang_present'] = count( $hreflangs ) > 0;
	$xdef = null;
	foreach ( $hreflangs as $h ) {
		if ( 'x-default' === strtolower( (string) $h['lang'] ) ) {
			$xdef = (string) $h['href'];
			break;
		}
	}
	$detail['x_default'] = $xdef;
	$checks['x_default_unprefixed'] = is_string( $xdef ) && '' !== $xdef && false === stripos( $xdef, '/' . $prefix . '/' );

	// Sitemap: shadow must not be a primary <loc> (Yoast). Soft when sitemap missing.
	$sitemap_urls = array(
		home_url( '/post-sitemap.xml' ),
		home_url( '/page-sitemap.xml' ),
		home_url( '/product-sitemap.xml' ),
		home_url( '/' . $post_type . '-sitemap.xml' ),
	);
	$target_slug = (string) get_post_field( 'post_name', $target_post_id );
	$shadow_loc_hit = false;
	$xhtml_hit = false;
	$sitemap_checked = false;
	foreach ( array_unique( $sitemap_urls ) as $sm_url ) {
		$sm = wptsall_e2e_mcm_http_get( $sm_url );
		if ( 200 !== $sm['status'] || false === strpos( $sm['body'], '<urlset' ) ) {
			continue;
		}
		$sitemap_checked = true;
		if ( '' !== $target_slug && preg_match( '#<loc>[^<]*' . preg_quote( $prefix, '#' ) . '/[^<]*' . preg_quote( $target_slug, '#' ) . '#i', $sm['body'] ) ) {
			$shadow_loc_hit = true;
		}
		if ( false !== strpos( $sm['body'], 'xhtml:link' ) || false !== strpos( $sm['body'], 'hreflang=' ) ) {
			$xhtml_hit = true;
		}
		break;
	}
	$detail['sitemap_checked'] = $sitemap_checked;
	$detail['shadow_loc_hit'] = $shadow_loc_hit;
	$detail['xhtml_hit'] = $xhtml_hit;
	$checks['sitemap_no_shadow_loc'] = ! $sitemap_checked || ! $shadow_loc_hit;

	// Soft: xhtml may be absent if Yoast disabled — do not fail hard.
	$hard = array(
		'front_200'              => $checks['front_200'],
		'front_has_marker'       => $checks['front_has_marker'],
		'front_vs_marker'        => $checks['front_vs_marker'],
		'front_no_double_prefix' => $checks['front_no_double_prefix'],
		'hreflang_present'       => $checks['hreflang_present'],
		'x_default_unprefixed'   => $checks['x_default_unprefixed'],
		'sitemap_no_shadow_loc'  => $checks['sitemap_no_shadow_loc'],
	);

	return array(
		'ok'     => ! in_array( false, $hard, true ),
		'checks' => $checks,
		'detail' => $detail,
		'source' => $source_post_id,
		'target' => $target_post_id,
	);
}

/**
 * Read selected projects from env.
 *
 * @param array<string,array> $specs All specs.
 * @return string[]
 */
function wptsall_e2e_mcm_selected_projects( array $specs ): array {
	$csv = getenv( 'E2E_MANUAL_PROJECTS' );
	$csv = is_string( $csv ) ? trim( $csv ) : '';
	if ( '' === $csv ) {
		return array_keys( $specs );
	}
	$selected = array();
	foreach ( explode( ',', $csv ) as $item ) {
		$item = trim( $item );
		if ( '' !== $item && isset( $specs[ $item ] ) ) {
			$selected[] = $item;
		}
	}
	return array_values( array_unique( $selected ) );
}

try {
	if ( ! defined( 'WPTSALL_VERSION' ) ) {
		throw new RuntimeException( 'WPMMCC ATS plugin is not loaded.' );
	}

	foreach ( array(
		'Language_Service'             => Language_Service::class,
		'Virtual_Site_Service'         => Virtual_Site_Service::class,
		'Site_Relation_Service'        => Site_Relation_Service::class,
		'Manual_Content_Service'       => Manual_Content_Service::class,
		'Taxonomy_Translation_Service' => Taxonomy_Translation_Service::class,
		'Virtual_Site_SEO'             => Virtual_Site_SEO::class,
	) as $label => $class_name ) {
		wptsall_e2e_mcm_assert( 'class loaded: ' . $label, class_exists( $class_name ), $class_name );
	}

	if ( function_exists( 'wptsall_run_migrations' ) ) {
		wptsall_run_migrations();
	}
	foreach ( array(
		'wptsall_create_languages_table',
		'wptsall_create_virtual_sites_table',
		'wptsall_create_site_relations_table',
		'wptsall_create_field_mapping_tables',
		'wptsall_create_strings_table',
		'wptsall_create_menu_mappings_table',
	) as $schema_fn ) {
		if ( function_exists( $schema_fn ) ) {
			call_user_func( $schema_fn );
		}
	}

	$admin_login = getenv( 'WP_ADMIN_USER' );
	$admin_login = is_string( $admin_login ) && '' !== $admin_login ? $admin_login : 'e2esmokeadmin';
	$admin = get_user_by( 'login', $admin_login );
	if ( ! $admin ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => $admin_login,
				'user_pass'  => getenv( 'WP_ADMIN_PASS' ) ?: 'Wptsall-Smoke-Admin-2026!',
				'user_email' => $admin_login . '@wpmm.test',
				'role'       => 'administrator',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create admin user: ' . $user_id->get_error_message() );
		}
		$admin = get_user_by( 'id', (int) $user_id );
	}
	wp_set_current_user( (int) $admin->ID );
	foreach ( array( 'manage_wptsall', 'manage_wptsall_translations', 'manage_wptsall_settings', 'manage_wptsall_sync' ) as $cap ) {
		$admin->add_cap( $cap, true );
	}
	wptsall_e2e_mcm_assert( 'manual management permission', function_exists( 'wptsall_user_can_manage_translations' ) && wptsall_user_can_manage_translations(), array( 'user' => $admin_login ) );

	$source_lang = get_locale() ?: 'en_US';
	$source_lang_id = Language_Service::upsert(
		array(
			'code'        => $source_lang,
			'slug'        => strtolower( str_replace( '_', '-', $source_lang ) ),
			'name'        => 'Source ' . $source_lang,
			'native_name' => 'Source ' . $source_lang,
			'locale'      => $source_lang,
			'is_default'  => 1,
			'status'      => 'active',
		)
	);
	if ( $source_lang_id ) {
		Language_Service::set_default( (int) $source_lang_id );
	}
	$target_lang = 'fr_FR';
	$target_lang_id = Language_Service::upsert(
		array(
			'code'        => $target_lang,
			'slug'        => 'fr',
			'name'        => 'French',
			'native_name' => 'Français',
			'locale'      => $target_lang,
			'flag'        => '🇫🇷',
			'sort_order'  => 10,
			'status'      => 'active',
		)
	);
	wptsall_e2e_mcm_assert( 'source language default exists', (bool) Language_Service::get_default(), Language_Service::get_default() );
	wptsall_e2e_mcm_assert( 'target language exists', (bool) Language_Service::get_by_code( $target_lang ), Language_Service::get_by_code( $target_lang ) );

	$stamp = strtolower( substr( md5( uniqid( 'manual-content-plugin-matrix-', true ) ), 0, 8 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.md5_md5
	$prefix = 'manual-matrix-fr-' . $stamp;
	$vs_result = Virtual_Site_Service::create(
		array(
			'name'        => 'Manual Matrix Français ' . $stamp,
			'path_prefix' => $prefix,
			'lang'        => $target_lang,
		)
	);
	$vs_id = (string) ( $vs_result['site_id'] ?? '' );
	wptsall_e2e_mcm_assert( 'virtual manual matrix site created', ! empty( $vs_result['success'] ) && '' !== $vs_id, $vs_result );
	if ( '' === $vs_id ) {
		throw new RuntimeException( 'Could not create virtual site.' );
	}

	$relation_result = Site_Relation_Service::create_relation(
		array(
			'source_site_id'    => get_current_blog_id(),
			'source_lang'       => $source_lang,
			'template'          => 'wordpress-blog',
			'auto_create_model' => true,
			'target_sites'      => array(
				array(
					'id'   => $vs_id,
					'type' => 'virtual',
					'lang' => $target_lang,
				),
			),
		)
	);
	$relation_id = (int) ( $relation_result['relation_ids'][0] ?? 0 );
	wptsall_e2e_mcm_assert( 'manual matrix relation created', ! empty( $relation_result['success'] ) && $relation_id > 0, $relation_result );
	if ( $relation_id <= 0 ) {
		throw new RuntimeException( 'Could not create relation.' );
	}
	$virtual_site = Virtual_Site_Service::get_by_relation( $relation_id );
	wptsall_e2e_mcm_assert( 'virtual site resolves by relation', is_array( $virtual_site ) && trim( (string) ( $virtual_site['path_prefix'] ?? '' ), '/' ) === $prefix, $virtual_site );

	if ( class_exists( Settings_Service::class ) ) {
		Settings_Service::update(
			array(
				'url_form'                => 'subdir',
				'hreflang_emitter'        => 'wpmmcc-ats',
				'prefer_sitemap_hreflang' => false,
				'default_language'        => $source_lang,
			)
		);
	}

	$GLOBALS['wp_rest_server'] = new WP_REST_Server();
	do_action( 'rest_api_init' );

	$specs = e2e_plugin_project_specs();
	$projects = wptsall_e2e_mcm_selected_projects( $specs );
	wptsall_e2e_mcm_assert( 'selected manual matrix projects', 20 === count( $projects ) || '' !== trim( (string) getenv( 'E2E_MANUAL_PROJECTS' ) ), array( 'count' => count( $projects ), 'projects' => $projects ) );
	$admin_paths = wptsall_e2e_mcm_load_admin_paths( dirname( __DIR__ ) . '/plugin-journeys.yaml' );

	$manual_admin_pages = array(
		array( 'slug' => 'wptsall-dashboard', 'label' => 'Translation Dashboard' ),
		array( 'slug' => 'wptsall-manual', 'label' => 'Manual Hub' ),
		array( 'slug' => 'wptsall-languages', 'label' => 'Languages' ),
		array( 'slug' => 'wptsall-sites', 'label' => 'Sites / Relations' ),
		array( 'slug' => 'wptsall-url-discovery', 'label' => 'URL Discovery' ),
		array( 'slug' => 'wptsall-pending', 'label' => 'Pending Translations' ),
		array( 'slug' => 'wptsall-tax-translations', 'label' => 'Taxonomy Translation' ),
		array( 'slug' => 'wptsall-field-discovery', 'label' => 'Field Discovery' ),
		array( 'slug' => 'wptsall-content-types', 'label' => 'Content Types' ),
		array( 'slug' => 'wptsall-strings', 'label' => 'Strings' ),
		array( 'slug' => 'wptsall-menu-sync', 'label' => 'Menu Sync' ),
		array( 'slug' => 'wptsall-custom-fields', 'label' => 'Custom Fields' ),
		array( 'slug' => 'wptsall-theme-plugin-loc', 'label' => 'Theme & Plugin Localization' ),
	);
	foreach ( $manual_admin_pages as &$page ) {
		$page['url'] = admin_url( 'admin.php?page=' . $page['slug'] );
	}
	unset( $page );

	$project_results = array();
	$used_source_ids = array();
	foreach ( $projects as $project ) {
		$spec = $specs[ $project ];
		$plugin_slug = (string) ( $spec['plugin_slug'] ?? '' );
		$project_assertions = array();
		$project_failures = array();
		$record = static function ( string $name, bool $ok, $detail = null ) use ( &$project_assertions, &$project_failures, $project ) {
			$project_assertions[] = array( 'name' => $name, 'ok' => $ok, 'detail' => $detail );
			if ( ! $ok ) {
				$project_failures[] = array( 'name' => $name, 'detail' => $detail );
			}
			wptsall_e2e_mcm_assert( 'project ' . $project . ': ' . $name, $ok, $detail );
		};

		$required_plugins = array_values( array_filter( array_map( 'strval', (array) ( $spec['required_wp_plugins'] ?? array() ) ) ) );
		foreach ( $required_plugins as $required_slug ) {
			$record( 'required plugin active: ' . $required_slug, wptsall_e2e_mcm_plugin_active( $required_slug ), array( 'slug' => $required_slug ) );
		}

		$post_types = wptsall_e2e_mcm_source_post_types( $spec );
		$verifier = is_array( $spec['verifier'] ?? null ) ? $spec['verifier'] : array();
		$source_meta_key = is_scalar( $verifier['source_meta_key'] ?? null ) ? (string) $verifier['source_meta_key'] : '';
		$source = wptsall_e2e_mcm_pick_source_post( $post_types, $source_meta_key, $used_source_ids, $project );
		$source_seeded = false;
		if ( ! $source ) {
			$created_id = wptsall_e2e_mcm_create_source_post( $post_types[0], $project, $stamp, (int) $admin->ID );
			if ( $created_id > 0 ) {
				$source = array(
					'ID'           => $created_id,
					'post_type'    => $post_types[0],
					'post_name'    => get_post_field( 'post_name', $created_id ),
					'post_title'   => get_the_title( $created_id ),
					'content_len'  => strlen( wp_strip_all_tags( (string) get_post_field( 'post_content', $created_id ) ) ),
				);
				$source_seeded = true;
			}
		}
		$source_post_id = (int) ( $source['ID'] ?? 0 );
		$post_type = (string) ( $source['post_type'] ?? ( $post_types[0] ?? 'post' ) );
		if ( $source_post_id > 0 ) {
			$used_source_ids[] = $source_post_id;
		}
		$record( 'source content exists', $source_post_id > 0, array( 'post_types' => $post_types, 'source' => $source, 'seeded' => $source_seeded ) );
		if ( ! empty( $source['official_fixture'] ) ) {
			$record( 'official fixture selected', true, array( 'post_id' => $source_post_id ) );
		} else {
			wptsall_e2e_mcm_warn( 'project ' . $project . ': no official fixture tag — used longest publish content', $source );
		}

		$source_plugin_meta = $source_post_id > 0 ? wptsall_e2e_mcm_prepare_source_plugin_meta( $source_post_id, $post_type, $project, $stamp ) : array( 'ok' => false, 'reason' => 'no_source' );
		if ( empty( $source_plugin_meta['skipped'] ) ) {
			$record( 'plugin frontend source meta seeded', ! empty( $source_plugin_meta['ok'] ), $source_plugin_meta );
		}

		$term_info = $source_post_id > 0 ? wptsall_e2e_mcm_prepare_terms( $source_post_id, $post_type, $project, $target_lang, $relation_id, $stamp ) : array( 'ok' => false, 'reason' => 'no_source' );
		if ( empty( $term_info['skipped'] ) ) {
			$record( 'manual taxonomy term mapping', ! empty( $term_info['ok'] ), $term_info );
		}

		$editor = $source_post_id > 0 ? wptsall_e2e_mcm_rest( 'GET', '/wptsall/v2/manual-translations/editor-data', array( 'source_post_id' => $source_post_id, 'relation_id' => $relation_id ) ) : array( 'status' => 0, 'data' => null );
		$record( 'manual editor-data REST', 200 === (int) $editor['status'] && is_array( $editor['data'] ?? null ), array( 'status' => $editor['status'] ) );

		$payload = $source_post_id > 0 ? wptsall_e2e_mcm_translation_payload( $source_post_id, $spec, $project, $stamp, 'plugin' ) : array( 'translated_data' => array(), 'expected' => array() );
		$save = $source_post_id > 0 ? wptsall_e2e_mcm_rest(
			'POST',
			'/wptsall/v2/manual-translations',
			array(
				'source_post_id'  => $source_post_id,
				'relation_id'     => $relation_id,
				'translated_data' => $payload['translated_data'],
			)
		) : array( 'status' => 0, 'data' => null );
		$target_post_id = (int) ( is_array( $save['data'] ) ? ( $save['data']['target_id'] ?? 0 ) : 0 );
		$record( 'manual translation REST save', in_array( (int) $save['status'], array( 200, 201 ), true ) && $target_post_id > 0, array( 'status' => $save['status'], 'data' => $save['data'] ) );

		$target_verify = $target_post_id > 0 ? wptsall_e2e_mcm_verify_target( $source_post_id, $target_post_id, $relation_id, $payload['expected'], $project ) : array( 'ok' => false, 'checks' => array() );
		$record( 'manual target persisted/mapped', ! empty( $target_verify['ok'] ), $target_verify );

		if ( ! empty( $term_info['target_term_id'] ) && $target_post_id > 0 ) {
			$target_terms = wp_get_object_terms( $target_post_id, (string) $term_info['taxonomy'], array( 'fields' => 'ids' ) );
			$record(
				'target post uses manual mapped term',
				! is_wp_error( $target_terms ) && in_array( (int) $term_info['target_term_id'], array_map( 'intval', (array) $target_terms ), true ),
				array( 'target_terms' => is_wp_error( $target_terms ) ? $target_terms->get_error_message() : $target_terms, 'term_info' => $term_info )
			);
		}

		$bridge = wptsall_e2e_mcm_create_bridge_page( $project, $spec, $relation_id, is_array( $virtual_site ) ? $virtual_site : array(), $prefix, $stamp, (int) $admin->ID );
		$record( 'manual frontend bridge page saved', ! empty( $bridge['ok'] ), $bridge );

		$target_url = $target_post_id > 0 ? wptsall_e2e_mcm_virtual_url( $target_post_id, is_array( $virtual_site ) ? $virtual_site : array(), $prefix ) : '';
		$target_required = wptsall_e2e_mcm_post_type_is_front_routable( $post_type );
		if ( preg_match( '/(^|-)search[-_]?home/i', (string) ( $source['post_name'] ?? '' ) ) ) {
			$target_required = false;
		}

		$public_seams = array( 'ok' => true, 'skipped' => true, 'reason' => 'not_routable_or_missing' );
		if ( $target_required && $target_post_id > 0 && '' !== $target_url ) {
			$public_seams = wptsall_e2e_mcm_public_seams(
				$source_post_id,
				$target_post_id,
				$target_url,
				$prefix,
				(string) ( $payload['expected']['content_marker'] ?? '' ),
				$post_type
			);
			if ( ! empty( $public_seams['skipped'] ) ) {
				wptsall_e2e_mcm_warn( 'project ' . $project . ': public seams soft-skipped', $public_seams );
			} else {
				$record( 'public seams (front/hreflang/sitemap)', ! empty( $public_seams['ok'] ), $public_seams );
			}
		} else {
			wptsall_e2e_mcm_warn( 'project ' . $project . ': public seams skipped', array( 'routable' => $target_required, 'url' => $target_url ) );
		}

		$alternates = $target_post_id > 0 && class_exists( Virtual_Site_SEO::class ) ? Virtual_Site_SEO::build_sitemap_alternates( $target_post_id ) : array();
		$has_target_alt = false;
		$has_x_default = false;
		foreach ( (array) $alternates as $alt ) {
			$lang = strtolower( (string) ( $alt['hreflang'] ?? '' ) );
			if ( 'fr-fr' === $lang ) {
				$has_target_alt = true;
			}
			if ( 'x-default' === $lang ) {
				$has_x_default = true;
			}
		}
		$record( 'manual SEO alternates built', $has_target_alt && $has_x_default, array( 'alternates' => $alternates ) );

		$native_path = (string) ( $admin_paths[ $project ]['path'] ?? '' );
		if ( '' === $native_path ) {
			$native_path = 'post' === $post_type ? '/wp-admin/edit.php' : '/wp-admin/edit.php?post_type=' . rawurlencode( $post_type );
		}
		$core_post_edit_required = (bool) preg_match( '#(^|/|\\?)edit\.php(\\?|$)#', $native_path );

		$frontend_urls = array();
		if ( '' !== $target_url ) {
			$frontend_urls[] = array(
				'id'                    => $project . ':target',
				'kind'                  => 'target',
				'url'                   => $target_url,
				'required'              => $target_required,
				'expect_title'          => (string) ( $payload['expected']['title'] ?? '' ),
				'expect_content_marker' => (string) ( $payload['expected']['content_marker'] ?? '' ),
				'expect_hreflang'       => 'fr-FR',
			);
		}
		if ( ! empty( $bridge['url'] ) ) {
			$frontend_urls[] = array(
				'id'                    => $project . ':bridge',
				'kind'                  => 'bridge',
				'url'                   => (string) $bridge['url'],
				'required'              => true,
				'expect_title'          => (string) ( $bridge['expected']['title'] ?? '' ),
				'expect_content_marker' => (string) ( $bridge['expected']['content_marker'] ?? '' ),
				'expect_hreflang'       => 'fr-FR',
			);
		}

		$project_results[] = array(
			'project'              => $project,
			'plugin_slug'          => $plugin_slug,
			'required_plugins'     => $required_plugins,
			'ok'                   => empty( $project_failures ),
			'assertions'           => $project_assertions,
			'failures'             => $project_failures,
			'post_type'            => $post_type,
			'post_type_routable'   => wptsall_e2e_mcm_post_type_is_front_routable( $post_type ),
			'source_post_id'       => $source_post_id,
			'target_post_id'       => $target_post_id,
			'source_title'         => $source_post_id > 0 ? get_the_title( $source_post_id ) : '',
			'target_title'         => $target_post_id > 0 ? get_the_title( $target_post_id ) : '',
			'source_plugin_meta'   => $source_plugin_meta,
			'term'                 => $term_info,
			'editor_data_status'   => (int) $editor['status'],
			'manual_save_status'   => (int) $save['status'],
			'target_verify'        => $target_verify,
			'public_seams'         => $public_seams,
			'official_fixture'     => ! empty( $source['official_fixture'] ),
			'expected'             => $payload['expected'],
			'bridge'               => $bridge,
			'frontend_urls'        => $frontend_urls,
			'admin'                => array(
				'native_admin_url'       => wptsall_e2e_mcm_abs_url( $native_path ),
				'native_admin_path'      => $native_path,
				'post_edit_required'     => $core_post_edit_required,
				'source_edit_url'        => $source_post_id > 0 ? admin_url( 'post.php?post=' . $source_post_id . '&action=edit' ) : '',
				'target_edit_url'        => $target_post_id > 0 ? admin_url( 'post.php?post=' . $target_post_id . '&action=edit' ) : '',
				'manual_editor_url'      => $source_post_id > 0 ? admin_url( 'admin.php?page=wptsall-translate&source_post_id=' . $source_post_id . '&relation_id=' . $relation_id ) : '',
			),
		);
	}

	if ( function_exists( 'flush_rewrite_rules' ) ) {
		flush_rewrite_rules( false );
	}
	wp_cache_flush();

	$targets = array(
		'ok'                 => empty( $GLOBALS['wptsall_e2e_mcm_failures'] ),
		'mode'               => 'manual-only-content-plugin-matrix-no-client-no-auto-translation',
		'started_at'         => $started_at,
		'finished_at'        => gmdate( 'c' ),
		'wp_base'            => home_url( '/' ),
		'plugin_version'     => defined( 'WPTSALL_VERSION' ) ? WPTSALL_VERSION : '',
		'source_lang'        => $source_lang,
		'target_lang'        => $target_lang,
		'prefix'             => $prefix,
		'virtual_site_id'    => $vs_id,
		'relation_id'        => $relation_id,
		'manual_admin_pages' => $manual_admin_pages,
		'projects'           => $project_results,
		'assertions'         => $GLOBALS['wptsall_e2e_mcm_assertions'],
		'failures'           => $GLOBALS['wptsall_e2e_mcm_failures'],
		'warnings'           => $GLOBALS['wptsall_e2e_mcm_warnings'],
		'summary'            => array(
			'total_projects'  => count( $project_results ),
			'passed_projects' => count( array_filter( $project_results, static function ( $row ) { return ! empty( $row['ok'] ); } ) ),
			'failed_projects' => count( array_filter( $project_results, static function ( $row ) { return empty( $row['ok'] ); } ) ),
			'assertions'      => count( $GLOBALS['wptsall_e2e_mcm_assertions'] ),
			'failures'        => count( $GLOBALS['wptsall_e2e_mcm_failures'] ),
			'warnings'        => count( $GLOBALS['wptsall_e2e_mcm_warnings'] ),
		),
	);

	$targets_file = e2e_runtime_file( 'manual-content-plugin-matrix-targets.json' );
	if ( ! is_dir( dirname( $targets_file ) ) ) {
		mkdir( dirname( $targets_file ), 0755, true );
	}
	file_put_contents( $targets_file, wp_json_encode( $targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	$targets['targets_file'] = $targets_file;

	wptsall_e2e_mcm_emit( $targets );
	if ( ! empty( $GLOBALS['wptsall_e2e_mcm_failures'] ) ) {
		exit( 1 );
	}
} catch ( Throwable $e ) {
	$report = array(
		'ok'          => false,
		'mode'        => 'manual-only-content-plugin-matrix-no-client-no-auto-translation',
		'started_at'  => $started_at,
		'finished_at' => gmdate( 'c' ),
		'exception'   => array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		),
		'assertions'  => $GLOBALS['wptsall_e2e_mcm_assertions'] ?? array(),
		'failures'    => array_merge(
			$GLOBALS['wptsall_e2e_mcm_failures'] ?? array(),
			array(
				array(
					'name'   => 'exception',
					'detail' => $e->getMessage(),
				),
			)
		),
		'warnings'    => $GLOBALS['wptsall_e2e_mcm_warnings'] ?? array(),
	);
	wptsall_e2e_mcm_emit( $report );
	exit( 1 );
}
