<?php
/**
 * E2E v2 数据真实性增强
 *
 * 目标：将 seeding 后的数据补齐到接近真实用户发布行为。
 * 仅补缺失值，不覆盖手工/官方数据已有内容。
 *
 * Run:
 *   cd ${WPTSALL_WP_ROOT:-/var/www/html} && wp eval-file /home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/php/enrich-seeding-realism.php
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;

echo "=== E2E v2: Enrich Seeding Realism ===\n\n";

/**
 * 生成摘要。
 */
function e2e_realism_excerpt( string $content ): string {
	$plain = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
	if ( $plain === '' ) {
		return '';
	}
	return wp_trim_words( $plain, 30, '...' );
}

/**
 * 提取关键词。
 */
function e2e_realism_focus_kw( string $title ): string {
	$title = trim( $title );
	if ( $title === '' ) {
		return '';
	}

	if ( preg_match( '/\s/u', $title ) ) {
		$words = preg_split( '/\s+/', $title );
		return trim( implode( ' ', array_slice( $words, 0, 4 ) ) );
	}

	if ( function_exists( 'mb_substr' ) ) {
		return trim( mb_substr( $title, 0, 8 ) );
	}

	return trim( substr( $title, 0, 16 ) );
}

/**
 * 确保附件文本字段。
 */
function e2e_realism_enrich_attachment( int $attachment_id, string $label = '' ): int {
	$post = get_post( $attachment_id );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		return 0;
	}

	$label = trim( $label );
	if ( $label === '' ) {
		$label = trim( (string) $post->post_title );
	}
	if ( $label === '' ) {
		$label = 'media asset';
	}

	$updated = 0;

	if ( strpos( (string) $post->post_mime_type, 'image/' ) === 0 ) {
		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( $alt === '' ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $label . ' image' );
			$updated++;
		}
	}

	$patch = array( 'ID' => $attachment_id );
	$dirty = false;

	if ( trim( (string) $post->post_excerpt ) === '' ) {
		$patch['post_excerpt'] = $label;
		$dirty = true;
	}
	if ( trim( (string) $post->post_content ) === '' ) {
		$patch['post_content'] = $label . ' attachment';
		$dirty = true;
	}

	if ( $dirty ) {
		wp_update_post( $patch );
		$updated++;
	}

	return $updated;
}

/**
 * 填充 SEO 字段（仅缺失值）。
 */
function e2e_realism_fill_seo( WP_Post $post ): int {
	$title = trim( (string) $post->post_title );
	if ( $title === '' ) {
		return 0;
	}

	$excerpt = trim( (string) $post->post_excerpt );
	if ( $excerpt === '' ) {
		$excerpt = e2e_realism_excerpt( (string) $post->post_content );
	}
	if ( $excerpt === '' ) {
		$excerpt = $title;
	}

	$desc = wp_html_excerpt( $excerpt, 155, '...' );
	$kw   = e2e_realism_focus_kw( $title );
	$site = get_bloginfo( 'name' );

	$meta_map = array(
		'_yoast_wpseo_title'      => $title . ' | ' . $site,
		'_yoast_wpseo_metadesc'   => $desc,
		'_yoast_wpseo_focuskw'    => $kw,
		'_aioseo_title'           => $title . ' | ' . $site,
		'_aioseo_description'     => $desc,
		'_aioseo_keywords'        => $kw,
		'rank_math_title'         => $title . ' | ' . $site,
		'rank_math_description'   => $desc,
		'rank_math_focus_keyword' => $kw,
	);

	$updated = 0;
	foreach ( $meta_map as $key => $value ) {
		$current = trim( (string) get_post_meta( $post->ID, $key, true ) );
		if ( $current === '' && $value !== '' ) {
			update_post_meta( $post->ID, $key, $value );
			$updated++;
		}
	}

	return $updated;
}

/**
 * 为缺图内容选择默认图片。
 *
 * @param int[] $image_ids 图片 ID 池。
 */
function e2e_realism_pick_image( array $image_ids, WP_Post $post ): int {
	if ( empty( $image_ids ) ) {
		return 0;
	}
	$seed = abs( (int) crc32( $post->post_type . '|' . $post->ID ) );
	$idx  = $seed % count( $image_ids );
	return (int) $image_ids[ $idx ];
}

