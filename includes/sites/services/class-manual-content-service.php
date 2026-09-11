<?php
/**
 * Manual Content Service
 *
 * Core service for free-version manual translation.
 * Produces data identical to Sync_Executor::sync_post_to_virtual_site().
 *
 * @package WPTSALL
 * @since 1.0.0
 */

namespace WPTSALL\Sites\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual_Content_Service class
 *
 * Provides manual translation editor data retrieval, saving, and status checking.
 * All methods are static, consistent with other service classes.
 *
 * @since 1.0.0
 */
class Manual_Content_Service {

	/**
	 * wp_posts columns that must go through wp_insert_post / wp_update_post.
	 *
	 * @since 2.1.0
	 * @return string[]
	 */
	public static function standard_post_columns() {
		return array(
			'ID',
			'post_title',
			'post_content',
			'post_excerpt',
			'post_status',
			'post_name',
			'post_date',
			'post_date_gmt',
			'post_author',
			'post_parent',
			'menu_order',
			'comment_status',
			'ping_status',
			'post_password',
			'post_type',
		);
	}

	/**
	 * Whether a field is a native wp_posts column.
	 *
	 * @since 2.1.0
	 * @param string $field_name Field key.
	 * @return bool
	 */
	public static function is_standard_post_column( $field_name ) {
		return in_array( (string) $field_name, self::standard_post_columns(), true );
	}

	/**
	 * Split translate field values into post columns vs post meta.
	 *
	 * Plugin shapes such as `_elementor_data` or Woo `_product_attributes`
	 * must be written with update_post_meta, not wp_insert_post.
	 *
	 * @since 2.1.0
	 * @param array $translate_fields Field keys marked translate.
	 * @param array $translated_data  User / client values keyed by field.
	 * @return array{columns: array, meta: array}
	 */
	public static function partition_translated_fields( array $translate_fields, array $translated_data ) {
		$columns = array();
		$meta    = array();
		foreach ( $translate_fields as $field_name ) {
			$field_name = (string) $field_name;
			if ( ! array_key_exists( $field_name, $translated_data ) ) {
				continue;
			}
			if ( self::is_standard_post_column( $field_name ) ) {
				$columns[ $field_name ] = $translated_data[ $field_name ];
			} else {
				$meta[ $field_name ] = $translated_data[ $field_name ];
			}
		}
		return array(
			'columns' => $columns,
			'meta'    => $meta,
		);
	}

	/**
	 * Persist translate-capable meta onto a target post (virtual copy or WP subsite).
	 *
	 * @since 2.1.0
	 * @param int   $target_id          Target post id (current blog).
	 * @param array $meta               Meta key => value.
	 * @param array $field_capabilities Optional capability map for format hints.
	 * @return int Number of keys written.
	 */
	public static function persist_translate_meta( $target_id, array $meta, array $field_capabilities = array() ) {
		$target_id = (int) $target_id;
		if ( $target_id <= 0 || empty( $meta ) ) {
			return 0;
		}
		$written = 0;
		foreach ( $meta as $meta_key => $meta_value ) {
			$meta_key = (string) $meta_key;
			if ( '' === $meta_key || 0 === strpos( $meta_key, '_wptsall_' ) ) {
				continue;
			}
			$format = '';
			if ( isset( $field_capabilities[ $meta_key ] ) && is_array( $field_capabilities[ $meta_key ] ) ) {
				$format = (string) ( $field_capabilities[ $meta_key ]['content_format'] ?? '' );
			}
			$value = self::sanitize_translate_meta_value( $meta_key, $meta_value, $format );
			update_post_meta( $target_id, $meta_key, $value );
			if ( class_exists( '\\WPTSALL\\Sync\\Services\\Field_Ownership_Service' ) ) {
				\WPTSALL\Sync\Services\Field_Ownership_Service::claim_manual(
					'post',
					$target_id,
					'meta:' . $meta_key,
					is_scalar( $value ) ? (string) $value : wp_json_encode( $value )
				);
			}
			++$written;
		}
		return $written;
	}

	/**
	 * Persist an internal WPTSALL marker without third-party post-meta interception.
	 *
	 * Some content plugins provide their own metadata stores/filters for custom post
	 * types and may short-circuit update_post_meta(). Internal routing/status markers
	 * must always live in wp_postmeta because our read side queries wp_postmeta
	 * directly for virtual-site isolation.
	 *
	 * @param int    $target_id Target post ID in the current blog context.
	 * @param string $meta_key Internal meta key.
	 * @param mixed  $meta_value Internal meta value.
	 * @return bool
	 */
	private static function persist_internal_post_meta( int $target_id, string $meta_key, $meta_value ): bool {
		if ( $target_id <= 0 || '' === $meta_key || 0 !== strpos( $meta_key, '_wptsall_' ) ) {
			return false;
		}

		$ok = false;
		if ( class_exists( '\\WPTSALL\\Tasks\\Services\\Direct_DB_Service' ) ) {
			$ok = \WPTSALL\Tasks\Services\Direct_DB_Service::update_post_meta( $target_id, $meta_key, $meta_value );
		}

		if ( ! $ok ) {
			$result = update_post_meta( $target_id, $meta_key, $meta_value );
			$ok     = false !== $result || get_post_meta( $target_id, $meta_key, true ) === $meta_value;
			clean_post_cache( $target_id );
			wp_cache_delete( $target_id, 'post_meta' );
		}

		return (bool) $ok;
	}

	/**
	 * Persist the common source/relation marker set for manual targets.
	 *
	 * @param int         $target_id Target post ID in the current blog context.
	 * @param int         $source_post_id Source post ID.
	 * @param int         $source_blog_id Source blog ID.
	 * @param int         $relation_id Relation ID.
	 * @param string|null $virtual_site_id Optional virtual site ID marker.
	 * @return void
	 */
	private static function persist_translation_markers( int $target_id, int $source_post_id, int $source_blog_id, int $relation_id, ?string $virtual_site_id = null ): void {
		if ( null !== $virtual_site_id && '' !== $virtual_site_id ) {
			self::persist_internal_post_meta( $target_id, '_wptsall_virtual_site_id', $virtual_site_id );
		}
		self::persist_internal_post_meta( $target_id, '_wptsall_source_post_id', $source_post_id );
		self::persist_internal_post_meta( $target_id, '_wptsall_source_blog_id', $source_blog_id );
		self::persist_internal_post_meta( $target_id, '_wptsall_relation_id', $relation_id );
	}

