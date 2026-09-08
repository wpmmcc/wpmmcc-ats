<?php
/**
 * WPTSALL Virtual Site Content Migration
 *
 * Migrates data from deprecated virtual_site_content table to wp_posts/wp_terms + meta.
 *
 * @package WPTSALL
 * @since 0.8.0
 */
/**
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
 * Internal table names / DDL only (activation & migrations). Values use prepare where user input exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrate virtual site content from legacy table to wp_posts/wp_terms.
 *
 * This function should be called once during the v0.8.0 upgrade.
 * It converts JSON-stored content in virtual_site_content table to actual
 * WordPress posts/terms with wptsall meta markers.
 *
 * @since 0.8.0
 *
 * @param bool $dry_run If true, only report what would be migrated without making changes.
 * @return array Migration result statistics.
 */
function wptsall_migrate_virtual_site_content_to_native( $dry_run = false ) {
	global $wpdb;

	$result = array(
		'posts_migrated'   => 0,
		'posts_skipped'    => 0,
		'posts_failed'     => 0,
		'terms_migrated'   => 0,
		'terms_skipped'    => 0,
		'terms_failed'     => 0,
		'errors'           => array(),
		'dry_run'          => $dry_run,
	);

	$table_name = wptsall_table( 'virtual_site_content' );

	// Check if legacy table exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
	);

	if ( ! $table_exists ) {
		$result['message'] = 'Legacy virtual_site_content table does not exist. Nothing to migrate.';
		return $result;
	}

	// Get all rows from legacy table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM %i ORDER BY id',
			$table_name
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		$result['message'] = 'No data found in legacy table. Nothing to migrate.';
		return $result;
	}

	wptsall_log_info(
		'migration',
		'Starting virtual site content migration',
		array(
			'total_rows' => count( $rows ),
			'dry_run'    => $dry_run,
		)
	);

	foreach ( $rows as $row ) {
		$object_type     = $row['object_type'] ?? '';
		$subtype         = $row['subtype'] ?? '';
		$virtual_site_id = $row['virtual_site_id'] ?? '';
		$source_blog_id  = (int) ( $row['source_blog_id'] ?? 1 );
		$source_id       = (int) ( $row['source_object_id'] ?? 0 );
		$content_json    = $row['content'] ?? '';
		$translation_meta = $row['translation_meta'] ?? '';

		if ( empty( $content_json ) || empty( $virtual_site_id ) || empty( $source_id ) ) {
			if ( 'post_type' === $object_type ) {
				++$result['posts_skipped'];
			} else {
				++$result['terms_skipped'];
			}
			continue;
		}

		$content = json_decode( $content_json, true );
		if ( ! $content ) {
			if ( 'post_type' === $object_type ) {
				++$result['posts_failed'];
			} else {
				++$result['terms_failed'];
			}
			$result['errors'][] = "Failed to decode JSON for row ID {$row['id']}";
			continue;
		}

		if ( 'post_type' === $object_type ) {
			$migrate_result = wptsall_migrate_single_post_content(
				$content,
				$subtype,
				$virtual_site_id,
				$source_blog_id,
				$source_id,
				$translation_meta,
				$dry_run
			);

			if ( $migrate_result['success'] ) {
				if ( $migrate_result['skipped'] ) {
					++$result['posts_skipped'];
				} else {
					++$result['posts_migrated'];
				}
			} else {
				++$result['posts_failed'];
				$result['errors'][] = $migrate_result['error'];
			}
		} elseif ( 'taxonomy' === $object_type ) {
			$migrate_result = wptsall_migrate_single_term_content(
				$content,
				$subtype,
				$virtual_site_id,
				$source_blog_id,
				$source_id,
				$translation_meta,
				$dry_run
			);

			if ( $migrate_result['success'] ) {
				if ( $migrate_result['skipped'] ) {
					++$result['terms_skipped'];
				} else {
					++$result['terms_migrated'];
				}
			} else {
				++$result['terms_failed'];
				$result['errors'][] = $migrate_result['error'];
			}
		}
	}

	$result['message'] = sprintf(
		'Migration %s: Posts migrated=%d, skipped=%d, failed=%d; Terms migrated=%d, skipped=%d, failed=%d',
		$dry_run ? 'preview' : 'completed',
		$result['posts_migrated'],
		$result['posts_skipped'],
		$result['posts_failed'],
		$result['terms_migrated'],
		$result['terms_skipped'],
		$result['terms_failed']
	);

	wptsall_log_info(
		'migration',
		'Virtual site content migration finished',
		$result
	);

	return $result;
}

