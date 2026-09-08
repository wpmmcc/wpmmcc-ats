<?php
/**
 * Media Translation Frontend Hooks (1.3.0)
 *
 * On virtual-site requests, swap the image src / srcset / alt for the
 * translated media row when one is registered. Mirrors Polylang's
 * `pll_translate_media` integration.
 *
 * @package WPTSALL
 * @since 1.3.0
 */

namespace WPTSALL\MediaTranslation\Hooks;

use WPTSALL\MediaTranslation\Services\Media_Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Translation_Frontend {

	/**
	 * Guard nested core media calls while resolving a mapped target. Without it,
	 * wp_get_attachment_image_src()/srcset() would re-enter these filters when
	 * fetching the target rendition.
	 *
	 * @var bool
	 */
	private static $resolving_target = false;

	public static function init() {
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 4 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_image_attributes' ), 10, 3 );
		// wp_get_attachment_image_srcset() obtains the image URL through the
		// image-src filter before it reads metadata. Replace that metadata as
		// well, otherwise core sees a target URL with a source filename and
		// returns false before wp_calculate_image_srcset can run.
		add_filter( 'wp_calculate_image_srcset_meta', array( __CLASS__, 'filter_image_srcset_meta' ), 10, 4 );
		// wp_get_attachment_image() builds srcset through this filter, separately
		// from wp_get_attachment_image_src. Swap the complete target rendition
		// list so responsive HTML never leaks source attachment URLs.
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_image_srcset' ), 10, 5 );
		// P1-1 fix: also translate direct attachment URL lookups used by
		// WooCommerce downloads, PDF generators, and themes that call
		// wp_get_attachment_url() directly instead of going through
		// image_src.
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 10, 2 );
	}

	/**
	 * When current request is for a virtual site, swap to the translated media.
	 *
	 * @param array|false  $image         { src, width, height, is_intermediate }
	 * @param int          $attachment_id
	 * @param string|array $size
	 * @param bool         $icon
	 */
	public static function filter_image_src( $image, $attachment_id, $size, $icon ) {
		if ( self::$resolving_target || ! is_array( $image ) || (int) $attachment_id <= 0 ) {
			return $image;
		}
		$target = self::target_id( $attachment_id );
		if ( $target <= 0 ) {
			return $image;
		}

		// Ask core for the matching target size, not merely the target full URL.
		// This preserves thumbnail/medium dimensions and lets core generate an
		// accurate target srcset for the same requested size.
		$target_image = self::without_target_filters(
			static function () use ( $target, $size, $icon ) {
				return wp_get_attachment_image_src( $target, $size, $icon );
			}
		);
		return is_array( $target_image ) ? $target_image : $image;
	}

	/**
	 * Translate responsive image attributes and alt text. The old early return
	 * for an empty alt accidentally skipped srcset replacement altogether.
	 */
	public static function filter_image_attributes( $attrs, $attachment, $size ) {
		if ( self::$resolving_target || ! is_array( $attrs ) || ! ( $attachment instanceof \WP_Post ) ) {
			return $attrs;
		}
		$target = self::target_id( (int) $attachment->ID );
		if ( $target <= 0 ) {
			return $attrs;
		}

		$target_srcset = self::without_target_filters(
			static function () use ( $target, $size ) {
				return wp_get_attachment_image_srcset( $target, $size );
			}
		);
		if ( is_string( $target_srcset ) && '' !== $target_srcset ) {
			$attrs['srcset'] = $target_srcset;
		} elseif ( ! empty( $attrs['srcset'] ) ) {
			// A small image can legitimately have no intermediate variants. Keep a
			// valid single-candidate srcset rather than leaking the source URL.
			$target_url = self::target_url( $target );
			$target_meta = wp_get_attachment_metadata( $target );
			if ( '' !== $target_url ) {
				$attrs['srcset'] = $target_url . ' ' . max( 1, (int) ( $target_meta['width'] ?? 1 ) ) . 'w';
			}
		}
		$target_sizes = self::without_target_filters(
			static function () use ( $target, $size ) {
				return wp_get_attachment_image_sizes( $target, $size );
			}
		);
		if ( is_string( $target_sizes ) && '' !== $target_sizes ) {
			$attrs['sizes'] = $target_sizes;
		}

		if ( ! empty( $attrs['alt'] ) && class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			$context  = 'media_alt';
			$key      = 'attachment_' . (int) $attachment->ID;
			$alt      = (string) $attrs['alt'];
			$tr = \WPTSALL\Strings\Services\String_Translation_Service::translate( $context, $key, $alt, $lang );
			if ( '' !== $tr && $tr !== $alt ) {
				$attrs['alt'] = $tr;
			}
		}
		return $attrs;
	}

	/**
	 * Swap WordPress responsive source candidates to candidates generated from
	 * the mapped target attachment.
	 *
	 * @param array|false $sources       Candidate sources.
	 * @param array       $size_array    Requested dimensions.
	 * @param string      $image_src     Source image URL.
	 * @param array       $image_meta    Source attachment metadata.
	 * @param int         $attachment_id Source attachment ID.
	 * @return array|false
	 */
	public static function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $image_src, $image_meta );
		if ( self::$resolving_target || ! is_array( $sources ) || (int) $attachment_id <= 0 ) {
			return $sources;
		}
		$target = self::target_id( $attachment_id );
		if ( $target <= 0 ) {
			return $sources;
		}

		$target_image = self::without_target_filters(
			static function () use ( $target, $size_array ) {
				return wp_get_attachment_image_src( $target, $size_array );
			}
		);
		$target_meta = wp_get_attachment_metadata( $target );
		if ( is_array( $target_image ) && is_array( $target_meta ) ) {
			$target_sources = self::without_target_filters(
				static function () use ( $size_array, $target_image, $target_meta, $target ) {
					return wp_calculate_image_srcset( $size_array, (string) $target_image[0], $target_meta, $target );
				}
			);
			if ( is_array( $target_sources ) && ! empty( $target_sources ) ) {
				return $target_sources;
			}
		}

		// Core can return no srcset for a tiny image. Preserve a target-only
		// candidate whenever callers already requested srcset, so plugins such as
		// SEO renderers never retain a source-language media URL.
		$target_url = self::target_url( $target );
		if ( '' === $target_url ) {
			return $sources;
		}
		$width = max( 1, (int) ( $target_meta['width'] ?? ( is_array( $size_array ) ? ( $size_array[0] ?? 1 ) : 1 ) ) );
		return array(
			$width => array(
				'url'        => $target_url,
				'descriptor' => 'w',
				'value'      => $width,
			),
		);
	}

	/**
	 * Provide target attachment metadata to core's direct
	 * wp_get_attachment_image_srcset() path.
	 *
	 * @param array  $image_meta    Source attachment metadata.
	 * @param array  $size_array    Requested image dimensions.
	 * @param string $image_src     Image URL after image-src filters.
	 * @param int    $attachment_id Source attachment ID.
	 * @return array
	 */
	public static function filter_image_srcset_meta( $image_meta, $size_array, $image_src, $attachment_id ) {
		unset( $size_array, $image_src );
		if ( self::$resolving_target || ! is_array( $image_meta ) || (int) $attachment_id <= 0 ) {
			return $image_meta;
		}
		$target = self::target_id( $attachment_id );
		if ( $target <= 0 ) {
			return $image_meta;
		}
		$target_meta = self::without_target_filters(
			static function () use ( $target ) {
				return wp_get_attachment_metadata( $target );
			}
		);
		return is_array( $target_meta ) && ! empty( $target_meta['file'] ) ? $target_meta : $image_meta;
	}

	/**
	 * P1-1: Translate direct attachment URL lookups.
	 *
	 * Many plugins (WooCommerce downloadable products, PDF invoice
	 * generators, custom themes) call wp_get_attachment_url() directly
	 * instead of wp_get_attachment_image_src(). Without this filter,
	 * those URLs stay in the source language.
	 *
	 * @since 1.5.1
	 *
	 * @param string $url           Original attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Translated attachment URL or original.
	 */
	public static function filter_attachment_url( $url, $attachment_id ) {
		if ( self::$resolving_target || empty( $url ) || (int) $attachment_id <= 0 ) {
			return $url;
		}
		$target = self::target_id( $attachment_id );
		if ( $target <= 0 ) {
			return $url;
		}
		$tgt_url = self::target_url( $target );
		return $tgt_url ? $tgt_url : $url;
	}

	/**
	 * @param int $source_id Source attachment ID.
	 * @return int
	 */
	private static function target_id( $source_id ) {
		$lang = self::current_lang();
		if ( '' === $lang ) {
			return 0;
		}
		$target = Media_Translation_Service::get_target_id( (int) $source_id, $lang );
		return ( $target > 0 && $target !== (int) $source_id ) ? (int) $target : 0;
	}

	/**
	 * @param int $target_id Target attachment ID.
	 * @return string
	 */
	private static function target_url( $target_id ) {
		return (string) self::without_target_filters(
			static function () use ( $target_id ) {
				return wp_get_attachment_url( $target_id );
			}
		);
	}

	/**
	 * @param callable $callback Core media call.
	 * @return mixed
	 */
	private static function without_target_filters( callable $callback ) {
		$previous = self::$resolving_target;
		self::$resolving_target = true;
		try {
			return $callback();
		} finally {
			self::$resolving_target = $previous;
		}
	}

	/**
	 * Detect current request's target language via Language_Context.
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
