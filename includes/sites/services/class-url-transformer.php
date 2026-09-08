<?php
/**
 * URL Transformer Service
 *
 * Handles URL format transformations between different permalink structures.
 *
 * @package WPTSALL
 * @subpackage Sites\Services
 * @since 0.6.0
 */

namespace WPTSALL\Sites\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL Transformer Class
 *
 * Transforms URLs between different permalink structures (e.g., /%postname%/ to /%year%/%monthnum%/%postname%/).
 * Used for virtual sites that have different permalink settings than their source sites.
 */
class URL_Transformer {

	/**
	 * WordPress default permalink structures.
	 *
	 * @var array
	 */
	const STRUCTURES = array(
		'plain'                                 => '', // ?p=123
		'day_and_name'                          => '/%year%/%monthnum%/%day%/%postname%/',
		'month_and_name'                        => '/%year%/%monthnum%/%postname%/',
		'numeric'                               => '/archives/%post_id%',
		'post_name'                             => '/%postname%/',
	);

	/**
	 * Parse a URL to extract content information.
	 *
	 * @param string $url                  The URL to parse.
	 * @param string $permalink_structure  The permalink structure used to parse.
	 * @param string $path_prefix          Optional path prefix to strip (e.g., 'en/').
	 * @return array|null Parsed content info or null if cannot parse.
	 */
	public static function parse_url( $url, $permalink_structure = '', $path_prefix = '' ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return null;
		}

		// Handle plain permalink (?p=123) - check query string BEFORE path processing.
		// Plain permalinks use query parameters regardless of path.
		if ( empty( $permalink_structure ) || 'plain' === $permalink_structure ) {
			$query_string = wp_parse_url( $url, PHP_URL_QUERY );
			if ( $query_string ) {
				parse_str( $query_string, $params );
				if ( isset( $params['p'] ) ) {
					return array(
						'type'    => 'post',
						'post_id' => (int) $params['p'],
					);
				}
				if ( isset( $params['page_id'] ) ) {
					return array(
						'type'    => 'page',
						'post_id' => (int) $params['page_id'],
					);
				}
				if ( isset( $params['cat'] ) ) {
					return array(
						'type'    => 'category',
						'term_id' => (int) $params['cat'],
					);
				}
				if ( isset( $params['tag'] ) ) {
					return array(
						'type' => 'tag',
						'slug' => sanitize_title( $params['tag'] ),
					);
				}
			}
			// For plain permalinks, if no query params, return null (not home).
			return null;
		}

		// Strip path prefix if present.
		if ( ! empty( $path_prefix ) ) {
			$prefix = '/' . trim( $path_prefix, '/' ) . '/';
			if ( strpos( $path, $prefix ) === 0 ) {
				$path = substr( $path, strlen( $prefix ) - 1 );
			}
		}

		$path = trim( $path, '/' );
		if ( empty( $path ) ) {
			return array( 'type' => 'home' );
		}

		// Handle numeric permalink (/archives/123).
		if ( strpos( $permalink_structure, '%post_id%' ) !== false ) {
			if ( preg_match( '#^archives/(\d+)/?$#', $path, $matches ) ) {
				return array(
					'type'    => 'post',
					'post_id' => (int) $matches[1],
				);
			}
		}

		// Handle date-based and postname permalinks.
		$patterns = array(
			// Day and name.
			'/%year%/%monthnum%/%day%/%postname%/' => '#^(\d{4})/(\d{2})/(\d{2})/([^/]+)/?$#',
			// Month and name.
			'/%year%/%monthnum%/%postname%/'       => '#^(\d{4})/(\d{2})/([^/]+)/?$#',
			// Post name only.
			'/%postname%/'                         => '#^([^/]+)/?$#',
		);

