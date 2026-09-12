<?php
/**
 * WPTSALL Sites Page
 *
 * Sites management page - Integrated site relations management
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.6.0 - Add many-to-many model association support and monitoring task display
 * @updated 0.7.0 - Remove language pack management tab (global language packs managed by Templates module)
 */

namespace WPTSALL\Sites\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Models\Services\Translation_Rule_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sites Page Class
 *
 * Tab structure (v0.7.0):
 * 1. Site relations - Display all site relations (grouped by model)
 * 2. Add site relation - Create new site relation (auto-select virtual site)
 * 3. Add virtual site - Create new virtual site
 * 4. Virtual site list - Display all virtual sites
 *
 * Removed (v0.7.0):
 * - Hook config - Hooks auto-registered, no manual configuration needed
 * - Language packs - Global language packs managed independently by Templates module
 */
class Sites_Page {

	/**
	 * Page slug
	 */
	const PAGE_SLUG = 'wptsall-sites';

	/**
	 * Current tab
	 *
	 * @var string
	 */
	private static $active_tab = 'virtual_list';

	/**
	 * Initialize
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'wptsall-sites' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wptsall-sites',
			WPTSALL_URL . 'assets/css/sites.css',
			array(),
			WPTSALL_VERSION
		);

		// Legacy assets/js/sites.js (option-backed /sites CRUD) removed from enqueue.
		// Active UI uses site-relations.js via Sites module.
	}

	/**
	 * Render page
	 *
	 * Uses unified page structure from Admin_Page_Helper.
	 * Structure: wrap > h1 > hr.wp-header-end > (admin notices) > tabs > content
	 */
	public static function render_page() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'virtual_list';

		$tabs = array(
			'virtual_list' => __( 'Virtual Sites', 'wpmmcc-ats' ),
			'conflicts'    => __( 'Conflicts', 'wpmmcc-ats' ),
			'add'          => __( 'Add Relation', 'wpmmcc-ats' ),
			'add_virtual'  => __( 'Add Virtual Site', 'wpmmcc-ats' ),
			'list'         => __( 'All Relations', 'wpmmcc-ats' ),
		);

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$add_url  = add_query_arg( 'tab', 'add', $base_url );

		$actions = array(
			array(
				'label' => __( 'Add Site Relation', 'wpmmcc-ats' ),
				'url'   => $add_url,
				'class' => 'button button-primary',
			),
		);

		Admin_Page_Helper::render_header(
			__( 'Sites Management', 'wpmmcc-ats' ),
			'sites',
			$actions
		);

		Admin_Page_Helper::render_tabs( $tabs, self::$active_tab, $base_url );

