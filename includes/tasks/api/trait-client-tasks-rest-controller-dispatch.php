<?php
/**
 * Client Tasks REST Controller dispatch trait.
 *
 * Extracted from Client_Tasks_REST_Controller to isolate non-text write-back
 * dispatch preparation from task orchestration.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Client_Tasks_REST_Controller_Dispatch_Trait {

	/**
	 * Extract non-text items from client result for Write_Back_Dispatcher.
	 *
	 * @param array $result        Client result payload.
	 * @param array $patch_context Normalized patch context from normalize_client_result_patch().
	 * @return array
	 */
	private function extract_non_text_dispatch_items( $result, $patch_context ) {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$items = array();
		$seen  = array();

		$direct_items = is_array( $result['non_text_items'] ?? null ) ? $result['non_text_items'] : array();
		foreach ( $direct_items as $item ) {
			if ( ! is_array( $item ) || empty( $item['entity_type'] ) ) {
				continue;
			}
			$dedupe_key = $this->build_non_text_item_dedupe_key( $item );
			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}
			$seen[ $dedupe_key ] = true;
			$items[]             = $item;
		}

		$type_map = array(
			'images'    => 'image',
			'videos'    => 'video',
			'audios'    => 'audio',
			'documents' => 'document',
		);
		foreach ( $type_map as $list_key => $entity_type ) {
			$typed_items = is_array( $result[ $list_key ] ?? null ) ? $result[ $list_key ] : array();
			foreach ( $typed_items as $typed_item ) {
				if ( ! is_array( $typed_item ) ) {
					continue;
				}
				$item = $this->normalize_typed_non_text_item( $typed_item, $entity_type );
				if ( empty( $item ) ) {
					continue;
				}
				$dedupe_key = $this->build_non_text_item_dedupe_key( $item );
				if ( isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}
				$seen[ $dedupe_key ] = true;
				$items[]             = $item;
			}
		}

		$fragments = is_array( $patch_context['fragments'] ?? null ) ? $patch_context['fragments'] : array();
		foreach ( $fragments as $fragment ) {
			if ( ! is_array( $fragment ) || ! $this->is_non_text_patch_fragment( $fragment ) ) {
				continue;
			}
			if ( 'completed' !== ( $fragment['status'] ?? '' ) ) {
				continue;
			}
			$item = $this->build_dispatch_item_from_fragment( $fragment );
			if ( empty( $item ) ) {
				continue;
			}
			$dedupe_key = $this->build_non_text_item_dedupe_key( $item );
			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}
			$seen[ $dedupe_key ] = true;
			$items[]             = $item;
		}

		return $items;
	}

	/**
	 * Build dispatch context for Write_Back_Dispatcher from task data.
	 *
	 * @param array $task Task data.
	 * @param array $row  Task DB row.
	 * @return array
	 */
	private function build_write_back_dispatch_context( $task, $row ) {
		global $wpdb;

		$context = array(
			'relation_id' => 0,
			'task_id'     => (int) ( $task['id'] ?? 0 ),
			'source_blog' => (int) ( $task['blog_id'] ?? get_current_blog_id() ),
			'target_blog' => (int) ( $task['target_blog'] ?? 0 ),
			'target_type' => sanitize_key( (string) ( $task['target_type'] ?? 'wp' ) ),
			'lang_to'     => sanitize_text_field( (string) ( $task['lang_to'] ?? '' ) ),
		);

		$site_id  = (int) ( $task['site_id'] ?? 0 );
		$template = sanitize_key( (string) ( $task['template'] ?? '' ) );

		if ( $site_id > 0 ) {
			$context['relation_id'] = $site_id;
		} elseif ( '' !== $template ) {
			$rel_table = wptsall_table( 'site_relations' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rel_id    = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE template = %s LIMIT 1',
					$rel_table,
					$template
				)
			);
			if ( $rel_id ) {
				$context['relation_id'] = (int) $rel_id;
			}
		}

		return $context;
	}

	/**
	 * Normalize a typed non-text item into dispatch format.
	 *
	 * @param array  $item        Raw item from typed array.
	 * @param string $entity_type The entity type.
	 * @return array
	 */
	private function normalize_typed_non_text_item( $item, $entity_type ) {
		if ( ! is_array( $item ) ) {
			return array();
		}

		if ( ! empty( $item['entity_type'] ) && ! empty( $item['translated_ref'] ) ) {
			return $item;
		}

		$source_id = (int) ( $item['source_id'] ?? ( $item['id'] ?? ( $item['attachment_id'] ?? 0 ) ) );

		$translated_ref = is_array( $item['translated_ref'] ?? null ) ? $item['translated_ref'] : array();
		if ( empty( $translated_ref ) ) {
			$ref_type  = sanitize_key( (string) ( $item['ref_type'] ?? '' ) );
			$ref_value = $item['ref_value'] ?? ( $item['url'] ?? ( $item['path'] ?? '' ) );

			if ( '' === $ref_type && ! empty( $item['url'] ) ) {
				$ref_type  = 'url';
				$ref_value = $item['url'];
			} elseif ( '' === $ref_type && ! empty( $item['path'] ) ) {
				$ref_type  = 'path';
				$ref_value = $item['path'];
			} elseif ( '' === $ref_type && ! empty( $item['target_id'] ) ) {
				$ref_type  = 'id';
				$ref_value = $item['target_id'];
			}

			if ( '' === $ref_type || '' === (string) $ref_value ) {
				return array();
			}

			$translated_ref = array(
				'ref_type'  => $ref_type,
				'ref_value' => $ref_value,
			);
		}

		$metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
		if ( ! empty( $item['filename'] ) ) {
			$metadata['filename'] = sanitize_file_name( $item['filename'] );
		}
		if ( ! empty( $item['mime_type'] ) ) {
			$metadata['mime_type'] = sanitize_mime_type( $item['mime_type'] );
		}
		if ( ! empty( $item['title'] ) ) {
			$metadata['title'] = sanitize_text_field( $item['title'] );
		}

		$adapter_entity_type = $entity_type;
		if ( in_array( $entity_type, array( 'image', 'video', 'audio' ), true ) ) {
			$adapter_entity_type = ! empty( $item['entity_type'] )
				? sanitize_key( $item['entity_type'] )
				: $entity_type;
		}

		return array(
			'entity_type'    => $adapter_entity_type,
			'source_id'      => $source_id,
			'translated_ref' => $translated_ref,
			'metadata'       => $metadata,
		);
	}

	/**
	 * Build a dispatch item from a non-text patch fragment.
	 *
	 * @param array $fragment Normalized patch fragment.
	 * @return array
	 */
	private function build_dispatch_item_from_fragment( $fragment ) {
		if ( ! is_array( $fragment ) ) {
			return array();
		}

		$type      = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? 'text' ) );
		$value     = (string) ( $fragment['value'] ?? '' );
		$structured = $this->parse_non_text_patch_value( $value );
		if ( empty( $structured ) ) {
			return array();
		}

		$translated_ref = is_array( $structured['translated_ref'] ?? null )
			? $structured['translated_ref']
			: array();

		if ( empty( $translated_ref ) ) {
			if ( ! empty( $structured['url'] ) ) {
				$translated_ref = array(
					'ref_type'  => 'url',
					'ref_value' => $structured['url'],
				);
			} elseif ( ! empty( $structured['path'] ) ) {
				$translated_ref = array(
					'ref_type'  => 'path',
					'ref_value' => $structured['path'],
				);
			} elseif ( ! empty( $structured['target_id'] ) ) {
				$translated_ref = array(
					'ref_type'  => 'id',
					'ref_value' => $structured['target_id'],
				);
			}
		}

		if ( empty( $translated_ref['ref_type'] ) || empty( $translated_ref['ref_value'] ) ) {
			return array();
		}

		$source_id = (int) ( $structured['source_id'] ?? ( $structured['id'] ?? 0 ) );

		$entity_type_map = array(
			'image'    => 'image',
			'video'    => 'video',
			'audio'    => 'audio',
			'document' => 'document',
		);
		$entity_type = $entity_type_map[ $type ] ?? 'attachment';
		if ( ! empty( $structured['entity_type'] ) ) {
			$entity_type = sanitize_key( $structured['entity_type'] );
		}

		$metadata = is_array( $structured['metadata'] ?? null ) ? $structured['metadata'] : array();

		return array(
			'entity_type'    => $entity_type,
			'source_id'      => $source_id,
			'translated_ref' => $translated_ref,
			'metadata'       => $metadata,
		);
	}

	/**
	 * Build a deduplication key for a non-text dispatch item.
	 *
	 * @param array $item Dispatch item.
	 * @return string
	 */
	private function build_non_text_item_dedupe_key( $item ) {
		$entity_type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
		$source_id   = (int) ( $item['source_id'] ?? 0 );
		$ref         = is_array( $item['translated_ref'] ?? null ) ? $item['translated_ref'] : array();
		$ref_type    = sanitize_key( (string) ( $ref['ref_type'] ?? '' ) );
		$ref_value   = (string) ( $ref['ref_value'] ?? '' );

		return sprintf( '%s:%d:%s:%s', $entity_type, $source_id, $ref_type, $ref_value );
	}
}