/**
 * 给 meta 行加统计桶标记（批量写路径用）。
 *
 * @param array  $rows   array( post_id, meta_key, meta_value )。
 * @param string $bucket 统计桶名。
 * @return array<int, array{0:int,1:string,2:string,3:string}>
 */
function att_meta_rows_with_bucket( array $rows, string $bucket ): array {
	$out = array();
	foreach ( $rows as $row ) {
		$out[] = array( (int) $row[0], (string) $row[1], (string) $row[2], $bucket );
	}
	return $out;
}

$target_post_types = array(
	'post',
	'page',
	'product',
	'download',
	'topic',
	'courses',
	'lp_course',
	'tribe_events',
	'job_listing',
	'envira',
	'podcast',
	'wprm_recipe',
	'site-review',
);

$stats = array(
	'attachments_seen'      => 0,
	'attachments_updated'   => 0,
	'posts_seen'            => 0,
	'excerpt_filled'        => 0,
	'seo_fields_filled'     => 0,
	'featured_set'          => 0,
	'featured_alt_filled'   => 0,
	'core_category_assigned'=> 0,
	'dates_rebalanced'      => 0,
);

// 1) 附件文本字段补齐
// PERF 2026-09-02 (gate5 RG-CORE): the per-post `wp_update_post` /
// `update_post_meta` loop took ~421s on the core-content full dataset
// (828 posts × full hook/sanitize path per write). Writes are now collected
// and applied as batched direct SQL inside one transaction; the summary and
// fill rules are unchanged. Cache is flushed once at the end so later
// in-process reads stay consistent.
$attachment_ids = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

$image_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts}
	 WHERE post_type='attachment'
	   AND post_status='inherit'
	   AND post_mime_type LIKE 'image/%'
	 ORDER BY ID ASC"
);
$image_ids = array_map( 'intval', is_array( $image_ids ) ? $image_ids : array() );

// Collect attachment patches instead of writing per attachment.
$att_post_updates = array(); // array( ID => array(post_excerpt, post_content) )
$att_meta_rows    = array(); // array( post_id, meta_key, meta_value )
foreach ( $attachment_ids as $aid ) {
	$stats['attachments_seen']++;
	$aid = (int) $aid;

	$post = get_post( $aid );
	if ( ! $post || $post->post_type !== 'attachment' ) {
		continue;
	}

	$label = trim( (string) $post->post_title );
	if ( $label === '' ) {
		$label = 'media asset';
	}

	$dirty_post = false;
	$patch      = array( 'post_excerpt' => (string) $post->post_excerpt, 'post_content' => (string) $post->post_content );

	if ( trim( $patch['post_excerpt'] ) === '' ) {
		$patch['post_excerpt'] = $label;
		$dirty_post            = true;
	}
	if ( trim( $patch['post_content'] ) === '' ) {
		$patch['post_content'] = $label . ' attachment';
		$dirty_post            = true;
	}
	if ( $dirty_post ) {
		$att_post_updates[ $aid ] = $patch;
		$stats['attachments_updated']++;
	}

	if ( strpos( (string) $post->post_mime_type, 'image/' ) === 0 ) {
		$alt = trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) );
		if ( $alt === '' ) {
			$att_meta_rows[] = array( $aid, '_wp_attachment_image_alt', $label . ' image' );
		}
	}
}

