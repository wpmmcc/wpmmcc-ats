<?php
/**
 * Seed mirrored posts on WP Multisite T1 blog + post_mappings for topology deep smoke.
 *
 * Expects is_multisite() and an active site_relations row with target_site_type=wp.
 * Env: WPTSALL_T1_BLOG_ID (optional; defaults to first blog_id > 1).
 *
 * Run: wp eval-file lab-subsite-seed-mirrors.php
 */

if ( ! is_multisite() ) {
	echo "SKIP: not multisite\n";
	exit( 0 );
}

global $wpdb;

$blog_id = (int) ( getenv( 'WPTSALL_T1_BLOG_ID' ) ?: 0 );
if ( $blog_id <= 1 ) {
	foreach ( get_sites( array( 'number' => 20, 'orderby' => 'id', 'order' => 'ASC' ) ) as $site ) {
		$id = (int) $site->blog_id;
		if ( $id > 1 ) {
			$blog_id = $id;
			break;
		}
	}
}
if ( $blog_id <= 1 ) {
	echo "ERROR: no T1 blog\n";
	exit( 1 );
}

$rel_table = function_exists( 'wptsall_table' ) ? wptsall_table( 'site_relations' ) : $wpdb->prefix . 'wptsall_site_relations';
$pm_table  = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';

$relation = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$rel_table} WHERE target_site_type = %s AND target_site_id = %s AND status = %s ORDER BY id ASC LIMIT 1",
		'wp',
		(string) $blog_id,
		'active'
	),
	ARRAY_A
);

if ( ! $relation ) {
	echo "ERROR: no active wp relation for blog {$blog_id}\n";
	exit( 1 );
}

$relation_id = (int) $relation['id'];
echo "Relation #{$relation_id} → blog {$blog_id}\n";

/**
 * Representative CPT samples for T1 (6).
 *
 * @var array<int, array{post_type:string,label:string}>
 */
$samples = array(
	array( 'post_type' => 'post', 'label' => 'core-post' ),
	array( 'post_type' => 'product', 'label' => 'woocommerce' ),
	array( 'post_type' => 'download', 'label' => 'edd' ),
	array( 'post_type' => 'lp_course', 'label' => 'learnpress' ),
	array( 'post_type' => 'page', 'label' => 'yoast-page' ),
	array( 'post_type' => 'job_listing', 'label' => 'job-manager' ),
);

$allowed_types = array_map(
	static function ( $s ) {
		return $s['post_type'];
	},
	$samples
);
$placeholders = implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$pm_table} WHERE relation_id = %d AND target_site_id = %s AND source_post_type NOT IN ({$placeholders})",
		array_merge( array( $relation_id, (string) $blog_id ), $allowed_types )
	)
);

$now = current_time( 'mysql' );
$ok  = 0;
$skip = 0;

foreach ( $samples as $sample ) {
	$pt    = $sample['post_type'];
	$label = $sample['label'];

	if ( ! post_type_exists( $pt ) ) {
		echo "  SKIP {$label}: post_type {$pt} missing on blog 1\n";
		++$skip;
		continue;
	}

	$source = get_posts(
		array(
			'post_type'      => $pt,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'suppress_filters' => true,
		)
	);
	if ( empty( $source ) ) {
		echo "  SKIP {$label}: no publish {$pt} on blog 1\n";
		++$skip;
		continue;
	}
	$src = $source[0];
	$src_id = (int) $src->ID;

	$existing_map = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$pm_table} WHERE relation_id = %d AND source_post_id = %d AND target_site_id = %s LIMIT 1",
			$relation_id,
			$src_id,
			(string) $blog_id
		),
		ARRAY_A
	);

	if ( $existing_map && (int) $existing_map['target_post_id'] > 0 ) {
		$tid = (int) $existing_map['target_post_id'];
		switch_to_blog( $blog_id );
		$exists = get_post( $tid );
		restore_current_blog();
		if ( $exists ) {
			echo "  OK {$label}: mapped src={$src_id} → target={$tid} (existing)\n";
			++$ok;
			continue;
		}
	}

	$title   = '[T1 EN] ' . $src->post_title;
	$content = $src->post_content;
	$slug    = $src->post_name ? ( $src->post_name . '-en' ) : sanitize_title( $title );

	switch_to_blog( $blog_id );

	// Ensure CPT registered on subsite (plugins should be active).
	if ( ! post_type_exists( $pt ) ) {
		restore_current_blog();
		echo "  SKIP {$label}: post_type {$pt} missing on blog {$blog_id}\n";
		++$skip;
		continue;
	}

	$target_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => $pt,
			'post_name'    => $slug,
			'post_author'  => 1,
		),
		true
	);
	restore_current_blog();

	if ( is_wp_error( $target_id ) || ! $target_id ) {
		$msg = is_wp_error( $target_id ) ? $target_id->get_error_message() : 'insert failed';
		echo "  FAIL {$label}: {$msg}\n";
		continue;
	}

	$target_id = (int) $target_id;
	if ( $existing_map ) {
		$wpdb->update(
			$pm_table,
			array(
				'target_post_id'   => $target_id,
				'target_post_type' => $pt,
				'updated_at'       => $now,
			),
			array( 'id' => (int) $existing_map['id'] )
		);
	} else {
		$wpdb->insert(
			$pm_table,
			array(
				'relation_id'      => $relation_id,
				'source_post_id'   => $src_id,
				'source_post_type' => $pt,
				'source_site_id'   => 1,
				'target_post_id'   => $target_id,
				'target_post_type' => $pt,
				'target_site_id'   => (string) $blog_id,
				'relationship_type'=> 'translation',
				'needs_resync'     => 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
	}

	echo "  OK {$label}: src={$src_id} → target={$target_id}\n";
	++$ok;
}

echo "Done: ok={$ok} skip={$skip}\n";
if ( $ok < 1 ) {
	exit( 1 );
}
