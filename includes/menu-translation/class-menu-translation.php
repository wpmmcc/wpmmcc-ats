<?php
/**
 * Menu Translation (1.3.0)
 *
 * Translates nav menu item title / description / attribute title via the
 * String Translation service. Mirrors Polylang's `pll_translate_string`
 * pattern applied to menu items (Polylang's pll__ option on each nav-menu-item
 * is what we replicate via context='menu' + key=item_<id>_title).
 *
 * Hooks:
 *   wp_setup_nav_menu_item    — at load time, capture original title
 *   wp_nav_menu_objects       — at render time, swap title by current lang
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\MenuTranslation;

use WPTSALL\Strings\Services\String_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu_Translation {

	const CONTEXT = 'menu';

	public static function init() {
		add_filter( 'wp_setup_nav_menu_item', array( __CLASS__, 'capture_original' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'translate_menu' ), 10, 1 );
		// On menu item save, register the title for translation.
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'on_save_item' ), 10, 3 );
	}

	/**
	 * Capture original (untranslated) menu item title.
	 */
	public static function capture_original( $item ) {
		if ( ! is_object( $item ) || ! isset( $item->ID ) ) {
			return $item;
		}
		// Stash original title in a private field so the render filter can read it
		// without re-querying.
		$item->wptsall_original_title = (string) $item->title;
		return $item;
	}

	/**
	 * Replace title/description with translated versions on render.
	 */
	public static function translate_menu( $items ) {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return $items;
		}
		$lang = self::current_lang();
		if ( '' === $lang || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $items;
		}
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) {
				continue;
			}
			$id = (int) $item->ID;
			$orig_title = isset( $item->wptsall_original_title ) ? (string) $item->wptsall_original_title : (string) $item->title;
			$tr = String_Translation_Service::translate( self::CONTEXT, 'item_' . $id . '_title', $orig_title, $lang );
			if ( '' !== $tr && $tr !== $orig_title ) {
				$item->title = $tr;
			}
			// Description
			if ( ! empty( $item->description ) ) {
				$orig_desc = (string) $item->description;
				$trd = String_Translation_Service::translate( self::CONTEXT, 'item_' . $id . '_description', $orig_desc, $lang );
				if ( '' !== $trd && $trd !== $orig_desc ) {
					$item->description = $trd;
				}
			}
			// attr-title
			if ( ! empty( $item->attr_title ) ) {
				$orig_attr = (string) $item->attr_title;
				$tra = String_Translation_Service::translate( self::CONTEXT, 'item_' . $id . '_attr_title', $orig_attr, $lang );
				if ( '' !== $tra && $tra !== $orig_attr ) {
					$item->attr_title = $tra;
				}
			}
		}
		return $items;
	}

	/**
	 * Register a menu item's title/description/attr_title for translation
	 * the first time it's saved, so the Strings page can list it.
	 */
	public static function on_save_item( $menu_id, $menu_item_db_id, $args ) {
		if ( ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return;
		}
		if ( ! empty( $args['menu-item-title'] ) ) {
			String_Translation_Service::register( self::CONTEXT, 'item_' . (int) $menu_item_db_id . '_title', (string) $args['menu-item-title'] );
		}
		if ( ! empty( $args['menu-item-description'] ) ) {
			String_Translation_Service::register( self::CONTEXT, 'item_' . (int) $menu_item_db_id . '_description', (string) $args['menu-item-description'] );
		}
		if ( ! empty( $args['menu-item-attr-title'] ) ) {
			String_Translation_Service::register( self::CONTEXT, 'item_' . (int) $menu_item_db_id . '_attr_title', (string) $args['menu-item-attr-title'] );
		}
	}

	/**
	 * Detect current request's target language via Language_Context.
	 */
	private static function current_lang() {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::current_language();
		}
		if ( isset( $GLOBALS['wptsall_current_virtual_site']['lang'] ) ) {
			return (string) $GLOBALS['wptsall_current_virtual_site']['lang'];
		}
		return '';
	}
}