// 2) 内容真实性补齐
$posts = get_posts(
	array(
		'post_type'      => $target_post_types,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);

$default_category = (int) get_option( 'default_category', 1 );
$site_name        = get_bloginfo( 'name' );

// Collect content patches; all writes are applied in batches below.
$excerpt_updates = array();  // array( ID => excerpt )
$seo_meta_rows   = array();  // array( post_id, meta_key, meta_value )
$thumb_meta_rows = array();  // array( post_id, '_thumbnail_id', image ID )
$alt_meta_rows   = array();  // array( attachment ID, '_wp_attachment_image_alt', value )
$cat_missing     = array();  // post IDs without a category

foreach ( $posts as $post ) {
	$stats['posts_seen']++;

	// excerpt
	if ( trim( (string) $post->post_excerpt ) === '' ) {
		$excerpt = e2e_realism_excerpt( (string) $post->post_content );
		if ( $excerpt === '' ) {
			$excerpt = trim( (string) $post->post_title );
		}
		if ( $excerpt !== '' ) {
			$excerpt_updates[ (int) $post->ID ] = $excerpt;
			$post->post_excerpt                 = $excerpt;
			$stats['excerpt_filled']++;
		}
	}

	// SEO meta: collect missing keys via one batched existence query per post set (done below).
	$title   = trim( (string) $post->post_title );
	if ( $title !== '' ) {
		$excerpt2 = trim( (string) $post->post_excerpt );
		if ( $excerpt2 === '' ) {
			$excerpt2 = e2e_realism_excerpt( (string) $post->post_content );
		}
		if ( $excerpt2 === '' ) {
			$excerpt2 = $title;
		}

		$desc  = wp_html_excerpt( $excerpt2, 155, '...' );
		$kw    = e2e_realism_focus_kw( $title );
		$meta_map = array(
			'_yoast_wpseo_title'      => $title . ' | ' . $site_name,
			'_yoast_wpseo_metadesc'   => $desc,
			'_yoast_wpseo_focuskw'    => $kw,
			'_aioseo_title'           => $title . ' | ' . $site_name,
			'_aioseo_description'     => $desc,
			'_aioseo_keywords'        => $kw,
			'rank_math_title'         => $title . ' | ' . $site_name,
			'rank_math_description'   => $desc,
			'rank_math_focus_keyword' => $kw,
		);
		foreach ( $meta_map as $key => $value ) {
			if ( $value !== '' ) {
				$seo_meta_rows[ (int) $post->ID . '|' . $key ] = array( (int) $post->ID, $key, $value );
			}
		}
	}

	if ( post_type_supports( $post->post_type, 'thumbnail' ) ) {
		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id > 0 ) {
			$thumb_mime = (string) get_post_mime_type( $thumbnail_id );
			if ( strpos( $thumb_mime, 'image/' ) !== 0 ) {
				$thumbnail_id = e2e_realism_pick_image( $image_ids, $post );
				if ( $thumbnail_id > 0 ) {
					$thumb_meta_rows[] = array( (int) $post->ID, '_thumbnail_id', (string) $thumbnail_id );
					$stats['featured_set']++;
				} else {
					$thumbnail_id = 0;
				}
			}
		}

		if ( $thumbnail_id <= 0 ) {
			$thumbnail_id = e2e_realism_pick_image( $image_ids, $post );
			if ( $thumbnail_id > 0 ) {
				$thumb_meta_rows[] = array( (int) $post->ID, '_thumbnail_id', (string) $thumbnail_id );
				$stats['featured_set']++;
			}
		}

		if ( $thumbnail_id > 0 ) {
			$alt_before = trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
			if ( $alt_before === '' ) {
				$label = trim( (string) $post->post_title );
				if ( $label === '' ) {
					$label = 'media asset';
				}
				$alt_meta_rows[] = array( (int) $thumbnail_id, '_wp_attachment_image_alt', $label . ' image' );
				$stats['featured_alt_filled']++;
			}
		}
	}

	// 核心 post 没有分类时补默认分类，符合真实站点常见发布路径。
	if ( $post->post_type === 'post' ) {
		$cat_missing[] = (int) $post->ID;
	}
}

// ---------------------------------------------------------------------------
// 批量应用收集到的写入（单事务 + 直 SQL，绕过逐条 hook 路径）。
// meta 行的第 4 位是统计桶（attachment_alt / seo / thumbnail / featured_alt），
// 保证 summary 口径与旧的逐条写路径一致。
// ---------------------------------------------------------------------------
$all_meta_rows = array_merge(
	att_meta_rows_with_bucket( $att_meta_rows, 'attachment_alt' ),
	att_meta_rows_with_bucket( $seo_meta_rows, 'seo' ),
	att_meta_rows_with_bucket( $thumb_meta_rows, 'thumbnail' ),
	att_meta_rows_with_bucket( $alt_meta_rows, 'featured_alt' )
);
$meta_written  = 0;
$post_written  = 0;
$bucket_counts = array(
	'attachment_alt' => 0,
	'seo'            => 0,
	'thumbnail'      => 0,
	'featured_alt'   => 0,
);

$wpdb->query( 'START TRANSACTION' );

if ( ! empty( $excerpt_updates ) ) {
	foreach ( $excerpt_updates as $pid => $excerpt ) {
		$wpdb->update(
			$wpdb->posts,
			array( 'post_excerpt' => $excerpt ),
			array( 'ID' => (int) $pid ),
			array( '%s' ),
			array( '%d' )
		);
		$post_written++;
	}
}

