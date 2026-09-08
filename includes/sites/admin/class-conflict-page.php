<?php
/**
 * WPTSALL Conflict Management Page
 *
 * Conflict management page - view and resolve sync conflicts
 *
 * @package WPTSALL\Sites\Admin
 * @since 0.9.0
 */

namespace WPTSALL\Sites\Admin;

use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conflict Page Class
 *
 * Provides conflict management UI:
 * 1. Conflict statistics overview
 * 2. conflictlist（pagination、filter）
 * 3. Conflict resolution popup
 * 4. bulkaction
 */
class Conflict_Page {

	/**
	 * Page slug
	 */
	const PAGE_SLUG = 'wptsall-conflicts';

	/**
	 * Initialize
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts and styles
	 *
	 * @since 0.9.0
	 * @updated 0.9.1 - Support loading on Sites page conflicts tab
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		// Check if we're on the Sites page with conflicts tab
		$is_sites_conflicts_tab = (
			strpos( $hook, 'wptsall-sites' ) !== false &&
			isset( $_GET['tab'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'conflicts' === $_GET['tab'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		// Also support legacy standalone page (for backwards compatibility)
		$is_standalone_page = strpos( $hook, self::PAGE_SLUG ) !== false;

		if ( ! $is_sites_conflicts_tab && ! $is_standalone_page ) {
			return;
		}

		wp_enqueue_style(
			'wptsall-conflict-manager',
			WPTSALL_URL . 'assets/css/conflict-manager.css',
			array(),
			WPTSALL_VERSION
		);

		wp_enqueue_script(
			'wptsall-conflict-manager',
			WPTSALL_URL . 'assets/js/conflict-manager.js',
			array( 'jquery', 'wp-api-fetch' ),
			WPTSALL_VERSION,
			true
		);

		wp_localize_script(
			'wptsall-conflict-manager',
			'wptsallConflicts',
			array(
				'restUrl'   => rest_url( 'wptsall/v2' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'confirm_resolve'      => __( 'Are you sure you want to resolve this conflict?', 'wpmmcc-ats' ),
					'confirm_auto_resolve' => __( 'Are you sure you want to auto-resolve all pending conflicts?', 'wpmmcc-ats' ),
					'resolving'            => __( 'Resolving...', 'wpmmcc-ats' ),
					'resolved'             => __( 'Resolved', 'wpmmcc-ats' ),
					'loading'              => __( 'Loading...', 'wpmmcc-ats' ),
					'no_conflicts'         => __( 'No conflict records', 'wpmmcc-ats' ),
					'error'                => __( 'Operation failed', 'wpmmcc-ats' ),
					'success'              => __( 'Operation successful', 'wpmmcc-ats' ),
					'source_value'         => __( 'Source Site Value', 'wpmmcc-ats' ),
					'target_value'         => __( 'Target Site Value', 'wpmmcc-ats' ),
					'select_strategy'      => __( 'Select resolution strategy', 'wpmmcc-ats' ),
				),
				'strategies' => array(
					'source_wins' => __( 'Source Wins', 'wpmmcc-ats' ),
					'target_wins' => __( 'Target Wins', 'wpmmcc-ats' ),
					'newest_wins' => __( 'Newest Wins', 'wpmmcc-ats' ),
					'manual'      => __( 'Manual', 'wpmmcc-ats' ),
					'merge'       => __( 'Merge', 'wpmmcc-ats' ),
				),
			)
		);
	}

	/**
	 * Render page (standalone page)
	 *
	 * @deprecated 0.9.1 Use render_content() instead, conflicts are now a tab in Sites page.
	 */
	public static function render_page() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}

		Admin_Page_Helper::render_header(
			__( 'Conflict Management', 'wpmmcc-ats' ),
			'conflicts',
			array(),
			array( 'wptsall-conflict-page' )
		);
		self::render_content();
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Render content (for embedding in Sites page tab)
	 *
	 * @since 0.9.1
	 */
	public static function render_content() {
		?>

			<!-- Stats Summary -->
			<div class="wptsall-conflict-stats" id="conflict-stats">
				<div class="stats-loading"><?php esc_html_e( 'Loading statistics...', 'wpmmcc-ats' ); ?></div>
			</div>

			<!-- Toolbar -->
			<div class="wptsall-conflict-toolbar">
				<div class="toolbar-left">
					<select id="conflict-status-filter" class="conflict-filter">
						<option value=""><?php esc_html_e( 'All Statuses', 'wpmmcc-ats' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'wpmmcc-ats' ); ?></option>
						<option value="resolved"><?php esc_html_e( 'Resolved', 'wpmmcc-ats' ); ?></option>
					</select>
					<button type="button" id="btn-refresh" class="button">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh', 'wpmmcc-ats' ); ?>
					</button>
				</div>
				<div class="toolbar-right">
					<button type="button" id="btn-auto-resolve" class="button button-primary">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Auto-resolve All', 'wpmmcc-ats' ); ?>
					</button>
				</div>
			</div>

			<!-- Conflict List -->
			<div class="wptsall-conflict-list" id="conflict-list">
				<div class="list-loading"><?php esc_html_e( 'Loading conflict list...', 'wpmmcc-ats' ); ?></div>
			</div>

			<!-- Pagination -->
			<div class="wptsall-conflict-pagination" id="conflict-pagination"></div>

			<!-- Resolve Modal -->
			<?php self::render_resolve_modal(); ?>
		<?php
	}

	/**
	 * Render resolve modal
	 */
	private static function render_resolve_modal() {
		?>
		<div id="conflict-resolve-modal" class="wptsall-modal" style="display:none;">
			<div class="wptsall-modal-overlay"></div>
			<div class="wptsall-modal-content">
				<div class="wptsall-modal-header">
					<h2><?php esc_html_e( 'Resolve Conflict', 'wpmmcc-ats' ); ?></h2>
					<button type="button" class="wptsall-modal-close">&times;</button>
				</div>
				<div class="wptsall-modal-body">
					<!-- Conflict Info -->
					<div class="conflict-info" id="modal-conflict-info">
						<div class="info-row">
							<span class="info-label"><?php esc_html_e( 'Object Type', 'wpmmcc-ats' ); ?>:</span>
							<span class="info-value" id="modal-object-type"></span>
						</div>
						<div class="info-row">
							<span class="info-label"><?php esc_html_e( 'Conflict Type', 'wpmmcc-ats' ); ?>:</span>
							<span class="info-value" id="modal-conflict-type"></span>
						</div>
						<div class="info-row">
							<span class="info-label"><?php esc_html_e( 'Detection Time', 'wpmmcc-ats' ); ?>:</span>
							<span class="info-value" id="modal-detected-at"></span>
						</div>
					</div>

					<!-- Field Comparison -->
					<div class="conflict-comparison" id="modal-comparison">
						<h3><?php esc_html_e( 'Field Comparison', 'wpmmcc-ats' ); ?></h3>
						<table class="comparison-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Field', 'wpmmcc-ats' ); ?></th>
									<th><?php esc_html_e( 'Source Site Value', 'wpmmcc-ats' ); ?></th>
									<th><?php esc_html_e( 'Target Site Value', 'wpmmcc-ats' ); ?></th>
									<th class="manual-select-col" style="display:none;"><?php esc_html_e( 'Select', 'wpmmcc-ats' ); ?></th>
								</tr>
							</thead>
							<tbody id="modal-comparison-body">
							</tbody>
						</table>
					</div>

					<!-- Strategy Selection -->
					<div class="conflict-strategy">
						<h3><?php esc_html_e( 'Resolution Strategy', 'wpmmcc-ats' ); ?></h3>
						<div class="strategy-options">
							<label class="strategy-option">
								<input type="radio" name="resolve_strategy" value="source_wins" checked>
								<span class="strategy-label">
									<strong><?php esc_html_e( 'Source Wins', 'wpmmcc-ats' ); ?></strong>
									<small><?php esc_html_e( 'Use source site values to overwrite target site', 'wpmmcc-ats' ); ?></small>
								</span>
							</label>
							<label class="strategy-option">
								<input type="radio" name="resolve_strategy" value="target_wins">
								<span class="strategy-label">
									<strong><?php esc_html_e( 'Target Wins', 'wpmmcc-ats' ); ?></strong>
									<small><?php esc_html_e( 'Keep target site values', 'wpmmcc-ats' ); ?></small>
								</span>
							</label>
							<label class="strategy-option">
								<input type="radio" name="resolve_strategy" value="newest_wins">
								<span class="strategy-label">
									<strong><?php esc_html_e( 'Newest Wins', 'wpmmcc-ats' ); ?></strong>
									<small><?php esc_html_e( 'Use the value with the most recent modification time', 'wpmmcc-ats' ); ?></small>
								</span>
							</label>
							<label class="strategy-option">
								<input type="radio" name="resolve_strategy" value="manual">
								<span class="strategy-label">
									<strong><?php esc_html_e( 'Manual', 'wpmmcc-ats' ); ?></strong>
									<small><?php esc_html_e( 'Select which value to use for each field', 'wpmmcc-ats' ); ?></small>
								</span>
							</label>
						</div>
					</div>
				</div>
				<div class="wptsall-modal-footer">
					<button type="button" class="button wptsall-modal-cancel"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></button>
					<button type="button" class="button button-primary" id="btn-confirm-resolve">
						<?php esc_html_e( 'Confirm Resolve', 'wpmmcc-ats' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}
