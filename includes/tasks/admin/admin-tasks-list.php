<?php
/**
 * WPTSALL Tasks List Admin Page
 *
 * v0.6.1 Rewrite:
 * - Task list grouped by site relation (monitoring tasks)
 * - "Add Task" creates monitoring task for selected relation
 * - Uses REST API for task operations
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Tasks\Services\Monitoring_Task_Service;

// Note: wptsall_get_task_parameters() and wptsall_save_task_parameters() are now
// defined in tasks.php for global availability. The functions are loaded before
// this admin file, so no need to redefine them here.

/**
 * Render task list page.
 */
/**
 * Render monitoring tasks tab (main view).
 *
 * Groups tasks by site relation.
 */
function wptsall_render_monitoring_tasks_tab() {
	// Get all site relations.
	$relations = Site_Relation_Service::get_all_relations();

	// Get relations without monitoring tasks for "add task" dropdown.
	$relations_without_task = array();
	$relations_with_task    = array();

	foreach ( $relations as $relation ) {
		$task = Monitoring_Task_Service::get_by_relation( $relation['id'] );
		if ( $task ) {
			$relations_with_task[] = array(
				'relation' => $relation,
				'task'     => $task,
			);
		} else {
			$relations_without_task[] = $relation;
		}
	}

	wptsall_log_debug(
		'tasks-admin',
		'Rendering monitoring tasks tab',
		array(
			'total_relations'      => count( $relations ),
			'with_task'            => count( $relations_with_task ),
			'without_task'         => count( $relations_without_task ),
		)
	);
	?>

	<!-- Add Task Modal -->
	<div id="wptsall-add-task-modal" style="display:none; margin: 20px 0;">
		<div class="card" style="max-width: 600px;">
			<h2><?php esc_html_e( 'Add Monitoring Task', 'wpmmcc-ats' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Select a site relation to create a monitoring task. Each site relation can only have one monitoring task.', 'wpmmcc-ats' ); ?></p>

			<?php if ( empty( $relations_without_task ) ) : ?>
				<p class="notice notice-warning" style="padding: 10px;">
					<?php esc_html_e( 'All site relations already have monitoring tasks, or no site relations are available.', 'wpmmcc-ats' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-sites' ) ); ?>">
						<?php esc_html_e( 'Go to Site Management to create site relations', 'wpmmcc-ats' ); ?>
					</a>
				</p>
			<?php else : ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="add-task-relation"><?php esc_html_e( 'Site Relation', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<select id="add-task-relation" class="regular-text">
								<option value=""><?php esc_html_e( '-- Select site relation --', 'wpmmcc-ats' ); ?></option>
								<?php foreach ( $relations_without_task as $rel ) : ?>
									<?php
									$source_label = sprintf( '%s #%s', __( 'Site', 'wpmmcc-ats' ), $rel['source_site_id'] );
									$target_label = ( 'virtual' === $rel['target_site_type'] )
										? sprintf( '%s (%s)', __( 'Virtual Site', 'wpmmcc-ats' ), $rel['target_site_id'] )
										: sprintf( '%s #%s', __( 'WP Subsite', 'wpmmcc-ats' ), $rel['target_site_id'] );
									$lang_info    = sprintf( '%s → %s', $rel['source_lang'] ?: 'auto', $rel['target_lang'] ?: 'auto' );
									$label        = sprintf( '%s → %s (%s)', $source_label, $target_label, $lang_info );
									?>
									<option value="<?php echo esc_attr( $rel['id'] ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="button" class="button button-primary" id="wptsall-create-task-btn">
						<?php esc_html_e( 'Create Monitoring Task', 'wpmmcc-ats' ); ?>
					</button>
					<button type="button" class="button" id="wptsall-cancel-add-task">
						<?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?>
					</button>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Monitoring Tasks List -->
	<?php if ( empty( $relations_with_task ) ) : ?>
		<div class="notice notice-info" style="margin-top: 20px;">
			<p>
				<?php esc_html_e( 'No monitoring tasks yet. Click "Add Monitoring Task" to create monitoring for a site relation.', 'wpmmcc-ats' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="wptsall-monitoring-tasks" style="margin-top: 20px;">
			<?php foreach ( $relations_with_task as $item ) : ?>
				<?php wptsall_render_monitoring_task_card( $item['relation'], $item['task'] ); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	// JavaScript for task operations (must stay in PHP mode after HTML branch).
	$monitor_script = WPTSALL_URL . 'assets/js/tasks-monitor.js';
	$monitor_path   = WPTSALL_PATH . 'assets/js/tasks-monitor.js';
	wp_enqueue_script( 'wptsall-tasks-monitor', $monitor_script, array( 'jquery' ), file_exists( $monitor_path ) ? (string) filemtime( $monitor_path ) : WPTSALL_VERSION, true );
	wp_localize_script(
		'wptsall-tasks-monitor',
		'wptsallTasksMonitor',
		array(
			'restUrl' => rest_url( 'wptsall/v2' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'please_select_a_site_relation' => __( 'Please select a site relation', 'wpmmcc-ats' ),
				'creating' => __( 'Creating...', 'wpmmcc-ats' ),
				'creation_failed' => __( 'Creation failed', 'wpmmcc-ats' ),
				'create_monitoring_task' => __( 'Create Monitoring Task', 'wpmmcc-ats' ),
				'request_failed' => __( 'Request failed', 'wpmmcc-ats' ),
				'operation_failed' => __( 'Operation failed', 'wpmmcc-ats' ),
				'executing' => __( 'Executing...', 'wpmmcc-ats' ),
				'execution_completed' => __( 'Execution completed', 'wpmmcc-ats' ),
				'checked' => __( 'Checked', 'wpmmcc-ats' ),
				'synced' => __( 'Synced', 'wpmmcc-ats' ),
				'error' => __( 'Error', 'wpmmcc-ats' ),
				'execution_failed' => __( 'Execution failed', 'wpmmcc-ats' ),
				'execute_now' => __( 'Execute Now', 'wpmmcc-ats' ),
				'are_you_sure_you_want_to_delete_this_monitoring_ta' => __( 'Are you sure you want to delete this monitoring task?', 'wpmmcc-ats' ),
				'delete_failed' => __( 'Delete failed', 'wpmmcc-ats' ),
				'scanning' => __( 'Scanning...', 'wpmmcc-ats' ),
				'language_pack_scan_completed' => __( 'Language pack scan completed', 'wpmmcc-ats' ),
				'templates' => __( 'Templates', 'wpmmcc-ats' ),
				'entries' => __( 'Entries', 'wpmmcc-ats' ),
				'scan_failed' => __( 'Scan failed', 'wpmmcc-ats' ),
				'translating' => __( 'Translating...', 'wpmmcc-ats' ),
				'translation_completed' => __( 'Translation completed', 'wpmmcc-ats' ),
				'target_language' => __( 'Target Language', 'wpmmcc-ats' ),
				'translated_entries' => __( 'Translated Entries', 'wpmmcc-ats' ),
				'translation_failed' => __( 'Translation failed', 'wpmmcc-ats' ),
			),
		)
	);
}

/**
 * Render a single monitoring task card.
 *
 * @param array $relation Relation data.
 * @param array $task     Task data.
 */
function wptsall_render_monitoring_task_card( $relation, $task ) {
	$status       = $task['status'] ?? 'unknown';
	$progress     = $task['progress'] ?? array();
	$last_check   = $task['last_check_at'] ?? null;
	$next_check   = $task['next_check_at'] ?? null;

	$status_colors = array(
		'active'    => '#46b450',
		'paused'    => '#f0b849',
		'completed' => '#0073aa',
		'error'     => '#dc3232',
	);
	$status_labels = array(
		'active'    => __( 'Running', 'wpmmcc-ats' ),
		'paused'    => __( 'Paused', 'wpmmcc-ats' ),
		'completed' => __( 'Completed', 'wpmmcc-ats' ),
		'error'     => __( 'Error', 'wpmmcc-ats' ),
	);

	$color = $status_colors[ $status ] ?? '#999';
	$label = $status_labels[ $status ] ?? $status;

	// Calculate progress percentage.
	$total_items  = $progress['total_items'] ?? 0;
	$synced_items = $progress['synced_items'] ?? 0;
	$percentage   = $progress['percentage'] ?? 0;
	if ( $total_items > 0 && $percentage == 0 ) {
		$percentage = round( ( $synced_items / $total_items ) * 100, 1 );
	}

	// Get relation display info.
	$source_label = sprintf( '%s #%s', __( 'Site', 'wpmmcc-ats' ), $relation['source_site_id'] );
	$target_label = ( 'virtual' === $relation['target_site_type'] )
		? sprintf( '%s (%s)', __( 'Virtual Site', 'wpmmcc-ats' ), $relation['target_site_id'] )
		: sprintf( '%s #%s', __( 'WP Subsite', 'wpmmcc-ats' ), $relation['target_site_id'] );
	?>
	<div class="card wptsall-task-card" style="margin-bottom: 15px; max-width: 100%;">
		<div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap;">
			<!-- Left: Relation Info -->
			<div style="flex: 1; min-width: 300px;">
				<h3 style="margin: 0 0 10px 0;">
					<?php echo esc_html( $source_label ); ?> → <?php echo esc_html( $target_label ); ?>
					<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; background: <?php echo esc_attr( $color ); ?>; color: #fff; font-size: 11px; font-weight: 600; margin-left: 10px;">
						<?php echo esc_html( $label ); ?>
					</span>
				</h3>
				<p style="margin: 5px 0; color: #666; font-size: 13px;">
					<strong><?php esc_html_e( 'Language', 'wpmmcc-ats' ); ?>:</strong>
					<?php echo esc_html( $relation['source_lang'] ?: 'auto' ); ?> →
					<?php echo esc_html( $relation['target_lang'] ?: 'auto' ); ?>
					&nbsp;|&nbsp;
					<strong><?php esc_html_e( 'Task ID', 'wpmmcc-ats' ); ?>:</strong> #<?php echo intval( $task['id'] ); ?>
				</p>

				<!-- Progress Bar -->
				<div style="margin: 15px 0;">
					<div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px;">
						<span><?php esc_html_e( 'Sync Progress', 'wpmmcc-ats' ); ?></span>
						<span><?php echo esc_html( number_format( $percentage, 1 ) ); ?>%</span>
					</div>
					<div style="background: #e0e0e0; border-radius: 3px; height: 8px; overflow: hidden;">
						<div style="background: <?php echo esc_attr( $color ); ?>; width: <?php echo absint( min( 100, $percentage ) ); ?>%; height: 100%; transition: width 0.3s;"></div>
					</div>
					<div style="display: flex; justify-content: space-between; font-size: 11px; color: #666; margin-top: 5px;">
						<span><?php
						/* translators: %d is the number of synced items */
						printf( esc_html__( 'Synced: %d', 'wpmmcc-ats' ), (int) $synced_items );
						?></span>
						<span><?php
						/* translators: %d is the total number of items */
						printf( esc_html__( 'Total: %d', 'wpmmcc-ats' ), (int) $total_items );
						?></span>
					</div>
				</div>

				<!-- Timing Info -->
				<div style="font-size: 12px; color: #666;">
					<?php if ( $last_check ) : ?>
						<span style="margin-right: 15px;">
							<strong><?php esc_html_e( 'Last Checked', 'wpmmcc-ats' ); ?>:</strong>
							<?php echo esc_html( $last_check ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $next_check && 'active' === $status ) : ?>
						<span>
							<strong><?php esc_html_e( 'Next Checked', 'wpmmcc-ats' ); ?>:</strong>
							<?php echo esc_html( $next_check ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Right: Actions -->
			<div style="display: flex; flex-direction: column; gap: 10px; align-items: flex-end; margin-top: 10px;">
				<!-- Primary Actions -->
				<div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
					<?php if ( 'active' === $status ) : ?>
						<button type="button" class="button wptsall-toggle-monitoring" data-relation-id="<?php echo esc_attr( $relation['id'] ); ?>" data-action="stop">
							<?php esc_html_e( 'Pause', 'wpmmcc-ats' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="button button-primary wptsall-toggle-monitoring" data-relation-id="<?php echo esc_attr( $relation['id'] ); ?>" data-action="start">
							<?php esc_html_e( 'Start', 'wpmmcc-ats' ); ?>
						</button>
					<?php endif; ?>

					<button type="button" class="button wptsall-trigger-check" data-task-id="<?php echo esc_attr( $task['id'] ); ?>">
						<?php esc_html_e( 'Execute Now', 'wpmmcc-ats' ); ?>
					</button>

					<a href="<?php echo esc_url( add_query_arg( 'log_for', $task['id'] ) ); ?>" class="button">
						<?php esc_html_e( 'Log', 'wpmmcc-ats' ); ?>
					</a>

					<button type="button" class="button wptsall-delete-task" data-task-id="<?php echo esc_attr( $task['id'] ); ?>" style="color: #a00;">
						<?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?>
					</button>
				</div>

				<!-- Language Pack Actions (P2 & P3) -->
				<div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; border-top: 1px solid #e0e0e0; padding-top: 10px;">
					<span style="font-size: 12px; color: #666; align-self: center;"><?php esc_html_e( 'Language Pack:', 'wpmmcc-ats' ); ?></span>
					<button type="button" class="button wptsall-scan-langpack" data-relation-id="<?php echo esc_attr( $relation['id'] ); ?>">
						<?php esc_html_e( 'Scan', 'wpmmcc-ats' ); ?>
					</button>
					<button type="button" class="button wptsall-translate-langpack" data-relation-id="<?php echo esc_attr( $relation['id'] ); ?>">
						<?php esc_html_e( 'Translate', 'wpmmcc-ats' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render job aggregate tab.
 *
 * @return void
 */
function wptsall_render_task_jobs_tab() {
	global $wpdb;

	$table = wptsall_table( 'task_jobs' );
	if ( function_exists( 'wptsall_task_jobs_table_exists' ) && ! wptsall_task_jobs_table_exists() ) {
		?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'Job aggregate table has not been created. Please access the tasks API once or reactivate the plugin.', 'wpmmcc-ats' ); ?>
		</p></div>
		<?php
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page = 20;
	$offset   = ( $page - 1 ) * $per_page;

	$where_parts = array( '1=1' );
	$where_args  = array();
	if ( '' !== $status ) {
		$where_parts[] = 'status = %s';
		$where_args[]  = $status;
	}
	if ( '' !== $job_id ) {
		$where_parts[] = 'job_id = %s';
		$where_args[]  = $job_id;
	}
	$where_sql = implode( ' AND ', $where_parts );

	$total = (int) wptsall_db_get_var(
		'SELECT COUNT(*) FROM %i WHERE ' . $where_sql,
		array_merge( array( $table ), $where_args )
	);

	$query_args = array_merge( array( $table ), $where_args, array( $per_page, $offset ) );
	$rows       = wptsall_db_get_results(
		'SELECT job_id, status, progress, task_total, pending_count, processing_count, retry_count, failed_count, completed_count, other_count, latest_status, latest_task_id, latest_at, updated_at
			 FROM %i
			 WHERE ' . $where_sql . '
			 ORDER BY updated_at DESC
			 LIMIT %d OFFSET %d',
		$query_args,
		ARRAY_A
	);

	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$base_url    = admin_url( 'admin.php?page=wptsall-tasks&tab=jobs' );
	$relations   = Site_Relation_Service::get_all_relations();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice params
	$job_bundle_created = isset( $_GET['job_bundle_created'] ) ? absint( $_GET['job_bundle_created'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice params
	$job_bundle_total = isset( $_GET['job_bundle_total'] ) ? absint( $_GET['job_bundle_total'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice params
	$job_bundle_id = isset( $_GET['job_bundle_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_bundle_id'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice params
	$job_bundle_preview = isset( $_GET['job_bundle_preview'] ) ? absint( $_GET['job_bundle_preview'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice params
	$job_retry_failed_subtasks = isset( $_GET['retried_failed_subtasks'] ) ? absint( $_GET['retried_failed_subtasks'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice params
	$job_retry_failed_subtasks_items = isset( $_GET['retried_failed_subtasks_items'] ) ? absint( $_GET['retried_failed_subtasks_items'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice params
	$job_retry_failed_subtasks_scope = isset( $_GET['retried_failed_subtasks_scope'] ) ? sanitize_text_field( wp_unslash( $_GET['retried_failed_subtasks_scope'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- detail panel state params
	$details_job_id = isset( $_GET['details_job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['details_job_id'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- detail panel state params
	$details_status = isset( $_GET['details_status'] ) ? sanitize_key( wp_unslash( $_GET['details_status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- detail panel state params
	$details_business_line = isset( $_GET['details_business_line'] ) ? sanitize_key( wp_unslash( $_GET['details_business_line'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- detail panel state params
	$details_task_type = isset( $_GET['details_task_type'] ) ? sanitize_key( wp_unslash( $_GET['details_task_type'] ) ) : '';
	if ( ! in_array( $details_status, array( '', 'pending', 'processing', 'retry', 'failed', 'completed' ), true ) ) {
		$details_status = '';
	}
	if ( ! in_array( $details_business_line, array( '', 'post_content', 'taxonomy_content', 'plugin_i18n', 'theme_i18n', 'custom_model' ), true ) ) {
		$details_business_line = '';
	}
	if ( ! in_array( $details_task_type, array( '', 'text', 'image', 'video', 'audio', 'document', 'mixed' ), true ) ) {
		$details_task_type = '';
	}
	?>
	<div style="margin-top: 20px;">
		<?php if ( $job_bundle_created > 0 ) : ?>
			<div class="notice notice-success inline" style="margin: 0 0 10px 0;">
				<p style="margin: 6px 0;">
					<?php
					printf(
						/* translators: 1: task total, 2: job id */
						esc_html__( 'Job task package created: %1$d tasks, Job ID %2$s', 'wpmmcc-ats' ),
						(int) $job_bundle_total,
						'' !== $job_bundle_id ? esc_html( $job_bundle_id ) : '-'
					);
					?>
				</p>
			</div>
		<?php elseif ( $job_bundle_preview > 0 ) : ?>
			<div class="notice notice-info inline" style="margin: 0 0 10px 0;">
				<p style="margin: 6px 0;">
					<?php
					printf(
						/* translators: %d: task total */
						esc_html__( 'Job task package preview completed: estimated %d tasks (not saved)', 'wpmmcc-ats' ),
						(int) $job_bundle_total
					);
					?>
				</p>
			</div>
		<?php elseif ( $job_retry_failed_subtasks > 0 ) : ?>
			<div class="notice notice-success inline" style="margin: 0 0 10px 0;">
				<p style="margin: 6px 0;">
					<?php
					printf(
						/* translators: 1: updated tasks, 2: updated subtasks */
						esc_html__( 'Job failed subtask retry completed: task %1$d, subtask %2$d', 'wpmmcc-ats' ),
						(int) $job_retry_failed_subtasks,
						(int) $job_retry_failed_subtasks_items
					);
					?>
					<?php if ( '' !== $job_retry_failed_subtasks_scope ) : ?>
						<br /><?php echo esc_html( $job_retry_failed_subtasks_scope ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="card" style="margin-bottom: 12px; padding: 12px 16px;">
			<h3 style="margin: 0 0 8px 0;"><?php esc_html_e( 'Create Job Task Package', 'wpmmcc-ats' ); ?></h3>
			<p style="margin: 0 0 10px 0; color: #666;">
				<?php esc_html_e( 'Generate multiple business line tasks under one Job per Site Relation (content tasks + language pack tasks). Preview before saving.', 'wpmmcc-ats' ); ?>
			</p>
			<div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
				<label for="wptsall-job-relation-id"><?php esc_html_e( 'Site Relation', 'wpmmcc-ats' ); ?></label>
				<select id="wptsall-job-relation-id" style="min-width: 220px;">
					<option value=""><?php esc_html_e( '-- Select Relation --', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( (array) $relations as $relation ) : ?>
						<?php
						$relation_id = absint( $relation['id'] ?? 0 );
						if ( $relation_id <= 0 ) {
							continue;
						}
						$source_label = sprintf( '#%d', absint( $relation['source_site_id'] ?? 0 ) );
						$target_label = sprintf(
							'%s:%s',
							sanitize_key( (string) ( $relation['target_site_type'] ?? 'wp' ) ),
							sanitize_text_field( (string) ( $relation['target_site_id'] ?? '' ) )
						);
						$lang_label = sprintf(
							'%s→%s',
							sanitize_text_field( (string) ( $relation['source_lang'] ?? 'auto' ) ),
							sanitize_text_field( (string) ( $relation['target_lang'] ?? 'auto' ) )
						);
						?>
						<option value="<?php echo esc_attr( $relation_id ); ?>">
							<?php echo esc_html( sprintf( 'ID:%d | %s -> %s | %s', $relation_id, $source_label, $target_label, $lang_label ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

					<label><input type="checkbox" id="wptsall-job-include-content" checked="checked" /> <?php esc_html_e( 'Content Tasks', 'wpmmcc-ats' ); ?></label>
					<label><input type="checkbox" id="wptsall-job-include-language-pack" /> <?php esc_html_e( 'Language Pack Tasks', 'wpmmcc-ats' ); ?></label>
					<label><input type="checkbox" id="wptsall-job-preview" /> <?php esc_html_e( 'Preview Only', 'wpmmcc-ats' ); ?></label>
					<label><input type="checkbox" id="wptsall-job-allow-existing" /> <?php esc_html_e( 'Allow Reuse Job ID', 'wpmmcc-ats' ); ?></label>

				<label for="wptsall-job-limit"><?php esc_html_e( 'Content Limit', 'wpmmcc-ats' ); ?></label>
				<input type="number" id="wptsall-job-limit" min="1" max="5000" value="100" style="width: 90px;" />

				<label for="wptsall-job-batch-size"><?php esc_html_e( 'Language Pack Batch', 'wpmmcc-ats' ); ?></label>
				<input type="number" id="wptsall-job-batch-size" min="1" max="500" value="50" style="width: 90px;" />

				<input type="text" id="wptsall-job-target-lang" placeholder="<?php esc_attr_e( 'Target Language (optional)', 'wpmmcc-ats' ); ?>" style="width: 130px;" />
				<input type="text" id="wptsall-job-custom-id" placeholder="<?php esc_attr_e( 'Custom Job ID (optional)', 'wpmmcc-ats' ); ?>" style="min-width: 200px;" />

				<button type="button" class="button button-primary" id="wptsall-create-job-bundle-btn">
					<?php esc_html_e( 'Create Task Package', 'wpmmcc-ats' ); ?>
				</button>
			</div>
			<div id="wptsall-job-create-result" style="margin-top: 8px; color: #555; font-size: 12px;"></div>
		</div>

		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="wptsall-tasks" />
			<input type="hidden" name="tab" value="jobs" />
			<input type="hidden" name="details_job_id" value="<?php echo esc_attr( $details_job_id ); ?>" />
			<input type="hidden" name="details_status" value="<?php echo esc_attr( $details_status ); ?>" />
			<input type="hidden" name="details_business_line" value="<?php echo esc_attr( $details_business_line ); ?>" />
			<input type="hidden" name="details_task_type" value="<?php echo esc_attr( $details_task_type ); ?>" />
			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( array( 'running', 'partial', 'completed', 'failed' ) as $st ) : ?>
					<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( $st ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="job_id" value="<?php echo esc_attr( $job_id ); ?>" placeholder="<?php esc_attr_e( 'Job ID', 'wpmmcc-ats' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wpmmcc-ats' ); ?></button>
			<a class="button button-secondary" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'wpmmcc-ats' ); ?></a>
		</form>
		<div style="margin: 0 0 12px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
			<label for="wptsall-jobs-batch-business-line"><?php esc_html_e( 'Batch Business Line', 'wpmmcc-ats' ); ?></label>
			<select id="wptsall-jobs-batch-business-line">
				<option value=""><?php esc_html_e( 'All Business Lines', 'wpmmcc-ats' ); ?></option>
				<option value="post_content">post_content</option>
				<option value="taxonomy_content">taxonomy_content</option>
				<option value="plugin_i18n">plugin_i18n</option>
				<option value="theme_i18n">theme_i18n</option>
				<option value="custom_model">custom_model</option>
			</select>
			<button type="button" class="button button-secondary" id="wptsall-jobs-batch-retry-failed-subtasks">
				<?php esc_html_e( 'Retry all failed subtasks for Jobs on this page', 'wpmmcc-ats' ); ?>
			</button>
			<span id="wptsall-jobs-batch-retry-status" style="font-size:12px; color:#666;"></span>
		</div>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job ID', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Task Count', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Latest Update', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No Job records.', 'wpmmcc-ats' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$row_job_id = sanitize_text_field( (string) ( $row['job_id'] ?? '' ) );
						$history_url = add_query_arg(
							array(
								'page'   => 'wptsall-tasks',
								'tab'    => 'history',
								'job_id' => $row_job_id,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td><code><?php echo esc_html( $row_job_id ); ?></code></td>
							<td><?php echo esc_html( sanitize_key( (string) ( $row['status'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['progress'] ?? 0 ) ); ?>%</td>
							<td>
								<?php
								printf(
									/* translators: 1: total, 2: completed, 3: failed, 4: retry, 5: processing, 6: pending */
									esc_html__( 'total=%1$d, completed=%2$d, failed=%3$d, retry=%4$d, processing=%5$d, pending=%6$d', 'wpmmcc-ats' ),
									(int) ( $row['task_total'] ?? 0 ),
									(int) ( $row['completed_count'] ?? 0 ),
									(int) ( $row['failed_count'] ?? 0 ),
									(int) ( $row['retry_count'] ?? 0 ),
									(int) ( $row['processing_count'] ?? 0 ),
									(int) ( $row['pending_count'] ?? 0 )
								);
								?>
							</td>
								<td>
									<?php echo esc_html( sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ) ); ?>
									<br />
									<small><?php echo esc_html( 'latest_status=' . sanitize_key( (string) ( $row['latest_status'] ?? '' ) ) . ', task=' . absint( $row['latest_task_id'] ?? 0 ) ); ?></small>
								</td>
									<td>
										<button
											type="button"
											class="button button-small wptsall-job-toggle-details"
											data-job-id="<?php echo esc_attr( $row_job_id ); ?>"
											data-history-url="<?php echo esc_url( $history_url ); ?>"
										>
											<?php esc_html_e( 'View Job Tasks', 'wpmmcc-ats' ); ?>
										</button>
										<a class="button button-small" href="<?php echo esc_url( $history_url ); ?>">
											<?php esc_html_e( 'View Task Details', 'wpmmcc-ats' ); ?>
										</a>
										<button
											type="button"
										class="button button-small wptsall-job-retry-failed-subtasks-jobtab"
										data-job-id="<?php echo esc_attr( $row_job_id ); ?>"
										style="margin-left: 6px;"
									>
											<?php esc_html_e( 'Retry Failed Subtasks', 'wpmmcc-ats' ); ?>
										</button>
									</td>
								</tr>
							<tr class="wptsall-job-details-row" data-job-id="<?php echo esc_attr( $row_job_id ); ?>" style="display:none;">
								<td colspan="6" style="background:#fcfcfd;">
									<div class="wptsall-job-details-panel" data-job-id="<?php echo esc_attr( $row_job_id ); ?>">
										<div class="wptsall-job-details-toolbar" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:8px;">
											<label>
												<?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?>
												<select class="wptsall-job-details-filter-status">
													<option value=""><?php esc_html_e( 'All', 'wpmmcc-ats' ); ?></option>
													<option value="pending">pending</option>
													<option value="processing">processing</option>
													<option value="retry">retry</option>
													<option value="failed">failed</option>
													<option value="completed">completed</option>
												</select>
											</label>
											<label>
												<?php esc_html_e( 'Business Line', 'wpmmcc-ats' ); ?>
												<select class="wptsall-job-details-filter-business-line">
													<option value=""><?php esc_html_e( 'All', 'wpmmcc-ats' ); ?></option>
													<option value="post_content">post_content</option>
													<option value="taxonomy_content">taxonomy_content</option>
													<option value="plugin_i18n">plugin_i18n</option>
													<option value="theme_i18n">theme_i18n</option>
													<option value="custom_model">custom_model</option>
												</select>
											</label>
											<label>
												<?php esc_html_e( 'Task Type', 'wpmmcc-ats' ); ?>
												<select class="wptsall-job-details-filter-task-type">
													<option value=""><?php esc_html_e( 'All', 'wpmmcc-ats' ); ?></option>
													<option value="text">text</option>
													<option value="image">image</option>
													<option value="video">video</option>
													<option value="audio">audio</option>
													<option value="document">document</option>
													<option value="mixed">mixed</option>
												</select>
											</label>
											<button type="button" class="button button-small wptsall-job-details-reload">
												<?php esc_html_e( 'Refresh Details', 'wpmmcc-ats' ); ?>
											</button>
											<button type="button" class="button button-small wptsall-job-details-retry-failed">
												<?php esc_html_e( 'Retry filtered failed subtasks', 'wpmmcc-ats' ); ?>
											</button>
											<span class="wptsall-job-details-meta" style="color:#666; font-size:12px;"></span>
										</div>
										<div class="wptsall-job-details-content" style="font-size:12px; color:#333;">
											<?php esc_html_e( 'Click "Refresh Details" to load tasks.', 'wpmmcc-ats' ); ?>
										</div>
									</div>
								</td>
							</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav" style="margin-top:10px;">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg(
									array(
										'page'   => 'wptsall-tasks',
										'tab'    => 'jobs',
										'status' => $status,
										'job_id' => $job_id,
										'details_job_id' => $details_job_id,
										'details_status' => $details_status,
										'details_business_line' => $details_business_line,
										'details_task_type' => $details_task_type,
										'paged'  => '%#%',
									),
									admin_url( 'admin.php' )
								),
								'format'    => '',
								'current'   => $page,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			</div>
			<?php endif; ?>
		</div>
	<?php
		// Job bundle create / details JS (must stay in PHP mode after HTML branch).
		$jobs_script = WPTSALL_URL . 'assets/js/tasks-jobs.js';
		$jobs_path   = WPTSALL_PATH . 'assets/js/tasks-jobs.js';
		wp_enqueue_script( 'wptsall-tasks-jobs', $jobs_script, array( 'jquery' ), file_exists( $jobs_path ) ? (string) filemtime( $jobs_path ) : WPTSALL_VERSION, true );
		wp_localize_script(
			'wptsall-tasks-jobs',
			'wptsallTasksJobs',
			array(
				'restUrl'             => rest_url( 'wptsall/v2' ),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'jobsBaseUrl'         => $base_url,
				'initialDetailsState' => array(
					'job_id'        => $details_job_id,
					'status'        => $details_status,
					'business_line' => $details_business_line,
					'task_type'     => $details_task_type,
				),
				'i18n'                => array(
					'collapse_job_tasks' => __( 'Collapse Job Tasks', 'wpmmcc-ats' ),
					'view_job_tasks' => __( 'View Job Tasks', 'wpmmcc-ats' ),
					'no_tasks_under_current_filter' => __( 'No tasks under current filter.', 'wpmmcc-ats' ),
					'retry_queue_item' => __( 'Retry queue item', 'wpmmcc-ats' ),
					'dismiss_latest' => __( 'Dismiss Latest', 'wpmmcc-ats' ),
					'clear_queue' => __( 'Clear Queue', 'wpmmcc-ats' ),
					'history_filter' => __( 'History Filter', 'wpmmcc-ats' ),
					'task_id' => __( 'Task ID', 'wpmmcc-ats' ),
					'status' => __( 'Status', 'wpmmcc-ats' ),
					'business_line' => __( 'Business Line', 'wpmmcc-ats' ),
					'type' => __( 'Type', 'wpmmcc-ats' ),
					'subtask_summary' => __( 'Subtask Summary', 'wpmmcc-ats' ),
					'manual_queue' => __( 'Manual Queue', 'wpmmcc-ats' ),
					'object' => __( 'Object', 'wpmmcc-ats' ),
					'retry' => __( 'Retry', 'wpmmcc-ats' ),
					'updated' => __( 'Updated', 'wpmmcc-ats' ),
					'actions' => __( 'Actions', 'wpmmcc-ats' ),
					'loading' => __( 'Loading...', 'wpmmcc-ats' ),
					'loading_task_details' => __( 'Loading task details...', 'wpmmcc-ats' ),
					'detail_response_format_error' => __( 'Detail response format error', 'wpmmcc-ats' ),
					'request_failed' => __( 'Request failed', 'wpmmcc-ats' ),
					'please_select_a_site_relation' => __( 'Please select a site relation', 'wpmmcc-ats' ),
					'please_select_at_least_one_task_scope_content_task' => __( 'Please select at least one task scope (content tasks or language pack tasks)', 'wpmmcc-ats' ),
					'processing' => __( 'Processing...', 'wpmmcc-ats' ),
					'failed_to_create_task_package' => __( 'Failed to create task package', 'wpmmcc-ats' ),
					'task_package_result' => __( 'Task Package Result', 'wpmmcc-ats' ),
					'preview_completed_not_saved' => __( 'Preview completed (not saved)', 'wpmmcc-ats' ),
					'task_package_created_but_task_count_is_0_please_ch' => __( 'Task package created, but task count is 0. Please check Models/Template configuration.', 'wpmmcc-ats' ),
					'no_processable_jobs_on_this_page' => __( 'No processable Jobs on this page.', 'wpmmcc-ats' ),
					'optional_note_applied_to_all_batch_requests' => __( 'Optional note (applied to all batch requests)', 'wpmmcc-ats' ),
					'batch_retry_failed_subtasks_for_jobs_on_this_page_' => __( 'Batch retry failed subtasks for Jobs on this page. Continue?', 'wpmmcc-ats' ),
					'batch_processing' => __( 'Batch processing...', 'wpmmcc-ats' ),
					'batch_execution_completed' => __( 'Batch execution completed', 'wpmmcc-ats' ),
					'batch_processing_2' => __( 'Batch processing', 'wpmmcc-ats' ),
					'missing_job_id_cannot_execute' => __( 'Missing job_id, cannot execute.', 'wpmmcc-ats' ),
					'optional_note_leave_blank_to_submit' => __( 'Optional note (leave blank to submit)', 'wpmmcc-ats' ),
					'failed_subtask_retry_failed' => __( 'Failed subtask retry failed', 'wpmmcc-ats' ),
					'execution_completed' => __( 'Execution completed', 'wpmmcc-ats' ),
					'invalid_manual_queue_parameters' => __( 'Invalid manual queue parameters', 'wpmmcc-ats' ),
					'clear_this_task_manual_queue_continue' => __( 'Clear this task manual queue. Continue?', 'wpmmcc-ats' ),
					'manual_queue_operation_failed' => __( 'manual queue Operation failed', 'wpmmcc-ats' ),
					'job_failedsubtaskretryfailed' => __( 'Job FailedSubtaskRetryFailed', 'wpmmcc-ats' ),
				),
			)
		);
	}
