<?php
/**
 * WPTSALL Translation Rule Validator
 *
 * Validates translation rule completeness and correctness
 *
 * @deprecated 1.4.0 Use WPTSALL\Models\Services\Rule_Validation_Service instead.
 * @see \WPTSALL\Models\Services\Rule_Validation_Service
 * @package WPTSALL
 * @since 0.4.0
 */

namespace WPTSALL\Models\Validators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation Rule Validator Class
 *
 * @deprecated 1.4.0 Use WPTSALL\Models\Services\Rule_Validation_Service instead.
 * @see \WPTSALL\Models\Services\Rule_Validation_Service
 */
class Translation_Rule_Validator {

	/**
	 * Validation errors
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Valid url_type list
	 *
	 * @var array
	 */
	private static $valid_url_types = array(
		'single',
		'archive',
		'taxonomy',
		'author',
		'date',
		'search',
		'home',
		'page',
		'attachment',
		'embed',
		'feed',
		'endpoint',
	);

	/**
	 * Valid data_type list
	 *
	 * @var array
	 */
	private static $valid_data_types = array(
		'post',
		'term',
		'user',
		'comment',
		'option',
		'custom_table',
	);

	/**
	 * Validate rule
	 *
	 * @deprecated 1.4.0 Use Rule_Validation_Service::validate_all() instead.
	 * @param array $rule     Rule data.
	 * @param int   $model_id Model ID.
	 * @return array Validation result ['valid' => bool, 'errors' => array].
	 */
	public function validate( $rule, $model_id ) {
		_doing_it_wrong( __METHOD__, 'Use WPTSALL\\Models\\Services\\Rule_Validation_Service::validate_all() instead.', '1.4.0' );
		$this->errors = array();

		// Frontend URL validation
		$this->validate_frontend( $rule );

		// Data source validation
		$this->validate_data_source( $rule );

		// Field validation
		$this->validate_fields( $rule );

		// Backend URL validation
		$this->validate_backend( $rule );

		// URL pattern uniqueness validation
		$this->validate_uniqueness( $rule, $model_id );

		// Only error type blocks validation; warning type is informational
		$has_errors = $this->has_blocking_errors();

		return array(
			'valid'    => ! $has_errors,
			'errors'   => $this->errors,
			'warnings' => $this->get_warnings_only(),
		);
	}

