<?php
/**
 * Attachment Write-Back Adapter
 *
 * Handles write-back for WordPress media library attachments.
 * Copies or references translated media files in the target site.
 *
 * Supported ref_types:
 * - url: Direct URL to translated media file (sideload into target)
 * - id:  Existing attachment ID on target site
 * - path: Relative file path within uploads directory
 *
 * @package WPTSALL\Tasks\Sync
 * @since 1.0.5
 */

namespace WPTSALL\Tasks\Sync;

use WPTSALL\Tasks\Services\Direct_DB_Service;
use WPTSALL\Models\Services\Media_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attachment write-back adapter.
 *
 * Handles WordPress media library items (images, videos, audio, documents
 * stored as attachments).
 */
class Attachment_Write_Back_Adapter extends Write_Back_Adapter_Base {

	/**
	 * Get adapter type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'attachment';
	}

	/**
	 * Get supported ref types.
	 *
	 * @return array
	 */
	public function get_supported_ref_types(): array {
		return array( 'url', 'id', 'path' );
	}

	/**
	 * Check if this adapter can handle the given item.
	 *
	 * @param array $item Non-text item.
	 * @return bool
	 */
	public function can_handle( array $item ): bool {
		$type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
		return in_array( $type, array( 'attachment', 'media', 'image', 'video', 'audio' ), true );
	}

	/**
	 * Apply translated attachment to target.
	 *
	 * Routes to the appropriate handler based on target_type and ref_type:
	 * - For WP subsites: uses switch_to_blog + WordPress media functions
	 * - For virtual sites: stores with virtual site meta markers
	 *
	 * @param array $item    Non-text item with translated content.
	 * @param array $context Sync context.
	 * @return array Result.
	 */
	public function apply( array $item, array $context ): array {
		$translated_ref = $item['translated_ref'] ?? array();
		$validation     = $this->validate_ref( $translated_ref );

		if ( is_wp_error( $validation ) ) {
			return $this->build_result( false, null, $validation->get_error_message() );
		}

		$ref_type    = sanitize_key( (string) $translated_ref['ref_type'] );
		$ref_value   = $translated_ref['ref_value'];
		$target_type = $context['target_type'] ?? 'wp';
		$source_id   = (int) ( $item['source_id'] ?? 0 );

		wptsall_log_info(
			'tasks-sync',
			'Attachment write-back applying',
			array(
				'source_id'   => $source_id,
				'ref_type'    => $ref_type,
				'target_type' => $target_type,
				'adapter'     => $this->get_type(),
			)
		);

		switch ( $ref_type ) {
			case 'url':
				return $this->apply_from_url( $ref_value, $item, $context );

			case 'id':
				return $this->apply_from_id( (int) $ref_value, $item, $context );

			case 'path':
				return $this->apply_from_path( (string) $ref_value, $item, $context );

			default:
				return $this->build_result(
					false,
					null,
					sprintf( 'Unhandled ref_type=%s in attachment adapter', $ref_type )
				);
		}
	}

	/**
	 * Apply attachment from a URL.
	 *
	 * Direct URL download is disabled for security. Media files must be
	 * uploaded via the client media-upload endpoint instead.
	 *
	 * @param string $url     The URL (no longer used).
	 * @param array  $item    Non-text item.
	 * @param array  $context Sync context.
	 * @return array Result (always error).
	 */
	private function apply_from_url( string $url, array $item, array $context ): array {
		wptsall_log_error(
			'tasks-sync',
			'Attachment URL download rejected — use media-upload endpoint',
			array(
				'url'       => $url,
				'source_id' => (int) ( $item['source_id'] ?? 0 ),
			)
		);

		return $this->build_result(
			false,
			null,
			__( 'Direct URL download is disabled. Media files must be uploaded via the client media-upload endpoint.', 'wpmmcc-ats' )
		);
	}

	/**
	 * Apply attachment by referencing an existing attachment ID on the target.
	 *
	 * Verifies the attachment exists on the target site and creates a mapping.
	 *
	 * @param int   $attachment_id The target attachment ID.
	 * @param array $item          Non-text item.
	 * @param array $context       Sync context.
	 * @return array Result.
	 */
	private function apply_from_id( int $attachment_id, array $item, array $context ): array {
		$target_type = $context['target_type'] ?? 'wp';
		$target_blog = (int) ( $context['target_blog'] ?? 0 );
		$source_id   = (int) ( $item['source_id'] ?? 0 );
		$switched    = false;

		// Switch to target blog for WP multisite targets.
		if ( 'wp' === $target_type && $target_blog > 0 && is_multisite() ) {
			switch_to_blog( $target_blog );
			$switched = true;
		}

		// Verify attachment exists on the target site.
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $this->build_result(
				false,
				null,
				sprintf( 'Attachment ID %d does not exist on target site', $attachment_id )
			);
		}

