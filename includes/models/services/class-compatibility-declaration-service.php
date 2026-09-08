<?php
/**
 * Compatibility Declaration Service
 *
 * Normalizes additive compatibility declarations into a single model-facing
 * registry so scanners and admin tooling do not couple directly to any one
 * vendor format.
 *
 * @package WPTSALL
 * @since 1.6.2
 */

namespace WPTSALL\Models\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility_Declaration_Service class.
 */
class Compatibility_Declaration_Service {

	/**
	 * Get normalized field declarations for a specific post type.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function get_field_declarations_for_post_type( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$rows      = self::get_wpml_field_declarations( $post_type );

		/**
		 * Filter normalized compatibility field declarations.
		 *
		 * @param array  $rows      Declaration rows.
		 * @param string $post_type Post type context.
		 */
		$rows = apply_filters( 'wptsall_model_compatibility_field_declarations', $rows, $post_type );

		return self::normalize_declaration_rows( is_array( $rows ) ? $rows : array(), $post_type );
	}

	/**
	 * Get normalized field declarations across all scopes.
	 *
	 * @return array
	 */
	public static function get_all_field_declarations(): array {
		$rows = self::get_wpml_field_declarations( '' );

		/**
		 * Filter all normalized compatibility field declarations.
		 *
		 * @param array  $rows      Declaration rows.
		 * @param string $post_type Empty post type means all scopes.
		 */
		$rows = apply_filters( 'wptsall_model_compatibility_field_declarations', $rows, '' );

		return self::normalize_declaration_rows( is_array( $rows ) ? $rows : array(), '' );
	}

	/**
	 * Get registry providers currently contributing declarations.
	 *
	 * @return array
	 */
	public static function get_registry_sources(): array {
		return array_values(
			array_unique(
				array_map(
					'sanitize_key',
					array_filter(
						array_column( self::get_all_field_declarations(), 'provider' )
					)
				)
			)
		);
	}

	/**
	 * Load declarations from wpml-config.xml sources.
	 *
	 * @param string $post_type Post type context, or empty for all.
	 * @return array
	 */
	private static function get_wpml_field_declarations( string $post_type ): array {
		if ( ! class_exists( '\WPTSALL\Core\WPML_Config_Reader' ) ) {
			return array();
		}

		$configs      = \WPTSALL\Core\WPML_Config_Reader::get_all_configs();
		$declarations = array();
		$matched      = false;

		foreach ( $configs as $provider_key => $config ) {
			$custom_types = is_array( $config['custom-types'] ?? null ) ? $config['custom-types'] : array();
			$fields       = is_array( $config['custom-fields'] ?? null ) ? $config['custom-fields'] : array();

			if ( '' !== $post_type && ! empty( $custom_types ) && ! isset( $custom_types[ $post_type ] ) ) {
				continue;
			}

			if ( '' !== $post_type ) {
				$matched = true;
			}

			foreach ( $fields as $field_name => $action ) {
				$declarations[] = self::build_declaration_row(
					(string) $field_name,
					(string) $action,
					(string) $provider_key,
					'' !== $post_type ? $post_type : ( ! empty( $custom_types ) ? implode( ',', array_keys( $custom_types ) ) : '*' )
				);
			}
		}

		if ( '' !== $post_type && ! $matched ) {
			foreach ( $configs as $provider_key => $config ) {
				$custom_types = is_array( $config['custom-types'] ?? null ) ? $config['custom-types'] : array();
				// Provider already scoped to other CPTs — do not dump those fields onto this type.
				if ( ! empty( $custom_types ) ) {
					continue;
				}
				$fields = is_array( $config['custom-fields'] ?? null ) ? $config['custom-fields'] : array();
				foreach ( $fields as $field_name => $action ) {
					$declarations[] = self::build_declaration_row( (string) $field_name, (string) $action, (string) $provider_key, '*' );
				}
			}
		}

		return $declarations;
	}

	/**
	 * Build one normalized declaration row.
	 *
	 * @param string $field_name     Field name.
	 * @param string $action         Declared action.
	 * @param string $provider_key   Provider key.
	 * @param string $post_type_hint Post type hint.
	 * @return array
	 */
	private static function build_declaration_row( string $field_name, string $action, string $provider_key, string $post_type_hint ): array {
		$field_name = sanitize_text_field( $field_name );
		$capability = sanitize_key( $action ?: 'sync' );

		return array(
			'field_kind'        => 'meta',
			'field_key'         => $field_name,
			'capability'        => $capability,
			'source_origin'     => 'wpml_config_xml',
			'source_detail'     => sprintf( 'provider:%s;action:%s;scope:%s', sanitize_text_field( $provider_key ), $capability, sanitize_text_field( $post_type_hint ) ),
			'confidence_score'  => 92,
			'confidence_reason' => 'Declared by compatibility registry',
			'provider'          => 'wpml_config_xml',
			'provider_key'      => sanitize_text_field( $provider_key ),
			'declaration_type'  => 'custom_field',
			'extra'             => array(
				'declaration_provider'     => 'wpml_config_xml',
				'declaration_provider_key' => sanitize_text_field( $provider_key ),
				'declaration_type'         => 'custom_field',
				'declared_action'          => $capability,
				'post_type_scope'          => sanitize_text_field( $post_type_hint ),
			),
		);
	}


	/**
	 * Get supported discovery field kinds.
	 *
	 * @return array
	 */
	private static function get_allowed_field_kinds(): array {
		return array( 'core', 'meta', 'column', 'block_attr', 'shortcode_attr', 'config' );
	}

	/**
	 * Infer discovery field kind from declaration type.
	 *
	 * @param string $declaration_type Declaration type.
	 * @return string
	 */
	private static function infer_field_kind_from_declaration_type( string $declaration_type ): string {
		$declaration_type = sanitize_key( $declaration_type );

		if ( 'block_subtree' === $declaration_type ) {
			return 'block_attr';
		}

		if ( 'shortcode_arg' === $declaration_type ) {
			return 'shortcode_attr';
		}

		if ( 'config_object' === $declaration_type ) {
			return 'config';
		}

		return 'meta';
	}

	/**
	 * Normalize declaration extra payload and widen supported top-level inputs.
	 *
	 * @param array  $row Declaration row.
	 * @param string $post_type Post type context.
	 * @return array
	 */
	private static function normalize_declaration_extra( array $row, string $post_type ): array {
		$extra = is_array( $row['extra'] ?? null ) ? $row['extra'] : array();
		$declaration_type = sanitize_key( (string) ( $row['declaration_type'] ?? '' ) );

		foreach ( array( 'path', 'paths', 'item_shape', 'repeater', 'block_name', 'block_names', 'shortcode_tag', 'shortcode_tags', 'arg', 'args', 'config_key', 'config_path', 'object_key', 'selector', 'subtree', 'subtree_root', 'subtree_path', 'source_group', 'routing_profile', 'delivery_target', 'source_role', 'source_profile', 'message_template_part' ) as $key ) {
			if ( array_key_exists( $key, $row ) && ! array_key_exists( $key, $extra ) ) {
				$extra[ $key ] = $row[ $key ];
			}
		}

		if ( 'message_template' === $declaration_type ) {
			if ( ! array_key_exists( 'source_group', $extra ) ) {
				$extra['source_group'] = 'message_template';
			}
			if ( ! array_key_exists( 'delivery_target', $extra ) ) {
				$extra['delivery_target'] = 'message_template_writeback';
			}
		}

		if ( 'config_object' === $declaration_type ) {
			if ( ! array_key_exists( 'source_group', $extra ) ) {
				$extra['source_group'] = 'config_object';
			}
			if ( ! array_key_exists( 'delivery_target', $extra ) ) {
				$extra['delivery_target'] = 'config_writeback';
			}
		}

		if ( '' !== $post_type ) {
			$extra['post_type_context'] = $post_type;
		}

		return self::sanitize_extra_payload( $extra );
	}

	/**
	 * Sanitize nested extra payload values while preserving structure.
	 *
	 * @param mixed $value Payload value.
	 * @return mixed
	 */
	private static function sanitize_extra_payload( $value ) {
		if ( is_array( $value ) ) {
			$normalized = array();
			foreach ( $value as $key => $item ) {
				$normalized_key = is_string( $key ) ? sanitize_key( $key ) : $key;
				$normalized[ $normalized_key ] = self::sanitize_extra_payload( $item );
			}
			return $normalized;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Normalize and de-duplicate declaration rows.
	 *
	 * @param array  $rows      Declaration rows.
	 * @param string $post_type Post type context.
	 * @return array
	 */
	private static function normalize_declaration_rows( array $rows, string $post_type ): array {
		$normalized = array();
		$allowed_field_kinds = self::get_allowed_field_kinds();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field_key = sanitize_text_field( (string) ( $row['field_key'] ?? '' ) );
			if ( '' === $field_key ) {
				continue;
			}

			$declaration_type = sanitize_key( (string) ( $row['declaration_type'] ?? 'field' ) );
			$field_kind = sanitize_key( (string) ( $row['field_kind'] ?? self::infer_field_kind_from_declaration_type( $declaration_type ) ) );
			if ( ! in_array( $field_kind, $allowed_field_kinds, true ) ) {
				$field_kind = 'meta';
			}

			$item = array(
				'field_kind'        => $field_kind,
				'field_key'         => $field_key,
				'capability'        => sanitize_key( (string) ( $row['capability'] ?? 'sync' ) ),
				'source_origin'     => sanitize_key( (string) ( $row['source_origin'] ?? 'compatibility_registry' ) ),
				'source_detail'     => sanitize_textarea_field( (string) ( $row['source_detail'] ?? '' ) ),
				'confidence_score'  => max( 0, min( 100, (int) ( $row['confidence_score'] ?? 90 ) ) ),
				'confidence_reason' => sanitize_textarea_field( (string) ( $row['confidence_reason'] ?? 'Compatibility declaration' ) ),
				'provider'          => sanitize_key( (string) ( $row['provider'] ?? 'compatibility_registry' ) ),
				'provider_key'      => sanitize_text_field( (string) ( $row['provider_key'] ?? '' ) ),
				'declaration_type'  => $declaration_type,
				'extra'             => self::normalize_declaration_extra( $row, $post_type ),
			);

			$key = $field_kind . ':' . $field_key;
			if ( ! isset( $normalized[ $key ] ) ) {
				$normalized[ $key ] = $item;
				continue;
			}

			$existing = $normalized[ $key ];
			$existing['confidence_score'] = max( (int) $existing['confidence_score'], (int) $item['confidence_score'] );
			if ( (int) $item['confidence_score'] >= (int) $existing['confidence_score'] && '' !== $item['confidence_reason'] ) {
				$existing['confidence_reason'] = $item['confidence_reason'];
			}
			$details = array_filter( array_unique( array_filter( array_map( 'trim', array_merge( explode( '|', (string) $existing['source_detail'] ), explode( '|', (string) $item['source_detail'] ) ) ) ) ) );
			$existing['source_detail'] = implode( ' | ', $details );
			$existing['extra'] = array_merge( $existing['extra'], $item['extra'] );
			$normalized[ $key ] = $existing;
		}

		return array_values( $normalized );
	}
}
