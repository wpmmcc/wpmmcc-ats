<?php
/**
 * Languages Admin Page
 *
 * Top-level submenu for managing WPTSALL languages.
 * Decoupled from site_relations.target_lang.
 *
 * @package WPTSALL
 * @since 1.2.0
  * phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin list/render $_GET filters; state-changing handlers use check_admin_referer / check_ajax_referer.
 */
namespace WPTSALL\Languages\Admin;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Languages_Page class.
 */
class Languages_Page {

	const PAGE_SLUG = 'wptsall-languages';
	const CAP       = 'manage_wptsall_settings';

	public static function init() {
		add_action( 'admin_post_wptsall_language_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wptsall_language_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_wptsall_language_set_default', array( __CLASS__, 'handle_set_default' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Languages', 'wpmmcc-ats' ),
			__( 'Languages', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'edit' === $action || 'add' === $action ) {
			self::render_edit_form( $action );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$rows = Language_Service::get_all( array( 'status' => 'all' ) );
		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		Admin_Page_Helper::render_header(
			__( 'Languages', 'wpmmcc-ats' ),
			__( 'Manage site languages used by site relations, string translation, and language switcher.', 'wpmmcc-ats' )
		);
		?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'action', 'add', $base_url ) ); ?>">
				<?php esc_html_e( 'Add Language', 'wpmmcc-ats' ); ?>
			</a>
		</p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Name', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Native', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Locale', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Flag', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Default', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="9"><?php esc_html_e( 'No languages yet.', 'wpmmcc-ats' ); ?></td></tr>
			<?php else : foreach ( $rows as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( $r['code'] ); ?></code></td>
					<td><code><?php echo esc_html( $r['slug'] ); ?></code></td>
					<td><?php echo esc_html( $r['name'] ); ?></td>
					<td><?php echo esc_html( $r['native_name'] ); ?></td>
					<td><?php echo esc_html( $r['locale'] ); ?></td>
					<td><?php echo esc_html( $r['flag'] ); ?></td>
					<td>
						<?php if ( ! empty( $r['is_default'] ) ) : ?>
							<span class="dashicons dashicons-star-filled" aria-label="<?php esc_attr_e( 'Default', 'wpmmcc-ats' ); ?>"></span>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'wptsall_set_default_lang' ); ?>
								<input type="hidden" name="action" value="wptsall_language_set_default">
								<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
								<button class="button button-small" type="submit"><?php esc_html_e( 'Make default', 'wpmmcc-ats' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $r['status'] ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'action' => 'edit', 'id' => (int) $r['id'] ), $base_url ) ); ?>">
							<?php esc_html_e( 'Edit', 'wpmmcc-ats' ); ?>
						</a>
						<?php if ( empty( $r['is_default'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this language?', 'wpmmcc-ats' ) ); ?>');">
							<?php wp_nonce_field( 'wptsall_delete_lang' ); ?>
							<input type="hidden" name="action" value="wptsall_language_delete">
							<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
							<button class="button button-small" type="submit"><?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?></button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
		Admin_Page_Helper::render_footer();
	}

	private static function render_edit_form( $action ) {
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$row  = array(
			'code' => '', 'slug' => '', 'name' => '', 'native_name' => '',
			'locale' => '', 'flag' => '', 'direction' => 'ltr',
			'sort_order' => 0, 'is_default' => 0, 'status' => 'active',
		);
		if ( 'edit' === $action && $id > 0 ) {
			$existing = Language_Service::get_by_code( '' );
			// find by id
			$all = Language_Service::get_all( array( 'status' => 'all' ) );
			foreach ( $all as $r ) {
				if ( (int) $r['id'] === $id ) { $row = array_merge( $row, $r ); break; }
			}
		}
		Admin_Page_Helper::render_header(
			'edit' === $action ? __( 'Edit Language', 'wpmmcc-ats' ) : __( 'Add Language', 'wpmmcc-ats' ),
			''
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_save_lang' ); ?>
			<input type="hidden" name="action" value="wptsall_language_save">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<table class="form-table">
				<tr>
					<th><label for="code"><?php esc_html_e( 'Code', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="code" id="code" type="text" class="regular-text" required value="<?php echo esc_attr( $row['code'] ); ?>" placeholder="en_US">
						<p class="description"><?php esc_html_e( 'BCP-47 / WP locale code (e.g. en_US, zh_CN, de_DE).', 'wpmmcc-ats' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="slug"><?php esc_html_e( 'URL Slug', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="slug" id="slug" type="text" class="regular-text" value="<?php echo esc_attr( $row['slug'] ); ?>" placeholder="en-us">
						<p class="description"><?php esc_html_e( 'Used in virtual site URL prefix. Defaults to lowercased code with underscore -> dash.', 'wpmmcc-ats' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="name"><?php esc_html_e( 'Name (English)', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="name" id="name" type="text" class="regular-text" required value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="English (United States)"></td>
				</tr>
				<tr>
					<th><label for="native_name"><?php esc_html_e( 'Native Name', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="native_name" id="native_name" type="text" class="regular-text" value="<?php echo esc_attr( $row['native_name'] ); ?>" placeholder="English"></td>
				</tr>
				<tr>
					<th><label for="locale"><?php esc_html_e( 'WordPress Locale', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="locale" id="locale" type="text" class="regular-text" value="<?php echo esc_attr( $row['locale'] ); ?>" placeholder="en_US">
						<p class="description"><?php esc_html_e( 'Used to load .mo files. Defaults to Code if empty.', 'wpmmcc-ats' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="flag"><?php esc_html_e( 'Flag', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="flag" id="flag" type="text" class="regular-text" value="<?php echo esc_attr( $row['flag'] ); ?>" placeholder="us.png">
						<p class="description"><?php esc_html_e( 'Filename under Polylang-style /wp-content/plugins/wpmmcc-ats/assets/flags/ (optional).', 'wpmmcc-ats' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="direction"><?php esc_html_e( 'Direction', 'wpmmcc-ats' ); ?></label></th>
					<td><select name="direction" id="direction">
						<option value="ltr" <?php selected( $row['direction'], 'ltr' ); ?>><?php esc_html_e( 'LTR', 'wpmmcc-ats' ); ?></option>
						<option value="rtl" <?php selected( $row['direction'], 'rtl' ); ?>><?php esc_html_e( 'RTL', 'wpmmcc-ats' ); ?></option>
					</select></td>
				</tr>
				<tr>
					<th><label for="sort_order"><?php esc_html_e( 'Sort Order', 'wpmmcc-ats' ); ?></label></th>
					<td><input name="sort_order" id="sort_order" type="number" class="small-text" value="<?php echo (int) $row['sort_order']; ?>"></td>
				</tr>
				<tr>
					<th><label for="status"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></label></th>
					<td><select name="status" id="status">
						<option value="active" <?php selected( $row['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'wpmmcc-ats' ); ?></option>
						<option value="inactive" <?php selected( $row['status'], 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'wpmmcc-ats' ); ?></option>
					</select></td>
				</tr>
				<tr>
					<th><label for="is_default"><?php esc_html_e( 'Default', 'wpmmcc-ats' ); ?></label></th>
					<td><label><input type="checkbox" name="is_default" id="is_default" value="1" <?php checked( $row['is_default'], 1 ); ?>> <?php esc_html_e( 'Use as the default site language', 'wpmmcc-ats' ); ?></label></td>
				</tr>
			</table>
			<p>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Save', 'wpmmcc-ats' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'wpmmcc-ats' ); ?></a>
			</p>
		</form>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_save_lang' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$data = array(
			'id'          => $id,
			'code'        => sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ),
			'slug'        => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'native_name' => sanitize_text_field( wp_unslash( $_POST['native_name'] ?? '' ) ),
			'locale'      => sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) ),
			'flag'        => sanitize_file_name( wp_unslash( $_POST['flag'] ?? '' ) ),
			'direction'   => sanitize_key( wp_unslash( $_POST['direction'] ?? 'ltr' ) ),
			'sort_order'  => (int) ( isset( $_POST['sort_order'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_order'] ) ) : 0 ),
			'is_default'  => ! empty( $_POST['is_default'] ) ? 1 : 0,
			'status'      => in_array( sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ), array( 'active', 'inactive' ), true ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active',
		);
		$result = Language_Service::upsert( $data );
		if ( $data['is_default'] && $result ) {
			Language_Service::set_default( $result );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_delete_lang' );
		$id = (int) ( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0 );
		$res = Language_Service::delete( $id );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'error' => $res->get_error_code() ), admin_url( 'admin.php' ) ) );
		} else {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		}
		exit;
	}

	public static function handle_set_default() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_set_default_lang' );
		Language_Service::set_default( (int) ( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0 ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