/**
 * Migrate a single post from legacy JSON to wp_posts + meta.
 *
 * @since 0.8.0
 *
 * @param array  $content          Content data from JSON.
 * @param string $post_type        Post type.
 * @param string $virtual_site_id  Virtual site identifier.
 * @param int    $source_blog_id   Source blog ID.
 * @param int    $source_post_id   Source post ID.
 * @param string $translation_meta Translation meta JSON.
 * @param bool   $dry_run          Whether this is a dry run.
 * @return array Migration result.
 */
function wptsall_migrate_single_post_content( $content, $post_type, $virtual_site_id, $source_blog_id, $source_post_id, $translation_meta, $dry_run ) {
	global $wpdb;

	$result = array(
		'success' => false,
		'skipped' => false,
		'error'   => '',
	);

	// Check if already migrated (post exists with these meta markers).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID FROM %i p
			INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
			INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
			WHERE pm1.meta_value = %s
			AND pm2.meta_value = %d
			AND p.post_type = %s
			LIMIT 1",
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->postmeta,
			$virtual_site_id,
			$source_post_id,
			$post_type
		)
	);

	if ( $existing_id ) {
		// Already migrated.
		$result['success'] = true;
		$result['skipped'] = true;
		return $result;
	}

	if ( $dry_run ) {
		$result['success'] = true;
		return $result;
	}

	// Build post data from content.
	$post_data = array(
		'post_title'   => $content['post_title'] ?? '',
		'post_content' => $content['post_content'] ?? '',
		'post_excerpt' => $content['post_excerpt'] ?? '',
		'post_status'  => $content['post_status'] ?? 'publish',
		'post_type'    => $post_type,
		'post_name'    => $content['post_name'] ?? '',
		'post_date'    => $content['post_date'] ?? current_time( 'mysql' ),
		'post_author'  => get_current_user_id() ?: 1,
	);

	// Insert post.
	$new_post_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $new_post_id ) ) {
		$result['error'] = "Failed to create post for source_id={$source_post_id}: " . $new_post_id->get_error_message();
		return $result;
	}

	// Add virtual site markers.
	\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, $virtual_site_id );
	\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_post_id, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_POST_ID, $source_post_id );
	\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_post_id, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_BLOG_ID, $source_blog_id );
	\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_post_id, '_wptsall_last_synced', current_time( 'mysql' ) );
	update_post_meta( $new_post_id, '_wptsall_migrated_from_legacy', 1 );

	// Store translation meta if present.
	if ( ! empty( $translation_meta ) ) {
		$meta_array = json_decode( $translation_meta, true );
		if ( $meta_array ) {
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $new_post_id, '_wptsall_translation_meta', $meta_array );
		}
	}

	// Migrate additional meta fields from content.
	if ( isset( $content['meta'] ) && is_array( $content['meta'] ) ) {
		foreach ( $content['meta'] as $meta_key => $meta_value ) {
			if ( strpos( $meta_key, '_wptsall_' ) === 0 ) {
				continue;
			}
			$value = is_array( $meta_value ) ? ( $meta_value[0] ?? '' ) : $meta_value;
			update_post_meta( $new_post_id, $meta_key, $value );
		}
	}

	$result['success']   = true;
	$result['target_id'] = $new_post_id;

	return $result;
}

/**
 * Migrate a single term from legacy JSON to wp_terms + meta.
 *
 * @since 0.8.0
 *
 * @param array  $content          Content data from JSON.
 * @param string $taxonomy         Taxonomy name.
 * @param string $virtual_site_id  Virtual site identifier.
 * @param int    $source_blog_id   Source blog ID.
 * @param int    $source_term_id   Source term ID.
 * @param string $translation_meta Translation meta JSON.
 * @param bool   $dry_run          Whether this is a dry run.
 * @return array Migration result.
 */
