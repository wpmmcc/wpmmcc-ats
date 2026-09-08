<?php
/**
 * Media Mapping Service
 *
 * Handles media file ID mappings and file copying between source and target sites.
 * Supports alt text translation and metadata preservation.
 *
 * @package WPTSALL\Models\Services
 * @since 0.5.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Mapping Service Class
 */
class Media_Mapping_Service {

	/**
	 * Tables whose relation-scoped schema has been checked during this request.
	 *
	 * Media mappings are deliberately site-local tables.  A source-site admin
	 * request can therefore be at the current schema while a target subsite has
	 * not yet received an admin request after an upgrade.  Cache by table name
	 * because a single sync can switch blogs several times.
	 *
	 * @var array<string,bool>
	 */
	private static $relation_schema_checked = array();

	/**
	 * Ensure the current site's media mapping table supports relation-scoped
	 * mappings before a write or relation-scoped read.
	 *
	 * This is a narrow self-healing guard for multisite write-back.  Network
	 * activation and normal migrations remain the primary upgrade mechanism;
	 * this prevents a target site which has not seen admin_init from silently
	 * losing its media URL/ID replacements.
	 *
	 * @return void
	 */
	private static function ensure_relation_scoped_schema() {
		$table = wptsall_table( 'media_mappings' );
		if ( isset( self::$relation_schema_checked[ $table ] ) ) {
			return;
		}

		if ( function_exists( 'wptsall_ensure_relation_scoped_mapping_tables' ) ) {
			wptsall_ensure_relation_scoped_mapping_tables();
		} elseif ( function_exists( 'wptsall_create_media_mappings_table' ) ) {
			wptsall_create_media_mappings_table();
		}

		self::$relation_schema_checked[ $table ] = true;
	}

	/**
	 * Get media mapping from source to target
	 *
	 * @param int    $source_media_id Source media ID.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID (can be virtual).
	 * @param int    $relation_id     Optional relation ID. When provided, the
	 *                                lookup is strictly relation-scoped.
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_mapping( $source_media_id, $source_site_id, $target_site_id, $relation_id = 0 ) {
		global $wpdb;
		$table       = wptsall_table( 'media_mappings' );
		$relation_id = absint( $relation_id );

		if ( $relation_id > 0 ) {
			self::ensure_relation_scoped_schema();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE relation_id = %d
					AND source_media_id = %d
					AND source_site_id = %d
					AND target_site_id = %s",
					$table,
					$relation_id,
					$source_media_id,
					$source_site_id,
					$target_site_id
				),
				ARRAY_A
			);
		} else {
			// Keep the historical three-argument API for legacy callers and
			// fixtures. Production relation-aware paths pass relation_id above.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i
					WHERE source_media_id = %d
					AND source_site_id = %d
					AND target_site_id = %s",
					$table,
					$source_media_id,
					$source_site_id,
					$target_site_id
				),
				ARRAY_A
			);
		}

		return $mapping ? $mapping : null;
	}

	/**
	 * Get mapped media ID by relation ID and source media ID
	 *
	 * Simplified alias for cross-module API compliance.
	 * Uses relation_id to resolve source_site_id and target_site_id internally.
	 *
	 * @since 0.8.1
	 *
	 * @param int $relation_id Site relation ID.
	 * @param int $source_id   Source media ID.
	 * @return int|null Target media ID or null if not found.
	 */
	public static function get_mapped_id( int $relation_id, int $source_id ): ?int {
		// 1. Get relation info.
		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return null;
		}

		// 2. Determine source and target site IDs.
		$source_site_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id = (string) ( $relation['target_site_id'] ?? '' );

		if ( empty( $target_site_id ) ) {
			return null;
		}

		// 3. Validate source media (switch to source blog to ensure correct context).
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$source_media = get_post( $source_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $source_media || 'attachment' !== $source_media->post_type ) {
			return null;
		}

		// 4. Get mapping using existing method.
		$mapping = self::get_mapping( $source_id, $source_site_id, $target_site_id, $relation_id );

