<?php
/**
 * Theme & Plugin Localization admin page (Layer C).
 *
 * @package WPTSALL\Templates\Admin
 * @since 2.3.0
 */

namespace WPTSALL\Templates\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Relation_Config_Service;
use WPTSALL\Templates\Scanners\Language_Pack_Scanner;
use WPTSALL\Templates\Services\Template_Service;
use WPTSALL\Strings\Services\Site_String_Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme_Plugin_Localization_Page class.
 */
class Theme_Plugin_Localization_Page {

	const PAGE_SLUG = 'wptsall-theme-plugin-loc';
	const CAP       = 'manage_wptsall_translations';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_wptsall_scan_i18n', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_wptsall_save_i18n_config', array( __CLASS__, 'handle_save_config' ) );
		add_action( 'admin_post_wptsall_scan_site_strings', array( __CLASS__, 'handle_scan_site_strings' ) );
	}

	/**
	 * Register submenu.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Theme & Plugin Localization', 'wpmmcc-ats' ),
			__( 'Theme & Plugin Loc', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$relations = Site_Relation_Service::get_all_relations( array( 'status' => 'active' ) );
		$relation_id = isset( $_GET['relation_id'] ) ? (int) $_GET['relation_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $relation_id <= 0 && ! empty( $relations ) ) {
			$relation_id = (int) ( $relations[0]['id'] ?? 0 );
		}
		$config = $relation_id > 0 ? Relation_Config_Service::get_template_config( $relation_id ) : array();
		$theme  = wp_get_theme();
		$plugins = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		Admin_Page_Helper::render_header(
			__( 'Theme & Plugin Localization', 'wpmmcc-ats' ),
			__( 'Layer C: scan gettext domains for theme/plugins and manage Layer B site strings separately.', 'wpmmcc-ats' )
		);

		if ( isset( $_GET['scanned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Language pack scan completed.', 'wpmmcc-ats' ) . '</p></div>';
		}
		if ( isset( $_GET['site_strings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Site structure strings registered.', 'wpmmcc-ats' ) . '</p></div>';
		}
		?>
		<p class="description"><?php esc_html_e( 'CPT pages = Models; menu/widget/site title = Strings; theme/plugin buttons = here (gettext).', 'wpmmcc-ats' ); ?></p>

		<h2><?php esc_html_e( 'Relation & switches', 'wpmmcc-ats' ); ?></h2>
		<form method="get" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label for="relation_id"><?php esc_html_e( 'Site relation', 'wpmmcc-ats' ); ?></label>
			<select name="relation_id" id="relation_id" onchange="this.form.submit()">
				<?php foreach ( $relations as $rel ) : ?>
					<option value="<?php echo (int) $rel['id']; ?>" <?php selected( $relation_id, (int) $rel['id'] ); ?>>
						<?php echo esc_html( '#' . $rel['id'] . ' ' . ( $rel['target_lang'] ?? '' ) . ' → ' . ( $rel['target_site_id'] ?? '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php if ( $relation_id > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;margin-bottom:24px;">
			<?php wp_nonce_field( 'wptsall_save_i18n_config' ); ?>
			<input type="hidden" name="action" value="wptsall_save_i18n_config">
			<input type="hidden" name="relation_id" value="<?php echo (int) $relation_id; ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Client translation', 'wpmmcc-ats' ); ?></th>
					<td>
						<label><input type="checkbox" name="translate_theme_i18n" value="1" <?php checked( ! empty( $config['translate_theme_i18n'] ) ); ?>> <?php esc_html_e( 'Theme gettext (theme_i18n)', 'wpmmcc-ats' ); ?></label><br>
						<label><input type="checkbox" name="translate_plugin_i18n" value="1" <?php checked( ! empty( $config['translate_plugin_i18n'] ) ); ?>> <?php esc_html_e( 'Plugin gettext (plugin_i18n)', 'wpmmcc-ats' ); ?></label><br>
						<label><input type="checkbox" name="translate_config_i18n" value="1" <?php checked( ! empty( $config['translate_config_i18n'] ) ); ?>> <?php esc_html_e( 'Config/reusable blocks (config_i18n)', 'wpmmcc-ats' ); ?></label><br>
						<label><input type="checkbox" name="translate_site_strings" value="1" <?php checked( ! empty( $config['translate_site_strings'] ) ); ?>> <?php esc_html_e( 'Site title/tagline (site_strings)', 'wpmmcc-ats' ); ?></label><br>
						<label><input type="checkbox" name="translate_menu_strings" value="1" <?php checked( ! empty( $config['translate_menu_strings'] ) ); ?>> <?php esc_html_e( 'Menu labels (menu_strings)', 'wpmmcc-ats' ); ?></label><br>
						<label><input type="checkbox" name="translate_widget_strings" value="1" <?php checked( ! empty( $config['translate_widget_strings'] ) ); ?>> <?php esc_html_e( 'Widget text (widget_strings)', 'wpmmcc-ats' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><label for="gettext_domain_whitelist"><?php esc_html_e( 'Gettext domain whitelist', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="3" name="gettext_domain_whitelist" id="gettext_domain_whitelist" placeholder="memberpress, woocommerce"><?php echo esc_textarea( implode( ', ', (array) ( $config['gettext_domain_whitelist'] ?? array() ) ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional: only scan/translate these domains (comma-separated). Empty = all active theme/plugin domains.', 'wpmmcc-ats' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save i18n settings', 'wpmmcc-ats' ) ); ?>
		</form>

		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'wptsall_scan_i18n' ); ?>
				<input type="hidden" name="action" value="wptsall_scan_i18n">
				<input type="hidden" name="relation_id" value="<?php echo (int) $relation_id; ?>">
				<?php submit_button( __( 'Scan theme & plugin strings', 'wpmmcc-ats' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
				<?php wp_nonce_field( 'wptsall_scan_site_strings' ); ?>
				<input type="hidden" name="action" value="wptsall_scan_site_strings">
				<?php submit_button( __( 'Scan site structure strings (Layer B)', 'wpmmcc-ats' ), 'secondary', 'submit', false ); ?>
			</form>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-strings' ) ); ?>"><?php esc_html_e( 'Edit Strings', 'wpmmcc-ats' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-templates&relation_id=' . $relation_id ) ); ?>"><?php esc_html_e( 'Templates / manual gettext', 'wpmmcc-ats' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-tasks' ) ); ?>"><?php esc_html_e( 'Tasks', 'wpmmcc-ats' ); ?></a>
		</p>

		<?php
		$templates = Template_Service::get_by_relation( $relation_id );
		?>
		<h2><?php esc_html_e( 'Scan progress (Layer C templates)', 'wpmmcc-ats' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<thead><tr><th><?php esc_html_e( 'Domain', 'wpmmcc-ats' ); ?></th><th><?php esc_html_e( 'Type', 'wpmmcc-ats' ); ?></th><th><?php esc_html_e( 'Entries', 'wpmmcc-ats' ); ?></th><th><?php esc_html_e( 'Translated', 'wpmmcc-ats' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $templates ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No templates yet — run a scan.', 'wpmmcc-ats' ); ?></td></tr>
			<?php else : foreach ( $templates as $tpl ) : ?>
				<tr>
					<td><code><?php echo esc_html( $tpl['text_domain'] ?? '' ); ?></code></td>
					<td><?php echo esc_html( $tpl['source_type'] ?? '' ); ?></td>
					<td><?php echo (int) ( $tpl['total_entries'] ?? 0 ); ?></td>
					<td><?php echo (int) ( $tpl['translated_entries'] ?? 0 ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Active theme & plugins', 'wpmmcc-ats' ); ?></h2>
		<ul>
			<li><strong><?php echo esc_html( $theme->get( 'Name' ) ); ?></strong> — <code><?php echo esc_html( $theme->get_template() ); ?></code></li>
			<?php foreach ( $active_plugins as $plugin_file ) :
				if ( ! isset( $plugins[ $plugin_file ] ) ) {
					continue;
				}
				$pdata = $plugins[ $plugin_file ];
				?>
				<li><?php echo esc_html( $pdata['Name'] ?? $plugin_file ); ?> — <code><?php echo esc_html( dirname( $plugin_file ) ); ?></code></li>
			<?php endforeach; ?>
		</ul>
		<?php
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Run language pack scan for relation.
	 *
	 * @return void
	 */
	public static function handle_scan() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_scan_i18n' );
		$relation_id = (int) ( $_POST['relation_id'] ?? 0 );
		if ( $relation_id > 0 ) {
			Language_Pack_Scanner::scan_relation( $relation_id, Language_Pack_Scanner::SCAN_ALL );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'relation_id' => $relation_id, 'scanned' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Register Layer B strings.
	 *
	 * @return void
	 */
	public static function handle_scan_site_strings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_scan_site_strings' );
		Site_String_Scanner::scan_all();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'site_strings' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save relation i18n config.
	 *
	 * @return void
	 */
	public static function handle_save_config() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_save_i18n_config' );
		$relation_id = (int) ( $_POST['relation_id'] ?? 0 );
		$whitelist_raw = sanitize_textarea_field( wp_unslash( (string) ( $_POST['gettext_domain_whitelist'] ?? '' ) ) );
		$whitelist = array_values( array_filter( array_map( 'trim', explode( ',', $whitelist_raw ) ) ) );
		$config = array(
			'translate_theme_i18n'    => ! empty( $_POST['translate_theme_i18n'] ),
			'translate_plugin_i18n'   => ! empty( $_POST['translate_plugin_i18n'] ),
			'translate_config_i18n'   => ! empty( $_POST['translate_config_i18n'] ),
			'translate_site_strings'  => ! empty( $_POST['translate_site_strings'] ),
			'translate_menu_strings'  => ! empty( $_POST['translate_menu_strings'] ),
			'translate_widget_strings'=> ! empty( $_POST['translate_widget_strings'] ),
			'gettext_domain_whitelist'=> $whitelist,
		);
		if ( $relation_id > 0 ) {
			Relation_Config_Service::save_template_config( $relation_id, $config );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'relation_id' => $relation_id, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
