<?php
/**
 * URL Discovery Service (P5-4)
 *
 * Given a URL (or list of URLs) on the source site, find its counterpart
 * on a target site by URL pattern matching. The typical workflow is:
 *
 *   1. Admin pastes source URL: `/zh-cn/products/foo/`
 *   2. Service computes expected target URL by swapping the path prefix
 *      (e.g. `zh_CN` → `en_US`): `/en-us/products/foo/`
 *   3. Service does a HEAD/GET to the target URL, looks for the post id
 *   4. If found, creates a post_mapping linking source → target
 *
 * Supports:
 *   - Single URL
 *   - Bulk URLs (textarea)
 *   - Custom path-prefix → language mapping
 *   - Path-prefix variants (zh_cn vs zh-cn, case-insensitive)
 *
 * @package WPTSALL\ManualTranslation
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Services;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\Models\Services\Post_Mapping_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Url_Discovery_Service {

	/**
	 * Discover translations for a list of source URLs against one target language.
	 *
	 * @since 1.4.0
	 *
	 * @param array  $urls            List of source URLs (relative to home_url).
	 * @param string $source_lang     Source language code (e.g. zh_CN).
	 * @param string $target_lang     Target language code (e.g. en_US).
	 * @param int    $source_site_id  Source site/blog id (default: current).
	 * @return array {
	 *     @type array $results   Per-URL result list.
	 *     @type int   $found     Count of successfully discovered mappings.
	 *     @type int   $missing   Count of URLs without a target counterpart.
	 *     @type int   $errors    Count of URLs that errored.
	 * }
	 */
	public static function discover_bulk( array $urls, string $source_lang, string $target_lang, int $source_site_id = 0 ): array {
		$results = array();
		$found   = 0;
		$missing = 0;
		$errors  = 0;
		$source_site_id = $source_site_id > 0 ? $source_site_id : get_current_blog_id();

		$source_prefix = self::build_prefix( $source_lang );
		$target_prefix = self::build_prefix( $target_lang );

		foreach ( $urls as $raw_url ) {
			$url = esc_url_raw( trim( $raw_url ) );
			if ( '' === $url ) {
				continue;
			}
			$parsed = wp_parse_url( $url );
			if ( empty( $parsed['path'] ) ) {
				$results[] = array(
					'url'     => $url,
					'status'  => 'error',
					'message' => __( 'URL has no path', 'wpmmcc-ats' ),
				);
				++$errors;
				continue;
			}

			$path = $parsed['path'];
			$source_match = self::match_path_prefix( $path, $source_prefix, $source_lang );
			$target_path  = null !== $source_match ? $target_prefix . $source_match : $target_prefix . $path;

			$source_post_id = url_to_postid( home_url( $path ) );
			if ( $source_post_id <= 0 ) {
				$results[] = array(
					'url'         => $url,
					'status'      => 'error',
					'message'     => __( 'Source URL did not resolve to a post', 'wpmmcc-ats' ),
					'source_path' => $path,
					'target_path' => $target_path,
				);
				++$errors;
				continue;
			}

			$target_post_id = self::find_post_id_by_path( $target_path, $source_site_id );
			if ( $target_post_id <= 0 ) {
				$results[] = array(
					'url'            => $url,
					'status'         => 'missing',
					'source_post_id' => $source_post_id,
					'source_path'    => $path,
					'target_path'    => $target_path,
					'message'        => __( 'No target post found at the expected path', 'wpmmcc-ats' ),
				);
				++$missing;
				continue;
			}

			$source_post = get_post( $source_post_id );
			$target_post = get_post( $target_post_id );
			$mapping_id  = Post_Mapping_Service::create_mapping( array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => $source_post->post_type ?? 'post',
				'source_site_id'    => $source_site_id,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => $target_post->post_type ?? 'post',
				'target_site_id'    => (string) $source_site_id,
				'relationship_type' => 'translation',
			) );
			$results[] = array(
				'url'            => $url,
				'status'         => $mapping_id ? 'found' : 'error',
				'source_post_id' => $source_post_id,
				'target_post_id' => $target_post_id,
				'source_path'    => $path,
				'target_path'    => $target_path,
				'mapping_id'     => $mapping_id,
				'message'        => $mapping_id
					? __( 'Mapping created', 'wpmmcc-ats' )
					: __( 'Mapping creation failed', 'wpmmcc-ats' ),
			);
			$mapping_id ? ++$found : ++$errors;
		}

		// Audit log hook (P6-3).
		do_action( 'wptsall_url_discovery_bulk', $urls, $source_lang, $target_lang );

		return array(
			'results' => $results,
			'found'   => $found,
			'missing' => $missing,
			'errors'  => $errors,
		);
	}

	/**
	 * Find a post id by its path (e.g. /en-us/products/foo/).
	 *
	 * @since 1.4.0
	 *
	 * @param string $path    Relative path.
	 * @param int    $site_id Blog id (uses current if 0).
	 * @return int Post id or 0 if not found.
	 */
	private static function find_post_id_by_path( string $path, int $site_id = 0 ): int {
		$id = url_to_postid( home_url( $path ) );
		if ( $id > 0 ) {
			return $id;
		}
		$slug = trim( basename( untrailingslashit( $path ) ) );
		if ( '' === $slug ) {
			return 0;
		}
		$posts = get_posts( array(
			'name'        => $slug,
			'post_type'   => 'any',
			'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'numberposts' => 1,
		) );
		return $posts ? (int) $posts[0]->ID : 0;
	}

	/**
	 * Build the canonical path prefix for a language code.
	 *
	 * Looks up the stored slug from `wptsall_languages` first, then falls back
	 * to a normalized lowercase dash-separated form of the code.
	 *
	 * @since 1.4.0
	 */
	private static function build_prefix( string $lang_code ): string {
		$langs = Language_Service::get_all( array( 'status' => 'all' ) );
		$slug  = '';
		foreach ( $langs as $lang ) {
			if ( isset( $lang['code'] ) && (string) $lang['code'] === $lang_code ) {
				$slug = (string) ( $lang['slug'] ?? '' );
				break;
			}
		}
		if ( '' === $slug ) {
			$slug = strtolower( str_replace( '_', '-', $lang_code ) );
		}
		return '/' . ltrim( $slug, '/' );
	}

	/**
	 * Find the prefix in $path that matches the language code (allowing
	 * dash / underscore / case variants), and return the path remainder.
	 *
	 * @since 1.4.0
	 *
	 * @return string|null Path remainder (with the matched prefix stripped) or null.
	 */
	private static function match_path_prefix( string $path, string $prefix, string $lang_code ): ?string {
		if ( '' === $prefix || '/' === $prefix ) {
			return null;
		}
		$code = strtolower( str_replace( '_', '-', (string) $lang_code ) );
		$candidates = array_values( array_unique( array_filter( array(
			$prefix,
			'/' . $code,
			'/' . str_replace( '-', '_', $code ),
		) ) ) );
		foreach ( $candidates as $cand ) {
			if ( 0 === strpos( $path, $cand ) ) {
				return substr( $path, strlen( $cand ) );
			}
		}
		return null;
	}

	/**
	 * Get a quick discovery preview for one URL (without writing to DB).
	 *
	 * @since 1.4.0
	 *
	 * @param string $url         Source URL.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return array{
	 *     @type string $source_path
	 *     @type string $target_path
	 *     @type int    $source_post_id
	 *     @type int    $target_post_id
	 *     @type string $source_post_title
	 *     @type string $target_post_title
	 *     @type bool   $exists
	 * }
	 */
	public static function preview( string $url, string $source_lang, string $target_lang ): array {
		$url    = esc_url_raw( trim( $url ) );
		$parsed = wp_parse_url( $url );
		$path   = $parsed['path'] ?? '';

		$source_prefix = self::build_prefix( $source_lang );
		$target_prefix = self::build_prefix( $target_lang );

		$source_match = self::match_path_prefix( $path, $source_prefix, $source_lang );
		$target_path  = null !== $source_match ? $target_prefix . $source_match : $target_prefix . $path;

		$source_post_id = url_to_postid( home_url( $path ) );
		$target_post_id = self::find_post_id_by_path( $target_path );

		$source_post = $source_post_id ? get_post( $source_post_id ) : null;
		$target_post = $target_post_id ? get_post( $target_post_id ) : null;

		return array(
			'source_path'       => $path,
			'target_path'       => $target_path,
			'source_post_id'    => (int) ( $source_post_id ?? 0 ),
			'target_post_id'    => (int) ( $target_post_id ?? 0 ),
			'source_post_title' => $source_post ? $source_post->post_title : '',
			'target_post_title' => $target_post ? $target_post->post_title : '',
			'exists'            => $source_post_id > 0 && $target_post_id > 0,
		);
	}
}
