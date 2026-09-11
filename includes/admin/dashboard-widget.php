<?php
/**
 * WPTSALL Dashboard Widget
 *
 * Displays statistics and quick actions on WordPress dashboard.
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register dashboard widgets.
 */
function wptsall_register_dashboard_widgets() {
	if ( ! wptsall_user_can_manage_translations() ) {
		return;
	}

	wp_add_dashboard_widget(
		'wptsall_stats_widget',
		__( 'WPTSALL Statistics Overview', 'wpmmcc-ats' ),
		'wptsall_render_dashboard_widget'
	);
}
add_action( 'wp_dashboard_setup', 'wptsall_register_dashboard_widgets' );

/**
 * Enqueue dashboard widget styles on the dashboard screen.
 *
 * @param string $hook Current admin page hook.
 */
function wptsall_dashboard_widget_assets( $hook ) {
	if ( 'index.php' !== $hook || ! wptsall_user_can_manage_translations() ) {
		return;
	}

	$style_rel  = 'assets/css/dashboard-widget.css';
	$style_path = WPTSALL_PATH . $style_rel;
	wp_enqueue_style(
		'wptsall-dashboard-widget',
		WPTSALL_URL . $style_rel,
		array(),
		file_exists( $style_path ) ? (string) filemtime( $style_path ) : WPTSALL_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'wptsall_dashboard_widget_assets' );

/**
 * Render dashboard widget content.
 */
function wptsall_render_dashboard_widget() {
	// Get cached statistics.
	$task_stats = array();
	if ( function_exists( 'wptsall_get_cached_task_stats' ) ) {
		$task_stats = wptsall_get_cached_task_stats();
	}
	$hook_stats = wptsall_get_cached_hook_stats();

	// Get templates and sites count.
	$templates = wptsall_saved_templates();
	$sites     = get_option( 'wptsall_sites', array() );
	$virtual   = wptsall_get_virtual_sites();

	?>
	<div class="wptsall-dashboard-widget">
		<?php if ( ! empty( $task_stats ) ) : ?>
		<!-- Task Statistics -->
		<h4 style="margin: 0 0 10px 0; font-size: 14px; color: #1d2327;">
			<span class="dashicons dashicons-list-view" style="font-size: 16px; vertical-align: middle;"></span>
			<?php esc_html_e( 'Task Statistics', 'wpmmcc-ats' ); ?>
		</h4>
		<div class="wptsall-stat-grid">
			<?php foreach ( array( 'pending', 'processing', 'completed', 'retry' ) as $status ) : ?>
				<div class="wptsall-stat-card status-<?php echo esc_attr( $status ); ?>">
					<div class="wptsall-stat-number"><?php echo intval( $task_stats[ $status ] ?? 0 ); ?></div>
					<div class="wptsall-stat-label"><?php echo esc_html( ucfirst( $status ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<!-- Hook Statistics -->
		<h4 style="margin: 15px 0 10px 0; font-size: 14px; color: #1d2327;">
			<span class="dashicons dashicons-admin-plugins" style="font-size: 16px; vertical-align: middle;"></span>
			<?php esc_html_e( 'Hook Statistics', 'wpmmcc-ats' ); ?>
		</h4>
		<div class="wptsall-stat-grid">
			<?php foreach ( array( 'enabled', 'disabled' ) as $status ) : ?>
				<div class="wptsall-stat-card status-<?php echo esc_attr( $status ); ?>">
					<div class="wptsall-stat-number"><?php echo intval( $hook_stats[ $status ] ?? 0 ); ?></div>
					<div class="wptsall-stat-label"><?php echo esc_html( ucfirst( $status ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- Configuration Summary -->
		<h4 style="margin: 15px 0 10px 0; font-size: 14px; color: #1d2327;">
			<span class="dashicons dashicons-admin-settings" style="font-size: 16px; vertical-align: middle;"></span>
			<?php esc_html_e( 'Configuration Overview', 'wpmmcc-ats' ); ?>
		</h4>
		<div class="wptsall-stat-grid">
			<div class="wptsall-stat-card">
				<div class="wptsall-stat-number"><?php echo count( $templates ); ?></div>
				<div class="wptsall-stat-label"><?php esc_html_e( 'Templates', 'wpmmcc-ats' ); ?></div>
			</div>
			<div class="wptsall-stat-card">
				<div class="wptsall-stat-number"><?php echo count( $sites ); ?></div>
				<div class="wptsall-stat-label"><?php esc_html_e( 'Site Relations', 'wpmmcc-ats' ); ?></div>
			</div>
			<div class="wptsall-stat-card">
				<div class="wptsall-stat-number"><?php echo count( $virtual ); ?></div>
				<div class="wptsall-stat-label"><?php esc_html_e( 'Virtual Sites', 'wpmmcc-ats' ); ?></div>
			</div>
		</div>

		<!-- Translation Progress (P5-7) -->
		<?php
		if ( class_exists( '\\WPTSALL\\ManualTranslation\\Services\\Translation_Progress_Service' ) ) :
			$progress = \WPTSALL\ManualTranslation\Services\Translation_Progress_Service::summary();
			$by_lang  = $progress['by_language'] ?? array();
		?>
		<h4 style="margin: 15px 0 10px 0; font-size: 14px; color: #1d2327;">
			<span class="dashicons dashicons-translation" style="font-size: 16px; vertical-align: middle;"></span>
			<?php esc_html_e( 'Translation Progress', 'wpmmcc-ats' ); ?>
		</h4>
		<div class="wptsall-stat-grid">
			<?php
			$default_lang = \WPTSALL\Languages\Services\Language_Service::get_default();
			foreach ( $by_lang as $code => $stats ) :
				$total      = (int) ( $stats['total'] ?? 0 );
				$translated = (int) ( $stats['translated'] ?? 0 );
				$pct        = $total > 0 ? (int) round( ( $translated / $total ) * 100 ) : 0;
				$is_default = $default_lang && ( (string) $default_lang['code'] ) === $code;
			?>
			<div class="wptsall-stat-card" title="<?php echo esc_attr( $code ); ?>">
				<div class="wptsall-stat-number"><?php echo esc_html( $pct ); ?>%</div>
				<div class="wptsall-stat-label">
					<?php echo esc_html( $code ); ?><?php echo $is_default ? ' (default)' : ''; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<!-- Quick Actions -->
		<div class="wptsall-quick-actions">
			<h4><?php esc_html_e( 'Quick Actions', 'wpmmcc-ats' ); ?></h4>
			<div class="wptsall-action-buttons">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-tasks' ) ); ?>" class="wptsall-action-btn">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'View Tasks', 'wpmmcc-ats' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpmmcc-ats' ) ); ?>" class="wptsall-action-btn">
					<span class="dashicons dashicons-admin-tools"></span>
					<?php esc_html_e( 'Manage Templates', 'wpmmcc-ats' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-sites' ) ); ?>" class="wptsall-action-btn">
					<span class="dashicons dashicons-admin-plugins"></span>
					<?php esc_html_e( 'Manage Sites', 'wpmmcc-ats' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpmmcc-ats' ) ); ?>" class="wptsall-action-btn">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Import/Export', 'wpmmcc-ats' ); ?>
				</a>
			</div>
		</div>

		<!-- Cache Info -->
		<?php
		$cache_stats = wptsall_cache_get_stats();
		if ( $cache_stats['count'] > 0 ) :
			?>
			<p style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #dcdcde; font-size: 12px; color: #646970;">
				<span class="dashicons dashicons-backup" style="font-size: 14px; vertical-align: middle;"></span>
				<?php
				printf(
					/* translators: 1: Cache count, 2: Cache size */
					esc_html__( 'Cache: %1$d items (%2$s)', 'wpmmcc-ats' ),
					intval( $cache_stats['count'] ),
					esc_html( $cache_stats['size_human'] )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Add admin bar quick links.
 *
 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
 */
function wptsall_add_admin_bar_menu( $wp_admin_bar ) {
	if ( ! wptsall_user_can_manage_translations() ) {
		return;
	}

	// Main menu.
	$wp_admin_bar->add_node(
		array(
			'id'    => 'wpmmcc-ats',
			'title' => '<span class="ab-icon dashicons dashicons-networking"></span><span class="ab-label">WPTSALL</span>',
			'href'  => admin_url( 'admin.php?page=wpmmcc-ats' ),
			'meta'  => array(
				'title' => __( 'WPTSALL Management', 'wpmmcc-ats' ),
			),
		)
	);

	// Tasks submenu with badge.
	if ( function_exists( 'wptsall_get_cached_task_stats' ) ) {
		$task_stats = wptsall_get_cached_task_stats();
		$pending    = intval( $task_stats['pending'] ?? 0 );
		$retry      = intval( $task_stats['retry'] ?? 0 );

		$badge = '';
		if ( $pending + $retry > 0 ) {
			$badge = sprintf(
				' <span class="wptsall-badge" style="background:#dc3232;color:#fff;border-radius:10px;padding:2px 6px;font-size:11px;margin-left:5px;">%d</span>',
				$pending + $retry
			);
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'wpmmcc-ats',
				'id'     => 'wptsall-tasks',
				'title'  => __( 'Tasks', 'wpmmcc-ats' ) . $badge,
				'href'   => admin_url( 'admin.php?page=wptsall-tasks' ),
			)
		);
	}

	$wp_admin_bar->add_node(
		array(
			'parent' => 'wpmmcc-ats',
			'id'     => 'wptsall-models',
			'title'  => __( 'Models', 'wpmmcc-ats' ),
			'href'   => admin_url( 'admin.php?page=wpmmcc-ats' ),
		)
	);

	$wp_admin_bar->add_node(
		array(
			'parent' => 'wpmmcc-ats',
			'id'     => 'wptsall-sites',
			'title'  => __( 'Sites', 'wpmmcc-ats' ),
			'href'   => admin_url( 'admin.php?page=wptsall-sites' ),
		)
	);
}
add_action( 'admin_bar_menu', 'wptsall_add_admin_bar_menu', 100 );
