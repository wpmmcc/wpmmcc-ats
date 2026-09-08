<?php
/**
 * Custom Fields Translation Page (1.3.0)
 *
 * Lists all meta_field values registered for translation (via
 * Custom_Field_Translation_Service::on_save_post) and lets the admin
 * edit translations per language. Reuses the String_Translation_Service
 * table (context='cpt_field').
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\CustomFields\Admin;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Strings\Services\String_Translation_Service;
use WPTSALL\CustomFields\Services\Custom_Field_Translation_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Custom_Fields_Page {

	const PAGE_SLUG = 'wptsall-custom-fields';
	const CAP       = 'manage_wptsall_settings';

	public static function init() {
		add_action( 'admin_post_wptsall_cf_save',   array( __CLASS__, 'handle_save' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Custom Fields', 'wpmmcc-ats' ),
			__( 'Custom Fields', 'wpmmcc-ats' ),
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
		$ctx = Custom_Field_Translation_Service::CONTEXT;

		// List all registered strings in the cpt_field context.
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'strings' ) : $wpdb->prefix . 'wptsall_strings';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, string_key, source_text, source_lang, translations, status
			 FROM %i
			 WHERE context = %s
			 ORDER BY id DESC LIMIT 500',
			$table,
			$ctx
		), ARRAY_A );

		Admin_Page_Helper::render_header(
			__( 'Custom Field Translations', 'wpmmcc-ats' ),
			__( 'Translates custom post-type meta values registered in Models. New values are auto-registered on post save.', 'wpmmcc-ats' )
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_cf_save' ); ?>
			<input type="hidden" name="action" value="wptsall_cf_save">
			<table class="wp-list-table widefat fixed striped wptsall-cf-table">
				<thead>
					<tr>
						<th style="width:22%"><?php esc_html_e( 'Field / Post', 'wpmmcc-ats' ); ?></th>
						<th style="width:30%"><?php esc_html_e( 'Source value', 'wpmmcc-ats' ); ?></th>
						<?php foreach ( $languages as $lang ) : ?>
							<th><?php echo esc_html( $lang['code'] ); ?></th>
						<?php endforeach; ?>
						<th style="width:60px"><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo (int) ( 3 + count( $languages ) ); ?>"><?php esc_html_e( 'No custom field values registered yet. Save a post with a model-registered field to auto-populate.', 'wpmmcc-ats' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$tr = json_decode( (string) ( $r['translations'] ?? '' ), true );
					if ( ! is_array( $tr ) ) { $tr = array(); }
					// Parse key to show "field_<key>_<id>".
					$bits = explode( '_', (string) $r['string_key'], 3 );
					$bits = array_pad( $bits, 3, '' );
				?>
					<tr>
						<td>
							<code>field:<?php echo esc_html( $bits[1] ); ?></code><br>
							<small>post #<?php echo (int) $bits[2]; ?></small>
						</td>
						<td><?php echo esc_html( wp_trim_words( (string) $r['source_text'], 12, '…' ) ); ?>
							<input type="hidden" name="rows[<?php echo (int) $r['id']; ?>][source]" value="<?php echo esc_attr( $r['source_text'] ); ?>">
						</td>
						<?php foreach ( $languages as $lang ) : ?>
							<td>
								<input type="text" name="rows[<?php echo (int) $r['id']; ?>][tr][<?php echo esc_attr( $lang['code'] ); ?>]" value="<?php echo esc_attr( (string) ( $tr[ $lang['code'] ] ?? '' ) ); ?>" class="regular-text">
							</td>
						<?php endforeach; ?>
						<td><?php echo esc_html( $r['status'] ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<p>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Save Translations', 'wpmmcc-ats' ); ?></button>
			</p>
		</form>
		<p class="description"><?php esc_html_e( 'Front-end swap: WPTSALL\\CustomFields\\Services\\Custom_Field_Translation_Service::filter_meta() runs on get_post_metadata() when in a virtual-site request.', 'wpmmcc-ats' ); ?></p>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_cf_save' );
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array(); // phpcs:ignore
		$count = 0;
		foreach ( $rows as $id => $payload ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $payload ) ) {
				continue;
			}
			$tr = isset( $payload['tr'] ) && is_array( $payload['tr'] ) ? $payload['tr'] : array();
			$clean = array();
			foreach ( $tr as $code => $txt ) {
				$safe_code = sanitize_key( (string) $code );
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
}
