<?php
/**
 * Advanced Custom Fields (ACF) field rules adapter
 *
 * P0-2 (2026-06-18): Now reads ACF field definitions via the native
 * acf_get_field_groups() / acf_get_fields() API. This discovers all
 * user-defined ACF fields and classifies them by ACF field type.
 *
 * @package WPTSALL\Models\Adapters
 * @since 1.2.0
 * @updated 1.5.1 P0-2: Real ACF API integration.
 */

namespace WPTSALL\Models\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Advanced_Custom_Fields_Field_Rules_Adapter implements Plugin_Field_Rules_Adapter {

	private static $translatable_types = array(
		'text'     => 'plain_text',
		'textarea' => 'plain_text',
		'wysiwyg'  => 'rich_html',
		'email'    => 'plain_text',
		'url'      => 'plain_text',
		'password' => 'plain_text',
	);

	private static $id_reference_types = array(
		'image'        => array( 'reference_type' => 'media', 'reference_target' => 'attachment', 'translatable' => false ),
		'file'         => array( 'reference_type' => 'media', 'reference_target' => 'attachment', 'translatable' => false ),
		'gallery'      => array( 'reference_type' => 'media', 'reference_target' => 'attachment', 'translatable' => false ),
		'post_object'  => array( 'reference_type' => 'post', 'reference_target' => 'self', 'translatable' => false ),
		'relationship' => array( 'reference_type' => 'post', 'reference_target' => 'self', 'translatable' => false ),
		'user'         => array( 'reference_type' => 'user', 'reference_target' => 'user', 'translatable' => false ),
		'taxonomy'     => array( 'reference_type' => 'term', 'reference_target' => 'self', 'translatable' => false ),
	);

	private static $sync_types = array(
		'number', 'range', 'true_false', 'color_picker',
		'date_picker', 'date_time_picker', 'time_picker',
		'select', 'checkbox', 'radio', 'button_group',
	);

	public function get_plugin_slug(): string {
		return 'advanced-custom-fields/acf.php';
	}

	private function base_rules(): array {
		return array(
			'acf_repeater'         => array( 'type' => 'translate', 'content_format' => 'serialized_php', 'enabled' => true, 'translatable' => true ),
			'acf_flexible_content' => array( 'type' => 'translate', 'content_format' => 'serialized_php', 'enabled' => true, 'translatable' => true ),
			'acf_group'            => array( 'type' => 'translate', 'content_format' => 'serialized_php', 'enabled' => true, 'translatable' => true ),
		);
	}

	public function get_field_rules(): array {
		// Always include the base container rules so the adapter is never
		// empty: serialized ACF containers are translatable even when no
		// user-defined field groups exist yet.
		$base = self::base_rules();

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			return array_merge( $base, $this->discover_acf_fields() );
		}

		return $base;
	}

	private function discover_acf_fields(): array {
		$rules  = array();
		$groups = acf_get_field_groups();
		if ( empty( $groups ) ) {
			return $rules;
		}

		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group['key'] );
			if ( empty( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$meta_key = $field['name'] ?? '';
				$type     = $field['type'] ?? 'text';
				$label    = $field['label'] ?? $meta_key;
				if ( '' === $meta_key ) {
					continue;
				}
				if ( in_array( $type, array( 'accordion', 'tab', 'message' ), true ) ) {
					continue;
				}
				if ( in_array( $type, array( 'repeater', 'flexible_content', 'group' ), true ) ) {
					$rules[ $meta_key ] = array(
						'type' => 'translate', 'content_format' => 'serialized_php',
						'enabled' => true, 'translatable' => true,
						'acf_field_type' => $type, 'acf_field_label' => $label,
					);
					continue;
				}
				$rules[ $meta_key ] = $this->classify_acf_field( $meta_key, $type, $label );
			}
		}
		return $rules;
	}

	private function classify_acf_field( string $meta_key, string $acf_type, string $label ): array {
		if ( isset( self::$translatable_types[ $acf_type ] ) ) {
			return array(
				'type' => 'translate',
				'content_format' => self::$translatable_types[ $acf_type ],
				'enabled' => true, 'translatable' => true,
				'acf_field_type' => $acf_type, 'acf_field_label' => $label,
			);
		}
		if ( isset( self::$id_reference_types[ $acf_type ] ) ) {
			$ref = self::$id_reference_types[ $acf_type ];
			return array(
				'type' => 'id_mapping', 'enabled' => true, 'translatable' => false,
				'reference_type' => $ref['reference_type'], 'reference_target' => $ref['reference_target'],
				'acf_field_type' => $acf_type, 'acf_field_label' => $label,
			);
		}
		if ( in_array( $acf_type, self::$sync_types, true ) ) {
			return array(
				'type' => 'sync', 'enabled' => true, 'translatable' => false,
				'acf_field_type' => $acf_type, 'acf_field_label' => $label,
			);
		}
		return array(
			'type' => 'sync', 'enabled' => true, 'translatable' => false,
			'acf_field_type' => $acf_type, 'acf_field_label' => $label,
		);
	}

	public function get_field_patterns(): array {
		return array();
	}
}
