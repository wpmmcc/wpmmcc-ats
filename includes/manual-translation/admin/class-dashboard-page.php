<?php
/**
 * Translation Dashboard — progress + needs_resync aggregation.
 *
 * @package WPTSALL\ManualTranslation\Admin
 * @since 2.2.0
 */

namespace WPTSALL\ManualTranslation\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\ManualTranslation\Services\Translation_Progress_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard_Page class.
 */
class Dashboard_Page {

	const PAGE_SLUG = 'wptsall-dashboard';
	const CAP       = 'manage_wptsall_translations';

	/**
	 * @return void
	 */
	public static function init() {
		// no-op
	}

	/**
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Translation Dashboard', 'wpmmcc-ats' ),
			__( 'Dashboard', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$summary = class_exists( Translation_Progress_Service::class )
			? Translation_Progress_Service::summary()
			: array();
		$layers  = class_exists( Translation_Progress_Service::class )
			? Translation_Progress_Service::layer_summary()
			: array();
		$resync  = self::count_needs_resync();
		$rels    = class_exists( Site_Relation_Service::class )
			? Site_Relation_Service::get_all_relations( array( 'status' => 'active' ) )
			: array();

		Admin_Page_Helper::render_header(
			__( 'Translation Dashboard', 'wpmmcc-ats' ),
			__( 'Progress by language / post type and mappings marked needs_resync.', 'wpmmcc-ats' )
		);
		?>
		<div class="wptsall-dashboard" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:12px 0 20px;">
			<div class="card" style="padding:12px;margin:0;">
				<strong><?php esc_html_e( 'Source items', 'wpmmcc-ats' ); ?></strong>
				<p style="font-size:28px;margin:8px 0 0;"><?php echo esc_html( (string) ( $summary['total_source'] ?? 0 ) ); ?></p>
			</div>
			<div class="card" style="padding:12px;margin:0;">
				<strong><?php esc_html_e( 'Mapped translations', 'wpmmcc-ats' ); ?></strong>
				<p style="font-size:28px;margin:8px 0 0;"><?php echo esc_html( (string) ( $summary['total_translated'] ?? 0 ) ); ?></p>
			</div>
			<div class="card" style="padding:12px;margin:0;">
				<strong><?php esc_html_e( 'Overall %', 'wpmmcc-ats' ); ?></strong>
				<p style="font-size:28px;margin:8px 0 0;"><?php echo esc_html( (string) ( $summary['percent'] ?? 0 ) ); ?>%</p>
			</div>
			<div class="card" style="padding:12px;margin:0;">
				<strong><?php esc_html_e( 'needs_resync', 'wpmmcc-ats' ); ?></strong>
				<p style="font-size:28px;margin:8px 0 0;"><?php echo esc_html( (string) $resync['posts'] ); ?> / <?php echo esc_html( (string) $resync['terms'] ); ?></p>
				<p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'posts / terms', 'wpmmcc-ats' ); ?></p>
			</div>
			<div class="card" style="padding:12px;margin:0;">
				<strong><?php esc_html_e( 'Active relations', 'wpmmcc-ats' ); ?></strong>
				<p style="font-size:28px;margin:8px 0 0;"><?php echo esc_html( (string) count( $rels ) ); ?></p>
			</div>
		</div>

		<?php if ( ! empty( $layers ) ) : ?>
		<h2><?php esc_html_e( 'Three-layer progress', 'wpmmcc-ats' ); ?></h2>
		<table class="widefat striped" style="max-width:720px;margin-bottom:16px;">
			<thead><tr><th><?php esc_html_e( 'Layer', 'wpmmcc-ats' ); ?></th><th><?php esc_html_e( 'Total', 'wpmmcc-ats' ); ?></th><th><?php esc_html_e( 'Done', 'wpmmcc-ats' ); ?></th><th>%</th></tr></thead>
			<tbody>
			<?php foreach ( array( 'layer_a', 'layer_b', 'layer_c' ) as $key ) :
				if ( empty( $layers[ $key ] ) ) {
					continue;
				}
				$row = $layers[ $key ];
				?>
				<tr>
					<td><?php echo esc_html( (string) ( $row['label'] ?? $key ) ); ?></td>
					<td><?php echo (int) ( $row['total'] ?? 0 ); ?></td>
					<td><?php echo (int) ( $row['translated'] ?? 0 ); ?></td>
					<td><?php echo (int) ( $row['percent'] ?? 0 ); ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'By language', 'wpmmcc-ats' ); ?></h2>
		<table class="widefat striped" style="max-width:720px;">
			<thead><tr>
				<th><?php esc_html_e( 'Language', 'wpmmcc-ats' ); ?></th>
				<th><?php esc_html_e( 'Translated', 'wpmmcc-ats' ); ?></th>
				<th><?php esc_html_e( 'Total', 'wpmmcc-ats' ); ?></th>
				<th>%</th>
			</tr></thead>
			<tbody>
			<?php foreach ( (array) ( $summary['by_language'] ?? array() ) as $code => $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $code ); ?></td>
					<td><?php echo esc_html( (string) ( $row['translated'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['total'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['percent'] ?? 0 ) ); ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2 style="margin-top:20px;"><?php esc_html_e( 'By post type', 'wpmmcc-ats' ); ?></h2>
		<table class="widefat striped" style="max-width:720px;">
			<thead><tr>
				<th><?php esc_html_e( 'Post type', 'wpmmcc-ats' ); ?></th>
				<th><?php esc_html_e( 'Translated', 'wpmmcc-ats' ); ?></th>
				<th><?php esc_html_e( 'Total', 'wpmmcc-ats' ); ?></th>
				<th>%</th>
			</tr></thead>
			<tbody>
			<?php
			$by_pt = (array) ( $summary['by_post_type'] ?? array() );
			arsort( $by_pt );
			$i = 0;
			foreach ( $by_pt as $pt => $row ) :
				if ( $i++ > 25 ) {
					break;
				}
				?>
				<tr>
					<td><code><?php echo esc_html( (string) $pt ); ?></code></td>
					<td><?php echo esc_html( (string) ( $row['translated'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['total'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['percent'] ?? 0 ) ); ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:16px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-pending' ) ); ?>"><?php esc_html_e( 'Pending translations', 'wpmmcc-ats' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-tasks' ) ); ?>"><?php esc_html_e( 'Tasks', 'wpmmcc-ats' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-menu-sync' ) ); ?>"><?php esc_html_e( 'Menu Sync', 'wpmmcc-ats' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Count mappings with needs_resync=1.
	 *
	 * @return array{posts:int,terms:int}
	 */
	private static function count_needs_resync(): array {
		global $wpdb;
		$posts = 0;
		$terms = 0;
		$pt    = wptsall_table( 'post_mappings' );
		$tt    = wptsall_table( 'term_mappings' );
		if ( $pt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot admin dashboard count; freshness preferred over cache.
			$posts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE needs_resync = 1', $pt ) );
		}
		if ( $tt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot admin dashboard count; freshness preferred over cache.
			$terms = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE needs_resync = 1', $tt ) );
		}
		return array(
			'posts' => $posts,
			'terms' => $terms,
		);
	}
}
