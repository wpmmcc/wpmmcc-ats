<?php
/**
 * Write-Back Adapter Interface and Base Class
 *
 * Defines the contract for non-text content write-back adapters.
 * Each adapter handles a specific entity type (attachment, media metadata, document).
 *
 * Adapters are responsible for:
 * 1. Validating translated_ref references
 * 2. Applying translated content back to WordPress entities
 * 3. Routing unsupported items to the manual queue
 *
 * @package WPTSALL\Tasks\Sync
 * @since 1.0.5
 */

namespace WPTSALL\Tasks\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write-back adapter interface.
 *
 * All non-text entity adapters must implement this interface.
 */
interface Write_Back_Adapter_Interface {

	/**
	 * Get the adapter type identifier.
	 *
	 * @return string One of: 'attachment', 'media_meta', 'document'.
	 */
	public function get_type(): string;

	/**
	 * Get supported translated_ref types for this adapter.
	 *
	 * @return array List of supported ref types (e.g. ['url', 'id', 'path']).
	 */
	public function get_supported_ref_types(): array;

	/**
	 * Validate a translated_ref for this adapter.
	 *
	 * @param array $translated_ref The translated reference.
	 *   Expected keys: 'ref_type' (url|id|path), 'ref_value', 'source_ref'.
	 * @return true|\WP_Error True if valid, WP_Error with details otherwise.
	 */
	public function validate_ref( array $translated_ref );

	/**
	 * Apply a translated result back to the target entity.
	 *
	 * @param array $item     The non-text item with translated content.
	 *   Expected keys: 'source_id', 'target_id', 'translated_ref', 'metadata'.
	 * @param array $context  Sync context.
	 *   Expected keys: 'relation_id', 'source_blog', 'target_blog', 'target_type', 'lang_to'.
	 * @return array Result with keys: 'success' (bool), 'target_id' (int|string|null), 'error' (string|null).
	 */
	public function apply( array $item, array $context ): array;

	/**
	 * Check if this adapter can handle the given item.
	 *
	 * @param array $item The non-text item to check.
	 * @return bool True if this adapter can handle the item.
	 */
	public function can_handle( array $item ): bool;
}

/**
 * Abstract base class for write-back adapters.
 *
 * Provides common validation and error handling logic.
 */
abstract class Write_Back_Adapter_Base implements Write_Back_Adapter_Interface {

	/**
	 * Validate ref_type against supported types.
	 *
	 * @param array $translated_ref The translated reference.
	 * @return true|\WP_Error
	 */
	public function validate_ref( array $translated_ref ) {
		$ref_type = sanitize_key( (string) ( $translated_ref['ref_type'] ?? '' ) );

		if ( '' === $ref_type ) {
			return new \WP_Error(
				'missing_ref_type',
				__( 'translated_ref missing ref_type', 'wpmmcc-ats' ),
				array( 'adapter' => $this->get_type() )
			);
		}

		if ( ! in_array( $ref_type, $this->get_supported_ref_types(), true ) ) {
			return new \WP_Error(
				'unsupported_ref_type',
				sprintf(
					/* translators: 1: ref type, 2: adapter type, 3: supported types */
					__( 'ref_type "%1$s" not supported by %2$s adapter (supported: %3$s)', 'wpmmcc-ats' ),
					$ref_type,
					$this->get_type(),
					implode( ', ', $this->get_supported_ref_types() )
				),
				array(
					'adapter'        => $this->get_type(),
					'ref_type'       => $ref_type,
					'supported'      => $this->get_supported_ref_types(),
				)
			);
		}

		$ref_value = $translated_ref['ref_value'] ?? null;
		if ( null === $ref_value || '' === $ref_value ) {
			return new \WP_Error(
				'missing_ref_value',
				__( 'translated_ref missing ref_value', 'wpmmcc-ats' ),
				array( 'adapter' => $this->get_type(), 'ref_type' => $ref_type )
			);
		}

		return $this->validate_ref_value( $ref_type, $ref_value );
	}

	/**
	 * Validate ref_value for a specific ref_type.
	 *
	 * Override in subclasses for type-specific validation.
	 *
	 * @param string $ref_type  The reference type.
	 * @param mixed  $ref_value The reference value.
	 * @return true|\WP_Error
	 */
	protected function validate_ref_value( string $ref_type, $ref_value ) {
		switch ( $ref_type ) {
			case 'url':
				if ( ! filter_var( $ref_value, FILTER_VALIDATE_URL ) ) {
					return new \WP_Error(
						'invalid_url',
						__( 'ref_value is not a valid URL', 'wpmmcc-ats' ),
						array( 'ref_value' => $ref_value )
					);
				}
				$scheme = wp_parse_url( $ref_value, PHP_URL_SCHEME );
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					return new \WP_Error(
						'invalid_url_scheme',
						__( 'Only HTTP/HTTPS URLs are allowed', 'wpmmcc-ats' ),
						array( 'ref_value' => $ref_value )
					);
				}
				return true;

			case 'id':
				if ( ! is_numeric( $ref_value ) || (int) $ref_value <= 0 ) {
					return new \WP_Error(
						'invalid_id',
						__( 'ref_value is not a valid ID', 'wpmmcc-ats' ),
						array( 'ref_value' => $ref_value )
					);
				}
				return true;

			case 'path':
				$ref_value = (string) $ref_value;
				if ( '' === $ref_value || false !== strpos( $ref_value, '..' ) ) {
					return new \WP_Error(
						'invalid_path',
						__( 'ref_value is not a valid path', 'wpmmcc-ats' ),
						array( 'ref_value' => $ref_value )
					);
				}
				return true;

			default:
				return true;
		}
	}

	/**
	 * Build a standardized result array.
	 *
	 * @param bool        $success   Whether the operation succeeded.
	 * @param int|null    $target_id Target entity ID (if created/updated).
	 * @param string|null $error     Error message if failed.
	 * @return array
	 */
	protected function build_result( bool $success, $target_id = null, ?string $error = null ): array {
		return array(
			'success'   => $success,
			'target_id' => $target_id,
			'error'     => $error,
			'adapter'   => $this->get_type(),
		);
	}
}
