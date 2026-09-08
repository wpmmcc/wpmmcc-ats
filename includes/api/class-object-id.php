<?php
/**
 * Public object-ID API (WPML-style `wpml_object_id`).
 *
 * Themes and content plugins should resolve "this post/term/attachment in
 * language X" through this function instead of hardcoding source IDs.
 *
 * @package WPTSALL\API
 * @since 2.2.0
 */

namespace WPTSALL\API;

use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Hooks\Virtual_Site_Query_Switch;
use WPTSALL\Models\Services\Media_Mapping_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object_Id class.
 */
class Object_Id {

	/**
	 * Resolve an element to its counterpart in a target language / virtual site.
	 *
	 * @param int         $element_id               Source (or any) element ID.
	 * @param string      $element_type             post | page | {post_type} | category | post_tag | {taxonomy} | attachment | any.
	 * @param bool        $return_original_if_missing When no mapping, return original ID.
	 * @param string|null $language_code            Locale (en_US) or null = current virtual site.
	 * @return int
	 */
	public static function resolve( $element_id, $element_type = 'post', $return_original_if_missing = false, $language_code = null ) {
		$element_id = (int) $element_id;
		if ( $element_id <= 0 ) {
			return 0;
		}

		$kind = self::classify_type( (string) $element_type );
		$vs   = self::resolve_virtual_site_context( $language_code );

		if ( ! $vs ) {
			return $return_original_if_missing ? $element_id : 0;
		}

		$found = 0;
		if ( 'attachment' === $kind ) {
			$found = self::resolve_media( $element_id, $vs );
		} elseif ( 'taxonomy' === $kind ) {
			$tax   = self::taxonomy_from_type( (string) $element_type );
			$found = Virtual_Site_Query_Switch::find_shadow_term_id( $element_id, $vs, $tax );
		} else {
			$pt    = ( 'post' === $kind || 'any' === $kind ) ? '' : (string) $element_type;
			$found = Virtual_Site_Query_Switch::find_shadow_post_id( $element_id, $vs, $pt );
			if ( $found <= 0 ) {
				$found = self::resolve_via_post_mappings( $element_id, $vs );
			}
			if ( $found <= 0 && class_exists( '\\WPTSALL\\Sites\\Services\\Translation_Identity' ) ) {
				$rid = (int) ( $vs['relation_id'] ?? 0 );
				if ( $rid <= 0 && class_exists( Site_Relation_Service::class ) ) {
					$rel = Site_Relation_Service::get_relation_by_target( (string) ( $vs['id'] ?? '' ), 'virtual' );
					$rid = is_array( $rel ) ? (int) ( $rel['id'] ?? 0 ) : 0;
				}
				if ( $rid > 0 ) {
					$tid = \WPTSALL\Sites\Services\Translation_Identity::find_target( (int) $element_id, $rid, false );
					$found = $tid ? (int) $tid : 0;
				}
			}
		}

		if ( $found > 0 ) {
			return (int) $found;
		}
		return $return_original_if_missing ? $element_id : 0;
	}

	/**
	 * @param string|null $language_code Language or null.
	 * @return array|null Virtual site row.
	 */
	protected static function resolve_virtual_site_context( $language_code ) {
		if ( null === $language_code || '' === (string) $language_code ) {
			$cur = Virtual_Site_Router::get_current_virtual_site();
			if ( is_array( $cur ) ) {
				return $cur;
			}
			return null;
		}
		$lang = (string) $language_code;
		foreach ( (array) Virtual_Site_Service::get_all( array( 'status' => 'active' ) ) as $site ) {
			$site_lang = (string) ( $site['lang'] ?? $site['site_language'] ?? '' );
			if ( $site_lang === $lang || strtolower( str_replace( '-', '_', $site_lang ) ) === strtolower( str_replace( '-', '_', $lang ) ) ) {
				return $site;
			}
		}
		return null;
	}

