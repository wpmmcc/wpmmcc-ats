<?php
/**
 * Config Filter
 *
 * Translates site configuration content (blogname, menus, widgets) for virtual sites.
 * Data-driven: only activates when config translations exist in template_entries.
 *
 * @package WPTSALL\Hooks
 * @since 1.1.0
 */

namespace WPTSALL\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Config_Filter class
 *
 * Filters WordPress options and menu/widget output to return translated
 * config content for virtual sites. Uses the same data-driven pattern
 * as Gettext_Filter: translations present in template_entries -> filter active;
 * no translations -> no-op.
 *
 * Config entries are stored with text_domain='config-site' and identified by msgctxt:
 * - site_title       -> option_blogname
 * - site_tagline     -> option_blogdescription
 * - menu_item_title  -> wp_nav_menu_objects (item->title)
 * - menu_item_attr_title -> wp_nav_menu_objects (item->attr_title)
 * - widget_title     -> widget_title filter
 */
class Config_Filter {

	/**
	 * Translation cache organized by msgctxt.
	 *
	 * Format: [ msgctxt => [ msgid => msgstr ] ]
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Whether filters are initialized.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Current relation ID.
	 *
	 * @var int|null
	 */
	private static $current_relation_id = null;

	/**
	 * Target language code.
	 *
	 * @var string
	 */
	private static $target_lang = 'zh_CN';

	/**
	 * Initialize config filters.
	 *
	 * @return void
	 */
	public static function init() {
		// Only run on frontend.
		if ( is_admin() ) {
			return;
		}

		// Run after Gettext_Filter (priority 20) to ensure virtual site is detected.
		add_action( 'init', array( __CLASS__, 'maybe_init_filters' ), 21 );

		// Listen for translation updates to clear cache.
		add_action( 'wptsall_translation_completed', array( __CLASS__, 'on_translation_completed' ) );
		add_action( 'wptsall_entry_updated', array( __CLASS__, 'on_entry_updated' ), 10, 2 );
	}

