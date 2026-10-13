<?php
/**
 * Unit: multi-segment taxonomy path match (EDD downloads/category).
 *
 * @package WPTSALL
 */

use WPTSALL\Hooks\Virtual_Site_Router;

class Test_Taxonomy_Multi_Segment_Path extends SimpleTestCase {

	public function test_match_taxonomy_path_method_exists() {
		$this->assertTrue( method_exists( Virtual_Site_Router::class, 'match_taxonomy_path' ) );
	}

	public function test_match_downloads_category_style_path() {
		// Seed map via filter so we don't depend on EDD being active in unit runner.
		$cb = static function ( $map ) {
			$map['downloads/category'] = 'download_category';
			$map['category']           = 'category';
			return $map;
		};
		add_filter( 'wptsall_taxonomy_url_map', $cb );

		// Clear cache
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'taxonomy_map_cache' ) ) {
			$prop = $ref->getProperty( 'taxonomy_map_cache' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}

		$hit = Virtual_Site_Router::match_taxonomy_path( 'downloads/category/category-ii-287' );
		$this->assertTrue( is_array( $hit ), 'should match multi-segment slug' );
		$this->assertEquals( 'download_category', $hit['taxonomy'] );
		$this->assertEquals( 'category-ii-287', $hit['term_slug'] );

		$simple = Virtual_Site_Router::match_taxonomy_path( 'category/uncategorized' );
		$this->assertTrue( is_array( $simple ) );
		$this->assertEquals( 'category', $simple['taxonomy'] );

		remove_filter( 'wptsall_taxonomy_url_map', $cb );
		if ( isset( $prop ) ) {
			$prop->setValue( null, null );
		}
	}
}
