<?php
/**
 * Translation Status Column
 *
 * Polylang-style column in wp-admin post list showing which languages a
 * given post has translations for, with links to the translation editor.
 *
 * Extends the column to ALL content plugin post types registered in
 * `wptsall_models` (is_content_plugin=1), not just `post` and `page`.
 *
 * @package WPTSALL
 * @since 1.2.0
 * @updated 1.4.0 — register for all managed post types
 */

namespace WPTSALL\TranslationStatus;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Models\Services\Plugin_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Translation_Status_Column {

	const COLUMN_SLUG = 'wptsall_translations';

	/**
	 * Cached list of post types that should show the column.
	 *
	 * Computed once per request from wptsall_models.
	 *
	 * @var array|null
	 */
	private static $managed_post_types = null;

	/**
	 * Register the column on all managed post list screens.
	 */
	public static function init() {
		foreach ( self::get_managed_post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}

		// Pass the managed post types to JS for the Quick Edit panel (P5-9).
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_quick_edit_assets' ) );
	}

	/**
	 * Build (and cache) the list of post types we manage.
	 *
	 * Strategy:
	 *   1. Read all `wptsall_models` rows where is_content_plugin=1.
	 *   2. For each, decode the post_types JSON; collect every `name`.
	 *   3. Always include `post` and `page` (default WP content).
	 *   4. Filter out post types that are not publicly registered (e.g. a
	 *      plugin that has been deactivated).
	 *
	 * @return string[]
	 */
	public static function get_managed_post_types(): array {
		if ( null !== self::$managed_post_types ) {
			return self::$managed_post_types;
		}
		$registered = get_post_types( array( 'show_ui' => true ), 'names' );
		$pt_set     = array( 'post', 'page' );

		if ( class_exists( '\\WPTSALL\\Models\\Services\\Plugin_Mapping_Service' ) ) {
			global $wpdb;
			$models_table = function_exists( 'wptsall_table' )
				? wptsall_table( 'models' )
				: $wpdb->prefix . 'wptsall_models';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$models_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $models_table ) ) === $models_table;
			if ( $models_ready ) {
				$mappings = Plugin_Mapping_Service::get_all( array( 'is_content_plugin' => 1 ) );
				foreach ( $mappings as $m ) {
					$pts = $m['post_types'] ?? array();
					foreach ( $pts as $entry ) {
						if ( is_array( $entry ) && isset( $entry['name'] ) ) {
							$name = (string) $entry['name'];
						} elseif ( is_string( $entry ) ) {
							$name = $entry;
						} else {
							continue;
						}
						if ( '' !== $name && isset( $registered[ $name ] ) ) {
							$pt_set[] = $name;
						}
					}
				}
			}
		}

		self::$managed_post_types = array_values( array_unique( $pt_set ) );
		return self::$managed_post_types;
	}

	/**
	 * Allow tests / code to reset the cache (e.g. after a model is added).
	 */
	public static function flush_cache(): void {
		self::$managed_post_types = null;
	}

	/**
	 * Enqueue a tiny script on post list screens that adds a language
	 * selector to the Quick Edit / Bulk Edit panel. The selector uses
	 * the same flag set as the column.
	 */
	public static function enqueue_quick_edit_assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base || empty( $screen->post_type ) ) {
			return;
		}
		if ( ! in_array( $screen->post_type, self::get_managed_post_types(), true ) ) {
			return;
		}
		$languages  = Language_Service::get_all( array( 'status' => 'active' ) );
		$script_rel = 'assets/js/quick-edit-language.js';
		$script_abs = WPTSALL_PATH . $script_rel;
		if ( file_exists( $script_abs ) ) {
			wp_enqueue_script(
				'wptsall-quickedit-language',
				WPTSALL_URL . $script_rel,
				array( 'jquery' ),
				filemtime( $script_abs ),
				true
			);
			wp_localize_script(
				'wptsall-quickedit-language',
				'wptsallQuickEdit',
				array(
					'langs'    => $languages,
					'postType' => $screen->post_type,
				)
			);
		}
	}

	public static function add_column( $columns ) {
		// Insert after 'title' so the column is visible without horizontal scroll.
		$new = array();
		foreach ( $columns as $k => $label ) {
			$new[ $k ] = $label;
			if ( 'title' === $k ) {
				$new[ self::COLUMN_SLUG ] = __( 'Translations', 'wpmmcc-ats' );
			}
		}
		return $new;
	}

	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN_SLUG !== $column ) {
			return;
		}
		$languages    = Language_Service::get_all( array( 'status' => 'all' ) );
		$default      = Language_Service::get_default();
		$default_code = $default ? (string) $default['code'] : 'zh_CN';
		$mappings     = self::get_mappings_for_post( $post_id );
		$out          = array();
		foreach ( $languages as $lang ) {
			$code = (string) $lang['code'];
			$has  = isset( $mappings[ $code ] );
			$is_source = ! $has && self::is_source_for( $post_id, $code, $mappings, $default_code );
			if ( $has ) {
				$tid = (int) $mappings[ $code ]['target_post_id'];
				$out[] = sprintf(
					'<a href="%s" title="%s" class="wptsall-flag wptsall-flag-yes">%s</a>',
					esc_url( get_edit_post_link( $tid ) ),
					esc_attr( sprintf( '%s → #%d', $code, $tid ) ),
					esc_html( $code )
				);
			} elseif ( $is_source ) {
				$out[] = sprintf(
					'<span class="wptsall-flag wptsall-flag-source" title="%s">%s</span>',
					esc_attr__( 'Source', 'wpmmcc-ats' ),
					esc_html( $code )
				);
			} else {
				$add_url = '';
				if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Resolver' ) ) {
					$rid = \WPTSALL\Sites\Services\Relation_Resolver::for_post_lang( (int) $post_id, $code );
					if ( $rid > 0 ) {
						$add_url = add_query_arg(
							array(
								'page'           => 'wptsall-translate',
								'source_post_id' => (int) $post_id,
								'relation_id'    => $rid,
							),
							admin_url( 'admin.php' )
						);
					}
				}
				if ( '' === $add_url ) {
					$add_url = add_query_arg(
						array(
							'page'    => 'wptsall-translate',
							'post_id' => (int) $post_id,
							'to_lang' => $code,
						),
						admin_url( 'admin.php' )
					);
				}
				$out[] = sprintf(
					'<a href="%s" title="%s" class="wptsall-flag wptsall-flag-missing">+%s</a>',
					esc_url( $add_url ),
					/* translators: %s: language code */
					esc_attr( sprintf( __( 'Add %s translation', 'wpmmcc-ats' ), $code ) ),
					esc_html( $code )
				);
			}
		}
		echo '<div class="wptsall-translation-flags">' . implode( ' ', $out ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function get_mappings_for_post( $post_id ) {
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			$full = \WPTSALL\Sites\Services\Translation_Identity::get_translations( (int) $post_id );
			$out  = array();
			foreach ( $full as $code => $row ) {
				if ( '_current_lang' === $code || ! is_array( $row ) ) {
					continue;
				}
				$out[ $code ] = $row;
			}
			return $out;
		}

		global $wpdb;
		$table      = wptsall_table( 'post_mappings' );
		$relations  = wptsall_table( 'site_relations' );
		// target_lang is stored on site_relations, joined via relation_id.
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT r.target_lang, m.target_post_id, m.source_post_id
			 FROM %i m
			 LEFT JOIN %i r ON r.id = m.relation_id
			 WHERE m.source_post_id = %d OR m.target_post_id = %d',
			$table,
			$relations,
			$post_id,
			$post_id
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$code = (string) ( $r['target_lang'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$out[ $code ] = $r;
		}
		return $out;
	}

	private static function is_source_for( $post_id, $lang_code, $mappings, $default_code ) {
		foreach ( $mappings as $m ) {
			if ( (int) $m['target_post_id'] === (int) $post_id ) {
				return false;
			}
		}
		return $default_code === $lang_code;
	}
}
