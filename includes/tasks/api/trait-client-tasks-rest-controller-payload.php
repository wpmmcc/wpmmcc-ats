<?php
/**
 * Client tasks REST controller payload trait.
 *
 * Extract client task payload normalization and object reference shaping from
 * the main controller.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Client_Tasks_REST_Controller_Payload_Trait {

	/**
	 * Build task payload array from DB row.
	 *
	 * @param array $row Task row.
	 * @return array
	 */
	private function build_task_data_from_row( $row ) {
		$task = array(
			'id'                => (int) ( $row['id'] ?? 0 ),
			'blog_id'           => (int) ( $row['blog_id'] ?? 0 ),
			'target_blog'       => (int) ( $row['target_blog'] ?? 0 ),
			'target_type'       => sanitize_key( (string) ( $row['target_type'] ?? 'wp' ) ),
			'target_identifier' => sanitize_text_field( (string) ( $row['target_identifier'] ?? '' ) ),
			'site_id'           => (int) ( $row['site_id'] ?? 0 ),
			'site_mode'         => sanitize_text_field( (string) ( $row['site_mode'] ?? '' ) ),
			'template'          => sanitize_key( (string) ( $row['template'] ?? '' ) ),
			'object_type'       => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
			'subtype'           => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
			'object_id'         => (int) ( $row['object_id'] ?? 0 ),
			'lang_from'         => sanitize_text_field( (string) ( $row['lang_from'] ?? '' ) ),
			'lang_to'           => sanitize_text_field( (string) ( $row['lang_to'] ?? '' ) ),
		);

		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		if ( is_array( $payload ) ) {
			$task = array_merge( $task, $payload );
		}

		return $task;
	}

	/**
	 * Normalize outgoing client task payload structure.
	 *
	 * Ensure payload has a stable shape for Rust client:
	 * - payload.task_type
	 * - payload.subtasks[]
	 *
	 * @param array $payload Raw payload.
	 * @param array $row     Task row.
	 * @return array
	 */
	private function normalize_client_task_payload( $payload, $row ) {
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$subtasks = $this->normalize_client_subtasks_for_payload( $payload );
		if ( empty( $subtasks ) ) {
			$subtasks = $this->build_text_subtasks_from_fields( $payload );
		}
		$task_type     = $this->infer_client_task_type( $payload, $row, $subtasks );
		$business_line = $this->infer_client_business_line( $payload, $row );
		$object_ref    = $this->build_client_object_ref( $payload, $row );
		$job_id        = $this->infer_client_job_id( $payload, $row, $business_line );

		$payload['job_id']        = $job_id;
		$payload['business_line'] = $business_line;
		$payload['object_ref']    = $object_ref;
		$payload['task_type']     = $task_type;
		$payload['subtasks']      = $subtasks;
		$payload['content_items'] = $subtasks;
		return $payload;
	}

	/**
	 * Infer client task type.
	 *
	 * @param array $payload  Payload.
	 * @param array $row      Task row.
	 * @param array $subtasks Normalized subtasks.
	 * @return string
	 */
	private function infer_client_task_type( $payload, $row, $subtasks ) {
		if ( is_array( $payload ) ) {
			foreach ( array( 'task_type', 'type' ) as $type_key ) {
				if ( ! empty( $payload[ $type_key ] ) && is_string( $payload[ $type_key ] ) ) {
					return $this->normalize_client_task_type_value( (string) $payload[ $type_key ] );
				}
			}
		}

		$types = array();
		foreach ( (array) $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$type          = $this->normalize_client_task_type_value( (string) ( $subtask['type'] ?? 'text' ) );
			$types[ $type ] = true;
		}
		$type_keys = array_keys( $types );
		if ( count( $type_keys ) > 1 ) {
			return 'mixed';
		}
		if ( 1 === count( $type_keys ) ) {
			return (string) $type_keys[0];
		}

		if ( is_array( $payload ) ) {
			$type_map = array(
				'image'    => array( 'images', 'image_items' ),
				'video'    => array( 'videos', 'video_items' ),
				'audio'    => array( 'audios', 'audio_items' ),
				'document' => array( 'documents', 'document_items' ),
			);
			foreach ( $type_map as $type => $keys ) {
				foreach ( $keys as $key ) {
					if ( ! empty( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
						return $type;
					}
				}
			}
		}

		$row_type = sanitize_key( (string) ( $row['type'] ?? '' ) );
		if ( in_array( $row_type, array( 'image', 'video', 'audio', 'document', 'text' ), true ) ) {
			return $row_type;
		}

		return 'text';
	}

	/**
	 * Infer client business line.
	 *
	 * @param array $payload Payload.
	 * @param array $row     Task row.
	 * @return string
	 */
	private function infer_client_business_line( $payload, $row ) {
		if ( is_array( $payload ) && ! empty( $payload['business_line'] ) && is_string( $payload['business_line'] ) ) {
			return $this->normalize_client_business_line_value( (string) $payload['business_line'] );
		}

		$object_type = sanitize_key( (string) ( $row['object_type'] ?? ( $payload['object_type'] ?? '' ) ) );
		$subtype     = sanitize_key( (string) ( $row['subtype'] ?? ( $payload['subtype'] ?? '' ) ) );
		if ( in_array( $object_type, array( 'post_type', 'post' ), true ) ) {
			return 'post_content';
		}
		if ( in_array( $object_type, array( 'taxonomy', 'term' ), true ) ) {
			return 'taxonomy_content';
		}
		if ( 'language_pack' === $object_type ) {
			if ( in_array( $subtype, array( 'theme', 'theme_i18n' ), true ) ) {
				return 'theme_i18n';
			}
			return 'plugin_i18n';
		}
		return 'custom_model';
	}

	/**
	 * Normalize business line value.
	 *
	 * @param string $raw_value Raw value.
	 * @return string
	 */
	private function normalize_client_business_line_value( $raw_value ) {
		$value = sanitize_key( strtolower( trim( (string) $raw_value ) ) );
		if ( in_array( $value, array( 'post', 'post_type', 'post_content' ), true ) ) {
			return 'post_content';
		}
		if ( in_array( $value, array( 'taxonomy', 'term', 'taxonomy_content' ), true ) ) {
			return 'taxonomy_content';
		}
		if ( in_array( $value, array( 'theme', 'theme_i18n' ), true ) ) {
			return 'theme_i18n';
		}
		if ( in_array( $value, array( 'plugin', 'plugin_i18n', 'language_pack', 'language_pack_i18n' ), true ) ) {
			return 'plugin_i18n';
		}
		if ( in_array( $value, array( 'custom_model', 'custom', 'model' ), true ) ) {
			return 'custom_model';
		}
		return 'custom_model';
	}

	/**
	 * Build object_ref from payload/row.
	 *
	 * @param array $payload Payload.
	 * @param array $row     Task row.
	 * @return array
	 */
	private function build_client_object_ref( $payload, $row ) {
		$existing = is_array( $payload ) ? ( $payload['object_ref'] ?? array() ) : array();
		if ( is_array( $existing ) && ! empty( $existing['object_type'] ) && isset( $existing['object_id'] ) ) {
			return array(
				'object_type'       => sanitize_key( (string) ( $existing['object_type'] ?? '' ) ),
				'subtype'           => sanitize_key( (string) ( $existing['subtype'] ?? '' ) ),
				'object_id'         => (int) ( $existing['object_id'] ?? 0 ),
				'source_blog_id'    => (int) ( $existing['source_blog_id'] ?? ( $row['blog_id'] ?? 0 ) ),
				'target_blog_id'    => (int) ( $existing['target_blog_id'] ?? ( $row['target_blog'] ?? 0 ) ),
				'target_type'       => sanitize_key( (string) ( $existing['target_type'] ?? ( $row['target_type'] ?? '' ) ) ),
				'target_identifier' => sanitize_text_field( (string) ( $existing['target_identifier'] ?? ( $row['target_identifier'] ?? '' ) ) ),
				'site_id'           => (int) ( $existing['site_id'] ?? ( $row['site_id'] ?? 0 ) ),
			);
		}

		return array(
			'object_type'       => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
			'subtype'           => sanitize_key( (string) ( $row['subtype'] ?? '' ) ),
			'object_id'         => (int) ( $row['object_id'] ?? 0 ),
			'source_blog_id'    => (int) ( $row['blog_id'] ?? 0 ),
			'target_blog_id'    => (int) ( $row['target_blog'] ?? 0 ),
			'target_type'       => sanitize_key( (string) ( $row['target_type'] ?? '' ) ),
			'target_identifier' => sanitize_text_field( (string) ( $row['target_identifier'] ?? '' ) ),
			'site_id'           => (int) ( $row['site_id'] ?? 0 ),
		);
	}

	/**
	 * Infer job id from payload/row.
	 *
	 * @param array  $payload       Payload.
	 * @param array  $row           Task row.
	 * @param string $business_line Business line.
	 * @return string
	 */
	private function infer_client_job_id( $payload, $row, $business_line ) {
		if ( is_array( $payload ) && ! empty( $payload['job_id'] ) && is_string( $payload['job_id'] ) ) {
			return sanitize_text_field( (string) $payload['job_id'] );
		}
		$site_id = (int) ( $row['site_id'] ?? 0 );
		$task_id = (int) ( $row['id'] ?? 0 );
		return sanitize_key( sprintf( 'job_legacy_%d_%s_%d', $site_id, sanitize_key( (string) $business_line ), $task_id ) );
	}

	/**
	 * Normalize client task type value.
	 *
	 * @param string $raw_type Raw type.
	 * @return string
	 */
	private function normalize_client_task_type_value( $raw_type ) {
		$type = sanitize_key( strtolower( trim( (string) $raw_type ) ) );
		if ( in_array( $type, array( 'text', 'image', 'video', 'audio', 'document', 'mixed' ), true ) ) {
			return $type;
		}
		if ( in_array( $type, array( 'text_translation', 'field', 'fields' ), true ) ) {
			return 'text';
		}
		if ( in_array( $type, array( 'image_translation', 'images' ), true ) ) {
			return 'image';
		}
		if ( in_array( $type, array( 'video_translation', 'videos' ), true ) ) {
			return 'video';
		}
		if ( in_array( $type, array( 'audio_translation', 'audios' ), true ) ) {
			return 'audio';
		}
		if ( in_array( $type, array( 'document_translation', 'documents', 'doc', 'file', 'files' ), true ) ) {
			return 'document';
		}
		return 'text';
	}

	/**
	 * Normalize payload subtasks list.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function normalize_client_subtasks_for_payload( $payload ) {
		$subtasks = array();
		$seen     = array();

		if ( ! is_array( $payload ) ) {
			return $subtasks;
		}

		foreach ( array( 'subtasks', 'content_items' ) as $list_key ) {
			if ( empty( $payload[ $list_key ] ) || ! is_array( $payload[ $list_key ] ) ) {
				continue;
			}
			foreach ( array_values( $payload[ $list_key ] ) as $index => $item ) {
				$this->append_client_subtask_from_item(
					$subtasks,
					$seen,
					$item,
					'text',
					'subtask',
					$index
				);
			}
		}

		$typed_lists = array(
			'image'    => array( 'images', 'image_items' ),
			'video'    => array( 'videos', 'video_items' ),
			'audio'    => array( 'audios', 'audio_items' ),
			'document' => array( 'documents', 'document_items' ),
		);
		foreach ( $typed_lists as $type => $keys ) {
			foreach ( $keys as $list_key ) {
				if ( empty( $payload[ $list_key ] ) || ! is_array( $payload[ $list_key ] ) ) {
					continue;
				}
				foreach ( array_values( $payload[ $list_key ] ) as $index => $item ) {
					$this->append_client_subtask_from_item(
						$subtasks,
						$seen,
						$item,
						$type,
						$type,
						$index
					);
				}
			}
		}

		return $subtasks;
	}

	/**
	 * Build text subtasks from payload fields (legacy payload compatibility).
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function build_text_subtasks_from_fields( $payload ) {
		$subtasks = array();
		$seen     = array();
		if ( ! is_array( $payload ) || empty( $payload['fields'] ) || ! is_array( $payload['fields'] ) ) {
			return $subtasks;
		}

		foreach ( $payload['fields'] as $field_key => $field_value ) {
			$key = is_string( $field_key ) && '' !== $field_key
				? sanitize_key( $field_key )
				: 'field_' . ( count( $subtasks ) + 1 );
			$source_text = $this->extract_client_source_text( $field_value );
			$dedupe      = 'text|' . $key;
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;
			$subtasks[]      = array(
				'type'        => 'text',
				'key'         => $key,
				'source_text' => $source_text,
			);
		}

		return $subtasks;
	}

	/**
	 * Append normalized subtask item.
	 *
	 * @param array  $subtasks      Subtasks list.
	 * @param array  $seen          De-dup map.
	 * @param mixed  $item          Raw item.
	 * @param string $fallback_type Fallback type.
	 * @param string $key_prefix    Key prefix.
	 * @param int    $index         Item index.
	 * @return void
	 */
	private function append_client_subtask_from_item( &$subtasks, &$seen, $item, $fallback_type, $key_prefix, $index ) {
		$item_array = is_array( $item ) ? $item : array( 'value' => $item );
		$raw_type   = (string) ( $item_array['type'] ?? ( $item_array['kind'] ?? ( $item_array['task_type'] ?? $fallback_type ) ) );
		$type       = $this->normalize_client_task_type_value( $raw_type );
		$raw_key    = (string) ( $item_array['key'] ?? ( $item_array['id'] ?? '' ) );
		$key        = '' !== trim( $raw_key )
			? sanitize_key( $raw_key )
			: sanitize_key( $key_prefix . '_' . ( (int) $index + 1 ) );
		if ( '' === $key ) {
			$key = sanitize_key( $key_prefix . '_' . ( (int) $index + 1 ) );
		}
		$dedupe = $type . '|' . $key;
		if ( isset( $seen[ $dedupe ] ) ) {
			return;
		}
		$seen[ $dedupe ] = true;

		$subtask = array(
			'type'        => $type,
			'task_type'   => $type,
			'key'         => $key,
			'source_text' => $this->extract_client_source_text( $item_array ),
		);

		if ( isset( $item_array['entry_id'] ) ) {
			$subtask['entry_id'] = (int) $item_array['entry_id'];
		}
		if ( ! empty( $item_array['context'] ) ) {
			$subtask['context'] = sanitize_text_field( (string) $item_array['context'] );
		}
		if ( ! empty( $item_array['plural'] ) ) {
			$subtask['plural'] = sanitize_text_field( (string) $item_array['plural'] );
		}

		$source_ref = sanitize_text_field( (string) ( $item_array['source_ref'] ?? '' ) );
		if ( '' !== $source_ref ) {
			$subtask['source_ref'] = $source_ref;
		}
		if ( is_array( $item_array['source_payload'] ?? null ) && ! empty( $item_array['source_payload'] ) ) {
			$subtask['source_payload'] = $item_array['source_payload'];
		}

		$subtasks[]   = $subtask;
		$target_path = sanitize_text_field(
			(string) ( $item_array['target_path'] ?? ( $item_array['path'] ?? '' ) )
		);
		if ( '' !== $target_path && preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
			$subtasks[ count( $subtasks ) - 1 ]['target_path'] = $target_path;
		}
		$target_paths = array();
		foreach ( array( 'target_paths', 'field_targets', 'targets' ) as $map_key ) {
			if ( ! is_array( $item_array[ $map_key ] ?? null ) ) {
				continue;
			}
			foreach ( $item_array[ $map_key ] as $raw_field => $raw_path ) {
				$field = sanitize_key( (string) $raw_field );
				$path  = sanitize_text_field( (string) $raw_path );
				if ( '' === $field || '' === $path ) {
					continue;
				}
				if ( ! preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $path ) ) {
					continue;
				}
				$target_paths[ $field ] = $path;
			}
		}
		if ( ! empty( $target_paths ) ) {
			$subtasks[ count( $subtasks ) - 1 ]['target_paths'] = $target_paths;
		}
	}

	/**
	 * Extract source text from raw payload value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function extract_client_source_text( $value ) {
		if ( is_array( $value ) ) {
			$candidate_keys = array(
				'source_text',
				'text',
				'value',
				'source',
				'caption',
				'title',
				'description',
				'transcript',
				'prompt',
				'content',
			);
			foreach ( $candidate_keys as $key ) {
				if ( isset( $value[ $key ] ) ) {
					$text = $this->extract_client_source_text( $value[ $key ] );
					if ( '' !== trim( $text ) ) {
						return $text;
					}
				}
			}
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return is_string( $encoded ) ? $encoded : '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		return '';
	}
}