	/**
	 * Check for blocking errors (excluding warnings)
	 *
	 * @return bool
	 */
	private function has_blocking_errors() {
		foreach ( $this->errors as $field => $field_errors ) {
			foreach ( $field_errors as $err ) {
				if ( ( $err['type'] ?? 'error' ) === 'error' ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get warnings only
	 *
	 * @return array
	 */
	private function get_warnings_only() {
		$warnings = array();
		foreach ( $this->errors as $field => $field_errors ) {
			foreach ( $field_errors as $err ) {
				if ( ( $err['type'] ?? 'error' ) === 'warning' ) {
					if ( ! isset( $warnings[ $field ] ) ) {
						$warnings[ $field ] = array();
					}
					$warnings[ $field ][] = $err;
				}
			}
		}
		return $warnings;
	}

	/**
	 * Validate frontend URL section
	 *
	 * @param array $rule Rule data.
	 */
	private function validate_frontend( $rule ) {
		$url_pattern = $rule['url_pattern'] ?? '';
		$url_type    = $rule['url_type'] ?? '';

		// url_pattern is required
		if ( empty( $url_pattern ) ) {
			$this->add_error( 'url_pattern', 'URL pattern cannot be empty' );
		} else {
			// Supports two semantics:
			// 1) Pretty permalink pattern: starts with / (backward compatible)
			// 2) Canonical query signature: starts with ? (permalink-structure independent)
			if ( strpos( $url_pattern, '/' ) !== 0 && strpos( $url_pattern, '?' ) !== 0 ) {
				$this->add_error( 'url_pattern', 'URL pattern must start with / or ?' );
			}

			// Check for invalid characters
			if ( preg_match( '/[<>"\'\s]/', $url_pattern ) ) {
				$this->add_error( 'url_pattern', 'URL pattern contains invalid characters' );
			}

			// Non archive/home/search types must contain placeholders
			$no_placeholder_types = array( 'archive', 'home', 'search', 'feed' );
			if ( ! in_array( $url_type, $no_placeholder_types, true ) ) {
				if ( strpos( $url_pattern, '{slug}' ) === false &&
					strpos( $url_pattern, '{id}' ) === false &&
					strpos( $url_pattern, '{name}' ) === false &&
					strpos( $url_pattern, '{term}' ) === false ) {
					$this->add_error(
						'url_pattern',
						'URL pattern must contain {slug}, {id}, {term}, or {name} placeholder'
					);
				}
			}
		}

		// url_type validation
		if ( empty( $url_type ) ) {
			$this->add_error( 'url_type', 'Please select a page type' );
		} elseif ( ! in_array( $url_type, self::$valid_url_types, true ) ) {
			$this->add_error( 'url_type', 'Invalid page type: ' . esc_html( $url_type ) );
		}
	}

	/**
	 * Validate data source section
	 *
	 * @param array $rule Rule data.
	 */
	private function validate_data_source( $rule ) {
		$data_type   = $rule['data_type'] ?? '';
		$object_name = $rule['object_name'] ?? '';

		// data_type validation
		if ( empty( $data_type ) ) {
			$this->add_error( 'data_type', 'Please select a data type' );
		} elseif ( ! in_array( $data_type, self::$valid_data_types, true ) ) {
			$this->add_error( 'data_type', 'Invalid data type: ' . esc_html( $data_type ) );
		}

		// object_name validation
		if ( empty( $object_name ) ) {
			$this->add_error( 'object_name', 'Please specify the object name (post_type or taxonomy)' );
		} else {
			// Check if object exists
			if ( $data_type === 'post' ) {
				if ( ! post_type_exists( $object_name ) ) {
					$this->add_error(
						'object_name',
						sprintf(
							/* translators: %s: post_type name */
							"post_type '%s' does not exist; please check if the plugin is activated",
							esc_html( $object_name )
						)
					);
				}
			} elseif ( $data_type === 'term' ) {
				if ( ! taxonomy_exists( $object_name ) ) {
					$this->add_error(
						'object_name',
						sprintf(
							/* translators: %s: taxonomy name */
							"taxonomy '%s' does not exist; please check if the plugin is activated",
							esc_html( $object_name )
						)
					);
				}
			}
		}
	}

	/**
	 * Validate fields section (v0.8.0 unified field_capabilities format)
	 *
	 * @param array $rule Rule data.
	 */
	private function validate_fields( $rule ) {
		$field_capabilities = $rule['field_capabilities'] ?? array();

		// Handle JSON strings
		if ( is_string( $field_capabilities ) ) {
			$field_capabilities = json_decode( $field_capabilities, true ) ?: array();
		}

		// Valid field types (includes legacy names for backward compatibility)
		$valid_types = array( 'translate', 'sync', 'id_mapping', 'mapping', 'compute', 'skip', 'no_sync' );

		// Validate each field configuration
		$has_translate_field = false;
		$data_type           = $rule['data_type'] ?? '';
		$object_name         = $rule['object_name'] ?? '';
		$valid_fields        = array();

		if ( $data_type && $object_name ) {
			$valid_fields = $this->get_valid_fields( $data_type, $object_name );
		}

		foreach ( $field_capabilities as $field_name => $config ) {
			// Validate field name
			if ( empty( $field_name ) || ! is_string( $field_name ) ) {
				$this->add_error(
					'field_capabilities',
					'Invalid field name'
				);
				continue;
			}

			// Validate config: accept both v1 string ("translate") and v2 object ({"type": "translate", ...})
			if ( is_string( $config ) ) {
				// v2 mixed format: plain string value like "translate", "sync", etc.
				$type = $config;
			} elseif ( is_array( $config ) ) {
				// v1/v2 object format: {"type": "translate", ...}
				$type = $config['type'] ?? '';
			} else {
				$this->add_error(
					'field_capabilities',
					sprintf(
						/* translators: %s: field name */
						"Invalid configuration format for field '%s'",
						esc_html( $field_name )
					)
				);
				continue;
			}

			// Validate type
			if ( ! empty( $type ) && ! in_array( $type, $valid_types, true ) ) {
				$this->add_error(
					'field_capabilities',
					sprintf(
						/* translators: 1: field name, 2: type value */
						"Invalid type '%2\$s' for field '%1\$s'",
						esc_html( $field_name ),
						esc_html( $type )
					)
				);
			}

			// Check for translate fields (string values are considered enabled)
			$is_enabled = is_string( $config ) || ! empty( $config['enabled'] );
			if ( 'translate' === $type && $is_enabled ) {
				$has_translate_field = true;
			}

			// Validate field existence (warning level)
			if ( ! empty( $valid_fields ) && ! $this->is_valid_field( $field_name, $valid_fields ) ) {
				$this->add_warning(
					'field_capabilities',
					sprintf(
						/* translators: %s: field name */
						"Field '%s' may not exist; please verify the field name is correct",
						esc_html( $field_name )
					)
				);
			}
		}

		// At least one enabled translate field is required
		if ( ! $has_translate_field && ! empty( $field_capabilities ) ) {
			$this->add_warning( 'field_capabilities', 'It is recommended to specify at least one translate field' );
		}
	}

	/**
	 * Validate backend URL section
	 *
	 * @param array $rule Rule data.
	 */
	private function validate_backend( $rule ) {
		$backend_edit = $rule['backend_edit'] ?? '';
		$backend_list = $rule['backend_list'] ?? '';

		// edit_pattern validation
		if ( ! empty( $backend_edit ) ) {
			// Must contain /wp-admin/
			if ( strpos( $backend_edit, '/wp-admin/' ) === false ) {
				$this->add_error( 'backend_edit', 'Backend edit URL must contain /wp-admin/' );
			}

			// Must contain {id} placeholder
			if ( strpos( $backend_edit, '{id}' ) === false ) {
				$this->add_error( 'backend_edit', 'Backend edit URL must contain {id} placeholder' );
			}
		}

		// list_pattern validation
		if ( ! empty( $backend_list ) ) {
			if ( strpos( $backend_list, '/wp-admin/' ) === false ) {
				$this->add_error( 'backend_list', 'Backend list URL must contain /wp-admin/' );
			}
		}
	}

	/**
	 * Validate URL pattern uniqueness
	 *
	 * @param array $rule     Rule data.
	 * @param int   $model_id Model ID.
	 */
	private function validate_uniqueness( $rule, $model_id ) {
		global $wpdb;

		$url_pattern = $rule['url_pattern'] ?? '';
		$rule_id     = $rule['id'] ?? 0;

		if ( empty( $url_pattern ) ) {
			return;
		}

		$table = wptsall_table( 'translation_rules' );

		// Check for duplicates within the same model
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name FROM %i WHERE model_id = %d AND url_pattern = %s AND id != %d',
				$table,
				$model_id,
				$url_pattern,
				$rule_id
			)
		);

		if ( $existing ) {
			$name = $existing->name ? $existing->name : "#{$existing->id}";
			$this->add_error(
				'url_pattern',
				sprintf(
					/* translators: %s: rule name or ID */
					'This URL pattern already exists in rule %s',
					esc_html( $name )
				)
			);
		}
	}

	/**
	 * Get valid field list
	 *
	 * @param string $data_type   Data type.
	 * @param string $object_name Object name.
	 * @return array
	 */
	private function get_valid_fields( $data_type, $object_name ) {
		$fields = array(
			'main' => array(),
			'meta' => array(),
		);

		if ( $data_type === 'post' ) {
			// wp_posts table fields
			$fields['main'] = array(
				'ID',
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_excerpt',
				'post_status',
				'comment_status',
				'ping_status',
				'post_password',
				'post_name',
				'to_ping',
				'pinged',
				'post_modified',
				'post_modified_gmt',
				'post_content_filtered',
				'post_parent',
				'guid',
				'menu_order',
				'post_type',
				'post_mime_type',
				'comment_count',
			);

			// Get used meta_keys
			if ( post_type_exists( $object_name ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_keys = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT pm.meta_key
						FROM %i pm
						JOIN %i p ON pm.post_id = p.ID
						WHERE p.post_type = %s
						LIMIT 100',
						$wpdb->postmeta,
						$wpdb->posts,
						$object_name
					)
				);
				$fields['meta'] = $meta_keys ? $meta_keys : array();
			}
		} elseif ( $data_type === 'term' ) {
			// wp_terms table fields
			$fields['main'] = array(
				'term_id',
				'name',
				'slug',
				'term_group',
				'description',
				'parent',
				'count',
			);

			// Get used meta_keys
			if ( taxonomy_exists( $object_name ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_keys = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT tm.meta_key
						FROM %i tm
						JOIN %i tt ON tm.term_id = tt.term_id
						WHERE tt.taxonomy = %s
						LIMIT 100',
						$wpdb->termmeta,
						$wpdb->term_taxonomy,
						$object_name
					)
				);
				$fields['meta'] = $meta_keys ? $meta_keys : array();
			}
		} elseif ( $data_type === 'user' ) {
			$fields['main'] = array(
				'ID',
				'user_login',
				'user_nicename',
				'user_email',
				'user_url',
				'user_registered',
				'display_name',
			);
		}

		return $fields;
	}

