<?php
/**
 * E2E v2 content-meta-content project verification.
 *
 * Ensures podcast + recipe content is translated and written back to the
 * active targets, while Yoast-style meta write-back is visible on those
 * translated targets.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && E2E_PROJECT=content-meta-content wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-content-meta-content.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Verify content-meta-content Project ===\n\n";

$passed  = 0;
$failed  = 0;
$skipped = 0;
$checks  = array();

/**
 * Local marker helper for project verification.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function e2e_content_meta_has_marker( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}

	return preg_match( E2E_MARKER_PATTERN, $value ) === 1
		|| ( false !== strpos( $value, '【' ) && false !== strpos( $value, '】' ) );
}

/**
 * Load post meta from a specific site.
 *
 * @param int    $blog_id  Blog ID.
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key.
 * @return mixed
 */
function e2e_content_meta_get_post_meta_from_site( int $blog_id, int $post_id, string $meta_key ) {
	if ( $post_id <= 0 || '' === $meta_key ) {
		return '';
	}

	$switched = false;
	if ( is_multisite() && $blog_id > 0 && $blog_id !== get_current_blog_id() && get_blog_details( $blog_id ) ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$value = get_post_meta( $post_id, $meta_key, true );

	if ( $switched ) {
		restore_current_blog();
	}

	return $value;
}

/**
 * Soft-check content-meta taxonomies.
 *
 * @param wpdb              $wpdb                DB handle.
 * @param string            $term_mappings_table Term mapping table name.
 * @param int               $wp_blog_id          Target WP blog id.
 * @param bool              $require_virtual     Whether virtual target proof is required.
 * @param string            $taxonomy            Source taxonomy.
 * @param array<int,mixed> &$checks              Result list.
 * @param int              &$passed              Passed counter.
 * @param int              &$failed              Failed counter.
 * @param int              &$skipped             Skipped counter.
 * @return void
 */
function e2e_content_meta_soft_taxonomy_check( $wpdb, string $term_mappings_table, int $wp_blog_id, bool $require_virtual, string $taxonomy, array &$checks, int &$passed, int &$failed, int &$skipped ): void {
	$source_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy}
			 WHERE taxonomy = %s",
			$taxonomy
		)
	);

	if ( $source_count <= 0 ) {
		echo "SKIP: content-meta extension taxonomy {$taxonomy} has no source terms.\n";
		++$skipped;
		return;
	}

	e2e_check(
		"content-meta extension taxonomy {$taxonomy} source",
		true,
		"found={$source_count}",
		$checks,
		$passed,
		$failed
	);

	$wp_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT target_term_id
			 FROM {$term_mappings_table}
			 WHERE source_taxonomy = %s
			   AND target_site_id = %s
			 ORDER BY id DESC
			 LIMIT 100",
			$taxonomy,
			(string) $wp_blog_id
		),
		ARRAY_A
	);

	$wp_mapped = 0;
	$wp_marked = 0;
	if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
		switch_to_blog( $wp_blog_id );
		foreach ( (array) $wp_rows as $row ) {
			$target_term = get_term( (int) ( $row['target_term_id'] ?? 0 ) );
			if ( ! $target_term || is_wp_error( $target_term ) ) {
				continue;
			}

			++$wp_mapped;
			if ( e2e_content_meta_has_marker( (string) $target_term->name . "\n" . (string) $target_term->description ) ) {
				++$wp_marked;
			}
		}
		restore_current_blog();
	}

	if ( $wp_mapped <= 0 ) {
		echo "SKIP: content-meta extension taxonomy {$taxonomy} has source terms but no term mapping proof yet.\n";
		$skipped += 2;
	} else {
		e2e_check(
			"content-meta extension taxonomy {$taxonomy} wp mappings",
			true,
			"mapped={$wp_mapped}, marked={$wp_marked}, blog={$wp_blog_id}",
			$checks,
			$passed,
			$failed
		);
		e2e_check(
			"content-meta extension taxonomy {$taxonomy} wp markers",
			$wp_marked > 0,
			"mapped={$wp_mapped}, marked={$wp_marked}",
			$checks,
			$passed,
			$failed
		);
	}

	if ( ! $require_virtual ) {
		echo "SKIP: content-meta extension taxonomy {$taxonomy} virtual proof disabled for core-only lane.\n";
		$skipped += 2;
		return;
	}

	$virtual_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT target_term_id
			 FROM {$term_mappings_table}
			 WHERE source_taxonomy = %s
			   AND target_site_id LIKE %s
			 ORDER BY id DESC
			 LIMIT 100",
			$taxonomy,
			'v_%'
		),
		ARRAY_A
	);

	$virtual_mapped = 0;
	$virtual_marked = 0;
	foreach ( (array) $virtual_rows as $row ) {
		$target_term = get_term( (int) ( $row['target_term_id'] ?? 0 ) );
		if ( ! $target_term || is_wp_error( $target_term ) ) {
			continue;
		}

		++$virtual_mapped;
		if ( e2e_content_meta_has_marker( (string) $target_term->name . "\n" . (string) $target_term->description ) ) {
			++$virtual_marked;
		}
	}

	if ( $virtual_mapped <= 0 ) {
		echo "SKIP: content-meta extension taxonomy {$taxonomy} has no virtual term proof yet.\n";
		$skipped += 2;
		return;
	}

	e2e_check(
		"content-meta extension taxonomy {$taxonomy} virtual mappings",
		true,
		"mapped={$virtual_mapped}, marked={$virtual_marked}",
		$checks,
		$passed,
		$failed
	);
	e2e_check(
		"content-meta extension taxonomy {$taxonomy} virtual markers",
		$virtual_marked > 0,
		"mapped={$virtual_mapped}, marked={$virtual_marked}",
		$checks,
		$passed,
		$failed
	);
}

