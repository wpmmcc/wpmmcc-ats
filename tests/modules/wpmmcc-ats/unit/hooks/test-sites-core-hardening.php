<?php
/**
 * Sites-core hardening: S6 static pages, term_link remap, S5/S8 signals, SEO gaps.
 *
 * @package WPTSALL
 * @group sites-hardening
 */

use WPTSALL\Hooks\Virtual_Site_Link_Filters;
use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Hooks\Virtual_Site_SEO;
use WPTSALL\Core\Language_Context;
use WPTSALL\MenuTranslation\Admin\Menu_Sync_Page;
use WPTSALL\Sites\Services\Translation_Identity;

class Test_Sites_Core_Hardening extends SimpleTestCase {

	/** @var array|null */
	private $saved_settings = null;

	/** @var int[] */
	private $post_ids = array();

	/** @var int[] */
	private $term_ids = array();

	public function setUp(): void {
		parent::setUp();
		if ( class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			$this->saved_settings = \WPTSALL\Settings\Services\Settings_Service::get_all();
			\WPTSALL\Settings\Services\Settings_Service::update(
				array(
					'default_language'               => 'zh_CN',
					'url_form'                       => 'subdir',
					'directory_for_default_language' => false,
				)
			);
		}
	}

	public function tearDown(): void {
		foreach ( $this->post_ids as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
		$this->post_ids = array();
		foreach ( $this->term_ids as $tid ) {
			wp_delete_term( (int) $tid, 'category' );
		}
		$this->term_ids = array();
		$GLOBALS['wptsall_current_virtual_site'] = null;
		$ref = new ReflectionClass( Virtual_Site_Router::class );
		if ( $ref->hasProperty( 'current_virtual_site' ) ) {
			$prop = $ref->getProperty( 'current_virtual_site' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
		if ( null !== $this->saved_settings && class_exists( '\\WPTSALL\\Settings\\Services\\Settings_Service' ) ) {
			\WPTSALL\Settings\Services\Settings_Service::update( $this->saved_settings );
		}
		parent::tearDown();
	}

	private function pin_vs( array $vs ) {
		$GLOBALS['wptsall_current_virtual_site'] = $vs;
		$ref  = new ReflectionClass( Virtual_Site_Router::class );
		$prop = $ref->getProperty( 'current_virtual_site' );
		$prop->setAccessible( true );
		$prop->setValue( null, $vs );
	}

	public function test_s6_map_static_front_page_to_shadow() {
		$vs = array( 'id' => '99', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$this->pin_vs( $vs );

		$source = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Front Source ' . uniqid( 'p', false ),
			)
		);
		$shadow = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Front Shadow ' . uniqid( 'p', false ),
			)
		);
		$this->assertFalse( is_wp_error( $source ) );
		$this->assertFalse( is_wp_error( $shadow ) );
		$this->post_ids[] = (int) $source;
		$this->post_ids[] = (int) $shadow;

		Translation_Identity::write_meta( (int) $shadow, Translation_Identity::META_VIRTUAL_SITE_ID, '99' );
		Translation_Identity::write_meta( (int) $shadow, Translation_Identity::META_SOURCE_POST_ID, (string) $source );
		Translation_Identity::write_meta( (int) $shadow, Translation_Identity::META_RELATION_ID, '1' );

		$mapped = Virtual_Site_Link_Filters::filter_page_on_front( $source );
		$this->assertSame( (int) $shadow, (int) $mapped, 'page_on_front must map to shadow under VS' );

		$unmapped = Virtual_Site_Link_Filters::filter_page_on_front( (int) $source + 99999 );
		$this->assertSame( (int) $source + 99999, (int) $unmapped, 'missing shadow falls back to source id' );
	}

	public function test_term_link_remaps_source_term_via_mapping() {
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
			$this->markTestSkipped( 'Term_Mapping_Service missing' );
		}
		$vs = array( 'id' => '55', 'path_prefix' => 'en_us', 'lang' => 'en_US', 'relation_id' => 4242 );
		$this->pin_vs( $vs );

