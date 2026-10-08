<?php
/**
 * E2E v2 learning-content project verification.
 *
 * Ensures Tutor + LearnPress seeded objects are actually translated/written
 * back to both wp and virtual targets in the current dataset.
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && E2E_PROJECT=learning-content wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-learning-content.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Verify learning-content Project ===\n\n";

$passed = 0;
$failed = 0;
$skipped = 0;
$checks = array();

/**
 * Local marker helper for project verification.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function e2e_learning_has_marker( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}

	return preg_match( E2E_MARKER_PATTERN, $value ) === 1
		|| ( false !== strpos( $value, '【' ) && false !== strpos( $value, '】' ) );
}

/**
 * Soft-check additional learning post objects.
 *
 * @param wpdb              $wpdb                DB handle.
 * @param string            $post_mappings_table Mapping table name.
 * @param int               $wp_blog_id          Target WP blog id.
 * @param bool              $require_virtual     Whether virtual target proof is required.
 * @param string            $source_post_type    Source post type.
 * @param array<int,mixed> &$checks              Result list.
 * @param int              &$passed              Passed counter.
 * @param int              &$failed              Failed counter.
 * @param int              &$skipped             Skipped counter.
 * @return void
 */
function e2e_learning_soft_post_object_check( $wpdb, string $post_mappings_table, int $wp_blog_id, bool $require_virtual, string $source_post_type, array &$checks, int &$passed, int &$failed, int &$skipped ): void {
	$source_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = %s
			   AND post_status = 'publish'",
			$source_post_type
		)
	);

	if ( $source_count <= 0 ) {
		echo "SKIP: learning extension {$source_post_type} has no published source objects.\n";
		++$skipped;
		return;
	}

	e2e_check(
		"learning extension source {$source_post_type}",
		true,
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

	$wp_mapped = 0;
	$wp_marked = 0;
	if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
		switch_to_blog( $wp_blog_id );
		foreach ( (array) $wp_rows as $row ) {
			$target_post = get_post( (int) ( $row['target_post_id'] ?? 0 ) );
			if ( ! $target_post ) {
				continue;
			}

			++$wp_mapped;
			if ( e2e_learning_has_marker( (string) $target_post->post_title . "\n" . (string) $target_post->post_content . "\n" . (string) $target_post->post_excerpt ) ) {
				++$wp_marked;
			}
		}
		restore_current_blog();
	}

	if ( $wp_mapped <= 0 ) {
		echo "SKIP: learning extension {$source_post_type} has source data but no wp mapping proof yet.\n";
		$skipped += 2;
	} else {
		e2e_check(
			"learning extension {$source_post_type} wp mappings",
			true,
			"mapped={$wp_mapped}, marked={$wp_marked}, blog={$wp_blog_id}",
			$checks,
			$passed,
			$failed
		);
		e2e_check(
			"learning extension {$source_post_type} wp markers",
			$wp_marked > 0,
			"mapped={$wp_mapped}, marked={$wp_marked}",
			$checks,
			$passed,
			$failed
		);
	}

	if ( ! $require_virtual ) {
		echo "SKIP: learning extension {$source_post_type} virtual proof disabled for core-only lane.\n";
		$skipped += 2;
		return;
	}

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
		if ( e2e_learning_has_marker( (string) $target_post->post_title . "\n" . (string) $target_post->post_content . "\n" . (string) $target_post->post_excerpt ) ) {
			++$virtual_marked;
		}
	}

	if ( $virtual_mapped <= 0 ) {
		echo "SKIP: learning extension {$source_post_type} has no virtual mapping proof yet.\n";
		$skipped += 2;
		return;
	}

	e2e_check(
		"learning extension {$source_post_type} virtual mappings",
		true,
		"mapped={$virtual_mapped}, marked={$virtual_marked}",
		$checks,
		$passed,
		$failed
	);
	e2e_check(
		"learning extension {$source_post_type} virtual markers",
		$virtual_marked > 0,
		"mapped={$virtual_mapped}, marked={$virtual_marked}",
		$checks,
		$passed,
		$failed
	);
}

