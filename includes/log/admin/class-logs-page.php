<?php
/**
 * Admin Logs Page — ops file logs + audit trail.
 *
 * @package WPTSALL\Log\Admin
 * @since 2.1.5
 */

namespace WPTSALL\Log\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs_Page
 */
class Logs_Page {

	const PAGE_SLUG = 'wptsall-logs';
	const CAP       = 'manage_wptsall_settings';

	/**
	 * Register submenu.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Logs', 'wpmmcc-ats' ),
			__( 'Logs', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle audit clear POST.
	 *
	 * @return void
	 */
	public static function handle_clear_audit() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_clear_audit_logs' );
		if ( class_exists( '\\WPTSALL\\CLI\\Audit_Log' ) ) {
			\WPTSALL\CLI\Audit_Log::clear();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'tab'     => 'audit',
					'cleared' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'ops';
		if ( ! in_array( $tab, array( 'ops', 'audit' ), true ) ) {
			$tab = 'ops';
		}

		Admin_Page_Helper::render_header(
			__( 'Logs', 'wpmmcc-ats' ),
			'logs'
		);
		echo '<p class="description">' . esc_html__( 'Inspect ops file logs (debug mode) and the translation audit trail.', 'wpmmcc-ats' ) . '</p>';

		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<h2 class="nav-tab-wrapper">
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'ops', $base ) ); ?>" class="nav-tab <?php echo 'ops' === $tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Ops files', 'wpmmcc-ats' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'audit', $base ) ); ?>" class="nav-tab <?php echo 'audit' === $tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Audit trail', 'wpmmcc-ats' ); ?>
			</a>
		</h2>
		<?php
		if ( 'audit' === $tab ) {
			self::render_audit_tab();
		} else {
			self::render_ops_tab();
		}
	}

	/**
	 * Ops file logs tab.
	 *
	 * @return void
	 */
	private static function render_ops_tab() {
		$enabled = function_exists( 'wptsall_log_enabled' ) && wptsall_log_enabled();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lines = isset( $_GET['lines'] ) ? absint( $_GET['lines'] ) : 200;
		if ( $lines <= 0 ) {
			$lines = 200;
		}
		$lines = min( 2000, $lines );

		$files = function_exists( 'wptsall_get_log_files' ) ? wptsall_get_log_files( null ) : array();
		?>
		<div class="wrap">
			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Ops file logging is currently disabled. Enable Settings → Debug mode or define WPTSALL_LOG_ENABLED in wp-config.php.', 'wpmmcc-ats' ); ?>
				</p></div>
			<?php else : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Ops file logging is enabled. Files live under uploads/wptsall-logs-* (web-inaccessible).', 'wpmmcc-ats' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="ops">
				<label for="wptsall-log-file"><?php esc_html_e( 'File', 'wpmmcc-ats' ); ?></label>
				<select name="file" id="wptsall-log-file">
					<option value=""><?php esc_html_e( '— select —', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( (array) $files as $item ) : ?>
						<?php $name = (string) ( $item['name'] ?? '' ); ?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $file, $name ); ?>>
							<?php echo esc_html( $name ); ?>
							(<?php echo esc_html( size_format( (int) ( $item['size'] ?? 0 ) ) ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<label for="wptsall-log-lines"><?php esc_html_e( 'Lines', 'wpmmcc-ats' ); ?></label>
				<input type="number" min="20" max="2000" name="lines" id="wptsall-log-lines" value="<?php echo (int) $lines; ?>" class="small-text">
				<?php submit_button( __( 'View', 'wpmmcc-ats' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( empty( $files ) ) : ?>
				<p><?php esc_html_e( 'No ops log files found yet.', 'wpmmcc-ats' ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $file ) : ?>
				<?php
				$path    = trailingslashit( Log\get_log_dir() ) . $file;
				$content = function_exists( 'wptsall_read_log_file' ) ? wptsall_read_log_file( $path, $lines ) : '';
				?>
				<h3><?php echo esc_html( $file ); ?></h3>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;overflow:auto;max-height:560px;font-size:12px;"><?php echo esc_html( $content ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Audit trail tab.
	 *
	 * @return void
	 */
	private static function render_audit_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cleared = isset( $_GET['cleared'] );
		$rows    = class_exists( '\\WPTSALL\\CLI\\Audit_Log' )
			? \WPTSALL\CLI\Audit_Log::list( 100 )
			: array();
		?>
		<div class="wrap">
			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Audit log cleared.', 'wpmmcc-ats' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
				<?php wp_nonce_field( 'wptsall_clear_audit_logs' ); ?>
				<input type="hidden" name="action" value="wptsall_clear_audit_logs">
				<?php submit_button( __( 'Clear audit log', 'wpmmcc-ats' ), 'delete', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'User', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Action', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Data', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No audit entries yet.', 'wpmmcc-ats' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['time'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['user'] ?? '' ) ); ?></td>
								<td><code><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></code></td>
								<td><code><?php echo esc_html( wp_json_encode( $row['data'] ?? array() ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
