<?php
/**
 * Pending Translations Page (P5-3)
 *
 * Lists all source posts that have no translation in the selected target
 * language. Provides a "Translate now" action that opens the translation
 * editor.
 *
 * @package WPTSALL\ManualTranslation\Admin
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\ManualTranslation\Services\Translation_Progress_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pending_Translations_Page {

	const PAGE_SLUG = 'wptsall-pending';
	const CAP       = 'manage_wptsall_translations';

	public static function init() {
		// No additional hooks.
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wptsall-manual',
			__( 'Pending Translations', 'wpmmcc-ats' ),
			__( 'Pending Translations', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$languages = Language_Service::get_all( array( 'status' => 'active' ) );
		if ( empty( $languages ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No active languages configured. Add languages first.', 'wpmmcc-ats' ) . '</p></div>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target_lang = isset( $_GET['target_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['target_lang'] ) ) : ( $languages[0]['code'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';

		$pending = $target_lang ? Translation_Progress_Service::pending_for_language( $target_lang, $post_type, 500 ) : array();

		Admin_Page_Helper::render_header(
			__( 'Pending Translations', 'wpmmcc-ats' ),
			__( 'Source posts that do not yet have a translation in the target language. Click "Translate" to open the translation editor.', 'wpmmcc-ats' )
		);
		?>
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label><?php esc_html_e( 'Target language', 'wpmmcc-ats' ); ?>:
				<select name="target_lang" onchange="this.form.submit()">
					<?php foreach ( $languages as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $target_lang, $lang['code'] ); ?>>
							<?php echo esc_html( $lang['code'] ); ?> — <?php echo esc_html( $lang['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="margin-left:12px;"><?php esc_html_e( 'Post type', 'wpmmcc-ats' ); ?>:
				<select name="post_type" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All', 'wpmmcc-ats' ); ?></option>
					<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $post_type, $pt->name ); ?>><?php echo esc_html( $pt->labels->name ?? $pt->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</form>

		<p>
			<strong><?php echo count( $pending ); ?></strong> <?php esc_html_e( 'posts pending translation', 'wpmmcc-ats' ); ?>
			<?php if ( $target_lang ) : ?>
				<?php esc_html_e( 'into', 'wpmmcc-ats' ); ?> <code><?php echo esc_html( $target_lang ); ?></code>
			<?php endif; ?>
		</p>

		<?php if ( empty( $pending ) ) : ?>
			<p><?php esc_html_e( 'No pending posts. Everything is translated! 🎉', 'wpmmcc-ats' ); ?></p>
		<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'ID', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Title', 'wpmmcc-ats' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
					<th style="width:140px;"><?php esc_html_e( 'Date', 'wpmmcc-ats' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $pending as $p ) : ?>
				<tr>
					<td><?php echo (int) $p['ID']; ?></td>
					<td>
						<?php if ( $p['edit_link'] ) : ?>
							<a href="<?php echo esc_url( $p['edit_link'] ); ?>"><?php echo esc_html( $p['post_title'] ?: sprintf( '#%d', (int) $p['ID'] ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $p['post_title'] ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $p['post_type'] ); ?></td>
					<td><?php echo esc_html( $p['post_status'] ); ?></td>
					<td><?php echo esc_html( mysql2date( 'Y-m-d', (string) $p['post_date'] ) ); ?></td>
					<td>
						<?php
						$translate_args = array(
							'page'           => 'wptsall-translate',
							'source_post_id' => (int) $p['ID'],
						);
						if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Resolver' ) ) {
							$rid = \WPTSALL\Sites\Services\Relation_Resolver::for_post_lang( (int) $p['ID'], (string) $target_lang );
							if ( $rid > 0 ) {
								$translate_args['relation_id'] = $rid;
							}
						}
						?>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( $translate_args, admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Translate', 'wpmmcc-ats' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif;
		Admin_Page_Helper::render_footer();
	}
}
