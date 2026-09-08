<?php
/**
 * Blog Template Model
 *
 * WordPress default Blog template generator
 *
 * @package WPTSALL
 * @since 0.3.0
 */

namespace WPTSALL\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blog_Template class
 *
 * Generates templates for WordPress default content (post, page, category, tag)
 */
class Blog_Template {

	/**
	 * Template identifier
	 */
	const TEMPLATE_SLUG = 'wordpress-blog';

	/**
	 * Generate WordPress default Blog template
	 *
	 * @return array Template data
	 */
	public static function generate() {
		return array(
			'plugin'       => self::TEMPLATE_SLUG,
			'plugin_name'  => __( 'WordPress Blog', 'wpmmcc-ats' ),
			'version'      => '1.0.0',
			'description'  => __( 'WordPress default Blog content (posts, pages, categories, tags)', 'wpmmcc-ats' ),
			'author'       => 'WPTSALL',
			'objects'      => array(
				'post_types'  => self::get_post_types(),
				'taxonomies'  => self::get_taxonomies(),
				'url_types'   => self::get_url_types(),
			),
			'sync_policies' => self::get_sync_policies(),
		);
	}

	/**
	 * Get post type configuration
	 *
	 * @return array Post types array
	 */
	private static function get_post_types() {
		return array(
			// Post (post)
			array(
				'type'             => 'post_type',
				'subtype'          => 'post',
				'label'            => __( 'Posts', 'wpmmcc-ats' ),
				'data_retrieval'   => array(
					'method'      => 'wp_query',
					'post_type'   => 'post',
					'post_status' => array( 'publish', 'draft', 'pending' ),
				),
				'fields_map'       => array(
					'post' => array(
						'post_title',
						'post_content',
						'post_excerpt',
						'post_name',
						'post_status',
						'comment_status',
						'ping_status',
						'menu_order',
					),
					'meta' => array(
						'_edit_last',
						'_edit_lock',
					),
				),
				'discover_meta'    => true,
				'cross_table'      => array(
					'taxonomies'  => array( 'category', 'post_tag' ),
					'attachments' => array( '_thumbnail_id' ),
				),
			),

			// Page (page)
			array(
				'type'             => 'post_type',
				'subtype'          => 'page',
				'label'            => __( 'Pages', 'wpmmcc-ats' ),
				'data_retrieval'   => array(
					'method'      => 'wp_query',
					'post_type'   => 'page',
					'post_status' => array( 'publish', 'draft', 'pending' ),
				),
				'fields_map'       => array(
					'post' => array(
						'post_title',
						'post_content',
						'post_excerpt',
						'post_name',
						'post_status',
						'post_parent',
						'menu_order',
						'page_template',
					),
					'meta' => array(
						'_edit_last',
						'_edit_lock',
						'_wp_page_template',
					),
				),
				'discover_meta'    => true,
				'cross_table'      => array(
					'attachments' => array( '_thumbnail_id' ),
				),
			),
		);
	}

	/**
	 * Get taxonomy configuration
	 *
	 * @return array Taxonomies array
	 */
	private static function get_taxonomies() {
		return array(
			// Category (category)
			array(
				'type'           => 'taxonomy',
				'subtype'        => 'category',
				'label'          => __( 'Categories', 'wpmmcc-ats' ),
				'data_retrieval' => array(
					'method'     => 'get_terms',
					'taxonomy'   => 'category',
					'hide_empty' => false,
				),
				'fields_map'     => array(
					'term' => array(
						'name',
						'slug',
						'description',
					),
					'meta' => array(),
				),
				'discover_meta'  => true,
				'hierarchical'   => true,
			),

			// Tag (post_tag)
			array(
				'type'           => 'taxonomy',
				'subtype'        => 'post_tag',
				'label'          => __( 'Tags', 'wpmmcc-ats' ),
				'data_retrieval' => array(
					'method'     => 'get_terms',
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
				),
				'fields_map'     => array(
					'term' => array(
						'name',
						'slug',
						'description',
					),
					'meta' => array(),
				),
				'discover_meta'  => true,
				'hierarchical'   => false,
			),
		);
	}