/**
 * Soft-check learning course taxonomies.
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
function e2e_learning_soft_taxonomy_check( $wpdb, string $term_mappings_table, int $wp_blog_id, bool $require_virtual, string $taxonomy, array &$checks, int &$passed, int &$failed, int &$skipped ): void {
	$source_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy}
			 WHERE taxonomy = %s",
			$taxonomy
		)
	);

	if ( $source_count <= 0 ) {
		echo "SKIP: learning extension taxonomy {$taxonomy} has no source terms.\n";
		++$skipped;
		return;
	}

	e2e_check(
		"learning extension taxonomy {$taxonomy} source",
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
			if ( e2e_learning_has_marker( (string) $target_term->name . "\n" . (string) $target_term->description ) ) {
				++$wp_marked;
			}
		}
		restore_current_blog();
	}

	if ( $wp_mapped <= 0 ) {
		echo "SKIP: learning extension taxonomy {$taxonomy} has source terms but no term mapping proof yet.\n";
		$skipped += 2;
	} else {
		e2e_check(
			"learning extension taxonomy {$taxonomy} wp mappings",
			true,
			"mapped={$wp_mapped}, marked={$wp_marked}, blog={$wp_blog_id}",
			$checks,
			$passed,
			$failed
		);
		e2e_check(
			"learning extension taxonomy {$taxonomy} wp markers",
			$wp_marked > 0,
			"mapped={$wp_mapped}, marked={$wp_marked}",
			$checks,
			$passed,
			$failed
		);
	}

	if ( ! $require_virtual ) {
		echo "SKIP: learning extension taxonomy {$taxonomy} virtual proof disabled for core-only lane.\n";
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
		if ( e2e_learning_has_marker( (string) $target_term->name . "\n" . (string) $target_term->description ) ) {
			++$virtual_marked;
		}
	}

	if ( $virtual_mapped <= 0 ) {
		echo "SKIP: learning extension taxonomy {$taxonomy} has no virtual term proof yet.\n";
		$skipped += 2;
		return;
	}

	e2e_check(
		"learning extension taxonomy {$taxonomy} virtual mappings",
		true,
		"mapped={$virtual_mapped}, marked={$virtual_marked}",
		$checks,
		$passed,
		$failed
	);
	e2e_check(
		"learning extension taxonomy {$taxonomy} virtual markers",
		$virtual_marked > 0,
		"mapped={$virtual_mapped}, marked={$virtual_marked}",
		$checks,
		$passed,
		$failed
	);
}

if ( ! e2e_is_exact_project( 'learning-content' ) ) {
	echo "SKIP: current project is not learning-content.\n";
	exit( 0 );
}

$post_mappings_table = e2e_table( 'post_mappings' );
if ( ! e2e_table_exists( $post_mappings_table ) ) {
	echo "ERROR: post_mappings table not found.\n";
	exit( 1 );
}
$term_mappings_table = e2e_table( 'term_mappings' );

$relation_ids = e2e_load_relation_ids();
$wp_blog_id   = e2e_get_wp_target_blog_id( $relation_ids );
$require_virtual = ! e2e_is_core_only();
$source_types = array(
	'tutor'      => 'courses',
	'learnpress' => 'lp_course',
);

foreach ( $source_types as $label => $source_post_type ) {
	$source_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = %s
			   AND post_status = 'publish'",
			$source_post_type
		)
	);
	e2e_check(
		"learning source {$source_post_type}",
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

	$wp_mapped = 0;
	$wp_marked = 0;
	if ( $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
		switch_to_blog( $wp_blog_id );
		foreach ( (array) $wp_rows as $row ) {
			$target_post = get_post( (int) ( $row['target_post_id'] ?? 0 ) );
			if ( ! $target_post ) {
			continue;
		}
		++$wp_mapped;
		if ( e2e_learning_has_marker( (string) $target_post->post_title . "\n" . (string) $target_post->post_content ) ) {
			++$wp_marked;
		}
	}
		restore_current_blog();
	}

	// Align with verify-plugin-project.php: core-only Stage 6 no longer drives
	// Client website-OAuth writeback; mapping/marker hard gates live in
	// automatic-plugin-slice / full-scope lanes instead.
	if ( e2e_is_core_only() ) {
		echo "SKIP: learning {$source_post_type} wp mapping hard gate disabled for core-only lane.\n";
		++$skipped;
		echo "SKIP: learning {$source_post_type} wp marker hard gate disabled for core-only lane.\n";
		++$skipped;
	} else {
		e2e_check(
			"learning {$source_post_type} wp mappings",
			$wp_mapped > 0,
			"mapped={$wp_mapped}, marked={$wp_marked}, blog={$wp_blog_id}",
			$checks,
			$passed,
			$failed
		);
		e2e_check(
			"learning {$source_post_type} wp markers",
			$wp_marked > 0,
			"mapped={$wp_mapped}, marked={$wp_marked}",
			$checks,
			$passed,
			$failed
		);
	}

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
				if ( e2e_learning_has_marker( (string) $target_post->post_title . "\n" . (string) $target_post->post_content ) ) {
					++$virtual_marked;
				}
			}

			e2e_check(
				"learning {$source_post_type} virtual mappings",
				$virtual_mapped > 0,
				"mapped={$virtual_mapped}, marked={$virtual_marked}",
				$checks,
				$passed,
				$failed
			);
			e2e_check(
				"learning {$source_post_type} virtual markers",
				$virtual_marked > 0,
				"mapped={$virtual_mapped}, marked={$virtual_marked}",
				$checks,
				$passed,
				$failed
			);
		} else {
			echo "SKIP: learning {$source_post_type} virtual mapping hard gate disabled for core-only lane.\n";
		$skipped += 2;
	}
}

foreach ( array( 'lesson', 'tutor_quiz', 'lp_lesson', 'lp_quiz' ) as $extension_post_type ) {
	e2e_learning_soft_post_object_check(
		$wpdb,
		$post_mappings_table,
		$wp_blog_id,
		$require_virtual,
		$extension_post_type,
		$checks,
		$passed,
		$failed,
		$skipped
	);
}

if ( e2e_table_exists( $term_mappings_table ) ) {
	foreach ( array( 'course-category', 'course-tag', 'course_category', 'course_tag' ) as $taxonomy ) {
		e2e_learning_soft_taxonomy_check(
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
	echo "SKIP: learning extension taxonomy proof disabled because term_mappings table is missing.\n";
	$skipped += 4;
}

e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	exit( 1 );
}
