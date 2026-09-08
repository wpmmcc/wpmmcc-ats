<?php
/**
 * WPTSALL Tasks Page
 *
 * Tabbed Tasks admin page for the unified edition.
 * Central hub for translation operations:
 * - Site Relations overview
 * - Sync Records (translation results history)
 * - Language Packs (i18n scan status)
 * - Authorization (license + API token management)
 * - Features (showcase)
 *
 * @package WPTSALL\Tasks\Admin
 * @since 1.2.0
 */

namespace WPTSALL\Tasks\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Model_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tasks_Page {

	const PAGE_SLUG = 'wptsall-tasks';

	private static $active_tab = 'relations';

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		// Legacy admin-post hook: clear stale license options from older builds.
		add_action( 'admin_post_wptsall_delete_license', array( __CLASS__, 'handle_delete_license' ) );
	}

	/**
	 * Clear legacy license options (all features are free; no license is required).
	 *
	 * @since 1.9.0 Implemented to avoid fatal from missing admin_post handler.
	 * @return void
	 */
	public static function handle_delete_license() {
		if ( ! wptsall_user_can_manage_translations() ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_delete_license' );

		delete_option( 'wptsall_license_key' );
		delete_option( 'wptsall_license_status' );
		delete_option( 'wptsall_license_data' );
		delete_option( 'wptsall_pro_active' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE_SLUG,
					'tab'             => 'authorization',
					'license_cleared' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'wptsall-tasks' ) === false ) {
			return;
		}
		// admin-common.css is already loaded globally by menu.php
	}

	private static function get_tabs() {
		return array(
			'monitoring'   => __( 'Monitoring', 'wpmmcc-ats' ),
			'jobs'         => __( 'Job Aggregate', 'wpmmcc-ats' ),
			'relations'    => __( 'Site Relations', 'wpmmcc-ats' ),
			'sync_records' => __( 'Sync Records', 'wpmmcc-ats' ),
			'lang_packs'   => __( 'Language Packs', 'wpmmcc-ats' ),
			'authorization' => __( 'Authorization', 'wpmmcc-ats' ),
			'features'     => __( 'Features', 'wpmmcc-ats' ),
		);
	}

	public static function render_page() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'relations';

		$tabs     = self::get_tabs();
		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		$actions = array();
		if ( 'monitoring' === self::$active_tab ) {
			$actions[] = array(
				'label' => __( 'Add Monitoring Task', 'wpmmcc-ats' ),
				'url'   => '#',
				'id'    => 'wptsall-add-task-btn',
				'class' => 'button button-primary',
			);
		}

		Admin_Page_Helper::render_header(
			__( 'Task Management', 'wpmmcc-ats' ),
			'tasks',
			$actions
		);

		Admin_Page_Helper::render_tabs( $tabs, self::$active_tab, $base_url );

		echo '<div class="wptsall-tab-content">';

		switch ( self::$active_tab ) {
			case 'monitoring':
				self::render_monitoring_tab();
				break;

			case 'jobs':
				self::render_jobs_tab();
				break;

			case 'relations':
				self::render_relations_tab();
				break;

			case 'sync_records':
				self::render_sync_records_tab();
				break;

			case 'lang_packs':
				self::render_lang_packs_tab();
				break;

			case 'authorization':
				self::render_authorization_tab();
				break;

			case 'features':
				self::render_features_tab();
				break;

			default:
				self::render_relations_tab();
				break;
		}

		echo '</div>';

		Admin_Page_Helper::render_footer();
	}

	// =====================================================================
	// Tab: Monitoring
	// =====================================================================

	private static function render_monitoring_tab() {
		if ( ! function_exists( 'wptsall_render_monitoring_tasks_tab' ) ) {
			require_once __DIR__ . '/admin-tasks-list.php';
		}
		wptsall_render_monitoring_tasks_tab();
	}

	private static function render_jobs_tab() {
		if ( ! function_exists( 'wptsall_render_task_jobs_tab' ) ) {
			require_once __DIR__ . '/admin-tasks-list.php';
		}
		wptsall_render_task_jobs_tab();
	}

	// =====================================================================
	// Tab: Site Relations
	// =====================================================================

	private static function render_relations_tab() {
		$relations = Site_Relation_Service::get_all_relations();
		$sites_url = admin_url( 'admin.php?page=wptsall-sites' );

		echo '<div style="margin-bottom: 12px;">';
		echo '<a href="' . esc_url( add_query_arg( 'tab', 'add', $sites_url ) ) . '" class="button button-primary">';
		echo esc_html__( 'Add Site Relation', 'wpmmcc-ats' ) . '</a>';
		echo '</div>';

		if ( empty( $relations ) ) {
			echo '<p>' . esc_html__( 'No site relations configured yet. Add a site relation to start translating content.', 'wpmmcc-ats' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Target', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Template', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Languages', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Models', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wpmmcc-ats' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $relations as $rel ) {
			$relation_id = (int) $rel['id'];
			$models      = Relation_Model_Service::get_models_by_relation( $relation_id );
			$model_count = count( $models );
			$status      = $rel['status'] ?? 'unknown';

			$status_badge = 'active' === $status
				? '<span style="color:#059669;font-weight:600;">' . esc_html( $status ) . '</span>'
				: '<span style="color:#6b7280;">' . esc_html( $status ) . '</span>';

			echo '<tr>';
			echo '<td>' . esc_html( $relation_id ) . '</td>';
			echo '<td>' . esc_html( $rel['source_lang'] ?? '' ) . ' (site ' . esc_html( $rel['source_site_id'] ?? '' ) . ')</td>';
			echo '<td>' . esc_html( $rel['target_lang'] ?? '' ) . ' (' . esc_html( $rel['target_site_type'] ?? 'wp' ) . ': ' . esc_html( $rel['target_site_id'] ?? '' ) . ')</td>';
			echo '<td>' . esc_html( $rel['template'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( ( $rel['source_lang'] ?? '' ) . ' → ' . ( $rel['target_lang'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $model_count ) . '</td>';
			echo '<td>' . $status_badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description" style="margin-top: 10px;">';
		echo '<a href="' . esc_url( $sites_url ) . '">';
		echo esc_html__( 'Full site management (virtual sites, conflict resolution)', 'wpmmcc-ats' );
		echo ' &rarr;</a></p>';
	}

	// =====================================================================
	// Tab: Sync Records
	// =====================================================================

	private static function render_sync_records_tab() {
		global $wpdb;

		$results_table = wptsall_table( 'translation_results' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $results_table ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, relation_id, object_type, object_id, status, source_lang, target_lang, client_task_id, created_at, synced_at
				FROM %i
				ORDER BY id DESC
				LIMIT %d OFFSET %d',
				$results_table,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p>' . sprintf(
			/* translators: %d: total number of sync records */
			esc_html__( 'Total %d sync records.', 'wpmmcc-ats' ),
			$total
		) . '</p>';
		// phpcs:enable

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No sync records yet. Records will appear here after the translation client processes content.', 'wpmmcc-ats' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Relation', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Object', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Languages', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Client Task', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'wpmmcc-ats' ) . '</th>';
		echo '<th>' . esc_html__( 'Synced', 'wpmmcc-ats' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$status_color = 'synced' === $row['status'] ? '#059669' : ( 'pending' === $row['status'] ? '#d97706' : '#6b7280' );

			echo '<tr>';
			echo '<td>' . esc_html( $row['id'] ) . '</td>';
			echo '<td>#' . esc_html( $row['relation_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['object_type'] ) . ' #' . esc_html( $row['object_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['source_lang'] ) . ' → ' . esc_html( $row['target_lang'] ) . '</td>';
			echo '<td><span style="color:' . esc_attr( $status_color ) . ';font-weight:600;">' . esc_html( $row['status'] ) . '</span></td>';
			echo '<td><code style="font-size:11px;">' . esc_html( mb_substr( $row['client_task_id'], 0, 16 ) ) . '</code></td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $row['synced_at'] ?: '-' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Pagination.
		$total_pages = ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=sync_records' );
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				if ( $i === $page ) {
					echo '<span class="tablenav-pages-navspan button disabled">' . esc_html( $i ) . '</span> ';
				} else {
					echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $i, $base_url ) ) . '">' . esc_html( $i ) . '</a> ';
				}
			}
			echo '</div></div>';
		}
	}

	// =====================================================================
	// Tab: Language Packs
	// =====================================================================

	private static function render_lang_packs_tab() {
		global $wpdb;

		$tasks_table = wptsall_table( 'tasks' );

		// Get monitoring tasks that have i18n scan data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$monitoring_tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, relation_id, status, meta
				FROM %i
				WHERE type = %s
				ORDER BY id DESC
				LIMIT %d",
				$tasks_table,
				'monitoring',
				50
			),
			ARRAY_A
		);

		echo '<h3>' . esc_html__( 'i18n Scan Status by Relation', 'wpmmcc-ats' ) . '</h3>';

		$has_scans = false;

		if ( ! empty( $monitoring_tasks ) ) {
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Relation', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Task Status', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Scan Status', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Templates Found', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Entries Scanned', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Retries', 'wpmmcc-ats' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $monitoring_tasks as $task ) {
				$meta = json_decode( $task['meta'] ?? '{}', true ) ?: array();
				$scan = $meta['i18n_scan'] ?? null;

				if ( ! $scan ) {
					continue;
				}
				$has_scans = true;

				$scan_status = $scan['status'] ?? 'not_started';
				$templates   = $scan['templates_found'] ?? 0;
				$entries     = $scan['entries_scanned'] ?? 0;
				$retries     = $scan['retry_count'] ?? 0;

				$status_color = 'completed' === $scan_status ? '#059669' : ( 'in_progress' === $scan_status ? '#2563eb' : '#6b7280' );

				echo '<tr>';
				echo '<td>#' . esc_html( $task['relation_id'] ) . '</td>';
				echo '<td>' . esc_html( $task['status'] ) . '</td>';
				echo '<td><span style="color:' . esc_attr( $status_color ) . ';font-weight:600;">' . esc_html( $scan_status ) . '</span></td>';
				echo '<td>' . esc_html( $templates ) . '</td>';
				echo '<td>' . esc_html( $entries ) . '</td>';
				echo '<td>' . esc_html( $retries ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( ! $has_scans ) {
			echo '<p>' . esc_html__( 'No language pack scans have been performed yet. i18n scans run automatically for virtual site relations with translation enabled.', 'wpmmcc-ats' ) . '</p>';
		}

		// Template entries summary.
		$entries_table = wptsall_table( 'template_entries' );
		$templates_table = wptsall_table( 'templates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry_stats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name, t.type,
					COUNT(e.id) AS total_entries,
					SUM(CASE WHEN e.status = %s THEN 1 ELSE 0 END) AS translated
				FROM %i t
				LEFT JOIN %i e ON t.id = e.template_id
				GROUP BY t.id
				ORDER BY t.name",
				'translated',
				$templates_table,
				$entries_table
			),
			ARRAY_A
		);

		if ( ! empty( $entry_stats ) ) {
			echo '<h3 style="margin-top: 24px;">' . esc_html__( 'Template Translation Progress', 'wpmmcc-ats' ) . '</h3>';
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Template', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Total Entries', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Translated', 'wpmmcc-ats' ) . '</th>';
			echo '<th>' . esc_html__( 'Progress', 'wpmmcc-ats' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $entry_stats as $stat ) {
				$total      = (int) $stat['total_entries'];
				$translated = (int) $stat['translated'];
				$pct        = $total > 0 ? round( ( $translated / $total ) * 100 ) : 0;

				echo '<tr>';
				echo '<td>' . esc_html( $stat['name'] ) . '</td>';
				echo '<td>' . esc_html( $stat['type'] ) . '</td>';
				echo '<td>' . esc_html( $total ) . '</td>';
				echo '<td>' . esc_html( $translated ) . '</td>';
				echo '<td>' . esc_html( $pct ) . '%</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

	// =====================================================================
	// Tab: Authorization
	// =====================================================================

	private static function render_authorization_tab() {
		echo '<p>' . esc_html__( 'All features are free. No license key required.', 'wpmmcc-ats' ) . '</p>';
		echo '<p>' . esc_html__( 'The client API token is automatically generated for communication with the translation client.', 'wpmmcc-ats' ) . '</p>';
	}

	// =====================================================================
	// Tab: Features
	// =====================================================================

	private static function render_features_tab() {
		echo '<div class="wptsall-feature-showcase">';

		echo '<div class="wptsall-feature-intro">';
		echo '<h3>' . esc_html__( 'Task Management Features', 'wpmmcc-ats' ) . '</h3>';
		echo '<p>' . esc_html__( 'Automate content synchronization and translation workflows with powerful task management capabilities.', 'wpmmcc-ats' ) . '</p>';
		echo '</div>';

		echo '<div class="wptsall-feature-grid">';

		self::render_feature_card(
			'dashicons-controls-repeat',
			__( 'Monitoring Tasks', 'wpmmcc-ats' ),
			__( 'Automatically detect new and updated content across your site relations. Monitoring tasks continuously track changes and create sync jobs when content needs translation.', 'wpmmcc-ats' )
		);

		self::render_feature_card(
			'dashicons-networking',
			__( 'Job Aggregation', 'wpmmcc-ats' ),
			__( 'Group individual sync tasks into optimized batches for efficient processing. Reduce API calls and improve throughput with intelligent job planning.', 'wpmmcc-ats' )
		);

		self::render_feature_card(
			'dashicons-translation',
			__( 'Automated Translation', 'wpmmcc-ats' ),
			__( 'The self-hosted translation service pulls pending tasks, processes them through translation components, and submits results back to WordPress automatically.', 'wpmmcc-ats' )
		);

		self::render_feature_card(
			'dashicons-backup',
			__( 'Task History', 'wpmmcc-ats' ),
			__( 'Full audit trail of all sync and translation operations. Track task status, execution times, error details, and content changes over time.', 'wpmmcc-ats' )
		);

		self::render_feature_card(
			'dashicons-admin-settings',
			__( 'Task Parameters', 'wpmmcc-ats' ),
			__( 'Fine-tune task behavior with configurable parameters. Control batch sizes, scheduling intervals, retry policies, and content filtering rules.', 'wpmmcc-ats' )
		);

		self::render_feature_card(
			'dashicons-admin-multisite',
			__( 'i18n String Scanning', 'wpmmcc-ats' ),
			__( 'Scan plugin and theme translation strings to build comprehensive language packs. Automatically discover translatable strings from .pot files and source code.', 'wpmmcc-ats' )
		);

		echo '</div>'; // .wptsall-feature-grid

		echo '</div>'; // .wptsall-feature-showcase
	}

	private static function render_feature_card( $icon, $title, $description ) {
		echo '<div class="wptsall-feature-card">';
		echo '<div class="wptsall-feature-icon">';
		echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
		echo '</div>';
		echo '<div class="wptsall-feature-body">';
		echo '<h4>' . esc_html( $title ) . '</h4>';
		echo '<p>' . esc_html( $description ) . '</p>';
		echo '</div>';
		echo '</div>';
	}
}
