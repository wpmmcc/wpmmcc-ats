<?php
/**
 * Smart Field Classifier
 *
 * Intelligently identifies field types based on WordPress conventions
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Field Classifier
 *
 * Automatically identifies field types based on WordPress table structure and naming conventions
 */
class Smart_Field_Classifier {

	/**
	 * ========================================
	 * Value-analysis based field type classification (for translation rules)
	 * ========================================
	 */

	/**
	 * Field type constants.
	 *
	 * @since 0.3.0
	 * @since 1.0.0 FIELD_TYPE_ID_MAP value changed to 'id_mapping' (per MODULE-CHAINS.md spec).
	 * @since 1.0.0 Added FIELD_TYPE_SKIP.
	 */
	const FIELD_TYPE_TRANSLATE  = 'translate';
	const FIELD_TYPE_SYNC       = 'sync';
	const FIELD_TYPE_ID_MAP     = 'id_mapping';  // Changed from 'id_map' to match chain spec.
	const FIELD_TYPE_COMPUTE    = 'compute';
	const FIELD_TYPE_SKIP       = 'skip';

	/**
	 * Backward compatibility constant (deprecated).
	 *
	 * @deprecated 1.0.0 Use FIELD_TYPE_ID_MAP instead.
	 */
	const FIELD_TYPE_ID_MAP_LEGACY = 'id_map';

	/**
	 * Supported content_format values for translation fields.
	 *
	 * This registry is the authoritative server-side set used to normalize
	 * content_format values before they enter persistence/sync paths.
	 *
	 * @var string[]
	 * @since 1.6.0
	 */
	private static $supported_content_formats = array(
		'plain_text',
		'rich_html',
		'serialized_php',
		'json_structured',
		'media_ref',
		'slug',
	);

	/**
	 * WordPress core wp_posts column field classifications.
	 *
	 * Used by both Smart_Field_Classifier and Smart_Field_Scanner
	 * to ensure consistent type values for core post columns.
	 *
	 * @var array
	 * @since 1.1.0
	 */
	public static $core_post_fields = array(
		'post_title'    => 'translate',
		'post_content'  => 'translate',
		'post_excerpt'  => 'translate',
		'post_name'     => 'translate',
		'guid'          => 'compute',
		'post_author'   => 'id_mapping',  // User ID needs cross-site mapping (H3).
		'post_parent'   => 'id_mapping',
		'post_date'     => 'sync',
		'post_status'   => 'sync',
		'post_type'     => 'sync',
		'comment_count' => 'compute',
	);

	/**
	 * Fixed list of computed fields (no value analysis needed).
	 *
	 * These fields are recomputed on the target site and do not need syncing.
	 *
	 * @var array
	 * @since 0.3.0
	 * @since 1.0.0 Added comment_count.
	 */
	private static $compute_fields = array(
		'post' => array( 'guid', 'comment_count' ),
		'term' => array(),
	);

	/**
	 * Fixed list of skip fields (not processed).
	 *
	 * These are WordPress internal temporary/system fields that should not be synced.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private static $skip_fields = array(
		'_edit_lock',
		'_edit_last',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
		'_encloseme',
		'_pingme',
	);

	/**
	 * Skip field patterns (regex).
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private static $skip_patterns = array(
		'/^_transient_/',
		'/^_site_transient_/',
		'/^_oembed_/',
		'/^_wptsall_cache_/',
	);

	/**
	 * PII field patterns (static, for classify_for_chain).
	 *
	 * @var array
	 * @since 1.5.0
	 */
	private static $static_pii_patterns = array(
		'email',
		'phone',
		'mobile',
		'address',
		'billing',
		'shipping',
		'payment',
		'ip_address',
		'customer',
		'user_login',
		'user_pass',
		'user_email',
	);

	/**
	 * ========================================
	 * Chain 1 compatible static methods (MODULE-CHAINS.md spec)
	 * ========================================
	 */

	/**
	 * Core field content_format hardcoded map.
	 *
	 * Priority 1 in the infer_content_format() chain.
	 *
	 * @var array
	 * @since 1.4.0
	 */
	private static $core_content_format_map = array(
		'post_title'                  => 'plain_text',
		'post_content'                => 'rich_html',
		'post_excerpt'                => 'plain_text',
		'post_name'                   => 'slug',
		'name'                        => 'plain_text',
		'description'                 => 'rich_html',
		'slug'                        => 'slug',
		'_wp_attachment_image_alt'    => 'plain_text',
	);

	/**
	 * Known plugin field content_format map.
	 *
	 * Priority 2 in the infer_content_format() chain.
	 *
	 * @var array
	 * @since 1.4.0
	 */
	private static $plugin_content_format_map = array(
		'_yoast_wpseo_title'          => 'plain_text',
		'_yoast_wpseo_metadesc'       => 'plain_text',
		'rank_math_title'             => 'plain_text',
		'rank_math_description'       => 'plain_text',
		'_elementor_data'             => 'json_structured',
		'_product_attributes'         => 'serialized_php',
		'_downloadable_files'         => 'serialized_php',
		'_product_image_gallery'      => 'media_ref',
		'_thumbnail_id'               => 'media_ref',
	);

	/**
	 * Get content_format for a core field.
	 *
	 * @since 1.4.0
	 *
	 * @param string $field_name Field name.
	 * @return string|null Content format or null if not a known core field.
	 */
	public static function get_core_field_content_format( string $field_name ): ?string {
		return self::$core_content_format_map[ $field_name ] ?? null;
	}

