<?php
/**
 * Unified ID Mapping Resolver
 *
 * Replaces three parallel id_mapping systems:
 * - System A: field_capabilities type="id_mapping" (no reference_type detail)
 * - System B: tasks.php wptsall_process_id_mapping_fields() (legacy field_category query)
 * - System C: Field_Processor::process_sync_mapped_fields() (separate vocabulary)
 *
 * All three systems performed the same core operation: resolve source IDs to
 * target IDs using the four Mapping Services. This class unifies the logic
 * behind a single API with explicit reference_type dispatch and format handling.
 *
 * @package WPTSALL\Models\Services
 * @since 1.3.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified ID Mapping Resolver Class
 *
 * @since 1.3.0
 */
class Id_Mapping_Resolver {

	/**
	 * Resolve a single id_mapping field's source value to target value.
	 *
	 * @since 1.3.0
	 *
	 * @param string $field_name   Meta key or field name.
	 * @param mixed  $source_value Source value (int, CSV string, serialized, JSON).
	 * @param array  $field_config From field_capabilities v2: {type, reference_type, reference_target, value_format}.
	 * @param array  $context      {relation_id: int, source_blog_id: int, target_blog_id: int, target_type: string}.
	 * @return array {resolved: bool, target_value: mixed, method: string, error: ?string}
	 */
	public static function resolve( $field_name, $source_value, $field_config, $context ) {
		// Determine reference_type: explicit config wins, then infer from field name.
		$reference_type = ! empty( $field_config['reference_type'] )
			? $field_config['reference_type']
			: self::infer_reference_type( $field_name, $source_value );

		// Determine value format: explicit config wins, default to scalar.
		$value_format = ! empty( $field_config['value_format'] )
			? $field_config['value_format']
			: 'scalar';

		// Parse source_value into an array of integer IDs.
		$source_ids = self::parse_value( $source_value, $value_format );

		// Empty value passthrough — nothing to resolve.
		if ( empty( $source_ids ) ) {
			return array(
				'resolved'     => true,
				'target_value' => $source_value,
				'method'       => 'passthrough',
				'error'        => null,
			);
		}

		$relation_id  = isset( $context['relation_id'] ) ? absint( $context['relation_id'] ) : 0;
		$resolved_ids = array();
		$all_resolved = true;
		$method       = $reference_type;

		$is_multi_value = in_array( $value_format, array( 'csv', 'serialized', 'json' ), true );

		foreach ( $source_ids as $source_id ) {
			$target_id = self::resolve_single_id( $source_id, $reference_type, $field_config, $context );

			if ( null !== $target_id ) {
				$resolved_ids[] = $target_id;
			} else {
				$all_resolved = false;
				if ( $is_multi_value ) {
					// Multi-value: keep source ID so partial data is preserved
					// (e.g. CSV "101,2,103" is better than losing everything).
					$resolved_ids[] = $source_id;
				}
				// Scalar: do NOT keep source ID — writing a source-site ID
				// into the target site creates dangling references (e.g. a
				// _thumbnail_id pointing to a non-existent attachment).
			}
		}

		// For scalar fields where resolution failed, return null to prevent
		// writing invalid IDs to the target site.
		if ( ! $is_multi_value && ! $all_resolved ) {
			return array(
				'resolved'     => false,
				'target_value' => null,
				'method'       => $method,
				'error'        => sprintf(
					/* translators: 1: field name, 2: reference type */
					'Scalar field %1$s could not be resolved via %2$s mapping',
					$field_name,
					$reference_type
				),
			);
		}

		// Reconstruct the value in the original format.
		$target_value = self::reconstruct_value( $resolved_ids, $value_format );

		return array(
			'resolved'     => $all_resolved,
			'target_value' => $target_value,
			'method'       => $method,
			'error'        => $all_resolved ? null : sprintf(
				/* translators: 1: field name, 2: reference type */
				'Some IDs in %1$s could not be resolved via %2$s mapping',
				$field_name,
				$reference_type
			),
		);
	}

	/**
	 * Resolve multiple id_mapping fields at once.
	 *
	 * @since 1.3.0
	 *
	 * @param array $fields        Array of [field_name => source_value].
	 * @param array $field_configs Full field_capabilities config keyed by field name.
	 * @param array $context       Same as resolve().
	 * @return array [field_name => resolve_result]
	 */
	public static function resolve_batch( $fields, $field_configs, $context ) {
		$results = array();

		foreach ( $fields as $field_name => $source_value ) {
			$config = isset( $field_configs[ $field_name ] ) ? $field_configs[ $field_name ] : array();

			// Normalise v1 string config to v2 array format.
			if ( is_string( $config ) ) {
				$config = array( 'type' => $config );
			}

			$results[ $field_name ] = self::resolve( $field_name, $source_value, $config, $context );
		}

		return $results;
	}

	/**
	 * Infer reference_type from field name when not declared in config.
	 *
	 * This is the FALLBACK -- explicit config is always preferred.
	 *
	 * @since 1.3.0
	 *
	 * @param string $field_name   Meta key.
	 * @param mixed  $sample_value Optional sample value for runtime detection.
	 * @return string media|term|post|user|generic
	 */
	public static function infer_reference_type( $field_name, $sample_value = null ) {
		// Media patterns.
		if ( preg_match( '/thumbnail|image|gallery|attachment|media|video|photo|avatar/i', $field_name ) ) {
			return 'media';
		}

		// Term / taxonomy patterns.
		if ( preg_match( '/primary_category|_cat_ids|_tag_ids|_taxonomy|_term|_categories|_tags/i', $field_name ) ) {
			return 'term';
		}

		// Post parent patterns.
		if ( 'post_parent' === $field_name || preg_match( '/_parent$/i', $field_name ) ) {
			return 'post';
		}

		// User / author patterns.
		if ( 'post_author' === $field_name ) {
			return 'user';
		}

		return 'generic';
	}

