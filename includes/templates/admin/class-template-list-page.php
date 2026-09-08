<?php
/**
 * Template List Page
 *
 * Language pack list management page
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
  * phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin list/render $_GET filters; state-changing handlers use check_admin_referer / check_ajax_referer.
 */
namespace WPTSALL\Templates\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template_List_Page class
 *
 * Provides language pack list management interface
 */
class Template_List_Page {

	/**
	 * Page slug
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wptsall-templates';

	/**
	 * Initialize
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Add menu page
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Language Packs', 'wpmmcc-ats' ),
			__( 'Language Packs', 'wpmmcc-ats' ),
			'manage_wptsall_translations',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts
	 *
	 * @param string $hook_suffix Page hook suffix
	 */
	public static function enqueue_scripts( $hook_suffix ) {
		if ( strpos( $hook_suffix, self::PAGE_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wptsall-templates-admin',
			WPTSALL_URL . 'assets/templates-admin.css',
			array(),
			WPTSALL_VERSION
		);

		wp_enqueue_script(
			'wptsall-templates-admin',
			WPTSALL_URL . 'assets/templates-admin.js',
			array( 'jquery', 'wp-api-fetch' ),
			WPTSALL_VERSION,
			true
		);

		wp_localize_script( 'wptsall-templates-admin', 'wptsallTemplates', array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'apiBase'  => rest_url( 'wptsall/v2' ),
			'strings'  => array(
				'scanning'    => __( 'Scanning...', 'wpmmcc-ats' ),
				'scanSuccess' => __( 'Scan completed', 'wpmmcc-ats' ),
				'scanFailed'  => __( 'Scan failed', 'wpmmcc-ats' ),
				'confirm'     => __( 'Are you sure you want to perform this action?', 'wpmmcc-ats' ),
				'deleting'    => __( 'Deleting...', 'wpmmcc-ats' ),
			),
		) );
	}

	/**
	 * Render page
	 */
	public static function render_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

