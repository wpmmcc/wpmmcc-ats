<?php
/**
 * Field Processor
 *
 * Core field processing engine for translation and sync operations.
 * Handles all field types: translate, sync, id_mapping, compute.
 *
 * @package WPTSALL\Models\Services
 * @since 0.5.0
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field Processor Class
 */
class Field_Processor {

	/**
	 * Translation rule configuration
	 *
	 * @var array
	 */
	private $rule_config = array();

	/**
	 * Source site ID
	 *
	 * @var int
	 */
	private $source_site_id;

	/**
	 * Target site ID
	 *
	 * @var string
	 */
	private $target_site_id;

	/**
	 * Target language
	 *
	 * @var string
	 */
	private $target_lang;

	/**
	 * Media handling mode (copy/reference)
	 *
	 * @var string
	 * @since 0.8.0
	 */
	private $media_handling = 'copy';

	/**
	 * Site relation ID for id_mapping resolution
	 *
	 * @var int
	 * @since 1.3.0
	 */
	private $relation_id = 0;

	/**
	 * Constructor
	 *
	 * @param array  $rule_config    Translation rule configuration.
	 * @param int    $source_site_id Source site ID.
	 * @param string $target_site_id Target site ID.
	 * @param string $target_lang    Target language.
	 * @param array  $options        Additional options (media_handling, etc.).
	 */
	public function __construct( $rule_config, $source_site_id, $target_site_id, $target_lang, $options = array() ) {
		$this->rule_config    = $rule_config;
		$this->source_site_id = $source_site_id;
		$this->target_site_id = $target_site_id;
		$this->target_lang    = $target_lang;

		// v0.8.0: Support media handling mode from site relation config.
		if ( ! empty( $options['media_handling'] ) ) {
			$this->media_handling = $options['media_handling'];
		}

		// v1.3.0: Accept relation_id for Id_Mapping_Resolver, with fallback lookup.
		if ( ! empty( $options['relation_id'] ) ) {
			$this->relation_id = absint( $options['relation_id'] );
		} else {
			$this->relation_id = $this->lookup_relation_id( $source_site_id, $target_site_id );
		}

		wptsall_log_debug(
			'models',
			'Field_Processor initialized',
			array(
				'source_site_id' => $source_site_id,
				'target_site_id' => $target_site_id,
				'target_lang'    => $target_lang,
				'media_handling' => $this->media_handling,
				'relation_id'    => $this->relation_id,
			)
		);
	}

	/**
	 * Process all fields for a source entity
	 *
	 * @param array $source_data Source data (post fields, meta, taxonomies).
	 * @return array Processed target data.
	 */
	public function process_fields( $source_data ) {
		$start_time  = microtime( true );
		$target_data = array();

		wptsall_log_debug(
			'models',
			'Processing fields started',
			array(
				'has_post_data'  => ! empty( $source_data['post'] ),
				'has_meta_data'  => ! empty( $source_data['meta'] ),
				'has_taxonomies' => ! empty( $source_data['taxonomies'] ),
				'meta_keys'      => ! empty( $source_data['meta'] ) ? array_keys( $source_data['meta'] ) : array(),
			)
		);

		// 1. Process translate fields (flat map from Sync_Executor).
		$translate_config = json_decode( $this->rule_config['translate_fields'] ?? '{}', true );
		if ( ! empty( $translate_config ) ) {
			$translated  = $this->process_translate_fields( $source_data, $translate_config );
			$target_data = array_merge( $target_data, $translated );
		}

		// 2. Process sync fields (flat map from Sync_Executor).
		$sync_config = json_decode( $this->rule_config['sync_fields'] ?? '{}', true );
		if ( ! empty( $sync_config ) ) {
			$synced      = $this->process_sync_fields( $source_data, $sync_config );
			$target_data = array_merge( $target_data, $synced );
		}

		// 3. Process field_mappings via Id_Mapping_Resolver (v1.3.0).
		$field_mappings_config = json_decode( $this->rule_config['field_mappings'] ?? '{}', true );
		if ( ! empty( $field_mappings_config ) ) {
			$resolved    = $this->process_field_mappings( $source_data, $field_mappings_config );
			$target_data = array_merge( $target_data, $resolved );
		}

		// 4. Process compute fields (flat map from Sync_Executor).
		$compute_config = json_decode( $this->rule_config['compute_fields'] ?? '{}', true );
		if ( ! empty( $compute_config ) ) {
			$computed    = $this->process_compute_fields( $target_data, $compute_config );
			$target_data = array_merge( $target_data, $computed );
		}

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );
		wptsall_log_info(
			'models',
			'Processing fields completed',
			array(
				'output_keys' => array_keys( $target_data ),
				'duration_ms' => $duration_ms,
			)
		);

