<?php
/**
 * Unit tests: Metadata_Id_Remapper (runtime TranslateIds-style).
 *
 * @package WPTSALL
 * @since 2.2.0
 */

use WPTSALL\Hooks\Metadata_Id_Remapper;
use WPTSALL\Hooks\Virtual_Site_Router;

class Test_Metadata_Id_Remapper extends SimpleTestCase {

	public function setUp(): void {
		parent::setUp();
		Metadata_Id_Remapper::reset_cache();
	}

	public function test_field_map_includes_thumbnail() {
		$map = Metadata_Id_Remapper::get_field_map();
		$this->assertTrue( isset( $map['_thumbnail_id'] ), 'default _thumbnail_id missing' );
		$this->assertEquals( 'media', $map['_thumbnail_id']['reference_type'] ?? '' );
	}

	public function test_remap_scalar_id_via_object_id() {
		$source = wp_insert_post( array(
			'post_title'  => 'Remap Src',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		$shadow = wp_insert_post( array(
			'post_title'  => 'Remap Shadow',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		update_post_meta( $shadow, '_wptsall_virtual_site_id', 'v_smoke_fixed' );
		update_post_meta( $shadow, '_wptsall_source_post_id', $source );

		// Attachment-like: create two posts as stand-ins and map via post shadow for 'any'.
		$vs = array(
			'id'          => 'smoke_fixed',
			'path_prefix' => 'en_us',
			'lang'        => 'en_US',
		);
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$p = $ref->getProperty( 'current_virtual_site' );
			$p->setAccessible( true );
			$p->setValue( null, $vs );
		}

		$config = array( 'type' => 'id_mapping', 'reference_type' => 'post', 'reference_target' => 'post' );
		$mapped = Metadata_Id_Remapper::remap_one_value( $source, 'related_post', $config );
		$this->assertEquals( $shadow, (int) $mapped, 'scalar post ID should remap to shadow' );

		$csv = Metadata_Id_Remapper::remap_one_value( $source . ',999999', '_product_image_gallery', array(
			'type' => 'id_mapping',
			'reference_type' => 'post',
			'reference_target' => 'post',
		) );
		$this->assertTrue( is_string( $csv ) && 0 === strpos( $csv, (string) $shadow . ',' ), 'csv remap: ' . $csv );

		wp_delete_post( $source, true );
		wp_delete_post( $shadow, true );
		$GLOBALS['wptsall_current_virtual_site'] = null;
		if ( isset( $p ) ) {
			$p->setValue( null, null );
		}
		Metadata_Id_Remapper::reset_cache();
	}

	public function test_filter_skips_without_virtual_site() {
		$GLOBALS['wptsall_current_virtual_site'] = null;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$p = $ref->getProperty( 'current_virtual_site' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
		$out = Metadata_Id_Remapper::filter_get_post_metadata( null, 1, '_thumbnail_id', true );
		$this->assertTrue( null === $out, 'must not short-circuit off VS' );
	}

	public function test_filter_remaps_thumbnail_on_virtual_site() {
		// Create source + shadow posts; thumbnail meta on a carrier post holds source ID.
		$src_media = wp_insert_post( array(
			'post_title'  => 'Media Src',
			'post_status' => 'inherit',
			'post_type'   => 'attachment',
			'post_mime_type' => 'image/jpeg',
		) );
		$sh_media = wp_insert_post( array(
			'post_title'  => 'Media Shadow',
			'post_status' => 'inherit',
			'post_type'   => 'attachment',
			'post_mime_type' => 'image/jpeg',
		) );
		update_post_meta( $sh_media, '_wptsall_virtual_site_id', 'v_smoke_fixed' );
		update_post_meta( $sh_media, '_wptsall_source_post_id', $src_media );

		$carrier = wp_insert_post( array(
			'post_title'  => 'Carrier',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		update_post_meta( $carrier, '_thumbnail_id', $src_media );

		$vs = array(
			'id'          => 'smoke_fixed',
			'path_prefix' => 'en_us',
			'lang'        => 'en_US',
		);
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$p = $ref->getProperty( 'current_virtual_site' );
			$p->setAccessible( true );
			$p->setValue( null, $vs );
		}
		Metadata_Id_Remapper::reset_cache();

		// Ensure filter is registered for this test process.
		Metadata_Id_Remapper::init();

		$got = Metadata_Id_Remapper::filter_get_post_metadata( null, $carrier, '_thumbnail_id', true );
		$this->assertEquals( $sh_media, (int) $got, 'thumbnail should remap to shadow attachment, got=' . var_export( $got, true ) );

		wp_delete_post( $src_media, true );
		wp_delete_post( $sh_media, true );
		wp_delete_post( $carrier, true );
		$GLOBALS['wptsall_current_virtual_site'] = null;
		if ( isset( $p ) ) {
			$p->setValue( null, null );
		}
		Metadata_Id_Remapper::reset_cache();
	}
}
