<?php
/**
 * Translation values cannot grant identity, configuration or editorial rights.
 *
 * Field selection and format still come from the effective server-side rule.
 *
 * @package WPTSALL
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Field_Write_Policy {

	/**
	 * Mandatory protection, including when a rule accidentally marks it translate.
	 *
	 * @param string $field       Canonical field name.
	 * @param string $object_type Object family.
	 * @param bool   $meta        Metadata channel.
	 * @return bool
	 */
	public static function allows_translation( string $field, string $object_type, bool $meta = false ): bool {
		if ( '' === $field || preg_match( '/[\x00-\x1f]/', $field ) ) {
			return false;
		}
		if ( preg_match( '/^_(?:wptsall_|wpmmcc_|edit_|oembed_)/i', $field )
			|| ( 0 === strpos( strtolower( $field ), '_wp_' ) && '_wp_attachment_image_alt' !== $field ) ) {
			return false;
		}
		if ( Smart_Field_Classifier::is_code_like_field_name( $field ) ) {
			return false;
		}
		if ( $meta ) {
			return in_array( $object_type, array( 'post_type', 'taxonomy' ), true );
		}
		if ( 'post_type' === $object_type ) {
			return in_array( $field, array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ), true );
		}
		if ( 'taxonomy' === $object_type ) {
			return in_array( $field, array( 'name', 'description', 'slug' ), true );
		}
		if ( 'option' === $object_type ) {
			return ! in_array( $field, array( 'option_name', 'option_id', 'autoload' ), true );
		}
		return false;
	}
}