	/**
	 * @param string $element_type Type string.
	 * @return string post|taxonomy|attachment|any
	 */
	protected static function classify_type( $element_type ) {
		$t = strtolower( trim( $element_type ) );
		if ( in_array( $t, array( 'attachment', 'media', 'image' ), true ) ) {
			return 'attachment';
		}
		if ( 'any' === $t || '' === $t ) {
			return 'any';
		}
		if ( taxonomy_exists( $t ) || 0 === strpos( $t, 'tax_' ) || in_array( $t, array( 'category', 'post_tag', 'tag' ), true ) ) {
			return 'taxonomy';
		}
		return 'post';
	}

	/**
	 * @param string $element_type Type.
	 * @return string
	 */
	protected static function taxonomy_from_type( $element_type ) {
		$t = strtolower( trim( $element_type ) );
		if ( 0 === strpos( $t, 'tax_' ) ) {
			return substr( $t, 4 );
		}
		if ( 'tag' === $t ) {
			return 'post_tag';
		}
		return $t;
	}

	/**
	 * @param int   $source_id Source attachment.
	 * @param array $vs        Virtual site.
	 * @return int
	 */
	protected static function resolve_media( $source_id, array $vs ) {
		$relation_id = (int) ( $vs['relation_id'] ?? 0 );
		if ( $relation_id > 0 && class_exists( Media_Mapping_Service::class ) ) {
			$mapped = Media_Mapping_Service::get_mapped_id( $relation_id, $source_id );
			if ( $mapped ) {
				return (int) $mapped;
			}
		}
		$target_site = (string) ( $vs['id'] ?? '' );
		if ( '' !== $target_site && class_exists( Media_Mapping_Service::class ) ) {
			$m = Media_Mapping_Service::get_mapping( $source_id, get_current_blog_id(), $target_site, $relation_id );
			if ( $m && ! empty( $m['target_media_id'] ) ) {
				return (int) $m['target_media_id'];
			}
			// Also try v_ prefix candidate.
			if ( 0 !== strpos( $target_site, 'v_' ) ) {
				$m = Media_Mapping_Service::get_mapping( $source_id, get_current_blog_id(), 'v_' . $target_site, $relation_id );
				if ( $m && ! empty( $m['target_media_id'] ) ) {
					return (int) $m['target_media_id'];
				}
			}
		}
		// Shadow attachment posts (copy mode / manual) use the same meta as posts.
		$shadow = Virtual_Site_Query_Switch::find_shadow_post_id( $source_id, $vs, 'attachment' );
		if ( $shadow > 0 ) {
			return (int) $shadow;
		}
		return 0;
	}

	/**
	 * Fallback: post_mappings by relation / target_site_id.
	 *
	 * @param int   $source_id Source post.
	 * @param array $vs        Virtual site.
	 * @return int
	 */
	protected static function resolve_via_post_mappings( $source_id, array $vs ) {
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';
		$relation_id = (int) ( $vs['relation_id'] ?? 0 );
		$site_id     = (string) ( $vs['id'] ?? '' );
		$candidates  = array_filter( array( $site_id, ( 0 === strpos( $site_id, 'v_' ) ) ? $site_id : 'v_' . $site_id ) );

		if ( $relation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$tid = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT target_post_id FROM %i WHERE source_post_id = %d AND relation_id = %d AND target_post_id > 0 ORDER BY id DESC LIMIT 1',
					$table,
					$source_id,
					$relation_id
				)
			);
			if ( $tid > 0 ) {
				return $tid;
			}
		}
		foreach ( $candidates as $cid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$tid = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT target_post_id FROM %i WHERE source_post_id = %d AND target_site_id = %s AND target_post_id > 0 ORDER BY id DESC LIMIT 1',
					$table,
					$source_id,
					$cid
				)
			);
			if ( $tid > 0 ) {
				return $tid;
			}
		}
		return 0;
	}
}
