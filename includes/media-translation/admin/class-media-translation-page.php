<?php
/**
 * Media Translations Admin Page (1.3.0)
 *
 * Lists all media translation mappings with filter by source attachment
 * or target language. Provides a form to record a new mapping (paste the
 * source attachment id + target attachment id + target lang code).
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\MediaTranslation\Admin;

use WPTSALL\MediaTranslation\Services\Media_Translation_Service;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Translation_Page {

	const PAGE_SLUG = 'wptsall-media';
	const CAP       = 'manage_wptsall_translations';

	public static function init() {
		add_action( 'admin_post_wptsall_media_save',   array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wptsall_media_delete', array( __CLASS__, 'handle_delete' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Media Translations', 'wpmmcc-ats' ),
			__( 'Media', 'wpmmcc-ats' ),
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
		$counts    = Media_Translation_Service::counts();
		$src_id    = isset( $_GET['src'] )  ? (int) $_GET['src']  : 0; // phpcs:ignore
		$tgt_lang  = isset( $_GET['tgt'] )  ? sanitize_text_field( wp_unslash( $_GET['tgt'] ) ) : ''; // phpcs:ignore
		$rows      = Media_Translation_Service::list_mappings( array(
			'source_id'   => $src_id,
			'target_lang' => $tgt_lang,
			'limit'       => 200,
		) );

		Admin_Page_Helper::render_header(
			__( 'Media Translations', 'wpmmcc-ats' ),
			/* translators: 1: total mappings, 2: source attachment count, 3: language count */
			sprintf( __( '%1$d mappings across %2$d source attachments in %3$d languages.', 'wpmmcc-ats' ), $counts['total'], $counts['sources'], $counts['langs'] )
		);
		?>
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="number" name="src" value="<?php echo (int) $src_id; ?>" placeholder="<?php esc_attr_e( 'Source attachment ID', 'wpmmcc-ats' ); ?>" min="0">
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
			<?php wp_nonce_field( 'wptsall_media_save' ); ?>
			<input type="hidden" name="action" value="wptsall_media_save">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Source attachment ID', 'wpmmcc-ats' ); ?></th>
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
					<th><?php esc_html_e( 'Target attachment ID', 'wpmmcc-ats' ); ?></th>
					<td><input type="number" name="target_id" min="1" required class="small-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Method', 'wpmmcc-ats' ); ?></th>
					<td><select name="method">
						<option value="copy"><?php esc_html_e( 'copy', 'wpmmcc-ats' ); ?></option>
						<option value="reference"><?php esc_html_e( 'reference', 'wpmmcc-ats' ); ?></option>
					</select></td>
				</tr>
			</table>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Save mapping', 'wpmmcc-ats' ); ?></button>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Existing mappings', 'wpmmcc-ats' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Target lang', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Target', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Method', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No media mappings yet.', 'wpmmcc-ats' ); ?></td></tr>
			<?php else : foreach ( $rows as $r ) : ?>
				<tr>
					<td>
						#<?php echo (int) $r['source_media_id']; ?><br>
						<small><?php echo esc_html( (string) $r['source_file_url'] ); ?></small>
					</td>
					<td><code><?php echo esc_html( (string) ( $r['target_lang'] ?? $r['target_site_id'] ) ); ?></code></td>
					<td>
						#<?php echo (int) $r['target_media_id']; ?><br>
						<small><?php echo esc_html( (string) $r['target_file_url'] ); ?></small>
					</td>
					<td><?php echo esc_html( (string) $r['mapping_method'] ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete?', 'wpmmcc-ats' ) ); ?>');">
							<?php wp_nonce_field( 'wptsall_media_delete' ); ?>
							<input type="hidden" name="action" value="wptsall_media_delete">
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
		check_admin_referer( 'wptsall_media_save' );
		$source_id   = (int) ( isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : 0 );
		$target_id   = (int) ( isset( $_POST['target_id'] ) ? sanitize_text_field( wp_unslash( $_POST['target_id'] ) ) : 0 );
		$target_lang = (string) ( isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '' );
		$method      = (string) ( isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'copy' );
		if ( $source_id <= 0 || $target_id <= 0 || '' === $target_lang ) {
			wp_die( esc_html__( 'Invalid input.', 'wpmmcc-ats' ) );
		}
		$src_post = get_post( $source_id );
		$tgt_post = get_post( $target_id );
		if ( ! $src_post || ! $tgt_post ) {
			wp_die( esc_html__( 'Source or target attachment not found.', 'wpmmcc-ats' ) );
		}
		$src_url  = (string) wp_get_attachment_url( $source_id );
		$src_path = (string) get_attached_file( $source_id );
		$tgt_url  = (string) wp_get_attachment_url( $target_id );
		$tgt_path = (string) get_attached_file( $target_id );
		Media_Translation_Service::record( $source_id, $src_url, $src_path, $target_id, $tgt_url, $tgt_path, $target_lang, $method );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_media_delete' );
		Media_Translation_Service::delete( (int) ( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0 ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
