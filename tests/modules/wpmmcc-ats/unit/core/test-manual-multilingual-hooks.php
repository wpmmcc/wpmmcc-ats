<?php
/**
 * Unit tests for manual multilingual frontend hooks.
 *
 * @package WPTSALL
 */

class Test_Manual_Multilingual_Hooks extends SimpleTestCase {

	public function test_public_string_translation_filter_is_registered_and_uses_wp_locale_case() {
		$this->assertTrue( has_filter( 'wptsall_translate_string', 'wptsall_translate_string' ) !== false, 'public string translation filter must be registered' );
		$this->assertTrue( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) );
		$this->assertTrue( class_exists( '\\WPTSALL\\Core\\Language_Context' ) );

		$key = 'unit_manual_filter_' . wp_rand( 1000, 999999 );
		$id  = \WPTSALL\Strings\Services\String_Translation_Service::register( 'unit_manual', $key, 'Manual source label', 'en_US' );
		try {
			$this->assertGreaterThan( 0, (int) $id, 'string register must persist a row' );
			$this->assertTrue( \WPTSALL\Strings\Services\String_Translation_Service::set_translations( (int) $id, array( 'fr_FR' => 'Libellé manuel' ) ) );

			$GLOBALS['wptsall_current_virtual_site'] = array( 'lang' => 'fr_FR' );
			\WPTSALL\Core\Language_Context::reset();
			\WPTSALL\Core\Language_Context::set_language( 'fr_FR' );
			$this->assertEquals( 'fr_FR', \WPTSALL\Core\Language_Context::current_language(), 'locale must keep WP-style fr_FR case' );

			$translated = apply_filters( 'wptsall_translate_string', 'Manual source label', $key, 'fr_FR', 'unit_manual' );
			$this->assertEquals( 'Libellé manuel', $translated );
		} finally {
			unset( $GLOBALS['wptsall_current_virtual_site'] );
			if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
				\WPTSALL\Core\Language_Context::reset();
			}
		}
	}

	public function test_custom_field_translation_filter_fetches_raw_meta_when_pre_metadata_value_is_null() {
		$this->assertTrue( class_exists( '\\WPTSALL\\CustomFields\\Services\\Custom_Field_Translation_Service' ) );
		$this->assertTrue( class_exists( '\\WPTSALL\\Strings\\Services\\String_Translation_Service' ) );

		$post_id = wp_insert_post( array(
			'post_title'  => 'Manual Meta Hook Unit ' . wp_rand( 1000, 999999 ),
			'post_status' => 'publish',
			'post_type'   => 'post',
		), true );
		$this->assertGreaterThan( 0, (int) $post_id );

		try {
			update_post_meta( (int) $post_id, 'manual_gate_meta', 'source meta' );
			$id = \WPTSALL\Strings\Services\String_Translation_Service::register( 'cpt_field', 'field_manual_gate_meta_' . (int) $post_id, 'source meta', 'en_US' );
			$this->assertGreaterThan( 0, (int) $id );
			$this->assertTrue( \WPTSALL\Strings\Services\String_Translation_Service::set_translations( (int) $id, array( 'fr_FR' => 'champ traduit' ) ) );

			$GLOBALS['wptsall_current_virtual_site'] = array( 'lang' => 'fr_FR' );
			\WPTSALL\Core\Language_Context::reset();
			\WPTSALL\Core\Language_Context::set_language( 'fr_FR' );
			$filtered = \WPTSALL\CustomFields\Services\Custom_Field_Translation_Service::filter_meta( null, (int) $post_id, 'manual_gate_meta', true );
			$this->assertEquals( 'champ traduit', $filtered, 'pre_metadata null path must still load raw meta and return translated value' );
		} finally {
			unset( $GLOBALS['wptsall_current_virtual_site'] );
			if ( class_exists( '\\WPTSALL\\Core\\Language_Context' ) ) {
				\WPTSALL\Core\Language_Context::reset();
			}
			wp_delete_post( (int) $post_id, true );
		}
	}

	public function test_editor_payload_does_not_coerce_text_meta_with_media_words_to_zero() {
		$this->assertTrue( class_exists( '\\WPTSALL\\Sites\\Services\\Manual_Content_Service' ) );

		$value = 'méta manuelle FR envira-gallery-lite-content';
		$this->assertEquals(
			$value,
			\WPTSALL\Sites\Services\Manual_Content_Service::sanitize_editor_payload_value( 'manual_matrix_meta_envira_gallery_lite', $value ),
			'text meta containing gallery should not be inferred as a media id'
		);
		$this->assertEquals( 123, \WPTSALL\Sites\Services\Manual_Content_Service::sanitize_editor_payload_value( '_thumbnail_id', '123' ) );
	}

	public function test_manual_translation_markers_are_raw_persisted_when_post_meta_is_intercepted() {
		global $wpdb;

		$this->assertTrue( class_exists( '\\WPTSALL\\Sites\\Services\\Manual_Content_Service' ) );
		$this->assertTrue( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) );
		$this->assertTrue( class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) );
		$this->assertTrue( class_exists( '\\WPTSALL\\Tasks\\Services\\Direct_DB_Service' ) );

		$post_type = 'wptsall_marker_case';
		if ( ! post_type_exists( $post_type ) ) {
			register_post_type(
				$post_type,
				array(
					'public'             => true,
					'publicly_queryable' => true,
					'show_ui'            => true,
					'supports'           => array( 'title', 'editor', 'excerpt' ),
				)
			);
		}

		$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( ! empty( $admin[0] ) ) {
			wp_set_current_user( (int) $admin[0]->ID );
		}

		$stamp = strtolower( substr( md5( uniqid( 'manual-marker-unit-', true ) ), 0, 8 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.md5_md5
		$source_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_title'   => 'Manual Marker Source ' . $stamp,
				'post_name'    => 'manual-marker-source-' . $stamp,
				'post_content' => '<p>Manual marker source</p>',
			),
			true
		);
		$this->assertGreaterThan( 0, (int) $source_id );

		$target_id = 0;
		$relation_id = 0;
		$virtual_site_id = '';
		$meta_interceptor = static function ( $check, $object_id, $meta_key ) use ( $post_type ) {
			$post = get_post( (int) $object_id );
			if ( $post && $post_type === $post->post_type && 0 === strpos( (string) $meta_key, '_wptsall_' ) ) {
				return true;
			}
			return $check;
		};

		try {
			\WPTSALL\Languages\Services\Language_Service::upsert(
				array(
					'code'        => 'fr_FR',
					'slug'        => 'fr',
					'name'        => 'French',
					'native_name' => 'Français',
					'locale'      => 'fr_FR',
					'sort_order'  => 10,
					'status'      => 'active',
				)
			);
			$virtual_site = \WPTSALL\Sites\Services\Virtual_Site_Service::create(
				array(
					'name'        => 'Manual Marker Unit ' . $stamp,
					'path_prefix' => 'manual-marker-' . $stamp,
					'lang'        => 'fr_FR',
				)
			);
			$virtual_site_id = (string) ( $virtual_site['site_id'] ?? '' );
			$this->assertNotEquals( '', $virtual_site_id, 'virtual site id must be created' );

			$relation = \WPTSALL\Sites\Services\Site_Relation_Service::create_relation(
				array(
					'source_site_id'    => get_current_blog_id(),
					'source_lang'       => get_locale() ?: 'en_US',
					'template'          => 'wordpress-blog',
					'auto_create_model' => true,
					'target_sites'      => array(
						array(
							'id'   => $virtual_site_id,
							'type' => 'virtual',
							'lang' => 'fr_FR',
						),
					),
				)
			);
			$relation_id = (int) ( $relation['relation_ids'][0] ?? 0 );
			$this->assertGreaterThan( 0, $relation_id, 'relation id must be created' );
			$relation_row = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $relation_id );
			$target_virtual_site_id = (string) ( is_array( $relation_row ) ? ( $relation_row['target_site_id'] ?? '' ) : '' );
			if ( '' === $target_virtual_site_id ) {
				$target_virtual_site_id = 0 === strpos( $virtual_site_id, 'v_' ) ? $virtual_site_id : 'v_' . $virtual_site_id;
			}

			add_filter( 'update_post_metadata', $meta_interceptor, 10, 5 );
			add_filter( 'add_post_metadata', $meta_interceptor, 10, 5 );
			$result = \WPTSALL\Sites\Services\Manual_Content_Service::save_translation(
				(int) $source_id,
				$relation_id,
				array(
					'post_title'   => 'Titre manuel ' . $stamp,
					'post_name'    => 'titre-manuel-' . $stamp,
					'post_content' => '<p>Contenu manuel ' . esc_html( $stamp ) . '</p>',
					'post_excerpt' => 'Extrait manuel ' . $stamp,
				)
			);
			$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
			$target_id = (int) ( $result['target_id'] ?? 0 );
			$this->assertGreaterThan( 0, $target_id, 'manual save must create target' );

			foreach ( array(
				'_wptsall_virtual_site_id' => $target_virtual_site_id,
				'_wptsall_source_post_id'   => (string) (int) $source_id,
				'_wptsall_source_blog_id'   => (string) get_current_blog_id(),
				'_wptsall_relation_id'      => (string) $relation_id,
			) as $key => $expected ) {
				$raw = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
						$target_id,
						$key
					)
				);
				$this->assertEquals( $expected, (string) $raw, $key . ' must be present in raw wp_postmeta' );
			}
		} finally {
			remove_filter( 'update_post_metadata', $meta_interceptor, 10 );
			remove_filter( 'add_post_metadata', $meta_interceptor, 10 );
			if ( $target_id > 0 ) {
				wp_delete_post( $target_id, true );
			}
			wp_delete_post( (int) $source_id, true );
			// Relation + virtual site must go too: leftover ACTIVE fr_FR
			// fixtures make Taxonomy_Translation_Service::link() resolve a
			// relation for plain 'fr_FR' keys, breaking the manual
			// translation listing contract in other suite files
			// (2026-09-12 incident: v_1478/relation 365 leaked).
			if ( ! empty( $relation_id ) ) {
				\WPTSALL\Sites\Services\Site_Relation_Service::delete_relation( (int) $relation_id );
			}
			if ( '' !== $virtual_site_id ) {
				\WPTSALL\Sites\Services\Virtual_Site_Service::delete( $virtual_site_id );
			}
			if ( function_exists( 'unregister_post_type' ) && post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
	}
}
