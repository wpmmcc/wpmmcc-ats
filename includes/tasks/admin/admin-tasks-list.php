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

/**
 * Render task history tab (legacy task list).
 */
function wptsall_render_task_history_tab() {
	// Load WP_List_Table class.
	require_once dirname( __FILE__ ) . '/class-wptsall-tasks-list-table.php';

	// Handle bulk actions.
	wptsall_handle_task_bulk_actions();
	// Handle one-click history actions.
	wptsall_handle_task_history_actions();

	// Create list table instance.
	$list_table = new \WPTSALL_Tasks_List_Table();
	$list_table->prepare_items();
	// Read-only admin list filters (GET). No state change — nonce not required.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$current_filters = array(
		'status'         => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		'site_id'        => isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0,
		'template'       => isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '',
		'job_id'         => isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '',
		'business_line'  => isset( $_GET['business_line'] ) ? sanitize_key( wp_unslash( $_GET['business_line'] ) ) : '',
		'subtask_status' => isset( $_GET['subtask_status'] ) ? sanitize_key( wp_unslash( $_GET['subtask_status'] ) ) : '',
		's'              => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$action_base_args = array(
		'page'                => 'wptsall-tasks',
		'tab'                 => 'history',
	);
	foreach ( $current_filters as $key => $value ) {
		if ( '' === $value || 0 === $value ) {
			continue;
		}
		$action_base_args[ $key ] = $value;
	}

	$retry_failed_url = wp_nonce_url(
		add_query_arg(
			array_merge(
				$action_base_args,
				array(
					'wptsall_task_action' => 'retry_failed',
				)
			),
			admin_url( 'admin.php' )
		),
		'wptsall_retry_failed_tasks'
	);
	$retry_failed_subtasks_url = wp_nonce_url(
		add_query_arg(
			array_merge(
				$action_base_args,
				array(
					'wptsall_task_action' => 'retry_failed_subtasks',
				)
			),
			admin_url( 'admin.php' )
		),
		'wptsall_retry_failed_subtasks'
	);

	?>
	<div style="margin-top: 20px;">
		<?php
		?>
		<div style="margin-bottom: 12px;">
			<a href="<?php echo esc_url( $retry_failed_url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Retry all failed tasks (current filter)', 'wpmmcc-ats' ); ?>
			</a>
			<a href="<?php echo esc_url( $retry_failed_subtasks_url ); ?>" class="button button-secondary" style="margin-left: 8px;">
				<?php esc_html_e( 'Retry all failed subtasks (current filter)', 'wpmmcc-ats' ); ?>
			</a>
			<?php if ( '' !== (string) $current_filters['job_id'] ) : ?>
				<button
					type="button"
					class="button button-secondary wptsall-job-retry-failed-subtasks"
					data-job-id="<?php echo esc_attr( (string) $current_filters['job_id'] ); ?>"
					data-business-line="<?php echo esc_attr( (string) $current_filters['business_line'] ); ?>"
					style="margin-left: 8px;"
				>
					<?php esc_html_e( 'Retry current Job failed subtasks', 'wpmmcc-ats' ); ?>
				</button>
			<?php endif; ?>
		</div>
		<div class="notice notice-info inline" style="margin:8px 0 12px 0;">
			<p style="margin:6px 0;">
				<?php esc_html_e( 'Job / Task / Subtask three-level view enabled: filter by Job, business line, subtask status, and perform Retry/Skip on subtask rows.', 'wpmmcc-ats' ); ?>
			</p>
		</div>
		<?php
		// Display status summary.
		$list_table->display_status_summary();

		// Display any notices.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['deleted'] ) && check_admin_referer( 'wptsall_bulk_action', '_wpnonce' ) ) {
			$deleted = absint( $_GET['deleted'] );
			echo '<div class="notice notice-success is-dismissible"><p>';
			/* translators: %d: Number of tasks deleted */
			printf( esc_html__( 'Deleted %d task(s).', 'wpmmcc-ats' ), (int) $deleted );
			echo '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['retried'] ) && check_admin_referer( 'wptsall_bulk_action', '_wpnonce' ) ) {
			$retried = absint( $_GET['retried'] );
			echo '<div class="notice notice-success is-dismissible"><p>';
			/* translators: %d: Number of tasks retried */
			printf( esc_html__( 'Marked %d task(s) for retry.', 'wpmmcc-ats' ), (int) $retried );
			echo '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['processed'] ) && check_admin_referer( 'wptsall_bulk_action', '_wpnonce' ) ) {
			$processed = absint( $_GET['processed'] );
			echo '<div class="notice notice-success is-dismissible"><p>';
			/* translators: %d: Number of tasks marked pending */
			printf( esc_html__( 'Marked %d task(s) as pending.', 'wpmmcc-ats' ), (int) $processed );
			echo '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['retried_failed'] ) ) {
			$retried_failed = absint( $_GET['retried_failed'] );
			$retried_failed_scope = isset( $_GET['retried_failed_scope'] )
				? sanitize_text_field( wp_unslash( $_GET['retried_failed_scope'] ) )
				: '';
			$retried_failed_tasks = isset( $_GET['retried_failed_tasks'] )
				? sanitize_text_field( wp_unslash( $_GET['retried_failed_tasks'] ) )
				: '';
			echo '<div class="notice notice-success is-dismissible"><p>';
			/* translators: %d: Number of failed tasks retried */
			printf( esc_html__( 'Batch retried %d failed/retrying task(s).', 'wpmmcc-ats' ), (int) $retried_failed );
			if ( '' !== $retried_failed_scope ) {
				echo '<br />' . esc_html__( 'Scope:', 'wpmmcc-ats' ) . ' ' . esc_html( $retried_failed_scope );
			}
			if ( '' !== $retried_failed_tasks ) {
				echo '<br />' . esc_html__( 'Task ID sample:', 'wpmmcc-ats' ) . ' #' . esc_html( $retried_failed_tasks );
			}
			echo '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['retried_failed_subtasks'] ) ) {
			$retried_failed_subtasks = absint( $_GET['retried_failed_subtasks'] );
			$retried_failed_subtasks_items = isset( $_GET['retried_failed_subtasks_items'] )
				? absint( $_GET['retried_failed_subtasks_items'] )
				: 0;
			$retried_scope = isset( $_GET['retried_failed_subtasks_scope'] )
				? sanitize_text_field( wp_unslash( $_GET['retried_failed_subtasks_scope'] ) )
				: '';
			$retried_task_samples = isset( $_GET['retried_failed_subtasks_tasks'] )
				? sanitize_text_field( wp_unslash( $_GET['retried_failed_subtasks_tasks'] ) )
				: '';
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: task count, 2: subtask count */
				esc_html__( 'Batch retried %2$d failed subtask(s) across %1$d task(s).', 'wpmmcc-ats' ),
				(int) $retried_failed_subtasks,
				(int) $retried_failed_subtasks_items
			);
			if ( '' !== $retried_scope ) {
				echo '<br />' . esc_html__( 'Scope:', 'wpmmcc-ats' ) . ' ' . esc_html( $retried_scope );
			}
			if ( '' !== $retried_task_samples ) {
				echo '<br />' . esc_html__( 'Task ID sample:', 'wpmmcc-ats' ) . ' #' . esc_html( $retried_task_samples );
			}
			echo '</p></div>';
		}
		?>
		<?php wptsall_render_manual_subtask_action_history(); ?>

		<form id="wptsall-tasks-filter" method="get">
			<input type="hidden" name="page" value="wptsall-tasks" />
			<input type="hidden" name="tab" value="history" />
			<?php
			$list_table->search_box( __( 'Search Tasks', 'wpmmcc-ats' ), 'task' );
			$list_table->display();
			?>
		</form>
	</div>
	<?php
	// Subtask intervention JS (must stay in PHP mode after HTML branch).
	$sub_script = WPTSALL_URL . 'assets/js/tasks-subtasks.js';
	$sub_path   = WPTSALL_PATH . 'assets/js/tasks-subtasks.js';
	wp_enqueue_script( 'wptsall-tasks-subtasks', $sub_script, array( 'jquery' ), file_exists( $sub_path ) ? (string) filemtime( $sub_path ) : WPTSALL_VERSION, true );
	wp_localize_script(
		'wptsall-tasks-subtasks',
		'wptsallTasksSubtasks',
		array(
			'restUrl' => rest_url( 'wptsall/v2' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'invalid_subtask_parameters' => __( 'Invalid subtask parameters', 'wpmmcc-ats' ),
				'optional_note_leave_blank_to_submit' => __( 'Optional note (leave blank to submit)', 'wpmmcc-ats' ),
				'retry_subtask' => __( 'Retry subtask', 'wpmmcc-ats' ),
				'skip_subtask' => __( 'Skip subtask', 'wpmmcc-ats' ),
				'subtask_operation_failed' => __( 'Subtask operation failed', 'wpmmcc-ats' ),
				'request_failed' => __( 'Request failed', 'wpmmcc-ats' ),
				'invalid_manual_queue_parameters' => __( 'Invalid manual queue parameters', 'wpmmcc-ats' ),
				'clear_this_task_manual_queue_continue' => __( 'Clear this task manual queue. Continue?', 'wpmmcc-ats' ),
				'retry_queue_item' => __( 'Retry queue item', 'wpmmcc-ats' ),
				'dismiss_queue_item' => __( 'Dismiss Queue Item', 'wpmmcc-ats' ),
				'clear_queue' => __( 'Clear Queue', 'wpmmcc-ats' ),
				'manual_queue_operation_failed' => __( 'manual queue Operation failed', 'wpmmcc-ats' ),
				'missing_job_id_cannot_execute' => __( 'Missing job_id, cannot execute.', 'wpmmcc-ats' ),
				'processing' => __( 'Processing', 'wpmmcc-ats' ),
				'job_level_failed_subtask_retry_failed' => __( 'Job-level failed subtask retry failed', 'wpmmcc-ats' ),
			),
		)
	);
}

