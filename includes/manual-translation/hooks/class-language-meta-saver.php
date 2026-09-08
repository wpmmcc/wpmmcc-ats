<?php
/**
 * Language Meta Saver (P5-9)
 *
 * Persists the `_wptsall_language` post meta chosen via:
 *   - The Polylang-style flag block in the WPTSALL meta box on post edit page.
 *   - The Quick Edit / Bulk Edit language selector injected by
 *     `assets/js/quick-edit-language.js`.
 *
 * @package WPTSALL\ManualTranslation
 * @since 1.4.0
 */

namespace WPTSALL\ManualTranslation\Hooks;

use WPTSALL\Languages\Services\Language_Service;
use WPTSALL\TranslationStatus\Translation_Status_Column;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Language_Meta_Saver {

	const META_KEY = '_wptsall_language';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'save' ), 25, 2 );
		add_action( 'wp_ajax_inline-save', array( __CLASS__, 'save_quick_edit' ), 5 );
	}

	/**
	 * Save handler for the classic editor meta box.
	 */
	public static function save( int $post_id, $post ) {
		// Plugin Check: verify nonce before processing form data.
		if ( ! isset( $_POST['wptsall_site_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wptsall_site_meta_nonce'] ) ), 'wptsall_site_meta_nonce' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, Translation_Status_Column::get_managed_post_types(), true ) ) {
			return;
		}
		$lang = isset( $_POST[ self::META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) ) : '';
		self::persist( $post_id, $lang );
	}

	/**
	 * Save handler for Quick Edit / Bulk Edit.
	 */
	public static function save_quick_edit() {
		// Plugin Check: verify nonce for AJAX quick edit.
		if ( ! check_ajax_referer( 'inlineeditnonce', '_inline_edit', false ) ) {
			return;
		}
		$post_ids = isset( $_POST['post_ID'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['post_ID'] ) ) : array();
		$langs    = isset( $_POST[ self::META_KEY ] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ self::META_KEY ] ) ) : array();
		if ( empty( $post_ids ) ) {
			return;
		}
		foreach ( $post_ids as $idx => $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 || ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}
			$lang = isset( $langs[ $idx ] ) ? sanitize_text_field( wp_unslash( $langs[ $idx ] ) ) : '';
			self::persist( $pid, $lang );
		}
	}

	private static function persist( int $post_id, string $lang ): void {
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( '' === $lang ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}
		$valid = array();
		foreach ( Language_Service::get_all( array( 'status' => 'all' ) ) as $l ) {
			$valid[] = (string) $l['code'];
		}
		if ( ! in_array( $lang, $valid, true ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_KEY, $lang );
	}
}
