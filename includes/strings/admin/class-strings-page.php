<?php
/**
 * Strings Admin Page
 *
 * Polylang-style string translation table: source text on the left, one
 * translation input per active language on the right.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\Strings\Admin;

use WPTSALL\Strings\Services\String_Translation_Service;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Strings_Page {

	const PAGE_SLUG = 'wptsall-strings';
	const CAP       = 'manage_wptsall_translations';
	const NONCE_SAVE   = 'wptsall_save_strings';
	const NONCE_DELETE = 'wptsall_delete_string';

	public static function init() {
		add_action( 'admin_post_wptsall_strings_save',   array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wptsall_string_delete',  array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_wptsall_strings_scan',   array( __CLASS__, 'handle_scan' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'String Translations', 'wpmmcc-ats' ),
			__( 'Strings', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$languages = Language_Service::get_all();
		$counts    = String_Translation_Service::counts();
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$context   = isset( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : ''; // phpcs:ignore
		$status    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore
		$rows      = String_Translation_Service::list( array(
			'search'  => $search,
			'context' => $context,
			'status'  => $status,
			'limit'   => 200,
		) );
		$labels    = String_Translation_Service::context_labels();

		Admin_Page_Helper::render_header(
			__( 'String Translations', 'wpmmcc-ats' ),
			sprintf(
				'%d registered, %d translated, %d pending.',
				(int) $counts['total'], (int) $counts['translated'], (int) $counts['pending']
			)
		);

		// UI-28-01/02: visible feedback for the write paths' redirect flags.
		$scanned = isset( $_GET['scanned'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deleted = isset( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $scanned || $updated || $deleted ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				if ( $scanned ) {
					esc_html_e( 'Scan complete.', 'wpmmcc-ats' );
				} elseif ( $updated ) {
					echo esc_html(
						sprintf(
							/* translators: %d: number of saved strings. */
							__( 'Saved translations for %d strings.', 'wpmmcc-ats' ),
							max( 0, (int) $_GET['updated'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						)
					);
				} else {
					esc_html_e( 'String deleted.', 'wpmmcc-ats' );
				}
				?>
			</p></div>
		<?php endif;

		// Register site_title automatically so it shows up.
		$blogname = get_option( 'blogname' );
		if ( is_string( $blogname ) && '' !== $blogname ) {
			String_Translation_Service::register( 'site_title', 'blogname', $blogname );
		}
		$blogdescription = get_option( 'blogdescription' );
		if ( is_string( $blogdescription ) && '' !== $blogdescription ) {
			String_Translation_Service::register( 'site_tagline', 'blogdescription', $blogdescription );
		}
		if ( $blogname || $blogdescription ) {
			// Reload after potential inserts.
			$counts = String_Translation_Service::counts();
			$rows   = String_Translation_Service::list( array( 'search' => $search, 'context' => $context, 'limit' => 200 ) );
		}
		?>
		<form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<select name="context">
				<option value=""><?php esc_html_e( 'All contexts', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( $labels as $ctx => $label ) : ?>
					<option value="<?php echo esc_attr( $ctx ); ?>" <?php selected( $context, $ctx ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All statuses', 'wpmmcc-ats' ); ?></option>
				<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'wpmmcc-ats' ); ?></option>
				<option value="translated" <?php selected( $status, 'translated' ); ?>><?php esc_html_e( 'Translated', 'wpmmcc-ats' ); ?></option>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search source text or key…', 'wpmmcc-ats' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'wpmmcc-ats' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
			<?php wp_nonce_field( 'wptsall_strings_scan' ); ?>
			<input type="hidden" name="action" value="wptsall_strings_scan">
			<button class="button button-secondary" type="submit"><?php esc_html_e( 'Scan menus/widgets/site', 'wpmmcc-ats' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wptsall_strings_save">
			<?php wp_nonce_field( self::NONCE_SAVE ); ?>
			<table class="wp-list-table widefat fixed striped wptsall-strings-table">
				<thead>
					<tr>
						<th style="width:18%"><?php esc_html_e( 'Context / Key', 'wpmmcc-ats' ); ?></th>
						<th style="width:30%"><?php esc_html_e( 'Source', 'wpmmcc-ats' ); ?></th>
						<?php foreach ( $languages as $lang ) : ?>
							<th style="width:<?php echo (int) ( 52 / max( 1, count( $languages ) ) ); ?>%"><?php echo esc_html( $lang['code'] ); ?></th>
						<?php endforeach; ?>
						<th style="width:60px"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
						<th style="width:60px"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo (int) ( 4 + count( $languages ) ); ?>"><?php esc_html_e( 'No strings registered yet. Site title and tagline are auto-registered on this page.', 'wpmmcc-ats' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$translations = json_decode( (string) ( $r['translations'] ?? '' ), true );
					if ( ! is_array( $translations ) ) { $translations = array(); }
				?>
					<tr>
						<td>
							<code><?php echo esc_html( $r['context'] ); ?></code><br>
							<small><?php echo esc_html( $r['string_key'] ); ?></small>
						</td>
						<td>
							<?php echo esc_html( $r['source_text'] ); ?>
							<input type="hidden" name="rows[<?php echo (int) $r['id']; ?>][source]" value="<?php echo esc_attr( $r['source_text'] ); ?>">
						</td>
						<?php foreach ( $languages as $lang ) : ?>
							<td>
								<?php
								// UI-28-08: keys saved before the case-preserving fix are
								// lowercase (sanitize_key side effect) — fall back for them.
								$tr_val = $translations[ $lang['code'] ] ?? $translations[ strtolower( $lang['code'] ) ] ?? '';
								?>
								<input type="text" name="rows[<?php echo (int) $r['id']; ?>][tr][<?php echo esc_attr( $lang['code'] ); ?>]" value="<?php echo esc_attr( (string) $tr_val ); ?>" class="regular-text">
							</td>
						<?php endforeach; ?>
						<td>
							<span class="wptsall-status-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span>
						</td>
						<?php
						// UI-28-01: this used to be a nested <form> INSIDE the
						// outer save form — invalid HTML. The parser drops the
						// inner <form> tags, so 200 delete action/id hidden
						// inputs landed inside the save form and PHP's
						// last-wins duplicate-scalar rule turned EVERY submit
						// (save or delete) into handle_delete(last row) —
						// "Save Translations" deleted data. Row action is now
						// a WP-core style GET link (nonce via wp_nonce_url),
						// and handle_delete accepts GET.
						$delete_url = wp_nonce_url(
							add_query_arg(
								array(
									'action' => 'wptsall_string_delete',
									'id'     => (int) $r['id'],
								),
								admin_url( 'admin-post.php' )
							),
							self::NONCE_DELETE
						);
						?>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this string?', 'wpmmcc-ats' ) ); ?>');"><?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?></a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<p>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Save Translations', 'wpmmcc-ats' ); ?></button>
			</p>
		</form>
		<hr>
		<h2><?php esc_html_e( 'Use the wptsall_translate_string filter', 'wpmmcc-ats' ); ?></h2>
		<p><?php esc_html_e( 'Once you have registered a string above, you can fetch its translation in PHP via:', 'wpmmcc-ats' ); ?></p>
		<pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;max-width:760px;">echo WPTSALL\Strings\Services\String_Translation_Service::translate( 'site_title', 'blogname', 'Default title', 'en_US' );</pre>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE_SAVE );
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$count = 0;
		foreach ( $rows as $id => $payload ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $payload ) ) {
				continue;
			}
			$tr = isset( $payload['tr'] ) && is_array( $payload['tr'] ) ? $payload['tr'] : array();
			$clean = array();
			foreach ( $tr as $code => $txt ) {
				// UI-28-08: sanitize_key() lowercases the code (en_US → en_us),
				// but every reader (this page's render, String_Translation_Service
				// ::translate(), REST consumers) looks up the Language_Service
				// code in canonical mixed case — so saved values could never be
				// displayed or served back. Locale codes are [A-Za-z0-9_-]; keep
				// the case, drop anything else.
				$safe_code = preg_match( '/^[A-Za-z0-9_-]{1,20}$/', (string) $code ) ? (string) $code : '';
				if ( '' === $safe_code ) {
					continue;
				}
				$clean[ $safe_code ] = sanitize_text_field( (string) $txt );
			}
			if ( String_Translation_Service::set_translations( $id, $clean ) ) {
				$count++;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE_DELETE );
		// UI-28-01: the row Delete control is now a GET link (nonce in URL),
		// so accept the id from either channel.
		String_Translation_Service::delete( (int) ( isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : 0 ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_scan() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_strings_scan' );
		if ( class_exists( '\\WPTSALL\\Strings\\Services\\Site_String_Scanner' ) ) {
			\WPTSALL\Strings\Services\Site_String_Scanner::scan_all();
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'scanned' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