	/**
	 * Conditionally initialize filters.
	 *
	 * Only activates for virtual sites that have config translations.
	 *
	 * @return void
	 */
	public static function maybe_init_filters() {
		if ( is_admin() ) {
			return;
		}

		// Config filter: virtual sites + WP subsite targets (relation by blog id).
		$relation_id = null;
		$target_lang = 'zh_CN';

		if ( class_exists( '\WPTSALL\Hooks\Virtual_Site_Router' ) ) {
			$virtual_site = Virtual_Site_Router::get_current_virtual_site();
			if ( $virtual_site ) {
				$relation_id = $virtual_site['relation_id'] ?? null;
				$target_lang = $virtual_site['lang'] ?? 'zh_CN';
			}
		}

		if ( ! $relation_id && is_multisite() ) {
			global $wpdb;
			$table        = wptsall_table( 'site_relations' );
			$current_blog = (string) get_current_blog_id();
			$main_blog_id = defined( 'BLOG_ID_CURRENT_SITE' ) ? BLOG_ID_CURRENT_SITE : 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rel = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, target_lang FROM %i
					 WHERE target_site_id = %s AND target_site_type = 'wp'
					   AND source_site_id = %d AND status = 'active' LIMIT 1",
					$table,
					$current_blog,
					$main_blog_id
				),
				ARRAY_A
			);
			if ( $rel ) {
				$relation_id = (int) $rel['id'];
				$target_lang = (string) ( $rel['target_lang'] ?? $target_lang );
			}
		}

		if ( ! $relation_id ) {
			return;
		}

		self::$current_relation_id = $relation_id;
		self::$target_lang         = $target_lang;

		// Load config translations.
		$translations = \WPTSALL\Templates\Services\Template_Entry_Service::get_translations_for_hook(
			intval( $relation_id ),
			'config-site'
		);

		if ( empty( $translations ) ) {
			return;
		}

		// Organize by msgctxt for quick lookup.
		foreach ( $translations as $key => $data ) {
			$msgid   = $key;
			$msgctxt = '';

			if ( false !== strpos( $key, "\x04" ) ) {
				list( $msgctxt, $msgid ) = explode( "\x04", $key, 2 );
			}

			if ( $msgctxt && ! empty( $data['msgstr'] ) ) {
				if ( ! isset( self::$cache[ $msgctxt ] ) ) {
					self::$cache[ $msgctxt ] = array();
				}
				self::$cache[ $msgctxt ][ $msgid ] = $data['msgstr'];
			}
		}

		if ( empty( self::$cache ) ) {
			return;
		}

		// Register filters based on available translation types.
		if ( isset( self::$cache['site_title'] ) ) {
			add_filter( 'option_blogname', array( __CLASS__, 'filter_blogname' ) );
		}

		if ( isset( self::$cache['site_tagline'] ) ) {
			add_filter( 'option_blogdescription', array( __CLASS__, 'filter_blogdescription' ) );
		}

		if ( isset( self::$cache['menu_item_title'] ) || isset( self::$cache['menu_item_attr_title'] ) ) {
			add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_nav_menu_objects' ), 10, 2 );
		}

		if ( isset( self::$cache['widget_title'] ) ) {
			add_filter( 'widget_title', array( __CLASS__, 'filter_widget_title' ), 10, 3 );
		}

		self::$initialized = true;

		wptsall_log_info(
			'hooks-config',
			'Config filter initialized',
			array(
				'relation_id'  => $relation_id,
				'target_lang'  => $target_lang,
				'entry_types'  => array_keys( self::$cache ),
				'entry_counts' => array_map( 'count', self::$cache ),
			)
		);
	}

	/**
	 * Filter blogname option.
	 *
	 * @param string $value Current blogname value.
	 * @return string Translated blogname or original.
	 */
	public static function filter_blogname( $value ) {
		return self::translate_config( 'site_title', $value );
	}

	/**
	 * Filter blogdescription option.
	 *
	 * @param string $value Current blogdescription value.
	 * @return string Translated blogdescription or original.
	 */
	public static function filter_blogdescription( $value ) {
		return self::translate_config( 'site_tagline', $value );
	}

	/**
	 * Filter navigation menu item objects.
	 *
	 * Translates menu item titles and attr_titles.
	 *
	 * @param array $items Sorted array of menu item objects.
	 * @param array $args  Menu arguments.
	 * @return array Modified menu items.
	 */
	public static function filter_nav_menu_objects( $items, $args ) {
		if ( empty( $items ) ) {
			return $items;
		}

		$title_map      = self::$cache['menu_item_title'] ?? array();
		$attr_title_map = self::$cache['menu_item_attr_title'] ?? array();

		foreach ( $items as &$item ) {
			if ( ! empty( $title_map ) && ! empty( $item->title ) && isset( $title_map[ $item->title ] ) ) {
				$item->title = self::maybe_wrap_with_marker( $title_map[ $item->title ] );
			}

			if ( ! empty( $attr_title_map ) && ! empty( $item->attr_title ) && isset( $attr_title_map[ $item->attr_title ] ) ) {
				$item->attr_title = self::maybe_wrap_with_marker( $attr_title_map[ $item->attr_title ] );
			}
		}
		unset( $item );

		return $items;
	}

	/**
	 * Filter widget title.
	 *
	 * @param string $title    Widget title.
	 * @param array  $instance Widget instance settings.
	 * @param string $id_base  Widget ID base.
	 * @return string Translated widget title or original.
	 */
	public static function filter_widget_title( $title, $instance = array(), $id_base = '' ) {
		if ( empty( $title ) ) {
			return $title;
		}

		return self::translate_config( 'widget_title', $title );
	}

	/**
	 * Look up and apply a config translation.
	 *
	 * @param string $msgctxt The config context key.
	 * @param string $value   The original value.
	 * @return string Translated value or original.
	 */
	private static function translate_config( $msgctxt, $value ) {
		if ( empty( $value ) || ! isset( self::$cache[ $msgctxt ] ) ) {
			return $value;
		}

		if ( isset( self::$cache[ $msgctxt ][ $value ] ) ) {
			return self::maybe_wrap_with_marker( self::$cache[ $msgctxt ][ $value ] );
		}

		return $value;
	}

	/**
	 * Conditionally wrap translation with language marker.
	 *
	 * @param string $translated Translation content.
	 * @return string Wrapped or unwrapped content.
	 */
	private static function maybe_wrap_with_marker( $translated ) {
		if ( empty( $translated ) ) {
			return $translated;
		}

		// Use Translation_Simulation_Service if Pro is active.
		if ( class_exists( '\WPTSALL\Tasks\Services\Translation_Simulation_Service' ) ) {
			return \WPTSALL\Tasks\Services\Translation_Simulation_Service::apply_markers( $translated, self::$target_lang );
		}

		return $translated;
	}

	/**
	 * Handle translation completed event.
	 *
	 * @param int $template_id Template ID.
	 * @return void
	 */
	public static function on_translation_completed( $template_id ) {
		self::clear_cache();
	}

	/**
	 * Handle entry updated event.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param array $data     Entry data.
	 * @return void
	 */
	public static function on_entry_updated( $entry_id, $data ) {
		$text_domain = $data['text_domain'] ?? '';
		if ( 'config-site' === $text_domain || empty( $text_domain ) ) {
			self::clear_cache();
		}
	}

	/**
	 * Clear the in-memory cache.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		self::$cache = array();

		wptsall_log_debug(
			'hooks-config',
			'Config filter cache cleared'
		);
	}

	/**
	 * Check if filters are initialized.
	 *
	 * @return bool
	 */
	public static function is_initialized() {
		return self::$initialized;
	}

	/**
	 * Get cache stats for diagnostics.
	 *
	 * @return array
	 */
	public static function get_cache_stats() {
		return array(
			'initialized'  => self::$initialized,
			'relation_id'  => self::$current_relation_id,
			'entry_types'  => array_keys( self::$cache ),
			'entry_counts' => array_map( 'count', self::$cache ),
		);
	}
}
