<?php
/**
 * Media Metadata Write-Back Adapter
 *
 * Handles write-back for media metadata: alt text, captions, descriptions,
 * and other translatable attachment fields.
 *
 * Unlike Attachment_Write_Back_Adapter which deals with the media file itself,
 * this adapter handles the textual metadata associated with media items.
 *
 * Supported ref_types:
 * - id: Attachment ID on target site (update metadata in place)
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
 * Media metadata write-back adapter.
 *
 * Handles translatable metadata on attachment posts:
 * alt text (_wp_attachment_image_alt), caption (post_excerpt),
 * description (post_content), title (post_title).
 */
class Media_Meta_Write_Back_Adapter extends Write_Back_Adapter_Base {

	/**
	 * Metadata fields that can be translated on attachments.
	 *
	 * Maps logical field names to WordPress storage locations.
	 * Post fields are stored in wp_posts columns; meta fields in wp_postmeta.
	 *
	 * @var array
	 */
	const TRANSLATABLE_FIELDS = array(
		'alt_text'    => '_wp_attachment_image_alt',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
		'title'       => 'post_title',
	);

	/**
	 * Fields stored as post columns (not postmeta).
	 *
	 * @var array
	 */
	const POST_COLUMN_FIELDS = array( 'caption', 'description', 'title' );

	/**
	 * Get adapter type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'media_meta';
	}

	/**
	 * Get supported ref types.
	 *
	 * @return array
	 */
	public function get_supported_ref_types(): array {
		return array( 'id' );
	}

	/**
	 * Check if this adapter can handle the given item.
	 *
	 * @param array $item Non-text item.
	 * @return bool
	 */
	public function can_handle( array $item ): bool {
		$type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
		return in_array( $type, array( 'media_meta', 'attachment_meta', 'image_meta' ), true );
	}

	/**
	 * Apply translated media metadata to target attachment.
	 *
	 * Updates translatable fields on the target attachment:
	 * - Post fields (title, caption, description) via wp_update_post or Direct_DB_Service
	 * - Meta fields (alt_text) via update_post_meta or Direct_DB_Service
	 *
	 * @param array $item    Non-text item with translated metadata.
	 *   Expected: 'translated_ref' => ['ref_type' => 'id', 'ref_value' => target_attachment_id],
	 *             'translated_fields' => ['alt_text' => '...', 'caption' => '...', ...].
	 * @param array $context Sync context.
	 * @return array Result.
	 */
	public function apply( array $item, array $context ): array {
		$translated_ref = $item['translated_ref'] ?? array();
		$validation     = $this->validate_ref( $translated_ref );

		if ( is_wp_error( $validation ) ) {
			return $this->build_result( false, null, $validation->get_error_message() );
		}

		$target_attachment_id = (int) $translated_ref['ref_value'];
		$translated_fields    = $item['translated_fields'] ?? array();

		if ( empty( $translated_fields ) ) {
			return $this->build_result( false, null, 'No translated fields provided for media metadata' );
		}

		$target_type = $context['target_type'] ?? 'wp';
		$target_blog = (int) ( $context['target_blog'] ?? 0 );
		$switched    = false;

		wptsall_log_info(
			'tasks-sync',
			'Media meta write-back applying',
			array(
				'target_attachment_id' => $target_attachment_id,
				'fields'              => array_keys( $translated_fields ),
				'target_type'         => $target_type,
				'adapter'             => $this->get_type(),
			)
		);

		// Switch to target blog for WP multisite targets.
		if ( 'wp' === $target_type && $target_blog > 0 && is_multisite() ) {
			switch_to_blog( $target_blog );
			$switched = true;
		}

		// Verify attachment exists on target.
		$attachment = get_post( $target_attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return $this->build_result(
				false,
				null,
				sprintf( 'Attachment ID %d does not exist on target site', $target_attachment_id )
			);
		}

		// Separate fields into post columns and meta fields.
		$post_updates = array();
		$meta_updates = array();
		$updated      = 0;
		$errors       = array();

		foreach ( $translated_fields as $field_key => $field_value ) {
			if ( ! isset( self::TRANSLATABLE_FIELDS[ $field_key ] ) ) {
				$errors[] = sprintf( 'Unknown media meta field: %s', $field_key );
				continue;
			}

			$wp_field = self::TRANSLATABLE_FIELDS[ $field_key ];

			if ( in_array( $field_key, self::POST_COLUMN_FIELDS, true ) ) {
				$post_updates[ $wp_field ] = sanitize_text_field( $field_value );
			} else {
				$meta_updates[ $wp_field ] = sanitize_text_field( $field_value );
			}
		}

		// Apply post column updates (title, caption, description).
		if ( ! empty( $post_updates ) ) {
			if ( 'virtual' === $target_type ) {
				// Use Direct_DB_Service for virtual site context.
				$site_context = array(
					'type'            => 'virtual',
					'virtual_site_id' => (string) $target_blog,
				);
				$result = Direct_DB_Service::update_post( $target_attachment_id, $post_updates, $site_context );
			} else {
				$post_updates['ID'] = $target_attachment_id;
				$result = wp_update_post( $post_updates, true );
			}

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$updated += count( $post_updates ) - ( isset( $post_updates['ID'] ) ? 1 : 0 );
			}
		}

		// Apply meta field updates (alt_text).
		foreach ( $meta_updates as $meta_key => $meta_value ) {
			$success = update_post_meta( $target_attachment_id, $meta_key, $meta_value );

			if ( false !== $success ) {
				++$updated;
			} else {
				$errors[] = sprintf( 'Failed to update meta key: %s', $meta_key );
			}
		}

		// Store write-back tracking meta.
		update_post_meta(
			$target_attachment_id,
			'_wptsall_media_meta_updated',
			current_time( 'mysql' )
		);

		if ( ! empty( $context['relation_id'] ) ) {
			update_post_meta(
				$target_attachment_id,
				'_wptsall_relation_id',
				(int) $context['relation_id']
			);
		}

		if ( $switched ) {
			restore_current_blog();
		}

		if ( 0 === $updated && ! empty( $errors ) ) {
			wptsall_log_error(
				'tasks-sync',
				'Media meta write-back failed',
				array(
					'target_attachment_id' => $target_attachment_id,
					'errors'              => $errors,
				)
			);
			return $this->build_result(
				false,
				$target_attachment_id,
				implode( '; ', $errors )
			);
		}

		wptsall_log_info(
			'tasks-sync',
			'Media meta write-back completed',
			array(
				'target_attachment_id' => $target_attachment_id,
				'fields_updated'      => $updated,
				'errors'              => $errors,
				'target_type'         => $target_type,
			)
		);

		return $this->build_result( true, $target_attachment_id );
	}
}
