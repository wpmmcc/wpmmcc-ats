<?php
/**
 * Admin Hooks
 *
 * Backend admin hooks for displaying virtual site information in post lists and edit pages.
 *
 * @package WPTSALL\Hooks
 * @since 0.5.0
 */

namespace WPTSALL\Hooks;

use WPTSALL\Sites\Services\Virtual_Site_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin_Hooks class
 *
 * Provides admin interface enhancements for virtual sites:
 * - Filter dropdown in post lists
 * - Custom columns showing virtual site status
 * - Meta boxes in post edit screen
 * - Auto-sync on post save
 *
 * @deprecated 0.9.0 Use Admin_Virtual_Site_Manager instead. All functionality has been merged.
 * @see Admin_Virtual_Site_Manager
 */
class Admin_Hooks {

	/**
	 * Post types with virtual site support
	 *
	 * @var array
	 */
	private static $supported_post_types = array( 'post', 'page' );

	/**
	 * Whether hooks are initialized
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize admin hooks
	 *
	 * @return void
	 */
	public static function init() {
		// Only run in admin
		if ( ! is_admin() ) {
			return;
		}

		if ( self::$initialized ) {
			return;
		}

		// Get supported post types from settings
		$settings = get_option( 'wptsall_settings', array() );
		if ( ! empty( $settings['virtual_site_post_types'] ) && is_array( $settings['virtual_site_post_types'] ) ) {
			self::$supported_post_types = $settings['virtual_site_post_types'];
		}

		// Register hooks for each post type
		foreach ( self::$supported_post_types as $post_type ) {
			// Add filter dropdown
			add_action( 'restrict_manage_posts', array( __CLASS__, 'add_virtual_site_filter' ) );

			// Add custom columns
			add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'add_columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}

		// Filter posts by virtual site
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_by_virtual_site' ) );

		// Add meta boxes
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );

		// Save post hook
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );

		// Edit form indicator
		add_action( 'edit_form_top', array( __CLASS__, 'show_virtual_site_indicator' ) );

