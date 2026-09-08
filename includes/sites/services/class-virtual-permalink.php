<?php
/**
 * Virtual Permalink — CPT-agnostic public URL for shadow / virtual content.
 *
 * Polylang pattern: resolve the peer post, then use core permalink helpers so
 * Woo `product/{slug}` and any CPT rewrite slug work without hardcoding.
 * Prefixing is delegated to existing `post_link` / `post_type_link` filters
 * and {@see Url_Converter::virtualize()}.
 *
 * @package WPTSALL\Sites\Services
 * @since 2.3.0
 */

namespace WPTSALL\Sites\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Virtual_Permalink class.
 */
class Virtual_Permalink {

	/**
	 * Build the public URL for a post under a virtual site / relation context.
	 *
	 * @param int|\WP_Post   $post    Target (shadow) post ID or object.
	 * @param array|int|null $context Virtual site row, site relation row, relation ID, or null
	 *                                (infer from post meta `_wptsall_virtual_site_id` / `_wptsall_relation_id`).
	 * @return string Absolute URL or empty string.
	 */
	public static function for_post( $post, $context = null ): string {
		$post = get_post( $post );
		if ( ! $post || empty( $post->ID ) ) {
			return '';
		}

		$url = get_permalink( $post );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$vs = self::resolve_virtual_site( $context, $post );
		if ( ! is_array( $vs ) || empty( $vs['path_prefix'] ) ) {
			return $url;
		}

		if ( class_exists( Url_Converter::class ) ) {
			return Url_Converter::virtualize( $url, $vs, $post );
		}

		return $url;
	}

	/**
	 * Build URL from a site relation (virtual target).
	 *
	 * @param int|\WP_Post $post        Target post.
	 * @param int|array    $relation_id Relation ID or relation row.
	 * @return string
	 */
	public static function for_relation_post( $post, $relation_id ): string {
		return self::for_post( $post, $relation_id );
	}

	/**
	 * Resolve a virtual site row from context or post meta.
	 *
	 * @param array|int|null $context Context.
	 * @param \WP_Post       $post    Post.
	 * @return array|null
	 */
	private static function resolve_virtual_site( $context, \WP_Post $post ) {
		if ( is_array( $context ) ) {
			if ( ! empty( $context['path_prefix'] ) || isset( $context['lang'] ) ) {
				// Looks like a virtual site row.
				if ( ! empty( $context['path_prefix'] ) ) {
					return $context;
				}
			}
			if ( isset( $context['target_site_id'] ) || isset( $context['target_site_type'] ) ) {
				return self::vs_from_relation_row( $context );
			}
			if ( isset( $context['id'] ) && isset( $context['target_lang'] ) ) {
				return self::vs_from_relation_row( $context );
			}
		}

		if ( is_int( $context ) || ( is_string( $context ) && ctype_digit( (string) $context ) ) ) {
			$rid = (int) $context;
			if ( $rid > 0 && class_exists( Virtual_Site_Service::class ) ) {
				$vs = Virtual_Site_Service::get_by_relation( $rid );
				if ( is_array( $vs ) ) {
					return $vs;
				}
				if ( class_exists( Site_Relation_Service::class ) ) {
					$rel = Site_Relation_Service::get_relation( $rid );
					if ( is_array( $rel ) ) {
						return self::vs_from_relation_row( $rel );
					}
				}
			}
		}

		$vs_id = (string) Translation_Identity::raw_meta( (int) $post->ID, Translation_Identity::META_VIRTUAL_SITE_ID );
		if ( $vs_id && class_exists( Virtual_Site_Service::class ) ) {
			$vs = Virtual_Site_Service::get( $vs_id );
			if ( is_array( $vs ) ) {
				return $vs;
			}
		}

		$rel_id = (int) Translation_Identity::raw_meta( (int) $post->ID, Translation_Identity::META_RELATION_ID );
		if ( $rel_id > 0 && class_exists( Virtual_Site_Service::class ) ) {
			$vs = Virtual_Site_Service::get_by_relation( $rel_id );
			if ( is_array( $vs ) ) {
				return $vs;
			}
		}

		return null;
	}

	/**
	 * Map a site relation row to a virtual site row.
	 *
	 * @param array $relation Relation row.
	 * @return array|null
	 */
	private static function vs_from_relation_row( array $relation ) {
		if ( class_exists( Virtual_Site_Service::class ) ) {
			$rid = (int) ( $relation['id'] ?? 0 );
			if ( $rid > 0 ) {
				$vs = Virtual_Site_Service::get_by_relation( $rid );
				if ( is_array( $vs ) ) {
					return $vs;
				}
			}
			$site_id = (string) ( $relation['target_site_id'] ?? '' );
			if ( '' !== $site_id ) {
				$vs = Virtual_Site_Service::get( $site_id );
				if ( is_array( $vs ) ) {
					return $vs;
				}
			}
		}

		$path_prefix = trim( (string) ( $relation['target_theme_path'] ?? '' ), '/' );
		if ( '' === $path_prefix && ! empty( $relation['target_lang'] ) ) {
			$path_prefix = sanitize_title( (string) $relation['target_lang'] );
		}
		if ( '' === $path_prefix ) {
			return null;
		}

		return array(
			'id'          => (string) ( $relation['target_site_id'] ?? '' ),
			'path_prefix' => $path_prefix,
			'lang'        => (string) ( $relation['target_lang'] ?? '' ),
		);
	}
}
