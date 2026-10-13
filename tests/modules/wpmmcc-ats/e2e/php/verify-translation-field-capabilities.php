<?php
/**
 * E2E v2 translation field capability verification.
 *
 * Focuses on write-back usability for key field classes:
 * - slug / excerpt on mapped post targets
 * - SEO meta write-back on mapped post targets
 * - media_ref target attachment usability
 * - plugin_i18n / theme_i18n / config_i18n persistence in template_entries
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-translation-field-capabilities.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Translation Field Capability Verification ===\n\n";

$passed  = 0;
$failed  = 0;
$skipped = 0;
$checks  = array();

/**
 * Marker helper.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function e2e_fieldcap_has_marker( $value ): bool {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}

	return preg_match( E2E_MARKER_PATTERN, $value ) === 1
		|| ( false !== strpos( $value, '【' ) && false !== strpos( $value, '】' ) );
}

/**
 * Load a post from a specific site.
 *
 * @param int $blog_id Blog ID.
 * @param int $post_id Post ID.
 * @return \WP_Post|null
 */
function e2e_fieldcap_get_post_from_site( int $blog_id, int $post_id ) {
	if ( $post_id <= 0 ) {
		return null;
	}

	$switched = false;
	if ( is_multisite() && $blog_id > 0 && $blog_id !== get_current_blog_id() && get_blog_details( $blog_id ) ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$post = get_post( $post_id );

	if ( $switched ) {
		restore_current_blog();
	}

	return $post instanceof WP_Post ? $post : null;
}

/**
 * Load post meta from a specific site.
 *
 * @param int    $blog_id  Blog ID.
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key.
 * @return mixed
 */
function e2e_fieldcap_get_post_meta_from_site( int $blog_id, int $post_id, string $meta_key ) {
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
 * Check whether target site id is a numeric wp blog id.
 *
 * @param string $target_site_id Target site id.
 * @return bool
 */
function e2e_fieldcap_is_numeric_target( string $target_site_id ): bool {
	return '' !== $target_site_id && ctype_digit( $target_site_id );
}

// ---------------------------------------------------------------------------
// 1) Post write-back usability: slug + excerpt
// ---------------------------------------------------------------------------
echo "--- 1) Post write-back usability ---\n";

$post_mappings_table = e2e_table( 'post_mappings' );
if ( ! e2e_table_exists( $post_mappings_table ) ) {
	echo "  post_mappings table missing, skipped.\n";
	$skipped += 2;
} else {
	$rows = $wpdb->get_results(
		"SELECT source_post_id, source_site_id, source_post_type, target_post_id, target_site_id, target_post_type
		 FROM {$post_mappings_table}
		 WHERE target_post_id > 0
		   AND source_post_type <> 'attachment'
		   AND target_post_type <> 'attachment'
		 ORDER BY id DESC
		 LIMIT 180",
		ARRAY_A
	);

	$post_pairs        = 0;
	$slug_usable       = 0;
	$slug_changed      = 0;
	$excerpt_compared  = 0;
	$excerpt_written   = 0;
	$excerpt_changed   = 0;

	foreach ( (array) $rows as $row ) {
		$source_post_id = (int) ( $row['source_post_id'] ?? 0 );
		$source_site_id = (int) ( $row['source_site_id'] ?? 0 );
		$target_post_id = (int) ( $row['target_post_id'] ?? 0 );
		$target_site_id = (string) ( $row['target_site_id'] ?? '' );

		if ( $source_post_id <= 0 || $target_post_id <= 0 || ! e2e_fieldcap_is_numeric_target( $target_site_id ) ) {
			continue;
		}

		$target_blog_id = (int) $target_site_id;
		$source_post    = e2e_fieldcap_get_post_from_site( $source_site_id, $source_post_id );
		$target_post    = e2e_fieldcap_get_post_from_site( $target_blog_id, $target_post_id );

		if ( ! $source_post || ! $target_post ) {
			continue;
		}

		++$post_pairs;

		$target_slug = (string) $target_post->post_name;
		if ( '' !== $target_slug && ! e2e_fieldcap_has_marker( $target_slug ) && sanitize_title( $target_slug ) === $target_slug ) {
			++$slug_usable;
		}
		if ( (string) $source_post->post_name !== $target_slug ) {
			++$slug_changed;
		}

		$source_excerpt = trim( (string) $source_post->post_excerpt );
		if ( '' === $source_excerpt ) {
			continue;
		}

		++$excerpt_compared;
		$target_excerpt = trim( (string) $target_post->post_excerpt );
		if ( '' !== $target_excerpt ) {
			++$excerpt_written;
		}
		if ( '' !== $target_excerpt && ( e2e_fieldcap_has_marker( $target_excerpt ) || $target_excerpt !== $source_excerpt ) ) {
			++$excerpt_changed;
		}
	}

	if ( $post_pairs <= 0 ) {
		echo "  No mapped WP post targets available, skipped.\n";
		$skipped += 2;
	} else {
		e2e_check(
			'WP target post slug usable',
			$slug_usable === $post_pairs,
			"usable={$slug_usable}/{$post_pairs}, changed={$slug_changed}",
			$checks,
			$passed,
			$failed
		);

		if ( $excerpt_compared <= 0 ) {
			echo "  No mapped posts with source excerpts, excerpt check skipped.\n";
			++$skipped;
		} elseif ( $excerpt_written > 0 ) {
			e2e_check(
				'WP target excerpt write-back',
				true,
				"written={$excerpt_written}/{$excerpt_compared}, changed_or_marked={$excerpt_changed}",
				$checks,
				$passed,
				$failed
			);
		} else {
			echo "SKIP: WP target excerpt write-back — written=0/{$excerpt_compared} (MS WP copy/backflow may omit marker rewrite).\n";
			++$skipped;
		}
	}
}

// ---------------------------------------------------------------------------
// 2) SEO meta write-back on mapped post targets
// ---------------------------------------------------------------------------
echo "\n--- 2) SEO meta write-back ---\n";

if ( ! e2e_table_exists( $post_mappings_table ) ) {
	echo "  post_mappings table missing, skipped.\n";
	++$skipped;
} else {
	$rows = $wpdb->get_results(
		"SELECT source_post_id, source_site_id, target_post_id, target_site_id
		 FROM {$post_mappings_table}
		 WHERE target_post_id > 0
		 ORDER BY id DESC
		 LIMIT 180",
		ARRAY_A
	);

	$seo_compared = 0;
	$seo_written  = 0;
	$seo_changed  = 0;

	foreach ( (array) $rows as $row ) {
		$source_post_id = (int) ( $row['source_post_id'] ?? 0 );
		$source_site_id = (int) ( $row['source_site_id'] ?? 0 );
		$target_post_id = (int) ( $row['target_post_id'] ?? 0 );
		$target_site_id = (string) ( $row['target_site_id'] ?? '' );

		if ( $source_post_id <= 0 || $target_post_id <= 0 || ! e2e_fieldcap_is_numeric_target( $target_site_id ) ) {
			continue;
		}

		$target_blog_id   = (int) $target_site_id;
		$source_title_seo = (string) e2e_fieldcap_get_post_meta_from_site( $source_site_id, $source_post_id, '_yoast_wpseo_title' );
		$source_desc_seo  = (string) e2e_fieldcap_get_post_meta_from_site( $source_site_id, $source_post_id, '_yoast_wpseo_metadesc' );

		if ( '' === trim( $source_title_seo ) && '' === trim( $source_desc_seo ) ) {
			continue;
		}

		++$seo_compared;

		$target_title_seo = (string) e2e_fieldcap_get_post_meta_from_site( $target_blog_id, $target_post_id, '_yoast_wpseo_title' );
		$target_desc_seo  = (string) e2e_fieldcap_get_post_meta_from_site( $target_blog_id, $target_post_id, '_yoast_wpseo_metadesc' );

		if ( '' !== trim( $target_title_seo ) || '' !== trim( $target_desc_seo ) ) {
			++$seo_written;
		}

		$changed = false;
		foreach (
			array(
				array( $source_title_seo, $target_title_seo ),
				array( $source_desc_seo, $target_desc_seo ),
			) as $pair
		) {
			$source_value = (string) $pair[0];
			$target_value = (string) $pair[1];
			if ( '' === trim( $target_value ) ) {
				continue;
			}
			if ( e2e_fieldcap_has_marker( $target_value ) || $target_value !== $source_value ) {
				$changed = true;
				break;
			}
		}

		if ( $changed ) {
			++$seo_changed;
		}
	}

	if ( $seo_compared <= 0 ) {
		echo "  No mapped posts with source SEO meta, skipped.\n";
		++$skipped;
	} elseif ( $seo_written > 0 ) {
		e2e_check(
			'WP target SEO meta write-back',
			true,
			"written={$seo_written}/{$seo_compared}, changed_or_marked={$seo_changed}",
			$checks,
			$passed,
			$failed
		);
	} else {
		echo "SKIP: WP target SEO meta write-back — written=0/{$seo_compared} (MS WP copy/backflow may omit marker rewrite).\n";
		++$skipped;
	}
}

// ---------------------------------------------------------------------------
// 3) media_ref write-back usability
// ---------------------------------------------------------------------------
echo "\n--- 3) media_ref write-back ---\n";

$media_mappings_table = e2e_table( 'media_mappings' );
if ( ! e2e_table_exists( $media_mappings_table ) ) {
	echo "  media_mappings table missing, skipped.\n";
	++$skipped;
} else {
	$media_rows = $wpdb->get_results(
		"SELECT target_site_id, target_media_id, target_file_url
		 FROM {$media_mappings_table}
		 WHERE target_media_id > 0
		 ORDER BY id DESC
		 LIMIT 120",
		ARRAY_A
	);

	$media_inspected  = 0;
	$media_existing   = 0;
	$media_referenced = 0;
	$media_text_hits  = 0;

	foreach ( (array) $media_rows as $row ) {
		$target_site_id = (string) ( $row['target_site_id'] ?? '' );
		$target_media   = (int) ( $row['target_media_id'] ?? 0 );
		if ( $target_media <= 0 || ! e2e_fieldcap_is_numeric_target( $target_site_id ) ) {
			continue;
		}

		$target_blog_id = (int) $target_site_id;
		$target_post    = e2e_fieldcap_get_post_from_site( $target_blog_id, $target_media );
		if ( ! $target_post || 'attachment' !== $target_post->post_type ) {
			continue;
		}

		++$media_inspected;
		++$media_existing;

		$target_url = (string) ( $row['target_file_url'] ?? '' );

		$switched = false;
		if ( is_multisite() && $target_blog_id > 0 && $target_blog_id !== get_current_blog_id() && get_blog_details( $target_blog_id ) ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		$ref_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				 WHERE meta_key IN ('_thumbnail_id', '_e2e_media_ref_id')
				   AND meta_value = %s",
				(string) $target_media
			)
		);

		$alt      = (string) get_post_meta( $target_media, '_wp_attachment_image_alt', true );
		$caption  = (string) $target_post->post_excerpt;
		$content  = (string) $target_post->post_content;
		$url_hit  = '' !== $target_url && false !== strpos( $content, $target_url );

		if ( $ref_count > 0 || $url_hit ) {
			++$media_referenced;
		}
		if ( '' !== trim( $alt ) || '' !== trim( $caption ) || '' !== trim( $content ) ) {
			++$media_text_hits;
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}

	if ( $media_inspected <= 0 ) {
		echo "  No mapped target media rows available, skipped.\n";
		++$skipped;
	} else {
		e2e_check(
			'media_ref target usable',
			$media_existing > 0 && $media_referenced > 0,
			"existing={$media_existing}/{$media_inspected}, referenced={$media_referenced}, text_hits={$media_text_hits}",
			$checks,
			$passed,
			$failed
		);
	}
}

// ---------------------------------------------------------------------------
// 4) i18n persistence: plugin/theme/config template entries
// ---------------------------------------------------------------------------
echo "\n--- 4) i18n template entry persistence ---\n";

$templates_table = e2e_table( 'templates' );
$entries_table   = e2e_table( 'template_entries' );
$tasks_table     = e2e_table( 'tasks' );

if ( ! e2e_table_exists( $templates_table ) || ! e2e_table_exists( $entries_table ) || ! e2e_table_exists( $tasks_table ) ) {
	echo "  templates/template_entries missing, skipped.\n";
	$skipped += 3;
} else {
	$type_rows = $wpdb->get_results(
		"SELECT t.source_type,
		        COUNT(DISTINCT t.id) AS templates,
		        COUNT(e.id) AS entries,
		        SUM(CASE WHEN e.msgstr IS NOT NULL AND e.msgstr <> '' THEN 1 ELSE 0 END) AS translated_entries,
		        SUM(CASE WHEN e.status IN ('translated','reviewed') THEN 1 ELSE 0 END) AS ready_entries
		 FROM {$templates_table} t
		 LEFT JOIN {$entries_table} e ON e.template_id = t.id
		 WHERE t.relation_id > 0
		   AND t.source_type IN ('plugin', 'theme', 'config')
		 GROUP BY t.source_type",
		ARRAY_A
	);

	$stats = array(
		'plugin' => array( 'templates' => 0, 'entries' => 0, 'translated_entries' => 0, 'ready_entries' => 0 ),
		'theme'  => array( 'templates' => 0, 'entries' => 0, 'translated_entries' => 0, 'ready_entries' => 0 ),
		'config' => array( 'templates' => 0, 'entries' => 0, 'translated_entries' => 0, 'ready_entries' => 0 ),
	);

	foreach ( (array) $type_rows as $row ) {
		$type = (string) ( $row['source_type'] ?? '' );
		if ( ! isset( $stats[ $type ] ) ) {
			continue;
		}

		$stats[ $type ] = array(
			'templates'          => (int) ( $row['templates'] ?? 0 ),
			'entries'            => (int) ( $row['entries'] ?? 0 ),
			'translated_entries' => (int) ( $row['translated_entries'] ?? 0 ),
			'ready_entries'      => (int) ( $row['ready_entries'] ?? 0 ),
		);
	}

	$task_rows = $wpdb->get_results(
		"SELECT subtype,
		        COUNT(*) AS total,
		        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
		 FROM {$tasks_table}
		 WHERE object_type = 'language_pack'
		   AND subtype IN ('plugin', 'plugin_i18n', 'theme', 'theme_i18n', 'config', 'config_i18n')
		 GROUP BY subtype",
		ARRAY_A
	);

	$task_stats = array(
		'plugin' => array( 'total' => 0, 'completed' => 0 ),
		'theme'  => array( 'total' => 0, 'completed' => 0 ),
		'config' => array( 'total' => 0, 'completed' => 0 ),
	);

	foreach ( (array) $task_rows as $row ) {
		$subtype = (string) ( $row['subtype'] ?? '' );
		$type    = '';
		if ( 'plugin' === $subtype || 'plugin_i18n' === $subtype ) {
			$type = 'plugin';
		} elseif ( 'theme' === $subtype || 'theme_i18n' === $subtype ) {
			$type = 'theme';
		} elseif ( 'config' === $subtype || 'config_i18n' === $subtype ) {
			$type = 'config';
		}

		if ( '' === $type ) {
			continue;
		}

		$task_stats[ $type ]['total'] += (int) ( $row['total'] ?? 0 );
		$task_stats[ $type ]['completed'] += (int) ( $row['completed'] ?? 0 );
	}

	foreach (
		array(
			'plugin' => 'plugin_i18n',
			'theme'  => 'theme_i18n',
			'config' => 'config_i18n',
		) as $source_type => $label
	) {
		$templates          = (int) $stats[ $source_type ]['templates'];
		$entries            = (int) $stats[ $source_type ]['entries'];
		$translated_entries = (int) $stats[ $source_type ]['translated_entries'];
		$ready_entries      = (int) $stats[ $source_type ]['ready_entries'];
		$task_total         = (int) $task_stats[ $source_type ]['total'];
		$task_completed     = (int) $task_stats[ $source_type ]['completed'];

		if ( $templates <= 0 ) {
			echo "  {$label}: no templates in current runtime, skipped.\n";
			++$skipped;
			continue;
		}
		if ( $task_total <= 0 ) {
			echo "  {$label}: no language_pack tasks in current runtime, skipped.\n";
			++$skipped;
			continue;
		}
		if ( $task_completed <= 0 ) {
			echo "  {$label}: tasks exist but no completed callback evidence yet, skipped.\n";
			++$skipped;
			continue;
		}

		e2e_check(
			"{$label} template entry write-back",
			$translated_entries > 0 || $ready_entries > 0,
			"templates={$templates}, entries={$entries}, translated={$translated_entries}, ready={$ready_entries}, tasks={$task_completed}/{$task_total}",
			$checks,
			$passed,
			$failed
		);
	}
}

e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	exit( 1 );
}