		self::$initialized = true;
	}

	/**
	 * Add virtual site filter dropdown
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public static function add_virtual_site_filter( $post_type ) {
		if ( ! in_array( $post_type, self::$supported_post_types, true ) ) {
			return;
		}

		// Get all active virtual sites
		$virtual_sites = Virtual_Site_Service::get_all( array( 'status' => 'active' ) );

		if ( empty( $virtual_sites ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['wptsall_virtual_site'] ) ? sanitize_text_field( wp_unslash( $_GET['wptsall_virtual_site'] ) ) : '';

		echo '<select name="wptsall_virtual_site" id="wptsall_virtual_site_filter">';
		echo '<option value="">' . esc_html__( 'All Virtual Sites', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="none"' . selected( $selected, 'none', false ) . '>' . esc_html__( 'No Virtual Site', 'wpmmcc-ats' ) . '</option>';

		foreach ( $virtual_sites as $site ) {
			$site_id   = $site['id'];
			$site_name = $site['name'] ?? $site['path_prefix'];
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $site_id ),
				selected( $selected, $site_id, false ),
				esc_html( $site_name )
			);
		}

		echo '</select>';
	}

	/**
	 * Filter posts by virtual site
	 *
	 * @param \WP_Query $query WordPress query object.
	 * @return void
	 */
	public static function filter_by_virtual_site( $query ) {
		global $pagenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['wptsall_virtual_site'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$virtual_site_filter = sanitize_text_field( wp_unslash( $_GET['wptsall_virtual_site'] ) );

		if ( 'none' === $virtual_site_filter ) {
			// Filter posts without virtual site content
			$post_ids_with_virtual = self::get_posts_with_virtual_content();

			if ( ! empty( $post_ids_with_virtual ) ) {
				$query->set( 'post__not_in', $post_ids_with_virtual );
			}
		} else {
			// Filter posts with specific virtual site content
			$post_ids = self::get_posts_for_virtual_site( intval( $virtual_site_filter ) );

			if ( ! empty( $post_ids ) ) {
				$query->set( 'post__in', $post_ids );
			} else {
				// No posts match, return empty result
				$query->set( 'post__in', array( 0 ) );
			}
		}
	}

	/**
	 * Add custom columns to post list
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// Add virtual site column after title
			if ( 'title' === $key ) {
				$new_columns['wptsall_virtual_sites'] = __( 'Virtual Sites', 'wpmmcc-ats' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column content
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'wptsall_virtual_sites' !== $column ) {
			return;
		}

		$virtual_sites = self::get_virtual_sites_for_post( $post_id );

		if ( empty( $virtual_sites ) ) {
			echo '<span class="wptsall-no-virtual-site">—</span>';
			return;
		}

		$badges = array();
		foreach ( $virtual_sites as $site ) {
			$site_name = esc_html( $site['name'] ?? $site['path_prefix'] );
			$lang      = ! empty( $site['lang'] ) ? ' (' . esc_html( $site['lang'] ) . ')' : '';
			$badges[]  = '<span class="wptsall-virtual-site-badge">' . $site_name . $lang . '</span>';
		}

		echo wp_kses_post( implode( ' ', $badges ) );
	}

	/**
	 * Show virtual site indicator at top of edit form
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function show_virtual_site_indicator( $post ) {
		if ( ! in_array( $post->post_type, self::$supported_post_types, true ) ) {
			return;
		}

		$virtual_sites = self::get_virtual_sites_for_post( $post->ID );

		if ( empty( $virtual_sites ) ) {
			return;
		}

		echo '<div class="wptsall-virtual-site-notice notice notice-info inline">';
		echo '<p><strong>' . esc_html__( 'Virtual Site Copies:', 'wpmmcc-ats' ) . '</strong> ';

		$site_names = array();
		foreach ( $virtual_sites as $site ) {
			$site_name    = esc_html( $site['name'] ?? $site['path_prefix'] );
			$lang         = ! empty( $site['lang'] ) ? ' (' . esc_html( $site['lang'] ) . ')' : '';
			$site_names[] = $site_name . $lang;
		}

		echo esc_html( implode( ', ', $site_names ) );
		echo '</p></div>';
	}

	/**
	 * Add meta boxes for virtual site management
	 *
	 * @return void
	 */
	public static function add_meta_boxes() {
		foreach ( self::$supported_post_types as $post_type ) {
			add_meta_box(
				'wptsall_virtual_site_meta',
				__( 'Virtual Sites', 'wpmmcc-ats' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render meta box content
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'wptsall_virtual_site_meta', 'wptsall_virtual_site_nonce' );

		$virtual_sites = Virtual_Site_Service::get_all( array( 'status' => 'active' ) );

		if ( empty( $virtual_sites ) ) {
			echo '<p>' . esc_html__( 'No virtual sites configured.', 'wpmmcc-ats' ) . '</p>';
			return;
		}

		$post_virtual_sites = self::get_virtual_sites_for_post( $post->ID );
		$post_site_ids      = wp_list_pluck( $post_virtual_sites, 'id' );

		echo '<div class="wptsall-virtual-site-meta-content">';

		foreach ( $virtual_sites as $site ) {
			$site_name = $site['name'] ?? $site['path_prefix'];
			$lang      = ! empty( $site['lang'] ) ? ' (' . $site['lang'] . ')' : '';
			$has_copy  = in_array( $site['id'], $post_site_ids, true );

			echo '<div class="wptsall-virtual-site-item">';

			if ( $has_copy ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> ';
				printf(
					'<strong>%s%s</strong>',
					esc_html( $site_name ),
					esc_html( $lang )
				);
				echo ' <em>(' . esc_html__( 'Has copy', 'wpmmcc-ats' ) . ')</em>';
			} else {
				echo '<span class="dashicons dashicons-minus" style="color:#999;"></span> ';
				printf( '%s%s', esc_html( $site_name ), esc_html( $lang ) );
			}

			echo '</div>';
		}

		echo '</div>';

		// Sync button
		echo '<div style="margin-top:12px;">';
		echo '<label>';
		echo '<input type="checkbox" name="wptsall_sync_on_save" value="1"> ';
		echo esc_html__( 'Sync to virtual sites on save', 'wpmmcc-ats' );
		echo '</label>';
		echo '</div>';
	}

	/**
	 * Handle post save
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post, $update ) {
		// Verify nonce
		if ( ! isset( $_POST['wptsall_virtual_site_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wptsall_virtual_site_nonce'] ) ), 'wptsall_virtual_site_meta' ) ) {
			return;
		}

		// Check autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check user capability
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check post type
		if ( ! in_array( $post->post_type, self::$supported_post_types, true ) ) {
			return;
		}

		// Check if sync requested
		if ( empty( $_POST['wptsall_sync_on_save'] ) ) {
			return;
		}

		// Trigger sync action
		do_action( 'wptsall_sync_post_to_virtual_sites', $post_id, $post );

		// Log sync request
		if ( function_exists( 'wptsall_log' ) ) {
			wptsall_log(
				'admin',
				'info',
				'Post sync to virtual sites requested',
				array(
					'post_id'   => $post_id,
					'post_type' => $post->post_type,
				)
			);
		}
	}

	/**
	 * Get virtual sites that have content for a post
	 *
	 * @since 0.7.0 Updated to use virtual_site_content table
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function get_virtual_sites_for_post( $post_id ) {
		global $wpdb;

		$table_name = wptsall_table( 'virtual_site_content' );

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		) === $table_name;

		if ( ! $table_exists ) {
			return array();
		}

		// Get virtual site IDs that have content for this post
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$virtual_site_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT virtual_site_id FROM %i WHERE source_object_id = %d AND object_type = %s',
				$table_name,
				intval( $post_id ),
				'post_type'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder

		if ( empty( $virtual_site_ids ) ) {
			return array();
		}

		// Get virtual site details
		$virtual_sites = array();

		foreach ( $virtual_site_ids as $virtual_site_id ) {
			// Extract numeric ID from virtual_site_id (e.g., 'v_2' -> 2)
			$site_id = intval( str_replace( 'v_', '', $virtual_site_id ) );
			$site = Virtual_Site_Service::get( $site_id );
			if ( $site ) {
				$virtual_sites[] = $site;
			}
		}

		return $virtual_sites;
	}

	/**
	 * Get posts that have virtual content
	 *
	 * @since 0.7.0 Updated to use virtual_site_content table
	 * @return array Post IDs.
	 */
	private static function get_posts_with_virtual_content() {
		global $wpdb;

		$table_name = wptsall_table( 'virtual_site_content' );

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		) === $table_name;

		if ( ! $table_exists ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT source_object_id FROM %i WHERE object_type = %s',
				$table_name,
				'post_type'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder

		return array_map( 'intval', $post_ids );
	}

	/**
	 * Get posts for a specific virtual site
	 *
	 * Note: This queries the legacy virtual_site_content table.
	 * The replacement class Admin_Virtual_Site_Manager uses wp_posts + _wptsall_virtual_site_id meta instead.
	 *
	 * @since 0.7.0 Updated to use virtual_site_content table
	 * @deprecated 0.9.0 Use Admin_Virtual_Site_Manager instead.
	 * @param int|string $site_id Virtual site ID (numeric) or prefixed ID (e.g. 'v_2').
	 * @return array Post IDs.
	 */
	private static function get_posts_for_virtual_site( $site_id ) {
		global $wpdb;

		$table_name = wptsall_table( 'virtual_site_content' );

		// Check if table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		) === $table_name;

		if ( ! $table_exists ) {
			return array();
		}

		// Convert site_id to virtual_site_id format, accepting both 'v_2' and plain '2'.
		$virtual_site_id = is_string( $site_id ) && str_starts_with( $site_id, 'v_' )
			? $site_id
			: 'v_' . intval( $site_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT source_object_id FROM %i WHERE virtual_site_id = %s AND object_type = %s',
				$table_name,
				$virtual_site_id,
				'post_type'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder

		return array_map( 'intval', $post_ids );
	}

	/**
	 * Check if admin hooks are initialized
	 *
	 * @return bool
	 */
	public static function is_initialized() {
		return self::$initialized;
	}

	/**
	 * Get supported post types
	 *
	 * @return array
	 */
	public static function get_supported_post_types() {
		return self::$supported_post_types;
	}
}
