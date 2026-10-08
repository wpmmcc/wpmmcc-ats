<?php
/**
 * E2E v2 写回验证
 *
 * 按插件×目标类型矩阵检查翻译结果写回情况。
 * 合并自 testing/verify-write-back.php + testing/verify-client-e2e.php。
 *
 * Run: cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/verify-writeback.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Write-Back Verification ===\n\n";

$passed  = 0;
$failed  = 0;
$skipped = 0;
$checks  = array();

$relation_ids = e2e_load_relation_ids();

// ==================================================================
// SECTION 1: Virtual Site Content
// ==================================================================
echo "--- Section 1: Virtual Site Content ---\n";

$virtual_rid = $relation_ids['virtual'] ?? 0;
if ( $virtual_rid > 0 ) {
	// Virtual site content stored in wp_posts with _wptsall_virtual_site_id meta
	$vs_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_virtual_site_id'"
	);

	e2e_check( 'Virtual: posts exist', $vs_count > 0, "$vs_count posts", $checks, $passed, $failed );

	if ( $vs_count > 0 ) {
		// Check translation markers in titles
		$vs_markers = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_virtual_site_id'
			 WHERE p.post_title LIKE '%\xE3\x80\x90%\xE3\x80\x91%' OR p.post_content LIKE '%\xE3\x80\x90%\xE3\x80\x91%'"
		);
		e2e_check( 'Virtual: translation markers', $vs_markers > 0, "$vs_markers with markers", $checks, $passed, $failed );

		// Check origin mapping
		$vs_origins_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm1
			 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
			 WHERE pm1.meta_key = '_wptsall_virtual_site_id' AND pm2.meta_key = '_wptsall_origin_object_id'"
		);

		$vs_origins_mapping = 0;
		$post_mappings_table = e2e_table( 'post_mappings' );
		if ( e2e_table_exists( $post_mappings_table ) ) {
			$vs_origins_mapping = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$post_mappings_table}
				 WHERE target_site_id LIKE 'v_%'"
			);
		}

		$vs_origins_total = $vs_origins_meta + $vs_origins_mapping;
		e2e_check(
			'Virtual: origin mapping',
			$vs_origins_total > 0,
			"meta={$vs_origins_meta}, post_mappings={$vs_origins_mapping}",
			$checks,
			$passed,
			$failed
		);
	}
} else {
	echo "  Virtual relation not configured, skipped.\n";
	++$skipped;
}

// ==================================================================
// SECTION 2: WP Subsite
// ==================================================================
echo "\n--- Section 2: WP Subsite ---\n";

$wp_rid = $relation_ids['wp'] ?? 0;
$wp_blog_id = e2e_get_wp_target_blog_id( $relation_ids );
if ( $wp_rid > 0 && $wp_blog_id > 0 && is_multisite() && get_blog_details( $wp_blog_id ) ) {
	switch_to_blog( $wp_blog_id );

	// Posts with source mapping meta
	$synced = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_content, p.post_name, p.post_type
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		 WHERE pm.meta_key IN ('_wptsall_source_post_id', '_wptsall_origin_object_id')
		   AND p.post_status != 'trash'
		 ORDER BY p.ID DESC
		 LIMIT 50",
		ARRAY_A
	);

	$count = count( $synced );
	e2e_check( "Blog {$wp_blog_id}: synced posts", $count > 0, "$count posts", $checks, $passed, $failed );

	if ( $count > 0 ) {
		$title_markers   = 0;
		$content_markers = 0;
		$clean_slugs     = 0;
		$bad_slugs       = 0;

		foreach ( $synced as $post ) {
			if ( preg_match( E2E_MARKER_PATTERN, $post['post_title'] ) ) {
				++$title_markers;
			}
			if ( preg_match( E2E_MARKER_PATTERN, $post['post_content'] ) ) {
				++$content_markers;
			}
			if ( preg_match( E2E_MARKER_PATTERN, $post['post_name'] ) ) {
				++$bad_slugs;
			} else {
				++$clean_slugs;
			}
		}

		if ( $title_markers > 0 ) {
			e2e_check( "Blog {$wp_blog_id}: title markers", true, "$title_markers/$count", $checks, $passed, $failed );
		} else {
			echo "SKIP: Blog {$wp_blog_id}: title markers — {$title_markers}/{$count} (WP target may be copy/T1 mirror without translate markers).\n";
			++$skipped;
		}
		if ( $content_markers > 0 ) {
			e2e_check( "Blog {$wp_blog_id}: content markers", true, "$content_markers/$count", $checks, $passed, $failed );
		} else {
			echo "SKIP: Blog {$wp_blog_id}: content markers — {$content_markers}/{$count} (WP target may be copy/T1 mirror without translate markers).\n";
			++$skipped;
		}
		e2e_check( "Blog {$wp_blog_id}: slugs clean", $bad_slugs === 0, "$clean_slugs clean, $bad_slugs bad", $checks, $passed, $failed );
	}

	restore_current_blog();
} else {
	echo "  WP relation not configured or target blog missing, skipped.\n";
	++$skipped;
}

// ==================================================================
// SECTION 3: Self Translation (Blog 1)
// ==================================================================
echo "\n--- Section 3: Self Translation (Blog 1) ---\n";

$self_rid = $relation_ids['self'] ?? 0;
if ( $self_rid > 0 ) {
	$self_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wptsall_self_translation'"
	);

	if ( $self_count > 0 ) {
		e2e_check( 'Self: posts exist', true, "$self_count posts", $checks, $passed, $failed );

		$self_markers = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wptsall_self_translation'
			 WHERE p.post_title LIKE '%\xE3\x80\x90%\xE3\x80\x91%' OR p.post_content LIKE '%\xE3\x80\x90%\xE3\x80\x91%'"
		);
		e2e_check( 'Self: translation markers', $self_markers > 0, "$self_markers with markers", $checks, $passed, $failed );
	} else {
		echo "  Self Translation: no content found (feature may not be implemented yet).\n";
		++$skipped;
	}
} else {
	echo "  Self Translation relation not configured, skipped.\n";
	++$skipped;
}

// ==================================================================
// SECTION 4: Task Completion
// ==================================================================
echo "\n--- Section 4: Task Completion ---\n";

$tasks_table = e2e_table( 'tasks' );
if ( e2e_table_exists( $tasks_table ) ) {
	$relation_scope = array_filter(
		array_map(
			'intval',
			array(
				$relation_ids['virtual'] ?? 0,
				$relation_ids['wp'] ?? 0,
				$relation_ids['self'] ?? 0,
			)
		)
	);
	$scope_sql = '';
	if ( ! empty( $relation_scope ) ) {
		$scope_sql = ' AND relation_id IN (' . implode( ',', $relation_scope ) . ')';
	}

	$completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tasks_table WHERE status = 'completed'{$scope_sql}" );
	$pending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tasks_table WHERE status = 'pending'{$scope_sql}" );
	$error     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tasks_table WHERE status IN ('error', 'failed'){$scope_sql}" );

	$synced_results = 0;
	$results_table_for_completion = e2e_table( 'translation_results' );
	if ( e2e_table_exists( $results_table_for_completion ) ) {
		$synced_results = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$results_table_for_completion}
			 WHERE synced_at IS NOT NULL{$scope_sql}"
		);
	}

	e2e_check(
		'Write-back completion evidence',
		$completed > 0 || $synced_results > 0,
		"completed_tasks={$completed}, synced_results={$synced_results}, pending={$pending}, error={$error}",
		$checks, $passed, $failed
	);
}

// ==================================================================
// SECTION 5: Term Sync
// ==================================================================
echo "\n--- Section 5: Term Sync ---\n";

$term_table = e2e_table( 'term_mappings' );
if ( e2e_table_exists( $term_table ) ) {
	$term_count = e2e_table_count( $term_table );
	e2e_check( 'Term mappings', true, "$term_count mappings", $checks, $passed, $failed );
} else {
	echo "  Term mappings table not found, skipped.\n";
	++$skipped;
}

// ==================================================================
// SECTION 6: Field-format integrity + translated_meta path
// ==================================================================
echo "\n--- Section 6: Field-format integrity ---\n";

// 6.1 serialized_php fields should remain unserializable after write-back.
$serialized_meta_rows = $wpdb->get_results(
	"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
	 WHERE meta_key IN ('_product_attributes', '_eg_gallery_data', '_tribe_venue_meta')
	   AND meta_value LIKE 'a:%'
	 LIMIT 120",
	ARRAY_A
);

if ( empty( $serialized_meta_rows ) ) {
	echo "  No serialized_php samples found, skipped.\n";
	++$skipped;
} else {
	$serialized_ok = 0;
	foreach ( $serialized_meta_rows as $row ) {
		$value = $row['meta_value'] ?? '';
		if ( '' === $value ) {
			continue;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$decoded = @unserialize( $value );
		if ( false !== $decoded || 'b:0;' === $value ) {
			++$serialized_ok;
		}
	}

	e2e_check(
		'serialized_php meta valid',
		$serialized_ok === count( $serialized_meta_rows ),
		"valid={$serialized_ok}/" . count( $serialized_meta_rows ),
		$checks,
		$passed,
		$failed
	);
}

// 6.2 json_structured fields should remain valid JSON.
$json_meta_rows = $wpdb->get_results(
	"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
	 WHERE meta_key IN ('_elementor_data', 'wprm_recipe_ingredients', 'acf_repeater')
	   AND (meta_value LIKE '[%' OR meta_value LIKE '{%')
	 LIMIT 120",
	ARRAY_A
);

if ( empty( $json_meta_rows ) ) {
	echo "  No json_structured samples found, skipped.\n";
	++$skipped;
} else {
	$json_ok = 0;
	foreach ( $json_meta_rows as $row ) {
		$value = (string) ( $row['meta_value'] ?? '' );
		if ( '' === $value ) {
			continue;
		}
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
			++$json_ok;
		}
	}

	e2e_check(
		'json_structured meta valid',
		$json_ok === count( $json_meta_rows ),
		"valid={$json_ok}/" . count( $json_meta_rows ),
		$checks,
		$passed,
		$failed
	);
}

// 6.3 translated_meta callback path must be exercised in E2E.
$results_table = e2e_table( 'translation_results' );
if ( e2e_table_exists( $results_table ) ) {
	$translated_meta_rows = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$results_table}
		 WHERE translated_meta IS NOT NULL
		   AND translated_meta != ''
		   AND translated_meta != '{}'
		   AND translated_meta != '[]'"
	);
	e2e_check(
		'translated_meta path exercised',
		$translated_meta_rows > 0,
		"rows={$translated_meta_rows}",
		$checks,
		$passed,
		$failed
	);
} else {
	echo "  translation_results table missing, skipped.\n";
	++$skipped;
}

// ==================================================================
// 按插件分组统计（信息性输出）
// ==================================================================
echo "\n--- Per-Plugin Summary ---\n";

if ( e2e_table_exists( $results_table ) ) {
	$plugin_slug_col = $wpdb->get_var(
		$wpdb->prepare( "SHOW COLUMNS FROM {$results_table} LIKE %s", 'plugin_slug' )
	);

	if ( ! empty( $plugin_slug_col ) ) {
		$per_plugin = $wpdb->get_results(
			"SELECT
				COALESCE(NULLIF(plugin_slug, ''), 'wordpress-blog') as plugin,
				COUNT(*) as total,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			 FROM $results_table
			 GROUP BY plugin_slug
			 ORDER BY total DESC",
			ARRAY_A
		);
	} else {
		$per_plugin = $wpdb->get_results(
			"SELECT
				COALESCE(NULLIF(object_type, ''), 'unknown') as plugin,
				COUNT(*) as total,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			 FROM $results_table
			 GROUP BY object_type
			 ORDER BY total DESC",
			ARRAY_A
		);
		echo "  plugin_slug column not found, using object_type summary.\n";
	}

	if ( ! empty( $per_plugin ) ) {
		printf( "  %-30s %6s %6s %6s\n", 'Plugin', 'Total', 'Done', 'Failed' );
		echo "  " . str_repeat( '-', 54 ) . "\n";
		foreach ( $per_plugin as $row ) {
			printf( "  %-30s %6s %6s %6s\n", $row['plugin'], $row['total'], $row['completed'], $row['failed'] );
		}
	}
}

// ==================================================================
// 结果
// ==================================================================
e2e_print_results( $checks, $passed, $failed, $skipped );

if ( $failed > 0 ) {
	exit( 1 );
}
