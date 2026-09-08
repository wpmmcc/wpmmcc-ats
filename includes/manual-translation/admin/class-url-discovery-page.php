<?php
/**
 * URL Discovery Page (P5-4)
 *
 * Admin page where the user pastes a list of source URLs and wptsall
 * auto-detects their translation counterparts by swapping the path prefix.
 *
 * Workflow:
 *   1. Admin selects source language + target language.
 *   2. Admin pastes one URL per line.
 *   3. Service computes target path, finds target post, creates mapping.
 *   4. Per-URL result table shows success / missing / error.
 *
 * @package WPTSALL\ManualTranslation\Admin
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\ManualTranslation\Services\Url_Discovery_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Url_Discovery_Page {

	const PAGE_SLUG = 'wptsall-url-discovery';
	const CAP       = 'manage_wptsall_translations';
	const NONCE     = 'wptsall_url_discovery';

	public static function init() {
		add_action( 'admin_post_wptsall_url_discovery_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'wptsall-manual',
			__( 'URL Discovery', 'wpmmcc-ats' ),
			__( 'URL Discovery', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$languages   = Language_Service::get_all( array( 'status' => 'all' ) );
		$default     = Language_Service::get_default();
		$default_code = $default ? (string) $default['code'] : ( $languages[0]['code'] ?? 'zh_CN' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$results = isset( $_GET['wptsall_results'] ) ? json_decode( sanitize_text_field( wp_unslash( $_GET['wptsall_results'] ) ), true ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source_lang = isset( $_GET['source_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['source_lang'] ) ) : $default_code;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target_lang = isset( $_GET['target_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['target_lang'] ) ) : ( $languages[1]['code'] ?? 'en_US' );

		Admin_Page_Helper::render_header(
			__( 'URL Discovery', 'wpmmcc-ats' ),
			__( 'Paste source URLs (one per line) and wptsall will compute the target-language counterpart path, locate the target post, and create a translation mapping.', 'wpmmcc-ats' )
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wptsall_url_discovery_run">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="source_lang"><?php esc_html_e( 'Source language', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="source_lang" id="source_lang">
							<?php foreach ( $languages as $lang ) : ?>
								<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $source_lang, $lang['code'] ); ?>>
									<?php echo esc_html( $lang['code'] ); ?> — <?php echo esc_html( $lang['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="target_lang"><?php esc_html_e( 'Target language', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="target_lang" id="target_lang">
							<?php foreach ( $languages as $lang ) : ?>
								<?php if ( $lang['code'] === $source_lang ) { continue; } ?>
								<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $target_lang, $lang['code'] ); ?>>
									<?php echo esc_html( $lang['code'] ); ?> — <?php echo esc_html( $lang['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="urls"><?php esc_html_e( 'Source URLs', 'wpmmcc-ats' ); ?></label><br><small><?php esc_html_e( 'One URL per line. Relative paths OK.', 'wpmmcc-ats' ); ?></small></th>
					<td>
						<textarea name="urls" id="urls" rows="10" class="large-text" placeholder="/products/foo/&#10;/blog/hello-world/&#10;https://blog.wpmm.cc/about/"></textarea>
					</td>
				</tr>
			</table>
			<p>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Discover Translations', 'wpmmcc-ats' ); ?></button>
			</p>
		</form>

		<?php if ( is_array( $results ) && ! empty( $results ) ) : ?>
			<h2><?php esc_html_e( 'Results', 'wpmmcc-ats' ); ?></h2>
			<p>
				<span style="color:#46b450;">✓ <?php echo (int) ( $results['found'] ?? 0 ); ?> <?php esc_html_e( 'found', 'wpmmcc-ats' ); ?></span>,
				<span style="color:#ffb900;">⚠ <?php echo (int) ( $results['missing'] ?? 0 ); ?> <?php esc_html_e( 'missing', 'wpmmcc-ats' ); ?></span>,
				<span style="color:#dc3232;">✗ <?php echo (int) ( $results['errors'] ?? 0 ); ?> <?php esc_html_e( 'errors', 'wpmmcc-ats' ); ?></span>
			</p>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source URL', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Target path', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Source post', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Target post', 'wpmmcc-ats' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpmmcc-ats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( (array) ( $results['results'] ?? array() ) as $r ) :
					$status = (string) ( $r['status'] ?? 'error' );
					$color  = $status === 'found' ? '#46b450' : ( $status === 'missing' ? '#ffb900' : '#dc3232' );
				?>
					<tr>
						<td><code><?php echo esc_html( (string) ( $r['url'] ?? '' ) ); ?></code></td>
						<td><code><?php echo esc_html( (string) ( $r['target_path'] ?? '' ) ); ?></code></td>
						<td>
							<?php if ( ! empty( $r['source_post_id'] ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $r['source_post_id'] ) ); ?>">#<?php echo (int) $r['source_post_id']; ?></a>
							<?php else : ?>—<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $r['target_post_id'] ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $r['target_post_id'] ) ); ?>">#<?php echo (int) $r['target_post_id']; ?></a>
							<?php else : ?>—<?php endif; ?>
						</td>
						<td><span style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $r['message'] ?? $status ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		Admin_Page_Helper::render_footer();
	}

	public static function handle_run() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( self::NONCE );
		$urls        = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['urls'] ) ) : ''; // phpcs:ignore
		$source_lang = isset( $_POST['source_lang'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source_lang'] ) ) : ''; // phpcs:ignore
		$target_lang = isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_lang'] ) ) : ''; // phpcs:ignore
		$url_list    = array_filter( array_map( 'trim', explode( "\n", $urls ) ) );

		$results = Url_Discovery_Service::discover_bulk( $url_list, $source_lang, $target_lang );

		wp_safe_redirect( add_query_arg(
			array(
				'page'           => self::PAGE_SLUG,
				'wptsall_results'=> wp_json_encode( $results ),
				'source_lang'    => $source_lang,
				'target_lang'    => $target_lang,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
