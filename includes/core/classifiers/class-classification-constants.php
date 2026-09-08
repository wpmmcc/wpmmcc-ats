<?php
/**
 * Classification Constants
 *
 * Defines constants for URL and data classification
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classification System Constants Class
 */
class Classification_Constants {

	// ==================== URL Classification Constants ====================

	/**
	 * URL location types
	 */
	const URL_LOCATION_FRONTEND = 'frontend';  // Frontend URL
	const URL_LOCATION_BACKEND  = 'backend';   // Backend admin URL
	const URL_LOCATION_API      = 'api';       // API endpoint
	const URL_LOCATION_AJAX     = 'ajax';      // AJAX endpoint

	/**
	 * URL access levels
	 */
	const URL_ACCESS_PUBLIC     = 'public';      // Public access
	const URL_ACCESS_LOGIN      = 'login';       // Login required
	const URL_ACCESS_ROLE       = 'role';        // Specific role required
	const URL_ACCESS_ADMIN      = 'admin';       // Admin only
	const URL_ACCESS_RESTRICTED = 'restricted';  // Restricted access

	// ==================== Data Classification Constants ====================

	/**
	 * Data content types
	 */
	const DATA_TYPE_CONTENT    = 'content';     // Main content
	const DATA_TYPE_ATTACHMENT = 'attachment';  // Attachment/media
	const DATA_TYPE_COMMENT    = 'comment';     // Comment
	const DATA_TYPE_USER       = 'user';        // User data
	const DATA_TYPE_ORDER      = 'order';       // Order data
	const DATA_TYPE_TERM       = 'term';        // Taxonomy/tag
	const DATA_TYPE_META       = 'meta';        // Metadata
	const DATA_TYPE_SETTINGS   = 'settings';    // Settings data
	const DATA_TYPE_LOG        = 'log';         // Log data

	/**
	 * Data privacy levels
	 */
	const DATA_PRIVACY_PUBLIC   = 'public';    // Public data
	const DATA_PRIVACY_INTERNAL = 'internal';  // Internal data
	const DATA_PRIVACY_PRIVATE  = 'private';   // Private data
	const DATA_PRIVACY_PII      = 'pii';       // Personally identifiable information

	/**
	 * Sync policies
	 */
	const SYNC_ALWAYS       = 'always';        // Always sync
	const SYNC_CONFIGURABLE = 'configurable';  // Configurable
	const SYNC_NEVER        = 'never';         // Never sync
	const SYNC_CONDITIONAL  = 'conditional';   // Conditional sync

	// ==================== Helper Methods ====================

	/**
	 * Get all URL location types
	 *
	 * @return array
	 */
	public static function get_url_locations() {
		return array(
			self::URL_LOCATION_FRONTEND => __( 'Frontend', 'wpmmcc-ats' ),
			self::URL_LOCATION_BACKEND  => __( 'Backend Admin', 'wpmmcc-ats' ),
			self::URL_LOCATION_API      => 'API',
			self::URL_LOCATION_AJAX     => 'AJAX',
		);
	}

	/**
	 * Get all URL access level types
	 *
	 * @return array
	 */
	public static function get_url_access_levels() {
		return array(
			self::URL_ACCESS_PUBLIC     => __( 'Public access', 'wpmmcc-ats' ),
			self::URL_ACCESS_LOGIN      => __( 'Login required', 'wpmmcc-ats' ),
			self::URL_ACCESS_ROLE       => __( 'Specific role required', 'wpmmcc-ats' ),
			self::URL_ACCESS_ADMIN      => __( 'Admin only', 'wpmmcc-ats' ),
			self::URL_ACCESS_RESTRICTED => __( 'Restricted access', 'wpmmcc-ats' ),
		);
	}