if ( ! empty( $att_post_updates ) ) {
	foreach ( $att_post_updates as $aid => $patch ) {
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_excerpt' => $patch['post_excerpt'],
				'post_content' => $patch['post_content'],
			),
			array( 'ID' => (int) $aid ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$post_written++;
	}
}

if ( ! empty( $all_meta_rows ) ) {
	// 一次取回已存在的 (post_id, meta_key) 及其值，避免逐条 get_post_meta。
	$ids       = array_values( array_unique( array_map(
		static function ( $row ) {
			return (int) $row[0];
		},
		$all_meta_rows
	) ) );
	$keys      = array_values( array_unique( array_map(
		static function ( $row ) {
			return (string) $row[1];
		},
		$all_meta_rows
	) ) );
	$id_placeholders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

	$existing = array();
	$rows     = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above with %d/%s only.
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_placeholders) AND meta_key IN ($key_placeholders)",
			array_merge( $ids, $keys )
		),
		ARRAY_A
	);
	foreach ( ( is_array( $rows ) ? $rows : array() ) as $row ) {
		$existing[ (int) $row['post_id'] . '|' . (string) $row['meta_key'] ] = (string) $row['meta_value'];
	}

	$insert_rows = array();
	$update_rows = array();
	foreach ( $all_meta_rows as $row ) {
		$pid     = (int) $row[0];
		$key     = (string) $row[1];
		$val     = (string) $row[2];
		$bucket  = (string) $row[3];
		$map_key = $pid . '|' . $key;

		if ( ! array_key_exists( $map_key, $existing ) ) {
			$insert_rows[] = array( $pid, $key, $val, $bucket );
		} elseif ( trim( $existing[ $map_key ] ) === '' && $val !== '' ) {
			// 已有行但值为空 → 原逻辑会填值；UPDATE 而不是 INSERT 以免产生重复 meta 行。
			$update_rows[] = array( $pid, $key, $val, $bucket );
		}
	}

	if ( ! empty( $insert_rows ) ) {
		$values       = array();
		$placeholders = array();
		foreach ( $insert_rows as $row ) {
			$values[]       = (int) $row[0];
			$values[]       = $row[1];
			$values[]       = $row[2];
			$placeholders[] = '(%d, %s, %s)';
			$bucket_counts[ $row[3] ]++;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are (%d,%s,%s) triples only.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES "
				. implode( ', ', $placeholders ),
				$values
			)
		);
	}

	if ( ! empty( $update_rows ) ) {
		foreach ( $update_rows as $row ) {
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $row[2] ),
				array(
					'post_id'  => (int) $row[0],
					'meta_key' => $row[1],
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
			$bucket_counts[ $row[3] ]++;
		}
	}
}

// 无分类 core post：NOT EXISTS 精确找缺失（LEFT JOIN 会把有 tag 无分类的
// post 误判为 NULL），再走 wp_set_post_categories 维护 term 计数。
if ( ! empty( $cat_missing ) ) {
	$cat_ids           = array_values( array_unique( $cat_missing ) );
	$cat_placeholders  = implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) );
	$uncategorized_ids = $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are %d list only.
			"SELECT p.ID FROM {$wpdb->posts} p
			 WHERE p.ID IN ($cat_placeholders) AND p.post_type = 'post'
			   AND NOT EXISTS (
			       SELECT 1 FROM {$wpdb->term_relationships} tr
			       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			       WHERE tr.object_id = p.ID AND tt.taxonomy = 'category'
			   )",
			$cat_ids
		)
	);
	foreach ( ( is_array( $uncategorized_ids ) ? $uncategorized_ids : array() ) as $pid ) {
		wp_set_post_categories( (int) $pid, array( $default_category ), true );
		$stats['core_category_assigned']++;
	}
}

$wpdb->query( 'COMMIT' );

$meta_written = array_sum( $bucket_counts );
// 日期重排等其他步骤在本进程内还会读 posts；flush 保证一致性。
if ( $post_written > 0 || $meta_written > 0 ) {
	$stats['attachments_updated'] += $bucket_counts['attachment_alt'];
	$stats['seo_fields_filled']   += $bucket_counts['seo'];
	wp_cache_flush();
}

