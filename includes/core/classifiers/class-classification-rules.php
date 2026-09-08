<?php
/**
 * Classification Rules
 *
 * URL and data classification rule configuration
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPTSALL\Core\Classification_Constants as CC;

/**
 * Classification Rules Class
 */
class Classification_Rules {

	/**
	 * Get URL classification rules
	 *
	 * @return array
	 */
	public static function get_url_rules() {
		return array(
			// WordPress backend admin
			array(
				'name'     => 'wp_admin',
				'pattern'  => '/^\/wp-admin\//',
				'location' => CC::URL_LOCATION_BACKEND,
				'access'   => CC::URL_ACCESS_ADMIN,
				'syncable' => false,
				'reason'   => 'Backend admin URLs are not syncable',
			),

			// WordPress login page
			array(
				'name'     => 'wp_login',
				'pattern'  => '/^\/(wp-login\.php|login|register)/',
				'location' => CC::URL_LOCATION_FRONTEND,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => false,
				'reason'   => 'Login and registration pages do not need syncing',
			),

			// REST API
			array(
				'name'     => 'rest_api',
				'pattern'  => '/^\/wp-json\//',
				'location' => CC::URL_LOCATION_API,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => true,
			),

			// AJAX endpoint
			array(
				'name'     => 'ajax',
				'pattern'  => '/\/admin-ajax\.php/',
				'location' => CC::URL_LOCATION_AJAX,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => false,
				'reason'   => 'AJAX endpoints are dynamically handled and do not need syncing',
			),

			// User account/profile pages
			array(
				'name'     => 'user_account',
				'pattern'  => '/^\/(my-account|account|profile|dashboard)\//',
				'location' => CC::URL_LOCATION_FRONTEND,
				'access'   => CC::URL_ACCESS_LOGIN,
				'syncable' => false,
				'reason'   => 'Login-required user pages are not syncable',
			),

			// Blog posts
			array(
				'name'     => 'blog_post',
				'pattern'  => '/^\/(blog|post|article)\//',
				'location' => CC::URL_LOCATION_FRONTEND,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => true,
			),

			// Product pages
			array(
				'name'     => 'product',
				'pattern'  => '/^\/product\//',
				'location' => CC::URL_LOCATION_FRONTEND,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => true,
			),

			// Portfolio
			array(
				'name'     => 'portfolio',
				'pattern'  => '/^\/portfolio\//',
				'location' => CC::URL_LOCATION_FRONTEND,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => true,
			),

			// Cart and checkout
			array(
				'name'     => 'checkout',
				'pattern'  => '/^\/(cart|checkout|order)\//',
				'location' => CC::URL_LOCATION_FRONTEND,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => false,
				'reason'   => 'Shopping and order pages contain sensitive information',
			),

			// Default rule - public frontend pages
			array(
				'name'     => 'default',
				'pattern'  => '/.*/',
				'location' => CC::URL_LOCATION_FRONTEND,
				'access'   => CC::URL_ACCESS_PUBLIC,
				'syncable' => true,
				'priority' => 999, // Lowest priority
			),
		);
	}

	/**
	 * Get data classification rules
	 *
	 * @return array
	 */
	public static function get_data_rules() {
		return array(
			// Standard posts
			'post' => array(
				'data_type'   => CC::DATA_TYPE_CONTENT,
				'privacy'     => CC::DATA_PRIVACY_PUBLIC,
				'sync_policy' => CC::SYNC_ALWAYS,
				'conditions'  => array(
					'post_status' => array( 'publish' ),
				),
			),

			// Pages
			'page' => array(
				'data_type'   => CC::DATA_TYPE_CONTENT,
				'privacy'     => CC::DATA_PRIVACY_PUBLIC,
				'sync_policy' => CC::SYNC_ALWAYS,
				'conditions'  => array(
					'post_status' => array( 'publish' ),
				),
			),

			// Attachments
			'attachment' => array(
				'data_type'   => CC::DATA_TYPE_ATTACHMENT,
				'privacy'     => CC::DATA_PRIVACY_PUBLIC,
				'sync_policy' => CC::SYNC_CONFIGURABLE,
				'default'     => true,
			),

			// Comments
			'comment' => array(
				'data_type'   => CC::DATA_TYPE_COMMENT,
				'privacy'     => CC::DATA_PRIVACY_PUBLIC,
				'sync_policy' => CC::SYNC_CONFIGURABLE,
				'default'     => false,
			),

			// Users
			'user' => array(
				'data_type'   => CC::DATA_TYPE_USER,
				'privacy'     => CC::DATA_PRIVACY_PII,
				'sync_policy' => CC::SYNC_NEVER,
				'reason'      => 'User data contains personally identifiable information and cannot be synced',
			),

			// WooCommerce products
			'product' => array(
				'data_type'   => CC::DATA_TYPE_CONTENT,
				'privacy'     => CC::DATA_PRIVACY_PUBLIC,
				'sync_policy' => CC::SYNC_ALWAYS,
				'conditions'  => array(
					'post_status' => array( 'publish' ),
				),
			),

			// WooCommerce orders
			'shop_order' => array(
				'data_type'   => CC::DATA_TYPE_ORDER,
				'privacy'     => CC::DATA_PRIVACY_PII,
				'sync_policy' => CC::SYNC_NEVER,
				'reason'      => 'Orders contain personal and payment information and cannot be synced',
			),

			// Portfolio
			'portfolio' => array(
				'data_type'   => CC::DATA_TYPE_CONTENT,
				'privacy'     => CC::DATA_PRIVACY_PUBLIC,
				'sync_policy' => CC::SYNC_ALWAYS,
				'conditions'  => array(
					'post_status' => array( 'publish' ),
				),
			),

			// Taxonomies
			'taxonomy' => array(
				'data_type'   => CC::DATA_TYPE_TERM,
				'privacy'     => CC::DATA_PRIVACY_PUBLIC,
				'sync_policy' => CC::SYNC_ALWAYS,
			),
		);
	}

