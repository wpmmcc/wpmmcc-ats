<?php
/**
 * Menu Sync admin tool — clone source nav menus for a virtual site with prefixed URLs.
 *
 * @package WPTSALL\MenuTranslation\Admin
 * @since 2.2.0
 */

namespace WPTSALL\MenuTranslation\Admin;

use WPTSALL\Admin\Admin_Page_Helper;
use WPTSALL\Sites\Services\Url_Converter;
use WPTSALL\Sites\Services\Virtual_Site_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu_Sync_Page class.
 */
class Menu_Sync_Page {

	const PAGE_SLUG = 'wptsall-menu-sync';
	const CAP       = 'manage_wptsall_sync';

	/**
	 * Register admin_post handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_wptsall_menu_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_menu_sync_needed' ) );
	}

	/**
	 * Prompt operators to run Menu Sync when virtual sites exist without mappings.
	 *
	 * @return void
	 */
	public static function maybe_notice_menu_sync_needed() {
		if ( ! wptsall_user_can_manage_translations() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || empty( $screen->id ) ) {
			return;
		}
		// Limit noise: WPTSALL admin + Appearance menus.
		$ok = ( false !== strpos( (string) $screen->id, 'wptsall' ) )
			|| in_array( $screen->id, array( 'nav-menus', 'themes' ), true );
		if ( ! $ok ) {
			return;
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			return;
		}
		$sites = Virtual_Site_Service::get_all( array( 'status' => 'active' ) );
		if ( empty( $sites ) || ! is_array( $sites ) ) {
			return;
		}
		if ( class_exists( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service' )
			&& method_exists( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service', 'menu_auto_clone_enabled' )
			&& \WPTSALL\MenuTranslation\Menu_Mapping_Service::menu_auto_clone_enabled() ) {
			return;
		}
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'menu_mappings' ) : '';
		if ( ! $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot admin notice count; freshness preferred over cache.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( $count > 0 ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html__( 'Virtual sites are active but no menu mappings exist. Run Menu Sync (or enable menu auto-clone) so each language site shows the correct navigation tree.', 'wpmmcc-ats' );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Menu Sync', 'wpmmcc-ats' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpmmcc-ats',
			__( 'Menu Sync', 'wpmmcc-ats' ),
			__( 'Menu Sync', 'wpmmcc-ats' ),
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
		$menus = wp_get_nav_menus();
		$sites = class_exists( Virtual_Site_Service::class )
			? Virtual_Site_Service::get_all( array( 'status' => 'active' ) )
			: array();

		Admin_Page_Helper::render_header(
			__( 'Menu Sync', 'wpmmcc-ats' ),
			__( 'Clone a source menu for a virtual site: item URLs get the path prefix and post/term object IDs are remapped when mappings exist.', 'wpmmcc-ats' )
		);

		if ( isset( $_GET['synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$mid = (int) $_GET['synced']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html(
				sprintf(
					/* translators: %d: menu term id */
					__( 'Menu synced. New menu ID: %d', 'wpmmcc-ats' ),
					$mid
				)
			) . ' — <a href="' . esc_url( admin_url( 'nav-menus.php?action=edit&menu=' . $mid ) ) . '">' . esc_html__( 'Edit menu', 'wpmmcc-ats' ) . '</a></p></div>';
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
			<?php wp_nonce_field( 'wptsall_menu_sync' ); ?>
			<input type="hidden" name="action" value="wptsall_menu_sync">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="source_menu"><?php esc_html_e( 'Source menu', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="source_menu" id="source_menu" required>
							<option value=""><?php esc_html_e( '— Select —', 'wpmmcc-ats' ); ?></option>
							<?php foreach ( $menus as $menu ) : ?>
								<option value="<?php echo esc_attr( (string) $menu->term_id ); ?>">
									<?php echo esc_html( $menu->name . ' (#' . $menu->term_id . ', ' . $menu->count . ' items)' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="virtual_site"><?php esc_html_e( 'Target virtual site', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<select name="virtual_site" id="virtual_site" required>
							<option value=""><?php esc_html_e( '— Select —', 'wpmmcc-ats' ); ?></option>
							<?php foreach ( $sites as $site ) : ?>
								<option value="<?php echo esc_attr( (string) ( $site['id'] ?? '' ) ); ?>">
									<?php echo esc_html( ( $site['name'] ?? $site['id'] ) . ' /' . ( $site['path_prefix'] ?? '' ) . '/ (' . ( $site['lang'] ?? '' ) . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="menu_name"><?php esc_html_e( 'New menu name', 'wpmmcc-ats' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="menu_name" id="menu_name" placeholder="<?php esc_attr_e( 'Leave empty to auto-name', 'wpmmcc-ats' ); ?>">
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Clone & rewrite menu', 'wpmmcc-ats' ) ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'Front-end already rewrites menu URLs on virtual sites. Use this tool when you need a separate WP menu assigned to a theme location for that language.', 'wpmmcc-ats' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wptsall-strings&context=menu' ) ); ?>"><?php esc_html_e( 'Translate menu labels in Strings →', 'wpmmcc-ats' ); ?></a></p>
		<?php
	}

	/**
	 * Handle clone request.
	 *
	 * @return void
	 */
	public static function handle_sync() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Forbidden', 'wpmmcc-ats' ) );
		}
		check_admin_referer( 'wptsall_menu_sync' );
		$source_id = isset( $_POST['source_menu'] ) ? absint( wp_unslash( $_POST['source_menu'] ) ) : 0;
		$vs_id     = sanitize_text_field( wp_unslash( (string) ( $_POST['virtual_site'] ?? '' ) ) );
		$name      = sanitize_text_field( wp_unslash( (string) ( $_POST['menu_name'] ?? '' ) ) );

		$result = self::sync_menu( $source_id, $vs_id, $name );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => self::PAGE_SLUG,
						'error' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'synced' => (int) $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Clone or refresh a menu for a virtual site.
	 *
	 * @param int    $source_menu_id Source menu term ID.
	 * @param string $vs_id          Virtual site id.
	 * @param string $new_name       Optional name.
	 * Existing source/virtual-site mappings are refreshed in place. Reusing the
	 * target term avoids the duplicate-name error from wp_create_nav_menu(),
	 * preserves theme locations, and makes incremental resync deterministic.
	 *
	 * @return int|\WP_Error Target menu ID.
	 */
	public static function sync_menu( int $source_menu_id, string $vs_id, string $new_name = '' ) {
		if ( $source_menu_id <= 0 || '' === $vs_id ) {
			return new \WP_Error( 'invalid', __( 'Missing source menu or virtual site.', 'wpmmcc-ats' ) );
		}
		$vs = Virtual_Site_Service::get( $vs_id );
		if ( ! is_array( $vs ) || empty( $vs['path_prefix'] ) ) {
			return new \WP_Error( 'vs', __( 'Virtual site not found.', 'wpmmcc-ats' ) );
		}
		$source = wp_get_nav_menu_object( $source_menu_id );
		if ( ! $source ) {
			return new \WP_Error( 'menu', __( 'Source menu not found.', 'wpmmcc-ats' ) );
		}
		if ( '' === $new_name ) {
			$new_name = $source->name . ' [' . ( $vs['lang'] ?? $vs['path_prefix'] ) . ']';
		}
		$existing_target_id = class_exists( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service' )
			? \WPTSALL\MenuTranslation\Menu_Mapping_Service::get_target_menu( $source_menu_id, $vs_id )
			: 0;
		if ( $existing_target_id > 0 && term_exists( $existing_target_id, 'nav_menu' ) ) {
			$new_id = $existing_target_id;
			// A mapped target must be rebuilt in place: creating another menu with
			// the same deterministic name fails in WordPress and leaves the mapping
			// permanently pending. Delete items first so removed source items become
			// tombstones on the target as well.
			$existing_items = wp_get_nav_menu_items( $new_id, array( 'post_status' => 'any' ) );
			$clear_items    = static function () use ( $existing_items ) {
				foreach ( (array) $existing_items as $existing_item ) {
					if ( ! empty( $existing_item->ID ) ) {
						wp_delete_post( (int) $existing_item->ID, true );
					}
				}
			};
			if ( class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
				\WPTSALL\Hooks\Content_Change_Dispatcher::with_internal_write( $clear_items );
			} else {
				$clear_items();
			}
		} else {
			$new_id = wp_create_nav_menu( $new_name );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
		}
		$items = wp_get_nav_menu_items( $source_menu_id );
		$id_map = array(); // old item db id => new item db id
		foreach ( (array) $items as $item ) {
			$object_id = (int) $item->object_id;
			$type      = (string) $item->type;
			$url       = (string) $item->url;

			if ( 'post_type' === $type && $object_id > 0 && function_exists( 'wptsall_object_id' ) ) {
				$mapped = (int) wptsall_object_id( $object_id, (string) $item->object, false, (string) ( $vs['lang'] ?? '' ) );
				if ( $mapped > 0 ) {
					$object_id = $mapped;
					$plink     = get_permalink( $mapped );
					if ( $plink ) {
						$url = Url_Converter::virtualize( $plink, $vs );
					}
				} else {
					$url = Url_Converter::virtualize( $url, $vs );
				}
			} elseif ( 'taxonomy' === $type && $object_id > 0 && function_exists( 'wptsall_object_id' ) ) {
				$mapped = (int) wptsall_object_id( $object_id, (string) $item->object, false, (string) ( $vs['lang'] ?? '' ) );
				if ( $mapped > 0 ) {
					$object_id = $mapped;
					$tlink     = get_term_link( $mapped, (string) $item->object );
					if ( ! is_wp_error( $tlink ) ) {
						$url = Url_Converter::virtualize( (string) $tlink, $vs );
					}
				} else {
					$url = Url_Converter::virtualize( $url, $vs );
				}
			} else {
				$url = Url_Converter::virtualize( $url, $vs );
			}

			$parent = 0;
			if ( ! empty( $item->menu_item_parent ) && isset( $id_map[ (int) $item->menu_item_parent ] ) ) {
				$parent = (int) $id_map[ (int) $item->menu_item_parent ];
			}

			$new_item = wp_update_nav_menu_item(
				(int) $new_id,
				0,
				array(
					'menu-item-title'     => $item->title,
					'menu-item-url'       => $url,
					'menu-item-status'    => 'publish',
					'menu-item-type'      => $type,
					'menu-item-object'    => $item->object,
					'menu-item-object-id' => $object_id,
					'menu-item-parent-id' => $parent,
					'menu-item-position'  => (int) $item->menu_order,
					'menu-item-classes'   => implode( ' ', (array) $item->classes ),
					'menu-item-xfn'       => $item->xfn,
					'menu-item-description' => $item->description,
					'menu-item-attr-title'  => $item->attr_title,
					'menu-item-target'      => $item->target,
				)
			);
			if ( ! is_wp_error( $new_item ) ) {
				$id_map[ (int) $item->ID ] = (int) $new_item;
			}
		}
		update_term_meta( (int) $new_id, '_wptsall_synced_from_menu', $source_menu_id );
		update_term_meta( (int) $new_id, '_wptsall_virtual_site_id', $vs_id );

		if ( class_exists( '\\WPTSALL\\MenuTranslation\\Menu_Mapping_Service' ) ) {
			\WPTSALL\MenuTranslation\Menu_Mapping_Service::upsert_mapping(
				$source_menu_id,
				(int) $new_id,
				$vs_id,
				''
			);
		}

		if ( class_exists( '\\WPTSALL\\Strings\\Services\\Site_String_Scanner' ) ) {
			\WPTSALL\Strings\Services\Site_String_Scanner::register_menu_items( (int) $new_id );
		}

		return (int) $new_id;
	}
}
