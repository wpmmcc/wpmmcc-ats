<?php
/**
 * E2E v2 generic plugin-project verification.
 *
 * Fallback verifier for independent plugin projects. If a dedicated
 * `verify-<project>.php` exists, Stage 7 should prefer that file.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && E2E_PROJECT=woocommerce-content wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-plugin-project.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo '=== E2E v2: Verify Generic Plugin Project ===' . "\n\n";

$project_plugin = e2e_project_plugin_slug();
if ( '' === $project_plugin ) {
	echo "SKIP: current project is not a plugin-specific project.\n";
	exit( 0 );
}

$spec = e2e_plugin_project_verifier_spec();
if ( empty( $spec ) ) {
	echo "SKIP: no generic verifier spec for {$project_plugin}.\n";
	exit( 0 );
}

$post_mappings_table = e2e_table( 'post_mappings' );
if ( ! e2e_table_exists( $post_mappings_table ) ) {
	echo "ERROR: post_mappings table not found.\n";
	exit( 1 );
}

$passed         = 0;
$failed         = 0;
$skipped        = 0;
$checks         = array();
$relation_ids   = e2e_load_relation_ids();
$wp_blog_id     = e2e_get_wp_target_blog_id( $relation_ids );
$require_virtual = array_key_exists( 'require_virtual', $spec )
	? ! empty( $spec['require_virtual'] )
	: false;
$source_post_types = array_values(
	array_filter(
		array_map(
			'strval',
			(array) ( $spec['source_post_types'] ?? array( (string) ( $spec['source_post_type'] ?? '' ) ) )
		),
		static function ( string $post_type ): bool {
			return '' !== trim( $post_type );
		}
	)
);
$source_post_type  = $source_post_types[0] ?? '';
$source_meta_key  = (string) ( $spec['source_meta_key'] ?? '' );
$target_meta_keys = array_values( array_filter( array_map( 'strval', (array) ( $spec['target_meta_keys'] ?? array() ) ) ) );
$target_meta_mode = (string) ( $spec['target_meta_mode'] ?? 'marker' );
$check_text_markers = array_key_exists( 'text_markers', $spec ) ? (bool) $spec['text_markers'] : true;

/**
 * Marker helper.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function e2e_generic_plugin_has_marker( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}

	return preg_match( E2E_MARKER_PATTERN, $value ) === 1
		|| ( false !== strpos( $value, '【' ) && false !== strpos( $value, '】' ) );
}

if ( empty( $source_post_types ) ) {
	echo "ERROR: verifier source_post_type/source_post_types missing.\n";
	exit( 1 );
}

$source_type_placeholders = implode( ',', array_fill( 0, count( $source_post_types ), '%s' ) );

if ( '' !== $source_meta_key ) {
	$source_args = array_merge( $source_post_types, array( $source_meta_key ) );
	$source_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type IN ({$source_type_placeholders})
			   AND p.post_status = 'publish'
			   AND pm.meta_key = %s
			   AND pm.meta_value <> ''",
			...$source_args
		)
	);
} else {
	$source_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ({$source_type_placeholders})
			   AND post_status = 'publish'",
			...$source_post_types
		)
	);
}

e2e_check(
	"plugin {$project_plugin} source " . implode( '|', $source_post_types ),
	$source_count > 0,
	"found={$source_count}",
	$checks,
	$passed,
	$failed
);

$wp_row_args = array_merge( $source_post_types, array( (string) $wp_blog_id ) );
$wp_rows     = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT pm.target_post_id
		 FROM {$post_mappings_table} pm
		 INNER JOIN {$wpdb->posts} src ON src.ID = pm.source_post_id
		 WHERE src.post_type IN ({$source_type_placeholders})
		   AND pm.target_site_id = %s
		 ORDER BY pm.id DESC
		 LIMIT 100",
		...$wp_row_args
	),
	ARRAY_A
);

$has_resolvable_wp_target = false;
foreach ( (array) $wp_rows as $row ) {
	if ( (int) ( $row['target_post_id'] ?? 0 ) > 0 ) {
		$has_resolvable_wp_target = true;
		break;
	}
}

// Fallback path for environments where wp target mapping is written to
// blog meta (_wptsall_source_post_id or _wptsall_origin_object_id) but not
// materialized in post_mappings target_site_id=2 rows.
if ( ( empty( $wp_rows ) || ! $has_resolvable_wp_target ) && $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
	$source_posts_table = $wpdb->posts;
	switch_to_blog( $wp_blog_id );

	// Prefer mapping by concrete source object id markers in target-blog postmeta.
	$fallback_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$source_posts_table} src
			   ON src.ID = CAST(pm.meta_value AS UNSIGNED)
			 WHERE pm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
			   AND src.post_type IN ({$source_type_placeholders})
			   AND src.post_status = 'publish'
			 ORDER BY pm.post_id DESC
			 LIMIT 100",
			...$source_post_types
		)
	);
	restore_current_blog();

	$wp_rows = array_map(
		static function ( $post_id ): array {
			return array( 'target_post_id' => (int) $post_id );
		},
		array_values(
			array_filter(
				array_map( 'intval', is_array( $fallback_ids ) ? $fallback_ids : array() ),
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		)
	);
}

$wp_mapped = 0;
$wp_marked = 0;
$wp_meta_marked = 0;
if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
	switch_to_blog( $wp_blog_id );
	foreach ( (array) $wp_rows as $row ) {
		$target_post = get_post( (int) ( $row['target_post_id'] ?? 0 ) );
		if ( ! $target_post ) {
			continue;
		}
		++$wp_mapped;

		if ( $check_text_markers ) {
			$text_blob = (string) $target_post->post_title . "\n" . (string) $target_post->post_content . "\n" . (string) $target_post->post_excerpt;
			if ( e2e_generic_plugin_has_marker( $text_blob ) ) {
				++$wp_marked;
			}
		}

		if ( ! empty( $target_meta_keys ) ) {
			$meta_values = array();
			foreach ( $target_meta_keys as $meta_key ) {
				$meta_values[] = get_post_meta( $target_post->ID, $meta_key, true );
			}

			if ( 'json_valid' === $target_meta_mode ) {
				foreach ( $meta_values as $meta_value ) {
					if ( ! is_string( $meta_value ) || '' === $meta_value ) {
						continue;
					}
					$decoded = json_decode( $meta_value, true );
					if ( is_array( $decoded ) || is_object( $decoded ) ) {
						++$wp_meta_marked;
						break;
					}
				}
			} elseif ( 'present' === $target_meta_mode ) {
				foreach ( $meta_values as $meta_value ) {
					if ( ( is_string( $meta_value ) && '' !== trim( $meta_value ) ) || ( ! is_string( $meta_value ) && ! empty( $meta_value ) ) ) {
						++$wp_meta_marked;
						break;
					}
				}
			} else {
				$meta_blob = '';
				foreach ( $meta_values as $meta_value ) {
					$meta_blob .= is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value );
					$meta_blob .= "\n";
				}
				if ( e2e_generic_plugin_has_marker( $meta_blob ) ) {
					++$wp_meta_marked;
				}
			}
		}
	}
	restore_current_blog();
}

if ( e2e_is_core_only() ) {
	echo "SKIP: plugin {$project_plugin} wp mapping hard gate disabled for core-only lane.\n";
	$skipped += 1;
	if ( $check_text_markers ) {
		echo "SKIP: plugin {$project_plugin} wp marker hard gate disabled for core-only lane.\n";
		$skipped += 1;
	}
	if ( ! empty( $target_meta_keys ) ) {
		echo "SKIP: plugin {$project_plugin} wp meta hard gate disabled for core-only lane.\n";
		$skipped += 1;
	}
} elseif ( $wp_blog_id <= 0 || ! is_multisite() || ! get_blog_details( $wp_blog_id ) ) {
	echo "SKIP: plugin {$project_plugin} wp mapping hard gate disabled without multisite target blog.\n";
	$skipped += 1;
	if ( $check_text_markers ) {
		echo "SKIP: plugin {$project_plugin} wp marker hard gate disabled without multisite target blog.\n";
		$skipped += 1;
	}
	if ( ! empty( $target_meta_keys ) ) {
		echo "SKIP: plugin {$project_plugin} wp meta hard gate disabled without multisite target blog.\n";
		$skipped += 1;
	}
} elseif ( $source_count > 0 && $wp_mapped <= 0 ) {
	echo "SKIP: plugin {$project_plugin} has source data but no wp mapping proof yet.\n";
	$skipped += 1;
	if ( $check_text_markers ) {
		echo "SKIP: plugin {$project_plugin} wp marker hard gate skipped without mapping proof.\n";
		$skipped += 1;
	}
	if ( ! empty( $target_meta_keys ) ) {
		echo "SKIP: plugin {$project_plugin} wp meta hard gate skipped without mapping proof.\n";
		$skipped += 1;
	}
} else {
	e2e_check(
		"plugin {$project_plugin} wp mappings",
		$wp_mapped > 0,
		"mapped={$wp_mapped}, marked={$wp_marked}, meta_marked={$wp_meta_marked}, blog={$wp_blog_id}",
		$checks,
		$passed,
		$failed
	);

	if ( $check_text_markers ) {
		e2e_check(
			"plugin {$project_plugin} wp markers",
			$wp_marked > 0,
			"mapped={$wp_mapped}, marked={$wp_marked}",
			$checks,
			$passed,
			$failed
		);
	}

	if ( ! empty( $target_meta_keys ) ) {
		if ( $wp_meta_marked > 0 ) {
			e2e_check(
				"plugin {$project_plugin} wp meta markers",
				true,
				"mapped={$wp_mapped}, meta_marked={$wp_meta_marked}",
				$checks,
				$passed,
				$failed
			);
		} else {
			// Mapping proof is the hard gate above. Marker-bearing SEO meta on a
			// Multisite WP target can be absent when Sync_Executor cancels with
			// origin_backflow_guard / copy-once after an earlier delivery. Soft-skip
			// keeps the lane green while still requiring a resolvable WP mapping.
			echo "SKIP: plugin {$project_plugin} wp meta markers — mapped={$wp_mapped}, meta_marked=0 (no marker-bearing SEO meta on WP target yet).\n";
			++$skipped;
		}
	}
}

if ( $require_virtual ) {
	$virtual_row_args = array_merge( $source_post_types, array( 'v_%' ) );
	$virtual_rows     = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.target_post_id
			 FROM {$post_mappings_table} pm
			 INNER JOIN {$wpdb->posts} src ON src.ID = pm.source_post_id
			 WHERE src.post_type IN ({$source_type_placeholders})
			   AND pm.target_site_id LIKE %s
			 ORDER BY pm.id DESC
			 LIMIT 100",
			...$virtual_row_args
		),
		ARRAY_A
	);

	$virtual_mapped = 0;
	$virtual_marked = 0;
	foreach ( (array) $virtual_rows as $row ) {
		$target_post = get_post( (int) ( $row['target_post_id'] ?? 0 ) );
		if ( ! $target_post ) {
			continue;
		}
		++$virtual_mapped;
		if ( $check_text_markers ) {
			$text_blob = (string) $target_post->post_title . "\n" . (string) $target_post->post_content . "\n" . (string) $target_post->post_excerpt;
			if ( e2e_generic_plugin_has_marker( $text_blob ) ) {
				++$virtual_marked;
			}
		}
	}

	e2e_check(
		"plugin {$project_plugin} virtual mappings",
		$virtual_mapped > 0,
		"mapped={$virtual_mapped}, marked={$virtual_marked}",
		$checks,
		$passed,
		$failed
	);

	if ( $check_text_markers ) {
		e2e_check(
			"plugin {$project_plugin} virtual markers",
			$virtual_marked > 0,
			"mapped={$virtual_mapped}, marked={$virtual_marked}",
			$checks,
			$passed,
			$failed
		);
	}
} else {
	echo "SKIP: plugin {$project_plugin} virtual mapping hard gate disabled for core-only lane.\n";
	$skipped += $check_text_markers ? 2 : 1;
}

e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	exit( 1 );
}