		switch ( $action ) {
			case 'edit':
			case 'view':
				Template_Edit_Page::render();
				break;
			default:
				self::render_list_page();
				break;
		}
	}

	/**
	 * Render list page
	 */
	private static function render_list_page() {
		// Get filter parameters
		$relation_id = isset( $_GET['relation_id'] ) ? absint( $_GET['relation_id'] ) : 0;
		$source_type = isset( $_GET['source_type'] ) ? sanitize_key( $_GET['source_type'] ) : '';
		$status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$page        = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		// Get data
		$result = Template_Service::get_all( array(
			'relation_id' => $relation_id,
			'source_type' => $source_type,
			'status'      => $status,
			'page'        => $page,
			'per_page'    => 20,
		) );

		// Get site relations list (for filter dropdown)
		$relations = Site_Relation_Service::get_all_relations();

		$actions = array();
		if ( $relation_id ) {
			$actions[] = array(
				'label' => __( 'Scan Language Packs', 'wpmmcc-ats' ),
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=scan&relation_id=' . $relation_id ),
				'class' => 'button button-primary wptsall-scan-btn',
				'attrs' => array(
					'data-relation-id' => $relation_id,
				),
			);
		}

		Admin_Page_Helper::render_header(
			__( 'Language Packs', 'wpmmcc-ats' ),
			'templates',
			$actions
		);
		?>
				<div class="wptsall-page-content">
					<?php self::render_filters( $relations, $relation_id, $source_type, $status ); ?>
					<?php self::render_i18n_scan_notice( $relation_id, $result['items'] ?? array() ); ?>

			<?php if ( empty( $result['items'] ) ) : ?>
				<div class="wptsall-no-items">
					<p><?php esc_html_e( 'No language pack data.', 'wpmmcc-ats' ); ?></p>
					<?php if ( ! $relation_id ) : ?>
						<p><?php esc_html_e( 'Please select a site relation first, then click the "Scan Language Packs" button.', 'wpmmcc-ats' ); ?></p>
					<?php else : ?>
						<p>
							<a href="#" class="button wptsall-scan-btn" data-relation-id="<?php echo esc_attr( $relation_id ); ?>">
								<?php esc_html_e( 'Scan Language Packs', 'wpmmcc-ats' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php self::render_table( $result['items'] ); ?>
				<?php self::render_pagination( $result['total'], $result['pages'], $page ); ?>
			<?php endif; ?>
				</div><!-- .wptsall-page-content -->

		<?php Admin_Page_Helper::render_footer(); ?>

		<div id="wptsall-scan-modal" class="wptsall-modal" style="display:none;">
			<div class="wptsall-modal-content">
				<span class="wptsall-modal-close">&times;</span>
				<h2><?php esc_html_e( 'Scan Language Packs', 'wpmmcc-ats' ); ?></h2>
				<div class="wptsall-scan-progress">
					<div class="wptsall-progress-bar">
						<div class="wptsall-progress-fill"></div>
					</div>
					<p class="wptsall-progress-text"><?php esc_html_e( 'Preparing to scan...', 'wpmmcc-ats' ); ?></p>
				</div>
				<div class="wptsall-scan-results" style="display:none;">
					<h3><?php esc_html_e( 'Scan Results', 'wpmmcc-ats' ); ?></h3>
					<div class="wptsall-results-list"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render i18n scan status notice
	 *
	 * @since 1.2.0
	 * @param int   $relation_id Current relation ID
	 * @param array $templates   Current page template list
	 */
	private static function render_i18n_scan_notice( $relation_id, $templates ) {
		if ( ! $relation_id ) {
			return;
		}

		// Only show for virtual target relations.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		if ( ! $relation || 'virtual' !== ( $relation['target_site_type'] ?? '' ) ) {
			return;
		}

		if ( ! class_exists( '\\WPTSALL\\Tasks\\Services\\Monitoring_Task_Service' ) ) {
			return;
		}

		$scan = \WPTSALL\Tasks\Services\Monitoring_Task_Service::get_i18n_scan_status( $relation_id );
		$status = $scan['status'] ?? 'not_started';

		$notice_type = 'info';
		$message     = '';

		switch ( $status ) {
			case 'not_started':
				$message = __( 'Language packs for this site relation have not been scanned yet. Click the "Scan Language Packs" button to start scanning.', 'wpmmcc-ats' );
				break;

			case 'phase1_scanning':
			case 'phase2_creating':
				$notice_type = 'info';
				$message = __( 'Language pack scanning is in progress. Please refresh the page shortly to see results.', 'wpmmcc-ats' );
				break;

			case 'phase1_done':
				$notice_type = 'info';
				$message = __( 'Language pack scanning is in progress (creating translation tasks). Please refresh the page shortly to see results.', 'wpmmcc-ats' );
				break;

			case 'error':
				$retry_count = (int) ( $scan['retry_count'] ?? 0 );
				$error_msg   = esc_html( $scan['error'] ?? __( 'Unknown error', 'wpmmcc-ats' ) );
				if ( $retry_count < 3 ) {
					$notice_type = 'warning';
					/* translators: %s: error message */
					$message = sprintf( __( 'Language pack scan error: %s. The system will retry automatically. You can also click "Scan Language Packs" to retry manually.', 'wpmmcc-ats' ), $error_msg );
				} else {
					$notice_type = 'error';
					/* translators: %s: error message */
					$message = sprintf( __( 'Language pack scan failed multiple times: %s. Please click "Scan Language Packs" to retry manually.', 'wpmmcc-ats' ), $error_msg );
				}
				break;

			case 'completed':
				if ( empty( $templates ) ) {
					$notice_type = 'warning';
					$message = __( 'Scan completed but no translatable language packs found. Please check if the plugins/themes contain .pot files.', 'wpmmcc-ats' );
				}
				// If templates exist, don't show notice (normal state).
				break;
		}

		if ( empty( $message ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s inline"><p>%s</p></div>',
			esc_attr( $notice_type ),
			wp_kses_post( $message )
		);
	}

	/**
	 * Render filters
	 *
	 * @param array  $relations    Site relations list
	 * @param int    $relation_id  Current relation ID
	 * @param string $source_type  Source type
	 * @param string $status       Status
	 */
	private static function render_filters( $relations, $relation_id, $source_type, $status ) {
		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wptsall-filters">
			<form method="get" action="">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">

				<select name="relation_id" id="filter-relation">
					<option value=""><?php esc_html_e( 'All Site Relations', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( $relations as $relation ) : ?>
						<option value="<?php echo esc_attr( $relation['id'] ); ?>" <?php selected( $relation_id, $relation['id'] ); ?>>
							<?php
							printf(
								'#%d: %s → %s / %s',
								intval( $relation['id'] ),
								esc_html( $relation['source_site_id'] ),
								esc_html( $relation['target_site_id'] ),
								esc_html( $relation['template'] )
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="source_type" id="filter-source-type">
					<option value=""><?php esc_html_e( 'All Types', 'wpmmcc-ats' ); ?></option>
					<option value="theme" <?php selected( $source_type, 'theme' ); ?>><?php esc_html_e( 'Theme', 'wpmmcc-ats' ); ?></option>
					<option value="plugin" <?php selected( $source_type, 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'wpmmcc-ats' ); ?></option>
				</select>

				<select name="status" id="filter-status">
					<option value=""><?php esc_html_e( 'All Statuses', 'wpmmcc-ats' ); ?></option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending Scan', 'wpmmcc-ats' ); ?></option>
					<option value="scanned" <?php selected( $status, 'scanned' ); ?>><?php esc_html_e( 'Scanned', 'wpmmcc-ats' ); ?></option>
					<option value="translating" <?php selected( $status, 'translating' ); ?>><?php esc_html_e( 'Translating', 'wpmmcc-ats' ); ?></option>
					<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'wpmmcc-ats' ); ?></option>
				</select>

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wpmmcc-ats' ); ?></button>

				<?php if ( $relation_id || $source_type || $status ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'wpmmcc-ats' ); ?></a>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render table
	 *
	 * @param array $templates Template list
	 */
	private static function render_table( $templates ) {
		?>
		<table class="wp-list-table widefat fixed striped wptsall-templates-table">
			<thead>
				<tr>
					<th class="column-relation"><?php esc_html_e( 'Site Relation', 'wpmmcc-ats' ); ?></th>
					<th class="column-type"><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></th>
					<th class="column-domain"><?php esc_html_e( 'Language Pack', 'wpmmcc-ats' ); ?></th>
					<th class="column-version"><?php esc_html_e( 'Version', 'wpmmcc-ats' ); ?></th>
					<th class="column-progress"><?php esc_html_e( 'Progress', 'wpmmcc-ats' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $template ) : ?>
					<?php self::render_table_row( $template ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render table row
	 *
	 * @param array $template Template data
	 */
	private static function render_table_row( $template ) {
		$edit_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . $template['id'] );
		$progress   = 0;
		$total      = (int) $template['total_entries'];
		$translated = (int) $template['translated_entries'];

		if ( $total > 0 ) {
			$progress = round( ( $translated / $total ) * 100, 1 );
		}

		$status_labels = array(
			'pending'     => __( 'Pending Scan', 'wpmmcc-ats' ),
			'scanned'     => __( 'Scanned', 'wpmmcc-ats' ),
			'translating' => __( 'Translating', 'wpmmcc-ats' ),
			'completed'   => __( 'Completed', 'wpmmcc-ats' ),
		);

		$type_labels = array(
			'theme'  => __( 'Theme', 'wpmmcc-ats' ),
			'plugin' => __( 'Plugin', 'wpmmcc-ats' ),
		);
		?>
		<tr data-template-id="<?php echo esc_attr( $template['id'] ); ?>">
			<td class="column-relation">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-sites&action=edit&id=' . $template['relation_id'] ) ); ?>">
					#<?php echo esc_html( $template['relation_id'] ); ?>
				</a>
			</td>
			<td class="column-type">
				<span class="wptsall-type wptsall-type-<?php echo esc_attr( $template['source_type'] ); ?>">
					<?php echo esc_html( $type_labels[ $template['source_type'] ] ?? $template['source_type'] ); ?>
				</span>
			</td>
			<td class="column-domain">
				<strong>
					<a href="<?php echo esc_url( $edit_url ); ?>">
						<?php echo esc_html( $template['source_name'] ?: $template['text_domain'] ); ?>
					</a>
				</strong>
				<div class="row-actions">
					<span class="text-domain"><?php echo esc_html( $template['text_domain'] ); ?></span>
				</div>
			</td>
			<td class="column-version">
				<?php echo esc_html( $template['source_version'] ?: '-' ); ?>
			</td>
			<td class="column-progress">
				<div class="wptsall-progress-bar-mini">
					<div class="wptsall-progress-fill" style="width: <?php echo esc_attr( $progress ); ?>%;"></div>
				</div>
				<span class="wptsall-progress-text">
					<?php
					printf(
						/* translators: 1: translated count, 2: total count, 3: percentage */
						esc_html__( '%1$d / %2$d (%3$s%%)', 'wpmmcc-ats' ),
						intval( $translated ),
						intval( $total ),
						esc_html( $progress )
					);
					?>
				</span>
			</td>
			<td class="column-status">
				<span class="wptsall-status wptsall-status-<?php echo esc_attr( $template['status'] ); ?>">
					<?php echo esc_html( $status_labels[ $template['status'] ] ?? $template['status'] ); ?>
				</span>
			</td>
			<td class="column-actions">
				<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
					<?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?>
				</a>
				<button type="button" class="button button-small wptsall-rescan-btn" data-id="<?php echo esc_attr( $template['id'] ); ?>">
					<?php esc_html_e( 'Rescan', 'wpmmcc-ats' ); ?>
				</button>
				<button type="button" class="button button-small button-link-delete wptsall-delete-btn" data-id="<?php echo esc_attr( $template['id'] ); ?>">
					<?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render pagination
	 *
	 * @param int $total   Total count
	 * @param int $pages   Total pages
	 * @param int $current Current page
	 */
	private static function render_pagination( $total, $pages, $current ) {
		if ( $pages <= 1 ) {
			return;
		}

		$base_url = remove_query_arg( 'paged' );
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of items */
						esc_html( _n( '%s item', '%s items', $total, 'wpmmcc-ats' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<span class="pagination-links">
					<?php if ( $current > 1 ) : ?>
						<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'First page', 'wpmmcc-ats' ); ?></span>
							<span aria-hidden="true">&laquo;</span>
						</a>
						<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current - 1, $base_url ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'wpmmcc-ats' ); ?></span>
							<span aria-hidden="true">&lsaquo;</span>
						</a>
					<?php endif; ?>

					<span class="paging-input">
						<span class="current-page"><?php echo esc_html( $current ); ?></span>
						<span class="tablenav-paging-text">
							<?php esc_html_e( '/', 'wpmmcc-ats' ); ?>
							<span class="total-pages"><?php echo esc_html( $pages ); ?></span>
						</span>
					</span>

					<?php if ( $current < $pages ) : ?>
						<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current + 1, $base_url ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'wpmmcc-ats' ); ?></span>
							<span aria-hidden="true">&rsaquo;</span>
						</a>
						<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pages, $base_url ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'wpmmcc-ats' ); ?></span>
							<span aria-hidden="true">&raquo;</span>
						</a>
					<?php endif; ?>
				</span>
			</div>
		</div>
		<?php
	}
}
