<?php
/**
 * Client Tasks REST Controller patch trait.
 *
 * Extracted from Client_Tasks_REST_Controller to isolate client result
 * normalization and patch helper logic from task orchestration.
 *
 * @package WPTSALL\Tasks\API
 */

namespace WPTSALL\Tasks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Client_Tasks_REST_Controller_Patch_Trait {

	/**
	 * Normalize translated fields from client result.
	 *
	 * Accepted:
	 * - { "fields": [ { "key": "post_title", "translated": "..." } ] }
	 * - { "fields": { "post_title": "..." } }
	 *
	 * @param array $result Client result payload.
	 * @return array<string, string>
	 */
	private function normalize_translated_fields( $result ) {
		$map    = array();
		$fields = is_array( $result ) ? ( $result['fields'] ?? array() ) : array();

		if ( is_array( $fields ) ) {
			foreach ( $fields as $key => $item ) {
				if ( is_array( $item ) && isset( $item['key'] ) ) {
					$field_key = $this->normalize_field_key( (string) $item['key'] );
					$value     = isset( $item['translated'] ) ? (string) $item['translated'] : (string) ( $item['value'] ?? '' );
					if ( '' !== $field_key ) {
						$map[ $field_key ] = $value;
					}
					continue;
				}

				if ( is_string( $key ) ) {
					$field_key = $this->normalize_field_key( $key );
					if ( '' !== $field_key ) {
						$map[ $field_key ] = is_scalar( $item ) ? (string) $item : wp_json_encode( $item );
					}
				}
			}
		}

		$subtasks = is_array( $result ) ? ( $result['subtasks'] ?? array() ) : array();
		if ( is_array( $subtasks ) ) {
			foreach ( $subtasks as $subtask ) {
				if ( ! is_array( $subtask ) ) {
					continue;
				}
				$type = $this->normalize_client_task_type_value( (string) ( $subtask['type'] ?? '' ) );
				if ( 'text' !== $type ) {
					continue;
				}
				$status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
				if ( ! in_array( $status, array( 'completed', 'success', 'done' ), true ) ) {
					continue;
				}
				$field_key = $this->normalize_field_key( (string) ( $subtask['key'] ?? '' ) );
				if ( '' === $field_key ) {
					continue;
				}
				$translated = $subtask['translated'] ?? ( $subtask['value'] ?? '' );
				$translated = is_scalar( $translated )
					? (string) $translated
					: wp_json_encode( $translated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$map[ $field_key ] = is_string( $translated ) ? $translated : '';
			}
		}

		$patch     = is_array( $result ) ? ( $result['patch'] ?? array() ) : array();
		$fragments = is_array( $patch ) ? ( $patch['fragments'] ?? array() ) : array();
		if ( is_array( $fragments ) ) {
			foreach ( $fragments as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? '' ) );
				if ( 'text' !== $type ) {
					continue;
				}
				$status = sanitize_key( (string) ( $fragment['status'] ?? '' ) );
				if ( ! in_array( $status, array( 'completed', 'success', 'done' ), true ) ) {
					continue;
				}
				$field_key = $this->normalize_field_key( (string) ( $fragment['key'] ?? '' ) );
				if ( '' === $field_key ) {
					continue;
				}
				$value = $fragment['value'] ?? ( $fragment['translated'] ?? '' );
				$value = is_scalar( $value )
					? (string) $value
					: wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$map[ $field_key ] = is_string( $value ) ? $value : '';
			}
		}