	/**
	 * Get all data content types
	 *
	 * @return array
	 */
	public static function get_data_types() {
		return array(
			self::DATA_TYPE_CONTENT    => __( 'Main content', 'wpmmcc-ats' ),
			self::DATA_TYPE_ATTACHMENT => __( 'Attachment/media', 'wpmmcc-ats' ),
			self::DATA_TYPE_COMMENT    => __( 'Comment', 'wpmmcc-ats' ),
			self::DATA_TYPE_USER       => __( 'User data', 'wpmmcc-ats' ),
			self::DATA_TYPE_ORDER      => __( 'Order data', 'wpmmcc-ats' ),
			self::DATA_TYPE_TERM       => __( 'Taxonomy/tag', 'wpmmcc-ats' ),
			self::DATA_TYPE_META       => __( 'Metadata', 'wpmmcc-ats' ),
			self::DATA_TYPE_SETTINGS   => __( 'Settings data', 'wpmmcc-ats' ),
			self::DATA_TYPE_LOG        => __( 'Log data', 'wpmmcc-ats' ),
		);
	}

	/**
	 * Get all privacy levels
	 *
	 * @return array
	 */
	public static function get_privacy_levels() {
		return array(
			self::DATA_PRIVACY_PUBLIC   => __( 'Public data', 'wpmmcc-ats' ),
			self::DATA_PRIVACY_INTERNAL => __( 'Internal data', 'wpmmcc-ats' ),
			self::DATA_PRIVACY_PRIVATE  => __( 'Private data', 'wpmmcc-ats' ),
			self::DATA_PRIVACY_PII      => __( 'Personally identifiable information (PII)', 'wpmmcc-ats' ),
		);
	}

	/**
	 * Get all sync policies
	 *
	 * @return array
	 */
	public static function get_sync_policies() {
		return array(
			self::SYNC_ALWAYS       => __( 'Always sync', 'wpmmcc-ats' ),
			self::SYNC_CONFIGURABLE => __( 'Configurable', 'wpmmcc-ats' ),
			self::SYNC_NEVER        => __( 'Never sync', 'wpmmcc-ats' ),
			self::SYNC_CONDITIONAL  => __( 'Conditional sync', 'wpmmcc-ats' ),
		);
	}

	/**
	 * Validate URL location type
	 *
	 * @param string $location
	 * @return bool
	 */
	public static function is_valid_url_location( $location ) {
		return in_array(
			$location,
			array(
				self::URL_LOCATION_FRONTEND,
				self::URL_LOCATION_BACKEND,
				self::URL_LOCATION_API,
				self::URL_LOCATION_AJAX,
			),
			true
		);
	}

	/**
	 * Validate access level type
	 *
	 * @param string $access
	 * @return bool
	 */
	public static function is_valid_url_access( $access ) {
		return in_array(
			$access,
			array(
				self::URL_ACCESS_PUBLIC,
				self::URL_ACCESS_LOGIN,
				self::URL_ACCESS_ROLE,
				self::URL_ACCESS_ADMIN,
				self::URL_ACCESS_RESTRICTED,
			),
			true
		);
	}

	/**
	 * Validate data type
	 *
	 * @param string $data_type
	 * @return bool
	 */
	public static function is_valid_data_type( $data_type ) {
		return in_array(
			$data_type,
			array(
				self::DATA_TYPE_CONTENT,
				self::DATA_TYPE_ATTACHMENT,
				self::DATA_TYPE_COMMENT,
				self::DATA_TYPE_USER,
				self::DATA_TYPE_ORDER,
				self::DATA_TYPE_TERM,
				self::DATA_TYPE_META,
				self::DATA_TYPE_SETTINGS,
				self::DATA_TYPE_LOG,
			),
			true
		);
	}

	/**
	 * Validate privacy level
	 *
	 * @param string $privacy
	 * @return bool
	 */
	public static function is_valid_privacy_level( $privacy ) {
		return in_array(
			$privacy,
			array(
				self::DATA_PRIVACY_PUBLIC,
				self::DATA_PRIVACY_INTERNAL,
				self::DATA_PRIVACY_PRIVATE,
				self::DATA_PRIVACY_PII,
			),
			true
		);
	}

	/**
	 * Validate sync policy
	 *
	 * @param string $policy
	 * @return bool
	 */
	public static function is_valid_sync_policy( $policy ) {
		return in_array(
			$policy,
			array(
				self::SYNC_ALWAYS,
				self::SYNC_CONFIGURABLE,
				self::SYNC_NEVER,
				self::SYNC_CONDITIONAL,
			),
			true
		);
	}
}
