<?php
/**
 * Document Write-Back Adapter
 *
 * Handles write-back for document files (PDFs, Office documents, etc.)
 * that require special handling beyond standard attachment processing.
 *
 * For documents with a direct translated file available (url or path),
 * this adapter sideloads/registers the file as an attachment.
 * For documents requiring embedded text extraction, OCR, or format
 * conversion, items are routed to the manual queue.
 *
 * Supported ref_types:
 * - url:  URL to translated document file
 * - path: File path to translated document
 *
 * @package WPTSALL\Tasks\Sync
 * @since 1.0.5
 */

namespace WPTSALL\Tasks\Sync;

use WPTSALL\Tasks\Services\Direct_DB_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document write-back adapter.
 *
 * Handles document files (PDFs, DOCX, XLSX, etc.) that contain
 * translatable content embedded within the file format.
 */
class Document_Write_Back_Adapter extends Write_Back_Adapter_Base {

	/**
	 * Document MIME types handled by this adapter.
	 *
	 * @var array
	 */
	const DOCUMENT_MIME_TYPES = array(
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	);

	/**
	 * Get adapter type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'document';
	}

	/**
	 * Get supported ref types.
	 *
	 * @return array
	 */
	public function get_supported_ref_types(): array {
		return array( 'url', 'path' );
	}

	/**
	 * Check if this adapter can handle the given item.
	 *
	 * @param array $item Non-text item.
	 * @return bool
	 */
	public function can_handle( array $item ): bool {
		$type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
		if ( 'document' === $type ) {
			return true;
		}

		$mime = (string) ( $item['mime_type'] ?? '' );
		if ( '' !== $mime ) {
			return in_array( $mime, self::DOCUMENT_MIME_TYPES, true );
		}

		return false;
	}

	/**
	 * Apply translated document to target.
	 *
	 * When a translated document file is available (url or path),
	 * sideloads it into the target site's media library.
	 * Items flagged as needing manual processing (embedded text, OCR)
	 * are routed to the manual queue via a failed result.
	 *
	 * @param array $item    Non-text item with translated document reference.
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
		$metadata    = $item['metadata'] ?? array();
		$source_id   = (int) ( $item['source_id'] ?? 0 );
		$target_type = $context['target_type'] ?? 'wp';

		// Check if item is flagged for manual processing.
		$requires_manual = ! empty( $metadata['requires_manual_processing'] )
			|| ! empty( $metadata['needs_ocr'] )
			|| ! empty( $metadata['needs_text_extraction'] );

		if ( $requires_manual ) {
			wptsall_log_info(
				'tasks-sync',
				'Document flagged for manual processing',
				array(
					'source_id' => $source_id,
					'ref_type'  => $ref_type,
					'reason'    => 'requires_manual_processing',
					'adapter'   => $this->get_type(),
				)
			);

			return $this->build_result(
				false,
				null,
				'Document requires manual processing (OCR/text extraction); routed to manual queue'
			);
		}

		wptsall_log_info(
			'tasks-sync',
			'Document write-back applying',
			array(
				'source_id'   => $source_id,
				'ref_type'    => $ref_type,
				'target_type' => $target_type,
				'mime_type'   => $item['mime_type'] ?? '',
				'adapter'     => $this->get_type(),
			)
		);

		switch ( $ref_type ) {
			case 'url':
				return $this->apply_from_url( (string) $ref_value, $item, $context );

			case 'path':
				return $this->apply_from_path( (string) $ref_value, $item, $context );

			default:
				return $this->build_result(
					false,
					null,
					sprintf( 'Unhandled ref_type=%s in document adapter', $ref_type )
				);
		}
	}

	/**
	 * Apply document from a URL.
	 *
	 * Direct URL download is disabled for security. Document files must be
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
			'Document URL download rejected — use media-upload endpoint',
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
	 * Apply document from a file path.
	 *
	 * Locates the translated document file and creates an attachment for it.
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
				sprintf( 'Document file not found at path: %s', $relative_path )
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
				sprintf( 'Could not determine MIME type for document: %s', $relative_path )
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
				'Document attachment creation from path failed',
				array(
					'path'  => $relative_path,
					'error' => $error,
				)
			);
			return $this->build_result( false, null, $error );
		}

		// Generate attachment metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		// Store source and tracking metadata.
		\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, '_wptsall_source_attachment_id', $source_id );
		update_post_meta( $attachment_id, '_wptsall_document_type', 'translated' );
		if ( ! empty( $context['relation_id'] ) ) {
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, \WPTSALL\Sites\Services\Translation_Identity::META_RELATION_ID, (int ) $context['relation_id'] );
		}
		if ( ! empty( $context['lang_to'] ) ) {
			update_post_meta( $attachment_id, '_wptsall_target_language', sanitize_key( $context['lang_to'] ) );
		}

		// For virtual sites, add virtual site marker.
		if ( 'virtual' === $target_type ) {
			$virtual_site_id = (string) $target_blog;
			if ( ! empty( $virtual_site_id ) ) {
				\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $attachment_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, $virtual_site_id );
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}

		wptsall_log_info(
			'tasks-sync',
			'Document created from path successfully',
			array(
				'source_id'     => $source_id,
				'attachment_id' => $attachment_id,
				'path'          => $relative_path,
				'mime_type'     => $filetype['type'],
				'target_type'   => $target_type,
			)
		);

		return $this->build_result( true, $attachment_id );
	}
}