/**
 * Soft-check structured/meta-bearing fields on mapped WP targets.
 *
 * @param wpdb              $wpdb                DB handle.
 * @param string            $post_mappings_table Mapping table name.
 * @param int               $wp_blog_id          Target WP blog id.
 * @param string            $source_post_type    Source post type.
 * @param string            $label               Display label.
 * @param array<int,string> $meta_keys           Meta keys to inspect.
 * @param array<int,mixed> &$checks              Result list.
 * @param int              &$passed              Passed counter.
 * @param int              &$failed              Failed counter.
 * @param int              &$skipped             Skipped counter.
 * @return void
 */
function e2e_content_meta_soft_post_meta_check( $wpdb, string $post_mappings_table, int $wp_blog_id, string $source_post_type, string $label, array $meta_keys, array &$checks, int &$passed, int &$failed, int &$skipped ): void {
	if ( empty( $meta_keys ) ) {
		++$skipped;
		return;
	}

	$meta_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
	$source_args       = array_merge( array( $source_post_type ), $meta_keys );
	$source_count      = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			   AND p.post_status = 'publish'
			   AND pm.meta_key IN ({$meta_placeholders})
			   AND pm.meta_value IS NOT NULL
			   AND pm.meta_value <> ''",
			$source_args
		)
	);

	if ( $source_count <= 0 ) {
		echo "SKIP: content-meta extension {$label} has no source meta evidence.\n";
		++$skipped;
		return;
	}

	e2e_check(
		"content-meta extension {$label} source meta",
		true,
		"entries={$source_count}, keys=" . implode( ',', $meta_keys ),
		$checks,
		$passed,
		$failed
	);

	$wp_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.source_post_id, pm.source_site_id, pm.target_post_id
			 FROM {$post_mappings_table} pm
			 INNER JOIN {$wpdb->posts} src ON src.ID = pm.source_post_id
			 WHERE src.post_type = %s
			   AND pm.target_site_id = %s
			 ORDER BY pm.id DESC
			 LIMIT 100",
			$source_post_type,
			(string) $wp_blog_id
		),
		ARRAY_A
	);

	$wp_mapped    = 0;
	$meta_written = 0;
	$meta_changed = 0;
	if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
		foreach ( (array) $wp_rows as $row ) {
			$source_post_id = (int) ( $row['source_post_id'] ?? 0 );
			$source_site_id = (int) ( $row['source_site_id'] ?? 0 );
			$target_post_id = (int) ( $row['target_post_id'] ?? 0 );
			if ( $source_post_id <= 0 || $target_post_id <= 0 ) {
				continue;
			}

			++$wp_mapped;
			foreach ( $meta_keys as $meta_key ) {
				$source_value = (string) e2e_content_meta_get_post_meta_from_site( $source_site_id, $source_post_id, $meta_key );
				if ( '' === trim( $source_value ) ) {
					continue;
				}

				$target_value = (string) e2e_content_meta_get_post_meta_from_site( $wp_blog_id, $target_post_id, $meta_key );
				if ( '' !== trim( $target_value ) ) {
					++$meta_written;
				}
				if ( '' !== trim( $target_value ) && ( e2e_content_meta_has_marker( $target_value ) || $target_value !== $source_value ) ) {
					++$meta_changed;
				}
			}
		}
	}

	if ( $wp_mapped <= 0 ) {
		echo "SKIP: content-meta extension {$label} has source meta but no wp mapping proof yet.\n";
		++$skipped;
		return;
	}

	e2e_check(
		"content-meta extension {$label} wp meta",
		$meta_written > 0,
		"mapped={$wp_mapped}, written={$meta_written}, changed_or_marked={$meta_changed}",
		$checks,
		$passed,
		$failed
	);
}