	/**
	 * Get URL type configuration
	 *
	 * @return array URL types array
	 */
	private static function get_url_types() {
		return array(
			// Post archive
			array(
				'label'        => __( 'Post Archive', 'wpmmcc-ats' ),
				'route'        => '/blog/',
				'object_type'  => 'post_type',
				'subtype'      => 'post',
				'is_archive'   => true,
				'match_mode'   => 'prefix',
			),

			// Single post
			array(
				'label'        => __( 'Single Post', 'wpmmcc-ats' ),
				'route'        => '/{year}/{month}/{day}/{postname}/',
				'object_type'  => 'post_type',
				'subtype'      => 'post',
				'is_single'    => true,
				'match_mode'   => 'auto',
				'placeholders' => array(
					'year'     => '[0-9]{4}',
					'month'    => '[0-9]{2}',
					'day'      => '[0-9]{2}',
					'postname' => '[^/]+',
				),
			),

			// Page
			array(
				'label'        => __( 'Page', 'wpmmcc-ats' ),
				'route'        => '/{pagename}/',
				'object_type'  => 'post_type',
				'subtype'      => 'page',
				'is_single'    => true,
				'match_mode'   => 'auto',
				'placeholders' => array(
					'pagename' => '[^/]+',
				),
			),

			// Category archive
			array(
				'label'        => __( 'Category Archive', 'wpmmcc-ats' ),
				'route'        => '/category/{category}/',
				'object_type'  => 'taxonomy',
				'subtype'      => 'category',
				'is_archive'   => true,
				'match_mode'   => 'auto',
				'placeholders' => array(
					'category' => '[^/]+',
				),
			),

			// Tag archive
			array(
				'label'        => __( 'Tag Archive', 'wpmmcc-ats' ),
				'route'        => '/tag/{tag}/',
				'object_type'  => 'taxonomy',
				'subtype'      => 'post_tag',
				'is_archive'   => true,
				'match_mode'   => 'auto',
				'placeholders' => array(
					'tag' => '[^/]+',
				),
			),
		);
	}

	/**
	 * Save Blog template to database
	 *
	 * @return array Saved template
	 */
	public static function save() {
		$template = self::generate();
		update_option( 'wptsall_template_' . self::TEMPLATE_SLUG, $template );

		wptsall_log(
			'template',
			'info',
			'Blog template saved',
			array(
				'template' => self::TEMPLATE_SLUG,
				'version'  => $template['version'],
			)
		);

		do_action( 'wptsall_blog_template_saved', $template );

		return $template;
	}

	/**
	 * Get Blog template
	 *
	 * @param bool $force_regenerate Whether to force regeneration
	 * @return array Template data
	 */
	public static function get( $force_regenerate = false ) {
		if ( $force_regenerate ) {
			return self::save();
		}

		$template = get_option( 'wptsall_template_' . self::TEMPLATE_SLUG );

		if ( ! $template ) {
			$template = self::save();
		}

		return $template;
	}

	/**
	 * Check if Blog template exists
	 *
	 * @return bool
	 */
	public static function exists() {
		return (bool) get_option( 'wptsall_template_' . self::TEMPLATE_SLUG );
	}

	/**
	 * Delete Blog template
	 *
	 * @return bool
	 */
	public static function delete() {
		$result = delete_option( 'wptsall_template_' . self::TEMPLATE_SLUG );

		if ( $result ) {
			wptsall_log(
				'template',
				'info',
				'Blog template deleted',
				array( 'template' => self::TEMPLATE_SLUG )
			);

			do_action( 'wptsall_blog_template_deleted' );
		}

		return $result;
	}

	/**
	 * Get Blog template info (summary)
	 *
	 * @return array Summary info
	 */
	public static function get_info() {
		$template = self::get();

		return array(
			'slug'        => self::TEMPLATE_SLUG,
			'name'        => $template['plugin_name'],
			'version'     => $template['version'],
			'description' => $template['description'],
			'post_types'  => count( $template['objects']['post_types'] ?? array() ),
			'taxonomies'  => count( $template['objects']['taxonomies'] ?? array() ),
			'url_types'   => count( $template['objects']['url_types'] ?? array() ),
			'exists'      => self::exists(),
		);
	}