		return $target_data;
	}

	/**
	 * Process translate fields
	 *
	 * Accepts a flat map produced by Sync_Executor::build_rule_config_from_merged():
	 *   { "post_title": {"type":"translate","source":"post"}, "_yoast_title": {"type":"translate","source":"meta","meta_key":"_yoast_title"} }
	 *
	 * @param array $source_data      Source data.
	 * @param array $translate_config Flat map of field_name => {type, source, meta_key?, content_type?}.
	 * @return array Translated fields.
	 */
	private function process_translate_fields( $source_data, $translate_config ) {
		$translated = array();
		$core_count = 0;
		$meta_count = 0;

		foreach ( $translate_config as $field_name => $field_config ) {
			$source_type  = $field_config['source'] ?? 'meta';
			$content_type = $field_config['content_type'] ?? 'text';

			if ( 'post' === $source_type ) {
				$source_val = $source_data['post'][ $field_name ] ?? '';
				if ( ! empty( $source_val ) ) {
					$translated[ $field_name ] = $this->translate_text( $source_val, $content_type );
					++$core_count;
				}
			} else {
				$meta_key   = $field_config['meta_key'] ?? $field_name;
				$source_val = $source_data['meta'][ $meta_key ] ?? '';
				if ( ! empty( $source_val ) ) {
					if ( ! isset( $translated['meta'] ) ) {
						$translated['meta'] = array();
					}
					$translated['meta'][ $meta_key ] = $this->translate_text( $source_val, $content_type );
					++$meta_count;
				}
			}
		}

		wptsall_log_debug(
			'models',
			'Translate fields processed',
			array(
				'core_fields_count' => $core_count,
				'meta_fields_count' => $meta_count,
			)
		);

		return $translated;
	}

	/**
	 * Process sync fields
	 *
	 * Accepts a flat map produced by Sync_Executor::build_rule_config_from_merged():
	 *   { "post_date": {"type":"sync","source":"post"}, "_sku": {"type":"sync","source":"meta","meta_key":"_sku"} }
	 *
	 * @param array $source_data Source data.
	 * @param array $sync_config Flat map of field_name => {type, source, meta_key?}.
	 * @return array Synced fields.
	 */
	private function process_sync_fields( $source_data, $sync_config ) {
		$synced = array();

		foreach ( $sync_config as $field_name => $field_config ) {
			$source_type = $field_config['source'] ?? 'meta';

			if ( 'post' === $source_type ) {
				$value = $source_data['post'][ $field_name ] ?? null;
				if ( null !== $value ) {
					$synced[ $field_name ] = $value;
				}
			} else {
				$meta_key = $field_config['meta_key'] ?? $field_name;
				$value    = $source_data['meta'][ $meta_key ] ?? null;
				if ( null !== $value ) {
					if ( ! isset( $synced['meta'] ) ) {
						$synced['meta'] = array();
					}
					$synced['meta'][ $meta_key ] = $value;
				}
			}
		}

		return $synced;
	}

	/**
	 * Process field_mappings via Id_Mapping_Resolver.
	 *
	 * Reads the `field_mappings` key from the rule config (produced by
	 * Sync_Executor::build_rule_config_from_merged) and resolves each
	 * id_mapping field through the unified Id_Mapping_Resolver.
	 *
	 * Each entry in $field_mappings_config is keyed by field_name with a
	 * config array that may contain: type, reference_type, reference_target,
	 * value_format, source (post/meta), meta_key.
	 *
	 * @since 1.3.0
	 *
	 * @param array $source_data           Source data (post, meta, taxonomies).
	 * @param array $field_mappings_config Field mappings configuration keyed by field name.
	 * @return array Resolved fields ready to merge into target_data.
	 */
	private function process_field_mappings( $source_data, $field_mappings_config ) {
		$resolved_data = array();

		$context = array(
			'relation_id'    => $this->relation_id,
			'source_blog_id' => $this->source_site_id,
			'target_blog_id' => $this->target_site_id,
			'target_lang'    => $this->target_lang,
		);

		$resolved_count = 0;
		$failed_count   = 0;

		foreach ( $field_mappings_config as $field_name => $field_config ) {
			// Determine where to read the source value.
			$source_type = $field_config['source'] ?? 'meta';
			$meta_key    = $field_config['meta_key'] ?? $field_name;

			$source_value = null;
			if ( 'post' === $source_type ) {
				$source_value = $source_data['post'][ $field_name ] ?? null;
			} else {
				// Meta fields: raw meta can be an array with [0] element.
				$raw = $source_data['meta'][ $meta_key ] ?? null;
				if ( is_array( $raw ) && ! isset( $raw['type'] ) ) {
					// WordPress get_post_meta returns array( [0] => value ).
					$source_value = $raw[0] ?? null;
				} else {
					$source_value = $raw;
				}
			}

			// Skip empty values.
			if ( null === $source_value || '' === $source_value ) {
				continue;
			}

			$result = \WPTSALL\Models\Services\Id_Mapping_Resolver::resolve(
				$field_name,
				$source_value,
				$field_config,
				$context
			);

			if ( ! empty( $result['resolved'] ) && 'passthrough' !== $result['method'] ) {
				++$resolved_count;
			} elseif ( ! empty( $result['error'] ) ) {
				++$failed_count;
				wptsall_log_warning(
					'models',
					'Field mapping resolution partial failure',
					array(
						'field' => $field_name,
						'error' => $result['error'],
					)
				);
			}

			// Place the resolved value in the correct output location.
			$target_value = $result['target_value'];
			if ( 'post' === $source_type ) {
				$resolved_data[ $field_name ] = $target_value;
			} else {
				if ( ! isset( $resolved_data['meta'] ) ) {
					$resolved_data['meta'] = array();
				}
				$resolved_data['meta'][ $meta_key ] = $target_value;
			}
		}

		wptsall_log_debug(
			'models',
			'Field mappings processed via Id_Mapping_Resolver',
			array(
				'total_fields'   => count( $field_mappings_config ),
				'resolved_count' => $resolved_count,
				'failed_count'   => $failed_count,
				'relation_id'    => $this->relation_id,
			)
		);

		return $resolved_data;
	}

	/**
	 * Look up relation_id from source and target site IDs.
	 *
	 * Fallback when relation_id is not explicitly provided in constructor options.
	 * Queries the site_relations table to find a matching relation.
	 *
	 * @since 1.3.0
	 *
	 * @param int|string $source_site_id Source site ID.
	 * @param int|string $target_site_id Target site ID.
	 * @return int Relation ID or 0 if not found.
	 */
	private function lookup_relation_id( $source_site_id, $target_site_id ) {
		if ( ! function_exists( 'wptsall_table' ) ) {
			return 0;
		}

		global $wpdb;

		// This is a FALLBACK — callers should pass relation_id explicitly.
		wptsall_log_debug( 'models', 'Field_Processor using relation_id lookup fallback', array(
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
		) );

		$table = wptsall_table( 'site_relations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i
				WHERE source_site_id = %d
				AND target_site_id = %s
				AND status = 'active'
				ORDER BY id DESC
				LIMIT 1",
				$table,
				absint( $source_site_id ),
				$target_site_id
			)
		);

		return $relation_id ? absint( $relation_id ) : 0;
	}

	/**
	 * Process compute fields
	 *
	 * Accepts a flat map produced by Sync_Executor::build_rule_config_from_merged():
	 *   { "post_name": {"type":"compute","method":"slug"}, "guid": {"type":"compute","method":"auto"} }
	 *
	 * @param array $target_data    Target data so far.
	 * @param array $compute_config Flat map of field_name => {type, method, source_field?}.
	 * @return array Computed fields.
	 */
	private function process_compute_fields( $target_data, $compute_config ) {
		$computed = array();

		foreach ( $compute_config as $field_name => $compute_field ) {
			$compute_method = $compute_field['method'] ?? 'auto';

			switch ( $compute_method ) {
				case 'slug':
					// Generate slug from title.
					$title = $target_data['post_title'] ?? '';
					if ( ! empty( $title ) ) {
						$computed[ $field_name ] = sanitize_title( $title );
						wptsall_log_debug(
							'models',
							'Computed slug field',
							array(
								'field' => $field_name,
								'title' => mb_substr( $title, 0, 50 ),
								'slug'  => $computed[ $field_name ],
							)
						);
					}
					break;

				case 'auto_increment':
					// Auto-increment based on existing posts.
					wptsall_log_debug(
						'models',
						'Compute field skipped - auto_increment not implemented',
						array( 'field' => $field_name )
					);
					break;

				case 'copy_from':
					// Copy from another field.
					$source_field = $compute_field['source_field'] ?? '';
					if ( ! empty( $source_field ) && isset( $target_data[ $source_field ] ) ) {
						$computed[ $field_name ] = $target_data[ $source_field ];
						wptsall_log_debug(
							'models',
							'Computed copy_from field',
							array(
								'field'        => $field_name,
								'source_field' => $source_field,
							)
						);
					}
					break;
			}
		}

		if ( ! empty( $computed ) ) {
			wptsall_log_debug(
				'models',
				'Compute fields processed',
				array(
					'computed_fields' => array_keys( $computed ),
				)
			);
		}

		return $computed;
	}

	/**
	 * Translate text
	 *
	 * @param string $text         Text to translate.
	 * @param string $content_type Content type (text/html/preserve_html).
	 * @return string Translated text.
	 */
	private function translate_text( $text, $content_type = 'text' ) {
		// TODO: Integrate with translation service API
		// For now, return placeholder translated text

		switch ( $content_type ) {
			case 'html':
				// Full HTML translation
				return $text; // Placeholder

			case 'preserve_html':
				// Translate text but preserve HTML tags
				return $text; // Placeholder

			case 'preserve_shortcodes':
				// Translate text but preserve shortcodes
				return $text; // Placeholder

			case 'text':
			default:
				// Plain text translation
				return $text; // Placeholder
		}
	}

	/**
	 * Get field processing summary
	 *
	 * @return array Summary of what was processed.
	 */
	public function get_processing_summary() {
		$translate_config      = json_decode( $this->rule_config['translate_fields'] ?? '{}', true );
		$sync_config           = json_decode( $this->rule_config['sync_fields'] ?? '{}', true );
		$field_mappings_config = json_decode( $this->rule_config['field_mappings'] ?? '{}', true );
		$compute_config        = json_decode( $this->rule_config['compute_fields'] ?? '{}', true );

		// Build translate_fields list for apply_translation_markers() (Path A).
		$translate_fields = array();
		foreach ( $translate_config as $field_name => $field_config ) {
			$translate_fields[] = array(
				'field' => $field_name,
				'type'  => $field_config['content_type'] ?? 'text',
			);
		}

		return array(
			'translate_fields_count' => count( $translate_config ),
			'translate_fields'       => $translate_fields,
			'sync_fields_count'      => count( $sync_config ),
			'field_mappings_count'   => count( $field_mappings_config ),
			'compute_fields_count'   => count( $compute_config ),
			'target_lang'            => $this->target_lang,
			'target_site_id'         => $this->target_site_id,
			'media_handling'         => $this->media_handling,
			'relation_id'            => $this->relation_id,
		);
	}

	/**
	 * Get current media handling mode
	 *
	 * @return string Media handling mode (copy/reference).
	 * @since 0.8.0
	 */
	public function get_media_handling() {
		return $this->media_handling;
	}
}