	/**
	 * Sanitize a translate meta value without destroying JSON / serialized shapes.
	 *
	 * @since 2.1.0
	 * @param string $meta_key Field key.
	 * @param mixed  $value    Raw value.
	 * @param string $format   content_format hint.
	 * @return mixed
	 */
	/**
	 * Keep only native wp_posts columns before wp_insert_post / wp_update_post.
	 *
	 * @since 2.1.0
	 * @param array $post_data Candidate post fields.
	 * @return array
	 */
	public static function only_post_columns( array $post_data ) {
		$out = array();
		foreach ( $post_data as $key => $value ) {
			if ( self::is_standard_post_column( $key ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	public static function sanitize_translate_meta_value( $meta_key, $value, $format = '' ) {
		unset( $meta_key );
		if ( in_array( $format, array( 'json_structured', 'serialized_php' ), true ) ) {
			return $value;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$trim = ltrim( $value );
			if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
				$decoded = json_decode( $value, true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					return $decoded;
				}
			}
			if ( 'rich_html' === $format ) {
				return wp_kses_post( $value );
			}
		}
		return $value;
	}

	/**
	 * Sanitize one editor/REST payload value using WP columns + adapter rules.
	 *
	 * Structured plugin fields (Elementor JSON, Woo attributes, ACF groups)
	 * must not go through sanitize_text_field.
	 *
	 * @since 2.1.0
	 * @param string $field_name Field key.
	 * @param mixed  $value      Raw editor value.
	 * @return mixed
	 */
	public static function sanitize_editor_payload_value( $field_name, $value ) {
		$field_name = (string) $field_name;
		if ( 'post_content' === $field_name ) {
			return is_string( $value ) ? wp_kses_post( $value ) : $value;
		}
		if ( 'post_excerpt' === $field_name ) {
			return is_string( $value ) ? sanitize_textarea_field( $value ) : $value;
		}
		if ( in_array( $field_name, array( 'post_title', 'post_name', 'post_status' ), true ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}
		if ( self::is_media_field( $field_name, array(), $value ) ) {
			return absint( $value );
		}

		$format = '';
		if ( class_exists( '\\WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry' ) ) {
			$rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( $field_name );
			if ( is_array( $rule ) ) {
				$format = (string) ( $rule['content_format'] ?? '' );
			}
		}

		if ( in_array( $format, array( 'json_structured', 'serialized_php' ), true )
			|| is_array( $value )
			|| is_object( $value ) ) {
			return self::sanitize_translate_meta_value( $field_name, $value, $format ? $format : 'json_structured' );
		}
		if ( 'rich_html' === $format && is_string( $value ) ) {
			return wp_kses_post( $value );
		}
		if ( is_string( $value ) ) {
			$trim = ltrim( $value );
			if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
				return self::sanitize_translate_meta_value( $field_name, $value, 'json_structured' );
			}
			if ( false !== strpos( $value, "\n" ) ) {
				return sanitize_textarea_field( $value );
			}
			return sanitize_text_field( $value );
		}
		return $value;
	}

	/**
	 * Overlay active plugin adapter rules onto a relation field config.
	 *
	 * Only keys already stored on the source post are added, so Woo commerce
	 * meta is not dumped onto a regular post. This is the mapping-admin path
	 * for plugin characteristics — not a frontend hook pack.
	 *
	 * @since 2.1.0
	 * @param array $field_config Merged relation field config.
	 * @param int   $post_id      Source post ID.
	 * @return array
	 */
	public static function apply_adapter_field_rules( array $field_config, $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! class_exists( '\\WPTSALL\\Models\\Adapters\\Plugin_Field_Rules_Registry' ) ) {
			return $field_config;
		}

		$meta_keys = array_keys( (array) get_post_meta( $post_id ) );
		foreach ( $meta_keys as $meta_key ) {
			$meta_key = (string) $meta_key;
			if ( '' === $meta_key || 0 === strpos( $meta_key, '_wptsall_' ) ) {
				continue;
			}
			$rule = \WPTSALL\Models\Adapters\Plugin_Field_Rules_Registry::match_meta_key( $meta_key );
			if ( ( ! is_array( $rule ) || empty( $rule ) ) && class_exists( '\\WPTSALL\\Core\\WPML_Config_Reader' ) ) {
				$peer_action = \WPTSALL\Core\WPML_Config_Reader::get_field_action( $meta_key );
				if ( $peer_action ) {
					$format = 'plain_text';
					$trim   = '';
					$raw    = get_post_meta( $post_id, $meta_key, true );
					if ( is_array( $raw ) || is_object( $raw ) ) {
						$format = 'json_structured';
					} elseif ( is_string( $raw ) ) {
						$trim = ltrim( $raw );
						if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
							$format = 'json_structured';
						}
					}
					$rule = array(
						'type'           => $peer_action,
						'content_format' => $format,
						'enabled'        => 'skip' !== $peer_action,
						'source'         => 'peer_config',
					);
				}
			}
			if ( ! is_array( $rule ) || empty( $rule ) ) {
				continue;
			}
			if ( ! isset( $field_config['field_capabilities'] ) || ! is_array( $field_config['field_capabilities'] ) ) {
				$field_config['field_capabilities'] = array();
			}
			if ( isset( $field_config['field_capabilities'][ $meta_key ] ) && is_array( $field_config['field_capabilities'][ $meta_key ] ) ) {
				$field_config['field_capabilities'][ $meta_key ] = array_merge( $rule, $field_config['field_capabilities'][ $meta_key ] );
			} else {
				$field_config['field_capabilities'][ $meta_key ] = $rule;
			}

			$type     = (string) ( $field_config['field_capabilities'][ $meta_key ]['type'] ?? $rule['type'] ?? 'sync' );
			$list_key = 'sync_fields';
			if ( 'translate' === $type ) {
				$list_key = 'translate_fields';
			} elseif ( in_array( $type, array( 'id_mapping', 'mapping' ), true ) ) {
				$list_key = 'id_mapping_fields';
				if ( ! empty( $rule['reference_type'] ) ) {
					if ( ! isset( $field_config['id_mapping_details'] ) || ! is_array( $field_config['id_mapping_details'] ) ) {
						$field_config['id_mapping_details'] = array();
					}
					$field_config['id_mapping_details'][ $meta_key ] = array(
						'reference_type' => $rule['reference_type'],
						'value_format'   => $rule['value_format'] ?? 'scalar',
					);
				}
			} elseif ( in_array( $type, array( 'no_sync', 'skip' ), true ) ) {
				$list_key = 'skip_fields';
			} elseif ( 'compute' === $type ) {
				$list_key = 'compute_fields';
			}

			if ( ! isset( $field_config[ $list_key ] ) || ! is_array( $field_config[ $list_key ] ) ) {
				$field_config[ $list_key ] = array();
			}
			if ( ! in_array( $meta_key, $field_config[ $list_key ], true ) ) {
				$field_config[ $list_key ][] = $meta_key;
			}
		}

		return $field_config;
	}

	/**
	 * Get editor data for manual translation.
	 *
	 * Retrieves source post data, existing translation (if any), field configuration,
	 * and relation metadata needed to render the translation editor.
	 *
	 * @since 1.0.0
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id    Site relation ID.
	 * @return array|\WP_Error Editor data array or WP_Error on failure.
	 */
	public static function get_editor_data( int $source_post_id, int $relation_id ) {
		// Validate relation exists and is active.
		$relation = Site_Relation_Service::get_relation( $relation_id );

		if ( ! $relation ) {
			return new \WP_Error(
				'relation_not_found',
				__( 'Site relation not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_Error(
				'relation_inactive',
				__( 'Site relation is not active.', 'wpmmcc-ats' ),
				array( 'status' => 409 )
			);
		}

		// Get source post.
		$source_post = get_post( $source_post_id );

		if ( ! $source_post ) {
			return new \WP_Error(
				'source_post_not_found',
				__( 'Source post not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Get field config for this relation and post type.
		$field_config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
			$relation_id,
			$source_post->post_type
		);
		$field_config = self::apply_adapter_field_rules( $field_config, $source_post_id );

		// Build source data.
		$source_data = array(
			'ID'           => $source_post->ID,
			'post_title'   => $source_post->post_title,
			'post_content' => $source_post->post_content,
			'post_excerpt' => $source_post->post_excerpt,
			'post_status'  => $source_post->post_status,
			'post_type'    => $source_post->post_type,
			'post_name'    => $source_post->post_name,
			'post_date'    => $source_post->post_date,
		);

		// Include translate-capable plugin meta (Elementor, Woo, ACF, …) plus id_mapping.
		$translate_fields   = $field_config['translate_fields'] ?? array();
		$id_mapping_fields  = $field_config['id_mapping_fields'] ?? array();
		$id_mapping_details = $field_config['id_mapping_details'] ?? array();
		foreach ( $translate_fields as $field_name ) {
			$field_name = (string) $field_name;
			if ( self::is_standard_post_column( $field_name ) ) {
				continue;
			}
			$meta_val = get_post_meta( $source_post_id, $field_name, true );
			if ( '' !== $meta_val && null !== $meta_val ) {
				$source_data[ $field_name ] = $meta_val;
			}
		}
		foreach ( $id_mapping_fields as $field_name ) {
			// Skip standard post columns (already in source_data).
			if ( property_exists( $source_post, $field_name ) ) {
				$source_data[ $field_name ] = $source_post->$field_name;
				continue;
			}
			// Get meta value for meta-based id_mapping fields.
			$meta_val = get_post_meta( $source_post_id, $field_name, true );
			if ( '' !== $meta_val ) {
				$source_data[ $field_name ] = $meta_val;

				// For media fields, also include a preview URL for the editor.
				if ( self::is_media_id_mapping( $field_name, $id_mapping_details ) ) {
					$attachment_id = absint( $meta_val );
					if ( $attachment_id ) {
						$url = wp_get_attachment_url( $attachment_id );
						if ( $url ) {
							$source_data[ $field_name . '_url' ] = $url;
						}
					}
				}
			}
		}

		// Check for existing translation.
		$target_data    = array();
		$existing_id    = self::find_existing_translation( $source_post_id, $relation_id );

		if ( $existing_id ) {
			$target_post = self::get_target_post( $existing_id, $relation );

			if ( $target_post ) {
				$target_data = array(
					'ID'           => $target_post->ID,
					'post_title'   => $target_post->post_title,
					'post_content' => $target_post->post_content,
					'post_excerpt' => $target_post->post_excerpt,
					'post_status'  => $target_post->post_status,
					'post_type'    => $target_post->post_type,
					'post_name'    => $target_post->post_name,
					'post_date'    => $target_post->post_date,
				);

				// Include media/id_mapping meta fields in target data.
				$target_site_type = $relation['target_site_type'] ?? 'wp';
				$target_switched  = false;
				if ( 'wp' === $target_site_type && is_multisite() ) {
					switch_to_blog( (int) $relation['target_site_id'] );
					$target_switched = true;
				}
				try {
					foreach ( $translate_fields as $field_name ) {
						$field_name = (string) $field_name;
						if ( self::is_standard_post_column( $field_name ) ) {
							continue;
						}
						$target_meta_val = get_post_meta( $existing_id, $field_name, true );
						if ( '' !== $target_meta_val && null !== $target_meta_val ) {
							$target_data[ $field_name ] = $target_meta_val;
						}
					}
					foreach ( $id_mapping_fields as $field_name ) {
						if ( property_exists( $target_post, $field_name ) ) {
							$target_data[ $field_name ] = $target_post->$field_name;
							continue;
						}
						$target_meta_val = get_post_meta( $existing_id, $field_name, true );
						if ( '' !== $target_meta_val ) {
							$target_data[ $field_name ] = $target_meta_val;
						}
					}
				} finally {
					if ( $target_switched ) {
						restore_current_blog();
					}
				}
			}
		}

		wptsall_log_debug(
			'manual-translation',
			'Editor data retrieved',
			array(
				'source_post_id' => $source_post_id,
				'relation_id'    => $relation_id,
				'has_existing'   => ! empty( $target_data ),
			)
		);

		return array(
			'source'       => $source_data,
			// Empty array JSON-encodes as [] and breaks object|null contracts;
			// expose null when no translation target exists yet.
			'target'       => ! empty( $target_data ) ? $target_data : null,
			'field_config' => $field_config,
			'relation'     => $relation,
		);
	}

	/**
	 * Get suggestion-only translation memory results for one manual editor field.
	 *
	 * Reuses the templates translation-memory service, but keeps it advisory-only
	 * for source-side manual editing.
	 *
	 * @since 1.6.3
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $relation_id Relation ID.
	 * @param string $field_key Field key.
	 * @param int    $limit Max suggestions.
	 * @return array|\WP_Error
	 */
	public static function get_memory_suggestions( int $source_post_id, int $relation_id, string $field_key, int $limit = 5 ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error( 'relation_not_found', __( 'Site relation not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_Error( 'relation_inactive', __( 'Site relation is not active.', 'wpmmcc-ats' ), array( 'status' => 409 ) );
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return new \WP_Error( 'source_post_not_found', __( 'Source post not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$field_key = sanitize_key( $field_key );
		$field_config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
			$relation_id,
			$source_post->post_type
		);
		$translate_fields = array_map( 'sanitize_key', (array) ( $field_config['translate_fields'] ?? array() ) );
		if ( '' === $field_key || ! in_array( $field_key, $translate_fields, true ) ) {
			return new \WP_Error( 'invalid_memory_field', __( 'This field does not support translation memory suggestions.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$raw_source_text = '';
		if ( property_exists( $source_post, $field_key ) ) {
			$raw_source_text = (string) $source_post->$field_key;
		} else {
			$meta_value = get_post_meta( $source_post_id, $field_key, true );
			if ( is_scalar( $meta_value ) ) {
				$raw_source_text = (string) $meta_value;
			}
		}

		if ( in_array( $field_key, array( 'post_content', 'post_excerpt' ), true ) ) {
			$raw_source_text = wp_strip_all_tags( $raw_source_text );
		}

		$normalized_source_text = $raw_source_text;
		if ( class_exists( '\\WPTSALL\\Templates\\Services\\Template_Entry_Service' ) ) {
			$normalized_source_text = \WPTSALL\Templates\Services\Template_Entry_Service::normalize_original_string( $raw_source_text );
		}
		if ( '' === $normalized_source_text ) {
			return array(
				'items' => array(),
				'total' => 0,
				'field_key' => $field_key,
				'text_domain' => '',
				'skipped_reason' => 'empty_source',
			);
		}
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $normalized_source_text ) > 1200 : strlen( $normalized_source_text ) > 1200 ) {
			return array(
				'items' => array(),
				'total' => 0,
				'field_key' => $field_key,
				'text_domain' => '',
				'skipped_reason' => 'source_too_long',
			);
		}

		$text_domain = self::resolve_memory_text_domain( $relation_id, (string) $source_post->post_type );
		$limit = max( 1, min( 10, $limit ) );
		$items = array();

		if ( class_exists( '\WPTSALL\TranslationMemory\Services\Translation_Memory_Service' ) && '' !== $text_domain ) {
			$items = \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::suggest_for_relation_domain(
				$relation_id,
				$text_domain,
				$normalized_source_text,
				'',
				$limit
			);
		}

		if ( empty( $items ) && 'wordpress-blog' !== $text_domain && class_exists( '\WPTSALL\TranslationMemory\Services\Translation_Memory_Service' ) ) {
			$text_domain = 'wordpress-blog';
			$items = \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::suggest_for_relation_domain(
				$relation_id,
				$text_domain,
				$normalized_source_text,
				'',
				$limit
			);
		}

		return array(
			'items' => array_values( (array) $items ),
			'total' => count( (array) $items ),
			'field_key' => $field_key,
			'text_domain' => $text_domain,
			'source_length' => function_exists( 'mb_strlen' ) ? mb_strlen( $normalized_source_text ) : strlen( $normalized_source_text ),
		);
	}

	/**
	 * Save manual translation.
	 *
	 * Creates or updates a translated post in the target site (virtual or WP subsite).
	 * Produces data identical to Sync_Executor::sync_post_to_virtual_site().
	 *
	 * @since 1.0.0
	 *
	 * @param int   $source_post_id  Source post ID.
	 * @param int   $relation_id     Site relation ID.
	 * @param array $translated_data Translated field values (post_title, post_content, post_excerpt, post_name).
	 * @return array|\WP_Error Result array with target_id, is_update, relation_id, or WP_Error.
	 */
	public static function save_translation( int $source_post_id, int $relation_id, array $translated_data ) {
		// Validate relation exists.
		$relation = Site_Relation_Service::get_relation( $relation_id );

		if ( ! $relation ) {
			return new \WP_Error(
				'relation_not_found',
				__( 'Site relation not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Get source post.
		$source_post = get_post( $source_post_id );

		if ( ! $source_post ) {
			return new \WP_Error(
				'source_post_not_found',
				__( 'Source post not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		// Get field config to determine which fields accept user input.
		$field_config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
			$relation_id,
			$source_post->post_type
		);
		$field_config = self::apply_adapter_field_rules( $field_config, $source_post_id );

		$translate_fields = $field_config['translate_fields'] ?? array( 'post_title', 'post_content', 'post_excerpt', 'post_name' );
		$compute_fields   = $field_config['compute_fields'] ?? array();
		$field_capabilities = $field_config['field_capabilities'] ?? array();
		$partitioned      = self::partition_translated_fields( $translate_fields, $translated_data );
		$translate_meta   = $partitioned['meta'];
		// Keep extra plugin meta passed by the editor/client even when the
		// current rule set has not listed the key yet (Woo attributes, Elementor JSON).
		foreach ( $translated_data as $extra_key => $extra_value ) {
			$extra_key = (string) $extra_key;
			if ( self::is_standard_post_column( $extra_key ) || isset( $translate_meta[ $extra_key ] ) ) {
				continue;
			}
			$translate_meta[ $extra_key ] = $extra_value;
		}

		// Build target post_data array (matching Sync_Executor structure).
		// - translate columns: from user input
		// - translate meta: written after insert via persist_translate_meta()
		// - sync fields: from source post
		// - compute fields: from user input if provided, else derived
		$post_data = array(
			'post_status'  => $source_post->post_status,
			'post_type'    => $source_post->post_type,
			'post_date'    => $source_post->post_date,
			'post_author'  => get_current_user_id() ?: 1,
		);

		// Apply translate columns from user input (never dump plugin meta into wp_insert_post).
		// Patch semantics: absent key ≠ clear — omit from $post_data so updates keep prior
		// values; on create, fall back to the source column.
		$existing_target = null;
		if ( class_exists( Translation_Identity::class ) ) {
			$existing_target = Translation_Identity::find_target( $source_post_id, $relation_id, false );
		}
		foreach ( $translate_fields as $field_name ) {
			if ( ! self::is_standard_post_column( $field_name ) ) {
				continue;
			}
			if ( array_key_exists( $field_name, $translated_data ) ) {
				if ( 'post_content' === $field_name ) {
					$post_data[ $field_name ] = wp_kses_post( $translated_data[ $field_name ] );
				} elseif ( 'post_excerpt' === $field_name ) {
					$post_data[ $field_name ] = sanitize_textarea_field( $translated_data[ $field_name ] );
				} else {
					$post_data[ $field_name ] = sanitize_text_field( $translated_data[ $field_name ] );
				}
			} elseif ( ! $existing_target && property_exists( $source_post, $field_name ) ) {
				$post_data[ $field_name ] = $source_post->$field_name;
			}
			// else: update path — leave field out of $post_data (wp_update_post keeps prior value).
		}

		// Apply compute fields from user input or derive defaults.
		foreach ( $compute_fields as $field_name ) {
			if ( isset( $translated_data[ $field_name ] ) && '' !== $translated_data[ $field_name ] ) {
				$post_data[ $field_name ] = sanitize_title( $translated_data[ $field_name ] );
			} elseif ( 'post_name' === $field_name ) {
				// Derive slug from translated title.
				$post_data['post_name'] = sanitize_title( $post_data['post_title'] ?? '' );
			}
		}

		if ( '' === trim( (string) ( $post_data['post_title'] ?? '' ) )
			&& '' === trim( (string) ( $post_data['post_content'] ?? '' ) )
			&& '' === trim( (string) ( $post_data['post_excerpt'] ?? '' ) ) ) {
			foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $core_field ) {
				if ( ! isset( $translated_data[ $core_field ] ) || '' === trim( (string) $translated_data[ $core_field ] ) ) {
					continue;
				}
				if ( 'post_content' === $core_field ) {
					$post_data[ $core_field ] = wp_kses_post( $translated_data[ $core_field ] );
				} elseif ( 'post_excerpt' === $core_field ) {
					$post_data[ $core_field ] = sanitize_textarea_field( $translated_data[ $core_field ] );
				} else {
					$post_data[ $core_field ] = sanitize_text_field( $translated_data[ $core_field ] );
				}
			}
		}
		if ( '' === trim( (string) ( $post_data['post_title'] ?? '' ) )
			&& '' === trim( (string) ( $post_data['post_content'] ?? '' ) )
			&& '' === trim( (string) ( $post_data['post_excerpt'] ?? '' ) ) ) {
			$post_data['post_title'] = (string) $source_post->post_title;
		}

		if ( ! isset( $post_data['post_name'] ) || '' === (string) $post_data['post_name'] ) {
			$post_data['post_name'] = sanitize_title( $post_data['post_title'] ?? '' );
		}

		// Apply id_mapping fields.
		// Media fields can be overridden by user input; non-media fields copy from source.
		$id_mapping_fields  = $field_config['id_mapping_fields'] ?? array();
		$id_mapping_details = $field_config['id_mapping_details'] ?? array();
		// B3: Use enriched config instead of separate query.
		$media_fields_data  = self::collect_manual_media_fields( $source_post, $relation, $translated_data, $id_mapping_fields, $id_mapping_details ); // Track media fields after cross-blog safe resolution.
		foreach ( $id_mapping_fields as $field_name ) {
			if ( self::is_media_id_mapping( $field_name, $id_mapping_details ) ) {
				continue;
			} else {
				// Non-media id_mapping fields: resolve via Id_Mapping_Resolver with safe defaults.
				if ( property_exists( $source_post, $field_name ) ) {
					$source_value = $source_post->$field_name;
					if ( absint( $source_value ) > 0 ) {
						$cap = $field_capabilities[ $field_name ] ?? array();
						$cap = is_array( $cap ) ? $cap : array( 'type' => 'id_mapping' );
						$resolved = \WPTSALL\Models\Services\Id_Mapping_Resolver::resolve(
							$field_name,
							$source_value,
							$cap,
							array(
								'relation_id'    => $relation_id,
								'source_blog_id' => get_current_blog_id(),
								'target_blog_id' => (int) ( $relation['target_site_id'] ?? 0 ),
								'target_type'    => $relation['target_site_type'] ?? 'virtual',
							)
						);
						if ( $resolved['resolved'] && null !== $resolved['target_value'] ) {
							$post_data[ $field_name ] = $resolved['target_value'];
						} else {
							// Safe defaults when resolver cannot map the ID.
							if ( 'post_parent' === $field_name ) {
								$post_data[ $field_name ] = 0;
							} elseif ( 'post_author' === $field_name ) {
								$post_data[ $field_name ] = get_current_user_id() ?: 1;
							} else {
								$post_data[ $field_name ] = 0;
							}
						}
					} else {
						$post_data[ $field_name ] = $source_value;
					}
				}
			}
		}

		// Resolve non-media ID fields while the source blog is still current. Do
		// not read source post meta after switching to a target subsite: numeric
		// post IDs and meta rows are local to each blog.
		$resolved_id_mapping_meta = array();
		foreach ( $id_mapping_fields as $field_name ) {
			if ( in_array( $field_name, array( 'post_author', 'post_parent' ), true ) || self::is_media_id_mapping( $field_name, $id_mapping_details ) || property_exists( $source_post, $field_name ) ) {
				continue;
			}
			$source_meta = get_post_meta( $source_post_id, $field_name, true );
			if ( '' === $source_meta || null === $source_meta ) {
				continue;
			}
			$cap = $field_capabilities[ $field_name ] ?? array();
			$resolved = \WPTSALL\Models\Services\Id_Mapping_Resolver::resolve(
				$field_name,
				$source_meta,
				is_array( $cap ) ? $cap : array( 'type' => 'id_mapping' ),
				array(
					'relation_id'    => $relation_id,
					'source_blog_id' => (int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
					'target_blog_id' => (int) ( $relation['target_site_id'] ?? 0 ),
					'target_type'    => $relation['target_site_type'] ?? 'virtual',
				)
			);
			// An unresolved source ID must not be copied into another blog.
			if ( ! empty( $resolved['resolved'] ) && array_key_exists( 'target_value', $resolved ) && null !== $resolved['target_value'] ) {
				$resolved_id_mapping_meta[ $field_name ] = $resolved['target_value'];
			}
		}

		$target_site_type = $relation['target_site_type'] ?? 'wp';
		$post_data        = self::only_post_columns( $post_data );

		if ( 'virtual' === $target_site_type ) {
			$result = self::save_to_virtual_site( $source_post_id, $relation, $post_data );
		} else {
			$result = self::save_to_wp_subsite( $source_post_id, $relation, $post_data );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Apply translated metadata and already-resolved relation-safe media/ID
		// fields in the target blog context.
		$target_id_for_meta    = (int) $result['target_id'];
		$target_site_type_meta = $relation['target_site_type'] ?? 'wp';
		$switched_for_media    = false;
		if ( 'wp' === $target_site_type_meta && is_multisite() ) {
			switch_to_blog( (int) $relation['target_site_id'] );
			$switched_for_media = true;
		}
		try {
			self::persist_translate_meta( $target_id_for_meta, $translate_meta, $field_capabilities );

			foreach ( $media_fields_data as $field_name => $attachment_id ) {
				if ( '_thumbnail_id' === $field_name ) {
					if ( $attachment_id ) {
						set_post_thumbnail( $target_id_for_meta, $attachment_id );
					} else {
						delete_post_thumbnail( $target_id_for_meta );
					}
				} else {
					update_post_meta( $target_id_for_meta, $field_name, $attachment_id );
				}
			}

			foreach ( $resolved_id_mapping_meta as $field_name => $target_meta ) {
				update_post_meta( $target_id_for_meta, $field_name, $target_meta );
			}
		} finally {
			if ( $switched_for_media ) {
				restore_current_blog();
			}
		}

		// Sync term/taxonomy associations from source to target.
		// Pass the already-fetched $field_config to avoid a redundant DB query.
		self::sync_term_associations( $source_post_id, $result['target_id'], $relation_id, $relation, $field_config );

		// Create/update post mapping (+ re-affirm markers).
		if ( class_exists( Translation_Identity::class ) ) {
			Translation_Identity::ensure_markers(
				(int) $result['target_id'],
				$source_post_id,
				$relation_id,
				array(
					'relation'  => $relation,
					'post_type' => $source_post->post_type,
				)
			);
		} elseif ( class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) ) {
			\WPTSALL\Models\Services\Post_Mapping_Service::create_mapping(
				array(
					'source_post_id'    => $source_post_id,
					'source_post_type'  => $source_post->post_type,
					'source_site_id'    => (int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
					'relation_id'       => $relation_id,
					'target_post_id'    => $result['target_id'],
					'target_post_type'  => $source_post->post_type,
					'target_site_id'    => $relation['target_site_id'],
					'relationship_type' => 'translation',
				)
			);
		}

		wptsall_log_info(
			'manual-translation',
			'Manual translation saved',
			array(
				'source_post_id' => $source_post_id,
				'relation_id'    => $relation_id,
				'target_id'      => $result['target_id'],
				'is_update'      => $result['is_update'],
				'target_type'    => $target_site_type,
			)
		);

		if ( class_exists( '\WPTSALL\Sites\Services\Relation_Admin_Event_Service' ) ) {
			\WPTSALL\Sites\Services\Relation_Admin_Event_Service::log_event(
				$relation_id,
				! empty( $result['is_update'] ) ? 'target_manual_updated' : 'target_manual_created',
				array(
					'source_post_id' => $source_post_id,
					'target_post_id' => (int) ( $result['target_id'] ?? 0 ),
					'target_type' => $target_site_type,
					'field_mode' => 'translation',
				),
				'post',
				$source_post_id,
				(int) ( $result['target_id'] ?? 0 )
			);
		}

		/**
		 * Fires after a manual translation has been saved successfully.
		 *
		 * Lets integrations react to manual saves (cache invalidation, sync
		 * triggers, audit tooling) without hooking internal persistence
		 * methods. Fires only after the target post, meta, terms, and
		 * mappings have been persisted.
		 *
		 * @since 2.1.0
		 *
		 * @param int   $source_post_id Source post ID.
		 * @param int   $relation_id    Site relation ID.
		 * @param int   $target_id      Target post ID that received the translation.
		 * @param array $context        Context: target language, target site type, update flag.
		 */
		do_action(
			'wptsall_manual_translation_saved',
			$source_post_id,
			$relation_id,
			(int) $result['target_id'],
			array(
				'target_lang'      => (string) ( $relation['target_lang'] ?? '' ),
				'target_site_type' => $target_site_type,
				'is_update'        => (bool) ( $result['is_update'] ?? false ),
			)
		);

		return array(
			'target_id'   => $result['target_id'],
			'is_update'   => $result['is_update'],
			'relation_id' => $relation_id,
		);
	}

	/**
	 * Create a translation skeleton for a source post.
	 *
	 * Builds a draft target post based on the field configuration:
	 * - sync fields: copied directly from source
	 * - translate fields: left empty (for manual editing)
	 * - compute fields: derived (e.g., post_name = sanitize_title of source title)
	 * - id_mapping fields: attempt to resolve mapped IDs via Post_Mapping_Service or Term_Mapping_Service
	 *
	 * The skeleton is saved as a draft to the appropriate target (virtual site or WP subsite).
	 *
	 * @since 1.1.0
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id    Site relation ID.
	 * @return array|\WP_Error Array with target_id, relation_id, target_type on success, or WP_Error.
	 */
	public static function create_translation_skeleton( int $source_post_id, int $relation_id ) {
		$skeleton = self::build_translation_skeleton_context( $source_post_id, $relation_id );
		if ( is_wp_error( $skeleton ) ) {
			return $skeleton;
		}

		$existing_id = self::find_existing_translation( $source_post_id, $relation_id );
		if ( $existing_id ) {
			$target_site_type = $skeleton['relation']['target_site_type'] ?? 'wp';
			return array(
				'target_id'   => $existing_id,
				'relation_id' => $relation_id,
				'target_type' => $target_site_type,
				'is_new'      => false,
			);
		}

		return self::persist_translation_skeleton(
			$source_post_id,
			$relation_id,
			$skeleton['relation'],
			$skeleton['source_post'],
			$skeleton['post_data'],
			$skeleton['media_fields_data']
		);
	}

	/**
	 * Build translation skeleton context shared by create/rebuild flows.
	 *
	 * @since 1.3.5
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id Relation ID.
	 * @return array|\WP_Error
	 */
	private static function build_translation_skeleton_context( int $source_post_id, int $relation_id ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error( 'relation_not_found', __( 'Site relation not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== sanitize_key( (string) ( $relation['status'] ?? '' ) ) ) {
			return new \WP_Error( 'relation_inactive', __( 'Site relation is not active.', 'wpmmcc-ats' ), array( 'status' => 409 ) );
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return new \WP_Error( 'source_post_not_found', __( 'Source post not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$field_config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
			$relation_id,
			$source_post->post_type
		);

		$translate_fields  = $field_config['translate_fields'] ?? array( 'post_title', 'post_content', 'post_excerpt', 'post_name' );
		$sync_fields       = $field_config['sync_fields'] ?? array( 'post_date', 'post_status' );
		$compute_fields    = $field_config['compute_fields'] ?? array();
		$id_mapping_fields = $field_config['id_mapping_fields'] ?? array();

		$post_data = array(
			'post_status' => 'draft',
			'post_type'   => $source_post->post_type,
			'post_author' => get_current_user_id() ?: 1,
		);

		foreach ( $sync_fields as $field_name ) {
			if ( property_exists( $source_post, $field_name ) ) {
				if ( 'post_status' === $field_name ) {
					$post_data['post_status'] = 'draft';
				} else {
					$post_data[ $field_name ] = $source_post->$field_name;
				}
			}
		}

		foreach ( $translate_fields as $field_name ) {
			if ( self::is_standard_post_column( $field_name ) ) {
				$post_data[ $field_name ] = '';
			}
		}
		if ( '' === trim( (string) ( $post_data['post_title'] ?? '' ) )
			&& '' === trim( (string) ( $post_data['post_content'] ?? '' ) )
			&& '' === trim( (string) ( $post_data['post_excerpt'] ?? '' ) ) ) {
			$post_data['post_title'] = (string) $source_post->post_title;
		}

		foreach ( $compute_fields as $field_name ) {
			if ( 'post_name' === $field_name ) {
				$post_data['post_name'] = sanitize_title( $source_post->post_title );
			} elseif ( 'comment_count' === $field_name ) {
				$post_data['comment_count'] = 0;
			}
		}

		$id_mapping_details = $field_config['id_mapping_details'] ?? array();
		$field_capabilities = $field_config['field_capabilities'] ?? array();
		$resolver_context   = array(
			'relation_id'    => $relation_id,
			'source_blog_id' => get_current_blog_id(),
			'target_blog_id' => (int) ( $relation['target_site_id'] ?? 0 ),
			'target_type'    => $relation['target_site_type'] ?? 'virtual',
		);
		$media_fields_data = self::collect_manual_media_fields( $source_post, $relation, array(), $id_mapping_fields, $id_mapping_details );
		foreach ( $id_mapping_fields as $field_name ) {
			$cap = $field_capabilities[ $field_name ] ?? array();
			$cap = is_array( $cap ) ? $cap : array( 'type' => 'id_mapping' );
			if ( self::is_media_id_mapping( $field_name, $id_mapping_details ) ) {
				continue;
			} elseif ( property_exists( $source_post, $field_name ) ) {
				$source_value = $source_post->$field_name;
				if ( absint( $source_value ) > 0 ) {
					$resolved = \WPTSALL\Models\Services\Id_Mapping_Resolver::resolve( $field_name, $source_value, $cap, $resolver_context );
					$post_data[ $field_name ] = $resolved['resolved'] ? $resolved['target_value'] : $source_value;
				} else {
					$post_data[ $field_name ] = $source_value;
				}
			} else {
				$source_meta = get_post_meta( $source_post_id, $field_name, true );
				if ( '' !== $source_meta ) {
					$resolved = \WPTSALL\Models\Services\Id_Mapping_Resolver::resolve( $field_name, $source_meta, $cap, $resolver_context );
					if ( $resolved['resolved'] && null !== $resolved['target_value'] ) {
						$post_data[ $field_name ] = $resolved['target_value'];
					}
				}
			}
		}

		return array(
			'relation' => $relation,
			'source_post' => $source_post,
			'post_data' => $post_data,
			'media_fields_data' => $media_fields_data,
		);
	}

	/**
	 * Persist a skeleton payload to target storage and sync related metadata.
	 *
	 * @since 1.3.5
	 *
	 * @param int     $source_post_id Source post ID.
	 * @param int     $relation_id Relation ID.
	 * @param array   $relation Relation row.
	 * @param \WP_Post $source_post Source post.
	 * @param array   $post_data Skeleton post data.
	 * @param array   $media_fields_data Media mapping fallback data.
	 * @return array|\WP_Error
	 */
	private static function persist_translation_skeleton( int $source_post_id, int $relation_id, array $relation, $source_post, array $post_data, array $media_fields_data ) {
		$target_site_type = $relation['target_site_type'] ?? 'wp';
		if ( 'virtual' === $target_site_type ) {
			$result = self::save_to_virtual_site( $source_post_id, $relation, $post_data );
		} else {
			$result = self::save_to_wp_subsite( $source_post_id, $relation, $post_data );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$target_id = (int) ( $result['target_id'] ?? 0 );
		$switched_blog = false;
		if ( 'wp' === $target_site_type && is_multisite() ) {
			switch_to_blog( (int) $relation['target_site_id'] );
			$switched_blog = true;
		}
		try {
			foreach ( $media_fields_data as $field_name => $attachment_id ) {
				if ( '_thumbnail_id' === $field_name ) {
					if ( $attachment_id ) {
						set_post_thumbnail( $target_id, $attachment_id );
					}
				} else {
					update_post_meta( $target_id, $field_name, $attachment_id );
				}
			}
		} finally {
			if ( $switched_blog ) {
				restore_current_blog();
			}
		}

		self::sync_term_associations( $source_post_id, $target_id, $relation_id, $relation );
		if ( class_exists( '\WPTSALL\Models\Services\Post_Mapping_Service' ) ) {
			\WPTSALL\Models\Services\Post_Mapping_Service::create_mapping(
				array(
					'source_post_id'    => $source_post_id,
					'source_post_type'  => $source_post->post_type,
					'source_site_id'    => (int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
					'relation_id'       => $relation_id,
					'target_post_id'    => $target_id,
					'target_post_type'  => $source_post->post_type,
					'target_site_id'    => $relation['target_site_id'],
					'relationship_type' => 'translation',
				)
			);
		}

		wptsall_log_info( 'manual-translation', 'Translation skeleton created', array(
			'source_post_id' => $source_post_id,
			'relation_id'    => $relation_id,
			'target_id'      => $target_id,
			'target_type'    => $target_site_type,
		) );

		return array(
			'target_id'   => $target_id,
			'relation_id' => $relation_id,
			'target_type' => $target_site_type,
			'is_new'      => ! empty( $result['is_update'] ) ? false : ! empty( $result['is_new'] ),
		);
	}

	/**
	 * Normalize a requested rebuild mode.
	 *
	 * @param string $mode Requested rebuild mode.
	 * @return string
	 */
	private static function normalize_rebuild_mode( string $mode ): string {
		$mode = sanitize_key( $mode );
		if ( '' === $mode ) {
			$mode = 'reset_translated_only';
		}

		return in_array( $mode, self::get_supported_rebuild_modes(), true ) ? $mode : '';
	}

	/**
	 * Get operator-facing rebuild mode details.
	 *
	 * @return array
	 */
	public static function get_rebuild_mode_details(): array {
		return array(
			'reset_translated_only' => array(
				'mode' => 'reset_translated_only',
				'label' => __( 'Reset translated fields only', 'wpmmcc-ats' ),
				'summary' => __( 'Clears translated fields and returns the target to draft, while preserving synced fields, mapped media, taxonomies, and relation bindings.', 'wpmmcc-ats' ),
			),
			'full_reset' => array(
				'mode' => 'full_reset',
				'label' => __( 'Full skeleton reset', 'wpmmcc-ats' ),
				'summary' => __( 'Rebuilds the target from a fresh skeleton state so source-derived structure is regenerated before translation resumes.', 'wpmmcc-ats' ),
			),
		);
	}

	/**
	 * Get supported rebuild modes.
	 *
	 * @return array
	 */
	private static function get_supported_rebuild_modes(): array {
		return array_keys( self::get_rebuild_mode_details() );
	}

	/**
	 * Rebuild a wp target by resetting translated fields while preserving synced state.
	 *
	 * @param int      $target_post_id Target post ID.
	 * @param int      $source_post_id Source post ID.
	 * @param int      $relation_id Relation ID.
	 * @param array    $relation Relation row.
	 * @param \WP_Post $source_post Source post object.
	 * @param array    $skeleton_post_data Skeleton-derived post data.
	 * @param array    $media_fields_data Media field mapping data.
	 * @return array|\WP_Error
	 */
	private static function rebuild_wp_target_translated_fields_only( int $target_post_id, int $source_post_id, int $relation_id, array $relation, $source_post, array $skeleton_post_data, array $media_fields_data ) {
		$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
		$switched_blog  = false;
		if ( is_multisite() && $target_blog_id > 0 ) {
			switch_to_blog( $target_blog_id );
			$switched_blog = true;
		}

		try {
		$target_post = get_post( $target_post_id );
		if ( ! $target_post ) {
			return new \WP_Error( 'target_post_not_found', __( 'Target post not found on target site.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		$field_config      = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation( $relation_id, $source_post->post_type );
		$translate_fields  = array_values( array_unique( array_map( 'strval', (array) ( $field_config['translate_fields'] ?? array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ) ) ) ) );
		$compute_fields    = array_values( array_unique( array_map( 'strval', (array) ( $field_config['compute_fields'] ?? array() ) ) ) );
		$id_mapping_fields = array_values( array_unique( array_map( 'strval', (array) ( $field_config['id_mapping_fields'] ?? array() ) ) ) );
		$id_mapping_details = $field_config['id_mapping_details'] ?? array();

		$post_data = array(
			'ID'          => $target_post_id,
			'post_status' => 'draft',
		);

		foreach ( array_merge( $translate_fields, $compute_fields ) as $field_name ) {
			if ( isset( $skeleton_post_data[ $field_name ] ) && property_exists( $target_post, $field_name ) ) {
				$post_data[ $field_name ] = $skeleton_post_data[ $field_name ];
			}
		}

		$result_id = wp_update_post( $post_data, true );
		if ( is_wp_error( $result_id ) ) {
			return $result_id;
		}

		foreach ( $id_mapping_fields as $field_name ) {
			if ( self::is_media_id_mapping( $field_name, $id_mapping_details ) ) {
				$attachment_id = absint( $media_fields_data[ $field_name ] ?? 0 );
				if ( '_thumbnail_id' === $field_name ) {
					if ( $attachment_id > 0 ) {
						set_post_thumbnail( $target_post_id, $attachment_id );
					}
				} elseif ( $attachment_id > 0 ) {
					update_post_meta( $target_post_id, $field_name, $attachment_id );
				}
				continue;
			}
			if ( array_key_exists( $field_name, $skeleton_post_data ) ) {
				update_post_meta( $target_post_id, $field_name, $skeleton_post_data[ $field_name ] );
			}
		}

		self::persist_translation_markers( $target_post_id, $source_post_id, (int) ( $relation['source_site_id'] ?? get_current_blog_id() ), $relation_id );
		self::persist_internal_post_meta( $target_post_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

		return array(
			'target_id'   => $target_post_id,
			'relation_id' => $relation_id,
			'target_type' => 'wp',
			'is_new'      => false,
		);
		} finally {
			if ( $switched_blog ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Resolve a mapped post ID via Post_Mapping_Service.
	 *
	 * @since 1.1.0
	 * @deprecated 1.3.0 Use \WPTSALL\Models\Services\Id_Mapping_Resolver::resolve() instead.
	 *
	 * @param int $source_id   Source post/object ID to resolve.
	 * @param int $relation_id Site relation ID.
	 * @return int|null Mapped target ID or null if not found.
	 */
	private static function resolve_mapped_post_id( int $source_id, int $relation_id ): ?int {
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Post_Mapping_Service' ) ) {
			return null;
		}

		$mapped = \WPTSALL\Models\Services\Post_Mapping_Service::get_mapped_id( $relation_id, $source_id );

		return $mapped ? (int) $mapped : null;
	}

	/**
	 * Update all fields on a target post (Edit Mode).
	 *
	 * Unlike save_translation() which respects field classifications (only updating
	 * translate/compute fields and copying sync fields from source), this method
	 * updates ALL provided fields directly on the target post. Used when the admin
	 * switches to "Edit Mode" and has full control over all fields.
	 *
	 * Also updates taxonomy term associations if 'taxonomies' key is present.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param int   $relation_id    Site relation ID.
	 * @param array $all_fields     All field values to update. May include 'taxonomies' key.
	 * @return array|\WP_Error Result array with target_id, or WP_Error.
	 */
	public static function update_all_fields( int $target_post_id, int $relation_id, array $all_fields ) {
		// Validate relation exists.
		$relation = Site_Relation_Service::get_relation( $relation_id );

		if ( ! $relation ) {
			return new \WP_Error(
				'relation_not_found',
				__( 'Site relation not found.', 'wpmmcc-ats' ),
				array( 'status' => 404 )
			);
		}

		$target_site_type = $relation['target_site_type'] ?? 'wp';

		// Extract taxonomy data before processing fields.
		$taxonomies = array();
		if ( isset( $all_fields['taxonomies'] ) ) {
			$taxonomies = $all_fields['taxonomies'];
			unset( $all_fields['taxonomies'] );
		}

		// Get field config and id_mapping_details for field type detection.
		$post_type = 'post';
		if ( 'wp' === $target_site_type && is_multisite() && (int) ( $relation['target_site_id'] ?? 0 ) > 0 ) {
			$target_context_switched = false;
			switch_to_blog( (int) $relation['target_site_id'] );
			$target_context_switched = true;
			try {
				$target_post_obj = get_post( $target_post_id );
				$post_type = $target_post_obj ? $target_post_obj->post_type : 'post';
			} finally {
				if ( $target_context_switched ) {
					restore_current_blog();
				}
			}
		} else {
			$target_post_obj = get_post( $target_post_id );
			$post_type = $target_post_obj ? $target_post_obj->post_type : 'post';
		}
		$field_config       = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
			$relation_id,
			$post_type
		);
		$id_mapping_details = $field_config['id_mapping_details'] ?? array();

		// Build post_data from all provided fields.
		$post_data = array( 'ID' => $target_post_id );

		$standard_columns = array(
			'post_title', 'post_content', 'post_excerpt', 'post_status',
			'post_name', 'post_date', 'post_author', 'post_parent',
			'menu_order', 'comment_status', 'ping_status',
		);

		$meta_fields = array();

		foreach ( $all_fields as $field_name => $value ) {
			if ( in_array( $field_name, $standard_columns, true ) ) {
				// Sanitize based on field type.
				if ( 'post_content' === $field_name ) {
					$post_data[ $field_name ] = wp_kses_post( $value );
				} elseif ( 'post_name' === $field_name ) {
					$post_data[ $field_name ] = sanitize_title( $value );
				} elseif ( 'post_excerpt' === $field_name ) {
					$post_data[ $field_name ] = sanitize_textarea_field( $value );
				} else {
					$post_data[ $field_name ] = sanitize_text_field( $value );
				}
			} else {
				// Meta field.
				if ( self::is_media_id_mapping( $field_name, $id_mapping_details ) ) {
					$meta_fields[ $field_name ] = absint( $value );
				} else {
					$meta_fields[ $field_name ] = sanitize_text_field( $value );
				}
			}
		}

		// Handle virtual site target.
		if ( 'virtual' === $target_site_type ) {
			$result_id = wp_update_post( $post_data, true );

			if ( is_wp_error( $result_id ) ) {
				wptsall_log_error(
					'manual-translation',
					'Edit Mode: Failed to update virtual site post',
					array(
						'error'     => $result_id->get_error_message(),
						'target_id' => $target_post_id,
					)
				);
				return $result_id;
			}

			// Update meta fields.
			foreach ( $meta_fields as $meta_key => $meta_value ) {
				if ( '_thumbnail_id' === $meta_key ) {
					if ( $meta_value ) {
						set_post_thumbnail( $target_post_id, $meta_value );
					} else {
						delete_post_thumbnail( $target_post_id );
					}
				} else {
					update_post_meta( $target_post_id, $meta_key, $meta_value );
				}
			}

			// Update taxonomy associations.
			if ( ! empty( $taxonomies ) ) {
				self::apply_taxonomy_updates( $target_post_id, $taxonomies );
			}

			// Update sync timestamp.
			self::persist_internal_post_meta( $target_post_id, '_wptsall_last_synced', current_time( 'mysql', true ) );
		} else {
			// Handle WP subsite target.
			$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
			$switched       = false;

			if ( is_multisite() && $target_blog_id > 0 ) {
				switch_to_blog( $target_blog_id );
				$switched = true;
			}

			try {
				$result_id = wp_update_post( $post_data, true );

				if ( is_wp_error( $result_id ) ) {
					wptsall_log_error(
						'manual-translation',
						'Edit Mode: Failed to update WP subsite post',
						array(
							'error'     => $result_id->get_error_message(),
							'target_id' => $target_post_id,
						)
					);
					return $result_id;
				}

				// Update meta fields.
				foreach ( $meta_fields as $meta_key => $meta_value ) {
					if ( '_thumbnail_id' === $meta_key ) {
						if ( $meta_value ) {
							set_post_thumbnail( $target_post_id, $meta_value );
						} else {
							delete_post_thumbnail( $target_post_id );
						}
					} else {
						update_post_meta( $target_post_id, $meta_key, $meta_value );
					}
				}

				// Update taxonomy associations.
				if ( ! empty( $taxonomies ) ) {
					self::apply_taxonomy_updates( $target_post_id, $taxonomies );
				}

				// Update sync timestamp.
				self::persist_internal_post_meta( $target_post_id, '_wptsall_last_synced', current_time( 'mysql', true ) );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		wptsall_log_info(
			'manual-translation',
			'Edit Mode: All fields updated',
			array(
				'target_id'      => $target_post_id,
				'relation_id'    => $relation_id,
				'fields_count'   => count( $all_fields ),
				'has_taxonomies' => ! empty( $taxonomies ),
			)
		);

		$source_post_id = self::resolve_source_post_id_from_target( $target_post_id, $relation );
		if ( class_exists( '\WPTSALL\Sites\Services\Relation_Admin_Event_Service' ) ) {
			\WPTSALL\Sites\Services\Relation_Admin_Event_Service::log_event(
				$relation_id,
				'target_manual_updated',
				array(
					'source_post_id' => $source_post_id,
					'target_post_id' => $target_post_id,
					'field_mode' => 'edit',
					'fields_count' => count( $all_fields ),
					'has_taxonomies' => ! empty( $taxonomies ),
				),
				'post',
				$source_post_id,
				$target_post_id
			);
		}

		return array(
			'target_id'   => $target_post_id,
			'is_update'   => true,
			'relation_id' => $relation_id,
		);
	}

	/**
	 * Apply taxonomy term updates to a target post.
	 *
	 * Handles both hierarchical (array of term IDs) and flat (object with term_ids
	 * and new_terms) taxonomy data.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $post_id    Target post ID.
	 * @param array $taxonomies Taxonomy data: { taxonomy_name => term_ids[] | { term_ids: [], new_terms: [] } }.
	 */
	private static function apply_taxonomy_updates( int $post_id, array $taxonomies ): void {
		foreach ( $taxonomies as $taxonomy_name => $term_data ) {
			$taxonomy_name = sanitize_key( $taxonomy_name );

			if ( ! taxonomy_exists( $taxonomy_name ) ) {
				continue;
			}

			$term_ids = array();

			if ( is_array( $term_data ) && ! isset( $term_data['term_ids'] ) ) {
				// Hierarchical taxonomy: array of term IDs.
				$term_ids = array_map( 'absint', $term_data );
			} elseif ( is_array( $term_data ) || is_object( $term_data ) ) {
				$term_data = (array) $term_data;

				// Existing term IDs.
				if ( ! empty( $term_data['term_ids'] ) ) {
					$term_ids = array_map( 'absint', (array) $term_data['term_ids'] );
				}

				// New terms to create.
				if ( ! empty( $term_data['new_terms'] ) ) {
					foreach ( (array) $term_data['new_terms'] as $term_name ) {
						$term_name = sanitize_text_field( $term_name );
						if ( empty( $term_name ) ) {
							continue;
						}

						// Check if term already exists.
						$existing = term_exists( $term_name, $taxonomy_name );
						if ( $existing ) {
							$term_ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
						} else {
							$new_term = wp_insert_term( $term_name, $taxonomy_name );
							if ( ! is_wp_error( $new_term ) ) {
								$term_ids[] = (int) $new_term['term_id'];
							}
						}
					}
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy_name );
			} else {
				// Clear all terms for this taxonomy if empty.
				wp_set_object_terms( $post_id, array(), $taxonomy_name );
			}
		}
	}

	/**
	 * Preflight-check whether a target post can be attached safely to a relation.
	 *
	 * This does not change mappings; it only reports conflicts and risk signals.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id Relation ID.
	 * @param int $candidate_target_post_id Candidate target post ID.
	 * @return array|\WP_Error
	 */
	public static function preflight_attach_target( int $source_post_id, int $relation_id, int $candidate_target_post_id, bool $allow_replace_existing = false ) {
		$context = self::get_wp_target_management_context( $source_post_id, $relation_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$result = self::validate_wp_target_candidate( $context['source_post'], $context['relation'], $candidate_target_post_id, $allow_replace_existing );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['mode'] = $allow_replace_existing ? 'replace' : 'attach';
		$result['mode_label'] = $allow_replace_existing ? __( 'Replace current target', 'wpmmcc-ats' ) : __( 'Attach existing target', 'wpmmcc-ats' );
		$result['operator_summary'] = ! empty( $result['allowed'] )
			? ( $allow_replace_existing
				? __( 'Replacement preflight passed. This candidate can take over the relation after you confirm the swap.', 'wpmmcc-ats' )
				: __( 'Attach preflight passed. This candidate can be bound to the relation after you confirm.', 'wpmmcc-ats' ) )
			: ( $allow_replace_existing
				? __( 'Replacement preflight is blocked. Resolve the reported conflicts before swapping targets.', 'wpmmcc-ats' )
				: __( 'Attach preflight is blocked. Resolve the reported conflicts before binding this candidate.', 'wpmmcc-ats' ) );
		$result['operator_notes'] = array_values( array_filter( array(
			__( 'Preflight checks relation occupancy, reverse mappings, and post-type compatibility before any mutation runs.', 'wpmmcc-ats' ),
			/* translators: %d: <value> */
			$result['existing_target_id'] ? sprintf( __( 'Current relation target: #%d.', 'wpmmcc-ats' ), (int) $result['existing_target_id'] ) : __( 'Current relation target: none attached.', 'wpmmcc-ats' ),
			/* translators: %1$d: <value>, %2$s: <value> */
			sprintf( __( 'Candidate target #%1$d status: %2$s.', 'wpmmcc-ats' ), (int) $result['candidate_target_id'], (string) $result['candidate_status'] ),
		) ) );

		if ( class_exists( '\WPTSALL\Sites\Services\Relation_Admin_Event_Service' ) ) {
			\WPTSALL\Sites\Services\Relation_Admin_Event_Service::log_event(
				$relation_id,
				'attach_preflight',
				array(
					'source_post_id' => $source_post_id,
					'candidate_target_post_id' => $candidate_target_post_id,
					'allowed' => $result['allowed'],
					'conflicts' => $result['conflicts'],
					'warnings' => $result['warnings'],
					'mode' => $result['mode'],
				),
				'post',
				$source_post_id,
				$candidate_target_post_id
			);
		}

		return $result;
	}

	/**
	 * Search target posts on a wp target site for manual attach/review workflows.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $relation_id Site relation ID.
	 * @param string $query Search query.
	 * @param string $post_type Optional post type.
	 * @return array|\WP_Error
	 */
	public static function search_target_posts( int $source_post_id, int $relation_id, string $query, string $post_type = '' ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error( 'relation_not_found', __( 'Site relation not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}
		if ( 'wp' !== ( $relation['target_site_type'] ?? 'wp' ) ) {
			return new \WP_Error( 'unsupported_target', __( 'Remote target search only supports wp target sites.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}
		if ( ! is_multisite() ) {
			return new \WP_Error( 'multisite_required', __( 'Remote target search requires multisite.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}
		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return new \WP_Error( 'source_post_not_found', __( 'Source post not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}
		$q = sanitize_text_field( $query );
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			$post_type = $source_post->post_type;
		}
		$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
		if ( $target_blog_id <= 0 ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$switched = false;
		switch_to_blog( $target_blog_id );
		$switched = true;
		try {
			$posts = get_posts( array(
				'post_type' => $post_type,
				'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 10,
				'orderby' => 'date',
				'order' => 'DESC',
				's' => $q,
			) );
			$items = array();
			foreach ( (array) $posts as $post ) {
				$items[] = array(
					'id' => (int) $post->ID,
					'post_title' => (string) $post->post_title,
					'post_name' => (string) $post->post_name,
					'post_status' => (string) $post->post_status,
					'edit_url' => get_edit_post_link( $post->ID, '' ),
					'frontend_url' => get_permalink( $post->ID ),
					'modified' => (string) $post->post_modified,
				);
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		return array( 'items' => $items, 'total' => count( $items ) );
	}

	/**
	 * Attach an existing wp target post to the relation as the translation target.
	 *
	 * Safe mode only: the operation is allowed only when preflight returns allowed=true.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id Relation ID.
	 * @param int $candidate_target_post_id Candidate target post ID.
	 * @return array|\WP_Error
	 */
	public static function attach_existing_translation( int $source_post_id, int $relation_id, int $candidate_target_post_id ) {
		$context = self::get_wp_target_management_context( $source_post_id, $relation_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$preflight = self::preflight_attach_target( $source_post_id, $relation_id, $candidate_target_post_id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		if ( empty( $preflight['allowed'] ) ) {
			return new \WP_Error( 'attach_blocked', implode( ' ', (array) ( $preflight['conflicts'] ?? array() ) ), array( 'status' => 409, 'preflight' => $preflight ) );
		}

		return self::bind_wp_target_translation(
			$context['source_post'],
			$context['relation'],
			$candidate_target_post_id,
			'target_attached',
			array(
				'preflight' => $preflight,
			)
		);
	}


	/**
	 * Safely detach the current wp target from a source/relation pair.
	 *
	 * Detach is non-destructive: it removes relation/source markers and mapping rows,
	 * but keeps the target post itself intact for manual review.
	 *
	 * @since 1.3.5
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id Relation ID.
	 * @param int $expected_target_post_id Expected current target ID.
	 * @return array|\WP_Error
	 */
	public static function detach_existing_translation( int $source_post_id, int $relation_id, int $expected_target_post_id = 0 ) {
		$context = self::get_wp_target_management_context( $source_post_id, $relation_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$current_target_id = self::find_existing_translation( $source_post_id, $relation_id );
		if ( $current_target_id <= 0 ) {
			return new \WP_Error( 'no_attached_target', __( 'No attached target exists for this relation.', 'wpmmcc-ats' ), array( 'status' => 409 ) );
		}
		if ( $expected_target_post_id > 0 && $current_target_id !== $expected_target_post_id ) {
			return new \WP_Error( 'target_mismatch', __( 'The attached target changed before detach could run.', 'wpmmcc-ats' ), array( 'status' => 409, 'current_target_id' => $current_target_id ) );
		}

		$detach = self::detach_wp_target_binding( $context['source_post'], $context['relation'], $current_target_id );
		if ( is_wp_error( $detach ) ) {
			return $detach;
		}

		$removed_mapping_ids = array_map( 'intval', (array) ( $detach['removed_mapping_ids'] ?? array() ) );

		return array(
			'success' => true,
			'relation_id' => $relation_id,
			'target_id' => $current_target_id,
			'detached' => true,
			'target_post_preserved' => true,
			'removed_mapping_ids' => $removed_mapping_ids,
			'mapping_rows_removed_count' => count( $removed_mapping_ids ),
			'summary' => ! empty( $removed_mapping_ids )
				/* translators: %1$d: <value>, %2$s: <value> */
				? sprintf( __( 'Detached target #%1$d and removed mapping rows: %2$s.', 'wpmmcc-ats' ), $current_target_id, implode( ', ', $removed_mapping_ids ) )
			/* translators: target post ID, comma-separated mapping IDs */
				: sprintf( __( 'Detached target #%d while keeping the target post itself intact.', 'wpmmcc-ats' ), $current_target_id ),
		);
	}

	/**
	 * Replace the currently attached target with another candidate target.
	 *
	 * @since 1.3.5
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id Relation ID.
	 * @param int $candidate_target_post_id Candidate replacement target ID.
	 * @param int $expected_current_target_id Expected current target ID.
	 * @return array|\WP_Error
	 */
	public static function replace_existing_translation( int $source_post_id, int $relation_id, int $candidate_target_post_id, int $expected_current_target_id = 0 ) {
		$context = self::get_wp_target_management_context( $source_post_id, $relation_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$current_target_id = self::find_existing_translation( $source_post_id, $relation_id );
		if ( $current_target_id <= 0 ) {
			return new \WP_Error( 'no_attached_target', __( 'No attached target exists for this relation.', 'wpmmcc-ats' ), array( 'status' => 409 ) );
		}
		if ( $expected_current_target_id > 0 && $current_target_id !== $expected_current_target_id ) {
			return new \WP_Error( 'target_mismatch', __( 'The attached target changed before replace could run.', 'wpmmcc-ats' ), array( 'status' => 409, 'current_target_id' => $current_target_id ) );
		}
		if ( $current_target_id === $candidate_target_post_id ) {
			return array(
				'success' => true,
				'relation_id' => $relation_id,
				'target_id' => $candidate_target_post_id,
				'replaced' => false,
				'unchanged' => true,
			);
		}

		$candidate_check = self::validate_wp_target_candidate( $context['source_post'], $context['relation'], $candidate_target_post_id, true );
		if ( is_wp_error( $candidate_check ) ) {
			return $candidate_check;
		}
		if ( empty( $candidate_check['allowed'] ) ) {
			return new \WP_Error( 'replace_blocked', implode( ' ', (array) ( $candidate_check['conflicts'] ?? array() ) ), array( 'status' => 409, 'preflight' => $candidate_check ) );
		}

		$detach = self::detach_wp_target_binding( $context['source_post'], $context['relation'], $current_target_id, 'target_replaced' );
		if ( is_wp_error( $detach ) ) {
			return $detach;
		}

		do_action( 'wptsall_manual_translation_before_replace_attach', $source_post_id, $relation_id, $current_target_id, $candidate_target_post_id );

		$attach = self::bind_wp_target_translation(
			$context['source_post'],
			$context['relation'],
			$candidate_target_post_id,
			'target_attached',
			array(
				'preflight' => $candidate_check,
				'replace_request' => true,
			)
		);
		if ( is_wp_error( $attach ) ) {
			$rollback = self::bind_wp_target_translation(
				$context['source_post'],
				$context['relation'],
				$current_target_id,
				'target_restored_after_failed_replace',
				array(
					'failed_candidate_target_post_id' => $candidate_target_post_id,
					'attach_error' => $attach->get_error_message(),
				)
			);

			$attach_error_data = $attach->get_error_data();
			$status = is_array( $attach_error_data ) && ! empty( $attach_error_data['status'] ) ? (int) $attach_error_data['status'] : 500;
			$rollback_restored = ! is_wp_error( $rollback );
			$message = $rollback_restored
				? __( 'Failed to attach replacement target; original binding restored.', 'wpmmcc-ats' )
				: __( 'Failed to attach replacement target and failed to restore original binding.', 'wpmmcc-ats' );

			return new \WP_Error(
				'replace_attach_failed',
				$message,
				array(
					'status' => $status,
					'attach_error' => $attach->get_error_message(),
					'rollback_restored' => $rollback_restored,
					'rollback_error' => is_wp_error( $rollback ) ? $rollback->get_error_message() : '',
					'candidate_target_post_id' => $candidate_target_post_id,
					'current_target_post_id' => $current_target_id,
				)
			);
		}

		if ( class_exists( '\WPTSALL\Sites\Services\Relation_Admin_Event_Service' ) ) {
			\WPTSALL\Sites\Services\Relation_Admin_Event_Service::log_event(
				$relation_id,
				'target_replaced',
				array(
					'source_post_id' => $source_post_id,
					'previous_target_post_id' => $current_target_id,
					'candidate_target_post_id' => $candidate_target_post_id,
					'preflight' => $candidate_check,
				),
				'post',
				$source_post_id,
				$candidate_target_post_id
			);
		}

		$attach['previous_target_id'] = $current_target_id;
		$attach['replaced'] = true;
		return $attach;
	}

	/**
	 * Rebuild the currently attached wp target back into skeleton state.
	 *
	 * @since 1.3.5
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $relation_id Relation ID.
	 * @param int    $expected_target_post_id Expected current target ID.
	 * @param string $mode Rebuild mode.
	 * @return array|\WP_Error
	 */
	public static function rebuild_existing_translation( int $source_post_id, int $relation_id, int $expected_target_post_id = 0, string $mode = 'reset_translated_only' ) {
		$skeleton = self::build_translation_skeleton_context( $source_post_id, $relation_id );
		if ( is_wp_error( $skeleton ) ) {
			return $skeleton;
		}
		if ( 'wp' !== ( $skeleton['relation']['target_site_type'] ?? 'wp' ) ) {
			return new \WP_Error( 'unsupported_target', __( 'Rebuild currently supports wp target sites only.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}

		$current_target_id = self::find_existing_translation( $source_post_id, $relation_id );
		if ( $current_target_id <= 0 ) {
			return new \WP_Error( 'no_attached_target', __( 'No attached target exists for this relation.', 'wpmmcc-ats' ), array( 'status' => 409 ) );
		}
		if ( $expected_target_post_id > 0 && $current_target_id !== $expected_target_post_id ) {
			return new \WP_Error( 'target_mismatch', __( 'The attached target changed before rebuild could run.', 'wpmmcc-ats' ), array( 'status' => 409, 'current_target_id' => $current_target_id ) );
		}

		$mode = self::normalize_rebuild_mode( $mode );
		if ( '' === $mode ) {
			return new \WP_Error(
				'invalid_rebuild_mode',
				__( 'Invalid rebuild mode supplied.', 'wpmmcc-ats' ),
				array(
					'status' => 400,
					'allowed_modes' => self::get_supported_rebuild_modes(),
				)
			);
		}

		$mode_details = self::get_rebuild_mode_details();
		$selected_mode = is_array( $mode_details[ $mode ] ?? null ) ? $mode_details[ $mode ] : array();
		$summary = '';
		if ( 'reset_translated_only' === $mode ) {
			$result = self::rebuild_wp_target_translated_fields_only(
				$current_target_id,
				$source_post_id,
				$relation_id,
				$skeleton['relation'],
				$skeleton['source_post'],
				$skeleton['post_data'],
				$skeleton['media_fields_data']
			);
			/* translators: 1: target post ID, 2: summary text */
			$summary = sprintf( __( 'Reset translated fields on target #%1$d. %2$s', 'wpmmcc-ats' ), $current_target_id, (string) ( $selected_mode['summary'] ?? '' ) );
		} else {
			$result = self::persist_translation_skeleton(
				$source_post_id,
				$relation_id,
				$skeleton['relation'],
				$skeleton['source_post'],
				$skeleton['post_data'],
				$skeleton['media_fields_data']
			);
			/* translators: 1: target post ID, 2: summary text */
			$summary = sprintf( __( 'Rebuilt target #%1$d back to a fresh skeleton state. %2$s', 'wpmmcc-ats' ), $current_target_id, (string) ( $selected_mode['summary'] ?? '' ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( '\WPTSALL\Sites\Services\Relation_Admin_Event_Service' ) ) {
			\WPTSALL\Sites\Services\Relation_Admin_Event_Service::log_event(
				$relation_id,
				'target_rebuilt',
				array(
					'source_post_id' => $source_post_id,
					'target_post_id' => $result['target_id'] ?? 0,
					'rebuild_mode' => $mode,
					'preserved_sync_fields' => 'reset_translated_only' === $mode,
					'preserved_taxonomies' => 'reset_translated_only' === $mode,
					'preserved_media_fields' => 'reset_translated_only' === $mode,
				),
				'post',
				$source_post_id,
				(int) ( $result['target_id'] ?? 0 )
			);
		}

		$result['rebuilt'] = true;
		$result['rebuild_mode'] = $mode;
		$result['rebuild_mode_details'] = $selected_mode;
		$result['summary'] = $summary;
		$result['allowed_rebuild_modes'] = self::get_supported_rebuild_modes();
		$result['target_binding_preserved'] = true;
		if ( 'reset_translated_only' === $mode ) {
			$result['preserved_sync_fields'] = true;
			$result['preserved_taxonomies'] = true;
			$result['preserved_media_fields'] = true;
		}
		return $result;
	}

	/**
	 * Validate source/relation context for wp target management actions.
	 *
	 * @since 1.3.5
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id Relation ID.
	 * @return array|\WP_Error
	 */
	private static function get_wp_target_management_context( int $source_post_id, int $relation_id ) {
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation ) {
			return new \WP_Error( 'relation_not_found', __( 'Site relation not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}
		if ( 'wp' !== ( $relation['target_site_type'] ?? 'wp' ) ) {
			return new \WP_Error( 'unsupported_target', __( 'This action currently supports wp target sites only.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}
		if ( ! is_multisite() ) {
			return new \WP_Error( 'multisite_required', __( 'This action requires multisite.', 'wpmmcc-ats' ), array( 'status' => 400 ) );
		}
		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return new \WP_Error( 'source_post_not_found', __( 'Source post not found.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
		}

		return array(
			'relation' => $relation,
			'source_post' => $source_post,
			'source_blog_id' => (int) ( $relation['source_site_id'] ?? get_current_blog_id() ),
			'target_blog_id' => (int) ( $relation['target_site_id'] ?? 0 ),
		);
	}

	/**
	 * Resolve the most relevant text domain for manual-editor memory suggestions.
	 *
	 * @since 1.6.3
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $post_type Source post type.
	 * @return string
	 */
	private static function resolve_memory_text_domain( int $relation_id, string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) && class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
			$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );
			foreach ( (array) $models as $model ) {
				$model_id = (int) ( $model['id'] ?? 0 );
				if ( $model_id <= 0 ) {
					continue;
				}
				$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule_by_post_type( $model_id, $post_type );
				if ( empty( $rule ) ) {
					continue;
				}
				$text_domain = sanitize_key( (string) ( $model['text_domain'] ?? $model['plugin_slug'] ?? '' ) );
				if ( '' !== $text_domain ) {
					return $text_domain;
				}
			}
		}

		return 'wordpress-blog';
	}

	/**
	 * Validate a candidate target post for attach/replace flows.
	 *
	 * @since 1.3.5
	 *
	 * @param \WP_Post $source_post Source post object.
	 * @param array    $relation Relation row.
	 * @param int      $candidate_target_post_id Candidate target post ID.
	 * @param bool     $allow_replace_existing Whether an existing current target is allowed.
	 * @return array|\WP_Error
	 */
	private static function validate_wp_target_candidate( $source_post, array $relation, int $candidate_target_post_id, bool $allow_replace_existing = false ) {
		$candidate_target_post_id = (int) $candidate_target_post_id;
		$existing_target_id = self::find_existing_translation( (int) $source_post->ID, (int) $relation['id'] );
		$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
		$source_blog_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$switched = false;
		switch_to_blog( $target_blog_id );
		$switched = true;
		try {
			$target_post = get_post( $candidate_target_post_id );
			if ( ! $target_post ) {
				return new \WP_Error( 'target_post_not_found', __( 'Candidate target post not found on target site.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
			}
			$relation_mapping = \WPTSALL\Models\Services\Post_Mapping_Service::get_reverse_mapping(
				$candidate_target_post_id,
				$target_post->post_type,
				(string) $relation['target_site_id'],
				$source_blog_id,
				(int) $relation['id']
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		$conflicts = array();
		$warnings = array();
		$allowed = true;
		if ( ! $allow_replace_existing && $existing_target_id && $existing_target_id !== $candidate_target_post_id ) {
			$conflicts[] = __( 'Current relation already points to a different target post.', 'wpmmcc-ats' );
			$allowed = false;
		}
		if ( ! empty( $relation_mapping ) && (int) ( $relation_mapping['source_post_id'] ?? 0 ) !== (int) $source_post->ID ) {
			$conflicts[] = __( 'Candidate target is already mapped to another source post in this relation.', 'wpmmcc-ats' );
			$allowed = false;
		}
		if ( $source_post->post_type !== $target_post->post_type ) {
			$conflicts[] = __( 'Source and candidate target post types do not match.', 'wpmmcc-ats' );
			$allowed = false;
		}

		return array(
			'allowed' => $allowed,
			'conflicts' => $conflicts,
			'warnings' => $warnings,
			'existing_target_id' => (int) $existing_target_id,
			'candidate_target_id' => $candidate_target_post_id,
			'candidate_post_type' => (string) $target_post->post_type,
			'candidate_status' => (string) $target_post->post_status,
		);
	}

	/**
	 * Bind a wp target post to the given source/relation pair.
	 *
	 * @param \WP_Post $source_post Source post object.
	 * @param array    $relation Relation row.
	 * @param int      $target_post_id Target post ID.
	 * @param string   $event_type Audit event type.
	 * @param array    $event_context Extra audit payload.
	 * @return array|\WP_Error
	 */
	private static function bind_wp_target_translation( $source_post, array $relation, int $target_post_id, string $event_type = 'target_attached', array $event_context = array() ) {
		$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
		$source_blog_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$relation_id = (int) ( $relation['id'] ?? 0 );

		$switched = false;
		$target_post = null;
		switch_to_blog( $target_blog_id );
		$switched = true;
		try {
			$target_post = get_post( $target_post_id );
			if ( ! $target_post ) {
				return new \WP_Error( 'target_post_not_found', __( 'Target post not found on target site.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
			}

			// Markers must be written on the target blog; mapping upsert is global.
			if ( class_exists( Translation_Identity::class ) ) {
				Translation_Identity::ensure_markers(
					$target_post_id,
					(int) $source_post->ID,
					$relation_id,
					array(
						'relation'        => $relation,
						'source_blog_id'  => $source_blog_id,
						'post_type'       => $source_post->post_type,
						'virtual_site_id' => null,
					)
				);
			} else {
				self::persist_translation_markers( $target_post_id, (int) $source_post->ID, $source_blog_id, $relation_id );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		if ( ! class_exists( Translation_Identity::class ) && class_exists( '\WPTSALL\Models\Services\Post_Mapping_Service' ) ) {
			\WPTSALL\Models\Services\Post_Mapping_Service::create_mapping( array(
				'source_post_id' => (int) $source_post->ID,
				'source_post_type' => $source_post->post_type,
				'source_site_id' => $source_blog_id,
				'relation_id' => $relation_id,
				'target_post_id' => $target_post_id,
				'target_post_type' => $target_post ? $target_post->post_type : $source_post->post_type,
				'target_site_id' => $relation['target_site_id'],
				'relationship_type' => 'translation',
			) );
		}

		if ( '' !== $event_type && class_exists( '\WPTSALL\Sites\Services\Relation_Admin_Event_Service' ) ) {
			\WPTSALL\Sites\Services\Relation_Admin_Event_Service::log_event(
				$relation_id,
				$event_type,
				array_merge(
					array(
						'source_post_id' => (int) $source_post->ID,
						'candidate_target_post_id' => $target_post_id,
					),
					$event_context
				),
				'post',
				(int) $source_post->ID,
				$target_post_id
			);
		}

		return array(
			'success' => true,
			'relation_id' => $relation_id,
			'target_id' => $target_post_id,
			'attached' => true,
		);
	}

	/**
	 * Remove relation/source binding from a wp target post and mapping rows.
	 *
	 * @since 1.3.5
	 *
	 * @param \WP_Post $source_post Source post.
	 * @param array    $relation Relation row.
	 * @param int      $target_post_id Target post ID.
	 * @param string   $event_type Audit event type.
	 * @return array|\WP_Error
	 */
	private static function detach_wp_target_binding( $source_post, array $relation, int $target_post_id, string $event_type = 'target_detached' ) {
		$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
		$source_blog_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$relation_id = (int) ( $relation['id'] ?? 0 );
		$switched = false;
		switch_to_blog( $target_blog_id );
		$switched = true;
		try {
			$target_post = get_post( $target_post_id );
			if ( ! $target_post ) {
				return new \WP_Error( 'target_post_not_found', __( 'Target post not found on target site.', 'wpmmcc-ats' ), array( 'status' => 404 ) );
			}
			Translation_Identity::delete_meta( (int) $target_post_id, Translation_Identity::META_SOURCE_POST_ID );
			Translation_Identity::delete_meta( (int) $target_post_id, Translation_Identity::META_SOURCE_BLOG_ID );
			Translation_Identity::delete_meta( (int) $target_post_id, Translation_Identity::META_RELATION_ID );
			\WPTSALL\Sites\Services\Translation_Identity::delete_meta( (int) $target_post_id, '_wptsall_last_synced' );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		$removed_mapping_ids = array();
		if ( class_exists( '\WPTSALL\Models\Services\Post_Mapping_Service' ) ) {
			$mapping = \WPTSALL\Models\Services\Post_Mapping_Service::get_mapping(
				(int) $source_post->ID,
				(string) $source_post->post_type,
				$source_blog_id,
				(string) $relation['target_site_id'],
				$relation_id
			);
			if ( ! empty( $mapping['id'] ) ) {
				\WPTSALL\Models\Services\Post_Mapping_Service::delete_mapping( (int) $mapping['id'] );
				$removed_mapping_ids[] = (int) $mapping['id'];
			}
		}

		if ( class_exists( '\WPTSALL\Sites\Services\Relation_Admin_Event_Service' ) ) {
			\WPTSALL\Sites\Services\Relation_Admin_Event_Service::log_event(
				$relation_id,
				$event_type,
				array(
					'source_post_id' => (int) $source_post->ID,
					'target_post_id' => $target_post_id,
					'removed_mapping_ids' => $removed_mapping_ids,
				),
				'post',
				(int) $source_post->ID,
				$target_post_id
			);
		}

		return array(
			'target_id' => $target_post_id,
			'removed_mapping_ids' => $removed_mapping_ids,
		);
	}

	/**
	 * Get translation status for a source post across all active relations.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $post_type      Post type (optional, auto-detected if empty).
	 * @return array Array of status entries per relation.
	 */
	public static function get_translation_status( int $source_post_id, string $post_type = '' ): array {
		if ( '' === $post_type ) {
			$post = get_post( $source_post_id );
			if ( ! $post ) {
				return array();
			}
			$post_type = $post->post_type;
		}

		// Get all active relations for the current source site.
		$relations = Site_Relation_Service::get_all_relations(
			array(
				'source_site_id' => get_current_blog_id(),
				'status'         => 'active',
			)
		);

		if ( empty( $relations ) ) {
			return array();
		}

		$statuses = array();

		foreach ( $relations as $relation ) {
			$relation_id   = (int) $relation['id'];
			$target_post_id = self::find_existing_translation( $source_post_id, $relation_id );

			$status = 'missing';

			if ( $target_post_id ) {
				$target_post = self::get_target_post( $target_post_id, $relation );

				if ( $target_post ) {
					$status = ( 'publish' === $target_post->post_status ) ? 'published' : 'draft';
				}
			}

			$statuses[] = array(
				'relation_id'      => $relation_id,
				'target_site_name' => $relation['target_lang'] ?? '',
				'target_site_type' => $relation['target_site_type'] ?? 'wp',
				'target_post_id'   => $target_post_id,
				'status'           => $status,
			);
		}

		return $statuses;
	}

	/**
	 * Find existing translation for a source post in a given relation.
	 *
	 * Uses the same meta query patterns as Sync_Executor.
	 *
	 * @since 1.0.0
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $relation_id    Site relation ID.
	 * @return int|null Target post ID or null if not found.
	 */
	public static function find_existing_translation( int $source_post_id, int $relation_id ): ?int {
		if ( class_exists( Translation_Identity::class ) ) {
			return Translation_Identity::find_target( $source_post_id, $relation_id, true );
		}

		$relation = Site_Relation_Service::get_relation( $relation_id );

		if ( ! $relation ) {
			return null;
		}

		$target_site_type = $relation['target_site_type'] ?? 'wp';

		if ( 'virtual' === $target_site_type ) {
			return self::find_virtual_site_translation( $source_post_id, $relation['target_site_id'], $relation_id );
		}

		return self::find_wp_subsite_translation( $source_post_id, (int) $relation['target_site_id'], $relation_id );
	}

	/**
	 * Find existing translation in a virtual site.
	 *
	 * Matches Sync_Executor::sync_post_to_virtual_site() meta query pattern exactly.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $virtual_site_id Virtual site ID (e.g. "v_zh").
	 * @param int    $relation_id     Site relation ID.
	 * @return int|null Target post ID or null.
	 */
	private static function find_virtual_site_translation( int $source_post_id, string $virtual_site_id, int $relation_id = 0 ): ?int {
		global $wpdb;

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- shadow-post lookup; freshness required, caches would go stale on shadow inserts.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
					INNER JOIN %i pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wptsall_relation_id'
					WHERE pm1.meta_value = %s AND pm2.meta_value = %d AND pm3.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$virtual_site_id,
					$source_post_id,
					$relation_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- shadow-post lookup; freshness required, caches would go stale on shadow inserts.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_virtual_site_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_post_id'
					WHERE pm1.meta_value = %s AND pm2.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$virtual_site_id,
					$source_post_id
				)
			);
		}

		return $existing_id ? (int) $existing_id : null;
	}

	/**
	 * Find existing translation in a WP subsite.
	 *
	 * Queries by _wptsall_source_post_id and _wptsall_source_blog_id meta on the target blog.
	 *
	 * @since 1.0.0
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $target_blog_id Target blog ID.
	 * @param int $relation_id    Site relation ID.
	 * @return int|null Target post ID or null.
	 */
	private static function find_wp_subsite_translation( int $source_post_id, int $target_blog_id, int $relation_id = 0 ): ?int {
		global $wpdb;

		$source_blog_id = get_current_blog_id();
		$switched       = false;
		if ( is_multisite() && $target_blog_id !== $source_blog_id ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		try {
			if ( $relation_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- shadow-post lookup; freshness required, caches would go stale on shadow inserts.
				$existing_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT p.ID FROM %i p
						INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
						INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_blog_id'
						INNER JOIN %i pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wptsall_relation_id'
						WHERE pm1.meta_value = %d AND pm2.meta_value = %d AND pm3.meta_value = %d
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->postmeta,
						$wpdb->postmeta,
						$source_post_id,
						$source_blog_id,
						$relation_id
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- shadow-post lookup; freshness required, caches would go stale on shadow inserts.
				$existing_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT p.ID FROM %i p
						INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
						INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_blog_id'
						WHERE pm1.meta_value = %d AND pm2.meta_value = %d
						LIMIT 1",
						$wpdb->posts,
						$wpdb->postmeta,
						$wpdb->postmeta,
						$source_post_id,
						$source_blog_id
					)
				);
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return $existing_id ? (int) $existing_id : null;
	}

	/**
	 * Save translation to a virtual site.
	 *
	 * Mirrors Sync_Executor::sync_post_to_virtual_site() logic exactly.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param array $relation       Relation data.
	 * @param array $post_data      Post data to insert/update.
	 * @return array|\WP_Error Result with target_id and is_update.
	 */
	/**
	 * Resolve a target-blog attachment id for manual translation workflows.
	 *
	 * Rules:
	 * - explicit user-selected target attachment IDs are allowed only if they exist on target blog
	 * - source-blog attachment IDs must resolve through relation-scoped media mapping
	 * - unresolved source attachment IDs are skipped instead of being written cross-blog
	 *
	 * @since 1.3.6
	 *
	 * @param array $relation Relation row.
	 * @param int   $candidate_id Candidate/source attachment id.
	 * @param bool  $is_explicit_target Whether the value came from user input as target-side selection.
	 * @return int
	 */
	private static function resolve_wp_target_attachment_id( array $relation, int $candidate_id, bool $is_explicit_target = false ): int {
		$candidate_id = absint( $candidate_id );
		if ( $candidate_id <= 0 ) {
			return 0;
		}
		$target_blog_id = (int) ( $relation['target_site_id'] ?? 0 );
		if ( $target_blog_id <= 0 || ! is_multisite() ) {
			return $candidate_id;
		}
		if ( $is_explicit_target ) {
			$switched = false;
			switch_to_blog( $target_blog_id );
			$switched = true;
			try {
				$attachment = get_post( $candidate_id );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
			return ( $attachment && 'attachment' === $attachment->post_type ) ? $candidate_id : 0;
		}
		if ( class_exists( '\WPTSALL\Models\Services\Media_Mapping_Service' ) ) {
			$mapped = \WPTSALL\Models\Services\Media_Mapping_Service::get_mapped_id( (int) ( $relation['id'] ?? 0 ), $candidate_id );
			return $mapped ? (int) $mapped : 0;
		}
		return 0;
	}

	/**
	 * Collect media fields for save/skeleton flows without leaking source-blog attachment IDs.
	 *
	 * @since 1.3.6
	 *
	 * @param \WP_Post $source_post Source post object.
	 * @param array    $relation Relation row.
	 * @param array    $translated_data User-provided translated data.
	 * @param array    $id_mapping_fields Candidate media/id mapping fields.
	 * @param array    $id_mapping_details Field details.
	 * @return array
	 */
	private static function collect_manual_media_fields( $source_post, array $relation, array $translated_data, array $id_mapping_fields, array $id_mapping_details ): array {
		$media_fields_data = array();
		foreach ( $id_mapping_fields as $field_name ) {
			if ( ! self::is_media_id_mapping( $field_name, $id_mapping_details ) ) {
				continue;
			}
			if ( isset( $translated_data[ $field_name ] ) && '' !== $translated_data[ $field_name ] ) {
				$resolved = self::resolve_wp_target_attachment_id( $relation, absint( $translated_data[ $field_name ] ), true );
				if ( $resolved > 0 ) {
					$media_fields_data[ $field_name ] = $resolved;
				}
				continue;
			}
			if ( ! property_exists( $source_post, $field_name ) ) {
				$source_meta = get_post_meta( $source_post->ID, $field_name, true );
				if ( '' !== $source_meta ) {
					$resolved = self::resolve_wp_target_attachment_id( $relation, absint( $source_meta ), false );
					if ( $resolved > 0 ) {
						$media_fields_data[ $field_name ] = $resolved;
					}
				}
			}
		}
		return $media_fields_data;
	}

	private static function save_to_virtual_site( int $source_post_id, array $relation, array $post_data ) {
		$virtual_site_id = $relation['target_site_id'];
		$source_blog_id  = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$relation_id     = (int) $relation['id'];

		// Check if already exists via meta markers (same query as Sync_Executor).
		$existing_id = self::find_virtual_site_translation( $source_post_id, $virtual_site_id, $relation_id );

		if ( $existing_id ) {
			// Update existing post.
			$post_data['ID'] = $existing_id;
			$result_id       = wp_update_post( $post_data, true );

			if ( is_wp_error( $result_id ) ) {
				wptsall_log_error(
					'manual-translation',
					'Failed to update virtual site post',
					array(
						'error'     => $result_id->get_error_message(),
						'target_id' => $existing_id,
					)
				);
				return $result_id;
			}

			$target_id  = $existing_id;
			$is_update  = true;
		} else {
			// Create new post.
			$target_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $target_id ) ) {
				wptsall_log_error(
					'manual-translation',
					'Failed to create virtual site post',
					array( 'error' => $target_id->get_error_message() )
				);
				return $target_id;
			}

			$is_update = false;
		}

		// Persist routing/status markers via direct DB so content-plugin meta filters cannot intercept them.
		self::persist_translation_markers( $target_id, $source_post_id, $source_blog_id, $relation_id, (string) $virtual_site_id );
		self::persist_internal_post_meta( $target_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

		return array(
			'target_id' => $target_id,
			'is_update' => $is_update,
		);
	}

	/**
	 * Save translation to a WP subsite.
	 *
	 * Mirrors Sync_Executor's WP subsite sync pattern.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $source_post_id Source post ID.
	 * @param array $relation       Relation data.
	 * @param array $post_data      Post data to insert/update.
	 * @return array|\WP_Error Result with target_id and is_update.
	 */
	private static function save_to_wp_subsite( int $source_post_id, array $relation, array $post_data ) {
		$target_blog_id = (int) $relation['target_site_id'];
		$source_blog_id = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$relation_id    = (int) $relation['id'];

		$switched = false;
		if ( is_multisite() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		try {
			// Check if already exists via relation-scoped meta markers.
			$existing_id = self::find_wp_subsite_translation_internal( $source_post_id, $source_blog_id, $relation_id );

			if ( $existing_id ) {
				// Update existing post.
				$post_data['ID'] = $existing_id;
				$result_id       = wp_update_post( $post_data, true );

				if ( is_wp_error( $result_id ) ) {
					wptsall_log_error(
						'manual-translation',
						'Failed to update WP subsite post',
						array(
							'error'     => $result_id->get_error_message(),
							'target_id' => $existing_id,
						)
					);
					return $result_id;
				}

				$target_id = $existing_id;
				$is_update = true;
			} else {
				// Create new post.
				$target_id = wp_insert_post( $post_data, true );

				if ( is_wp_error( $target_id ) ) {
					wptsall_log_error(
						'manual-translation',
						'Failed to create WP subsite post',
						array( 'error' => $target_id->get_error_message() )
					);
					return $target_id;
				}

				$is_update = false;
			}

			// Persist routing/status markers via direct DB so content-plugin meta filters cannot intercept them.
			self::persist_translation_markers( $target_id, $source_post_id, $source_blog_id, $relation_id );
			self::persist_internal_post_meta( $target_id, '_wptsall_last_synced', current_time( 'mysql', true ) );

			return array(
				'target_id' => $target_id,
				'is_update' => $is_update,
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Find existing translation in WP subsite (internal, no blog switching).
	 *
	 * Called after switch_to_blog() has already been done.
	 *
	 * @since 1.0.0
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $source_blog_id Source blog ID.
	 * @param int $relation_id    Site relation ID.
	 * @return int|null Target post ID or null.
	 */
	private static function find_wp_subsite_translation_internal( int $source_post_id, int $source_blog_id, int $relation_id = 0 ): ?int {
		global $wpdb;

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- shadow-post lookup; freshness required, caches would go stale on shadow inserts.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_blog_id'
					INNER JOIN %i pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wptsall_relation_id'
					WHERE pm1.meta_value = %d AND pm2.meta_value = %d AND pm3.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$source_post_id,
					$source_blog_id,
					$relation_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- shadow-post lookup; freshness required, caches would go stale on shadow inserts.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wptsall_source_post_id'
					INNER JOIN %i pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wptsall_source_blog_id'
					WHERE pm1.meta_value = %d AND pm2.meta_value = %d
					LIMIT 1",
					$wpdb->posts,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$source_post_id,
					$source_blog_id
				)
			);
		}

		return $existing_id ? (int) $existing_id : null;
	}

	/**
	 * Get a target post object, handling blog switching for WP subsites.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $relation       Relation data.
	 * @return \WP_Post|null Post object or null.
	 */

	/**
	 * Resolve source post ID from a target post.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $relation Relation row.
	 * @return int
	 */
	private static function resolve_source_post_id_from_target( int $target_post_id, array $relation ): int {
		$target_type = (string) ( $relation['target_site_type'] ?? 'wp' );
		$source_post_id = 0;
		$switched = false;
		if ( 'wp' === $target_type && is_multisite() ) {
			switch_to_blog( (int) ( $relation['target_site_id'] ?? 0 ) );
			$switched = true;
		}
		try {
			$source_post_id = absint( \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $target_post_id, \WPTSALL\Sites\Services\Translation_Identity::META_SOURCE_POST_ID ) );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		return $source_post_id;
	}

	private static function get_target_post( int $target_post_id, array $relation ): ?\WP_Post {
		$target_site_type = $relation['target_site_type'] ?? 'wp';

		if ( 'virtual' === $target_site_type ) {
			// Virtual site posts live in the main blog's wp_posts.
			return get_post( $target_post_id );
		}

		// WP subsite: need to switch blog.
		$target_blog_id = (int) $relation['target_site_id'];
		$post           = null;

		$switched = false;
		if ( is_multisite() ) {
			switch_to_blog( $target_blog_id );
			$switched = true;
		}

		try {
			$post = get_post( $target_post_id );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return $post;
	}

	/**
	 * Sync taxonomy term associations from source post to target post.
	 *
	 * For each taxonomy in the model's related_taxonomies:
	 * - Gets source post terms via wp_get_object_terms()
	 * - Maps them to target terms using Term_Mapping_Service::batch_map_terms()
	 * - Sets mapped terms on the target post via wp_set_object_terms()
	 *
	 * Handles both WP subsite targets (with switch_to_blog) and virtual site targets.
	 * Also resolves id_mapping meta fields that reference term IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param int        $source_post_id Source post ID.
	 * @param int        $target_post_id Target post ID.
	 * @param int        $relation_id    Site relation ID.
	 * @param array      $relation       Relation data array.
	 * @param array|null $field_config   Optional pre-fetched field config to avoid redundant DB query.
	 * @return void
	 */
	public static function sync_term_associations( int $source_post_id, int $target_post_id, int $relation_id, array $relation, ?array $field_config = null ): void {
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
			return;
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return;
		}

		// Determine managed taxonomies from the translation rule's related_taxonomies.
		$managed_taxonomies = array();

		if ( null === $field_config ) {
			$field_config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
				$relation_id,
				$source_post->post_type
			);
		}

		// B3: Use enriched config instead of separate query.
		$related_taxonomies = $field_config['related_taxonomies'] ?? array();

		if ( ! empty( $related_taxonomies ) ) {
			$managed_taxonomies = $related_taxonomies;
		} else {
			// Fallback: use all taxonomies registered for this post type.
			$managed_taxonomies = get_object_taxonomies( $source_post->post_type, 'names' );
		}

		if ( empty( $managed_taxonomies ) ) {
			return;
		}

		$source_site_id  = (int) ( $relation['source_site_id'] ?? get_current_blog_id() );
		$target_site_id  = $relation['target_site_id'] ?? '';
		$target_lang     = $relation['target_language'] ?? $relation['target_lang'] ?? 'en_US';
		$target_site_type = $relation['target_site_type'] ?? 'wp';

		// Read all source-side values before switching to the target blog. A
		// source and target can reuse the same numeric post/term IDs, so reading
		// after switch_to_blog() would silently associate the target's terms.
		$source_switched = false;
		if ( is_multisite() && $source_site_id > 0 && $source_site_id !== get_current_blog_id() ) {
			switch_to_blog( $source_site_id );
			$source_switched = true;
		}
		$source_terms_by_taxonomy = array();
		try {
			foreach ( $managed_taxonomies as $taxonomy ) {
				$source_terms = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $source_terms ) && ! empty( $source_terms ) ) {
					$source_terms_by_taxonomy[ $taxonomy ] = $source_terms;
				}
			}
		} finally {
			if ( $source_switched ) {
				restore_current_blog();
			}
		}

		// Resolve id_mapping meta fields that reference term IDs. Capture the
		// source values now, while still in the source-blog context.
		$id_mapping_fields  = $field_config['id_mapping_fields'] ?? array();
		$field_capabilities = $field_config['field_capabilities'] ?? array();
		$term_id_fields     = self::get_term_reference_meta_fields( $id_mapping_fields, $field_capabilities );
		$source_term_values = array();
		if ( ! empty( $term_id_fields ) ) {
			$source_switched = false;
			if ( is_multisite() && $source_site_id > 0 && $source_site_id !== get_current_blog_id() ) {
				switch_to_blog( $source_site_id );
				$source_switched = true;
			}
			try {
				foreach ( $term_id_fields as $field_name ) {
					$source_value = get_post_meta( $source_post_id, $field_name, true );
					if ( ! empty( $source_value ) ) {
						$source_term_values[ $field_name ] = $source_value;
					}
				}
			} finally {
				if ( $source_switched ) {
					restore_current_blog();
				}
			}
		}

		$target_switched = false;
		if ( 'wp' === $target_site_type && is_multisite() && is_numeric( $target_site_id ) ) {
			switch_to_blog( (int) $target_site_id );
			$target_switched = true;
		}
		try {
			foreach ( $source_terms_by_taxonomy as $taxonomy => $source_terms ) {
				$mapped = \WPTSALL\Models\Services\Term_Mapping_Service::batch_map_terms(
					$source_terms,
					$taxonomy,
					$source_site_id,
					(string) $target_site_id,
					$target_lang,
					array(
						'create_if_missing' => true,
						'match_by_slug'     => true,
						'auto_translate'    => false,
						'relation_id'       => $relation_id,
					)
				);

				if ( ! empty( $mapped ) ) {
					wp_set_object_terms( $target_post_id, array_values( $mapped ), $taxonomy );
				}
			}

			foreach ( $source_term_values as $field_name => $source_value ) {
				$mapped_id = \WPTSALL\Models\Services\Term_Mapping_Service::get_mapped_id( $relation_id, (int) $source_value );
				if ( $mapped_id ) {
					update_post_meta( $target_post_id, $field_name, $mapped_id );
				}
			}
		} finally {
			if ( $target_switched ) {
				restore_current_blog();
			}
		}

		wptsall_log_debug(
			'manual-translation',
			'Term associations synced',
			array(
				'source_post_id' => $source_post_id,
				'target_post_id' => $target_post_id,
				'relation_id'    => $relation_id,
				'taxonomies'     => $managed_taxonomies,
			)
		);
	}

	/**
	 * Get related taxonomies for a post_type from translation rules.
	 *
	 * @since 1.1.0
	 * @deprecated 1.3.0 Use $field_config['related_taxonomies'] from get_merged_config_for_relation() instead (B3).
	 *
	 * @param int    $relation_id Site relation ID.
	 * @param string $post_type   Post type name.
	 * @return array Array of taxonomy names, or empty array.
	 */
	private static function get_related_taxonomies_for_post_type( int $relation_id, string $post_type ): array {
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) ) {
			return array();
		}

		$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );

		if ( empty( $models ) ) {
			return array();
		}

		foreach ( $models as $model ) {
			$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule_by_post_type(
				(int) $model['id'],
				$post_type
			);

			if ( $rule && ! empty( $rule['related_taxonomies'] ) ) {
				return $rule['related_taxonomies'];
			}
		}

		return array();
	}

	/**
	 * Get raw field_capabilities from the translation rule for a relation and post type.
	 *
	 * Returns the per-field config array from the translation rule's field_capabilities
	 * column. Each key is a field name, each value is an array with 'type', 'reference_type',
	 * 'value_format', etc. This provides the detail needed to determine field reference types
	 * without pattern guessing.
	 *
	 * @since 1.3.0
	 * @deprecated 1.3.0 Use $field_config['field_capabilities'] from get_merged_config_for_relation() instead (B3).
	 *
	 * @param int    $relation_id Site relation ID.
	 * @param string $post_type   Post type name.
	 * @return array Raw field_capabilities keyed by field name, or empty array.
	 */
	private static function get_field_capabilities_for_relation( int $relation_id, string $post_type ): array {
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) ) {
			return array();
		}

		$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );

		if ( empty( $models ) ) {
			return array();
		}

		foreach ( $models as $model ) {
			$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule_by_post_type(
				(int) $model['id'],
				$post_type
			);

			if ( $rule && ! empty( $rule['field_capabilities'] ) ) {
				return is_array( $rule['field_capabilities'] ) ? $rule['field_capabilities'] : array();
			}
		}

		return array();
	}

	/**
	 * Get meta field names that are known to store term IDs.
	 *
	 * Filters id_mapping fields to those that reference taxonomy terms
	 * (e.g., _yoast_wpseo_primary_category, rank_math_primary_category).
	 *
	 * Uses field_capabilities config when available to check reference_type == 'term'.
	 * Falls back to Id_Mapping_Resolver::infer_reference_type() for backward compatibility
	 * with older data that lacks explicit reference_type.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Uses Id_Mapping_Resolver instead of regex patterns.
	 *
	 * @param array $id_mapping_fields  All id_mapping field names.
	 * @param array $field_capabilities Raw field_capabilities from translation rule (optional).
	 * @return array Subset of field names that store term IDs.
	 */
	private static function get_term_reference_meta_fields( array $id_mapping_fields, array $field_capabilities = array() ): array {
		$term_fields = array();

		foreach ( $id_mapping_fields as $field_name ) {
			$cap = $field_capabilities[ $field_name ] ?? null;

			if ( is_array( $cap ) && ! empty( $cap['reference_type'] ) ) {
				// v2 object format with explicit reference_type.
				if ( 'term' === $cap['reference_type'] ) {
					$term_fields[] = $field_name;
				}
				continue;
			}

			// v1 string format or missing config -- fall back to inference.
			$inferred = \WPTSALL\Models\Services\Id_Mapping_Resolver::infer_reference_type( $field_name );
			if ( 'term' === $inferred ) {
				$term_fields[] = $field_name;
			}
		}

		return $term_fields;
	}

	/**
	 * Check if a field is a media id_mapping using id_mapping_details from merged config.
	 *
	 * Preferred over is_media_field() because id_mapping_details comes directly from
	 * the merged config and includes scanner-detected reference_type metadata.
	 * Falls back to is_media_field() when the field is not present in id_mapping_details.
	 *
	 * @since 1.4.0
	 *
	 * @param string $field_name         Field name to check.
	 * @param array  $id_mapping_details id_mapping_details from merged config (field_name => detail array).
	 * @return bool True if the field is a media/attachment id_mapping field.
	 */
	private static function is_media_id_mapping( string $field_name, array $id_mapping_details ): bool {
		if ( isset( $id_mapping_details[ $field_name ]['reference_type'] ) ) {
			return 'media' === $id_mapping_details[ $field_name ]['reference_type'];
		}
		// Fallback to legacy pattern matching.
		return self::is_media_field( $field_name );
	}

	/**
	 * Check if a field name represents a media/attachment field.
	 *
	 * Detects fields that store attachment IDs (featured image, ACF image fields, etc.).
	 *
	 * Uses field_capabilities config when available to check reference_type == 'media'.
	 * Falls back to Id_Mapping_Resolver::infer_reference_type() for backward compatibility
	 * with older data that lacks explicit reference_type.
	 *
	 * @since 1.1.0
	 * @since 1.3.0 Uses Id_Mapping_Resolver instead of hardcoded patterns.
	 *
	 * @param string $field_name        Field name to check.
	 * @param array  $field_capabilities Raw field_capabilities from translation rule (optional).
	 * @param mixed  $sample_value Optional submitted/source value; non-numeric values must not be coerced by name-only fallback.
	 * @return bool True if the field is a media/attachment field.
	 */
	private static function is_media_field( string $field_name, array $field_capabilities = array(), $sample_value = null ): bool {
		$cap = $field_capabilities[ $field_name ] ?? null;

		if ( is_array( $cap ) && ! empty( $cap['reference_type'] ) ) {
			// v2 object format with explicit reference_type.
			return 'media' === $cap['reference_type'];
		}

		// v1 string format or missing config -- fall back to inference only for
		// numeric ID-looking values when a value sample is available. This avoids
		// converting ordinary text fields such as manual_matrix_meta_envira_gallery_lite
		// to 0 merely because their key contains words like gallery/image/photo.
		if ( null !== $sample_value ) {
			if ( is_array( $sample_value ) || is_object( $sample_value ) || is_bool( $sample_value ) ) {
				return false;
			}
			if ( ! is_numeric( $sample_value ) ) {
				return false;
			}
		}

		return 'media' === \WPTSALL\Models\Services\Id_Mapping_Resolver::infer_reference_type( $field_name );
	}
}