	/**
	 * Validate Blog template
	 *
	 * @return array Validation result
	 */
	public static function validate() {
		$template = self::get();

		// Use template validator
		if ( class_exists( '\WPTSALL\Models\Validators\Template_Validator' ) ) {
			return \WPTSALL\Models\Validators\Template_Validator::validate_template( $template );
		}

		// Basic validation
		$errors   = array();
		$warnings = array();

		if ( empty( $template['objects']['post_types'] ) ) {
			$errors[] = __( 'Blog template is missing post types', 'wpmmcc-ats' );
		}

		if ( empty( $template['objects']['taxonomies'] ) ) {
			$errors[] = __( 'Blog template is missing taxonomies', 'wpmmcc-ats' );
		}

		// Check sync policies
		if ( empty( $template['sync_policies'] ) ) {
			$warnings[] = __( 'Blog template is missing sync policy configuration', 'wpmmcc-ats' );
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Get supported content types
	 *
	 * @return array Content type list
	 */
	public static function get_supported_types() {
		return array(
			'post_types'  => array( 'post', 'page' ),
			'taxonomies'  => array( 'category', 'post_tag' ),
		);
	}

	/**
	 * Check if content type is Blog-related
	 *
	 * @param string $object_type  Object type (post_type/taxonomy)
	 * @param string $subtype      Subtype
	 * @return bool
	 */
	public static function is_blog_type( $object_type, $subtype ) {
		$supported = self::get_supported_types();

		if ( 'post_type' === $object_type ) {
			return in_array( $subtype, $supported['post_types'], true );
		}

		if ( 'taxonomy' === $object_type ) {
			return in_array( $subtype, $supported['taxonomies'], true );
		}

		return false;
	}

	/**
	 * Get sync policy configuration
	 *
	 * @since 0.3.0
	 * @return array Sync policies
	 */
	private static function get_sync_policies() {
		if ( ! class_exists( '\WPTSALL\Core\Classification_Constants' ) ) {
			return array();
		}

		$CC = '\WPTSALL\Core\Classification_Constants';

		return array(
			// Data type sync configuration
			'data_types' => array(
				$CC::DATA_TYPE_CONTENT    => true,   // Main content (posts, pages)
				$CC::DATA_TYPE_ATTACHMENT => true,   // Attachments
				$CC::DATA_TYPE_COMMENT    => false,  // Comments (not synced by default)
				$CC::DATA_TYPE_USER       => false,  // Users (never sync)
				$CC::DATA_TYPE_ORDER      => false,  // Orders (never sync)
				$CC::DATA_TYPE_TERM       => true,   // Categories/Tags
				$CC::DATA_TYPE_META       => true,   // Metadata
				$CC::DATA_TYPE_SETTINGS   => false,  // Settings
				$CC::DATA_TYPE_LOG        => false,  // Logs
			),

			// URL access level configuration
			'url_access' => array(
				$CC::URL_ACCESS_PUBLIC     => true,   // Public access
				$CC::URL_ACCESS_LOGIN      => false,  // Login required
				$CC::URL_ACCESS_ROLE       => false,  // Role restricted
				$CC::URL_ACCESS_ADMIN      => false,  // Admin only
				$CC::URL_ACCESS_RESTRICTED => false,  // Restricted access
			),

			// Metadata sync mode
			'meta_sync_mode' => 'whitelist',  // whitelist or blacklist

			// Allowed metadata patterns in whitelist mode
			'meta_whitelist' => array(
				'_thumbnail_id',      // Featured image
				'_yoast_wpseo_*',    // Yoast SEO
				'rank_math_*',       // Rank Math
				'_wp_page_template', // Page template
				'subtitle',          // Subtitle
				'reading_time',      // Reading time
			),

			// Blocked metadata patterns in blacklist mode
			'meta_blacklist' => array(
				'_edit_lock',        // Edit lock
				'_edit_last',        // Last editor
				'_transient_*',      // Transient data
				'billing_*',         // Billing info
				'shipping_*',        // Shipping info
				'user_*',            // User related
				'payment_*',         // Payment info
			),

			// Status filter
			'status_filter' => array(
				'post' => array( 'publish' ),       // Published posts only
				'page' => array( 'publish' ),       // Published pages only
			),

			// Privacy protection
			'privacy_protection' => array(
				'block_pii'       => true,   // Block PII data
				'strip_pii_meta'  => true,   // Filter PII metadata
				'anonymize_users' => true,   // Anonymize user references
			),
		);
	}

	/**
	 * Update sync policies
	 *
	 * @since 0.3.0
	 * @param array $sync_policies New sync policies
	 * @return array Updated template
	 */
	public static function update_sync_policies( $sync_policies ) {
		$template = self::get();

		// Merge new sync policies
		$template['sync_policies'] = wp_parse_args(
			$sync_policies,
			$template['sync_policies'] ?? self::get_sync_policies()
		);

		// Save updated template
		update_option( 'wptsall_template_' . self::TEMPLATE_SLUG, $template );

		wptsall_log(
			'template',
			'info',
			'Blog template sync policies updated',
			array(
				'template' => self::TEMPLATE_SLUG,
				'policies' => $sync_policies,
			)
		);

		do_action( 'wptsall_blog_template_sync_policies_updated', $template );

		return $template;
	}

	/**
	 * Get sync policies
	 *
	 * @since 0.3.0
	 * @return array Sync policies
	 */
	public static function get_sync_policies_config() {
		$template = self::get();
		return $template['sync_policies'] ?? self::get_sync_policies();
	}

	/**
	 * Get discovered fields
	 *
	 * @since 0.3.0
	 * @param string $context Context (post_meta|user_meta|comment_meta|term_meta)
	 * @return array
	 */
	public static function get_discovered_fields( $context = null ) {

		$template = self::get();
		$fields   = $template['discovered_fields'] ?? array();

		if ( null === $context ) {
			return $fields;
		}

		return $fields[ $context ] ?? array();
	}

	/**
	 * Manually add field
	 *
	 * @since 0.3.0
	 * @param string $context  Context
	 * @param string $meta_key Field name
	 * @param array  $config   Field configuration
	 * @return array Updated template
	 */
	public static function add_manual_field( $context, $meta_key, $config ) {

		$template = self::get();

		if ( ! isset( $template['discovered_fields'] ) ) {
			$template['discovered_fields'] = array();
		}

		if ( ! isset( $template['discovered_fields'][ $context ] ) ) {
			$template['discovered_fields'][ $context ] = array();
		}

		// Mark as manually added
		$config['auto_detected'] = false;
		$config['detected_by']   = 'manual';

		$template['discovered_fields'][ $context ][ $meta_key ] = $config;

		// Save template
		update_option( 'wptsall_template_' . self::TEMPLATE_SLUG, $template );

		wptsall_log( 'template', 'info', sprintf( 'Manually added field: %s (%s)', $meta_key, $context ) );

		do_action( 'wptsall_blog_template_field_added', $meta_key, $context, $config );

		return $template;
	}

	/**
	 * Update field configuration
	 *
	 * @since 0.3.0
	 * @param string $context  Context
	 * @param string $meta_key Field name
	 * @param array  $config   New configuration
	 * @return array Updated template
	 */
	public static function update_field_config( $context, $meta_key, $config ) {

		$template = self::get();

		if ( ! isset( $template['discovered_fields'][ $context ][ $meta_key ] ) ) {
			return $template;
		}

		// Merge configuration
		$template['discovered_fields'][ $context ][ $meta_key ] = array_merge(
			$template['discovered_fields'][ $context ][ $meta_key ],
			$config
		);

		// Mark as modified
		$template['discovered_fields'][ $context ][ $meta_key ]['manually_modified'] = true;

		// Save template
		update_option( 'wptsall_template_' . self::TEMPLATE_SLUG, $template );

		wptsall_log( 'template', 'info', sprintf( 'Updated field config: %s (%s)', $meta_key, $context ) );

		do_action( 'wptsall_blog_template_field_updated', $meta_key, $context, $config );

		return $template;
	}

	/**
	 * Remove field
	 *
	 * @since 0.3.0
	 * @param string $context  Context
	 * @param string $meta_key Field name
	 * @return array Updated template
	 */
	public static function remove_field( $context, $meta_key ) {

		$template = self::get();

		if ( isset( $template['discovered_fields'][ $context ][ $meta_key ] ) ) {
			unset( $template['discovered_fields'][ $context ][ $meta_key ] );

			update_option( 'wptsall_template_' . self::TEMPLATE_SLUG, $template );

			wptsall_log( 'template', 'info', sprintf( 'Removed field: %s (%s)', $meta_key, $context ) );

			do_action( 'wptsall_blog_template_field_removed', $meta_key, $context );
		}

		return $template;
	}

	/**
	 * Get field statistics
	 *
	 * @since 0.3.0
	 * @return array
	 */
	public static function get_fields_statistics() {

		$template = self::get();
		$fields   = $template['discovered_fields'] ?? array();

		$stats = array(
			'total'         => 0,
			'by_context'    => array(),
			'by_source'     => array(),
			'by_privacy'    => array(),
			'syncable'      => 0,
			'blocked'       => 0,
			'manual_added'  => 0,
		);

		foreach ( $fields as $context => $context_fields ) {

			$stats['by_context'][ $context ] = count( $context_fields );
			$stats['total'] += count( $context_fields );

			foreach ( $context_fields as $field ) {

				// Stats by source
				$source = $field['source'] ?? 'unknown';
				if ( ! isset( $stats['by_source'][ $source ] ) ) {
					$stats['by_source'][ $source ] = 0;
				}
				++$stats['by_source'][ $source ];

				// Stats by privacy level
				$privacy = $field['privacy'] ?? 'unknown';
				if ( ! isset( $stats['by_privacy'][ $privacy ] ) ) {
					$stats['by_privacy'][ $privacy ] = 0;
				}
				++$stats['by_privacy'][ $privacy ];

				// Sync stats
				if ( $field['sync_enabled'] ?? false ) {
					++$stats['syncable'];
				} else {
					++$stats['blocked'];
				}

				// Manually added stats
				if ( ! ( $field['auto_detected'] ?? true ) ) {
					++$stats['manual_added'];
				}
			}
		}

		return $stats;
	}
}
