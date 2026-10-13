<?php
/**
 * Languages Module Loader
 *
 * Loads the Languages admin pages and (in future) REST API routes.
 *
 * @package WPTSALL\Languages
 * @since 1.2.0
 */

namespace WPTSALL\Languages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Languages Module.
 */
class Module {
	/**
	 * Initialize the module.
	 */
	public static function init() {
		// Admin page registration is centrally handled by
		// includes/admin/menu.php (priority 10, same 'wptsall-languages'
		// slug). ATS-P2-01 (3.8flash): this second add_menu_page hook at
		// priority 11 registered the same submenu again, rendering two
		// identical "Languages" entries in the WPTSALL sidebar. Only the
		// form action handlers and admin-bar switcher are wired here.

		// Form action handlers self-register in Languages_Page::init()
		// (called from includes/admin/menu.php) — do not duplicate here.

		// P1-4 — Admin bar language switcher (Polylang pattern).
		add_action( 'admin_bar_menu', array( '\\WPTSALL\\Languages\\Admin\\Admin_Bar_Switcher', 'add_node' ), 100 );
	}
}