		return $mapping ? (int) $mapping['target_media_id'] : null;
	}

	/**
	 * Get reverse mapping (target to source)
	 *
	 * @param int    $target_media_id Target media ID.
	 * @param string $target_site_id  Target site ID.
	 * @param int    $source_site_id  Source site ID.
	 * @return array|null Mapping data or null if not found.
	 */
	public static function get_reverse_mapping( $target_media_id, $target_site_id, $source_site_id ) {
		global $wpdb;
		$table = wptsall_table( 'media_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE target_media_id = %d
				AND target_site_id = %s
				AND source_site_id = %d",
				$table,
				$target_media_id,
				$target_site_id,
				$source_site_id
			),
			ARRAY_A
		);

		return $mapping ? $mapping : null;
	}

	/**
	 * Create or update media mapping
	 *
	 * @param array $mapping_data {
	 *     Mapping data.
	 *
	 *     @type int    $source_media_id   Source media ID.
	 *     @type int    $source_site_id    Source site ID.
	 *     @type string $source_file_path  Source file path.
	 *     @type string $source_file_url   Source file URL.
	 *     @type int    $target_media_id   Target media ID.
	 *     @type string $target_site_id    Target site ID.
	 *     @type string $target_file_path  Target file path.
	 *     @type string $target_file_url   Target file URL.
	 *     @type string $mapping_method    Mapping method (copy/reuse/external).
	 *     @type bool   $alt_translated    Whether alt text was translated.
	 *     @type array  $metadata          Additional metadata.
	 * }
	 * @return int|false Mapping ID or false on failure.
	 */
	public static function create_mapping( $mapping_data ) {
		global $wpdb;
		self::ensure_relation_scoped_schema();
		$table = wptsall_table( 'media_mappings' );

		// Check if mapping already exists
		$existing = self::get_mapping(
			$mapping_data['source_media_id'],
			$mapping_data['source_site_id'],
			$mapping_data['target_site_id'],
			absint( $mapping_data['relation_id'] ?? 0 )
		);

		$now = current_time( 'mysql', true );

		$data = array(
			'source_media_id'  => $mapping_data['source_media_id'],
			'relation_id'      => absint( $mapping_data['relation_id'] ?? 0 ),
			'source_site_id'   => $mapping_data['source_site_id'],
			'source_file_path' => $mapping_data['source_file_path'],
			'source_file_url'  => $mapping_data['source_file_url'],
			'target_media_id'  => $mapping_data['target_media_id'],
			'target_site_id'   => $mapping_data['target_site_id'],
			'target_file_path' => $mapping_data['target_file_path'],
			'target_file_url'  => $mapping_data['target_file_url'],
			'mapping_method'   => $mapping_data['mapping_method'] ?? 'copy',
			'alt_translated'   => ! empty( $mapping_data['alt_translated'] ) ? 1 : 0,
			'metadata'         => isset( $mapping_data['metadata'] ) ? wp_json_encode( $mapping_data['metadata'] ) : null,
			'updated_at'       => $now,
		);

		if ( $existing ) {
			// Update existing mapping
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				$data,
				array( 'id' => $existing['id'] ),
				array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);

			return $result !== false ? (int) $existing['id'] : false;
		} else {
			// Create new mapping
			$data['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$table,
				$data,
				array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);

			return $result ? (int) $wpdb->insert_id : false;
		}
	}

	/**
	 * Map media with copying and translation
	 *
	 * @param int    $source_media_id Source media ID.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @param string $target_lang     Target language.
	 * @param array  $config          Configuration options.
	 * @return int|false Target media ID or false on failure.
	 */
	public static function map_media_with_copy( $source_media_id, $source_site_id, $target_site_id, $target_lang, $config = array() ) {
		$relation_id = absint( $config['relation_id'] ?? 0 );
		self::ensure_relation_scoped_schema();

		// Check if mapping exists
		$mapping = self::get_mapping( $source_media_id, $source_site_id, $target_site_id, $relation_id );

		if ( $mapping && (int) ( $mapping['target_media_id'] ?? 0 ) > 0 ) {
			self::backfill_mapping_relation( $mapping, $relation_id );
			return (int) $mapping['target_media_id'];
		}

		// Get configuration
		$copy_file       = $config['copy_file'] ?? true;
		$translate_alt   = $config['translate_alt'] ?? false;
		$reuse_existing  = $config['reuse_existing'] ?? false;

		// Try to reuse existing media first
		if ( $reuse_existing ) {
			$reused_id = self::find_existing_media( $source_media_id, $source_site_id, $target_site_id );
			if ( $reused_id ) {
				self::create_mapping(
					array(
						'source_media_id'  => $source_media_id,
						'relation_id'      => $relation_id,
						'source_site_id'   => $source_site_id,
						'source_file_path' => self::get_media_path( $source_media_id, $source_site_id ),
						'source_file_url'  => self::get_media_url( $source_media_id, $source_site_id ),
						'target_media_id'  => $reused_id,
						'target_site_id'   => $target_site_id,
						'target_file_path' => self::get_media_path( $reused_id, $target_site_id ),
						'target_file_url'  => self::get_media_url( $reused_id, $target_site_id ),
						'mapping_method'   => 'reuse',
						'alt_translated'   => false,
					)
				);
				return $reused_id;
			}
		}

		// Copy file to target site
		if ( $copy_file ) {
			$target_media_id = self::copy_media_to_target(
				$source_media_id,
				$source_site_id,
				$target_site_id,
				$target_lang,
				$translate_alt,
				$relation_id
			);

			if ( $target_media_id ) {
				return $target_media_id;
			}
		}

		return false;
	}

	/**
	 * Copy media file to target site
	 *
	 * @param int    $source_media_id Source media ID.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @param string $target_lang     Target language.
	 * @param bool   $translate_alt   Whether to translate alt text.
	 * @return int|false Target media ID or false on failure.
	 */
	private static function copy_media_to_target( $source_media_id, $source_site_id, $target_site_id, $target_lang, $translate_alt = false, $relation_id = 0 ) {
		// Only support numeric target sites for now (multisite)
		if ( ! is_numeric( $target_site_id ) ) {
			// Virtual sites would need different handling
			return false;
		}

		// Get source media data
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$source_file = get_attached_file( $source_media_id );
		$source_url  = wp_get_attachment_url( $source_media_id );
		$source_meta = get_post_meta( $source_media_id, '_wp_attachment_metadata', true );
		$source_alt  = get_post_meta( $source_media_id, '_wp_attachment_image_alt', true );
		$source_post = get_post( $source_media_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $source_file || ! file_exists( $source_file ) ) {
			wptsall_log_error(
				'models',
				'Source media file not found',
				array(
					'source_media_id' => $source_media_id,
					'source_file'     => $source_file,
				)
			);
			return false;
		}

		// Switch to target site
		$need_switch = is_multisite() && (int) $target_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( (int) $target_site_id );
		}

		// Load attachment metadata helpers.
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Copy file to target
		$upload_dir = wp_upload_dir();
		$filename   = basename( $source_file );
		$target_file = $upload_dir['path'] . '/' . $filename;

		// Handle duplicate filenames
		$target_file = wp_unique_filename( $upload_dir['path'], $filename );
		$target_path = $upload_dir['path'] . '/' . $target_file;

		// Copy file
		if ( ! copy( $source_file, $target_path ) ) {
			if ( $need_switch ) {
				restore_current_blog();
			}
			wptsall_log_error(
				'models',
				'Failed to copy media file',
				array(
					'source_file' => $source_file,
					'target_path' => $target_path,
				)
			);
			return false;
		}

		// Get file type
		$filetype = wp_check_filetype( $target_file, null );

		// Prepare attachment data
		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . $target_file,
			'post_mime_type' => $filetype['type'],
			'post_title'     => $source_post->post_title,
			'post_content'   => $source_post->post_content,
			'post_excerpt'   => $source_post->post_excerpt,
			'post_status'    => 'inherit',
		);

		// Insert attachment
		$target_media_id = wp_insert_attachment( $attachment, $target_path );

		if ( is_wp_error( $target_media_id ) ) {
			// Clean up copied file
			wp_delete_file( $target_path );
			if ( $need_switch ) {
				restore_current_blog();
			}
			wptsall_log_error(
				'models',
				'Failed to insert attachment',
				array(
					'error' => $target_media_id->get_error_message(),
				)
			);
			return false;
		}

		// Generate attachment metadata
		$attach_data = wp_generate_attachment_metadata( $target_media_id, $target_path );
		wp_update_attachment_metadata( $target_media_id, $attach_data );

		// Copy alt text
		$alt_text = $source_alt;
		if ( $translate_alt && ! empty( $alt_text ) ) {
			// TODO: Integrate with translation service
			// For now, just append language code
			$alt_text = $alt_text . ' (' . $target_lang . ')';
		}

		if ( ! empty( $alt_text ) ) {
			update_post_meta( $target_media_id, '_wp_attachment_image_alt', $alt_text );
		}

		$target_url  = wp_get_attachment_url( $target_media_id );
		$target_file_path = get_attached_file( $target_media_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		// Create mapping
		self::create_mapping(
			array(
				'source_media_id'  => $source_media_id,
				'relation_id'      => absint( $relation_id ),
				'source_site_id'   => $source_site_id,
				'source_file_path' => $source_file,
				'source_file_url'  => $source_url,
				'target_media_id'  => $target_media_id,
				'target_site_id'   => $target_site_id,
				'target_file_path' => $target_file_path,
				'target_file_url'  => $target_url,
				'mapping_method'   => 'copy',
				'alt_translated'   => $translate_alt,
			)
		);

		wptsall_log_info(
			'models',
			'Media file copied successfully',
			array(
				'source_media_id' => $source_media_id,
				'target_media_id' => $target_media_id,
				'target_site_id'  => $target_site_id,
			)
		);

		return $target_media_id;
	}

	/**
	 * Backfill a legacy mapping's relation ID without ever reassigning a mapping
	 * that is already owned by another relation.
	 *
	 * @param array $mapping Existing mapping row.
	 * @param int   $relation_id Relation to attach.
	 * @return void
	 */
	private static function backfill_mapping_relation( array $mapping, $relation_id ) {
		$relation_id = absint( $relation_id );
		if ( $relation_id <= 0 || (int) ( $mapping['relation_id'] ?? 0 ) > 0 || empty( $mapping['id'] ) ) {
			return;
		}

		global $wpdb;
		$table = wptsall_table( 'media_mappings' );
		// Do not overwrite a concurrent relation assignment.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET relation_id = %d, updated_at = %s WHERE id = %d AND relation_id = 0',
				$table,
				$relation_id,
				current_time( 'mysql', true ),
				(int) $mapping['id']
			)
		);
	}

	/**
	 * Get mappings for one source/target pair, optionally scoped to a relation.
	 *
	 * Sync_Executor uses this before copying embedded media so retry runs do not
	 * duplicate files.  Keeping this query in the mapping service also makes the
	 * relation boundary explicit rather than relying on an undeclared helper.
	 *
	 * @param int        $source_site_id Source blog ID.
	 * @param int|string $target_site_id Target site identifier.
	 * @param int        $relation_id Relation ID, or 0 for legacy callers.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_mappings_by_site_pair( $source_site_id, $target_site_id, $relation_id = 0 ) {
		global $wpdb;
		self::ensure_relation_scoped_schema();
		$table       = wptsall_table( 'media_mappings' );
		$relation_id = absint( $relation_id );

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE source_site_id = %d AND target_site_id = %s AND relation_id = %d',
					$table,
					(int) $source_site_id,
					(string) $target_site_id,
					$relation_id
				),
				ARRAY_A
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_site_id = %d AND target_site_id = %s',
				$table,
				(int) $source_site_id,
				(string) $target_site_id
			),
			ARRAY_A
		);
	}

	/**
	 * Find existing media in target site
	 *
	 * @param int    $source_media_id Source media ID.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @return int|false Existing media ID or false if not found.
	 */
	private static function find_existing_media( $source_media_id, $source_site_id, $target_site_id ) {
		// Get source file hash
		$need_switch = is_multisite() && $source_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( $source_site_id );
		}

		$source_file = get_attached_file( $source_media_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		if ( ! $source_file || ! file_exists( $source_file ) ) {
			return false;
		}

		$source_hash = md5_file( $source_file );

		// Search in target site (only for numeric site IDs)
		if ( ! is_numeric( $target_site_id ) ) {
			return false;
		}

		$need_switch = is_multisite() && (int) $target_site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( (int) $target_site_id );
		}

		// Query attachments
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $attachments as $attachment_id ) {
			$target_file = get_attached_file( $attachment_id );
			if ( $target_file && file_exists( $target_file ) ) {
				$target_hash = md5_file( $target_file );
				if ( $source_hash === $target_hash ) {
					if ( $need_switch ) {
						restore_current_blog();
					}
					return $attachment_id;
				}
			}
		}

		if ( $need_switch ) {
			restore_current_blog();
		}

		return false;
	}

	/**
	 * Get media file path
	 *
	 * @param int        $media_id Media ID.
	 * @param int|string $site_id  Site ID.
	 * @return string File path.
	 */
	private static function get_media_path( $media_id, $site_id ) {
		if ( ! is_numeric( $site_id ) ) {
			return '';
		}

		$need_switch = is_multisite() && (int) $site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( (int) $site_id );
		}

		$path = get_attached_file( $media_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		return $path ? $path : '';
	}

	/**
	 * Get media URL
	 *
	 * @param int        $media_id Media ID.
	 * @param int|string $site_id  Site ID.
	 * @return string Media URL.
	 */
	private static function get_media_url( $media_id, $site_id ) {
		if ( ! is_numeric( $site_id ) ) {
			return '';
		}

		$need_switch = is_multisite() && (int) $site_id !== get_current_blog_id();
		if ( $need_switch ) {
			switch_to_blog( (int) $site_id );
		}

		$url = wp_get_attachment_url( $media_id );

		if ( $need_switch ) {
			restore_current_blog();
		}

		return $url ? $url : '';
	}

	/**
	 * Batch map media files
	 *
	 * @param array  $media_ids       Array of media IDs.
	 * @param int    $source_site_id  Source site ID.
	 * @param string $target_site_id  Target site ID.
	 * @param string $target_lang     Target language.
	 * @param array  $config          Configuration options.
	 * @return array Array of source_id => target_id mappings.
	 */
	public static function batch_map_media( $media_ids, $source_site_id, $target_site_id, $target_lang, $config = array() ) {
		$mapped = array();

		foreach ( $media_ids as $media_id ) {
			$target_id = self::map_media_with_copy(
				$media_id,
				$source_site_id,
				$target_site_id,
				$target_lang,
				$config
			);

			if ( $target_id ) {
				$mapped[ $media_id ] = $target_id;
			}
		}

		return $mapped;
	}

	/**
	 * Delete mapping
	 *
	 * @param int $mapping_id Mapping ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_mapping( $mapping_id ) {
		global $wpdb;
		$table = wptsall_table( 'media_mappings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $mapping_id ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Clean up orphaned mappings
	 *
	 * Deletes mappings where source or target media no longer exists.
	 *
	 * @return int Number of mappings deleted.
	 */
	public static function cleanup_orphaned_mappings() {
		global $wpdb;
		$table = wptsall_table( 'media_mappings' );

		// Get all mappings
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$mappings = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i', $table ),
			ARRAY_A
		);

		$deleted = 0;

		foreach ( $mappings as $mapping ) {
			$source_exists = false;
			$target_exists = false;

			// Check source media exists
			if ( is_multisite() ) {
				switch_to_blog( $mapping['source_site_id'] );
			}
			$source_post = get_post( $mapping['source_media_id'] );
			$source_exists = $source_post && 'attachment' === $source_post->post_type;
			if ( is_multisite() ) {
				restore_current_blog();
			}

			// Check target media exists (only for numeric site IDs)
			if ( is_numeric( $mapping['target_site_id'] ) ) {
				if ( is_multisite() ) {
					switch_to_blog( (int) $mapping['target_site_id'] );
				}
				$target_post = get_post( $mapping['target_media_id'] );
				$target_exists = $target_post && 'attachment' === $target_post->post_type;
				if ( is_multisite() ) {
					restore_current_blog();
				}
			} else {
				// Virtual site - assume exists for now
				$target_exists = true;
			}

			// Delete if either doesn't exist
			if ( ! $source_exists || ! $target_exists ) {
				self::delete_mapping( $mapping['id'] );
				++$deleted;
			}
		}

		return $deleted;
	}
}
