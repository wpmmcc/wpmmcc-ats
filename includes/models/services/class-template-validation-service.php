<?php
/**
 * Template Validation Service
 *
 * Validates model template layer: object fields, relationships, and discovery coverage.
 * Extracted from Simulation_Validator::do_validate_template() and enhanced with
 * relationship and discovery coverage checks.
 *
 * @package WPTSALL
 * @since   1.4.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template Validation Service Class
 *
 * Provides comprehensive template-layer validation:
 * 1. Object field completeness and correctness
 * 2. Relationship integrity (references, taxonomies)
 * 3. Discovery coverage (runtime vs model_objects)
 *
 * @since 1.4.0
 */
class Template_Validation_Service {

	/** Valid data_type enum values (canonical source, same as Simulation_Validator). */
	const VALID_DATA_TYPES = array(
		'text', 'html', 'numeric', 'id_ref', 'id_list', 'url',
		'datetime', 'boolean', 'enum', 'slug', 'serialized', 'json',
	);

	/**
	 * Template layer full validation.
	 *
	 * Runs all template checks and returns a unified result.
	 *
	 * @since 1.4.0
	 *
	 * @param int $model_id Model ID.
	 * @return array { valid: bool, errors: array[], warnings: array[] }
	 */
	public function validate_all( int $model_id ): array {
		$errors   = array();
		$warnings = array();

		// Pre-fetch objects and fields once to avoid redundant DB queries.
		$objects = Model_Object_Service::get_objects_for_model( $model_id );

		$fields_by_object = array();
		foreach ( $objects as $obj ) {
			$obj_id = (int) ( $obj['id'] ?? 0 );
			if ( $obj_id > 0 ) {
				$fields_by_object[ $obj_id ] = Model_Object_Service::get_fields_for_object( $obj_id );
			}
		}

		$field_errors = $this->validate_object_fields( $model_id, $objects, $fields_by_object );
		$errors       = array_merge( $errors, $field_errors );

		$relationship_warnings = $this->validate_relationships( $model_id, $objects, $fields_by_object );
		$warnings              = array_merge( $warnings, $relationship_warnings );

		$coverage_warnings = $this->validate_discovery_coverage( $model_id, $objects );
		$warnings          = array_merge( $warnings, $coverage_warnings );

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate object field completeness and correctness.
	 *
	 * Checks:
	 * - Model has at least one storage object
	 * - Each object has fields (warning if empty)
	 * - field_key is non-empty and <= 255 characters
	 * - data_type is in the valid enum (warning if unknown)
	 * - id_ref/id_list fields must have reference_type
	 * - reference_target resolves to an existing model object (warning if not)
	 *
	 * @since 1.4.0
	 *
	 * @param int        $model_id         Model ID.
	 * @param array|null $objects          Pre-fetched objects (optional, fetched if null).
	 * @param array|null $fields_by_object Pre-fetched fields keyed by object ID (optional, fetched if null).
	 * @return array Array of error entries (empty if all valid).
	 */
	public function validate_object_fields( int $model_id, ?array $objects = null, ?array $fields_by_object = null ): array {
		$errors = array();

		if ( null === $objects ) {
			$objects = Model_Object_Service::get_objects_for_model( $model_id );
		}

		if ( empty( $objects ) ) {
			$errors[] = array(
				'code'    => 'no_objects',
				'message' => 'Model has no storage objects (post_types, taxonomies, or custom_tables).',
				'context' => array( 'model_id' => $model_id ),
			);
			return $errors;
		}

		foreach ( $objects as $object ) {
			$object_id   = (int) ( $object['id'] ?? 0 );
			$object_name = $object['object_name'] ?? '';

			$fields = $fields_by_object[ $object_id ] ?? Model_Object_Service::get_fields_for_object( $object_id );

			if ( empty( $fields ) ) {
				// No fields is not an error but will be captured as a warning
				// in validate_relationships(). Skip field-level checks.
				continue;
			}

			foreach ( $fields as $field ) {
				$field_key  = $field['field_key'] ?? '';
				$data_type  = $field['data_type'] ?? null;
				$ref_type   = $field['reference_type'] ?? null;

				// Check: field_key non-empty.
				if ( '' === $field_key ) {
					$errors[] = array(
						'code'    => 'empty_field_key',
						'message' => "Empty field_key in object '{$object_name}'.",
						'context' => array(
							'object_id' => $object_id,
							'field_id'  => $field['id'] ?? 0,
						),
					);
					continue;
				}

				// Check: field_key length <= 255.
				if ( strlen( $field_key ) > 255 ) {
					$errors[] = array(
						'code'    => 'field_key_too_long',
						'message' => "Field key '{$field_key}' exceeds 255 characters.",
						'context' => array( 'object_id' => $object_id ),
					);
				}

				// Check: data_type in valid enum (warning-level, captured as error in
				// field completeness since unknown types may cause runtime failures).
				if ( null !== $data_type && '' !== $data_type && ! in_array( $data_type, self::VALID_DATA_TYPES, true ) ) {
					$errors[] = array(
						'code'    => 'unknown_data_type',
						'message' => "Field '{$field_key}' has unknown data_type '{$data_type}'.",
						'context' => array(
							'object_name' => $object_name,
							'valid_types' => self::VALID_DATA_TYPES,
						),
					);
				}

				// Check: id_ref/id_list must have reference_type.
				if ( in_array( $data_type, array( 'id_ref', 'id_list' ), true ) && empty( $ref_type ) ) {
					$errors[] = array(
						'code'    => 'missing_reference_type',
						'message' => "Field '{$field_key}' is {$data_type} but missing reference_type.",
						'context' => array( 'object_name' => $object_name ),
					);
				}

				// Check: special characters in field_key (warning).
				if ( preg_match( '/[^\w\-]/', $field_key ) ) {
					$errors[] = array(
						'code'    => 'field_key_special_chars',
						'message' => "Field key '{$field_key}' contains special characters.",
						'context' => array( 'object_name' => $object_name ),
					);
				}

				// Check: wp_ reserved prefix (warning — may conflict with WP core).
				if ( preg_match( '/^wp_/', $field_key ) && ! preg_match( '/^_wp_/', $field_key ) ) {
					$errors[] = array(
						'code'    => 'reserved_prefix',
						'message' => "Field key '{$field_key}' uses 'wp_' prefix which is reserved by WordPress.",
						'context' => array( 'object_name' => $object_name ),
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Validate object relationships.
	 *
	 * Checks:
	 * - Objects with no fields (warning)
	 * - reference_target fields point to existing model objects (warning if not)
	 * - Registered taxonomies for post_type objects are discovered as model objects (warning if not)
	 * - id_ref reference_targets have corresponding model_objects
	 *
	 * @since 1.4.0
	 *
	 * @param int        $model_id         Model ID.
	 * @param array|null $objects          Pre-fetched objects (optional, fetched if null).
	 * @param array|null $fields_by_object Pre-fetched fields keyed by object ID (optional, fetched if null).
	 * @return array Array of warning entries.
	 */
	public function validate_relationships( int $model_id, ?array $objects = null, ?array $fields_by_object = null ): array {
		$warnings = array();

		if ( null === $objects ) {
			$objects = Model_Object_Service::get_objects_for_model( $model_id );
		}

		if ( empty( $objects ) ) {
			return $warnings;
		}

		// Build lookup of all object_names in this model.
		$object_names = array();
		foreach ( $objects as $obj ) {
			$name = $obj['object_name'] ?? '';
			if ( '' !== $name ) {
				$object_names[] = $name;
			}
		}

		foreach ( $objects as $object ) {
			$object_id   = (int) ( $object['id'] ?? 0 );
			$object_name = $object['object_name'] ?? '';
			$object_type = $object['object_type'] ?? '';

			$fields = $fields_by_object[ $object_id ] ?? Model_Object_Service::get_fields_for_object( $object_id );

			// Warning: object with no fields.
			if ( empty( $fields ) ) {
				$warnings[] = array(
					'code'    => 'no_fields',
					'message' => "Object '{$object_name}' ({$object_type}) has no fields.",
					'context' => array( 'object_id' => $object_id ),
				);
				continue;
			}

			// Check reference_target resolution.
			foreach ( $fields as $field ) {
				$field_key  = $field['field_key'] ?? '';
				$ref_type   = $field['reference_type'] ?? null;
				$ref_target = $field['reference_target'] ?? null;

				if ( empty( $ref_target ) ) {
					continue;
				}

				// media and user references are external; no need to check model objects.
				if ( in_array( $ref_type, array( 'media', 'user' ), true ) ) {
					continue;
				}

				if ( ! in_array( $ref_target, $object_names, true ) ) {
					$warnings[] = array(
						'code'    => 'unresolved_reference_target',
						'message' => "Field '{$field_key}' references target '{$ref_target}' not found in model objects.",
						'context' => array(
							'object_name'    => $object_name,
							'reference_type' => $ref_type,
						),
					);
				}
			}

			// Check: for post_type objects, verify registered taxonomies are model objects.
			if ( 'post_type' === $object_type && function_exists( 'get_object_taxonomies' ) ) {
				$registered_taxonomies = get_object_taxonomies( $object_name );
				if ( is_array( $registered_taxonomies ) ) {
					foreach ( $registered_taxonomies as $taxonomy ) {
						// Only check public taxonomies.
						$tax_obj = get_taxonomy( $taxonomy );
						if ( ! $tax_obj || ! $tax_obj->public ) {
							continue;
						}
						if ( ! in_array( $taxonomy, $object_names, true ) ) {
							$warnings[] = array(
								'code'    => 'taxonomy_not_in_model',
								'message' => "Post type '{$object_name}' has registered taxonomy '{$taxonomy}' not found in model objects.",
								'context' => array(
									'object_name' => $object_name,
									'taxonomy'    => $taxonomy,
								),
							);
						}
					}
				}
			}
		}

		return $warnings;
	}

	/**
	 * Discovery coverage check.
	 *
	 * Compares WP runtime post_types and taxonomies against model_objects to
	 * find registered types that are not yet modeled. Only checks public types.
	 *
	 * @since 1.4.0
	 *
	 * @param int        $model_id Model ID.
	 * @param array|null $objects  Pre-fetched objects (optional, fetched if null).
	 * @return array Array of warning entries.
	 */
	public function validate_discovery_coverage( int $model_id, ?array $objects = null ): array {
		$warnings = array();

		if ( null === $objects ) {
			$objects = Model_Object_Service::get_objects_for_model( $model_id );
		}

		// Build sets of modeled post_types and taxonomies.
		$modeled_post_types = array();
		$modeled_taxonomies = array();

		foreach ( $objects as $obj ) {
			$name = $obj['object_name'] ?? '';
			$type = $obj['object_type'] ?? '';

			if ( 'post_type' === $type && '' !== $name ) {
				$modeled_post_types[] = $name;
			} elseif ( 'taxonomy' === $type && '' !== $name ) {
				$modeled_taxonomies[] = $name;
			}
		}

		// Check: are there WP runtime post_types registered by the model's plugins
		// that are not yet in the model? We check all public post_types and
		// see if any have taxonomies that ARE modeled (suggesting the post_type
		// belongs to the same model context).
		if ( function_exists( 'get_post_types' ) ) {
			$all_post_types = get_post_types( array( 'public' => true ), 'names' );

			foreach ( $all_post_types as $pt ) {
				// Skip built-in WP types -- they are always available and typically
				// handled separately.
				if ( in_array( $pt, array( 'attachment' ), true ) ) {
					continue;
				}

				if ( in_array( $pt, $modeled_post_types, true ) ) {
					continue;
				}

				// Check if this post_type shares a taxonomy with a modeled post_type.
				$pt_taxonomies = get_object_taxonomies( $pt );
				$shared        = array_intersect( $pt_taxonomies, $modeled_taxonomies );

				if ( ! empty( $shared ) ) {
					$warnings[] = array(
						'code'    => 'undiscovered_post_type',
						'message' => "Public post_type '{$pt}' shares taxonomies with modeled objects but is not in the model.",
						'context' => array(
							'post_type'         => $pt,
							'shared_taxonomies' => array_values( $shared ),
						),
					);
				}
			}
		}

		// Check: public taxonomies used by modeled post_types but not themselves modeled.
		if ( function_exists( 'get_taxonomies' ) ) {
			$all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

			foreach ( $all_taxonomies as $tax_obj ) {
				$tax_name = $tax_obj->name;

				if ( in_array( $tax_name, $modeled_taxonomies, true ) ) {
					continue;
				}

				// Check if this taxonomy is registered for any modeled post_type.
				$tax_post_types = (array) $tax_obj->object_type;
				$shared_pt      = array_intersect( $tax_post_types, $modeled_post_types );

				if ( ! empty( $shared_pt ) ) {
					$warnings[] = array(
						'code'    => 'undiscovered_taxonomy',
						'message' => "Public taxonomy '{$tax_name}' is registered for modeled post_types but not in the model.",
						'context' => array(
							'taxonomy'             => $tax_name,
							'registered_post_types' => array_values( $shared_pt ),
						),
					);
				}
			}
		}

		return $warnings;
	}

	/**
	 * Check if a meta key has actual data in the database.
	 *
	 * Useful for warning about template fields that may be stale or unused.
	 *
	 * @since 1.5.0
	 *
	 * @param string $meta_key Meta key to check.
	 * @param string $context  Object context: 'post', 'user', 'comment', 'term'.
	 * @return bool True if at least one row exists with this meta key.
	 */
	public function field_exists_in_database( string $meta_key, string $context = 'post' ): bool {
		global $wpdb;

		$table_map = array(
			'post'    => $wpdb->postmeta,
			'user'    => $wpdb->usermeta,
			'comment' => $wpdb->commentmeta,
			'term'    => $wpdb->termmeta,
		);

		$table = $table_map[ $context ] ?? null;
		if ( null === $table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE meta_key = %s LIMIT 1",
				$table,
				$meta_key
			)
		);

		return (int) $count > 0;
	}
}