	/**
	 * Get content_format for a known plugin field.
	 *
	 * @since 1.4.0
	 *
	 * @param string $field_name Field name.
	 * @return string|null Content format or null if not a known plugin field.
	 */
	public static function get_known_plugin_field_content_format( string $field_name ): ?string {
		return self::$plugin_content_format_map[ $field_name ] ?? null;
	}

	/**
	 * Get the list of supported content_format values.
	 *
	 * @since 1.6.0
	 * @return string[]
	 */
	public static function get_supported_content_formats(): array {
		return self::$supported_content_formats;
	}

	/**
	 * Normalize a content_format value to the supported registry.
	 *
	 * Accepts a few legacy aliases to preserve backward compatibility.
	 *
	 * @since 1.6.0
	 *
	 * @param string $content_format Raw format.
	 * @param string $fallback       Fallback when unsupported.
	 * @return string Normalized supported format.
	 */
	public static function normalize_content_format( string $content_format, string $fallback = 'plain_text' ): string {
		$normalized = sanitize_key( $content_format );
		$fallback   = sanitize_key( $fallback );

		$aliases = array(
			'html'       => 'rich_html',
			'json'       => 'json_structured',
			'serialized' => 'serialized_php',
			'text'       => 'plain_text',
			'code'       => 'plain_text',
		);

		if ( isset( $aliases[ $normalized ] ) ) {
			$normalized = $aliases[ $normalized ];
		}
		if ( isset( $aliases[ $fallback ] ) ) {
			$fallback = $aliases[ $fallback ];
		}

		if ( in_array( $normalized, self::$supported_content_formats, true ) ) {
			return $normalized;
		}
		if ( in_array( $fallback, self::$supported_content_formats, true ) ) {
			return $fallback;
		}
		return 'plain_text';
	}

	/**
	 * Infer content_format for a translatable field.
	 *
	 * Priority chain:
	 * 1. Core field hardcoded map.
	 * 2. Known plugin fields.
	 * 3. Value analysis (serialized, JSON, HTML detection).
	 * 4. Field name pattern matching.
	 * 5. Default: plain_text.
	 *
	 * @since 1.4.0
	 *
	 * @param string $field_name    Field name.
	 * @param array  $sample_values Sample values for analysis (optional).
	 * @return string Content format.
	 */
	public static function infer_content_format( string $field_name, array $sample_values = array() ): string {
		// Priority 1: Core field hardcoded map.
		$core_format = self::get_core_field_content_format( $field_name );
		if ( null !== $core_format ) {
			return self::normalize_content_format( $core_format );
		}

		// Priority 2: Known plugin fields.
		$plugin_format = self::get_known_plugin_field_content_format( $field_name );
		if ( null !== $plugin_format ) {
			return self::normalize_content_format( $plugin_format );
		}

		// Priority 3: Value analysis.
		if ( ! empty( $sample_values ) ) {
			foreach ( $sample_values as $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				// Serialized PHP detection.
				if ( is_serialized( $value ) ) {
					return 'serialized_php';
				}

				// JSON detection.
				if ( self::is_json_static( $value ) ) {
					return 'json_structured';
				}

				// HTML detection.
				if ( wp_strip_all_tags( $value ) !== $value ) {
					return 'rich_html';
				}
			}
		}

		// Priority 4: Field name pattern matching.
		if ( preg_match( '/(^|_)(slug|permalink|post_name)$/i', $field_name ) ) {
			return 'slug';
		}
		if ( preg_match( '/(^|_)(attachment|thumbnail|image|images|gallery|avatar|icon|logo|file|files|document|pdf|media)(_id|_ids)?$/i', $field_name ) ) {
			return 'media_ref';
		}
		if ( self::is_code_like_field_name( $field_name ) ) {
			return 'plain_text';
		}
		if ( preg_match( '/_content$|_body$|_description$|_bio$/i', $field_name ) ) {
			return 'rich_html';
		}
		if ( preg_match( '/_data$|_json$/i', $field_name ) ) {
			return 'json_structured';
		}
		if ( preg_match( '/_title$|_name$|_label$|_heading$|_desc$/i', $field_name ) ) {
			return 'plain_text';
		}

		// Priority 5: Default.
		return 'plain_text';
	}

	/**
	 * Whether a field name looks like CSS/JS/PHP/code storage (not translatable).
	 *
	 * @since 1.8.0
	 *
	 * @param string $field_name Field or meta key name.
	 * @return bool
	 */
	public static function is_code_like_field_name( string $field_name ): bool {
		return (bool) preg_match(
			'/(^|_)(css|scss|less|js|javascript|script|code|snippet|regex|template|shortcode|sql|json_ld|custom_css|elementor_css)$/i',
			$field_name
		);
	}

