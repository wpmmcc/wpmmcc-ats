<?php
/**
 * Translatable Content Types Config Page (P5-8)
 *
 * Admin UI for opting in/out of which post types and taxonomies are
 * considered "translatable" by WPTSALL. This complements the
 * wptsall_models scanner (which auto-derives candidates) by giving the
 * admin a manual override.
 *
 * The selection is stored in the `wptsall_translatable_post_types` and
 * `wptsall_translatable_taxonomies` options. Consumers (the translation
 * column, the manual hub, etc.) should read these options first and
 * fall back to the model-derived list.
 *
 * @package WPTSALL\ManualTranslation\Admin
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Models\Services\Plugin_Mapping_Service;
use WPTSALL\TranslationStatus\Translation_Status_Column;
use WPTSALL\TranslationStatus\Translation_Status_Term_Column;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Types_Config_Page {

	const PAGE_SLUG       = 'wptsall-content-types';
	const CAP             = 'manage_wptsall_settings';
	const NONCE           = 'wptsall_content_types_config';
	const OPTION_POSTS    = 'wptsall_translatable_post_types';
	const OPTION_TAXES    = 'wptsall_translatable_taxonomies';

	public static function init() {
		add_action( 'admin_post_wptsall_content_types_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wptsall-manual',
			__( 'Content Types Config', 'wpmmcc-ats' ),
			__( 'Content Types', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Effective list of translatable post types (manual override OR model-derived).
	 */
	public static function get_translatable_post_types(): array {
		$override = get_option( self::OPTION_POSTS, null );
		if ( is_array( $override ) ) {
			return array_values( array_unique( $override ) );
		}
		return Translation_Status_Column::get_managed_post_types();
	}

	public static function get_translatable_taxonomies(): array {
		$override = get_option( self::OPTION_TAXES, null );
		if ( is_array( $override ) ) {
			return array_values( array_unique( $override ) );
		}
		return Translation_Status_Term_Column::get_managed_taxonomies();
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE );
		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		$taxes      = isset( $_POST['taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['taxonomies'] ) ) : array();

		$registered_pt  = get_post_types( array( 'show_ui' => true ), 'names' );
		$registered_tx  = get_taxonomies( array( 'show_ui' => true ), 'names' );
		$post_types     = array_values( array_intersect( $post_types, array_keys( $registered_pt ) ) );
		$taxes          = array_values( array_intersect( $taxes, array_keys( $registered_tx ) ) );

		update_option( self::OPTION_POSTS, $post_types, false );
		update_option( self::OPTION_TAXES, $taxes, false );

		// Flush caches so consumers pick up the new selection.
		Translation_Status_Column::flush_cache();
		Translation_Status_Term_Column::flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'wptsall_saved' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = isset( $_GET['wptsall_saved'] );

		$all_post_types = Translation_Status_Column::get_managed_post_types();
		$all_taxonomies = Translation_Status_Term_Column::get_managed_taxonomies();
		$active_pts     = self::get_translatable_post_types();
		$active_txs     = self::get_translatable_taxonomies();

		Admin_Page_Helper::render_header(
			__( 'Translatable Content Types', 'wpmmcc-ats' ),
			__( 'Choose which post types and taxonomies the translation system should manage. Clear all to revert to the auto-derived list from wptsall_models.', 'wpmmcc-ats' )
		);
		?>
		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'wpmmcc-ats' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wptsall_content_types_save">

			<h2><?php esc_html_e( 'Post Types', 'wpmmcc-ats' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Translatable post types', 'wpmmcc-ats' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $all_post_types as $pt ) :
								$obj     = get_post_type_object( $pt );
								$label   = $obj ? $obj->labels->singular_name : $pt;
								$checked = in_array( $pt, $active_pts, true ) ? 'checked' : '';
							?>
								<label style="display:inline-block;min-width:200px;margin:2px 8px 2px 0;">
									<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt ); ?>" <?php echo esc_attr( $checked ); ?>>
									<?php echo esc_html( $label ); ?>
									<code style="color:#888;"><?php echo esc_html( $pt ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Uncheck all to use the auto-derived list (from wptsall_models).', 'wpmmcc-ats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Translatable taxonomies', 'wpmmcc-ats' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $all_taxonomies as $tx ) :
								$obj     = get_taxonomy( $tx );
								$label   = $obj ? $obj->labels->singular_name : $tx;
								$checked = in_array( $tx, $active_txs, true ) ? 'checked' : '';
							?>
								<label style="display:inline-block;min-width:200px;margin:2px 8px 2px 0;">
									<input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $tx ); ?>" <?php echo esc_attr( $checked ); ?>>
									<?php echo esc_html( $label ); ?>
									<code style="color:#888;"><?php echo esc_html( $tx ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'wpmmcc-ats' ) ); ?>
		</form>
		<?php
	}
}
