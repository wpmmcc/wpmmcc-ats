<?php
/**
 * Translation Memory Admin Page
 *
 * TranslatePress-style TM table: search/browse bilingual pairs, add new,
 * delete, and import/export as TSV.
 *
 * @package WPTSALL
 * @since 1.2.0
 */

namespace WPTSALL\TranslationMemory\Admin;

use WPTSALL\TranslationMemory\Services\Translation_Memory_Service;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Admin\Admin_Page_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translation_Memory_Page {

	const PAGE_SLUG = 'wptsall-tm';
	const CAP       = 'manage_wptsall_translations';

	public static function init() {
		add_action( 'admin_post_wptsall_tm_save',   array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wptsall_tm_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_wptsall_tm_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Translation Memory', 'wpmmcc-ats' ),
			__( 'Translation Memory', 'wpmmcc-ats' ),
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
		$counts    = Translation_Memory_Service::counts();
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
		$src       = isset( $_GET['src'] ) ? sanitize_text_field( wp_unslash( $_GET['src'] ) ) : ''; // phpcs:ignore
		$tgt       = isset( $_GET['tgt'] ) ? sanitize_text_field( wp_unslash( $_GET['tgt'] ) ) : ''; // phpcs:ignore
		$rows      = Translation_Memory_Service::list_pairs( array(
			'search'      => $search,
			'source_lang' => $src,
			'target_lang' => $tgt,
			'limit'       => 200,
		) );

		Admin_Page_Helper::render_header(
			__( 'Translation Memory', 'wpmmcc-ats' ),
			/* translators: 1: entry count, 2: language pair count */
			sprintf( __( '%1$d entries across %2$d language pairs. Lookup runs on every translation request.', 'wpmmcc-ats' ), $counts['total'], $counts['pairs'] )
		);

		?>
		<form method="get" style="margin-bottom:12px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search source / target…', 'wpmmcc-ats' ); ?>">
			<select name="src">
				<option value=""><?php esc_html_e( 'All source langs', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( $languages as $l ) : ?>
					<option value="<?php echo esc_attr( $l['code'] ); ?>" <?php selected( $src, $l['code'] ); ?>><?php echo esc_html( $l['code'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="tgt">
				<option value=""><?php esc_html_e( 'All target langs', 'wpmmcc-ats' ); ?></option>
				<?php foreach ( $languages as $l ) : ?>
					<option value="<?php echo esc_attr( $l['code'] ); ?>" <?php selected( $tgt, $l['code'] ); ?>><?php echo esc_html( $l['code'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'wpmmcc-ats' ); ?></button>
		</form>

		<h2><?php esc_html_e( 'Add new entry', 'wpmmcc-ats' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_tm_save' ); ?>
			<input type="hidden" name="action" value="wptsall_tm_save">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Source lang', 'wpmmcc-ats' ); ?></th>
					<td><select name="source_lang">
						<?php foreach ( $languages as $l ) echo '<option value="' . esc_attr( $l['code'] ) . '">' . esc_html( $l['code'] ) . '</option>'; ?>
					</select></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Target lang', 'wpmmcc-ats' ); ?></th>
					<td><select name="target_lang">
						<?php foreach ( $languages as $l ) echo '<option value="' . esc_attr( $l['code'] ) . '">' . esc_html( $l['code'] ) . '</option>'; ?>
					</select></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Source text', 'wpmmcc-ats' ); ?></th>
					<td><input name="source_text" type="text" class="regular-text" required></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Target text', 'wpmmcc-ats' ); ?></th>
					<td><input name="target_text" type="text" class="regular-text" required></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Domain (optional)', 'wpmmcc-ats' ); ?></th>
					<td><input name="domain" type="text" class="regular-text" placeholder="site_title, button_label, etc."></td>
				</tr>
			</table>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Add', 'wpmmcc-ats' ); ?></button>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Existing entries', 'wpmmcc-ats' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
			<?php wp_nonce_field( 'wptsall_tm_export' ); ?>
			<input type="hidden" name="action" value="wptsall_tm_export">
			<button class="button" type="submit"><?php esc_html_e( 'Download TSV', 'wpmmcc-ats' ); ?></button>
		</form>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source lang', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Source', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Target lang', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Target', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Hits', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Domain', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'wpmmcc-ats' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpmmcc-ats' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'No TM entries yet.', 'wpmmcc-ats' ); ?></td></tr>
			<?php else : foreach ( $rows as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( $r['source_lang'] ); ?></code></td>
					<td><?php echo esc_html( $r['source_text'] ); ?></td>
					<td><code><?php echo esc_html( $r['target_lang'] ); ?></code></td>
					<td><?php echo esc_html( $r['target_text'] ); ?></td>
					<td><?php echo (int) $r['occurrences']; ?></td>
					<td><?php echo esc_html( (string) $r['domain'] ); ?></td>
					<td><?php echo esc_html( (string) $r['last_used_at'] ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete?', 'wpmmcc-ats' ) ); ?>');">
							<?php wp_nonce_field( 'wptsall_tm_delete' ); ?>
							<input type="hidden" name="action" value="wptsall_tm_delete">
							<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
							<button class="button button-small" type="submit"><?php esc_html_e( 'Delete', 'wpmmcc-ats' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Lookup: WPTSALL\TranslationMemory\Services\Translation_Memory_Service::lookup($source, $src, $tgt) returns the target text on exact match.', 'wpmmcc-ats' ); ?></p>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_tm_save' );
		Translation_Memory_Service::record(
			sanitize_textarea_field( wp_unslash( $_POST['source_text'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['source_lang'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['target_lang'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['target_text'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) )
		);
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_tm_delete' );
		Translation_Memory_Service::delete( (int) ( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0 ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_tm_export' );
		$rows = Translation_Memory_Service::list_pairs( array( 'limit' => 100000 ) );
		nocache_headers();
		header( 'Content-Type: text/tab-separated-values; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wptsall-tm-' . gmdate( 'Ymd-His' ) . '.tsv"' );
		echo "source_lang\ttarget_lang\tsource_text\ttarget_text\toccurrences\tdomain\n";
		foreach ( (array) $rows as $r ) {
			echo wp_kses_post( '' ); // dummy to keep wp_kses_post import-aware in linters.
			$fields = array( $r['source_lang'], $r['target_lang'], $r['source_text'], $r['target_text'], $r['occurrences'], $r['domain'] );
			$clean = array_map( function ( $v ) { return str_replace( array( "\t", "\n", "\r" ), ' ', (string) $v ); }, $fields );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TSV file download (Content-Type: text/tab-separated-values), not browser output.
			echo implode( "\t", $clean ) . "\n";
		}
		exit;
	}
}