if ( ! e2e_is_exact_project( 'content-meta-content' ) ) {
	echo "SKIP: current project is not content-meta-content.\n";
	exit( 0 );
}

$post_mappings_table = e2e_table( 'post_mappings' );
if ( ! e2e_table_exists( $post_mappings_table ) ) {
	echo "ERROR: post_mappings table not found.\n";
	exit( 1 );
}
$term_mappings_table = e2e_table( 'term_mappings' );

$relation_ids    = e2e_load_relation_ids();
$wp_blog_id      = e2e_get_wp_target_blog_id( $relation_ids );
$require_virtual = ! e2e_is_core_only();
$source_types    = array(
	'seriously-simple-podcasting' => 'podcast',
	'wp-recipe-maker'             => 'wprm_recipe',
);

foreach ( $source_types as $source_post_type ) {
	$source_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = %s
			   AND post_status = 'publish'",
			$source_post_type
		)
	);
	e2e_check(
		"content-meta source {$source_post_type}",
		$source_count > 0,
		"found={$source_count}",
		$checks,
		$passed,
		$failed
	);

	$wp_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.target_post_id
			 FROM {$post_mappings_table} pm
			 INNER JOIN {$wpdb->posts} src ON src.ID = pm.source_post_id
			 WHERE src.post_type = %s
			   AND pm.target_site_id = %s
			 ORDER BY pm.id DESC
			 LIMIT 100",
			$source_post_type,
			(string) $wp_blog_id
		),
		ARRAY_A
	);

	$wp_mapped      = 0;
	$wp_marked      = 0;
	$wp_yoast_marked = 0;
	if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
		switch_to_blog( $wp_blog_id );
		foreach ( (array) $wp_rows as $row ) {
			$target_post = get_post( (int) ( $row['target_post_id'] ?? 0 ) );
			if ( ! $target_post ) {
				continue;
			}
			++$wp_mapped;
			if ( e2e_content_meta_has_marker( (string) $target_post->post_title . "\n" . (string) $target_post->post_content . "\n" . (string) $target_post->post_excerpt ) ) {
				++$wp_marked;
			}

			$yoast_blob = (string) get_post_meta( $target_post->ID, '_yoast_wpseo_title', true ) . "\n" . (string) get_post_meta( $target_post->ID, '_yoast_wpseo_metadesc', true );
			if ( e2e_content_meta_has_marker( $yoast_blob ) ) {
				++$wp_yoast_marked;
			}
		}
		restore_current_blog();
	}

	e2e_check(
		"content-meta {$source_post_type} wp mappings",
		$wp_mapped > 0,
		"mapped={$wp_mapped}, marked={$wp_marked}, yoast_marked={$wp_yoast_marked}, blog={$wp_blog_id}",
		$checks,
		$passed,
		$failed
	);
	e2e_check(
		"content-meta {$source_post_type} wp markers",
		$wp_marked > 0,
		"mapped={$wp_mapped}, marked={$wp_marked}",
		$checks,
		$passed,
		$failed
	);
	e2e_check(
		"content-meta {$source_post_type} wp yoast markers",
		$wp_yoast_marked > 0,
		"mapped={$wp_mapped}, yoast_marked={$wp_yoast_marked}",
		$checks,
		$passed,
		$failed
	);

	if ( $require_virtual ) {
		$virtual_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.target_post_id
				 FROM {$post_mappings_table} pm
				 INNER JOIN {$wpdb->posts} src ON src.ID = pm.source_post_id
				 WHERE src.post_type = %s
				   AND pm.target_site_id LIKE %s
				 ORDER BY pm.id DESC
				 LIMIT 100",
				$source_post_type,
				'v_%'
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
			if ( e2e_content_meta_has_marker( (string) $target_post->post_title . "\n" . (string) $target_post->post_content . "\n" . (string) $target_post->post_excerpt ) ) {
				++$virtual_marked;
			}
		}

		e2e_check(
			"content-meta {$source_post_type} virtual mappings",
			$virtual_mapped > 0,
			"mapped={$virtual_mapped}, marked={$virtual_marked}",
			$checks,
			$passed,
			$failed
		);
		e2e_check(
			"content-meta {$source_post_type} virtual markers",
			$virtual_marked > 0,
			"mapped={$virtual_mapped}, marked={$virtual_marked}",
			$checks,
			$passed,
			$failed
		);
	} else {
		echo "SKIP: content-meta {$source_post_type} virtual mapping hard gate disabled for core-only lane.\n";
		$skipped += 2;
	}
}

