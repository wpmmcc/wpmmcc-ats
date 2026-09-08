<?php
/**
 * Virtual site admin manager
 *
 * Dynamically manages virtual site content based on model data and translation rules
 * No hardcoded content types needed, processes dynamically based on database config
 *
 * @package WPTSALL\Hooks
 * @since 0.5.0
  * phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin list/render $_GET filters; state-changing handlers use check_admin_referer / check_ajax_referer.
 */
namespace WPTSALL\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
/**
 * Virtual site admin manager class
 *
 * Features:
 * - Dynamically discover all content types that need management (from wp_wptsall_models)
 * - Add site filter to admin list pages
 * - Display the site a content belongs to (column)
 * - Display/switch site on edit page
 * - Select site when creating new content
 */
class Admin_Virtual_Site_Manager {

	/**
	 * Content types that need management
	 *
	 * @var array|null
	 */
	private $managed_types = null;

	/**
	 * virtual sitelist
	 *
	 * @var array|null
	 */
	private $virtual_sites = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', array( $this, 'init_hooks' ) );
	}

	/**
	 * Initialize admin hooks
	 */
	public function init_hooks() {
		$this->load_managed_types();

		if ( empty( $this->managed_types['post_types'] ) && empty( $this->managed_types['taxonomies'] ) ) {
			wptsall_log_debug( 'hooks-admin', 'No managed types found, skipping admin hooks' );
			return;
		}

		wptsall_log_info(
			'hooks-admin',
			'Admin Virtual Site Manager initialized',
			array(
				'post_types_count' => count( $this->managed_types['post_types'] ),
				'taxonomies_count' => count( $this->managed_types['taxonomies'] ),
			)
		);

		// List page filter
		add_action( 'restrict_manage_posts', array( $this, 'add_site_filter' ), 10, 2 );
		add_action( 'restrict_manage_comments', array( $this, 'add_site_filter_taxonomy' ) );

		// queryfilter
		add_filter( 'pre_get_posts', array( $this, 'filter_posts_by_site' ) );
		add_filter( 'pre_get_terms', array( $this, 'filter_terms_by_site' ) );

		// Core content type columns
		add_filter( 'manage_posts_columns', array( $this, 'add_site_column' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( $this, 'add_site_column' ), 10, 2 );
		add_action( 'manage_posts_custom_column', array( $this, 'render_site_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'render_site_column' ), 10, 2 );

		// Dynamically register columns for post types
		foreach ( $this->managed_types['post_types'] as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_site_column' ), 10, 2 );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_site_column' ), 10, 2 );
		}

		// Dynamically register columns for taxonomies
		foreach ( $this->managed_types['taxonomies'] as $taxonomy ) {
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_site_column_taxonomy' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_site_column_taxonomy' ), 10, 3 );
		}

		// Meta boxes（Editpage）
		add_action( 'add_meta_boxes', array( $this, 'add_site_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_site_meta' ), 10, 2 );

		// Sync trigger hooks (migrated from Admin_Hooks, v0.9.0)
		add_action( 'save_post', array( $this, 'on_save_post_sync' ), 20, 3 );

		// Edit form top virtual site indicator (migrated from Admin_Hooks, v0.9.0)
		add_action( 'edit_form_top', array( $this, 'show_virtual_site_indicator' ) );

		// Taxonomy EditField
		foreach ( $this->managed_types['taxonomies'] as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'add_taxonomy_site_field' ), 10, 2 );
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'add_taxonomy_site_field_new' ) );
			add_action( "edited_{$taxonomy}", array( $this, 'save_taxonomy_site_meta' ), 10, 2 );
			add_action( "created_{$taxonomy}", array( $this, 'save_taxonomy_site_meta' ), 10, 2 );
		}

		// Admin UI enhancements
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// bulkActions
		foreach ( $this->managed_types['post_types'] as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'register_bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_actions' ), 10, 3 );
		}
		add_action( 'admin_footer-edit.php', array( $this, 'render_bulk_action_form' ) );

		// Translation row action link (v1.1.0)
		add_filter( 'post_row_actions', array( $this, 'add_translation_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_translation_row_actions' ), 10, 2 );

		// Admin View / Preview links for shadow posts → virtual-site URLs.
		add_filter( 'post_link', array( $this, 'rewrite_admin_virtual_permalink' ), 20, 2 );
		add_filter( 'page_link', array( $this, 'rewrite_admin_virtual_permalink' ), 20, 2 );
		add_filter( 'post_type_link', array( $this, 'rewrite_admin_virtual_permalink' ), 20, 2 );
		add_filter( 'preview_post_link', array( $this, 'rewrite_admin_preview_link' ), 20, 2 );
		add_filter( 'get_sample_permalink_html', array( $this, 'filter_sample_permalink_html' ), 20, 5 );
	}

	/**
	 * Load content types that need management from database
	 */
	private function load_managed_types() {
		if ( null !== $this->managed_types ) {
			return;
		}

		global $wpdb;

		$this->managed_types = array(
			'post_types' => array(),
			'taxonomies' => array(),
		);

		// Query only models associated with site_relations for the current site.
		// This ensures the admin only manages types that have active translation relations.
		$models_table    = wptsall_table( 'models' );
		$relations_table = wptsall_table( 'site_relations' );
		$rel_models_tbl  = wptsall_table( 'relation_models' );
		if ( ! $models_table ) {
			return;
		}

		$current_blog_id = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$models = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT m.id, m.plugin_slug, m.post_types, m.taxonomies
				FROM %i m
				INNER JOIN %i rm ON rm.model_id = m.id
				INNER JOIN %i sr ON sr.id = rm.relation_id
				WHERE m.status = %s AND sr.source_site_id = %d",
				$models_table,
				$rel_models_tbl,
				$relations_table,
				'active',
				$current_blog_id
			),
			ARRAY_A
		);

		if ( empty( $models ) ) {
			return;
		}

		// Parse content types for each model
		foreach ( $models as $model ) {
			// Post Types — handle both string format ["post","page"]
			// and object format [{"name":"post","label":"Posts"}].
			$post_types = json_decode( $model['post_types'], true );
			if ( is_array( $post_types ) ) {
				foreach ( $post_types as $pt ) {
					$pt_name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
					if ( ! empty( $pt_name ) && is_string( $pt_name ) && post_type_exists( $pt_name ) ) {
						$this->managed_types['post_types'][] = $pt_name;
					}
				}
			}

			// Taxonomies — handle both string format ["category","post_tag"]
			// and object format [{"name":"category","label":"Categories"}].
			$taxonomies = json_decode( $model['taxonomies'], true );
			if ( is_array( $taxonomies ) ) {
				foreach ( $taxonomies as $tax ) {
					$tax_name = is_array( $tax ) ? ( $tax['name'] ?? '' ) : $tax;
					if ( ! empty( $tax_name ) && is_string( $tax_name ) && taxonomy_exists( $tax_name ) ) {
						$this->managed_types['taxonomies'][] = $tax_name;
					}
				}
			}
		}

		// Deduplicate
		$this->managed_types['post_types'] = array_unique( $this->managed_types['post_types'] );
		$this->managed_types['taxonomies'] = array_unique( $this->managed_types['taxonomies'] );

		// Always expose core CMS types so list/edit translation UI stays visible
		// even when relation models omit post/category (Polylang-style baseline).
		foreach ( array( 'post', 'page' ) as $core_pt ) {
			if ( post_type_exists( $core_pt ) && ! in_array( $core_pt, $this->managed_types['post_types'], true ) ) {
				$this->managed_types['post_types'][] = $core_pt;
			}
		}
		foreach ( array( 'category', 'post_tag' ) as $core_tax ) {
			if ( taxonomy_exists( $core_tax ) && ! in_array( $core_tax, $this->managed_types['taxonomies'], true ) ) {
				$this->managed_types['taxonomies'][] = $core_tax;
			}
		}

		wptsall_log_debug(
			'hooks-admin',
			'Loaded managed types from models',
			array(
				'post_types' => $this->managed_types['post_types'],
				'taxonomies' => $this->managed_types['taxonomies'],
			)
		);
	}

	/**
	 * Get virtual site list
	 *
	 * @return array
	 */
	private function get_virtual_sites() {
		if ( null !== $this->virtual_sites ) {
			return $this->virtual_sites;
		}

		if ( ! class_exists( '\WPTSALL\Sites\Services\Virtual_Site_Service' ) ) {
			$this->virtual_sites = array();
			return $this->virtual_sites;
		}

		$this->virtual_sites = \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) );

		return $this->virtual_sites;
	}

	/**
	 * Add site filter (Post Types)
	 *
	 * @param string $post_type Post type.
	 * @param string $which     Top or bottom of the table.
	 */
	public function add_site_filter( $post_type, $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		if ( ! in_array( $post_type, $this->managed_types['post_types'], true ) ) {
			return;
		}

		$virtual_sites  = $this->get_virtual_sites();
		$selected_site  = isset( $_GET['wptsall_site'] ) ? intval( $_GET['wptsall_site'] ) : 0;
		$nonce          = wp_create_nonce( 'wptsall_site_filter' );

		echo '<select name="wptsall_site" id="wptsall-site-filter">';
		echo '<option value="0">' . esc_html__( 'All Sites', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="-1" ' . selected( $selected_site, -1, false ) . '>' . esc_html__( 'Main Site', 'wpmmcc-ats' ) . '</option>';

		foreach ( $virtual_sites as $site ) {
			echo '<option value="' . esc_attr( $site['id'] ) . '" ' . selected( $selected_site, $site['id'], false ) . '>';
			echo esc_html( $site['name'] ) . ' (' . esc_html( $site['path_prefix'] ) . ')';
			echo '</option>';
		}

		echo '</select>';
		echo '<input type="hidden" name="wptsall_site_nonce" value="' . esc_attr( $nonce ) . '">';
	}

	/**
	 * Add site filter (Taxonomies)
	 */
	public function add_site_filter_taxonomy() {
		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->taxonomy ) ) {
			return;
		}

		if ( ! in_array( $screen->taxonomy, $this->managed_types['taxonomies'], true ) ) {
			return;
		}

		$virtual_sites = $this->get_virtual_sites();
		$selected_site = isset( $_GET['wptsall_site'] ) ? intval( $_GET['wptsall_site'] ) : 0;
		$nonce         = wp_create_nonce( 'wptsall_site_filter' );

		echo '<div class="alignleft actions">';
		echo '<select name="wptsall_site" id="wptsall-site-filter">';
		echo '<option value="0">' . esc_html__( 'All Sites', 'wpmmcc-ats' ) . '</option>';
		echo '<option value="-1" ' . selected( $selected_site, -1, false ) . '>' . esc_html__( 'Main Site', 'wpmmcc-ats' ) . '</option>';

		foreach ( $virtual_sites as $site ) {
			echo '<option value="' . esc_attr( $site['id'] ) . '" ' . selected( $selected_site, $site['id'], false ) . '>';
			echo esc_html( $site['name'] ) . ' (' . esc_html( $site['path_prefix'] ) . ')';
			echo '</option>';
		}

		echo '</select>';
		echo '<input type="hidden" name="wptsall_site_nonce" value="' . esc_attr( $nonce ) . '">';
		echo '<input type="submit" class="button" value="' . esc_attr__( 'Filter', 'wpmmcc-ats' ) . '">';
		echo '</div>';
	}

	/**
	 * Filter posts query by site
	 *
	 * @param \WP_Query $query WordPress query object.
	 */
	public function filter_posts_by_site( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( ! $post_type && isset( $_GET['post_type'] ) ) {
			$post_type = sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
		}

		if ( ! in_array( $post_type, $this->managed_types['post_types'], true ) ) {
			return;
		}

		if ( ! isset( $_GET['wptsall_site'] ) || ! isset( $_GET['wptsall_site_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wptsall_site_nonce'] ) ), 'wptsall_site_filter' ) ) {
			return;
		}

		$site_id = intval( $_GET['wptsall_site'] );

		if ( 0 === $site_id ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' ) ?: array();

		if ( -1 === $site_id ) {
			// Main site: No virtual site marker
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_wptsall_virtual_site_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wptsall_virtual_site_id',
					'value' => '',
				),
			);
		} else {
			// Virtual Site — meta may store an integer ID or a string like "v_2".
			// Cast to string for reliable comparison (WordPress default type is CHAR).
			$meta_query[] = array(
				'key'   => '_wptsall_virtual_site_id',
				'value' => (string) $site_id,
			);
		}

		$query->set( 'meta_query', $meta_query );

		wptsall_log_debug(
			'hooks-admin',
			'Applied site filter to posts query',
			array(
				'post_type' => $post_type,
				'site_id'   => $site_id,
			)
		);
	}

	/**
	 * Filter terms query by site
	 *
	 * @param array $args Query arguments.
	 * @return array Modified arguments.
	 */
	public function filter_terms_by_site( $args ) {
		if ( ! is_admin() ) {
			return $args;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->taxonomy ) ) {
			return $args;
		}

		if ( ! in_array( $screen->taxonomy, $this->managed_types['taxonomies'], true ) ) {
			return $args;
		}

		if ( ! isset( $_GET['wptsall_site'] ) || ! isset( $_GET['wptsall_site_nonce'] ) ) {
			return $args;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wptsall_site_nonce'] ) ), 'wptsall_site_filter' ) ) {
			return $args;
		}

		$site_id = intval( $_GET['wptsall_site'] );

		if ( 0 === $site_id ) {
			return $args;
		}

		$meta_query = $args['meta_query'] ?? array();

		if ( -1 === $site_id ) {
			// Main site
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_wptsall_virtual_site_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wptsall_virtual_site_id',
					'value' => '',
				),
			);
		} else {
			// Virtual Site — meta may store an integer ID or a string like "v_2".
			// Cast to string for reliable comparison (WordPress default type is CHAR).
			$meta_query[] = array(
				'key'   => '_wptsall_virtual_site_id',
				'value' => (string) $site_id,
			);
		}

		$args['meta_query'] = $meta_query;

		wptsall_log_debug(
			'hooks-admin',
			'Applied site filter to terms query',
			array(
				'taxonomy' => $screen->taxonomy,
				'site_id'  => $site_id,
			)
		);

		return $args;
	}

	/**
	 * Add site column (Post Types)
	 *
	 * @param array  $columns Columns.
	 * @param string $post_type Post type.
	 * @return array Modified columns.
	 */
	public function add_site_column( $columns, $post_type = '' ) {
		if ( ! empty( $post_type ) && ! in_array( $post_type, $this->managed_types['post_types'], true ) ) {
			return $columns;
		}

		$new_columns = array();
		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;
			if ( 'title' === $key ) {
				$new_columns['wptsall_site']        = __( 'Site', 'wpmmcc-ats' );
				$new_columns['wptsall_translation'] = __( 'Translation Status', 'wpmmcc-ats' );
			}
		}

		return $new_columns;
	}

	/**
	 * Add site column (Taxonomies)
	 *
	 * @param array $columns Columns.
	 * @return array Modified columns.
	 */
	public function add_site_column_taxonomy( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;
			if ( 'name' === $key ) {
				$new_columns['wptsall_site']        = __( 'Site', 'wpmmcc-ats' );
				$new_columns['wptsall_translation'] = __( 'Translation Status', 'wpmmcc-ats' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render site column content (Post Types)
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_site_column( $column, $post_id ) {
		if ( 'wptsall_translation' === $column ) {
			$virtual_site_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
			// Only show translation links for source site posts (not translated copies)
			if ( ! empty( $virtual_site_id ) ) {
				echo '<span class="dashicons dashicons-translation" style="color:#999;" title="' . esc_attr__( 'Translated content', 'wpmmcc-ats' ) . '"></span>';
				return;
			}
			$post     = get_post( $post_id );
			$post_type = $post ? (string) $post->post_type : '';
			$statuses = \WPTSALL\Sites\Services\Manual_Content_Service::get_translation_status( $post_id, $post_type );
			$statuses = self::filter_translation_statuses_for_post_type( $statuses, $post_type );
			if ( empty( $statuses ) ) {
				echo '—';
				return;
			}
			$dots = array();
			foreach ( $statuses as $s ) {
				if ( 'published' === $s['status'] ) {
					$color = '#46b450'; // green
				} elseif ( 'draft' === $s['status'] ) {
					$color = '#ffb900'; // yellow
				} else {
					$color = '#dc3232'; // red
				}
				$url    = admin_url( 'admin.php?page=wptsall-translate&source_post_id=' . $post_id . '&relation_id=' . intval( $s['relation_id'] ) );
				$dots[] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $s['target_site_name'] . ': ' . $s['status'] ) . '" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $color . ';margin-right:3px;"></a>';
			}
			echo wp_kses_post( implode( '', $dots ) );
			return;
		}

		if ( 'wptsall_site' !== $column ) {
			return;
		}

		$site_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );

		if ( empty( $site_id ) ) {
			echo '<span class="wptsall-site-badge wptsall-site-main">' . esc_html__( 'Main', 'wpmmcc-ats' ) . '</span>';
			return;
		}

		$virtual_sites = $this->get_virtual_sites();
		foreach ( $virtual_sites as $site ) {
			if ( intval( $site['id'] ) === intval( $site_id ) ) {
				echo '<span class="wptsall-site-badge wptsall-site-virtual">';
				echo esc_html( $site['name'] );
				echo '</span>';
				return;
			}
		}

		echo '<span class="wptsall-site-badge wptsall-site-unknown">' . esc_html__( 'Unknown', 'wpmmcc-ats' ) . '</span>';
	}

	/**
	 * Render site column content (Taxonomies)
	 *
	 * @param string $content Column content.
	 * @param string $column  Column name.
	 * @param int    $term_id Term ID.
	 * @return string Modified content.
	 */
	public function render_site_column_taxonomy( $content, $column, $term_id ) {
		if ( 'wptsall_translation' === $column ) {
			$site_id = get_term_meta( $term_id, '_wptsall_virtual_site_id', true );
			if ( ! empty( $site_id ) ) {
				return '<span class="dashicons dashicons-translation" style="color:#999;" title="' . esc_attr__( 'Translated term', 'wpmmcc-ats' ) . '"></span>';
			}
			$map = $this->collect_term_translations( (int) $term_id );
			unset( $map['_current_lang'] );
			if ( empty( $map ) ) {
				return '—';
			}
			$dots = array();
			foreach ( $map as $lang => $tid ) {
				$tid = (int) $tid;
				if ( $tid > 0 ) {
					$color = '#46b450';
					$url   = get_edit_term_link( $tid );
				} else {
					$color = '#dc3232';
					$url   = '';
				}
				$inner = '<span title="' . esc_attr( (string) $lang . ( $tid > 0 ? ' → #' . $tid : ' missing' ) ) . '" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $color . ';margin-right:3px;"></span>';
				if ( $url ) {
					$dots[] = '<a href="' . esc_url( $url ) . '">' . $inner . '</a>';
				} else {
					$dots[] = $inner;
				}
			}
			return wp_kses_post( implode( '', $dots ) );
		}

		if ( 'wptsall_site' !== $column ) {
			return $content;
		}

		$site_id = get_term_meta( $term_id, '_wptsall_virtual_site_id', true );

		if ( empty( $site_id ) ) {
			return '<span class="wptsall-site-badge wptsall-site-main">' . esc_html__( 'Main', 'wpmmcc-ats' ) . '</span>';
		}

		$virtual_sites = $this->get_virtual_sites();
		foreach ( $virtual_sites as $site ) {
			if ( (string) ( $site['id'] ?? '' ) === (string) $site_id || intval( $site['id'] ?? 0 ) === intval( $site_id ) ) {
				return '<span class="wptsall-site-badge wptsall-site-virtual">' . esc_html( $site['name'] ) . '</span>';
			}
		}

		return '<span class="wptsall-site-badge wptsall-site-unknown">' . esc_html__( 'Unknown', 'wpmmcc-ats' ) . '</span>';
	}

	/**
	 * Add site meta box (edit page)
	 */
	public function add_site_meta_box() {
		foreach ( $this->managed_types['post_types'] as $post_type ) {
			add_meta_box(
				'wptsall_site_meta_box',
				__( 'WPTSALL Translation', 'wpmmcc-ats' ),
				array( $this, 'render_site_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the WPTSALL Translation Meta Box.
	 *
	 * Shows virtual site selector, translation actions, and translatable field summary.
	 * Field list is driven by field_capabilities from Translation_Rule_Service (F5).
	 * Handles multiple relations (both virtual and wp) with a relation selector (F6).
	 *
	 * @since 0.5.0
	 * @since 1.5.0 Refactored: fields from field_capabilities, multi-relation selector.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_site_meta_box( $post ) {
		$site_id       = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post->ID, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		$virtual_sites = $this->get_virtual_sites();

		wp_nonce_field( 'wptsall_site_meta_nonce', 'wptsall_site_meta_nonce' );

		// --- Polylang-style language flag block (P5-2) ---
		// Shows a row of language flags: current post's language highlighted,
		// each existing translation as a clickable flag with edit link, and
		// missing translations as a "+" link to the translate page.
		$languages    = \WPTSALL\Languages\Services\Language_Service::get_all( array( 'status' => 'all' ) );
		$default_lang = \WPTSALL\Languages\Services\Language_Service::get_default();
		$default_code = $default_lang ? (string) $default_lang['code'] : 'zh_CN';
		$translations = $this->collect_post_translations( $post->ID );
		$current_lang = isset( $translations['_current_lang'] ) ? (string) $translations['_current_lang'] : $default_code;
		unset( $translations['_current_lang'] );
		echo '<div class="wptsall-metabox-langs" style="margin:6px 0 8px 0;">';
		echo '<p style="margin:0 0 4px 0;"><strong>' . esc_html__( 'Languages:', 'wpmmcc-ats' ) . '</strong></p>';
		echo '<div class="wptsall-translation-flags">';
		foreach ( $languages as $lang ) {
			$code = (string) $lang['code'];
			$is_current = ( $code === $current_lang );
			$tid = isset( $translations[ $code ] ) ? (int) $translations[ $code ] : 0;
			if ( $is_current ) {
				echo '<span class="wptsall-flag wptsall-flag-current" title="' . esc_attr( $code . ' (current)' ) . '">' . esc_html( $code ) . '</span> ';
			} elseif ( $tid > 0 ) {
				$edit_url = get_edit_post_link( $tid );
				echo '<a href="' . esc_url( $edit_url ) . '" class="wptsall-flag wptsall-flag-yes" title="' . esc_attr( $code . ' → #' . $tid ) . '">' . esc_html( $code ) . '</a> ';
			} else {
				$add_url = '';
				if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Resolver' ) ) {
					$rid = \WPTSALL\Sites\Services\Relation_Resolver::for_post_lang( (int) $post->ID, $code );
					if ( $rid > 0 ) {
						$add_url = add_query_arg(
							array(
								'page'           => 'wptsall-translate',
								'source_post_id' => (int) $post->ID,
								'relation_id'    => $rid,
							),
							admin_url( 'admin.php' )
						);
					}
				}
				if ( '' === $add_url ) {
					$add_url = add_query_arg(
						array( 'page' => 'wptsall-translate', 'post_id' => (int) $post->ID, 'to_lang' => $code ),
						admin_url( 'admin.php' )
					);
				}
				/* translators: %s: language code */
				echo '<a href="' . esc_url( $add_url ) . '" class="wptsall-flag wptsall-flag-missing" title="' . esc_attr( sprintf( __( 'Add %s translation', 'wpmmcc-ats' ), $code ) ) . '">+' . esc_html( $code ) . '</a> ';
			}
		}
		echo '</div></div>';

		// --- Translation status block (Phase C2) ---
		$this->render_translation_status_block( $post, $languages, $current_lang, $translations, $default_code );

		// --- Virtual Site Selector ---
		echo '<p><label for="wptsall_virtual_site_id"><strong>' . esc_html__( 'Select Site:', 'wpmmcc-ats' ) . '</strong></label></p>';
		echo '<select name="wptsall_virtual_site_id" id="wptsall_virtual_site_id" class="widefat">';
		echo '<option value="0" ' . selected( empty( $site_id ), true, false ) . '>' . esc_html__( 'Main Site', 'wpmmcc-ats' ) . '</option>';

		foreach ( $virtual_sites as $site ) {
			echo '<option value="' . esc_attr( $site['id'] ) . '" ' . selected( $site_id, $site['id'], false ) . '>';
			echo esc_html( $site['name'] ) . ' (' . esc_html( $site['path_prefix'] ) . ')';
			echo '</option>';
		}

		echo '</select>';

		if ( ! empty( $site_id ) ) {
			echo '<p class="description">' . esc_html__( 'This content belongs to a virtual site.', 'wpmmcc-ats' ) . '</p>';
		}

		// --- Sync checkbox ---
		echo '<div style="margin-top:12px;">';
		echo '<label>';
		echo '<input type="checkbox" name="wptsall_sync_on_save" value="1"> ';
		echo esc_html__( 'Sync to virtual sites on save', 'wpmmcc-ats' );
		echo '</label>';
		echo '</div>';

		// --- Skip translation UI for translated copies (virtual site posts) ---
		if ( ! empty( $site_id ) ) {
			return;
		}

		// --- Translation section: multi-relation support (F5 + F6) ---
		// Process ALL relations (both virtual and wp), not just virtual ones.
		$relations = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations(
			array(
				'source_site_id' => get_current_blog_id(),
				'status'         => 'active',
			)
		);

		if ( empty( $relations ) ) {
			return;
		}

		echo '<div class="wptsall-translate-section" style="margin-top:12px;border-top:1px solid #ddd;padding-top:8px;">';
		echo '<strong>' . esc_html__( 'Translation:', 'wpmmcc-ats' ) . '</strong>';

		// --- Relation selector when multiple relations exist (F6) ---
		if ( count( $relations ) > 1 ) {
			echo '<div class="wptsall-relation-selector" style="margin:6px 0;">';
			echo '<select id="wptsall-relation-select" class="widefat" onchange="wptsallSwitchRelation(this.value)">';
			foreach ( $relations as $r ) {
				$r_id    = (int) $r['id'];
				$r_label = esc_html( $r['target_lang'] ?? $r['target_language'] ?? '' );
				$r_type  = esc_html( $r['target_site_type'] ?? 'wp' );
				echo '<option value="' . esc_attr( $r_id ) . '">';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
				echo $r_label . ' (' . $r_type . ')';
				echo '</option>';
			}
			echo '</select>';
			echo '</div>';
		}

		// --- Per-relation translation details ---
		foreach ( $relations as $idx => $relation ) {
			$relation_id      = (int) $relation['id'];
			$target_lang      = $relation['target_lang'] ?? $relation['target_language'] ?? '';
			$target_site_type = $relation['target_site_type'] ?? 'wp';
			$display_style    = ( 0 === $idx ) ? '' : 'display:none;';

			echo '<div class="wptsall-relation-panel" id="wptsall-relation-panel-' . esc_attr( $relation_id ) . '" style="' . esc_attr( $display_style ) . 'margin-top:6px;">';

			// Single relation: show target info inline.
			if ( count( $relations ) === 1 ) {
				echo '<p class="description">' . esc_html( $target_lang ) . ' (' . esc_html( $target_site_type ) . ')</p>';
			}

			// Get field_capabilities for this relation + post_type (F5).
			// Fields are read dynamically on each render (no caching needed).
			$has_explicit_rule = self::has_translation_rule_for_relation( $relation_id, $post->post_type );
			$field_config      = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
				$relation_id,
				$post->post_type
			);

			$translate_fields = $field_config['translate_fields'] ?? array();

			if ( ! $has_explicit_rule ) {
				// No explicit translation rule exists for this content type (F5).
				echo '<p class="description" style="color:#d63638;">';
				echo esc_html__( 'No translation rules found for this content type.', 'wpmmcc-ats' );
				echo '</p>';
			} elseif ( ! empty( $translate_fields ) ) {
				// Show translate field names as a summary.
				echo '<p class="description" style="margin-top:4px;">';
				echo esc_html__( 'Translatable fields: ', 'wpmmcc-ats' );
				echo '<code>' . esc_html( implode( ', ', $translate_fields ) ) . '</code>';
				echo '</p>';
			}

			// Translation status and action links.
			$target_post_id = \WPTSALL\Sites\Services\Manual_Content_Service::find_existing_translation( $post->ID, $relation_id );
			$url            = admin_url( 'admin.php?page=wptsall-translate&source_post_id=' . $post->ID . '&relation_id=' . $relation_id );
			$site_name      = esc_html( $target_lang );

			if ( $target_post_id ) {
				$target_post  = $this->get_target_post_for_display( $target_post_id, $relation );
				$status       = $target_post ? $target_post->post_status : 'unknown';
				$status_label = ( 'publish' === $status ) ? '' : ' (' . esc_html( $status ) . ')';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $status_label is escaped above
				echo '<p><a href="' . esc_url( $url ) . '" class="button button-small">' . esc_html__( 'Edit Translation', 'wpmmcc-ats' ) . '</a>' . $status_label . '</p>';
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $site_name is escaped above
				echo '<p><a href="' . esc_url( $url ) . '" class="button button-small">' . esc_html__( 'Translate to ', 'wpmmcc-ats' ) . $site_name . '</a></p>';
			}

			echo '</div>'; // .wptsall-relation-panel
		}

		// JavaScript for relation switching (F6).
		if ( count( $relations ) > 1 ) {
			$script_rel  = 'assets/js/relation-switch.js';
			$script_path = WPTSALL_PATH . $script_rel;
			wp_enqueue_script(
				'wptsall-relation-switch',
				WPTSALL_URL . $script_rel,
				array(),
				file_exists( $script_path ) ? (string) filemtime( $script_path ) : WPTSALL_VERSION,
				true
			);
		}

		echo '</div>'; // .wptsall-translate-section
	}

	/**
	 * Render editor translation status table (lang / status / View / Edit).
	 *
	 * @since 2.2.0
	 *
	 * @param \WP_Post             $post          Post.
	 * @param array<int,array>     $languages     Language rows.
	 * @param string               $current_lang  Current language code.
	 * @param array<string,int>    $translations  lang => target_post_id.
	 * @param string               $default_code  Default language code.
	 * @return void
	 */
	private function render_translation_status_block( $post, array $languages, string $current_lang, array $translations, string $default_code ): void {
		unset( $default_code );
		$vs_by_lang = array();
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			foreach ( (array) \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) ) as $site ) {
				$code = (string) ( $site['lang'] ?? '' );
				if ( '' !== $code ) {
					$vs_by_lang[ $code ] = $site;
				}
			}
		}

		echo '<div class="wptsall-translation-status" style="margin:8px 0 10px 0;">';
		echo '<p style="margin:0 0 4px 0;"><strong>' . esc_html__( 'Translation status:', 'wpmmcc-ats' ) . '</strong></p>';
		echo '<table class="widefat striped" style="margin:0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Language', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wpmmcc-ats' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $languages as $lang ) {
			$code       = (string) ( $lang['code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$is_current = ( $code === $current_lang );
			$tid        = isset( $translations[ $code ] ) ? (int) $translations[ $code ] : 0;
			if ( $is_current ) {
				$tid = (int) $post->ID;
			}

			$status = __( 'Missing', 'wpmmcc-ats' );
			if ( $is_current ) {
				$status = __( 'Current', 'wpmmcc-ats' );
			} elseif ( $tid > 0 ) {
				$tp = get_post( $tid );
				$status = $tp ? (string) $tp->post_status : __( 'Mapped', 'wpmmcc-ats' );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $code ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>';

			$actions = array();
			if ( $tid > 0 ) {
				$edit = get_edit_post_link( $tid );
				if ( $edit ) {
					$actions[] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'wpmmcc-ats' ) . '</a>';
				}
				$view = get_permalink( $tid );
				if ( $view && isset( $vs_by_lang[ $code ] ) && class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
					$view = \WPTSALL\Sites\Services\Url_Converter::virtualize( $view, $vs_by_lang[ $code ] );
				}
				if ( $view ) {
					$actions[] = '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'wpmmcc-ats' ) . '</a>';
				}
			} else {
				$add_url = '';
				if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Resolver' ) ) {
					$rid = \WPTSALL\Sites\Services\Relation_Resolver::for_post_lang( (int) $post->ID, $code );
					if ( $rid > 0 ) {
						$add_url = add_query_arg(
							array(
								'page'           => 'wptsall-translate',
								'source_post_id' => (int) $post->ID,
								'relation_id'    => $rid,
							),
							admin_url( 'admin.php' )
						);
					}
				}
				if ( '' === $add_url ) {
					$add_url = add_query_arg(
						array(
							'page'    => 'wptsall-translate',
							'post_id' => (int) $post->ID,
							'to_lang' => $code,
						),
						admin_url( 'admin.php' )
					);
				}
				$actions[] = '<a href="' . esc_url( $add_url ) . '">' . esc_html__( 'Translate', 'wpmmcc-ats' ) . '</a>';
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped above.
			echo implode( ' · ', $actions );
			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Get a target post object for display purposes, handling blog switching.
	 *
	 * @since 1.5.0
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $relation       Relation data.
	 * @return \WP_Post|null Post object or null.
	 */
	private function get_target_post_for_display( int $target_post_id, array $relation ): ?\WP_Post {
		$target_site_type = $relation['target_site_type'] ?? 'wp';

		if ( 'virtual' === $target_site_type ) {
			return get_post( $target_post_id );
		}

		$post = null;
		if ( is_multisite() ) {
			switch_to_blog( (int) $relation['target_site_id'] );
			$post = get_post( $target_post_id );
			restore_current_blog();
		} else {
			$post = get_post( $target_post_id );
		}

		return $post;
	}

	/**
	 * Collect all translation target_post_ids for a post, keyed by language code.
	 *
	 * The pseudo-key '_current_lang' carries the post's own language (best-effort:
	 * if no mapping references this post as a target, it is treated as the
	 * default-language source).
	 *
	 * @since 1.4.0
	 *
	 * @param int $post_id Post id.
	 * @return array<string,int> Map of lang_code => target_post_id (+ '_current_lang').
	 */
	private function collect_post_translations( int $post_id ): array {
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			return \WPTSALL\Sites\Services\Translation_Identity::get_translation_ids_by_lang( $post_id );
		}

		global $wpdb;
		$out       = array();
		$table     = wptsall_table( 'post_mappings' );
		$relations = wptsall_table( 'site_relations' );
		// target_lang is stored on site_relations, joined via relation_id.
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT r.target_lang, m.target_post_id, m.source_post_id
			 FROM %i m
			 LEFT JOIN %i r ON r.id = m.relation_id
			 WHERE m.source_post_id = %d OR m.target_post_id = %d',
			$table,
			$relations,
			$post_id,
			$post_id
		), ARRAY_A );
		$is_target_for = array();
		foreach ( (array) $rows as $r ) {
			$code = (string) ( $r['target_lang'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			if ( (int) $r['source_post_id'] === $post_id ) {
				$out[ $code ] = (int) $r['target_post_id'];
			}
			if ( (int) $r['target_post_id'] === $post_id ) {
				$is_target_for[ $code ] = true;
				$out['_current_lang'] = $code;
			}
		}
		if ( ! isset( $out['_current_lang'] ) ) {
			$default = \WPTSALL\Languages\Services\Language_Service::get_default();
			$out['_current_lang'] = $default ? (string) $default['code'] : 'zh_CN';
		}
		return $out;
	}

	/**
	 * Check if an explicit translation rule exists for a relation and post type.
	 *
	 * Returns true if a translation rule has been configured (not using defaults).
	 * Used in the Meta Box to display a notice when no rule is found (F5).
	 *
	 * @since 1.5.0
	 *
	 * @param int    $relation_id Site relation ID.
	 * @param string $post_type   Post type name.
	 * @return bool True if an explicit translation rule exists.
	 */
	private static function has_translation_rule_for_relation( int $relation_id, string $post_type ): bool {
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) ) {
			return false;
		}

		$models = \WPTSALL\Sites\Services\Relation_Model_Service::get_models_by_relation( $relation_id );

		if ( empty( $models ) ) {
			return false;
		}

		foreach ( $models as $model ) {
			$rule = \WPTSALL\Models\Services\Translation_Rule_Service::get_rule_by_post_type(
				(int) $model['id'],
				$post_type
			);

			if ( $rule ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Save site meta.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_site_meta( $post_id, $post ) {
		if ( ! isset( $_POST['wptsall_site_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wptsall_site_meta_nonce'] ) ), 'wptsall_site_meta_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->managed_types['post_types'], true ) ) {
			return;
		}

		$site_id     = isset( $_POST['wptsall_virtual_site_id'] ) ? intval( $_POST['wptsall_virtual_site_id'] ) : 0;
		$old_site_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );

		if ( $site_id > 0 ) {
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, $site_id );
		} else {
			\WPTSALL\Sites\Services\Translation_Identity::delete_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		}

		wptsall_log_info(
			'hooks-admin',
			'Post site assignment changed',
			array(
				'post_id'     => $post_id,
				'post_type'   => $post->post_type,
				'old_site_id' => $old_site_id ?: 'main',
				'new_site_id' => $site_id > 0 ? $site_id : 'main',
			)
		);
	}

	/**
	 * Add taxonomy site field (edit page)
	 *
	 * @param \WP_Term $term     Term object.
	 * @param string   $taxonomy Taxonomy slug.
	 */
	public function add_taxonomy_site_field( $term, $taxonomy ) {
		$site_id       = get_term_meta( $term->term_id, '_wptsall_virtual_site_id', true );
		$virtual_sites = $this->get_virtual_sites();

		wp_nonce_field( 'wptsall_taxonomy_site_nonce', 'wptsall_taxonomy_site_nonce' );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="wptsall_virtual_site_id"><?php esc_html_e( 'Virtual Site', 'wpmmcc-ats' ); ?></label>
			</th>
			<td>
				<select name="wptsall_virtual_site_id" id="wptsall_virtual_site_id" class="postform">
					<option value="0" <?php selected( empty( $site_id ), true ); ?>><?php esc_html_e( 'Main Site', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( $virtual_sites as $site ) : ?>
						<option value="<?php echo esc_attr( $site['id'] ); ?>" <?php selected( $site_id, $site['id'] ); ?>>
							<?php echo esc_html( $site['name'] ); ?> (<?php echo esc_html( $site['path_prefix'] ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Select which site this term belongs to.', 'wpmmcc-ats' ); ?></p>
			</td>
		</tr>
		<?php
		$this->render_term_translation_status_row( $term );
	}

	/**
	 * Taxonomy edit: translation status table row.
	 *
	 * @since 2.2.0
	 * @param \WP_Term $term Term.
	 * @return void
	 */
	private function render_term_translation_status_row( $term ): void {
		if ( ! $term || empty( $term->term_id ) ) {
			return;
		}
		$map = $this->collect_term_translations( (int) $term->term_id );
		$current = isset( $map['_current_lang'] ) ? (string) $map['_current_lang'] : '';
		unset( $map['_current_lang'] );
		?>
		<tr class="form-field wptsall-term-translation-status">
			<th scope="row"><?php esc_html_e( 'Translation status', 'wpmmcc-ats' ); ?></th>
			<td>
				<table class="widefat striped" style="max-width:480px;">
					<thead><tr>
						<th><?php esc_html_e( 'Language', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					$languages = \WPTSALL\Languages\Services\Language_Service::get_all( array( 'status' => 'all' ) );
					foreach ( $languages as $lang ) {
						$code = (string) ( $lang['code'] ?? '' );
						if ( '' === $code ) {
							continue;
						}
						$tid        = isset( $map[ $code ] ) ? (int) $map[ $code ] : 0;
						$is_current = ( $code === $current ) || ( '' === $current && empty( $map ) && $code === (string) ( \WPTSALL\Languages\Services\Language_Service::get_default()['code'] ?? '' ) );
						if ( $is_current ) {
							$tid = (int) $term->term_id;
						}
						$status = __( 'Missing', 'wpmmcc-ats' );
						if ( $is_current ) {
							$status = __( 'Current', 'wpmmcc-ats' );
						} elseif ( $tid > 0 ) {
							$status = __( 'Mapped', 'wpmmcc-ats' );
						}
						echo '<tr><td>' . esc_html( $code ) . '</td><td>' . esc_html( $status ) . '</td><td>';
						if ( $tid > 0 ) {
							$edit = get_edit_term_link( $tid );
							$view = get_term_link( $tid );
							if ( ! is_wp_error( $view ) && class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) && class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
								foreach ( (array) \WPTSALL\Sites\Services\Virtual_Site_Service::get_all( array( 'status' => 'active' ) ) as $site ) {
									if ( (string) ( $site['lang'] ?? '' ) === $code ) {
										$view = \WPTSALL\Sites\Services\Url_Converter::virtualize( (string) $view, $site );
										break;
									}
								}
							}
							if ( $edit ) {
								echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'wpmmcc-ats' ) . '</a>';
							}
							if ( ! is_wp_error( $view ) && $view ) {
								echo ( $edit ? ' · ' : '' ) . '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'wpmmcc-ats' ) . '</a>';
							}
						} else {
							echo '—';
						}
						echo '</td></tr>';
					}
					?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php
	}

	/**
	 * Collect term translation target IDs keyed by language.
	 *
	 * @since 2.2.0
	 * @param int $term_id Term ID.
	 * @return array<string,int> lang => target_term_id (+ optional _current_lang).
	 */
	private function collect_term_translations( int $term_id ): array {
		global $wpdb;
		$out   = array();
		$table = wptsall_table( 'term_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT target_lang, target_term_id, source_term_id, source_lang
				 FROM %i
				 WHERE source_term_id = %d OR target_term_id = %d',
				$table,
				$term_id,
				$term_id
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$code = (string) ( $r['target_lang'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			if ( (int) $r['source_term_id'] === $term_id ) {
				$out[ $code ] = (int) $r['target_term_id'];
			}
			if ( (int) $r['target_term_id'] === $term_id ) {
				$out['_current_lang'] = $code;
			}
		}
		if ( ! isset( $out['_current_lang'] ) ) {
			$default = \WPTSALL\Languages\Services\Language_Service::get_default();
			$out['_current_lang'] = $default ? (string) $default['code'] : 'zh_CN';
		}
		return $out;
	}

	/**
	 * Add taxonomy site field (new page)
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function add_taxonomy_site_field_new( $taxonomy ) {
		$virtual_sites = $this->get_virtual_sites();

		wp_nonce_field( 'wptsall_taxonomy_site_nonce', 'wptsall_taxonomy_site_nonce' );
		?>
		<div class="form-field">
			<label for="wptsall_virtual_site_id"><?php esc_html_e( 'Virtual Site', 'wpmmcc-ats' ); ?></label>
			<select name="wptsall_virtual_site_id" id="wptsall_virtual_site_id" class="postform">
				<option value="0"><?php esc_html_e( 'Main Site', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( $virtual_sites as $site ) : ?>
					<option value="<?php echo esc_attr( $site['id'] ); ?>">
						<?php echo esc_html( $site['name'] ); ?> (<?php echo esc_html( $site['path_prefix'] ); ?>)
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Select which site this term belongs to.', 'wpmmcc-ats' ); ?></p>
		</div>
		<?php
	}

	/**
	 * save Taxonomy site Meta
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 */
	public function save_taxonomy_site_meta( $term_id, $tt_id ) {
		if ( ! isset( $_POST['wptsall_taxonomy_site_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wptsall_taxonomy_site_nonce'] ) ), 'wptsall_taxonomy_site_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$site_id     = isset( $_POST['wptsall_virtual_site_id'] ) ? intval( $_POST['wptsall_virtual_site_id'] ) : 0;
		$old_site_id = get_term_meta( $term_id, '_wptsall_virtual_site_id', true );

		if ( $site_id > 0 ) {
			update_term_meta( $term_id, '_wptsall_virtual_site_id', $site_id );
		} else {
			delete_term_meta( $term_id, '_wptsall_virtual_site_id' );
		}

		wptsall_log_info(
			'hooks-admin',
			'Term site assignment changed',
			array(
				'term_id'     => $term_id,
				'old_site_id' => $old_site_id ?: 'main',
				'new_site_id' => $site_id > 0 ? $site_id : 'main',
			)
		);
	}

	/**
	 * Display management notices
	 */
	public function show_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || ( 'edit' !== $screen->base && 'edit-tags' !== $screen->base ) ) {
			return;
		}

		if ( ! isset( $_GET['wptsall_site'] ) || ! isset( $_GET['wptsall_site_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wptsall_site_nonce'] ) ), 'wptsall_site_filter' ) ) {
			return;
		}

		$site_id = intval( $_GET['wptsall_site'] );

		if ( 0 === $site_id ) {
			return;
		}

		if ( -1 === $site_id ) {
			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'Showing content from: Main Site', 'wpmmcc-ats' );
			echo '</p></div>';
			return;
		}

		$virtual_sites = $this->get_virtual_sites();
		foreach ( $virtual_sites as $site ) {
			if ( intval( $site['id'] ) === $site_id ) {
				echo '<div class="notice notice-info"><p>';
				echo esc_html__( 'Showing content from:', 'wpmmcc-ats' ) . ' <strong>' . esc_html( $site['name'] ) . '</strong> (' . esc_html( $site['path_prefix'] ) . ')';
				echo '</p></div>';
				return;
			}
		}
	}

	/**
	 * Load management resources
	 */
	public function enqueue_admin_assets() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$style_rel  = 'assets/css/virtual-site-admin.css';
		$style_path = WPTSALL_PATH . $style_rel;
		wp_enqueue_style(
			'wptsall-virtual-site-admin',
			WPTSALL_URL . $style_rel,
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : WPTSALL_VERSION
		);
	}

	/**
	 * registerbulkaction
	 *
	 * @since 0.8.1
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public function register_bulk_actions( $bulk_actions ) {
		$bulk_actions['wptsall_assign_site']  = __( 'Assign to Virtual Site', 'wpmmcc-ats' );
		$bulk_actions['wptsall_move_to_main'] = __( 'Move to Main Site', 'wpmmcc-ats' );
		return $bulk_actions;
	}

	/**
	 * processbulkaction
	 *
	 * @since 0.8.1
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Action being performed.
	 * @param array  $post_ids    Array of post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
		if ( 'wptsall_assign_site' === $doaction ) {
			// Assign to virtual site
			if ( ! isset( $_REQUEST['wptsall_bulk_site_id'] ) ) {
				return $redirect_to;
			}

			$site_id = intval( $_REQUEST['wptsall_bulk_site_id'] );

			if ( $site_id <= 0 ) {
				return $redirect_to;
			}

			// Validate site exists
			$virtual_sites = $this->get_virtual_sites();
			$site_exists   = false;
			foreach ( $virtual_sites as $site ) {
				if ( intval( $site['id'] ) === $site_id ) {
					$site_exists = true;
					break;
				}
			}

			if ( ! $site_exists ) {
				return add_query_arg( 'wptsall_bulk_error', 'invalid_site', $redirect_to );
			}

			$updated = 0;
			foreach ( $post_ids as $post_id ) {
				if ( current_user_can( 'edit_post', $post_id ) ) {
					\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID, $site_id );
					++$updated;
				}
			}

			$redirect_to = add_query_arg( 'wptsall_bulk_assigned', $updated, $redirect_to );
			$redirect_to = add_query_arg( 'wptsall_bulk_site', $site_id, $redirect_to );

			wptsall_log_info(
				'hooks-admin',
				'Bulk assign to virtual site completed',
				array(
					'site_id'      => $site_id,
					'total_posts'  => count( $post_ids ),
					'updated'      => $updated,
				)
			);

		} elseif ( 'wptsall_move_to_main' === $doaction ) {
			// Move to Main site
			$updated = 0;
			foreach ( $post_ids as $post_id ) {
				if ( current_user_can( 'edit_post', $post_id ) ) {
					\WPTSALL\Sites\Services\Translation_Identity::delete_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
					++$updated;
				}
			}

			$redirect_to = add_query_arg( 'wptsall_bulk_moved', $updated, $redirect_to );

			wptsall_log_info(
				'hooks-admin',
				'Bulk move to main site completed',
				array(
					'total_posts' => count( $post_ids ),
					'updated'     => $updated,
				)
			);
		}

		return $redirect_to;
	}

	/**
	 * Render bulk action form (site selector)
	 *
	 * @since 0.8.1
	 */
	public function render_bulk_action_form() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		$post_type = $screen->post_type;
		if ( ! in_array( $post_type, $this->managed_types['post_types'], true ) ) {
			return;
		}

		$virtual_sites = $this->get_virtual_sites();
		if ( empty( $virtual_sites ) ) {
			return;
		}

		// Build site options
		$options = '';
		foreach ( $virtual_sites as $site ) {
			$options .= sprintf(
				'<option value="%d">%s (%s)</option>',
				esc_attr( $site['id'] ),
				esc_html( $site['name'] ),
				esc_html( $site['path_prefix'] )
			);
		}

		$select_label  = __( 'Select site...', 'wpmmcc-ats' );
		$alert_msg     = __( 'Please select a virtual site.', 'wpmmcc-ats' );
		$selector_html = '<option value="">' . esc_html( $select_label ) . '</option>' . $options;

		$script_rel  = 'assets/js/bulk-site-selector.js';
		$script_path = WPTSALL_PATH . $script_rel;
		wp_enqueue_script(
			'wptsall-bulk-site-selector',
			WPTSALL_URL . $script_rel,
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : WPTSALL_VERSION,
			true
		);
		wp_localize_script(
			'wptsall-bulk-site-selector',
			'wptsallBulkSiteSelector',
			array(
				'selectorHtml' => $selector_html,
				'alertMsg'     => $alert_msg,
			)
		);

		// Display bulk actions result notices
		$this->show_bulk_action_notices();
	}

	/**
	 * Display bulk action result notices
	 *
	 * @since 0.8.1
	 */
	private function show_bulk_action_notices() {
		// Assignment success
		if ( isset( $_GET['wptsall_bulk_assigned'] ) ) {
			$count   = intval( $_GET['wptsall_bulk_assigned'] );
			$site_id = isset( $_GET['wptsall_bulk_site'] ) ? intval( $_GET['wptsall_bulk_site'] ) : 0;

			$site_name = '';
			if ( $site_id > 0 ) {
				$virtual_sites = $this->get_virtual_sites();
				foreach ( $virtual_sites as $site ) {
					if ( intval( $site['id'] ) === $site_id ) {
						$site_name = $site['name'];
						break;
					}
				}
			}

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: 1: number of posts, 2: site name */
					esc_html( _n(
						'%1$d post assigned to %2$s.',
						'%1$d posts assigned to %2$s.',
						$count,
						'wpmmcc-ats'
					) ),
					absint( $count ),
					'<strong>' . esc_html( $site_name ) . '</strong>'
				)
			);
		}

		// Move to Main site success
		if ( isset( $_GET['wptsall_bulk_moved'] ) ) {
			$count = intval( $_GET['wptsall_bulk_moved'] );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %d: number of posts */
					esc_html( _n(
						'%d post moved to Main Site.',
						'%d posts moved to Main Site.',
						$count,
						'wpmmcc-ats'
					) ),
					absint( $count )
				)
			);
		}

		// error
		if ( isset( $_GET['wptsall_bulk_error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['wptsall_bulk_error'] ) );
			$message = '';

			switch ( $error ) {
				case 'invalid_site':
					$message = __( 'Invalid virtual site selected.', 'wpmmcc-ats' );
					break;
				default:
					$message = __( 'An error occurred during the bulk action.', 'wpmmcc-ats' );
			}

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Trigger sync action on save and auto-sync non-translatable fields.
	 *
	 * Two behaviors:
	 * 1. If "Sync to virtual sites on save" checkbox is checked, triggers full sync action.
	 * 2. Always auto-syncs non-translatable (sync) fields to existing target posts
	 *    for all active relations. This ensures sync fields stay in sync without
	 *    explicit user action. Translate fields are never overwritten.
	 *
	 * @since 0.9.0
	 * @since 1.1.0 Added auto-sync of non-translatable fields to existing targets.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function on_save_post_sync( $post_id, $post, $update ) {
		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check user capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check post type.
		if ( ! in_array( $post->post_type, $this->managed_types['post_types'], true ) ) {
			return;
		}

		// Skip virtual site copies (translated posts should not trigger sync).
		$virtual_site_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post_id, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( ! empty( $virtual_site_id ) ) {
			return;
		}

		// Prevent infinite recursion.
		static $syncing_posts = array();
		if ( isset( $syncing_posts[ $post_id ] ) ) {
			return;
		}
		$syncing_posts[ $post_id ] = true;

		// 1. Full sync if checkbox is checked (requires nonce).
		if ( isset( $_POST['wptsall_site_meta_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wptsall_site_meta_nonce'] ) ), 'wptsall_site_meta_nonce' ) &&
			! empty( $_POST['wptsall_sync_on_save'] ) ) {

			do_action( 'wptsall_sync_post_to_virtual_sites', $post_id, $post );

			wptsall_log_info(
				'hooks-admin',
				'Post sync to virtual sites requested',
				array(
					'post_id'   => $post_id,
					'post_type' => $post->post_type,
				)
			);
		}

		// 2. Auto-sync non-translatable fields to existing target posts.
		if ( $update ) {
			$this->auto_sync_non_translatable_fields( $post_id, $post );
		}

		unset( $syncing_posts[ $post_id ] );
	}

	/**
	 * Auto-sync non-translatable (sync) fields to existing target posts.
	 *
	 * For each active relation with an existing translation of this post,
	 * updates sync fields and id_mapping fields. Translate fields are never touched.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $post_id Source post ID.
	 * @param \WP_Post $post    Source post object.
	 */
	private function auto_sync_non_translatable_fields( int $post_id, \WP_Post $post ): void {
		// Get all active relations for the current source site.
		$relations = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations(
			array(
				'source_site_id' => get_current_blog_id(),
				'status'         => 'active',
			)
		);

		if ( empty( $relations ) ) {
			return;
		}

		foreach ( $relations as $relation ) {
			$relation_id = (int) $relation['id'];

			// Check if a translation already exists for this post+relation.
			$target_id = \WPTSALL\Sites\Services\Manual_Content_Service::find_existing_translation( $post_id, $relation_id );

			if ( ! $target_id ) {
				continue; // No existing translation, skip (no auto-create).
			}

			// Get field config to determine which fields are sync vs translate.
			$field_config = \WPTSALL\Models\Services\Translation_Rule_Service::get_merged_config_for_relation(
				$relation_id,
				$post->post_type
			);

			$sync_fields       = $field_config['sync_fields'] ?? array();
			$copy_once_fields  = $field_config['copy_once_fields'] ?? array();
			$id_mapping_fields = $field_config['id_mapping_fields'] ?? array();

			if ( empty( $sync_fields ) && empty( $copy_once_fields ) && empty( $id_mapping_fields ) ) {
				continue;
			}

			// Build update data from sync fields (always overwrite).
			$update_data = array( 'ID' => $target_id );

			foreach ( $sync_fields as $field_name ) {
				if ( property_exists( $post, $field_name ) ) {
					$update_data[ $field_name ] = $post->$field_name;
				}
			}

			// Copy-once post columns: only when target is still empty/default.
			foreach ( $copy_once_fields as $field_name ) {
				if ( ! property_exists( $post, $field_name ) ) {
					continue;
				}
				$target_post = get_post( $target_id );
				$existing    = ( $target_post && property_exists( $target_post, $field_name ) ) ? $target_post->$field_name : '';
				if ( class_exists( '\\WPTSALL\\Models\\Services\\Field_Capability' )
					&& ! \WPTSALL\Models\Services\Field_Capability::should_write_to_target( 'copy_once', $existing ) ) {
					continue;
				}
				$update_data[ $field_name ] = $post->$field_name;
			}

			$target_site_type = $relation['target_site_type'] ?? 'wp';
			$switched_blog    = false;

			if ( 'wp' === $target_site_type && is_multisite() ) {
				switch_to_blog( (int) $relation['target_site_id'] );
				$switched_blog = true;
			}

			// Update the target post with sync fields.
			if ( count( $update_data ) > 1 ) { // More than just 'ID'.
				wp_update_post( $update_data );
			}

			// Update id_mapping meta fields.
			foreach ( $id_mapping_fields as $field_name ) {
				// Skip standard post columns (handled via wp_update_post above).
				if ( property_exists( $post, $field_name ) ) {
					continue;
				}
				// Copy meta from source to target.
				$source_meta = get_post_meta( $post_id, $field_name, true );
				if ( '' !== $source_meta ) {
					update_post_meta( $target_id, $field_name, $source_meta );
				}
			}

			// Copy-once meta: write only when target empty (WPML Copy once).
			foreach ( $copy_once_fields as $field_name ) {
				if ( property_exists( $post, $field_name ) ) {
					continue;
				}
				$existing = get_post_meta( $target_id, $field_name, true );
				if ( class_exists( '\\WPTSALL\\Models\\Services\\Field_Capability' )
					&& ! \WPTSALL\Models\Services\Field_Capability::should_write_to_target( 'copy_once', $existing ) ) {
					continue;
				}
				$source_meta = get_post_meta( $post_id, $field_name, true );
				if ( '' !== $source_meta && false !== $source_meta ) {
					update_post_meta( $target_id, $field_name, $source_meta );
				}
			}

			// Update sync timestamp.
			\WPTSALL\Sites\Services\Translation_Identity::write_meta( (int) $target_id, '_wptsall_last_synced', current_time( 'mysql' ) );

			if ( $switched_blog ) {
				restore_current_blog();
			}

			wptsall_log_debug(
				'hooks-admin',
				'Auto-synced non-translatable fields to target',
				array(
					'source_post_id' => $post_id,
					'target_id'      => $target_id,
					'relation_id'    => $relation_id,
					'sync_fields'    => $sync_fields,
				)
			);
		}
	}

	/**
	 * Display virtual site indicator at the top of edit form
	 *
	 * Migrated from Admin_Hooks
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function show_virtual_site_indicator( $post ) {
		if ( ! in_array( $post->post_type, $this->managed_types['post_types'], true ) ) {
			return;
		}

		$virtual_sites = $this->get_virtual_sites_for_post( $post->ID );

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
	 * Get virtual site copies for a specific post
	 *
	 * Migrated from Admin_Hooks
	 *
	 * @since 0.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_virtual_sites_for_post( $post_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wptsall_virtual_site_content';

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
		$all_sites     = $this->get_virtual_sites();

		foreach ( $virtual_site_ids as $virtual_site_id ) {
			// Extract numeric ID from virtual_site_id (e.g., 'v_2' -> 2)
			$site_id = intval( str_replace( 'v_', '', $virtual_site_id ) );

			foreach ( $all_sites as $site ) {
				if ( intval( $site['id'] ) === $site_id ) {
					$virtual_sites[] = $site;
					break;
				}
			}
		}

		return $virtual_sites;
	}

	/**
	 * Add translation row action link (v1.1.0)
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Post object.
	 * @return array Modified actions.
	 */
	public function add_translation_row_actions( $actions, $post ) {
		// Only for source site posts (not translated copies)
		$virtual_site_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post->ID, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( ! empty( $virtual_site_id ) ) {
			return $actions;
		}

		// Keep capability aligned with the hidden translation editor page.
		if ( ! wptsall_user_can_manage_translations() ) {
			return $actions;
		}

		$statuses = \WPTSALL\Sites\Services\Manual_Content_Service::get_translation_status( $post->ID, $post->post_type );
		$statuses = self::filter_translation_statuses_for_post_type( $statuses, $post->post_type );
		if ( empty( $statuses ) ) {
			return $actions;
		}

		$show_target_name = count( $statuses ) > 1;

		foreach ( $statuses as $s ) {
			$url = admin_url( 'admin.php?page=wptsall-translate&source_post_id=' . $post->ID . '&relation_id=' . intval( $s['relation_id'] ) );
			$key = 'wptsall_translate_' . $s['relation_id'];
			$target_label = trim( (string) ( $s['target_site_name'] ?? '' ) );
			$label        = $show_target_name && '' !== $target_label
				? sprintf(
					/* translators: %s: target site/language label */
					__( 'Translate (%s)', 'wpmmcc-ats' ),
					$target_label
				)
				: __( 'Translate', 'wpmmcc-ats' );
			$actions[ $key ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Keep only translation statuses that have explicit rules for the post type.
	 *
	 * @since 1.7.0
	 *
	 * @param array  $statuses  Status entries from Manual_Content_Service::get_translation_status().
	 * @param string $post_type Source post type.
	 * @return array
	 */
	private static function filter_translation_statuses_for_post_type( array $statuses, string $post_type ): array {
		if ( '' === $post_type || empty( $statuses ) ) {
			return array();
		}

		$filtered = array();
		foreach ( $statuses as $status ) {
			$relation_id = (int) ( $status['relation_id'] ?? 0 );
			if ( $relation_id <= 0 ) {
				continue;
			}
			if ( ! self::has_translation_rule_for_relation( $relation_id, $post_type ) ) {
				continue;
			}
			$filtered[] = $status;
		}

		return $filtered;
	}

	/**
	 * Rewrite admin View permalinks for shadow posts to the virtual-site URL.
	 *
	 * @since 2.2.0
	 *
	 * @param string       $permalink Permalink.
	 * @param int|\WP_Post $post      Post or ID.
	 * @return string
	 */
	public function rewrite_admin_virtual_permalink( $permalink, $post = null ) {
		$post_obj = is_object( $post ) ? $post : get_post( $post );
		if ( ! $post_obj || empty( $post_obj->ID ) ) {
			return $permalink;
		}
		$vs_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) (int) $post_obj->ID, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( ! $vs_id ) {
			return $permalink;
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' )
			|| ! class_exists( '\\WPTSALL\\Sites\\Services\\Url_Converter' ) ) {
			return $permalink;
		}
		$vs = \WPTSALL\Sites\Services\Virtual_Site_Service::get( $vs_id );
		if ( ! is_array( $vs ) || empty( $vs['path_prefix'] ) ) {
			return $permalink;
		}
		return \WPTSALL\Sites\Services\Url_Converter::virtualize( (string) $permalink, $vs, $post_obj );
	}

	/**
	 * Rewrite preview links for shadow posts.
	 *
	 * @since 2.2.0
	 *
	 * @param string  $preview Preview URL.
	 * @param \WP_Post $post   Post.
	 * @return string
	 */
	public function rewrite_admin_preview_link( $preview, $post = null ) {
		return $this->rewrite_admin_virtual_permalink( $preview, $post );
	}

	/**
	 * Show virtual prefix in the sample permalink HTML on the edit screen.
	 *
	 * @since 2.2.0
	 *
	 * @param string $html    Sample permalink HTML.
	 * @param int    $post_id Post ID.
	 * @param string $new_title Title.
	 * @param string $new_slug Slug.
	 * @param \WP_Post $post  Post.
	 * @return string
	 */
	public function filter_sample_permalink_html( $html, $post_id = 0, $new_title = null, $new_slug = null, $post = null ) {
		$post_obj = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post_obj ) {
			return $html;
		}
		$vs_id = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) (int) $post_obj->ID, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( ! $vs_id || ! class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			return $html;
		}
		$vs = \WPTSALL\Sites\Services\Virtual_Site_Service::get( $vs_id );
		$prefix = is_array( $vs ) ? trim( (string) ( $vs['path_prefix'] ?? '' ), '/' ) : '';
		if ( '' === $prefix || ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false !== strpos( $html, '/' . $prefix . '/' ) ) {
			return $html;
		}
		// Inject a visible prefix hint before the editable slug span when possible.
		$hint = '<span class="wptsall-sample-prefix">' . esc_html( $prefix ) . '/</span>';
		if ( false !== strpos( $html, '<span id="editable-post-name"' ) ) {
			return str_replace( '<span id="editable-post-name"', $hint . '<span id="editable-post-name"', $html );
		}
		return $hint . $html;
	}
}

// Initializemanager
new Admin_Virtual_Site_Manager();