		// #9 (public-repo deep E2E ledger 2026-09-11): after a relation is
		// created the scan has already set up the model automatically —
		// guide the user to the next step (adding the first translation
		// rule) instead of leaving the flow without direction.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$relation_created = ! empty( $_GET['wptsall_relation_created'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$created_model_id = isset( $_GET['wptsall_model_id'] ) ? absint( $_GET['wptsall_model_id'] ) : 0;
		if ( $relation_created ) {
			$rule_url = add_query_arg(
				array(
					'page'     => 'wpmmcc-ats',
					'action'   => 'edit',
					'model_id' => $created_model_id > 0 ? $created_model_id : false,
				),
				admin_url( 'admin.php' )
			);
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %s: link to add the first translation rule for the auto-created model */
				esc_html__( 'Site relation created. The model was set up automatically by the scan. Next step: %s to start translating.', 'wpmmcc-ats' ),
				'<a class="button button-primary" style="margin-left:6px;" href="' . esc_url( $rule_url ) . '">' . esc_html__( 'Add First Rule', 'wpmmcc-ats' ) . '</a>'
			);
			echo '</p></div>';
		}

		echo '<div class="wptsall-page-content">';
		switch ( self::$active_tab ) {
			case 'add':
				self::render_add_tab();
				break;
			case 'add_virtual':
				self::render_add_virtual_tab();
				break;
			case 'virtual_list':
				self::render_virtual_list_tab();
				break;
			case 'conflicts':
				self::render_conflicts_tab();
				break;
			default:
				self::render_list_tab();
				break;
		}
		echo '</div>'; // .wptsall-page-content

		// Render edit modal
		self::render_edit_modal();

		// Render virtual site modal (v0.6.0)
		self::render_virtual_site_modal();

		Admin_Page_Helper::render_footer();
	}

	/**
	 * Render site list tab (v0.5.1 - standard table view)
	 */
	private static function render_list_tab() {
		// Get all site relations
		$all_relations = Site_Relation_Service::get_all_relations();

		// Get filter parameters
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_source = isset( $_GET['filter_source'] ) ? intval( $_GET['filter_source'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_target = isset( $_GET['filter_target'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_target'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_plugin = isset( $_GET['filter_plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_plugin'] ) ) : '';

		// Apply filters
		$relations = array();
		foreach ( $all_relations as $rel ) {
			// Source site filter
			if ( $filter_source && (int) $rel['source_site_id'] !== $filter_source ) {
				continue;
			}

			// Target site filter
			if ( $filter_target && $rel['target_site_id'] !== $filter_target ) {
				continue;
			}

			// Plugin filter
			if ( $filter_plugin && $rel['template'] !== $filter_plugin ) {
				continue;
			}

			$relations[] = $rel;
		}

		// Get all available source sites, target sites, plugins for filter options
		$source_sites = array();
		$target_sites = array();
		$plugins      = array();

		foreach ( $all_relations as $rel ) {
			// Collect source sites
			$source_key = $rel['source_site_id'];
			if ( ! isset( $source_sites[ $source_key ] ) ) {
				$source_sites[ $source_key ] = self::get_site_name( $rel['source_site_id'] );
			}

			// Collect target sites
			$target_key = $rel['target_site_id'];
			if ( ! isset( $target_sites[ $target_key ] ) ) {
				$target_sites[ $target_key ] = $rel['target_site_id'];
			}

			// Collect plugins
			$plugin_key = $rel['template'];
			if ( ! isset( $plugins[ $plugin_key ] ) ) {
				$plugins[ $plugin_key ] = ucwords( str_replace( array( '-', '_' ), ' ', $rel['template'] ) );
			}
		}

		echo '<div class="wptsall-sites-list">';

		// Filter bar - WordPress standard inline layout
		echo '<div class="tablenav top">';
		echo '<form method="get" action="" class="alignleft actions">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<input type="hidden" name="page" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ?? 'wptsall-sites' ) ) ) . '">';
		echo '<input type="hidden" name="tab" value="list">';

		// Source site filter
		echo '<label for="filter_source" class="screen-reader-text">' . esc_html__( 'Source Site', 'wpmmcc-ats' ) . '</label>';
		echo '<select name="filter_source" id="filter_source">';
		echo '<option value="">' . esc_html__( 'Source Site', 'wpmmcc-ats' ) . '</option>';
		foreach ( $source_sites as $id => $name ) {
			$selected = ( $filter_source === (int) $id ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $id ) . '"' . esc_attr( $selected ) . '>' . esc_html( $name ) . ' (#' . esc_html( $id ) . ')</option>';
		}
		echo '</select>';

		// Target site filter
		echo '<label for="filter_target" class="screen-reader-text">' . esc_html__( 'Target Site', 'wpmmcc-ats' ) . '</label>';
		echo '<select name="filter_target" id="filter_target">';
		echo '<option value="">' . esc_html__( 'Target Site', 'wpmmcc-ats' ) . '</option>';
		foreach ( $target_sites as $id => $name ) {
			$selected = ( $filter_target === $id ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $id ) . '"' . esc_attr( $selected ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';

		// Plugin filter
		echo '<label for="filter_plugin" class="screen-reader-text">' . esc_html__( 'Plugin/Model', 'wpmmcc-ats' ) . '</label>';
		echo '<select name="filter_plugin" id="filter_plugin">';
		echo '<option value="">' . esc_html__( 'Plugin/Model', 'wpmmcc-ats' ) . '</option>';
		foreach ( $plugins as $slug => $name ) {
			$selected = ( $filter_plugin === $slug ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $slug ) . '"' . esc_attr( $selected ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';

		// Filter button
		echo '<input type="submit" class="button" value="' . esc_attr__( 'Filter', 'wpmmcc-ats' ) . '">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=wptsall-sites&tab=list' ) ) . '" class="button" style="margin-left: 4px;">' . esc_html__( 'Reset', 'wpmmcc-ats' ) . '</a>';

		echo '</form>';
		echo '</div>';

		// List statistics
		echo '<p style="margin-bottom: 15px;">';
		echo sprintf(
			/* translators: %d: number of relations */
			esc_html__( 'Total %d site relations', 'wpmmcc-ats' ),
			count( $relations )
		);
		echo '</p>';

		if ( empty( $relations ) ) {
			echo '<div class="wptsall-no-sites">';
			echo '<p>' . esc_html__( 'No site relations configured yet.', 'wpmmcc-ats' ) . '</p>';
			echo '<p><a href="' . esc_url( add_query_arg( 'tab', 'add' ) ) . '" class="button button-primary">' . esc_html__( 'Add Site', 'wpmmcc-ats' ) . '</a></p>';
			echo '</div>';
		} else {
			// Standard table
			echo '<table class="wp-list-table widefat fixed striped wptsall-relations-table">';
			echo '<thead><tr>';
			echo '<th style="width:60px;">' . esc_html__( 'ID', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Source Site', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Source Language', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Target Site', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Target Language', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'wpmmcc-ats' ) . '</th>';
			echo '<th style="width:100px;">' . esc_html__( 'Models', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'wpmmcc-ats' ) . '</th>';
			echo '<th style="width:220px;">' . esc_html__( 'Actions', 'wpmmcc-ats' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $relations as $rel ) {
				$site_type    = $rel['target_site_type'] ?? 'virtual';
				$type_label   = ( 'wp' === $site_type ) ? __( 'WP Site', 'wpmmcc-ats' ) : __( 'Virtual Sites', 'wpmmcc-ats' );
				$status       = $rel['status'] ?? 'active';
				$status_label = ( 'inactive' === $status ) ? __( 'Disabled', 'wpmmcc-ats' ) : __( 'Enabled', 'wpmmcc-ats' );
				$source_name  = self::get_site_name( $rel['source_site_id'] );

				// v0.6.0: Get associated model count.
				$models_count = $rel['models_count'] ?? 0;
				if ( ! $models_count ) {
					$models_count = Relation_Model_Service::get_models_count( (int) $rel['id'] );
				}

				echo '<tr data-relation-id="' . esc_attr( $rel['id'] ) . '">';
				echo '<td><code>#' . esc_html( $rel['id'] ) . '</code></td>';
				echo '<td>' . esc_html( $source_name ) . '<br><small>#' . esc_html( $rel['source_site_id'] ) . '</small></td>';
				echo '<td><span class="lang-badge">' . esc_html( $rel['source_lang'] ?: 'en_US' ) . '</span></td>';
				echo '<td><strong>' . esc_html( $rel['target_site_id'] ) . '</strong></td>';
				echo '<td><span class="lang-badge">' . esc_html( $rel['target_lang'] ?? '' ) . '</span></td>';
				echo '<td>' . esc_html( $type_label ) . '</td>';
				// v0.6.0: Display associated model count.
				echo '<td>';
				if ( $models_count > 0 ) {
					echo '<a href="#" class="wptsall-view-models-btn" data-relation-id="' . esc_attr( $rel['id'] ) . '">';
					echo '<span class="wptsall-models-count">' . esc_html( $models_count ) . '</span> ';
					echo esc_html__( ' models', 'wpmmcc-ats' );
					echo '</a>';
				} else {
					echo '<span class="wptsall-no-models">' . esc_html__( 'None', 'wpmmcc-ats' ) . '</span>';
					echo ' <a href="#" class="wptsall-add-models-btn button-link" data-relation-id="' . esc_attr( $rel['id'] ) . '">';
					echo esc_html__( 'Add', 'wpmmcc-ats' );
					echo '</a>';
				}
				echo '</td>';
				echo '<td><span class="wptsall-status wptsall-status-' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span></td>';
				echo '<td class="wptsall-actions">';
				echo '<button class="button button-small wptsall-sync-theme-btn" data-relation-id="' . esc_attr( $rel['id'] ) . '" data-source-id="' . esc_attr( $rel['source_site_id'] ) . '" title="' . esc_attr__( 'Sync Theme', 'wpmmcc-ats' ) . '">';
				echo '<span class="dashicons dashicons-update"></span>';
				echo '</button> ';
				echo '<button class="button button-small wptsall-edit-relation-btn" data-relation-id="' . esc_attr( $rel['id'] ) . '">' . esc_html__( 'Edit', 'wpmmcc-ats' ) . '</button> ';
				echo '<button class="button button-small wptsall-config-relation-btn" data-relation-id="' . esc_attr( $rel['id'] ) . '" title="' . esc_attr__( 'Post Type Configuration', 'wpmmcc-ats' ) . '">' . esc_html__( 'Configuration', 'wpmmcc-ats' ) . '</button> ';
				echo '<button class="button button-small wptsall-copy-config-btn" data-relation-id="' . esc_attr( $rel['id'] ) . '" title="' . esc_attr__( 'Copy configuration to other relations', 'wpmmcc-ats' ) . '">';
				echo '<span class="dashicons dashicons-admin-page"></span>';
				echo '</button> ';
				echo '<button class="button button-small button-link-delete wptsall-delete-relation-btn" data-relation-id="' . esc_attr( $rel['id'] ) . '">' . esc_html__( 'Delete', 'wpmmcc-ats' ) . '</button>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * Get site name by ID
	 *
	 * @param int $site_id Site ID.
	 * @return string
	 */
	private static function get_site_name( $site_id ) {
		if ( is_multisite() ) {
			$site = get_blog_details( $site_id );
			return $site ? $site->blogname : '#' . $site_id;
		}
		return get_bloginfo( 'name' );
	}

	/**
	 * Render add site relation tab (v0.5.0 - with virtual site dropdown)
	 */
	private static function render_add_tab() {
		$models          = Translation_Rule_Service::get_models();
		$languages       = self::get_available_languages();
		$current_lang    = get_option( 'WPLANG', 'en_US' ) ?: 'en_US';
		$virtual_sites   = Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
		$multisite_sites = is_multisite() ? get_sites( array( 'number' => 100 ) ) : array();

		echo '<div class="wptsall-add-site">';
		echo '<form id="wptsall-create-relation-form" method="post">';
		wp_nonce_field( 'wptsall_add_relation', 'wptsall_relation_nonce' );

		echo '<h3>' . esc_html__( 'Create Site Relation', 'wpmmcc-ats' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Site relations define translation/sync mappings between source sites and target sites.', 'wpmmcc-ats' ) . '</p>';

		echo '<table class="form-table">';

		// Source site section
		echo '<tr><th colspan="2"><h4 style="margin:0;">' . esc_html__( 'Source Site Configuration', 'wpmmcc-ats' ) . '</h4></th></tr>';

		// Source site ID
		echo '<tr>';
		echo '<th><label for="source_site_id">' . esc_html__( 'Source Site', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		if ( is_multisite() ) {
			$sites = get_sites( array( 'number' => 100 ) );
			echo '<select name="source_site_id" id="source_site_id" required>';
			foreach ( $sites as $site ) {
				$selected = ( (int) $site->blog_id === get_current_blog_id() ) ? ' selected' : '';
				echo '<option value="' . esc_attr( $site->blog_id ) . '"' . esc_attr( $selected ) . '>' . esc_html( $site->blogname ?: $site->domain . $site->path ) . ' (#' . esc_html( $site->blog_id ) . ')</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="source_site_id" value="1">';
			echo '<span class="description">' . esc_html( get_bloginfo( 'name' ) ) . ' (#1)</span>';
		}
		echo '</td>';
		echo '</tr>';

		// Source language
		echo '<tr>';
		echo '<th><label for="source_lang">' . esc_html__( 'Source Language', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		echo '<select name="source_lang" id="source_lang" class="wptsall-select2" data-placeholder="' . esc_attr__( 'Select Source Language', 'wpmmcc-ats' ) . '" required>';
		echo '<option value=""></option>'; // Empty option for placeholder.
		foreach ( $languages as $code => $name ) {
			$selected = ( $code === $current_lang ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $code ) . '"' . esc_attr( $selected ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Source site content language.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Template/Model
		echo '<tr>';
		echo '<th><label for="template">' . esc_html__( 'Template/Model', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		echo '<select name="template" id="template" required>';
		echo '<option value="wordpress-blog">' . esc_html__( 'WordPress Blog (default)', 'wpmmcc-ats' ) . '</option>';
		if ( ! empty( $models['models'] ) ) {
			foreach ( $models['models'] as $model ) {
				echo '<option value="' . esc_attr( $model['plugin_slug'] ) . '">' . esc_html( $model['plugin_name'] ) . '</option>';
			}
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Select the content model to sync.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Target sites section
		echo '<tr><th colspan="2"><h4 style="margin:0;">' . esc_html__( 'Target Site Configuration', 'wpmmcc-ats' ) . '</h4></th></tr>';

		// Target site type
		echo '<tr>';
		echo '<th><label for="target_site_type">' . esc_html__( 'Target Type', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		echo '<select name="target_site_type" id="target_site_type" required>';
		echo '<option value="virtual">' . esc_html__( 'Virtual Sites', 'wpmmcc-ats' ) . '</option>';
		if ( is_multisite() ) {
			echo '<option value="wp">' . esc_html__( 'WordPress Site', 'wpmmcc-ats' ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Virtual sites use copy records, WordPress sites are real multisite subsites.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Target site dropdown (for virtual sites)
		echo '<tr id="target_virtual_row">';
		echo '<th><label for="target_virtual_site">' . esc_html__( 'Target Virtual Site', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		if ( empty( $virtual_sites ) ) {
			echo '<p class="description" style="color: #d63638;">';
			echo esc_html__( 'No virtual sites yet, please', 'wpmmcc-ats' ) . ' ';
			echo '<a href="' . esc_url( add_query_arg( 'tab', 'add_virtual' ) ) . '">' . esc_html__( 'Add Virtual Site', 'wpmmcc-ats' ) . '</a>';
			echo '</p>';
			echo '<input type="hidden" name="target_site_id" id="target_site_id" value="">';
			echo '<input type="hidden" name="target_lang" id="target_lang" value="">';
		} else {
			echo '<select name="target_virtual_site" id="target_virtual_site" required>';
			echo '<option value="">' . esc_html__( 'Select virtual site', 'wpmmcc-ats' ) . '</option>';
			foreach ( $virtual_sites as $vs ) {
				echo '<option value="' . esc_attr( $vs['id'] ) . '" data-lang="' . esc_attr( $vs['lang'] ) . '">';
				echo esc_html( $vs['name'] ) . ' (/' . esc_html( $vs['path_prefix'] ) . '/ - ' . esc_html( $vs['lang'] ) . ')';
				echo '</option>';
			}
			echo '</select>';
			echo '<input type="hidden" name="target_site_id" id="target_site_id" value="">';
			echo '<input type="hidden" name="target_lang" id="target_lang" value="">';
			echo '<p class="description">' . esc_html__( 'Target language will be set automatically when a virtual site is selected.', 'wpmmcc-ats' ) . '</p>';
		}
		echo '</td>';
		echo '</tr>';

		// Target site dropdown (for WordPress multisite)
		echo '<tr id="target_wp_row" style="display:none;">';
		echo '<th><label for="target_wp_site">' . esc_html__( 'Target WordPress Site', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		if ( is_multisite() && ! empty( $multisite_sites ) ) {
			echo '<select name="target_wp_site" id="target_wp_site">';
			echo '<option value="">' . esc_html__( 'Select site', 'wpmmcc-ats' ) . '</option>';
			foreach ( $multisite_sites as $ms_site ) {
				if ( (int) $ms_site->blog_id === get_current_blog_id() ) {
					continue; // Skip current site
				}
				$site_lang = get_blog_option( $ms_site->blog_id, 'WPLANG', 'en_US' ) ?: 'en_US';
				echo '<option value="' . esc_attr( $ms_site->blog_id ) . '" data-lang="' . esc_attr( $site_lang ) . '">';
				echo esc_html( $ms_site->blogname ?: $ms_site->domain . $ms_site->path ) . ' (#' . esc_html( $ms_site->blog_id ) . ' - ' . esc_html( $site_lang ) . ')';
				echo '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Target language will be set automatically to the selected site\'s language.', 'wpmmcc-ats' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Multisite is not enabled or no sites available.', 'wpmmcc-ats' ) . '</p>';
		}
		echo '</td>';
		echo '</tr>';

		// Media handling option
		echo '<tr>';
		echo '<th><label for="media_handling">' . esc_html__( 'Media Handling', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		echo '<select name="media_handling" id="media_handling">';
		echo '<option value="copy">' . esc_html__( 'Copy', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="reference">' . esc_html__( 'Reference', 'wpmmcc-ats' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Copy: copies media files to target site | Reference: stores original media URL only, no file copying', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		// Unique constraint notice
		echo '<div class="notice notice-info inline" style="margin: 20px 0;">';
		echo '<p><strong>' . esc_html__( 'Uniqueness Constraint', 'wpmmcc-ats' ) . ':</strong> ';
		echo esc_html__( 'The combination of source site + source language + template + target site + target language must be unique.', 'wpmmcc-ats' );
		echo '</p>';
		echo '</div>';

		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create Relation', 'wpmmcc-ats' ) . '</button>';
		echo '</p>';

		echo '</form>';
		echo '</div>';

		// JavaScript to handle target type switching.
		$script_rel  = 'assets/js/sites-target-switch.js';
		$script_path = WPTSALL_PATH . $script_rel;
		wp_enqueue_script(
			'wptsall-sites-target-switch',
			WPTSALL_URL . $script_rel,
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : WPTSALL_VERSION,
			true
		);
	}

	/**
	 * Render add virtual site tab (v0.5.0)
	 */
	private static function render_add_virtual_tab() {
		$languages = self::get_available_languages();

		echo '<div class="wptsall-add-virtual-site">';
		echo '<form id="wptsall-create-virtual-site-form" method="post">';
		wp_nonce_field( 'wptsall_add_virtual_site', 'wptsall_virtual_site_nonce' );

		echo '<h3>' . esc_html__( 'Add Virtual Site', 'wpmmcc-ats' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Virtual sites are virtual path configurations for translated content, no real WordPress multisite needed.', 'wpmmcc-ats' ) . '</p>';

		echo '<table class="form-table">';

		// Site name
		echo '<tr>';
		echo '<th><label for="site_name">' . esc_html__( 'Site Name', 'wpmmcc-ats' ) . ' <span class="required">*</span></label></th>';
		echo '<td>';
		echo '<input type="text" name="site_name" id="site_name" class="regular-text" required placeholder="' . esc_attr__( 'e.g. Chinese Site', 'wpmmcc-ats' ) . '">';
		echo '<p class="description">' . esc_html__( 'Display name for the virtual site.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Site path
		echo '<tr>';
		echo '<th><label for="site_path">' . esc_html__( 'Site Path', 'wpmmcc-ats' ) . ' <span class="required">*</span></label></th>';
		echo '<td>';
		echo '<div style="display: flex; align-items: center;">';
		echo '<span style="margin-right: 5px;">' . esc_html( home_url( '/' ) ) . '</span>';
		echo '<input type="text" name="site_path" id="site_path" style="width: 100px;" required pattern="[a-zA-Z0-9_-]+" placeholder="zh">';
		echo '<span style="margin-left: 5px;">/</span>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'URL path prefix, can only contain letters, numbers, hyphens and underscores.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Site language
		echo '<tr>';
		echo '<th><label for="site_language">' . esc_html__( 'Site Language', 'wpmmcc-ats' ) . ' <span class="required">*</span></label></th>';
		echo '<td>';
		echo '<select name="site_language" id="site_language" class="wptsall-select2" data-placeholder="' . esc_attr__( 'Select site language', 'wpmmcc-ats' ) . '" required>';
		echo '<option value=""></option>'; // Empty option for placeholder.
		foreach ( $languages as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Target language for the virtual site.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Permalink structure (v0.6.0)
		echo '<tr>';
		echo '<th><label for="site_permalink">' . esc_html__( 'Permalink Structure', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		echo '<select name="site_permalink" id="site_permalink">';
		echo '<option value="">' . esc_html__( 'Inherit from source site', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="plain">' . esc_html__( 'Plain', 'wpmmcc-ats' ) . ' (?p=123)</option>';
		echo '<option value="/%year%/%monthnum%/%day%/%postname%/">' . esc_html__( 'Day and name', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="/%year%/%monthnum%/%postname%/">' . esc_html__( 'Month and name', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="/archives/%post_id%">' . esc_html__( 'Numeric', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="/%postname%/">' . esc_html__( 'Post name', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="custom">' . esc_html__( 'Custom Structure', 'wpmmcc-ats' ) . '</option>';
		echo '</select>';
		/* translators: %category% and %postname% are WordPress permalink structure tags, not printf placeholders */
		echo '<input type="text" name="site_permalink_custom" id="site_permalink_custom" class="regular-text" style="display:none; margin-top: 10px;" placeholder="' . esc_attr__( 'e.g. /%category%/%postname%/', 'wpmmcc-ats' ) . '">';
		echo '<p class="description">' . esc_html__( 'Permalink structure for the virtual site. Leave empty to inherit from source site.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Category base (v0.6.0)
		echo '<tr>';
		echo '<th><label for="site_category_base">' . esc_html__( 'Category Base', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" name="site_category_base" id="site_category_base" class="regular-text" placeholder="category">';
		echo '<p class="description">' . esc_html__( 'URL prefix for categories. Leave empty to inherit from source site.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Tag base (v0.6.0)
		echo '<tr>';
		echo '<th><label for="site_tag_base">' . esc_html__( 'Tag Base', 'wpmmcc-ats' ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" name="site_tag_base" id="site_tag_base" class="regular-text" placeholder="tag">';
		echo '<p class="description">' . esc_html__( 'URL prefix for tags. Leave empty to inherit from source site.', 'wpmmcc-ats' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create Virtual Site', 'wpmmcc-ats' ) . '</button>';
		echo '</p>';

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render virtual site list tab (v0.5.0)
	 */
	private static function render_virtual_list_tab() {
		$virtual_sites = Virtual_Site_Service::get_all();
		$languages     = self::get_available_languages();

		echo '<div class="wptsall-virtual-sites-list">';
		echo '<h3>' . esc_html__( 'Virtual Site List', 'wpmmcc-ats' ) . '</h3>';

		if ( empty( $virtual_sites ) ) {
			echo '<div class="wptsall-no-sites">';
			echo '<p>' . esc_html__( 'No virtual sites created yet.', 'wpmmcc-ats' ) . '</p>';
			echo '<p><a href="' . esc_url( add_query_arg( 'tab', 'add_virtual' ) ) . '" class="button button-primary">' . esc_html__( 'Add Virtual Site', 'wpmmcc-ats' ) . '</a></p>';
			echo '</div>';
		} else {
			// Bulk actions (ISS-SIT-034).
			echo '<div class="tablenav top">';
			echo '<div class="alignleft actions bulkactions">';
			echo '<label class="screen-reader-text" for="wptsall-virtual-bulk-action">' . esc_html__( 'Bulk Actions', 'wpmmcc-ats' ) . '</label>';
			echo '<select id="wptsall-virtual-bulk-action" class="wptsall-virtual-bulk-action">';
			echo '<option value="">' . esc_html__( 'Bulk Actions', 'wpmmcc-ats' ) . '</option>';
			echo '<option value="activate">' . esc_html__( 'Set to Active', 'wpmmcc-ats' ) . '</option>';
			echo '<option value="deactivate">' . esc_html__( 'Set to Inactive', 'wpmmcc-ats' ) . '</option>';
			echo '<option value="delete">' . esc_html__( 'Delete', 'wpmmcc-ats' ) . '</option>';
			echo '</select> ';
			echo '<button type="button" class="button action wptsall-virtual-bulk-apply">' . esc_html__( 'Apply', 'wpmmcc-ats' ) . '</button>';
			echo '</div>';
			echo '</div>';

			echo '<table class="wp-list-table widefat fixed striped wptsall-virtual-sites-table">';
			echo '<thead><tr>';
			echo '<td class="manage-column column-cb check-column">';
			echo '<input type="checkbox" class="wptsall-virtual-select-all" aria-label="' . esc_attr__( 'Select All', 'wpmmcc-ats' ) . '">';
			echo '</td>';
			echo '<th style="width: 60px;">' . esc_html__( 'ID', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Site Name', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Site Path', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Language', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Created At', 'wpmmcc-ats' ) . '</th>';
			echo '<th style="width: 180px;">' . esc_html__( 'Actions', 'wpmmcc-ats' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $virtual_sites as $site ) {
				$status       = $site['status'] ?? 'active';
				$status_label = ( 'inactive' === $status ) ? __( 'Disabled', 'wpmmcc-ats' ) : __( 'Enabled', 'wpmmcc-ats' );
				$lang_name    = $languages[ $site['lang'] ] ?? $site['lang'];
				$path_prefix  = $site['path_prefix'] ?? '';
				$full_url     = home_url( '/' . $path_prefix . '/' );

				echo '<tr data-site-id="' . esc_attr( $site['id'] ) . '">';
				echo '<th scope="row" class="check-column">';
				echo '<input type="checkbox" class="wptsall-virtual-select" value="' . esc_attr( $site['id'] ) . '">';
				echo '</th>';
				echo '<td><code>#' . esc_html( $site['id'] ) . '</code></td>';
				echo '<td><strong>' . esc_html( $site['name'] ) . '</strong></td>';
				echo '<td>';
				echo '<code>/' . esc_html( $path_prefix ) . '/</code>';
				echo ' <a href="' . esc_url( $full_url ) . '" target="_blank" title="' . esc_attr__( 'Preview', 'wpmmcc-ats' ) . '">';
				echo '<span class="dashicons dashicons-external" style="font-size: 14px;"></span>';
				echo '</a>';
				echo '</td>';
				echo '<td><span class="lang-badge">' . esc_html( $lang_name ) . '</span></td>';
				echo '<td><span class="wptsall-status wptsall-status-' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span></td>';
				echo '<td>' . esc_html( $site['created_at'] ?? '-' ) . '</td>';
				echo '<td class="wptsall-actions">';
				echo '<button class="button button-small wptsall-edit-virtual-site" data-site-id="' . esc_attr( $site['id'] ) . '">' . esc_html__( 'Edit', 'wpmmcc-ats' ) . '</button> ';
				echo '<button class="button button-small wptsall-toggle-virtual-site" data-site-id="' . esc_attr( $site['id'] ) . '" data-status="' . esc_attr( $status ) . '">';
				echo ( 'active' === $status ) ? esc_html__( 'Disabled', 'wpmmcc-ats' ) : esc_html__( 'Enabled', 'wpmmcc-ats' );
				echo '</button> ';
				echo '<button class="button button-small button-link-delete wptsall-delete-virtual-site" data-site-id="' . esc_attr( $site['id'] ) . '">' . esc_html__( 'Delete', 'wpmmcc-ats' ) . '</button>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			echo '<p style="margin-top: 15px;">';
			echo '<a href="' . esc_url( add_query_arg( 'tab', 'add_virtual' ) ) . '" class="button">' . esc_html__( 'Add Virtual Site', 'wpmmcc-ats' ) . '</a>';
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Render conflicts tab (v0.9.1)
	 *
	 * Embeds the conflict management content from Conflict_Page.
	 */
	private static function render_conflicts_tab() {
		echo '<div class="wptsall-conflict-page">';
		Conflict_Page::render_content();
		echo '</div>';
	}

	/**
	 * Get all sites (v0.4.0 - one-to-one structure)
	 *
	 * @return array
	 */
	private static function get_all_sites() {
		$sites = array();

		// Use Site_Relation_Service to get all relations
		$relations = Site_Relation_Service::get_all_relations();

		foreach ( $relations as $rel ) {
			$type       = $rel['target_site_type'] ?? 'virtual';
			$type_label = ( 'wp' === $type || 'multisite' === $type ) ? __( 'Multisite', 'wpmmcc-ats' ) : __( 'Virtual Sites', 'wpmmcc-ats' );

			$sites[] = array(
				'id'                => $rel['id'],
				'name'              => self::format_relation_name( $rel ),
				'slug'              => $rel['template'] ?? 'site-' . $rel['id'],
				'type'              => $type,
				'type_label'        => $type_label,
				'source_site_id'    => $rel['source_site_id'],
				'source_lang'       => $rel['source_lang'] ?? '',
				'source_theme_name' => $rel['source_theme_name'] ?? '',
				'target_site_id'    => $rel['target_site_id'],
				'target_lang'       => $rel['target_lang'] ?? '',
				'target_theme_name' => $rel['target_theme_name'] ?? '',
				'template'          => $rel['template'] ?? '',
				'model_id'          => 0,
				'model_name'        => '',
				'status'            => $rel['status'] ?? 'active',
				'status_label'      => ( 'inactive' === ( $rel['status'] ?? 'active' ) ) ? __( 'Disabled', 'wpmmcc-ats' ) : __( 'Enabled', 'wpmmcc-ats' ),
				'relation_id'       => $rel['id'],
			);
		}

		return $sites;
	}

	/**
	 * Get grouped relations for display (v0.4.0)
	 *
	 * @param int $source_site_id Optional source site ID filter.
	 * @return array
	 */
	private static function get_grouped_sites( $source_site_id = 0 ) {
		return Site_Relation_Service::get_grouped_relations( $source_site_id );
	}

	/**
	 * Format relation name for display
	 *
	 * @param array $relation Relation data.
	 * @return string
	 */
	private static function format_relation_name( $relation ) {
		$template = $relation['template'] ?? '';
		$target_id = $relation['target_site_id'] ?? '';
		$target_lang = $relation['target_lang'] ?? '';

		if ( $template ) {
			$name = ucwords( str_replace( array( '-', '_' ), ' ', $template ) );
		} else {
			$name = __( 'Site Relations', 'wpmmcc-ats' );
		}

		if ( $target_lang ) {
			$name .= ' → ' . $target_lang;
		}

		return $name;
	}

	/**
	 * Get single site
	 *
	 * @param int $site_id Site ID.
	 * @return array|null
	 */
	private static function get_site( $site_id ) {
		$sites = self::get_all_sites();
		foreach ( $sites as $site ) {
			if ( (int) $site['id'] === (int) $site_id ) {
				return $site;
			}
		}
		return null;
	}

	/**
	 * Get available languages
	 *
	 * Uses centralized wptsall_get_available_languages() function.
	 *
	 * @since 0.8.1 Refactored to use centralized function.
	 * @return array
	 */
	private static function get_available_languages() {
		return wptsall_get_available_languages();
	}

	/**
	 * Render edit site modal
	 */
	private static function render_edit_modal() {
		$models    = Translation_Rule_Service::get_models();
		$languages = self::get_available_languages();
		?>
		<div id="wptsall-edit-site-modal" class="wptsall-modal" style="display:none;">
			<div class="wptsall-modal-content">
				<span class="wptsall-modal-close">&times;</span>
				<h2><?php esc_html_e( 'Edit Site', 'wpmmcc-ats' ); ?></h2>

				<form id="wptsall-edit-site-form">
					<input type="hidden" id="edit_site_id" name="site_id" value="">

					<table class="form-table">
						<tr>
							<th><label for="edit_site_name"><?php esc_html_e( 'Site Name', 'wpmmcc-ats' ); ?></label></th>
							<td><input type="text" name="name" id="edit_site_name" class="regular-text" required></td>
						</tr>

						<tr>
							<th><label for="edit_site_slug"><?php esc_html_e( 'Site Identifier', 'wpmmcc-ats' ); ?></label></th>
							<td>
								<input type="text" name="slug" id="edit_site_slug" class="regular-text" pattern="[a-z0-9-]+">
								<p class="description"><?php esc_html_e( 'Used in URL, can only contain lowercase letters, numbers and hyphens.', 'wpmmcc-ats' ); ?></p>
							</td>
						</tr>

						<tr>
							<th><label for="edit_target_lang"><?php esc_html_e( 'Target Language', 'wpmmcc-ats' ); ?></label></th>
							<td>
								<select name="target_lang" id="edit_target_lang" class="wptsall-select2" data-placeholder="<?php esc_attr_e( 'Select Target Language', 'wpmmcc-ats' ); ?>" required>
									<option value=""></option>
									<?php foreach ( $languages as $code => $name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<tr>
							<th><label for="edit_model_id"><?php esc_html_e( 'Bind Model', 'wpmmcc-ats' ); ?></label></th>
							<td>
								<select name="model_id" id="edit_model_id">
									<option value=""><?php esc_html_e( 'None', 'wpmmcc-ats' ); ?></option>
									<?php if ( ! empty( $models['models'] ) ) : ?>
										<?php foreach ( $models['models'] as $model ) : ?>
											<option value="<?php echo esc_attr( $model['id'] ); ?>"><?php echo esc_html( $model['plugin_name'] ); ?></option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
							</td>
						</tr>

						<tr>
							<th><label for="edit_site_status"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></label></th>
							<td>
								<select name="status" id="edit_site_status">
									<option value="active"><?php esc_html_e( 'Enabled', 'wpmmcc-ats' ); ?></option>
									<option value="inactive"><?php esc_html_e( 'Disabled', 'wpmmcc-ats' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wpmmcc-ats' ); ?></button>
						<button type="button" class="button wptsall-modal-close"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render virtual site modal (v0.6.0)
	 */
	private static function render_virtual_site_modal() {
		$languages = self::get_available_languages();
		?>
		<div id="wptsall-virtual-site-modal" class="wptsall-modal" style="display:none;">
			<div class="wptsall-modal-content">
				<span class="wptsall-modal-close">&times;</span>
				<h2 class="wptsall-modal-title"><?php esc_html_e( 'Virtual Sites', 'wpmmcc-ats' ); ?></h2>

				<div class="wptsall-modal-error" style="display:none;"></div>

				<form id="wptsall-virtual-site-form">
					<input type="hidden" id="virtual-site-id" name="site_id" value="">

					<div class="wptsall-modal-body">
						<table class="form-table">
							<tr>
								<th><label for="virtual-site-name"><?php esc_html_e( 'Site Name', 'wpmmcc-ats' ); ?> <span class="required">*</span></label></th>
								<td><input type="text" name="name" id="virtual-site-name" class="regular-text" required></td>
							</tr>

							<tr>
								<th><label for="virtual-site-subtitle"><?php esc_html_e( 'Site Subtitle', 'wpmmcc-ats' ); ?></label></th>
								<td><input type="text" name="subtitle" id="virtual-site-subtitle" class="regular-text"></td>
							</tr>

							<tr>
								<th><label for="virtual-site-path"><?php esc_html_e( 'Site Path', 'wpmmcc-ats' ); ?> <span class="required">*</span></label></th>
								<td>
									<div style="display: flex; align-items: center;">
										<span style="margin-right: 5px;"><?php echo esc_html( home_url( '/' ) ); ?></span>
										<input type="text" name="path" id="virtual-site-path" style="width: 100px;" required pattern="[a-zA-Z0-9_-]+">
										<span style="margin-left: 5px;">/</span>
									</div>
									<div id="virtual-site-path-conflict" style="display:none;"></div>
								</td>
							</tr>

							<tr>
								<th><label for="virtual-site-lang"><?php esc_html_e( 'Site Language', 'wpmmcc-ats' ); ?> <span class="required">*</span></label></th>
								<td>
									<select name="lang" id="virtual-site-lang" class="wptsall-select2" data-placeholder="<?php esc_attr_e( 'Select site language', 'wpmmcc-ats' ); ?>" required>
										<option value=""></option>
										<?php foreach ( $languages as $code => $name ) : ?>
											<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

							<tr>
								<th><label for="virtual-site-logo"><?php esc_html_e( 'Site Logo', 'wpmmcc-ats' ); ?></label></th>
								<td><input type="text" name="logo_url" id="virtual-site-logo" class="regular-text" placeholder="<?php esc_attr_e( 'Logo URL', 'wpmmcc-ats' ); ?>"></td>
							</tr>

							<tr>
								<th><label for="virtual-site-blog-sync"><?php esc_html_e( 'Enable blog sync', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<input type="checkbox" name="enable_blog_sync" id="virtual-site-blog-sync" value="1">
									<label for="virtual-site-blog-sync"><?php esc_html_e( 'Sync posts from another blog', 'wpmmcc-ats' ); ?></label>
								</td>
							</tr>

							<tr class="wptsall-blog-source-field" style="display:none;">
								<th><label for="virtual-site-blog-source"><?php esc_html_e( 'Source blog', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<select name="blog_source_site" id="virtual-site-blog-source">
										<option value=""><?php esc_html_e( 'Select blog', 'wpmmcc-ats' ); ?></option>
										<?php
										if ( is_multisite() ) {
											$sites = get_sites( array( 'number' => 100 ) );
											foreach ( $sites as $blog ) {
												$details = get_blog_details( $blog->blog_id );
												echo '<option value="' . esc_attr( $blog->blog_id ) . '">' . esc_html( $details->blogname ) . ' (' . esc_html( $blog->blog_id ) . ')</option>';
											}
										}
										?>
									</select>
								</td>
							</tr>

							<!-- v0.6.0: Permalink settings -->
							<tr>
								<th><label for="virtual-site-permalink"><?php esc_html_e( 'Permalink structure', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<select name="permalink_structure" id="virtual-site-permalink">
										<option value=""><?php esc_html_e( 'Inherit from source site', 'wpmmcc-ats' ); ?></option>
										<option value="plain"><?php esc_html_e( 'Plain', 'wpmmcc-ats' ); ?> (?p=123)</option>
										<option value="/%year%/%monthnum%/%day%/%postname%/"><?php esc_html_e( 'Day and name', 'wpmmcc-ats' ); ?></option>
										<option value="/%year%/%monthnum%/%postname%/"><?php esc_html_e( 'Month and name', 'wpmmcc-ats' ); ?></option>
										<option value="/archives/%post_id%"><?php esc_html_e( 'Numeric', 'wpmmcc-ats' ); ?></option>
										<option value="/%postname%/"><?php esc_html_e( 'Post name', 'wpmmcc-ats' ); ?></option>
										<option value="custom"><?php esc_html_e( 'Custom structure', 'wpmmcc-ats' ); ?></option>
									</select>
									<?php /* translators: %category% and %postname% are WordPress permalink structure tags, not printf placeholders */ ?>
									<input type="text" name="permalink_custom" id="virtual-site-permalink-custom" class="regular-text" style="display:none; margin-top: 10px;" placeholder="<?php esc_attr_e( 'e.g.: /%category%/%postname%/', 'wpmmcc-ats' ); ?>">
								</td>
							</tr>

							<tr>
								<th><label for="virtual-site-category-base"><?php esc_html_e( 'Category base', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<input type="text" name="category_base" id="virtual-site-category-base" class="regular-text" placeholder="category">
									<p class="description"><?php esc_html_e( 'Leave empty to inherit from the source site.', 'wpmmcc-ats' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><label for="virtual-site-tag-base"><?php esc_html_e( 'Tag base', 'wpmmcc-ats' ); ?></label></th>
								<td>
									<input type="text" name="tag_base" id="virtual-site-tag-base" class="regular-text" placeholder="tag">
									<p class="description"><?php esc_html_e( 'Leave empty to inherit from the source site.', 'wpmmcc-ats' ); ?></p>
								</td>
							</tr>
						</table>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wpmmcc-ats' ); ?></button>
						<button type="button" class="button wptsall-modal-close"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}

// Initialize
Sites_Page::init();
