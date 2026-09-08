<?php
/**
 * User Translation Admin Page (1.3.0)
 *
 * Lists wp_wptsall_user_mappings rows with filter by source user / target
 * language. Provides a form to record a new mapping (paste source user id
 * + target user id + target lang code). Also re-registers user display_name
 * etc. for translation via the String Translation service.
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\UserTranslation\Admin;

use WPTSALL\UserTranslation\Services\User_Translation_Service;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class User_Translation_Page {

	const PAGE_SLUG = 'wptsall-users';
	const CAP       = 'manage_wptsall_translations';

	public static function init() {
		add_action( 'admin_post_wptsall_user_save',   array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wptsall_user_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'User Translations', 'wpmmcc-ats' ),
			__( 'Users', 'wpmmcc-ats' ),
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
		$counts    = User_Translation_Service::counts();
		$src_id    = isset( $_GET['src'] ) ? (int) $_GET['src'] : 0; // phpcs:ignore
		$tgt_lang  = isset( $_GET['tgt'] ) ? sanitize_text_field( wp_unslash( $_GET['tgt'] ) ) : ''; // phpcs:ignore
		$rows      = User_Translation_Service::list_mappings( array(
			'source_id'   => $src_id,
			'target_lang' => $tgt_lang,
			'limit'       => 200,
		) );

		Admin_Page_Helper::render_header(
			__( 'User Translations', 'wpmmcc-ats' ),
			/* translators: 1: total mappings, 2: source user count, 3: language count */
			sprintf( __( '%1$d mappings across %2$d source users in %3$d languages. User display_name/first_name/last_name/description are translated via String Translation on virtual-site requests.', 'wpmmcc-ats' ), $counts['total'], $counts['sources'], $counts['langs'] )
		);
		?>
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="number" name="src" value="<?php echo (int) $src_id; ?>" placeholder="<?php esc_attr_e( 'Source user ID', 'wpmmcc-ats' ); ?>" min="0">
			<select name="tgt">
				<option value=""><?php esc_html_e( 'All target langs', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( $languages as $l ) : ?>
					<option value="<?php echo esc_attr( $l['code'] ); ?>" <?php selected( $tgt_lang, $l['code'] ); ?>><?php echo esc_html( $l['code'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'wpmmcc-ats' ); ?></button>
		</form>

		<h2><?php esc_html_e( 'Add / update mapping', 'wpmmcc-ats' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_user_save' ); ?>
			<input type="hidden" name="action" value="wptsall_user_save">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Source user ID', 'wpmmcc-ats' ); ?></th>
					<td><input type="number" name="source_id" min="1" required class="small-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Target language', 'wpmmcc-ats' ); ?></th>
					<td><select name="target_lang" required>
						<?php foreach ( $languages as $l ) : ?>
							<option value="<?php echo esc_attr( $l['code'] ); ?>"><?php echo esc_html( $l['code'] ); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Target user ID', 'wpmmcc-ats' ); ?></th>
					<td><input type="number" name="target_id" min="1" required class="small-text">
					<p class="description"><?php esc_html_e( 'In single-site mode this is usually the same as the source ID; the row is informational.', 'wpmmcc-ats' ); ?></p></td>
				</tr>
			</table>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Save mapping', 'wpmmcc-ats' ); ?></button>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Existing mappings', 'wpmmcc-ats' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source user', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Target lang', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Target user', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No user mappings yet.', 'wpmmcc-ats' ); ?></td></tr>
			<?php else : foreach ( $rows as $r ) : ?>
				<tr>
					<td>#<?php echo (int) $r['source_user_id']; ?></td>
					<td><code><?php echo esc_html( (string) $r['target_site_id'] ); ?></code></td>
					<td>#<?php echo (int) $r['target_user_id']; ?></td>
					<td><?php echo esc_html( (string) $r['mapping_type'] ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete?', 'wpmmcc-ats' ) ); ?>');">
							<?php wp_nonce_field( 'wptsall_user_delete' ); ?>
							<input type="hidden" name="action" value="wptsall_user_delete">
							<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
							<button class="button button-small" type="submit"><?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_user_save' );
		$source_id   = (int) ( isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : 0 );
		$target_id   = (int) ( isset( $_POST['target_id'] ) ? sanitize_text_field( wp_unslash( $_POST['target_id'] ) ) : 0 );
		$target_lang = (string) ( isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '' );
		if ( $source_id <= 0 || $target_id <= 0 || '' === $target_lang ) {
			wp_die( esc_html__( 'Invalid input.', 'wpmmcc-ats' ) );
		}
		User_Translation_Service::record( $source_id, $target_id, $target_lang );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_user_delete' );
		User_Translation_Service::delete( (int) ( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0 ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function on_profile_update( $user_id, $old_user_data ) {
		User_Translation_Service::on_save_user( $user_id, $old_user_data );
	}
}