		// Store the mapping reference.
		\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, '_wptsall_source_attachment_id', $source_id );
		if ( ! empty( $context['relation_id'] ) ) {
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, \WPTSALL\Sites\Services\Translation_Identity::META_RELATION_ID, (int ) $context['relation_id'] );
		}

		// Write media_mappings for Sync_Executor to resolve ID references.
		$source_site_id = (int) ( $context['source_site_id'] ?? get_current_blog_id() );
		$target_site_str = 'wp' === $target_type ? (string) $target_blog : ( $context['target_site_id'] ?? '' );
		if ( $source_id > 0 && ! empty( $target_site_str ) ) {
			Media_Mapping_Service::create_mapping( array(
				'source_media_id'  => $source_id,
				'relation_id'      => absint( $context['relation_id'] ?? 0 ),
				'source_site_id'   => $source_site_id,
				'source_file_path' => '',
				'source_file_url'  => '',
				'target_media_id'  => $attachment_id,
				'target_site_id'   => $target_site_str,
				'target_file_path' => '',
				'target_file_url'  => wp_get_attachment_url( $attachment_id ) ?: '',
				'mapping_method'   => 'id_reference',
			) );
		}

		if ( $switched ) {
			restore_current_blog();
		}

		wptsall_log_info(
			'tasks-sync',
			'Attachment ID reference mapped successfully',
			array(
				'source_id'     => $source_id,
				'attachment_id' => $attachment_id,
				'target_type'   => $target_type,
			)
		);

		return $this->build_result( true, $attachment_id );
	}

	/**
	 * Apply attachment from a file path within the uploads directory.
	 *
	 * Locates the file and creates an attachment post for it.
	 *
	 * @param string $relative_path Relative path within uploads directory.
	 * @param array  $item          Non-text item.
	 * @param array  $context       Sync context.
	 * @return array Result.
	 */
	private function apply_from_path( string $relative_path, array $item, array $context ): array {
		$target_type = $context['target_type'] ?? 'wp';
		$target_blog = (int) ( $context['target_blog'] ?? 0 );
		$source_id   = (int) ( $item['source_id'] ?? 0 );
		$metadata    = $item['metadata'] ?? array();
		$switched    = false;

		// Switch to target blog for WP multisite targets.
		if ( 'wp' === $target_type && $target_blog > 0 && is_multisite() ) {
			switch_to_blog( $target_blog );
			$switched = true;
		}

		// Resolve the full file path.
		$upload_dir = wp_upload_dir();
		$file_path  = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative_path, '/' );

		if ( ! file_exists( $file_path ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $this->build_result(
				false,
				null,
				sprintf( 'File not found at path: %s', $relative_path )
			);
		}

		// Determine MIME type.
		$filetype = wp_check_filetype( $file_path );
		if ( empty( $filetype['type'] ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $this->build_result(
				false,
				null,
				sprintf( 'Could not determine MIME type for: %s', $relative_path )
			);
		}

		// Build attachment data.
		$title = $metadata['title'] ?? preg_replace( '/\.[^.]+$/', '', basename( $file_path ) );

		$attachment_data = array(
			'guid'           => trailingslashit( $upload_dir['baseurl'] ) . ltrim( $relative_path, '/' ),
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field( $title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		// Insert the attachment.
		$attachment_id = wp_insert_attachment( $attachment_data, $file_path );

		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			if ( $switched ) {
				restore_current_blog();
			}
			$error = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'wp_insert_attachment returned 0';
			wptsall_log_error(
				'tasks-sync',
				'Attachment creation from path failed',
				array(
					'path'  => $relative_path,
					'error' => $error,
				)
			);
			return $this->build_result( false, null, $error );
		}

		// Generate attachment metadata (thumbnails, sizes, etc.).
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		// Store source reference.
		\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, '_wptsall_source_attachment_id', $source_id );
		if ( ! empty( $context['relation_id'] ) ) {
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, \WPTSALL\Sites\Services\Translation_Identity::META_RELATION_ID, (int ) $context['relation_id'] );
		}

		// For virtual sites, add virtual site marker.
		if ( 'virtual' === $target_type ) {
			$virtual_site_id = $context['target_blog'] ?? '';
			if ( ! empty( $virtual_site_id ) ) {
				\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, $virtual_site_id );
			}
		}

		// Write media_mappings for Sync_Executor to resolve ID references.
		$source_site_id  = (int) ( $context['source_site_id'] ?? get_current_blog_id() );
		$target_site_str = 'wp' === $target_type ? (string) $target_blog : ( $context['target_site_id'] ?? '' );
		$target_url      = wp_get_attachment_url( $attachment_id ) ?: '';
		if ( $source_id > 0 && ! empty( $target_site_str ) ) {
			Media_Mapping_Service::create_mapping( array(
				'source_media_id'  => $source_id,
				'relation_id'      => absint( $context['relation_id'] ?? 0 ),
				'source_site_id'   => $source_site_id,
				'source_file_path' => '',
				'source_file_url'  => '',
				'target_media_id'  => $attachment_id,
				'target_site_id'   => $target_site_str,
				'target_file_path' => $file_path,
				'target_file_url'  => $target_url,
				'mapping_method'   => 'path_upload',
			) );
		}

		if ( $switched ) {
			restore_current_blog();
		}

		wptsall_log_info(
			'tasks-sync',
			'Attachment created from path successfully',
			array(
				'source_id'     => $source_id,
				'attachment_id' => $attachment_id,
				'path'          => $relative_path,
				'target_type'   => $target_type,
			)
		);

		return $this->build_result( true, $attachment_id );
	}
}
