<?php
/**
 * Translation Status Column for Taxonomy Terms (P5-1 term)
 *
 * Polylang-style column in wp-admin term list (`edit-tags.php`) showing
 * which languages a given term has translations for, with links to the
 * translation editor.
 *
 * @package WPTSALL
 * @since 1.4.0
 */

namespace WPTSALL\TranslationStatus;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Models\Services\Plugin_Mapping_Service;
use WPTSALL\Models\Services\Term_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translation_Status_Term_Column {

	const COLUMN_SLUG = 'wptsall_term_translations';

	/** @var array|null */
	private static $managed_taxonomies = null;

	public static function init() {
		foreach ( self::get_managed_taxonomies() as $tx ) {
			add_filter( "manage_edit-{$tx}_columns", array( __CLASS__, 'add_column' ) );
			add_filter( "manage_{$tx}_custom_column", array( __CLASS__, 'render_column' ), 10, 3 );
		}
	}

	/**
	 * Build (and cache) the list of taxonomies we manage.
	 */
	public static function get_managed_taxonomies(): array {
		if ( null !== self::$managed_taxonomies ) {
			return self::$managed_taxonomies;
		}
		$registered = get_taxonomies( array( 'show_ui' => true ), 'names' );
		$tx_set     = array( 'category', 'post_tag' );

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
					$taxes = $m['taxonomies'] ?? array();
					foreach ( $taxes as $entry ) {
						if ( is_array( $entry ) && isset( $entry['name'] ) ) {
							$name = (string) $entry['name'];
						} elseif ( is_string( $entry ) ) {
							$name = $entry;
						} else {
							continue;
						}
						if ( '' !== $name && isset( $registered[ $name ] ) ) {
							$tx_set[] = $name;
						}
					}
				}
			}
		}

		self::$managed_taxonomies = array_values( array_unique( $tx_set ) );
		return self::$managed_taxonomies;
	}

	public static function flush_cache(): void {
		self::$managed_taxonomies = null;
	}

	public static function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $k => $label ) {
			$new[ $k ] = $label;
			if ( 'name' === $k ) {
				$new[ self::COLUMN_SLUG ] = __( 'Translations', 'wpmmcc-ats' );
			}
		}
		return $new;
	}

	public static function render_column( $out, $column, $term_id ) {
		if ( self::COLUMN_SLUG !== $column ) {
			return $out;
		}
		$term = get_term( (int) $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return $out;
		}
		$languages    = Language_Service::get_all( array( 'status' => 'all' ) );
		$default      = Language_Service::get_default();
		$default_code = $default ? (string) $default['code'] : 'zh_CN';
		$mappings     = self::get_term_translations( (int) $term_id, $term->taxonomy );
		$flags        = array();
		foreach ( $languages as $lang ) {
			$code = (string) $lang['code'];
			$tid  = isset( $mappings[ $code ] ) ? (int) $mappings[ $code ] : 0;
			if ( $tid > 0 && (int) $tid !== (int) $term_id ) {
				$edit_url = get_edit_term_link( $tid, $term->taxonomy );
				$flags[] = sprintf(
					'<a href="%s" title="%s" class="wptsall-flag wptsall-flag-yes">%s</a>',
					esc_url( $edit_url ),
					esc_attr( sprintf( '%s → #%d', $code, $tid ) ),
					esc_html( $code )
				);
			} elseif ( $tid > 0 && (int) $tid === (int) $term_id ) {
				$flags[] = sprintf(
					'<span class="wptsall-flag wptsall-flag-source" title="%s">%s</span>',
					esc_attr__( 'Source', 'wpmmcc-ats' ),
					esc_html( $code )
				);
			} elseif ( $code === $default_code ) {
				$flags[] = sprintf(
					'<span class="wptsall-flag wptsall-flag-source" title="%s">%s</span>',
					esc_attr__( 'Source', 'wpmmcc-ats' ),
					esc_html( $code )
				);
			} else {
				$flags[] = sprintf(
					'<a href="%s" title="%s" class="wptsall-flag wptsall-flag-missing">+%s</a>',
					esc_url( admin_url( 'admin.php?page=wptsall-tax-translations&term_id=' . (int) $term_id . '&to_lang=' . $code ) ),
					/* translators: %s: <value> */
					esc_attr( sprintf( __( 'Add %s translation', 'wpmmcc-ats' ), $code ) ),
					esc_html( $code )
				);
			}
		}
		return '<div class="wptsall-translation-flags">' . implode( ' ', $flags ) . '</div>';
	}

	private static function get_term_translations( int $term_id, string $taxonomy ): array {
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
			return array();
		}
		$site_id = get_current_blog_id();
		$rows    = Term_Mapping_Service::get_all_mappings_for_source( $term_id, $taxonomy, $site_id );
		$out     = array();
		foreach ( (array) $rows as $r ) {
			$code = (string) ( $r['target_lang'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$out[ $code ] = (int) ( $r['target_term_id'] ?? 0 );
		}
		return $out;
	}
}