// 3) 时间分布修正：若某 post_type 过于集中，按可复现规则重排。
foreach ( $target_post_types as $post_type ) {
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type=%s AND post_status='publish'",
			$post_type
		)
	);
	if ( $total < 6 ) {
		continue;
	}

	$days = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT DATE(post_date)) FROM {$wpdb->posts}
			 WHERE post_type=%s AND post_status='publish'",
			$post_type
		)
	);
	if ( $days >= 3 ) {
		continue;
	}

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type=%s AND post_status='publish'
			 ORDER BY ID ASC",
			$post_type
		)
	);

	foreach ( $ids as $post_id ) {
		$post_id  = (int) $post_id;
		$seed     = abs( (int) crc32( $post_type . '|' . $post_id ) );
		$days_ago = 3 + ( $seed % 360 );
		$hour     = 8 + ( $seed % 11 );
		$minute   = (int) floor( ( $seed / 11 ) % 60 );
		$second   = (int) floor( ( $seed / 37 ) % 60 );

		$local_day  = wp_date( 'Y-m-d', current_time( 'timestamp' ) - ( $days_ago * DAY_IN_SECONDS ) );
		$local_date = sprintf( '%s %02d:%02d:%02d', $local_day, $hour, $minute, $second );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_date'     => $local_date,
				'post_date_gmt' => get_gmt_from_date( $local_date ),
			)
		);
		$stats['dates_rebalanced']++;
	}
}

// 4) 全局时间分布修正：Stage 3 verifier 看的是当前业务线集合的合并日期分布。
$date_post_types = $target_post_types;
if ( function_exists( 'e2e_is_core_content_project' ) && e2e_is_core_content_project() ) {
	$date_post_types = array( 'post', 'page' );
} elseif ( function_exists( 'e2e_is_learning_content_project' ) && e2e_is_learning_content_project() ) {
	$date_post_types = array( 'post', 'page', 'courses', 'lp_course' );
} elseif ( function_exists( 'e2e_is_commerce_content_project' ) && e2e_is_commerce_content_project() ) {
	$date_post_types = array( 'post', 'page', 'product', 'download' );
} elseif ( function_exists( 'e2e_is_media_builder_content_project' ) && e2e_is_media_builder_content_project() ) {
	$date_post_types = array( 'post', 'page', 'envira', 'elementor_library' );
} elseif ( function_exists( 'e2e_is_community_content_project' ) && e2e_is_community_content_project() ) {
	$date_post_types = array( 'post', 'page', 'forum', 'topic', 'reply', 'site-review' );
} elseif ( function_exists( 'e2e_is_listings_events_content_project' ) && e2e_is_listings_events_content_project() ) {
	$date_post_types = array( 'post', 'page', 'tribe_events', 'job_listing' );
} elseif ( function_exists( 'e2e_is_content_meta_content_project' ) && e2e_is_content_meta_content_project() ) {
	$date_post_types = array( 'post', 'page', 'podcast', 'wprm_recipe' );
}

$type_placeholders = implode( ',', array_fill( 0, count( $date_post_types ), '%s' ) );
$global_days = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(DISTINCT DATE(post_date)) FROM {$wpdb->posts}
		 WHERE post_status='publish'
		   AND post_type IN ($type_placeholders)",
		$date_post_types
	)
);
$global_total = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_status='publish'
		   AND post_type IN ($type_placeholders)",
		$date_post_types
	)
);

if ( $global_total >= 20 && $global_days < 20 ) {
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status='publish'
			   AND post_type IN ($type_placeholders)
			 ORDER BY post_type ASC, ID ASC",
			$date_post_types
		)
	);

	$offset = 0;
	foreach ( $ids as $post_id ) {
		$post_id = (int) $post_id;
		$days_ago = 3 + ( $offset % 90 );
		$hour     = 8 + ( $offset % 10 );
		$minute   = ( $offset * 7 ) % 60;
		$second   = ( $offset * 13 ) % 60;

		$local_day  = wp_date( 'Y-m-d', current_time( 'timestamp' ) - ( $days_ago * DAY_IN_SECONDS ) );
		$local_date = sprintf( '%s %02d:%02d:%02d', $local_day, $hour, $minute, $second );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_date'     => $local_date,
				'post_date_gmt' => get_gmt_from_date( $local_date ),
			)
		);
		$stats['dates_rebalanced']++;
		$offset++;
	}
}

echo "--- Realism Enrichment Summary ---\n";
foreach ( $stats as $k => $v ) {
	echo str_pad( $k, 24, ' ', STR_PAD_RIGHT ) . ": {$v}\n";
}
echo "\nDone.\n";