	/**
	 * Classify a single field (Chain 1 spec signature).
	 *
	 * Per MODULE-CHAINS.md Chain 1 spec:
	 * Smart_Field_Classifier::classify($field_name, $sample_values)
	 * Returns: array with keys 'type', 'content_format', 'confidence'.
	 *
	 * @param string $field_name    Field name.
	 * @param array  $sample_values Sample values array (optional).
	 * @param string $context       Object context: 'post' or 'term'. Default 'post'.
	 * @return array Classification result with keys:
	 *               - 'type' (string): translate|sync|id_mapping|compute|skip.
	 *               - 'content_format' (string|null): Only set for 'translate' type.
	 *               - 'confidence' (int): 0-100 confidence score.
	 * @since 1.0.0
	 * @since 1.4.0 Returns array instead of string. Use $result['type'] for the type string.
	 */
	public static function classify_for_chain( string $field_name, array $sample_values = array(), string $context = 'post' ): array {
		$sample_count = count( $sample_values );

		// 1. First check if it is a skip field.
		if ( self::is_skip_field( $field_name ) ) {
			wptsall_log_debug( 'core', 'Field classified as skip', array(
				'field' => $field_name,
				'type'  => self::FIELD_TYPE_SKIP,
				'rule'  => 'skip_field_match',
			) );
			return array(
				'type'           => self::FIELD_TYPE_SKIP,
				'content_format' => null,
				'confidence'     => 95,
			);
		}

		// 1a. Code/script fields must not be translated (WordPress.org guideline).
		if ( self::is_code_like_field_name( $field_name ) ) {
			return array(
				'type'           => self::FIELD_TYPE_SKIP,
				'content_format' => null,
				'confidence'     => 95,
			);
		}

		// 1b. PII privacy check — skip fields containing personal data (GDPR).
		if ( self::is_pii_field( $field_name, $context ) ) {
			wptsall_log_debug( 'core', 'Field classified as skip (PII)', array(
				'field'   => $field_name,
				'type'    => self::FIELD_TYPE_SKIP,
				'rule'    => 'pii_field_match',
				'context' => $context,
			) );
			return array(
				'type'           => self::FIELD_TYPE_SKIP,
				'content_format' => null,
				'confidence'     => 90,
			);
		}

		// 2. Check if it is a computed field (check specified context and all contexts).
		$compute = self::$compute_fields[ $context ] ?? array();
		if ( 'post' !== $context ) {
			// Also check post compute fields (they are universal).
			$compute = array_merge( $compute, self::$compute_fields['post'] ?? array() );
		}
		if ( in_array( $field_name, $compute, true ) ) {
			wptsall_log_debug( 'core', 'Field classified as compute', array(
				'field'   => $field_name,
				'type'    => self::FIELD_TYPE_COMPUTE,
				'rule'    => 'compute_field_list',
				'context' => $context,
			) );
			return array(
				'type'           => self::FIELD_TYPE_COMPUTE,
				'content_format' => null,
				'confidence'     => 90,
			);
		}

		// 3. If sample values exist, classify based on value analysis.
		if ( ! empty( $sample_values ) ) {
			$analysis = self::analyze_values_static( $sample_values, $field_name );
			$type     = self::determine_type_from_analysis( $analysis, $field_name );
			wptsall_log_debug( 'core', 'Field classified by value analysis', array(
				'field'        => $field_name,
				'type'         => $type,
				'sample_count' => $sample_count,
				'rule'         => 'value_analysis',
			) );
			return array(
				'type'           => $type,
				'content_format' => ( self::FIELD_TYPE_TRANSLATE === $type )
					? self::infer_content_format( $field_name, $sample_values )
					: null,
				'confidence'     => 85,
			);
		}

		// 4. Classify based on field name only.
		$type = self::classify_by_name_static( $field_name );
		$confidence = isset( self::$explicit_field_mappings[ $field_name ] ) ? 95 : 70;
		wptsall_log_debug( 'core', 'Field classified by name pattern', array(
			'field' => $field_name,
			'type'  => $type,
			'rule'  => 'name_pattern',
		) );
		return array(
			'type'           => $type,
			'content_format' => ( self::FIELD_TYPE_TRANSLATE === $type )
				? self::infer_content_format( $field_name, $sample_values )
				: null,
			'confidence'     => $confidence,
		);
	}

	/**
	 * Batch classify fields (Chain 1 spec signature).
	 *
	 * Per MODULE-CHAINS.md Chain 1 spec:
	 * Smart_Field_Classifier::classify_batch($fields)
	 *
	 * @param array  $fields  Fields array, format: ['field_name' => ['sample1', 'sample2', ...], ...]
	 *                        or simple array: ['field1', 'field2', ...].
	 * @param string $context Object context: 'post' or 'term'. Default 'post'.
	 * @return array Classification results: ['field_name' => 'type', ...].
	 *               Each value is the type string (backward compatible).
	 *               Use classify_batch_for_chain_full() for full array results.
	 * @since 1.0.0
	 */
	public static function classify_batch_for_chain( array $fields, string $context = 'post' ): array {
		$total_fields = count( $fields );
		wptsall_log_debug( 'core', 'Batch classification started', array(
			'total_fields' => $total_fields,
		) );

		$result = array();
		$stats  = array(
			'translate'  => 0,
			'sync'       => 0,
			'id_mapping' => 0,
			'compute'    => 0,
			'skip'       => 0,
		);

		foreach ( $fields as $key => $value ) {
			if ( is_numeric( $key ) && is_string( $value ) ) {
				// Simple array format: ['field1', 'field2', ...].
				$field_name    = $value;
				$sample_values = array();
			} else {
				// Associative array format: ['field_name' => ['sample1', ...], ...].
				$field_name    = $key;
				$sample_values = is_array( $value ) ? $value : array();
			}

			$classification          = self::classify_for_chain( $field_name, $sample_values, $context );
			$type                    = $classification['type'];
			$result[ $field_name ]   = $type;

			if ( isset( $stats[ $type ] ) ) {
				++$stats[ $type ];
			}
		}

		wptsall_log_info( 'core', 'Batch classification completed', array(
			'total_fields' => $total_fields,
			'stats'        => $stats,
		) );

		return $result;
	}