	/**
	 * Check if field is valid
	 *
	 * @param string $field        Field name.
	 * @param array  $valid_fields Valid field list.
	 * @return bool
	 */
	private function is_valid_field( $field, $valid_fields ) {
		// Main table fields
		if ( in_array( $field, $valid_fields['main'], true ) ) {
			return true;
		}

		// Meta fields
		if ( in_array( $field, $valid_fields['meta'], true ) ) {
			return true;
		}

		// Fields starting with _ are usually meta fields; allow them (may be new fields)
		if ( strpos( $field, '_' ) === 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Add error
	 *
	 * @param string $field   Field name.
	 * @param string $message Error message.
	 */
	private function add_error( $field, $message ) {
		if ( ! isset( $this->errors[ $field ] ) ) {
			$this->errors[ $field ] = array();
		}
		$this->errors[ $field ][] = array(
			'type'    => 'error',
			'message' => $message,
		);
	}

	/**
	 * Add warning
	 *
	 * @param string $field   Field name.
	 * @param string $message Warning message.
	 */
	private function add_warning( $field, $message ) {
		if ( ! isset( $this->errors[ $field ] ) ) {
			$this->errors[ $field ] = array();
		}
		$this->errors[ $field ][] = array(
			'type'    => 'warning',
			'message' => $message,
		);
	}

	/**
	 * Get valid URL type list
	 *
	 * @return array
	 */
	public static function get_url_types() {
		return array(
			'single'     => 'Single content page',
			'archive'    => 'Archive/list page',
			'taxonomy'   => 'Taxonomy/tag archive',
			'author'     => 'Author archive page',
			'date'       => 'Date archive page',
			'search'     => 'Search results page',
			'home'       => 'Home/blog page',
			'page'       => 'Static page',
			'attachment' => 'Attachment page',
			'embed'      => 'Embed page',
			'feed'       => 'RSS/Feed',
			'endpoint'   => 'Custom endpoint',
		);
	}

	/**
	 * Get valid data type list
	 *
	 * @return array
	 */
	public static function get_data_types() {
		return array(
			'post'         => 'Posts/custom types (wp_posts)',
			'term'         => 'Terms/tags (wp_terms)',
			'user'         => 'Users (wp_users)',
			'comment'      => 'Comments (wp_comments)',
			'option'       => 'Options/settings (wp_options)',
			'custom_table' => 'Plugin custom table',
		);
	}
}
