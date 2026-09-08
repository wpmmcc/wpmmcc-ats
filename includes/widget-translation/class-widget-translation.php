<?php
/**
 * Widget Translation (1.3.0)
 *
 * Translates widget title and text fields via the String Translation
 * service. Mirrors Polylang's `pll_register_string` widget integration
 * (which scans `sidebars_widgets` + widget option on display).
 *
 * Hooks:
 *   widget_display_callback — fires for each widget before render; swap title
 *                             and 'text' / 'content' fields.
 *   widget_update_callback — fires on save; re-register strings.
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\WidgetTranslation;

use WPTSALL\Strings\Services\String_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget_Translation {

	const CONTEXT = 'widget';

	public static function init() {
		add_filter( 'widget_display_callback', array( __CLASS__, 'translate_widget' ), 10, 3 );
		add_filter( 'widget_update_callback',  array( __CLASS__, 'register_on_save' ), 10, 4 );
	}

	/**
	 * Translate widget fields at display time.
	 *
	 * @param array     $instance Widget instance.
	 * @param \WP_Widget $widget   Widget object.
	 * @param array     $args     Sidebar args.
	 */
	public static function translate_widget( $instance, $widget, $args ) {
		if ( ! is_array( $instance ) || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $instance;
		}
		$lang = self::current_lang();
		if ( '' === $lang ) {
			return $instance;
		}
		$widget_id = is_object( $widget ) && isset( $widget->id ) ? (string) $widget->id : ( is_object( $widget ) ? strtolower( get_class( $widget ) ) : 'unknown' );
		$base_key  = $widget_id;
		// Title
		if ( ! empty( $instance['title'] ) ) {
			$tr = String_Translation_Service::translate( self::CONTEXT, $base_key . '_title', (string) $instance['title'], $lang );
			if ( '' !== $tr && $tr !== $instance['title'] ) {
				$instance['title'] = $tr;
			}
		}
		// Text / content (custom_html, text, customizer text widgets, etc.)
		$text_keys = array( 'text', 'content', 'description', 'message', 'html', 'body' );
		foreach ( $text_keys as $k ) {
			if ( ! empty( $instance[ $k ] ) ) {
				$tr = String_Translation_Service::translate( self::CONTEXT, $base_key . '_' . $k, (string) $instance[ $k ], $lang );
				if ( '' !== $tr && $tr !== $instance[ $k ] ) {
					$instance[ $k ] = $tr;
				}
			}
		}
		return $instance;
	}

	/**
	 * Register widget strings on save (Polylang's `pll_register_string` for widgets).
	 */
	public static function register_on_save( $instance, $new_instance, $old_instance, $widget ) {
		if ( ! is_array( $instance ) || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $instance;
		}
		$widget_id = is_object( $widget ) && isset( $widget->id ) ? (string) $widget->id : ( is_object( $widget ) ? strtolower( get_class( $widget ) ) : 'unknown' );
		if ( ! empty( $instance['title'] ) ) {
			String_Translation_Service::register( self::CONTEXT, $widget_id . '_title', (string) $instance['title'] );
		}
		$text_keys = array( 'text', 'content', 'description', 'message', 'html', 'body' );
		foreach ( $text_keys as $k ) {
			if ( ! empty( $instance[ $k ] ) ) {
				String_Translation_Service::register( self::CONTEXT, $widget_id . '_' . $k, (string) $instance[ $k ] );
			}
		}
		return $instance;
	}

	/**
	 * Detect current language via Language_Context.
	 */
	private static function current_lang() {
		if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
			return \WPTSALL\Core\Language_Context::current_language();
		}
		return isset( $GLOBALS['wptsall_current_virtual_site']['lang'] )
			? (string) $GLOBALS['wptsall_current_virtual_site']['lang']
			: '';
	}
}