		$source = wp_insert_term( 'Src Cat ' . uniqid( 't', false ), 'category' );
		$shadow = wp_insert_term( 'Sh Cat ' . uniqid( 't', false ), 'category' );
		$this->assertFalse( is_wp_error( $source ) );
		$this->assertFalse( is_wp_error( $shadow ) );
		$source_id = (int) $source['term_id'];
		$shadow_id = (int) $shadow['term_id'];
		$this->term_ids[] = $source_id;
		$this->term_ids[] = $shadow_id;

		update_term_meta( $shadow_id, '_wptsall_virtual_site_id', '55' );
		update_term_meta( $shadow_id, '_wptsall_source_term_id', $source_id );

		\WPTSALL\Models\Services\Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'     => $source_id,
				'source_taxonomy'    => 'category',
				'source_site_id'     => get_current_blog_id(),
				'relation_id'        => 4242,
				'source_lang'        => 'zh_CN',
				'target_term_id'     => $shadow_id,
				'target_taxonomy'    => 'category',
				'target_site_id'     => '55',
				'target_lang'        => 'en_US',
				'mapping_method'     => 'test',
				'translation_method' => 'unit',
			)
		);

		$term = get_term( $source_id, 'category' );
		$base = get_term_link( $term );
		$this->assertFalse( is_wp_error( $base ) );
		$link = Virtual_Site_Router::rewrite_term_link( $base, $term, 'category' );
		$this->assertTrue( is_string( $link ) && '' !== $link );
		$this->assertTrue( false !== strpos( $link, '/en_us/' ), 'remapped term link should be prefixed: ' . $link );
	}

	public function test_s8_bind_sets_language_context() {
		$vs     = array( 'id' => '7', 'path_prefix' => 'fr', 'lang' => 'fr_FR' );
		$ref    = new ReflectionClass( Virtual_Site_Router::class );
		$method = $ref->getMethod( 'bind_detected_virtual_site' );
		$method->setAccessible( true );
		$method->invoke( null, $vs, 'unit-test', array() );
		$this->assertSame( 'fr_FR', Language_Context::current_language() );
		$this->assertNotEmpty( Virtual_Site_Router::get_current_virtual_site() );
	}

	public function test_s5_menu_sync_notice_method_exists() {
		$this->assertTrue( method_exists( Menu_Sync_Page::class, 'maybe_notice_menu_sync_needed' ) );
		$this->assertTrue( method_exists( \WPTSALL\Settings\Admin\Seo_Settings_Page::class, 'maybe_notice_static_front_shadow_missing' ) );
	}

	public function test_core_sitemap_entry_attaches_alternates_contract() {
		$post_id = wp_insert_post(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Sitemap Alt ' . uniqid( 's', false ),
				'post_type'   => 'post',
			)
		);
		$this->assertFalse( is_wp_error( $post_id ) );
		$this->post_ids[] = (int) $post_id;

		$GLOBALS['wptsall_current_virtual_site'] = null;
		$ref  = new ReflectionClass( Virtual_Site_Router::class );
		$prop = $ref->getProperty( 'current_virtual_site' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$entry = array( 'loc' => get_permalink( $post_id ) );
		$out   = Virtual_Site_SEO::filter_sitemap_post_entry( $entry, 'post', get_post( $post_id ) );
		$this->assertTrue( is_array( $out ) );
		$this->assertArrayHasKey( 'loc', $out );
	}

	public function test_seopress_og_filter_virtualizes() {
		$this->assertTrue( method_exists( Virtual_Site_SEO::class, 'filter_opengraph_url' ) );
		$vs = array( 'id' => 'og', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$this->pin_vs( $vs );
		$out = Virtual_Site_SEO::filter_opengraph_url( home_url( '/post/' ) );
		$this->assertTrue( false !== strpos( $out, '/en_us/' ), $out );
	}

	public function test_canonical_virtualizes_non_singular_under_vs() {
		$vs = array( 'id' => 'c1', 'path_prefix' => 'en_us', 'lang' => 'en_US' );
		$this->pin_vs( $vs );
		$src = home_url( '/category/news/' );
		$out = Virtual_Site_SEO::filter_canonical_url( $src, null );
		$this->assertTrue( false !== strpos( (string) $out, '/en_us/' ), (string) $out );
	}
}