	/**
	 * Extract id_mapping fields from field_capabilities config.
	 *
	 * Handles both v1 (string) and v2 (object) formats.
	 *
	 * @since 1.3.0
	 *
	 * @param array $field_capabilities Full field_capabilities (v1 or v2 format).
	 * @return array [field_name => field_config] only id_mapping fields.
	 */
	public static function extract_id_mapping_fields( $field_capabilities ) {
		$id_mapping_fields = array();

		if ( ! is_array( $field_capabilities ) ) {
			return $id_mapping_fields;
		}

		foreach ( $field_capabilities as $field_name => $config ) {
			// v2 object format: {"type": "id_mapping", "reference_type": "media", ...}
			if ( is_array( $config ) ) {
				$type = isset( $config['type'] ) ? $config['type'] : '';
				if ( 'id_mapping' === $type ) {
					$id_mapping_fields[ $field_name ] = $config;
				}
				continue;
			}

			// v1 string format: "id_mapping"
			if ( is_string( $config ) && 'id_mapping' === $config ) {
				$id_mapping_fields[ $field_name ] = array( 'type' => 'id_mapping' );
			}
		}

		return $id_mapping_fields;
	}

	/**
	 * Parse a source value into an array of integer IDs.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed  $value  Raw field value.
	 * @param string $format Value format: scalar, csv, serialized, json.
	 * @return int[] Array of positive integer IDs.
	 */
	private static function parse_value( $value, $format ) {
		switch ( $format ) {
			case 'csv':
				if ( ! is_string( $value ) || '' === $value ) {
					return array();
				}
				$parts = explode( ',', $value );
				return array_values( array_filter( array_map( 'absint', $parts ) ) );

			case 'serialized':
				$unserialized = maybe_unserialize( $value );
				if ( is_array( $unserialized ) ) {
					return array_values( array_filter( array_map( 'absint', $unserialized ) ) );
				}
				// Single serialized value.
				$id = absint( $unserialized );
				return $id > 0 ? array( $id ) : array();

			case 'json':
				if ( is_string( $value ) ) {
					$decoded = json_decode( $value, true );
				} else {
					$decoded = $value;
				}
				if ( is_array( $decoded ) ) {
					return array_values( array_filter( array_map( 'absint', $decoded ) ) );
				}
				$id = absint( $decoded );
				return $id > 0 ? array( $id ) : array();

			case 'scalar':
			default:
				$id = absint( $value );
				return $id > 0 ? array( $id ) : array();
		}
	}

	/**
	 * Reconstruct resolved IDs back to the original value format.
	 *
	 * @since 1.3.0
	 *
	 * @param int[]  $ids    Array of resolved integer IDs.
	 * @param string $format Value format: scalar, csv, serialized, json.
	 * @return mixed Reconstructed value in original format.
	 */
	private static function reconstruct_value( $ids, $format ) {
		switch ( $format ) {
			case 'csv':
				return implode( ',', $ids );

			case 'serialized':
				return maybe_serialize( $ids );

			case 'json':
				return wp_json_encode( $ids );

			case 'scalar':
			default:
				return ! empty( $ids ) ? $ids[0] : null;
		}
	}

	/**
	 * Resolve a single source ID to a target ID via the appropriate Mapping Service.
	 *
	 * Dispatch order for 'generic': post -> term -> media.
	 * Returns null when no mapping is found.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $source_id    Source entity ID.
	 * @param string $reference_type media|term|post|user|generic.
	 * @param array  $field_config  Field configuration (unused currently, reserved for future).
	 * @param array  $context       {relation_id: int, ...}.
	 * @return int|null Target entity ID or null.
	 */
	private static function resolve_single_id( $source_id, $reference_type, $field_config, $context ) {
		$relation_id = isset( $context['relation_id'] ) ? absint( $context['relation_id'] ) : 0;

		if ( 0 === $relation_id ) {
			return null;
		}

		switch ( $reference_type ) {
			case 'media':
				return Media_Mapping_Service::get_mapped_id( $relation_id, $source_id );

			case 'term':
				return Term_Mapping_Service::get_mapped_id( $relation_id, $source_id );

			case 'post':
				return Post_Mapping_Service::get_mapped_id( $relation_id, $source_id );

			case 'user':
				return User_Mapping_Service::get_mapped_id( $relation_id, $source_id );

			case 'generic':
				// Try post first (most common), then term, then media.
				$target_id = Post_Mapping_Service::get_mapped_id( $relation_id, $source_id );
				if ( null !== $target_id ) {
					return $target_id;
				}

				$target_id = Term_Mapping_Service::get_mapped_id( $relation_id, $source_id );
				if ( null !== $target_id ) {
					return $target_id;
				}

				$target_id = Media_Mapping_Service::get_mapped_id( $relation_id, $source_id );
				if ( null !== $target_id ) {
					return $target_id;
				}

				return null;

			default:
				return null;
		}
	}
}
