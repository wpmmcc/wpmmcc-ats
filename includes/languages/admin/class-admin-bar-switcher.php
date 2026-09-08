<?php
/**
 * Admin Bar Language Switcher
 *
 * Polylang-style language switcher in wp-admin bar. Shows the current post's
 * translation status (e.g. "53847 → en_US, de_DE, + add") when on singular
 * post/page edit screens.
 *
 * @package WPTSALL
 * @since 1.2.0
  * phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin list/render $_GET filters; state-changing handlers use check_admin_referer / check_ajax_referer.
 */
namespace WPTSALL\Languages\Admin;

use WPTSALL\Languages\Services\Language_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and intentional meta/tax lookups; all dynamic values go through $wpdb->prepare() / wptsall_db_* helpers (no unprepared user input).
class Admin_Bar_Switcher {

	public static function init() {
		// Front-end + back-end admin bar (priority 100 to be near the right side).
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_node' ), 100 );
	}

	/**
	 * Detect the current post id (post editor / front-end singular) and emit
	 * a dropdown listing the post's translations across known languages.
	 */
	public static function add_node( $bar ) {
		// Skip on network admin / user admin screens.
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$post_id = self::detect_post_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Find all translations for this source post (unified identity API).
		$mappings = array();
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
			$full = \WPTSALL\Sites\Services\Translation_Identity::get_translations( (int) $post_id );
			foreach ( $full as $code => $row ) {
				if ( '_current_lang' === $code || ! is_array( $row ) ) {
					continue;
				}
				$mappings[] = array(
					'target_lang'    => (string) ( $row['target_lang'] ?? $code ),
					'target_post_id' => (int) ( $row['target_post_id'] ?? 0 ),
					'target_site_id' => (string) ( $row['target_site_id'] ?? '' ),
					'relation_id'    => (int) ( $row['relation_id'] ?? 0 ),
					'target_site_type' => 'virtual',
				);
			}
		} else {
			global $wpdb;
			$mappings_table  = wptsall_table( 'post_mappings' );
			$relations_table = wptsall_table( 'site_relations' );
			$mappings = $wpdb->get_results( $wpdb->prepare(
				'SELECT r.target_lang, m.target_post_id, m.target_site_id, m.relation_id, r.target_site_type
				 FROM %i m
				 LEFT JOIN %i r ON r.id = m.relation_id
				 WHERE m.source_post_id = %d AND m.target_post_id > 0',
				$mappings_table,
				$relations_table,
				$post_id
			), ARRAY_A );
		}

		$languages = Language_Service::get_all( array( 'status' => 'all' ) );
		$lang_map  = array();
		foreach ( $languages as $l ) {
			$lang_map[ (string) $l['code'] ] = $l;
		}
		$default = Language_Service::get_default();
		$default_code = $default ? (string) $default['code'] : '';

		// Build the "current" node.
		$current_code = self::detect_post_lang( $post, $mappings, $default_code );
		$current_label = $current_code && isset( $lang_map[ $current_code ] )
			? $lang_map[ $current_code ]['code']
			: '—';
		$title = sprintf( '%s %s', "\xF0\x9F\x8C\x90", $current_label );

		$bar->add_node( array(
			'id'    => 'wptsall-lang-switcher',
			'title' => $title,
			'href'  => false,
			'meta'  => array( 'title' => __( 'WPTSALL language switcher', 'wpmmcc-ats' ) ),
		) );

		// "Current" entry.
		$bar->add_node( array(
			'parent' => 'wptsall-lang-switcher',
			'id'     => 'wptsall-lang-current',
			'title'  => sprintf( '%s %s — %s', "\xE2\x9C\x89", __( 'Current', 'wpmmcc-ats' ), $current_code ?: __( 'unknown', 'wpmmcc-ats' ) ),
			'href'  => get_edit_post_link( $post_id ),
		) );

