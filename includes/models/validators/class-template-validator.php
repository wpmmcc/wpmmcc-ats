<?php
/**
 * WPTSALL Template Validator Class
 *
 * Validates template structure integrity, field validity, and cross-table reference integrity
 *
 * @deprecated 1.4.0 Use WPTSALL\Models\Services\Template_Validation_Service instead.
 * @see \WPTSALL\Models\Services\Template_Validation_Service
 * @package WPTSALL
 * @since   0.2.0
 */

namespace WPTSALL\Models\Validators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template Validator Class
 *
 * @deprecated 1.4.0 Use WPTSALL\Models\Services\Template_Validation_Service instead.
 * @see \WPTSALL\Models\Services\Template_Validation_Service
 */
class Template_Validator {

	/**
	 * Validate template
	 *
	 * @deprecated 1.4.0 Use Template_Validation_Service::validate_all() instead.
	 * @param array $template Template data
	 * @return array Validation result array( 'valid' => bool, 'errors' => array, 'warnings' => array )
	 */
	public static function validate_template( $template ) {
		_doing_it_wrong( __METHOD__, 'Use WPTSALL\\Models\\Services\\Template_Validation_Service::validate_all() instead.', '1.4.0' );
		$errors   = array();
		$warnings = array();

		// 1. Basic structure validation
		$struct_errors = self::validate_structure( $template );
		$errors        = array_merge( $errors, $struct_errors );

		// 2. URL type validation
		if ( ! empty( $template['url_types'] ) && is_array( $template['url_types'] ) ) {
			foreach ( $template['url_types'] as $index => $url_type ) {
				$prefix      = "url_types[{$index}]";
				$url_errors  = self::validate_url_type( $url_type, $prefix );
				$errors      = array_merge( $errors, $url_errors );
			}
		}

		// 3. Cross-table reference integrity validation
		$ref_result = self::validate_cross_references( $template );
		$errors     = array_merge( $errors, $ref_result['errors'] );
		$warnings   = array_merge( $warnings, $ref_result['warnings'] );

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate basic structure
	 *
	 * @param array $template Template data
	 * @return array Error list
	 */
	private static function validate_structure( $template ) {
		$errors = array();

		// Required fields
		$required_fields = array( 'plugin', 'url_types' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $template[ $field ] ) ) {
				/* translators: %s: field name */
				$errors[] = sprintf( __( 'Missing required field: %s', 'wpmmcc-ats' ), $field );
			}
		}

		// Validate plugin type
		if ( isset( $template['plugin'] ) && ! is_string( $template['plugin'] ) ) {
			$errors[] = __( 'The plugin field must be a string', 'wpmmcc-ats' );
		}

		// Validate url_types type
		if ( isset( $template['url_types'] ) && ! is_array( $template['url_types'] ) ) {
			$errors[] = __( 'The url_types field must be an array', 'wpmmcc-ats' );
		}

		// Validate url_types is not empty
		if ( isset( $template['url_types'] ) && is_array( $template['url_types'] ) && empty( $template['url_types'] ) ) {
			$errors[] = __( 'url_types cannot be an empty array', 'wpmmcc-ats' );
		}

