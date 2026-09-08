<?php
/**
 * Field Discovery Page (P5-6)
 *
 * Lists all meta keys for a chosen post type and lets the admin mark
 * each as "translatable" or "sync only". Persists decisions to
 * wptsall_field_translations option.
 *
 * @package WPTSALL\ManualTranslation\Admin
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\ManualTranslation\Services\Field_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Field_Discovery_Page {

	const PAGE_SLUG = 'wptsall-field-discovery';
	const CAP       = 'manage_wptsall_settings';
	const NONCE     = 'wptsall_field_discovery';

	public static function init() {
		add_action( 'admin_post_wptsall_field_discovery_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wptsall-manual',
			__( 'Field Discovery', 'wpmmcc-ats' ),
			__( 'Field Discovery', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$fields = Field_Translation_Service::discover_for_post_type( $post_type, 200 );

		Admin_Page_Helper::render_header(
			__( 'Field Discovery', 'wpmmcc-ats' ),
			sprintf(
				/* translators: %s: post type label */
				__( 'Custom fields (meta keys) discovered in %s. Mark each as "Translatable" to make wptsall swap its value when in a virtual-site request.', 'wpmmcc-ats' ),
				$post_types[ $post_type ]->labels->name ?? $post_type
			)
		);
		?>
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label><?php esc_html_e( 'Post type', 'wpmmcc-ats' ); ?>:
				<select name="post_type" onchange="this.form.submit()">
					<?php foreach ( $post_types as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $post_type, $pt->name ); ?>><?php echo esc_html( $pt->labels->name ?? $pt->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</form>

		<?php if ( empty( $fields ) ) : ?>
			<p><?php esc_html_e( 'No meta keys found for this post type yet. Save a post with custom fields to populate.', 'wpmmcc-ats' ); ?></p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wptsall_field_discovery_save">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Meta key', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Posts using', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Sample value', 'wpmmcc-ats' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'Translatable', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $fields as $f ) : ?>
					<tr>
						<td><code><?php echo esc_html( $f['key'] ); ?></code></td>
						<td><?php echo (int) $f['count']; ?></td>
						<td><code style="font-size:0.9em;"><?php echo esc_html( $f['sample'] ); ?></code></td>
						<td>
							<?php if ( ! empty( $f['blocked'] ) ) : ?>
								<span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'Code/script fields are not translatable', 'wpmmcc-ats' ); ?>"></span>
								<span class="description"><?php esc_html_e( 'Blocked (code)', 'wpmmcc-ats' ); ?></span>
							<?php else : ?>
							<label>
								<input type="checkbox" name="fields[<?php echo esc_attr( $f['key'] ); ?>]" value="1" <?php checked( $f['translatable'] ); ?>>
								<?php esc_html_e( 'Translate', 'wpmmcc-ats' ); ?>
							</label>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Save Field Translation Config', 'wpmmcc-ats' ); ?></button>
			</p>
		</form>
		<?php endif;
		Admin_Page_Helper::render_footer();
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE );
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( (string) $_POST['post_type'] ) : ''; // phpcs:ignore
		$fields_in = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( (array) $_POST['fields'] ) : array(); // phpcs:ignore
		$out = array();
		foreach ( $fields_in as $key => $_ ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || mb_strlen( $key ) > 191 ) {
				continue;
			}
			if ( Field_Translation_Service::is_code_like_key( $key ) ) {
				continue;
			}
			$out[ $key ] = array(
				'translatable' => true,
				'sync'         => false,
			);
		}
		// Preserve "off" keys (translatable=false) by leaving them absent in $out — they default to off.
		// The page also has a "Sample value" so admin can see what they are toggling.
		Field_Translation_Service::save_for_post_type( $post_type, $out );
		wp_safe_redirect( add_query_arg( array(
			'page'        => self::PAGE_SLUG,
			'post_type'   => $post_type,
			'wptsall_msg' => 'saved',
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