		// One node per existing translation.
		$seen = array();
		foreach ( (array) $mappings as $m ) {
			$code = (string) $m['target_lang'];
			if ( '' === $code || isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;
			$label = isset( $lang_map[ $code ] ) ? $lang_map[ $code ]['code'] : $code;
			$url = self::url_for_translation( $m, $post, $code );
			$bar->add_node( array(
				'parent' => 'wptsall-lang-switcher',
				'id'     => 'wptsall-lang-' . sanitize_key( $code ),
				'title'  => $label . ' → #' . (int) $m['target_post_id'],
				'href'  => $url ?: get_edit_post_link( (int) $m['target_post_id'] ),
			) );
		}

		// "+ Add translation" entry — only on edit screens.
		if ( is_admin() && current_user_can( 'edit_post', $post_id ) ) {
			$add_args = array(
				'page'    => 'wptsall-translate',
				'post_id' => (int) $post_id,
			);
			if ( class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Resolver' ) ) {
				$rid = \WPTSALL\Sites\Services\Relation_Resolver::for_post_default( (int) $post_id );
				if ( $rid > 0 ) {
					$add_args = array(
						'page'           => 'wptsall-translate',
						'source_post_id' => (int) $post_id,
						'relation_id'    => $rid,
					);
				}
			}
			$add_url = add_query_arg( $add_args, admin_url( 'admin.php' ) );
			$bar->add_node( array(
				'parent' => 'wptsall-lang-switcher',
				'id'     => 'wptsall-lang-add',
				'title'  => '+ ' . __( 'Add translation', 'wpmmcc-ats' ),
				'href'  => $add_url,
			) );
		}
	}

	/**
	 * Try to identify the current post id from various admin / front-end contexts.
	 */
	private static function detect_post_id() {
		if ( is_admin() ) {
			global $pagenow;
			if ( 'post.php' === $pagenow && ! empty( $_GET['post'] ) ) {
				return (int) $_GET['post'];
			}
			if ( 'post-new.php' === $pagenow ) {
				return 0; // new post, no mapping yet
			}
			if ( 'edit.php' === $pagenow && ! empty( $_GET['post'] ) ) {
				return (int) $_GET['post'];
			}
			return 0;
		}
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}

	/**
	 * Best-effort detect of the post's current language code.
	 */
	private static function detect_post_lang( $post, $mappings, $default_code ) {
		// If post has _wptsall_virtual_site_id meta, look at relation's target_lang.
		$vs = \WPTSALL\Sites\Services\Translation_Identity::raw_meta( (int) $post->ID, \WPTSALL\Sites\Services\Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( $vs ) {
			foreach ( (array) $mappings as $m ) {
				if ( (string) $m['target_site_id'] === (string) $vs ) {
					return (string) $m['target_lang'];
				}
			}
		}
		// Heuristic: if post id is in post_mappings.target_post_id, that means it's a target.
		foreach ( (array) $mappings as $m ) {
			if ( (int) $m['target_post_id'] === (int) $post->ID ) {
				return (string) $m['target_lang'];
			}
		}
		// Heuristic: if it's the source (no mapping target hits), return default.
		return $default_code;
	}

	/**
	 * Build the front-end URL for a translation (CPT rewrite–aware).
	 */
	private static function url_for_translation( $mapping, $source_post, $lang_code ) {
		unset( $source_post, $lang_code );
		$target_id = (int) ( $mapping['target_post_id'] ?? 0 );
		if ( $target_id <= 0 ) {
			return '';
		}
		$context = null;
		$rel_id  = (int) ( $mapping['relation_id'] ?? 0 );
		if ( $rel_id > 0 ) {
			$context = $rel_id;
		} elseif ( ! empty( $mapping['target_site_id'] ) ) {
			$context = array(
				'target_site_id' => (string) $mapping['target_site_id'],
			);
		}
		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Permalink' ) ) {
			return \WPTSALL\Sites\Services\Virtual_Permalink::for_post( $target_id, $context );
		}
		$url = get_permalink( $target_id );
		return is_string( $url ) ? $url : '';
	}
}