		if ( isset( $patterns[ $permalink_structure ] ) ) {
			$pattern = $patterns[ $permalink_structure ];
			if ( preg_match( $pattern, $path, $matches ) ) {
				$result = array(
					'type' => 'post',
					'slug' => end( $matches ),
				);

				// Extract date components if present.
				if ( strpos( $permalink_structure, '%year%' ) !== false ) {
					$result['year'] = $matches[1];
					$result['month'] = $matches[2];
					if ( strpos( $permalink_structure, '%day%' ) !== false ) {
						$result['day'] = $matches[3];
					}
				}

				return $result;
			}
		}

		// Try to match custom permalink structure.
		return self::parse_custom_structure( $path, $permalink_structure );
	}

	/**
	 * Parse a custom permalink structure.
	 *
	 * @param string $path                 The URL path.
	 * @param string $permalink_structure  The custom structure.
	 * @return array|null
	 */
	private static function parse_custom_structure( $path, $permalink_structure ) {
		$structure = trim( $permalink_structure, '/' );

		// Build regex from structure.
		$regex = preg_quote( $structure, '#' );
		$regex = str_replace(
			array( '%year%', '%monthnum%', '%day%', '%postname%', '%post_id%', '%category%', '%author%' ),
			array( '(\d{4})', '(\d{2})', '(\d{2})', '([^/]+)', '(\d+)', '([^/]+)', '([^/]+)' ),
			$regex
		);
		$regex = '#^' . $regex . '/?$#';

		if ( preg_match( $regex, $path, $matches ) ) {
			$result = array( 'type' => 'post' );

			// Map matches to fields.
			$placeholders = array();
			preg_match_all( '/%(\w+)%/', $structure, $placeholder_matches );
			if ( ! empty( $placeholder_matches[1] ) ) {
				$placeholders = $placeholder_matches[1];
			}

			foreach ( $placeholders as $i => $placeholder ) {
				if ( isset( $matches[ $i + 1 ] ) ) {
					switch ( $placeholder ) {
						case 'year':
							$result['year'] = $matches[ $i + 1 ];
							break;
						case 'monthnum':
							$result['month'] = $matches[ $i + 1 ];
							break;
						case 'day':
							$result['day'] = $matches[ $i + 1 ];
							break;
						case 'postname':
							$result['slug'] = $matches[ $i + 1 ];
							break;
						case 'post_id':
							$result['post_id'] = (int) $matches[ $i + 1 ];
							break;
						case 'category':
							$result['category'] = $matches[ $i + 1 ];
							break;
						case 'author':
							$result['author'] = $matches[ $i + 1 ];
							break;
					}
				}
			}

			return $result;
		}

		return null;
	}

	/**
	 * Generate a URL from content information.
	 *
	 * @param array  $content              Content info with type, slug, year, month, day, post_id.
	 * @param string $permalink_structure  Target permalink structure.
	 * @param string $path_prefix          Path prefix to prepend (e.g., 'en').
	 * @return string The generated URL path.
	 */
	public static function generate_url( $content, $permalink_structure, $path_prefix = '' ) {
		if ( empty( $content ) || empty( $content['type'] ) ) {
			return '';
		}

		$path = '';

		// Handle plain permalink.
		if ( empty( $permalink_structure ) || 'plain' === $permalink_structure ) {
			if ( isset( $content['post_id'] ) ) {
				$path = '?p=' . $content['post_id'];
			} elseif ( isset( $content['slug'] ) ) {
				// Need to look up the post by slug.
				$post = self::get_post_by_slug( $content['slug'] );
				if ( $post ) {
					$path = '?p=' . $post->ID;
				}
			}
		} elseif ( strpos( $permalink_structure, '%post_id%' ) !== false ) {
			// Numeric permalink.
			$post_id = $content['post_id'] ?? null;
			if ( ! $post_id && isset( $content['slug'] ) ) {
				$post = self::get_post_by_slug( $content['slug'] );
				if ( $post ) {
					$post_id = $post->ID;
				}
			}
			if ( $post_id ) {
				$path = '/archives/' . $post_id;
			}
		} else {
			// Build URL from structure.
			$path = $permalink_structure;

			// Replace placeholders.
			if ( isset( $content['year'] ) ) {
				$path = str_replace( '%year%', $content['year'], $path );
			}
			if ( isset( $content['month'] ) ) {
				$path = str_replace( '%monthnum%', $content['month'], $path );
			}
			if ( isset( $content['day'] ) ) {
				$path = str_replace( '%day%', $content['day'], $path );
			}
			if ( isset( $content['slug'] ) ) {
				$path = str_replace( '%postname%', $content['slug'], $path );
			}
			if ( isset( $content['post_id'] ) ) {
				$path = str_replace( '%post_id%', $content['post_id'], $path );
			}
			if ( isset( $content['category'] ) ) {
				$path = str_replace( '%category%', $content['category'], $path );
			}
			if ( isset( $content['author'] ) ) {
				$path = str_replace( '%author%', $content['author'], $path );
			}

			// If we have slug but need date info, look up the post.
			if ( strpos( $path, '%' ) !== false && isset( $content['slug'] ) ) {
				$post = self::get_post_by_slug( $content['slug'] );
				if ( $post ) {
					$date = get_the_date( 'Y-m-d', $post );
					$date_parts = explode( '-', $date );
					$path = str_replace( '%year%', $date_parts[0], $path );
					$path = str_replace( '%monthnum%', $date_parts[1], $path );
					$path = str_replace( '%day%', $date_parts[2], $path );
					$path = str_replace( '%post_id%', $post->ID, $path );
				}
			}
		}

		// Add path prefix.
		if ( ! empty( $path_prefix ) && ! empty( $path ) ) {
			$prefix = '/' . trim( $path_prefix, '/' );
			if ( strpos( $path, '?' ) === 0 ) {
				// Plain permalink - prefix goes before query string.
				$path = $prefix . '/' . $path;
			} else {
				$path = $prefix . '/' . ltrim( $path, '/' );
			}
		}

		return $path;
	}

	/**
	 * Transform a URL from one permalink structure to another.
	 *
	 * @param string $source_url           The source URL.
	 * @param string $source_structure     Source permalink structure.
	 * @param string $target_structure     Target permalink structure.
	 * @param string $target_path_prefix   Target path prefix.
	 * @param string $source_path_prefix   Source path prefix to strip.
	 * @return string|null The transformed URL or null if cannot transform.
	 */
	public static function transform(
		$source_url,
		$source_structure,
		$target_structure,
		$target_path_prefix = '',
		$source_path_prefix = ''
	) {
		// If structures are the same, just update the path prefix.
		if ( $source_structure === $target_structure ) {
			$path = wp_parse_url( $source_url, PHP_URL_PATH );
			if ( ! empty( $source_path_prefix ) ) {
				$prefix = '/' . trim( $source_path_prefix, '/' ) . '/';
				if ( strpos( $path, $prefix ) === 0 ) {
					$path = substr( $path, strlen( $prefix ) - 1 );
				}
			}
			if ( ! empty( $target_path_prefix ) ) {
				$path = '/' . trim( $target_path_prefix, '/' ) . $path;
			}

			if ( function_exists( 'wptsall_log_debug' ) ) {
				wptsall_log_debug(
					'sites-virtual',
					'URL transformed (same structure)',
					array(
						'source_url'  => $source_url,
						'result_path' => $path,
						'prefix_only' => true,
					)
				);
			}

			return $path;
		}

		// Parse the source URL.
		$content = self::parse_url( $source_url, $source_structure, $source_path_prefix );
		if ( empty( $content ) ) {
			if ( function_exists( 'wptsall_log_warning' ) ) {
				wptsall_log_warning(
					'sites-virtual',
					'URL transformation failed - cannot parse source URL',
					array(
						'source_url'       => $source_url,
						'source_structure' => $source_structure,
						'source_prefix'    => $source_path_prefix,
					)
				);
			}
			return null;
		}

		// Generate the target URL.
		$result = self::generate_url( $content, $target_structure, $target_path_prefix );

		if ( function_exists( 'wptsall_log_debug' ) ) {
			wptsall_log_debug(
				'sites-virtual',
				'URL transformed',
				array(
					'source_url'       => $source_url,
					'source_structure' => $source_structure,
					'target_structure' => $target_structure,
					'target_prefix'    => $target_path_prefix,
					'parsed_content'   => $content,
					'result_path'      => $result,
				)
			);
		}

		return $result;
	}

	/**
	 * Get the source site's permalink structure.
	 *
	 * @param int $blog_id Optional blog ID (for multisite).
	 * @return string
	 */
	public static function get_source_permalink_structure( $blog_id = 0 ) {
		if ( $blog_id && is_multisite() ) {
			switch_to_blog( $blog_id );
			$structure = get_option( 'permalink_structure', '' );
			restore_current_blog();
			return $structure;
		}

		return get_option( 'permalink_structure', '' );
	}

	/**
	 * Get the effective permalink structure for a virtual site.
	 *
	 * Returns the virtual site's structure if set, otherwise inherits from source site.
	 *
	 * @param array $virtual_site Virtual site data.
	 * @return string
	 */
	public static function get_effective_permalink_structure( $virtual_site ) {
		// If virtual site has explicit structure, use it.
		if ( ! empty( $virtual_site['permalink_structure'] ) ) {
			return $virtual_site['permalink_structure'];
		}

		// Otherwise, inherit from source site.
		$source_blog_id = $virtual_site['blog_source_site'] ?? $virtual_site['source_blog_id'] ?? 0;
		return self::get_source_permalink_structure( $source_blog_id );
	}

	/**
	 * Get the effective category base for a virtual site.
	 *
	 * @param array $virtual_site Virtual site data.
	 * @return string
	 */
	public static function get_effective_category_base( $virtual_site ) {
		if ( ! empty( $virtual_site['category_base'] ) ) {
			return $virtual_site['category_base'];
		}

		$source_blog_id = $virtual_site['blog_source_site'] ?? $virtual_site['source_blog_id'] ?? 0;
		if ( $source_blog_id && is_multisite() ) {
			switch_to_blog( $source_blog_id );
			$base = get_option( 'category_base', '' );
			restore_current_blog();
			return $base ?: 'category';
		}

		return get_option( 'category_base', '' ) ?: 'category';
	}

	/**
	 * Get the effective tag base for a virtual site.
	 *
	 * @param array $virtual_site Virtual site data.
	 * @return string
	 */
	public static function get_effective_tag_base( $virtual_site ) {
		if ( ! empty( $virtual_site['tag_base'] ) ) {
			return $virtual_site['tag_base'];
		}

		$source_blog_id = $virtual_site['blog_source_site'] ?? $virtual_site['source_blog_id'] ?? 0;
		if ( $source_blog_id && is_multisite() ) {
			switch_to_blog( $source_blog_id );
			$base = get_option( 'tag_base', '' );
			restore_current_blog();
			return $base ?: 'tag';
		}

		return get_option( 'tag_base', '' ) ?: 'tag';
	}

	/**
	 * Get a post by its slug.
	 *
	 * @param string $slug     Post slug.
	 * @param string $post_type Post type (default 'post').
	 * @return \WP_Post|null
	 */
	private static function get_post_by_slug( $slug, $post_type = 'post' ) {
		$posts = get_posts( array(
			'name'           => $slug,
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		) );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Normalize a permalink structure value.
	 *
	 * Handles 'plain' as empty string for WordPress compatibility.
	 *
	 * @param string $structure The structure value.
	 * @return string
	 */
	public static function normalize_structure( $structure ) {
		if ( 'plain' === $structure ) {
			return '';
		}
		return $structure;
	}
}
