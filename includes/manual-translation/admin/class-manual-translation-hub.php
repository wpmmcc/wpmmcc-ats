<?php
/**
 * Manual Translation Hub (P5 - entry page)
 *
 * Single entry page for all manual translation management tasks:
 *   - Translation progress overview
 *   - Pending Translations list
 *   - URL Discovery
 *   - Taxonomy Translations
 *   - Field Discovery
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

class Manual_Translation_Hub {

	const PAGE_SLUG = 'wptsall-manual';
	const CAP       = 'manage_wptsall_translations';

	public static function init() {
		// No additional hooks needed at this level; sub-pages register their own.
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Manual Translation', 'wpmmcc-ats' ),
			__( 'Manual', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$summary = Translation_Progress_Service::summary();
		$languages = Language_Service::get_all( array( 'status' => 'all' ) );
		$pending_counts = self::pending_counts_per_lang( $languages );

		Admin_Page_Helper::render_header(
			__( 'Manual Translation Management', 'wpmmcc-ats' ),
			__( 'Discover, link, and manage translations across all content types.', 'wpmmcc-ats' )
		);
		?>
		<div class="wptsall-manual-hub">
			<!-- Overview: translation progress -->
			<div class="wptsall-hub-section">
				<h2><?php esc_html_e( 'Translation Progress', 'wpmmcc-ats' ); ?></h2>
				<p>
					<strong style="font-size:1.4em;"><?php echo (int) $summary['percent']; ?>%</strong>
					<?php esc_html_e( 'overall completion', 'wpmmcc-ats' ); ?>
					(<?php echo (int) $summary['total_translated']; ?> / <?php echo (int) $summary['total_source']; ?>
					<?php esc_html_e( 'source posts translated to at least one language', 'wpmmcc-ats' ); ?>)
				</p>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Language', 'wpmmcc-ats' ); ?></th>
							<th><?php esc_html_e( 'Translated', 'wpmmcc-ats' ); ?></th>
							<th><?php esc_html_e( 'Pending', 'wpmmcc-ats' ); ?></th>
							<th><?php esc_html_e( '%', 'wpmmcc-ats' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $summary['by_language'] as $code => $info ) : ?>
						<tr>
							<td><code><?php echo esc_html( $code ); ?></code></td>
							<td><?php echo (int) $info['translated']; ?></td>
							<td><?php echo (int) ( $info['total'] - $info['translated'] ); ?></td>
							<td>
								<div style="background:#ddd;width:200px;height:14px;border-radius:7px;overflow:hidden;">
									<div style="background:linear-gradient(90deg,#46b450,#00a0d2);height:14px;width:<?php echo (int) $info['percent']; ?>%;"></div>
								</div>
								<small><?php echo (int) $info['percent']; ?>%</small>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Quick links to sub-pages -->
			<div class="wptsall-hub-section" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:24px;">
				<?php
				$cards = array(
					array(
						'icon'  => '🔗',
						'title' => __( 'URL Discovery', 'wpmmcc-ats' ),
						'desc'  => __( 'Paste a list of source URLs and let wptsall auto-discover their translation counterparts.', 'wpmmcc-ats' ),
						'url'   => add_query_arg( 'page', 'wptsall-url-discovery', admin_url( 'admin.php' ) ),
					),
					array(
						'icon'  => '📋',
						'title' => __( 'Pending Translations', 'wpmmcc-ats' ),
						'desc'  => __( 'See which source posts have no translation in a given language, then jump to create one.', 'wpmmcc-ats' ),
						'url'   => add_query_arg( 'page', 'wptsall-pending', admin_url( 'admin.php' ) ),
					),
					array(
						'icon'  => '🏷️',
						'title' => __( 'Taxonomy Translations', 'wpmmcc-ats' ),
						'desc'  => __( 'Link category, tag, or custom-taxonomy terms to their translation counterparts.', 'wpmmcc-ats' ),
						'url'   => add_query_arg( 'page', 'wptsall-tax-translations', admin_url( 'admin.php' ) ),
					),
					array(
						'icon'  => '🧩',
						'title' => __( 'Field Discovery', 'wpmmcc-ats' ),
						'desc'  => __( 'See all custom fields across post types and decide which ones to translate.', 'wpmmcc-ats' ),
						'url'   => add_query_arg( 'page', 'wptsall-field-discovery', admin_url( 'admin.php' ) ),
					),
					array(
						'icon'  => '📝',
						'title' => __( 'Strings (Layer B)', 'wpmmcc-ats' ),
						'desc'  => __( 'Menus, widgets, site title — site structure strings.', 'wpmmcc-ats' ),
						'url'   => add_query_arg( 'page', 'wptsall-strings', admin_url( 'admin.php' ) ),
					),
					array(
						'icon'  => '🎨',
						'title' => __( 'Theme & Plugin Loc (Layer C)', 'wpmmcc-ats' ),
						'desc'  => __( 'Scan gettext domains; configure client theme/plugin/config i18n.', 'wpmmcc-ats' ),
						'url'   => add_query_arg( 'page', 'wptsall-theme-plugin-loc', admin_url( 'admin.php' ) ),
					),
					array(
						'icon'  => '🧭',
						'title' => __( 'Menu Sync', 'wpmmcc-ats' ),
						'desc'  => __( 'Clone menus for virtual sites; labels sync to Strings.', 'wpmmcc-ats' ),
						'url'   => add_query_arg( 'page', 'wptsall-menu-sync', admin_url( 'admin.php' ) ),
					),
				);
				foreach ( $cards as $card ) :
				?>
				<a href="<?php echo esc_url( $card['url'] ); ?>" class="wptsall-hub-card" style="display:block;padding:16px;border:1px solid #ccd0d4;border-radius:6px;text-decoration:none;color:inherit;background:#fff;">
					<div style="font-size:2em;line-height:1;"><?php echo esc_html( $card['icon'] ); ?></div>
					<h3 style="margin:8px 0 4px;"><?php echo esc_html( $card['title'] ); ?></h3>
					<p style="margin:0;color:#646970;"><?php echo esc_html( $card['desc'] ); ?></p>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		Admin_Page_Helper::render_footer();
	}

	/**
	 * Get pending counts per language (for the dashboard widget).
	 *
	 * @since 1.4.0
	 *
	 * @param array $languages List of language rows.
	 * @return array<string,int> Map of lang_code => pending count.
	 */
	private static function pending_counts_per_lang( array $languages ): array {
		$out = array();
		foreach ( $languages as $lang ) {
			$out[ (string) $lang['code'] ] = count( Translation_Progress_Service::pending_for_language( (string) $lang['code'], '', 1000 ) );
		}
		return $out;
	}
}
