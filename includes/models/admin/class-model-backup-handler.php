<?php
/**
 * Model Backup / Restore (1.2.0)
 *
 * Server-side JSON backup and restore for the wp_wptsall_models table.
 * Falls back to a server-side implementation when the V3 client UI isn't
 * available (e.g. zero-side-effect tests, headless envs).
 *
 * @package WPTSALL\Models\Admin
 * @since 1.2.0
 */

namespace WPTSALL\Models\Admin;

use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Model_Backup_Handler {

	const PAGE_SLUG = 'wptsall-models-backup';
	const CAP       = 'manage_wptsall_settings';

	public static function init() {
		add_action( 'admin_post_wptsall_model_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_wptsall_model_import', array( __CLASS__, 'handle_import' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Models Backup', 'wpmmcc-ats' ),
			__( 'Models Backup', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'wptsall_models' )
		);

		Admin_Page_Helper::render_header(
			__( 'Models Backup & Restore', 'wpmmcc-ats' ),
			/* translators: %d: <value> */
			sprintf( __( 'Server-side JSON export/import of the %d model rows in wp_wptsall_models.', 'wpmmcc-ats' ), $count )
		);
		?>
		<h2><?php esc_html_e( 'Export', 'wpmmcc-ats' ); ?></h2>
		<p><?php esc_html_e( 'Download all model rows as a JSON file you can archive or share.', 'wpmmcc-ats' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_model_export' ); ?>
			<input type="hidden" name="action" value="wptsall_model_export">
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Download backup.json', 'wpmmcc-ats' ); ?></button>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Import', 'wpmmcc-ats' ); ?></h2>
		<p><?php esc_html_e( 'Upload a backup.json file. Existing models with the same plugin_slug are updated; new ones are inserted. A dry-run preflight lists the changes before they happen.', 'wpmmcc-ats' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'wptsall_model_import' ); ?>
			<input type="hidden" name="action" value="wptsall_model_import">
			<input type="hidden" name="dry_run" value="1">
			<input type="file" name="backup_file" accept=".json" required>
			<button class="button" type="submit"><?php esc_html_e( 'Dry-run preview', 'wpmmcc-ats' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" onsubmit="return confirm('<?php echo esc_js( __( 'Apply this import? Existing rows with the same plugin_slug will be overwritten.', 'wpmmcc-ats' ) ); ?>');">
			<?php wp_nonce_field( 'wptsall_model_import' ); ?>
			<input type="hidden" name="action" value="wptsall_model_import">
			<input type="hidden" name="dry_run" value="0">
			<input type="file" name="backup_file" accept=".json" required>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Import & apply', 'wpmmcc-ats' ); ?></button>
		</form>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_model_export' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . 'wptsall_models' ),
			ARRAY_A
		);
		$payload = array(
			'schema'   => 'wptsall-models/1',
			'exported_at' => current_time( 'mysql' ),
			'count'    => count( (array) $rows ),
			'rows'     => array_map( array( __CLASS__, 'normalize_row' ), (array) $rows ),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wptsall-models-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_model_import' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path validated via is_uploaded_file().
		$tmp_name = isset( $_FILES['backup_file']['tmp_name'] ) ? (string) $_FILES['backup_file']['tmp_name'] : '';
		if ( '' === $tmp_name ) {
			wp_die( esc_html__( 'No file uploaded.', 'wpmmcc-ats' ) );
		}
		if ( ! is_uploaded_file( $tmp_name ) ) {
			wp_die( esc_html__( 'Invalid upload.', 'wpmmcc-ats' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading validated upload tmp file.
		$raw  = file_get_contents( $tmp_name );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['schema'] ) || 'wptsall-models/1' !== $data['schema'] || empty( $data['rows'] ) ) {
			wp_die( esc_html__( 'Invalid backup file format.', 'wpmmcc-ats' ) );
		}
		$dry_run = ! empty( $_POST['dry_run'] );
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_models';
		$would_insert = 0; $would_update = 0; $applied = 0;
		foreach ( (array) $data['rows'] as $row ) {
			$row = self::normalize_row( $row );
			$slug = (string) ( $row['plugin_slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE plugin_slug = %s", $table, $slug ) );
			if ( $existing > 0 ) {
				$would_update++;
				if ( ! $dry_run ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->update( $table, $row, array( 'id' => $existing ) );
					$applied++;
				}
			} else {
				$would_insert++;
				if ( ! $dry_run ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert( $table, $row );
					$applied++;
				}
			}
		}
		$msg = $dry_run
			? sprintf( 'DRY RUN: %d inserts, %d updates pending.', $would_insert, $would_update )
			: sprintf( 'APPLIED: %d inserts, %d updates (%d total applied).', $would_insert, $would_update, $applied );
		wp_safe_redirect( add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'msg'     => rawurlencode( $msg ),
				'dry_run' => $dry_run ? '1' : '0',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	private static function normalize_row( $row ) {
		$allowed = array( 'plugin_slug', 'plugin_name', 'plugin_version', 'text_domain', 'description', 'plugin_file', 'is_content_plugin', 'post_types', 'taxonomies', 'meta_fields', 'custom_tables', 'status', 'usage_status' );
		$out = array();
		foreach ( $allowed as $k ) {
			$out[ $k ] = $row[ $k ] ?? null;
		}
		// post_types / taxonomies / meta_fields / custom_tables are longtext JSON
		// or comma-separated — keep verbatim.
		if ( isset( $out['is_content_plugin'] ) ) {
			$out['is_content_plugin'] = (int) $out['is_content_plugin'];
		}
		return $out;
	}
}