if ( e2e_table_exists( $term_mappings_table ) ) {
	foreach ( array( 'series', 'wprm_course', 'wprm_cuisine', 'wprm_keyword', 'wprm_ingredient' ) as $taxonomy ) {
		e2e_content_meta_soft_taxonomy_check(
			$wpdb,
			$term_mappings_table,
			$wp_blog_id,
			$require_virtual,
			$taxonomy,
			$checks,
			$passed,
			$failed,
			$skipped
		);
	}
} else {
	echo "SKIP: content-meta extension taxonomy proof disabled because term_mappings table is missing.\n";
	$skipped += 5;
}

e2e_content_meta_soft_post_meta_check(
	$wpdb,
	$post_mappings_table,
	$wp_blog_id,
	'wprm_recipe',
	'recipe structured timing/meta',
	array( 'wprm_prep_time', 'wprm_cook_time', 'wprm_total_time', 'wprm_servings', 'wprm_servings_unit', 'wprm_video_metadata' ),
	$checks,
	$passed,
	$failed,
	$skipped
);

e2e_content_meta_soft_post_meta_check(
	$wpdb,
	$post_mappings_table,
	$wp_blog_id,
	'podcast',
	'podcast yoast focus keyword',
	array( '_yoast_wpseo_focuskw' ),
	$checks,
	$passed,
	$failed,
	$skipped
);

e2e_content_meta_soft_post_meta_check(
	$wpdb,
	$post_mappings_table,
	$wp_blog_id,
	'wprm_recipe',
	'yoast social meta',
	array( '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description' ),
	$checks,
	$passed,
	$failed,
	$skipped
);

e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	exit( 1 );
}