		return $map;
	}

	/**
	 * Normalize patch payload from client result.
	 *
	 * @param array $result Client result payload.
	 * @return array
	 */
	private function normalize_client_result_patch( $result ) {
		$normalized = array(
			'idempotency_key'  => '',
			'fragments'        => array(),
			'translated_fields'=> array(),
			'summary'          => array(
				'total'     => 0,
				'completed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
			),
		);
		if ( ! is_array( $result ) ) {
			return $normalized;
		}

		$patch = is_array( $result['patch'] ?? null ) ? $result['patch'] : array();
		$normalized['idempotency_key'] = sanitize_text_field(
			(string) ( $patch['idempotency_key'] ?? ( $patch['key'] ?? '' ) )
		);

		$seen = array();
		$append_fragment = function( $fragment ) use ( &$normalized, &$seen ) {
			if ( ! is_array( $fragment ) ) {
				return;
			}
			$fragment_id = (string) ( $fragment['fragment_id'] ?? '' );
			if ( '' === $fragment_id ) {
				$fragment_id = (string) ( $fragment['type'] ?? 'text' ) . ':' . (string) ( $fragment['key'] ?? '' );
			}
			$dedupe_key = sanitize_key( $fragment_id );
			if ( '' === $dedupe_key ) {
				$dedupe_key = 'frag_' . ( count( $normalized['fragments'] ) + 1 );
			}
			if ( isset( $seen[ $dedupe_key ] ) ) {
				return;
			}
			$seen[ $dedupe_key ] = true;

			$status = sanitize_key( (string) ( $fragment['status'] ?? 'completed' ) );
			if ( in_array( $status, array( 'success', 'done' ), true ) ) {
				$status = 'completed';
			} elseif ( 'error' === $status ) {
				$status = 'failed';
			} elseif ( 'noop' === $status ) {
				$status = 'skipped';
			}
			if ( ! in_array( $status, array( 'completed', 'failed', 'skipped' ), true ) ) {
				$status = 'completed';
			}
			$fragment['status'] = $status;
			$normalized['fragments'][] = $fragment;
			$normalized['summary']['total']++;
			if ( isset( $normalized['summary'][ $status ] ) ) {
				$normalized['summary'][ $status ]++;
			}
			if ( 'completed' === $status ) {
				$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? '' ) );
				$key  = $this->normalize_field_key( (string) ( $fragment['key'] ?? '' ) );
				if ( 'text' === $type && '' !== $key ) {
					$normalized['translated_fields'][ $key ] = (string) ( $fragment['value'] ?? '' );
				}
			}
		};

		$patch_fragments = is_array( $patch['fragments'] ?? null ) ? $patch['fragments'] : array();
		foreach ( $patch_fragments as $fragment ) {
			$normalized_fragment = $this->normalize_single_client_patch_fragment( $fragment, array() );
			$append_fragment( $normalized_fragment );
		}

		$subtasks = is_array( $result['subtasks'] ?? null ) ? $result['subtasks'] : array();
		foreach ( $subtasks as $subtask ) {
			if ( ! is_array( $subtask ) ) {
				continue;
			}
			$fallback = array(
				'type'          => $subtask['type'] ?? '',
				'key'           => $subtask['key'] ?? '',
				'status'        => $subtask['status'] ?? 'completed',
				'translated'    => $subtask['translated'] ?? ( $subtask['value'] ?? '' ),
				'error_code'    => $subtask['error_code'] ?? '',
				'error_message' => $subtask['error_message'] ?? '',
				'fallback'      => ! empty( $subtask['fallback'] ),
			);
			if ( isset( $subtask['patch_fragment'] ) ) {
				$normalized_fragment = $this->normalize_single_client_patch_fragment(
					$subtask['patch_fragment'],
					$fallback
				);
				$append_fragment( $normalized_fragment );
				continue;
			}
			$normalized_fragment = $this->normalize_single_client_patch_fragment( $fallback, array() );
			$append_fragment( $normalized_fragment );
		}

		return $normalized;
	}

	/**
	 * Normalize single patch fragment.
	 *
	 * @param mixed $fragment Raw fragment.
	 * @param array $fallback Fallback values.
	 * @return array
	 */
	private function normalize_single_client_patch_fragment( $fragment, $fallback = array() ) {
		$fallback = is_array( $fallback ) ? $fallback : array();
		$raw      = is_array( $fragment ) ? $fragment : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$type = $this->normalize_client_task_type_value(
			(string) ( $raw['type'] ?? ( $raw['task_type'] ?? ( $fallback['type'] ?? 'text' ) ) )
		);
		$key_raw = (string) ( $raw['key'] ?? ( $raw['id'] ?? ( $fallback['key'] ?? '' ) ) );
		$key     = sanitize_key( $key_raw );
		$status  = sanitize_key( (string) ( $raw['status'] ?? ( $fallback['status'] ?? 'completed' ) ) );
		if ( in_array( $status, array( 'success', 'done' ), true ) ) {
			$status = 'completed';
		} elseif ( 'error' === $status ) {
			$status = 'failed';
		} elseif ( 'noop' === $status ) {
			$status = 'skipped';
		}
		if ( ! in_array( $status, array( 'completed', 'failed', 'skipped' ), true ) ) {
			$status = 'completed';
		}

		$value = $this->normalize_client_patch_fragment_value(
			$raw['value'] ?? ( $raw['translated'] ?? ( $fallback['translated'] ?? '' ) )
		);
		$target_path = sanitize_text_field(
			(string) ( $raw['target_path'] ?? ( $raw['path'] ?? '' ) )
		);
		if ( '' !== $target_path && ! preg_match( '/^(post|meta|term)\.[A-Za-z0-9_\-]+$/', $target_path ) ) {
			$target_path = '';
		}
		$error_code = $this->normalize_client_error_code(
			(string) ( $raw['error_code'] ?? ( $fallback['error_code'] ?? '' ) )
		);
		$error_message = sanitize_text_field(
			(string) ( $raw['error_message'] ?? ( $fallback['error_message'] ?? '' ) )
		);
		$fallback_used = ! empty( $raw['fallback'] ) || ! empty( $fallback['fallback'] );
		$fragment_id   = sanitize_text_field(
			(string) ( $raw['fragment_id'] ?? sprintf( '%s:%s', $type, $key ) )
		);

		if ( '' === $key && '' === $target_path ) {
			return array();
		}

		return array(
			'fragment_id'   => $fragment_id,
			'type'          => $type,
			'key'           => $key,
			'status'        => $status,
			'value'         => $value,
			'target_path'   => $target_path,
			'error_code'    => $error_code,
			'error_message' => $error_message,
			'fallback'      => (bool) $fallback_used,
		);
	}

	/**
	 * Normalize fragment value into string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize_client_patch_fragment_value( $value ) {
		if ( is_null( $value ) ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Apply patch fragments to complete data.
	 *
	 * @param array $task          Task.
	 * @param array $complete_data Complete data.
	 * @param array $fragments     Patch fragments.
	 * @return array|\WP_Error
	 */
	private function apply_patch_fragments_to_complete_data( $task, $complete_data, $fragments ) {
		$summary = array(
			'total'                   => 0,
			'completed'               => 0,
			'failed'                  => 0,
			'skipped'                 => 0,
			'applied'                 => 0,
			'applied_business'        => 0,
			'applied_recorded'        => 0,
			'recorded_only'           => 0,
			'unapplied'               => 0,
			'applied_fragments'       => array(),
			'recorded_only_fragments' => array(),
			'unapplied_fragments'     => array(),
		);
		if ( ! is_array( $complete_data ) ) {
			return new \WP_Error(
				'patch_apply_invalid_source',
				__( 'Patch application failed: invalid source data', 'wpmmcc-ats' ),
				array( 'status' => 500 )
			);
		}
		if ( ! is_array( $fragments ) ) {
			return array(
				'complete_data' => $complete_data,
				'summary'       => $summary,
			);
		}

		if ( ! isset( $complete_data['meta'] ) || ! is_array( $complete_data['meta'] ) ) {
			$complete_data['meta'] = array();
		}

		foreach ( $fragments as $fragment ) {
			if ( ! is_array( $fragment ) ) {
				continue;
			}
			$summary['total']++;
			$status = sanitize_key( (string) ( $fragment['status'] ?? 'completed' ) );
			if ( in_array( $status, array( 'success', 'done' ), true ) ) {
				$status = 'completed';
			} elseif ( 'error' === $status ) {
				$status = 'failed';
			} elseif ( 'noop' === $status ) {
				$status = 'skipped';
			}
			if ( ! in_array( $status, array( 'completed', 'failed', 'skipped' ), true ) ) {
				$status = 'completed';
			}
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
			if ( 'completed' !== $status ) {
				continue;
			}

			$value           = $this->normalize_client_patch_fragment_value( $fragment['value'] ?? '' );
			$target_path     = (string) ( $fragment['target_path'] ?? '' );
			$applied_target  = false;
			$fallback_result = array(
				'applied'          => false,
				'business_applied' => false,
				'recorded'         => false,
			);
			$is_non_text = $this->is_non_text_patch_fragment( $fragment );
			if ( '' !== $target_path ) {
				$target_value = $value;
				if ( $is_non_text ) {
					$target_value = $this->resolve_non_text_target_path_value( $fragment, $target_path, $value );
				}
				if ( ! $is_non_text || '' !== trim( (string) $target_value ) ) {
					$applied_target = $this->apply_patch_value_to_complete_data_path( $complete_data, $target_path, $target_value );
				}
			}
			if ( ! $applied_target || $is_non_text ) {
				$fallback_result = $this->apply_patch_value_by_fallback_mapping( $task, $complete_data, $fragment, $value );
			}
			$applied_fallback = ! empty( $fallback_result['applied'] );
			$applied_business = $applied_target || ! empty( $fallback_result['business_applied'] );
			$applied_recorded = ! empty( $fallback_result['recorded'] );
			$applied          = $applied_target || $applied_fallback;
			if ( $applied ) {
				$summary['applied']++;
				if ( $applied_business ) {
					$summary['applied_business']++;
				}
				if ( $applied_recorded ) {
					$summary['applied_recorded']++;
				}
				$summary['applied_fragments'][] = array(
					'fragment_id'      => sanitize_text_field( (string) ( $fragment['fragment_id'] ?? '' ) ),
					'type'             => sanitize_key( (string) ( $fragment['type'] ?? 'text' ) ),
					'key'              => sanitize_key( (string) ( $fragment['key'] ?? '' ) ),
					'fragment_key'     => $this->build_patch_fragment_key( $fragment ),
					'business_applied' => (bool) $applied_business,
					'recorded'         => (bool) $applied_recorded,
					'target_path'      => sanitize_text_field( (string) ( $fragment['target_path'] ?? '' ) ),
				);
				if ( $is_non_text && ! $applied_business && $applied_recorded ) {
					$summary['recorded_only']++;
					$summary['recorded_only_fragments'][] = array(
						'fragment_id'  => sanitize_text_field( (string) ( $fragment['fragment_id'] ?? '' ) ),
						'type'         => sanitize_key( (string) ( $fragment['type'] ?? 'text' ) ),
						'key'          => sanitize_key( (string) ( $fragment['key'] ?? '' ) ),
						'fragment_key' => $this->build_patch_fragment_key( $fragment ),
						'target_path'  => sanitize_text_field( (string) ( $fragment['target_path'] ?? '' ) ),
					);
				}
			} else {
				$summary['unapplied']++;
				$summary['unapplied_fragments'][] = array(
					'fragment_id'  => sanitize_text_field( (string) ( $fragment['fragment_id'] ?? '' ) ),
					'type'         => sanitize_key( (string) ( $fragment['type'] ?? 'text' ) ),
					'key'          => sanitize_key( (string) ( $fragment['key'] ?? '' ) ),
					'fragment_key' => $this->build_patch_fragment_key( $fragment ),
					'target_path'  => sanitize_text_field( (string) ( $fragment['target_path'] ?? '' ) ),
				);
			}
		}

		return array(
			'complete_data' => $complete_data,
			'summary'       => $summary,
		);
	}

	/**
	 * Build normalized patch fragment key (type:key).
	 *
	 * @param array $fragment Patch fragment.
	 * @return string
	 */
	private function build_patch_fragment_key( $fragment ) {
		if ( ! is_array( $fragment ) ) {
			return '';
		}
		$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? 'text' ) );
		if ( '' === $type ) {
			$type = 'text';
		}
		$key = sanitize_key( (string) ( $fragment['key'] ?? '' ) );
		if ( '' === $key ) {
			return '';
		}
		return $type . ':' . $key;
	}

	/**
	 * Collect normalized fragment keys from patch fragments.
	 *
	 * @param array $fragments Patch fragments.
	 * @return array
	 */
	private function collect_fragment_keys_from_fragments( $fragments ) {
		$keys = array();
		foreach ( (array) $fragments as $fragment ) {
			if ( ! is_array( $fragment ) ) {
				continue;
			}
			$key = $this->build_patch_fragment_key( $fragment );
			if ( '' === $key ) {
				continue;
			}
			$keys[] = $key;
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Collect retry fragment keys from patch summary.
	 *
	 * @param array $patch_summary Patch summary.
	 * @return array
	 */
	private function collect_patch_retry_fragment_keys( $patch_summary ) {
		$keys = array();
		foreach ( array( 'unapplied_fragments', 'recorded_only_fragments' ) as $bucket ) {
			$items = is_array( $patch_summary[ $bucket ] ?? null ) ? $patch_summary[ $bucket ] : array();
			foreach ( $items as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$key = sanitize_text_field( (string) ( $fragment['fragment_key'] ?? '' ) );
				if ( '' === $key ) {
					$key = $this->build_patch_fragment_key( $fragment );
				}
				if ( '' === $key ) {
					continue;
				}
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Cleanup manual queue after patch apply succeeded.
	 *
	 * @param array $meta_data     Task meta (by ref).
	 * @param array $patch_summary Patch apply summary.
	 * @return void
	 */
	private function cleanup_manual_queue_after_patch_apply( &$meta_data, $patch_summary ) {
		if ( ! is_array( $meta_data ) ) {
			return;
		}
		$queue_items = is_array( $meta_data['client_patch_manual_queue'] ?? null )
			? array_values( $meta_data['client_patch_manual_queue'] )
			: array();
		if ( empty( $queue_items ) ) {
			return;
		}

		$applied_count          = (int) ( $patch_summary['applied'] ?? 0 );
		$business_applied_count = (int) ( $patch_summary['applied_business'] ?? 0 );
		if ( $applied_count <= 0 || $business_applied_count <= 0 ) {
			return;
		}

		$applied_keys = array();
		foreach ( (array) ( $patch_summary['applied_fragments'] ?? array() ) as $fragment ) {
			if ( ! is_array( $fragment ) || empty( $fragment['business_applied'] ) ) {
				continue;
			}
			$key = sanitize_text_field( (string) ( $fragment['fragment_key'] ?? '' ) );
			if ( '' === $key ) {
				$key = $this->build_patch_fragment_key( $fragment );
			}
			if ( '' === $key ) {
				continue;
			}
			$applied_keys[ $key ] = true;
		}
		if ( empty( $applied_keys ) ) {
			return;
		}

		$next_queue = array();
		foreach ( $queue_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$reason = sanitize_key( (string) ( $item['reason'] ?? '' ) );
			if ( 'non_text_without_target_mapping' === $reason ) {
				continue;
			}

			$unapplied_fragments = is_array( $item['unapplied_fragments'] ?? null )
				? array_values( $item['unapplied_fragments'] )
				: array();
			if ( empty( $unapplied_fragments ) ) {
				$next_queue[] = $item;
				continue;
			}

			$next_fragments = array();
			foreach ( $unapplied_fragments as $fragment ) {
				if ( ! is_array( $fragment ) ) {
					continue;
				}
				$fragment_key = sanitize_text_field( (string) ( $fragment['fragment_key'] ?? '' ) );
				if ( '' === $fragment_key ) {
					$fragment_key = $this->build_patch_fragment_key( $fragment );
				}
				if ( '' !== $fragment_key && isset( $applied_keys[ $fragment_key ] ) ) {
					continue;
				}
				$next_fragments[] = $fragment;
			}
			if ( empty( $next_fragments ) && in_array( $reason, array( 'patch_fragment_unapplied', 'patch_fragment_recorded_only' ), true ) ) {
				continue;
			}
			$item['unapplied_fragments'] = $next_fragments;
			if ( isset( $item['unapplied'] ) ) {
				$item['unapplied'] = count( $next_fragments );
			}
			$next_queue[] = $item;
		}

		if ( empty( $next_queue ) ) {
			unset( $meta_data['client_patch_manual_queue'] );
			return;
		}
		$meta_data['client_patch_manual_queue'] = array_values( $next_queue );
	}

	/**
	 * Apply patch by explicit target path.
	 *
	 * @param array  $complete_data Complete data.
	 * @param string $target_path   Target path.
	 * @param string $value         Value.
	 * @return bool
	 */
	private function apply_patch_value_to_complete_data_path( &$complete_data, $target_path, $value ) {
		$target_path = trim( (string) $target_path );
		if ( '' === $target_path ) {
			return false;
		}
		if ( ! preg_match( '/^(post|meta|term)\.([A-Za-z0-9_\-]+)$/', $target_path, $matches ) ) {
			return false;
		}
		$root = (string) $matches[1];
		$key  = sanitize_key( (string) $matches[2] );
		if ( '' === $key ) {
			return false;
		}
		if ( ! isset( $complete_data[ $root ] ) || ! is_array( $complete_data[ $root ] ) ) {
			$complete_data[ $root ] = array();
		}
		$patched_value = $value;
		if ( ( 'term' === $root && 'slug' === $key ) || ( 'post' === $root && 'post_name' === $key ) ) {
			$patched_value = sanitize_title( $value );
		}
		$complete_data[ $root ][ $key ] = $patched_value;
		return true;
	}

	/**
	 * Whether patch fragment is a non-text type.
	 *
	 * @param array $fragment Patch fragment.
	 * @return bool
	 */
	private function is_non_text_patch_fragment( $fragment ) {
		$type = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? 'text' ) );
		return 'text' !== $type;
	}

	/**
	 * Resolve target path value for non-text fragments.
	 *
	 * @param array  $fragment    Patch fragment.
	 * @param string $target_path Target path.
	 * @param string $value       Raw patch value.
	 * @return string
	 */
	private function resolve_non_text_target_path_value( $fragment, $target_path, $value ) {
		$target_path = trim( (string) $target_path );
		if ( '' === $target_path ) {
			return $value;
		}
		if ( ! preg_match( '/^(post|meta|term)\.([A-Za-z0-9_\-]+)$/', $target_path, $matches ) ) {
			return $value;
		}
		$target_key = sanitize_key( (string) ( $matches[2] ?? '' ) );
		if ( '' === $target_key ) {
			return $value;
		}
		if ( 0 === strpos( $target_key, 'wptsall_' ) ) {
			return $value;
		}

		$structured         = $this->parse_non_text_patch_value( $value );
		$translated_ref     = sanitize_text_field( (string) ( $structured['translated_ref'] ?? '' ) );
		$translated_fields  = is_array( $structured['translated_fields'] ?? null ) ? $structured['translated_fields'] : array();
		if ( empty( $structured ) ) {
			return $value;
		}
		$candidates = array( $target_key );
		if ( in_array( $target_key, array( 'post_title', 'title', 'name' ), true ) ) {
			$candidates = array_merge( $candidates, array( 'title', 'name', 'summary', 'text' ) );
		} elseif ( in_array( $target_key, array( 'post_content', 'content', 'description' ), true ) ) {
			$candidates = array_merge( $candidates, array( 'description', 'content', 'text', 'transcript', 'subtitle', 'subtitles', 'summary', 'script', 'voiceover' ) );
		} elseif ( in_array( $target_key, array( 'post_excerpt', 'excerpt', 'caption' ), true ) ) {
			$candidates = array_merge( $candidates, array( 'caption', 'description', 'summary', 'text' ) );
		} elseif ( in_array( $target_key, array( 'post_name', 'slug' ), true ) ) {
			$candidates = array_merge( $candidates, array( 'slug', 'title', 'text' ) );
		} elseif ( in_array( $target_key, array( 'alt', 'alt_text' ), true ) ) {
			$candidates = array_merge( $candidates, array( 'alt', 'alt_text', 'title', 'text' ) );
		}
		foreach ( array_unique( $candidates ) as $candidate ) {
			$normalized = sanitize_key( (string) $candidate );
			if ( '' === $normalized || ! array_key_exists( $normalized, $translated_fields ) ) {
				continue;
			}
			$field_value = $this->normalize_client_patch_fragment_value( $translated_fields[ $normalized ] );
			if ( '' !== trim( $field_value ) ) {
				return $field_value;
			}
		}
		if ( '' !== $translated_ref ) {
			return $translated_ref;
		}
		return '';
	}

	/**
	 * Apply patch by fallback mapping.
	 *
	 * @param array  $task          Task.
	 * @param array  $complete_data Complete data.
	 * @param array  $fragment      Fragment.
	 * @param string $value         Value.
	 * @return array
	 */
	private function apply_patch_value_by_fallback_mapping( $task, &$complete_data, $fragment, $value ) {
		$result = array(
			'applied'          => false,
			'business_applied' => false,
			'recorded'         => false,
		);
		$type        = $this->normalize_client_task_type_value( (string) ( $fragment['type'] ?? '' ) );
		$key         = sanitize_key( (string) ( $fragment['key'] ?? '' ) );
		$object_type = sanitize_key( (string) ( $task['object_type'] ?? '' ) );
		if ( '' === $key ) {
			return $result;
		}
		if ( ! isset( $complete_data['meta'] ) || ! is_array( $complete_data['meta'] ) ) {
			$complete_data['meta'] = array();
		}

		if ( 'text' === $type ) {
			if ( in_array( $object_type, array( 'post_type', 'post' ), true ) ) {
				if ( ! isset( $complete_data['post'] ) || ! is_array( $complete_data['post'] ) ) {
					$complete_data['post'] = array();
				}
				if ( in_array( $key, array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ), true ) ) {
					$complete_data['post'][ $key ] = 'post_name' === $key ? sanitize_title( $value ) : $value;
					$result['applied']          = true;
					$result['business_applied'] = true;
					return $result;
				}
				$complete_data['meta'][ $key ] = $value;
				$result['applied']          = true;
				$result['business_applied'] = true;
				return $result;
			}
			if ( in_array( $object_type, array( 'taxonomy', 'term' ), true ) ) {
				if ( ! isset( $complete_data['term'] ) || ! is_array( $complete_data['term'] ) ) {
					$complete_data['term'] = array();
				}
				if ( in_array( $key, array( 'name', 'description', 'slug' ), true ) ) {
					$complete_data['term'][ $key ] = 'slug' === $key ? sanitize_title( $value ) : $value;
					$result['applied']          = true;
					$result['business_applied'] = true;
					return $result;
				}
				$complete_data['meta'][ $key ] = $value;
				$result['applied']          = true;
				$result['business_applied'] = true;
				return $result;
			}
			if ( 'option' === $object_type ) {
				$complete_data['meta'][ $key ] = $value;
				$result['applied']          = true;
				$result['business_applied'] = true;
				return $result;
			}
			return $result;
		}

		$structured = $this->parse_non_text_patch_value( $value );
		if ( empty( $structured ) ) {
			return $result;
		}

		$translated_fields = is_array( $structured['translated_fields'] ?? null ) ? $structured['translated_fields'] : array();
		$source_ref        = is_array( $structured['source_ref'] ?? null ) ? $structured['source_ref'] : array();
		$translated_ref    = is_array( $structured['translated_ref'] ?? null ) ? $structured['translated_ref'] : array();
		$default_value     = sanitize_text_field( (string) ( $translated_ref['ref_value'] ?? '' ) );

		$applied = $this->apply_non_text_structured_target_paths(
			$complete_data,
			$structured,
			$translated_fields,
			$source_ref,
			$translated_ref,
			$default_value
		);
		if ( $applied ) {
			$result['applied']          = true;
			$result['business_applied'] = true;
			return $result;
		}

		if ( ! isset( $complete_data['meta']['wptsall_non_text_results'] ) || ! is_array( $complete_data['meta']['wptsall_non_text_results'] ) ) {
			$complete_data['meta']['wptsall_non_text_results'] = array();
		}
		$complete_data['meta']['wptsall_non_text_results'][ $key ] = $structured;
		$result['applied']  = true;
		$result['recorded'] = true;

		return $result;
	}

	/**
	 * Apply non-text structured fields to known target paths.
	 *
	 * @param array  $complete_data     Complete data.
	 * @param array  $structured        Structured result payload.
	 * @param array  $translated_fields Translated fields.
	 * @param array  $source_ref        Source ref.
	 * @param array  $translated_ref    Translated ref.
	 * @param string $default_value     Default value.
	 * @return bool
	 */
	private function apply_non_text_structured_target_paths( &$complete_data, $structured, $translated_fields, $source_ref, $translated_ref, $default_value ) {
		$target_paths = is_array( $structured['target_paths'] ?? null ) ? $structured['target_paths'] : array();
		$applied      = false;

		foreach ( $target_paths as $alias => $mode ) {
			$target_path = sanitize_text_field( (string) $alias );
			$mode        = sanitize_key( (string) $mode );
			if ( '' === $target_path ) {
				continue;
			}
			$resolved = $this->resolve_non_text_structured_target_value(
				$alias,
				$mode,
				$translated_fields,
				$source_ref,
				$translated_ref,
				$default_value
			);
			if ( '' === trim( (string) $resolved ) ) {
				continue;
			}
			if ( $this->apply_patch_value_to_complete_data_path( $complete_data, $target_path, $resolved ) ) {
				$applied = true;
			}
		}

		return $applied;
	}

	/**
	 * Resolve non-text structured target value.
	 *
	 * @param string $alias             Alias.
	 * @param string $mode              Mode.
	 * @param array  $translated_fields Translated fields.
	 * @param array  $source_ref        Source ref.
	 * @param array  $translated_ref    Translated ref.
	 * @param string $default_value     Default value.
	 * @return string
	 */
	private function resolve_non_text_structured_target_value( $alias, $mode, $translated_fields, $source_ref, $translated_ref, $default_value ) {
		$alias = sanitize_key( (string) $alias );
		$mode  = sanitize_key( (string) $mode );

		if ( '' !== $mode ) {
			if ( 'translated_ref' === $mode ) {
				return sanitize_text_field( (string) ( $translated_ref['ref_value'] ?? $default_value ) );
			}
			if ( 'source_ref' === $mode ) {
				return sanitize_text_field( (string) ( $source_ref['ref_value'] ?? '' ) );
			}
			if ( 'default' === $mode ) {
				return $default_value;
			}
		}

		$candidate_keys = array( $alias );
		if ( in_array( $alias, array( 'title', 'name' ), true ) ) {
			$candidate_keys = array_merge( $candidate_keys, array( 'title', 'name', 'summary', 'text' ) );
		} elseif ( in_array( $alias, array( 'description', 'content', 'caption' ), true ) ) {
			$candidate_keys = array_merge( $candidate_keys, array( 'description', 'content', 'caption', 'summary', 'text' ) );
		} elseif ( in_array( $alias, array( 'slug', 'post_name' ), true ) ) {
			$candidate_keys = array_merge( $candidate_keys, array( 'slug', 'title', 'text' ) );
		}
		foreach ( array_unique( $candidate_keys ) as $candidate_key ) {
			$candidate_key = sanitize_key( (string) $candidate_key );
			if ( '' === $candidate_key || ! array_key_exists( $candidate_key, $translated_fields ) ) {
				continue;
			}
			$value = $this->normalize_client_patch_fragment_value( $translated_fields[ $candidate_key ] );
			if ( '' !== trim( $value ) ) {
				return $value;
			}
		}

		return $default_value;
	}

	/**
	 * Parse non-text patch value.
	 *
	 * @param string $value Value.
	 * @return array
	 */
	private function parse_non_text_patch_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}
		if ( ! in_array( $value[0], array( '{', '[' ), true ) ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalize client error code.
	 *
	 * @param string $raw      Raw code.
	 * @param string $fallback Fallback code.
	 * @return string
	 */
	private function normalize_client_error_code( $raw, $fallback = '' ) {
		$code = sanitize_key( (string) $raw );
		if ( '' !== $code ) {
			return $code;
		}
		$fallback = sanitize_key( (string) $fallback );
		return '' !== $fallback ? $fallback : 'callback_failed';
	}

	/**
	 * Extract callback failed error code from message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function extract_callback_failed_error_code( $message ) {
		$message = (string) $message;
		if ( preg_match( '/callback_failed:([a-z0-9_\-]+)/i', $message, $matches ) ) {
			return sanitize_key( (string) $matches[1] );
		}
		return 'callback_failed';
	}
}