	/**
	 * Get metadata classification rules
	 *
	 * @return array
	 */
	public static function get_meta_rules() {
		return array(
			// Never-sync metadata patterns
			'never_sync' => array(
				'_edit_lock',              // Edit lock
				'_edit_last',              // Last editor
				'_wp_old_slug',            // Old slug
				'_wp_old_date',            // Old date
				'_transient_*',            // Transient data
				'_site_transient_*',       // Site transient data
				'session_*',               // Session data
				'_wp_session_*',           // WP session
				'*_cache',                 // Cache data
				'_oembed_*',               // oEmbed cache
			),

			// PII field patterns
			'pii_fields' => array(
				'billing_*',               // Billing information
				'shipping_*',              // Shipping information
				'user_email',              // Email
				'user_phone',              // Phone
				'user_mobile',             // Mobile
				'user_address',            // Address
				'payment_*',               // Payment information
				'ip_address',              // IP address
				'customer_ip_address',     // Customer IP
				'_customer_*',             // Customer data
				'_billing_*',              // WooCommerce billing
				'_shipping_*',             // WooCommerce shipping
			),

			// Configurable sync - sync by default
			'configurable_sync' => array(
				'_thumbnail_id',           // Featured image
				'_yoast_wpseo_*',         // Yoast SEO
				'rank_math_*',            // Rank Math
				'_wp_page_template',      // Page template
				'product_*',              // Product fields
				'subtitle',               // Subtitle
				'reading_time',           // Reading time
				'author_bio',             // Author bio
				'is_featured',            // Featured flag
				'related_links',          // Related links
				'custom_meta',            // Custom metadata
			),

			// Configurable sync - no sync by default
			'configurable_no_sync' => array(
				'post_views_count',       // View count
				'_wp_attachment_metadata', // Attachment metadata
				'_wp_attached_file',      // Attached file
			),
		);
	}

	/**
	 * Get default sync configuration
	 *
	 * @return array
	 */
	public static function get_default_sync_config() {
		return array(
			// Data type sync configuration
			'data_types' => array(
				CC::DATA_TYPE_CONTENT    => true,   // Main content
				CC::DATA_TYPE_ATTACHMENT => true,   // Attachments
				CC::DATA_TYPE_COMMENT    => false,  // Comments
				CC::DATA_TYPE_USER       => false,  // Users
				CC::DATA_TYPE_ORDER      => false,  // Orders
				CC::DATA_TYPE_TERM       => true,   // Taxonomies
				CC::DATA_TYPE_META       => true,   // Metadata
				CC::DATA_TYPE_SETTINGS   => false,  // Settings
				CC::DATA_TYPE_LOG        => false,  // Logs
			),

			// URL access level configuration
			'url_access' => array(
				CC::URL_ACCESS_PUBLIC     => true,   // Public
				CC::URL_ACCESS_LOGIN      => false,  // Login required
				CC::URL_ACCESS_ROLE       => false,  // Role restricted
				CC::URL_ACCESS_ADMIN      => false,  // Admin
				CC::URL_ACCESS_RESTRICTED => false,  // Restricted
			),

			// Metadata sync mode
			'meta_sync_mode' => 'whitelist',  // whitelist or blacklist

			// Status filter
			'status_filter' => array(
				'post'       => array( 'publish' ),
				'page'       => array( 'publish' ),
				'product'    => array( 'publish' ),
				'portfolio'  => array( 'publish' ),
			),

			// Privacy protection
			'privacy_protection' => array(
				'block_pii'       => true,   // Block PII data
				'strip_pii_meta'  => true,   // Strip PII metadata
				'anonymize_users' => true,   // Anonymize user references
			),
		);
	}

	/**
	 * Apply filters to allow custom rules
	 *
	 * @param string $rule_type Rule type
	 * @param array  $rules     Default rules
	 * @return array
	 */
	public static function apply_filters( $rule_type, $rules ) {
		/**
		 * Filter classification rules
		 *
		 * @param array $rules Rules array
		 */
		return apply_filters( "wptsall_classification_rules_{$rule_type}", $rules );
	}
}