		return $errors;
	}

	/**
	 * Validate URL type
	 *
	 * @param array  $url_type URL type data
	 * @param string $prefix   Error message prefix
	 * @return array Error list
	 */
	private static function validate_url_type( $url_type, $prefix = '' ) {
		$errors = array();

		// Required fields
		$required = array( 'type_id', 'object_type' );
		foreach ( $required as $field ) {
			if ( empty( $url_type[ $field ] ) ) {
				/* translators: 1: field prefix, 2: field name */
				$errors[] = sprintf( __( '%1$s: Missing required field %2$s', 'wpmmcc-ats' ), $prefix, $field );
			}
		}

		// Validate object_type
		if ( ! empty( $url_type['object_type'] ) ) {
			$valid_types = array( 'post_type', 'taxonomy', 'home', 'search', 'author', 'date' );
			if ( ! in_array( $url_type['object_type'], $valid_types, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: field prefix, 2: invalid value, 3: valid values */
					__( '%1$s: Invalid object_type value "%2$s", valid values: %3$s', 'wpmmcc-ats' ),
					$prefix,
					$url_type['object_type'],
					implode( ', ', $valid_types )
				);
			}

			// post_type must have subtype
			if ( 'post_type' === $url_type['object_type'] && empty( $url_type['subtype'] ) ) {
				/* translators: %s: field prefix */
				$errors[] = sprintf( __( '%s: subtype is required when object_type is post_type', 'wpmmcc-ats' ), $prefix );
			}

			// taxonomy must have subtype
			if ( 'taxonomy' === $url_type['object_type'] && empty( $url_type['subtype'] ) ) {
				/* translators: %s: field prefix */
				$errors[] = sprintf( __( '%s: subtype is required when object_type is taxonomy', 'wpmmcc-ats' ), $prefix );
			}
		}

		// Validate data_retrieval
		if ( ! empty( $url_type['data_retrieval'] ) ) {
			$data_errors = self::validate_data_retrieval( $url_type['data_retrieval'], $prefix );
			$errors      = array_merge( $errors, $data_errors );
		}

		return $errors;
	}

	/**
	 * Validate data retrieval configuration
	 *
	 * @param array  $data_retrieval Data retrieval config
	 * @param string $prefix         Error message prefix
	 * @return array Error list
	 */
	private static function validate_data_retrieval( $data_retrieval, $prefix = '' ) {
		$errors = array();
		$prefix = $prefix . '.data_retrieval';

		// method is required
		if ( empty( $data_retrieval['method'] ) ) {
			/* translators: %s: field prefix */
			$errors[] = sprintf( __( '%s: Missing method field', 'wpmmcc-ats' ), $prefix );
		} else {
			// Validate method value
			$valid_methods = array( 'wp_post', 'wp_query', 'wp_term', 'option', 'direct_sql' );
			if ( ! in_array( $data_retrieval['method'], $valid_methods, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: field prefix, 2: invalid value, 3: valid values */
					__( '%1$s: Invalid method value "%2$s", valid values: %3$s', 'wpmmcc-ats' ),
					$prefix,
					$data_retrieval['method'],
					implode( ', ', $valid_methods )
				);
			}
		}

		// Validate fields_map
		if ( ! empty( $data_retrieval['fields_map'] ) ) {
			$fields_errors = self::validate_fields_map( $data_retrieval['fields_map'], $prefix );
			$errors        = array_merge( $errors, $fields_errors );
		}

		return $errors;
	}

	/**
	 * Validate field mappings
	 *
	 * @param array  $fields_map Field mapping config
	 * @param string $prefix     Error message prefix
	 * @return array Error list
	 */
	private static function validate_fields_map( $fields_map, $prefix = '' ) {
		$errors = array();
		$prefix = $prefix . '.fields_map';

		// Validate core fields
		if ( ! empty( $fields_map['core'] ) ) {
			if ( empty( $fields_map['core']['fields'] ) || ! is_array( $fields_map['core']['fields'] ) ) {
				/* translators: %s: field prefix */
				$errors[] = sprintf( __( '%s.core: Missing fields array', 'wpmmcc-ats' ), $prefix );
			} else {
				$core_errors = self::validate_fields( $fields_map['core']['fields'], $prefix . '.core' );
				$errors      = array_merge( $errors, $core_errors );
			}
		}

		// Validate meta fields
		if ( ! empty( $fields_map['meta']['fields'] ) ) {
			$meta_errors = self::validate_fields( $fields_map['meta']['fields'], $prefix . '.meta' );
			$errors      = array_merge( $errors, $meta_errors );
		}

		return $errors;
	}

	/**
	 * Validate field list
	 *
	 * @param array  $fields Field list
	 * @param string $prefix Error message prefix
	 * @return array Error list
	 */
	private static function validate_fields( $fields, $prefix = '' ) {
		$errors = array();

		foreach ( $fields as $index => $field ) {
			$field_prefix = $prefix . ".fields[{$index}]";

			// Required fields
			if ( empty( $field['name'] ) ) {
				/* translators: %s: field prefix */
				$errors[] = sprintf( __( '%s: Missing name field', 'wpmmcc-ats' ), $field_prefix );
			}

			if ( empty( $field['type'] ) ) {
				/* translators: %s: field prefix */
				$errors[] = sprintf( __( '%s: Missing type field', 'wpmmcc-ats' ), $field_prefix );
			} else {
				// Validate data type
				$valid_types = array( 'bigint', 'int', 'varchar', 'text', 'longtext', 'decimal', 'float', 'date', 'datetime', 'tinyint', 'mediumtext' );
				if ( ! in_array( $field['type'], $valid_types, true ) ) {
					$errors[] = sprintf(
						/* translators: 1: field prefix, 2: invalid type */
						__( '%1$s.type: Invalid data type "%2$s"', 'wpmmcc-ats' ),
						$field_prefix,
						$field['type']
					);
				}
			}

			// Validate reference configuration
			if ( ! empty( $field['reference'] ) ) {
				$ref_errors = self::validate_reference( $field['reference'], $field_prefix );
				$errors     = array_merge( $errors, $ref_errors );
			}
		}

		return $errors;
	}

	/**
	 * Validate reference configuration
	 *
	 * @param array  $reference Reference config
	 * @param string $prefix    Error message prefix
	 * @return array Error list
	 */
	private static function validate_reference( $reference, $prefix = '' ) {
		$errors = array();
		$prefix = $prefix . '.reference';

		// type is required
		if ( empty( $reference['type'] ) ) {
			/* translators: %s: field prefix */
			$errors[] = sprintf( __( '%s: Missing type field', 'wpmmcc-ats' ), $prefix );
		} else {
			// Validate type value
			$valid_types = array( 'post_type', 'taxonomy', 'user', 'attachment', 'option', 'term' );
			if ( ! in_array( $reference['type'], $valid_types, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: field prefix, 2: invalid type */
					__( '%1$s.type: Invalid reference type "%2$s"', 'wpmmcc-ats' ),
					$prefix,
					$reference['type']
				);
			}

			// post_type reference must have subtype
			if ( 'post_type' === $reference['type'] && empty( $reference['subtype'] ) ) {
				/* translators: %s: field prefix */
				$errors[] = sprintf( __( '%s: post_type reference must specify subtype', 'wpmmcc-ats' ), $prefix );
			}

			// taxonomy reference must have subtype
			if ( 'taxonomy' === $reference['type'] && empty( $reference['subtype'] ) ) {
				/* translators: %s: field prefix */
				$errors[] = sprintf( __( '%s: taxonomy reference must specify subtype', 'wpmmcc-ats' ), $prefix );
			}
		}

		// field is required
		if ( empty( $reference['field'] ) ) {
			/* translators: %s: field prefix */
			$errors[] = sprintf( __( '%s: Missing field field', 'wpmmcc-ats' ), $prefix );
		}

		// Validate cascade operation
		if ( ! empty( $reference['cascade'] ) ) {
			$valid_cascades = array( 'update_mapping', 'copy_file', 'keep_source', 'set_null', 'skip', 'sync_taxonomy' );
			if ( ! in_array( $reference['cascade'], $valid_cascades, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: field prefix, 2: invalid cascade value */
					__( '%1$s.cascade: Invalid cascade operation "%2$s"', 'wpmmcc-ats' ),
					$prefix,
					$reference['cascade']
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate cross-table reference integrity
	 *
	 * @param array $template Template data
	 * @return array Array containing errors and warnings
	 */
	private static function validate_cross_references( $template ) {
		$errors   = array();
		$warnings = array();

		// Extract all references
		$all_references = self::extract_all_references( $template );

		// Extract all defined object types
		$defined_objects = self::extract_defined_objects( $template );

		// Validate each reference
		foreach ( $all_references as $ref ) {
			if ( 'post_type' === $ref['type'] && ! empty( $ref['subtype'] ) ) {
				// Check if referenced post_type is defined in template
				if ( ! in_array( $ref['subtype'], $defined_objects['post_types'], true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: field name, 2: post type */
						__( 'Field "%1$s" references a post_type not defined in template: %2$s', 'wpmmcc-ats' ),
						$ref['field_name'],
						$ref['subtype']
					);
				}
			} elseif ( 'taxonomy' === $ref['type'] && ! empty( $ref['subtype'] ) ) {
				// Check if referenced taxonomy is defined in template
				if ( ! in_array( $ref['subtype'], $defined_objects['taxonomies'], true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: field name, 2: taxonomy */
						__( 'Field "%1$s" references a taxonomy not defined in template: %2$s', 'wpmmcc-ats' ),
						$ref['field_name'],
						$ref['subtype']
					);
				}
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Extract all references
	 *
	 * @param array $template Template data
	 * @return array Reference list
	 */
	private static function extract_all_references( $template ) {
		$references = array();

		if ( empty( $template['url_types'] ) || ! is_array( $template['url_types'] ) ) {
			return $references;
		}

		foreach ( $template['url_types'] as $url_type ) {
			if ( empty( $url_type['data_retrieval']['fields_map'] ) ) {
				continue;
			}

			$fields_map = $url_type['data_retrieval']['fields_map'];

			// Extract core field references
			if ( ! empty( $fields_map['core']['fields'] ) && is_array( $fields_map['core']['fields'] ) ) {
				foreach ( $fields_map['core']['fields'] as $field ) {
					if ( ! empty( $field['reference'] ) && ! empty( $field['name'] ) ) {
						$references[] = array_merge(
							$field['reference'],
							array( 'field_name' => $field['name'] )
						);
					}
				}
			}

			// Extract meta field references
			if ( ! empty( $fields_map['meta']['fields'] ) && is_array( $fields_map['meta']['fields'] ) ) {
				foreach ( $fields_map['meta']['fields'] as $field ) {
					if ( ! empty( $field['reference'] ) && ! empty( $field['name'] ) ) {
						$references[] = array_merge(
							$field['reference'],
							array( 'field_name' => $field['name'] )
						);
					}
				}
			}
		}

		return $references;
	}

	/**
	 * Extract all object types defined in template
	 *
	 * @param array $template Template data
	 * @return array Array containing post_types and taxonomies
	 */
	private static function extract_defined_objects( $template ) {
		$post_types  = array();
		$taxonomies  = array();

		if ( empty( $template['url_types'] ) || ! is_array( $template['url_types'] ) ) {
			return array(
				'post_types'  => $post_types,
				'taxonomies'  => $taxonomies,
			);
		}

		foreach ( $template['url_types'] as $url_type ) {
			if ( 'post_type' === $url_type['object_type'] && ! empty( $url_type['subtype'] ) ) {
				$post_types[] = $url_type['subtype'];
			} elseif ( 'taxonomy' === $url_type['object_type'] && ! empty( $url_type['subtype'] ) ) {
				$taxonomies[] = $url_type['subtype'];
			}
		}

		return array(
			'post_types'  => array_unique( $post_types ),
			'taxonomies'  => array_unique( $taxonomies ),
		);
	}

	/**
	 * Quick validate (JSON format and basic structure only)
	 *
	 * @deprecated 1.4.0 Use Template_Validation_Service instead.
	 * @param string $json JSON string
	 * @return array Validation result
	 */
	public static function quick_validate( $json ) {
		_doing_it_wrong( __METHOD__, 'Use WPTSALL\\Models\\Services\\Template_Validation_Service instead.', '1.4.0' );
		// JSON format validation
		$decoded = json_decode( $json, true );

		if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'valid'  => false,
				'errors' => array( __( 'JSON format error: ', 'wpmmcc-ats' ) . json_last_error_msg() ),
			);
		}

		// Basic structure validation
		return array(
			'valid'  => true,
			'data'   => $decoded,
			'errors' => array(),
		);
	}
}