	/**
	 * Batch classify fields with full result arrays.
	 *
	 * Same as classify_batch_for_chain() but returns the full classification
	 * array (including content_format and confidence) for each field.
	 *
	 * @param array  $fields  Fields array, format: ['field_name' => ['sample1', 'sample2', ...], ...]
	 *                        or simple array: ['field1', 'field2', ...].
	 * @param string $context Object context: 'post' or 'term'. Default 'post'.
	 * @return array Classification results: ['field_name' => ['type'=>..., 'content_format'=>..., 'confidence'=>...], ...].
	 * @since 1.4.0
	 */
	public static function classify_batch_for_chain_full( array $fields, string $context = 'post' ): array {
		$result = array();

		foreach ( $fields as $key => $value ) {
			if ( is_numeric( $key ) && is_string( $value ) ) {
				$field_name    = $value;
				$sample_values = array();
			} else {
				$field_name    = $key;
				$sample_values = is_array( $value ) ? $value : array();
			}

			$result[ $field_name ] = self::classify_for_chain( $field_name, $sample_values, $context );
		}

		return $result;
	}

	/**
	 * Check if the field should be skipped.
	 *
	 * @param string $field_name Field name.
	 * @return bool
	 * @since 1.0.0
	 */
	public static function is_skip_field( string $field_name ): bool {
		// Check fixed list.
		if ( in_array( $field_name, self::$skip_fields, true ) ) {
			return true;
		}

		// Check pattern matching.
		foreach ( self::$skip_patterns as $pattern ) {
			if ( preg_match( $pattern, $field_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get skip fields list.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function get_skip_fields(): array {
		return self::$skip_fields;
	}

	/**
	 * Get skip field patterns.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function get_skip_patterns(): array {
		return self::$skip_patterns;
	}

	/**
	 * Check if the field contains personally identifiable information (PII).
	 *
	 * PII fields should be skipped to comply with GDPR and privacy regulations.
	 *
	 * @param string $field_name Field name.
	 * @param string $context    Object context: 'post', 'user', 'comment', 'term'.
	 * @return bool True if the field is PII.
	 * @since 1.5.0
	 */
	public static function is_pii_field( string $field_name, string $context = '' ): bool {
		// User context: all fields except safe display fields are PII.
		if ( 'user' === $context ) {
			$safe_user_fields = array( 'nickname', 'display_name', 'description', 'user_url' );
			if ( ! in_array( $field_name, $safe_user_fields, true ) ) {
				return true;
			}
		}

		// Exempt geographic/location fields from PII matching.
		// These contain translatable or sync-able location data, not personal addresses.
		$pii_exempt_prefixes = array(
			'property_address_',  // easy-property-listings: property address components.
			'listing_address_',   // generic listing address.
			'location_',          // location fields.
			'geo_',               // geographic fields.
		);
		foreach ( $pii_exempt_prefixes as $prefix ) {
			if ( 0 === strpos( $field_name, $prefix ) ) {
				return false;
			}
		}

		// Exempt specific content-attached fields that contain "email" or "phone"
		// but are not personal user data (e.g. anonymous poster contact info).
		$pii_exempt_exact = array(
			'_bbp_anonymous_email',
			'_rtcl_email',
			'_rtcl_phone',
			'_email',
			'_email_message',       // Sensei LMS: course email notification template.
			'_email_subject',       // Sensei LMS: course email notification subject.
		);
		if ( in_array( $field_name, $pii_exempt_exact, true ) ) {
			return false;
		}

		// Check against PII patterns using substring matching.
		foreach ( self::$static_pii_patterns as $pattern ) {
			if ( false !== stripos( $field_name, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Static method: Analyze sample values.
	 *
	 * @param array  $samples    Sample values array.
	 * @param string $field_name Field name.
	 * @return array Analysis results.
	 * @since 1.0.0
	 */
	private static function analyze_values_static( array $samples, string $field_name ): array {
		$analysis = array(
			'total'              => count( $samples ),
			'numeric_count'      => 0,
			'valid_id_count'     => 0,
			'long_text_count'    => 0,
			'has_spaces_count'   => 0,
			'has_html_count'     => 0,
			'serialized_count'   => 0,
			'json_count'         => 0,
			'url_count'          => 0,
			'date_count'         => 0,
			'boolean_count'      => 0,
		);

		foreach ( $samples as $value ) {
			if ( empty( $value ) ) {
				continue;
			}

			$value_str = is_string( $value ) ? $value : strval( $value );

			// Numeric detection.
			if ( is_numeric( $value ) ) {
				++$analysis['numeric_count'];
				$int_val = intval( $value );
				if ( $int_val > 0 && $int_val < 1000000 ) {
					++$analysis['valid_id_count'];
				}
				continue;
			}

			// Serialized detection.
			if ( is_serialized( $value_str ) ) {
				++$analysis['serialized_count'];
				continue;
			}

			// JSON detection.
			if ( self::is_json_static( $value_str ) ) {
				++$analysis['json_count'];
				continue;
			}

			// URL detection.
			if ( filter_var( $value_str, FILTER_VALIDATE_URL ) ) {
				++$analysis['url_count'];
				continue;
			}

			// Text characteristics.
			$len = mb_strlen( $value_str );
			if ( $len > 50 ) {
				++$analysis['long_text_count'];
			}
			if ( preg_match( '/\s/', $value_str ) ) {
				++$analysis['has_spaces_count'];
			}
			if ( preg_match( '/<[^>]+>/', $value_str ) ) {
				++$analysis['has_html_count'];
			}
		}

		return $analysis;
	}

	/**
	 * Static method: Determine type from analysis results.
	 *
	 * @param array  $analysis   Analysis results.
	 * @param string $field_name Field name.
	 * @return string Field type.
	 * @since 1.0.0
	 */
	private static function determine_type_from_analysis( array $analysis, string $field_name ): string {
		$total = $analysis['total'];
		if ( $total === 0 ) {
			return self::classify_by_name_static( $field_name );
		}

		// Name-based classification takes priority for fields with explicit mappings
		// or strong name-based signals. This prevents numeric values (like prices
		// or stock counts) from being misclassified as id_mapping just because
		// they happen to match valid post IDs.
		$name_type = self::classify_by_name_static( $field_name );

		// If explicit mapping exists, always trust the name.
		if ( isset( self::$explicit_field_mappings[ $field_name ] ) ) {
			return $name_type;
		}

		// If name classification says sync, trust it unconditionally.
		// Real id_mapping fields (_thumbnail_id, post_parent, etc.) are classified
		// as id_mapping by classify_by_name_static(), not sync.
		if ( self::FIELD_TYPE_SYNC === $name_type ) {
			return self::FIELD_TYPE_SYNC;
		}

		// ID mapping detection.
		if ( $analysis['numeric_count'] > $total * 0.8 && $analysis['valid_id_count'] > $total * 0.5 ) {
			return self::FIELD_TYPE_ID_MAP;
		}

		// Translatable text detection.
		$translate_score = 0;
		if ( $analysis['long_text_count'] > $total * 0.3 ) {
			$translate_score += 0.3;
		}
		if ( $analysis['has_spaces_count'] > $total * 0.5 ) {
			$translate_score += 0.25;
		}
		if ( $analysis['has_html_count'] > 0 ) {
			$translate_score += 0.25;
		}

		if ( $translate_score >= 0.5 ) {
			return self::FIELD_TYPE_TRANSLATE;
		}

		// Serialized/JSON fields: if dominant, these contain translatable strings.
		$structured_count = $analysis['serialized_count'] + $analysis['json_count'];
		if ( $structured_count > $total * 0.5 ) {
			return self::FIELD_TYPE_TRANSLATE;
		}

		// Sync fields (excluding serialized/JSON which are handled above).
		$sync_count = $analysis['numeric_count'] + $analysis['url_count'] +
		              $analysis['date_count'] + $analysis['boolean_count'];

		if ( $sync_count > $total * 0.5 ) {
			return self::FIELD_TYPE_SYNC;
		}

		return $name_type;
	}

	/**
	 * Lazy-load field mappings from wpml-config.xml files.
	 *
	 * Called once on first classification. Merges XML-declared fields into
	 * $explicit_field_mappings and allows explicit XML actions to override
	 * existing mappings so runtime WPML config is respected.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	private static function maybe_load_wpml_config(): void {
		if ( self::$wpml_config_loaded ) {
			return;
		}

		self::$wpml_config_loaded = true;

		if ( ! class_exists( '\\WPTSALL\\Core\\WPML_Config_Reader' ) ) {
			return;
		}

		$xml_fields      = WPML_Config_Reader::get_all_custom_fields();
		$added           = 0;
		$overridden      = 0;
		$id_map_conflicts = 0;
		$valid_actions   = array( 'translate', 'sync', 'copy', 'skip', 'ignore', 'id_map', 'id_mapping', 'compute' );

		foreach ( $xml_fields as $field_name => $action ) {
			$field_name = sanitize_text_field( (string) $field_name );
			$action     = sanitize_key( (string) $action );
			if ( '' === $field_name || ! in_array( $action, $valid_actions, true ) ) {
				continue;
			}

			$existing = self::$explicit_field_mappings[ $field_name ] ?? null;

			// P1-TEST-01 (2026-09-02, gate evidence wp_ 9083): a third-party
			// wpml-config.xml may declare our built-in structural ID fields
			// as ignore/skip — e.g. sitepress's wpml-config.xml ships
			// `_thumbnail_id => ignore`. For WPML that only means "WPML
			// itself does not translate it"; for wptsall these fields are
			// the media/relation id-mapping pipeline (built-in wins, same
			// merge order as JSON hot-plug vs built-in adapters). Never let
			// an XML declaration downgrade a built-in id_map field.
			if ( null !== $existing && 'id_map' === $existing
				&& in_array( $action, array( 'skip', 'ignore' ), true ) ) {
				++$id_map_conflicts;
				continue;
			}

			self::$explicit_field_mappings[ $field_name ] = $action;
			if ( null === $existing ) {
				++$added;
			} elseif ( $existing !== $action ) {
				++$overridden;
			}
		}

		if ( $added > 0 || $overridden > 0 || $id_map_conflicts > 0 ) {
			wptsall_log_debug( 'core', 'WPML config fields merged into classifier', array(
				'added'            => $added,
				'overridden'       => $overridden,
				'id_map_conflicts' => $id_map_conflicts,
				'total_xml'        => count( $xml_fields ),
				'total_mapping'    => count( self::$explicit_field_mappings ),
			) );
		}
	}

	/**
	 * Static method: Classify by field name.
	 *
	 * @param string $field_name Field name.
	 * @return string Field type.
	 * @since 1.0.0
	 */
	private static function classify_by_name_static( string $field_name ): string {
		// 0a. Lazy-load wpml-config.xml field mappings.
		self::maybe_load_wpml_config();

		// 0. Explicit mappings take priority (highest priority).
		if ( isset( self::$explicit_field_mappings[ $field_name ] ) ) {
			$mapped = self::$explicit_field_mappings[ $field_name ];
			// Normalize legacy value 'id_map' to 'id_mapping'.
			if ( 'id_map' === $mapped ) {
				return self::FIELD_TYPE_ID_MAP;
			}
			// Normalize WPML action aliases.
			if ( 'copy' === $mapped ) {
				return self::FIELD_TYPE_SYNC;
			}
			if ( 'ignore' === $mapped ) {
				return self::FIELD_TYPE_SKIP;
			}
			return $mapped;
		}

		// 0b. P2-1: Check WordPress register_meta type declarations.
		// If a meta field was registered as number/boolean via
		// register_post_meta(), it should be sync (not translate).
		// This prevents numeric fields like _price from being guessed
		// as translate by the name_pattern heuristics below.
		$registered_type = self::get_registered_meta_type( $field_name );
		if ( null !== $registered_type ) {
			if ( in_array( $registered_type, array( 'number', 'integer', 'boolean' ), true ) ) {
				return self::FIELD_TYPE_SYNC;
			}
			if ( in_array( $registered_type, array( 'string' ), true ) ) {
				// String meta registered by a plugin is likely translatable
				// content. Fall through to name patterns to confirm, but
				// record that it was explicitly registered.
			}
		}

		// 1. ID reference pattern.
		if ( preg_match( '/_id$|_ids$|^post_parent$|_image_id$|_gallery_ids$|_attachment_id$/', $field_name ) ) {
			return self::FIELD_TYPE_ID_MAP;
		}

		// ID list fields (comma-separated IDs stored as string).
		if ( preg_match( '/_image_gallery$|_children$/', $field_name ) ) {
			return self::FIELD_TYPE_ID_MAP;
		}

		// 1b. Taxonomy reference patterns (meta keys storing term IDs).
		if ( preg_match( '/primary_category$|primary_product_cat$|_cat_ids$|_tag_ids$/', $field_name ) ) {
			return self::FIELD_TYPE_ID_MAP;
		}

		// 2. SEO translatable field pattern.
		if ( preg_match( '/^_yoast_wpseo_.*(?:title|desc|focuskw|keyphrase)|^rank_math_.*(?:title|desc|keyword)|^_seopress_.*(?:title|desc)|^_aioseo_.*(?:title|desc)/i', $field_name ) ) {
			return self::FIELD_TYPE_TRANSLATE;
		}

		// 2b. Core slug fields are translatable, with URL-safe write-back handled later.
		if ( in_array( $field_name, array( 'post_name', 'slug' ), true ) ) {
			return self::FIELD_TYPE_TRANSLATE;
		}

		// 3. Generic translatable field pattern.
		// Unified from instance classify_by_field_name() + static path (C1).
		if ( preg_match( '/title$|description$|content$|excerpt$|summary$|caption$|alt_text|heading$|subtitle$|tagline$|text$|message$|body$|label$|slogan$|bio$|biography$|instructions$|notes$|comment$|review$|testimonial$/i', $field_name ) ) {
			return self::FIELD_TYPE_TRANSLATE;
		}

		// 3b. /name$/i pattern — only match known translatable name fields.
		// Excluded from above to avoid misclassifying display_name, user_name, etc. (M3).
		if ( preg_match( '/(?:^|_)(?:product|item|category|tag|brand|attribute)_name$/i', $field_name ) ) {
			return self::FIELD_TYPE_TRANSLATE;
		}

		// 4. Sync field pattern (prices, counts, dimensions, config, etc.).
		if ( preg_match( '/price$|^_price|count$|rating$|status$|order$|date$|time$|settings$|config$|stock$|quantity$|weight$|length$|width$|height$|^_sku$|^total_sales$/i', $field_name ) ) {
			return self::FIELD_TYPE_SYNC;
		}

		// Default to sync.
		return self::FIELD_TYPE_SYNC;
	}

	/**
	 * P2-1: Get the registered meta type for a field name.
	 *
	 * Checks WordPress's register_post_meta / register_meta declarations
	 * across all post types. Returns the WP type (string/integer/number/
	 * boolean/array/object) if registered, or null if not found.
	 *
	 * @since 1.5.1
	 *
	 * @param string $field_name Meta key to look up.
	 * @return string|null
	 */
	private static function get_registered_meta_type( string $field_name ): ?string {
		// Check object cache for this field's registered type.
		$cache_key = 'wptsall_rmt_' . md5( $field_name );
		$cached = wp_cache_get( $cache_key, 'wptsall_register_meta' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		// Query all post types' registered meta.
		$post_types = get_post_types( array(), 'names' );
		foreach ( $post_types as $pt ) {
			$registered = get_registered_meta_keys( 'post', $pt );
			if ( isset( $registered[ $field_name ]['type'] ) ) {
				$type = $registered[ $field_name ]['type'];
				wp_cache_set( $cache_key, $type, 'wptsall_register_meta', 3600 );
				return $type;
			}
		}

		// Also check term meta registrations.
		$registered_term = get_registered_meta_keys( 'term' );
		if ( isset( $registered_term[ $field_name ]['type'] ) ) {
			$type = $registered_term[ $field_name ]['type'];
			wp_cache_set( $cache_key, $type, 'wptsall_register_meta', 3600 );
			return $type;
		}

		// Not found in any register_meta call.
		wp_cache_set( $cache_key, '', 'wptsall_register_meta', 3600 );
		return null;
	}

	/**
	 * Static method: Check if value is JSON.
	 *
	 * @param string $value Value.
	 * @return bool
	 * @since 1.0.0
	 */
	private static function is_json_static( string $value ): bool {
		if ( strlen( $value ) < 2 ) {
			return false;
		}
		$first = $value[0];
		if ( $first !== '{' && $first !== '[' ) {
			return false;
		}
		json_decode( $value );
		return json_last_error() === JSON_ERROR_NONE;
	}

	/**
	 * Propose a complete field_capabilities entry for a field.
	 *
	 * Uses classify_for_chain() to determine type and content_format, then builds
	 * a full capability structure suitable for insertion into translation_rules.field_capabilities.
	 *
	 * @since 1.1.0
	 *
	 * @param string $field_name    Field name (e.g. 'post_title', '_thumbnail_id').
	 * @param string $context       Object context: 'post' or 'term'. Default 'post'.
	 * @param array  $sample_values Sample values for improved classification (optional).
	 * @return array Complete capability entry with keys:
	 *               - 'type' (string): translate|sync|id_mapping|compute|skip.
	 *               - 'enabled' (bool): true.
	 *               - 'source' (string): 'manual'.
	 *               - 'direction' (string): 'one_way'.
	 *               - 'confidence' (int): 0-100 from classifier.
	 *               Plus type-specific keys:
	 *               - translate: 'content_format', 'storage'.
	 *               - id_mapping: 'reference_type', 'value_format'.
	 */
	public static function propose_field_capability( string $field_name, string $context = 'post', array $sample_values = array() ): array {
		$classification = self::classify_for_chain( $field_name, $sample_values, $context );

		$capability = array(
			'type'       => $classification['type'],
			'enabled'    => true,
			'source'     => 'manual',
			'direction'  => 'one_way',
			'confidence' => $classification['confidence'],
		);

		switch ( $classification['type'] ) {
			case self::FIELD_TYPE_TRANSLATE:
				$capability['content_format'] = $classification['content_format']
					?: self::infer_content_format( $field_name, $sample_values );
				$capability['storage'] = self::infer_storage( $field_name, $context );
				break;

			case self::FIELD_TYPE_ID_MAP:
				$capability['reference_type'] = self::infer_reference_type( $field_name );
				$capability['value_format']   = self::infer_value_format( $field_name );
				break;

			case self::FIELD_TYPE_SYNC:
				$capability['storage'] = self::infer_storage( $field_name, $context );
				break;
		}

		return $capability;
	}

	/**
	 * Infer reference_type for an id_mapping field from its name.
	 *
	 * @since 1.1.0
	 *
	 * @param string $field_name Field name.
	 * @return string Reference type: 'media', 'post', 'term', 'user', or 'post' (default).
	 */
	private static function infer_reference_type( string $field_name ): string {
		// Media references.
		if ( preg_match( '/thumbnail|image|gallery|attachment|media|avatar|photo|logo|icon|banner|cover/i', $field_name ) ) {
			return 'media';
		}

		// User references.
		if ( preg_match( '/author|user|owner|assignee|instructor|teacher|student/i', $field_name ) ) {
			return 'user';
		}

		// Term/taxonomy references.
		if ( preg_match( '/categor|tag|tax|term|_cat|_tag/i', $field_name ) ) {
			return 'term';
		}

		// Default to post.
		return 'post';
	}

	/**
	 * Infer value_format for an id_mapping field.
	 *
	 * @since 1.1.0
	 *
	 * @param string $field_name Field name.
	 * @return string Value format: 'csv' for list fields, 'scalar' otherwise.
	 */
	private static function infer_value_format( string $field_name ): string {
		// Fields known to store comma-separated or serialized ID lists.
		if ( preg_match( '/_ids$|_gallery$|_children$|_list$/', $field_name ) ) {
			return 'csv';
		}

		// Explicit list fields.
		$csv_fields = array( '_product_image_gallery', '_upsell_ids', '_crosssell_ids', '_children' );
		if ( in_array( $field_name, $csv_fields, true ) ) {
			return 'csv';
		}

		return 'scalar';
	}

	/**
	 * Infer storage location for a field.
	 *
	 * @since 1.1.0
	 *
	 * @param string $field_name Field name.
	 * @param string $context    Object context: 'post' or 'term'.
	 * @return string Storage: 'column' for core table fields, 'meta' for meta fields.
	 */
	private static function infer_storage( string $field_name, string $context = 'post' ): string {
		if ( 'post' === $context && isset( self::$core_post_fields[ $field_name ] ) ) {
			return 'column';
		}

		// Core term fields.
		$core_term_fields = array( 'name', 'description', 'slug' );
		if ( 'term' === $context && in_array( $field_name, $core_term_fields, true ) ) {
			return 'column';
		}

		return 'meta';
	}

	/**
	 * Whether wpml-config.xml fields have been loaded.
	 *
	 * @var bool
	 * @since 1.5.0
	 */
	private static $wpml_config_loaded = false;

	/**
	 * Explicit classification mappings for plugin-specific fields.
	 *
	 * @var array
	 */
	private static $explicit_field_mappings = array(
		// Yoast SEO translatable fields.
		'_yoast_wpseo_title'            => 'translate',
		'_yoast_wpseo_metadesc'         => 'translate',
		'_yoast_wpseo_focuskw'          => 'translate',
		'_yoast_wpseo_opengraph-title'  => 'translate',
		'_yoast_wpseo_opengraph-description' => 'translate',
		'_yoast_wpseo_twitter-title'    => 'translate',
		'_yoast_wpseo_twitter-description' => 'translate',
		'_yoast_wpseo_opengraph-image'     => 'sync',      // OG image URL (M1).
		'_yoast_wpseo_opengraph-image-id'  => 'id_map',    // OG image attachment ID (M1).
		'_yoast_wpseo_twitter-image'       => 'sync',      // Twitter image URL (M1).
		'_yoast_wpseo_twitter-image-id'    => 'id_map',    // Twitter image attachment ID (M1).

		// AIOSEO translatable fields.
		'_aioseo_title'                 => 'translate',
		'_aioseo_description'           => 'translate',
		'_aioseo_og_title'              => 'translate',
		'_aioseo_og_description'        => 'translate',
		'_aioseo_twitter_title'         => 'translate',
		'_aioseo_twitter_description'   => 'translate',

		// Rank Math translatable fields.
		'rank_math_title'               => 'translate',
		'rank_math_description'         => 'translate',
		'rank_math_focus_keyword'       => 'translate',
		'rank_math_facebook_title'      => 'translate',
		'rank_math_facebook_description' => 'translate',
		'rank_math_twitter_title'       => 'translate',
		'rank_math_twitter_description' => 'translate',

		// SEOPress translatable fields.
		'_seopress_titles_title'        => 'translate',
		'_seopress_titles_desc'         => 'translate',
		'_seopress_social_fb_title'     => 'translate',
		'_seopress_social_fb_desc'      => 'translate',
		'_seopress_social_twitter_title' => 'translate',
		'_seopress_social_twitter_desc' => 'translate',

		// WooCommerce translatable fields.
		'_product_short_description'    => 'translate',
		'_purchase_note'                => 'translate',
		'_variation_description'        => 'translate',   // WooCommerce variation description (M2).

		// WooCommerce ID mapping fields.
		'_thumbnail_id'                 => 'id_map',
		'_product_image_gallery'        => 'id_map',
		'_upsell_ids'                   => 'id_map',
		'_crosssell_ids'                => 'id_map',

		// bbPress ID mapping fields.
		'_bbp_forum_id'                 => 'id_map',
		'_bbp_topic_id'                 => 'id_map',
		'_bbp_reply_id'                 => 'id_map',
		'_bbp_last_topic_id'            => 'id_map',
		'_bbp_last_reply_id'            => 'id_map',
		'_bbp_last_active_id'           => 'id_map',

		// EDD fields.
		'edd_price'                     => 'sync',
		'_edd_download_files'           => 'sync',

		// WordPress core.
		'_wp_attachment_image_alt'       => 'translate',
		'_wp_attachment_metadata'        => 'skip',   // Contains file paths; target site should regenerate.
		'_wp_attached_file'              => 'skip',   // File path; invalid cross-site.
		'_menu_item_title'               => 'translate',

		// Nav menu item fields (H4).
		'_menu_item_object_id'           => 'id_map',
		'_menu_item_menu_item_parent'    => 'id_map',
		'_menu_item_type'                => 'sync',
		'_menu_item_object'              => 'sync',
		'_menu_item_target'              => 'sync',
		'_menu_item_classes'             => 'sync',
		'_menu_item_url'                 => 'sync',

		// WooCommerce price/dimension fields (prevent id_mapping misclassification)
		'_price'                        => 'sync',
		'_regular_price'                => 'sync',
		'_sale_price'                   => 'sync',
		'_stock'                        => 'sync',
		'_weight'                       => 'sync',
		'_length'                       => 'sync',
		'_width'                        => 'sync',
		'_height'                       => 'sync',
		'_sku'                          => 'sync',
		'total_sales'                   => 'sync',

		// WooCommerce ID list fields
		'_children'                     => 'id_map',

		// bbPress count fields (prevent id_mapping misclassification)
		'_bbp_reply_count'              => 'sync',
		'_bbp_voice_count'              => 'sync',
		'_bbp_forum_topic_count'        => 'sync',
		'_bbp_forum_reply_count'        => 'sync',

		// Taxonomy reference fields (term ID mappings).
		'_yoast_wpseo_primary_category'        => 'id_map',
		'_yoast_wpseo_primary_product_cat'     => 'id_map',
		'rank_math_primary_category'           => 'id_map',
		'rank_math_primary_product_cat'        => 'id_map',
		'_product_cat_ids'                     => 'id_map',
		'_default_attributes'                  => 'sync',  // Serialized; not simple ID mapping.

		// Source site sync markers (always sync).
		'_wptsall_source_post_id'       => 'id_map',
		'_wptsall_source_term_id'       => 'id_map',
		'_wptsall_source_blog_id'       => 'skip',
		'_wptsall_relation_id'          => 'skip',
		'_wptsall_origin_object_id'     => 'skip',
		'_wptsall_origin_site_id'       => 'skip',
		'_wptsall_virtual_site_id'      => 'sync',
		'_wptsall_is_virtual_copy'      => 'sync',
		'_wptsall_template'             => 'sync',
		'_wptsall_lang'                 => 'sync',
	);
}
