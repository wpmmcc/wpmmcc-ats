<?php
/**
 * Browse-as-role — discover login-state gettext strings (Layer C).
 *
 * @package WPTSALL\Templates\Admin
 * @since 2.3.0
 */

namespace WPTSALL\Templates\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Templates\Services\Template_Entry_Service;
use WPTSALL\Templates\Services\Template_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Browse_As_Role_Page class.
 */
class Browse_As_Role_Page {

	const PAGE_SLUG = 'wptsall-browse-as-role';
	const CAP       = 'manage_wptsall_security';
	const TRANSIENT = 'wptsall_browse_gettext_hits';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_wptsall_start_browse_session', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_wptsall_register_browse_strings', array( __CLASS__, 'handle_register' ) );
		add_filter( 'gettext', array( __CLASS__, 'track_gettext' ), 999, 3 );
		add_filter( 'gettext_with_context', array( __CLASS__, 'track_gettext_with_context' ), 999, 4 );
	}

	/**
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Browse as Role', 'wpmmcc-ats' ),
			__( 'Browse as Role', 'wpmmcc-ats' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @param string $domain      Domain.
	 * @return string
	 */
	public static function track_gettext( $translation, $text, $domain ) {
		self::maybe_record( $domain, $text, '' );
		return $translation;
	}

	/**
	 * @param string $translation Translation.
	 * @param string $text        Original.
	 * @param string $context     Context.
	 * @param string $domain      Domain.
	 * @return string
	 */
	public static function track_gettext_with_context( $translation, $text, $context, $domain ) {
		self::maybe_record( $domain, $text, $context );
		return $translation;
	}

	/**
	 * @param string $domain  Text domain.
	 * @param string $text    Msgid.
	 * @param string $context Msgctxt.
	 * @return void
	 */
	private static function maybe_record( $domain, $text, $context ) {
		if ( ! get_transient( self::TRANSIENT . '_active' ) ) {
			return;
		}
		if ( '' === trim( (string) $text ) ) {
			return;
		}
		$hits = get_transient( self::TRANSIENT );
		if ( ! is_array( $hits ) ) {
			$hits = array();
		}
		$key = md5( (string) $domain . '|' . (string) $context . '|' . (string) $text );
		$hits[ $key ] = array(
			'domain'  => (string) $domain,
			'msgid'   => (string) $text,
			'msgctxt' => (string) $context,
		);
		set_transient( self::TRANSIENT, $hits, HOUR_IN_SECONDS );
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		global $wp_roles;
		$hits = get_transient( self::TRANSIENT );
		if ( ! is_array( $hits ) ) {
			$hits = array();
		}
		Admin_Page_Helper::render_header(
			__( 'Browse as Role', 'wpmmcc-ats' ),
			__( 'Start a tracking session, browse the front-end while logged in as a role, then register discovered gettext strings into templates.', 'wpmmcc-ats' )
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;margin-bottom:16px;">
			<?php wp_nonce_field( 'wptsall_start_browse' ); ?>
			<input type="hidden" name="action" value="wptsall_start_browse_session">
			<p>
				<label for="role"><?php esc_html_e( 'Role to simulate (opens front-end in new tab after start)', 'wpmmcc-ats' ); ?></label><br>
				<select name="role" id="role">
					<?php foreach ( $wp_roles->roles as $role_key => $role ) : ?>
						<option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php submit_button( __( 'Start tracking session', 'wpmmcc-ats' ), 'primary', 'submit', false ); ?>
		</form>
		<p><?php
		printf(
			/* translators: %d: captured string count in the current browsing session. */
			esc_html__( 'Captured strings this session: %d', 'wpmmcc-ats' ),
			count( $hits )
		);
		?></p>
		<?php if ( ! empty( $hits ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wptsall_register_browse' ); ?>
			<input type="hidden" name="action" value="wptsall_register_browse_strings">
			<input type="number" name="relation_id" min="1" placeholder="<?php esc_attr_e( 'Relation ID', 'wpmmcc-ats' ); ?>" required>
			<?php submit_button( __( 'Register hits into template_entries', 'wpmmcc-ats' ), 'secondary' ); ?>
		</form>
		<table class="wp-list-table widefat striped"><thead><tr><th>Domain</th><th>String</th><th>Context</th></tr></thead><tbody>
		<?php foreach ( array_slice( $hits, 0, 100 ) as $hit ) : ?>
			<tr><td><code><?php echo esc_html( $hit['domain'] ); ?></code></td><td><?php echo esc_html( $hit['msgid'] ); ?></td><td><?php echo esc_html( $hit['msgctxt'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif;
		Admin_Page_Helper::render_footer();
	}

	/**
	 * @return void
	 */
	public static function handle_start() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_start_browse' );
		delete_transient( self::TRANSIENT );
		set_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS );
		set_transient( self::TRANSIENT . '_active', 1, HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'started' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return void
	 */
	public static function handle_register() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_register_browse' );
		$relation_id = isset( $_POST['relation_id'] ) ? absint( wp_unslash( $_POST['relation_id'] ) ) : 0;
		$hits        = get_transient( self::TRANSIENT );
		$registered  = 0;
		if ( $relation_id > 0 && is_array( $hits ) ) {
			foreach ( $hits as $hit ) {
				$domain = sanitize_text_field( (string) ( $hit['domain'] ?? 'default' ) );
				$tpl_id = Template_Service::get_or_create(
					array(
						'relation_id'     => $relation_id,
						'source_type'     => 'plugin',
						'text_domain'     => $domain,
						'source_name'     => $domain,
						'source_identifier' => $domain,
						'source_language' => get_locale(),
					)
				);
				if ( $tpl_id > 0 ) {
					Template_Entry_Service::get_or_create(
						$tpl_id,
						array(
							'msgid'     => (string) ( $hit['msgid'] ?? '' ),
							'msgctxt'   => (string) ( $hit['msgctxt'] ?? '' ),
							'reference' => 'browse_as_role',
						)
					);
					++$registered;
				}
			}
		}
		delete_transient( self::TRANSIENT . '_active' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'registered' => $registered ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
