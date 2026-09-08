<?php
/**
 * FSE module bootstrap.
 *
 * @package WPTSALL\Fse
 */

namespace WPTSALL\Fse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module class.
 */
class Module {
	/**
	 * @return void
	 */
	public static function init() {
		require_once dirname( __FILE__ ) . '/class-fse-content-adapter.php';
		Fse_Content_Adapter::init();
		add_filter( 'the_content', array( __CLASS__, 'filter_the_content' ), 12 );
		add_filter( 'the_title', array( __CLASS__, 'filter_the_title' ), 12, 2 );
	}

	/**
	 * Translate FSE post content on virtual-site front requests.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function filter_the_content( $content ) {
		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, Fse_Content_Adapter::$managed_types, true ) ) {
			return $content;
		}
		$lang = function_exists( 'wptsall_current_language' ) ? wptsall_current_language() : '';
		if ( '' === $lang ) {
			return $content;
		}
		return Fse_Content_Adapter::translate_content( $content, $lang, (int) $post->ID, $post->post_type );
	}

	/**
	 * @param string $title Title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_the_title( $title, $post_id = 0 ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, Fse_Content_Adapter::$managed_types, true ) ) {
			return $title;
		}
		$lang = function_exists( 'wptsall_current_language' ) ? wptsall_current_language() : '';
		if ( '' === $lang || ! class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) ) {
			return $title;
		}
		$tr = \WPTSALL\Strings\Services\String_Translation_Service::translate(
			'fse_' . $post->post_type,
			'title_' . (int) $post->ID,
			(string) $title,
			$lang
		);
		return ( '' !== $tr ) ? $tr : $title;
	}
}
