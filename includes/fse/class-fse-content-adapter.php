<?php
/**
 * FSE / block-theme content adapter.
 *
 * Brings wp_template, wp_template_part, wp_navigation, wp_global_styles into
 * the translation pipeline (scan + progress + change dispatcher + REST hooks).
 * Block JSON structure is preserved; only human-readable string leaves are
 * registered for Layer B / task discovery via post_content hash + needs_resync.
 *
 * @package WPTSALL\Fse
 * @since 2.0.1
 */

namespace WPTSALL\Fse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fse_Content_Adapter class.
 */
class Fse_Content_Adapter {

	/**
	 * Post types managed by this adapter.
	 *
	 * @var array<int,string>
	 */
	public static $managed_types = array(
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
	);

	/**
	 * @return void
	 */
	public static function init() {
		foreach ( self::$managed_types as $pt ) {
			add_action( "rest_after_insert_{$pt}", array( __CLASS__, 'on_rest_after_insert' ), 20, 3 );
		}
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert' ), 90, 4 );
		add_filter( 'wptsall_fse_managed_post_types', array( __CLASS__, 'filter_managed_types' ) );
	}

	/**
	 * @param array $types Types.
	 * @return array
	 */
	public static function filter_managed_types( $types ) {
		return array_values( array_unique( array_merge( (array) $types, self::$managed_types ) ) );
	}

	/**
	 * @param \WP_Post         $post     Post.
	 * @param \WP_REST_Request $request  Request.
	 * @param bool             $creating Creating.
	 * @return void
	 */
	public static function on_rest_after_insert( $post, $request, $creating ) {
		unset( $request, $creating );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}
		self::mark_and_extract( $post );
	}

	/**
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post.
	 * @param bool          $update      Update.
	 * @param null|\WP_Post $post_before Before.
	 * @return void
	 */
	public static function on_after_insert( $post_id, $post, $update, $post_before = null ) {
		unset( $post_id, $update, $post_before );
		if ( ! ( $post instanceof \WP_Post ) || ! in_array( $post->post_type, self::$managed_types, true ) ) {
			return;
		}
		if ( function_exists( 'wptsall_is_internal_write' ) && wptsall_is_internal_write() ) {
			return;
		}
		self::mark_and_extract( $post );
	}

	/**
	 * Mark mappings dirty and register extractable strings.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function mark_and_extract( \WP_Post $post ) {
		global $wpdb;
		$table = function_exists( 'wptsall_table' ) ? wptsall_table( 'post_mappings' ) : $wpdb->prefix . 'wptsall_post_mappings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET needs_resync = 1, updated_at = %s WHERE source_post_id = %d AND needs_resync = 0',
				$table,
				current_time( 'mysql' ),
				(int) $post->ID
			)
		);

		$strings = self::extract_translatable_strings( (string) $post->post_content );
		if ( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			$ctx = 'fse_' . $post->post_type;
			if ( '' !== trim( (string) $post->post_title ) ) {
				\WPTSALL\Strings\Services\String_Translation_Service::register(
					$ctx,
					'title_' . (int) $post->ID,
					(string) $post->post_title
				);
			}
			foreach ( $strings as $i => $text ) {
				\WPTSALL\Strings\Services\String_Translation_Service::register(
					$ctx,
					'block_' . (int) $post->ID . '_' . $i,
					$text
				);
			}
		}

		/**
		 * After FSE content is registered for translation.
		 *
		 * @param \WP_Post           $post    Post.
		 * @param array<int,string>  $strings Extracted strings.
		 */
		do_action( 'wptsall_fse_content_changed', $post, $strings );
	}

	/**
	 * Extract plain-text leaves from block markup / JSON-ish content.
	 *
	 * @param string $content Post content.
	 * @return array<int,string>
	 */
	public static function extract_translatable_strings( $content ) {
		$content = (string) $content;
		$out     = array();
		if ( '' === $content ) {
			return $out;
		}

		// Prefer block parser when available.
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $content );
			self::walk_blocks( $blocks, $out );
		} else {
			$plain = wp_strip_all_tags( $content );
			$plain = trim( preg_replace( '/\s+/u', ' ', $plain ) );
			if ( strlen( $plain ) >= 2 ) {
				$out[] = $plain;
			}
		}

		$out = array_values( array_unique( array_filter( $out, static function ( $s ) {
			$s = trim( (string) $s );
			return strlen( $s ) >= 2 && ! preg_match( '/^\{/', $s );
		} ) ) );

		return $out;
	}

	/**
	 * @param array $blocks Blocks.
	 * @param array $out    Collector.
	 * @return void
	 */
	private static function walk_blocks( $blocks, array &$out ) {
		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			foreach ( array( 'content', 'title', 'label', 'citation', 'caption', 'placeholder' ) as $key ) {
				if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
					$text = trim( wp_strip_all_tags( $attrs[ $key ] ) );
					if ( '' !== $text ) {
						$out[] = $text;
					}
				}
			}
			// Navigation link labels often live in attrs.label.
			if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$text = trim( wp_strip_all_tags( $block['innerHTML'] ) );
				if ( '' !== $text && strlen( $text ) < 500 ) {
					$out[] = $text;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk_blocks( $block['innerBlocks'], $out );
			}
		}
	}

	/**
	 * Apply stored Layer B string translations back into block HTML for a VS request.
	 *
	 * @param string $content Content.
	 * @param string $lang    Target lang.
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function translate_content( $content, $lang, $post_id, $post_type ) {
		if ( '' === $lang || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $content;
		}
		$strings = self::extract_translatable_strings( $content );
		$ctx     = 'fse_' . $post_type;
		foreach ( $strings as $i => $text ) {
			$tr = \WPTSALL\Strings\Services\String_Translation_Service::translate(
				$ctx,
				'block_' . (int) $post_id . '_' . $i,
				$text,
				$lang
			);
			if ( '' !== $tr && $tr !== $text ) {
				$content = str_replace( $text, $tr, $content );
			}
		}
		return $content;
	}
}