function wptsall_migrate_single_term_content( $content, $taxonomy, $virtual_site_id, $source_blog_id, $source_term_id, $translation_meta, $dry_run ) {
	global $wpdb;

	$result = array(
		'success' => false,
		'skipped' => false,
		'error'   => '',
	);

	// Check if already migrated (term exists with these meta markers).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT t.term_id FROM %i t
			INNER JOIN %i tt ON t.term_id = tt.term_id
			INNER JOIN %i tm1 ON t.term_id = tm1.term_id AND tm1.meta_key = '_wptsall_virtual_site_id'
			INNER JOIN %i tm2 ON t.term_id = tm2.term_id AND tm2.meta_key = '_wptsall_source_term_id'
			WHERE tm1.meta_value = %s
			AND tm2.meta_value = %d
			AND tt.taxonomy = %s
			LIMIT 1",
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->termmeta,
			$wpdb->termmeta,
			$virtual_site_id,
			$source_term_id,
			$taxonomy
		)
	);

	if ( $existing_id ) {
		// Already migrated.
		$result['success'] = true;
		$result['skipped'] = true;
		return $result;
	}

	if ( $dry_run ) {
		$result['success'] = true;
		return $result;
	}

	// Build term data from content.
	$term_data = array(
		'slug'        => $content['slug'] ?? '',
		'description' => $content['description'] ?? '',
	);

	$term_name = $content['name'] ?? '';
	if ( empty( $term_name ) ) {
		$result['error'] = "Term name is empty for source_id={$source_term_id}";
		return $result;
	}

	// Insert term.
	$new_term = wp_insert_term( $term_name, $taxonomy, $term_data );

	if ( is_wp_error( $new_term ) ) {
		// If term already exists by slug, try to get its ID.
		if ( $new_term->get_error_code() === 'term_exists' ) {
			$existing_term = get_term_by( 'slug', $term_data['slug'], $taxonomy );
			if ( $existing_term ) {
				$new_term_id = $existing_term->term_id;
			} else {
				$result['error'] = "Failed to create term for source_id={$source_term_id}: " . $new_term->get_error_message();
				return $result;
			}
		} else {
			$result['error'] = "Failed to create term for source_id={$source_term_id}: " . $new_term->get_error_message();
			return $result;
		}
	} else {
		$new_term_id = $new_term['term_id'];
	}

	// Add virtual site markers.
	update_term_meta( $new_term_id, '_wptsall_virtual_site_id', $virtual_site_id );
	update_term_meta( $new_term_id, '_wptsall_source_term_id', $source_term_id );
	update_term_meta( $new_term_id, '_wptsall_source_blog_id', $source_blog_id );
	\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $new_term_id, '_wptsall_last_synced', current_time( 'mysql' ) );
	update_term_meta( $new_term_id, '_wptsall_migrated_from_legacy', 1 );

	// Store translation meta if present.
	if ( ! empty( $translation_meta ) ) {
		$meta_array = json_decode( $translation_meta, true );
		if ( $meta_array ) {
			\WPTSALL\Tasks\Services\Direct_DB_Service::update_term_meta( (int) $new_term_id, '_wptsall_translation_meta', $meta_array );
		}
	}

	$result['success']   = true;
	$result['target_id'] = $new_term_id;

	return $result;
}

/**
 * Check if virtual site content migration is needed.
 *
 * @since 0.8.0
 *
 * @return bool True if migration is needed.
 */
function wptsall_needs_virtual_site_content_migration() {
	global $wpdb;

	$table_name = wptsall_table( 'virtual_site_content' );

	// Check if legacy table exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
	);

	if ( ! $table_exists ) {
		return false;
	}

	// Check if there are any rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );

	return (int) $count > 0;
}

/**
 * Get virtual site content migration status.
 *
 * @since 0.8.0
 *
 * @return array Migration status.
 */
