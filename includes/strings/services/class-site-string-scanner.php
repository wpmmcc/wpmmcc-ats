<?php
/**
 * Site String Scanner — registers Layer B strings into wptsall_strings.
 *
 * @package WPTSALL\Strings\Services
 * @since 2.3.0
 */

namespace WPTSALL\Strings\Services;

use WPTSALL\Templates\Scanners\Content_String_Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site_String_Scanner class.
 */
class Site_String_Scanner {

	/**
	 * Scan site identity, menus, and widgets into wptsall_strings.
	 *
	 * @return array{registered:int,contexts:array<string,int>}
	 */
	public static function scan_all() {
		$registered = 0;
		$contexts   = array();

		$registered += self::scan_site_identity( $contexts );
		$registered += self::scan_menus( $contexts );
		$registered += self::scan_widgets( $contexts );

		return array(
			'registered' => $registered,
			'contexts'   => $contexts,
		);
	}

	/**
	 * Register menu item labels from a nav menu (e.g. after Menu Sync).
	 *
	 * @param int $menu_id Menu term id.
	 * @return int Number registered.
	 */
	public static function register_menu_items( $menu_id ) {
		$menu_id = (int) $menu_id;
		if ( $menu_id <= 0 ) {
			return 0;
		}
		$count = 0;
		$items = wp_get_nav_menu_items( $menu_id );
		foreach ( (array) $items as $item ) {
			if ( ! empty( $item->title ) && Content_String_Scanner::is_translatable( $item->title ) ) {
				$key = 'item_' . (int) $item->ID . '_title';
				if ( String_Translation_Service::register_with_object( 'menu', $key, $item->title, (int) $item->ID ) ) {
					++$count;
				}
			}
			if ( ! empty( $item->attr_title ) && Content_String_Scanner::is_translatable( $item->attr_title ) ) {
				$key = 'item_' . (int) $item->ID . '_attr_title';
				if ( String_Translation_Service::register_with_object( 'menu', $key, $item->attr_title, (int) $item->ID ) ) {
					++$count;
				}
			}
			if ( ! empty( $item->description ) && Content_String_Scanner::is_translatable( $item->description ) ) {
				$key = 'item_' . (int) $item->ID . '_description';
				if ( String_Translation_Service::register_with_object( 'menu', $key, $item->description, (int) $item->ID ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * @param array<string,int> $contexts Context counters (by ref).
	 * @return int
	 */
	private static function scan_site_identity( array &$contexts ) {
		$count = 0;
		$blogname = get_option( 'blogname' );
		if ( is_string( $blogname ) && '' !== $blogname ) {
			if ( String_Translation_Service::register( 'site_title', 'blogname', $blogname ) ) {
				++$count;
				++$contexts['site_title'];
			}
		}
		$blogdescription = get_option( 'blogdescription' );
		if ( is_string( $blogdescription ) && '' !== $blogdescription ) {
			if ( String_Translation_Service::register( 'site_tagline', 'blogdescription', $blogdescription ) ) {
				++$count;
				++$contexts['site_tagline'];
			}
		}
		return $count;
	}

	/**
	 * @param array<string,int> $contexts Context counters (by ref).
	 * @return int
	 */
	private static function scan_menus( array &$contexts ) {
		$count = 0;
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( ! empty( $menu->name ) && Content_String_Scanner::is_translatable( $menu->name ) ) {
				$key = 'menu_' . (int) $menu->term_id . '_name';
				if ( String_Translation_Service::register_with_object( 'menu', $key, $menu->name, (int) $menu->term_id ) ) {
					++$count;
					++$contexts['menu'];
				}
			}
			$count += self::register_menu_items( (int) $menu->term_id );
			if ( $count > 0 ) {
				$contexts['menu'] = ( $contexts['menu'] ?? 0 ) + 1;
			}
		}
		return $count;
	}

	/**
	 * @param array<string,int> $contexts Context counters (by ref).
	 * @return int
	 */
	private static function scan_widgets( array &$contexts ) {
		$count            = 0;
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );
		foreach ( (array) $sidebars_widgets as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $matches ) ) {
					continue;
				}
				$widget_type     = $matches[1];
				$widget_instance = (int) $matches[2];
				$widget_options  = get_option( 'widget_' . $widget_type );
				if ( ! isset( $widget_options[ $widget_instance ] ) || ! is_array( $widget_options[ $widget_instance ] ) ) {
					continue;
				}
				$instance = $widget_options[ $widget_instance ];
				$text_keys = array( 'title', 'text', 'content', 'description', 'message', 'html', 'body' );
				foreach ( $text_keys as $field ) {
					if ( empty( $instance[ $field ] ) || ! Content_String_Scanner::is_translatable( (string) $instance[ $field ] ) ) {
						continue;
					}
					$key = sanitize_key( $widget_id . '_' . $field );
					if ( String_Translation_Service::register_with_object( 'widget', $key, (string) $instance[ $field ], null ) ) {
						++$count;
						++$contexts['widget'];
					}
				}
			}
		}
		return $count;
	}
}