/**
 * Render manual subtask action history panel.
 *
 * @return void
 */
function wptsall_render_manual_subtask_action_history() {
	global $wpdb;
	$table = wptsall_table( 'tasks' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$filter_task_id = isset( $_GET['manual_log_task_id'] ) ? absint( $_GET['manual_log_task_id'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$filter_action = isset( $_GET['manual_log_action'] ) ? sanitize_key( wp_unslash( $_GET['manual_log_action'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$filter_date_from = isset( $_GET['manual_log_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['manual_log_date_from'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$filter_date_to = isset( $_GET['manual_log_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['manual_log_date_to'] ) ) : '';

	if ( ! in_array( $filter_action, array( '', 'retry', 'skip' ), true ) ) {
		$filter_action = '';
	}
	$valid_date_regex = '/^\d{4}-\d{2}-\d{2}$/';
	$has_from = '' !== $filter_date_from && 1 === preg_match( $valid_date_regex, $filter_date_from );
	$has_to   = '' !== $filter_date_to && 1 === preg_match( $valid_date_regex, $filter_date_to );

	$where_conditions = array( 'meta LIKE %s' );
	$params = array(
		$table,
		'%"manual_subtask_actions"%'
	);
	if ( $filter_task_id > 0 ) {
		$where_conditions[] = 'id = %d';
		$params[] = $filter_task_id;
	}
	if ( '' !== $filter_action ) {
		$where_conditions[] = 'meta LIKE %s';
		$params[] = '%"action":"' . $wpdb->esc_like( $filter_action ) . '"%';
	}
	if ( $has_from ) {
		$where_conditions[] = 'updated_at >= %s';
		$params[] = $filter_date_from . ' 00:00:00';
	}
	if ( $has_to ) {
		$where_conditions[] = 'updated_at <= %s';
		$params[] = $filter_date_to . ' 23:59:59';
	}
	$where_sql = implode( ' AND ', $where_conditions );
	$params[]  = 300;

	$task_rows = wptsall_db_get_results(
		'SELECT id, status, updated_at, meta FROM %i WHERE ' . $where_sql . ' ORDER BY updated_at DESC LIMIT %d',
		$params,
		ARRAY_A
	);
	$history_rows = array();
	$from_ts = $has_from ? strtotime( $filter_date_from . ' 00:00:00' ) : 0;
	$to_ts   = $has_to ? strtotime( $filter_date_to . ' 23:59:59' ) : 0;

	foreach ( (array) $task_rows as $task_row ) {
		$task_id = absint( $task_row['id'] ?? 0 );
		$meta = json_decode( (string) ( $task_row['meta'] ?? '' ), true );
		if ( ! is_array( $meta ) ) {
			continue;
		}
		$actions = is_array( $meta['manual_subtask_actions'] ?? null ) ? $meta['manual_subtask_actions'] : array();
		foreach ( $actions as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$action = sanitize_key( (string) ( $entry['action'] ?? '' ) );
			if ( '' !== $filter_action && $action !== $filter_action ) {
				continue;
			}
			$at = sanitize_text_field( (string) ( $entry['at'] ?? '' ) );
			$at_ts = $at ? strtotime( $at ) : 0;
			if ( $from_ts > 0 && $at_ts > 0 && $at_ts < $from_ts ) {
				continue;
			}
			if ( $to_ts > 0 && $at_ts > 0 && $at_ts > $to_ts ) {
				continue;
			}

			$history_rows[] = array(
				'task_id'    => $task_id,
				'action'     => $action,
				'type'       => sanitize_key( (string) ( $entry['type'] ?? 'text' ) ),
				'key'        => sanitize_text_field( (string) ( $entry['key'] ?? '' ) ),
				'note'       => sanitize_text_field( (string) ( $entry['note'] ?? '' ) ),
				'at'         => $at,
				'user_id'    => absint( $entry['user_id'] ?? 0 ),
				'user_login' => sanitize_text_field( (string) ( $entry['user_login'] ?? '' ) ),
			);
		}
	}

	usort(
		$history_rows,
		function( $a, $b ) {
			$ta = isset( $a['at'] ) ? strtotime( (string) $a['at'] ) : 0;
			$tb = isset( $b['at'] ) ? strtotime( (string) $b['at'] ) : 0;
			if ( $ta === $tb ) {
				return intval( $b['task_id'] ?? 0 ) <=> intval( $a['task_id'] ?? 0 );
			}
			return $tb <=> $ta;
		}
	);
	if ( count( $history_rows ) > 200 ) {
		$history_rows = array_slice( $history_rows, 0, 200 );
	}

	$summary = array(
		'total' => count( $history_rows ),
		'retry' => 0,
		'skip'  => 0,
	);
	foreach ( $history_rows as $row ) {
		$act = sanitize_key( (string) ( $row['action'] ?? '' ) );
		if ( 'retry' === $act ) {
			$summary['retry']++;
		} elseif ( 'skip' === $act ) {
			$summary['skip']++;
		}
	}
	?>
	<div class="card" style="max-width:100%; margin: 14px 0 18px 0;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Subtask Manual Intervention History', 'wpmmcc-ats' ); ?></h2>
		<form method="get" style="margin-bottom:10px;">
			<input type="hidden" name="page" value="wptsall-tasks" />
			<input type="hidden" name="tab" value="history" />
			<?php
			// Preserve read-only list filters when re-rendering the history form (GET only).
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			foreach ( array( 'status', 'site_id', 'template', 'job_id', 'business_line', 'subtask_status', 's' ) as $filter_key ) {
				if ( ! isset( $_GET[ $filter_key ] ) ) {
					continue;
				}
				$value = sanitize_text_field( wp_unslash( $_GET[ $filter_key ] ) );
				if ( '' === $value ) {
					continue;
				}
				echo '<input type="hidden" name="' . esc_attr( $filter_key ) . '" value="' . esc_attr( $value ) . '" />';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<label style="margin-right:8px;">
				<?php esc_html_e( 'Task ID', 'wpmmcc-ats' ); ?>
				<input type="number" min="1" name="manual_log_task_id" value="<?php echo $filter_task_id > 0 ? esc_attr( $filter_task_id ) : ''; ?>" style="width:110px;" />
			</label>
			<label style="margin-right:8px;">
				<?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?>
				<select name="manual_log_action">
					<option value=""><?php esc_html_e( 'All', 'wpmmcc-ats' ); ?></option>
					<option value="retry" <?php selected( $filter_action, 'retry' ); ?>>retry</option>
					<option value="skip" <?php selected( $filter_action, 'skip' ); ?>>skip</option>
				</select>
			</label>
			<label style="margin-right:8px;">
				<?php esc_html_e( 'Start Date', 'wpmmcc-ats' ); ?>
				<input type="date" name="manual_log_date_from" value="<?php echo esc_attr( $has_from ? $filter_date_from : '' ); ?>" />
			</label>
			<label style="margin-right:8px;">
				<?php esc_html_e( 'End Date', 'wpmmcc-ats' ); ?>
				<input type="date" name="manual_log_date_to" value="<?php echo esc_attr( $has_to ? $filter_date_to : '' ); ?>" />
			</label>
			<?php submit_button( __( 'Filter History', 'wpmmcc-ats' ), 'secondary', 'manual_log_filter', false ); ?>
		</form>

		<div style="font-size:12px; color:#555; margin-bottom:8px;">
			<?php
			printf(
				/* translators: 1: total count, 2: retry count, 3: skip count */
				esc_html__( 'Current results: %1$d entries (retry=%2$d, skip=%3$d)', 'wpmmcc-ats' ),
				intval( $summary['total'] ),
				intval( $summary['retry'] ),
				intval( $summary['skip'] )
			);
			?>
		</div>

		<?php if ( empty( $history_rows ) ) : ?>
			<p style="margin:0;"><?php esc_html_e( 'No manual intervention records under current filter conditions.', 'wpmmcc-ats' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:160px;"><?php esc_html_e( 'Time', 'wpmmcc-ats' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Task', 'wpmmcc-ats' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Action', 'wpmmcc-ats' ); ?></th>
						<th style="width:220px;"><?php esc_html_e( 'Subtask', 'wpmmcc-ats' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Operator', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Note', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history_rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['at'] ?? '' ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'log_for', intval( $row['task_id'] ?? 0 ) ) ); ?>">
									#<?php echo intval( $row['task_id'] ?? 0 ); ?>
								</a>
							</td>
							<td><code><?php echo esc_html( sanitize_key( (string) ( $row['action'] ?? '' ) ) ); ?></code></td>
							<td><code><?php echo esc_html( sanitize_key( (string) ( $row['type'] ?? 'text' ) ) ); ?>:<?php echo esc_html( sanitize_text_field( (string) ( $row['key'] ?? '' ) ) ); ?></code></td>
							<td>
								<?php echo esc_html( sanitize_text_field( (string) ( $row['user_login'] ?? '' ) ) ); ?>
								<?php if ( intval( $row['user_id'] ?? 0 ) > 0 ) : ?>
									(<?php echo intval( $row['user_id'] ); ?>)
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $row['note'] ?? '' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Handle task parameters save.
 */
function wptsall_handle_task_parameters_save() {
	// Check if form was submitted.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
	if ( ! isset( $_POST['wptsall_save_task_parameters'] ) ) {
		return;
	}

	// Verify nonce.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking here
	if ( ! isset( $_POST['wptsall_task_params_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wptsall_task_params_nonce'] ) ), 'wptsall_task_parameters' ) ) {
		wp_die( esc_html__( 'Security verification failed', 'wpmmcc-ats' ) );
	}

	// Collect parameters.
	$params = array();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above
	$fields = array(
		'high_priority_interval', 'normal_priority_interval', 'low_priority_interval', 'retry_interval',
		'high_priority_batch', 'normal_priority_batch', 'low_priority_batch', 'monitoring_batch',
		'max_retry_count', 'retry_delay_1', 'retry_delay_2', 'retry_delay_3',
		'cleanup_completed_days', 'cleanup_failed_days',
		'enable_parallel', 'max_concurrent_tasks',
		'task_timeout', 'enable_task_locking',
		'cache_default_ttl', 'cache_templates_ttl', 'cache_sites_ttl', 'cache_stats_ttl',
	);

	foreach ( $fields as $field ) {
		if ( isset( $_POST[ $field ] ) ) {
			$params[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		}
	}
	// phpcs:enable

	// Save parameters.
	wptsall_save_task_parameters( $params );

	wptsall_log_info( 'tasks-admin', 'Task parameters saved', $params );

	// Add success notice.
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Task parameters saved.', 'wpmmcc-ats' ) . '</p></div>';
	} );
}

/**
 * Render task parameters form.
 */
function wptsall_render_task_parameters_form() {
	$params = wptsall_get_task_parameters();
	?>
	<div class="wptsall-task-parameters" style="margin-top: 20px;">
		<form method="post" action="">
			<?php wp_nonce_field( 'wptsall_task_parameters', 'wptsall_task_params_nonce' ); ?>

			<!-- Cron Schedule Settings -->
			<div class="card" style="max-width: 800px; margin-bottom: 20px;">
				<h2><?php esc_html_e( 'Scheduled Dispatch Settings', 'wpmmcc-ats' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure execution intervals for tasks of different priorities.', 'wpmmcc-ats' ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="high_priority_interval"><?php esc_html_e( 'High Priority Interval', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="high_priority_interval" id="high_priority_interval"
								value="<?php echo esc_attr( $params['high_priority_interval'] ); ?>"
								min="1" max="60" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'High priority task check interval (1-60 minutes)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="normal_priority_interval"><?php esc_html_e( 'Normal Priority Interval', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="normal_priority_interval" id="normal_priority_interval"
								value="<?php echo esc_attr( $params['normal_priority_interval'] ); ?>"
								min="5" max="120" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'Normal priority task check interval (5-120 minutes)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="low_priority_interval"><?php esc_html_e( 'Low Priority Interval', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="low_priority_interval" id="low_priority_interval"
								value="<?php echo esc_attr( $params['low_priority_interval'] ); ?>"
								min="10" max="240" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'Low priority task check interval (10-240 minutes)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="monitoring_batch"><?php esc_html_e( 'Monitoring Scan Batch', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="monitoring_batch" id="monitoring_batch"
								value="<?php echo esc_attr( $params['monitoring_batch'] ); ?>"
								min="10" max="500" class="small-text" />
							<?php esc_html_e( 'objects', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'Number of objects scanned per monitoring task run (10-500)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Retry Settings -->
			<div class="card" style="max-width: 800px; margin-bottom: 20px;">
				<h2><?php esc_html_e( 'Retry Settings', 'wpmmcc-ats' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="max_retry_count"><?php esc_html_e( 'Maximum Retry Count', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="max_retry_count" id="max_retry_count"
								value="<?php echo esc_attr( $params['max_retry_count'] ); ?>"
								min="1" max="10" class="small-text" />
							<?php esc_html_e( 'times', 'wpmmcc-ats' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Retry Delay', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<p>
								<?php esc_html_e( '1st Retry:', 'wpmmcc-ats' ); ?>
								<input type="number" name="retry_delay_1" value="<?php echo esc_attr( $params['retry_delay_1'] ); ?>" min="1" max="60" class="small-text" />
								<?php esc_html_e( 'minutes later', 'wpmmcc-ats' ); ?>
							</p>
							<p>
								<?php esc_html_e( '2nd Retry:', 'wpmmcc-ats' ); ?>
								<input type="number" name="retry_delay_2" value="<?php echo esc_attr( $params['retry_delay_2'] ); ?>" min="5" max="120" class="small-text" />
								<?php esc_html_e( 'minutes later', 'wpmmcc-ats' ); ?>
							</p>
							<p>
								<?php esc_html_e( '3rd Retry:', 'wpmmcc-ats' ); ?>
								<input type="number" name="retry_delay_3" value="<?php echo esc_attr( $params['retry_delay_3'] ); ?>" min="15" max="1440" class="small-text" />
								<?php esc_html_e( 'minutes later', 'wpmmcc-ats' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Cleanup Settings -->
			<div class="card" style="max-width: 800px; margin-bottom: 20px;">
				<h2><?php esc_html_e( 'Cleanup Settings', 'wpmmcc-ats' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="cleanup_completed_days"><?php esc_html_e( 'Keep Completed Tasks', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="cleanup_completed_days" id="cleanup_completed_days"
								value="<?php echo esc_attr( $params['cleanup_completed_days'] ); ?>"
								min="1" max="365" class="small-text" />
							<?php esc_html_e( 'days', 'wpmmcc-ats' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cleanup_failed_days"><?php esc_html_e( 'Keep Failed Tasks', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="cleanup_failed_days" id="cleanup_failed_days"
								value="<?php echo esc_attr( $params['cleanup_failed_days'] ); ?>"
								min="7" max="365" class="small-text" />
							<?php esc_html_e( 'days', 'wpmmcc-ats' ); ?>
						</td>
					</tr>
				</table>
			</div>

			<!-- Performance Settings -->
			<div class="card" style="max-width: 800px; margin-bottom: 20px;">
				<h2><?php esc_html_e( 'Performance Settings', 'wpmmcc-ats' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="task_timeout"><?php esc_html_e( 'Task Timeout', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="task_timeout" id="task_timeout"
								value="<?php echo esc_attr( $params['task_timeout'] ); ?>"
								min="60" max="900" class="small-text" />
							<?php esc_html_e( 'seconds', 'wpmmcc-ats' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="enable_task_locking"><?php esc_html_e( 'Task Locking', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="enable_task_locking" id="enable_task_locking" value="1"
									<?php checked( $params['enable_task_locking'] ); ?> />
								<?php esc_html_e( 'Enable task locking (prevents the same task from being processed by multiple processes simultaneously)', 'wpmmcc-ats' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<!-- Cache Settings -->
			<div class="card" style="max-width: 800px; margin-bottom: 20px;">
				<h2><?php esc_html_e( 'Cache Settings', 'wpmmcc-ats' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure cache expiration for various data types. Caching improves performance, but overly long cache times may cause data inconsistency.', 'wpmmcc-ats' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="cache_default_ttl"><?php esc_html_e( 'Default Cache Time', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="cache_default_ttl" id="cache_default_ttl"
								value="<?php echo esc_attr( $params['cache_default_ttl'] ); ?>"
								min="5" max="1440" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'Default cache expiration time (5-1440 minutes)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cache_templates_ttl"><?php esc_html_e( 'Template Cache Time', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="cache_templates_ttl" id="cache_templates_ttl"
								value="<?php echo esc_attr( $params['cache_templates_ttl'] ); ?>"
								min="10" max="1440" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'Translation template data cache duration (10-1440 minutes)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cache_sites_ttl"><?php esc_html_e( 'Site Cache Time', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="cache_sites_ttl" id="cache_sites_ttl"
								value="<?php echo esc_attr( $params['cache_sites_ttl'] ); ?>"
								min="10" max="1440" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'Site relation and virtual site data cache duration (10-1440 minutes)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cache_stats_ttl"><?php esc_html_e( 'Stats Cache Time', 'wpmmcc-ats' ); ?></label>
						</th>
						<td>
							<input type="number" name="cache_stats_ttl" id="cache_stats_ttl"
								value="<?php echo esc_attr( $params['cache_stats_ttl'] ); ?>"
								min="1" max="60" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpmmcc-ats' ); ?>
							<p class="description"><?php esc_html_e( 'Task and hook statistics cache duration (1-60 minutes, shorter is recommended for frequently changing data)', 'wpmmcc-ats' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<p class="submit">
				<button type="submit" name="wptsall_save_task_parameters" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'wpmmcc-ats' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Handle bulk actions for tasks.
 */
function wptsall_handle_task_bulk_actions() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
	if ( ! isset( $_GET['action'] ) && ! isset( $_GET['action2'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
	$action = isset( $_GET['action'] ) && '-1' !== $_GET['action'] ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
	if ( ! $action ) {
		$action = isset( $_GET['action2'] ) && '-1' !== $_GET['action2'] ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : '';
	}

	if ( ! $action ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
	if ( empty( $_GET['task_ids'] ) ) {
		return;
	}

	// Verify nonce.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking here
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bulk-tasks' ) ) {
		wp_die( esc_html__( 'Security verification failed', 'wpmmcc-ats' ) );
	}

	global $wpdb;
	$table = wptsall_table( 'tasks' );
	$ids   = array_map( 'intval', (array) wp_unslash( $_GET['task_ids'] ) );
	$count = 0;

	foreach ( $ids as $id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$table,
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			continue;
		}

		if ( 'delete' === $action ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query
			$wpdb->delete( $table, array( 'id' => $id ) );
			$count++;
		} elseif ( 'retry' === $action ) {
			$note = sprintf(
				/* translators: %s: datetime */
				__( 'Manual retry (%s)', 'wpmmcc-ats' ),
				current_time( 'mysql' )
			);
			wptsall_update_task_status( $row, 'pending', $note, true );
			$count++;
		} elseif ( 'process' === $action ) {
			wptsall_update_task_status( $row, 'pending', 'Manually marked as pending, will be processed automatically by Cron', false );
			$count++;
		}
	}

	// Log bulk action.
	if ( $count > 0 ) {
		wptsall_log_info( 'tasks-admin', 'Bulk action performed', array( 'action' => $action, 'count' => $count ) );
		if ( function_exists( 'wptsall_cache_invalidate_tasks' ) ) {
			wptsall_cache_invalidate_tasks();
		}
	}

	// Redirect with success message.
	$sendback = remove_query_arg( array( 'action', 'action2', 'task_ids', '_wpnonce', '_wp_http_referer' ), wp_get_referer() );
	if ( $count > 0 ) {
		if ( 'delete' === $action ) {
			$sendback = add_query_arg( 'deleted', $count, $sendback );
		} elseif ( 'process' === $action ) {
			$sendback = add_query_arg( 'processed', $count, $sendback );
		} elseif ( 'retry' === $action ) {
			$sendback = add_query_arg( 'retried', $count, $sendback );
		}
	}
	wp_safe_redirect( $sendback );
	exit;
}

/**
 * Handle one-click actions in history tab.
 *
 * @return void
 */
function wptsall_handle_task_history_actions() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
	if ( ! isset( $_GET['wptsall_task_action'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
	$action = sanitize_key( wp_unslash( $_GET['wptsall_task_action'] ) );
	if ( ! in_array( $action, array( 'retry_failed', 'retry_failed_subtasks' ), true ) ) {
		return;
	}

	if ( 'retry_failed' === $action ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking here
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wptsall_retry_failed_tasks' ) ) {
			wp_die( esc_html__( 'Security verification failed', 'wpmmcc-ats' ) );
		}

		global $wpdb;
		$table = wptsall_table( 'tasks' );
		$note  = sprintf(
			/* translators: %s: datetime */
			__( 'Bulk retry failed tasks (%s)', 'wpmmcc-ats' ),
			current_time( 'mysql' )
		);
		$where_conditions = array( 'status IN (%s, %s)' );
		$params = array(
			$table,
			'failed',
			'retry',
		);
		wptsall_append_history_filters_to_query( $where_conditions, $params, $wpdb, true );
		$where_sql = implode( ' AND ', $where_conditions );
		// M21: explicit column list avoids loading payload LONGTEXT in batch retry queries.
		$params[] = 500;

		$rows = wptsall_db_get_results(
			'SELECT id, status, retry_count FROM %i WHERE ' . $where_sql . ' ORDER BY updated_at ASC LIMIT %d',
			$params,
			ARRAY_A
		);

		$count = 0;
		$task_ids = array();
		foreach ( (array) $rows as $row ) {
			$id = absint( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			wptsall_update_task_status( $row, 'pending', $note, true );
			++$count;
			$task_ids[] = $id;
		}

		if ( $count > 0 && function_exists( 'wptsall_cache_invalidate_tasks' ) ) {
			wptsall_cache_invalidate_tasks();
		}

		$sendback = remove_query_arg(
			array( 'wptsall_task_action', '_wpnonce', '_wp_http_referer' ),
			wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wptsall-tasks&tab=history' )
		);
		$sendback = add_query_arg(
			array(
				'retried_failed'       => $count,
				'retried_failed_scope' => wptsall_describe_history_filter_scope(),
				'retried_failed_tasks' => implode( ',', array_slice( $task_ids, 0, 20 ) ),
			),
			$sendback
		);
		wp_safe_redirect( $sendback );
		exit;
	}

	// retry_failed_subtasks branch.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking here
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wptsall_retry_failed_subtasks' ) ) {
		wp_die( esc_html__( 'Security verification failed', 'wpmmcc-ats' ) );
	}

	global $wpdb;
	$table = wptsall_table( 'tasks' );
	$now   = current_time( 'mysql' );
	$where_conditions = array( '1=1' );
	$params = array( $table );
	wptsall_append_history_filters_to_query( $where_conditions, $params, $wpdb, true );
	$where_sql = implode( ' AND ', $where_conditions );
	$params[]  = 500;

	$rows = wptsall_db_get_results(
		'SELECT id, status, retry_count, payload, meta FROM %i WHERE ' . $where_sql . ' ORDER BY updated_at ASC LIMIT %d',
		$params,
		ARRAY_A
	);

	$updated_tasks    = 0;
	$updated_subtasks = 0;
	$updated_task_ids = array();
	foreach ( (array) $rows as $row ) {
		$task_id = absint( $row['id'] ?? 0 );
		if ( $task_id <= 0 ) {
			continue;
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$meta    = json_decode( (string) ( $row['meta'] ?? '' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$failed_keys = wptsall_collect_failed_subtask_fragment_keys_from_meta( $meta );
		if ( empty( $failed_keys ) ) {
			continue;
		}

		$payload['manual_retry_only_subtasks'] = array_values( array_unique( $failed_keys ) );
		$meta['manual_last_action'] = array(
			'source'      => 'admin_history_batch',
			'action'      => 'retry_failed_subtasks',
			'task_id'     => $task_id,
			'subtask_cnt' => count( $failed_keys ),
			'at'          => $now,
			'user_id'     => get_current_user_id(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query
		$updated = $wpdb->update(
			$table,
			array(
				'status'      => 'retry',
				'status_note' => sprintf(
					/* translators: 1: subtask count, 2: datetime */
					__( 'Bulk retry failed subtasks (%1$d items, %2$s)', 'wpmmcc-ats' ),
					count( $failed_keys ),
					$now
				),
				'retry_count' => intval( $row['retry_count'] ?? 0 ) + 1,
				'payload'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'meta'        => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at'  => $now,
			),
			array( 'id' => $task_id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			continue;
		}

		$updated_tasks++;
		$updated_subtasks += count( $failed_keys );
		$updated_task_ids[] = $task_id;
	}

	if ( $updated_tasks > 0 && function_exists( 'wptsall_cache_invalidate_tasks' ) ) {
		wptsall_cache_invalidate_tasks();
	}

	$sendback = remove_query_arg(
		array( 'wptsall_task_action', '_wpnonce', '_wp_http_referer' ),
		wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wptsall-tasks&tab=history' )
	);
	$sendback = add_query_arg(
		array(
			'retried_failed_subtasks'       => $updated_tasks,
			'retried_failed_subtasks_items' => $updated_subtasks,
			'retried_failed_subtasks_scope' => wptsall_describe_history_filter_scope(),
			'retried_failed_subtasks_tasks' => implode( ',', array_slice( $updated_task_ids, 0, 20 ) ),
		),
		$sendback
	);
	wp_safe_redirect( $sendback );
	exit;
}

/**
 * Collect failed subtask fragment keys from meta.client_result.subtasks.
 *
 * @param array $meta Task meta.
 * @return array
 */
function wptsall_collect_failed_subtask_fragment_keys_from_meta( $meta ) {
	$keys = array();
	if ( ! is_array( $meta ) ) {
		return $keys;
	}
	$subtasks = is_array( $meta['client_result']['subtasks'] ?? null ) ? $meta['client_result']['subtasks'] : array();
	foreach ( $subtasks as $subtask ) {
		if ( ! is_array( $subtask ) ) {
			continue;
		}
		$status = sanitize_key( (string) ( $subtask['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'failed', 'error' ), true ) ) {
			continue;
		}
		$type = sanitize_key( strtolower( (string) ( $subtask['type'] ?? ( $subtask['task_type'] ?? 'text' ) ) ) );
		if ( in_array( $type, array( 'image_translation', 'images' ), true ) ) {
			$type = 'image';
		} elseif ( in_array( $type, array( 'video_translation', 'videos' ), true ) ) {
			$type = 'video';
		} elseif ( in_array( $type, array( 'audio_translation', 'audios' ), true ) ) {
			$type = 'audio';
		} elseif ( in_array( $type, array( 'document_translation', 'documents', 'doc', 'file', 'files' ), true ) ) {
			$type = 'document';
		} else {
			$type = 'text';
		}
		$key = strtolower( trim( (string) ( $subtask['key'] ?? '' ) ) );
		$key = str_replace( array( ' ', '.', '/' ), '_', $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', (string) $key );
		$key = preg_replace( '/_{2,}/', '_', (string) $key );
		$key = sanitize_key( trim( (string) $key, '_' ) );
		if ( '' === $key ) {
			continue;
		}
		$keys[] = $type . ':' . $key;
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Append history filter conditions from current request to SQL query.
 *
 * @param array  $where_conditions Where conditions by ref.
 * @param array  $params           Prepare params by ref.
 * @param object $wpdb             WordPress DB object.
 * @param bool   $include_status   Whether to include status filter from request.
 * @return void
 */
function wptsall_append_history_filters_to_query( &$where_conditions, &$params, $wpdb, $include_status = true ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$business_line = isset( $_GET['business_line'] ) ? sanitize_key( wp_unslash( $_GET['business_line'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$subtask_status = isset( $_GET['subtask_status'] ) ? sanitize_key( wp_unslash( $_GET['subtask_status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

	if ( $include_status && '' !== $status ) {
		$where_conditions[] = 'status = %s';
		$params[] = $status;
	}
	if ( $site_id > 0 ) {
		$where_conditions[] = 'site_id = %d';
		$params[] = $site_id;
	}
	if ( '' !== $template ) {
		$where_conditions[] = 'template = %s';
		$params[] = $template;
	}
	if ( '' !== $job_id ) {
		$where_conditions[] = 'payload LIKE %s';
		$params[] = '%"job_id":"' . $wpdb->esc_like( $job_id ) . '"%';
	}
	if ( '' !== $business_line ) {
		$where_conditions[] = 'payload LIKE %s';
		$params[] = '%"business_line":"' . $wpdb->esc_like( $business_line ) . '"%';
	}
	if ( '' !== $subtask_status ) {
		$subtask_patterns = array();
		if ( 'failed' === $subtask_status ) {
			$subtask_patterns = array( '"status":"failed"', '"status":"error"' );
		} elseif ( 'completed' === $subtask_status ) {
			$subtask_patterns = array( '"status":"completed"', '"status":"success"', '"status":"done"' );
		} elseif ( 'skipped' === $subtask_status ) {
			$subtask_patterns = array( '"status":"skipped"', '"status":"noop"' );
		} elseif ( 'pending' === $subtask_status ) {
			$subtask_patterns = array( '"status":"pending"', '"status":"processing"' );
		}
		if ( ! empty( $subtask_patterns ) ) {
			$parts = array();
			foreach ( $subtask_patterns as $pattern ) {
				$parts[] = 'meta LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $pattern ) . '%';
			}
			$where_conditions[] = '(' . implode( ' OR ', $parts ) . ')';
		}
	}
	if ( '' !== $search ) {
		$search_term = '%' . $wpdb->esc_like( $search ) . '%';
		$where_conditions[] = '(object_type LIKE %s OR subtype LIKE %s OR status_note LIKE %s)';
		$params[] = $search_term;
		$params[] = $search_term;
		$params[] = $search_term;
	}
}

/**
 * Describe current history filter scope.
 *
 * @return string
 */
function wptsall_describe_history_filter_scope() {
	$parts = array();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$business_line = isset( $_GET['business_line'] ) ? sanitize_key( wp_unslash( $_GET['business_line'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$subtask_status = isset( $_GET['subtask_status'] ) ? sanitize_key( wp_unslash( $_GET['subtask_status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter params only
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

	if ( '' !== $status ) {
		$parts[] = 'status=' . $status;
	}
	if ( $site_id > 0 ) {
		$parts[] = 'site_id=' . $site_id;
	}
	if ( '' !== $template ) {
		$parts[] = 'template=' . $template;
	}
	if ( '' !== $job_id ) {
		$parts[] = 'job_id=' . $job_id;
	}
	if ( '' !== $business_line ) {
		$parts[] = 'business_line=' . $business_line;
	}
	if ( '' !== $subtask_status ) {
		$parts[] = 'subtask_status=' . $subtask_status;
	}
	if ( '' !== $search ) {
		$parts[] = 'search=' . $search;
	}
	if ( empty( $parts ) ) {
		return __( 'All Tasks', 'wpmmcc-ats' );
	}
	return implode( '; ', $parts );
}

/**
 * Display task log.
 *
 * @param int $task_id Task ID.
 */
function wptsall_display_task_log( $task_id ) {
	global $wpdb;
	$log_table = wptsall_task_log_table_name();
	$task_table = wptsall_table( 'tasks' );

	// Get task info.
	$task = Monitoring_Task_Service::get( $task_id );
	// Get raw task row for client callback diagnostics.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query
	$task_row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, status, retry_count, status_note, created_at, updated_at, meta FROM %i WHERE id = %d',
			$task_table,
			$task_id
		),
		ARRAY_A
	);
	$task_meta = json_decode( (string) ( $task_row['meta'] ?? '' ), true );
	if ( ! is_array( $task_meta ) ) {
		$task_meta = array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query
	$logs = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM %i WHERE task_id = %d ORDER BY id DESC LIMIT 100',
			$log_table,
			$task_id
		),
		ARRAY_A
	);

	// Calculate summary stats from logs.
	$summary = array(
		'total_cycles'   => 0,
		'total_checked'  => 0,
		'total_synced'   => 0,
		'total_errors'   => 0,
		'last_error'     => null,
		'models_stats'   => array(),
	);

	foreach ( $logs as $log ) {
		if ( ! empty( $log['context'] ) ) {
			$context = json_decode( $log['context'], true );
			if ( $context && isset( $context['total_checked'] ) ) {
				$summary['total_cycles']++;
				$summary['total_checked'] += (int) ( $context['total_checked'] ?? 0 );
				$summary['total_synced']  += (int) ( $context['synced'] ?? 0 );
				$summary['total_errors']  += (int) ( $context['errors'] ?? 0 );

				// Aggregate models stats.
				if ( ! empty( $context['models_checked'] ) ) {
					foreach ( $context['models_checked'] as $model_id => $stats ) {
						if ( ! isset( $summary['models_stats'][ $model_id ] ) ) {
							$summary['models_stats'][ $model_id ] = array(
								'checked' => 0,
								'synced'  => 0,
								'errors'  => 0,
							);
						}
						$summary['models_stats'][ $model_id ]['checked'] += (int) ( $stats['checked'] ?? 0 );
						$summary['models_stats'][ $model_id ]['synced']  += (int) ( $stats['synced'] ?? 0 );
						$summary['models_stats'][ $model_id ]['errors']  += (int) ( $stats['errors'] ?? 0 );
					}
				}
			}

			// Track last error.
			if ( ( $context['errors'] ?? 0 ) > 0 && ! $summary['last_error'] ) {
				$summary['last_error'] = array(
					'time'    => $log['created_at'],
					'context' => $context,
				);
			}
		}
	}

	// Get model names for display.
	$model_names = array();
	if ( $task && ! empty( $task['progress']['models_progress'] ) ) {
		foreach ( $task['progress']['models_progress'] as $model_id => $info ) {
			$model_names[ $model_id ] = $info['plugin_name'] ?? "Model #{$model_id}";
		}
	}

	Admin_Page_Helper::render_header(
		sprintf(
			/* translators: %d: task id */
			esc_html__( 'Task Execution Log #%d', 'wpmmcc-ats' ),
			intval( $task_id )
		),
		'tasks',
		array(
			array(
				'label' => __( '← Back to task list', 'wpmmcc-ats' ),
				'url'   => remove_query_arg( 'log_for' ),
				'class' => 'button',
			),
		)
	);
	?>
	<div class="wptsall-page-content">
		<?php if ( $task_row ) : ?>
		<div class="card" style="max-width: 100%; margin-bottom: 20px;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Translation Service Callback Overview', 'wpmmcc-ats' ); ?></h2>
			<?php
			$client_agg  = isset( $task_meta['client_result_aggregate'] ) && is_array( $task_meta['client_result_aggregate'] ) ? $task_meta['client_result_aggregate'] : array();
			$client_env  = isset( $task_meta['client_result_envelope'] ) && is_array( $task_meta['client_result_envelope'] ) ? $task_meta['client_result_envelope'] : array();
			$client_last = isset( $task_meta['client_last_status'] ) && is_array( $task_meta['client_last_status'] ) ? $task_meta['client_last_status'] : array();
			?>
			<table class="form-table" style="margin: 0;">
				<tr>
					<th style="width: 160px;"><?php esc_html_e( 'Task Status', 'wpmmcc-ats' ); ?></th>
					<td>
						<?php echo esc_html( sanitize_key( (string) ( $task_row['status'] ?? '' ) ) ); ?>
						&nbsp;|&nbsp;
						retry_count=<?php echo intval( $task_row['retry_count'] ?? 0 ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Aggregate Fields', 'wpmmcc-ats' ); ?></th>
					<td>
						fields=<?php echo intval( $client_agg['field_total'] ?? 0 ); ?>,
						fallback=<?php echo intval( $client_agg['field_fallback'] ?? 0 ); ?>,
						subtasks=<?php echo intval( $client_agg['subtask_total'] ?? 0 ); ?>,
						subtask_failed=<?php echo intval( $client_agg['subtask_failed'] ?? 0 ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Component & Duration', 'wpmmcc-ats' ); ?></th>
					<td>
						component=<?php echo esc_html( sanitize_text_field( (string) ( $client_env['component_id'] ?? '-' ) ) ); ?>,
						elapsed=<?php echo intval( $client_env['elapsed_ms'] ?? 0 ); ?>ms
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Latest Service Status', 'wpmmcc-ats' ); ?></th>
					<td>
						<?php echo esc_html( sanitize_key( (string) ( $client_last['status'] ?? '-' ) ) ); ?>,
						progress=<?php echo intval( $client_last['progress'] ?? 0 ); ?>,
						at=<?php echo esc_html( sanitize_text_field( (string) ( $client_last['at'] ?? '' ) ) ); ?>
					</td>
				</tr>
			</table>
		</div>
		<?php endif; ?>

		<?php if ( $task ) : ?>
		<!-- Task Status Overview -->
		<div class="card" style="max-width: 100%; margin-bottom: 20px;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Task Status', 'wpmmcc-ats' ); ?></h2>
			<?php
			$status       = $task['status'] ?? 'unknown';
			$progress     = $task['progress'] ?? array();
			$total_items  = $progress['total_items'] ?? 0;
			$synced_items = $progress['synced_items'] ?? 0;
			$pending      = $progress['pending_items'] ?? 0;
			$failed       = $progress['failed_items'] ?? 0;
			$percentage   = $progress['percentage'] ?? 0;

			$status_colors = array(
				'active'    => '#2271b1',
				'paused'    => '#dba617',
				'completed' => '#00a32a',
				'error'     => '#d63638',
			);
			$status_labels = array(
				'active'    => __( 'Running', 'wpmmcc-ats' ),
				'paused'    => __( 'Paused', 'wpmmcc-ats' ),
				'completed' => __( 'Completed', 'wpmmcc-ats' ),
				'error'     => __( 'Error', 'wpmmcc-ats' ),
			);
			?>
			<table class="form-table" style="margin: 0;">
				<tr>
					<th style="width: 120px;"><?php esc_html_e( 'Current Status', 'wpmmcc-ats' ); ?></th>
					<td>
						<span style="display: inline-block; padding: 4px 12px; border-radius: 3px; background: <?php echo esc_attr( $status_colors[ $status ] ?? '#999' ); ?>; color: #fff; font-weight: 600;">
							<?php echo esc_html( $status_labels[ $status ] ?? $status ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Sync Progress', 'wpmmcc-ats' ); ?></th>
					<td>
						<div style="display: flex; align-items: center; gap: 15px;">
							<div style="flex: 1; max-width: 300px;">
								<div style="background: #e0e0e0; border-radius: 3px; height: 12px; overflow: hidden;">
									<div style="background: <?php echo esc_attr( $status_colors[ $status ] ?? '#999' ); ?>; width: <?php echo absint( min( 100, $percentage ) ); ?>%; height: 100%;"></div>
								</div>
							</div>
							<strong><?php echo esc_html( number_format( $percentage, 1 ) ); ?>%</strong>
						</div>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Statistics', 'wpmmcc-ats' ); ?></th>
					<td>
						<span style="margin-right: 20px;"><strong><?php esc_html_e( 'Total:', 'wpmmcc-ats' ); ?></strong> <?php echo intval( $total_items ); ?></span>
						<span style="margin-right: 20px; color: #00a32a;"><strong><?php esc_html_e( 'Synced:', 'wpmmcc-ats' ); ?></strong> <?php echo intval( $synced_items ); ?></span>
						<span style="margin-right: 20px; color: #dba617;"><strong><?php esc_html_e( 'Pending:', 'wpmmcc-ats' ); ?></strong> <?php echo intval( $pending ); ?></span>
						<?php if ( $failed > 0 ) : ?>
						<span style="color: #d63638;"><strong><?php esc_html_e( 'Failed:', 'wpmmcc-ats' ); ?></strong> <?php echo intval( $failed ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
		<?php endif; ?>

		<!-- Execution Summary -->
		<?php if ( $summary['total_cycles'] > 0 ) : ?>
		<div class="card" style="max-width: 100%; margin-bottom: 20px;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Execution Statistics Summary', 'wpmmcc-ats' ); ?></h2>
			<table class="form-table" style="margin: 0;">
				<tr>
					<th style="width: 120px;"><?php esc_html_e( 'Execution Count', 'wpmmcc-ats' ); ?></th>
					<td><?php echo intval( $summary['total_cycles'] ); ?> <?php esc_html_e( 'times', 'wpmmcc-ats' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total Checked', 'wpmmcc-ats' ); ?></th>
					<td><?php echo intval( $summary['total_checked'] ); ?> <?php esc_html_e( 'items', 'wpmmcc-ats' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total Synced', 'wpmmcc-ats' ); ?></th>
					<td style="color: #00a32a;"><?php echo intval( $summary['total_synced'] ); ?> <?php esc_html_e( 'items', 'wpmmcc-ats' ); ?></td>
				</tr>
				<?php if ( $summary['total_errors'] > 0 ) : ?>
				<tr>
					<th><?php esc_html_e( 'Total Errors', 'wpmmcc-ats' ); ?></th>
					<td style="color: #d63638;"><?php echo intval( $summary['total_errors'] ); ?> <?php esc_html_e( 'items', 'wpmmcc-ats' ); ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( ! empty( $summary['models_stats'] ) ) : ?>
			<h3 style="margin-top: 20px;"><?php esc_html_e( 'Statistics by Models (Plugins)', 'wpmmcc-ats' ); ?></h3>
			<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Models', 'wpmmcc-ats' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Checked', 'wpmmcc-ats' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Synced', 'wpmmcc-ats' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Error', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $summary['models_stats'] as $model_id => $stats ) : ?>
					<tr>
						<td><?php echo esc_html( $model_names[ $model_id ] ?? "Model #{$model_id}" ); ?></td>
						<td><?php echo intval( $stats['checked'] ); ?></td>
						<td style="color: #00a32a;"><?php echo intval( $stats['synced'] ); ?></td>
						<td style="color: <?php echo $stats['errors'] > 0 ? '#d63638' : 'inherit'; ?>;"><?php echo intval( $stats['errors'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( $summary['last_error'] ) : ?>
			<h3 style="margin-top: 20px; color: #d63638;"><?php esc_html_e( 'Recent Errors', 'wpmmcc-ats' ); ?></h3>
			<div style="background: #fef7f7; border: 1px solid #d63638; padding: 10px; border-radius: 3px;">
				<p style="margin: 0 0 5px 0;"><strong><?php esc_html_e( 'Time:', 'wpmmcc-ats' ); ?></strong> <?php echo esc_html( $summary['last_error']['time'] ); ?></p>
				<p style="margin: 0;"><strong><?php esc_html_e( 'Error count:', 'wpmmcc-ats' ); ?></strong> <?php echo intval( $summary['last_error']['context']['errors'] ?? 0 ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<!-- Detailed Logs -->
		<div class="card" style="max-width: 100%;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Execution Log Details', 'wpmmcc-ats' ); ?></h2>

			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No log entries', 'wpmmcc-ats' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 50px;">ID</th>
							<th style="width: 150px;"><?php esc_html_e( 'Status Change', 'wpmmcc-ats' ); ?></th>
							<th style="width: 200px;"><?php esc_html_e( 'Execution Result', 'wpmmcc-ats' ); ?></th>
							<th><?php esc_html_e( 'Note', 'wpmmcc-ats' ); ?></th>
							<th style="width: 160px;"><?php esc_html_e( 'Time', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<?php
							$context      = ! empty( $log['context'] ) ? json_decode( $log['context'], true ) : null;
							$has_stats    = $context && isset( $context['total_checked'] );
							$has_errors   = $context && ( $context['errors'] ?? 0 ) > 0;
							$row_style    = $has_errors ? 'background: #fef7f7;' : '';
							?>
							<tr style="<?php echo esc_attr( $row_style ); ?>">
								<td><?php echo intval( $log['id'] ); ?></td>
								<td>
									<code><?php echo esc_html( $log['status_from'] ); ?></code>
									→
									<code><?php echo esc_html( $log['status_to'] ); ?></code>
								</td>
								<td>
									<?php if ( $has_stats ) : ?>
										<span title="<?php esc_attr_e( 'Checked', 'wpmmcc-ats' ); ?>"><?php echo intval( $context['total_checked'] ?? 0 ); ?></span> /
										<span style="color: #00a32a;" title="<?php esc_attr_e( 'Synced', 'wpmmcc-ats' ); ?>"><?php echo intval( $context['synced'] ?? 0 ); ?></span> /
										<span style="color: <?php echo $has_errors ? '#d63638' : 'inherit'; ?>;" title="<?php esc_attr_e( 'Error', 'wpmmcc-ats' ); ?>"><?php echo intval( $context['errors'] ?? 0 ); ?></span>
										<small style="color: #666;">(Checked/Synced/Error)</small>
									<?php else : ?>
										<span style="color: #999;">-</span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $log['note'] ); ?>
									<?php if ( $has_errors && ! empty( $context['models_checked'] ) ) : ?>
										<details style="margin-top: 5px;">
											<summary style="cursor: pointer; color: #d63638; font-size: 12px;"><?php esc_html_e( 'View Error Details', 'wpmmcc-ats' ); ?></summary>
											<div style="margin-top: 5px; padding: 5px; background: #fff; border: 1px solid #ddd; font-size: 11px;">
												<?php foreach ( $context['models_checked'] as $mid => $mstats ) : ?>
													<?php if ( ( $mstats['errors'] ?? 0 ) > 0 ) : ?>
														<div style="margin-bottom: 3px;">
															<strong><?php echo esc_html( $model_names[ $mid ] ?? "Model #{$mid}" ); ?>:</strong>
															<?php echo intval( $mstats['errors'] ); ?> <?php esc_html_e( 'error(s)', 'wpmmcc-ats' ); ?>
														</div>
													<?php endif; ?>
												<?php endforeach; ?>
											</div>
										</details>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $log['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<?php
	Admin_Page_Helper::render_footer();
}
